import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.HR_E2E_BASE_URL || '').replace(/\/$/, '');
const debuggerUrl = (process.env.HR_E2E_DEBUG_URL || '').replace(/\/$/, '');
const artifactDirectory = process.env.HR_E2E_ARTIFACT_DIR;
const username = process.env.HR_E2E_USERNAME;
const password = process.env.HR_E2E_PASSWORD;

for (const [name, value] of Object.entries({
  HR_E2E_BASE_URL: baseUrl,
  HR_E2E_DEBUG_URL: debuggerUrl,
  HR_E2E_ARTIFACT_DIR: artifactDirectory,
  HR_E2E_USERNAME: username,
  HR_E2E_PASSWORD: password,
})) {
  if (!value) throw new Error(`${name} is required.`);
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
    this.listeners = new Map();
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
  }

  async send(method, params = {}) {
    await this.ready;
    const id = this.nextId++;
    const result = new Promise((resolve, reject) => this.pending.set(id, { method, resolve, reject }));
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
const runtimeErrors = { failedRequests: [], responses500: [], sqlState: [], rawTranslations: [] };

client.on('Page.loadEventFired', () => { loadCount += 1; });
client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
  browserErrors.push(exceptionDetails?.exception?.description || exceptionDetails?.text || 'Unhandled JavaScript exception');
});
client.on('Runtime.consoleAPICalled', ({ type, args }) => {
  if (!['error', 'assert'].includes(type)) return;
  const message = args.map((argument) => argument.value || argument.description || '').join(' ');
  browserErrors.push(message);
  if (/SQLSTATE/i.test(message)) runtimeErrors.sqlState.push(message);
});
client.on('Log.entryAdded', ({ entry }) => {
  if (entry.level === 'error' && !entry.url?.endsWith('/favicon.ico')) {
    browserErrors.push(entry.url ? `${entry.url}: ${entry.text}` : entry.text);
  }
});
client.on('Network.loadingFailed', ({ type, canceled, errorText, requestId }) => {
  if (['XHR', 'Fetch'].includes(type) && canceled !== true) runtimeErrors.failedRequests.push(errorText || requestId);
});
client.on('Network.responseReceived', ({ response }) => {
  if (response?.url?.startsWith(baseUrl) && response.status >= 500) {
    runtimeErrors.responses500.push(`${response.status} ${response.url}`);
  }
});

await Promise.all([
  client.send('Page.enable'),
  client.send('Runtime.enable'),
  client.send('Log.enable'),
  client.send('Network.enable'),
]);
await client.send('Emulation.setDeviceMetricsOverride', {
  width: Number(process.env.HR_E2E_VIEWPORT_WIDTH || 1440),
  height: Number(process.env.HR_E2E_VIEWPORT_HEIGHT || 1000),
  deviceScaleFactor: 1,
  mobile: false,
});

async function evaluate(expression) {
  const result = await client.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
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
      const result = await callback();
      if (result) return result;
    } catch (error) {
      lastError = error;
    }
    await sleep(100);
  }
  throw new Error(`${message}${lastError ? ` (${lastError.message})` : ''}`);
}

async function navigate(url) {
  const before = loadCount;
  await client.send('Page.navigate', { url });
  await waitUntil(() => loadCount > before, `Navigation did not complete: ${url}`);
  await waitUntil(() => evaluate(`document.readyState === 'complete' && Boolean(document.body)`), `Page was not ready: ${url}`);
  await sleep(400);
}

async function setField(selector, value) {
  const changed = await evaluate(`(() => {
    const field = document.querySelector(${JSON.stringify(selector)});
    if (!field) return false;
    field.value = ${JSON.stringify(value)};
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  })()`);
  assert(changed, `Field not found: ${selector}`);
}

async function submitForm(selector, context) {
  const before = loadCount;
  const submitted = await evaluate(`(() => {
    const form = document.querySelector(${JSON.stringify(selector)});
    if (!form) return false;
    form.requestSubmit();
    return true;
  })()`);
  assert(submitted, `${context}: form not found.`);
  await waitUntil(() => loadCount > before, `${context}: navigation did not complete.`, 45000);
  await waitUntil(() => evaluate(`document.readyState === 'complete'`), `${context}: page did not become ready.`);
}

async function bodyText() {
  return evaluate('document.body.innerText');
}

async function captureScreenshot(filename) {
  const screenshot = await client.send('Page.captureScreenshot', { format: 'png', fromSurface: true });
  await writeFile(path.join(artifactDirectory, filename), Buffer.from(screenshot.data, 'base64'));
}

