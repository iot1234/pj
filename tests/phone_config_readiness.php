<?php
declare(strict_types=1);

// Run the real CLI preflight without --db, using only an isolated testing
// environment. Retired activation settings must not block phone-only access;
// required owner/security and booking settings still fail closed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$baseEnvironment = array_merge(getenv(), [
    'APP_ENV'=>'testing', 'APP_DEBUG'=>'false', 'APP_URL'=>'http://localhost',
    'APP_TIMEZONE'=>'Asia/Bangkok', 'APP_KEY'=>'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    'FORCE_HTTPS'=>'false', 'TRUSTED_PROXIES'=>'', 'RUNTIME_ROLE'=>'job',
    'SESSION_NAME'=>'phone_config_test', 'SESSION_LIFETIME_SECONDS'=>'3600',
    'BOOKING_HOLD_SECONDS'=>'86400', 'DB_HOST'=>'127.0.0.1', 'DB_PORT'=>'1',
    'DB_DATABASE'=>'testing', 'DB_USERNAME'=>'testing', 'DB_PASSWORD'=>'testing-only',
    'DB_SSL'=>'false', 'DB_SSL_CA'=>'', 'ADMIN_USERNAME'=>'owner.fixture',
    'ADMIN_ROLE'=>'owner', 'ADMIN_PASSWORD'=>'',
]);
$run = static function (array $changes) use ($root, $baseEnvironment): array {
    $environment = array_merge($baseEnvironment, $changes);
    $command = [PHP_BINARY];
    if (is_string($ini = php_ini_loaded_file())) array_push($command, '-c', $ini);
    array_push($command, $root . '/scripts/check_requirements.php', '--strict');
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
        $pipes, $root, $environment, ['bypass_shell'=>true,'create_new_console'=>false]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated configuration preflight');
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['exit'=>proc_close($process),'stdout'=>$stdout,'stderr'=>$stderr];
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$baseline = $run(['RESIDENT_ACTIVATION_TTL_SECONDS'=>'604800']);
foreach (['', 'not-a-number', '-1', '999999999999999999999999999999'] as $legacyTtl) {
    $result = $run(['RESIDENT_ACTIVATION_TTL_SECONDS'=>$legacyTtl]);
    $assert($result === $baseline, 'Unused activation setting changed phone-only deployment readiness');
    $assert(!str_contains($result['stdout'] . $result['stderr'], 'RESIDENT_ACTIVATION_TTL_SECONDS'),
        'Retired activation setting still appears in configuration validation');
}
fwrite(STDOUT, "PASS retired activation settings do not change real CLI preflight results\n");
foreach ([
    ['BOOKING_HOLD_SECONDS'=>'invalid'],
    ['SESSION_LIFETIME_SECONDS'=>'invalid'],
    ['ADMIN_PASSWORD'=>'short'],
] as $invalid) {
    $result = $run($invalid);
    $key = array_key_first($invalid);
    $assert($result['exit'] !== 0 && str_contains($result['stdout'] . $result['stderr'], '[FAIL] ' . $key),
        'A required owner/security or booking configuration stopped failing closed: ' . $key);
}
fwrite(STDOUT, "PASS required owner-password, session and booking settings still fail closed\n");
fwrite(STDOUT, "2 phone-only configuration regression groups passed\n");
