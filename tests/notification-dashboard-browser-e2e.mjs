import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.NOTIFICATION_DASHBOARD_E2E_BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const debuggerUrl = (process.env.NOTIFICATION_DASHBOARD_E2E_DEBUG_URL || 'http://127.0.0.1:9225').replace(/\/$/, '');
const artifactDirectory = process.env.NOTIFICATION_DASHBOARD_E2E_ARTIFACT_DIR;
const loginIdentifier = process.env.NOTIFICATION_DASHBOARD_E2E_LOGIN || 'runtime_demo_full';
const loginPassword = process.env.NOTIFICATION_DASHBOARD_E2E_PASSWORD || 'RuntimeDemo2026!';

if (!artifactDirectory) {
  throw new Error('NOTIFICATION_DASHBOARD_E2E_ARTIFACT_DIR is required.');
}

await mkdir(artifactDirectory, { recursive: true });

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

class DevToolsClient {
  constructor(webSocketUrl) {
    this.webSocket = new WebSocket(webSocketUrl);
    this.nextId = 1;
    this.pending = new Map();
    this.ready = new Promise((resolve, reject) => {
      this.webSocket.addEventListener('open', resolve, { once: true });
      this.webSocket.addEventListener('error', reject, { once: true });
    });
    this.webSocket.addEventListener('message', (event) => {
      const message = JSON.parse(event.data);

      if (message.id) {
        const pending = this.pending.get(message.id);
        if (!pending) return;
        this.pending.delete(message.id);
        if (message.error) pending.reject(new Error(`${pending.method}: ${message.error.message}`));
        else pending.resolve(message.result || {});
        return;
      }

      for (const listener of this.listeners.get(message.method) || []) listener(message.params || {});
    });
    this.listeners = new Map();
  }

  async send(method, params = {}) {
    await this.ready;
    const id = this.nextId++;
    const result = new Promise((resolve, reject) => this.pending.set(id, { method, reject, resolve }));
    this.webSocket.send(JSON.stringify({ id, method, params }));

    return result;
  }

  on(method, listener) {
    const listeners = this.listeners.get(method) || [];
    listeners.push(listener);
    this.listeners.set(method, listeners);
  }

  close() {
    this.webSocket.close();
  }
}

const target = await fetch(`${debuggerUrl}/json/new?${encodeURIComponent(`${baseUrl}/login`)}`, { method: 'PUT' }).then((response) => {
  if (!response.ok) throw new Error(`Unable to create Chrome target: HTTP ${response.status}`);

  return response.json();
});
const client = new DevToolsClient(target.webSocketDebuggerUrl);
await client.ready;

let loadCount = 0;
const browserErrors = [];
const responseErrors = [];
client.on('Page.loadEventFired', () => { loadCount += 1; });
client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
  browserErrors.push(exceptionDetails?.exception?.description || exceptionDetails?.text || 'Unhandled JavaScript exception');
});
client.on('Runtime.consoleAPICalled', ({ type, args }) => {
  if (type === 'error' || type === 'assert') {
    browserErrors.push(args.map((argument) => argument.value || argument.description || '').join(' '));
  }
});
client.on('Network.responseReceived', ({ response, type }) => {
  if (['Document', 'XHR', 'Fetch'].includes(type) && response?.url?.startsWith(baseUrl) && response.status >= 400) {
    responseErrors.push(`${response.status} ${response.url}`);
  }
});

await Promise.all([
  client.send('Page.enable'),
  client.send('Runtime.enable'),
  client.send('Network.enable'),
]);

async function evaluate(expression) {
  const result = await client.send('Runtime.evaluate', {
    awaitPromise: true,
    expression,
    returnByValue: true,
    userGesture: true,
  });

  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'Browser evaluation failed.');
  }

  return result.result?.value;
}

async function waitUntil(callback, message, timeout = 30000) {
  const deadline = Date.now() + timeout;
  let lastError;

  while (Date.now() < deadline) {
    try {
      const value = await callback();
      if (value) return value;
    } catch (error) {
      lastError = error;
    }

    await sleep(150);
  }

  throw new Error(`${message}${lastError ? ` (${lastError.message})` : ''}`);
}

