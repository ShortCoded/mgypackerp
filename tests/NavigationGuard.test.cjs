const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const guardSource = readFileSync(
  path.join(__dirname, '..', 'public/assets/js/modules/Core/navigation-guard.js'),
  'utf8',
);

function guardHarness({ confirmResult = true } = {}) {
  const documentListeners = new Map();
  const windowListeners = new Map();
  const state = { confirmations: 0, events: [], timerId: 0 };
  const formAttributes = new Set();
  const form = {
    closest() {
      return null;
    },
    getAttribute(name) {
      return name === 'method' ? 'post' : null;
    },
    hasAttribute(name) {
      return formAttributes.has(name);
    },
    matches() {
      return false;
    },
    querySelector() {
      return {};
    },
    removeAttribute(name) {
      formAttributes.delete(name);
    },
    setAttribute(name) {
      formAttributes.add(name);
    },
  };
  const document = {
    addEventListener(type, callback) {
      documentListeners.set(type, callback);
    },
    contains(candidate) {
      return candidate === form;
    },
  };
  const window = {
    AppNavigationGuardConfig: {
      submissionGraceMs: 15000,
      unsavedChangesMessage: 'Unsaved changes',
    },
    addEventListener(type, callback) {
      windowListeners.set(type, callback);
    },
    clearTimeout() {},
    confirm(message) {
      state.confirmations += 1;
      assert.equal(message, 'Unsaved changes');

      return confirmResult;
    },
    dispatchEvent(event) {
      state.events.push(event);
    },
    setTimeout() {
      state.timerId += 1;

      return state.timerId;
    },
  };
  class CustomEvent {
    constructor(type, options) {
      this.detail = options.detail;
      this.type = type;
    }
  }

  vm.runInNewContext(guardSource, { CustomEvent, document, Set, WeakMap, window });

  return { documentListeners, form, state, window, windowListeners };
}

test('controlled navigation only prompts after an eligible form becomes dirty', () => {
  const harness = guardHarness({ confirmResult: false });
  let navigated = false;

  harness.window.AppNavigationGuard.run(() => {
    navigated = true;
  });

  assert.equal(navigated, true);
  assert.equal(harness.state.confirmations, 0);

  harness.window.AppNavigationGuard.markDirty(harness.form);
  navigated = false;

  assert.equal(harness.window.AppNavigationGuard.isDirty(), true);
  assert.equal(harness.window.AppNavigationGuard.run(() => {
    navigated = true;
  }), false);
  assert.equal(navigated, false);
  assert.equal(harness.state.confirmations, 1);
});

test('submitting suppresses the warning while a failed submission restores it', () => {
  const harness = guardHarness();

  harness.window.AppNavigationGuard.markDirty(harness.form);
  harness.window.AppNavigationGuard.submissionStarted(harness.form);

  assert.equal(harness.window.AppNavigationGuard.isDirty(), false);

  harness.window.AppNavigationGuard.submissionFailed(harness.form);

  assert.equal(harness.window.AppNavigationGuard.isDirty(), true);

  harness.window.AppNavigationGuard.markSaved(harness.form);

  assert.equal(harness.window.AppNavigationGuard.isDirty(), false);
});

test('beforeunload is quiet for clean forms and protects dirty ones', () => {
  const harness = guardHarness();
  const event = {
    prevented: false,
    preventDefault() {
      this.prevented = true;
    },
  };

  harness.windowListeners.get('beforeunload')(event);
  assert.equal(event.prevented, false);

  harness.window.AppNavigationGuard.markDirty(harness.form);
  harness.windowListeners.get('beforeunload')(event);

  assert.equal(event.prevented, true);
  assert.equal(event.returnValue, '');
});
