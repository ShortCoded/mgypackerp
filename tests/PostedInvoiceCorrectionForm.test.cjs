const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('correction rows keep original identities while additions and removals renumber fields and product units', () => {
    const events = {};
    let productSelected;
    const initialized = [];
    const lines = {
        children: [],
        querySelectorAll: () => lines.children,
        append: row => { lines.children.push(row); },
        get lastElementChild() { return lines.children.at(-1); },
    };
    const row = (index, originalId) => {
        const inputs = ['original_line_public_id', 'product_doc_num', 'unit_doc_num', 'quantity'].map(field => ({
            name: `lines[${index}][${field}]`, value: field === 'original_line_public_id' ? originalId : '',
        }));
        const unit = { options: [], replaceChildren(...options) { this.options = options; } };
        return {
            inputs, unit,
            querySelectorAll: selector => selector === '[name]' ? inputs : [],
            querySelector: selector => selector === '[data-correction-unit]' ? unit : null,
            remove() { lines.children.splice(lines.children.indexOf(this), 1); },
        };
    };
    const original = row(0, 'original-a');
    const removed = row(1, 'original-b');
    lines.children.push(original, removed);
    const form = {
        querySelector: selector => selector === '[data-correction-lines]' ? lines : { content: { cloneNode: () => row('__INDEX__', '') } },
        addEventListener: (name, handler) => { events[name] = handler; },
    };
    vm.runInNewContext(fs.readFileSync('public/assets/js/modules/Core/posted-invoice-correction.js', 'utf8'), {
        document: { addEventListener: (_, callback) => callback(), querySelectorAll: () => [form] },
        window: {
            jQuery: () => ({ on: (_, __, handler) => { productSelected = handler; } }),
            AppSelect2Ajax: { init: row => initialized.push(row) },
        },
        Option: function (text, value, _, selected) { Object.assign(this, { text, value, selected }); },
    });
    events.click({ target: { closest: selector => selector === '[data-correction-add]' ? {} : null } });
    const added = lines.children[2];
    assert.equal(added.inputs[0].value, '');
    assert.equal(added.inputs[3].name, 'lines[2][quantity]');
    assert.equal(initialized[0], added);
    events.click({ target: { closest: selector => selector === '[data-correction-remove]' ? { closest: () => removed } : null } });
    assert.equal(lines.children.length, 2);
    assert.equal(original.inputs[0].value, 'original-a');
    assert.equal(added.inputs[3].name, 'lines[1][quantity]');
    productSelected({ target: { closest: () => added }, params: { data: {
        unitDocNum: 'carton', units: [{ id: 'piece', text: 'Piece' }, { id: 'carton', text: 'Carton' }],
    } } });
    assert.equal(added.unit.options.length, 2);
    assert.equal(added.unit.options[1].selected, true);
    productSelected({ target: { closest: () => added }, params: { data: {
        unitDocNum: 'kg', unit_options: [{ id: 'kg', text: 'Kilogram' }],
    } } });
    assert.equal(added.unit.options.length, 1);
    assert.equal(added.unit.options[0].value, 'kg');
});

test('manual item changes reset the old layer and batch while retaining the original line identity', () => {
    let selectProduct;
    let destroyed = false;
    let cachedUrl;
    let removedFlag;
    const identity = { value: 'original-line-42' };
    const batch = { value: 'old-product-batch' };
    const unit = { replaceChildren() {} };
    const layer = { dataset: { layerUrl: '/admin/inventory/documents/select2/receipt-layers' },
        options: ['old-product-layer'], replaceChildren() { this.options = []; } };
    const row = { querySelector: selector => selector === '[data-correction-unit]' ? unit : selector === '[data-correction-layer]' ? layer : null,
        querySelectorAll: selector => selector === '[data-correction-item-metadata]' ? [batch] : [] };
    const form = { dataset: { storeUuid: 'authorized-store', stockStatus: 'available' }, addEventListener() {},
        querySelector: selector => selector === '[name="posting_date"]' ? { value: '2026-09-29' } : { children: [] } };
    const initialized = [];
    vm.runInNewContext(fs.readFileSync('public/assets/js/modules/Core/posted-invoice-correction.js', 'utf8'), {
        document: { addEventListener: (_, callback) => callback(), querySelectorAll: () => [form] },
        window: { location: { origin: 'http://localhost' },
            jQuery: element => element === form ? { on: (_, __, callback) => { selectProduct = callback; } }
                : { hasClass: () => true, select2: action => { destroyed = action === 'destroy'; },
                    removeData: flag => { removedFlag = flag; }, data: (_, value) => { cachedUrl = value; } },
            AppSelect2Ajax: { init: element => initialized.push(element) } },
        URL, Option: function (text, value) { Object.assign(this, { text, value }); },
    });
    selectProduct({ target: { closest: () => row }, params: { data: { id: 'replacement-product', unit_options: [{ id: 'piece', text: 'Piece' }] } } });
    const url = new URL(layer.dataset.url);
    assert.equal(url.searchParams.get('branch_store_uuid'), 'authorized-store');
    assert.equal(url.searchParams.get('product_doc_num'), 'replacement-product');
    assert.equal(url.searchParams.get('document_date'), '2026-09-29');
    assert.equal(url.searchParams.get('stock_status'), 'available');
    assert.equal(cachedUrl, layer.dataset.url);
    assert.equal(destroyed, true);
    assert.equal(removedFlag, 'select2AjaxInitialized');
    assert.deepEqual(layer.options, []);
    assert.equal(batch.value, '');
    assert.equal(identity.value, 'original-line-42');
    assert.equal(initialized[0], row);
});
