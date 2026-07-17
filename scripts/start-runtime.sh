#!/bin/sh
set -eu

script_directory=$(CDPATH= cd "$(dirname "$0")" && pwd)
role=${RUNTIME_ROLE:-}

case "$role" in
    web)
        exec "$script_directory/start-web.sh" "$@"
        ;;
    worker)
        exec "$script_directory/start-worker.sh" "$@"
        ;;
    job)
        echo "RUNTIME_ROLE=job requires an explicit one-shot command" >&2
        exit 64
        ;;
    *)
        echo "RUNTIME_ROLE must be web, worker, or job" >&2
        exit 64
        ;;
esac
