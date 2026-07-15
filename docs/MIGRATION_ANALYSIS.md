# ผลวิเคราะห์และแนวทางย้ายระบบเดิม

เอกสารนี้บันทึกผลวิเคราะห์และเหตุผลของ PHP/MySQL rewrite โดยใช้ `1.txt` เป็นขอบเขตสูงสุด รอบการตรวจนี้อ่าน `newap/` เป็นข้อมูลอ้างอิงเพิ่มเติมแบบ read-only โดยไม่แก้ไฟล์ในนั้น และไม่ได้แตะโฟลเดอร์ `ap/`

## สิ่งที่พบในโครงการเดิม

- `ap-main/` เป็น source หลัก ส่วน `ap-main-current-snapshot-*` และ `ap-push-worktree-*` เป็น snapshot/worktree จึงไม่ควรนำมารวมกันเป็น source เดียว
- ระบบหลักใช้ Node.js 18, Express และ PostgreSQL (`express`, `pg`, `connect-pg-simple`) พร้อมหน้า SPA ที่มีสะพาน `localStorage` ↔ `/api/data`
- ข้อมูลบางส่วนอยู่ใน `app_data` แบบ JSONB ขณะที่ข้อมูลรุ่นใหม่อยู่ในตาราง relational เช่น tenant, bill, payment, contract และ access ทำให้มีโอกาสเกิดข้อมูลสองแหล่งที่ไม่ตรงกัน
- migration เดิมถูกรันร่วมกับการเริ่ม server และมี in-process scheduler สำหรับ backup/late fee/auto billing ซึ่งเพิ่มผลข้างเคียงระหว่าง deploy
- โครงการเดิมมีฟีเจอร์เกิน `1.txt` จำนวนมาก เช่น แจ้งซ่อม สัญญา access/RFID รายงาน Excel/PDF feature flags recurring charge, health/backup UI, SMS/email, Slip2Go และ booking deposit
- รูป/ไฟล์บางเส้นทางเคยเก็บเป็น base64 หรือพึ่ง local storage/object storage จึงไม่เหมาะกับการคัดลอกโครงสร้างเดิมเข้าฐานข้อมูลใหม่โดยตรง

## รูปแบบจาก `newap/` ที่นำมาใช้

การตรวจ `newap/` ใช้เพื่อเทียบพฤติกรรมและหาจุดขาดของ workflow ไม่ใช่การคัดลอกทั้งระบบ รูปแบบที่นำมาปรับให้เข้ากับ FR-01–FR-16 มีดังนี้

- การกู้คืนงานตรวจสลิปที่ยังไม่จบ: Admin ดูหลักฐานที่ผ่านการตรวจความสมบูรณ์ สั่งตรวจซ้ำภายใต้ verification lease หรือปิดรายการ pending ที่หมด lease พร้อมเหตุผลได้ โดยไม่มีทางลัด manual paid/approve
- การจัดการผลลัพธ์ mutation ที่กำกวม: client กำหนด timeout และแยกกรณีที่คำสั่งอาจสำเร็จฝั่ง server แล้ว เพื่อให้ผู้ใช้ refresh ตรวจสถานะก่อนส่งคำสั่งเดิมซ้ำ
- วงจรผู้พัก: Admin รีเซ็ต PIN พร้อม revoke session เก่า และย้ายออกด้วย transaction หลังตรวจว่าบิลเดือนปิดท้ายชำระแล้วและไม่มีบิล pending

ฟีเจอร์อื่นของระบบอ้างอิง เช่น multi-property, สัญญา, บัญชีขั้นสูง และ scheduler ไม่ได้ถูกนำมาโดยอัตโนมัติ เพราะอยู่นอกขอบเขต `1.txt` และต้องมี requirement/schema/authorization/test แยกต่างหาก

## การตัดสินใจสำหรับระบบใหม่

ระบบใหม่อยู่ใน `appj/php-mysql/` โดยแยกออกจาก source อ้างอิงใน `appj/newap/` และ `appj/ap/` เพื่อให้ย้อนตรวจและเปรียบเทียบได้โดยไม่แก้ต้นฉบับ การออกแบบใหม่มีหลักดังนี้

- ใช้ PHP 8.2+ แบบ front controller และ MySQL 8/InnoDB
- ย้ายเฉพาะ FR-01 ถึง FR-16 จาก `1.txt`; รายการอื่นไม่สร้าง route, table หรือหน้าจอตามมา
- เลิกใช้ JSONB/localStorage เป็นแหล่งข้อมูลหลัก แล้วแยกเป็น 14 ตาราง relational พร้อม FK, CHECK, unique/generated keys และ transaction
- สถานะห้องคำนวณจาก booking/occupancy จริง ไม่เปิดให้ client แก้ status โดยตรง
- บิลเก็บ snapshot ค่าเช่า มิเตอร์ อัตรา และยอด ณ วันที่ออกบิล พร้อม trigger ห้ามแก้ข้อมูลการเงินย้อนหลัง
- สลิปถูก decode/re-encode และเก็บใต้ private storage; การเปิดหลักฐานผ่าน route ที่ยืนยันสิทธิ์ ตรวจ canonical path/MIME/ขนาด/dimensions/HMAC ซ้ำ และบันทึก audit เท่านั้น
- ค่าใช้งาน PromptPay, LINE, SlipOK และ EasySlip จัดการจาก Admin → ตั้งค่า โดย Owner และเก็บใน singleton `integration_settings`; LINE token/API key เข้ารหัส AES-256-GCM ด้วย `APP_KEY` และ AAD แยก field ส่วน `APP_KEY`, `APP_URL` และค่าเชื่อมต่อ DB ยังคงเป็น infrastructure environment

