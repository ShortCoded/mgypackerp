const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const projectRoot = path.join(__dirname, '..');
const scriptSource = readFileSync(
  path.join(projectRoot, 'public/assets/js/modules/HR/employee-self-service.js'),
  'utf8',
);

function classList(initial = []) {
  const values = new Set(initial);

  return {
    contains(value) {
      return values.has(value);
    },
    toggle(value, enabled) {
      if (enabled) values.add(value);
      else values.delete(value);
    },
  };
}

function response(status, body) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json() {
      return Promise.resolve(body);
    },
  };
}

function frontendHarness(fetchHandler) {
  const documentListeners = new Map();
  const windowListeners = new Map();
  const rootListeners = new Map();
  const assignments = [];
  const fetchCalls = [];
  const contextCalls = [];
  const feedback = { className: '', textContent: '' };
  const stateLabel = { textContent: '' };
  const clock = { dataset: {}, textContent: '' };
  const checkIn = { textContent: '' };
  const worked = { textContent: '' };
  const breaks = { textContent: '' };
  const punchButton = {
    classList: classList(),
    dataset: { eventType: 'check_in' },
    disabled: false,
  };
  const elements = new Map([
    ['.js-attendance-feedback', feedback],
    ['.js-attendance-state-label', stateLabel],
    ['.js-attendance-clock', clock],
    ['.js-attendance-check-in', checkIn],
    ['.js-worked-minutes', worked],
    ['.js-break-minutes', breaks],
  ]);
  const root = {
    dataset: {
      loginUrl: '/login',
      messages: JSON.stringify({
        checkedInAt: 'Checked in since :time',
        failed: 'Failed',
        locating: 'Locating',
        saved: 'Saved',
        states: { not_checked_in: 'Not checked in', working: 'Working' },
      }),
      punchUrl: '/employee/hr/attendance/punch',
      statusUrl: '/employee/hr/attendance/status',
    },
    addEventListener(type, callback) {
      rootListeners.set(type, callback);
    },
    querySelector(selector) {
      return elements.get(selector) || null;
    },
    querySelectorAll(selector) {
      if (selector === '.js-attendance-punch') return [punchButton];
      if (selector === '.js-request-field') return [];
      return [];
    },
  };
  const document = {
    hidden: false,
    addEventListener(type, callback) {
      documentListeners.set(type, callback);
    },
    querySelector(selector) {
      if (selector === '.employee-self-service') return root;
      if (selector === 'meta[name="csrf-token"]') {
        return { getAttribute: () => 'csrf-token' };
      }
      return null;
    },
  };
  const window = {
    AppClientContext: {
      collect(options) {
        contextCalls.push(options);
        return Promise.resolve({
          client_context: { platform: 'mobile-test' },
          client_location: {
            accuracy: 10,
            latitude: 30.04442,
            longitude: 31.235712,
            source: 'browser_geolocation_live',
          },
        });
      },
    },
    Intl,
    addEventListener(type, callback) {
      windowListeners.set(type, callback);
    },
    crypto: webcrypto,
    fetch(url, options) {
      fetchCalls.push({ options, url });
      return Promise.resolve(fetchHandler(url, options, fetchCalls.length));
    },
    location: {
      assign(url) {
        assignments.push(url);
      },
    },
    setInterval() {},
  };

  vm.runInNewContext(scriptSource, {
    Date,
    Intl,
    JSON,
    Math,
    Promise,
    document,
    window,
  });

  return {
    assignments,
    checkIn,
    click() {
      rootListeners.get('click')({ target: { closest: () => punchButton } });
    },
    contextCalls,
    document,
    emitDocument(type) {
      documentListeners.get(type)?.();
    },
    emitWindow(type) {
      windowListeners.get(type)?.();
    },
    fetchCalls,
    feedback,
    punchButton,
    stateLabel,
  };
}

async function flushPromises() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

