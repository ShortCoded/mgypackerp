import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.ERP_BROWSER_BASE_URL || 'http://127.0.0.1:8765').replace(/\/$/, '');
const debuggerUrl = (process.env.ERP_BROWSER_DEBUG_URL || 'http://127.0.0.1:9222').replace(/\/$/, '');
const artifactDirectory = process.env.ERP_BROWSER_ARTIFACT_DIR || 'storage/app/test-artifacts/integrated-plastic-factory';
const login = process.env.ERP_BROWSER_LOGIN || 'demo.full@shortcoded.test';
const password = process.env.ERP_BROWSER_PASSWORD || 'RuntimeDemo2026!';

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
const consoleErrors = [];
const failedRequests = [];
const badResponses = [];
client.on('Page.loadEventFired', () => { loadCount += 1; });
client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
  consoleErrors.push(exceptionDetails?.exception?.description || exceptionDetails?.text || 'Unhandled JavaScript exception');
});
client.on('Runtime.consoleAPICalled', ({ type, args }) => {
  if (['error', 'assert'].includes(type)) {
    consoleErrors.push(args.map((argument) => argument.value || argument.description || '').join(' '));
  }
});
client.on('Log.entryAdded', ({ entry }) => {
  if (entry.level === 'error' && !entry.url?.endsWith('/favicon.ico')) {
    consoleErrors.push(entry.url ? `${entry.url}: ${entry.text}` : entry.text);
  }
});
client.on('Network.loadingFailed', ({ type, canceled, errorText }) => {
  if (!canceled && ['Document', 'XHR', 'Fetch'].includes(type)) failedRequests.push(`${type}: ${errorText}`);
});
client.on('Network.responseReceived', ({ response, type }) => {
  if (response?.url?.startsWith(baseUrl) && response.status >= 400 && ['Document', 'XHR', 'Fetch'].includes(type)) {
    badResponses.push(`${response.status} ${response.url}`);
  }
});

await Promise.all([
  client.send('Page.enable'),
  client.send('Runtime.enable'),
  client.send('Log.enable'),
  client.send('Network.enable'),
]);

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

async function waitUntil(callback, message, timeout = 45000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    try {
      if (await callback()) return;
    } catch {
      // Navigation can briefly replace the execution context while polling.
    }
    await sleep(125);
  }
  throw new Error(message);
}

async function viewport(width, height, mobile = false) {
  await client.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
}

async function navigate(route) {
  const url = `${baseUrl}${route}`;
  const before = loadCount;
  const beforeTimeOrigin = await evaluate('performance.timeOrigin');
  const result = await client.send('Page.navigate', { url });
  assert(!result.errorText, `${route}: ${result.errorText}`);
  await waitUntil(async () => loadCount > before || await evaluate(`performance.timeOrigin !== ${JSON.stringify(beforeTimeOrigin)}`), `Navigation did not complete: ${route}`);
  await waitUntil(() => evaluate(`document.readyState === 'complete' && Boolean(document.body)`), `Page was not ready: ${route}`);
  await sleep(250);
}

async function setField(selector, value) {
  const updated = await evaluate(`(() => {
    const field = document.querySelector(${JSON.stringify(selector)});
    if (!field) return false;
    field.value = ${JSON.stringify(value)};
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  })()`);
  assert(updated, `Missing login field: ${selector}`);
}

async function fetchBinary(route) {
  return evaluate(`(async () => {
    const response = await fetch(${JSON.stringify(route)}, { credentials: 'same-origin' });
    const bytes = new Uint8Array(await response.arrayBuffer());
    return {
      status: response.status,
      redirected: response.redirected,
      url: response.url,
      type: response.headers.get('content-type') || '',
      disposition: response.headers.get('content-disposition') || '',
      signature: String.fromCharCode(...bytes.slice(0, 5)),
      size: bytes.length,
    };
  })()`);
}

await viewport(1440, 1000);
await navigate('/login');
if (!(await evaluate(`Boolean(document.querySelector('[name="login"]'))`))) {
  const logout = await evaluate(`(async () => {
    const tokenResponse = await fetch('/auth/csrf-token', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const token = (await tokenResponse.json()).csrf_token;
    const response = await fetch('/logout', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'text/html', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
    });
    return { status: response.status, url: response.url };
  })()`);
  assert(logout.status >= 200 && logout.status < 400, `Existing browser session logout failed: ${JSON.stringify(logout)}`);
  await navigate('/login');
}
assert(await evaluate(`Boolean(document.querySelector('[name="login"]'))`), 'Login screen did not expose its form.');
await setField('[name="login"]', login);
await setField('[name="password"]', password);
assert(await evaluate(`(() => { const form = document.querySelector('form'); if (!form) return false; form.requestSubmit(); return true; })()`), 'Login form missing.');
await waitUntil(() => evaluate(`!location.pathname.includes('/login')`), 'Persistent demo login failed.');
await waitUntil(() => evaluate(`document.readyState === 'complete' && Boolean(document.body)`), 'Dashboard was not ready after login.');

