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
require_once $root . '/src/Support/LinePlatformSchema.php';
require_once $root . '/src/Support/PendingOpeningSchema.php';

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
$notices = [];

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

function normalizeTriggerAction(string $sql): string
{
    $normalized = preg_replace('/\s+/u', '', str_replace('`', '', $sql));
    return strtolower(is_string($normalized) ? $normalized : '');
}

/** @return array<string,string> */
function expectedTriggerActions(string $schemaPath): array
{
    $schema = file_get_contents($schemaPath);
    if (!is_string($schema)) {
        return [];
    }
    $matched = preg_match_all(
        '/CREATE\s+TRIGGER\s+([a-z0-9_]+)\s+'
        . '(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+[a-z0-9_]+\s+'
        . 'FOR\s+EACH\s+ROW\s+(BEGIN.*?\bEND)\s*\$\$/isu',
        $schema,
        $matches,
        PREG_SET_ORDER,
    );
    if ($matched === false) {
        return [];
    }
    $actions = [];
    foreach ($matches as $match) {
        $name = strtolower((string) ($match[1] ?? ''));
        $body = normalizeTriggerAction((string) ($match[2] ?? ''));
        if ($name === '' || $body === '' || isset($actions[$name])) {
            return [];
        }
        $actions[$name] = $body;
    }
    return $actions;
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
$residentActivationTtl = filter_var(
    envValue($env, 'RESIDENT_ACTIVATION_TTL_SECONDS', '604800'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 900, 'max_range' => 2592000]],
);
if ($residentActivationTtl === false) {
    addResult($errors, 'RESIDENT_ACTIVATION_TTL_SECONDS ต้องเป็นเลข 900-2592000');
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
        if ($dbSsl) {
            if (!defined('PDO::MYSQL_ATTR_SSL_CA')
                || !defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                throw new RuntimeException('pdo_mysql TLS identity-verification support is unavailable');
            }
            $ca = trim((string) envValue($env, 'DB_SSL_CA', ''));
            if ($ca === '' || !is_file($ca) || !is_readable($ca)) {
                throw new RuntimeException('DB_SSL_CA is not a readable CA file');
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
            'admin_users', 'residents', 'line_link_codes', 'rooms', 'bookings', 'occupancies',
            'meter_readings', 'billing_settings', 'integration_settings', 'bills', 'bill_items', 'payments',
            'notification_outbox', 'notification_worker_heartbeats', 'audit_logs', 'rate_limits', 'transfer_instructions',
            'line_official_accounts','line_room_bindings','line_room_policies','line_admin_recipients','line_notice_outbox',
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
            addResult($successes, 'พบตารางระบบครบ 21 ตาราง รวม LINE OA และผู้รับหลายบัญชี');
            $lineSchemaErrors=\Dormitory\Support\LinePlatformSchema::errors($pdo);
            if($lineSchemaErrors===[])addResult($successes,'LINE platform columns, generated guards, indexes, foreign keys, CHECK constraints และ legacy OA ถูกต้อง');
            else foreach($lineSchemaErrors as$lineSchemaError)addResult($errors,$lineSchemaError);
            $openingSchemaErrors=\Dormitory\Support\PendingOpeningSchema::errors($pdo);
            if($openingSchemaErrors===[])addResult($successes,'migration 015 enforced opening-reading CHECK ตรง canonical');
            else foreach($openingSchemaErrors as$openingSchemaError)addResult($errors,$openingSchemaError);
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
            addResult($errors,'schema ยังมี residents.pin_hash ซึ่งไม่รองรับกับ source ปัจจุบัน; ใช้ transitional commit a52bc33 ให้ทุก replica พร้อม รัน migration 006 ตรวจว่าคอลัมน์หาย แล้วดำเนิน migrations 007–015 ตาม maintenance guide ก่อน deploy source ปัจจุบัน');
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
            'trg_transfer_insert_guard'=>['INSERT','BEFORE','transfer_instructions'],
            'trg_transfer_update_guard'=>['UPDATE','BEFORE','transfer_instructions'],
            'trg_transfer_no_delete'=>['DELETE','BEFORE','transfer_instructions'],
            'trg_bookings_insert_guard' => ['INSERT', 'BEFORE', 'bookings'],
            'trg_bookings_identity_immutable' => ['UPDATE', 'BEFORE', 'bookings'],
            'trg_occupancies_relationship_guard' => ['INSERT', 'BEFORE', 'occupancies'],
            'trg_occupancies_identity_immutable' => ['UPDATE', 'BEFORE', 'occupancies'],
            'trg_meter_readings_occupancy_guard' => ['INSERT', 'BEFORE', 'meter_readings'],
            'trg_meter_readings_occupancy_guard_update' => ['UPDATE', 'BEFORE', 'meter_readings'],
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
            'trg_line_room_bindings_insert_guard' => ['INSERT','BEFORE','line_room_bindings'],
            'trg_line_room_bindings_update_guard' => ['UPDATE','BEFORE','line_room_bindings'],
            'trg_line_notice_relationship_guard' => ['INSERT','BEFORE','line_notice_outbox'],
            'trg_line_notice_relationship_guard_update' => ['UPDATE','BEFORE','line_notice_outbox'],
        ];
        $expectedTriggerBodies=expectedTriggerActions($root.'/database/schema.sql');
        $triggerStatement = $pdo->prepare('SELECT trigger_name,event_manipulation,action_timing,event_object_table,action_statement FROM information_schema.triggers WHERE trigger_schema=?');
        $triggerStatement->execute([$database]);
        $foundTriggers = [];
        $foundTriggerBodies=[];
        foreach ($triggerStatement->fetchAll() as $row) {
            $name = (string) ($row['TRIGGER_NAME'] ?? $row['trigger_name'] ?? '');
            $foundTriggers[$name] = [
                strtoupper((string) ($row['EVENT_MANIPULATION'] ?? $row['event_manipulation'] ?? '')),
                strtoupper((string) ($row['ACTION_TIMING'] ?? $row['action_timing'] ?? '')),
                (string) ($row['EVENT_OBJECT_TABLE'] ?? $row['event_object_table'] ?? ''),
            ];
            $foundTriggerBodies[$name]=normalizeTriggerAction(
                (string)($row['ACTION_STATEMENT']??$row['action_statement']??'')
            );
        }
        $invalidTriggers = [];
        $invalidTriggerBodies=[];
        foreach ($expectedTriggers as $name => $definition) {
            if (($foundTriggers[$name] ?? null) !== $definition) {
                $invalidTriggers[] = $name;
            }
            if(!isset($expectedTriggerBodies[$name])
                ||($foundTriggerBodies[$name]??null)!==$expectedTriggerBodies[$name]){
                $invalidTriggerBodies[]=$name;
            }
        }
        if(count($expectedTriggerBodies)!==count($expectedTriggers)){
            addResult($errors,'อ่าน canonical trigger bodies จาก database/schema.sql ไม่ครบ');
        }elseif ($invalidTriggers === []&&$invalidTriggerBodies===[]) {
            addResult($successes, 'พบ integrity triggers พร้อม event/timing/body ตรง canonical ครบ 26 รายการ');
        } elseif ($schemaAudit || $grantInspection['can_read_all_triggers']) {
            $invalid=array_values(array_unique(array_merge($invalidTriggers,$invalidTriggerBodies)));
            addResult($errors, 'schema ขาด trigger หรือ event/timing/body ไม่ตรง canonical: ' . implode(', ', $invalid));
        } else {
            addResult($skips, 'MySQL ซ่อน trigger metadata/body จากบัญชี runtime-only; รัน --schema-audit ด้วยบัญชี DBA เพื่อรับรอง triggers 26 รายการ');
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
            'bookings.move_in_request_hash' => ['char(64)', 'YES'],
            'bills.resident_name_snapshot' => ['varchar(150)', 'NO'],
            'bills.room_code_snapshot' => ['varchar(32)', 'NO'],
            'payments.verification_lease_until' => ['datetime(6)', 'YES'],
            'payments.verification_token' => ['char(64)', 'YES'],
            'payments.verification_attempts' => ['smallint unsigned', 'NO'],
            'residents.access_password_hash' => ['varchar(255)', 'YES'],
            'residents.activation_code_hash' => ['char(64)', 'YES'],
            'residents.activation_expires_at' => ['datetime(6)', 'YES'],
            'residents.activation_consumed_at' => ['datetime(6)', 'YES'],
            'line_link_codes.id' => ['bigint unsigned', 'NO'],
            'line_link_codes.resident_id' => ['bigint unsigned', 'NO'],
            'line_link_codes.code_hash' => ['char(64)', 'NO'],
            'line_link_codes.status' => ["enum('pending','bound','expired','revoked')", 'NO'],
            'line_link_codes.line_user_id' => ['varchar(33)', 'YES'],
            'line_link_codes.expires_at' => ['datetime(6)', 'NO'],
            'line_link_codes.bound_at' => ['datetime(6)', 'YES'],
            'line_link_codes.revoked_at' => ['datetime(6)', 'YES'],
            'line_link_codes.created_at' => ['datetime(6)', 'NO'],
            'line_link_codes.updated_at' => ['datetime(6)', 'NO'],
            'line_link_codes.pending_resident_id' => ['bigint unsigned', 'YES'],
            'occupancies.opening_water_reading' => ['decimal(14,2)', 'YES'],
            'occupancies.opening_electric_reading' => ['decimal(14,2)', 'YES'],
            'meter_readings.occupancy_id' => ['bigint unsigned', 'YES'],
            'notification_outbox.claim_token' => ['char(64)', 'YES'],
            'notification_outbox.lease_until' => ['datetime(6)', 'YES'],
            'notification_worker_heartbeats.worker_id' => ['char(64)', 'NO'],
            'notification_worker_heartbeats.status' => ["enum('starting','running','error','stopped')", 'NO'],
            'notification_worker_heartbeats.started_at' => ['datetime(6)', 'NO'],
            'notification_worker_heartbeats.heartbeat_at' => ['datetime(6)', 'NO'],
            'notification_worker_heartbeats.last_cycle_at' => ['datetime(6)', 'YES'],
            'notification_worker_heartbeats.last_processed' => ['int unsigned', 'NO'],
            'notification_worker_heartbeats.last_sent' => ['int unsigned', 'NO'],
            'notification_worker_heartbeats.last_failed' => ['int unsigned', 'NO'],
            'notification_worker_heartbeats.last_retried' => ['int unsigned', 'NO'],
            'notification_worker_heartbeats.last_lost_claims' => ['int unsigned', 'NO'],
            'notification_worker_heartbeats.last_recovered' => ['int unsigned', 'NO'],
            'notification_worker_heartbeats.last_error' => ['varchar(1000)', 'YES'],
            'notification_worker_heartbeats.created_at' => ['datetime(6)', 'NO'],
            'notification_worker_heartbeats.updated_at' => ['datetime(6)', 'NO'],
        ];
        $columnStatement = $pdo->prepare(
            'SELECT table_name,column_name,column_type,is_nullable
               FROM information_schema.columns
              WHERE table_schema=?
                AND table_name IN (
                    \'bookings\',\'bills\',\'payments\',\'residents\',\'occupancies\',
                    \'meter_readings\',\'notification_outbox\',\'notification_worker_heartbeats\',
                    \'line_link_codes\'
                )'
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
            addResult($successes, 'พบคอลัมน์และชนิดข้อมูลที่ต้องใช้จาก migrations 002/007/008/009/010/012 ครบ '
                . count($requiredColumns) . ' คอลัมน์');
        } else {
            addResult($errors, 'schema ขาดคอลัมน์หรือชนิดข้อมูลจาก migrations 002/007/008/009/010/012 ไม่ตรง: '
                . implode(', ', $invalidColumns));
        }

        $checkStatement=$pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=? AND constraint_type='CHECK'");
        $checkStatement->execute([$database]);
        $checkCount=(int)$checkStatement->fetchColumn();
        if($checkCount>=119)addResult($successes,'พบ CHECK constraints ครบอย่างน้อย 119 รายการ');
        else addResult($errors,'schema มี CHECK constraints ไม่ครบ; พบ '.$checkCount.' จากอย่างน้อย 119');

        // A generated UNIQUE guard is ineffective when its expression has been
        // changed to always return NULL. Verify the complete definition of all
        // six write-critical generated columns, not only their names.
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
            'line_link_codes.pending_resident_id' => [
                'type' => 'bigint unsigned',
                'expressions' => ["casewhenstatus='pending'thenresident_idelsenullend"],
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
            addResult($successes, 'พบ generated uniqueness guards ที่มีนิยามถูกต้องครบ 6 คอลัมน์');
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
            'integration_settings.line_basic_id' => ['varchar(33)', 'YES'],
            'notification_outbox.line_accepted_request_id' => ['varchar(128)', 'YES'],
            'notification_outbox.line_request_id' => ['varchar(128)', 'YES'],
            'notification_outbox.recipient' => ['varchar(33)', 'NO'],
            'residents.line_user_id' => ['varchar(33)', 'YES'],
        ];
        $lineColumnsStatement=$pdo->prepare("SELECT table_name,column_name,column_type,is_nullable
            FROM information_schema.columns
            WHERE table_schema=? AND (
                (table_name='integration_settings' AND column_name IN ('line_channel_secret_enc','line_basic_id'))
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
        if($invalidLineColumns===[])addResult($successes,'พบคอลัมน์ LINE webhook/reconciliation/Basic ID และชนิดข้อมูลครบ 6 คอลัมน์');
        else addResult($errors,'schema ขาดคอลัมน์/ชนิดข้อมูลจาก migration 004/011: '.implode(', ',$invalidLineColumns));

        $requiredLineChecks=['chk_integration_settings_line_secret','chk_integration_settings_line_basic_id','chk_notification_outbox_line_accepted_request_id','chk_notification_outbox_line_request_id','chk_notification_outbox_recipient','chk_residents_line_user_id'];
        $lineChecksStatement=$pdo->prepare("SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_schema=? AND constraint_type='CHECK' AND constraint_name IN ('chk_integration_settings_line_secret','chk_integration_settings_line_basic_id','chk_notification_outbox_line_accepted_request_id','chk_notification_outbox_line_request_id','chk_notification_outbox_recipient','chk_residents_line_user_id')");
        $lineChecksStatement->execute([$database]);
        $lineChecks=array_map(static fn(array $row):string=>(string)($row['CONSTRAINT_NAME']??$row['constraint_name']??''),$lineChecksStatement->fetchAll());
        $missingLineChecks=array_values(array_diff($requiredLineChecks,$lineChecks));
        if($missingLineChecks===[])addResult($successes,'พบ LINE webhook/reconciliation/Basic ID CHECK constraints ครบ 6 รายการ');
        else addResult($errors,'schema ขาด CHECK constraints จาก migration 004/011: '.implode(', ',$missingLineChecks));

        $requiredCurrentChecks=[
            'chk_notification_outbox_claim_lease',
            'chk_notification_worker_error',
            'chk_notification_worker_id',
            'chk_occupancies_opening_readings_v2',
            'chk_residents_access_password',
            'chk_residents_activation_state',
            'chk_residents_password_activation',
            'chk_line_link_codes_hash',
            'chk_line_link_codes_line_user',
            'chk_line_link_codes_state',
            'chk_line_link_codes_timestamps',
            'chk_bookings_move_in_request_hash',
        ];
        $currentChecksStatement=$pdo->prepare(
            "SELECT constraint_name FROM information_schema.table_constraints
              WHERE constraint_schema=? AND constraint_type='CHECK'
                AND constraint_name IN ("
            .implode(',',array_fill(0,count($requiredCurrentChecks),'?')).')'
        );
        $currentChecksStatement->execute(array_merge([$database],$requiredCurrentChecks));
        $currentChecks=array_map(
            static fn(array $row):string=>(string)($row['CONSTRAINT_NAME']??$row['constraint_name']??''),
            $currentChecksStatement->fetchAll()
        );
        $missingCurrentChecks=array_values(array_diff($requiredCurrentChecks,$currentChecks));
        if($missingCurrentChecks===[]){
            addResult($successes,'พบ CHECK constraints ของ migrations 007/008/009/010/012/015 ครบ 12 รายการ');
        }else{
            addResult($errors,'schema ขาด CHECK constraints ของ migrations 007/008/009/010/012/015: '
                .implode(', ',$missingCurrentChecks));
        }

        $requiredOperationalIndexes=[
            'meter_readings.idx_meter_readings_occupancy_period'=>['occupancy_id','period'],
            'notification_outbox.idx_notification_outbox_lease'=>['status','lease_until'],
            'notification_worker_heartbeats.idx_notification_worker_heartbeat'=>['heartbeat_at'],
            'line_link_codes.idx_line_link_codes_expiry'=>['status','expires_at'],
            'line_link_codes.idx_line_link_codes_resident'=>['resident_id','created_at'],
        ];
        $operationalIndexPredicates=[];
        $operationalIndexParameters=[$database];
        foreach(array_keys($requiredOperationalIndexes)as$qualifiedIndex){
            [$table,$index]=explode('.',$qualifiedIndex,2);
            $operationalIndexPredicates[]='(table_name=? AND index_name=?)';
            $operationalIndexParameters[]=$table;
            $operationalIndexParameters[]=$index;
        }
        $operationalIndexesStatement=$pdo->prepare(
            'SELECT table_name,index_name,column_name,non_unique,seq_in_index,sub_part'
            .' FROM information_schema.statistics WHERE table_schema=? AND ('
            .implode(' OR ',$operationalIndexPredicates).') ORDER BY table_name,index_name,seq_in_index'
        );
        $operationalIndexesStatement->execute($operationalIndexParameters);
        $foundOperationalIndexes=[];
        $invalidOperationalIndexes=[];
        foreach($operationalIndexesStatement->fetchAll()as$row){
            $qualifiedIndex=(string)($row['TABLE_NAME']??$row['table_name']??'').'.'
                .(string)($row['INDEX_NAME']??$row['index_name']??'');
            if(!isset($requiredOperationalIndexes[$qualifiedIndex])
                ||(int)($row['NON_UNIQUE']??$row['non_unique']??0)!==1
                ||(int)($row['SEQ_IN_INDEX']??$row['seq_in_index']??0)
                    !==count($foundOperationalIndexes[$qualifiedIndex]??[])+1
                ||($row['SUB_PART']??$row['sub_part']??null)!==null){
                $invalidOperationalIndexes[]=$qualifiedIndex;
                continue;
            }
            $foundOperationalIndexes[$qualifiedIndex][]=(string)(
                $row['COLUMN_NAME']??$row['column_name']??''
            );
        }
        foreach($requiredOperationalIndexes as$qualifiedIndex=>$definition){
            if(($foundOperationalIndexes[$qualifiedIndex]??null)!==$definition){
                $invalidOperationalIndexes[]=$qualifiedIndex;
            }
        }
        $invalidOperationalIndexes=array_values(array_unique($invalidOperationalIndexes));
        if($invalidOperationalIndexes===[]){
            addResult($successes,'พบ operational indexes ของ migrations 007/009/010 ครบ 5 รายการ');
        }else{
            addResult($errors,'schema ขาดหรือมีนิยาม operational index ไม่ตรง: '
                .implode(', ',$invalidOperationalIndexes));
        }

        $meterOccupancyForeignKeyStatement=$pdo->prepare(
            "SELECT k.column_name,k.referenced_table_name,k.referenced_column_name,
                    r.update_rule,r.delete_rule
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints r
                 ON r.constraint_schema=k.constraint_schema
                AND r.table_name=k.table_name
                AND r.constraint_name=k.constraint_name
              WHERE k.constraint_schema=? AND k.table_name='meter_readings'
                AND k.constraint_name='fk_meter_readings_occupancy'
              ORDER BY k.ordinal_position"
        );
        $meterOccupancyForeignKeyStatement->execute([$database]);
        $meterOccupancyForeignKey=$meterOccupancyForeignKeyStatement->fetchAll();
        $meterOccupancyForeignKeyExact=count($meterOccupancyForeignKey)===1;
        if($meterOccupancyForeignKeyExact){
            $foreignKeyRow=$meterOccupancyForeignKey[0];
            $meterOccupancyForeignKeyExact=
                (string)($foreignKeyRow['COLUMN_NAME']??$foreignKeyRow['column_name']??'')==='occupancy_id'
                &&(string)($foreignKeyRow['REFERENCED_TABLE_NAME']??$foreignKeyRow['referenced_table_name']??'')==='occupancies'
                &&(string)($foreignKeyRow['REFERENCED_COLUMN_NAME']??$foreignKeyRow['referenced_column_name']??'')==='id'
                &&strtoupper((string)($foreignKeyRow['UPDATE_RULE']??$foreignKeyRow['update_rule']??''))==='RESTRICT'
                &&strtoupper((string)($foreignKeyRow['DELETE_RULE']??$foreignKeyRow['delete_rule']??''))==='RESTRICT';
        }
        if($meterOccupancyForeignKeyExact){
            addResult($successes,'พบ foreign key meter_readings(occupancy_id) ไป occupancies(id) แบบ RESTRICT');
        }else{
            addResult($errors,'schema ขาดหรือมีนิยาม fk_meter_readings_occupancy ไม่ตรงกับ migration 009');
        }

        $lineCodeForeignKeyStatement=$pdo->prepare(
            "SELECT k.column_name,k.referenced_table_name,k.referenced_column_name,
                    r.update_rule,r.delete_rule
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints r
                 ON r.constraint_schema=k.constraint_schema
                AND r.table_name=k.table_name
                AND r.constraint_name=k.constraint_name
              WHERE k.constraint_schema=? AND k.table_name='line_link_codes'
                AND k.constraint_name='fk_line_link_codes_resident'
              ORDER BY k.ordinal_position"
        );
        $lineCodeForeignKeyStatement->execute([$database]);
        $lineCodeForeignKey=$lineCodeForeignKeyStatement->fetchAll();
        $lineCodeForeignKeyExact=count($lineCodeForeignKey)===1;
        if($lineCodeForeignKeyExact){
            $lineCodeForeignKeyRow=$lineCodeForeignKey[0];
            $lineCodeForeignKeyExact=
                (string)($lineCodeForeignKeyRow['COLUMN_NAME']??$lineCodeForeignKeyRow['column_name']??'')==='resident_id'
                &&(string)($lineCodeForeignKeyRow['REFERENCED_TABLE_NAME']??$lineCodeForeignKeyRow['referenced_table_name']??'')==='residents'
                &&(string)($lineCodeForeignKeyRow['REFERENCED_COLUMN_NAME']??$lineCodeForeignKeyRow['referenced_column_name']??'')==='id'
                &&strtoupper((string)($lineCodeForeignKeyRow['UPDATE_RULE']??$lineCodeForeignKeyRow['update_rule']??''))==='RESTRICT'
                &&strtoupper((string)($lineCodeForeignKeyRow['DELETE_RULE']??$lineCodeForeignKeyRow['delete_rule']??''))==='RESTRICT';
        }
        if($lineCodeForeignKeyExact){
            addResult($successes,'พบ foreign key line_link_codes(resident_id) ไป residents(id) แบบ RESTRICT');
        }else{
            addResult($errors,'schema ขาดหรือมีนิยาม fk_line_link_codes_resident ไม่ตรงกับ migration 010');
        }

        $requiredLineCodeUniqueIndexes=[
            'uq_line_link_codes_code_hash'=>['code_hash'],
            'uq_line_link_codes_pending_resident'=>['pending_resident_id'],
        ];
        $lineCodeUniqueIndexStatement=$pdo->prepare(
            "SELECT index_name,column_name,non_unique,seq_in_index,sub_part
               FROM information_schema.statistics
              WHERE table_schema=? AND table_name='line_link_codes'
                AND index_name IN ('uq_line_link_codes_code_hash','uq_line_link_codes_pending_resident')
              ORDER BY index_name,seq_in_index"
        );
        $lineCodeUniqueIndexStatement->execute([$database]);
        $lineCodeUniqueIndexes=[];
        $invalidLineCodeUniqueIndexes=[];
        foreach($lineCodeUniqueIndexStatement->fetchAll()as$lineCodeIndexRow){
            $indexName=(string)($lineCodeIndexRow['INDEX_NAME']??$lineCodeIndexRow['index_name']??'');
            if(!isset($requiredLineCodeUniqueIndexes[$indexName])
                ||(int)($lineCodeIndexRow['NON_UNIQUE']??$lineCodeIndexRow['non_unique']??1)!==0
                ||(int)($lineCodeIndexRow['SEQ_IN_INDEX']??$lineCodeIndexRow['seq_in_index']??0)
                    !==count($lineCodeUniqueIndexes[$indexName]??[])+1
                ||($lineCodeIndexRow['SUB_PART']??$lineCodeIndexRow['sub_part']??null)!==null){
                $invalidLineCodeUniqueIndexes[]=$indexName;
                continue;
            }
            $lineCodeUniqueIndexes[$indexName][]=(string)(
                $lineCodeIndexRow['COLUMN_NAME']??$lineCodeIndexRow['column_name']??''
            );
        }
        foreach($requiredLineCodeUniqueIndexes as$indexName=>$definition){
            if(($lineCodeUniqueIndexes[$indexName]??null)!==$definition){
                $invalidLineCodeUniqueIndexes[]=$indexName;
            }
        }
        $invalidLineCodeUniqueIndexes=array_values(array_unique($invalidLineCodeUniqueIndexes));
        if($invalidLineCodeUniqueIndexes===[]){
            addResult($successes,'พบ unique indexes ป้องกัน code hash ซ้ำและ pending code ซ้ำต่อผู้พักจาก migration 010');
        }else{
            addResult($errors,'schema ขาดหรือมีนิยาม unique indexes ของ LINE binding ไม่ตรง: '
                .implode(', ',$invalidLineCodeUniqueIndexes));
        }

        if($invalidColumns===[]&&$missingCurrentChecks===[]&&$meterOccupancyForeignKeyExact){
            $overlappingOccupancyMonths=(int)$pdo->query(
                "SELECT COUNT(*)
                   FROM occupancies first_row
                   JOIN occupancies second_row
                     ON second_row.id>first_row.id
                    AND (
                        second_row.room_id=first_row.room_id
                        OR second_row.resident_id=first_row.resident_id
                    )
                  WHERE DATE_FORMAT(first_row.move_in_date,'%Y-%m-01')
                            <=DATE_FORMAT(COALESCE(second_row.move_out_date,'9999-12-31'),'%Y-%m-01')
                    AND DATE_FORMAT(second_row.move_in_date,'%Y-%m-01')
                            <=DATE_FORMAT(COALESCE(first_row.move_out_date,'9999-12-31'),'%Y-%m-01')"
            )->fetchColumn();
            if($overlappingOccupancyMonths===0){
                addResult($successes,'ไม่มี occupancy ของห้องหรือ resident เดียวกันทับรอบเดือน');
            }else{
                addResult($errors,'data readiness ไม่ผ่าน: occupancy ของห้องหรือ resident เดียวกันทับรอบเดือน '
                    .$overlappingOccupancyMonths
                    .' คู่; ระบบไม่รองรับค่าเช่า/มิเตอร์แบบแบ่งเดือน ต้อง reconcile ก่อนเปิด billing');
            }

            $invalidResidentOccupancyStates=(int)$pdo->query(
                "SELECT COUNT(*)
                   FROM (
                       SELECT CONCAT('resident:',resident_row.id) AS issue_key
                         FROM residents resident_row
                         LEFT JOIN occupancies active_occupancy
                           ON active_occupancy.resident_id=resident_row.id
                          AND active_occupancy.status='active'
                         LEFT JOIN rooms active_room
                           ON active_room.id=active_occupancy.room_id
                        WHERE resident_row.active=1
                        GROUP BY resident_row.id
                       HAVING COUNT(active_occupancy.id)<>1
                           OR SUM(CASE
                               WHEN active_room.id IS NOT NULL
                                AND active_room.deleted_at IS NULL THEN 1
                               ELSE 0
                           END)<>1

                       UNION ALL

                       SELECT CONCAT('occupancy:',occupancy_row.id) AS issue_key
                         FROM occupancies occupancy_row
                         JOIN residents linked_resident
                           ON linked_resident.id=occupancy_row.resident_id
                         JOIN rooms linked_room
                           ON linked_room.id=occupancy_row.room_id
                         JOIN bookings linked_booking
                           ON linked_booking.id=occupancy_row.booking_id
                        WHERE (
                            occupancy_row.status='active'
                            AND (
                                linked_resident.active<>1
                                OR linked_room.deleted_at IS NOT NULL
                            )
                        )
                           OR linked_booking.status<>'moved_in'
                           OR NOT(linked_booking.resident_id<=>occupancy_row.resident_id)
                           OR NOT(linked_booking.room_id<=>occupancy_row.room_id)
                           OR NOT(linked_booking.booked_monthly_rent<=>occupancy_row.monthly_rent)

                       UNION ALL

                       SELECT CONCAT('booking:',moved_booking.id) AS issue_key
                         FROM bookings moved_booking
                         LEFT JOIN occupancies moved_occupancy
                           ON moved_occupancy.booking_id=moved_booking.id
                        WHERE moved_booking.status='moved_in'
                          AND (
                              moved_occupancy.id IS NULL
                              OR NOT(moved_booking.resident_id<=>moved_occupancy.resident_id)
                              OR NOT(moved_booking.room_id<=>moved_occupancy.room_id)
                              OR NOT(moved_booking.booked_monthly_rent<=>moved_occupancy.monthly_rent)
                          )
                   ) invalid_state_rows"
            )->fetchColumn();
            if($invalidResidentOccupancyStates===0){
                addResult($successes,'resident/occupancy/room/booking lifecycle states สอดคล้องกัน');
            }else{
                addResult($errors,'data readiness ไม่ผ่าน: resident/occupancy/room/booking lifecycle state '
                    .'ไม่สอดคล้อง '.$invalidResidentOccupancyStates
                    .' จุด; ต้อง reconcile ก่อนเปิด login, billing และ move-out');
            }

            $invalidFinancialRelationships=(int)$pdo->query(
                <<<'SQL'
SELECT COUNT(*)
  FROM (
      SELECT CONCAT('bill:',bill_row.id) AS issue_key
        FROM bills bill_row
        JOIN occupancies bill_occupancy
          ON bill_occupancy.id=bill_row.occupancy_id
        LEFT JOIN meter_readings bill_water
          ON bill_water.room_id=bill_row.room_id
         AND bill_water.occupancy_id=bill_row.occupancy_id
         AND bill_water.meter_type='water'
         AND bill_water.period=bill_row.period
        LEFT JOIN meter_readings bill_electric
          ON bill_electric.room_id=bill_row.room_id
         AND bill_electric.occupancy_id=bill_row.occupancy_id
         AND bill_electric.meter_type='electric'
         AND bill_electric.period=bill_row.period
       WHERE NOT(bill_row.resident_id<=>bill_occupancy.resident_id)
          OR NOT(bill_row.room_id<=>bill_occupancy.room_id)
          OR NOT(bill_row.rent_amount<=>bill_occupancy.monthly_rent)
          OR bill_row.period<DATE_FORMAT(bill_occupancy.move_in_date,'%Y-%m-01')
          OR bill_row.period>DATE_FORMAT(
              COALESCE(bill_occupancy.move_out_date,'9999-12-31'),
              '%Y-%m-01'
          )
          OR bill_water.id IS NULL
          OR NOT(bill_row.water_previous<=>bill_water.previous_reading)
          OR NOT(bill_row.water_current<=>bill_water.current_reading)
          OR NOT(bill_row.water_units<=>bill_water.units_used)
          OR bill_electric.id IS NULL
          OR NOT(bill_row.electric_previous<=>bill_electric.previous_reading)
          OR NOT(bill_row.electric_current<=>bill_electric.current_reading)
          OR NOT(bill_row.electric_units<=>bill_electric.units_used)

      UNION ALL

      SELECT CONCAT('bill-items:',item_bill.id) AS issue_key
        FROM bills item_bill
        LEFT JOIN bill_items item_row ON item_row.bill_id=item_bill.id
       GROUP BY item_bill.id,item_bill.rent_amount,
                item_bill.water_units,item_bill.water_rate,item_bill.water_amount,
                item_bill.electric_units,item_bill.electric_rate,item_bill.electric_amount,
                item_bill.other_description,item_bill.other_amount
      HAVING SUM(CASE
                 WHEN item_row.item_type='rent'
                  AND item_row.quantity=1.00
                  AND item_row.unit_price=item_bill.rent_amount
                  AND item_row.amount=item_bill.rent_amount THEN 1
                 ELSE 0
             END)<>1
          OR SUM(CASE
                 WHEN item_row.item_type='water'
                  AND item_row.quantity=item_bill.water_units
                  AND item_row.unit_price=item_bill.water_rate
                  AND item_row.amount=item_bill.water_amount THEN 1
                 ELSE 0
             END)<>1
          OR SUM(CASE
                 WHEN item_row.item_type='electric'
                  AND item_row.quantity=item_bill.electric_units
                  AND item_row.unit_price=item_bill.electric_rate
                  AND item_row.amount=item_bill.electric_amount THEN 1
                 ELSE 0
             END)<>1
          OR (
              item_bill.other_amount=0
              AND SUM(CASE WHEN item_row.item_type='other' THEN 1 ELSE 0 END)<>0
          )
          OR (
              item_bill.other_amount>0
              AND SUM(CASE
                      WHEN item_row.item_type='other'
                       AND item_row.quantity=1.00
                       AND item_row.unit_price=item_bill.other_amount
                       AND item_row.amount=item_bill.other_amount
                       AND item_row.description=item_bill.other_description THEN 1
                      ELSE 0
                  END)<>1
          )

      UNION ALL

      SELECT CONCAT('payment:',payment_row.id) AS issue_key
        FROM payments payment_row
        JOIN bills payment_bill ON payment_bill.id=payment_row.bill_id
       WHERE NOT(payment_row.resident_id<=>payment_bill.resident_id)
          OR NOT(payment_row.amount<=>payment_bill.total_amount)
          OR (payment_row.status='pending' AND payment_bill.status<>'pending')
          OR (payment_row.status='verified' AND payment_bill.status<>'paid')

      UNION ALL

      SELECT CONCAT('bill-payment:',paid_bill.id) AS issue_key
        FROM bills paid_bill
        LEFT JOIN payments paid_payment ON paid_payment.bill_id=paid_bill.id
       GROUP BY paid_bill.id,paid_bill.status,
                paid_bill.resident_id,paid_bill.total_amount
      HAVING (
          paid_bill.status='paid'
          AND SUM(CASE
                  WHEN paid_payment.status='verified'
                   AND paid_payment.resident_id=paid_bill.resident_id
                   AND paid_payment.amount=paid_bill.total_amount THEN 1
                  ELSE 0
              END)<>1
      ) OR (
          paid_bill.status='pending'
          AND SUM(CASE WHEN paid_payment.status='verified' THEN 1 ELSE 0 END)<>0
      )

      UNION ALL

      SELECT CONCAT('notification:',notification_row.id) AS issue_key
        FROM notification_outbox notification_row
        JOIN bills notification_bill ON notification_bill.id=notification_row.bill_id
       WHERE NOT(notification_row.resident_id<=>notification_bill.resident_id)
  ) invalid_financial_rows
SQL
            )->fetchColumn();
            if($invalidFinancialRelationships===0){
                addResult($successes,'ความสัมพันธ์ occupancy/bill/items/payment/notification ถูกต้อง');
            }else{
                addResult($errors,'data readiness ไม่ผ่าน: ความสัมพันธ์ทางการเงินหรือผู้รับแจ้งเตือน '
                    .'ไม่สอดคล้อง '.$invalidFinancialRelationships
                    .' จุด; ต้อง reconcile หลักฐานก่อนเปิด billing, payment, move-out และ LINE');
            }

            $missingOpeningCounts=\Dormitory\Support\PendingOpeningSchema::missingOpeningCounts($pdo);
            if($missingOpeningCounts['invalid']>0){
                addResult($errors,'data readiness ไม่ผ่าน: occupancy ขาดค่าเปิดมิเตอร์เพียงชนิดเดียว '
                    .'หรือยังขาดค่าทั้งคู่ทั้งที่มีประวัติมิเตอร์/บิลเกี่ยวข้อง '
                    .$missingOpeningCounts['invalid']
                    .' แถว; ต้อง reconcile จากหลักฐานระหว่าง maintenance ห้ามเดาค่า');
            }
            if($missingOpeningCounts['pending']>0){
                addResult($notices,'active occupancies เดิมรอค่าเปิดมิเตอร์น้ำ/ไฟ '
                    .$missingOpeningCounts['pending']
                    .' แถว โดยไม่มีประวัติมิเตอร์หรือบิลเกี่ยวข้อง; ระบบปิดการจดมิเตอร์และออกบิลของห้องเหล่านี้ '
                    .'จนกว่าแอดมินบันทึกค่าจริงทั้งคู่ผ่านหน้าผู้เช่า');
            }elseif($missingOpeningCounts['invalid']===0){
                addResult($successes,'active occupancies มีค่าเปิดมิเตอร์น้ำ/ไฟครบทุกแถว');
            }

            $invalidMeterOccupancyLinks=(int)$pdo->query(
                "SELECT COUNT(*)
                   FROM meter_readings m
                   LEFT JOIN occupancies linked ON linked.id=m.occupancy_id
                  WHERE (
                      m.occupancy_id IS NULL
                      AND EXISTS(
                          SELECT 1 FROM occupancies overlap_row
                           WHERE overlap_row.room_id=m.room_id
                             AND overlap_row.move_in_date<=LAST_DAY(m.period)
                             AND (overlap_row.move_out_date IS NULL
                                  OR overlap_row.move_out_date>=m.period)
                      )
                  ) OR (
                      m.occupancy_id IS NOT NULL
                      AND (
                          linked.id IS NULL
                          OR linked.room_id<>m.room_id
                          OR linked.move_in_date>LAST_DAY(m.period)
                          OR (linked.move_out_date IS NOT NULL
                              AND linked.move_out_date<m.period)
                      )
                  )"
            )->fetchColumn();
            if($invalidMeterOccupancyLinks===0){
                addResult($successes,'meter readings ผูก occupancy/ห้อง/ช่วงวันที่ถูกต้องทุกแถว');
            }else{
                addResult($errors,'data readiness ไม่ผ่าน: meter readings ทับช่วง occupancy แต่ไม่ผูก '
                    .'occupancy_id หรือผูกห้อง/ช่วงวันที่ไม่ตรง '.$invalidMeterOccupancyLinks
                    .' แถว; ต้อง reconcile ก่อนเปิด billing');
            }

            $invalidMeterChains=(int)$pdo->query(
                "SELECT COUNT(*)
                   FROM meter_readings current_row
                   JOIN occupancies occupancy_row
                     ON occupancy_row.id=current_row.occupancy_id
                   LEFT JOIN meter_readings prior_row
                     ON prior_row.occupancy_id=current_row.occupancy_id
                    AND prior_row.room_id=current_row.room_id
                    AND prior_row.meter_type=current_row.meter_type
                    AND prior_row.period=DATE_SUB(current_row.period,INTERVAL 1 MONTH)
                  WHERE (
                      current_row.period=DATE_FORMAT(occupancy_row.move_in_date,'%Y-%m-01')
                      AND NOT (
                          current_row.previous_reading <=> IF(
                              current_row.meter_type='water',
                              occupancy_row.opening_water_reading,
                              occupancy_row.opening_electric_reading
                          )
                      )
                  ) OR (
                      current_row.period>DATE_FORMAT(occupancy_row.move_in_date,'%Y-%m-01')
                      AND (
                          prior_row.id IS NULL
                          OR NOT (
                              current_row.previous_reading
                              <=> prior_row.current_reading
                          )
                      )
                  )"
            )->fetchColumn();
            if($invalidMeterChains===0){
                addResult($successes,'meter readings มี opening baseline และลำดับเดือนต่อเนื่องถูกต้อง');
            }else{
                addResult($errors,'data readiness ไม่ผ่าน: meter chain ขาดเดือนหรือ previous reading '
                    .'ไม่ตรงกับ opening/เดือนก่อน '.$invalidMeterChains
                    .' แถว; ต้องกระทบยอดและซ่อมด้วย DBA ระหว่าง maintenance ก่อนเปิด billing');
            }

            $activeResidentsWithoutCredential=(int)$pdo->query(
                "SELECT COUNT(*) FROM residents
                  WHERE active=1
                    AND NOT (
                        access_password_hash IS NOT NULL
                        OR (
                            activation_code_hash IS NOT NULL
                            AND activation_consumed_at IS NULL
                            AND activation_expires_at>UTC_TIMESTAMP(6)
                        )
                    )"
            )->fetchColumn();
            if($activeResidentsWithoutCredential===0){
                addResult($successes,'active residents มี password หรือ activation key ที่ยังใช้ได้ครบทุกแถว');
            }else{
                addResult($errors,'data readiness ไม่ผ่าน: active residents ไม่มี password หรือ activation key ที่ยังใช้ได้ '
                    .$activeResidentsWithoutCredential
                    .' แถว; deploy ใน maintenance, ให้ผู้ดูแล reissue access key '
                    .'และยืนยันการส่งมอบผ่านช่องทางส่วนตัวก่อนเปิด traffic');
            }
        }else{
            addResult($skips,'ข้าม data-readiness ของ resident/occupancy/meter เพราะ schema migrations 007–009 ยังไม่ครบ');
        }

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
                'SELECT promptpay_target,payment_receiver_account_tail,line_basic_id,
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

                $lineAnyTokenReady=false;$lineAnyWebhookReady=false;$lineDefaultBindingReady=false;
                if(in_array('line_official_accounts',$found,true)){
                    $lineAccounts=$pdo->query('SELECT id,enabled,is_default,basic_id,access_token_enc,channel_secret_enc FROM line_official_accounts WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC);
                    foreach($lineAccounts as$lineAccount){
                        if((int)$lineAccount['enabled']!==1)continue;
                        $oaId=(int)$lineAccount['id'];
                        $oaToken=$oaId===0?$lineReady:configuredIntegrationSecret($lineAccount['access_token_enc'],'line_oa_'.$oaId.'_access_token',$secretCipher,$errors);
                        $oaSecret=$oaId===0?$lineChannelSecretReady:configuredIntegrationSecret($lineAccount['channel_secret_enc'],'line_oa_'.$oaId.'_channel_secret',$secretCipher,$errors);
                        $oaBasic=$oaId===0?($integration['line_basic_id']??null):$lineAccount['basic_id'];
                        $oaBasicReady=is_string($oaBasic)&&preg_match('/^@[A-Za-z0-9._-]{1,32}$/D',$oaBasic)===1;
                        $lineAnyTokenReady=$lineAnyTokenReady||$oaToken;
                        $lineAnyWebhookReady=$lineAnyWebhookReady||($oaToken&&$oaSecret);
                        if((int)$lineAccount['is_default']===1)$lineDefaultBindingReady=$oaToken&&$oaSecret&&$oaBasicReady;
                    }
                }
                if ($lineAnyTokenReady) addResult($successes, 'มี LINE OA ที่เปิดใช้งานและเก็บ Channel access token แบบเข้ารหัสถูกต้อง');
                else addResult($warnings, 'ยังไม่มี LINE OA ที่เปิดใช้งานพร้อม token; worker จะยังส่งบิลไม่ได้');
                if ($lineAnyWebhookReady) addResult($successes, 'มี LINE OA ที่ตั้ง token และ Channel secret พร้อมตรวจ signed webhook');
                else addResult($warnings, 'ยังไม่มี LINE OA ที่ตั้ง token และ Channel secret ครบ');
                if ($lineDefaultBindingReady) addResult($successes, 'LINE OA เริ่มต้นเปิดใช้งานและตั้ง Basic ID พร้อมสร้างรหัสผูกบัญชี');
                else addResult($warnings, 'ยังไม่มี LINE OA เริ่มต้นที่พร้อมสร้างรหัสผูกบัญชี');

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
foreach ($notices as $message) {
    echo "[NOTICE] {$message}\n";
}
foreach ($warnings as $message) {
    echo "[WARN] {$message}\n";
}
foreach ($errors as $message) {
    echo "[FAIL] {$message}\n";
}
echo "\nสรุป: " . count($successes) . " ผ่าน, " . count($skips) . " ข้ามโดยตั้งใจ, " . count($notices) . " ข้อมูลรอดำเนินการ, " . count($warnings) . " คำเตือน, " . count($errors) . " ไม่ผ่าน\n";

if ($errors !== [] || ($strict && $warnings !== [])) {
    exit(1);
}
exit(0);
