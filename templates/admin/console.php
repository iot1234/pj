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
            <p class="admin-nav-label">สรุปประจำวัน</p>
            <button class="admin-nav-item is-active" type="button" data-admin-nav="overview" aria-current="page">
                <span>ภาพรวม</span>
            </button>

            <p class="admin-nav-label">จัดการหอพัก</p>
            <button class="admin-nav-item" type="button" data-admin-nav="rooms">
                <span>ห้องพัก</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="bookings">
                <span>การจอง</span><span class="nav-count" id="booking-nav-count" hidden>0</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="residents">
                <span>ผู้พักอาศัย</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="meters">
                <span>จดมิเตอร์</span>
            </button>

            <p class="admin-nav-label">การเงิน</p>
            <button class="admin-nav-item" type="button" data-admin-nav="bills">
                <span>ใบแจ้งหนี้</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="payments">
                <span>การชำระเงิน</span><span class="nav-count" id="payment-nav-count" hidden>0</span>
            </button>

            <p class="admin-nav-label">LINE</p>
            <button class="admin-nav-item" type="button" data-admin-nav="line-oas"><span>บัญชี LINE OA</span></button>
            <button class="admin-nav-item" type="button" data-admin-nav="line-bindings"><span>การผูก LINE ผู้พัก</span></button>
            <p class="admin-nav-label">ระบบ</p>
            <button class="admin-nav-item owner-only" type="button" data-admin-nav="users" <?= $adminRole === 'owner' ? '' : 'hidden' ?>>
                <span>ผู้ดูแลระบบ</span>
            </button>
            <button class="admin-nav-item" type="button" data-admin-nav="settings">
                <span>ตั้งค่า</span>
            </button>
        </nav>

        <div class="admin-sidebar-profile">
            <span class="avatar" aria-hidden="true"><?= e($adminInitial) ?></span>
            <span><strong><?= e($adminName) ?></strong><small><?= $adminRole === 'owner' ? 'เจ้าของระบบ' : 'ผู้ดูแลระบบ' ?></small></span>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <button class="text-control admin-menu-toggle" type="button" aria-label="เปิดเมนู" aria-controls="admin-sidebar" aria-expanded="false" data-admin-menu-toggle>เมนู</button>
            <div>
                <p class="eyebrow">ภาพรวมการจัดการ</p>
                <h1 id="admin-page-title">ภาพรวม</h1>
            </div>
            <div class="admin-topbar-actions">
                <span class="live-indicator">เข้าสู่ระบบแล้ว</span>
                <button class="button button-ghost button-small" type="button" data-admin-logout>ออกจากระบบ</button>
            </div>
        </header>

        <main class="admin-content" id="main-content" tabindex="-1">
            <section class="admin-view is-active" data-admin-view="overview" aria-labelledby="overview-title">
                <div class="section-heading">
                    <div><p class="eyebrow">สรุปสถานะวันนี้</p><h2 id="overview-title">วันนี้ต้องทำอะไรบ้าง</h2><p>กดการ์ดเพื่อไปยังหน้าที่เกี่ยวข้องได้ทันที</p></div>
                    <div class="section-heading-actions"><button class="button button-secondary" type="button" data-refresh="overview">รีเฟรช</button></div>
                </div>
                <div class="alert alert-error" id="overview-error" role="alert" hidden>
                    <div><strong>โหลดภาพรวมได้ไม่ครบ</strong><p data-error-message></p></div>
                    <button class="button button-small" type="button" data-refresh="overview">ลองใหม่</button>
                </div>
                <div class="stats-grid stats-grid-four" id="overview-stats" aria-live="polite">
                    <button class="stat-card stat-action" type="button" data-overview-jump="bookings"><span>การจองรอยืนยัน</span><strong data-overview-stat="bookings">—</strong><small data-overview-note="bookings">กำลังโหลด…</small></button>
                    <button class="stat-card stat-action" type="button" data-overview-jump="payments"><span>สลิปรอตรวจ</span><strong data-overview-stat="payments">—</strong><small data-overview-note="payments">กำลังโหลด…</small></button>
                    <button class="stat-card stat-action" type="button" data-overview-jump="rooms"><span>ห้องว่าง</span><strong data-overview-stat="rooms">—</strong><small data-overview-note="rooms">กำลังโหลด…</small></button>
                    <button class="stat-card stat-action" type="button" data-overview-jump="bills"><span>ยอดค้างชำระรอบนี้</span><strong data-overview-stat="outstanding">—</strong><small data-overview-note="outstanding">กำลังโหลด…</small></button>
                </div>
                <div class="overview-grid">
                    <section class="panel overview-panel" aria-labelledby="overview-month-title">
                        <div class="panel-heading">
                            <div><h3 id="overview-month-title">งานรอบเดือน <span id="overview-period">—</span></h3><p>ลำดับงาน: จดมิเตอร์ / ตรวจยอด / ออกบิล / ตามการชำระ</p></div>
                        </div>
                        <ul class="overview-tasks">
                            <li class="overview-task">
                                <div class="overview-task-head"><span>จดมิเตอร์</span><strong data-overview-task="meters">—</strong></div>
                                <span class="overview-progress"><span data-overview-bar="meters"></span></span>
                                <small data-overview-task-note="meters">กำลังโหลด…</small>
                                <button class="button button-small button-secondary" type="button" data-overview-jump="meters">ไปจดมิเตอร์</button>
                            </li>
                            <li class="overview-task">
                                <div class="overview-task-head"><span>ออกบิลห้องที่มีผู้พัก</span><strong data-overview-task="bills">—</strong></div>
                                <span class="overview-progress"><span data-overview-bar="bills"></span></span>
                                <small data-overview-task-note="bills">กำลังโหลด…</small>
                                <button class="button button-small button-secondary" type="button" data-overview-jump="bills">ไปออกบิล</button>
                            </li>
                            <li class="overview-task">
                                <div class="overview-task-head"><span>เก็บเงินตามบิล</span><strong data-overview-task="collection">—</strong></div>
                                <span class="overview-progress"><span data-overview-bar="collection"></span></span>
                                <small data-overview-task-note="collection">กำลังโหลด…</small>
                                <button class="button button-small button-secondary" type="button" data-overview-jump="payments">ไปตรวจการชำระ</button>
                            </li>
                        </ul>
                    </section>

                    <?php if ($canManageIntegrations): ?>
                    <section class="panel overview-panel owner-only" id="overview-health-card" aria-labelledby="overview-health-title">
                        <div class="panel-heading">
                            <div><h3 id="overview-health-title">การส่งบิลผ่าน LINE</h3><p>ตัวส่งข้อความเบื้องหลัง (worker) และคิวที่ค้างอยู่</p></div>
                            <span class="status-pill status-neutral" id="overview-health-status">กำลังโหลด</span>
                        </div>
                        <dl class="overview-health" id="overview-health-detail"></dl>
                        <p class="field-hint" id="overview-health-note">ถ้า worker หยุดทำงาน บิลจะค้างอยู่ในคิวและผู้พักจะไม่ได้รับข้อความ</p>
                    </section>
                    <?php endif; ?>
                </div>
            </section>

            <section class="admin-view" data-admin-view="rooms" aria-labelledby="rooms-title" hidden>
                <div class="section-heading">
                    <div><p class="eyebrow">สถานะห้องล่าสุด</p><h2 id="rooms-title">ห้องพักทั้งหมด</h2></div>
                    <div class="section-heading-actions"><button class="button button-secondary" type="button" data-refresh="rooms">รีเฟรช</button><button class="button button-primary" type="button" data-open-room-dialog>เพิ่มห้องพัก</button></div>
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
                    <div class="table-scroll" role="region" aria-label="ตารางห้องพัก" tabindex="0"><table><thead><tr><th>ห้อง</th><th>ชั้น</th><th>ประเภท</th><th>ราคา/เดือน</th><th>สถานะ</th><th class="align-right">จัดการ</th></tr></thead><tbody id="admin-room-rows"></tbody></table></div>
                    <div class="table-state" id="admin-room-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดห้องพัก…</p></div>
                </div>
            </section>

            <section class="admin-view" data-admin-view="bookings" aria-labelledby="bookings-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">จัดการคำขอ</p><h2 id="bookings-title">การจองห้อง</h2></div></div>
                <div class="stats-grid" id="booking-stats" aria-live="polite">
                    <article class="stat-card stat-reserved"><span>รอตรวจสอบ</span><strong data-booking-stat="pending">—</strong><small>ทั้งระบบ ไม่ขึ้นกับตัวกรอง</small></article>
                    <article class="stat-card"><span>ยืนยันแล้ว รอเข้าพัก</span><strong data-booking-stat="confirmed">—</strong><small>นับจากรายการที่โหลดอยู่</small></article>
                    <article class="stat-card"><span>แสดงอยู่ในตาราง</span><strong data-booking-stat="loaded">—</strong><small data-booking-stat-note="loaded">—</small></article>
                </div>
                <div class="toolbar">
                    <label><span>สถานะ</span><select id="booking-status-filter"><option value="">ทั้งหมด</option><option value="pending">รอตรวจสอบ</option><option value="confirmed">ยืนยันแล้ว</option><option value="cancelled">ยกเลิก</option><option value="moved_in">เข้าพักแล้ว</option></select></label>
                    <button class="button button-secondary button-small" type="button" data-refresh="bookings">รีเฟรช</button>
                    <button class="button button-ghost button-small" id="booking-load-more" type="button" hidden>โหลดรายการเพิ่มเติม</button>
                </div>
                <div class="panel table-panel"><div class="table-scroll" role="region" aria-label="ตารางการจองห้องพัก" tabindex="0"><table><thead><tr><th>เลขที่</th><th>ผู้จอง</th><th>ห้อง</th><th>วันที่ขอ</th><th>สถานะ</th><th class="align-right">จัดการ</th></tr></thead><tbody id="booking-rows"></tbody></table></div><div class="table-state" id="booking-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดการจอง…</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="residents" aria-labelledby="residents-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">ข้อมูลผู้เช่าปัจจุบัน</p><h2 id="residents-title">ผู้พักอาศัย</h2></div><button class="button button-primary" type="button" data-open-resident-create>เพิ่มผู้พักเข้าห้อง</button></div>
                <div class="stats-grid stats-grid-four" id="resident-stats" aria-live="polite">
                    <article class="stat-card"><span>ผู้พักทั้งหมด</span><strong data-resident-stat="all">—</strong></article>
                    <article class="stat-card stat-occupied"><span>ผูก LINE แล้ว</span><strong data-resident-stat="line">—</strong><small>รับบิลผ่าน LINE ได้</small></article>
                    <article class="stat-card stat-reserved"><span>รอเปิดใช้งาน</span><strong data-resident-stat="activation">—</strong><small>ยังไม่ได้ใช้รหัสเปิดใช้งาน</small></article>
                    <article class="stat-card"><span>ยังไม่ผูก LINE</span><strong data-resident-stat="noline">—</strong><small>ต้องแจ้งบิลด้วยวิธีอื่น</small></article>
                </div>
                <div class="security-note" id="resident-opening-note" role="status" hidden><strong id="resident-opening-count"></strong><span>กรอกเลขน้ำและไฟจริง ณ วันเข้าพักด้วยปุ่ม “เติมเลขเริ่มต้น” ของแต่ละห้อง ระบบจะพักการจดมิเตอร์และออกบิลของห้องเหล่านี้ไว้จนกว่าข้อมูลจะครบ</span></div>
                <div class="toolbar"><label class="search-field"><span class="sr-only">ค้นหาผู้พัก</span><input id="resident-search" type="search" placeholder="ค้นหาชื่อ เบอร์โทร หรือห้อง…"></label></div>
                <div class="panel table-panel"><div class="table-scroll" role="region" aria-label="ตารางผู้พักอาศัย" tabindex="0"><table><thead><tr><th>ผู้พัก</th><th>ห้อง</th><th>ติดต่อ</th><th>LINE</th><th>วันเข้าพัก</th><th>สถานะ</th><th class="align-right">จัดการ</th></tr></thead><tbody id="resident-rows"></tbody></table></div><div class="table-state" id="resident-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดผู้พัก…</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="meters" aria-labelledby="meters-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">บันทึกการใช้น้ำและไฟ</p><h2 id="meters-title">จดมิเตอร์รายเดือน</h2></div></div>
                <div class="toolbar toolbar-period"><label><span>รอบเดือน</span><input type="month" id="meter-period" max="<?= e($maximumBillingPeriod) ?>" required></label><p class="toolbar-note">ค่าที่กรอกต้องไม่น้อยกว่าครั้งก่อน</p><p class="toolbar-progress" id="meter-progress" role="status">—</p></div>
                <div class="security-note meter-baseline-note" role="note"><strong>ตรวจเลขมิเตอร์เริ่มต้นก่อนบันทึก</strong><span>ห้องที่ขึ้น “รอเลข ณ วันเข้าพัก” ต้องเติมเลขน้ำและไฟจริงของวันเข้าพักก่อน ช่อง “เดือนแรก · หน่วย 0” ใช้ตั้งต้นเฉพาะมิเตอร์ที่ยังไม่มีประวัติและไม่มีผู้พักรอเลขเริ่มต้น</span></div>
                <div class="panel table-panel"><div class="table-scroll meter-table" role="region" aria-label="ตารางจดมิเตอร์รายเดือน" tabindex="0"><table><thead><tr><th rowspan="2">ห้อง</th><th colspan="3">มิเตอร์น้ำ</th><th colspan="3">มิเตอร์ไฟ</th><th rowspan="2" class="align-right">จัดการ</th></tr><tr><th>ก่อน</th><th>ปัจจุบัน</th><th>ใช้</th><th>ก่อน</th><th>ปัจจุบัน</th><th>ใช้</th></tr></thead><tbody id="meter-rows"></tbody></table></div><div class="table-state" id="meter-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดมิเตอร์…</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="bills" aria-labelledby="bills-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">ออกเอกสารจากค่ามิเตอร์</p><h2 id="bills-title">ใบแจ้งหนี้</h2></div></div>
                <form class="panel billing-builder" id="bill-builder-form">
                    <div class="security-note" id="billing-readiness-note"><strong>ตรวจสอบค่ารายเดือนก่อนออกบิล</strong><span>ระบบกำลังโหลดสถานะจากหน้า “ตั้งค่า”</span></div>
                    <div class="form-grid form-grid-four">
                        <label class="field"><span>รอบเดือน</span><input type="month" name="period" id="bill-period" max="<?= e($maximumBillingPeriod) ?>" required></label>
                        <div class="field"><span>ค่าน้ำ / หน่วย · จากตั้งค่า</span><output class="auto-value" id="bill-water-rate">กำลังโหลด…</output></div>
                        <div class="field"><span>ค่าไฟ / หน่วย · จากตั้งค่า</span><output class="auto-value" id="bill-electric-rate">กำลังโหลด…</output></div>
                        <div class="field"><span>กำหนดชำระ · คำนวณอัตโนมัติ</span><output class="auto-value" id="bill-due-date">กำลังโหลด…</output></div>
                        <label class="field form-span-two"><span>รายการอื่น (ไม่บังคับ)</span><input type="text" name="other_description" maxlength="120" placeholder="เช่น ค่าทำความสะอาด"></label>
                        <label class="field"><span>จำนวนเงินอื่น / ห้อง</span><input type="number" name="other_amount" min="0" step="0.01" value="0"><small>จำนวนนี้จะเพิ่มให้ทุกห้องที่เลือก ไม่ใช่ยอดรวมของทุกห้อง</small></label>
                    </div>
                    <label class="check-field" id="bill-current-period-confirmation" hidden>
                        <input type="checkbox" name="confirm_current_period">
                        <span>ยืนยันว่าจดมิเตอร์ครบและต้องการปิดยอดของเดือนปัจจุบันตอนนี้</span>
                    </label>
                    <p class="field-hint" id="bill-current-period-help" hidden>บิลเดือนปัจจุบันเป็นยอดเต็มรอบ ระบบไม่คิดค่าเช่าแบบแบ่งวัน และจะไม่รับการแก้เลขมิเตอร์หลังออกบิลแล้ว</p>
                    <p class="field-hint">ค่าเช่าและเลขมิเตอร์ดึงจากห้อง ค่าน้ำ ค่าไฟ และกำหนดชำระดึงจากการตั้งค่าบนเซิร์ฟเวอร์ ไม่ต้องกรอกซ้ำ เลือกห้องแล้วตรวจยอดก่อนยืนยันทุกครั้ง</p>
                    <fieldset class="room-selector"><legend>เลือกห้องที่จะออกบิล</legend><label class="check-field"><input type="checkbox" id="select-all-bill-rooms"><span>เลือกทุกห้อง</span></label><div class="room-check-grid" id="bill-room-options"><span class="muted">เลือกรอบเดือนเพื่อโหลดห้อง</span></div></fieldset>
                    <p class="form-error" id="bill-builder-error" role="alert" hidden></p>
                    <div class="form-actions"><button class="button button-secondary" type="button" id="preview-bills-button" disabled>ตรวจยอดก่อน</button><button class="button button-primary" type="submit" id="create-bills-button" disabled>ออกบิลที่เลือก</button></div>
                </form>
                <div class="section-subheading"><h3>บิลในรอบเดือน</h3><button class="button button-secondary button-small" type="button" id="line-bulk-button" disabled>เข้าคิว LINE ทั้งหมด</button></div>
                <div class="stats-grid stats-grid-four" id="bill-stats" aria-live="polite">
                    <article class="stat-card"><span>บิลรอบนี้</span><strong data-bill-stat="all">—</strong></article>
                    <article class="stat-card stat-reserved"><span>รอชำระ</span><strong data-bill-stat="pending">—</strong></article>
                    <article class="stat-card"><span>กำลังตรวจสลิป</span><strong data-bill-stat="verifying">—</strong></article>
                    <article class="stat-card stat-occupied"><span>ยอดค้างรวม</span><strong data-bill-stat="outstanding">—</strong></article>
                </div>
                <div class="panel table-panel"><div class="table-scroll" role="region" aria-label="ตารางใบแจ้งหนี้" tabindex="0"><table><thead><tr><th>เลขที่บิล</th><th>ห้อง</th><th>ผู้พัก</th><th>ยอดรวม</th><th>กำหนดชำระ</th><th>สถานะ</th><th class="align-right">LINE</th></tr></thead><tbody id="bill-admin-rows"></tbody></table></div><div class="table-state" id="bill-admin-state" data-state="idle"><p>เลือกรอบเดือนเพื่อดูบิล</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="payments" aria-labelledby="payments-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">หลักฐานการโอนจากผู้พัก</p><h2 id="payments-title">การชำระเงิน</h2></div></div>
                <div class="stats-grid" id="payment-stats" aria-live="polite">
                    <article class="stat-card stat-reserved"><span>รอตรวจ</span><strong data-payment-stat="pending">—</strong><small>ทั้งระบบ ไม่ขึ้นกับตัวกรอง</small></article>
                    <article class="stat-card"><span>แสดงอยู่ในตาราง</span><strong data-payment-stat="loaded">—</strong><small data-payment-stat-note="loaded">—</small></article>
                    <article class="stat-card"><span>ยอดรวมที่แสดง</span><strong data-payment-stat="amount">—</strong><small>นับจากรายการที่โหลดอยู่</small></article>
                </div>
                <div class="toolbar"><label><span>สถานะตรวจสอบ</span><select id="payment-status-filter"><option value="">ทั้งหมด (รายการค้างก่อน)</option><option value="pending">รอตรวจ</option><option value="verified">ยืนยันแล้ว</option><option value="rejected">ปฏิเสธสลิป</option></select></label><button class="button button-secondary button-small" type="button" data-refresh="payments">รีเฟรช</button><button class="button button-ghost button-small" id="payment-load-more" type="button" hidden>โหลดรายการเพิ่มเติม</button></div>
                <div class="security-note"><strong>ระบบไม่อนุญาตให้กดยืนยันยอดด้วยมือ</strong><span>รายการจะเป็น “ชำระแล้ว” ต่อเมื่อผู้ให้บริการตรวจสลิปผ่านเท่านั้น หากรายการค้าง ให้ดูหลักฐานแล้วเลือกตรวจซ้ำหรือปิดเพื่อให้ผู้พักส่งสลิปใหม่</span></div>
                <div class="panel table-panel"><div class="table-scroll" role="region" aria-label="ตารางการชำระเงิน" tabindex="0"><table><thead><tr><th>อัปโหลดเมื่อ</th><th>บิล / ห้อง</th><th>ผู้พัก</th><th>ยอดเงิน</th><th>ผลตรวจ</th><th class="align-right">จัดการ</th></tr></thead><tbody id="payment-rows"></tbody></table></div><div class="table-state" id="payment-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดการชำระ…</p></div></div>
            </section>

            <section class="admin-view owner-only" data-admin-view="users" aria-labelledby="users-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">เฉพาะเจ้าของระบบ</p><h2 id="users-title">ผู้ดูแลระบบ</h2></div><button class="button button-primary" type="button" data-open-user-dialog>เพิ่มผู้ดูแล</button></div>
                <div class="security-note"><strong>สิทธิ์การเข้าถึง</strong><span>เจ้าของ (Owner) จัดการบัญชีได้ ผู้ดูแล (Admin) ใช้งานโมดูลหอพักและการเงิน</span></div>
                <div class="panel table-panel"><div class="table-scroll" role="region" aria-label="ตารางผู้ดูแลระบบ" tabindex="0"><table><thead><tr><th>ชื่อผู้ใช้</th><th>บทบาท</th><th>สถานะ</th><th>แก้ไขล่าสุด</th><th class="align-right">จัดการ</th></tr></thead><tbody id="user-rows"></tbody></table></div><div class="table-state" id="user-state" data-state="loading"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดผู้ดูแล…</p></div></div>
            </section>

            <section class="admin-view" data-admin-view="line-oas" aria-labelledby="line-oas-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">การสื่อสารของหอพัก</p><h2 id="line-oas-title">บัญชี LINE Official Account</h2><p>ตั้งค่าบอทหลักของหอพัก แล้วเพิ่มบัญชีผู้พักหรือผู้ดูแลเป็นผู้รับแจ้งเตือนได้</p></div><div class="form-actions"><button class="button button-secondary" type="button" data-refresh="line-oas">รีเฟรช</button><button class="button button-primary" type="button" id="line-oa-configure">ตั้งค่า LINE Bot</button></div></div>
                <p class="form-error" id="line-oas-error" role="alert" hidden></p>
                <div id="line-oa-list" class="line-platform-grid" aria-live="polite"></div>
                <div class="section-heading"><div><h3>ผู้รับแจ้งเตือนฝ่ายจัดการ</h3><p>สร้างรหัสให้เจ้าของหรือผู้ดูแล แล้วให้เจ้าตัวส่งรหัสจาก LINE ของตนเอง จึงจะเริ่มรับแจ้งเตือน</p></div><button class="button button-primary" type="button" id="line-recipient-create">เพิ่มผู้รับแจ้งเตือน</button></div>
                <div id="line-recipient-list" class="line-platform-grid" aria-live="polite"></div>
            </section>
            <section class="admin-view" data-admin-view="line-bindings" aria-labelledby="line-bindings-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">บัญชีผู้พักและห้อง</p><h2 id="line-bindings-title">การผูก LINE ผู้พัก</h2><p>ดูทุกบัญชีที่ผูกกับห้อง สร้างรหัสเพิ่ม หรือยกเลิกเฉพาะรายการที่ต้องการ</p></div><button class="button button-secondary" type="button" data-refresh="line-bindings">รีเฟรช</button></div>
                <p class="form-error" id="line-bindings-error" role="alert" hidden></p>
                <div id="line-binding-summary" class="line-platform-summary" aria-live="polite"></div>
                <div class="table-toolbar"><label class="field"><span>ค้นหาผู้พัก ห้อง หรือเบอร์โทร</span><input id="line-binding-search" type="search" maxlength="150" placeholder="ชื่อ / ห้อง / เบอร์โทร"></label><label class="field"><span>สถานะการผูก</span><select id="line-binding-filter"><option value="">ทุกสถานะ</option><option value="bound">ผูกแล้ว</option><option value="pending">รอส่งรหัส</option><option value="unbound">ยังไม่ผูก</option><option value="blocked">ระงับการผูก</option></select></label></div>
                <div class="table-scroll"><table class="data-table"><thead><tr><th>ผู้พัก / ห้อง</th><th>สถานะ</th><th>บัญชี / รหัสรอใช้</th><th>จัดการ</th></tr></thead><tbody id="line-binding-rows"></tbody></table></div>
            </section>
            <section class="admin-view" data-admin-view="settings" aria-labelledby="settings-title" hidden>
                <div class="section-heading"><div><p class="eyebrow">การเรียกเก็บเงินและบริการภายนอก</p><h2 id="settings-title">ตั้งค่าระบบ</h2></div></div>
                <div class="settings-grid">
                    <form class="panel settings-card" id="settings-form"><div class="panel-heading"><div><h3>การเรียกเก็บรายเดือน</h3><p>ต้องตรวจสอบและบันทึกอย่างน้อยหนึ่งครั้งก่อนออกบิลจริง</p></div></div><div class="form-grid"><label class="field"><span>ค่าน้ำต่อหน่วย (บาท)</span><input type="number" name="water_rate" min="0" step="0.01" required></label><label class="field"><span>ค่าไฟต่อหน่วย (บาท)</span><input type="number" name="electric_rate" min="0" step="0.01" required></label><label class="field"><span>ระยะเวลาชำระ (วัน)</span><input type="number" name="due_days" min="1" max="60" step="1" required><small>จำนวนวันจากวันออกบิลถึงวันครบกำหนด</small></label></div><p class="field-hint" id="billing-settings-status">กำลังโหลดสถานะ…</p><p class="form-error" id="settings-error" role="alert" hidden></p><div class="form-actions"><button class="button button-primary" type="submit">ยืนยันและบันทึกการตั้งค่า</button></div></form>
                    <form class="panel settings-card settings-integration-card" id="integration-settings-form" data-owner-only="<?= $canManageIntegrations ? 'true' : 'false' ?>">
                        <div class="panel-heading"><div><h3>พร้อมเพย์และตรวจสลิป</h3><p>ค่าลับถูกเข้ารหัสในฐานข้อมูลและจะไม่แสดงค่าจริงกลับมาบนหน้าเว็บ</p></div></div>
                        <div class="security-note"><strong>กุญแจระบบ</strong><span>ช่องค่าลับที่เว้นว่างจะเก็บค่าเดิม เลือก “ล้างค่า” เมื่อต้องการยกเลิกจริง การเปลี่ยนส่วนนี้ทำได้เฉพาะเจ้าของระบบ</span></div>
                        <div class="integration-form-grid">
                            <fieldset class="integration-fieldset">
                                <legend> PromptPay QR</legend>
                                <label class="field"><span>เบอร์พร้อมเพย์ / เลขผู้เสียภาษี</span><input name="promptpay_target" type="text" inputmode="numeric" maxlength="13" pattern="(?:0[0-9]{9}|[0-9]{13})" placeholder="0812345678"<?= $integrationDisabled ?>><small>ใช้สร้าง QR ตามยอดบิล</small></label>
                                <label class="field"><span>ชื่อบัญชีที่แสดง</span><input name="promptpay_name" type="text" maxlength="120" placeholder="ชื่อผู้รับเงิน"<?= $integrationDisabled ?>></label>
                                <div class="integration-status-row"><div class="integration-status-copy"><span>ความพร้อมของค่าที่บันทึก</span><small class="integration-test-result" data-integration-test-result="promptpay">ยังไม่ได้ทดสอบค่าที่บันทึกนี้</small></div><div class="integration-status-actions"><span class="status-pill status-neutral" data-integration-status="promptpay">กำลังโหลด</span><button class="button button-small button-secondary" type="button" data-test-integration="promptpay" disabled>สุ่มยอดและแสดง QR ทดสอบ</button></div></div>
                            </fieldset>

                            <fieldset class="integration-fieldset">
                                <legend> LINE และคิวแจ้งเตือน</legend>
                                <p>จัดการ OA, Token, Webhook และผู้รับแจ้งเตือนได้ที่หน้า LINE สำหรับเจ้าของและผู้ดูแลทุกคน</p>
                                <button class="button button-secondary" type="button" data-admin-nav="line-oas">เปิดบัญชี LINE OA</button>
                                <p class="field-hint">ระบบใช้ค่าการส่งซ้ำและขนาดคิวที่บันทึกไว้ให้อัตโนมัติ ไม่ต้องตั้งค่าเพิ่ม</p>
                            </fieldset>

                            <fieldset class="integration-fieldset integration-fieldset-wide">
                                <legend> ตรวจสลิปอัตโนมัติ</legend>
                                <div class="form-grid form-grid-two">
                                     <label class="field"><span>ผู้ให้บริการ</span><select name="slip_provider" aria-describedby="slip-provider-help"<?= $integrationDisabled ?>><option value="none">ยังไม่เปิดใช้</option><option value="slipok">SlipOK</option><option value="easyslip">EasySlip</option></select><small id="slip-provider-help" aria-live="polite">เลือกผู้ให้บริการเพื่อแสดงเฉพาะช่องที่ต้องกรอก</small></label>
                                     <label class="field"><span>เลขบัญชีปลายทาง/เลขท้าย</span><input name="payment_receiver_account_tail" type="text" inputmode="numeric" minlength="6" maxlength="20" pattern="[0-9]{6,20}" placeholder="อย่างน้อย 6 หลัก"<?= $integrationDisabled ?>><small>ใช้เทียบผู้รับบนสลิป ไม่ใช่เบอร์พร้อมเพย์ และต้องตรงอย่างน้อย 6 หลัก</small></label>
                                    <label class="field" data-slip-provider-field="slipok"><span>SlipOK Branch ID</span><input name="slipok_branch_id" type="text" maxlength="80" pattern="[A-Za-z0-9_-]{1,80}" placeholder="จำเป็นเมื่อเลือก SlipOK"<?= $integrationDisabled ?>></label>
                                    <label class="field" data-slip-provider-field="slipok"><span>SlipOK API Key</span><input name="slipok_api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="เว้นว่างเพื่อเก็บค่าเดิม"<?= $integrationDisabled ?>><small data-secret-status="slipok_api_key">ยังไม่ได้โหลดสถานะ</small></label>
                                    <label class="field" data-slip-provider-field="easyslip"><span>EasySlip API Key</span><input name="easyslip_api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="เว้นว่างเพื่อเก็บค่าเดิม"<?= $integrationDisabled ?>><small data-secret-status="easyslip_api_key">ยังไม่ได้โหลดสถานะ</small></label>
                                    <label class="check-field danger-check" data-slip-provider-field="slipok"><input name="slipok_api_key_clear" type="checkbox" value="1"<?= $integrationDisabled ?>><span>ล้าง SlipOK API Key</span></label>
                                    <label class="check-field danger-check" data-slip-provider-field="easyslip"><input name="easyslip_api_key_clear" type="checkbox" value="1"<?= $integrationDisabled ?>><span>ล้าง EasySlip API Key</span></label>


                                </div>
                                <div class="integration-status-row"><div class="integration-status-copy"><span>ความพร้อมของค่าที่บันทึก</span><small class="integration-test-result" data-integration-test-result="slip">ยังไม่ได้ตรวจ credential ที่บันทึกนี้</small></div><div class="integration-status-actions"><span class="status-pill status-neutral" data-integration-status="slip">กำลังโหลด</span><button class="button button-small button-secondary" type="button" data-test-integration="slip" disabled>ตรวจ API Key ที่บันทึก</button></div></div>
                            </fieldset>
                        </div>
                        <details><summary>ค่าที่ระบบจัดการให้อัตโนมัติ</summary><p class="field-hint" id="integration-managed-defaults">กำลังโหลด…</p></details>
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

        <nav class="mobile-bottom-nav admin-bottom-nav" aria-label="เมนูผู้ดูแลบนมือถือ">
            <button class="is-active" type="button" data-admin-nav="overview">ภาพรวม</button>
            <button type="button" data-admin-nav="bookings">การจอง<span class="nav-count nav-count-dot" id="booking-bottom-count" hidden>0</span></button>
            <button type="button" data-admin-nav="payments">ชำระเงิน<span class="nav-count nav-count-dot" id="payment-bottom-count" hidden>0</span></button>
            <button type="button" aria-controls="admin-sidebar" aria-expanded="false" data-admin-menu-toggle>เมนูทั้งหมด</button>
        </nav>
    </div>
