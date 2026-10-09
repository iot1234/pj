# Implementation contract

This directory started as a clean PHP 8.2 / MySQL 8 rewrite of FR-01 through
FR-16 from the repository-level `1.txt`, and now includes dedicated daily stays.
The old Node/PostgreSQL application
is reference material only and is not modified.

## Runtime conventions

- Daily rooms use a separate date-based booking/payment ledger. See
  [daily booking](docs/DAILY_BOOKING.md). Additive migrations 018/019/020 are required
  with this source, including global payment evidence and transfer allocation.
- Daily bookings lock the physical room first and allocate every overnight date
  under a generated unique room/night key. Checkout is exclusive. Monthly and
  daily rooms are explicitly separated; a room cannot change mode while in use.
- Daily guest capabilities authorize only their booking and are sent in a header,
  never as a query parameter or phone-based resident session.

- Front controller: `public/index.php`; local router: `router.php`.
- Autoload namespace: `Dormitory\\` mapped to `src/`.
- Every JSON response has `{ "ok": bool, "data": ..., "message": string? }`.
- JSON and multipart mutations require same-origin validation plus
  `X-CSRF-Token`: authenticated requests use a session token, while anonymous
  login/booking pages use a two-hour signed stateless guest token. The sole
  server-to-server exception is the LINE webhook, which authenticates the raw
  body with `X-Line-Signature` and the encrypted Channel secret.
- Login roles are owner and resident. All management routes require an active,
  non-retired owner. Former admin accounts are permanently disabled by migration
  017 without granting owner access. Legacy admin URLs, table and audit actor
  names remain compatibility identifiers.
- Resident login uses only a normalized phone belonging to exactly one active
  resident and occupancy. It reports auth_method=phone, assurance=low and
  phone_verified=false. No password, PIN or activation code is requested.
  Resident sessions expire after 15 minutes idle or one hour absolute. Name and
  email are editable; identity/room changes belong to the owner and revoke sessions.
- Owners authenticate with username/password, independently from residents.
- Room status is derived: active occupancy/daily check-in = `occupied`;
  pending/confirmed bookings or retained paid nights = `reserved` for their
  current stay date. Daily housekeeping adds `cleaning` until the owner marks
  the room ready. Future reservations and blocks also prevent deleting a room.
- Catalogue edits require the current HMAC room version at the HTTP boundary;
  housekeeping updates do not invalidate unrelated catalogue drafts.
- Periods use `YYYY-MM` at the API boundary and the first day of the month in
  MySQL `DATE` columns.
- Production traffic terminates HTTPS at a reverse proxy. The Compose HTTP
  port binds to loopback (`APP_BIND=127.0.0.1`) by default, and forwarded
  scheme/client headers are trusted only from explicit `TRUSTED_PROXIES`.

## Tables

`admin_users`, `residents`, `line_link_codes`, `rooms`, `bookings`, `occupancies`,
`meter_readings`, `billing_settings`, `integration_settings`, `bills`,
`bill_items`, `payments`, `notification_outbox`,
`notification_worker_heartbeats`, `audit_logs`, `rate_limits`,
`line_official_accounts`, `line_room_policies`, `line_room_bindings`,
`line_admin_recipients`, `line_notice_outbox`, and `transfer_instructions`, plus
`daily_bookings`, `daily_booking_nights`, `daily_room_blocks`,
`daily_booking_actions`, `daily_housekeeping_actions`, `payment_evidence_registry`,
`payment_amount_registry`, `daily_transfer_instructions`, `daily_payments`,
`daily_payment_actions`, `daily_refunds`, and `daily_deposit_settlements` (34 tables).

## Page routes

- `/` public available rooms and booking form
- `/resident/login`, `/resident` resident portal
- `/admin/login`, `/admin` owner console

## JSON API contract

### Public and authentication

- `POST /api/webhooks/line` accepts signed LINE `follow`/text-message events
  from direct users, deduplicates `webhookEventId`, consumes an exact self-service
  `BIND-` code when present, and otherwise replies with binding instructions. It
  never replies with the raw LINE User ID or stores inbound message content.
