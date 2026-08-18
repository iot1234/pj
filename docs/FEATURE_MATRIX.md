# Feature matrix: FR-01 ถึง FR-16

เอกสารนี้เป็นขอบเขตอ้างอิงของ PHP/MySQL rewrite ฟีเจอร์ที่ไม่อยู่ใน FR-01–FR-16 ถือว่าไม่อยู่ในงาน แม้ระบบเดิมจะเคยมี

## Actor และสิทธิ์

| Actor | เข้าใช้ | ขอบเขต |
|---|---|---|
| Guest | ไม่ต้อง login | ดูห้องว่างและส่งจอง |
| Resident | เบอร์โทรที่ผูกกับผู้พัก/ห้อง active + password; ครั้งแรกใช้ activation code เพื่อตั้ง password | ดู/แก้ชื่อและ email ของตน ดูบิลของตน เปิด QR และส่งสลิปของตน |
| Admin | username + password | ห้อง การจอง ผู้เช่า มิเตอร์ บิล LINE และการชำระ |
| Owner | Admin role `owner` | สิทธิ์ Admin ทั้งหมด จัดการบัญชีผู้ดูแล และเปลี่ยนค่า PromptPay/LINE/SlipOK/EasySlip |

สถานะห้องไม่ใช่ field ที่แก้ตรง ๆ แต่คำนวณตามลำดับ: มี `occupancy active` = `occupied`; ไม่เช่นนั้นมี booking `pending/confirmed` = `reserved`; นอกนั้น = `available`

Resident login ต้องใช้เบอร์ของผู้พัก active ร่วมกับ password ไม่มี trusted-device bypass และ session หมดอายุเมื่อ idle 15 นาทีหรืออายุรวม 1 ชั่วโมง ครั้งแรกใช้ activation code แบบครั้งเดียวที่ผู้ดูแลออกให้เพื่อตั้ง password; ระบบเก็บ code เป็น HMAC เท่านั้น การออกใหม่ revoke password/เซสชันเดิม บัญชี Admin/Owner ใช้ username/password แยกกัน

## Requirement coverage

