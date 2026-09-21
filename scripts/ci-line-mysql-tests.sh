#!/usr/bin/env bash
# Sourced by ci.yml to share its database setup function, containers and traps.
# Keep these suites in isolated schemas before the web quota tests begin.

# Legacy opening-reading upgrade guards run with the schema owner in an
# otherwise empty disposable database. The test imports its own fixtures.
pending_migration_database=appj_pending_schema_ci
docker exec \
  --env MYSQL_PWD="$CI_DBA_PASSWORD" \
  "$database" mysql --host=127.0.0.1 --user=root \
  --execute="CREATE DATABASE ${pending_migration_database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
docker run --rm \
  --network "$network" \
  --entrypoint php \
  --env APP_ENV=testing \
  --env DB_HOST="$database" \
  --env DB_PORT=3306 \
  --env DB_DATABASE="$pending_migration_database" \
  --env DB_USERNAME=root \
  --env DB_PASSWORD="$CI_DBA_PASSWORD" \
  --env DB_SSL=false \
  "$image" tests/pending_opening_migration_mysql.php

# The one-time admin completion path uses a restricted runtime account. Only
# its parent test receives DDL credentials to build legacy/fault fixtures.
pending_database=appj_pending_test_baselines
pending_username=pending_test_runtime
docker exec \
  --env MYSQL_PWD="$CI_DBA_PASSWORD" \
  "$database" mysql --host=127.0.0.1 --user=root \
  --execute="CREATE DATABASE ${pending_database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
run_database_setup "$pending_database" "$pending_username"
docker run --rm \
  --network "$network" \
  --entrypoint php \
  --env APP_ENV=testing \
  --env APP_DEBUG=false \
  --env APP_URL=http://localhost \
  --env APP_TIMEZONE=Asia/Bangkok \
  --env APP_KEY="$CI_APP_KEY" \
  --env FORCE_HTTPS=false \
  --env DB_HOST="$database" \
  --env DB_PORT=3306 \
  --env DB_DATABASE="$pending_database" \
  --env DB_USERNAME="$pending_username" \
  --env DB_PASSWORD="$CI_DB_PASSWORD" \
  --env DB_SSL=false \
  --env PENDING_SCHEMA_USERNAME=root \
  --env PENDING_SCHEMA_PASSWORD="$CI_DBA_PASSWORD" \
  "$image" tests/pending_opening_mysql.php

# Each LINE suite requires its own fresh schema and runtime account.
# The provisioner escapes schema underscores and verifies exact grants.
for line_suite in binding bot room_binding oa platform billing_delivery billing_defaults meter guide; do
  line_database="appj_line_test_${line_suite}"
  line_username="line_test_${line_suite}"
  docker exec \
    --env MYSQL_PWD="$CI_DBA_PASSWORD" \
    "$database" mysql --host=127.0.0.1 --user=root \
    --execute="CREATE DATABASE ${line_database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  run_database_setup "$line_database" "$line_username"
  if [ "$line_suite" = room_binding ]; then
    # Test-only fault injection is installed by the schema owner.
    # The runtime account keeps SELECT/INSERT/UPDATE privileges.
    docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --host=127.0.0.1 --user=root \
      --database="$line_database" --delimiter='//' --execute="
        CREATE TRIGGER trg_line_room_test_audit_failure
        BEFORE INSERT ON audit_logs FOR EACH ROW
        BEGIN
          IF CAST(NEW.action AS BINARY)=CAST(COALESCE(@line_room_test_fail_audit_action,'') AS BINARY) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated room binding audit failure';
          END IF;
        END//
        CREATE TRIGGER trg_line_room_test_notice_failure
        BEFORE INSERT ON line_notice_outbox FOR EACH ROW
        BEGIN
          IF COALESCE(@line_room_test_fail_notice_insert,0)=1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated room binding notice failure';
          END IF;
        END//"
  fi
  line_scripts=("tests/line_${line_suite}_mysql.php")
  case "$line_suite" in
    billing_delivery) line_scripts=(tests/billing_line_delivery_mysql.php) ;;
    billing_defaults) line_scripts=(tests/billing_defaults_mysql.php) ;;
    guide) line_scripts=(tests/billing_guidance_mysql.php) ;;
    meter) line_scripts=(tests/meter_recovery_mysql.php) ;;
  esac
  for line_script in "${line_scripts[@]}"; do
    docker run --rm \
      --network "$network" \
      --entrypoint php \
      --env APP_ENV=testing \
      --env APP_DEBUG=false \
      --env APP_URL=http://localhost \
      --env APP_TIMEZONE=Asia/Bangkok \
      --env APP_KEY="$CI_APP_KEY" \
      --env FORCE_HTTPS=false \
      --env DB_HOST="$database" \
      --env DB_PORT=3306 \
      --env DB_DATABASE="$line_database" \
      --env DB_USERNAME="$line_username" \
      --env DB_PASSWORD="$CI_DB_PASSWORD" \
      --env DB_SSL=false \
      "$image" "$line_script"
  done
