'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
function extract(start, end) { const a = source.indexOf(start), b = source.indexOf(end, a); assert.ok(a >= 0 && b > a, start); return source.slice(a, b); }
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; }
function resetHarness({ active = true } = {}) {
  const confirmation = deferred(), response = deferred(), writes = [], notices = [], warnings = [];
  const resident = { id: 21, full_name: 'Alice', room_code: 'A101', active };
  const button = { disabled: false, dataset: { id: '21', action: 'revoke-resident-sessions' } };
  const rows = { addEventListener(_name, listener) { this.click = listener; } };
  const state = { residentListReady: true, residents: [resident] };
  let reloads = 0;
  const context = {
    $: () => rows, state, ApiError: Error, text: String,
    setBusy: (node, busy) => { node.disabled = busy; },
    confirmAction: (...args) => { warnings.push(args); return confirmation.promise; },
    api: (url, options) => { writes.push({ url, options }); return response.promise; },
    toast: (...args) => notices.push(args), loadResidents: async () => { reloads += 1; },
  };
  vm.runInNewContext(extract('function notifyResidentPhoneAccess(', 'function setAdminMenu(')
    + extract("$('#resident-rows').addEventListener('click'", "$('#opening-readings-form').addEventListener('submit'"), context);
  return { button, state, resident, writes, notices, warnings, confirmation, response, reloads: () => reloads,
    click: () => rows.click({ target: { closest: () => button } }) };
}

test('owner can revoke sessions and LINE without issuing or delivering credentials', async () => {
  const ui = resetHarness(), work = ui.click();
  assert.equal(ui.button.disabled, true); assert.equal(ui.writes.length, 0);
  await ui.click(); assert.equal(ui.warnings.length, 1, 'a second click cannot open a second confirmation');
  assert.match(ui.warnings[0][1], /เบอร์เดิมได้ทันที/); assert.match(ui.warnings[0][1], /ต้องผูก LINE ใหม่/);
  ui.confirmation.resolve(true); await new Promise(setImmediate);
  assert.equal(ui.writes[0].url, '/api/admin/residents/21/access/reissue');
  assert.deepEqual(JSON.parse(JSON.stringify(ui.writes[0].options.body)), {});
  ui.response.resolve({ resident_id: 21, sessions_revoked: true, resident_access: { auth_method: 'phone', activation_required: false } });
  await work;
  assert.equal(ui.reloads(), 1); assert.equal(ui.button.disabled, false);
  assert.match(ui.notices[0][0], /Alice · ห้อง A101/); assert.match(ui.notices[0][0], /เบอร์ที่ผูกกับห้อง/);
  assert.doesNotMatch(ui.notices[0][0], /รหัส|คัดลอก|ส่งมอบ/);
});

test('cancelled, stale or inactive resident reset never dispatches a mutation', async () => {
  const cancelled = resetHarness(), work = cancelled.click(); cancelled.confirmation.resolve(false); await work;
  assert.equal(cancelled.writes.length, 0); assert.equal(cancelled.button.disabled, false);
  const stale = resetHarness(), pending = stale.click(); stale.state.residents = []; stale.confirmation.resolve(true); await pending;
  assert.equal(stale.writes.length, 0); assert.equal(stale.button.disabled, false);
  const inactive = resetHarness({ active: false }); await inactive.click();
  assert.equal(inactive.warnings.length, 0); assert.equal(inactive.writes.length, 0);
});

test('failed or cross-resident reset responses cannot announce a completed reset', async () => {
  for (const reply of [{}, { resident_id: 22, sessions_revoked: true }, { resident_id: 21, sessions_revoked: false }]) {
    const ui = resetHarness(), work = ui.click(); ui.confirmation.resolve(true); await new Promise(setImmediate);
    ui.response.resolve(reply); await work;
    assert.equal(ui.reloads(), 0); assert.equal(ui.button.disabled, false); assert.equal(ui.notices[0][1], 'error');
  }
});

function logoutHarness(dirty = false) {
  const confirmation = deferred(), response = deferred(), writes = [], destinations = [], warnings = [];
  const button = { disabled: false, setAttribute() {}, removeAttribute() {}, addEventListener(_name, fn) { this.click = fn; } };
  const context = {
    adminLogoutInProgress: false, state: { billDraftEdited: dirty }, $$: () => [button],
    hasDirtySettings: () => false, hasDirtyMeterRows: () => false, hasDirtyDialogDrafts: () => false,
    confirmAction: (...args) => { warnings.push(args); return confirmation.promise; },
    api: (url, options) => { writes.push({ url, options }); return response.promise; },
    location: { assign: value => destinations.push(value) }, toast() {},
  };
  vm.runInNewContext(extract("const adminLogoutButtons = $$('[data-admin-logout]');", 'const initialHash = location.hash'), context);
  return { button, confirmation, response, writes, warnings, destinations, click: () => button.click() };
}

test('ordinary owner logout has no activation delivery warning and sends one request', async () => {
  const ui = logoutHarness(), work = ui.click(); await ui.click();
  assert.equal(ui.warnings.length, 0); assert.equal(ui.writes.length, 1); assert.equal(ui.button.disabled, true);
  ui.response.resolve({}); await work; assert.deepEqual(ui.destinations, ['/admin/login']);
});

test('unsaved billing still requires a discard decision before owner logout', async () => {
  const ui = logoutHarness(true), work = ui.click();
  assert.equal(ui.writes.length, 0); assert.match(ui.warnings[0][1], /ข้อมูลบิล/); assert.doesNotMatch(ui.warnings[0][1], /activation|รหัสเปิดใช้งาน|ส่งมอบ/);
  ui.confirmation.resolve(false); await work; assert.equal(ui.writes.length, 0);
  const retry = ui.click(); ui.confirmation.resolve(true); await retry;
  assert.equal(ui.warnings.length, 2, 'the cancelled confirmation must release the logout guard');
  assert.equal(ui.writes.length, 0);
});

test('beforeunload protects billing drafts without credential delivery state', () => {
  let listener;
  const state = { billWorking: false, billDraftEdited: false }, context = {
    window: { addEventListener(_name, fn) { listener = fn; } }, state, adminLogoutInProgress: false,
    hasDirtySettings: () => false, hasDirtyMeterRows: () => false, hasDirtyDialogDrafts: () => false,
  };
  vm.runInNewContext(extract("window.addEventListener('beforeunload'", "integrationSettingsForm?.addEventListener('submit'"), context);
  for (const [working, draft, expected] of [[false, false, false], [true, false, true], [false, true, true]]) {
    state.billWorking = working; state.billDraftEdited = draft;
    const event = { prevented: false, preventDefault() { this.prevented = true; } }; listener(event);
    assert.equal(event.prevented, expected);
  }
});