| FR | ความต้องการจาก `1.txt` | หน้า/API | ตารางและกฎหลัก | Acceptance ที่ต้องผ่าน |
|---|---|---|---|---|
| FR-01 | Guest ดูห้องว่างพร้อมประเภท ราคา สิ่งอำนวยความสะดวก รูป | `/`, `GET /api/public/rooms` | `rooms`; query คืนเฉพาะ derived `available` และไม่คืน soft-deleted room | ห้อง reserved/occupied/deleted ไม่ปรากฏ; amenities เป็น array และ image URL ไม่อ่าน path จากผู้ใช้ |
| FR-02 | Guest จองด้วยชื่อ/เบอร์ แล้วห้องเป็น “จองแล้ว” รอ Admin | `POST /api/public/bookings` | `bookings`; normalized Thai phone, idempotency key, generated unique active room/phone, IP/phone rate limit, hold timeout จาก DB clock | request ซ้ำด้วย key เดิมคืน 200 โดยไม่สร้างรายการ/audit ซ้ำ; ห้องหรือเบอร์เดียวมี pending/confirmed ได้หนึ่งรายการ; invalid/unavailable room ไม่กิน successful-booking quota; pending หมดอายุคืนห้องและ replay ต้องใช้ key ใหม่ ไม่แจ้งว่าสำเร็จ |
| FR-03 | Resident login ด้วยเบอร์และ credential ที่ผูกห้อง | `/resident/login`, `POST /api/auth/resident/login` `{phone,credential,new_password?}`, logout/me | `residents`, active `occupancies`; password hash, expiring one-time activation HMAC, normalized phone, layered IP/account/source rate limits, generic error, session rotation, ไม่มี resident trusted-device bypass | ครั้งแรกใช้ activation code พร้อมตั้ง password ที่แข็งแรง; code ถูกใช้ซ้ำ/หมดอายุไม่ได้; ครั้งถัดไปใช้ password; malformed/inactive/ไม่มีห้อง/credential ผิดตอบ error รวม; field PIN/field เกินถูกปฏิเสธ; session idle 15 นาที/absolute 1 ชั่วโมง |
| FR-04 | Resident ดูประวัติบิลย้อนหลังและสถานะ | `/resident`, `GET /api/resident/bills`, `GET /api/resident/bills/{id}` | `bills`, `bill_items`, `payments`; query bind resident จาก session | เห็นเฉพาะบิลตนเอง เรียงย้อนหลัง แสดง pending/paid และ payment ล่าสุด; เปิด ID ของคนอื่นได้ 404/403 |
| FR-05 | Resident ดูและแก้ข้อมูลส่วนตัว | `GET|PUT /api/resident/profile`, `POST /api/resident/profile/line/code`, `POST .../line/unlink`; Admin ใช้ `PUT /api/admin/residents/{id}` และ `POST .../{id}/access/reissue` | `residents`, `line_link_codes`; ชื่อ/email แก้ผ่าน allowlist, รหัส LINE `BIND-` มีข้อมูลสุ่ม 128 บิต อายุ 10 นาที เก็บเฉพาะ HMAC, เบอร์เป็น login identity; ไม่มี PIN | Resident แก้ชื่อ/email ได้; ส่งรหัสในแชตส่วนตัวกับ OA แล้ว webhook ผูก `source.userId` ภายใต้ transaction/row lock; รหัสหมดอายุ/ใช้ซ้ำ/ผู้พัก inactive ถูกปฏิเสธ; LINE ส่งบิลได้เมื่อ audit ล่าสุดยืนยัน ID ปัจจุบันเท่านั้น; legacy ID fail-closed; Admin เปลี่ยนเบอร์หรือ reissue access แล้ว revoke password/session และ pending bind code เดิม |
| FR-06 | เพิ่ม ลบ แก้บัญชีผู้ดูแล | `GET|POST /api/admin/users`, `PUT|DELETE /api/admin/users/{id}` | `admin_users`; owner-only, unique username, password hash, auth version | Admin ธรรมดาถูกปฏิเสธ; ปิด owner คนสุดท้ายไม่ได้; เปลี่ยน password/active/role revoke session เก่า |
| FR-07 | เพิ่ม ลบ แก้ข้อมูลห้อง | `GET|POST /api/admin/rooms`, `PUT|DELETE /api/admin/rooms/{id}` | `rooms`; unique room code, JSON amenities, soft delete | validate floor/rent/type/amenities; ห้องที่มี booking/occupancy active ลบไม่ได้; public ไม่เห็น deleted |
| FR-08 | แสดง Available/Occupied/Reserved | Admin room list และ public room list | derived จาก `rooms` + active `bookings` + active `occupancies` | precedence occupied > reserved > available ถูกต้อง และไม่มี endpoint รับ status จาก client |
| FR-09 | ยืนยัน/ยกเลิก booking และย้ายเข้าเป็น occupied | `GET /api/admin/bookings`, `POST .../{id}/confirm`, `/cancel`, `/move-in` `{email,move_in_date,opening_water_reading,opening_electric_reading,reuse_resident_id?}` | `bookings`, `residents`, `occupancies`; transaction + room/booking/resident row locks + state checks; canonical SHA-256 ของคำขอย้ายเข้า; insert triggers บังคับ booking เริ่ม pending และ occupancy เริ่ม active/ตรง moved-in booking/ค่าเช่า | pending→confirmed/cancelled; confirmed→moved_in สร้าง/ผูก resident+occupancy และค่าเปิดมิเตอร์; race/replay คำขอเดิมยังผ่านแม้แก้ email โปรไฟล์ภายหลัง แต่ payload ที่เปลี่ยนถูกปฏิเสธและไม่สร้าง occupancy/code ใหม่; ปฏิเสธการย้ายเข้าในเดือนที่ห้องหรือ resident มี occupancy เดิม หรือห้องมี meter reading อยู่ก่อนเพื่อไม่สร้าง occupancy ที่ออกบิลไม่ได้ |
| FR-10 | Admin ดูรายละเอียดผู้เช่าปัจจุบันของแต่ละห้อง พร้อมงานดูแลวงจรผู้พัก | `GET /api/admin/residents`, `PUT /api/admin/residents/{id}`, `POST .../{id}/access/reissue`, `POST .../{id}/move-out` | active `occupancies` join `residents`, `rooms`; transaction + row lock + `auth_version`; activation code เก็บเฉพาะ HMAC | แก้ข้อมูลที่ยืนยันแล้วและเปลี่ยนเบอร์/reissue access พร้อม revoke password/session; ย้ายออกได้เมื่อมีบิลครบทุกเดือนตั้งแต่ย้ายเข้าถึงย้ายออก ชำระแล้วทั้งหมด ไม่มีบิลเดือนหลังวันที่ย้าย จากนั้นปิด occupancy/ผู้พัก ล้าง LINE/credential เดิม และคืนห้องเป็นว่างอย่างเป็นชุดเดียว |
| FR-11 | บันทึกมิเตอร์น้ำ/ไฟรายห้องรายเดือน | `GET /api/admin/meters?period=YYYY-MM`, `POST /api/admin/meters` | `meter_readings`; ผูก `occupancy_id`, unique room/type/period, decimal 2 ตำแหน่ง, trigger ตรวจห้อง/ช่วง occupancy; readiness ตรวจ opening baseline และ chain เดือนต่อเดือน | เดือน/ห้องเดียวมี water/electric ได้ประเภทละหนึ่งแถว; occupied room ต้องผูก occupancy ที่ถูกต้อง; input ติดลบ/ย้อน/เกิน 9,999,999 ถูกปฏิเสธ; usage เกิน 10,000 หน่วยรวบรวมเตือนทุกประเภทก่อนยืนยัน; chain ขาดเดือน/ยอดก่อนหน้าไม่ตรงทำให้ deploy gate ล้มเหลว และแก้หลังออกบิลไม่ได้ |
| FR-12 | ดึงค่าก่อนหน้าและคำนวณหน่วยอัตโนมัติ | API มิเตอร์เดียวกับ FR-11 | previous reading จากแถวล่าสุดใน occupancy เดียวกัน หรือค่าเปิดมิเตอร์ของ occupancy; DB CHECK `units=current-previous` | รอบแรกใช้ opening reading เป็น previous; รอบถัดไปใช้ current ล่าสุดของ occupancy เดียวกัน ไม่ดึงค่าผู้เช่ารายก่อน; client กำหนด previous/units เองไม่ได้ |
| FR-13 | ออกบิลหลายห้อง รวมเช่า น้ำ ไฟ อื่น ๆ | `POST /api/admin/bills/preview`, `POST /api/admin/bills/bulk`, `GET /api/admin/bills` | `billing_settings`, `bills`, `bill_items`; immutable snapshots, unique occupancy/period, HMAC preview token; trigger บังคับบิลเริ่ม pending และค่าเช่า/มิเตอร์ตรง occupancy ledger | preview แจ้งห้องขาดมิเตอร์; bulk ต้องใช้ token อายุ 5 นาทีที่ผูกยอด/ผู้พัก/มิเตอร์และหยุดเมื่อข้อมูลเปลี่ยน; transaction/idempotent; direct paid insert ถูกปฏิเสธและ total คำนวณ server ตรง snapshot 2 ตำแหน่ง |
| FR-14 | ส่งบิล LINE รายห้อง/พร้อมกัน | `POST /api/admin/bills/{id}/line`, `POST /api/admin/bills/line-bulk` | `notification_outbox`, `notification_worker_heartbeats`, `integration_settings`, append-only `audit_logs`; token เข้ารหัส, verified recipient HMAC, unique bill/purpose, stable UUID v4 retry key, claim token/lease fencing, provider request IDs, backoff | ไม่มีหรือยังไม่ยืนยัน LINE ID ถูก skip/แจ้งชัด; บิลที่สลิปล่าสุด pending/verified จะไม่เข้าคิว และ worker ตรวจซ้ำภายใต้ลำดับ lock bill → payment → outbox ก่อน network call; stale worker สรุป claim ของรายอื่นไม่ได้; retry ใช้ payload/ผู้รับ/`X-Line-Retry-Key` เดิม; HTTP 409 ถือว่าได้รับแล้วเฉพาะเมื่อมี `x-line-accepted-request-id`, ส่วน 408/429/5xx retry; heartbeat/terminal failure/request IDs ใช้ monitor และ reconcile |
| FR-15 | สร้าง QR พร้อมเพย์ตามยอดบิล | `GET /api/resident/bills/{id}/promptpay` | `bills.total_amount` snapshot + PromptPay target ใน `integration_settings`; EMV CRC | QR amount มาจากบิลของ resident ที่ login เท่านั้น; endpoint เปิดเมื่อ PromptPay และระบบตรวจสลิปพร้อมและไม่มี payment สถานะ pending/verified; target/amount invalid ถูกปฏิเสธ; CRC ถูกต้อง |
| FR-16 | ตรวจสลิป SlipOK/EasySlip เทียบยอด/ปลายทาง แล้ว paid | `POST /api/resident/bills/{id}/slip`; Admin ใช้ paginated `GET /api/admin/payments`, `GET .../{id}/slip`, `POST .../{id}/retry`, `POST .../{id}/close` | `payments`, `integration_settings`, private slip storage; API key เข้ารหัส, HMAC/transaction unique; reserve + verification lease ก่อนเรียก provider และ finalize ด้วย bill lock | JPEG/PNG/WebP ไม่เกิน 4 MiB/4,096 px/8 MP; pending recovery เรียงก่อนและแบ่งหน้า; amount/receiver/transaction/เวลาโอน/ประเทศและสกุล THB ถูกต้องจึง verified+paid; timeout/ผลไม่ชัดเจน/ตั้งค่าผู้รับไม่ครบหรือผู้รับไม่ตรงคง pending เพื่อแก้ค่าแล้ว retry; หลักฐานผิดแบบ terminalหรือยอดไม่ตรงเป็น rejected; ปิด pending ที่ lease หมดอายุได้โดยระบุเหตุผล แต่ไม่มี manual paid/approve |

