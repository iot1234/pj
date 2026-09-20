'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const paths = ['admin/console.php', 'admin/login.php', 'public/home.php', 'resident/login.php', 'resident/portal.php'];
const templates = paths.map(name => [name, read('templates/' + name)]);
const forbidden = /[\p{Extended_Pictographic}\u2190-\u2bff\u203a\ufe0f\u200d]/u;
test('all first-party page templates omit emoji and decorative icon glyphs', () => {
  for (const [name, html] of templates) assert.doesNotMatch(html, forbidden, name);
});
test('navigation is text-only and preserves admin and resident unread badges', () => {
  for (const [name, html] of templates) {
    for (const match of html.matchAll(/<nav\b[^>]*>[\s\S]*?<\/nav>/g)) assert.doesNotMatch(match[0], /aria-hidden="true"|<svg\b|<img\b|integration-icon/, name);
  }
  const admin = read('templates/admin/console.php'), resident = read('templates/resident/portal.php');
  for (const id of ['booking-nav-count', 'payment-nav-count', 'booking-bottom-count', 'payment-bottom-count']) assert.ok(admin.includes(`id="${id}"`));
  assert.ok(resident.includes('id="resident-unpaid-count"'));
});
test('formerly icon-only close and mobile menu controls retain visible Thai labels and actions', () => {
  for (const [, html] of templates) {
    const controls = [...html.matchAll(/<button\b[^>]*class="text-control[^>]*>[\s\S]*?<\/button>/g)];
    for (const [button] of controls) {
      assert.match(button, /data-close-dialog|data-admin-menu-toggle/);
      assert.match(button, />(?:ปิด|เมนู)<\/button>$/);
      assert.match(button, /type="button"/);
    }
  }
  assert.ok(read('templates/admin/console.php').includes('data-admin-menu-toggle>เมนู</button>'));
});
test('removing icons never removes meaningful payment, warning or required-field content', () => {
  const admin = read('templates/admin/console.php'), resident = read('templates/resident/portal.php'), guest = read('templates/public/home.php');
  assert.ok(admin.includes('id="confirm-message"')); assert.ok(admin.includes('QR นี้ชี้บัญชีจริง'));
  assert.ok(resident.includes('บิลนี้ชำระแล้ว')); assert.ok(resident.includes('id="resident-load-qr"'));
  assert.ok(guest.includes('รับคำขอจองเรียบร้อยแล้ว')); assert.ok(guest.includes('name="full_name"'));
  assert.match(guest, /name="phone"[^>]*required/);
  for (const [, html] of templates) assert.doesNotMatch(html, /class="(?:empty-icon|success-mark|confirm-icon|integration-icon|resident-login-art)"/);
});
test('text navigation does not inherit fixed icon widths or floating icon-position badges', () => {
  const css = read('public/assets/css/app.css');
  assert.doesNotMatch(css, /\.admin-nav-item > span:first-child|\.nav-item > span:first-child/);
  assert.match(css, /\.text-control \{[^}]*min-height: 44px/);
  assert.match(css, /\.admin-bottom-nav \.nav-count \{[^}]*position: static/);
  assert.ok(css.includes('auto minmax(110px, auto) max-content'));
});
test('resident bill details have a text affordance instead of an arrow', () => {
  assert.ok(read('templates/resident/portal.php').includes('<span class="bill-row-detail">ดูบิล</span>'));
  assert.doesNotMatch(read('public/assets/css/app.css'), /bill-row-arrow/);
});
test('LINE replies omit emoji but preserve success explanations and private-data checks', () => {
  const bot = read('src/Domain/LineBotService.php');
  assert.doesNotMatch(bot, /\p{Extended_Pictographic}/u);
  assert.ok(bot.includes('ผูกบัญชี LINE สำเร็จ')); assert.ok(bot.includes('ยืนยันการผูก LINE แล้ว'));
});
