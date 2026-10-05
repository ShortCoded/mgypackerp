const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/js/modules/Production/execution.js', 'utf8');
function runFunction(name, nextName, context) {
  const start = source.indexOf(`  function ${name}(`);
  const end = source.indexOf(`  function ${nextName}(`, start);
  vm.runInNewContext(source.slice(start, end) + `\n${name}(...args);`, context);
}

test('editing retains source values and prevents source selector changes', () => {
  let changes = 0;
  const sourceType = { value: 'sales_order', disabled: false };
  const sourceDocument = { value: 'SYNTHETIC-SO', disabled: false };
  const wrapper = { hidden: true, querySelector: () => sourceDocument };
  const form = { dataset: { productionSourceLocked: '1' }, querySelector: selector => selector.includes('type') ? sourceType : wrapper };
  runFunction('productionOrderSourceVisibility', 'reindexProductionOrderLines', {
    document: { querySelector: () => form }, args: [],
    $: () => ({ val() { return this; }, trigger() { changes++; } }),
  });
  assert.equal(sourceType.disabled, true);
  assert.equal(sourceDocument.disabled, true);
  assert.equal(sourceDocument.value, 'SYNTHETIC-SO');
  assert.equal(wrapper.hidden, false);
  assert.equal(changes, 0);
});

for (const editing of [true, false]) {
  test(`${editing ? 'correction permits' : 'creation locks'} removal and replacement of linked source lines`, () => {
    const quantity = { value: '', min: '0.00000001', closest: () => ({ appendChild() {} }) };
    const remove = { disabled: false };
    const duplicate = { disabled: false };
    const product = { value: '', style: {}, appendChild() {} };
    const row = { dataset: {}, querySelector(selector) {
      if (selector.includes('source_line_reference')) return product;
      if (selector.includes('quantity')) return quantity;
      if (selector.includes('remove')) return remove;
      if (selector.includes('duplicate')) return duplicate;
      return null;
    } };
    const body = { lastElementChild: row, appendChild() {} };
    const form = { dataset: { allowSourceAmendment: editing ? '1' : '0' }, querySelector: () => body };
    class Template {}
    const template = new Template();
    template.content = { cloneNode: () => ({}) };
    runFunction('addProductionOrderLine', 'loadConfiguredProductionStages', {
      args: [form, { source_line_reference: 'sales_order_line:SYNTHETIC', quantity: '2', required_quantity: '5' }, null, false],
      document: { getElementById: () => template, createElement: () => ({}) },
      HTMLTemplateElement: Template, Option: function () {}, window: {},
      reindexProductionOrderLines() {}, initializeWorkflowSelects() {}, loadConfiguredProductionStages() {}, loadProductionLineDetails() {},
      fallbackMessage: () => 'Required',
    });
    assert.equal(quantity.value, '2');
    assert.equal(remove.disabled, !editing);
    assert.equal(duplicate.disabled, !editing);
    assert.equal(quantity.min, editing ? '0.00000001' : '5');
    assert.equal(row.dataset.requiredSourceLine, editing ? undefined : '1');
    assert.equal(product.style.pointerEvents, editing ? undefined : 'none');
  });
}
