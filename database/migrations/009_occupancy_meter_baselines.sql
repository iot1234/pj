-- Bind monthly meter readings to the exact occupancy and preserve explicit
-- opening readings for every new check-in.
--
-- Stop web/worker writes, back up MySQL, run as schema owner after migration
-- 008, then deploy the matching application code. Safe to rerun after success.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

SET @dormitory_009_required_tables := (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN (
           'bookings', 'occupancies', 'meter_readings', 'residents', 'rooms'
       )
       AND table_type = 'BASE TABLE'
);
SET @dormitory_009_guard_sql := IF(
    @dormitory_009_required_tables = 5,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_009_IMPORT_PRIOR_SCHEMA_FIRST'
);
PREPARE dormitory_009_guard FROM @dormitory_009_guard_sql;
EXECUTE dormitory_009_guard;
DEALLOCATE PREPARE dormitory_009_guard;

SET @dormitory_009_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'occupancies'
           AND column_name = 'opening_water_reading'
    ),
    'SELECT 1',
    'ALTER TABLE occupancies ADD COLUMN opening_water_reading DECIMAL(14,2) NULL AFTER move_out_date'
);
PREPARE dormitory_009_statement FROM @dormitory_009_sql;
EXECUTE dormitory_009_statement;
DEALLOCATE PREPARE dormitory_009_statement;

SET @dormitory_009_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'occupancies'
           AND column_name = 'opening_electric_reading'
    ),
    'SELECT 1',
    'ALTER TABLE occupancies ADD COLUMN opening_electric_reading DECIMAL(14,2) NULL AFTER opening_water_reading'
);
PREPARE dormitory_009_statement FROM @dormitory_009_sql;
EXECUTE dormitory_009_statement;
DEALLOCATE PREPARE dormitory_009_statement;

SET @dormitory_009_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'meter_readings'
           AND column_name = 'occupancy_id'
    ),
    'SELECT 1',
    'ALTER TABLE meter_readings ADD COLUMN occupancy_id BIGINT UNSIGNED NULL AFTER room_id'
);
PREPARE dormitory_009_statement FROM @dormitory_009_sql;
EXECUTE dormitory_009_statement;
DEALLOCATE PREPARE dormitory_009_statement;

-- Backfill only unambiguous historical rows. Ambiguous legacy periods remain
-- NULL and are blocked from new billing until an operator repairs them.
UPDATE meter_readings m
JOIN occupancies o
  ON o.room_id = m.room_id
 AND o.move_in_date <= LAST_DAY(m.period)
 AND (o.move_out_date IS NULL OR o.move_out_date >= m.period)
SET m.occupancy_id = o.id
WHERE m.occupancy_id IS NULL
  AND (
      SELECT COUNT(*)
        FROM occupancies candidate
       WHERE candidate.room_id = m.room_id
         AND candidate.move_in_date <= LAST_DAY(m.period)
         AND (candidate.move_out_date IS NULL
              OR candidate.move_out_date >= m.period)
  ) = 1;

-- Preserve the ledger's actual first-period baselines for legacy
-- occupancies. Do not invent a value when either meter is missing.
UPDATE occupancies o
JOIN meter_readings water
  ON water.occupancy_id = o.id
 AND water.meter_type = 'water'
 AND water.period = DATE_FORMAT(o.move_in_date, '%Y-%m-01')
JOIN meter_readings electric
  ON electric.occupancy_id = o.id
 AND electric.meter_type = 'electric'
 AND electric.period = DATE_FORMAT(o.move_in_date, '%Y-%m-01')
SET o.opening_water_reading = water.previous_reading,
    o.opening_electric_reading = electric.previous_reading
WHERE o.opening_water_reading IS NULL
  AND o.opening_electric_reading IS NULL;

