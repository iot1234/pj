-- Remove the middle administrator role; only owners and residents can log in.
-- Apply after 016 with web, workers and scheduled jobs stopped. Back up and
-- test restoration first. Import without --force. Safe to rerun while stopped.
-- Legacy admin IDs, password hashes and every audit/financial reference remain.
-- They become permanently inactive historical rows, NEVER active owners.
-- If the legacy database has admins but no active real owner, first create an
-- explicit owner using scripts/create_admin.php --role=owner --password-stdin.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

SET @dormitory_017_required_tables := (
    SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
       AND table_name IN ('admin_users', 'transfer_instructions', 'line_admin_recipients', 'line_notice_outbox')
);
SET @dormitory_017_required_columns := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'admin_users'
       AND column_name IN ('id','username','password_hash','role','auth_version','active','created_by','created_at','updated_at')
);
SET @dormitory_017_role_shape := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'role'
       AND column_type IN ('enum(''owner'',''admin'')','enum(''owner'')') AND is_nullable = 'NO'
);
SET @dormitory_017_prerequisite_sql := IF(
    @dormitory_017_required_tables = 4 AND @dormitory_017_required_columns = 9 AND @dormitory_017_role_shape = 1,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_017_IMPORT_PRIOR_MIGRATIONS_FIRST'
);
PREPARE dormitory_017_prerequisite FROM @dormitory_017_prerequisite_sql;
EXECUTE dormitory_017_prerequisite;
DEALLOCATE PREPARE dormitory_017_prerequisite;

-- Validate a partially applied migration before changing data or any object.
SET @dormitory_017_retired_count := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'retired_at'
);
SET @dormitory_017_valid_retired_count := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'retired_at'
       AND column_type = 'datetime(6)' AND is_nullable = 'YES' AND column_default IS NULL
);
SET @dormitory_017_marker_guard_sql := IF(
    @dormitory_017_retired_count = 0 OR (@dormitory_017_retired_count = 1 AND @dormitory_017_valid_retired_count = 1),
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_017_INVALID_RETIRED_AT_COLUMN'
);
PREPARE dormitory_017_marker_guard FROM @dormitory_017_marker_guard_sql;
EXECUTE dormitory_017_marker_guard;
DEALLOCATE PREPARE dormitory_017_marker_guard;

SET @dormitory_017_constraint_conflicts := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND constraint_name IN ('chk_admin_users_owner','chk_admin_users_retired')
       AND (table_name <> 'admin_users' OR constraint_type <> 'CHECK')
);
SET @dormitory_017_constraint_guard_sql := IF(
    @dormitory_017_constraint_conflicts = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_017_CONFLICTING_CHECK_NAME'
);
PREPARE dormitory_017_constraint_guard FROM @dormitory_017_constraint_guard_sql;
EXECUTE dormitory_017_constraint_guard;
DEALLOCATE PREPARE dormitory_017_constraint_guard;

SET @dormitory_017_invalid_accounts := (
    SELECT COUNT(*) FROM admin_users
     WHERE role NOT IN ('owner','admin') OR active NOT IN (0,1) OR auth_version < 1
        OR (role = 'admin' AND auth_version = 4294967295)
);
SET @dormitory_017_invalid_retired_sql := IF(
    @dormitory_017_retired_count = 0,
    'SET @dormitory_017_invalid_retired := 0',
    'SELECT COUNT(*) INTO @dormitory_017_invalid_retired FROM admin_users WHERE retired_at IS NOT NULL AND active<>0'
);
PREPARE dormitory_017_invalid_retired FROM @dormitory_017_invalid_retired_sql;
EXECUTE dormitory_017_invalid_retired;
DEALLOCATE PREPARE dormitory_017_invalid_retired;
SET @dormitory_017_data_guard_sql := IF(
    @dormitory_017_invalid_accounts = 0 AND @dormitory_017_invalid_retired = 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_017_REPAIR_ACCOUNT_STATE_FIRST'
);
PREPARE dormitory_017_data_guard FROM @dormitory_017_data_guard_sql;
EXECUTE dormitory_017_data_guard;
DEALLOCATE PREPARE dormitory_017_data_guard;

-- Do not strand the operator or silently promote any administrator. This
-- guard runs before ADD COLUMN, UPDATE, ALTER or replacement of any trigger.
SET @dormitory_017_admin_count := (SELECT COUNT(*) FROM admin_users WHERE role = 'admin');
SET @dormitory_017_owner_count_sql := IF(
    @dormitory_017_retired_count = 0,
    'SELECT COUNT(*) INTO @dormitory_017_owner_count FROM admin_users WHERE role=''owner'' AND active=1',
    'SELECT COUNT(*) INTO @dormitory_017_owner_count FROM admin_users WHERE role=''owner'' AND active=1 AND retired_at IS NULL'
);
PREPARE dormitory_017_owner_count FROM @dormitory_017_owner_count_sql;
EXECUTE dormitory_017_owner_count;
DEALLOCATE PREPARE dormitory_017_owner_count;
SET @dormitory_017_owner_guard_sql := IF(
    @dormitory_017_admin_count = 0 OR @dormitory_017_owner_count > 0,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_017_CREATE_ACTIVE_OWNER_BEFORE_RETIRING_ADMINS'
);
PREPARE dormitory_017_owner_guard FROM @dormitory_017_owner_guard_sql;
EXECUTE dormitory_017_owner_guard;
DEALLOCATE PREPARE dormitory_017_owner_guard;

