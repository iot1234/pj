#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/src/Config.php';
require_once $root . '/src/Security/SecretCipher.php';
require_once $root . '/src/Support/SchemaGuard.php';

$arguments = array_slice($argv, 1);
$productionMode = in_array('--production', $arguments, true);
$schemaAudit = in_array('--schema-audit', $arguments, true);
$checkDatabase = in_array('--db', $arguments, true) || $productionMode || $schemaAudit;
$strict = in_array('--strict', $arguments, true) || $productionMode;

if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    echo "Usage: php scripts/check_requirements.php [--db] [--strict] [--production] [--schema-audit]\n";
    echo "  --db      connect to MySQL and verify the schema\n";
    echo "  --strict  return a failure code when warnings exist\n";
    echo "  --production  require production mode, check MySQL, and fail on warnings\n";
    echo "  --schema-audit verify trigger definitions with a temporary DBA/schema-owner connection\n";
    echo "  RUNTIME_ROLE=all|web|worker|job limits integration checks to that process role\n";
    exit(0);
}

$errors = [];
$warnings = [];
$successes = [];
$skips = [];

/** @param list<string> $target */
function addResult(array &$target, string $message): void
{
    $target[] = $message;
}

/** @return array<string,string> */
function readEnvironmentFile(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $values[$key] = $value;
    }
    return $values;
}

/** @param array<string,string> $fileValues */
function envValue(array $fileValues, string $key, ?string $default = null): ?string
{
    $runtime = getenv($key);
    if ($runtime !== false) {
        return (string) $runtime;
    }
    return array_key_exists($key, $fileValues) ? $fileValues[$key] : $default;
}

/** @param list<string> $errors */
function validatedEnvBool(?string $value, bool $default, string $key, array &$errors): bool
{
    if ($value === null) {
        return $default;
    }
    return match (strtolower(trim($value))) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => (function () use ($key, $default, &$errors): bool {
            addResult($errors, $key . ' ต้องเป็น true/false, 1/0, yes/no หรือ on/off');
            return $default;
        })(),
    };
}

function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    if ($unit === 'g') {
        $number *= 1024;
        $unit = 'm';
    }
    if ($unit === 'm') {
        $number *= 1024;
        $unit = 'k';
    }
    if ($unit === 'k') {
        $number *= 1024;
    }
    return (int) min($number, PHP_INT_MAX);
}

function configuredDatabaseValue(mixed $value): bool
{
    return is_string($value) && trim($value) !== '';
}

/** @param list<string> $errors */
function configuredIntegrationSecret(
    mixed $payload,
    string $field,
    ?\Dormitory\Security\SecretCipher $cipher,
    array &$errors,
): bool {
    if (!configuredDatabaseValue($payload)) {
        return false;
    }
    if ($cipher === null) {
        addResult($errors, "ไม่สามารถตรวจสอบ secret {$field} ได้; ตรวจสอบ APP_KEY และ PHP OpenSSL");
        return false;
    }
    try {
        // SecretCipher authenticates both the ciphertext and its field-specific
        // AAD, matching SystemSettingsService::secretMetadata exactly.
        return $cipher->decrypt((string) $payload, $field) !== '';
    } catch (Throwable) {
        addResult($errors, "secret {$field} ถอดรหัสไม่สำเร็จหรือถูกย้ายผิดฟิลด์; ตรวจสอบ APP_KEY และข้อมูลที่เข้ารหัส");
        return false;
    }
}

/**
 * SHOW GRANTS is inspected as an allowlist: the runtime account may have only
 * global USAGE plus SELECT/INSERT/UPDATE on the configured database. Runtime
 * code uses soft-delete/state transitions and does not need destructive DELETE.
 *
 * @param list<array<int,mixed>> $rows
 * @return array{exact:bool,missing:list<string>,can_read_all_triggers:bool}
 */
function inspectRuntimeGrants(array $rows, string $database): array
{
    $allowedCrud = ['SELECT', 'INSERT', 'UPDATE'];
    $seenCrud = [];
    $unexpected = false;
    $canReadAllTriggers = false;
    // In GRANT database scope, _ and % are wildcards even inside backticks.
    // Require their escaped form so a similarly named schema is never covered.
    $grantDatabasePattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $database);
    $quotedDatabaseScope = '`' . str_replace('`', '``', $grantDatabasePattern) . '`.*';
    $plainDatabaseScope = $grantDatabasePattern . '.*';

    foreach ($rows as $row) {
        $grant = trim((string) ($row[0] ?? ''));
        if ($grant === '') {
            $unexpected = true;
            continue;
        }
        if (stripos($grant, 'WITH GRANT OPTION') !== false) {
            $unexpected = true;
        }
        if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/i', $grant, $match)) {
            // Role assignments and any future grant format are rejected closed.
            $unexpected = true;
            continue;
        }

        $scope = trim($match[2]);
        $databaseScope = $scope === $quotedDatabaseScope || $scope === $plainDatabaseScope;
        $scopeCoversDatabase = $databaseScope || $scope === '*.*';
        $privileges = array_values(array_filter(array_map(
            static fn(string $value): string => strtoupper(trim($value)),
            explode(',', trim($match[1])),
        )));

        foreach ($privileges as $privilege) {
            if (($privilege === 'TRIGGER' || $privilege === 'ALL PRIVILEGES') && $scopeCoversDatabase) {
                $canReadAllTriggers = true;
            }
            if ($privilege === 'USAGE') {
                if ($scope !== '*.*') {
                    $unexpected = true;
                }
                continue;
            }
            if (!$databaseScope || !in_array($privilege, $allowedCrud, true)) {
                $unexpected = true;
                continue;
            }
            $seenCrud[$privilege] = true;
        }
    }

    $missing = array_values(array_filter(
        $allowedCrud,
        static fn(string $privilege): bool => !isset($seenCrud[$privilege]),
    ));

    return [
        'exact' => !$unexpected && $missing === [],
        'missing' => $missing,
        'can_read_all_triggers' => $canReadAllTriggers,
    ];
}

