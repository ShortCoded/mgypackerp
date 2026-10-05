const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('manual supplier selection discount changes clear inheritance before keyboard submission', () => {
    const intent = {value: '1'};
    const listeners = {};
    const field = {addEventListener(name, handler) {listeners[name] = handler;}, closest() {return {querySelector() {return intent;}};}};
    const document = {addEventListener(name, handler) {handler();}, querySelectorAll(selector) {return selector === '[data-selection-discount-input]' ? [field] : [];}};
    vm.runInNewContext(fs.readFileSync(`${__dirname}/../public/assets/js/modules/Purchases/procurement-cycle.js`, 'utf8'), {document, window: {}});
    listeners.input();
    assert.equal(intent.value, '0');
    intent.value = '1';
    listeners.change();
    assert.equal(intent.value, '0');
});

test('removing a quote header discount clears its prior entered value', () => {
    const type = {value: 'fixed', addEventListener(name, handler) {this.changed = handler;}};
    const value = {value: '50'};
    const form = {querySelector(selector) {return selector.includes('type') ? type : value;}};
    const document = {addEventListener(name, handler) {handler();}, querySelectorAll(selector) {return selector === '[data-supplier-quote-discount-form]' ? [form] : [];}};
    vm.runInNewContext(fs.readFileSync(`${__dirname}/../public/assets/js/modules/Purchases/procurement-cycle.js`, 'utf8'), {document, window: {}});
    type.value = '';
    type.changed();
    assert.equal(value.value, '0');
});
