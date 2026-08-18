-- Replace phone-only resident login with expiring, single-use activation
-- credentials and a persistent password hash. Safe to rerun.

SET @dormitory_residents_table_count := (
    SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='residents' AND table_type='BASE TABLE'
);
SET @dormitory_residents_guard_sql := IF(
    @dormitory_residents_table_count=1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_MIGRATION_008_ABORT_RESIDENTS_TABLE_MISSING'
);
PREPARE dormitory_residents_guard FROM @dormitory_residents_guard_sql;
EXECUTE dormitory_residents_guard;
DEALLOCATE PREPARE dormitory_residents_guard;

SET @dormitory_has_access_password_hash := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='residents' AND column_name='access_password_hash'
);
SET @dormitory_add_access_password_hash := IF(
    @dormitory_has_access_password_hash=0,
    'ALTER TABLE residents ADD COLUMN access_password_hash VARCHAR(255) NULL AFTER auth_version',
    'SELECT 1'
);
PREPARE dormitory_add_access_password_hash_stmt FROM @dormitory_add_access_password_hash;
EXECUTE dormitory_add_access_password_hash_stmt;
DEALLOCATE PREPARE dormitory_add_access_password_hash_stmt;

SET @dormitory_has_activation_code_hash := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='residents' AND column_name='activation_code_hash'
);
SET @dormitory_add_activation_code_hash := IF(
    @dormitory_has_activation_code_hash=0,
    'ALTER TABLE residents ADD COLUMN activation_code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER access_password_hash',
    'SELECT 1'
);
PREPARE dormitory_add_activation_code_hash_stmt FROM @dormitory_add_activation_code_hash;
EXECUTE dormitory_add_activation_code_hash_stmt;
DEALLOCATE PREPARE dormitory_add_activation_code_hash_stmt;

SET @dormitory_has_activation_expires_at := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='residents' AND column_name='activation_expires_at'
);
SET @dormitory_add_activation_expires_at := IF(
    @dormitory_has_activation_expires_at=0,
    'ALTER TABLE residents ADD COLUMN activation_expires_at DATETIME(6) NULL AFTER activation_code_hash',
    'SELECT 1'
);
PREPARE dormitory_add_activation_expires_at_stmt FROM @dormitory_add_activation_expires_at;
EXECUTE dormitory_add_activation_expires_at_stmt;
DEALLOCATE PREPARE dormitory_add_activation_expires_at_stmt;

SET @dormitory_has_activation_consumed_at := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='residents' AND column_name='activation_consumed_at'
);
SET @dormitory_add_activation_consumed_at := IF(
    @dormitory_has_activation_consumed_at=0,
    'ALTER TABLE residents ADD COLUMN activation_consumed_at DATETIME(6) NULL AFTER activation_expires_at',
    'SELECT 1'
);
PREPARE dormitory_add_activation_consumed_at_stmt FROM @dormitory_add_activation_consumed_at;
EXECUTE dormitory_add_activation_consumed_at_stmt;
DEALLOCATE PREPARE dormitory_add_activation_consumed_at_stmt;

SET @dormitory_resident_access_column_count := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='residents'
       AND column_name IN (
           'access_password_hash','activation_code_hash',
           'activation_expires_at','activation_consumed_at'
       )
);
SET @dormitory_resident_access_guard_sql := IF(
    @dormitory_resident_access_column_count=4,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_MIGRATION_008_ABORT_COLUMNS_MISSING'
);
PREPARE dormitory_resident_access_guard FROM @dormitory_resident_access_guard_sql;
EXECUTE dormitory_resident_access_guard;
DEALLOCATE PREPARE dormitory_resident_access_guard;

SET @dormitory_008_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema=DATABASE()
           AND table_name='residents'
           AND constraint_name='chk_residents_access_password'
           AND constraint_type='CHECK'
    ),
    'SELECT 1',
    'ALTER TABLE residents ADD CONSTRAINT chk_residents_access_password CHECK (access_password_hash IS NULL OR CHAR_LENGTH(access_password_hash) BETWEEN 20 AND 255)'
);
PREPARE dormitory_008_statement FROM @dormitory_008_sql;
EXECUTE dormitory_008_statement;
DEALLOCATE PREPARE dormitory_008_statement;

SET @dormitory_008_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema=DATABASE()
           AND table_name='residents'
           AND constraint_name='chk_residents_activation_state'
           AND constraint_type='CHECK'
    ),
    'SELECT 1',
    'ALTER TABLE residents ADD CONSTRAINT chk_residents_activation_state CHECK (((activation_code_hash IS NULL) AND (activation_expires_at IS NULL)) OR ((activation_code_hash REGEXP ''^[0-9a-f]{64}$'') AND (activation_expires_at IS NOT NULL) AND (activation_consumed_at IS NULL) AND (access_password_hash IS NULL)))'
);
PREPARE dormitory_008_statement FROM @dormitory_008_sql;
EXECUTE dormitory_008_statement;
DEALLOCATE PREPARE dormitory_008_statement;

SET @dormitory_008_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema=DATABASE()
           AND table_name='residents'
           AND constraint_name='chk_residents_password_activation'
           AND constraint_type='CHECK'
    ),
    'SELECT 1',
    'ALTER TABLE residents ADD CONSTRAINT chk_residents_password_activation CHECK (access_password_hash IS NULL OR ((activation_code_hash IS NULL) AND (activation_expires_at IS NULL) AND (activation_consumed_at IS NOT NULL)))'
);
PREPARE dormitory_008_statement FROM @dormitory_008_sql;
EXECUTE dormitory_008_statement;
DEALLOCATE PREPARE dormitory_008_statement;
