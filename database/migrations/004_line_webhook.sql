-- Add the encrypted LINE channel secret, provider reconciliation IDs, and
-- enforce the official Messaging API user-ID format on existing deployments.
-- Run with a schema-owning account before deploying webhook-enabled code.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

SET @dormitory_004_required_tables := (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN ('integration_settings', 'residents', 'notification_outbox')
       AND table_type = 'BASE TABLE'
);
SET @dormitory_004_schema_guard_sql := IF(
    @dormitory_004_required_tables = 3,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_004_IMPORT_SCHEMA_AND_PRIOR_MIGRATIONS_FIRST'
);
PREPARE dormitory_004_schema_guard FROM @dormitory_004_schema_guard_sql;
EXECUTE dormitory_004_schema_guard;
DEALLOCATE PREPARE dormitory_004_schema_guard;

-- Fail before any ALTER when legacy values cannot satisfy the official
-- Messaging API shape. Correct/quarantine those rows deliberately, then rerun.
SET @dormitory_004_invalid_line_ids :=
    (SELECT COUNT(*) FROM residents
      WHERE line_user_id IS NOT NULL
        AND line_user_id NOT REGEXP '^U[0-9a-f]{32}$')
    +
    (SELECT COUNT(*) FROM notification_outbox
      WHERE recipient NOT REGEXP '^U[0-9a-f]{32}$');
SET @dormitory_004_data_guard_sql := IF(
    @dormitory_004_invalid_line_ids = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_004_INVALID_LINE_USER_IDS_FIX_ROWS_BEFORE_MIGRATION'
);
PREPARE dormitory_004_data_guard FROM @dormitory_004_data_guard_sql;
EXECUTE dormitory_004_data_guard;
DEALLOCATE PREPARE dormitory_004_data_guard;

SET @dormitory_004_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'integration_settings'
           AND column_name = 'line_channel_secret_enc'
    ),
    'SELECT 1',
    'ALTER TABLE integration_settings ADD COLUMN line_channel_secret_enc TEXT CHARACTER SET ascii COLLATE ascii_bin NULL AFTER line_channel_access_token_enc'
);
PREPARE dormitory_004_statement FROM @dormitory_004_sql;
EXECUTE dormitory_004_statement;
DEALLOCATE PREPARE dormitory_004_statement;

SET @dormitory_004_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'notification_outbox'
           AND column_name = 'line_request_id'
    ),
    'SELECT 1',
    'ALTER TABLE notification_outbox ADD COLUMN line_request_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER retry_key'
);
PREPARE dormitory_004_statement FROM @dormitory_004_sql;
EXECUTE dormitory_004_statement;
DEALLOCATE PREPARE dormitory_004_statement;

SET @dormitory_004_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'notification_outbox'
           AND column_name = 'line_accepted_request_id'
    ),
    'SELECT 1',
    'ALTER TABLE notification_outbox ADD COLUMN line_accepted_request_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER line_request_id'
);
PREPARE dormitory_004_statement FROM @dormitory_004_sql;
EXECUTE dormitory_004_statement;
DEALLOCATE PREPARE dormitory_004_statement;

-- Existing rows passed the preflight, so narrowing these columns is lossless.
ALTER TABLE residents
    MODIFY COLUMN line_user_id VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE notification_outbox
    MODIFY COLUMN recipient VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

SET @dormitory_004_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = 'residents'
           AND constraint_name = 'chk_residents_line_user_id'
           AND constraint_type = 'CHECK'
    ),
    'ALTER TABLE residents DROP CHECK chk_residents_line_user_id',
    'SELECT 1'
);
PREPARE dormitory_004_statement FROM @dormitory_004_sql;
EXECUTE dormitory_004_statement;
DEALLOCATE PREPARE dormitory_004_statement;
ALTER TABLE residents
    ADD CONSTRAINT chk_residents_line_user_id
    CHECK (line_user_id IS NULL OR line_user_id REGEXP '^U[0-9a-f]{32}$');

SET @dormitory_004_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = 'notification_outbox'
           AND constraint_name = 'chk_notification_outbox_recipient'
           AND constraint_type = 'CHECK'
    ),
    'ALTER TABLE notification_outbox DROP CHECK chk_notification_outbox_recipient',
    'SELECT 1'
);
PREPARE dormitory_004_statement FROM @dormitory_004_sql;
EXECUTE dormitory_004_statement;
DEALLOCATE PREPARE dormitory_004_statement;
ALTER TABLE notification_outbox
    ADD CONSTRAINT chk_notification_outbox_recipient
    CHECK (recipient REGEXP '^U[0-9a-f]{32}$');

SET @dormitory_004_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = 'integration_settings'
           AND constraint_name = 'chk_integration_settings_line_secret'
           AND constraint_type = 'CHECK'
    ),
    'SELECT 1',
    'ALTER TABLE integration_settings ADD CONSTRAINT chk_integration_settings_line_secret CHECK (line_channel_secret_enc IS NULL OR CHAR_LENGTH(line_channel_secret_enc) BETWEEN 20 AND 65000)'
);
PREPARE dormitory_004_statement FROM @dormitory_004_sql;
EXECUTE dormitory_004_statement;
DEALLOCATE PREPARE dormitory_004_statement;

SET @dormitory_004_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = 'notification_outbox'
           AND constraint_name = 'chk_notification_outbox_line_request_id'
           AND constraint_type = 'CHECK'
    ),
    'SELECT 1',
    'ALTER TABLE notification_outbox ADD CONSTRAINT chk_notification_outbox_line_request_id CHECK (line_request_id IS NULL OR line_request_id REGEXP ''^[!-~]{1,128}$'')'
);
PREPARE dormitory_004_statement FROM @dormitory_004_sql;
EXECUTE dormitory_004_statement;
DEALLOCATE PREPARE dormitory_004_statement;

SET @dormitory_004_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = 'notification_outbox'
           AND constraint_name = 'chk_notification_outbox_line_accepted_request_id'
           AND constraint_type = 'CHECK'
    ),
    'SELECT 1',
    'ALTER TABLE notification_outbox ADD CONSTRAINT chk_notification_outbox_line_accepted_request_id CHECK (line_accepted_request_id IS NULL OR line_accepted_request_id REGEXP ''^[!-~]{1,128}$'')'
);
PREPARE dormitory_004_statement FROM @dormitory_004_sql;
EXECUTE dormitory_004_statement;
DEALLOCATE PREPARE dormitory_004_statement;

SELECT column_name
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name IN ('integration_settings', 'notification_outbox')
   AND column_name IN ('line_channel_secret_enc', 'line_request_id', 'line_accepted_request_id')
 ORDER BY table_name, ordinal_position;
