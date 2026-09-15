-- Canonical LINE platform extension. Generated copies are embedded in schema.sql
-- and migration 014 by scripts/build_install_sql.php. Edit this source only.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS line_official_accounts (
    id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    basic_id VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin NULL,
    channel_id VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL,
    add_friend_url VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    access_token_enc TEXT CHARACTER SET ascii COLLATE ascii_bin NULL,
    channel_secret_enc TEXT CHARACTER SET ascii COLLATE ascii_bin NULL,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_default TINYINT UNSIGNED NOT NULL DEFAULT 0,
    legacy_route_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    route_token CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_user_id VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin NULL,
    token_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    identity_verified_at DATETIME(6) NULL,
    last_seen_at DATETIME(6) NULL,
    last_error VARCHAR(100) NULL,
    deleted_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    active_default_key TINYINT GENERATED ALWAYS AS (CASE WHEN is_default=1 AND deleted_at IS NULL THEN 1 ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_line_official_accounts_slug (slug),
    UNIQUE KEY uq_line_official_accounts_route (route_token),
    UNIQUE KEY uq_line_official_accounts_provider (provider_user_id),
    UNIQUE KEY uq_line_official_accounts_default (active_default_key),
    CONSTRAINT fk_line_official_accounts_creator FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_line_official_accounts_editor FOREIGN KEY (updated_by) REFERENCES admin_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_line_official_accounts_slug CHECK (slug REGEXP '^[a-z0-9][a-z0-9_-]{0,39}$'),
    CONSTRAINT chk_line_official_accounts_name CHECK (CHAR_LENGTH(TRIM(name)) BETWEEN 1 AND 120),
    CONSTRAINT chk_line_official_accounts_basic CHECK (basic_id IS NULL OR basic_id REGEXP '^@[A-Za-z0-9._-]{1,32}$'),
    CONSTRAINT chk_line_official_accounts_channel CHECK (channel_id IS NULL OR channel_id REGEXP '^[0-9]{1,60}$'),
    CONSTRAINT chk_line_official_accounts_flags CHECK (enabled IN(0,1) AND is_default IN(0,1) AND legacy_route_enabled IN(0,1) AND (id=0 OR legacy_route_enabled=0)),
    CONSTRAINT chk_line_official_accounts_route CHECK (route_token REGEXP '^[0-9a-f]{48}$'),
    CONSTRAINT chk_line_official_accounts_provider CHECK (provider_user_id IS NULL OR provider_user_id REGEXP '^U[0-9a-f]{32}$'),
    CONSTRAINT chk_line_official_accounts_fingerprint CHECK (token_fingerprint IS NULL OR token_fingerprint REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_line_official_accounts_deleted CHECK (deleted_at IS NULL OR (enabled=0 AND is_default=0)),
    CONSTRAINT chk_line_official_accounts_legacy CHECK (id<>0 OR (basic_id IS NULL AND access_token_enc IS NULL AND channel_secret_enc IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OA 0 delegates its public identity and encrypted credentials to the existing
-- integration singleton. This seed never copies or changes those credentials.
INSERT IGNORE INTO line_official_accounts (id,slug,name,enabled,is_default,legacy_route_enabled,route_token)
VALUES (0,'legacy','LINE เดิมของหอพัก',1,1,1,LOWER(HEX(RANDOM_BYTES(24))));

CREATE TABLE IF NOT EXISTS line_room_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resident_id BIGINT UNSIGNED NOT NULL,
    occupancy_id BIGINT UNSIGNED NOT NULL,
    auth_version BIGINT UNSIGNED NOT NULL,
    oa_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    code_enc TEXT CHARACTER SET ascii COLLATE ascii_bin NULL,
    status ENUM('pending','bound','expired','revoked') NOT NULL DEFAULT 'pending',
    line_user_id VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin NULL,
    proof_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expires_at DATETIME(6) NOT NULL,
    bound_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    active_line_user_id VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN status='bound' THEN line_user_id ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_line_room_bindings_code_hash (code_hash),
    UNIQUE KEY uq_line_room_bindings_oa_user (oa_id,active_line_user_id),
    KEY idx_line_room_bindings_resident (resident_id,status,created_at),
    KEY idx_line_room_bindings_expiry (status,expires_at),
    CONSTRAINT fk_line_room_bindings_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_line_room_bindings_occupancy FOREIGN KEY (occupancy_id) REFERENCES occupancies(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_line_room_bindings_oa FOREIGN KEY (oa_id) REFERENCES line_official_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_line_room_bindings_creator FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_line_room_bindings_hash CHECK (code_hash REGEXP '^[0-9a-f]{64}$' AND (proof_hash IS NULL OR proof_hash REGEXP '^[0-9a-f]{64}$')),
    CONSTRAINT chk_line_room_bindings_identity CHECK (auth_version>=1 AND (line_user_id IS NULL OR line_user_id REGEXP '^U[0-9a-f]{32}$')),
    CONSTRAINT chk_line_room_bindings_state CHECK (
      (status='pending' AND line_user_id IS NULL AND proof_hash IS NULL AND bound_at IS NULL AND revoked_at IS NULL)
      OR (status='bound' AND line_user_id IS NOT NULL AND proof_hash IS NOT NULL AND bound_at IS NOT NULL AND revoked_at IS NULL)
      OR (status='expired' AND line_user_id IS NULL AND proof_hash IS NULL AND bound_at IS NULL AND revoked_at IS NULL)
      OR (status='revoked' AND revoked_at IS NOT NULL AND ((line_user_id IS NULL AND bound_at IS NULL AND proof_hash IS NULL) OR (line_user_id IS NOT NULL AND bound_at IS NOT NULL AND proof_hash IS NOT NULL)))
    ),
    CONSTRAINT chk_line_room_bindings_time CHECK (expires_at>created_at AND (bound_at IS NULL OR (bound_at>=created_at AND bound_at<expires_at)) AND (revoked_at IS NULL OR revoked_at>=created_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS line_room_policies (
    resident_id BIGINT UNSIGNED NOT NULL,
    blocked TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(500) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (resident_id),
    CONSTRAINT fk_line_room_policies_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_line_room_policies_editor FOREIGN KEY (updated_by) REFERENCES admin_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_line_room_policies_blocked CHECK (blocked IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS line_admin_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    oa_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    is_owner TINYINT UNSIGNED NOT NULL DEFAULT 0,
    enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
    muted_categories JSON NOT NULL,
    line_user_id VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    code_enc TEXT CHARACTER SET ascii COLLATE ascii_bin NULL,
    expires_at DATETIME(6) NULL,
    claimed_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_line_admin_recipients_code_hash (code_hash),
    KEY idx_line_admin_recipients_oa (oa_id,enabled),
    CONSTRAINT fk_line_admin_recipients_oa FOREIGN KEY (oa_id) REFERENCES line_official_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_line_admin_recipients_creator FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_line_admin_recipients_flags CHECK (is_owner IN(0,1) AND enabled IN(0,1)),
    CONSTRAINT chk_line_admin_recipients_label CHECK (CHAR_LENGTH(TRIM(label)) BETWEEN 1 AND 120),
    CONSTRAINT chk_line_admin_recipients_mutes CHECK (JSON_TYPE(muted_categories)='ARRAY'),
    CONSTRAINT chk_line_admin_recipients_hash CHECK (code_hash IS NULL OR code_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_line_admin_recipients_identity CHECK (line_user_id IS NULL OR line_user_id REGEXP '^U[0-9a-f]{32}$'),
    CONSTRAINT chk_line_admin_recipients_claim CHECK ((claimed_at IS NULL AND line_user_id IS NULL) OR (claimed_at IS NOT NULL AND line_user_id IS NOT NULL)),
    CONSTRAINT chk_line_admin_recipients_revoked CHECK (revoked_at IS NULL OR enabled=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS line_notice_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    oa_id BIGINT UNSIGNED NOT NULL,
    resident_id BIGINT UNSIGNED NULL,
    binding_id BIGINT UNSIGNED NULL,
    admin_recipient_id BIGINT UNSIGNED NULL,
    recipient VARCHAR(33) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    category VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message_enc TEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    retry_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dedupe_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claim_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_until DATETIME(6) NULL,
    next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_error VARCHAR(200) NULL,
    sent_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_line_notice_outbox_retry_key (retry_key),
    UNIQUE KEY uq_line_notice_outbox_dedupe_key (dedupe_key),
    KEY idx_line_notice_outbox_due (status,next_attempt_at),
    KEY idx_line_notice_outbox_lease (status,lease_until),
    CONSTRAINT fk_line_notice_outbox_oa FOREIGN KEY (oa_id) REFERENCES line_official_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_line_notice_outbox_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_line_notice_outbox_binding FOREIGN KEY (binding_id) REFERENCES line_room_bindings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_line_notice_outbox_admin FOREIGN KEY (admin_recipient_id) REFERENCES line_admin_recipients(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_line_notice_outbox_recipient CHECK (recipient REGEXP '^U[0-9a-f]{32}$'),
    CONSTRAINT chk_line_notice_outbox_category CHECK (category REGEXP '^[a-z][a-z0-9_]{0,19}$'),
    CONSTRAINT chk_line_notice_outbox_source CHECK ((admin_recipient_id IS NOT NULL AND resident_id IS NULL AND binding_id IS NULL) OR (admin_recipient_id IS NULL AND resident_id IS NOT NULL)),
    CONSTRAINT chk_line_notice_outbox_retry CHECK (retry_key REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT chk_line_notice_outbox_dedupe CHECK (dedupe_key IS NULL OR dedupe_key REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT chk_line_notice_outbox_attempts CHECK (attempts<=100),
    CONSTRAINT chk_line_notice_outbox_lease CHECK ((status='processing' AND claim_token IS NOT NULL AND claim_token REGEXP '^[0-9a-f]{64}$' AND lease_until IS NOT NULL) OR (status<>'processing' AND claim_token IS NULL AND lease_until IS NULL)),
    CONSTRAINT chk_line_notice_outbox_sent CHECK ((status='sent' AND sent_at IS NOT NULL) OR (status<>'sent' AND sent_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The original notification rows remain pinned to legacy OA 0. Every DDL is
-- conditional for a maintenance rerun; readiness checks still detect drift.
SET @line_014_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notification_outbox' AND column_name='line_oa_id')=0,
 'ALTER TABLE notification_outbox ADD COLUMN line_oa_id BIGINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE line_014_stmt FROM @line_014_sql; EXECUTE line_014_stmt; DEALLOCATE PREPARE line_014_stmt;
SET @line_014_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notification_outbox' AND column_name='line_binding_id')=0,
 'ALTER TABLE notification_outbox ADD COLUMN line_binding_id BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE line_014_stmt FROM @line_014_sql; EXECUTE line_014_stmt; DEALLOCATE PREPARE line_014_stmt;
SET @line_014_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notification_outbox' AND column_name='line_delivery_key')=0,
 'ALTER TABLE notification_outbox ADD COLUMN line_delivery_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(line_binding_id,0)) STORED', 'SELECT 1');
PREPARE line_014_stmt FROM @line_014_sql; EXECUTE line_014_stmt; DEALLOCATE PREPARE line_014_stmt;
SET @line_014_sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='notification_outbox' AND index_name='uq_notification_outbox_bill_purpose' AND seq_in_index=1)=1,
 'ALTER TABLE notification_outbox DROP INDEX uq_notification_outbox_bill_purpose, ADD UNIQUE KEY uq_notification_outbox_bill_binding (bill_id,purpose,line_delivery_key)', 'SELECT 1');
PREPARE line_014_stmt FROM @line_014_sql; EXECUTE line_014_stmt; DEALLOCATE PREPARE line_014_stmt;
SET @line_014_sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='notification_outbox' AND constraint_name='fk_notification_outbox_oa')=0,
 'ALTER TABLE notification_outbox ADD CONSTRAINT fk_notification_outbox_oa FOREIGN KEY (line_oa_id) REFERENCES line_official_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT', 'SELECT 1');
PREPARE line_014_stmt FROM @line_014_sql; EXECUTE line_014_stmt; DEALLOCATE PREPARE line_014_stmt;
SET @line_014_sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='notification_outbox' AND constraint_name='fk_notification_outbox_line_binding')=0,
 'ALTER TABLE notification_outbox ADD CONSTRAINT fk_notification_outbox_line_binding FOREIGN KEY (line_binding_id) REFERENCES line_room_bindings(id) ON DELETE RESTRICT ON UPDATE RESTRICT', 'SELECT 1');
PREPARE line_014_stmt FROM @line_014_sql; EXECUTE line_014_stmt; DEALLOCATE PREPARE line_014_stmt;

DROP TRIGGER IF EXISTS trg_line_room_bindings_insert_guard;
DROP TRIGGER IF EXISTS trg_line_room_bindings_update_guard;
DROP TRIGGER IF EXISTS trg_line_notice_relationship_guard;
DROP TRIGGER IF EXISTS trg_line_notice_relationship_guard_update;
DROP TRIGGER IF EXISTS trg_notification_relationship_guard;
DROP TRIGGER IF EXISTS trg_notification_relationship_guard_update;
DELIMITER $$
CREATE TRIGGER trg_line_room_bindings_insert_guard BEFORE INSERT ON line_room_bindings FOR EACH ROW
BEGIN
    DECLARE occupancy_resident BIGINT UNSIGNED DEFAULT NULL;
    SELECT resident_id INTO occupancy_resident FROM occupancies WHERE id=NEW.occupancy_id LIMIT 1;
    IF NOT (occupancy_resident <=> NEW.resident_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='LINE binding resident must match its occupancy';
    END IF;
END$$
CREATE TRIGGER trg_line_room_bindings_update_guard BEFORE UPDATE ON line_room_bindings FOR EACH ROW
BEGIN
    IF NOT (OLD.resident_id <=> NEW.resident_id) OR NOT (OLD.occupancy_id <=> NEW.occupancy_id)
       OR NOT (OLD.oa_id <=> NEW.oa_id) OR NOT (OLD.auth_version <=> NEW.auth_version)
       OR NOT (OLD.code_hash <=> NEW.code_hash) OR (OLD.status IN('bound','revoked','expired') AND NEW.status NOT IN(OLD.status,'revoked'))
       OR (OLD.line_user_id IS NOT NULL AND NOT (OLD.line_user_id <=> NEW.line_user_id))
       OR (OLD.proof_hash IS NOT NULL AND NOT (OLD.proof_hash <=> NEW.proof_hash)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='LINE binding identity and completed proof are immutable';
    END IF;
END$$
CREATE TRIGGER trg_line_notice_relationship_guard BEFORE INSERT ON line_notice_outbox FOR EACH ROW
BEGIN
    DECLARE binding_oa BIGINT UNSIGNED DEFAULT NULL;
    DECLARE binding_resident BIGINT UNSIGNED DEFAULT NULL;
    DECLARE contact_oa BIGINT UNSIGNED DEFAULT NULL;
    IF NEW.binding_id IS NOT NULL THEN
      SELECT oa_id,resident_id INTO binding_oa,binding_resident FROM line_room_bindings WHERE id=NEW.binding_id LIMIT 1;
      IF NOT (binding_oa <=> NEW.oa_id) OR NOT (binding_resident <=> NEW.resident_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='LINE notice binding must match its OA and resident';
      END IF;
    ELSEIF NEW.resident_id IS NOT NULL AND NEW.oa_id<>0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Nonlegacy resident notice requires its binding';
    END IF;
    IF NEW.admin_recipient_id IS NOT NULL THEN
      SELECT oa_id INTO contact_oa FROM line_admin_recipients WHERE id=NEW.admin_recipient_id LIMIT 1;
      IF NOT (contact_oa <=> NEW.oa_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='LINE notice admin recipient must match its OA'; END IF;
    END IF;
END$$
CREATE TRIGGER trg_line_notice_relationship_guard_update BEFORE UPDATE ON line_notice_outbox FOR EACH ROW
BEGIN
    IF NOT (OLD.oa_id <=> NEW.oa_id) OR NOT (OLD.resident_id <=> NEW.resident_id) OR NOT (OLD.binding_id <=> NEW.binding_id)
       OR NOT (OLD.admin_recipient_id <=> NEW.admin_recipient_id) OR NOT (OLD.recipient <=> NEW.recipient)
       OR NOT (OLD.category <=> NEW.category) OR NOT (OLD.retry_key <=> NEW.retry_key) OR NOT (OLD.dedupe_key <=> NEW.dedupe_key)
       OR (OLD.attempts>0 AND NOT (OLD.message_enc <=> NEW.message_enc)) OR (OLD.status='sent' AND NEW.status<>'sent') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='LINE notice destination and attempted message are immutable';
    END IF;
END$$
CREATE TRIGGER trg_notification_relationship_guard BEFORE INSERT ON notification_outbox FOR EACH ROW
BEGIN
    DECLARE bill_resident BIGINT UNSIGNED DEFAULT NULL;
    DECLARE bill_occupancy BIGINT UNSIGNED DEFAULT NULL;
    DECLARE binding_resident BIGINT UNSIGNED DEFAULT NULL;
    DECLARE binding_occupancy BIGINT UNSIGNED DEFAULT NULL;
    DECLARE binding_oa BIGINT UNSIGNED DEFAULT NULL;
    SELECT resident_id,occupancy_id INTO bill_resident,bill_occupancy FROM bills WHERE id=NEW.bill_id LIMIT 1;
    IF NOT (bill_resident <=> NEW.resident_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Notification resident must match its bill'; END IF;
    IF NEW.line_binding_id IS NOT NULL THEN
      SELECT resident_id,occupancy_id,oa_id INTO binding_resident,binding_occupancy,binding_oa FROM line_room_bindings WHERE id=NEW.line_binding_id LIMIT 1;
      IF NOT (binding_resident <=> NEW.resident_id) OR NOT (binding_occupancy <=> bill_occupancy) OR NOT (binding_oa <=> NEW.line_oa_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Notification binding must match its bill occupancy and OA';
      END IF;
    ELSEIF NEW.line_oa_id<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Nonlegacy notification requires its binding';
    END IF;
END$$
CREATE TRIGGER trg_notification_relationship_guard_update BEFORE UPDATE ON notification_outbox FOR EACH ROW
BEGIN
    DECLARE bill_resident BIGINT UNSIGNED DEFAULT NULL;
    SELECT resident_id INTO bill_resident FROM bills WHERE id=NEW.bill_id LIMIT 1;
    IF NOT (OLD.bill_id <=> NEW.bill_id) OR NOT (bill_resident <=> NEW.resident_id)
       OR NOT (OLD.line_oa_id <=> NEW.line_oa_id) OR NOT (OLD.line_binding_id <=> NEW.line_binding_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Notification resident must match its immutable bill and OA binding';
    END IF;
END$$
DELIMITER ;
