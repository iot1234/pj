<?php

declare(strict_types=1);

$adminName = (string) ($user['username'] ?? $user['name'] ?? 'ผู้ดูแลระบบ');
$adminRole = (string) ($user['role'] ?? 'admin');
$adminInitial = strtoupper(substr($adminName, 0, 1));
$canManageIntegrations = $adminRole === 'owner';
$integrationDisabled = ' disabled';
$businessToday = new DateTimeImmutable('today', new DateTimeZone((string) $appTimezone));
$maximumBillingPeriod = $businessToday->format('Y-m');
$maximumBillingDueDate = $businessToday->modify('+60 days')->format('Y-m-d');
?>
<div class="admin-shell" data-admin-app>
    <aside class="admin-sidebar" id="admin-sidebar" aria-label="เมนูจัดการ">
        <a class="admin-brand" href="/admin" aria-label="DormFlow หน้าหลักผู้ดูแล">
            <span class="brand-mark" aria-hidden="true">D</span>
            <span><strong>DormFlow</strong><small>ระบบจัดการหอพัก</small></span>
        </a>

        <nav class="admin-nav">
            <p class="admin-nav-label">จัดการหอพัก</p>
            <button class="admin-nav-item is-active" type="button" data-admin-nav="rooms" aria-current="page">
                <span aria-hidden="true">▦</span><span>ห้องพัก</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="bookings">
                <span aria-hidden="true">▣</span><span>การจอง</span><span class="nav-count" id="booking-nav-count" hidden>0</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="residents">
                <span aria-hidden="true">◎</span><span>ผู้พักอาศัย</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="meters">
                <span aria-hidden="true">∿</span><span>จดมิเตอร์</span>
            </button>

            <p class="admin-nav-label">การเงิน</p>
            <button class="admin-nav-item" type="button" data-admin-nav="bills">
                <span aria-hidden="true">฿</span><span>ใบแจ้งหนี้</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="payments">
                <span aria-hidden="true">✓</span><span>การชำระเงิน</span>
            </button>

            <p class="admin-nav-label">ระบบ</p>
            <button class="admin-nav-item owner-only" type="button" data-admin-nav="users" <?= $adminRole === 'owner' ? '' : 'hidden' ?>>
                <span aria-hidden="true">⚿</span><span>ผู้ดูแลระบบ</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="settings">
                <span aria-hidden="true">⚙</span><span>ตั้งค่า</span>
            </button>
        </nav>

        <div class="admin-sidebar-profile">
            <span class="avatar" aria-hidden="true"><?= e($adminInitial) ?></span>
            <span><strong><?= e($adminName) ?></strong><small><?= $adminRole === 'owner' ? 'เจ้าของระบบ' : 'ผู้ดูแลระบบ' ?></small></span>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <button class="icon-button admin-menu-toggle" type="button" aria-label="เปิดเมนู" aria-controls="admin-sidebar" aria-expanded="false" data-admin-menu-toggle>☰</button>
            <div>
                <p class="eyebrow">ภาพรวมการจัดการ</p>
                <h1 id="admin-page-title">ห้องพัก</h1>
            </div>
            <div class="admin-topbar-actions">
                <span class="live-indicator"><span aria-hidden="true"></span>เข้าสู่ระบบแล้ว</span>
                <button class="button button-ghost button-small" type="button" data-admin-logout>ออกจากระบบ</button>
            </div>
        </header>

        <main class="admin-content" id="main-content" tabindex="-1">
            <section class="admin-view is-active" data-admin-view="rooms" aria-labelledby="rooms-title">
                <div class="section-heading">
                    <div><p class="eyebrow">สถานะห้องแบบเรียลไทม์</p><h2 id="rooms-title">ห้องพักทั้งหมด</h2></div>
                    <button class="button button-primary" type="button" data-open-room-dialog>+  เพิ่มห้องพัก</button>
                </div>
                <div class="stats-grid stats-grid-four" id="room-stats" aria-live="polite">
                    <article class="stat-card"><span>ห้องทั้งหมด</span><strong data-room-stat="all">—</strong></article>
                    <article class="stat-card stat-available"><span>ว่าง</span><strong data-room-stat="available">—</strong></article>
                    <article class="stat-card stat-reserved"><span>รอเข้าพัก</span><strong data-room-stat="reserved">—</strong></article>
                    <article class="stat-card stat-occupied"><span>มีผู้พัก</span><strong data-room-stat="occupied">—</strong></article>
                </div>
                <div class="toolbar">
                    <label class="search-field"><span class="sr-only">ค้นหาห้อง</span><input type="search" id="admin-room-search" placeholder="ค้นหารหัสห้อง ชั้น หรือประเภท…"></label>
                    <label><span class="sr-only">สถานะห้อง</span><select id="admin-room-status"><option value="">ทุกสถานะ</option><option value="available">ว่าง</option><option value="reserved">รอเข้าพัก</option><option value="occupied">มีผู้พัก</option></select></label>
                </div>
                <div class="panel table-panel">
                    <div class="table-scroll"><table><thead><tr><th>ห้อง</th><th>ชั้น</th><th>ประเภท</th><th>ราคา/เดือน</th><th>สถานะ</th><th class="align-right">จัดการ</th></tr></thead><tbody id="admin-room-rows"></tbody></table></div>
                    <div class="table-state" id="admin-room-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดห้องพัก…</p></div>
                </div>
            </section>

            <section class="admin-view" data-admin-view="bookings" aria-labelledby="bookings-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">จัดการคำขอ</p><h2 id="bookings-title">การจองห้อง</h2></div></div>
                <div class="toolbar">
                    <label><span>สถานะ</span><select id="booking-status-filter"><option value="">ทั้งหมด</option><option value="pending">รอตรวจสอบ</option><option value="confirmed">ยืนยันแล้ว</option><option value="cancelled">ยกเลิก</option><option value="moved_in">เข้าพักแล้ว</option></select></label>
                    <button class="button button-secondary button-small" type="button" data-refresh="bookings">รีเฟรช</button>
                    <button class="button button-ghost button-small" id="booking-load-more" type="button" hidden>โหลดรายการเพิ่มเติม</button>
                </div>
                <div class="panel table-panel"><div class="table-scroll"><table><thead><tr><th>เลขที่</th><th>ผู้จอง</th><th>ห้อง</th><th>วันที่ขอ</th><th>สถานะ</th><th class="align-right">จัดการ</th></tr></thead><tbody id="booking-rows"></tbody></table></div><div class="table-state" id="booking-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดการจอง…</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="residents" aria-labelledby="residents-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">ข้อมูลผู้เช่าปัจจุบัน</p><h2 id="residents-title">ผู้พักอาศัย</h2></div><button class="button button-primary" type="button" data-open-resident-create>+&nbsp; เพิ่มผู้พักเข้าห้อง</button></div>
                <div class="toolbar"><label class="search-field"><span class="sr-only">ค้นหาผู้พัก</span><input id="resident-search" type="search" placeholder="ค้นหาชื่อ เบอร์โทร หรือห้อง…"></label></div>
                <div class="panel table-panel"><div class="table-scroll"><table><thead><tr><th>ผู้พัก</th><th>ห้อง</th><th>ติดต่อ</th><th>LINE</th><th>วันเข้าพัก</th><th>สถานะ</th><th class="align-right">จัดการ</th></tr></thead><tbody id="resident-rows"></tbody></table></div><div class="table-state" id="resident-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดผู้พัก…</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="meters" aria-labelledby="meters-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">บันทึกการใช้น้ำและไฟ</p><h2 id="meters-title">จดมิเตอร์รายเดือน</h2></div></div>
                <div class="toolbar toolbar-period"><label><span>รอบเดือน</span><input type="month" id="meter-period" max="<?= e($maximumBillingPeriod) ?>" required></label><p class="toolbar-note">ค่าที่กรอกต้องไม่น้อยกว่าครั้งก่อน</p></div>
                <div class="security-note meter-baseline-note" role="note"><strong>มิเตอร์ช่องที่ขึ้น “เดือนแรก · หน่วย 0”</strong><span>เลขที่กรอกครั้งแรกจะเป็นค่าตั้งต้น (baseline) และหน่วยของรอบนี้จะเป็น 0 เฉพาะมิเตอร์ช่องนั้น มิเตอร์อีกประเภทที่มีค่าก่อนหน้าแล้วจะคิดส่วนต่างตามปกติ</span></div>
                <div class="panel table-panel"><div class="table-scroll meter-table"><table><thead><tr><th rowspan="2">ห้อง</th><th colspan="3">มิเตอร์น้ำ</th><th colspan="3">มิเตอร์ไฟ</th><th rowspan="2" class="align-right">จัดการ</th></tr><tr><th>ก่อน</th><th>ปัจจุบัน</th><th>ใช้</th><th>ก่อน</th><th>ปัจจุบัน</th><th>ใช้</th></tr></thead><tbody id="meter-rows"></tbody></table></div><div class="table-state" id="meter-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดมิเตอร์…</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="bills" aria-labelledby="bills-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">ออกเอกสารจากค่ามิเตอร์</p><h2 id="bills-title">ใบแจ้งหนี้</h2></div></div>
                <form class="panel billing-builder" id="bill-builder-form">
                    <div class="security-note" id="billing-readiness-note"><strong>ตรวจสอบค่ารายเดือนก่อนออกบิล</strong><span>ระบบกำลังโหลดสถานะจากหน้า “ตั้งค่า”</span></div>
                    <div class="form-grid form-grid-four">
                        <label class="field"><span>รอบเดือน</span><input type="month" name="period" id="bill-period" max="<?= e($maximumBillingPeriod) ?>" required></label>
                        <label class="field"><span>ค่าน้ำ / หน่วย</span><input type="number" name="water_rate" id="bill-water-rate" min="0" step="0.01" required></label>
                        <label class="field"><span>ค่าไฟ / หน่วย</span><input type="number" name="electric_rate" id="bill-electric-rate" min="0" step="0.01" required></label>
                        <label class="field"><span>กำหนดชำระ</span><input type="date" name="due_date" id="bill-due-date" max="<?= e($maximumBillingDueDate) ?>" required></label>
                        <label class="field form-span-two"><span>รายการอื่น (ไม่บังคับ)</span><input type="text" name="other_description" maxlength="120" placeholder="เช่น ค่าทำความสะอาด"></label>
                        <label class="field"><span>จำนวนเงินอื่น / ห้อง</span><input type="number" name="other_amount" min="0" step="0.01" value="0"><small>จำนวนนี้จะเพิ่มให้ทุกห้องที่เลือก ไม่ใช่ยอดรวมของทุกห้อง</small></label>
                    </div>
                    <fieldset class="room-selector"><legend>เลือกห้องที่จะออกบิล</legend><label class="check-field"><input type="checkbox" id="select-all-bill-rooms"><span>เลือกทุกห้อง</span></label><div class="room-check-grid" id="bill-room-options"><span class="muted">เลือกรอบเดือนเพื่อโหลดห้อง</span></div></fieldset>
                    <p class="form-error" id="bill-builder-error" role="alert" hidden></p>
                    <div class="form-actions"><button class="button button-secondary" type="button" id="preview-bills-button">ตรวจยอดก่อน</button><button class="button button-primary" type="submit">ออกบิลที่เลือก</button></div>
                </form>
                <div class="section-subheading"><h3>บิลในรอบเดือน</h3><button class="button button-secondary button-small" type="button" id="line-bulk-button" disabled>เข้าคิว LINE ทั้งหมด</button></div>
                <div class="panel table-panel"><div class="table-scroll"><table><thead><tr><th>เลขที่บิล</th><th>ห้อง</th><th>ผู้พัก</th><th>ยอดรวม</th><th>กำหนดชำระ</th><th>สถานะ</th><th class="align-right">LINE</th></tr></thead><tbody id="bill-admin-rows"></tbody></table></div><div class="table-state" id="bill-admin-state" data-state="idle"><p>เลือกรอบเดือนเพื่อดูบิล</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="payments" aria-labelledby="payments-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">หลักฐานการโอนจากผู้พัก</p><h2 id="payments-title">การชำระเงิน</h2></div></div>
                <div class="toolbar"><label><span>สถานะตรวจสอบ</span><select id="payment-status-filter"><option value="">ทั้งหมด (รายการค้างก่อน)</option><option value="pending">รอตรวจ</option><option value="verified">ยืนยันแล้ว</option><option value="rejected">ปฏิเสธสลิป</option></select></label><button class="button button-secondary button-small" type="button" data-refresh="payments">รีเฟรช</button><button class="button button-ghost button-small" id="payment-load-more" type="button" hidden>โหลดรายการเพิ่มเติม</button></div>
                <div class="security-note"><strong>ระบบไม่อนุญาตให้กดยืนยันยอดด้วยมือ</strong><span>รายการจะเป็น “ชำระแล้ว” ต่อเมื่อผู้ให้บริการตรวจสลิปผ่านเท่านั้น หากรายการค้าง ให้ดูหลักฐานแล้วเลือกตรวจซ้ำหรือปิดเพื่อให้ผู้พักส่งสลิปใหม่</span></div>
                <div class="panel table-panel"><div class="table-scroll"><table><thead><tr><th>อัปโหลดเมื่อ</th><th>บิล / ห้อง</th><th>ผู้พัก</th><th>ยอดเงิน</th><th>ผลตรวจ</th><th class="align-right">จัดการ</th></tr></thead><tbody id="payment-rows"></tbody></table></div><div class="table-state" id="payment-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดการชำระ…</p></div></div>
            </section>

            <section class="admin-view owner-only" data-admin-view="users" aria-labelledby="users-title" <?= $adminRole === 'owner' ? 'hidden' : 'hidden' ?>>
                <div class="section-heading"><div><p class="eyebrow">เฉพาะเจ้าของระบบ</p><h2 id="users-title">ผู้ดูแลระบบ</h2></div><button class="button button-primary" type="button" data-open-user-dialog>+  เพิ่มผู้ดูแล</button></div>
                <div class="security-note"><strong>สิทธิ์การเข้าถึง</strong><span>เจ้าของ (Owner) จัดการบัญชีได้ ผู้ดูแล (Admin) ใช้งานโมดูลหอพักและการเงิน</span></div>
                <div class="panel table-panel"><div class="table-scroll"><table><thead><tr><th>ชื่อผู้ใช้</th><th>บทบาท</th><th>สถานะ</th><th>แก้ไขล่าสุด</th><th class="align-right">จัดการ</th></tr></thead><tbody id="user-rows"></tbody></table></div><div class="table-state" id="user-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดผู้ดูแล…</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="settings" aria-labelledby="settings-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">การเรียกเก็บเงินและบริการภายนอก</p><h2 id="settings-title">ตั้งค่าระบบ</h2></div></div>
                <div class="settings-grid">
                    <form class="panel settings-card" id="settings-form"><div class="panel-heading"><div><h3>การเรียกเก็บรายเดือน</h3><p>ต้องตรวจสอบและบันทึกอย่างน้อยหนึ่งครั้งก่อนออกบิลจริง</p></div></div><div class="form-grid"><label class="field"><span>ค่าน้ำต่อหน่วย (บาท)</span><input type="number" name="water_rate" min="0" step="0.01" required></label><label class="field"><span>ค่าไฟต่อหน่วย (บาท)</span><input type="number" name="electric_rate" min="0" step="0.01" required></label><label class="field"><span>ระยะเวลาชำระ (วัน)</span><input type="number" name="due_days" min="1" max="60" step="1" required><small>จำนวนวันจากวันออกบิลถึงวันครบกำหนด</small></label></div><p class="field-hint" id="billing-settings-status">กำลังโหลดสถานะ…</p><p class="form-error" id="settings-error" role="alert" hidden></p><div class="form-actions"><button class="button button-primary" type="submit">ยืนยันและบันทึกการตั้งค่า</button></div></form>
                    <form class="panel settings-card settings-integration-card" id="integration-settings-form" data-owner-only="<?= $canManageIntegrations ? 'true' : 'false' ?>">
                        <div class="panel-heading"><div><h3>พร้อมเพย์, LINE Bot และตรวจสลิป</h3><p>ค่าลับถูกเข้ารหัสในฐานข้อมูลและจะไม่แสดงค่าจริงกลับมาบนหน้าเว็บ</p></div></div>
                        <div class="security-note"><strong>กุญแจระบบ</strong><span>ช่องค่าลับที่เว้นว่างจะเก็บค่าเดิม เลือก “ล้างค่า” เมื่อต้องการยกเลิกจริง การเปลี่ยนส่วนนี้ทำได้เฉพาะเจ้าของระบบ</span></div>
                        <div class="integration-form-grid">
                            <fieldset class="integration-fieldset">
                                <legend><span class="integration-icon">฿</span> PromptPay QR</legend>
                                <label class="field"><span>เบอร์พร้อมเพย์ / เลขผู้เสียภาษี</span><input name="promptpay_target" type="text" inputmode="numeric" maxlength="13" pattern="(?:0[0-9]{9}|[0-9]{13})" placeholder="0812345678"<?= $integrationDisabled ?>><small>ใช้สร้าง QR ตามยอดบิล</small></label>
                                <label class="field"><span>ชื่อบัญชีที่แสดง</span><input name="promptpay_name" type="text" maxlength="120" placeholder="ชื่อผู้รับเงิน"<?= $integrationDisabled ?>></label>
                                <div class="integration-status-row"><div class="integration-status-copy"><span>ความพร้อมของค่าที่บันทึก</span><small class="integration-test-result" data-integration-test-result="promptpay">ยังไม่ได้ทดสอบค่าที่บันทึกนี้</small></div><div class="integration-status-actions"><span class="status-pill status-neutral" data-integration-status="promptpay">กำลังโหลด</span><button class="button button-small button-secondary" type="button" data-test-integration="promptpay" disabled>สุ่มยอดและแสดง QR ทดสอบ</button></div></div>
                            </fieldset>

                            <fieldset class="integration-fieldset">
                                <legend><span class="integration-icon">L</span> LINE Messaging API</legend>
                                <label class="field"><span>Channel access token</span><input name="line_channel_access_token" type="password" maxlength="4096" autocomplete="new-password" placeholder="เว้นว่างเพื่อเก็บค่าเดิม"<?= $integrationDisabled ?>><small data-secret-status="line_channel_access_token">ยังไม่ได้โหลดสถานะ</small></label>
                                <label class="check-field danger-check"><input name="line_channel_access_token_clear" type="checkbox" value="1"<?= $integrationDisabled ?>><span>ล้าง Channel access token ที่บันทึกไว้</span></label>
                                <label class="field"><span>Channel secret</span><input name="line_channel_secret" type="password" maxlength="4096" autocomplete="new-password" placeholder="เว้นว่างเพื่อเก็บค่าเดิม"<?= $integrationDisabled ?>><small data-secret-status="line_channel_secret">ยังไม่ได้โหลดสถานะ</small></label>
                                <label class="check-field danger-check"><input name="line_channel_secret_clear" type="checkbox" value="1"<?= $integrationDisabled ?>><span>ล้าง Channel secret ที่บันทึกไว้</span></label>
                                <label class="field"><span>Webhook URL</span><input type="url" data-line-webhook-url readonly value=""><small data-line-webhook-readiness>ต้องตั้ง Token และ Channel secret ให้ครบ แล้วเปิด Use webhook และ Webhook redelivery ใน LINE Developers Console</small></label>
                                <div class="form-grid form-grid-two"><label class="field"><span>ลองส่งสูงสุด (ครั้ง)</span><input name="line_max_attempts" type="number" min="1" max="20" step="1" required<?= $integrationDisabled ?>></label><label class="field"><span>จำนวนงานต่อรอบ</span><input name="notification_batch_size" type="number" min="1" max="100" step="1" required<?= $integrationDisabled ?>></label></div>
                                <div class="integration-status-row"><div class="integration-status-copy"><span>ความพร้อมของค่าที่บันทึก</span><small class="integration-test-result" data-integration-test-result="line">ยังไม่ได้ทดสอบค่าที่บันทึกนี้</small></div><div class="integration-status-actions"><span class="status-pill status-neutral" data-integration-status="line">กำลังโหลด</span><button class="button button-small button-secondary" type="button" data-test-integration="line" disabled>ทดสอบ Token ที่บันทึก</button></div></div>
                            </fieldset>

                            <fieldset class="integration-fieldset integration-fieldset-wide">
                                <legend><span class="integration-icon">✓</span> ตรวจสลิปอัตโนมัติ</legend>
                                <div class="form-grid form-grid-two">
                                     <label class="field"><span>ผู้ให้บริการ</span><select name="slip_provider" aria-describedby="slip-provider-help"<?= $integrationDisabled ?>><option value="none">ยังไม่เปิดใช้</option><option value="slipok">SlipOK</option><option value="easyslip">EasySlip</option></select><small id="slip-provider-help" aria-live="polite">เลือกผู้ให้บริการเพื่อแสดงเฉพาะช่องที่ต้องกรอก</small></label>
                                     <label class="field"><span>เลขบัญชีปลายทาง/เลขท้าย</span><input name="payment_receiver_account_tail" type="text" inputmode="numeric" minlength="6" maxlength="20" pattern="[0-9]{6,20}" placeholder="อย่างน้อย 6 หลัก"<?= $integrationDisabled ?>><small>ใช้เทียบผู้รับบนสลิป ไม่ใช่เบอร์พร้อมเพย์ และต้องตรงอย่างน้อย 6 หลัก</small></label>
                                    <label class="field" data-slip-provider-field="slipok"><span>SlipOK Branch ID</span><input name="slipok_branch_id" type="text" maxlength="80" pattern="[A-Za-z0-9_-]{1,80}" placeholder="จำเป็นเมื่อเลือก SlipOK"<?= $integrationDisabled ?>></label>
                                    <label class="field" data-slip-provider-field="slipok"><span>SlipOK API Key</span><input name="slipok_api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="เว้นว่างเพื่อเก็บค่าเดิม"<?= $integrationDisabled ?>><small data-secret-status="slipok_api_key">ยังไม่ได้โหลดสถานะ</small></label>
                                    <label class="field" data-slip-provider-field="easyslip"><span>EasySlip API Key</span><input name="easyslip_api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="เว้นว่างเพื่อเก็บค่าเดิม"<?= $integrationDisabled ?>><small data-secret-status="easyslip_api_key">ยังไม่ได้โหลดสถานะ</small></label>
                                    <label class="check-field danger-check" data-slip-provider-field="slipok"><input name="slipok_api_key_clear" type="checkbox" value="1"<?= $integrationDisabled ?>><span>ล้าง SlipOK API Key</span></label>
                                    <label class="check-field danger-check" data-slip-provider-field="easyslip"><input name="easyslip_api_key_clear" type="checkbox" value="1"<?= $integrationDisabled ?>><span>ล้าง EasySlip API Key</span></label>
                                    <label class="field"><span>ขนาดสลิปสูงสุด</span><select name="slip_max_bytes" required<?= $integrationDisabled ?>><option value="262144">256 KiB</option><option value="524288">512 KiB</option><option value="1048576">1 MiB</option><option value="2097152">2 MiB</option><option value="3145728">3 MiB</option><option value="4194304">4 MiB</option></select><small>หน้าอัปโหลดของผู้พักจะใช้ค่านี้อัตโนมัติ</small></label>
                                    <label class="field"><span>ช่วงเผื่อเวลา (วินาที)</span><input name="slip_time_tolerance_seconds" type="number" min="0" max="3600" step="1" required<?= $integrationDisabled ?>></label>
                                </div>
                                <div class="integration-status-row"><div class="integration-status-copy"><span>ความพร้อมของค่าที่บันทึก</span><small class="integration-test-result" data-integration-test-result="slip">ยังไม่ได้ตรวจ credential ที่บันทึกนี้</small></div><div class="integration-status-actions"><span class="status-pill status-neutral" data-integration-status="slip">กำลังโหลด</span><button class="button button-small button-secondary" type="button" data-test-integration="slip" disabled>ตรวจ API Key ที่บันทึก</button></div></div>
                            </fieldset>
                        </div>
                        <p class="form-error" id="integration-settings-error" role="alert" hidden></p>
                        <?php if ($canManageIntegrations): ?>
                            <div class="form-actions"><button class="button button-primary" type="submit" data-integration-save disabled>บันทึกการเชื่อมต่อ</button></div>
                        <?php else: ?>
                            <p class="muted">บัญชีผู้ดูแลทั่วไปดูสถานะได้ แต่มีเฉพาะเจ้าของระบบที่เปลี่ยนค่านี้ได้</p>
                        <?php endif; ?>
                    </form>
                </div>
            </section>
        </main>
    </div>
