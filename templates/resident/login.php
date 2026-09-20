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
      <p>เข้าสู่ระบบด้วยเบอร์โทรศัพท์และรหัสผ่านของผู้พัก หรือใช้รหัสเปิดใช้งานครั้งเดียวเมื่อเข้าใช้ครั้งแรก</p>
    </div>

  </section>

  <section class="auth-card" aria-labelledby="resident-login-title">
    <div class="auth-card-heading">
      <a class="back-link" href="/">กลับหน้าห้องว่าง</a>
      <span class="eyebrow">Resident access</span>
      <h2 id="resident-login-title">เข้าสู่ระบบผู้เช่า</h2>
      <p>กรอกเบอร์โทรศัพท์และข้อมูลลับของบัญชี ระบบจะไม่อนุญาตให้เข้าใช้ด้วยเบอร์โทรเพียงอย่างเดียว</p>
    </div>
    <form id="resident-login-form" class="stack-form" novalidate>
      <div class="field">
        <label for="resident-phone">เบอร์โทรศัพท์</label>
        <input id="resident-phone" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="081-234-5678" aria-describedby="resident-phone-help" required autofocus>
        <small id="resident-phone-help">ใช้ได้เฉพาะเบอร์ของผู้พักที่มีห้องใช้งานอยู่</small>
      </div>
      <label class="check-field">
        <input id="resident-first-activation" type="checkbox" aria-controls="resident-new-password-fields">
        <span>เข้าใช้ครั้งแรกด้วยรหัสเปิดใช้งานจากผู้ดูแล</span>
      </label>
      <div class="field">
        <label id="resident-credential-label" for="resident-credential">รหัสผ่าน</label>
        <span class="password-field">
          <input id="resident-credential" name="credential" type="password" maxlength="200" autocomplete="current-password" aria-describedby="resident-credential-help" required>
          <button class="password-toggle" type="button" data-password-toggle aria-label="แสดงรหัสลับ" aria-controls="resident-credential" aria-pressed="false">แสดง</button>
        </span>
        <small id="resident-credential-help">ใช้รหัสผ่านที่ตั้งไว้ตอนเปิดใช้งานครั้งแรก</small>
      </div>
      <div class="form-grid" id="resident-new-password-fields" hidden>
        <div class="field">
          <label for="resident-new-password">ตั้งรหัสผ่านใหม่</label>
          <span class="password-field">
            <input id="resident-new-password" name="new_password" type="password" minlength="12" maxlength="200" autocomplete="new-password" aria-describedby="resident-new-password-help">
            <button class="password-toggle" type="button" data-password-toggle aria-label="แสดงรหัสผ่านใหม่" aria-controls="resident-new-password" aria-pressed="false">แสดง</button>
          </span>
          <small id="resident-new-password-help">อย่างน้อย 12 ตัวอักษร และควรผสมตัวอักษร ตัวเลข หรือสัญลักษณ์</small>
        </div>
        <div class="field">
          <label for="resident-new-password-confirm">ยืนยันรหัสผ่านใหม่</label>
          <span class="password-field">
            <input id="resident-new-password-confirm" name="new_password_confirm" type="password" minlength="12" maxlength="200" autocomplete="new-password">
            <button class="password-toggle" type="button" data-password-toggle aria-label="แสดงการยืนยันรหัสผ่าน" aria-controls="resident-new-password-confirm" aria-pressed="false">แสดง</button>
          </span>
        </div>
      </div>
      <div class="form-error" id="resident-login-error" role="alert" hidden></div>
      <button class="button button-primary button-large button-full" type="submit" data-submit-label="เข้าสู่ระบบ">เข้าสู่ระบบ</button>
    </form>
    <div class="support-note">
      <strong>เบอร์ไม่ตรงหรือเปลี่ยนเบอร์?</strong>
      <p>ติดต่อผู้ดูแลหอพักเพื่อยืนยันตัวตน แก้ไขเบอร์ หรือขอรหัสเปิดใช้งานใหม่ การออกคีย์ใหม่จะยกเลิกเซสชันและรหัสผ่านเดิมทันที</p>
      <a class="button button-secondary button-full" href="#" data-public-support-line target="_blank" rel="noopener noreferrer" hidden>ติดต่อผู้ดูแลผ่าน LINE</a>
    </div>
  </section>
</main>