</div>

<dialog class="modal" id="promptpay-test-dialog" aria-labelledby="promptpay-test-title">
    <div class="modal-card promptpay-test-card">
        <div class="modal-header"><div><p class="eyebrow">ทดสอบค่าที่บันทึก</p><h2 id="promptpay-test-title">PromptPay QR ทดสอบ</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div>
        <div class="security-note promptpay-transfer-warning" role="alert"><strong>QR นี้ชี้บัญชีจริง</strong><span>ไม่มีโหมด sandbox และไม่หมดอายุอัตโนมัติ สแกนเพื่อตรวจชื่อผู้รับและยอดเท่านั้น แล้วกดยกเลิกในแอปธนาคาร ห้ามกดยืนยันโอน เพราะเงินจะถูกโอนจริง</span></div>
        <div class="qr-stage promptpay-test-qr-stage" id="promptpay-test-qr-stage" aria-live="polite"><div class="qr-placeholder">กดสุ่มยอดจากหน้าตั้งค่าเพื่อสร้าง QR ทดสอบ</div></div>
        <p class="field-hint">การทดสอบนี้สร้าง QR ในระบบเท่านั้น ไม่สร้างบิล ไม่สร้างรายการชำระ และไม่ส่งข้อมูลไปยังผู้ให้บริการตรวจสลิป</p>
        <div class="form-actions"><button class="button button-primary" type="button" data-close-dialog>ตรวจแล้วและปิด</button></div>
    </div>
