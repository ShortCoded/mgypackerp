const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function field(initial = '') {
  let value = initial;
  return {
    val(next) {
      if (next !== undefined) value = next;
      return value;
    },
    text(next) {
      if (next !== undefined) value = next;
      return value;
    },
    attr() { return ''; }
  };
}

function formWithLine(rowFields, formFields, lineSelector) {
  const data = {};
  const row = {
    find(selector) {
      return rowFields[selector] || field('0');
    }
  };
  const form = {
    attr() { return ''; },
    data(key, value) {
      if (value !== undefined) data[key] = value;
      return data[key];
    },
    find(selector) {
      if (selector === lineSelector) {
        return { each(callback) { callback.call(row); } };
      }
      if (selector === '.js-purchase-invoice-schedule-amount') {
        return { each() {} };
      }
      return formFields[selector] || field('0');
    }
  };

  return { form, rowFields, formFields };
}

function preview(modulePath) {
  const browser = { clearTimeout() {} };
  const document = { readyState: 'loading', addEventListener() {} };
  const numbersSource = fs.readFileSync(path.join(__dirname, '../public/assets/js/modules/Core/numeric-input.js'), 'utf8');
  vm.runInNewContext(numbersSource, { window: browser, document, BigInt, Number, String, Math });

  let source = fs.readFileSync(path.join(__dirname, '../public/assets/js/modules', modulePath), 'utf8');
  const initAt = source.lastIndexOf('  $(function () {');
  assert.ok(initAt > 0, 'preview module must retain its normal initializer');
  source = source.slice(0, initAt) + '  window.__testPreview = calculateTotals;\n' + source.slice(initAt);
  const jquery = (value) => typeof value === 'function' ? undefined : value && typeof value.find === 'function' ? value : field('');
  vm.runInNewContext(source, { jQuery: jquery, window: browser, document, BigInt, Number, String, Math, Set });

  return browser.__testPreview;
}

test('quotation preview preserves the required eight-place unit price through header discount', () => {
  const calculate = preview('Sales/quotations.js');
  const rowFields = {
    '[name$="[quantity]"]': field('10000'),
    '[name$="[unit_price]"]': field('22.54545123'),
    '[name$="[discount_type]"]': field(''),
    '[name$="[discount_value]"]': field('0'),
    '[name$="[tax_rate]"]': field('0'),
    '.js-quotation-line-discount-amount': field(),
    '.js-quotation-line-tax-amount': field(),
    '.js-quotation-line-total': field()
  };
  const formFields = {
    '[name="discount_type"]': field('percentage'),
    '[name="discount_value"]': field('10'),
    '.js-quotation-subtotal': field(),
    '.js-quotation-discount': field(),
    '.js-quotation-tax': field(),
    '.js-quotation-total': field()
  };

  calculate(formWithLine(rowFields, formFields, '.js-quotation-line').form);

  assert.equal(formFields['.js-quotation-subtotal'].text(), '225,454.5123');
  assert.equal(formFields['.js-quotation-discount'].text(), '22,545.4512');
  assert.equal(formFields['.js-quotation-total'].text(), '202,909.0611');
});

test('purchase invoice preview matches eight-place price and tiny amount boundaries', () => {
  const calculate = preview('Purchases/purchase-invoices.js');
  const rowFields = {
    '.js-purchase-invoice-quantity': field('10000'),
    '.js-purchase-invoice-unit-price': field('22.54545123'),
    '.js-purchase-invoice-discount-type': field(''),
    '.js-purchase-invoice-discount-value': field('0'),
    '.js-purchase-invoice-tax-rate': field('0'),
    '.js-purchase-invoice-line-subtotal': field(),
    '.js-purchase-invoice-line-discount': field(),
    '.js-purchase-invoice-line-tax': field(),
    '.js-purchase-invoice-line-total': field()
  };
  const formFields = {
    '.js-purchase-invoice-header-discount-type': field('percentage'),
    '.js-purchase-invoice-header-discount-value': field('10'),
    '.js-purchase-invoice-freight': field('0'),
    '.js-purchase-invoice-freight-tax-rate': field('0'),
    '.js-purchase-invoice-paid': field('0'),
    '.js-purchase-invoice-subtotal': field(),
    '.js-purchase-invoice-line-discounts': field(),
    '.js-purchase-invoice-header-discount': field(),
    '.js-purchase-invoice-freight-total': field(),
    '.js-purchase-invoice-freight-tax': field(),
    '.js-purchase-invoice-taxable': field(),
    '.js-purchase-invoice-tax': field(),
    '.js-purchase-invoice-total': field(),
    '.js-purchase-invoice-remaining': field(),
    '.js-purchase-invoice-schedule-total': field(),
    '.js-purchase-invoice-schedule-difference': field()
  };
  const fixture = formWithLine(rowFields, formFields, '.js-purchase-invoice-line');

  calculate(fixture.form);
  assert.equal(formFields['.js-purchase-invoice-subtotal'].text(), '225,454.5123');
  assert.equal(formFields['.js-purchase-invoice-header-discount'].text(), '22,545.4512');
  assert.equal(formFields['.js-purchase-invoice-total'].text(), '202,909.0611');

  rowFields['.js-purchase-invoice-quantity'].val('1000000');
  rowFields['.js-purchase-invoice-unit-price'].val('0.00000001');
  formFields['.js-purchase-invoice-header-discount-type'].val('');
  calculate(fixture.form);
  assert.equal(formFields['.js-purchase-invoice-total'].text(), '0.01');
});
