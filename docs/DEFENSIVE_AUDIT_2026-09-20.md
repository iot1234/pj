# ผลตรวจป้องกันข้อผิดพลาด — 20 กันยายน 2026

ตรวจ repository `php-mysql` จาก commit `855b07b` และแก้เฉพาะการส่งค่า environment, การตรวจ Compose ใน CI และคู่มือที่ไม่ตรงกับระบบปัจจุบัน ไม่พบข้อผิดพลาดเพิ่มเติมในเส้นทางธุรกิจที่ทดสอบผ่านด้านล่าง ผลนี้ไม่ใช่การรับรองว่าทุกกรณีของระบบปราศจากข้อผิดพลาด

## ปัญหาที่พบและแก้

### 1. อายุการจองและรหัสเปิดใช้งานถูกละเลยเมื่อใช้ Docker Compose (P2)

`.env.example` และ `.env.production.example` เปิดให้กำหนด `BOOKING_HOLD_SECONDS` กับ `RESIDENT_ACTIVATION_TTL_SECONDS` แต่ `docker-compose.yml` เดิมไม่ได้ส่งสองค่านี้เข้าเว็บหรือ worker และ `.dockerignore` ไม่บรรจุ `.env` ลง image ผลคือการจองยังใช้ 24 ชั่วโมงและรหัสเปิดใช้งานยังใช้ 7 วัน แม้ผู้ดูแลจะตั้งค่าอื่น นอกจากนี้ `WORKER_INSTANCE_ID` ที่กำหนดไว้ก็ไม่ได้ส่งเข้า worker

แก้ให้สองค่าอายุถูกส่งผ่าน environment ร่วมของเว็บและ worker พร้อมค่าเริ่มต้นเดิม และส่งชื่อ instance ให้ worker เพิ่มการตรวจ effective configuration ที่ Compose สร้างจริงใน `tests/compose_environment.php` และ CI โดยทดสอบทั้งค่าเริ่มต้นและค่ากำหนดเอง พร้อมตรวจว่ารหัสผ่าน root ไม่ถูกส่งให้เว็บหรือ worker

หลักฐาน: Docker Compose v5.5.1 สร้าง configuration ได้จริง การทดสอบไฟล์เดิมปฏิเสธด้วย `app did not receive the expected BOOKING_HOLD_SECONDS`; ไฟล์ที่แก้ผ่านทั้งโหมด default และ custom การอ่าน configuration นี้ไม่สร้าง container หรือเชื่อมต่อฐานจริง

### 2. คู่มืออัปเกรดหยุดก่อน migration ที่ runtime บังคับ (P2)

README/คู่มือ LINE ยังระบุว่าจบที่ `014`; ลำดับในคู่มือ SQL ยังข้าม `014` และ `015` ก่อน deploy และบางจุดยังอ้าง schema 16 ตาราง/19 triggers/86 CHECKs ขณะที่ source ปัจจุบันต้องมี 21 ตาราง/23 triggers/116 CHECKs และบังคับ `chk_occupancies_opening_readings_v2` จาก migration `015` การทำตามขั้นตอนเดิมอาจทำให้ readiness ตอบ HTTP 503 หลังอัปเกรด

ปรับ README, START_HERE, ARCHITECTURE และคู่มือ SQL/LINE ให้ครบถึง `015_pending_occupancy_opening_readings.sql` ระบุช่วงหยุด web/worker/cron และการตรวจ schema ให้ตรงกัน พร้อมแก้คำอธิบายค่าเปิดมิเตอร์ legacy ที่ยังไม่ทราบทั้งสองค่าแต่ไม่มีประวัติ ให้ใช้ขั้นตอนกรอกค่าจริงครั้งเดียวแทนการเดาค่า

แก้ข้อมูลเกี่ยวเนื่องที่อาจทำให้ตั้งค่าผิดด้วย: การตั้งคีย์และคัดลอก Webhook ต้องใช้หน้า บัญชี LINE OA; API login ผู้พักใช้ `credential` คู่กับเบอร์โทร และ `new_password` เมื่อเปิดใช้งานครั้งแรก; ขั้นตรวจ production ใช้ `--production` เพิ่มจาก `--strict`

หลักฐาน: `tests/healthz_mysql.php` ยืนยันว่าฐานก่อน `015`, CHECK ที่นิยามผิด หรือ CHECK ที่ไม่ได้บังคับใช้ ถูกปฏิเสธ และกลับมาตอบ HTTP 200 เมื่อคืน schema ที่ถูกต้อง

## ผลทดสอบ

