# ระบบบริหารหอพัก PHP / MySQL

ระบบนี้เป็นการเขียนใหม่ด้วย PHP 8.2+ และ MySQL 8 โดยยกมาเฉพาะ FR-01 ถึง FR-16 จาก `1.txt`: ห้องและการจอง ผู้เช่า มิเตอร์ บิล LINE พร้อมเพย์ และตรวจสลิป ไม่มีการพึ่งพา Node.js/PostgreSQL เดิม

หากติดตั้งใหม่และต้องการขั้นตอนสั้นที่สุด ให้เริ่มที่ [`START_HERE.md`](START_HERE.md) โดย XAMPP/Laragon สามารถ import `database/install.sql` **ไฟล์เดียว** ผ่าน phpMyAdmin ได้ ส่วน Docker ติดตั้ง SQL และเปิด worker ให้อัตโนมัติ

รายละเอียดการครอบคลุมแต่ละข้ออยู่ที่ [docs/FEATURE_MATRIX.md](docs/FEATURE_MATRIX.md), คู่มือตั้งค่า MySQL อยู่ที่ [docs/SQL_SETUP.md](docs/SQL_SETUP.md), ผลวิเคราะห์ระบบเดิมและแนวทางย้ายข้อมูลอยู่ที่ [docs/MIGRATION_ANALYSIS.md](docs/MIGRATION_ANALYSIS.md), ผลทดสอบอยู่ที่ [docs/QA_REPORT.md](docs/QA_REPORT.md) และแนวทางความปลอดภัยอยู่ที่ [docs/SECURITY.md](docs/SECURITY.md)

## ความต้องการของระบบ

- PHP 8.2 ขึ้นไป (Docker ใช้ PHP 8.3)
- MySQL 8.0.16 ขึ้นไป แนะนำ MySQL 8.4; ไม่แนะนำ MariaDB เพราะ constraint/generated column อาจทำงานต่างกัน
- PHP extensions: `pdo_mysql`, `curl`, `mbstring`, `gd`, `fileinfo`, `json`, `openssl`, `session`
- Apache เปิด `mod_rewrite` และตั้ง DocumentRoot ไปที่โฟลเดอร์ `public/`
- HTTPS สำหรับ production

## เตรียมค่า environment

เลือก template ให้ตรงกับสภาพแวดล้อม แล้วแก้ `.env` ด้วย editor ที่ไม่ส่งไฟล์ขึ้น cloud สำหรับเครื่องพัฒนา local เท่านั้น:

```powershell
Copy-Item .env.example .env
```

สำหรับ production:

```powershell
Copy-Item .env.production.example .env
```

จากนั้นสร้าง `APP_KEY` ใหม่สำหรับทั้ง local และ production:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

นำค่าสุ่ม 64 ตัวที่ได้ใส่ `APP_KEY` จากนั้นกำหนดค่าอย่างน้อยดังนี้:

- `.env.example` เป็น local development เท่านั้น ห้ามนำไป deploy production
- production ต้องเริ่มจาก `.env.production.example` แล้วเปลี่ยน `APP_URL`, `APP_KEY`, รหัสฐานข้อมูล และค่า proxy ให้ตรงระบบจริง
- `APP_ENV=development`, `APP_DEBUG=true` และ `FORCE_HTTPS=false` ใช้ได้เฉพาะเครื่อง local
- production ต้องใช้ `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, `FORCE_HTTPS=true`
- เปลี่ยน `DB_PASSWORD` เป็นค่าสุ่มที่เดายากเสมอ; `DB_ROOT_PASSWORD` ต้องเป็นคนละค่าสุ่มและใช้เฉพาะ Docker Compose ตอนดูแลฐานข้อมูล ห้ามส่งให้ web/worker
- XAMPP/Laragon ไม่ใช้ `DB_ROOT_PASSWORD` จากแอป ให้เว้นว่างหรือลบค่านี้จาก `.env` หลัง DBA สร้าง database/runtime user แล้ว
- เมื่อใช้ Docker ให้ `DB_DATABASE` และ `DB_USERNAME` มีเฉพาะ A-Z, a-z, 0-9 หรือ `_` (`DB_USERNAME` ไม่เกิน 32 ตัว) เพื่อให้สคริปต์ลดสิทธิ์ตรวจสอบค่าได้แบบ fail-closed
- `APP_KEY` ต้องคงเดิมและมีค่าเดียวกันใน web/worker ทุก instance เพราะใช้สร้าง HMAC และถอดรหัส token/API key ใน `integration_settings`; ห้ามหมุนค่าโดยไม่มีขั้นตอน re-encrypt หรือล้างค่าลับด้วย key เดิมแล้วกรอกใหม่หลังเปลี่ยน key
- `TRUSTED_PROXIES` ใส่เฉพาะ IP ของ reverse proxy ที่ควบคุมเอง คั่นด้วย comma; หากไม่ได้ใช้ proxy ให้เว้นว่าง
- ค่าโครงสร้างพื้นฐาน เช่น `APP_KEY`, `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` และ `DB_PASSWORD` ยังต้องมาจาก environment/secret manager ส่วนเบอร์พร้อมเพย์, LINE token และ SlipOK/EasySlip key ไม่ต้องและไม่ควรใส่ใน `.env`

ห้าม commit `.env` และห้ามส่งไฟล์นี้ทางแชตหรืออีเมล

## ติดตั้งด้วย Docker (แนะนำ)

1. ติดตั้ง Docker Desktop และตรวจว่าคำสั่ง `docker compose version` ทำงานได้
2. เตรียม `.env` ตามหัวข้อก่อนหน้า: local ใช้ `.env.example`; production ใช้ `.env.production.example` โดยต้องใส่ `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` และเปลี่ยน `APP_URL`
3. สร้างและเปิดระบบ:

```powershell
docker compose up --build -d
docker compose ps
docker compose exec app php scripts/check_requirements.php --db
```

เครื่อง local เปิด `http://localhost:8080` หรือ URL/port ที่กำหนดใน `.env`; production ต้องเปิดผ่าน HTTPS URL จริงและ reverse proxy ที่กำหนดไว้ โดย Compose ผูกพอร์ตเว็บกับ `APP_BIND=127.0.0.1` เป็นค่าเริ่มต้น ไม่เปิด plain HTTP ให้เครือข่ายภายนอกโดยอัตโนมัติ