- `GET /api/public/rooms`
- `POST /api/public/bookings` `{room_id, full_name, phone, idempotency_key}`
- `POST /api/auth/admin/login` `{username,password}`
- `POST /api/auth/admin/logout`
- `POST /api/auth/resident/login` `{phone}` for the active resident and room.
- `POST /api/auth/resident/logout`
- `GET /api/auth/me`

### Resident

- `GET|PUT /api/resident/profile`
- `POST /api/resident/profile/line/code` `{}` returns a `BIND-` code once;
  the logged-in resident sends that exact code to the official account in a
  direct chat within 10 minutes. The code contains 128 random bits and MySQL
  stores only its keyed HMAC digest. `POST .../line/unlink` `{}` removes the
  binding. The retired `/line/start` and `/line/confirm` OTP endpoints are not
  routed, so an older session challenge cannot overwrite a self-service bind.
  Billing delivery requires the latest append-only audit proof to match the
  current LINE ID; legacy IDs without this proof are treated as unverified.
- `GET /api/resident/bills`
- `GET /api/resident/bills/{id}`
- `POST /api/resident/bills/{id}/promptpay` reserves the unique transfer amount;
  `GET /api/resident/bills/{id}/promptpay` reads an existing reserved instruction.
- `POST /api/resident/bills/{id}/slip` multipart field `slip`

### Owner

- `GET|POST /api/admin/users`; `PUT|DELETE /api/admin/users/{id}` manage only
  owner accounts. Retired historical accounts are read-only and cannot reactivate.
- `POST /api/admin/residents/{id}/access/reissue` is a compatibility revocation
  endpoint: it increments `auth_version`, clears legacy credentials and revokes
  existing resident sessions/LINE bindings. It returns phone access status and
  `sessions_revoked=true`, with no activation code, password or expiry.
- `GET|POST /api/admin/rooms`; `PUT|DELETE /api/admin/rooms/{id}`
- `GET /api/admin/residents` returns current residents, occupancy IDs, and
  their rooms.
- `PUT /api/admin/residents/{id}` `{full_name,phone,email}` lets an
  owner perform an identity-verified correction; changing the login phone
  increments `auth_version` and revokes existing resident sessions.
- `POST /api/admin/residents/{id}/move-out` `{move_out_date}` ends the active
  occupancy only after the closing-month bill exists, is paid, and no pending
  bill remains; a backdated move-out is rejected if later bills already exist.
- `GET /api/admin/bookings?status=&offset=&limit=` returns active work first by
  default plus `pending_count` and pagination metadata.
- `POST /api/admin/bookings/{id}/confirm`
- `POST /api/admin/bookings/{id}/cancel`
- `POST /api/admin/bookings/{id}/move-in`
  `{email?,move_in_date,opening_water_reading,opening_electric_reading,reuse_resident_id?}`; LINE is linked later by the
  resident through the verified flow above.
- `GET /api/admin/meters?period=YYYY-MM`
- `POST /api/admin/meters`
  `{room_id,period,water_current,electric_current,confirm_large_usage?}`;
  anomalous water/electric values are returned together before confirmation.
- `POST /api/admin/bills/preview`
  `{period,room_ids,water_rate,electric_rate,other_description,other_amount,due_date}`
- `POST /api/admin/bills/bulk` with the same body plus the short-lived
  `preview_token`; source data changes invalidate the token.
- `GET /api/admin/bills?period=YYYY-MM`
- `POST /api/admin/bills/{id}/line`
- `POST /api/admin/bills/line-bulk` `{period}`
- `GET /api/admin/payments?status=&offset=&limit=` exposes paginated automatic
  slip-verification results, with pending recovery work first by default.
- `GET /api/admin/payments/{id}/slip` returns authenticated, audited evidence
  inline with private/no-store caching.
- `POST /api/admin/payments/{id}/retry` re-verifies the same immutable evidence
  under a bounded verification lease.
- `POST /api/admin/payments/{id}/close` `{reason}` closes an expired pending
  verification without marking its bill paid. There is deliberately no manual
  paid/approve endpoint.
