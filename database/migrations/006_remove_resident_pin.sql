-- Remove the retired resident PIN credential from an installed database.
-- Deploy phone-only application code that supports both schema shapes first,
-- wait until every web replica is healthy, back up MySQL, then run this file.
-- Safe to rerun after a successful migration.

SET @dormitory_residents_table_count := (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'residents'
       AND table_type = 'BASE TABLE'
);
SET @dormitory_residents_table_guard_sql := IF(
    @dormitory_residents_table_count = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_MIGRATION_006_ABORT_RESIDENTS_TABLE_MISSING'
);
PREPARE dormitory_residents_table_guard FROM @dormitory_residents_table_guard_sql;
EXECUTE dormitory_residents_table_guard;
DEALLOCATE PREPARE dormitory_residents_table_guard;

SET @dormitory_has_resident_pin_column := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'residents'
       AND column_name = 'pin_hash'
);

SET @dormitory_drop_resident_pin_sql := IF(
    @dormitory_has_resident_pin_column = 1,
    'ALTER TABLE residents DROP COLUMN pin_hash',
    'SELECT 1'
);
PREPARE dormitory_drop_resident_pin FROM @dormitory_drop_resident_pin_sql;
EXECUTE dormitory_drop_resident_pin;
DEALLOCATE PREPARE dormitory_drop_resident_pin;

SET @dormitory_resident_pin_column_count := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'residents'
       AND column_name = 'pin_hash'
);
SET @dormitory_resident_pin_guard_sql := IF(
    @dormitory_resident_pin_column_count = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_MIGRATION_006_ABORT_PIN_COLUMN_REMAINS'
);
PREPARE dormitory_resident_pin_guard FROM @dormitory_resident_pin_guard_sql;
EXECUTE dormitory_resident_pin_guard;
DEALLOCATE PREPARE dormitory_resident_pin_guard;
