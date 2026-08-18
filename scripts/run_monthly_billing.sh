#!/bin/sh
set -eu

log() {
    printf '%s\n' "monthly billing job: $1" >&2
}

if [ "${RUNTIME_ROLE:-}" != "job" ]; then
    log 'RUNTIME_ROLE must be job'
    exit 64
fi

timeout_seconds=${MONTHLY_BILLING_TIMEOUT_SECONDS:-900}
case "$timeout_seconds" in
    ''|*[!0-9]*)
        log 'MONTHLY_BILLING_TIMEOUT_SECONDS must be an integer'
        exit 64
        ;;
esac
if [ "${#timeout_seconds}" -gt 4 ]; then
    log 'MONTHLY_BILLING_TIMEOUT_SECONDS is outside the supported integer range'
    exit 64
fi
if [ "$timeout_seconds" -lt 60 ] || [ "$timeout_seconds" -gt 3600 ]; then
    log 'MONTHLY_BILLING_TIMEOUT_SECONDS must be between 60 and 3600'
    exit 64
fi

script_directory=$(CDPATH= cd "$(dirname "$0")" && pwd) || {
    log 'cannot resolve the script directory'
    exit 70
}

runtime_uid=$(id -u www-data) || {
    log 'runtime account is unavailable'
    exit 70
}
runtime_gid=$(id -g www-data) || {
    log 'runtime group is unavailable'
    exit 70
}
current_uid=$(id -u) || {
    log 'cannot determine process identity'
    exit 70
}
current_gid=$(id -g) || {
    log 'cannot determine process identity'
    exit 70
}

if [ "$current_uid" -eq 0 ]; then
    command -v gosu >/dev/null 2>&1 || {
        log 'privilege drop helper is unavailable'
        exit 70
    }
    install -d -o www-data -g www-data -m 0750 \
        /var/www/html/storage \
        /var/www/html/storage/logs \
        /var/www/html/storage/sessions \
        /var/www/html/storage/cache
    exec gosu www-data:www-data sh "$script_directory/run_monthly_billing.sh" "$@"
    exit 70
fi

if [ "$current_uid" -ne "$runtime_uid" ] || [ "$current_gid" -ne "$runtime_gid" ]; then
    log 'job must run as the dedicated runtime account'
    exit 77
fi

command -v php >/dev/null 2>&1 || {
    log 'PHP CLI is unavailable'
    exit 70
}
command -v timeout >/dev/null 2>&1 || {
    log 'timeout helper is unavailable'
    exit 70
}

umask 027
exec timeout \
    --foreground \
    --signal=TERM \
    --kill-after=15s \
    "$timeout_seconds" \
    php "$script_directory/generate_monthly_bills.php" "$@"
