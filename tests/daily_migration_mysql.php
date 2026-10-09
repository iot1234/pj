<?php
declare(strict_types=1);

use Dormitory\Application;
use Dormitory\Config;
use Dormitory\Database;
use Dormitory\Domain\PaymentService;
use Dormitory\Support\DailyBookingSchema;

// Schema-owner fixture only. Credentials come solely from the runner's environment.
$database=(string)getenv('DB_DATABASE');
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('DAILY_MIGRATION_TEST')!=='1'
    ||preg_match('/^appj_daily_migration_[a-z0-9_]+$/D',$database)!==1){fwrite(STDERR,"Refusing daily migration test outside an opted-in disposable schema\n");exit(64);}
$pdo=new PDO('mysql:host='.(getenv('DB_HOST')?:'127.0.0.1').';port='.(getenv('DB_PORT')?:'3306').';dbname='.$database.';charset=utf8mb4',
    (string)getenv('DB_USERNAME'),(string)getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$assert((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'Daily migration fixture requires an empty schema');
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{if(!str_starts_with($class,'Dormitory\\'))return;$path=$root.'/src/'.str_replace('\\','/',substr($class,10)).'.php';if(is_file($path))require_once $path;});
if(!function_exists('e')){function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
date_default_timezone_set('UTC');
$read=static function(string $path):string{$value=file_get_contents($path);if(!is_string($value)||$value==='')throw new RuntimeException('Cannot read repository SQL');return$value;};
$runSql=static function(string $source)use($pdo):void{
    $delimiter=';';$buffer='';
    foreach(preg_split('/\R/',$source)as$line){
        if(preg_match('/^\s*--/',$line)||trim($line)==='')continue;
        if(preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i',$line,$match)){if(trim($buffer)!=='')throw new RuntimeException('Unfinished repository SQL');$delimiter=$match[1];continue;}
        $buffer.=$line."\n";$trimmed=rtrim($buffer);if(!str_ends_with($trimmed,$delimiter))continue;
        $statement=trim(substr($trimmed,0,-strlen($delimiter)));$buffer='';$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);
        try{$result=$pdo->query($statement);do{if($result->columnCount()>0)$result->fetchAll();}while($result->nextRowset());$result->closeCursor();}
        finally{$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);}
    }
    if(trim($buffer)!=='')throw new RuntimeException('Unterminated repository SQL');
};
$canonical=$read($root.'/database/schema.sql');$baseline=$canonical;
foreach(['DAILY_BOOKINGS','DAILY_PAYMENTS']as$section){$baseline=preg_replace('/^-- BEGIN GENERATED '.preg_quote($section,'/').'\R.*?^-- END GENERATED '.preg_quote($section,'/').'\R?/ms','',$baseline,1,$count);$assert($count===1,'Missing canonical generated section '.$section);}
$baseline=preg_replace_callback('/CREATE TABLE IF NOT EXISTS rooms \(\R(.*?)\R\) ENGINE=InnoDB[^;]*;/s',static function(array $match)use($assert):string{
    $table=$match[0];$table=preg_replace('/^[ \t]*(?:rental_mode|daily_rate|max_guests|daily_deposit|housekeeping_status|housekeeping_version)\b[^\r\n]*\R/m','',$table,-1,$columns);$assert($columns===6,'Expected exactly six additive daily room columns');
    $table=preg_replace('/^[ \t]*CONSTRAINT (?:chk_rooms_daily_policy|chk_rooms_housekeeping_version)\b[^\r\n]*\R/m','',$table,-1,$checks);$assert($checks===2,'Expected exactly two additive daily room checks');
    $table=preg_replace('/^[ \t]*CONSTRAINT chk_rooms_monthly_rent\b[^\r\n]*/m','    CONSTRAINT chk_rooms_monthly_rent CHECK (monthly_rent > 0 AND monthly_rent <= 1000000),',$table,1,$rent);$assert($rent===1,'Missing baseline monthly rent constraint');return$table;
},$baseline,1,$roomTables);$assert($roomTables===1,'Missing canonical rooms table');
$runSql($baseline);$runSql($read($root.'/database/defaults.sql'));
$assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='rooms' AND column_name='rental_mode'")->fetchColumn()===0,'Baseline must predate daily rooms');
$pdo->exec("INSERT INTO admin_users(username,password_hash,role,auth_version,active) VALUES('migration_owner','preserved-fixture-owner-hash','owner',7,1)");$owner=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO rooms(room_code,floor,room_type,monthly_rent,description,amenities) VALUES('MIGRATION-PRESERVED',2,'Monthly fixture','3456.78','Preserved room description','[]')");$roomId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO bookings(reference_no,room_id,full_name,phone_norm,booked_monthly_rent,idempotency_key) VALUES('BK-MIGRATION-PRESERVED',?,'Preserved monthly guest','0819900081','3456.78','monthly-migration-preserved-key')")->execute([$roomId]);$bookingId=(int)$pdo->lastInsertId();
$snapshot=static function()use($pdo,$bookingId,$roomId,$owner):array{
    $q=$pdo->prepare('SELECT * FROM bookings WHERE id=?');$q->execute([$bookingId]);$booking=$q->fetch();
    $q=$pdo->prepare('SELECT id,room_code,floor,room_type,monthly_rent,description,amenities,image_key,deleted_at,created_at,updated_at FROM rooms WHERE id=?');$q->execute([$roomId]);$room=$q->fetch();
    $q=$pdo->prepare('SELECT * FROM admin_users WHERE id=?');$q->execute([$owner]);return['booking'=>$booking,'room'=>$room,'owner'=>$q->fetch()];
};
$original=$snapshot();$m18=$read($root.'/database/migrations/018_daily_bookings.sql');$m19=$read($root.'/database/migrations/019_daily_payments.sql');$m20=$read($root.'/database/migrations/020_daily_review_fixes.sql');$groups=0;
$runSql($m18);$assert($snapshot()===$original,'Migration 018 rewrote existing monthly data');$assert(DailyBookingSchema::errors($pdo)!==[],'Partial daily upgrade falsely reported ready before finance migration');
$runSql($m19);$runSql($m20);$assert($snapshot()===$original,'Migrations 019/020 rewrote existing monthly data');$errors=DailyBookingSchema::errors($pdo);$assert($errors===[],'Complete daily upgrade rejected: '.implode('; ',$errors));
$q=$pdo->prepare('SELECT rental_mode,daily_rate,max_guests,daily_deposit,housekeeping_status,housekeeping_version FROM rooms WHERE id=?');$q->execute([$roomId]);$policy=$q->fetch();
$assert($policy['rental_mode']==='monthly'&&$policy['daily_rate']===null&&(int)$policy['max_guests']===2&&$policy['daily_deposit']==='0.00'&&$policy['housekeeping_status']==='ready'&&(int)$policy['housekeeping_version']===1,'Migration changed an existing room to daily or supplied an unsafe policy');
$groups++;fwrite(STDOUT,"PASS real baseline 017 upgrades additively and preserves monthly booking, owner and room snapshots\n");
$runSql($m18);$runSql($m19);$runSql($m20);$assert($snapshot()===$original&&DailyBookingSchema::errors($pdo)===[],'Replayed migrations changed history or readiness');$groups++;fwrite(STDOUT,"PASS self-contained migrations 018, 019 and 020 replay without changing existing data\n");

