import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.MFG_E2E_BASE_URL || 'http://127.0.0.1:8790').replace(/\/$/, '');
const debuggerUrl = (process.env.MFG_E2E_DEBUG_URL || 'http://127.0.0.1:9250').replace(/\/$/, '');
const artifactDirectory = process.env.MFG_E2E_ARTIFACT_DIR;

if (!artifactDirectory) {
  throw new Error('MFG_E2E_ARTIFACT_DIR is required.');
}

await mkdir(artifactDirectory, { recursive: true });

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};
const localDate = (daysFromToday = 0) => {
  const date = new Date();
  date.setDate(date.getDate() + daysFromToday);

  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
  ].join('-');
};
const localDateTime = (daysFromToday, hour, minute = 0) => `${localDate(daysFromToday)}T${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;

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
        message.error ? pending.reject(new Error(`${pending.method}: ${message.error.message}`)) : pending.resolve(message.result || {});
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
    this.listeners.set(method, [...(this.listeners.get(method) || []), listener]);
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
let csrfToken = '';
let mobileQualityCapture = null;
const errors = { javascript: [], console: [], failedXhr: [], dataTable: [], select2: [], responses500: [], sqlState: [] };
client.on('Page.loadEventFired', () => { loadCount += 1; });
client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => errors.javascript.push(exceptionDetails?.exception?.description || exceptionDetails?.text || 'Unhandled JavaScript exception'));
client.on('Runtime.consoleAPICalled', ({ type, args }) => {
  if (!['error', 'assert'].includes(type)) return;
  const message = args.map((argument) => argument.value || argument.description || '').join(' ');
  errors.console.push(message);
  if (/DataTable/i.test(message)) errors.dataTable.push(message);
  if (/Select2/i.test(message)) errors.select2.push(message);
});
client.on('Network.loadingFailed', ({ type, canceled, errorText, requestId }) => {
  if (['XHR', 'Fetch'].includes(type) && canceled !== true) errors.failedXhr.push(errorText || requestId);
});
client.on('Network.responseReceived', ({ response }) => {
  if (response?.url?.startsWith(baseUrl) && response.status >= 500) errors.responses500.push(`${response.status} ${response.url}`);
});

await Promise.all([
  client.send('Page.enable'),
  client.send('Runtime.enable'),
  client.send('Log.enable'),
  client.send('Network.enable'),
]);
const mobileQa = process.env.MFG_E2E_MOBILE === '1';
await client.send('Emulation.setDeviceMetricsOverride', mobileQa
  ? { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }
  : { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });

async function evaluate(expression) {
  const result = await client.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'Browser evaluation failed.');
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

async function navigate(route) {
  const url = route.startsWith('http') ? route : `${baseUrl}${route}`;
  const before = loadCount;
  await client.send('Page.navigate', { url });
  await waitUntil(() => loadCount > before, `Navigation did not complete: ${url}`);
  await waitUntil(() => evaluate(`document.readyState === 'complete' && Boolean(document.body)`), `Page was not ready: ${url}`);
  await sleep(150);
  const renderedCsrfToken = await evaluate(`document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value || ''`);
  if (renderedCsrfToken) csrfToken = renderedCsrfToken;
  const body = await evaluate('document.body.innerText');
  if (/SQLSTATE\[|Stack trace:|Internal Server Error/i.test(body)) errors.sqlState.push(url);
  return evaluate('location.href');
}

async function setField(selector, value) {
  const changed = await evaluate(`(() => { const field = document.querySelector(${JSON.stringify(selector)}); if (!field) return false; field.value = ${JSON.stringify(String(value))}; field.dispatchEvent(new Event('input', { bubbles: true })); field.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
  assert(changed, `Missing field ${selector}`);
}

async function submitNavigation(selector, context) {
  const before = loadCount;
  const submitted = await evaluate(`(() => { const form = document.querySelector(${JSON.stringify(selector)}); if (!form) return false; form.requestSubmit(); return true; })()`);
  assert(submitted, `${context}: form not found`);
  await waitUntil(() => loadCount > before, `${context}: navigation did not occur`);
  await waitUntil(() => evaluate(`document.readyState === 'complete'`), `${context}: redirected page did not finish`);
  const renderedCsrfToken = await evaluate(`document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value || ''`);
  if (renderedCsrfToken) csrfToken = renderedCsrfToken;
}

async function post(route, entries = [], { expectedError = false, file = false } = {}) {
  const result = await evaluate(`(async () => {
    const data = new FormData();
    const requestCsrfToken = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value || ${JSON.stringify(csrfToken)};
    data.append('_token', requestCsrfToken);
    for (const [name, value] of ${JSON.stringify(entries)}) data.append(name, value);
    if (${file}) {
      const bytes = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2n0sAAAAASUVORK5CYII='), (character) => character.charCodeAt(0));
      data.append('evidence_files[]', new File([bytes], 'qc-evidence.png', { type: 'image/png' }));
    }
    const response = await fetch(${JSON.stringify(`${baseUrl}${route}`)}, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': requestCsrfToken } });
    const text = await response.text();
    let payload = null;
    try { payload = JSON.parse(text); } catch (error) {}
    return { status: response.status, payload, text: text.slice(0, 2000), url: response.url };
  })()`);
  if (/SQLSTATE\[/i.test(result.text)) errors.sqlState.push(route);
  if (expectedError) {
    assert(result.status === 422, `Expected 422 from ${route}, received ${result.status}: ${result.text}`);
  } else {
    assert(result.status >= 200 && result.status < 300, `POST ${route} failed ${result.status}: ${result.text}`);
  }
  return result;
}

async function postVisibleForm(actionFragment, overrides = []) {
  const result = await evaluate(`(async () => {
    const form = Array.from(document.forms).find((candidate) =>
      candidate.hasAttribute('action')
        && candidate.method.toLowerCase() !== 'get'
        && candidate.action.includes(${JSON.stringify(actionFragment)})
    );
    if (!form) return { missing: true };
    const data = new FormData(form);
    const requestCsrfToken = data.get('_token') || document.querySelector('meta[name="csrf-token"]')?.content || ${JSON.stringify(csrfToken)};
    data.set('_token', requestCsrfToken);
    for (const [name, value] of ${JSON.stringify(overrides)}) data.set(name, value);
    const response = await fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': requestCsrfToken } });
    const text = await response.text();
    let payload = null;
    try { payload = JSON.parse(text); } catch (error) {}
    return { status: response.status, payload, text: text.slice(0, 2000) };
  })()`);
  assert(!result.missing, `Visible form not found for ${actionFragment}`);
  assert(result.status >= 200 && result.status < 300, `Visible form ${actionFragment} failed ${result.status}: ${result.text}`);
  return result;
}

async function streamPdf(route, filename, visuallyOpen = true) {
  const url = route.startsWith('http') ? route : `${baseUrl}${route}`;
  const { cookies } = await client.send('Network.getAllCookies');
  const response = await fetch(url, {
    headers: {
      Accept: 'application/pdf',
      Cookie: cookies.map((cookie) => `${cookie.name}=${cookie.value}`).join('; '),
    },
  });
  const bytes = Buffer.from(await response.arrayBuffer());
  const contentType = response.headers.get('content-type') || '';
  const disposition = response.headers.get('content-disposition') || '';
  assert(response.ok, `mPDF stream ${route} failed ${response.status}: ${bytes.toString('utf8', 0, 1000)}`);
  assert(contentType.includes('application/pdf'), `${route} did not stream application/pdf: ${contentType}`);
  assert(disposition.toLowerCase().includes('inline'), `${route} was not inline: ${disposition}`);
  assert(bytes.subarray(0, 5).toString('ascii') === '%PDF-', `${route} did not return an mPDF PDF signature.`);
  await writeFile(path.join(artifactDirectory, filename), bytes);

  if (visuallyOpen) {
    await client.send('Page.navigate', { url });
    await sleep(500);
  }
}

async function downloadAuthenticated(route, filename, expectedContentType, expectedMagic) {
  const url = route.startsWith('http') ? route : `${baseUrl}${route}`;
  const { cookies } = await client.send('Network.getAllCookies');
  const response = await fetch(url, {
    headers: { Cookie: cookies.map((cookie) => `${cookie.name}=${cookie.value}`).join('; ') },
  });
  const bytes = Buffer.from(await response.arrayBuffer());
  assert(response.ok, `Download ${route} failed ${response.status}: ${bytes.toString('utf8', 0, 1000)}`);
  assert((response.headers.get('content-type') || '').includes(expectedContentType), `${route} returned the wrong content type.`);
  assert(bytes.subarray(0, expectedMagic.length).toString('binary') === expectedMagic, `${route} returned the wrong file signature.`);
  await writeFile(path.join(artifactDirectory, filename), bytes);
}

async function assertBody(text, context) {
  const body = await evaluate('document.body.innerText');
  assert(body.includes(text), `${context} did not contain ${text}`);
}

async function assertBodyInsensitive(text, context) {
  const body = await evaluate('document.body.innerText');
  assert(body.toLowerCase().includes(text.toLowerCase()), `${context} did not contain ${text}`);
}

async function remoteOption(selector, query, extra = {}) {
  const option = await evaluate(`(async () => {
    const field = document.querySelector(${JSON.stringify(selector)});
    if (!field?.dataset.url) return null;
    const url = new URL(field.dataset.url, location.origin);
    url.searchParams.set('q', ${JSON.stringify(query)});
    for (const [name, value] of Object.entries(${JSON.stringify(extra)})) url.searchParams.set(name, value);
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const payload = await response.json();
    const result = (payload.results || []).find((item) => String(item.text || '').includes(${JSON.stringify(query)})) || payload.results?.[0];
    return result ? { id: String(result.id), text: String(result.text || '') } : null;
  })()`);
  assert(option?.id, `Remote option ${query} was not available for ${selector}`);
  return option;
}

async function openSalesOrder(customerName) {
  await navigate(`/admin/sales/sales-orders?customer=${encodeURIComponent(customerName)}`);
  const url = await evaluate(`(() => {
    const row = Array.from(document.querySelectorAll('tbody tr')).find((candidate) => candidate.innerText.includes(${JSON.stringify(customerName)}));
    return row?.querySelector('td a')?.href || null;
  })()`);
  assert(url, `Approved Sales order for ${customerName} was not visible.`);
  await navigate(url);
  await assertBody('Approved', `${customerName} approved Sales order`);
  return new URL(url).pathname;
}

async function createProductionRequirement(customerName, quantity) {
  const salesOrderUrl = await openSalesOrder(customerName);
  const result = await postVisibleForm('/production-requests', [['lines[0][quantity]', String(quantity)]]);
  assert(result.payload?.data?.url, `${customerName}: Production requirement URL was missing.`);
  const productionUrl = new URL(result.payload.data.url).pathname;
  await navigate(productionUrl);
  await assertBody('Sales Order', `${customerName} Production requirement lineage`);
  return { salesOrderUrl, productionUrl, productionDoc: productionUrl.split('/').pop() };
}

async function submitQualityInspection(inspectionUrl, { result = 'passed', evidence = false, notes = 'Quality sample completed' } = {}) {
  await post(`${inspectionUrl}/receive`);
  await post(`${inspectionUrl}/start`);
  if (evidence) {
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await navigate(inspectionUrl);
    mobileQualityCapture = await evaluate(`(() => { const input = document.querySelector('input[name="evidence_files[]"][capture="environment"]'); const actions = input?.closest('.quality-capture-actions'); const rect = actions?.getBoundingClientRect(); return { exists: Boolean(input), right: rect?.right || 0, width: innerWidth, capture: input?.getAttribute('capture'), overflow: document.documentElement.scrollWidth - innerWidth }; })()`);
    assert(mobileQualityCapture.exists && mobileQualityCapture.right <= mobileQualityCapture.width + 1 && mobileQualityCapture.capture === 'environment' && mobileQualityCapture.overflow <= 1, `Mobile QC capture failed: ${JSON.stringify(mobileQualityCapture)}`);
    if (!mobileQa) {
      await client.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    }
  }
  await post(inspectionUrl + '/submit', [
    ['result', result],
    ['disposition', result === 'passed' ? 'release' : 'rework'],
    ['defect_code', result === 'passed' ? '' : 'E2E-QC'],
    ['affected_base_quantity', result === 'passed' ? '0' : '5'],
    ['corrective_action', result === 'passed' ? '' : 'Adjust the machine and inspect a new sample'],
    ['notes', notes],
  ], { file: evidence });
  if (result === 'passed') {
    await post(`${inspectionUrl}/approve`);
  } else {
    await post(`${inspectionUrl}/reject`, [['reason', 'Corrective action and reinspection are required']]);
  }
  await post(`${inspectionUrl}/close`, [['close_notes', 'Inspection reviewed and closed']]);
  return inspectionUrl;
}

async function createQualityInspection(runUrl, options = {}) {
  await navigate(runUrl);
  const createUrl = await evaluate(`document.querySelector('a[href*="/admin/production/quality/create"]')?.href || null`);
  assert(createUrl, `${runUrl}: controlled Quality request action was not available.`);
  await navigate(createUrl);
  const requestValues = await evaluate(`(() => {
    const run = document.querySelector('[name="production_run_id"]')?.value;
    return { run, type: '' };
  })()`);
  assert(requestValues.run, `${runUrl}: Quality request did not preselect its Production run.`);
  const created = await post('/admin/production/quality', [
    ['production_run_id', requestValues.run],
    ['quality_inspection_type_id', requestValues.type],
    ['affected_base_quantity', options.result === 'failed' ? '5' : '0'],
    ['notes', options.notes || 'Production requested a controlled Quality sample'],
  ]);
  const inspectionUrl = new URL(created.url).pathname;
  assert(/\/admin\/production\/quality\/\d+$/.test(inspectionUrl), `Quality request did not redirect to an inspection: ${created.url}`);
  return submitQualityInspection(inspectionUrl, options);
}

async function logoutCurrentUser() {
  const status = await evaluate(`(async () => {
    const data = new FormData();
    const requestCsrfToken = document.querySelector('meta[name="csrf-token"]')?.content || ${JSON.stringify(csrfToken)};
    data.append('_token', requestCsrfToken);
    const response = await fetch(${JSON.stringify(`${baseUrl}/logout`)}, { method: 'POST', body: data, headers: { 'X-CSRF-TOKEN': requestCsrfToken } });
    return response.status;
  })()`);
  assert(status >= 200 && status < 400, `Logout failed with ${status}.`);
}

async function loginAs(username, password = 'e2e-password') {
  await navigate('/login');
  await setField('[name="login"]', username);
  await setField('[name="password"]', password);
  await submitNavigation('form', `${username} login`);
  assert(!(await evaluate('location.pathname')).includes('/login'), `${username} login failed.`);
}

async function browserResponseStatus(route) {
  return evaluate(`fetch(${JSON.stringify(`${baseUrl}${route}`)}, { headers: { Accept: 'text/html' } }).then((response) => response.status)`);
}

if (process.env.MFG_E2E_POSTCHECK_ONLY === '1') {
  try {
    const orderDoc = process.env.MFG_E2E_ORDER_DOC;
    const run1Id = process.env.MFG_E2E_RUN1_ID;
    const run2Id = process.env.MFG_E2E_RUN2_ID;
    const run1Number = process.env.MFG_E2E_RUN1_NUMBER;
    const run2Number = process.env.MFG_E2E_RUN2_NUMBER;
    const receiptDoc = process.env.MFG_E2E_RECEIPT_DOC;
    assert([orderDoc, run1Id, run2Id, run1Number, run2Number, receiptDoc].every(Boolean), 'Postcheck identifiers are required.');

    await navigate('/admin/production/reports/operations');
    if ((await evaluate('location.pathname')).includes('/login')) {
      await setField('[name="login"]', 'admin');
      await setField('[name="password"]', 'admin');
      await submitNavigation('form', 'Login');
    }
    await navigate('/lang/en');

    if (process.env.MFG_E2E_EXPECT_UNCONFIGURED_REPORT === '1') {
      await navigate('/admin/inventory/reports/operations');
      await assertBody('raw_material_inventory', 'Unconfigured Inventory report classification guidance');
      await assertBody('Create or update one active postable account in the chart of accounts', 'Unconfigured Inventory report remediation');
      await assertBody('E2E PP Raw Material', 'Unconfigured Inventory operational balances');
      const reconciliationBadges = await evaluate(`Array.from(document.querySelectorAll('.badge')).map((node) => node.innerText.trim()).filter((text) => text === 'Reconciled')`);
      assert(reconciliationBadges.length === 0, `Unconfigured report falsely displayed reconciliation: ${JSON.stringify(reconciliationBadges)}`);
      await downloadAuthenticated('/admin/inventory/reports/operations/export.xlsx', 'inventory-operations-unconfigured.xlsx', 'spreadsheetml', 'PK');
      await streamPdf('/admin/inventory/reports/operations/print', 'inventory-operations-unconfigured.pdf');
    }

    await navigate('/admin/production/reports/operations');
    for (const text of [run1Number, run2Number, 'Material Requirements, Consumption and Variance']) await assertBody(text, 'Production report');
    await navigate(`/admin/production/work-orders/${orderDoc}`);
    await assertBody('Completed', 'Completed production order');
    await streamPdf(`/admin/inventory/documents/${receiptDoc}/print`, 'finished-goods-receipt-en.pdf');
    await streamPdf(`/admin/production/runs/${run1Id}/print`, 'production-run-en.pdf');
    await navigate('/lang/ar');
    await streamPdf(`/admin/production/runs/${run1Id}/print`, 'production-run-ar.pdf');
    await streamPdf(`/admin/inventory/documents/${receiptDoc}/print`, 'finished-goods-receipt-ar.pdf');

    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await navigate('/admin/production/quality');
    const mobileQc = await evaluate(`(() => { const table = document.querySelector('.erp-datatable'); const rect = table?.getBoundingClientRect(); return { exists: Boolean(table), right: rect?.right || 0, width: innerWidth, overflow: document.documentElement.scrollWidth - innerWidth }; })()`);
    assert(mobileQc.exists && mobileQc.right <= mobileQc.width + 1 && mobileQc.overflow <= 1, `Mobile Quality list failed: ${JSON.stringify(mobileQc)}`);

    for (const [category, values] of Object.entries(errors)) assert(values.length === 0, `${category} errors: ${JSON.stringify(values)}`);
    const result = { orderDoc, runs: [run1Number, run2Number], receiptDoc, mobileQc, errors };
    await writeFile(path.join(artifactDirectory, 'postcheck-result.json'), JSON.stringify(result, null, 2));
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  } catch (error) {
    await writeFile(path.join(artifactDirectory, 'postcheck-failure.txt'), `${error.stack || error}\nURL: ${await evaluate('location.href').catch(() => 'unknown')}\n${await evaluate('document.body.innerText').catch(() => '')}`);
    throw error;
  } finally {
    client.close();
  }
  process.exit(0);
}

try {
  await navigate('/login');
  await setField('[name="login"]', 'admin');
  await setField('[name="password"]', 'admin');
  await submitNavigation('form', 'Login');
  assert(!(await evaluate('location.pathname')).includes('/login'), 'Admin login failed.');
  await navigate('/lang/en');

  const visibleRoutes = [
    '/admin/inventory/documents', '/admin/inventory/stock-counts', '/admin/inventory/reports/operations',
    '/admin/production/stages', '/admin/production/work-orders', '/admin/production/runs', '/admin/production/quality', '/admin/production/reports/operations',
  ];
  for (const route of visibleRoutes) {
    await navigate(route);
    const body = await evaluate('document.body.innerText');
    assert(!/ERP UI Shell|interface only|placeholder/i.test(body), `Shell content remained visible at ${route}`);
  }

  assert((await browserResponseStatus('/admin/inventory/accounting')) === 404, 'Removed Inventory accounting mapping screen is still reachable.');
  assert((await browserResponseStatus('/admin/inventory/warehouse-locations')) === 404, 'Removed Warehouse Locations shell is still reachable.');
  assert((await browserResponseStatus('/admin/production/resources')) === 404, 'Removed Production Resources shell is still reachable.');
  assert((await browserResponseStatus('/admin/production/identifiers')) === 404, 'Removed Production Identifiers shell is still reachable.');

  const openingProducts = [
    ['Product-E2E-MFG-PP', '1000', 'E2E-PP-OPENING'],
    ['Product-E2E-MFG-MB', '100', 'E2E-MB-OPENING'],
    ['Product-E2E-MFG-CARTON', '500', 'E2E-CARTON-OPENING'],
    ...Array.from({ length: 7 }, (_, index) => [
      `Product-E2E-PACK-${String(index + 1).padStart(2, '0')}`,
      index === 3 ? '1005' : '1000',
      index === 0 ? 'WRAP-CUSTOMER-A-001' : `PACK-COMP-${String(index + 1).padStart(2, '0')}`,
    ]),
    ...Array.from({ length: 25 }, (_, index) => [
      `Product-E2E-25-COMP-${String(index + 1).padStart(2, '0')}`,
      '5',
      `STRESS-${String(index + 1).padStart(2, '0')}`,
    ]),
  ];
  await navigate('/admin/inventory/opening-stocks/create');
  const openingOverrides = [
    ['branch_store_uuid', '00000000-0000-4000-8000-000000000091'],
    ['notes', 'Browser-created deterministic Inventory opening quantities'],
    ...openingProducts.flatMap(([productDocNum, quantity, batch], index) => [
      [`lines[${index}][product_doc_num]`, productDocNum],
      [`lines[${index}][quantity]`, quantity],
      [`lines[${index}][stock_status]`, 'available'],
      [`lines[${index}][batch_lot]`, batch],
    ]),
  ];
  const openingResult = await postVisibleForm('/opening-stocks', openingOverrides);
  const openingDoc = openingResult.payload?.data?.doc_num;
  assert(openingDoc, 'Browser Opening Stock did not return a document number.');
  await navigate(openingResult.payload.data.urls.show);
  for (const text of ['E2E PP Raw Material', 'TEST Printed Wrapper — Customer A', 'TEST Stress Component 25']) await assertBody(text, 'Opening Stock reload');
  await post(`/admin/inventory/opening-stocks/${openingDoc}/approve`);
  await streamPdf(`/admin/inventory/opening-stocks/${openingDoc}/print`, 'opening-stock-en.pdf');

  await navigate('/admin/inventory/opening-stock-pricings/create');
  const pricingBranch = await remoteOption('#branch_doc_num', 'Main Branch');
  const pricingCurrency = await remoteOption('#currency_doc_num', 'EGP');
  const remainingPricingLines = await evaluate(`(async () => {
    const form = document.querySelector('.js-opening-stock-pricing-form');
    const url = new URL(form.dataset.remainingLinesUrl, location.origin);
    url.searchParams.set('branch_doc_num', ${JSON.stringify(pricingBranch.id)});
    url.searchParams.set('opening_stock_doc_num', ${JSON.stringify(openingDoc)});
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const payload = await response.json();
    return payload.data?.lines || [];
  })()`);
  assert(remainingPricingLines.length === openingProducts.length, `Expected ${openingProducts.length} Opening Stock pricing lines, got ${remainingPricingLines.length}.`);
  const priceFor = (text) => text.includes('Product-E2E-MFG-PP') ? '2' : text.includes('Product-E2E-MFG-MB') ? '8' : text.includes('Product-E2E-MFG-CARTON') ? '0.5' : '1';
  const pricingResult = await postVisibleForm('/opening-stock-pricings', [
    ['branch_doc_num', pricingBranch.id],
    ['opening_stock_doc_num', openingDoc],
    ['currency_doc_num', pricingCurrency.id],
    ['exchange_rate', '1'],
    ['notes', 'Browser-applied canonical moving-average opening valuation'],
    ...remainingPricingLines.flatMap((line, index) => [
      [`lines[${index}][opening_stock_line_public_id]`, String(line.id)],
      [`lines[${index}][unit_price]`, priceFor(String(line.text || ''))],
    ]),
  ]);
  assert(pricingResult.payload?.data?.doc_num, 'Opening Stock Pricing did not persist.');

  await navigate('/admin/finance/opening-balances/create');
  const openingBalanceCurrency = await remoteOption('#currency_doc_num', 'EGP');
  const openingBalanceRaw = await remoteOption('select[name="lines[0][account_doc_num]"]', '1131');
  const openingBalancePackaging = await remoteOption('select[name="lines[0][account_doc_num]"]', '1134');
  const openingBalanceEquity = await remoteOption('select[name="lines[0][account_doc_num]"]', '34');
  const financeOpeningResult = await postVisibleForm('/opening-balances', [
    ['currency_doc_num', openingBalanceCurrency.id],
    ['exchange_rate', '1'],
    ['description', 'Finance-owned Inventory opening value for browser-created quantities'],
    ['lines[0][account_doc_num]', openingBalanceRaw.id], ['lines[0][transaction_type]', 'debit'], ['lines[0][amount]', '2925'],
    ['lines[1][account_doc_num]', openingBalancePackaging.id], ['lines[1][transaction_type]', 'debit'], ['lines[1][amount]', '7255'],
    ['lines[2][account_doc_num]', openingBalanceEquity.id], ['lines[2][transaction_type]', 'credit'], ['lines[2][amount]', '10180'],
  ]);
  const financeOpeningDoc = financeOpeningResult.payload?.data?.doc_num;
  assert(financeOpeningDoc, 'Finance Opening Balance did not persist.');
  await post(`/admin/finance/opening-balances/${financeOpeningDoc}/approve`);

  const primaryDemand = await createProductionRequirement('E2E Sales-Origin Customer', '10');
  const orderDoc = primaryDemand.productionDoc;
  const orderUrl = `/admin/production/work-orders/${orderDoc}`;
  await navigate(orderUrl);
  await assertBody('Sales Order', 'Sales-origin Production order');
  await assertBody('1000.00000000', 'Production order base quantity');
  await streamPdf(`${orderUrl}/print`, 'production-order-en.pdf');
  await navigate(orderUrl);
  await post(`/admin/production/work-orders/${orderDoc}/release`);

  await navigate('/admin/production/runs');
  const planningIds = await evaluate(`(() => {
    const option = (name, text) => Array.from(document.querySelector('[name="' + name + '"]').options).find((item) => item.text.includes(text))?.value;
    return { line: option('production_order_line_id', ${JSON.stringify(orderDoc)}), asset: option('fixed_asset_id', 'E2E Injection Machine 1') };
  })()`);
  assert(Object.values(planningIds).every(Boolean), `Planning options incomplete: ${JSON.stringify(planningIds)}`);
  const run1Result = await post('/admin/production/runs', [
    ['production_order_line_id', planningIds.line], ['planned_quantity', '4'], ['planned_start_at', localDateTime(7, 8)], ['planned_end_at', localDateTime(7, 11)], ['fixed_asset_id', planningIds.asset], ['batch_lot', 'E2E-RUN-1'],
  ]);
  const run2Result = await post('/admin/production/runs', [
    ['production_order_line_id', planningIds.line], ['planned_quantity', '6'], ['planned_start_at', localDateTime(7, 11)], ['planned_end_at', localDateTime(7, 16)], ['fixed_asset_id', planningIds.asset], ['batch_lot', 'E2E-RUN-2'],
  ]);
  await post('/admin/production/runs', [
    ['production_order_line_id', planningIds.line], ['planned_quantity', '1'], ['planned_start_at', localDateTime(7, 10)], ['planned_end_at', localDateTime(7, 12)], ['fixed_asset_id', planningIds.asset],
  ], { expectedError: true });
  const run1Url = new URL(run1Result.payload.data.url).pathname;
  const run2Url = new URL(run2Result.payload.data.url).pathname;
  const run1Doc = run1Url.split('/').pop();
  const run2Doc = run2Url.split('/').pop();
  const run1Number = run1Result.payload.data.run_number;
  const run2Number = run2Result.payload.data.run_number;

  async function prepareRun(runUrl, runDoc) {
    await navigate(runUrl);
    const stores = await evaluate(`(() => { const options = Array.from(document.querySelector('[name="branch_store_id"]').options); return { raw: options.find((option) => option.text.includes('E2E Raw Material Store'))?.value, finished: options.find((option) => option.text.includes('E2E Finished Goods Store'))?.value }; })()`);
    assert(stores.raw && stores.finished, `${runDoc}: production stores missing`);
    await post(`/admin/production/runs/${runDoc}/reserve`, [['branch_store_id', stores.raw]]);
    await post(`/admin/production/runs/${runDoc}/issue`, [['branch_store_id', stores.raw]]);
    await post(`/admin/production/runs/${runDoc}/setup/start`);
    await post(`/admin/production/runs/${runDoc}/setup/complete`);
    await post(`/admin/production/runs/${runDoc}/start`);
    return stores;
  }

  const run1Stores = await prepareRun(run1Url, run1Doc);
  await post(`/admin/production/runs/${run1Doc}/progress`, [['good_base_quantity', '200'], ['notes', 'Run 1 progress 1']]);
  await post(`/admin/production/runs/${run1Doc}/progress`, [['good_base_quantity', '200'], ['notes', 'Run 1 progress 2']]);
  await createQualityInspection(run1Url, { notes: 'Run 1 normal sample' });
  const run1QualityUrl = await createQualityInspection(run1Url, { notes: 'Run 1 observation with mobile evidence', evidence: true });
  await navigate(run1Url);
  await postVisibleForm('/account-materials');
  const receipt1 = await post(`/admin/production/runs/${run1Doc}/receive`, [['branch_store_id', run1Stores.finished], ['base_quantity', '200']]);
  const receipt2 = await post(`/admin/production/runs/${run1Doc}/receive`, [['branch_store_id', run1Stores.finished], ['base_quantity', '200']]);
  await post(`/admin/production/runs/${run1Doc}/complete`);

  const run2Stores = await prepareRun(run2Url, run2Doc);
  await navigate(run2Url);
  const requirementIds = await evaluate(`Array.from(document.querySelectorAll('form[action*="account-materials"] input[name$="[requirement_id]"]')).map((field) => field.value)`);
  assert(requirementIds.length === 3, `Expected 3 BOM requirements, got ${requirementIds.length}`);
  await post(`/admin/production/runs/${run2Doc}/issue`, [['branch_store_id', run2Stores.raw], ['additional', '1'], ['lines[0][requirement_id]', requirementIds[0]], ['lines[0][quantity]', '1']]);
  await post(`/admin/production/runs/${run2Doc}/return`, [['branch_store_id', run2Stores.raw], ['lines[0][requirement_id]', requirementIds[0]], ['lines[0][quantity]', '0.5']]);
  await post(`/admin/production/runs/${run2Doc}/progress`, [['good_base_quantity', '300'], ['notes', 'Run 2 progress 1']]);
  await post(`/admin/production/runs/${run2Doc}/progress`, [['good_base_quantity', '300'], ['notes', 'Run 2 progress 2']]);
  const failedQualityUrl = await createQualityInspection(run2Url, { result: 'failed', notes: 'Adjust machine and resample' });
  const reinspectionResult = await post(`${failedQualityUrl}/reinspect`);
  const passedReinspectionUrl = new URL(reinspectionResult.payload.redirect_url).pathname;
  await submitQualityInspection(passedReinspectionUrl, { notes: 'Corrective action verified' });
  await post(`/admin/production/runs/${run2Doc}/resume`);
  await navigate(run2Url);
  const accountOverrides = await evaluate(`(() => {
    const form = document.querySelector('form[action*="account-materials"]');
    const rows = Array.from(form.querySelectorAll('input[name$="[requirement_id]"]')).map((field) => field.closest('.row'));
    const raw = rows.find((row) => row.innerText.includes('E2E PP Raw Material'));
    return { consumed: raw.querySelector('input[name$="[consumed_quantity]"]').name, waste: raw.querySelector('input[name$="[waste_quantity]"]').name };
  })()`);
  await postVisibleForm('/account-materials', [[accountOverrides.consumed, '60'], [accountOverrides.waste, '0.5']]);
  const receipt3 = await post(`/admin/production/runs/${run2Doc}/receive`, [['branch_store_id', run2Stores.finished], ['base_quantity', '300']]);
  const receipt4 = await post(`/admin/production/runs/${run2Doc}/receive`, [['branch_store_id', run2Stores.finished], ['base_quantity', '300']]);
  await post(`/admin/production/runs/${run2Doc}/complete`);

  const packingDemandA = await createProductionRequirement('E2E Packing Customer A', '1000');
  const packingDemandB = await createProductionRequirement('E2E Packing Customer B', '1000');
  await post(`/admin/production/work-orders/${packingDemandA.productionDoc}/release`);
  await post(`/admin/production/work-orders/${packingDemandB.productionDoc}/release`);
  await navigate('/admin/production/runs');
  const packingPlanningIds = await evaluate(`(() => {
    const option = (name, text) => Array.from(document.querySelector('[name="' + name + '"]').options).find((item) => item.text.includes(text))?.value;
    return {
      lineA: option('production_order_line_id', ${JSON.stringify(packingDemandA.productionDoc)}),
      lineB: option('production_order_line_id', ${JSON.stringify(packingDemandB.productionDoc)}),
      asset: option('fixed_asset_id', 'E2E Customer Packing Line 02'),
    };
  })()`);
  assert(Object.values(packingPlanningIds).every(Boolean), `Packing planning options incomplete: ${JSON.stringify(packingPlanningIds)}`);
  const packingRun1Result = await post('/admin/production/runs', [
    ['production_order_line_id', packingPlanningIds.lineA], ['planned_quantity', '400'], ['planned_start_at', localDateTime(8, 8)], ['planned_end_at', localDateTime(8, 11)], ['fixed_asset_id', packingPlanningIds.asset], ['batch_lot', 'KIT-A-RUN-400'],
  ]);
  const packingRun2Result = await post('/admin/production/runs', [
    ['production_order_line_id', packingPlanningIds.lineA], ['planned_quantity', '600'], ['planned_start_at', localDateTime(8, 11)], ['planned_end_at', localDateTime(8, 16)], ['fixed_asset_id', packingPlanningIds.asset], ['batch_lot', 'KIT-A-RUN-600'],
  ]);
  const packingRunBResult = await post('/admin/production/runs', [
    ['production_order_line_id', packingPlanningIds.lineB], ['planned_quantity', '1000'], ['planned_start_at', localDateTime(9, 8)], ['planned_end_at', localDateTime(9, 16)], ['fixed_asset_id', packingPlanningIds.asset], ['batch_lot', 'KIT-B-BLOCKED'],
  ]);
  const packingRun1Url = new URL(packingRun1Result.payload.data.url).pathname;
  const packingRun2Url = new URL(packingRun2Result.payload.data.url).pathname;
  const packingRunBUrl = new URL(packingRunBResult.payload.data.url).pathname;
  const packingRun1Doc = packingRun1Url.split('/').pop();
  const packingRun2Doc = packingRun2Url.split('/').pop();
  const packingRunBDoc = packingRunBUrl.split('/').pop();
  await navigate(packingRun1Url);
  const packingStores = await evaluate(`(() => {
    const options = Array.from(document.querySelector('[name="branch_store_id"]').options);
    return { raw: options.find((option) => option.text.includes('E2E Raw Material Store'))?.value, finished: options.find((option) => option.text.includes('E2E Finished Goods Store'))?.value };
  })()`);
  assert(packingStores.raw && packingStores.finished, 'Packing production stores were missing.');
  await post(`/admin/production/runs/${packingRun1Doc}/reserve`, [['branch_store_id', packingStores.raw]]);
  await post(`/admin/production/runs/${packingRun2Doc}/reserve`, [['branch_store_id', packingStores.raw]]);
  await post(`/admin/production/runs/${packingRunBDoc}/reserve`, [['branch_store_id', packingStores.raw]], { expectedError: true });

  async function completePackingRun(runUrl, runDoc, goodQuantity, withForkDamage = false) {
    await post(`/admin/production/runs/${runDoc}/issue`, [['branch_store_id', packingStores.raw]]);
    await navigate(runUrl);
    const packingRequirementCount = await evaluate('document.querySelectorAll(\'form[action*="account-materials"] input[name$="[requirement_id]"]\').length');
    assert(packingRequirementCount === 7, `${runDoc}: Expected seven real packing components, got ${packingRequirementCount}.`);
    if (withForkDamage) {
      const forkRequirement = await evaluate(`(() => {
        const form = document.querySelector('form[action*="account-materials"]');
        const row = Array.from(form.querySelectorAll('input[name$="[requirement_id]"]')).map((field) => field.closest('.row')).find((candidate) => candidate.innerText.includes('TEST Kit Fork'));
        return row?.querySelector('input[name$="[requirement_id]"]')?.value || null;
      })()`);
      assert(forkRequirement, 'Packing fork requirement was not visible.');
      await post(`/admin/production/runs/${runDoc}/issue`, [['branch_store_id', packingStores.raw], ['additional', '1'], ['lines[0][requirement_id]', forkRequirement], ['lines[0][quantity]', '5']]);
    }
    await post(`/admin/production/runs/${runDoc}/setup/start`);
    await post(`/admin/production/runs/${runDoc}/setup/complete`);
    await post(`/admin/production/runs/${runDoc}/start`);
    await post(`/admin/production/runs/${runDoc}/progress`, [['good_base_quantity', String(goodQuantity)], ['notes', 'Packing browser output']]);
    await navigate(runUrl);
    const materialOverrides = withForkDamage ? await evaluate(`(() => {
      const form = document.querySelector('form[action*="account-materials"]');
      const row = Array.from(form.querySelectorAll('input[name$="[requirement_id]"]')).map((field) => field.closest('.row')).find((candidate) => candidate.innerText.includes('TEST Kit Fork'));
      const consumed = row.querySelector('input[name$="[consumed_quantity]"]');
      const waste = row.querySelector('input[name$="[waste_quantity]"]');
      return [[consumed.name, String(Number(consumed.value) - 5)], [waste.name, '5']];
    })()`) : [];
    await postVisibleForm('/account-materials', materialOverrides);
    await createQualityInspection(runUrl, { notes: 'Packing final inspection passed' });
    const receipts = [];
    if (String(goodQuantity) === '400') {
      receipts.push(await post(`/admin/production/runs/${runDoc}/receive`, [['branch_store_id', packingStores.finished], ['base_quantity', '200']]));
      receipts.push(await post(`/admin/production/runs/${runDoc}/receive`, [['branch_store_id', packingStores.finished], ['base_quantity', '200']]));
    } else {
      receipts.push(await post(`/admin/production/runs/${runDoc}/receive`, [['branch_store_id', packingStores.finished], ['base_quantity', String(goodQuantity)]]));
    }
    await post(`/admin/production/runs/${runDoc}/complete`);
    return receipts.map((receipt) => receipt.payload.data.doc_num);
  }

  const packingReceiptDocs = [
    ...await completePackingRun(packingRun1Url, packingRun1Doc, '400', true),
    ...await completePackingRun(packingRun2Url, packingRun2Doc, '600'),
  ];
  await navigate(`/admin/production/work-orders/${packingDemandA.productionDoc}`);
  await assertBody('Completed', 'Packing Production order');

  await navigate('/admin/inventory/documents/create');
  const movementIds = await evaluate(`(() => {
    const option = (name, text) => Array.from(document.querySelector('[name="' + name + '"]').options).find((item) => item.text.includes(text))?.value;
    const productOptions = Array.from(document.querySelector('[name="lines[0][product_id]"]').options);
    return {
      rawStore: option('branch_store_id', 'E2E Raw Material Store'),
      finishedStore: option('destination_branch_store_id', 'E2E Finished Goods Store'),
      raw: option('lines[0][product_id]', 'E2E PP Raw Material'),
      packaging: option('lines[0][product_id]', 'E2E Packaging Carton'),
      stress: Array.from({ length: 25 }, (_, index) => productOptions.find((item) => item.text.includes('TEST Stress Component ' + String(index + 1).padStart(2, '0')))?.value),
    };
  })()`);
  assert(movementIds.stress.every(Boolean), `The 25 stress products were not available: ${JSON.stringify(movementIds.stress)}`);
  const dynamicLineCount = await evaluate(`(() => {
    const add = document.querySelector('[data-add-inventory-line]');
    for (let index = 1; index < 25; index += 1) add.click();
    return document.querySelectorAll('[data-inventory-line]').length;
  })()`);
  assert(dynamicLineCount === 25, `The browser Inventory form rendered ${dynamicLineCount} lines instead of 25.`);
  const stressDocumentResult = await postVisibleForm('/inventory/documents', [
    ['branch_store_id', movementIds.rawStore],
    ['destination_branch_store_id', movementIds.rawStore],
    ['document_type', 'inventory_adjustment_in'],
    ['movement_reason', 'Browser 25-line valued Inventory stress document'],
    ['source_stock_status', 'available'],
    ['destination_stock_status', 'available'],
    ...movementIds.stress.flatMap((productId, index) => [
      [`lines[${index}][product_id]`, productId],
      [`lines[${index}][quantity]`, '5'],
      [`lines[${index}][unit_cost]`, '1'],
    ]),
  ]);
  const stressDocumentDoc = stressDocumentResult.payload?.data?.doc_num;
  assert(stressDocumentDoc, 'The browser 25-line Inventory document did not persist.');
  await navigate(stressDocumentResult.payload.data.url);
  assert((await evaluate('document.querySelectorAll("tbody tr").length')) >= 25, 'The reloaded Inventory document did not show all 25 lines.');
  await streamPdf(`/admin/inventory/documents/${stressDocumentDoc}/print`, 'inventory-25-line-en.pdf');

  const stressDemand = await createProductionRequirement('E2E Stress Customer', '1');
  await post(`/admin/production/work-orders/${stressDemand.productionDoc}/release`);
  await navigate('/admin/production/runs');
  const stressPlanningIds = await evaluate(`(() => {
    const option = (name, text) => Array.from(document.querySelector('[name="' + name + '"]').options).find((item) => item.text.includes(text))?.value;
    return { line: option('production_order_line_id', ${JSON.stringify(stressDemand.productionDoc)}), asset: option('fixed_asset_id', 'E2E Injection Machine 1') };
  })()`);
  assert(Object.values(stressPlanningIds).every(Boolean), `25-component planning options incomplete: ${JSON.stringify(stressPlanningIds)}`);
  const stressRunResult = await post('/admin/production/runs', [
    ['production_order_line_id', stressPlanningIds.line], ['planned_quantity', '1'], ['planned_start_at', localDateTime(10, 8)], ['planned_end_at', localDateTime(10, 10)], ['fixed_asset_id', stressPlanningIds.asset], ['batch_lot', 'E2E-25-COMPONENT-RUN'],
  ]);
  const stressRunUrl = new URL(stressRunResult.payload.data.url).pathname;
  const stressRunDoc = stressRunUrl.split('/').pop();
  await navigate(stressRunUrl);
  assert((await evaluate('document.querySelectorAll(\'form[action*="account-materials"] input[name$="[requirement_id]"]\').length')) === 25, 'The real BOM did not generate 25 Production material requirements.');
  await post(`/admin/production/runs/${stressRunDoc}/reserve`, [['branch_store_id', movementIds.rawStore]]);
  await post(`/admin/production/runs/${stressRunDoc}/issue`, [['branch_store_id', movementIds.rawStore]]);
  await navigate(stressRunUrl);
  await assertBody('TEST Stress Component 25', '25-component Material Issue lineage');
  await streamPdf(`${stressRunUrl}/materials/print`, 'production-25-component-materials-en.pdf');

  const transferResult = await post('/admin/inventory/documents', [['branch_store_id', movementIds.rawStore], ['destination_branch_store_id', movementIds.finishedStore], ['document_type', 'inventory_transfer'], ['document_date', localDate()], ['movement_reason', 'MFG E2E transfer'], ['source_stock_status', 'available'], ['destination_stock_status', 'available'], ['lines[0][product_id]', movementIds.packaging], ['lines[0][quantity]', '5'], ['lines[0][batch_lot]', 'E2E-CARTON-OPENING']]);
  const damageResult = await post('/admin/inventory/documents', [['branch_store_id', movementIds.rawStore], ['destination_branch_store_id', movementIds.rawStore], ['document_type', 'inventory_damage'], ['document_date', localDate()], ['movement_reason', 'MFG E2E damage'], ['source_stock_status', 'available'], ['destination_stock_status', 'damaged'], ['lines[0][product_id]', movementIds.raw], ['lines[0][quantity]', '1'], ['lines[0][batch_lot]', 'E2E-PP-OPENING']]);
  const scrapResult = await post('/admin/inventory/documents', [['branch_store_id', movementIds.rawStore], ['document_type', 'inventory_scrap'], ['document_date', localDate()], ['movement_reason', 'MFG E2E damaged disposition'], ['source_stock_status', 'damaged'], ['lines[0][product_id]', movementIds.raw], ['lines[0][quantity]', '1'], ['lines[0][batch_lot]', 'E2E-PP-OPENING']]);
  await post('/admin/inventory/documents', [['branch_store_id', movementIds.rawStore], ['document_type', 'inventory_adjustment_out'], ['document_date', localDate()], ['movement_reason', 'MFG E2E negative stock guard'], ['source_stock_status', 'available'], ['lines[0][product_id]', movementIds.raw], ['lines[0][quantity]', '99999'], ['lines[0][batch_lot]', 'E2E-PP-OPENING']], { expectedError: true });

  await navigate('/admin/inventory/stock-counts');
  const countResult = await post('/admin/inventory/stock-counts', [['branch_store_id', movementIds.rawStore], ['stock_status', 'available'], ['count_date', localDate()], ['product_ids[0]', movementIds.raw], ['notes', 'MFG E2E deliberate variance']]);
  const countUrl = new URL(countResult.payload.data.url).pathname;
  await navigate(countUrl);
  const countEntries = await evaluate(`(() => {
    const id = document.querySelector('input[form="count-form"][name$="[line_id]"]').value;
    const physicalInput = document.querySelector('input[form="count-form"][name$="[physical_quantity]"]');
    const systemQuantity = physicalInput.closest('tr').children[3].innerText.trim();
    return { id, physical: String(Number(systemQuantity) - 1) };
  })()`);
  await post(`${countUrl}/record`, [['lines[0][line_id]', countEntries.id], ['lines[0][physical_quantity]', countEntries.physical], ['lines[0][variance_reason]', 'MFG E2E verified shortage']]);
  await post(`${countUrl}/approve`);
  await streamPdf(`${countUrl}/print`, 'stock-count-variance-en.pdf');
  await streamPdf(`/admin/inventory/documents/${transferResult.payload.data.doc_num}/print`, 'inventory-transfer-en.pdf');
  await streamPdf(`/admin/inventory/documents/${damageResult.payload.data.doc_num}/print`, 'inventory-damage-en.pdf');
  await streamPdf(`/admin/inventory/documents/${scrapResult.payload.data.doc_num}/print`, 'inventory-scrap-en.pdf');

  await navigate('/admin/inventory/reports/operations');
  for (const text of ['E2E PP Raw Material', 'TEST Sales-Origin Plastic Product', 'TEST Customer Kit', 'Production']) await assertBody(text, 'Inventory report');
  const reconciliationStatuses = await evaluate(`(() => {
    const heading = Array.from(document.querySelectorAll('h5')).find((node) => node.innerText.includes('General Ledger Reconciliation'));
    return Array.from(heading?.closest('.card')?.querySelectorAll('tbody .badge') || []).map((node) => node.innerText.trim());
  })()`);
  assert(reconciliationStatuses.length === 5 && reconciliationStatuses.every((status) => status === 'Reconciled'), `GL reconciliation was not zero: ${JSON.stringify(reconciliationStatuses)}`);
  await downloadAuthenticated('/admin/inventory/reports/operations/export.xlsx', 'inventory-operations.xlsx', 'spreadsheetml', 'PK');
  await streamPdf('/admin/inventory/reports/operations/print', 'inventory-operations-en.pdf');
  await navigate('/admin/production/reports/operations');
  for (const text of [run1Number, run2Number, packingRun1Result.payload.data.run_number, 'Material Requirements, Consumption and Variance']) await assertBody(text, 'Production report');
  await downloadAuthenticated('/admin/production/reports/operations/export.xlsx', 'production-operations.xlsx', 'spreadsheetml', 'PK');
  await streamPdf('/admin/production/reports/operations/print', 'production-operations-en.pdf');
  await navigate(orderUrl);
  await assertBody('Completed', 'Completed production order');

  const receiptDocs = [receipt1, receipt2, receipt3, receipt4].map((result) => result.payload.data.doc_num);
  await navigate(`/admin/inventory/documents/${receiptDocs[0]}`);
  const backwardRunUrl = await evaluate('Array.from(document.querySelectorAll(\'a[href*="/admin/production/runs/"]\')).map((link) => link.href)[0] || null');
  assert(backwardRunUrl, 'Finished Goods receipt did not link backward to its Production run.');
  await navigate(backwardRunUrl);
  const backwardOrderUrl = await evaluate('Array.from(document.querySelectorAll(\'a[href*="/admin/production/work-orders/"]\')).map((link) => link.href)[0] || null');
  const backwardSalesUrl = await evaluate('Array.from(document.querySelectorAll(\'a[href*="/admin/sales/sales-orders/"]\')).map((link) => link.href)[0] || null');
  assert(backwardOrderUrl && backwardSalesUrl, 'Production run did not expose Production Order and Sales Order backward lineage.');
  await navigate(backwardOrderUrl);
  await assertBody(orderDoc, 'Backward Production Order navigation');
  await navigate(backwardSalesUrl);
  await assertBody(orderDoc, 'Backward Sales Order navigation');
  await navigate(run2Url);
  const runDocumentLinks = await evaluate(`(() => {
    const heading = Array.from(document.querySelectorAll('h6')).find((node) => node.innerText.includes('Related documents and source lineage'));
    const links = Array.from(heading?.closest('.card')?.querySelectorAll('.col-md-4') || []).map((column) => ({
      label: column.querySelector('strong')?.innerText.trim() || '',
      href: column.querySelector('a[href*="/admin/inventory/documents/"]')?.href || null,
    })).filter((item) => item.href);
    return Object.fromEntries(links.map((item) => [item.label, item.href]));
  })()`);
  const lineageLink = (requiredWords, excludedWords = []) => Object.entries(runDocumentLinks)
    .find(([label]) => requiredWords.every((word) => label.includes(word)) && excludedWords.every((word) => !label.includes(word)))?.[1];
  const lineageDocuments = {
    'Material Issue': lineageLink(['Material', 'Issue'], ['Additional']),
    'Additional Material Issue': lineageLink(['Additional', 'Material', 'Issue']),
    'Material Return': lineageLink(['Material', 'Return']),
    'Production Waste': lineageLink(['Production', 'Waste']),
    'Production Receipt': lineageLink(['Production', 'Receipt']),
  };
  for (const [label, url] of Object.entries(lineageDocuments)) {
    assert(url, `Run lineage did not expose ${label}.`);
  }
  await navigate(lineageDocuments['Material Issue']);
  await assertBody(run2Number, 'Material Issue to Production run lineage');

  await streamPdf(`${orderUrl}/requirement/print`, 'production-requirement-en.pdf');
  await streamPdf(`${run1Url}/materials/print`, 'material-requirement-en.pdf');
  await streamPdf(`${run1Url}/quality/print`, 'in-process-qc-en.pdf');
  await streamPdf(`${run1Url}/completion/print`, 'production-completion-en.pdf');
  await streamPdf(lineageDocuments['Material Issue'].replace(/\/$/, '') + '/print', 'material-issue-en.pdf');
  await streamPdf(lineageDocuments['Additional Material Issue'].replace(/\/$/, '') + '/print', 'additional-material-issue-en.pdf');
  await streamPdf(lineageDocuments['Material Return'].replace(/\/$/, '') + '/print', 'material-return-en.pdf');
  await streamPdf(`/admin/inventory/documents/${receiptDocs[0]}/print`, 'finished-goods-receipt-en.pdf');
  await streamPdf(`${run1Url}/print`, 'production-run-en.pdf');

  await navigate('/admin/inventory/reports/operations');
  await logoutCurrentUser();
  await loginAs('e2e_warehouse');
  await navigate('/admin/inventory/reports/operations');
  assert(!(await evaluate('document.documentElement.innerHTML.includes("<th>Value</th>")')), 'Warehouse user received financial Inventory values.');
  assert((await browserResponseStatus('/admin/production/reports/operations')) === 403, 'Warehouse user accessed Production reports.');
  assert((await browserResponseStatus('/admin/inventory/accounting')) === 404, 'Removed Inventory accounting mapping screen is still reachable for warehouse users.');

  await logoutCurrentUser();
  await loginAs('e2e_planner');
  assert((await browserResponseStatus('/admin/production/runs')) === 200, 'Planner could not access Production planning.');
  assert((await browserResponseStatus('/admin/inventory/documents/create')) === 403, 'Planner accessed warehouse document creation.');
  assert((await browserResponseStatus('/admin/production/reports/operations')) === 403, 'Planner accessed restricted Production cost reporting.');

  await logoutCurrentUser();
  await loginAs('e2e_quality');
  assert((await browserResponseStatus(run1Url)) === 200, 'Quality user could not access the Production run QC workspace.');
  assert((await browserResponseStatus('/admin/inventory/documents/create')) === 403, 'Quality user accessed warehouse document creation.');
  assert((await browserResponseStatus('/admin/inventory/reports/operations')) === 403, 'Quality user accessed Inventory reporting.');

  await logoutCurrentUser();
  await loginAs('e2e_cost');
  await navigate('/admin/inventory/reports/operations');
  assert((await evaluate('document.documentElement.innerHTML.includes("<th>Value</th>")')), 'Cost user did not receive authorized Inventory values.');
  assert((await browserResponseStatus('/admin/production/reports/operations')) === 200, 'Cost user could not access Production financial reporting.');
  assert((await browserResponseStatus('/admin/inventory/accounting')) === 404, 'Removed Inventory accounting mapping screen is still reachable for cost users.');
  assert((await browserResponseStatus('/admin/inventory/documents/create')) === 403, 'Cost user accessed warehouse document creation.');

  await logoutCurrentUser();
  await loginAs('admin', 'admin');
  await navigate('/lang/ar');
  await streamPdf(`${run1Url}/print`, 'production-run-ar.pdf');
  await streamPdf(`/admin/inventory/documents/${receiptDocs[0]}/print`, 'finished-goods-receipt-ar.pdf');

  assert(mobileQualityCapture?.exists, 'The live mobile Quality capture step was not exercised.');

  const result = {
    navigation: { opened: visibleRoutes.length, routes: visibleRoutes },
    accounting: { source: 'chart-of-accounts classifications', mapping_screen_removed: true, reconciliation: reconciliationStatuses },
    openingStock: { doc_num: openingDoc, pricing_doc_num: pricingResult.payload.data.doc_num, finance_opening_balance: financeOpeningDoc, browser_created_lines: openingProducts.length },
    salesOrigin: { sales_order_url: primaryDemand.salesOrderUrl, production_requirement_url: primaryDemand.productionUrl, real_fk_lineage: true },
    order: { doc_num: orderDoc, target_cartons: '10', target_base_pieces: '1000.00000000', status: 'completed' },
    runs: [{ run: run1Number, planned_cartons: '4', good_base: '400' }, { run: run2Number, planned_cartons: '6', good_base: '600' }],
    bom: { raw_kg: '100.00000000', masterbatch_kg: '2.00000000', packaging_cartons: '10.00000000' },
    finishedGoodsReceipts: receiptDocs,
    secondFactory: { order: packingDemandA.productionDoc, blocked_cross_order_run: packingRunBResult.payload.data.run_number, runs: [packingRun1Result.payload.data.run_number, packingRun2Result.payload.data.run_number], finished_goods_receipts: packingReceiptDocs, customer_specific_wrapper: true, additional_fork_issue: '5', waste: '5' },
    stress: { inventory_document: stressDocumentDoc, inventory_lines: 25, production_run: stressRunResult.payload.data.run_number, bom_requirements: 25, material_issue_posted: true },
    inventoryOperations: { transfer: '5 packaging cartons', damage: '1 kg', scrap: '1 kg', count_variance: '-1 kg', over_issue_rejected: true },
    quality: { samples: 4, failed_hold_pass_resume: true, image_uploaded: true, mobile: mobileQualityCapture },
    permissions: { warehouse: 'financial values hidden', planner: 'warehouse blocked', quality: 'QC-only workspace', cost: 'financial reports visible, warehouse blocked' },
    exports: ['inventory-operations.xlsx', 'production-operations.xlsx'],
    prints: [
      'opening-stock-en.pdf', 'production-order-en.pdf', 'production-requirement-en.pdf', 'production-run-en.pdf',
      'material-requirement-en.pdf', 'material-issue-en.pdf', 'additional-material-issue-en.pdf', 'material-return-en.pdf',
      'in-process-qc-en.pdf', 'production-completion-en.pdf', 'finished-goods-receipt-en.pdf', 'inventory-transfer-en.pdf',
      'inventory-damage-en.pdf', 'inventory-scrap-en.pdf', 'stock-count-variance-en.pdf', 'inventory-25-line-en.pdf',
      'production-25-component-materials-en.pdf', 'inventory-operations-en.pdf', 'production-operations-en.pdf',
      'production-run-ar.pdf', 'finished-goods-receipt-ar.pdf',
    ],
    errors,
  };
  for (const [category, values] of Object.entries(errors)) assert(values.length === 0, `${category} errors: ${JSON.stringify(values)}`);
  await writeFile(path.join(artifactDirectory, 'result.json'), JSON.stringify(result, null, 2));
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
} catch (error) {
  await writeFile(path.join(artifactDirectory, 'failure.txt'), `${error.stack || error}\nURL: ${await evaluate('location.href').catch(() => 'unknown')}\n${await evaluate('document.body.innerText').catch(() => '')}`);
  throw error;
} finally {
  client.close();
}
