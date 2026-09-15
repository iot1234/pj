-- Preserve unknown legacy opening readings without inventing consumption.
-- Apply after 014 with web, workers and scheduled jobs stopped; back up and
-- prove restoration first. Import without --force. Safe to rerun after a
-- successful run, or to finish an interrupted run while writes stay stopped.
-- New check-ins still require both values. Only an active pending occupancy
-- with no meter/bill history may complete both real opening readings once.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

SET @dormitory_015_required_tables := (
    SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
       AND table_name IN ('occupancies', 'meter_readings', 'bills', 'line_official_accounts')
);
SET @dormitory_015_required_columns := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'occupancies'
       AND column_name IN ('opening_water_reading', 'opening_electric_reading')
       AND column_type = 'decimal(14,2)' AND is_nullable = 'YES'
);
SET @dormitory_015_prerequisite_sql := IF(
    @dormitory_015_required_tables = 4 AND @dormitory_015_required_columns = 2,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_015_IMPORT_PRIOR_MIGRATIONS_FIRST'
);
PREPARE dormitory_015_prerequisite FROM @dormitory_015_prerequisite_sql;
EXECUTE dormitory_015_prerequisite;
DEALLOCATE PREPARE dormitory_015_prerequisite;

-- Reject partial readings, invalid values and unknown readings with history
-- before replacing any guard. Those cases need evidence-based DBA recovery.
SET @dormitory_015_invalid_opening_readings := (
    SELECT COUNT(*) FROM occupancies o
     WHERE (o.opening_water_reading IS NULL) <> (o.opening_electric_reading IS NULL)
        OR o.opening_water_reading < 0 OR o.opening_water_reading > 9999999.00
        OR o.opening_electric_reading < 0 OR o.opening_electric_reading > 9999999.00
        OR (
            o.opening_water_reading IS NULL AND o.opening_electric_reading IS NULL
            AND (
                EXISTS (
                    SELECT 1 FROM meter_readings history
                     WHERE history.occupancy_id = o.id
                        OR (history.room_id = o.room_id
                            AND history.period >= DATE_FORMAT(o.move_in_date, '%Y-%m-01')
                            AND history.period <= DATE_FORMAT(COALESCE(o.move_out_date, '9999-12-31'), '%Y-%m-01'))
                )
                OR EXISTS (
                    SELECT 1 FROM bills history
                     WHERE history.occupancy_id = o.id
                        OR (history.room_id = o.room_id
                            AND history.period >= DATE_FORMAT(o.move_in_date, '%Y-%m-01')
                            AND history.period <= DATE_FORMAT(COALESCE(o.move_out_date, '9999-12-31'), '%Y-%m-01'))
                )
            )
        )
);
SET @dormitory_015_data_guard_sql := IF(
    @dormitory_015_invalid_opening_readings = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_015_REPAIR_OPENING_READINGS_HISTORY_FIRST'
);
PREPARE dormitory_015_data_guard FROM @dormitory_015_data_guard_sql;
EXECUTE dormitory_015_data_guard;
DEALLOCATE PREPARE dormitory_015_data_guard;

-- The v2 CHECK is also the application's readiness marker. Remove it before
-- replacing triggers so an interrupted rerun cannot report this upgrade ready.
SET @dormitory_015_remove_marker_sql := IF(
    EXISTS(SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_schema = DATABASE() AND table_name = 'occupancies'
              AND constraint_name = 'chk_occupancies_opening_readings_v2'
              AND constraint_type = 'CHECK'),
    'ALTER TABLE occupancies DROP CHECK chk_occupancies_opening_readings_v2',
    'SELECT 1'
);
PREPARE dormitory_015_remove_marker FROM @dormitory_015_remove_marker_sql;
EXECUTE dormitory_015_remove_marker;
DEALLOCATE PREPARE dormitory_015_remove_marker;

-- The following four trigger bodies match database/schema.sql exactly.

DROP TRIGGER IF EXISTS trg_occupancies_identity_immutable;

DELIMITER $$

