'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/rental-workspaces.js'), 'utf8');

function deferred() {
  let resolve, reject;
  const promise = new Promise((a, b) => { resolve = a; reject = b; });
  return { promise, resolve, reject };
}
function element(tag = 'div', className = '', content = '') {
  const node = { tag, className, children: [], dataset: {}, hidden: false, disabled: false, value: '', listeners: {}, closestNodes: {}, parent: null,
    append(...items) { for (const item of items) { this.children.push(item); if (item && typeof item === 'object') item.parent = this; } },
    replaceChildren(...items) { this.children = []; this._text = ''; this.append(...items); },
    addEventListener(name, callback) { this.listeners[name] = callback; },
    querySelector(selector) { return selector === '[data-error-message]' ? this.errorTarget || null : null; },
    closest(selector) {
      if (this.closestNodes[selector]) return this.closestNodes[selector];
      if (selector === '[data-daily-room-action]' && this.dataset.dailyRoomAction) return this;
      if (selector.startsWith('.') && this.className.split(' ').includes(selector.slice(1))) return this;
      return this.parent?.closest(selector) || null;
    },
  };
  Object.defineProperty(node, 'textContent', { get() { return this._text || ''; }, set(value) { this._text = String(value); this.children = []; } });
  node.textContent = content;
  return node;
}
function textTree(node) { return [node.textContent, ...node.children.map(textTree)].filter(Boolean).join(' '); }
function harness() {
  const nodes = new Map(), requests = [], opened = [], notices = [];
  const $ = selector => { if (!nodes.has(selector)) nodes.set(selector, element()); return nodes.get(selector); };
  for (const type of ['daily', 'monthly']) {
    $(`#${type}-revenue-error`).errorTarget = element();
    $(`#${type}-revenue-rows`).closestNodes['.panel'] = element('section', 'panel');
    $(`#${type}-revenue-deposit`).closestNodes['.stat-card'] = element('article', 'stat-card');
  }
  $('#daily-overview-error').errorTarget = element();
  const context = { window: {}, AbortController, URLSearchParams };
  vm.createContext(context); vm.runInContext(source, context);
  const helpers = { $, create(...args) {
      const created = element(...args); let id = '';
      Object.defineProperty(created, 'id', { get() { return id; }, set(value) { id = String(value); nodes.set(`#${id}`, created); } });
      return created;
    },
    api(url, options = {}) { const request = { url, options, ...deferred() }; requests.push(request); return request.promise; },
    money: value => `money:${value}`, formatDateTime: String, todayPeriod: () => '2026-10',
    errorMessage: error => error?.message || String(error),
    showFormError(node, error = '') { node.textContent = error?.message || String(error); node.hidden = !error; },
    setBusy(node, busy) { node.disabled = busy; }, confirmAction: async () => true,
    openRoomForm(room, mode) { opened.push({ room, mode }); }, toast(...args) { notices.push(args); },
  };
  const functions = context.window.DormRentalWorkspaces;
  const controller = functions.init(helpers);
  return { $, requests, opened, notices, functions, controller, helpers,
    roomClick(control) { return $('#daily-room-rows').listeners.click({ target: control }); },
  };
}
function room(id = 1, extra = {}) {
  return { id, rental_mode: 'daily', room_code: `D${id}`, floor: 1, room_type: 'มาตรฐาน', daily_rate: '100.00', daily_deposit: '10.00', max_guests: 2,
    status: 'available', housekeeping_status: 'ready', can_delete: true, ...extra };
}
function event(type = 'daily', extra = {}) {
  return { event_id: 1, event_type: 'collection', reference_no: type === 'daily' ? 'DAY-1' : 'BILL-1', room_code: type === 'daily' ? 'D1' : 'M1',
    occurred_at: '2026-10-10T05:00:00.000000Z', amount: type === 'daily' ? '110.13' : '3050.27', cash_flow_direction: 'in',
    ...(type === 'daily' ? { booking_id: 1, room_amount: '100.00', deposit_amount: '10.00', bank_adjustment: '0.13', payment_method: 'slip' }
      : { bill_id: 1, bill_period: '2026-10-01', charges_amount: '3050.00', rent_amount: '2500.00', water_amount: '200.00', electric_amount: '350.00', other_amount: '0.00', bank_adjustment: '0.27' }), ...extra };
}
function report(type = 'daily', period = '2026-10', extra = {}) {
  const start = new Date(`${period}-01T00:00:00Z`), end = new Date(start);
  end.setUTCMonth(end.getUTCMonth() + 1); start.setUTCHours(-7); end.setUTCHours(-7);
  const summary = type === 'daily'
    ? { gross_receipts: '110.13', room_collections: '100.00', security_deposit_collections: '10.00', bank_adjustment: '0.13', cash_receipts: '0.00', bank_receipts: '110.13',
      future_stay_room_collections: '0.00', refunds_total: '0.00', deposit_refunds: '0.00', cancellation_refunds: '0.00', damage_retention: '0.00', net_cash_flow: '110.13',
      verified_payment_count: 1, future_stay_payment_count: 0, refund_count: 0, retention_count: 0 }
    : { gross_receipts: '3050.27', collected_charges: '3050.00', rent_collections: '2500.00', water_collections: '200.00', electric_collections: '350.00', other_collections: '0.00',
      bank_adjustment: '0.27', earlier_bill_period_collections: '0.00', later_bill_period_collections: '0.00', refunds_total: '0.00', net_cash_flow: '3050.27', verified_payment_count: 1, refund_count: 0 };
  const base = { type, period, currency: 'THB', timezone: 'Asia/Bangkok', generated_at_utc: '2026-10-10T05:00:00.000000Z',
    range: { start_utc: start.toISOString(), end_utc: end.toISOString() },
    basis: { collections: 'verified_at', refunds: type === 'daily' ? 'created_at' : null, deposit_retention: type === 'daily' ? 'created_at' : null,
      earned_revenue_calculated: false, future_stays_relative_to: 'generated_at', balances_scope: type === 'daily' ? 'current_all_periods' : null, room_labels: type === 'daily' ? 'current_catalogue' : 'invoice_snapshot' },
    summary, events: { items: [event(type, { occurred_at: `${period}-10T05:00:00.000000Z`, ...(type === 'monthly' ? { bill_period: `${period}-01` } : {}) })], offset: 0, limit: 200, next_offset: 1, has_more: false },
    ...(type === 'daily' ? { balances: { active_stay_security_deposits: '10.00', closed_booking_cash_refundable: '0.00', unconfirmed_verified_cash: '0.00', as_of_utc: '2026-10-10T05:00:00.000000Z' } } : {}),
  };
  return { ...base, ...extra, summary: { ...summary, ...extra.summary }, events: { ...base.events, ...extra.events },
    ...(type === 'daily' ? { balances: { ...base.balances, ...extra.balances } } : {}) };
}
async function load(h, type, data) {
  const work = h.controller.loadRevenue(type); h.requests.at(-1).resolve(data); await work;
}
function rowActions(h) { return h.$('#daily-room-rows').children.map(row => row.children.at(-1).children[0].children); }
function forged(action, id) { const control = element('button'); control.dataset = { dailyRoomAction: action, id: String(id) }; return control; }

