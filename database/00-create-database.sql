-- Advanced/manual fresh-install step. For the simplest new installation,
-- import database/install.sql once instead. Run this standalone file from the
-- phpMyAdmin server-level SQL tab only when the `dormitory` database does not
-- exist yet. It intentionally creates no user and grants no privileges.
--
-- Required server: Oracle MySQL 8.0.16+ (not MariaDB).

CREATE DATABASE IF NOT EXISTS dormitory
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE dormitory;

SELECT DATABASE() AS selected_database, VERSION() AS mysql_version;

-- Next, keep `dormitory` selected in phpMyAdmin and import:
--   1. database/schema.sql
--   2. database/defaults.sql
-- Import database/demo.sql only on an isolated local-development database.