## หน้าภาพรวมของผู้ดูแล

- `/admin` เปิดที่วิว `overview` (hash ว่าง) วิวเดิมทั้งหมดยังเข้าถึงได้ด้วย hash เช่น `#rooms`, `#bills`
- ภาพรวมรวมคำขอที่ค้างจาก `GET /api/admin/bookings?status=pending`, `GET /api/admin/payments?status=pending`, `GET /api/admin/rooms`, `GET /api/admin/bills?period=YYYY-MM` และ `GET /api/admin/meters?period=YYYY-MM` ด้วย `Promise.allSettled` จึงยังแสดงส่วนที่โหลดสำเร็จเมื่อบางคำขอล้มเหลว
- `GET /api/admin/operations/health` แสดงเป็นการ์ด “การส่งบิลผ่าน LINE” เฉพาะ Owner (การ์ดถูก render ฝั่ง server เมื่อ role เป็น owner เท่านั้น) ครอบคลุม heartbeat ของ worker และคิว pending/failed/stale
- จำนวนงานค้างที่กระทบเงินแสดงเป็น badge ที่เมนู “การจอง” และ “การชำระเงิน” ทั้งบน sidebar และแถบเมนูล่างบนมือถือ โดยอ่านจาก `pending_count` ของ API ไม่ใช่จำนวนแถวที่โหลดมา

