-- Repair migration for databases that ran an early copy of migration 002.
-- It is safe to run repeatedly with a schema-owning/DBA account.
-- Runtime DB users must not have TRIGGER or DROP privileges.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- Abort before changing triggers if this is not a DormFlow schema.
SET @dormitory_003_required_tables := (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN ('bill_items', 'audit_logs')
       AND table_type = 'BASE TABLE'
);
SET @dormitory_003_guard_sql := IF(
    @dormitory_003_required_tables = 2,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_ERROR_IMPORT_SCHEMA_AND_MIGRATIONS_FIRST'
);
PREPARE dormitory_003_guard FROM @dormitory_003_guard_sql;
EXECUTE dormitory_003_guard;
DEALLOCATE PREPARE dormitory_003_guard;

DROP TRIGGER IF EXISTS trg_bill_items_no_update;
DROP TRIGGER IF EXISTS trg_bill_items_no_delete;
DROP TRIGGER IF EXISTS trg_audit_logs_no_update;
DROP TRIGGER IF EXISTS trg_audit_logs_no_delete;

DELIMITER $$

CREATE TRIGGER trg_bill_items_no_update
BEFORE UPDATE ON bill_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Bill items are immutable';
END$$

CREATE TRIGGER trg_bill_items_no_delete
BEFORE DELETE ON bill_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Bill items are immutable';
END$$

CREATE TRIGGER trg_audit_logs_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Audit logs are append-only';
END$$

CREATE TRIGGER trg_audit_logs_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Audit logs are append-only';
END$$

DELIMITER ;

-- Expected result after migration: 4 rows.
SELECT trigger_name
  FROM information_schema.triggers
 WHERE trigger_schema = DATABASE()
   AND trigger_name IN (
       'trg_bill_items_no_update',
       'trg_bill_items_no_delete',
       'trg_audit_logs_no_update',
       'trg_audit_logs_no_delete'
   )
 ORDER BY trigger_name;
