'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
const loaderStart = source.indexOf('async function loadMeters()');
const helperStart = source.indexOf('function setMeterTableLoading(');
const end = source.indexOf('loaders.meters = loadMeters;', loaderStart);
assert.ok(loaderStart >= 0 && end > loaderStart, 'production meter loader must be available');
const loaderSource = source.slice(helperStart >= 0 ? helperStart : loaderStart, end);

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

function node(tagName, value = '', disabled = false) {
  const attributes = new Map();
  return {
    tagName, value, disabled, dataset: {},
    setAttribute: (key, value) => attributes.set(key, String(value)),
    getAttribute: (key) => attributes.get(key) ?? null,
    hasAttribute: (key) => attributes.has(key),
    removeAttribute: (key) => attributes.delete(key),
    toggleAttribute: (key, enabled) => enabled ? attributes.set(key, '') : attributes.delete(key),
  };
}

function harness() {
  const period = { value: '2026-08' };
  const status = { dataset: {} };
  const requests = [];
  const rows = { ...node('TBODY'), controls: [], replaceChildren() { this.controls = []; } };
  const state = {
    meterPeriod: '2026-08', meterController: null, loaded: new Set(),
    meters: [{ water_current: '100.00', electric_current: '200.00', electric_locked: true }],
  };
  const renderMeters = () => {
    rows.controls = state.meters.flatMap((meter) => [
      node('INPUT', meter.water_current, meter.water_locked === true),
      node('INPUT', meter.electric_current, meter.electric_locked === true),
      node('BUTTON'),
    ]);
    status.dataset.state = state.meters.length ? 'ready' : 'empty';
  };
  renderMeters();
  const context = {
    AbortController, state, rememberMeterDrafts: () => {}, setStat: () => {}, toast: () => {},
    $: (selector) => ({ '#meter-period': period, '#meter-state': status, '#meter-rows': rows })[selector],
    $$: (_selector, root) => root.controls,
    todayPeriod: () => '2026-08',
    setTableState: (element, value) => { element.dataset.state = value; },
    renderMeters,
    listFrom: (value, key) => value[key],
    errorMessage: (error) => error.message,
    api: (url, options) => {
      const request = { ...deferred(), url, signal: options.signal };
      requests.push(request);
      // A server may still finish a request after cancellation. Tests control
      // completion order explicitly rather than assuming abort always wins.
      return request.promise;
    },
  };
  vm.createContext(context);
  vm.runInContext(loaderSource, context);
  return {
    state, rows, period, status, requests,
    load: context.loadMeters,
    typeWater(value) {
      const input = rows.controls[0];
      if (input && !input.disabled && !rows.hasAttribute('inert')) input.value = value;
    },
  };
}

test('pending meter reload prevents edits to the old visible values', async () => {
  const ui = harness();
  const work = ui.load();
  ui.typeWater('125.00');
  assert.equal(ui.rows.controls[0].value, '100.00', 'typing cannot create a draft that the response will erase');
  assert.equal(ui.rows.controls[0].disabled, true);
  assert.equal(ui.rows.controls[2].disabled, true);
  ui.requests[0].resolve({ meters: [{ water_current: '110.00', electric_current: '200.00', electric_locked: true }] });
  await work;
  assert.equal(ui.rows.controls[0].value, '110.00');
  assert.equal(ui.rows.controls[0].disabled, false);
  assert.equal(ui.rows.controls[1].disabled, true, 'billing locks remain enforced');
  assert.equal(ui.rows.controls[2].disabled, false);
  assert.equal(ui.rows.hasAttribute('inert'), false);
});

test('failed reload clears stale controls and disables writes until a successful retry', async () => {
  const ui=harness(),work=ui.load();ui.requests[0].reject(new Error('Temporary failure'));await work;
  assert.equal(ui.status.dataset.state,'error');assert.equal(ui.rows.controls.length,0);assert.equal(ui.state.meterListReady,false);
  const retry=ui.load();ui.requests[1].resolve({meters:[{water_current:'101.00',electric_current:'201.00'}]});await retry;
  assert.equal(ui.state.meterListReady,true);assert.equal(ui.rows.controls[0].value,'101.00');
});

test('an older failed request cannot unlock or overwrite the loading state of its replacement', async () => {
  const ui = harness();
  const oldWork = ui.load();
  const latestWork = ui.load();
  assert.equal(ui.requests[0].signal.aborted, true);
  ui.requests[0].reject(new Error('Old request failed late'));
  await oldWork;
  assert.equal(ui.status.dataset.state, 'loading');
  assert.equal(ui.rows.controls[0].disabled, true);
  assert.equal(ui.rows.controls[2].disabled, true);
  ui.requests[1].reject(new Error('Latest request failed'));
  await latestWork;
  assert.equal(ui.rows.controls.length, 0, 'a failed newest read cannot expose old writable inputs');
  assert.equal(ui.state.meterListReady, false);
});

test('switching month removes old inputs even when the new month fails to load', async () => {
  const ui = harness();
  ui.period.value = '2026-09';
  const work = ui.load();
  assert.equal(ui.rows.controls.length, 0, 'August values must not appear below a September selector');
  ui.requests[0].reject(new Error('September unavailable'));
  await work;
  assert.equal(ui.rows.controls.length, 0);
  assert.equal(ui.status.dataset.state, 'error');
});

test('a late response from an old month never replaces the new month', async () => {
  const ui = harness();
  const oldWork = ui.load();
  ui.period.value = '2026-09';
  const nextWork = ui.load();
  ui.requests[1].resolve({ meters: [{ water_current: '150.00', electric_current: '250.00' }] });
  await nextWork;
  ui.requests[0].resolve({ meters: [{ water_current: '100.00', electric_current: '200.00' }] });
  await oldWork;
  assert.equal(ui.state.meterPeriod, '2026-09');
  assert.equal(ui.rows.controls[0].value, '150.00');
  assert.equal(ui.rows.controls[0].disabled, false);
});

test('a meter save completing during a failed reload does not leave its button stuck disabled', async () => {
  const ui = harness();
  const button = ui.rows.controls[2];
  button.disabled = true;
  button.setAttribute('aria-busy', 'true');
  const work = ui.load();
  button.disabled = false;
  button.removeAttribute('aria-busy');
  assert.equal(ui.rows.hasAttribute('inert'), true, 'save cleanup cannot allow interaction with the loading table');
  ui.requests[0].reject(new Error('Reload failed'));
  await work;
  assert.equal(button.disabled, false);
});

test('a save that is still pending remains disabled when a meter reload fails', async () => {
  const ui = harness();
  const button = ui.rows.controls[2];
  button.disabled = true;
  button.setAttribute('aria-busy', 'true');
  const work = ui.load();
  ui.requests[0].reject(new Error('Reload failed'));
  await work;
  assert.equal(button.disabled, true);
  assert.equal(ui.rows.controls.length, 0);
});

test('a queued save event cannot submit the old table while its reload is pending', async () => {
  const start = source.indexOf('async function saveMeterRow(');
  const end = source.indexOf("$('#meter-rows').addEventListener('click'", start);
  assert.ok(start >= 0 && end > start);
  const messages = [];
  const context = {
    state: { meterController: new AbortController() },
    toast: (message) => messages.push(message),
  };
  vm.createContext(context);
  vm.runInContext(source.slice(start, end), context);
  await context.saveMeterRow({ closest() { throw new Error('old inputs must not be read'); } });
  assert.equal(messages.length, 1);
});