## Operational settings ของ FR-14–FR-16

- `GET /api/admin/settings` คืน billing settings และสถานะ integration ที่ปลอดภัย; `PUT /api/admin/settings/integrations` เปลี่ยนค่าได้เฉพาะ Owner
- `POST /api/webhooks/line` ตรวจลายเซ็น raw body ด้วย Channel secret, deduplicate `webhookEventId`, รับรหัส `BIND-` จากข้อความตัวอักษรในแชตผู้ใช้โดยตรง และตอบผล/วิธีผูกบัญชีโดยไม่เปิดเผย LINE User ID ดิบหรือเก็บเนื้อหาข้อความ
- PromptPay target/name, บัญชีปลายทาง, LINE retry/batch, provider/branch, ขนาดไฟล์ และช่วงเผื่อเวลาถูกเก็บใน singleton `integration_settings`
- LINE Channel access token/Channel secret และ SlipOK/EasySlip API key เข้ารหัส AES-256-GCM ด้วย key ที่ derive จาก `APP_KEY` และ field-specific AAD; API คืนเพียง configured flag กับ masked hint ไม่คืน plaintext/ciphertext
- ช่อง secret ว่าง/`null` หมายถึงเก็บค่าเดิม การลบต้องส่ง `*_clear=true` อย่างชัดเจน; web และ worker อ่านฐานข้อมูลในรอบใช้งานถัดไปโดยไม่ต้อง restart
- `APP_KEY`, `APP_URL` และค่าเชื่อมต่อ MySQL เป็น infrastructure settings ที่ยังอยู่ใน environment ไม่อยู่ในหน้าหลังบ้าน
- การยืนยันผู้รับเป็น provider-specific: SlipOK ใช้ผลตรวจบัญชีของ branch ที่กำหนดเมื่อส่ง `log=true` เพราะเลขผู้รับใน response ถูก mask; EasySlip ต้องได้ `matchedAccount` และนำ `matchedAccount.bankNumber` แบบเต็มมาเทียบ suffix อย่างน้อย 6 หลักกับ `payment_receiver_account_tail`

## Shared controls

