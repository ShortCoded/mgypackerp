const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function classList() {
  return {
    add() {},
    remove() {},
    toggle() {},
  };
}

test('navigation search reuses a recent response on repeated focus', async () => {
  const inputListeners = {};
  const input = {
    value: '',
    addEventListener(type, callback) {
      inputListeners[type] = callback;
    },
    blur() {},
    setAttribute() {},
  };
  const dropdown = { classList: classList() };
  const resultsRoot = {
    innerHTML: '',
    addEventListener() {},
  };
  const root = {
    contains: () => true,
    querySelector(selector) {
      return {
        '#navbar_search_input': input,
        '.dropdown-menu': dropdown,
        '[data-navigation-search-results]': resultsRoot,
        '[data-bs-dismiss="search"] button': null,
      }[selector];
    },
    querySelectorAll: () => [],
  };
  const document = {
    addEventListener() {},
    createElement() {
      return {
        innerHTML: '',
        set textContent(value) {
          this.innerHTML = value || '';
        },
      };
    },
    querySelector(selector) {
      if (selector === '[data-navigation-search]') {
        return root;
      }

      if (selector === 'meta[name="csrf-token"]') {
        return { getAttribute: () => 'csrf-token' };
      }

      return null;
    },
  };
  let fetchCount = 0;
  const window = {
    AppNavigationSearch: {
      cacheMs: 30000,
      recentClearUrl: '/navigation-search/recent',
      recentStoreUrl: '/navigation-search/recent',
      searchUrl: '/navigation-search',
    },
    clearTimeout,
    fetch: async () => {
      fetchCount += 1;

      return {
        json: async () => ({ data: { section: 'recent', results: [] } }),
        ok: true,
      };
    },
    location: {
      href: 'https://erp.test/dashboard',
      origin: 'https://erp.test',
    },
    setTimeout,
  };
  const source = fs.readFileSync(
    path.join(process.cwd(), 'public/assets/js/modules/Core/navigation-search.js'),
    'utf8',
  );

  vm.runInNewContext(source, {
    AbortController,
    Date,
    URL,
    document,
    window,
  });

  inputListeners.focus();
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
  inputListeners.focus();
  await new Promise((resolve) => setImmediate(resolve));

  assert.equal(fetchCount, 1);
});
