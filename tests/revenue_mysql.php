<?php
declare(strict_types=1);

// This test can only touch an explicitly opted-in fresh LOCAL disposable schema.
// It never loads .env or calls a provider, and leaves fixture rows for inspection.
$database = (string) getenv('DB_DATABASE');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('REVENUE_MYSQL_TEST') !== '1'
    || preg_match('/^appj_revenue_[a-z0-9_]+$/D', $database) !== 1
    || !in_array((string) getenv('DB_HOST'), ['127.0.0.1','localhost'], true)) exit(64);
spl_autoload_register(static function (string $name): void {
    if (str_starts_with($name, 'Dormitory\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($name, 10)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
use Dormitory\Application;
use Dormitory\Config;
use Dormitory\Domain\RevenueService;
use Dormitory\Http\HttpException;
use Dormitory\Security\Password;
use Dormitory\Support\Validator;

foreach (['APP_URL'=>'http://127.0.0.1','APP_KEY'=>'revenue-fixture-secret-key-2026-at-least-32-bytes','APP_TIMEZONE'=>'Asia/Bangkok','APP_DEBUG'=>'false','FORCE_HTTPS'=>'false','RUNTIME_ROLE'=>'job','DB_SSL'=>'false'] as $key => $value) putenv($key . '=' . $value);
$configClass = new ReflectionClass(Config::class);
$config = $configClass->newInstanceWithoutConstructor();
$configClass->getConstructor()->invoke($config, [], dirname(__DIR__));
$config->configurePhp();
$app = new Application($config);
$pdo = $app->database()->pdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) throw new RuntimeException('Unexpected test database');
foreach (['admin_users','rooms','bookings','occupancies','bills','bill_items','payments','daily_bookings','daily_payments','daily_refunds','daily_deposit_settlements'] as $table) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) throw new RuntimeException('Fresh disposable revenue schema required');
}
$checks = 0;
$assert = static function (bool $ok, string $why = 'Assertion failed') use (&$checks): void { if (!$ok) throw new RuntimeException($why); $checks++; };
$groups = 0;
$test = static function (string $name, callable $fn) use (&$groups): void { $fn(); $groups++; echo "PASS {$name}\n"; };
$expect = static function (callable $fn, string $code) use ($assert): void { try { $fn(); } catch (HttpException $e) { $assert($e->errorCode === $code, 'Wrong error code: ' . $e->errorCode); return; } throw new RuntimeException('Expected ' . $code); };
$password = Password::hash('Revenue-Disposable-Fixture-2026!');
$q = $pdo->prepare("INSERT INTO admin_users(username,password_hash,role,active,auth_version) VALUES('revenue_owner',?,'owner',1,1)");
$q->execute([$password]); $owner = (int) $pdo->lastInsertId();
$q = $pdo->prepare("INSERT INTO admin_users(username,password_hash,role,active,auth_version,retired_at) VALUES(?,?,'owner',0,1,?)");
$q->execute(['revenue_inactive',$password,null]); $inactive = (int) $pdo->lastInsertId();
$q->execute(['revenue_retired',$password,'2020-01-01 00:00:00']); $retired = (int) $pdo->lastInsertId();
$clock = new DateTimeImmutable((string) $pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn(), new DateTimeZone('UTC'));
$local = $clock->setTimezone(new DateTimeZone('Asia/Bangkok'));
$today = $local->format('Y-m-d'); $period = $local->format('Y-m');
$startLocal = new DateTimeImmutable($period . '-01 00:00:00', new DateTimeZone('Asia/Bangkok'));
$start = $startLocal->setTimezone(new DateTimeZone('UTC'));
$end = $startLocal->modify('+1 month')->setTimezone(new DateTimeZone('UTC'));
$stamp = static fn(DateTimeImmutable $at): string => $at->format('Y-m-d H:i:s.u');
$monthPrevious = $startLocal->modify('-1 month')->format('Y-m');
$monthNext = $startLocal->modify('+1 month')->format('Y-m');
$service = $app->revenue();
$app->billing()->updateSettings(['water_rate'=>'3.17','electric_rate'=>'4.11','due_days'=>7], $owner);
$monthlySequence = 0;
$monthly = function (string $at, string $status = 'verified', ?string $adjustment = null, ?string $billPeriod = null) use ($app, $pdo, $owner, $period, $startLocal, &$monthlySequence): array {
    $monthlySequence++; $n = $monthlySequence; $billPeriod ??= $period;
    $room = $app->rooms()->create(['room_code'=>'REV-M-' . $n,'floor'=>1,'room_type'=>'Fixture','monthly_rent'=>'100.01']);
    $moveIn = $billPeriod === $period ? $billPeriod . '-01' : $startLocal->modify('-1 day')->format('Y-m-d');
    $resident = $app->bookings()->createAdminResident($owner, ['room_id'=>$room['id'],'full_name'=>'Revenue monthly fixture ' . $n,'phone'=>'089880' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),'move_in_date'=>$moveIn,'opening_water_reading'=>'0','opening_electric_reading'=>'0','idempotency_key'=>'revenue-monthly-movein-' . $n]);
    $app->meters()->record(['room_id'=>$room['id'],'period'=>$billPeriod,'water_current'=>'2','electric_current'=>'3'], $owner);
    $input = ['period'=>$billPeriod,'room_ids'=>[$room['id']],'confirm_current_period'=>true,'other_description'=>'Fixture other charge','other_amount'=>'5.07'];
    $preview = $app->billing()->preview($input);
    $bill = $app->billing()->bulk($input + ['preview_token'=>$preview['preview_token']], $owner)['created'][0];
    $transfer = $bill['total_amount'];
    if ($adjustment !== null) {
        $transfer = Validator::decimalString(Validator::scaledDecimal($bill['total_amount'],'principal',2,12) + Validator::scaledDecimal($adjustment,'adjustment'));
        $pdo->prepare("INSERT INTO payment_amount_registry(subject_type,subject_id,transfer_amount) VALUES('monthly',?,?)")->execute([$bill['id'],$transfer]);
        $pdo->prepare("INSERT INTO transfer_instructions(bill_id,resident_id,bill_amount,adjustment_amount,transfer_amount,promptpay_target) VALUES(?,?,?,?,?,'0812345678')")->execute([$bill['id'],$resident['resident_id'],$bill['total_amount'],$adjustment,$transfer]);
    }
    $hmac = hash('sha256','revenue-monthly-' . $n);
    $pdo->prepare("INSERT INTO payments(bill_id,resident_id,amount,status,slip_path,slip_mime,slip_hmac) VALUES(?,?,?,'pending','storage/private/slips/revenue-fixture.jpg','image/jpeg',?)")->execute([$bill['id'],$resident['resident_id'],$bill['total_amount'],$hmac]);
    $payment = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payment_evidence_registry(subject_type,subject_id,slip_hmac) VALUES('monthly',?,?)")->execute([$payment,$hmac]);
    if ($status === 'verified') {
        $pdo->prepare("UPDATE payment_evidence_registry SET transaction_ref=? WHERE subject_type='monthly' AND subject_id=?")->execute(['REV-M-BANK-' . $n,$payment]);
        $pdo->prepare("UPDATE payments SET status='verified',transaction_ref=?,verified_at=?,provider_payload=? WHERE id=?")->execute(['REV-M-BANK-' . $n,$at,json_encode(['amount'=>$transfer], JSON_THROW_ON_ERROR),$payment]);
        $pdo->prepare("UPDATE bills SET status='paid',paid_at=? WHERE id=?")->execute([$at,$bill['id']]);
        if ($adjustment !== null) {
            $pdo->prepare("UPDATE transfer_instructions SET status='settled',settled_at=UTC_TIMESTAMP(6) WHERE bill_id=?")->execute([$bill['id']]);
            $pdo->prepare("UPDATE payment_amount_registry SET status='settled',settled_at=UTC_TIMESTAMP(6) WHERE subject_type='monthly' AND subject_id=?")->execute([$bill['id']]);
        }
    } elseif ($status === 'rejected') {
        $pdo->prepare("UPDATE payments SET status='rejected',rejection_reason='Fixture rejected proof' WHERE id=?")->execute([$payment]);
        $pdo->prepare("UPDATE payment_evidence_registry SET claim_status='released' WHERE subject_type='monthly' AND subject_id=?")->execute([$payment]);
    }
    return ['room'=>$room['id'],'occupancy'=>$resident['occupancy_id'],'bill'=>$bill['id'],'payment'=>$payment,'principal'=>$bill['total_amount']];
};
$before = $monthly($stamp($start->modify('-1 microsecond')));
$atStart = $monthly($stamp($start), 'verified', '0.37', $monthPrevious);
$atLast = $monthly($stamp($end->modify('-1 microsecond')));
$atEnd = $monthly($stamp($end));
$monthly($stamp($start->modify('+1 hour')), 'pending');
$monthly($stamp($start->modify('+2 hours')), 'rejected');

