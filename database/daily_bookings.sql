-- Additive daily-room booking tables. Room columns are installed by migration 018.
-- Existing monthly ledgers and bookings retain their original invariants.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- Clear the previous completion marker before a rerun replaces any guards.
SET @daily_booking_marker_reset=IF(EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='daily_bookings' AND constraint_name='chk_daily_booking_schema_v18'),'ALTER TABLE daily_bookings DROP CHECK chk_daily_booking_schema_v18','SELECT 1');
PREPARE daily_booking_marker FROM @daily_booking_marker_reset; EXECUTE daily_booking_marker; DEALLOCATE PREPARE daily_booking_marker;

CREATE TABLE IF NOT EXISTS daily_bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no VARCHAR(40) NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    phone_norm CHAR(10) NOT NULL,
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    guests SMALLINT UNSIGNED NOT NULL,
    nightly_rate DECIMAL(12,2) NOT NULL,
    room_amount DECIMAL(14,2) NOT NULL,
    deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(14,2) NOT NULL,
    status ENUM('pending','confirmed','checked_in','checked_out','cancelled','expired','no_show') NOT NULL DEFAULT 'pending',
    expires_at DATETIME(6) NOT NULL,
    access_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    confirmed_at DATETIME(6) NULL,
    actual_check_in_at DATETIME(6) NULL,
    actual_check_out_at DATETIME(6) NULL,
    closed_at DATETIME(6) NULL,
    close_reason VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_booking_reference (reference_no),
    UNIQUE KEY uq_daily_booking_idempotency (idempotency_key),
    KEY idx_daily_booking_room_dates (room_id,check_in_date,check_out_date),
    KEY idx_daily_booking_expiry (status,expires_at),
    KEY idx_daily_booking_phone (phone_norm,created_at),
    CONSTRAINT fk_daily_booking_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_daily_booking_creator FOREIGN KEY (created_by) REFERENCES admin_users(id) ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT chk_daily_booking_dates CHECK (check_out_date>check_in_date AND DATEDIFF(check_out_date,check_in_date)<=90),
    CONSTRAINT chk_daily_booking_name CHECK (CHAR_LENGTH(TRIM(full_name)) BETWEEN 2 AND 150),
    CONSTRAINT chk_daily_booking_phone CHECK (phone_norm REGEXP '^0[0-9]{9}$'),
    CONSTRAINT chk_daily_booking_guests CHECK (guests BETWEEN 1 AND 20),
    CONSTRAINT chk_daily_booking_money CHECK (nightly_rate>0 AND nightly_rate<=1000000 AND deposit_amount>=0 AND deposit_amount<=1000000 AND room_amount=nightly_rate*DATEDIFF(check_out_date,check_in_date) AND total_amount=room_amount+deposit_amount),
    CONSTRAINT chk_daily_booking_hashes CHECK (access_token_hash REGEXP '^[0-9a-f]{64}$' AND request_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_daily_booking_key CHECK (idempotency_key REGEXP '^[A-Za-z0-9_-]{16,64}$'),
    CONSTRAINT chk_daily_booking_version CHECK (version>=1),
    CONSTRAINT chk_daily_booking_state CHECK (
      (status='pending' AND confirmed_at IS NULL AND actual_check_in_at IS NULL AND actual_check_out_at IS NULL AND closed_at IS NULL)
      OR (status='confirmed' AND confirmed_at IS NOT NULL AND actual_check_in_at IS NULL AND actual_check_out_at IS NULL AND closed_at IS NULL)
      OR (status='checked_in' AND confirmed_at IS NOT NULL AND actual_check_in_at IS NOT NULL AND actual_check_out_at IS NULL AND closed_at IS NULL)
      OR (status='checked_out' AND confirmed_at IS NOT NULL AND actual_check_in_at IS NOT NULL AND actual_check_out_at IS NOT NULL AND closed_at IS NOT NULL)
      OR (status IN ('cancelled','expired','no_show') AND actual_check_in_at IS NULL AND actual_check_out_at IS NULL AND closed_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_booking_nights (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    stay_date DATE NOT NULL,
    nightly_rate DECIMAL(12,2) NOT NULL,
    active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    active_room_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN active=1 THEN room_id ELSE NULL END) STORED,
    released_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_booking_night (booking_id,stay_date),
    UNIQUE KEY uq_daily_active_room_night (active_room_id,stay_date),
    KEY idx_daily_night_dates (room_id,stay_date),
    CONSTRAINT fk_daily_night_booking FOREIGN KEY (booking_id) REFERENCES daily_bookings(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_daily_night_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_daily_night_rate CHECK (nightly_rate>0 AND nightly_rate<=1000000),
    CONSTRAINT chk_daily_night_release CHECK ((active=1 AND released_at IS NULL) OR (active=0 AND released_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_room_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason VARCHAR(500) NOT NULL,
    active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    idempotency_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    release_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    release_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    released_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id), UNIQUE KEY uq_daily_block_key (idempotency_key), KEY idx_daily_block_dates (room_id,active,start_date,end_date),
    CONSTRAINT fk_daily_block_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_daily_block_dates CHECK (end_date>start_date),
    CONSTRAINT chk_daily_block_active CHECK ((active=1 AND released_at IS NULL AND version=1) OR (active=0 AND released_at IS NOT NULL AND version=2 AND release_key IS NOT NULL AND release_hash IS NOT NULL)),
    CONSTRAINT chk_daily_block_reason CHECK (CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_booking_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(20) NOT NULL,
    idempotency_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id), UNIQUE KEY uq_daily_action_key (booking_id,idempotency_key),
    CONSTRAINT fk_daily_action_booking FOREIGN KEY (booking_id) REFERENCES daily_bookings(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_daily_action_hash CHECK (request_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_daily_action_name CHECK (action IN ('confirm','cancel','check-in','check-out','no-show'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_housekeeping_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id), UNIQUE KEY uq_daily_housekeeping_key (room_id,idempotency_key),
    CONSTRAINT fk_daily_housekeeping_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_daily_housekeeping_hash CHECK (request_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_daily_booking_insert_guard;
DROP TRIGGER IF EXISTS trg_daily_booking_immutable;
DROP TRIGGER IF EXISTS trg_daily_night_insert_guard;
DROP TRIGGER IF EXISTS trg_daily_night_immutable;
DROP TRIGGER IF EXISTS trg_daily_night_no_delete;
DROP TRIGGER IF EXISTS trg_daily_action_no_update;
DROP TRIGGER IF EXISTS trg_daily_action_no_delete;
DROP TRIGGER IF EXISTS trg_monthly_booking_mode_guard;
DROP TRIGGER IF EXISTS trg_monthly_occupancy_mode_guard;
DROP TRIGGER IF EXISTS trg_daily_block_insert_guard;
DROP TRIGGER IF EXISTS trg_daily_block_immutable;
DROP TRIGGER IF EXISTS trg_daily_housekeeping_no_update;
DROP TRIGGER IF EXISTS trg_daily_housekeeping_no_delete;
DROP TRIGGER IF EXISTS trg_daily_block_no_delete;
DROP TRIGGER IF EXISTS trg_daily_booking_no_delete;
DROP TRIGGER IF EXISTS trg_daily_room_mode_guard;
DELIMITER $$
CREATE TRIGGER trg_daily_booking_insert_guard BEFORE INSERT ON daily_bookings FOR EACH ROW
BEGIN
    DECLARE room_mode VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE room_deleted DATETIME(6);
    DECLARE capacity SMALLINT UNSIGNED;
    SELECT rental_mode,deleted_at,max_guests INTO room_mode,room_deleted,capacity FROM rooms WHERE id=NEW.room_id FOR UPDATE;
    IF NEW.status<>'pending' OR room_mode<>'daily' OR room_deleted IS NOT NULL OR NEW.guests>capacity
       OR EXISTS(SELECT 1 FROM occupancies WHERE room_id=NEW.room_id AND status='active')
       OR EXISTS(SELECT 1 FROM bookings WHERE room_id=NEW.room_id AND status IN ('pending','confirmed'))
       OR EXISTS(SELECT 1 FROM daily_room_blocks WHERE room_id=NEW.room_id AND active=1 AND start_date<NEW.check_out_date AND end_date>NEW.check_in_date)
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Daily booking requires an available dedicated daily room'; END IF;
END$$
CREATE TRIGGER trg_daily_booking_immutable BEFORE UPDATE ON daily_bookings FOR EACH ROW
BEGIN
    IF NOT(OLD.room_id<=>NEW.room_id) OR NOT(OLD.reference_no<=>NEW.reference_no)
       OR NOT(OLD.full_name<=>NEW.full_name) OR NOT(OLD.phone_norm<=>NEW.phone_norm)
       OR NOT(OLD.check_in_date<=>NEW.check_in_date) OR NOT(OLD.check_out_date<=>NEW.check_out_date)
       OR NOT(OLD.guests<=>NEW.guests) OR NOT(OLD.nightly_rate<=>NEW.nightly_rate)
       OR NOT(OLD.room_amount<=>NEW.room_amount) OR NOT(OLD.deposit_amount<=>NEW.deposit_amount)
       OR NOT(OLD.total_amount<=>NEW.total_amount) OR NOT(OLD.idempotency_key<=>NEW.idempotency_key)
       OR NOT(OLD.request_hash<=>NEW.request_hash) OR NOT(OLD.access_token_hash<=>NEW.access_token_hash)
       OR NOT(OLD.created_by<=>NEW.created_by)
       OR NOT(OLD.expires_at<=>NEW.expires_at) OR NOT(OLD.created_at<=>NEW.created_at)
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Daily booking identity and price snapshots are immutable'; END IF;
    IF OLD.status<>NEW.status AND NOT(
       (OLD.status='pending' AND NEW.status IN ('confirmed','cancelled','expired'))
       OR (OLD.status='confirmed' AND NEW.status IN ('checked_in','cancelled','no_show'))
       OR (OLD.status='checked_in' AND NEW.status='checked_out'))
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid daily booking transition'; END IF;
    IF (OLD.status<>NEW.status AND NEW.version<>OLD.version+1) OR (OLD.status=NEW.status AND NEW.version<>OLD.version)
       OR (OLD.status=NEW.status AND (NOT(OLD.confirmed_at<=>NEW.confirmed_at) OR NOT(OLD.actual_check_in_at<=>NEW.actual_check_in_at) OR NOT(OLD.actual_check_out_at<=>NEW.actual_check_out_at) OR NOT(OLD.closed_at<=>NEW.closed_at) OR NOT(OLD.close_reason<=>NEW.close_reason)))
       OR (NEW.status IN ('cancelled','expired','no_show') AND NOT(OLD.confirmed_at<=>NEW.confirmed_at))
       OR (OLD.confirmed_at IS NOT NULL AND NOT(OLD.confirmed_at<=>NEW.confirmed_at))
       OR (OLD.actual_check_in_at IS NOT NULL AND NOT(OLD.actual_check_in_at<=>NEW.actual_check_in_at))
       OR (OLD.actual_check_out_at IS NOT NULL AND NOT(OLD.actual_check_out_at<=>NEW.actual_check_out_at))
       OR (OLD.closed_at IS NOT NULL AND (NOT(OLD.closed_at<=>NEW.closed_at) OR NOT(OLD.close_reason<=>NEW.close_reason)))
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Daily transition evidence is immutable'; END IF;
END$$
CREATE TRIGGER trg_daily_night_insert_guard BEFORE INSERT ON daily_booking_nights FOR EACH ROW
BEGIN
    DECLARE booking_room BIGINT UNSIGNED;
    DECLARE start_day DATE;
    DECLARE end_day DATE;
    DECLARE rate DECIMAL(12,2);
    DECLARE booking_state VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    SELECT room_id,check_in_date,check_out_date,nightly_rate,status INTO booking_room,start_day,end_day,rate,booking_state FROM daily_bookings WHERE id=NEW.booking_id FOR SHARE;
    IF NEW.room_id<>booking_room OR NEW.stay_date<start_day OR NEW.stay_date>=end_day OR NEW.nightly_rate<>rate OR booking_state<>'pending' OR NEW.active<>1
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Night must match its pending daily booking'; END IF;
END$$
CREATE TRIGGER trg_daily_night_immutable BEFORE UPDATE ON daily_booking_nights FOR EACH ROW
BEGIN
    IF NOT(OLD.booking_id<=>NEW.booking_id) OR NOT(OLD.room_id<=>NEW.room_id) OR NOT(OLD.stay_date<=>NEW.stay_date)
       OR NOT(OLD.nightly_rate<=>NEW.nightly_rate) OR NOT(OLD.created_at<=>NEW.created_at)
       OR (OLD.active=0 AND (NEW.active<>0 OR NOT(OLD.released_at<=>NEW.released_at)))
       OR (OLD.active=1 AND NEW.active=0 AND NOT EXISTS(SELECT 1 FROM daily_bookings WHERE id=NEW.booking_id AND status IN ('cancelled','expired','no_show')))
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Night history is immutable except cancellation release'; END IF;
END$$
CREATE TRIGGER trg_daily_night_no_delete BEFORE DELETE ON daily_booking_nights FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Daily night history cannot be deleted'; END$$
CREATE TRIGGER trg_daily_action_no_update BEFORE UPDATE ON daily_booking_actions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Daily action history is append only'; END$$
CREATE TRIGGER trg_daily_action_no_delete BEFORE DELETE ON daily_booking_actions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Daily action history is append only'; END$$
CREATE TRIGGER trg_monthly_booking_mode_guard BEFORE INSERT ON bookings FOR EACH ROW
BEGIN
    DECLARE room_mode VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    SELECT rental_mode INTO room_mode FROM rooms WHERE id=NEW.room_id FOR UPDATE;
    IF room_mode<>'monthly' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Monthly booking requires monthly room'; END IF;
END$$
CREATE TRIGGER trg_monthly_occupancy_mode_guard BEFORE INSERT ON occupancies FOR EACH ROW
BEGIN
    DECLARE room_mode VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    SELECT rental_mode INTO room_mode FROM rooms WHERE id=NEW.room_id FOR UPDATE;
    IF room_mode<>'monthly' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Monthly occupancy requires monthly room'; END IF;
END$$
CREATE TRIGGER trg_daily_block_insert_guard BEFORE INSERT ON daily_room_blocks FOR EACH ROW
BEGIN
    DECLARE room_mode VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    SELECT rental_mode INTO room_mode FROM rooms WHERE id=NEW.room_id FOR UPDATE;
    IF room_mode<>'daily' OR EXISTS(SELECT 1 FROM daily_bookings WHERE room_id=NEW.room_id
        AND check_in_date<NEW.end_date AND check_out_date>NEW.start_date
        AND (status IN ('confirmed','checked_in','checked_out') OR (status='pending' AND expires_at>UTC_TIMESTAMP(6))))
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Block cannot overlap a daily booking'; END IF;
END$$
CREATE TRIGGER trg_daily_block_immutable BEFORE UPDATE ON daily_room_blocks FOR EACH ROW
BEGIN
    IF NOT(OLD.room_id<=>NEW.room_id) OR NOT(OLD.start_date<=>NEW.start_date) OR NOT(OLD.end_date<=>NEW.end_date)
       OR NOT(OLD.reason<=>NEW.reason) OR NOT(OLD.idempotency_key<=>NEW.idempotency_key)
       OR NOT(OLD.request_hash<=>NEW.request_hash) OR NOT(OLD.created_at<=>NEW.created_at)
       OR NOT(OLD.active=1 AND NEW.active=0 AND NEW.version=OLD.version+1)
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Room block history is immutable except release'; END IF;
END$$
CREATE TRIGGER trg_daily_housekeeping_no_update BEFORE UPDATE ON daily_housekeeping_actions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Housekeeping actions are append only'; END$$
CREATE TRIGGER trg_daily_housekeeping_no_delete BEFORE DELETE ON daily_housekeeping_actions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Housekeeping actions are append only'; END$$
CREATE TRIGGER trg_daily_block_no_delete BEFORE DELETE ON daily_room_blocks FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Room block history cannot be deleted'; END$$
CREATE TRIGGER trg_daily_booking_no_delete BEFORE DELETE ON daily_bookings FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Daily booking history cannot be deleted'; END$$
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

-- Completion marker is installed last because runtime users cannot inspect TRIGGER metadata.
SET @daily_booking_marker_sql=IF(EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='daily_bookings' AND constraint_name='chk_daily_booking_schema_v18'),'SELECT 1','ALTER TABLE daily_bookings ADD CONSTRAINT chk_daily_booking_schema_v18 CHECK (version>=1 AND CHAR_LENGTH(request_hash)=64 AND CHAR_LENGTH(access_token_hash)=64)');
PREPARE daily_booking_marker FROM @daily_booking_marker_sql; EXECUTE daily_booking_marker; DEALLOCATE PREPARE daily_booking_marker;
