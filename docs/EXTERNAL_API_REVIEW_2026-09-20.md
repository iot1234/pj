# ตรวจบริการภายนอกและป้องกันการทำรายการผิดพลาด — 20 กันยายน 2026

## ขอบเขตที่ตรวจจากโค้ด

| บริการ | จุดเรียกที่ตรวจ | หน้าที่ |
|---|---|---|
| LINE Messaging API | bot info, webhook endpoint, reply, push | ตรวจบัญชี/การตั้งค่า ตอบแชต และส่งแจ้งเตือน |
| SlipOK | ตรวจสลิปแบบไฟล์และอ่าน quota ตาม branch | ตรวจหลักฐานและบัญชีรับเงินที่ลงทะเบียน |
| EasySlip v2 | verify/bank และ info | ตรวจหลักฐาน เช็กซ้ำ จับคู่บัญชี และอ่านสถานะ/โควตา |
| พร้อมเพย์ | สร้าง EMV payload ในระบบเอง | สร้าง QR ไม่ใช่ API ยืนยันเงินเข้าจากธนาคาร |
| เว็บส่วนหน้า | shared fetch และหน้า settings/LINE | จำกัดเวลารอ กันกดซ้ำ และแสดงผลที่ตรวจได้จริง |

## ข้อผิดพลาดที่แก้

**1. บริการขัดข้องไม่ใช่สลิปไม่ถูกต้อง**

เดิม error ที่ไม่รู้จักหรือคำตอบว่างบางแบบ รวมถึง HTTP 401/403 ของ SlipOK อาจถูกจัดเป็นผลปฏิเสธถาวร แก้ให้ปัญหาการเชื่อมต่อ สิทธิ์ โควตา บริการล่ม และ response ที่ไม่รู้จักคงสถานะ pending โดยไม่ทำบิลเป็น paid และไม่สั่งผู้พักโอนซ้ำ ข้อผิดพลาดหลักฐานที่ระบุไว้ชัดเจนยังปฏิเสธตามเดิม สลิปซ้ำจาก provider ยังคงต้องตรวจสอบกระทบยอด ไม่ถือเป็นหลักฐานให้จ่ายอีกบิล

**2. ตรวจ EasySlip ตามชนิดข้อมูลจริง**

เมื่อระบบส่ง checkDuplicate และ matchAmount ต้องมี isDuplicate และ isAmountMatched เป็น boolean ที่ถูกต้อง ไม่แปลงข้อความ "false" หรือตัวเลขเป็นสถานะผ่าน ตรวจว่า transRef เป็นข้อความ โครงสร้าง matchedAccount ถูกต้อง และ amountInSlip ตรงกับ rawSlip.amount.amount หากข้อมูลไม่ครบ/ขัดแย้งให้ pending ไม่ใช้ค่าเดาหรือ fallback เพื่ออนุมัติ

**3. อ่านค่าตรวจสลิปจากชุดเดียวกัน**

อ่าน provider, key, branch, บัญชีรับเงิน และช่วงเผื่อเวลาจากแถว settings เดียวกัน แทนการอ่านแยกหลายรอบ ใช้ชุดเดิมตลอดการเรียก provider และเปรียบเทียบลายนิ้วมือของค่าหลังได้ผล หากเปลี่ยนระหว่างรอให้ pending และตรวจหลักฐานเดิมใหม่ ไม่ผสมคีย์ของชุดหนึ่งกับบัญชีอีกชุดหนึ่ง ลายนิ้วมือไม่มี plaintext key และไม่เพิ่มช่องกรอกใด ๆ

**4. ลองส่ง LINE ซ้ำด้วยคำขอเดิม**

