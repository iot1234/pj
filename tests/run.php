<?php
declare(strict_types=1);

use Dormitory\Domain\PromptPayService;
use Dormitory\Domain\BillingService;
use Dormitory\Domain\AdminUserService;
use Dormitory\Domain\LineDeliveryException;
use Dormitory\Domain\LineWebhookService;
use Dormitory\Domain\LineBindingService;
use Dormitory\Domain\NotificationService;
use Dormitory\Domain\PaymentService;
use Dormitory\Domain\SystemSettingsService;
use Dormitory\AuditLogger;
use Dormitory\Config;
use Dormitory\Database;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Http\Response;
use Dormitory\Http\Router;
use Dormitory\Http\Routes;
use Dormitory\Integration\SlipVerifier;
use Dormitory\Security\Password;
use Dormitory\Security\ResidentAccessCredential;
use Dormitory\Security\SecretCipher;
use Dormitory\Support\MySqlError;
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
$test('validators reject composite and ambiguous scalar JSON values without warnings',function()use($same):void{
    set_error_handler(static function(int $severity,string $message,string $file,int $line):never{
        throw new ErrorException($message,0,$severity,$file,$line);
    });
    try{
        $cases=[
            static fn()=>Validator::phone([]),
            static fn()=>Validator::period(new stdClass()),
            static fn()=>Validator::date([], 'move_in_date'),
            static fn()=>Validator::id(true),
            static fn()=>Validator::nullableEmail(false),
            static fn()=>Validator::enum(1,'status',['1']),
            static fn()=>Validator::scaledDecimal([], 'amount'),
            static fn()=>Validator::scaledDecimal(true, 'amount'),
        ];
        foreach($cases as$case){
            try{$case();throw new RuntimeException('ambiguous validator input was accepted');}
            catch(HttpException $error){$same(422,$error->status);$same('VALIDATION_ERROR',$error->errorCode);}
        }
    }finally{
        restore_error_handler();
    }
});
$test('service string inputs reject composite JSON values before casting',function()use($same,$app):void{
    $assertValidation=static function(callable $callback,string $field)use($same):void{
        try{$callback();throw new RuntimeException('composite service input was accepted');}
        catch(HttpException $error){$same(422,$error->status);$same('VALIDATION_ERROR',$error->errorCode);$same($field,$error->details['field']??null);}
    };
    $assertValidation(fn()=>$app->adminUsers()->create(['username'=>'safe_admin','password'=>[]],1),'password');
    $assertValidation(fn()=>$app->adminUsers()->update(1,['password'=>[]],1),'password');
    $assertValidation(fn()=>$app->billing()->updateSettings([
        'water_rate'=>'1.00','electric_rate'=>'1.00','due_days'=>true,
    ],1),'due_days');
    $boundedInteger=new ReflectionMethod(SystemSettingsService::class,'boundedInteger');
    foreach([true,false,[],new stdClass()]as$ambiguousInteger){
        $assertValidation(
            fn()=>$boundedInteger->invoke($app->settings(),$ambiguousInteger,'line_max_attempts',1,20),
            'line_max_attempts',
        );
    }
    $same(5,$boundedInteger->invoke($app->settings(),'5','line_max_attempts',1,20));
    $today=(new DateTimeImmutable('today',new DateTimeZone((string)$app->config->get('APP_TIMEZONE','Asia/Bangkok'))))->format('Y-m-d');
    $assertValidation(fn()=>$app->bookings()->createAdminResident(1,[
        'room_id'=>1,'full_name'=>'Composite Input','phone'=>'0812345678',
        'move_in_date'=>$today,'opening_water_reading'=>'0.00',
        'opening_electric_reading'=>'0.00','idempotency_key'=>[],
    ]),'idempotency_key');
    $roomValidate=new ReflectionMethod($app->rooms(),'validate');
    $same(['description'=>null,'image_key'=>null],$roomValidate->invoke($app->rooms(),['description'=>null,'image_key'=>null],true));
    $assertValidation(fn()=>$roomValidate->invoke($app->rooms(),['description'=>[]],true),'description');
    $assertValidation(fn()=>$roomValidate->invoke($app->rooms(),['image_key'=>[]],true),'image_key');
    $assertValidation(fn()=>$roomValidate->invoke($app->rooms(),['floor'=>true],true),'floor');
    $roomSource=file_get_contents(dirname(__DIR__).'/src/Domain/RoomService.php');
    $billingSource=file_get_contents(dirname(__DIR__).'/src/Domain/BillingService.php');
    if(!is_string($roomSource)||!is_string($billingSource))throw new RuntimeException('cannot read service validation sources');
    $same(true,str_contains($roomSource,"Validator::string(\$input['description'], 'description', 0, 2000)"));
    $same(true,str_contains($roomSource,"if(\$input['image_key']!==null&&!is_string(\$input['image_key']))"));
    $same(true,str_contains($billingSource,"&& !is_string(\$input['other_description']))"));
    $routes=file_get_contents(dirname(__DIR__).'/src/Http/Routes.php');if(!is_string($routes))throw new RuntimeException('cannot read routes');
    $same(true,str_contains($routes,'$queryString=static function'));
    $same(2,substr_count($routes,"if(!is_string(\$query[\$key]))throw new HttpException"));
    $same(false,str_contains($routes,"isset(\$r->query['status'])?(string)\$r->query['status']"));
});
$test('admin payment query helpers are captured and reject composite values before database access',function()use($same,$app):void{
    $property=new ReflectionProperty(Dormitory\Http\Router::class,'routes');
    $registered=$property->getValue(Dormitory\Http\Routes::build($app));
    $handler=null;
    foreach($registered as$route){
        if($route['method']==='GET'&&$route['regex']==='#^/api/admin/payments/?$#'){$handler=$route['handler'];break;}
    }
    if(!is_callable($handler))throw new RuntimeException('admin payment list route not found');
    $request=new Request('GET','/api/admin/payments',[],['status'=>[]],[],[],[],'query-helper-test');
    try{$handler($request);throw new RuntimeException('composite payment status was accepted');}
    catch(HttpException $error){
        $same(422,$error->status);$same('VALIDATION_ERROR',$error->errorCode);$same('status',$error->details['field']??null);
    }
});
$test('unknown fields rejected',fn()=>$throws(fn()=>Validator::only(['safe'=>1,'password'=>2],['safe']),'UNKNOWN_FIELDS'));
$test('protected HTML pages redirect to the matching login while APIs keep JSON 401',function()use($same,$app):void{
    $app->session()->logout();
    $app->clearActorCache();
    $router=Routes::build($app);
    foreach(['/resident'=>'/resident/login','/resident/'=>'/resident/login','/admin'=>'/admin/login','/admin/'=>'/admin/login']as$path=>$destination){
        $response=$router->dispatch(new Request('GET',$path,[],[],[],[],[],'html-auth-redirect'));
        $same(302,$response->status);
        $same($destination,$response->headers['Location']??null);
        $same('no-store',$response->headers['Cache-Control']??null);
    }
    $response=$router->dispatch(new Request('GET','/api/resident/profile',[],[],[],[],[],'api-auth-json'));
    $same(401,$response->status);
    $same('application/json; charset=utf-8',$response->headers['Content-Type']??null);
    $payload=json_decode($response->body,true,64,JSON_THROW_ON_ERROR);
    $same(false,$payload['ok']??null);
    $same('UNAUTHENTICATED',$payload['data']['code']??null);
});
$test('HTML router errors are safe Thai pages with a deterministic home link',function()use($same,$app):void{
    $assertPage=static function(Response $response,int $status,string $thaiTitle)use($same):void{
        $same($status,$response->status);
        $same('text/html; charset=utf-8',$response->headers['Content-Type']??null);
        $same(true,str_contains($response->body,'<html lang="th">'));
        $same(true,str_contains($response->body,$thaiTitle));
        $same(true,str_contains($response->body,'href="/"'));
        $same(true,str_contains($response->body,'กลับหน้าแรก'));
    };

    $notFound=(new Router($app))->dispatch(new Request('GET','/missing-page',[],[],[],[],[],'html-404'));
    $assertPage($notFound,404,'ไม่พบหน้าที่ต้องการ');

    $forbidden=new Router($app);
    $forbidden->get('/forbidden',static fn():never=>throw new HttpException(403,'sensitive permission detail','FORBIDDEN'));
    $forbiddenResponse=$forbidden->dispatch(new Request('GET','/forbidden',[],[],[],[],[],'html-403'));
    $assertPage($forbiddenResponse,403,'ไม่มีสิทธิ์เข้าถึง');
    $same(false,str_contains($forbiddenResponse->body,'sensitive permission detail'));

    $broken=new Router($app);
    $broken->get('/broken',static fn():null=>null);
    $brokenResponse=$broken->dispatch(new Request('GET','/broken',[],[],[],[],[],'html-500-reference'));
    $assertPage($brokenResponse,500,'ระบบขัดข้องชั่วคราว');
    $same(true,str_contains($brokenResponse->body,'html-500-reference'));
    $same(false,str_contains($brokenResponse->body,'Route did not return a response'));

    $escaped=Response::htmlError(500,'"><script>alert(1)</script>');
    $same(false,str_contains($escaped->body,'<script>alert(1)</script>'));
    $same(true,str_contains($escaped->body,'&lt;script&gt;alert(1)&lt;/script&gt;'));
});
$test('empty-body mutation routes reject unknown JSON fields before side effects',function()use($same,$app):void{
    $property=new ReflectionProperty(Router::class,'routes');
    $registered=$property->getValue(Routes::build($app));
    $cases=[
        ['POST','/api/auth/admin/logout'],
        ['POST','/api/auth/resident/logout'],
        ['POST','/api/resident/profile/line/code'],
        ['POST','/api/resident/profile/line/unlink'],
        ['POST','/api/resident/bills/1/slip'],
        ['DELETE','/api/admin/users/1'],
        ['DELETE','/api/admin/rooms/1'],
        ['POST','/api/admin/residents/1/line/code'],
        ['POST','/api/admin/residents/1/line/unlink'],
        ['POST','/api/admin/residents/1/access/reissue'],
        ['POST','/api/admin/bookings/1/confirm'],
        ['POST','/api/admin/bills/1/line'],
        ['POST','/api/admin/payments/1/retry'],
    ];
    foreach($cases as[$method,$path]){
        $matchedRoutes=[];
        foreach($registered as$route){
            if($route['method']!==$method||!preg_match($route['regex'],$path,$matches))continue;
            $params=[];
            foreach($matches as$key=>$value)if(is_string($key))$params[$key]=rawurldecode($value);
            $matchedRoutes[]=[$route['handler'],$params];
        }
        $same(1,count($matchedRoutes));
        [$handler,$params]=$matchedRoutes[0];
        $request=(new Request($method,$path,[],[],['unexpected'=>true],[],[],'empty-body-allowlist'))->withParams($params);
        try{$handler($request);throw new RuntimeException("{$method} {$path} accepted an unknown field");}
        catch(HttpException $error){$same('UNKNOWN_FIELDS',$error->errorCode);}
    }
});
$test('FR-10 resident lifecycle endpoints are explicitly allowlisted',function()use($same,$app):void{
    $property=new ReflectionProperty(Dormitory\Http\Router::class,'routes');
    $routes=$property->getValue(Dormitory\Http\Routes::build($app));
    $methods=[];
    foreach($routes as$route){if(str_contains($route['regex'],'api/admin/residents'))$methods[]=$route['method'];}
    $same(['GET','GET','POST','POST','POST','PUT','POST','POST'],$methods);
});
$test('FR-16 payment recovery has no manual paid endpoint',function()use($same,$app):void{
    $property=new ReflectionProperty(Dormitory\Http\Router::class,'routes');
    $routes=$property->getValue(Dormitory\Http\Routes::build($app));
    $methods=[];$paymentRoutes=[];
    foreach($routes as$route){if(str_contains($route['regex'],'api/admin/payments')){$methods[]=$route['method'];$paymentRoutes[]=$route['regex'];}}
    $same(['GET','GET','POST','POST'],$methods);
    foreach($paymentRoutes as$regex){if(preg_match('/approve|manual|mark.paid/i',$regex))throw new RuntimeException('manual payment approval route exists');}
});
$test('all SQL bootstraps include the expected integrity triggers',function()use($same):void{
    $root=dirname(__DIR__);
    foreach([
        'database/schema.sql'=>23,
        'database/migrations/002_operational_hardening.sql'=>15,
    ]as$file=>$expected){
        $sql=file_get_contents($root.'/'.$file);if(!is_string($sql))throw new RuntimeException("cannot read {$file}");
        preg_match_all('/^CREATE TRIGGER\s+([a-z0-9_]+)/mi',$sql,$matches);
        $same($expected,count(array_unique($matches[1])));
    }
    $repair=file_get_contents($root.'/database/migrations/003_append_only_guards.sql');if(!is_string($repair))throw new RuntimeException('cannot read migration 003');
    preg_match_all('/^CREATE TRIGGER\s+([a-z0-9_]+)/mi',$repair,$matches);$same(4,count(array_unique($matches[1])));
    $installer=file_get_contents($root.'/database/install.sql');if(!is_string($installer))throw new RuntimeException('cannot read fresh installer');
    preg_match_all('/^CREATE TRIGGER\s+([a-z0-9_]+)/mi',$installer,$matches);$same(23,count(array_unique($matches[1])));
    $schema=file_get_contents($root.'/database/schema.sql');if(!is_string($schema))throw new RuntimeException('cannot read fresh schema');
    foreach([
        'trg_bookings_insert_guard',
        'trg_occupancies_relationship_guard',
        "NEW.status <> 'pending'",
        "booking_status <> 'moved_in'",
        "NEW.opening_water_reading IS NULL",
        'Bill rent and meter snapshots must match its occupancy period',
        "period = NEW.period\n     LIMIT 1\n     FOR SHARE",
    ]as$guard)$same(true,str_contains($schema,$guard));
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
    $promptPayRouteStart=strpos($routes,"/api/resident/bills/{id}/promptpay");
    $promptPayRouteEnd=strpos($routes,"/api/resident/bills/{id}/slip",$promptPayRouteStart===false?0:$promptPayRouteStart);
    $promptPayRoute=$promptPayRouteStart!==false&&$promptPayRouteEnd!==false?substr($routes,$promptPayRouteStart,$promptPayRouteEnd-$promptPayRouteStart):'';
    $billLock=strpos($promptPayRoute,'SELECT id FROM bills WHERE id=? AND resident_id=? FOR SHARE');
    $settingsLock=strpos($promptPayRoute,'SELECT id FROM integration_settings WHERE id=1 FOR SHARE');
    $missingSettingsGuard=strpos($promptPayRoute,"if(\$settingsId===false)throw new HttpException(503");
    $detailRead=strpos($promptPayRoute,'residentDetail');
    $same(true,$billLock!==false&&$settingsLock!==false&&$missingSettingsGuard!==false&&$detailRead!==false
        &&$billLock<$settingsLock&&$settingsLock<$missingSettingsGuard&&$missingSettingsGuard<$detailRead);
});
$test('critical usability guards remain in the web UI',function()use($same):void{
    $root=dirname(__DIR__);$js=file_get_contents($root.'/public/assets/js/app.js');$admin=file_get_contents($root.'/templates/admin/console.php');$layout=file_get_contents($root.'/templates/layout.php');$resident=file_get_contents($root.'/src/Domain/ResidentService.php');$meters=file_get_contents($root.'/src/Domain/MeterService.php');$portal=file_get_contents($root.'/templates/resident/portal.php');
    if(!is_string($js)||!is_string($admin)||!is_string($layout)||!is_string($resident)||!is_string($meters)||!is_string($portal))throw new RuntimeException('cannot read UI sources');
    $same(1,preg_match('/paymentConfigurationReady\s*=\s*promptPayReady\s*&&\s*slipReady/',$js));
    $same(1,preg_match('/function applySavedMeterResult\s*\(/',$js));
    $same(0,preg_match('/name="confirm_pin"[^>]*required/',$admin));
    $same(1,preg_match('/จำนวนเงินอื่น \/ ห้อง/',$admin));
    $same(1,preg_match('/water_units.*water_rate.*water_amount/s',$js));
    $same(true,str_contains($js,'Array.isArray(bill.items)'));
    $same(false,str_contains($js,'bill.line_items'));
    $same(true,str_contains($js,'function syncBillActionState()'));
    $same(true,str_contains($js,'currentPeriodConfirmed'));
    $same(true,str_contains($js,"elements.confirm_current_period.addEventListener('change', invalidateBillPreview)"));
    $same(true,str_contains($js,'function hasDirtyMeterRows()'));
    $same(1,preg_match("/activeView === 'meters'.*?hasDirtyMeterRows\(\).*?renderMeters\(\);/s",$js));
    foreach(['is_billed','has_later_reading',"'_locked'", "'_lock_reason'"]as$item)$same(true,str_contains($meters,$item));
    foreach(['waterLocked','electricLocked','editableInputs','ออกบิลแล้ว']as$item)$same(true,str_contains($js,$item));
    $same(true,str_contains($js,"profile.line_user_id_hint"));
    $same(true,str_contains($resident,"unset(\$row['line_user_id'],\$row['auth_version'])"));
    $same(2,preg_match_all('/id="(?:preview-bills-button|create-bills-button)" disabled/',$admin));
    $same(true,str_contains($portal,'id="resident-line-status-refresh"'));
    $same(true,str_contains($portal,'id="resident-line-add-friend"'));
    $same(true,str_contains($portal,'id="resident-line-code-qr"'));
    $same(true,str_contains($portal,'id="resident-line-code-qr-fallback"'));
    $same(true,str_contains($js,'startLineCodeTracking(result.expires_at)'));
    $same(true,str_contains($js,'async function getQrLibrary()'));
    $same(true,str_contains($js,'const moduleUrl = `${source}${source.includes(\'?\') ? \'&\' : \'?\'}module=1`;'));
    $same(false,str_contains($js,'qrcode-loader'));
    $same(true,str_contains($layout,'$assetUrl = static function'));
    $same(true,str_contains($layout,'$assetUrl(\'/assets/js/vendor/qrcode.min.js\')'));
    $same(true,str_contains($layout,'$assetUrl(\'/assets/js/app.js\')'));
    $same(true,str_contains($layout,'meta name="app-timezone"'));
    $same(false,str_contains($js,"timeZone: 'Asia/Bangkok'"));
});
$test('login controls and admin tables retain accessibility contracts',function()use($same):void{
    $root=dirname(__DIR__);
    $residentLogin=file_get_contents($root.'/templates/resident/login.php');
    $adminLogin=file_get_contents($root.'/templates/admin/login.php');
    $admin=file_get_contents($root.'/templates/admin/console.php');
    $css=file_get_contents($root.'/public/assets/css/app.css');
    if(!is_string($residentLogin)||!is_string($adminLogin)||!is_string($admin)||!is_string($css))throw new RuntimeException('cannot read accessibility UI sources');

    foreach([
        'for="resident-phone"','id="resident-phone"','aria-describedby="resident-phone-help"',
        'for="resident-credential"','id="resident-credential"','aria-controls="resident-credential"',
        'for="resident-new-password"','id="resident-new-password"','aria-controls="resident-new-password"',
        'for="resident-new-password-confirm"','id="resident-new-password-confirm"','aria-controls="resident-new-password-confirm"',
    ]as$contract)$same(true,str_contains($residentLogin,$contract));
    foreach([
        'for="admin-username"','id="admin-username"','for="admin-password"','id="admin-password"','aria-controls="admin-password"',
    ]as$contract)$same(true,str_contains($adminLogin,$contract));
    $same(0,preg_match('/<label[^>]*class="field"[^>]*>.*?password-toggle.*?<\/label>/s',$residentLogin.$adminLogin));
    $same(7,preg_match_all('/class="table-scroll(?: meter-table)?" role="region" aria-label="[^"]+" tabindex="0"/',$admin));
    $same(true,str_contains($css,'.table-scroll:focus-visible'));
    $same(1,preg_match('/\.status-[^{]*\.status-danger\s*\{[^}]*var\(--red\)/s',$css));
    foreach(['.button { display: inline-flex; min-height: 44px','.button-small { min-height: 44px','.text-control { display: inline-flex; min-width: 54px; min-height: 44px','.password-toggle { position: absolute;']as$contract)$same(true,str_contains($css,$contract));
    $same(1,preg_match('/\.password-toggle\s*\{[^}]*min-width:\s*44px;[^}]*min-height:\s*44px;/s',$css));
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
$test('MySQL duplicate classification requires errno 1062 and an expected unique key',function()use($same):void{
    $error=static function(int $driverCode,string $message,string $sqlState='23000'):PDOException{
        $exception=new PDOException($message);
        $exception->errorInfo=[$sqlState,$driverCode,$message];
        return $exception;
    };
    $qualified=$error(1062,"Duplicate entry 'owner' for key 'admin_users.uq_admin_users_username'");
    $same(true,MySqlError::isDuplicateKey($qualified,'uq_admin_users_username'));
    $same(false,MySqlError::isDuplicateKey($qualified,'uq_residents_phone_norm'));
    $same(false,MySqlError::isDuplicateKey($error(1062,"Duplicate entry 'owner' for key 'uq_admin_users_username_shadow'"),'uq_admin_users_username'));
    $same(false,MySqlError::isDuplicateKey($error(1452,'Cannot add or update a child row: a foreign key constraint fails'),'uq_admin_users_username'));
    $same(false,MySqlError::isDuplicateKey($error(3819,"Check constraint 'chk_admin_users_role' is violated"),'uq_admin_users_username'));
    $same(false,MySqlError::isDuplicateKey($error(1062,"Duplicate entry 'uq_admin_users_username' for key 'uq_unexpected_new_guard'"),'uq_admin_users_username'));
});
$test('identity and payment services only translate their expected duplicate keys',function()use($same):void{
    foreach([
        'src/Domain/AdminUserService.php'=>['uq_admin_users_username'],
        'src/Domain/ResidentService.php'=>['uq_residents_phone_norm'],
        'src/Domain/PaymentService.php'=>['uq_payments_slip_hmac','uq_payments_one_active_per_bill','uq_payments_transaction_ref'],
    ]as$file=>$expectedKeys){
        $source=file_get_contents(dirname(__DIR__).'/'.$file);
        if(!is_string($source))throw new RuntimeException('cannot read '.$file);
        $same(false,str_contains($source,"getCode()==='23000'"));
        foreach($expectedKeys as$key)$same(true,str_contains($source,"'{$key}'"));
    }
});
$test('LINE binding duplicate races are scoped to their exact unique keys',function()use($same):void{
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/LineBindingService.php');
    if(!is_string($source))throw new RuntimeException('cannot read LineBindingService');
    $same(true,str_contains($source,"MySqlError::isDuplicateKey(\$error, 'uq_line_link_codes_code_hash')"));
    $same(true,str_contains($source,"MySqlError::isDuplicateKey(\$error, 'uq_residents_line_user_id')"));
    $same(2,substr_count($source,'MySqlError::isDuplicateKey('));
    $same(false,str_contains($source,'errorInfo[1]'));
});
$test('PromptPay phone CRC vector',function()use($same):void{$same('00020101021229370016A000000677010111011300668123456785802TH530376454071234.566304D937',PromptPayService::payload('0812345678','1234.56'));});
$test('PromptPay tax ID CRC vector',function()use($same):void{$same('00020101021229370016A000000677010111021312345678901235802TH530376454041.006304304C',PromptPayService::payload('1234567890123','1.00'));});
$test('PromptPay connection test returns a bounded random live-account QR without payment side effects',function()use($same,$app):void{
    $database=$app->database();$pdoProperty=new ReflectionProperty(Database::class,'pdo');$original=$pdoProperty->getValue($database);
    $row=['id'=>1,'promptpay_target'=>'0812345678','promptpay_name'=>'QA Receiver'];
    $fakePdo=new class($row) extends PDO{
        public int $queries=0;
        /** @param array<string,mixed> $row */
        public function __construct(private readonly array $row){}
        public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{
            $this->queries++;
            return new class($this->row) extends PDOStatement{
                private bool $read=false;
                /** @param array<string,mixed> $row */
                public function __construct(private readonly array $row){}
                public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{
                    if($this->read)return false;$this->read=true;return $this->row;
                }
            };
        }
    };
    $amounts=[];
    try{
        $pdoProperty->setValue($database,$fakePdo);
        for($index=0;$index<48;$index++){
            $result=$app->settings()->testConnection(' PrOmPtPaY ');$qr=$result['test_qr']??null;
            if(!is_array($qr))throw new RuntimeException('PromptPay test QR envelope is missing');
            $amount=$qr['amount']??null;$payload=$qr['payload']??null;$generatedAt=$qr['generated_at']??null;
            $same(true,is_string($amount)&&preg_match('/^1\.(?:0[1-9]|[1-9][0-9])$/D',$amount)===1);
            $same(true,(float)$amount>=1.01&&(float)$amount<=1.99);
            $same(PromptPayService::payload('0812345678',$amount),(string)$payload);
            $same(true,is_string($generatedAt)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$generatedAt)===1);
            $same('promptpay',$result['integration']??null);$same(true,$result['ready']??null);
            $same('********5678',$result['target_hint']??null);$same('QA Receiver',$result['recipient_name']??null);
            $same([],array_values(array_intersect(['bill_id','payment_id','provider','transaction_ref'],array_keys($result))));
            $amounts[$amount]=true;
        }
    }finally{$pdoProperty->setValue($database,$original);}
    $same(true,count($amounts)>1);
    $same(48,$fakePdo->queries);

    $settings=file_get_contents(dirname(__DIR__).'/src/Domain/SystemSettingsService.php');
    $routesSource=file_get_contents(dirname(__DIR__).'/src/Http/Routes.php');
    if(!is_string($settings)||!is_string($routesSource))throw new RuntimeException('cannot read PromptPay test sources');
    $start=strpos($settings,"if (\$integration === 'promptpay')");$end=strpos($settings,"if (\$integration === 'slip')",$start===false?0:$start);
    if($start===false||$end===false)throw new RuntimeException('cannot isolate PromptPay connection test');
    $block=substr($settings,$start,$end-$start);
    $same(true,str_contains($block,'random_int(101, 199)'));
    $same(true,str_contains($block,'PromptPayService::payload($target, $amount)'));
    foreach(['fixedJsonGet','payments()->','billing()->','INSERT ','UPDATE ']as$sideEffect)$same(false,str_contains($block,$sideEffect));
    $routeStart=strpos($routesSource,"'/api/admin/settings/integrations/test'");
    $routeEnd=strpos($routesSource,'return $router',$routeStart===false?0:$routeStart);
    if($routeStart===false||$routeEnd===false)throw new RuntimeException('cannot isolate integration test route');
    $routeBlock=substr($routesSource,$routeStart,$routeEnd-$routeStart);
    $same(true,str_contains($routeBlock,"Validator::only(\$r->body,['integration'])"));

    $routesProperty=new ReflectionProperty(Dormitory\Http\Router::class,'routes');
    $registered=$routesProperty->getValue(Dormitory\Http\Routes::build($app));
    $matches=array_values(array_filter($registered,static fn(array$route):bool=>str_contains($route['regex'],'api/admin/settings/integrations/test')));
    $same(1,count($matches));$same('POST',$matches[0]['method']);$same(['auth'=>'admin','role'=>'owner'],$matches[0]['options']);
});
$test('PromptPay random QR UI validates server data and renders it without HTML injection',function()use($same):void{
    $root=dirname(__DIR__);$js=file_get_contents($root.'/public/assets/js/app.js');$template=file_get_contents($root.'/templates/admin/console.php');
    if(!is_string($js)||!is_string($template))throw new RuntimeException('cannot read PromptPay test UI');
    $start=strpos($js,'async function renderPromptPayTestQr');$end=strpos($js,'function advanceIntegrationRevision',$start===false?0:$start);
    if($start===false||$end===false)throw new RuntimeException('cannot isolate PromptPay QR renderer');
    $renderer=substr($js,$start,$end-$start);
    foreach(["/^1\\.\\d{2}$/","number(amount) < 1.01","number(amount) > 1.99","/^000201/","payload.length > 512","renderQrCanvas(qrLibrary, canvas, payload","replaceChildren(canvas, meta)"]as$guard)$same(true,str_contains($renderer,$guard));
    $same(false,str_contains($renderer,'innerHTML'));
    $same(true,str_contains(substr($js,0,6000),'node.textContent = content'));
    $helperStart=strpos($js,'function renderQrCanvas');$helperEnd=strpos($js,'function safeRoomImage',$helperStart===false?0:$helperStart);
    if($helperStart===false||$helperEnd===false)throw new RuntimeException('cannot isolate callback QR renderer');
    $helper=substr($js,$helperStart,$helperEnd-$helperStart);
    foreach(['return new Promise','let settled = false','if (settled) return','qrLibrary.toCanvas(canvas, payload, options, finish)','pending?.then','finish(error)']as$contract)$same(true,str_contains($helper,$contract));
    $same(false,str_contains($js,'await qrLibrary.toCanvas('));
    $same(2,substr_count($js,'await renderQrCanvas(qrLibrary, canvas, payload'));
    $same(true,str_contains($renderer,"form.dataset.revision !== revision || form.dataset.dirty === 'true'"));
    $same(true,str_contains($js,"if (integration === 'promptpay') clearPromptPayTestQr()"));
    $same(true,str_contains($js,'await renderPromptPayTestQr(result, form, revision)'));
    $same(true,str_contains($template,'id="promptpay-test-dialog"'));
    $same(true,str_contains($template,'id="promptpay-test-qr-stage"'));
    $same(true,str_contains($template,'class="security-note promptpay-transfer-warning" role="alert"'));
    $same(true,str_contains($template,'QR นี้ชี้บัญชีจริง'));
    $same(true,str_contains($template,'ห้ามกดยืนยันโอน'));
    $same(true,str_contains($template,'ไม่สร้างบิล ไม่สร้างรายการชำระ'));
});
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
$test('EasySlip terminal versus retryable errors',function()use($same):void{$same(false,SlipVerifier::isTransientProviderError('easyslip','SLIP_NOT_FOUND',404));$same(true,SlipVerifier::isTransientProviderError('easyslip','SLIP_PENDING',404));$same(true,SlipVerifier::isTransientProviderError('easyslip','API_SERVER_ERROR',500));$same(true,SlipVerifier::isTransientProviderError('easyslip','VALIDATION_ERROR',400));});
$test('provider multipart filenames are derived from verified MIME types',function()use($same):void{
    $filename=new ReflectionMethod(SlipVerifier::class,'uploadFilenameForMime');
    $same('slip.jpg',$filename->invoke(null,'image/jpeg'));
    $same('slip.png',$filename->invoke(null,'image/png'));
    $same('slip.webp',$filename->invoke(null,'image/webp'));
    try{$filename->invoke(null,'image/gif');throw new RuntimeException('unsupported provider upload MIME was accepted');}
    catch(InvalidArgumentException $error){$same('Unsupported slip MIME type',$error->getMessage());}
    $source=file_get_contents(dirname(__DIR__).'/src/Integration/SlipVerifier.php');
    if(!is_string($source))throw new RuntimeException('cannot read SlipVerifier');
    $same(2,substr_count($source,'new \\CURLFile($path,$mime,self::uploadFilenameForMime($mime))'));
    $same(false,str_contains($source,"new \\CURLFile(\$path,\$mime,'slip')"));
});
$test('SlipOK bank delay and receiver configuration remain retryable',function()use($same):void{$same(true,SlipVerifier::isTransientProviderError('slipok',1009,400));$same(true,SlipVerifier::isTransientProviderError('slipok',1010,400));$same(true,SlipVerifier::isTransientProviderError('slipok',1014,400));$same(false,SlipVerifier::isTransientProviderError('slipok',1007,400));$same(false,SlipVerifier::isTransientProviderError('slipok',1011,400));$same(false,SlipVerifier::isTransientProviderError('slipok',1013,400));});
$test('slip provider and cURL diagnostics never become public payment reasons',function()use($same,$app):void{
    $service=new SlipVerifier($app);
    $providerReason=new ReflectionMethod(SlipVerifier::class,'providerReason');
    $diagnostic='SSL certificate problem at C:\\private\\ca.pem for internal-proxy.example';
    $same('EasySlip rejected the slip',$providerReason->invoke($service,['message'=>$diagnostic],'EasySlip rejected the slip'));
    $same('SlipOK rejected the slip',$providerReason->invoke($service,['error'=>['message'=>$diagnostic]],'SlipOK rejected the slip'));

    $source=file_get_contents(dirname(__DIR__).'/src/Integration/SlipVerifier.php');
    if(!is_string($source))throw new RuntimeException('cannot read SlipVerifier');
    $verifyStart=strpos($source,'public function verify(');
    $verifyEnd=strpos($source,'private function slipOk(',$verifyStart===false?0:$verifyStart);
    $requestStart=strpos($source,'private function request(');
    $requestEnd=strpos($source,'private function auditPayload(',$requestStart===false?0:$requestStart);
    if($verifyStart===false||$verifyEnd===false||$requestStart===false||$requestEnd===false)throw new RuntimeException('cannot isolate slip verification error paths');
    $verify=substr($source,$verifyStart,$verifyEnd-$verifyStart);
    $request=substr($source,$requestStart,$requestEnd-$requestStart);
    $same(1,preg_match('/catch\(\\\\Throwable\)\{return \$this->pending\(\$provider,\'[^\']+\',\[\]\);\}/',$verify));
    $same(false,str_contains($verify,'getMessage('));
    $same(false,str_contains($source,'Provider unavailable:'));
    $same(false,str_contains($request,'curl_error('));
    $same(true,str_contains($request,'curl_errno($ch)'));
});
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
$test('monthly automation accepts only a completed period and stays dry-run by default',function()use($same,$throws,$app):void{
    $guard=new ReflectionMethod(BillingService::class,'assertClosedBillingPeriod');
    $now=new DateTimeImmutable('2026-07-15T12:00:00+07:00');
    $guard->invoke($app->billing(),'2026-06',$now);
    $throws(fn()=>$guard->invoke($app->billing(),'2026-07',$now),'BILL_PERIOD_NOT_CLOSED');
    $throws(fn()=>$guard->invoke($app->billing(),'2026-08',$now),'BILL_PERIOD_NOT_CLOSED');
    $script=file_get_contents(dirname(__DIR__).'/scripts/generate_monthly_bills.php');
    if(!is_string($script))throw new RuntimeException('cannot read monthly billing CLI');
    $same(true,str_contains($script,"\$apply = false;"));
    $same(true,str_contains($script,"if (!\$apply)"));
    $same(true,str_contains($script,'generateClosedPeriod('));
    $same(true,str_contains($script,"'bill.monthly_generate'"));
    $billing=file_get_contents(dirname(__DIR__).'/src/Domain/BillingService.php');
    if(!is_string($billing))throw new RuntimeException('cannot read billing service');
    $same(true,str_contains($billing,'AND NOT EXISTS ('));
    $same(true,str_contains($billing,'WHERE b.occupancy_id=o.id'));
    $same(true,str_contains($billing,"'BILL_AUTOMATION_BLOCKED'"));
});
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
$test('admin rehash and credential-backed resident login use layered throttling',function()use($same,$app):void{
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
    $same(true,str_contains($residentBlock,"Validator::only(\$input, ['phone','credential','new_password'])"));
    $same(true,str_contains($residentBlock,"'resident-login-ip-daily'"));
    $same(true,str_contains($residentBlock,"'auth_method' => \$authMethod"));
    $same(true,str_contains($residentBlock,"'assurance' => 'high'"));
    $same(true,str_contains($residentBlock,'writeStrict'));
    $same(true,str_contains($residentBlock,"JOIN occupancies o ON o.resident_id=r.id AND o.status='active'"));
    $same(true,str_contains($residentBlock,'JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL'));
    $same(false,str_contains($residentBlock,'pin_hash'));
    $same(false,str_contains($residentBlock,'trustedDevice'));
    $same(true,str_contains($residentBlock,"limiter()->clear('resident-login"));
    $same(false,str_contains($residentBlock,"rememberLoginDevice('resident'"));
    $same(true,str_contains($residentBlock,'access_password_hash'));
    $same(true,str_contains($residentBlock,'activation_code_hash=NULL'));
    $same(true,str_contains($residentBlock,'auth_version=auth_version+1'));
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
$test('resident activation codes are deterministic, high entropy, expiring and stored only as hashes',function()use($same,$app):void{
    $issued=ResidentAccessCredential::issue($app->config,17,4);
    $same(1,preg_match('/^[0-9A-HJKMNP-TV-Z]{5}(?:-[0-9A-HJKMNP-TV-Z]{5}){3}$/D',$issued['code']));
    $same(64,strlen($issued['hash']));
    $same(true,ResidentAccessCredential::verify($app->config,17,4,$issued['code'],$issued['hash']));
    $same(true,ResidentAccessCredential::verify($app->config,17,4,strtolower(str_replace('-',' ',$issued['code'])),$issued['hash']));
    $same(false,ResidentAccessCredential::verify($app->config,17,5,$issued['code'],$issued['hash']));
    $same(false,ResidentAccessCredential::verify($app->config,17,4,'00000-00000-00000-00000',$issued['hash']));
    $same($issued['code'],ResidentAccessCredential::restore($app->config,17,4,$issued['hash']));
    $same(604800,ResidentAccessCredential::ttlSeconds($app->config));

    $booking=file_get_contents(dirname(__DIR__).'/src/Domain/BookingService.php');
    $migration=file_get_contents(dirname(__DIR__).'/database/migrations/008_resident_access_credentials.sql');
    if(!is_string($booking)||!is_string($migration))throw new RuntimeException('cannot read resident credential sources');
    $same(true,str_contains($booking,'ResidentAccessCredential::issue'));
    $same(true,str_contains($booking,'access_password_hash=NULL'));
    $same(true,str_contains($booking,"'activation_code'=>\$activation['code']"));
    foreach(['access_password_hash','activation_code_hash','activation_expires_at','activation_consumed_at']as$column)$same(true,str_contains($migration,$column));
    $same(false,str_contains($migration,'activation_code_plain'));
});
$test('occupancy meter migration preserves safe pending openings and rejects partial or historical gaps',function()use($same):void{
    $migration=file_get_contents(dirname(__DIR__).'/database/migrations/009_occupancy_meter_baselines.sql');
    if(!is_string($migration))throw new RuntimeException('cannot read occupancy meter migration');
    $same(true,str_contains($migration,'@dormitory_009_active_opening_gaps'));
    $same(true,str_contains($migration,'(o.opening_water_reading IS NULL) <> (o.opening_electric_reading IS NULL)'));
    $same(true,str_contains($migration,'SELECT 1 FROM meter_readings history'));
    $same(true,str_contains($migration,'SELECT 1 FROM bills history'));
    $same(true,str_contains($migration,'history.occupancy_id = o.id'));
    $same(true,str_contains($migration,'history.room_id = o.room_id'));
    $same(true,str_contains($migration,'opening_water_reading IS NULL'));
    $same(true,str_contains($migration,'opening_electric_reading IS NULL'));
    $same(true,str_contains($migration,'DORMITORY_009_REPAIR_ACTIVE_OPENING_READINGS_BEFORE_RERUN'));
    $same(true,str_contains($migration,'DORMITORY_009_REPAIR_OCCUPANCY_PERIOD_OVERLAPS_BEFORE_RERUN'));
    $same(true,str_contains($migration,'DORMITORY_009_REPAIR_RESIDENT_OCCUPANCY_STATES_BEFORE_RERUN'));
    $same(true,str_contains($migration,'DORMITORY_009_REPAIR_METER_OCCUPANCY_LINKS_BEFORE_RERUN'));
    $same(true,str_contains($migration,'DORMITORY_009_REPAIR_METER_CHAIN_BEFORE_RERUN'));
    $same(true,str_contains($migration,'prior_row.period = DATE_SUB(current_row.period, INTERVAL 1 MONTH)'));
    $same(true,str_contains($migration,"second_row.id > first_row.id"));
    $same(true,str_contains($migration,"linked_booking.status <> 'moved_in'"));
    $same(true,str_contains($migration,'ADD CONSTRAINT chk_occupancies_opening_readings CHECK'));
});
$test('direct admin resident check-in is authenticated, rate-limited, audited, and transactionally routed',function()use($same,$app):void{
    $routesProperty=new ReflectionProperty(Dormitory\Http\Router::class,'routes');
    $registered=$routesProperty->getValue(Dormitory\Http\Routes::build($app));
    $collection=array_values(array_filter($registered,static fn(array$route):bool=>$route['regex']==='#^/api/admin/residents/?$#'));
    $same(2,count($collection));$same(['GET','POST'],array_column($collection,'method'));
    $create=array_values(array_filter($collection,static fn(array$route):bool=>$route['method']==='POST'));
    $same(1,count($create));$same(['auth'=>'admin'],$create[0]['options']);

    $source=file_get_contents(dirname(__DIR__).'/src/Http/Routes.php');if(!is_string($source))throw new RuntimeException('cannot read direct resident route');
    $start=strpos($source,"\$router->post('/api/admin/residents'");$end=strpos($source,"\$router->put('/api/admin/residents/{id}'",$start===false?0:$start);
    if($start===false||$end===false)throw new RuntimeException('cannot isolate direct resident route');
    $route=substr($source,$start,$end-$start);
    $limit=strpos($route,"hit('resident-admin-create',(string)\$adminId,60,3600,300)");
    $expiry=strpos($route,"expirePublicPhoneHolds(\$r->body['phone']??null)");
    $transaction=strpos($route,'$app->database()->transaction');
    $service=strpos($route,'createAdminResident($adminId,$r->body)');
    $audit=strpos($route,"writeStrict(\$r,\$app->actor(),'resident.admin_create'");
    $same(true,$limit!==false&&$expiry!==false&&$transaction!==false&&$service!==false&&$audit!==false
        &&$limit<$expiry&&$expiry<$transaction&&$transaction<$service&&$service<$audit);
    $same(true,str_contains($route,"if(!\$replay)\$app->audit()->writeStrict"));
    $same(true,str_contains($route,'$replay?200:201'));
    $same(true,str_contains($route,"\$replay?'Existing resident check-in returned':'Resident checked in'"));
});
$test('direct resident UI keeps one idempotency key, lists only available rooms, and confirms historical reuse',function()use($same):void{
    $root=dirname(__DIR__);$js=file_get_contents($root.'/public/assets/js/app.js');$template=file_get_contents($root.'/templates/admin/console.php');
    if(!is_string($js)||!is_string($template))throw new RuntimeException('cannot read direct resident UI sources');
    foreach([
        'data-open-resident-create','id="resident-create-dialog"','id="resident-create-form"',
        'name="idempotency_key"','name="room_id" required','name="move_in_date" type="date" min="2000-01-01" required',
        'name="full_name"','name="phone"','name="email"','name="reuse_resident_id" type="checkbox" disabled',
        'id="resident-create-reuse-field" hidden','id="resident-create-error" role="alert" hidden',
    ]as$surface)$same(true,str_contains($template,$surface));
    $same(true,str_contains($template,'activation code'));
    $same(true,str_contains($template,'name="opening_water_reading"'));
    $same(true,str_contains($template,'name="opening_electric_reading"'));
    $same(false,str_contains(substr($template,strpos($template,'id="resident-create-dialog"'),strpos($template,'id="resident-edit-dialog"')-strpos($template,'id="resident-create-dialog"')),'PIN'));

    $populateStart=strpos($js,'function populateResidentCreateRooms');$populateEnd=strpos($js,'async function openResidentCreateForm',$populateStart===false?0:$populateStart);
    $openEnd=strpos($js,'function renderResidents',$populateEnd===false?0:$populateEnd);
    if($populateStart===false||$populateEnd===false||$openEnd===false)throw new RuntimeException('cannot isolate direct resident form setup');
    $populate=substr($js,$populateStart,$populateEnd-$populateStart);$open=substr($js,$populateEnd,$openEnd-$populateEnd);
    $same(true,str_contains($populate,"state.rooms.filter((room) => room.status === 'available')"));
    $same(true,str_contains($populate,'select.replaceChildren(prompt)'));
    $same(true,str_contains($open,"room?.status === 'available' ? room.id : ''"));
    $same(true,str_contains($open,"if (!state.loaded.has('rooms')) await loadRooms()"));
    $same(true,str_contains($open,'resetResidentCreateReuse(form)'));
    $same(true,str_contains($open,'form.elements.idempotency_key.value = window.crypto?.randomUUID?.()'));
    $same(1,substr_count($open,'form.elements.idempotency_key.value ='));
    $same(true,str_contains($open,'form.elements.move_in_date.max = isoToday()'));
    $same(true,str_contains($js,"actionButton('เพิ่มผู้พัก', 'add-resident-to-room'"));
    $same(true,str_contains($js,"if (room.status === 'available') actions.push"));
    $same(true,str_contains($js,"button.dataset.action === 'add-resident-to-room'"));
    foreach(['resident.access_active === true','resident.activation_pending === true','ต้องออกคีย์']as$accessState)$same(true,str_contains($js,$accessState));

    $submitStart=strpos($js,"\$('#resident-create-form').addEventListener('submit'");
    $submitEnd=strpos($js,"\$('#resident-rows').addEventListener('click'",$submitStart===false?0:$submitStart);
    if($submitStart===false||$submitEnd===false)throw new RuntimeException('cannot isolate direct resident form submission');
    $submit=substr($js,$submitStart,$submitEnd-$submitStart);
    $same(true,str_contains($submit,'Object.fromEntries(new FormData(form).entries())'));
    $same(true,str_contains($submit,"api('/api/admin/residents', { method: 'POST', body: values })"));
    $same(false,str_contains($submit,'idempotency_key.value ='));
    $same(true,str_contains($submit,"requestError?.details?.code === 'RESIDENT_REUSE_CONFIRMATION_REQUIRED'"));
    foreach([
        "reuseInput.value = String(requestError.details.resident_id || '')","reuseInput.disabled = false",
        'reuseInput.required = true','reuseInput.focus()',"\$('#resident-create-reuse-field').hidden = false",
        "['ROOM_NOT_AVAILABLE', 'ROOM_OCCUPIED', 'ROOM_DELETED']",'await loadRooms()','populateResidentCreateRooms(values.room_id)',
        'Promise.all([loadResidents(), loadRooms(), loadBookings()])',
    ]as$behavior)$same(true,str_contains($submit,$behavior));
    $same(true,strpos($submit,"api('/api/admin/residents'")<strpos($submit,'form.reset()'));
    $same(false,str_contains($submit,'innerHTML'));
});
$test('direct admin resident check-in validates the complete request before database access',function()use($same,$throws,$throwsHttp,$app):void{
    $service=$app->bookings();$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
    $valid=[
        'room_id'=>1,'full_name'=>'Direct Resident','phone'=>'0812345678',
        'move_in_date'=>$today,'opening_water_reading'=>'0.00',
        'opening_electric_reading'=>'0.00','idempotency_key'=>'admin-checkin-0001',
    ];
    $throws(fn()=>$service->createAdminResident(1,$valid+['unexpected'=>true]),'UNKNOWN_FIELDS');
    $throws(fn()=>$service->createAdminResident(1,array_replace($valid,['room_id'=>0])),'VALIDATION_ERROR');
    $throws(fn()=>$service->createAdminResident(1,array_replace($valid,['full_name'=>''])),'VALIDATION_ERROR');
    $throws(fn()=>$service->createAdminResident(1,array_replace($valid,['phone'=>'12345'])),'VALIDATION_ERROR');
    $throws(fn()=>$service->createAdminResident(1,array_replace($valid,['email'=>'not-an-email'])),'VALIDATION_ERROR');
    $throwsHttp(fn()=>$service->createAdminResident(1,array_replace($valid,['move_in_date'=>(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d')])),'VALIDATION_ERROR',422);
    foreach(['0001-01-01','1999-12-31']as$tooEarly){
        $throwsHttp(fn()=>$service->createAdminResident(1,array_replace($valid,['move_in_date'=>$tooEarly])),'VALIDATION_ERROR',422);
    }
    $throwsHttp(fn()=>$service->createAdminResident(1,array_replace($valid,['idempotency_key'=>'too-short'])),'VALIDATION_ERROR',422);
    $throws(fn()=>$service->createAdminResident(1,$valid+['reuse_resident_id'=>0]),'VALIDATION_ERROR');

    $moveInDateGuard=new ReflectionMethod($service,'assertMoveInDateAllowed');
    $moveInDateGuard->invoke(null,'2000-01-01');
    foreach(['0001-01-01','1999-12-31']as$tooEarly){
        try{$moveInDateGuard->invoke(null,$tooEarly);throw new RuntimeException('early move-in date was accepted');}
        catch(HttpException $error){
            $same(422,$error->status);$same('VALIDATION_ERROR',$error->errorCode);
            $same('move_in_date',$error->details['field']??null);$same('2000-01-01',$error->details['minimum']??null);
        }
    }

    $reference=new ReflectionMethod($service,'administrativeReference');
    $arguments=['admin-checkin-0001',1,'Direct Resident','0812345678',null,false,$today,null,'0.00','0.00'];
    $base=$reference->invoke($service,...$arguments);$same(1,preg_match('/^ADM-[A-F0-9]{32}$/D',$base));
    $same($base,$reference->invoke($service,...$arguments));
    foreach([
        ['admin-checkin-0002',1,'Direct Resident','0812345678',null,false,$today,null,'0.00','0.00'],
        ['admin-checkin-0001',2,'Direct Resident','0812345678',null,false,$today,null,'0.00','0.00'],
        ['admin-checkin-0001',1,'Another Resident','0812345678',null,false,$today,null,'0.00','0.00'],
        ['admin-checkin-0001',1,'Direct Resident','0899999999',null,false,$today,null,'0.00','0.00'],
        ['admin-checkin-0001',1,'Direct Resident','0812345678',null,true,$today,null,'0.00','0.00'],
        ['admin-checkin-0001',1,'Direct Resident','0812345678','qa@example.com',true,$today,null,'0.00','0.00'],
        ['admin-checkin-0001',1,'Direct Resident','0812345678',null,false,'2026-01-01',null,'0.00','0.00'],
        ['admin-checkin-0001',1,'Direct Resident','0812345678',null,false,$today,99,'0.00','0.00'],
        ['admin-checkin-0001',1,'Direct Resident','0812345678',null,false,$today,null,'1.00','0.00'],
        ['admin-checkin-0001',1,'Direct Resident','0812345678',null,false,$today,null,'0.00','1.00'],
    ]as$changed)$same(true,$base!==$reference->invoke($service,...$changed));
});
$test('direct admin resident check-in locks room first and replays only the canonical committed result',function()use($same):void{
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/BookingService.php');if(!is_string($source))throw new RuntimeException('cannot read BookingService');
    $start=strpos($source,'public function createAdminResident');$end=strpos($source,'public function moveIn(',$start===false?0:$start);
    if($start===false||$end===false)throw new RuntimeException('cannot isolate direct resident service');
    $create=substr($source,$start,$end-$start);
    $same(true,str_contains($create,"'opening_water_reading','opening_electric_reading'"));
    $same(true,str_contains($create,"preg_match('/^[A-Za-z0-9_-]{16,64}$/',\$idempotency)"));
    $same(true,str_contains($create,'$this->app->database()->transaction(function(PDO $pdo)'));

    $roomLock=strpos($create,'SELECT id,monthly_rent,deleted_at FROM rooms WHERE id=? FOR UPDATE');
    $keyRead=strpos($create,'SELECT * FROM bookings WHERE idempotency_key=? LIMIT 1');
    $firstFingerprint=strpos($create,'if(!hash_equals($reference');
    $bookingLock=strpos($create,'SELECT * FROM bookings WHERE id=? FOR UPDATE');
    $secondFingerprint=strpos($create,'if(!hash_equals($reference',$firstFingerprint===false?0:$firstFingerprint+1);
    $same(true,$roomLock!==false&&$keyRead!==false&&$firstFingerprint!==false&&$bookingLock!==false&&$secondFingerprint!==false
        &&$roomLock<$keyRead&&$keyRead<$firstFingerprint&&$firstFingerprint<$bookingLock&&$bookingLock<$secondFingerprint);
    $same(true,substr_count($create,"'IDEMPOTENCY_KEY_REUSED'")>=2);
    $same(true,str_contains($create,'opening_water_reading,opening_electric_reading'));
    $same(true,str_contains($create,"'idempotent_replay'=>true"));
    $same(true,str_contains($create,'$this->moveInRequestHash('));
    $same(true,str_contains($create,'?($emailProvided||$reuseResidentIdProvided)'));
    $same(true,str_contains($create,"'MOVE_IN_ALREADY_COMPLETED'"));

    $occupied=strpos($create,"SELECT id FROM occupancies WHERE room_id=? AND status='active' LIMIT 1 FOR UPDATE");
    $reserved=strpos($create,"SELECT id FROM bookings WHERE room_id=? AND status IN ('pending','confirmed') LIMIT 1");
    $activePhone=strpos($create,'SELECT id FROM bookings WHERE active_phone_norm=? LIMIT 1');
    $insert=strpos($create,'INSERT INTO bookings');
    $confirm=strpos($create,"SET status='confirmed'",$insert===false?0:$insert);
    $moveIn=strpos($create,'$this->moveInWithPolicy');
    $same(true,$occupied!==false&&$reserved!==false&&$activePhone!==false&&$insert!==false&&$confirm!==false&&$moveIn!==false
        &&$roomLock<$occupied&&$occupied<$reserved&&$reserved<$activePhone&&$activePhone<$insert&&$insert<$confirm&&$confirm<$moveIn);
    $same(true,str_contains($create,"VALUES (?,?,?,?,?,'pending',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"));
    $same(true,str_contains($create,"'uq_bookings_one_active_per_phone'"));
    $same(true,str_contains($create,"'uq_bookings_one_active_per_room'"));
    $same(true,str_contains($create,"],true)"));
    $same(true,str_contains($create,"\$result['idempotent_replay']=false"));

    $referenceStart=strpos($source,'private function administrativeReference');$referenceEnd=strpos($source,'private function transition',$referenceStart===false?0:$referenceStart);
    if($referenceStart===false||$referenceEnd===false)throw new RuntimeException('cannot isolate administrative reference');
    $reference=substr($source,$referenceStart,$referenceEnd-$referenceStart);
    foreach(["'email_provided'=>\$emailProvided","'email'=>\$email","'move_in_date'=>\$moveIn","'reuse_resident_id'=>\$reuseResidentId","hash('sha256',\$idempotency.\"\\0\".\$canonical)"]as$field)$same(true,str_contains($reference,$field));
    $same(true,str_contains($source,'return $this->moveInWithPolicy($id,$adminId,$input,false)'));
    $same(2,substr_count($source,'self::assertMoveInDateAllowed($moveIn);'));
    $same(true,str_contains($source,'if(!$allowBeforeBookingDate&&$moveIn<$bookedDate)'));
    $same(true,str_contains($source,'if($reuseResidentId!==$residentId)'));
    $same(true,str_contains($source,"'RESIDENT_REUSE_CONFIRMATION_REQUIRED'"));
    $same(true,str_contains($source,"SELECT id FROM occupancies WHERE resident_id=? AND status='active' LIMIT 1 FOR UPDATE"));
    $roomLockGlobal=strpos($source,'SELECT id,deleted_at FROM rooms WHERE id=? FOR UPDATE');
    $meterConflict=strpos($source,'FROM meter_readings',$roomLockGlobal===false?0:$roomLockGlobal);
    $meterConflictCode=strpos($source,"'MOVE_IN_METER_PERIOD_CONFLICT'",$meterConflict===false?0:$meterConflict);
    $same(true,$roomLockGlobal!==false&&$meterConflict!==false&&$meterConflictCode!==false
        &&$roomLockGlobal<$meterConflict&&$meterConflict<$meterConflictCode);
    $client=file_get_contents(dirname(__DIR__).'/public/assets/js/app.js');
    if(!is_string($client))throw new RuntimeException('cannot read client error mapping');
    $same(true,str_contains($client,'MOVE_IN_METER_PERIOD_CONFLICT:'));
    $same(true,str_contains($client,'MOVE_IN_PERIOD_CONFLICT:'));
    $residentBranch=strpos($source,'try { if ($resident) {');
    $activeResidentGuard=strpos($source,"SELECT id FROM occupancies WHERE resident_id=? AND status='active' LIMIT 1 FOR UPDATE",$residentBranch===false?0:$residentBranch);
    $reuseGuard=strpos($source,'if($reuseResidentId!==$residentId)',$residentBranch===false?0:$residentBranch);
    $same(true,$residentBranch!==false&&$activeResidentGuard!==false&&$reuseGuard!==false&&$activeResidentGuard<$reuseGuard);
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
    $same(true,str_contains($bootstrap,'database has invalid generated uniqueness guard definitions'));
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
    $moveInTransition=strpos($moveInBlock,"SET status='moved_in'");
    $occupancyInsert=strpos($moveInBlock,'INSERT INTO occupancies',$moveInTransition===false?0:$moveInTransition);
    $same(true,$moveInTransition!==false&&$occupancyInsert!==false&&$moveInTransition<$occupancyInsert);
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
$test('resident phones are serialized across active bookings and occupancies',function()use($same):void{
    $root=dirname(__DIR__);
    $booking=file_get_contents($root.'/src/Domain/BookingService.php');
    $resident=file_get_contents($root.'/src/Domain/ResidentService.php');
    if(!is_string($booking)||!is_string($resident))throw new RuntimeException('cannot read resident phone invariant sources');
    $same(true,str_contains($booking,"lockBucket('resident-booking-phone',\$phone)"));
    $same(true,substr_count($booking,'$this->lockPhoneInvariant(')>=5);
    $same(true,substr_count($booking,'$this->assertPhoneHasNoActiveOccupancy(')>=5);
    $same(true,str_contains($booking,"WHERE r.phone_norm=?"));
    $same(true,str_contains($booking,"'RESIDENT_ALREADY_OCCUPIED'"));

    $createStart=strpos($booking,'public function createPublicOutcome');
    $createEnd=strpos($booking,'public function all',$createStart===false?0:$createStart);
    $create=$createStart!==false&&$createEnd!==false?substr($booking,$createStart,$createEnd-$createStart):'';
    $phoneLock=strpos($create,'$this->lockPhoneInvariant($phone)');
    $occupancyGuard=strpos($create,'$this->assertPhoneHasNoActiveOccupancy($pdo,$phone)');
    $bookingGuard=strpos($create,'SELECT id FROM bookings WHERE active_phone_norm=? LIMIT 1');
    $same(true,$phoneLock!==false&&$occupancyGuard!==false&&$bookingGuard!==false&&$phoneLock<$occupancyGuard&&$occupancyGuard<$bookingGuard);

    $requestedLock=strpos($resident,"lockBucket('resident-booking-phone',\$requestedPhone)");
    $residentRowLock=strpos($resident,'SELECT r.full_name,r.phone_norm,r.email,r.line_user_id');
    $activeBooking=strpos($resident,'SELECT id FROM bookings WHERE active_phone_norm=? LIMIT 1 FOR UPDATE');
    $same(true,$requestedLock!==false&&$residentRowLock!==false&&$activeBooking!==false&&$requestedLock<$residentRowLock&&$residentRowLock<$activeBooking);
    $same(true,str_contains($resident,"'BOOKING_PHONE_ACTIVE'"));
});
$test('booking quota CI checks isolate the denied request',function()use($same):void{
    $script=file_get_contents(dirname(__DIR__).'/scripts/ci-booking-edge-tests.sh');
    $initial=file_get_contents(dirname(__DIR__).'/scripts/ci-initial-quota-check.sh');
    $workflow=file_get_contents(dirname(__DIR__).'/.github/workflows/ci.yml');
    if(!is_string($script)||!is_string($initial)||!is_string($workflow))throw new RuntimeException('cannot read booking CI checks');
    $same(true,str_contains($script,"WHERE idempotency_key='ci-phone-rate-000003'"));
    $same(false,str_contains($script,"WHERE r.room_code='CI-RATE-8'"));
    $same(true,str_contains($script,'Unexpected phone quota state:'));
    $same(true,str_contains($script,'ci-active-resident-public-000001'));
    $same(true,str_contains($script,'RESIDENT_ALREADY_OCCUPIED'));
    $same(true,str_contains($script,'ci-admin-phone-held-000001'));
    $same(true,str_contains($script,'BOOKING_PHONE_ACTIVE'));
    $same(true,str_contains($initial,"'13|6|5|2'"));
    $same(true,str_contains($script,"'4|1|1|1'"));
    $same(true,str_contains($workflow,"'4|2|1'"));
});
$test('resident lifecycle blocks unbilled months and same-period re-entry',function()use($same):void{
    $resident=file_get_contents(dirname(__DIR__).'/src/Domain/ResidentService.php');
    $booking=file_get_contents(dirname(__DIR__).'/src/Domain/BookingService.php');
    if(!is_string($resident)||!is_string($booking))throw new RuntimeException('cannot read resident lifecycle sources');
    $same(true,str_contains($resident,"'MOVE_OUT_MISSING_BILLS'"));
    $same(true,str_contains($resident,"'MOVE_OUT_HAS_LATER_METERS'"));
    $same(true,str_contains($resident,'SELECT id,meter_type,period FROM meter_readings WHERE room_id=? AND period>? ORDER BY period,id FOR UPDATE'));
    $same(true,str_contains($resident,"'later_meter_ids'=>array_column(\$laterMeterReadings,'id')"));
    $same(true,str_contains($resident,"'later_meter_periods'=>array_values(array_unique(array_column(\$laterMeterReadings,'period')))"));
    $meterHistoryGuard=strpos($resident,'SELECT id,meter_type,period FROM meter_readings WHERE room_id=? AND period>?');
    $laterBillGuard=strpos($resident,'SELECT id,bill_no,period FROM bills WHERE occupancy_id=? AND period>?');
    $same(true,$meterHistoryGuard!==false&&$laterBillGuard!==false&&$meterHistoryGuard<$laterBillGuard);
    $same(true,str_contains($resident,'self::billingPeriods($firstPeriod,$period)'));
    $same(true,str_contains($resident,'SET active=0,line_user_id=NULL,access_password_hash=NULL'));
    $same(true,str_contains($resident,'activation_consumed_at=NULL,auth_version=auth_version+1'));
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
    $template=file_get_contents(dirname(__DIR__).'/templates/admin/console.php');if(!is_string($template))throw new RuntimeException('cannot read admin template');
    $same(2,substr_count($template,'name="move_in_date" type="date" min="2000-01-01"'));
});
$test('room optional fields and overdue display status are deterministic',function()use($same):void{
    $rooms=file_get_contents(dirname(__DIR__).'/src/Domain/RoomService.php');if(!is_string($rooms))throw new RuntimeException('cannot read RoomService');
    $same(true,str_contains($rooms,"\$data['description'] ??= null"));$same(true,str_contains($rooms,"\$data['image_key'] ??= null"));
    $display=new ReflectionMethod(BillingService::class,'displayStatus');$now=new DateTimeImmutable('2026-07-18T12:00:00Z');
    $same('overdue',$display->invoke(null,'pending','2026-07-17',$now));$same('pending',$display->invoke(null,'pending','2026-07-18',$now));$same('paid',$display->invoke(null,'paid','2026-07-01',$now));
});
$test('exact meter replay is a provenance-preserving no-op',function()use($same):void{
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/MeterService.php');
    if(!is_string($source))throw new RuntimeException('cannot read MeterService');
    $loopStart=strpos($source,'foreach($prepared as$type=>$reading)');
    $loopEnd=strpos($source,"\$result['large_usage_confirmed']",$loopStart===false?0:$loopStart);
    if($loopStart===false||$loopEnd===false)throw new RuntimeException('cannot isolate meter persistence loop');
    $loop=substr($source,$loopStart,$loopEnd-$loopStart);
    $unchanged=strpos($loop,"\$unchanged=\$reading['id']!==null&&\$reading['old_current_scaled']===\$currentScaled");
    $guard=strpos($loop,'if(!$unchanged){');
    $update=strpos($loop,'UPDATE meter_readings SET occupancy_id=?,current_reading=?,units_used=?,recorded_by=?,updated_at=UTC_TIMESTAMP()');
    $same(true,$unchanged!==false&&$guard!==false&&$update!==false&&$unchanged<$guard&&$guard<$update);
    $same(1,substr_count($loop,'UPDATE meter_readings SET'));
    $same(1,preg_match('/if\s*\(!\$unchanged\)\s*\{\s*\$update\s*=\s*\$pdo->prepare\(\'UPDATE meter_readings SET[^\']*recorded_by=\?,updated_at=UTC_TIMESTAMP\(\)[^\']*\'\);/s',$loop));
    $same(true,str_contains($loop,"\$result['unchanged_meter_types'][]=\$type"));
});
$test('move-in replay is bound to a canonical immutable request digest',function()use($same,$app):void{
    $root=dirname(__DIR__);
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/BookingService.php');
    $schema=file_get_contents($root.'/database/schema.sql');
    $migration=file_get_contents($root.'/database/migrations/012_move_in_request_hash.sql');
    if(!is_string($source)||!is_string($schema)||!is_string($migration))throw new RuntimeException('cannot read move-in replay sources');

    $digest=new ReflectionMethod($app->bookings(),'moveInRequestHash');
    $arguments=[41,'BK-MOVE-IN-HASH-0001',true,'original@example.test',false,null,'2026-08-01','10.00','20.00'];
    $base=$digest->invoke($app->bookings(),...$arguments);
    $same(1,preg_match('/^[0-9a-f]{64}$/D',$base));
    $same($base,$digest->invoke($app->bookings(),...$arguments));
    foreach([
        [42,'BK-MOVE-IN-HASH-0001',true,'original@example.test',false,null,'2026-08-01','10.00','20.00'],
        [41,'BK-MOVE-IN-HASH-0002',true,'original@example.test',false,null,'2026-08-01','10.00','20.00'],
        [41,'BK-MOVE-IN-HASH-0001',false,null,false,null,'2026-08-01','10.00','20.00'],
        [41,'BK-MOVE-IN-HASH-0001',true,null,false,null,'2026-08-01','10.00','20.00'],
        [41,'BK-MOVE-IN-HASH-0001',true,'changed@example.test',false,null,'2026-08-01','10.00','20.00'],
        [41,'BK-MOVE-IN-HASH-0001',true,'original@example.test',true,77,'2026-08-01','10.00','20.00'],
        [41,'BK-MOVE-IN-HASH-0001',true,'original@example.test',false,null,'2026-08-02','10.00','20.00'],
        [41,'BK-MOVE-IN-HASH-0001',true,'original@example.test',false,null,'2026-08-01','11.00','20.00'],
        [41,'BK-MOVE-IN-HASH-0001',true,'original@example.test',false,null,'2026-08-01','10.00','21.00'],
    ]as$changed)$same(true,$base!==$digest->invoke($app->bookings(),...$changed));

    $methodStart=strpos($source,'private function moveInWithPolicy(');
    $branchStart=strpos($source,"if(\$booking['status']==='moved_in'){",$methodStart===false?0:$methodStart);
    $branchEnd=strpos($source,"if (\$booking['status'] !== 'confirmed')",$branchStart===false?0:$branchStart);
    if($methodStart===false||$branchStart===false||$branchEnd===false)throw new RuntimeException('cannot isolate move-in replay branch');
    $branch=substr($source,$branchStart,$branchEnd-$branchStart);
    $hashGuard=strpos($branch,'hash_equals($storedRequestHash,$moveInRequestHash)');
    $legacyGuard=strpos($branch,'?($emailProvided||$reuseResidentIdProvided)');
    $conflict=strpos($branch,"'MOVE_IN_ALREADY_COMPLETED'");
    $replay=strpos($branch,"'idempotent_replay'=>true");
    $same(false,str_contains($branch,'JOIN residents'));
    $same(false,str_contains($branch,'resident_email'));
    $same(true,$hashGuard!==false&&$legacyGuard!==false&&$conflict!==false&&$replay!==false
        &&$legacyGuard<$hashGuard&&$hashGuard<$conflict&&$conflict<$replay);
    $same(true,str_contains($source,"move_in_request_hash=?,updated_at=UTC_TIMESTAMP()"));
    foreach([
        "'email_provided'=>\$emailProvided",
        "'reuse_resident_id_provided'=>\$reuseResidentIdProvided",
        "'move_in_date'=>\$moveIn",
        "'opening_water_reading'=>\$openingWater",
        "'opening_electric_reading'=>\$openingElectric",
        '"dormflow:move-in:v1\\0".$canonical',
    ]as$field)$same(true,str_contains($source,$field));

    foreach([
        'move_in_request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
        'CONSTRAINT chk_bookings_move_in_request_hash CHECK',
        "status = 'moved_in'",
        "move_in_request_hash REGEXP '^[0-9a-f]{64}$'",
        'OLD.move_in_request_hash <=> NEW.move_in_request_hash',
    ]as$guard)$same(true,str_contains($schema,$guard));
    foreach([
        'DORMITORY_012_IMPORT_PRIOR_MIGRATIONS_FIRST',
        'DORMITORY_012_INVALID_MOVE_IN_HASH_COLUMN',
        'DORMITORY_012_INVALID_MOVE_IN_HASH_DATA',
        'ALTER TABLE bookings DROP CHECK chk_bookings_move_in_request_hash',
        'DORMITORY_012_MOVE_IN_HASH_POSTCONDITION_FAILED',
        'DROP TRIGGER IF EXISTS trg_bookings_identity_immutable',
        'DORMITORY_012_TRIGGER_POSTCONDITION_FAILED',
    ]as$guard)$same(true,str_contains($migration,$guard));
});
$test('meter and billing dates cannot create irreversible future records',function()use($same,$app):void{
    $timezone=new DateTimeZone((string)$app->config->get('APP_TIMEZONE','Asia/Bangkok'));$today=new DateTimeImmutable('today',$timezone);
    $currentPeriod=$today->format('Y-m');$todayDate=$today->format('Y-m-d');
    $meterGuard=new ReflectionMethod(Dormitory\Domain\MeterService::class,'assertPeriodIsNotFuture');
    $meterGuard->invoke($app->meters(),$currentPeriod);
    try{$meterGuard->invoke($app->meters(),'2100-12');throw new RuntimeException('future meter period was accepted');}
    catch(HttpException $error){$same(422,$error->status);$same('VALIDATION_ERROR',$error->errorCode);$same('period',$error->details['field']??null);$same($currentPeriod,$error->details['maximum']??null);}

    $billingGuard=new ReflectionMethod(BillingService::class,'validatedBillingDates');
    $same([$currentPeriod,$currentPeriod.'-01',$todayDate],$billingGuard->invoke($app->billing(),$currentPeriod,$todayDate));
    try{$billingGuard->invoke($app->billing(),'2100-12','2100-12-31');throw new RuntimeException('future bill period was accepted');}
    catch(HttpException $error){$same(422,$error->status);$same('period',$error->details['field']??null);$same($currentPeriod,$error->details['maximum']??null);}
    $maximumDueDate=$today->modify('+60 days')->format('Y-m-d');
    try{$billingGuard->invoke($app->billing(),$currentPeriod,$today->modify('+61 days')->format('Y-m-d'));throw new RuntimeException('excessive bill due date was accepted');}
    catch(HttpException $error){$same(422,$error->status);$same('due_date',$error->details['field']??null);$same($maximumDueDate,$error->details['maximum']??null);}

    $previousTimezone=getenv('APP_TIMEZONE');
    try{
        putenv('APP_TIMEZONE=Pacific/Kiritimati');
        $boundary=new DateTimeImmutable('2026-01-31T12:00:00Z');
        $meterGuard->invoke($app->meters(),'2026-02',$boundary);
        $same(['2026-02','2026-02-01','2026-02-01'],$billingGuard->invoke($app->billing(),'2026-02','2026-02-01',$boundary));
    }finally{
        if($previousTimezone===false)putenv('APP_TIMEZONE');else putenv('APP_TIMEZONE='.$previousTimezone);
    }

    $template=file_get_contents(dirname(__DIR__).'/templates/admin/console.php');if(!is_string($template))throw new RuntimeException('cannot read admin template');
    $same(true,str_contains($template,'id="meter-period" max="<?= e($maximumBillingPeriod) ?>"'));
    $same(true,str_contains($template,'id="bill-period" max="<?= e($maximumBillingPeriod) ?>"'));
    $same(true,str_contains($template,'<output class="auto-value" id="bill-due-date">'));
    $same(true,str_contains($template,'new DateTimeZone((string) $appTimezone)'));
    $routes=file_get_contents(dirname(__DIR__).'/src/Http/Routes.php');if(!is_string($routes))throw new RuntimeException('cannot read routes');
    $same(true,str_contains($routes,"'appTimezone'=>(string)\$app->config->get('APP_TIMEZONE','Asia/Bangkok')"));
});
$test('LINE bill delivery supports hashed self-service binding codes and verified recipients',function()use($same,$app):void{
    $routes=new ReflectionProperty(Dormitory\Http\Router::class,'routes');$registered=$routes->getValue(Dormitory\Http\Routes::build($app));$paths=[];
    foreach($registered as$route){if(str_contains($route['regex'],'profile/line'))$paths[]=$route['method'].':'.$route['regex'];}
    $same(2,count($paths));
    $resident=file_get_contents(dirname(__DIR__).'/src/Domain/ResidentService.php');$booking=file_get_contents(dirname(__DIR__).'/src/Domain/BookingService.php');$binding=file_get_contents(dirname(__DIR__).'/src/Domain/LineBindingService.php');
    if(!is_string($resident)||!is_string($booking)||!is_string($binding))throw new RuntimeException('cannot read LINE binding sources');
    $same(1,preg_match("/updateProfile.*?Validator::only\\(\\\$input,\\['full_name','email'\\]\\)/s",$resident));
    $same(1,preg_match("/moveIn.*?Validator::only\\(\\\$input,\\[.*?'email','move_in_date','reuse_resident_id'.*?'opening_water_reading','opening_electric_reading'.*?\\]\\)/s",$booking));
    $same(true,str_contains($resident,"Validator::only(\$input,[])"));
    $same(false,str_contains($resident,'current_pin'));
    $same(true,str_contains($binding,'private const CODE_BYTES = 16'));
    $same(true,str_contains($binding,'int $expectedAuthVersion'));
    $same(true,str_contains($binding,"'RESIDENT_SESSION_STALE'"));
    $same(true,str_contains($binding,"private const CODE_PATTERN = '/^BIND-[A-F0-9]{32}$/D'"));
    $same(true,str_contains($binding,'strtoupper(bin2hex(random_bytes(self::CODE_BYTES)))'));
    $same(true,str_contains($binding,"hash_hmac('sha256'"));
    $same(true,str_contains($binding,'line-bind-code\\0'));
    $same(true,str_contains($binding,'SELECT resident_id FROM line_link_codes WHERE code_hash=?'));
    $same(true,str_contains($binding,'public function consumeSerialized'));
    $same(true,str_contains($binding,'withLineBindingLock'));
    $same(false,str_contains($binding,"(string) \$error->getCode() === '23000'"));
    $same(false,str_contains($binding,'INSERT INTO line_link_codes (code,'));
    $notification=file_get_contents(dirname(__DIR__).'/src/Domain/NotificationService.php');if(!is_string($notification))throw new RuntimeException('cannot read NotificationService');
    $same(true,str_contains($notification,'isLineBindingVerified'));
    $same(true,str_contains($notification,"resident.line_link_verified','resident.line_unlinked"));
    $routesSource=file_get_contents(dirname(__DIR__).'/src/Http/Routes.php');if(!is_string($routesSource))throw new RuntimeException('cannot read Routes');
    $same(true,str_contains($routesSource,"'/api/resident/profile/line/code'"));
    $same(true,str_contains($routesSource,"'resident.line_link_code_issued'"));
    $same(true,str_contains($routesSource,"lineBindings()->issue(\$residentId,(int)\$actor['auth_version'])"));
    $same(true,str_contains($resident,'$this->app->lineBindings()->revokePending($id)'));
    $reissueStart=strpos($routesSource,"'/api/admin/residents/{id}/access/reissue'");
    $reissueEnd=strpos($routesSource,"'/api/admin/residents/{id}/move-out'",$reissueStart===false?0:$reissueStart);
    if($reissueStart===false||$reissueEnd===false)throw new RuntimeException('cannot isolate resident access reissue route');
    $reissueRoute=substr($routesSource,$reissueStart,$reissueEnd-$reissueStart);
    $same(true,str_contains($reissueRoute,'$app->notifications()->withLineBindingLock($target'));
    $same(true,str_contains($routesSource,'clearLineLinkChallenge'));
    $same(false,str_contains($routesSource,"'/api/resident/profile/line/start'"));
    $same(false,str_contains($routesSource,"'/api/resident/profile/line/confirm'"));
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
    $same(false,str_contains($source,"line_request_id=NULL,line_accepted_request_id=NULL"));
    $failedStart=strpos($source,"if(\$existing['status']==='failed'){");
    $failedEnd=strpos($source,"}elseif(\$existing['status']==='pending'",$failedStart);
    $failedSource=substr($source,$failedStart,$failedEnd-$failedStart);
    foreach(['retry_key=?','created_at=','payload=?','attempts=0']as$reset)$same(false,str_contains($failedSource,$reset));
    $same(true,str_contains($failedSource,'LINE_RETRY_WINDOW_EXPIRED'));
    $same(true,str_contains($source,'created_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()'));
    $same(true,str_contains($source,'SELECT id,resident_id,line_oa_id,line_binding_id,retry_key,attempts,recipient,payload'));
    $same(true,str_contains($source,'retry_generation_expired'));
});
$test('LINE delivery response classification is fail closed and provider IDs are retained',function()use($same):void{
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/NotificationService.php');
    if(!is_string($source))throw new RuntimeException('cannot read NotificationService');
    $accepted=strpos($source,'if($status>=200&&$status<300)');
    $conflict=strpos($source,'if($status===409)');
    $retryable=strpos($source,'if($status>=500&&$status<=599)throw new LineDeliveryException');
    $terminal=strpos($source,"throw new LineDeliveryException('LINE API rejected the request");
    if($accepted===false||$conflict===false||$retryable===false||$terminal===false
        ||!($accepted<$conflict&&$conflict<$retryable&&$retryable<$terminal)){
        throw new RuntimeException('LINE HTTP outcome order is unsafe');
    }
    $same(true,str_contains($source,'LINE retry conflict did not include an accepted request ID'));
    $same(true,str_contains($source,"['x-line-request-id','x-line-accepted-request-id']"));
    $same(true,str_contains($source,"preg_match('/^[\\x21-\\x7E]+$/D',\$value)===1"));
    $same(true,str_contains($source,"'request_id'=>\$providerHeaders['x-line-request-id']??null"));
    $same(true,str_contains($source,"'accepted_request_id'=>\$providerHeaders['x-line-accepted-request-id']??null"));
    $same(true,str_contains($source,"new LineDeliveryException('LINE network request failed"));
    $same(true,str_contains($source,'in_array($status,[408,429],true)'));
    $same(true,str_contains($source,"SUM(n.status='failed' AND b.status='pending')"));
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
    $same(['event_id','event_type','line_user_id','reply_token','message_text'],array_keys($candidate));
    $same($lineUserId,$candidate['line_user_id']);$same('message',$candidate['event_type']);$same($event['message']['text'],$candidate['message_text']);
    $follow=$event;$follow['type']='follow';unset($follow['message']);$followCandidate=$candidateMethod->invoke($service,$follow);$same('follow',$followCandidate['event_type']);$same(null,$followCandidate['message_text']);
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
    $same(true,str_contains($source,"action IN ('line.webhook_user_id_replied','line.webhook_reply_sent','line.webhook_reply_token_unavailable','line.webhook_reply_suppressed')"));
    $same(true,str_contains($source,"'line_user_id_hash' => \$this->identityHash"));
    $same(true,str_contains($source,'lineBindings()->consumeSerialized'));
    $same(false,str_contains($source,'LINE User ID ของคุณคือ'));
    $replyTextMethod=new ReflectionMethod(LineWebhookService::class,'replyTextForCandidate');
    $instructions=$replyTextMethod->invoke($service,$request,$followCandidate);
    $same('instructions',$instructions['outcome']);$same(false,str_contains($instructions['text'],$lineUserId));
    $malformedCandidate=$candidate;$malformedCandidate['message_text']='BIND-1234';
    $same('invalid_code',$replyTextMethod->invoke($service,$request,$malformedCandidate)['outcome']);

    $identityHash=new ReflectionMethod(LineWebhookService::class,'identityHash');
    $hashed=$identityHash->invoke($service,$lineUserId);
    $same(64,strlen($hashed));$same(1,preg_match('/^[a-f0-9]{64}$/D',$hashed));$same(false,$hashed===$lineUserId);
    $same($hashed,$identityHash->invoke($service,$lineUserId));
    $same(false,$hashed===$identityHash->invoke($service,'Uffffffffffffffffffffffffffffffff'));

    $categoryMethod=new ReflectionMethod(LineWebhookService::class,'replyCategory');
    $bindCandidate=$candidate;$bindCandidate['message_text']='BIND-'.str_repeat('A',32);
    $same('bind',$categoryMethod->invoke(null,$bindCandidate));
    $same('instructions',$categoryMethod->invoke(null,$candidate));
    $same('instructions',$categoryMethod->invoke(null,$malformedCandidate));
});
$test('LINE webhook throttling is hashed, split by purpose, terminal for instructions and retryable for valid BIND codes',function()use($same):void{
    $source=file_get_contents(dirname(__DIR__).'/src/Domain/LineWebhookService.php');
    if(!is_string($source))throw new RuntimeException('cannot read LineWebhookService');
    foreach([
        'line-webhook-instruction-user','line-webhook-instruction-global',
        'line-webhook-bind-user','line-webhook-bind-global',
    ]as$scope)$same(true,str_contains($source,$scope));
    $same(true,str_contains($source,"\$identity = \$this->identityHash(\$candidate['line_user_id'])"));
    $same(true,str_contains($source,"limiter()->hit(\$user[0], \$identity"));
    $same(false,str_contains($source,"limiter()->hit(\$user[0], \$candidate['line_user_id']"));
    $same(true,str_contains($source,"'line.webhook_reply_suppressed'"));
    $same(true,str_contains($source,"'rate_limit_scope' => \$allowance"));

    $handleStart=strpos($source,'public function handle(');
    $handleEnd=strpos($source,'private function assertSignature(',$handleStart===false?0:$handleStart);
    if($handleStart===false||$handleEnd===false)throw new RuntimeException('cannot isolate LINE webhook handler');
    $handle=substr($source,$handleStart,$handleEnd-$handleStart);
    $cap=strpos($handle,'if (!self::canStartReply($deadlineNanoseconds, $replyAttempts))');
    $allowance=strpos($handle,'$allowance = $this->consumeReplyAllowance',$cap===false?0:$cap);
    $bindBranch=strpos($handle,"if (\$category === 'bind')",$allowance===false?0:$allowance);
    $bindDeferred=strpos($handle,"return 'deferred';",$bindBranch===false?0:$bindBranch);
    $suppressedAudit=strpos($handle,"'line.webhook_reply_suppressed'",$bindDeferred===false?0:$bindDeferred);
    $suppressedReturn=strpos($handle,"return 'suppressed';",$suppressedAudit===false?0:$suppressedAudit);
    $providerCall=strpos($handle,'$replyDisposition = $this->replyWithText',$suppressedReturn===false?0:$suppressedReturn);
    $providerDeferred=strpos($handle,"if (\$replyDisposition === 'deferred')",$providerCall===false?0:$providerCall);
    $terminalAudit=strpos($handle,'$this->writeEventAudit(',$providerDeferred===false?0:$providerDeferred);
    $deferCount=strpos($handle,'$deferred++;',$terminalAudit===false?0:$terminalAudit);
    $throw=strpos($handle,"'LINE_WEBHOOK_BATCH_DEFERRED'");
    $return=strrpos($handle,'return $result;');
    $same(true,$cap!==false&&$allowance!==false&&$bindBranch!==false&&$bindDeferred!==false
        &&$suppressedAudit!==false&&$suppressedReturn!==false&&$providerCall!==false&&$providerDeferred!==false
        &&$terminalAudit!==false&&$deferCount!==false&&$throw!==false&&$return!==false
        &&$cap<$allowance&&$allowance<$bindBranch&&$bindBranch<$bindDeferred
        &&$bindDeferred<$suppressedAudit&&$suppressedAudit<$suppressedReturn
        &&$suppressedReturn<$providerCall&&$providerCall<$providerDeferred
        &&$providerDeferred<$terminalAudit&&$terminalAudit<$deferCount&&$deferCount<$throw&&$throw<$return);
    $same(true,str_contains($handle,"elseif (\$disposition === 'deferred')"));
    $same(true,str_contains($handle,"throw new HttpException(\n                503,"));
    $same(true,str_contains($handle,"['deferred_events' => \$deferred]"));
});
$test('LINE webhook outbound work has a monotonic deadline and a two-call budget',function()use($same):void{
    $serviceReflection=new ReflectionClass(LineWebhookService::class);
    $same(2,$serviceReflection->getConstant('MAX_REPLIES'));

    $remaining=new ReflectionMethod(LineWebhookService::class,'remainingMilliseconds');
    $same(1500,$remaining->invoke(null,2_500_000_000,1_000_000_000));
    $same(0,$remaining->invoke(null,1_000_000_000,1_000_000_000));
    $same(0,$remaining->invoke(null,999_000_000,1_000_000_000));

    $canStart=new ReflectionMethod(LineWebhookService::class,'canStartReply');
    $same(true,$canStart->invoke(null,2_500_000_000,0,1_000_000_000));
    $same(false,$canStart->invoke(null,2_500_000_000,2,1_000_000_000));
    $same(false,$canStart->invoke(null,2_249_000_000,0,1_000_000_000));

    $source=file_get_contents(dirname(__DIR__).'/src/Domain/LineWebhookService.php');
    if(!is_string($source))throw new RuntimeException('cannot read LineWebhookService');
    $same(true,str_contains($source,'hrtime(true)'));
    $same(true,str_contains($source,'CURLOPT_CONNECTTIMEOUT_MS'));
    $same(true,str_contains($source,'CURLOPT_TIMEOUT_MS'));
    $same(false,str_contains($source,'CURLOPT_TIMEOUT => 8'));
    $body=strpos($source,'$body = json_encode([');
    $deadlineCheck=strpos($source,'if ($remainingMilliseconds < self::MIN_REPLY_START_MILLISECONDS)',$body===false?0:$body);
    $curlStart=strpos($source,'$ch = curl_init(self::REPLY_ENDPOINT)',$deadlineCheck===false?0:$deadlineCheck);
    $same(true,$body!==false&&$deadlineCheck!==false&&$curlStart!==false&&$body<$deadlineCheck&&$deadlineCheck<$curlStart);
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
    $root=dirname(__DIR__);$js=file_get_contents($root.'/public/assets/js/app.js');$admin=file_get_contents($root.'/templates/admin/console.php');$residentPortal=file_get_contents($root.'/templates/resident/portal.php');$settingsService=file_get_contents($root.'/src/Domain/SystemSettingsService.php');$schema=file_get_contents($root.'/database/schema.sql');$migration=file_get_contents($root.'/database/migrations/004_line_webhook.sql');$bindingMigration=file_get_contents($root.'/database/migrations/010_line_self_service_binding.sql');$friendMigration=file_get_contents($root.'/database/migrations/011_line_add_friend_identity.sql');
    if(!is_string($js)||!is_string($admin)||!is_string($residentPortal)||!is_string($settingsService)||!is_string($schema)||!is_string($migration)||!is_string($bindingMigration)||!is_string($friendMigration))throw new RuntimeException('cannot read LINE UI/schema sources');
    $lineStart=strpos($js,"lineStartForm.addEventListener('submit'");$lineCopy=strpos($js,"lineCodeCopyButton.addEventListener('click'",$lineStart?:0);$lineStartSource=substr($js,(int)$lineStart,(int)$lineCopy-(int)$lineStart);
    $same(true,str_contains($lineStartSource,"'/api/resident/profile/line/code'"));
    $same(true,str_contains($lineStartSource,'/^BIND-[A-F0-9]{32}$/'));
    $same(true,substr_count($js,"raw.replace(' ', 'T')")>=2);
    $same(true,str_contains($js,'navigator.clipboard.writeText(code)'));
    $same(false,str_contains($residentPortal,'name="line_user_id"'));
    $same(false,str_contains($residentPortal,'resident-line-confirm-form'));
    $same(true,str_contains($residentPortal,'id="confirm-dialog"'));
    $same(1,substr_count($js,'async function confirmAction('));
    $same(true,strpos($js,'async function confirmAction(')<strpos($js,'function initResidentPortal()'));
    $same(true,str_contains($js,"sent: 'LINE รับคำขอแล้ว'"));
    $same(true,str_contains($js,"? 'โควตาไม่จำกัด'"));
    $same(true,str_contains($js,"integrations.line_webhook_url"));
    $same(true,str_contains($js,'เปิด Use webhook และ Webhook redelivery แล้วกด Verify'));
    $same(false,str_contains($js,'Webhook พร้อมใช้งาน'));
    $same(true,str_contains($admin,'name="channel_secret" type="password"'));
    $same(false,str_contains($admin,'name="basic_id"'));
    $same(true,str_contains($admin,'id="line-oa-diagnostics"'));
    $same(false,str_contains($admin,'name="line_channel_secret"'));
    $same(false,str_contains($settingsService,'curl_error('));
    foreach(['curlFailureMessage','CURLE_OPERATION_TIMEDOUT','CURLE_SSL_CACERT','ตรวจ CA certificate ของเซิร์ฟเวอร์']as$item)$same(true,str_contains($settingsService,$item));

    $same(1,preg_match('/line_user_id VARCHAR\(33\).*?CHECK\s*\(\s*line_user_id IS NULL OR line_user_id REGEXP \'\^U\[0-9a-f\]\{32\}\$\'\s*\)/s',$schema));
    $same(1,preg_match('/recipient VARCHAR\(33\).*?CHECK\s*\(\s*recipient REGEXP \'\^U\[0-9a-f\]\{32\}\$\'\s*\)/s',$schema));
    foreach(['line_channel_secret_enc','line_request_id','line_accepted_request_id']as$column){$same(true,str_contains($schema,$column));$same(true,str_contains($migration,$column));}
    foreach(['line_link_codes','code_hash','pending_resident_id','uq_line_link_codes_pending_resident']as$item){$same(true,str_contains($schema,$item));$same(true,str_contains($bindingMigration,$item));}
    foreach(['line_basic_id','chk_integration_settings_line_basic_id']as$item){$same(true,str_contains($schema,$item));$same(true,str_contains($friendMigration,$item));}
    $same(false,str_contains($schema,'line_link_codes (code'));
    $same(true,str_contains($migration,'@dormitory_004_invalid_line_ids'));
    $same(true,str_contains($migration,"line_user_id NOT REGEXP '^U[0-9a-f]{32}$'"));
    $same(true,str_contains($migration,"recipient NOT REGEXP '^U[0-9a-f]{32}$'"));

    $routes=new ReflectionProperty(Dormitory\Http\Router::class,'routes');$registered=$routes->getValue(Dormitory\Http\Routes::build($app));
    $webhooks=array_values(array_filter($registered,static fn(array$route):bool=>str_contains($route['regex'],'api/webhooks/line')));
    $same(2,count($webhooks));foreach($webhooks as$webhook){$same('POST',$webhook['method']);$same([],$webhook['options']);}
    $application=file_get_contents($root.'/src/Application.php');if(!is_string($application))throw new RuntimeException('cannot read Application');
    $same(true,str_contains($application,"\$request->method === 'POST' && Request::isLineWebhookPath(\$request->path)"));
    $same(true,Request::isLineWebhookPath('/api/webhooks/line'));
    $same(true,Request::isLineWebhookPath('/api/webhooks/line/oa/'.str_repeat('a',48)));
    $same(false,Request::isLineWebhookPath('/api/webhooks/line/oa/not-a-route-token'));
    $health=file_get_contents($root.'/public/healthz.php');if(!is_string($health))throw new RuntimeException('cannot read health check');
    foreach(['line_channel_secret_enc','line_request_id','line_accepted_request_id']as$column)$same(true,str_contains($health,$column));
});
$test('runtime readiness rejects legacy PIN schemas and incomplete unique guards',function()use($same):void{
    $root=dirname(__DIR__);
    $health=file_get_contents($root.'/public/healthz.php');
    $bootstrap=file_get_contents($root.'/scripts/bootstrap_database.sh');
    $requirements=file_get_contents($root.'/scripts/check_requirements.php');
    if(!is_string($health)||!is_string($bootstrap)||!is_string($requirements))throw new RuntimeException('cannot read schema readiness sources');
    foreach([
        'booked_monthly_rent','active_phone_norm','resident_name_snapshot','room_code_snapshot',
        'verification_lease_until','verification_token','verification_attempts','active_bill_id',
        'line_user_id','recipient',
    ]as$column)$same(true,str_contains($health,"'{$column}'"));
    $same(true,str_contains($health,"table_name='residents' AND column_name='pin_hash'"));
    $same(true,str_contains($health,"fetchColumn() !== 0"));
    $same(true,str_contains($bootstrap,"column_name = 'pin_hash'"));
    $same(true,str_contains($bootstrap,'database still contains retired residents.pin_hash'));
    foreach([
        'uq_bookings_one_active_per_phone','uq_bookings_one_active_per_room',
        'uq_occupancies_one_active_per_room','uq_occupancies_one_active_per_resident',
        'uq_bills_occupancy_period','uq_bill_items_bill_type',
        'uq_payments_slip_hmac','uq_payments_transaction_ref','uq_payments_one_active_per_bill',
    ]as$index){$same(true,str_contains($health,$index));$same(true,str_contains($bootstrap,$index));}
    $same(true,str_contains($health,"\$indexRow['NON_UNIQUE']"));
    $same(true,str_contains($health,"\$indexRow['SUB_PART']"));
    $same(true,str_contains($health,"\$indexRow['SEQ_IN_INDEX']"));
    $same(true,str_contains($bootstrap,"index_name <> 'PRIMARY' AND non_unique = 0"));
    foreach([
        '$invalidMeterOccupancyLinks',
        '$invalidMeterChains',
        '$invalidFinancialRelationships',
        '$activeResidentsWithoutCredential',
        '$overlappingOccupancyMonths',
        '$invalidResidentOccupancyStates',
        'prior_row.period=DATE_SUB(current_row.period,INTERVAL 1 MONTH)',
        'occupancy ของห้องหรือ resident เดียวกันทับรอบเดือน',
        'second_row.resident_id=first_row.resident_id',
        "moved_booking.status='moved_in'",
        'moved_occupancy.id IS NULL',
        'linked_booking.booked_monthly_rent<=>occupancy_row.monthly_rent',
        "CONCAT('bill-payment:',paid_bill.id)",
        "CONCAT('notification:',notification_row.id)",
        'payment_row.amount<=>payment_bill.total_amount',
        'bill_water.occupancy_id=bill_row.occupancy_id',
        'resident/occupancy/room/booking lifecycle state',
        'ความสัมพันธ์ occupancy/bill/items/payment/notification',
        'meter chain ขาดเดือนหรือ previous reading',
        'active residents ไม่มี password หรือ activation key',
    ]as$guard)$same(true,str_contains($requirements,$guard));
    $same(true,str_contains($requirements,"addResult(\$errors,'data readiness ไม่ผ่าน: active residents"));
    foreach([$health,$bootstrap,$requirements]as$currentGate)$same(true,str_contains($currentGate,'chk_occupancies_opening_readings_v2'));
    $same(true,str_contains($health,'PendingOpeningSchema::errors($pdo)'));
    $same(true,str_contains($requirements,'PendingOpeningSchema::missingOpeningCounts($pdo)'));
    $same(true,str_contains($requirements,"\$missingOpeningCounts['invalid']>0"));
    $same(true,str_contains($requirements,"addResult(\$notices,'active occupancies เดิมรอค่าเปิดมิเตอร์น้ำ/ไฟ '"));
    foreach([
        'action_statement',
        'normalizeTriggerAction',
        'expectedTriggerActions',
        'event/timing/body',
        'triggers 23 รายการ',
    ]as$bodyAuditGuard)$same(true,str_contains($requirements,$bodyAuditGuard));
    $same(true,str_contains($bootstrap,'actual_trigger_count" == 23'));
    $same(true,str_contains($bootstrap,'trg_bookings_insert_guard|BEFORE|INSERT|bookings'));
    $same(true,str_contains($bootstrap,'trg_occupancies_relationship_guard|BEFORE|INSERT|occupancies'));
});
$test('runtime readiness validates complete generated uniqueness definitions',function()use($same):void{
    $root=dirname(__DIR__);
    $health=file_get_contents($root.'/public/healthz.php');
    $bootstrap=file_get_contents($root.'/scripts/bootstrap_database.sh');
    $requirements=file_get_contents($root.'/scripts/check_requirements.php');
    if(!is_string($health)||!is_string($bootstrap)||!is_string($requirements))throw new RuntimeException('cannot read generated-column readiness sources');
    foreach(['column_type','is_nullable','extra','generation_expression']as$metadata){
        $same(true,str_contains($health,$metadata));
        $same(true,str_contains($bootstrap,$metadata));
        $same(true,str_contains($requirements,$metadata));
    }
    $definitions=[
        'bookings.active_room_id'=>["'type' => 'bigint unsigned'","casewhenstatusin'pending','confirmed'thenroom_idelsenullend"],
        'bookings.active_phone_norm'=>["'type' => 'char(10)'","casewhenstatusin'pending','confirmed'thenphone_normelsenullend"],
        'occupancies.active_room_id'=>["'type' => 'bigint unsigned'","casewhenstatus='active'thenroom_idelsenullend"],
        'occupancies.active_resident_id'=>["'type' => 'bigint unsigned'","casewhenstatus='active'thenresident_idelsenullend"],
        'payments.active_bill_id'=>["'type' => 'bigint unsigned'","casewhenstatusin'pending','verified'thenbill_idelsenullend"],
    ];
    foreach($definitions as$qualified=>$needles){
        $same(true,str_contains($health,"'{$qualified}'"));
        $same(true,str_contains($bootstrap,$qualified.'|'));
        $same(true,str_contains($requirements,"'{$qualified}'"));
        foreach($needles as$needle){
            $same(true,str_contains($health,$needle));
            $same(true,str_contains($requirements,$needle));
            $bootstrapNeedle=str_replace("'type' => '",'',$needle);
            $bootstrapNeedle=str_replace("'",'', $bootstrapNeedle);
            if(str_starts_with($needle,"'type'"))$same(true,str_contains($bootstrap,'|'.$bootstrapNeedle.'|YES|STORED GENERATED|'));
            else $same(true,str_contains($bootstrap,$needle));
        }
    }
    $same(true,str_contains($health,"\$extra !== 'STORED GENERATED'"));
    $same(true,str_contains($health,"!in_array(\$normalizedExpression, \$expected['expressions'], true)"));
    $same(true,str_contains($bootstrap,'actual_generated_column_definitions'));
    $same(true,str_contains($bootstrap,'database has invalid generated uniqueness guard definitions'));
    $same(true,str_contains($requirements,"\$extra !== 'STORED GENERATED'"));
    $same(true,str_contains($requirements,"!in_array(\$normalizedExpression, \$expected['expressions'], true)"));
    $same(true,str_contains($requirements,'array_diff('));
    $same(true,str_contains($requirements,'schema ขาดหรือมีนิยาม generated uniqueness guards ไม่ถูกต้อง'));
});
$test('database CLI scripts enforce fail-closed TLS identity verification',function()use($same):void{
    $root=dirname(__DIR__);
    $bootstrap=file_get_contents($root.'/scripts/bootstrap_database.sh');
    $provision=file_get_contents($root.'/scripts/provision_runtime_db_user.sh');
    $requirements=file_get_contents($root.'/scripts/check_requirements.php');
    if(!is_string($bootstrap)||!is_string($provision)||!is_string($requirements))throw new RuntimeException('cannot read database CLI scripts');
    foreach([$bootstrap,$provision]as$script){
        $same(true,str_contains($script,'local db_ssl_value="${DB_SSL-false}"'));
        $same(true,str_contains($script,'mysql --no-defaults --help 2>&1'));
        $same(true,str_contains($script,'"$DB_SSL_CA" == /*'));
        $same(true,str_contains($script,'-f "$DB_SSL_CA"'));
        $same(true,str_contains($script,'-r "$DB_SSL_CA"'));
        $same(true,str_contains($script,'--ssl-mode=VERIFY_IDENTITY'));
        $same(true,str_contains($script,'--ssl-verify-server-cert'));
        $same(true,str_contains($script,'refusing to connect'));
    }
    $same(true,str_contains($bootstrap,'mysql_args+=("${mysql_tls_args[@]}")'));
    $same(true,str_contains($provision,'dba_args+=("${mysql_tls_args[@]}")'));
    $same(true,str_contains($provision,'runtime_args+=("${mysql_tls_args[@]}")'));
    $same(true,str_contains($provision,"runtime_account_tls_clause='REQUIRE SSL'"));
    $same(true,str_contains($requirements,"!defined('PDO::MYSQL_ATTR_SSL_CA')"));
    $same(true,str_contains($requirements,"!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')"));
    $constantGuard=strpos($requirements,"!defined('PDO::MYSQL_ATTR_SSL_CA')");
    $pdoConnect=strpos($requirements,'$pdo = new PDO(');
    $same(true,$constantGuard!==false&&$pdoConnect!==false&&$constantGuard<$pdoConnect);
    $createUser=strpos($provision,"CREATE USER '\${DB_USERNAME}'@'%'");
    $identifiedBy=strpos($provision,"IDENTIFIED BY '\${DB_PASSWORD}'",$createUser===false?0:$createUser);
    $requireSsl=strpos($provision,'${runtime_account_tls_clause}',$identifiedBy===false?0:$identifiedBy);
    $passwordExpiry=strpos($provision,'PASSWORD EXPIRE NEVER',$requireSsl===false?0:$requireSsl);
    $accountUnlock=strpos($provision,'ACCOUNT UNLOCK',$passwordExpiry===false?0:$passwordExpiry);
    $same(true,$createUser!==false&&$identifiedBy!==false&&$requireSsl!==false
        &&$passwordExpiry!==false&&$accountUnlock!==false
        &&$createUser<$identifiedBy&&$identifiedBy<$requireSsl
        &&$requireSsl<$passwordExpiry&&$passwordExpiry<$accountUnlock);
});
$test('container runtime command dispatches by fail-closed role',function()use($same):void{
    $root=dirname(__DIR__);
    $script=file_get_contents($root.'/scripts/start-runtime.sh');
    $docker=file_get_contents($root.'/Dockerfile');
    $monthly=file_get_contents($root.'/scripts/run_monthly_billing.sh');
    $provision=file_get_contents($root.'/scripts/provision_runtime_db_user.sh');
    $setup=file_get_contents($root.'/scripts/setup-database.sh');
    $workflow=file_get_contents($root.'/.github/workflows/ci.yml');
    if(!is_string($script)||!is_string($docker)||!is_string($monthly)||!is_string($provision)||!is_string($setup)||!is_string($workflow))throw new RuntimeException('cannot read runtime dispatch sources');
    foreach(['web)','worker)','job)']as$case)$same(true,str_contains($script,$case));
    $same(true,str_contains($script,'role=${RUNTIME_ROLE:-}'));
    $same(false,str_contains($script,'RUNTIME_ROLE:-web'));
    $same(true,str_contains($docker,'CMD ["/var/www/html/scripts/start-runtime.sh"]'));
    $same(true,str_contains($docker,'/var/www/html/scripts/run_monthly_billing.sh'));
    $same(true,str_contains($monthly,'exec gosu www-data:www-data sh "$script_directory/run_monthly_billing.sh" "$@"'));
    $same(true,str_contains($setup,'database setup requires RUNTIME_ROLE=job'));
    $roleGuard=strpos($setup,'RUNTIME_ROLE:-');
    $bootstrapCall=strpos($setup,'bootstrap_database.sh');
    $same(true,$roleGuard!==false&&$bootstrapCall!==false&&$roleGuard<$bootstrapCall);
    $same(true,str_contains($provision,"[[ \"\$readiness\" == '21|1|1' ]]"));
    $same(false,str_contains($provision,"[[ \"\$readiness\" == '15|1|1' ]]"));
    $same(true,str_contains($workflow,"[ \"\$install_shape\" = '21|23|116' ]"));
    $same(false,str_contains($workflow,"[ \"\$install_shape\" = '15|19|80' ]"));
    $checker=file_get_contents(dirname(__DIR__).'/scripts/check_requirements.php');if(!is_string($checker))throw new RuntimeException('cannot read requirement checker');
    $same(true,str_contains($checker,"\$runtimeRole=(string)envValue(\$env,'RUNTIME_ROLE','all')"));
    $same(false,str_contains($checker,'$runtimeRole=strtolower'));
});
$test('public and resident UX keeps mutation, LINE, dialog, and recovery guards',function()use($same):void{
    $root=dirname(__DIR__);
    $js=file_get_contents($root.'/public/assets/js/app.js');
    $public=file_get_contents($root.'/templates/public/home.php');
    $portal=file_get_contents($root.'/templates/resident/portal.php');
    if(!is_string($js)||!is_string($public)||!is_string($portal))throw new RuntimeException('cannot read public/resident UX sources');

    foreach(['id="booking-success-room"','id="booking-success-phone"']as$surface)$same(true,str_contains($public,$surface));
    foreach(['maskBookingPhone','booking-success-room','booking-success-phone']as$behavior)$same(true,str_contains($js,$behavior));
    $bookingStart=strpos($js,"bookingForm.addEventListener('submit'");
    $bookingEnd=strpos($js,'function initLogin(',$bookingStart===false?0:$bookingStart);
    if($bookingStart===false||$bookingEnd===false)throw new RuntimeException('cannot isolate public booking submission');
    $booking=substr($js,$bookingStart,$bookingEnd-$bookingStart);
    $lock=strpos($booking,'setDialogBusy(bookingDialog, true)');
    $request=strpos($booking,"api('/api/public/bookings'");
    $unlock=strpos($booking,'setDialogBusy(bookingDialog, false)');
    $same(true,$lock!==false&&$request!==false&&$unlock!==false&&$lock<$request&&$request<$unlock);
    $same(true,str_contains($booking,'} finally {'));

    $setupStart=strpos($js,'function setupCommonInteractions()');
    $setupEnd=strpos($js,'function initPublicRooms()',$setupStart===false?0:$setupStart);
    if($setupStart===false||$setupEnd===false)throw new RuntimeException('cannot isolate common dialog interactions');
    $setup=substr($js,$setupStart,$setupEnd-$setupStart);
    $same(true,str_contains($js,'function dialogCloseBlocked(dialog, trigger = null)'));
    $same(true,str_contains($js,'function setDialogBusy(dialog, busy)'));
    $same(true,str_contains($setup,'if (dialogCloseBlocked(dialog)) event.preventDefault();'));
    $same(false,str_contains($setup,"if (dialog.querySelector('form')) dialog.addEventListener('cancel'"));

    $same(0,preg_match('/id="resident-line-code-expiry"[^>]*aria-live/',$portal));
    foreach(['lineStartForm.hidden = true','const hasActiveCode = state.lineCodeExpiresAt > Date.now()']as$guard)$same(true,str_contains($js,$guard));
    foreach(['id="resident-bills-empty-title"','id="resident-bills-empty-copy"','id="resident-bill-loading-message"','id="resident-bill-detail-retry"']as$surface)$same(true,str_contains($portal,$surface));
    foreach(['ไม่มีบิลรอชำระ','ยังไม่มีบิลที่ชำระแล้ว','billDetailRetryButton.addEventListener']as$behavior)$same(true,str_contains($js,$behavior));
    $same(1,preg_match('/id="resident-bill-loading"[^>]*role="status"/',$portal));
    $same(true,str_contains($js,"billDetailLoading.setAttribute('role', 'alert')"));
    $same(true,str_contains($js,"canvas.setAttribute('role', 'img')"));
    $same(true,str_contains($js,"message.setAttribute('role', 'alert')"));
    $same(true,str_contains($js,'qrStage.replaceChildren(message)'));
});

$test('public LINE contact exposes only a strictly validated public identity',function()use($same):void{
    $root=dirname(__DIR__);
    $settings=file_get_contents($root.'/src/Domain/SystemSettingsService.php');
    $routes=file_get_contents($root.'/src/Http/Routes.php');
    $js=file_get_contents($root.'/public/assets/js/app.js');
    $public=file_get_contents($root.'/templates/public/home.php');
    $residentLogin=file_get_contents($root.'/templates/resident/login.php');
    if(!is_string($settings)||!is_string($routes)||!is_string($js)||!is_string($public)||!is_string($residentLogin))throw new RuntimeException('cannot read public LINE contact sources');

    $contactStart=strpos($settings,'public function publicContact(): array');
    $contactEnd=$contactStart===false?false:strpos($settings,'/**',$contactStart+strlen('public function publicContact(): array'));
    if($contactStart===false||$contactEnd===false)throw new RuntimeException('cannot isolate public contact method');
    $contact=substr($settings,$contactStart,$contactEnd-$contactStart);
    $same(true,str_contains($contact,'SELECT line_basic_id FROM integration_settings WHERE id=1'));
    $same(false,str_contains($contact,'SELECT *'));
    foreach(['line_channel_access_token','line_channel_secret','promptpay_target','payment_receiver_account_tail','slipok_api_key','easyslip_api_key']as$privateField)$same(false,str_contains($contact,$privateField));
    $same(true,str_contains($contact,'/^@[A-Za-z0-9._-]{1,32}$/D'));
    $same(true,str_contains($contact,"'line_add_friend_url' => 'https://line.me/R/ti/p/' . \$lineBasicId"));
    $same(true,str_contains($routes,"'/api/public/contact'"));
    $same(true,str_contains($routes,'Response::json($app->settings()->publicContact())'));

    $supportStart=strpos($js,'async function loadPublicSupport()');
    $supportEnd=$supportStart===false?false:strpos($js,'function initPublicRooms()',$supportStart);
    if($supportStart===false||$supportEnd===false)throw new RuntimeException('cannot isolate public support loader');
    $support=substr($js,$supportStart,$supportEnd-$supportStart);
    $same(true,str_contains($support,'/^https:\/\/line\.me\/R\/ti\/p\/(?:@|%40)[A-Za-z0-9._-]{1,32}$/'));
    $same(true,str_contains($support,'if (!url) return;'));
    $same(true,str_contains($support,'link.href = url'));
    foreach([$public,$residentLogin]as$template){
        $attribute=strpos($template,'data-public-support-line');
        $open=$attribute===false?false:strrpos(substr($template,0,$attribute),'<a');
        $close=$attribute===false?false:strpos($template,'>',$attribute);
        if($attribute===false||$open===false||$close===false)throw new RuntimeException('cannot isolate public support link');
        $link=substr($template,$open,$close-$open+1);
        foreach(['target="_blank"','rel="noopener noreferrer"','hidden']as$guard)$same(true,str_contains($link,$guard));
    }
});

$test('resident payment UI locks slip mutation and rejects stale verifying refreshes',function()use($same):void{
    $js=file_get_contents(dirname(__DIR__).'/public/assets/js/app.js');
    if(!is_string($js))throw new RuntimeException('cannot read resident payment UI source');

    $slipStart=strpos($js,"$('#resident-slip-form').addEventListener('submit'");
    $slipEnd=$slipStart===false?false:strpos($js,'const initialHash = location.hash',$slipStart);
    if($slipStart===false||$slipEnd===false)throw new RuntimeException('cannot isolate slip submission');
    $slip=substr($js,$slipStart,$slipEnd-$slipStart);
    $slipLock=strpos($slip,'setDialogBusy(billDialog, true)');
    $slipRequest=strpos($slip,'/slip`');
    $slipUnlock=strpos($slip,'finally { setBusy(button, false); setDialogBusy(billDialog, false); }');
    $same(true,$slipLock!==false&&$slipRequest!==false&&$slipUnlock!==false&&$slipLock<$slipRequest&&$slipRequest<$slipUnlock);

    $pollStart=strpos($js,'async function refreshVerifyingBills(force = false)');
    $pollEnd=$pollStart===false?false:strpos($js,'function appendBreakdown(',$pollStart);
    if($pollStart===false||$pollEnd===false)throw new RuntimeException('cannot isolate verifying bill refresh');
    $poll=substr($js,$pollStart,$pollEnd-$pollStart);
    $generationCapture=strpos($poll,'const loadGeneration = state.loadRequest');
    $pollRequest=strpos($poll,"api('/api/resident/bills')");
    $generationGuard=strpos($poll,'if (loadGeneration !== state.loadRequest) return;');
    $unchangedGuard=strpos($poll,'if (JSON.stringify(nextBills) === JSON.stringify(state.bills)) return;');
    $billAssignment=strpos($poll,'state.bills = nextBills;');
    $billRender=strpos($poll,'renderBills();');
    $currentBillGuard=strpos($poll,'String(state.currentBillId) === String(currentId)');
    $same(true,$generationCapture!==false&&$pollRequest!==false&&$generationGuard!==false&&$unchangedGuard!==false&&$billAssignment!==false&&$billRender!==false&&$currentBillGuard!==false
        &&$generationCapture<$pollRequest&&$pollRequest<$generationGuard&&$generationGuard<$unchangedGuard&&$unchangedGuard<$billAssignment&&$billAssignment<$billRender&&$billRender<$currentBillGuard);
    $same(true,str_contains($poll,'finally { state.billRefreshRequest = false; }'));
    $same(true,str_contains($js,"if (doc.visibilityState === 'visible') refreshVerifyingBills(true);"));
});

$test('activation passwords survive retryable failure and unsent access secrets block navigation',function()use($same):void{
    $js=file_get_contents(dirname(__DIR__).'/public/assets/js/app.js');
    if(!is_string($js))throw new RuntimeException('cannot read activation UX source');

    $loginStart=strpos($js,'function initResidentLogin()');
    $loginEnd=$loginStart===false?false:strpos($js,'function initResidentPortal()',$loginStart);
    if($loginStart===false||$loginEnd===false)throw new RuntimeException('cannot isolate resident login');
    $login=substr($js,$loginStart,$loginEnd-$loginStart);
    $successFlag=strpos($login,'let loginSucceeded = false;');
    $loginRequest=strpos($login,"api('/api/auth/resident/login'");
    $successSet=strpos($login,'loginSucceeded = true;');
    $finally=strpos($login,'} finally {');
    $same(true,$successFlag!==false&&$loginRequest!==false&&$successSet!==false&&$finally!==false&&$successFlag<$loginRequest&&$loginRequest<$successSet&&$successSet<$finally);
    $finallyEnd=$finally===false?false:strpos($login,'resetSecretVisibility();',$finally);
    if($finally===false||$finallyEnd===false)throw new RuntimeException('cannot isolate resident login cleanup');
    $cleanup=substr($login,$finally,$finallyEnd-$finally);
    $retainGuard=strpos($cleanup,'if (!activating || loginSucceeded)');
    $newPasswordClear=strpos($cleanup,"form.elements.new_password.value = '';");
    $confirmationClear=strpos($cleanup,"form.elements.new_password_confirm.value = '';");
    $same(true,$retainGuard!==false&&$newPasswordClear!==false&&$confirmationClear!==false&&$retainGuard<$newPasswordClear&&$newPasswordClear<$confirmationClear);

    $same(true,str_contains($js,"let residentActivationSecret = '';"));
    $same(true,str_contains($js,'residentActivationSecret = code;'));
    $beforeStart=strpos($js,"window.addEventListener('beforeunload'");
    $beforeEnd=$beforeStart===false?false:strpos($js,'integrationSettingsForm?.addEventListener',$beforeStart);
    if($beforeStart===false||$beforeEnd===false)throw new RuntimeException('cannot isolate admin beforeunload guard');
    $beforeUnload=substr($js,$beforeStart,$beforeEnd-$beforeStart);
    $same(true,str_contains($beforeUnload,'if (adminLogoutInProgress || (!residentActivationSecret && !hasDirtySettings() && !hasDirtyMeterRows() && !hasDirtyDialogDrafts())) return;'));
    $same(true,str_contains($beforeUnload,"event.preventDefault(); event.returnValue = '';"));
});

$test('resident LINE polling merges only LINE state without overwriting profile edits',function()use($same):void{
    $js=file_get_contents(dirname(__DIR__).'/public/assets/js/app.js');
    if(!is_string($js))throw new RuntimeException('cannot read resident LINE polling source');

    $pollStart=strpos($js,'async function refreshLineStatus(silent = false)');
    $pollEnd=$pollStart===false?false:strpos($js,'function replaceResidentHash(',$pollStart);
    if($pollStart===false||$pollEnd===false)throw new RuntimeException('cannot isolate resident LINE status polling');
    $poll=substr($js,$pollStart,$pollEnd-$pollStart);

    $revisionCapture=strpos($poll,'const profileRevision = state.profileRevision;');
    $request=strpos($poll,"api('/api/resident/profile')");
    $revisionGuard=strpos($poll,'if (profileRevision !== state.profileRevision) return false;');
    $merge=strpos($poll,'state.profile = {');
    $render=strpos($poll,'renderLineStatus();');
    $same(true,$revisionCapture!==false&&$request!==false&&$revisionGuard!==false&&$merge!==false&&$render!==false
        &&$revisionCapture<$request&&$request<$revisionGuard&&$revisionGuard<$merge&&$merge<$render);
    foreach([
        'line_verified: latestProfile.line_verified',
        'line_user_id_hint: latestProfile.line_user_id_hint',
        'line_add_friend_url: latestProfile.line_add_friend_url',
    ]as$lineField)$same(true,str_contains($poll,$lineField));
    foreach([
        'fillProfile();',
        'state.profile = objectFrom',
        'latestProfile.full_name',
        'latestProfile.email',
        'latestProfile.phone',
        'latestProfile.room_code',
    ]as$profileOverwrite)$same(false,str_contains($poll,$profileOverwrite));
});

$test('resident and admin logout use shared in-flight locks and recover after failure',function()use($same):void{
    $js=file_get_contents(dirname(__DIR__).'/public/assets/js/app.js');
    if(!is_string($js))throw new RuntimeException('cannot read logout UX source');

    $residentStart=strpos($js,"const residentLogoutButtons = \$\$('[data-resident-logout]');");
    $residentEnd=$residentStart===false?false:strpos($js,"profileForm.addEventListener('submit'",$residentStart);
    if($residentStart===false||$residentEnd===false)throw new RuntimeException('cannot isolate resident logout handler');
    $resident=substr($js,$residentStart,$residentEnd-$residentStart);
    $residentGuard=strpos($resident,'if (residentLogoutInProgress) return;');
    $residentLock=strpos($resident,'residentLogoutInProgress = true;');
    $residentDisable=strpos($resident,"residentLogoutButtons.forEach((item) => { item.disabled = true; item.setAttribute('aria-busy', 'true'); });");
    $residentRequest=strpos($resident,"api('/api/auth/resident/logout'");
    $residentRedirect=strpos($resident,"location.assign('/resident/login');");
    $residentUnlock=strpos($resident,'residentLogoutInProgress = false;',$residentRequest===false?0:$residentRequest);
    $residentEnable=strpos($resident,"residentLogoutButtons.forEach((item) => { item.disabled = false; item.removeAttribute('aria-busy'); });");
    $same(true,$residentGuard!==false&&$residentLock!==false&&$residentDisable!==false&&$residentRequest!==false&&$residentRedirect!==false&&$residentUnlock!==false&&$residentEnable!==false
        &&$residentGuard<$residentLock&&$residentLock<$residentDisable&&$residentDisable<$residentRequest&&$residentRequest<$residentRedirect&&$residentRedirect<$residentUnlock&&$residentUnlock<$residentEnable);
    $same(false,str_contains($resident,'finally { location.assign'));

    $adminStart=strpos($js,"const adminLogoutButtons = \$\$('[data-admin-logout]');");
    $adminEnd=$adminStart===false?false:strpos($js,'const initialHash = location.hash',$adminStart);
    if($adminStart===false||$adminEnd===false)throw new RuntimeException('cannot isolate admin logout handler');
    $admin=substr($js,$adminStart,$adminEnd-$adminStart);
    $adminGuard=strpos($admin,'if (adminLogoutInProgress) return;');
    $adminLock=strpos($admin,'adminLogoutInProgress = true;');
    $activationGuard=strpos($admin,'const hasUncopiedAccess = Boolean(residentActivationSecret);');
    $confirm=strpos($admin,'&& !await confirmAction(');
    $cancelUnlock=strpos($admin,'adminLogoutInProgress = false;',$confirm===false?0:$confirm);
    $adminDisable=strpos($admin,"adminLogoutButtons.forEach((item) => { item.disabled = true; item.setAttribute('aria-busy', 'true'); });");
    $adminRequest=strpos($admin,"api('/api/auth/admin/logout'");
    $adminRedirect=strpos($admin,"location.assign('/admin/login');");
    $failureUnlock=strpos($admin,'adminLogoutInProgress = false;',$adminRequest===false?0:$adminRequest);
    $adminEnable=strpos($admin,"adminLogoutButtons.forEach((item) => { item.disabled = false; item.removeAttribute('aria-busy'); });");
    $same(true,$adminGuard!==false&&$adminLock!==false&&$activationGuard!==false&&$confirm!==false&&$cancelUnlock!==false&&$adminDisable!==false&&$adminRequest!==false&&$adminRedirect!==false&&$failureUnlock!==false&&$adminEnable!==false
        &&$adminGuard<$adminLock&&$adminLock<$activationGuard&&$activationGuard<$confirm&&$confirm<$cancelUnlock&&$cancelUnlock<$adminDisable&&$adminDisable<$adminRequest&&$adminRequest<$adminRedirect&&$adminRedirect<$failureUnlock&&$failureUnlock<$adminEnable);
    foreach([
        "hasUncopiedAccess ? 'ยังมี activation code แสดงอยู่'",
        "hasUncopiedAccess ? 'ยืนยันว่าได้ส่งมอบแล้ว'",
        'รหัสเปิดใช้งานจะแสดงได้ครั้งเดียวและจะถูกล้างเมื่อออกจากระบบ',
    ]as$activationWarning)$same(true,str_contains($admin,$activationWarning));
    $same(false,str_contains($admin,'finally { location.assign'));
});

$test('settings saves fence stale loads and block navigation until completion',function()use($same):void{
    $js=file_get_contents(dirname(__DIR__).'/public/assets/js/app.js');
    if(!is_string($js))throw new RuntimeException('cannot read settings concurrency source');
    foreach(['let settingsSaveInProgress = false;','let settingsLoadGeneration = 0;']as$stateGuard)$same(true,str_contains($js,$stateGuard));

    $switchStart=strpos($js,'function switchView(name, force = false, updateHash = true)');
    $switchEnd=$switchStart===false?false:strpos($js,'function roomMatches(',$switchStart);
    if($switchStart===false||$switchEnd===false)throw new RuntimeException('cannot isolate admin view switch');
    $switch=substr($js,$switchStart,$switchEnd-$switchStart);
    $navigationStart=strpos($switch,"if (activeView === 'settings' && name !== 'settings' && settingsSaveInProgress)");
    $dirtyGuard=strpos($switch,"if (activeView === 'settings' && name !== 'settings' && hasDirtySettings())");
    if($navigationStart===false||$dirtyGuard===false)throw new RuntimeException('cannot isolate settings navigation lock');
    $navigationGuard=substr($switch,$navigationStart,$dirtyGuard-$navigationStart);
    $same(true,str_contains($navigationGuard,'กำลังบันทึกการตั้งค่า กรุณารอให้เสร็จก่อนเปลี่ยนหน้า'));
    $same(true,str_contains($navigationGuard,'return false;'));

    $loadStart=strpos($js,'async function loadSettings()');
    $loadEnd=$loadStart===false?false:strpos($js,'function applyBillingReadiness()',$loadStart);
    if($loadStart===false||$loadEnd===false)throw new RuntimeException('cannot isolate settings loader');
    $load=substr($js,$loadStart,$loadEnd-$loadStart);
    $generationCapture=strpos($load,'const generation = ++settingsLoadGeneration;');
    $loadRequest=strpos($load,"api('/api/admin/settings')");
    $generationGuard=strpos($load,'if (generation !== settingsLoadGeneration) return;');
    $settingsAssignment=strpos($load,"state.settings = objectFrom(data, 'settings');");
    $same(true,$generationCapture!==false&&$loadRequest!==false&&$generationGuard!==false&&$settingsAssignment!==false
        &&$generationCapture<$loadRequest&&$loadRequest<$generationGuard&&$generationGuard<$settingsAssignment);
    $same(2,substr_count($load,'if (generation !== settingsLoadGeneration) return;'));
    $same(true,str_contains($load,"if (integrationSettingsForm?.dataset.dirty !== 'true') renderIntegrationSettings"));

    $billingStart=strpos($js,"billingSettingsForm?.addEventListener('submit'");
    $billingEnd=$billingStart===false?false:strpos($js,"integrationSettingsForm?.addEventListener('input'",$billingStart);
    if($billingStart===false||$billingEnd===false)throw new RuntimeException('cannot isolate billing settings save');
    $billing=substr($js,$billingStart,$billingEnd-$billingStart);
    $billingGuard=strpos($billing,'if (settingsSaveInProgress || !form.reportValidity()) return;');
    $billingLock=strpos($billing,'settingsSaveInProgress = true;');
    $billingRequest=strpos($billing,"api('/api/admin/settings'");
    $billingFence=strpos($billing,'++settingsLoadGeneration;');
    $billingUnlock=strpos($billing,'finally { settingsSaveInProgress = false;');
    $same(true,$billingGuard!==false&&$billingLock!==false&&$billingRequest!==false&&$billingFence!==false&&$billingUnlock!==false
        &&$billingGuard<$billingLock&&$billingLock<$billingRequest&&$billingRequest<$billingFence&&$billingFence<$billingUnlock);

    $integrationStart=strpos($js,"integrationSettingsForm?.addEventListener('submit'");
    $integrationEnd=$integrationStart===false?false:strpos($js,"\$\$('[data-test-integration]')",$integrationStart);
    if($integrationStart===false||$integrationEnd===false)throw new RuntimeException('cannot isolate integration settings save');
    $integration=substr($js,$integrationStart,$integrationEnd-$integrationStart);
    $integrationGuard=strpos($integration,'if (settingsSaveInProgress || !form.reportValidity()) return;');
    $integrationLock=strpos($integration,'settingsSaveInProgress = true;');
    $integrationRequest=strpos($integration,"api('/api/admin/settings/integrations'");
    $integrationFence=strpos($integration,'++settingsLoadGeneration;');
    $integrationUnlock=strpos($integration,'finally { settingsSaveInProgress = false;');
    $same(true,$integrationGuard!==false&&$integrationLock!==false&&$integrationRequest!==false&&$integrationFence!==false&&$integrationUnlock!==false
        &&$integrationGuard<$integrationLock&&$integrationLock<$integrationRequest&&$integrationRequest<$integrationFence&&$integrationFence<$integrationUnlock);
});

$test('bill preview invalidates an older token before starting a replacement request',function()use($same):void{
    $js=file_get_contents(dirname(__DIR__).'/public/assets/js/app.js');
    if(!is_string($js))throw new RuntimeException('cannot read bill preview source');
    $previewStart=strpos($js,"$('#preview-bills-button').addEventListener('click'");
    $previewEnd=$previewStart===false?false:strpos($js,"$('#bill-builder-form').addEventListener('submit'",$previewStart);
    if($previewStart===false||$previewEnd===false)throw new RuntimeException('cannot isolate bill preview handler');
    $preview=substr($js,$previewStart,$previewEnd-$previewStart);
    $validation=strpos($preview,"if (!$('#bill-builder-form').reportValidity() || !payload.room_ids.length)");
    $invalidate=strpos($preview,'invalidateBillPreview();');
    $busy=strpos($preview,"setBusy(button, true, 'กำลังคำนวณ…');");
    $request=strpos($preview,"api('/api/admin/bills/preview'");
    $same(true,$validation!==false&&$invalidate!==false&&$busy!==false&&$request!==false
        &&$validation<$invalidate&&$invalidate<$busy&&$busy<$request);
    $same(1,substr_count($preview,'invalidateBillPreview();'));
});

$test('admin bill status and LINE queues honor the deterministic latest payment',function()use($same):void{
    $root=dirname(__DIR__);
    $billing=file_get_contents($root.'/src/Domain/BillingService.php');
    $notifications=file_get_contents($root.'/src/Domain/NotificationService.php');
    $js=file_get_contents($root.'/public/assets/js/app.js');
    if(!is_string($billing)||!is_string($notifications)||!is_string($js))throw new RuntimeException('cannot read admin payment-aware LINE sources');

    $adminListStart=strpos($billing,'public function adminList(?string $period): array');
    $adminListEnd=$adminListStart===false?false:strpos($billing,'public function residentList(',$adminListStart);
    if($adminListStart===false||$adminListEnd===false)throw new RuntimeException('cannot isolate admin bill list');
    $adminList=substr($billing,$adminListStart,$adminListEnd-$adminListStart);
    $paymentSelect=strpos($adminList,'(SELECT p.status');
    $paymentScope=strpos($adminList,'WHERE p.bill_id=b.id');
    $paymentOrder=strpos($adminList,'ORDER BY p.id DESC');
    $paymentAlias=strpos($adminList,'LIMIT 1) AS payment_status');
    $same(true,$paymentSelect!==false&&$paymentScope!==false&&$paymentOrder!==false&&$paymentAlias!==false
        &&$paymentSelect<$paymentScope&&$paymentScope<$paymentOrder&&$paymentOrder<$paymentAlias);

    $enqueueStart=strpos($notifications,'public function enqueueBill(int $billId): array');
    $enqueueEnd=$enqueueStart===false?false:strpos($notifications,'public function enqueuePeriod(',$enqueueStart);
    if($enqueueStart===false||$enqueueEnd===false)throw new RuntimeException('cannot isolate single-bill LINE enqueue');
    $enqueue=substr($notifications,$enqueueStart,$enqueueEnd-$enqueueStart);
    $billLock=strpos($enqueue,'WHERE b.id=? FOR UPDATE');
    $latestPaymentLock=strpos($enqueue,'SELECT status FROM payments WHERE bill_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $pendingGuard=strpos($enqueue,"if(\$paymentStatus==='pending')");
    $verifiedGuard=strpos($enqueue,"if(\$paymentStatus==='verified')");
    $lineConfiguration=strpos($enqueue,'lineOfficialAccounts()->credentials((int)$target[\'oa_id\'])');
    $outboxWrite=strpos($enqueue,'INSERT INTO notification_outbox');
    $same(true,$billLock!==false&&$latestPaymentLock!==false&&$pendingGuard!==false&&$verifiedGuard!==false&&$lineConfiguration!==false&&$outboxWrite!==false
        &&$billLock<$latestPaymentLock&&$latestPaymentLock<$pendingGuard&&$pendingGuard<$verifiedGuard&&$verifiedGuard<$lineConfiguration&&$lineConfiguration<$outboxWrite);
    foreach(['BILL_PAYMENT_PENDING','BILL_PAYMENT_VERIFIED',"['payment_status'=>\$paymentStatus]"]as$contract)$same(true,str_contains($enqueue,$contract));

    $periodStart=strpos($notifications,'public function enqueuePeriod(array $input): array');
    $periodEnd=$periodStart===false?false:strpos($notifications,'public function process(',$periodStart);
    if($periodStart===false||$periodEnd===false)throw new RuntimeException('cannot isolate bulk LINE enqueue');
    $period=substr($notifications,$periodStart,$periodEnd-$periodStart);
    $same(true,str_contains($period,'$item=$this->enqueueBill((int)$id);'));
    $same(true,str_contains($period,"\$skipped[]=['bill_id'=>(int)\$id,'code'=>\$e->errorCode,'message'=>\$e->getMessage()];"));

    $same(true,str_contains($notifications,"\$id=(int)\$row['id'];\$billId=(int)\$row['bill_id'];"));
    $same(true,str_contains($notifications,'return $this->deliverClaimed($id,$billId,$claimToken);'));
    $deliveryStart=strpos($notifications,'private function deliverClaimed(int $id,int $billId,string $claimToken): array');
    $deliveryEnd=$deliveryStart===false?false:strpos($notifications,'private function refreshClaim(',$deliveryStart);
    if($deliveryStart===false||$deliveryEnd===false)throw new RuntimeException('cannot isolate claimed LINE delivery');
    $delivery=substr($notifications,$deliveryStart,$deliveryEnd-$deliveryStart);
    $deliveryTransaction=strpos($delivery,'$this->app->database()->transaction');
    $deliveryBillLock=strpos($delivery,'SELECT id,status,resident_id FROM bills WHERE id=? FOR UPDATE');
    $deliveryPaymentLock=strpos($delivery,'SELECT status FROM payments WHERE bill_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $deliveryOutboxLock=strpos($delivery,"WHERE id=? AND bill_id=? AND status='processing' AND claim_token=?");
    $deliveryPendingGuard=strpos($delivery,"if(\$paymentStatus==='pending')");
    $deliveryVerifiedGuard=strpos($delivery,"if(\$paymentStatus==='verified')");
    $deliveryPush=strpos($delivery,'$this->pushLine(');
    $same(true,$deliveryTransaction!==false&&$deliveryBillLock!==false&&$deliveryPaymentLock!==false&&$deliveryOutboxLock!==false
        &&$deliveryPendingGuard!==false&&$deliveryVerifiedGuard!==false&&$deliveryPush!==false
        &&$deliveryTransaction<$deliveryBillLock&&$deliveryBillLock<$deliveryPaymentLock&&$deliveryPaymentLock<$deliveryOutboxLock
        &&$deliveryOutboxLock<$deliveryPendingGuard&&$deliveryPendingGuard<$deliveryVerifiedGuard&&$deliveryVerifiedGuard<$deliveryPush);
    foreach([
        'LINE delivery cancelled: payment slip is pending review',
        'LINE delivery cancelled: payment slip is already verified',
        'AND lease_until>UTC_TIMESTAMP(6)',
    ]as$deliveryContract)$same(true,str_contains($delivery,$deliveryContract));

    $renderStart=strpos($js,'function renderAdminBills()');
    $renderEnd=$renderStart===false?false:strpos($js,'async function loadBills()',$renderStart);
    if($renderStart===false||$renderEnd===false)throw new RuntimeException('cannot isolate admin bill rendering');
    $render=substr($js,$renderStart,$renderEnd-$renderStart);
    $paymentState=strpos($render,"const paymentStatus = String(bill.payment_status || '').toLowerCase();");
    $paymentLock=strpos($render,"const paymentLocksLine = paymentStatus === 'pending' || paymentStatus === 'verified';");
    $mayQueue=strpos($render,"bill.status === 'pending' && !paymentLocksLine");
    $pendingDisplay=strpos($render,"paymentStatus === 'pending' ? 'verifying'");
    $verifiedDisplay=strpos($render,"paymentStatus === 'verified' ? 'paid'");
    $same(true,$paymentState!==false&&$paymentLock!==false&&$mayQueue!==false&&$pendingDisplay!==false&&$verifiedDisplay!==false
        &&$paymentState<$paymentLock&&$paymentLock<$mayQueue&&$mayQueue<$pendingDisplay&&$pendingDisplay<$verifiedDisplay);
    foreach(['กำลังตรวจสลิป ไม่ส่งแจ้งชำระซ้ำ',"displayStatus === 'verifying' ? 'กำลังตรวจสลิป'","displayStatus === 'paid' ? 'ชำระแล้ว'"]as$displayContract)$same(true,str_contains($render,$displayContract));
});

$test('pending or verified slips suppress irrelevant payment configuration warnings',function()use($same):void{
    $js=file_get_contents(dirname(__DIR__).'/public/assets/js/app.js');
    if(!is_string($js))throw new RuntimeException('cannot read resident payment notice source');
    $noticeStart=strpos($js,'const paymentConfigurationReady = promptPayReady && slipReady;');
    $noticeEnd=$noticeStart===false?false:strpos($js,"const breakdown = \$('#resident-bill-breakdown');",$noticeStart);
    if($noticeStart===false||$noticeEnd===false)throw new RuntimeException('cannot isolate resident payment notice');
    $notice=substr($js,$noticeStart,$noticeEnd-$noticeStart);
    foreach([
        "const paymentInProgress = payment?.status === 'pending' || payment?.status === 'verified';",
        'const paymentBlocked = !paymentConfigurationReady && !paymentInProgress;',
        "if (paymentBlocked) {",
        'สลิปอยู่ระหว่างตรวจสอบ กรุณารอผลและอย่าโอนซ้ำ',
        'สลิปผ่านการตรวจสอบแล้ว ไม่ต้องชำระซ้ำ',
        "paymentBlocked ? 'alert' : 'status'",
    ]as$guard)$same(true,str_contains($notice,$guard));
    $same(false,str_contains($notice,'if (!paymentConfigurationReady) {'));
});

$test('admin console opens on an overview that surfaces pending work and worker health',function()use($same):void{
    $root=dirname(__DIR__);
    $admin=file_get_contents($root.'/templates/admin/console.php');
    $js=file_get_contents($root.'/public/assets/js/app.js');
    $payments=file_get_contents($root.'/src/Domain/PaymentService.php');
    $routes=file_get_contents($root.'/src/Http/Routes.php');
    foreach(compact('admin','js','payments','routes')as$name=>$source){
        if(!is_string($source))throw new RuntimeException("cannot read {$name} overview source");
    }

    // The landing view is the overview, and every other view starts hidden so a
    // failed script load cannot reveal several stacked sections at once.
    $same(true,str_contains($admin,'<section class="admin-view is-active" data-admin-view="overview" aria-labelledby="overview-title">'));
    $same(true,str_contains($admin,'<section class="admin-view" data-admin-view="rooms" aria-labelledby="rooms-title" hidden>'));
    $same(1,preg_match_all('/class="admin-view is-active"/',$admin));
    $same(11,preg_match_all('/data-admin-view="/',$admin));
    foreach(['line-oas','line-bindings']as$view)$same(true,str_contains($admin,'class="admin-view" data-admin-view="'.$view.'"'));

    // Work that costs money must be visible without opening the view first.
    foreach(['id="booking-nav-count"','id="payment-nav-count"','id="booking-bottom-count"','id="payment-bottom-count"']as$badge)$same(true,str_contains($admin,$badge));
    $same(true,str_contains($js,"renderCountBadge(['#payment-nav-count', '#payment-bottom-count'], state.paymentPendingCount);"));
    $same(true,str_contains($js,"api('/api/admin/payments?status=pending&offset=0&limit=1')"));
    $same(true,str_contains($payments,"SELECT COUNT(*) FROM payments WHERE status='pending'"));
    $same(true,str_contains($payments,"'pending_count'=>\$pendingCount"));

    // The overview is the home view, so its hash stays empty and bookmarks of
    // the other views keep working.
    $same(true,str_contains($js,"const homeView = 'overview';"));
    $same(true,str_contains($js,'const hash = name === homeView ? \'\' : `#${name}`;'));
    $same(0,preg_match('/name === \'rooms\' \? \'\' :/',$js));

    // Worker health has no other surface; only an owner may read it.
    $same(true,str_contains($routes,"'notifications'=>\$app->notifications()->workerHealth(),"));
    $overviewStart=strpos($js,'async function loadOverview() {');
    $overviewEnd=$overviewStart===false?false:strpos($js,'loaders.overview = loadOverview;',$overviewStart);
    if($overviewStart===false||$overviewEnd===false)throw new RuntimeException('cannot isolate admin overview loader');
    $overview=substr($js,$overviewStart,$overviewEnd-$overviewStart);
    $same(true,str_contains($overview,"if (role === 'owner') requests.push(api('/api/admin/operations/health', options));"));
    $same(1,substr_count($overview,"api('/api/admin/operations/health'"));
    $same(true,str_contains($overview,'results = await Promise.allSettled(requests);'));
    $same(true,str_contains($overview,'if (controller.signal.aborted) return;'));
    $same(true,str_contains($admin,'id="overview-health-card"'));
    $same(true,str_contains($admin,'<?php if ($canManageIntegrations): ?>'));

    // A half-entered room still needs attention, so progress counts both meters.
    $same(true,str_contains($js,"const meterIsComplete = (meter) => !meterHasPendingOpening(meter) && ['water', 'electric'].every((type) => {"));
    $same(true,str_contains($admin,'id="meter-progress"'));
});

$test('trigger local variables pin their collation instead of inheriting the database default',function()use($same):void{
    $root=dirname(__DIR__);
    // A stored-program variable declared without CHARACTER SET takes the DATABASE
    // default collation, not the collation of the tables it is compared against.
    // install.sql creates the database as utf8mb4_unicode_ci, but a host-managed
    // database (Railway) or a container using MYSQL_DATABASE gets the server
    // default utf8mb4_0900_ai_ci, and the <=> against bill_items.description then
    // fails with error 1267 for every bill item insert.
    foreach([
        'database/schema.sql',
        'database/install.sql',
        'database/migrations/013_trigger_collation_pinning.sql',
    ]as$file){
        $sql=file_get_contents($root.'/'.$file);
        if(!is_string($sql))throw new RuntimeException("cannot read {$file}");
        $same(0,preg_match_all('/DECLARE\s+[a-z_]+\s+(?:VARCHAR|CHAR|TEXT|ENUM)\s*(?:\([^)]*\))?\s+DEFAULT\b/i',$sql));
        $same(3,preg_match_all('/DECLARE\s+[a-z_]+\s+VARCHAR\(\d+\) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT/',$sql));
    }

    // scripts/check_requirements.php compares each stored body against schema.sql
    // after stripping whitespace only. MySQL drops "--" comments when it stores a
    // trigger, so a comment inside BEGIN...END makes the canonical audit fail;
    // explanations belong above CREATE TRIGGER instead.
    $bodyPattern='/CREATE\s+TRIGGER\s+([a-z0-9_]+)\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+[a-z0-9_]+\s+FOR\s+EACH\s+ROW\s+(BEGIN.*?\bEND)\s*\$\$/isu';
    foreach(['database/schema.sql','database/install.sql']as$file){
        $sql=file_get_contents($root.'/'.$file);
        if(!is_string($sql))throw new RuntimeException("cannot read {$file}");
        $same(23,preg_match_all($bodyPattern,$sql,$bodies,PREG_SET_ORDER));
        foreach($bodies as $trigger)$same(false,str_contains($trigger[2],'--'));
    }

    $migration=file_get_contents($root.'/database/migrations/013_trigger_collation_pinning.sql');
    if(!is_string($migration))throw new RuntimeException('cannot read migration 013');
    // The migration only recreates guards; it must not touch tables or data.
    $same(0,preg_match('/\b(ALTER TABLE|CREATE TABLE|DROP TABLE|INSERT INTO|DELETE FROM)\b/i',$migration));
    foreach([
        'trg_occupancies_relationship_guard',
        'trg_bill_items_insert_guard',
        'trg_payments_relationship_guard',
    ]as$trigger){
        $same(1,preg_match_all('/^DROP TRIGGER IF EXISTS '.$trigger.';$/m',$migration));
        $same(1,preg_match_all('/^CREATE TRIGGER '.$trigger.'$/m',$migration));
    }
    $same(true,str_contains($migration,'DORMITORY_013_IMPORT_PRIOR_MIGRATIONS_FIRST'));
    $same(true,str_contains($migration,'DORMITORY_013_TRIGGER_POSTCONDITION_FAILED'));

    // The recreated bodies must stay byte-identical to the fresh schema, or an
    // upgraded database would enforce different rules than a new one.
    $schema=file_get_contents($root.'/database/schema.sql');
    if(!is_string($schema))throw new RuntimeException('cannot read schema');
    $body=static function(string $sql,string $trigger):string{
        $start=strpos($sql,'CREATE TRIGGER '.$trigger."\n");
        if($start===false)throw new RuntimeException("missing {$trigger}");
        $end=strpos($sql,'END$$',$start);
        if($end===false)throw new RuntimeException("unterminated {$trigger}");
        return substr($sql,$start,$end-$start+5);
    };
    foreach([
        'trg_occupancies_relationship_guard',
        'trg_bill_items_insert_guard',
        'trg_payments_relationship_guard',
    ]as$trigger)$same($body($schema,$trigger),$body($migration,$trigger));
});

$test('LINE account links use the official encoded OA chat with the exact one-time code',function()use($same):void{
    $code='BIND-'.str_repeat('A',32);
    $links=LineBindingService::officialAccountLinks('@dorm.flow_1-2',$code);
    $same('https://line.me/R/ti/p/%40dorm.flow_1-2',$links['line_add_friend_url']);
    $same('https://line.me/R/oaMessage/%40dorm.flow_1-2/?'.$code,$links['line_message_url']);
    $same(null,LineBindingService::officialAccountLinks('@dorm.flow_1-2')['line_message_url']);
    foreach(['https://example.test/@dorm','@dorm/../other','@dorm?message=secret','@dorm#fragment',"@dorm\n",[],null]as$invalid){
        $same(['line_add_friend_url'=>null,'line_message_url'=>null],LineBindingService::officialAccountLinks($invalid,$code));
    }
    foreach(['BIND-'.str_repeat('a',32),$code.'&extra=1','prefix '.$code,$code."\n"]as$invalidCode){
        $same(null,LineBindingService::officialAccountLinks('@dorm',$invalidCode)['line_message_url']);
    }
});

$test('admin LINE status, issuance and unlink routes require admin access',function()use($same,$app):void{
    $router=Routes::build($app);
    $registered=(new ReflectionProperty(Router::class,'routes'))->getValue($router);
    foreach([
        ['GET','/api/admin/residents/17/line'],
        ['POST','/api/admin/residents/17/line/code'],
        ['POST','/api/admin/residents/17/line/unlink'],
    ]as[$method,$path]){
        $matches=array_values(array_filter($registered,static fn(array$route):bool=>$route['method']===$method&&preg_match($route['regex'],$path)===1));
        $same(1,count($matches));
        $same('admin',$matches[0]['options']['auth']??null);
        $same(false,array_key_exists('role',$matches[0]['options']));
    }
});

$test('bill delivery aggregation never reports partial delivery as completely sent',function()use($same):void{
    $summary=new ReflectionMethod(BillingService::class,'summarizeLineDeliveries');
    $empty=$summary->invoke(null,[]);$same(null,$empty['line_status']);$same(0,$empty['line_delivery_counts']['total']);
    $sent=['status'=>'sent','attempts'=>1,'sent_at'=>'2026-09-15 10:00:00','line_request_id'=>'one-request','line_accepted_request_id'=>'accepted'];
    $single=$summary->invoke(null,[$sent]);$same('sent',$single['line_status']);$same('accepted',$single['line_accepted_request_id']);
    $mixed=$summary->invoke(null,[$sent,['status'=>'failed','attempts'=>3,'last_error'=>'test failure'],['status'=>'pending','attempts'=>0]]);
    $same('failed',$mixed['line_status']);$same(['total'=>3,'pending'=>1,'processing'=>0,'sent'=>1,'failed'=>1],$mixed['line_delivery_counts']);
    $same('test failure',$mixed['line_last_error']);$same(null,$mixed['line_accepted_request_id']);$same(null,$mixed['line_sent_at']);
    $processing=$summary->invoke(null,[$sent,['status'=>'processing'],['status'=>'pending']]);$same('processing',$processing['line_status']);
    $pending=$summary->invoke(null,[$sent,['status'=>'pending']]);$same('pending',$pending['line_status']);
});
$test('LINE binding lock rejects an unbounded wait before touching MySQL',function()use($app):void{
    foreach([-1,13,PHP_INT_MAX]as$seconds){
        try{$app->notifications()->withLineBindingLock(1,static fn()=>null,$seconds);}
        catch(InvalidArgumentException){continue;}
        throw new RuntimeException('Invalid LINE lock timeout was accepted');
    }
});

require __DIR__.'/line_setup_unit.php';
require __DIR__.'/external_api_unit.php';
fwrite(STDOUT,"\n{$passed} passed, {$failed} failed".PHP_EOL);exit($failed===0?0:1);
