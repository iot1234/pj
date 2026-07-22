# คู่มือตั้งค่า MySQL

ระบบต้องเชื่อมต่อฐานข้อมูลได้ก่อนจึงจะเปิดหน้าหลังบ้านได้ ดังนั้นค่าการเชื่อมต่อ MySQL (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) เป็นค่าโครงสร้างพื้นฐานที่ตั้งในไฟล์ `.env` หรือ secret manager ของเครื่อง deploy ไม่สามารถย้ายไปตั้งจากหน้า Admin ได้ phpMyAdmin เป็นเพียงหน้าจอสำหรับ import/ตรวจ/ดูแลฐานข้อมูล ไม่ใช่จุดที่แอปอ่านค่าการเชื่อมต่อ ส่วนเบอร์ PromptPay, LINE Channel access token/Channel secret และ API key ตรวจสลิปให้ตั้งจาก Admin → ตั้งค่า หลังระบบเชื่อมต่อฐานข้อมูลแล้ว

## ค่าที่ต้องตั้ง

| ค่า | ใช้ทำอะไร | Docker Compose | XAMPP/Laragon |
|---|---|---|---|
| `DB_HOST` | ชื่อหรือ IP ของ MySQL | Compose บังคับเป็น `db` ให้ app/worker | ปกติ `127.0.0.1` |
| `DB_PORT` | พอร์ตที่ PHP ใช้เชื่อม MySQL | Compose บังคับเป็น `3306` ภายใน network | ปกติ `3306` |
| `DB_FORWARD_PORT` | พอร์ตจากเครื่อง host เข้า MySQL container เพื่อดูแลฐานข้อมูล | ปกติ `3307`; ผูกเฉพาะ `127.0.0.1` | ไม่ได้ใช้ |
| `DB_DATABASE` | ชื่อฐานข้อมูล | ค่าเริ่มต้น `dormitory` | ต้องตรงกับฐานข้อมูลที่สร้าง/import |
| `DB_USERNAME` | บัญชี runtime ของเว็บและ worker | Compose สร้างให้ครั้งแรกและลดสิทธิ์เหลือ `SELECT`/`INSERT`/`UPDATE` | ต้องสร้างแยกจาก `root` |
| `DB_PASSWORD` | รหัสผ่านบัญชี runtime | ต้องไม่ว่างและต้องไม่ซ้ำ root password | ต้องตรงกับ runtime user |
| `DB_ROOT_PASSWORD` | รหัส root สำหรับ bootstrap MySQL container | ใช้เฉพาะ service `db` | เว้นว่างหรือลบออก |
| `DB_SSL`, `DB_SSL_CA` | TLS ระหว่าง PHP กับ MySQL ระยะไกล | network ส่วนตัวภายใน Compose ใช้ `false` ได้ | เปิดเมื่อ MySQL ระยะไกลรองรับและมี CA |

`DB_FORWARD_PORT` ไม่ใช่ `DB_PORT` ของแอป ตัวอย่างเช่นเครื่อง host อาจเชื่อม `127.0.0.1:3307` เพื่อดูแลฐานข้อมูล แต่ app/worker ใน Docker ยังคงเชื่อม `db:3306`

## ไฟล์ SQL และลำดับ import

การติดตั้งใหม่ต้องใช้ Oracle MySQL 8.0.16 ขึ้นไป ไม่ใช่ MariaDB โดยเลือกวิธีง่ายหรือวิธีแยกขั้นตอนดังนี้:

**วิธีง่ายสำหรับฐานใหม่ชื่อ `dormitory`:** เปิด phpMyAdmin ระดับ server แล้ว import `database/install.sql` เพียงไฟล์เดียว ไฟล์นี้รวมการสร้างฐาน, schema, triggers และค่าเริ่มต้นที่ปลอดภัยไว้แล้ว ไม่มีบัญชี Owner, ข้อมูลห้อง, demo หรือ credential ใด ๆ และห้าม import ทับฐานที่มีข้อมูลอยู่

