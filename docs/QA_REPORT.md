# รายงานตรวจรับระบบ

ผลตรวจและการแก้ไขรอบใหม่อยู่ที่ [รายงานวันที่ 2026-09-15](AUDIT_2026-09-15.md) ส่วนเอกสารด้านล่างเป็นหลักฐานของรอบก่อนหน้า

สถานะล่าสุด: ขยายหลาย LINE OA/หลายบัญชีต่อห้อง/ผู้รับแจ้งเตือนแล้ว ต้องใช้ migration 014 (21 ตาราง/23 triggers/116 CHECKs) ตัวเลข 16/19/86 และขั้น migration ด้านล่างเป็นรายงานฐานเดิมก่อนงานขยายนี้ ผลตรวจล่าสุดเรื่อง LINE และคำสั่งห้อง/บิล: [คู่มือใช้งาน](LINE_BINDING.md) และ [ผลตรวจ LINE วันที่ 2026-09-15](LINE_QA_2026-09-15.md)

วันที่ตรวจล่าสุด: 2026-08-04

ขอบเขตคือโปรเจกต์ PHP/MySQL ใน `php-mysql` ตาม FR-01–FR-16 โดยใช้ `newap` เป็นข้อมูลอ้างอิงแบบอ่านอย่างเดียวและไม่แก้ไข `ap` ผลที่ต้องใช้ credential หรือระบบ production จริงถูกแยกไว้ชัดเจนเพื่อไม่อ้างผลเกินหลักฐาน

Resident ไม่มี PIN แล้ว แต่ต้องใช้เบอร์ของ resident/occupancy active ร่วมกับ password ครั้งแรกใช้ activation code แบบครั้งเดียวที่หมดอายุเพื่อตั้ง password ระบบเก็บเฉพาะ HMAC ของ code และ password hash; Admin/Owner ใช้ username/password แยกกัน ส่วนการผูก LINE ใช้รหัส `BIND-` แบบสุ่ม 128 บิต อายุ 10 นาที และเก็บเฉพาะ HMAC ใน MySQL

## ผลตรวจอัตโนมัติ

- PHP lint ผ่านครบทุกไฟล์ PHP ใน source tree และ `bash -n` ผ่านสคริปต์ deployment ทั้ง 10 ไฟล์
- `php tests/run.php` ผ่าน 104/104 tests, 0 failures รวม resident activation/password, check-in/meter baseline และ move-in replay digest, monthly billing, สถานะสลิปล่าสุด/การกันส่ง LINE ซ้ำ, LINE webhook rate-limit/deadline/redelivery, Railway proxy trust, session lock release, slip quota/duplicate/idempotency, duplicate-key scoping, accessibility contracts และ schema guards สำคัญ
- `php tests/notification_worker_hardening.php` ผ่าน และ CLI ของ worker ปฏิเสธ unknown/duplicate/out-of-range arguments ด้วย exit 64 ก่อน bootstrap; `php scripts/test_generate_monthly_bills_cli.php` ผ่าน 7/7 cases
- ทดสอบ monthly billing CLI กับ MySQL จริงและ runtime user แบบ least privilege: dry-run รอบปิดพบ 1 ห้อง, apply สร้างบิลยอด 4,299.00 ถูกต้อง, apply ซ้ำเป็น no-op และไม่สร้างบิลซ้ำ
- HTTP smoke ด้วย PHP server ผ่าน `GET /`, `/healthz.php`, public rooms, Owner login และ admin rooms ตามลำดับ `200/200/200/200/200`; การทดสอบนี้ยืนยัน routing/session/DB แบบ local แต่ไม่แทน reverse proxy/TLS ของ Railway
- `node --check public/assets/js/app.js` ผ่าน
- CI ยิงผ่าน Apache + MySQL จริงเพื่อยืนยันโควตาการจองราย IP/เบอร์, การ commit สถานะ block, การไม่สร้าง booking เมื่อถูกจำกัด และ race ของ idempotency key ที่ต้อง rollback quota ของ request ที่แพ้
- ไม่พบ PHP warning, fatal error หรือ deprecation ใน log ของ HTTP/browser E2E
- ตรวจหน้า Admin ทั้ง desktop และ mobile แล้ว เมนู/กล่องยืนยันทำงานได้ คอลัมน์ปุ่มสำคัญยังมองเห็นเมื่อเลื่อนตาราง และ browser console ไม่มี warning/error

