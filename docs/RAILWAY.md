# Deploy บน Railway

Railway ต้องรับค่า config และ secret ผ่านหน้า **Variables** ของแต่ละ service
เท่านั้น ห้าม commit `.env`, `APP_KEY`, รหัสผ่าน หรือ API key ลง Git repository

โครงสร้าง production ที่แนะนำมี 5 services ใน environment เดียวกัน:

- `MySQL` — ฐานข้อมูล
- `web` — Apache/PHP และ public domain
- `worker` — ประมวลผล notification outbox ต่อเนื่อง
- `database-setup` — job ชั่วคราวสำหรับติดตั้ง schema และ runtime user แล้วลบทิ้ง
- `monthly-billing` — Railway Cron สำหรับสร้างบิลของเดือนที่ปิดแล้ว

## 1. สร้าง domain ของ web ก่อน

สร้าง service ชื่อ `web` จาก repository นี้ แล้ว Generate Domain ในหน้า Networking ก่อน
เมื่อ Railway สร้าง `${{RAILWAY_PUBLIC_DOMAIN}}` แล้ว ให้นำตัวแปรนั้นมากำหนด `APP_URL` แต่ยังไม่ต้องตั้ง
Healthcheck Path และยังไม่ควรเปิดใช้งาน web จนกว่าฐานข้อมูลจะพร้อม

ถ้าใช้ชื่อ service อื่นแทน `web` ต้องเปลี่ยน namespace ที่ทั้ง `worker` และ
`monthly-billing` อ้างอิงให้ตรง

## 2. เตรียมฐานข้อมูลแบบ one-time

สร้าง service ชั่วคราวชื่อ `database-setup` จาก repository เดียวกัน ห้ามมี public
domain, ห้ามตั้ง Healthcheck Path, ตั้ง `RUNTIME_ROLE=job` และตั้ง Start Command เป็น:

```text
/var/www/html/scripts/setup-database.sh
```

ตั้ง Variables ของ job ให้ครบ โดยเปลี่ยน namespace `MySQL` ให้ตรงกับชื่อ database
service จริง:

```dotenv
RUNTIME_ROLE=job

DB_DBA_HOST=${{MySQL.MYSQLHOST}}
DB_DBA_PORT=${{MySQL.MYSQLPORT}}
DB_DBA_USERNAME=${{MySQL.MYSQLUSER}}
DB_DBA_PASSWORD=${{MySQL.MYSQLPASSWORD}}

DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=dormitory_app
DB_PASSWORD=<random-hex-32-ถึง-128-ตัวอักษร>
DB_SSL=false
DB_SSL_CA=
```

`DB_DBA_*` ต้องเป็นบัญชี DBA/root ของ MySQL service ส่วน `DB_*` คือบัญชี runtime
ใหม่ ห้ามใช้ชื่อเดียวกันหรือรหัสเดียวกัน ค่าตัวอย่าง `DB_SSL=false` ใช้ได้เฉพาะ
Railway MySQL ที่เชื่อมผ่าน private host ใน environment เดียวกันตามหัวข้อ TLS ด้านล่าง
สคริปต์รองรับ MySQL 8.0.16 ขึ้นไปและจะ:

1. import `database/schema.sql` กับ `database/defaults.sql` เฉพาะฐานที่ว่าง
2. ปฏิเสธฐานที่ partial หรือ schema ไม่ตรง แทนการ import ทับข้อมูล
3. ตรวจ tables, triggers, constraints และ default rows
4. สร้าง runtime account ใหม่แบบ clean slate ภายใต้ named lock
5. ปฏิเสธ mandatory roles, stored-object definer และ session เก่าที่ยังใช้งาน
6. ให้เพียง `SELECT`, `INSERT`, `UPDATE` บน schema ที่กำหนด
7. login ด้วย runtime account เพื่อตรวจ role, grant และ schema ซ้ำ และตรวจ certificate/hostname
   ของ server เพิ่มเมื่อเปิด `DB_SSL=true`

