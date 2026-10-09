// Mocked UI smoke test. It renders templates directly and never loads application/database configuration.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const output = path.resolve(root, '../tmp/daily-ui-20261009'); fs.mkdirSync(output, { recursive: true });
const renderPath = path.join(output, 'render.php');
fs.writeFileSync(renderPath, `<?php function e($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}\n$appTimezone='Asia/Bangkok';$csrfToken='mock-daily-csrf';$title='Daily fixture';$user=['role'=>'owner','username'=>'Fixture'];\nforeach(['daily-home'=>'public/daily.php','admin-console'=>'admin/console.php'] as $page=>$name){$contentTemplate=${JSON.stringify(root.replaceAll('\\', '/'))}.'/templates/'.$name;ob_start();require ${JSON.stringify(root.replaceAll('\\', '/'))}.'/templates/layout.php';file_put_contents(__DIR__.'/'.$page.'.html',ob_get_clean());}\n`);
execFileSync(process.env.PHP_BINARY || 'php', ['-n', renderPath]);
const mime = { '.css': 'text/css', '.js': 'application/javascript', '.jpg': 'image/jpeg' };
const server = createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const file = url.pathname === '/daily' ? path.join(output, 'daily-home.html') : url.pathname === '/admin' ? path.join(output, 'admin-console.html') : path.join(root, 'public', url.pathname);
  if (!file.startsWith(root) && !file.startsWith(output)) { res.writeHead(403).end(); return; }
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
  res.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'text/html; charset=utf-8', 'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'" }); res.end(fs.readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); const base = `http://127.0.0.1:${server.address().port}`;
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'msedge', headless: true });
const errors = [], requests = [];
const room = { id: 2, room_code: 'D02', floor: 1, room_type: 'มาตรฐาน', rental_mode: 'daily', monthly_rent: '0.00', daily_rate: '500.00', nightly_rate: '500.00', daily_deposit: '100.00', max_guests: 2, housekeeping_status: 'ready', housekeeping_version: 1, image_url: '/assets/images/rooms/room-standard.jpg', status: 'available', can_delete: true, in_use: false, room_version: 'mock-room-version' };
let booking = null, paid = false, reviewPayment = null, reviewClosed = [], evidenceAvailable = true, providerReady = false, instructionExists = false;
const quote = input => { const nights = (Date.parse(input.check_out_date) - Date.parse(input.check_in_date)) / 86400000; return { ...room, ...input, room_id: 2, guests: Number(input.guests), nights, room_amount: (nights * 500).toFixed(2), deposit_amount: '100.00', total_amount: (nights * 500 + 100).toFixed(2), quote_token: 'mock-quote-token', quote_expires_at: '2099-01-01T00:00:00Z' }; };
const reviewedRow = row => row ? {...row,evidence_available:evidenceAvailable,evidence_error:evidenceAvailable?null:'SLIP_FILE_UNREADABLE',can_close:row.status==='pending'&&!row.verifying,can_retry:['pending','closed'].includes(row.status)&&evidenceAvailable&&!row.verifying,can_restore:!evidenceAvailable&&!row.verifying} : null;
const payment = () => {
  const active=reviewPayment?reviewedRow(reviewPayment):paid?{id:8,booking_id:booking.id,status:'verified',method:'cash',receipt_reference:'MOCK-RECEIPT',amount:booking.total_amount,transfer_amount:booking.total_amount}:null;
  const closed=reviewClosed.map(reviewedRow), unresolved=closed.length>0, hasActive=active&&['pending','verified'].includes(active.status);
  return {booking_id:booking.id,booking_status:booking.status,version:booking.version,cash_available:!paid&&!active&&!instructionExists&&booking.status==='pending',has_transfer_instruction:instructionExists,has_closed_unresolved:unresolved,closed_payments:closed,can_generate_qr:providerReady&&!hasActive&&!unresolved,can_upload:instructionExists&&!hasActive&&!unresolved,can_owner_upload:instructionExists&&!hasActive,paid,payment:active,received_amount:paid?booking.total_amount:'0.00',refunded_amount:'0.00',refundable_amount:paid?booking.deposit_amount:'0.00',deposit_remaining:paid?booking.deposit_amount:'0.00',capabilities:{promptpay_ready:providerReady,slip_verification_ready:providerReady}};
};
async function context(width) {
  const ctx = await browser.newContext({ viewport: { width, height: 920 } });
  await ctx.route('**/*', async route => {
    const req = route.request(), url = new URL(req.url()); if (url.origin !== base) { await route.abort(); return; }
    if (!url.pathname.startsWith('/api/')) { await route.continue(); return; }
    const jsonBody=(req.headers()['content-type']||'').includes('application/json')?req.postDataJSON():null;
    requests.push({ path: url.pathname, method: req.method(), headers: req.headers(), body: jsonBody });
    const input = req.method() === 'GET' ? Object.fromEntries(url.searchParams) : jsonBody || {}; let data;
    if (url.pathname === '/api/public/daily/availability') data = { items: [quote(input)] };
    else if (url.pathname === '/api/public/daily/quote') data = quote(input);
    else if (req.method() === 'POST' && ['/api/public/daily/bookings', '/api/admin/daily/bookings'].includes(url.pathname)) { booking = { ...quote(input), id: 10, reference_no: 'DAY-MOCK-10', full_name: input.full_name, phone: input.phone, version: 1, status: 'pending', expires_at: '2099-01-01T00:00:00Z', access_token: 'a'.repeat(64) }; data = booking; }
    else if (url.pathname === '/api/public/daily/bookings/10') data = booking;
    else if (/\/daily\/bookings\/10\/payment$/.test(url.pathname)) data = payment();
    else if (url.pathname === '/api/admin/daily/bookings/10/cash') { assert.equal(input.expected_version, booking.version); assert.equal(typeof input.idempotency_key, 'string'); assert.equal('request_key' in input, false); paid = true; booking.status = 'confirmed'; booking.version += 1; data = { ...payment().payment, booking_status: booking.status, version: booking.version }; }
    else if (url.pathname === '/api/admin/daily/bookings/10/confirm') { assert.equal(input.expected_version, booking.version); booking.status = 'confirmed'; booking.version += 1; data = booking; }
    else if (url.pathname === '/api/admin/daily/bookings/10/check-in') { assert.equal(input.expected_version, booking.version); booking.status = 'checked_in'; booking.version += 1; data = booking; }
    else if(url.pathname==='/api/admin/daily/payments/9/close'){assert.equal(input.expected_version,booking.version);assert.equal(typeof input.idempotency_key,'string');reviewPayment.status='closed';reviewClosed=[{...reviewPayment}];data={...reviewedRow(reviewPayment),booking_status:booking.status,version:booking.version};}
    else if(url.pathname==='/api/admin/daily/payments/9/restore'){assert.ok(req.headers()['content-type'].startsWith('multipart/form-data;'));evidenceAvailable=true;data={...reviewedRow(reviewPayment),booking_status:booking.status,version:booking.version};}
    else if(url.pathname==='/api/admin/daily/payments/9/retry'){assert.equal(evidenceAvailable,true);reviewPayment.status='verified';reviewClosed=[];paid=true;booking.status='confirmed';booking.version+=1;data={...reviewedRow(reviewPayment),booking_status:booking.status,version:booking.version};}
    else if (url.pathname === '/api/admin/daily/bookings') data = { items: booking ? [booking] : [] };
    else if (url.pathname === '/api/admin/daily/calendar') data = { items: booking ? [booking] : [], rooms: [room], blocks: [], from: input.from, to: input.to };
    else if (url.pathname === '/api/admin/rooms') data = [room];
    else if (url.pathname === '/api/admin/bookings') data = { items: [], pending_count: 0, next_offset: 0, has_more: false };
    else if (url.pathname === '/api/admin/payments') data = { items: [], pending_count: 0, next_offset: 0, has_more: false };
    else data = {};
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data }) });
  }); return ctx;
}
try {
  for (const width of [1366, 390, 320]) {
    const ctx = await context(width), page = await ctx.newPage(); page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/daily'); await page.locator('#daily-search-form [type="submit"]').click(); await page.locator('#daily-room-grid button').click();
    await page.locator('#daily-booking-form [type="submit"]:not([disabled])').waitFor();
    assert.equal(await page.locator('#daily-quote-summary').getByText('฿600.00', { exact: true }).count(), 1);
    await page.screenshot({ path: path.join(output, `public-${width}.png`), fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'public has no page-width overflow');
    await page.locator('#daily-booking-form [name="full_name"]').fill('Mock Guest'); await page.locator('#daily-booking-form [name="phone"]').fill('0812345678'); await page.locator('#daily-booking-form [type="submit"]').click();
    await page.locator('#daily-booking-dialog').waitFor({ state: 'hidden' }); await page.locator('#daily-detail').getByText('DAY-MOCK-10', { exact: true }).waitFor();
    assert.equal(await page.locator('#daily-load-qr').isDisabled(), true);
    const access = await page.evaluate(() => JSON.parse(sessionStorage.getItem('dorm.daily.access.v1'))); assert.deepEqual(Object.keys(access).sort(), ['id', 'token']);
    await page.reload(); await page.locator('#daily-booking-summary').getByText('DAY-MOCK-10', { exact: true }).waitFor();
    const guestRead = requests.filter(r => r.path === '/api/public/daily/bookings/10').at(-1); assert.equal(guestRead.headers['x-booking-access-token'], 'a'.repeat(64));
    await page.goto(base + '/admin#daily'); await page.locator('#daily-admin-rows tr').waitFor(); await page.screenshot({ path: path.join(output, `owner-${width}.png`), fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'owner has no page-width overflow');
    await ctx.close(); console.log(`PASS daily search/quote/create/restoration + owner layout ${width}px`);
  }
  const ctx = await context(1366), page = await ctx.newPage(); page.on('pageerror', e => errors.push(e.message)); paid = false;
  await page.goto(base + '/admin#daily'); await page.locator('#daily-admin-rows tr').waitFor(); await page.locator('#daily-owner-create').click();
  await page.locator('#daily-owner-create-form [name="full_name"]').fill('Owner Walk In'); await page.locator('#daily-owner-create-form [name="phone"]').fill('0812345679'); await page.locator('#daily-owner-create-form [name="guests"]').fill('1');
  await page.locator('#daily-owner-review').click(); await page.locator('#daily-owner-create-form [type="submit"]:not([disabled])').waitFor(); await page.locator('#daily-owner-create-form [type="submit"]').click(); await page.locator('#daily-owner-create-dialog').waitFor({ state: 'hidden' });
  await page.locator('#daily-admin-rows').getByRole('button', { name: 'รายละเอียด / การเงิน' }).click(); await page.locator('#daily-cash-form').waitFor(); await page.locator('#daily-cash-form [name="reference"]').fill('MOCK-RECEIPT'); await page.locator('#daily-cash-form [type="submit"]').click(); await page.locator('#confirm-dialog [data-confirm-accept]').click();
  await page.locator('#daily-owner-payment-status').getByText('ได้รับเงินครบ', { exact: false }).waitFor(); await page.locator('#daily-owner-detail-dialog [data-close-dialog]').click();
  await page.locator('#daily-admin-rows').getByRole('button', { name: 'เช็กอิน', exact: true }).waitFor();
  await page.locator('#daily-admin-rows').getByRole('button', { name: 'เช็กอิน', exact: true }).click(); await page.locator('#confirm-dialog [data-confirm-accept]').click(); await page.locator('#daily-admin-rows').getByRole('button', { name: 'เช็กเอาต์', exact: true }).waitFor();
  console.log('PASS owner quote/create/cash receipt with automatic confirmation/check-in integration');
  booking.status='pending';booking.version=1;paid=false;instructionExists=true;providerReady=true;evidenceAvailable=false;reviewPayment={id:9,booking_id:booking.id,method:'slip',status:'pending',amount:booking.total_amount,transfer_amount:(Number(booking.total_amount)+.13).toFixed(2),verifying:false};
  await page.goto(base+'/admin#daily');await page.locator('#daily-admin-rows').getByRole('button',{name:'รายละเอียด / การเงิน'}).click();await page.getByRole('button',{name:'พักการตรวจหลักฐาน',exact:true}).click();await page.locator('#daily-close-form [name="reason"]').fill('หลักฐานเดิมอ่านไม่ได้ รอไฟล์ต้นฉบับ');await page.locator('#daily-close-form [type="submit"]').click();await page.locator('#confirm-dialog [data-confirm-accept]').click();await page.locator('#daily-owner-payment-status').getByText('พักการตรวจ',{exact:false}).waitFor();
  assert.equal(await page.locator('#daily-cash-form').isHidden(),true);
  await page.getByRole('button',{name:'เลือกไฟล์ต้นฉบับเพื่อซ่อม'}).click();await page.locator('#daily-owner-restore-form [name="slip"]').setInputFiles(path.join(root,'public/assets/images/rooms/room-standard.jpg'));await page.locator('#daily-owner-restore-form [type="submit"]').click();await page.getByRole('button',{name:'กลับมาตรวจหลักฐานเดิม'}).waitFor();await page.getByRole('button',{name:'กลับมาตรวจหลักฐานเดิม'}).click();await page.locator('#confirm-dialog [data-confirm-accept]').click();await page.locator('#daily-owner-payment-status').getByText('ได้รับเงินครบ',{exact:false}).waitFor();
  console.log('PASS owner closes unknown verification, restores original evidence, and retries the same receipt without manual payment approval');
  reviewPayment.status='closed';reviewClosed=[{...reviewPayment}];paid=false;booking.status='expired';booking.version=3;
  await page.evaluate(()=>sessionStorage.setItem('dorm.daily.access.v1',JSON.stringify({id:10,token:'a'.repeat(64)})));await page.goto(base+'/daily');await page.locator('#daily-payment-status').getByText('พักการตรวจ',{exact:false}).waitFor();assert.equal(await page.locator('#daily-load-qr').isDisabled(),true);assert.equal(await page.locator('#daily-slip-form').isHidden(),true);
  console.log('PASS public closed/expired evidence never invites another transfer or reopens owner review');
  await ctx.close(); assert.deepEqual(errors, []);
  console.log('PASS no JavaScript errors, no external network calls, capability only in header');
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