| รายการ | ผล |
| --- | --- |
| PHP syntax | ผ่าน 77 ไฟล์: เดิม 76 และ regression test ใหม่ 1 |
| JavaScript syntax | ผ่าน `app.js` และ `admin-line-platform.js` |
| JavaScript interaction tests | 76 ผ่าน, 0 ล้มเหลว |
| PHP unit/contract tests | 110 ผ่าน, 0 ล้มเหลว |
| LINE bot offline tests | 7 ผ่าน |
| Monthly billing CLI tests | 7 ผ่าน |
| Notification worker claim/heartbeat contract | ผ่าน |
| Install SQL bundle consistency | ผ่าน; `install.sql` ตรงกับแหล่งสร้าง |
| MySQL integration | ผ่านทั้ง 10 ชุดที่ระบุด้านล่าง |
| Schema audit | 54 ผ่าน, 0 ล้มเหลว, 2 คำเตือนตาม fixture |
| Compose regression | ค่าเริ่มต้นผ่าน, ค่ากำหนดเองผ่าน, ปฏิเสธไฟล์เดิมได้ |
| GitHub workflow | actionlint v1.7.12 ผ่าน; ไม่ได้ตรวจ shellcheck/pyflakes |
| Diff whitespace | `git diff --check` ผ่าน |

MySQL 8.4.10 ใช้ data directory และ schema ใหม่ในเครื่อง ผูกเฉพาะ `127.0.0.1:33329` แอปทดสอบใช้สิทธิ์ SELECT/INSERT/UPDATE; การสร้าง fixture, ทดสอบ migration และ schema audit ใช้บัญชีเจ้าของฐานแยก ไม่อ่าน `.env` ของโครงการ และหยุด MySQL ตามปกติหลังทดสอบเสร็จ

ชุด integration ที่รัน:

- `pending_opening_migration_mysql.php`: อัปเกรด legacy จนถึง 015, รันซ้ำ, ค่า NULL ไม่ครบคู่, ประวัติมิเตอร์/บิล, trigger และ claim lease
- `pending_opening_mysql.php`: สิทธิ์ admin/CSRF, กรอกค่าเปิดมิเตอร์ครั้งเดียว, rollback เมื่อ audit ล้มเหลว, idempotency และออกบิลหลังกรอกครบ
- `line_binding_mysql.php`: ออกรหัส/แทนรหัส, replay, expiry, unlink และยกเลิกสิทธิ์จากการออก credential ใหม่
- `line_bot_mysql.php`: 18 กลุ่มทดสอบคำสั่ง/ข้อมูลผู้พัก โดยจำลองการตอบจาก provider
- `line_room_binding_mysql.php`: 20 กลุ่ม รวมหลายบัญชีต่อห้อง การยกเลิก การบล็อก และ rollback
- `line_oa_mysql.php`: 18 กลุ่ม รวมดึง metadata, pin ตัวตน OA, หมุน Token/Secret/route, ตรวจลายเซ็น และ audit ไม่เก็บค่าลับ
- `line_platform_mysql.php`: 12 กลุ่มทดสอบ OA/ผู้รับ/คิวและการส่งจำลอง
- `billing_line_delivery_mysql.php`: 7 กลุ่มตรวจผลส่งหลายบัญชีและการไม่นับยอดบิลซ้ำ
- `healthz_mysql.php`: 8 กลุ่ม ตรวจ schema ที่ขาด/ผิดและการกู้ readiness
- `lifecycle_mysql.php`: รับเข้าพัก → activation/password → มิเตอร์ → ออกบิล → payment → ย้ายออกและถอน session

CI ของ commit ตั้งต้นผ่านแล้ว: [Verify PHP application — 855b07b](https://github.com/iot1234/pj/actions/runs/35468851484) ส่วนขั้นตรวจ Compose ที่เพิ่มรอบนี้ตรวจในเครื่องแล้ว และจะรันบน GitHub เมื่อมีการ push

## หลักฐานและขอบเขตที่ยังไม่ได้ยืนยัน

Log ของฐานทดสอบและตัวรันทดสอบอยู่ใน `../tmp/defensive-audit-20260920-c88b6c07/` จากราก repository โดยแยกไฟล์ตามชื่อ suite ไม่มีการแก้ข้อมูล production, deploy, commit หรือ push ในรอบนี้

การทดสอบแอปในเครื่องใช้ PHP 8.5.8 พร้อมส่วนขยายที่จำเป็น ส่วน CI ของ commit ตั้งต้นทดสอบ Docker/PHP 8.3 สำเร็จแล้ว ไม่ได้สร้าง Docker image ใหม่ในเครื่องนี้

LINE และระบบตรวจสลิปใช้ fixture/transport จำลอง ไม่ได้ส่งข้อความจริงหรือใช้ credential ของผู้ให้บริการ การทดสอบ payment ยืนยันการควบคุมสถานะและหลักฐานจำลอง ไม่ใช่การยืนยันธุรกรรมธนาคารจริง คำเตือนสองรายการใน schema audit เกิดจาก fixture ไม่ตั้ง PromptPay และ provider ตรวจสลิป ไม่ใช่ผลตรวจ production

ยังไม่ได้ตรวจ production configuration/data, การกู้ backup ของระบบจริง, การใช้งาน LINE บนมือถือ หรือความสามารถรองรับโหลดจำนวนมาก
