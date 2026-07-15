# Security model และแนวทางปฏิบัติ

เอกสารนี้อธิบาย control ที่ระบบมีให้และสิ่งที่ผู้ deploy ต้องทำเพิ่ม ระบบจัดการข้อมูลส่วนบุคคล หลักฐานชำระเงิน และสิทธิ์ทางการเงิน จึงไม่ควรเปิดใช้งาน production ด้วยค่า local-development

## ขอบเขตความเชื่อถือ

- Browser ของ Guest/Resident/Admin ถือว่าไม่น่าเชื่อถือทั้งหมด รวมถึง hidden fields, total, room status, file name, MIME จาก browser และ client IP header
- MySQL, PHP host และ reverse proxy อยู่ใน trusted infrastructure แต่ต้องใช้บัญชี/สิทธิ์แยกกัน
- LINE, SlipOK และ EasySlip เป็น third party ที่อาจช้า ล่ม ส่งข้อมูลผิดรูป หรือถูกโจมตี ระบบจึงตรวจ response ก่อนเปลี่ยนสถานะทางการเงิน
- `.env`, `APP_KEY`, database backup (รวม ciphertext ใน `integration_settings`), `storage/private/slips` และ provider payload เป็นข้อมูลลับ ห้ามอยู่ใต้ public web root หรือ artifact ที่เผยแพร่

## Authentication และ authorization

- Admin ใช้ username/password ความยาว 12-200 ตัว ผู้เช่าใช้เบอร์โทรที่ normalize แล้วร่วมกับ PIN ตัวเลข 6-12 หลัก; PIN ที่ซ้ำทั้งชุดหรือลำดับพื้นฐานถูกปฏิเสธ
- password/PIN เก็บด้วย `password_hash()` โดยเลือก Argon2id เมื่อ runtime รองรับและ fallback เป็น bcrypt ไม่มี plaintext credential หรือ reusable seed hash ใน SQL
- login error เป็นข้อความรวมและใช้ dummy hash เพื่อลด username/phone enumeration พร้อม random delay
- rate limit เก็บใน MySQL และ lock ด้วย transactionทั้งต่อ IP และบัญชีที่มีจริง; bucket key เป็น HMAC จึงไม่เก็บ phone/username ตรง ๆ ส่วน username/phone ที่ไม่มีจริงหรือรูปแบบผิดจะรวมเป็น unknown bucket ต่อ IP เพื่อไม่ให้ผู้โจมตีสร้างแถวไม่จำกัด การ block ถูกแปลงเป็น generic credential failure หลัง dummy/actual verify และ delay เดียวกัน จึงไม่เป็น account-enumeration oracle และรหัสที่เดาถูกไม่ข้าม bucket ที่ block แล้ว
- session ID ถูก rotate เมื่อ login และล้างเมื่อ logout ใช้ strict mode, cookie only, HttpOnly, SameSite=Lax และ Secure เมื่อเปิด HTTPS; บังคับหมดอายุทั้งแบบ idle และอายุรวมตาม `SESSION_LIFETIME_SECONDS` และเริ่ม file session แบบ lazy เฉพาะเมื่อมี session ที่บันทึกอยู่หรือ login สำเร็จ Anonymous GET จึงไม่สร้างไฟล์ใหม่
- ทุก request ที่มี session จะตรวจ `active` และ `auth_version` กับฐานข้อมูล การปิดบัญชี เปลี่ยนรหัส/PIN หรือเพิ่ม auth version จึง revoke session เก่าได้
- Route guard แยก Guest, Resident, Admin และ owner; เฉพาะ owner จัดการบัญชี admin และเปลี่ยนค่า PromptPay/LINE/slip integrations ส่วน service ต้องกันการปิด/ลบ owner คนสุดท้าย
- Query ของผู้เช่าต้อง bind `resident_id` จาก session เสมอ ห้ามเชื่อ bill/resident/room ID จาก URL เพียงอย่างเดียว

