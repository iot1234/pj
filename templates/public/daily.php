<?php declare(strict_types=1); ?>
<header class="site-header public-header daily-public-header">
  <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">H</span><span><strong>หอพักของคุณ</strong><small>ห้องพักรายวัน</small></span></a>
  <nav class="header-actions" aria-label="ประเภทการพัก"><a class="button button-ghost" href="/">รายเดือน</a><a class="button button-primary" href="/daily" aria-current="page">รายวัน</a><a class="button button-dark" href="/admin/login">เจ้าของระบบ</a></nav>
</header>
<main id="main-content" class="public-main" data-daily-public>
  <section class="content-section" aria-labelledby="daily-title">
    <div class="section-heading"><div><span class="eyebrow">ห้องพักรายวัน</span><h1 id="daily-title">จองห้องพักรายวัน</h1><p>เลือกวันพักเพื่อดูห้องว่างและยอดรวม ราคาห้องรวมน้ำและไฟแล้ว</p></div></div>
    <ol class="daily-steps" aria-label="ขั้นตอนการจอง"><li><strong>1. เลือกวันพัก</strong><span>ระบุวันเข้า วันออก และจำนวนผู้พัก</span></li><li><strong>2. เลือกห้อง</strong><span>ตรวจยอดรวม แล้วกรอกชื่อและเบอร์โทร</span></li><li><strong>3. ชำระและรอยืนยัน</strong><span>ทำตามคำแนะนำในรายละเอียดการจอง</span></li></ol>
    <form id="daily-search-form" class="panel form-grid form-grid-four">
      <label class="field"><span>วันเข้าพัก (เช็กอิน)</span><input name="check_in_date" type="date" aria-describedby="daily-search-summary" required></label>
      <label class="field"><span>วันออก (เช็กเอาต์)</span><input name="check_out_date" type="date" aria-describedby="daily-search-summary" required><small>เช่น เข้า 10 ออก 11 = พัก 1 คืน</small></label>
      <label class="field"><span>ผู้พัก (คน)</span><input name="guests" type="number" min="1" max="20" step="1" value="1" required></label>
      <div class="field"><span>ดูห้องที่จองได้</span><button class="button button-primary" type="submit">ค้นหาห้องว่าง</button></div>
    </form>
    <p id="daily-search-summary" class="daily-stay-preview" role="status"></p>
    <p class="form-error" id="daily-search-error" role="alert" hidden></p>
    <p id="daily-results-note" role="status">กรอกช่วงวันที่เพื่อดูห้องที่ว่างตลอดการพัก</p>
    <p id="daily-storage-note" class="field-hint" role="status" hidden></p>
    <div id="daily-room-grid" class="room-grid" aria-live="polite"></div>
    <a class="button button-secondary" data-public-support-line href="#" target="_blank" rel="noopener" hidden>สอบถามหอพักผ่าน LINE</a>
  </section>
  <section id="daily-detail" class="content-section" aria-labelledby="daily-detail-title" hidden>
    <div class="section-heading"><div><h2 id="daily-detail-title" tabindex="-1">การจองของคุณ</h2><p>เก็บหมายเลขอ้างอิงและเปิดแท็บนี้ไว้ เพื่อกลับมาตรวจสถานะหรือส่งสลิป</p></div><button class="button button-secondary" type="button" id="daily-detail-refresh">ตรวจสถานะล่าสุด</button></div>
    <p class="form-error" id="daily-detail-error" role="alert" hidden></p>
    <aside id="daily-next-step" class="daily-next-step" role="status" hidden></aside>
    <a class="button button-secondary" href="/daily" target="_blank" rel="noopener">จองเพิ่มในแท็บใหม่</a>
    <div id="daily-booking-summary" class="panel daily-summary" aria-live="polite"></div>
    <button class="button button-secondary" type="button" id="daily-copy-reference" disabled>คัดลอกหมายเลขอ้างอิง</button>
    <p id="daily-hold-countdown" class="field-hint" role="status"></p>
    <div id="daily-slip-recovery" class="security-note stack-form" role="alert" hidden><strong>ยังไม่ทราบผลสลิปเดิม ห้ามโอนซ้ำ</strong><p>กดตรวจผลก่อน หากยังไม่พบรายการ ให้เลือกไฟล์เดิมและส่งเพื่อตรวจผลคำขอเดิม ระบบจะตรวจว่าเป็นไฟล์เดิม หากการจองหมดเวลาแล้วให้ติดต่อหอพักเรื่องคืนเงิน</p><button id="daily-slip-recovery-refresh" type="button" class="button button-secondary">ตรวจผลสลิปเดิม</button></div>
    <div id="daily-payment-panel" class="panel stack-form" hidden>
      <h3>ชำระค่าจอง</h3><p id="daily-payment-status" role="status"></p>
      <div id="daily-payment-totals" class="daily-summary"></div>
      <p id="daily-payment-help">ตรวจชื่อผู้รับและยอดโอนตาม QR ก่อนยืนยัน ระบบแยกยอดด้วยสตางค์ ห้ามปัดยอด</p>
      <button type="button" class="button button-primary" id="daily-load-qr" data-action-help aria-describedby="daily-payment-help daily-next-step" disabled>สร้าง QR ชำระเงิน</button>
      <div id="daily-qr-stage" class="qr-stage" aria-live="polite"></div>
      <form id="daily-slip-form" class="stack-form" hidden>
        <label class="field"><span>หลักฐานการโอน</span><input name="slip" type="file" accept="image/jpeg,image/png,image/webp" required><small>JPEG, PNG หรือ WebP ไม่เกิน 4 MiB</small></label>
        <button class="button button-primary" type="submit">ส่งสลิปให้ระบบตรวจ</button>
      </form>
      <p class="form-error" id="daily-payment-error" role="alert" hidden></p>
    </div>
    <details class="daily-secondary-options"><summary>ปิดรายละเอียดในแท็บนี้</summary><p class="field-hint">การจองยังอยู่ในระบบ แต่แท็บนี้จะเปิดรายละเอียดต่อไม่ได้ เก็บหมายเลขอ้างอิงก่อนปิด หากต้องการยกเลิกการจองให้ติดต่อหอพัก</p><button class="button button-ghost" type="button" id="daily-forget-booking">ปิดรายละเอียดในแท็บนี้</button></details>
  </section>
  <section id="daily-recovery" class="panel stack-form" hidden aria-label="ตรวจผลคำขอเดิม"><h2>ตรวจผลการจองเดิมก่อน</h2><p>การเชื่อมต่อขัดข้อง จึงยังไม่ทราบว่าจองสำเร็จหรือไม่ กดตรวจผลด้านล่าง ระบบจะตรวจรายการเดิมให้ ไม่ต้องกรอกใหม่หรือเริ่มจองซ้ำ</p><button id="daily-recover-submit" type="button" class="button button-primary">ตรวจผลคำขอเดิม</button><p id="daily-recovery-error" class="form-error" role="alert" hidden></p></section>
