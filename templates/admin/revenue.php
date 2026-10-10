<?php

declare(strict_types=1);

$revenueWorkspaces = [
    'daily' => ['label' => 'รายวัน', 'source' => 'การจองรายวัน', 'back' => 'daily-overview'],
    'monthly' => ['label' => 'รายเดือน', 'source' => 'บิลรายเดือน', 'back' => 'overview'],
];
foreach ($revenueWorkspaces as $revenueType => $revenueWorkspace):
    $revenuePrefix = $revenueType . '-revenue';
?>
<section class="admin-view workspace-revenue" data-admin-view="<?= e($revenuePrefix) ?>" aria-labelledby="<?= e($revenuePrefix) ?>-title" hidden>
    <div class="section-heading">
        <div><p class="eyebrow">ส่วนงาน<?= e($revenueWorkspace['label']) ?></p><h2 id="<?= e($revenuePrefix) ?>-title">รายรับ<?= e($revenueWorkspace['label']) ?></h2><p>แสดงเฉพาะรายการจาก<?= e($revenueWorkspace['source']) ?>ตามเดือนที่เลือก</p></div>
        <div class="section-heading-actions"><button class="button button-secondary" type="button" data-refresh="<?= e($revenuePrefix) ?>">รีเฟรช</button></div>
    </div>
    <div class="toolbar revenue-toolbar"><label class="field"><span><?= $revenueType === 'daily' ? 'เดือนที่ระบบยืนยันรับ / บันทึกคืนเงิน' : 'เดือนที่ระบบยืนยันรับเงิน' ?></span><input id="<?= e($revenuePrefix) ?>-period" type="month" value="<?= e($maximumBillingPeriod) ?>" max="<?= e($maximumBillingPeriod) ?>" required></label><button class="button button-ghost" type="button" data-overview-jump="<?= e($revenueWorkspace['back']) ?>">กลับภาพรวม<?= e($revenueWorkspace['label']) ?></button></div>
    <div class="alert alert-error" id="<?= e($revenuePrefix) ?>-error" role="alert" hidden><div><strong>โหลดรายรับ<?= e($revenueWorkspace['label']) ?>ไม่ได้</strong><p data-error-message></p></div><button class="button button-small" type="button" data-refresh="<?= e($revenuePrefix) ?>">ลองใหม่</button></div>
    <p id="<?= e($revenuePrefix) ?>-state" role="status">กำลังโหลดรายรับ<?= e($revenueWorkspace['label']) ?>…</p>
    <div class="stats-grid stats-grid-four" id="<?= e($revenuePrefix) ?>-stats" aria-live="polite">
        <article class="stat-card"><span><?= $revenueType === 'daily' ? 'ค่าห้องที่รับแล้ว' : 'ค่าเช่า น้ำ ไฟ และรายการในบิลที่รับแล้ว' ?></span><strong id="<?= e($revenuePrefix) ?>-received">—</strong><small><?= $revenueType === 'daily' ? 'ยังไม่หักเงินคืน ไม่รวมเงินประกันและเศษยอดโอน' : 'ไม่รวมเศษยอดโอน' ?></small></article>
        <?php if ($revenueType === 'daily'): ?><article class="stat-card"><span>เงินคืน</span><strong id="<?= e($revenuePrefix) ?>-refunded">—</strong><small>รายการคืนเงินที่บันทึกแล้ว</small></article><?php endif; ?>
        <article class="stat-card"><span><?= $revenueType === 'daily' ? 'เงินเข้าออกสุทธิ' : 'ยอดรับรวมเศษยอดโอน' ?></span><strong id="<?= e($revenuePrefix) ?>-net">—</strong><small><?= $revenueType === 'daily' ? 'รวมเงินประกันและเศษยอด หักเงินคืน ไม่ใช่รายได้ค่าห้อง' : 'นับเฉพาะรายการรับเงินรายเดือนที่ยืนยันแล้ว' ?></small></article>
        <?php if ($revenueType === 'daily'): ?><article class="stat-card" id="<?= e($revenuePrefix) ?>-deposit-card"><span>เงินประกันของรายการพักที่ยังดำเนินอยู่</span><strong id="<?= e($revenuePrefix) ?>-deposit">—</strong><small>ยอดปัจจุบัน เฉพาะการจองยืนยันแล้วหรือกำลังเข้าพัก</small></article><?php endif; ?>
    </div>
    <section class="panel revenue-details" aria-labelledby="<?= e($revenuePrefix) ?>-details-title"><div class="panel-heading"><div><h3 id="<?= e($revenuePrefix) ?>-details-title">รายละเอียดรายรับ<?= e($revenueWorkspace['label']) ?></h3><p id="<?= e($revenuePrefix) ?>-ledger-note">นับตามวันที่ระบบยืนยันรับเงินหรือบันทึกคืนเงินในเดือนที่เลือก</p></div></div><div id="<?= e($revenuePrefix) ?>-details"></div></section>
    <section class="panel table-panel" aria-labelledby="<?= e($revenuePrefix) ?>-transactions-title">
        <div class="panel-heading"><div><h3 id="<?= e($revenuePrefix) ?>-transactions-title">รายการเงิน<?= e($revenueWorkspace['label']) ?> <span id="<?= e($revenuePrefix) ?>-heading-month"></span></h3><p>ตรวจรายการอ้างอิงจาก<?= e($revenueWorkspace['source']) ?></p></div></div>
        <div class="table-scroll" role="region" aria-label="รายการเงิน<?= e($revenueWorkspace['label']) ?>" tabindex="0"><table><thead><tr><th><?= $revenueType === 'daily' ? 'วันที่ยืนยัน / บันทึก' : 'วันที่ยืนยันรับเงิน' ?></th><th>รายการ / อ้างอิง</th><th>ห้อง</th><th>ประเภท</th><th class="align-right">เงินรับ</th><th class="align-right"><?= $revenueType === 'daily' ? 'เงินคืน / หักประกัน' : 'รอบบิล' ?></th></tr></thead><tbody id="<?= e($revenuePrefix) ?>-rows"></tbody></table></div>
    </section>
</section>
<?php endforeach; ?>
