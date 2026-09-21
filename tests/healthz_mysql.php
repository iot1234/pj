<?php
declare(strict_types=1);

// Run only against a disposable, freshly installed MySQL schema. The web
// subprocess receives the runtime account; DDL credentials stay in this runner.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || preg_match('/^appj_(?:healthz|line)_test_[a-z0-9_]+$/D', (string) getenv('DB_DATABASE')) !== 1) {
    fwrite(STDERR, "A dedicated testing database is required for healthz regression\n");
    exit(64);
}
$legacy = in_array('--legacy', array_slice($argv, 1), true);
$root = dirname(__DIR__);
/** @var Dormitory\Application $app */
$app = require $root . '/bootstrap.php';
$pdo = $app->database()->pdo();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$assert(count($tables) === ($legacy ? 16 : 22), 'The regression requires the expected fresh table set');
foreach ($tables as $table) {
    $assert(preg_match('/^[a-z_]+$/D', $table) === 1, 'Unexpected testing table name');
    if (in_array($table, ['billing_settings', 'integration_settings', 'line_official_accounts'], true)) continue;
    $assert((int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, 'The regression requires an unused database');
}
if (!$legacy) {
    $assert((int) $pdo->query('SELECT COUNT(*) FROM line_official_accounts')->fetchColumn() === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM line_official_accounts WHERE id=0')->fetchColumn() === 1,
        'The regression requires only the canonical legacy OA metadata seed');
}
$schema = null;
if (!$legacy) {
    $username = (string) getenv('HEALTHZ_SCHEMA_USERNAME');
    $password = (string) getenv('HEALTHZ_SCHEMA_PASSWORD');
    $assert($username !== '' && $password !== '', 'Separate schema-owner credentials are required for testing fault injection');
    $host = Dormitory\Config::validatedDbHost($app->config->require('DB_HOST'));
    $port = $app->config->intInRange('DB_PORT', 3306, 1, 65535);
    $database = (string) getenv('DB_DATABASE');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if ($app->config->bool('DB_SSL', false)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $app->config->require('DB_SSL_CA');
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    $schema = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, $options);
}

foreach (['storage', 'storage/private', 'storage/private/slips', 'storage/sessions', 'storage/logs', 'storage/cache'] as $directory) {
    $path = $root . '/' . $directory;
    $assert(is_dir($path) || mkdir($path, 0700, true), 'Cannot prepare testing storage');
}
$temporaryDirectory = sys_get_temp_dir() . '/appj-healthz-' . bin2hex(random_bytes(8));
$assert(mkdir($temporaryDirectory, 0700), 'Cannot prepare testing server log');
$logPath = $temporaryDirectory . '/server.log';
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
$assert(is_resource($socket), 'Cannot reserve a testing HTTP port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$assert(is_string($address), 'Cannot determine the testing HTTP address');
$command = [PHP_BINARY];
$ini = php_ini_loaded_file();
if (is_string($ini)) array_push($command, '-c', $ini);
array_push($command, '-d', 'error_log=' . $logPath, '-S', $address, '-t', $root . '/public');
$environment = getenv();
foreach (array_keys($environment) as $name) {
    if (str_starts_with($name, 'HEALTHZ_SCHEMA_') || str_starts_with($name, 'DB_DBA_')) unset($environment[$name]);
}
$environment['APP_DEBUG'] = 'false';
$environment['FORCE_HTTPS'] = 'false';
$environment['APP_URL'] = 'http://' . $address;
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']], $pipes, $root, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
$assert(is_resource($process), 'Cannot start the testing HTTP server');
fclose($pipes[0]);
$request = static function () use ($address, $logPath): array {
    clearstatcache(true, $logPath);
    $offset = is_file($logPath) ? (int) filesize($logPath) : 0;
    $curl = curl_init('http://' . $address . '/healthz.php');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_PROXY => '']);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $curl = null;
    if (!is_string($response)) return ['status' => 0, 'body' => '', 'headers' => '', 'log' => ''];
    $log = file_get_contents($logPath, false, null, $offset);
    return ['status' => $status, 'body' => substr($response, $headerSize), 'headers' => substr($response, 0, $headerSize), 'log' => is_string($log) ? $log : ''];
};
$unavailable = static function (array $response, string $diagnostic) use ($assert): void {
    $assert($response['status'] === 503, 'Expected HTTP 503 for an incompatible schema');
    $assert($response['body'] === '{"status":"unavailable"}', 'Failure details leaked into the public response');
    $assert(str_contains(strtolower($response['headers']), 'cache-control: no-store'), 'Readiness must not be cached');
    $assert(str_contains($response['log'], $diagnostic), 'The server log omitted the expected safe schema diagnostic: ' . $diagnostic);
    $assert(!str_contains($response['log'], 'PDOException'), 'Missing schema objects should be diagnosed before application SQL fails');
};
$passed = 0;
$pass = static function (string $name) use (&$passed): void {
    $passed++;
    fwrite(STDOUT, "PASS {$name}\n");
};