| พื้นที่ | การควบคุม |
|---|---|
| Mutation API | session CSRF สำหรับผู้ login หรือ signed stateless guest CSRF อายุ 2 ชั่วโมง + same-origin check, JSON/multipart size limit, allowlist input |
| Session | lazy start สำหรับ anonymous, strict cookie, HttpOnly, SameSite, Secure บน HTTPS, rotate login, `auth_version` revocation; Resident idle 15 นาที/absolute 1 ชั่วโมงและไม่มี trusted-device bypass |
| SQL | PDO native prepared statements, InnoDB transactions, `SELECT ... FOR UPDATE`, FK/CHECK/unique/generated keys |
| เงิน | integer/decimal scale 2, total คำนวณ server, bill snapshot immutable, transaction reference unique |
| File | magic-byte MIME, image decode/dimension limits, random private path, 0600, content HMAC |
| Outbound | fixed HTTPS host, no redirect, timeout/response cap, operational credential เข้ารหัสใน MySQL และถอดรหัสเฉพาะฝั่ง server |
| Audit | actor/action/entity/request ID, redact secret, append-only trigger |

## นอกขอบเขตโดยตั้งใจ

รายการต่อไปนี้ไม่ใช่ FR-01–FR-16 และไม่ได้ควรนำจากระบบเดิมมาโดยอัตโนมัติ:

- สัญญาเช่า/e-signature, เงินประกัน, แจ้งซ่อม, พัสดุ, ที่จอดรถ, ประตู/คีย์การ์ด
- บัญชีแยกประเภท ภาษี ใบกำกับภาษี/ใบเสร็จเต็มรูป รายงานบัญชีขั้นสูง
- OTP/MFA สำหรับ login, social/LINE Login, bot command แบบสนทนาทั่วไป, การเก็บประวัติข้อความ และ mobile application
- manual override ให้ paid โดยไม่มีหลักฐาน, partial payment, refund, chargeback
- multi-property/multi-tenant, dynamic plugin/provider endpoint, arbitrary file manager
- การย้ายข้อมูลอัตโนมัติจาก Node/PostgreSQL เดิม

หากต้องเพิ่มรายการนอกขอบเขต ต้องออก requirement ใหม่ ทบทวน schema/authorization/threat model และเพิ่ม test แยก ไม่ควรแทรก field/endpoint โดยไม่มี contract

## End-to-end verification

1. สร้าง owner โดยไม่มี default password แล้ว login/logout และตรวจ session rotation
2. ยิง booking สอง request พร้อมกันไปห้องเดียว รวม replay idempotency key
3. ยืนยัน ยกเลิก และย้ายเข้า ตรวจ state transition/derived room status ทุกขั้น
4. login resident ด้วยเบอร์ active เท่านั้น ตรวจ generic error/field PIN ถูกปฏิเสธ/session 15 นาที–1 ชั่วโมง แล้วลองเข้าบิล/profile ของ resident อื่น
5. จด baseline และเดือนถัดไปทั้งน้ำ/ไฟ รวม rollback reading และ duplicate period
6. preview/bulk บิลหลายห้อง รวมขาดมิเตอร์, ค่าอื่น, bulk ซ้ำ และ total mismatch จาก client
7. ใช้ Owner บันทึก integration settings ตรวจว่า Admin ธรรมดาแก้ไม่ได้, API ไม่คืน secret, ช่องว่างเก็บค่าเดิม, explicit clear ลบจริง และ web/worker เห็นค่ารอบถัดไปโดยไม่ restart
8. ตั้ง signed LINE webhook แล้วทดสอบรหัส `BIND-` ที่ถูกต้อง/หมดอายุ/ใช้ซ้ำ, ลายเซ็นผิด, event ซ้ำ และ event กลุ่ม/ห้อง โดย audit ต้องไม่เก็บรหัสหรือ LINE User ID ดิบ จากนั้นส่ง LINE รายบิล/ทั้งเดือน ทดสอบ retry payload/key เดิม, 409 ที่มี `x-line-accepted-request-id` ถูกต้องซึ่งต้อง finalize เป็น sent, 409 ที่ไม่มี/มี ID ผิดรูปแบบซึ่งต้องไม่ถูกนับว่าสำเร็จ, provider request IDs และ max attempts; ขั้น Verify/reply/push จริงต้องใช้ credential ของ LINE บน staging
9. เปิด PromptPay QR แล้ว decode ตรวจ target/amount/CRC
10. อัปโหลดไฟล์ผิดประเภท/ใหญ่/ซ้ำ และ provider cases: timeout, amount mismatch, receiver mismatch, transaction ซ้ำ, verified
11. ตรวจ bill paid, immutable snapshot, audit redaction และ restore backup บน staging