-- A legacy occupancy without either opening reading may remain pending only
-- when no meter or bill history exists for it or its room during its occupancy.
-- The application and migration 015 block financial writes until an admin
-- supplies both actual move-in readings. Never invent baselines. A partial
-- pair, or missing readings with historical evidence, still requires repair
-- from the source ledger while all application writes remain stopped.
SET @dormitory_009_active_opening_gaps := (
    SELECT COUNT(*)
      FROM occupancies o
     WHERE (o.opening_water_reading IS NULL) <> (o.opening_electric_reading IS NULL)
        OR (
            o.opening_water_reading IS NULL
            AND o.opening_electric_reading IS NULL
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
SET @dormitory_009_opening_guard_sql := IF(
    @dormitory_009_active_opening_gaps = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_009_REPAIR_ACTIVE_OPENING_READINGS_BEFORE_RERUN'
);
PREPARE dormitory_009_opening_guard FROM @dormitory_009_opening_guard_sql;
EXECUTE dormitory_009_opening_guard;
DEALLOCATE PREPARE dormitory_009_opening_guard;

-- Monthly rent and meters are not prorated. Two occupancies of the same room
-- or resident must therefore never cover the same calendar month, even if
-- their actual move-out/move-in days do not overlap.
SET @dormitory_009_occupancy_period_overlaps := (
    SELECT COUNT(*)
      FROM occupancies first_row
      JOIN occupancies second_row
        ON second_row.id > first_row.id
       AND (
           second_row.room_id = first_row.room_id
           OR second_row.resident_id = first_row.resident_id
       )
     WHERE DATE_FORMAT(first_row.move_in_date, '%Y-%m-01')
               <= DATE_FORMAT(COALESCE(second_row.move_out_date, '9999-12-31'), '%Y-%m-01')
       AND DATE_FORMAT(second_row.move_in_date, '%Y-%m-01')
               <= DATE_FORMAT(COALESCE(first_row.move_out_date, '9999-12-31'), '%Y-%m-01')
);
SET @dormitory_009_occupancy_overlap_guard_sql := IF(
    @dormitory_009_occupancy_period_overlaps = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_009_REPAIR_OCCUPANCY_PERIOD_OVERLAPS_BEFORE_RERUN'
);
PREPARE dormitory_009_occupancy_overlap_guard
    FROM @dormitory_009_occupancy_overlap_guard_sql;
EXECUTE dormitory_009_occupancy_overlap_guard;
DEALLOCATE PREPARE dormitory_009_occupancy_overlap_guard;

-- Validate the bidirectional lifecycle state used by login, reissue,
-- billing, and move-out. Also reject moved-in bookings without an occupancy
-- and occupancy evidence whose room, resident, or rent snapshot differs from
-- the immutable booking.
SET @dormitory_009_invalid_resident_occupancy_states := (
    SELECT COUNT(*)
      FROM (
          SELECT CONCAT('resident:', resident_row.id) AS issue_key
            FROM residents resident_row
            LEFT JOIN occupancies active_occupancy
              ON active_occupancy.resident_id = resident_row.id
             AND active_occupancy.status = 'active'
            LEFT JOIN rooms active_room
              ON active_room.id = active_occupancy.room_id
           WHERE resident_row.active = 1
           GROUP BY resident_row.id
          HAVING COUNT(active_occupancy.id) <> 1
              OR SUM(
                  CASE
                      WHEN active_room.id IS NOT NULL
                       AND active_room.deleted_at IS NULL THEN 1
                      ELSE 0
                  END
              ) <> 1

          UNION ALL

          SELECT CONCAT('occupancy:', occupancy_row.id) AS issue_key
            FROM occupancies occupancy_row
            JOIN residents linked_resident
              ON linked_resident.id = occupancy_row.resident_id
            JOIN rooms linked_room
              ON linked_room.id = occupancy_row.room_id
            JOIN bookings linked_booking
              ON linked_booking.id = occupancy_row.booking_id
           WHERE (
               occupancy_row.status = 'active'
               AND (
                   linked_resident.active <> 1
                   OR linked_room.deleted_at IS NOT NULL
               )
           )
              OR linked_booking.status <> 'moved_in'
              OR NOT (linked_booking.resident_id <=> occupancy_row.resident_id)
              OR NOT (linked_booking.room_id <=> occupancy_row.room_id)
              OR NOT (linked_booking.booked_monthly_rent <=> occupancy_row.monthly_rent)

          UNION ALL

          SELECT CONCAT('booking:', moved_booking.id) AS issue_key
            FROM bookings moved_booking
            LEFT JOIN occupancies moved_occupancy
              ON moved_occupancy.booking_id = moved_booking.id
           WHERE moved_booking.status = 'moved_in'
             AND (
                 moved_occupancy.id IS NULL
                 OR NOT (moved_booking.resident_id <=> moved_occupancy.resident_id)
                 OR NOT (moved_booking.room_id <=> moved_occupancy.room_id)
                 OR NOT (moved_booking.booked_monthly_rent <=> moved_occupancy.monthly_rent)
             )
      ) invalid_state_rows
);
SET @dormitory_009_resident_state_guard_sql := IF(
    @dormitory_009_invalid_resident_occupancy_states = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_009_REPAIR_RESIDENT_OCCUPANCY_STATES_BEFORE_RERUN'
);
PREPARE dormitory_009_resident_state_guard
    FROM @dormitory_009_resident_state_guard_sql;
EXECUTE dormitory_009_resident_state_guard;
DEALLOCATE PREPARE dormitory_009_resident_state_guard;

-- Existing rows predate the relationship triggers installed by migration 002.
-- Verify the complete financial chain before relying on paid bills for
-- move-out or delivering a notification to a resident.
SET @dormitory_009_invalid_financial_relationships := (
    SELECT COUNT(*)
      FROM (
          SELECT CONCAT('bill:', bill_row.id) AS issue_key
            FROM bills bill_row
            JOIN occupancies bill_occupancy
              ON bill_occupancy.id = bill_row.occupancy_id
            LEFT JOIN meter_readings bill_water
              ON bill_water.room_id = bill_row.room_id
             AND bill_water.occupancy_id = bill_row.occupancy_id
             AND bill_water.meter_type = 'water'
             AND bill_water.period = bill_row.period
            LEFT JOIN meter_readings bill_electric
              ON bill_electric.room_id = bill_row.room_id
             AND bill_electric.occupancy_id = bill_row.occupancy_id
             AND bill_electric.meter_type = 'electric'
             AND bill_electric.period = bill_row.period
           WHERE NOT (bill_row.resident_id <=> bill_occupancy.resident_id)
              OR NOT (bill_row.room_id <=> bill_occupancy.room_id)
              OR NOT (bill_row.rent_amount <=> bill_occupancy.monthly_rent)
              OR bill_row.period < DATE_FORMAT(bill_occupancy.move_in_date, '%Y-%m-01')
              OR bill_row.period > DATE_FORMAT(
                  COALESCE(bill_occupancy.move_out_date, '9999-12-31'),
                  '%Y-%m-01'
              )
              OR bill_water.id IS NULL
              OR NOT (bill_row.water_previous <=> bill_water.previous_reading)
              OR NOT (bill_row.water_current <=> bill_water.current_reading)
              OR NOT (bill_row.water_units <=> bill_water.units_used)
              OR bill_electric.id IS NULL
              OR NOT (bill_row.electric_previous <=> bill_electric.previous_reading)
              OR NOT (bill_row.electric_current <=> bill_electric.current_reading)
              OR NOT (bill_row.electric_units <=> bill_electric.units_used)

          UNION ALL

          SELECT CONCAT('bill-items:', item_bill.id) AS issue_key
            FROM bills item_bill
            LEFT JOIN bill_items item_row ON item_row.bill_id = item_bill.id
           GROUP BY item_bill.id, item_bill.rent_amount,
                    item_bill.water_units, item_bill.water_rate, item_bill.water_amount,
                    item_bill.electric_units, item_bill.electric_rate, item_bill.electric_amount,
                    item_bill.other_description, item_bill.other_amount
          HAVING SUM(CASE
                     WHEN item_row.item_type = 'rent'
                      AND item_row.quantity = 1.00
                      AND item_row.unit_price = item_bill.rent_amount
                      AND item_row.amount = item_bill.rent_amount THEN 1
                     ELSE 0
                 END) <> 1
              OR SUM(CASE
                     WHEN item_row.item_type = 'water'
                      AND item_row.quantity = item_bill.water_units
                      AND item_row.unit_price = item_bill.water_rate
                      AND item_row.amount = item_bill.water_amount THEN 1
                     ELSE 0
                 END) <> 1
              OR SUM(CASE
                     WHEN item_row.item_type = 'electric'
                      AND item_row.quantity = item_bill.electric_units
                      AND item_row.unit_price = item_bill.electric_rate
                      AND item_row.amount = item_bill.electric_amount THEN 1
                     ELSE 0
                 END) <> 1
              OR (
                  item_bill.other_amount = 0
                  AND SUM(CASE WHEN item_row.item_type = 'other' THEN 1 ELSE 0 END) <> 0
              )
              OR (
                  item_bill.other_amount > 0
                  AND SUM(CASE
                          WHEN item_row.item_type = 'other'
                           AND item_row.quantity = 1.00
                           AND item_row.unit_price = item_bill.other_amount
                           AND item_row.amount = item_bill.other_amount
                           AND item_row.description = item_bill.other_description THEN 1
                          ELSE 0
                      END) <> 1
              )

          UNION ALL

          SELECT CONCAT('payment:', payment_row.id) AS issue_key
            FROM payments payment_row
            JOIN bills payment_bill ON payment_bill.id = payment_row.bill_id
           WHERE NOT (payment_row.resident_id <=> payment_bill.resident_id)
              OR NOT (payment_row.amount <=> payment_bill.total_amount)
              OR (payment_row.status = 'pending' AND payment_bill.status <> 'pending')
              OR (payment_row.status = 'verified' AND payment_bill.status <> 'paid')

          UNION ALL

          SELECT CONCAT('bill-payment:', paid_bill.id) AS issue_key
            FROM bills paid_bill
            LEFT JOIN payments paid_payment ON paid_payment.bill_id = paid_bill.id
           GROUP BY paid_bill.id, paid_bill.status,
                    paid_bill.resident_id, paid_bill.total_amount
          HAVING (
              paid_bill.status = 'paid'
              AND SUM(CASE
                      WHEN paid_payment.status = 'verified'
                       AND paid_payment.resident_id = paid_bill.resident_id
                       AND paid_payment.amount = paid_bill.total_amount THEN 1
                      ELSE 0
                  END) <> 1
          ) OR (
              paid_bill.status = 'pending'
              AND SUM(CASE WHEN paid_payment.status = 'verified' THEN 1 ELSE 0 END) <> 0
          )

          UNION ALL

          SELECT CONCAT('notification:', notification_row.id) AS issue_key
            FROM notification_outbox notification_row
            JOIN bills notification_bill ON notification_bill.id = notification_row.bill_id
           WHERE NOT (notification_row.resident_id <=> notification_bill.resident_id)
      ) invalid_financial_rows
);
SET @dormitory_009_financial_guard_sql := IF(
    @dormitory_009_invalid_financial_relationships = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_009_REPAIR_FINANCIAL_RELATIONSHIPS_BEFORE_RERUN'
);
PREPARE dormitory_009_financial_guard
    FROM @dormitory_009_financial_guard_sql;
EXECUTE dormitory_009_financial_guard;
DEALLOCATE PREPARE dormitory_009_financial_guard;

-- Do not install immutable/future-write guards over legacy rows whose
-- occupancy association or month-to-month chain is already inconsistent.
-- Repair the exact rows from the source meter ledger while writes are still
-- stopped, then rerun this migration.
SET @dormitory_009_invalid_meter_links := (
    SELECT COUNT(*)
      FROM meter_readings m
      LEFT JOIN occupancies linked ON linked.id = m.occupancy_id
     WHERE (
         m.occupancy_id IS NULL
         AND EXISTS (
             SELECT 1
               FROM occupancies overlap_row
              WHERE overlap_row.room_id = m.room_id
                AND overlap_row.move_in_date <= LAST_DAY(m.period)
                AND (
                    overlap_row.move_out_date IS NULL
                    OR overlap_row.move_out_date >= m.period
                )
         )
     ) OR (
         m.occupancy_id IS NOT NULL
         AND (
             linked.id IS NULL
             OR linked.room_id <> m.room_id
             OR linked.move_in_date > LAST_DAY(m.period)
             OR (
                 linked.move_out_date IS NOT NULL
                 AND linked.move_out_date < m.period
             )
         )
     )
);
SET @dormitory_009_link_guard_sql := IF(
    @dormitory_009_invalid_meter_links = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_009_REPAIR_METER_OCCUPANCY_LINKS_BEFORE_RERUN'
);
PREPARE dormitory_009_link_guard FROM @dormitory_009_link_guard_sql;
EXECUTE dormitory_009_link_guard;
DEALLOCATE PREPARE dormitory_009_link_guard;

SET @dormitory_009_invalid_meter_chains := (
    SELECT COUNT(*)
      FROM meter_readings current_row
      JOIN occupancies occupancy_row
        ON occupancy_row.id = current_row.occupancy_id
      LEFT JOIN meter_readings prior_row
        ON prior_row.occupancy_id = current_row.occupancy_id
       AND prior_row.room_id = current_row.room_id
       AND prior_row.meter_type = current_row.meter_type
       AND prior_row.period = DATE_SUB(current_row.period, INTERVAL 1 MONTH)
     WHERE (
         current_row.period = DATE_FORMAT(occupancy_row.move_in_date, '%Y-%m-01')
         AND NOT (
             current_row.previous_reading <=> IF(
                 current_row.meter_type = 'water',
                 occupancy_row.opening_water_reading,
                 occupancy_row.opening_electric_reading
             )
         )
     ) OR (
         current_row.period > DATE_FORMAT(occupancy_row.move_in_date, '%Y-%m-01')
         AND (
             prior_row.id IS NULL
             OR NOT (current_row.previous_reading <=> prior_row.current_reading)
         )
     )
);
SET @dormitory_009_chain_guard_sql := IF(
    @dormitory_009_invalid_meter_chains = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_009_REPAIR_METER_CHAIN_BEFORE_RERUN'
);
PREPARE dormitory_009_chain_guard FROM @dormitory_009_chain_guard_sql;
EXECUTE dormitory_009_chain_guard;
DEALLOCATE PREPARE dormitory_009_chain_guard;

SET @dormitory_009_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'meter_readings'
           AND index_name = 'idx_meter_readings_occupancy_period'
    ),
    'SELECT 1',
    'ALTER TABLE meter_readings ADD KEY idx_meter_readings_occupancy_period (occupancy_id, period)'
);
PREPARE dormitory_009_statement FROM @dormitory_009_sql;
EXECUTE dormitory_009_statement;
DEALLOCATE PREPARE dormitory_009_statement;

