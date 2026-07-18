-- Required, idempotent baseline for a new deployment.
-- Safe for production: no room, resident, booking, bill, payment, admin,
-- password, PromptPay number, LINE token or slip-provider credential.
--
-- Water/electric rates intentionally start at zero and updated_by stays NULL.
-- An administrator must review and save the real billing settings before
-- issuing the first bill.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

INSERT IGNORE INTO billing_settings
    (id, water_rate, electric_rate, due_days, updated_by)
VALUES
    (1, 0.00, 0.00, 7, NULL);

-- Operational credentials start empty and are configured by an owner at
-- Admin -> Settings. No integration secret is stored in source control.
INSERT IGNORE INTO integration_settings
    (id, line_max_attempts, notification_batch_size, slip_provider,
     slip_max_bytes, slip_time_tolerance_seconds, updated_by)
VALUES
    (1, 5, 25, 'none', 4194304, 300, NULL);
