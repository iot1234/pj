'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
const start = source.indexOf('function effectiveBillStatus('), end = source.indexOf('async function loadBills()', start);
function node(text = '') { return { textContent: text, children: [], disabled: false, append(...children) { this.children.push(...children); }, replaceChildren() { this.children = []; }, allText() { return this.textContent + this.children.map(child => child.allText()).join(' '); } }; }
function render(bills, legacyReady = false) {
  const nodes = new Map(), stats = new Map(), actions = [];
  const $ = key => { if (!nodes.has(key)) nodes.set(key, node()); return nodes.get(key); };
  const create = (_tag, _className = '', text = '') => node(text);
  const context = { billDataReady: () => true,
    $, create, state: { bills, settings: { integrations: { line_ready: legacyReady } } },
    objectFrom: value => value && typeof value === 'object' ? value : {}, number: value => Number(value || 0),
    text: value => String(value || ''), money: value => String(value), formatDate: value => String(value),
    pill: (_status, label) => node(label), td: value => typeof value === 'object' ? value : node(value),
    actionButton: (label, action, id) => { const button = node(label); actions.push({ action, id, button }); return button; },
    setStat: (key, value) => stats.set(key, value), setTableState() {},
  };
  vm.createContext(context); vm.runInContext(source.slice(start, end), context); context.renderAdminBills();
  return { rows: $('#bill-admin-rows'), bulk: $('#line-bulk-button'), actions, stats };
}
const bill = overrides => ({ id: 11, bill_no: 'B-11', status: 'pending', display_status: 'pending', payment_status: null, total_amount: '4500.00', line_linked: true, line_ready: true, ...overrides });

test('multiple recipient state shows partial failures without duplicating billing totals', () => {
  const result = render([bill({ line_status: 'failed', line_last_error: 'Test failure', line_can_queue: true, line_delivery_counts: { total: 3, sent: 1, pending: 1, failed: 1 } })]);
  assert.equal(result.rows.children.length, 1); assert.equal(result.stats.get('[data-bill-stat="outstanding"]'), '4500');
  assert.match(result.rows.allText(), /3 ผู้รับ/); assert.match(result.rows.allText(), /LINE รับแล้ว 1/); assert.match(result.rows.allText(), /ไม่สำเร็จ 1/);
  assert.equal(result.actions.length, 1); assert.equal(result.bulk.disabled, false);
});

test('a newly linked account can queue even after earlier recipients were sent and legacy OA is unavailable', () => {
  const result = render([bill({ line_status: 'sent', line_can_queue: true, line_recipient_count: 2, line_delivery_counts: { total: 1, sent: 1 } })], false);
  assert.equal(result.actions.length, 1); assert.equal(result.actions[0].id, 11); assert.equal(result.bulk.disabled, false);
});

test('pending or verified payment prevents LINE queue buttons despite a stale eligibility field', () => {
  for (const payment_status of ['pending', 'verified']) {
    const result = render([bill({ payment_status, line_can_queue: true })]);
    assert.equal(result.actions.length, 0); assert.equal(result.bulk.disabled, true);
  }
});

test('complete delivery suppresses repeat queueing and mixed sent/pending remains visibly pending', () => {
  const sent = render([bill({ line_status: 'sent', line_can_queue: false, line_delivery_counts: { total: 2, sent: 2 } })]);
  assert.equal(sent.actions.length, 0); assert.equal(sent.bulk.disabled, true);
  const pending = render([bill({ line_status: 'pending', line_can_queue: false, line_delivery_counts: { total: 2, sent: 1, pending: 1 } })]);
  assert.match(pending.rows.allText(), /รอส่ง 1/); assert.equal(pending.bulk.disabled, true);
});
