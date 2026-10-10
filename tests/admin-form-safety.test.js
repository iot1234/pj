'use strict';
// Offline regression tests for existing form controls; no database or provider access.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
function extract(a,b) {
  const start=source.indexOf(a),end=source.indexOf(b,start);
  assert.ok(start>=0&&end>start,`Missing production code ${a}`);
  return source.slice(start,end);
}
test('draft form setup queries a collection and remains connected to real controls', () => {
  const setup=extract('function setupCommonInteractions()', 'async function loadPublicSupport(');
  assert.ok(setup.includes("$$('form[data-guard-draft]').forEach"));
  assert.ok(setup.includes("requestDialogClose(button.closest('dialog'), button)"));
  assert.ok(setup.includes("dialog.addEventListener('cancel'"));
});
test('closing a guarded form checks the save lock before asking to discard', () => {
  const close=extract('function requestDialogClose(', 'function setupCommonInteractions()');
  assert.ok(close.indexOf('dialogCloseBlocked') < close.indexOf('window.confirm'));
  assert.ok(close.includes("form.dataset.draftDirty === 'true'"));
  assert.ok(close.includes('return false;'));
});
test('the unload guard protects pending bills and edited dialogs without an activation secret', () => {
  const unload=extract("window.addEventListener('beforeunload'", "integrationSettingsForm?.addEventListener('submit'");
  for(const expression of ['!state.billWorking','!state.billDraftEdited','!hasDirtySettings()','!hasDirtyMeterRows()','!hasDirtyDialogDrafts()'])assert.ok(unload.includes(expression));
  assert.ok(!unload.includes('residentActivationSecret'));
});
test('room and administrator saves check the in-flight marker before reading the form', () => {
  for(const selector of ['room-form','user-form']){
    const start=source.indexOf(`$('#${selector}').addEventListener('submit'`);
    const handler=source.slice(start,source.indexOf('\n\n',start));
    assert.ok(handler.indexOf("form.dataset.submitting === 'true'") < handler.indexOf('new FormData(form)'));
    assert.ok(handler.includes('beginDialogSave(form)'));
    assert.ok(handler.includes('finally { finishDialogSave(form); setBusy(button, false); }'));
    assert.ok(handler.indexOf('finishDialogSave(form); form.reset(); closeDialog') > handler.indexOf('await api('));
  }
});
test('room loading fences both late results and failures and clears stale cached rooms', () => {
  const loader=extract('async function loadRooms()', 'function openRoomForm(');
  assert.equal(loader.split('if (state.roomController !== controller) return;').length-1,2);
  assert.ok(loader.includes('state.rooms = []; rows.replaceChildren();'));
  assert.ok(loader.includes("rows.setAttribute('inert', '')"));
  assert.ok(extract('function renderRooms()', 'async function loadRooms()').includes('if (state.roomListReady === false) return;'));
});
test('bill actions are not ready while the room list is stale or refreshing', () => {
  const ready=extract('function billDataReady()', 'function selectedBillRooms()');
  assert.ok(ready.includes('state.billCandidatesReady === true && state.billListAvailable === true && !state.billController'));
});

test('switching room rental mode shows its instructions and preserves values for switching back', () => {
  const elements = Object.fromEntries(['rental_mode', 'monthly_rent', 'daily_rate', 'max_guests', 'daily_deposit'].map(name => [name, { value: '' }]));
  Object.assign(elements.monthly_rent, { value: '3500.00' });
  Object.assign(elements.daily_rate, { value: '650.00' });
  Object.assign(elements.max_guests, { value: '3' });
  Object.assign(elements.daily_deposit, { value: '200.00' });
  const fields = ['monthly', 'daily', 'daily', 'daily'].map(mode => ({ dataset: { rentalFields: mode } })), help = {};
  const context = { $: () => help, $$: () => fields };
  vm.createContext(context);
  vm.runInContext(extract('function syncRoomRentalFields(', "$('#room-form').elements.rental_mode?.addEventListener"), context);
  elements.rental_mode.value = 'daily'; context.syncRoomRentalFields({ elements });
  assert.equal(elements.monthly_rent.disabled, true); assert.equal(elements.monthly_rent.required, false);
  for (const name of ['daily_rate', 'max_guests', 'daily_deposit']) assert.equal(elements[name].disabled, false);
  assert.equal(elements.daily_rate.required, true); assert.equal(elements.max_guests.required, true);
  assert.equal(fields[0].hidden, true); assert.equal(fields[1].hidden, false);
  assert.match(help.textContent, /บันทึกห้องแล้วไปเมนู “จองรายวัน”/);
  elements.rental_mode.value = 'monthly'; context.syncRoomRentalFields({ elements });
  assert.equal(elements.monthly_rent.disabled, false); assert.equal(elements.monthly_rent.required, true);
  assert.equal(elements.daily_rate.required, false); assert.equal(elements.max_guests.required, false);
  for (const name of ['daily_rate', 'max_guests', 'daily_deposit']) assert.equal(elements[name].disabled, true);
  assert.equal(elements.monthly_rent.value, '3500.00'); assert.equal(elements.daily_rate.value, '650.00');
  assert.equal(elements.max_guests.value, '3'); assert.equal(elements.daily_deposit.value, '200.00');
  assert.match(help.textContent, /รายเดือน.*ยังไม่เพิ่มผู้พักหรือสร้างการจอง/);
});
