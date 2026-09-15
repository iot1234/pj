<?php
declare(strict_types=1);

use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\Password;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$database=(string)getenv('DB_DATABASE');
if(getenv('APP_ENV')!=='testing'||preg_match('/^(?:appj_|dormitory_test)[A-Za-z0-9_]*$/D',$database)!==1){
    fwrite(STDERR,"Refusing LINE integration outside a dedicated testing database\n");exit(64);
}
/** @var Dormitory\Application $app */
$app=require dirname(__DIR__).'/bootstrap.php';
$pdo=$app->database()->pdo();
foreach(['admin_users','residents','rooms','bookings','occupancies','bills','payments']as$table){
    if((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()!==0){
        fwrite(STDERR,"LINE integration requires a fresh testing database\n");exit(64);
    }
}
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$expect=static function(callable $callback,string $code)use($assert):void{
    try{$callback();}catch(HttpException $error){$assert($error->errorCode===$code,"Unexpected LINE error: {$error->errorCode}");return;}
    throw new RuntimeException("Expected LINE error: {$code}");
};
$request=new Request('POST','/tests/line-binding',[],[],[],[],['REMOTE_ADDR'=>'127.0.0.20'],'line-binding-integration');
$owner=$pdo->prepare("INSERT INTO admin_users (username,password_hash,role,auth_version,active) VALUES ('line_owner',?,'owner',1,1)");
$owner->execute([Password::hash(bin2hex(random_bytes(24)).'Aa1!')]);
$ownerId=(int)$pdo->lastInsertId();
$actor=['type'=>'admin','id'=>$ownerId,'role'=>'owner'];
$app->settings()->update([
    'line_basic_id'=>'@dormflowtest',
    'line_channel_access_token'=>'integration-line-token-visible-ascii-2026',
    'line_channel_secret'=>'integration-line-secret-visible-ascii-2026',
],$ownerId);
$room=$app->rooms()->create(['room_code'=>'LINE-101','floor'=>1,'room_type'=>'LINE integration','monthly_rent'=>'4500.00']);
$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
$checkIn=$app->bookings()->createAdminResident($ownerId,[
    'room_id'=>$room['id'],'full_name'=>'LINE Integration Resident','phone'=>'0811112222',
    'move_in_date'=>$today,'opening_water_reading'=>'100.00','opening_electric_reading'=>'200.00',
    'idempotency_key'=>'line-integration-checkin-0001',
]);
$residentId=(int)$checkIn['resident_id'];
$lineUserId='U0123456789abcdef0123456789abcdef';
$latestCode=static function()use($pdo,$residentId):array{
    $statement=$pdo->prepare('SELECT * FROM line_link_codes WHERE resident_id=? ORDER BY id DESC LIMIT 1');
    $statement->execute([$residentId]);return $statement->fetch();
};
$issue=static function()use($app,$residentId,$request,$actor):array{
    return $app->notifications()->withLineBindingLock($residentId,function()use($app,$residentId,$request,$actor):array{
        return $app->database()->transaction(function()use($app,$residentId,$request,$actor):array{
            $data=$app->lineBindings()->issueForAdmin($residentId);
            $app->audit()->writeStrict($request,$actor,'resident.line_link_code_issued','resident',$residentId,['expires_at'=>$data['expires_at'],'single_use'=>true,'issued_by_admin'=>true]);
            return $data;
        });
    });
};
$first=$issue();
$stored=$latestCode();
$assert($stored['status']==='pending'&&strlen($stored['code_hash'])===64,'Admin issue did not persist a pending digest');
$assert(!str_contains(json_encode($stored,JSON_THROW_ON_ERROR),$first['code']),'Raw LINE code was stored');
$assert($first['line_message_url']==='https://line.me/R/oaMessage/%40dormflowtest/?'.$first['code'],'Admin issue chat URL is incorrect');
$status=$app->residents()->lineStatus($residentId);
$assert($status['resident_id']===$residentId&&$status['line_binding_ready']===true&&$status['line_verified']===false,'Unbound administrative status is incorrect');
$assert($status['pending_expires_at']===$first['expires_at'],'Pending expiry is absent from status');
$assert(!array_key_exists('line_user_id',$status)&&!str_contains(json_encode($status,JSON_THROW_ON_ERROR),'BIND-'),'Administrative status exposed a LINE credential');

// Strict audit failure must roll back both a new code and revocation of its predecessor.
try{
    $app->notifications()->withLineBindingLock($residentId,function()use($app,$residentId):void{
        $app->database()->transaction(function()use($app,$residentId):void{
            $app->lineBindings()->issueForAdmin($residentId);
            throw new RuntimeException('Simulated issuance audit failure');
        });
    });
    throw new RuntimeException('Issuance failure was not propagated');
}catch(RuntimeException $error){$assert($error->getMessage()==='Simulated issuance audit failure','Unexpected issuance failure');}
$assert($latestCode()['id']===$stored['id']&&$latestCode()['status']==='pending','Failed issue replaced the previous code');
$second=$issue();
$expect(fn()=>$app->lineBindings()->consume($first['code'],$lineUserId),'LINE_LINK_CODE_INVALID');

// A missing audit proof must not leave a linked resident or a spent code.
try{
    $app->lineBindings()->consumeSerialized($second['code'],$lineUserId,static function():void{throw new RuntimeException('Simulated binding proof failure');});
    throw new RuntimeException('Binding failure was not propagated');
}catch(RuntimeException $error){$assert($error->getMessage()==='Simulated binding proof failure','Unexpected binding failure');}
$assert($latestCode()['status']==='pending','Proof failure consumed the code');
$identity=$pdo->prepare('SELECT line_user_id FROM residents WHERE id=?');$identity->execute([$residentId]);
$assert($identity->fetchColumn()===null,'Proof failure left the resident linked');
$proof=static function(array $bound)use($app,$request):void{
    if($app->notifications()->isLineBindingVerified((int)$bound['resident_id'],$bound['line_user_id']))return;
    $app->audit()->writeStrict($request,null,'resident.line_link_verified','resident',$bound['resident_id'],[
        'method'=>'self_service_code','line_user_id_hint'=>'•••'.substr($bound['line_user_id'],-6),
        'line_user_id_hash'=>$app->notifications()->lineBindingHash((int)$bound['resident_id'],$bound['line_user_id']),
    ]);
};
$bound=$app->lineBindings()->consumeSerialized($second['code'],$lineUserId,$proof,0);
$assert($bound['newly_bound']===true,'Valid LINE code could not bind after proof rollback');
$linked=$app->residents()->lineStatus($residentId);
$assert($linked['line_verified']===true&&$linked['pending_expires_at']===null&&$linked['line_user_id_hint']==='•••abcdef','Linked status is incorrect');
$replay=$app->lineBindings()->consumeSerialized($second['code'],$lineUserId,$proof);
$assert($replay['newly_bound']===false,'Bound-code replay is not idempotent');
$expect(fn()=>$app->lineBindings()->consume($second['code'],'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),'LINE_LINK_CODE_INVALID');
$expect(fn()=>$issue(),'LINE_ALREADY_LINKED');

$app->notifications()->withLineBindingLock($residentId,function()use($app,$residentId,$request,$actor):void{
    $app->database()->transaction(function()use($app,$residentId,$request,$actor):void{
        $app->residents()->unlinkLine($residentId,[]);$app->lineBindings()->revokePending($residentId);
        $app->audit()->writeStrict($request,$actor,'resident.line_unlinked','resident',$residentId,['reason'=>'admin_unlink']);
    });
});
$assert($app->residents()->lineStatus($residentId)['line_verified']===false,'Admin unlink left a verified binding');
$expect(fn()=>$app->lineBindings()->consumeSerialized($second['code'],$lineUserId,$proof),'LINE_LINK_CODE_INVALID');
$expired=$issue();$expiresRow=$latestCode();
$expire=$pdo->prepare("UPDATE line_link_codes SET expires_at=DATE_ADD(created_at,INTERVAL 1 MICROSECOND) WHERE id=?");
$expire->execute([$expiresRow['id']]);
$expect(fn()=>$app->lineBindings()->consumeSerialized($expired['code'],$lineUserId,$proof),'LINE_LINK_CODE_EXPIRED');
$assert($latestCode()['status']==='expired','Serialized expiry did not commit its status');
$beforeReissue=$issue();
$app->notifications()->withLineBindingLock($residentId,fn()=>$app->residents()->reissueAccess($residentId));
$expect(fn()=>$app->lineBindings()->consume($beforeReissue['code'],$lineUserId),'LINE_LINK_CODE_INVALID');
$afterReissue=$issue();
$assert($afterReissue['code']!==$beforeReissue['code'],'Admin issue did not use the new credential version');
foreach($pdo->query('SELECT details FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN)as$auditText){
    $assert(!str_contains((string)$auditText,'BIND-')&&!str_contains((string)$auditText,'oaMessage')&&!str_contains((string)$auditText,$lineUserId),'LINE code, prefilled URL or raw LINE ID leaked to audit');
}
fwrite(STDOUT,"PASS LINE binding MySQL: admin issue/status/unlink, code replacement, atomic proof, replay, expiry and credential rotation\n");