MySQL จะ import `database/schema.sql` และ `database/defaults.sql` เฉพาะครั้งแรกที่ volume `db_data` ยังว่าง จากนั้น `database/03-runtime-grants.sh` จะลดสิทธิ์ `DB_USERNAME` เหลือ `SELECT`, `INSERT`, `UPDATE` เท่านั้น ไม่มี `DELETE` หรือสิทธิ์ DDL ส่วน `defaults.sql` ไม่มีห้อง ผู้เช่า บัญชีผู้ดูแล หรือ credential และกำหนดอัตราค่าน้ำ/ไฟเป็นศูนย์ เจ้าของระบบต้องเพิ่มห้องจริงและบันทึกอัตราจริงก่อนออกบิล `database/demo.sql` เป็นข้อมูลห้องทดสอบที่ต้อง import เองบนฐาน local เท่านั้น

หาก `db_data` มีอยู่ก่อนเพิ่มสคริปต์ลดสิทธิ์ init file จะไม่รันย้อนหลัง ให้สำรองข้อมูลแล้วรันหนึ่งครั้ง จากนั้นตรวจด้วยบัญชี runtime:

```powershell
docker compose exec db bash /docker-entrypoint-initdb.d/03-runtime-grants.sh
docker compose exec app php scripts/check_requirements.php --db
```

Compose เปิดทั้ง `app` และ `worker`; worker เป็นตัวส่ง LINE จาก outbox ทุก 15 วินาที ส่วน `.dockerignore` กัน `.env`, session, log และสลิปออกจาก build context/image layers

หากแก้ SQL หลังฐานข้อมูลถูกสร้างแล้ว ให้ทำ migration/import อย่างตั้งใจและสำรองข้อมูลก่อน คำสั่ง `docker compose down -v` จะลบฐานข้อมูลและไฟล์สลิปใน named volumes ทั้งหมด จึงใช้ได้เฉพาะการรีเซ็ตเครื่องพัฒนาเท่านั้น

ดู log โดยไม่เปิดเผย `.env`:

```powershell
docker compose logs --tail 100 app
docker compose logs --tail 100 worker
docker compose logs --tail 100 db
```

## ติดตั้งบน XAMPP

XAMPP บางรุ่นมี MariaDB หรือ PHP เก่า ต้องตรวจให้เป็น PHP 8.2+ และ MySQL 8 จริงก่อน หากไม่ตรงให้ใช้ Docker หรือเปลี่ยน runtime