</main>
<dialog class="app-dialog booking-dialog" id="daily-booking-dialog" aria-labelledby="daily-booking-title"><div class="dialog-panel">
  <div class="dialog-header"><h2 id="daily-booking-title">ตรวจรายละเอียดและส่งคำขอจอง</h2><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div>
  <div id="daily-quote-summary" class="daily-summary" aria-live="polite"></div>
  <form id="daily-booking-form" class="stack-form">
    <label class="field"><span>ชื่อ–นามสกุล</span><input name="full_name" autocomplete="name" minlength="2" maxlength="150" required></label>
    <label class="field"><span>เบอร์มือถือไทย</span><input name="phone" type="tel" autocomplete="tel" maxlength="20" required></label>
    <div class="daily-next-step"><strong>ส่งคำขอแล้ว ยังต้องชำระก่อนยืนยันห้อง</strong><p>ระบบจะแสดงเวลาที่กันห้องไว้และวิธีชำระในหน้าถัดไป เมื่อสลิปผ่านหรือเจ้าของบันทึกรับเงินสดครบจึงยืนยันการจอง ค่าประกันคืนหลังตรวจห้องตอนเช็กเอาต์ โดยอาจหักตามความเสียหาย</p></div>
    <p class="form-error" id="daily-booking-error" role="alert" hidden></p>
    <div class="dialog-actions"><button class="button button-ghost" type="button" data-close-dialog>กลับ</button><button type="submit" class="button button-primary" data-action-help aria-describedby="daily-quote-summary daily-booking-error" disabled>ส่งคำขอจอง</button></div>
  </form>
</div></dialog>
