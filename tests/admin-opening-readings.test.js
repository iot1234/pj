'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
function between(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.ok(start >= 0 && end > start, `production source boundary: ${startMarker}`);
  return source.slice(start, end);
}

function node(tagName = 'DIV', className = '', textContent = '') {
  const attributes = new Map();
  return {
    tagName, className, textContent, children: [], value: '', disabled: false, dataset: {},
    classList: { toggle() {} },
    append(...items) { this.children.push(...items); },
    replaceChildren(...items) { this.children = items; },
    addEventListener() {},
    setAttribute: (key, value) => attributes.set(key, String(value)),
    getAttribute: (key) => attributes.get(key) ?? null,
    hasAttribute: (key) => attributes.has(key),
    removeAttribute: (key) => attributes.delete(key),
  };
}

function descendants(element) {
  return [element, ...(element.children || []).flatMap(descendants)];
}

function meterHarness(meter) {
  const rows = node('TBODY');
  const statistics = {};
  const context = {
    state: { meters: [{ room_id: 8, room_code: '8483', ...meter }], meterPeriod: '2026-09' },
    create: node,
    $: (selector) => selector === '#meter-rows' ? rows : node(),
    $$: (_selector, root) => descendants(root).filter((item) => item.tagName === 'input'),
    text: (value, fallback = '—') => value == null || value === '' ? fallback : String(value),
    number: (value) => Number(value) || 0,
    finiteNumber: value => value === '' || value == null ? null : Number(value),
    formatPeriod: value => String(value),
    td: (content) => { const cell = node('td'); cell.append(content); return cell; },
    actionButton: (label, action, id) => ({ ...node('button', '', label), dataset: { action, id: String(id) } }),
    setStat: (key, value) => { statistics[key] = value; },
    setTableState() {},
  };
  vm.createContext(context);
  vm.runInContext(between('const meterHasPendingOpening =', 'function applySavedMeterResult('), context);
  context.renderMeters();
  const all = descendants(rows);
  return { all, statistics, inputs: all.filter((item) => item.tagName === 'input') };
}

test('pending opening readings cannot appear as zero usage or accept monthly input', () => {
  const ui = meterHarness({ opening_readings_pending: true, water_previous: null, electric_previous: null });
  assert.equal(ui.inputs.length, 2);
  assert.ok(ui.inputs.every((input) => input.disabled && input.value === ''));
  assert.ok(ui.inputs.every((input) => input.getAttribute('aria-label').includes('ยังขาดเลขมิเตอร์ ณ วันเข้าพัก')));
  assert.equal(ui.all.filter((item) => item.textContent === 'รอเลข ณ วันเข้าพัก').length, 2);
  assert.equal(ui.all.filter((item) => item.dataset?.meterDelta).map((item) => item.textContent).join(','), '—,—');
  assert.ok(ui.all.some((item) => item.dataset?.action === 'meter-opening-readings'));
  assert.ok(!ui.all.some((item) => item.dataset?.action === 'save-meter'));
  assert.match(ui.statistics['#meter-progress'], /จดแล้ว 0\/1/);
});

test('an actual zero opening reading remains a usable baseline', () => {
  const ui = meterHarness({ opening_readings_pending: false, water_previous: '0.00', electric_previous: '0.00', water_current: '7.50', electric_current: '12.00' });
  assert.ok(ui.inputs.every((input) => !input.disabled && input.min === '0'));
  assert.deepEqual(ui.all.filter((item) => item.dataset?.meterDelta).map((item) => item.textContent), ['7.50', '12.00']);
  assert.ok(ui.all.some((item) => item.dataset?.action === 'save-meter'));
  assert.ok(!ui.all.some((item) => item.textContent === 'รอเลข ณ วันเข้าพัก'));
});

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

function formHarness({ deferReload = false } = {}) {
  const requests = [];
  const errors = [];
  const reload = deferred();
  const dialog = { ...node(), open: true, close() { this.open = false; } };
  const submit = node('BUTTON');
  const close = node('BUTTON');
  const fields = ['occupancy_id', 'opening_water_reading', 'opening_electric_reading'].map((name, i) => ({ ...node('INPUT'), name, value: ['81', '0.00', '253.75'][i] }));
  const form = {
    ...node('FORM'),
    reportValidity: () => true,
    querySelector: () => submit,
    reset: () => fields.forEach((field) => { field.value = ''; }),
    addEventListener: (_name, listener) => { form.submit = listener; },
  };
  const state = { billPreview: {}, loaded: new Set(['residents', 'meters', 'bills', 'overview']) };
  const context = {
    state,
    $: (selector) => ({ '#opening-readings-form': form, '#opening-readings-dialog': dialog, '#opening-readings-error': node() })[selector],
    $$: (_selector, root) => root === form ? fields : [close],
    FormData: class { entries() { return fields.map((field) => [field.name, field.value]); } },
    showFormError: (_node, message) => { if (message) errors.push(message.message || message); },
    errorMessage: (error) => error.message,
    toast() {},
    api: (url, options) => { const request = { ...deferred(), url, options }; requests.push(request); return request.promise; },
    loadResidents: () => deferReload ? reload.promise : Promise.resolve(),
  };
  vm.createContext(context);
  vm.runInContext(between('function setBusy(', 'function showFormError(')
    + between('function dialogCloseBlocked(', 'async function confirmAction(')
    + between("$('#opening-readings-form').addEventListener('submit'", "$('#resident-edit-form').addEventListener('submit'"), context);
  return {
    dialog, fields, submit, close, requests, errors, state,
    save: () => form.submit({ preventDefault() {}, currentTarget: form }),
    cancel: () => context.closeDialog(dialog, close),
    resolveReload: reload.resolve,
  };
}