SET @dormitory_009_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = 'meter_readings'
           AND constraint_name = 'fk_meter_readings_occupancy'
           AND constraint_type = 'FOREIGN KEY'
    ),
    'SELECT 1',
    'ALTER TABLE meter_readings ADD CONSTRAINT fk_meter_readings_occupancy FOREIGN KEY (occupancy_id) REFERENCES occupancies(id) ON UPDATE RESTRICT ON DELETE RESTRICT'
);
PREPARE dormitory_009_statement FROM @dormitory_009_sql;
EXECUTE dormitory_009_statement;
DEALLOCATE PREPARE dormitory_009_statement;

SET @dormitory_009_sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = 'occupancies'
           AND constraint_name = 'chk_occupancies_opening_readings'
           AND constraint_type = 'CHECK'
    ),
    'SELECT 1',
    'ALTER TABLE occupancies ADD CONSTRAINT chk_occupancies_opening_readings CHECK (((opening_water_reading IS NULL) AND (opening_electric_reading IS NULL)) OR ((opening_water_reading IS NOT NULL) AND (opening_electric_reading IS NOT NULL) AND (opening_water_reading >= 0) AND (opening_water_reading <= 9999999.00) AND (opening_electric_reading >= 0) AND (opening_electric_reading <= 9999999.00)))'
);
PREPARE dormitory_009_statement FROM @dormitory_009_sql;
EXECUTE dormitory_009_statement;
DEALLOCATE PREPARE dormitory_009_statement;

