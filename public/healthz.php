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
    // columns or uniqueness guards from a newer migration. Keep this list to
    // deployment-critical fields that current write paths use immediately.
    $requiredColumns = [
        ['residents', 'line_user_id'],
        ['bookings', 'booked_monthly_rent'],
        ['bookings', 'active_room_id'],
        ['bookings', 'active_phone_norm'],
        ['occupancies', 'active_room_id'],
        ['occupancies', 'active_resident_id'],
        ['bills', 'resident_name_snapshot'],
        ['bills', 'room_code_snapshot'],
        ['payments', 'verification_lease_until'],
        ['payments', 'verification_token'],
        ['payments', 'verification_attempts'],
        ['payments', 'active_bill_id'],
        ['integration_settings', 'line_channel_secret_enc'],
        ['notification_outbox', 'recipient'],
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

    // A UNIQUE index over a generated column is only as strong as the
    // expression that feeds it. Verify the full generated-column definition;
    // checking the column/index names alone would accept guards that always
    // evaluate to NULL and therefore enforce no active-state uniqueness.
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
    $generatedParameters = [$app->config->require('DB_DATABASE')];
    foreach (array_keys($requiredGeneratedColumns) as $qualifiedColumn) {
        [$table, $column] = explode('.', $qualifiedColumn, 2);
        $generatedPredicates[] = '(table_name=? AND column_name=?)';
        $generatedParameters[] = $table;
        $generatedParameters[] = $column;
    }
    $generated = $pdo->prepare(
        'SELECT table_name,column_name,column_type,is_nullable,extra,generation_expression'
        . ' FROM information_schema.columns WHERE table_schema=? AND ('
        . implode(' OR ', $generatedPredicates) . ') ORDER BY table_name,column_name'
    );
    $generated->execute($generatedParameters);
    $actualGeneratedColumns = [];
    foreach ($generated->fetchAll() as $generatedRow) {
        $table = (string) ($generatedRow['table_name'] ?? $generatedRow['TABLE_NAME'] ?? '');
        $column = (string) ($generatedRow['column_name'] ?? $generatedRow['COLUMN_NAME'] ?? '');
        $qualifiedColumn = $table . '.' . $column;
        $expected = $requiredGeneratedColumns[$qualifiedColumn] ?? null;
        $expression = $generatedRow['generation_expression']
            ?? $generatedRow['GENERATION_EXPRESSION']
            ?? null;
        if (!is_array($expected) || isset($actualGeneratedColumns[$qualifiedColumn])
            || !is_string($expression)) {
            throw new RuntimeException('schema generated-column readiness check failed');
        }
        $normalizedExpression = strtolower(str_replace("\\'", "'", $expression));
        $normalizedExpression = preg_replace("/_[a-z0-9_]+'/", "'", $normalizedExpression);
        $normalizedExpression = str_replace(['`', '(', ')'], '', (string) $normalizedExpression);
        $normalizedExpression = preg_replace('/\s+/', '', $normalizedExpression);
        $columnType = strtolower((string) (
            $generatedRow['column_type'] ?? $generatedRow['COLUMN_TYPE'] ?? ''
        ));
        $nullable = strtoupper((string) (
            $generatedRow['is_nullable'] ?? $generatedRow['IS_NULLABLE'] ?? ''
        ));
        $extra = strtoupper(trim((string) (
            $generatedRow['extra'] ?? $generatedRow['EXTRA'] ?? ''
        )));
        if ($columnType !== $expected['type'] || $nullable !== 'YES'
            || $extra !== 'STORED GENERATED' || !is_string($normalizedExpression)
            || !in_array($normalizedExpression, $expected['expressions'], true)) {
            throw new RuntimeException('schema generated-column readiness check failed');
        }
        $actualGeneratedColumns[$qualifiedColumn] = true;
    }
    if (count($actualGeneratedColumns) !== count($requiredGeneratedColumns)) {
        throw new RuntimeException('schema generated-column readiness check failed');
    }

    // PIN authentication was deliberately removed. A legacy NOT NULL pin_hash
    // column makes every current resident insert fail even though all tables
    // still exist, so never report such a mixed-version schema as healthy.
    $retiredColumns = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns"
        . " WHERE table_schema=? AND table_name='residents' AND column_name='pin_hash'"
    );
    $retiredColumns->execute([$app->config->require('DB_DATABASE')]);
    if ((int) $retiredColumns->fetchColumn() !== 0) {
        throw new RuntimeException('retired schema column readiness check failed');
    }

    // Generated columns prevent duplicate active states only when the exact
    // full-length UNIQUE indexes also exist. Verify every write-critical unique
    // guard so a partial migration cannot accept inconsistent financial or
    // occupancy data while readiness remains green.
    $requiredUniqueIndexes = [
        'admin_users.uq_admin_users_username' => ['username'],
        'residents.uq_residents_phone_norm' => ['phone_norm'],
        'residents.uq_residents_line_user_id' => ['line_user_id'],
        'rooms.uq_rooms_room_code' => ['room_code'],
        'bookings.uq_bookings_reference_no' => ['reference_no'],
        'bookings.uq_bookings_idempotency_key' => ['idempotency_key'],
        'bookings.uq_bookings_one_active_per_room' => ['active_room_id'],
        'bookings.uq_bookings_one_active_per_phone' => ['active_phone_norm'],
        'occupancies.uq_occupancies_booking' => ['booking_id'],
        'occupancies.uq_occupancies_one_active_per_room' => ['active_room_id'],
        'occupancies.uq_occupancies_one_active_per_resident' => ['active_resident_id'],
        'meter_readings.uq_meter_readings_room_type_period' => ['room_id', 'meter_type', 'period'],
        'bills.uq_bills_bill_no' => ['bill_no'],
        'bills.uq_bills_occupancy_period' => ['occupancy_id', 'period'],
        'bill_items.uq_bill_items_bill_type' => ['bill_id', 'item_type'],
        'payments.uq_payments_slip_hmac' => ['slip_hmac'],
        'payments.uq_payments_transaction_ref' => ['transaction_ref'],
        'payments.uq_payments_one_active_per_bill' => ['active_bill_id'],
        'notification_outbox.uq_notification_outbox_bill_purpose' => ['bill_id', 'purpose'],
        'notification_outbox.uq_notification_outbox_retry_key' => ['retry_key'],
    ];
    $indexPredicates = [];
    $indexParameters = [$app->config->require('DB_DATABASE')];
    foreach (array_keys($requiredUniqueIndexes) as $qualifiedIndex) {
        [$table, $index] = explode('.', $qualifiedIndex, 2);
        $indexPredicates[] = '(table_name=? AND index_name=?)';
        $indexParameters[] = $table;
        $indexParameters[] = $index;
    }
    $indexes = $pdo->prepare(
        'SELECT table_name,index_name,column_name,non_unique,seq_in_index,sub_part'
        . ' FROM information_schema.statistics WHERE table_schema=? AND ('
        . implode(' OR ', $indexPredicates) . ') ORDER BY table_name,index_name,seq_in_index'
    );
    $indexes->execute($indexParameters);
    $actualUniqueIndexes = [];
    foreach ($indexes->fetchAll() as $indexRow) {
        $qualifiedIndex = (string) ($indexRow['table_name'] ?? $indexRow['TABLE_NAME'] ?? '')
            . '.' . (string) ($indexRow['index_name'] ?? $indexRow['INDEX_NAME'] ?? '');
        $nonUnique = $indexRow['non_unique'] ?? $indexRow['NON_UNIQUE'] ?? 1;
        $subPart = $indexRow['sub_part'] ?? $indexRow['SUB_PART'] ?? null;
        $sequence = $indexRow['seq_in_index'] ?? $indexRow['SEQ_IN_INDEX'] ?? 0;
        if (!isset($requiredUniqueIndexes[$qualifiedIndex])
            || (int) $nonUnique !== 0
            || $subPart !== null
            || (int) $sequence
                !== count($actualUniqueIndexes[$qualifiedIndex] ?? []) + 1) {
            throw new RuntimeException('schema unique-index readiness check failed');
        }
        $actualUniqueIndexes[$qualifiedIndex][] = (string) (
            $indexRow['column_name'] ?? $indexRow['COLUMN_NAME'] ?? ''
        );
    }
    ksort($actualUniqueIndexes);
    ksort($requiredUniqueIndexes);
    if ($actualUniqueIndexes !== $requiredUniqueIndexes) {
        throw new RuntimeException('schema unique-index readiness check failed');
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
    $errorMessage = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $error->getMessage());
    error_log(sprintf(
        '[healthz:%s] readiness check failed (%s: %s)',
        $requestId,
        $error::class,
        substr(is_string($errorMessage) ? $errorMessage : 'unknown error', 0, 300),
    ));
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '{"status":"unavailable"}';
}