async function selectRuntimeOperatingContext() {
  return evaluate(`(async () => {
  const tokenResponse = await fetch('/auth/csrf-token', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  });
  const token = (await tokenResponse.json()).csrf_token;
  const response = await fetch('/admin/operating-context/select', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token,
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify({
      company_doc_num: 'Company-00002',
      branch_doc_num: 'Branch-00002',
      financial_period_doc_num: 'Period-00002',
    }),
  });
  return { status: response.status, body: await response.text() };
})()`);
}

let operatingContext = await selectRuntimeOperatingContext();
assert(operatingContext.status >= 200 && operatingContext.status < 300, `Operating context failed: ${JSON.stringify(operatingContext)}`);
assert(JSON.parse(operatingContext.body)?.data?.current?.company?.name === 'Mgy Plast Manufacturing - Runtime Demo', `Unexpected selected company: ${operatingContext.body}`);
await sleep(350);
operatingContext = await selectRuntimeOperatingContext();
assert(operatingContext.status >= 200 && operatingContext.status < 300, `Operating context confirmation failed: ${JSON.stringify(operatingContext)}`);
await navigate('/dashboard');
assert(await evaluate(`document.body.innerText.includes('Mgy Plast Manufacturing - Runtime Demo')`), 'Dashboard did not retain the runtime demo company context.');

const pages = [
  ['/dashboard', 'Dashboard'],
  ['/admin/sales/quotations', 'Sales / Quotations'],
  ['/admin/sales/quotations/create', 'Sales / New Quotation'],
  ['/admin/sales/sales-orders', 'Sales / Orders'],
  ['/admin/sales/sales-orders/create', 'Sales / New Order'],
  ['/admin/sales/customer-invoices', 'Sales / Invoices'],
  ['/admin/purchases/purchase-requisitions', 'Purchases / Requisitions'],
  ['/admin/purchases/purchase-requisitions/create', 'Purchases / New Requisition'],
  ['/admin/purchases/purchase-orders/create', 'Purchases / New Order'],
  ['/admin/purchases/procurement-cycle-report', 'Purchases / Cycle Report'],
  ['/admin/inventory/documents/create', 'Inventory / New Document'],
  ['/admin/inventory/reports/operations', 'Inventory / Operations'],
  ['/admin/production/work-orders', 'Production / Orders'],
  ['/admin/fixed-assets/assets', 'Fixed Assets / Register'],
  ['/admin/fixed-assets/assets/create', 'Fixed Assets / New Asset'],
  ['/admin/fixed-assets/assets/FA-00003/card', 'Fixed Assets / Asset Card'],
  ['/admin/fixed-assets/accounting-mappings', 'Fixed Assets / Accounting'],
  ['/admin/fixed-assets/depreciation', 'Fixed Assets / Depreciation'],
  ['/admin/fixed-assets/reports', 'Fixed Assets / Reports'],
  ['/admin/accounting/journal-entries', 'Accounting / Journals'],
  ['/admin/accounting/reports/account-ledger', 'Accounting / Ledger'],
  ['/admin/sales/customer-contracts', 'Restored Sales Shell'],
  ['/admin/production/material-requirements-planning', 'Restored Production Shell'],
];
const results = [];

for (const [route, label] of pages) {
  await navigate(route);
  const state = await evaluate(`(() => ({
    path: location.pathname,
    title: document.title,
    heading: document.querySelector('h1, h2, .page-title')?.innerText?.trim() || '',
    text: document.body.innerText.slice(0, 12000),
    links: document.querySelectorAll('a[href]').length,
    disabledLinks: [...document.querySelectorAll('a[href]')].filter((link) => ['#', 'javascript:void(0)', 'javascript:;'].includes(link.getAttribute('href'))).length,
  }))()`);
  assert(state.path === route, `${label} redirected unexpectedly to ${state.path}.`);
  assert(!/(SQLSTATE|Server Error|Undefined variable|Call to undefined|DataTables warning|Not Implemented|Lorem ipsum|403 Forbidden|Unauthorized)/i.test(state.text), `${label} exposed an implementation/runtime failure: ${state.text.slice(0, 500)}`);
  assert(state.text.length > 100 && state.links > 0, `${label} did not render a usable ERP screen: ${state.text.slice(0, 500)}`);
  results.push({ label, route, title: state.title, heading: state.heading, links: state.links, disabledLinks: state.disabledLinks });
}