$envPath = $root . DIRECTORY_SEPARATOR . '.env';
$env = readEnvironmentFile($envPath);

if (version_compare(PHP_VERSION, '8.2.0', '>=')) {
    addResult($successes, 'PHP ' . PHP_VERSION . ' (ต้องการ 8.2 ขึ้นไป)');
} else {
    addResult($errors, 'PHP ต้องเป็น 8.2 ขึ้นไป; พบ ' . PHP_VERSION);
}

$requiredExtensions = ['pdo', 'pdo_mysql', 'curl', 'mbstring', 'gd', 'fileinfo', 'json', 'openssl', 'session'];
foreach ($requiredExtensions as $extension) {
    if (extension_loaded($extension)) {
        addResult($successes, 'PHP extension: ' . $extension);
    } else {
        addResult($errors, 'ไม่พบ PHP extension ที่จำเป็น: ' . $extension);
    }
}
$requiredGdFunctions = [
    'imagecreatefromjpeg', 'imagejpeg',
    'imagecreatefrompng', 'imagepng',
    'imagecreatefromwebp', 'imagewebp',
];
$missingGdFunctions = array_values(array_filter(
    $requiredGdFunctions,
    static fn(string $function): bool => !function_exists($function),
));
if ($missingGdFunctions === []) {
    addResult($successes, 'PHP GD รองรับ decode/encode JPEG, PNG และ WebP');
} else {
    addResult($errors, 'PHP GD ขาด JPEG/PNG/WebP codec functions: ' . implode(', ', $missingGdFunctions));
}
if (extension_loaded('pdo') && in_array('mysql', PDO::getAvailableDrivers(), true)) {
    addResult($successes, 'PDO MySQL driver พร้อมใช้งาน');
} else {
    addResult($errors, 'PDO ไม่มี MySQL driver');
}

if (is_file($envPath) && is_readable($envPath)) {
    addResult($successes, 'พบไฟล์ .env และอ่านได้');
} elseif (getenv('APP_KEY') !== false || getenv('DB_DATABASE') !== false) {
    addResult($successes, 'ใช้ค่า configuration จาก process environment (ไม่ต้องมี .env ใน container)');
} else {
    addResult($errors, 'ไม่พบ .env หรือ process environment สำหรับ runtime configuration');
}

$appEnv = strtolower(trim((string) envValue($env, 'APP_ENV', 'production')));
if (!in_array($appEnv, ['development', 'staging', 'production', 'testing'], true)) {
    addResult($errors, 'APP_ENV ต้องเป็น development, staging, production หรือ testing');
}
$runtimeRole=(string)envValue($env,'RUNTIME_ROLE','all');
if(!in_array($runtimeRole,['all','web','worker','job'],true))addResult($errors,'RUNTIME_ROLE ต้องเป็น all, web, worker หรือ job');
else addResult($successes,'ตรวจ configuration ตามบทบาท runtime: '.$runtimeRole);
$production = $appEnv === 'production';
if ($productionMode && !$production) {
    addResult($errors, '--production ต้องใช้ APP_ENV=production เพื่อป้องกันการ deploy ด้วยโหมด development/staging');
}
if($production&&$runtimeRole==='all')addResult($errors,'production ต้องกำหนด RUNTIME_ROLE เป็น web, worker หรือ job ให้ชัดเจน');
$appDebug = validatedEnvBool(envValue($env, 'APP_DEBUG'), false, 'APP_DEBUG', $errors);
$forceHttps = validatedEnvBool(envValue($env, 'FORCE_HTTPS'), $production, 'FORCE_HTTPS', $errors);
$dbSsl = validatedEnvBool(envValue($env, 'DB_SSL'), false, 'DB_SSL', $errors);

$appKey = trim((string) envValue($env, 'APP_KEY', ''));
if ($appKey === '') {
    addResult($errors, 'APP_KEY ยังว่างอยู่');
} elseif (strlen($appKey) < 32 || stripos($appKey, 'change') !== false) {
    addResult($errors, 'APP_KEY ต้องเป็นค่าสุ่มอย่างน้อย 32 ตัวอักษรและไม่ใช่ placeholder');
} else {
    addResult($successes, 'APP_KEY ถูกกำหนดแล้ว (ไม่แสดงค่า)');
}

$secretCipher = null;
if ($production && $appKey !== '') {
    try {
        \Dormitory\Config::validatedAppKey($appKey, true);
    } catch (Throwable) {
        addResult($errors, 'production APP_KEY ต้องมี random key material แบบ printable อย่างน้อย 32 ไบต์และมีความหลากหลายสูง');
    }
}
if ($appKey !== '' && extension_loaded('openssl')) {
    try {
        $secretCipher = new \Dormitory\Security\SecretCipher(\Dormitory\Config::fromEnvironment($root));
    } catch (Throwable) {
        // A configured ciphertext below will turn this into a targeted error.
        // General APP_KEY/OpenSSL validation is already reported above.
        $secretCipher = null;
    }
}