## ผลตรวจ Oracle MySQL 8.4.10

- `database/install.sql` ถูกสร้างจาก schema/default sources ปัจจุบัน (source digest `3327135c65c6d7793cc2151004ed191221662ceeda02184df1f255e5375680f8`); final CI ต้องคงการทดสอบ import ไฟล์เดียวและ guard ฐานไม่ว่าง
- Fresh schema ปัจจุบันกำหนด 16 ตาราง, CHECK constraints อย่างน้อย 86 รายการ และ integrity triggers 19 รายการ รวม booking/occupancy insert guards, notification claim lease/heartbeat, resident credential, occupancy-meter binding, `line_link_codes`, LINE Basic ID และ move-in request hash; ไม่มี `residents.pin_hash` การ import/requirement gate ต้องรันซ้ำหลังเพิ่ม migration 012
- รัน `003_append_only_guards.sql` ซ้ำ 2 รอบบน fixture schema ขั้นก่อน migrations 007–009 ได้โดยไม่เกิดข้อผิดพลาด และจำนวน trigger ใน fixture ขั้นนั้นยังคงเป็น 15; final fresh schema มี 19 triggers ตามบรรทัดก่อนหน้า
- จำลองฐานรุ่นก่อนแล้วรัน `002_operational_hardening.sql` สำเร็จ: เพิ่มคอลัมน์/ดัชนีที่ต้องใช้และสร้าง trigger ครบ 15 รายการ
- ทดสอบ `003_append_only_guards.sql` ในฐานที่อาจเคยใช้ migration 002 รุ่นเก่าแล้ว: สร้างตัวป้องกัน `bill_items` และ `audit_logs` ครบ 4 รายการแบบรันซ้ำได้
- ทดสอบ `004_line_webhook.sql` บน schema ก่อนเพิ่ม webhook แล้วรันซ้ำ 2 รอบสำเร็จ: ได้ 73 CHECK constraints และคอลัมน์/ชนิดข้อมูล LINE webhook/reconciliation ครบ; กรณีมี LINE User ID legacy ผิดรูปแบบ migration หยุดก่อนสร้างคอลัมน์ใหม่ตาม preflight
- ทดสอบ `005_booking_active_phone.sql` บน MySQL 8.4.10 ทั้งรอบแรกและรันซ้ำสำเร็จ; duplicate active phone, composite/lookalike index, generated expression ที่ผิด `ELSE` และ literal หลอกถูกปฏิเสธ ขณะที่ canonical expression และลำดับ `IN` ที่สลับกันผ่าน
- ทดสอบ transitional commit `a52bc33` กับ MySQL 8.4.10 บนฐาน legacy ที่ `pin_hash NOT NULL`, รัน `006_remove_resident_pin.sql` ซ้ำ 2 รอบและยืนยันว่าคอลัมน์ถูกลบ; source ปัจจุบันปฏิเสธ field `pin` และใช้ activation/password แทน
- `scripts/check_requirements.php --db` ปัจจุบันตรวจ 16 ตาราง, คอลัมน์ migrations 002/007/008/009/010/011/012, จำนวน CHECK ขั้นต่ำร่วมกับ named CHECK ของ LINE Basic ID และ move-in request hash, generated/unique/operational indexes และ foreign key ของ occupancy/LINE binding; canonical fresh schema มี CHECK 86 รายการ ต้องรัน checker ด้วย runtime user ที่มีเฉพาะ `SELECT`/`INSERT`/`UPDATE` และจบด้วย 0 schema error ก่อนเปิด production
- Final release database ใช้ runtime user ที่มีเฉพาะ `SELECT`/`INSERT`/`UPDATE` บน schema และผ่าน `check_requirements.php --db` โดยข้าม trigger metadata ตามข้อจำกัดสิทธิ์และไม่มี schema error
- Schema audit ด้วยบัญชี schema owner ตรวจ trigger ครบ 19 รายการรวม event/timing/table/body ตรง canonical ตรวจว่าไม่มี `residents.pin_hash` และไม่มี schema error; warning ที่เหลือเป็น credential ธุรกิจที่จงใจไม่ใส่ใน QA
- ทดสอบตรงว่า `audit_logs` แก้ไขและลบไม่ได้ทั้งคู่
- `php tests/lifecycle_mysql.php` ผ่านบนฐาน MySQL แยกด้วย runtime user แบบ least privilege ตั้งแต่เพิ่มผู้พัก/activation/password → เปลี่ยนอีเมลแล้ว replay move-in เดิมด้วย digest → จดมิเตอร์ → preview/ออกบิล idempotent → เข้าคิว LINE แล้วมีสลิป pending ก่อน worker ซึ่งต้องยกเลิกการส่ง → จำลองผล provider ที่ verified → paid → ย้ายออก/เพิกถอน session และ credential รวมกรณี payload move-in ที่เปลี่ยนต้องถูกปฏิเสธ, digest แก้ย้อนหลังไม่ได้ และห้องว่างมีมิเตอร์ในเดือนย้ายเข้าที่ต้องตอบ `MOVE_IN_METER_PERIOD_CONFLICT` โดย rollback ไม่ทิ้ง booking/resident/occupancy ครึ่งชุด
- จำลองฐาน schema เดิม 14 ตาราง/15 triggers แล้วรัน `007` → `008` → `009`: เมื่อ active occupancy ขาดค่าเปิดมิเตอร์ `009` หยุดก่อนเพิ่ม CHECK/trigger ใหม่ตาม guard, หลังใส่ค่าจากหลักฐานทดสอบแล้วรันซ้ำ 2 รอบสำเร็จเป็น 15 ตาราง/19 triggers/80 CHECKs และ Admin reissue activation ให้ resident เดิมได้; ขั้น release ปัจจุบันต้องรัน `010` ต่อเพื่อเป็น 16 ตาราง/84 CHECKs, `011` เป็น 16 ตาราง/85 CHECKs และ `012` เป็น canonical 16 ตาราง/19 triggers/86 CHECKs
- บนฐานทดสอบแยก จงใจทำ `previous_reading` ไม่ตรงเดือนก่อนและจงใจผูก meter reading เข้ากับ occupancy ผิดห้อง: migration `009` หยุดด้วย guard ที่ตรงสาเหตุทั้งสองกรณี จากนั้นซ่อมข้อมูลแล้วรันซ้ำ 2 รอบสำเร็จและคง 15 ตาราง/19 triggers/80 CHECKs ก่อนเพิ่ม `010`; หลัง `010` ต้องพบ 16 ตาราง/19 triggers/84 CHECKs, หลัง `011` เป็น 16 ตาราง/19 triggers/85 CHECKs และหลัง `012` เป็น 16 ตาราง/19 triggers/86 CHECKs
- ทดสอบ guard lifecycle เพิ่มด้วยข้อมูลผิดทีละกรณี: resident เดียวมี occupancy ทับเดือนข้ามห้อง, moved-in booking ไม่มี occupancy และค่าเช่า booking/occupancy ไม่ตรงกัน ทุกกรณีทำให้ `009` หยุดที่ guard ก่อนติดตั้ง trigger และหลังซ่อมรันซ้ำ 2 รอบสำเร็จ
- ลบ verified payment ออกจาก paid bill ในฐานทดสอบที่ถอด no-delete trigger ชั่วคราว: ทั้ง migration `009` และ runtime readiness หยุดด้วย financial relationship guard; trigger ใหม่ยังปฏิเสธ direct `INSERT` บิลสถานะ paid แม้ snapshot อื่นถูกต้อง
- แทน trigger audit แบบ no-op โดยคงชื่อ/event/timing/table เดิม: `--schema-audit` ตรวจ body drift และล้มเหลว จากนั้น controlled recovery ด้วย canonical schema ทำให้ audit ผ่านครบ 19 trigger bodies
- จงใจสร้าง resident ที่ active แต่ไม่มีทั้ง password และ activation code ที่ยังใช้ได้: `check_requirements.php --db` ตอบ failure และหยุด readiness; หลังปิดแถวทดสอบ ตัวตรวจกลับมาจบด้วย 0 schema/data error