DROP TRIGGER IF EXISTS trg_occupancies_identity_immutable;
DROP TRIGGER IF EXISTS trg_meter_readings_occupancy_guard;
DROP TRIGGER IF EXISTS trg_meter_readings_occupancy_guard_update;
DROP TRIGGER IF EXISTS trg_bookings_insert_guard;
DROP TRIGGER IF EXISTS trg_occupancies_relationship_guard;
DROP TRIGGER IF EXISTS trg_bills_relationship_guard;

DELIMITER $$

CREATE TRIGGER trg_bookings_insert_guard
BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    DECLARE room_deleted DATETIME(6) DEFAULT NULL;
    DECLARE active_occupancy_count INT UNSIGNED DEFAULT 0;
    DECLARE active_phone_occupancy_count INT UNSIGNED DEFAULT 0;

    SELECT deleted_at
      INTO room_deleted
      FROM rooms
     WHERE id = NEW.room_id
     LIMIT 1
     FOR UPDATE;
    SELECT COUNT(*)
      INTO active_occupancy_count
      FROM occupancies
     WHERE room_id = NEW.room_id
       AND status = 'active'
     FOR SHARE;
    SELECT COUNT(*)
      INTO active_phone_occupancy_count
      FROM residents resident_row
      JOIN occupancies active_occupancy
        ON active_occupancy.resident_id = resident_row.id
       AND active_occupancy.status = 'active'
     WHERE resident_row.phone_norm = NEW.phone_norm
     FOR SHARE;

    IF NEW.status <> 'pending'
        OR room_deleted IS NOT NULL
        OR active_occupancy_count <> 0
        OR active_phone_occupancy_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A booking must start pending for an available room and resident';
    END IF;
