import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../public/assets/js/modules/Core/connectivity.js', import.meta.url), 'utf8');

function createPage() {
  const timers = new Map();
  const events = new Map();
  const ajaxEvents = new Map();
  const classes = new Set();
  const message = { textContent: '' };
  const retryButton = {
    hidden: false,
    disabled: false,
    addEventListener(name, handler) { events.set('retry:' + name, handler); },
  };
  const status = {
    hidden: true,
    dataset: { offlineMessage: 'offline', onlineMessage: 'online' },
    classList: {
      add(name) { classes.add(name); },
      remove(name) { classes.delete(name); },
    },
    querySelector(selector) {
      return selector === '[data-erp-connectivity-message]' ? message : retryButton;
    },
  };
  const documentElement = {
    dataset: {},
    removeAttribute(name) {
      if (name === 'data-connectivity') {
        delete this.dataset.connectivity;
      }
    },
  };
  const document = {
    documentElement,
    querySelector(selector) {
      return selector === '[data-erp-connectivity-status]' ? status : null;
    },
  };
  const page = {
    now: 0,
    nextTimerId: 1,
    healthCalls: 0,
    healthResults: [],
    appResponse: { ok: true },
    status,
    message,
    retryButton,
    documentElement,
    events,
    ajaxEvents,
    async advance(milliseconds) {
      const target = this.now + milliseconds;

      while (true) {
        const due = [...timers].sort((a, b) => a[1].at - b[1].at)
          .find((entry) => entry[1].at <= target);

        if (!due) {
          break;
        }

        timers.delete(due[0]);
        this.now = due[1].at;
        due[1].callback();
        await this.flush();
      }

      this.now = target;
      await this.flush();
    },
    async flush() {
      for (let index = 0; index < 12; index += 1) {
        await Promise.resolve();
      }
    },
  };
  const window = {
    location: { href: 'http://erp.test/dashboard', origin: 'http://erp.test' },
    navigator: { onLine: true },
    AbortController,
    setTimeout(callback, delay) {
      const id = page.nextTimerId++;
      timers.set(id, { at: page.now + delay, callback });
      return id;
    },
    clearTimeout(id) { timers.delete(id); },
    addEventListener(name, handler) { events.set(name, handler); },
    fetch(input) {
      if (input === '/up') {
        page.healthCalls += 1;
        const healthy = page.healthResults.shift() ?? true;
        return healthy ? Promise.resolve({ ok: true }) : Promise.reject(new TypeError('unreachable'));
      }

      return Promise.resolve(page.appResponse);
    },
    jQuery() {
      return {
        off() { return this; },
        on(name, handler) { ajaxEvents.set(name, handler); return this; },
      };
    },
  };

  vm.runInNewContext(script, { window, document, URL, Promise });

  page.window = window;
  return page;
}

test('aborted and cross-origin AJAX requests do not claim the server is offline', async () => {
  const page = createPage();
  const ajaxError = page.ajaxEvents.get('ajaxError.erpConnectivity');

  ajaxError(null, { status: 0, statusText: 'abort' }, { url: '/admin/search' }, 'abort');
  ajaxError(null, { status: 0, statusText: 'error' }, { url: 'https://other.test/search' }, 'error');
  await page.advance(5000);

  assert.equal(page.healthCalls, 0);
  assert.equal(page.status.hidden, true);
});

test('an aborted fetch does not start a health check', async () => {
  const page = createPage();

  page.window.AppConnectivity.reportFailure({ name: 'AbortError' });
  await page.advance(5000);

  assert.equal(page.healthCalls, 0);
  assert.equal(page.status.hidden, true);
});

test('a transient failure is ignored when the health endpoint recovers', async () => {
  const page = createPage();
  page.healthResults.push(false, true);

  page.window.AppConnectivity.reportFailure(new TypeError('network error'));
  await page.advance(750);
  await page.advance(1000);

  assert.equal(page.healthCalls, 2);
  assert.equal(page.status.hidden, true);
  assert.equal(page.documentElement.dataset.connectivity, undefined);
});

test('a real outage needs two failed probes and recovery is confirmed', async () => {
  const page = createPage();
  page.healthResults.push(false, false, true);

  page.events.get('offline')();
  await page.advance(750);
  assert.equal(page.status.hidden, true);
  await page.advance(1000);
  assert.equal(page.healthCalls, 2);
  assert.equal(page.documentElement.dataset.connectivity, 'offline');
  assert.equal(page.retryButton.hidden, false);

  assert.equal(await page.window.AppConnectivity.retry(), true);
  assert.equal(page.documentElement.dataset.connectivity, 'online');
  assert.equal(page.retryButton.hidden, true);
  await page.advance(3000);
  assert.equal(page.status.hidden, true);
});

test('a failed AJAX request is checked against server health before warning', async () => {
  const page = createPage();
  page.healthResults.push(true);

  page.ajaxEvents.get('ajaxError.erpConnectivity')(
    null,
    { status: 0, statusText: 'error' },
    { url: '/admin/search' },
    'error',
  );
  await page.advance(750);

  assert.equal(page.healthCalls, 1);
  assert.equal(page.status.hidden, true);
});

test('confirmed outages recover automatically when the server responds again', async () => {
  const page = createPage();
  page.healthResults.push(false, false, true);

  page.events.get('offline')();
  await page.advance(1750);
  assert.equal(page.documentElement.dataset.connectivity, 'offline');

  await page.advance(15000);
  assert.equal(page.healthCalls, 3);
  assert.equal(page.documentElement.dataset.connectivity, 'online');
});

test('an application success cancels an unconfirmed failure without flashing a message', async () => {
  const page = createPage();

  page.window.AppConnectivity.reportFailure(new TypeError('temporary failure'));
  await page.window.fetch('/dashboard');
  await page.advance(5000);

  assert.equal(page.healthCalls, 0);
  assert.equal(page.status.hidden, true);
});

test('HTTP errors and unrelated AJAX success cannot claim recovery', async () => {
  const page = createPage();
  page.healthResults.push(false, false);
  page.events.get('offline')();
  await page.advance(1750);
  assert.equal(page.documentElement.dataset.connectivity, 'offline');

  page.appResponse = { ok: false };
  await page.window.fetch('/admin/orders');
  page.ajaxEvents.get('ajaxSuccess.erpConnectivity')(null, { status: 200 }, { url: 'https://other.test/search' });
  assert.equal(page.documentElement.dataset.connectivity, 'offline');

  page.ajaxEvents.get('ajaxSuccess.erpConnectivity')(null, { status: 200 }, { url: '/admin/search' });
  assert.equal(page.documentElement.dataset.connectivity, 'online');
});

test('a healthy online event without a prior outage shows no recovery message', async () => {
  const page = createPage();

  await page.events.get('online')();

  assert.equal(page.healthCalls, 1);
  assert.equal(page.status.hidden, true);
});