async function setViewport(width, height) {
  await client.send('Emulation.setDeviceMetricsOverride', {
    deviceScaleFactor: 1,
    height,
    mobile: width <= 430,
    screenOrientation: width > height
      ? { angle: 90, type: 'landscapePrimary' }
      : { angle: 0, type: 'portraitPrimary' },
    width,
  });
}

async function navigate(route) {
  const before = loadCount;
  const url = route.startsWith('http') ? route : `${baseUrl}${route}`;
  const navigation = await client.send('Page.navigate', { url });

  if (navigation.errorText) throw new Error(`${url}: ${navigation.errorText}`);
  await waitUntil(() => loadCount > before, `Navigation did not complete: ${url}`);
  await waitUntil(() => evaluate(`document.readyState === 'complete' && Boolean(document.body)`), `Page was not ready: ${url}`);
  await sleep(250);
}

async function screenshot(filename) {
  const capture = await client.send('Page.captureScreenshot', {
    captureBeyondViewport: false,
    format: 'png',
    fromSurface: true,
  });
  const outputPath = path.join(artifactDirectory, filename);
  await writeFile(outputPath, Buffer.from(capture.data, 'base64'));

  return outputPath;
}

async function setField(selector, value) {
  const changed = await evaluate(`(() => {
    const field = document.querySelector(${JSON.stringify(selector)});
    if (!field) return false;
    field.value = ${JSON.stringify(String(value))};
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  })()`);
  assert(changed, `Missing field: ${selector}`);
}

async function login() {
  await navigate('/login');

  if (!(await evaluate(`Boolean(document.querySelector('[name="login"]'))`))) return;

  await setField('[name="login"]', loginIdentifier);
  await setField('[name="password"]', loginPassword);
  const before = loadCount;
  assert(await evaluate(`(() => {
    const form = document.querySelector('form');
    if (!form) return false;
    form.requestSubmit();
    return true;
  })()`), 'Login form missing.');
  await waitUntil(() => loadCount > before, 'Login did not navigate.');
  await waitUntil(() => evaluate(`!location.pathname.includes('/login')`), 'Login failed.');
}

async function ensureOperatingContext() {
  const selected = await evaluate(`(async () => {
    const config = window.AppOperatingContext || {};
    if (!config.current?.requires_selection) return false;
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    };
    const initial = (await (await fetch(config.optionsUrl, { credentials: 'same-origin', headers })).json()).data;
    const company = initial.companies[0];
    const optionsUrl = new URL(config.optionsUrl, location.origin);
    optionsUrl.searchParams.set('company_doc_num', company.doc_num);
    const scoped = (await (await fetch(optionsUrl, { credentials: 'same-origin', headers })).json()).data;
    const response = await fetch(config.selectUrl, {
      body: JSON.stringify({
        branch_doc_num: scoped.branches[0].doc_num,
        company_doc_num: company.doc_num,
        financial_period_doc_num: scoped.financial_periods[0].doc_num,
      }),
      credentials: 'same-origin',
      headers,
      method: 'POST',
    });
    if (!response.ok) throw new Error('Operating context selection failed: HTTP ' + response.status);
    return true;
  })()`);

  if (selected) await navigate('/dashboard');
}

async function notificationLayoutAudit(width) {
  return evaluate(`(() => {
    const visible = (element) => {
      if (!element) return false;
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return !element.hidden && style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    };
    const fields = ['notification-search', 'notification-module', 'notification-type', 'notification-state', 'notification-from', 'notification-to']
      .map((id) => {
        const input = document.getElementById(id);
        const column = input?.closest('[class*="col-"]');
        const rect = column?.getBoundingClientRect();
        return rect ? { bottom: rect.bottom, id, left: rect.left, right: rect.right, top: rect.top, width: rect.width } : null;
      }).filter(Boolean);
    return {
      deviceStatus: document.querySelector('[data-push-notification-status]')?.textContent.trim() || '',
      diagnostics: Boolean(document.querySelector('a[href*="diagnostics"]')),
      direction: document.documentElement.dir,
      fields,
      rawDateInputs: document.querySelectorAll('input[type="date"]').length,
      sharedDateInputs: document.querySelectorAll('.js-date-picker[data-storage-format]').length,
      sharedSelects: document.querySelectorAll('.js-select2-local').length,
      testButtons: document.querySelectorAll('[data-notification-sound-test]').length,
      viewportWidth: innerWidth,
      visibleFieldCount: fields.filter((field) => field.left >= 0 && field.right <= innerWidth).length,
    };
  })()`);
}

