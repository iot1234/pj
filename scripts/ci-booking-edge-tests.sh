#!/usr/bin/env bash
set -Eeuo pipefail

mode="${1:-}"
database="${2:-}"
csrf="${3:-}"
rate_room_ids=(0 0 0 0 0 "${4:-}" "${5:-}" "${6:-}")
web="${7:-}"

if [[ -z "$mode" || -z "$database" || -z "$csrf" || -z "${rate_room_ids[5]}" || -z "${rate_room_ids[6]}" || -z "${rate_room_ids[7]}" ]]; then
  echo "Missing booking edge-test arguments" >&2
  exit 64
fi
if [[ -z "${CI_DBA_PASSWORD:-}" || -z "${CI_DB_DATABASE:-}" ]]; then
  echo "Missing CI database environment" >&2
  exit 64
fi

case "$mode" in
  cross)
    # An expired hold in another room must be cancelled in a short
    # preflight transaction so the same phone can book a free room
    # without reintroducing a room<->booking lock cycle.
    cross_old_room_id="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT r.id FROM rooms r
        WHERE r.id IN (${rate_room_ids[5]},${rate_room_ids[6]})
          AND NOT EXISTS (SELECT 1 FROM bookings b
            WHERE b.room_id=r.id AND b.status IN ('pending','confirmed'))
        ORDER BY r.id LIMIT 1")"
    [ -n "$cross_old_room_id" ]
    docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --host=127.0.0.1 --user=root \
      --database="$CI_DB_DATABASE" --execute="
        INSERT INTO bookings
          (reference_no,room_id,full_name,phone_norm,booked_monthly_rent,
           status,idempotency_key,created_at,updated_at)
        SELECT 'BK-CI-CROSS-EXPIRED',id,'CI Cross Expired','0878888888',
               monthly_rent,'pending','ci-cross-expired-old-000001',
               DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),
               DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)
        FROM rooms WHERE id=${cross_old_room_id};"
    cross_replacement_body="$(printf '{\"room_id\":%s,\"full_name\":\"CI Cross Replacement\",\"phone\":\"0878888888\",\"idempotency_key\":\"ci-cross-expired-new-000001\"}' \
      "${rate_room_ids[7]}")"
    cross_replacement_status="$(curl --silent --show-error \
      --output /tmp/ci-cross-replacement.json --write-out '%{http_code}' \
      --header 'X-Forwarded-Proto: https' \
      --header 'Content-Type: application/json' \
      --header 'Origin: https://ci-dormitory.example.co.th' \
      --header "X-CSRF-Token: ${csrf}" --data "$cross_replacement_body" \
      http://127.0.0.1:18080/api/public/bookings)"
    cross_replacement_state="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT CONCAT(
          SUM(idempotency_key='ci-cross-expired-old-000001'
            AND status='cancelled'
            AND cancel_reason LIKE 'Automatically expired after % seconds'),
          '|',SUM(idempotency_key='ci-cross-expired-new-000001'
            AND status='pending'),
          '|',SUM(phone_norm='0878888888'
            AND status IN ('pending','confirmed')))
        FROM bookings
        WHERE idempotency_key IN
          ('ci-cross-expired-old-000001','ci-cross-expired-new-000001')")"
    [ "$cross_replacement_status" = 201 ]
    [ "$cross_replacement_state" = '1|1|1' ]
    ;;
  phone)
    # Exercise the phone quota across two completed holds. The third
    # request must persist the phone block without creating a booking.

    for attempt in $(seq 1 3); do
      room_id="${rate_room_ids[$((attempt + 4))]}"
      body="$(printf '{\"room_id\":%s,\"full_name\":\"CI Phone Rate\",\"phone\":\"0899999999\",\"idempotency_key\":\"ci-phone-rate-%06d\"}' \
        "$room_id" "$attempt")"
      status="$(curl --silent --show-error \
        --output "/tmp/ci-phone-rate-${attempt}.json" \
        --write-out '%{http_code}' \
        --header 'X-Forwarded-Proto: https' \
        --header 'Content-Type: application/json' \
        --header 'Origin: https://ci-dormitory.example.co.th' \
        --header "X-CSRF-Token: ${csrf}" \
        --data "$body" \
        http://127.0.0.1:18080/api/public/bookings)"
      if [ "$attempt" -le 2 ]; then
        [ "$status" = 201 ]
        docker exec \
          --env MYSQL_PWD="$CI_DBA_PASSWORD" \
          "$database" mysql --host=127.0.0.1 --user=root \
          --database="$CI_DB_DATABASE" --execute="
            UPDATE bookings
            SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),
                cancel_reason='CI phone quota transition',updated_at=UTC_TIMESTAMP()
            WHERE idempotency_key='$(printf 'ci-phone-rate-%06d' "$attempt")'
              AND status='pending';"
      else
        [ "$status" = 429 ]
        grep --quiet '"code":"RATE_LIMITED"' "/tmp/ci-phone-rate-${attempt}.json"
      fi
    done

    phone_booking_count="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT COUNT(*) FROM bookings WHERE phone_norm='0899999999'")"
    denied_phone_booking_count="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT COUNT(*) FROM bookings
        WHERE idempotency_key='ci-phone-rate-000003'")"
    committed_phone_block="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT COUNT(*) FROM rate_limits
        WHERE hits=3 AND blocked_until IS NOT NULL
          AND TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),blocked_until)>80000")"
    phone_quota_bucket_shape="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT CONCAT(COUNT(*),'|',
          SUM(hits=2 AND blocked_until IS NULL),'|',
          SUM(hits=3 AND blocked_until IS NOT NULL),'|',
          SUM(hits=3 AND blocked_until IS NULL))
        FROM rate_limits")"
    if [[ "$phone_booking_count" != 2 \
        || "$denied_phone_booking_count" != 0 \
        || "$committed_phone_block" != 1 \
        || "$phone_quota_bucket_shape" != '3|1|1|1' ]]; then
      echo "Unexpected phone quota state: bookings=$phone_booking_count denied=$denied_phone_booking_count block=$committed_phone_block buckets=$phone_quota_bucket_shape" >&2
      exit 1
    fi
    ;;
  post)
    if [[ -z "$web" ]]; then
      echo "Missing web container argument" >&2
      exit 64
    fi
    # A phone with an active booking is an identity conflict, not a
    # third successful-looking attempt. It must not be falsely blocked
    # after reaching two legitimate daily hits.
    stable_phone_bucket="$(docker exec "$web" php -r \
      'echo hash_hmac("sha256","public-booking-phone:0867777777",(string)getenv("APP_KEY"));')"
    for attempt in 1 2; do
      room_id="${rate_room_ids[$((attempt + 4))]}"
      stable_body="$(printf '{\"room_id\":%s,\"full_name\":\"CI Active Phone\",\"phone\":\"0867777777\",\"idempotency_key\":\"ci-active-phone-%06d\"}' \
        "$room_id" "$attempt")"
      stable_status="$(curl --silent --show-error \
        --output "/tmp/ci-active-phone-${attempt}.json" --write-out '%{http_code}' \
        --header 'X-Forwarded-Proto: https' \
        --header 'Content-Type: application/json' \
        --header 'Origin: https://ci-dormitory.example.co.th' \
        --header "X-CSRF-Token: ${csrf}" --data "$stable_body" \
        http://127.0.0.1:18080/api/public/bookings)"
      [ "$stable_status" = 201 ]
      if [ "$attempt" = 1 ]; then
        docker exec \
          --env MYSQL_PWD="$CI_DBA_PASSWORD" \
          "$database" mysql --host=127.0.0.1 --user=root \
          --database="$CI_DB_DATABASE" --execute="
            UPDATE bookings
            SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),
                cancel_reason='CI active-phone transition',updated_at=UTC_TIMESTAMP()
            WHERE idempotency_key='ci-active-phone-000001' AND status='pending';"
      fi
    done
    stable_conflict_body="$(printf '{\"room_id\":%s,\"full_name\":\"CI Active Phone\",\"phone\":\"0867777777\",\"idempotency_key\":\"ci-active-phone-000003\"}' \
      "${rate_room_ids[7]}")"
    stable_conflict_status="$(curl --silent --show-error \
      --output /tmp/ci-active-phone-conflict.json --write-out '%{http_code}' \
      --header 'X-Forwarded-Proto: https' \
      --header 'Content-Type: application/json' \
      --header 'Origin: https://ci-dormitory.example.co.th' \
      --header "X-CSRF-Token: ${csrf}" --data "$stable_conflict_body" \
      http://127.0.0.1:18080/api/public/bookings)"
    stable_phone_state="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT CONCAT(
          (SELECT COUNT(*) FROM bookings
            WHERE phone_norm='0867777777' AND status IN ('pending','confirmed')),
          '|',COALESCE((SELECT hits FROM rate_limits
            WHERE bucket_key='${stable_phone_bucket}'),-1),
          '|',COALESCE((SELECT blocked_until IS NOT NULL FROM rate_limits
            WHERE bucket_key='${stable_phone_bucket}'),-1))")"
    [ "$stable_conflict_status" = 409 ]
    grep --quiet '"code":"BOOKING_PHONE_ACTIVE"' /tmp/ci-active-phone-conflict.json
    [ "$stable_phone_state" = '1|2|0' ]
    docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --host=127.0.0.1 --user=root \
      --database="$CI_DB_DATABASE" --execute="
        UPDATE bookings
        SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),
            cancel_reason='CI active-phone cleanup',updated_at=UTC_TIMESTAMP()
        WHERE idempotency_key='ci-active-phone-000002' AND status='pending';"

    # Same-room duplicate delivery must serialize on the physical room,
    # create exactly one row, and return one idempotent replay without
    # consuming a second successful-booking quota.
    same_room_body="$(printf '{\"room_id\":%s,\"full_name\":\"CI Same Room\",\"phone\":\"0844444444\",\"idempotency_key\":\"ci-same-room-key-000001\"}' \
      "${rate_room_ids[7]}")"
    curl --silent --show-error \
      --output /tmp/ci-same-room-a.json --write-out '%{http_code}' \
      --header 'X-Forwarded-Proto: https' \
      --header 'Content-Type: application/json' \
      --header 'Origin: https://ci-dormitory.example.co.th' \
      --header "X-CSRF-Token: ${csrf}" --data "$same_room_body" \
      http://127.0.0.1:18080/api/public/bookings >/tmp/ci-same-room-a.status &
    same_room_pid_a=$!
    curl --silent --show-error \
      --output /tmp/ci-same-room-b.json --write-out '%{http_code}' \
      --header 'X-Forwarded-Proto: https' \
      --header 'Content-Type: application/json' \
      --header 'Origin: https://ci-dormitory.example.co.th' \
      --header "X-CSRF-Token: ${csrf}" --data "$same_room_body" \
      http://127.0.0.1:18080/api/public/bookings >/tmp/ci-same-room-b.status &
    same_room_pid_b=$!
    wait "$same_room_pid_a"
    wait "$same_room_pid_b"
    same_room_statuses="$(cat /tmp/ci-same-room-a.status):$(cat /tmp/ci-same-room-b.status)"
    case "$same_room_statuses" in
      201:200|200:201) ;;
      *)
        echo "Unexpected same-room replay statuses: $same_room_statuses"
        jq --compact-output '{ok,message,code:.data.code,replay:.data.idempotent_replay}' \
          /tmp/ci-same-room-a.json /tmp/ci-same-room-b.json || true
        exit 1
        ;;
    esac
    same_room_replay_count="$(jq --slurp '[.[] | select(.data.idempotent_replay == true)] | length' \
      /tmp/ci-same-room-a.json /tmp/ci-same-room-b.json)"
    same_room_booking_count="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT COUNT(*) FROM bookings
        WHERE idempotency_key='ci-same-room-key-000001'")"
    [ "$same_room_replay_count" = 1 ]
    [ "$same_room_booking_count" = 1 ]

    # An original retry must retain its terminal idempotency semantics
    # after the room is soft-deleted. This also proves expiry commits
    # before the BOOKING_EXPIRED response is raised.
    docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --host=127.0.0.1 --user=root \
      --database="$CI_DB_DATABASE" --execute="
        UPDATE bookings
        SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),
            cancel_reason='CI same-room transition',updated_at=UTC_TIMESTAMP()
        WHERE idempotency_key='ci-same-room-key-000001' AND status='pending';
        INSERT INTO bookings
          (reference_no,room_id,full_name,phone_norm,booked_monthly_rent,
           status,idempotency_key,created_at,updated_at)
        SELECT 'BK-CI-DELETED-REPLAY',id,'CI Deleted Replay','0855555555',
               monthly_rent,'pending','ci-deleted-replay-000001',
               DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),
               DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)
        FROM rooms WHERE id=${rate_room_ids[7]};
        UPDATE rooms SET deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
        WHERE id=${rate_room_ids[7]};"
    deleted_replay_body="$(printf '{\"room_id\":%s,\"full_name\":\"CI Deleted Replay\",\"phone\":\"0855555555\",\"idempotency_key\":\"ci-deleted-replay-000001\"}' \
      "${rate_room_ids[7]}")"
    deleted_replay_status="$(curl --silent --show-error \
      --output /tmp/ci-deleted-replay.json --write-out '%{http_code}' \
      --header 'X-Forwarded-Proto: https' \
      --header 'Content-Type: application/json' \
      --header 'Origin: https://ci-dormitory.example.co.th' \
      --header "X-CSRF-Token: ${csrf}" --data "$deleted_replay_body" \
      http://127.0.0.1:18080/api/public/bookings)"
    deleted_replay_state="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT CONCAT(status,'|',
          cancel_reason LIKE 'Automatically expired after % seconds')
        FROM bookings WHERE idempotency_key='ci-deleted-replay-000001'")"
    [ "$deleted_replay_status" = 409 ]
    grep --quiet '"code":"BOOKING_EXPIRED"' /tmp/ci-deleted-replay.json
    [ "$deleted_replay_state" = 'cancelled|1' ]
    ;;
  *)
    echo "Unknown booking edge-test mode" >&2
    exit 64
    ;;
esac
