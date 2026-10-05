const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function runtime(module, initialRows = [], initialFields = {}) {
  const outputs = {}, fields = {...initialFields}, data = {}, timers = new Map(), requests = [];
  let timerId = 0;
  function field(selector) {
    return {length: 1, val(value) {if (value !== undefined) {fields[selector] = String(value); return this;} return fields[selector] || '';},
      text(value) {if (value !== undefined) {outputs[selector] = String(value); return this;} return outputs[selector] || fields[selector] || '';},
      attr() {return '';}, each() {return this;}, on() {return this;}};
  }
  function row(values) {
    return {values, inherit: '1', find(selector) {
      const match = selector.match(/\[name\$="\[([^\]]+)\]\"\]/);
      const key = match ? match[1] : selector;
      return {length:1, val(value) {if (value !== undefined) {values[key] = String(value); return this;} return values[key] || '';},
        text(value) {if (value !== undefined) {values[key] = String(value); return this;} return values[key] || '';}, eq() {return this;}};
    }, attr(name, value) {if (value !== undefined) {this.inherit = value; return this;} return this.inherit;}};
  }
  const rows = initialRows.map(row);
  const rowList = {length: rows.length, each(fn) {rows.forEach((r,i) => fn.call(r,i));return this;}};
  const attributes = {'data-readonly':'0', 'data-discount-preview-url':'/isolated-preview', 'data-inherit-header-discount':'1'};
  const form = {length:1, find(selector) {if (selector === '.js-purchase-invoice-line') return rowList;return field(selector);},
    attr(name) {return attributes[name] || '';}, data(key,value) {if (value !== undefined) {data[key]=value;return this;}return data[key];}};
  const document = {readyState:'loading',addEventListener(){}};
  function $(selector) {
    if (typeof selector === 'function') return;
    if (rows.includes(selector)) return selector;
    if (selector === '.js-purchase-order-line') return rowList;
    if (selector === '.js-purchase-order-form') return {attr(){return '0';}};
    if (selector === document) return {on(){}};
    return field(selector);
  }
  $.ajax = config => {
    const request = {config, done(fn){this.success=fn;return this;}, fail(fn){this.failure=fn;return this;}, always(fn){this.complete=fn;return this;},abort(){this.aborted=true;}};
    requests.push(request);return request;
  };
  const window = {setTimeout(fn){const id=++timerId;timers.set(id,fn);return id;},clearTimeout(id){timers.delete(id);}};
  const context = {window,document,jQuery:$};
  vm.runInNewContext(fs.readFileSync(`${__dirname}/../public/assets/js/modules/Core/numeric-input.js`,'utf8'),context);
  const source = fs.readFileSync(`${__dirname}/../public/assets/js/modules/Purchases/${module}.js`,'utf8');
  const exportName = module === 'purchase-orders' ? 'calculateTotals' : 'queueDiscountPreview';
  vm.runInNewContext(source.replace('})(jQuery, window, document);',`window.runDiscountTest = ${exportName}; })(jQuery, window, document);`),context);
  return {window,form,fields,outputs,rows,requests,timers,normalized: selector => window.AppNumbers.normalize(outputs[selector])};
}

test('purchase order preview deducts own and header discounts once and calculates VAT after both',()=>{
  const r=runtime('purchase-orders',[{'.js-line-quantity':'1','.js-line-unit-price':'1000','.js-line-discount-type':'percentage','.js-line-discount-value':'10','.js-line-tax-rate':'14'}],{'#header_discount_type':'fixed','#header_discount_value':'50'});
  r.window.runDiscountTest();
  assert.equal(r.normalized('.js-total-header-discount'),'50');
  assert.equal(r.normalized('.js-total-discount'),'100');
  assert.equal(r.normalized('.js-total-tax'),'119');
  assert.equal(r.normalized('.js-total-amount'),'969');
});

test('purchase order header rounding conserves the amount with a final zero base',()=>{
  const r=runtime('purchase-orders',[1,1,1,0].map(price=>({'.js-line-quantity':'1','.js-line-unit-price':String(price)})),{'#header_discount_type':'fixed','#header_discount_value':'0.0002'});
  r.window.runDiscountTest();
  assert.equal(r.normalized('.js-total-header-discount'),'0.0002');
  assert.equal(r.normalized('.js-total-amount'),'2.9998');
  assert.equal(r.window.AppNumbers.normalize(r.rows[3].values['.js-line-total']),'0');
});

test('invoice source preview uses server booked amounts and ignores stale responses',()=>{
  const r=runtime('purchase-invoices',[{public_id:'',purchase_order_line_public_id:'SOURCE',product_doc_num:'RAW',unit_doc_num:'KG',quantity:'1',unit_price:'22.54545',discount_type:'percentage',discount_value:'10',tax_rate:'14'}],{'#purchase_order_doc_num':'PO-SYNTHETIC','.js-purchase-invoice-header-discount-type':'fixed','.js-purchase-invoice-header-discount-value':'0.0002'});
  r.window.runDiscountTest(r.form);
  for (const fn of r.timers.values()) fn();r.timers.clear();
  assert.equal(r.requests.length,1);
  const first=r.requests[0];
  r.window.runDiscountTest(r.form);
  for (const fn of r.timers.values()) fn();r.timers.clear();
  const current=r.requests[1];
  assert.equal(first.aborted,true);
  const result = total => ({data:{defaults:{header_discount_type:'fixed',header_discount_value:'0.0001',lines:[{discount_type:'percentage',discount_value:'10'}]},calculation:{invoice:{subtotal_amount:'22.5455',line_discount_amount:'2.2545',header_discount_amount:'0.0001',taxable_amount:'20.2909',tax_amount:'2.8407',total_amount:total},lines:[{subtotal_amount:'22.5455',discount_amount:'2.2545',tax_amount:'2.8407',total_after_tax:total}]}}});
  first.success(result('999'));
  assert.equal(r.outputs['.js-purchase-invoice-total'],undefined);
  current.success(result('23.1316'));current.complete();
  assert.equal(r.normalized('.js-purchase-invoice-total'),'23.1316');
  assert.equal(r.rows[0].values['.js-purchase-invoice-discount-value'],'10');
  assert.equal(r.fields['.js-purchase-invoice-header-discount-value'],'0.0001');
  assert.equal(r.form.data('discountPreviewPending'),false);
});

test('purchase order header rounding leaves no line with a discount above its tiny base',()=>{
  const r=runtime('purchase-orders',Array.from({length:5},()=>({'.js-line-quantity':'1','.js-line-unit-price':'0.0001'})),{'#header_discount_type':'fixed','#header_discount_value':'0.0002'});
  r.window.runDiscountTest();
  const net=r.rows.reduce((sum,row)=>r.window.AppNumbers.add(sum,r.window.AppNumbers.normalize(row.values['.js-line-total'])),'0');
  assert.equal(r.window.AppNumbers.normalize(net),'0.0003');
  assert.equal(r.normalized('.js-total-amount'),'0.0003');
});