</dialog>

<dialog class="modal" id="room-dialog" aria-labelledby="room-dialog-title">
    <form class="modal-card modal-card-wide" id="room-form" data-guard-draft>
        <input type="hidden" name="id">
        <div class="modal-header"><div><p class="eyebrow">จัดการข้อมูลหลัก</p><h2 id="room-dialog-title">เพิ่มห้องพัก</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div>
        <div class="form-grid form-grid-two"><label class="field"><span>รหัสห้อง</span><input name="room_code" type="text" maxlength="30" required autocomplete="off"></label><label class="field"><span>ชั้น</span><input name="floor" type="number" min="1" max="200" step="1" required></label><label class="field"><span>ประเภทห้อง</span><input name="room_type" type="text" maxlength="50" required></label><label class="field"><span>ค่าเช่ารายเดือน</span><input name="monthly_rent" type="number" min="0.01" step="0.01" required></label></div><details class="optional-fields"><summary>รูปห้องและรายละเอียดเพิ่มเติม</summary><div class="form-grid form-grid-two"><label class="field form-span-two"><span>รูปห้อง</span><select name="image_key" required><option value="room-standard.jpg">ห้องมาตรฐาน</option><option value="room-deluxe.jpg">ห้องดีลักซ์</option><option value="room-suite.jpg">ห้องสวีท</option><option value="room-studio.jpg">ห้องสตูดิโอ</option></select></label><label class="field form-span-two"><span>สิ่งอำนวยความสะดวก</span><input name="amenities" type="text" maxlength="500" placeholder="คั่นด้วยจุลภาค เช่น แอร์, ตู้เย็น, เตียง"></label><label class="field form-span-two"><span>รายละเอียด</span><textarea name="description" rows="3" maxlength="1000"></textarea></label></div></details>
        <p class="form-error" id="room-form-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">บันทึกห้อง</button></div>
    </form>
