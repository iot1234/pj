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
      <p>เข้าสู่ระบบด้วยเบอร์โทรศัพท์ที่ผู้ดูแลยืนยันและผูกกับห้องที่กำลังพักอยู่</p>
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
      <p>กรอกเบอร์โทรศัพท์ที่ลงทะเบียนไว้กับหอพัก</p>
    </div>
    <form id="resident-login-form" class="stack-form" novalidate>
      <label class="field">
        <span>เบอร์โทรศัพท์</span>
        <input name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="081-234-5678" required autofocus>
        <small>ใช้ได้เฉพาะเบอร์ของผู้พักที่มีห้องใช้งานอยู่</small>
      </label>
      <div class="form-error" id="resident-login-error" role="alert" hidden></div>
      <button class="button button-primary button-large button-full" type="submit" data-submit-label="เข้าสู่ระบบ">เข้าสู่ระบบ</button>
    </form>
    <div class="support-note">
      <strong>เบอร์ไม่ตรงหรือเปลี่ยนเบอร์?</strong>
      <p>ติดต่อผู้ดูแลหอพักเพื่อยืนยันตัวตนและแก้ไขเบอร์ ระบบจะยกเลิกเซสชันของเบอร์เดิมเมื่อบันทึกเบอร์ใหม่</p>
    </div>
  </section>
</main>
