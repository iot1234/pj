<?php
declare(strict_types=1);

use Dormitory\Domain\LineOfficialAccountService;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\Password;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || preg_match('/^appj_[a-z0-9_]+$/D', (string)getenv('DB_DATABASE')) !== 1) exit(64);
$app = require dirname(__DIR__).'/bootstrap.php';
$pdo = $app->database()->pdo();
foreach (['admin_users','rooms','residents','bookings','occupancies','bills','line_room_bindings'] as $table) {
    if ((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() !== 0) throw new RuntimeException('Fresh isolated operations database required');
}
$assert = static function(bool $ok, string $why='Assertion failed'): void { if (!$ok) throw new RuntimeException($why); };
$expect = static function(callable $fn, string $code) use ($assert): void {
    try { $fn(); } catch (HttpException $e) { $assert($e->errorCode === $code, "Expected {$code}, got {$e->errorCode}"); return; }
    throw new RuntimeException("Expected {$code}");
};
$groups=0;
$test = static function(string $name, callable $fn) use (&$groups): void { $fn(); $groups++; fwrite(STDOUT,"PASS {$name}\n"); };
$request = static fn(): Request => new Request('POST','/tests/operations',['user-agent'=>'isolated-operations'],[],[],[],['REMOTE_ADDR'=>'127.0.0.71'],'operations-'.bin2hex(random_bytes(4)));
$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
$period=substr($today,0,7);
$password='Operations-Owner-Fixture-2026!';
$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active) VALUES ('operations_owner',?,'owner',1,1)")->execute([Password::hash($password)]);
$owner=(int)$pdo->lastInsertId();
$app->auth()->adminLogin($request(),['username'=>'operations_owner','password'=>$password]);
$makeRoom=static fn(string $code):array=>$app->rooms()->create(['room_code'=>$code,'floor'=>1,'room_type'=>'fixture','monthly_rent'=>'3000.00']);
$a=$makeRoom('ops-a'); $b=$makeRoom('ops-b'); $c=$makeRoom('ops-c');
$bookingInput=['room_id'=>$a['id'],'full_name'=>'Operations resident','phone'=>'0817700001','idempotency_key'=>'operations-booking-key-0001'];
$booking=$app->bookings()->createPublic($bookingInput);

