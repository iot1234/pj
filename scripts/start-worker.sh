#!/bin/sh
set -u

# Railway should invoke this script with `sh scripts/start-worker.sh`.
# Values are deliberately bounded before they are passed to the worker or
# used in shell arithmetic.

log() {
    printf '%s\n' "worker supervisor: $1" >&2
}

normalize_unsigned_integer() {
    normalized=$1
    while [ "${normalized#0}" != "$normalized" ]; do
        normalized=${normalized#0}
    done
    if [ -z "$normalized" ]; then
        normalized=0
    fi
    printf '%s\n' "$normalized"
}

validated_integer() {
    option_name=$1
    option_value=$2
    minimum=$3
    maximum=$4

    case "$option_value" in
        ''|*[!0-9]*)
            log "$option_name must be an unsigned integer"
            return 64
            ;;
    esac

    option_value=$(normalize_unsigned_integer "$option_value")
    if [ "$option_value" -lt "$minimum" ] || [ "$option_value" -gt "$maximum" ]; then
        log "$option_name is outside its allowed range"
        return 64
    fi
    printf '%s\n' "$option_value"
}

poll_seconds=$(validated_integer WORKER_POLL_SECONDS "${WORKER_POLL_SECONDS:-15}" 1 300) || exit 64
initial_backoff=$(validated_integer WORKER_INITIAL_BACKOFF_SECONDS "${WORKER_INITIAL_BACKOFF_SECONDS:-2}" 1 300) || exit 64
maximum_backoff=$(validated_integer WORKER_MAX_BACKOFF_SECONDS "${WORKER_MAX_BACKOFF_SECONDS:-60}" 1 3600) || exit 64
stable_runtime=$(validated_integer WORKER_STABLE_RUNTIME_SECONDS "${WORKER_STABLE_RUNTIME_SECONDS:-300}" 1 86400) || exit 64
maximum_rapid_failures=$(validated_integer WORKER_MAX_RAPID_FAILURES "${WORKER_MAX_RAPID_FAILURES:-5}" 1 100) || exit 64

if [ "$initial_backoff" -gt "$maximum_backoff" ]; then
    log 'WORKER_INITIAL_BACKOFF_SECONDS must not exceed WORKER_MAX_BACKOFF_SECONDS'
    exit 64
fi

script_directory=$(CDPATH= cd "$(dirname "$0")" && pwd) || {
    log 'cannot resolve the worker script directory'
    exit 70
}

worker_uid=$(id -u www-data) || {
    log 'worker account is unavailable'
    exit 70
}
worker_gid=$(id -g www-data) || {
    log 'worker group is unavailable'
    exit 70
}
current_uid=$(id -u) || {
    log 'cannot determine worker identity'
    exit 70
}
current_gid=$(id -g) || {
    log 'cannot determine worker identity'
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
    exec gosu www-data:www-data "$script_directory/start-worker.sh" "$@"
    exit 70
fi

if [ "$current_uid" -ne "$worker_uid" ] || [ "$current_gid" -ne "$worker_gid" ]; then
    log 'worker must run as the dedicated runtime account'
    exit 77
fi

umask 027

active_pid=''
stop_requested=0
stop_status=0
stop_signal=TERM

request_stop() {
    stop_requested=1
    stop_status=$1
    stop_signal=$2
    if [ -n "$active_pid" ]; then
        kill "-$stop_signal" "$active_pid" 2>/dev/null || :
    fi
}

trap 'request_stop 143 TERM' TERM
trap 'request_stop 130 INT' INT

rapid_failures=0
backoff_seconds=$initial_backoff

while [ "$stop_requested" -eq 0 ]; do
    started_at=$(date +%s) || {
        log 'cannot read the system clock'
        exit 70
    }

    php "$script_directory/process_notifications.php" --loop "--sleep=$poll_seconds" &
    active_pid=$!
    if [ "$stop_requested" -ne 0 ]; then
        kill "-$stop_signal" "$active_pid" 2>/dev/null || :
    fi

    if wait "$active_pid"; then
        worker_status=0
    else
        worker_status=$?
    fi

    if [ "$stop_requested" -ne 0 ]; then
        wait "$active_pid" 2>/dev/null || :
        active_pid=''
        exit "$stop_status"
    fi
    active_pid=''

    finished_at=$(date +%s) || {
        log 'cannot read the system clock'
        exit 70
    }
    runtime_seconds=$((finished_at - started_at))
    if [ "$runtime_seconds" -lt 0 ]; then
        runtime_seconds=0
    fi

    if [ "$runtime_seconds" -ge "$stable_runtime" ]; then
        rapid_failures=0
        backoff_seconds=$initial_backoff
        log 'worker exited after a stable runtime; restarting'
    else
        rapid_failures=$((rapid_failures + 1))
        if [ "$rapid_failures" -ge "$maximum_rapid_failures" ]; then
            log 'rapid failure limit reached; exiting for platform restart policy'
            exit 1
        fi
        # The child status is intentionally not echoed alongside environment
        # or command details. Child output remains the source of diagnostics.
        if [ "$worker_status" -eq 0 ]; then
            log 'worker stopped unexpectedly; restarting with backoff'
        else
            log 'worker failed rapidly; restarting with backoff'
        fi
    fi

    if [ "$stop_requested" -ne 0 ]; then
        exit "$stop_status"
    fi
    sleep "$backoff_seconds" &
    active_pid=$!
    if [ "$stop_requested" -ne 0 ]; then
        kill "-$stop_signal" "$active_pid" 2>/dev/null || :
    fi
    wait "$active_pid" 2>/dev/null || :
    active_pid=''
    if [ "$stop_requested" -ne 0 ]; then
        exit "$stop_status"
    fi

    next_backoff=$((backoff_seconds * 2))
    if [ "$next_backoff" -gt "$maximum_backoff" ]; then
        backoff_seconds=$maximum_backoff
    else
        backoff_seconds=$next_backoff
    fi
done

exit "$stop_status"
