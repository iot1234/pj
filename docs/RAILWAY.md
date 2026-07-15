# Deploy บน Railway

Railway ต้องรับ configuration และ secret ผ่านหน้า **Variables** ของ service
หรือ secret manager เท่านั้น ห้าม commit `.env`, `APP_KEY` หรือรหัสฐานข้อมูลลง
Git repository

## Web service

เชื่อม GitHub repository นี้กับ Railway โดยใช้ `Dockerfile` ที่รากโครงการ แล้ว
กำหนด Variables ต่อไปนี้:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://${{ RAILWAY_PUBLIC_DOMAIN }}
APP_TIMEZONE=Asia/Bangkok
APP_KEY=<random-64-hex-secret>
FORCE_HTTPS=true
TRUSTED_PROXIES=
SESSION_NAME=dormitory_session
SESSION_LIFETIME_SECONDS=43200

DB_HOST=${{ MySQL.MYSQLHOST }}
DB_PORT=${{ MySQL.MYSQLPORT }}
DB_DATABASE=${{ MySQL.MYSQLDATABASE }}
DB_USERNAME=<least-privilege-runtime-user>
DB_PASSWORD=<runtime-user-secret>
DB_SSL=false
DB_SSL_CA=
```

เปลี่ยน namespace `MySQL` ให้ตรงกับชื่อ database service จริงใน Railway
โปรเจกต์ต้องอยู่ environment เดียวกันจึงจะใช้ private hostname
`*.railway.internal` ได้ ค่า `APP_KEY` ต้องคงเดิมตลอดอายุ deployment เพราะใช้
เข้ารหัส integration secrets และต้องตั้งเป็น secret/sealed variable

Docker image จะปรับ Apache ให้ฟังพอร์ตจาก `PORT` ของ Railway อัตโนมัติ และ
รองรับ `X-Forwarded-Proto`, `X-Real-IP` จาก Railway edge โดยตรวจ runtime identity
กับ request ID ก่อนเชื่อ header

## MySQL ครั้งแรก

ตรวจ `MYSQLDATABASE` ของ database service ก่อนเสมอ Railway มักใช้ฐานชื่อ
`railway` จึงห้ามใช้ `database/install.sql` ซึ่งสร้างฐานชื่อ `dormitory` โดยไม่
ตรวจให้ตรงก่อน สำหรับฐานใหม่ที่เลือกไว้แล้ว ให้ import ตามลำดับ:

1. `database/schema.sql`
2. `database/defaults.sql`

ใช้บัญชี `root` เฉพาะ bootstrap/migration จากนั้นสร้าง runtime user แยกและให้
เพียง `SELECT`, `INSERT`, `UPDATE` บนฐานของแอป ห้ามใช้ root เป็นบัญชีประจำของ
web/worker และห้าม import ทับฐานที่มีข้อมูลอยู่

## Worker และ storage

สร้าง Railway service แยกจาก repository เดียวกันสำหรับ worker และ override
Start Command เป็น:

```text
php scripts/process_notifications.php --loop --sleep=15
```

ตั้ง Variables ชุดเดียวกับ web service โดยเฉพาะ `APP_KEY` และค่าฐานข้อมูล
หากต้องเก็บ session/สลิปข้าม deployment ให้ mount Railway Volume ที่
`/var/www/html/storage` และวางแผน backup ของทั้ง MySQL กับไฟล์สลิป

หลังฐานพร้อม ให้สร้าง Owner ผ่าน shell ของ web service แล้วรัน:

```text
php scripts/check_requirements.php --db --strict --production
```
