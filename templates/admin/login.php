<?php declare(strict_types=1); ?>
<main id="main-content" class="auth-shell auth-admin">
  <section class="auth-aside" aria-labelledby="admin-welcome-title">
    <a class="brand brand-inverse" href="/">
      <span class="brand-mark" aria-hidden="true">H</span>
      <span><strong>หอพักของคุณ</strong><small>Admin Console</small></span>
    </a>
    <div class="auth-aside-copy">
      <span class="eyebrow">Secure administration</span>
      <h1 id="admin-welcome-title">จัดการห้อง ผู้เช่า และการเงินในที่เดียว</h1>
      <p>บัญชีผู้ดูแลได้รับการป้องกันด้วย session ฝั่งเซิร์ฟเวอร์ การจำกัดความถี่ และ CSRF token</p>
    </div>
    <ul class="auth-points">
      <li> ห้องและการจองอัปเดตจากฐานข้อมูลจริง</li>
      <li> ทุกการเปลี่ยนสถานะสำคัญถูกบันทึก</li>
      <li> ข้อมูลการชำระเงินจำกัดตามสิทธิ์</li>
    </ul>
  </section>

  <section class="auth-card" aria-labelledby="admin-login-title">
    <div class="auth-card-heading">
      <a class="back-link" href="/">กลับหน้าห้องว่าง</a>
      <span class="eyebrow">Administrator</span>
      <h2 id="admin-login-title">เข้าสู่ระบบผู้ดูแล</h2>
      <p>ใช้ชื่อผู้ใช้และรหัสผ่านของบัญชีผู้ดูแลระบบ</p>
    </div>
    <form id="admin-login-form" class="stack-form" novalidate>
      <div class="field">
        <label for="admin-username">ชื่อผู้ใช้</label>
        <input id="admin-username" name="username" type="text" minlength="3" maxlength="64" autocomplete="username" autocapitalize="none" required autofocus>
      </div>
      <div class="field">
        <label for="admin-password">รหัสผ่าน</label>
        <span class="password-field">
          <input id="admin-password" name="password" type="password" minlength="12" maxlength="200" autocomplete="current-password" required>
          <button class="password-toggle" type="button" data-password-toggle aria-label="แสดงรหัสผ่าน" aria-controls="admin-password" aria-pressed="false">แสดง</button>
        </span>
      </div>
      <div class="form-error" id="admin-login-error" role="alert" hidden></div>
      <button class="button button-primary button-large button-full" type="submit" data-submit-label="เข้าสู่ระบบ">เข้าสู่ระบบ</button>
    </form>
    <p class="auth-footnote">ระบบจะไม่บอกว่าชื่อผู้ใช้หรือรหัสผ่านส่วนใดผิด เพื่อป้องกันการเดาบัญชี</p>
  </section>
</main>
