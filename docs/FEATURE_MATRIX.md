# Feature matrix: FR-01 ถึง FR-16

เอกสารนี้เป็นขอบเขตอ้างอิงของ PHP/MySQL rewrite ฟีเจอร์ที่ไม่อยู่ใน FR-01–FR-16 ถือว่าไม่อยู่ในงาน แม้ระบบเดิมจะเคยมี

## Actor และสิทธิ์

| Actor | เข้าใช้ | ขอบเขต |
|---|---|---|
| Guest | ไม่ต้อง login | ดูห้องว่างและส่งจอง |
| Resident | เบอร์โทร + PIN 6–12 หลัก | ดู/แก้ profile ของตน ดูบิลของตน เปิด QR และส่งสลิปของตน |
| Admin | username + password | ห้อง การจอง ผู้เช่า มิเตอร์ บิล LINE และการชำระ |
| Owner | Admin role `owner` | สิทธิ์ Admin ทั้งหมด จัดการบัญชีผู้ดูแล และเปลี่ยนค่า PromptPay/LINE/SlipOK/EasySlip |

สถานะห้องไม่ใช่ field ที่แก้ตรง ๆ แต่คำนวณตามลำดับ: มี `occupancy active` = `occupied`; ไม่เช่นนั้นมี booking `pending/confirmed` = `reserved`; นอกนั้น = `available`

## Requirement coverage

