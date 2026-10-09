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
    requests[offset].resolve({ items: bookings }); requests[offset + 1].resolve({ items: bookings, blocks: [], rooms: [room] }); requests[offset + 2].resolve([room]); requests[offset + 3].resolve({ items: bookings });
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
  const latest = ui.search(); ui.requests[1].resolve({ items: [quote({ id: 3, room_id: 3, room_code: 'D03' })] }); await latest;
  ui.requests[0].resolve({ items: [quote()] }); await old;
  const roomBody = ui.$('#daily-room-grid').children[0].children[0]; assert.equal(roomBody.children[0].textContent, 'ห้อง D03');
});
test('changing search criteria while quote is loading cannot enable stale booking', async () => {
  const ui = harness(), search = ui.search(); ui.requests[0].resolve({ items: [quote()] }); await search;
  const choose = ui.$('#daily-room-grid').children[0].children[0].children[3].listeners.click();
  ui.$('#daily-search-form').elements.guests.value = '2'; ui.$('#daily-search-form').listeners.input();
  ui.requests[1].resolve(quote()); await choose;
  assert.equal(ui.$('#daily-booking-form').querySelector().disabled, true);
});
test('an unknown create outcome retries the identical payload and idempotency key', async () => {
  const ui = harness(), search = ui.search(); ui.requests[0].resolve({ items: [quote()] }); await search;
  const choose = ui.$('#daily-room-grid').children[0].children[0].children[3].listeners.click(); ui.requests[1].resolve(quote()); await choose;
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