- `GET /api/admin/settings` returns billing settings plus masked integration
  settings/status metadata.
- `PUT /api/admin/settings` updates billing rates/due days.
- `PUT /api/admin/settings/integrations` is owner-only and updates PromptPay,
  LINE, SlipOK, and EasySlip operational settings.
- `POST /api/admin/settings/integrations/test` is owner-only and tests the
  saved LINE or slip-provider credential without returning the secret.

## Shared payloads

Room objects expose `id`, `room_code`, `floor`, `room_type`, `monthly_rent`,
`description`, `amenities` (array), `image_key`, `image_url`, and derived
`status`. Bill objects expose immutable meter/rate/amount snapshots and never
accept a client-provided total. PromptPay uses the server-reserved transfer amount:
the bill snapshot plus a 0.01–0.99 baht adjustment, stable across repeated QR
requests. QR readiness requires PromptPay and the transfer-allocation schema;
slip verification readiness is independent.

`integration_settings` is a singleton (`id=1`). Non-secret operational values
are returned normally, but the LINE Channel access token/Channel secret and
SlipOK/EasySlip credentials are never returned as plaintext or ciphertext. The
API exposes only a `*_configured` boolean and
masked `*_hint`. A missing, `null`, empty, or whitespace-only secret update
keeps the current value; only the matching `*_clear=true` removes it. Web and
worker processes query this row at use time, so an owner update applies without
an application restart.

## Security invariants

- PDO native prepared statements, transactions and `SELECT ... FOR UPDATE`
  for booking, owner management, move-in, bill generation, and payment finalization.
- Lazy session start, session rotation, strict cookies, session/stateless guest
  CSRF plus same-origin checks, DB-backed IP/account rate limits, generic login
  errors, owner password hashing, and `auth_version` session revocation.
  Resident sessions have fixed 15-minute idle and one-hour absolute limits and
  never use the trusted-device bypass. Phone login matches exactly one active
  resident/occupancy and a non-deleted room, and every request rechecks the
  phone, room, occupancy and `auth_version`. It requires no password or activation
  code and reports low assurance; knowing the phone number permits account access.
  Legacy activation/password columns remain for database compatibility. Check-in,
  move-in, historical-resident reuse and reissue do not issue or return credentials.
  Reissue revokes sessions through `auth_version` and retires existing LINE
  bindings. `RESIDENT_ACTIVATION_TTL_SECONDS` is ignored and no longer forwarded
  by Compose or validated by readiness; it does not control resident sessions.
- Slip files are JPEG/PNG/WebP at most 4 MiB, validated by magic bytes and
  dimensions, stored below `storage/private`, and protected by an APP_KEY-based
  HMAC. Owner evidence viewing revalidates the canonical path, MIME, size,
  dimensions, and HMAC and writes a strict audit event. External verification
  must match amount to one satang, receiver, and a globally unique transaction
  reference before a bill becomes paid. Missing receiver configuration or a
  receiver mismatch stays pending so an owner can correct settings and retry;
  terminally invalid evidence and amount mismatch are rejected.
- LINE Channel access token/Channel secret and slip API credentials are
  AES-256-GCM encrypted in `integration_settings`. The key is derived from
  environment-only `APP_KEY`,
  and field-specific AAD prevents moving a valid ciphertext between columns.
  Outbound URLs are fixed HTTPS allowlist endpoints, redirects are disabled,
  and responses are size/time bounded.
- Infrastructure configuration remains outside the operational settings table:
  `APP_KEY`, `APP_URL`, database connectivity/credentials, proxy trust, and
  other deployment controls come from environment/secret management. There is
  no environment fallback for PromptPay/LINE/slip operational settings.

## Database initialization and upgrade

Fresh databases import `database/schema.sql` followed by
`database/defaults.sql`; defaults creates the billing and integration singleton
rows without credentials, rooms, residents, or owner accounts. Fresh schema and
`database/install.sql` include migrations through 019.
`database/demo.sql` is optional local-development data and is never imported by
the production bootstrap.

