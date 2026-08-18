-- Persist a canonical digest of the move-in request on the booking ledger.
-- This keeps idempotent replay independent from mutable resident profile data.
-- Safe to rerun with a schema-owning/DBA account during maintenance.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- Refuse an unrelated or pre-hardening schema before changing any object.
SET @dormitory_012_has_bookings := (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND table_type = 'BASE TABLE'
       AND engine = 'InnoDB'
);
SET @dormitory_012_prerequisite_columns := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND column_name IN (
           'reference_no', 'room_id', 'full_name', 'phone_norm',
           'booked_monthly_rent', 'status', 'idempotency_key',
           'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at',
           'cancel_reason', 'resident_id', 'moved_in_at', 'created_at'
       )
);
SET @dormitory_012_prerequisite_sql := IF(
    @dormitory_012_has_bookings = 1
        AND @dormitory_012_prerequisite_columns = 15,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_012_IMPORT_PRIOR_MIGRATIONS_FIRST'
);
PREPARE dormitory_012_prerequisite FROM @dormitory_012_prerequisite_sql;
EXECUTE dormitory_012_prerequisite;
DEALLOCATE PREPARE dormitory_012_prerequisite;

-- An existing column must already have the exact security-relevant shape.
-- Never silently accept or coerce a partial/manual migration.
SET @dormitory_012_column_count := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND column_name = 'move_in_request_hash'
);
SET @dormitory_012_valid_column_count := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND column_name = 'move_in_request_hash'
       AND column_type = 'char(64)'
       AND character_set_name = 'ascii'
       AND collation_name = 'ascii_bin'
       AND is_nullable = 'YES'
);
SET @dormitory_012_column_guard_sql := IF(
    @dormitory_012_column_count = 0
        OR (@dormitory_012_column_count = 1
            AND @dormitory_012_valid_column_count = 1),
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_012_INVALID_MOVE_IN_HASH_COLUMN'
);
PREPARE dormitory_012_column_guard FROM @dormitory_012_column_guard_sql;
EXECUTE dormitory_012_column_guard;
DEALLOCATE PREPARE dormitory_012_column_guard;

SET @dormitory_012_add_column_sql := IF(
    @dormitory_012_column_count = 0,
    'ALTER TABLE bookings ADD COLUMN move_in_request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER moved_in_at',
    'SELECT 1'
);
PREPARE dormitory_012_add_column FROM @dormitory_012_add_column_sql;
EXECUTE dormitory_012_add_column;
DEALLOCATE PREPARE dormitory_012_add_column;

-- The name may be absent or may already identify a CHECK. A conflicting
-- constraint type is an incompatible manual schema and must stop migration.
SET @dormitory_012_constraint_name_count := (
    SELECT COUNT(*)
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'bookings'
       AND constraint_name = 'chk_bookings_move_in_request_hash'
);
SET @dormitory_012_check_name_count := (
    SELECT COUNT(*)
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'bookings'
       AND constraint_name = 'chk_bookings_move_in_request_hash'
       AND constraint_type = 'CHECK'
);
SET @dormitory_012_constraint_guard_sql := IF(
    (@dormitory_012_constraint_name_count = 0
        AND @dormitory_012_check_name_count = 0)
    OR (@dormitory_012_constraint_name_count = 1
        AND @dormitory_012_check_name_count = 1),
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_012_CONFLICTING_CHECK_NAME'
);
PREPARE dormitory_012_constraint_guard FROM @dormitory_012_constraint_guard_sql;
EXECUTE dormitory_012_constraint_guard;
DEALLOCATE PREPARE dormitory_012_constraint_guard;

-- Validate data before replacing a possibly incomplete same-named CHECK.
SET @dormitory_012_invalid_hash_rows := (
    SELECT COUNT(*)
      FROM bookings
     WHERE move_in_request_hash IS NOT NULL
       AND (
           status <> 'moved_in'
           OR move_in_request_hash NOT REGEXP '^[0-9a-f]{64}$'
       )
);
SET @dormitory_012_data_guard_sql := IF(
    @dormitory_012_invalid_hash_rows = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_012_INVALID_MOVE_IN_HASH_DATA'
);
PREPARE dormitory_012_data_guard FROM @dormitory_012_data_guard_sql;
EXECUTE dormitory_012_data_guard;
DEALLOCATE PREPARE dormitory_012_data_guard;

