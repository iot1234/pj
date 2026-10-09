<?php
declare(strict_types=1);
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Http\Routes;
use Dormitory\Security\Password;
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||preg_match('/^appj_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE'))!==1){fwrite(STDERR,"Dedicated testing database required\n");exit(64);}
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
foreach(['admin_users','rooms','residents','bills']as$table)if((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()!==0)throw new RuntimeException('Fresh fixture required');
$passed=0;$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$test=static function(string $name,callable $fn)use(&$passed):void{$fn();$passed++;fwrite(STDOUT,"PASS {$name}\n");};
$expect=static function(callable $fn,string $code)use($assert):void{try{$fn();}catch(HttpException $e){$assert($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);};
$serial=0;$request=static function(string $path='/tests/phone',string $method='POST',?string $ip=null)use(&$serial):Request{return new Request($method,$path,['user-agent'=>'isolated-phone-test'],[],[],[],['REMOTE_ADDR'=>$ip??'127.1.0.'.(++$serial)],'phone-test-'.bin2hex(random_bytes(5)));};
$q=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active)VALUES('phone_test_owner',?,'owner',1,1)");$q->execute([Password::hash('Phone-Test-Owner-2026!')]);$owner=(int)$pdo->lastInsertId();
$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$month=substr($today,0,7);
$withoutActivationConfiguration=static function(callable $fn):mixed{
 $old=getenv('RESIDENT_ACTIVATION_TTL_SECONDS');putenv('RESIDENT_ACTIVATION_TTL_SECONDS=not-a-number');
 try{return $fn();}finally{$old===false?putenv('RESIDENT_ACTIVATION_TTL_SECONDS'):putenv('RESIDENT_ACTIVATION_TTL_SECONDS='.$old);}
};
$assertPhoneAccess=static function(array $result,bool $sessionsRevoked)use($assert):void{
 $assert(($result['resident_access']??null)===['auth_method'=>'phone','activation_required'=>false,'sessions_revoked'=>$sessionsRevoked],'Resident access contract must contain only phone mode and actual session invalidation');
};
$assertNoResidentCredentials=static function(int $id)use($pdo,$assert):void{
 $q=$pdo->prepare('SELECT access_password_hash,activation_code_hash,activation_expires_at,activation_consumed_at FROM residents WHERE id=?');$q->execute([$id]);
 $assert($q->fetch()===['access_password_hash'=>null,'activation_code_hash'=>null,'activation_expires_at'=>null,'activation_consumed_at'=>null],'Unused resident credentials remain stored');
};
$residents=[];$rooms=[];$residentInputs=[];
foreach(['0817900001','0817900002']as$i=>$phone){
 $room=$app->rooms()->create(['room_code'=>'PHONE-'.($i+1),'floor'=>1,'room_type'=>'test','monthly_rent'=>'3000.00']);$rooms[]=$room;
 $residentInputs[]=['room_id'=>$room['id'],'full_name'=>'Phone fixture '.($i+1),'phone'=>$phone,'move_in_date'=>$today,'opening_water_reading'=>'100','opening_electric_reading'=>'200','idempotency_key'=>'phone-only-fixture-2026-'.$i];
 $residents[]=$withoutActivationConfiguration(fn()=>$app->bookings()->createAdminResident($owner,$residentInputs[$i]));
}
$firstId=(int)$residents[0]['resident_id'];$firstRoom=(int)$rooms[0]['id'];$firstOccupancy=(int)$residents[0]['occupancy_id'];
$test('new phone-only check-ins create no secrets and ignore obsolete activation TTL configuration',function()use($residents,$assertPhoneAccess,$assertNoResidentCredentials):void{
 foreach($residents as$result){$assertPhoneAccess($result,false);$assertNoResidentCredentials((int)$result['resident_id']);}
});
$test('direct and booking replays clear unused legacy credentials without changing sessions or tenancy ledgers',function()use($app,$pdo,$owner,$firstId,$residentInputs,$residents,$today,$request,$withoutActivationConfiguration,$assertPhoneAccess,$assertNoResidentCredentials,$assert):void{
 $app->auth()->residentLogin($request(),['phone'=>'0817900001']);
 $q=$pdo->prepare('SELECT auth_version FROM residents WHERE id=?');$q->execute([$firstId]);$version=$q->fetchColumn();
 $bookingBefore=$pdo->query('SELECT * FROM bookings ORDER BY id')->fetchAll();$occupancyBefore=$pdo->query('SELECT * FROM occupancies ORDER BY id')->fetchAll();
 $pdo->prepare('UPDATE residents SET activation_code_hash=?,activation_expires_at=UTC_TIMESTAMP(6)+INTERVAL 7 DAY WHERE id=?')->execute([str_repeat('a',64),$firstId]);
 $replay=$withoutActivationConfiguration(fn()=>$app->bookings()->createAdminResident($owner,$residentInputs[0]));
 $assert($replay['idempotent_replay']===true,'Direct check-in did not replay');$assertPhoneAccess($replay,false);$assertNoResidentCredentials($firstId);
 $pdo->prepare('UPDATE residents SET access_password_hash=?,activation_consumed_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([Password::hash('Unused-Resident-Fixture-2026!'),$firstId]);
 $replay=$withoutActivationConfiguration(fn()=>$app->bookings()->moveIn((int)$residents[0]['booking_id'],$owner,['move_in_date'=>$today,'opening_water_reading'=>'100','opening_electric_reading'=>'200']));
 $assert($replay['idempotent_replay']===true,'Booking move-in did not replay');$assertPhoneAccess($replay,false);$assertNoResidentCredentials($firstId);
 $q->execute([$firstId]);$assert($q->fetchColumn()===$version,'Replay invalidated a valid phone session');
 $assert(($app->actor(true)['id']??null)===$firstId,'Replay revoked an existing phone-only session');
 $assert($pdo->query('SELECT * FROM bookings ORDER BY id')->fetchAll()===$bookingBefore,'Replay altered the booking digest or audit ledger');
 $assert($pdo->query('SELECT * FROM occupancies ORDER BY id')->fetchAll()===$occupancyBefore,'Replay altered occupancy or meter snapshots');
 $app->auth()->logout($request());
});
$test('historical resident reuse clears old password credentials and still signs in with the existing phone',function()use($app,$pdo,$owner,$today,$request,$withoutActivationConfiguration,$assertPhoneAccess,$assertNoResidentCredentials,$assert):void{
 $pdo->prepare("INSERT INTO residents(full_name,phone_norm,auth_version,active,access_password_hash,activation_consumed_at) VALUES('Historical phone fixture','0817900100',3,0,?,UTC_TIMESTAMP(6))")->execute([Password::hash('Historical-Unused-Resident-2026!')]);$id=(int)$pdo->lastInsertId();
 $room=$app->rooms()->create(['room_code'=>'PHONE-HISTORICAL','floor'=>1,'room_type'=>'test','monthly_rent'=>'3000.00']);
 $result=$withoutActivationConfiguration(fn()=>$app->bookings()->createAdminResident($owner,['room_id'=>$room['id'],'full_name'=>'Historical phone fixture','phone'=>'0817900100','reuse_resident_id'=>$id,'move_in_date'=>$today,'opening_water_reading'=>'0','opening_electric_reading'=>'0','idempotency_key'=>'phone-only-historical-reuse']));
 $assert($result['resident_id']===$id,'Historical identity was replaced');$assertPhoneAccess($result,true);$assertNoResidentCredentials($id);
 $actor=$app->auth()->residentLogin($request(),['phone'=>'0817900100']);$assert($actor['id']===$id&&$actor['auth_version']===4,'Reused phone-only account cannot enter or sessions were not revoked');$app->auth()->logout($request());
});
$test('deployment readiness accepts phone-only residents without passwords or activation',function()use($pdo,$assert):void{
 $legacyCredentialCount=(int)$pdo->query('SELECT COUNT(*) FROM residents WHERE access_password_hash IS NOT NULL OR activation_code_hash IS NOT NULL OR activation_expires_at IS NOT NULL OR activation_consumed_at IS NOT NULL')->fetchColumn();
 $assert($legacyCredentialCount===0,'Fresh phone-only check-in still generated a legacy credential');
 $assert(Dormitory\Support\ResidentAccessReadiness::countInvalid($pdo)===0,'Valid phone-only residents failed deployment data readiness');
});
$test('a never-activated resident enters with phone alone and is marked low assurance',function()use($app,$request,$firstId,$firstRoom,$assert):void{
 $a=$app->auth()->residentLogin($request(),['phone'=>'081-790-0001']);
 $assert($a['id']===$firstId&&$a['room_id']===$firstRoom&&$a['auth_method']==='phone'&&$a['assurance']==='low'&&$a['phone_verified']===false,'Phone login identity or assurance incorrect');
});
$test('each request resolves the same active room without upgrading phone ownership',function()use($app,$firstRoom,$assert):void{$a=$app->actor(true);$assert($a['room_id']===$firstRoom&&$a['assurance']==='low'&&$a['phone_verified']===false,'Actor changed assurance or room');});
$test('Thai international phone format matches the same resident without a device secret',function()use($app,$request,$firstId,$assert):void{
 $app->auth()->logout($request());$_COOKIE=[];$a=$app->auth()->residentLogin($request(),['phone'=>'+66817900001']);$assert($a['id']===$firstId,'Normalized phone mismatch');
});
$test('legacy password and activation hashes are neither required nor consumed by login',function()use($pdo,$app,$request,$firstId,$assert):void{
 $q=$pdo->prepare('SELECT access_password_hash,activation_code_hash,auth_version FROM residents WHERE id=?');$q->execute([$firstId]);$before=$q->fetch();
 $app->auth()->residentLogin($request(),['phone'=>'0817900001']);$q->execute([$firstId]);$assert($q->fetch()===$before,'Phone login unexpectedly rewrote legacy credential or auth version');
});
$test('resident sessions cannot invoke administrative APIs',function()use($app,$request,$assert):void{
 $r=Routes::build($app)->dispatch($request('/api/admin/rooms','GET'));$assert(in_array($r->status,[401,403],true),'Resident obtained administrative room list');
});
$test('credential, role and room identifiers supplied by clients are rejected',function()use($app,$request,$expect):void{
 foreach(['credential'=>'anything','new_password'=>'not-used','role'=>'owner','room_id'=>999]as$key=>$value)$expect(fn()=>$app->auth()->residentLogin($request(),['phone'=>'0817900001',$key=>$value]),'UNKNOWN_FIELDS');
});
$test('unknown and malformed numbers do not establish a session',function()use($app,$request,$expect,$assert):void{
 $app->auth()->logout($request());foreach(['0817999999','',[],true,1234567890,'0817<script>']as$phone){$expect(fn()=>$app->auth()->residentLogin($request(),['phone'=>$phone]),'INVALID_CREDENTIALS');$assert($app->actor(true)===null,'Invalid phone left an actor');}
});
$test('an active resident without an active occupancy cannot enter',function()use($pdo,$app,$request,$expect):void{
 $pdo->exec("INSERT INTO residents(full_name,phone_norm,auth_version,active)VALUES('No room fixture','0817900099',1,1)");
 $assertCount=Dormitory\Support\ResidentAccessReadiness::countInvalid($pdo);
 if($assertCount!==1)throw new RuntimeException('Phone data readiness must identify the active resident without a room');
 $expect(fn()=>$app->auth()->residentLogin($request(),['phone'=>'0817900099']),'INVALID_CREDENTIALS');
});
$test('resident list no longer requires initial activation or a password',function()use($app,$assert):void{
 foreach($app->residents()->list()as$r)$assert($r['access_active']===true&&$r['activation_pending']===false,'Admin status still requires activation');
});
$app->billing()->updateSettings(['water_rate'=>'18','electric_rate'=>'7','due_days'=>7],$owner);$billIds=[];
foreach($rooms as$room){
 $app->meters()->record(['room_id'=>$room['id'],'period'=>$month,'water_current'=>'110','electric_current'=>'220'],$owner);
 $input=['room_ids'=>[$room['id']],'period'=>$month,'confirm_current_period'=>true];$preview=$app->billing()->preview($input);
 $result=$app->billing()->bulk($input+['preview_token'=>$preview['preview_token']],$owner);$billIds[]=(int)$result['created'][0]['id'];
}
$test('phone access still scopes bill reads to the matched resident',function()use($app,$request,$billIds,$assert):void{
 $app->auth()->residentLogin($request(),['phone'=>'0817900001']);$router=Routes::build($app);
 $own=$router->dispatch($request('/api/resident/bills/'.$billIds[0],'GET'));$other=$router->dispatch($request('/api/resident/bills/'.$billIds[1],'GET'));
 $assert($own->status===200&&$other->status===404,'Cross-resident bill visibility changed');
});
$test('a session cannot silently switch occupancy or room even with the same account version',function()use($app,$request,$assert):void{
 // Previous HTTP probes release their session; start from a proved valid
 // resident actor so this cannot pass merely because its identity was absent.
 $actor=$app->auth()->residentLogin($request(),['phone'=>'0817900001']);$assert(($actor['room_id']??0)>0&&($actor['occupancy_id']??0)>0,'Valid resident scope required before tampering');
 $actor['room_id']+=100;$app->session()->login($actor);$app->clearActorCache();$assert($app->actor(true)===null,'Mismatched room retained access');
 $actor=$app->auth()->residentLogin($request(),['phone'=>'0817900001']);$assert(($actor['occupancy_id']??0)>0,'Valid occupancy required before tampering');$actor['occupancy_id']+=100;$app->session()->login($actor);$app->clearActorCache();$assert($app->actor(true)===null,'Mismatched occupancy retained access');
});
$test('changing a phone clears old credentials and invalidates the old session and old number',function()use($app,$pdo,$request,$firstId,$expect,$assert,$withoutActivationConfiguration,$assertPhoneAccess,$assertNoResidentCredentials):void{
 $pdo->prepare('UPDATE residents SET activation_code_hash=?,activation_expires_at=UTC_TIMESTAMP(6)+INTERVAL 7 DAY WHERE id=?')->execute([str_repeat('b',64),$firstId]);
 $app->auth()->residentLogin($request(),['phone'=>'0817900001']);$result=$withoutActivationConfiguration(fn()=>$app->residents()->updateByAdmin($firstId,['phone'=>'0817900003']));
 $assertPhoneAccess($result,true);$assertNoResidentCredentials($firstId);
 $assert($app->actor(true)===null,'Old session survived phone change');$expect(fn()=>$app->auth()->residentLogin($request(),['phone'=>'0817900001']),'INVALID_CREDENTIALS');
 $a=$app->auth()->residentLogin($request(),['phone'=>'0817900003']);$assert($a['id']===$firstId,'Updated phone cannot enter');
});
$test('compatibility access reset clears old passwords and restores access using only the unchanged phone',function()use($app,$pdo,$request,$firstId,$assert,$withoutActivationConfiguration,$assertPhoneAccess,$assertNoResidentCredentials):void{
 $pdo->prepare('UPDATE residents SET access_password_hash=?,activation_consumed_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([Password::hash('Reset-Unused-Resident-Fixture-2026!'),$firstId]);
 $old=$app->auth()->residentLogin($request(),['phone'=>'0817900003']);
 $result=$app->lineOfficialAccounts()->withRegistryLock(fn()=>$app->notifications()->withLineBindingLock($firstId,fn()=>$withoutActivationConfiguration(fn()=>$app->residents()->reissueAccess($firstId))));
 $assertPhoneAccess($result,true);$assertNoResidentCredentials($firstId);$assert($app->actor(true)===null,'Access reset left the previous phone session active');
 $new=$app->auth()->residentLogin($request(),['phone'=>'0817900003']);$assert($new['id']===$firstId&&$new['auth_version']===$old['auth_version']+1,'Access reset added a secret requirement or failed to revoke sessions');
});
$test('deactivation denies both existing sessions and new phone entry',function()use($app,$pdo,$firstId,$request,$expect,$assert):void{
 $q=$pdo->prepare('UPDATE residents SET active=0,auth_version=auth_version+1 WHERE id=?');$q->execute([$firstId]);
 $assert($app->actor(true)===null,'Inactive resident retained session');$expect(fn()=>$app->auth()->residentLogin($request(),['phone'=>'0817900003']),'INVALID_CREDENTIALS');
});
$test('legacy credential sessions cannot claim high assurance after this policy change',function()use($app,$request,$assert):void{
 $a=$app->auth()->residentLogin($request(),['phone'=>'0817900002']);$a['auth_method']='password';$a['assurance']='high';$app->session()->login($a);$app->clearActorCache();
 $assert($app->actor(true)===null,'Legacy credential session remained active');
});
$test('admin sign-in still requires its password',function()use($app,$request,$expect,$assert):void{
 $expect(fn()=>$app->auth()->adminLogin($request(),['username'=>'phone_test_owner']),'INVALID_CREDENTIALS');
 $a=$app->auth()->adminLogin($request(),['username'=>'phone_test_owner','password'=>'Phone-Test-Owner-2026!']);
 $assert($a['type']==='admin'&&$a['role']==='owner','Admin password login regressed');$app->auth()->logout($request());
});
$test('IP throttling still stops repeated unknown-phone lookups',function()use($app,$request,$expect,$assert):void{
 for($i=0;$i<12;$i++)$expect(fn()=>$app->auth()->residentLogin($request('/tests/throttle','POST','127.2.0.1'),['phone'=>'0817999900']),'INVALID_CREDENTIALS');
 $expect(fn()=>$app->auth()->residentLogin($request('/tests/throttle','POST','127.2.0.1'),['phone'=>'0817999900']),'RATE_LIMITED');
 $assert($app->actor(true)===null,'Throttled lookup created session');
});
$test('phone login audit records explicit low assurance and never a submitted secret',function()use($pdo,$assert):void{
 $rows=$pdo->query("SELECT details FROM audit_logs WHERE action='auth.resident_login'")->fetchAll(PDO::FETCH_COLUMN);
 $assert(count($rows)>0,'No audit record');foreach($rows as$raw){$d=json_decode($raw,true,16,JSON_THROW_ON_ERROR);$assert($d['auth_method']==='phone'&&$d['assurance']==='low'&&$d['phone_verified']===false,'Audit misrepresented identity proof');}
});
fwrite(STDOUT,"{$passed} phone-only resident MySQL groups passed; no external calls or production data\n");