รัน service นี้ครั้งเดียวและรอให้ deployment เป็น `Completed` ด้วย exit code `0`
จากนั้นลบ service และ `DB_DBA_*` ทันที ห้ามผูกคำสั่งนี้เป็น pre-deploy ถาวร และ
ห้ามรันพร้อมกับ web/worker/monthly-billing เพราะการสร้างบัญชีแบบ clean slate จะ
ปฏิเสธ active session

### ฐานข้อมูล Railway ที่ติดตั้งอยู่แล้ว

ห้ามรัน `database-setup` หรือ import `schema.sql` ทับฐานที่มีข้อมูล ให้ snapshot/backup
MySQL และทดสอบ restore ก่อน แล้วใช้ Railway Data/SQL console หรือ migration job ชั่วคราว
ที่ถือ `DB_DBA_*` รันไฟล์ตามลำดับ `001` (เฉพาะเมื่อยังไม่มี `integration_settings`) →
`002` หนึ่งครั้ง → `003` → `004_line_webhook.sql` → `005_booking_active_phone.sql` โดยตรวจและแก้ค่า legacy ใน
`residents.line_user_id`/`notification_outbox.recipient` ให้ตรง `^U[0-9a-f]{32}$`
ก่อนรัน `004` บัญชี `dormitory_app` ใช้รัน migration ไม่ได้เพราะไม่มี DDL
ก่อนรัน `005` ต้องยกเลิกหรือปิดคำขอซ้ำให้เหลือ pending/confirmed ไม่เกินหนึ่งรายการต่อเบอร์

จากนั้น deploy transitional commit `a52bc33` และรอให้ทุก web replica healthy ก่อน
snapshot/backup อีกครั้งแล้วรัน `006_remove_resident_pin.sql` เพื่อลบ
`residents.pin_hash` Migration 006 รันซ้ำได้แต่ห้ามรันขณะยังมีแอปรุ่น PIN หลังลบ
คอลัมน์แล้วแอปรุ่น PIN เดิม rollback ไม่ได้โดยไม่ restore schema/backup และ
**ห้าม deploy source ปัจจุบันในจุดนี้** เพราะ source ปัจจุบันต้องใช้ schema 007–012
ครบก่อน

ปิด public traffic/การเขียน, หยุด worker ทุก replica และ disable/pause schedule ของ
`monthly-billing` ตลอด maintenance window จากนั้นรัน
`007_notification_worker_fencing.sql` → `008_resident_access_credentials.sql` →
`009_occupancy_meter_baselines.sql` → `010_line_self_service_binding.sql` →
`011_line_add_friend_identity.sql` → `012_move_in_request_hash.sql` → `013_trigger_collation_pinning.sql` ตามลำดับ Migration `013` จำเป็นบน Railway เสมอ เพราะฐานที่ Railway สร้างให้ใช้ collation ปริยาย `utf8mb4_0900_ai_ci` ซึ่งทำให้การออกบิลล้มด้วย error 1267 จนกว่าจะรัน Migration `007` จะคืนงาน `processing`
รุ่นเก่าที่ไม่มี claim/lease เป็น `pending` โดยไม่เปลี่ยน retry key ส่วน `009`
จะหยุดเมื่อ lifecycle/meter legacy ไม่สอดคล้องและเปลี่ยนกฎการเขียนมิเตอร์ ต้อง
reconcile จนรันซ้ำผ่านก่อน deploy source ปัจจุบัน Fresh database จาก `install.sql`
มีโครงสร้างล่าสุดอยู่แล้วและไม่ต้องรัน `006`–`012`

เมื่อ 012 ผ่าน ให้ deploy source ปัจจุบันโดยยังปิด public traffic และยังไม่เริ่ม
worker/cron จากนั้นให้ Owner เข้า Admin ผ่านช่องทาง maintenance ที่จำกัดผู้ดูแล:

1. reissue activation code ให้ resident ที่ active เดิมทุกคนซึ่งยังไม่มี password/code
   และส่งมอบรหัสผ่านช่องทางส่วนตัว
