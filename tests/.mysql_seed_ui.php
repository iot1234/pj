<?php
declare(strict_types=1);

use Dormitory\Http\Request;
use Dormitory\Security\Password;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || preg_match('/^appj_final_[a-z0-9_]+$/D', (string) getenv('DB_DATABASE')) !== 1) {
    fwrite(STDERR, "Refusing UI fixture seed outside a dedicated testing database\n");
    exit(64);
}

/** @var Dormitory\Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$pdo = $app->database()->pdo();
foreach (['admin_users', 'residents', 'rooms', 'bookings', 'occupancies', 'bills', 'payments'] as $table) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() !== 0) {
        throw new RuntimeException('UI fixture database is not clean: ' . $table);
    }
}

$request = static fn(string $suffix): Request => new Request(
    'POST',
    '/tests/ui/' . $suffix,
    ['user-agent' => 'dormitory-ui-fixture'],
    [],
    [],
    [],
    ['REMOTE_ADDR' => '127.0.0.20'],
    'ui-' . $suffix . '-' . bin2hex(random_bytes(4)),
);

$ownerPassword = 'QA-Owner-Only-2026!Long';
$insertOwner = $pdo->prepare("INSERT INTO admin_users
    (username,password_hash,role,auth_version,active,created_by,created_at,updated_at)
    VALUES ('qa_owner',?,'owner',1,1,NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
$insertOwner->execute([Password::hash($ownerPassword)]);
$ownerId = (int) $pdo->lastInsertId();

$app->billing()->updateSettings([
    'water_rate' => '18.50',
    'electric_rate' => '7.25',
    'due_days' => 7,
], $ownerId);
$app->settings()->update(['line_basic_id' => '@dormflowtest'], $ownerId);

$roomOne = $app->rooms()->create([
    'room_code' => 'QA-101', 'floor' => 1, 'room_type' => 'Standard',
    'monthly_rent' => '4500.00', 'amenities' => ['Wi-Fi', 'เครื่องปรับอากาศ'],
]);
$roomTwo = $app->rooms()->create([
    'room_code' => 'QA-102', 'floor' => 1, 'room_type' => 'Standard',
    'monthly_rent' => '4600.00', 'amenities' => ['Wi-Fi'],
]);
$app->rooms()->create([
    'room_code' => 'QA-201', 'floor' => 2, 'room_type' => 'Deluxe',
    'monthly_rent' => '5200.00', 'amenities' => ['Wi-Fi', 'ระเบียง'],
]);
$app->rooms()->create([
    'room_code' => 'QA-202', 'floor' => 2, 'room_type' => 'Deluxe',
    'monthly_rent' => '5300.00', 'amenities' => ['Wi-Fi', 'เครื่องทำน้ำอุ่น'],
]);

$timezone = new DateTimeZone((string) $app->config->get('APP_TIMEZONE', 'Asia/Bangkok'));
$today = (new DateTimeImmutable('today', $timezone))->format('Y-m-d');
$period = substr($today, 0, 7);
$dueDate = (new DateTimeImmutable('today', $timezone))->modify('+7 days')->format('Y-m-d');

$createResident = static function (
    array $room,
    string $name,
    string $phone,
    string $email,
    string $password,
    string $key,
) use ($app, $ownerId, $today, $request): array {
    $checkIn = $app->bookings()->createAdminResident($ownerId, [
        'room_id' => $room['id'],
        'full_name' => $name,
        'phone' => $phone,
        'email' => $email,
        'move_in_date' => $today,
        'opening_water_reading' => '100.00',
        'opening_electric_reading' => '200.00',
        'idempotency_key' => $key,
    ]);
    $activation = (string) ($checkIn['resident_access']['activation_code'] ?? '');
    if ($activation === '') {
        throw new RuntimeException('UI fixture activation code was not issued');
    }
    $app->auth()->residentLogin($request('activate-' . $phone), [
        'phone' => $phone,
    ]);
    $app->auth()->logout($request('logout-' . $phone));
    return $checkIn;
};

$residentOne = $createResident(
    $roomOne,
    'ผู้พักทดสอบ หนึ่ง',
    '0812345678',
    'resident.one@example.test',
    'QA-Resident-Only-2026!Long',
    'ui-checkin-resident-one-20260803',
);
$createResident(
    $roomTwo,
    'ผู้พักทดสอบ สอง',
    '0898765432',
    'resident.two@example.test',
    'QA-Resident-Two-2026!Long',
    'ui-checkin-resident-two-20260803',
);

$app->meters()->record([
    'room_id' => $roomOne['id'], 'period' => $period,
    'water_current' => '112.00', 'electric_current' => '225.00',
], $ownerId);
$app->meters()->record([
    'room_id' => $roomTwo['id'], 'period' => $period,
    'water_current' => '108.00', 'electric_current' => '219.00',
], $ownerId);

$preview = $app->billing()->preview([
    'period' => $period,
    'room_ids' => [$roomOne['id']],
    'due_date' => $dueDate,
    'confirm_current_period' => true,
]);
if (($preview['issues'] ?? []) !== [] || !is_string($preview['preview_token'] ?? null)) {
    throw new RuntimeException('UI fixture bill preview is not ready');
}
$issued = $app->billing()->bulk([
    'period' => $period,
    'room_ids' => [$roomOne['id']],
    'due_date' => $dueDate,
    'confirm_current_period' => true,
    'preview_token' => $preview['preview_token'],
], $ownerId);
$billId = (int) ($issued['created'][0]['id'] ?? 0);
if ($billId < 1) {
    throw new RuntimeException('UI fixture bill was not created');
}
$amount = $pdo->prepare('SELECT total_amount FROM bills WHERE id=?');
$amount->execute([$billId]);
$insertPayment = $pdo->prepare("INSERT INTO payments
    (bill_id,resident_id,amount,status,slip_path,slip_mime,slip_hmac,
     verification_attempts,created_at,updated_at)
    VALUES (?, ?, ?, 'pending', 'tests/ui-pending-slip.jpg', 'image/jpeg', ?, 1,
            UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
$insertPayment->execute([
    $billId,
    (int) $residentOne['resident_id'],
    (string) $amount->fetchColumn(),
    hash('sha256', 'ui-pending-slip-' . $billId),
]);

fwrite(STDOUT, "PASS UI MySQL fixture seeded\n");
