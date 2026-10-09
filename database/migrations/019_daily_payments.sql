-- Migration 019: stop writes and workers; back up before shared evidence backfill.
-- Shared evidence namespace plus a separate full-prepayment daily ledger.
-- Remove an earlier completion marker before replacing any ledger guard on a rerun.
SET @daily_review_marker_exists=(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='daily_payments' AND constraint_name='chk_daily_payment_review_v20');
SET @daily_review_marker_sql=IF(@daily_review_marker_exists>0,'ALTER TABLE daily_payments DROP CHECK chk_daily_payment_review_v20','SELECT 1');
PREPARE daily_review_marker_stmt FROM @daily_review_marker_sql; EXECUTE daily_review_marker_stmt; DEALLOCATE PREPARE daily_review_marker_stmt;
SET @daily_finance_marker_exists=(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='daily_payments' AND constraint_name='chk_daily_finance_schema_v19' AND constraint_type='CHECK');
SET @daily_finance_marker_sql=IF(@daily_finance_marker_exists>0,'ALTER TABLE daily_payments DROP CHECK chk_daily_finance_schema_v19','SELECT 1');
PREPARE daily_finance_marker_stmt FROM @daily_finance_marker_sql;
EXECUTE daily_finance_marker_stmt;
DEALLOCATE PREPARE daily_finance_marker_stmt;

CREATE TABLE IF NOT EXISTS payment_evidence_registry (
    subject_type ENUM('monthly','daily') NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    slip_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    transaction_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    claim_status ENUM('active','released') NOT NULL DEFAULT 'active',
    active_slip_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN claim_status='active' THEN slip_hmac ELSE NULL END) STORED,
    active_txn_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (CASE WHEN claim_status='active' THEN transaction_ref ELSE NULL END) STORED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(subject_type,subject_id),
    UNIQUE KEY uq_evidence_slip(active_slip_hmac),
    UNIQUE KEY uq_evidence_transaction(active_txn_ref),
    KEY idx_evidence_slip_history(slip_hmac), KEY idx_evidence_transaction_history(transaction_ref)
) ENGINE=InnoDB;

-- Proof claim projections preserve rejected raw evidence while allowing a correct context to reverify.
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='active_slip_hmac'),'SELECT 1','ALTER TABLE payments ADD COLUMN active_slip_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN status IN(''pending'',''verified'') THEN slip_hmac ELSE NULL END) STORED');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='credited_txn_ref'),'SELECT 1','ALTER TABLE payments ADD COLUMN credited_txn_ref VARCHAR(191) GENERATED ALWAYS AS (CASE WHEN status=''verified'' THEN transaction_ref ELSE NULL END) STORED');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_bad_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payments' AND index_name='uq_payments_slip_hmac' AND (column_name<>'active_slip_hmac' OR non_unique<>0 OR sub_part IS NOT NULL));
SET @daily_projection_sql=IF(@daily_projection_bad_index>0,'ALTER TABLE payments DROP INDEX uq_payments_slip_hmac','SELECT 1');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payments' AND index_name='uq_payments_slip_hmac'),'SELECT 1','ALTER TABLE payments ADD UNIQUE KEY uq_payments_slip_hmac(active_slip_hmac)');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_bad_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payments' AND index_name='uq_payments_transaction_ref' AND (column_name<>'credited_txn_ref' OR non_unique<>0 OR sub_part IS NOT NULL));
SET @daily_projection_sql=IF(@daily_projection_bad_index>0,'ALTER TABLE payments DROP INDEX uq_payments_transaction_ref','SELECT 1');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payments' AND index_name='uq_payments_transaction_ref'),'SELECT 1','ALTER TABLE payments ADD UNIQUE KEY uq_payments_transaction_ref(credited_txn_ref)');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payment_evidence_registry' AND column_name='claim_status'),'SELECT 1','ALTER TABLE payment_evidence_registry ADD COLUMN claim_status ENUM(''active'',''released'') NOT NULL DEFAULT ''active''');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payment_evidence_registry' AND column_name='active_slip_hmac'),'SELECT 1','ALTER TABLE payment_evidence_registry ADD COLUMN active_slip_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN claim_status=''active'' THEN slip_hmac ELSE NULL END) STORED');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payment_evidence_registry' AND column_name='active_txn_ref'),'SELECT 1','ALTER TABLE payment_evidence_registry ADD COLUMN active_txn_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (CASE WHEN claim_status=''active'' THEN transaction_ref ELSE NULL END) STORED');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_bad_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payment_evidence_registry' AND index_name='uq_evidence_slip' AND (column_name<>'active_slip_hmac' OR non_unique<>0 OR sub_part IS NOT NULL));
SET @daily_projection_sql=IF(@daily_projection_bad_index>0,'ALTER TABLE payment_evidence_registry DROP INDEX uq_evidence_slip','SELECT 1');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payment_evidence_registry' AND index_name='uq_evidence_slip'),'SELECT 1','ALTER TABLE payment_evidence_registry ADD UNIQUE KEY uq_evidence_slip(active_slip_hmac)');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_bad_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payment_evidence_registry' AND index_name='uq_evidence_transaction' AND (column_name<>'active_txn_ref' OR non_unique<>0 OR sub_part IS NOT NULL));
SET @daily_projection_sql=IF(@daily_projection_bad_index>0,'ALTER TABLE payment_evidence_registry DROP INDEX uq_evidence_transaction','SELECT 1');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='payment_evidence_registry' AND index_name='uq_evidence_transaction'),'SELECT 1','ALTER TABLE payment_evidence_registry ADD UNIQUE KEY uq_evidence_transaction(active_txn_ref)');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
UPDATE payment_evidence_registry e JOIN payments p ON e.subject_type='monthly' AND e.subject_id=p.id SET e.claim_status='released' WHERE p.status='rejected' AND e.claim_status='active';