</dialog>

<dialog class="modal" id="move-in-dialog" aria-labelledby="move-in-title">
    <form class="modal-card" id="move-in-form">
        <input type="hidden" name="booking_id">
        <div class="modal-header"><div><p class="eyebrow">เปิดบัญชีผู้พัก</p><h2 id="move-in-title">รับเข้าพัก</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div>
        <p class="modal-lead" id="move-in-summary"></p>
        <div class="form-grid form-grid-two">
            <label class="field"><span>วันที่เข้าพัก</span><input name="move_in_date" type="date" min="2000-01-01" required></label>
            <label class="field"><span>อีเมล (ไม่บังคับ)</span><input name="email" type="email" maxlength="190" autocomplete="email"></label>
            <label class="field"><span>เลขมิเตอร์น้ำเริ่มต้น</span><input name="opening_water_reading" type="number" inputmode="decimal" min="0" max="9999999" step="0.01" required><small>อ่านจากหน้ามิเตอร์ ณ ตอนส่งมอบห้อง</small></label>
            <label class="field"><span>เลขมิเตอร์ไฟเริ่มต้น</span><input name="opening_electric_reading" type="number" inputmode="decimal" min="0" max="9999999" step="0.01" required><small>อ่านจากหน้ามิเตอร์ ณ ตอนส่งมอบห้อง</small></label>
        </div>
        <p class="field-hint">หลังบันทึก ระบบจะแสดงรหัสเปิดใช้งานครั้งเดียว ผู้พักต้องใช้รหัสนี้กับเบอร์โทรเพื่อตั้งรหัสผ่านใหม่</p>
        <label class="check-field" id="move-in-reuse-field" hidden><input name="reuse_resident_id" type="checkbox" disabled><span id="move-in-reuse-label">ยืนยันการเชื่อมบัญชีผู้พักเดิม</span></label>
        <p class="field-hint" id="move-in-reuse-help" hidden>เลือกเฉพาะเมื่อยืนยันแล้วว่าเป็นบุคคลเดิม ประวัติบิลเก่าจะถูกเชื่อมกับบัญชีนี้ หากเป็นคนละคนต้องใช้เบอร์โทรอื่นเพื่อปกป้องข้อมูลส่วนบุคคล</p>
        <p class="form-error" id="move-in-error" role="alert" hidden></p>
        <div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">ยืนยันเข้าพัก</button></div>
    </form>