2. ตรวจ/บันทึก billing settings และ PromptPay/LINE/slip integrations รวม LINE Official Account Basic ID ให้ครบ
3. สร้าง isolated one-shot schema-audit job ชั่วคราว โดย map `DB_USERNAME` และ
   `DB_PASSWORD` ของ job นี้ไปยังค่า schema owner จาก `DB_DBA_USERNAME`/
   `DB_DBA_PASSWORD` แล้วรัน `php scripts/check_requirements.php --schema-audit`;
   ห้ามใส่ DBA credential ใน web/worker/monthly-billing และลบ job/credential หลังผ่าน
4. รัน `php scripts/check_requirements.php --db --strict --production` ด้วย runtime
   user จนไม่มี error/warning

`/healthz.php` ตรวจ schema/readiness ของ web แต่ไม่แทน data gate ข้างต้น จึงห้ามเปิด
traffic เพียงเพราะ Railway healthcheck ผ่าน เมื่อ strict gate ผ่านและตรวจพบ 16 ตาราง,
19 triggers พร้อม body ตรง canonical และ CHECK 86 รายการแล้ว จึงเปิด traffic, เริ่ม worker, เปิด
monthly-billing schedule และลบ migration job/`DB_DBA_*`

## 3. ตั้งค่า web

สร้าง `APP_KEY` เป็น random hex 64 ตัวอักษรและเก็บค่าเดิมตลอดอายุระบบ เพราะใช้
เข้ารหัส integration secrets ตั้ง Variables ของ `web` ดังนี้:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
APP_TIMEZONE=Asia/Bangkok
APP_KEY=<random-64-hex-secret>
FORCE_HTTPS=true
TRUSTED_PROXIES=
RUNTIME_ROLE=web
SESSION_NAME=dormitory_session
SESSION_LIFETIME_SECONDS=43200
RESIDENT_ACTIVATION_TTL_SECONDS=604800

DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=dormitory_app
DB_PASSWORD=<ค่าเดียวกับ-database-setup>
DB_SSL=false
DB_SSL_CA=
```

### การเชื่อมต่อ MySQL และ TLS

Railway MySQL ใน project/environment เดียวกันควรใช้ `${{MySQL.MYSQLHOST}}` ซึ่งเป็น
private host และไม่เปิด TCP Proxy สู่สาธารณะ Railway private networking เข้ารหัส
การสื่อสารระหว่าง service ด้วย WireGuard อยู่แล้ว ในโหมดนี้ตัวอย่างใช้
`DB_SSL=false` เพราะ Railway MySQL template ไม่ได้ประกาศตัวแปร CA สำหรับตรวจ
certificate/hostname ของ MySQL เอง นี่เป็นการพึ่ง transport ของ platform ไม่ใช่
application-level MySQL TLS และต้องไม่อ้างว่า MySQL certificate ถูกตรวจแล้ว

ถ้าใช้ MySQL ภายนอก, public TCP endpoint หรือ provider ที่ให้ CA ซึ่งมี SAN ตรงกับ
`DB_HOST` ต้องวาง CA ที่อ่านได้ใน image/volume ด้วย absolute path เดียวกันของ
`database-setup`, `web`, `worker` และ `monthly-billing` แล้วตั้งทั้งสองค่านี้เหมือนกัน
ทุก service:

```dotenv
DB_SSL=true
DB_SSL_CA=/var/www/html/config/mysql-ca.pem
```

ห้ามเปิด `DB_SSL=true` โดยไม่มี CA ที่ตรงกับ hostname เพราะระบบจะหยุดแบบ
fail-closed ระหว่าง readiness/startup และต้องรันการ provision runtime user ขณะเปิด
ค่าเดียวกัน เพื่อให้ MySQL กำหนด `REQUIRE SSL` แก่บัญชี `dormitory_app` ด้วย
หาก Railway MySQL ที่ใช้อยู่ไม่ให้ CA/certificate ที่ตรวจชื่อ private host ได้ ให้คง
private WireGuard mode, ปิด public TCP Proxy และบันทึกข้อจำกัดนี้ไว้ใน security review

`SESSION_LIFETIME_SECONDS` ยังใช้กับ Admin/Owner ส่วน Resident ถูกจำกัดตายตัวที่ idle 15 นาทีและอายุรวม 1 ชั่วโมงและไม่มี trusted-device bypass Resident login ใช้เบอร์ของ resident/occupancy active ร่วมกับ password; ครั้งแรกใช้ activation code แบบครั้งเดียวเพื่อตั้ง password ค่า `RESIDENT_ACTIVATION_TTL_SECONDS` กำหนดอายุ code ได้ 900–2,592,000 วินาที (ค่าเริ่มต้น 604,800 หรือ 7 วัน) ผู้ดูแลต้องส่ง code ผ่านช่องทางส่วนตัวและออกใหม่ทันทีหากสงสัยว่ารั่ว

ห้ามใส่ `DB_DBA_*`, `DB_ROOT_PASSWORD` หรือ `ADMIN_PASSWORD` ไว้ใน web service
ชื่อฐานต้องตรงกับ `MYSQLDATABASE`; Railway มักใช้ชื่อ `railway` จึงห้ามใช้
`database/install.sql` ซึ่งสร้างฐาน `dormitory` โดยไม่ตรวจชื่อก่อน

Docker image จะปรับ Apache ให้ฟัง `PORT` ของ Railway อัตโนมัติ ให้ mount Railway
Volume ที่ `/var/www/html/storage` เฉพาะ web service เพื่อเก็บ session และสลิปข้าม
deployment แล้วตั้ง **Healthcheck Path** เป็น `/healthz.php` endpoint นี้ตอบ `200`
ต่อเมื่อ config, MySQL schema/defaults และ writable storage พร้อม มิฉะนั้นตอบ `503`
โดยไม่เปิดเผย DSN หรือ secret

service ที่ผูก Volume ใช้ replicas หลายตัวไม่ได้ จึงต้องตั้ง `web` เป็น **1 replica**
และยอมรับว่า redeploy อาจมี downtime ช่วงสั้นตามข้อจำกัดของ
[Railway Volumes](https://docs.railway.com/volumes/reference) ให้เปิด scheduled backup
ของ Volume และทดสอบกู้คืนจริงตาม
[Railway Volume Backups](https://docs.railway.com/volumes/backups) แยกจาก backup/restore
ของ MySQL เพราะสลิปกับ session ไม่ได้อยู่ใน database backup

Railway ใช้ Healthcheck Path เพื่อยืนยัน deployment ใหม่ก่อนสลับ traffic เท่านั้น
ไม่ใช่ระบบเฝ้าระวังต่อเนื่องหลัง deploy ตาม
[Railway Healthchecks](https://docs.railway.com/deployments/healthchecks) จึงต้องมี
external uptime monitor สำหรับ `/healthz.php` เพิ่มต่างหาก

ปล่อย `RAILWAY_RUN_UID` ของ web ว่าง เพราะ startup ต้องใช้ root เฉพาะปรับ ownership
ของ volume และตั้ง Apache; Apache จะลดสิทธิ์ request workers เป็น `www-data` เอง

เมื่อ Variables, volume และฐานข้อมูลพร้อมแล้วจึง deploy `web`

## 4. สร้าง Owner

ใช้ shell ของ container หรือ job ชั่วคราวที่มี Variables ชุด runtime ของ web กำหนด
`ADMIN_PASSWORD` เป็นรหัสสุ่มที่ยาวอย่างน้อย 20 ตัวและมีอย่างน้อย 2 กลุ่มอักขระ แล้วรัน:

```sh
printf '%s' "$ADMIN_PASSWORD" | php scripts/create_admin.php \
  --username=owner --password-stdin --role=owner
```

ลบ `ADMIN_PASSWORD` ทันทีเมื่อสำเร็จ ห้ามเก็บไว้กับ process ระยะยาว จากนั้น login
เข้า Admin → Settings เพื่อตั้ง billing, PromptPay receiver, LINE Channel access
token/Channel secret และ provider credentials ที่ระบบใช้งานจริง ค่าลับทั้งหมดนี้เก็บ
เข้ารหัสใน MySQL ไม่ต้องเพิ่มเป็น Railway Variables เมื่อค่าจำเป็นครบและ
`ADMIN_PASSWORD` ถูกลบแล้วจึงรัน production gate:

```sh
php scripts/check_requirements.php --db --strict --production
```

โหมด `--strict` จะจบด้วย exit code ที่ไม่ใช่ศูนย์เมื่อยังมี warning จึงห้ามตีความว่า
deploy พร้อมใช้งานจนกว่าคำสั่งนี้จะผ่าน

ใน LINE Developers Console ให้ตั้ง Webhook URL เป็น
`https://<web-domain>/api/webhooks/line` (URL เดียวกับที่หน้า Settings แสดง) แล้วเปิด
**Use webhook** และ **Webhook redelivery** จากนั้นกด **Verify** ต้องใช้ domain ของ `web` และ `APP_URL` ต้องตรง HTTPS origin นี้พอดี
route นี้รับ request จาก LINE โดยตรวจ `X-Line-Signature` ด้วย Channel secret จึงไม่ต้อง
และไม่ควรตั้ง public domain ให้ worker

