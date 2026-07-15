#!/usr/bin/env bash
set -Eeuo pipefail
set +x

umask 077
unset MYSQL_PWD

log() {
    printf '%s\n' "bootstrap_database: $*" >&2
}

die() {
    log "$*"
    exit 1
}

require_env() {
    local name="$1"
    [[ -n "${!name:-}" ]] || die "${name} must be set and non-empty"
}

require_env DB_DATABASE

# A one-time Railway job may receive final runtime DB_* values before that
# account exists. When any DBA value is supplied, require the complete set and
# use it only inside this process. Otherwise fall back to DB_* for local Docker
# bootstrap and explicit schema-audit runs.
if [[ -n "${DB_DBA_HOST:-}${DB_DBA_PORT:-}${DB_DBA_USERNAME:-}${DB_DBA_PASSWORD:-}" ]]; then
    for required_name in DB_DBA_HOST DB_DBA_PORT DB_DBA_USERNAME DB_DBA_PASSWORD; do
        require_env "$required_name"
    done
    DB_HOST="$DB_DBA_HOST"
    DB_PORT="$DB_DBA_PORT"
    DB_USERNAME="$DB_DBA_USERNAME"
    DB_PASSWORD="$DB_DBA_PASSWORD"
else
    for required_name in DB_HOST DB_USERNAME DB_PASSWORD; do
        require_env "$required_name"
    done
fi

DB_PORT="${DB_PORT:-3306}"
DB_CONNECT_RETRIES="${DB_CONNECT_RETRIES:-30}"
DB_CONNECT_RETRY_SECONDS="${DB_CONNECT_RETRY_SECONDS:-2}"