INSERT INTO payment_evidence_registry(subject_type,subject_id,slip_hmac,transaction_ref,claim_status)
SELECT 'monthly',id,slip_hmac,transaction_ref,IF(status='rejected','released','active') FROM payments
ON DUPLICATE KEY UPDATE subject_id=subject_id;

CREATE TABLE IF NOT EXISTS payment_amount_registry (
    subject_type ENUM('monthly','daily') NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    transfer_amount DECIMAL(14,2) NOT NULL,
    status ENUM('reserved','settled','released') NOT NULL DEFAULT 'reserved',
    settled_at DATETIME(6) NULL,
    active_amount DECIMAL(14,2) GENERATED ALWAYS AS
      (CASE WHEN status IN('reserved','settled') THEN transfer_amount ELSE NULL END) STORED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(subject_type,subject_id),
    UNIQUE KEY uq_amount_global_active(active_amount),
    KEY idx_amount_history(transfer_amount),
    CONSTRAINT chk_global_transfer_positive CHECK(transfer_amount>0),
    CONSTRAINT chk_global_amount_settlement_time CHECK(status<>'settled' OR settled_at IS NOT NULL)
) ENGINE=InnoDB;

SET @daily_old_release_check=(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='payment_amount_registry' AND constraint_name='chk_daily_amount_never_release');
SET @daily_amount_upgrade_sql=IF(@daily_old_release_check>0,'ALTER TABLE payment_amount_registry DROP CHECK chk_daily_amount_never_release','SELECT 1');
PREPARE daily_amount_upgrade_stmt FROM @daily_amount_upgrade_sql;
EXECUTE daily_amount_upgrade_stmt;
DEALLOCATE PREPARE daily_amount_upgrade_stmt;
ALTER TABLE payment_amount_registry MODIFY COLUMN active_amount DECIMAL(14,2) GENERATED ALWAYS AS (CASE WHEN status IN('reserved','settled') THEN transfer_amount ELSE NULL END) STORED;
SET @daily_settlement_check=(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='payment_amount_registry' AND constraint_name='chk_global_amount_settlement_time');
SET @daily_amount_upgrade_sql=IF(@daily_settlement_check=0,'ALTER TABLE payment_amount_registry ADD CONSTRAINT chk_global_amount_settlement_time CHECK(status<>''settled'' OR settled_at IS NOT NULL)','SELECT 1');
PREPARE daily_amount_upgrade_stmt FROM @daily_amount_upgrade_sql;
EXECUTE daily_amount_upgrade_stmt;
DEALLOCATE PREPARE daily_amount_upgrade_stmt;

INSERT INTO payment_amount_registry(subject_type,subject_id,transfer_amount,status,settled_at)
SELECT 'monthly',bill_id,transfer_amount,status,settled_at FROM transfer_instructions
ON DUPLICATE KEY UPDATE subject_id=subject_id;

CREATE TABLE IF NOT EXISTS daily_transfer_instructions (
    booking_id BIGINT UNSIGNED NOT NULL,
    booking_amount DECIMAL(14,2) NOT NULL,
    adjustment_amount DECIMAL(4,2) NOT NULL,
    transfer_amount DECIMAL(14,2) NOT NULL,
    promptpay_target VARCHAR(20) NOT NULL,
    recipient_name VARCHAR(191) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(booking_id),
    KEY idx_daily_transfer_amount(transfer_amount),
    CONSTRAINT fk_daily_transfer_booking FOREIGN KEY(booking_id) REFERENCES daily_bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_daily_transfer_amount CHECK(booking_amount>0 AND adjustment_amount BETWEEN 0.01 AND 0.99 AND transfer_amount=booking_amount+adjustment_amount)
) ENGINE=InnoDB;

