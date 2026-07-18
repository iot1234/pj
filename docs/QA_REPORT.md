# รายงานตรวจรับระบบ

วันที่ตรวจล่าสุด: 2026-07-18

ขอบเขตคือโปรเจกต์ PHP/MySQL ใน `php-mysql` ตาม FR-01–FR-16 โดยใช้ `newap` เป็นข้อมูลอ้างอิงแบบอ่านอย่างเดียวและไม่แก้ไข `ap` ผลที่ต้องใช้ credential หรือระบบ production จริงถูกแยกไว้ชัดเจนเพื่อไม่อ้างผลเกินหลักฐาน

รุ่น phone-only ไม่มี PIN สำหรับ Resident และยังคง password สำหรับ Admin/Owner ผลด้าน rate limit, generic error และ session ไม่ได้เปลี่ยนข้อเท็จจริงว่าผู้รู้เบอร์ของ resident active สามารถ takeover ครั้งแรกได้; LINE OTP พิสูจน์เพียงการควบคุมบัญชี LINE ปลายทาง

## ผลตรวจอัตโนมัติ

- PHP 8.3 lint ผ่าน 46/46 ไฟล์ ไม่มี syntax error
- `php tests/run.php` ผ่าน 64/64 tests, 0 failures รวม regression ของ phone-only/PIN removal, signed LINE webhook/retry, Railway proxy trust, session lock release, slip quota/duplicate/idempotency, ประเทศ/สกุลเงินของสลิป, signed bill preview, one-active-booking-per-phone, วงจรบิลก่อนย้ายออก และ UX/schema guards สำคัญ
- `node --check public/assets/js/app.js` ผ่าน
- CI ยิงผ่าน Apache + MySQL จริงเพื่อยืนยันโควตาการจองราย IP/เบอร์, การ commit สถานะ block, การไม่สร้าง booking เมื่อถูกจำกัด และ race ของ idempotency key ที่ต้อง rollback quota ของ request ที่แพ้
- ไม่พบ PHP warning, fatal error หรือ deprecation ใน log ของ HTTP/browser E2E
- ตรวจหน้า Admin ทั้ง desktop และ mobile แล้ว เมนู/กล่องยืนยันทำงานได้ คอลัมน์ปุ่มสำคัญยังมองเห็นเมื่อเลื่อนตาราง และ browser console ไม่มี warning/error

## ผลตรวจ Oracle MySQL 8.4.10

