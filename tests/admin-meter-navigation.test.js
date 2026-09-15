'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

// Execute the actual navigation function with a small DOM boundary. No API,
// database, browser, or copied implementation is needed for this regression.
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
const start = source.indexOf('function switchView(name, force = false, updateHash = true)');
const end = source.indexOf('function roomMatches(', start);
assert.ok(start >= 0 && end > start, 'admin navigation function must be available');
const navigationSource = source.slice(start, end);

function harness({ dirty = true, accept = false } = {}) {
  const effects = { confirmations: [], renders: 0, loads: [], menuChanges: 0 };
  const draft = { water: dirty ? '125.00' : '100.00' };
  const panels = ['meters', 'rooms', 'settings'].map((name) => ({
    dataset: { adminView: name },
    hidden: name !== 'meters',
    active: name === 'meters',
    classList: {
      toggle(_className, active) { panels.find((panel) => panel.dataset.adminView === name).active = active; },
    },
  }));
  const title = { textContent: '' };
  const resetMeters = () => { effects.renders += 1; draft.water = '100.00'; };
  const context = {
    titles: { meters: 'จดมิเตอร์', rooms: 'ห้องพัก', settings: 'ตั้งค่า' },
    role: 'owner',
    app: { classList: { contains: () => false } },
    $: (selector) => selector === '[data-admin-view].is-active'
      ? panels.find((panel) => panel.active)
      : selector === '#admin-page-title' ? title : null,
    $$: (selector) => selector === '[data-admin-view]' ? panels : [],
    settingsSaveInProgress: false,
    hasDirtySettings: () => false,
    hasDirtyMeterRows: () => draft.water !== '100.00',
    window: { confirm: (message) => { effects.confirmations.push(message); return accept; } },
    renderMeters: resetMeters,
    mobileMenu: { matches: false },
    homeView: 'overview',
    location: { hash: '#meters' },
    setAdminMenu: () => { effects.menuChanges += 1; },
    state: { loaded: new Set(['meters', 'rooms']) },
    loaders: {
      meters: () => { effects.loads.push('meters'); resetMeters(); },
      rooms: () => { effects.loads.push('rooms'); },
    },
  };
  vm.createContext(context);
  vm.runInContext(`${navigationSource}\nglobalThis.navigate = switchView;`, context);
  return { effects, draft, panels, navigate: context.navigate };
}

test('clicking the active meter menu keeps draft readings when discard is cancelled', () => {
  const ui = harness();
  assert.equal(ui.navigate('meters'), false);
  assert.equal(ui.effects.confirmations.length, 1);
  assert.equal(ui.draft.water, '125.00');
  assert.deepEqual(ui.effects.loads, []);
  assert.equal(ui.effects.renders, 0);
});

test('forcing a refresh of the active meter page also requires discard confirmation', () => {
  const ui = harness();
  assert.equal(ui.navigate('meters', true), false);
  assert.equal(ui.effects.confirmations.length, 1);
  assert.equal(ui.draft.water, '125.00');
  assert.deepEqual(ui.effects.loads, []);
});

test('confirmed refresh discards drafts and fetches the meter list once', () => {
  const ui = harness({ accept: true });
  assert.equal(ui.navigate('meters'), true);
  assert.equal(ui.effects.confirmations.length, 1);
  assert.equal(ui.draft.water, '100.00');
  assert.deepEqual(ui.effects.loads, ['meters']);
});

test('clean meter pages can still refresh without a confirmation', () => {
  const ui = harness({ dirty: false });
  assert.equal(ui.navigate('meters'), true);
  assert.equal(ui.effects.confirmations.length, 0);
  assert.deepEqual(ui.effects.loads, ['meters']);
});

test('cancelling navigation to a different page preserves the active view and drafts', () => {
  const ui = harness();
  assert.equal(ui.navigate('rooms'), false);
  assert.equal(ui.panels.find((panel) => panel.active).dataset.adminView, 'meters');
  assert.equal(ui.draft.water, '125.00');
  assert.deepEqual(ui.effects.loads, []);
});

test('confirmed navigation discards drafts and loads the requested page', () => {
  const ui = harness({ accept: true });
  assert.equal(ui.navigate('rooms'), true);
  assert.equal(ui.panels.find((panel) => panel.active).dataset.adminView, 'rooms');
  assert.equal(ui.draft.water, '100.00');
  assert.deepEqual(ui.effects.loads, ['rooms']);
});
