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

test('simplebar loads after document content instead of blocking the head', () => {
    const styles = view('resources/views/layouts/partials/styles.blade.php');
    const appScripts = view('resources/views/layouts/partials/scripts.blade.php');
    const authLayout = view('resources/views/layouts/auth.blade.php');
    const authHead = authLayout.slice(0, authLayout.indexOf('</head>'));

    assert.doesNotMatch(styles, /simplebar\.min\.js/);
    assert.match(appScripts, /simplebar\.min\.js/);
    assert.doesNotMatch(authHead, /simplebar\.min\.js/);
    assert.match(authLayout.slice(authLayout.indexOf('</head>')), /simplebar\.min\.js/);
});

test('archive upload assets are scoped to the file manager instead of every page', () => {
    const styles = view('resources/views/layouts/partials/styles.blade.php');
    const appScripts = view('resources/views/layouts/partials/scripts.blade.php');
    const fileManager = view('resources/views/modules/core/file-manager/index.blade.php');

    assert.doesNotMatch(styles, /vendors\/dropzone\/dropzone\.css/);
    assert.doesNotMatch(appScripts, /vendors\/dropzone\/dropzone-min\.js/);
    assert.doesNotMatch(appScripts, /Core\/archive-uploader\.js/);
    assert.match(fileManager, /vendors\/dropzone\/dropzone\.css/);
    assert.match(fileManager, /vendors\/dropzone\/dropzone-min\.js/);
    assert.match(fileManager, /Core\/archive-uploader\.js/);
});
