import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../public/assets/js/document-cancellation-review.js', import.meta.url), 'utf8');

function page(fetch) {
    let submit;
    let reloads = 0;
    const button = { disabled: false };
    const classes = new Set(['d-none']);
    const error = { textContent: '', classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c) } };
    const form = {
        action: '/scoped-document/cancel', dataset: { errorMessage: 'Localized failure' },
        reportValidity: () => true,
        querySelector: (selector) => selector === 'button[type="submit"]' ? button : error,
    };
    vm.runInNewContext(script, {
        document: { addEventListener: (_, callback) => { submit = callback; } },
        window: { location: { reload: () => { reloads += 1; } } }, fetch,
        FormData: class { constructor(source) { this.source = source; } },
    });
    return { form, button, error, classes, reloads: () => reloads, submit: () => submit({ target: { closest: () => form }, preventDefault() {} }) };
}

test('one in flight cancellation prevents a second submission and reloads only after native success', async () => {
    let resolve;
    let calls = 0;
    const p = page((url, options) => {
        calls += 1;
        assert.equal(url, '/scoped-document/cancel');
        assert.equal(options.headers.Accept, 'application/json');
        assert.equal(options.body.source, p.form);
        return new Promise((r) => { resolve = r; });
    });
    const pending = p.submit();
    await p.submit();
    assert.equal(calls, 1);
    assert.equal(p.button.disabled, true);
    assert.equal(p.reloads(), 0);
    resolve({ ok: true, json: async () => ({ success: true }) });
    await pending;
    assert.equal(p.reloads(), 1);
});

test('validation and dependency failure is shown as text and allows retry with the same form token', async () => {
    let calls = 0;
    const p = page(async () => (++calls === 1
        ? { ok: false, json: async () => ({ errors: { reason: ['Required'], dependency: ['<blocked>'] } }) }
        : { ok: true, json: async () => ({ data: { doc_num: 'SYNTHETIC' } }) }));
    await p.submit();
    assert.equal(p.error.textContent, 'Required\n<blocked>');
    assert.equal(p.classes.has('d-none'), false);
    assert.equal(p.button.disabled, false);
    assert.equal(p.reloads(), 0);
    await p.submit();
    assert.equal(calls, 2);
    assert.equal(p.reloads(), 1);
});

test('an HTML login or server response does not look like a successful cancellation', async () => {
    const p = page(async () => ({ ok: true, json: async () => { throw new SyntaxError('HTML'); } }));
    await p.submit();
    assert.equal(p.error.textContent, 'Localized failure');
    assert.equal(p.reloads(), 0);
    assert.equal(p.button.disabled, false);
});