รายละเอียดการจับคู่ทุก requirement อยู่ใน `FEATURE_MATRIX.md` ส่วน API contract อยู่ใน `ARCHITECTURE.md`

## ข้อมูลเดิมกับ MySQL ใหม่

`database/defaults.sql` เป็นค่าเริ่มต้นที่ปลอดภัยสำหรับทุกสภาพแวดล้อม มีเฉพาะ singleton settings ที่ไม่มี credential และไม่มีห้อง/ผู้เช่า/บัญชีผู้ดูแล ส่วน `database/demo.sql` เป็นข้อมูลห้องทดสอบแบบเห็นชัดที่ต้อง import เองเฉพาะฐาน local ไม่ใช่ตัวนำเข้าข้อมูล production การนำข้อมูลใช้งานจริงจาก PostgreSQL เดิมเข้ามาต้อง reconcile ก่อน เพราะห้อง/ผู้เช่า/บิลอาจปรากฏทั้ง JSONB และตาราง relational

การติดตั้งใหม่ import `database/schema.sql` แล้ว `database/defaults.sql`; schema มีโครงสร้างล่าสุดและ defaults สร้าง singleton `billing_settings`/`integration_settings` โดยไม่มี credential จึงไม่ต้องรัน migration ซ้ำ ส่วนระบบ PHP/MySQL ที่ติดตั้งจาก schema รุ่นก่อนต้องสำรองและทดสอบ restore แล้วใช้บัญชี schema/migration ที่มีสิทธิ์ DDL รัน `database/migrations/001_integration_settings.sql` เมื่อยังไม่มีตาราง integration จากนั้นรัน `database/migrations/002_operational_hardening.sql` หนึ่งครั้งเพื่อเพิ่ม snapshot การจอง/บิล, payment verification lease และ integrity triggers ชุด 15 รายการ แล้วรัน `database/migrations/003_append_only_guards.sql` เพื่อซ่อม/ยืนยัน trigger แบบ append-only 4 รายการของ `bill_items` และ `audit_logs` ไฟล์ `001` เป็น idempotent และไม่คัดลอก secret จาก environment, ไฟล์ `002` ห้ามรันซ้ำ ส่วนไฟล์ `003` ออกแบบให้รันซ้ำได้ หลัง migration ต้องตรวจด้วย `--schema-audit` โดยบัญชี DBA/schema owner ชั่วคราว

หลังอัปเกรด Owner ต้องกรอก PromptPay/LINE/SlipOK/EasySlip ใหม่ผ่านหน้าหลังบ้าน ค่าดำเนินงานจาก `.env` รุ่นเดิมไม่ถูกอ่านเป็น fallback เพื่อป้องกัน configuration สองแหล่ง ข้อมูล secret ที่ API คืนมีเพียงสถานะ configured และ hint แบบปิดบัง ช่อง secret ว่างเก็บค่าเดิมและต้องใช้คำสั่ง clear โดยชัดแจ้งเมื่อต้องการลบ Web กับ worker อ่านแถวฐานข้อมูลในรอบใช้งานถัดไป จึงไม่ต้อง restart หลังบันทึก

ลำดับที่แนะนำสำหรับการย้ายข้อมูลจริง:

1. หยุดการเขียนระบบเดิมชั่วคราวและสำรอง PostgreSQL พร้อมไฟล์ object/local storage
2. เลือก source of truth ต่อ entity แล้วตรวจรายการซ้ำ โดยเฉพาะเบอร์ผู้เช่า ห้อง การจอง active และเลขอ้างอิงธุรกรรม
3. นำเข้าห้อง → ผู้เช่า → booking → occupancy → meter → bill/bill item → payment ตามลำดับ FK
4. แปลงยอดเงินเป็นทศนิยม 2 ตำแหน่ง และรอบบิลเป็นวันแรกของเดือน
5. ห้ามย้ายสถานะ `paid` หากไม่มีหลักฐานยอด ผู้รับ และ transaction reference ที่ reconcile แล้ว
6. เปรียบเทียบยอดรวม จำนวนห้อง active ผู้เช่าปัจจุบัน และบิลค้างระหว่างสองระบบ ก่อนสลับ traffic
7. ตั้งค่า integration ผ่าน Owner UI, ตรวจ readiness โดยไม่เปิดเผย secret และทดสอบกับ credential/staging ของผู้ติดตั้งก่อนสลับ traffic
8. เก็บระบบเดิมแบบ read-only ตามระยะเวลานโยบาย แล้วจึงทำลายข้อมูลอย่างควบคุม

ไม่มีการสร้าง importer ที่เดา source of truth อัตโนมัติ เพราะอาจย้ายข้อมูลขัดกันหรือทำให้บิลผิดคน การย้ายข้อมูล production ต้องทำจาก export ที่ freeze แล้วและได้รับการอนุมัติจากเจ้าของข้อมูล

## รายการที่ตัดออกโดยตั้งใจ

- แจ้งซ่อม สัญญา/ลายเซ็น เงินประกัน พัสดุ ที่จอดรถ และ access card
- รายงาน/ส่งออก Excel/PDF, VAT/บัญชีขั้นสูง และ feature flags
- recurring charges manager, auto-billing scheduler, backup/health UI
- SMS/email, Slip2Go, booking deposit/hold และ manual paid override

การเพิ่มรายการเหล่านี้ภายหลังต้องมี requirement, schema, authorization และ test แยก ไม่ควรนำตาราง/route เดิมกลับมาโดยอัตโนมัติ