</div>

<dialog class="modal" id="promptpay-test-dialog" aria-labelledby="promptpay-test-title">
    <div class="modal-card promptpay-test-card">
        <div class="modal-header"><div><p class="eyebrow">ทดสอบค่าที่บันทึก</p><h2 id="promptpay-test-title">PromptPay QR ทดสอบ</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div>
        <div class="security-note promptpay-transfer-warning" role="alert"><strong>QR นี้ชี้บัญชีจริง</strong><span>ไม่มีโหมด sandbox และไม่หมดอายุอัตโนมัติ สแกนเพื่อตรวจชื่อผู้รับและยอดเท่านั้น แล้วกดยกเลิกในแอปธนาคาร ห้ามกดยืนยันโอน เพราะเงินจะถูกโอนจริง</span></div>
        <div class="qr-stage promptpay-test-qr-stage" id="promptpay-test-qr-stage" aria-live="polite"><div class="qr-placeholder">กดสุ่มยอดจากหน้าตั้งค่าเพื่อสร้าง QR ทดสอบ</div></div>
        <p class="field-hint">การทดสอบนี้สร้าง QR ในระบบเท่านั้น ไม่สร้างบิล ไม่สร้างรายการชำระ และไม่ส่งข้อมูลไปยังผู้ให้บริการตรวจสลิป</p>
        <div class="form-actions"><button class="button button-primary" type="button" data-close-dialog>ตรวจแล้วและปิด</button></div>
    </div>
