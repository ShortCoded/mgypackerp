import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.SALES_E2E_BASE_URL || 'http://127.0.0.1:8765').replace(/\/$/, '');
const debuggerUrl = (process.env.SALES_E2E_DEBUG_URL || 'http://127.0.0.1:9222').replace(/\/$/, '');
const artifactDirectory = process.env.SALES_E2E_ARTIFACT_DIR;

if (!artifactDirectory) {
  throw new Error('SALES_E2E_ARTIFACT_DIR is required.');
}

await mkdir(artifactDirectory, { recursive: true });

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const assert = (condition, message) => {
  if (!condition) {
    throw new Error(message);
  }
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
client.on('Page.loadEventFired', () => { loadCount += 1; });
client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
  browserErrors.push(exceptionDetails?.exception?.description || exceptionDetails?.text || 'Unhandled JavaScript exception');
});
client.on('Runtime.consoleAPICalled', ({ type, args }) => {
  if (type === 'error' || type === 'assert') {
    browserErrors.push(args.map((argument) => argument.value || argument.description || '').join(' '));
  }
});
client.on('Log.entryAdded', ({ entry }) => {
  if (entry.level === 'error' && !entry.url?.endsWith('/favicon.ico')) {
    browserErrors.push(entry.url ? `${entry.url}: ${entry.text}` : entry.text);
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

async function waitForReady() {
  await waitUntil(
    () => evaluate(`document.readyState === 'complete'`),
    'Page did not reach readyState=complete.',
  );
}

async function navigate(url) {
  const before = loadCount;
  await client.send('Page.navigate', { url });
  await waitUntil(() => loadCount > before, `Navigation did not complete: ${url}`);
  await waitForReady();
  await waitUntil(() => evaluate('Boolean(document.body)'), `Document body missing: ${url}`);
}

async function currentUrl() {
  return evaluate('window.location.href');
}

async function bodyText() {
  return evaluate('document.body.innerText');
}

async function assertBodyContains(text, context) {
  const body = await bodyText();

  if (!body.includes(text)) {
    await writeFile(path.join(artifactDirectory, 'failure-state.txt'), [
      `Context: ${context}`,
      `Expected: ${text}`,
      `URL: ${await currentUrl()}`,
      '',
      body,
    ].join('\n'));
  }

  assert(body.includes(text), `${context} did not contain “${text}”.`);
}

async function submitForm(selector, context, timeout = 45000) {
  const before = loadCount;
  const submitted = await evaluate(`(() => {
    const form = document.querySelector(${JSON.stringify(selector)});
    if (!form) return false;
    form.requestSubmit();
    return true;
  })()`);
  assert(submitted, `${context}: form not found (${selector}).`);
  try {
    await waitUntil(() => loadCount > before, `${context}: redirect/reload did not occur.`, timeout);
  } catch (error) {
    const alert = await evaluate(`document.querySelector('.js-sales-form-alert:not(.d-none), .js-auth-alert:not(.d-none), .js-form-alert:not(.d-none)')?.innerText || ''`);
    throw new Error(`${context} failed${alert ? `: ${alert}` : ''}. ${error.message}`);
  }
  await waitForReady();
}

async function setField(selector, value) {
  const changed = await evaluate(`(() => {
    const field = document.querySelector(${JSON.stringify(selector)});
    if (!field) return false;
    if (field._flatpickr && /^\d{4}-\d{2}-\d{2}$/.test(${JSON.stringify(value)})) {
      field._flatpickr.setDate(${JSON.stringify(value)}, true, 'Y-m-d');
    } else {
      field.value = ${JSON.stringify(value)};
    }
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  })()`);
  assert(changed, `Field not found: ${selector}`);
}

async function click(selector, context) {
  const clicked = await evaluate(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (!element) return false;
    element.click();
    return true;
  })()`);
  assert(clicked, `${context}: element not found (${selector}).`);
}

async function clickAndWaitForNavigation(selector, context, timeout = 45000) {
  const before = loadCount;
  await click(selector, context);
  await waitUntil(() => loadCount > before, `${context}: navigation did not occur.`, timeout);
  await waitForReady();
}

const today = new Date();
const isoDate = (days) => {
  const date = new Date(today);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
};

async function fillOrder({ customer, lineCount, quantity, price, tax, total, reference }) {
  const filled = await evaluate(`(() => {
    const set = (selector, value) => {
      const field = document.querySelector(selector);
      if (!field) throw new Error('Missing field: ' + selector);
      if (field._flatpickr && /^\d{4}-\d{2}-\d{2}$/.test(value)) field._flatpickr.setDate(value, true, 'Y-m-d');
      else field.value = value;
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    };
    while (document.querySelectorAll('[data-sales-lines] tr').length < ${lineCount}) {
      document.querySelector('[data-sales-add-line]').click();
    }
    set('#customer_doc_num', ${JSON.stringify(customer)});
    set('#order_date', ${JSON.stringify(isoDate(0))});
    set('#expected_delivery_date', ${JSON.stringify(isoDate(14))});
    set('#customer_reference', ${JSON.stringify(reference)});
    const store = document.querySelector('#branch_store_uuid');
    set('#branch_store_uuid', store.options[1].value);
    const representative = document.querySelector('#sales_employee_doc_num');
    if (representative.options.length > 1) set('#sales_employee_doc_num', representative.options[1].value);
    const rows = [...document.querySelectorAll('[data-sales-lines] tr')];
    rows.forEach((row, index) => {
      set('[name="lines[' + index + '][product_doc_num]"]', 'Product-E2E-SPOON');
      set('[name="lines[' + index + '][description]"]', 'TEST Plastic Spoon — carton line ' + String(index + 1).padStart(2, '0'));
      set('[name="lines[' + index + '][unit_doc_num]"]', 'Unit-E2E-CARTON');
      set('[name="lines[' + index + '][quantity]"]', ${JSON.stringify(quantity)});
      set('[name="lines[' + index + '][unit_price]"]', ${JSON.stringify(price)});
      set('[name="lines[' + index + '][discount_amount]"]', '0');
      set('[name="lines[' + index + '][tax_amount]"]', ${JSON.stringify(tax)});
      set('[name="lines[' + index + '][requested_date]"]', ${JSON.stringify(isoDate(14))});
      set('[name="lines[' + index + '][specifications][packaging]"]', '1000 pieces / export carton');
      set('[name="lines[' + index + '][specifications][customer_specification]"]', 'Food-grade white spoon, sealed inner bags');
      set('[name="lines[' + index + '][warehouse_notes]"]', 'Keep cartons dry and palletized');
      set('[name="lines[' + index + '][production_notes]"]', 'Customer print mark E2E-' + String(index + 1).padStart(2, '0'));
    });
    if (document.querySelectorAll('[data-sales-schedules] tr').length === 0) {
      document.querySelector('[data-sales-add-schedule]').click();
    }
    set('[name="payment_schedules[0][title]"]', 'Order total');
    set('[name="payment_schedules[0][due_date]"]', ${JSON.stringify(isoDate(30))});
    set('[name="payment_schedules[0][amount]"]', ${JSON.stringify(total)});
    set('#notes', 'Browser E2E sales-cycle order');
    return { rows: rows.length, total: rows.reduce((sum, row) => sum + Number(row.querySelector('[data-sales-line-total]').innerText), 0) };
  })()`);
  assert(filled.rows === lineCount, `Expected ${lineCount} order rows, found ${filled.rows}.`);
  assert(Math.abs(filled.total - Number(total)) < 0.001, `Browser line totals ${filled.total} did not equal ${total}.`);
}

async function fillQuotation({ customer, lines, total, reference }) {
  const filled = await evaluate(`(() => {
    const set = (selector, value) => {
      const field = document.querySelector(selector);
      if (!field) throw new Error('Missing field: ' + selector);
      if (field._flatpickr && /^\d{4}-\d{2}-\d{2}$/.test(value)) field._flatpickr.setDate(value, true, 'Y-m-d');
      else field.value = value;
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const select = (selector, value, label) => {
      const field = document.querySelector(selector);
      if (!field) throw new Error('Missing select: ' + selector);
      field.replaceChildren(new Option(label, value, true, true));
      if (window.jQuery) window.jQuery(field).trigger('change');
      else field.dispatchEvent(new Event('change', { bubbles: true }));
    };
    while (document.querySelectorAll('.js-quotation-lines .js-quotation-line').length < ${lines.length}) {
      document.querySelector('.js-quotation-add-line').click();
    }
    set('#quotation_date', ${JSON.stringify(isoDate(0))});
    set('#valid_until', ${JSON.stringify(isoDate(30))});
    set('#revision_date', ${JSON.stringify(isoDate(0))});
    set('#subject', 'Canonical browser quotation ${reference}');
    set('#customer_reference', ${JSON.stringify(reference)});
    set('#internal_notes', 'Browser E2E internal conversion note');
    select('#customer_doc_num', ${JSON.stringify(customer)}, ${JSON.stringify(customer)} + ' / E2E Customer');
    select('#currency_doc_num', 'CUR-00001', 'EGP / Egyptian Pound');
    const rows = [...document.querySelectorAll('.js-quotation-lines .js-quotation-line')];
    const definitions = ${JSON.stringify(lines)};
    rows.forEach((row, index) => {
      const definition = definitions[index];
      select('[name="lines[' + index + '][product_doc_num]"]', definition.product, definition.product + ' / ' + definition.description);
      select('[name="lines[' + index + '][unit_doc_num]"]', definition.unit, definition.unit);
      set('[name="lines[' + index + '][description]"]', definition.description + ' — line ' + String(index + 1).padStart(2, '0'));
      set('[name="lines[' + index + '][quantity]"]', definition.quantity);
      set('[name="lines[' + index + '][unit_price]"]', definition.price);
      set('[name="lines[' + index + '][discount_value]"]', '0');
      set('[name="lines[' + index + '][tax_rate]"]', definition.taxRate);
      set('[name="lines[' + index + '][requested_date]"]', ${JSON.stringify(isoDate(14))});
      set('[name="lines[' + index + '][specifications][packaging]"]', definition.packaging);
      set('[name="lines[' + index + '][specifications][customer_specification]"]', 'Food-grade customer specification E2E-' + String(index + 1).padStart(2, '0'));
      set('[name="lines[' + index + '][warehouse_notes]"]', 'Keep dry and palletized');
      set('[name="lines[' + index + '][production_notes]"]', 'Customer print mark E2E-' + String(index + 1).padStart(2, '0'));
      set('[name="lines[' + index + '][notes]"]', 'Quoted source line ' + String(index + 1));
    });
    if (document.querySelectorAll('.js-quotation-milestones .js-quotation-milestone').length === 0) {
      document.querySelector('.js-quotation-add-milestone').click();
    }
    set('[name="payment_milestones[0][title]"]', 'Quotation total');
    set('[name="payment_milestones[0][amount]"]', ${JSON.stringify(total)});
    set('[name="payment_milestones[0][due_type]"]', 'on_contract');
    document.querySelector('[name="submit_action"]').value = 'save_view';
    return { rows: rows.length, total: document.querySelector('.js-quotation-total')?.innerText || '' };
  })()`);
  assert(filled.rows === lines.length, `Expected ${lines.length} quotation rows, found ${filled.rows}.`);
}

async function submitAction(actionFragment, context, configure = null) {
  const selector = `form.js-sales-cycle-action[action*=${JSON.stringify(actionFragment)}]`;
  if (configure) await configure(selector);
  await submitForm(selector, context);
}

async function setLocale(locale) {
  const result = await evaluate(`fetch(${JSON.stringify(`${baseUrl}/lang/${locale}`)}, {
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  }).then(async response => ({ ok: response.ok, payload: await response.json() }))`);
  assert(result.ok && result.payload.locale === locale, `Unable to switch print locale to ${locale}.`);
}

const manifest = {
  scenario: {},
  prints: [],
  browserErrors,
};

try {
  await navigate(`${baseUrl}/login`);
  if (await evaluate(`Boolean(document.querySelector('#login'))`)) {
    await setField('#login', 'admin');
    await setField('#password', 'admin');
    await submitForm('form.js-auth-form', 'Login');
  }
  assert(!(await currentUrl()).includes('/login'), 'Login did not leave the login page.');

  await navigate(`${baseUrl}/admin/sales/sales-orders/create`);
  await fillOrder({
    customer: 'Customer-990002', lineCount: 1, quantity: '1', price: '200', tax: '28', total: '228',
    reference: 'E2E-CREDIT-HOLD',
  });
  await submitForm('form.js-sales-cycle-form', 'Create credit-hold test order');
  const creditOrderUrl = await currentUrl();
  await navigate(creditOrderUrl);
  const editUrl = await evaluate(`document.querySelector('a[href*="/edit"]')?.href`);
  assert(editUrl, 'Draft credit order did not expose Edit Draft.');
  await navigate(editUrl);
  await setField('#customer_reference', 'E2E-CREDIT-HOLD-EDITED');
  await submitForm('form.js-sales-cycle-form', 'Edit and save draft credit order');
  await submitAction('/submit', 'Submit credit order');
  await submitAction('/approve', 'Evaluate blocked credit order');
  await assertBodyContains('Credit Control', 'Credit-held order');
  await assertBodyContains('Held Credit', 'Credit-held order');
  await submitAction('/credit-override', 'Override credit hold', async (selector) => {
    await setField(`${selector} textarea[name="reason"]`, 'Authorized browser E2E credit exception');
  });
  await assertBodyContains('Authorized browser E2E credit exception', 'Overridden credit order audit trail');
  manifest.scenario.creditOrderUrl = creditOrderUrl;

  await navigate(`${baseUrl}/admin/sales/quotations/create`);
  await fillQuotation({
    customer: 'Customer-990001',
    reference: 'E2E-100-CARTONS-PLUS-SERVICE',
    total: '12540',
    lines: [
      { product: 'Product-E2E-SPOON', unit: 'Unit-E2E-CARTON', description: 'TEST Plastic Spoon', quantity: '100', price: '100', taxRate: '14', packaging: '1000 pieces / export carton' },
      { product: 'Product-E2E-SERVICE', unit: 'Unit-E2E-PIECE', description: 'TEST Packaging Design Service', quantity: '1', price: '1000', taxRate: '14', packaging: 'Digital service deliverable' },
    ],
  });
  await submitForm('form.js-quotation-form', 'Create canonical main quotation');
  const quotationUrl = await currentUrl();
  const quotationDoc = quotationUrl.split('/').pop();
  await click('#quotation-lines-tab', 'Open main quotation lines');
  await assertBodyContains('TEST Plastic Spoon', 'Reloaded main quotation');
  await assertBodyContains('TEST Packaging Design Service', 'Reloaded main quotation');
  await assertBodyContains('1000 pieces / export carton', 'Reloaded main quotation');
  await clickAndWaitForNavigation('.js-quotation-status-action[data-url*="mark-sent"]', 'Mark main quotation sent');
  await clickAndWaitForNavigation('.js-quotation-status-action[data-url*="accept"]', 'Accept main quotation');
  await clickAndWaitForNavigation('.js-quotation-convert-action', 'Convert main quotation to sales order');
  const orderUrl = await currentUrl();
  const orderDoc = orderUrl.split('/').pop();
  await navigate(orderUrl);
  await assertBodyContains('TEST Plastic Spoon', 'Reloaded main order');
  await assertBodyContains('TEST Packaging Design Service', 'Reloaded main order');
  await assertBodyContains(quotationDoc, 'Main order source quotation');
  await assertBodyContains('1000 pieces / export carton', 'Reloaded main order');
  await submitAction('/submit', 'Submit main order');
  await submitAction('/approve', 'Approve main order');
  await assertBodyContains('Approved', 'Approved main order');

  await submitAction('/reservations', 'Reserve available 30 cartons', async (selector) => {
    await setField(`${selector} input[name="quantity"]`, '30');
  });
  await assertBodyContains('Active reservation', 'Reserved order');

  await submitAction('/production-requests', 'Create 70-carton production requirement', async (selector) => {
    const result = await evaluate(`(() => {
      const form = document.querySelector(${JSON.stringify(selector)});
      const quantities = [...form.querySelectorAll('input[name$="[quantity]"]')];
      quantities.forEach(field => { field.value = '70'; field.dispatchEvent(new Event('input', { bubbles: true })); });
      return quantities.length;
    })()`);
    assert(result === 1, `Production request expected one physical source line, found ${result}.`);
  });
  const productionSalesUrl = await currentUrl();
  const productionDoc = productionSalesUrl.split('/').pop();
  const productionUrl = `${baseUrl}/admin/production/work-orders/${productionDoc}`;
  await navigate(productionUrl);
  await assertBodyContains(orderDoc, 'Manufacturing work order source Sales Order');
  await assertBodyContains('1000 pieces / export carton', 'Manufacturing work order');
  assert(!(await bodyText()).includes('Unit price'), 'Manufacturing work order leaked selling prices.');
  await submitAction('/complete', 'Post 70-carton production receipt', async (selector) => {
    const result = await evaluate(`(() => {
      const form = document.querySelector(${JSON.stringify(selector)});
      const quantities = [...form.querySelectorAll('input[name$="[quantity]"]')];
      quantities.forEach(field => { field.value = '70'; field.dispatchEvent(new Event('input', { bubbles: true })); });
      return quantities.length;
    })()`);
    assert(result === 1, `Production completion expected one line, found ${result}.`);
  });
  await assertBodyContains('Completed', 'Completed production work order');

  await navigate(orderUrl);
  await submitAction('/deliveries', 'Post first 60-carton delivery', async (selector) => {
    const count = await evaluate(`(() => {
      const form = document.querySelector(${JSON.stringify(selector)});
      form.querySelector('[name="recipient_name"]').value = 'E2E Receiving Clerk';
      form.querySelector('[name="vehicle_number"]').value = 'E2E-TRUCK-60';
      const quantities = [...form.querySelectorAll('input[name$="[quantity]"]')];
      quantities.forEach(field => { field.value = '60'; field.dispatchEvent(new Event('input', { bubbles: true })); });
      return quantities.length;
    })()`);
    assert(count === 1, `First delivery expected one physical line, found ${count}.`);
  });
  const deliveryOneUrl = await currentUrl();
  const deliveryOneDoc = deliveryOneUrl.split('/').pop();
  await navigate(deliveryOneUrl);
  assert(!(await bodyText()).includes('Unit price'), 'Warehouse delivery view leaked selling prices.');

  await navigate(orderUrl);
  await assertBodyContains('Partially Fulfilled', 'Order after first delivery');
  await assertBodyContains('40.00000000', 'Order remaining quantity after first delivery');
  await submitAction('/deliveries', 'Post second 40-carton delivery', async (selector) => {
    const count = await evaluate(`(() => {
      const form = document.querySelector(${JSON.stringify(selector)});
      form.querySelector('[name="recipient_name"]').value = 'E2E Receiving Clerk';
      form.querySelector('[name="vehicle_number"]').value = 'E2E-TRUCK-40';
      const quantities = [...form.querySelectorAll('input[name$="[quantity]"]')];
      quantities.forEach(field => { field.value = '40'; field.dispatchEvent(new Event('input', { bubbles: true })); });
      return quantities.length;
    })()`);
    assert(count === 1, `Second delivery expected one physical line, found ${count}.`);
  });
  const deliveryTwoUrl = await currentUrl();
  const deliveryTwoDoc = deliveryTwoUrl.split('/').pop();

  await navigate(orderUrl);
  await assertBodyContains('Fulfilled', 'Order after second delivery');
  const invoiceLineCount = await evaluate(`document.querySelectorAll('form[action*="/invoices"] input[name^="lines"][name$="[quantity]"]').length`);
  assert(invoiceLineCount === 3, `Fully delivered mixed order exposed ${invoiceLineCount} invoice source lines instead of 3.`);
  await submitAction('/invoices', 'Create invoice from two deliveries', async (selector) => {
    await setField(`${selector} [name="payment_schedules[0][amount]"]`, '3762');
    await setField(`${selector} [name="payment_schedules[1][amount]"]`, '3762');
    await setField(`${selector} [name="payment_schedules[2][amount]"]`, '5016');
  });
  const invoiceUrl = await currentUrl();
  const invoiceDoc = invoiceUrl.split('/').pop();
  await navigate(invoiceUrl);
  await assertBodyContains(deliveryOneDoc, 'Draft invoice lineage');
  await assertBodyContains(deliveryTwoDoc, 'Draft invoice lineage');
  await submitAction('/post', 'Post sales invoice');
  await assertBodyContains('Posted', 'Posted sales invoice');
  await assertBodyContains('Not Configured', 'Electronic invoice status');
  await assertBodyContains('12,540', 'Posted invoice total');

  await navigate(`${baseUrl}/admin/sales/customer-receipts/create?invoice=${encodeURIComponent(invoiceDoc)}`);
  await setField('#amount', '3762');
  await setField('#payment_method', 'cash');
  await setField('#cashbox_doc_num', 'Cashbox-E2E');
  await click('[data-receipt-allocation-row] [data-receipt-allocation-toggle]', 'Select first installment');
  await setField('[data-receipt-allocation-row] input[name$="[amount]"]', '3762');
  await submitForm('form.js-sales-cycle-form', 'Approve partial cash collection');
  const receiptOneUrl = await currentUrl();
  const canonicalCashPrint = await evaluate(`document.querySelector('a[href*="cash-receipt-vouchers"][href$="/print"]')?.href`);
  assert(canonicalCashPrint, 'Cash collection did not link to its canonical Finance voucher print.');

  await navigate(`${baseUrl}/admin/sales/customer-receipts/create?invoice=${encodeURIComponent(invoiceDoc)}`);
  await setField('#amount', '8778');
  await setField('#payment_method', 'cheque');
  await setField('#bank_account_doc_num', 'BankAccount-E2E');
  await setField('#reference_no', 'E2E-CHQ-8778');
  await setField('#cheque_due_date', isoDate(30));
  await setField('#external_bank_name', 'E2E Drawer Bank');
  const allocationRows = await evaluate(`(() => {
    const rows = [...document.querySelectorAll('[data-receipt-allocation-row]')];
    const amounts = ['3762', '5016'];
    rows.forEach((row, index) => {
      row.querySelector('[data-receipt-allocation-toggle]').click();
      row.querySelector('input[name$="[amount]"]').value = amounts[index];
      row.querySelector('input[name$="[amount]"]').dispatchEvent(new Event('input', { bubbles: true }));
    });
    return rows.length;
  })()`);
  assert(allocationRows === 2, `Cheque collection expected two open installments, found ${allocationRows}.`);
  await submitForm('form.js-sales-cycle-form', 'Approve cheque collection for remainder');
  const receiptTwoUrl = await currentUrl();
  const canonicalChequePrint = await evaluate(`document.querySelector('a[href*="/cheques/"][href$="/print"]')?.href`);
  assert(canonicalChequePrint, 'Cheque collection did not link to its canonical Finance cheque print.');

  await navigate(invoiceUrl);
  const settledOutstanding = await evaluate(`(() => {
    const label = [...document.querySelectorAll('strong')]
      .find((node) => node.innerText.trim() === 'Outstanding');

    return label?.parentElement?.innerText ?? '';
  })()`);
  assert(/0(?:[.,]0+)?/.test(settledOutstanding), `Settled invoice still had an outstanding balance: ${settledOutstanding}`);
  const selectedReturns = await evaluate(`(() => {
    const row = [...document.querySelectorAll('[data-sales-return-line]')]
      .find(candidate => candidate.innerText.includes('TEST Plastic Spoon'));
    if (!row) return 0;
    row.querySelector('[data-sales-return-toggle]').click();
    const input = row.querySelector('input[name$="[quantity]"]');
    input.value = '10';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    return 1;
  })()`);
  assert(selectedReturns === 1, `Return selection expected one physical invoice line, found ${selectedReturns}.`);
  await submitAction('/returns', 'Create 10-carton sales return');
  const returnUrl = await currentUrl();
  const returnDoc = returnUrl.split('/').pop();
  await submitAction('/authorize', 'Authorize sales return');
  await submitAction('/receive', 'Receive sales return for quality');
  const qualityLineCount = await evaluate(`(() => {
    const rows = [...document.querySelectorAll('form[action*="/inspect"] tbody tr')];
    const dispositions = [['7', '3']];
    rows.forEach((row, index) => {
      const fields = row.querySelectorAll('input[name$="[saleable_quantity]"], input[name$="[quarantine_quantity]"]');
      fields[0].value = dispositions[index][0];
      fields[1].value = dispositions[index][1];
      fields[0].dispatchEvent(new Event('input', { bubbles: true }));
      fields[1].dispatchEvent(new Event('input', { bubbles: true }));
    });
    return rows.length;
  })()`);
  assert(qualityLineCount === 1, `Quality disposition expected one line, found ${qualityLineCount}.`);
  await submitAction('/inspect', 'Post 7 saleable / 3 quarantine quality disposition');
  await assertBodyContains('Inspected', 'Inspected return');
  await submitAction('/close', 'Close return and post credit note');
  await assertBodyContains('Closed', 'Closed return');

  await navigate(invoiceUrl);
  const creditNoteUrl = await evaluate(`(() => {
    const heading = [...document.querySelectorAll('strong')].find(node => node.innerText.trim() === 'Credit Notes');
    return heading?.parentElement?.querySelector('a')?.href || '';
  })()`);
  assert(creditNoteUrl, 'Invoice lineage did not expose its posted Credit Note link.');
  const creditNoteDoc = creditNoteUrl.split('/').pop();
  await navigate(creditNoteUrl);
  await assertBodyContains(invoiceDoc, 'Credit Note original invoice lineage');
  await assertBodyContains('1,140', 'Credit Note tax-inclusive value');

  const stressLines = Array.from({ length: 25 }, (_, index) => ({
    product: 'Product-E2E-SERVICE',
    unit: 'Unit-E2E-PIECE',
    description: `TEST Stress Service ${String(index + 1).padStart(2, '0')}`,
    quantity: '1',
    price: '100',
    taxRate: '14',
    packaging: `Stress deliverable ${String(index + 1).padStart(2, '0')}`,
  }));
  await navigate(`${baseUrl}/admin/sales/quotations/create`);
  await fillQuotation({ customer: 'Customer-990001', lines: stressLines, total: '2850', reference: 'E2E-25-LINE-STRESS' });
  await submitForm('form.js-quotation-form', 'Create 25-line stress quotation');
  const stressQuotationUrl = await currentUrl();
  const stressQuotationDoc = stressQuotationUrl.split('/').pop();
  await click('#quotation-lines-tab', 'Open stress quotation lines');
  await assertBodyContains('TEST Stress Service 25', 'Reloaded stress quotation');
  await clickAndWaitForNavigation('.js-quotation-status-action[data-url*="mark-sent"]', 'Mark stress quotation sent');
  await clickAndWaitForNavigation('.js-quotation-status-action[data-url*="accept"]', 'Accept stress quotation');
  await clickAndWaitForNavigation('.js-quotation-convert-action', 'Convert stress quotation');
  const stressOrderUrl = await currentUrl();
  const stressOrderDoc = stressOrderUrl.split('/').pop();
  await assertBodyContains(stressQuotationDoc, 'Stress order source quotation');
  await assertBodyContains('TEST Stress Service 25', 'Stress sales order');
  await submitAction('/submit', 'Submit stress order');
  await submitAction('/approve', 'Approve stress order');
  const stressInvoiceLineCount = await evaluate(`document.querySelectorAll('form[action*="/invoices"] input[name^="lines"][name$="[quantity]"]').length`);
  assert(stressInvoiceLineCount === 25, `Stress order exposed ${stressInvoiceLineCount} invoice lines instead of 25.`);
  await submitAction('/invoices', 'Create 25-line stress invoice', async (selector) => {
    await setField(`${selector} [name="payment_schedules[0][amount]"]`, '855');
    await setField(`${selector} [name="payment_schedules[1][amount]"]`, '855');
    await setField(`${selector} [name="payment_schedules[2][amount]"]`, '1140');
  });
  const stressInvoiceUrl = await currentUrl();
  const stressInvoiceDoc = stressInvoiceUrl.split('/').pop();
  await submitAction('/post', 'Post 25-line stress invoice');
  await assertBodyContains('2,850', 'Posted stress invoice total');

  const reportQuery = new URLSearchParams({
    from: isoDate(-1),
    to: isoDate(31),
    customer_doc_num: 'Customer-990001',
    product_doc_num: 'Product-E2E-SPOON',
    quotation_doc_num: quotationDoc,
    order_doc_num: orderDoc,
    invoice_doc_num: invoiceDoc,
    quotation_status: 'converted',
    order_status: 'fulfilled',
    overdue_state: 'not_overdue',
    payment_state: 'settled',
    return_reason: 'customer_rejection',
    quality_disposition: 'saleable',
  }).toString();
  const salesReportUrl = `${baseUrl}/admin/reports/sales/sales-orders?${reportQuery}`;
  await navigate(salesReportUrl);
  await assertBodyContains('Sales Cycle Operational Report', 'Canonical Sales report');
  await assertBodyContains(orderDoc, 'Canonical Sales report order lineage');
  await assertBodyContains('E2E Main Customer', 'Canonical Sales report customer');
  await assertBodyContains('TEST Plastic Spoon', 'Canonical Sales report product');
  await assertBodyContains('Customer Rejection', 'Canonical Sales report return reason');

  const reportYear = today.getUTCFullYear();
  const customerStatementUrl = `${baseUrl}/admin/accounting/reports/customer-statement?run=1&customer_doc_num=Customer-990001&from_date=${reportYear}-01-01&to_date=${reportYear}-12-31`;
  await navigate(customerStatementUrl);
  await assertBodyContains('Customer-990001', 'Canonical Customer Statement customer');
  await assertBodyContains(invoiceDoc, 'Canonical Customer Statement invoice');
  await assertBodyContains('CR-00001', 'Canonical Customer Statement cash receipt');
  await assertBodyContains(creditNoteDoc, 'Canonical Customer Statement credit note');

  const printUrls = [
    ['quotation', `${baseUrl}/admin/sales/quotations/${quotationDoc}/print`, true],
    ['sales-order', `${baseUrl}/admin/sales/sales-orders/${orderDoc}/print`, true],
    ['production-request', `${baseUrl}/admin/production/work-orders/${productionDoc}/print`, false],
    ['delivery-1', `${baseUrl}/admin/sales/delivery-notes/${deliveryOneDoc}/print`, false],
    ['delivery-2', `${baseUrl}/admin/sales/delivery-notes/${deliveryTwoDoc}/print`, false],
    ['sales-invoice', `${baseUrl}/admin/sales/sales-invoices/${invoiceDoc}/print`, true],
    ['payment-schedule', `${baseUrl}/admin/sales/sales-invoices/${invoiceDoc}/payment-schedule/print`, true],
    ['cash-customer-receipt', `${receiptOneUrl}/print`, true],
    ['cheque-customer-receipt', `${receiptTwoUrl}/print`, true],
    ['finance-cash-receipt-voucher', canonicalCashPrint, true],
    ['finance-received-cheque', canonicalChequePrint, true],
    ['sales-return', `${baseUrl}/admin/sales/sales-returns/${returnDoc}/print`, true],
    ['quality-disposition', `${baseUrl}/admin/sales/sales-returns/${returnDoc}/quality-disposition/print`, false],
    ['credit-note', `${baseUrl}/admin/sales/sales-invoices/${creditNoteDoc}/print`, true],
    ['stress-quotation-25-lines', `${baseUrl}/admin/sales/quotations/${stressQuotationDoc}/print`, true],
    ['stress-sales-order-25-lines', `${baseUrl}/admin/sales/sales-orders/${stressOrderDoc}/print`, true],
    ['stress-sales-invoice-25-lines', `${baseUrl}/admin/sales/sales-invoices/${stressInvoiceDoc}/print`, true],
    ['sales-cycle-report', `${baseUrl}/admin/reports/sales/sales-orders/print?${reportQuery}`, true],
  ];

  for (const locale of ['en', 'ar']) {
    await setLocale(locale);
    for (const [name, url] of printUrls) {
      const pdf = await evaluate(`(async () => {
        const response = await fetch(${JSON.stringify(url)}, { credentials: 'same-origin' });
        const bytes = new Uint8Array(await response.arrayBuffer());
        let binary = '';
        for (let offset = 0; offset < bytes.length; offset += 0x8000) {
          binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
        }
        return {
          status: response.status,
          contentType: response.headers.get('content-type') || '',
          disposition: response.headers.get('content-disposition') || '',
          signature: String.fromCharCode(...bytes.subarray(0, 5)),
          data: btoa(binary),
          byteLength: bytes.length,
        };
      })()`);
      assert(pdf.status === 200, `${name} PDF returned HTTP ${pdf.status}.`);
      assert(pdf.contentType.startsWith('application/pdf'), `${name} PDF returned ${pdf.contentType}.`);
      assert(pdf.disposition.startsWith('inline;'), `${name} PDF was not streamed inline.`);
      assert(pdf.signature === '%PDF-', `${name} response did not begin with %PDF-.`);
      const filename = `${locale}-${name}.pdf`;
      await writeFile(path.join(artifactDirectory, filename), Buffer.from(pdf.data, 'base64'));
      manifest.prints.push({
        locale,
        name,
        filename,
        bytes: pdf.byteLength,
        status: pdf.status,
        contentType: pdf.contentType,
        disposition: pdf.disposition,
        signature: pdf.signature,
      });
    }
  }

  manifest.scenario = {
    ...manifest.scenario,
    orderUrl,
    quotationUrl,
    productionUrl,
    deliveryOneUrl,
    deliveryTwoUrl,
    invoiceUrl,
    receiptOneUrl,
    receiptTwoUrl,
    returnUrl,
    creditNoteUrl,
    stressQuotationUrl,
    stressOrderUrl,
    stressInvoiceUrl,
    salesReportUrl,
    customerStatementUrl,
    documents: { quotationDoc, orderDoc, productionDoc, deliveryOneDoc, deliveryTwoDoc, invoiceDoc, returnDoc, creditNoteDoc, stressQuotationDoc, stressOrderDoc, stressInvoiceDoc },
    invoiceLineCount,
    stressInvoiceLineCount,
    canonicalCashPrint,
    canonicalChequePrint,
  };

  const actionableErrors = [...new Set(browserErrors)].filter((message) => message && !message.includes('favicon.ico'));
  await writeFile(path.join(artifactDirectory, 'manifest.json'), JSON.stringify(manifest, null, 2));
  assert(actionableErrors.length === 0, `Browser console errors detected: ${actionableErrors.join(' | ')}`);
  process.stdout.write(`${JSON.stringify(manifest, null, 2)}\n`);
} finally {
  client.close();
}
