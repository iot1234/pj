<?php declare(strict_types=1); ?>
<header class="site-header public-header">
  <a class="brand" href="/" aria-label="หน้าแรกระบบหอพัก">
    <span class="brand-mark" aria-hidden="true">H</span>
    <span>
      <strong>หอพักของคุณ</strong>
      <small>ห้องว่างและการจอง</small>
    </span>
  </a>
  <nav class="header-actions" aria-label="เมนูผู้ใช้งาน">
    <a class="button button-ghost" href="/resident/login">ผู้เช่าเข้าสู่ระบบ</a>
    <a class="button button-dark" href="/admin/login">ผู้ดูแลระบบ</a>
  </nav>
</header>

<main id="main-content" class="public-main">
  <section class="public-hero" aria-labelledby="public-title">
    <div class="hero-copy">
      <span class="eyebrow">Available rooms</span>
      <h1 id="public-title">ค้นหาห้องที่พร้อมเข้าอยู่</h1>
      <p>ดูราคา รูปภาพ และสิ่งอำนวยความสะดวกของห้องว่างจริง แล้วส่งคำขอจองได้ทันทีโดยไม่ต้องสมัครสมาชิก</p>
      <div class="hero-steps" aria-label="ขั้นตอนการจอง">
        <span><b>1</b> เลือกห้อง</span>
        <span><b>2</b> กรอกชื่อและเบอร์โทร</span>
        <span><b>3</b> รอผู้ดูแลยืนยัน</span>
      </div>
    </div>
    <div class="hero-stat" aria-live="polite">
      <span>ห้องว่างขณะนี้</span>
      <strong id="public-room-count">—</strong>
      <small>ข้อมูลอัปเดตจากระบบโดยตรง</small>
    </div>
  </section>

  <section class="content-section" aria-labelledby="rooms-title">
    <div class="section-heading">
      <div>
        <span class="eyebrow">Room directory</span>
        <h2 id="rooms-title">ห้องพักที่ว่าง</h2>
        <p>ระบบแสดงเฉพาะห้องสถานะ “ว่าง” เท่านั้น</p>
      </div>
    </div>

    <div class="filter-bar" aria-label="ตัวกรองห้องพัก">
      <label class="field field-search">
        <span>ค้นหาห้อง</span>
        <input id="public-room-search" type="search" placeholder="เลขห้องหรือประเภทห้อง" autocomplete="off">
      </label>
      <label class="field">
        <span>ประเภท</span>
        <select id="public-room-type">
          <option value="">ทุกประเภท</option>
        </select>
      </label>
      <label class="field">
        <span>ชั้น</span>
        <select id="public-room-floor">
          <option value="">ทุกชั้น</option>
        </select>
      </label>
      <button class="button button-ghost filter-reset" id="public-filter-reset" type="button">ล้างตัวกรอง</button>
    </div>

    <div class="alert alert-error" id="public-rooms-error" role="alert" hidden>
      <div>
        <strong>โหลดรายการห้องไม่สำเร็จ</strong>
        <p data-error-message>กรุณาตรวจสอบอินเทอร์เน็ตแล้วลองอีกครั้ง</p>
      </div>
      <button class="button button-small" id="public-rooms-retry" type="button">ลองใหม่</button>
    </div>

    <div class="room-grid" id="public-room-grid" aria-live="polite" aria-busy="true">
      <article class="room-card room-card-skeleton" aria-hidden="true"></article>
      <article class="room-card room-card-skeleton" aria-hidden="true"></article>
      <article class="room-card room-card-skeleton" aria-hidden="true"></article>
    </div>
    <div class="empty-state" id="public-rooms-empty" hidden>
      <span class="empty-icon" aria-hidden="true">⌂</span>
      <h3>ไม่พบห้องว่างที่ตรงกับตัวกรอง</h3>
      <p>ลองเปลี่ยนประเภท ชั้น หรือคำค้นหา</p>
    </div>
  </section>
</main>

<footer class="site-footer">
  <span>ระบบจองห้องพักอย่างปลอดภัย</span>
  <span>ข้อมูลผู้เช่าจะไม่แสดงต่อสาธารณะ</span>
</footer>

<template id="public-room-card-template">
  <article class="room-card">
    <div class="room-media">
      <img data-room-image alt="" loading="lazy" decoding="async">
      <span class="status-badge status-available">ว่าง</span>
      <span class="room-code" data-room-code></span>
    </div>
    <div class="room-card-body">
      <div class="room-card-title">
        <div>
          <span class="muted" data-room-floor></span>
          <h3 data-room-type></h3>
        </div>
        <strong class="room-price" data-room-rent></strong>
      </div>
      <p class="room-description" data-room-description></p>
      <ul class="amenity-list" data-room-amenities aria-label="สิ่งอำนวยความสะดวก"></ul>
      <button class="button button-primary button-full" type="button" data-book-room>จองห้องนี้</button>
    </div>
  </article>
</template>

<dialog class="app-dialog booking-dialog" id="booking-dialog" aria-labelledby="booking-dialog-title">
  <div class="dialog-panel">
    <div class="dialog-header">
      <div>
        <span class="eyebrow">Booking request</span>
        <h2 id="booking-dialog-title">ส่งคำขอจองห้อง</h2>
      </div>
      <button class="icon-button" type="button" data-close-dialog aria-label="ปิดหน้าต่าง">×</button>
    </div>

    <form id="public-booking-form" class="stack-form" novalidate>
      <div class="booking-room-summary">
        <img id="booking-room-image" alt="">
        <div>
          <span>ห้องที่เลือก</span>
          <strong id="booking-room-label">—</strong>
          <small id="booking-room-meta">—</small>
        </div>
      </div>
      <input id="booking-room-id" name="room_id" type="hidden">
      <input id="booking-idempotency" name="idempotency_key" type="hidden">

      <label class="field">
        <span>ชื่อ–นามสกุล <b aria-hidden="true">*</b></span>
        <input name="full_name" type="text" minlength="2" maxlength="120" autocomplete="name" required>
        <small>ใช้สำหรับให้ผู้ดูแลติดต่อกลับ</small>
      </label>
      <label class="field">
        <span>เบอร์โทรศัพท์ <b aria-hidden="true">*</b></span>
        <input name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="081-234-5678" required>
        <small>กรอกเบอร์มือถือไทยที่ติดต่อได้</small>
      </label>
      <div class="form-error" id="public-booking-error" role="alert" hidden></div>
      <div class="dialog-actions">
        <button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button>
        <button class="button button-primary" type="submit" data-submit-label="ส่งคำขอจอง">ส่งคำขอจอง</button>
      </div>
      <p class="form-privacy">เมื่อส่งคำขอ ห้องจะถูกกันไว้เป็น “จองแล้ว” และรอผู้ดูแลยืนยัน ข้อมูลของคุณไม่แสดงต่อสาธารณะ</p>
    </form>

    <div class="success-state" id="public-booking-success" hidden tabindex="-1">
      <span class="success-mark" aria-hidden="true">✓</span>
      <h3>รับคำขอจองเรียบร้อยแล้ว</h3>
      <p>ห้องถูกกันไว้แล้ว ผู้ดูแลจะติดต่อกลับตามเบอร์ที่แจ้ง</p>
      <div class="reference-box">
        <span>หมายเลขอ้างอิง</span>
        <strong id="booking-reference">—</strong>
      </div>
      <button class="button button-primary button-full" type="button" data-close-dialog>กลับไปดูห้อง</button>
    </div>
  </div>
</dialog>