ข้อจำกัด: ระบบใช้ PIN ไม่ได้มี OTP/MFA ใน scope FR-01–FR-16 เจ้าของระบบควรแจก PIN ผ่านช่องทางที่พิสูจน์ตัวบุคคล บังคับเปลี่ยนเมื่อสงสัยว่ารั่ว และพิจารณาเพิ่ม MFA เป็นโครงการแยกหากระดับความเสี่ยงต้องการ

## CSRF, origin และ input

- mutation ทุก JSON/multipart API ต้องมี `Origin` หรือ `Referer` ตรงกับ normalized `APP_URL` และ `X-CSRF-Token`: ผู้ใช้ที่มี session ต้องใช้ token ที่ผูกกับ session ส่วน Guest/login/public booking ใช้ token แบบ stateless อายุไม่เกิน 2 ชั่วโมงที่ลงลายเซ็น HMAC ด้วย `APP_KEY` จึงไม่ต้องสร้าง anonymous session
- production ต้องกำหนด `APP_URL` แบบ HTTPS ให้ตรง origin จริง ห้ามอนุญาต wildcard origin
- request body จำกัดประมาณ 5 MiB; JSON ต้อง parse ได้และมี object shape
- validator ใช้ allowlist field, type, length, enum, phone/date/period และ decimal scale; field ที่ไม่รู้จักต้องถูกปฏิเสธเพื่อกัน mass assignment
- PDO ใช้ native prepared statements (`ATTR_EMULATE_PREPARES=false`) ห้ามต่อค่าจากผู้ใช้เข้า SQL ส่วน dynamic list ต้องสร้างเฉพาะจำนวน placeholder และ bind ทุกค่า
- response JSON mutation ตั้ง `Cache-Control: no-store`; error production ไม่ควรส่ง stack trace, SQL หรือ secret กลับ browser

## XSS และ browser headers

- template ต้อง escape ข้อมูลทุกจุดตาม context ด้วย HTML escaping; URL/attribute/JSON ต้องใช้ encoder ที่เหมาะกับ context นั้น
- application ส่ง CSP แบบ self-only, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'` พร้อม `nosniff`, `DENY`, Referrer/Permissions/COOP/CORP headers
- production ส่ง HSTS; ควรตั้งซ้ำที่ reverse proxy หลังยืนยันว่า domain และ subdomain ใช้ HTTPS ทั้งหมด
- ห้ามเพิ่ม inline script, remote CDN หรือ `unsafe-inline` โดยไม่ทบทวน CSP และ supply-chain risk

## Database integrity และ concurrency

MySQL ต้องเป็น 8.0.16+ เพื่อให้ `CHECK` ทำงานจริง ใช้ InnoDB/utf8mb4, strict SQL mode และเก็บ timestamp เป็น UTC; PHP แปลงเพื่อแสดงผล `Asia/Bangkok`

- active booking ใช้ generated `active_room_id` + unique index เพื่อกัน booking pending/confirmed ซ้ำในห้องเดียว
- active occupancy ใช้ generated room/resident IDs + unique indexes เพื่อกันหนึ่งห้องหรือหนึ่งผู้เช่ามี occupancy active ซ้ำ
- booking/move-in/bill/payment/admin-owner flows ใช้ transaction และ `SELECT ... FOR UPDATE`; duplicate-key เป็น conflict ไม่ใช่ retry แบบ blind
- meter กำหนดหนึ่งแถวต่อห้อง/ประเภท/เดือน, current ≥ previous และ units เท่ากับผลต่างที่ปัด 2 ตำแหน่ง
- booking เก็บ snapshot ค่าเช่าขณะจอง และ bill เก็บ snapshot ชื่อผู้พัก รหัสห้อง ค่าเช่า มิเตอร์ rate และ amount เพื่อไม่ให้การแก้ข้อมูลปัจจุบันเปลี่ยนประวัติย้อนหลัง; total คำนวณฝั่ง server และ unique ต่อ occupancy/period
- integrity triggers 15 รายการป้องกัน snapshot/หลักฐานเปลี่ยนสถานะ/ความสัมพันธ์ข้ามตาราง, payment ที่สรุปแล้วและการลบ payment, bill/bill items, notification outbox กับ audit logs; ผู้ใช้ฐานข้อมูล runtime ไม่ควรมีสิทธิ์ `DELETE`, `DROP`, `ALTER`, `TRIGGER` หรือปิด constraint
- payment transaction reference และ slip HMAC เป็น global unique; generated unique key กัน payment pending/verified หลายรายการต่อ bill และ verification lease/token กัน worker/request หลายตัวสรุปรายการเดียวกันพร้อมกัน
- outbox unique ต่อ `(bill_id,purpose)` และ `retry_key`; key เป็น UUID v4 lowercase ที่เก็บเดิมตลอด retry
- `integration_settings` ใช้ singleton `id=1`, FK `updated_by` และ CHECK จำกัดรูปแบบ/range; runtime web/worker มีเฉพาะ `SELECT`, `INSERT`, `UPDATE` ไม่ต้องมี `DELETE` หรือสิทธิ์ DDL

