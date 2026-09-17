import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.HEADER_E2E_BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const debuggerUrl = (process.env.HEADER_E2E_DEBUG_URL || 'http://127.0.0.1:9224').replace(/\/$/, '');
const artifactDirectory = process.env.HEADER_E2E_ARTIFACT_DIR;
const loginIdentifier = process.env.HEADER_E2E_LOGIN || 'runtime_demo_full';
const loginPassword = process.env.HEADER_E2E_PASSWORD || 'RuntimeDemo2026!';

if (!artifactDirectory) {
  throw new Error('HEADER_E2E_ARTIFACT_DIR is required.');
}

await mkdir(artifactDirectory, { recursive: true });

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

class DevToolsClient {
  constructor(webSocketUrl) {
    this.webSocket = new WebSocket(webSocketUrl);
    this.nextId = 1;
    this.pending = new Map();
    this.listeners = new Map();
    this.ready = new Promise((resolve, reject) => {
      this.webSocket.addEventListener('open', resolve, { once: true });
      this.webSocket.addEventListener('error', reject, { once: true });
    });
    this.webSocket.addEventListener('message', (event) => {
      const message = JSON.parse(event.data);

      if (message.id) {
        const pending = this.pending.get(message.id);
        if (!pending) return;
        this.pending.delete(message.id);
        if (message.error) pending.reject(new Error(`${pending.method}: ${message.error.message}`));
        else pending.resolve(message.result || {});
        return;
      }

      for (const listener of this.listeners.get(message.method) || []) listener(message.params || {});
    });
  }

  async send(method, params = {}) {
    await this.ready;
    const id = this.nextId++;
    const result = new Promise((resolve, reject) => this.pending.set(id, { method, resolve, reject }));
    this.webSocket.send(JSON.stringify({ id, method, params }));

    return result;
  }

  on(method, listener) {
    const listeners = this.listeners.get(method) || [];
    listeners.push(listener);
    this.listeners.set(method, listeners);
  }

  close() {
    this.webSocket.close();
  }
}

const target = await fetch(`${debuggerUrl}/json/new?${encodeURIComponent(`${baseUrl}/login`)}`, { method: 'PUT' }).then((response) => {
  if (!response.ok) throw new Error(`Unable to create Chrome target: HTTP ${response.status}`);
  return response.json();
});
const client = new DevToolsClient(target.webSocketDebuggerUrl);
await client.ready;

let loadCount = 0;
let offlineExpected = false;
let standaloneScriptIdentifier = null;
const browserErrors = [];
const responseErrors = [];
client.on('Page.loadEventFired', () => { loadCount += 1; });
client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
  browserErrors.push(exceptionDetails?.exception?.description || exceptionDetails?.text || 'Unhandled JavaScript exception');
});
client.on('Runtime.consoleAPICalled', ({ type, args }) => {
  if (type === 'error' || type === 'assert') {
    browserErrors.push(args.map((argument) => argument.value || argument.description || '').join(' '));
  }
});
client.on('Network.responseReceived', ({ response, type }) => {
  if (!offlineExpected && type === 'Document' && response?.url?.startsWith(baseUrl) && response.status >= 400) {
    responseErrors.push(`${response.status} ${response.url}`);
  }
});

await Promise.all([
  client.send('Page.enable'),
  client.send('Runtime.enable'),
  client.send('Network.enable'),
]);

async function evaluate(expression) {
  const result = await client.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
    userGesture: true,
  });

  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'Browser evaluation failed.');
  }

  return result.result?.value;
}

async function waitUntil(callback, message, timeout = 45000) {
  const deadline = Date.now() + timeout;
  let lastError;

  while (Date.now() < deadline) {
    try {
      const value = await callback();
      if (value) return value;
    } catch (error) {
      lastError = error;
    }

    await sleep(150);
  }

  throw new Error(`${message}${lastError ? ` (${lastError.message})` : ''}`);
}

async function setViewport(width, height) {
  await client.send('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: 1,
    mobile: width <= 430,
    screenOrientation: width > height
      ? { type: 'landscapePrimary', angle: 90 }
      : { type: 'portraitPrimary', angle: 0 },
  });
}

