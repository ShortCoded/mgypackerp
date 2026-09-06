const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const runtimeSource = readFileSync(
  path.join(__dirname, '..', 'public/assets/js/modules/Core/pwa-runtime.js'),
  'utf8',
);
const cleanupMarkerKey = 'erp-pwa-disabled-cleanup-v1';

function createState() {
  return {
    cacheDeletes: 0,
    cacheKeyReads: 0,
    failRegistrationLookup: false,
    registrations: 0,
    serviceWorkerRegistrations: 0,
    storage: new Map(),
    unregisters: 0,
  };
}

function runtimeHarness(state, enabled) {
  const listeners = new Map();
  const managedRegistration = {
    active: { scriptURL: 'https://erp.test/pwa-service-worker.js' },
    installing: null,
    waiting: null,
    addEventListener() {},
    unregister() {
      state.unregisters += 1;

      return Promise.resolve(true);
    },
  };
  const serviceWorker = {
    controller: null,
    addEventListener() {},
    getRegistrations() {
      state.serviceWorkerRegistrations += 1;

      if (state.failRegistrationLookup) {
        return Promise.reject(new Error('temporary service worker failure'));
      }

      return Promise.resolve([managedRegistration]);
    },
    register() {
      state.registrations += 1;

      return Promise.resolve(managedRegistration);
    },
  };
  const window = {
    AppPwaRuntime: {
      cachePrefix: 'erp-pwa-cache',
      enabled,
      scope: '/',
      serviceWorkerUrl: '/pwa-service-worker.js',
    },
    caches: {
      delete() {
        state.cacheDeletes += 1;

        return Promise.resolve(true);
      },
      keys() {
        state.cacheKeyReads += 1;

        return Promise.resolve(['erp-pwa-cache-v1', 'unrelated-cache']);
      },
    },
    localStorage: {
      getItem(key) {
        return state.storage.has(key) ? state.storage.get(key) : null;
      },
      removeItem(key) {
        state.storage.delete(key);
      },
      setItem(key, value) {
        state.storage.set(key, String(value));
      },
    },
    location: { href: 'https://erp.test/dashboard' },
    navigator: { serviceWorker },
    addEventListener(type, callback) {
      listeners.set(type, callback);
    },
  };
  const document = {
    querySelector() {
      return null;
    },
  };

  vm.runInNewContext(runtimeSource, { document, Promise, URL, window });

  return {
    async load() {
      await listeners.get('load')();
    },
  };
}

test('disabled PWA cleanup runs once for repeated navigations', async () => {
  const state = createState();

  await runtimeHarness(state, false).load();

  assert.equal(state.serviceWorkerRegistrations, 1);
  assert.equal(state.unregisters, 1);
  assert.equal(state.cacheKeyReads, 1);
  assert.equal(state.cacheDeletes, 1);
  assert.ok(state.storage.has(cleanupMarkerKey));

  await runtimeHarness(state, false).load();

  assert.equal(state.serviceWorkerRegistrations, 1);
  assert.equal(state.unregisters, 1);
  assert.equal(state.cacheKeyReads, 1);
  assert.equal(state.cacheDeletes, 1);
});

test('enabling PWA clears the marker so the next disable transition cleans up', async () => {
  const state = createState();

  await runtimeHarness(state, false).load();
  await runtimeHarness(state, true).load();

  assert.equal(state.registrations, 1);
  assert.equal(state.storage.has(cleanupMarkerKey), false);

  await runtimeHarness(state, false).load();

  assert.equal(state.unregisters, 2);
  assert.equal(state.cacheKeyReads, 2);
  assert.equal(state.cacheDeletes, 2);
  assert.ok(state.storage.has(cleanupMarkerKey));
});

test('failed disabled cleanup is retried on the next navigation', async () => {
  const state = createState();
  state.failRegistrationLookup = true;

  await runtimeHarness(state, false).load();

  assert.equal(state.storage.has(cleanupMarkerKey), false);

  state.failRegistrationLookup = false;
  await runtimeHarness(state, false).load();

  assert.equal(state.serviceWorkerRegistrations, 2);
  assert.equal(state.unregisters, 1);
  assert.ok(state.storage.has(cleanupMarkerKey));
});
