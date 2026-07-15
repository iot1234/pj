<?php
declare(strict_types=1);

use Dormitory\Domain\PromptPayService;
use Dormitory\Domain\BillingService;
use Dormitory\Domain\AdminUserService;
use Dormitory\Domain\PaymentService;
use Dormitory\AuditLogger;
use Dormitory\Config;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Integration\SlipVerifier;
use Dormitory\Security\Password;
use Dormitory\Security\SecretCipher;
use Dormitory\Support\Validator;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

// Keep the test process isolated from a developer's local/production .env.
// Explicit process variables (for example from CI) still take precedence.
$testEnvironmentDefaults=[
    'APP_ENV'=>'testing',
    'APP_DEBUG'=>'false',
    'APP_URL'=>'http://localhost',
    'APP_TIMEZONE'=>'Asia/Bangkok',
    'APP_KEY'=>'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    'FORCE_HTTPS'=>'false',
    'TRUSTED_PROXIES'=>'',
    'RUNTIME_ROLE'=>'job',
    'SESSION_NAME'=>'dormitory_test_session',
    'SESSION_LIFETIME_SECONDS'=>'3600',
    'DB_HOST'=>'127.0.0.1',
    'DB_PORT'=>'3306',
    'DB_DATABASE'=>'testing',
    'DB_USERNAME'=>'testing',
    'DB_PASSWORD'=>'testing-only',
    'DB_SSL'=>'false',
    'DB_SSL_CA'=>'',
];
foreach($testEnvironmentDefaults as$key=>$value){
    if(getenv($key)!==false)continue;
    putenv($key.'='.$value);
    $_ENV[$key]=$value;
    $_SERVER[$key]=$value;
}

$app=require dirname(__DIR__).'/bootstrap.php';
$passed=0;$failed=0;
$test=static function(string $name,callable $callback)use(&$passed,&$failed):void{try{$callback();$passed++;fwrite(STDOUT,"PASS {$name}".PHP_EOL);}catch(Throwable $e){$failed++;fwrite(STDERR,"FAIL {$name}: {$e->getMessage()}".PHP_EOL);}};
$same=static function(mixed $expected,mixed $actual):void{if($expected!==$actual)throw new RuntimeException('expected '.var_export($expected,true).', got '.var_export($actual,true));};
$throws=static function(callable $callback,string $code):void{try{$callback();}catch(HttpException $e){if($e->errorCode!==$code)throw new RuntimeException("expected {$code}, got {$e->errorCode}");return;}throw new RuntimeException("expected exception {$code}");};
$throwsHttp=static function(callable $callback,string $code,int $status):void{try{$callback();}catch(HttpException $e){if($e->errorCode!==$code||$e->status!==$status)throw new RuntimeException("expected {$status} {$code}, got {$e->status} {$e->errorCode}");return;}throw new RuntimeException("expected exception {$status} {$code}");};