constraint ไม่สามารถกัน active booking กับ active occupancy ที่อยู่คนละตารางพร้อมกันได้ด้วย unique index ตัวเดียว service จึงต้อง lock ห้องและตรวจทั้งสองตารางใน transaction การแก้ business flow ต้องรักษา invariant นี้

## Slip upload และการเปลี่ยนสถานะ paid

- รับเฉพาะ JPEG/PNG/WebP ไม่เกิน 4 MiB; ตรวจ MIME ด้วย `finfo`, parse image จริง, จำกัด 4,096 px ต่อด้านและ 8 ล้านพิกเซล พร้อมตรวจ memory budget ก่อน decode เพื่อกัน decompression bomb/หน่วยความจำหมด
- ไม่ใช้ชื่อไฟล์เดิม ไฟล์ตั้งชื่อสุ่ม เก็บใต้ `storage/private/slips/YYYY/MM` ด้วย permission จำกัดและไม่มี public download route; Admin เปิดหลักฐานได้เฉพาะ endpoint ที่ตรวจ session/role, rate limit และ audit
- สร้าง HMAC-SHA256 ของเนื้อหาโดยใช้ `APP_KEY` เพื่อจับการอัปโหลดไฟล์เดิม โดยไม่ใช้ checksum ธรรมดาที่เดา/สร้างจากไฟล์สาธารณะได้
- ก่อนใช้หลักฐานเดิมเพื่อตรวจซ้ำหรือส่งให้ Admin ระบบ canonicalize path ให้อยู่ใต้ slip root แล้วตรวจไฟล์จริง, MIME, ขนาด, มิติ/จำนวนพิกเซล และ HMAC เทียบฐานข้อมูลอีกครั้ง หากไม่ตรงจะหยุดแบบ fail-closed
- ผู้ให้บริการต้องคืน transaction reference ที่ valid และ unique ยอดต้องตรง bill ถึง 1 สตางค์ และ receiver reference ต้องตรงเลขบัญชีปลายทาง/เลขท้าย 6–20 หลักที่ Owner ตั้งไว้
- เวลาโอนต้องไม่ก่อนเวลาสร้างบิลและไม่อยู่ในอนาคตเกินช่วงเผื่อเวลาที่ Owner ตั้งไว้ (0–3,600 วินาที); เวลา provider ที่หาย/parse ไม่ได้ต้องคง pending ไม่ใช่ paid
- ระบบ reserve payment พร้อม verification lease ใน transaction ก่อนเรียก provider แล้วจึง lock bill/payment เพื่อ finalize payment + bill paid ใน transaction เดียว; token ป้องกันผลตอบกลับที่หมดอายุทับผลตรวจปัจจุบัน และ browser ส่งยอด/สถานะ paid เองไม่ได้
- provider unavailable/response malformed/ผลที่สรุปไม่ได้ รวมถึง receiver ที่ provider ยังยืนยันกับบัญชีที่ Owner ตั้งไว้ไม่ได้ ต้องคง `pending` เพื่อให้แก้ configuration แล้วตรวจหลักฐานเดิมซ้ำได้ ส่วนยอดไม่ตรงและเวลาที่ผิดเงื่อนไขเป็น `rejected` โดยผลตรวจอัตโนมัติ ห้าม fail-open หรือเปิดปุ่มบังคับให้เป็น paid
- Admin ตรวจซ้ำได้เฉพาะรายการ `pending` โดยใช้ verification lease/token และจำกัด 20 ครั้ง; การปิดรายการต้องรอให้ lease หมดและระบุเหตุผล ระบบเปลี่ยนเป็น `rejected` เพื่อปล่อยให้ผู้พักส่งหลักฐานใหม่ ไม่ได้เปลี่ยนบิลเป็น paid
- provider payload อาจมีข้อมูลธนาคาร ให้จำกัด retention, จำกัดสิทธิ์ query และ redact token/image ก่อนบันทึก ห้ามนำ payload เต็มไปเขียน application log