</dialog>

<dialog class="modal" id="booking-cancel-dialog" aria-labelledby="booking-cancel-title">
    <form class="modal-card" id="booking-cancel-form"><input type="hidden" name="booking_id"><div class="modal-header"><div><p class="eyebrow">บันทึกการตัดสินใจ</p><h2 id="booking-cancel-title">ยกเลิกการจอง</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div><p class="modal-lead" id="booking-cancel-summary"></p><div class="form-grid"><label class="field"><span>เหตุผล (ไม่บังคับ)</span><textarea name="reason" minlength="3" maxlength="500" rows="4" placeholder="เช่น ผู้จองขอยกเลิก หรือไม่สามารถติดต่อได้"></textarea><small>หากระบุ กรุณากรอกอย่างน้อย 3 ตัวอักษร เพื่อใช้ตรวจสอบย้อนหลัง</small></label></div><p class="form-error" id="booking-cancel-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>กลับ</button><button class="button button-danger" type="submit">ยืนยันยกเลิกการจอง</button></div></form>
</dialog>

<dialog class="modal" id="resident-create-dialog" aria-labelledby="resident-create-title">
    <form class="modal-card modal-card-wide" id="resident-create-form">
        <input type="hidden" name="idempotency_key">
        <div class="modal-header"><div><p class="eyebrow">ผู้พักหลัก / ผู้ถือบัญชีของห้อง</p><h2 id="resident-create-title">เพิ่มผู้พักเข้าห้อง</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div>
        <div class="security-note"><strong>หนึ่งห้องมีผู้พักหลักได้ครั้งละ 1 คน</strong><span>ระบบจะสร้างหลักฐานรับเข้าพักและรหัสเปิดใช้งานครั้งเดียว ต้องตรวจตัวตน เบอร์ และเลขมิเตอร์จริงก่อนบันทึก</span></div>
        <div class="form-grid form-grid-two">
            <label class="field"><span>ห้องว่าง</span><select name="room_id" required></select><small id="resident-create-room-help">แสดงเฉพาะห้องที่ระบบตรวจว่าไม่มีผู้จองหรือผู้พัก</small></label>
            <label class="field"><span>วันที่เข้าพัก</span><input name="move_in_date" type="date" min="2000-01-01" required></label>
            <label class="field"><span>ชื่อ–นามสกุล</span><input name="full_name" type="text" minlength="1" maxlength="150" required autocomplete="name"></label>
            <label class="field"><span>เบอร์โทรศัพท์สำหรับเข้าสู่ระบบ</span><input name="phone" type="tel" minlength="10" maxlength="20" required autocomplete="tel" placeholder="0812345678"></label>
            <label class="field"><span>เลขมิเตอร์น้ำเริ่มต้น</span><input name="opening_water_reading" type="number" inputmode="decimal" min="0" max="9999999" step="0.01" required><small>อ่านจากหน้ามิเตอร์ ณ ตอนส่งมอบห้อง</small></label>
            <label class="field"><span>เลขมิเตอร์ไฟเริ่มต้น</span><input name="opening_electric_reading" type="number" inputmode="decimal" min="0" max="9999999" step="0.01" required><small>อ่านจากหน้ามิเตอร์ ณ ตอนส่งมอบห้อง</small></label>
            <label class="field form-span-two"><span>อีเมล (ไม่บังคับ)</span><input name="email" type="email" maxlength="190" autocomplete="email"></label>
        </div>
        <label class="check-field" id="resident-create-reuse-field" hidden><input name="reuse_resident_id" type="checkbox" disabled><span id="resident-create-reuse-label">ยืนยันการเชื่อมบัญชีผู้พักเดิม</span></label>
        <p class="field-hint" id="resident-create-reuse-help" hidden>เลือกเฉพาะเมื่อยืนยันแล้วว่าเป็นบุคคลเดิม ประวัติบิลเก่าจะถูกเชื่อมกับบัญชีนี้ หากเป็นคนละคนต้องใช้เบอร์อื่นเพื่อปกป้องข้อมูลส่วนบุคคล</p>
        <p class="form-error" id="resident-create-error" role="alert" hidden></p>
        <div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">ยืนยันและรับเข้าพัก</button></div>
    </form>
