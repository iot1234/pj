# คู่มือตั้งค่า MySQL

> Source ปัจจุบันต้องอัปเกรดตามลำดับถึง `015_pending_occupancy_opening_readings.sql` โดยรัน `013_trigger_collation_pinning.sql` → `014_line_platform.sql` → `015_pending_occupancy_opening_readings.sql` ต่อจาก `012` ก่อน deploy Fresh schema/install รวมแล้วและมี 21 ตาราง/23 triggers/116 CHECK constraints อ่านขั้นตอน LINE เพิ่มเติมใน [คู่มือ LINE](LINE_BINDING.md)

ระบบต้องเชื่อมต่อฐานข้อมูลได้ก่อนจึงจะเปิดหน้าหลังบ้านได้ ดังนั้นค่าการเชื่อมต่อ MySQL (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) เป็นค่าโครงสร้างพื้นฐานที่ตั้งในไฟล์ `.env` หรือ secret manager ของเครื่อง deploy ไม่สามารถย้ายไปตั้งจากหน้า Admin ได้ phpMyAdmin เป็นเพียงหน้าจอสำหรับ import/ตรวจ/ดูแลฐานข้อมูล ไม่ใช่จุดที่แอปอ่านค่าการเชื่อมต่อ ส่วนเบอร์ PromptPay และ API key ตรวจสลิปให้ตั้งจาก Admin → ตั้งค่า และคีย์ LINE ให้ตั้งจาก Admin → บัญชี LINE OA หลังระบบเชื่อมต่อฐานข้อมูลแล้ว

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
| `DB_SSL`, `DB_SSL_CA` | TLS ระหว่าง PHP/สคริปต์ติดตั้งกับ MySQL ระยะไกล | network ส่วนตัวภายใน Compose ใช้ `false` ได้ | เปิดเมื่อ MySQL ระยะไกลรองรับและมีไฟล์ CA แบบ absolute path ที่อ่านได้; ตัวติดตั้งจะบังคับตรวจ hostname/certificate และหยุดทันทีหาก client ทำไม่ได้ |

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
8. ยืนยันว่า `residents.pin_hash` ไม่มีแล้ว หยุด notification worker ทุก instance แล้วรัน `database/migrations/007_notification_worker_fencing.sql` ด้วย schema owner ไฟล์นี้เพิ่ม claim token/lease, ดัชนี lease และตาราง heartbeat และจะคืนเฉพาะงาน `processing` แบบเก่าที่ไม่มี claim/lease กลับเป็น `pending`; คง worker ไว้ในสถานะหยุดจน deploy source ที่รองรับ schema นี้
9. รัน `database/migrations/008_resident_access_credentials.sql` เพื่อเพิ่ม activation credential แบบใช้ครั้งเดียวและ password hash ของผู้พัก ไฟล์นี้ปลอดภัยต่อการรันซ้ำ
10. เปิด maintenance window ที่ปิด public write, หยุด web/worker และ pause monthly-billing cron สำรองข้อมูลอีกครั้ง แล้วรัน `database/migrations/009_occupancy_meter_baselines.sql` หลัง `008` เพื่อผูกมิเตอร์กับ occupancy และเก็บค่าเปิดมิเตอร์ โดยยังคงปิด traffic และยังไม่ deploy source ปัจจุบันจนกว่าจะรัน `010`–`015` ในขั้นถัดไปครบ
11. ขณะที่ยังปิด traffic ให้รัน `database/migrations/010_line_self_service_binding.sql` หลัง `009` เพื่อสร้าง `line_link_codes` สำหรับรหัส `BIND-`; migration นี้ปลอดภัยต่อการรันซ้ำเมื่อ table มีนิยามตรง และจะหยุดหากพบ table บางส่วนหรือคอลัมน์/FK/CHECK/index สำคัญไม่ตรง
12. ขณะที่ยังปิด traffic ให้รัน `database/migrations/011_line_add_friend_identity.sql` หลัง `010` เพื่อเพิ่ม `integration_settings.line_basic_id` สำหรับ LINE Official Account Basic ID สาธารณะพร้อม named CHECK ที่รับรูปแบบ `@...`; migration นี้ปลอดภัยต่อการรันซ้ำและจะหยุดหาก postcondition ของคอลัมน์หรือ CHECK ไม่ครบ
13. ขณะที่ยังปิด traffic ให้รัน `database/migrations/012_move_in_request_hash.sql` หลัง `011` เพื่อเพิ่ม `bookings.move_in_request_hash`, named CHECK และ trigger ที่ตรึง digest หลังย้ายเข้า Migration นี้ปลอดภัยต่อการรันซ้ำ, ไม่สร้าง hash ย้อนหลังจากอีเมลปัจจุบัน และจะหยุดหาก column/data/postcondition ไม่ตรง
14. รัน `database/migrations/013_trigger_collation_pinning.sql` หลัง `012` เพื่อสร้าง `trg_occupancies_relationship_guard`, `trg_bill_items_insert_guard` และ `trg_payments_relationship_guard` ใหม่โดยระบุ collation ของตัวแปรในทริกเกอร์อย่างชัดเจน จำเป็นเมื่อฐานถูกสร้างโดยผู้ให้บริการ (Railway) หรือ container ที่ใช้ `MYSQL_DATABASE` เพราะ collation ปริยายของฐานจะเป็น `utf8mb4_0900_ai_ci` ทำให้การออกบิลล้มด้วย error 1267 Migration นี้ไม่แก้ตาราง/คอลัมน์/ตรรกะ และปลอดภัยต่อการรันซ้ำ
15. รัน `database/migrations/014_line_platform.sql` หลัง `013` เพื่อเพิ่มทะเบียน OA การผูกหลายบัญชีต่อห้อง ผู้รับแจ้งเตือน และคิวแจ้งเตือน อ่าน [คู่มือ LINE](LINE_BINDING.md) ก่อนตั้งค่าบัญชีจริง
16. รัน `database/migrations/015_pending_occupancy_opening_readings.sql` หลัง `014` ขณะ web/worker/cron ยังหยุดอยู่ ห้ามใช้ `--force` Migration นี้อนุญาตค่าเปิดมิเตอร์ legacy ที่ยังไม่ทราบทั้งสองค่าเฉพาะกรณีไม่มีประวัติมิเตอร์หรือบิล และเพิ่ม guard ให้กรอกค่าจริงได้ครั้งเดียวโดยไม่แก้หลักฐานย้อนหลัง หากพบข้อมูลไม่ผ่าน preflight ให้กระทบยอดจากหลักฐานและคง maintenance ไว้ก่อนรันซ้ำ
17. Deploy source ปัจจุบันโดยยังปิด public traffic แล้วให้ Owner เข้าผ่านช่องทาง maintenance เพื่อ reissue credential ผู้พักเดิมที่ยังเข้าไม่ได้และบันทึก integration settings ส่วนคีย์ LINE และ Basic ID ใช้หน้า บัญชี LINE OA
18. ให้ production รัน `php scripts/check_requirements.php --db --strict --production` ด้วยบัญชี runtime แล้วรัน `php scripts/check_requirements.php --schema-audit` ด้วยบัญชี DBA/schema owner ชั่วคราว Canonical schema ต้องพบ 21 ตาราง, integrity triggers 23 รายการพร้อม body ตรง canonical และ CHECK อย่างน้อย 116 รายการ รวม named guard `chk_occupancies_opening_readings_v2` ของ migration `015` ที่มีนิยามตรงและเปิดการบังคับใช้
19. เริ่ม worker หลัง migration/deploy สำเร็จและตรวจว่า `notification_worker_heartbeats.heartbeat_at` เดินต่อเนื่อง ห้ามถือว่า web `/healthz.php` ที่ผ่านเพียงอย่างเดียวพิสูจน์ว่า worker ส่ง LINE ได้ เพราะ web readiness ตั้งใจไม่ผูกกับ liveness ของ service อื่น

