'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const webcrypto = require('node:crypto').webcrypto;
const { File } = require('node:buffer');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/daily-booking.js'), 'utf8');
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; }
function node() {
  return { children: [], dataset: {}, disabled: false, hidden: false, textContent: '', value: '', listeners: {}, attributes: {},
    append(...items) { this.children.push(...items); }, replaceChildren(...items) { this.children = items; this.textContent = ''; },
    addEventListener(name, fn) { this.listeners[name] = fn; }, setAttribute(name, value) { this.attributes[name] = value; }, removeAttribute(name) { delete this.attributes[name]; },
    querySelector() { return this.submit || (this.submit = node()); }, reportValidity() { return true; }, reset() {},
  };
}
function harness(saved = {}) {
  const nodes = new Map(), storage = new Map(Object.entries(saved)), requests = [], timers=[];
  const $ = (id) => { if (!nodes.has(id)) nodes.set(id, node()); return nodes.get(id); };
  $('#daily-search-form').elements = { check_in_date: node(), check_out_date: node(), guests: Object.assign(node(), { value: '1' }) };
  $('#daily-booking-form').elements = { full_name: node(), phone: node() };
  $('#daily-slip-form').elements = { slip: Object.assign(node(), { files: [] }) };
  let serial = 0;
  const context = { window: { setInterval(callback,ms) {timers.push({callback,ms});}, crypto: { randomUUID: () => `request-${++serial}` } }, crypto: { randomUUID: () => `request-${++serial}`, subtle: webcrypto.subtle },
    document: { addEventListener() {}, visibilityState: 'visible' }, URLSearchParams, sessionStorage: { getItem: k => storage.get(k), setItem: (k, v) => storage.set(k, v), removeItem: k => storage.delete(k) },
    FormData: class { constructor(form) { this.form = form; } entries() { return Object.entries(this.form.elements).filter(([, n]) => !n.disabled).map(([k, n]) => [k, n.value]); } },
  };
  vm.createContext(context); vm.runInContext(source, context);
  const h = { $, $$: () => [], create: (_tag, className, content = '') => Object.assign(node(), { className, textContent: content }),
    api: (url, options = {}) => { const d = deferred(); requests.push({ url, options, ...d }); return d.promise; },
    errorMessage: error => error?.message || String(error), showFormError: (n, message = '') => { n.textContent = message?.message || message; n.hidden = !message; },
    money: String, formatDate: String, formatDateTime: String, isoToday: () => '2026-10-09', isoDateOffsetDays: d => `2026-10-${String(9 + d).padStart(2, '0')}`,
    setBusy: (n, busy) => { n.disabled = busy; }, setFormFieldsBusy: (form, busy) => { Object.values(form.elements).forEach(n => { n.disabled = busy; }); },
    setDialogBusy: (n, busy) => { n.dataset.dialogBusy = String(busy); }, openDialog: n => { n.open = true; }, closeDialog: n => { n.open = false; },
    getQrLibrary: async () => ({}), renderQrCanvas: async () => {},
  };
  const controller = context.window.DormDaily.initPublic(h);
  return { $, requests, storage, timers, controller, functions: context.window.DormDaily, search: () => $('#daily-search-form').listeners.submit({ preventDefault() {} }), submit: () => $('#daily-booking-form').listeners.submit({ preventDefault() {} }) };
}
function adminHarness(saved = {}) {
  const ui = harness(saved), { $, requests } = ui;
  $('#daily-admin-filter').elements = { from: node(), to: node(), status: node() };
  $('#daily-owner-create-form').elements = Object.fromEntries(['room_id', 'guests', 'check_in_date', 'check_out_date', 'full_name', 'phone'].map(name => [name, node()]));
  for (const id of ['daily-cash-form', 'daily-refund-form', 'daily-deposit-form', 'daily-close-form']) $(`#${id}`).elements = Object.fromEntries(['reference', 'amount', 'reason', 'retained_amount'].map(name => [name, node()]));
  for(const id of ['daily-owner-upload-form','daily-owner-restore-form'])$(`#${id}`).elements={slip:Object.assign(node(),{files:[]})};
  const h = { $, create: (_tag, className, content = '') => Object.assign(node(), { className, textContent: content }),
    api: (url, options = {}) => { const d = deferred(); requests.push({ url, options, ...d }); return d.promise; },
    errorMessage: e => e.message, showFormError: (n, value = '') => { n.textContent = value?.message || value; n.hidden = !value; }, money: String, formatDate: String, formatDateTime: String,
    isoToday: () => '2026-10-09', isoDateOffsetDays: d => `2026-10-${String(9 + d).padStart(2, '0')}`,
    setBusy: (n, busy) => { n.disabled = busy; }, setFormFieldsBusy: (form, busy) => { Object.values(form.elements).forEach(n => { n.disabled = busy; }); },
    setDialogBusy: (n, busy) => { n.dataset.dialogBusy = String(busy); }, openDialog: n => { n.open = true; }, closeDialog: n => { n.open = false; }, confirmAction: async () => true, toast() {},
  };
  const admin = ui.functions.initAdmin(h);
  function resolveLoad(offset, bookings = [], room = { id: 2, room_code: 'D02', rental_mode: 'daily', housekeeping_status: 'cleaning', housekeeping_version: 7, daily_rate: '500.00' }) {
    requests[offset].resolve({ items: bookings, has_more: false, next_offset: bookings.length }); requests[offset + 1].resolve({ items: bookings, blocks: [], rooms: [room] }); requests[offset + 2].resolve([room]); requests[offset + 3].resolve({ items: bookings, has_more: false, next_offset: bookings.length });
  }
  return { ...ui, admin, resolveLoad };
}
const dates = { check_in_date: '2026-10-09', check_out_date: '2026-10-10', guests: 1 };
const quote = (extra = {}) => ({ id: 2, room_id: 2, room_code: 'D02', room_type: 'มาตรฐาน', floor: 1, ...dates, nights: 1, nightly_rate: '500.00', room_amount: '500.00', deposit_amount: '100.00', total_amount: '600.00', quote_token: 'signed-quote', quote_expires_at: '2099-01-01T00:00:00Z', ...extra });
const booking = (extra = {}) => ({ ...quote(), id: 10, reference_no: 'DAY-10', version: 1, status: 'pending', expires_at: '2099-01-01T00:00:00Z', access_token: 'a'.repeat(64), ...extra });
const paymentRecord = (extra = {}) => ({ id: 5, booking_id: 10, method: 'slip', status: 'pending', amount: '600.00', transfer_amount: '600.13', verifying: false, ...extra });
const paymentSummary = (extra = {}) => ({ booking_id: 10, booking_status: 'pending', version: 1, has_transfer_instruction: true, cash_available: false, has_closed_unresolved: false, closed_payments: [], can_generate_qr: true, can_upload: true, can_owner_upload: true, paid: false, payment: null, received_amount: '0.00', refunded_amount: '0.00', refundable_amount: '0.00', deposit_remaining: '0.00', capabilities: { promptpay_ready: true, slip_verification_ready: true }, ...extra });
test('dates reject impossible calendar days, zero nights and fractional guests', () => {
  const f = harness().functions;
  for (const bad of [{ ...dates, check_in_date: '2026-02-30' }, { ...dates, check_out_date: dates.check_in_date }, { ...dates, guests: 1.5 }, { ...dates, check_out_date: '2027-01-10' }]) assert.throws(() => f.range(bad));
  assert.equal(f.range({ ...dates, check_in_date: '2026-12-31', check_out_date: '2027-01-01' }).check_out_date, '2027-01-01');
});
test('quote validation rejects a different stay and inconsistent server amounts', () => {
  const f = harness().functions;
  for (const bad of [{ guests: 2 }, { check_out_date: '2026-10-11' }, { room_id: 3 }, { room_amount: '501.00' }, { total_amount: '601.00' }, { quote_token: '' }]) assert.throws(() => f.validQuote(quote(bad), { ...dates, room_id: 2 }));
  assert.equal(f.validQuote(quote(), { ...dates, room_id: 2 }).total_amount, '600.00');
  assert.throws(()=>f.validQuote(quote({nightly_rate:'1'+'0'.repeat(300),room_amount:'1'+'0'.repeat(300),total_amount:'1'+'0'.repeat(300)}),{...dates,room_id:2}));
});
test('pending and paid payments both prevent another transfer or upload', () => {
  const f = harness().functions, b = booking(), base = paymentSummary();
  assert.equal(f.paymentGuard(b, base).mayTransfer, true);
  for (const data of [{ ...base, payment: paymentRecord() }, { ...base, paid: true, payment: paymentRecord({ status: 'verified' }) }, { ...base, payment: paymentRecord({ status: 'closed' }) }]) { const guard = f.paymentGuard(b, data); assert.equal(guard.mayTransfer, false); assert.equal(guard.mayUpload, false); }
  assert.equal(f.paymentGuard({ ...b, status: 'cancelled' }, { ...base, booking_status: 'cancelled' }).mayTransfer, false);
  assert.throws(() => f.paymentGuard(b, { ...base, booking_id: 11 }));
});
test('expired reservations accept already transferred evidence but never offer another transfer', () => {
  const f = harness().functions, expired = booking({ status: 'expired', expires_at: '2020-01-01T00:00:00Z' });
  const guard = f.paymentGuard(expired, paymentSummary({ booking_status: 'expired' }));
  assert.equal(guard.mayTransfer, false); assert.equal(guard.mayUpload, true);
  assert.equal(f.paymentGuard(expired, paymentSummary({ booking_status: 'expired', has_transfer_instruction: false })).mayUpload, false);
  assert.equal(f.paymentGuard(booking({ expires_at: '2020-01-01T00:00:00Z' }), paymentSummary()).mayTransfer, false);
});
test('closed proof history blocks another transfer even when the latest record is rejected', () => {
  const f=harness().functions, data=paymentSummary({payment:paymentRecord({id:6,status:'rejected'}),has_closed_unresolved:true,closed_payments:[paymentRecord({status:'closed'})]});
  const guard=f.paymentGuard(booking(),data);assert.equal(guard.closed,true);assert.equal(guard.mayTransfer,false);assert.equal(guard.mayUpload,false);
});
test('a different newly rejected receipt does not clear unknown-upload recovery', () => {
  const f=harness().functions,marker={booking_id:10,prior_payment_id:5};
  assert.equal(f.uploadResolved(marker,paymentRecord({id:6,status:'rejected'})),false);assert.equal(f.uploadResolved(marker,paymentRecord({id:6,status:'pending'})),true);
});
test('malformed receipt IDs, statuses and ledger totals cannot enable payment controls', () => {
  const f = harness().functions;
  for (const bad of [{ version: 0 }, { received_amount: null }, { refundable_amount: '1.00' }, { payment: paymentRecord({ id: 0 }) }, { payment: paymentRecord({ method: 'unknown' }) }, { cash_available: 'true' }, { booking_status: 'unknown' }]) assert.throws(() => f.paymentGuard(booking(), paymentSummary(bad)));
});
test('owner actions respect stay dates and confirmation happens through payment instead of an unpaid confirm button', () => {
  const f = harness().functions;
  assert.deepEqual(JSON.parse(JSON.stringify(f.bookingActions(booking(), '2026-10-09'))), [['ยกเลิก', 'cancel']]);
  const confirmed = booking({ status: 'confirmed', check_in_date: '2026-10-10', check_out_date: '2026-10-12', nights: 2, room_amount: '1000.00', total_amount: '1100.00' });
  assert.equal(f.bookingActions(confirmed, '2026-10-09').some(a => a[1] === 'check-in'), false);
  assert.equal(f.bookingActions(confirmed, '2026-10-10').some(a => a[1] === 'check-in'), true);
  assert.equal(f.bookingActions(confirmed, '2026-10-10').some(a => a[1] === 'no-show'), false);
  assert.equal(f.bookingActions(confirmed, '2026-10-12').some(a => a[1] === 'check-in'), false);
  assert.equal(f.bookingActions(confirmed, '2026-10-12').some(a => a[1] === 'no-show'), true);
});
test('a guest still checked in after planned checkout blocks future calendar cells', () => {
  const f = harness().functions, overdue = booking({ room_id: 2, status: 'checked_in', check_in_date: '2026-10-01', check_out_date: '2026-10-03' });
  const future = booking({ id: 11, room_id: 2, status: 'confirmed', check_in_date: '2026-10-15', check_out_date: '2026-10-17' });
  assert.equal(f.calendarStay([future, overdue], 2, '2026-10-15', '2026-10-09').id, overdue.id);
  assert.equal(f.calendarStay([future, { ...overdue, status: 'checked_out' }], 2, '2026-10-15', '2026-10-09').id, future.id);
  assert.equal(f.calendarStay([overdue], 3, '2026-10-15', '2026-10-09'), undefined);
});
test('monthly work counts exclude daily occupants and available inventory excludes cleaning rooms', () => {
  const app = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/app.js'), 'utf8');
  const start = app.indexOf('function overviewRoomCounts('), end = app.indexOf('function initAdminConsole()', start), context = {};
  vm.createContext(context); vm.runInContext(app.slice(start, end), context);
  const counts = context.overviewRoomCounts([{ status: 'occupied', rental_mode: 'monthly' }, { status: 'occupied', rental_mode: 'daily' }, { status: 'available', rental_mode: 'daily', housekeeping_status: 'cleaning' }, { status: 'available', rental_mode: 'daily', housekeeping_status: 'ready' }, { status: 'available', rental_mode: 'monthly' }]);
  assert.equal(counts.monthlyOccupied, 1); assert.equal(counts.occupied, 2); assert.equal(counts.available, 2);
});
test('a room that looks available still hides delete when the server reports future commitments', () => {
  const app = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/app.js'), 'utf8'), rows = node(), rendered = [];
  const context = { state: { roomListReady: true, rooms: [{ id: 2, room_code: 'D02', status: 'available', rental_mode: 'daily', daily_rate: '500.00', can_delete: false }, { id: 3, room_code: 'M03', status: 'available', rental_mode: 'monthly', monthly_rent: '3000.00', can_delete: true }] },
    $: selector => selector === '#admin-room-rows' ? rows : node(), create: () => node(), text: String, money: String, safeRoomImage: () => '', roomMatches: () => true, td: v => v, pill: String, rowActions: (...items) => items,
    actionButton: (label, action, id) => { rendered.push({ action, id }); return { label, action, id }; }, setTableState() {},
  };
  vm.createContext(context); vm.runInContext(app.slice(app.indexOf('function renderRooms()'), app.indexOf('async function loadRooms()')), context); context.renderRooms();
  assert.equal(rendered.some(a => a.id === 2 && a.action === 'delete-room'), false); assert.equal(rendered.some(a => a.id === 3 && a.action === 'delete-room'), true); assert.equal(rendered.some(a => a.id === 2 && a.action === 'add-resident-to-room'), false);
});
test('room edits submit the opened server version and retain it after an unknown write outcome', async () => {
  const app = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/app.js'), 'utf8'), form = node(), error = node(), requests = [], pending = deferred();
  form.elements = { expected_version: { value: 'opened-room-version' } }; const values = { id: '2', expected_version: 'opened-room-version', room_code: 'D02', rental_mode: 'daily', daily_rate: '550.00', max_guests: '2', daily_deposit: '100.00', amenities: '' };
  const context = { $: selector => selector === '#room-form' ? form : selector === '#room-form-error' ? error : node(), FormData: class { entries() { return Object.entries(values); } }, number: Number, beginDialogSave: () => true, finishDialogSave() {}, setBusy() {}, closeDialog() {}, toast() {}, loadRooms() {}, showFormError: (n, value) => { n.textContent = value?.message || value || ''; }, api: (url, options) => { requests.push({ url, options }); return pending.promise; } };
  vm.createContext(context); const start = app.indexOf("$('#room-form').addEventListener('submit'"), end = app.indexOf('const setStat =', start); vm.runInContext(app.slice(start, end), context);
  const work = form.listeners.submit({ preventDefault() {}, currentTarget: form }); assert.equal(requests[0].options.body.expected_version, 'opened-room-version'); assert.equal(requests[0].options.method, 'PUT');
  pending.reject(new Error('unknown result')); await work; assert.equal(form.elements.expected_version.value, 'opened-room-version'); assert.equal(values.daily_rate, '550.00'); assert.equal(error.textContent, 'unknown result');
});
test('finance success is scoped to the intended receipt and historical close outcomes do not overwrite a later retry', () => {
  const f=harness().functions,b=booking(),payload={reference:' R-001 ',amount:'40.00'};
  assert.throws(()=>f.validFinanceOutcome('refund',{},b,payload));assert.throws(()=>f.validFinanceOutcome('refund',{id:8,booking_id:11,amount:'40.00',reference_no:'R-001'},b,payload));assert.throws(()=>f.validFinanceOutcome('refund',{id:8,booking_id:10,amount:'50.00',reference_no:'R-001'},b,payload));
  assert.equal(f.validFinanceOutcome('refund',{id:8,booking_id:10,amount:'40.00',reference_no:'R-001'},b,payload).id,8);
  assert.throws(()=>f.validFinanceOutcome('close',paymentRecord(),b,{},5));assert.equal(f.validFinanceOutcome('close',paymentRecord(),b,{},5,true).status,'pending');
});
test('QR instructions require the exact booking total and a matching reserved satang adjustment', () => {
  const f = harness().functions, b = booking(), qr = { booking_id: 10, payload: '000201-mock-payload', target: '0812345678', bill_amount: '600.00', adjustment_amount: '0.13', amount: '600.13', amount_locked: true };
  assert.equal(f.validTransfer(qr, b).amount, '600.13');
  for (const bad of [{ booking_id: 11 }, { amount: '600.00' }, { bill_amount: '500.00' }, { adjustment_amount: '0.00' }, { adjustment_amount: '1.00' }, { amount_locked: false }]) assert.throws(() => f.validTransfer({ ...qr, ...bad }, b));
});
test('daily success feedback replaces the previous success and preserves persistent errors', () => {
  const items = [], notify = harness().functions.feedback((message, type) => { const item = { message, type, dataset: {}, removed: false, remove() { this.removed = true; } }; items.push(item); return item; });
  notify('สร้างการจองแล้ว'); notify('ไม่ทราบผลรายการเงิน ห้ามคืนซ้ำ', 'error'); notify('เช็กอินแล้ว'); notify('เช็กเอาต์แล้ว');
  const active = items.filter(item => !item.removed);
  assert.deepEqual(active.map(item => item.message), ['ไม่ทราบผลรายการเงิน ห้ามคืนซ้ำ', 'เช็กเอาต์แล้ว']);
  assert.equal(active.filter(item => item.type === 'success').length, 1); assert.equal(active.find(item => item.type === 'error').removed, false);
});
test('changing dates fences older availability responses and unlocks a fresh search', async () => {
  const ui = harness(), old = ui.search();
  ui.$('#daily-search-form').elements.check_out_date.value = '2026-10-11'; ui.$('#daily-search-form').listeners.input();
  assert.equal(ui.$('#daily-search-form').querySelector().disabled, false);
  const latest = ui.search(); ui.requests[1].resolve({ items: [quote({ id: 3, room_id: 3, room_code: 'D03', check_out_date: '2026-10-11', nights: 2, room_amount: '1000.00', total_amount: '1100.00' })] }); await latest;
  ui.requests[0].resolve({ items: [quote()] }); await old;
  const roomBody = ui.$('#daily-room-grid').children[0].children[0]; assert.equal(roomBody.children[0].textContent, 'ห้อง D03');
});
test('changing search criteria while quote is loading cannot enable stale booking', async () => {
  const ui = harness(), search = ui.search(); ui.requests[0].resolve({ items: [quote()] }); await search;
  const choose = publicRoomButton(ui).listeners.click();
  ui.$('#daily-search-form').elements.guests.value = '2'; ui.$('#daily-search-form').listeners.input();
  ui.requests[1].resolve(quote()); await choose;
  assert.equal(ui.$('#daily-booking-form').querySelector().disabled, true);
});
test('an unknown create outcome retries the identical payload and idempotency key', async () => {
  const ui = harness(), search = ui.search(); ui.requests[0].resolve({ items: [quote()] }); await search;
  const choose = publicRoomButton(ui).listeners.click(); ui.requests[1].resolve(quote()); await choose;
  ui.$('#daily-booking-form').elements.full_name.value = 'Guest'; ui.$('#daily-booking-form').elements.phone.value = '0812345678'; ui.submit();
  ui.requests[2].reject(Object.assign(new Error('unknown'), { details: { code: 'MUTATION_OUTCOME_UNKNOWN' } })); await new Promise(resolve => setImmediate(resolve));
  const payload = JSON.stringify(ui.requests[2].options.body), retry = ui.$('#daily-recover-submit').listeners.click();
  assert.equal(JSON.stringify(ui.requests[3].options.body), payload);
  ui.requests[3].resolve(booking()); await new Promise(resolve => setImmediate(resolve));
  assert.equal(ui.requests[4].options.headers['X-Booking-Access-Token'], 'a'.repeat(64));
  assert.doesNotMatch(ui.requests[4].url, /aaaa|token|0812345678/);
  ui.requests[4].resolve(booking()); ui.requests[5].resolve(paymentSummary({ payment: paymentRecord() })); await retry;
  assert.equal(ui.$('#daily-load-qr').disabled, true); assert.equal(ui.$('#daily-slip-form').hidden, true); assert.equal(ui.storage.has('dorm.daily.pending.v1'), false);
  assert.deepEqual(Object.keys(JSON.parse(ui.storage.get('dorm.daily.access.v1'))).sort(), ['id', 'token']);
});
async function flushUntil(condition) { for (let i = 0; i < 100 && !condition(); i += 1) await new Promise(resolve => setTimeout(resolve, 1)); assert.equal(condition(), true, 'expected asynchronous request was reached'); }
test('a delayed upload commit keeps QR blocked through stale rejected reads and a reload', async () => {
  const saved = { 'dorm.daily.access.v1': JSON.stringify({ id: 10, token: 'a'.repeat(64) }) }, ui = harness(saved), rejected = paymentRecord({ status: 'rejected' });
  ui.requests[0].resolve(booking()); ui.requests[1].resolve(paymentSummary({ payment: rejected })); await flushUntil(() => ui.$('#daily-slip-form').hidden === false);
  const file = new File(['exact original evidence'], 'proof.png', { type: 'image/png' }); ui.$('#daily-slip-form').elements.slip.files = [file];
  const upload = ui.$('#daily-slip-form').listeners.submit({ preventDefault() {}, currentTarget: ui.$('#daily-slip-form') }); await flushUntil(() => ui.requests.length === 3);
  ui.requests[2].reject(Object.assign(new Error('connection lost'), { details: { code: 'MUTATION_OUTCOME_UNKNOWN' } })); await flushUntil(() => ui.requests.length === 5);
  ui.requests[3].resolve(booking()); ui.requests[4].resolve(paymentSummary({ payment: rejected })); await upload;
  assert.equal(ui.$('#daily-load-qr').disabled, true); assert.equal(ui.$('#daily-slip-recovery').hidden, false); assert.equal(ui.storage.has('dorm.daily.upload.v1'), true);
  const count = ui.requests.length; await ui.$('#daily-load-qr').listeners.click(); assert.equal(ui.requests.length, count);
  const restored = harness(Object.fromEntries(ui.storage)); restored.requests[0].resolve(booking()); restored.requests[1].resolve(paymentSummary({ payment: rejected })); await flushUntil(() => restored.$('#daily-slip-recovery').hidden === false && restored.$('#daily-slip-form').hidden === false);
  assert.equal(restored.$('#daily-load-qr').disabled, true); assert.equal(restored.storage.has('dorm.daily.upload.v1'), true);
  const refresh = restored.controller.loadDetail(); restored.requests[2].resolve(booking()); restored.requests[3].resolve(paymentSummary({ payment: paymentRecord({ id: 6 }) })); await refresh;
  assert.equal(restored.storage.has('dorm.daily.upload.v1'), false); assert.equal(restored.$('#daily-load-qr').disabled, true); assert.equal(restored.$('#daily-slip-form').hidden, true);
});
test('an upload that never arrived can be resolved by retrying only the identical file', async () => {
  const original = new File(['original proof bytes'], 'proof.png', { type: 'image/png' }), digest = Buffer.from(await webcrypto.subtle.digest('SHA-256', await original.arrayBuffer())).toString('hex');
  const ui = harness({ 'dorm.daily.access.v1': JSON.stringify({ id: 10, token: 'a'.repeat(64) }), 'dorm.daily.upload.v1': JSON.stringify({ booking_id: 10, prior_payment_id: 5, sha256: digest }) }), rejected = paymentRecord({ status: 'rejected' });
  ui.requests[0].resolve(booking()); ui.requests[1].resolve(paymentSummary({ payment: rejected })); await flushUntil(() => ui.$('#daily-slip-form').hidden === false);
  ui.$('#daily-slip-form').elements.slip.files = [new File(['different proof bytes'], 'proof.png', { type: 'image/png' })];
  const wrong = ui.$('#daily-slip-form').listeners.submit({ preventDefault() {}, currentTarget: ui.$('#daily-slip-form') }); await flushUntil(() => ui.requests.length === 4);
  assert.equal(ui.requests.some(r => r.url.endsWith('/slip')), false); ui.requests[2].resolve(booking()); ui.requests[3].resolve(paymentSummary({ payment: rejected })); await wrong;
  assert.equal(ui.storage.has('dorm.daily.upload.v1'), true); assert.match(ui.$('#daily-payment-error').textContent, /ไฟล์เดิม/);
  ui.$('#daily-slip-form').elements.slip.files = [original]; const retry = ui.$('#daily-slip-form').listeners.submit({ preventDefault() {}, currentTarget: ui.$('#daily-slip-form') }); await flushUntil(() => ui.requests.length === 5);
  assert.equal(ui.requests[4].url, '/api/public/daily/bookings/10/slip'); ui.requests[4].resolve(rejected); await flushUntil(() => ui.requests.length === 7); ui.requests[5].resolve(booking()); ui.requests[6].resolve(paymentSummary({ payment: rejected })); await retry;
  assert.equal(ui.storage.has('dorm.daily.upload.v1'), false);
});
test('a payment status race does not stop pending verification polling or expose a stale QR', async () => {
  const ui=harness({'dorm.daily.access.v1':JSON.stringify({id:10,token:'a'.repeat(64)})});ui.requests[0].resolve(booking());ui.requests[1].resolve(paymentSummary({payment:paymentRecord()}));await flushUntil(()=>ui.$('#daily-payment-panel').hidden===false);
  const refresh=ui.controller.loadDetail();ui.requests[2].resolve(booking());ui.requests[3].resolve(paymentSummary({booking_status:'confirmed',version:2,paid:true,payment:paymentRecord({status:'verified'}),received_amount:'600.00'}));await refresh;
  assert.equal(ui.$('#daily-load-qr').disabled,true);assert.equal(ui.$('#daily-payment-panel').hidden,true);
  ui.timers.find(timer=>timer.ms===10000).callback();assert.equal(ui.requests.length,6);ui.requests[4].resolve(booking({status:'confirmed',version:2}));ui.requests[5].resolve(paymentSummary({booking_status:'confirmed',version:2,paid:true,payment:paymentRecord({status:'verified'}),received_amount:'600.00'}));await flushUntil(()=>String(ui.$('#daily-payment-status').textContent).includes('ยืนยันการจองแล้ว'));
  assert.equal(ui.$('#daily-load-qr').disabled,true);
});
test('cleaning-ready sends the displayed room version and retains the recovery message after reloading', async () => {
  const ui = adminHarness(), loading = ui.admin.load(); ui.resolveLoad(0); await loading;
  const readyButton = ui.$('#daily-housekeeping').children[0].children[2], mutation = readyButton.listeners.click(); await new Promise(resolve => setImmediate(resolve));
  assert.equal(ui.requests[4].options.body.expected_version, 7); assert.equal(typeof ui.requests[4].options.body.idempotency_key, 'string');
  ui.requests[4].reject(Object.assign(new Error('สถานะทำความสะอาดเปลี่ยนแล้ว กรุณารีเฟรช'), { status: 409 })); await new Promise(resolve => setImmediate(resolve)); ui.resolveLoad(5); await mutation;
  assert.match(ui.$('#daily-admin-error').textContent, /สถานะทำความสะอาดเปลี่ยน/);
});
test('failed owner refresh removes both room operations and cached booking actions', async () => {
  const ui = adminHarness(), initial = ui.admin.load(); ui.resolveLoad(0, [booking()]); await initial;
  assert.equal(ui.$('#daily-admin-rows').children.length, 1);
  const reload = ui.admin.load(); ui.requests[4].reject(new Error('offline')); ui.requests[5].resolve({ items: [], blocks: [], rooms: [] }); ui.requests[6].resolve([]); ui.requests[7].resolve({ items: [] }); await reload;
  assert.equal(ui.$('#daily-admin-rows').children.length, 0); assert.equal(ui.$('#daily-housekeeping').children.length, 0); assert.equal(ui.$('#daily-calendar').children.length, 0);
  ui.$('#daily-owner-create').listeners.click(); assert.notEqual(ui.$('#daily-owner-create-dialog').open, true);
});
test('default owner table includes guests due to check out today and already overdue', async () => {
  const due=booking({id:1,reference_no:'DAY-DUE',status:'checked_in',version:3,check_in_date:'2026-10-08',check_out_date:'2026-10-09'}),overdue=booking({id:2,reference_no:'DAY-OVERDUE',room_id:3,room_code:'D03',status:'checked_in',version:3,check_in_date:'2026-10-06',check_out_date:'2026-10-07'}),ui=adminHarness(),work=ui.admin.load();
  const rooms=[{id:2,room_code:'D02',rental_mode:'daily',housekeeping_status:'ready',housekeeping_version:1,status:'occupied'},{id:3,room_code:'D03',rental_mode:'daily',housekeeping_status:'ready',housekeeping_version:1,status:'occupied'}];
  assert.match(ui.requests[0].url,/from=2026-10-09/);ui.requests[0].resolve({items:[overdue,due],has_more:false,next_offset:2});ui.requests[1].resolve({items:[overdue,due],rooms,blocks:[]});ui.requests[2].resolve(rooms);ui.requests[3].resolve({items:[overdue,due],has_more:false,next_offset:2});await work;
  assert.equal(ui.$('[data-daily-count="departures"]').textContent,2);assert.equal(ui.$('#daily-admin-rows').children.length,2);
  for(const row of ui.$('#daily-admin-rows').children)assert.equal(row.children[6].children[0].children.some(button=>button.textContent==='เช็กเอาต์'),true);
});
test('owner can load the 501st booking and the page button prevents double dispatch', async () => {
  const ui=adminHarness(),rows=Array.from({length:501},(_,i)=>booking({id:i+1,reference_no:'DAY-'+(i+1),status:'cancelled'})),loading=ui.admin.load();
  ui.requests[0].resolve({items:rows.slice(0,500),has_more:true,next_offset:500});ui.requests[1].resolve({items:rows,rooms:[],blocks:[]});ui.requests[2].resolve([]);ui.requests[3].resolve({items:[],has_more:false,next_offset:0});await loading;
  assert.equal(ui.$('#daily-admin-rows').children.length,500);assert.match(ui.$('#daily-admin-note').textContent,/ยังมีรายการ/);assert.equal(ui.$('#daily-booking-load-more').hidden,false);
  const next=ui.$('#daily-booking-load-more').listeners.click();await ui.$('#daily-booking-load-more').listeners.click();assert.equal(ui.requests.length,5);assert.match(ui.requests[4].url,/offset=500/);
  ui.requests[4].resolve({items:[rows[500]],has_more:false,next_offset:501});await next;assert.equal(ui.$('#daily-admin-rows').children.length,501);assert.equal(ui.$('#daily-booking-load-more').hidden,true);assert.match(ui.$('#daily-admin-note').textContent,/ครบตามตัวกรอง/);
});
test('changing the owner date filter fences a pending page and discards old page controls', async () => {
  const ui=adminHarness(),loading=ui.admin.load();ui.requests[0].resolve({items:[booking()],has_more:true,next_offset:1});ui.requests[1].resolve({items:[booking()],rooms:[],blocks:[]});ui.requests[2].resolve([]);ui.requests[3].resolve({items:[],has_more:false,next_offset:0});await loading;
  const page=ui.$('#daily-booking-load-more').listeners.click();ui.$('#daily-admin-filter').elements.from.value='2026-10-10';ui.$('#daily-admin-filter').listeners.input();ui.requests[4].resolve({items:[booking({id:11,reference_no:'DAY-11'})],has_more:false,next_offset:2});await page;
  assert.equal(ui.$('#daily-admin-rows').children.length,0);assert.equal(ui.$('#daily-booking-load-more').hidden,true);assert.match(ui.$('#daily-admin-note').textContent,/ตัวกรองเปลี่ยน/);
});
async function openMixedProofs() {
  const ui=adminHarness(),loading=ui.admin.load();ui.resolveLoad(0,[booking()]);await loading;ui.$('#daily-admin-rows').children[0].children[6].children[0].children[0].listeners.click();
  const pending=paymentRecord({id:5,can_close:true,can_restore:true,can_retry:false,evidence_available:false}),closed=paymentRecord({id:6,status:'closed',can_close:false,can_restore:true,can_retry:false,evidence_available:false});
  ui.requests[4].resolve(paymentSummary({payment:pending,has_closed_unresolved:true,closed_payments:[closed]}));await flushUntil(()=>ui.$('#daily-owner-slip-actions').children.length===2);
  const groups=ui.$('#daily-owner-slip-actions').children;
  return {...ui,pending,closed,selectClose:()=>groups[0].children.find(button=>button.textContent==='พักการตรวจหลักฐาน').listeners.click(),selectRestore:()=>groups[1].children.find(button=>button.textContent==='เลือกไฟล์ต้นฉบับเพื่อซ่อม').listeners.click()};
}
test('closing one receipt cannot retarget the restore form for another receipt', async () => {
  const ui=await openMixedProofs();ui.selectRestore();ui.selectClose();assert.match(ui.$('#daily-owner-restore-summary').textContent,/หลักฐาน 6/);assert.match(ui.$('#daily-close-summary').textContent,/หลักฐาน 5/);
  const form=ui.$('#daily-owner-restore-form');form.elements.slip.files=[new File(['original proof for receipt 6'],'proof.png',{type:'image/png'})];form.listeners.submit({preventDefault(){},currentTarget:form});await flushUntil(()=>ui.requests.length===6);
  assert.equal(ui.requests[5].url,'/api/admin/daily/payments/6/restore');ui.requests[5].resolve({...ui.closed,evidence_available:true,can_restore:false});await flushUntil(()=>ui.requests.length===7);ui.requests[6].resolve(paymentSummary({payment:ui.pending,has_closed_unresolved:true,closed_payments:[{...ui.closed,evidence_available:true,can_restore:false}]}));await flushUntil(()=>ui.requests.length===11);ui.resolveLoad(7,[booking()]);
});
test('selecting a restore receipt cannot retarget the form that closes the pending receipt', async () => {
  const ui=await openMixedProofs();ui.selectClose();ui.selectRestore();const form=ui.$('#daily-close-form');form.elements.reason.value='รอไฟล์ต้นฉบับ';form.listeners.submit({preventDefault(){},currentTarget:form});await flushUntil(()=>ui.requests.length===6);
  assert.equal(ui.requests[5].url,'/api/admin/daily/payments/5/close');ui.requests[5].resolve({...ui.pending,status:'closed',can_close:false});await flushUntil(()=>ui.requests.length===7);ui.requests[6].resolve(paymentSummary({payment:{...ui.pending,status:'closed',can_close:false},has_closed_unresolved:true,closed_payments:[{...ui.pending,status:'closed',can_close:false},ui.closed]}));await flushUntil(()=>ui.requests.length===11);ui.resolveLoad(7,[booking()]);
});
test('stale close and restore capabilities are rechecked before either form dispatches', async () => {
  const ui=await openMixedProofs();ui.selectClose();ui.selectRestore();ui.closed.can_restore=false;ui.pending.can_close=false;
  const restore=ui.$('#daily-owner-restore-form');restore.elements.slip.files=[new File(['proof'],'proof.png',{type:'image/png'})];restore.listeners.submit({preventDefault(){},currentTarget:restore});const close=ui.$('#daily-close-form');close.elements.reason.value='ทดสอบ';close.listeners.submit({preventDefault(){},currentTarget:close});await new Promise(resolve=>setImmediate(resolve));
  assert.equal(ui.requests.length,5);assert.match(ui.$('#daily-owner-detail-error').textContent,/เปลี่ยนแล้ว/);
});
test('a committed evidence restore with a lost response reconciles on reload without posting again', async () => {
  const pending={booking_id:10,action:'restore',booking:booking(),prior_payment_id:6,payment_id:6,sha256:'a'.repeat(64)},ui=adminHarness({'dorm.daily.owner-proof.v1':JSON.stringify(pending)}),loading=ui.admin.load();ui.resolveLoad(0,[booking()]);await loading;
  ui.$('#daily-owner-recovery-open').listeners.click();const restored=paymentRecord({id:6,status:'closed',evidence_available:true,can_restore:false,can_retry:true});ui.requests[4].resolve(paymentSummary({payment:restored,has_closed_unresolved:true,closed_payments:[restored]}));await flushUntil(()=>ui.admin.busy()===false);
  assert.equal(ui.storage.has('dorm.daily.owner-proof.v1'),false);assert.equal(ui.$('#daily-owner-recovery').hidden,true);assert.equal(ui.$('#daily-owner-detail-dialog').dataset.preserveData,'false');assert.equal(ui.$('#daily-owner-restore-form').hidden,true);assert.equal(ui.requests.some(r=>r.url.endsWith('/restore')),false);
  assert.notEqual(ui.$('#daily-owner-next-step').children[0].textContent, 'ตรวจผลรายการเดิมก่อน');
  assert.equal(ui.$('#daily-owner-load-qr').disabled, true, 'closed evidence still blocks a new transfer after recovery resolves');
});
test('exact original-file recovery can replay a pinned restore no longer present in the summary', async () => {
  const original=new File(['original restored receipt 6'],'proof.png',{type:'image/png'}),sha256=Buffer.from(await webcrypto.subtle.digest('SHA-256',await original.arrayBuffer())).toString('hex'),pending={booking_id:10,action:'restore',booking:booking(),prior_payment_id:6,payment_id:6,sha256},ui=adminHarness({'dorm.daily.owner-proof.v1':JSON.stringify(pending)}),loading=ui.admin.load();ui.resolveLoad(0,[booking()]);await loading;
  ui.$('#daily-owner-recovery-open').listeners.click();const newer=paymentRecord({id:7,status:'pending',evidence_available:true,can_restore:false,can_retry:true});ui.requests[4].resolve(paymentSummary({payment:newer}));await flushUntil(()=>ui.$('#daily-owner-restore-form').hidden===false&&ui.$('#daily-owner-payment-actions').hidden===false);
  assert.equal(ui.admin.busy(),true);const form=ui.$('#daily-owner-restore-form');form.elements.slip.files=[original];form.listeners.submit({preventDefault(){},currentTarget:form});await flushUntil(()=>ui.requests.length===6);
  assert.equal(ui.requests[5].url,'/api/admin/daily/payments/6/restore');ui.requests[5].resolve(paymentRecord({id:6,status:'rejected',evidence_available:true,can_restore:false}));await flushUntil(()=>ui.requests.length===7);ui.requests[6].resolve(paymentSummary({payment:newer}));await flushUntil(()=>ui.requests.length===11);ui.resolveLoad(7,[booking()]);await flushUntil(()=>ui.admin.busy()===false);assert.equal(ui.storage.has('dorm.daily.owner-proof.v1'),false);
});
test('an owner refund with a lost response survives reload and resolves by a read-only request lookup', async () => {
  const key='fixture-refund-idempotency-000001',payload={expected_version:1,idempotency_key:key,amount:'40.00',reference:'R-001',reason:'คืนค่าประกัน'},pending={booking_id:10,action:'refund',payload,booking:booking(),payment_id:5};
  const ui=adminHarness({'dorm.daily.owner-finance.v1':JSON.stringify(pending)}),loading=ui.admin.load();ui.resolveLoad(0,[booking()]);await loading;
  assert.equal(ui.admin.busy(),true);assert.equal(ui.admin.inFlight(),false);assert.equal(ui.$('#daily-owner-recovery').hidden,false);
  ui.$('#daily-owner-recovery-open').listeners.click();ui.requests[4].resolve(paymentSummary({booking_status:'confirmed',version:2,paid:true,payment:paymentRecord({status:'verified'}),received_amount:'600.00',refundable_amount:'100.00',deposit_remaining:'100.00'}));await flushUntil(()=>ui.$('#daily-owner-payment-actions').hidden===false);
  assert.equal(ui.$('#daily-refund-form').elements.reference.value,'R-001');assert.equal(ui.$('#daily-refund-form').elements.reference.disabled,true);
  ui.$('#daily-refund-form').listeners.submit({preventDefault(){},currentTarget:ui.$('#daily-refund-form')});await flushUntil(()=>ui.requests.length===6);assert.match(ui.requests[5].url,/\/requests\/refund\?key=fixture-refund-idempotency-000001$/);assert.equal(ui.requests[5].options.method,undefined);
  ui.requests[5].resolve({found:true,booking_id:10,action:'refund',result:{id:9,booking_id:10,amount:'40.00',reference_no:'R-001'}});await flushUntil(()=>ui.requests.length===7);
  assert.equal(ui.requests.some(r=>r.url.endsWith('/refund')&&r.options.method==='POST'),false);assert.equal(ui.storage.has('dorm.daily.owner-finance.v1'),false);
  ui.requests[6].resolve(paymentSummary({booking_status:'confirmed',version:2,paid:true,payment:paymentRecord({status:'verified'}),received_amount:'600.00',refunded_amount:'40.00',refundable_amount:'60.00',deposit_remaining:'60.00'}));await flushUntil(()=>ui.requests.length===11);ui.resolveLoad(7,[booking({status:'confirmed',version:2})]);await flushUntil(()=>ui.admin.busy()===false);
});