ให้นำเข้าด้วยบัญชี DBA/schema owner ที่มีสิทธิ์อย่างน้อย `CREATE DATABASE`, `CREATE`, `REFERENCES`, `TRIGGER` และ `INSERT`; บัญชี runtime ของแอปใช้ติดตั้งไม่ได้ (`REFERENCES` จำเป็นต่อการสร้าง foreign keys) ตัวติดตั้งจะหยุดก่อนแก้ไขเมื่อพบฐานที่ไม่ว่าง และห้ามใช้ตัวเลือก force/continue-on-error เนื่องจาก MySQL DDL auto-commit หากการติดตั้งฐานใหม่ขาดกลางทาง ให้ยืนยันก่อนว่าไม่มีข้อมูลจริง แล้วลบเฉพาะฐาน `dormitory` ที่ติดตั้งค้างและ import ใหม่ตั้งแต่ต้น ห้าม import ซ้ำบนฐานที่ค้าง

ไฟล์ `install.sql` ถูกสร้างจาก source สามไฟล์ด้านล่างและตรวจว่าเป็นรุ่นปัจจุบันได้ด้วย `php scripts/build_install_sql.php --check` วิธีแยกไฟล์ต่อไปนี้เก็บไว้สำหรับผู้ที่ใช้ชื่อฐานอื่น, ไม่มีสิทธิ์ `CREATE DATABASE` หรือดูแลระบบแบบแยกขั้นตอน:

1. `database/00-create-database.sql` — สร้างและเลือกฐานข้อมูล `dormitory`; ข้ามได้เมื่อสร้างและเลือกฐานข้อมูลไว้แล้ว
2. `database/schema.sql` — สร้างตาราง, foreign keys, constraints, generated columns และ triggers
3. `database/defaults.sql` — สร้าง singleton settings ที่ไม่มี credential ไม่มีห้องหรือข้อมูลจำลอง และกำหนดค่าน้ำ/ค่าไฟเริ่มต้นเป็นศูนย์

`database/demo.sql` เป็นข้อมูลห้องทดสอบแบบเห็นชัดสำหรับเครื่องพัฒนาเท่านั้น ไม่ถูก import โดย Docker และห้าม import ใน production เจ้าของระบบต้องเพิ่มห้องจริงและตรวจบันทึกอัตราค่าน้ำ ค่าไฟ และวันครบกำหนดจากหน้า Admin ก่อนออกบิลครั้งแรก

การติดตั้งใหม่ที่ import `install.sql` ไฟล์เดียว หรือใช้วิธีขั้นสูง `schema.sql` แล้วตามด้วย `defaults.sql` มีโครงสร้างและ integrity triggers ล่าสุดครบแล้ว ไม่มี `residents.pin_hash` และไม่ต้องรันไฟล์ใน `database/migrations/` เพิ่ม

ฐานข้อมูลที่ติดตั้งจาก schema รุ่นเก่าไม่ควร import `schema.sql` ทับเพื่อหวังให้อัปเกรด ให้สำรองข้อมูล ทดสอบ restore บน staging และใช้ไฟล์ใน `database/migrations/` ด้วยบัญชี schema owner ที่มีสิทธิ์ DDL และคงอยู่ เพราะบัญชีนี้จะเป็น `DEFINER` ของ trigger; ห้ามลบบัญชีหลัง migration เว้นแต่ recreate trigger ครบภายใต้ definer ที่คงอยู่:

