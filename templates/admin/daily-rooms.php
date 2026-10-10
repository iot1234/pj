<?php declare(strict_types=1); ?>
<section class="admin-view" data-admin-view="daily-rooms" aria-labelledby="daily-rooms-title" hidden>
    <div class="section-heading">
        <div><p class="eyebrow">ส่วนงานรายวัน</p><h2 id="daily-rooms-title">ห้องรายวัน</h2><p>ตั้งราคาต่อคืน จำนวนผู้พักสูงสุด และค่าประกันสำหรับการพักรายวัน</p></div>
        <div class="section-heading-actions"><button class="button button-secondary" type="button" data-refresh="daily-rooms">รีเฟรช</button><button class="button button-primary" type="button" data-open-daily-room-dialog>เพิ่มห้องรายวัน</button></div>
    </div>
    <div class="security-note"><strong>บันทึกห้องก่อนรับจองรายวัน</strong><span>ราคาต่อคืนรวมค่าน้ำและไฟแล้ว การเพิ่มห้องยังไม่สร้างการจอง ตรวจช่วงว่างในปฏิทินก่อนรับผู้พัก</span></div>
    <div class="form-actions"><button class="button button-primary" type="button" data-overview-jump="daily">ไปจัดการจองรายวัน</button><button class="button button-secondary" type="button" data-overview-jump="daily-overview">ดูภาพรวมรายวัน</button></div>
    <div class="stats-grid workspace-stats" id="daily-room-stats" aria-live="polite">
        <article class="stat-card"><span>ห้องรายวันทั้งหมด</span><strong id="daily-room-stat-all">—</strong></article>
        <article class="stat-card stat-available"><span>ว่างตามสถานะห้อง</span><strong id="daily-room-stat-available">—</strong></article>
        <article class="stat-card stat-reserved"><span>มีการจองรอเข้าพัก</span><strong id="daily-room-stat-reserved">—</strong></article>
        <article class="stat-card stat-occupied"><span>มีผู้พักอยู่</span><strong id="daily-room-stat-occupied">—</strong></article>
        <article class="stat-card"><span>รอทำความสะอาด</span><strong id="daily-room-stat-cleaning">—</strong></article>
    </div>
    <p class="field-hint">สถานะห้องและความสะอาดยังไม่ยืนยันว่าขายได้ทุกวันที่เลือก ให้ตรวจช่วงว่างจากปฏิทินในหน้าจองรายวัน</p>
    <div class="toolbar">
        <label class="search-field"><span class="sr-only">ค้นหาห้องรายวัน</span><input type="search" id="daily-room-search" placeholder="ค้นหารหัสห้อง ชั้น หรือประเภท…"></label>
        <label><span class="sr-only">สถานะห้องรายวัน</span><select id="daily-room-status"><option value="">ทุกสถานะ</option><option value="available">ว่าง</option><option value="reserved">รอเข้าพัก</option><option value="occupied">มีผู้พัก</option><option value="cleaning">รอทำความสะอาด</option></select></label>
    </div>
    <div class="panel table-panel">
        <div class="table-scroll" role="region" aria-label="ตารางห้องรายวัน" tabindex="0"><table><thead><tr><th>ห้อง</th><th>ชั้น</th><th>ประเภท</th><th>ราคาต่อคืน / ค่าประกัน</th><th>ผู้พักสูงสุด</th><th>สถานะ / ความสะอาด</th><th class="align-right">จัดการ</th></tr></thead><tbody id="daily-room-rows"></tbody></table></div>
        <div class="table-state" id="daily-room-state" data-state="loading" role="status"><span class="spinner" aria-hidden="true"></span><p>กำลังโหลดห้องรายวัน…</p></div>
    </div>
</section>