$test('monthly verified collections use local UTC boundaries, bill items and immutable actual transfer cents without fanout', function () use ($service,$period,$owner,$assert,$monthPrevious,$monthNext): void {
    $r = $service->monthly($period,$owner); $s = $r['summary'];
    $assert($s['verified_payment_count'] === 2 && $s['gross_receipts'] === '247.87');
    $assert($s['collected_charges'] === '247.50' && $s['bank_adjustment'] === '0.37');
    $assert($s['rent_collections'] === '200.02' && $s['water_collections'] === '12.68' && $s['electric_collections'] === '24.66' && $s['other_collections'] === '10.14');
    $assert($s['earlier_bill_period_collections'] === '123.75' && $s['net_cash_flow'] === '247.87');
    $assert(count($r['events']['items']) === 2 && $r['events']['items'][1]['amount'] === '124.12' && $r['events']['items'][1]['charges_amount'] === '123.75');
    $assert($service->monthly($monthPrevious,$owner)['summary']['verified_payment_count'] === 1);
    $assert($service->monthly($monthNext,$owner)['summary']['verified_payment_count'] === 1);
    $assert($r['basis']['earned_revenue_calculated'] === false && !isset($r['summary']['security_deposit_collections']));
});
$test('historical monthly collections survive ended occupancy and room conversion to daily', function () use ($pdo,$atStart,$startLocal,$service,$period,$owner,$assert): void {
    $pdo->prepare("UPDATE occupancies SET status='ended',move_out_date=? WHERE id=?")->execute([$startLocal->modify('-1 day')->format('Y-m-d'),$atStart['occupancy']]);
    $pdo->prepare("UPDATE rooms SET rental_mode='daily',daily_rate='950.00',monthly_rent='0.00' WHERE id=?")->execute([$atStart['room']]);
    $r = $service->monthly($period,$owner);
    $assert($r['summary']['gross_receipts'] === '247.87' && $r['summary']['rent_collections'] === '200.02');
    $assert($r['events']['items'][1]['room_code'] === 'REV-M-2');
});

