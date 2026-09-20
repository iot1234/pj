<?php
declare(strict_types=1);
// Included by tests/run.php: no database or network access.
$test('LINE setup maps provider and network errors without exposing raw diagnostics',function()use($same):void{
 $guard=new ReflectionMethod(Dormitory\Domain\LineOfficialAccountService::class,'assertSetupResponse');
 $guard->invoke(null,200,0,false,'/v2/bot/info');
 foreach([[0,28,'LINE_CONNECT_TIMEOUT'],[0,6,'LINE_DNS_ERROR'],[0,60,'LINE_TLS_ERROR'],[0,77,'LINE_TLS_ERROR'],[0,7,'LINE_CONNECT_FAILED'],[401,0,'LINE_TOKEN_REJECTED'],[403,0,'LINE_TOKEN_REJECTED'],[429,0,'LINE_API_RATE_LIMITED'],[503,0,'LINE_API_UNAVAILABLE']]as[$http,$errno,$code]){
  try{$guard->invoke(null,$http,$errno,false,'/v2/bot/info');throw new RuntimeException('Expected '.$code);}
  catch(Dormitory\Http\HttpException $e){$same($code,$e->errorCode);$same(false,str_contains($e->getMessage(),'Bearer'));}
 }
 try{$guard->invoke(null,404,0,false,'/v2/bot/channel/webhook/endpoint');throw new RuntimeException('Expected missing webhook');}
 catch(Dormitory\Http\HttpException $e){$same('LINE_WEBHOOK_NOT_SET',$e->errorCode);}
});
$test('LINE readiness requires current remote activation, matching URL and signed callback evidence',function()use($same):void{
 $fn=new ReflectionMethod(Dormitory\Domain\LineOfficialAccountService::class,'connectionState');
 $url='https://dorm.example.test/api/webhooks/line/oa/'.str_repeat('a',48);
 $row=['enabled'=>true,'credentials_ready'=>true,'identity_verified'=>true,'webhook_verified'=>true,'webhook_url'=>$url,'last_error'=>null,'legacy_route_enabled'=>false];
 $remote=['endpoint'=>$url,'active'=>true];$same(true,$fn->invoke(null,$row,$remote)['ready']);
 foreach([['enabled',false,'disabled'],['credentials_ready',false,'missing_credentials'],['identity_verified',false,'identity_unverified'],['webhook_verified',false,'awaiting_callback'],['last_error','LINE_DESTINATION_MISMATCH','callback_error'],['webhook_url','http://localhost/test','public_url_invalid']]as[$key,$value,$status]){
  $result=$fn->invoke(null,array_replace($row,[$key=>$value]),$remote);$same(false,$result['ready']);$same($status,$result['status']);
 }
 $same('webhook_disabled',$fn->invoke(null,$row,array_replace($remote,['active'=>false]))['status']);
 $same('endpoint_mismatch',$fn->invoke(null,$row,array_replace($remote,['endpoint'=>$url.'wrong']))['status']);
 $same('unchecked',$fn->invoke(null,$row,null)['status']);
 $same(null,$fn->invoke(null,$row,null)['endpoint_matches']);
});
$test('legacy webhook URL is accepted only while explicit compatibility is enabled',function()use($same):void{
 $fn=new ReflectionMethod(Dormitory\Domain\LineOfficialAccountService::class,'connectionState');$url='https://dorm.example.test/api/webhooks/line';
 $row=['enabled'=>true,'credentials_ready'=>true,'identity_verified'=>true,'webhook_verified'=>true,'webhook_url'=>$url.'/oa/'.str_repeat('a',48),'legacy_route_enabled'=>false];
 $remote=['endpoint'=>$url,'active'=>true];$same(false,$fn->invoke(null,$row,$remote)['ready']);
 $same(true,$fn->invoke(null,array_replace($row,['legacy_route_enabled'=>true]),$remote)['ready']);
});
$test('LINE setup transport is HTTPS-only, bounded and never disables certificate validation',function()use($same):void{
 $source=file_get_contents(dirname(__DIR__).'/src/Domain/LineOfficialAccountService.php');
 foreach(['Content-Type: application/json','CURLOPT_TIMEOUT=>8','CURLOPT_CONNECTTIMEOUT=>3','CURLOPT_SSL_VERIFYPEER=>true','CURLOPT_SSL_VERIFYHOST=>2','CURLOPT_FOLLOWLOCATION=>false','SET SESSION innodb_lock_wait_timeout=3']as$guard)$same(true,str_contains($source,$guard));
 $same(false,str_contains($source,'curl_error('));
 $routes=file_get_contents(dirname(__DIR__).'/src/Http/LineAdminRoutes.php');$same(true,str_contains($routes,"'test'=>'checkConnection'"));
});