| FR | ความต้องการจาก `1.txt` | หน้า/API | ตารางและกฎหลัก | Acceptance ที่ต้องผ่าน |
|---|---|---|---|---|
| FR-01 | Guest ดูห้องว่างพร้อมประเภท ราคา สิ่งอำนวยความสะดวก รูป | `/`, `GET /api/public/rooms` | `rooms`; query คืนเฉพาะ derived `available` และไม่คืน soft-deleted room | ห้อง reserved/occupied/deleted ไม่ปรากฏ; amenities เป็น array และ image URL ไม่อ่าน path จากผู้ใช้ |
| FR-02 | Guest จองด้วยชื่อ/เบอร์ แล้วห้องเป็น “จองแล้ว” รอ Admin | `POST /api/public/bookings` | `bookings`; normalized Thai phone, idempotency key, generated unique active room, IP/phone rate limit, hold timeout จาก DB clock | request ซ้ำด้วย key เดิมไม่สร้างซ้ำ; booking พร้อมกันห้องเดียวสำเร็จได้หนึ่งรายการ; invalid/unavailable room ไม่กิน phone quota; pending หมดอายุคืนห้องและ replay ต้องใช้ key ใหม่ ไม่แจ้งว่าสำเร็จ |
| FR-03 | Resident login ด้วยเบอร์ที่ผูกห้อง พร้อม PIN | `/resident/login`, `POST /api/auth/resident/login`, logout/me | `residents`, active `occupancies`; PIN hash, IP + account/source + distributed account rate limit, signed trusted-device recovery, generic error, session rotation | เบอร์+PIN ถูกและมี occupancy active เข้าได้; inactive/ไม่มีห้อง/PIN ผิดเข้าไม่ได้และไม่บอกว่าข้อมูลใดผิด; อุปกรณ์ใหม่ข้าม bucket ที่ block ไม่ได้ แต่อุปกรณ์ที่เคยสำเร็จใช้ credential ถูกต้องกู้จาก account-lockout DoS ได้โดยยังติด IP limit |
| FR-04 | Resident ดูประวัติบิลย้อนหลังและสถานะ | `/resident`, `GET /api/resident/bills`, `GET /api/resident/bills/{id}` | `bills`, `bill_items`, `payments`; query bind resident จาก session | เห็นเฉพาะบิลตนเอง เรียงย้อนหลัง แสดง pending/paid และ payment ล่าสุด; เปิด ID ของคนอื่นได้ 404/403 |
| FR-05 | Resident ดูและแก้ข้อมูลส่วนตัว | `GET|PUT /api/resident/profile`, `POST /api/resident/profile/pin`, `POST /api/resident/profile/line/{start,confirm,unlink}`; Admin ใช้ `PUT /api/admin/residents/{id}` | `residents`; ชื่อ/email แก้ผ่าน allowlist, LINE ต้อง PIN ปัจจุบัน + OTP อายุ 10 นาที + audit HMAC, เบอร์เป็น login identity | Resident แก้ชื่อ/email และเปลี่ยน PIN ด้วย PIN เดิม; LINE ส่งบิลได้เมื่อ audit ล่าสุดยืนยัน ID ปัจจุบันเท่านั้น; legacy ID fail-closed; Admin เปลี่ยนเบอร์แล้ว revoke session เดิม |
| FR-06 | เพิ่ม ลบ แก้บัญชีผู้ดูแล | `GET|POST /api/admin/users`, `PUT|DELETE /api/admin/users/{id}` | `admin_users`; owner-only, unique username, password hash, auth version | Admin ธรรมดาถูกปฏิเสธ; ปิด owner คนสุดท้ายไม่ได้; เปลี่ยน password/active/role revoke session เก่า |
| FR-07 | เพิ่ม ลบ แก้ข้อมูลห้อง | `GET|POST /api/admin/rooms`, `PUT|DELETE /api/admin/rooms/{id}` | `rooms`; unique room code, JSON amenities, soft delete | validate floor/rent/type/amenities; ห้องที่มี booking/occupancy active ลบไม่ได้; public ไม่เห็น deleted |
| FR-08 | แสดง Available/Occupied/Reserved | Admin room list และ public room list | derived จาก `rooms` + active `bookings` + active `occupancies` | precedence occupied > reserved > available ถูกต้อง และไม่มี endpoint รับ status จาก client |
| FR-09 | ยืนยัน/ยกเลิก booking และย้ายเข้าเป็น occupied | `GET /api/admin/bookings`, `POST .../{id}/confirm`, `/cancel`, `/move-in` | `bookings`, `residents`, `occupancies`; transaction + row locks + state checks | pending→confirmed/cancelled; confirmed→moved_in สร้าง/ผูก resident+occupancy; race/replay ไม่สร้าง occupancy ซ้ำ |
| FR-10 | Admin ดูรายละเอียดผู้เช่าปัจจุบันของแต่ละห้อง พร้อมงานดูแลวงจรผู้พัก | `GET /api/admin/residents`, `PUT /api/admin/residents/{id}`, `POST .../{id}/reset-pin`, `POST .../{id}/move-out` | active `occupancies` join `residents`, `rooms`; transaction + row lock + `auth_version` | แก้ข้อมูลที่ยืนยันแล้ว/รีเซ็ต PIN พร้อม revoke session; ย้ายออกได้เมื่อมีบิลเดือนปิดท้ายที่ชำระแล้ว ไม่มีบิล pending และไม่มีบิลเดือนหลังวันที่ย้าย จากนั้นปิด occupancy/ผู้พักและคืนห้องเป็นว่างอย่างเป็นชุดเดียว |
| FR-11 | บันทึกมิเตอร์น้ำ/ไฟรายห้องรายเดือน | `GET /api/admin/meters?period=YYYY-MM`, `POST /api/admin/meters` | `meter_readings`; unique room/type/period, decimal 2 ตำแหน่ง | เดือน/ห้องเดียวมี water/electric ได้ประเภทละหนึ่งแถว; input ติดลบ/ย้อน/เกิน 9,999,999 ถูกปฏิเสธ; usage เกิน 10,000 หน่วยรวบรวมเตือนทุกประเภทก่อนยืนยัน และแก้หลังออกบิลไม่ได้ |
| FR-12 | ดึงเดือนก่อนและคำนวณหน่วยอัตโนมัติ | API มิเตอร์เดียวกับ FR-11 | previous reading ล่าสุดก่อน period; first baseline previous=current; DB CHECK `units=current-previous` | เดือนแรก units=0; เดือนถัดไป previous ตรง current ล่าสุด; client กำหนด previous/units เองไม่ได้ |
| FR-13 | ออกบิลหลายห้อง รวมเช่า น้ำ ไฟ อื่น ๆ | `POST /api/admin/bills/preview`, `POST /api/admin/bills/bulk`, `GET /api/admin/bills` | `billing_settings`, `bills`, `bill_items`; immutable snapshots, unique occupancy/period, HMAC preview token | preview แจ้งห้องขาดมิเตอร์; bulk ต้องใช้ token อายุ 5 นาทีที่ผูกยอด/ผู้พัก/มิเตอร์และหยุดเมื่อข้อมูลเปลี่ยน; transaction/idempotent; total คำนวณ server และตรง snapshot 2 ตำแหน่ง |
| FR-14 | ส่งบิล LINE รายห้อง/พร้อมกัน | `POST /api/admin/bills/{id}/line`, `POST /api/admin/bills/line-bulk` | `notification_outbox`, `integration_settings`, append-only `audit_logs`; token เข้ารหัส, verified recipient HMAC, unique bill/purpose, stable UUID v4 retry key, provider request IDs, backoff | ไม่มีหรือยังไม่ยืนยัน LINE ID ถูก skip/แจ้งชัด; worker ตรวจ ID กับ audit ล่าสุดก่อน network call; retry ใช้ payload/ผู้รับ/`X-Line-Retry-Key` เดิม; 409 ถือว่าได้รับแล้ว; terminal failure มองเห็นได้และ request IDs ช่วย reconcile |
| FR-15 | สร้าง QR พร้อมเพย์ตามยอดบิล | `GET /api/resident/bills/{id}/promptpay` | `bills.total_amount` snapshot + PromptPay target ใน `integration_settings`; EMV CRC | QR amount มาจากบิลของ resident ที่ login เท่านั้น; endpoint เปิดเมื่อ PromptPay และระบบตรวจสลิปพร้อมและไม่มี payment สถานะ pending/verified; target/amount invalid ถูกปฏิเสธ; CRC ถูกต้อง |
| FR-16 | ตรวจสลิป SlipOK/EasySlip เทียบยอด/ปลายทาง แล้ว paid | `POST /api/resident/bills/{id}/slip`; Admin ใช้ paginated `GET /api/admin/payments`, `GET .../{id}/slip`, `POST .../{id}/retry`, `POST .../{id}/close` | `payments`, `integration_settings`, private slip storage; API key เข้ารหัส, HMAC/transaction unique; reserve + verification lease ก่อนเรียก provider และ finalize ด้วย bill lock | JPEG/PNG/WebP ไม่เกิน 4 MiB/4,096 px/8 MP; pending recovery เรียงก่อนและแบ่งหน้า; amount/receiver/transaction/เวลาโอนถูกต้องจึง verified+paid; timeout/ผลไม่ชัดเจน/ตั้งค่าผู้รับไม่ครบหรือผู้รับไม่ตรง (รวม SlipOK 1014) คง pending เพื่อแก้ค่าแล้ว retry; หลักฐานผิดแบบ terminalหรือยอดไม่ตรงเป็น rejected; ปิด pending ที่ lease หมดอายุได้โดยระบุเหตุผล แต่ไม่มี manual paid/approve |

