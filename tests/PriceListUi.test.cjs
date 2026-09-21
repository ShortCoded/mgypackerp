const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/modules/Sales/price-lists.js'), 'utf8');
const form = fs.readFileSync(path.join(root, 'resources/views/modules/sales/price-lists/form.blade.php'), 'utf8');
const line = fs.readFileSync(path.join(root, 'resources/views/modules/sales/price-lists/line.blade.php'), 'utf8');

test('price list duplicate inserts after the focused row and hydrates editable values', () => {
  assert.match(script, /function lineValues\(row\)/);
  assert.match(script, /afterRow\.insertAdjacentElement\('afterend', row\)/);
  assert.match(script, /createLine\(lineValues\(sourceRow\), sourceRow\)/);
  assert.match(script, /product\.replaceChildren\(option\)/);
  assert.match(script, /unitPrice\.value = line\.unit_price/);
  assert.match(script, /discountType\.value = line\.allowed_discount_type/);
  assert.match(script, /discountValue\.value = line\.allowed_discount_value/);
  assert.match(script, /window\.AppSelect2Ajax\?\.init\(row\)/);
  assert.match(script, /window\.AppNumbers\?\.refresh\(row\)/);
  assert.match(script, /renumber\(\);/);
});

test('price list duplicate creates a fresh template row without clipboard or persisted identity state', () => {
  assert.match(script, /template\.innerHTML\.replaceAll\('__INDEX__'/);
  assert.doesNotMatch(script, /sessionStorage|lineClipboard|clipboardKey|data-price-list-copy|data-price-list-paste/);
  assert.doesNotMatch(script, /line\.id|line\.doc_num|line\.deleted_at|line\.created_at|line\.updated_at/);
});

test('both add-item controls share the same add-row implementation', () => {
  assert.equal((form.match(/data-price-list-add/g) || []).length, 2);
  assert.equal((form.match(/data-shortcut-action="line\.add"/g) || []).length, 2);
  assert.match(script, /closest\('\[data-price-list-add\]'\)[\s\S]*?createLine\(\)/);
});

test('duplicate and delete row actions remain compact and delegated', () => {
  assert.match(line, /data-price-list-duplicate/);
  assert.match(line, /data-price-list-remove/);
  assert.match(line, /d-inline-flex align-items-center gap-1/);
  assert.match(script, /removeLine\(remove\.closest\('\[data-price-list-line\]'\)\)/);
});

test('Alt+D duplicates only the row containing focus and never claims Ctrl+D', () => {
  assert.match(script, /form\?\.addEventListener\('keydown'/);
  assert.match(script, /event\.target\.closest\?\.\('\[data-price-list-line\]'\)/);
  assert.match(script, /event\.ctrlKey \|\| event\.metaKey \|\| event\.shiftKey/);
  assert.match(script, /isAltShortcut\(event, \['KeyD'\], \[68\], \['d'\]\)/);
  assert.match(script, /createLine\(lineValues\(row\), row\)/);
  assert.doesNotMatch(script, /Ctrl\s*\+\s*D/i);
});
