import fs from 'node:fs';

const browserBaseUrl = 'http://127.0.0.1:8123';
const debugBaseUrl = 'http://127.0.0.1:9223';

class ChromePage {
    constructor(webSocketUrl) {
        this.socket = new WebSocket(webSocketUrl);
        this.sequence = 0;
        this.pending = new Map();
    }

    async connect() {
        await new Promise((resolve, reject) => {
            this.socket.addEventListener('open', resolve, { once: true });
            this.socket.addEventListener('error', reject, { once: true });
        });

        this.socket.addEventListener('message', (event) => {
            const message = JSON.parse(event.data);

            if (! message.id || ! this.pending.has(message.id)) {
                return;
            }

            const { resolve, reject } = this.pending.get(message.id);
            this.pending.delete(message.id);

            if (message.error) {
                reject(new Error(message.error.message));
                return;
            }

            resolve(message.result);
        });

        await this.send('Page.enable');
        await this.send('Runtime.enable');
        await this.send('Network.enable');
    }

    send(method, params = {}) {
        const id = ++this.sequence;

        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            this.socket.send(JSON.stringify({ id, method, params }));
        });
    }

    async evaluate(expression) {
        const response = await this.send('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        });

        if (response.exceptionDetails) {
            throw new Error(response.exceptionDetails.exception?.description ?? 'Browser evaluation failed.');
        }

        return response.result.value;
    }

    async navigate(path) {
        const destination = path.startsWith('http') ? path : `${browserBaseUrl}${path}`;
        await this.send('Page.navigate', { url: destination });
        const start = Date.now();

        while (Date.now() - start < 10000) {
            const loaded = await this.evaluate(`document.readyState === 'complete' && location.href.startsWith(${JSON.stringify(destination)})`);
            if (loaded) {
                break;
            }

            await new Promise((resolve) => setTimeout(resolve, 100));
        }

        await new Promise((resolve) => setTimeout(resolve, 250));
    }

    async waitFor(browserCallback, timeout = 10000) {
        const start = Date.now();
        const expression = `(${browserCallback.toString()})()`;

        while (Date.now() - start < timeout) {
            if (await this.evaluate(expression)) {
                return;
            }

            await new Promise((resolve) => setTimeout(resolve, 100));
        }

        throw new Error(`Browser wait timed out: ${expression}`);
    }

    async fill(name, value, index = 0) {
        const result = await this.evaluate(`(() => {
            const fields = Array.from(document.querySelectorAll('[name=${JSON.stringify(name)}]'));
            const field = fields[${index}];
            if (! field) return false;
            field.value = ${JSON.stringify(String(value))};
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        })()`);

        if (! result) {
            throw new Error(`Missing browser field: ${name}[${index}]`);
        }
    }

    async submit(selector = 'form', submitSelector = '[type="submit"]') {
        const previousUrl = await this.evaluate('location.href');
        const submitted = await this.evaluate(`(() => {
            const form = document.querySelector(${JSON.stringify(selector)});
            const submitter = form?.querySelector(${JSON.stringify(submitSelector)});
            if (! form || ! submitter) return false;
            form.requestSubmit(submitter);
            return true;
        })()`);

        if (! submitted) {
            throw new Error(`Unable to submit ${selector} using ${submitSelector}`);
        }

        await this.waitFor(() => document.readyState === 'complete');
        await new Promise((resolve) => setTimeout(resolve, 400));

        const errors = await this.evaluate(`(() => Array.from(document.querySelectorAll('.alert-danger:not(.d-none), .invalid-feedback:not(:empty), .js-form-alert:not(.d-none)')).map((element) => element.innerText.trim()).filter(Boolean))()`);
        if (errors.length > 0) {
            throw new Error(`Form submission failed at ${previousUrl}: ${errors.join(' | ')}`);
        }

        return this.evaluate('location.href');
    }

    async click(selector) {
        const clicked = await this.evaluate(`(() => {
            const element = document.querySelector(${JSON.stringify(selector)});
            if (! element) return false;
            element.click();
            return true;
        })()`);

        if (! clicked) {
            throw new Error(`Missing browser action: ${selector}`);
        }

        await new Promise((resolve) => setTimeout(resolve, 500));
    }

    async submitBrowserForm(actionIncludes) {
        const result = await this.evaluate(`(() => {
            const form = Array.from(document.forms).find((candidate) => candidate.method.toLowerCase() !== 'get' && candidate.action.includes(${JSON.stringify(actionIncludes)}));
            if (! form) return Promise.resolve({ missing: true });
            return fetch(form.action, { method: form.method || 'POST', body: new FormData(form) }).then(async (response) => {
                const body = await response.text();
                const parsed = new DOMParser().parseFromString(body, 'text/html');
                return {
                    status: response.status,
                    url: response.url,
                    title: parsed.title,
                    errors: Array.from(parsed.querySelectorAll('.alert-danger, .invalid-feedback')).map((node) => node.textContent.trim()).filter(Boolean).slice(0, 20),
                    excerpt: parsed.body?.innerText.slice(0, 1200) || body.slice(0, 1200),
                };
            });
        })()`);

        if (result.missing) {
            throw new Error(`Missing form whose action includes ${actionIncludes}`);
        }

        if (result.status >= 400 || result.errors.length > 0) {
            throw new Error(`Browser form failed (${result.status}) ${result.url}: ${result.errors.join(' | ')} ${result.excerpt}`);
        }

        await this.navigate(result.url);

        return result.url;
    }

    async postBrowserAction(url, fields = {}) {
        const result = await this.evaluate(`(() => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value;
            const data = new FormData();
            data.append('_token', token || '');
            for (const [name, value] of Object.entries(${JSON.stringify(fields)})) data.append(name, value);
            return fetch(${JSON.stringify(url)}, { method: 'POST', body: data }).then(async (response) => {
                const body = await response.text();
                const parsed = new DOMParser().parseFromString(body, 'text/html');
                return {
                    status: response.status,
                    url: response.url,
                    errors: Array.from(parsed.querySelectorAll('.alert-danger, .invalid-feedback')).map((node) => node.textContent.trim()).filter(Boolean).slice(0, 20),
                    excerpt: parsed.body?.innerText.slice(0, 1200) || body.slice(0, 1200),
                };
            });
        })()`);

        if (result.status >= 400 || result.errors.length > 0) {
            throw new Error(`Browser action failed (${result.status}) ${result.url}: ${result.errors.join(' | ')} ${result.excerpt}`);
        }

        await this.navigate(result.url);

        return result.url;
    }

    async setFirstOption(name, textIncludes = null) {
        const result = await this.evaluate(`(() => {
            const select = document.querySelector('[name=${JSON.stringify(name)}]');
            if (! select) return false;
            const option = Array.from(select.options).find((candidate) => candidate.value && (${textIncludes === null ? 'true' : `candidate.text.includes(${JSON.stringify(textIncludes)})`}));
            if (! option) return false;
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return option.value;
        })()`);

        if (! result) {
            throw new Error(`No usable option for ${name}`);
        }

        return result;
    }

    async setSelectOption(name, value, textValue = value) {
        const result = await this.evaluate(`(() => {
            const select = document.querySelector('[name=${JSON.stringify(name)}]');
            if (! select) return false;
            let option = Array.from(select.options).find((candidate) => candidate.value === ${JSON.stringify(value)});
            if (! option) {
                option = new Option(${JSON.stringify(textValue)}, ${JSON.stringify(value)}, true, true);
                select.add(option);
            }
            select.value = ${JSON.stringify(value)};
            option.selected = true;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return select.value;
        })()`);

        if (! result) {
            throw new Error(`Unable to select ${value} for ${name}`);
        }
    }

    async submitJsonForm(actionIncludes) {
        const result = await this.evaluate(`(() => {
            const form = document.querySelector('.js-purchase-invoice-form') || Array.from(document.forms).find((candidate) => candidate.method.toLowerCase() !== 'get' && candidate.action.includes(${JSON.stringify(actionIncludes)}));
            if (! form) return Promise.resolve({ missing: true });
            return fetch(form.action, {
                method: form.method || 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
            }).then(async (response) => ({ status: response.status, payload: await response.json() }));
        })()`);

        if (result.missing) {
            throw new Error(`Missing JSON form whose action includes ${actionIncludes}`);
        }

        if (result.status >= 400 || ! result.payload.success) {
            throw new Error(`JSON form failed (${result.status}): ${JSON.stringify(result.payload)}`);
        }

        if (result.payload.redirect) {
            await this.navigate(result.payload.redirect);
        }

        return result.payload;
    }

    async assertContains(textValue) {
        const found = await this.evaluate(`document.body.innerText.includes(${JSON.stringify(textValue)})`);
        if (! found) {
            throw new Error(`Page ${await this.evaluate('location.href')} does not contain: ${textValue}`);
        }
    }

    async screenshot(path) {
        const result = await this.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        fs.writeFileSync(path, Buffer.from(result.data, 'base64'));
    }

    async print(path) {
        const result = await this.send('Page.printToPDF', {
            printBackground: true,
            paperWidth: 8.2677,
            paperHeight: 11.6929,
            marginTop: 0,
            marginBottom: 0,
            marginLeft: 0,
            marginRight: 0,
            preferCSSPageSize: true,
        });
        fs.writeFileSync(path, Buffer.from(result.data, 'base64'));
    }
}

