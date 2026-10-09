<?php
declare(strict_types=1);

use Dormitory\Http\HttpException;
use Dormitory\Security\Password;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||preg_match('/^appj_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE'))!==1)exit(64);
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
if(($argv[1]??'')==='--race-worker'){
    $roomId=(int)$argv[2];$start=$argv[3];$end=$argv[4];$suffix=$argv[5];
    try{
        $quote=$app->dailyBookings()->quote(['room_id'=>$roomId,'check_in_date'=>$start,'check_out_date'=>$end,'guests'=>1]);
        fwrite(STDOUT,"READY\n");fflush(STDOUT);fgets(STDIN);
        $row=$app->dailyBookings()->createPublic(['room_id'=>$roomId,'full_name'=>'Concurrent guest '.$suffix,'phone'=>'081990000'.$suffix,'check_in_date'=>$start,'check_out_date'=>$end,'guests'=>1,'quote_token'=>$quote['quote_token'],'idempotency_key'=>'daily-concurrent-key-000'.$suffix]);
        fwrite(STDOUT,json_encode(['ok'=>true,'id'=>$row['id']],JSON_THROW_ON_ERROR)."\n");
    }catch(HttpException $e){fwrite(STDOUT,json_encode(['ok'=>false,'error'=>$e->errorCode],JSON_THROW_ON_ERROR)."\n");}
    exit;
}
foreach(['admin_users','rooms','daily_bookings','daily_payments']as$table)if((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()!==0)throw new RuntimeException('Fresh isolated daily booking database required');
$assert=static function(bool $ok,string $why='Assertion failed'):void{if(!$ok)throw new RuntimeException($why);};
$expect=static function(callable $fn,string $code)use($assert):void{try{$fn();}catch(HttpException $e){$assert($e->errorCode===$code,"Expected {$code}, got {$e->errorCode}");return;}throw new RuntimeException("Expected {$code}");};
$sqlReject=static function(callable $fn,bool $allowDeletePermissionDenial=false)use($assert):void{try{$fn();}catch(PDOException $e){$codes=$allowDeletePermissionDenial?[1644,3819,1062,1142]:[1644,3819,1062];$assert(in_array((int)($e->errorInfo[1]??0),$codes,true),'Unexpected SQL rejection '.$e->getMessage());return;}throw new RuntimeException('Direct SQL bypass accepted');};
$groups=0;$test=static function(string $name,callable $fn)use(&$groups):void{$fn();$groups++;fwrite(STDOUT,"PASS {$name}\n");};
$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$date=static fn(int $offset):string=>(new DateTimeImmutable($today))->modify(sprintf('%+d days',$offset))->format('Y-m-d');
$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active) VALUES ('daily_booking_owner',?,'owner',1,1)")->execute([Password::hash('Daily-Booking-Fixture-2026!')]);$owner=(int)$pdo->lastInsertId();
$makeRoom=static function(string $code,string $mode='daily',string $deposit='0.00')use($pdo):int{
    $pdo->prepare("INSERT INTO rooms(room_code,floor,room_type,monthly_rent,amenities,rental_mode,daily_rate,max_guests,daily_deposit) VALUES (?,1,'fixture',?,'[]',?,?,2,?)")->execute([$code,$mode==='monthly'?'3000.00':'0.00',$mode,$mode==='daily'?'650.25':null,$deposit]);return(int)$pdo->lastInsertId();
};
$create=static function(int $room,string $start,string $end,string $phone='0819900011',?string $key=null)use($app):array{
    $quote=$app->dailyBookings()->quote(['room_id'=>$room,'check_in_date'=>$start,'check_out_date'=>$end,'guests'=>2]);
    return$app->dailyBookings()->createPublic(['room_id'=>$room,'full_name'=>'Daily fixture guest','phone'=>$phone,'check_in_date'=>$start,'check_out_date'=>$end,'guests'=>2,'quote_token'=>$quote['quote_token'],'idempotency_key'=>$key??'daily-create-'.bin2hex(random_bytes(10))]);
};
$daily=$makeRoom('DAILY-A','daily','100.00');$monthly=$makeRoom('MONTHLY-A','monthly');$race=$makeRoom('DAILY-RACE');$expiry=$makeRoom('DAILY-EXPIRY');$blockRoom=$makeRoom('DAILY-BLOCK');
$booking=$create($daily,$today,$date(2));
$test('per-night exact snapshot and secure booking access',function()use($app,$assert,$expect,$booking):void{
    $assert($booking['room_amount']==='1300.50'&&$booking['total_amount']==='1400.50'&&$booking['nights']===2);
    $assert($app->dailyBookings()->guestDetails($booking['id'],$booking['access_token'])['id']===$booking['id']);
    $expect(fn()=>$app->dailyBookings()->guestDetails($booking['id'],str_repeat('0',64)),'DAILY_BOOKING_NOT_FOUND');
    $assert(!isset($app->dailyBookings()->all()['items'][0]['access_token']));
});
$test('overlap rejected and adjacent future trips by the same phone allowed',function()use($app,$assert,$expect,$create,$daily,$today,$date,$booking):void{
    $expect(fn()=>$create($daily,$date(1),$date(3)),'DAILY_ROOM_NOT_AVAILABLE');
    $next=$create($daily,$date(2),$date(4));$assert($next['phone']===$booking['phone']);
    $found=$app->dailyBookings()->availability(['check_in_date'=>$date(4),'check_out_date'=>$date(5),'guests'=>2]);$assert(in_array($daily,array_column($found['items'],'room_id'),true));
});
$test('monthly rooms excluded and immutable night ledger enforced',function()use($app,$expect,$sqlReject,$pdo,$monthly,$booking,$daily,$today,$date):void{
    $expect(fn()=>$app->dailyBookings()->quote(['room_id'=>$monthly,'check_in_date'=>$today,'check_out_date'=>$date(1),'guests'=>1]),'ROOM_NOT_DAILY');
    $sqlReject(fn()=>$pdo->prepare("UPDATE daily_bookings SET nightly_rate='700.00',room_amount='1400.00',total_amount='1500.00' WHERE id=?")->execute([$booking['id']]));
    $sqlReject(fn()=>$pdo->prepare('UPDATE daily_booking_nights SET room_id=? WHERE booking_id=?')->execute([$monthly,$booking['id']]));
    $sqlReject(fn()=>$pdo->prepare('DELETE FROM daily_booking_nights WHERE booking_id=?')->execute([$booking['id']]),true);
    $sqlReject(fn()=>$pdo->prepare("UPDATE rooms SET rental_mode='monthly',monthly_rent=3000 WHERE id=?")->execute([$daily]));
});
$test('dedicated daily rooms stay outside monthly meter entry',function()use($app,$assert,$expect,$pdo,$daily,$monthly,$today,$owner):void{
    $period=substr($today,0,7);$rows=$app->meters()->list($period);$roomIds=array_column($rows,'room_id');
    $assert(!in_array($daily,$roomIds,true),'Daily room must not appear as a vacant room in monthly meter entry');
    $assert(in_array($monthly,$roomIds,true),'Monthly rooms must remain available to monthly metering');
    $query=$pdo->prepare('SELECT COUNT(*) FROM meter_readings WHERE room_id=?');$query->execute([$daily]);$before=(int)$query->fetchColumn();
    $expect(fn()=>$app->meters()->record(['room_id'=>$daily,'period'=>$period,'water_current'=>'0','electric_current'=>'0'],$owner),'ROOM_RENTAL_MODE');
    $query->execute([$daily]);$assert((int)$query->fetchColumn()===$before,'Rejected daily metering must not write either utility reading');
});
$test('public replay recovers token while binding all input fields',function()use($app,$assert,$expect,$makeRoom,$today,$date):void{
    $room=$makeRoom('DAILY-REPLAY');$quote=$app->dailyBookings()->quote(['room_id'=>$room,'check_in_date'=>$today,'check_out_date'=>$date(1),'guests'=>1]);
    $input=['room_id'=>$room,'full_name'=>'Replay fixture','phone'=>'0819900012','check_in_date'=>$today,'check_out_date'=>$date(1),'guests'=>1,'quote_token'=>$quote['quote_token'],'idempotency_key'=>'daily-replay-key-00001'];
    $a=$app->dailyBookings()->createPublic($input);$b=$app->dailyBookings()->createPublic($input);$assert($a['id']===$b['id']&&$a['access_token']===$b['access_token']&&$b['idempotent_replay']);
    $expect(fn()=>$app->dailyBookings()->createPublic(array_replace($input,['full_name'=>'Changed fixture'])),'IDEMPOTENCY_KEY_REUSED');
});
$test('dirty same-day rooms cannot be booked or marked ready with stale version',function()use($app,$assert,$expect,$makeRoom,$pdo,$today,$date,$owner):void{
    $room=$makeRoom('DAILY-DIRTY');$pdo->prepare("UPDATE rooms SET housekeeping_status='cleaning',housekeeping_version=2 WHERE id=?")->execute([$room]);
    $expect(fn()=>$app->dailyBookings()->quote(['room_id'=>$room,'check_in_date'=>$today,'check_out_date'=>$date(1),'guests'=>1]),'ROOM_NOT_READY');
    $expect(fn()=>$app->dailyBookings()->markReady($room,['expected_version'=>1,'idempotency_key'=>'daily-ready-stale-0001'],$owner),'DAILY_VERSION_CONFLICT');
    $input=['expected_version'=>2,'idempotency_key'=>'daily-ready-good-00001'];$a=$app->dailyBookings()->markReady($room,$input,$owner);$b=$app->dailyBookings()->markReady($room,$input,$owner);$assert($a['housekeeping_version']===3&&$b['idempotent_replay']);
    $pdo->prepare("UPDATE rooms SET housekeeping_status='cleaning',housekeeping_version=4 WHERE id=?")->execute([$room]);
    $latest=$app->dailyBookings()->markReady($room,$input,$owner);$assert($latest['idempotent_replay']&&$latest['housekeeping_status']==='cleaning'&&$latest['housekeeping_version']===4,'Old ready request must report current cleaning state without resetting it');
});
$test('full payment required and daily checkout closes without monthly bills',function()use($app,$assert,$expect,$booking,$owner,$pdo,$daily):void{
    $expect(fn()=>$app->dailyBookings()->transition($booking['id'],'confirm',['expected_version'=>1,'idempotency_key'=>'daily-confirm-unpaid-01'],$owner),'DAILY_PAYMENT_REQUIRED');
    $payment=$app->dailyPayments()->cash($booking['id'],['expected_version'=>1,'idempotency_key'=>'daily-cash-booking-0001','reference'=>'CASH-DAILY-BOOKING-0001','reason'=>'ทดสอบรับเงินครบ'],$owner);$assert($payment['booking_status']==='confirmed');
    $input=['expected_version'=>2,'idempotency_key'=>'daily-check-in-key-0001'];$in=$app->dailyBookings()->transition($booking['id'],'check-in',$input,$owner);$again=$app->dailyBookings()->transition($booking['id'],'check-in',$input,$owner);$assert($in['version']===3&&$again['idempotent_replay']);
    $expect(fn()=>$app->dailyBookings()->transition($booking['id'],'check-out',['expected_version'=>3,'idempotency_key'=>'daily-checkout-unsettled'],$owner),'DAILY_DEPOSIT_UNSETTLED');
    $app->dailyPayments()->refund($booking['id'],['expected_version'=>3,'idempotency_key'=>'daily-booking-refund-001','amount'=>'100.00','reference'=>'DAILY-DEPOSIT-RETURN-1','reason'=>'คืนเงินประกันเต็มจำนวน'],$owner);
    $out=$app->dailyBookings()->transition($booking['id'],'check-out',['expected_version'=>3,'idempotency_key'=>'daily-check-out-key-001'],$owner);$assert($out['status']==='checked_out'&&$out['version']===4);
    $replay=$app->dailyBookings()->transition($booking['id'],'check-in',$input,$owner);$assert($replay['idempotent_replay']&&$replay['status']==='checked_out'&&$replay['version']===4,'Old check-in replay must report current checked-out state');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM bills')->fetchColumn()===0);
    $q=$pdo->prepare('SELECT housekeeping_status,housekeeping_version FROM rooms WHERE id=?');$q->execute([$daily]);$row=$q->fetch();$assert($row['housekeeping_status']==='cleaning'&&(int)$row['housekeeping_version']===2);
    $q=$pdo->prepare('SELECT COUNT(*) FROM daily_booking_nights WHERE booking_id=? AND active=1');$q->execute([$booking['id']]);$assert((int)$q->fetchColumn()===2,'Early checkout must retain booked future night history');
    $rejected=false;try{$pdo->prepare("UPDATE rooms SET rental_mode='monthly',monthly_rent=3000 WHERE id=?")->execute([$daily]);}catch(PDOException $e){$rejected=(int)($e->errorInfo[1]??0)===1644;}$assert($rejected,'Early checkout must not allow conversion while paid future nights remain allocated');
});
$test('cancel releases nights with immutable idempotent action history',function()use($app,$assert,$expect,$sqlReject,$pdo,$create,$makeRoom,$today,$date,$owner):void{
    $room=$makeRoom('DAILY-CANCEL');$row=$create($room,$today,$date(2));$input=['expected_version'=>1,'idempotency_key'=>'daily-cancel-action-001','reason'=>'ยกเลิกทดสอบ'];
    $a=$app->dailyBookings()->transition($row['id'],'cancel',$input,$owner);$b=$app->dailyBookings()->transition($row['id'],'cancel',$input,$owner);$assert($a['status']==='cancelled'&&$b['idempotent_replay']);
    $expect(fn()=>$app->dailyBookings()->transition($row['id'],'cancel',array_replace($input,['reason'=>'different']),$owner),'IDEMPOTENCY_KEY_REUSED');
    $new=$create($room,$today,$date(2));$assert($new['id']!==$row['id']);
    $sqlReject(fn()=>$pdo->prepare('UPDATE daily_booking_nights SET active=1,released_at=NULL WHERE booking_id=?')->execute([$row['id']]));
});
$test('explicit room blocks filter availability and release by version',function()use($app,$assert,$expect,$blockRoom,$today,$date,$owner):void{
    $block=$app->dailyBookings()->createBlock($blockRoom,['start_date'=>$today,'end_date'=>$date(2),'reason'=>'ทดสอบซ่อมห้อง','idempotency_key'=>'daily-block-create-001'],$owner);
    $expect(fn()=>$app->dailyBookings()->quote(['room_id'=>$blockRoom,'check_in_date'=>$today,'check_out_date'=>$date(1),'guests'=>1]),'DAILY_ROOM_NOT_AVAILABLE');
    $expect(fn()=>$app->dailyBookings()->releaseBlock($block['id'],['expected_version'=>2,'idempotency_key'=>'daily-block-stale-0001'],$owner),'DAILY_VERSION_CONFLICT');
    $released=$app->dailyBookings()->releaseBlock($block['id'],['expected_version'=>1,'idempotency_key'=>'daily-block-release-001'],$owner);$assert(!$released['active']);
});
$test('expired holds disappear without worker and mutations release them atomically',function()use($app,$assert,$pdo,$expiry,$today,$date,$create):void{
    $key='daily-expiry-fixture-0001';$pdo->prepare("INSERT INTO daily_bookings(reference_no,room_id,full_name,phone_norm,check_in_date,check_out_date,guests,nightly_rate,room_amount,deposit_amount,total_amount,expires_at,access_token_hash,idempotency_key,request_hash) VALUES('DY-EXPIRED-FIXTURE-001',?,'Expired fixture','0819900031',?,?,1,'650.25','650.25',0,'650.25',DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND),?,?,?)")->execute([$expiry,$today,$date(1),str_repeat('a',64),$key,str_repeat('b',64)]);$old=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO daily_booking_nights(booking_id,room_id,stay_date,nightly_rate) VALUES(?,?,?,'650.25')")->execute([$old,$expiry,$today]);
    $rooms=$app->dailyBookings()->availability(['check_in_date'=>$today,'check_out_date'=>$date(1),'guests'=>1]);$assert(in_array($expiry,array_column($rooms['items'],'room_id'),true));
    $new=$create($expiry,$today,$date(1));$q=$pdo->prepare('SELECT status,version FROM daily_bookings WHERE id=?');$q->execute([$old]);$row=$q->fetch();$assert($row['status']==='expired'&&(int)$row['version']===2&&$new['id']!==$old);
    $assert((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='daily.booking.expired'")->fetchColumn()===1);
});
$test('two genuine database sessions racing one room leave exactly one booking',function()use($assert,$pdo,$race,$date):void{
    $processes=[];
    $phpOptions=php_ini_loaded_file()?['-c',php_ini_loaded_file()]:['-d','extension_dir='.ini_get('extension_dir'),'-d','extension=openssl','-d','extension=mbstring','-d','extension=pdo_mysql'];
    foreach(['1','2']as$suffix){$pipes=[];$proc=proc_open(array_merge([PHP_BINARY],$phpOptions,[__FILE__,'--race-worker',(string)$race,$date(8),$date(10),$suffix]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);if(!is_resource($proc))throw new RuntimeException('Cannot start concurrency worker');$processes[]=['proc'=>$proc,'pipes'=>$pipes];}
    foreach($processes as$p)$assert(trim((string)fgets($p['pipes'][1]))==='READY','Both workers must quote before the race starts');
    foreach($processes as$p){fwrite($p['pipes'][0],"START\n");fflush($p['pipes'][0]);fclose($p['pipes'][0]);}
    $results=[];foreach($processes as$p){$output=stream_get_contents($p['pipes'][1]);$error=stream_get_contents($p['pipes'][2]);fclose($p['pipes'][1]);fclose($p['pipes'][2]);$code=proc_close($p['proc']);$assert($code===0,'Concurrency worker failed '.$error);$results[]=json_decode(trim($output),true,512,JSON_THROW_ON_ERROR);}
    $assert(count(array_filter($results,static fn(array$r):bool=>$r['ok']))===1,'Exactly one concurrent booking may succeed');
    $q=$pdo->prepare('SELECT COUNT(*) FROM daily_bookings WHERE room_id=?');$q->execute([$race]);$assert((int)$q->fetchColumn()===1);
    $q=$pdo->prepare('SELECT COUNT(*) FROM daily_booking_nights WHERE room_id=? AND active=1');$q->execute([$race]);$assert((int)$q->fetchColumn()===2);
});
$test('owner list filters validate bounds and retain independent pagination',function()use($app,$expect,$assert,$today,$date):void{
    $expect(fn()=>$app->dailyBookings()->all(['from'=>$date(2),'to'=>$today]),'VALIDATION_ERROR');$expect(fn()=>$app->dailyBookings()->all(['limit'=>5001]),'VALIDATION_ERROR');
    $one=$app->dailyBookings()->all(['limit'=>1]);$two=$app->dailyBookings()->all(['limit'=>1,'offset'=>$one['next_offset']]);$assert($one['has_more']&&$one['next_offset']===1&&$two['items'][0]['id']!==$one['items'][0]['id']);
});
$test('room capacity cannot invalidate already allocated guests through API or SQL',function()use($app,$makeRoom,$create,$today,$date,$expect,$sqlReject,$pdo):void{
    $room=$makeRoom('DAILY-CAPACITY');$create($room,$today,$date(1));$expect(fn()=>$app->rooms()->update($room,['max_guests'=>1]),'ROOM_CAPACITY_IN_USE');$sqlReject(fn()=>$pdo->prepare('UPDATE rooms SET max_guests=1 WHERE id=?')->execute([$room]));
});
$test('a stale room catalogue cannot overwrite another owner change even within one timestamp',function()use($app,$makeRoom,$pdo,$assert,$expect):void{
    $room=$makeRoom('DAILY-CATALOGUE');$before=$app->rooms()->find($room);$pdo->prepare("UPDATE rooms SET daily_rate='700.00',description='Other owner catalogue update' WHERE id=?")->execute([$room]);
    $expect(fn()=>$app->rooms()->update($room,['daily_rate'=>'800.00','expected_version'=>$before['room_version']]),'ROOM_VERSION_CONFLICT');$current=$app->rooms()->find($room);$assert($current['daily_rate']==='700.00'&&$current['description']==='Other owner catalogue update'&&$current['room_version']!==$before['room_version']);
});
$test('historical monthly readings remain visible and locked after converting an unused room to daily',function()use($app,$makeRoom,$today,$owner,$assert,$expect):void{
    $room=$makeRoom('METER-HISTORY-CONVERT','monthly');$period=substr($today,0,7);$app->meters()->record(['room_id'=>$room,'period'=>$period,'water_current'=>'5.00','electric_current'=>'8.00'],$owner);$app->rooms()->update($room,['rental_mode'=>'daily','daily_rate'=>'650.25','monthly_rent'=>'0.00']);
    $rows=array_values(array_filter($app->meters()->list($period),static fn(array $r):bool=>$r['room_id']===$room));$assert(count($rows)===1);$history=$rows[0];$assert($history['rental_mode']==='daily'&&$history['water_locked']&&$history['electric_locked']&&$history['water_lock_reason']==='daily_history'&&$history['electric_lock_reason']==='daily_history');$assert($history['water_current']==='5.00'&&$history['electric_current']==='8.00');
    $expect(fn()=>$app->meters()->record(['room_id'=>$room,'period'=>$period,'water_current'=>'6.00','electric_current'=>'9.00'],$owner),'ROOM_RENTAL_MODE');
});
$test('expired monthly holds retire before changing rental mode or retiring a room',function()use($app,$makeRoom,$pdo,$assert):void{
    foreach(['MODE','DELETE']as$action){$room=$makeRoom('OLD-MONTHLY-'.$action,'monthly');$pdo->prepare("INSERT INTO bookings(reference_no,room_id,full_name,phone_norm,booked_monthly_rent,status,idempotency_key,created_at,updated_at) VALUES(?,?,'Expired monthly fixture',?,'3000.00','pending',?,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 8 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 8 DAY))")->execute(['BK-EXPIRED-'.$action,$room,$action==='MODE'?'0819900041':'0819900042','old-monthly-mode-'.$action]);$old=(int)$pdo->lastInsertId();
        if($action==='MODE')$app->rooms()->update($room,['rental_mode'=>'daily','daily_rate'=>'650.25','monthly_rent'=>'0.00']);else$app->rooms()->delete($room);
        $q=$pdo->prepare('SELECT status FROM bookings WHERE id=?');$q->execute([$old]);$assert($q->fetchColumn()==='cancelled');
        $q=$pdo->prepare('SELECT rental_mode,deleted_at FROM rooms WHERE id=?');$q->execute([$room]);$state=$q->fetch();$assert($action==='MODE'?$state['rental_mode']==='daily':$state['deleted_at']!==null);
    }
});
$test('block retries survive midnight and later release without creating another block',function()use($app,$makeRoom,$pdo,$date,$owner,$assert):void{
    $room=$makeRoom('DAILY-PAST-BLOCK');$input=['start_date'=>$date(-1),'end_date'=>$date(2),'reason'=>'Lost-response block fixture','idempotency_key'=>'daily-past-block-replay'];$hash=hash('sha256',json_encode(['room_id'=>$room,'start_date'=>$input['start_date'],'end_date'=>$input['end_date'],'reason'=>$input['reason']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $pdo->prepare('INSERT INTO daily_room_blocks(room_id,start_date,end_date,reason,idempotency_key,request_hash) VALUES(?,?,?,?,?,?)')->execute([$room,$input['start_date'],$input['end_date'],$input['reason'],$input['idempotency_key'],$hash]);$id=(int)$pdo->lastInsertId();
    $first=$app->dailyBookings()->createBlock($room,$input,$owner);$assert($first['id']===$id&&$first['idempotent_replay']&&$first['active']);$app->dailyBookings()->releaseBlock($id,['expected_version'=>1,'idempotency_key'=>'daily-past-block-release'],$owner);
    $again=$app->dailyBookings()->createBlock($room,$input,$owner);$assert($again['id']===$id&&$again['idempotent_replay']&&!$again['active']);$q=$pdo->prepare('SELECT COUNT(*) FROM daily_room_blocks WHERE room_id=?');$q->execute([$room]);$assert((int)$q->fetchColumn()===1);
});
$test('ended legacy holds map expired and outcome expiry commits inside an outer audit transaction',function()use($app,$makeRoom,$pdo,$today,$date,$owner,$assert):void{
    $room=$makeRoom('DAILY-ENDED-HOLD');$token=hash('sha256','ended-hold-fixture');$pdo->prepare("INSERT INTO daily_bookings(reference_no,room_id,full_name,phone_norm,check_in_date,check_out_date,guests,nightly_rate,room_amount,deposit_amount,total_amount,expires_at,access_token_hash,idempotency_key,request_hash) VALUES('DY-ENDED-HOLD',?,'Ended hold fixture','0819900051',?,?,1,'650.25','650.25',0,'650.25',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 20 MINUTE),?,'daily-ended-hold-fixture',?)")->execute([$room,$date(-1),$today,hash('sha256',$token),hash('sha256','ended-hold-request')]);$old=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO daily_booking_nights(booking_id,room_id,stay_date,nightly_rate) VALUES(?,?,?,'650.25')")->execute([$old,$room,$date(-1)]);
    $details=$app->dailyBookings()->guestDetails($old,$token);$assert($details['status']==='expired'&&strtotime($details['expires_at'])<=time());$pending=$app->dailyBookings()->all(['status'=>'pending']);$expired=$app->dailyBookings()->all(['status'=>'expired']);$assert(!in_array($old,array_column($pending['items'],'id'),true)&&in_array($old,array_column($expired['items'],'id'),true));
    $outcome=$app->database()->transaction(fn()=>$app->dailyBookings()->transitionOutcome($old,'confirm',['expected_version'=>1,'idempotency_key'=>'daily-ended-hold-confirm'],$owner));$assert(($outcome['_error']??null)==='DAILY_BOOKING_EXPIRED');
    $q=$pdo->prepare('SELECT status,version FROM daily_bookings WHERE id=?');$q->execute([$old]);$saved=$q->fetch();$assert($saved['status']==='expired'&&(int)$saved['version']===2);$q=$pdo->prepare('SELECT COUNT(*) FROM daily_booking_nights WHERE booking_id=? AND active=1');$q->execute([$old]);$assert((int)$q->fetchColumn()===0);
    $room=$makeRoom('DAILY-ENDED-WORKER');$pdo->prepare("INSERT INTO daily_bookings(reference_no,room_id,full_name,phone_norm,check_in_date,check_out_date,guests,nightly_rate,room_amount,deposit_amount,total_amount,expires_at,access_token_hash,idempotency_key,request_hash) VALUES('DY-ENDED-WORKER',?,'Ended worker fixture','0819900052',?,?,1,'650.25','650.25',0,'650.25',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 20 MINUTE),?,'daily-ended-worker-fixture',?)")->execute([$room,$date(-1),$today,hash('sha256','worker-token'),hash('sha256','worker-request')]);$work=$app->dailyBookings()->expireHolds(1);$assert($work['expired']===1&&$work['rooms_processed']===1&&!$work['has_more']);
});
$test('near-midnight quotes and holds stop at checkout and overdue occupants remain in future calendar',function()use($app,$makeRoom,$pdo,$today,$date,$owner,$assert):void{
    $nearRoom=$makeRoom('DAILY-MIDNIGHT');$near=(new DateTimeImmutable($today.' 00:00:00',new DateTimeZone('Asia/Bangkok')))->modify('-1 minute')->getTimestamp();
    try{
        $pdo->exec('SET timestamp='.$near);$clock=new DateTimeImmutable((string)$pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn(),new DateTimeZone('UTC'));$assert($clock->getTimestamp()===$near,'Fixture must control the DB clock without changing host time');
        $input=['room_id'=>$nearRoom,'check_in_date'=>$date(-1),'check_out_date'=>$today,'guests'=>1];$quote=$app->dailyBookings()->quote($input);$row=$app->dailyBookings()->createPublic($input+['full_name'=>'Near midnight fixture','phone'=>'0819900061','quote_token'=>$quote['quote_token'],'idempotency_key'=>'daily-near-midnight-create']);$boundary=$near+60;$assert(strtotime($quote['quote_expires_at'])===$boundary&&strtotime($row['expires_at'])===$boundary);
        $room=$makeRoom('DAILY-OVERDUE');$input['room_id']=$room;$quote=$app->dailyBookings()->quote($input);$stay=$app->dailyBookings()->createPublic($input+['full_name'=>'Overdue fixture','phone'=>'0819900062','quote_token'=>$quote['quote_token'],'idempotency_key'=>'daily-overdue-create']);$app->dailyPayments()->cash($stay['id'],['expected_version'=>1,'idempotency_key'=>'daily-overdue-cash','reference'=>'DAILY-OVERDUE-CASH'],$owner);$app->dailyBookings()->transition($stay['id'],'check-in',['expected_version'=>2,'idempotency_key'=>'daily-overdue-checkin'],$owner);
    }finally{$pdo->exec('SET timestamp=0');}
    $calendar=$app->dailyBookings()->calendar(['from'=>$date(1),'to'=>$date(3),'room_id'=>$room]);$overdue=array_values(array_filter($calendar['overdue_items'],static fn(array $b):bool=>$b['id']===$stay['id']));$assert(count($overdue)===1&&$overdue[0]['overdue_checkout']);$assert(array_column($calendar['rooms'],'id')===[$room]&&count($calendar['all_rooms'])>1);
    $available=$app->dailyBookings()->availability(['check_in_date'=>$date(1),'check_out_date'=>$date(2),'guests'=>1]);$assert(!in_array($room,array_column($available['items'],'room_id'),true));
    $details=$app->dailyBookings()->guestDetails($row['id'],$row['access_token']);$assert($details['status']==='expired','One-minute hold must expire at checkout even without worker');
});
fwrite(STDOUT,"{$groups} daily booking MySQL groups passed; no external providers\n");
