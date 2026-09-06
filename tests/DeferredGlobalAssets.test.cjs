const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');

function view(relativePath) {
    return readFileSync(path.join(__dirname, '..', relativePath), 'utf8');
}

test('the global fontawesome runtime does not block app or auth parsing', () => {
    const appScripts = view('resources/views/layouts/partials/scripts.blade.php');
    const authLayout = view('resources/views/layouts/auth.blade.php');
    const deferredFontAwesome = /<script\s+defer\s+src="\{\{[^\n]+vendors\/fontawesome\/all\.min\.js[^\n]+\}\}"><\/script>/;

    assert.match(appScripts, deferredFontAwesome);
    assert.match(authLayout, deferredFontAwesome);
});
