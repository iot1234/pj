-- Fence concurrent notification workers with per-claim leases and record
-- bounded worker heartbeat/queue telemetry.
--
-- Stop notification workers before applying this migration. Run it with a
-- schema-owning account before deploying code that reads claim_token,
-- lease_until, or notification_worker_heartbeats. Safe to rerun after a
-- successful migration while workers are stopped.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

SET @dormitory_007_has_outbox := (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'notification_outbox'
       AND table_type = 'BASE TABLE'
);
SET @dormitory_007_schema_guard_sql := IF(
    @dormitory_007_has_outbox = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_007_IMPORT_SCHEMA_AND_PRIOR_MIGRATIONS_FIRST'
);
PREPARE dormitory_007_schema_guard FROM @dormitory_007_schema_guard_sql;
EXECUTE dormitory_007_schema_guard;
DEALLOCATE PREPARE dormitory_007_schema_guard;

SET @dormitory_007_sql := IF(
    EXISTS(
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'notification_outbox'
           AND column_name = 'claim_token'
    ),
    'SELECT 1',
    'ALTER TABLE notification_outbox ADD COLUMN claim_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER retry_key'
);
PREPARE dormitory_007_statement FROM @dormitory_007_sql;
EXECUTE dormitory_007_statement;
DEALLOCATE PREPARE dormitory_007_statement;

SET @dormitory_007_sql := IF(
    EXISTS(
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'notification_outbox'
           AND column_name = 'lease_until'
    ),
    'SELECT 1',
    'ALTER TABLE notification_outbox ADD COLUMN lease_until DATETIME(6) NULL AFTER claim_token'
);
PREPARE dormitory_007_statement FROM @dormitory_007_sql;
EXECUTE dormitory_007_statement;
DEALLOCATE PREPARE dormitory_007_statement;

SET @dormitory_007_valid_claim_column := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'notification_outbox'
       AND column_name = 'claim_token'
       AND data_type = 'char'
       AND character_maximum_length = 64
       AND is_nullable = 'YES'
       AND character_set_name = 'ascii'
       AND collation_name = 'ascii_bin'
);
SET @dormitory_007_valid_lease_column := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'notification_outbox'
       AND column_name = 'lease_until'
       AND data_type = 'datetime'
       AND datetime_precision = 6
       AND is_nullable = 'YES'
);
SET @dormitory_007_column_guard_sql := IF(
    @dormitory_007_valid_claim_column = 1
        AND @dormitory_007_valid_lease_column = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_007_INVALID_CLAIM_LEASE_COLUMNS'
);
PREPARE dormitory_007_column_guard FROM @dormitory_007_column_guard_sql;
EXECUTE dormitory_007_column_guard;
DEALLOCATE PREPARE dormitory_007_column_guard;

-- Rows claimed by the pre-fencing worker have no owner token. Return only
-- those legacy/incomplete claims to the queue; a rerun does not steal a valid
-- claim made by the new worker.
UPDATE notification_outbox
   SET status = 'pending',
       next_attempt_at = UTC_TIMESTAMP(6),
       claim_token = NULL,
       lease_until = NULL,
       updated_at = UTC_TIMESTAMP(6)
 WHERE status = 'processing'
   AND (claim_token IS NULL OR lease_until IS NULL);

SET @dormitory_007_sql := IF(
    EXISTS(
        SELECT 1
          FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = 'notification_outbox'
           AND constraint_name = 'chk_notification_outbox_claim_lease'
           AND constraint_type = 'CHECK'
    ),
    'SELECT 1',
    'ALTER TABLE notification_outbox ADD CONSTRAINT chk_notification_outbox_claim_lease CHECK (((status = ''processing'') AND claim_token IS NOT NULL AND claim_token REGEXP ''^[0-9a-f]{64}$'' AND lease_until IS NOT NULL) OR ((status <> ''processing'') AND claim_token IS NULL AND lease_until IS NULL))'
);
PREPARE dormitory_007_statement FROM @dormitory_007_sql;
EXECUTE dormitory_007_statement;
DEALLOCATE PREPARE dormitory_007_statement;

SET @dormitory_007_lease_index_rows := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'notification_outbox'
       AND index_name = 'idx_notification_outbox_lease'
);
SET @dormitory_007_valid_lease_index_rows := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'notification_outbox'
       AND index_name = 'idx_notification_outbox_lease'
       AND non_unique = 1
       AND sub_part IS NULL
       AND (
           (seq_in_index = 1 AND column_name = 'status')
           OR (seq_in_index = 2 AND column_name = 'lease_until')
       )
);
SET @dormitory_007_index_guard_sql := IF(
    @dormitory_007_lease_index_rows = 0
        OR (@dormitory_007_lease_index_rows = 2
            AND @dormitory_007_valid_lease_index_rows = 2),
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_007_INVALID_LEASE_INDEX'
);
PREPARE dormitory_007_index_guard FROM @dormitory_007_index_guard_sql;
EXECUTE dormitory_007_index_guard;
DEALLOCATE PREPARE dormitory_007_index_guard;

SET @dormitory_007_sql := IF(
    @dormitory_007_lease_index_rows = 0,
    'ALTER TABLE notification_outbox ADD KEY idx_notification_outbox_lease (status, lease_until)',
    'SELECT 1'
);
PREPARE dormitory_007_statement FROM @dormitory_007_sql;
EXECUTE dormitory_007_statement;
DEALLOCATE PREPARE dormitory_007_statement;

CREATE TABLE IF NOT EXISTS notification_worker_heartbeats (
    worker_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('starting', 'running', 'error', 'stopped') NOT NULL,
    started_at DATETIME(6) NOT NULL,
    heartbeat_at DATETIME(6) NOT NULL,
    last_cycle_at DATETIME(6) NULL,
    last_processed INT UNSIGNED NOT NULL DEFAULT 0,
    last_sent INT UNSIGNED NOT NULL DEFAULT 0,
    last_failed INT UNSIGNED NOT NULL DEFAULT 0,
    last_retried INT UNSIGNED NOT NULL DEFAULT 0,
    last_lost_claims INT UNSIGNED NOT NULL DEFAULT 0,
    last_recovered INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (worker_id),
    KEY idx_notification_worker_heartbeat (heartbeat_at),
    CONSTRAINT chk_notification_worker_id
        CHECK (worker_id REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_notification_worker_error
        CHECK (last_error IS NULL OR CHAR_LENGTH(TRIM(last_error)) BETWEEN 1 AND 1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @dormitory_007_valid_heartbeat_columns := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'notification_worker_heartbeats'
       AND column_name IN (
           'worker_id', 'status', 'started_at', 'heartbeat_at',
           'last_cycle_at', 'last_processed', 'last_sent', 'last_failed',
           'last_retried', 'last_lost_claims', 'last_recovered',
           'last_error', 'created_at', 'updated_at'
       )
);
SET @dormitory_007_heartbeat_guard_sql := IF(
    @dormitory_007_valid_heartbeat_columns = 14,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_007_INVALID_WORKER_HEARTBEAT_TABLE'
);
PREPARE dormitory_007_heartbeat_guard FROM @dormitory_007_heartbeat_guard_sql;
EXECUTE dormitory_007_heartbeat_guard;
DEALLOCATE PREPARE dormitory_007_heartbeat_guard;

SELECT table_name, column_name
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND (
       (table_name = 'notification_outbox'
           AND column_name IN ('claim_token', 'lease_until'))
       OR table_name = 'notification_worker_heartbeats'
   )
 ORDER BY table_name, ordinal_position;
