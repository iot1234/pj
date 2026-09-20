<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!isset($service,$test,$transport))throw new RuntimeException('LINE OA test fixture required');
$previousUrl=getenv('APP_URL');putenv('APP_URL=https://line-connection.example.test');
try {
 $service->touchWebhook(0);
 $remote=['endpoint'=>$service->get(0)['webhook_url'],'active'=>true];
 $peerRegistry=new Dormitory\Domain\LineOfficialAccountService(new Dormitory\Application($app->config),$transport);
 $live=new Dormitory\Domain\LineOfficialAccountService($app,$transport,static function(string $token)use(&$remote,$peerRegistry,$pdo,$assert):array{
  $assert(!$pdo->inTransaction(),'Remote endpoint read must not hold a DB transaction');
  $assert($peerRegistry->withRegistryLock(static fn()=>true,0),'Remote endpoint read held the registry lock');
  if($remote instanceof Throwable)throw $remote;
  return $remote;
 });
 $test('live check verifies remote endpoint and activation outside registry and transaction locks',function()use($live,$admin,$assert):void{
  $result=$live->checkConnection(0,$admin);$assert($result['ready']&&$result['connection']['status']==='ready');
  $assert(!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'fixture-token'),'Live status leaked a token');
 });
 $test('remote webhook disabled or wrong URL never reports ready despite historical proof',function()use($live,$service,$admin,$assert,&$remote):void{
  $remote=['endpoint'=>$service->get(0)['webhook_url'],'active'=>false];$r=$live->checkConnection(0,$admin);$assert(!$r['ready']&&$r['connection']['status']==='webhook_disabled');
  $remote=['endpoint'=>'https://other.example.test/webhook','active'=>true];$r=$live->checkConnection(0,$admin);$assert(!$r['ready']&&$r['connection']['status']==='endpoint_mismatch');
 });
 $test('missing, unavailable and malformed remote webhook results are explicit non-ready states',function()use($live,$admin,$assert,&$remote):void{
  foreach(['LINE_WEBHOOK_NOT_SET','LINE_CONNECT_TIMEOUT','LINE_TLS_ERROR']as$code){$remote=new Dormitory\Http\HttpException(502,'Safe fixture failure',$code);$r=$live->checkConnection(0,$admin);$assert(!$r['ready']&&$r['connection']['status']===$code);}
  $remote=['active'=>'true'];$r=$live->checkConnection(0,$admin);$assert(!$r['ready']&&$r['connection']['status']==='LINE_RESPONSE_INVALID');
 });
 $test('a concurrent configuration change invalidates the live check snapshot',function()use($app,$service,$transport,$admin,$expect):void{
  $changing=new Dormitory\Domain\LineOfficialAccountService($app,$transport,function(string $token)use($service,$admin):array{
   $url=$service->get(0)['webhook_url'];$service->update(0,['enabled'=>false],$admin);return ['endpoint'=>$url,'active'=>true];
  });
  $expect(fn()=>$changing->checkConnection(0,$admin),'LINE_CONFIGURATION_CHANGED');$service->update(0,['enabled'=>true],$admin);
 });
 $test('a paused bot is reported as paused without a remote webhook request',function()use($app,$service,$transport,$admin,$assert):void{
  $service->update(0,['enabled'=>false],$admin);$calls=0;
  $paused=new Dormitory\Domain\LineOfficialAccountService($app,$transport,function(string $token)use(&$calls):array{$calls++;return ['endpoint'=>'','active'=>false];});
  $result=$paused->checkConnection(0,$admin);$assert(!$result['ready']&&$result['connection']['status']==='disabled'&&$calls===0);
  $service->update(0,['enabled'=>true],$admin);
 });
 $test('setup row lock contention fails promptly and restores the connection lock timeout',function()use($app,$service,$pdo,$admin,$assert,$expect):void{
  $peer=(new Dormitory\Database($app->config))->pdo();$before=(int)$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
  $peer->beginTransaction();$peer->query('SELECT id FROM line_official_accounts WHERE id=0 FOR UPDATE')->fetch();$start=microtime(true);
  try{$expect(fn()=>$service->update(0,['enabled'=>false],$admin),'LINE_REGISTRY_BUSY');}finally{$peer->rollBack();}
  $assert(microtime(true)-$start<6,'Setup silently retried the blocked transaction');
  $assert((int)$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn()===$before,'Setup timeout leaked into other operations');
  $assert($service->get(0)['enabled'],'Failed setup changed the bot');
 });
} finally { $previousUrl===false?putenv('APP_URL'):putenv('APP_URL='.$previousUrl); }
