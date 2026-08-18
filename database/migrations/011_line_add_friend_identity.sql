-- Store the public LINE Official Account Basic ID in MySQL so residents can
-- open the correct add-friend page before submitting a short-lived BIND code.
-- This value is public metadata, not a channel credential. Safe to rerun.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

SET @dormitory_011_has_settings := (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'integration_settings'
       AND table_type = 'BASE TABLE'
);
SET @dormitory_011_prerequisite_sql := IF(
    @dormitory_011_has_settings = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_011_IMPORT_PRIOR_SCHEMA_FIRST'
);
PREPARE dormitory_011_prerequisite FROM @dormitory_011_prerequisite_sql;
EXECUTE dormitory_011_prerequisite;
DEALLOCATE PREPARE dormitory_011_prerequisite;

SET @dormitory_011_has_column := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'integration_settings'
       AND column_name = 'line_basic_id'
);
SET @dormitory_011_add_column_sql := IF(
    @dormitory_011_has_column = 0,
    'ALTER TABLE integration_settings ADD COLUMN line_basic_id VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER payment_receiver_account_tail',
    'SELECT 1'
);
PREPARE dormitory_011_add_column FROM @dormitory_011_add_column_sql;
EXECUTE dormitory_011_add_column;
DEALLOCATE PREPARE dormitory_011_add_column;

SET @dormitory_011_has_check := (
    SELECT COUNT(*)
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'integration_settings'
       AND constraint_type = 'CHECK'
       AND constraint_name = 'chk_integration_settings_line_basic_id'
);
SET @dormitory_011_add_check_sql := IF(
    @dormitory_011_has_check = 0,
    'ALTER TABLE integration_settings ADD CONSTRAINT chk_integration_settings_line_basic_id CHECK (line_basic_id IS NULL OR line_basic_id REGEXP ''^@[A-Za-z0-9._-]{1,32}$'')',
    'SELECT 1'
);
PREPARE dormitory_011_add_check FROM @dormitory_011_add_check_sql;
EXECUTE dormitory_011_add_check;
DEALLOCATE PREPARE dormitory_011_add_check;

SET @dormitory_011_valid_column := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'integration_settings'
       AND column_name = 'line_basic_id'
       AND column_type = 'varchar(33)'
       AND character_set_name = 'ascii'
       AND collation_name = 'ascii_bin'
       AND is_nullable = 'YES'
);
SET @dormitory_011_valid_check := (
    SELECT COUNT(*)
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'integration_settings'
       AND constraint_type = 'CHECK'
       AND constraint_name = 'chk_integration_settings_line_basic_id'
);
SET @dormitory_011_postcondition_sql := IF(
    @dormitory_011_valid_column = 1 AND @dormitory_011_valid_check = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_011_LINE_BASIC_ID_POSTCONDITION_FAILED'
);
PREPARE dormitory_011_postcondition FROM @dormitory_011_postcondition_sql;
EXECUTE dormitory_011_postcondition;
DEALLOCATE PREPARE dormitory_011_postcondition;
