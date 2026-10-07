const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const window = {};
const context = { window, document: { readyState: 'loading', addEventListener() {} } };
for (const source of ['Core/numeric-input.js', 'Sales/sales-discounts.js']) {
  vm.runInNewContext(fs.readFileSync(`${__dirname}/../public/assets/js/modules/${source}`, 'utf8'), context);
}
const calculate = window.AppSalesDiscounts.calculate;
test('unchanged partial source rows preserve booked gross tax and header rounding instead of recalculating their price product', () => {
  const row = {quantity:'1',unit_price:'22.54545',discount_type:'percentage',discount_value:'10',tax_rate:'14',tax_amount:'2.8408',tax_calculation_basis:'source_allocation',booked_gross:'22.5454',booked_header_discount:'0.0002',booked_own_discount:'2.2546'};
  const r=calculate([row],'fixed','0.0002');
  assert.equal(r.lines[0].gross,'22.5454');
  assert.equal(r.tax_amount,'2.8408');
  assert.equal(r.lines[0].own_discount_amount,'2.2546');
  assert.equal(r.total_amount,'23.1314');
  const percentage=calculate([{...row,booked_header_discount:'0.0001'}],'percentage','0.0005');
  assert.equal(percentage.header_discount_amount,'0.0001');
  assert.equal(percentage.lines[0].header_discount_amount,'0.0001');
});
test('commercial percentage and fixed inputs match server amounts and preserve tax', () => {
  const result = calculate([
    {quantity:'100', unit_price:'10', discount_type:'percentage', discount_value:'10', tax_amount:'126'},
    {quantity:'1', unit_price:'100', discount_type:'fixed', discount_value:'10', tax_amount:'12.6'},
  ], 'percentage', '5');
  assert.equal(result.valid, true);
  assert.equal(result.header_discount_amount, '49.5');
  assert.equal(result.total_amount, '1079.1');
  assert.deepEqual(Array.from(result.lines, row => row.discount_amount), ['145','14.5']);
});
test('header distribution conserves small amounts and skips zero bases', () => {
  const result = calculate(Array.from({length:3}, () => ({quantity:'1',unit_price:'1'})), 'fixed','0.0002');
  assert.deepEqual(Array.from(result.lines, row => row.header_discount_amount), ['0','0','0.0002']);
  assert.equal(result.total_amount,'2.9998');
  assert.equal(calculate([{quantity:'1',unit_price:'1',discount_type:'percentage',discount_value:'100'}], 'percentage','10').total_amount,'0');
});
test('invalid discounts are identified and prices keep eight decimal places', () => {
  assert.equal(calculate([{quantity:'10000',unit_price:'22.54545',discount_type:'fixed',discount_value:'0.1234'}],null,'0').total_amount,'225454.3766');
  for (const value of ['-1','101','1.00001','1e2']) {
    assert.equal(calculate([{quantity:'1',unit_price:'100',discount_type:'percentage',discount_value:value}],null,'0').valid,false);
  }
});
test('VAT inclusive withholding is separate from commercial discounts and matches four-place server rounding', () => {
  const gross = calculate([{quantity:'1',unit_price:'1000',tax_amount:'140'}],null,'0').total_amount;
  const result = window.AppSalesDiscounts.withholding(gross, '1');
  assert.equal(gross,'1140');
  assert.equal(result.withholding_amount,'11.4');
  assert.equal(result.net_payable_amount,'1128.6');
  assert.equal(window.AppSalesDiscounts.withholding('0.005','1').withholding_amount,'0.0001');
});

test('direct invoice amendment summary matches native tax proration and updates a single payment schedule', () => {
  const fields = {
    '.js-sales-quantity': {value:'1'}, '.js-sales-price': {value:'22.54545000'},
    '.js-sales-discount-type': {value:'percentage'}, '.js-sales-discount-value': {value:'10'},
    '.js-sales-discount': {value:'0'}, '.js-sales-tax': {value:'8.5222'},
    '[data-sales-line-total]': {textContent:''}, '[data-sales-own-discount-amount]': {textContent:''},
  };
  const row = {dataset:{invoiceBookedQuantity:'3',invoiceBookedTax:'8.5222'}, querySelector: selector => fields[selector] || null};
  const schedule = {value:'69.3948'};
  const elements = {'.js-sales-header-discount-type':{value:'fixed'},'.js-sales-header-discount-value':{value:'0.0002'}};
  const form = {
    hasAttribute: () => true, matches: () => true,
    querySelector: selector => elements[selector] || null,
    querySelectorAll: selector => selector === '[data-sales-lines] [data-sales-line]' ? [row] : [schedule],
  };
  const doc = {addEventListener(){},querySelectorAll(){return [];},querySelector(){return null;}};
  const source = fs.readFileSync(`${__dirname}/../public/assets/js/modules/Sales/sales-cycle.js`, 'utf8')
    .replace('})(window.jQuery, window, document);', 'window.testDirectSummary = calculateDocumentSummary; })(window.jQuery, window, document);');
  vm.runInNewContext(source, {window,document:doc});
  window.testDirectSummary(form);
  assert.equal(fields['.js-sales-tax'].value, '2.8407');
  assert.equal(fields['.js-sales-discount'].value, '2.2548');
  assert.equal(schedule.value, '23.1314');
});

test('explicit ETA withholding preview excludes VAT and keeps gross receivables separate', () => {
  const result = window.AppSalesDiscounts.withholding('1140', '1', 'eta_t4_net_excluding_tax', '1000');
  assert.equal(result.withholding_basis_amount, '1000');
  assert.equal(result.withholding_amount, '10');
  assert.equal(result.net_payable_amount, '1130');
  assert.equal(window.AppSalesDiscounts.withholding('1140', '1').withholding_amount, '11.4');
});


test('VAT percentages use the discounted base including header shares and retain legacy amount contracts', () => {
  for (const [rate, tax] of [['0','0'], ['14','119'], ['0.1234','1.0489'], ['100','850']]) {
    const result = calculate([{quantity:'1',unit_price:'1000',discount_type:'percentage',discount_value:'10',tax_rate:rate,tax_amount:'999'}], 'fixed','50');
    assert.equal(result.valid,true);assert.equal(result.lines[0].tax_amount,tax);
    assert.equal(result.total_amount,window.AppNumbers.add('850',tax));
  }
  for (const rate of ['-1','100.0001','1.00001','1e2']) {
    assert.equal(calculate([{quantity:'1',unit_price:'1000',tax_rate:rate}],null,'0').valid,false);
  }
  assert.equal(calculate([{quantity:'1',unit_price:'1000',tax_rate:'14',tax_calculation_basis:'source_allocation',tax_amount:'140'}], 'fixed','50').tax_amount,'140');
  assert.equal(calculate([{quantity:'1',unit_price:'1000',tax_amount:'126'}], 'fixed','50').tax_amount,'126');
});


test('tiny header discounts cannot create negative VAT bases', () => {
 const result=calculate(Array.from({length:5},()=>({quantity:'1',unit_price:'0.0001',tax_rate:'100'})),'fixed','0.0002');
 assert.equal(result.valid,true);assert.equal(result.tax_amount,'0.0003');assert.equal(result.total_amount,'0.0006');
 assert.deepEqual(Array.from(result.lines,row=>row.header_discount_amount),['0','0','0','0.0001','0.0001']);
});