</dialog>

<dialog class="modal" id="opening-readings-dialog" aria-labelledby="opening-readings-title">
    <form class="modal-card" id="opening-readings-form">
        <input type="hidden" name="occupancy_id">
        <div class="modal-header"><div><p class="eyebrow">เติมข้อมูลการเข้าพักที่ยังขาด</p><h2 id="opening-readings-title">เลขมิเตอร์ ณ วันเข้าพัก</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div>
        <p class="modal-lead" id="opening-readings-summary"></p>
        <div class="security-note" id="opening-readings-help"><strong>ใช้เลขจริงจากวันส่งมอบห้อง</strong><span>ตรวจจากบันทึกหรือภาพมิเตอร์วันเข้าพัก เลขทั้งสองใช้คำนวณบิลและบันทึกได้ครั้งเดียว หากยังหาเลขจริงไม่ได้ ให้กลับมากรอกภายหลังโดยไม่ใส่ค่า 0 แทน</span></div>
        <div class="form-grid form-grid-two">
            <label class="field"><span>เลขมิเตอร์น้ำ ณ วันเข้าพัก</span><input name="opening_water_reading" type="number" inputmode="decimal" min="0" max="9999999" step="0.01" required aria-describedby="opening-readings-help"></label>
            <label class="field"><span>เลขมิเตอร์ไฟ ณ วันเข้าพัก</span><input name="opening_electric_reading" type="number" inputmode="decimal" min="0" max="9999999" step="0.01" required aria-describedby="opening-readings-help"></label>
        </div>
        <p class="form-error" id="opening-readings-error" role="alert" hidden></p>
        <div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>กรอกภายหลัง</button><button class="button button-primary" type="submit">ยืนยันเลขและบันทึก</button></div>
    </form>
</dialog>

<dialog class="modal" id="resident-edit-dialog" aria-labelledby="resident-edit-title">
    <form class="modal-card" id="resident-edit-form"><input type="hidden" name="resident_id"><div class="modal-header"><div><p class="eyebrow">ข้อมูลผู้พักที่ยืนยันแล้ว</p><h2 id="resident-edit-title">แก้ข้อมูลผู้พัก</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div><p class="modal-lead" id="resident-edit-summary"></p><div class="security-note"><strong>เบอร์โทรเป็นชื่อบัญชี ไม่ใช่รหัสผ่าน</strong><span>ตรวจสอบตัวตนก่อนเปลี่ยนเบอร์ เมื่อบันทึก ระบบจะยกเลิกเซสชันและรหัสผ่านเดิม แล้วออก activation code ใหม่ให้ส่งมอบผู้พัก</span></div><div class="form-grid form-grid-two"><label class="field"><span>ชื่อ–นามสกุล</span><input name="full_name" type="text" minlength="1" maxlength="150" required autocomplete="name"></label><label class="field"><span>เบอร์โทรศัพท์</span><input name="phone" type="tel" minlength="10" maxlength="20" required autocomplete="tel"></label><label class="field"><span>อีเมล</span><input name="email" type="email" maxlength="190" autocomplete="email"></label></div><p class="field-hint">LINE User ID เปลี่ยนได้จากบัญชีผู้พักเท่านั้น และต้องยืนยันรหัสที่ส่งผ่าน LINE</p><p class="form-error" id="resident-edit-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">บันทึกข้อมูล</button></div></form>
</dialog>

