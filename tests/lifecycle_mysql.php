<?php
declare(strict_types=1);

use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Domain\LineWebhookService;
use Dormitory\Security\Password;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$database=(string)getenv('DB_DATABASE');
if(getenv('APP_ENV')!=='testing'
    ||preg_match('/^(?:appj_|dormitory_test)[A-Za-z0-9_]*$/D',$database)!==1){
    fwrite(STDERR,"Refusing to run lifecycle integration outside a dedicated testing database\n");
    exit(64);
}

/** @var Dormitory\Application $app */
$app=require dirname(__DIR__).'/bootstrap.php';
$pdo=$app->database()->pdo();

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};
$expectHttp=static function(callable $callback,string $code):void{
    try{$callback();}
    catch(HttpException $error){
        if($error->errorCode===$code)return;
        throw new RuntimeException("Expected {$code}; received {$error->errorCode}",0,$error);
    }
    throw new RuntimeException("Expected {$code}; no exception was thrown");
};
$request=static fn(string $suffix):Request=>new Request(
    'POST',
    '/tests/lifecycle/'.$suffix,
    ['user-agent'=>'dormitory-lifecycle-integration'],
    [],
    [],
    [],
    ['REMOTE_ADDR'=>'127.0.0.10'],
    'lifecycle-'.$suffix.'-'.bin2hex(random_bytes(4)),
);

