-- Add short-lived, self-service LINE account-link codes. Only the keyed-HMAC
-- digest is stored; the bearer code itself must never be persisted.
-- Safe to rerun after a successful migration.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

SET @dormitory_010_has_residents := (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'residents'
       AND table_type = 'BASE TABLE'
);
SET @dormitory_010_prerequisite_guard_sql := IF(
    @dormitory_010_has_residents = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_010_IMPORT_PRIOR_SCHEMA_FIRST'
);
PREPARE dormitory_010_prerequisite_guard
    FROM @dormitory_010_prerequisite_guard_sql;
EXECUTE dormitory_010_prerequisite_guard;
DEALLOCATE PREPARE dormitory_010_prerequisite_guard;

CREATE TABLE IF NOT EXISTS line_link_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resident_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('pending', 'bound', 'expired', 'revoked') NOT NULL DEFAULT 'pending',
    line_user_id VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expires_at DATETIME(6) NOT NULL,
    bound_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    pending_resident_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE WHEN status = 'pending' THEN resident_id ELSE NULL END
        ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_line_link_codes_code_hash (code_hash),
    UNIQUE KEY uq_line_link_codes_pending_resident (pending_resident_id),
    KEY idx_line_link_codes_expiry (status, expires_at),
    KEY idx_line_link_codes_resident (resident_id, created_at),
    CONSTRAINT fk_line_link_codes_resident
        FOREIGN KEY (resident_id) REFERENCES residents(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_line_link_codes_hash
        CHECK (code_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_line_link_codes_line_user CHECK (
        line_user_id IS NULL OR line_user_id REGEXP '^U[0-9a-f]{32}$'
    ),
    CONSTRAINT chk_line_link_codes_state CHECK (
        (
            status = 'pending'
            AND line_user_id IS NULL
            AND bound_at IS NULL
            AND revoked_at IS NULL
        )
        OR (
            status = 'bound'
            AND line_user_id IS NOT NULL
            AND bound_at IS NOT NULL
            AND revoked_at IS NULL
        )
        OR (
            status = 'expired'
            AND line_user_id IS NULL
            AND bound_at IS NULL
            AND revoked_at IS NULL
        )
        OR (
            status = 'revoked'
            AND revoked_at IS NOT NULL
            AND (
                (line_user_id IS NULL AND bound_at IS NULL)
                OR (line_user_id IS NOT NULL AND bound_at IS NOT NULL)
            )
        )
    ),
    CONSTRAINT chk_line_link_codes_timestamps CHECK (
        expires_at > created_at
        AND (
            bound_at IS NULL
            OR (bound_at >= created_at AND bound_at < expires_at)
        )
        AND (
            revoked_at IS NULL
            OR (
                revoked_at >= created_at
                AND (bound_at IS NULL OR revoked_at >= bound_at)
            )
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CREATE TABLE IF NOT EXISTS must not silently accept a partial or incompatible
-- table left by a failed/manual deployment. Validate its security-critical
-- columns, generated key, constraints, indexes, and resident relationship.
SET @dormitory_010_table_count := (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'line_link_codes'
       AND table_type = 'BASE TABLE'
       AND engine = 'InnoDB'
);

SET @dormitory_010_column_count := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'line_link_codes'
);

SET @dormitory_010_valid_column_count := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'line_link_codes'
       AND (
            (column_name = 'id'
                AND column_type = 'bigint unsigned'
                AND is_nullable = 'NO'
                AND extra LIKE '%auto_increment%')
         OR (column_name = 'resident_id'
                AND column_type = 'bigint unsigned'
                AND is_nullable = 'NO')
         OR (column_name = 'code_hash'
                AND data_type = 'char'
                AND character_maximum_length = 64
                AND character_set_name = 'ascii'
                AND collation_name = 'ascii_bin'
                AND is_nullable = 'NO')
         OR (column_name = 'status'
                AND column_type = 'enum(''pending'',''bound'',''expired'',''revoked'')'
                AND is_nullable = 'NO')
         OR (column_name = 'line_user_id'
                AND data_type = 'varchar'
                AND character_maximum_length = 33
                AND character_set_name = 'ascii'
                AND collation_name = 'ascii_bin'
                AND is_nullable = 'YES')
         OR (column_name = 'expires_at'
                AND data_type = 'datetime'
                AND datetime_precision = 6
                AND is_nullable = 'NO')
         OR (column_name IN ('bound_at', 'revoked_at')
                AND data_type = 'datetime'
                AND datetime_precision = 6
                AND is_nullable = 'YES')
         OR (column_name IN ('created_at', 'updated_at')
                AND data_type = 'datetime'
                AND datetime_precision = 6
                AND is_nullable = 'NO')
         OR (column_name = 'pending_resident_id'
                AND column_type = 'bigint unsigned'
                AND is_nullable = 'YES'
                AND extra LIKE '%STORED GENERATED%'
                AND LOWER(generation_expression) LIKE '%status%'
                AND LOWER(generation_expression) LIKE '%pending%'
                AND LOWER(generation_expression) LIKE '%resident_id%')
       )
);

SET @dormitory_010_constraint_count := (
    SELECT COUNT(*)
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'line_link_codes'
       AND (
            (constraint_name = 'fk_line_link_codes_resident'
                AND constraint_type = 'FOREIGN KEY')
         OR (constraint_name IN (
                'chk_line_link_codes_hash',
                'chk_line_link_codes_line_user',
                'chk_line_link_codes_state',
                'chk_line_link_codes_timestamps'
             ) AND constraint_type = 'CHECK')
       )
);

SET @dormitory_010_fk_column_count := (
    SELECT COUNT(*)
      FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE()
       AND table_name = 'line_link_codes'
       AND constraint_name = 'fk_line_link_codes_resident'
       AND column_name = 'resident_id'
       AND referenced_table_schema = DATABASE()
       AND referenced_table_name = 'residents'
       AND referenced_column_name = 'id'
);

SET @dormitory_010_fk_rule_count := (
    SELECT COUNT(*)
      FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'line_link_codes'
       AND constraint_name = 'fk_line_link_codes_resident'
       AND update_rule = 'RESTRICT'
       AND delete_rule = 'RESTRICT'
);

SET @dormitory_010_index_row_count := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'line_link_codes'
       AND index_name IN (
           'uq_line_link_codes_code_hash',
           'uq_line_link_codes_pending_resident',
           'idx_line_link_codes_expiry',
           'idx_line_link_codes_resident'
       )
);

SET @dormitory_010_valid_index_row_count := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'line_link_codes'
       AND sub_part IS NULL
       AND (
            (index_name = 'uq_line_link_codes_code_hash'
                AND non_unique = 0
                AND seq_in_index = 1
                AND column_name = 'code_hash')
         OR (index_name = 'uq_line_link_codes_pending_resident'
                AND non_unique = 0
                AND seq_in_index = 1
                AND column_name = 'pending_resident_id')
         OR (index_name = 'idx_line_link_codes_expiry'
                AND non_unique = 1
                AND (
                    (seq_in_index = 1 AND column_name = 'status')
                    OR (seq_in_index = 2 AND column_name = 'expires_at')
                ))
         OR (index_name = 'idx_line_link_codes_resident'
                AND non_unique = 1
                AND (
                    (seq_in_index = 1 AND column_name = 'resident_id')
                    OR (seq_in_index = 2 AND column_name = 'created_at')
                ))
       )
);

SET @dormitory_010_table_guard_sql := IF(
    @dormitory_010_table_count = 1
        AND @dormitory_010_column_count = 11
        AND @dormitory_010_valid_column_count = 11
        AND @dormitory_010_constraint_count = 5
        AND @dormitory_010_fk_column_count = 1
        AND @dormitory_010_fk_rule_count = 1
        AND @dormitory_010_index_row_count = 6
        AND @dormitory_010_valid_index_row_count = 6,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_010_INVALID_LINE_LINK_CODES_TABLE'
);
PREPARE dormitory_010_table_guard FROM @dormitory_010_table_guard_sql;
EXECUTE dormitory_010_table_guard;
DEALLOCATE PREPARE dormitory_010_table_guard;

SELECT table_name, column_name
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'line_link_codes'
 ORDER BY ordinal_position;
