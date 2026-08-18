-- Pin the collation of string local variables inside the relationship guards.
--
-- A stored-program variable declared without CHARACTER SET inherits the
-- DATABASE default collation, not the collation of the tables it is compared
-- against. database/install.sql creates the database with utf8mb4_unicode_ci,
-- but a managed host (Railway) or a container that provisions the database
-- from MYSQL_DATABASE creates it with the server default, utf8mb4_0900_ai_ci
-- on MySQL 8.0/8.4. trg_bill_items_insert_guard then compares
-- expected_other_description with bill_items.description using <=>, both
-- IMPLICIT and of different collations, and MySQL raises error 1267 for every
-- bill item insert, so issuing any bill fails.
--
-- Recreating the guards with an explicit collation makes them independent of
-- how the database itself was created. No table, column or guard logic changes.
-- Safe to rerun with a schema-owning/DBA account during maintenance.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- Refuse a schema that has not reached the current guard set.
SET @dormitory_013_prerequisite_triggers := (
    SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name IN (
           'trg_occupancies_relationship_guard',
           'trg_bill_items_insert_guard',
           'trg_payments_relationship_guard'
       )
);
SET @dormitory_013_prerequisite_sql := IF(
    @dormitory_013_prerequisite_triggers = 3,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_013_IMPORT_PRIOR_MIGRATIONS_FIRST'
);
PREPARE dormitory_013_prerequisite FROM @dormitory_013_prerequisite_sql;
EXECUTE dormitory_013_prerequisite;
DEALLOCATE PREPARE dormitory_013_prerequisite;

DROP TRIGGER IF EXISTS trg_occupancies_relationship_guard;

DELIMITER $$

CREATE TRIGGER trg_occupancies_relationship_guard
BEFORE INSERT ON occupancies
FOR EACH ROW
BEGIN
    DECLARE booking_status VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
    DECLARE booking_resident BIGINT UNSIGNED DEFAULT NULL;
    DECLARE booking_room BIGINT UNSIGNED DEFAULT NULL;
    DECLARE booking_rent DECIMAL(12,2) DEFAULT NULL;
    DECLARE resident_active TINYINT DEFAULT NULL;
    DECLARE room_deleted DATETIME(6) DEFAULT NULL;
    DECLARE overlapping_periods INT UNSIGNED DEFAULT 0;

    SELECT deleted_at
      INTO room_deleted
      FROM rooms
     WHERE id = NEW.room_id
     LIMIT 1
     FOR UPDATE;
    SELECT active
      INTO resident_active
      FROM residents
     WHERE id = NEW.resident_id
     LIMIT 1
     FOR UPDATE;
    SELECT status, resident_id, room_id, booked_monthly_rent
      INTO booking_status, booking_resident, booking_room, booking_rent
      FROM bookings
     WHERE id = NEW.booking_id
     LIMIT 1
     FOR SHARE;
    SELECT COUNT(*)
      INTO overlapping_periods
      FROM occupancies existing_occupancy
     WHERE (
            existing_occupancy.room_id = NEW.room_id
            OR existing_occupancy.resident_id = NEW.resident_id
       )
       AND DATE_FORMAT(existing_occupancy.move_in_date, '%Y-%m-01')
               <= DATE_FORMAT(COALESCE(NEW.move_out_date, '9999-12-31'), '%Y-%m-01')
       AND DATE_FORMAT(NEW.move_in_date, '%Y-%m-01')
               <= DATE_FORMAT(
                   COALESCE(existing_occupancy.move_out_date, '9999-12-31'),
                   '%Y-%m-01'
               )
     FOR SHARE;

    IF NEW.status <> 'active'
        OR NEW.move_out_date IS NOT NULL
        OR NEW.opening_water_reading IS NULL
        OR NEW.opening_electric_reading IS NULL
        OR room_deleted IS NOT NULL
        OR resident_active <> 1
        OR booking_status <> 'moved_in'
        OR NOT (booking_resident <=> NEW.resident_id)
        OR NOT (booking_room <=> NEW.room_id)
        OR NOT (booking_rent <=> NEW.monthly_rent)
        OR overlapping_periods <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Occupancy must start active and match its moved-in booking';
    END IF;