// Real monthly receipt with injected provider I/O. Config points at a fresh
// fixture directory, so Config::fromEnvironment cannot read the live .env.
$fixtureRoot=sys_get_temp_dir().DIRECTORY_SEPARATOR.'appj-daily-migration-'.bin2hex(random_bytes(10));
$assert(mkdir($fixtureRoot,0700,true),'Cannot create dedicated fixture directory');
$assert(!file_exists($fixtureRoot.'/.env'),'Fixture directory unexpectedly contains an environment file');
foreach(['APP_URL'=>'http://127.0.0.1','APP_KEY'=>'daily-migration-fixture-secret-key-2026-32-bytes','APP_TIMEZONE'=>'Asia/Bangkok','APP_DEBUG'=>'false','FORCE_HTTPS'=>'false','DB_SSL'=>'false']as$key=>$value)putenv($key.'='.$value);
try{
    $config=Config::fromEnvironment($fixtureRoot);$config->configurePhp();$app=new Application($config);
    $connection=new ReflectionProperty(Database::class,'pdo');$connection->setValue($app->database(),$pdo);
    $app->billing()->updateSettings(['water_rate'=>'0','electric_rate'=>'0','due_days'=>7],$owner);
    $app->settings()->update(['promptpay_target'=>'0812345678','promptpay_name'=>'Migration fixture','slip_provider'=>'slipok','slipok_branch_id'=>'fixture','slipok_api_key'=>'fixture-not-real','payment_receiver_account_tail'=>'567890'],$owner);
    $today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$period=substr($today,0,7);
    $paidRoom=$app->rooms()->create(['room_code'=>'MIGRATION-PAID','floor'=>1,'room_type'=>'Monthly receipt fixture','monthly_rent'=>'100.00']);
    $resident=$app->bookings()->createAdminResident($owner,['room_id'=>$paidRoom['id'],'full_name'=>'Monthly receipt fixture','phone'=>'0819900082','move_in_date'=>$period.'-01','opening_water_reading'=>'0','opening_electric_reading'=>'0','idempotency_key'=>'monthly-migration-receipt-create']);
    $app->meters()->record(['room_id'=>$paidRoom['id'],'period'=>$period,'water_current'=>'0','electric_current'=>'0'],$owner);
    $billing=['period'=>$period,'room_ids'=>[$paidRoom['id']],'confirm_current_period'=>true];$preview=$app->billing()->preview($billing);$bill=$app->billing()->bulk($billing+['preview_token'=>$preview['preview_token']],$owner)['created'][0];
    $instruction=$app->transfers()->reserve((int)$bill['id'],(int)$resident['resident_id']);
    $imagePath=$fixtureRoot.'/storage/receipt-fixture.png';$image=imagecreatetruecolor(16,16);imagefill($image,0,0,imagecolorallocate($image,17,41,83));imagepng($image,$imagePath);unset($image);
    $providerCalls=0;$service=new PaymentService($app,static function($url,$headers,$body)use(&$providerCalls):array{$providerCalls++;return['_status'=>200,'success'=>true,'data'=>['success'=>true,'transRef'=>'MIGRATION-MONTHLY-BACKFILL-REF','amount'=>$body['amount'],'transTimestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'countryCode'=>'TH','paidLocalCurrency'=>'THB','receiver'=>['account'=>['value'=>'xxx567890']]]];});
    $payment=$service->upload((int)$bill['id'],(int)$resident['resident_id'],['error'=>UPLOAD_ERR_OK,'tmp_name'=>$imagePath,'size'=>filesize($imagePath)]);
    $assert($payment['status']==='verified'&&$providerCalls===1,'Injected provider did not create one genuine verified monthly receipt');
    $receiptSnapshot=static function()use($pdo,$payment,$bill):array{$q=$pdo->prepare('SELECT * FROM payments WHERE id=?');$q->execute([$payment['id']]);$receipt=$q->fetch();$q=$pdo->prepare('SELECT * FROM bills WHERE id=?');$q->execute([$bill['id']]);$ledger=$q->fetch();$q=$pdo->prepare('SELECT * FROM transfer_instructions WHERE bill_id=?');$q->execute([$bill['id']]);return['receipt'=>$receipt,'bill'=>$ledger,'instruction'=>$q->fetch()];};
    $beforeBackfill=$receiptSnapshot();$assert($beforeBackfill['bill']['status']==='paid','Monthly receipt must close its real bill before the backfill fixture');
    // Only disposable shared indexes are removed. Money/booking/bill evidence stays intact.
    $pdo->exec('DROP TABLE payment_evidence_registry');$pdo->exec('DROP TABLE payment_amount_registry');
    $assert(DailyBookingSchema::errors($pdo)!==[],'Missing shared registries falsely reported ready');
    $runSql($m19);$runSql($m20);$assert($receiptSnapshot()===$beforeBackfill&&$snapshot()===$original,'Evidence backfill rewrote immutable monthly ledger or original booking');
    $q=$pdo->prepare("SELECT slip_hmac,transaction_ref FROM payment_evidence_registry WHERE subject_type='monthly' AND subject_id=?");$q->execute([$payment['id']]);$evidence=$q->fetch();
    $assert($evidence&&$evidence['slip_hmac']===$beforeBackfill['receipt']['slip_hmac']&&$evidence['transaction_ref']===$beforeBackfill['receipt']['transaction_ref'],'Existing receipt did not backfill into the cross-module evidence registry');
    $q=$pdo->prepare("SELECT transfer_amount,status,settled_at FROM payment_amount_registry WHERE subject_type='monthly' AND subject_id=?");$q->execute([$bill['id']]);$amount=$q->fetch();
    $assert($amount&&$amount['transfer_amount']===$beforeBackfill['instruction']['transfer_amount']&&$amount['status']===$beforeBackfill['instruction']['status']&&$amount['settled_at']===$beforeBackfill['instruction']['settled_at'],'Existing transfer did not backfill its exact amount and settled evidence');
    $assert(DailyBookingSchema::errors($pdo)===[],'Backfilled schema did not recover readiness');$groups++;fwrite(STDOUT,"PASS existing genuine monthly receipt and QR amount backfill without rewriting ledger evidence\n");
    $registryBefore=$pdo->query('SELECT * FROM payment_evidence_registry ORDER BY subject_type,subject_id')->fetchAll();$amountsBefore=$pdo->query('SELECT * FROM payment_amount_registry ORDER BY subject_type,subject_id')->fetchAll();
    $runSql($m19);$runSql($m20);$assert($receiptSnapshot()===$beforeBackfill&&$registryBefore===$pdo->query('SELECT * FROM payment_evidence_registry ORDER BY subject_type,subject_id')->fetchAll()&&$amountsBefore===$pdo->query('SELECT * FROM payment_amount_registry ORDER BY subject_type,subject_id')->fetchAll(),'Repeated finance backfill changed a receipt or duplicated registry identities');
    $groups++;fwrite(STDOUT,"PASS repeated finance backfill preserves receipt and registry identities\n");

    // Reconstruct the released 019 shape, while keeping genuine immutable ledger rows.
    $dailyRoom=$app->rooms()->create(['room_code'=>'MIGRATION-DAILY-PAID','floor'=>1,'room_type'=>'Daily upgrade fixture','rental_mode'=>'daily','daily_rate'=>'100.00','daily_deposit'=>'10.00','max_guests'=>2]);
    $end=(new DateTimeImmutable($today))->modify('+2 days')->format('Y-m-d');$daily=$app->dailyBookings()->createAdmin($owner,['room_id'=>$dailyRoom['id'],'full_name'=>'Preserved daily fixture','phone'=>'0819900084','check_in_date'=>$today,'check_out_date'=>$end,'guests'=>1,'idempotency_key'=>'migration-daily-create']);
    $cash=$app->dailyPayments()->cash($daily['id'],['expected_version'=>1,'idempotency_key'=>'migration-daily-cash','reference'=>'MIGRATION-DAILY-CASH','reason'=>'Actual fixture full cash receipt'],$owner);
    $app->dailyBookings()->transition($daily['id'],'check-in',['expected_version'=>2,'idempotency_key'=>'migration-daily-checkin'],$owner);
    $app->dailyPayments()->refund($daily['id'],['expected_version'=>3,'idempotency_key'=>'migration-daily-refund','amount'=>'10.00','reference'=>'MIGRATION-DAILY-REFUND','reason'=>'Actual fixture outbound deposit return'],$owner);
    $app->dailyBookings()->transition($daily['id'],'check-out',['expected_version'=>3,'idempotency_key'=>'migration-daily-checkout'],$owner);
    $rejectedRoom=$app->rooms()->create(['room_code'=>'MIGRATION-REJECTED','floor'=>1,'room_type'=>'Rejected proof fixture','monthly_rent'=>'100.00']);$rejectedResident=$app->bookings()->createAdminResident($owner,['room_id'=>$rejectedRoom['id'],'full_name'=>'Rejected receipt fixture','phone'=>'0819900085','move_in_date'=>$period.'-01','opening_water_reading'=>'0','opening_electric_reading'=>'0','idempotency_key'=>'migration-rejected-resident']);$app->meters()->record(['room_id'=>$rejectedRoom['id'],'period'=>$period,'water_current'=>'0','electric_current'=>'0'],$owner);
    $plan=['period'=>$period,'room_ids'=>[$rejectedRoom['id']],'confirm_current_period'=>true];$preview=$app->billing()->preview($plan);$rejectedBill=$app->billing()->bulk($plan+['preview_token'=>$preview['preview_token']],$owner)['created'][0];$app->transfers()->reserve((int)$rejectedBill['id'],(int)$rejectedResident['resident_id']);
    $badImagePath=$fixtureRoot.'/storage/rejected-proof.png';$image=imagecreatetruecolor(16,16);imagefill($image,0,0,imagecolorallocate($image,31,99,173));imagepng($image,$badImagePath);unset($image);
    $rejectService=new PaymentService($app,static fn($url,$headers,$body):array=>['_status'=>200,'success'=>true,'data'=>['success'=>true,'transRef'=>'MIGRATION-REJECTED-BANK-REF','amount'=>'1.00','transTimestamp'=>gmdate('Y-m-d\TH:i:s\Z'),'countryCode'=>'TH','paidLocalCurrency'=>'THB','receiver'=>['account'=>['value'=>'xxx567890']]]]);$rejected=$rejectService->upload((int)$rejectedBill['id'],(int)$rejectedResident['resident_id'],['error'=>UPLOAD_ERR_OK,'tmp_name'=>$badImagePath,'size'=>filesize($badImagePath)]);$assert($rejected['status']==='rejected','Injected bad amount must create a genuine rejected receipt');
    $projectionFields=['active_slip_hmac','credited_txn_ref','active_txn_ref','claim_status'];
    $upgradeSnapshot=static function()use($pdo,$snapshot,$projectionFields):array{
        $out=['original'=>$snapshot()];foreach(['payments','bills','transfer_instructions','payment_evidence_registry','payment_amount_registry','daily_bookings','daily_booking_nights','daily_payments','daily_refunds','daily_deposit_settlements','daily_booking_actions']as$table){$order=in_array($table,['payment_evidence_registry','payment_amount_registry'],true)?'subject_type,subject_id':($table==='transfer_instructions'?'bill_id':($table==='daily_deposit_settlements'?'booking_id':'id'));$rows=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll();foreach($rows as&$row)foreach($projectionFields as$field)unset($row[$field]);unset($row);$out[$table]=$rows;}return$out;
    };
    $legacyLedger=$upgradeSnapshot();
    $financeSource=$read($root.'/database/daily_payments.sql');preg_match_all('/CREATE TRIGGER\s+([A-Za-z0-9_]+)/i',$financeSource,$financeTriggers);$guardNames=array_unique(array_merge($financeTriggers[1],['trg_daily_room_mode_guard']));
    $guardBodies=static function()use($pdo,$guardNames):array{$q=$pdo->prepare('SELECT trigger_name AS guard_name,action_statement AS guard_body FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN('.implode(',',array_fill(0,count($guardNames),'?')).') ORDER BY trigger_name');$q->execute(array_values($guardNames));$out=[];foreach($q->fetchAll()as$row)$out[$row['guard_name']]=preg_replace('/\s+/',' ',trim($row['guard_body']));return$out;};
    $canonicalGuards=$guardBodies();$assert(count($canonicalGuards)===count($guardNames),'Schema-owner fixture cannot inspect all canonical review guards');
    $q=$pdo->query("SELECT action_statement AS guard_body FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='trg_daily_room_mode_guard'");$roomGuard=$q->fetch()['guard_body'];
    $weakRoomGuard=preg_replace('/IF NEW\.max_guests<OLD\.max_guests.*?END IF;/s','',$roomGuard,1,$capacityBranches);$assert($capacityBranches===1,'Released room guard reconstruction did not remove exactly its capacity branch');
    $pdo->exec('ALTER TABLE daily_payments DROP CHECK chk_daily_payment_review_v20');
    // Only the guards changed/rebuilt by 020 reference new claim/status semantics.
    // Unchanged 019 amount, refund, deposit, insert and history guards remain installed.
    preg_match_all('/CREATE TRIGGER\s+([A-Za-z0-9_]+)/i',$m20,$reviewTriggers);foreach(array_unique($reviewTriggers[1])as$trigger)$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);
    $pdo->exec('DROP TRIGGER IF EXISTS trg_daily_room_mode_guard');$pdo->exec('CREATE TRIGGER trg_daily_room_mode_guard BEFORE UPDATE ON rooms FOR EACH ROW '.$weakRoomGuard);
    // Payment action receipts are new in 020 and were absent from the old schema.
    $pdo->exec('DROP TABLE daily_payment_actions');
    foreach([
        ['payments','uq_payments_slip_hmac','active_slip_hmac','slip_hmac'],
        ['payments','uq_payments_transaction_ref','credited_txn_ref','transaction_ref'],
        ['daily_payments','uq_daily_payment_slip','active_slip_hmac','slip_hmac'],
        ['daily_payments','uq_daily_payment_transaction','credited_txn_ref','transaction_ref'],
        ['payment_evidence_registry','uq_evidence_slip','active_slip_hmac','slip_hmac'],
        ['payment_evidence_registry','uq_evidence_transaction','active_txn_ref','transaction_ref'],
    ]as[$table,$index,$projection,$raw]){
        $pdo->exec('ALTER TABLE '.$table.' DROP INDEX '.$index.', DROP COLUMN '.$projection.', ADD UNIQUE KEY '.$index.'('.$raw.')');
    }
    $pdo->exec('ALTER TABLE payment_evidence_registry DROP COLUMN claim_status');
    $pdo->exec("ALTER TABLE daily_payments MODIFY status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'");
    $assert($upgradeSnapshot()===$legacyLedger,'019 reconstruction changed genuine ledger evidence');$assert(DailyBookingSchema::errors($pdo)!==[],'Released 019 raw uniqueness incorrectly reports ready for reviewed code');
    $runSql($m20);$reviewErrors=DailyBookingSchema::errors($pdo);$assert($reviewErrors===[],'020 did not upgrade the actual old 019 projections, actions and enum: '.implode('; ',$reviewErrors));$assert($upgradeSnapshot()===$legacyLedger,'020 changed immutable booking, payment, deposit return or action evidence');$assert($guardBodies()===$canonicalGuards,'020 failed to restore exact canonical finance and room-capacity guard bodies');
    $q=$pdo->prepare('SELECT claim_status,active_slip_hmac,active_txn_ref FROM payment_evidence_registry WHERE subject_type=\'monthly\' AND subject_id=?');$q->execute([$payment['id']]);$claim=$q->fetch();$assert($claim['claim_status']==='active'&&$claim['active_slip_hmac']===$beforeBackfill['receipt']['slip_hmac']&&$claim['active_txn_ref']===$beforeBackfill['receipt']['transaction_ref'],'020 lost the credited monthly claim');
    $q->execute([$rejected['id']]);$discardedClaim=$q->fetch();$assert($discardedClaim['claim_status']==='released'&&$discardedClaim['active_slip_hmac']===null&&$discardedClaim['active_txn_ref']===null,'020 did not release historical rejected evidence from raw unique claims');$q=$pdo->prepare('SELECT slip_hmac,provider_payload,status FROM payments WHERE id=?');$q->execute([$rejected['id']]);$discarded=$q->fetch();$payload=json_decode($discarded['provider_payload'],true,512,JSON_THROW_ON_ERROR);$assert($discarded['status']==='rejected'&&$discarded['slip_hmac']!==''&&($payload['unverified_transaction_ref']??null)==='MIGRATION-REJECTED-BANK-REF','020 discarded raw rejected image identity or provider history');
    foreach(['payments'=>'uq_payments_slip_hmac','daily_payments'=>'uq_daily_payment_slip','payment_evidence_registry'=>'uq_evidence_slip']as$table=>$index){$q=$pdo->prepare('SELECT column_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$q->execute([$table,$index]);$assert($q->fetchColumn()==='active_slip_hmac','020 did not replace raw slip uniqueness');}
    $assert((int)$pdo->query('SELECT COUNT(*) FROM daily_payment_actions')->fetchColumn()===0,'020 manufactured historical action receipts');$groups++;fwrite(STDOUT,"PASS released 019 raw uniqueness upgrades to 020 without rewriting real monthly/daily receipts, deposit returns or booking actions\n");
    $fullReviewed=$pdo->query('SELECT * FROM payment_evidence_registry ORDER BY subject_type,subject_id')->fetchAll();$runSql($m20);$assert(DailyBookingSchema::errors($pdo)===[]&&$upgradeSnapshot()===$legacyLedger&&$fullReviewed===$pdo->query('SELECT * FROM payment_evidence_registry ORDER BY subject_type,subject_id')->fetchAll()&&$guardBodies()===$canonicalGuards,'Repeated 020 changed ledger, claim history or reviewed guard bodies');$groups++;fwrite(STDOUT,"PASS repeated 020 preserves reviewed ledger and active claim state\n");
}finally{
    // Resolve every deletion target beneath this newly created fixture root.
    $resolvedRoot=realpath($fixtureRoot);if(is_string($resolvedRoot)){
        $prefix=rtrim(str_replace('\\','/',$resolvedRoot),'/').'/';
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as$item){$resolved=realpath($item->getPathname());if(!is_string($resolved)||!str_starts_with(str_replace('\\','/',$resolved),$prefix))throw new RuntimeException('Fixture cleanup escaped its verified directory');if($item->isDir())rmdir($resolved);else unlink($resolved);}
        rmdir($resolvedRoot);
    }
}
fwrite(STDOUT,"{$groups} daily migration and backfill groups passed; isolated schema and injected provider only\n");
