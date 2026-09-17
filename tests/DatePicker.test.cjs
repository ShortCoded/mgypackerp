const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const assert = require('node:assert/strict');

test('localized date display inputs cannot be initialized as canonical date fields', () => {
  const source = readFileSync('public/assets/js/modules/Core/date-picker.js', 'utf8');

  assert.match(source, /className !== 'js-date-picker'/);
  assert.match(source, /input\.classList\.contains\('erp-date-picker-display'\)/);
  assert.match(source, /options\.altInputClass = displayInputClass\(input\)/);
  assert.doesNotMatch(source, /input\.className \+ ' erp-date-picker-display'/);
});