done

# Readiness tests use a separate schema so missing-table/index probes
# cannot change the business lifecycle database. Only the test parent
# receives DDL credentials; its HTTP child uses the runtime account.
healthz_database=appj_healthz_test_readiness
healthz_username=healthz_test_readiness
docker exec \
  --env MYSQL_PWD="$CI_DBA_PASSWORD" \
  "$database" mysql --host=127.0.0.1 --user=root \
  --execute="CREATE DATABASE ${healthz_database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
run_database_setup "$healthz_database" "$healthz_username"
docker run --rm \
  --network "$network" \
  --entrypoint php \
  --env APP_ENV=testing \
  --env APP_DEBUG=false \
  --env APP_URL=http://localhost \
  --env APP_TIMEZONE=Asia/Bangkok \
  --env APP_KEY="$CI_APP_KEY" \
  --env FORCE_HTTPS=false \
  --env DB_HOST="$database" \
  --env DB_PORT=3306 \
  --env DB_DATABASE="$healthz_database" \
  --env DB_USERNAME="$healthz_username" \
  --env DB_PASSWORD="$CI_DB_PASSWORD" \
  --env DB_SSL=false \
  --env HEALTHZ_SCHEMA_USERNAME=root \
  --env HEALTHZ_SCHEMA_PASSWORD="$CI_DBA_PASSWORD" \
  "$image" tests/healthz_mysql.php

# Lifecycle fixtures include LINE/phone rate-limit buckets and fake
# integration keys. Keep them out of the web quota/worker database.
lifecycle_database=appj_ci_lifecycle
lifecycle_username=lifecycle_test_runtime
docker exec \
  --env MYSQL_PWD="$CI_DBA_PASSWORD" \
  "$database" mysql --host=127.0.0.1 --user=root \
  --execute="CREATE DATABASE ${lifecycle_database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
run_database_setup "$lifecycle_database" "$lifecycle_username"
docker run --rm \
  --network "$network" \
  --entrypoint php \
  --env APP_ENV=testing \
  --env APP_DEBUG=false \
  --env APP_URL=http://localhost \
  --env APP_TIMEZONE=Asia/Bangkok \
  --env APP_KEY="$CI_APP_KEY" \
  --env FORCE_HTTPS=false \
  --env DB_HOST="$database" \
  --env DB_PORT=3306 \
  --env DB_DATABASE="$lifecycle_database" \
  --env DB_USERNAME="$lifecycle_username" \
  --env DB_PASSWORD="$CI_DB_PASSWORD" \
  --env DB_SSL=false \
  "$image" tests/lifecycle_mysql.php

# Audit the lifecycle's populated schema with the temporary owner;
# the long-running web/worker account remains least privilege.
docker run --rm \
  --network "$network" \
  --entrypoint php \
  --env APP_ENV=testing \
  --env APP_DEBUG=false \
  --env APP_URL=http://localhost \
  --env APP_TIMEZONE=Asia/Bangkok \
  --env APP_KEY="$CI_APP_KEY" \
  --env FORCE_HTTPS=false \
  --env DB_HOST="$database" \
  --env DB_PORT=3306 \
  --env DB_DATABASE="$lifecycle_database" \
  --env DB_USERNAME=root \
  --env DB_PASSWORD="$CI_DBA_PASSWORD" \
  --env DB_SSL=false \
  "$image" scripts/check_requirements.php --db --schema-audit