</dialog>

<dialog class="modal" id="room-dialog" aria-labelledby="room-dialog-title">
    <form class="modal-card modal-card-wide" id="room-form">
        <input type="hidden" name="id">
        <div class="modal-header"><div><p class="eyebrow">จัดการข้อมูลหลัก</p><h2 id="room-dialog-title">เพิ่มห้องพัก</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div>
        <div class="form-grid form-grid-two"><label class="field"><span>รหัสห้อง</span><input name="room_code" type="text" maxlength="30" required autocomplete="off"></label><label class="field"><span>ชั้น</span><input name="floor" type="number" min="1" max="200" step="1" required></label><label class="field"><span>ประเภทห้อง</span><input name="room_type" type="text" maxlength="50" required></label><label class="field"><span>ค่าเช่ารายเดือน</span><input name="monthly_rent" type="number" min="0.01" step="0.01" required></label><label class="field form-span-two"><span>รูปห้อง</span><select name="image_key" required><option value="room-standard.jpg">ห้องมาตรฐาน</option><option value="room-deluxe.jpg">ห้องดีลักซ์</option><option value="room-suite.jpg">ห้องสวีท</option><option value="room-studio.jpg">ห้องสตูดิโอ</option></select></label><label class="field form-span-two"><span>สิ่งอำนวยความสะดวก</span><input name="amenities" type="text" maxlength="500" placeholder="คั่นด้วยจุลภาค เช่น แอร์, ตู้เย็น, เตียง"></label><label class="field form-span-two"><span>รายละเอียด</span><textarea name="description" rows="3" maxlength="1000"></textarea></label></div>
        <p class="form-error" id="room-form-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">บันทึกห้อง</button></div>
    </form>
