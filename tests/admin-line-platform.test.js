'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/admin-line-platform.js'), 'utf8');
const appSource = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const settle = () => new Promise(setImmediate);
const codeA = 'BIND-' + 'A'.repeat(32), codeB = 'BIND-' + 'B'.repeat(32);
const message = (code) => 'https://line.me/R/oaMessage/%40dorm.test/?' + code;
const readyOas = { rows: [{ id: 0, name: 'เดิม', enabled: true, is_default: true, line_binding_ready: true }, { id: 2, name: 'OA ใหม่', enabled: true, line_binding_ready: true }], default_oa_id: 0 };
const detail = (id = 1, overrides = {}) => ({ resident_id: id, full_name: 'ผู้พัก ' + id, room_code: 'A' + id, blocked: false, bound_count: 0, pending_codes: [], bound_accounts: [], history: [], ...overrides });
const pending = (code = codeA) => ({ id: code === codeA ? 10 : 11, oa_id: 0, oa_name: 'เดิม', code, expires_at: new Date(Date.now() + 7 * 86400000).toISOString(), line_message_url: message(code) });

class Node {
  constructor(tag = 'div', value = '') { this.tagName = tag.toUpperCase(); this._text = value; this.children = []; this.parentNode = null; this.dataset = {}; this.attributes = new Map(); this.listeners = new Map(); this.value = ''; this.defaultValue = ''; this.disabled = false; this.checked = false; this.hidden = false; this.open = false; this.width = 220; this.height = 220; }
  get textContent() { return this._text + this.children.map((node) => node.textContent).join(''); }
  set textContent(value) { this._text = String(value); this.children = []; }
  get options() { return this.children.filter((node) => node.tagName === 'OPTION'); }
  append(...nodes) { for (const node of nodes) { node.parentNode = this; this.children.push(node); if (this.tagName === 'SELECT' && this.children.length === 1) this.value = node.value; } }
  replaceChildren(...nodes) { this._text = ''; this.children = []; if (this.tagName === 'SELECT') this.value = ''; this.append(...nodes); }
  setAttribute(key, value) { this.attributes.set(key, String(value)); }
  getAttribute(key) { return this.attributes.get(key) ?? null; }
  hasAttribute(key) { return this.attributes.has(key); }
  removeAttribute(key) { this.attributes.delete(key); if (key === 'href') delete this.href; }
  addEventListener(name, fn) { if (!this.listeners.has(name)) this.listeners.set(name, []); this.listeners.get(name).push(fn); }
  async dispatch(name) { for (const callback of this.listeners.get(name) || []) await callback({ preventDefault() {}, currentTarget: this, target: this }); }
  contains(node) { return node === this || this.children.some((child) => child.contains(node)); }
  showModal() { this.open = true; }
  close() { this.open = false; this.dispatch('close'); }
  focus() {} select() {} reportValidity() { return true; }
  getContext() { return { clearRect() {} }; }
  all() { return this.children.flatMap((child) => [child, ...child.all()]); }
  reset() { for (const node of this.all()) { node.value = node.defaultValue; node.checked = false; } }
}