1. วางโครงการ **นอก** `htdocs`, `www` และ public web root อื่นทั้งหมด แล้วตั้ง Apache VirtualHost ให้ `DocumentRoot` ชี้ `C:/path/to/php-mysql/public` เท่านั้น ห้ามวางทั้งโครงการใต้ `htdocs` แม้มี VirtualHost เพราะ default host อาจยังเปิด `.env`, SQL หรือสลิปผ่าน URL อื่นได้
2. เปิด extensions ใน `php.ini`: `pdo_mysql`, `curl`, `mbstring`, `gd`, `fileinfo`, `openssl`
3. เปิด `mod_rewrite` และอนุญาต `AllowOverride FileInfo AuthConfig Options=Indexes,MultiViews` สำหรับโฟลเดอร์ `public` (`AuthConfig` จำเป็นต่อกฎ `Require all denied` ที่กันไฟล์ซ่อน และ Options allowlist อนุญาตเฉพาะการปิด directory listing/Multiviews)
4. restart Apache แล้วตรวจด้วย `php -m` และ `php -v` จาก PHP ตัวเดียวกับ Apache
5. ติดตั้งฐานข้อมูล:
   - **วิธีง่ายสำหรับฐานใหม่:** เปิด phpMyAdmin ระดับ server แล้ว import `database/install.sql` เพียงไฟล์เดียว ระบบจะสร้างและเลือกฐาน `dormitory` พร้อมตาราง/trigger/ค่าเริ่มต้นให้ครบ
   - **วิธีขั้นสูง:** หากไม่มีสิทธิ์ `CREATE DATABASE` หรือจะใช้ชื่อฐานอื่น ให้สร้างและเลือกฐานนั้นเอง แล้ว import `database/schema.sql` ก่อน ตามด้วย `database/defaults.sql`
   - `database/demo.sql` เป็นข้อมูลทดสอบแบบ optional สำหรับเครื่อง local ห้าม import ใน production
   - สร้าง runtime user แยกจาก `root` หลัง import (เมนู User accounts หรือ SQL ด้านล่าง) เพราะ MySQL 8 คำสั่ง `GRANT` จะไม่สร้าง user ให้เอง:

     ```sql
     CREATE USER 'dormitory_app'@'127.0.0.1' IDENTIFIED BY 'รหัสสุ่มที่ยาวและไม่ซ้ำ';
     GRANT SELECT, INSERT, UPDATE ON dormitory.* TO 'dormitory_app'@'127.0.0.1';
     ```

   - หากชื่อฐานข้อมูลไม่ใช่ `dormitory` ให้สร้าง/เลือกชื่อนั้นเองและตั้ง `DB_DATABASE` ให้ตรงกัน
6. คัดลอก `.env.example` เป็น `.env` สำหรับ local; ตั้ง `DB_HOST=127.0.0.1`, `DB_PORT=3306` และบัญชีฐานข้อมูลจริง โดยเว้นว่าง/ลบ `DB_ROOT_PASSWORD` เพราะ PHP runtime ไม่ต้องใช้
7. รัน `php scripts/check_requirements.php --db`

ตัวอย่าง VirtualHost (แก้ path และชื่อ host ให้ตรงเครื่อง):

```apache
<VirtualHost *:80>
    ServerName dormitory.test
    DocumentRoot "C:/path/to/php-mysql/public"
    DirectoryIndex index.php
    <Directory "C:/path/to/php-mysql">
        Require all denied
    </Directory>
    <Directory "C:/path/to/php-mysql/public">
        Options -Indexes +FollowSymLinks -MultiViews
        AllowOverride FileInfo AuthConfig Options=Indexes,MultiViews
        Require all granted
    </Directory>
</VirtualHost>
```

ตรวจว่า Apache include ไฟล์ VirtualHost นี้ และเพิ่ม `127.0.0.1 dormitory.test` ในไฟล์ hosts ของ Windows ด้วยสิทธิ์ Administrator จากนั้น restart Apache; หากใช้ชื่ออื่นให้แก้ `ServerName`, hosts และ `APP_URL` ให้ตรงกันทั้งหมด

อย่าตั้ง DocumentRoot ไปที่โฟลเดอร์รากของโครงการ เพราะ `.env`, source code และ `storage/private` ต้องเข้าถึงจากเว็บไม่ได้

## ติดตั้งบน Laragon

1. เลือก PHP 8.2+ และ MySQL 8 จากเมนู Laragon; หากมีเฉพาะ MariaDB ให้เพิ่ม MySQL 8 หรือใช้ Docker
2. วางโครงการนอกโฟลเดอร์ `www` ที่ default host เปิดอ่านได้ แล้วสร้าง VirtualHost โดยให้ document root ลงท้ายด้วย `php-mysql/public`
3. เปิด extensions และ `mod_rewrite` ตามรายการเดียวกับ XAMPP แล้ว restart All
4. ฐานใหม่ชื่อ `dormitory` ให้ import `database/install.sql` ไฟล์เดียว; หากใช้ชื่ออื่นหรือไม่มีสิทธิ์สร้างฐาน ให้สร้าง/เลือกฐานเองแล้ว import `schema.sql` ตามด้วย `defaults.sql`; `demo.sql` ใช้ได้เฉพาะฐาน local ที่แยกจากข้อมูลจริง
5. สร้าง `.env`, ตั้ง `APP_URL` ให้ตรง virtual host, เว้นว่าง/ลบ `DB_ROOT_PASSWORD` และรันตัวตรวจ:

```powershell
php scripts/check_requirements.php --db
```

สำหรับการทดสอบแบบไม่ตั้ง Apache สามารถใช้ front controller ของ PHP ได้:

```powershell
php -S 127.0.0.1:8080 -t public router.php
```

คำสั่งนี้ใช้เพื่อพัฒนาเท่านั้น ไม่ใช่ production server

## สร้างผู้ดูแลคนแรก

ระบบไม่มีบัญชีหรือรหัสผ่านตั้งต้น แนะนำให้ส่งรหัสผ่านผ่าน standard input เพื่อไม่ให้ปรากฏใน command history หรือ process list รหัสผ่านต้องยาว 12–200 ตัวอักษร ไม่ใช้ชื่อผู้ใช้/คำยอดนิยม และต้องผสมชนิดอักขระให้คาดเดายาก ตัวอย่าง PowerShell 7:

```powershell
$ownerPassword = Read-Host 'รหัสผ่าน Owner' -MaskInput
$ownerPassword | php scripts/create_admin.php --username=owner --role=owner --password-stdin
Remove-Variable ownerPassword
```

ระบบ automation ใช้ secret store แล้ว pipe ค่าเข้า `--password-stdin` ได้เช่นกัน หลีกเลี่ยง `--password=...` เว้นแต่ runner ปกป้องทั้ง command history และ process list แล้ว:

```powershell
$env:OWNER_PASSWORD | php scripts/create_admin.php --username=owner --role=owner --password-stdin
Remove-Item Env:OWNER_PASSWORD
```

บน Docker ให้ส่งค่าเข้า standard input ของ one-shot container และใช้ `-T` เพื่ออ่าน pipe (ไม่ใส่รหัสผ่านต่อท้าย `-e` และไม่ส่งให้ long-running app/worker):

```powershell
$ownerPassword = Read-Host 'รหัสผ่าน Owner' -MaskInput
$ownerPassword | docker compose run --rm -T -e ADMIN_USERNAME=owner -e ADMIN_ROLE=owner app php scripts/create_admin.php --password-stdin
Remove-Variable ownerPassword
```

เมื่อสร้างสำเร็จ ให้ลบตัวแปร/secret ชั่วคราวจาก shell ทันที Compose ส่งเฉพาะ runtime allowlist ให้ `app`/`worker` จึงไม่มี `DB_ROOT_PASSWORD` หรือรหัสผ่าน bootstrap อยู่ใน process ระยะยาว

บัญชี `owner` เท่านั้นที่เพิ่ม/แก้ไข/ปิดบัญชีผู้ดูแลรายอื่นได้ ระบบป้องกันการลบ owner คนสุดท้าย แต่ควรมีขั้นตอนกู้คืนที่ควบคุมโดยผู้ดูแลฐานข้อมูลด้วย

## ฐานข้อมูล

ต้องใช้ Oracle MySQL 8.0.16 ขึ้นไปจริง ตรวจด้วย `SELECT VERSION();` หากผลมีคำว่า `MariaDB` ให้เปลี่ยนไปใช้ MySQL 8 หรือ Docker ก่อน เพราะ constraint/generated column ของ schema นี้ตั้งใจให้ fail-closed และไม่ลดระดับความปลอดภัยเพื่อรองรับ MariaDB

การติดตั้งใหม่แบบง่ายผ่าน phpMyAdmin ให้ import `database/install.sql` **ไฟล์เดียว** จากหน้าระดับ server ไฟล์นี้สร้างฐาน `dormitory`, ตาราง, constraints, triggers และค่าเริ่มต้นให้ครบ โดยไม่มีบัญชี Owner, credential หรือข้อมูลจำลอง และห้ามใช้ทับฐานเดิมที่มีข้อมูล

กรณีขั้นสูงที่ใช้ชื่อฐานอื่นหรือไม่มีสิทธิ์ `CREATE DATABASE` ให้สร้าง/เลือกฐานเอง แล้ว import `database/schema.sql` ตามด้วย `database/defaults.sql` ส่วน `database/00-create-database.sql` เป็นตัวช่วยสร้างฐานชื่อ `dormitory` และ `database/demo.sql` เป็นห้องทดสอบแบบ optional สำหรับ local เท่านั้น