END$$

DELIMITER ;

DROP TRIGGER IF EXISTS trg_bill_items_insert_guard;

DELIMITER $$

CREATE TRIGGER trg_bill_items_insert_guard
BEFORE INSERT ON bill_items
FOR EACH ROW
BEGIN
    DECLARE expected_quantity DECIMAL(14,2) DEFAULT NULL;
    DECLARE expected_unit_price DECIMAL(14,2) DEFAULT NULL;
    DECLARE expected_amount DECIMAL(14,2) DEFAULT NULL;
    DECLARE expected_other_description VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

    SELECT CASE NEW.item_type
               WHEN 'rent' THEN 1.00
               WHEN 'water' THEN water_units
               WHEN 'electric' THEN electric_units
               WHEN 'other' THEN 1.00
           END,
           CASE NEW.item_type
               WHEN 'rent' THEN rent_amount
               WHEN 'water' THEN water_rate
               WHEN 'electric' THEN electric_rate
               WHEN 'other' THEN other_amount
           END,
           CASE NEW.item_type
               WHEN 'rent' THEN rent_amount
               WHEN 'water' THEN water_amount
               WHEN 'electric' THEN electric_amount
               WHEN 'other' THEN other_amount
           END,
           other_description
      INTO expected_quantity, expected_unit_price, expected_amount, expected_other_description
      FROM bills
     WHERE id = NEW.bill_id
     LIMIT 1;

    IF NOT (expected_quantity <=> NEW.quantity)
        OR NOT (expected_unit_price <=> NEW.unit_price)
        OR NOT (expected_amount <=> NEW.amount)
        OR (NEW.item_type = 'other'
            AND (expected_amount <= 0 OR NOT (expected_other_description <=> NEW.description))) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Bill item must match its issued bill snapshot';
    END IF;
END$$

DELIMITER ;

DROP TRIGGER IF EXISTS trg_payments_relationship_guard;

DELIMITER $$

CREATE TRIGGER trg_payments_relationship_guard
BEFORE INSERT ON payments
FOR EACH ROW
BEGIN
    DECLARE bill_resident BIGINT UNSIGNED DEFAULT NULL;
    DECLARE bill_total DECIMAL(14,2) DEFAULT NULL;
    DECLARE bill_status VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
    SELECT resident_id, total_amount, status
      INTO bill_resident, bill_total, bill_status
      FROM bills
     WHERE id = NEW.bill_id
     LIMIT 1;
    IF NOT (bill_resident <=> NEW.resident_id)
        OR NOT (bill_total <=> NEW.amount)
        OR bill_status <> 'pending'
        OR NEW.status <> 'pending' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Payment must start pending and match a pending bill';
    END IF;
END$$

DELIMITER ;

-- Every guard must be back in place with the same timing and event.
SET @dormitory_013_trigger_postcondition := (
    SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND action_timing = 'BEFORE'
       AND event_manipulation = 'INSERT'
       AND ((trigger_name = 'trg_occupancies_relationship_guard' AND event_object_table = 'occupancies')
         OR (trigger_name = 'trg_bill_items_insert_guard' AND event_object_table = 'bill_items')
         OR (trigger_name = 'trg_payments_relationship_guard' AND event_object_table = 'payments'))
);
SET @dormitory_013_trigger_postcondition_sql := IF(
    @dormitory_013_trigger_postcondition = 3,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_013_TRIGGER_POSTCONDITION_FAILED'
);
PREPARE dormitory_013_trigger_postcondition_statement
    FROM @dormitory_013_trigger_postcondition_sql;
EXECUTE dormitory_013_trigger_postcondition_statement;
DEALLOCATE PREPARE dormitory_013_trigger_postcondition_statement;

SELECT trigger_name, event_object_table, action_timing, event_manipulation
  FROM information_schema.triggers
 WHERE trigger_schema = DATABASE()
   AND trigger_name IN (
       'trg_occupancies_relationship_guard',
       'trg_bill_items_insert_guard',
       'trg_payments_relationship_guard'
   )
 ORDER BY trigger_name;