## Outbound integrations และ SSRF

- URL ถูก hard-code เป็น HTTPS เท่านั้น: LINE `api.line.me`, SlipOK `api.slipok.com`, EasySlip `api.easyslip.com` ไม่มี environment สำหรับเปลี่ยน scheme/host
- cURL จำกัด protocol เป็น HTTPS, ปิด redirect, connect timeout 5 วินาที, total timeout จำกัด, จำกัดขนาด/depth response และ reject malformed JSON
- secret ส่งใน header ไป host ที่กำหนดเท่านั้นและต้องไม่อยู่ใน error/audit/browser response
- LINE ใช้ UUID v4 ที่บันทึกใน outbox เป็น `X-Line-Retry-Key` เดิมทุก retry; HTTP 409 หมายถึง request เดิมได้รับแล้วและต้อง finalize เป็น sent ไม่ส่งใหม่ด้วย key ใหม่
- outbox ใช้ `FOR UPDATE SKIP LOCKED`, processing timeout recovery, exponential backoff และ maximum attempts เพื่อลดทั้ง duplicate และ retry storm

## การปกป้อง integration settings

- PromptPay/LINE/SlipOK/EasySlip เป็น operational settings ใน MySQL ไม่ใช่ environment settings และไม่มี environment fallback; Owner เปลี่ยนค่าจาก Admin → ตั้งค่า ส่วน Admin ทั่วไปอ่านได้เฉพาะค่าปกติและสถานะความพร้อม
- LINE Channel access token, SlipOK API key และ EasySlip API key เข้ารหัสแบบ AES-256-GCM; key ขนาด 256 บิต derive จาก `APP_KEY` ด้วย HKDF-SHA256 และใช้ nonce สุ่ม 12 bytes/tag 16 bytes
- AAD มี namespace/version และชื่อ field จึงย้าย ciphertext ที่ valid ไปอีกคอลัมน์ไม่ได้โดยไม่ทำให้ authentication fail; หาก payload, key หรือ AAD ไม่ตรง ระบบปฏิเสธการถอดรหัสด้วย error เดียวกัน
- API หลังบ้านไม่คืน plaintext หรือ ciphertext ของ credential แต่คืนเฉพาะ `*_configured` และ hint แบบ `********` ตามด้วยท้ายค่าไม่เกิน 4 ตัว รวมทั้ง readiness ที่ไม่เผยค่า secret
- update แบบ partial ที่ไม่มี field, ส่ง `null`, ว่าง หรือมีแต่ whitespace จะเก็บ secret เดิม การลบต้องส่ง `*_clear=true` โดยชัดเจน และ request ที่ทั้งตั้งค่าใหม่กับ clear พร้อมกันถูกปฏิเสธ
- web และ worker query `integration_settings` ตอนใช้งาน จึงรับค่าที่ Owner บันทึกใน request/รอบ worker ถัดไปโดยไม่ restart; ทุก instance ต้องใช้ `APP_KEY` เดียวกัน

## Secrets และ deployment

