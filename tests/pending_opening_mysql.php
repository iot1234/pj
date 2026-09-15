<?php
declare(strict_types=1);

use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Http\Routes;
use Dormitory\Security\Password;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'
    ||preg_match('/^appj_pending_test_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE'))!==1){
    fwrite(STDERR,"A dedicated fresh pending-opening test database is required\n");exit(64);
}
ob_start();
$app=require dirname(__DIR__).'/bootstrap.php';
$pdo=$app->database()->pdo();
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$expect=static function(callable $action,string $code)use($assert):void{
    try{$action();}catch(HttpException $e){$assert($e->errorCode===$code,"Expected {$code}, received {$e->errorCode}");return;}
    throw new RuntimeException("Expected {$code}");
};
foreach(['admin_users','residents','rooms','occupancies','bills','meter_readings']as$table){
    $assert((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()===0,'Test schema must be unused');
}
$ownerUser=(string)getenv('PENDING_SCHEMA_USERNAME');$ownerPassword=(string)getenv('PENDING_SCHEMA_PASSWORD');
$assert($ownerUser!==''&&$ownerPassword!=='','Separate schema-owner credentials are required');
$host=Dormitory\Config::validatedDbHost($app->config->require('DB_HOST'));
$port=$app->config->intInRange('DB_PORT',3306,1,65535);$db=$app->config->require('DB_DATABASE');
$schema=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$ownerUser,$ownerPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$insert=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active) VALUES('pending_owner',?,'owner',1,1)");
$insert->execute([Password::hash('Pending-Test-Password-2026!')]);$owner=(int)$pdo->lastInsertId();
$app->billing()->updateSettings(['water_rate'=>'10.00','electric_rate'=>'5.00','due_days'=>7],$owner);
$today=new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok'));
$moveIn=$today->format('Y-m-d');$legacyMoveIn=$today->modify('first day of previous month')->format('Y-m-d');$period=substr($legacyMoveIn,0,7);
$dueDate=$today->modify('+7 days')->format('Y-m-d');
$created=[];
foreach([1,2]as$number){
    $room=$app->rooms()->create(['room_code'=>'PENDING-'.$number,'floor'=>1,'room_type'=>'Test','monthly_rent'=>'3000.00']);
    $created[]=$app->bookings()->createAdminResident($owner,['room_id'=>$room['id'],'full_name'=>'Pending Test '.$number,
        'phone'=>'081999100'.$number,'move_in_date'=>$moveIn,'opening_water_reading'=>'10.00','opening_electric_reading'=>'20.00',
        'idempotency_key'=>'pending-opening-test-'.$number]);
}
$occupancy=(int)$created[0]['occupancy_id'];$room=(int)$created[0]['room_id'];
// Reconstruct pre-migration data only inside this fresh isolated test schema.
$trigger=$schema->query('SHOW CREATE TRIGGER trg_occupancies_identity_immutable')->fetch();
$assert(is_string($trigger['SQL Original Statement']??null),'Cannot preserve the canonical test trigger');
$schema->exec('DROP TRIGGER trg_occupancies_identity_immutable');
try{$schema->exec("UPDATE occupancies SET opening_water_reading=NULL,opening_electric_reading=NULL,move_in_date='{$legacyMoveIn}' WHERE id={$occupancy}");}
finally{$schema->exec($trigger['SQL Original Statement']);}
$residents=$app->residents()->list();
$pending=array_values(array_filter($residents,static fn(array $r):bool=>$r['occupancy_id']===$occupancy))[0];
$assert($pending['opening_readings_pending']===true&&$pending['opening_water_reading']===null,'Legacy missing readings were replaced or hidden');
$counts=Dormitory\Support\PendingOpeningSchema::missingOpeningCounts($pdo);
$assert($counts['pending']===1&&$counts['invalid']===0,'Safe legacy pending state was not recognized');
foreach([$period,$today->format('Y-m')]as$meterPeriod){
    $row=array_values(array_filter($app->meters()->list($meterPeriod),static fn(array $r):bool=>$r['room_id']===$room))[0];
    $assert($row['opening_readings_pending']===true&&$row['water_locked']&&$row['electric_locked'],'Pending meters must stay locked in every occupancy month');
    $expect(fn()=>$app->meters()->record(['room_id'=>$room,'period'=>$meterPeriod,'water_current'=>'50.00'],$owner),'METER_OPENING_REQUIRED');
}
$billInput=['room_ids'=>[$room],'period'=>$period,'due_date'=>$dueDate];
$preview=$app->billing()->preview($billInput);
$assert($preview['bills']===[]&&$preview['issues'][0]['code']==='METER_OPENING_REQUIRED','Pending room was not excluded from billing');
$expect(fn()=>$app->billing()->bulk($billInput+['preview_token'=>$preview['preview_token']],$owner),'BILL_PREVIEW_INVALID');
$expect(fn()=>$app->meters()->setOpeningReadings($occupancy,['opening_water_reading'=>'0.00']),'VALIDATION_ERROR');
$expect(fn()=>$app->meters()->setOpeningReadings($occupancy,['opening_water_reading'=>'-1','opening_electric_reading'=>'2']),'VALIDATION_ERROR');
$expect(fn()=>$app->meters()->setOpeningReadings($occupancy,['opening_water_reading'=>'10000000','opening_electric_reading'=>'2']),'METER_TOO_HIGH');
$expect(fn()=>$app->meters()->setOpeningReadings($occupancy,['opening_water_reading'=>'1','opening_electric_reading'=>'2','monthly_rent'=>'0']),'UNKNOWN_FIELDS');
$router=Routes::build($app);
$request=static fn(array $body,array $headers=[]):Request=>new Request('POST',"/api/admin/occupancies/{$occupancy}/opening-readings",
    $headers,[],$body,[],['REMOTE_ADDR'=>'127.0.0.51'],'pending-'.bin2hex(random_bytes(4)));
