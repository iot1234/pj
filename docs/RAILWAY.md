# Deploy บน Railway

Railway ต้องรับค่า config และ secret ผ่านหน้า **Variables** ของแต่ละ service
เท่านั้น ห้าม commit `.env`, `APP_KEY`, รหัสผ่าน หรือ API key ลง Git repository

โครงสร้าง production ที่แนะนำมี 4 services ใน environment เดียวกัน:

- `MySQL` — ฐานข้อมูล
- `web` — Apache/PHP และ public domain
- `worker` — ประมวลผล notification outbox ต่อเนื่อง
- `database-setup` — job ชั่วคราวสำหรับติดตั้ง schema และ runtime user แล้วลบทิ้ง

## 1. สร้าง domain ของ web ก่อน

สร้าง service ชื่อ `web` จาก repository นี้ แล้ว Generate Domain ในหน้า Networking ก่อน
กำหนด `APP_URL` เพื่อให้ `${{RAILWAY_PUBLIC_DOMAIN}}` resolve ได้ แต่ยังไม่ต้องตั้ง
Healthcheck Path และยังไม่ควรเปิดใช้งาน web จนกว่าฐานข้อมูลจะพร้อม

ถ้าใช้ชื่อ service อื่นแทน `web` ต้องเปลี่ยน namespace ที่ worker อ้างอิงให้ตรง

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
DB_PASSWORD=<random-hex-อย่างน้อย-32-ตัวอักษร>
```

`DB_DBA_*` ต้องเป็นบัญชี DBA/root ของ MySQL service ส่วน `DB_*` คือบัญชี runtime
ใหม่ ห้ามใช้ชื่อเดียวกันหรือรหัสเดียวกัน สคริปต์รองรับ MySQL 8.0.16 ขึ้นไปและจะ:

1. import `database/schema.sql` กับ `database/defaults.sql` เฉพาะฐานที่ว่าง
2. ปฏิเสธฐานที่ partial หรือ schema ไม่ตรง แทนการ import ทับข้อมูล
3. ตรวจ tables, triggers, constraints และ default rows
4. สร้าง runtime account ใหม่แบบ clean slate ภายใต้ named lock
5. ปฏิเสธ mandatory roles, stored-object definer และ session เก่าที่ยังใช้งาน
6. ให้เพียง `SELECT`, `INSERT`, `UPDATE` บน schema ที่กำหนด
7. login ด้วย runtime account เพื่อตรวจ server identity, role, grant และ schema ซ้ำ

รัน service นี้ครั้งเดียวและรอให้ deployment เป็น `Completed` ด้วย exit code `0`
จากนั้นลบ service และ `DB_DBA_*` ทันที ห้ามผูกคำสั่งนี้เป็น pre-deploy ถาวร และ
ห้ามรันพร้อมกับ web/worker เพราะการสร้างบัญชีแบบ clean slate จะปฏิเสธ active session

### ฐานข้อมูล Railway ที่ติดตั้งอยู่แล้ว

ห้ามรัน `database-setup` หรือ import `schema.sql` ทับฐานที่มีข้อมูล ให้ snapshot/backup
MySQL และทดสอบ restore ก่อน แล้วใช้ Railway Data/SQL console หรือ migration job ชั่วคราว
ที่ถือ `DB_DBA_*` รันไฟล์ตามลำดับ `001` (เฉพาะเมื่อยังไม่มี `integration_settings`) →
`002` หนึ่งครั้ง → `003` → `004_line_webhook.sql` → `005_booking_active_phone.sql` โดยตรวจและแก้ค่า legacy ใน
`residents.line_user_id`/`notification_outbox.recipient` ให้ตรง `^U[0-9a-f]{32}$`
ก่อนรัน `004` บัญชี `dormitory_app` ใช้รัน migration ไม่ได้เพราะไม่มี DDL
ก่อนรัน `005` ต้องยกเลิกหรือปิดคำขอซ้ำให้เหลือ pending/confirmed ไม่เกินหนึ่งรายการต่อเบอร์

จากนั้น deploy transitional commit `a52bc33` และรอให้ทุก web replica healthy ก่อนจึง snapshot/backup อีกครั้งแล้วรัน
`006_remove_resident_pin.sql` เพื่อลบ `residents.pin_hash` Migration 006 รันซ้ำได้แต่ห้ามรันขณะยังมีแอปรุ่น PIN;
หลังลบคอลัมน์แล้วแอปรุ่น PIN เดิม rollback ไม่ได้โดยไม่ restore schema/backup ให้ตรวจว่าคอลัมน์หายแล้วจึง deploy
source ปัจจุบัน ซึ่งไม่อ่านหรือเขียน `pin_hash` อีก Fresh database จาก `install.sql` ไม่มีคอลัมน์นี้และไม่ต้องรัน 006

หลัง migration ให้รัน `php scripts/check_requirements.php --schema-audit` ด้วย schema
owner และ `php scripts/check_requirements.php --db --strict --production` ด้วย runtime
user เมื่อผ่านแล้วจึงลบ migration job กับ `DB_DBA_*` ออก

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

DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=dormitory_app
DB_PASSWORD=<ค่าเดียวกับ-database-setup>
DB_SSL=false
DB_SSL_CA=
```

