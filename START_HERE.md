# เริ่มใช้งานแบบง่าย

เอกสารนี้เป็นทางลัดสำหรับติดตั้งใหม่ หากระบบมีฐานข้อมูลและข้อมูลใช้งานอยู่แล้ว **ห้าม import `database/install.sql` ทับ** ให้ใช้คู่มืออัปเกรดใน `docs/SQL_SETUP.md` ตัวติดตั้งจะปฏิเสธฐานที่ไม่ว่าง และห้ามเลือกตัวเลือก force/continue เมื่อ import

## ทางเลือก A: Docker Desktop (แนะนำ)

1. คัดลอก `.env.production.example` เป็น `.env` สำหรับ production หรือ `.env.example` สำหรับเครื่องทดสอบ
2. สร้าง `APP_KEY` ด้วย `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
3. กำหนด `APP_URL`, `APP_KEY`, `DB_PASSWORD` และ `DB_ROOT_PASSWORD` โดยรหัสฐานข้อมูลสองตัวต้องไม่ซ้ำกัน
4. เปิดระบบ:

   ```powershell
   docker compose up --build -d
   docker compose exec app php scripts/check_requirements.php --db
   ```

5. สร้าง Owner คนแรก:

   ```powershell
   $password = Read-Host 'รหัสผ่าน Owner' -AsSecureString
   $plain = [System.Net.NetworkCredential]::new('', $password).Password
   $plain | docker compose exec -T app php scripts/create_admin.php --username=owner --role=owner --password-stdin
   Remove-Variable plain,password
   ```

6. เปิด `/admin` → **ตั้งค่า** เพื่อบันทึกค่าน้ำ ค่าไฟ วันครบกำหนด PromptPay และระบบตรวจสลิป สำหรับ LINE ให้เปิด **บัญชี LINE OA** → **เชื่อมต่อ LINE OA** แล้วกรอกเพียง Channel access token และ Channel secret ระบบดึงข้อมูลบัญชีให้เอง จากนั้นคัดลอก Webhook URL ที่แสดงไปใส่ใน LINE Developers Console เปิด **Use webhook**, **Webhook redelivery** และกด **Verify**

Docker จะติดตั้งฐานข้อมูลและเปิด LINE worker ให้อัตโนมัติ ไม่ต้อง import SQL ด้วยตนเอง

## ทางเลือก B: XAMPP/Laragon + phpMyAdmin

ข้อกำหนดคือ PHP 8.2+ และ Oracle MySQL 8.0.16+ ไม่ใช่ MariaDB

1. วางโครงการไว้นอก `htdocs`/`www` และตั้ง VirtualHost ให้ DocumentRoot ชี้เฉพาะ `php-mysql/public`
2. เปิด phpMyAdmin ระดับ server เลือก **Import** แล้วนำเข้าไฟล์เดียว:

   ```text
   database/install.sql
   ```

3. เปิดแท็บ SQL แล้วสร้าง MySQL runtime user ที่ไม่ใช่ `root` (เปลี่ยนรหัสตัวอย่างก่อนรัน):

   ```sql
   CREATE USER 'dormitory_app'@'127.0.0.1'
   IDENTIFIED BY 'เปลี่ยนเป็นรหัสสุ่มที่ยาวและไม่ซ้ำ';
   GRANT SELECT, INSERT, UPDATE
   ON dormitory.* TO 'dormitory_app'@'127.0.0.1';
   ```

4. คัดลอก `.env.example` เป็น `.env`, สร้าง `APP_KEY` ด้วย `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` แล้วตั้ง `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` ให้ตรงกับข้อ 3
5. รัน:

   ```powershell
   php scripts/check_requirements.php --db
   $password = Read-Host 'รหัสผ่าน Owner' -AsSecureString
   $plain = [System.Net.NetworkCredential]::new('', $password).Password
   $plain | php scripts/create_admin.php --username=owner --role=owner --password-stdin
   Remove-Variable plain,password
   ```

6. เปิด `/admin` → **ตั้งค่า** แล้วกรอกค่าดำเนินงานจากหน้าเว็บ สำหรับ LINE ไปที่ **บัญชี LINE OA** → **เชื่อมต่อ LINE OA** กรอก Channel access token และ Channel secret ระบบดึงชื่อ/Basic ID ให้เอง ใช้ Webhook URL ที่ระบบแสดงสำหรับบัญชีนั้นใน LINE Developers Console เปิด **Use webhook**, **Webhook redelivery** และกด **Verify**
7. ตั้ง worker ส่ง LINE: บน Linux ให้ supervisor รัน `sh scripts/start-worker.sh` เป็น process เบื้องหลัง; บน Windows ให้ Task Scheduler รัน `php scripts/process_notifications.php` แบบ one-shot อย่างน้อยทุกนาที (ไม่ใช้ `--loop` ใน scheduled task)

## ตรวจว่าพร้อมใช้

- หน้า Guest เห็นเฉพาะห้องว่างและส่งจองได้
- Owner login ได้ และหน้าแรกหลังเข้าสู่ระบบคือ **ภาพรวม** ซึ่งบอกจำนวนการจองรอยืนยัน สลิปรอตรวจ ความคืบหน้าการจดมิเตอร์/ออกบิลของเดือนนี้ และสถานะตัวส่งบิลผ่าน LINE
- Owner login ได้และหน้า **ตั้งค่า** แสดงสถานะ PromptPay/ตรวจสลิป ส่วนสถานะ LINE อยู่หน้า **บัญชี LINE OA**
- LINE Developers Verify webhook ผ่าน, request ลายเซ็นผิดถูกปฏิเสธ และส่งคำว่า `เมนู` หา Bot แล้วได้รับวิธีผูกบัญชี
- เพิ่มห้อง → ยืนยันจอง → รับเข้าพัก → จดมิเตอร์ → ตรวจยอด → ออกบิลได้
- `php scripts/check_requirements.php --db --strict --production` ผ่านก่อนเปิด production โดยใช้ environment สำหรับ production จริง

ฐานเดิมต้องอัปเกรดตามลำดับจนถึง `015_pending_occupancy_opening_readings.sql` ก่อนใช้ source ปัจจุบัน ฐานใหม่ที่ import `install.sql` มีโครงสร้างนี้แล้ว รายละเอียด SQL, ฐานชื่ออื่น และการอัปเกรดฐานเดิมอยู่ใน `docs/SQL_SETUP.md`