$test('room normalization, duplicates and invalid updates preserve the original room',function()use($app,$assert,$expect,$a):void{
    $assert($a['room_code']==='OPS-A');
    $expect(fn()=>$app->rooms()->create(['room_code'=>'ops-a','floor'=>2,'room_type'=>'duplicate','monthly_rent'=>'1000']), 'ROOM_CODE_EXISTS');
    $expect(fn()=>$app->rooms()->update($a['id'],['floor'=>2,'monthly_rent'=>'-1']), 'VALIDATION_ERROR');
    $fresh=$app->rooms()->find($a['id']);$assert($fresh['floor']===1 && $fresh['monthly_rent']==='3000.00');
});
$test('public booking replay and cross-room phone guards preserve one reservation',function()use($app,$pdo,$assert,$expect,$bookingInput,$booking,$b,$a):void{
    $again=$app->bookings()->createPublic($bookingInput);$assert($again['id']===$booking['id'] && $again['idempotent_replay']);
    $other=array_replace($bookingInput,['room_id'=>$b['id'],'idempotency_key'=>'operations-booking-key-0002']);
    $expect(fn()=>$app->bookings()->createPublic($other),'BOOKING_PHONE_ACTIVE');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===1);
    $expect(fn()=>$app->rooms()->delete($a['id']),'ROOM_IN_USE');
    $assert($app->rooms()->find($a['id'])['status']==='reserved');
});
$app->bookings()->confirm($booking['id'],$owner);
$move=['move_in_date'=>$today,'opening_water_reading'=>'100.00','opening_electric_reading'=>'200.00'];
$resident=$app->bookings()->moveIn($booking['id'],$owner,$move);$residentId=$resident['resident_id'];
$test('check-in replay cannot create a second occupancy or replace immutable readings',function()use($app,$pdo,$assert,$expect,$booking,$owner,$move,$a):void{
    $assert($app->bookings()->moveIn($booking['id'],$owner,$move)['idempotent_replay']);
    $expect(fn()=>$app->bookings()->moveIn($booking['id'],$owner,array_replace($move,['opening_water_reading'=>'101'])),'MOVE_IN_ALREADY_COMPLETED');
    $expect(fn()=>$app->bookings()->cancel($booking['id'],$owner,'not allowed'),'BOOKING_BAD_STATE');
    $expect(fn()=>$app->rooms()->delete($a['id']),'ROOM_IN_USE');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM occupancies')->fetchColumn()===1 && $app->rooms()->find($a['id'])['status']==='occupied');
});
$test('room catalog rent changes do not silently rewrite an existing tenancy rate',function()use($app,$pdo,$assert,$a,$resident):void{
    $app->rooms()->update($a['id'],['monthly_rent'=>'4500.00']);
    $q=$pdo->prepare('SELECT monthly_rent FROM occupancies WHERE id=?');$q->execute([$resident['occupancy_id']]);
    $assert($q->fetchColumn()==='3000.00' && $app->rooms()->find($a['id'])['monthly_rent']==='4500.00');
});
$reserved=$app->bookings()->createPublic(['room_id'=>$b['id'],'full_name'=>'Other booking','phone'=>'0817700002','idempotency_key'=>'operations-other-booking-01']);
$oa=new LineOfficialAccountService($app,static fn(string $token):array=>['userId'=>'U'.str_repeat('a',32),'basicId'=>'@ops_fixture','displayName'=>'Operations fixture']);
$oa->update(0,['channel_access_token'=>'operations-fixture-token','channel_secret'=>'operations-fixture-secret','enabled'=>true],$owner);
$invitation=$app->lineRoomBindings()->issue($residentId,[],$owner);
$app->lineRoomBindings()->consume($invitation['code'],'U'.str_repeat('5',32),0,0);
$pending=$app->lineRoomBindings()->issue($residentId,['replace_pending'=>false],$owner);
$app->auth()->residentLogin($request(),['phone'=>'0817700001']);
$test('phone conflict cannot partially change the resident, active LINE or session',function()use($app,$assert,$expect,$residentId):void{
    $expect(fn()=>$app->residents()->updateByAdmin($residentId,['full_name'=>'Must not save','phone'=>'0817700002']),'BOOKING_PHONE_ACTIVE');
    $profile=$app->residents()->profile($residentId);
    $assert($profile['full_name']==='Operations resident' && $profile['phone']==='0817700001' && $profile['line_verified']);
    $assert($app->actor(true)['id']===$residentId);
});
$test('confirmed phone change revokes the old session and both active and pending LINE bindings',function()use($app,$pdo,$assert,$expect,$residentId,$request):void{
    $result=$app->residents()->updateByAdmin($residentId,['phone'=>'0817700003']);
    $assert($result['sessions_revoked'] && !$result['line_verified'] && $app->actor(true)===null);
    $q=$pdo->prepare("SELECT COUNT(*) FROM line_room_bindings WHERE resident_id=? AND status IN ('active','pending')");$q->execute([$residentId]);$assert((int)$q->fetchColumn()===0);
    $expect(fn()=>$app->auth()->residentLogin($request(),['phone'=>'0817700001']),'INVALID_CREDENTIALS');
    $assert($app->auth()->residentLogin($request(),['phone'=>'0817700003'])['id']===$residentId);
});
$app->billing()->updateSettings(['water_rate'=>'18.00','electric_rate'=>'7.00','due_days'=>7],$owner);
$test('invalid paired meters do not save a partial reading or enable a bill',function()use($app,$pdo,$assert,$expect,$a,$period,$owner):void{
    $expect(fn()=>$app->meters()->record(['room_id'=>$a['id'],'period'=>$period,'water_current'=>'110','electric_current'=>'1'],$owner),'METER_ROLLBACK');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM meter_readings')->fetchColumn()===0);
    $preview=$app->billing()->preview(['period'=>$period,'room_ids'=>[$a['id']],'confirm_current_period'=>true]);$assert($preview['issues']!==[]);
});
$app->meters()->record(['room_id'=>$a['id'],'period'=>$period,'water_current'=>'110','electric_current'=>'220'],$owner);
$billInput=['period'=>$period,'room_ids'=>[$a['id']],'confirm_current_period'=>true];
$preview=$app->billing()->preview($billInput);
$test('billing settings changed after preview invalidate the token without partial bills',function()use($app,$pdo,$assert,$expect,$owner,$billInput,$preview):void{
    $app->billing()->updateSettings(['water_rate'=>'20.00','electric_rate'=>'7.00','due_days'=>7],$owner);
    $expect(fn()=>$app->billing()->bulk($billInput+['preview_token'=>$preview['preview_token']],$owner),'BILL_PREVIEW_CHANGED');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM bills')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM bill_items')->fetchColumn()===0);
});
$fresh=$app->billing()->preview($billInput);$app->billing()->bulk($billInput+['preview_token'=>$fresh['preview_token']],$owner);
$bill=$pdo->query('SELECT * FROM bills')->fetch();
$test('room and resident renaming leave issued bill amounts and snapshots intact',function()use($app,$pdo,$assert,$a,$residentId,$bill,$period):void{
    $app->rooms()->update($a['id'],['room_code'=>'OPS-RENAMED','monthly_rent'=>'5000.00']);
    $app->residents()->updateByAdmin($residentId,['full_name'=>'New resident name']);
    $stored=$pdo->query('SELECT * FROM bills')->fetch();
    foreach(['total_amount','rent_amount','room_code_snapshot','resident_name_snapshot','occupancy_id'] as $key)$assert($stored[$key]===$bill[$key]);
    $assert($stored['rent_amount']==='3000.00' && $stored['total_amount']==='3340.00');
    $admin=$app->billing()->adminList($period)[0];$tenant=$app->billing()->residentDetail($residentId,(int)$bill['id']);
    $assert($admin['room_code']==='OPS-A' && $tenant['total_amount']==='3340.00');
});
$test('unpaid move-out leaves tenancy, login and occupied room unchanged',function()use($app,$assert,$expect,$residentId,$today,$a):void{
    $expect(fn()=>$app->residents()->moveOut($residentId,['move_out_date'=>$today]),'MOVE_OUT_HAS_PENDING_BILLS');
    $assert($app->rooms()->find($a['id'])['status']==='occupied' && $app->actor(true)['id']===$residentId);
});
$test('cancel releases a room and phone but cannot revive the cancelled request',function()use($app,$expect,$assert,$reserved,$owner,$b):void{
    $app->bookings()->cancel($reserved['id'],$owner,'fixture cancelled');$assert($app->rooms()->find($b['id'])['status']==='available');
    $expect(fn()=>$app->bookings()->confirm($reserved['id'],$owner),'BOOKING_BAD_STATE');
    $new=$app->bookings()->createPublic(['room_id'=>$b['id'],'full_name'=>'Other booking','phone'=>'0817700002','idempotency_key'=>'operations-rebook-after-cancel']);$assert($new['id']!==$reserved['id']);
});
$test('outer transaction failure rolls back direct check-in and all dependent records',function()use($app,$pdo,$assert,$owner,$c,$today):void{
    $tables=['bookings','residents','occupancies','line_notice_outbox','audit_logs'];$before=[];
    foreach($tables as $table)$before[$table]=(int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    try {
        $app->database()->transaction(function()use($app,$owner,$c,$today):void{
            $app->bookings()->createAdminResident($owner,['room_id'=>$c['id'],'full_name'=>'Rollback fixture','phone'=>'0817700004','move_in_date'=>$today,'opening_water_reading'=>'0','opening_electric_reading'=>'0','idempotency_key'=>'operations-rollback-resident']);
            throw new RuntimeException('Simulated failure before commit');
        });
    } catch(RuntimeException $e) { $assert($e->getMessage()==='Simulated failure before commit'); }
    foreach($tables as $table)$assert((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()===$before[$table],$table.' escaped rollback');
    $assert($app->rooms()->find($c['id'])['status']==='available');
});
$test('administrator safety guards protect self, last owner and conflicting active flags',function()use($app,$assert,$expect,$owner):void{
    $expect(fn()=>$app->adminUsers()->delete($owner,$owner),'SELF_DELETE');
    $expect(fn()=>$app->adminUsers()->update($owner,['role'=>'admin'],$owner),'SELF_OWNER_CHANGE');
    $expect(fn()=>$app->adminUsers()->delete($owner,0),'LAST_OWNER');
    $expect(fn()=>$app->adminUsers()->update($owner,['active'=>true,'is_active'=>false],$owner),'VALIDATION_ERROR');
    $assert($app->adminUsers()->list()[0]['role']==='owner' && $app->adminUsers()->list()[0]['active']);
});
$test('disabled administrator loses an existing session and cannot sign in again',function()use($app,$assert,$expect,$owner,$request):void{
    $password='Operations-Staff-Fixture-2026!';$staff=$app->adminUsers()->create(['username'=>'operations_staff','password'=>$password,'role'=>'admin'],$owner);
    $app->auth()->adminLogin($request(),['username'=>'operations_staff','password'=>$password]);
    $app->adminUsers()->delete($staff['id'],$owner);$assert($app->actor(true)===null);
    $expect(fn()=>$app->auth()->adminLogin($request(),['username'=>'operations_staff','password'=>$password]),'INVALID_CREDENTIALS');
});
fwrite(STDOUT,"{$groups} operations integration groups passed\n");
