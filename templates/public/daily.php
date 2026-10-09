<?php declare(strict_types=1); ?>
<header class="site-header public-header">
  <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">H</span><span><strong>หอพักของคุณ</strong><small>ห้องพักรายวัน</small></span></a>
  <nav class="header-actions" aria-label="ประเภทการพัก"><a class="button button-ghost" href="/">รายเดือน</a><a class="button button-primary" href="/daily" aria-current="page">รายวัน</a><a class="button button-dark" href="/admin/login">เจ้าของระบบ</a></nav>
</header>
<main id="main-content" class="public-main" data-daily-public>
  <section class="content-section" aria-labelledby="daily-title">
    <div class="section-heading"><div><span class="eyebrow">ห้องพักรายวัน</span><h1 id="daily-title">เลือกวันพัก แล้วค้นหาห้องว่าง</h1><p>เลือกวันเช็กอินและวันเช็กเอาต์ ราคานี้รวมค่าน้ำและค่าไฟแล้ว</p></div></div>
    <form id="daily-search-form" class="panel form-grid form-grid-four">
      <label class="field"><span>วันเช็กอิน</span><input name="check_in_date" type="date" required></label>
      <label class="field"><span>วันเช็กเอาต์</span><input name="check_out_date" type="date" required><small>คิดค่าห้องตามจำนวนคืน ไม่รวมวันเช็กเอาต์</small></label>
      <label class="field"><span>ผู้พัก (คน)</span><input name="guests" type="number" min="1" max="20" step="1" value="1" required></label>
      <div class="field"><span>ตรวจห้องว่าง</span><button class="button button-primary" type="submit">ค้นหาห้อง</button></div>
    </form>
    <p class="form-error" id="daily-search-error" role="alert" hidden></p>
    <p id="daily-results-note" role="status">กรอกช่วงวันที่เพื่อดูห้องที่ว่างตลอดการพัก</p>
    <p id="daily-storage-note" class="field-hint" role="status" hidden></p>
    <div id="daily-room-grid" class="room-grid" aria-live="polite"></div>
  </section>
  <section id="daily-detail" class="content-section" aria-labelledby="daily-detail-title" hidden>
    <div class="section-heading"><div><h2 id="daily-detail-title">การจองของคุณ</h2><p>ข้อมูลนี้เปิดได้เฉพาะแท็บที่ใช้จอง เก็บหมายเลขอ้างอิงไว้ติดต่อหอพัก</p></div><button class="button button-secondary" type="button" id="daily-detail-refresh">ตรวจสถานะล่าสุด</button></div>
    <p class="form-error" id="daily-detail-error" role="alert" hidden></p>
    <div id="daily-booking-summary" class="panel daily-summary" aria-live="polite"></div>
    <button class="button button-secondary" type="button" id="daily-copy-reference" disabled>คัดลอกหมายเลขอ้างอิง</button>
    <p id="daily-hold-countdown" class="field-hint" role="status"></p>
    <div id="daily-slip-recovery" class="security-note stack-form" role="alert" hidden><strong>ยังไม่ทราบผลสลิปเดิม ห้ามโอนซ้ำ</strong><p>กดตรวจผลก่อน หากยังไม่พบรายการ ให้เลือกไฟล์เดิมและส่งเพื่อตรวจผลคำขอเดิม ระบบจะตรวจว่าเป็นไฟล์เดิม หากการจองหมดเวลาแล้วให้ติดต่อหอพักเรื่องคืนเงิน</p><button id="daily-slip-recovery-refresh" type="button" class="button button-secondary">ตรวจผลสลิปเดิม</button></div>
    <div id="daily-payment-panel" class="panel stack-form" hidden>
      <h3>ชำระค่าจอง</h3><p id="daily-payment-status" role="status"></p>
      <p id="daily-payment-help">ตรวจชื่อผู้รับและยอดโอนตาม QR ก่อนยืนยัน ระบบแยกยอดด้วยสตางค์ ห้ามปัดยอด</p>
      <button type="button" class="button button-primary" id="daily-load-qr" disabled>สร้าง QR ชำระเงิน</button>
      <div id="daily-qr-stage" class="qr-stage" aria-live="polite"></div>
      <form id="daily-slip-form" class="stack-form" hidden>
        <label class="field"><span>หลักฐานการโอน</span><input name="slip" type="file" accept="image/jpeg,image/png,image/webp" required><small>JPEG, PNG หรือ WebP ไม่เกิน 4 MiB</small></label>
        <button class="button button-primary" type="submit">ส่งสลิปให้ระบบตรวจ</button>
      </form>
      <p class="form-error" id="daily-payment-error" role="alert" hidden></p>
    </div>
    <p class="field-hint">การล้างสิทธิ์ด้านล่างปิดรายละเอียดในแท็บนี้ โดยการจองยังอยู่ในระบบ หากต้องการยกเลิก กรุณาติดต่อหอพักด้วยหมายเลขอ้างอิง</p>
    <button class="button button-ghost" type="button" id="daily-forget-booking">ปิดรายละเอียดและล้างสิทธิ์จากแท็บนี้</button>
  </section>
  <section id="daily-recovery" class="panel stack-form" hidden aria-label="ตรวจผลคำขอเดิม"><h2>ตรวจผลการจองเดิม</h2><p>ยังไม่ทราบผลคำขอ ห้ามเริ่มจองซ้ำ กดปุ่มนี้เพื่อส่งคำขอเดิมและอ่านผล โดยใช้หมายเลขคำขอเดิม</p><button id="daily-recover-submit" type="button" class="button button-primary">ตรวจผลคำขอเดิม</button><p id="daily-recovery-error" class="form-error" role="alert" hidden></p></section>
</main>
<dialog class="app-dialog booking-dialog" id="daily-booking-dialog" aria-labelledby="daily-booking-title"><div class="dialog-panel">
  <div class="dialog-header"><h2 id="daily-booking-title">ตรวจรายละเอียดและส่งคำขอจอง</h2><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div>
  <div id="daily-quote-summary" class="daily-summary" aria-live="polite"></div>
  <form id="daily-booking-form" class="stack-form">
    <label class="field"><span>ชื่อ–นามสกุล</span><input name="full_name" autocomplete="name" minlength="2" maxlength="150" required></label>
    <label class="field"><span>เบอร์มือถือไทย</span><input name="phone" type="tel" autocomplete="tel" maxlength="20" required></label>
    <p class="field-hint">คำขอจะกันห้องเฉพาะช่วงวันที่เลือก เมื่อระบบตรวจสลิปผ่านหรือเจ้าของรับเงินสดครบ ระบบจะยืนยันห้องให้อัตโนมัติภายในเวลาที่กันห้อง ค่าประกันแยกจากค่าห้องและคืนเมื่อเช็กเอาต์หลังตรวจห้อง</p>
    <p class="form-error" id="daily-booking-error" role="alert" hidden></p>
    <div class="dialog-actions"><button class="button button-ghost" type="button" data-close-dialog>กลับ</button><button type="submit" class="button button-primary" disabled>ส่งคำขอจอง</button></div>
  </form>
</div></dialog>