- `.env` ถูก ignore จาก Git และ Docker build context แต่ operator ต้องตรวจ secret scanning ใน CI, image เก่า และ history ด้วย การเพิ่ม ignore file ไม่ลบ secret ที่เคย commit/build
- `APP_KEY`, `APP_URL`, DB credential และ admin bootstrap password เป็น infrastructure configuration ที่ต้องมาจาก environment/secret manager หรือไฟล์ permission จำกัด; ห้ามย้าย `APP_KEY` เข้า `integration_settings` เพราะต้องใช้ถอดรหัสตารางนั้น
- LINE token และ SlipOK/EasySlip key ต้องกรอกผ่าน Owner UI และเก็บเข้ารหัสใน MySQL ไม่ควรซ้ำไว้ใน `.env`, command line, log หรือ audit payload
- รหัสผ่าน Owner bootstrap เป็น input ชั่วคราวของ one-shot process แนะนำ `scripts/create_admin.php --password-stdin` เพื่อไม่ให้ปรากฏใน command line; Compose ไม่ส่งรหัสนี้หรือ `DB_ROOT_PASSWORD` ให้ app/worker ระยะยาว
- `DB_ROOT_PASSWORD` ใช้เฉพาะ database service ของ Docker; XAMPP/Laragon ต้องเว้นว่าง/ลบจาก runtime `.env` หลัง DBA สร้าง user แล้ว
- แยก MySQL migration user ออกจาก runtime user; runtime มีเฉพาะ global `USAGE` และ `SELECT`/`INSERT`/`UPDATE` บน database ระบบ ไม่มี `DELETE` หรือสิทธิ์ DDL
- DocumentRoot ต้องเป็น `public/`; Apache config ปฏิเสธ root/storage/dotfiles และปิด directory listing/server signature
- production ใช้ TLS ที่ reverse proxy, `FORCE_HTTPS=true`, `APP_DEBUG=false`; ตั้ง `TRUSTED_PROXIES` เฉพาะ IP proxy ที่ควบคุมเอง และป้องกัน client ต่อ PHP host โดยตรง
- Compose bind พอร์ต HTTP ของแอปกับ loopback (`APP_BIND=127.0.0.1`) เป็นค่าเริ่มต้น ให้ reverse proxy เป็น public ingress ห้ามเปลี่ยนเป็น `0.0.0.0` โดยไม่มี firewall/network policy และการทบทวน exposure
- container เปิด `no-new-privileges`; ควรเพิ่ม network policy, read-only root filesystem/secret mount และ resource limits ตาม platform ที่ deploy

การหมุน `APP_KEY` ทำให้ integration credential ที่เข้ารหัสด้วย key เดิมถอดไม่ได้ทันที และไม่ได้ invalidate PHP session file โดยอัตโนมัติ จึงต้องทำเป็น migration ที่ทดสอบแล้ว ทางเลือกที่ไม่ต้องสร้างเครื่องมือ re-encrypt คือใช้ key เดิมเปลี่ยน provider เป็น `none` และ explicit-clear credential ทั้งหมดก่อนเปลี่ยน key จากนั้นตั้ง `APP_KEY` ใหม่ให้ web/worker พร้อมกันและกรอก credential ใหม่ หากเหตุการณ์ต้องบังคับ logout ให้ล้าง server-side session store และเพิ่ม `auth_version` พร้อมวางแผนผลต่อ rate-limit bucket, slip HMAC และ retry เป็น incident ที่ตรวจสอบได้

## Logs, audit และข้อมูลส่วนบุคคล

- audit เก็บ actor/action/entity/request ID/IP/user-agent และ redact key ที่สื่อถึง password, PIN, token, secret, authorization, slip
- audit table มี trigger ห้าม update/delete แต่ DBA ยังเปลี่ยนหรือลบได้ ควรส่งสำเนาไป append-only log store ภายนอกสำหรับระบบที่ต้องการหลักฐานสูง
- ห้าม log request body ของ login, `.env`, Authorization header, image/base64, PIN/password หรือ provider secret
- จำกัดผู้ที่อ่าน phone/email/LINE ID/slip/provider payload และกำหนด retention ตามวัตถุประสงค์และกฎหมายคุ้มครองข้อมูลส่วนบุคคล
- schedule ให้บัญชี DBA/maintenance ลบ `rate_limits` ที่ `updated_at` เก่ากว่านโยบาย (ตัวอย่าง 30 วัน) โดยไม่ให้สิทธิ์ `DELETE` แก่ runtime; กำหนด capacity/retention ของ audit แยกกันเพราะ audit มี append-only trigger และต้อง archive/ลบผ่าน migration ที่ควบคุม
- monitor อย่างน้อย: login fail/rate-limit surge, owner/admin change, booking conflict, bill generation failure, duplicate transaction, receiver mismatch, provider unavailable และ LINE terminal failure

