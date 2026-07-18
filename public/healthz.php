<?php
declare(strict_types=1);

$requestId = 'unknown';

try {
    $requestId = bin2hex(random_bytes(8));
    // Railway and Docker readiness probes reach Apache over the container's
    // private HTTP socket. Treat only this endpoint as HTTPS so normal
    // production configuration is validated without a redirect response.
    $_SERVER['HTTPS'] = 'on';

    /** @var Dormitory\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';
    $pdo = $app->database()->pdo();

    if ((int) $pdo->query('SELECT 1')->fetchColumn() !== 1) {
        throw new RuntimeException('database readiness check failed');
    }

    $tables = [
        'admin_users',
        'residents',
        'rooms',
        'bookings',
        'occupancies',
        'meter_readings',
        'billing_settings',
        'integration_settings',
        'bills',
        'bill_items',
        'payments',
        'notification_outbox',
        'audit_logs',
        'rate_limits',
    ];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $schema = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables'
        . ' WHERE table_schema=? AND table_name IN (' . $placeholders . ')'
    );
    $schema->execute(array_merge([$app->config->require('DB_DATABASE')], $tables));
    if ((int) $schema->fetchColumn() !== count($tables)) {
        throw new RuntimeException('schema readiness check failed');
    }

    // A table-count-only probe can stay green while application code expects
    // columns from a newer migration. Keep this list to the deployment-critical
    // additive columns that the current runtime reads or writes immediately.
    $requiredColumns = [
        ['integration_settings', 'line_channel_secret_enc'],
        ['notification_outbox', 'line_request_id'],
        ['notification_outbox', 'line_accepted_request_id'],
    ];
    $columnPredicates = implode(' OR ', array_fill(0, count($requiredColumns), '(table_name=? AND column_name=?)'));
    $columns = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns'
        . ' WHERE table_schema=? AND (' . $columnPredicates . ')'
    );
    $columnParameters = [$app->config->require('DB_DATABASE')];
    foreach ($requiredColumns as [$table, $column]) {
        $columnParameters[] = $table;
        $columnParameters[] = $column;
    }
    $columns->execute($columnParameters);
    if ((int) $columns->fetchColumn() !== count($requiredColumns)) {
        throw new RuntimeException('schema migration readiness check failed');
    }

    $defaults = $pdo->query(
        'SELECT '
        . 'EXISTS(SELECT 1 FROM billing_settings WHERE id=1) AS billing_ready,'
        . 'EXISTS(SELECT 1 FROM integration_settings WHERE id=1) AS integration_ready'
    )->fetch();
    if (!is_array($defaults)
        || (int) ($defaults['billing_ready'] ?? 0) !== 1
        || (int) ($defaults['integration_ready'] ?? 0) !== 1) {
        throw new RuntimeException('default data readiness check failed');
    }

    $storageDirectories = [
        $app->config->root . '/storage',
        $app->config->root . '/storage/private',
        $app->config->root . '/storage/private/slips',
        $app->config->root . '/storage/sessions',
        $app->config->root . '/storage/logs',
        $app->config->root . '/storage/cache',
    ];
    foreach ($storageDirectories as $directory) {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('storage readiness check failed');
        }
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '{"status":"ok"}';
} catch (Throwable $error) {
    error_log(sprintf('[healthz:%s] readiness check failed (%s)', $requestId, $error::class));
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '{"status":"unavailable"}';
}
