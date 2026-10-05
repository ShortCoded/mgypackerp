const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { runInNewContext } = require('node:vm');

const root = path.join(__dirname, '..');
const source = readFileSync(path.join(root, 'public/assets/js/modules/Core/select2-ajax.js'), 'utf8');

function runtime(locale) {
    const catalog = readFileSync(path.join(root, `resources/lang/${locale}/common.php`), 'utf8');
    function section(name) {
        const body = catalog.match(new RegExp("'" + name + "'\\s*=>\\s*\\[([\\s\\S]*?)\\]"))[1];
        return Object.fromEntries(Array.from(body.matchAll(/'(\w+)'\s*=>\s*'([^']+)'/g), match => [match[1], match[2]]));
    }
    const translations = { select2: section('select2'), placeholders: section('placeholders') };
    const globalDefaults = {};
    const initialized = [];
    const document = { readyState: 'loading', body: {}, documentElement: { getAttribute: () => locale === 'ar' ? 'rtl' : 'ltr' }, addEventListener() {} };
    const empty = { length: 0 };
    const wrappers = new Map();
    const $ = element => {
        if (!wrappers.has(element)) {
            const data = Object.assign({}, element.data);
            wrappers.set(element, {
                length: 1,
                data(key, value) { if (arguments.length === 2) { data[key] = value; return this; } return data[key]; },
                closest: () => element.modal || empty,
                on() { return this; },
                select2(options) { initialized.push({ element, options }); return this; }
            });
        }
        return wrappers.get(element);
    };
    $.fn = { select2: { defaults: { set: (key, value) => { globalDefaults[key] = value; } } } };
    const window = { AppSelect2: { messages: translations.select2, placeholder: translations.placeholders.select } };
    runInNewContext(source, { jQuery: $, window, document });
    return { globalDefaults, initialized, window, translations };
}

for (const locale of ['ar', 'en']) {
    test(`${locale} defaults cover manual selectors, limits and accessible labels`, () => {
        const { globalDefaults, translations } = runtime(locale);
        const messages = globalDefaults.language;
        for (const key of ['errorLoading', 'loadingMore', 'noResults', 'removeAllItems', 'removeItem', 'search', 'searching']) {
            assert.equal(messages[key](), translations.select2[key]);
        }
        assert.equal(messages.inputTooShort({ minimum: 5, input: 'اب' }), translations.select2.inputTooShort.replace(':count', '3'));
        assert.equal(messages.inputTooLong({ maximum: 2, input: 'abcd' }), translations.select2.inputTooLong.replace(':count', '2'));
        assert.equal(messages.maximumSelected({ maximum: 3 }), translations.select2.maximumSelected.replace(':count', '3'));
        assert.equal(globalDefaults.placeholder, translations.placeholders.select);
    });

    test(`${locale} dynamic modal selectors retain translations, field overrides and ajax parameters`, () => {
        const { window, initialized, translations } = runtime(locale);
        const modal = { length: 1 };
        const element = { multiple: false, modal, closest: () => null, data: { url: '/synthetic/select2/products', 'no-results': 'Field specific empty result', 'per-page': 10 } };
        const scope = { querySelectorAll: () => [element] };
        window.AppSelect2Ajax.init(scope);
        window.AppSelect2Ajax.init(scope);
        assert.equal(initialized.length, 1);
        assert.equal(initialized[0].options.dropdownParent, modal);
        assert.equal(initialized[0].options.language.searching(), translations.select2.searching);
        assert.equal(initialized[0].options.language.noResults(), 'Field specific empty result');
        assert.equal(initialized[0].options.placeholder, translations.placeholders.select);
        assert.deepEqual(JSON.parse(JSON.stringify(initialized[0].options.ajax.data({ term: 'a', page: 2 }))), { q: 'a', term: 'a', page: 2, per_page: 10 });
        const newElement = { closest: () => null, data: {} };
        window.AppSelect2Ajax.init({ querySelectorAll: () => [newElement] });
        assert.equal(initialized.length, 2);
        assert.equal(initialized[1].options.language.searching(), translations.select2.searching);
    });
}

test('app and guest layouts install versioned localized defaults before page initializers', () => {
    const app = readFileSync(path.join(root, 'resources/views/layouts/partials/scripts.blade.php'), 'utf8');
    const auth = readFileSync(path.join(root, 'resources/views/layouts/auth.blade.php'), 'utf8');
    for (const view of [app, auth]) {
        assert.ok(view.indexOf('select2.full.min.js') < view.indexOf("@include('layouts.partials.select2-config')"));
        assert.ok(view.indexOf("@include('layouts.partials.select2-config')") < view.indexOf('Core/select2-ajax.js'));
        assert.match(view, /->url\('assets\/js\/modules\/Core\/select2-ajax\.js'\)/);
    }
    assert.ok(app.indexOf('Core/select2-ajax.js') < app.indexOf('Core/layout.js'));
    assert.ok(auth.indexOf('Core/select2-ajax.js') < auth.indexOf('Auth/ajax.js'));
});