function harness() {
  const nodes = new Map(), requests = [], timers = new Map(), timeouts = new Map(), events = new Map();
  const effects = { toasts: [], copied: [], qr: [], confirmations: [] };
  let allowConfirm = true, nextTimer = 1, now = Date.now();
  const make = (id, tag = 'div') => { const node = new Node(tag); nodes.set('#' + id, node); return node; };
  const dialog = make('line-platform-dialog', 'dialog');
  const selectorMatches = (node, selector) => selector.split(',').some((part) => {
    part = part.trim();
    if (part === 'input' || part === 'select' || part === 'textarea' || part === 'button') return node.tagName === part.toUpperCase();
    if (part === '[data-close-dialog]') return node.hasAttribute('data-close-dialog');
    if (part === 'button[type="submit"]') return node.tagName === 'BUTTON' && node.type === 'submit';
    const name = /^\[name="([^"]+)"\]$/.exec(part); return name ? node.name === name[1] : false;
  });
  const $$ = (selector, root = dialog) => root.all().filter((node) => selectorMatches(node, selector));
  const $ = (selector, root) => root ? $$(selector, root)[0] || null : nodes.get(selector) || make(selector.slice(1));
  for (const id of ['line-platform-title', 'line-platform-summary', 'line-platform-error', 'line-pending-list', 'line-account-list', 'line-binding-history', 'line-recipient-code', 'line-oa-diagnostics', 'line-webhook-detail', 'line-binding-detail', 'line-oa-token-hint', 'line-oa-secret-hint', 'line-binding-policy', 'line-recipient-owner-field', 'line-recipient-enabled-field', 'line-recipient-mutes', 'line-recipient-status']) dialog.append(make(id));
  for (const id of ['line-binding-block', 'line-binding-unblock', 'line-binding-revoke-all', 'line-recipient-delete', 'line-binding-refresh', 'line-recipient-refresh']) dialog.append(make(id, 'button'));
  dialog.append(make('line-block-reason', 'textarea'));
  for (const id of ['line-oa-configure', 'line-recipient-create']) make(id, 'button');
  make('line-binding-search', 'input'); make('line-binding-filter', 'select');
  const formSpec = {
    'line-oa-form': ['channel_access_token', 'channel_secret', 'channel_access_token_clear', 'channel_secret_clear', 'enabled'],
    'line-binding-code-form': ['ttl_days', 'replace_pending'],
    'line-recipient-form': ['label', 'is_owner', 'enabled'],
  };
  for (const [id, names] of Object.entries(formSpec)) {
    const form = make(id, 'form'), fields = new Map(); form.elements = { namedItem: (key) => fields.get(key) };
    for (const name of names) { const node = new Node(['oa_id', 'replace_pending'].includes(name) ? 'select' : 'input'); node.name = name; if (name === 'ttl_days') node.defaultValue = node.value = '7'; if (name === 'replace_pending') node.defaultValue = node.value = 'true'; fields.set(name, node); form.append(node); }
    const submit = new Node('button', 'บันทึก'); submit.type = 'submit'; form.append(submit);
    if (id === 'line-recipient-form') for (const category of ['booking', 'payment', 'billing']) { const node = new Node('input'); node.name = 'muted_categories'; node.value = node.defaultValue = category; form.append(node); }
    dialog.append(form);
  }
  const close = new Node('button'); close.setAttribute('data-close-dialog', ''); dialog.append(close);
  const document = { visibilityState: 'visible', execCommand: () => true, addEventListener: (name, fn) => events.set('document:' + name, fn) };
  const window = { isSecureContext: true, setTimeout: (fn,ms) => { const id=nextTimer++; timeouts.set(id,{fn,ms}); return id; }, clearTimeout: id => timeouts.delete(id), setInterval: (fn, ms) => { const id = nextTimer++; timers.set(id, { fn, ms }); return id; }, clearInterval: (id) => timers.delete(id), addEventListener: (name, fn) => events.set('window:' + name, fn) };
  const context = { window, document, AbortController, Date: class extends Date { static now() { return now; } }, navigator: { clipboard: { writeText: async (value) => effects.copied.push(value) } }, $, $$ };
  vm.createContext(context); vm.runInContext(source, context);
  const extract = (a, b) => appSource.slice(appSource.indexOf(a), appSource.indexOf(b, appSource.indexOf(a)));
  vm.runInContext(extract('function setBusy(', 'function showFormError(') + extract('function openDialog(', 'async function confirmAction('), context);
  const helpers = {
    $, $$, create: (tag, className = '', text = '') => { const node = new Node(tag, text); node.className = className; return node; },
    api: (url, options = {}) => { const item = { ...deferred(), url, options }; requests.push(item); return item.promise; },
    toast: (value) => effects.toasts.push(value), errorMessage: (cause) => cause.message,
    showFormError: (node, message = '') => { node.textContent = message; node.hidden = !message; }, formatDateTime: (value) => String(value || '—'),
    openDialog: context.openDialog, closeDialog: context.closeDialog, setDialogBusy: context.setDialogBusy, setFormFieldsBusy: context.setFormFieldsBusy,
    confirmAction: async (...args) => { effects.confirmations.push(args); return allowConfirm; },
    getQrLibrary: async () => ({}), renderQrCanvas: async (_library, _canvas, value) => effects.qr.push(value),
  };
  const controller = window.DormLinePlatform.init(helpers);
  const form = (id) => $('#' + id);
  const field = (id, name) => form(id).elements.namedItem(name);
  const button = (label, root = dialog) => root.all().find((node) => node.tagName === 'BUTTON' && node.textContent === label);
  const openBinding = async (row = detail()) => { const work = controller.openBinding(row.resident_id); requests.at(-2).resolve(row); requests.at(-1).resolve(readyOas); await work; await settle(); };
  return { $, $$, window, document, controller, requests, effects, timers, helpers, dialog, form, field, button, openBinding, context, setConfirm: (value) => { allowConfirm = value; }, advance: (ms) => { now += ms; }, tick: (ms) => { for (const [id,timer] of [...timeouts]) if(timer.ms === ms){timeouts.delete(id);timer.fn();} for (const timer of [...timers.values()]) if (timer.ms === ms) timer.fn(); } };
}