phpMyAdmin เป็นเพียงหน้าจอสำหรับสร้าง/import/ตรวจและดูแล MySQL ไม่ใช่ที่เก็บค่าการเชื่อมต่อของแอป ตัวเว็บและ worker อ่าน `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` และ `DB_PASSWORD` จาก `.env` หรือ secret manager ทุกครั้งที่เริ่ม process

ตรวจว่าฐานข้อมูลถูกเลือกก่อน import ด้วย:

```sql
SELECT DATABASE(), VERSION();
```

คอลัมน์แรกต้องเป็นชื่อฐานข้อมูล ไม่ใช่ `NULL` และ version ต้องเป็น MySQL 8.0.16 ขึ้นไป

สำหรับ production ให้ DBA สร้าง user ของแอปหลัง import และแยกบัญชีสำหรับสร้าง schema/backup ตัวอย่างครั้งแรก (เปลี่ยน host/password ให้ตรงระบบจริง):

```sql
CREATE USER 'dormitory_app'@'app-host' IDENTIFIED BY 'รหัสสุ่มจาก secret manager';
GRANT SELECT, INSERT, UPDATE ON dormitory.* TO 'dormitory_app'@'app-host';
```

MySQL ตีความ `_` และ `%` ในขอบเขต database ของ `GRANT` เป็น wildcard แม้อยู่ใน backtick หากใช้ชื่อเช่น `my_dorm` ต้องเขียน `my\_dorm.*` เพื่อไม่ให้สิทธิ์ครอบคลุม schema ชื่อใกล้เคียง ตัวตรวจจะปฏิเสธ grant ที่ไม่ escape แบบ fail-closed

อย่าใช้ MySQL `root` เป็น `DB_USERNAME` ของเว็บ และอย่าเปิด port 3306 สู่ Internet ใน Docker ตัวอย่างผูก port ฐานข้อมูลไว้ที่ `127.0.0.1` เท่านั้น

บัญชี runtime ที่มีเฉพาะ `SELECT`/`INSERT`/`UPDATE` จะมองไม่เห็น `INFORMATION_SCHEMA.TRIGGERS` ตามกฎสิทธิ์ของ MySQL ตัวตรวจจึงแสดง `[SKIP]` สำหรับชื่อ trigger โดยไม่ถือว่าล้มเหลว ให้ DBA รัน `php scripts/check_requirements.php --schema-audit` ด้วยบัญชี schema owner หลัง import/restore เพื่อรับรองว่า integrity triggers ทั้ง 15 รายการและ event/timing/table ตรงทั้งหมด แล้วกลับมาใช้บัญชี runtime ตามเดิม บัญชีที่ import/สร้าง trigger จะเป็น `DEFINER`; ต้องเป็นบัญชี schema owner ที่ล็อกการใช้งานและคงอยู่ ห้ามลบบัญชีนั้นหลัง migration เว้นแต่ DBA จะ recreate trigger ทั้งหมดภายใต้ definer ที่คงอยู่

### อัปเกรดฐานข้อมูลที่ติดตั้งอยู่แล้ว

การติดตั้งใหม่ที่ import `database/install.sql` ไฟล์เดียว หรือใช้วิธีขั้นสูง `database/schema.sql` ตามด้วย `database/defaults.sql` มีโครงสร้างล่าสุดและแถวตั้งค่าพร้อมแล้ว ไม่ต้องรัน migration ใดซ้ำ ส่วน `database/demo.sql` เป็นข้อมูลทดสอบแบบ optional สำหรับ local เท่านั้น

ฐานข้อมูลเดิมต้องสำรองและทดสอบ restore ก่อน แล้วใช้บัญชี schema/migration ที่มีสิทธิ์ DDL (ไม่ใช่ `DB_USERNAME` ของแอป) รันตามลำดับ: `database/migrations/001_integration_settings.sql` เมื่อฐานยังไม่มี `integration_settings` (ไฟล์นี้ idempotent), รัน `database/migrations/002_operational_hardening.sql` **หนึ่งครั้งเท่านั้น**, แล้วรัน `database/migrations/003_append_only_guards.sql` เพื่อซ่อมฐานที่เคยใช้ `002` รุ่นต้นให้มีตัวกันแก้/ลบ bill items และ audit logs ครบ ไฟล์ `003` ปลอดภัยต่อการรันซ้ำ แต่ `002` ไม่ใช่ idempotent จึงต้องตรวจ staging และห้ามรัน `002` ซ้ำ