$appUrl = trim((string) envValue($env, 'APP_URL', ''));
$appUrlScheme = null;
try {
    $appUrlScheme = \Dormitory\Config::validatedAppUrlScheme($appUrl);
} catch (Throwable) {
    addResult($errors, 'APP_URL ต้องเป็น HTTP(S) origin ที่ไม่มี credentials, path, query หรือ fragment');
}
if ($appUrlScheme !== null && $forceHttps && $appUrlScheme !== 'https') {
    addResult($errors, 'เมื่อ FORCE_HTTPS=true ต้องใช้ APP_URL แบบ https://');
}
$appUrlHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
$reservedProductionHosts = ['dormitory.example.com', 'example.com', 'example.org', 'example.net', 'localhost'];
if ($production && (in_array($appUrlHost, $reservedProductionHosts, true)
    || str_ends_with($appUrlHost, '.example.com')
    || str_ends_with($appUrlHost, '.example.org')
    || str_ends_with($appUrlHost, '.example.net')
    || str_ends_with($appUrlHost, '.test')
    || str_ends_with($appUrlHost, '.invalid')
    || str_ends_with($appUrlHost, '.localhost'))) {
    addResult($errors, 'production ต้องเปลี่ยน APP_URL จากโดเมนตัวอย่าง/ทดสอบเป็น origin จริง');
}
if ($production && $appDebug) {
    addResult($errors, 'ห้ามเปิด APP_DEBUG ใน production');
}
if ($production && !$forceHttps) {
    addResult($errors, 'production ต้องเปิด FORCE_HTTPS=true');
}
$appTimezone = trim((string) envValue($env, 'APP_TIMEZONE', 'Asia/Bangkok'));
try {
    new DateTimeZone($appTimezone);
} catch (Throwable) {
    addResult($errors, 'APP_TIMEZONE ต้องเป็น timezone identifier ที่ PHP รองรับ');
}
$sessionLifetime = filter_var(envValue($env, 'SESSION_LIFETIME_SECONDS', '43200'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 300, 'max_range' => 604800],
]);
if ($sessionLifetime === false) {
    addResult($errors, 'SESSION_LIFETIME_SECONDS ต้องเป็นเลข 300-604800');
}
$bookingHold = filter_var(envValue($env, 'BOOKING_HOLD_SECONDS', '86400'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 900, 'max_range' => 604800],
]);
if ($bookingHold === false) {
    addResult($errors, 'BOOKING_HOLD_SECONDS ต้องเป็นเลข 900-604800');
}

foreach (['DB_DATABASE', 'DB_USERNAME'] as $key) {
    if (trim((string) envValue($env, $key, '')) === '') {
        addResult($errors, $key . ' ยังว่างอยู่');
    }
}
$dbHost = trim((string) envValue($env, 'DB_HOST', $production ? '' : '127.0.0.1'));
if ($production && $dbHost === '') {
    addResult($errors, 'production ต้องระบุ DB_HOST จาก environment/secret manager');
} elseif ($dbHost !== '') {
    try {
        $dbHost=\Dormitory\Config::validatedDbHost($dbHost);
    } catch (Throwable) {
        addResult($errors, 'DB_HOST มีรูปแบบ hostname หรือ address ไม่ถูกต้อง');
    }
}
$dbPort = filter_var(envValue($env, 'DB_PORT', '3306'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if ($dbPort === false) {
    addResult($errors, 'DB_PORT ต้องเป็นเลขพอร์ต 1-65535');
}
$dbPasswordRaw = (string) envValue($env, 'DB_PASSWORD', '');
$dbPassword = $production ? trim($dbPasswordRaw) : $dbPasswordRaw;
if ($appEnv === 'production' && $dbPassword === '') {
    addResult($errors, 'production ห้ามใช้ DB_PASSWORD ว่าง');
} elseif ($dbPassword === '') {
    addResult($warnings, 'DB_PASSWORD ว่าง เหมาะเฉพาะฐานข้อมูล local ที่จำกัดการเข้าถึง');
}

$adminUsername = trim((string) envValue($env, 'ADMIN_USERNAME', ''));
$adminPassword = (string) envValue($env, 'ADMIN_PASSWORD', '');
$adminRole = strtolower(trim((string) envValue($env, 'ADMIN_ROLE', 'owner')));
if ($adminUsername !== '' && !preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $adminUsername)) {
    addResult($errors, 'ADMIN_USERNAME ใช้ได้เฉพาะ A-Z, a-z, 0-9, _, ., - และยาว 3-64 ตัว');
}
if ($adminPassword !== '' && (strlen($adminPassword) < 12 || strlen($adminPassword) > 200)) {
    addResult($errors, 'ADMIN_PASSWORD ต้องยาว 12-200 ตัวอักษร');
}
if (!in_array($adminRole, ['owner', 'admin'], true)) {
    addResult($errors, 'ADMIN_ROLE ต้องเป็น owner หรือ admin');
}
if ($adminPassword === '' && !$checkDatabase) {
    addResult($warnings, 'ADMIN_PASSWORD ว่าง: หากยังไม่มี owner ให้กำหนดชั่วคราวแล้วรัน scripts/create_admin.php');
} elseif ($adminPassword !== '') {
    addResult($warnings, 'ตรวจพบ ADMIN_PASSWORD: หลังสร้างบัญชีแล้วควรลบค่าจาก .env');
}

$slipMaxBytes = 4 * 1024 * 1024;
$minimumPostBytes = 5 * 1024 * 1024;
if($runtimeRole!=='worker') {
    $uploadLimit = iniBytes((string) ini_get('upload_max_filesize'));
    $postLimit = iniBytes((string) ini_get('post_max_size'));
    if (!filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN)) {
        addResult($errors, 'PHP ปิด file_uploads อยู่');
    }
    if ($uploadLimit < $slipMaxBytes) {
        addResult($errors, 'upload_max_filesize ต้องรองรับสลิปสูงสุด 4 MiB');
    }
    if ($postLimit < $minimumPostBytes) {
        addResult($errors, 'post_max_size ต้องไม่น้อยกว่า 5 MiB เพื่อเผื่อ multipart overhead ของสลิป 4 MiB');
    }
}

