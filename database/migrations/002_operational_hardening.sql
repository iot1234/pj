-- One-time upgrade for installations created before operational hardening 002.
-- Run with a schema-owning/DBA account. Back up and test on staging first.
-- Do not run this migration twice; the preflight checker reports the expected
-- columns/triggers after a successful upgrade.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

ALTER TABLE bookings
    ADD COLUMN booked_monthly_rent DECIMAL(12,2) NULL AFTER phone_norm;

UPDATE bookings b
JOIN rooms r ON r.id=b.room_id
LEFT JOIN occupancies o ON o.booking_id=b.id
SET b.booked_monthly_rent=COALESCE(o.monthly_rent,r.monthly_rent)
WHERE b.booked_monthly_rent IS NULL;

-- A moved-in booking is reconstructed from the occupancy's historical rent.
-- Pending/confirmed/cancelled legacy bookings have no historical rent snapshot,
-- so their current room rent is the only recoverable approximation.

ALTER TABLE bookings
    MODIFY booked_monthly_rent DECIMAL(12,2) NOT NULL,
    ADD CONSTRAINT chk_bookings_monthly_rent CHECK (booked_monthly_rent > 0 AND booked_monthly_rent <= 1000000);

ALTER TABLE bills
    ADD COLUMN resident_name_snapshot VARCHAR(150) NULL AFTER room_id,
    ADD COLUMN room_code_snapshot VARCHAR(32) NULL AFTER resident_name_snapshot;

UPDATE bills b
JOIN residents res ON res.id=b.resident_id
JOIN rooms r ON r.id=b.room_id
SET b.resident_name_snapshot=res.full_name,
    b.room_code_snapshot=r.room_code
WHERE b.resident_name_snapshot IS NULL OR b.room_code_snapshot IS NULL;

-- Legacy schemas did not retain the name/room-code text printed at issue time.
-- These two values are reconstructed from current master data; review or export
-- legally significant historical documents before this upgrade.

ALTER TABLE bills
    MODIFY resident_name_snapshot VARCHAR(150) NOT NULL,
    MODIFY room_code_snapshot VARCHAR(32) NOT NULL,
    ADD CONSTRAINT chk_bills_resident_name CHECK (CHAR_LENGTH(TRIM(resident_name_snapshot)) BETWEEN 1 AND 150),
    ADD CONSTRAINT chk_bills_room_code CHECK (CHAR_LENGTH(TRIM(room_code_snapshot)) BETWEEN 1 AND 32);

ALTER TABLE payments
    ADD COLUMN verification_lease_until DATETIME(6) NULL AFTER verified_at,
    ADD COLUMN verification_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER verification_lease_until,
    ADD COLUMN verification_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER verification_token,
    ADD CONSTRAINT chk_payments_verification_attempts CHECK (verification_attempts <= 100),
    ADD CONSTRAINT chk_payments_verification_lease CHECK (
        (verification_lease_until IS NULL AND verification_token IS NULL)
        OR (status = 'pending'
            AND verification_lease_until IS NOT NULL
            AND verification_token IS NOT NULL
            AND verification_token REGEXP '^[0-9a-f]{64}$')
    );

ALTER TABLE bill_items
    ADD UNIQUE KEY uq_bill_items_bill_type (bill_id, item_type);

DROP TRIGGER IF EXISTS trg_bills_snapshot_immutable;
DROP TRIGGER IF EXISTS trg_bills_no_delete;
DROP TRIGGER IF EXISTS trg_bookings_identity_immutable;
DROP TRIGGER IF EXISTS trg_occupancies_identity_immutable;
DROP TRIGGER IF EXISTS trg_bill_items_insert_guard;
DROP TRIGGER IF EXISTS trg_bill_items_no_update;
DROP TRIGGER IF EXISTS trg_bill_items_no_delete;
DROP TRIGGER IF EXISTS trg_audit_logs_no_update;
DROP TRIGGER IF EXISTS trg_audit_logs_no_delete;
DROP TRIGGER IF EXISTS trg_payments_final_immutable;
DROP TRIGGER IF EXISTS trg_payments_no_delete;
DROP TRIGGER IF EXISTS trg_bills_relationship_guard;
DROP TRIGGER IF EXISTS trg_payments_relationship_guard;
DROP TRIGGER IF EXISTS trg_notification_relationship_guard;
DROP TRIGGER IF EXISTS trg_notification_relationship_guard_update;

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
                OR NOT (OLD.resident_id <=> NEW.resident_id))) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Booking transition evidence is immutable once recorded';
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
        OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Occupancy identity and rent snapshot are immutable';
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