`SESSION_LIFETIME_SECONDS` ยังใช้กับ Admin/Owner ส่วน Resident ถูกจำกัดตายตัวที่ idle 15 นาทีและอายุรวม 1 ชั่วโมงและไม่มี trusted-device bypass Resident login ใช้เฉพาะเบอร์ของ resident/occupancy active; Admin/Owner ยังใช้ username/password ผู้ที่รู้เบอร์ resident active สามารถ takeover ครั้งแรกได้แม้มี rate limit จึงควรเพิ่ม OTP/MFA หากระดับความเสี่ยงยอมรับ phone-only ไม่ได้

ห้ามใส่ `DB_DBA_*`, `DB_ROOT_PASSWORD` หรือ `ADMIN_PASSWORD` ไว้ใน web service
ชื่อฐานต้องตรงกับ `MYSQLDATABASE`; Railway มักใช้ชื่อ `railway` จึงห้ามใช้
`database/install.sql` ซึ่งสร้างฐาน `dormitory` โดยไม่ตรวจชื่อก่อน

Docker image จะปรับ Apache ให้ฟัง `PORT` ของ Railway อัตโนมัติ ให้ mount Railway
Volume ที่ `/var/www/html/storage` เฉพาะ web service เพื่อเก็บ session และสลิปข้าม
deployment แล้วตั้ง **Healthcheck Path** เป็น `/healthz.php` endpoint นี้ตอบ `200`
ต่อเมื่อ config, MySQL schema/defaults และ writable storage พร้อม มิฉะนั้นตอบ `503`
โดยไม่เปิดเผย DSN หรือ secret

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
```

`APP_KEY`, `DB_*`, timezone และ session config ต้องตรงกับ web ทั้งหมด ห้ามใช้
`${{RAILWAY_PUBLIC_DOMAIN}}` ของ worker เอง เพราะ worker ไม่มี public domain

- ไม่ต้องมี public domain
- ปล่อย Healthcheck Path ว่าง
- ไม่ต้อง mount volume
- ปล่อย `RAILWAY_RUN_UID` ว่าง; startup wrapper จะลดสิทธิ์จาก root เป็น
  `www-data:www-data` และตรวจ UID/GID เอง ถ้าลดสิทธิ์ไม่ได้จะหยุดแบบ fail-closed

wrapper จะ validate tuning values, forward `TERM`/`INT`, จำกัด rapid failures และใช้
bounded exponential backoff เพื่อไม่ให้เกิด restart storm

## 6. ตรวจหลัง deploy

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

ลำดับที่ถูกต้องคือ Generate web domain → database setup/provision → ตั้ง web Variables
และ volume → เปิด web healthcheck/deploy → สร้าง Owner → สร้าง worker
