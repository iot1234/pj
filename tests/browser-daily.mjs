// Opt-in actual browser journey against the disposable daily fixture created by the test operator.
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
const base = process.env.DAILY_TEST_URL || 'http://127.0.0.1:18959';
if (process.env.APP_ENV !== 'testing' || !/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Testing environment and disposable loopback daily fixture required');
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const output = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../tmp/daily-ui-20261009'); fs.mkdirSync(output, { recursive: true });
const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'msedge', headless: true });
const context = await browser.newContext({ viewport: { width: 1366, height: 920 } });
await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
const page = await context.newPage(), errors = []; page.on('pageerror', e => errors.push(e.message));
const suffix = String(Date.now()).slice(-7), guestName = `Daily Public Browser ${suffix}`, ownerName = `Daily Owner Browser ${suffix}`;
const offset = (date, days) => new Date(Date.parse(`${date}T00:00:00Z`) + days * 86400000).toISOString().slice(0, 10);
async function confirm() { await page.locator('#confirm-dialog [data-confirm-accept]').click(); }
async function publicCard() { return page.locator('#daily-room-grid .room-card').filter({ hasText: 'DAILY-PUBLIC' }); }
async function ownerRow() { return page.locator('#daily-admin-rows tr').filter({ hasText: ownerName }); }
async function api(url, method = 'GET', body) { return page.evaluate(async ({ url, method, body }) => { const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, ...(body ? { body: JSON.stringify(body) } : {}) }); return { status: response.status, ...await response.json() }; }, { url, method, body }); }
try {
  if (!['rooms', 'visual'].includes(process.env.DAILY_TEST_RESUME)) {
  assert.equal((await context.request.get(base+'/api/admin/daily/bookings')).status(),401);
  await page.goto(base + '/daily');
  assert.equal((await context.request.post(base+'/api/public/daily/bookings',{headers:{Origin:base,'Content-Type':'application/json'},data:{}})).status(),403);
  const today = await page.locator('#daily-search-form [name="check_in_date"]').inputValue();
  await page.locator('#daily-search-form [type="submit"]').click(); await (await publicCard()).getByRole('button').click(); await page.locator('#daily-booking-form [type="submit"]:not([disabled])').waitFor();
  assert.equal(await page.locator('#daily-quote-summary').getByText('฿750.00', { exact: true }).count(), 1);
  await page.locator('#daily-booking-form [name="full_name"]').fill(guestName); await page.locator('#daily-booking-form [name="phone"]').fill('088' + suffix);
  let savedId = null, firstPayload = null, replayPayload = null, once = false;
  await page.route('**/api/public/daily/bookings', async route => {
    if (route.request().method() !== 'POST') { await route.continue(); return; }
    const payload = route.request().postDataJSON();
    if (!once) { once = true; firstPayload = payload; const response = await route.fetch(); const envelope = await response.json(); assert.equal(response.status(), 201); savedId = envelope.data.id; await route.abort('failed'); }
    else { replayPayload = payload; await route.continue(); }
  });
  await page.locator('#daily-booking-form [type="submit"]').click(); await page.locator('#daily-recovery').waitFor(); await page.locator('#daily-booking-dialog [data-close-dialog]').first().click();
  await page.locator('#daily-recover-submit').click(); await page.locator('#daily-booking-summary').getByText('หมายเลขอ้างอิง', { exact: true }).waitFor();
  assert.deepEqual(replayPayload, firstPayload); const access = await page.evaluate(() => JSON.parse(sessionStorage.getItem('dorm.daily.access.v1'))); assert.equal(access.id, savedId); assert.equal(typeof access.token, 'string');
  assert.equal((await context.request.get(base+`/api/public/daily/bookings/${savedId}`,{headers:{'X-Booking-Access-Token':'0'.repeat(64)}})).status(),404);
  await page.reload(); await page.locator('#daily-booking-summary').getByText('หมายเลขอ้างอิง', { exact: true }).waitFor(); assert.equal(await page.evaluate(() => JSON.parse(sessionStorage.getItem('dorm.daily.access.v1')).token), access.token);
  assert.equal(await page.locator('#daily-load-qr').isDisabled(), true); assert.match(await page.locator('#daily-payment-help').textContent(), /ยังไม่ได้เปิดรับชำระด้วย QR/);
  assert.equal(new URL(page.url()).search, ''); await page.unroute('**/api/public/daily/bookings'); console.log('PASS actual public search/quote/committed response loss/exact replay/stable capability restoration');
  console.log('PASS actual unauthenticated owner denial/missing guest CSRF/wrong booking capability');
  for (const width of [1366, 390, 320]) { await page.setViewportSize({ width, height: 920 }); await page.waitForFunction(() => !document.querySelector('#toast-region .toast-success')); await page.screenshot({ path: path.join(output, `actual-public-${width}.png`), fullPage: true }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true); }
  await page.setViewportSize({ width: 1366, height: 920 }); await page.goto(base + '/admin/login'); await page.locator('[name="username"]').fill('daily_browser_owner'); await page.locator('[name="password"]').fill('Daily-Browser-Fixture-Only-2026!'); await page.locator('#admin-login-form [type="submit"]').click(); await page.waitForURL(base + '/admin');
  assert.equal((await context.request.put(base+'/api/admin/rooms/2147483647',{headers:{Origin:base,'Content-Type':'application/json'},data:{expected_version:'negative-csrf-probe',daily_rate:'300.00'}})).status(),403);
  await page.goto(base + '/admin#daily'); await page.locator('#daily-admin-rows tr').first().waitFor(); await page.locator('#daily-owner-create').click();
  const roomValue = await page.locator('#daily-owner-create-form [name="room_id"] option').evaluateAll(options => options.find(o => o.textContent.includes('DAILY-OWNER')).value); await page.locator('#daily-owner-create-form [name="room_id"]').selectOption(roomValue);
  await page.locator('#daily-owner-create-form [name="guests"]').fill('1'); await page.locator('#daily-owner-create-form [name="full_name"]').fill(ownerName); await page.locator('#daily-owner-create-form [name="phone"]').fill('089' + suffix); await page.locator('#daily-owner-review').click(); await page.locator('#daily-owner-create-form [type="submit"]:not([disabled])').waitFor();
  assert.equal(await page.locator('#daily-owner-quote-summary').getByText('฿550.00', { exact: true }).count(), 1); await page.locator('#daily-owner-create-form [type="submit"]').click(); await page.locator('#daily-owner-create-dialog').waitFor({ state: 'hidden' }); await (await ownerRow()).waitFor();
  await page.locator('#daily-owner-detail-dialog').waitFor({ state: 'visible' }); await page.locator('#daily-cash-form').waitFor(); await page.locator('#daily-cash-form [name="reference"]').fill('BROWSER-CASH-' + suffix); await page.locator('#daily-cash-form [type="submit"]').click(); await confirm(); await page.locator('#daily-owner-payment-status').getByText('ได้รับเงินครบ', { exact: false }).waitFor();
  await page.locator('#daily-owner-detail-dialog [data-close-dialog]').click(); await (await ownerRow()).getByRole('button', { name: 'เช็กอิน', exact: true }).click(); await confirm(); await (await ownerRow()).getByRole('button', { name: 'เช็กเอาต์', exact: true }).waitFor();
  console.log('PASS actual owner quote/create/cash receipt automatically confirms/check-in');
  await (await ownerRow()).getByRole('button', { name: 'รายละเอียด / การเงิน' }).click(); await page.locator('#daily-deposit-form').waitFor(); await page.locator('#daily-deposit-form [name="retained_amount"]').fill('100.00'); await page.locator('#daily-deposit-form [name="reason"]').fill('บันทึกหักค่าประกันจากข้อมูลทดสอบเท่านั้น'); await page.locator('#daily-deposit-form [type="submit"]').click(); await confirm();
  await page.waitForFunction(() => document.querySelector('#daily-owner-payment-totals dd:last-child')?.textContent === '฿0.00'); await page.locator('#daily-owner-detail-dialog [data-close-dialog]').click(); await (await ownerRow()).getByRole('button', { name: 'เช็กเอาต์', exact: true }).click(); await confirm(); await (await ownerRow()).getByText('เช็กเอาต์แล้ว', { exact: true }).waitFor();
  const roomOperation = page.locator('#daily-housekeeping article').filter({ hasText: 'DAILY-OWNER' }); await roomOperation.getByRole('button', { name: 'ยืนยันทำความสะอาดเสร็จ' }).click(); await confirm(); await roomOperation.getByText('ทำความสะอาดแล้ว', { exact: true }).waitFor();
  await roomOperation.getByRole('button', { name: 'ปิดขายช่วงวัน' }).click(); await page.locator('#daily-block-form [name="start_date"]').fill(offset(today, 3)); await page.locator('#daily-block-form [name="end_date"]').fill(offset(today, 4)); await page.locator('#daily-block-form [name="reason"]').fill('ช่วงปิดขายจากการทดสอบ browser'); await page.locator('#daily-block-form [type="submit"]').click(); await page.locator('#daily-block-dialog').waitFor({ state: 'hidden' }); await roomOperation.getByRole('button', { name: 'ยกเลิกช่วงปิดขาย' }).click(); await confirm(); await roomOperation.getByRole('button', { name: 'ยกเลิกช่วงปิดขาย' }).waitFor({ state: 'hidden' });
  console.log('PASS actual deposit retention/check-out/versioned housekeeping/room block/release');
  const bookings = await api('/api/admin/daily/bookings'); assert.equal(bookings.status, 200); assert.equal(bookings.data.items.filter(b => b.full_name === guestName).length, 1); assert.equal(bookings.data.items.filter(b => b.full_name === ownerName).length, 1); assert.equal(bookings.data.items.find(b => b.full_name === ownerName).status, 'checked_out');
  } else {
    await page.goto(base + '/admin/login'); await page.locator('[name="username"]').fill('daily_browser_owner'); await page.locator('[name="password"]').fill('Daily-Browser-Fixture-Only-2026!'); await page.locator('#admin-login-form [type="submit"]').click(); await page.waitForURL(base + '/admin');
  }
  if (process.env.DAILY_TEST_RESUME !== 'visual') {
  await page.goto(base + '/admin#rooms'); await page.locator('#admin-room-rows tr').first().waitFor(); await page.locator('[data-open-room-dialog]').click(); await page.locator('#room-form [name="room_code"]').fill('DAILY-UI-' + suffix); await page.locator('#room-form [name="floor"]').fill('2'); await page.locator('#room-form [name="room_type"]').fill('ห้องทดสอบรายวัน'); await page.locator('#room-form [name="rental_mode"]').selectOption('daily');
  assert.equal(await page.locator('#room-form [name="monthly_rent"]').isDisabled(), true); await page.locator('#room-form [name="daily_rate"]').fill('300.00'); await page.locator('#room-form [name="max_guests"]').fill('2'); await page.locator('#room-form [name="daily_deposit"]').fill('0.00'); await page.locator('#room-form [type="submit"]').click(); await page.locator('#room-dialog').waitFor({ state: 'hidden' }); await page.locator('#admin-room-rows tr').filter({ hasText: 'DAILY-UI-' + suffix }).waitFor();
  console.log('PASS actual daily room creation with conditional monthly/daily validation');
  const createdRoom=(await api('/api/admin/rooms')).data.find(r=>r.room_code==='DAILY-UI-'+suffix);assert.ok(createdRoom?.room_version);
  await page.locator('#admin-room-rows tr').filter({hasText:'DAILY-UI-'+suffix}).locator('[data-action="edit-room"]').click();
  const openedVersion=await page.locator('#room-form [name="expected_version"]').inputValue();await page.locator('#room-form [name="daily_rate"]').fill('350.00');
  const otherUpdate=await api(`/api/admin/rooms/${createdRoom.id}`,'PUT',{expected_version:createdRoom.room_version,daily_rate:'325.00'});assert.equal(otherUpdate.status,200);
  const staleResponse=page.waitForResponse(r=>r.url().endsWith(`/api/admin/rooms/${createdRoom.id}`)&&r.request().method()==='PUT');await page.locator('#room-form [type="submit"]').click();assert.equal((await staleResponse).status(),409);await page.locator('#room-form-error').waitFor();
  assert.equal(await page.locator('#room-form [name="daily_rate"]').inputValue(),'350.00');assert.equal(await page.locator('#room-form [name="expected_version"]').inputValue(),openedVersion);assert.equal((await api('/api/admin/rooms')).data.find(r=>r.id===createdRoom.id).daily_rate,'325.00');
  page.once('dialog',dialog=>dialog.accept());await page.locator('#room-dialog [data-close-dialog]').first().click();await page.locator('#room-dialog').waitFor({state:'hidden'});
  console.log('PASS actual stale room version rejects overwrite and preserves the opened draft/version');
  }
  if (process.env.DAILY_TEST_RESUME === 'visual') {
    await page.goto(base + '/daily'); await page.locator('#daily-search-form [type="submit"]').click(); await page.locator('#daily-room-grid .room-card').first().waitFor();
    for (const width of [1366, 390, 320]) { await page.setViewportSize({ width, height: 920 }); await page.waitForFunction(() => !document.querySelector('#toast-region .toast-success')); await page.evaluate(() => document.activeElement?.blur()); await page.screenshot({ path: path.join(output, `actual-public-${width}.png`), fullPage: true }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true); }
  }
  await page.goto(base + '/admin#daily'); await page.locator('#daily-admin-rows tr').first().waitFor();
  for (const width of [1366, 390, 320]) { await page.setViewportSize({ width, height: 920 }); await page.waitForFunction(() => !document.querySelector('#toast-region .toast-success')); await page.waitForTimeout(300); await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: path.join(output, `actual-owner-${width}.png`), fullPage: true }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true); }
  assert.deepEqual(errors, []);
  console.log('PASS actual 1366/390/320px no overflow/JavaScript errors; no external network calls');
} finally { await browser.close(); }
