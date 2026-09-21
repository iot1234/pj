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
const helpers = between('function setBusy(', 'function showFormError(')
  + between('function dialogCloseBlocked(', 'async function confirmAction(');
const flows = [
  { form: 'move-in-form', dialog: 'move-in-dialog', end: 'function resetResidentCreateReuse(' },
  { form: 'resident-create-form', dialog: 'resident-create-dialog', end: "$('#resident-rows').addEventListener('click'" },
  { form: 'resident-edit-form', dialog: 'resident-edit-dialog', end: "$('#resident-move-out-form').addEventListener('submit'" },
];

function node() {
  const attributes = new Map();
  return {
    dataset: {}, textContent: '', disabled: false,
    setAttribute: (key, value) => attributes.set(key, value),
    getAttribute: (key) => attributes.get(key) ?? null,
    hasAttribute: (key) => attributes.has(key),
    removeAttribute: (key) => attributes.delete(key),
  };
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

function harness(flow, { deferReload = false } = {}) {
  const requests = [];
  const reload = deferred();
  const effects = { resets: 0, access: [], errors: [] };
  const submitButton = node();
  const closeButton = node();
  const dialog = { ...node(), open: true, close() { this.open = false; } };
  const summary = { textContent: 'Alice · ห้อง A101' };
  const reuseInput = { ...node(), disabled: true, required: false, value: '', focus() {} };
  const form = {
    ...node(),
    closest: () => dialog,
    elements: { reuse_resident_id: reuseInput },
    reportValidity: () => true,
    querySelector: () => submitButton,
    reset: () => { effects.resets += 1; },
    addEventListener: (_event, callback) => { form.submit = callback; },
  };
  const values = {
    booking_id: '11', resident_id: '21', room_id: '1',
    full_name: 'Alice', phone: '0811111111', email: '',
  };
  const otherNodes = new Map();
  const context = {
    FormData: class { entries() { return Object.entries(values); } },
    $: (selector) => {
      if (selector === `#${flow.form}`) return form;
      if (selector === `#${flow.dialog}`) return dialog;
      if (selector === '#move-in-summary') return summary;
      if (!otherNodes.has(selector)) otherNodes.set(selector, node());
      return otherNodes.get(selector);
    },
    $$: (selector, root) => selector === '[data-close-dialog]' ? [closeButton] : root === form ? [reuseInput] : [],
    state: {
      rooms: [{ id: 1, room_code: 'A101', status: 'available' }],
      residents: [{ id: 21, full_name: 'Alice', phone: '0811111111', room_code: 'A101' }],
      loaded: new Set(['residents']),
    },
    api: () => { const request = deferred(); requests.push(request); return request.promise; },
    text: (value, fallback = '—') => value == null || value === '' ? fallback : String(value),
    errorMessage: (error) => error.message,
    showFormError: (_node, message) => { if (message) effects.errors.push(message.message || message); },
    showResidentAccess: (data, label) => effects.access.push({ data, label }),
    toast: () => {},
    loadResidents: () => deferReload ? reload.promise : Promise.resolve(),
    loadRooms: () => deferReload ? reload.promise : Promise.resolve(),
    loadBookings: () => deferReload ? reload.promise : Promise.resolve(),
  };
  vm.createContext(context);
  const listener = between(`$('#${flow.form}').addEventListener('submit'`, flow.end);
  vm.runInContext(`${helpers}\n${listener}`, context);
  return {
    effects, dialog, closeButton, submitButton, summary, reuseInput,
    submit: () => form.submit({ preventDefault() {}, currentTarget: form }),
    close: () => context.closeDialog(dialog, closeButton),
    resolve: (value, request = 0) => requests[request].resolve(value),
    reject: (error, request = 0) => requests[request].reject(error),
    resolveReload: reload.resolve,
    reopen: () => { dialog.open = true; submitButton.disabled = false; },
  };
}

for (const flow of flows) {
  test(`${flow.form}: pending save blocks close and releases the modal after success`, async () => {
    const ui = harness(flow);
    const work = ui.submit();
    assert.equal(ui.dialog.dataset.dialogBusy, 'true');
    assert.equal(ui.closeButton.disabled, true);
    assert.equal(ui.close(), false, 'cannot close and reopen this form for another resident');
    ui.resolve({ full_name: 'Alice', room_code: 'A101', resident_access: { activation_required: true } });
    await work;
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.closeButton.disabled, false);
    assert.equal(ui.submitButton.disabled, false);
    assert.equal(ui.effects.resets, 1);
    assert.equal(ui.effects.access[0].label, 'Alice · ห้อง A101');
  });

  test(`${flow.form}: failed save keeps form data and restores close controls`, async () => {
    const ui = harness(flow);
    const work = ui.submit();
    assert.equal(ui.close(), false);
    ui.reject(new Error('Temporary failure'));
    await work;
    assert.equal(ui.dialog.open, true);
    assert.equal(ui.closeButton.disabled, false);
    assert.equal(ui.submitButton.disabled, false);
    assert.equal(ui.effects.resets, 0);
    assert.deepEqual(ui.effects.errors, ['Temporary failure']);
    assert.equal(ui.close(), true);
  });

  test(`${flow.form}: save controls finish before the post-save list reload`, async () => {
    const ui = harness(flow, { deferReload: true });
    const work = ui.submit();
    ui.resolve({ resident_access: { activation_required: true } });
    await new Promise(setImmediate);
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.submitButton.disabled, false, 'completed save must release its button before closing');
    assert.equal(ui.closeButton.disabled, false);
    ui.resolveReload();
    await work;
  });

  test(`${flow.form}: an earlier list reload cannot unlock a newer pending save`, async () => {
    const ui = harness(flow, { deferReload: true });
    const firstSave = ui.submit();
    ui.resolve({ resident_access: { activation_required: true } });
    await new Promise(setImmediate);
    ui.reopen();
    const nextSave = ui.submit();
    assert.equal(ui.dialog.dataset.dialogBusy, 'true');
    ui.resolveReload();
    await firstSave;
    assert.equal(ui.dialog.dataset.dialogBusy, 'true', 'older finally must not release the new request');
    assert.equal(ui.submitButton.disabled, true);
    assert.equal(ui.close(), false);
    ui.resolve({ resident_access: { activation_required: true } }, 1);
    await nextSave;
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.submitButton.disabled, false);
  });
}

test('move-in activation label uses the submitted booking snapshot', async () => {
  const ui = harness(flows[0]);
  const work = ui.submit();
  // Even if another UI refresh changes the shared label, the credential must
  // remain associated with the resident whose request was submitted.
  ui.summary.textContent = 'Bob · ห้อง B202';
  ui.resolve({ resident_access: { activation_required: true } });
  await work;
  assert.equal(ui.effects.access[0].label, 'Alice · ห้อง A101');
});

test('move-in reuse confirmation remains enabled after the failed save unlocks fields', async () => {
  const ui = harness(flows[0]);
  const work = ui.submit();
  ui.reject(Object.assign(new Error('Confirm previous resident'), { details: { code: 'RESIDENT_REUSE_CONFIRMATION_REQUIRED', resident_id: 21, resident_name: 'Alice' } }));
  await work;
  assert.equal(ui.reuseInput.disabled, false);
  assert.equal(ui.reuseInput.required, true);
  assert.equal(ui.reuseInput.value, '21');
  assert.equal(ui.dialog.open, true);
});