test('platform chat URL accepts exact BIND/OWNER/ADMIN codes only on official OA message URLs', () => {
  const ui = harness(), validate = ui.window.DormLinePlatform.messageUrl;
  for (const prefix of ['BIND', 'OWNER', 'ADMIN']) { const code = prefix + '-' + 'F'.repeat(32); assert.equal(validate(message(code), code), message(code)); }
  for (const url of ['javascript:alert(1)', message(codeA) + '#fragment', message(codeB), message(codeA).replace('line.me', 'line.me.evil.test'), message(codeA).replace('%40', '@')]) assert.equal(validate(url, codeA), '');
});

test('binding filters combine trimmed search with an exact status', () => {
  const matches = harness().window.DormLinePlatform.matchesBinding;
  const row = { full_name: 'สมชาย Example', room_code: 'B-12', phone: '0812345678', line_binding_status: 'pending' };
  assert.equal(matches(row, ' example ', 'pending'), true); assert.equal(matches(row, 'B-12', ''), true); assert.equal(matches(row, '08123', 'bound'), false);
});

test('late detail from a previous resident cannot replace a newly selected resident', async () => {
  const ui = harness(); const first = ui.controller.openBinding(1), second = ui.controller.openBinding(2);
  ui.requests[2].resolve(detail(2)); ui.requests[3].resolve(readyOas); await second;
  ui.requests[0].resolve(detail(1)); ui.requests[1].resolve(readyOas); await first;
  assert.match(ui.$('#line-platform-summary').textContent, /ผู้พัก 2/);
});

test('cancel replacement preserves the existing usable code and sends no mutation', async () => {
  const ui = harness(); await ui.openBinding(detail(1, { pending_codes: [pending()] }));
  ui.setConfirm(false); await ui.form('line-binding-code-form').dispatch('submit');
  assert.equal(ui.requests.length, 2); assert.equal(ui.effects.confirmations.length, 1);
  await ui.button('คัดลอกรหัส').dispatch('click'); assert.deepEqual(ui.effects.copied, [codeA]);
  assert.equal(ui.dialog.dataset.dialogBusy, undefined);
});

test('adding a code lets the server choose the primary bot and preserves TTL and replacement choice', async () => {
  const ui = harness(); await ui.openBinding(detail(1, { pending_codes: [pending()] }));
  ui.field('line-binding-code-form', 'replace_pending').value = 'false'; ui.field('line-binding-code-form', 'ttl_days').value = '30';
  const work = ui.form('line-binding-code-form').dispatch('submit');
  assert.equal(ui.requests.at(-1).url, '/api/admin/line/residents/1/codes');
  assert.deepEqual(JSON.parse(JSON.stringify(ui.requests.at(-1).options.body)), { ttl_days: 30, replace_pending: false });
  assert.equal(ui.dialog.dataset.dialogBusy, 'true');
  ui.requests.at(-1).resolve({ detail: detail(1, { pending_codes: [pending(), pending(codeB)] }) }); await work;
  assert.equal(ui.$('#line-pending-list').children.length, 2); assert.equal(ui.effects.confirmations.length, 0);
  assert.equal(ui.dialog.dataset.dialogBusy, undefined);
});

test('invalid TTL does not create a code even if form validation is bypassed', async () => {
  const ui = harness(); await ui.openBinding();
  for (const value of ['0', '31', '1.5', 'NaN']) { ui.field('line-binding-code-form', 'ttl_days').value = value; await ui.form('line-binding-code-form').dispatch('submit'); }
  assert.equal(ui.requests.length, 2); assert.match(ui.$('#line-platform-error').textContent, /1–30/);
});

test('confirmed replacement clears old code before the POST and fences an earlier status read', async () => {
  const ui = harness(); await ui.openBinding(detail(1, { pending_codes: [pending()] }));
  const refresh = ui.controller.refreshDialog(true), oldRead = ui.requests.at(-1);
  const work = ui.form('line-binding-code-form').dispatch('submit'); await settle();
  const issue = ui.requests.at(-1); assert.equal(issue.options.body.replace_pending, true);
  assert.ok(!ui.$('#line-pending-list').textContent.includes(codeA)); assert.equal(ui.button('คัดลอกรหัส'), undefined);
  issue.resolve({ detail: detail(1, { pending_codes: [pending(codeB)] }) }); await work;
  oldRead.resolve(detail(1, { pending_codes: [pending()] })); await refresh;
  await ui.button('คัดลอกรหัส').dispatch('click'); assert.deepEqual(ui.effects.copied, [codeB]);
});