</dialog>

<dialog class="modal" id="move-in-dialog" aria-labelledby="move-in-title">
    <form class="modal-card" id="move-in-form"><input type="hidden" name="booking_id"><div class="modal-header"><div><p class="eyebrow">เปิดบัญชีผู้พัก</p><h2 id="move-in-title">รับเข้าพัก</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div><p class="modal-lead" id="move-in-summary"></p><div class="form-grid"><label class="field"><span>อีเมล (ไม่บังคับ)</span><input name="email" type="email" maxlength="254" autocomplete="email"></label><label class="field"><span>วันที่เข้าพัก</span><input name="move_in_date" type="date" min="2000-01-01" required></label></div><p class="field-hint">ผู้พักเข้าสู่ระบบด้วยเบอร์ที่ยืนยันในใบจองนี้ และสามารถผูก LINE ได้เองโดยยืนยันรหัสที่ส่งไปยังบัญชี LINE</p><label class="check-field" id="move-in-reuse-field" hidden><input name="reuse_resident_id" type="checkbox" disabled><span id="move-in-reuse-label">ยืนยันการเชื่อมบัญชีผู้พักเดิม</span></label><p class="field-hint" id="move-in-reuse-help" hidden>เลือกเฉพาะเมื่อยืนยันแล้วว่าเป็นบุคคลเดิม ประวัติบิลเก่าจะถูกเชื่อมกับบัญชีนี้ หากเป็นคนละคนต้องใช้เบอร์โทรอื่นเพื่อปกป้องข้อมูลส่วนบุคคล</p><p class="form-error" id="move-in-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">ยืนยันเข้าพัก</button></div></form>
