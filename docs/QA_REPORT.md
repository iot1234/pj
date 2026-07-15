# รายงานตรวจรับระบบ

วันที่ตรวจล่าสุด: 2026-07-15

ขอบเขตคือโปรเจกต์ PHP/MySQL ใน `php-mysql` ตาม FR-01–FR-16 โดยใช้ `newap` เป็นข้อมูลอ้างอิงแบบอ่านอย่างเดียวและไม่แก้ไข `ap` ผลที่ต้องใช้ credential หรือระบบ production จริงถูกแยกไว้ชัดเจนเพื่อไม่อ้างผลเกินหลักฐาน

## ผลตรวจอัตโนมัติ

- PHP 8.3.29 lint ผ่าน 43/43 ไฟล์ ไม่มี syntax error
- `php tests/run.php` ผ่าน 44/44 tests, 0 failures รวม regression ของ signed bill preview, guest CSRF/lazy session, ตัวกัน QR เมื่อเส้นทางชำระเงินยังไม่พร้อม และ UX guard สำคัญ
- `node --check public/assets/js/app.js` ผ่าน
- ไม่พบ PHP warning, fatal error หรือ deprecation ใน log ของ HTTP/browser E2E
- ตรวจหน้า Admin ทั้ง desktop และ mobile แล้ว เมนู/กล่องยืนยันทำงานได้ คอลัมน์ปุ่มสำคัญยังมองเห็นเมื่อเลื่อนตาราง และ browser console ไม่มี warning/error

## ผลตรวจ Oracle MySQL 8.4.10

- Fresh import `database/install.sql` ไฟล์เดียวผ่าน: 14 ตาราง, integrity triggers 15 รายการ, `billing_settings`/`integration_settings` อย่างละ 1 แถว และไม่มี Owner/ข้อมูลตัวอย่าง; import ซ้ำถูกตัวกันฐานไม่ว่างปฏิเสธก่อนแตะ schema โดยจำนวนตาราง/trigger/settings ไม่เปลี่ยน
- Fresh import `database/schema.sql` + `database/defaults.sql` ผ่าน: 14 ตาราง, 70 CHECK constraints และ integrity triggers 15 รายการ
- รัน `003_append_only_guards.sql` ซ้ำ 2 รอบบนฐานใหม่ได้โดยไม่เกิดข้อผิดพลาด และจำนวน trigger ยังคงเป็น 15
- จำลองฐานรุ่นก่อนแล้วรัน `002_operational_hardening.sql` สำเร็จ: เพิ่มคอลัมน์/ดัชนีที่ต้องใช้และสร้าง trigger ครบ 15 รายการ
- ทดสอบ `003_append_only_guards.sql` ในฐานที่อาจเคยใช้ migration 002 รุ่นเก่าแล้ว: สร้างตัวป้องกัน `bill_items` และ `audit_logs` ครบ 4 รายการแบบรันซ้ำได้
- Final release database ใช้ runtime user ที่มีเฉพาะ `SELECT`/`INSERT`/`UPDATE` บน schema และผ่าน `check_requirements.php --db`: 33 ผ่าน, ข้าม trigger metadata 1 รายการตามข้อจำกัดสิทธิ์, เตือน 1 รายการเพราะไม่ได้ใส่ credential ตรวจสลิปจริง และ 0 error
- Schema audit ด้วยบัญชี schema owner ผ่าน 34 รายการ ตรวจ trigger ครบ 15 รายการ เตือน 2 รายการตามที่คาดไว้จาก root local ที่ไม่มีรหัสผ่านและ credential ตรวจสลิปจริงที่ไม่ได้ใส่ใน QA และ 0 error
- ทดสอบตรงว่า `audit_logs` แก้ไขและลบไม่ได้ทั้งคู่

Negative/positive invariants ที่ยิงตรงผ่าน MySQL ผ่านทั้งหมด:

- แก้ snapshot ค่าเช่าของ booking/occupancy ไม่ได้
- แก้หลักฐาน confirm/cancel/move-in หลังบันทึกไม่ได้
- เปลี่ยน bill เป็น paid โดยไม่มี verified payment ที่ตรงกันไม่ได้
- payment ต้องเริ่ม pending, lease ต้องมี token และ payment ที่สรุปแล้วแก้/ลบไม่ได้
- bill item ต้องตรง snapshot ของ bill, item type ซ้ำไม่ได้ และแก้/ลบภายหลังไม่ได้
- notification outbox ผูก bill ข้าม resident ไม่ได้ทั้ง insert/update
- bill และ audit log ลบไม่ได้; audit log แก้ไม่ได้
- เส้นทาง verified payment + bill paid ที่มีหลักฐานครบทำงานสำเร็จ