const targets = await fetch(`${debugBaseUrl}/json/list`).then((response) => response.json());
const target = targets.find((candidate) => candidate.type === 'page');

if (! target) {
    throw new Error('No Chrome page target is available.');
}

const page = new ChromePage(target.webSocketDebuggerUrl);
await page.connect();

const purchaseInvoiceUrl = `${browserBaseUrl}/admin/purchases/purchase-invoices/PINV-00001-00001`;
await page.navigate(`${purchaseInvoiceUrl}/edit`);
await page.fill('submit_action', 'save_view');
await page.fill('lines[0][discount_value]', '0');
await page.fill('payment_schedules[2][amount]', '45.92');
await page.submitJsonForm('/admin/purchases/purchase-invoices/PINV-00001-00001');
await page.postBrowserAction(`${purchaseInvoiceUrl}/approve`);
process.stdout.write(`STAGE invoice-approved ${purchaseInvoiceUrl}\n`);

async function createPayment(method, amount, allocationAmounts, chequeNumber = null) {
    await page.navigate('/admin/purchases/supplier-payments/create');
    await page.fill('supplier_doc_num', 'Supplier-BROWSER-A');
    await page.fill('payment_method', method);
    await page.setSelectOption('currency_doc_num', 'CUR-00001', 'EGP');
    await page.fill('amount', amount);
    await page.fill('exchange_rate', '1');
    await page.fill('purchase_order_doc_num', 'PO-00001-00001');
    await page.fill('reason', `Browser ${method} supplier settlement`);
    if (method === 'cash') {
        await page.setFirstOption('cashbox_doc_num');
    } else {
        await page.setFirstOption('bank_account_doc_num');
    }
    if (method === 'cheque') {
        await page.fill('cheque_number', chequeNumber);
        await page.fill('cheque_date', '2026-08-23');
        await page.fill('cheque_due_date', '2026-08-30');
    }
    for (let index = 0; index < allocationAmounts.length; index++) {
        if (allocationAmounts[index] !== null) {
            await page.fill(`allocations[${index}][amount]`, allocationAmounts[index]);
        }
    }
    const paymentUrl = await page.submitBrowserForm('/admin/purchases/supplier-payments');
    await page.postBrowserAction(`${paymentUrl}/approve`);
    return paymentUrl;
}

const cashPaymentUrl = await createPayment('cash', '40', ['40', null, null]);
const cancelledBankPaymentUrl = await createPayment('bank', '50', ['10', '40', null]);
await page.postBrowserAction(`${cancelledBankPaymentUrl}/cancel`, { cancel_reason: 'Browser reversal verification' });
const bankPaymentUrl = await createPayment('bank', '50', ['10', '40', null]);
const chequePaymentUrl = await createPayment('cheque', '20', [null, '10', '10'], 'BROWSER-CHQ-001');
process.stdout.write(`STAGE payments ${JSON.stringify({ cashPaymentUrl, cancelledBankPaymentUrl, bankPaymentUrl, chequePaymentUrl })}\n`);
page.socket.close();