test('attendance tap locks duplicate submissions and asks for a fresh accurate location', async () => {
  const harness = frontendHarness(() => response(200, {
    data: {
      allowed_actions: ['break_start', 'check_out'],
      break_minutes: 0,
      check_in_at: '2026-09-13T08:00:00+03:00',
      check_in_display: '13/09/2026 08:00 AM',
      state: 'working',
      worked_minutes: 1,
    },
    message: 'Saved',
  }));

  harness.click();
  harness.click();
  assert.equal(harness.punchButton.disabled, true);

  await flushPromises();

  assert.equal(harness.fetchCalls.length, 1);
  assert.equal(harness.contextCalls.length, 1);
  assert.deepEqual(
    { ...harness.contextCalls[0] },
    {
      enableHighAccuracy: true,
      forceLocationRefresh: true,
      includeLocation: true,
      maximumAge: 0,
      timeout: 10000,
    },
  );
  assert.equal(harness.fetchCalls[0].options.credentials, 'same-origin');
  assert.equal(harness.fetchCalls[0].options.headers['X-CSRF-TOKEN'], 'csrf-token');
  assert.match(JSON.parse(harness.fetchCalls[0].options.body).idempotency_key, /^[0-9a-f-]{36}$/i);
  assert.equal(harness.punchButton.disabled, false);
  assert.equal(harness.stateLabel.textContent, 'Working');
  assert.equal(harness.checkIn.textContent, 'Checked in since 13/09/2026 08:00 AM');
});

test('a failed punch retries with the same idempotency key and rotates it only after success', async () => {
  const outcomes = [
    response(503, { message: 'Temporary failure' }),
    response(200, { data: { allowed_actions: [], state: 'working' }, message: 'Saved' }),
    response(200, { data: { allowed_actions: [], state: 'working' }, message: 'Saved' }),
  ];
  const harness = frontendHarness(() => outcomes.shift());

  harness.click();
  await flushPromises();
  harness.click();
  await flushPromises();
  harness.click();
  await flushPromises();

  const keys = harness.fetchCalls.map((call) => JSON.parse(call.options.body).idempotency_key);
  assert.equal(keys[0], keys[1]);
  assert.notEqual(keys[1], keys[2]);
});

test('status refreshes when the mobile page resumes and redirects expired sessions to login', async () => {
  const harness = frontendHarness((url) => {
    if (url.includes('/status')) return response(401, { message: 'Unauthenticated' });
    return response(200, { data: { allowed_actions: [], state: 'working' } });
  });

  harness.document.hidden = true;
  harness.emitDocument('visibilitychange');
  await flushPromises();
  assert.equal(harness.fetchCalls.length, 0);

  harness.document.hidden = false;
  harness.emitWindow('pageshow');
  await flushPromises();

  assert.equal(harness.fetchCalls.length, 1);
  assert.equal(harness.fetchCalls[0].options.method, 'GET');
  assert.deepEqual(harness.assignments, ['/login']);
});

test('HR self-service and reports retain shared components, density, and responsive layouts', () => {
  const css = readFileSync(
    path.join(projectRoot, 'public/assets/css/modules/HR/employee-self-service.css'),
    'utf8',
  );
  const selfService = readFileSync(
    path.join(projectRoot, 'resources/views/modules/hr/self-service/index.blade.php'),
    'utf8',
  );
  const report = readFileSync(
    path.join(projectRoot, 'resources/views/modules/hr/attendance/index.blade.php'),
    'utf8',
  );
  const tableCard = readFileSync(
    path.join(projectRoot, 'resources/views/components/admin/report/table-card.blade.php'),
    'utf8',
  );

  assert.match(css, /@media\(max-width:575\.98px\)/);
  assert.match(css, /\.attendance-actions\{grid-template-columns:1fr\}/);
  assert.match(css, /\.attendance-action-btn\{min-height:4\.75rem/);
  assert.match(selfService, /data-login-url=/);
  assert.match(selfService, /aria-live="polite"/);
  assert.match(selfService, /<x-forms\.date-input/);
  assert.match(selfService, /hr_requests\.letter_languages\.ar/);
  assert.match(selfService, /hr_requests\.letter_languages\.en/);
  assert.match(selfService, /form-control-lg/);
  assert.match(report, /<x-admin\.report\.page/);
  assert.match(report, /<x-admin\.report\.filter-panel/);
  assert.match(report, /<x-admin\.report\.table-card/);
  assert.match(report, /<x-forms\.date-input/);
  assert.match(report, /d-none d-lg-block/);
  assert.match(report, /data-attendance-mobile-cards/);
  assert.doesNotMatch(report, /type="date"/);
  assert.doesNotMatch(report, /->format\(/);
  assert.match(tableCard, /table table-sm table-hover/);
});
