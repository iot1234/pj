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
        SELECT monthly_rent INTO @ci_cross_rent FROM rooms WHERE id=${cross_old_room_id};
        INSERT INTO bookings
          (reference_no,room_id,full_name,phone_norm,booked_monthly_rent,
           status,idempotency_key,created_at,updated_at)
        VALUES ('BK-CI-CROSS-EXPIRED',${cross_old_room_id},'CI Cross Expired','0878888888',
               @ci_cross_rent,'pending','ci-cross-expired-old-000001',
               DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),
               DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY));"
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
        || "$phone_quota_bucket_shape" != '4|1|1|1' ]]; then
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

    # A phone already attached to an active occupancy must not create a
    # second-room booking. The per-table UNIQUE guards alone cannot enforce
    # this cross-table identity invariant. Follow the real booking transitions
    # and supply resident access and meter baselines required by current guards.
    docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --host=127.0.0.1 --user=root \
      --database="$CI_DB_DATABASE" --execute="
        SELECT monthly_rent INTO @ci_active_rent FROM rooms WHERE id=${rate_room_ids[5]};
        INSERT INTO residents
          (full_name,phone_norm,email,active,auth_version,
           activation_code_hash,activation_expires_at)
        VALUES ('CI Active Resident','0833333333',NULL,1,1,
                LOWER(HEX(RANDOM_BYTES(32))),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 DAY));
        SET @ci_active_resident_id=LAST_INSERT_ID();
        INSERT INTO bookings
          (reference_no,room_id,full_name,phone_norm,booked_monthly_rent,
           status,idempotency_key)
        VALUES ('BK-CI-ACTIVE-RESIDENT',${rate_room_ids[5]},'CI Active Resident','0833333333',
                @ci_active_rent,'pending','ci-active-resident-ledger-000001');
        SET @ci_active_resident_booking_id=LAST_INSERT_ID();
        UPDATE bookings SET status='confirmed',confirmed_at=UTC_TIMESTAMP()
        WHERE id=@ci_active_resident_booking_id AND status='pending';
        UPDATE bookings SET status='moved_in',resident_id=@ci_active_resident_id,moved_in_at=UTC_TIMESTAMP()
        WHERE id=@ci_active_resident_booking_id AND status='confirmed';
        INSERT INTO occupancies
          (resident_id,room_id,booking_id,monthly_rent,status,move_in_date,
           opening_water_reading,opening_electric_reading)
        VALUES (@ci_active_resident_id,${rate_room_ids[5]},@ci_active_resident_booking_id,
                @ci_active_rent,'active',CURRENT_DATE(),0.00,0.00);"
    occupied_phone_body="$(printf '{\"room_id\":%s,\"full_name\":\"CI Duplicate Resident\",\"phone\":\"0833333333\",\"idempotency_key\":\"ci-active-resident-public-000001\"}' \
      "${rate_room_ids[6]}")"
    occupied_phone_status="$(curl --silent --show-error \
      --output /tmp/ci-active-resident-public.json --write-out '%{http_code}' \
      --header 'X-Forwarded-Proto: https' \
      --header 'Content-Type: application/json' \
      --header 'Origin: https://ci-dormitory.example.co.th' \
      --header "X-CSRF-Token: ${csrf}" --data "$occupied_phone_body" \
      http://127.0.0.1:18080/api/public/bookings)"
    occupied_phone_booking_count="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT COUNT(*) FROM bookings
        WHERE idempotency_key='ci-active-resident-public-000001'")"
    [ "$occupied_phone_status" = 409 ]
    grep --quiet '"code":"RESIDENT_ALREADY_OCCUPIED"' /tmp/ci-active-resident-public.json
    [ "$occupied_phone_booking_count" = 0 ]

    # The inverse transition is guarded by the same mutex: an admin cannot
    # change an active resident to a phone held by a pending booking.
    docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --host=127.0.0.1 --user=root \
      --database="$CI_DB_DATABASE" --execute="
        SELECT monthly_rent INTO @ci_admin_phone_rent FROM rooms WHERE id=${rate_room_ids[6]};
        INSERT INTO bookings
          (reference_no,room_id,full_name,phone_norm,booked_monthly_rent,
           status,idempotency_key)
        VALUES ('BK-CI-ADMIN-PHONE',${rate_room_ids[6]},'CI Pending Phone','0822222222',
                @ci_admin_phone_rent,'pending','ci-admin-phone-held-000001');"
    admin_phone_guard="$(docker exec "$web" php -r '
      $app=require "/var/www/html/bootstrap.php";
      $id=(int)$app->database()->pdo()->query(
        "SELECT id FROM residents WHERE phone_norm=\"0833333333\""
      )->fetchColumn();
      try{$app->residents()->updateByAdmin($id,["phone"=>"0822222222"]);}
      catch(\Dormitory\Http\HttpException $error){
        if($error->status===409&&$error->errorCode==="BOOKING_PHONE_ACTIVE"){
          echo "guarded";exit(0);
        }
      }
      exit(1);')"
    [ "$admin_phone_guard" = guarded ]
    unchanged_resident_phone="$(docker exec \
      --env MYSQL_PWD="$CI_DBA_PASSWORD" \
      "$database" mysql --batch --skip-column-names \
      --host=127.0.0.1 --user=root --database="$CI_DB_DATABASE" \
      --execute="SELECT phone_norm FROM residents
        WHERE full_name='CI Active Resident'")"
    [ "$unchanged_resident_phone" = 0833333333 ]

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
        SELECT monthly_rent INTO @ci_deleted_rent FROM rooms WHERE id=${rate_room_ids[7]};
        INSERT INTO bookings
          (reference_no,room_id,full_name,phone_norm,booked_monthly_rent,
           status,idempotency_key,created_at,updated_at)
        VALUES ('BK-CI-DELETED-REPLAY',${rate_room_ids[7]},'CI Deleted Replay','0855555555',
               @ci_deleted_rent,'pending','ci-deleted-replay-000001',
               DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY),
               DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY));
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