</dialog>

<dialog class="modal" id="booking-cancel-dialog" aria-labelledby="booking-cancel-title">
    <form class="modal-card" id="booking-cancel-form"><input type="hidden" name="booking_id"><div class="modal-header"><div><p class="eyebrow">บันทึกการตัดสินใจ</p><h2 id="booking-cancel-title">ยกเลิกการจอง</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div><p class="modal-lead" id="booking-cancel-summary"></p><div class="form-grid"><label class="field"><span>เหตุผล (ไม่บังคับ)</span><textarea name="reason" minlength="3" maxlength="500" rows="4" placeholder="เช่น ผู้จองขอยกเลิก หรือไม่สามารถติดต่อได้"></textarea><small>หากระบุ กรุณากรอกอย่างน้อย 3 ตัวอักษร เพื่อใช้ตรวจสอบย้อนหลัง</small></label></div><p class="form-error" id="booking-cancel-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>กลับ</button><button class="button button-danger" type="submit">ยืนยันยกเลิกการจอง</button></div></form>
</dialog>

<dialog class="modal" id="resident-create-dialog" aria-labelledby="resident-create-title">
    <form class="modal-card modal-card-wide" id="resident-create-form">
        <input type="hidden" name="idempotency_key">
        <div class="modal-header"><div><p class="eyebrow">ผู้พักหลัก / ผู้ถือบัญชีของห้อง</p><h2 id="resident-create-title">เพิ่มผู้พักเข้าห้อง</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div>
        <div class="security-note"><strong>หนึ่งห้องมีผู้พักหลักได้ครั้งละ 1 คน</strong><span>ระบบจะสร้างหลักฐานรับเข้าพักและผูกประวัติบิลกับบุคคลนี้ทันที ผู้พักเข้าสู่ระบบด้วยเบอร์โทร จึงต้องตรวจตัวตนและเบอร์ให้ถูกต้องก่อนบันทึก</span></div>
        <div class="form-grid form-grid-two">
            <label class="field"><span>ห้องว่าง</span><select name="room_id" required></select><small id="resident-create-room-help">แสดงเฉพาะห้องที่ระบบตรวจว่าไม่มีผู้จองหรือผู้พัก</small></label>
            <label class="field"><span>วันที่เข้าพัก</span><input name="move_in_date" type="date" min="2000-01-01" required></label>
            <label class="field"><span>ชื่อ–นามสกุล</span><input name="full_name" type="text" minlength="1" maxlength="150" required autocomplete="name"></label>
            <label class="field"><span>เบอร์โทรศัพท์สำหรับเข้าสู่ระบบ</span><input name="phone" type="tel" minlength="10" maxlength="20" required autocomplete="tel" placeholder="0812345678"></label>
            <label class="field form-span-two"><span>อีเมล (ไม่บังคับ)</span><input name="email" type="email" maxlength="190" autocomplete="email"></label>
        </div>
        <label class="check-field" id="resident-create-reuse-field" hidden><input name="reuse_resident_id" type="checkbox" disabled><span id="resident-create-reuse-label">ยืนยันการเชื่อมบัญชีผู้พักเดิม</span></label>
        <p class="field-hint" id="resident-create-reuse-help" hidden>เลือกเฉพาะเมื่อยืนยันแล้วว่าเป็นบุคคลเดิม ประวัติบิลเก่าจะถูกเชื่อมกับบัญชีนี้ หากเป็นคนละคนต้องใช้เบอร์อื่นเพื่อปกป้องข้อมูลส่วนบุคคล</p>
        <p class="form-error" id="resident-create-error" role="alert" hidden></p>
        <div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">ยืนยันและรับเข้าพัก</button></div>
    </form>