CREATE TRIGGER trg_occupancies_identity_immutable
BEFORE UPDATE ON occupancies
FOR EACH ROW
BEGIN
    DECLARE meter_history INT UNSIGNED DEFAULT 0;
    DECLARE bill_history INT UNSIGNED DEFAULT 0;

    IF NOT (OLD.id <=> NEW.id)
        OR NOT (OLD.resident_id <=> NEW.resident_id)
        OR NOT (OLD.room_id <=> NEW.room_id)
        OR NOT (OLD.booking_id <=> NEW.booking_id)
        OR NOT (OLD.monthly_rent <=> NEW.monthly_rent)
        OR NOT (OLD.move_in_date <=> NEW.move_in_date)
        OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Occupancy identity and rent snapshot are immutable';
    END IF;
    IF NOT (OLD.opening_water_reading <=> NEW.opening_water_reading)
        OR NOT (OLD.opening_electric_reading <=> NEW.opening_electric_reading) THEN
        IF OLD.status <> 'active'
            OR NEW.status <> 'active'
            OR NOT (OLD.move_out_date <=> NEW.move_out_date)
            OR OLD.opening_water_reading IS NOT NULL
            OR OLD.opening_electric_reading IS NOT NULL
            OR NEW.opening_water_reading IS NULL
            OR NEW.opening_electric_reading IS NULL
            OR NEW.opening_water_reading < 0
            OR NEW.opening_water_reading > 9999999.00
            OR NEW.opening_electric_reading < 0
            OR NEW.opening_electric_reading > 9999999.00 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Opening readings may only be completed together once for a pending active occupancy';
        END IF;
        SELECT COUNT(*) INTO meter_history
          FROM meter_readings
         WHERE occupancy_id = OLD.id
            OR (room_id = OLD.room_id
                AND period >= DATE_FORMAT(OLD.move_in_date, '%Y-%m-01')
                AND period <= DATE_FORMAT(COALESCE(OLD.move_out_date, '9999-12-31'), '%Y-%m-01'))
         FOR SHARE;
        SELECT COUNT(*) INTO bill_history
          FROM bills
         WHERE occupancy_id = OLD.id
            OR (room_id = OLD.room_id
                AND period >= DATE_FORMAT(OLD.move_in_date, '%Y-%m-01')
                AND period <= DATE_FORMAT(COALESCE(OLD.move_out_date, '9999-12-31'), '%Y-%m-01'))
         FOR SHARE;
        IF meter_history <> 0 OR bill_history <> 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Opening readings cannot be completed after meter or bill history exists';
        END IF;
    END IF;
    IF OLD.status = 'ended'
        AND (NEW.status <> 'ended' OR NOT (OLD.move_out_date <=> NEW.move_out_date)) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'An ended occupancy is immutable';
    END IF;
    IF OLD.status = 'active' AND NEW.status NOT IN ('active', 'ended') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid occupancy state transition';
    END IF;
END$$

DELIMITER ;

DROP TRIGGER IF EXISTS trg_meter_readings_occupancy_guard;

DELIMITER $$

