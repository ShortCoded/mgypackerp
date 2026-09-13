<?php

use Symfony\Component\Process\Process;

test('sales document summary calculates every operational and financial total', function (): void {
    $script = <<<'JS'
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(process.argv[1], 'utf8').replace(
    "document.addEventListener('DOMContentLoaded', () => {",
    "window.__calculateDocumentSummary = calculateDocumentSummary;\n  document.addEventListener('DOMContentLoaded', () => {"
);
const outputs = {};
const makeRow = ({product, quantity, price, discount, tax}) => ({
    querySelector(selector) {
        const values = {
            '[name$="[product_doc_num]"]': product,
            '.js-sales-quantity': quantity,
            '.js-sales-price': price,
            '.js-sales-discount': discount,
            '.js-sales-tax': tax
        };
        return values[selector] === undefined ? null : {value: values[selector]};
    }
});
const rows = [
    makeRow({product: 'P-1', quantity: '10', price: '27', discount: '10', tax: '36.4'}),
    makeRow({product: 'P-1', quantity: '2', price: '5', discount: '0', tax: '1'})
];
const form = {
    matches: () => true,
    querySelectorAll: () => rows,
    querySelector(selector) {
        if (selector === '[name="currency_doc_num"]') {
            return {selectedOptions: [{textContent: 'EGP — Egyptian Pound'}]};
        }
        outputs[selector] ||= {textContent: ''};
        return outputs[selector];
    }
};
const document = {
    querySelector: () => null,
    addEventListener: () => {}
};
const window = {
    jQuery: {},
    salesCycleMessages: {},
    AppNumbers: {
        number: value => Number(value) || 0,
        format: value => String(Number(Number(value).toFixed(4)))
    }
};
vm.runInNewContext(source, {document, window, console, crypto: {randomUUID: () => 'uuid'}});
window.__calculateDocumentSummary(form);

assert.equal(outputs['[data-sales-summary-lines]'].textContent, '2');
assert.equal(outputs['[data-sales-summary-products]'].textContent, '1');
assert.equal(outputs['[data-sales-summary-quantity]'].textContent, '12');
assert.equal(outputs['[data-sales-summary-subtotal]'].textContent, '280');
assert.equal(outputs['[data-sales-summary-discount]'].textContent, '10');
assert.equal(outputs['[data-sales-summary-taxable]'].textContent, '270');
assert.equal(outputs['[data-sales-summary-tax]'].textContent, '37.4');
assert.equal(outputs['[data-sales-summary-total]'].textContent, '307.4');
assert.equal(outputs['[data-sales-summary-currency]'].textContent, 'EGP — Egyptian Pound');
JS;
    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/public/assets/js/modules/Sales/sales-cycle.js']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});