เดิม manual requeue ของงาน failed สร้าง retry UUID ใหม่และรีเซ็ตเวลา/attempts แก้ให้คง retry key, payload, recipient, เวลาต้นทาง และประวัติ attempts หากเคยส่งสำเร็จแต่คำตอบหาย การตรวจซ้ำจะยังอ้างคำขอเดิม ไม่ส่งเป็นคำขอใหม่อัตโนมัติ หากพ้น 24 ชั่วโมงตามนโยบายอายุงานของระบบ หรือผู้รับเปลี่ยน ให้แจ้งเหตุผลและหยุดก่อนส่ง ต้องตรวจประวัติแทนการหมุน UUID เพื่อบังคับส่ง

**5. หมดเวลารอแล้วคืนการควบคุมจริง**

shared API ใช้ deadline แข่งกับทั้ง fetch และการอ่าน body ไม่พึ่ง abort เพียงอย่างเดียว transport ที่ไม่ยอมจบจึงไม่ทิ้งปุ่มล็อกตลอดเวลา cleanup ปลดตัวกันกดซ้ำและ timer เมื่อจบ ผลจากคำขอเก่าไม่ปลด lock ของคำขอใหม่ การบันทึกที่ไม่ทราบผลยังแสดง unknown outcome และไม่ส่งซ้ำเอง

**6. ผลตรวจบริการไม่ใช่ผลการรับเงิน**

หน้า settings ต้องได้รับ ready=true จริงก่อนแสดงผลผ่าน แยกข้อความ “ตรวจคีย์และโควตาผ่าน ยังไม่ยืนยันสลิปหรือการรับเงิน” และ “สร้าง QR ตามค่าที่บันทึกแล้ว ยังไม่ยืนยันบัญชีหรือการรับเงิน” กรณี ready=false, HTML error หรือ timeout แสดงข้อผิดพลาดและเปิดให้ตรวจใหม่ ไม่เพิ่มช่องกรอกค่าทางเทคนิค

**7. ไม่เก็บ raw error ที่ไม่รู้จักเป็นรหัสตรวจสอบ**

provider code ที่เขียนลง audit จำกัดเฉพาะชุดรหัสที่รู้จัก ไม่เก็บ object หรือข้อความอิสระจาก provider ในช่องนี้ สาเหตุที่แสดงต่อผู้พักเป็นข้อความควบคุม ไม่ส่ง API key หรือ network diagnostics ออกมา

## สิ่งที่ตรวจและยังคงไว้

TLS ตรวจ peer/hostname, ไม่ตาม redirect และใช้ HTTPS endpoint ที่กำหนดไว้ การตรวจสลิปเพิ่ม allowlist ที่ชั้น request อีกครั้ง ตัวส่งสลิปจำกัด body response 256 KiB และเวลารอ 12 วินาที ไม่มี automatic POST retry เพื่อไม่ใช้เครดิตซ้ำ ตัวอ่านโควตาจำกัด response 64 KiB และเวลารอ 10 วินาที ส่วน LINE คงการตรวจลายเซ็น raw webhook, ตรวจสิทธิ์ผู้รับล่าสุด, retry key และ accepted request ID ตามเดิม

ไฟล์สลิปยังผ่านการตรวจ MIME/ขนาด/ภาพจริงและ HMAC การอัปโหลดซ้ำใช้หลักฐานเดิมเป็น idempotency key และ reserve ก่อนเรียก provider ขณะสลิปรอตรวจไม่ส่งข้อความให้ผู้พักจ่ายซ้ำ การแก้รอบนี้ไม่เปลี่ยน schema และไม่เพิ่ม endpoint รับชำระเงินจากคำกล่าวอ้างของเบราว์เซอร์

## ผลทดสอบรอบสุดท้าย

PHP unit/contract **122 ผ่าน**; JavaScript **126 ผ่าน**, ไม่มี failed/cancelled/skipped; PHP syntax **82 ไฟล์ผ่าน**, JavaScript syntax 2 ไฟล์ผ่าน; LINE bot offline, billing CLI, worker contract, shell syntax, install SQL bundle และ diff whitespace ผ่าน

