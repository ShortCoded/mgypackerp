<?php

use Symfony\Component\Process\Process;

test('purchase order JavaScript preserves posted display totals and recalculates editable quantities', function (): void {
    $script = <<<'JS'
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(process.argv[1], 'utf8');
for (const readonly of [true, false]) {
    const values = { '#freight_amount': '5', '.js-line-quantity': '10', '.js-line-unit-price': '27', '.js-line-discount-type': 'fixed', '.js-line-discount-value': '10', '.js-line-tax-rate': '14', '.js-line-received': '4' };
    const rendered = {
        '.js-total-subtotal': '9,990',
        '.js-total-discount': '20',
        '.js-total-taxable': '9,970',
        '.js-total-tax': '24',
        '.js-total-freight': '5',
        '.js-total-amount': '9,999',
        '.js-line-total': '9,994'
    };
    const row = {};
    const jq = selector => {
        if (typeof selector === 'function') { selector(); return; }
        return {
            length: 0,
            attr: name => name === 'data-readonly' ? (readonly ? '1' : '0') : '',
            find: name => jq(name),
            val: () => values[selector] || '',
            each(callback) { if (selector === '.js-purchase-order-line') callback.call(row); return this; },
            text(value) { if (value === undefined) return values[selector] || rendered[selector] || ''; rendered[selector] = value; return this; },
            on() { return this; }
        };
    };
    jq.fn = {};
    const document = { getElementById: () => null, querySelector: () => null, activeElement: null };
    vm.runInNewContext(source, { jQuery: jq, document, window: { AppNumbers: { number: value => Number(value) || 0, format: value => String(value) } } });
    if (readonly) {
        assert.equal(rendered['.js-total-subtotal'], '9,990');
        assert.equal(rendered['.js-total-discount'], '20');
        assert.equal(rendered['.js-total-taxable'], '9,970');
        assert.equal(rendered['.js-total-tax'], '24');
        assert.equal(rendered['.js-total-freight'], '5');
        assert.equal(rendered['.js-total-amount'], '9,999');
        assert.equal(rendered['.js-line-total'], '9,994');
    } else {
        assert.equal(rendered['.js-total-ordered'], '10');
        assert.equal(rendered['.js-total-received'], '4');
        assert.equal(rendered['.js-total-remaining'], '6');
        assert.equal(rendered['.js-total-subtotal'], '270');
        assert.equal(rendered['.js-total-discount'], '10');
        assert.equal(rendered['.js-total-taxable'], '260');
        assert.equal(rendered['.js-total-tax'], '36.4');
        assert.equal(rendered['.js-total-freight'], '5');
        assert.equal(rendered['.js-line-total'], '296.4');
        assert.equal(rendered['.js-total-amount'], '301.4');
    }
}
JS;
    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/public/assets/js/modules/Purchases/purchase-orders.js']);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});