-- Recreate the named CHECK so rerunning repairs an incomplete definition,
-- rather than trusting only a matching constraint name.
SET @dormitory_012_drop_check_sql := IF(
    @dormitory_012_check_name_count = 1,
    'ALTER TABLE bookings DROP CHECK chk_bookings_move_in_request_hash',
    'SELECT 1'
);
PREPARE dormitory_012_drop_check FROM @dormitory_012_drop_check_sql;
EXECUTE dormitory_012_drop_check;
DEALLOCATE PREPARE dormitory_012_drop_check;

ALTER TABLE bookings
    ADD CONSTRAINT chk_bookings_move_in_request_hash CHECK (
        move_in_request_hash IS NULL
        OR (
            status = 'moved_in'
            AND move_in_request_hash REGEXP '^[0-9a-f]{64}$'
        )
    );

SET @dormitory_012_post_column_count := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'bookings'
       AND column_name = 'move_in_request_hash'
       AND column_type = 'char(64)'
       AND character_set_name = 'ascii'
       AND collation_name = 'ascii_bin'
       AND is_nullable = 'YES'
);
SET @dormitory_012_post_check_count := (
    SELECT COUNT(*)
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'bookings'
       AND constraint_name = 'chk_bookings_move_in_request_hash'
       AND constraint_type = 'CHECK'
);
SET @dormitory_012_schema_postcondition_sql := IF(
    @dormitory_012_post_column_count = 1
        AND @dormitory_012_post_check_count = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_012_MOVE_IN_HASH_POSTCONDITION_FAILED'
);
PREPARE dormitory_012_schema_postcondition
    FROM @dormitory_012_schema_postcondition_sql;
EXECUTE dormitory_012_schema_postcondition;
DEALLOCATE PREPARE dormitory_012_schema_postcondition;

-- Keep the digest immutable after the confirmed -> moved_in transition. This
-- also prevents silently backfilling an unverifiable digest onto a legacy row.
DROP TRIGGER IF EXISTS trg_bookings_identity_immutable;

DELIMITER $$

CREATE TRIGGER trg_bookings_identity_immutable
BEFORE UPDATE ON bookings
FOR EACH ROW
BEGIN
    IF NOT (OLD.reference_no <=> NEW.reference_no)
        OR NOT (OLD.room_id <=> NEW.room_id)
        OR NOT (OLD.full_name <=> NEW.full_name)
        OR NOT (OLD.phone_norm <=> NEW.phone_norm)
        OR NOT (OLD.booked_monthly_rent <=> NEW.booked_monthly_rent)
        OR NOT (OLD.idempotency_key <=> NEW.idempotency_key)
        OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Booking identity and rent snapshot are immutable';
    END IF;
    IF NOT (
        OLD.status = NEW.status
        OR (OLD.status = 'pending' AND NEW.status IN ('confirmed', 'cancelled'))
        OR (OLD.status = 'confirmed' AND NEW.status IN ('cancelled', 'moved_in'))
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid booking state transition';
    END IF;
    IF (OLD.confirmed_at IS NOT NULL
            AND (NOT (OLD.confirmed_at <=> NEW.confirmed_at)
                OR NOT (OLD.confirmed_by <=> NEW.confirmed_by)))
        OR (OLD.cancelled_at IS NOT NULL
            AND (NOT (OLD.cancelled_at <=> NEW.cancelled_at)
                OR NOT (OLD.cancelled_by <=> NEW.cancelled_by)
                OR NOT (OLD.cancel_reason <=> NEW.cancel_reason)))
        OR (OLD.moved_in_at IS NOT NULL
            AND (NOT (OLD.moved_in_at <=> NEW.moved_in_at)
                OR NOT (OLD.resident_id <=> NEW.resident_id)))
        OR (OLD.status = 'moved_in'
            AND NOT (OLD.move_in_request_hash <=> NEW.move_in_request_hash)) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Booking transition evidence is immutable once recorded';
    END IF;
END$$

DELIMITER ;

SET @dormitory_012_trigger_postcondition := (
    SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name = 'trg_bookings_identity_immutable'
       AND event_object_table = 'bookings'
       AND action_timing = 'BEFORE'
       AND event_manipulation = 'UPDATE'
);
SET @dormitory_012_trigger_postcondition_sql := IF(
    @dormitory_012_trigger_postcondition = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_012_TRIGGER_POSTCONDITION_FAILED'
);
PREPARE dormitory_012_trigger_postcondition_statement
    FROM @dormitory_012_trigger_postcondition_sql;
EXECUTE dormitory_012_trigger_postcondition_statement;
DEALLOCATE PREPARE dormitory_012_trigger_postcondition_statement;

SELECT column_name, column_type, is_nullable
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'bookings'
   AND column_name = 'move_in_request_hash';