CREATE TRIGGER trg_bills_snapshot_immutable
BEFORE UPDATE ON bills
FOR EACH ROW
BEGIN
    DECLARE verified_payment_count INT UNSIGNED DEFAULT 0;
    IF NOT (OLD.bill_no <=> NEW.bill_no)
        OR NOT (OLD.occupancy_id <=> NEW.occupancy_id)
        OR NOT (OLD.resident_id <=> NEW.resident_id)
        OR NOT (OLD.room_id <=> NEW.room_id)
        OR NOT (OLD.resident_name_snapshot <=> NEW.resident_name_snapshot)
        OR NOT (OLD.room_code_snapshot <=> NEW.room_code_snapshot)
        OR NOT (OLD.period <=> NEW.period)
        OR NOT (OLD.due_date <=> NEW.due_date)
        OR NOT (OLD.rent_amount <=> NEW.rent_amount)
        OR NOT (OLD.water_previous <=> NEW.water_previous)
        OR NOT (OLD.water_current <=> NEW.water_current)
        OR NOT (OLD.water_units <=> NEW.water_units)
        OR NOT (OLD.water_rate <=> NEW.water_rate)
        OR NOT (OLD.water_amount <=> NEW.water_amount)
        OR NOT (OLD.electric_previous <=> NEW.electric_previous)
        OR NOT (OLD.electric_current <=> NEW.electric_current)
        OR NOT (OLD.electric_units <=> NEW.electric_units)
        OR NOT (OLD.electric_rate <=> NEW.electric_rate)
        OR NOT (OLD.electric_amount <=> NEW.electric_amount)
        OR NOT (OLD.other_description <=> NEW.other_description)
        OR NOT (OLD.other_amount <=> NEW.other_amount)
        OR NOT (OLD.total_amount <=> NEW.total_amount)
        OR NOT (OLD.created_by <=> NEW.created_by)
        OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Bill financial snapshots are immutable';
    END IF;
    IF OLD.status = 'paid'
        AND (NEW.status <> 'paid' OR NOT (OLD.paid_at <=> NEW.paid_at)) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A paid bill cannot be reopened or re-timestamped';
    END IF;
    IF OLD.status = 'pending' AND NEW.status = 'paid' THEN
        SELECT COUNT(*) INTO verified_payment_count
          FROM payments
         WHERE bill_id = OLD.id
           AND status = 'verified'
           AND amount = OLD.total_amount;
        IF verified_payment_count <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'A bill requires one matching verified payment';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_bills_relationship_guard
BEFORE INSERT ON bills
FOR EACH ROW
BEGIN
    DECLARE occupancy_resident BIGINT UNSIGNED DEFAULT NULL;
    DECLARE occupancy_room BIGINT UNSIGNED DEFAULT NULL;
    SELECT resident_id, room_id
      INTO occupancy_resident, occupancy_room
      FROM occupancies
     WHERE id = NEW.occupancy_id
     LIMIT 1;
    IF NEW.status <> 'pending'
        OR NEW.paid_at IS NOT NULL
        OR NOT (occupancy_resident <=> NEW.resident_id)
        OR NOT (occupancy_room <=> NEW.room_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Bill must start pending and match its occupancy';
    END IF;
END$$

CREATE TRIGGER trg_bills_no_delete
BEFORE DELETE ON bills
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Issued bills cannot be deleted';
END$$

CREATE TRIGGER trg_bill_items_insert_guard
BEFORE INSERT ON bill_items
FOR EACH ROW
BEGIN
    DECLARE expected_quantity DECIMAL(14,2) DEFAULT NULL;
    DECLARE expected_unit_price DECIMAL(14,2) DEFAULT NULL;
    DECLARE expected_amount DECIMAL(14,2) DEFAULT NULL;
    DECLARE expected_other_description VARCHAR(255) DEFAULT NULL;

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

CREATE TRIGGER trg_payments_relationship_guard
BEFORE INSERT ON payments
FOR EACH ROW
BEGIN
    DECLARE bill_resident BIGINT UNSIGNED DEFAULT NULL;
    DECLARE bill_total DECIMAL(14,2) DEFAULT NULL;
    DECLARE bill_status VARCHAR(16) DEFAULT NULL;
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

CREATE TRIGGER trg_payments_final_immutable
BEFORE UPDATE ON payments
FOR EACH ROW
BEGIN
    IF NOT (OLD.bill_id <=> NEW.bill_id)
        OR NOT (OLD.resident_id <=> NEW.resident_id)
        OR NOT (OLD.amount <=> NEW.amount)
        OR NOT (OLD.slip_path <=> NEW.slip_path)
        OR NOT (OLD.slip_mime <=> NEW.slip_mime)
        OR NOT (OLD.slip_hmac <=> NEW.slip_hmac)
        OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Payment evidence identity is immutable';
    END IF;
    IF OLD.status IN ('verified', 'rejected')
        AND (NOT (OLD.status <=> NEW.status)
            OR NOT (OLD.provider <=> NEW.provider)
            OR NOT (OLD.transaction_ref <=> NEW.transaction_ref)
            OR NOT (OLD.receiver_ref <=> NEW.receiver_ref)
            OR NOT (OLD.provider_payload <=> NEW.provider_payload)
            OR NOT (OLD.rejection_reason <=> NEW.rejection_reason)
            OR NOT (OLD.verified_at <=> NEW.verified_at)
            OR NOT (OLD.verification_lease_until <=> NEW.verification_lease_until)
            OR NOT (OLD.verification_token <=> NEW.verification_token)
            OR NOT (OLD.verification_attempts <=> NEW.verification_attempts)) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A final payment decision is immutable';
    END IF;
END$$

CREATE TRIGGER trg_payments_no_delete
BEFORE DELETE ON payments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payment evidence cannot be deleted';
END$$

CREATE TRIGGER trg_notification_relationship_guard
BEFORE INSERT ON notification_outbox
FOR EACH ROW
BEGIN
    DECLARE bill_resident BIGINT UNSIGNED DEFAULT NULL;
    SELECT resident_id
      INTO bill_resident
      FROM bills
     WHERE id = NEW.bill_id
     LIMIT 1;
    IF NOT (bill_resident <=> NEW.resident_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Notification resident must match its bill';
    END IF;
END$$

CREATE TRIGGER trg_notification_relationship_guard_update
BEFORE UPDATE ON notification_outbox
FOR EACH ROW
BEGIN
    DECLARE bill_resident BIGINT UNSIGNED DEFAULT NULL;
    SELECT resident_id
      INTO bill_resident
      FROM bills
     WHERE id = NEW.bill_id
     LIMIT 1;
    IF NOT (OLD.bill_id <=> NEW.bill_id)
        OR NOT (bill_resident <=> NEW.resident_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Notification resident must match its immutable bill';
    END IF;
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