CREATE TRIGGER trg_meter_readings_occupancy_guard
BEFORE INSERT ON meter_readings
FOR EACH ROW
BEGIN
    DECLARE occupancy_room BIGINT UNSIGNED DEFAULT NULL;
    DECLARE occupancy_move_in DATE DEFAULT NULL;
    DECLARE occupancy_move_out DATE DEFAULT NULL;
    DECLARE occupancy_opening_water DECIMAL(14,2) DEFAULT NULL;
    DECLARE occupancy_opening_electric DECIMAL(14,2) DEFAULT NULL;
    DECLARE expected_previous DECIMAL(14,2) DEFAULT NULL;
    DECLARE prior_rows INT UNSIGNED DEFAULT 0;
    DECLARE overlapping_occupancies INT UNSIGNED DEFAULT 0;

    IF NEW.occupancy_id IS NULL THEN
        SELECT COUNT(*) INTO overlapping_occupancies
          FROM occupancies
         WHERE room_id = NEW.room_id
           AND move_in_date <= LAST_DAY(NEW.period)
           AND (move_out_date IS NULL OR move_out_date >= NEW.period);
        IF overlapping_occupancies <> 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'An occupied-room meter reading requires occupancy_id';
        END IF;
    ELSE
        SELECT room_id, move_in_date, move_out_date,
               opening_water_reading, opening_electric_reading
          INTO occupancy_room, occupancy_move_in, occupancy_move_out,
               occupancy_opening_water, occupancy_opening_electric
          FROM occupancies
         WHERE id = NEW.occupancy_id
         LIMIT 1
         FOR SHARE;
        IF occupancy_opening_water IS NULL OR occupancy_opening_electric IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Complete both occupancy opening readings before recording meters';
        END IF;
        IF NOT (occupancy_room <=> NEW.room_id)
            OR occupancy_move_in > LAST_DAY(NEW.period)
            OR (occupancy_move_out IS NOT NULL
                AND occupancy_move_out < NEW.period) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Meter reading must belong to its occupancy period and room';
        END IF;
        IF DATE_FORMAT(occupancy_move_in, '%Y-%m-01') = NEW.period THEN
            SET expected_previous = IF(
                NEW.meter_type = 'water',
                occupancy_opening_water,
                occupancy_opening_electric
            );
            IF expected_previous IS NULL
                OR NOT (expected_previous <=> NEW.previous_reading) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'First-period meter baseline must match the occupancy opening reading';
            END IF;
        ELSE
            SELECT COUNT(*), MAX(current_reading)
              INTO prior_rows, expected_previous
              FROM meter_readings
             WHERE room_id = NEW.room_id
               AND occupancy_id = NEW.occupancy_id
               AND meter_type = NEW.meter_type
               AND period = DATE_SUB(NEW.period, INTERVAL 1 MONTH);
            IF prior_rows <> 1
                OR NOT (expected_previous <=> NEW.previous_reading) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Meter history must be contiguous within one occupancy';
            END IF;
        END IF;
    END IF;
END$$

DELIMITER ;

DROP TRIGGER IF EXISTS trg_meter_readings_occupancy_guard_update;

DELIMITER $$

CREATE TRIGGER trg_meter_readings_occupancy_guard_update
BEFORE UPDATE ON meter_readings
FOR EACH ROW
BEGIN
    DECLARE occupancy_room BIGINT UNSIGNED DEFAULT NULL;
    DECLARE occupancy_move_in DATE DEFAULT NULL;
    DECLARE occupancy_move_out DATE DEFAULT NULL;
    DECLARE occupancy_opening_water DECIMAL(14,2) DEFAULT NULL;
    DECLARE occupancy_opening_electric DECIMAL(14,2) DEFAULT NULL;
    DECLARE expected_previous DECIMAL(14,2) DEFAULT NULL;
    DECLARE prior_rows INT UNSIGNED DEFAULT 0;
    DECLARE later_rows INT UNSIGNED DEFAULT 0;
    DECLARE overlapping_occupancies INT UNSIGNED DEFAULT 0;

    IF NOT (OLD.room_id <=> NEW.room_id)
        OR NOT (OLD.meter_type <=> NEW.meter_type)
        OR NOT (OLD.period <=> NEW.period)
        OR NOT (OLD.previous_reading <=> NEW.previous_reading)
        OR NOT (OLD.created_at <=> NEW.created_at)
        OR (OLD.occupancy_id IS NOT NULL
            AND NOT (OLD.occupancy_id <=> NEW.occupancy_id)) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Meter identity and previous reading are immutable';
    END IF;

    IF NEW.occupancy_id IS NULL THEN
        SELECT COUNT(*) INTO overlapping_occupancies
          FROM occupancies
         WHERE room_id = NEW.room_id
           AND move_in_date <= LAST_DAY(NEW.period)
           AND (move_out_date IS NULL OR move_out_date >= NEW.period);
        IF overlapping_occupancies <> 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'An occupied-room meter reading requires occupancy_id';
        END IF;
    ELSE
        SELECT room_id, move_in_date, move_out_date,
               opening_water_reading, opening_electric_reading
          INTO occupancy_room, occupancy_move_in, occupancy_move_out,
               occupancy_opening_water, occupancy_opening_electric
          FROM occupancies
         WHERE id = NEW.occupancy_id
         LIMIT 1
         FOR SHARE;
        IF occupancy_opening_water IS NULL OR occupancy_opening_electric IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Complete both occupancy opening readings before recording meters';
        END IF;
        IF NOT (occupancy_room <=> NEW.room_id)
            OR occupancy_move_in > LAST_DAY(NEW.period)
            OR (occupancy_move_out IS NOT NULL
                AND occupancy_move_out < NEW.period) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Meter reading must belong to its occupancy period and room';
        END IF;
        IF DATE_FORMAT(occupancy_move_in, '%Y-%m-01') = NEW.period THEN
            SET expected_previous = IF(
                NEW.meter_type = 'water',
                occupancy_opening_water,
                occupancy_opening_electric
            );
            IF expected_previous IS NULL
                OR NOT (expected_previous <=> NEW.previous_reading) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'First-period meter baseline must match the occupancy opening reading';
            END IF;
        ELSE
            SELECT COUNT(*), MAX(current_reading)
              INTO prior_rows, expected_previous
              FROM meter_readings
             WHERE room_id = NEW.room_id
               AND occupancy_id = NEW.occupancy_id
               AND meter_type = NEW.meter_type
               AND period = DATE_SUB(NEW.period, INTERVAL 1 MONTH);
            IF prior_rows <> 1
                OR NOT (expected_previous <=> NEW.previous_reading) THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Meter history must be contiguous within one occupancy';
            END IF;
        END IF;
        IF NOT (OLD.current_reading <=> NEW.current_reading) THEN
            SELECT COUNT(*) INTO later_rows
              FROM meter_readings
             WHERE room_id = NEW.room_id
               AND meter_type = NEW.meter_type
               AND period > NEW.period;
            IF later_rows <> 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A meter reading referenced by a later period is immutable';
            END IF;
        END IF;
    END IF;