async function selectMarkerOperatingContext() {
  const result = await evaluate(`(async () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) return { ok: false, status: 0, body: 'Missing CSRF token' };
    const response = await fetch('/admin/operating-context/select', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
      },
      body: JSON.stringify({
        company_doc_num: 'Company-HR-E2E-FULL-CYCLE',
        branch_doc_num: 'Branch-HR-E2E-FULL-CYCLE',
        financial_period_doc_num: 'FinancialPeriod-HR-E2E-FULL-CYCLE',
      }),
    });
    return { ok: response.ok, status: response.status, body: await response.text() };
  })()`);
  assert(result.ok, `Unable to select marker operating context: HTTP ${result.status}.`);
}

const routes = [
  ['/dashboard', 'لوحة'],
  ['/admin/hr/employees', 'الموظفين'],
  ['/admin/hr/employment-types', 'أنواع التوظيف'],
  ['/admin/hr/departments', 'الإدارات'],
  ['/admin/hr/sections', 'الأقسام الوظيفية'],
  ['/admin/hr/jobs', 'الوظائف'],
  ['/admin/hr/shifts', 'الورديات'],
  ['/admin/hr/shift-assignments', 'تخصيص الورديات'],
  ['/admin/hr/attendance-settings', 'إعدادات الحضور والانصراف'],
  ['/admin/hr/employee-attendance', 'حضور وانصراف الموظفين'],
  ['/admin/hr/hr-requests', 'طلبات الموارد البشرية'],
  ['/admin/hr/leave-types', 'أنواع الإجازات'],
  ['/admin/hr/payroll-attendance-policies', 'سياسات تأثير الحضور'],
  ['/admin/hr/payroll-preparation', 'إعداد الرواتب'],
  ['/my/hr', 'الخدمات الذاتية للموظف'],
  ['/admin/hr/reports/employees', 'تقرير الموظفين'],
  ['/admin/hr/reports/attendance', 'حضور وانصراف الموظفين'],
  ['/admin/hr/reports/leave-requests', 'تقرير طلبات'],
  ['/admin/hr/reports/payroll', 'تقرير الرواتب'],
  ['/admin/hr/reports/payroll-payments', 'تقرير مدفوعات'],
];

try {
  await navigate(`${baseUrl}/login`);
  await setField('#login', username);
  await setField('#password', password);
  await submitForm('form.js-auth-form', 'HR E2E login');
  assert(!(await evaluate('window.location.pathname')).includes('/login'), 'HR E2E login remained on the login page.');

  await navigate(`${baseUrl}/lang/ar`);
  await selectMarkerOperatingContext();

  const results = [];
  for (const [route, expectedText] of routes) {
    await navigate(`${baseUrl}${route}`);
    const currentPath = await evaluate('window.location.pathname');
    const text = await bodyText();
    const direction = await evaluate(`document.documentElement.dir || getComputedStyle(document.body).direction`);

    assert(!currentPath.includes('/login'), `${route} redirected to login.`);
    assert(direction === 'rtl', `${route} did not render RTL Arabic UI.`);
    assert(text.includes(expectedText), `${route} did not contain expected Arabic text: ${expectedText}`);
    assert(!/(?:translation missing|hr(?:_|\.)[a-z0-9_.-]{3,})/i.test(text), `${route} rendered a raw translation key.`);

    results.push({ route, expectedText, direction });
  }

  const markerVisible = await bodyText();
  assert(markerVisible.length > 0, 'The final HR report rendered no content.');

  const actionableErrors = [...new Set(browserErrors)].filter((message) =>
    message && !/favicon\.ico|ResizeObserver loop|ERR_ABORTED/i.test(message));
  for (const [category, values] of Object.entries(runtimeErrors)) {
    assert(values.length === 0, `${category} errors: ${values.join(' | ')}`);
  }
  assert(actionableErrors.length === 0, `Browser errors: ${actionableErrors.join(' | ')}`);

  await captureScreenshot('hr-full-cycle-final.png');
  await writeFile(path.join(artifactDirectory, 'hr-e2e-result.json'), JSON.stringify({
    locale: 'ar',
    routes: results,
    browserErrors: actionableErrors,
    runtimeErrors,
  }, null, 2));
} catch (error) {
  await captureScreenshot('hr-full-cycle-failure.png').catch(() => {});
  await writeFile(path.join(artifactDirectory, 'hr-e2e-failure.txt'), String(error?.stack || error)).catch(() => {});
  throw error;
} finally {
  client.close();
}