async function setDisplayMode(value) {
  await client.send('Emulation.setEmulatedMedia', {
    features: value ? [{ name: 'display-mode', value }] : [],
  });

  if (value === 'standalone' && !standaloneScriptIdentifier) {
    const injected = await client.send('Page.addScriptToEvaluateOnNewDocument', {
      source: `Object.defineProperty(window.navigator, 'standalone', { configurable: true, value: true });`,
    });
    standaloneScriptIdentifier = injected.identifier;
  } else if (!value && standaloneScriptIdentifier) {
    await client.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: standaloneScriptIdentifier });
    standaloneScriptIdentifier = null;
  }

  await sleep(150);
}

async function navigate(route, timeout = 45000) {
  const url = route.startsWith('http') ? route : `${baseUrl}${route}`;
  const before = loadCount;
  const navigation = await client.send('Page.navigate', { url });

  if (navigation.errorText && !offlineExpected) throw new Error(`${url}: ${navigation.errorText}`);
  await waitUntil(() => loadCount > before, `Navigation did not complete: ${url}`, timeout);
  await waitUntil(() => evaluate(`document.readyState === 'complete' && Boolean(document.body)`), `Page was not ready: ${url}`, timeout);
  await sleep(200);
}

async function screenshot(filename) {
  const capture = await client.send('Page.captureScreenshot', {
    captureBeyondViewport: false,
    format: 'png',
    fromSurface: true,
  });
  const outputPath = path.join(artifactDirectory, filename);
  await writeFile(outputPath, Buffer.from(capture.data, 'base64'));

  return outputPath;
}

async function setField(selector, value) {
  const changed = await evaluate(`(() => {
    const field = document.querySelector(${JSON.stringify(selector)});
    if (!field) return false;
    field.value = ${JSON.stringify(String(value))};
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  })()`);
  assert(changed, `Missing field: ${selector}`);
}

async function login() {
  await navigate('/login');

  if (!(await evaluate(`Boolean(document.querySelector('[name="login"]'))`))) {
    return;
  }

  await setField('[name="login"]', loginIdentifier);
  await setField('[name="password"]', loginPassword);
  const before = loadCount;
  assert(await evaluate(`(() => {
    const form = document.querySelector('form');
    if (!form) return false;
    form.requestSubmit();
    return true;
  })()`), 'Login form missing.');
  await waitUntil(() => loadCount > before, 'Login did not navigate.');
  await waitUntil(() => evaluate(`!location.pathname.includes('/login')`), 'Header QA login failed.');
}

async function ensureOperatingContext() {
  const selection = await evaluate(`(async () => {
    const config = window.AppOperatingContext || {};

    if (!config.current?.requires_selection) {
      return { selected: false };
    }

    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    };
    const initialResponse = await fetch(config.optionsUrl, { credentials: 'same-origin', headers });
    const initial = (await initialResponse.json()).data;
    const company = [...initial.companies].sort((left, right) => right.text.length - left.text.length)[0];
    const optionsUrl = new URL(config.optionsUrl, location.origin);
    optionsUrl.searchParams.set('company_doc_num', company.doc_num);
    const scopedResponse = await fetch(optionsUrl, { credentials: 'same-origin', headers });
    const scoped = (await scopedResponse.json()).data;
    const branch = [...scoped.branches].sort((left, right) => right.text.length - left.text.length)[0];
    const period = [...scoped.financial_periods].sort((left, right) => right.text.length - left.text.length)[0];
    const saveResponse = await fetch(config.selectUrl, {
      body: JSON.stringify({
        branch_doc_num: branch.doc_num,
        company_doc_num: company.doc_num,
        financial_period_doc_num: period.doc_num,
      }),
      credentials: 'same-origin',
      headers,
      method: 'POST',
    });

    if (!saveResponse.ok) {
      throw new Error('Operating context could not be selected: HTTP ' + saveResponse.status);
    }

    return { branch: branch.text, company: company.text, period: period.text, selected: true };
  })()`);

  if (selection.selected) {
    await navigate('/dashboard');
  }

  return selection;
}