MySQL **11 ชุดผ่าน** พร้อม schema audit บน data directory ใหม่ที่ `127.0.0.1:33342` ชุด billing delivery รวม **31 checks** ทดสอบ retry identity/อายุงาน หลักฐานสมบูรณ์ response ว่าง/สิทธิ์ผิด/บริการล่ม duplicate/exception และ config เปลี่ยนระหว่างรอ โดยใช้ transport จำลอง ไม่ออกอินเทอร์เน็ตไปหา provider

Microsoft Edge headless **39 กรณีผ่าน**: LINE 11, regression เดิม 22, integration status 6 ใช้ HTML/CSS/JS จริงและ API จำลอง รวม deadline, retry recovery, ready=false ไม่ขึ้นสีสำเร็จ, ผลตอบกลับ HTML และจอมือถือ ไม่มี JavaScript page error ทุก request นอก loopback ถูกบล็อก

Log และ source สำรองอยู่ใน `storage/logs/external-api-20260920/` ผล MySQL สุดท้ายอยู่ `verification-mysql-final/` ผลเบราว์เซอร์อยู่ `verification/` ไม่รวมใน Git มีการหยุดเฉพาะ process ทดสอบที่สร้างในรอบนี้

## ขอบเขตที่ไม่ควรเข้าใจเกินผลตรวจ

ไม่ได้ใช้คีย์จริง ตรวจสลิปจริง ส่ง LINE หาผู้พัก หรือทดสอบธุรกรรมธนาคาร และไม่แตะ `.env`, APP_KEY หรือฐานข้อมูล production การผ่าน unit/MySQL/browser/CI ไม่แทนการตรวจ staging กับบัญชีบริการจริง และ Edge ที่ปรับ viewport ไม่เท่ากับ Safari/iOS จริง

การอ่าน config แบบ snapshot และเทียบก่อน/หลังครอบคลุมช่วงรอ provider ไม่ใช่ transaction ร่วมกับบริการภายนอก และยังไม่เพิ่มการล็อก fingerprint ที่จุด commit ของ PaymentService การเปลี่ยนบัญชีรับเงินจริงควรแยกจากช่วงประมวลผลสลิปและตรวจยอดค้างก่อนเปิดใช้อีกครั้ง

ฟังก์ชันทดสอบ LINE เดิมใน general settings ตรวจ Token/ข้อมูลบัญชีเท่านั้น ไม่ใช่การตรวจ Webhook ทั้งเส้นทาง ให้ใช้ **บัญชี LINE OA > ตรวจการเชื่อมต่อจริง** ที่เพิ่มใน commit ก่อน ส่วนข้อความบางกรณีของ endpoint อ่านโควตายังเป็นข้อความรวม ไม่ได้แยกรหัสผู้ให้บริการทุกกรณีในรอบนี้

ตรวจสอบสัญญา API จากเอกสารทางการต่อไปนี้ ไม่เปลี่ยนไปใช้ EasySlip Partners API ซึ่งมีรูปแบบยืนยันตัวตนต่างจาก API v2 ที่โครงการใช้อยู่:

- EasySlip v2 bank: https://document.easyslip.com/en/v2/verify/bank/
- EasySlip v2 info: https://document.easyslip.com/en/v2/info
- SlipOK check slip: https://slipok.com/api-documentation/check-slip/
- SlipOK error codes: https://slipok.com/api-documentation/error-status-code/
- SlipOK quota: https://slipok.com/api-documentation/check-slip-quota/
- LINE retry keys: https://developers.line.biz/en/docs/messaging-api/retrying-api-request/

ทดสอบซ้ำใน VS Code: `scripts\test-local.cmd` การทดสอบ MySQL ต้องใช้ฐานใหม่แยกและบัญชีทดสอบ ไม่รันชุด fixture บนฐานที่มีข้อมูลจริง โฟลเดอร์ `storage/presentation-actual-20260920/` เป็นงานอื่นที่ปรากฏระหว่างการตรวจ ไม่แก้ไข ไม่ลบ และไม่รวมใน commit นี้
