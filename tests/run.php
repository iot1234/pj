<?php
declare(strict_types=1);

use Dormitory\Domain\PromptPayService;
use Dormitory\Domain\BillingService;
use Dormitory\Domain\AdminUserService;
use Dormitory\Domain\LineDeliveryException;
use Dormitory\Domain\LineWebhookService;
use Dormitory\Domain\NotificationService;
use Dormitory\Domain\PaymentService;
use Dormitory\Domain\SystemSettingsService;
use Dormitory\AuditLogger;
use Dormitory\Config;
use Dormitory\Database;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Integration\SlipVerifier;
use Dormitory\Security\Password;
use Dormitory\Security\SecretCipher;
use Dormitory\Support\SchemaGuard;
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

$test('session release persists state and resident idle/absolute limits expire behaviorally',function()use($same,$app):void{
    $manager=$app->session();$cookieName=session_name();$sessionFiles=[];
    $rememberSession=static function()use(&$sessionFiles):string{
        $sessionId=session_id();
        if(!preg_match('/^[A-Za-z0-9,-]{22,128}$/D',$sessionId))throw new RuntimeException('unexpected test session id');
        $sessionFiles[$sessionId]=rtrim((string)ini_get('session.save_path'),'/\\').DIRECTORY_SEPARATOR.'sess_'.$sessionId;
        return $sessionId;
    };
    try{
        $same(PHP_SESSION_NONE,session_status());
        $challenge=['resident_id'=>7,'nonce'=>'release-lock-test','attempts'=>0];
        $manager->storeLineLinkChallenge($challenge);
        $same(PHP_SESSION_ACTIVE,session_status());
        $sessionId=$rememberSession();$sessionFile=$sessionFiles[$sessionId];

        $manager->release();
        $same(PHP_SESSION_NONE,session_status());
        clearstatcache(true,$sessionFile);$same(true,is_file($sessionFile));

        // A later mutation in the same request can reopen the persisted
        // session after the slow network section has released its lock.
        $_COOKIE[$cookieName]=$sessionId;
        $same($challenge,$manager->lineLinkChallenge());
        $manager->clearLineLinkChallenge();
        $same(null,$manager->lineLinkChallenge());

        $resident=['type'=>'resident','id'=>7,'auth_version'=>1];
        $manager->login($resident);
        $absoluteSessionId=$rememberSession();
        $_SESSION['dormitory_created_at']=time()-3601;
        $_SESSION['dormitory_last_seen_at']=time();
        $manager->release();$_COOKIE[$cookieName]=$absoluteSessionId;
        $same(null,$manager->actor());
        $replacementId=$rememberSession();
        $same(false,hash_equals($absoluteSessionId,$replacementId));
        $manager->logout();

        unset($_COOKIE[$cookieName]);
        $manager->login($resident);
        $idleSessionId=$rememberSession();
        $_SESSION['dormitory_created_at']=time();
        $_SESSION['dormitory_last_seen_at']=time()-901;
        $manager->release();$_COOKIE[$cookieName]=$idleSessionId;
        $same(null,$manager->actor());
        $replacementId=$rememberSession();
        $same(false,hash_equals($idleSessionId,$replacementId));
        $manager->logout();

        $same(PHP_SESSION_NONE,session_status());
        foreach($sessionFiles as$path){clearstatcache(true,$path);$same(false,is_file($path));}
    }finally{
        if(session_status()===PHP_SESSION_ACTIVE){
            $rememberSession();
            try{$manager->logout();}catch(Throwable){if(session_status()===PHP_SESSION_ACTIVE){$_SESSION=[];@session_destroy();}}
        }
        unset($_COOKIE[$cookieName]);
        foreach($sessionFiles as$path)if(is_file($path))@unlink($path);
    }
});

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
    $same(['GET','PUT','POST'],$methods);
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
    $same(0,preg_match('/name="confirm_pin"[^>]*required/',$admin));
    $same(1,preg_match('/จำนวนเงินอื่น \/ ห้อง/',$admin));
    $same(1,preg_match('/water_units.*water_rate.*water_amount/s',$js));
});
$test('resident PIN surfaces and runtime compatibility paths are retired',function()use($same,$app):void{
    $root=dirname(__DIR__);
    $auth=file_get_contents($root.'/src/Domain/AuthService.php');
    $resident=file_get_contents($root.'/src/Domain/ResidentService.php');
    $booking=file_get_contents($root.'/src/Domain/BookingService.php');
    $routes=file_get_contents($root.'/src/Http/Routes.php');
    $login=file_get_contents($root.'/templates/resident/login.php');
    $portal=file_get_contents($root.'/templates/resident/portal.php');
    $admin=file_get_contents($root.'/templates/admin/console.php');
    $js=file_get_contents($root.'/public/assets/js/app.js');
    $schema=file_get_contents($root.'/database/schema.sql');
    $installer=file_get_contents($root.'/database/install.sql');
    $migration=file_get_contents($root.'/database/migrations/006_remove_resident_pin.sql');
    foreach(compact('auth','resident','booking','routes','login','portal','admin','js','schema','installer','migration')as$name=>$source){
        if(!is_string($source))throw new RuntimeException("cannot read {$name} PIN-removal source");
    }
    $same(false,str_contains($auth,'pin_hash'));
    $same(false,str_contains($resident,'current_pin'));
    $same(false,str_contains($resident,'changePin'));
    $same(false,str_contains($resident,'resetPin'));
    $same(false,str_contains($routes,'/api/resident/profile/pin'));
    $same(false,str_contains($routes,'reset-pin'));
    $same(false,str_contains($schema,'pin_hash'));
    $same(false,str_contains($installer,'pin_hash'));
    $same(true,str_contains($migration,'ALTER TABLE residents DROP COLUMN pin_hash'));
    $same(true,str_contains($migration,'DORMITORY_MIGRATION_006_ABORT_RESIDENTS_TABLE_MISSING'));
    $same(true,str_contains($migration,'DORMITORY_MIGRATION_006_ABORT_PIN_COLUMN_REMAINS'));
    $same(false,str_contains($booking,'pin_hash'));
    $same(false,str_contains($booking,'hasLegacyResidentCredentialColumn'));
    $same(false,str_contains($booking,'disabledLegacyCredential'));
    $requirements=file_get_contents($root.'/scripts/check_requirements.php');if(!is_string($requirements))throw new RuntimeException('cannot read requirements checker');
    $same(true,str_contains($requirements,"column_name='pin_hash'"));
    $same(true,str_contains($requirements,'schema ยังมี residents.pin_hash'));
    $same(true,str_contains($requirements,'ไม่รองรับกับ source ปัจจุบัน'));
    foreach([$login,$portal,$admin,$js]as$surface)$same(0,preg_match('/\bPIN\b/i',$surface));
    $routesProperty=new ReflectionProperty(Dormitory\Http\Router::class,'routes');
    $registered=$routesProperty->getValue(Dormitory\Http\Routes::build($app));
    foreach($registered as$route){
        if(preg_match('/(?:profile\/pin|reset.pin)/i',$route['regex']))throw new RuntimeException('retired resident PIN route remains registered');
    }
    $session=file_get_contents($root.'/src/Security/SessionManager.php');if(!is_string($session))throw new RuntimeException('cannot read SessionManager');
    $same(true,str_contains($session,'private const RESIDENT_ABSOLUTE_LIFETIME = 3600'));
    $same(true,str_contains($session,'private const RESIDENT_IDLE_LIFETIME = 900'));
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
$test('resident PIN validator is removed and weak admin passwords are rejected',function()use($same,$throws):void{
    $same(false,method_exists(Password::class,'assertPin'));
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
$test('slip locale must be Thai baht',function()use($same):void{
    $locale=new ReflectionMethod(SlipVerifier::class,'evaluatePaymentLocale');
    $same(['decision'=>'valid','reason'=>null],$locale->invoke(null,'easyslip','TH','THB'));
    $same(['decision'=>'valid','reason'=>null],$locale->invoke(null,'slipok','TH','764'));
    $same(['decision'=>'valid','reason'=>null],$locale->invoke(null,'slipok','TH',null));
    $same('pending',$locale->invoke(null,'easyslip','TH',null)['decision']);
    $same('pending',$locale->invoke(null,'slipok',null,'764')['decision']);
    $same('rejected',$locale->invoke(null,'easyslip','US','THB')['decision']);
    $same('rejected',$locale->invoke(null,'slipok','TH','USD')['decision']);
    $source=file_get_contents(dirname(__DIR__).'/src/Integration/SlipVerifier.php');if(!is_string($source))throw new RuntimeException('cannot read SlipVerifier');
    $same(true,str_contains($source,"\$raw['countryCode']"));
    $same(true,str_contains($source,"\$d['paidLocalCurrency']"));
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

$test('runtime integer ranges and production database password fail closed',function()use($same,$app):void{
    putenv('TEST_BOUNDED_INTEGER=900');
    try{$same(900,$app->config->intInRange('TEST_BOUNDED_INTEGER',600,300,1200));}
    finally{putenv('TEST_BOUNDED_INTEGER');}
    foreach(['0','65536']as$port){
        putenv('DB_PORT='.$port);
        try{(new Database($app->config))->pdo();throw new RuntimeException('invalid DB_PORT was accepted');}
        catch(RuntimeException $error){if($error->getMessage()==='invalid DB_PORT was accepted')throw $error;$same(true,str_contains($error->getMessage(),'DB_PORT')&&str_contains($error->getMessage(),'between'));}
        finally{putenv('DB_PORT=3306');}
    }
    putenv('DB_HOST=localhost;dbname=other');
    try{(new Database($app->config))->pdo();throw new RuntimeException('invalid DB_HOST was accepted');}
    catch(RuntimeException $error){if($error->getMessage()==='invalid DB_HOST was accepted')throw $error;$same(true,str_contains($error->getMessage(),'DB_HOST'));}
    finally{putenv('DB_HOST=127.0.0.1');}
    $same('mysql.internal:3306',Config::validatedDbHost(' mysql.internal:3306 '));
    putenv('APP_ENV=production');putenv('DB_HOST=');
    try{(new Database($app->config))->pdo();throw new RuntimeException('empty production DB_HOST was accepted');}
    catch(RuntimeException $error){if($error->getMessage()==='empty production DB_HOST was accepted')throw $error;$same(true,str_contains($error->getMessage(),'DB_HOST'));}
    finally{putenv('DB_HOST=127.0.0.1');}
    putenv('DB_PASSWORD=');
    try{(new Database($app->config))->pdo();throw new RuntimeException('empty production DB_PASSWORD was accepted');}
    catch(RuntimeException $error){if($error->getMessage()==='empty production DB_PASSWORD was accepted')throw $error;$same(true,str_contains($error->getMessage(),'DB_PASSWORD'));}
    finally{putenv('APP_ENV=testing');putenv('DB_PASSWORD=testing-only');}
});
$test('production APP_KEY requires random 32-byte key material',function()use($same):void{
    $sequential='';for($i=0;$i<32;$i++)$sequential.=sprintf('%02x',$i);
    foreach([str_repeat('a',64),str_repeat('ab',32),str_repeat('x',64),$sequential,'000102030405060708090a0b0c0d0e0f000102030405060708090a0b0c0d0e0f','qwertyuiopasdfghjklzxcvbnm123456','1234567890abcdefghijklmnopqrstuvwxyz']as$key){
        try{Config::validatedAppKey($key,true);throw new RuntimeException('weak production APP_KEY was accepted');}
        catch(RuntimeException $error){if($error->getMessage()==='weak production APP_KEY was accepted')throw $error;}
    }
    $strong='5a1697717edc7dd7783c09b4c594fefa546e5aab212ae6136749002617d085f6';
    $same($strong,Config::validatedAppKey($strong,true));
    $base64='oBIPWekW02veQzND7dwDkgk1YzqJpKSRro6dU2QEThc=';
    $same($base64,Config::validatedAppKey($base64,true));
    $base64Url=rtrim(strtr($base64,'+/','-_'),'=');
    $same($base64Url,Config::validatedAppKey($base64Url,true));
    $raw='Q7!vL2@xP9#cN4$mR8%tK5^zW3&hD6*jS';
    $same($raw,Config::validatedAppKey($raw,true));
    $rawWithSpace='Q7!vL2@xP9#cN4$m R8%tK5^zW3&hD6*jS';
    $same($rawWithSpace,Config::validatedAppKey($rawWithSpace,true));
    $block='';for($i=0;$i<33;$i++)$block.=chr(33+$i);
    try{Config::validatedAppKey(base64_encode($block.$block),true);throw new RuntimeException('long repeated APP_KEY pattern was accepted');}
    catch(RuntimeException $error){if($error->getMessage()==='long repeated APP_KEY pattern was accepted')throw $error;}
    try{Config::validatedAppKey(str_repeat('Ab3!',300),true);throw new RuntimeException('oversized APP_KEY was accepted');}
    catch(RuntimeException $error){if($error->getMessage()==='oversized APP_KEY was accepted')throw $error;}
});
$test('admin rehash and phone-only resident login use layered throttling',function()use($same,$app):void{
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/AuthService.php');if(!is_string($source))throw new RuntimeException('cannot read AuthService');
    $same(1,preg_match('/UPDATE admin_users SET password_hash=\?,updated_at=UTC_TIMESTAMP\(\) WHERE id=\? AND password_hash=\? AND auth_version=\?/',$source));
    $same(true,str_contains($source,'(!$accountAllowed&&!$trustedDevice)'));
    foreach(['admin-login-account-source','admin-login-account','resident-login-account-source','resident-login-account']as$scope)$same(true,str_contains($source,$scope));
    $same(true,str_contains($source,'DUMMY_ARGON2ID_HASH'));
    $same(true,str_contains($source,'$2y$10$'));
    $same(false,str_contains($source,'$sourceAllowed&&$this->accountAttemptAllowed'));
    $same(true,str_contains($source,'globalAccountAttemptAllowed'));
    $same(true,str_contains($source,"\$scope.'-timing-pad'"));
    $same(true,str_contains($source,'private static function verifyCredential'));
    $same(false,str_contains($source,"Password::hash('dummy-password"));
    $residentStart=strpos($source,'public function residentLogin');$residentEnd=strpos($source,'public function logout',$residentStart===false?0:$residentStart);
    if($residentStart===false||$residentEnd===false)throw new RuntimeException('cannot isolate resident login');
    $residentBlock=substr($source,$residentStart,$residentEnd-$residentStart);
    $same(true,str_contains($residentBlock,"Validator::only(\$input, ['phone'])"));
    $same(true,str_contains($residentBlock,"'resident-login-ip-daily'"));
    $same(true,str_contains($residentBlock,"'auth_method' => 'phone_only'"));
    $same(true,str_contains($residentBlock,"'assurance' => 'low'"));
    $same(true,str_contains($residentBlock,'writeStrict'));
    $same(true,str_contains($residentBlock,"JOIN occupancies o ON o.resident_id=r.id AND o.status='active'"));
    $same(true,str_contains($residentBlock,'JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL'));
    $same(false,str_contains($residentBlock,'pin_hash'));
    $same(false,str_contains($residentBlock,'trustedDevice'));
    $same(false,str_contains($residentBlock,"limiter()->clear('resident-login"));
    $same(false,str_contains($residentBlock,"rememberLoginDevice('resident'"));
    $auth=new Dormitory\Domain\AuthService($app);$create=new ReflectionMethod($auth,'createLoginDeviceToken');$valid=new ReflectionMethod($auth,'validLoginDeviceToken');$now=time();
    $verifyCredential=new ReflectionMethod($auth,'verifyCredential');$credential='Timing-safe-test-password-48!';$credentialHash=Password::hash($credential);
    $same(true,$verifyCredential->invoke($auth,$credential,$credentialHash));
    $same(false,$verifyCredential->invoke($auth,$credential,null));
    $same(true,$verifyCredential->invoke($auth,'dummy-password-that-is-never-valid','$2y$10$u0R/rN94jiaDgP4CtmlUUu9M5buyoEZvxmz4wiLkxAAQqTs4/Nuki'));
    $token=$create->invoke($auth,'admin',7,3,$now+60);
    $same(true,$valid->invoke($auth,$token,'admin',7,3,$now));
    $same(false,$valid->invoke($auth,$token,'admin',7,4,$now));
    $tampered=substr($token,0,-1).(str_ends_with($token,'0')?'1':'0');
    $same(false,$valid->invoke($auth,$tampered,'admin',7,3,$now));
    $cookieName=new ReflectionMethod($auth,'loginDeviceCookieName');
    $same(false,$cookieName->invoke($auth,'admin',7)===$cookieName->invoke($auth,'resident',7));
    $same(false,$cookieName->invoke($auth,'admin',7)===$cookieName->invoke($auth,'admin',8));
    $limiterSource=file_get_contents(dirname(__DIR__).'/src/Security/RateLimiter.php');if(!is_string($limiterSource))throw new RuntimeException('cannot read RateLimiter');
    $same(true,str_contains($limiterSource,'UPDATE rate_limits SET updated_at=UTC_TIMESTAMP() WHERE bucket_key=?'));
    $same(true,str_contains($limiterSource,'public function lockBucket(string $scope,string $identity): void'));
    $same(true,str_contains($limiterSource,'public function refundHit(string $scope,string $identity): void'));
    $same(true,str_contains($limiterSource,"CASE WHEN hits<=1 THEN '1970-01-01 00:00:00.000000'"));
    $same(true,str_contains($limiterSource,'blocked_until IS NULL AND hits>0'));
    $same(true,str_contains($limiterSource,"if(!\$pdo->inTransaction())throw new \\LogicException"));
});
$test('booking holds use the database clock and inactive replays fail closed',function()use($same):void{
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/BookingService.php');if(!is_string($source))throw new RuntimeException('cannot read BookingService');
    $same(true,substr_count($source,'SELECT * FROM bookings WHERE idempotency_key=?')>=1);
    $same(true,str_contains($source,'SELECT id FROM bookings WHERE idempotency_key=? LIMIT 1 FOR UPDATE'));
    $same(false,str_contains($source,'$lockedExisting'));
    $same(true,str_contains($source,"SELECT * FROM bookings WHERE room_id=? AND status IN ('pending','confirmed') LIMIT 1"));
    $same(false,str_contains($source,"SELECT * FROM bookings WHERE room_id=? AND status IN ('pending','confirmed') LIMIT 1 FOR UPDATE"));
    $same(true,str_contains($source,"hash_equals((string)\$reservedRow['idempotency_key'],\$idempotency)"));
    $createStart=strpos($source,'public function createPublicOutcome');
    $transactionStart=strpos($source,'return $this->app->database()->transaction',$createStart===false?0:$createStart);
    if($createStart===false||$transactionStart===false)throw new RuntimeException('cannot isolate public booking pre-transaction path');
    $same(false,str_contains(substr($source,$createStart,$transactionStart-$createStart),'$this->expirePending();'));
    $same(true,str_contains($source,'$this->expirePending($roomId);'));
    $same(true,str_contains($source,'public function expirePublicPhoneHolds(mixed $rawPhone): void'));
    $same(true,str_contains($source,"WHERE phone_norm=? AND status='pending'"));
    $same(true,str_contains($source,"WHERE room_id=? AND status='pending'"));
    $same(true,str_contains($source,"WHERE id=? AND status='pending'"));
    $same(false,str_contains($source,'{$roomSql}'));
    $same(true,str_contains($source,'created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL'));
    $same(true,str_contains($source,"'BOOKING_INACTIVE'"));
    $same(true,str_contains($source,"'BOOKING_PHONE_ACTIVE'"));
    $same(true,str_contains($source,"['idempotent_replay']=true"));
    $schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');if(!is_string($schema))throw new RuntimeException('cannot read schema');
    $same(true,str_contains($schema,'active_phone_norm CHAR(10)'));
    $same(true,str_contains($schema,'UNIQUE KEY uq_bookings_one_active_per_phone (active_phone_norm)'));
    $migration=file_get_contents(dirname(__DIR__).'/database/migrations/005_booking_active_phone.sql');if(!is_string($migration))throw new RuntimeException('cannot read migration 005');
    $same(true,str_contains($migration,'DORMITORY_MIGRATION_005_ABORT_DUPLICATE_ACTIVE_PHONE'));
    $same(true,str_contains($migration,'DORMITORY_MIGRATION_005_ABORT_INVALID_ACTIVE_PHONE_COLUMN'));
    $same(true,str_contains($migration,'DORMITORY_MIGRATION_005_ABORT_INVALID_ACTIVE_PHONE_INDEX'));
    $same(true,str_contains($migration,'@dormitory_expected_active_phone_expression_a'));
    $same(true,str_contains($migration,'normalized_expression IN'));
    $same(false,str_contains($migration,"generation_expression LIKE '%phone_norm%'"));
    $same(true,substr_count($migration,'AND sub_part IS NULL')>=2);
    $same(true,str_contains($migration,'@dormitory_final_active_phone_index_row_count = 1'));
    $bootstrap=file_get_contents(dirname(__DIR__).'/scripts/bootstrap_database.sh');if(!is_string($bootstrap))throw new RuntimeException('cannot read database bootstrap');
    $same(true,str_contains($bootstrap,"active_phone_norm|0|1|FULL"));
    $same(true,str_contains($bootstrap,'invalid bookings.active_phone_norm generated expression'));
    $requirements=file_get_contents(dirname(__DIR__).'/scripts/check_requirements.php');if(!is_string($requirements))throw new RuntimeException('cannot read requirements check');
    $same(true,str_contains($requirements,"index_name='uq_bookings_one_active_per_phone'"));
    $same(true,str_contains($requirements,'count($bookingPhoneIndexRows)===1'));
    $canonical="(case when (`status` in (_utf8mb4\\'pending\\',_utf8mb4\\'confirmed\\')) then `phone_norm` else NULL end)";
    $reversed="case when status in ('confirmed', 'pending') then phone_norm else null end";
    $same("casewhenstatusin'pending','confirmed'thenphone_normelsenullend",SchemaGuard::activePhoneGenerationExpression($canonical));
    $same("casewhenstatusin'confirmed','pending'thenphone_normelsenullend",SchemaGuard::activePhoneGenerationExpression($reversed));
    foreach([
        "case when status in ('pending','confirmed') then phone_norm else phone_norm end",
        "case when status in ('pen(ding)','confirmed') then phone_norm else null end",
        "case when status in ('pen ding','confirmed') then phone_norm else null end",
        "case when status='pending' then phone_norm when status='confirmed' then null else null end",
        "if(status in ('pending','confirmed'),phone_norm,null)",
    ] as $invalidExpression)$same(null,SchemaGuard::activePhoneGenerationExpression($invalidExpression));
    $routes=file_get_contents(dirname(__DIR__).'/src/Http/Routes.php');if(!is_string($routes))throw new RuntimeException('cannot read Routes');
    $same(true,str_contains($routes,"'public-booking-attempt-ip'"));
    $preflightExpiry=strpos($routes,'expirePublicPhoneHolds');
    $routeBookingTransaction=strpos($routes,'$outcome=$app->database()->transaction',$preflightExpiry===false?0:$preflightExpiry);
    $same(true,$preflightExpiry!==false&&$routeBookingTransaction!==false&&$preflightExpiry<$routeBookingTransaction);
    $same(true,str_contains($routes,"\$replay?200:201"));
    $same(true,str_contains($routes,"&&!\$replay"));
    $same(true,str_contains($source,"hit('public-booking-ip',\$clientIp,5,86400)"));
    $same(false,str_contains($source,"hit('public-booking-ip',\$clientIp,5,86400,3600)"));
    $same(true,str_contains($source,"\$error->status!==429||\$error->errorCode!=='RATE_LIMITED'"));
    $same(true,str_contains($source,"return \$this->errorOutcome(\$error->status,\$error->getMessage(),\$error->errorCode,\$error->details)"));
    $same(true,str_contains($source,"refundHit('public-booking-ip',\$clientIp)"));
    $createEnd=strpos($source,'public function all',$createStart);
    if($createEnd===false)throw new RuntimeException('cannot isolate public booking method');
    $createBlock=substr($source,$createStart,$createEnd-$createStart);
    $same(false,str_contains($createBlock,"lockBucket('public-booking-phone',\$phone)"));
    $roomLock=strpos($createBlock,"SELECT id,monthly_rent,deleted_at FROM rooms WHERE id=? FOR UPDATE");
    $firstIdempotencyRead=strpos($createBlock,'SELECT * FROM bookings WHERE idempotency_key=? LIMIT 1');
    $deletedGuard=strpos($createBlock,"\$roomRow['deleted_at']!==null");
    $scopedExpiry=strpos($createBlock,'$this->expirePending($roomId);');
    $activePhoneRead=strpos($createBlock,'SELECT id FROM bookings WHERE active_phone_norm=? LIMIT 1');
    $ipHit=strpos($createBlock,"hit('public-booking-ip',\$clientIp,5,86400)");
    $phoneHit=strpos($createBlock,"hit('public-booking-phone',\$phone,2,86400)");
    $same(true,$roomLock!==false&&$firstIdempotencyRead!==false&&$deletedGuard!==false
        &&$scopedExpiry!==false&&$activePhoneRead!==false&&$ipHit!==false&&$phoneHit!==false
        &&$roomLock<$firstIdempotencyRead&&$firstIdempotencyRead<$deletedGuard
        &&$deletedGuard<$scopedExpiry&&$scopedExpiry<$activePhoneRead
        &&$activePhoneRead<$ipHit&&$ipHit<$phoneHit);
    $same(true,str_contains($createBlock,"'uq_bookings_idempotency_key'"));
    $same(true,str_contains($createBlock,"'uq_bookings_one_active_per_phone'"));
    $same(true,str_contains($createBlock,"'uq_bookings_one_active_per_room'"));
    $same(false,str_contains($createBlock,"WHERE phone_norm=? AND status IN ('pending','confirmed') LIMIT 1 FOR UPDATE"));
    $same(false,str_contains(substr($createBlock,0,$ipHit),'SELECT id FROM bookings WHERE active_phone_norm=? LIMIT 1 FOR UPDATE'));
    $insertCatchStart=strpos($source,'} catch (\\PDOException $error)');
    $createdRead=strpos($source,"\$created=\$pdo->prepare('SELECT * FROM bookings WHERE id=?')",$insertCatchStart===false?0:$insertCatchStart);
    if($insertCatchStart===false||$createdRead===false)throw new RuntimeException('cannot isolate booking duplicate-key handler');
    $insertCatch=substr($source,$insertCatchStart,$createdRead-$insertCatchStart);
    $same(true,str_contains($insertCatch,"'BOOKING_RETRY'"));
    $same(false,str_contains($insertCatch,'return $this->replay'));
    $same(true,str_contains($source,'private function replay(PDO $pdo'));
    $moveInStart=strpos($source,'public function moveIn');
    $transitionStart=strpos($source,'private function transition');
    if($moveInStart===false||$transitionStart===false)throw new RuntimeException('cannot isolate booking mutators');
    $moveInBlock=substr($source,$moveInStart,$transitionStart-$moveInStart);
    $moveInLookup=strpos($moveInBlock,'SELECT room_id FROM bookings WHERE id=?');
    $moveInRoomLock=strpos($moveInBlock,'SELECT id,deleted_at FROM rooms WHERE id=? FOR UPDATE');
    $moveInBookingLock=strpos($moveInBlock,'SELECT * FROM bookings WHERE id=? FOR UPDATE');
    $same(true,$moveInLookup!==false&&$moveInRoomLock!==false&&$moveInBookingLock!==false
        &&$moveInLookup<$moveInRoomLock&&$moveInRoomLock<$moveInBookingLock);
    $transitionEnd=strpos($source,'private function map',$transitionStart);
    if($transitionEnd===false)throw new RuntimeException('cannot isolate booking transition');
    $transitionBlock=substr($source,$transitionStart,$transitionEnd-$transitionStart);
    $transitionLookup=strpos($transitionBlock,'SELECT room_id FROM bookings WHERE id=?');
    $transitionRoomLock=strpos($transitionBlock,'SELECT id FROM rooms WHERE id=? FOR UPDATE');
    $transitionBookingLock=strpos($transitionBlock,'SELECT * FROM bookings WHERE id=? FOR UPDATE');
    $same(true,$transitionLookup!==false&&$transitionRoomLock!==false&&$transitionBookingLock!==false
        &&$transitionLookup<$transitionRoomLock&&$transitionRoomLock<$transitionBookingLock);
    $same(false,str_contains($source,'SELECT b.*,r.monthly_rent,r.deleted_at'));
    $rooms=file_get_contents(dirname(__DIR__).'/src/Domain/RoomService.php');if(!is_string($rooms))throw new RuntimeException('cannot read RoomService');
    $same(false,str_contains($rooms,'created_at>=DATE_SUB'));
    $same(true,str_contains($rooms,'created_at>DATE_SUB'));
});
$test('booking quota CI checks isolate the denied request',function()use($same):void{
    $script=file_get_contents(dirname(__DIR__).'/scripts/ci-booking-edge-tests.sh');
    if(!is_string($script))throw new RuntimeException('cannot read booking edge-test script');
    $same(true,str_contains($script,"WHERE idempotency_key='ci-phone-rate-000003'"));
    $same(false,str_contains($script,"WHERE r.room_code='CI-RATE-8'"));
    $same(true,str_contains($script,'Unexpected phone quota state:'));
});
$test('resident lifecycle blocks unbilled months and same-period re-entry',function()use($same):void{
    $resident=file_get_contents(dirname(__DIR__).'/src/Domain/ResidentService.php');
    $booking=file_get_contents(dirname(__DIR__).'/src/Domain/BookingService.php');
    if(!is_string($resident)||!is_string($booking))throw new RuntimeException('cannot read resident lifecycle sources');
    $same(true,str_contains($resident,"'MOVE_OUT_MISSING_BILLS'"));
    $same(true,str_contains($resident,'self::billingPeriods($firstPeriod,$period)'));
    $same(true,str_contains($resident,'active=0,line_user_id=NULL,auth_version=auth_version+1'));
    $same(true,str_contains($resident,"'_line_unlinked_audit'"));
    $routes=file_get_contents(dirname(__DIR__).'/src/Http/Routes.php');if(!is_string($routes))throw new RuntimeException('cannot read Routes');
    $same(true,str_contains($routes,"'resident.line_unlinked','resident',\$target,\$lineAudit"));
    $moveOutStart=strpos($routes,"'/api/admin/residents/{id}/move-out'");
    $moveOutEnd=strpos($routes,"'/api/admin/bookings'",$moveOutStart===false?0:$moveOutStart);
    if($moveOutStart===false||$moveOutEnd===false)throw new RuntimeException('cannot isolate move-out route');
    $moveOutRoute=substr($routes,$moveOutStart,$moveOutEnd-$moveOutStart);
    $lockAt=strpos($moveOutRoute,'$app->notifications()->withLineBindingLock($target');
    $transactionAt=strpos($moveOutRoute,'$app->database()->transaction');
    $moveOutAt=strpos($moveOutRoute,'$app->residents()->moveOut');
    $lineAuditAt=strpos($moveOutRoute,"'resident.line_unlinked'");
    $moveOutAuditAt=strpos($moveOutRoute,"'resident.move_out'");
    $same(true,$lockAt!==false&&$transactionAt!==false&&$moveOutAt!==false&&$lineAuditAt!==false&&$moveOutAuditAt!==false
        &&$lockAt<$transactionAt&&$transactionAt<$moveOutAt&&$moveOutAt<$lineAuditAt&&$lineAuditAt<$moveOutAuditAt);
    $periods=new ReflectionMethod(Dormitory\Domain\ResidentService::class,'billingPeriods');
    $same(['2025-12-01','2026-01-01','2026-02-01'],$periods->invoke(null,'2025-12-01','2026-02-01'));
    $same(true,str_contains($booking,"'RESIDENT_MOVE_IN_PERIOD_CONFLICT'"));
    $same(true,str_contains($booking,"resident_id=? AND status='ended' AND move_out_date>=?"));
    $same(false,str_contains($booking,"resident_id=? AND status='ended' AND move_out_date>=? AND move_out_date<?"));
    $same(true,str_contains($booking,"room_id=? AND status='ended' AND move_out_date>=? ORDER BY"));
    $same(true,str_contains($booking,"substr((string)\$priorResidentOccupancy['move_out_date'],0,7).'-01'"));
});
$test('room optional fields and overdue display status are deterministic',function()use($same):void{
    $rooms=file_get_contents(dirname(__DIR__).'/src/Domain/RoomService.php');if(!is_string($rooms))throw new RuntimeException('cannot read RoomService');
    $same(true,str_contains($rooms,"\$data['description'] ??= null"));$same(true,str_contains($rooms,"\$data['image_key'] ??= null"));
    $display=new ReflectionMethod(BillingService::class,'displayStatus');$now=new DateTimeImmutable('2026-07-18T12:00:00Z');
    $same('overdue',$display->invoke(null,'pending','2026-07-17',$now));$same('pending',$display->invoke(null,'pending','2026-07-18',$now));$same('paid',$display->invoke(null,'paid','2026-07-01',$now));
});
$test('LINE bill delivery requires an authenticated one-time-code link flow',function()use($same,$app):void{
    $routes=new ReflectionProperty(Dormitory\Http\Router::class,'routes');$registered=$routes->getValue(Dormitory\Http\Routes::build($app));$paths=[];
    foreach($registered as$route){if(str_contains($route['regex'],'profile/line'))$paths[]=$route['method'].':'.$route['regex'];}
    $same(3,count($paths));
    $resident=file_get_contents(dirname(__DIR__).'/src/Domain/ResidentService.php');$booking=file_get_contents(dirname(__DIR__).'/src/Domain/BookingService.php');
    if(!is_string($resident)||!is_string($booking))throw new RuntimeException('cannot read LINE binding sources');
    $same(1,preg_match("/updateProfile.*?Validator::only\\(\\\$input,\\['full_name','email'\\]\\)/s",$resident));
    $same(1,preg_match("/moveIn.*?Validator::only\\(\\\$input, \\['email','move_in_date','reuse_resident_id'\\]\\)/s",$booking));
    $same(true,str_contains($resident,'lineLinkDigest'));
    $same(true,str_contains($resident,"Validator::only(\$input,['line_user_id'])"));
    $same(true,str_contains($resident,"Validator::only(\$input,[])"));
    $same(false,str_contains($resident,'current_pin'));
    $notification=file_get_contents(dirname(__DIR__).'/src/Domain/NotificationService.php');if(!is_string($notification))throw new RuntimeException('cannot read NotificationService');
    $same(true,str_contains($notification,'isLineBindingVerified'));
    $same(true,str_contains($notification,"resident.line_link_verified','resident.line_unlinked"));
    $routesSource=file_get_contents(dirname(__DIR__).'/src/Http/Routes.php');if(!is_string($routesSource))throw new RuntimeException('cannot read Routes');
    $same(true,str_contains($routesSource,'line_user_id_hash'));
});
$test('LINE outbox retries preserve identity, payload bytes, and retry UUID',function()use($same,$app):void{
    $service=$app->notifications();
    $validId=new ReflectionMethod(NotificationService::class,'validLineUserId');
    $recipient='U0123456789abcdef0123456789abcdef';
    $same(true,$validId->invoke($service,$recipient));
    foreach([
        null,'','0123456789abcdef0123456789abcdef','U0123456789abcdef0123456789abcde',
        'U0123456789abcdef0123456789abcdef0','U0123456789abcdef0123456789abcdeF',
        'u0123456789abcdef0123456789abcdef','U0123456789abcdef0123456789abcdeg',
    ]as$value)$same(false,$validId->invoke($service,$value));

    $randomUuid=new ReflectionMethod(NotificationService::class,'randomUuid');
    $validUuid=new ReflectionMethod(NotificationService::class,'validRetryUuid');$seen=[];
    for($i=0;$i<32;$i++){
        $uuid=$randomUuid->invoke($service);$same(true,$validUuid->invoke($service,$uuid));
        if(isset($seen[$uuid]))throw new RuntimeException('duplicate LINE retry UUID generated');
        $seen[$uuid]=true;
    }
    foreach(['','550e8400-e29b-11d4-a716-446655440000','550E8400-E29B-41D4-A716-446655440000','550e8400-e29b-41d4-c716-446655440000']as$uuid)$same(false,$validUuid->invoke($service,$uuid));

    $storedBody=new ReflectionMethod(NotificationService::class,'storedLinePayloadBody');
    $stored='{"to":"'.$recipient.'","messages":[{"type":"text","text":"bill 2026-07 / 1,234.56"}]}';
    $same($stored,$storedBody->invoke($service,$stored,$recipient));
    $terminal=static function(callable $callback):void{
        try{$callback();}
        catch(LineDeliveryException $error){if($error->retryable)throw new RuntimeException('corrupt stored LINE data was marked retryable');return;}
        throw new RuntimeException('corrupt stored LINE data was accepted');
    };
    $terminal(fn()=>$storedBody->invoke($service,$stored,'Ufedcba9876543210fedcba9876543210'));
    $terminal(fn()=>$storedBody->invoke($service,'{"to":"'.$recipient.'","messages":[]}',$recipient));
    $terminal(fn()=>$storedBody->invoke($service,'{"to":"'.$recipient.'","messages":[{"type":"image","text":"x"}]}',$recipient));
    $terminal(fn()=>$storedBody->invoke($service,'{"to":',$recipient));

    $source=file_get_contents(dirname(__DIR__).'/src/Domain/NotificationService.php');
    if(!is_string($source))throw new RuntimeException('cannot read NotificationService');
    $same(true,str_contains($source,"\$existing['status']==='pending'&&(int)\$existing['attempts']===0"));
    $same(true,str_contains($source,"line_request_id=NULL,line_accepted_request_id=NULL"));
    $same(true,str_contains($source,'created_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()'));
    $same(true,str_contains($source,'n.recipient,n.payload'));
    $same(true,str_contains($source,'retry_generation_expired'));
});
$test('LINE delivery response classification is fail closed and provider IDs are retained',function()use($same):void{
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/NotificationService.php');
    if(!is_string($source))throw new RuntimeException('cannot read NotificationService');
    $accepted=strpos($source,'if(($status>=200&&$status<300)||$status===409)return');
    $retryable=strpos($source,'if($status>=500&&$status<=599)throw new LineDeliveryException');
    $terminal=strpos($source,"throw new LineDeliveryException('LINE API rejected the request");
    if($accepted===false||$retryable===false||$terminal===false||!($accepted<$retryable&&$retryable<$terminal))throw new RuntimeException('LINE HTTP outcome order is unsafe');
    $same(true,str_contains($source,"['x-line-request-id','x-line-accepted-request-id']"));
    $same(true,str_contains($source,"preg_match('/^[\\x21-\\x7E]+$/D',\$value)===1"));
    $same(true,str_contains($source,"'request_id'=>\$providerHeaders['x-line-request-id']??null"));
    $same(true,str_contains($source,"'accepted_request_id'=>\$providerHeaders['x-line-accepted-request-id']??null"));
    $same(true,str_contains($source,"new LineDeliveryException('LINE network request failed"));
    $same(false,str_contains($source,"if(\$status>=400&&\$status<500)throw new LineDeliveryException('LINE API rejected the request (HTTP '.\$status.')',true"));
});
$test('signed LINE webhook binds the exact raw body and strict direct-user IDs',function()use($same,$throwsHttp,$app):void{
    $service=new LineWebhookService($app);$signatureMethod=new ReflectionMethod(LineWebhookService::class,'assertSignature');
    $raw='{"destination":"Uffffffffffffffffffffffffffffffff","events":[]}';$secret='webhook-secret-test';
    $signature=base64_encode(hash_hmac('sha256',$raw,$secret,true));
    $request=new Request('POST','/api/webhooks/line',['content-type'=>'application/json; charset=utf-8','x-line-signature'=>$signature],[],[],[],['REMOTE_ADDR'=>'100.64.0.8'],'line-webhook-test',$raw);
    $signatureMethod->invoke($service,$request,$raw,$secret);
    $same($raw,$request->withParams(['ignored'=>'value'])->rawBody);
    $throwsHttp(fn()=>$signatureMethod->invoke($service,$request,$raw."\n",$secret),'LINE_WEBHOOK_SIGNATURE_INVALID',401);
    $wrong=new Request('POST','/api/webhooks/line',['x-line-signature'=>base64_encode(str_repeat('x',32))],[],[],[],[],'line-webhook-wrong',$raw);
    $throwsHttp(fn()=>$signatureMethod->invoke($service,$wrong,$raw,$secret),'LINE_WEBHOOK_SIGNATURE_INVALID',401);
    $malformed=new Request('POST','/api/webhooks/line',['x-line-signature'=>'not base64 ***'],[],[],[],[],'line-webhook-malformed',$raw);
    $throwsHttp(fn()=>$signatureMethod->invoke($service,$malformed,$raw,$secret),'LINE_WEBHOOK_SIGNATURE_INVALID',401);

    $candidateMethod=new ReflectionMethod(LineWebhookService::class,'replyCandidate');
    $lineUserId='U0123456789abcdef0123456789abcdef';
    $event=[
        'webhookEventId'=>'01ARZ3NDEKTSV4RRFFQ69G5FAV','mode'=>'active','type'=>'message',
        'replyToken'=>'reply_token_1234567890','source'=>['type'=>'user','userId'=>$lineUserId],
        'message'=>['type'=>'text','id'=>'123','text'=>'เลข LINE ของฉันคืออะไร'],
    ];
    $candidate=$candidateMethod->invoke($service,$event);
    $same(['event_id','event_type','line_user_id','reply_token'],array_keys($candidate));
    $same($lineUserId,$candidate['line_user_id']);$same('message',$candidate['event_type']);
    $follow=$event;$follow['type']='follow';unset($follow['message']);$same('follow',$candidateMethod->invoke($service,$follow)['event_type']);
    $invalid=[];
    $copy=$event;$copy['source']['userId']='U0123456789abcdef0123456789abcdeF';$invalid[]=$copy;
    $copy=$event;$copy['source']['userId']='U0123456789abcdef0123456789abcde';$invalid[]=$copy;
    $copy=$event;$copy['source']['type']='group';$invalid[]=$copy;
    $copy=$event;$copy['mode']='standby';$invalid[]=$copy;
    $copy=$event;$copy['message']['type']='image';$invalid[]=$copy;
    $copy=$event;$copy['webhookEventId']=strtolower($copy['webhookEventId']);$invalid[]=$copy;
    $copy=$event;$copy['replyToken']='short';$invalid[]=$copy;
    foreach($invalid as$item)$same(null,$candidateMethod->invoke($service,$item));

    $source=file_get_contents(dirname(__DIR__).'/src/Domain/LineWebhookService.php');
    if(!is_string($source))throw new RuntimeException('cannot read LineWebhookService');
    $same(true,str_contains($source,"return 'token_unavailable'"));
    $same(true,str_contains($source,"action IN ('line.webhook_user_id_replied','line.webhook_reply_token_unavailable')"));
    $same(true,str_contains($source,"'line_user_id_hash' => \$this->identityHash"));
});
$test('Railway proxy trust requires runtime identity, edge request ID, and an internal peer',function()use($same,$app):void{
    $keys=['RAILWAY_PROJECT_ID','RAILWAY_ENVIRONMENT_ID','RAILWAY_SERVICE_ID','TRUSTED_PROXIES'];$before=[];
    foreach($keys as$key)$before[$key]=getenv($key);
    try{
        putenv('RAILWAY_PROJECT_ID=project-test');putenv('RAILWAY_ENVIRONMENT_ID=environment-test');putenv('RAILWAY_SERVICE_ID=service-test');putenv('TRUSTED_PROXIES=');
        $same(true,$app->config->isRailwayProxyRequest('edge-request','100.64.0.2'));
        $same(true,$app->config->isRailwayProxyRequest('edge-request','100.255.255.255'));
        $same(false,$app->config->isRailwayProxyRequest('edge-request','101.0.0.1'));
        $same(false,$app->config->isRailwayProxyRequest('edge-request','203.0.113.40'));
        $same(false,$app->config->isRailwayProxyRequest('','100.64.0.2'));
        putenv('RAILWAY_SERVICE_ID=');$same(false,$app->config->isRailwayProxyRequest('edge-request','100.64.0.2'));putenv('RAILWAY_SERVICE_ID=service-test');

        $forged=new Request('GET','/',['x-railway-request-id'=>'forged','x-real-ip'=>'198.51.100.9','x-forwarded-for'=>'198.51.100.9'],[],[],[],['REMOTE_ADDR'=>'203.0.113.40'],'forged-proxy');
        $same('203.0.113.40',$app->security()->clientIp($forged));
        $edge=new Request('GET','/',['x-railway-request-id'=>'edge-request','x-real-ip'=>'198.51.100.9'],[],[],[],['REMOTE_ADDR'=>'100.64.0.2'],'railway-proxy');
        $same('198.51.100.9',$app->security()->clientIp($edge));
    }finally{
        foreach($before as$key=>$value){if($value===false)putenv($key);else putenv($key.'='.$value);}
    }
});
$test('slip quota accounting blocks exhausted and fractional credits',function()use($same):void{
    $quota=new ReflectionMethod(SystemSettingsService::class,'quotaRemaining');
    $same(null,$quota->invoke(null,null));$same(0,$quota->invoke(null,0));$same(-2,$quota->invoke(null,-2));
    $same(0,$quota->invoke(null,0.999));$same(1,$quota->invoke(null,'1.999'));$same(20,$quota->invoke(null,'2e1'));
    $same(null,$quota->invoke(null,''));$same(null,$quota->invoke(null,'NaN'));$same(null,$quota->invoke(null,INF));$same(null,$quota->invoke(null,[]));
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/SystemSettingsService.php');
    if(!is_string($source))throw new RuntimeException('cannot read SystemSettingsService');
    $same(2,substr_count($source,'SLIP_QUOTA_EXHAUSTED'));
    $same(true,str_contains($source,'if($quota<=0)'));
    $same(true,str_contains($source,"array_key_exists('isActive',\$branch)"));
    $same(true,str_contains($source,"array_key_exists('remaining',\$quotaInfo)"));
    $same(true,str_contains($source,'$quota=$quotaRaw===null?null:self::quotaRemaining($quotaRaw)'));
    $same(true,str_contains($source,'if($quota!==null&&$quota<=0)'));
});
$test('provider-declared duplicate slips remain pending for reconciliation',function()use($same):void{
    $same(false,SlipVerifier::isTransientProviderError('slipok',1012,400));
    $same(false,SlipVerifier::isTransientProviderError('easyslip','DUPLICATE_SLIP',400));
    $source=file_get_contents(dirname(__DIR__).'/src/Integration/SlipVerifier.php');
    if(!is_string($source))throw new RuntimeException('cannot read SlipVerifier');
    $ambiguous=strpos($source,"if((\$raw['ambiguous_duplicate']??false)===true)");
    $ordinary=strpos($source,"\$transient=(bool)(\$raw['transient']??false)");
    if($ambiguous===false||$ordinary===false||$ambiguous>$ordinary)throw new RuntimeException('ambiguous provider duplicates are finalized before pending handling');
    $slipDuplicate=strpos($source,"if(\$providerCode==='1012')");$slipSuccess=strpos($source,'$success=',$slipDuplicate?:0);
    $easyDuplicate=strpos($source,'if($wasDuplicate)');$easySuccess=strpos($source,"'transaction_ref'=>\$this->scalarString",$easyDuplicate?:0);
    if($slipDuplicate===false||$slipSuccess===false||$slipDuplicate>$slipSuccess||$easyDuplicate===false||$easySuccess===false||$easyDuplicate>$easySuccess)throw new RuntimeException('provider duplicate branch occurs after success construction');
    $same(true,substr_count($source,"'ambiguous_duplicate'=>true")>=2);
    $same(true,str_contains($source,"'checkDuplicate'=>'true'"));
    $same(true,str_contains($source,"'log'=>'true'"));
});
$test('stored slip permissions are private on POSIX systems',function()use($same,$app):void{
    $directory=$app->config->root.'/storage/private/slips';
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('cannot create private slip test directory');
    $file=tempnam($directory,'permission-test-');if($file===false)throw new RuntimeException('cannot create permission test file');
    try{
        if(file_put_contents($file,'test')===false)throw new RuntimeException('cannot write permission test file');
        @chmod($file,0666);
        $method=new ReflectionMethod(PaymentService::class,'secureStoredSlipPermissions');
        $method->invoke(new PaymentService($app),$file);$same(true,is_file($file));
        if(PHP_OS_FAMILY!=='Windows'){
            clearstatcache(true,$file);$permissions=fileperms($file);
            if($permissions===false)throw new RuntimeException('cannot read stored slip permissions');
            $same(0600,$permissions&0777);
        }
    }finally{@unlink($file);}
});
$test('canonical slip HMAC provides safe upload idempotency',function()use($same):void{
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/PaymentService.php');$schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');
    if(!is_string($source)||!is_string($schema))throw new RuntimeException('cannot read payment idempotency sources');
    $uploadStart=strpos($source,'public function upload(');$uploadEnd=strpos($source,'public function list(',$uploadStart?:0);$upload=substr($source,(int)$uploadStart,(int)$uploadEnd-(int)$uploadStart);
    $replay=strpos($upload,"if((\$payment['idempotent_replay']??false)===true)");$provider=strpos($upload,'$this->verifier->verify(');
    if($replay===false||$provider===false||$replay>$provider)throw new RuntimeException('idempotent replay reaches the slip provider');
    $same(false,str_contains($upload,"\$bill['status']!=='pending'"));
    $same(true,str_contains($upload,'if(is_file($absolute))@unlink($absolute)'));
    $reserveStart=strpos($source,'private function reserve(');$reserveEnd=strpos($source,'private function finalizeReserved(',$reserveStart?:0);$reserve=substr($source,(int)$reserveStart,(int)$reserveEnd-(int)$reserveStart);
    $duplicate=strpos($reserve,'WHERE slip_hmac=? LIMIT 1 FOR UPDATE');$active=strpos($reserve,"status IN ('pending','verified')");$insert=strpos($reserve,'INSERT INTO payments');
    if($duplicate===false||$active===false||$insert===false||!($duplicate<$active&&$active<$insert))throw new RuntimeException('slip HMAC replay is checked too late');
    $billStatus=strpos($reserve,"\$current['status']!=='pending'");
    if($billStatus===false||$duplicate>$billStatus)throw new RuntimeException('paid-bill status blocks a matching idempotent replay');
    $same(true,substr_count($reserve,"['idempotent_replay']=true")>=2);
    $same(true,str_contains($reserve,"(int)\$row['bill_id']===(int)\$bill['id']&&(int)\$row['resident_id']===\$residentId"));
    $same(true,str_contains($reserve,"'DUPLICATE_SLIP'"));
    $same(1,preg_match('/UNIQUE KEY\s+uq_payments_slip_hmac\s*\(slip_hmac\)/i',$schema));
});
$test('LINE and slip safety states are wired through UI, routes, and schema',function()use($same,$app):void{
    $root=dirname(__DIR__);$js=file_get_contents($root.'/public/assets/js/app.js');$admin=file_get_contents($root.'/templates/admin/console.php');$schema=file_get_contents($root.'/database/schema.sql');$migration=file_get_contents($root.'/database/migrations/004_line_webhook.sql');
    if(!is_string($js)||!is_string($admin)||!is_string($schema)||!is_string($migration))throw new RuntimeException('cannot read LINE UI/schema sources');
    $lineStart=strpos($js,"lineStartForm.addEventListener('submit'");$lineConfirm=strpos($js,"lineConfirmForm.addEventListener('submit'",$lineStart?:0);$lineStartSource=substr($js,(int)$lineStart,(int)$lineConfirm-(int)$lineStart);
    $same(true,str_contains($lineStartSource,"'LINE_DELIVERY_REJECTED'"));
    $same(false,str_contains($lineStartSource,"'LINE_DELIVERY_TEMPORARY'"));
    $same(true,str_contains($js,"sent: 'LINE รับคำขอแล้ว'"));
    $same(true,str_contains($js,"? 'โควตาไม่จำกัด'"));
    $same(true,str_contains($js,"integrations.line_webhook_url"));
    $same(true,str_contains($admin,'name="line_channel_secret" type="password"'));
    $same(true,str_contains($admin,'data-line-webhook-url readonly'));

    $same(1,preg_match('/line_user_id VARCHAR\(33\).*?CHECK\s*\(\s*line_user_id IS NULL OR line_user_id REGEXP \'\^U\[0-9a-f\]\{32\}\$\'\s*\)/s',$schema));
    $same(1,preg_match('/recipient VARCHAR\(33\).*?CHECK\s*\(\s*recipient REGEXP \'\^U\[0-9a-f\]\{32\}\$\'\s*\)/s',$schema));
    foreach(['line_channel_secret_enc','line_request_id','line_accepted_request_id']as$column){$same(true,str_contains($schema,$column));$same(true,str_contains($migration,$column));}
    $same(true,str_contains($migration,'@dormitory_004_invalid_line_ids'));
    $same(true,str_contains($migration,"line_user_id NOT REGEXP '^U[0-9a-f]{32}$'"));
    $same(true,str_contains($migration,"recipient NOT REGEXP '^U[0-9a-f]{32}$'"));

    $routes=new ReflectionProperty(Dormitory\Http\Router::class,'routes');$registered=$routes->getValue(Dormitory\Http\Routes::build($app));
    $webhooks=array_values(array_filter($registered,static fn(array$route):bool=>str_contains($route['regex'],'api/webhooks/line')));
    $same(1,count($webhooks));$same('POST',$webhooks[0]['method']);$same([],$webhooks[0]['options']);
    $application=file_get_contents($root.'/src/Application.php');if(!is_string($application))throw new RuntimeException('cannot read Application');
    $same(true,str_contains($application,"\$request->method === 'POST' && \$request->path === '/api/webhooks/line'"));
    $health=file_get_contents($root.'/public/healthz.php');if(!is_string($health))throw new RuntimeException('cannot read health check');
    foreach(['line_channel_secret_enc','line_request_id','line_accepted_request_id']as$column)$same(true,str_contains($health,$column));
});
$test('container runtime command dispatches by fail-closed role',function()use($same):void{
    $script=file_get_contents(dirname(__DIR__).'/scripts/start-runtime.sh');$docker=file_get_contents(dirname(__DIR__).'/Dockerfile');
    if(!is_string($script)||!is_string($docker))throw new RuntimeException('cannot read runtime dispatch sources');
    foreach(['web)','worker)','job)']as$case)$same(true,str_contains($script,$case));
    $same(true,str_contains($script,'role=${RUNTIME_ROLE:-}'));
    $same(false,str_contains($script,'RUNTIME_ROLE:-web'));
    $same(true,str_contains($docker,'CMD ["/var/www/html/scripts/start-runtime.sh"]'));
    $checker=file_get_contents(dirname(__DIR__).'/scripts/check_requirements.php');if(!is_string($checker))throw new RuntimeException('cannot read requirement checker');
    $same(true,str_contains($checker,"\$runtimeRole=(string)envValue(\$env,'RUNTIME_ROLE','all')"));
    $same(false,str_contains($checker,'$runtimeRole=strtolower'));
});

fwrite(STDOUT,"\n{$passed} passed, {$failed} failed".PHP_EOL);exit($failed===0?0:1);
