<?php
declare(strict_types=1);

// Read the effective `docker compose config --format json` output. The CI
// caller uses an empty env file so local credentials never enter the fixture.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array(array_slice($argv, 1), [[], ['--defaults']], true)) {
    fwrite(STDERR, "Usage: php tests/compose_environment.php [--defaults] < compose.json\n");
    exit(64);
}
$defaults = array_slice($argv, 1) === ['--defaults'];
$config = json_decode((string)stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$expected = $defaults
    ? ['BOOKING_HOLD_SECONDS'=>'86400', 'RESIDENT_ACTIVATION_TTL_SECONDS'=>'604800']
    : ['BOOKING_HOLD_SECONDS'=>'1800', 'RESIDENT_ACTIVATION_TTL_SECONDS'=>'3600'];
foreach (['app', 'worker'] as $service) {
    $environment = $config['services'][$service]['environment'] ?? [];
    foreach ($expected as $key => $value) {
        if ((string)($environment[$key] ?? '') !== $value) {
            throw new RuntimeException("{$service} did not receive the expected {$key}");
        }
    }
    if (array_key_exists('DB_ROOT_PASSWORD', $environment) || array_key_exists('MYSQL_ROOT_PASSWORD', $environment)) {
        throw new RuntimeException("{$service} must not receive database root credentials");
    }
}
if (($config['services']['worker']['environment']['WORKER_INSTANCE_ID'] ?? null) !== ($defaults ? '' : 'compose-ci-worker')) {
    throw new RuntimeException('Worker instance label was not forwarded');
}
fwrite(STDOUT, 'PASS Compose ' . ($defaults ? 'default' : 'custom') . " booking lifetime, activation lifetime, worker identity and credential isolation\n");