END$$

DELIMITER ;

DROP TRIGGER IF EXISTS trg_bills_relationship_guard;

DELIMITER $$

CREATE TRIGGER trg_bills_relationship_guard
BEFORE INSERT ON bills
FOR EACH ROW
BEGIN
    DECLARE occupancy_resident BIGINT UNSIGNED DEFAULT NULL;
    DECLARE occupancy_room BIGINT UNSIGNED DEFAULT NULL;
    DECLARE occupancy_rent DECIMAL(12,2) DEFAULT NULL;
    DECLARE occupancy_move_in DATE DEFAULT NULL;
    DECLARE occupancy_move_out DATE DEFAULT NULL;
    DECLARE occupancy_opening_water DECIMAL(14,2) DEFAULT NULL;
    DECLARE occupancy_opening_electric DECIMAL(14,2) DEFAULT NULL;
    DECLARE water_previous DECIMAL(14,2) DEFAULT NULL;
    DECLARE water_current DECIMAL(14,2) DEFAULT NULL;
    DECLARE water_units DECIMAL(14,2) DEFAULT NULL;
    DECLARE electric_previous DECIMAL(14,2) DEFAULT NULL;
    DECLARE electric_current DECIMAL(14,2) DEFAULT NULL;
    DECLARE electric_units DECIMAL(14,2) DEFAULT NULL;

    SELECT resident_id, room_id, monthly_rent, move_in_date, move_out_date,
           opening_water_reading, opening_electric_reading
      INTO occupancy_resident, occupancy_room, occupancy_rent,
           occupancy_move_in, occupancy_move_out,
           occupancy_opening_water, occupancy_opening_electric
      FROM occupancies
     WHERE id = NEW.occupancy_id
     LIMIT 1
     FOR SHARE;
    IF occupancy_opening_water IS NULL OR occupancy_opening_electric IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Complete both occupancy opening readings before issuing bills';
    END IF;
    SELECT previous_reading, current_reading, units_used
      INTO water_previous, water_current, water_units
      FROM meter_readings
     WHERE room_id = NEW.room_id
       AND occupancy_id = NEW.occupancy_id
       AND meter_type = 'water'
       AND period = NEW.period
     LIMIT 1
     FOR SHARE;
    SELECT previous_reading, current_reading, units_used
      INTO electric_previous, electric_current, electric_units
      FROM meter_readings
     WHERE room_id = NEW.room_id
       AND occupancy_id = NEW.occupancy_id
       AND meter_type = 'electric'
       AND period = NEW.period
     LIMIT 1
     FOR SHARE;

    IF NEW.status <> 'pending'
        OR NEW.paid_at IS NOT NULL
        OR NOT (occupancy_resident <=> NEW.resident_id)
        OR NOT (occupancy_room <=> NEW.room_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Bill must start pending and match its occupancy';
    END IF;
    IF NOT (occupancy_rent <=> NEW.rent_amount)
        OR NEW.period < DATE_FORMAT(occupancy_move_in, '%Y-%m-01')
        OR NEW.period > DATE_FORMAT(COALESCE(occupancy_move_out, '9999-12-31'), '%Y-%m-01')
        OR NOT (water_previous <=> NEW.water_previous)
        OR NOT (water_current <=> NEW.water_current)
        OR NOT (water_units <=> NEW.water_units)
        OR NOT (electric_previous <=> NEW.electric_previous)
        OR NOT (electric_current <=> NEW.electric_current)
        OR NOT (electric_units <=> NEW.electric_units) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Bill rent and meter snapshots must match its occupancy period';
    END IF;
END$$

DELIMITER ;

-- Verify all new guards before publishing the readiness marker.
SET @dormitory_015_trigger_postcondition := (
    SELECT COUNT(*) FROM information_schema.triggers
     WHERE trigger_schema = DATABASE() AND action_timing = 'BEFORE'
       AND ((trigger_name = 'trg_occupancies_identity_immutable'
             AND event_object_table = 'occupancies' AND event_manipulation = 'UPDATE')
         OR (trigger_name = 'trg_meter_readings_occupancy_guard'
             AND event_object_table = 'meter_readings' AND event_manipulation = 'INSERT')
         OR (trigger_name = 'trg_meter_readings_occupancy_guard_update'
             AND event_object_table = 'meter_readings' AND event_manipulation = 'UPDATE')
         OR (trigger_name = 'trg_bills_relationship_guard'
             AND event_object_table = 'bills' AND event_manipulation = 'INSERT'))
);
SET @dormitory_015_trigger_postcondition_sql := IF(
    @dormitory_015_trigger_postcondition = 4,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_015_TRIGGER_POSTCONDITION_FAILED'
);
PREPARE dormitory_015_trigger_postcondition_statement FROM @dormitory_015_trigger_postcondition_sql;
EXECUTE dormitory_015_trigger_postcondition_statement;
DEALLOCATE PREPARE dormitory_015_trigger_postcondition_statement;

SET @dormitory_015_replace_check_sql := IF(
    EXISTS(SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_schema = DATABASE() AND table_name = 'occupancies'
              AND constraint_name = 'chk_occupancies_opening_readings'
              AND constraint_type = 'CHECK'),
    'ALTER TABLE occupancies DROP CHECK chk_occupancies_opening_readings, ADD CONSTRAINT chk_occupancies_opening_readings_v2 CHECK (((opening_water_reading IS NULL) AND (opening_electric_reading IS NULL)) OR ((opening_water_reading IS NOT NULL) AND (opening_electric_reading IS NOT NULL) AND (opening_water_reading >= 0) AND (opening_water_reading <= 9999999.00) AND (opening_electric_reading >= 0) AND (opening_electric_reading <= 9999999.00)))',
    'ALTER TABLE occupancies ADD CONSTRAINT chk_occupancies_opening_readings_v2 CHECK (((opening_water_reading IS NULL) AND (opening_electric_reading IS NULL)) OR ((opening_water_reading IS NOT NULL) AND (opening_electric_reading IS NOT NULL) AND (opening_water_reading >= 0) AND (opening_water_reading <= 9999999.00) AND (opening_electric_reading >= 0) AND (opening_electric_reading <= 9999999.00)))'
);
PREPARE dormitory_015_replace_check FROM @dormitory_015_replace_check_sql;
EXECUTE dormitory_015_replace_check;
DEALLOCATE PREPARE dormitory_015_replace_check;

SELECT constraint_name, enforced FROM information_schema.table_constraints
 WHERE constraint_schema = DATABASE() AND table_name = 'occupancies'
   AND constraint_name = 'chk_occupancies_opening_readings_v2';