addResult($successes, 'PromptPay, LINE และผู้ให้บริการตรวจสลิปใช้ค่าที่เข้ารหัสในฐานข้อมูล (ตั้งจากหลังบ้าน)');

if ($dbSsl) {
    $caPath = trim((string) envValue($env, 'DB_SSL_CA', ''));
    if ($caPath === '' || !is_file($caPath) || !is_readable($caPath)) {
        addResult($errors, 'DB_SSL=true แต่ DB_SSL_CA ไม่ใช่ไฟล์ CA ที่อ่านได้');
    }
}

$storagePaths = [
    $root . DIRECTORY_SEPARATOR . 'storage',
    $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private',
    $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'slips',
    $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions',
    $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',
    $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache',
];
foreach ($storagePaths as $path) {
    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    if (!is_dir($path)) {
        addResult($errors, 'ไม่พบโฟลเดอร์ ' . $relative);
    } elseif (!is_writable($path)) {
        addResult($errors, 'PHP เขียนโฟลเดอร์ ' . $relative . ' ไม่ได้');
    } else {
        addResult($successes, 'เขียนโฟลเดอร์ ' . $relative . ' ได้');
    }
}

if ($checkDatabase && extension_loaded('pdo_mysql')) {
    try {
        $host = trim((string) envValue($env, 'DB_HOST', $production ? '' : '127.0.0.1'));
        if ($host === '') {
            if($production)throw new RuntimeException('DB_HOST is required in production');
            $host='127.0.0.1';
        }
        $host=\Dormitory\Config::validatedDbHost($host);
        $database = trim((string) envValue($env, 'DB_DATABASE', ''));
        $username = trim((string) envValue($env, 'DB_USERNAME', ''));
        $port = (int) envValue($env, 'DB_PORT', '3306');
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ];
        if ($dbSsl && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $ca = trim((string) envValue($env, 'DB_SSL_CA', ''));
            if ($ca === '') {
                throw new RuntimeException('DB_SSL_CA is missing');
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        $pdo = new PDO($dsn, $username, $dbPassword, $options);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'mariadb') !== false) {
            addResult($errors, 'ต้องใช้ MySQL 8; ตรวจพบ MariaDB ซึ่งมีพฤติกรรม constraint ต่างกัน');
        } elseif (preg_match('/^(\d+\.\d+\.\d+)/', $version, $match) && version_compare($match[1], '8.0.16', '>=')) {
            addResult($successes, 'เชื่อมต่อ MySQL ' . $match[1] . ' สำเร็จ');
        } else {
            addResult($errors, 'MySQL ต้องเป็น 8.0.16 ขึ้นไป; พบ ' . preg_replace('/[^0-9A-Za-z_.-]/', '', $version));
        }

        $expectedTables = [
            'admin_users', 'residents', 'rooms', 'bookings', 'occupancies',
            'meter_readings', 'billing_settings', 'integration_settings', 'bills', 'bill_items', 'payments',
            'notification_outbox', 'audit_logs', 'rate_limits',
        ];
        $statement = $pdo->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema=? AND table_name IN ('
            . implode(',', array_fill(0, count($expectedTables), '?')) . ')'
        );
        $statement->execute(array_merge([$database], $expectedTables));
        $found = array_map(static function (array $row): string {
            return (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
        }, $statement->fetchAll());
        $missing = array_values(array_diff($expectedTables, $found));
        if ($missing === []) {
            addResult($successes, 'พบตารางระบบครบ 14 ตาราง');
        } else {
            addResult($errors, 'schema ไม่ครบ; ขาดตาราง: ' . implode(', ', $missing));
        }

        $retiredResidentCredentialStatement=$pdo->prepare("SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema=? AND table_name='residents' AND column_name='pin_hash'");
        $retiredResidentCredentialStatement->execute([$database]);
        $retiredResidentCredentialColumns=(int)$retiredResidentCredentialStatement->fetchColumn();
        if($retiredResidentCredentialColumns===0){
            addResult($successes,'schema ไม่มีคอลัมน์ credential ของ Resident ที่เลิกใช้แล้ว');
        }else{
            addResult($errors,'schema ยังมี residents.pin_hash ซึ่งไม่รองรับกับ source ปัจจุบัน; ใช้ transitional commit a52bc33 ให้ทุก replica พร้อม รัน migration 006 ตรวจว่าคอลัมน์หาย แล้วจึง deploy source ปัจจุบัน');
        }

        $grantRows = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_NUM);
        $grantInspection = inspectRuntimeGrants($grantRows, $database);
        if ($schemaAudit) {
            if ($grantInspection['can_read_all_triggers']) {
                addResult($successes, 'บัญชี schema audit มองเห็น trigger metadata; ตรวจ least privilege แยกด้วยบัญชี runtime');
            } else {
                addResult($errors, '--schema-audit ต้องใช้บัญชี DBA/schema owner ที่มองเห็น trigger metadata');
            }
        } elseif ($grantInspection['exact']) {
            addResult($successes, 'DB_USERNAME มีเฉพาะ USAGE + SELECT/INSERT/UPDATE บน database ที่กำหนด');
        } elseif ($grantInspection['missing'] !== []) {
            addResult($errors, 'DB_USERNAME ขาดสิทธิ์ runtime หรือมีสิทธิ์นอก allowlist; ขาด: ' . implode(', ', $grantInspection['missing']));
        } else {
            addResult($errors, 'DB_USERNAME มีสิทธิ์/ขอบเขตนอก allowlist USAGE + SELECT/INSERT/UPDATE; ให้แยก migration/DBA user');
        }

        $expectedTriggers = [
            'trg_bookings_identity_immutable' => ['UPDATE', 'BEFORE', 'bookings'],
            'trg_occupancies_identity_immutable' => ['UPDATE', 'BEFORE', 'occupancies'],
            'trg_bills_snapshot_immutable' => ['UPDATE', 'BEFORE', 'bills'],
            'trg_bills_relationship_guard' => ['INSERT', 'BEFORE', 'bills'],
            'trg_bills_no_delete' => ['DELETE', 'BEFORE', 'bills'],
            'trg_bill_items_insert_guard' => ['INSERT', 'BEFORE', 'bill_items'],
            'trg_bill_items_no_update' => ['UPDATE', 'BEFORE', 'bill_items'],
            'trg_bill_items_no_delete' => ['DELETE', 'BEFORE', 'bill_items'],
            'trg_payments_relationship_guard' => ['INSERT', 'BEFORE', 'payments'],
            'trg_payments_final_immutable' => ['UPDATE', 'BEFORE', 'payments'],
            'trg_payments_no_delete' => ['DELETE', 'BEFORE', 'payments'],
            'trg_notification_relationship_guard' => ['INSERT', 'BEFORE', 'notification_outbox'],
            'trg_notification_relationship_guard_update' => ['UPDATE', 'BEFORE', 'notification_outbox'],
            'trg_audit_logs_no_update' => ['UPDATE', 'BEFORE', 'audit_logs'],
            'trg_audit_logs_no_delete' => ['DELETE', 'BEFORE', 'audit_logs'],
        ];
        $triggerStatement = $pdo->prepare('SELECT trigger_name,event_manipulation,action_timing,event_object_table FROM information_schema.triggers WHERE trigger_schema=?');
        $triggerStatement->execute([$database]);
        $foundTriggers = [];
        foreach ($triggerStatement->fetchAll() as $row) {
            $name = (string) ($row['TRIGGER_NAME'] ?? $row['trigger_name'] ?? '');
            $foundTriggers[$name] = [
                strtoupper((string) ($row['EVENT_MANIPULATION'] ?? $row['event_manipulation'] ?? '')),
                strtoupper((string) ($row['ACTION_TIMING'] ?? $row['action_timing'] ?? '')),
                (string) ($row['EVENT_OBJECT_TABLE'] ?? $row['event_object_table'] ?? ''),
            ];
        }
        $invalidTriggers = [];
        foreach ($expectedTriggers as $name => $definition) {
            if (($foundTriggers[$name] ?? null) !== $definition) {
                $invalidTriggers[] = $name;
            }
        }
        if ($invalidTriggers === []) {
            addResult($successes, 'พบ immutable/relationship triggers พร้อม event/timing ครบ 15 รายการ');
        } elseif ($schemaAudit || $grantInspection['can_read_all_triggers']) {
            addResult($errors, 'schema ขาด trigger หรือ event/timing ไม่ตรง: ' . implode(', ', $invalidTriggers));
        } else {
            addResult($skips, 'MySQL ซ่อน trigger metadata จากบัญชี runtime-only; รัน --schema-audit ด้วยบัญชี DBA เพื่อรับรอง triggers 15 รายการ');
        }

        $indexStatement = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema=? AND table_name='bill_items'
                AND index_name='uq_bill_items_bill_type' AND non_unique=0"
        );
        $indexStatement->execute([$database]);
        if ((int) $indexStatement->fetchColumn() === 2) {
            addResult($successes, 'พบ unique bill item type guard ครบ 2 คอลัมน์');
        } else {
            addResult($errors, 'schema ขาด unique index bill_items(bill_id,item_type)');
        }

        $requiredColumns = [
            'bookings.booked_monthly_rent' => ['decimal(12,2)', 'NO'],
            'bills.resident_name_snapshot' => ['varchar(150)', 'NO'],
            'bills.room_code_snapshot' => ['varchar(32)', 'NO'],
            'payments.verification_lease_until' => ['datetime(6)', 'YES'],
            'payments.verification_token' => ['char(64)', 'YES'],
            'payments.verification_attempts' => ['smallint unsigned', 'NO'],
        ];
        $columnStatement = $pdo->prepare(
            'SELECT table_name,column_name,column_type,is_nullable
               FROM information_schema.columns
              WHERE table_schema=?
                AND table_name IN (\'bookings\',\'bills\',\'payments\')'
        );
        $columnStatement->execute([$database]);
        $foundColumns = [];
        foreach ($columnStatement->fetchAll() as $row) {
            $tableName = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
            $columnName = (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');
            $foundColumns[$tableName . '.' . $columnName] = [
                strtolower((string) ($row['COLUMN_TYPE'] ?? $row['column_type'] ?? '')),
                strtoupper((string) ($row['IS_NULLABLE'] ?? $row['is_nullable'] ?? '')),
            ];
        }
        $invalidColumns = [];
        foreach ($requiredColumns as $column => $definition) {
            if (($foundColumns[$column] ?? null) !== $definition) {
                $invalidColumns[] = $column;
            }
        }
        if ($invalidColumns === []) {
            addResult($successes, 'พบ operational-hardening columns ครบ 6 คอลัมน์');
        } else {
            addResult($errors, 'schema ยังไม่ได้ใช้ migration 002 หรือชนิดคอลัมน์ไม่ตรง: ' . implode(', ', $invalidColumns));
        }

        $checkStatement=$pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=? AND constraint_type='CHECK'");
        $checkStatement->execute([$database]);
        $checkCount=(int)$checkStatement->fetchColumn();
        if($checkCount>=73)addResult($successes,'พบ CHECK constraints ครบอย่างน้อย 73 รายการ');
        else addResult($errors,'schema มี CHECK constraints ไม่ครบ; พบ '.$checkCount.' จากอย่างน้อย 73');

        // A generated UNIQUE guard is ineffective when its expression has been
        // changed to always return NULL. Verify the complete definition of all
        // five write-critical generated columns, not only their names.
        $requiredGeneratedColumns = [
            'bookings.active_room_id' => [
                'type' => 'bigint unsigned',
                'expressions' => [
                    "casewhenstatusin'pending','confirmed'thenroom_idelsenullend",
                    "casewhenstatusin'confirmed','pending'thenroom_idelsenullend",
                ],
            ],
            'bookings.active_phone_norm' => [
                'type' => 'char(10)',
                'expressions' => [
                    "casewhenstatusin'pending','confirmed'thenphone_normelsenullend",
                    "casewhenstatusin'confirmed','pending'thenphone_normelsenullend",
                ],
            ],
            'occupancies.active_room_id' => [
                'type' => 'bigint unsigned',
                'expressions' => ["casewhenstatus='active'thenroom_idelsenullend"],
            ],
            'occupancies.active_resident_id' => [
                'type' => 'bigint unsigned',
                'expressions' => ["casewhenstatus='active'thenresident_idelsenullend"],
            ],
            'payments.active_bill_id' => [
                'type' => 'bigint unsigned',
                'expressions' => [
                    "casewhenstatusin'pending','verified'thenbill_idelsenullend",
                    "casewhenstatusin'verified','pending'thenbill_idelsenullend",
                ],
            ],
        ];
        $generatedPredicates = [];
        $generatedParameters = [$database];
        foreach (array_keys($requiredGeneratedColumns) as $qualifiedColumn) {
            [$table, $column] = explode('.', $qualifiedColumn, 2);
            $generatedPredicates[] = '(table_name=? AND column_name=?)';
            $generatedParameters[] = $table;
            $generatedParameters[] = $column;
        }
        $generatedStatement = $pdo->prepare(
            'SELECT table_name,column_name,column_type,is_nullable,extra,generation_expression'
            . ' FROM information_schema.columns WHERE table_schema=? AND ('
            . implode(' OR ', $generatedPredicates) . ') ORDER BY table_name,column_name'
        );
        $generatedStatement->execute($generatedParameters);
        $foundGeneratedColumns = [];
        $invalidGeneratedColumns = [];
        foreach ($generatedStatement->fetchAll() as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
            $column = (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');
            $qualifiedColumn = $table . '.' . $column;
            $expected = $requiredGeneratedColumns[$qualifiedColumn] ?? null;
            $expression = $row['GENERATION_EXPRESSION'] ?? $row['generation_expression'] ?? null;
            if (!is_array($expected) || isset($foundGeneratedColumns[$qualifiedColumn])
                || !is_string($expression)) {
                $invalidGeneratedColumns[] = $qualifiedColumn;
                continue;
            }
            $foundGeneratedColumns[$qualifiedColumn] = true;
            $normalizedExpression = strtolower(str_replace("\\'", "'", $expression));
            $normalizedExpression = preg_replace("/_[a-z0-9_]+'/", "'", $normalizedExpression);
            $normalizedExpression = str_replace(['`', '(', ')'], '', (string) $normalizedExpression);
            $normalizedExpression = preg_replace('/\s+/', '', $normalizedExpression);
            $columnType = strtolower((string) ($row['COLUMN_TYPE'] ?? $row['column_type'] ?? ''));
            $nullable = strtoupper((string) ($row['IS_NULLABLE'] ?? $row['is_nullable'] ?? ''));
            $extra = strtoupper(trim((string) ($row['EXTRA'] ?? $row['extra'] ?? '')));
            if ($columnType !== $expected['type'] || $nullable !== 'YES'
                || $extra !== 'STORED GENERATED' || !is_string($normalizedExpression)
                || !in_array($normalizedExpression, $expected['expressions'], true)) {
                $invalidGeneratedColumns[] = $qualifiedColumn;
            }
        }
        $missingGeneratedColumns = array_values(array_diff(
            array_keys($requiredGeneratedColumns),
            array_keys($foundGeneratedColumns),
        ));
        $invalidGeneratedColumns = array_values(array_unique(array_merge(
            $invalidGeneratedColumns,
            $missingGeneratedColumns,
        )));
        if ($invalidGeneratedColumns === []) {
            addResult($successes, 'พบ generated uniqueness guards ที่มีนิยามถูกต้องครบ 5 คอลัมน์');
        } else {
            addResult($errors, 'schema ขาดหรือมีนิยาม generated uniqueness guards ไม่ถูกต้อง: '
                . implode(', ', $invalidGeneratedColumns));
        }

        $bookingPhoneColumnStatement=$pdo->prepare("SELECT column_type,is_nullable,extra,generation_expression
            FROM information_schema.columns
            WHERE table_schema=? AND table_name='bookings' AND column_name='active_phone_norm'");
        $bookingPhoneColumnStatement->execute([$database]);
        $bookingPhoneColumn=$bookingPhoneColumnStatement->fetch();
        $bookingPhoneExpression=is_array($bookingPhoneColumn)
            ? \Dormitory\Support\SchemaGuard::activePhoneGenerationExpression(
                $bookingPhoneColumn['GENERATION_EXPRESSION']??$bookingPhoneColumn['generation_expression']??null
            )
            : null;
        $bookingPhoneColumnExact=is_array($bookingPhoneColumn)
            &&strtolower((string)($bookingPhoneColumn['COLUMN_TYPE']??$bookingPhoneColumn['column_type']??''))==='char(10)'
            &&strtoupper((string)($bookingPhoneColumn['IS_NULLABLE']??$bookingPhoneColumn['is_nullable']??''))==='YES'
            &&strtoupper(trim((string)($bookingPhoneColumn['EXTRA']??$bookingPhoneColumn['extra']??'')))==='STORED GENERATED'
            &&$bookingPhoneExpression!==null;
        if($bookingPhoneColumnExact)addResult($successes,'generated column bookings.active_phone_norm มีนิพจน์ CASE ที่ถูกต้อง');
        else addResult($errors,'bookings.active_phone_norm ต้องเป็น stored CHAR(10) และใช้ CASE status pending/confirmed เป็น phone_norm มิฉะนั้นเป็น NULL เท่านั้น');

        $bookingPhoneIndexStatement=$pdo->prepare("SELECT column_name,non_unique,seq_in_index,sub_part
            FROM information_schema.statistics
            WHERE table_schema=? AND table_name='bookings'
              AND index_name='uq_bookings_one_active_per_phone'
            ORDER BY seq_in_index");
        $bookingPhoneIndexStatement->execute([$database]);
        $bookingPhoneIndexRows=$bookingPhoneIndexStatement->fetchAll();
        $bookingPhoneIndexExact=count($bookingPhoneIndexRows)===1;
        if($bookingPhoneIndexExact){
            $indexRow=$bookingPhoneIndexRows[0];
            $bookingPhoneIndexExact=(string)($indexRow['COLUMN_NAME']??$indexRow['column_name']??'')==='active_phone_norm'
                &&(int)($indexRow['NON_UNIQUE']??$indexRow['non_unique']??1)===0
                &&(int)($indexRow['SEQ_IN_INDEX']??$indexRow['seq_in_index']??0)===1
                &&($indexRow['SUB_PART']??$indexRow['sub_part']??null)===null;
        }
        if($bookingPhoneIndexExact)addResult($successes,'พบ unique index bookings(active_phone_norm) แบบเต็มคอลัมน์');
        else addResult($errors,'schema ขาด unique index bookings(active_phone_norm) แบบคอลัมน์เดียวเต็มความยาวจาก migration 005');

        $requiredLineColumns = [
            'integration_settings.line_channel_secret_enc' => ['text', 'YES'],
            'notification_outbox.line_accepted_request_id' => ['varchar(128)', 'YES'],
            'notification_outbox.line_request_id' => ['varchar(128)', 'YES'],
            'notification_outbox.recipient' => ['varchar(33)', 'NO'],
            'residents.line_user_id' => ['varchar(33)', 'YES'],
        ];
        $lineColumnsStatement=$pdo->prepare("SELECT table_name,column_name,column_type,is_nullable
            FROM information_schema.columns
            WHERE table_schema=? AND (
                (table_name='integration_settings' AND column_name='line_channel_secret_enc')
                OR (table_name='notification_outbox' AND column_name IN ('line_request_id','line_accepted_request_id','recipient'))
                OR (table_name='residents' AND column_name='line_user_id')
            )");
        $lineColumnsStatement->execute([$database]);
        $lineColumns=[];
        foreach($lineColumnsStatement->fetchAll() as $row){
            $table=(string)($row['TABLE_NAME']??$row['table_name']??'');
            $column=(string)($row['COLUMN_NAME']??$row['column_name']??'');
            $lineColumns[$table.'.'.$column]=[
                strtolower((string)($row['COLUMN_TYPE']??$row['column_type']??'')),
                strtoupper((string)($row['IS_NULLABLE']??$row['is_nullable']??'')),
            ];
        }
        $invalidLineColumns=[];
        foreach($requiredLineColumns as $column=>$definition){
            if(($lineColumns[$column]??null)!==$definition)$invalidLineColumns[]=$column;
        }
        if($invalidLineColumns===[])addResult($successes,'พบคอลัมน์ LINE webhook/reconciliation และชนิดข้อมูลครบ 5 คอลัมน์');
        else addResult($errors,'schema ขาดคอลัมน์/ชนิดข้อมูลจาก migration 004: '.implode(', ',$invalidLineColumns));

        $requiredLineChecks=['chk_integration_settings_line_secret','chk_notification_outbox_line_accepted_request_id','chk_notification_outbox_line_request_id','chk_notification_outbox_recipient','chk_residents_line_user_id'];
        $lineChecksStatement=$pdo->prepare("SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_schema=? AND constraint_type='CHECK' AND constraint_name IN ('chk_integration_settings_line_secret','chk_notification_outbox_line_accepted_request_id','chk_notification_outbox_line_request_id','chk_notification_outbox_recipient','chk_residents_line_user_id')");
        $lineChecksStatement->execute([$database]);
        $lineChecks=array_map(static fn(array $row):string=>(string)($row['CONSTRAINT_NAME']??$row['constraint_name']??''),$lineChecksStatement->fetchAll());
        $missingLineChecks=array_values(array_diff($requiredLineChecks,$lineChecks));
        if($missingLineChecks===[])addResult($successes,'พบ LINE webhook/reconciliation CHECK constraints ครบ 5 รายการ');
        else addResult($errors,'schema ขาด CHECK constraints จาก migration 004: '.implode(', ',$missingLineChecks));

        if ($missing === [] && $invalidLineColumns === []) {
            $ownerCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role='owner' AND active=1")->fetchColumn();
            if ($ownerCount < 1) {
                addResult($warnings, 'ฐานข้อมูลยังไม่มี owner ที่ active');
            } else {
                addResult($successes, 'พบ owner ที่ active อย่างน้อย 1 บัญชี');
            }
            $billingSettings = $pdo->query('SELECT water_rate,electric_rate,due_days,updated_by FROM billing_settings WHERE id=1')->fetch();
            if (!$billingSettings) addResult($warnings, 'ไม่พบ billing_settings id=1; import database/defaults.sql แล้วบันทึกค่าจริงผ่านหน้า Admin -> ตั้งค่า');
            elseif ($billingSettings['updated_by'] === null) addResult($warnings, 'ยังไม่ได้ยืนยันค่าน้ำ ค่าไฟ และวันครบกำหนดผ่านหลังบ้าน; ระบบจะปฏิเสธการออกบิล');
            else addResult($successes, 'ผู้ดูแลยืนยันค่าการเรียกเก็บรายเดือนผ่านหลังบ้านแล้ว');
            $integration = $pdo->query(
                'SELECT promptpay_target,payment_receiver_account_tail,
                        line_channel_access_token_enc,line_channel_secret_enc,slip_provider,
                        slipok_api_key_enc,slipok_branch_id,easyslip_api_key_enc
                   FROM integration_settings WHERE id=1'
            )->fetch();
            if (!$integration) {
                addResult($warnings, 'ไม่พบ integration_settings id=1; รัน migration 001 หรือ import database/defaults.sql');
            } else {
                $promptPayReady = configuredDatabaseValue($integration['promptpay_target'] ?? null);
                if ($promptPayReady) addResult($successes, 'ตั้ง PromptPay จากหลังบ้านแล้ว');
                else addResult($warnings, 'ยังไม่ได้ตั้ง PromptPay จากหลังบ้าน; QR ชำระเงินจะยังไม่พร้อม');

                $lineReady = configuredIntegrationSecret(
                    $integration['line_channel_access_token_enc'] ?? null,
                    'line_channel_access_token',
                    $secretCipher,
                    $errors,
                );
                $lineChannelSecretReady = configuredIntegrationSecret(
                    $integration['line_channel_secret_enc'] ?? null,
                    'line_channel_secret',
                    $secretCipher,
                    $errors,
                );
                $slipOkKeyReady = configuredIntegrationSecret(
                    $integration['slipok_api_key_enc'] ?? null,
                    'slipok_api_key',
                    $secretCipher,
                    $errors,
                );
                $easySlipKeyReady = configuredIntegrationSecret(
                    $integration['easyslip_api_key_enc'] ?? null,
                    'easyslip_api_key',
                    $secretCipher,
                    $errors,
                );

                if ($lineReady) addResult($successes, 'ตั้ง LINE Channel access token แบบเข้ารหัสและตรวจสอบความถูกต้องแล้ว');
                else addResult($warnings, 'ยังไม่ได้ตั้ง LINE token จากหลังบ้าน; worker จะยังส่งบิลไม่ได้');
                if ($lineReady && $lineChannelSecretReady) addResult($successes, 'ตั้ง LINE Channel secret แล้ว; signed webhook พร้อมตรวจสอบลายเซ็น');
                else addResult($warnings, 'ยังตั้ง LINE webhook ไม่ครบ; ต้องมีทั้ง Channel access token และ Channel secret');

                $provider = (string) ($integration['slip_provider'] ?? 'none');
                // PromptPay is intentionally not a receiver-account fallback:
                // runtime slip readiness requires the explicit 6-20 digit
                // payment_receiver_account_tail as well.
                $receiverReady = configuredDatabaseValue($integration['payment_receiver_account_tail'] ?? null);
                $slipReady = $provider === 'slipok'
                    ? $slipOkKeyReady && configuredDatabaseValue($integration['slipok_branch_id'] ?? null) && $receiverReady
                    : ($provider === 'easyslip' ? $easySlipKeyReady && $receiverReady : false);
                if ($slipReady) addResult($successes, 'การตรวจสลิปอัตโนมัติพร้อมใช้ด้วย ' . $provider);
                else addResult($warnings, 'การตรวจสลิปอัตโนมัติยังตั้งค่าไม่ครบ; เปิด Admin -> Settings เพื่อตรวจสถานะ');
            }
        }
    } catch (Throwable $error) {
        $driverCode = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
        $message = match ($driverCode) {
            1045 => 'MySQL ปฏิเสธชื่อผู้ใช้หรือรหัสผ่าน; ตรวจ DB_USERNAME/DB_PASSWORD และ host grant',
            1049 => 'ไม่พบฐานข้อมูล DB_DATABASE; สร้าง database และ import schema.sql ก่อน',
            1142, 1143 => 'บัญชี DB_USERNAME ไม่มีสิทธิ์ runtime ที่จำเป็น; รัน 03-runtime-grants.sh ด้วยบัญชี DBA',
            2002, 2003 => 'ติดต่อ MySQL ไม่ได้; ตรวจ DB_HOST/DB_PORT, service, firewall และ Docker port',
            2026 => 'เชื่อมต่อ TLS กับ MySQL ไม่สำเร็จ; ตรวจ DB_SSL และ DB_SSL_CA',
            default => 'เชื่อมต่อ/ตรวจ MySQL ไม่สำเร็จ; ตรวจ host, port, database, user, password, TLS และลำดับ import SQL',
        };
        addResult($errors, $message);
    }
} elseif (!$checkDatabase) {
    addResult($warnings, 'ยังไม่ได้ตรวจฐานข้อมูล; รันซ้ำพร้อม --db หลัง import SQL');
}

echo "\nDormitory PHP/MySQL requirement check\n";
echo str_repeat('=', 39) . "\n";
foreach ($successes as $message) {
    echo "[OK]   {$message}\n";
}
foreach ($skips as $message) {
    echo "[SKIP] {$message}\n";
}
foreach ($warnings as $message) {
    echo "[WARN] {$message}\n";
}
foreach ($errors as $message) {
    echo "[FAIL] {$message}\n";
}
echo "\nสรุป: " . count($successes) . " ผ่าน, " . count($skips) . " ข้ามโดยตั้งใจ, " . count($warnings) . " คำเตือน, " . count($errors) . " ไม่ผ่าน\n";

if ($errors !== [] || ($strict && $warnings !== [])) {
    exit(1);
}
exit(0);