หลัง `001` (เมื่อจำเป็น) → `002` หนึ่งครั้ง → `003` ให้ login ด้วยบัญชี `owner` แล้วกรอกค่าที่ Admin → ตั้งค่า → “พร้อมเพย์, LINE Bot และตรวจสลิป” ระบบไม่ย้ายหรืออ่านค่าดำเนินงานเดิมจาก environment โดยอัตโนมัติ จึงต้องกรอกใหม่ในหน้าหลังบ้านก่อนเปิด LINE/ตรวจสลิป/PromptPay จากนั้นรัน `php scripts/check_requirements.php --db --strict` ด้วยบัญชี runtime และ `php scripts/check_requirements.php --schema-audit` ด้วยบัญชี migration ชั่วคราวอีกครั้ง

## ตั้งค่า PromptPay, LINE และตรวจสลิป

ค่าใช้งานทั้งหมดในหัวข้อนี้จัดการจาก Admin → ตั้งค่า โดยบัญชี `owner` เท่านั้นที่บันทึกหรือเปลี่ยนค่าได้ บัญชี admin ทั่วไปอ่านค่าที่ไม่ลับและสถานะความพร้อมได้ แต่ credential จะแสดงเพียง hint แบบปิดบัง ค่าถูกเก็บใน singleton `integration_settings` และ web/worker อ่านจากฐานข้อมูลเมื่อใช้งาน จึงมีผลกับ request/รอบ worker ถัดไปโดยไม่ต้องแก้ `.env`, rebuild image หรือ restart process

- PromptPay: กรอกเบอร์มือถือไทย 10 หลักหรือเลขผู้เสียภาษี 13 หลัก, ชื่อผู้รับ และเลขบัญชีปลายทาง/เลขท้าย 6–20 หลัก QR ใช้ยอดจาก bill snapshot ฝั่ง server เท่านั้น ไม่รับยอดจาก browser และจะแสดงเมื่อทั้ง PromptPay กับผู้ให้บริการตรวจสลิปพร้อม โดยไม่มีรายการชำระที่กำลังดำเนินการ เพื่อไม่ให้ผู้พักโอนเข้ากระบวนการที่ยังตรวจยืนยันไม่ได้
- LINE: กรอก Channel access token, จำนวน retry 1–20 ครั้ง และ batch size 1–100 งาน พร้อมผูก `line_user_id` ที่ถูกต้องให้ผู้เช่าในขั้นตอนย้ายเข้า ระบบส่งเฉพาะ `https://api.line.me/v2/bot/message/push`; retry ใช้ `X-Line-Retry-Key` เดิมและ HTTP 409 หมายถึง request เดิมได้รับแล้ว
- SlipOK: เลือก provider เป็น SlipOK แล้วกรอก API key, Branch ID และบัญชีปลายทาง
- EasySlip: เลือก provider เป็น EasySlip แล้วกรอก API key และบัญชีปลายทาง
- การอัปโหลดสลิปตั้งขนาดได้ 1,024–4,194,304 bytes และช่วงผ่อนผันเวลา 0–3,600 วินาที (ค่าเริ่มต้น 300) เวลา provider ที่หาย/parse ไม่ได้ หรือยังยืนยันบัญชีผู้รับกับค่าที่ตั้งไว้ไม่ได้จะคง `pending` เพื่อไม่ fail-open
- Admin เปิดดูหลักฐานที่เก็บแบบ private ผ่าน endpoint ที่ตรวจสิทธิ์และ audit ได้ ระบบจะตรวจ path, MIME, ขนาด/มิติรูป และ HMAC ซ้ำก่อนส่งไฟล์; รายการ `pending` ที่พ้น verification lease สามารถตรวจซ้ำหลัง Owner แก้ค่า provider/บัญชีผู้รับ หรือปิดรายการพร้อมเหตุผลเพื่อให้ผู้พักส่งสลิปใหม่ได้ แต่ไม่มีปุ่มบังคับให้เป็น paid

LINE token, SlipOK API key และ EasySlip API key ถูกเข้ารหัสแบบ AES-256-GCM โดย derive key จาก `APP_KEY` และผูก AAD แยกตามชื่อ field ฐานข้อมูลจึงไม่เก็บ plaintext และ API หลังบ้านไม่คืนทั้ง plaintext หรือ ciphertext แต่คืนเฉพาะ `configured` กับ hint แบบปิดบัง ช่องค่าลับที่เว้นว่าง/ส่ง `null` จะเก็บค่าเดิมไว้ การลบต้องเลือก “ล้างค่า” (`*_clear=true`) อย่างชัดเจน และห้ามส่งค่าลับใหม่พร้อมคำสั่งล้างใน request เดียวกัน