<script src="<?= e($assetUrl('/assets/js/admin-line-platform.js')) ?>" defer></script>
<dialog class="modal" id="line-platform-dialog" aria-labelledby="line-platform-title">
    <div class="modal-card line-platform-modal">
        <div class="modal-header"><h2 id="line-platform-title">จัดการ LINE</h2><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div>
        <p id="line-platform-summary" class="muted"></p><p class="form-error" id="line-platform-error" role="alert" hidden></p>
        <form id="line-oa-form" class="stack-form" hidden>
            <div class="security-note"><strong>กรอกเพียง 2 ค่า</strong><span>นำ Channel access token และ Channel secret จาก LINE Developers มากรอก ระบบจะตรวจบัญชีและดึงชื่อกับ Basic ID ให้เอง</span></div>
            <div class="form-grid form-grid-two">
                <label class="field"><span>Channel access token</span><input name="channel_access_token" type="password" maxlength="8192" autocomplete="new-password" placeholder="วาง Token จาก Messaging API"><small id="line-oa-token-hint"></small></label>
                <label class="field"><span>Channel secret</span><input name="channel_secret" type="password" maxlength="8192" autocomplete="new-password" placeholder="วาง Secret จาก Basic settings"><small id="line-oa-secret-hint"></small></label>
            </div>
            <div class="security-note" id="line-oa-identity" aria-live="polite">ชื่อบัญชีและ Basic ID จะแสดงหลังตรวจสอบ Token</div>
            <details>
                <summary>หยุดใช้งานหรือล้างค่าลับ</summary>
                <div class="form-grid form-grid-two"><label class="check-field"><input type="checkbox" name="channel_access_token_clear"><span>ล้าง Token เดิม</span></label><label class="check-field"><input type="checkbox" name="channel_secret_clear"><span>ล้าง Secret เดิม</span></label></div>
                <label class="check-field"><input type="checkbox" name="enabled" checked><span>เปิดใช้งาน OA นี้</span></label>
            </details>
            <p class="field-hint">ค่าลับจะไม่ถูกส่งกลับมาแสดง หลังบันทึกให้นำ Webhook URL ที่ระบบสร้างไปวางใน LINE Developers แล้วกด Verify</p>
            <div id="line-oa-diagnostics" class="line-platform-diagnostics"></div>
            <div class="form-actions"><button class="button button-primary" type="submit">เชื่อมต่อและตรวจสอบบัญชี</button><button class="button button-secondary" type="button" data-close-dialog>ยกเลิก</button></div>
        </form>
        <div id="line-binding-detail" hidden>
            <p id="line-binding-policy" class="security-note"></p>
            <form id="line-binding-code-form" class="stack-form">
                <div class="form-grid form-grid-two"><div class="field"><span>LINE ของหอพัก · เลือกให้อัตโนมัติ</span><output class="auto-value" id="line-binding-bot"></output></div><label class="field"><span>อายุรหัส (วัน)</span><input name="ttl_days" type="number" min="1" max="30" step="1" value="7" required><small>เลือกได้ 1–30 วัน เริ่มต้น 7 วัน</small></label></div>
                <label class="field"><span>วิธีสร้างรหัส</span><select name="replace_pending"><option value="true">สร้างใหม่และยกเลิกรหัสรอใช้เดิม</option><option value="false">เพิ่มรหัสอีกชุด โดยเก็บรหัสรอใช้เดิม</option></select><small>บัญชีที่ผูกสำเร็จแล้วจะยังอยู่ จนกว่าจะยกเลิกบัญชีนั้น</small></label>
                <div class="form-actions"><button class="button button-primary" type="submit">สร้างรหัสผูก LINE</button></div>
            </form>
            <div class="form-actions"><button class="button button-secondary" type="button" id="line-binding-refresh">ตรวจสถานะ</button></div>
            <h3>รหัสรอใช้</h3><p class="muted">ให้ผู้พักเปิด LINE ของตนเอง แล้วกด “ส่ง” ในแชต OA ที่ระบุ ลิงก์และ QR เตรียมข้อความให้เท่านั้น</p><div id="line-pending-list" class="line-platform-grid"></div>
            <h3>บัญชีที่ผูกแล้ว</h3><div id="line-account-list" class="line-platform-grid"></div>
            <div class="line-platform-policy"><label class="field"><span>เหตุผลระงับการผูก</span><textarea id="line-block-reason" maxlength="500" rows="2" placeholder="ระบุเหตุผลเพื่อให้ผู้ดูแลคนอื่นเข้าใจ"></textarea></label><div class="form-actions"><button class="button button-danger" type="button" id="line-binding-block">ระงับและยกเลิกการผูกทั้งหมด</button><button class="button button-secondary" type="button" id="line-binding-unblock" hidden>ปลดการระงับ</button><button class="button button-danger" type="button" id="line-binding-revoke-all">ยกเลิกทุกบัญชีและทุกรหัส</button></div></div>
            <details><summary>ประวัติการผูก</summary><div id="line-binding-history" class="line-platform-history"></div></details>
        </div>
        <form id="line-recipient-form" class="stack-form" hidden>
            <div class="field"><span>LINE ของหอพัก · เลือกให้อัตโนมัติ</span><output class="auto-value" id="line-recipient-bot"></output></div>
            <label class="field"><span>ชื่อกำกับผู้รับ</span><input name="label" maxlength="120" required></label>
            <label class="check-field" id="line-recipient-owner-field"><input type="checkbox" name="is_owner"><span>ผู้รับหลักของเจ้าของหอ (OWNER)</span></label>
            <label class="check-field" id="line-recipient-enabled-field"><input type="checkbox" name="enabled" checked><span>เปิดรับแจ้งเตือน</span></label>
            <fieldset id="line-recipient-mutes"><legend>ปิดเสียงตามประเภท</legend><div class="line-platform-mutes"><label><input type="checkbox" name="muted_categories" value="booking"> การจอง</label><label><input type="checkbox" name="muted_categories" value="payment"> ชำระเงิน</label><label><input type="checkbox" name="muted_categories" value="billing"> บิล</label><label><input type="checkbox" name="muted_categories" value="tenancy"> การเข้าพัก</label><label><input type="checkbox" name="muted_categories" value="maintenance"> การซ่อมบำรุง</label><label><input type="checkbox" name="muted_categories" value="security"> ความปลอดภัย</label><label><input type="checkbox" name="muted_categories" value="system"> ระบบ</label></div></fieldset>
            <p id="line-recipient-status" class="muted"></p><div id="line-recipient-code"></div>
            <div class="form-actions"><button class="button button-primary" type="submit">บันทึกผู้รับ</button><button class="button button-secondary" type="button" id="line-recipient-refresh" hidden>ตรวจสถานะ</button><button class="button button-danger" type="button" id="line-recipient-delete" hidden>ยกเลิกผู้รับนี้</button><button class="button button-secondary" type="button" data-close-dialog>ปิด</button></div>
        </form>
        <div id="line-webhook-detail" class="line-platform-diagnostics" hidden></div>
    </div>
