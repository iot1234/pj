<?php
declare(strict_types=1);
$resident = is_array($user ?? null) ? $user : [];
$residentName = (string) ($resident['full_name'] ?? $resident['name'] ?? 'ผู้เช่า');
$residentPhone = (string) ($resident['phone'] ?? '');
$residentRoom = (string) ($resident['room_code'] ?? $resident['room'] ?? '');
$residentInitial = preg_match('/^./us', $residentName, $initialMatch) === 1 ? $initialMatch[0] : 'R';
?>
<div class="portal-shell resident-shell">
  <aside class="portal-sidebar resident-sidebar" aria-label="เมนูพอร์ทัลผู้เช่า">
    <a class="brand" href="/resident">
      <span class="brand-mark" aria-hidden="true">H</span>
      <span><strong>หอพักของคุณ</strong><small>Resident Portal</small></span>
    </a>
    <nav class="portal-nav">
      <button class="nav-item is-active" type="button" data-resident-view="dashboard" aria-current="page"><span aria-hidden="true">⌂</span>ภาพรวม</button>
      <button class="nav-item" type="button" data-resident-view="bills"><span aria-hidden="true">▤</span>บิลของฉัน <span class="nav-count" id="resident-unpaid-count" hidden></span></button>
      <button class="nav-item" type="button" data-resident-view="profile"><span aria-hidden="true">◉</span>ข้อมูลส่วนตัว</button>
    </nav>
    <div class="sidebar-profile">
      <span class="avatar" aria-hidden="true"><?= e($residentInitial) ?></span>
      <span><strong id="resident-sidebar-name"><?= e($residentName) ?></strong><small><?= $residentRoom !== '' ? 'ห้อง ' . e($residentRoom) : 'ผู้เช่า' ?></small></span>
    </div>
    <button class="button button-ghost button-full" id="resident-logout" data-resident-logout type="button">ออกจากระบบ</button>
  </aside>

  <div class="portal-workspace">
    <header class="portal-topbar">
      <div>
        <span class="eyebrow">Resident Portal</span>
        <h1 id="resident-page-title">ภาพรวม</h1>
      </div>
      <div class="topbar-user">
        <span id="resident-topbar-name"><?= e($residentName) ?></span>
        <small><?= $residentRoom !== '' ? 'ห้อง ' . e($residentRoom) : '' ?></small>
      </div>
    </header>

    <main id="main-content" class="portal-main" tabindex="-1">
      <div class="alert alert-error" id="resident-global-error" role="alert" hidden>
        <div><strong>โหลดข้อมูลไม่สำเร็จ</strong><p data-error-message></p></div>
        <button class="button button-small" type="button" id="resident-retry">ลองใหม่</button>
      </div>

      <section class="portal-view is-active" id="resident-view-dashboard" data-view-panel="dashboard" aria-labelledby="resident-dashboard-title">
        <div class="section-heading">
          <div><span class="eyebrow">Overview</span><h2 id="resident-dashboard-title">สวัสดี <?= e($residentName) ?></h2><p>ตรวจสอบยอดล่าสุดและสถานะการชำระเงินของคุณ</p></div>
        </div>
        <div class="summary-grid">
          <article class="summary-card summary-card-accent">
            <span>ห้องของคุณ</span>
            <strong><?= $residentRoom !== '' ? e($residentRoom) : '—' ?></strong>
            <small>ผูกกับเบอร์ <?= $residentPhone !== '' ? e($residentPhone) : '—' ?></small>
          </article>
          <article class="summary-card">
            <span>ยอดที่ต้องชำระ</span>
            <strong id="resident-due-total">—</strong>
            <small id="resident-due-caption">กำลังโหลดข้อมูลบิล</small>
          </article>
          <article class="summary-card">
            <span>บิลทั้งหมด</span>
            <strong id="resident-bill-count">—</strong>
            <small>ดูย้อนหลังได้ทุกเดือน</small>
          </article>
        </div>
        <div class="card-block">
          <div class="card-heading">
            <div><h3>บิลล่าสุด</h3><p>คลิกเพื่อดูรายละเอียด QR และแนบสลิป</p></div>
            <button class="button button-ghost button-small" type="button" data-resident-view="bills">ดูทั้งหมด</button>
          </div>
          <div class="bill-list compact-list" id="resident-recent-bills" aria-live="polite" aria-busy="true"></div>
        </div>
      </section>

      <section class="portal-view" id="resident-view-bills" data-view-panel="bills" aria-labelledby="resident-bills-title" hidden>
        <div class="section-heading section-heading-row">
          <div><span class="eyebrow">ประวัติใบแจ้งหนี้</span><h2 id="resident-bills-title">บิลของฉัน</h2><p>รายการย้อนหลังและสถานะการชำระของแต่ละรอบ</p></div>
          <button class="button button-ghost" id="resident-bills-refresh" type="button">รีเฟรช</button>
        </div>
        <div class="segmented-control" id="resident-bill-filter" aria-label="กรองสถานะบิล">
          <button class="is-active" type="button" data-bill-filter="all" aria-pressed="true">ทั้งหมด</button>
          <button type="button" data-bill-filter="unpaid" aria-pressed="false">รอชำระ</button>
          <button type="button" data-bill-filter="paid" aria-pressed="false">ชำระแล้ว</button>
        </div>
        <div class="bill-list" id="resident-bill-list" aria-live="polite" aria-busy="true"></div>
        <div class="empty-state" id="resident-bills-empty" hidden><span class="empty-icon" aria-hidden="true">▤</span><h3>ไม่มีบิลในรายการนี้</h3><p>เมื่อมีการออกบิล รายการจะแสดงที่นี่</p></div>
      </section>

      <section class="portal-view" id="resident-view-profile" data-view-panel="profile" aria-labelledby="resident-profile-title" hidden>
        <div class="section-heading"><div><span class="eyebrow">Account</span><h2 id="resident-profile-title">ข้อมูลส่วนตัว</h2><p>แก้ไขชื่อและอีเมล ห้องและเบอร์โทรเป็นข้อมูลที่ผู้ดูแลยืนยันไว้</p></div></div>
        <div class="profile-layout">
          <form class="card-block stack-form" id="resident-profile-form" novalidate>
            <div class="card-heading"><div><h3>ข้อมูลติดต่อ</h3><p>ข้อมูลนี้ใช้บนบิลและการติดต่อจากหอพัก</p></div></div>
            <label class="field"><span>ชื่อ–นามสกุล</span><input name="full_name" type="text" minlength="2" maxlength="120" autocomplete="name" required></label>
            <label class="field"><span>อีเมล</span><input name="email" type="email" maxlength="190" autocomplete="email" placeholder="name@example.com"></label>
            <label class="field"><span>เบอร์โทรศัพท์</span><input name="phone" type="tel" readonly aria-readonly="true"><small>หากต้องเปลี่ยนเบอร์ กรุณาติดต่อผู้ดูแลเพื่อยืนยันตัวตน</small></label>
            <label class="field"><span>ห้อง</span><input name="room_code" type="text" readonly aria-readonly="true"></label>
            <div class="form-error" id="resident-profile-error" role="alert" hidden></div>
            <button class="button button-primary" type="submit" data-submit-label="บันทึกข้อมูล">บันทึกข้อมูล</button>
          </form>

          <div class="card-block stack-form" id="resident-line-card">
            <div class="card-heading"><div><h3>รับบิลผ่าน LINE</h3><p id="resident-line-status">ยังไม่ได้ผูกบัญชี LINE</p></div></div>
            <form class="stack-form" id="resident-line-start-form" novalidate>
              <label class="field"><span>LINE User ID</span><input name="line_user_id" type="text" minlength="33" maxlength="33" pattern="U[0-9a-f]{32}" autocomplete="off" spellcheck="false" placeholder="U ตามด้วยเลขฐานสิบหก 32 ตัว" required><small>เพิ่มบัญชี LINE Official Account เป็นเพื่อนหรือส่งข้อความหา Bot แล้วคัดลอกรหัสที่ Bot ตอบกลับมาวางที่นี่</small></label>
              <label class="field"><span>PIN ปัจจุบัน</span><input name="current_pin" type="password" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="current-password" required><small>ใช้ยืนยันตัวตนก่อนผูกหรือยกเลิก LINE</small></label>
              <button class="button button-secondary" type="submit">ส่งรหัสยืนยัน</button>
            </form>
            <form class="stack-form" id="resident-line-confirm-form" novalidate hidden>
              <label class="field"><span>รหัสยืนยันจาก LINE</span><input name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required></label>
              <button class="button button-primary" type="submit">ยืนยันการผูกบัญชี</button>
            </form>
            <button class="button button-ghost" id="resident-line-unlink" type="button" hidden>ยกเลิกการผูก LINE</button>
            <div class="form-error" id="resident-line-error" role="alert" hidden></div>
          </div>

          <form class="card-block stack-form" id="resident-pin-form" novalidate>
            <div class="card-heading"><div><h3>เปลี่ยน PIN</h3><p>PIN ใหม่ต้องเป็นตัวเลข 6–12 หลักและไม่ควรเดาง่าย</p></div></div>
            <label class="field"><span>PIN ปัจจุบัน</span><input name="current_pin" type="password" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="current-password" required></label>
            <label class="field"><span>PIN ใหม่</span><input name="new_pin" type="password" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="new-password" required></label>
            <label class="field"><span>ยืนยัน PIN ใหม่</span><input name="confirm_pin" type="password" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="new-password" required></label>
            <div class="form-error" id="resident-pin-error" role="alert" hidden></div>
            <button class="button button-dark" type="submit" data-submit-label="เปลี่ยน PIN">เปลี่ยน PIN</button>
          </form>
        </div>
      </section>
    </main>

    <nav class="mobile-bottom-nav" aria-label="เมนูพอร์ทัลบนมือถือ">
      <button class="is-active" type="button" data-resident-view="dashboard"><span aria-hidden="true">⌂</span>ภาพรวม</button>
      <button type="button" data-resident-view="bills"><span aria-hidden="true">▤</span>บิล</button>
      <button type="button" data-resident-view="profile"><span aria-hidden="true">◉</span>โปรไฟล์</button>
      <button type="button" data-resident-logout><span aria-hidden="true">↪</span>ออกจากระบบ</button>
    </nav>
  </div>
