'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
const publicStart = source.indexOf('function initPublicRooms()');
const section = source.slice(publicStart, source.indexOf('function initLogin(', publicStart));
const pick = (a, b) => section.slice(section.indexOf(a), section.indexOf(b, section.indexOf(a)));
const loader = pick('async function load()', '[search, typeFilter, floorFilter]');
const populate = pick('function populateSelect(', 'function filteredRooms()');
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; }
function option(value = '') { return { value, cloneNode() { return option(this.value); } }; }
function select() { return { value: '', children: [option()], get firstElementChild() { return this.children[0]; }, replaceChildren() { this.children = []; this.value = ''; }, append(node) { this.children.push(node); } }; }
function harness() {
  const requests = [], typeFilter = select(), floorFilter = select();
  const grid = { attributes: {}, children: [], setAttribute(k, v) { this.attributes[k] = v; }, replaceChildren() { this.children = []; }, append(node) { this.children.push(node); } };
  const count = { textContent: '' }, empty = { hidden: true }, errorBox = { hidden: true }, errorText = {};
  const context = { grid, count, empty, errorBox, typeFilter, floorFilter, create: () => option(), $: () => errorText, listFrom: (data) => data.rooms, errorMessage: (error) => error.message,
    api: () => { const pending = deferred(); requests.push(pending); return pending.promise; }, render: () => { grid.setAttribute('aria-busy', 'false'); grid.children = Array.from(vm.runInContext('rooms', context)); } };
  vm.createContext(context);
  vm.runInContext(`let rooms = []; let roomLoadRequest = 0; let roomsReady = false; ${populate}\n${loader}`, context);
  return { ...context, requests, load: context.load, rooms: () => Array.from(vm.runInContext('rooms', context)), errorText };
}
const room = (id, type = 'standard', floor = 1) => ({ id, room_type: type, floor, status: 'available' });
test('a late older response never replaces the newest room availability', async () => {
  const ui = harness(), old = ui.load(), latest = ui.load();
  ui.requests[1].resolve({ rooms: [room(2)] }); await latest;
  ui.requests[0].resolve({ rooms: [room(1)] }); await old;
  assert.deepEqual(ui.rooms().map((item) => item.id), [2]);
});
test('an old failure cannot hide the successful newer room list', async () => {
  const ui = harness(), old = ui.load(), latest = ui.load();
  ui.requests[1].resolve({ rooms: [room(2)] }); await latest;
  ui.requests[0].reject(new Error('Old request failed')); await old;
  assert.equal(ui.errorBox.hidden, true); assert.equal(ui.grid.children.length, 1);
});
test('failed reload clears cached rooms and the empty-state message', async () => {
  const ui = harness(), first = ui.load(); ui.requests[0].resolve({ rooms: [room(1)] }); await first;
  ui.empty.hidden = false; const retry = ui.load(); ui.requests[1].reject(new Error('Offline')); await retry;
  assert.deepEqual(ui.rooms(), []); assert.equal(ui.empty.hidden, true);
  assert.equal(ui.errorBox.hidden, false); assert.equal(ui.errorText.textContent, 'Offline');
});
test('refresh preserves selected type and floor when they are still available', async () => {
  const ui = harness(); ui.typeFilter.value = 'suite'; ui.floorFilter.value = '2';
  const work = ui.load(); ui.requests[0].resolve({ rooms: [room(1, 'suite', 2)] }); await work;
  assert.equal(ui.typeFilter.value, 'suite'); assert.equal(ui.floorFilter.value, '2');
});
test('refresh resets a filter only when its option no longer exists', async () => {
  const ui = harness(); ui.typeFilter.value = 'suite'; ui.floorFilter.value = '3';
  const work = ui.load(); ui.requests[0].resolve({ rooms: [room(1)] }); await work;
  assert.equal(ui.typeFilter.value, ''); assert.equal(ui.floorFilter.value, '');
});