try {
    $initial = ['status' => 0];
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $initial = $request();
        if ($initial['status'] !== 0) break;
        usleep(100000);
    }
    if ($legacy) {
        $unavailable($initial, 'migration 014 required; missing tables: line_official_accounts');
        $pass('a real pre-014 sixteen-table schema fails HTTP readiness with the migration name');
    } else {
        $assert($initial['status'] === 200 && $initial['body'] === '{"status":"ok"}', 'The current fresh schema must pass real HTTP readiness');
        $pass('the current twenty-two-table schema passes real HTTP readiness with the runtime account');

        $schema->exec('RENAME TABLE transfer_instructions TO healthz_missing_transfer_instructions');
        try {
            $unavailable($request(), 'migration 016 required');
            $pass('missing amount reservations fail readiness before a broken QR deployment can go live');
        } finally {
            $schema->exec('RENAME TABLE healthz_missing_transfer_instructions TO transfer_instructions');
        }

        $schema->exec('ALTER TABLE transfer_instructions DROP INDEX uq_transfer_active_amount, ADD KEY uq_transfer_active_amount (active_amount)');
        try {
            $unavailable($request(), 'migration 016');
            $pass('a nonunique transfer amount index cannot pass HTTP readiness');
        } finally {
            $schema->exec('ALTER TABLE transfer_instructions DROP INDEX uq_transfer_active_amount, ADD UNIQUE KEY uq_transfer_active_amount (active_amount)');
        }

        $lineTables = ['line_official_accounts', 'line_room_bindings', 'line_room_policies', 'line_admin_recipients', 'line_notice_outbox'];
        $rename = static function (bool $restore) use ($schema, $lineTables): void {
            $pairs = [];
            foreach ($lineTables as $table) {
                $pairs[] = $restore ? "healthz_missing_{$table} TO {$table}" : "{$table} TO healthz_missing_{$table}";
            }
            $schema->exec('RENAME TABLE ' . implode(', ', $pairs));
        };
        $rename(false);
        try {
            $unavailable($request(), 'migration 014 required; missing tables: line_official_accounts');
            $pass('missing LINE platform tables fail closed with a migration-014 diagnostic');
        } finally {
            $rename(true);
        }

        $schema->exec('RENAME TABLE rate_limits TO healthz_missing_rate_limits');
        try {
            $unavailable($request(), 'schema readiness check failed; missing tables: rate_limits');
            $pass('a missing base table is identified in the server log without exposing it publicly');
        } finally {
            $schema->exec('RENAME TABLE healthz_missing_rate_limits TO rate_limits');
        }

        $schema->exec('ALTER TABLE notification_outbox DROP INDEX uq_notification_outbox_bill_binding, ADD UNIQUE KEY uq_notification_outbox_bill_binding (bill_id,purpose)');
        try {
            $unavailable($request(), 'LINE unique index mismatch: notification_outbox.uq_notification_outbox_bill_binding');
            $pass('the actual delivery index must include line_delivery_key');
        } finally {
            $schema->exec('ALTER TABLE notification_outbox DROP INDEX uq_notification_outbox_bill_binding, ADD UNIQUE KEY uq_notification_outbox_bill_binding (bill_id,purpose,line_delivery_key)');
        }

        $openingCheck = $schema->query("SELECT check_clause FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND constraint_name='chk_occupancies_opening_readings_v2'")->fetchColumn();
        $assert(is_string($openingCheck) && $openingCheck !== '', 'The canonical migration 015 check must be readable by the schema owner');
        $schema->exec('ALTER TABLE occupancies DROP CHECK chk_occupancies_opening_readings_v2, ADD CONSTRAINT chk_occupancies_opening_readings CHECK (' . $openingCheck . ')');
        try {
            $unavailable($request(), 'migration 015 required: Missing enforced migration 015 check');
            $pass('a pre-015 opening-reading schema cannot pass HTTP readiness');
        } finally {
            $schema->exec('ALTER TABLE occupancies DROP CHECK chk_occupancies_opening_readings, ADD CONSTRAINT chk_occupancies_opening_readings_v2 CHECK (' . $openingCheck . ')');
        }
        $schema->exec('ALTER TABLE occupancies DROP CHECK chk_occupancies_opening_readings_v2, ADD CONSTRAINT chk_occupancies_opening_readings_v2 CHECK (opening_water_reading IS NULL OR opening_electric_reading IS NULL OR (opening_water_reading >= 0 AND opening_electric_reading >= 0))');
        try {
            $unavailable($request(), 'Migration 015 opening-reading check differs from canonical schema');
            $pass('a permissive check with the migration-015 marker name still fails readiness');
        } finally {
            $schema->exec('ALTER TABLE occupancies DROP CHECK chk_occupancies_opening_readings_v2, ADD CONSTRAINT chk_occupancies_opening_readings_v2 CHECK (' . $openingCheck . ')');
        }
        $schema->exec('ALTER TABLE occupancies ALTER CHECK chk_occupancies_opening_readings_v2 NOT ENFORCED');
        try {
            $unavailable($request(), 'Missing enforced migration 015 check');
            $pass('the migration-015 check must be enforced');
        } finally {
            $schema->exec('ALTER TABLE occupancies ALTER CHECK chk_occupancies_opening_readings_v2 ENFORCED');
        }
        $restored = $request();
        $assert($restored['status'] === 200 && $restored['body'] === '{"status":"ok"}', 'The restored schema must pass readiness');
        $pass('restoring the canonical schema restores HTTP readiness');
    }
} finally {
    proc_terminate($process);
    proc_close($process);
    // Both paths were generated in this runner and contain only its HTTP log.
    unlink($logPath);
    rmdir($temporaryDirectory);
}
fwrite(STDOUT, "{$passed} healthz MySQL regression groups passed\n");