</dialog>

<dialog class="modal" id="resident-edit-dialog" aria-labelledby="resident-edit-title">
    <form class="modal-card" id="resident-edit-form"><input type="hidden" name="resident_id"><div class="modal-header"><div><p class="eyebrow">ข้อมูลผู้พักที่ยืนยันแล้ว</p><h2 id="resident-edit-title">แก้ข้อมูลผู้พัก</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div><p class="modal-lead" id="resident-edit-summary"></p><div class="security-note"><strong>เบอร์โทรคือข้อมูลเข้าสู่ระบบของผู้พัก</strong><span>ตรวจสอบตัวตนก่อนเปลี่ยนเบอร์ เมื่อบันทึกแล้วระบบจะยกเลิกเซสชันเดิมทันที และผู้พักต้องเข้าสู่ระบบด้วยเบอร์ใหม่</span></div><div class="form-grid form-grid-two"><label class="field"><span>ชื่อ–นามสกุล</span><input name="full_name" type="text" minlength="1" maxlength="150" required autocomplete="name"></label><label class="field"><span>เบอร์โทรศัพท์</span><input name="phone" type="tel" minlength="10" maxlength="20" required autocomplete="tel"></label><label class="field"><span>อีเมล</span><input name="email" type="email" maxlength="190" autocomplete="email"></label></div><p class="field-hint">LINE User ID เปลี่ยนได้จากบัญชีผู้พักเท่านั้น และต้องยืนยันรหัสที่ส่งผ่าน LINE</p><p class="form-error" id="resident-edit-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">บันทึกข้อมูล</button></div></form>
