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
        'line_link_codes',
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
        'notification_worker_heartbeats',
        'audit_logs',
        'rate_limits',
    ];
    $linePlatformTables = [
        'line_official_accounts',
        'line_room_bindings',
        'line_room_policies',
        'line_admin_recipients',
        'line_notice_outbox',
    ];
    $requiredTables = array_merge($tables, $linePlatformTables);
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $schema = $pdo->prepare(
        'SELECT table_name FROM information_schema.tables'
        . " WHERE table_schema=? AND table_type='BASE TABLE' AND table_name IN (" . $placeholders . ')'
    );
    $schema->execute(array_merge([$app->config->require('DB_DATABASE')], $requiredTables));
    $actualTables = $schema->fetchAll(PDO::FETCH_COLUMN);
    $missingTables = array_diff($tables, $actualTables);
    if ($missingTables !== []) {
        // Only names from the checked-in allowlist reach the server log. The
        // public response below remains generic and never exposes metadata.
        throw new RuntimeException('schema readiness check failed; missing tables: ' . implode(', ', $missingTables));
    }
    $missingLineTables = array_diff($linePlatformTables, $actualTables);
    if ($missingLineTables !== []) {
        throw new RuntimeException(
            'schema LINE platform readiness check failed; migration 014 required; missing tables: '
            . implode(', ', $missingLineTables),
        );
    }
    // Share the canonical migration-014 checks with the deployment checker so
    // room bindings, OA routing and delivery guards cannot drift independently.
    $lineSchemaErrors = Dormitory\Support\LinePlatformSchema::errors($pdo);
    if ($lineSchemaErrors !== []) {
        throw new RuntimeException(
            'schema LINE platform readiness check failed; check migration 014: '
            . implode('; ', array_slice($lineSchemaErrors, 0, 3)),
        );
    }

    // A table-count-only probe can stay green while application code expects
    // columns or uniqueness guards from a newer migration. Keep this list to
    // deployment-critical fields that current write paths use immediately.
    $requiredColumns = [
        ['residents', 'line_user_id'],
        ['residents', 'access_password_hash'],
        ['residents', 'activation_code_hash'],
        ['residents', 'activation_expires_at'],
        ['residents', 'activation_consumed_at'],
        ['line_link_codes', 'id'],
        ['line_link_codes', 'resident_id'],
        ['line_link_codes', 'code_hash'],
        ['line_link_codes', 'status'],
        ['line_link_codes', 'line_user_id'],
        ['line_link_codes', 'expires_at'],
        ['line_link_codes', 'bound_at'],
        ['line_link_codes', 'revoked_at'],
        ['line_link_codes', 'created_at'],
        ['line_link_codes', 'updated_at'],
        ['line_link_codes', 'pending_resident_id'],
        ['bookings', 'booked_monthly_rent'],
        ['bookings', 'move_in_request_hash'],
        ['bookings', 'active_room_id'],
        ['bookings', 'active_phone_norm'],
        ['occupancies', 'active_room_id'],
        ['occupancies', 'active_resident_id'],
        ['occupancies', 'opening_water_reading'],
        ['occupancies', 'opening_electric_reading'],
        ['meter_readings', 'occupancy_id'],
        ['bills', 'resident_name_snapshot'],
        ['bills', 'room_code_snapshot'],
        ['payments', 'verification_lease_until'],
        ['payments', 'verification_token'],
        ['payments', 'verification_attempts'],
        ['payments', 'active_bill_id'],
        ['integration_settings', 'line_channel_secret_enc'],
        ['integration_settings', 'line_basic_id'],
        ['notification_outbox', 'recipient'],
        ['notification_outbox', 'claim_token'],
        ['notification_outbox', 'lease_until'],
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

    // The current worker and resident/meter write paths depend on exact types,
    // not only column names. Fail readiness on a partial or manually altered
    // migration before a process can claim work with incompatible fencing.
    $requiredCurrentColumns = [
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
        'bookings.move_in_request_hash' => ['char(64)', 'YES'],
        'occupancies.opening_water_reading' => ['decimal(14,2)', 'YES'],
        'occupancies.opening_electric_reading' => ['decimal(14,2)', 'YES'],
        'meter_readings.occupancy_id' => ['bigint unsigned', 'YES'],
        'integration_settings.line_basic_id' => ['varchar(33)', 'YES'],
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
    $currentColumnPredicates = [];
    $currentColumnParameters = [$app->config->require('DB_DATABASE')];
    foreach (array_keys($requiredCurrentColumns) as $qualifiedColumn) {
        [$table, $column] = explode('.', $qualifiedColumn, 2);
        $currentColumnPredicates[] = '(table_name=? AND column_name=?)';
        $currentColumnParameters[] = $table;
        $currentColumnParameters[] = $column;
    }
    $currentColumns = $pdo->prepare(
        'SELECT table_name,column_name,column_type,is_nullable'
        . ' FROM information_schema.columns WHERE table_schema=? AND ('
        . implode(' OR ', $currentColumnPredicates) . ')'
    );
    $currentColumns->execute($currentColumnParameters);
    $actualCurrentColumns = [];
    foreach ($currentColumns->fetchAll() as $currentColumn) {
        $qualifiedColumn = (string) ($currentColumn['table_name'] ?? $currentColumn['TABLE_NAME'] ?? '')
            . '.' . (string) ($currentColumn['column_name'] ?? $currentColumn['COLUMN_NAME'] ?? '');
        if (!isset($requiredCurrentColumns[$qualifiedColumn])
            || isset($actualCurrentColumns[$qualifiedColumn])) {
            throw new RuntimeException('schema current-column readiness check failed');
        }
        $actualCurrentColumns[$qualifiedColumn] = [
            strtolower((string) ($currentColumn['column_type'] ?? $currentColumn['COLUMN_TYPE'] ?? '')),
            strtoupper((string) ($currentColumn['is_nullable'] ?? $currentColumn['IS_NULLABLE'] ?? '')),
        ];
    }
    ksort($actualCurrentColumns);
    ksort($requiredCurrentColumns);
    if ($actualCurrentColumns !== $requiredCurrentColumns) {
        throw new RuntimeException('schema current-column readiness check failed');
    }

    $requiredCurrentChecks = [
        'chk_notification_outbox_claim_lease',
        'chk_notification_worker_error',
        'chk_notification_worker_id',
        'chk_occupancies_opening_readings',
        'chk_residents_access_password',
        'chk_residents_activation_state',
        'chk_residents_password_activation',
        'chk_line_link_codes_hash',
        'chk_line_link_codes_line_user',
        'chk_line_link_codes_state',
        'chk_line_link_codes_timestamps',
        'chk_integration_settings_line_basic_id',
        'chk_bookings_move_in_request_hash',
    ];
    $currentChecks = $pdo->prepare(
        "SELECT constraint_name FROM information_schema.table_constraints"
        . " WHERE constraint_schema=? AND constraint_type='CHECK' AND constraint_name IN ("
        . implode(',', array_fill(0, count($requiredCurrentChecks), '?')) . ')'
    );
    $currentChecks->execute(array_merge(
        [$app->config->require('DB_DATABASE')],
        $requiredCurrentChecks,
    ));
    $actualCurrentChecks = array_map(
        static fn (array $row): string => (string) (
            $row['constraint_name'] ?? $row['CONSTRAINT_NAME'] ?? ''
        ),
        $currentChecks->fetchAll(),
    );
    sort($actualCurrentChecks);
    sort($requiredCurrentChecks);
    if ($actualCurrentChecks !== $requiredCurrentChecks) {
        throw new RuntimeException('schema current-check readiness check failed');
    }

    $requiredOperationalIndexes = [
        'meter_readings.idx_meter_readings_occupancy_period' => ['occupancy_id', 'period'],
        'notification_outbox.idx_notification_outbox_lease' => ['status', 'lease_until'],
        'notification_worker_heartbeats.idx_notification_worker_heartbeat' => ['heartbeat_at'],
        'line_link_codes.idx_line_link_codes_expiry' => ['status', 'expires_at'],
        'line_link_codes.idx_line_link_codes_resident' => ['resident_id', 'created_at'],
    ];
    $operationalIndexPredicates = [];
    $operationalIndexParameters = [$app->config->require('DB_DATABASE')];
    foreach (array_keys($requiredOperationalIndexes) as $qualifiedIndex) {
        [$table, $index] = explode('.', $qualifiedIndex, 2);
        $operationalIndexPredicates[] = '(table_name=? AND index_name=?)';
        $operationalIndexParameters[] = $table;
        $operationalIndexParameters[] = $index;
    }
    $operationalIndexes = $pdo->prepare(
        'SELECT table_name,index_name,column_name,non_unique,seq_in_index,sub_part'
        . ' FROM information_schema.statistics WHERE table_schema=? AND ('
        . implode(' OR ', $operationalIndexPredicates) . ') ORDER BY table_name,index_name,seq_in_index'
    );
    $operationalIndexes->execute($operationalIndexParameters);
    $actualOperationalIndexes = [];
    foreach ($operationalIndexes->fetchAll() as $indexRow) {
        $qualifiedIndex = (string) ($indexRow['table_name'] ?? $indexRow['TABLE_NAME'] ?? '')
            . '.' . (string) ($indexRow['index_name'] ?? $indexRow['INDEX_NAME'] ?? '');
        if (!isset($requiredOperationalIndexes[$qualifiedIndex])
            || (int) ($indexRow['non_unique'] ?? $indexRow['NON_UNIQUE'] ?? 0) !== 1
            || ($indexRow['sub_part'] ?? $indexRow['SUB_PART'] ?? null) !== null
            || (int) ($indexRow['seq_in_index'] ?? $indexRow['SEQ_IN_INDEX'] ?? 0)
                !== count($actualOperationalIndexes[$qualifiedIndex] ?? []) + 1) {
            throw new RuntimeException('schema operational-index readiness check failed');
        }
        $actualOperationalIndexes[$qualifiedIndex][] = (string) (
            $indexRow['column_name'] ?? $indexRow['COLUMN_NAME'] ?? ''
        );
    }
    ksort($actualOperationalIndexes);
    ksort($requiredOperationalIndexes);
    if ($actualOperationalIndexes !== $requiredOperationalIndexes) {
        throw new RuntimeException('schema operational-index readiness check failed');
    }

    $meterOccupancyForeignKey = $pdo->prepare(
        "SELECT k.column_name,k.referenced_table_name,k.referenced_column_name,"
        . " r.update_rule,r.delete_rule"
        . " FROM information_schema.key_column_usage k"
        . " JOIN information_schema.referential_constraints r"
        . " ON r.constraint_schema=k.constraint_schema"
        . " AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name"
        . " WHERE k.constraint_schema=? AND k.table_name='meter_readings'"
        . " AND k.constraint_name='fk_meter_readings_occupancy'"
    );
    $meterOccupancyForeignKey->execute([$app->config->require('DB_DATABASE')]);
    $foreignKeyRows = $meterOccupancyForeignKey->fetchAll();
    $foreignKeyRow = count($foreignKeyRows) === 1 ? $foreignKeyRows[0] : null;
    if (!is_array($foreignKeyRow)
        || (string) ($foreignKeyRow['column_name'] ?? $foreignKeyRow['COLUMN_NAME'] ?? '') !== 'occupancy_id'
        || (string) ($foreignKeyRow['referenced_table_name']
            ?? $foreignKeyRow['REFERENCED_TABLE_NAME'] ?? '') !== 'occupancies'
        || (string) ($foreignKeyRow['referenced_column_name']
            ?? $foreignKeyRow['REFERENCED_COLUMN_NAME'] ?? '') !== 'id'
        || strtoupper((string) ($foreignKeyRow['update_rule']
            ?? $foreignKeyRow['UPDATE_RULE'] ?? '')) !== 'RESTRICT'
        || strtoupper((string) ($foreignKeyRow['delete_rule']
            ?? $foreignKeyRow['DELETE_RULE'] ?? '')) !== 'RESTRICT') {
        throw new RuntimeException('schema occupancy foreign-key readiness check failed');
    }

    $lineCodeResidentForeignKey = $pdo->prepare(
        "SELECT k.column_name,k.referenced_table_name,k.referenced_column_name,"
        . " r.update_rule,r.delete_rule"
        . " FROM information_schema.key_column_usage k"
        . " JOIN information_schema.referential_constraints r"
        . " ON r.constraint_schema=k.constraint_schema"
        . " AND r.table_name=k.table_name AND r.constraint_name=k.constraint_name"
        . " WHERE k.constraint_schema=? AND k.table_name='line_link_codes'"
        . " AND k.constraint_name='fk_line_link_codes_resident'"
    );
    $lineCodeResidentForeignKey->execute([$app->config->require('DB_DATABASE')]);
    $lineCodeForeignKeyRows = $lineCodeResidentForeignKey->fetchAll();
    $lineCodeForeignKeyRow = count($lineCodeForeignKeyRows) === 1
        ? $lineCodeForeignKeyRows[0]
        : null;
    if (!is_array($lineCodeForeignKeyRow)
        || (string) ($lineCodeForeignKeyRow['column_name']
            ?? $lineCodeForeignKeyRow['COLUMN_NAME'] ?? '') !== 'resident_id'
        || (string) ($lineCodeForeignKeyRow['referenced_table_name']
            ?? $lineCodeForeignKeyRow['REFERENCED_TABLE_NAME'] ?? '') !== 'residents'
        || (string) ($lineCodeForeignKeyRow['referenced_column_name']
            ?? $lineCodeForeignKeyRow['REFERENCED_COLUMN_NAME'] ?? '') !== 'id'
        || strtoupper((string) ($lineCodeForeignKeyRow['update_rule']
            ?? $lineCodeForeignKeyRow['UPDATE_RULE'] ?? '')) !== 'RESTRICT'
        || strtoupper((string) ($lineCodeForeignKeyRow['delete_rule']
            ?? $lineCodeForeignKeyRow['DELETE_RULE'] ?? '')) !== 'RESTRICT') {
        throw new RuntimeException('schema LINE binding foreign-key readiness check failed');
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
        'line_link_codes.pending_resident_id' => [
            'type' => 'bigint unsigned',
            'expressions' => ["casewhenstatus='pending'thenresident_idelsenullend"],
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
        'notification_outbox.uq_notification_outbox_bill_binding' => ['bill_id', 'purpose', 'line_delivery_key'],
        'notification_outbox.uq_notification_outbox_retry_key' => ['retry_key'],
        'line_link_codes.uq_line_link_codes_code_hash' => ['code_hash'],
        'line_link_codes.uq_line_link_codes_pending_resident' => ['pending_resident_id'],
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