## 5. ตั้งค่า worker

สร้าง service ชื่อ `worker` จาก repository เดียวกันและปล่อย Start Command ว่าง ตัว image จะเลือก worker จาก `RUNTIME_ROLE=worker` เอง หากต้องกำหนดคำสั่งเองให้ใช้ `/var/www/html/scripts/start-runtime.sh` เพื่อคงการตรวจ role แบบ fail-closed

ใช้ Variables ชุด runtime เดียวกับ web แต่มีข้อแตกต่างดังนี้:

```dotenv
RUNTIME_ROLE=worker
APP_URL=https://${{web.RAILWAY_PUBLIC_DOMAIN}}
# ปกติปล่อยว่าง: worker จะใช้ RAILWAY_REPLICA_ID อัตโนมัติ
WORKER_INSTANCE_ID=
```

`APP_KEY`, `DB_*`, timezone และ session config ต้องตรงกับ web ทั้งหมด ห้ามใช้
`${{RAILWAY_PUBLIC_DOMAIN}}` ของ worker เอง เพราะ worker ไม่มี public domain
`WORKER_INSTANCE_ID` ไม่ใช่ secret และไม่จำเป็นบน Railway; หากกำหนดเองต้องไม่ซ้ำกัน
ระหว่าง worker replicas มิฉะนั้น heartbeat หลาย process จะเขียนทับแถวเดียวกัน

- ไม่ต้องมี public domain
- ปล่อย Healthcheck Path ว่าง
- ไม่ต้อง mount volume
- ปล่อย `RAILWAY_RUN_UID` ว่าง; startup wrapper จะลดสิทธิ์จาก root เป็น
  `www-data:www-data` และตรวจ UID/GID เอง ถ้าลดสิทธิ์ไม่ได้จะหยุดแบบ fail-closed

wrapper จะ validate tuning values, forward `TERM`/`INT`, จำกัด rapid failures และใช้
bounded exponential backoff เพื่อไม่ให้เกิด restart storm

