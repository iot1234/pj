-- Upgrade an existing php-mysql deployment to DB-backed integration settings.
-- Run against the selected application database as a schema-owning account.
-- This migration is idempotent and stores no credentials by itself.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS integration_settings (
    id TINYINT UNSIGNED NOT NULL,
    promptpay_target VARCHAR(13) CHARACTER SET ascii COLLATE ascii_bin NULL,
    promptpay_name VARCHAR(120) NULL,
    payment_receiver_account_tail VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    line_channel_access_token_enc TEXT CHARACTER SET ascii COLLATE ascii_bin NULL,
    line_max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    notification_batch_size TINYINT UNSIGNED NOT NULL DEFAULT 25,
    slip_provider ENUM('none', 'slipok', 'easyslip') NOT NULL DEFAULT 'none',
    slipok_api_key_enc TEXT CHARACTER SET ascii COLLATE ascii_bin NULL,
    slipok_branch_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    easyslip_api_key_enc TEXT CHARACTER SET ascii COLLATE ascii_bin NULL,
    slip_max_bytes INT UNSIGNED NOT NULL DEFAULT 4194304,
    slip_time_tolerance_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 300,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    CONSTRAINT fk_integration_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES admin_users(id)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT chk_integration_settings_singleton CHECK (id = 1),
    CONSTRAINT chk_integration_settings_promptpay CHECK (
        promptpay_target IS NULL OR promptpay_target REGEXP '^(0[0-9]{9}|[0-9]{13})$'
    ),
    CONSTRAINT chk_integration_settings_promptpay_name CHECK (
        promptpay_name IS NULL OR CHAR_LENGTH(TRIM(promptpay_name)) BETWEEN 1 AND 120
    ),
    CONSTRAINT chk_integration_settings_receiver_tail CHECK (
        payment_receiver_account_tail IS NULL OR payment_receiver_account_tail REGEXP '^[0-9]{6,20}$'
    ),
    CONSTRAINT chk_integration_settings_line_token CHECK (
        line_channel_access_token_enc IS NULL OR CHAR_LENGTH(line_channel_access_token_enc) BETWEEN 20 AND 65000
    ),
    CONSTRAINT chk_integration_settings_line_attempts CHECK (line_max_attempts BETWEEN 1 AND 20),
    CONSTRAINT chk_integration_settings_batch CHECK (notification_batch_size BETWEEN 1 AND 100),
    CONSTRAINT chk_integration_settings_slipok_key CHECK (
        slipok_api_key_enc IS NULL OR CHAR_LENGTH(slipok_api_key_enc) BETWEEN 20 AND 65000
    ),
    CONSTRAINT chk_integration_settings_slipok_branch CHECK (
        slipok_branch_id IS NULL OR slipok_branch_id REGEXP '^[A-Za-z0-9_-]{1,80}$'
    ),
    CONSTRAINT chk_integration_settings_easyslip_key CHECK (
        easyslip_api_key_enc IS NULL OR CHAR_LENGTH(easyslip_api_key_enc) BETWEEN 20 AND 65000
    ),
    CONSTRAINT chk_integration_settings_slip_size CHECK (slip_max_bytes BETWEEN 1024 AND 4194304),
    CONSTRAINT chk_integration_settings_slip_time CHECK (slip_time_tolerance_seconds BETWEEN 0 AND 3600)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO integration_settings
    (id, line_max_attempts, notification_batch_size, slip_provider,
     slip_max_bytes, slip_time_tolerance_seconds, updated_by)
VALUES
    (1, 5, 25, 'none', 4194304, 300, NULL);