## Backup, restore และ incident response

- สำรอง MySQL และ `storage/private/slips` แบบเข้ารหัสโดย snapshot ที่สัมพันธ์กัน เก็บนอก host และจำกัดผู้ถือ key; backup ฐานข้อมูลอย่างเดียวถอด integration secret ไม่ได้หากไม่มี `APP_KEY` รุ่นเดียวกัน
- ทดสอบ restore เป็นรอบ รวม foreign keys/triggers/generated indexes, owner login, bill snapshot, slip path, integration ciphertext และการกู้ `APP_KEY` จาก secret manager แยกต่างหากโดยไม่พิมพ์ secret
- กำหนด RPO/RTO, retention และวิธีลบข้อมูลตามนโยบาย ห้ามใช้ demo reset (`docker compose down -v`) บน production
- เมื่อสงสัย secret รั่ว: ปิดการเข้าถึง, เก็บหลักฐาน, rotate key/token/password ที่เกี่ยวข้อง, revoke sessions ด้วย auth version, ตรวจ audit/provider dashboard และ reconcile payment/LINE ก่อนกลับมาเปิด
- เมื่อ provider ล่ม ให้ payment คง pending และแจ้งเตือนผู้ดูแล ห้ามเปลี่ยน paid/rejected ด้วยปุ่ม manual หรือแก้ DB โดยไม่มีหลักฐานและกระบวนการอนุมัติที่แยกจากระบบ

## Checklist ก่อนเปิด production

- [ ] `php scripts/check_requirements.php --production` ผ่าน; Docker ต้องผ่านทั้ง service `app` และ `worker` ตาม README
- [ ] ฐานข้อมูลใหม่ import `schema.sql` + `defaults.sql` ครบ (`demo.sql` ใช้ได้เฉพาะ local แบบ optional และฐานใหม่ไม่ต้องรัน migration) หรือฐานข้อมูลเดิมสำรองแล้ว รัน `001_integration_settings.sql` เมื่อจำเป็น → `002_operational_hardening.sql` หนึ่งครั้ง → `003_append_only_guards.sql` (รันซ้ำได้) พร้อมรัน `--db --strict` ด้วยบัญชี runtime และ `--schema-audit` ด้วยบัญชี schema owner ที่คงอยู่เพื่อตรวจ integrity triggers 15 รายการ; ห้ามลบ trigger `DEFINER` หลังติดตั้ง
- [ ] HTTPS/HSTS/CSP/security headers ตรวจจากภายนอกแล้ว
- [ ] `.env`, source และ `storage/private` เปิดผ่าน URL ไม่ได้
- [ ] ไม่มี default credential, สร้าง Owner ผ่าน `--password-stdin`/secret store และลบตัวแปรรหัสผ่านชั่วคราวหลัง bootstrap
- [ ] owner คนแรก login ได้ และ role/IDOR/CSRF/rate-limit negative tests ผ่าน
- [ ] Owner ตั้ง integration ได้, Admin ทั่วไปแก้ไม่ได้, API ไม่คืน secret, ช่องว่างเก็บค่าเดิม, explicit clear ลบจริง และ web/worker เห็นค่ารอบถัดไปโดยไม่ restart
- [ ] ทดลอง booking race, move-in race, duplicate bill และ duplicate slip/transaction
- [ ] ทดสอบ LINE retry ด้วย key เดิม รวมกรณี HTTP 409
- [ ] ทดสอบ SlipOK/EasySlip ด้วย amount mismatch, receiver mismatch, duplicate และ timeout รวมเปิดดูหลักฐาน, แก้ค่า receiver แล้ว retry รายการ pending และปิดรายการหลัง verification lease หมด
- [ ] backup + restore drill ผ่าน และมีผู้รับผิดชอบ alert/incident ชัดเจน
