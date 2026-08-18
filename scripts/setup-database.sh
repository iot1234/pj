#!/usr/bin/env bash
set -Eeuo pipefail
set +x

if [[ "${RUNTIME_ROLE:-}" != "job" ]]; then
    printf '%s\n' 'database setup requires RUNTIME_ROLE=job' >&2
    exit 64
fi

script_directory="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

"${script_directory}/bootstrap_database.sh"
"${script_directory}/provision_runtime_db_user.sh"