foreach(['admin_users','residents','rooms','bookings','occupancies','bills','payments']as$table){
    $count=(int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    $assert($count===0,"Testing database is not clean: {$table}");
}

$ownerPassword='Owner-Lifecycle-Password-2026!';
$insertOwner=$pdo->prepare("INSERT INTO admin_users
    (username,password_hash,role,auth_version,active,created_by,created_at,updated_at)
    VALUES ('owner_lifecycle',?,'owner',1,1,NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
$insertOwner->execute([Password::hash($ownerPassword)]);
$ownerId=(int)$pdo->lastInsertId();

$app->billing()->updateSettings([
    'water_rate'=>'18.50',
    'electric_rate'=>'7.25',
    'due_days'=>7,
],$ownerId);
$room=$app->rooms()->create([
    'room_code'=>'E2E-101',
    'floor'=>1,
    'room_type'=>'Lifecycle',
    'monthly_rent'=>'4500.00',
    'amenities'=>['integration-test'],
]);

$timezone=new DateTimeZone((string)$app->config->get('APP_TIMEZONE','Asia/Bangkok'));
$today=(new DateTimeImmutable('today',$timezone))->format('Y-m-d');
$period=substr($today,0,7);
$dueDate=(new DateTimeImmutable('today',$timezone))->modify('+7 days')->format('Y-m-d');

// A vacant-room meter row establishes a different monthly baseline. Check-in
// for that same month must fail atomically rather than create an occupancy
// that can never be billed or moved out.
$meterBlockedRoom=$app->rooms()->create([
    'room_code'=>'E2E-METER-BLOCK',
    'floor'=>1,
    'room_type'=>'Lifecycle',
    'monthly_rent'=>'4300.00',
    'amenities'=>['integration-test'],
]);
$app->meters()->record([
    'room_id'=>$meterBlockedRoom['id'],
    'period'=>$period,
    'water_current'=>'50.00',
],$ownerId);
$expectHttp(
    fn()=>$app->bookings()->createAdminResident($ownerId,[
        'room_id'=>$meterBlockedRoom['id'],
        'full_name'=>'Blocked Meter Resident',
        'phone'=>'0823456789',
        'move_in_date'=>$today,
        'opening_water_reading'=>'50.00',
        'opening_electric_reading'=>'60.00',
        'idempotency_key'=>'lifecycle-meter-conflict-0001',
    ]),
    'MOVE_IN_METER_PERIOD_CONFLICT',
);
$blockedCount=$pdo->query("SELECT
    (SELECT COUNT(*) FROM bookings WHERE phone_norm='0823456789')
    +(SELECT COUNT(*) FROM residents WHERE phone_norm='0823456789')
    +(SELECT COUNT(*) FROM occupancies WHERE room_id=".(int)$meterBlockedRoom['id'].")")->fetchColumn();
$assert((int)$blockedCount===0,'Meter-period conflict left partial check-in data');

$checkIn=$app->bookings()->createAdminResident($ownerId,[
    'room_id'=>$room['id'],
    'full_name'=>'Lifecycle Resident',
    'phone'=>'0812345678',
    'email'=>'resident@example.test',
    'move_in_date'=>$today,
    'opening_water_reading'=>'100.00',
    'opening_electric_reading'=>'200.00',
    'idempotency_key'=>'lifecycle-checkin-000001',
]);
$assert(($checkIn['resident_access']['activation_required']??false)===true,'Activation was not issued');
$activation=(string)($checkIn['resident_access']['activation_code']??'');
$assert($activation!=='','Activation code is missing');
$residentId=(int)$checkIn['resident_id'];
$occupancyId=(int)$checkIn['occupancy_id'];

// Move-in replay is bound to the committed request, not the resident's mutable
// profile. Changing email later must neither break the original replay nor make
// a changed payload look like the original operation.
$bookingId=(int)$checkIn['booking_id'];
$storedMoveInHash=$pdo->prepare('SELECT move_in_request_hash FROM bookings WHERE id=?');
$storedMoveInHash->execute([$bookingId]);
$moveInHash=$storedMoveInHash->fetchColumn();
$assert(is_string($moveInHash)
    &&preg_match('/^[0-9a-f]{64}$/D',$moveInHash)===1,
    'Move-in request digest was not stored');
$app->residents()->updateProfile($residentId,[
    'full_name'=>'Lifecycle Resident',
    'email'=>'profile-changed@example.test',
]);
$originalMoveInRequest=[
    'email'=>'resident@example.test',
    'move_in_date'=>$today,
    'opening_water_reading'=>'100.00',
    'opening_electric_reading'=>'200.00',
];
$moveInReplay=$app->bookings()->moveIn($bookingId,$ownerId,$originalMoveInRequest);
$assert(($moveInReplay['idempotent_replay']??false)===true,
    'Original move-in request stopped replaying after profile email changed');
$expectHttp(
    fn()=>$app->bookings()->moveIn($bookingId,$ownerId,array_replace(
        $originalMoveInRequest,
        ['email'=>'profile-changed@example.test'],
    )),
    'MOVE_IN_ALREADY_COMPLETED',
);
$expectHttp(
    fn()=>$app->bookings()->moveIn(
        $bookingId,
        $ownerId,
        array_diff_key($originalMoveInRequest,['email'=>true]),
    ),
    'MOVE_IN_ALREADY_COMPLETED',
);
$hashMutationRejected=false;
try{
    $mutateHash=$pdo->prepare('UPDATE bookings SET move_in_request_hash=? WHERE id=?');
    $mutateHash->execute([str_repeat('a',64),$bookingId]);
}catch(PDOException){
    $hashMutationRejected=true;
}
$assert($hashMutationRejected,'Committed move-in request digest remained mutable');

$firstLogin=$app->auth()->residentLogin($request('phone-first'),['phone'=>'0812345678']);
$assert($firstLogin['auth_method']==='phone'&&$firstLogin['assurance']==='low'&&!$firstLogin['phone_verified'],'Phone access must not claim verified ownership');
$app->auth()->logout($request('logout-first'));
$phoneLogin=$app->auth()->residentLogin($request('phone-repeat'),['phone'=>'+66812345678']);
$assert($phoneLogin['auth_method']==='phone','Phone-only repeat login failed');
$residentAuthVersion=(int)$phoneLogin['auth_version'];
$assert($residentAuthVersion>0,'Resident auth version is missing');

// Configure non-production credentials locally. No provider request is made:
// the test invokes the webhook decision path before the HTTP reply step.
$lineSettings=$app->settings()->update([
    'line_basic_id'=>'@dormflowtest',
    'line_channel_access_token'=>'integration-line-token-visible-ascii-2026',
    'line_channel_secret'=>'integration-line-secret-visible-ascii-2026',
],$ownerId);
$assert(($lineSettings['line_webhook_ready']??false)===true,'LINE webhook settings are not ready');
$assert(($lineSettings['line_binding_ready']??false)===true,'LINE resident binding settings are not ready');
$expectHttp(
    fn()=>$app->lineBindings()->issue($residentId,$residentAuthVersion+1),
    'RESIDENT_SESSION_STALE',
);
$assert((int)$pdo->query("SELECT COUNT(*) FROM line_link_codes WHERE resident_id={$residentId}")->fetchColumn()===0,'Stale resident session created a LINE code');

// A short-lived code is shown once, while MySQL stores only a keyed HMAC.
$expiredCode=$app->lineBindings()->issue($residentId,$residentAuthVersion);
$assert(preg_match('/^BIND-[A-F0-9]{32}$/D',(string)$expiredCode['code'])===1,'LINE link code format is invalid');
$storedCode=$pdo->prepare("SELECT id,code_hash,status,expires_at FROM line_link_codes WHERE resident_id=? ORDER BY id DESC LIMIT 1");
$storedCode->execute([$residentId]);
$expiredRow=$storedCode->fetch();
$assert($expiredRow
    &&strlen((string)$expiredRow['code_hash'])===64
    &&preg_match('/^[0-9a-f]{64}$/D',(string)$expiredRow['code_hash'])===1
    &&!hash_equals((string)$expiredRow['code_hash'],(string)$expiredCode['code']),
    'LINE link code was not stored as a digest');
$rawColumns=$pdo->query("SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='line_link_codes'
      AND column_name IN ('code','raw_code','plain_code')")->fetchColumn();
$assert((int)$rawColumns===0,'LINE link table exposes a plaintext-code column');

// Force expiry without violating the table's chronological CHECK, then prove
// that consume commits the expired state before returning the safe error.
$forceExpiry=$pdo->prepare("UPDATE line_link_codes
    SET expires_at=DATE_ADD(created_at,INTERVAL 1 MICROSECOND)
    WHERE id=? AND status='pending'");
$forceExpiry->execute([(int)$expiredRow['id']]);
$expectHttp(
    fn()=>$app->lineBindings()->consume((string)$expiredCode['code'],'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
    'LINE_LINK_CODE_EXPIRED',
);
$expiredStatus=$pdo->prepare('SELECT status FROM line_link_codes WHERE id=?');
$expiredStatus->execute([(int)$expiredRow['id']]);
$assert($expiredStatus->fetchColumn()==='expired','Expired LINE link code remained pending');

$lineCode=$app->lineBindings()->issue($residentId,$residentAuthVersion);
$assert((int)$pdo->query("SELECT COUNT(*) FROM line_link_codes WHERE resident_id={$residentId} AND status='pending'")->fetchColumn()===1,'Resident has more than one pending LINE code');
$lineUserId='U0123456789abcdef0123456789abcdef';
$webhookService=new LineWebhookService($app);
$allowanceDecision=new ReflectionMethod(LineWebhookService::class,'consumeReplyAllowance');
$rateBucketCountBefore=(int)$pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn();
$allowanceCandidate=[
    'event_id'=>'01ARZ3NDEKTSV4RRFFQ69G5FAW',
    'event_type'=>'message',
    'line_user_id'=>$lineUserId,
    'reply_token'=>'integration_allowance_token_2026',
    'message_text'=>'help',
];
$assert($allowanceDecision->invoke($webhookService,$allowanceCandidate,'instructions')==='allowed','First LINE instruction allowance was denied');
$assert($allowanceDecision->invoke($webhookService,$allowanceCandidate,'instructions')==='user','Second LINE instruction did not hit the per-user cooldown');
$allowanceCandidate['message_text']='BIND-'.str_repeat('A',32);
$assert($allowanceDecision->invoke($webhookService,$allowanceCandidate,'bind')==='allowed','BIND allowance was not isolated from instruction replies');
$rateBucketCountAfter=(int)$pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn();
$assert($rateBucketCountAfter===$rateBucketCountBefore+4,'LINE webhook did not create four distinct user/global purpose buckets');
$rawRateBucket=$pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket_key LIKE ?');
$rawRateBucket->execute(['%'.$lineUserId.'%']);
$assert((int)$rawRateBucket->fetchColumn()===0,'Raw LINE user ID leaked into a rate-limit bucket');

$replyDecision=new ReflectionMethod(LineWebhookService::class,'replyTextForCandidate');
$candidate=[
    'event_id'=>'01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'event_type'=>'message',
    'line_user_id'=>$lineUserId,
    'reply_token'=>'integration_reply_token_2026',
    'message_text'=>(string)$lineCode['code'],
];
$bindReply=$replyDecision->invoke($webhookService,$request('line-bind'),$candidate);
$assert(($bindReply['outcome']??null)==='bound','Webhook decision path did not bind LINE');
$boundCode=$pdo->prepare("SELECT code_hash,status,line_user_id,bound_at FROM line_link_codes WHERE resident_id=? ORDER BY id DESC LIMIT 1");
$boundCode->execute([$residentId]);
$boundRow=$boundCode->fetch();
$assert($boundRow
    &&$boundRow['status']==='bound'
    &&$boundRow['line_user_id']===$lineUserId
    &&is_string($boundRow['bound_at'])
    &&$boundRow['bound_at']!=='',
    'LINE binding was not finalized atomically');
$assert($app->notifications()->isLineBindingVerified($residentId,$lineUserId),'LINE binding audit proof is missing');
$auditLeak=$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE details LIKE '%BIND-%'")->fetchColumn();
$assert((int)$auditLeak===0,'One-time LINE code leaked into audit logs');
$repeatReply=$replyDecision->invoke($webhookService,$request('line-bind-repeat'),$candidate);
$assert(($repeatReply['outcome']??null)==='already_bound','LINE binding replay is not idempotent');
$differentLineCandidate=$candidate;
$differentLineCandidate['line_user_id']='Ubbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$differentLineReply=$replyDecision->invoke($webhookService,$request('line-bind-other-line'),$differentLineCandidate);
$assert(($differentLineReply['outcome']??null)==='invalid_or_expired','Used LINE code was accepted by another account');

// The resident-level advisory lock and residents.line_user_id unique key must
// prevent one LINE account from being attached to two active residents.
$otherRoom=$app->rooms()->create([
    'room_code'=>'E2E-LINE-CONFLICT',
    'floor'=>2,
    'room_type'=>'Lifecycle',
    'monthly_rent'=>'4200.00',
    'amenities'=>['integration-test'],
]);
$otherCheckIn=$app->bookings()->createAdminResident($ownerId,[
    'room_id'=>$otherRoom['id'],
    'full_name'=>'LINE Conflict Resident',
    'phone'=>'0867890123',
    'move_in_date'=>$today,
    'opening_water_reading'=>'1.00',
    'opening_electric_reading'=>'2.00',
    'idempotency_key'=>'lifecycle-line-conflict-0001',
]);
$otherResidentId=(int)$otherCheckIn['resident_id'];
$otherAuthVersionStatement=$pdo->prepare('SELECT auth_version FROM residents WHERE id=?');
$otherAuthVersionStatement->execute([$otherResidentId]);
$otherAuthVersion=(int)$otherAuthVersionStatement->fetchColumn();

// Reissuing a resident credential must invalidate every code created by the
// previous session. Issuing a replacement code must also revoke its predecessor.
$supersededCode=$app->lineBindings()->issue($otherResidentId,$otherAuthVersion);
$preReissueCode=$app->lineBindings()->issue($otherResidentId,$otherAuthVersion);
$assert((int)$pdo->query("SELECT COUNT(*) FROM line_link_codes WHERE resident_id={$otherResidentId} AND status='pending'")->fetchColumn()===1,'LINE code replacement left multiple pending codes');
$expectHttp(
    fn()=>$app->lineBindings()->consume((string)$supersededCode['code'],'Ucccccccccccccccccccccccccccccccc'),
    'LINE_LINK_CODE_INVALID',
);
$app->notifications()->withLineBindingLock(
    $otherResidentId,
    fn():array=>$app->residents()->reissueAccess($otherResidentId),
);
$expectHttp(
    fn()=>$app->lineBindings()->consume((string)$preReissueCode['code'],'Ucccccccccccccccccccccccccccccccc'),
    'LINE_LINK_CODE_INVALID',
);
$assert((int)$pdo->query("SELECT COUNT(*) FROM line_link_codes WHERE resident_id={$otherResidentId} AND status='revoked'")->fetchColumn()===2,'Credential reissue did not revoke pending LINE codes');
$otherAuthVersionStatement->execute([$otherResidentId]);
$otherCode=$app->lineBindings()->issue($otherResidentId,(int)$otherAuthVersionStatement->fetchColumn());
$lineConflictCandidate=$candidate;
$lineConflictCandidate['event_id']='01ARZ3NDEKTSV4RRFFQ69G5FCX';
$lineConflictCandidate['message_text']=(string)$otherCode['code'];
$lineConflictReply=$replyDecision->invoke($webhookService,$request('line-bind-conflict'),$lineConflictCandidate);
$assert(($lineConflictReply['outcome']??null)==='line_in_use','One LINE account was accepted for two residents');
$otherState=$pdo->prepare("SELECT r.line_user_id,c.status
    FROM residents r JOIN line_link_codes c ON c.resident_id=r.id
    WHERE r.id=? ORDER BY c.id DESC LIMIT 1");
$otherState->execute([$otherResidentId]);
$otherRow=$otherState->fetch();
$assert($otherRow&&$otherRow['line_user_id']===null&&$otherRow['status']==='pending','LINE conflict left a partial binding');
$app->lineBindings()->revokePending($otherResidentId);

$reading=$app->meters()->record([
    'room_id'=>$room['id'],
    'period'=>$period,
    'water_current'=>'112.00',
    'electric_current'=>'225.00',
],$ownerId);
$assert(($reading['water']['previous']??null)==='100.00','Water opening baseline is incorrect');
$assert(($reading['electric']['previous']??null)==='200.00','Electric opening baseline is incorrect');
$assert((int)($reading['occupancy_id']??0)===$occupancyId,'Meter was not bound to occupancy');

// The database trigger must reject a broken next-month chain even if a
// caller bypasses MeterService.
$nextPeriod=(new DateTimeImmutable($period.'-01',new DateTimeZone('UTC')))
    ->modify('first day of next month')
    ->format('Y-m-01');
$pdo->beginTransaction();
$brokenHistoryRejected=false;
try{
    $invalid=$pdo->prepare("INSERT INTO meter_readings
        (room_id,occupancy_id,meter_type,period,previous_reading,current_reading,
         units_used,recorded_by,created_at,updated_at)
        VALUES (?,?,'water',?,'999.00','1000.00','1.00',?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
    $invalid->execute([$room['id'],$occupancyId,$nextPeriod,$ownerId]);
}catch(PDOException){
    $brokenHistoryRejected=true;
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
}
$assert($brokenHistoryRejected,'Broken meter history was accepted');

// A runtime writer cannot create a bill as already paid and bypass verified
// payment evidence, even when all occupancy and meter snapshots are valid.
$pdo->beginTransaction();
$paidInsertRejected=false;
try{
    $invalidPaid=$pdo->prepare("INSERT INTO bills
        (bill_no,occupancy_id,resident_id,room_id,resident_name_snapshot,
         room_code_snapshot,period,due_date,rent_amount,
         water_previous,water_current,water_units,water_rate,water_amount,
         electric_previous,electric_current,electric_units,electric_rate,
         electric_amount,other_description,other_amount,total_amount,
         status,paid_at,created_by,created_at)
        VALUES
        ('BILL-DIRECT-PAID-REJECT',?,?,?,'Lifecycle Resident','E2E-101',?,?,
         '4500.00','100.00','112.00','12.00','18.50','222.00',
         '200.00','225.00','25.00','7.25','181.25',
         NULL,'0.00','4903.25','paid',UTC_TIMESTAMP(6),?,UTC_TIMESTAMP(6))");
    $invalidPaid->execute([
        $occupancyId,$residentId,$room['id'],$period.'-01',$dueDate,$ownerId,
    ]);
}catch(PDOException){
    $paidInsertRejected=true;
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
}
$assert($paidInsertRejected,'Direct paid bill insert bypassed verified payment evidence');

$expectHttp(
    fn()=>$app->billing()->preview([
        'period'=>$period,
        'room_ids'=>[$room['id']],
        'due_date'=>$dueDate,
    ]),
    'CURRENT_BILLING_PERIOD_NOT_FINALIZED',
);
$preview=$app->billing()->preview([
    'period'=>$period,
    'room_ids'=>[$room['id']],
    'due_date'=>$dueDate,
    'confirm_current_period'=>true,
]);
$assert($preview['issues']===[]&&count($preview['bills'])===1,'Bill preview is not ready');
$issued=$app->billing()->bulk([
    'period'=>$period,
    'room_ids'=>[$room['id']],
    'due_date'=>$dueDate,
    'confirm_current_period'=>true,
    'preview_token'=>$preview['preview_token'],
],$ownerId);
$assert(count($issued['created'])===1,'Bill was not created exactly once');
$replayed=$app->billing()->bulk([
    'period'=>$period,
    'room_ids'=>[$room['id']],
    'due_date'=>$dueDate,
    'confirm_current_period'=>true,
    'preview_token'=>$preview['preview_token'],
],$ownerId);
$assert(count($replayed['created'])===0&&count($replayed['skipped'])===1,'Bill replay is not idempotent');
$billId=(int)$issued['created'][0]['id'];
$queued=$app->notifications()->enqueueBill($billId);
$assert(($queued['enqueue_state']??null)==='newly_queued','Verified LINE recipient was not queued for bill delivery');
$queuedRecipient=$pdo->prepare("SELECT recipient,status FROM notification_outbox WHERE bill_id=? AND purpose='bill_delivery'");
$queuedRecipient->execute([$billId]);
$queuedRow=$queuedRecipient->fetch();
$assert($queuedRow&&$queuedRow['recipient']===$lineUserId&&$queuedRow['status']==='pending','LINE bill outbox recipient is incorrect');

// Reproduce the queue race deterministically: the reminder was queued while
// unpaid, then a slip became pending before the worker claimed the row. The
// worker must cancel without calling LINE, and manual/bulk enqueue paths must
// also refuse to send another payment reminder.
$paymentId=$app->database()->transaction(function(PDO $pdo)use($billId,$residentId):int{
    $bill=$pdo->prepare('SELECT total_amount FROM bills WHERE id=? FOR UPDATE');
    $bill->execute([$billId]);
    $amount=$bill->fetchColumn();
    if($amount===false)throw new RuntimeException('Bill disappeared');
    $payment=$pdo->prepare("INSERT INTO payments
        (bill_id,resident_id,amount,status,slip_path,slip_mime,slip_hmac,
         verification_attempts,created_at,updated_at)
        VALUES (?, ?, ?, 'pending', 'tests/offline-evidence.jpg', 'image/jpeg', ?, 1,
                UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
    $payment->execute([$billId,$residentId,$amount,str_repeat('a',64)]);
    $paymentId=(int)$pdo->lastInsertId();
    return $paymentId;
});
$workerResult=$app->notifications()->process(1);
$assert(($workerResult['processed']??0)===1
    &&($workerResult['sent']??0)===0
    &&($workerResult['failed']??0)===1,
    'Worker sent a duplicate reminder after a payment became pending');
$cancelledOutbox=$pdo->prepare("SELECT status,last_error FROM notification_outbox WHERE bill_id=? AND purpose='bill_delivery'");
$cancelledOutbox->execute([$billId]);$cancelled=$cancelledOutbox->fetch();
$assert($cancelled&&$cancelled['status']==='failed'
    &&str_contains((string)$cancelled['last_error'],'payment slip is pending review'),
    'Payment-aware worker did not terminally cancel the queued reminder');
$expectHttp(fn()=>$app->notifications()->enqueueBill($billId),'BILL_PAYMENT_PENDING');
$periodQueue=$app->notifications()->enqueuePeriod(['period'=>$period]);
$assert(count($periodQueue['queued']??[])===0
    &&in_array('BILL_PAYMENT_PENDING',array_column($periodQueue['skipped']??[],'code'),true),
    'Bulk LINE enqueue did not skip a bill with a pending payment');

// Simulate only the already-verified provider result so this test remains
// offline. The same relationship/state triggers used by PaymentService still
// gate the transition to paid.
$app->database()->transaction(function(PDO $pdo)use($billId,$paymentId):void{
    $verified=$pdo->prepare("UPDATE payments SET
        status='verified',provider='offline-e2e',transaction_ref=?,
        receiver_ref='verified-test-receiver',provider_payload=JSON_OBJECT('offline',TRUE),
        rejection_reason=NULL,verified_at=UTC_TIMESTAMP(6),
        verification_lease_until=NULL,verification_token=NULL,updated_at=UTC_TIMESTAMP(6)
        WHERE id=? AND status='pending'");
    $verified->execute(['E2E-'.bin2hex(random_bytes(8)),$paymentId]);
    if($verified->rowCount()!==1)throw new RuntimeException('Payment was not finalized');
    $paid=$pdo->prepare("UPDATE bills SET status='paid',paid_at=UTC_TIMESTAMP(6)
        WHERE id=? AND status='pending'");
    $paid->execute([$billId]);
    if($paid->rowCount()!==1)throw new RuntimeException('Bill was not paid');
});
$adminBill=array_values(array_filter(
    $app->billing()->adminList($period),
    static fn(array $bill):bool=>(int)$bill['id']===$billId,
))[0]??null;
$assert($adminBill&&($adminBill['payment_status']??null)==='verified',
    'Admin bill list did not expose the latest verified payment status');

$healthCycle=[
    'processed'=>0,'sent'=>0,'failed'=>0,'retried'=>0,'lost_claims'=>0,'recovered'=>0,
];
$app->notifications()->recordWorkerHeartbeat('lifecycle-worker','running',$healthCycle);
$workerHealth=$app->notifications()->workerHealth();
$assert(($workerHealth['worker_live']??false)===true,'Worker heartbeat is not live');

$moveOut=$app->residents()->moveOut($residentId,['move_out_date'=>$today]);
$assert(($moveOut['status']??null)==='ended','Move-out did not complete');
$assert($app->rooms()->find((int)$room['id'])['status']==='available','Room did not return to available');
$assert($app->actor(true)===null,'Move-out did not revoke the resident session');

$stored=$pdo->prepare('SELECT active,access_password_hash,activation_code_hash FROM residents WHERE id=?');
$stored->execute([$residentId]);
$residentRow=$stored->fetch();
$assert($residentRow
    &&(int)$residentRow['active']===0
    &&$residentRow['access_password_hash']===null
    &&$residentRow['activation_code_hash']===null,'Move-out did not retire resident credentials');

fwrite(STDOUT,"PASS MySQL lifecycle: check-in, phone-only access, meter, billing, payment and move-out\n");
