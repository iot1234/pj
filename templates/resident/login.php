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
      <p>ใช้เบอร์โทรศัพท์ที่แจ้งไว้กับหอพักเพื่อเข้าใช้งานได้ทันที ไม่ต้องตั้งรหัสผ่าน</p>
    </div>

  </section>

  <section class="auth-card" aria-labelledby="resident-login-title">
    <div class="auth-card-heading">
      <a class="back-link" href="/">กลับหน้าห้องว่าง</a>
      <span class="eyebrow">Resident access</span>
      <h2 id="resident-login-title">เข้าสู่ระบบผู้เช่า</h2>
      <p>กรอกเบอร์ที่ผูกกับห้องของคุณ แล้วกดเข้าสู่ระบบ ไม่ต้องใช้รหัสผ่านหรือรหัสยืนยัน</p>
    </div>
    <form id="resident-login-form" class="stack-form" novalidate>
      <div class="field">
        <label for="resident-phone">เบอร์โทรศัพท์</label>
        <input id="resident-phone" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="081-234-5678" aria-describedby="resident-phone-help" required autofocus>
        <small id="resident-phone-help">ใช้ได้เฉพาะเบอร์ของผู้พักที่มีห้องใช้งานอยู่</small>
      </div>
      <div class="form-error" id="resident-login-error" role="alert" hidden></div>
      <button class="button button-primary button-large button-full" type="submit" data-submit-label="เข้าสู่ระบบ">เข้าสู่ระบบ</button>
    </form>
    <div class="support-note">
      <strong>เบอร์ไม่ตรงหรือเปลี่ยนเบอร์?</strong>
      <p>ติดต่อผู้ดูแลหอพักเพื่อตรวจเบอร์และห้อง ระบบเปิดให้เฉพาะผู้พักที่ยังเข้าอยู่ เมื่อเปลี่ยนเบอร์หรือย้ายออก เซสชันเดิมจะใช้ไม่ได้</p>
      <a class="button button-secondary button-full" href="#" data-public-support-line target="_blank" rel="noopener noreferrer" hidden>ติดต่อผู้ดูแลผ่าน LINE</a>
    </div>
  </section>
</main>