</dialog>

<dialog class="modal" id="resident-move-out-dialog" aria-labelledby="resident-move-out-title">
    <form class="modal-card" id="resident-move-out-form"><input type="hidden" name="resident_id"><div class="modal-header"><div><p class="eyebrow">สิ้นสุดการเข้าพัก</p><h2 id="resident-move-out-title">ย้ายผู้พักออก</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div><p class="modal-lead" id="resident-move-out-summary"></p><div class="security-note"><strong>ตรวจบิลปิดรอบก่อนย้ายออก</strong><span>ต้องไม่มีบิลค้าง และต้องมีบิลของเดือนที่ย้ายออกซึ่งชำระแล้ว กรุณาจดมิเตอร์ปลายงวดและออกบิลให้ครบก่อนดำเนินการ</span></div><div class="form-grid"><label class="field"><span>วันที่ย้ายออก</span><input name="move_out_date" type="date" required></label></div><p class="form-error" id="resident-move-out-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-danger" type="submit">ยืนยันย้ายออก</button></div></form>
</dialog>

<dialog class="modal" id="payment-close-dialog" aria-labelledby="payment-close-title">
    <form class="modal-card" id="payment-close-form"><input type="hidden" name="payment_id"><div class="modal-header"><div><p class="eyebrow">กู้รายการตรวจสลิปค้าง</p><h2 id="payment-close-title">ปิดรายการชำระนี้</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div><p class="modal-lead" id="payment-close-summary"></p><div class="security-note"><strong>การปิดรายการไม่ทำให้บิลเป็นชำระแล้ว</strong><span>หลักฐานนี้จะถูกปฏิเสธและเก็บไว้ตรวจสอบ ผู้พักจึงสามารถส่งสลิปใหม่ได้</span></div><div class="form-grid"><label class="field"><span>เหตุผล</span><textarea name="reason" minlength="3" maxlength="450" rows="4" required placeholder="เช่น ผู้ให้บริการไม่ตอบสนองหลังตรวจซ้ำแล้ว"></textarea></label></div><p class="form-error" id="payment-close-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-danger" type="submit">ปิดและให้ส่งสลิปใหม่</button></div></form>
