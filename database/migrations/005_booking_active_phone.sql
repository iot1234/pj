-- Add the one-active-booking-per-phone invariant to an installed database.
-- Back up and test restore first. Safe to rerun after a successful migration.

SET @dormitory_duplicate_active_phone_count := (
    SELECT COUNT(*)
      FROM (
          SELECT phone_norm
            FROM bookings
           WHERE status IN ('pending', 'confirmed')
           GROUP BY phone_norm
          HAVING COUNT(*) > 1
      ) duplicate_active_phones
);
SET @dormitory_booking_phone_guard_sql := IF(
    @dormitory_duplicate_active_phone_count = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_MIGRATION_005_ABORT_DUPLICATE_ACTIVE_PHONE'
);
PREPARE dormitory_booking_phone_guard FROM @dormitory_booking_phone_guard_sql;
EXECUTE dormitory_booking_phone_guard;
DEALLOCATE PREPARE dormitory_booking_phone_guard;

SET @dormitory_has_active_phone_column := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND column_name = 'active_phone_norm'
);
SET @dormitory_add_active_phone_column_sql := IF(
    @dormitory_has_active_phone_column = 0,
    'ALTER TABLE bookings ADD COLUMN active_phone_norm CHAR(10) GENERATED ALWAYS AS (CASE WHEN status IN (''pending'', ''confirmed'') THEN phone_norm ELSE NULL END) STORED AFTER active_room_id',
    'SELECT 1'
);
PREPARE dormitory_add_active_phone_column FROM @dormitory_add_active_phone_column_sql;
EXECUTE dormitory_add_active_phone_column;
DEALLOCATE PREPARE dormitory_add_active_phone_column;

SET @dormitory_expected_active_phone_expression_a := CONCAT(
    'casewhenstatusin', CHAR(39), 'pending', CHAR(39), ',',
    CHAR(39), 'confirmed', CHAR(39), 'thenphone_normelsenullend'
);
SET @dormitory_expected_active_phone_expression_b := CONCAT(
    'casewhenstatusin', CHAR(39), 'confirmed', CHAR(39), ',',
    CHAR(39), 'pending', CHAR(39), 'thenphone_normelsenullend'
);
SET @dormitory_valid_active_phone_column := (
    SELECT COUNT(*)
      FROM (
          SELECT column_metadata.*,
                 LOWER(REGEXP_REPLACE(
                     REPLACE(REPLACE(REPLACE(column_metadata.literal_expression, CHAR(96), ''), '(', ''), ')', ''),
                     '[[:space:]]+',
                     ''
                 )) AS normalized_expression
            FROM (
                SELECT data_type, character_maximum_length, is_nullable, extra,
                       LOWER(REGEXP_REPLACE(
                           REPLACE(generation_expression, CONCAT(CHAR(92), CHAR(39)), CHAR(39)),
                           CONCAT('_[[:alnum:]]+', CHAR(39)),
                           CHAR(39)
                       )) AS literal_expression
                  FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'bookings'
                   AND column_name = 'active_phone_norm'
            ) column_metadata
      ) normalized
     WHERE data_type = 'char'
       AND character_maximum_length = 10
       AND is_nullable = 'YES'
       AND extra = 'STORED GENERATED'
       AND LENGTH(literal_expression) - LENGTH(REPLACE(literal_expression, CHAR(39), '')) = 4
       AND LOCATE(CONCAT(CHAR(39), 'pending', CHAR(39)), literal_expression) > 0
       AND LOCATE(CONCAT(CHAR(39), 'confirmed', CHAR(39)), literal_expression) > 0
       AND normalized_expression IN (
           @dormitory_expected_active_phone_expression_a,
           @dormitory_expected_active_phone_expression_b
       )
);
SET @dormitory_active_phone_column_guard_sql := IF(
    @dormitory_valid_active_phone_column = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_MIGRATION_005_ABORT_INVALID_ACTIVE_PHONE_COLUMN'
);
PREPARE dormitory_active_phone_column_guard FROM @dormitory_active_phone_column_guard_sql;
EXECUTE dormitory_active_phone_column_guard;
DEALLOCATE PREPARE dormitory_active_phone_column_guard;

SET @dormitory_has_active_phone_index := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND index_name = 'uq_bookings_one_active_per_phone'
);
SET @dormitory_valid_existing_active_phone_index := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND index_name = 'uq_bookings_one_active_per_phone'
       AND non_unique = 0
       AND seq_in_index = 1
       AND column_name = 'active_phone_norm'
       AND sub_part IS NULL
);
SET @dormitory_active_phone_index_guard_sql := IF(
    @dormitory_has_active_phone_index = 0
        OR (@dormitory_has_active_phone_index = 1
            AND @dormitory_valid_existing_active_phone_index = 1),
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_MIGRATION_005_ABORT_INVALID_ACTIVE_PHONE_INDEX'
);
PREPARE dormitory_active_phone_index_guard FROM @dormitory_active_phone_index_guard_sql;
EXECUTE dormitory_active_phone_index_guard;
DEALLOCATE PREPARE dormitory_active_phone_index_guard;
SET @dormitory_add_active_phone_index_sql := IF(
    @dormitory_has_active_phone_index = 0,
    'ALTER TABLE bookings ADD UNIQUE KEY uq_bookings_one_active_per_phone (active_phone_norm)',
    'SELECT 1'
);
PREPARE dormitory_add_active_phone_index FROM @dormitory_add_active_phone_index_sql;
EXECUTE dormitory_add_active_phone_index;
DEALLOCATE PREPARE dormitory_add_active_phone_index;

SET @dormitory_final_active_phone_index_row_count := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND index_name = 'uq_bookings_one_active_per_phone'
);
SET @dormitory_valid_active_phone_index := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND index_name = 'uq_bookings_one_active_per_phone'
       AND non_unique = 0
       AND seq_in_index = 1
       AND column_name = 'active_phone_norm'
       AND sub_part IS NULL
);
SET @dormitory_final_active_phone_index_guard_sql := IF(
    @dormitory_final_active_phone_index_row_count = 1
        AND @dormitory_valid_active_phone_index = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_MIGRATION_005_ABORT_MISSING_ACTIVE_PHONE_INDEX'
);
PREPARE dormitory_final_active_phone_index_guard FROM @dormitory_final_active_phone_index_guard_sql;
EXECUTE dormitory_final_active_phone_index_guard;
DEALLOCATE PREPARE dormitory_final_active_phone_index_guard;