## ผล Live HTTP/MySQL E2E

ทดสอบผ่านบนฐาน QA แยกตั้งแต่ต้นจนจบ:

1. Owner login และตั้งค่า billing/PromptPay จากหลังบ้าน
2. สร้างห้อง, จองแบบ idempotent, ยืนยัน และย้ายเข้า
3. Resident login และแก้ข้อมูลติดต่อรวม LINE User ID
4. Owner reset PIN แล้ว session เดิมถูกเพิกถอน; PIN ใหม่ login ได้
5. ระบบไม่ยอมย้ายออกถ้ายังไม่มีบิลปิดรอบหรือมีบิลค้าง
6. บันทึกมิเตอร์และออกบิลโดยป้องกันบิลซ้ำ
7. เปิดหลักฐานสลิปส่วนตัวผ่าน endpoint ที่ตรวจสิทธิ์และมี audit
8. Retry การตรวจสลิปเมื่อยังไม่ตั้ง provider ตอบ 503 แบบ fail-closed และไม่เปลี่ยนบิลเป็น paid
9. ปิดรายการ pending ไม่ได้ขณะ verification lease ยังทำงาน และปิดได้หลัง lease หมดโดยต้องใส่เหตุผล
10. หลังมี verified payment ที่ตรงตามข้อบังคับจึงย้ายออกสำเร็จ, session Resident ถูกเพิกถอน และห้องกลับเป็นว่าง

ผลสุดท้ายที่ตรวจ: `room=1, booking=1, resident=1, bill=1, payment=1` และสถานะ `occupancy=ended`, `resident=inactive`, `bill=paid` พร้อม audit ของงานสำคัญ

รอบ final regression บนฐานใหม่อีกชุดยืนยันเพิ่มเติมว่า:

- Anonymous GET หลายครั้งและหน้า Guest ไม่สร้าง cookie/ไฟล์ session (`17 → 17` ไฟล์เดิม) แต่ login ที่สำเร็จจึงเริ่ม session
- booking ที่ room ID ไม่ถูกต้อง 2 ครั้งไม่กิน phone quota และ booking ห้องจริงครั้งถัดไปยังสำเร็จ
- account limiter ตอบ generic 401 เหมือนกัน และรหัสผ่านที่ถูกต้องไม่ข้าม account bucket ที่ถูก block; username ที่ไม่มีจริงหลายค่าใช้ unknown bucket ต่อ IP ร่วมกัน โดยมี rate-limit rows รวมเพียง 8 แถวใน scenario ทั้งหมด
- Admin เปลี่ยนข้อมูล/เบอร์ผู้พักได้จากหลังบ้าน การเปลี่ยนเบอร์เพิ่ม `auth_version`, session เดิมใช้ต่อไม่ได้ และเบอร์ใหม่ login ด้วย PIN เดิมได้
- ค่ามิเตอร์น้ำและไฟที่สูงผิดปกติถูกรวบรวมเตือนพร้อมกัน 2 ประเภท ต้องยืนยันทั้งชุด และแก้ค่าหลังออกบิลไม่ได้
- bill preview ผูก HMAC กับข้อมูลจริงและหมดอายุ; เปลี่ยนมิเตอร์หลัง preview แล้วใช้ token เดิมถูกปฏิเสธด้วย `BILL_PREVIEW_CHANGED` ส่วน preview ใหม่ออกบิลสำเร็จ
- PromptPay QR, LINE outbox, booking/payment pagination และการเก็บ LINE token แบบ ciphertext โดยไม่คืน secret ผ่าน API ทำงานตาม contract

## สิ่งที่ต้องตรวจด้วย credential/ระบบ deploy จริง

- LINE push จริง รวม quota/permission, retry key เดิม และ HTTP 409 replay
- SlipOK/EasySlip ด้วยสลิปจริง: สำเร็จ, ยอดผิด, ผู้รับผิด, transaction ซ้ำ, cached duplicate, 429 และ timeout
- HTTPS/HSTS/reverse proxy, firewall/network policy และ trusted proxy ของโดเมนจริง
- Worker/cron หลาย process, health monitoring และ alert/log aggregation บนเครื่อง deploy
- Backup/restore drill ที่กู้ MySQL, private slips และ `APP_KEY` รุ่นเดียวกับ ciphertext ได้

ระบบทำงานแบบ fail-closed: provider ที่ยังไม่พร้อมจะไม่ทำให้ bill เป็น paid, ไม่มี endpoint ให้ Admin กดอนุมัติชำระเอง, รายการที่ผลยังไม่แน่นอนคงเป็น pending เพื่อ retry หรือปิดพร้อมเหตุผล และ credential ไม่ถูกคืนผ่าน API หรือบันทึกใน log/audit
