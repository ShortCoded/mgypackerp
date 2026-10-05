const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function pricingHarness(editable = true) {
  const listeners = {};
  let selectProduct;
  let pendingPrice;
  const requests = [];
  const product = { value: 'Product-A', matches: () => false, closest: () => row };
  const unit = { value: 'Unit-A', innerHTML: '', appendChild(option) { this.value = option.value; } };
  const price = { value: '10.00000000' };
  const display = { textContent: '10' };
  const hint = { remove() {} };
  const form = { matches: () => false, querySelector: () => ({ value: 'fixture' }) };
  const row = {
    dataset: { priceLocked: '1', originalProduct: product.value, originalUnit: unit.value, originalPrice: price.value },
    hasAttribute: () => editable, closest: () => form, matches: () => false,
    querySelector(selector) {
      if (selector === '.js-sales-unit' || selector === '[name$="[unit_doc_num]"]') return unit;
      if (selector === '[name$="[product_doc_num]"]') return product;
      if (selector === '[name$="[unit_price]"]') return price;
      if (selector === '[data-price-display]') return display;
      if (selector === '[data-price-source]') return hint;
      if (selector === '[data-sales-product-details]') return {};
      return null;
    },
  };
  const document = {
    querySelector: () => null, querySelectorAll: () => [], createElement: () => ({}),
    addEventListener(name, callback) { listeners[name] = callback; },
  };
  const jquery = () => ({
    off() { return this; },
    on(name, selector, callback) { if (name === 'select2:select.salesProduct') selectProduct = callback; return this; },
  });
  const window = {
    jQuery: jquery, salesCycleMessages: { priceUrl: '/synthetic-pricing' },
    AppNumbers: { normalize: String, multiply: () => '0', subtract: () => '0', add: () => '0', format: String },
  };
  const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/modules/Sales/sales-cycle.js'), 'utf8');
  vm.runInNewContext(source, { window, document, URLSearchParams, fetch(url) {
    requests.push(url);
    return new Promise(resolve => { pendingPrice = resolve; });
  } });
  listeners.DOMContentLoaded();
  return {
    row, price, requests,
    select(value) {
      product.value = value;
      selectProduct.call(product, { params: { data: { units: [{ id: 'Unit-A', text: 'Unit A' }] } } });
    },
    respond(ok = true) {
      pendingPrice({ ok, json: async () => ({ data: { unit_price: '25.00000000', maximum_discount_amount: '0', source: 'fixture' } }) });
    },
  };
}

test('changing an editable order product releases its stored price and resolves the new price', async () => {
  const harness = pricingHarness();
  harness.select('Product-B');
  assert.equal(harness.row.dataset.priceLocked, undefined);
  assert.equal(harness.requests.length, 1);
  assert.match(harness.requests[0], /product_doc_num=Product-B/);
  harness.respond();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(harness.price.value, '25.00000000');
});

test('returning to the original product restores its stored price and ignores the pending replacement lookup', async () => {
  const harness = pricingHarness();
  harness.select('Product-B');
  harness.select('Product-A');
  assert.equal(harness.price.value, '10.00000000');
  assert.equal(harness.row.dataset.priceLocked, '1');
  assert.equal(harness.requests.length, 1);
  harness.respond(false);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(harness.price.value, '10.00000000');
});

test('a linked execution row keeps its stored price even if a product event is dispatched', () => {
  const harness = pricingHarness(false);
  harness.select('Product-B');
  assert.equal(harness.row.dataset.priceLocked, '1');
  assert.equal(harness.price.value, '10.00000000');
  assert.equal(harness.requests.length, 0);
});
