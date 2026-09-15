'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8').replace(/\r\n/g, '\n');
function between(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.ok(start >= 0 && end > start, startMarker);
  return source.slice(start, end);
}
const urlSource = between('function safeLineMessageUrl(', 'function safeRoomImage(');
const helpers = between('function setBusy(', 'function setFormFieldsBusy(')
  + between('function openDialog(', 'async function confirmAction(');
const codeA = `BIND-${'A'.repeat(32)}`;
const codeB = `BIND-${'B'.repeat(32)}`;
const messageUrl = (code) => `https://line.me/R/oaMessage/%40dorm.test/?${code}`;
function deferred() {
  let resolve; let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
const settle = () => new Promise(setImmediate);

function makeNode() {
  const attributes = new Map(); const listeners = new Map();
  return {
    dataset: {}, value: '', hidden: false, disabled: false, textContent: '', open: false,
    width: 220, height: 220, listeners,
    addEventListener(name, callback) { listeners.set(name, callback); },
    dispatch(name) { return listeners.get(name)?.({ preventDefault() {}, currentTarget: this }); },
    setAttribute(name, value) { attributes.set(name, String(value)); },
    getAttribute(name) { return attributes.get(name) ?? null; },
    hasAttribute(name) { return attributes.has(name); },
    removeAttribute(name) { attributes.delete(name); if (name === 'href') delete this.href; },
    showModal() { this.open = true; },
    close() { this.open = false; this.dispatch('close'); },
    focus() {}, select() {},
    getContext() { return { clearRect() {} }; },
  };
}

function harness(kind) {
  const nodes = new Map();
  const $ = (selector) => { if (!nodes.has(selector)) nodes.set(selector, makeNode()); return nodes.get(selector); };
  const requests = []; const timers = new Map(); const windowEvents = new Map(); const docEvents = new Map();
  const effects = { qr: [], toasts: [], statuses: [], copied: [], confirms: [] };
  let nextTimer = 1; let now = Date.now();
  let allowConfirm = true;
  const context = {
    $, $$: () => [$(`#${kind}-close-button`)],
    ApiError: class extends Error {},
    Date: class extends Date { static now() { return now; } },
    doc: { visibilityState: 'visible', addEventListener: (name, callback) => docEvents.set(name, callback), execCommand: () => true },
    window: {
      isSecureContext: true,
      setInterval: (callback, ms) => { const id = nextTimer++; timers.set(id, { callback, ms }); return id; },
      clearInterval: (id) => timers.delete(id),
      addEventListener: (name, callback) => windowEvents.set(name, callback),
    },
    navigator: { clipboard: { writeText: async (code) => effects.copied.push(code) } },
    api: (url, options = {}) => { const item = { ...deferred(), url, options }; requests.push(item); return item.promise; },
    objectFrom: (value, key) => value?.[key] && typeof value[key] === 'object' ? value[key] : value,
    errorMessage: (error) => error.message,
    formatDateTime: (value) => value,
    text: (value, fallback = '—') => value == null || value === '' ? fallback : String(value),
    showFormError: (node, message = '') => { node.textContent = message; node.hidden = !message; },
    toast: (message) => effects.toasts.push(message),
    confirmAction: async (...args) => { effects.confirms.push(args); return allowConfirm; },
    getQrLibrary: async () => ({ toCanvas() {} }),
    renderQrCanvas: async (_library, _canvas, payload) => { effects.qr.push(payload); },
    refreshVerifyingBills: () => {},
  };
  vm.createContext(context);
  vm.runInContext(`${urlSource}\n${helpers}`, context);
  if (kind === 'admin') {
    vm.runInContext(between('function initAdminLineBinding(', 'function initAdminConsole('), context);
    context.controller = context.initAdminLineBinding((status) => effects.statuses.push(status));
  } else {
    Object.assign(context, {
      state: {
        profile: { full_name: 'Draft name', email: 'draft@example.test', line_verified: false, line_user_id_hint: null, line_binding_ready: true },
        profileRevision: 0, lineStateRevision: 0, lineIssueRequest: false, lineStatusRequest: false, lineCodeExpiresAt: 0,
      },
      lineExpiryTimer: null, linePollTimer: null, lastLineReturnRefresh: 0,
      lineStartForm: $('#resident-line-start-form'), lineCodePanel: $('#resident-line-code-panel'),
      lineCodeInput: $('#resident-line-code'), lineCodeCopyButton: $('#resident-line-code-copy'),
      lineCodeRenewButton: $('#resident-line-code-renew'), lineOpenMessage: $('#resident-line-open-message'),
      lineCodeQr: $('#resident-line-code-qr'), lineCodeQrFallback: $('#resident-line-code-qr-fallback'),
      lineAddFriendLink: $('#resident-line-add-friend'), lineStatusRefreshButton: $('#resident-line-status-refresh'),
      lineUnlinkButton: $('#resident-line-unlink'),
    });
    context.lineStartForm.querySelector = () => $('#resident-line-submit');
    const residentSource = between('function stopLineCodeTracking(', 'function replaceResidentHash(')
      + between('function renderLineStatus()', 'function fillProfile()')
      + between("lineStartForm.addEventListener('submit'", "$('#resident-load-qr').addEventListener('click'")
      + between("doc.addEventListener('visibilitychange', () => {\n      if (doc.visibilityState === 'visible') refreshVerifyingBills(true);", '    loadAll();');
    vm.runInContext(residentSource, context);
    context.renderLineStatus();
  }
  const status = (overrides = {}) => ({
    resident_id: 21, full_name: 'Alice', room_code: 'A101', line_verified: false,
    line_user_id_hint: null, line_binding_ready: true, pending_expires_at: null,
    line_add_friend_url: 'https://line.me/R/ti/p/%40dorm.test', ...overrides,
  });
  return {
    $, context, requests, effects, timers, status,
    setConfirm: (value) => { allowConfirm = value; },
    advance: (ms) => { now += ms; },
    result: (code = codeA, overrides = {}) => ({ code, expires_at: new Date(now + 600_000).toISOString(), line_message_url: messageUrl(code), ...overrides }),
    async openAdmin(overrides = {}) {
      context.controller.open({ id: 21, full_name: 'Alice', room_code: 'A101' });
      requests.at(-1).resolve(status(overrides)); await settle();
    },
    tick: (ms) => { for (const timer of [...timers.values()]) if (timer.ms === ms) timer.callback(); },
    focus: () => windowEvents.get('focus')?.(),
    visibility: (value) => { context.doc.visibilityState = value; docEvents.get('visibilitychange')?.(); },
  };
}

test('LINE message links require the exact official URL and the same issued code', () => {
  const context = {}; vm.createContext(context); vm.runInContext(urlSource, context);
  assert.equal(context.safeLineMessageUrl(messageUrl(codeA), codeA), messageUrl(codeA));
  for (const value of [messageUrl(codeB), messageUrl(codeA).replace('https:', 'http:'), messageUrl(codeA).replace('line.me/', 'line.me.evil.test/'), `${messageUrl(codeA)}#extra`, `${messageUrl(codeA)}&redirect=evil`, `javascript:${codeA}`, messageUrl(codeA).replace('%40', '@')]) {
    assert.equal(context.safeLineMessageUrl(value, codeA), '');
  }
});

test('resident recognizes multiple verified accounts without a single-account hint', async () => {
  const ui = harness('resident');
  const refresh = ui.context.refreshLineStatus(true);
  ui.requests[0].resolve(ui.status({ line_verified: true, line_user_id_hint: null, line_bound_count: 2, line_blocked: false }));
  assert.equal(await refresh, true);
  assert.match(ui.$('#resident-line-status').textContent, /ยืนยันแล้ว 2 บัญชี/);
  assert.equal(ui.$('#resident-line-start-form').hidden, true);
  assert.equal(ui.$('#resident-line-unlink').hidden, false);
  await ui.context.issueResidentLineCode(); assert.equal(ui.requests.length, 1);
  assert.equal(ui.context.state.profile.full_name, 'Draft name');
});

test('resident block refresh clears old code surfaces and prevents issuing despite legacy readiness', async () => {
  const ui = harness('resident'); const issued = ui.context.issueResidentLineCode(); ui.requests[0].resolve(ui.result()); await issued;
  const refresh = ui.context.refreshLineStatus(true); ui.requests[1].resolve(ui.status({ line_blocked: true, line_bound_count: 0, line_binding_ready: true })); await refresh;
  assert.equal(ui.$('#resident-line-code').value, ''); assert.equal(ui.$('#resident-line-open-message').href, undefined);
  assert.equal(ui.$('#resident-line-submit').disabled, true); assert.match(ui.$('#resident-line-status').textContent, /ระงับ/);
  await ui.context.issueResidentLineCode(); assert.equal(ui.requests.length, 2);
});

test('legacy admin modal recognizes multiple accounts and refuses creation while blocked', async () => {
  const ui = harness('admin'); await ui.openAdmin({ line_verified: true, line_user_id_hint: null, line_bound_count: 2 });
  assert.match(ui.$('#admin-line-status').textContent, /ผูก LINE แล้ว 2 บัญชี/);
  assert.equal(ui.$('#admin-line-issue').disabled, true); assert.equal(ui.$('#admin-line-unlink').hidden, false);
  await ui.$('#admin-line-issue').dispatch('click'); assert.equal(ui.requests.length, 1);
  ui.$('#admin-line-dialog').close(); await ui.openAdmin({ line_blocked: true, line_bound_count: 0 });
  assert.equal(ui.$('#admin-line-issue').disabled, true); assert.match(ui.$('#admin-line-status').textContent, /ระงับ/);
  await ui.$('#admin-line-issue').dispatch('click'); assert.equal(ui.requests.length, 2);
});

test('resident code creates a chat link and QR URL while copy keeps the raw code', async () => {
  const ui = harness('resident');
  const work = ui.context.issueResidentLineCode();
  ui.requests[0].resolve(ui.result()); await work;
  assert.equal(ui.$('#resident-line-open-message').href, messageUrl(codeA));
  assert.deepEqual(ui.effects.qr, [messageUrl(codeA)]);
  await ui.$('#resident-line-code-copy').dispatch('click');
  assert.deepEqual(ui.effects.copied, [codeA]);
  assert.ok(ui.requests.every((request) => request.url.startsWith('/api/')), 'no request is sent to LINE by the page');
});

test('resident rotation cancellation preserves the code and confirmed rotation retires it before posting', async () => {
  const ui = harness('resident');
  const first = ui.context.issueResidentLineCode(); ui.requests[0].resolve(ui.result()); await first;
  ui.setConfirm(false); await ui.context.issueResidentLineCode(true);
  assert.equal(ui.requests.length, 1); assert.equal(ui.$('#resident-line-code').value, codeA);
  ui.setConfirm(true); const next = ui.context.issueResidentLineCode(true); await settle();
  assert.equal(ui.$('#resident-line-code').value, '');
  assert.equal(ui.$('#resident-line-open-message').href, undefined);
  ui.requests[1].resolve(ui.result(codeB)); await next;
  assert.equal(ui.$('#resident-line-open-message').href, messageUrl(codeB));
});

test('resident LINE refresh preserves profile drafts and announces only a transition to bound', async () => {
  const ui = harness('resident');
  const bound = { full_name: 'Server name', email: 'server@example.test', line_verified: true, line_user_id_hint: '•••123456', line_binding_ready: true };
  const first = ui.context.refreshLineStatus(true); ui.requests[0].resolve(bound); await first;
  const next = ui.context.refreshLineStatus(true); ui.requests[1].resolve(bound); await next;
  assert.equal(ui.context.state.profile.full_name, 'Draft name');
  assert.equal(ui.context.state.profile.email, 'draft@example.test');
  assert.equal(ui.effects.toasts.filter((message) => message.includes('สำเร็จ')).length, 1);
});

test('resident expiry removes the code, chat link, and QR', async () => {
  const ui = harness('resident');
  const work = ui.context.issueResidentLineCode(); ui.requests[0].resolve(ui.result()); await work;
  ui.advance(601_000); ui.tick(1000);
  assert.equal(ui.$('#resident-line-code').value, '');
  assert.equal(ui.$('#resident-line-open-message').href, undefined);
  assert.equal(ui.$('#resident-line-code-qr').hidden, true);
});

test('resident return refresh is deduplicated across visibility and focus events', async () => {
  const ui = harness('resident');
  ui.visibility('hidden'); ui.focus(); assert.equal(ui.requests.length, 0);
  ui.visibility('visible'); ui.focus(); assert.equal(ui.requests.length, 1);
  ui.requests[0].resolve(ui.status()); await settle();
  assert.equal(ui.context.state.profile.full_name, 'Draft name');
});

test('a missing or mismatched server link retains resident copy fallback without drawing a QR', async () => {
  const ui = harness('resident');
  const work = ui.context.issueResidentLineCode(); ui.requests[0].resolve(ui.result(codeA, { line_message_url: messageUrl(codeB) })); await work;
  assert.equal(ui.$('#resident-line-code').value, codeA);
  assert.equal(ui.$('#resident-line-open-message').hidden, true);
  assert.equal(ui.$('#resident-line-code-qr-fallback').hidden, false);
  assert.deepEqual(ui.effects.qr, []);
});

test('admin issuance locks the selected resident and displays only the official chat QR', async () => {
  const ui = harness('admin'); await ui.openAdmin();
  const work = ui.$('#admin-line-issue').dispatch('click');
  assert.equal(ui.$('#admin-line-dialog').dataset.dialogBusy, 'true');
  ui.requests.at(-1).resolve(ui.result()); await work;
  assert.equal(ui.$('#admin-line-code').value, codeA);
  assert.equal(ui.$('#admin-line-open-message').href, messageUrl(codeA));
  assert.deepEqual(ui.effects.qr, [messageUrl(codeA)]);
  ui.requests.at(-1).resolve(ui.status({ pending_expires_at: ui.result().expires_at })); await settle();
});

test('admin status from a previous resident cannot replace a newly opened resident', async () => {
  const ui = harness('admin');
  ui.context.controller.open({ id: 21, full_name: 'Alice', room_code: 'A101' });
  ui.$('#admin-line-dialog').close();
  ui.context.controller.open({ id: 22, full_name: 'Bob', room_code: 'B202' });
  ui.requests[1].resolve(ui.status({ resident_id: 22, line_verified: true, line_user_id_hint: '•••BBBBBB' })); await settle();
  ui.requests[0].resolve(ui.status({ line_verified: true, line_user_id_hint: '•••AAAAAA' })); await settle();
  assert.equal(ui.$('#admin-line-summary').textContent, 'Bob · ห้อง B202');
  assert.match(ui.$('#admin-line-status').textContent, /BBBBBB/);
  assert.equal(ui.effects.statuses.length, 1);
});

test('admin polling runs only while its modal is open and visible', async () => {
  const ui = harness('admin'); await ui.openAdmin();
  ui.visibility('hidden'); ui.tick(5000); assert.equal(ui.requests.length, 1);
  ui.visibility('visible'); assert.equal(ui.requests.length, 2);
  ui.requests[1].resolve(ui.status()); await settle();
  ui.$('#admin-line-dialog').close(); ui.tick(5000); ui.focus();
  assert.equal(ui.requests.length, 2); assert.equal(ui.timers.size, 0);
});

test('admin configuration gaps prevent code generation and show the setup reason', async () => {
  const ui = harness('admin'); await ui.openAdmin({ line_binding_ready: false });
  assert.equal(ui.$('#admin-line-issue').disabled, true);
  assert.equal(ui.$('#admin-line-readiness').hidden, false);
  await ui.$('#admin-line-issue').dispatch('click'); assert.equal(ui.requests.length, 1);
});

test('admin rotation requires confirmation and replaces the old code and QR', async () => {
  const ui = harness('admin'); await ui.openAdmin();
  const first = ui.$('#admin-line-issue').dispatch('click');
  ui.requests.at(-1).resolve(ui.result()); await first;
  ui.requests.at(-1).resolve(ui.status({ pending_expires_at: ui.result().expires_at })); await settle();
  ui.setConfirm(false); await ui.$('#admin-line-issue').dispatch('click');
  assert.equal(ui.$('#admin-line-code').value, codeA);
  assert.equal(ui.requests.filter((request) => request.options.method === 'POST').length, 1);
  ui.requests.at(-1).resolve(ui.status({ pending_expires_at: ui.result().expires_at })); await settle();
  ui.setConfirm(true); const next = ui.$('#admin-line-issue').dispatch('click'); await settle();
  assert.equal(ui.$('#admin-line-code').value, '');
  assert.equal(ui.$('#admin-line-open-message').href, undefined);
  ui.requests.at(-1).resolve(ui.result(codeB)); await next;
  assert.equal(ui.$('#admin-line-code').value, codeB);
  assert.deepEqual(ui.effects.qr, [messageUrl(codeA), messageUrl(codeB)]);
});

test('admin binding transition removes code surfaces and does not repeat its success toast', async () => {
  const ui = harness('admin'); await ui.openAdmin();
  const work = ui.$('#admin-line-issue').dispatch('click'); ui.requests.at(-1).resolve(ui.result()); await work;
  const bound = ui.status({ line_verified: true, line_user_id_hint: '•••123456' });
  ui.requests.at(-1).resolve(bound); await settle();
  assert.equal(ui.$('#admin-line-code').value, '');
  assert.equal(ui.$('#admin-line-open-message').href, undefined);
  assert.equal(ui.$('#admin-line-qr').hidden, true);
  const refresh = ui.$('#admin-line-refresh').dispatch('click'); ui.requests.at(-1).resolve(bound); await refresh;
  assert.equal(ui.effects.toasts.filter((message) => message === 'ผู้พักผูก LINE สำเร็จแล้ว').length, 1);
});

test('admin unlink cancellation sends nothing and a failed unlink restores controls', async () => {
  const ui = harness('admin'); await ui.openAdmin({ line_verified: true, line_user_id_hint: '•••123456' });
  ui.setConfirm(false); await ui.$('#admin-line-unlink').dispatch('click'); assert.equal(ui.requests.length, 1);
  ui.setConfirm(true); const work = ui.$('#admin-line-unlink').dispatch('click'); await settle();
  assert.equal(ui.$('#admin-line-dialog').dataset.dialogBusy, 'true');
  ui.requests.at(-1).reject(new Error('Temporary failure')); await work;
  assert.equal(ui.$('#admin-line-dialog').dataset.dialogBusy, undefined);
  assert.equal(ui.$('#admin-line-unlink').disabled, false);
  assert.equal(ui.$('#admin-line-error').textContent, 'Temporary failure');
});

test('admin expiry clears copy, deeplink, and QR until a new code is issued', async () => {
  const ui = harness('admin'); await ui.openAdmin();
  const work = ui.$('#admin-line-issue').dispatch('click'); ui.requests.at(-1).resolve(ui.result()); await work;
  ui.advance(601_000); ui.tick(1000);
  assert.equal(ui.$('#admin-line-code').value, '');
  assert.equal(ui.$('#admin-line-open-message').href, undefined);
  assert.equal(ui.$('#admin-line-qr').hidden, true);
});

test('issuing an admin code while a manual status read is pending does not leave a stale busy label', async () => {
  const ui = harness('admin'); await ui.openAdmin();
  const oldRead = ui.$('#admin-line-refresh').dispatch('click');
  const oldRequest = ui.requests.at(-1);
  const issue = ui.$('#admin-line-issue').dispatch('click'); ui.requests.at(-1).resolve(ui.result()); await issue;
  oldRequest.resolve(ui.status()); await oldRead;
  ui.requests.at(-1).resolve(ui.status({ pending_expires_at: ui.result().expires_at })); await settle();
  assert.equal(ui.$('#admin-line-refresh').getAttribute('aria-busy'), null);
  assert.equal(ui.$('#admin-line-refresh').disabled, false);
});

test('resident generation rejects an expired response instead of granting a local ten-minute extension', async () => {
  const ui = harness('resident');
  const work = ui.context.issueResidentLineCode();
  ui.requests[0].resolve(ui.result(codeA, { expires_at: '2000-01-01T00:00:00Z' })); await work;
  assert.equal(ui.$('#resident-line-code').value, '');
  assert.equal(ui.$('#resident-line-open-message').href, undefined);
  assert.equal(ui.timers.size, 0);
  assert.equal(ui.$('#resident-line-error').hidden, false);
});

test('late resident QR failure cannot reannounce or reveal a code that expired while drawing', async () => {
  const ui = harness('resident');
  const drawing = deferred(); ui.context.renderQrCanvas = () => drawing.promise;
  const work = ui.context.issueResidentLineCode(); ui.requests[0].resolve(ui.result()); await settle();
  ui.advance(601_000); ui.tick(1000);
  drawing.reject(new Error('Drawing failed')); await work;
  assert.equal(ui.$('#resident-line-code').value, '');
  assert.equal(ui.$('#resident-line-code-panel').hidden, true);
  assert.equal(ui.effects.toasts.filter((message) => message.startsWith('สร้างรหัสแล้ว')).length, 0);
});

test('resident unlink preserves unsaved profile fields and fences an older bound-status response', async () => {
  const ui = harness('resident');
  ui.context.state.profile.line_verified = true;
  ui.context.state.profile.line_user_id_hint = '•••123456';
  const read = ui.context.refreshLineStatus(true);
  const unlink = ui.$('#resident-line-unlink').dispatch('click'); await settle();
  ui.requests[1].resolve({ full_name: 'Server name', email: 'server@example.test', line_verified: false, line_user_id_hint: null, line_binding_ready: true }); await unlink;
  ui.requests[0].resolve({ line_verified: true, line_user_id_hint: '•••123456', line_binding_ready: true }); await read;
  assert.equal(ui.context.state.profile.full_name, 'Draft name');
  assert.equal(ui.context.state.profile.email, 'draft@example.test');
  assert.equal(ui.context.state.profile.line_verified, false);
  assert.equal(ui.$('#resident-line-unlink').hidden, true);
});
