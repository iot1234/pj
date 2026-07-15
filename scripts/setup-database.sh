#!/usr/bin/env bash
set -Eeuo pipefail
set +x

script_directory="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

"${script_directory}/bootstrap_database.sh"
"${script_directory}/provision_runtime_db_user.sh"
