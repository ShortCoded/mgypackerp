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
await client.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });

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
}

async function post(route, entries = [], { expectedError = false, file = false } = {}) {
  const result = await evaluate(`(async () => {
    const data = new FormData();
    data.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value || '');
    for (const [name, value] of ${JSON.stringify(entries)}) data.append(name, value);
    if (${file}) {
      const bytes = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2n0sAAAAASUVORK5CYII='), (character) => character.charCodeAt(0));
      data.append('evidence_file', new File([bytes], 'qc-evidence.png', { type: 'image/png' }));
    }
    const response = await fetch(${JSON.stringify(`${baseUrl}${route}`)}, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
    const text = await response.text();
    let payload = null;
    try { payload = JSON.parse(text); } catch (error) {}
    return { status: response.status, payload, text: text.slice(0, 2000) };
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
    const form = Array.from(document.forms).find((candidate) => candidate.action.includes(${JSON.stringify(actionFragment)}));
    if (!form) return { missing: true };
    const data = new FormData(form);
    for (const [name, value] of ${JSON.stringify(overrides)}) data.set(name, value);
    const response = await fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
    const text = await response.text();
    let payload = null;
    try { payload = JSON.parse(text); } catch (error) {}
    return { status: response.status, payload, text: text.slice(0, 2000) };
  })()`);
  assert(!result.missing, `Visible form not found for ${actionFragment}`);
  assert(result.status >= 200 && result.status < 300, `Visible form ${actionFragment} failed ${result.status}: ${result.text}`);
  return result;
}

async function printPdf(filename) {
  const pdf = await client.send('Page.printToPDF', { printBackground: true, preferCSSPageSize: true });
  await writeFile(path.join(artifactDirectory, filename), Buffer.from(pdf.data, 'base64'));
}

