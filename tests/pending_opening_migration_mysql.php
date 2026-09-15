<?php
declare(strict_types=1);

// This suite deliberately reconstructs pre-009 and corrupt legacy fixtures.
// It requires an EMPTY, isolated schema and a schema-owner account. It never
// reads .env or contacts the application, a provider, or another database.
$database = (string) getenv('DB_DATABASE');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || preg_match('/^appj_pending_schema_[a-z0-9_]+$/D', $database) !== 1) {
    fwrite(STDERR, "Refusing pending-opening migration test outside its isolated schema\n");
    exit(64);
}
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
        . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database . ';charset=utf8mb4',
    (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() === 0,
    'Migration test requires an empty schema');
$root = dirname(__DIR__);
require_once $root . '/src/Support/PendingOpeningSchema.php';
$schema = (string) file_get_contents($root . '/database/schema.sql');
$runSql = static function (string $sql) use ($pdo): void {
    $delimiter = ';';
    $buffer = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*--/', $line) === 1 || trim($line) === '') continue;
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (trim($buffer) !== '') throw new RuntimeException('Unexpected unfinished SQL');
            $delimiter = $match[1];
            continue;
        }
        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if (!str_ends_with($trimmed, $delimiter)) continue;
        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
        $buffer = '';
        // Some canonical migration lines contain several fixed SQL statements.
        // Only trusted repository SQL reaches this importer; native prepares
        // remain enabled for every fixture/application query outside import.
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $result = $pdo->query($statement);
            do {
                if ($result->columnCount() > 0) $result->fetchAll();
            } while ($result->nextRowset());
            $result->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
    }
    if (trim($buffer) !== '') throw new RuntimeException('Unterminated SQL');
};
$migrate = static function (string $filename) use ($runSql, $root): void {
    $runSql((string) file_get_contents($root . '/database/migrations/' . $filename));
};
$restoreTrigger = static function (string $name) use ($schema, $pdo): void {
    if (preg_match('/CREATE TRIGGER ' . preg_quote($name, '/') . '\s.*?END\$\$/s', $schema, $match) !== 1) {
        throw new RuntimeException('Canonical trigger missing: ' . $name);
    }
    $pdo->exec('DROP TRIGGER IF EXISTS ' . $name);
    $pdo->exec(substr($match[0], 0, -2));
};
$reject = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (PDOException $error) {
        $assert(str_contains($error->getMessage(), $message), 'Wrong database rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected database rejection: ' . $message);
};
$runSql($schema);
$runSql((string) file_get_contents($root . '/database/defaults.sql'));