1. รัน `database/migrations/001_integration_settings.sql` หากฐานเดิมยังไม่มีตาราง `integration_settings`; ไฟล์นี้ idempotent
2. รัน `database/migrations/002_operational_hardening.sql` **หนึ่งครั้งเท่านั้น** เพื่อเพิ่ม snapshot ค่าเช่าตอนจอง/ชื่อผู้พัก/รหัสห้อง, payment verification lease และ integrity triggers ให้ครบ 15 รายการ ไฟล์นี้ไม่ใช่ idempotent และห้ามรันซ้ำ
3. รัน `database/migrations/003_append_only_guards.sql` หลัง `002` เพื่อซ่อมฐานที่เคยรัน `002` รุ่นต้นให้มี trigger แบบ append-only ของ `bill_items` และ `audit_logs` ครบ ไฟล์นี้ตรวจ schema ก่อนแก้และปลอดภัยต่อการรันซ้ำ
4. ตรวจแถว `residents.line_user_id` และ `notification_outbox.recipient` ให้เป็น `U` ตามด้วย hexadecimal ตัวพิมพ์เล็ก 32 ตัวทั้งหมด แล้วรัน `database/migrations/004_line_webhook.sql` เพื่อเพิ่ม Channel secret ที่เข้ารหัส, LINE request IDs สำหรับ reconciliation และ CHECK รูปแบบ LINE User ID ไฟล์จะหยุดก่อน ALTER หากพบค่า legacy ที่แก้ไม่ได้โดยอัตโนมัติ และปลอดภัยต่อการรันซ้ำ
5. ตรวจและปิดคำขอจองซ้ำให้แต่ละเบอร์เหลือสถานะ `pending`/`confirmed` ไม่เกินหนึ่งรายการ แล้วรัน `database/migrations/005_booking_active_phone.sql` เพื่อเพิ่ม generated unique guard ต่อเบอร์ ไฟล์จะหยุดก่อน ALTER หากยังมีข้อมูลซ้ำ และปลอดภัยต่อการรันซ้ำ
6. Deploy transitional commit `a52bc33` ให้ทุก replica healthy เพื่อสร้างขอบเขต rolling upgrade ที่ไม่รับ PIN แต่ยังรองรับ schema เก่าชั่วคราว
7. สำรองและทดสอบ restore แล้วรัน `database/migrations/006_remove_resident_pin.sql` เพื่อลบคอลัมน์ legacy ไฟล์นี้ปลอดภัยต่อการรันซ้ำ ห้ามรันขณะยังมีแอปรุ่น PIN และหลังรันแล้วห้าม rollback ไปแอปรุ่น PIN เดิมโดยไม่ restore schema/backup
8. ยืนยันว่า `residents.pin_hash` ไม่มีแล้วจึง deploy source ปัจจุบัน ซึ่งไม่มี runtime compatibility สำหรับคอลัมน์นี้
9. รัน `php scripts/check_requirements.php --db --strict` ด้วยบัญชี runtime แล้วรัน `php scripts/check_requirements.php --schema-audit` ด้วยบัญชี DBA/schema owner ชั่วคราว เพื่อตรวจ generated guards, คอลัมน์ migration 004, CHECK constraints, การไม่มี `pin_hash` และ trigger ทั้ง 15 รายการหลังอัปเกรด

ข้อมูล snapshot ของรายการเก่าที่ migration สร้างขึ้นเป็นการประกอบย้อนจากข้อมูลที่ยังมีอยู่: ค่าเช่าจะเลือกจาก occupancy ก่อนแล้วจึง fallback ไปค่าเช่าห้องปัจจุบัน ส่วนชื่อผู้พัก/รหัสห้องของบิลเก่าอาจไม่ใช่ค่าประวัติเดิมหากเคยแก้ไข จึงต้องตรวจเอกสารย้อนหลังหรือ export เดิมบน staging ก่อนเปิดใช้งานจริง

## ติดตั้งด้วย Docker Compose

สำหรับ production ให้เริ่มจาก template ที่ปลอดภัย:

```powershell
Copy-Item .env.production.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

นำค่าสุ่ม 64 ตัวใส่ `APP_KEY` เปลี่ยน `APP_URL` เป็น HTTPS origin จริง และกำหนด `DB_PASSWORD` กับ `DB_ROOT_PASSWORD` เป็นคนละค่าสุ่ม ห้ามใส่ LINE Channel access token/Channel secret หรือ slip credential ใน `.env`

สำหรับเครื่องพัฒนา local เท่านั้นให้ใช้:

```powershell
Copy-Item .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

จากนั้นเปิดระบบ:

```powershell
docker compose up --build -d
docker compose ps
docker compose exec app php scripts/check_requirements.php --db
```

เมื่อ volume `db_data` ว่าง MySQL จะ import `schema.sql` และ `defaults.sql` อัตโนมัติเพียงครั้งแรก แล้วลดสิทธิ์ `DB_USERNAME` เหลือ `SELECT`, `INSERT`, `UPDATE` โดยไม่มี `DELETE` หรือสิทธิ์ DDL การแก้ไฟล์ SQL ภายหลังไม่ทำให้ volume เดิม import ซ้ำ

ห้ามใช้ `docker compose down -v` กับระบบจริง เพราะจะลบทั้งฐานข้อมูลและไฟล์สลิปใน named volumes หากต้องการข้อมูลห้องตัวอย่างบนฐานข้อมูล local ที่แยกจากข้อมูลจริง ให้ import `database/demo.sql` ด้วยตนเองหลังระบบเริ่มแล้ว

## ติดตั้งด้วย XAMPP หรือ Laragon

XAMPP หลายรุ่นติดตั้ง MariaDB มาให้ ต้องตรวจ `SELECT VERSION();` ให้เป็น Oracle MySQL 8.0.16+ และตรวจว่า PHP 8.2+ เปิด `pdo_mysql`, `curl`, `mbstring`, `gd`, `fileinfo`, `openssl`