Negative/positive invariants ที่ยิงตรงผ่าน MySQL ผ่านทั้งหมด:

- แก้ snapshot ค่าเช่าของ booking/occupancy ไม่ได้
- แก้หลักฐาน confirm/cancel/move-in หลังบันทึกไม่ได้
- เปลี่ยน bill เป็น paid โดยไม่มี verified payment ที่ตรงกันไม่ได้
- payment ต้องเริ่ม pending, lease ต้องมี token และ payment ที่สรุปแล้วแก้/ลบไม่ได้
- bill item ต้องตรง snapshot ของ bill, item type ซ้ำไม่ได้ และแก้/ลบภายหลังไม่ได้
- notification outbox ผูก bill ข้าม resident ไม่ได้ทั้ง insert/update
- bill และ audit log ลบไม่ได้; audit log แก้ไม่ได้
- เส้นทาง verified payment + bill paid ที่มีหลักฐานครบทำงานสำเร็จ

## Provider/staging E2E ที่ต้องยืนยันซ้ำหลัง migrations 007–012

MySQL lifecycle แบบ offline ผ่านแล้วตามหัวข้อก่อนหน้า แต่ผลนั้นจำลองเฉพาะผล provider ที่ verified และไม่แทน browser/HTTP, LINE หรือ Slip provider จริง ก่อนเปิด production ต้องรันบน staging แยกตั้งแต่ต้นจนจบ:

1. Owner login และตั้งค่า billing/PromptPay จากหลังบ้าน
2. สร้างห้อง, จองแบบ idempotent, ยืนยัน และย้ายเข้า
3. ใช้ activation code จาก move-in ร่วมกับเบอร์เพื่อตั้ง password, ยืนยันว่า code ใช้ซ้ำ/หมดอายุไม่ได้ แล้ว login รอบถัดไปด้วย password และแก้ชื่อ/email
4. ไม่มีหน้า/route PIN และ request ที่แอบส่ง field `pin` ถูกปฏิเสธ; Admin เปลี่ยนเบอร์หรือ reissue access แล้ว password/session เดิมถูกเพิกถอนและ code ใหม่ใช้ได้ครั้งเดียว
5. สร้างรหัส `BIND-` จากหน้า Resident, ส่งในแชตส่วนตัวกับ OA ภายใน 10 นาที, ยืนยันว่าใช้ซ้ำ/หมดอายุไม่ได้ และ unlink/rebind แล้ว audit ไม่เก็บรหัสหรือ LINE User ID ดิบ
6. ระบบไม่ยอมย้ายออกถ้ายังไม่มีบิลปิดรอบหรือมีบิลค้าง
7. บันทึกมิเตอร์และออกบิลโดยป้องกันบิลซ้ำ
8. เปิดหลักฐานสลิปส่วนตัวผ่าน endpoint ที่ตรวจสิทธิ์และมี audit
9. Retry การตรวจสลิปเมื่อยังไม่ตั้ง provider ตอบ 503 แบบ fail-closed และไม่เปลี่ยนบิลเป็น paid
10. ปิดรายการ pending ไม่ได้ขณะ verification lease ยังทำงาน และปิดได้หลัง lease หมดโดยต้องใส่เหตุผล
11. หลังมี verified payment ที่ตรงตามข้อบังคับจึงย้ายออกสำเร็จ, session Resident ถูกเพิกถอน และห้องกลับเป็นว่าง

ผลสุดท้ายที่ต้องยืนยัน: `room=1, booking=1, resident=1, bill=1, payment=1` และสถานะ `occupancy=ended`, `resident=inactive`, `bill=paid` พร้อม audit ของงานสำคัญ

รอบ final regression บนฐานใหม่อีกชุดต้องยืนยันเพิ่มเติมว่า:

- Anonymous GET หลายครั้งและหน้า Guest ไม่สร้าง cookie/ไฟล์ session (`17 → 17` ไฟล์เดิม) แต่ login ที่สำเร็จจึงเริ่ม session
- booking ที่ room ID ไม่ถูกต้อง 2 ครั้งไม่กิน phone quota และ booking ห้องจริงครั้งถัดไปยังสำเร็จ
- account limiter ตอบ generic 401 เหมือนกัน และ credential ที่ถูกต้องไม่ข้าม account bucket ที่ถูก block; principal ที่ไม่มีจริงหลายค่าใช้ unknown bucket ต่อ IP ร่วมกัน Resident ไม่มี trusted-device bypass และ malformed/inactive/ไม่มี occupancy/credential ผิดต้องไม่เผยสาเหตุ
- Admin เปลี่ยนข้อมูล/เบอร์ผู้พักและ reissue access ได้จากหลังบ้าน การเปลี่ยนเพิ่ม `auth_version`, session/password เดิมใช้ต่อไม่ได้ และเบอร์ใหม่ต้องใช้ activation code ใหม่ก่อนตั้ง password
- ค่ามิเตอร์น้ำและไฟที่สูงผิดปกติถูกรวบรวมเตือนพร้อมกัน 2 ประเภท ต้องยืนยันทั้งชุด และแก้ค่าหลังออกบิลไม่ได้
- bill preview ผูก HMAC กับข้อมูลจริงและหมดอายุ; เปลี่ยนมิเตอร์หลัง preview แล้วใช้ token เดิมถูกปฏิเสธด้วย `BILL_PREVIEW_CHANGED` ส่วน preview ใหม่ออกบิลสำเร็จ
- PromptPay QR, LINE outbox, signed webhook contract, booking/payment pagination และการเก็บ LINE Channel access token/Channel secret แบบ ciphertext โดยไม่คืน secret ผ่าน API ทำงานตาม contract

## สิ่งที่ต้องตรวจด้วย credential/ระบบ deploy จริง

- LINE Developers Verify webhook, การ consume รหัส `BIND-`, ข้อความตอบ, unlink/rebind และ LINE push จริง รวม quota/permission, retry payload/recipient/key เดิม, provider request IDs, 409 ที่มี `x-line-accepted-request-id` ถูกต้อง และ bare/invalid 409 ที่ต้องไม่ถูกนับว่าสำเร็จ รายการนี้ยังต้องใช้ Channel access token/Channel secret และ OA จริงบน staging; test แบบ mock/offline ไม่ทดแทน E2E ภายนอก
- SlipOK/EasySlip ด้วยสลิปจริง: สำเร็จ, ยอดผิด, ผู้รับผิด, transaction ซ้ำ, cached duplicate, 429 และ timeout
- HTTPS/HSTS/reverse proxy, firewall/network policy และ trusted proxy ของโดเมนจริง
- Worker/cron หลาย process, health monitoring และ alert/log aggregation บนเครื่อง deploy
- Backup/restore drill ที่กู้ MySQL, private slips และ `APP_KEY` รุ่นเดียวกับ ciphertext ได้
- เครื่องทดสอบนี้ไม่มีคำสั่ง Docker จึงยังไม่ได้ build/run production image; CI หรือ staging ที่มี Docker ต้องสร้าง image และรัน healthcheck ของทั้ง web/worker/job ก่อน deploy
- ฐานเดิมต้องทดสอบลำดับ transitional commit `a52bc33` → `006` → ปิด public write/หยุด worker/pause monthly-billing cron → `007` → `008` → `009` → `010` → `011` → `012` → deploy source ปัจจุบันโดยยังปิด traffic → reissue credential ผู้พักเดิม → ผ่าน runtime production gate และ schema audit → เปิด traffic/worker/cron; source ปัจจุบันต้องไม่ถูก deploy บน schema ที่ยังมี `pin_hash NOT NULL` หรือขาด claim lease/heartbeat/resident credential/occupancy-meter/LINE binding/Basic ID/move-in hash guards

ระบบทำงานแบบ fail-closed: provider ที่ยังไม่พร้อมจะไม่ทำให้ bill เป็น paid, ไม่มี endpoint ให้ Admin กดอนุมัติชำระเอง, รายการที่ผลยังไม่แน่นอนคงเป็น pending เพื่อ retry หรือปิดพร้อมเหตุผล และ credential ไม่ถูกคืนผ่าน API หรือบันทึกใน log/audit
