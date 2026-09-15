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
command -v sort >/dev/null 2>&1 || die "sort is required"

mysql_tls_args=()
configure_mysql_tls() {
    local db_ssl_value="${DB_SSL-false}"
    case "${db_ssl_value,,}" in
        1|true|yes|on)
            ;;
        0|false|no|off)
            return
            ;;
        *)
            die "DB_SSL must be a boolean (true/false, yes/no, on/off, or 1/0)"
            ;;
    esac

    [[ -n "${DB_SSL_CA:-}" \
        && "$DB_SSL_CA" == /* \
        && "$DB_SSL_CA" != *$'\n'* \
        && "$DB_SSL_CA" != *$'\r'* \
        && -f "$DB_SSL_CA" \
        && -r "$DB_SSL_CA" ]] \
        || die "DB_SSL_CA must be an absolute path to a readable CA file when DB_SSL is enabled"

    local mysql_help
    if ! mysql_help="$(mysql --no-defaults --help 2>&1)"; then
        die "could not inspect mysql CLI TLS capabilities"
    fi
    [[ "$mysql_help" == *"--ssl-ca"* ]] \
        || die "mysql CLI does not support a configured CA file; refusing to connect without identity verification"

    if [[ "$mysql_help" == *"--ssl-mode"* ]]; then
        mysql_tls_args+=(
            --ssl-mode=VERIFY_IDENTITY
            "--ssl-ca=${DB_SSL_CA}"
        )
    elif [[ "$mysql_help" == *"--ssl-verify-server-cert"* ]]; then
        mysql_tls_args+=(
            --ssl
            "--ssl-ca=${DB_SSL_CA}"
            --ssl-verify-server-cert
        )
    else
        die "mysql CLI cannot verify the database server identity; refusing to connect"
    fi
}
configure_mysql_tls

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
mysql_args+=("${mysql_tls_args[@]}")

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

expected_tables=$'admin_users\naudit_logs\nbill_items\nbilling_settings\nbills\nbookings\nintegration_settings\nline_admin_recipients\nline_link_codes\nline_notice_outbox\nline_official_accounts\nline_room_bindings\nline_room_policies\nmeter_readings\nnotification_outbox\nnotification_worker_heartbeats\noccupancies\npayments\nrate_limits\nresidents\nrooms'
expected_triggers=$'trg_audit_logs_no_delete|BEFORE|DELETE|audit_logs\ntrg_audit_logs_no_update|BEFORE|UPDATE|audit_logs\ntrg_bill_items_insert_guard|BEFORE|INSERT|bill_items\ntrg_bill_items_no_delete|BEFORE|DELETE|bill_items\ntrg_bill_items_no_update|BEFORE|UPDATE|bill_items\ntrg_bills_no_delete|BEFORE|DELETE|bills\ntrg_bills_relationship_guard|BEFORE|INSERT|bills\ntrg_bills_snapshot_immutable|BEFORE|UPDATE|bills\ntrg_bookings_identity_immutable|BEFORE|UPDATE|bookings\ntrg_bookings_insert_guard|BEFORE|INSERT|bookings\ntrg_line_notice_relationship_guard_update|BEFORE|UPDATE|line_notice_outbox\ntrg_line_notice_relationship_guard|BEFORE|INSERT|line_notice_outbox\ntrg_line_room_bindings_insert_guard|BEFORE|INSERT|line_room_bindings\ntrg_line_room_bindings_update_guard|BEFORE|UPDATE|line_room_bindings\ntrg_meter_readings_occupancy_guard_update|BEFORE|UPDATE|meter_readings\ntrg_meter_readings_occupancy_guard|BEFORE|INSERT|meter_readings\ntrg_notification_relationship_guard_update|BEFORE|UPDATE|notification_outbox\ntrg_notification_relationship_guard|BEFORE|INSERT|notification_outbox\ntrg_occupancies_identity_immutable|BEFORE|UPDATE|occupancies\ntrg_occupancies_relationship_guard|BEFORE|INSERT|occupancies\ntrg_payments_final_immutable|BEFORE|UPDATE|payments\ntrg_payments_no_delete|BEFORE|DELETE|payments\ntrg_payments_relationship_guard|BEFORE|INSERT|payments'
expected_hardening_columns=$'bills.resident_name_snapshot|varchar(150)|NO\nbills.room_code_snapshot|varchar(32)|NO\nbookings.booked_monthly_rent|decimal(12,2)|NO\npayments.verification_attempts|smallint unsigned|NO\npayments.verification_lease_until|datetime(6)|YES\npayments.verification_token|char(64)|YES'
expected_generated_columns=$'bookings.active_phone_norm\nbookings.active_room_id\nline_link_codes.pending_resident_id\nline_official_accounts.active_default_key\nline_room_bindings.active_line_user_id\nnotification_outbox.line_delivery_key\noccupancies.active_resident_id\noccupancies.active_room_id\npayments.active_bill_id'
expected_generated_column_definitions="bookings.active_phone_norm|char(10)|YES|STORED GENERATED|casewhenstatusin'pending','confirmed'thenphone_normelsenullend
bookings.active_room_id|bigint unsigned|YES|STORED GENERATED|casewhenstatusin'pending','confirmed'thenroom_idelsenullend
line_link_codes.pending_resident_id|bigint unsigned|YES|STORED GENERATED|casewhenstatus='pending'thenresident_idelsenullend
occupancies.active_resident_id|bigint unsigned|YES|STORED GENERATED|casewhenstatus='active'thenresident_idelsenullend
occupancies.active_room_id|bigint unsigned|YES|STORED GENERATED|casewhenstatus='active'thenroom_idelsenullend
payments.active_bill_id|bigint unsigned|YES|STORED GENERATED|casewhenstatusin'pending','verified'thenbill_idelsenullend"
expected_line_columns=$'integration_settings.line_channel_secret_enc|text|YES\nnotification_outbox.line_accepted_request_id|varchar(128)|YES\nnotification_outbox.line_request_id|varchar(128)|YES\nnotification_outbox.recipient|varchar(33)|NO\nresidents.line_user_id|varchar(33)|YES'
expected_line_checks=$'chk_integration_settings_line_secret\nchk_notification_outbox_line_accepted_request_id\nchk_notification_outbox_line_request_id\nchk_notification_outbox_recipient\nchk_residents_line_user_id'
expected_current_schema_columns="bookings.move_in_request_hash|char(64)|YES
line_link_codes.bound_at|datetime(6)|YES
line_link_codes.code_hash|char(64)|NO
line_link_codes.created_at|datetime(6)|NO
line_link_codes.expires_at|datetime(6)|NO
line_link_codes.id|bigint unsigned|NO
line_link_codes.line_user_id|varchar(33)|YES
line_link_codes.pending_resident_id|bigint unsigned|YES
line_link_codes.resident_id|bigint unsigned|NO
line_link_codes.revoked_at|datetime(6)|YES
line_link_codes.status|enum('pending','bound','expired','revoked')|NO
line_link_codes.updated_at|datetime(6)|NO
meter_readings.occupancy_id|bigint unsigned|YES
notification_outbox.claim_token|char(64)|YES
notification_outbox.lease_until|datetime(6)|YES
notification_worker_heartbeats.created_at|datetime(6)|NO
notification_worker_heartbeats.heartbeat_at|datetime(6)|NO
notification_worker_heartbeats.last_cycle_at|datetime(6)|YES
notification_worker_heartbeats.last_error|varchar(1000)|YES
notification_worker_heartbeats.last_failed|int unsigned|NO
notification_worker_heartbeats.last_lost_claims|int unsigned|NO
notification_worker_heartbeats.last_processed|int unsigned|NO
notification_worker_heartbeats.last_recovered|int unsigned|NO
notification_worker_heartbeats.last_retried|int unsigned|NO
notification_worker_heartbeats.last_sent|int unsigned|NO
notification_worker_heartbeats.started_at|datetime(6)|NO
notification_worker_heartbeats.status|enum('starting','running','error','stopped')|NO
notification_worker_heartbeats.updated_at|datetime(6)|NO
notification_worker_heartbeats.worker_id|char(64)|NO
occupancies.opening_electric_reading|decimal(14,2)|YES
occupancies.opening_water_reading|decimal(14,2)|YES
residents.access_password_hash|varchar(255)|YES
residents.activation_code_hash|char(64)|YES
residents.activation_consumed_at|datetime(6)|YES
residents.activation_expires_at|datetime(6)|YES"
expected_current_schema_checks=$'chk_bookings_move_in_request_hash\nchk_line_link_codes_hash\nchk_line_link_codes_line_user\nchk_line_link_codes_state\nchk_line_link_codes_timestamps\nchk_notification_outbox_claim_lease\nchk_notification_worker_error\nchk_notification_worker_id\nchk_occupancies_opening_readings\nchk_residents_access_password\nchk_residents_activation_state\nchk_residents_password_activation'
expected_operational_indexes=$'line_link_codes.idx_line_link_codes_expiry|status|1|1|FULL\nline_link_codes.idx_line_link_codes_expiry|expires_at|1|2|FULL\nline_link_codes.idx_line_link_codes_resident|resident_id|1|1|FULL\nline_link_codes.idx_line_link_codes_resident|created_at|1|2|FULL\nmeter_readings.idx_meter_readings_occupancy_period|occupancy_id|1|1|FULL\nmeter_readings.idx_meter_readings_occupancy_period|period|1|2|FULL\nnotification_outbox.idx_notification_outbox_lease|status|1|1|FULL\nnotification_outbox.idx_notification_outbox_lease|lease_until|1|2|FULL\nnotification_worker_heartbeats.idx_notification_worker_heartbeat|heartbeat_at|1|1|FULL'
expected_unique_indexes=$'admin_users.uq_admin_users_username|username|0|1|FULL\nbill_items.uq_bill_items_bill_type|bill_id|0|1|FULL\nbill_items.uq_bill_items_bill_type|item_type|0|2|FULL\nbills.uq_bills_bill_no|bill_no|0|1|FULL\nbills.uq_bills_occupancy_period|occupancy_id|0|1|FULL\nbills.uq_bills_occupancy_period|period|0|2|FULL\nbookings.uq_bookings_idempotency_key|idempotency_key|0|1|FULL\nbookings.uq_bookings_one_active_per_phone|active_phone_norm|0|1|FULL\nbookings.uq_bookings_one_active_per_room|active_room_id|0|1|FULL\nbookings.uq_bookings_reference_no|reference_no|0|1|FULL\nline_admin_recipients.uq_line_admin_recipients_code_hash|code_hash|0|1|FULL\nline_link_codes.uq_line_link_codes_code_hash|code_hash|0|1|FULL\nline_link_codes.uq_line_link_codes_pending_resident|pending_resident_id|0|1|FULL\nline_notice_outbox.uq_line_notice_outbox_dedupe_key|dedupe_key|0|1|FULL\nline_notice_outbox.uq_line_notice_outbox_retry_key|retry_key|0|1|FULL\nline_official_accounts.uq_line_official_accounts_default|active_default_key|0|1|FULL\nline_official_accounts.uq_line_official_accounts_provider|provider_user_id|0|1|FULL\nline_official_accounts.uq_line_official_accounts_route|route_token|0|1|FULL\nline_official_accounts.uq_line_official_accounts_slug|slug|0|1|FULL\nline_room_bindings.uq_line_room_bindings_code_hash|code_hash|0|1|FULL\nline_room_bindings.uq_line_room_bindings_oa_user|active_line_user_id|0|2|FULL\nline_room_bindings.uq_line_room_bindings_oa_user|oa_id|0|1|FULL\nmeter_readings.uq_meter_readings_room_type_period|meter_type|0|2|FULL\nmeter_readings.uq_meter_readings_room_type_period|period|0|3|FULL\nmeter_readings.uq_meter_readings_room_type_period|room_id|0|1|FULL\nnotification_outbox.uq_notification_outbox_bill_binding|bill_id|0|1|FULL\nnotification_outbox.uq_notification_outbox_bill_binding|line_delivery_key|0|3|FULL\nnotification_outbox.uq_notification_outbox_bill_binding|purpose|0|2|FULL\nnotification_outbox.uq_notification_outbox_retry_key|retry_key|0|1|FULL\noccupancies.uq_occupancies_booking|booking_id|0|1|FULL\noccupancies.uq_occupancies_one_active_per_resident|active_resident_id|0|1|FULL\noccupancies.uq_occupancies_one_active_per_room|active_room_id|0|1|FULL\npayments.uq_payments_one_active_per_bill|active_bill_id|0|1|FULL\npayments.uq_payments_slip_hmac|slip_hmac|0|1|FULL\npayments.uq_payments_transaction_ref|transaction_ref|0|1|FULL\nresidents.uq_residents_line_user_id|line_user_id|0|1|FULL\nresidents.uq_residents_phone_norm|phone_norm|0|1|FULL\nrooms.uq_rooms_room_code|room_code|0|1|FULL'

verify_schema_and_defaults() {
    local object_count base_table_count actual_tables actual_triggers actual_trigger_count
    local actual_hardening_columns actual_generated_columns actual_generated_column_definitions actual_line_columns actual_line_checks
    local actual_current_schema_columns actual_current_schema_checks actual_operational_indexes meter_occupancy_foreign_key line_code_resident_foreign_key
    local actual_unique_indexes retired_resident_credential_count check_constraint_count billing_row_count integration_row_count

    object_count="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")"
    base_table_count="$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")"
    [[ "$object_count" == 21 && "$base_table_count" == 21 ]] \
        || die "database is nonempty but does not contain exactly the required 21 base tables; refusing to modify it"

    actual_tables="$(mysql_query "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")"
    [[ "$actual_tables" == "$expected_tables" ]] \
        || die "database has a partial or incompatible table set; refusing to modify it"

    actual_trigger_count="$(mysql_query "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()")"
    [[ "$actual_trigger_count" == 23 ]] \
        || die "database does not contain exactly the required 23 integrity triggers; refusing to modify it"
    actual_triggers="$(mysql_query "SELECT CONCAT(trigger_name, '|', action_timing, '|', event_manipulation, '|', event_object_table) FROM information_schema.triggers WHERE trigger_schema = DATABASE()")"
    # INFORMATION_SCHEMA uses a server collation whose punctuation ordering can
    # differ between MySQL releases. Compare the tuple set under C ordering so
    # verification is deterministic and independent of metadata collation.
    actual_triggers="$(LC_ALL=C sort <<< "$actual_triggers")"
    [[ "$actual_triggers" == "$(LC_ALL=C sort <<< "$expected_triggers")" ]] \
        || die "database has missing or incompatible integrity triggers; refusing to modify it"

    actual_hardening_columns="$(mysql_query "SELECT CONCAT(table_name, '.', column_name, '|', LOWER(column_type), '|', is_nullable) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'bookings' AND column_name = 'booked_monthly_rent') OR (table_name = 'bills' AND column_name IN ('resident_name_snapshot', 'room_code_snapshot')) OR (table_name = 'payments' AND column_name IN ('verification_lease_until', 'verification_token', 'verification_attempts'))) ORDER BY table_name, column_name")"
    [[ "$actual_hardening_columns" == "$expected_hardening_columns" ]] \
        || die "database is missing required operational-hardening columns or their types do not match"

    actual_generated_columns="$(mysql_query "SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns WHERE table_schema = DATABASE() AND extra LIKE '%STORED GENERATED%' ORDER BY table_name, column_name")"
    [[ "$actual_generated_columns" == "$expected_generated_columns" ]] \
        || die "database has missing or incompatible generated uniqueness guards"

    actual_generated_column_definitions="$(mysql_query "SELECT CONCAT(table_name, '.', column_name, '|', LOWER(column_type), '|', is_nullable, '|', UPPER(extra), '|', normalized_expression) FROM (SELECT column_metadata.*, LOWER(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(column_metadata.literal_expression, CHAR(96), ''), '(', ''), ')', ''), '[[:space:]]+', '')) AS normalized_expression FROM (SELECT table_name, column_name, column_type, is_nullable, extra, LOWER(REGEXP_REPLACE(REPLACE(generation_expression, CONCAT(CHAR(92), CHAR(39)), CHAR(39)), CONCAT('_[[:alnum:]]+', CHAR(39)), CHAR(39))) AS literal_expression FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'bookings' AND column_name IN ('active_room_id', 'active_phone_norm')) OR (table_name = 'line_link_codes' AND column_name = 'pending_resident_id') OR (table_name = 'occupancies' AND column_name IN ('active_room_id', 'active_resident_id')) OR (table_name = 'payments' AND column_name = 'active_bill_id'))) column_metadata) normalized ORDER BY table_name, column_name")"
    # IN-list order is semantically irrelevant, and migration 005 deliberately
    # accepts both historical orders. Canonicalize those variants before the
    # exact definition comparison while keeping every literal/target explicit.
    local pending_confirmed_reversed="in'confirmed','pending'"
    local pending_confirmed_canonical="in'pending','confirmed'"
    local pending_verified_reversed="in'verified','pending'"
    local pending_verified_canonical="in'pending','verified'"
    actual_generated_column_definitions="${actual_generated_column_definitions//$pending_confirmed_reversed/$pending_confirmed_canonical}"
    actual_generated_column_definitions="${actual_generated_column_definitions//$pending_verified_reversed/$pending_verified_canonical}"
    [[ "$actual_generated_column_definitions" == "$expected_generated_column_definitions" ]] \
        || die "database has invalid generated uniqueness guard definitions"

    actual_line_columns="$(mysql_query "SELECT CONCAT(table_name, '.', column_name, '|', LOWER(column_type), '|', is_nullable) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'integration_settings' AND column_name = 'line_channel_secret_enc') OR (table_name = 'notification_outbox' AND column_name IN ('line_request_id', 'line_accepted_request_id', 'recipient')) OR (table_name = 'residents' AND column_name = 'line_user_id')) ORDER BY table_name, column_name")"
    [[ "$actual_line_columns" == "$expected_line_columns" ]] \
        || die "database is missing required LINE webhook/reconciliation columns or their types do not match"

    actual_line_checks="$(mysql_query "SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK' AND constraint_name IN ('chk_integration_settings_line_secret', 'chk_notification_outbox_line_accepted_request_id', 'chk_notification_outbox_line_request_id', 'chk_notification_outbox_recipient', 'chk_residents_line_user_id') ORDER BY constraint_name")"
    [[ "$actual_line_checks" == "$expected_line_checks" ]] \
        || die "database is missing required LINE webhook/reconciliation CHECK constraints"

    actual_current_schema_columns="$(mysql_query "SELECT CONCAT(table_name, '.', column_name, '|', LOWER(column_type), '|', is_nullable) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'bookings' AND column_name = 'move_in_request_hash') OR (table_name = 'residents' AND column_name IN ('access_password_hash', 'activation_code_hash', 'activation_expires_at', 'activation_consumed_at')) OR table_name = 'line_link_codes' OR (table_name = 'occupancies' AND column_name IN ('opening_water_reading', 'opening_electric_reading')) OR (table_name = 'meter_readings' AND column_name = 'occupancy_id') OR (table_name = 'notification_outbox' AND column_name IN ('claim_token', 'lease_until')) OR table_name = 'notification_worker_heartbeats') ORDER BY table_name, column_name")"
    [[ "$actual_current_schema_columns" == "$expected_current_schema_columns" ]] \
        || die "database is missing required migration 007/008/009/010/012 columns or their types do not match"

    actual_current_schema_checks="$(mysql_query "SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK' AND constraint_name IN ('chk_bookings_move_in_request_hash', 'chk_line_link_codes_hash', 'chk_line_link_codes_line_user', 'chk_line_link_codes_state', 'chk_line_link_codes_timestamps', 'chk_notification_outbox_claim_lease', 'chk_notification_worker_error', 'chk_notification_worker_id', 'chk_occupancies_opening_readings', 'chk_residents_access_password', 'chk_residents_activation_state', 'chk_residents_password_activation') ORDER BY constraint_name")"
    [[ "$actual_current_schema_checks" == "$expected_current_schema_checks" ]] \
        || die "database is missing required migration 007/008/009/010/012 CHECK constraints"

    actual_operational_indexes="$(mysql_query "SELECT CONCAT(table_name, '.', index_name, '|', COALESCE(column_name, '<expression>'), '|', non_unique, '|', seq_in_index, '|', IF(sub_part IS NULL, 'FULL', sub_part)) FROM information_schema.statistics WHERE table_schema = DATABASE() AND ((table_name = 'line_link_codes' AND index_name IN ('idx_line_link_codes_expiry', 'idx_line_link_codes_resident')) OR (table_name = 'meter_readings' AND index_name = 'idx_meter_readings_occupancy_period') OR (table_name = 'notification_outbox' AND index_name = 'idx_notification_outbox_lease') OR (table_name = 'notification_worker_heartbeats' AND index_name = 'idx_notification_worker_heartbeat')) ORDER BY table_name, index_name, seq_in_index")"
    [[ "$actual_operational_indexes" == "$expected_operational_indexes" ]] \
        || die "database is missing required notification lease/heartbeat or occupancy operational indexes"

    meter_occupancy_foreign_key="$(mysql_query "SELECT CONCAT(k.column_name, '|', k.referenced_table_name, '|', k.referenced_column_name, '|', r.update_rule, '|', r.delete_rule) FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema = k.constraint_schema AND r.table_name = k.table_name AND r.constraint_name = k.constraint_name WHERE k.constraint_schema = DATABASE() AND k.table_name = 'meter_readings' AND k.constraint_name = 'fk_meter_readings_occupancy' ORDER BY k.ordinal_position")"
    [[ "$meter_occupancy_foreign_key" == 'occupancy_id|occupancies|id|RESTRICT|RESTRICT' ]] \
        || die "database is missing the required meter_readings occupancy foreign key"

    line_code_resident_foreign_key="$(mysql_query "SELECT CONCAT(k.column_name, '|', k.referenced_table_name, '|', k.referenced_column_name, '|', r.update_rule, '|', r.delete_rule) FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema = k.constraint_schema AND r.table_name = k.table_name AND r.constraint_name = k.constraint_name WHERE k.constraint_schema = DATABASE() AND k.table_name = 'line_link_codes' AND k.constraint_name = 'fk_line_link_codes_resident' ORDER BY k.ordinal_position")"
    [[ "$line_code_resident_foreign_key" == 'resident_id|residents|id|RESTRICT|RESTRICT' ]] \
        || die "database is missing the required line_link_codes resident foreign key"

    retired_resident_credential_count="$(mysql_query "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'residents' AND column_name = 'pin_hash'")"
    [[ "$retired_resident_credential_count" == 0 ]] \
        || die "database still contains retired residents.pin_hash; apply migration 006 before deploying current source"

    actual_unique_indexes="$(mysql_query "SELECT CONCAT(table_name, '.', index_name, '|', COALESCE(column_name, '<expression>'), '|', non_unique, '|', seq_in_index, '|', IF(sub_part IS NULL, 'FULL', sub_part)) FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name <> 'PRIMARY' AND non_unique = 0")"
    actual_unique_indexes="$(LC_ALL=C sort <<< "$actual_unique_indexes")"
    [[ "$actual_unique_indexes" == "$(LC_ALL=C sort <<< "$expected_unique_indexes")" ]] \
        || die "database has missing or incompatible unique integrity indexes; refusing to modify it"

    check_constraint_count="$(mysql_query "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK'")"
    [[ "$check_constraint_count" =~ ^[0-9]+$ ]] \
        || die "could not validate CHECK constraints"
    (( check_constraint_count >= 116 )) \
        || die "database has fewer than the required 116 CHECK constraints"

    billing_row_count="$(mysql_query 'SELECT COUNT(*) FROM billing_settings WHERE id = 1')"
    integration_row_count="$(mysql_query 'SELECT COUNT(*) FROM integration_settings WHERE id = 1')"
    [[ "$billing_row_count" == 1 && "$integration_row_count" == 1 ]] \
        || die "database is missing required billing or integration settings defaults"
    [[ "$(mysql_query 'SELECT COUNT(*) FROM billing_settings')" == 1 \
        && "$(mysql_query 'SELECT COUNT(*) FROM integration_settings')" == 1 ]] \
        || die "settings tables violate the required singleton layout"

    DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_DATABASE="$DB_DATABASE" DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" \
        php "${script_dir}/check_line_platform_schema.php" \
        || die "LINE platform schema does not match migration 014"

    mysql_query 'SELECT COUNT(*) FROM (SELECT water_rate, electric_rate, due_days, updated_by, updated_at FROM billing_settings WHERE id = 1) AS required_billing_settings' >/dev/null
    mysql_query 'SELECT COUNT(*) FROM (SELECT promptpay_target, promptpay_name, payment_receiver_account_tail, line_channel_access_token_enc, line_channel_secret_enc, line_max_attempts, notification_batch_size, slip_provider, slipok_api_key_enc, slipok_branch_id, easyslip_api_key_enc, slip_max_bytes, slip_time_tolerance_seconds, updated_by, updated_at FROM integration_settings WHERE id = 1) AS required_integration_settings' >/dev/null
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