test('individual revoke targets only its binding and keeps the other account', async () => {
  const ui = harness(); await ui.openBinding(detail(1, { bound_count: 2, bound_accounts: [{ id: 0, oa_name: 'เดิม', line_user_id_hint: 'U***1111' }, { id: 24, oa_name: 'ใหม่', line_user_id_hint: 'U***2222' }] }));
  const work = ui.button('ยกเลิกบัญชีนี้').dispatch('click'); await settle();
  assert.equal(ui.requests.at(-1).url, '/api/admin/line/residents/1/accounts/0'); assert.equal(ui.requests.at(-1).options.method, 'DELETE');
  ui.requests.at(-1).resolve(detail(1, { bound_count: 1, bound_accounts: [{ id: 24, oa_name: 'ใหม่', line_user_id_hint: 'U***2222' }] })); await work;
  assert.equal(ui.$('#line-account-list').children.length, 1); assert.match(ui.$('#line-account-list').textContent, /2222/);
});

test('blocking requires a reason and confirmation; cancellation leaves all bindings intact', async () => {
  const ui = harness(); await ui.openBinding();
  await ui.$('#line-binding-block').dispatch('click'); assert.equal(ui.requests.length, 2); assert.match(ui.$('#line-platform-error').textContent, /เหตุผล/);
  ui.$('#line-block-reason').value = 'ผู้พักแจ้งอุปกรณ์หาย'; ui.setConfirm(false); await ui.$('#line-binding-block').dispatch('click'); assert.equal(ui.requests.length, 2);
  ui.setConfirm(true); const work = ui.$('#line-binding-block').dispatch('click'); await settle();
  assert.equal(ui.requests.at(-1).url, '/api/admin/line/residents/1/block'); assert.equal(ui.requests.at(-1).options.body.reason, 'ผู้พักแจ้งอุปกรณ์หาย');
  ui.requests.at(-1).resolve(detail(1, { blocked: true, reason: 'ผู้พักแจ้งอุปกรณ์หาย' })); await work;
  assert.equal(ui.form('line-binding-code-form').hidden, true); assert.equal(ui.$('#line-binding-unblock').hidden, false);
});

test('expired codes clear QR, input and links without waiting for another server response', async () => {
  const ui = harness(); const code = pending(); code.expires_at = new Date(Date.now() + 1000).toISOString();
  await ui.openBinding(detail(1, { pending_codes: [code] }));
  const input = ui.$('#line-pending-list').all().find((node) => node.tagName === 'INPUT'), link = ui.$('#line-pending-list').all().find((node) => node.tagName === 'A');
  assert.equal(input.value, codeA); ui.advance(2000); ui.tick(1000);
  assert.equal(input.value, ''); assert.equal(link.href, undefined); assert.match(ui.$('#line-pending-list').textContent, /หมดอายุ/);
});

test('late QR success after dialog close cannot reveal the code again', async () => {
  const ui = harness(), draw = deferred(); ui.helpers.renderQrCanvas = () => draw.promise;
  // A second independent controller uses the deferred QR implementation.
  const fresh = ui.window.DormLinePlatform.init(ui.helpers);
  const work = fresh.openBinding(1); ui.requests[0].resolve(detail(1, { pending_codes: [pending()] })); ui.requests[1].resolve(readyOas); await work; await settle();
  const canvas = ui.$('#line-pending-list').all().find((node) => node.tagName === 'CANVAS');
  ui.dialog.close(); draw.resolve(); await settle();
  assert.equal(canvas.hidden, true); assert.equal(ui.$('#line-pending-list').children.length, 0);
});

test('recipient status refresh preserves draft label and category mutes while recognizing a claim', async () => {
  const ui = harness(), row = { id: 9, oa_id: 0, oa_name: 'เดิม', label: 'ผู้ดูแล', enabled: true, is_owner: false, status: 'pending', muted_categories: [], code: 'ADMIN-' + 'A'.repeat(32), expires_at: new Date(Date.now() + 60000).toISOString() }; row.line_message_url = message(row.code);
  const work = ui.controller.openRecipient(9); ui.requests[0].resolve(row); ui.requests[1].resolve(readyOas); await work;
  ui.field('line-recipient-form', 'label').value = 'ชื่อร่าง'; ui.$$('[name="muted_categories"]', ui.form('line-recipient-form'))[0].checked = true;
  const refresh = ui.controller.refreshDialog(true); ui.requests.at(-1).resolve({ ...row, status: 'claimed', code: undefined, line_user_id_hint: 'U***1234' }); await refresh;
  assert.equal(ui.field('line-recipient-form', 'label').value, 'ชื่อร่าง'); assert.equal(ui.$$('[name="muted_categories"]', ui.form('line-recipient-form'))[0].checked, true);
  assert.equal(ui.$('#line-recipient-code').children.length, 0); assert.ok(ui.effects.toasts.includes('ยืนยันบัญชีผู้รับแจ้งเตือนแล้ว'));
});