// Only fixture setup bypasses the two new-entity guards. All foreign keys,
// CHECKs and other guards remain enabled, and both triggers are restored.
$pdo->exec('DROP TRIGGER trg_bookings_insert_guard');
$pdo->exec('DROP TRIGGER trg_occupancies_relationship_guard');
for ($id = 1; $id <= 3; $id++) {
    $phone = '089000000' . $id;
    $pdo->exec("INSERT INTO rooms(id,room_code,floor,room_type,monthly_rent,amenities)
        VALUES ({$id},'PENDING-{$id}',1,'Test',4500,JSON_ARRAY())");
    $pdo->exec("INSERT INTO residents(id,full_name,phone_norm) VALUES ({$id},'Legacy {$id}','{$phone}')");
    $pdo->exec("INSERT INTO bookings(id,reference_no,room_id,full_name,phone_norm,booked_monthly_rent,
        status,idempotency_key,confirmed_at,resident_id,moved_in_at)
        VALUES ({$id},'PENDING-{$id}',{$id},'Legacy {$id}','{$phone}',4500,
        'moved_in','pending-schema-legacy-{$id}','2026-07-01',{$id},'2026-07-01')");
    $pdo->exec("INSERT INTO occupancies(id,resident_id,room_id,booking_id,monthly_rent,move_in_date)
        VALUES ({$id},{$id},{$id},{$id},4500,'2026-07-01')");
}
$restoreTrigger('trg_bookings_insert_guard');
$restoreTrigger('trg_occupancies_relationship_guard');
$assert(Dormitory\Support\PendingOpeningSchema::errors($pdo) === [], 'Fresh marker/check rejected');
$assert(Dormitory\Support\PendingOpeningSchema::missingOpeningCounts($pdo) === ['pending'=>3,'invalid'=>0],
    'Safe unknown legacy rows were not classified as pending');

// A known opening pair can never be edited, cleared or partly set. The CHECK
// independently rejects partial NULL, rather than accepting SQL UNKNOWN.
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=10 WHERE id=1'), 'completed together once');
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=-1,opening_electric_reading=20 WHERE id=1'), 'completed together once');
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=10,opening_electric_reading=10000000 WHERE id=1'), 'completed together once');
$pdo->exec('DROP TRIGGER trg_occupancies_identity_immutable');
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=10 WHERE id=1'), 'chk_occupancies_opening_readings_v2');
$restoreTrigger('trg_occupancies_identity_immutable');
$pdo->exec('UPDATE occupancies SET opening_water_reading=10,opening_electric_reading=20 WHERE id=1');
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=11 WHERE id=1'), 'completed together once');
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=NULL,opening_electric_reading=NULL WHERE id=1'), 'completed together once');
$reject(fn() => $pdo->exec('UPDATE occupancies SET monthly_rent=4600 WHERE id=1'), 'identity and rent snapshot');
$reject(fn() => $pdo->exec('UPDATE occupancies SET id=100 WHERE id=1'), 'identity and rent snapshot');
$reject(fn() => $pdo->exec("UPDATE occupancies SET move_in_date='2026-07-02' WHERE id=1"), 'identity and rent snapshot');
$pdo->beginTransaction();
$pdo->exec("UPDATE occupancies SET status='ended',move_out_date='2026-07-31' WHERE id=3");
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=10,opening_electric_reading=20 WHERE id=3'), 'completed together once');
$pdo->rollBack();
$meterSql = "INSERT INTO meter_readings(room_id,occupancy_id,meter_type,period,previous_reading,current_reading,units_used)
    VALUES(2,2,'water','2026-07-01',0,1,1)";
$reject(fn() => $pdo->exec($meterSql), 'Complete both occupancy opening readings');
$billSql = "INSERT INTO bills(bill_no,occupancy_id,resident_id,room_id,resident_name_snapshot,room_code_snapshot,
    period,due_date,rent_amount,water_previous,water_current,water_units,water_rate,water_amount,
    electric_previous,electric_current,electric_units,electric_rate,electric_amount,total_amount)
    VALUES('PENDING-TEST',2,2,2,'Legacy 2','PENDING-2','2026-07-01','2026-07-07',4500,
    0,1,1,1,1,0,1,1,1,1,4502)";
$reject(fn() => $pdo->exec($billSql), 'Complete both occupancy opening readings');
$reject(fn() => $pdo->exec("INSERT INTO occupancies(resident_id,room_id,booking_id,monthly_rent,move_in_date)
    VALUES(1,1,1,4500,'2026-07-01')"), 'Occupancy must start active');
fwrite(STDOUT, "PASS fresh schema: atomic completion, immutable values/identity, partial-NULL CHECK, pending meter/bill/new-check-in guards\n");

// Simulate a pre-009 installation: none of the three 009 columns, FK/index,
// opening CHECK or replacement triggers exists. Other schema stays intact.
foreach (['trg_occupancies_identity_immutable','trg_occupancies_relationship_guard',
          'trg_meter_readings_occupancy_guard','trg_meter_readings_occupancy_guard_update',
          'trg_bills_relationship_guard'] as $name) {
    $pdo->exec('DROP TRIGGER ' . $name);
}
$pdo->exec('ALTER TABLE occupancies DROP CHECK chk_occupancies_opening_readings_v2,
    DROP COLUMN opening_water_reading, DROP COLUMN opening_electric_reading');
$pdo->exec('ALTER TABLE meter_readings DROP FOREIGN KEY fk_meter_readings_occupancy,
    DROP INDEX idx_meter_readings_occupancy_period, DROP COLUMN occupancy_id');
$migrate('009_occupancy_meter_baselines.sql');
$assert((int) $pdo->query('SELECT COUNT(*) FROM occupancies
    WHERE opening_water_reading IS NULL AND opening_electric_reading IS NULL')->fetchColumn() === 3,
    '009 invented or removed legacy readings');
foreach (['010_line_self_service_binding.sql','011_line_add_friend_identity.sql',
          '012_move_in_request_hash.sql','013_trigger_collation_pinning.sql','014_line_platform.sql',
          '015_pending_occupancy_opening_readings.sql'] as $migration) $migrate($migration);
$migrate('015_pending_occupancy_opening_readings.sql');
$assert(Dormitory\Support\PendingOpeningSchema::errors($pdo) === [], 'Migrated marker/check rejected');
$assert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema=DATABASE() AND constraint_type='CHECK'")->fetchColumn() === 116, 'Unexpected CHECK count');
$assert((int) $pdo->query('SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE()')->fetchColumn() === 23, 'Unexpected trigger count');
foreach (['trg_occupancies_identity_immutable','trg_meter_readings_occupancy_guard',
          'trg_meter_readings_occupancy_guard_update','trg_bills_relationship_guard'] as $name) {
    preg_match('/CREATE TRIGGER ' . $name . '\s.*?FOR EACH ROW\s+(BEGIN.*?END)\$\$/s', $schema, $match);
    $query = $pdo->prepare('SELECT action_statement FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?');
    $query->execute([$name]);
    $normalize = static fn(string $body): string => preg_replace('/\s+/', ' ', trim($body));
    $assert($normalize((string) $query->fetchColumn()) === $normalize($match[1]), 'Migration/canonical trigger differs: ' . $name);
}
fwrite(STDOUT, "PASS missing-009-columns upgrade through 015 and repeat 015: three unknown pairs preserved, 23 canonical triggers, 116 CHECKs\n");

// Fault injection proves both migrations refuse partial legacy data. No
// global foreign-key/CHECK bypass is used; only this isolated fixture changes.
$pdo->exec('DROP TRIGGER trg_occupancies_identity_immutable');
$pdo->exec('ALTER TABLE occupancies DROP CHECK chk_occupancies_opening_readings_v2');
$pdo->exec('UPDATE occupancies SET opening_water_reading=10 WHERE id=2');
$assert(Dormitory\Support\PendingOpeningSchema::missingOpeningCounts($pdo) === ['pending'=>2,'invalid'=>1],
    'Partial readings were not classified as invalid');
$reject(fn() => $migrate('009_occupancy_meter_baselines.sql'), 'DORMITORY_009_REPAIR_ACTIVE_OPENING_READINGS');
$reject(fn() => $migrate('015_pending_occupancy_opening_readings.sql'), 'DORMITORY_015_REPAIR_OPENING_READINGS_HISTORY');
$pdo->exec('UPDATE occupancies SET opening_water_reading=NULL WHERE id=2');
$migrate('015_pending_occupancy_opening_readings.sql');

// A pre-existing single-meter row (without a full evidence pair) prevents
// upgrade or completion. Pending rows cannot edit that history either.
$pdo->exec('DROP TRIGGER trg_meter_readings_occupancy_guard');
$pdo->exec($meterSql);
$restoreTrigger('trg_meter_readings_occupancy_guard');
$assert(Dormitory\Support\PendingOpeningSchema::missingOpeningCounts($pdo) === ['pending'=>2,'invalid'=>1],
    'Missing readings with meter history were not classified as invalid');
$reject(fn() => $pdo->exec("UPDATE meter_readings SET current_reading=2,units_used=2 WHERE occupancy_id=2"), 'Complete both occupancy opening readings');
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=0,opening_electric_reading=0 WHERE id=2'), 'after meter or bill history');
$reject(fn() => $migrate('009_occupancy_meter_baselines.sql'), 'DORMITORY_009_REPAIR_ACTIVE_OPENING_READINGS');
$reject(fn() => $migrate('015_pending_occupancy_opening_readings.sql'), 'DORMITORY_015_REPAIR_OPENING_READINGS_HISTORY');
$pdo->exec('DELETE FROM meter_readings WHERE occupancy_id=2');

$pdo->exec('DROP TRIGGER trg_meter_readings_occupancy_guard');
$pdo->exec(str_replace('VALUES(2,2,', 'VALUES(2,NULL,', $meterSql));
$restoreTrigger('trg_meter_readings_occupancy_guard');
$assert(Dormitory\Support\PendingOpeningSchema::missingOpeningCounts($pdo) === ['pending'=>2,'invalid'=>1],
    'Unlinked same-room history was not classified as invalid');
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=0,opening_electric_reading=0 WHERE id=2'), 'after meter or bill history');
$reject(fn() => $migrate('015_pending_occupancy_opening_readings.sql'), 'DORMITORY_015_REPAIR_OPENING_READINGS_HISTORY');
$pdo->exec('DELETE FROM meter_readings WHERE room_id=2');

$pdo->exec('DROP TRIGGER trg_bills_relationship_guard');
$pdo->exec($billSql);
$restoreTrigger('trg_bills_relationship_guard');
$assert(Dormitory\Support\PendingOpeningSchema::missingOpeningCounts($pdo) === ['pending'=>2,'invalid'=>1],
    'Missing readings with bill history were not classified as invalid');
$reject(fn() => $pdo->exec('UPDATE occupancies SET opening_water_reading=0,opening_electric_reading=0 WHERE id=2'), 'after meter or bill history');
$reject(fn() => $migrate('009_occupancy_meter_baselines.sql'), 'DORMITORY_009_REPAIR_ACTIVE_OPENING_READINGS');
$reject(fn() => $migrate('015_pending_occupancy_opening_readings.sql'), 'DORMITORY_015_REPAIR_OPENING_READINGS_HISTORY');
$pdo->exec('DROP TRIGGER trg_bills_no_delete');
$pdo->exec('DELETE FROM bills WHERE occupancy_id=2');
$restoreTrigger('trg_bills_no_delete');
$pdo->exec('UPDATE occupancies SET opening_water_reading=0,opening_electric_reading=0 WHERE id=2');
$pdo->exec($meterSql);
$assert((int) $pdo->query('SELECT COUNT(*) FROM meter_readings WHERE occupancy_id=2')->fetchColumn() === 1,
    'Confirmed true-zero baselines did not allow metering');
fwrite(STDOUT, "PASS partial/meter/bill/unlinked-history migration and readiness gates, pending meter-update block and true-zero completion\n");
