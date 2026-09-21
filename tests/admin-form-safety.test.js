'use strict';
// Offline regression tests for existing form controls; no database or provider access.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
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
test('the unload guard still protects activation data as well as edited dialogs', () => {
  const unload=extract("window.addEventListener('beforeunload'", "integrationSettingsForm?.addEventListener('submit'");
  for(const expression of ['!residentActivationSecret','!hasDirtySettings()','!hasDirtyMeterRows()','!hasDirtyDialogDrafts()'])assert.ok(unload.includes(expression));
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