SET @daily_old_amount_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='daily_transfer_instructions' AND index_name='uq_daily_transfer_amount');
SET @daily_amount_upgrade_sql=IF(@daily_old_amount_index>0,'ALTER TABLE daily_transfer_instructions DROP INDEX uq_daily_transfer_amount','SELECT 1');
PREPARE daily_amount_upgrade_stmt FROM @daily_amount_upgrade_sql;
EXECUTE daily_amount_upgrade_stmt;
DEALLOCATE PREPARE daily_amount_upgrade_stmt;
SET @daily_history_amount_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='daily_transfer_instructions' AND index_name='idx_daily_transfer_amount');
SET @daily_amount_upgrade_sql=IF(@daily_history_amount_index=0,'ALTER TABLE daily_transfer_instructions ADD INDEX idx_daily_transfer_amount(transfer_amount)','SELECT 1');
PREPARE daily_amount_upgrade_stmt FROM @daily_amount_upgrade_sql;
EXECUTE daily_amount_upgrade_stmt;
DEALLOCATE PREPARE daily_amount_upgrade_stmt;

CREATE TABLE IF NOT EXISTS daily_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    transfer_amount DECIMAL(14,2) NOT NULL,
    method ENUM('slip','cash') NOT NULL,
    status ENUM('pending','verified','rejected','closed') NOT NULL DEFAULT 'pending',
    request_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    slip_path VARCHAR(512) NULL,
    slip_mime VARCHAR(32) NULL,
    slip_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    provider VARCHAR(32) NULL,
    transaction_ref VARCHAR(191) NULL,
    receiver_ref VARCHAR(191) NULL,
    provider_payload JSON NULL,
    receipt_reference VARCHAR(191) NULL,
    rejection_reason VARCHAR(500) NULL,
    verification_lease_until DATETIME(6) NULL,
    verification_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verification_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    recorded_by BIGINT UNSIGNED NULL,
    refunded_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    deposit_refunded_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    deposit_retained_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    verified_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    active_booking_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN('pending','verified') THEN booking_id ELSE NULL END) STORED,
    active_slip_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN status IN('pending','verified','closed') THEN slip_hmac ELSE NULL END) STORED,
    credited_txn_ref VARCHAR(191) GENERATED ALWAYS AS (CASE WHEN status='verified' THEN transaction_ref ELSE NULL END) STORED,
    PRIMARY KEY(id),
    UNIQUE KEY uq_daily_payment_active(active_booking_id),
    UNIQUE KEY uq_daily_payment_request(request_key),
    UNIQUE KEY uq_daily_payment_slip(active_slip_hmac),
    UNIQUE KEY uq_daily_payment_transaction(credited_txn_ref),
    KEY idx_daily_payment_slip_history(slip_hmac), KEY idx_daily_payment_transaction_history(transaction_ref),
    UNIQUE KEY uq_daily_cash_receipt(receipt_reference),
    CONSTRAINT fk_daily_payment_booking FOREIGN KEY(booking_id) REFERENCES daily_bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_daily_payment_owner FOREIGN KEY(recorded_by) REFERENCES admin_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_daily_payment_amount CHECK(amount>0 AND transfer_amount>=amount),
    CONSTRAINT chk_daily_payment_balances CHECK(refunded_amount>=0 AND deposit_refunded_amount>=0 AND deposit_retained_amount>=0 AND deposit_refunded_amount<=refunded_amount AND refunded_amount+deposit_retained_amount<=transfer_amount),
    CONSTRAINT chk_daily_payment_lease CHECK((verification_token IS NULL)=(verification_lease_until IS NULL)),
    CONSTRAINT chk_daily_payment_verified CHECK((status='verified')=(verified_at IS NOT NULL)),
    CONSTRAINT chk_daily_payment_evidence CHECK(
      (method='slip' AND slip_path IS NOT NULL AND slip_mime IS NOT NULL AND slip_hmac IS NOT NULL AND recorded_by IS NULL)
      OR (method='cash' AND status='verified' AND recorded_by IS NOT NULL AND receipt_reference IS NOT NULL AND request_key IS NOT NULL AND slip_path IS NULL AND slip_hmac IS NULL AND transaction_ref IS NULL))
) ENGINE=InnoDB;

