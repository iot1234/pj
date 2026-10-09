-- AUTO-GENERATED FROM daily_review_fixes_finance.sql AND daily_bookings.sql.
-- Existing daily installations only: apply after 018/019 with web and workers stopped.
-- Additive finance portion of migration 020. Stop all writes and workers; back up first.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone='+00:00';
SET @daily_review_marker_sql=IF(EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='daily_payments' AND constraint_name='chk_daily_payment_review_v20'),'ALTER TABLE daily_payments DROP CHECK chk_daily_payment_review_v20','SELECT 1');
PREPARE daily_review_marker_stmt FROM @daily_review_marker_sql; EXECUTE daily_review_marker_stmt; DEALLOCATE PREPARE daily_review_marker_stmt;
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


DELIMITER $$
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

DELIMITER ;

DROP TRIGGER IF EXISTS trg_daily_room_mode_guard;
DELIMITER $$
CREATE TRIGGER trg_daily_room_mode_guard BEFORE UPDATE ON rooms FOR EACH ROW
BEGIN
    IF NEW.max_guests<OLD.max_guests AND EXISTS(SELECT 1 FROM daily_bookings WHERE room_id=OLD.id AND guests>NEW.max_guests
        AND (status IN('confirmed','checked_in') OR (status='pending' AND expires_at>UTC_TIMESTAMP(6)))) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Room capacity cannot be reduced below booked daily guests';
    END IF;
    IF (OLD.rental_mode<>NEW.rental_mode OR (OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL)) AND (
       EXISTS(SELECT 1 FROM occupancies WHERE room_id=OLD.id AND status='active')
       OR EXISTS(SELECT 1 FROM bookings WHERE room_id=OLD.id AND status IN ('pending','confirmed'))
       OR EXISTS(SELECT 1 FROM daily_bookings WHERE room_id=OLD.id AND
          (status IN ('confirmed','checked_in') OR (status='pending' AND expires_at>UTC_TIMESTAMP(6))))
       OR EXISTS(SELECT 1 FROM daily_booking_nights n JOIN daily_bookings b ON b.id=n.booking_id
          WHERE n.room_id=OLD.id AND n.active=1 AND b.status='checked_out'
            AND n.stay_date>=DATE(DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 HOUR)))
       OR EXISTS(SELECT 1 FROM daily_room_blocks WHERE room_id=OLD.id AND active=1 AND end_date>DATE(DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 HOUR))))
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Room use must be closed before changing rental mode or retiring room'; END IF;
END$$
DELIMITER ;

-- Completion marker for recoverable closed evidence; installed after every review guard.
SET @daily_review_marker_sql=IF(EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='daily_payments' AND constraint_name='chk_daily_payment_review_v20'),'SELECT 1','ALTER TABLE daily_payments ADD CONSTRAINT chk_daily_payment_review_v20 CHECK(status<>''closed'' OR (verification_token IS NULL AND verified_at IS NULL))');
PREPARE daily_review_marker_stmt FROM @daily_review_marker_sql; EXECUTE daily_review_marker_stmt; DEALLOCATE PREPARE daily_review_marker_stmt;