const artifacts = [];
const layoutAudits = [];

try {
  await setViewport(390, 844);
  await login();
  await navigate('/lang/ar');
  await evaluate(`localStorage.setItem('theme', 'light')`);
  await navigate('/dashboard');
  await ensureOperatingContext();

  await setViewport(1440, 1000);
  await navigate('/admin/notifications');
  const desktopAudit = await notificationLayoutAudit(1440);
  assert(desktopAudit.fields.length === 6 && desktopAudit.visibleFieldCount === 6, `Desktop notification fields overflowed: ${JSON.stringify(desktopAudit)}`);
  assert(new Set(desktopAudit.fields.map(({ top }) => Math.round(top))).size === 1, `Desktop filters were not in one balanced row: ${JSON.stringify(desktopAudit.fields)}`);
  assert(desktopAudit.sharedDateInputs === 2 && desktopAudit.rawDateInputs === 0, `Shared date inputs were not used: ${JSON.stringify(desktopAudit)}`);
  assert(desktopAudit.sharedSelects === 3, `Shared select inputs were not used: ${JSON.stringify(desktopAudit)}`);
  assert(!desktopAudit.diagnostics && desktopAudit.testButtons === 0, `Retired controls were still visible: ${JSON.stringify(desktopAudit)}`);
  layoutAudits.push({ width: 1440, ...desktopAudit });
  artifacts.push(await screenshot('notification-center-desktop-ar-1440.png'));

  assert(await evaluate(`(() => {
    const select = window.jQuery?.('#notification-module');
    if (!select?.length || typeof select.select2 !== 'function') return false;
    select.select2('open');
    return true;
  })()`), 'Notification module select could not be opened.');
  await waitUntil(() => evaluate(`Boolean(document.querySelector('.select2-container--open .select2-search__field'))`), 'Notification module select did not open.');
  await setField('.select2-container--open .select2-search__field', 'الم');
  await sleep(250);
  artifacts.push(await screenshot('notification-filters-open-desktop-ar-1440.png'));
  await evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'Escape' }))`);

  const savedFilterPath = '/admin/notifications?module=chat&type=chat.message&state=unread&from=2026-09-01&to=2026-09-17';
  await navigate(savedFilterPath);
  const savedFilters = await evaluate(`({
    from: document.getElementById('notification-from')?.value,
    module: document.getElementById('notification-module')?.value,
    state: document.getElementById('notification-state')?.value,
    to: document.getElementById('notification-to')?.value,
    type: document.getElementById('notification-type')?.value,
  })`);
  assert(savedFilters.from === '2026-09-01' && savedFilters.to === '2026-09-17', `Saved dates were not restored: ${JSON.stringify(savedFilters)}`);
  assert(savedFilters.module === 'chat' && savedFilters.type === 'chat.message' && savedFilters.state === 'unread', `Saved selects were not restored: ${JSON.stringify(savedFilters)}`);
  assert(await evaluate(`(() => {
    const select = window.jQuery?.('#notification-module');
    if (!select?.length || typeof select.select2 !== 'function') return false;
    select.val(null).trigger('change');
    return select.val() === null || select.val() === '';
  })()`), 'Clearing a notification select retained its previous value.');
  assert(await evaluate(`(() => {
    const input = document.getElementById('notification-from');
    input._flatpickr.setDate('2026-09-02', true, 'Y-m-d');
    return input.value === '2026-09-02';
  })()`), 'Date picker did not synchronize its storage value.');
  assert(await evaluate(`(() => {
    const input = document.getElementById('notification-from');
    const picker = input?._flatpickr;
    if (!picker?.altInput) return false;
    picker.altInput.value = '03/09/2026';
    picker.altInput.dispatchEvent(new Event('input', { bubbles: true }));
    picker.altInput.dispatchEvent(new FocusEvent('blur', { bubbles: true }));
    return input.value === '2026-09-03';
  })()`), 'Manual date entry did not synchronize its storage value.');

  const resetHref = await evaluate(`document.querySelector('form[action*="/admin/notifications"] a[href$="/admin/notifications"]')?.href || ''`);
  assert(resetHref !== '', 'Notification filter reset action was not available.');
  await navigate(new URL(resetHref).pathname);
  const resetFilters = await evaluate(`({
    from: document.getElementById('notification-from')?.value,
    module: document.getElementById('notification-module')?.value,
    state: document.getElementById('notification-state')?.value,
    to: document.getElementById('notification-to')?.value,
    type: document.getElementById('notification-type')?.value,
  })`);
  assert(resetFilters.from === '' && resetFilters.to === '' && resetFilters.module === '' && resetFilters.type === '' && resetFilters.state === 'all', `Reset filters retained stale values: ${JSON.stringify(resetFilters)}`);

  for (const [width, height] of [[1024, 768], [768, 1024], [390, 844], [320, 700]]) {
    await setViewport(width, height);
    await sleep(200);
    const audit = await notificationLayoutAudit(width);
    assert(audit.fields.length === 6 && audit.visibleFieldCount === 6, `Notification fields overflowed at ${width}px: ${JSON.stringify(audit)}`);
    assert(audit.sharedDateInputs === 2 && audit.sharedSelects === 3, `Shared fields were missing at ${width}px: ${JSON.stringify(audit)}`);
    layoutAudits.push({ width, ...audit });

    if (width === 390) artifacts.push(await screenshot('notification-center-mobile-ar-390.png'));
  }

  await setViewport(1440, 1000);
  await navigate('/dashboard');
  const dashboardAudit = await evaluate(`(() => {
    const header = document.querySelector('.plastics-dashboard-header');
    const personal = document.querySelector('[data-personal-dashboard]');
    return {
      contextInContentHeader: Boolean(header?.querySelector('.plastics-dashboard-context')),
      headerText: header?.innerText.trim() || '',
      personalHealthText: personal?.querySelector('[data-personal-health]')?.innerText.trim() || '',
      personalSummary: personal?.querySelector('[data-personal-summary]')?.innerText.trim() || '',
      title: header?.querySelector('h4')?.innerText.trim() || '',
    };
  })()`);
  assert(dashboardAudit.title === 'لوحة التحكم', `Dashboard title was not clean: ${JSON.stringify(dashboardAudit)}`);
  assert(!dashboardAudit.contextInContentHeader && !dashboardAudit.headerText.includes('آخر تحديث'), `Dashboard header repeated context or time: ${JSON.stringify(dashboardAudit)}`);
  assert(dashboardAudit.personalHealthText === '', `Personal dashboard showed an update time: ${JSON.stringify(dashboardAudit)}`);
  artifacts.push(await screenshot('dashboard-desktop-ar-1440.png'));

  await setViewport(390, 844);
  await sleep(200);
  artifacts.push(await screenshot('dashboard-mobile-ar-390.png'));

  await navigate('/lang/en');
  await navigate('/admin/notifications');
  const englishAudit = await notificationLayoutAudit(390);
  assert(englishAudit.direction === 'ltr', `English notification center direction was not LTR: ${JSON.stringify(englishAudit)}`);

  await evaluate(`localStorage.setItem('theme', 'dark')`);
  await navigate('/admin/notifications');
  assert((await evaluate(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'Dark theme did not apply to the notification center.');

  const result = {
    artifacts,
    browserErrors: [...new Set(browserErrors)].filter(Boolean),
    dashboard: dashboardAudit,
    english: englishAudit,
    layoutAudits,
    responseErrors: [...new Set(responseErrors)].filter(Boolean),
    savedFilters,
  };
  assert(result.browserErrors.length === 0, `Browser errors: ${result.browserErrors.join(' | ')}`);
  assert(result.responseErrors.length === 0, `HTTP errors: ${result.responseErrors.join(' | ')}`);
  await writeFile(path.join(artifactDirectory, 'result.json'), JSON.stringify(result, null, 2));
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
} catch (error) {
  await writeFile(path.join(artifactDirectory, 'failure.txt'), `${error.stack || error}\nURL: ${await evaluate('location.href').catch(() => 'unknown')}\n${await evaluate('document.body.innerText').catch(() => '')}`);
  throw error;
} finally {
  client.close();
}