SET @dormitory_017_add_marker_sql := IF(
    @dormitory_017_retired_count = 0,
    'ALTER TABLE admin_users ADD COLUMN retired_at DATETIME(6) NULL AFTER active',
    'SELECT 1'
);
PREPARE dormitory_017_add_marker FROM @dormitory_017_add_marker_sql;
EXECUTE dormitory_017_add_marker;
DEALLOCATE PREPARE dormitory_017_add_marker;

-- role='owner' is solely the schema-compatible historical label here. The
-- permanent marker and CHECK make reactivation impossible; application list,
-- authentication and authorization explicitly exclude retired accounts.
UPDATE admin_users
   SET active = 0,
       auth_version = auth_version + 1,
       retired_at = COALESCE(retired_at, UTC_TIMESTAMP(6)),
       role = 'owner',
       updated_at = UTC_TIMESTAMP(6)
 WHERE role = 'admin';
ALTER TABLE admin_users MODIFY COLUMN role ENUM('owner') NOT NULL DEFAULT 'owner';

-- Staff LINE identities are a separate legacy access path. Preserve delivery
-- history, revoke invitations and fence queued/claimed notices before workers
-- restart. Previously sent rows remain untouched and are never sent again.
UPDATE line_admin_recipients
   SET enabled = 0,
       revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)),
       code_enc = NULL,
       updated_at = UTC_TIMESTAMP(6)
 WHERE (is_owner = 0 OR EXISTS (
           SELECT 1 FROM admin_users creator
            WHERE creator.id = line_admin_recipients.created_by AND creator.retired_at IS NOT NULL
       ))
   AND (enabled <> 0 OR revoked_at IS NULL OR code_enc IS NOT NULL);
UPDATE line_notice_outbox notice
  JOIN line_admin_recipients recipient ON recipient.id = notice.admin_recipient_id
   SET notice.status = 'failed',
       notice.claim_token = NULL,
       notice.lease_until = NULL,
       notice.last_error = 'ADMIN_ROLE_RETIRED',
       notice.updated_at = UTC_TIMESTAMP(6)
 WHERE (recipient.is_owner = 0 OR EXISTS (
           SELECT 1 FROM admin_users creator
            WHERE creator.id = recipient.created_by AND creator.retired_at IS NOT NULL
       )) AND notice.status IN ('pending','processing');
ALTER TABLE line_admin_recipients ALTER COLUMN is_owner SET DEFAULT 1;

-- Restore canonical checks, including a same-named weak/unenforced legacy one.
SET @dormitory_017_owner_check_count := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'admin_users'
       AND constraint_name = 'chk_admin_users_owner' AND constraint_type = 'CHECK'
);
SET @dormitory_017_drop_owner_check_sql := IF(@dormitory_017_owner_check_count = 1,
    'ALTER TABLE admin_users DROP CHECK chk_admin_users_owner', 'SELECT 1');
PREPARE dormitory_017_drop_owner_check FROM @dormitory_017_drop_owner_check_sql;
EXECUTE dormitory_017_drop_owner_check;
DEALLOCATE PREPARE dormitory_017_drop_owner_check;
SET @dormitory_017_retired_check_count := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'admin_users'
       AND constraint_name = 'chk_admin_users_retired' AND constraint_type = 'CHECK'
);
SET @dormitory_017_drop_retired_check_sql := IF(@dormitory_017_retired_check_count = 1,
    'ALTER TABLE admin_users DROP CHECK chk_admin_users_retired', 'SELECT 1');
PREPARE dormitory_017_drop_retired_check FROM @dormitory_017_drop_retired_check_sql;
EXECUTE dormitory_017_drop_retired_check;
DEALLOCATE PREPARE dormitory_017_drop_retired_check;
ALTER TABLE admin_users ADD CONSTRAINT chk_admin_users_owner CHECK (role = 'owner');
ALTER TABLE admin_users ADD CONSTRAINT chk_admin_users_retired CHECK (retired_at IS NULL OR active = 0);

DROP TRIGGER IF EXISTS trg_admin_users_retirement_immutable;
DELIMITER $$
CREATE TRIGGER trg_admin_users_retirement_immutable
BEFORE UPDATE ON admin_users
FOR EACH ROW
BEGIN
    IF OLD.retired_at IS NOT NULL AND NOT (NEW.retired_at <=> OLD.retired_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RETIRED_ACCOUNT_IMMUTABLE';
    END IF;
END$$
DELIMITER ;

SET @dormitory_017_valid_role := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'admin_users' AND column_name = 'role'
       AND column_type = 'enum(''owner'')' AND is_nullable = 'NO' AND column_default = 'owner'
);
SET @dormitory_017_enforced_checks := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'admin_users' AND constraint_type = 'CHECK'
       AND constraint_name IN ('chk_admin_users_owner','chk_admin_users_retired') AND enforced = 'YES'
);
SET @dormitory_017_postcondition_sql := IF(
    @dormitory_017_valid_role = 1 AND @dormitory_017_enforced_checks = 2,
    'SELECT 1',
    'SELECT * FROM information_schema.DORMITORY_017_OWNER_ONLY_POSTCONDITION_FAILED'
);
PREPARE dormitory_017_postcondition FROM @dormitory_017_postcondition_sql;
EXECUTE dormitory_017_postcondition;
DEALLOCATE PREPARE dormitory_017_postcondition;