ข้อมูล snapshot ของรายการเก่าที่ migration สร้างขึ้นเป็นการประกอบย้อนจากข้อมูลที่ยังมีอยู่: ค่าเช่าจะเลือกจาก occupancy ก่อนแล้วจึง fallback ไปค่าเช่าห้องปัจจุบัน ส่วนชื่อผู้พัก/รหัสห้องของบิลเก่าอาจไม่ใช่ค่าประวัติเดิมหากเคยแก้ไข จึงต้องตรวจเอกสารย้อนหลังหรือ export เดิมบน staging ก่อนเปิดใช้งานจริง

### การแก้ข้อมูลก่อนผ่าน migration 009

ก่อนรัน `009_occupancy_meter_baselines.sql` บน staging ให้ตรวจรายการมิเตอร์น้ำและไฟของเดือนที่ย้ายเข้าและหลักฐานส่งมอบห้อง เพราะ migration จะ backfill ค่าเปิดมิเตอร์ได้ต่อเมื่อพบข้อมูลทั้งสองชนิด หากค่าเปิดทั้งสองยังไม่ทราบและไม่มีประวัติมิเตอร์หรือบิล สามารถคงเป็นข้อมูลรอดำเนินการได้โดยไม่เดาค่าเป็นศูนย์ แต่ยังออกบิลหรือจดมิเตอร์ไม่ได้จนกว่าจะกรอกค่าจริง Migration จะหยุดก่อนติดตั้ง immutable trigger เมื่อพบอย่างใดอย่างหนึ่ง:

- `DORMITORY_009_REPAIR_ACTIVE_OPENING_READINGS_BEFORE_RERUN`: ค่าเปิดมิเตอร์ขาดเพียงค่าเดียว หรือขาดทั้งสองแต่มีประวัติมิเตอร์/บิลแล้ว
- `DORMITORY_009_REPAIR_OCCUPANCY_PERIOD_OVERLAPS_BEFORE_RERUN`: occupancy ของห้องหรือ resident เดียวกันทับ calendar month ซึ่งระบบรายเดือนไม่รองรับ
- `DORMITORY_009_REPAIR_RESIDENT_OCCUPANCY_STATES_BEFORE_RERUN`: สถานะ resident/occupancy/room/booking, moved-in booking ที่ไม่มี occupancy หรือค่าเช่า snapshot ไม่สอดคล้อง
- `DORMITORY_009_REPAIR_FINANCIAL_RELATIONSHIPS_BEFORE_RERUN`: ความสัมพันธ์ occupancy → bill → bill items → payment หรือ bill → notification ไม่ตรงกัน, meter snapshot ของบิลไม่ตรง ledger, หรือ paid bill ไม่มี verified payment ที่ยอด/ผู้พักตรงกันหนึ่งรายการ
- `DORMITORY_009_REPAIR_METER_OCCUPANCY_LINKS_BEFORE_RERUN`: meter reading ทับช่วงผู้พักแต่ไม่ผูก `occupancy_id` หรือผูกผิดห้อง/ช่วงวัน
- `DORMITORY_009_REPAIR_METER_CHAIN_BEFORE_RERUN`: เดือนแรกไม่ตรงค่าเปิด, มีเดือนขาดช่วง หรือ `previous_reading` ไม่ตรง `current_reading` ของเดือนก่อน

คอลัมน์ที่เพิ่มและค่าที่ backfill สำเร็จแล้วอาจคงอยู่ตามธรรมชาติของ MySQL DDL ให้คง maintenance window ไว้ กระทบยอดค่าจากหลักฐานจริง แก้เฉพาะแถวที่รายงานด้วย DBA แล้วรัน migration เดิมซ้ำ จากนั้นตรวจ:

```sql
SELECT id, room_id, move_in_date
FROM occupancies
WHERE status = 'active'
  AND (
    opening_water_reading IS NULL
    OR opening_electric_reading IS NULL
  );
```