ALTER TABLE daily_payments MODIFY status ENUM('pending','verified','rejected','closed') NOT NULL DEFAULT 'pending';
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='daily_payments' AND column_name='active_slip_hmac'),'SELECT 1','ALTER TABLE daily_payments ADD COLUMN active_slip_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN status IN(''pending'',''verified'',''closed'') THEN slip_hmac ELSE NULL END) STORED');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='daily_payments' AND column_name='credited_txn_ref'),'SELECT 1','ALTER TABLE daily_payments ADD COLUMN credited_txn_ref VARCHAR(191) GENERATED ALWAYS AS (CASE WHEN status=''verified'' THEN transaction_ref ELSE NULL END) STORED');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_bad_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='daily_payments' AND index_name='uq_daily_payment_slip' AND (column_name<>'active_slip_hmac' OR non_unique<>0 OR sub_part IS NOT NULL));
SET @daily_projection_sql=IF(@daily_projection_bad_index>0,'ALTER TABLE daily_payments DROP INDEX uq_daily_payment_slip','SELECT 1');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='daily_payments' AND index_name='uq_daily_payment_slip'),'SELECT 1','ALTER TABLE daily_payments ADD UNIQUE KEY uq_daily_payment_slip(active_slip_hmac)');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_bad_index=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='daily_payments' AND index_name='uq_daily_payment_transaction' AND (column_name<>'credited_txn_ref' OR non_unique<>0 OR sub_part IS NOT NULL));
SET @daily_projection_sql=IF(@daily_projection_bad_index>0,'ALTER TABLE daily_payments DROP INDEX uq_daily_payment_transaction','SELECT 1');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
SET @daily_projection_sql=IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='daily_payments' AND index_name='uq_daily_payment_transaction'),'SELECT 1','ALTER TABLE daily_payments ADD UNIQUE KEY uq_daily_payment_transaction(credited_txn_ref)');
PREPARE daily_projection_stmt FROM @daily_projection_sql; EXECUTE daily_projection_stmt; DEALLOCATE PREPARE daily_projection_stmt;
UPDATE payment_evidence_registry e JOIN daily_payments p ON e.subject_type='daily' AND e.subject_id=p.id SET e.claim_status='released' WHERE p.status='rejected' AND e.claim_status='active';