test('OA save blocks switching modal and clears secret fields before a post-save list read', async () => {
  const ui = harness(); const opening = ui.controller.openOa();
  assert.equal(ui.requests[0].url, '/api/admin/line/oas/0');
  ui.requests[0].resolve({ id: 0, enabled: true }); await opening; ui.field('line-oa-form', 'channel_access_token').value = 'fake-test-token';
  const work = ui.form('line-oa-form').dispatch('submit'); assert.equal(ui.dialog.dataset.dialogBusy, 'true');
  await ui.controller.openBinding(2); assert.equal(ui.requests.length, 2);
  assert.equal(ui.requests[1].url, '/api/admin/line/oas/0');
  assert.equal(ui.requests[1].options.method, 'PUT');
  ui.requests[1].resolve({ id: 0 }); await work;
  assert.equal(ui.dialog.open, true); assert.equal(ui.field('line-oa-form', 'channel_access_token').value, ''); assert.equal(ui.dialog.dataset.dialogBusy, undefined);
  assert.ok(ui.requests.some((item) => item.url === '/api/admin/line/oas/0/webhook-status'));
  assert.equal(ui.requests.filter((item) => item.url === '/api/admin/line/oas').length, 1);
});

test('polling reads only visible open detail dialogs and close clears timers', async () => {
  const ui = harness(); await ui.openBinding(); ui.document.visibilityState = 'hidden'; ui.tick(5000); assert.equal(ui.requests.length, 2);
  ui.document.visibilityState = 'visible'; ui.tick(5000); assert.equal(ui.requests.length, 3); ui.requests[2].resolve(detail()); await settle();
  ui.dialog.close(); ui.tick(5000); assert.equal(ui.requests.length, 3); assert.equal(ui.timers.size, 0);
});

test('first connection reuses unconfigured legacy OA and submits only necessary credentials with blank metadata', async () => {
  const ui = harness();
  const load = ui.controller.loadOas();
  ui.requests[0].resolve({ rows: [{ id: 0, enabled: true, credentials_ready: false }], default_oa_id: 0 });
  ui.requests[1].resolve({ rows: [] }); await load;
  const open = ui.$('#line-oa-configure').dispatch('click');
  assert.equal(ui.requests.at(-1).url, '/api/admin/line/oas/0');
  ui.requests.at(-1).resolve({ id: 0, enabled: true, name: 'LINE เดิมของหอพัก', slug: 'legacy' });
  await open; await settle();
  ui.field('line-oa-form', 'channel_access_token').value = 'test-token';
  ui.field('line-oa-form', 'channel_secret').value = 'test-secret';
  const save = ui.form('line-oa-form').dispatch('submit');
  const request = ui.requests.at(-1);
  assert.equal(request.url, '/api/admin/line/oas/0');
  assert.equal(request.options.method, 'PUT');
  assert.deepEqual(Object.keys(request.options.body).sort(), ['channel_access_token','channel_access_token_clear','channel_secret','channel_secret_clear','enabled'].sort());
  request.resolve({ id: 0 }); await save;
  assert.equal(ui.field('line-oa-form', 'channel_secret').value, '');
  const statusRead = ui.requests.find((item) => item.url.endsWith('/webhook-status'));
  statusRead.resolve({ id: 0, name: 'Test OA', credentials_ready: true, identity_verified: true, webhook_verified: false });
  await settle();
  assert.match(ui.$('#line-webhook-detail').textContent, /รอ Verify Webhook/);
  const refresh = ui.controller.refreshDialog(true);
  ui.requests.at(-1).resolve({ id: 0, name: 'Test OA', credentials_ready: true, identity_verified: true, webhook_verified: true, operational_ready: true });
  await refresh;
  assert.ok(ui.effects.toasts.includes('Verify Webhook สำเร็จแล้ว'));
});