END$$

CREATE TRIGGER trg_occupancies_relationship_guard
BEFORE INSERT ON occupancies
FOR EACH ROW
BEGIN
    DECLARE booking_status VARCHAR(16) DEFAULT NULL;
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

CREATE TRIGGER trg_occupancies_identity_immutable
BEFORE UPDATE ON occupancies
FOR EACH ROW
BEGIN
    IF NOT (OLD.resident_id <=> NEW.resident_id)
        OR NOT (OLD.room_id <=> NEW.room_id)
        OR NOT (OLD.booking_id <=> NEW.booking_id)
        OR NOT (OLD.monthly_rent <=> NEW.monthly_rent)
        OR NOT (OLD.move_in_date <=> NEW.move_in_date)
        OR NOT (OLD.opening_water_reading <=> NEW.opening_water_reading)
        OR NOT (OLD.opening_electric_reading <=> NEW.opening_electric_reading)
        OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Occupancy identity and rent snapshot are immutable';
    END IF;
    IF OLD.status = 'ended'
        AND (NEW.status <> 'ended'
            OR NOT (OLD.move_out_date <=> NEW.move_out_date)) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'An ended occupancy is immutable';
    END IF;
    IF OLD.status = 'active' AND NEW.status NOT IN ('active', 'ended') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid occupancy state transition';
    END IF;
