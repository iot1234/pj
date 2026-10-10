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

function harness({ dirty = true, accept = false, dailyBusy = false } = {}) {
  const effects = { confirmations: [], renders: 0, loads: [], menuChanges: 0 };
  const draft = { water: dirty ? '125.00' : '100.00' };
  const panels = ['meters', 'rooms', 'settings', 'daily', 'bookings', 'residents', 'bills', 'payments'].map((name) => ({
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
    titles: vm.runInNewContext(`(${source.slice(source.indexOf('const titles =', source.indexOf('function initAdminConsole()'))).match(/^const titles = (\{[^\n]+\});/)[1]})`),
    role: 'owner',
    app: { classList: { contains: () => false } },
    $: (selector) => selector === '[data-admin-view].is-active'
      ? panels.find((panel) => panel.active)
      : selector === '#admin-page-title' ? title : null,
    $$: (selector) => selector === '[data-admin-view]' ? panels : [],
    settingsSaveInProgress: false,
    hasDirtySettings: () => false,
    hasDirtyMeterRows: () => draft.water !== '100.00',
    daily: { busy: () => dailyBusy, inFlight: () => dailyBusy },
    toast() {},
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
      daily: () => { effects.loads.push('daily'); },
      bookings: () => { effects.loads.push('bookings'); },
      residents: () => { effects.loads.push('residents'); },
      bills: () => { effects.loads.push('bills'); },
      payments: () => { effects.loads.push('payments'); },
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

test('overview and room shortcuts resolve to real pages and keep navigation safeguards', () => {
  const template = fs.readFileSync(path.join(__dirname, '../templates/admin/console.php'), 'utf8');
  const binding = source.match(/\$\$\('\[data-overview-jump\]'\)\.forEach\([^\n]+/)[0];
  const destinations = [...new Set([...template.matchAll(/data-overview-jump="([a-z-]+)"/g)].map(match => match[1]))];
  assert.ok(destinations.includes('daily') && destinations.includes('residents') && destinations.includes('bookings'));
  for (const name of destinations) {
    const ui = harness({ dirty: false }), button = { dataset: { overviewJump: name }, addEventListener(_event, callback) { this.click = callback; } };
    vm.runInNewContext(binding, { $$: () => [button], switchView: ui.navigate }); button.click();
    assert.equal(ui.panels.find(panel => panel.active).dataset.adminView, name);
    assert.deepEqual(ui.effects.loads, [name]);
  }
  const guarded = harness(), button = { dataset: { overviewJump: 'daily' }, addEventListener(_event, callback) { this.click = callback; } };
  vm.runInNewContext(binding, { $$: () => [button], switchView: guarded.navigate }); button.click();
  assert.equal(guarded.panels.find(panel => panel.active).dataset.adminView, 'meters');
  assert.equal(guarded.draft.water, '125.00'); assert.deepEqual(guarded.effects.loads, []);
  const pending = harness({ dirty: false, dailyBusy: true });
  assert.equal(pending.navigate('rooms'), false); assert.deepEqual(pending.effects.loads, []);
});