const procurementChainPages = [
  ['/admin/purchases/purchase-requisitions/PR-00003', 'PR-00003'],
  ['/admin/purchases/request-for-quotations/RFQ-00002', 'RFQ-00002'],
  ['/admin/purchases/supplier-quotation-entry/SQT-00003', 'SQT-00003'],
  ['/admin/purchases/supplier-quotation-comparison/RFQ-00002', 'RFQ-00002'],
  ['/admin/purchases/supplier-selection/SEL-00002', 'SEL-00002'],
  ['/admin/purchases/purchase-orders/PO-00002-00002', 'PO-00002-00002'],
  ['/admin/purchases/purchase-orders/PO-00002-00006', 'PO-00002-00006'],
  ['/admin/purchases/purchase-order-change-requests/POC-00001', 'POC-00001'],
  ['/admin/purchases/goods-receipt-notes/UIR-00002', 'UIR-00002'],
  ['/admin/purchases/goods-receipt-inspection/QCI-00002', 'QCI-00002'],
  ['/admin/purchases/purchase-invoices/PINV-00002-00002', 'PINV-00002-00002'],
  ['/admin/purchases/supplier-payments/SPAY-00002', 'SPAY-00002'],
  ['/admin/purchases/purchase-returns/PRET-00001', 'PRET-00001'],
  ['/admin/purchases/purchase-returns/PRET-00003', 'PRET-00003'],
  ['/admin/accounting/reports/supplier-statement?run=1&from_date=2026-01-01&to_date=2026-12-31&supplier_doc_num=Supplier-00001', 'Supplier-00001'],
  ['/admin/fixed-assets/assets/FA-00011/card', 'FA-00011'],
];
const procurementChain = [];
for (const [route, documentNumber] of procurementChainPages) {
  await navigate(route);
  const state = await evaluate(`({ path: location.pathname, text: document.body.innerText.slice(0, 20000) })`);
  assert(!/(SQLSTATE|Server Error|Undefined variable|Call to undefined|DataTables warning|403 Forbidden|Unauthorized|Unprocessable Content)/i.test(state.text), `${documentNumber} exposed a browser failure: ${state.text.slice(0, 500)}`);
  assert(state.text.includes(documentNumber), `${documentNumber} was not visible after reload.`);
  procurementChain.push({ route, documentNumber, path: state.path });
}