- Fresh import `database/install.sql` ไฟล์เดียวผ่าน: 14 ตาราง, integrity triggers 15 รายการ, `billing_settings`/`integration_settings` อย่างละ 1 แถว และไม่มี Owner/ข้อมูลตัวอย่าง; import ซ้ำถูกตัวกันฐานไม่ว่างปฏิเสธก่อนแตะ schema โดยจำนวนตาราง/trigger/settings ไม่เปลี่ยน
- Fresh import `database/schema.sql` + `database/defaults.sql` ผ่านบน MySQL 8.4.10: 14 ตาราง, 73 CHECK constraints และ integrity triggers 15 รายการ รวม Channel secret, LINE provider request IDs และคอลัมน์ LINE User ID แบบ 33 ตัว; schema รุ่น phone-only ต้องไม่มี `residents.pin_hash`
- รัน `003_append_only_guards.sql` ซ้ำ 2 รอบบนฐานใหม่ได้โดยไม่เกิดข้อผิดพลาด และจำนวน trigger ยังคงเป็น 15
- จำลองฐานรุ่นก่อนแล้วรัน `002_operational_hardening.sql` สำเร็จ: เพิ่มคอลัมน์/ดัชนีที่ต้องใช้และสร้าง trigger ครบ 15 รายการ
- ทดสอบ `003_append_only_guards.sql` ในฐานที่อาจเคยใช้ migration 002 รุ่นเก่าแล้ว: สร้างตัวป้องกัน `bill_items` และ `audit_logs` ครบ 4 รายการแบบรันซ้ำได้
- ทดสอบ `004_line_webhook.sql` บน schema ก่อนเพิ่ม webhook แล้วรันซ้ำ 2 รอบสำเร็จ: ได้ 73 CHECK constraints และคอลัมน์/ชนิดข้อมูล LINE webhook/reconciliation ครบ; กรณีมี LINE User ID legacy ผิดรูปแบบ migration หยุดก่อนสร้างคอลัมน์ใหม่ตาม preflight
- ทดสอบ `005_booking_active_phone.sql` บน MySQL 8.4.10 ทั้งรอบแรกและรันซ้ำสำเร็จ; duplicate active phone, composite/lookalike index, generated expression ที่ผิด `ELSE` และ literal หลอกถูกปฏิเสธ ขณะที่ canonical expression และลำดับ `IN` ที่สลับกันผ่าน
- ทดสอบ flow phone-only กับ MySQL 8.4.10 จริงครบทั้ง fresh schema ที่ไม่มี `pin_hash` และฐาน legacy ที่คอลัมน์ยังเป็น `NOT NULL`: move-in, login ด้วยเบอร์, การปฏิเสธ payload ที่แอบส่ง `pin`, compatibility credential แบบสุ่ม และ login หลังลบคอลัมน์ผ่านทั้งหมด; `006_remove_resident_pin.sql` รันซ้ำ 2 รอบได้และยืนยันว่าคอลัมน์ถูกลบ
- `scripts/check_requirements.php --db` ด้วย runtime user ที่มีเฉพาะ `SELECT`/`INSERT`/`UPDATE` ตรวจชนิดคอลัมน์ LINE 5 รายการและ CHECK constraints ที่เกี่ยวข้องครบ โดยจบด้วย 0 error; warning ที่เหลือเป็นค่าธุรกิจ/credential/Owner ที่จงใจไม่ใส่ในฐาน fresh-install QA
- Final release database ใช้ runtime user ที่มีเฉพาะ `SELECT`/`INSERT`/`UPDATE` บน schema และผ่าน `check_requirements.php --db` โดยข้าม trigger metadata ตามข้อจำกัดสิทธิ์และไม่มี schema error
- Schema audit ด้วยบัญชี schema owner ตรวจ trigger ครบ 15 รายการ ตรวจว่าไม่มี `residents.pin_hash` และไม่มี schema error; warning ที่เหลือเป็น credential ธุรกิจที่จงใจไม่ใส่ใน QA
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
3. Resident login ด้วยเบอร์ของ resident/occupancy active และแก้ชื่อ/email รวมเริ่มผูก LINE โดยยืนยัน OTP ที่ส่งไปปลายทาง
4. ไม่มีหน้า/route เปลี่ยนหรือ reset PIN, move-in ไม่รับ PIN และ request ที่แอบส่ง field `pin` ถูกปฏิเสธ; Admin เปลี่ยนเบอร์แล้ว session เดิมถูกเพิกถอนและเบอร์ใหม่ login ได้
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
- account limiter ตอบ generic 401 เหมือนกัน และรหัสผ่าน Admin ที่ถูกต้องไม่ข้าม account bucket ที่ถูก block; username ที่ไม่มีจริงหลายค่าใช้ unknown bucket ต่อ IP ร่วมกัน โดยมี rate-limit rows รวมเพียง 8 แถวใน scenario ทั้งหมด Resident ไม่มี trusted-device bypass และต้องถูกตรวจแยกว่า malformed/inactive/ไม่มี occupancy ไม่เผยสาเหตุ
- Admin เปลี่ยนข้อมูล/เบอร์ผู้พักได้จากหลังบ้าน การเปลี่ยนเบอร์เพิ่ม `auth_version`, session เดิมใช้ต่อไม่ได้ และเบอร์ใหม่ login แบบ phone-only ได้
- ค่ามิเตอร์น้ำและไฟที่สูงผิดปกติถูกรวบรวมเตือนพร้อมกัน 2 ประเภท ต้องยืนยันทั้งชุด และแก้ค่าหลังออกบิลไม่ได้
- bill preview ผูก HMAC กับข้อมูลจริงและหมดอายุ; เปลี่ยนมิเตอร์หลัง preview แล้วใช้ token เดิมถูกปฏิเสธด้วย `BILL_PREVIEW_CHANGED` ส่วน preview ใหม่ออกบิลสำเร็จ
- PromptPay QR, LINE outbox, signed webhook contract, booking/payment pagination และการเก็บ LINE Channel access token/Channel secret แบบ ciphertext โดยไม่คืน secret ผ่าน API ทำงานตาม contract

## สิ่งที่ต้องตรวจด้วย credential/ระบบ deploy จริง

- LINE Developers Verify webhook/ข้อความตอบจริงและ LINE push จริง รวม quota/permission, retry payload/recipient/key เดิม, provider request IDs และ HTTP 409 replay
- SlipOK/EasySlip ด้วยสลิปจริง: สำเร็จ, ยอดผิด, ผู้รับผิด, transaction ซ้ำ, cached duplicate, 429 และ timeout
- HTTPS/HSTS/reverse proxy, firewall/network policy และ trusted proxy ของโดเมนจริง
- Worker/cron หลาย process, health monitoring และ alert/log aggregation บนเครื่อง deploy
- Backup/restore drill ที่กู้ MySQL, private slips และ `APP_KEY` รุ่นเดียวกับ ciphertext ได้
- ฐานเดิมต้องทดสอบลำดับ deploy แอป phone-only ก่อนรัน `006_remove_resident_pin.sql`, รัน migration ซ้ำได้ และยืนยันว่า move-in ยังทำงานได้ทั้งช่วงที่ `pin_hash NOT NULL` ยังอยู่กับหลังคอลัมน์ถูกลบ; fresh schema ต้องไม่มีคอลัมน์นี้

ระบบทำงานแบบ fail-closed: provider ที่ยังไม่พร้อมจะไม่ทำให้ bill เป็น paid, ไม่มี endpoint ให้ Admin กดอนุมัติชำระเอง, รายการที่ผลยังไม่แน่นอนคงเป็น pending เพื่อ retry หรือปิดพร้อมเหตุผล และ credential ไม่ถูกคืนผ่าน API หรือบันทึกใน log/audit