$dailySequence = 0;
$daily = function (string $at, string $method = 'cash', string $status = 'verified', string $bookingState = 'confirmed', string $adjustment = '0.37', bool $future = false) use ($app,$pdo,$owner,$today,$local,&$dailySequence): array {
    $dailySequence++; $n = $dailySequence;
    $room = $app->rooms()->create(['room_code'=>'REV-D-' . $n,'floor'=>1,'room_type'=>'Fixture','rental_mode'=>'daily','daily_rate'=>'100.01','daily_deposit'=>'20.02','max_guests'=>2]);
    $in = $future ? $local->modify('+30 days')->format('Y-m-d') : $today;
    $out = (new DateTimeImmutable($in))->modify('+1 day')->format('Y-m-d');
    $pdo->prepare("INSERT INTO daily_bookings(reference_no,room_id,full_name,phone_norm,check_in_date,check_out_date,guests,nightly_rate,room_amount,deposit_amount,total_amount,expires_at,access_token_hash,idempotency_key,request_hash) VALUES(?,?,'Revenue daily fixture','0899900011',?,?,1,'100.01','100.01','20.02','120.03','2099-01-01 00:00:00',?,?,?)")->execute(['REV-DAY-' . $n,$room['id'],$in,$out,hash('sha256','access-' . $n),'revenue-daily-booking-' . $n,hash('sha256','request-' . $n)]);
    $id = (int) $pdo->lastInsertId();
    if ($method === 'cash') {
        $pdo->prepare("INSERT INTO daily_payments(booking_id,amount,transfer_amount,method,status,request_key,receipt_reference,recorded_by,provider_payload,verified_at) VALUES(?,'120.03','120.03','cash','verified',?,?,?,JSON_OBJECT('reason','Cash fixture received'),?)")->execute([$id,'revenue-cash-' . $n,'REV-CASH-' . $n,$owner,$at]);
        $payment = (int) $pdo->lastInsertId();
    } else {
        $amount = Validator::decimalString(12003 + Validator::scaledDecimal($adjustment,'adjustment'));
        $pdo->prepare("INSERT INTO payment_amount_registry(subject_type,subject_id,transfer_amount) VALUES('daily',?,?)")->execute([$id,$amount]);
        $pdo->prepare("INSERT INTO daily_transfer_instructions(booking_id,booking_amount,adjustment_amount,transfer_amount,promptpay_target) VALUES(?,'120.03',?,?,'0812345678')")->execute([$id,$adjustment,$amount]);
        $hmac = hash('sha256','revenue-daily-' . $n);
        $pdo->prepare("INSERT INTO daily_payments(booking_id,amount,transfer_amount,method,status,slip_path,slip_mime,slip_hmac) VALUES(?,'120.03',?,'slip','pending','storage/private/slips/revenue-fixture.jpg','image/jpeg',?)")->execute([$id,$amount,$hmac]);
        $payment = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payment_evidence_registry(subject_type,subject_id,slip_hmac) VALUES('daily',?,?)")->execute([$payment,$hmac]);
        if ($status === 'verified') {
            $reference = 'REV-D-BANK-' . $n;
            $pdo->prepare("UPDATE payment_evidence_registry SET transaction_ref=? WHERE subject_type='daily' AND subject_id=?")->execute([$reference,$payment]);
            $pdo->prepare("UPDATE daily_payments SET status='verified',transaction_ref=?,verified_at=? WHERE id=?")->execute([$reference,$at,$payment]);
            $pdo->prepare("UPDATE payment_amount_registry SET status='settled',settled_at=UTC_TIMESTAMP(6) WHERE subject_type='daily' AND subject_id=?")->execute([$id]);
        } elseif ($status === 'rejected' || $status === 'closed') {
            $pdo->prepare('UPDATE daily_payments SET status=?,rejection_reason=? WHERE id=?')->execute([$status,'Fixture uncredited proof',$payment]);
            if ($status === 'rejected') $pdo->prepare("UPDATE payment_evidence_registry SET claim_status='released' WHERE subject_type='daily' AND subject_id=?")->execute([$payment]);
        }
    }
    if ($status === 'verified') {
        if ($bookingState === 'expired') $pdo->prepare("UPDATE daily_bookings SET status='expired',version=2,closed_at=UTC_TIMESTAMP(6),close_reason='Expired fixture' WHERE id=?")->execute([$id]);
        else {
            $pdo->prepare("UPDATE daily_bookings SET status='confirmed',version=2,confirmed_at=? WHERE id=?")->execute([$at,$id]);
            if ($bookingState === 'checked_in') $pdo->prepare("UPDATE daily_bookings SET status='checked_in',version=3,actual_check_in_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$id]);
        }
    }
    return ['id'=>$id,'payment'=>$payment,'room'=>$room['id']];
};
$cash = $daily($stamp($start), 'cash', 'verified', 'confirmed', '0.37', true);
$bank = $daily($stamp($end->modify('-1 microsecond')), 'slip', 'verified', 'checked_in', '0.37');
$old = $daily($stamp($start->modify('-1 microsecond')), 'cash', 'verified', 'checked_in');
$daily($stamp($end), 'cash');
$closed = $daily($stamp($start->modify('+1 hour')), 'slip', 'verified', 'expired', '0.41');
$daily($stamp($start->modify('+2 hours')), 'slip', 'pending', 'pending', '0.51');
$daily($stamp($start->modify('+3 hours')), 'slip', 'rejected', 'pending', '0.52');
$daily($stamp($start->modify('+4 hours')), 'slip', 'closed', 'pending', '0.53');
$refund = static function (array $b, string $amount, string $purpose, string $suffix, string $at) use ($pdo,$owner): void {
    $pdo->prepare('INSERT INTO daily_refunds(booking_id,payment_id,amount,purpose,request_key,reference_no,reason,recorded_by,created_at) VALUES(?,?,?,?,?,?,?, ?,?)')->execute([$b['id'],$b['payment'],$amount,$purpose,'revenue-refund-key-' . $suffix,'REV-REFUND-' . $suffix,'Actual fixture refund',$owner,$at]);
};
$refund($bank,'2.00','deposit','bank1',$stamp($start->modify('+1 hour')));
$refund($bank,'3.00','deposit','bank2',$stamp($start->modify('+2 hours')));
$refund($old,'5.00','deposit','old',$stamp($start->modify('+3 hours')));
$refund($closed,'120.44','cancellation','closed',$stamp($start->modify('+4 hours')));
$pdo->prepare('INSERT INTO daily_deposit_settlements(booking_id,retained_amount,request_key,reason,recorded_by,created_at) VALUES(?,?,?,?,?,?)')->execute([$bank['id'],'7.01','revenue-retain-bank','Fixture damage retention',$owner,$stamp($start->modify('+5 hours'))]);
$pdo->prepare('INSERT INTO daily_deposit_settlements(booking_id,retained_amount,request_key,reason,recorded_by,created_at) VALUES(?,?,?,?,?,?)')->execute([$old['id'],'3.00','revenue-retain-old','Fixture damage retention',$owner,$stamp($start->modify('+6 hours'))]);

$test('daily receipts, partial refunds and damage retention never fan out and include old-payment refunds in their own month', function () use ($service,$period,$owner,$assert): void {
    $r = $service->daily($period,$owner); $s = $r['summary'];
    $assert($s['verified_payment_count'] === 3 && $s['gross_receipts'] === '360.87');
    $assert($s['room_collections'] === '300.03' && $s['security_deposit_collections'] === '60.06' && $s['bank_adjustment'] === '0.78');
    $assert($s['cash_receipts'] === '120.03' && $s['bank_receipts'] === '240.84');
    $assert($s['refund_count'] === 4 && $s['deposit_refunds'] === '10.00' && $s['cancellation_refunds'] === '120.44' && $s['refunds_total'] === '130.44');
    $assert($s['damage_retention'] === '10.01' && $s['retention_count'] === 2 && $s['net_cash_flow'] === '230.43');
    $assert(count($r['events']['items']) === 9 && !$r['events']['has_more']);
    $types = array_count_values(array_column($r['events']['items'],'event_type'));
    $assert($types === ['collection'=>3,'deposit_retention'=>2,'refund'=>4], 'Event ledgers stay separate');
    $assert(count(array_filter($r['events']['items'], static fn(array $event): bool => str_starts_with($event['room_code'], 'REV-D-'))) === 9, 'Each daily event resolves its unique room label');
    $assert($r['basis']['earned_revenue_calculated'] === false && $r['basis']['balances_scope'] === 'current_all_periods');
});
$test('future prepayments remain collections and fully cancelled refunds leave no phantom deposit balance', function () use ($service,$period,$owner,$assert): void {
    $r = $service->daily($period,$owner);
    $assert($r['summary']['future_stay_payment_count'] === 1 && $r['summary']['future_stay_room_collections'] === '100.01');
    // Includes the receipt at the next month's boundary: balances are CURRENT, not period-filtered.
    $assert($r['balances']['active_stay_security_deposits'] === '60.07');
    $assert($r['balances']['closed_booking_cash_refundable'] === '0.00' && $r['balances']['unconfirmed_verified_cash'] === '0.00');
});
$test('current cancellation balance preserves whole refundable cash without inventing room/deposit refund allocation', function () use ($daily,$stamp,$start,$service,$period,$owner,$assert): void {
    $daily($stamp($start->modify('+7 hours')), 'slip', 'verified', 'expired', '0.43');
    $r = $service->daily($period,$owner);
    $assert($r['balances']['closed_booking_cash_refundable'] === '120.46');
    $assert($r['balances']['active_stay_security_deposits'] === '60.07');
});
$test('each report verifies an active owner and malformed periods fail before financial queries', function () use ($service,$period,$owner,$inactive,$retired,$expect): void {
    foreach ([$inactive,$retired,999999] as $id) { $expect(fn() => $service->monthly($period,$id),'FORBIDDEN'); $expect(fn() => $service->daily($period,$id),'FORBIDDEN'); }
    $expect(fn() => $service->monthly($period . '-01',$owner),'VALIDATION_ERROR');
    $expect(fn() => $service->daily($period,$owner,-1),'VALIDATION_ERROR');
});
$test('a read-only report cannot mutate booking, payment, refund or invoice snapshots', function () use ($pdo,$service,$period,$owner,$assert): void {
    $tables = ['rooms','bills','bill_items','payments','daily_bookings','daily_payments','daily_refunds','daily_deposit_settlements'];
    $snapshot = static function () use ($pdo,$tables): string { $data=[]; foreach ($tables as $table) $data[$table]=$pdo->query('SELECT * FROM ' . $table)->fetchAll(); return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR)); };
    $before = $snapshot(); $service->monthly($period,$owner); $service->daily($period,$owner);
    $assert($snapshot() === $before, 'Read reports changed persisted records');
    $assert($service->monthly($period,$owner,1)['events']['offset'] === 1 && count($service->monthly($period,$owner,1)['events']['items']) === 1);
});
$test('refunds of prior collections can make a later month cash flow negative without invented room-income allocation', function () use ($daily,$refund,$stamp,$start,$end,$service,$monthNext,$owner,$assert): void {
    $prior = $daily($stamp($start->modify('-2 microseconds')), 'slip', 'verified', 'expired', '0.45');
    $refund($prior,'120.48','cancellation','next-month',$stamp($end->modify('+1 hour')));
    $r = $service->daily($monthNext,$owner);
    $assert($r['summary']['gross_receipts'] === '120.03' && $r['summary']['refunds_total'] === '120.48');
    $assert($r['summary']['net_cash_flow'] === '-0.45' && $r['summary']['cancellation_refunds'] === '120.48');
    $assert($r['events']['items'][0]['event_type'] === 'refund' && $r['events']['items'][0]['cash_flow_direction'] === 'out');
});
echo $groups, ' revenue MySQL groups passed (', $checks, ") checks; disposable schema only, no providers\n";
