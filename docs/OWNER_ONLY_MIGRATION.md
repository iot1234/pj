# เจ้าของระบบและผู้ใช้งานเท่านั้น

รุ่นนี้มีบทบาทที่เข้าสู่ระบบได้สองประเภท: เจ้าของระบบ (`owner`) และผู้พัก (`resident`) ทุกงานหลังบ้านดำเนินการโดยเจ้าของ การสร้างเจ้าของใหม่ต้องเป็นการสร้างบัญชีโดยผู้มีสิทธิ์อย่างชัดเจน ระบบไม่ยกระดับแอดมินเดิมให้อัตโนมัติ ชื่อเทคนิค `admin_users`, `/admin` และ actor type `admin` ใน audit เดิมยังใช้เพื่อรักษา URL, foreign keys และหลักฐานย้อนหลัง

## ฐานข้อมูลใหม่

นำเข้า `database/install.sql` บนฐานว่าง หรือ `database/schema.sql` ตามด้วย `database/defaults.sql` หากใช้ชื่อฐานอื่น ไฟล์รวม migration ถึง `020` แล้ว ไม่ต้องนำเข้า migration ซ้ำ จากนั้นสร้างเจ้าของด้วย password ผ่าน stdin เช่น:

```powershell
$ownerPassword = Read-Host 'รหัสผ่านเจ้าของ' -MaskInput
$ownerPassword | php scripts/create_admin.php --username=owner --role=owner --password-stdin
Remove-Variable ownerPassword
```

`ADMIN_ROLE` หากกำหนดไว้ต้องเป็น `owner` เท่านั้น ห้ามใส่ password ลง command line หรือไฟล์ SQL ติดตั้ง

## อัปเกรดฐานเดิม

1. สำรองฐานและทดสอบ restore บนสำเนาฐานก่อน ปิด traffic และหยุด web, worker และ monthly billing cron ทุก instance
2. รัน migrations ก่อนหน้าให้ครบถึง `016` ตาม [SQL_SETUP](SQL_SETUP.md) และยืนยันว่ามีเจ้าของเดิมที่ active อย่างน้อยหนึ่งบัญชี หากมีแต่แอดมิน ให้สร้างบัญชี owner อย่างชัดเจนด้วยคำสั่งข้างต้นก่อนรัน `017`
3. นำเข้า `database/migrations/017_owner_only_access.sql` ด้วย DBA/schema owner ที่คงอยู่ ห้ามใช้ `--force` หรือ continue-on-error ข้อผิดพลาด `DORMITORY_017_CREATE_ACTIVE_OWNER_BEFORE_RETIRING_ADMINS` หมายถึงต้องสร้าง/เปิดใช้งานเจ้าของเดิมก่อน; preflight นี้หยุดก่อนเปลี่ยนข้อมูลและ schema
4. คง traffic ปิดแล้วรัน `018` → `019` → `020` ตาม [คู่มือรายวัน](DAILY_BOOKING.md) ก่อน deploy source ปัจจุบัน จากนั้นตรวจด้วย `php scripts/check_requirements.php --db --strict --production` ด้วยบัญชี runtime และ `php scripts/check_requirements.php --db --schema-audit` ด้วย DBA ชั่วคราว Canonical ปัจจุบันมี 34 ตาราง, 63 triggers, 155 CHECK constraints และ `/healthz.php` ปฏิเสธ schema ที่อัปเกรดไม่ครบ
5. ตรวจว่าเจ้าของและผู้พักเข้าสู่ระบบได้ และแอดมินเดิมเข้าไม่ได้ หากต้องการให้บุคคลเดิมเป็นเจ้าของ ให้เจ้าของสร้างบัญชี owner ใหม่ด้วยชื่อผู้ใช้ใหม่อย่างชัดเจน แล้วออกคำเชิญ LINE สำหรับเจ้าของใหม่ เปิด worker/cron/traffic หลังตรวจผ่าน

## ผลต่อข้อมูลเดิม

Migration เปลี่ยนเฉพาะบัญชีเดิมที่ `role='admin'`: ตั้ง `active=0`, เพิ่ม `auth_version` เพื่อยกเลิก session และตั้ง `retired_at` เป็นเวลา UTC แบบถาวร เก็บ username, password hash, ID, created_by และหลักฐาน audit/booking/meter/payment/LINE ที่อ้าง ID เดิมครบ แถวเก่าถูกเก็บเป็นประวัติและมี `role='owner'` ตาม enum ใหม่ แต่ `retired_at` และ enforced CHECK บังคับไม่ให้เปิดใช้งาน ส่วน trigger ป้องกันการลบหรือเปลี่ยน retirement marker และ backend ปฏิเสธการแก้ credential/ชื่อ/สิทธิ์ของบัญชีที่เลิกใช้แล้ว

เจ้าของเดิมที่ไม่ถูกเลิกใช้ไม่เปลี่ยน ID, credential หรือ session version และข้อมูลผู้พัก/ห้อง/บิล/การชำระไม่เปลี่ยน ผู้รับ LINE ที่ `is_owner=0` และคำเชิญที่สร้างโดยแอดมินที่เลิกใช้ถูกปิดและเพิกถอน invitation แม้คำเชิญเดิมเคยระบุเป็น OWNER โดยไม่ถูกย้ายเป็นเจ้าของ งานแจ้งเตือนของผู้รับเหล่านี้ที่ยัง pending/processing เปลี่ยนเป็น failed พร้อม `ADMIN_ROLE_RETIRED` และล้าง claim/lease ส่วนงาน sent และผู้รับที่เป็นเจ้าของซึ่งสร้างโดยบัญชีเจ้าของเดิมคงเดิม

ไฟล์ `017` รันซ้ำได้ในช่วง maintenance โดยไม่เพิ่ม auth_version หรือเปลี่ยน retirement timestamp ซ้ำ หากขาดกลางทางเพราะ MySQL DDL auto-commit ให้คงทุก process หยุดไว้ แก้เหตุที่แจ้งแล้วรันไฟล์เดิมซ้ำ การย้อนกลับต้อง restore backup และ source ที่เข้ากัน; ห้ามล้าง `retired_at` เพื่อคืนสิทธิ์แอดมิน

## ทดสอบบนฐานแยก

`tests/owner_only_migration_mysql.php` ใช้บัญชี schema owner บนฐานว่างชื่อ `appj_owner_schema_*` และต้องมี `APP_ENV=testing` เท่านั้น โดยรับ `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` จาก process environment ไม่โหลด `.env` หรือส่งข้อความ LINE ทดสอบฐานใหม่, preflight เจ้าของ, การคงข้อมูลย้อนหลัง, session version, ป้องกันคืนสิทธิ์, คิว LINE และ migration rerun