test('decimal money preserves every digit in a 30-digit amount without floating point rounding', () => {
  assert.equal(harness().functions.decimalMoney('123456789012345678901234567890.12'), '฿123,456,789,012,345,678,901,234,567,890.12');
});
test('decimal money displays negative cash flow and exact zero', () => {
  const f = harness().functions;
  assert.equal(f.decimalMoney('-1234567.89'), '-฿1,234,567.89'); assert.equal(f.decimalMoney('0.00'), '฿0.00');
});
test('decimal money rejects numeric coercion, exponential notation, precision loss and partial values', () => {
  const f = harness().functions;
  for (const value of [null, undefined, 100, NaN, Infinity, '', '1', '1.0', '1.000', '1e3', ' 1.00', '1.00 ', '+1.00', '1,000.00', 'x1.00', '1'.repeat(31) + '.00']) assert.throws(() => f.decimalMoney(value), String(value));
});
test('room envelopes retain only explicitly matching rental scope', () => {
  const f = harness().functions, daily = [room()], monthly = [room(2, { rental_mode: 'monthly' })];
  for (const value of [daily, { items: daily }, { rooms: daily }]) assert.equal(f.scopedRooms(value, 'daily')[0].id, 1);
  assert.equal(f.scopedRooms(monthly, 'monthly')[0].id, 2);
});
test('mixed or wrong room scope cannot be silently filtered into an editable catalogue', () => {
  const f = harness().functions;
  for (const value of [[room(2, { rental_mode: 'monthly' })], [room(), room(2, { rental_mode: 'monthly' })]]) assert.throws(() => f.scopedRooms(value, 'daily'));
  assert.throws(() => f.scopedRooms([room()], 'monthly')); assert.throws(() => f.scopedRooms([], 'weekly'));
});
test('room IDs must be unique positive safe integers before edit controls can use them', () => {
  const f = harness().functions;
  for (const value of [[room(0)], [room('1')], [room(1.5)], [room(Number.MAX_SAFE_INTEGER + 1)], [null], [room(), room(1, { room_code: 'OTHER' })]]) assert.throws(() => f.scopedRooms(value, 'daily'));
});
test('valid daily and monthly revenue reports keep their declared type and month', () => {
  const f = harness().functions;
  for (const type of ['daily', 'monthly']) { const data = report(type); assert.equal(f.revenueReport(data, type, '2026-10'), data); }
});
test('revenue rejects another workspace, month and currency before showing any totals', () => {
  const f = harness().functions;
  for (const data of [report('monthly'), report('daily', '2026-09'), report('daily', '2026-10', { currency: 'USD' })]) assert.throws(() => f.revenueReport(data, 'daily', '2026-10'));
});
test('revenue rejects unsupported scope and malformed selected months even when echoed by a response', () => {
  const f = harness().functions;
  assert.throws(() => f.revenueReport({ ...report('monthly'), type: 'weekly' }, 'weekly', '2026-10'));
  for (const period of ['2026-13', '2026-00', '2026-1', '0000-10', '2026-10-01']) assert.throws(() => f.revenueReport({ ...report(), period }, 'daily', period));
});
test('revenue paging rejects wrong offsets, empty continuation pages and inconsistent next offsets', () => {
  const f = harness().functions;
  for (const events of [{ offset: 1 }, { next_offset: 0 }, { has_more: 'false' }, { items: [], next_offset: 0, has_more: true }, { offset: -1, next_offset: 0 }, { offset: 0.5, next_offset: 1.5 }, { limit: 0 }]) assert.throws(() => f.revenueReport(report('daily', '2026-10', { events }), 'daily', '2026-10', events.offset ?? 0));
});
test('revenue count metadata must be safe nonnegative integers rather than display undefined', () => {
  const f = harness().functions;
  for (const value of [undefined, null, '1', -1, 0.5, Number.MAX_SAFE_INTEGER + 1]) assert.throws(() => f.revenueReport(report('daily', '2026-10', { summary: { verified_payment_count: value } }), 'daily', '2026-10'));
});
test('revenue metadata cannot claim a different accounting basis or an invalid report time', () => {
  const f = harness().functions;
  for (const change of [{ timezone: '' }, { generated_at_utc: 'yesterday' }, { range: { start_utc: 'bad', end_utc: 'bad' } },
    { basis: { ...report().basis, earned_revenue_calculated: true } }, { balances: { as_of_utc: 'unknown' } }]) assert.throws(() => f.revenueReport(report('daily', '2026-10', change), 'daily', '2026-10'), JSON.stringify(change));
});
test('all rendered amount fields including advance collections are validated before painting', () => {
  const f = harness().functions;
  for (const [type, field] of [['daily', 'room_collections'], ['daily', 'future_stay_room_collections'], ['monthly', 'collected_charges'], ['monthly', 'later_bill_period_collections']]) assert.throws(() => f.revenueReport(report(type, '2026-10', { summary: { [field]: null } }), type, '2026-10'));
  assert.throws(() => f.revenueReport(report('daily', '2026-10', { balances: { active_stay_security_deposits: null } }), 'daily', '2026-10'));
});
test('duplicate ledger IDs are rejected within one event type while different daily ledgers may share an ID', () => {
  const f = harness().functions;
  assert.throws(() => f.revenueReport(report('daily', '2026-10', { events: { items: [event(), event()], next_offset: 2 } }), 'daily', '2026-10'));
  const valid = report('daily', '2026-10', { events: { items: [event(), event('daily', { event_type: 'refund', cash_flow_direction: 'out', amount: '1.00', refund_purpose: 'deposit', refund_reference: 'OUT-1' })], next_offset: 2 } });
  assert.equal(f.revenueReport(valid, 'daily', '2026-10'), valid);
});
test('monthly reports reject daily refund or deposit-retention ledger rows', () => {
  const f = harness().functions;
  for (const event_type of ['refund', 'deposit_retention']) assert.throws(() => f.revenueReport(report('monthly', '2026-10', { events: { items: [event('monthly', { event_type })] } }), 'monthly', '2026-10'));
});
test('malformed event identity, amount and reference cannot enter the revenue table', () => {
  const f = harness().functions;
  for (const change of [{ event_id: 0 }, { event_id: '1' }, { event_type: 'unknown' }, { reference_no: null }, { amount: '1e3' }]) assert.throws(() => f.revenueReport(report('daily', '2026-10', { events: { items: [event('daily', change)] } }), 'daily', '2026-10'));
});
test('a month with refunds exceeding its receipts keeps a negative cash flow without inventing negative receipts', async () => {
  const h = harness(), data = report('daily', '2026-10', { summary: { refunds_total: '200.00', deposit_refunds: '10.00', cancellation_refunds: '190.00', refund_count: 2, net_cash_flow: '-89.87' } });
  await load(h, 'daily', data);
  assert.equal(h.$('#daily-revenue-received').textContent, '฿100.00'); assert.equal(h.$('#daily-revenue-refunded').textContent, '฿200.00'); assert.equal(h.$('#daily-revenue-net').textContent, '-฿89.87');
  assert.throws(() => h.functions.revenueReport(report('daily', '2026-10', { summary: { room_collections: '-100.00' } }), 'daily', '2026-10'));
});
test('daily and monthly revenue use separate requests, totals, balances and rows', async () => {
  const h = harness(), daily = h.controller.loadRevenue('daily'), monthly = h.controller.loadRevenue('monthly');
  assert.equal(h.requests[0].url, '/api/admin/daily/revenue?period=2026-10&offset=0'); assert.equal(h.requests[1].url, '/api/admin/monthly/revenue?period=2026-10&offset=0');
  h.requests[1].resolve(report('monthly')); await monthly; assert.equal(h.$('#monthly-revenue-received').textContent, '฿3,050.00'); assert.equal(h.$('#daily-revenue-received').textContent, '—');
  h.requests[0].resolve(report()); await daily;
  assert.equal(h.$('#daily-revenue-received').textContent, '฿100.00'); assert.equal(h.$('#daily-revenue-net').textContent, '฿110.13'); assert.equal(h.$('#daily-revenue-deposit').textContent, '฿10.00');
  assert.equal(h.$('#monthly-revenue-net').textContent, '฿3,050.27'); assert.equal(h.$('#monthly-revenue-deposit').closest('.stat-card').hidden, true);
  assert.match(textTree(h.$('#daily-revenue-rows')), /DAY-1/); assert.doesNotMatch(textTree(h.$('#daily-revenue-rows')), /BILL-1/);
  assert.match(textTree(h.$('#monthly-revenue-rows')), /BILL-1/); assert.doesNotMatch(textTree(h.$('#monthly-revenue-rows')), /DAY-1/);
});
test('changing a month aborts and fences an older response even if the server finishes it later', async () => {
  const h = harness(), older = h.controller.loadRevenue('daily');
  h.$('#daily-revenue-period').value = '2026-09'; const newer = h.$('#daily-revenue-period').listeners.change();
  assert.equal(h.requests[0].options.signal.aborted, true); assert.match(h.requests[1].url, /period=2026-09/);
  h.requests[1].resolve(report('daily', '2026-09', { summary: { room_collections: '50.00', security_deposit_collections: '0.00', bank_adjustment: '0.00', gross_receipts: '50.00', bank_receipts: '50.00', net_cash_flow: '50.00' },
    events: { items: [event('daily', { reference_no: 'SEPTEMBER', occurred_at: '2026-09-10T05:00:00.000000Z', amount: '50.00', room_amount: '50.00', deposit_amount: '0.00', bank_adjustment: '0.00' })] } })); await newer;
  h.requests[0].resolve(report()); await older;
  assert.equal(h.$('#daily-revenue-received').textContent, '฿50.00'); assert.match(textTree(h.$('#daily-revenue-rows')), /SEPTEMBER/); assert.doesNotMatch(textTree(h.$('#daily-revenue-rows')), /DAY-1/); assert.equal(h.$('#daily-revenue-heading-month').textContent, '2026-09');
});
test('a stale failed request cannot replace a newer successful month with an error', async () => {
  const h = harness(), old = h.controller.loadRevenue('monthly'); h.$('#monthly-revenue-period').value = '2026-09'; const fresh = h.controller.loadRevenue('monthly');
  h.requests[1].resolve(report('monthly', '2026-09')); await fresh; h.requests[0].reject(new Error('old month failed')); await old;
  assert.equal(h.$('#monthly-revenue-received').textContent, '฿3,050.00'); assert.equal(h.$('#monthly-revenue-error').hidden, true);
});
test('failed refresh clears stale money and details to dashes while preserving the other workspace', async () => {
  const h = harness(); await load(h, 'daily', report()); await load(h, 'monthly', report('monthly'));
  const work = h.controller.loadRevenue('daily');
  assert.equal(h.$('#daily-revenue-received').textContent, '—'); assert.equal(h.$('#daily-revenue-rows').children.length, 0); assert.equal(h.$('#daily-revenue-details').children.length, 0);
  h.requests.at(-1).reject(new Error('report unavailable')); await work;
  for (const key of ['received', 'refunded', 'net', 'deposit']) assert.equal(h.$(`#daily-revenue-${key}`).textContent, '—');
  assert.equal(h.$('#daily-revenue-error').hidden, false); assert.match(h.$('#daily-revenue-error').errorTarget.textContent, /report unavailable/);
  assert.equal(h.$('#monthly-revenue-received').textContent, '฿3,050.00'); assert.match(textTree(h.$('#monthly-revenue-rows')), /BILL-1/);
});
test('a successful empty report shows server-provided zero while a wrong scope remains unknown', async () => {
  const h = harness(); await load(h, 'daily', report('monthly'));
  assert.equal(h.$('#daily-revenue-received').textContent, '—'); assert.equal(h.$('#daily-revenue-error').hidden, false);
  const empty = report('daily', '2026-10', { summary: Object.fromEntries(Object.entries(report().summary).map(([key, value]) => [key, typeof value === 'number' ? 0 : '0.00'])), events: { items: [], next_offset: 0 } });
  await load(h, 'daily', empty); assert.equal(h.$('#daily-revenue-received').textContent, '฿0.00'); assert.equal(h.$('#daily-revenue-error').hidden, true); assert.match(textTree(h.$('#daily-revenue-rows')), /ไม่มีรายการ/);
});
test('pagination requests the next offset once and retains isolated revenue rows', async () => {
  const h = harness(); await load(h, 'daily', report('daily', '2026-10', { events: { has_more: true }, summary: { verified_payment_count: 2 } }));
  const more = h.$('#daily-revenue-rows').closest('.panel').children[0]; assert.equal(more.hidden, false);
  const work = more.listeners.click(); const ignored = more.listeners.click(); await ignored;
  assert.equal(h.requests.length, 2); assert.match(h.requests[1].url, /offset=1$/);
  h.requests[1].resolve(report('daily', '2026-10', { events: { items: [event('daily', { event_id: 2, reference_no: 'DAY-2' })], offset: 1, next_offset: 2 }, summary: { verified_payment_count: 2 } })); await work;
  assert.equal(h.$('#daily-revenue-rows').children.length, 2); assert.equal(more.hidden, true); assert.equal(h.$('#monthly-revenue-rows').children.length, 0);
});
test('duplicate events across pages preserve the first page and display a recovery error', async () => {
  const h = harness(); await load(h, 'daily', report('daily', '2026-10', { events: { has_more: true } }));
  const work = h.controller.loadRevenue('daily', true); h.requests.at(-1).resolve(report('daily', '2026-10', { events: { offset: 1, next_offset: 2 } })); await work;
  assert.equal(h.$('#daily-revenue-rows').children.length, 1); assert.equal(h.$('#daily-revenue-error').hidden, false); assert.equal(h.$('#daily-revenue-received').textContent, '฿100.00');
});
test('changing the period during a continuation prevents old rows being appended to the new month', async () => {
  const h = harness(); await load(h, 'daily', report('daily', '2026-10', { events: { has_more: true } }));
  const oldPage = h.controller.loadRevenue('daily', true); h.$('#daily-revenue-period').value = '2026-09'; const newMonth = h.controller.loadRevenue('daily');
  assert.equal(h.requests[1].options.signal.aborted, true);
  h.requests[2].resolve(report('daily', '2026-09', { events: { items: [] , next_offset: 0 } })); await newMonth;
  h.requests[1].resolve(report('daily', '2026-10', { events: { items: [event('daily', { event_id: 2, reference_no: 'OLD-PAGE' })], offset: 1, next_offset: 2 } })); await oldPage;
  assert.equal(h.$('#daily-revenue-rows').children.length, 1); assert.doesNotMatch(textTree(h.$('#daily-revenue-rows')), /OLD-PAGE/); assert.equal(h.$('#daily-revenue-heading-month').textContent, '2026-09');
});
test('daily room housekeeping filters independently from booking status and search', async () => {
  const h = harness(), work = h.controller.loadDailyRooms();
  h.requests[0].resolve([room(1), room(2, { status: 'occupied', housekeeping_status: 'cleaning', room_type: 'ดีลักซ์' }), room(3, { status: 'reserved' }), room(4, { housekeeping_status: 'cleaning' })]); await work;
  assert.equal(h.$('#daily-room-stat-all').textContent, '4'); assert.equal(h.$('#daily-room-stat-available').textContent, '1'); assert.equal(h.$('#daily-room-stat-cleaning').textContent, '2');
  h.$('#daily-room-status').value = 'cleaning'; h.$('#daily-room-status').listeners.change(); assert.equal(h.$('#daily-room-rows').children.length, 2); assert.match(textTree(h.$('#daily-room-rows')), /D2/); assert.match(textTree(h.$('#daily-room-rows')), /D4/);
  h.$('#daily-room-status').value = 'occupied'; h.$('#daily-room-status').listeners.change(); assert.equal(h.$('#daily-room-rows').children.length, 1); assert.match(textTree(h.$('#daily-room-rows')), /D2/);
  h.$('#daily-room-status').value = ''; h.$('#daily-room-search').value = 'ดีลักซ์'; h.$('#daily-room-search').listeners.input(); assert.equal(h.$('#daily-room-rows').children.length, 1); assert.match(textTree(h.$('#daily-room-rows')), /D2/);
});
test('daily room creation and editing explicitly use daily context with server delete capabilities', async () => {
  const h = harness(), work = h.controller.loadDailyRooms(); h.requests[0].resolve([room(1, { can_delete: false }), room(2)]); await work;
  const actions = rowActions(h); assert.equal(actions[0].length, 1); assert.equal(actions[1].length, 2);
  await h.roomClick(actions[0][0]); assert.equal(h.opened[0].room.id, 1); assert.equal(h.opened[0].mode, 'daily');
  h.$('[data-open-daily-room-dialog]').listeners.click(); assert.equal(h.opened[1].room, null); assert.equal(h.opened[1].mode, 'daily');
  await h.roomClick(forged('delete', 1)); assert.equal(h.requests.length, 1);
});
test('wrong monthly room payload cannot produce daily edit or write actions', async () => {
  const h = harness(), work = h.controller.loadDailyRooms(); h.requests[0].resolve([room(99, { rental_mode: 'monthly' })]); await work;
  assert.equal(h.$('#daily-room-rows').children.length, 0); assert.match(h.$('#daily-room-state').textContent, /ไม่ตรงกับส่วนงาน/);
  await h.roomClick(forged('edit', 99)); await h.roomClick(forged('delete', 99)); assert.equal(h.opened.length, 0); assert.equal(h.requests.length, 1);
});
test('old room buttons are disabled by a refresh and stale room responses cannot restore them', async () => {
  const h = harness(), initial = h.controller.loadDailyRooms(); h.requests[0].resolve([room()]); await initial;
  const oldButton = rowActions(h)[0][0], older = h.controller.loadDailyRooms(), newest = h.controller.loadDailyRooms();
  assert.equal(h.requests[1].options.signal.aborted, true); await h.roomClick(oldButton); assert.equal(h.opened.length, 0);
  h.requests[2].resolve([room(2)]); await newest; h.requests[1].resolve([room(99, { rental_mode: 'monthly' })]); await older;
  assert.match(textTree(h.$('#daily-room-rows')), /D2/); assert.doesNotMatch(textTree(h.$('#daily-room-rows')), /D99/);
  await h.roomClick(forged('edit', 99)); assert.equal(h.opened.length, 0);
});
