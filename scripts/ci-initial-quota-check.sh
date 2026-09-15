#!/usr/bin/env bash
set -Eeuo pipefail

database="${1:-}"
committed_daily_block="${2:-}"
if [[ -z "$database" || -z "$committed_daily_block" || -z "${CI_DBA_PASSWORD:-}" || -z "${CI_DB_DATABASE:-}" ]]; then
  echo "Missing initial quota-check arguments" >&2
  exit 64
fi

initial_quota_bucket_shape="$(docker exec \
  --env MYSQL_PWD="$CI_DBA_PASSWORD" \
  "$database" mysql --batch --skip-column-names \
  --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
  --execute="SELECT CONCAT(COUNT(*),'|',SUM(hits=0),'|',
      SUM(hits=1),'|',SUM(hits=6)) FROM rate_limits")"
if [[ "$committed_daily_block" != 1 || "$initial_quota_bucket_shape" != '13|6|5|2' ]]; then
  echo "Initial booking quota mismatch: committed daily blocks=${committed_daily_block} (expected 1); buckets total|hits0|hits1|hits6=${initial_quota_bucket_shape} (expected 13|6|5|2)" >&2
  exit 1
fi
echo "PASS initial booking quota: 5 bookings, persistent sixth-request block, 13 isolated buckets"