[[ ${#DB_HOST} -le 255 && "$DB_HOST" =~ ^[A-Za-z0-9._:-]+$ && "$DB_HOST" != -* ]] \
    || die "DB_HOST must be a valid hostname or IP address"
[[ "$DB_DATABASE" =~ ^[A-Za-z0-9_]{1,64}$ ]] \
    || die "DB_DATABASE must contain only A-Z, a-z, 0-9, or underscore (maximum 64 characters)"
[[ "$DB_USERNAME" =~ ^[A-Za-z0-9_]{1,32}$ ]] \
    || die "DB_USERNAME must contain only A-Z, a-z, 0-9, or underscore (maximum 32 characters)"
[[ ${#DB_PASSWORD} -le 4096 && "$DB_PASSWORD" != *$'\n'* && "$DB_PASSWORD" != *$'\r'* ]] \
    || die "DB_PASSWORD must not contain a newline and must be at most 4096 characters"
[[ "$DB_PORT" =~ ^[0-9]{1,5}$ ]] || die "DB_PORT must be an integer from 1 to 65535"
DB_PORT_NUMBER=$((10#$DB_PORT))
(( DB_PORT_NUMBER >= 1 && DB_PORT_NUMBER <= 65535 )) \
    || die "DB_PORT must be an integer from 1 to 65535"
[[ "$DB_CONNECT_RETRIES" =~ ^[0-9]{1,3}$ ]] \
    || die "DB_CONNECT_RETRIES must be an integer from 1 to 120"
DB_CONNECT_RETRIES_NUMBER=$((10#$DB_CONNECT_RETRIES))
(( DB_CONNECT_RETRIES_NUMBER >= 1 && DB_CONNECT_RETRIES_NUMBER <= 120 )) \
    || die "DB_CONNECT_RETRIES must be an integer from 1 to 120"
[[ "$DB_CONNECT_RETRY_SECONDS" =~ ^[0-9]{1,2}$ ]] \
    || die "DB_CONNECT_RETRY_SECONDS must be an integer from 0 to 60"
DB_CONNECT_RETRY_SECONDS_NUMBER=$((10#$DB_CONNECT_RETRY_SECONDS))
(( DB_CONNECT_RETRY_SECONDS_NUMBER >= 0 && DB_CONNECT_RETRY_SECONDS_NUMBER <= 60 )) \
    || die "DB_CONNECT_RETRY_SECONDS must be an integer from 0 to 60"

command -v mysql >/dev/null 2>&1 || die "mysql CLI is required"

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
project_root="$(CDPATH= cd -- "${script_dir}/.." && pwd)"
schema_file="${project_root}/database/schema.sql"
defaults_file="${project_root}/database/defaults.sql"

[[ -r "$schema_file" ]] || die "database/schema.sql is missing or unreadable"
[[ -r "$defaults_file" ]] || die "database/defaults.sql is missing or unreadable"

mysql_args=(
    --no-defaults
    --protocol=TCP
    "--host=${DB_HOST}"
    "--port=${DB_PORT_NUMBER}"
    "--user=${DB_USERNAME}"
    "--database=${DB_DATABASE}"
    --connect-timeout=5
    --default-character-set=utf8mb4
    --batch
    --skip-column-names
    --raw
)

mysql_cli() {
    MYSQL_PWD="$DB_PASSWORD" mysql "${mysql_args[@]}" "$@"
}

mysql_query() {
    mysql_cli --execute="$1"
}

connected=false
for (( attempt = 1; attempt <= DB_CONNECT_RETRIES_NUMBER; attempt++ )); do
    if mysql_query 'SELECT 1' >/dev/null 2>&1; then
        connected=true
        break
    fi
    if (( attempt < DB_CONNECT_RETRIES_NUMBER )); then
        log "database is not ready (attempt ${attempt}/${DB_CONNECT_RETRIES_NUMBER}); retrying"
        sleep "$DB_CONNECT_RETRY_SECONDS_NUMBER"
    fi
done
[[ "$connected" == true ]] \
    || die "could not connect to the configured database after ${DB_CONNECT_RETRIES_NUMBER} attempts"

server_version="$(mysql_query 'SELECT VERSION()')"
[[ "${server_version,,}" != *mariadb* ]] || die "MariaDB is not supported; MySQL 8.0.16 or newer is required"
if [[ "$server_version" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+) ]]; then
    version_major=$((10#${BASH_REMATCH[1]}))
    version_minor=$((10#${BASH_REMATCH[2]}))
    version_patch=$((10#${BASH_REMATCH[3]}))
else
    die "could not validate the MySQL server version"
fi
if (( version_major < 8 \
    || (version_major == 8 && version_minor == 0 && version_patch < 16) )); then
    die "MySQL 8.0.16 or newer is required"
fi

expected_tables=$'admin_users\naudit_logs\nbill_items\nbilling_settings\nbills\nbookings\nintegration_settings\nmeter_readings\nnotification_outbox\noccupancies\npayments\nrate_limits\nresidents\nrooms'
expected_triggers=$'trg_audit_logs_no_delete|BEFORE|DELETE|audit_logs\ntrg_audit_logs_no_update|BEFORE|UPDATE|audit_logs\ntrg_bill_items_insert_guard|BEFORE|INSERT|bill_items\ntrg_bill_items_no_delete|BEFORE|DELETE|bill_items\ntrg_bill_items_no_update|BEFORE|UPDATE|bill_items\ntrg_bills_no_delete|BEFORE|DELETE|bills\ntrg_bills_relationship_guard|BEFORE|INSERT|bills\ntrg_bills_snapshot_immutable|BEFORE|UPDATE|bills\ntrg_bookings_identity_immutable|BEFORE|UPDATE|bookings\ntrg_notification_relationship_guard|BEFORE|INSERT|notification_outbox\ntrg_notification_relationship_guard_update|BEFORE|UPDATE|notification_outbox\ntrg_occupancies_identity_immutable|BEFORE|UPDATE|occupancies\ntrg_payments_final_immutable|BEFORE|UPDATE|payments\ntrg_payments_no_delete|BEFORE|DELETE|payments\ntrg_payments_relationship_guard|BEFORE|INSERT|payments'
expected_hardening_columns=$'bills.resident_name_snapshot|varchar(150)|NO\nbills.room_code_snapshot|varchar(32)|NO\nbookings.booked_monthly_rent|decimal(12,2)|NO\npayments.verification_attempts|smallint unsigned|NO\npayments.verification_lease_until|datetime(6)|YES\npayments.verification_token|char(64)|YES'
expected_generated_columns=$'bookings.active_room_id\noccupancies.active_resident_id\noccupancies.active_room_id\npayments.active_bill_id'

verify_schema_and_defaults() {
    local object_count base_table_count actual_tables actual_triggers
    local actual_hardening_columns actual_generated_columns bill_item_index
    local check_constraint_count billing_row_count integration_row_count

    object_count="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")"
    base_table_count="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")"
    [[ "$object_count" == 14 && "$base_table_count" == 14 ]] \
        || die "database is nonempty but does not contain exactly the required 14 base tables; refusing to modify it"

    actual_tables="$(mysql_query "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")"
    [[ "$actual_tables" == "$expected_tables" ]] \
        || die "database has a partial or incompatible table set; refusing to modify it"

    actual_triggers="$(mysql_query "SELECT CONCAT(trigger_name, '|', action_timing, '|', event_manipulation, '|', event_object_table) FROM information_schema.triggers WHERE trigger_schema = DATABASE() ORDER BY trigger_name")"
    [[ "$actual_triggers" == "$expected_triggers" ]] \
        || die "database has missing or incompatible integrity triggers; refusing to modify it"

    actual_hardening_columns="$(mysql_query "SELECT CONCAT(table_name, '.', column_name, '|', LOWER(column_type), '|', is_nullable) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'bookings' AND column_name = 'booked_monthly_rent') OR (table_name = 'bills' AND column_name IN ('resident_name_snapshot', 'room_code_snapshot')) OR (table_name = 'payments' AND column_name IN ('verification_lease_until', 'verification_token', 'verification_attempts'))) ORDER BY table_name, column_name")"
    [[ "$actual_hardening_columns" == "$expected_hardening_columns" ]] \
        || die "database is missing required operational-hardening columns or their types do not match"

    actual_generated_columns="$(mysql_query "SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns WHERE table_schema = DATABASE() AND extra LIKE '%STORED GENERATED%' ORDER BY table_name, column_name")"
    [[ "$actual_generated_columns" == "$expected_generated_columns" ]] \
        || die "database has missing or incompatible generated uniqueness guards"

    bill_item_index="$(mysql_query "SELECT column_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'bill_items' AND index_name = 'uq_bill_items_bill_type' AND non_unique = 0 ORDER BY seq_in_index")"
    [[ "$bill_item_index" == $'bill_id\nitem_type' ]] \
        || die "database is missing the required unique bill item index"

    check_constraint_count="$(mysql_query "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK'")"
    [[ "$check_constraint_count" =~ ^[0-9]+$ ]] \
        || die "could not validate CHECK constraints"
    (( check_constraint_count >= 70 )) \
        || die "database has fewer than the required 70 CHECK constraints"

    billing_row_count="$(mysql_query 'SELECT COUNT(*) FROM billing_settings WHERE id = 1')"
    integration_row_count="$(mysql_query 'SELECT COUNT(*) FROM integration_settings WHERE id = 1')"
    [[ "$billing_row_count" == 1 && "$integration_row_count" == 1 ]] \
        || die "database is missing required billing or integration settings defaults"
    [[ "$(mysql_query 'SELECT COUNT(*) FROM billing_settings')" == 1 \
        && "$(mysql_query 'SELECT COUNT(*) FROM integration_settings')" == 1 ]] \
        || die "settings tables violate the required singleton layout"

    mysql_query 'SELECT COUNT(*) FROM (SELECT water_rate, electric_rate, due_days, updated_by, updated_at FROM billing_settings WHERE id = 1) AS required_billing_settings' >/dev/null
    mysql_query 'SELECT COUNT(*) FROM (SELECT promptpay_target, promptpay_name, payment_receiver_account_tail, line_channel_access_token_enc, line_max_attempts, notification_batch_size, slip_provider, slipok_api_key_enc, slipok_branch_id, easyslip_api_key_enc, slip_max_bytes, slip_time_tolerance_seconds, updated_by, updated_at FROM integration_settings WHERE id = 1) AS required_integration_settings' >/dev/null
}

table_count="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")"
[[ "$table_count" =~ ^[0-9]+$ ]] || die "could not determine the current database table count"

if (( table_count == 0 )); then
    log "database is empty; importing database/schema.sql"
    mysql_cli < "$schema_file" >/dev/null \
        || die "schema import failed; the database may now be partial and requires operator review"

    log "schema import completed; importing database/defaults.sql"
    mysql_cli < "$defaults_file" >/dev/null \
        || die "defaults import failed; the database is partial and requires operator review"

    verify_schema_and_defaults
    log "fresh database bootstrap completed and verified"
else
    verify_schema_and_defaults
    log "database already contains the complete compatible schema and required settings; no changes made"
fi