CREATE TABLE IF NOT EXISTS daily_payment_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id BIGINT UNSIGNED NOT NULL,
    action ENUM('close') NOT NULL,
    idempotency_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(450) NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    response_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(id), UNIQUE KEY uq_daily_payment_action_key(payment_id,idempotency_key),
    CONSTRAINT fk_daily_payment_action_payment FOREIGN KEY(payment_id) REFERENCES daily_payments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_daily_payment_action_owner FOREIGN KEY(recorded_by) REFERENCES admin_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_daily_payment_action_hash CHECK(request_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_daily_payment_action_reason CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 3 AND 450)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daily_refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    purpose ENUM('cancellation','deposit') NOT NULL,
    request_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reference_no VARCHAR(191) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(id),
    UNIQUE KEY uq_daily_refund_request(request_key),
    UNIQUE KEY uq_daily_refund_reference(reference_no),
    CONSTRAINT fk_daily_refund_booking FOREIGN KEY(booking_id) REFERENCES daily_bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_daily_refund_payment FOREIGN KEY(payment_id) REFERENCES daily_payments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_daily_refund_owner FOREIGN KEY(recorded_by) REFERENCES admin_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_daily_refund_positive CHECK(amount>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daily_deposit_settlements (
    booking_id BIGINT UNSIGNED NOT NULL,
    retained_amount DECIMAL(14,2) NOT NULL,
    request_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(500) NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(booking_id),
    UNIQUE KEY uq_daily_deposit_request(request_key),
    CONSTRAINT fk_daily_deposit_booking FOREIGN KEY(booking_id) REFERENCES daily_bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_daily_deposit_owner FOREIGN KEY(recorded_by) REFERENCES admin_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_daily_deposit_nonnegative CHECK(retained_amount>=0)
) ENGINE=InnoDB;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_daily_payment_insert$$
CREATE TRIGGER trg_daily_payment_insert BEFORE INSERT ON daily_payments FOR EACH ROW
BEGIN
    IF NOT EXISTS(SELECT 1 FROM daily_bookings WHERE id=NEW.booking_id AND total_amount=NEW.amount) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_PAYMENT_AMOUNT_MISMATCH';
    END IF;
    IF NEW.refunded_amount<>0 OR NEW.deposit_refunded_amount<>0 OR NEW.deposit_retained_amount<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_INITIAL_BALANCE_INVALID'; END IF;
    IF NEW.method='cash' AND (NOT EXISTS(SELECT 1 FROM admin_users WHERE id=NEW.recorded_by AND role='owner' AND active=1 AND retired_at IS NULL)
      OR CHAR_LENGTH(TRIM(NEW.receipt_reference))<3 OR COALESCE(CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(NEW.provider_payload,'$.reason'))),0)<3
      OR NOT EXISTS(SELECT 1 FROM daily_bookings WHERE id=NEW.booking_id AND status='pending' AND expires_at>UTC_TIMESTAMP(6))
      OR EXISTS(SELECT 1 FROM daily_transfer_instructions WHERE booking_id=NEW.booking_id)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_CASH_OWNER_EVIDENCE_REQUIRED';
    END IF;
    IF NEW.method='slip' AND (NEW.status<>'pending' OR NOT EXISTS(SELECT 1 FROM daily_transfer_instructions WHERE booking_id=NEW.booking_id AND booking_amount=NEW.amount AND transfer_amount=NEW.transfer_amount)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_SLIP_INTENT_MISMATCH';
    END IF;
END$$
DROP TRIGGER IF EXISTS trg_daily_payment_update$$
CREATE TRIGGER trg_daily_payment_update BEFORE UPDATE ON daily_payments FOR EACH ROW
BEGIN
    DECLARE deposit_value DECIMAL(14,2);
    IF OLD.status<>NEW.status AND NOT((OLD.status='pending' AND NEW.status IN('verified','rejected','closed')) OR (OLD.status='closed' AND NEW.status='pending')) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_PAYMENT_STATE_INVALID';
    END IF;
    IF OLD.status='closed' AND NEW.status='pending' AND (NEW.verification_token IS NULL OR NEW.verification_lease_until<=UTC_TIMESTAMP(6) OR NEW.verification_attempts<>OLD.verification_attempts+1) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_PAYMENT_REOPEN_REQUIRES_CLAIM';
    END IF;
    IF NEW.status='closed' AND (NEW.verification_token IS NOT NULL OR NEW.verification_lease_until IS NOT NULL OR COALESCE(CHAR_LENGTH(TRIM(NEW.rejection_reason)),0)<3) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_PAYMENT_CLOSE_INVALID';
    END IF;
    IF NOT(OLD.booking_id<=>NEW.booking_id) OR NOT(OLD.amount<=>NEW.amount) OR NOT(OLD.transfer_amount<=>NEW.transfer_amount)
      OR NOT(OLD.method<=>NEW.method) OR NOT(OLD.request_key<=>NEW.request_key) OR NOT(OLD.slip_path<=>NEW.slip_path)
      OR NOT(OLD.slip_mime<=>NEW.slip_mime) OR NOT(OLD.slip_hmac<=>NEW.slip_hmac) OR NOT(OLD.created_at<=>NEW.created_at)
      OR NOT(OLD.receipt_reference<=>NEW.receipt_reference) OR NOT(OLD.recorded_by<=>NEW.recorded_by)
      OR (OLD.status IN('verified','rejected') AND (NOT(OLD.status<=>NEW.status) OR NOT(OLD.transaction_ref<=>NEW.transaction_ref)
      OR NOT(OLD.verified_at<=>NEW.verified_at) OR NOT(OLD.provider_payload<=>NEW.provider_payload) OR NOT(OLD.provider<=>NEW.provider)
      OR NOT(OLD.receiver_ref<=>NEW.receiver_ref) OR NOT(OLD.rejection_reason<=>NEW.rejection_reason)
      OR NOT(OLD.verification_token<=>NEW.verification_token) OR NOT(OLD.verification_lease_until<=>NEW.verification_lease_until)
      OR NOT(OLD.verification_attempts<=>NEW.verification_attempts))) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_PAYMENT_IMMUTABLE';
    END IF;
    SELECT deposit_amount INTO deposit_value FROM daily_bookings WHERE id=OLD.booking_id;
    IF NEW.refunded_amount<OLD.refunded_amount OR NEW.deposit_refunded_amount<OLD.deposit_refunded_amount OR NEW.deposit_retained_amount<OLD.deposit_retained_amount
      OR NEW.deposit_refunded_amount+NEW.deposit_retained_amount>deposit_value
      OR ((NOT(OLD.refunded_amount<=>NEW.refunded_amount) OR NOT(OLD.deposit_refunded_amount<=>NEW.deposit_refunded_amount) OR NOT(OLD.deposit_retained_amount<=>NEW.deposit_retained_amount)) AND OLD.status<>'verified') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_RECEIPT_BALANCE_INVALID';
    END IF;
    IF OLD.status='pending' AND NEW.status='verified' AND NOT EXISTS(SELECT 1 FROM payment_evidence_registry WHERE subject_type='daily' AND subject_id=OLD.id AND slip_hmac=OLD.slip_hmac AND transaction_ref=NEW.transaction_ref) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_GLOBAL_EVIDENCE_REQUIRED';
    END IF;
END$$
DROP TRIGGER IF EXISTS trg_daily_payment_action_insert$$
CREATE TRIGGER trg_daily_payment_action_insert BEFORE INSERT ON daily_payment_actions FOR EACH ROW
BEGIN
    IF NOT EXISTS(SELECT 1 FROM daily_payments WHERE id=NEW.payment_id AND method='slip' AND status='closed')
      OR NOT EXISTS(SELECT 1 FROM admin_users WHERE id=NEW.recorded_by AND role='owner' AND active=1 AND retired_at IS NULL) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_PAYMENT_ACTION_INVALID';
    END IF;
END$$
DROP TRIGGER IF EXISTS trg_daily_payment_action_no_update$$
CREATE TRIGGER trg_daily_payment_action_no_update BEFORE UPDATE ON daily_payment_actions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_PAYMENT_ACTION_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_daily_payment_action_no_delete$$
CREATE TRIGGER trg_daily_payment_action_no_delete BEFORE DELETE ON daily_payment_actions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_PAYMENT_ACTION_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_evidence_insert_guard$$
CREATE TRIGGER trg_evidence_insert_guard BEFORE INSERT ON payment_evidence_registry FOR EACH ROW
BEGIN
    IF (NEW.subject_type='monthly' AND NOT EXISTS(SELECT 1 FROM payments WHERE id=NEW.subject_id AND (NEW.slip_hmac IS NULL OR slip_hmac=NEW.slip_hmac)))
      OR (NEW.subject_type='daily' AND NOT EXISTS(SELECT 1 FROM daily_payments WHERE id=NEW.subject_id AND method='slip' AND (NEW.slip_hmac IS NULL OR slip_hmac=NEW.slip_hmac))) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='GLOBAL_EVIDENCE_SUBJECT_MISMATCH';
    END IF;
    IF (NEW.claim_status='released' AND NOT((NEW.subject_type='monthly' AND EXISTS(SELECT 1 FROM payments WHERE id=NEW.subject_id AND status='rejected')) OR (NEW.subject_type='daily' AND EXISTS(SELECT 1 FROM daily_payments WHERE id=NEW.subject_id AND status='rejected'))))
      OR (NEW.claim_status='active' AND ((NEW.subject_type='monthly' AND EXISTS(SELECT 1 FROM payments WHERE id=NEW.subject_id AND status='rejected')) OR (NEW.subject_type='daily' AND EXISTS(SELECT 1 FROM daily_payments WHERE id=NEW.subject_id AND status='rejected')))) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='GLOBAL_EVIDENCE_CLAIM_STATE_INVALID';
    END IF;
END$$
DROP TRIGGER IF EXISTS trg_evidence_immutable$$
CREATE TRIGGER trg_evidence_immutable BEFORE UPDATE ON payment_evidence_registry FOR EACH ROW
BEGIN
    IF NOT(OLD.subject_type<=>NEW.subject_type) OR NOT(OLD.subject_id<=>NEW.subject_id) OR NOT(OLD.created_at<=>NEW.created_at)
      OR (OLD.slip_hmac IS NOT NULL AND NOT(OLD.slip_hmac<=>NEW.slip_hmac))
      OR (OLD.transaction_ref IS NOT NULL AND NOT(OLD.transaction_ref<=>NEW.transaction_ref)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='GLOBAL_EVIDENCE_IMMUTABLE';
    END IF;
    IF OLD.claim_status='released' AND NEW.claim_status<>'released' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='GLOBAL_EVIDENCE_CANNOT_REACTIVATE'; END IF;
    IF OLD.claim_status<>NEW.claim_status AND NOT(
      OLD.claim_status='active' AND NEW.claim_status='released' AND (
       (OLD.subject_type='monthly' AND EXISTS(SELECT 1 FROM payments WHERE id=OLD.subject_id AND status='rejected'))
       OR (OLD.subject_type='daily' AND EXISTS(SELECT 1 FROM daily_payments WHERE id=OLD.subject_id AND status='rejected')))) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='GLOBAL_EVIDENCE_RELEASE_REQUIRES_REJECTION';
    END IF;
END$$
DROP TRIGGER IF EXISTS trg_evidence_no_delete$$
CREATE TRIGGER trg_evidence_no_delete BEFORE DELETE ON payment_evidence_registry FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='GLOBAL_EVIDENCE_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_amount_immutable$$
CREATE TRIGGER trg_amount_immutable BEFORE UPDATE ON payment_amount_registry FOR EACH ROW
BEGIN
    DECLARE booking_status VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE quarantine_start DATETIME(6);
    DECLARE pending_evidence INT DEFAULT 0;
    IF NOT(OLD.subject_type<=>NEW.subject_type) OR NOT(OLD.subject_id<=>NEW.subject_id) OR NOT(OLD.transfer_amount<=>NEW.transfer_amount) OR NOT(OLD.created_at<=>NEW.created_at)
      OR (OLD.status='released' AND NEW.status<>'released')
      OR (OLD.subject_type='daily' AND OLD.status='released' AND NOT(OLD.settled_at<=>NEW.settled_at))
      OR (OLD.subject_type='daily' AND OLD.settled_at IS NOT NULL AND NOT(OLD.settled_at<=>NEW.settled_at)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='GLOBAL_AMOUNT_IMMUTABLE';
    END IF;
    IF OLD.subject_type='daily' AND NEW.status<>OLD.status THEN
      SELECT status,CASE WHEN status IN('cancelled','no_show') THEN closed_at ELSE expires_at END
        INTO booking_status,quarantine_start FROM daily_bookings WHERE id=OLD.subject_id FOR SHARE;
      SELECT COUNT(*) INTO pending_evidence FROM daily_payments WHERE booking_id=OLD.subject_id AND status='pending' FOR SHARE;
      IF NEW.status='released' THEN
        IF pending_evidence>0 OR (OLD.status='settled' AND (OLD.settled_at IS NULL OR OLD.settled_at>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY)))
          OR (OLD.status='reserved' AND (booking_status NOT IN('pending','expired','cancelled','no_show') OR quarantine_start IS NULL OR quarantine_start>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY))) THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_AMOUNT_QUARANTINE_ACTIVE';
        END IF;
      ELSEIF OLD.status='reserved' AND NEW.status='settled' THEN
        IF NEW.settled_at IS NULL OR NOT EXISTS(SELECT 1 FROM daily_payments WHERE booking_id=OLD.subject_id AND status='verified') THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_AMOUNT_RECEIPT_REQUIRED';
        END IF;
      ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_AMOUNT_STATE_INVALID';
      END IF;
    END IF;
END$$
DROP TRIGGER IF EXISTS trg_amount_no_delete$$
CREATE TRIGGER trg_amount_no_delete BEFORE DELETE ON payment_amount_registry FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='GLOBAL_AMOUNT_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_daily_refund_insert$$
CREATE TRIGGER trg_daily_refund_insert BEFORE INSERT ON daily_refunds FOR EACH ROW
BEGIN
    DECLARE booking_room BIGINT UNSIGNED;
    DECLARE room_lock BIGINT UNSIGNED;
    DECLARE received DECIMAL(14,2);
    DECLARE refunded DECIMAL(14,2);
    DECLARE deposit_value DECIMAL(14,2);
    DECLARE retained DECIMAL(14,2);
    DECLARE deposit_refunded DECIMAL(14,2);
    SELECT room_id,deposit_amount INTO booking_room,deposit_value FROM daily_bookings WHERE id=NEW.booking_id;
    SELECT id INTO room_lock FROM rooms WHERE id=booking_room FOR UPDATE;
    SELECT transfer_amount,refunded_amount,deposit_refunded_amount,deposit_retained_amount INTO received,refunded,deposit_refunded,retained
      FROM daily_payments WHERE id=NEW.payment_id AND booking_id=NEW.booking_id AND status='verified' FOR UPDATE;
    IF received IS NULL OR NEW.amount>received-refunded-retained OR CHAR_LENGTH(TRIM(NEW.reference_no))<3 OR CHAR_LENGTH(TRIM(NEW.reason))<3
      OR NOT EXISTS(SELECT 1 FROM admin_users WHERE id=NEW.recorded_by AND role='owner' AND active=1 AND retired_at IS NULL) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_REFUND_EVIDENCE_INVALID';
    END IF;
    IF (NEW.purpose='cancellation' AND NOT EXISTS(SELECT 1 FROM daily_bookings WHERE id=NEW.booking_id AND status IN('cancelled','expired','no_show')))
      OR (NEW.purpose='deposit' AND (NEW.amount>deposit_value-deposit_refunded-retained OR NOT EXISTS(SELECT 1 FROM daily_bookings WHERE id=NEW.booking_id AND status IN('checked_in','checked_out')))) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_REFUND_PURPOSE_INVALID';
    END IF;
    UPDATE daily_payments SET refunded_amount=refunded_amount+NEW.amount,
      deposit_refunded_amount=deposit_refunded_amount+IF(NEW.purpose='deposit',NEW.amount,0) WHERE id=NEW.payment_id;
END$$
DROP TRIGGER IF EXISTS trg_daily_deposit_insert$$
CREATE TRIGGER trg_daily_deposit_insert BEFORE INSERT ON daily_deposit_settlements FOR EACH ROW
BEGIN
    DECLARE booking_room BIGINT UNSIGNED;
    DECLARE room_lock BIGINT UNSIGNED;
    DECLARE deposit_value DECIMAL(14,2);
    DECLARE refunded DECIMAL(14,2);
    DECLARE retained DECIMAL(14,2);
    DECLARE receipt_id BIGINT UNSIGNED;
    SELECT room_id,deposit_amount INTO booking_room,deposit_value FROM daily_bookings WHERE id=NEW.booking_id;
    SELECT id INTO room_lock FROM rooms WHERE id=booking_room FOR UPDATE;
    SELECT id,deposit_refunded_amount,deposit_retained_amount INTO receipt_id,refunded,retained FROM daily_payments WHERE booking_id=NEW.booking_id AND status='verified' FOR UPDATE;
    IF receipt_id IS NULL OR NEW.retained_amount>deposit_value-refunded-retained OR CHAR_LENGTH(TRIM(NEW.reason))<3
      OR NOT EXISTS(SELECT 1 FROM daily_bookings WHERE id=NEW.booking_id AND status IN('checked_in','checked_out'))
      OR NOT EXISTS(SELECT 1 FROM daily_payments WHERE booking_id=NEW.booking_id AND status='verified')
      OR NOT EXISTS(SELECT 1 FROM admin_users WHERE id=NEW.recorded_by AND role='owner' AND active=1 AND retired_at IS NULL) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_DEPOSIT_EVIDENCE_INVALID';
    END IF;
    UPDATE daily_payments SET deposit_retained_amount=deposit_retained_amount+NEW.retained_amount WHERE id=receipt_id;
END$$
DROP TRIGGER IF EXISTS trg_daily_payment_no_delete$$
CREATE TRIGGER trg_daily_payment_no_delete BEFORE DELETE ON daily_payments FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_PAYMENT_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_daily_transfer_no_update$$
DROP TRIGGER IF EXISTS trg_daily_transfer_insert$$
CREATE TRIGGER trg_daily_transfer_insert BEFORE INSERT ON daily_transfer_instructions FOR EACH ROW
BEGIN
    IF NOT EXISTS(SELECT 1 FROM daily_bookings WHERE id=NEW.booking_id AND status='pending' AND total_amount=NEW.booking_amount)
      OR NOT EXISTS(SELECT 1 FROM payment_amount_registry WHERE subject_type='daily' AND subject_id=NEW.booking_id AND transfer_amount=NEW.transfer_amount AND status='reserved') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_TRANSFER_BOOKING_MISMATCH';
    END IF;
END$$
CREATE TRIGGER trg_daily_transfer_no_update BEFORE UPDATE ON daily_transfer_instructions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_TRANSFER_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_daily_transfer_no_delete$$
CREATE TRIGGER trg_daily_transfer_no_delete BEFORE DELETE ON daily_transfer_instructions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_TRANSFER_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_daily_refund_no_update$$
CREATE TRIGGER trg_daily_refund_no_update BEFORE UPDATE ON daily_refunds FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_REFUND_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_daily_refund_no_delete$$
CREATE TRIGGER trg_daily_refund_no_delete BEFORE DELETE ON daily_refunds FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_REFUND_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_daily_deposit_no_update$$
CREATE TRIGGER trg_daily_deposit_no_update BEFORE UPDATE ON daily_deposit_settlements FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_DEPOSIT_IMMUTABLE'; END$$
DROP TRIGGER IF EXISTS trg_daily_deposit_no_delete$$
CREATE TRIGGER trg_daily_deposit_no_delete BEFORE DELETE ON daily_deposit_settlements FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_DEPOSIT_IMMUTABLE'; END$$
DROP PROCEDURE IF EXISTS assert_daily_finance_backfill$$
CREATE PROCEDURE assert_daily_finance_backfill()
BEGIN
    IF EXISTS(SELECT 1 FROM payments p LEFT JOIN payment_evidence_registry e ON e.subject_type='monthly' AND e.subject_id=p.id
      WHERE e.subject_id IS NULL OR NOT(e.slip_hmac<=>p.slip_hmac) OR NOT(e.transaction_ref<=>p.transaction_ref))
      OR EXISTS(SELECT 1 FROM transfer_instructions t LEFT JOIN payment_amount_registry a ON a.subject_type='monthly' AND a.subject_id=t.bill_id
      WHERE a.subject_id IS NULL OR a.transfer_amount<>t.transfer_amount) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DAILY_FINANCE_BACKFILL_CONFLICT';
    END IF;
END$$
CALL assert_daily_finance_backfill()$$
DROP PROCEDURE assert_daily_finance_backfill$$
DELIMITER ;

-- Set the enforced completion marker only after every ledger guard and backfill succeeded.
SET @daily_finance_marker_exists=(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='daily_payments' AND constraint_name='chk_daily_finance_schema_v19' AND constraint_type='CHECK');
SET @daily_finance_marker_sql=IF(@daily_finance_marker_exists=0,'ALTER TABLE daily_payments ADD CONSTRAINT chk_daily_finance_schema_v19 CHECK(amount>0 AND transfer_amount>=amount)','SELECT 1');
PREPARE daily_finance_marker_stmt FROM @daily_finance_marker_sql;
EXECUTE daily_finance_marker_stmt;
DEALLOCATE PREPARE daily_finance_marker_stmt;

-- Completion marker for recoverable closed evidence; installed after every review guard.
SET @daily_review_marker_sql=IF(EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='daily_payments' AND constraint_name='chk_daily_payment_review_v20'),'SELECT 1','ALTER TABLE daily_payments ADD CONSTRAINT chk_daily_payment_review_v20 CHECK(status<>''closed'' OR (verification_token IS NULL AND verified_at IS NULL))');
PREPARE daily_review_marker_stmt FROM @daily_review_marker_sql; EXECUTE daily_review_marker_stmt; DEALLOCATE PREPARE daily_review_marker_stmt;
