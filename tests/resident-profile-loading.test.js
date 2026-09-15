'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
const start = source.indexOf('function fillProfile()'), end = source.indexOf('async function refreshVerifyingBills(', start);
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
function harness() {
  const nodes = new Map(), requests = [];
  const $ = selector => { if (!nodes.has(selector)) nodes.set(selector, { disabled: false, hidden: false, textContent: '', setAttribute() {} }); return nodes.get(selector); };
  $('#resident-profile-fields').disabled = true;
  const profileForm = { dataset: {}, elements: Object.fromEntries(['full_name', 'email', 'phone', 'room_code'].map(name => [name, { value: '' }])) };
  const context = { $, profileForm, state: { profile: {}, bills: [], loadRequest: 0, profileRevision: 0 }, api: (url) => { const item = { url, ...deferred() }; requests.push(item); return item.promise; }, objectFrom: value => value, listFrom: value => value, renderLineStatus() {}, renderBills() {}, errorMessage: error => error.message };
  vm.createContext(context); vm.runInContext(source.slice(start, end), context);
  return { $, profileForm, requests, context };
}
const profile = { full_name: 'ชื่อที่บันทึก', email: 'saved@example.test', phone: '0812345678', room_code: 'A101' };

test('initial profile fields stay disabled until populated, including a failed load and retry', async () => {
  const ui = harness(), first = ui.context.loadAll();
  assert.equal(ui.$('#resident-profile-fields').disabled, true);
  ui.requests[0].reject(new Error('Offline')); ui.requests[1].resolve([]); await first;
  assert.equal(ui.$('#resident-profile-fields').disabled, true); assert.match(ui.$('#resident-profile-load-state').textContent, /ลองอีกครั้ง/);
  const retry = ui.context.loadAll(); ui.requests[2].resolve(profile); ui.requests[3].resolve([]); await retry;
  assert.equal(ui.profileForm.elements.full_name.value, profile.full_name); assert.equal(ui.$('#resident-profile-fields').disabled, false); assert.equal(ui.$('#resident-profile-load-state').hidden, true);
});

test('a later retry preserves edited name and email while refreshing readonly profile fields', async () => {
  const ui = harness(), initial = ui.context.loadAll(); ui.requests[0].resolve(profile); ui.requests[1].resolve([]); await initial;
  ui.profileForm.dataset.dirty = 'true'; ui.profileForm.elements.full_name.value = 'ชื่อร่าง'; ui.profileForm.elements.email.value = 'draft@example.test';
  const refresh = ui.context.loadAll(); ui.requests[2].resolve({ ...profile, room_code: 'A102' }); ui.requests[3].resolve([]); await refresh;
  assert.equal(ui.profileForm.elements.full_name.value, 'ชื่อร่าง'); assert.equal(ui.profileForm.elements.email.value, 'draft@example.test'); assert.equal(ui.profileForm.elements.room_code.value, 'A102');
});

test('an obsolete initial response cannot overwrite a newer populated profile', async () => {
  const ui = harness(), old = ui.context.loadAll(), current = ui.context.loadAll();
  ui.requests[2].resolve(profile); ui.requests[3].resolve([]); await current;
  ui.profileForm.elements.full_name.value = 'ข้อความหลังโหลด'; ui.profileForm.dataset.dirty = 'true';
  ui.requests[0].resolve({ ...profile, full_name: 'ข้อมูลเก่า' }); ui.requests[1].resolve([]); await old;
  assert.equal(ui.profileForm.elements.full_name.value, 'ข้อความหลังโหลด'); assert.equal(ui.$('#resident-profile-fields').disabled, false);
});