END$$

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
          FROM occupancies WHERE id = NEW.occupancy_id LIMIT 1;
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
          FROM occupancies WHERE id = NEW.occupancy_id LIMIT 1;
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

CREATE TRIGGER trg_bills_relationship_guard
BEFORE INSERT ON bills
FOR EACH ROW
BEGIN
    DECLARE occupancy_resident BIGINT UNSIGNED DEFAULT NULL;
    DECLARE occupancy_room BIGINT UNSIGNED DEFAULT NULL;
    DECLARE occupancy_rent DECIMAL(12,2) DEFAULT NULL;
    DECLARE occupancy_move_in DATE DEFAULT NULL;
    DECLARE occupancy_move_out DATE DEFAULT NULL;
    DECLARE water_previous DECIMAL(14,2) DEFAULT NULL;
    DECLARE water_current DECIMAL(14,2) DEFAULT NULL;
    DECLARE water_units DECIMAL(14,2) DEFAULT NULL;
    DECLARE electric_previous DECIMAL(14,2) DEFAULT NULL;
    DECLARE electric_current DECIMAL(14,2) DEFAULT NULL;
    DECLARE electric_units DECIMAL(14,2) DEFAULT NULL;

    SELECT resident_id, room_id, monthly_rent, move_in_date, move_out_date
      INTO occupancy_resident, occupancy_room, occupancy_rent,
           occupancy_move_in, occupancy_move_out
      FROM occupancies
     WHERE id = NEW.occupancy_id
     LIMIT 1
     FOR SHARE;
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

SELECT table_name, column_name
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND (
       (table_name = 'occupancies'
        AND column_name IN ('opening_water_reading', 'opening_electric_reading'))
       OR (table_name = 'meter_readings' AND column_name = 'occupancy_id')
   )
 ORDER BY table_name, ordinal_position;