// ตรวจปุ่มจาก HTML จริง ไม่ให้ DOM จำลองสร้างปุ่มที่หน้าเว็บไม่มีขึ้นมาเอง
const adminTemplate = fs.readFileSync(path.join(__dirname, '../templates/admin/console.php'), 'utf8');
test('every directly wired LINE button/input exists in the real admin template', () => {
  const ids = new Set([...adminTemplate.matchAll(/\bid="([A-Za-z0-9_-]+)"/g)].map((match) => match[1]));
  const wiredIds = [...source.matchAll(/\$\('#([A-Za-z0-9_-]+)'\)\.addEventListener/g)].map((match) => match[1]);
  assert.ok(wiredIds.length > 0);
  for (const id of wiredIds) assert.ok(ids.has(id), `Missing real admin element: #${id}`);
});
test('single-bot settings reject creating or switching to another OA', async () => {
  const ui = harness();
  for (const id of [null, 1, 2, -1]) await ui.controller.openOa(id);
  assert.equal(ui.requests.length, 0);
  assert.equal(ui.effects.toasts.length, 4);
});
test('binding picker keeps only the primary bot even when old OA data is returned', async () => {
  const ui = harness(); await ui.openBinding();
  assert.equal(ui.field('line-binding-code-form', 'oa_id'), undefined);
  assert.match(ui.$('#line-binding-bot').textContent, /เดิม/);
  assert.ok(!ui.$('#line-binding-bot').textContent.includes('OA ใหม่'));
});
test('new connection requires both secrets but configured blank fields preserve existing values', async () => {
  const ui=harness();let opening=ui.controller.openOa();ui.requests.at(-1).resolve({id:0,enabled:true});await opening;
  assert.equal(ui.field('line-oa-form','channel_access_token').required,true);assert.equal(ui.field('line-oa-form','channel_secret').required,true);
  opening=ui.controller.openOa();ui.requests.at(-1).resolve({id:0,enabled:true,channel_access_token_configured:true,channel_secret_configured:true});await opening;
  assert.equal(ui.field('line-oa-form','channel_access_token').required,false);assert.equal(ui.field('line-oa-form','channel_secret').required,false);
});
test('explicit credential clear pauses the bot and cancellation submits nothing', async () => {
  const ui=harness(),opening=ui.controller.openOa();ui.requests.at(-1).resolve({id:0,enabled:true,channel_access_token_configured:true,channel_secret_configured:true});await opening;
  ui.field('line-oa-form','channel_secret_clear').checked=true;ui.setConfirm(false);await ui.form('line-oa-form').dispatch('submit');assert.equal(ui.requests.length,1);
  ui.setConfirm(true);const saving=ui.form('line-oa-form').dispatch('submit');await settle();const request=ui.requests.at(-1);
  assert.equal(request.options.body.enabled,false);assert.equal(request.options.body.channel_secret_clear,true);request.resolve({id:0});await saving;
});
test('a queued binding submit cannot act on a different dialog mode', async () => {
  const ui=harness();await ui.openBinding();const opening=ui.controller.openOa();ui.requests.at(-1).resolve({id:0,enabled:true});await opening;
  const count=ui.requests.length;await ui.form('line-binding-code-form').dispatch('submit');assert.equal(ui.requests.length,count);
});

