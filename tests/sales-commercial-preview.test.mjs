import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const window = {};
const document = {readyState:'loading', addEventListener(){}};
const context = vm.createContext({window, document});
for (const path of ['public/assets/js/modules/Core/numeric-input.js', 'public/assets/js/modules/Sales/sales-discounts.js']) vm.runInContext(readFileSync(path,'utf8'), context);
const n = window.AppNumbers;
for (const [type, value] of [['fixed','90'],['percentage','10']]) {
  test(`combined line discount, ${type} document discount, VAT and ETA withholding`, () => {
    const result = window.AppSalesDiscounts.calculate([{quantity:'1',unit_price:'1000',discount_type:'percentage',discount_value:'10',tax_rate:'14',tax_calculation_basis:'rate'}],type,value);
    assert.equal(result.valid,true);
    for (const [field, value] of [['discount_amount','190'],['tax_amount','113.4'],['total_amount','923.4']]) assert.equal(n.compare(result[field],value),0);
    const preview = window.AppSalesDiscounts.withholding(result.total_amount,'1','eta_t4_net_excluding_tax',n.subtract(result.subtotal_amount,result.discount_amount));
    assert.equal(n.compare(preview.withholding_amount,'8.1'),0);
    assert.equal(n.compare(preview.net_payable_amount,'915.3'),0);
  });
}
test('historical gross preview retains its basis and amount',()=>{
  const result=window.AppSalesDiscounts.withholding('1140','1','gross_including_tax','1000');
  assert.equal(n.compare(result.withholding_amount,'11.4'),0);
});
test('eight place prices and fractional quantities retain canonical precision',()=>{
  assert.equal(n.compare(n.multiply('1','0.00005',4),'0.0001'),0);
  assert.equal(n.compare(n.multiply('22.54545123','2',4),'45.0909'),0);
});

test('commercial preview applies one percent to gross including VAT and keeps statutory expectations separate',()=>{
  const commercial=window.AppSalesDiscounts.withholding('1140','1','commercial_gross_preview','1000');
  const statutory=window.AppSalesDiscounts.withholding('1140','1','eta_t4_net_excluding_tax','1000');
  assert.equal(n.compare(commercial.withholding_amount,'11.4'),0);
  assert.equal(n.compare(commercial.net_payable_amount,'1128.6'),0);
  assert.equal(n.compare(statutory.withholding_amount,'10'),0);
});