const pdfRoutes = [
  ['/admin/purchases/procurement-print/purchase-requisition/PR-00003', 'Purchase Requisition PR-00003'],
  ['/admin/purchases/procurement-print/request-for-quotation/RFQ-00002', 'RFQ RFQ-00002'],
  ['/admin/purchases/procurement-print/supplier-quotation/SQT-00003', 'Supplier Quotation SQT-00003'],
  ['/admin/purchases/procurement-print/quotation-comparison/RFQ-00002', 'Quotation Comparison RFQ-00002'],
  ['/admin/purchases/procurement-print/supplier-selection/SEL-00002', 'Supplier Selection SEL-00002'],
  ['/admin/purchases/purchase-orders/PO-00002-00002/print', 'Purchase Order PO-00002-00002'],
  ['/admin/purchases/procurement-print/purchase-order-change-request/POC-00001', 'Purchase Order Change POC-00001'],
  ['/admin/purchases/procurement-print/purchase-order-delivery-schedule/PO-00002-00002', 'Delivery Schedule PO-00002-00002'],
  ['/admin/purchases/procurement-print/goods-receipt/UIR-00002', 'GRN UIR-00002'],
  ['/admin/purchases/procurement-print/goods-receipt-inspection/QCI-00002', 'Incoming QC QCI-00002'],
  ['/admin/purchases/purchase-invoices/PINV-00002-00002/print', 'Purchase Invoice PINV-00002-00002'],
  ['/admin/purchases/procurement-print/supplier-payment/SPAY-00002', 'Cash Supplier Payment SPAY-00002'],
  ['/admin/purchases/procurement-print/supplier-payment/SPAY-00003', 'Bank Supplier Payment SPAY-00003'],
  ['/admin/purchases/procurement-print/supplier-payment/SPAY-00004', 'Cheque Supplier Payment SPAY-00004'],
  ['/admin/finance/cash-payment-vouchers/CPV-00002/print', 'Cash Payment Voucher CPV-00002'],
  ['/admin/finance/cheques/OCH-00001/print', 'Outgoing Cheque OCH-00001'],
  ['/admin/purchases/procurement-print/purchase-return/PRET-00001', 'Pre-Invoice Return PRET-00001'],
  ['/admin/purchases/procurement-print/purchase-return/PRET-00003', 'Post-Invoice Return PRET-00003'],
  ['/admin/purchases/procurement-cycle-report/print?report_type=supplier_aging', 'Supplier Aging Report'],
  ['/admin/accounting/reports/supplier-statement/export/pdf?run=1&from_date=2026-01-01&to_date=2026-12-31&supplier_doc_num=Supplier-00001', 'Supplier Statement Supplier-00001'],
  ['/admin/fixed-assets/assets/FA-00011/print', 'Fixed Asset Card FA-00011'],
  ['/admin/fixed-assets/movements/FAD-MOV-00001/print', 'Fixed Asset Transfer FAD-MOV-00001'],
  ['/admin/fixed-assets/disposals/FAD-DSP-00001/print', 'Fixed Asset Sale FAD-DSP-00001'],
  ['/admin/fixed-assets/disposals/FAD-DSP-00003/print', 'Fixed Asset Write-Off FAD-DSP-00003'],
  ['/admin/fixed-assets/depreciation/runs/FAD-DEP-00002/print', 'Fixed Asset Depreciation FAD-DEP-00002'],
];
const pdfs = [];
for (const [route, label] of pdfRoutes) {
  const pdf = await fetchBinary(route);
  assert(pdf.status === 200, `${label} returned HTTP ${pdf.status}: ${pdf.url}`);
  assert(pdf.type.toLowerCase().includes('application/pdf'), `${label} returned ${pdf.type}.`);
  assert(pdf.disposition.toLowerCase().includes('inline'), `${label} was not streamed inline.`);
  assert(pdf.signature === '%PDF-', `${label} did not return a PDF signature.`);
  assert(pdf.size > 1000, `${label} returned an unexpectedly small PDF.`);
  pdfs.push({ label, route, ...pdf });
}

await navigate('/dashboard');
const navigationLinks = await evaluate(`[...document.querySelectorAll('a[href]')].map((link) => link.href)`);
for (const [moduleName, pathPart] of [
  ['Sales', '/admin/sales/'],
  ['Purchases', '/admin/purchases/'],
  ['Inventory', '/admin/inventory/'],
  ['Production', '/admin/production/'],
  ['Fixed Assets', '/admin/fixed-assets/'],
  ['Accounting', '/admin/accounting/'],
]) {
  assert(navigationLinks.some((link) => link.includes(pathPart)), `Navigation is missing ${moduleName}.`);
}
const desktopScreenshot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
await writeFile(path.join(artifactDirectory, 'dashboard-desktop.png'), Buffer.from(desktopScreenshot.data, 'base64'));

await viewport(390, 844, true);
for (const route of ['/dashboard', '/admin/sales/sales-orders', '/admin/purchases/purchase-requisitions']) {
  await navigate(route);
  const mobile = await evaluate(`(() => ({
    path: location.pathname,
    bodyWidth: document.body.scrollWidth,
    viewportWidth: document.documentElement.clientWidth,
    visibleControls: [...document.querySelectorAll('button, a, input, select')].filter((element) => {
      const rect = element.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0 && rect.bottom >= 0 && rect.top <= innerHeight;
    }).length,
  }))()`);
  assert(mobile.path === route, `Mobile navigation redirected unexpectedly: ${route}.`);
  assert(mobile.visibleControls > 0, `Mobile page has no visible interactive controls: ${route}.`);
}
const mobileScreenshot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
await writeFile(path.join(artifactDirectory, 'purchases-mobile.png'), Buffer.from(mobileScreenshot.data, 'base64'));

const unique = (values) => [...new Set(values.filter(Boolean))];
const report = {
  baseUrl,
  pages: results,
  procurementChain,
  pdfs,
  consoleErrors: unique(consoleErrors),
  failedRequests: unique(failedRequests),
  badResponses: unique(badResponses),
};
await writeFile(path.join(artifactDirectory, 'browser-report.json'), `${JSON.stringify(report, null, 2)}\n`);

assert(report.consoleErrors.length === 0, `Browser console errors: ${report.consoleErrors.join(' | ')}`);
assert(report.failedRequests.length === 0, `Failed browser requests: ${report.failedRequests.join(' | ')}`);
assert(report.badResponses.length === 0, `HTTP failures: ${report.badResponses.join(' | ')}`);

client.close();
process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
