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

function navigationHarness({
  currentUrl = 'https://erp.test/dashboard',
  entryIndex,
  entryUrls = [],
  guardAllows = true,
  iosStandalone = false,
  referrer = '',
  standalone = false,
} = {}) {
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
    guardCalls: 0,
    assigned: [],
    reloads: 0,
  };
  const displayModeQuery = {
    matches: standalone,
    addEventListener(type, callback) {
      mediaListeners.set(type, callback);
    },
  };
  const entries = entryUrls.map((url) => ({ url }));
  const navigation = Number.isInteger(entryIndex)
    ? {
        canGoBack: entryIndex > 0,
        canGoForward: entryIndex < entries.length - 1,
        currentEntry: entries[entryIndex],
        entries() {
          return entries;
        },
        back() {
          state.backs += 1;
        },
        forward() {
          state.forwards += 1;
        },
        addEventListener(type, callback) {
          navigationListeners.set(type, callback);
        },
      }
    : undefined;
  const window = {
    AppNavigationGuard: {
      run(action) {
        state.guardCalls += 1;

        if (!guardAllows) {
          return false;
        }

        action();

        return true;
      },
    },
    AppPwaRuntime: {
      navigationBlockedPaths: ['/login', '/lock-screen', '/logout'],
      navigationFallbackUrl: '/dashboard',
    },
    history: {
      back() {
        state.backs += 1;
      },
      forward() {
        state.forwards += 1;
      },
    },
    location: {
      href: currentUrl,
      origin: 'https://erp.test',
      assign(url) {
        state.assigned.push(url);
      },
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
    referrer,
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

test('standalone PWA at its fallback keeps unknown history actions disabled', () => {
  const harness = navigationHarness({ standalone: true });

  assert.equal(harness.toolbar.hidden, false);
  assert.equal(harness.rootClasses.has('erp-pwa-standalone'), true);
  assert.equal(harness.backButton.disabled, true);
  assert.equal(harness.forwardButton.disabled, true);

  harness.pageReloadButton.click();

  assert.equal(harness.state.reloads, 1);
  assert.equal(harness.state.guardCalls, 1);
});

test('direct internal deep link uses the configured safe fallback', () => {
  const harness = navigationHarness({
    currentUrl: 'https://erp.test/admin/purchases/requisitions/42',
    standalone: true,
  });

  assert.equal(harness.backButton.disabled, false);
  assert.equal(harness.forwardButton.disabled, true);

  harness.backButton.click();

  assert.deepEqual(harness.state.assigned, ['https://erp.test/dashboard']);
});

test('iOS standalone mode shows the PWA navigation controls', () => {
  const harness = navigationHarness({ iosStandalone: true });

  assert.equal(harness.toolbar.hidden, false);
  assert.equal(harness.rootClasses.has('erp-pwa-standalone'), true);
});

test('navigation API capabilities disable unavailable history actions', () => {
  const harness = navigationHarness({
    entryIndex: 1,
    entryUrls: [
      'https://erp.test/dashboard',
      'https://erp.test/admin/purchases',
      'https://erp.test/admin/purchases/requisitions',
    ],
    standalone: true,
  });

  assert.equal(harness.backButton.disabled, false);
  assert.equal(harness.forwardButton.disabled, false);

  harness.backButton.click();
  harness.forwardButton.click();

  assert.equal(harness.state.backs, 1);
  assert.equal(harness.state.forwards, 1);

  harness.navigation.currentEntry = harness.navigation.entries()[2];
  harness.navigation.canGoBack = true;
  harness.navigation.canGoForward = false;
  harness.navigationListeners.get('currententrychange')();

  assert.equal(harness.backButton.disabled, false);
  assert.equal(harness.forwardButton.disabled, true);
});

test('external referrer is never used as a back destination', () => {
  const harness = navigationHarness({
    currentUrl: 'https://erp.test/dashboard',
    referrer: 'https://external.example/landing',
    standalone: true,
  });

  assert.equal(harness.backButton.disabled, true);
});

test('unsaved-change guard can cancel PWA reload', () => {
  const harness = navigationHarness({ guardAllows: false, standalone: true });

  harness.pageReloadButton.click();

  assert.equal(harness.state.guardCalls, 1);
  assert.equal(harness.state.reloads, 0);
});

test('display mode changes reveal controls without a page reload', () => {
  const harness = navigationHarness();

  harness.displayModeQuery.matches = true;
  harness.syncDisplayMode();

  assert.equal(harness.toolbar.hidden, false);
  assert.equal(harness.rootClasses.has('erp-pwa-standalone'), true);
});