</dialog>
<dialog class="modal" id="admin-line-dialog" aria-labelledby="admin-line-title">
    <div class="modal-card stack-form">
        <div class="modal-header"><div><p class="eyebrow">รับบิลและดูข้อมูลห้องผ่าน LINE</p><h2 id="admin-line-title">ผูก LINE ของผู้พัก</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div>
        <p class="modal-lead" id="admin-line-summary"></p>
        <p id="admin-line-status" role="status">กำลังตรวจสอบสถานะ…</p>
        <p class="field-hint" id="admin-line-readiness" hidden>ผู้ดูแลยังตั้งค่า LINE ไม่ครบ กรุณาตั้งค่า Token, Channel secret และ Basic ID ก่อนสร้างรหัส</p>
        <div class="security-note"><strong>ให้ผู้พักใช้ LINE ของตนเอง</strong><span>สร้างรหัสแล้วส่งรหัสหรือ QR ให้ผู้พักโดยตรง ผู้พักต้องเปิดแชตของหอพักและกด “ส่ง” ใน LINE จึงจะผูกบัญชีสำเร็จ ลิงก์และ QR ไม่ส่งข้อความให้อัตโนมัติ</span></div>
        <a class="button button-secondary" id="admin-line-add-friend" target="_blank" rel="noopener noreferrer" hidden>เพิ่มเพื่อน LINE ของหอพัก</a>
        <div class="stack-form" id="admin-line-code-panel" hidden>
            <label class="field"><span>รหัสสำหรับส่งให้ LINE Bot</span><input id="admin-line-code" type="text" readonly autocomplete="off" spellcheck="false"></label>
            <p class="field-hint" id="admin-line-expiry"></p>
            <a class="button button-primary" id="admin-line-open-message" target="_blank" rel="noopener noreferrer" hidden>เปิด LINE พร้อมรหัสบนอุปกรณ์ผู้พัก</a>
            <canvas class="line-code-qr" id="admin-line-qr" width="220" height="220" role="img" aria-label="QR เปิดแชต LINE พร้อมรหัสของผู้พักรายนี้" hidden></canvas>
            <p class="field-hint" id="admin-line-qr-fallback" role="status" hidden>เปิด LINE หรือสร้าง QR อัตโนมัติไม่ได้ กรุณาคัดลอกรหัสให้ผู้พักส่งในแชตของหอพัก</p>
            <button class="button button-secondary" id="admin-line-copy" type="button">คัดลอกรหัสให้ผู้พัก</button>
        </div>
        <p class="form-error" id="admin-line-error" role="alert" hidden></p>
        <div class="form-actions"><button class="button button-primary" id="admin-line-issue" type="button" disabled>สร้างรหัสผูก LINE</button><button class="button button-secondary" id="admin-line-refresh" type="button">ตรวจสอบสถานะ</button><button class="button button-danger-text" id="admin-line-unlink" type="button" hidden>ยกเลิกการผูก LINE</button><button class="button button-ghost" type="button" data-close-dialog>ปิด</button></div>
    </div>
</dialog>

<dialog class="modal" id="resident-access-dialog" aria-labelledby="resident-access-title" data-require-explicit-close="true">
    <div class="modal-card">
        <div class="modal-header"><div><p class="eyebrow">ข้อมูลลับ แสดงเฉพาะครั้งนี้</p><h2 id="resident-access-title">รหัสเปิดใช้งานผู้พัก</h2></div></div>
        <p class="modal-lead" id="resident-access-summary"></p>
        <div class="security-note"><strong>ส่งให้ผู้พักโดยตรงเท่านั้น</strong><span>รหัสนี้ใช้ได้ครั้งเดียวและจะยกเลิกรหัสผ่าน/เซสชันเดิม ห้ามบันทึกในหมายเหตุ แชตกลุ่ม หรือภาพหน้าจอสาธารณะ</span></div>
        <label class="field"><span>Activation code</span><output id="resident-access-code" aria-live="polite">•••••-•••••-•••••-•••••</output><small id="resident-access-expiry"></small></label>
        <p class="form-error" id="resident-access-error" role="alert" hidden></p>
        <div class="form-actions"><button class="button button-secondary" type="button" id="resident-access-copy">คัดลอกรหัส</button><button class="button button-primary" type="button" data-close-dialog data-explicit-close>ส่งมอบแล้วและปิด</button></div>
    </div>
</dialog>

<dialog class="modal" id="resident-move-out-dialog" aria-labelledby="resident-move-out-title">
    <form class="modal-card" id="resident-move-out-form"><input type="hidden" name="resident_id"><div class="modal-header"><div><p class="eyebrow">สิ้นสุดการเข้าพัก</p><h2 id="resident-move-out-title">ย้ายผู้พักออก</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div><p class="modal-lead" id="resident-move-out-summary"></p><div class="security-note"><strong>ตรวจบิลปิดรอบก่อนย้ายออก</strong><span>ต้องไม่มีบิลค้าง และต้องมีบิลของเดือนที่ย้ายออกซึ่งชำระแล้ว กรุณาจดมิเตอร์ปลายงวดและออกบิลให้ครบก่อนดำเนินการ</span></div><div class="form-grid"><label class="field"><span>วันที่ย้ายออก</span><input name="move_out_date" type="date" required></label></div><p class="form-error" id="resident-move-out-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-danger" type="submit">ยืนยันย้ายออก</button></div></form>
</dialog>

<dialog class="modal" id="payment-close-dialog" aria-labelledby="payment-close-title">
    <form class="modal-card" id="payment-close-form"><input type="hidden" name="payment_id"><div class="modal-header"><div><p class="eyebrow">กู้รายการตรวจสลิปค้าง</p><h2 id="payment-close-title">ปิดรายการชำระถาวร</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div><p class="modal-lead" id="payment-close-summary"></p><div class="security-note"><strong>ปิดแล้วตรวจซ้ำรายการนี้ไม่ได้ และบิลยังไม่เป็นชำระแล้ว</strong><span>สลิปจะถูกเก็บไว้พร้อมผลปฏิเสธ ส่งไฟล์เดิมอีกครั้งจะได้ผลเดิม หากผู้พักโอนแล้วหรือระบบตรวจขัดข้อง ให้ตรวจยอดและลองตรวจซ้ำก่อนปิด การปิดรายการไม่ได้หมายความว่าผู้พักต้องโอนใหม่</span></div><div class="form-grid"><label class="field"><span>เหตุผล</span><textarea name="reason" minlength="3" maxlength="450" rows="4" required placeholder="ระบุเหตุผลและผลตรวจสอบก่อนปิดรายการ"></textarea></label></div><p class="form-error" id="payment-close-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-danger" type="submit">ปิดรายการถาวร</button></div></form>
</dialog>

<dialog class="modal" id="user-dialog" aria-labelledby="user-dialog-title">
    <form class="modal-card" id="user-form" data-guard-draft><input type="hidden" name="id"><div class="modal-header"><div><p class="eyebrow">เฉพาะ Owner</p><h2 id="user-dialog-title">เพิ่มผู้ดูแล</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div><div class="form-grid"><label class="field"><span>ชื่อผู้ใช้</span><input name="username" type="text" minlength="3" maxlength="64" required autocomplete="username"></label><label class="field"><span>รหัสผ่าน</span><input name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password"><small id="user-password-help">อย่างน้อย 12 ตัวอักษร</small></label><label class="field"><span>บทบาท</span><select name="role" required><option value="admin">ผู้ดูแล (Admin)</option><option value="owner">เจ้าของ (Owner)</option></select></label><label class="check-field"><input name="is_active" type="checkbox" value="1" checked><span>เปิดใช้งานบัญชี</span></label></div><p class="form-error" id="user-form-error" role="alert" hidden></p><div class="form-actions"><button class="button button-ghost" type="button" data-close-dialog>ยกเลิก</button><button class="button button-primary" type="submit">บันทึกผู้ดูแล</button></div></form>
</dialog>

<dialog class="modal" id="preview-dialog" aria-labelledby="preview-title">
    <div class="modal-card modal-card-wide"><div class="modal-header"><div><p class="eyebrow">ยังไม่สร้างบิล</p><h2 id="preview-title">ตรวจยอดก่อนออกบิล</h2></div><button class="text-control" type="button" data-close-dialog aria-label="ปิด">ปิด</button></div><div class="preview-list" id="bill-preview-content"></div><div class="form-actions"><button class="button button-primary" type="button" data-close-dialog>ตรวจแล้ว</button></div></div>
</dialog>

<dialog class="modal confirm-modal" id="confirm-dialog" aria-labelledby="confirm-title">
    <div class="modal-card"><h2 id="confirm-title">ยืนยันการทำรายการ</h2><p id="confirm-message"></p><div class="form-actions"><button class="button button-ghost" type="button" data-confirm-cancel>ยกเลิก</button><button class="button button-danger" type="button" data-confirm-accept>ยืนยัน</button></div></div>
</dialog>