</dialog>

<dialog class="modal" id="user-dialog" aria-labelledby="user-dialog-title">
    <form class="modal-card" id="user-form"><input type="hidden" name="id"><div class="modal-header"><div><p class="eyebrow">เฉพาะ Owner</p><h2 id="user-dialog-title">เพิ่มผู้ดูแล</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div><div class="form-grid"><label class="field"><span>ชื่อผู้ใช้</span><input name="username" type="text" minlength="3" maxlength="64" required autocomplete="username"></label><label class="field"><span>รหัสผ่าน</span><input name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password"><small id="user-password-help">อย่างน้อย 12 ตัวอักษร</small></label><label class="field"><span>บทบาท</span><select name="role" required><option value="admin">ผู้ดูแล (Admin)</option><option value="owner">เจ้าของ (Owner)</option></select></label><label class="check-field"><input name="is_active" type="checkbox" value="1" checked><span>เปิดใช้งานบัญชี</span></label></div><p class="form-error" id="user-form-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">บันทึกผู้ดูแล</button></div></form>
</dialog>

<dialog class="modal" id="preview-dialog" aria-labelledby="preview-title">
    <div class="modal-card modal-card-wide"><div class="modal-header"><div><p class="eyebrow">ยังไม่สร้างบิล</p><h2 id="preview-title">ตรวจยอดก่อนออกบิล</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="ปิด">×</button></div><div class="preview-list" id="bill-preview-content"></div><div class="form-actions"><button class="button button-primary" type="button" data-close-dialog>ตรวจแล้ว</button></div></div>
</dialog>

<dialog class="modal confirm-modal" id="confirm-dialog" aria-labelledby="confirm-title">
    <div class="modal-card"><div class="confirm-icon" aria-hidden="true">!</div><h2 id="confirm-title">ยืนยันการทำรายการ</h2><p id="confirm-message"></p><div class="form-actions"><button class="button button-ghost" type="button" data-confirm-cancel>ยกเลิก</button><button class="button button-danger" type="button" data-confirm-accept>ยืนยัน</button></div></div>
</dialog>