## Operational settings ของ FR-14–FR-16

- `GET /api/admin/settings` คืน billing settings และสถานะ integration ที่ปลอดภัย; `PUT /api/admin/settings/integrations` เปลี่ยนค่าได้เฉพาะ Owner
- `POST /api/webhooks/line` ตรวจลายเซ็น raw body ด้วย Channel secret, deduplicate `webhookEventId` และตอบ LINE User ID/วิธีผูกบัญชีให้ event `follow` หรือข้อความตัวอักษรจากผู้ใช้โดยตรง โดยไม่เก็บเนื้อหาข้อความ
- PromptPay target/name, บัญชีปลายทาง, LINE retry/batch, provider/branch, ขนาดไฟล์ และช่วงเผื่อเวลาถูกเก็บใน singleton `integration_settings`
- LINE Channel access token/Channel secret และ SlipOK/EasySlip API key เข้ารหัส AES-256-GCM ด้วย key ที่ derive จาก `APP_KEY` และ field-specific AAD; API คืนเพียง configured flag กับ masked hint ไม่คืน plaintext/ciphertext
- ช่อง secret ว่าง/`null` หมายถึงเก็บค่าเดิม การลบต้องส่ง `*_clear=true` อย่างชัดเจน; web และ worker อ่านฐานข้อมูลในรอบใช้งานถัดไปโดยไม่ต้อง restart
- `APP_KEY`, `APP_URL` และค่าเชื่อมต่อ MySQL เป็น infrastructure settings ที่ยังอยู่ใน environment ไม่อยู่ในหน้าหลังบ้าน
- การยืนยันผู้รับเป็น provider-specific: SlipOK ใช้ผลตรวจบัญชีของ branch ที่กำหนดเมื่อส่ง `log=true` เพราะเลขผู้รับใน response ถูก mask; EasySlip ต้องได้ `matchedAccount` และนำ `matchedAccount.bankNumber` แบบเต็มมาเทียบ suffix อย่างน้อย 6 หลักกับ `payment_receiver_account_tail`

## Shared controls

| พื้นที่ | การควบคุม |
|---|---|
| Mutation API | session CSRF สำหรับผู้ login หรือ signed stateless guest CSRF อายุ 2 ชั่วโมง + same-origin check, JSON/multipart size limit, allowlist input |
| Session | lazy start สำหรับ anonymous, strict cookie, HttpOnly, SameSite, Secure บน HTTPS, rotate login, `auth_version` revocation |
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
4. login resident แล้วลองเข้าบิล/profile ของ resident อื่น
5. จด baseline และเดือนถัดไปทั้งน้ำ/ไฟ รวม rollback reading และ duplicate period
6. preview/bulk บิลหลายห้อง รวมขาดมิเตอร์, ค่าอื่น, bulk ซ้ำ และ total mismatch จาก client
7. ใช้ Owner บันทึก integration settings ตรวจว่า Admin ธรรมดาแก้ไม่ได้, API ไม่คืน secret, ช่องว่างเก็บค่าเดิม, explicit clear ลบจริง และ web/worker เห็นค่ารอบถัดไปโดยไม่ restart
8. ตั้ง signed LINE webhook แล้วทดสอบลายเซ็นผิด, event ซ้ำ, event กลุ่ม/ห้อง และการตอบ ID โดย audit ต้องไม่เก็บข้อความ/LINE User ID ดิบ จากนั้นส่ง LINE รายบิล/ทั้งเดือน ทดสอบ retry payload เดิม, 409, provider request IDs และ max attempts
9. เปิด PromptPay QR แล้ว decode ตรวจ target/amount/CRC
10. อัปโหลดไฟล์ผิดประเภท/ใหญ่/ซ้ำ และ provider cases: timeout, amount mismatch, receiver mismatch, transaction ซ้ำ, verified
11. ตรวจ bill paid, immutable snapshot, audit redaction และ restore backup บน staging
