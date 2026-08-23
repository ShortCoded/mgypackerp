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

async function createSingleSupplierCycle(label, productDocNum, unitPrice, requiresReceipt) {
    await page.navigate('/admin/purchases/purchase-requisitions/create');
    if (requiresReceipt) {
        await page.setFirstOption('branch_store_uuid', 'Browser Raw Materials Store');
    }
    await page.fill('department', label);
    await page.fill('notes', `${label} browser scenario with no production source`);
    await page.fill('lines[0][product_doc_num]', productDocNum);
    await page.fill('lines[0][unit_doc_num]', 'Unit-BROWSER-KG');
    await page.fill('lines[0][requested_quantity]', '1');
    await page.fill('lines[0][source_type]', 'manual');
    await page.fill('lines[0][specification]', `${label} specification`);
    const requisitionUrl = await page.submitBrowserForm('/admin/purchases/purchase-requisitions');
    await page.postBrowserAction(`${requisitionUrl}/submit`);
    await page.postBrowserAction(`${requisitionUrl}/approve`);

    await page.navigate(`/admin/purchases/request-for-quotations/create/${requisitionUrl.split('/').pop()}`);
    await page.fill('commercial_notes', `${label} RFQ`);
    await page.evaluate(`(() => { const field = document.querySelector('[name="supplier_doc_nums[]"]'); Array.from(field.options).forEach((option) => { option.selected = option.value === 'Supplier-BROWSER-B'; }); field.dispatchEvent(new Event('change', { bubbles: true })); })()`);
    const rfqUrl = await page.submitBrowserForm('/admin/purchases/request-for-quotations/from/');
    await page.postBrowserAction(`${rfqUrl}/issue`);

    await page.navigate(`/admin/purchases/supplier-quotation-entry/create/${rfqUrl.split('/').pop()}`);
    await page.fill('supplier_doc_num', 'Supplier-BROWSER-B');
    await page.setFirstOption('currency_doc_num');
    await page.fill('exchange_rate', '1');
    await page.fill('supplier_reference', `${label.toUpperCase().replaceAll(' ', '-')}-OFFER`);
    await page.fill('valid_until', '2026-09-30');
    await page.fill('lead_time_days', '5');
    await page.fill('payment_terms', '45 days');
    await page.fill('freight_amount', '0');
    await page.fill('lines[0][offered_quantity]', '1');
    await page.fill('lines[0][unit_price]', unitPrice);
    await page.fill('lines[0][discount_amount]', '0');
    await page.fill('lines[0][tax_rate]', '14');
    const quotationUrl = await page.submitBrowserForm('/admin/purchases/supplier-quotation-entry/from/');
    await page.postBrowserAction(`${quotationUrl}/submit`);

    const comparisonUrl = `/admin/purchases/supplier-quotation-comparison/${rfqUrl.split('/').pop()}`;
    await page.navigate(comparisonUrl);
    await page.assertContains('Browser Resin Supplier B');
    await page.navigate(`/admin/purchases/supplier-selection/create/${rfqUrl.split('/').pop()}`);
    const selectionQuantityName = await page.evaluate(`document.querySelector('[name$="[selected_quantity]"]').name`);
    await page.fill(selectionQuantityName, '1');
    const selectionUrl = await page.submitBrowserForm('/admin/purchases/supplier-selection/from/');
    await page.postBrowserAction(`${selectionUrl}/approve`);
    const purchaseOrderUrl = await page.evaluate(`document.querySelector('a[href*="/admin/purchases/purchase-orders/"]').href`);
    await page.navigate(purchaseOrderUrl);
    await page.postBrowserAction(`${purchaseOrderUrl}/approve`);

    let receiptUrl = null;
    if (requiresReceipt) {
        await page.navigate(`/admin/purchases/purchase-order-delivery-schedule/create/${purchaseOrderUrl.split('/').pop()}`);
        await page.fill('schedules[0][scheduled_date]', '2026-09-20');
        await page.fill('schedules[0][scheduled_quantity]', '1');
        await page.submitBrowserForm('/admin/purchases/purchase-order-delivery-schedule/from/');
        await page.navigate(`/admin/purchases/goods-receipt-notes/create/${purchaseOrderUrl.split('/').pop()}`);
        await page.fill('supplier_delivery_note', `${label.toUpperCase().replaceAll(' ', '-')}-DN`);
        await page.setFirstOption('lines[0][delivery_schedule_public_id]');
        await page.fill('lines[0][delivered_quantity]', '1');
        receiptUrl = await page.submitBrowserForm('/admin/purchases/goods-receipt-notes/from/');
        await page.navigate(`/admin/purchases/goods-receipt-inspection/create/${receiptUrl.split('/').pop()}`);
        await page.fill('observations', `${label} accepted in incoming QC`);
        await page.fill('lines[0][accepted_quantity]', '1');
        await page.fill('lines[0][rejected_quantity]', '0');
        await page.submitBrowserForm('/admin/purchases/goods-receipt-inspection/from/');
    }

    const invoiceTotal = (Number(unitPrice) * 1.14).toFixed(2);
    const purchaseOrderDocNum = purchaseOrderUrl.split('/').pop();
    await page.navigate('/admin/purchases/purchase-invoices/create');
    await page.fill('submit_action', 'save_view');
    await page.setSelectOption('financial_period_doc_num', 'Period-00001', '2026');
    await page.setSelectOption('supplier_doc_num', 'Supplier-BROWSER-B', 'Browser Resin Supplier B');
    await page.fill('purchase_order_doc_num', purchaseOrderDocNum);
    await page.fill('supplier_invoice_number', `${label.toUpperCase().replaceAll(' ', '-')}-INV`);
    await page.fill('supplier_invoice_date', '23/08/2026');
    await page.setSelectOption('currency_doc_num', 'CUR-00001', 'EGP');
    await page.fill('exchange_rate', '1');
    await page.fill('payment_type', 'credit');
    const purchaseOrderLineValue = await page.evaluate(`Array.from(document.querySelector('[name="lines[0][purchase_order_line_public_id]"]').options).find((option) => option.value && option.text.includes(${JSON.stringify(purchaseOrderDocNum)}))?.value`);
    await page.fill('lines[0][purchase_order_line_public_id]', purchaseOrderLineValue);
    if (requiresReceipt) {
        const receiptDocNum = receiptUrl.split('/').pop();
        const receiptLineValue = await page.evaluate(`Array.from(document.querySelector('[name="lines[0][receipt_line_public_id]"]').options).find((option) => option.value && option.text.includes(${JSON.stringify(receiptDocNum)}))?.value`);
        await page.fill('lines[0][receipt_line_public_id]', receiptLineValue);
    }
    await page.setSelectOption('lines[0][product_doc_num]', productDocNum, label);
    await page.setSelectOption('lines[0][unit_doc_num]', 'Unit-BROWSER-KG', 'Unit');
    await page.fill('lines[0][quantity]', '1');
    await page.fill('lines[0][unit_price]', unitPrice);
    await page.fill('lines[0][discount_value]', '0');
    await page.fill('lines[0][tax_rate]', '14');
    await page.fill('payment_schedules[0][due_date]', '07/10/2026');
    await page.fill('payment_schedules[0][amount]', invoiceTotal);
    const invoiceResult = await page.submitJsonForm('/admin/purchases/purchase-invoices');
    const invoiceUrl = invoiceResult.redirect;
    const invoiceDocNum = invoiceUrl.split('/').pop();
    await page.postBrowserAction(`${invoiceUrl}/approve`);

    await page.navigate('/admin/purchases/supplier-payments/create');
    await page.fill('supplier_doc_num', 'Supplier-BROWSER-B');
    await page.fill('payment_method', requiresReceipt ? 'cash' : 'bank');
    await page.setSelectOption('currency_doc_num', 'CUR-00001', 'EGP');
    await page.fill('amount', invoiceTotal);
    if (requiresReceipt) {
        await page.setFirstOption('cashbox_doc_num');
    } else {
        await page.setFirstOption('bank_account_doc_num');
    }
    const allocation = await page.evaluate(`Array.from(document.querySelectorAll('[name^="allocations"][name$="[amount]"]')).map((field) => ({ name: field.name, row: field.closest('tr')?.innerText || '' })).find((row) => row.row.includes(${JSON.stringify(invoiceDocNum)}))`);
    await page.fill(allocation.name, invoiceTotal);
    const paymentUrl = await page.submitBrowserForm('/admin/purchases/supplier-payments');
    await page.postBrowserAction(`${paymentUrl}/approve`);

    return { requisitionUrl, rfqUrl, quotationUrl, comparisonUrl, selectionUrl, purchaseOrderUrl, receiptUrl, invoiceUrl, paymentUrl };
}

const manualCycle = await createSingleSupplierCycle('Manual Purchase', 'Product-BROWSER-RESIN', '15', true);
process.stdout.write(`STAGE manual-cycle ${JSON.stringify(manualCycle)}\n`);
const serviceCycle = await createSingleSupplierCycle('Service Purchase', 'Product-BROWSER-SERVICE', '30', false);
process.stdout.write(`STAGE service-cycle ${JSON.stringify(serviceCycle)}\n`);
page.socket.close();
