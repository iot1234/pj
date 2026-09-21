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
$residents=[];$rooms=[];
foreach(['0817900001','0817900002']as$i=>$phone){
 $room=$app->rooms()->create(['room_code'=>'PHONE-'.($i+1),'floor'=>1,'room_type'=>'test','monthly_rent'=>'3000.00']);$rooms[]=$room;
 $residents[]=$app->bookings()->createAdminResident($owner,['room_id'=>$room['id'],'full_name'=>'Phone fixture '.($i+1),'phone'=>$phone,'move_in_date'=>$today,'opening_water_reading'=>'100','opening_electric_reading'=>'200','idempotency_key'=>'phone-only-fixture-2026-'.$i]);
}
$firstId=(int)$residents[0]['resident_id'];$firstRoom=(int)$rooms[0]['id'];$firstOccupancy=(int)$residents[0]['occupancy_id'];
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
 $actor=$app->actor(true);$actor['room_id']+=100;$app->session()->login($actor);$app->clearActorCache();$assert($app->actor(true)===null,'Mismatched room retained access');
 $actor=$app->auth()->residentLogin($request(),['phone'=>'0817900001']);$actor['occupancy_id']+=100;$app->session()->login($actor);$app->clearActorCache();$assert($app->actor(true)===null,'Mismatched occupancy retained access');
});
$test('changing a phone invalidates the old session and old number',function()use($app,$request,$firstId,$expect,$assert):void{
 $app->auth()->residentLogin($request(),['phone'=>'0817900001']);$app->residents()->updateByAdmin($firstId,['phone'=>'0817900003']);
 $assert($app->actor(true)===null,'Old session survived phone change');$expect(fn()=>$app->auth()->residentLogin($request(),['phone'=>'0817900001']),'INVALID_CREDENTIALS');
 $a=$app->auth()->residentLogin($request(),['phone'=>'0817900003']);$assert($a['id']===$firstId,'Updated phone cannot enter');
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