ถ้าคำสั่งนี้พบแถว อย่าเดาค่าเป็น `0` หรือใช้ค่ามิเตอร์ปัจจุบันแทนค่าเปิดมิเตอร์ หลังรันถึง `015` ให้ใช้ `check_requirements.php --db` แยกข้อมูลรอดำเนินการออกจากข้อมูลไม่ถูกต้อง: กรณี active ที่ขาดทั้งสองและไม่มีประวัติสามารถกรอกค่าจริงครั้งเดียวผ่านหน้าผู้พักได้ ส่วนกรณีขาดค่าเดียวหรือมีประวัติแล้วต้องคง maintenance ไว้ กระทบยอดกับใบส่งมอบห้อง/ประวัติมิเตอร์/บิล/หลักฐานธนาคารบน staging และแก้ด้วย one-off repair ที่ผ่านการทบทวนและมีแผน rollback

Guard ของ `009` ตั้งใจรันขณะที่ trigger immutable จาก migration 002 ยังอยู่ จึงอาจปฏิเสธ `UPDATE` ที่ใช้ซ่อมข้อมูล legacy ห้ามแก้ด้วยการปิด `FOREIGN_KEY_CHECKS`/CHECK ทั้งระบบหรือเปิด traffic ค้างไว้ วิธี recovery ที่ควบคุมได้คือ:

1. ทำและทดสอบบน staging restore ก่อน ระบุ row, หลักฐานอ้างอิง, SQL ก่อน/หลัง และ trigger ที่ขวางเฉพาะรายการนั้นใน one-off repair ที่ผ่าน review
2. บน production ให้ปิด public write, worker และ cron ตลอดงาน สำรองอีกครั้ง ใช้ advisory maintenance lock และ drop เฉพาะ trigger immutable ที่ one-off repair ระบุ จากนั้นแก้เฉพาะแถวเป้าหมาย ห้ามใช้คำสั่งกว้างหรือเดาค่า
3. รัน `009_occupancy_meter_baselines.sql` ซ้ำจน preflight ทุกชุดผ่าน และอัปเกรดตามลำดับจนถึง `015` ก่อนเทียบ trigger กับ source ปัจจุบัน เฉพาะกรณีที่ one-off repair เคย drop trigger ที่ยังไม่ได้สร้างกลับ ให้ import `database/schema.sql` บน schema ที่ยืนยันแล้วว่า migrate ครบเพื่อ recreate canonical triggers ทั้ง 23 รายการ ขั้นนี้เป็น recovery exception ไม่ใช่วิธีอัปเกรด schema เก่า
4. รัน `--schema-audit` เพื่อเทียบ event/timing/table/body กับ canonical และรัน runtime production gate ก่อนปล่อย maintenance lock/เปิด traffic หากขั้นใดไม่ผ่านให้ restore backup หรือคงระบบปิดไว้

เมื่อ migration ผ่านแล้วห้าม `UPDATE occupancies`, bill หรือ payment แบบเฉพาะหน้า เพราะ trigger immutable ตั้งใจป้องกันการเปลี่ยนหลักฐานย้อนหลัง

จากนั้นต้องรัน `php scripts/check_requirements.php --db --strict --production` อีกครั้งบน production (staging ใช้ `--db --strict` ได้) ตัวตรวจจะหยุด deploy หากยังมี occupancy ของห้องหรือ resident เดียวกันทับรอบเดือน, lifecycle state/ค่าเช่า snapshot ของ resident/occupancy/room/booking ไม่สอดคล้อง, moved-in booking ไม่มี occupancy, ความสัมพันธ์ bill/items/payment/notification หรือ verified-payment state ไม่ตรงหลักฐาน, active occupancy ที่ค่าเปิดมิเตอร์ไม่ครบ, meter reading ที่ควรผูกกับ occupancy แต่ไม่ผูก/ผูกผิดห้องหรือผิดช่วงวันที่, chain มิเตอร์ขาดเดือน/ยอดก่อนหน้าไม่ตรง หรือผู้พัก active ไม่มีทั้ง password และ activation code ที่ยังไม่ถูกใช้/ยังไม่หมดอายุ กรณี credential ให้แก้ผ่าน Admin โดย reissue รหัสเปิดใช้งานและส่งให้เจ้าตัวผ่านช่องทางส่วนตัว

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

