const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const runtimeSource = readFileSync(
  path.join(__dirname, '..', 'public/assets/js/modules/Core/pwa-runtime.js'),
  'utf8',
);

function button() {
  const listeners = new Map();

  return {
    disabled: true,
    addEventListener(type, callback) {
      listeners.set(type, callback);
    },
    click() {
      if (!this.disabled) {
        listeners.get('click')?.();
      }
    },
  };
}

function navigationHarness({ canGoBack, canGoForward, iosStandalone = false, standalone = false } = {}) {
  const backButton = button();
  const forwardButton = button();
  const pageReloadButton = button();
  pageReloadButton.disabled = false;
  const rootClasses = new Set();
  const mediaListeners = new Map();
  const navigationListeners = new Map();
  const toolbar = { hidden: true };
  const state = {
    backs: 0,
    forwards: 0,
    reloads: 0,
  };
  const displayModeQuery = {
    matches: standalone,
    addEventListener(type, callback) {
      mediaListeners.set(type, callback);
    },
  };
  const navigation = typeof canGoBack === 'boolean' && typeof canGoForward === 'boolean'
    ? {
        canGoBack,
        canGoForward,
        addEventListener(type, callback) {
          navigationListeners.set(type, callback);
        },
      }
    : undefined;
  const window = {
    AppPwaRuntime: {},
    history: {
      back() {
        state.backs += 1;
      },
      forward() {
        state.forwards += 1;
      },
    },
    location: {
      href: 'https://erp.test/dashboard',
      reload() {
        state.reloads += 1;
      },
    },
    matchMedia() {
      return displayModeQuery;
    },
    navigation,
    navigator: { standalone: iosStandalone },
    addEventListener() {},
  };
  const elements = new Map([
    ['[data-erp-pwa-navigation]', toolbar],
    ['[data-erp-pwa-back]', backButton],
    ['[data-erp-pwa-forward]', forwardButton],
    ['[data-erp-pwa-page-reload]', pageReloadButton],
  ]);
  const document = {
    documentElement: {
      classList: {
        toggle(className, enabled) {
          if (enabled) {
            rootClasses.add(className);
          } else {
            rootClasses.delete(className);
          }
        },
      },
    },
    querySelector(selector) {
      return elements.get(selector) || null;
    },
  };

  vm.runInNewContext(runtimeSource, { document, Promise, URL, window });

  return {
    backButton,
    displayModeQuery,
    forwardButton,
    navigation,
    navigationListeners,
    pageReloadButton,
    rootClasses,
    state,
    toolbar,
    syncDisplayMode() {
      mediaListeners.get('change')?.();
    },
  };
}

test('PWA navigation stays hidden in a regular browser tab', () => {
  const harness = navigationHarness();

  assert.equal(harness.toolbar.hidden, true);
  assert.equal(harness.rootClasses.has('erp-pwa-standalone'), false);
});

test('standalone PWA shows working back forward and reload controls', () => {
  const harness = navigationHarness({ standalone: true });

  assert.equal(harness.toolbar.hidden, false);
  assert.equal(harness.rootClasses.has('erp-pwa-standalone'), true);
  assert.equal(harness.backButton.disabled, false);
  assert.equal(harness.forwardButton.disabled, false);

  harness.backButton.click();
  harness.forwardButton.click();
  harness.pageReloadButton.click();

  assert.deepEqual(harness.state, { backs: 1, forwards: 1, reloads: 1 });
});

test('iOS standalone mode shows the PWA navigation controls', () => {
  const harness = navigationHarness({ iosStandalone: true });

  assert.equal(harness.toolbar.hidden, false);
  assert.equal(harness.rootClasses.has('erp-pwa-standalone'), true);
});

test('navigation API capabilities disable unavailable history actions', () => {
  const harness = navigationHarness({
    canGoBack: false,
    canGoForward: true,
    standalone: true,
  });

  assert.equal(harness.backButton.disabled, true);
  assert.equal(harness.forwardButton.disabled, false);

  harness.navigation.canGoBack = true;
  harness.navigation.canGoForward = false;
  harness.navigationListeners.get('currententrychange')();

  assert.equal(harness.backButton.disabled, false);
  assert.equal(harness.forwardButton.disabled, true);
});

test('display mode changes reveal controls without a page reload', () => {
  const harness = navigationHarness();

  harness.displayModeQuery.matches = true;
  harness.syncDisplayMode();

  assert.equal(harness.toolbar.hidden, false);
  assert.equal(harness.rootClasses.has('erp-pwa-standalone'), true);
});
