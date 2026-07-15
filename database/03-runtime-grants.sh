#!/usr/bin/env bash
(
set -Eeuo pipefail

# The official MySQL image initially grants MYSQL_USER broad privileges.
# After schema creation, narrow that account to read/create/update operations used by
# the running PHP application. Schema migration remains a root/DBA task.
if [[ -z "${MYSQL_ROOT_PASSWORD:-}" || -z "${MYSQL_PASSWORD:-}" ]]; then
    echo "MYSQL_ROOT_PASSWORD and MYSQL_PASSWORD must both be set" >&2
    exit 1
fi
if [[ "${MYSQL_ROOT_PASSWORD}" == "${MYSQL_PASSWORD}" ]]; then
    echo "MYSQL_ROOT_PASSWORD and MYSQL_PASSWORD must be different" >&2
    exit 1
fi
if [[ ! "${MYSQL_DATABASE:-}" =~ ^[A-Za-z0-9_]{1,64}$ ]]; then
    echo "MYSQL_DATABASE must contain only A-Z, a-z, 0-9, or underscore" >&2
    exit 1
fi
if [[ ! "${MYSQL_USER:-}" =~ ^[A-Za-z0-9_]{1,32}$ ]]; then
    echo "MYSQL_USER must contain only A-Z, a-z, 0-9, or underscore" >&2
    exit 1
fi

mysql_database_grant_pattern="${MYSQL_DATABASE//_/\\_}"
MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql --protocol=socket --user=root <<SQL
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON \`${mysql_database_grant_pattern}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
)
