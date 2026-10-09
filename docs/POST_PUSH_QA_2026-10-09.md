# ตรวจซ้ำหลัง push — 9 ตุลาคม 2026

ตรวจ commit `1ce4a38` จาก GitHub Actions และตรวจโค้ดต่อจากผลทดสอบรายวันเดิม พบจุดที่ยังไม่ครบและแก้ source เพิ่มดังนี้

| จุดที่พบ | การแก้และหลักฐาน |
|---|---|
| CI ของชุดทดสอบมิเตอร์ยังคาด 121 CHECKs/27 triggers ของฐานรุ่นเก่า | เทียบชื่อและนิยามกับ canonical SQL ทั้งชุด พร้อมตรวจ enforcement และคง guards หลัง migration 009–015; fixture แยกผ่าน 4 กลุ่ม |
| CI รายงาน failure ระหว่างทดสอบปฏิเสธ installer ซ้ำ ทั้งที่เป็นผลที่ต้องการ | ใช้เงื่อนไข `if` สำหรับ negative test แล้วตรวจ abort marker เดิม โดยไม่ปิด error handling ของงาน integration |
| Fixture ทดสอบจองรายเดือนพยายามลบห้องที่ยังมี pending อยู่ จึงถูก guard ปฏิเสธ | ตรวจ expiry ผ่าน API ให้ commit การปิดรายการก่อนลบ แล้วตรวจ replay เดิมหลังลบห้องอีกครั้ง โดยคง guard; operations MySQL เพิ่มกรณีนี้และผ่าน 15 กลุ่ม |
| สถานะห้องและข้อห้ามเปลี่ยนประเภท/ลบใช้วันจาก PHP ต่างจากวันของฐานข้อมูล | อ่าน DB UTC แล้วแปลงตาม APP_TIMEZONE; ทดสอบทั้ง DB ข้ามวันก่อนและหลัง PHP รวม booking MySQL 20 กลุ่ม |
| ผู้พักที่ถึงวันออก/เกินกำหนดถูกตัดจากตารางช่วงเริ่มวันนี้ | หน้าเจ้าของรวมรายการ checked-in ที่ยังไม่เช็กเอาต์ เพื่อให้เปิดรายละเอียดและทำงานต่อได้ |
| เลือกซ่อมหลักฐานหนึ่งรายการ แล้วเลือกพักตรวจอีกหนึ่งรายการ ทำให้ form ส่งไปผิด ID | แยก ID ของแต่ละงานและตรวจสถานะ/สิทธิ์ดำเนินการล่าสุดก่อนส่ง; backend ยังคงตรวจ HMAC ของไฟล์ต้นฉบับ |
| ผลซ่อมหลักฐานสูญหายหลัง commit หรือหลักฐานเก่าหายจาก summary เพราะมีรายการใหม่ | ตรวจผลจากหลักฐาน ID เดิมที่พร้อมใช้งาน หรือ replay ไฟล์เดิมด้วย marker/SHA เดิม โดยไม่ใช้เงื่อนไขเริ่มงานใหม่มาปิดทางกู้ผล |
| ตารางแสดงเพียง 500 รายการโดยไม่อ่าน has_more/next_offset | แสดงสถานะข้อมูลที่ยังไม่ครบและปุ่มโหลดเพิ่มเติม ผูกกับตัวกรองเดิมและกันคำตอบเก่า/กดซ้ำ |
| Log worker แสดงเพียงชนิด exception ทำให้หาสาเหตุ schema ไม่พร้อมยาก | เพิ่มรหัสข้อผิดพลาดที่ผ่านรูปแบบปลอดภัยใน log/heartbeat โดยไม่พิมพ์ข้อความ exception หรือ credentials; worker probe เพิ่มใน healthz รวม 16 กลุ่มผ่าน |

[CI รอบเดิม](https://github.com/iot1234/pj/actions/runs/37947083151) ยืนยันว่า frontend, production Docker image, PHP syntax/unit และ Compose ผ่าน ส่วนขั้น MySQL integration หยุดที่ assertion จำนวน CHECK ของ fixture รุ่นเก่า ผล CI ของ source รุ่นแก้ต้องตรวจอีกครั้งหลัง push และไม่ถือการแก้ไฟล์อย่างเดียวเป็นหลักฐานว่าผ่าน

การทดสอบเพิ่มใช้ MySQL แยกและ transport จำลอง ไม่มีการโอนเงินจริงหรือรัน migration บนฐานใช้งานจริง ขั้นเปิดใช้ยังต้องสำรอง/ทดสอบ restore หยุด web/worker/cron ติดตั้ง migrations ที่ยังขาด แล้วผ่าน DBA schema audit และ runtime readiness ตาม [คู่มือรายวัน](DAILY_BOOKING.md) ก่อนเปิด traffic

ชุดแก้รอบนี้ผ่าน PHP unit 139 + daily unit 26, JavaScript 289, actual browser 7 stages และ mocked browser 7 stages ชุดฐานข้อมูลรายวันผ่าน booking 20/payment 20/schema fault 23/migration 6; ชุดมิเตอร์ migration เดิมผ่าน 4 และ HTTP/worker readiness ผ่าน 16 พร้อมตรวจ regression LINE, มิเตอร์และระบบรายเดือนบน fixture แยก
