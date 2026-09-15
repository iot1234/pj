#!/usr/bin/env bash
set -Eeuo pipefail
set +x

umask 077
unset MYSQL_PWD

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

require_env() {
    local name="$1"
    if [[ -z "${!name:-}" ]]; then
        fail "Required environment variable ${name} is missing or empty"
    fi
}

for name in \
    DB_DBA_HOST DB_DBA_PORT DB_DBA_USERNAME DB_DBA_PASSWORD \
    DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
    require_env "$name"
done

command -v mysql >/dev/null 2>&1 || fail 'The mysql client is required'

mysql_tls_args=()
mysql_tls_enabled=false
configure_mysql_tls() {
    local db_ssl_value="${DB_SSL-false}"
    case "${db_ssl_value,,}" in
        1|true|yes|on)
            ;;
        0|false|no|off)
            return
            ;;
        *)
            fail 'DB_SSL must be a boolean (true/false, yes/no, on/off, or 1/0)'
            ;;
    esac

    mysql_tls_enabled=true
    [[ -n "${DB_SSL_CA:-}" \
        && "$DB_SSL_CA" == /* \
        && "$DB_SSL_CA" != *$'\n'* \
        && "$DB_SSL_CA" != *$'\r'* \
        && -f "$DB_SSL_CA" \
        && -r "$DB_SSL_CA" ]] \
        || fail 'DB_SSL_CA must be an absolute path to a readable CA file when DB_SSL is enabled'

    local mysql_help
    if ! mysql_help="$(mysql --no-defaults --help 2>&1)"; then
        fail 'Could not inspect mysql CLI TLS capabilities'
    fi
    [[ "$mysql_help" == *"--ssl-ca"* ]] \
        || fail 'The mysql client does not support a configured CA file; refusing to connect without identity verification'

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
        fail 'The mysql client cannot verify the database server identity; refusing to connect'
    fi
}
configure_mysql_tls

runtime_account_tls_clause=''
if [[ "$mysql_tls_enabled" == true ]]; then
    # MySQL 8 CREATE USER grammar places REQUIRE after authentication options
    # and before password-expiry and account-lock options.
    runtime_account_tls_clause='REQUIRE SSL'
fi

[[ "$DB_DBA_HOST" =~ ^[A-Za-z0-9._:-]{1,253}$ ]] \
    || fail 'DB_DBA_HOST contains unsupported characters'
[[ "$DB_HOST" =~ ^[A-Za-z0-9._:-]{1,253}$ ]] \
    || fail 'DB_HOST contains unsupported characters'
[[ "$DB_DBA_PORT" =~ ^[0-9]{1,5}$ ]] \
    && ((10#$DB_DBA_PORT >= 1 && 10#$DB_DBA_PORT <= 65535)) \
    || fail 'DB_DBA_PORT must be an integer from 1 to 65535'
[[ "$DB_PORT" =~ ^[0-9]{1,5}$ ]] \
    && ((10#$DB_PORT >= 1 && 10#$DB_PORT <= 65535)) \
    || fail 'DB_PORT must be an integer from 1 to 65535'
[[ "$DB_DBA_USERNAME" =~ ^[A-Za-z0-9_]{1,32}$ ]] \
    || fail 'DB_DBA_USERNAME must contain only A-Z, a-z, 0-9, or underscore'
[[ "$DB_USERNAME" =~ ^[A-Za-z0-9_]{1,32}$ ]] \
    || fail 'DB_USERNAME must contain only A-Z, a-z, 0-9, or underscore'
[[ "$DB_DATABASE" =~ ^[A-Za-z0-9_]{1,64}$ ]] \
    || fail 'DB_DATABASE must contain only A-Z, a-z, 0-9, or underscore'
[[ "$DB_PASSWORD" =~ ^[A-Fa-f0-9]{32,128}$ ]] \
    || fail 'DB_PASSWORD must be 32-128 hexadecimal characters'

case "${DB_DATABASE,,}" in
    information_schema|mysql|performance_schema|sys)
        fail 'DB_DATABASE must not name a MySQL system schema'
        ;;
esac
case "${DB_USERNAME,,}" in
    root|mysql|mysql_*)
        fail 'DB_USERNAME is reserved and cannot be used as the runtime account'
        ;;
esac
[[ "${DB_DBA_USERNAME,,}" != "${DB_USERNAME,,}" ]] \
    || fail 'DB_DBA_USERNAME and DB_USERNAME must be different accounts'
[[ "$DB_DBA_PASSWORD" != "$DB_PASSWORD" ]] \
    || fail 'DB_DBA_PASSWORD and DB_PASSWORD must be different secrets'

dba_args=(
    --no-defaults
    --protocol=TCP
    "--host=$DB_DBA_HOST"
    "--port=$DB_DBA_PORT"
    "--user=$DB_DBA_USERNAME"
    "--database=$DB_DATABASE"
    --connect-timeout=5
    --batch
    --skip-column-names
    --raw
)
dba_args+=("${mysql_tls_args[@]}")

dba_query() {
    MYSQL_PWD="$DB_DBA_PASSWORD" mysql "${dba_args[@]}" --execute="$1"
}

connected=false
for ((attempt = 1; attempt <= 30; attempt += 1)); do
    if MYSQL_PWD="$DB_DBA_PASSWORD" mysql "${dba_args[@]}" \
        --execute='SELECT 1' >/dev/null 2>&1; then
        connected=true
        break
    fi
    if ((attempt < 30)); then
        sleep 2
    fi
done
[[ "$connected" == true ]] \
    || fail 'Could not authenticate to the configured database with the DBA connection'

if ! server_version="$(dba_query 'SELECT VERSION()' 2>/dev/null)"; then
    fail 'Could not validate the MySQL server version'
fi
server_version="${server_version%$'\r'}"
[[ "${server_version,,}" != *mariadb* ]] \
    || fail 'MariaDB is not supported; MySQL 8.0.16 or newer is required'
if [[ "$server_version" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+) ]]; then
    version_major=$((10#${BASH_REMATCH[1]}))
    version_minor=$((10#${BASH_REMATCH[2]}))
    version_patch=$((10#${BASH_REMATCH[3]}))
else
    fail 'Could not validate the MySQL server version'
fi
if ((version_major < 8 \
    || (version_major == 8 && version_minor == 0 && version_patch < 16))); then
    fail 'MySQL 8.0.16 or newer is required'
fi

if ! mandatory_roles="$(dba_query 'SELECT COALESCE(@@GLOBAL.mandatory_roles, "")' 2>/dev/null)"; then
    fail 'Could not validate mandatory MySQL roles'
fi
mandatory_roles="${mandatory_roles%$'\r'}"
if [[ -n "$mandatory_roles" && "${mandatory_roles^^}" != 'NONE' ]]; then
    fail 'The MySQL server has mandatory roles; refusing to create a least-privilege runtime account'
fi

if ! partial_revokes="$(dba_query 'SELECT @@GLOBAL.partial_revokes' 2>/dev/null)"; then
    fail 'Could not validate MySQL partial-revoke behavior'
fi
partial_revokes="${partial_revokes%$'\r'}"
if [[ "$partial_revokes" != 0 && "${partial_revokes^^}" != 'OFF' ]]; then
    fail 'MySQL partial_revokes must be disabled for escaped schema grant verification'
fi

if ! definer_count="$(dba_query "
    SELECT COUNT(*)
    FROM (
        SELECT DEFINER FROM information_schema.views
        UNION ALL SELECT DEFINER FROM information_schema.routines
        UNION ALL SELECT DEFINER FROM information_schema.triggers
        UNION ALL SELECT DEFINER FROM information_schema.events
    ) AS object_definers
    WHERE BINARY DEFINER = BINARY '${DB_USERNAME}@%'
" 2>/dev/null)"; then
    fail 'Could not audit stored-object definers before provisioning'
fi
definer_count="${definer_count%$'\r'}"
[[ "$definer_count" == 0 ]] \
    || fail 'The runtime account owns stored objects; refusing to recreate it'

if ! active_session_count="$(dba_query "
    SELECT COUNT(*)
    FROM information_schema.processlist
    WHERE USER = '${DB_USERNAME}'
" 2>/dev/null)"; then
    fail 'Could not audit active runtime database sessions before provisioning'
fi
active_session_count="${active_session_count%$'\r'}"
[[ "$active_session_count" == 0 ]] \
    || fail 'The runtime account has active sessions; stop web and worker before provisioning'

if ! dba_server_uuid="$(dba_query 'SELECT @@server_uuid' 2>/dev/null)"; then
    fail 'Could not identify the DBA database server'
fi
dba_server_uuid="${dba_server_uuid%$'\r'}"
[[ -n "$dba_server_uuid" ]] || fail 'The DBA database server returned an empty identity'

# MySQL treats underscore as a wildcard in a schema-level grant even inside
# backticks. Escape it so a similarly named schema is never covered.
grant_database="${DB_DATABASE//_/\\_}"

# All interpolated SQL values are constrained above to safe identifiers or a
# hexadecimal password. The named lock serializes account replacement on the
# server. DROP/CREATE deliberately fails closed: an interrupted first-run job
# may leave the account absent, but can never retain unknown old privileges.
if ! MYSQL_PWD="$DB_DBA_PASSWORD" mysql "${dba_args[@]}" >/dev/null 2>&1 <<SQL
SET @provision_lock = GET_LOCK('dormitory_runtime_user_provision', 30);
SET @lock_guard = IF(
    @provision_lock = 1,
    'DO 0',
    'SELECT * FROM information_schema.__dormitory_provision_lock_failed__'
);
PREPARE provision_guard FROM @lock_guard;
EXECUTE provision_guard;
DEALLOCATE PREPARE provision_guard;

DROP USER IF EXISTS '${DB_USERNAME}'@'%';
CREATE USER '${DB_USERNAME}'@'%'
    IDENTIFIED BY '${DB_PASSWORD}'
    ${runtime_account_tls_clause}
    PASSWORD EXPIRE NEVER
    ACCOUNT UNLOCK;
GRANT SELECT, INSERT, UPDATE ON \`${grant_database}\`.* TO '${DB_USERNAME}'@'%';
SET DEFAULT ROLE NONE TO '${DB_USERNAME}'@'%';
DO RELEASE_LOCK('dormitory_runtime_user_provision');
SQL
then
    fail 'Failed to provision the runtime database account'
fi

runtime_args=(
    --no-defaults
    --protocol=TCP
    "--host=$DB_HOST"
    "--port=$DB_PORT"
    "--user=$DB_USERNAME"
    "--database=$DB_DATABASE"
    --connect-timeout=5
    --batch
    --skip-column-names
    --raw
)
runtime_args+=("${mysql_tls_args[@]}")

runtime_query() {
    MYSQL_PWD="$DB_PASSWORD" mysql "${runtime_args[@]}" --execute="$1"
}

if ! runtime_identity="$(runtime_query \
    'SELECT CONCAT(CURRENT_USER(), CHAR(9), @@server_uuid, CHAR(9), DATABASE(), CHAR(9), CURRENT_ROLE())' \
    2>/dev/null)"; then
    fail 'The provisioned runtime database account could not authenticate'
fi
runtime_identity="${runtime_identity%$'\r'}"
IFS=$'\t' read -r runtime_account runtime_server_uuid runtime_database runtime_role \
    <<< "$runtime_identity"
[[ "$runtime_account" == "${DB_USERNAME}@%" ]] \
    || fail 'The runtime connection resolved to an unexpected MySQL account'
[[ "$runtime_server_uuid" == "$dba_server_uuid" ]] \
    || fail 'The DBA and runtime endpoints resolve to different MySQL servers'
[[ "$runtime_database" == "$DB_DATABASE" ]] \
    || fail 'The runtime connection selected an unexpected database'
[[ "$runtime_role" == 'NONE' ]] \
    || fail 'The runtime database account has an active role'

if ! grants="$(runtime_query 'SHOW GRANTS' 2>/dev/null)"; then
    fail 'Could not verify runtime database grants'
fi

expected_usage="GRANT USAGE ON *.* TO \`${DB_USERNAME}\`@\`%\`"
expected_database="GRANT SELECT, INSERT, UPDATE ON \`${grant_database}\`.* TO \`${DB_USERNAME}\`@\`%\`"
usage_count=0
database_count=0
grant_count=0
while IFS= read -r grant; do
    grant="${grant%$'\r'}"
    ((grant_count += 1))
    case "$grant" in
        "$expected_usage")
            ((usage_count += 1))
            ;;
        "$expected_database")
            ((database_count += 1))
            ;;
        *)
            fail 'Runtime database account has an unexpected grant or role'
            ;;
    esac
done <<< "$grants"

if ((grant_count != 2 || usage_count != 1 || database_count != 1)); then
    fail 'Runtime database grants do not match the required least-privilege allowlist'
fi

if ! readiness="$(runtime_query "
    SELECT CONCAT(
        (SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'),
        '|', (SELECT COUNT(*) FROM billing_settings WHERE id = 1),
        '|', (SELECT COUNT(*) FROM integration_settings WHERE id = 1)
    )
" 2>/dev/null)"; then
    fail 'The runtime account could not read the required application schema'
fi
readiness="${readiness%$'\r'}"
[[ "$readiness" == '21|1|1' ]] \
    || fail 'The application schema or required defaults are incomplete'

printf '%s\n' 'Runtime database account provisioned and verified'