ตั้ง **Restart Policy** เป็น `Always` เมื่อแผน Railway รองรับ เพื่อให้ worker ระยะยาว
ถูกเริ่มใหม่แม้ process จบด้วย exit code `0` หากใช้ `On Failure` ให้คงจำนวน retry สูงสุด
ตามที่แผนรองรับและตั้ง external alert เพราะ service จะหยุดหลัง retry ครบ ดูพฤติกรรมแต่ละ
policy ได้ที่ [Railway Restart Policy](https://docs.railway.com/deployments/restart-policy)
การ restart อย่างเดียวไม่ยืนยันว่า worker ประมวลผลได้ จึงต้องตรวจ heartbeat และแจ้งเตือน
เมื่อ `age_seconds` เกิน 600 วินาทีตามหัวข้อ “ตรวจหลัง deploy” ด้วย

## 6. ตั้งค่า monthly-billing

สร้าง service ชื่อ `monthly-billing` จาก repository เดียวกันเป็น
[Railway Cron Job](https://docs.railway.com/cron-jobs) โดยไม่สร้าง public domain,
ไม่ตั้ง Healthcheck Path และไม่ mount Volume ตั้ง Variables ชุด runtime เดียวกับ `web`
โดยเฉพาะ `APP_KEY`, `APP_TIMEZONE` และ `DB_*` แล้วเพิ่ม:

```dotenv
RUNTIME_ROLE=job
APP_URL=https://${{web.RAILWAY_PUBLIC_DOMAIN}}
MONTHLY_BILLING_ADMIN_ID=<id-ของ-admin-ที่-active>
MONTHLY_BILLING_TIMEOUT_SECONDS=900
```

`MONTHLY_BILLING_ADMIN_ID` ต้องเป็น ID ของ Admin/Owner ที่ยัง active เพื่อให้ audit log
ระบุผู้รับผิดชอบได้ `MONTHLY_BILLING_TIMEOUT_SECONDS` รับค่า 60–3600 วินาที
(แนะนำ 900) ห้ามใส่ `DB_DBA_*` หรือ `ADMIN_PASSWORD` ใน service นี้ ตั้ง Start Command
เป็น:

```sh
sh /var/www/html/scripts/run_monthly_billing.sh --previous-month --apply
```

Railway ตีความ cron schedule เป็น UTC ถ้าต้องการสร้างบิลเวลา 03:00 น. วันที่ 3
ตามเวลาไทย (`Asia/Bangkok`, UTC+7) ให้ตั้ง:

```text
0 20 2 * *
```

schedule นี้ทำงาน 20:00 UTC วันที่ 2 ซึ่งตรงกับ 03:00 น. วันที่ 3 ในประเทศไทย
ให้รันคำสั่งเดียวกันโดยตัด `--apply` ออกเป็น dry-run และตรวจผลก่อนเปิด cron จริง
ถ้ารอบก่อนยังทำงานอยู่เมื่อถึงรอบใหม่ Railway จะข้าม execution ใหม่แทนการรันซ้อน
จึงต้องตั้ง alert เมื่อ deployment ของ cron ล้มเหลวหรือไม่มีผลสำเร็จตามรอบ และตรวจบิล
หลังรอบแรกเสมอ

## 7. ตรวจหลัง deploy

ตรวจอย่างน้อย:

```text
GET https://<web-domain>/healthz.php  -> 200 {"status":"ok"}
GET https://<web-domain>/             -> หน้าเว็บปกติ
POST https://<web-domain>/api/webhooks/line ไม่มีลายเซ็นที่ถูกต้อง -> ถูกปฏิเสธ
```

ตรวจ deployment log ของ `web` และ `worker` ว่าไม่มี restart loop แล้วทดสอบ login,
LINE webhook จากปุ่ม Verify ของ LINE Developers, สร้างบิล, อัปโหลดสลิป และ notification
ด้วยข้อมูลทดสอบก่อนใช้งานจริง วางแผน backup ทั้ง MySQL และ Railway Volume เป็นคนละชุด
เพราะ volume ไม่ได้รวมอยู่ใน database backup

ตรวจ heartbeat ด้วย runtime/maintenance SQL console โดยไม่ต้องเปิด endpoint สาธารณะ:

```sql
SELECT status, heartbeat_at,
       TIMESTAMPDIFF(SECOND, heartbeat_at, UTC_TIMESTAMP(6)) AS age_seconds,
       last_processed, last_sent, last_failed, last_retried,
       last_lost_claims, last_recovered
FROM notification_worker_heartbeats
ORDER BY heartbeat_at DESC
LIMIT 5;
```

ขณะ worker ทำงาน `heartbeat_at` ต้องใหม่ต่อเนื่องและ `age_seconds` ไม่ควรเกิน 600 วินาที
ให้ตั้ง alert เมื่อ heartbeat เกินเกณฑ์, มี `last_lost_claims`, มี stale claim
(`notification_outbox.status='processing' AND lease_until<=UTC_TIMESTAMP(6)`) หรือมีงาน
`failed` เพิ่มขึ้น `/healthz.php` ของ web ตรวจว่า schema heartbeat พร้อม แต่ตั้งใจไม่ทำให้
web ล่มตาม worker ดังนั้น HTTP 200 จาก web ไม่ใช่หลักฐานว่า notification worker ยังมีชีวิต

ลำดับที่ถูกต้องคือ Generate web domain → database setup/provision → ตั้ง web Variables
และ volume → เปิด web healthcheck/deploy → สร้าง Owner → สร้าง worker →
ตั้ง monthly-billing cron และทดสอบ dry-run
