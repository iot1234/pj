#!/usr/bin/env bash
# Sourced by ci.yml; reuse its disposable database network and schema setup.
for daily_suite in booking payment; do
  daily_database="appj_daily_${daily_suite}_ci"
  daily_username="daily_${daily_suite}_runtime"
  docker exec --env MYSQL_PWD="$CI_DBA_PASSWORD" "$database" mysql \
    --host=127.0.0.1 --user=root \
    --execute="CREATE DATABASE ${daily_database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  run_database_setup "$daily_database" "$daily_username"
  docker run --rm --network "$network" --entrypoint php \
    --env APP_ENV=testing --env APP_DEBUG=false --env APP_URL=http://localhost \
    --env APP_TIMEZONE=Asia/Bangkok --env APP_KEY="$CI_APP_KEY" \
    --env FORCE_HTTPS=false --env RUNTIME_ROLE=job \
    --env DB_HOST="$database" --env DB_PORT=3306 --env DB_DATABASE="$daily_database" \
    --env DB_USERNAME="$daily_username" --env DB_PASSWORD="$CI_DB_PASSWORD" --env DB_SSL=false \
    "$image" "tests/daily_${daily_suite}_mysql.php"
done

# Fault injection and upgrade replay need DDL, exclusively in opted-in fixtures.
for daily_suite in schema migration; do
  daily_database="appj_daily_${daily_suite}_ci"
  docker exec --env MYSQL_PWD="$CI_DBA_PASSWORD" "$database" mysql \
    --host=127.0.0.1 --user=root \
    --execute="CREATE DATABASE ${daily_database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  if [ "$daily_suite" = schema ]; then
    run_database_setup "$daily_database" daily_schema_runtime
    daily_script=tests/daily_schema_fault_mysql.php
  else
    daily_script=tests/daily_migration_mysql.php
  fi
  docker run --rm --network "$network" --entrypoint php \
    --env APP_ENV=testing --env APP_DEBUG=false --env APP_URL=http://localhost \
    --env APP_TIMEZONE=Asia/Bangkok --env APP_KEY="$CI_APP_KEY" \
    --env FORCE_HTTPS=false --env RUNTIME_ROLE=job \
    --env DAILY_SCHEMA_FAULT_TEST=1 --env DAILY_MIGRATION_TEST=1 \
    --env DB_HOST="$database" --env DB_PORT=3306 --env DB_DATABASE="$daily_database" \
    --env DB_USERNAME=root --env DB_PASSWORD="$CI_DBA_PASSWORD" --env DB_SSL=false \
    "$image" "$daily_script"
done