const plain = value => JSON.parse(JSON.stringify(value));
function publicRoomButton(ui, index = 0) {
  const find = item => {
    if (item?.type === 'button' && typeof item.listeners?.click === 'function') return item;
    for (const child of item?.children || []) { const found = find(child); if (found) return found; }
    return null;
  };
  const button = find(ui.$('#daily-room-grid').children[index]);
  assert.ok(button, 'the room card exposes a booking action'); return button;
}
async function startPublicCreate(ui) {
  const searching = ui.search(); ui.requests[0].resolve({ items: [quote()] }); await searching;
  const choosing = publicRoomButton(ui).listeners.click();
  ui.requests[1].resolve(quote()); await choosing;
  ui.$('#daily-booking-form').elements.full_name.value = 'Guest';
  ui.$('#daily-booking-form').elements.phone.value = '0812345678';
  ui.submit(); await flushUntil(() => ui.requests.length === 3);
  return plain(ui.requests[2].options.body);
}
function resolveOwnerInventory(ui, offset, rooms = []) {
  ui.requests[offset].resolve({ items: [], has_more: false, next_offset: 0 });
  ui.requests[offset + 1].resolve({ items: [], blocks: [], rooms: rooms.filter(room => room.rental_mode === 'daily') });
  ui.requests[offset + 2].resolve(rooms);
  ui.requests[offset + 3].resolve({ items: [], has_more: false, next_offset: 0 });
}
test('unknown public creation survives rate limiting, CSRF rejection and reload without changing the request', async () => {
  const ui = harness(), original = await startPublicCreate(ui);
  ui.requests[2].reject(Object.assign(new Error('response lost'), { details: { code: 'MUTATION_OUTCOME_UNKNOWN' } }));
  await flushUntil(() => JSON.parse(ui.storage.get('dorm.daily.pending.v1') || '{}').outcome_unknown === true);
  for (const [status, code] of [[429, 'RATE_LIMITED'], [403, 'CSRF_INVALID']]) {
    const retry = ui.$('#daily-recover-submit').listeners.click(), request = ui.requests.at(-1);
    assert.deepEqual(plain(request.options.body), original);
    assert.equal(Object.hasOwn(request.options.body, 'outcome_unknown'), false, 'private recovery metadata must never reach the API');
    request.reject(Object.assign(new Error(code), { status, details: { code } })); await retry;
    const saved = JSON.parse(ui.storage.get('dorm.daily.pending.v1'));
    assert.equal(saved.idempotency_key, original.idempotency_key);
    assert.equal(saved.outcome_unknown, true);
    assert.equal(ui.$('#daily-recovery').hidden, false);
    assert.equal(ui.$('#daily-booking-form').querySelector().disabled, true);
  }
  const restored = harness(Object.fromEntries(ui.storage)), retry = restored.$('#daily-recover-submit').listeners.click();
  assert.deepEqual(plain(restored.requests[0].options.body), original);
  restored.requests[0].resolve(booking()); await flushUntil(() => restored.requests.length === 3);
  restored.requests[1].resolve(booking()); restored.requests[2].resolve(paymentSummary()); await retry;
  assert.equal(restored.storage.has('dorm.daily.pending.v1'), false);
  assert.equal(JSON.parse(restored.storage.get('dorm.daily.access.v1')).id, 10);
  assert.equal(restored.requests.filter(request => request.options.method === 'POST').length, 1);
});
test('a restored pre-marker public request remains unresolved after a later validation error', async () => {
  const original = { ...dates, room_id: 2, full_name: 'Guest', phone: '0812345678', quote_token: 'signed-quote', idempotency_key: 'legacy-pending-key' };
  const ui = harness({ 'dorm.daily.pending.v1': JSON.stringify(original) });
  const retry = ui.$('#daily-recover-submit').listeners.click();
  assert.deepEqual(plain(ui.requests[0].options.body), original);
  ui.requests[0].reject(Object.assign(new Error('validation failed on retry'), { status: 422, details: { code: 'VALIDATION_ERROR' } })); await retry;
  assert.equal(JSON.parse(ui.storage.get('dorm.daily.pending.v1')).idempotency_key, original.idempotency_key);
  assert.equal(ui.$('#daily-recovery').hidden, false);
  assert.equal(ui.requests.length, 1, 'a restored request must not start another booking automatically');
});
test('initial authentication, CSRF, timeout and quota failures preserve the same public booking key', async () => {
  for (const status of [401, 403, 408, 429]) {
    const ui = harness(), original = await startPublicCreate(ui);
    ui.requests[2].reject(Object.assign(new Error('preflight rejected'), { status }));
    await flushUntil(() => ui.$('#daily-recovery').hidden === false);
    const saved = JSON.parse(ui.storage.get('dorm.daily.pending.v1'));
    const { outcome_unknown, ...body } = saved;
    assert.equal(outcome_unknown, true); assert.deepEqual(body, original);
  }
  const ui = harness(); await startPublicCreate(ui);
  ui.requests[2].reject(Object.assign(new Error('invalid phone'), { status: 422, details: { code: 'VALIDATION_ERROR' } }));
  await flushUntil(() => ui.storage.has('dorm.daily.pending.v1') === false);
  assert.equal(ui.storage.has('dorm.daily.pending.v1'), false, 'a definite initial validation failure can release the draft');
});
test('an open confirmed booking keeps its capability when another room is selected', async () => {
  const access = { id: 10, token: 'a'.repeat(64) }, saved = JSON.stringify(access);
  const ui = harness({ 'dorm.daily.access.v1': saved });
  ui.requests[0].resolve(booking({ status: 'confirmed', version: 2 }));
  ui.requests[1].resolve(paymentSummary({ booking_status: 'confirmed', version: 2, paid: true, payment: paymentRecord({ status: 'verified' }), received_amount: '600.00' }));
  await flushUntil(() => ui.$('#daily-payment-panel').hidden === false);
  const searching = ui.search(); ui.requests[2].resolve({ items: [quote({ id: 3, room_id: 3, room_code: 'D03' })] }); await searching;
  await publicRoomButton(ui).listeners.click(); ui.submit();
  assert.equal(ui.requests.length, 3, 'neither a new quote nor a new creation can replace the opened booking');
  assert.equal(ui.storage.get('dorm.daily.access.v1'), saved);
  assert.notEqual(ui.$('#daily-booking-dialog').open, true);
  assert.match(ui.$('#daily-search-error').textContent, /แท็บใหม่/);
});
test('arrival changes enforce a later departure across year and leap-day boundaries before reviewing a price', async () => {
  const ui = harness(), form = ui.$('#daily-search-form'), arrival = form.elements.check_in_date, departure = form.elements.check_out_date;
  for (const [day, tomorrow] of [['2026-12-31', '2027-01-01'], ['2028-02-28', '2028-02-29'], ['2028-02-29', '2028-03-01']]) {
    arrival.value = day; departure.value = day; form.listeners.input({ target: arrival });
    assert.equal(departure.min, tomorrow); assert.equal(departure.value, tomorrow);
    assert.match(ui.$('#daily-search-summary').textContent, /1 คืน/);
  }
  departure.value = '2028-03-04'; form.listeners.input({ target: form.elements.guests });
  assert.equal(departure.value, '2028-03-04', 'changing guests must preserve an already valid stay');
  assert.equal(ui.requests.length, 0, 'editing dates alone must not reserve or quote a room');
});
test('editing the search cannot rewrite an already submitted unknown booking stay', async () => {
  const ui = harness(), original = await startPublicCreate(ui);
  ui.requests[2].reject(Object.assign(new Error('unknown'), { details: { code: 'MUTATION_OUTCOME_UNKNOWN' } }));
  await flushUntil(() => ui.$('#daily-recovery').hidden === false);
  const form = ui.$('#daily-search-form'); form.elements.check_in_date.value = '2027-01-01';
  form.listeners.input({ target: form.elements.check_in_date });
  const retry = ui.$('#daily-recover-submit').listeners.click();
  assert.deepEqual(plain(ui.requests[3].options.body), original);
  assert.equal(ui.requests[3].options.body.check_out_date, '2026-10-10');
  ui.requests[3].reject(Object.assign(new Error('still unknown'), { details: { code: 'MUTATION_OUTCOME_UNKNOWN' } })); await retry;
});
test('an empty daily inventory shows setup only after a complete successful load', async () => {
  const ui = adminHarness(), loading = ui.admin.load();
  assert.equal(ui.$('#daily-setup-guide').hidden, true); assert.equal(ui.$('#daily-owner-create').disabled, true);
  resolveOwnerInventory(ui, 0, [{ id: 1, room_code: 'M01', rental_mode: 'monthly' }]); await loading;
  assert.equal(ui.$('#daily-setup-guide').hidden, false); assert.equal(ui.$('#daily-owner-create').disabled, true);
  ui.$('#daily-owner-create').listeners.click();
  assert.notEqual(ui.$('#daily-owner-create-dialog').open, true);
  const failed = ui.admin.load();
  ui.requests[4].resolve({ items: [], has_more: false, next_offset: 0 }); ui.requests[5].resolve({ items: [], blocks: [], rooms: [] });
  ui.requests[6].reject(new Error('inventory unavailable')); ui.requests[7].resolve({ items: [], has_more: false, next_offset: 0 }); await failed;
  assert.equal(ui.$('#daily-setup-guide').hidden, true, 'an unavailable inventory must not be presented as an empty database');
  assert.equal(ui.$('#daily-owner-create').disabled, true); assert.match(ui.$('#daily-admin-error').textContent, /inventory unavailable/);
});
test('owner creation starts with an explicit room choice and reflects its capacity', async () => {
  const ui = adminHarness(), loading = ui.admin.load();
  ui.resolveLoad(0, [], { id: 2, room_code: 'D02', rental_mode: 'daily', housekeeping_status: 'ready', housekeeping_version: 1, daily_rate: '500.00', max_guests: 2 }); await loading;
  assert.equal(ui.$('#daily-setup-guide').hidden, true); assert.equal(ui.$('#daily-owner-create').disabled, false);
  ui.$('#daily-owner-create').listeners.click();
  const form = ui.$('#daily-owner-create-form');
  assert.equal(form.elements.room_id.value, ''); assert.equal(form.elements.room_id.children[0].value, '');
  form.elements.room_id.value = '2'; form.listeners.input({ target: form.elements.room_id });
  assert.equal(form.elements.guests.max, 2); assert.match(ui.$('#daily-owner-room-help').textContent, /ไม่เกิน 2 คน/);
  assert.equal(ui.requests.length, 4, 'selecting a room must not create or accept a new price automatically');
});
test('restored owner creation keeps its body through denied retries and opens the created detail on replay', async () => {
  const original = { ...dates, room_id: 2, full_name: 'Guest', phone: '0812345678', quote_token: 'signed-quote', idempotency_key: 'owner-recovery-key' };
  const ui = adminHarness({ 'dorm.daily.owner-create.v1': JSON.stringify(original) }), loading = ui.admin.load(); ui.resolveLoad(0); await loading;
  ui.$('#daily-owner-recovery-open').listeners.click();
  for (const [status, code] of [[429, 'RATE_LIMITED'], [403, 'CSRF_INVALID']]) {
    const offset = ui.requests.length, form = ui.$('#daily-owner-create-form');
    const retry = form.listeners.submit({ preventDefault() {}, currentTarget: form });
    assert.deepEqual(plain(ui.requests[offset].options.body), original);
    ui.requests[offset].reject(Object.assign(new Error(code), { status, details: { code } }));
    await flushUntil(() => ui.requests.length === offset + 5); ui.resolveLoad(offset + 1); await retry;
    assert.deepEqual(JSON.parse(ui.storage.get('dorm.daily.owner-create.v1')), original);
    assert.equal(ui.$('#daily-owner-recovery').hidden, false);
  }
  const restored = adminHarness(Object.fromEntries(ui.storage)), reloading = restored.admin.load(); restored.resolveLoad(0); await reloading;
  restored.$('#daily-owner-recovery-open').listeners.click();
  const form = restored.$('#daily-owner-create-form'), replay = form.listeners.submit({ preventDefault() {}, currentTarget: form });
  assert.deepEqual(plain(restored.requests[4].options.body), original);
  restored.requests[4].resolve(booking()); await flushUntil(() => restored.requests.length === 9); restored.resolveLoad(5, [booking()]);
  await flushUntil(() => restored.requests.length === 10); assert.equal(restored.requests[9].url, '/api/admin/daily/bookings/10/payment');
  restored.requests[9].resolve(paymentSummary()); await replay;
  assert.equal(restored.storage.has('dorm.daily.owner-create.v1'), false);
  assert.equal(restored.$('#daily-owner-detail-dialog').open, true);
  assert.equal(restored.requests.filter(request => request.options.method === 'POST').length, 1);
});
test('booking guidance preserves evidence and never invites another transfer while unresolved', () => {
  const f = harness().functions;
  const unresolved = [
    [paymentSummary({ payment: paymentRecord() }), /รอผลตรวจสลิป/, /อย่าโอนหรือรับเงินซ้ำ/],
    [paymentSummary({ payment: paymentRecord({ status: 'closed' }), has_closed_unresolved: true, closed_payments: [paymentRecord({ status: 'closed' })] }), /หลักฐานเดิม/, /อย่าโอนซ้ำ/],
    [paymentSummary(), /ยอดโอนเดิม/, /ห้ามโอนหรือรับเงินสดซ้ำ/],
  ];
  for (const [data, title, instruction] of unresolved) {
    const guide = f.bookingGuidance(booking(), data); assert.match(guide[0], title); assert.match(guide[1], instruction);
    assert.doesNotMatch(guide.join(' '), /กด “สร้าง QR|เลือกรับเงินสด/);
  }
  const recovery = f.bookingGuidance(null, null, true);
  assert.match(recovery[0], /ตรวจผลรายการเดิม/); assert.match(recovery[1], /ไม่เริ่มจอง.*โอนซ้ำ/);
  const waiting = f.bookingGuidance(null, null); assert.match(waiting[1], /รอข้อมูล/);
  const none = paymentSummary({ has_transfer_instruction: false, can_generate_qr: false, can_upload: false, capabilities: { promptpay_ready: false, slip_verification_ready: false } });
  const unavailable = f.bookingGuidance(booking(), none); assert.match(unavailable[0], /ติดต่อหอพัก/); assert.match(unavailable[1], /อย่าโอนเอง/);
  assert.equal(f.paymentGuard(booking(), none).mayTransfer, false);
});
test('paid and terminal booking guidance distinguishes arrival from refunds without reopening payment', () => {
  const f = harness().functions, verified = paymentRecord({ status: 'verified' });
  for (const status of ['confirmed', 'checked_in', 'checked_out']) {
    const stay = booking({ status, version: 2 }), receipt = paymentSummary({ booking_status: status, version: 2, paid: true, payment: verified, received_amount: '600.00', deposit_remaining: '100.00' });
    const guide = f.bookingGuidance(stay, receipt); assert.equal(f.paymentGuard(stay, receipt).mayTransfer, false);
    assert.doesNotMatch(guide.join(' '), /กด “สร้าง QR|ชำระเงินเพื่อยืนยัน/);
    if (status === 'confirmed') assert.match(guide[1], /มาเข้าพัก.*ไม่ต้องชำระซ้ำ/);
    if (status === 'checked_in') assert.match(guide[1], /ตรวจห้อง.*ผลตรวจ/);
    const recovered = f.bookingGuidance(stay, receipt, true); assert.match(recovered[0], /ตรวจผลรายการเดิม/);
  }
  for (const status of ['expired', 'cancelled', 'no_show']) {
    const stay = booking({ status }), receipt = paymentSummary({ booking_status: status, paid: true, payment: verified, received_amount: '600.00', refundable_amount: '600.00' });
    const guide = f.bookingGuidance(stay, receipt); assert.match(guide[1], /ห้ามโอนเพิ่ม.*สลิปเดิม.*คืนเงิน/);
    assert.equal(f.paymentGuard(stay, receipt).mayTransfer, false);
  }
});
test('an availability response with inconsistent or mismatched prices never exposes a bookable card', async () => {
  for (const malformed of [quote({ room_amount: '499.00' }), quote({ total_amount: '599.00' }), quote({ guests: 2 }), quote({ check_out_date: '2026-10-11' })]) {
    const ui = harness(), searching = ui.search();
    ui.requests[0].resolve({ items: [quote({ id: 3, room_id: 3, room_code: 'D03' }), malformed] }); await searching;
    assert.equal(ui.$('#daily-room-grid').children.length, 0);
    assert.equal(ui.$('#daily-search-error').hidden, false);
    assert.equal(ui.$('#daily-booking-form').querySelector().disabled, true);
    ui.submit(); assert.equal(ui.requests.length, 1, 'unsafe availability cannot produce a booking or a quote');
  }
});
test('unavailable provider guidance respects a reserved QR and the owner cash capability', () => {
  const f = harness().functions, capabilities = { promptpay_ready: false, slip_verification_ready: false };
  const reserved = paymentSummary({ capabilities });
  for (const owner of [false, true]) {
    const guide = f.bookingGuidance(booking(), reserved, false, owner);
    assert.match(guide[0], /ยอดโอนเดิม/); assert.match(guide[1], /ห้ามโอนหรือรับเงินสดซ้ำ/);
    assert.doesNotMatch(guide[1], /เมื่อรับเงินสด|รับเงินสดพร้อมใบรับเงินได้/);
  }
  const blocked = paymentSummary({ capabilities, has_transfer_instruction: false, cash_available: false });
  const blockedAdvice = f.bookingGuidance(booking(), blocked, false, true);
  assert.match(blockedAdvice[1], /ยังรับชำระเพิ่มไม่ได้/); assert.doesNotMatch(blockedAdvice[1], /เมื่อรับเงินสด|รับเงินสดพร้อมใบรับเงินได้/);
  const cash = f.bookingGuidance(booking(), { ...blocked, cash_available: true }, false, true);
  assert.match(cash[1], /เมื่อรับเงินสดครบและออกใบรับเงินแล้ว/);
});
test('a proven expired quote on recovery unlocks a new price review without silently creating another booking', async () => {
  const original = { ...dates, room_id: 2, full_name: 'Guest', phone: '0812345678', quote_token: 'old-signed-quote', idempotency_key: 'expired-price-original-key' };
  const ui = harness({ 'dorm.daily.pending.v1': JSON.stringify(original) }), retry = ui.$('#daily-recover-submit').listeners.click();
  assert.deepEqual(plain(ui.requests[0].options.body), original);
  ui.requests[0].reject(Object.assign(new Error('quote expired after existing-key lookup'), { status: 409, details: { code: 'DAILY_QUOTE_EXPIRED' } })); await retry;
  assert.equal(ui.storage.has('dorm.daily.pending.v1'), false); assert.equal(ui.$('#daily-recovery').hidden, true);
  assert.equal(ui.$('#daily-booking-form').querySelector().disabled, true); assert.equal(ui.requests.length, 1);
  const searching = ui.search(); ui.requests[1].resolve({ items: [quote()] }); await searching;
  const choosing = publicRoomButton(ui).listeners.click(); ui.requests[2].resolve(quote({ quote_token: 'fresh-signed-quote' })); await choosing;
  assert.equal(ui.$('#daily-booking-form').querySelector().disabled, false);
  assert.equal(ui.requests.filter(request => request.url === '/api/public/daily/bookings').length, 1, 'reviewing a replacement price alone must not create a new booking');
  ui.$('#daily-booking-form').elements.full_name.value = original.full_name; ui.$('#daily-booking-form').elements.phone.value = original.phone; ui.submit();
  assert.equal(ui.requests[3].options.body.quote_token, 'fresh-signed-quote');
  assert.notEqual(ui.requests[3].options.body.idempotency_key, original.idempotency_key);
  assert.equal(Object.hasOwn(ui.requests[3].options.body, 'outcome_unknown'), false);
  ui.requests[3].reject(Object.assign(new Error('initial input invalid'), { status: 422, details: { code: 'VALIDATION_ERROR' } }));
  await flushUntil(() => ui.storage.has('dorm.daily.pending.v1') === false);
});