async function auditHeader(expectCompact, expectPwa = false) {
  return evaluate(`(() => {
    const visible = (element) => {
      if (!element) return false;
      const style = getComputedStyle(element);
      const bounds = element.getBoundingClientRect();
      return !element.hidden && style.display !== 'none' && style.visibility !== 'hidden' && bounds.width > 0 && bounds.height > 0;
    };
    const bounds = (selector) => {
      const element = document.querySelector(selector);
      if (!visible(element)) return null;
      const rect = element.getBoundingClientRect();
      return { bottom: rect.bottom, height: rect.height, left: rect.left, right: rect.right, top: rect.top, width: rect.width };
    };
    const selectors = {
      account: '.erp-header-actions .erp-user-menu > .nav-link',
      brand: '.erp-primary-header .erp-navbar-brand',
      context: '.erp-mobile-header-tools .erp-operating-context-trigger',
      menu: '[data-erp-mobile-navigation-toggle]',
      notifications: '.erp-header-actions .erp-notifications-menu > .nav-link',
      pwa: '[data-erp-pwa-navigation]',
      search: '.erp-navigation-search',
    };
    const items = Object.fromEntries(Object.entries(selectors).map(([name, selector]) => [name, bounds(selector)]));
    const inside = Object.fromEntries(Object.entries(items).map(([name, rect]) => [name, !rect || (rect.left >= -1 && rect.right <= innerWidth + 1)]));
    const targets = ['account', 'menu', 'notifications'].map((name) => ({ name, rect: items[name] }));
    const overlaps = [];
    for (let first = 0; first < targets.length; first += 1) {
      for (let second = first + 1; second < targets.length; second += 1) {
        const a = targets[first];
        const b = targets[second];
        if (!a.rect || !b.rect) continue;
        if (a.rect.left < b.rect.right && a.rect.right > b.rect.left && a.rect.top < b.rect.bottom && a.rect.bottom > b.rect.top) {
          overlaps.push(a.name + ':' + b.name);
        }
      }
    }

    return {
      bodyOverflow: Math.max(document.body.scrollWidth, document.documentElement.scrollWidth) - innerWidth,
      compactExpected: ${expectCompact},
      direction: document.documentElement.dir,
      inside,
      items,
      overlaps,
      pwaExpected: ${expectPwa},
      width: innerWidth,
    };
  })()`);
}

function assertHeaderAudit(audit) {
  for (const name of ['account', 'brand', 'notifications']) {
    assert(audit.items[name], `${name} was hidden at ${audit.width}px.`);
    assert(audit.inside[name], `${name} escaped the viewport at ${audit.width}px.`);
  }

  assert(audit.bodyOverflow <= 1, `Header produced horizontal overflow at ${audit.width}px: ${JSON.stringify(audit)}`);
  assert(audit.overlaps.length === 0, `Header targets overlapped at ${audit.width}px: ${audit.overlaps.join(', ')}`);
  for (const name of ['account', 'notifications']) {
    assert(audit.items[name].width >= 43 && audit.items[name].height >= 43, `${name} touch target was too small at ${audit.width}px.`);
  }

  if (audit.compactExpected) {
    assert(audit.items.menu && audit.items.context, `Compact header controls were incomplete at ${audit.width}px.`);
    assert(audit.items.menu.width >= 43 && audit.items.menu.height >= 43, `menu touch target was too small at ${audit.width}px.`);
  } else {
    assert(!audit.items.menu && audit.items.search, `Desktop header did not switch to its expanded organization at ${audit.width}px.`);
  }

  assert(Boolean(audit.items.pwa) === audit.pwaExpected, `PWA toolbar visibility was incorrect at ${audit.width}px.`);
}

