<?php
declare(strict_types=1);
use Dormitory\Domain\LineOfficialAccountService;
use Dormitory\Http\HttpException;
use Dormitory\Security\Password;
use Dormitory\Security\SecretCipher;
use Dormitory\Support\LinePlatformSchema;
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||preg_match('/^appj_line_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE'))!==1){fwrite(STDERR,"Dedicated LINE testing database required\n");exit(64);}
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
if((int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()!==0)throw new RuntimeException('Fresh OA test database required');
$passed=0;$assert=static function(bool $v,string $why='Assertion failed'):void{if(!$v)throw new RuntimeException($why);};
$test=static function(string $name,callable $fn)use(&$passed):void{$fn();$passed++;fwrite(STDOUT,"PASS {$name}\n");};
$expect=static function(callable $fn,string $code)use($assert):void{try{$fn();}catch(HttpException $e){$assert($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);};
$q=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active)VALUES('oa_test_owner',?,'owner',1,1)");$q->execute([Password::hash(bin2hex(random_bytes(24)).'Aa1!')]);$admin=(int)$pdo->lastInsertId();
$calls=0;$display='ชื่อบัญชีจาก LINE';$basic='@fixture_a';
$transport=static function(string $token)use(&$calls,&$display,&$basic):array{$calls++;return match($token){
 'fixture-token-a','fixture-token-a-rotated'=>['userId'=>'U'.str_repeat('a',32),'basicId'=>$basic,'displayName'=>$display],
 'fixture-token-b'=>['userId'=>'U'.str_repeat('b',32),'basicId'=>'@fixture_b','displayName'=>'Different bot'],
 'fixture-token-malformed'=>['basicId'=>'@invalid'],
 default=>throw new RuntimeException('Unknown fixture token'),
};};
$service=new LineOfficialAccountService($app,$transport);(new ReflectionProperty($app,'lineOfficialAccounts'))->setValue($app,$service);
$test('canonical LINE schema is unchanged',function()use($pdo,$assert):void{$assert(LinePlatformSchema::errors($pdo)===[]);});
$test('first connection requires both secrets and rejects manual identity fields',function()use($service,$admin,$expect,&$calls,$assert):void{
 $expect(fn()=>$service->update(0,['enabled'=>true],$admin),'LINE_NOT_CONFIGURED');
 foreach(['name','slug','basic_id','channel_id','description','add_friend_url']as$field)$expect(fn()=>$service->update(0,[$field=>'manual'],$admin),'UNKNOWN_FIELDS');
 $assert($calls===0);
});
$test('disabled draft encrypts credentials and makes no provider call',function()use($service,$admin,$assert,&$calls):void{
 $row=$service->update(0,['channel_access_token'=>'fixture-token-a','channel_secret'=>'fixture-secret-a','enabled'=>false],$admin);
 $assert(!$row['enabled']&&$calls===0&&$row['channel_access_token_configured']);
 $json=json_encode($row,JSON_THROW_ON_ERROR);$assert(!str_contains($json,'fixture-token-a')&&!str_contains($json,'fixture-secret-a')&&!str_contains($json,'access_token_enc'));
});
$test('testing a disabled draft discovers identity without enabling or verifying webhook',function()use($service,$admin,$assert,$expect,&$calls):void{
 $expect(fn()=>$service->credentials(0),'LINE_OA_DISABLED');$tested=$service->test(0,$admin);
 $assert(!$tested['ready']&&!$tested['account']['enabled']&&$calls===1);
 $assert($tested['account']['basic_id']==='@fixture_a'&&$tested['account']['name']==='ชื่อบัญชีจาก LINE');
});
$test('enabling uses the same primary bot and generates links from provider identity',function()use($service,$admin,$assert):void{
 $row=$service->update(0,['enabled'=>true],$admin);
 $assert($row['identity_verified']&&!$row['webhook_verified']&&$service->defaultId()===0);
 $assert($row['line_add_friend_url']==='https://line.me/R/ti/p/%40fixture_a'&&count($service->all())===1);
});
$test('blank fields preserve encrypted credentials and token rotation pins the same bot',function()use($service,$admin,$assert):void{
 $service->update(0,['channel_access_token'=>'  ','channel_secret'=>null],$admin);
 $assert($service->credentials(0)['access_token']==='fixture-token-a');
 $service->update(0,['channel_access_token'=>'fixture-token-a-rotated'],$admin);
 $assert($service->credentials(0)['access_token']==='fixture-token-a-rotated');
});
$test('wrong bot and malformed provider data roll back credentials and audit',function()use($service,$pdo,$admin,$expect,$assert):void{
 $before=(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
 $expect(fn()=>$service->update(0,['channel_access_token'=>'fixture-token-b'],$admin),'LINE_OA_IDENTITY_MISMATCH');
 $expect(fn()=>$service->update(0,['channel_access_token'=>'fixture-token-malformed'],$admin),'LINE_TEST_FAILED');
 $assert($service->credentials(0)['access_token']==='fixture-token-a-rotated'&&(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===$before);
});
$test('current name and Basic ID refresh automatically for the pinned provider',function()use($service,$admin,$assert,&$display,&$basic):void{
 $display='ชื่อใหม่จาก LINE';$basic='@fixture_renamed';$row=$service->update(0,['enabled'=>true],$admin);
 $assert($row['name']===$display&&$row['basic_id']===$basic&&$row['line_add_friend_url']==='https://line.me/R/ti/p/%40fixture_renamed');
});
$test('single bot cannot be created again, deleted, or switched to an arbitrary ID',function()use($service,$admin,$expect):void{
 $expect(fn()=>$service->create([],$admin),'LINE_SINGLE_BOT_ONLY');$expect(fn()=>$service->remove(0,$admin),'LINE_SINGLE_BOT_ONLY');
 foreach([1,2,999]as$id){$expect(fn()=>$service->update($id,[],$admin),'LINE_SINGLE_BOT_ONLY');$expect(fn()=>$service->credentials($id),'LINE_SINGLE_BOT_ONLY');$expect(fn()=>$service->setDefault($id,$admin),'LINE_SINGLE_BOT_ONLY');}
});
$test('encrypted credential cannot be reused in the other secret field',function()use($pdo,$app,$assert):void{
 $encoded=(string)$pdo->query('SELECT line_channel_access_token_enc FROM integration_settings WHERE id=1')->fetchColumn();$cipher=new SecretCipher($app->config);
 $assert($cipher->decrypt($encoded,'line_channel_access_token')==='fixture-token-a-rotated');
 $rejected=false;try{$cipher->decrypt($encoded,'line_channel_secret');}catch(RuntimeException){$rejected=true;}$assert($rejected);
});
$test('registry lock is reentrant and excludes a peer without leaking a lock',function()use($service,$app,$transport,$expect,$assert):void{
 $peer=new LineOfficialAccountService(new Dormitory\Application($app->config),$transport);
 $service->withRegistryLock(function()use($service,$peer,$expect,$assert):void{$assert($service->withRegistryLock(static fn()=>7,0)===7);$expect(fn()=>$peer->withRegistryLock(static fn()=>true,0),'LINE_REGISTRY_BUSY');});
 try{$service->withRegistryLock(static fn()=>throw new RuntimeException('Test rollback'));}catch(RuntimeException){}
 $assert($peer->withRegistryLock(static fn()=>true,0));
});
$test('credential and strict audit updates share the transaction rollback',function()use($service,$app,$pdo,$admin,$assert):void{
 $before=(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
 try{$app->database()->transaction(function()use($service,$admin):void{$service->update(0,['enabled'=>false],$admin);throw new RuntimeException('Test rollback');});}catch(RuntimeException){}
 $assert($service->get(0)['enabled']&&(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===$before);
});
$test('clearing an enabled credential is rejected until explicitly paused',function()use($service,$admin,$assert,$expect):void{
 $expect(fn()=>$service->update(0,['channel_secret_clear'=>true],$admin),'LINE_NOT_CONFIGURED');
 $service->update(0,['enabled'=>false,'channel_secret_clear'=>true],$admin);$assert(!$service->get(0)['channel_secret_configured']);
 $expect(fn()=>$service->update(0,['enabled'=>true],$admin),'LINE_NOT_CONFIGURED');
 $service->update(0,['channel_secret'=>'fixture-secret-a','enabled'=>true],$admin);
});
$test('route rotation invalidates the old route and legacy compatibility flag',function()use($service,$admin,$expect,$assert):void{
 $old=basename($service->get(0)['webhook_url']);$row=$service->rotateRoute(0,$admin);
 $assert(!$row['legacy_route_enabled']&&basename($row['webhook_url'])!==$old);$expect(fn()=>$service->byRouteToken($old),'LINE_OA_NOT_FOUND');
});
$test('signed webhook proves callback readiness and secret rotation invalidates it',function()use($service,$app,$admin,$assert,$expect):void{
 $oa=$service->credentials(0);$raw=json_encode(['destination'=>$oa['provider_user_id'],'events'=>[]],JSON_THROW_ON_ERROR);
 $request=new Dormitory\Http\Request('POST','/api/webhooks/line/oa/'.$oa['route_token'],['content-type'=>'application/json','x-line-signature'=>base64_encode(hash_hmac('sha256',$raw,$oa['channel_secret'],true))],[],[],[],[],'single-bot-test',$raw);
 $webhook=new Dormitory\Domain\LineWebhookService($app,null,0);$webhook->handle($request);$assert($service->get(0)['operational_ready']);
 $service->update(0,['channel_secret'=>'fixture-secret-rotated'],$admin);$assert(!$service->get(0)['webhook_verified']);
 $expect(fn()=>$webhook->handle($request),'LINE_WEBHOOK_SIGNATURE_INVALID');$assert(!$service->test(0,$admin)['ready']);
});
$test('identity check cannot erase a callback error or claim delivery readiness',function()use($service,$admin,$assert):void{
 $service->touchWebhook(0,'LINE_DESTINATION_MISMATCH');$result=$service->test(0,$admin);
 $assert(!$result['ready']&&$service->get(0)['last_error']==='LINE_DESTINATION_MISMATCH');
});
$test('disable and resume preserve the pinned identity and have a deterministic default',function()use($service,$admin,$assert,$expect):void{
 $id=$service->get(0)['provider_user_id'];$service->update(0,['enabled'=>false],$admin);$expect(fn()=>$service->defaultId(),'LINE_DEFAULT_NOT_CONFIGURED');
 $service->update(0,['enabled'=>true],$admin);$assert($service->defaultId()===0&&$service->get(0)['provider_user_id']===$id);
});
$test('audits contain neither credentials nor encrypted payloads nor route tokens',function()use($pdo,$service,$assert):void{
 $route=basename($service->get(0)['webhook_url']);foreach($pdo->query('SELECT details FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN)as$details){
  $assert(!str_contains($details,'fixture-token')&&!str_contains($details,'fixture-secret')&&!str_contains($details,$route)&&!str_contains($details,'v1:'));
 }
});
fwrite(STDOUT,"{$passed} single-bot LINE OA MySQL tests passed; provider transport simulated\n");
