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
  if (entry.level === 'error' && !entry.url?.endsWith('/favicon.ico')) browserErrors.push(entry.text);
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
  assert((await bodyText()).includes(text), `${context} did not contain “${text}”.`);
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
    const alert = await evaluate(`document.querySelector('.js-sales-form-alert:not(.d-none), .js-auth-alert:not(.d-none)')?.innerText || ''`);
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

  await navigate(`${baseUrl}/admin/sales/sales-orders/create`);
  await fillOrder({
    customer: 'Customer-990001', lineCount: 25, quantity: '4', price: '100', tax: '56', total: '11400',
    reference: 'E2E-100-CARTONS',
  });
  await submitForm('form.js-sales-cycle-form', 'Create 100-carton main order');
  const orderUrl = await currentUrl();
  const orderDoc = orderUrl.split('/').pop();
  await navigate(orderUrl);
  await assertBodyContains('TEST Plastic Spoon', 'Reloaded main order');
  await assertBodyContains('1000 pieces / export carton', 'Reloaded main order');
  await submitAction('/submit', 'Submit main order');
  await submitAction('/approve', 'Approve main order');
  await assertBodyContains('Approved', 'Approved main order');

  await submitAction('/reservations', 'Reserve opening stock', async (selector) => {
    await setField(`${selector} input[name="quantity"]`, '4');
  });
  await assertBodyContains('Active reservation', 'Reserved order');

  await submitAction('/production-requests', 'Create 90-carton production requirement', async (selector) => {
    const result = await evaluate(`(() => {
      const form = document.querySelector(${JSON.stringify(selector)});
      const quantities = [...form.querySelectorAll('input[name$="[quantity]"]')];
      quantities.forEach(field => { field.value = '3.6'; field.dispatchEvent(new Event('input', { bubbles: true })); });
      return quantities.length;
    })()`);
    assert(result === 25, `Production request expected 25 source lines, found ${result}.`);
  });
  const productionSalesUrl = await currentUrl();
  const productionDoc = productionSalesUrl.split('/').pop();
  const productionUrl = `${baseUrl}/admin/production/work-orders/${productionDoc}`;
  await navigate(productionUrl);
  await assertBodyContains(orderDoc, 'Manufacturing work order source Sales Order');
  await assertBodyContains('1000 pieces / export carton', 'Manufacturing work order');
  assert(!(await bodyText()).includes('Unit price'), 'Manufacturing work order leaked selling prices.');
  await submitAction('/complete', 'Post 90-carton production receipt', async (selector) => {
    const result = await evaluate(`(() => {
      const form = document.querySelector(${JSON.stringify(selector)});
      const quantities = [...form.querySelectorAll('input[name$="[quantity]"]')];
      quantities.forEach(field => { field.value = '3.6'; field.dispatchEvent(new Event('input', { bubbles: true })); });
      return quantities.length;
    })()`);
    assert(result === 25, `Production completion expected 25 lines, found ${result}.`);
  });
  await assertBodyContains('Completed', 'Completed production work order');

  await navigate(orderUrl);
  await submitAction('/deliveries', 'Post first 60-carton delivery', async (selector) => {
    const count = await evaluate(`(() => {
      const form = document.querySelector(${JSON.stringify(selector)});
      form.querySelector('[name="recipient_name"]').value = 'E2E Receiving Clerk';
      form.querySelector('[name="vehicle_number"]').value = 'E2E-TRUCK-60';
      const quantities = [...form.querySelectorAll('input[name$="[quantity]"]')];
      quantities.forEach(field => { field.value = '2.4'; field.dispatchEvent(new Event('input', { bubbles: true })); });
      return quantities.length;
    })()`);
    assert(count === 25, `First delivery expected 25 lines, found ${count}.`);
  });
  const deliveryOneUrl = await currentUrl();
  const deliveryOneDoc = deliveryOneUrl.split('/').pop();
  await navigate(deliveryOneUrl);
  assert(!(await bodyText()).includes('Unit price'), 'Warehouse delivery view leaked selling prices.');

  await navigate(orderUrl);
  await assertBodyContains('Partially Fulfilled', 'Order after first delivery');
  await assertBodyContains('1.60000000', 'Order remaining quantity after first delivery');
  await submitAction('/deliveries', 'Post second 40-carton delivery', async (selector) => {
    const count = await evaluate(`(() => {
      const form = document.querySelector(${JSON.stringify(selector)});
      form.querySelector('[name="recipient_name"]').value = 'E2E Receiving Clerk';
      form.querySelector('[name="vehicle_number"]').value = 'E2E-TRUCK-40';
      const quantities = [...form.querySelectorAll('input[name$="[quantity]"]')];
      quantities.forEach(field => { field.value = '1.6'; field.dispatchEvent(new Event('input', { bubbles: true })); });
      return quantities.length;
    })()`);
    assert(count === 25, `Second delivery expected 25 lines, found ${count}.`);
  });
  const deliveryTwoUrl = await currentUrl();
  const deliveryTwoDoc = deliveryTwoUrl.split('/').pop();

  await navigate(orderUrl);
  await assertBodyContains('Fulfilled', 'Order after second delivery');
  const invoiceLineCount = await evaluate(`document.querySelectorAll('form[action*="/invoices"] input[name^="lines"][name$="[quantity]"]').length`);
  assert(invoiceLineCount === 50, `Fully delivered order exposed ${invoiceLineCount} invoice source lines instead of 50.`);
  await submitAction('/invoices', 'Create invoice from two deliveries', async (selector) => {
    await setField(`${selector} [name="payment_schedules[0][amount]"]`, '3420');
    await setField(`${selector} [name="payment_schedules[1][amount]"]`, '3420');
    await setField(`${selector} [name="payment_schedules[2][amount]"]`, '4560');
  });
  const invoiceUrl = await currentUrl();
  const invoiceDoc = invoiceUrl.split('/').pop();
  await navigate(invoiceUrl);
  await assertBodyContains(deliveryOneDoc, 'Draft invoice lineage');
  await assertBodyContains(deliveryTwoDoc, 'Draft invoice lineage');
  await submitAction('/post', 'Post sales invoice');
  await assertBodyContains('Posted', 'Posted sales invoice');
  await assertBodyContains('Not Configured', 'Electronic invoice status');
  await assertBodyContains('11,400', 'Posted invoice total');

  await navigate(`${baseUrl}/admin/sales/customer-receipts/create?invoice=${encodeURIComponent(invoiceDoc)}`);
  await setField('#amount', '3420');
  await setField('#payment_method', 'cash');
  await setField('#cashbox_doc_num', 'Cashbox-E2E');
  await click('[data-receipt-allocation-row] [data-receipt-allocation-toggle]', 'Select first installment');
  await setField('[data-receipt-allocation-row] input[name$="[amount]"]', '3420');
  await submitForm('form.js-sales-cycle-form', 'Approve partial cash collection');
  const receiptOneUrl = await currentUrl();
  const canonicalCashPrint = await evaluate(`document.querySelector('a[href*="cash-receipt-vouchers"][href$="/print"]')?.href`);
  assert(canonicalCashPrint, 'Cash collection did not link to its canonical Finance voucher print.');

  await navigate(`${baseUrl}/admin/sales/customer-receipts/create?invoice=${encodeURIComponent(invoiceDoc)}`);
  await setField('#amount', '7980');
  await setField('#payment_method', 'cheque');
  await setField('#bank_account_doc_num', 'BankAccount-E2E');
  await setField('#reference_no', 'E2E-CHQ-7980');
  await setField('#cheque_due_date', isoDate(30));
  await setField('#external_bank_name', 'E2E Drawer Bank');
  const allocationRows = await evaluate(`(() => {
    const rows = [...document.querySelectorAll('[data-receipt-allocation-row]')];
    const amounts = ['3420', '4560'];
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
    const rows = [...document.querySelectorAll('[data-sales-return-line]')].slice(0, 5);
    const quantities = ['2.4', '2.4', '2.4', '2.4', '0.4'];
    rows.forEach((row, index) => {
      row.querySelector('[data-sales-return-toggle]').click();
      const input = row.querySelector('input[name$="[quantity]"]');
      input.value = quantities[index];
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    return rows.length;
  })()`);
  assert(selectedReturns === 5, `Return selection expected five invoice lines, found ${selectedReturns}.`);
  await submitAction('/returns', 'Create 10-carton sales return');
  const returnUrl = await currentUrl();
  const returnDoc = returnUrl.split('/').pop();
  await submitAction('/authorize', 'Authorize sales return');
  await submitAction('/receive', 'Receive sales return for quality');
  const qualityLineCount = await evaluate(`(() => {
    const rows = [...document.querySelectorAll('form[action*="/inspect"] tbody tr')];
    const dispositions = [
      ['2.4', '0'], ['2.4', '0'], ['2.2', '0.2'], ['0', '2.4'], ['0', '0.4']
    ];
    rows.forEach((row, index) => {
      const fields = row.querySelectorAll('input[name$="[saleable_quantity]"], input[name$="[quarantine_quantity]"]');
      fields[0].value = dispositions[index][0];
      fields[1].value = dispositions[index][1];
      fields[0].dispatchEvent(new Event('input', { bubbles: true }));
      fields[1].dispatchEvent(new Event('input', { bubbles: true }));
    });
    return rows.length;
  })()`);
  assert(qualityLineCount === 5, `Quality disposition expected five lines, found ${qualityLineCount}.`);
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

  const printUrls = [
    ['sales-order', `${baseUrl}/admin/sales/sales-orders/${orderDoc}/print`, true],
    ['production-request', `${baseUrl}/admin/production/work-orders/${productionDoc}/print`, false],
    ['delivery-1', `${baseUrl}/admin/sales/delivery-notes/${deliveryOneDoc}/print`, false],
    ['delivery-2', `${baseUrl}/admin/sales/delivery-notes/${deliveryTwoDoc}/print`, false],
    ['sales-invoice', `${baseUrl}/admin/sales/sales-invoices/${invoiceDoc}/print`, true],
    ['payment-schedule', `${baseUrl}/admin/sales/sales-invoices/${invoiceDoc}/payment-schedule/print`, true],
    ['customer-receipt', `${receiptOneUrl}/print`, true],
    ['sales-return', `${baseUrl}/admin/sales/sales-returns/${returnDoc}/print`, true],
    ['quality-disposition', `${baseUrl}/admin/sales/sales-returns/${returnDoc}/quality-disposition/print`, false],
    ['credit-note', `${baseUrl}/admin/sales/sales-invoices/${creditNoteDoc}/print`, true],
  ];

  for (const locale of ['en', 'ar']) {
    await setLocale(locale);
    for (const [name, url, financial] of printUrls) {
      await navigate(url);
      const printState = await evaluate(`(() => ({
        lang: document.documentElement.lang,
        direction: document.querySelector('.sales-cycle-print')?.getAttribute('dir'),
        rows: document.querySelectorAll('.sales-cycle-print tbody tr').length,
        hasPriceHeading: document.body.innerText.includes('Unit price'),
        title: document.title
      }))()`);
      assert(printState.lang === locale, `${name} print did not render in ${locale}.`);
      assert(printState.direction === (locale === 'ar' ? 'rtl' : 'ltr'), `${name} print direction was incorrect for ${locale}.`);
      assert(printState.rows > 0 || name === 'payment-schedule', `${name} print had no printable rows.`);
      if (!financial && locale === 'en') assert(!printState.hasPriceHeading, `${name} operational print leaked selling prices.`);

      await client.send('Emulation.setEmulatedMedia', { media: 'print' });
      const printCss = await evaluate(`(() => ({
        actions: getComputedStyle(document.querySelector('.page-print-actions')).display,
        tableHeader: document.querySelector('thead') ? getComputedStyle(document.querySelector('thead')).display : null,
        horizontalOverflow: document.querySelector('.sales-cycle-print').scrollWidth > document.querySelector('.sales-cycle-print').clientWidth + 2
      }))()`);
      assert(printCss.actions === 'none', `${name} print still displayed browser actions.`);
      if (printCss.tableHeader) assert(printCss.tableHeader === 'table-header-group', `${name} print did not preserve repeatable table headers.`);
      assert(!printCss.horizontalOverflow, `${name} print had horizontal content overflow.`);
      const pdf = await client.send('Page.printToPDF', { printBackground: true, preferCSSPageSize: true });
      const filename = `${locale}-${name}.pdf`;
      await writeFile(path.join(artifactDirectory, filename), Buffer.from(pdf.data, 'base64'));
      manifest.prints.push({ locale, name, filename, bytes: Buffer.byteLength(pdf.data, 'base64'), ...printState });
      await client.send('Emulation.setEmulatedMedia', { media: 'screen' });
    }
  }

  await navigate(canonicalCashPrint);
  await assertBodyContains('E2E Main Customer', 'Canonical Finance cash voucher print');
  await navigate(canonicalChequePrint);
  await assertBodyContains('E2E-CHQ-7980', 'Canonical Finance cheque print');

  manifest.scenario = {
    ...manifest.scenario,
    orderUrl,
    productionUrl,
    deliveryOneUrl,
    deliveryTwoUrl,
    invoiceUrl,
    receiptOneUrl,
    receiptTwoUrl,
    returnUrl,
    creditNoteUrl,
    documents: { orderDoc, productionDoc, deliveryOneDoc, deliveryTwoDoc, invoiceDoc, returnDoc, creditNoteDoc },
    invoiceLineCount,
    canonicalCashPrint,
    canonicalChequePrint,
  };

  const actionableErrors = [...new Set(browserErrors)].filter((message) => message && !message.includes('favicon.ico'));
  assert(actionableErrors.length === 0, `Browser console errors detected: ${actionableErrors.join(' | ')}`);
  await writeFile(path.join(artifactDirectory, 'manifest.json'), JSON.stringify(manifest, null, 2));
  process.stdout.write(`${JSON.stringify(manifest, null, 2)}\n`);
} finally {
  client.close();
}