1. วิธีง่าย: เปิด phpMyAdmin ระดับ server แล้ว import `database/install.sql` ไฟล์เดียว
2. หากไม่มีสิทธิ์สร้างฐานหรือใช้ชื่อฐานอื่น ให้สร้าง/เลือกฐานนั้นเอง แล้ว import `database/schema.sql` ตามด้วย `database/defaults.sql`
3. สร้างบัญชี runtime แยกจาก `root` ด้วยบัญชี DBA:

```sql
CREATE USER 'dormitory_app'@'127.0.0.1'
IDENTIFIED BY 'เปลี่ยนเป็นรหัสสุ่มที่ยาวและไม่ซ้ำ';
GRANT SELECT, INSERT, UPDATE
ON dormitory.* TO 'dormitory_app'@'127.0.0.1';
```

4. คัดลอก `.env.example` เป็น `.env` สำหรับ local แล้วกำหนด:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dormitory
DB_USERNAME=dormitory_app
DB_PASSWORD=REPLACE_WITH_RUNTIME_PASSWORD
DB_ROOT_PASSWORD=
```

5. ตรวจระบบและสร้าง Owner คนแรก โดยส่งรหัสผ่านผ่าน standard input ไม่ใส่ใน command line:

```powershell
php scripts/check_requirements.php --db
$password = Read-Host 'รหัสผ่าน Owner' -AsSecureString
$plain = [System.Net.NetworkCredential]::new('', $password).Password
$plain | php scripts/create_admin.php --username=owner --role=owner --password-stdin
Remove-Variable plain,password
```

วางทั้งโครงการนอก `htdocs`, `www` และ public web root ของ default host แล้วตั้ง Apache/Laragon VirtualHost ให้ DocumentRoot ชี้เฉพาะโฟลเดอร์ `public/` ห้ามพึ่ง VirtualHost อย่างเดียวขณะที่รากโครงการยังอยู่ใต้ default web root เพราะ `.env`, source, SQL และ `storage/private` อาจถูกเปิดผ่าน hostname/path อื่น

## ตรวจว่าต่อฐานข้อมูลถูกตัว

รันใน phpMyAdmin หรือ MySQL client:

```sql
SELECT DATABASE() AS selected_database, VERSION() AS mysql_version;
SHOW TABLES;
```

`selected_database` ต้องไม่เป็น `NULL`, version ต้องเป็น MySQL 8.0.16 ขึ้นไป และหลัง import ต้องมีตารางระบบ 14 ตาราง จากนั้นรัน:

```powershell
php scripts/check_requirements.php --db
```

อย่าใช้ MySQL `root` เป็น `DB_USERNAME` ของเว็บ อย่าเปิดพอร์ต MySQL สู่อินเทอร์เน็ต และอย่านำรหัสผ่านจริงไปใส่ในคำสั่งที่ถูกบันทึกลง shell history

## หลังเชื่อม SQL สำเร็จ

1. สร้างบัญชี Owner คนแรกตาม README
2. เข้าหน้า Admin → ตั้งค่า แล้วบันทึกอัตราค่าน้ำ ค่าไฟ และวันครบกำหนดจริง
3. กรอก PromptPay, LINE Channel access token/Channel secret และผู้ให้บริการตรวจสลิปจากหน้าเดียวกัน ค่าลับจะถูกเข้ารหัสใน MySQL โดยใช้ key ที่ derive จาก `APP_KEY`; `APP_KEY` เองยังต้องอยู่ใน `.env`/secret manager และต้องตรงกันทุก web/worker instance
4. คัดลอก Webhook URL ที่หน้า Settings แสดง (`<APP_URL>/api/webhooks/line`) ไปใส่ใน LINE Developers Console เปิด **Use webhook** และ **Webhook redelivery** แล้วกด **Verify** โดย `APP_URL` ต้องเป็น HTTPS origin สาธารณะที่ตรงกับโดเมนจริง
5. เพิ่มห้องจริงจากหลังบ้าน; production ไม่มีห้องตัวอย่างอัตโนมัติ
6. รัน requirement checker อีกครั้งก่อนเปิดให้ผู้ใช้จริง ค่า LINE webhook จะพร้อมเมื่อถอดรหัสได้ทั้ง Channel access token และ Channel secret