$test('Thai phone normalization',function()use($same):void{$same('0812345678',Validator::phone('+66 81-234-5678'));$same('0812345678',Validator::phone('66812345678'));$same('0812345678',Validator::phone('081.234.5678'));});
$test('Thai phone rejection',fn()=>$throws(fn()=>Validator::phone('12345'),'VALIDATION_ERROR'));
$test('period canonicalization',function()use($same):void{$same('2026-07',Validator::period('2026-07'));$same('2026-07-01',Validator::periodDate('2026-07'));});
$test('period rejection',fn()=>$throws(fn()=>Validator::period('2026-13'),'VALIDATION_ERROR'));
$test('scaled decimal exactness',function()use($same):void{$same(123456,Validator::scaledDecimal('1234.56','amount'));$same('1234.56',Validator::decimalString(123456));});
$test('decimal rejects excess precision',fn()=>$throws(fn()=>Validator::scaledDecimal('1.001','amount'),'VALIDATION_ERROR'));
$test('unknown fields rejected',fn()=>$throws(fn()=>Validator::only(['safe'=>1,'password'=>2],['safe']),'UNKNOWN_FIELDS'));
$test('FR-10 resident lifecycle endpoints are explicitly allowlisted',function()use($same,$app):void{
    $property=new ReflectionProperty(Dormitory\Http\Router::class,'routes');
    $routes=$property->getValue(Dormitory\Http\Routes::build($app));
    $methods=[];
    foreach($routes as$route){if(str_contains($route['regex'],'api/admin/residents'))$methods[]=$route['method'];}
    $same(['GET','PUT','POST','POST'],$methods);
});
$test('FR-16 payment recovery has no manual paid endpoint',function()use($same,$app):void{
    $property=new ReflectionProperty(Dormitory\Http\Router::class,'routes');
    $routes=$property->getValue(Dormitory\Http\Routes::build($app));
    $methods=[];$paymentRoutes=[];
    foreach($routes as$route){if(str_contains($route['regex'],'api/admin/payments')){$methods[]=$route['method'];$paymentRoutes[]=$route['regex'];}}
    $same(['GET','GET','POST','POST'],$methods);
    foreach($paymentRoutes as$regex){if(preg_match('/approve|manual|mark.paid/i',$regex))throw new RuntimeException('manual payment approval route exists');}
});
$test('all SQL bootstraps include 15 integrity triggers',function()use($same):void{
    $root=dirname(__DIR__);
    foreach(['database/schema.sql','database/migrations/002_operational_hardening.sql']as$file){
        $sql=file_get_contents($root.'/'.$file);if(!is_string($sql))throw new RuntimeException("cannot read {$file}");
        preg_match_all('/^CREATE TRIGGER\s+([a-z0-9_]+)/mi',$sql,$matches);
        $same(15,count(array_unique($matches[1])));
    }
    $repair=file_get_contents($root.'/database/migrations/003_append_only_guards.sql');if(!is_string($repair))throw new RuntimeException('cannot read migration 003');
    preg_match_all('/^CREATE TRIGGER\s+([a-z0-9_]+)/mi',$repair,$matches);$same(4,count(array_unique($matches[1])));
    $installer=file_get_contents($root.'/database/install.sql');if(!is_string($installer))throw new RuntimeException('cannot read fresh installer');
    preg_match_all('/^CREATE TRIGGER\s+([a-z0-9_]+)/mi',$installer,$matches);$same(15,count(array_unique($matches[1])));
    $same(1,preg_match('/CREATE DATABASE IF NOT EXISTS dormitory/i',$installer));
    $same(1,preg_match('/DORMITORY_INSTALL_ABORT_DATABASE_NOT_EMPTY/i',$installer));
    $same(1,preg_match('/information_schema\.tables\s+WHERE table_schema = DATABASE\(\)/is',$installer));
    $same(1,preg_match('/INSERT IGNORE INTO billing_settings/i',$installer));
});
$test('PromptPay QR is blocked until the full payment path is ready',function()use($same,$throwsHttp,$app):void{
    $base=['status'=>'pending','payment_capabilities'=>['promptpay_ready'=>true,'slip_verification_ready'=>true],'payment'=>null];
    $throwsHttp(fn()=>$app->billing()->assertPromptPayAvailable([...$base,'status'=>'paid']),'BILL_ALREADY_PAID',409);
    $throwsHttp(fn()=>$app->billing()->assertPromptPayAvailable([...$base,'payment_capabilities'=>['promptpay_ready'=>false,'slip_verification_ready'=>true]]),'PROMPTPAY_NOT_CONFIGURED',503);
    $throwsHttp(fn()=>$app->billing()->assertPromptPayAvailable([...$base,'payment_capabilities'=>['promptpay_ready'=>true,'slip_verification_ready'=>false]]),'SLIP_NOT_CONFIGURED',503);
    $throwsHttp(fn()=>$app->billing()->assertPromptPayAvailable([...$base,'payment'=>['status'=>'pending']]),'PAYMENT_ALREADY_PENDING',409);
    $throwsHttp(fn()=>$app->billing()->assertPromptPayAvailable([...$base,'payment'=>['status'=>'verified']]),'PAYMENT_ALREADY_PENDING',409);
    $app->billing()->assertPromptPayAvailable([...$base,'payment'=>['status'=>'rejected']]);
    $routes=file_get_contents(dirname(__DIR__).'/src/Http/Routes.php');if(!is_string($routes))throw new RuntimeException('cannot read routes');
    $same(1,preg_match("#/api/resident/bills/\{id\}/promptpay'.*?residentDetail.*?assertPromptPayAvailable.*?PromptPayService::payload#s",$routes));
});
$test('critical usability guards remain in the web UI',function()use($same):void{
    $root=dirname(__DIR__);$js=file_get_contents($root.'/public/assets/js/app.js');$admin=file_get_contents($root.'/templates/admin/console.php');
    if(!is_string($js)||!is_string($admin))throw new RuntimeException('cannot read UI sources');
    $same(1,preg_match('/paymentConfigurationReady\s*=\s*promptPayReady\s*&&\s*slipReady/',$js));
    $same(1,preg_match('/function applySavedMeterResult\s*\(/',$js));
    $same(1,preg_match('/name="confirm_pin"[^>]*required/',$admin));
    $same(1,preg_match('/จำนวนเงินอื่น \/ ห้อง/',$admin));
    $same(1,preg_match('/water_units.*water_rate.*water_amount/s',$js));
});
$test('Apache permits the hidden-file access guard',function()use($same):void{
    $root=dirname(__DIR__);
    $htaccess=file_get_contents($root.'/public/.htaccess');
    $vhost=file_get_contents($root.'/apache-vhost.conf');
    if(!is_string($htaccess)||!is_string($vhost))throw new RuntimeException('cannot read Apache configuration');
    $same(1,preg_match('/<FilesMatch\s+"\^\\\."\s*>\s*Require\s+all\s+denied\s*<\/FilesMatch>/si',$htaccess));
    $same(1,preg_match('/<Directory\s+\/var\/www\/html\/public>.*?AllowOverride[^\r\n]*\bAuthConfig\b.*?<\/Directory>/si',$vhost));
});
$test('strict boolean validation',function()use($same,$throws):void{$same(false,Validator::boolean(false,'active'));$same(true,Validator::boolean(true,'active'));$same(false,Validator::boolean(0,'active'));$same(true,Validator::boolean(1,'active'));$throws(fn()=>Validator::boolean('false','active'),'VALIDATION_ERROR');$throws(fn()=>Validator::boolean(2,'active'),'VALIDATION_ERROR');});
$test('admin active aliases are strict and unambiguous',function()use($same,$throws):void{$active=new ReflectionMethod(AdminUserService::class,'requestedActive');$same(false,$active->invoke(null,['active'=>false]));$same(true,$active->invoke(null,['is_active'=>1]));$throws(fn()=>$active->invoke(null,['active'=>'false']),'VALIDATION_ERROR');$throws(fn()=>$active->invoke(null,['active'=>true,'is_active'=>false]),'VALIDATION_ERROR');});
$test('PromptPay phone CRC vector',function()use($same):void{$same('00020101021229370016A000000677010111011300668123456785802TH530376454071234.566304D937',PromptPayService::payload('0812345678','1234.56'));});
$test('PromptPay tax ID CRC vector',function()use($same):void{$same('00020101021229370016A000000677010111021312345678901235802TH530376454041.006304304C',PromptPayService::payload('1234567890123','1.00'));});
$test('receiver requires a collision-resistant tail',function()use($same):void{$same(true,PromptPayService::receiverMatches('0812345678','xxx-xx-2345678'));$same(true,PromptPayService::receiverMatches('123456','000123456'));$same(false,PromptPayService::receiverMatches('4567','0004567'));$same(false,PromptPayService::receiverMatches('123456','000123457'));});
$test('SlipOK trusts only the configured branch account contract',function()use($same):void{
    $same(true,PromptPayService::providerReceiverMatches('slipok','123109','xxx-x-x3109-x',true,'branch-a','branch-a'));
    $same(false,PromptPayService::providerReceiverMatches('slipok','123109','xxx-x-x3109-x',false,'branch-a','branch-a'));
    $same(false,PromptPayService::providerReceiverMatches('slipok','123109','xxx-x-x3109-x',true,'branch-a','branch-b'));
});
$test('EasySlip compares configured tail with registered matched account',function()use($same):void{
    $same(true,PromptPayService::providerReceiverMatches('easyslip','567890','123-4-56789-0',true));
    $same(false,PromptPayService::providerReceiverMatches('easyslip','111111','123-4-56789-0',true));
    $same(false,PromptPayService::providerReceiverMatches('easyslip','567890','xxx-x-x789-x',true));
    $same(false,PromptPayService::providerReceiverMatches('easyslip','567890','123-4-56789-0',false));
});
$test('integration secret encryption authenticates value and field',function()use($same,$app):void{
    $cipher=new SecretCipher($app->config);$payload=$cipher->encrypt('line-token-example','line_channel_access_token');
    $same(false,str_contains($payload,'line-token-example'));$same('line-token-example',$cipher->decrypt($payload,'line_channel_access_token'));
    try{$cipher->decrypt($payload,'slipok_api_key');throw new RuntimeException('AAD mismatch was accepted');}catch(RuntimeException $error){if($error->getMessage()==='AAD mismatch was accepted')throw $error;}
    $last=substr($payload,-1);$tampered=substr($payload,0,-1).($last==='A'?'B':'A');
    try{$cipher->decrypt($tampered,'line_channel_access_token');throw new RuntimeException('tampered ciphertext was accepted');}catch(RuntimeException $error){if($error->getMessage()==='tampered ciphertext was accepted')throw $error;}
});
$test('integration receiver reference requires at least six digits',function()use($same,$throws,$app):void{
    $method=new ReflectionMethod(Dormitory\Domain\SystemSettingsService::class,'receiverAccountTail');
    $same('123456',$method->invoke($app->settings(),'123-456'));
    $throws(fn()=>$method->invoke($app->settings(),'3456'),'VALIDATION_ERROR');
});
$test('APP_URL accepts only the same origin shape used by runtime',function()use($same):void{
    $same('http',Config::validatedAppUrlScheme('http://localhost:8080'));
    $same('https',Config::validatedAppUrlScheme('https://example.com/'));
    foreach([
        'https://user:pass@example.com',
        'https://example.com/app',
        'https://example.com?debug=1',
        'https://example.com/#fragment',
        'ftp://example.com',
        'example.com',
    ]as$url){
        try{Config::validatedAppUrlScheme($url);throw new RuntimeException("invalid APP_URL was accepted: {$url}");}
        catch(RuntimeException $error){if(str_starts_with($error->getMessage(),'invalid APP_URL was accepted:'))throw $error;}
    }
});
$test('forwarded HTTPS is trusted only from configured proxies',function()use($same,$app):void{
    $before=$_SERVER;putenv('TRUSTED_PROXIES=10.0.0.5');
    try{
        $_SERVER['REMOTE_ADDR']='203.0.113.10';$_SERVER['HTTP_X_FORWARDED_PROTO']='https';$_SERVER['HTTPS']='off';$_SERVER['SERVER_PORT']='80';$same(false,$app->config->requestIsHttps());
        $_SERVER['REMOTE_ADDR']='10.0.0.5';$same(true,$app->config->requestIsHttps());
    }finally{$_SERVER=$before;putenv('TRUSTED_PROXIES');}
});
$test('anonymous CSRF and actor lookup do not create file sessions',function()use($same,$throws,$app):void{
    $_COOKIE=[];
    $directory=dirname(__DIR__).'/storage/sessions';
    $before=glob($directory.'/sess_*')?:[];
    $same(null,$app->actor(true));
    $token=$app->security()->csrfToken();
    $same(1,preg_match('/^g\.[0-9]{10}\.[a-f0-9]{32}\.[a-f0-9]{64}$/D',$token));
    $same(PHP_SESSION_NONE,session_status());
    $origin=(string)$app->config->get('APP_URL');
    $valid=new Request('POST','/api/public/bookings',['origin'=>$origin,'x-csrf-token'=>$token],[],[],[],['REMOTE_ADDR'=>'127.0.0.1'],'csrf-test');
    $app->security()->assertMutation($valid);
    $invalid=new Request('POST','/api/public/bookings',['origin'=>$origin,'x-csrf-token'=>$token.'0'],[],[],[],['REMOTE_ADDR'=>'127.0.0.1'],'csrf-test-bad');
    $throws(fn()=>$app->security()->assertMutation($invalid),'CSRF_INVALID');
    $same(count($before),count(glob($directory.'/sess_*')?:[]));
});
$test('weak PIN rejection',fn()=>$throws(fn()=>Password::assertPin('123456'),'WEAK_PIN'));
$test('sequential PIN and weak admin passwords are rejected',function()use($throws):void{
    $throws(fn()=>Password::assertPin('012345'),'WEAK_PIN');
    $throws(fn()=>Password::assertAdmin(str_repeat(' ',12),'owner'),'WEAK_PASSWORD');
    $throws(fn()=>Password::assertAdmin('OwnerPassword!2026','owner'),'WEAK_PASSWORD');
    Password::assertAdmin('Safe-Console#2026','owner');
});
$test('APP_KEY strength is enforced outside production too',function()use($app):void{
    putenv('APP_KEY=short-development-key');
    try{$app->config->appKey();throw new RuntimeException('weak APP_KEY was accepted');}
    catch(RuntimeException $error){if($error->getMessage()==='weak APP_KEY was accepted')throw $error;}
    finally{putenv('APP_KEY');}
});
$test('trusted proxy chain uses the right-most untrusted client',function()use($same,$app):void{
    putenv('TRUSTED_PROXIES=10.0.0.4,10.0.0.5');
    try{
        $request=new Request('GET','/',['x-forwarded-for'=>'203.0.113.99, 198.51.100.20, 10.0.0.4'],[],[],[],['REMOTE_ADDR'=>'10.0.0.5'],'test');
        $same('198.51.100.20',$app->security()->clientIp($request));
        $invalid=new Request('GET','/',['x-forwarded-for'=>'203.0.113.99, invalid'],[],[],[],['REMOTE_ADDR'=>'10.0.0.5'],'test');
        $same('10.0.0.5',$app->security()->clientIp($invalid));
    }finally{putenv('TRUSTED_PROXIES');}
});
$test('EasySlip terminal versus retryable errors',function()use($same):void{$same(false,SlipVerifier::isTransientProviderError('easyslip','SLIP_NOT_FOUND',404));$same(true,SlipVerifier::isTransientProviderError('easyslip','SLIP_PENDING',404));$same(true,SlipVerifier::isTransientProviderError('easyslip','API_SERVER_ERROR',500));});
$test('SlipOK bank delay and receiver configuration remain retryable',function()use($same):void{$same(true,SlipVerifier::isTransientProviderError('slipok',1009,400));$same(true,SlipVerifier::isTransientProviderError('slipok',1010,400));$same(true,SlipVerifier::isTransientProviderError('slipok',1014,400));$same(false,SlipVerifier::isTransientProviderError('slipok',1007,400));$same(false,SlipVerifier::isTransientProviderError('slipok',1011,400));$same(false,SlipVerifier::isTransientProviderError('slipok',1013,400));});
$test('stored slip retry verifies MIME dimensions and HMAC',function()use($same,$throws,$app):void{
    $year=$app->config->root.'/storage/private/slips/2099';$directory=$year.'/01';$yearExisted=is_dir($year);$directoryExisted=is_dir($directory);
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('cannot create slip test directory');
    $file=$directory.'/qa-integrity.png';
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',true);
    if(!is_string($png)||file_put_contents($file,$png)===false)throw new RuntimeException('cannot write slip test image');
    try{
        $method=new ReflectionMethod(PaymentService::class,'storedSlipAbsolute');$service=new PaymentService($app);
        $hmac=hash_hmac_file('sha256',$file,$app->config->appKey());if(!is_string($hmac))throw new RuntimeException('cannot hash slip test image');
        $same(realpath($file),$method->invoke($service,'storage/private/slips/2099/01/qa-integrity.png','image/png',$hmac));
        $throws(fn()=>$method->invoke($service,'storage/private/slips/2099/01/qa-integrity.png','image/png',str_repeat('0',64)),'SLIP_FILE_INTEGRITY_FAILED');
    }finally{
        @unlink($file);if(!$directoryExisted)@rmdir($directory);if(!$yearExisted)@rmdir($year);
    }
});
$test('provider transfer-time field mapping',function()use($same):void{
    $same('2026-07-14T05:00:00.000Z',SlipVerifier::extractTransferredAt('slipok',['transTimestamp'=>'2026-07-14T05:00:00.000Z','transDate'=>'20260714','transTime'=>'12:00:00']));
    $same('2026-07-14T12:00:00+07:00',SlipVerifier::extractTransferredAt('slipok',['transDate'=>'20260714','transTime'=>'12:00:00']));
    $same('2026-07-14T12:00:00+07:00',SlipVerifier::extractTransferredAt('easyslip',['rawSlip'=>['date'=>'2026-07-14T12:00:00+07:00']]));
});
$test('transfer at bill tolerance boundary is valid',function()use($same):void{
    $result=SlipVerifier::evaluateTransactionTime('2026-07-14T04:55:00Z','2026-07-14 05:00:00.000000',300,new DateTimeImmutable('2026-07-14T05:30:00Z'));
    $same('valid',$result['decision']);$same('2026-07-14T04:55:00.000000Z',$result['transferred_at']);
});
$test('provider timezone is normalized to UTC',function()use($same):void{
    $result=SlipVerifier::evaluateTransactionTime('2026-07-14T12:00:00+07:00','2026-07-14 04:59:00.000000',300,new DateTimeImmutable('2026-07-14T05:30:00Z'));
    $same('valid',$result['decision']);$same('2026-07-14T05:00:00.000000Z',$result['transferred_at']);
});
$test('verification audit payload retains transfer time',function()use($same,$app):void{
    $method=new ReflectionMethod(SlipVerifier::class,'auditPayload');$payload=$method->invoke(new SlipVerifier($app),[
        'ok'=>true,
        'transferred_at'=>'2026-07-14T05:00:00.000000Z',
        'provider_branch'=>'branch-a',
        'receiver_match_source'=>'slipok_branch_log',
        'matched_account_ref'=>'123-4-56789-0',
    ]);
    $same('2026-07-14T05:00:00.000000Z',$payload['transferred_at']);
    $same('branch-a',$payload['provider_branch']);
    $same('slipok_branch_log',$payload['receiver_match_source']);
    $same('567890',$payload['matched_receiver_tail']);
});
$test('transfer before bill tolerance is rejected',function()use($same):void{
    $result=SlipVerifier::evaluateTransactionTime('2026-07-14T04:54:59.999999Z','2026-07-14 05:00:00.000000',300,new DateTimeImmutable('2026-07-14T05:30:00Z'));
    $same('rejected',$result['decision']);$same('Slip transfer predates the bill',$result['reason']);$same('2026-07-14T04:54:59.999999Z',$result['transferred_at']);
});
$test('transfer beyond future tolerance is rejected',function()use($same):void{
    $result=SlipVerifier::evaluateTransactionTime('2026-07-14T05:35:00.000001Z','2026-07-14 05:00:00.000000',300,new DateTimeImmutable('2026-07-14T05:30:00Z'));
    $same('rejected',$result['decision']);$same('Slip transfer time is in the future',$result['reason']);
});
$test('missing or malformed provider time remains retryable',function()use($same):void{
    foreach([null,'2026-02-30T05:00:00Z','2026-07-14 05:00:00','2026-07-14T05:00:00+24:00']as$value){$result=SlipVerifier::evaluateTransactionTime($value,'2026-07-14 05:00:00',300,new DateTimeImmutable('2026-07-14T05:30:00Z'));$same('pending',$result['decision']);$same(null,$result['transferred_at']);}
});
$test('invalid boolean configuration fails closed',function()use($app):void{putenv('TEST_INVALID_BOOLEAN=treu');try{$app->config->bool('TEST_INVALID_BOOLEAN');throw new RuntimeException('invalid boolean was accepted');}catch(RuntimeException $error){if($error->getMessage()==='invalid boolean was accepted')throw $error;}finally{putenv('TEST_INVALID_BOOLEAN');}});
$test('billing amounts respect DECIMAL(14,2) bounds',function()use($same,$throws):void{$multiply=new ReflectionMethod(BillingService::class,'multiplyMoney');$sum=new ReflectionMethod(BillingService::class,'sumMoney');$max=99_999_999_999_999;$same($max,$multiply->invoke(null,$max,100));$same($max,$sum->invoke(null,$max-1,1));$throws(fn()=>$multiply->invoke(null,$max,101),'AMOUNT_OVERFLOW');$throws(fn()=>$sum->invoke(null,$max,1),'AMOUNT_OVERFLOW');});
$test('billing preview token binds exact values and expiry',function()use($app,$throws):void{$service=new BillingService($app);$sign=new ReflectionMethod(BillingService::class,'previewToken');$verify=new ReflectionMethod(BillingService::class,'assertPreviewToken');$preview=['period'=>'2026-07','due_date'=>'2026-07-10','bills'=>[['room_id'=>1,'total_amount'=>'1234.56']],'issues'=>[]];$token=$sign->invoke($service,$preview,time()+60);$verify->invoke($service,$token,$preview);$changed=$preview;$changed['bills'][0]['total_amount']='1234.57';$throws(fn()=>$verify->invoke($service,$token,$changed),'BILL_PREVIEW_CHANGED');$expired=$sign->invoke($service,$preview,time()-1);$throws(fn()=>$verify->invoke($service,$expired,$preview),'BILL_PREVIEW_EXPIRED');});
$test('overlapping occupancies are excluded from billing',function()use($same):void{$partition=new ReflectionMethod(BillingService::class,'partitionOccupancies');$result=$partition->invoke(null,[['occupancy_id'=>1,'room_id'=>10,'room_code'=>'101'],['occupancy_id'=>2,'room_id'=>10,'room_code'=>'101'],['occupancy_id'=>3,'room_id'=>20,'room_code'=>'201']]);$same([3],array_column($result['occupancies'],'occupancy_id'));$same(1,count($result['issues']));$same('AMBIGUOUS_OCCUPANCY',$result['issues'][0]['code']);$same([1,2],$result['issues'][0]['occupancy_ids']);});
$test('audit UTF-8 truncation preserves valid characters',function()use($same):void{$cut=new ReflectionMethod(AuditLogger::class,'utf8Cut');$value=str_repeat('ก',200)."\xFF";$result=$cut->invoke(null,$value,500);$same(1,preg_match('//u',$result));$same(true,strlen($result)<=500);});

fwrite(STDOUT,"\n{$passed} passed, {$failed} failed".PHP_EOL);exit($failed===0?0:1);