`selected_database` ต้องไม่เป็น `NULL`, version ต้องเป็น MySQL 8.0.16 ขึ้นไป และหลัง import ต้องมีตารางระบบ 21 ตาราง จากนั้นรัน:

```powershell
php scripts/check_requirements.php --db
```

อย่าใช้ MySQL `root` เป็น `DB_USERNAME` ของเว็บ อย่าเปิดพอร์ต MySQL สู่อินเทอร์เน็ต และอย่านำรหัสผ่านจริงไปใส่ในคำสั่งที่ถูกบันทึกลง shell history

## หลังเชื่อม SQL สำเร็จ

1. สร้างบัญชี Owner คนแรกตาม README
2. เข้าหน้า Admin → ตั้งค่า แล้วบันทึกอัตราค่าน้ำ ค่าไฟ และวันครบกำหนดจริง
3. กรอก PromptPay และผู้ให้บริการตรวจสลิปจากหน้า ตั้งค่า ส่วน Channel access token/Channel secret ให้กรอกที่หน้า บัญชี LINE OA ซึ่งจะดึงชื่อและ Basic ID ให้เอง ค่าลับจะถูกเข้ารหัสใน MySQL โดยใช้ key ที่ derive จาก `APP_KEY`; `APP_KEY` เองยังต้องอยู่ใน `.env`/secret manager และต้องตรงกันทุก web/worker/job instance
4. คัดลอก Webhook URL ของแต่ละบัญชีจากหน้า บัญชี LINE OA ไปใส่ใน LINE Developers Console เปิด **Use webhook** และ **Webhook redelivery** แล้วกด **Verify** โดย `APP_URL` ต้องเป็น HTTPS origin สาธารณะที่ตรงกับโดเมนจริง
5. เพิ่มห้องจริงจากหลังบ้าน; production ไม่มีห้องตัวอย่างอัตโนมัติ
6. รัน requirement checker อีกครั้งก่อนเปิดให้ผู้ใช้จริง ค่า LINE binding จะพร้อมเมื่อ Basic ID ถูกต้องและถอดรหัสได้ทั้ง Channel access token และ Channel secret

การผูก LINE แบบปัจจุบันให้ผู้พัก login แล้วกดปุ่มเพิ่มเพื่อนจาก Basic ID ที่ Owner บันทึกใน MySQL จากนั้นสร้างรหัส `BIND-` จากหน้าโปรไฟล์ รหัสมีข้อมูลสุ่ม 128 บิต อายุ 10 นาที แสดงครั้งเดียวและมี QR ให้ใช้อีกอุปกรณ์สแกน โดย `line_link_codes` เก็บเฉพาะ HMAC-SHA256 จาก `APP_KEY` ผู้พักส่งรหัสในแชตส่วนตัวกับ OA; webhook ตรวจ `X-Line-Signature`, lock resident/code/occupancy และผูก `source.userId` แบบ transaction หน้าผู้พักตรวจสถานะอัตโนมัติ รหัสหมดอายุ ใช้แล้ว หรือถูกเพิกถอนใช้ซ้ำไม่ได้ ห้ามคัดลอกรหัสลง ticket/log/audit

Requirement checker และ `/healthz.php` ตรวจได้เฉพาะ schema/configuration ไม่ได้พิสูจน์ LINE network, quota หรือ credential จริง ก่อน production ต้องทดสอบ **Verify**, reply หลังส่งรหัส `BIND-`, unlink/rebind และ push บิลบน staging ด้วย Channel access token/Channel secret จริง