async function click(selector) {
  assert(await evaluate(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (!element) return false;
    element.click();
    return true;
  })()`), `Missing clickable element: ${selector}`);
}

const artifacts = [];
const audits = [];

try {
  await setViewport(390, 844);
  await setDisplayMode(null);
  await login();
  await evaluate(`localStorage.setItem('theme', 'light')`);
  await navigate('/dashboard');
  const operatingContext = await ensureOperatingContext();

  for (const [width, height] of [[320, 700], [360, 800], [390, 844], [430, 932], [768, 1024], [1024, 768], [1366, 768]]) {
    await setViewport(width, height);
    await sleep(200);
    const audit = await auditHeader(width < 1200, false);
    assertHeaderAudit(audit);
    audits.push(audit);

    if ([320, 390, 1024, 1366].includes(width)) {
      artifacts.push(await screenshot(`header-browser-ar-${width}.png`));
    }
  }

  await setViewport(844, 390);
  await sleep(200);
  const landscapeAudit = await auditHeader(true, false);
  assertHeaderAudit(landscapeAudit);
  artifacts.push(await screenshot('header-mobile-landscape-browser-ar-844x390.png'));

  await setViewport(390, 844);
  await click('[data-erp-mobile-navigation-toggle]');
  await waitUntil(() => evaluate(`document.querySelector('[data-erp-mobile-navigation]')?.classList.contains('show')`), 'Mobile navigation did not open.');
  await sleep(350);
  const drawerAudit = await evaluate(`(() => {
    const drawer = document.querySelector('[data-erp-mobile-navigation]');
    const bounds = drawer.getBoundingClientRect();
    const controls = [...drawer.querySelectorAll('button, a, select')].filter((element) => {
      const rect = element.getBoundingClientRect();
      return getComputedStyle(element).display !== 'none' && rect.width > 0 && rect.height > 0;
    });
    return {
      bounds: { bottom: bounds.bottom, left: bounds.left, right: bounds.right, top: bounds.top },
      currentLinks: drawer.querySelectorAll('.nav-link.active').length,
      hasLastItem: Boolean(drawer.querySelector('.erp-mobile-navigation-menu li:last-child')),
      minimumTarget: Math.min(...controls.map((element) => element.getBoundingClientRect().height)),
      overflowScrollable: getComputedStyle(drawer.querySelector('.offcanvas-body')).overflowY,
    };
  })()`);
  assert(drawerAudit.bounds.left >= -1 && drawerAudit.bounds.right <= 391, `Mobile drawer overflowed: ${JSON.stringify(drawerAudit)}`);
  assert(drawerAudit.hasLastItem && ['auto', 'scroll'].includes(drawerAudit.overflowScrollable), `Mobile drawer was not fully scrollable: ${JSON.stringify(drawerAudit)}`);
  assert(drawerAudit.minimumTarget >= 43, `Mobile drawer exposed a target smaller than 44px: ${JSON.stringify(drawerAudit)}`);
  artifacts.push(await screenshot('menu-mobile-browser-ar-390.png'));

  await evaluate(`document.querySelector('[data-erp-mobile-navigation]').dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'Escape' }))`);
  await waitUntil(() => evaluate(`!document.querySelector('[data-erp-mobile-navigation]')?.classList.contains('show') && !document.querySelector('.offcanvas-backdrop')`), 'Escape did not close mobile navigation.');
  assert(await evaluate(`document.activeElement?.matches('[data-erp-mobile-navigation-toggle]')`), 'Focus did not return to the mobile navigation toggle.');

  await click('[data-erp-mobile-navigation-toggle]');
  await waitUntil(() => evaluate(`document.querySelector('[data-erp-mobile-navigation]')?.classList.contains('show')`), 'Mobile navigation did not reopen.');
  await click('[data-erp-mobile-navigation] [data-bs-dismiss="offcanvas"]');
  await waitUntil(() => evaluate(`!document.querySelector('[data-erp-mobile-navigation]')?.classList.contains('show') && !document.querySelector('.offcanvas-backdrop')`), 'Close button did not close mobile navigation.');

  await click('[data-erp-mobile-navigation-toggle]');
  await waitUntil(() => evaluate(`Boolean(document.querySelector('.offcanvas-backdrop'))`), 'Mobile navigation backdrop did not appear.');
  await client.send('Input.dispatchMouseEvent', { button: 'left', clickCount: 1, type: 'mousePressed', x: 10, y: 400 });
  await client.send('Input.dispatchMouseEvent', { button: 'left', clickCount: 1, type: 'mouseReleased', x: 10, y: 400 });
  await waitUntil(() => evaluate(`!document.querySelector('[data-erp-mobile-navigation]')?.classList.contains('show') && !document.querySelector('.offcanvas-backdrop')`), 'Backdrop did not close mobile navigation.');

  await click('[data-erp-mobile-navigation-toggle]');
  await waitUntil(() => evaluate(`document.querySelector('[data-erp-mobile-navigation]')?.classList.contains('show')`), 'Mobile navigation did not open before resize.');
  await setViewport(1366, 768);
  await waitUntil(() => evaluate(`!document.querySelector('[data-erp-mobile-navigation]')?.classList.contains('show') && !document.querySelector('.offcanvas-backdrop')`), 'Desktop resize left the mobile navigation overlay active.');
  await setViewport(390, 844);

  await click('.erp-header-actions .erp-user-menu > .nav-link');
  await waitUntil(() => evaluate(`document.querySelector('.erp-user-menu > .dropdown-menu')?.classList.contains('show')`), 'Account menu did not open.');
  const accountAudit = await evaluate(`(() => {
    const menu = document.querySelector('.erp-user-menu > .dropdown-menu');
    const rect = menu.getBoundingClientRect();
    return {
      hasLock: Boolean(menu.querySelector('[data-lock-screen-form]')),
      hasLogout: Boolean(menu.querySelector('#btn_logout')),
      hasProfile: Boolean(menu.querySelector('a[href*="/profile"]')),
      inside: rect.left >= -1 && rect.right <= innerWidth + 1 && rect.top >= -1 && rect.bottom <= innerHeight + 1,
    };
  })()`);
  assert(accountAudit.hasLock && accountAudit.hasLogout && accountAudit.hasProfile && accountAudit.inside, `Account menu was incomplete: ${JSON.stringify(accountAudit)}`);
  artifacts.push(await screenshot('account-mobile-browser-ar-390.png'));
  await click('.erp-header-actions .erp-user-menu > .nav-link');

  await setViewport(1366, 768);
  await navigate('/profile');
  await waitUntil(() => evaluate(`Boolean(document.querySelector('.js-profile-password-form'))`), 'Profile password form did not render.');
  const profileValidationAudit = await evaluate(`(() => {
    const form = document.querySelector('.js-profile-password-form');
    const fields = [...form.querySelectorAll('input[type="password"]')];

    return {
      fields: fields.map((field) => ({ id: field.id, invalid: field.classList.contains('is-invalid') })),
      noValidate: form.noValidate,
    };
  })()`);
  assert(profileValidationAudit.noValidate && profileValidationAudit.fields.every((field) => !field.invalid), `Profile validation appeared before submit: ${JSON.stringify(profileValidationAudit)}`);
  artifacts.push(await screenshot('profile-password-before-submit-ar-1366.png'));

  await setViewport(390, 844);
  await navigate('/dashboard');

  await click('.erp-header-actions .erp-notifications-menu > .nav-link');
  await waitUntil(() => evaluate(`document.querySelector('.dropdown-menu-notification')?.classList.contains('show')`), 'Notifications menu did not open.');
  const notificationInside = await evaluate(`(() => {
    const rect = document.querySelector('.dropdown-menu-notification').getBoundingClientRect();
    return rect.left >= -1 && rect.right <= innerWidth + 1 && rect.bottom <= innerHeight + 1;
  })()`);
  assert(notificationInside, 'Notifications menu escaped the viewport.');
  const notificationCaretAudit = await evaluate(`(() => {
    const trigger = document.querySelector('.erp-notifications-menu > .nav-link');
    const menu = document.querySelector('.dropdown-menu-notification');
    const triggerRect = trigger.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const caret = getComputedStyle(menu, '::after');
    const caretLeft = Number.parseFloat(caret.left);
    const caretWidth = Number.parseFloat(caret.width);
    const caretCenter = menuRect.left + caretLeft + (caretWidth / 2);
    const triggerCenter = triggerRect.left + (triggerRect.width / 2);

    return {
      caretCenter,
      difference: Math.abs(caretCenter - triggerCenter),
      extraneousStatusNodes: menu.querySelectorAll('[data-push-notification-status], [data-notification-sound-status], [data-notifications-health]').length,
      pushControlHidden: document.querySelector('[data-push-notification-control]')?.hidden === true,
      technicalUnavailableHint: menu.innerText.includes('VAPID') || menu.innerText.includes('غير متاحة'),
      triggerCenter,
    };
  })()`);
  assert(notificationCaretAudit.difference <= 6, `Notification caret did not point to the bell: ${JSON.stringify(notificationCaretAudit)}`);
  assert(!notificationCaretAudit.technicalUnavailableHint, `Notification menu exposed a technical Push hint: ${JSON.stringify(notificationCaretAudit)}`);
  assert(notificationCaretAudit.extraneousStatusNodes === 0, `Notification menu still exposed secondary status text: ${JSON.stringify(notificationCaretAudit)}`);
  artifacts.push(await screenshot('notifications-mobile-browser-ar-390.png'));
  await click('.erp-header-actions .erp-notifications-menu > .nav-link');

  await setDisplayMode('standalone');
  await navigate('/dashboard');
  await waitUntil(() => evaluate(`document.querySelector('[data-erp-pwa-navigation]')?.hidden === false`), 'Standalone toolbar did not appear.');
  const standaloneAudit = await auditHeader(true, true);
  assertHeaderAudit(standaloneAudit);
  const pwaAudit = await evaluate(`(() => {
    const toolbar = document.querySelector('[data-erp-pwa-navigation]');
    const rect = toolbar.getBoundingClientRect();
    const buttons = [...toolbar.querySelectorAll('button')];
    return {
      bottomAligned: rect.bottom <= innerHeight + 1 && rect.bottom >= innerHeight - 2,
      buttonHeights: buttons.map((button) => button.getBoundingClientRect().height),
      labels: buttons.map((button) => button.innerText.trim()),
      toolbarCount: document.querySelectorAll('[data-erp-pwa-navigation]').length,
    };
  })()`);
  assert(pwaAudit.toolbarCount === 1 && pwaAudit.bottomAligned, `Standalone toolbar placement failed: ${JSON.stringify(pwaAudit)}`);
  assert(pwaAudit.buttonHeights.every((height) => height >= 44) && pwaAudit.labels.every(Boolean), `Standalone toolbar targets failed: ${JSON.stringify(pwaAudit)}`);
  artifacts.push(await screenshot('pwa-standalone-simulated-ar-390.png'));

  await setViewport(1366, 768);
  const standaloneDesktopAudit = await auditHeader(false, true);
  assertHeaderAudit(standaloneDesktopAudit);
  artifacts.push(await screenshot('pwa-standalone-simulated-ar-1366.png'));

  await setViewport(390, 844);

  await navigate('/profile/edit');
  await waitUntil(() => evaluate(`Boolean(document.querySelector('form input:not([type="hidden"]), form textarea, form select'))`), 'No editable document form was available for the unsaved-change test.');
  const guardAudit = await evaluate(`(() => {
    const form = [...document.forms].find((candidate) => (candidate.getAttribute('method') || 'get').toLowerCase() !== 'get'
      && candidate.querySelector('input:not([type="hidden"]), textarea, select'));
    let prompted = 0;
    const originalConfirm = window.confirm;
    window.confirm = () => { prompted += 1; return false; };
    window.AppNavigationGuard.markDirty(form);
    document.querySelector('[data-erp-pwa-page-reload]').click();
    const result = { dirty: window.AppNavigationGuard.isDirty(), path: location.pathname, prompted };
    window.confirm = originalConfirm;
    window.AppNavigationGuard.markSaved(form);
    return result;
  })()`);
  assert(guardAudit.dirty && guardAudit.prompted === 1 && guardAudit.path.endsWith('/profile/edit'), `Unsaved changes were not protected: ${JSON.stringify(guardAudit)}`);

  await evaluate(`document.querySelector('form input:not([type="hidden"]), form textarea, form select')?.focus()`);
  await client.send('Emulation.setVisibleSize', { height: 500, width: 390 });
  await sleep(250);
  const keyboardSimulation = await evaluate(`(() => ({
    innerHeight,
    toolbarDisplay: getComputedStyle(document.querySelector('[data-erp-pwa-navigation]')).display,
    viewportHeight: window.visualViewport?.height || innerHeight,
    virtualKeyboardClass: document.documentElement.classList.contains('erp-virtual-keyboard-open'),
  }))()`);
  if (keyboardSimulation.innerHeight - keyboardSimulation.viewportHeight > 120) {
    assert(keyboardSimulation.virtualKeyboardClass && keyboardSimulation.toolbarDisplay === 'none', `Virtual keyboard protection failed: ${JSON.stringify(keyboardSimulation)}`);
  }
  await client.send('Emulation.setVisibleSize', { height: 844, width: 390 });
  await setViewport(390, 844);

  await navigate('/dashboard');
  offlineExpected = true;
  await client.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
  await evaluate(`window.dispatchEvent(new Event('offline'))`);
  assert(await evaluate(`document.querySelector('[data-erp-connectivity-status]')?.hidden === false`), 'Offline status did not appear.');
  artifacts.push(await screenshot('connectivity-offline-simulated-ar-390.png'));
  await client.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  offlineExpected = false;
  await click('[data-erp-connectivity-retry]');
  await waitUntil(() => evaluate(`document.documentElement.dataset.connectivity === 'online'`), 'Connectivity retry did not confirm the server.');

  await setDisplayMode(null);
  await navigate('/lang/en');
  await navigate('/dashboard');
  assert((await evaluate(`document.documentElement.dir`)) === 'ltr', 'English direction did not activate.');
  assertHeaderAudit(await auditHeader(true, false));
  artifacts.push(await screenshot('header-mobile-browser-en-390.png'));

  await navigate('/lang/ar');
  await navigate('/dashboard');

  await setViewport(1366, 768);
  await evaluate(`localStorage.setItem('theme', 'dark')`);
  await navigate('/dashboard');
  assert((await evaluate(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'Dark mode did not activate.');
  await click('.erp-header-actions .erp-notifications-menu > .nav-link');
  await waitUntil(() => evaluate(`document.querySelector('.dropdown-menu-notification')?.classList.contains('show')`), 'Dark notification menu did not open.');
  artifacts.push(await screenshot('notifications-desktop-dark-ar-1366.png'));
  await click('.erp-header-actions .erp-notifications-menu > .nav-link');

  const darkToastAudit = await evaluate(`(() => {
    if (!window.Swal || !window.AppAlerts) return null;
    window.AppAlerts.toast('info', 'إشعار تجريبي', { text: 'تم وصول تحديث جديد بنجاح.', timer: 0 });
    const popup = document.querySelector('.swal2-popup');
    const style = getComputedStyle(popup);
    return {
      background: style.backgroundColor,
      color: style.color,
      theme: document.documentElement.getAttribute('data-swal2-theme'),
      visible: Boolean(popup),
    };
  })()`);
  assert(darkToastAudit?.visible && darkToastAudit.theme === 'dark' && darkToastAudit.background !== darkToastAudit.color, `Dark SweetAlert was not readable: ${JSON.stringify(darkToastAudit)}`);
  artifacts.push(await screenshot('sweetalert-notification-dark-ar-1366.png'));
  await evaluate(`window.Swal.close()`);

  await evaluate(`localStorage.setItem('theme', 'light')`);
  await navigate('/dashboard');
  const lightToastAudit = await evaluate(`(() => {
    window.AppAlerts.toast('success', 'إشعار تجريبي', { text: 'تم وصول تحديث جديد بنجاح.', timer: 0 });
    const popup = document.querySelector('.swal2-popup');
    const style = getComputedStyle(popup);
    return {
      background: style.backgroundColor,
      color: style.color,
      theme: document.documentElement.getAttribute('data-swal2-theme'),
      visible: Boolean(popup),
    };
  })()`);
  assert(lightToastAudit.visible && lightToastAudit.theme === 'light' && lightToastAudit.background !== lightToastAudit.color, `Light SweetAlert was not readable: ${JSON.stringify(lightToastAudit)}`);
  artifacts.push(await screenshot('sweetalert-notification-light-ar-1366.png'));
  await evaluate(`window.Swal.close()`);
  await setViewport(390, 844);

  await click('.erp-header-actions .erp-user-menu > .nav-link');
  const beforeLock = loadCount;
  await click('#btn_lock_screen');
  await waitUntil(() => loadCount > beforeLock, 'Lock action did not navigate.');
  const lockedPage = await evaluate(`({
    hasHeader: Boolean(document.querySelector('.erp-primary-header')),
    hasPassword: Boolean(document.querySelector('[name="password"]')),
    path: location.pathname,
  })`);
  assert(lockedPage.path === '/lock-screen' && lockedPage.hasPassword && !lockedPage.hasHeader, `Lock screen was invalid: ${JSON.stringify(lockedPage)}`);
  await navigate('/dashboard');
  const protectedWhileLocked = await evaluate('location.pathname');
  assert(protectedWhileLocked === '/lock-screen', `Protected route bypassed the active lock: ${protectedWhileLocked}`);
  await setField('[name="password"]', loginPassword);
  const beforeUnlock = loadCount;
  assert(await evaluate(`(() => { const form = document.querySelector('form'); if (!form) return false; form.requestSubmit(); return true; })()`), 'Unlock form missing.');
  await waitUntil(() => loadCount > beforeUnlock, 'Unlock did not navigate.');
  await waitUntil(() => evaluate(`Boolean(document.querySelector('.erp-primary-header'))`), 'Authenticated header did not return after unlock.');

  await click('.erp-header-actions .erp-user-menu > .nav-link');
  const beforeLogout = loadCount;
  await click('#btn_logout');
  await waitUntil(() => loadCount > beforeLogout, 'Logout did not navigate.');
  assert((await evaluate('location.pathname')).includes('/login'), 'Logout did not end the session.');
  await navigate('/dashboard');
  assert((await evaluate('location.pathname')).includes('/login'), 'Back navigation restored a protected authenticated page after logout.');

  const result = {
    account: accountAudit,
    artifacts,
    audits,
    browserErrors: [...new Set(browserErrors)].filter(Boolean),
    connectivity: { offlineShown: true, retryConfirmed: true },
    drawer: drawerAudit,
    drawerClosures: { backdrop: true, closeButton: true, escape: true, resize: true },
    guard: guardAudit,
    keyboardSimulation,
    locale: { ar: true, en: true },
    landscape: landscapeAudit,
    lockLogout: { lockBlockedProtectedRoute: true, lockedPage, logoutEndedSession: true },
    notificationsInsideViewport: notificationInside,
    notificationCaret: notificationCaretAudit,
    notificationThemes: { dark: darkToastAudit, light: lightToastAudit },
    operatingContext,
    pwa: pwaAudit,
    pwaDesktop: standaloneDesktopAudit,
    profileValidation: profileValidationAudit,
    responseErrors: [...new Set(responseErrors)],
    testedDisplayMode: 'standalone (Chrome DevTools display-mode with navigator.standalone fallback emulation)',
    viewports: audits.map(({ width }) => width),
  };
  assert(result.browserErrors.length === 0, `Browser errors: ${result.browserErrors.join(' | ')}`);
  assert(result.responseErrors.length === 0, `HTTP errors: ${result.responseErrors.join(' | ')}`);
  await writeFile(path.join(artifactDirectory, 'result.json'), JSON.stringify(result, null, 2));
  await rm(path.join(artifactDirectory, 'failure.txt'), { force: true });
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
} catch (error) {
  await writeFile(path.join(artifactDirectory, 'failure.txt'), `${error.stack || error}\nURL: ${await evaluate('location.href').catch(() => 'unknown')}\n${await evaluate('document.body.innerText').catch(() => '')}`);
  throw error;
} finally {
  client.close();
}