test('unsettled LINE read reaches a hard deadline and offers a usable retry', async () => {
 const ui=harness(),work=ui.controller.openOa();ui.tick(12000);await work;
 assert.equal(ui.requests[0].options.signal.aborted,true);
 assert.notEqual(ui.$('#line-platform-summary').textContent,'กำลังโหลด…');
 assert.equal(ui.$('#line-platform-retry').hidden,false);assert.equal(ui.$('#line-platform-feedback').dataset.state,'error');
 const retry=ui.$('#line-platform-retry').dispatch('click');ui.requests.at(-1).resolve({id:0,enabled:true});await retry;
 assert.equal(ui.form('line-oa-form').hidden,false);assert.equal(ui.$('#line-platform-error').hidden,true);
});
test('save timeout restores controls, retains keys and ignores a late success', async () => {
 const ui=harness(),open=ui.controller.openOa();ui.requests[0].resolve({id:0,enabled:true});await open;
 ui.field('line-oa-form','channel_access_token').value='fixture-secret-token';ui.field('line-oa-form','channel_secret').value='fixture-secret';
 const save=ui.form('line-oa-form').dispatch('submit');const request=ui.requests.at(-1);ui.tick(30000);await save;
 assert.notEqual(ui.dialog.dataset.dialogBusy,'true');assert.equal(ui.field('line-oa-form','channel_access_token').disabled,false);
 assert.equal(ui.field('line-oa-form','channel_access_token').value,'fixture-secret-token');assert.equal(ui.$('#line-platform-retry').textContent,'ตรวจค่าที่บันทึก');
 const count=ui.requests.length;request.resolve({id:0});await settle();assert.equal(ui.requests.length,count);assert.equal(ui.form('line-oa-form').hidden,false);
});
test('successful save shows returned account even when the following status read fails', async () => {
 const ui=harness(),open=ui.controller.openOa();ui.requests[0].resolve({id:0,enabled:true});await open;
 const save=ui.form('line-oa-form').dispatch('submit');ui.requests.at(-1).resolve({id:0,name:'Saved bot',enabled:true,credentials_ready:true,identity_verified:true,webhook_verified:false});await save;
 assert.equal(ui.$('#line-webhook-detail').hidden,false);assert.match(ui.$('#line-platform-summary').textContent,/Saved bot/);
 const status=ui.requests.find(r=>r.url.endsWith('/webhook-status'));status.reject(new Error('Status offline'));await settle();
 assert.equal(ui.$('#line-webhook-detail').hidden,false);assert.equal(ui.$('#line-platform-feedback').dataset.state,'error');
 assert.notEqual(ui.$('#line-platform-summary').textContent,'กำลังโหลด…');
});
test('recipient load failure cannot prevent a successfully loaded bot from displaying', async () => {
 const ui=harness(),load=ui.controller.loadOas();ui.requests[0].resolve(readyOas);await settle();
 assert.match(ui.$('#line-oa-list').textContent,/เดิม/);ui.requests[1].reject(new Error('Recipients offline'));await load;
 assert.match(ui.$('#line-oa-list').textContent,/เดิม/);assert.match(ui.$('#line-recipient-list').textContent,/ไม่สำเร็จ/);
});
test('poll failure is visible and the next successful read clears the stale failure', async () => {
 const ui=harness();await ui.openBinding();let refresh=ui.controller.refreshDialog(true);ui.requests.at(-1).reject(new Error('poll offline'));await refresh;
 assert.equal(ui.$('#line-platform-feedback').dataset.state,'error');assert.equal(ui.$('#line-platform-error').hidden,false);
 refresh=ui.controller.refreshDialog(true);ui.requests.at(-1).resolve(detail());await refresh;assert.equal(ui.$('#line-platform-error').hidden,true);
});
test('live check reports disabled webhook as attention instead of claiming a connection success', async () => {
 const ui=harness(),open=ui.controller.openOa();ui.requests[0].resolve({id:0,enabled:true});await open;
 const save=ui.form('line-oa-form').dispatch('submit');ui.requests.at(-1).resolve({id:0,enabled:true});await save;
 const row={id:0,enabled:true,credentials_ready:true,identity_verified:true,webhook_verified:true,updated_at:'test-revision'};
 ui.requests.find(r=>r.url.endsWith('/webhook-status')).resolve(row);await settle();
 const check=ui.$('#line-connection-test').dispatch('click'),request=ui.requests.at(-1);
 assert.equal(request.url,'/api/admin/line/oas/0/test');assert.equal(request.options.method,'POST');
 request.resolve({account:row,ready:false,connection:{ready:false,status:'webhook_disabled',message:'กรุณาเปิด Use webhook',checked_at:new Date().toISOString()}});await check;
 assert.equal(ui.$('#line-platform-feedback').dataset.state,'attention');assert.match(ui.$('#line-platform-feedback').textContent,/Use webhook/);
 assert.notEqual(ui.dialog.dataset.dialogBusy,'true');
});
test('a queued native close event cannot erase the newly opened LINE status view', async () => {
 const ui=harness(),open=ui.controller.openOa();ui.requests[0].resolve({id:0,enabled:true});await open;
 const save=ui.form('line-oa-form').dispatch('submit');ui.requests.at(-1).resolve({id:0,name:'Saved bot',enabled:true});await save;
 await ui.dialog.dispatch('close');
 assert.equal(ui.dialog.open,true);assert.equal(ui.$('#line-webhook-detail').hidden,false);
 const request=ui.requests.find(r=>r.url.endsWith('/webhook-status'));request.resolve({id:0,name:'Latest bot',enabled:true,webhook_verified:false});await settle();
 assert.match(ui.$('#line-platform-summary').textContent,/Latest bot/);assert.equal(ui.$('#line-connection-test').hidden,false);
});
test('saving LINE changes mode in the existing dialog without a close/reopen cycle', async () => {
 const ui=harness(),open=ui.controller.openOa();ui.requests[0].resolve({id:0,enabled:true});await open;
 let closeCount=0;const close=ui.dialog.close.bind(ui.dialog);ui.dialog.close=()=>{closeCount++;close();};
 const save=ui.form('line-oa-form').dispatch('submit');ui.requests.at(-1).resolve({id:0,enabled:true});await save;
 assert.equal(closeCount,0);assert.equal(ui.dialog.open,true);assert.equal(ui.form('line-oa-form').hidden,true);
});

