const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function appNumbers() {
  const browser = {};
  const document = {readyState: 'loading', addEventListener() {}};
  const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/modules/Core/numeric-input.js'), 'utf8');
  vm.runInNewContext(source, {window: browser, document, BigInt, Number, String, Math});

  return browser.AppNumbers;
}

test('unit price previews keep eight-place prices and the required monetary boundary', () => {
  const numbers = appNumbers();

  assert.equal(numbers.multiply('10000', '22.54545', 4), '225454.5');
  assert.equal(numbers.formatWithMinimumDecimals(numbers.multiply('10000', '22.54545', 4), 2), '225,454.50');
  assert.equal(numbers.formatWithMinimumDecimals('1.2345', 2), '1.2345');
  assert.equal(numbers.formatWithMinimumDecimals('not-a-number', 2), 'not-a-number');
  assert.equal(numbers.multiply('10000', '22.54545123', 4), '225454.5123');
  assert.equal(numbers.multiply('1000000', '0.00000001', 4), '0.01');
  assert.equal(numbers.multiply('10000', '٢٢٫٥٤٥٤٥', 4), '225454.5');
  assert.equal(numbers.multiply('1', '99999999999999.99999999'), '99999999999999.99999999');
  assert.equal(numbers.add('225454.5', '0.01'), '225454.51');
  assert.equal(numbers.round('0.00005', 4), '0.0001');
  assert.equal(numbers.divide('1', '3', 8), '0.33333333');
  assert.equal(numbers.divide('22.54545123', '100', 8), '0.22545451');
  assert.equal(numbers.divide('1', '0', 8), null);
});
