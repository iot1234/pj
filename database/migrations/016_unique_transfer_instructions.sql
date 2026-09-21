-- Unique transfer amounts. Apply to the selected application database after migration 015.
-- Additive only: never rewrite old bills, receipts, residents, or slip evidence.
CREATE TABLE IF NOT EXISTS transfer_instructions (
    bill_id BIGINT UNSIGNED NOT NULL,
    resident_id BIGINT UNSIGNED NOT NULL,
    bill_amount DECIMAL(14,2) NOT NULL,
    adjustment_amount DECIMAL(3,2) NOT NULL,
    transfer_amount DECIMAL(14,2) NOT NULL,
    promptpay_target VARCHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_name VARCHAR(120) NULL,
    status ENUM('reserved','settled','released') NOT NULL DEFAULT 'reserved',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    settled_at DATETIME(6) NULL,
    active_amount DECIMAL(14,2) GENERATED ALWAYS AS (CASE WHEN status IN ('reserved','settled') THEN transfer_amount ELSE NULL END) STORED,
    PRIMARY KEY (bill_id),
    UNIQUE KEY uq_transfer_active_amount (active_amount),
    KEY idx_transfer_release (status,settled_at),
    CONSTRAINT fk_transfer_bill FOREIGN KEY (bill_id) REFERENCES bills(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_transfer_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_transfer_amount CHECK (bill_amount>0 AND adjustment_amount BETWEEN 0.01 AND 0.99 AND transfer_amount=bill_amount+adjustment_amount),
    CONSTRAINT chk_transfer_target CHECK (promptpay_target REGEXP '^([0-9]{10}|[0-9]{13})$'),
    CONSTRAINT chk_transfer_state CHECK ((status='reserved' AND settled_at IS NULL) OR (status IN ('settled','released') AND settled_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER $$
DROP TRIGGER IF EXISTS trg_transfer_insert_guard$$
CREATE TRIGGER trg_transfer_insert_guard BEFORE INSERT ON transfer_instructions FOR EACH ROW
BEGIN
    DECLARE valid_bill INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_bill FROM bills WHERE id=NEW.bill_id AND resident_id=NEW.resident_id AND status='pending' AND total_amount=NEW.bill_amount;
    IF valid_bill<>1 OR NEW.status<>'reserved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TRANSFER_BILL_MISMATCH'; END IF;
    IF EXISTS(SELECT 1 FROM payments WHERE bill_id=NEW.bill_id AND status IN ('pending','verified')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TRANSFER_PAYMENT_EXISTS'; END IF;
END$$
DROP TRIGGER IF EXISTS trg_transfer_update_guard$$
CREATE TRIGGER trg_transfer_update_guard BEFORE UPDATE ON transfer_instructions FOR EACH ROW
BEGIN
    IF NOT(NEW.bill_id<=>OLD.bill_id) OR NOT(NEW.resident_id<=>OLD.resident_id)
        OR NOT(NEW.bill_amount<=>OLD.bill_amount) OR NOT(NEW.adjustment_amount<=>OLD.adjustment_amount)
        OR NOT(NEW.transfer_amount<=>OLD.transfer_amount) OR NOT(NEW.promptpay_target<=>OLD.promptpay_target)
        OR NOT(NEW.recipient_name<=>OLD.recipient_name) OR NOT(NEW.created_at<=>OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TRANSFER_SNAPSHOT_IMMUTABLE';
    END IF;
    IF NEW.status<>OLD.status THEN
        IF NOT EXISTS(SELECT 1 FROM bills WHERE id=NEW.bill_id AND status='paid') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TRANSFER_UNPAID_RELEASE'; END IF;
        IF OLD.status='reserved' AND NEW.status='settled' AND NEW.settled_at IS NOT NULL THEN
            SET NEW.settled_at=UTC_TIMESTAMP(6);
        ELSEIF OLD.status='settled' AND NEW.status='released' AND OLD.settled_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY) AND (NEW.settled_at<=>OLD.settled_at) THEN
            SET NEW.settled_at=OLD.settled_at;
        ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TRANSFER_STATE_INVALID'; END IF;
    ELSEIF NOT(NEW.settled_at<=>OLD.settled_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TRANSFER_SETTLEMENT_IMMUTABLE';
    END IF;
END$$
DROP TRIGGER IF EXISTS trg_transfer_no_delete$$
CREATE TRIGGER trg_transfer_no_delete BEFORE DELETE ON transfer_instructions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='TRANSFER_HISTORY_IMMUTABLE';
END$$
DELIMITER ;
