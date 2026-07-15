<?php declare(strict_types=1); ?>
<main id="main-content" class="auth-shell auth-resident">
  <section class="auth-aside auth-aside-warm" aria-labelledby="resident-welcome-title">
    <a class="brand brand-inverse" href="/">
      <span class="brand-mark" aria-hidden="true">H</span>
      <span><strong>หอพักของคุณ</strong><small>Resident Portal</small></span>
    </a>
    <div class="auth-aside-copy">
      <span class="eyebrow">For residents</span>
      <h1 id="resident-welcome-title">ดูบิล ชำระเงิน และจัดการข้อมูลของคุณ</h1>
      <p>เข้าสู่ระบบด้วยเบอร์โทรศัพท์ที่ผูกกับห้องและ PIN ส่วนตัว 6–12 หลัก</p>
    </div>
    <div class="resident-login-art" aria-hidden="true">
      <span>฿</span><span>⌂</span><span>✓</span>
    </div>
  </section>

  <section class="auth-card" aria-labelledby="resident-login-title">
    <div class="auth-card-heading">
      <a class="back-link" href="/">← กลับหน้าห้องว่าง</a>
      <span class="eyebrow">Resident access</span>
      <h2 id="resident-login-title">เข้าสู่ระบบผู้เช่า</h2>
      <p>กรอกเบอร์โทรศัพท์และ PIN ที่ผู้ดูแลออกให้เมื่อย้ายเข้า</p>
    </div>
    <form id="resident-login-form" class="stack-form" novalidate>
      <label class="field">
        <span>เบอร์โทรศัพท์</span>
        <input name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="081-234-5678" required autofocus>
      </label>
      <label class="field">
        <span>PIN</span>
        <span class="password-field">
          <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6,12}" minlength="6" maxlength="12" autocomplete="current-password" required>
          <button class="password-toggle" type="button" data-password-toggle aria-label="แสดง PIN" aria-pressed="false">แสดง</button>
        </span>
        <small>ตัวเลข 6–12 หลัก ห้ามบอก PIN แก่บุคคลอื่น</small>
      </label>
      <div class="form-error" id="resident-login-error" role="alert" hidden></div>
      <button class="button button-primary button-large button-full" type="submit" data-submit-label="เข้าสู่ระบบ">เข้าสู่ระบบ</button>
    </form>
    <div class="support-note">
      <strong>ลืม PIN หรือเบอร์ไม่ตรง?</strong>
      <p>ติดต่อผู้ดูแลหอพักเพื่อยืนยันตัวตนและออก PIN ใหม่ ระบบจะไม่ส่ง PIN ผ่านหน้าเว็บนี้</p>
    </div>
  </section>
</main>