async function assertBody(text, context) {
  const body = await evaluate('document.body.innerText');
  assert(body.includes(text), `${context} did not contain ${text}`);
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
      await navigate('/admin/production/reports/operations');
    }
    for (const text of [run1Number, run2Number, 'Material Variance']) await assertBody(text, 'Production report');
    await navigate(`/admin/production/work-orders/${orderDoc}`);
    await assertBody('Completed', 'Completed production order');
    await navigate(`/admin/inventory/documents/${receiptDoc}/print`);
    await printPdf('finished-goods-receipt-en.pdf');
    await navigate(`/admin/production/runs/${run1Id}/print`);
    await printPdf('production-run-en.pdf');
    await navigate('/lang/ar');
    await navigate(`/admin/production/runs/${run1Id}/print`);
    await printPdf('production-run-ar.pdf');
    await navigate(`/admin/inventory/documents/${receiptDoc}/print`);
    await printPdf('finished-goods-receipt-ar.pdf');

    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await navigate(`/admin/production/runs/${run1Id}`);
    const mobileQc = await evaluate(`(() => { const input = document.querySelector('input[name="evidence_file"]'); const rect = input?.getBoundingClientRect(); return { exists: Boolean(input), right: rect?.right || 0, width: innerWidth, capture: input?.getAttribute('capture') }; })()`);
    assert(mobileQc.exists && mobileQc.right <= mobileQc.width + 1 && mobileQc.capture === 'environment', `Mobile QC control failed: ${JSON.stringify(mobileQc)}`);

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
    '/admin/inventory/warehouse-locations', '/admin/inventory/documents', '/admin/inventory/stock-counts', '/admin/inventory/reports/operations',
    '/admin/production/resources', '/admin/production/work-orders', '/admin/production/runs', '/admin/production/reports/operations',
  ];
  for (const route of visibleRoutes) {
    await navigate(route);
    const body = await evaluate('document.body.innerText');
    assert(!/ERP UI Shell|interface only|placeholder/i.test(body), `Shell content remained visible at ${route}`);
  }

  await navigate('/admin/production/runs');
  const masterIds = await evaluate(`(() => {
    const byText = (name, text) => Array.from(document.querySelector('[name="' + name + '"]').options).find((option) => option.text.includes(text))?.value;
    return { product: byText('product_id', 'E2E Plastic Product'), unit: byText('unit_id', 'Carton') };
  })()`);
  assert(masterIds.product && masterIds.unit, 'Make-to-stock product/unit options were not available.');
  const orderResult = await post('/admin/production/work-orders/make-to-stock', [
    ['product_id', masterIds.product], ['unit_id', masterIds.unit], ['quantity', '10'], ['priority', 'high'], ['overproduction_tolerance_percent', '0'], ['production_notes', 'MFG browser E2E 10 cartons / 1000 pieces'],
  ]);
  const orderUrl = new URL(orderResult.payload.data.url).pathname;
  const orderDoc = orderUrl.split('/').pop();
  await navigate(orderUrl);
  await assertBody('1000.00000000', 'Production order base quantity');
  await printPdf('production-order-en.pdf');
  await post(`/admin/production/work-orders/${orderDoc}/release`);

  await navigate('/admin/production/runs');
  const planningIds = await evaluate(`(() => {
    const option = (name, text) => Array.from(document.querySelector('[name="' + name + '"]').options).find((item) => item.text.includes(text))?.value;
    return { line: option('production_order_line_id', ${JSON.stringify(orderDoc)}), machine: option('production_machine_id', 'E2E-MACHINE-01'), mold: option('production_mold_id', 'E2E-MOLD-01'), shift: option('production_shift_id', 'E2E-SHIFT-A') };
  })()`);
  assert(Object.values(planningIds).every(Boolean), `Planning options incomplete: ${JSON.stringify(planningIds)}`);
  const run1Result = await post('/admin/production/runs', [
    ['production_order_line_id', planningIds.line], ['planned_quantity', '4'], ['planned_start_at', '2026-09-01T08:00'], ['planned_end_at', '2026-09-01T11:00'], ['production_shift_id', planningIds.shift], ['production_machine_id', planningIds.machine], ['production_mold_id', planningIds.mold], ['batch_lot', 'E2E-RUN-1'],
  ]);
  const run2Result = await post('/admin/production/runs', [
    ['production_order_line_id', planningIds.line], ['planned_quantity', '6'], ['planned_start_at', '2026-09-01T11:00'], ['planned_end_at', '2026-09-01T16:00'], ['production_shift_id', planningIds.shift], ['production_machine_id', planningIds.machine], ['production_mold_id', planningIds.mold], ['batch_lot', 'E2E-RUN-2'],
  ]);
  await post('/admin/production/runs', [
    ['production_order_line_id', planningIds.line], ['planned_quantity', '1'], ['planned_start_at', '2026-09-01T10:00'], ['planned_end_at', '2026-09-01T12:00'], ['production_machine_id', planningIds.machine], ['production_mold_id', planningIds.mold],
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
  await post(`/admin/production/runs/${run1Doc}/inspect`, [['result', 'passed'], ['notes', 'Run 1 normal sample']]);
  await post(`/admin/production/runs/${run1Doc}/inspect`, [['result', 'passed'], ['notes', 'Run 1 observation with mobile evidence']], { file: true });
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
  await post(`/admin/production/runs/${run2Doc}/inspect`, [['result', 'failed'], ['defect_code', 'E2E-QC'], ['affected_base_quantity', '5'], ['corrective_action', 'Adjust mold and resample']]);
  await post(`/admin/production/runs/${run2Doc}/inspect`, [['result', 'passed'], ['notes', 'Corrective action verified']]);
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

  await navigate('/admin/inventory/documents/create');
  const movementIds = await evaluate(`(() => {
    const option = (name, text) => Array.from(document.querySelector('[name="' + name + '"]').options).find((item) => item.text.includes(text))?.value;
    return { rawStore: option('branch_store_id', 'E2E Raw Material Store'), finishedStore: option('destination_branch_store_id', 'E2E Finished Goods Store'), raw: option('lines[0][product_id]', 'E2E PP Raw Material'), packaging: option('lines[0][product_id]', 'E2E Packaging Carton') };
  })()`);
  await post('/admin/inventory/documents', [['branch_store_id', movementIds.rawStore], ['destination_branch_store_id', movementIds.finishedStore], ['document_type', 'inventory_transfer'], ['document_date', '2026-08-24'], ['movement_reason', 'MFG E2E transfer'], ['source_stock_status', 'available'], ['destination_stock_status', 'available'], ['lines[0][product_id]', movementIds.packaging], ['lines[0][quantity]', '5']]);
  await post('/admin/inventory/documents', [['branch_store_id', movementIds.rawStore], ['destination_branch_store_id', movementIds.rawStore], ['document_type', 'inventory_damage'], ['document_date', '2026-08-24'], ['movement_reason', 'MFG E2E damage'], ['source_stock_status', 'available'], ['destination_stock_status', 'damaged'], ['lines[0][product_id]', movementIds.raw], ['lines[0][quantity]', '1']]);
  await post('/admin/inventory/documents', [['branch_store_id', movementIds.rawStore], ['document_type', 'inventory_scrap'], ['document_date', '2026-08-24'], ['movement_reason', 'MFG E2E damaged disposition'], ['source_stock_status', 'damaged'], ['lines[0][product_id]', movementIds.raw], ['lines[0][quantity]', '1']]);
  await post('/admin/inventory/documents', [['branch_store_id', movementIds.rawStore], ['document_type', 'inventory_adjustment_out'], ['document_date', '2026-08-24'], ['movement_reason', 'MFG E2E negative stock guard'], ['source_stock_status', 'available'], ['lines[0][product_id]', movementIds.raw], ['lines[0][quantity]', '99999']], { expectedError: true });

  await navigate('/admin/inventory/stock-counts');
  const countResult = await post('/admin/inventory/stock-counts', [['branch_store_id', movementIds.rawStore], ['stock_status', 'available'], ['count_date', '2026-08-24'], ['product_ids[0]', movementIds.raw], ['notes', 'MFG E2E deliberate variance']]);
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

  await navigate('/admin/inventory/reports/operations');
  for (const text of ['E2E PP Raw Material', 'E2E Plastic Product', 'Production']) await assertBody(text, 'Inventory report');
  await navigate('/admin/production/reports/operations');
  for (const text of [run1Number, run2Number, 'Material Variance']) await assertBody(text, 'Production report');
  await navigate(orderUrl);
  await assertBody('Completed', 'Completed production order');

  const receiptDocs = [receipt1, receipt2, receipt3, receipt4].map((result) => result.payload.data.doc_num);
  await navigate(`/admin/inventory/documents/${receiptDocs[0]}/print`);
  await printPdf('finished-goods-receipt-en.pdf');
  await navigate(`${run1Url}/print`);
  await printPdf('production-run-en.pdf');
  await navigate('/lang/ar');
  await navigate(`${run1Url}/print`);
  await printPdf('production-run-ar.pdf');
  await navigate(`/admin/inventory/documents/${receiptDocs[0]}/print`);
  await printPdf('finished-goods-receipt-ar.pdf');

  await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await navigate(run1Url);
  const mobileQc = await evaluate(`(() => { const input = document.querySelector('input[name="evidence_file"]'); const rect = input?.getBoundingClientRect(); return { exists: Boolean(input), right: rect?.right || 0, width: innerWidth, capture: input?.getAttribute('capture') }; })()`);
  assert(mobileQc.exists && mobileQc.right <= mobileQc.width + 1 && mobileQc.capture === 'environment', `Mobile QC control failed: ${JSON.stringify(mobileQc)}`);

  const result = {
    navigation: { opened: visibleRoutes.length, routes: visibleRoutes },
    order: { doc_num: orderDoc, target_cartons: '10', target_base_pieces: '1000.00000000', status: 'completed' },
    runs: [{ run: run1Number, planned_cartons: '4', good_base: '400' }, { run: run2Number, planned_cartons: '6', good_base: '600' }],
    bom: { raw_kg: '100.00000000', masterbatch_kg: '2.00000000', packaging_cartons: '10.00000000' },
    finishedGoodsReceipts: receiptDocs,
    inventoryOperations: { transfer: '5 packaging cartons', damage: '1 kg', scrap: '1 kg', count_variance: '-1 kg', over_issue_rejected: true },
    quality: { samples: 4, failed_hold_pass_resume: true, image_uploaded: true, mobile: mobileQc },
    prints: ['production-order-en.pdf', 'finished-goods-receipt-en.pdf', 'production-run-en.pdf', 'production-run-ar.pdf', 'finished-goods-receipt-ar.pdf'],
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