$values=['opening_water_reading'=>'100.50','opening_electric_reading'=>'200.25'];
$assert($router->dispatch($request($values))->status===401,'Anonymous repair was allowed');
$app->session()->login(['type'=>'admin','id'=>$owner,'username'=>'pending_owner','name'=>'pending_owner','role'=>'owner','auth_version'=>1]);
$_COOKIE[session_name()]=session_id();$app->clearActorCache();
$headers=['origin'=>$app->config->require('APP_URL'),'x-csrf-token'=>$app->session()->csrfToken()];
$assert($router->dispatch($request($values,['origin'=>$headers['origin']]))->status===403,'Repair ignored CSRF');
$schema->exec("CREATE TRIGGER trg_pending_test_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW
    BEGIN IF CAST(NEW.action AS BINARY)=CAST('occupancy.opening_readings_set' AS BINARY)
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pending opening test audit failure'; END IF; END");
try{
    $assert($router->dispatch($request($values,$headers))->status===500,'Audit failure did not stop the repair');
    $assert($pdo->query("SELECT opening_water_reading FROM occupancies WHERE id={$occupancy}")->fetchColumn()===null,'Audit failure committed the opening readings');
}finally{$schema->exec('DROP TRIGGER trg_pending_test_audit_failure');}
$response=$router->dispatch($request($values,$headers));
$assert($response->status===200,'Admin opening repair failed: '.$response->body);
$result=json_decode($response->body,true,512,JSON_THROW_ON_ERROR)['data'];
$assert($result['opening_readings_pending']===false&&!$result['idempotent_replay'],'Successful repair result is incorrect');
$counts=Dormitory\Support\PendingOpeningSchema::missingOpeningCounts($pdo);
$assert($counts['pending']===0&&$counts['invalid']===0,'Filled opening pair still appears pending');
$response=$router->dispatch($request($values,$headers));
$assert($response->status===200&&json_decode($response->body,true)['data']['idempotent_replay'],'Same request was not idempotent');
$assert((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='occupancy.opening_readings_set'")->fetchColumn()===1,'Repair duplicated or omitted its audit event');
$expect(fn()=>$app->meters()->setOpeningReadings($occupancy,['opening_water_reading'=>'999','opening_electric_reading'=>'999']),'METER_OPENING_LOCKED');
$app->meters()->record(['room_id'=>$room,'period'=>$period,'water_current'=>'110.50','electric_current'=>'220.25'],$owner);
$preview=$app->billing()->preview($billInput);
$assert($preview['issues']===[]&&$preview['bills'][0]['water_units']==='10.00'&&$preview['bills'][0]['electric_units']==='20.00','Repaired room uses the wrong opening readings');
$bill=$app->billing()->bulk($billInput+['preview_token'=>$preview['preview_token']],$owner);
$assert(count($bill['created'])===1,'Repaired room cannot be billed');
$assert($app->meters()->setOpeningReadings($occupancy,$values)['idempotent_replay'],'Harmless retry after billing should return the original values');
$expect(fn()=>$app->meters()->setOpeningReadings($occupancy,['opening_water_reading'=>'0','opening_electric_reading'=>'0']),'METER_OPENING_LOCKED');
$otherRoom=(int)$created[1]['room_id'];
$app->meters()->record(['room_id'=>$otherRoom,'period'=>$today->format('Y-m'),'water_current'=>'12.00','electric_current'=>'25.00'],$owner);
$assert($app->billing()->preview(['room_ids'=>[$otherRoom],'period'=>$today->format('Y-m'),'due_date'=>$dueDate,'confirm_current_period'=>true])['issues']===[],'Pending room repair broke another room');
$app->session()->release();ob_end_clean();
fwrite(STDOUT,"PASS pending states, billing/meter isolation, validation, admin/CSRF, audit rollback, idempotency and correct billing after one-time repair\n");