Fresh schema no longer contains `residents.pin_hash`, and the current runtime
never probes or writes that column. An installation upgrading from an older
schema must use transitional commit `a52bc33` for the rolling boundary, wait
until every replica is healthy, run `006_remove_resident_pin.sql`, verify that
the column is absent, stop notification workers and run migration 007, then run
migration 008. Migration 009 requires a maintenance window with public traffic,
web/worker writes, and scheduled billing stopped. Keep them stopped through
migration 017, then deploy the matching source, verify resident phone/active-room
links and pass strict runtime and schema gates before reopening traffic or
restarting worker/cron. Passwords and activation codes are not required for
resident phone access. See [the upgrade sequence](docs/SQL_SETUP.md) and
[owner-only account migration](docs/OWNER_ONLY_MIGRATION.md).

Existing installations must be backed up and upgraded by a schema-owning
account. Run `database/migrations/001_integration_settings.sql` if the
integration table is absent, then run
`database/migrations/002_operational_hardening.sql` exactly once. Migration 002
adds booking/bill identity and rent snapshots, payment verification leases, and
the current set of 15 integrity triggers; it is intentionally not rerunnable.
Then run `database/migrations/003_append_only_guards.sql`. Migration 003 is a
rerunnable repair for installations that previously ran an older migration 002;
it recreates the four append-only `bill_items` and `audit_logs` update/delete
guards already present in the current schema and migration 002.
After correcting/quarantining legacy LINE IDs that do not match
`^U[0-9a-f]{32}$`, run `database/migrations/004_line_webhook.sql`. Migration
004 is rerunnable and adds the encrypted Channel secret, LINE provider request
IDs for reconciliation, strict 33-character ID columns, and their CHECK
constraints; its preflight stops before any ALTER when legacy values are
invalid.
Run `database/migrations/005_booking_active_phone.sql` after resolving duplicate
active bookings per phone, then deploy transitional commit `a52bc33` before
running `database/migrations/006_remove_resident_pin.sql`. Migration 006 is a
destructive schema cleanup, so back up and test restore first; an old
PIN-dependent application version cannot be rolled back after the column is
removed. With notification workers stopped, migration 007 adds claim-token
lease fencing and hashed worker heartbeat storage. Migration 008 adds resident
legacy activation/password columns, still retained for compatibility but no
longer required by resident login. Migration 009 must run after 008 with all
writes stopped; it binds readings to occupancies, installs two meter guards
and two booking/occupancy insert guards, and hardens bill creation. Deploy the current source only after migrations
006–017 succeed. Migration 010 is rerunnable for a compatible schema and adds
`line_link_codes`, two unique guards, two lookup indexes, a resident foreign key,
and four CHECK constraints. Migration 011 adds the public LINE Basic ID, while
migration 012 adds the nullable move-in request digest, its named CHECK, and the
matching immutable-evidence trigger body. Migration 013 pins trigger-variable
collations. Migration 014 adds the multi-OA LINE platform. Migration 015 allows
both unknown legacy opening readings to remain pending only where no meter or
bill history exists, and permits an audited one-time completion with real
readings. Migration 016 adds immutable unique transfer instructions. Migration
017 permits only owner account roles and permanently retires former admins,
preserving their account IDs, hashes, foreign keys and audit history. It also
revokes staff LINE invitations and invitations created by retired admins, and
fails their pending/processing notices without changing sent evidence. Existing
admins are never granted active ownership; an active original owner must exist
before their retirement. Keep web, worker and scheduled writes stopped through
017. Readiness must validate the enforced opening-reading and owner/retirement
guards; the original migration-017 schema contained 22 tables, 27 triggers and 121 CHECK
constraints. See [migration 017](docs/OWNER_ONLY_MIGRATION.md) for operator steps.
The current source also requires migrations 018/019/020; its exact deployment shape
is generated from canonical SQL and checked by the installer and readiness gates.
The application runtime account has only `SELECT`, `INSERT`, and `UPDATE` on the
application database and must not run any migration. After upgrade, an owner
configures integrations in Admin -> Settings.
