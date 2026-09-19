<?php
declare(strict_types=1);

use Dormitory\Domain\LineOfficialAccountService;
use Dormitory\Http\HttpException;
use Dormitory\Security\Password;
use Dormitory\Support\LinePlatformSchema;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||preg_match('/^appj_line_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE'))!==1){fwrite(STDERR,"Dedicated LINE testing database required\n");exit(64);}
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
if((int)$pdo->query('SELECT COUNT(*) FROM line_official_accounts WHERE id<>0')->fetchColumn()!==0)throw new RuntimeException('OA regression requires an unused LINE registry');
$passed=0;
$assert=static function(bool $value,string $why='Assertion failed'):void{if(!$value)throw new RuntimeException($why);};
$test=static function(string $name,callable $callback)use(&$passed):void{$callback();$passed++;fwrite(STDOUT,"PASS {$name}\n");};
$expect=static function(callable $callback,string $code)use($assert):void{try{$callback();}catch(HttpException $error){$assert($error->errorCode===$code,'Unexpected error: '.$error->errorCode);return;}throw new RuntimeException('Expected error '.$code);};
$statement=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active)VALUES('oa_test_owner',?,'owner',1,1)");$statement->execute([Password::hash(bin2hex(random_bytes(24)).'Aa1!')]);$admin=(int)$pdo->lastInsertId();
$calls=0;
$transport=static function(string $token)use(&$calls):array{$calls++;return match($token){
    'fixture-token-a','fixture-token-a-rotated'=>['userId'=>'U'.str_repeat('a',32),'basicId'=>'@fixture_a'],
    'fixture-token-b'=>['userId'=>'U'.str_repeat('b',32),'basicId'=>'@fixture_b'],
    'fixture-token-wrong'=>['userId'=>'U'.str_repeat('b',32),'basicId'=>'@fixture_a'],
    'fixture-token-auto'=>['userId'=>'U'.str_repeat('c',32),'basicId'=>'@auto_account','displayName'=>'ชื่อจาก LINE'],
    'fixture-token-legacy'=>['userId'=>'U'.str_repeat('d',32),'basicId'=>'@legacy_auto','displayName'=>'Legacy from LINE'],
    'fixture-token-disabled'=>['userId'=>'U'.str_repeat('e',32),'basicId'=>'@disabled_auto','displayName'=>'Disabled from LINE'],
    default=>throw new RuntimeException('Unexpected identity token fixture'),
};};
$service=new LineOfficialAccountService($app,$transport);
$test('migration installs exact LINE columns, indexes, foreign keys, checks and legacy metadata',static function()use($pdo,$assert):void{$errors=LinePlatformSchema::errors($pdo);$assert($errors===[],implode('; ',$errors));});
$test('legacy account remains configurable metadata without provider calls',static function()use($service,$admin,$assert,&$calls):void{$legacy=$service->update(0,['name'=>'Legacy metadata only'],$admin);$assert($legacy['id']===0&&$legacy['line_binding_ready']===false&&$calls===0);});
$a=null;
$test('disabled OA saves encrypted credentials without any network call',static function()use($service,$admin,$assert,&$a,&$calls):void{
    $a=$service->create(['slug'=>'qa-a','name'=>'QA A','basic_id'=>'@fixture_a','channel_id'=>'123','channel_access_token'=>'fixture-token-a','channel_secret'=>'fixture-secret-a','enabled'=>false],$admin);
    $assert($a['id']===1&&$a['enabled']===false&&$calls===0);$assert($a['channel_access_token_configured']===true);
    $json=json_encode($a,JSON_THROW_ON_ERROR);$assert(!str_contains($json,'fixture-token-a')&&!str_contains($json,'access_token_enc')&&!str_contains($json,'fixture-secret-a'));
});
$test('disabled transport is rejected and enabling pins provider identity',static function()use($service,$admin,$assert,$expect,&$a,&$calls):void{
    $expect(fn()=>$service->credentials($a['id']),'LINE_OA_DISABLED');$a=$service->update($a['id'],['enabled'=>true],$admin);
    $assert($a['line_binding_ready']===true&&$a['provider_user_id']==='U'.str_repeat('a',32)&&$calls===1);
    $assert($service->credentials($a['id'])['access_token']==='fixture-token-a');
});
$test('default selection is unique and never falls back from a disabled OA',static function()use($service,$admin,$assert,$expect,&$a):void{
    $service->setDefault($a['id'],$admin);$assert($service->defaultId()===$a['id']);
    $service->update($a['id'],['enabled'=>false],$admin);$expect(fn()=>$service->defaultId(),'LINE_DEFAULT_NOT_CONFIGURED');
    $service->update($a['id'],['enabled'=>true],$admin);$service->setDefault($a['id'],$admin);
});
$test('blank credentials preserve values and active token rotation keeps the OA identity',static function()use($service,$admin,$assert,&$a):void{
    $service->update($a['id'],['channel_access_token'=>'  ','channel_secret'=>null],$admin);
    $assert($service->credentials($a['id'])['access_token']==='fixture-token-a');
    $service->update($a['id'],['channel_access_token'=>'fixture-token-a-rotated'],$admin);
    $assert($service->credentials($a['id'])['access_token']==='fixture-token-a-rotated');
});
$test('another OA token and clearing an active credential roll back safely',static function()use($service,$admin,$assert,$expect,&$a):void{
    $expect(fn()=>$service->update($a['id'],['channel_access_token'=>'fixture-token-wrong'],$admin),'LINE_OA_IDENTITY_MISMATCH');
    $expect(fn()=>$service->update($a['id'],['channel_secret_clear'=>true],$admin),'LINE_NOT_CONFIGURED');
    $assert($service->credentials($a['id'])['access_token']==='fixture-token-a-rotated');
});
$b=null;
$test('multiple OA credentials have account-specific authenticated encryption',static function()use($service,$admin,$assert,$pdo,&$a,&$b):void{
    $b=$service->create(['slug'=>'qa-b','name'=>'QA B','basic_id'=>'@fixture_b','channel_access_token'=>'fixture-token-b','channel_secret'=>'fixture-secret-b','enabled'=>true],$admin);
    $query=$pdo->prepare('SELECT access_token_enc FROM line_official_accounts WHERE id=?');$query->execute([$a['id']]);$aCipher=$query->fetchColumn();$query->execute([$b['id']]);$bCipher=$query->fetchColumn();
    $update=$pdo->prepare('UPDATE line_official_accounts SET access_token_enc=? WHERE id=?');$update->execute([$aCipher,$b['id']]);
    $rejected=false;try{$service->credentials($b['id']);}catch(RuntimeException){$rejected=true;}finally{$update->execute([$bCipher,$b['id']]);}
    $assert($rejected,'Ciphertext from another OA was accepted');
});
$test('duplicate provider identity and untrusted URLs are rejected',static function()use($service,$admin,$expect):void{
    $expect(fn()=>$service->create(['slug'=>'qa-copy','name'=>'Copy','basic_id'=>'@fixture_a','channel_access_token'=>'fixture-token-a','channel_secret'=>'fixture-secret-a','enabled'=>true],$admin),'LINE_OA_DUPLICATE');
    $expect(fn()=>$service->create(['slug'=>'unsafe','name'=>'Unsafe','add_friend_url'=>'https://line.me.evil.test/steal'],$admin),'VALIDATION_ERROR');
});
$test('registry lock is reentrant, excludes another connection and releases after failure',static function()use($service,$app,$transport,$expect,$assert):void{
    $peer=new LineOfficialAccountService(new Dormitory\Application($app->config),$transport);
    $service->withRegistryLock(function()use($service,$peer,$expect,$assert):void{$assert($service->withRegistryLock(static fn():int=>7,0)===7);$expect(fn()=>$peer->withRegistryLock(static fn():bool=>true,0),'LINE_REGISTRY_BUSY');});
    $assert($peer->withRegistryLock(static fn():bool=>true,0));
    try{$service->withRegistryLock(static fn()=>throw new RuntimeException('fixture lock failure'));}catch(RuntimeException){}
    $assert($peer->withRegistryLock(static fn():bool=>true,0));
});
$test('strict audit and OA changes share the same rollback boundary',static function()use($service,$app,$pdo,$admin,$assert,&$a):void{
    $count=(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();$before=$service->get($a['id'])['name'];
    try{$service->withRegistryLock(fn()=>$app->database()->transaction(function()use($service,$a,$admin):void{$service->update($a['id'],['name'=>'Rollback fixture'],$admin);throw new RuntimeException('fixture audit failure');}));}catch(RuntimeException){}
    $assert($service->get($a['id'])['name']===$before&&(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===$count);
});
$test('route rotation invalidates both the previous token and legacy URL compatibility',static function()use($service,$admin,$pdo,$assert,$expect):void{
    $before=$service->get(0);$old=basename($before['webhook_url']);$after=$service->rotateRoute(0,$admin);
    $assert($before['legacy_route_enabled']===true&&$after['legacy_route_enabled']===false&&$old!==basename($after['webhook_url']));
    $expect(fn()=>$service->byRouteToken($old),'LINE_OA_NOT_FOUND');
    $service->touchWebhook(0,'LINE_TEST_ERROR');$assert($service->get(0)['last_error']==='LINE_TEST_ERROR');
});
$test('soft deletion preserves history while disabling credentials and future routing',static function()use($service,$admin,$assert,$expect,&$b):void{
    $old=basename($b['webhook_url']);$service->remove($b['id'],$admin);$deleted=$service->get($b['id']);
    $assert($deleted['deleted_at']!==null&&!$deleted['enabled']&&!$deleted['line_binding_ready']);
    $expect(fn()=>$service->byRouteToken($old),'LINE_OA_NOT_FOUND');$expect(fn()=>$service->credentials($b['id']),'LINE_OA_NOT_FOUND');
});
$test('audits omit raw credentials, ciphertext and webhook routing tokens',static function()use($pdo,$assert,$service,&$a):void{
    $token=basename($service->get($a['id'])['webhook_url']);
    foreach($pdo->query('SELECT details FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN)as$details){$assert(!str_contains($details,'fixture-token')&&!str_contains($details,'fixture-secret')&&!str_contains($details,$token)&&!str_contains($details,'v1:'));}
});
$test('two credentials discover metadata without replacing a configured default',static function()use($service,$admin,$assert,&$a,&$automatic):void{
    $automatic=$service->create(['name'=>'','slug'=>'','basic_id'=>'','channel_access_token'=>'fixture-token-auto','channel_secret'=>'fixture-secret-auto','enabled'=>true],$admin);
    $assert($automatic['name']==='ชื่อจาก LINE'&&$automatic['basic_id']==='@auto_account');
    $assert(preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D',$automatic['slug'])===1);
    $assert($automatic['line_add_friend_url']==='https://line.me/R/ti/p/%40auto_account');
    $assert($automatic['identity_verified']===true&&$automatic['webhook_verified']===false&&$automatic['operational_ready']===false);
    $assert($service->defaultId()===$a['id']);
    $tested=$service->test($automatic['id'],$admin);
    $assert($tested['identity_verified']===true&&$tested['ready']===false,'Bot info alone must not claim webhook readiness');
});
$test('signed empty webhook confirms the secret and secret rotation requires a fresh callback',static function()use($app,$service,$admin,$assert,$expect,&$automatic):void{
    $id=$automatic['id'];$oa=$service->credentials($id);
    $raw=json_encode(['destination'=>$oa['provider_user_id'],'events'=>[]],JSON_THROW_ON_ERROR);
    $request=new Dormitory\Http\Request('POST','/api/webhooks/line/oa/'.$oa['route_token'],['content-type'=>'application/json','x-line-signature'=>base64_encode(hash_hmac('sha256',$raw,$oa['channel_secret'],true))],[],[],[],[],'oa-setup-test',$raw);
    $webhook=new Dormitory\Domain\LineWebhookService($app,null,$id);
    $webhook->handle($request);
    $assert($service->get($id)['operational_ready']===true);
    $service->update($id,['channel_secret'=>'fixture-secret-rotated'],$admin);
    $assert($service->get($id)['webhook_verified']===false);
    $expect(fn()=>$webhook->handle($request),'LINE_WEBHOOK_SIGNATURE_INVALID');
    $assert($service->test($id,$admin)['ready']===false);
    $service->touchWebhook($id,'LINE_DESTINATION_MISMATCH');
    $assert($service->test($id,$admin)['ready']===false,'Identity test must not erase webhook errors');
});
$test('legacy quick setup discovers Basic ID and name transactionally',static function()use($app,$service,$admin,$assert,$expect):void{
    $legacy=$service->update(0,['name'=>'','slug'=>'','basic_id'=>'','channel_access_token'=>'fixture-token-legacy','channel_secret'=>'fixture-secret-legacy','enabled'=>true],$admin);
    $assert($legacy['name']==='Legacy from LINE'&&$legacy['basic_id']==='@legacy_auto'&&$legacy['slug']==='legacy');
    $assert($legacy['identity_verified']===true&&$legacy['webhook_verified']===false);
    $assert($app->settings()->value('LINE_BASIC_ID')==='@legacy_auto');
    $expect(fn()=>$service->update(0,['basic_id'=>'','channel_access_token'=>'fixture-token-auto'],$admin),'LINE_OA_IDENTITY_MISMATCH');
    $assert($app->settings()->value('LINE_BASIC_ID')==='@legacy_auto','Failed identity rotation must restore the Basic ID');
});
$test('testing a disabled draft persists discovered Basic ID without enabling it',static function()use($service,$admin,$assert):void{
    $draft=$service->create(['name'=>'Draft','slug'=>'draft','channel_access_token'=>'fixture-token-disabled','enabled'=>false],$admin);
    $tested=$service->test($draft['id'],$admin);
    $assert($tested['ready']===false&&$tested['account']['enabled']===false);
    $assert($service->get($draft['id'])['basic_id']==='@disabled_auto');
});
fwrite(STDOUT,"{$passed} LINE OA MySQL tests passed\n");