async function openStatusFixture(ui, row) {
 const opening=ui.controller.openOa();ui.requests.at(-1).resolve(row);await opening;
 const save=ui.form('line-oa-form').dispatch('submit');ui.requests.at(-1).resolve(row);await save;
 ui.requests.find(r=>r.url.endsWith('/webhook-status')).resolve(row);await settle();
}
test('a rejected current token is not labelled as a current verified token',async()=>{
 const ui=harness(),row={id:0,enabled:true,credentials_ready:true,identity_verified:true,webhook_verified:true,updated_at:'revision-1'};
 await openStatusFixture(ui,row);const work=ui.$('#line-connection-test').dispatch('click');
 ui.requests.at(-1).reject(Object.assign(new Error('LINE ปฏิเสธ Token'),{details:{code:'LINE_TOKEN_REJECTED'}}));await work;
 assert.match(ui.$('#line-webhook-detail').textContent,/Token: การตรวจล่าสุดถูก LINE ปฏิเสธ/);
 const poll=ui.controller.refreshDialog(true);ui.requests.at(-1).resolve(row);await poll;
 assert.match(ui.$('#line-platform-feedback').textContent,/ปฏิเสธ Token/);
});
test('callback failure after a successful live check removes the success status',async()=>{
 const ui=harness(),row={id:0,enabled:true,credentials_ready:true,identity_verified:true,webhook_verified:true,updated_at:'revision-1'};
 await openStatusFixture(ui,row);const work=ui.$('#line-connection-test').dispatch('click');
 ui.requests.at(-1).resolve({account:row,ready:true,connection:{ready:true,status:'ready',message:'ตรวจการเชื่อมต่อผ่านแล้ว',checked_at:new Date().toISOString()}});await work;
 assert.equal(ui.$('#line-platform-feedback').dataset.state,'ready');
 const poll=ui.controller.refreshDialog(true);ui.requests.at(-1).resolve({...row,last_error:'LINE_DESTINATION_MISMATCH',webhook_verified:false});await poll;
 assert.equal(ui.$('#line-platform-feedback').dataset.state,'attention');assert.match(ui.$('#line-platform-feedback').textContent,/Webhook ล่าสุดมีข้อผิดพลาด/);
});
test('expired live checks are not renewed merely by reading saved configuration',async()=>{
 const ui=harness(),row={id:0,enabled:true,credentials_ready:true,identity_verified:true,webhook_verified:true,updated_at:'revision-1'};
 await openStatusFixture(ui,row);const work=ui.$('#line-connection-test').dispatch('click');
 ui.requests.at(-1).resolve({account:row,ready:true,connection:{ready:true,status:'ready',message:'ตรวจผ่าน',checked_at:new Date().toISOString()}});await work;
 ui.advance(61000);const poll=ui.controller.refreshDialog(true);ui.requests.at(-1).resolve(row);await poll;
 assert.equal(ui.$('#line-platform-feedback').dataset.state,'attention');assert.match(ui.$('#line-platform-feedback').textContent,/ยืนยันสถานะ LINE ล่าสุด/);
});
test('failed binding reload clears old people and cannot be revived through a filter',async()=>{
 const ui=harness();let work=ui.controller.loadBindings();ui.requests.at(-1).resolve({rows:[{...detail(5),line_binding_status:'unbound'}],counts:{total:1,unbound:1}});await work;
 assert.match(ui.$('#line-binding-rows').textContent,/ผู้พัก 5/);
 work=ui.controller.loadBindings();assert.ok(!ui.$('#line-binding-rows').textContent.includes('ผู้พัก 5'));
 ui.requests.at(-1).reject(new Error('connection lost'));await work;
 await ui.$('#line-binding-search').dispatch('input');assert.match(ui.$('#line-binding-rows').textContent,/โหลดการผูก LINE ไม่สำเร็จ/);
 assert.ok(!ui.$('#line-binding-rows').textContent.includes('ผู้พัก 5'));
});
test('late old binding data does not erase the latest request failure',async()=>{
 const ui=harness(),first=ui.controller.loadBindings(),old=ui.requests.at(-1),second=ui.controller.loadBindings();
 ui.requests.at(-1).reject(new Error('newest request failed'));await second;
 old.resolve({rows:[{...detail(9),line_binding_status:'unbound'}],counts:{total:1}});await first;
 assert.match(ui.$('#line-bindings-error').textContent,/newest request failed/);assert.ok(!ui.$('#line-binding-rows').textContent.includes('ผู้พัก 9'));
});
test('malformed binding responses never become a successful empty list',async()=>{
 const ui=harness(),work=ui.controller.loadBindings();ui.requests.at(-1).resolve({unexpected:true});await work;
 assert.equal(ui.$('#line-bindings-error').hidden,false);assert.match(ui.$('#line-binding-summary').textContent,/ไม่สำเร็จ/);
});