</div>

<template id="resident-bill-row-template">
  <button class="bill-row" type="button" data-open-bill>
    <span class="bill-row-main"><strong data-bill-no></strong><small data-bill-period></small></span>
    <span class="status-badge" data-bill-status></span>
    <span class="bill-row-amount" data-bill-total></span>
    <span class="bill-row-arrow" aria-hidden="true">›</span>
  </button>
</template>

<dialog class="app-dialog bill-dialog" id="resident-bill-dialog" aria-labelledby="resident-bill-dialog-title">
  <div class="dialog-panel dialog-panel-wide">
    <div class="dialog-header">
      <div><span class="eyebrow">รายละเอียดใบแจ้งหนี้</span><h2 id="resident-bill-dialog-title">รายละเอียดบิล</h2></div>
      <button class="icon-button" type="button" data-close-dialog aria-label="ปิดหน้าต่าง">×</button>
    </div>
    <div class="bill-detail-loading" id="resident-bill-loading">กำลังโหลดรายละเอียดบิล…</div>
    <div id="resident-bill-detail" hidden>
      <div class="bill-detail-hero">
        <div><span id="resident-bill-number">—</span><small id="resident-bill-due">—</small></div>
        <div><span>ยอดสุทธิ</span><strong id="resident-bill-total">—</strong><span class="status-badge" id="resident-bill-status">—</span></div>
      </div>
      <div class="bill-detail-grid">
        <section aria-labelledby="bill-breakdown-title">
          <h3 id="bill-breakdown-title">รายการค่าใช้จ่าย</h3>
          <dl class="bill-breakdown" id="resident-bill-breakdown"></dl>
        </section>
        <section class="payment-panel" id="resident-payment-panel" aria-labelledby="payment-panel-title">
          <h3 id="payment-panel-title">ชำระผ่าน PromptPay</h3>
          <div class="qr-stage" id="resident-qr-stage" aria-live="polite">
            <div class="qr-placeholder">เลือก “แสดง QR” เพื่อสร้าง QR ตามยอดบิล</div>
          </div>
          <button class="button button-primary button-full" id="resident-load-qr" type="button">แสดง QR พร้อมเพย์</button>
          <div class="payment-notice" id="resident-payment-notice" role="status" hidden></div>
          <form class="slip-form" id="resident-slip-form" enctype="multipart/form-data" novalidate>
            <label class="file-picker">
              <span>แนบสลิปชำระเงิน</span>
              <input name="slip" type="file" accept="image/jpeg,image/png,image/webp" required>
              <small data-file-name>JPG, PNG หรือ WebP ขนาดไม่เกิน 4 MiB</small>
            </label>
            <div class="form-error" id="resident-slip-error" role="alert" hidden></div>
            <button class="button button-dark button-full" type="submit" data-submit-label="ส่งสลิปเพื่อตรวจสอบ">ส่งสลิปเพื่อตรวจสอบ</button>
          </form>
        </section>
        <section class="payment-complete" id="resident-payment-complete" hidden>
          <span class="success-mark" aria-hidden="true">✓</span><h3>บิลนี้ชำระแล้ว</h3><p>ไม่ต้องส่งสลิปเพิ่มเติม</p>
        </section>
      </div>
    </div>
    <div class="dialog-actions"><button class="button button-ghost" type="button" data-close-dialog>ปิด</button></div>
  </div>
</dialog>