Docker Compose รัน service `worker` ให้อัตโนมัติผ่าน `sh scripts/start-worker.sh` ซึ่งมี bounded exponential backoff เมื่อ process ล้มชั่วคราว ตรวจด้วย `docker compose ps worker` และ `docker compose logs worker` หากไม่ใช้ Docker ให้ supervisor บน Linux รัน wrapper นี้เป็น process เบื้องหลัง; สำหรับ Windows Task Scheduler ให้รัน `php scripts/process_notifications.php` แบบ one-shot อย่างน้อยทุกนาที มิฉะนั้นรายการจะค้างที่ “รอส่ง”

endpoint ผู้ให้บริการเป็น HTTPS allowlist แบบคงที่: SlipOK `https://api.slipok.com/api/line/apikey/{branch-id}` และ EasySlip `https://api.easyslip.com/v2/verify/bank`; ระบบปิด redirect ก่อนเปลี่ยน provider ให้ทดสอบด้วยบิลจำนวนน้อย ตรวจรูปแบบ receiver reference ของบัญชีจริง และเก็บหลักฐาน reconcile ระบบจะรับเป็น “ชำระแล้ว” ก็ต่อเมื่อยอดตรงถึง 1 สตางค์ บัญชีปลายทางตรง และ transaction reference ไม่เคยใช้มาก่อน

## ตรวจระบบก่อนเปิดใช้งาน

```powershell
php scripts/check_requirements.php
php scripts/check_requirements.php --db
php scripts/check_requirements.php --db --strict
php scripts/check_requirements.php --production
php scripts/check_requirements.php --schema-audit
```

`--production` บังคับ `APP_ENV=production`, เปิดการตรวจฐานข้อมูล และถือ warning เป็น failure ในคำสั่งเดียว เหมาะเป็น deployment gate ของ production ส่วน `--schema-audit` ใช้ชั่วคราวกับบัญชี DBA/schema owner เพื่อตรวจ trigger ทั้ง 15 รายการ ไม่ใช่คำสั่งสำหรับบัญชี runtime ประจำของแอป

บน Docker ให้ตรวจทั้งสองบทบาท เพราะ web และ worker ใช้ runtime role คนละค่า แม้อ่าน operational settings ชุดเดียวกันจาก MySQL:

```powershell
docker compose exec app php scripts/check_requirements.php --db --strict
docker compose exec worker php scripts/check_requirements.php --db --strict
```

`--db` ตรวจ MySQL version, schema guards ที่บัญชี runtime มองเห็น และ allowlist สิทธิ์ global `USAGE` กับ `SELECT`/`INSERT`/`UPDATE` เฉพาะฐานระบบ โดยถือว่า `DELETE` หรือสิทธิ์ DDL เป็นสิทธิ์เกินจำเป็น ส่วน `--strict` ให้คำเตือนทำให้ exit code เป็น failure เหมาะกับ deployment gate ตัวตรวจจะรายงานเฉพาะชื่อ config ที่ผิดและไม่พิมพ์ secret ค่า `RUNTIME_ROLE=web|worker` ถูกกำหนดให้แต่ละ service โดย Compose; การติดตั้งแบบรวม process ใช้ค่าเริ่มต้น `all`

ทดสอบ workflow อย่างน้อยหนึ่งรอบบน staging: จองห้อง → ยืนยัน → ย้ายเข้า → จดมิเตอร์สองประเภท → preview/bulk bill → เปิด QR → ส่ง LINE → อัปโหลดสลิป → ตรวจสถานะ paid และ audit log

## แนวทาง production

- ใช้ HTTPS/TLS ที่ reverse proxy, เปิด HSTS ที่ชั้น TLS และส่ง `X-Forwarded-Proto` เฉพาะจาก proxy ที่อยู่ใน `TRUSTED_PROXIES`
- คง `APP_BIND=127.0.0.1` สำหรับพอร์ต plain HTTP ของ Compose แล้วให้ reverse proxy เป็นจุดรับ traffic ภายนอก ห้าม bind เป็น `0.0.0.0` เว้นแต่มี firewall/network policy และเหตุผลที่ทบทวนแล้ว
- ปิด debug/display errors; ส่ง PHP/Apache logs ไปพื้นที่ที่ผู้ใช้เว็บอ่านไม่ได้ และตั้ง alert สำหรับ login fail, rate limit, slip verify fail และ LINE retry fail
- ให้ web user เขียนได้เฉพาะ `storage/`; source, `.env` และ SQL ต้องอ่านได้เฉพาะบัญชี deploy ที่จำเป็น
- สำรอง MySQL และ `storage/private/slips` แบบเข้ารหัส แยกตำแหน่ง และทดสอบ restore เป็นรอบ การมี backup ที่ไม่เคย restore ถือว่ายังไม่ผ่าน
- sync เวลาด้วย NTP เพราะ session, due date, provider timestamp และ audit log พึ่งพาเวลาที่ถูกต้อง
- หมุน `DB_PASSWORD` ผ่าน secret manager และหมุน LINE/slip credential ผ่านหน้า Settings เป็นระยะ ห้ามบันทึก secret ลง log/audit payload; การหมุน `APP_KEY` ต้องเป็น migration ที่ล้าง/re-encrypt integration secrets อย่างควบคุม
- อัปเดต PHP/MySQL/base image และตรวจ advisory เป็นรอบ โดยทดสอบ staging ก่อน production
- จำกัด retention ของ slip/provider payload ตามนโยบายข้อมูลส่วนบุคคลและสัญญาผู้ให้บริการ
- ให้บัญชี DBA/maintenance (ไม่ใช่ `DB_USERNAME` ของเว็บ) ล้าง rate-limit bucket เก่าตามรอบ เช่น `DELETE FROM rate_limits WHERE updated_at < UTC_TIMESTAMP() - INTERVAL 30 DAY;` และตั้ง retention/capacity ของ audit log; การ archive/ลบ audit ต้องเป็น migration ที่ควบคุมและสร้าง append-only trigger กลับครบ ห้ามให้สิทธิ์ `DELETE` แก่ runtime เพื่อความสะดวก