test('saving sends both exact readings once and locks edits and close until completion', async () => {
  const ui = formHarness();
  const work = ui.save();
  await ui.save();
  assert.equal(ui.requests.length, 1);
  assert.equal(ui.requests[0].url, '/api/admin/occupancies/81/opening-readings');
  assert.equal(JSON.stringify(ui.requests[0].options.body), '{"opening_water_reading":"0.00","opening_electric_reading":"253.75"}');
  assert.ok(ui.fields.every((field) => field.disabled));
  assert.equal(ui.cancel(), false);
  ui.requests[0].resolve({ opening_readings_pending: false });
  await work;
  assert.equal(ui.dialog.open, false);
  assert.ok(ui.fields.every((field) => !field.disabled && field.value === ''));
  assert.equal(ui.state.billPreview, null);
  assert.ok(!ui.state.loaded.has('meters') && !ui.state.loaded.has('bills'));
});

test('failed opening save retains real values and lets the admin correct or retry', async () => {
  const ui = formHarness();
  const work = ui.save();
  ui.requests[0].reject(new Error('The occupancy has changed'));
  await work;
  assert.equal(ui.dialog.open, true);
  assert.deepEqual(ui.fields.map((field) => field.value), ['81', '0.00', '253.75']);
  assert.ok(ui.fields.every((field) => !field.disabled));
  assert.equal(ui.submit.disabled, false);
  assert.deepEqual(ui.errors, ['The occupancy has changed']);
  assert.equal(ui.cancel(), true);
});

test('a completed list refresh cannot unlock a newer opening-reading save', async () => {
  const ui = formHarness({ deferReload: true });
  const first = ui.save();
  ui.requests[0].resolve({ opening_readings_pending: false });
  await new Promise(setImmediate);
  assert.equal(ui.dialog.open, false);
  assert.equal(ui.submit.disabled, false);
  ui.dialog.open = true;
  ui.fields.forEach((field, i) => { field.value = ['82', '88.50', '773.00'][i]; });
  const second = ui.save();
  ui.resolveReload();
  await first;
  assert.equal(ui.dialog.dataset.dialogBusy, 'true');
  assert.ok(ui.fields.every((field) => field.disabled));
  assert.equal(ui.cancel(), false);
  ui.requests[1].resolve({ opening_readings_pending: false });
  await second;
  assert.equal(ui.dialog.open, false);
});

test('missing history is locked and links to the missing month rather than a zero baseline', () => {
  const issue={code:'METER_HISTORY_GAP',message:'ขาดงวดก่อน',recovery_period:'2026-08',required_previous_period:'2026-08'};
  const ui=meterHarness({water_previous:null,electric_previous:null,water_locked:true,electric_locked:true,water_lock_reason:'history_gap',electric_lock_reason:'history_gap',water_issue:issue,electric_issue:issue,water_vacant_baseline:false,electric_vacant_baseline:false});
  assert.ok(ui.inputs.every(i=>i.disabled));assert.ok(!ui.all.some(i=>i.dataset?.action==='save-meter'));
  const links=ui.all.filter(i=>i.dataset?.action==='meter-missing-period');assert.equal(links.length,1);assert.equal(links[0].dataset.period,'2026-08');
  assert.equal(ui.all.filter(i=>i.textContent==='ขาดงวดก่อน').length,2);assert.ok(!ui.all.some(i=>String(i.textContent).includes('หน่วย 0')));
});
test('only an explicit vacant baseline may preview zero usage without a previous reading', () => {
  const vacant=meterHarness({water_previous:null,electric_previous:null,water_current:'100',electric_current:'200',water_vacant_baseline:true,electric_vacant_baseline:true});
  assert.deepEqual(vacant.all.filter(i=>i.dataset?.meterDelta).map(i=>i.textContent),['0.00','0.00']);
  const unknown=meterHarness({water_previous:null,electric_previous:null,water_current:'100',electric_current:'200'});
  assert.deepEqual(unknown.all.filter(i=>i.dataset?.meterDelta).map(i=>i.textContent),['—','—']);
});
test('negative usage is shown as a wrong reading instead of being clamped to zero', () => {
  const ui=meterHarness({water_previous:'100',water_current:'99',electric_previous:'200',electric_current:'201'});
  assert.equal(ui.all.find(i=>i.dataset?.meterDelta==='water').textContent,'เลขลดลง');
  assert.equal(ui.inputs[0].min,'100');
});