## ปัญหาที่พบบ่อย

- ได้ 404 ทุก route: ตรวจ `mod_rewrite`, `AllowOverride` และ DocumentRoot ต้องเป็น `public/`
- ได้ 500 พร้อมข้อความว่า `Require not allowed here` หรือ `Option MultiViews not allowed here`: ใช้ `AllowOverride FileInfo AuthConfig Options=Indexes,MultiViews` กับโฟลเดอร์ `public/` แล้ว reload Apache
- login แล้วเด้งกลับเมื่อใช้ HTTP local: ตั้ง `APP_ENV=development`, `FORCE_HTTPS=false`, `APP_URL` ให้ตรง origin แล้วล้าง cookie เดิม
- `could not find driver`: เปิด `pdo_mysql` ใน `php.ini` ของ PHP/Apache ตัวที่กำลังรันจริง
- import SQL ไม่ผ่าน: ตรวจว่าเป็น MySQL 8.0.16+ ไม่ใช่ MariaDB; ฐานใหม่ชื่อ `dormitory` ให้ใช้ `install.sql` ไฟล์เดียว หรือวิธีขั้นสูงต้องเลือกฐานก่อนแล้ว import `schema.sql` ก่อน `defaults.sql`; `demo.sql` ไม่จำเป็นต่อการทำงาน
- อัปโหลดสลิปไม่ได้: ตรวจ `file_uploads`, `upload_max_filesize >= 4M`, `post_max_size >= 5M` (เผื่อ multipart overhead) และสิทธิ์เขียน `storage/private/slips`
- ส่ง LINE ไม่ได้: ตรวจ token, `line_user_id`, quota และรายการ retry โดยไม่พิมพ์ token ลง log
- สลิปถูกปฏิเสธ: ตรวจยอด 2 ตำแหน่งทศนิยม, เลขท้ายบัญชี, provider config และ transaction reference ซ้ำ
- หน้า Settings แจ้งว่า integration ยังไม่พร้อม: ฐานใหม่ให้ import `install.sql` (หรือ `schema.sql` + `defaults.sql` แบบขั้นสูง); ฐานเดิมต้องรัน `001_integration_settings.sql` เมื่อจำเป็น, `002_operational_hardening.sql` หนึ่งครั้ง และ `003_append_only_guards.sql` (รันซ้ำได้) จากนั้น login ด้วย role `owner`; ช่อง secret ว่างหมายถึงเก็บค่าเดิม ไม่ได้ล้างค่า

## โครงสร้างหลัก

```text
START_HERE.md            ขั้นตอนเริ่มต้นฉบับสั้น
public/                 web root และ front controller
src/                    PHP application/domain/security/integration
templates/              หน้า Guest, Resident, Admin
database/install.sql    ตัวติดตั้งฐานใหม่ชื่อ dormitory แบบไฟล์เดียว
database/schema.sql     schema MySQL 8
database/defaults.sql   ค่าเริ่มต้นปลอดภัยที่ไม่มี credential/ข้อมูลจำลอง
database/demo.sql       ห้องตัวอย่าง optional สำหรับ local เท่านั้น
database/migrations/    SQL สำหรับอัปเกรดฐานข้อมูลที่ติดตั้งแล้ว
storage/private/slips/  ไฟล์สลิปที่ห้ามเสิร์ฟตรง
scripts/                setup, สร้าง install.sql และ requirement checks
docs/                   feature/security documentation
```
