import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.MOBILE_PWA_E2E_BASE_URL || 'http://127.0.0.1:8765').replace(/\/$/, '');
const debuggerUrl = (process.env.MOBILE_PWA_E2E_DEBUG_URL || 'http://127.0.0.1:9222').replace(/\/$/, '');
const artifactDirectory = process.env.MOBILE_PWA_E2E_ARTIFACT_DIR;
const skipPush = process.env.MOBILE_PWA_E2E_SKIP_PUSH === '1';
const loginIdentifier = process.env.MOBILE_PWA_E2E_LOGIN || 'admin';
const loginPassword = process.env.MOBILE_PWA_E2E_PASSWORD || 'admin';

if (!artifactDirectory) {
  throw new Error('MOBILE_PWA_E2E_ARTIFACT_DIR is required.');
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
const browserErrors = [];
const responseErrors = [];
const failedRequests = [];
client.on('Page.loadEventFired', () => { loadCount += 1; });
client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
  browserErrors.push(exceptionDetails?.exception?.description || exceptionDetails?.text || 'Unhandled JavaScript exception');
});
client.on('Runtime.consoleAPICalled', ({ type, args }) => {
  if (type === 'error' || type === 'assert') {
    browserErrors.push(args.map((argument) => argument.value || argument.description || '').join(' '));
  }
});
client.on('Log.entryAdded', ({ entry }) => {
  if (entry.level === 'error' && !entry.url?.endsWith('/favicon.ico')) {
    browserErrors.push(entry.url ? `${entry.url}: ${entry.text}` : entry.text);
  }
});
client.on('Network.responseReceived', ({ response, type }) => {
  if (type === 'Document' && response?.url?.startsWith(baseUrl) && response.status >= 400 && !offlineExpected) {
    responseErrors.push(`${response.status} ${response.url}`);
  }
});
client.on('Network.loadingFailed', ({ type, canceled, errorText }) => {
  if (!offlineExpected && !canceled && ['Document', 'XHR', 'Fetch'].includes(type)) {
    failedRequests.push(`${type}: ${errorText}`);
  }
});

await Promise.all([
  client.send('Page.enable'),
  client.send('Runtime.enable'),
  client.send('Log.enable'),
  client.send('Network.enable'),
  client.send('ServiceWorker.enable'),
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

async function navigate(route, timeout = 45000) {
  const url = route.startsWith('http') ? route : `${baseUrl}${route}`;
  const before = loadCount;
  const navigation = await client.send('Page.navigate', { url });
  if (navigation.errorText && !offlineExpected) throw new Error(`${url}: ${navigation.errorText}`);
  await waitUntil(() => loadCount > before, `Navigation did not complete: ${url}`, timeout);
  await waitUntil(() => evaluate(`document.readyState === 'complete' && Boolean(document.body)`), `Page was not ready: ${url}`, timeout);
  await sleep(100);
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
    assert(!(await evaluate('location.pathname')).includes('/login'), 'Login screen did not expose its form.');
    return;
  }
  await setField('[name="login"]', loginIdentifier);
  await setField('[name="password"]', loginPassword);
  const before = loadCount;
  assert(await evaluate(`(() => { const form = document.querySelector('form'); if (!form) return false; form.requestSubmit(); return true; })()`), 'Login form missing.');
  await waitUntil(() => loadCount > before, 'Login did not navigate.');
  await waitUntil(() => evaluate(`!location.pathname.includes('/login')`), 'Admin login failed.');
}

async function enableSound() {
  await evaluate(`(() => {
    window.__erpAudioPlayCount = 0;
    const original = HTMLMediaElement.prototype.play;
    if (!HTMLMediaElement.prototype.__erpQaWrapped) {
      HTMLMediaElement.prototype.play = function () {
        window.__erpAudioPlayCount += 1;
        return Promise.resolve();
      };
      HTMLMediaElement.prototype.__erpQaWrapped = original;
    }
    const toggle = document.querySelector('[data-notification-sound-toggle]');
    if (toggle?.getAttribute('aria-pressed') === 'true') toggle.click();
    toggle?.click();
  })()`);
  await waitUntil(() => evaluate(`document.querySelector('[data-notification-sound-toggle]')?.getAttribute('aria-pressed') === 'true'`), 'Sound was not enabled through the UI.');
  return evaluate('window.__erpAudioPlayCount');
}

async function subscribeToPush() {
  await client.send('Browser.grantPermissions', { origin: baseUrl, permissions: ['notifications'] });
  await evaluate(`(() => {
    window.__erpPushMessages = [];
    navigator.serviceWorker.addEventListener('message', (event) => {
      if (event.data?.type === 'ERP_PUSH_NOTIFICATION') window.__erpPushMessages.push(event.data.notification);
    });
    document.querySelector('[data-push-notification-toggle]')?.click();
  })()`);
  await waitUntil(
    () => evaluate(`document.querySelector('[data-push-notification-toggle]')?.getAttribute('aria-pressed') === 'true'`),
    'Push subscription was not enabled through the UI.',
    90000,
  );
  const subscription = await evaluate(`navigator.serviceWorker.ready.then((registration) => registration.pushManager.getSubscription()).then((item) => item?.toJSON() || null)`);
  assert(subscription?.endpoint && subscription?.keys?.p256dh && subscription?.keys?.auth, 'Browser did not persist a complete PushSubscription.');
  return { endpointHost: new URL(subscription.endpoint).host, contentEncoding: await evaluate(`PushManager.supportedContentEncodings?.[0] || 'aes128gcm'`) };
}

async function createMobilePurchaseRequisition() {
  await setViewport(390, 844);
  await navigate('/admin/purchases/purchase-requisitions/create');
  assert((await evaluate('location.pathname')).endsWith('/purchase-requisitions/create'), 'Purchase Requisition create screen was inaccessible.');
  await evaluate(`document.querySelector('[data-add-procurement-line]')?.click()`);
  const rowCount = await evaluate(`document.querySelectorAll('[data-procurement-line]').length`);
  assert(rowCount === 2, `Expected two Purchase Requisition lines, found ${rowCount}.`);
  const selected = await evaluate(`(() => {
    const rows = [...document.querySelectorAll('[data-procurement-line]')];
    const products = [...document.querySelectorAll('.js-procurement-product option[value]')].map((option) => option.value).filter(Boolean);
    if (products.length < 2) return null;
    const requiredDate = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
    rows.forEach((row, index) => {
      const product = row.querySelector('.js-procurement-product');
      product.value = products[index];
      product.dispatchEvent(new Event('change', { bubbles: true }));
      row.querySelector('[name$="[requested_quantity]"]').value = String(index + 2);
      row.querySelector('[name$="[required_date]"]').value = requiredDate;
      row.querySelector('[name$="[specification]"]').value = 'Mobile PWA QA line ' + String(index + 1);
    });
    const store = document.querySelector('[name="branch_store_uuid"]');
    if (store?.options.length > 1) store.value = store.options[1].value;
    document.querySelector('[name="department"]').value = 'Mobile QA';
    document.querySelector('[name="notes"]').value = 'Created at 390px through the canonical browser form.';
    return rows.map((row) => row.querySelector('.js-procurement-product option:checked')?.textContent.trim());
  })()`);
  assert(selected?.length === 2 && selected.every(Boolean), 'Purchase Requisition products were not selected.');
  const layout = await auditLayout();
  assert(layout.bodyOverflow === 0 && layout.unwrappedTables.length === 0, `Purchase form overflow: ${JSON.stringify(layout)}`);
  const before = loadCount;
  assert(await evaluate(`(() => { const form = document.querySelector('[data-procurement-form]'); if (!form) return false; form.requestSubmit(); return true; })()`), 'Purchase Requisition form missing.');
  await waitUntil(() => loadCount > before, 'Purchase Requisition save did not redirect.');
  await waitUntil(() => evaluate(`location.pathname.includes('/purchase-requisitions/') && !location.pathname.endsWith('/create')`), 'Purchase Requisition save did not reach its document.');
  const result = await evaluate(`({ url: location.pathname, text: document.body.innerText, doc: location.pathname.split('/').pop() })`);
  for (const label of selected) assert(result.text.includes(label.split('/').pop().trim()) || result.text.includes(label), `Saved Purchase Requisition did not reload ${label}.`);
  await navigate(result.url);
  assert((await evaluate('document.body.innerText')).includes(result.doc), 'Purchase Requisition document number was not present after reload.');
  const pdf = await evaluate(`(async () => {
    const link = [...document.querySelectorAll('a[href]')].find((item) => item.href.includes('/procurement-print/'));
    if (!link) return null;
    const response = await fetch(link.href, { credentials: 'same-origin' });
    const bytes = new Uint8Array(await response.arrayBuffer());
    return { status: response.status, type: response.headers.get('content-type') || '', signature: String.fromCharCode(...bytes.slice(0, 5)) };
  })()`);
  assert(pdf?.status === 200 && pdf.type.startsWith('application/pdf') && pdf.signature === '%PDF-', `Procurement PDF failed: ${JSON.stringify(pdf)}`);
  return { docNum: result.doc, products: selected, pdf };
}

async function auditLayout() {
  return evaluate(`(() => {
    const visible = (element) => {
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    };
    const outside = (element) => {
      const rect = element.getBoundingClientRect();
      return rect.left < -1 || rect.right > innerWidth + 1;
    };
    const descriptor = (element) => element.id || element.getAttribute('name') || element.className || element.tagName;
    const fixedOverflow = [...document.querySelectorAll('body *')].filter((element) => visible(element) && ['fixed', 'sticky'].includes(getComputedStyle(element).position) && outside(element)).map(descriptor).slice(0, 10);
    const formOverflow = [...document.forms].filter((element) => visible(element) && outside(element)).map(descriptor).slice(0, 10);
    const overlayOverflow = [...document.querySelectorAll('.modal.show, .dropdown-menu.show, .select2-dropdown')].filter((element) => visible(element) && outside(element)).map(descriptor).slice(0, 10);
    const unwrappedTables = [...document.querySelectorAll('table')].filter((table) => {
      if (!visible(table) || table.getBoundingClientRect().right <= innerWidth + 1) return false;
      return !table.closest('.table-responsive, .dataTables_scroll, .procurement-lines-scroll, [class*="table-scroll"], [class*="overflow-auto"]');
    }).map(descriptor).slice(0, 10);
    const smallInputs = innerWidth <= 430
      ? [...document.querySelectorAll('input:not([type="hidden"]), select, textarea')].filter((element) => visible(element) && parseFloat(getComputedStyle(element).fontSize) < 15.5).map(descriptor).slice(0, 10)
      : [];
    const clippedText = [...document.querySelectorAll('label, .btn, th, td')].filter((element) => {
      if (!visible(element)) return false;
      const style = getComputedStyle(element);
      return style.overflow === 'hidden' && element.scrollWidth > element.clientWidth + 2 && !element.closest('.table-responsive');
    }).map(descriptor).slice(0, 10);
    return {
      bodyOverflow: Math.max(0, document.documentElement.scrollWidth - innerWidth, document.body.scrollWidth - innerWidth),
      fixedOverflow,
      formOverflow,
      overlayOverflow,
      unwrappedTables,
      smallInputs,
      clippedText,
    };
  })()`);
}

async function deriveCanonicalRoutes() {
  await navigate('/dashboard');
  return evaluate(`(() => [...new Set([...document.querySelectorAll('nav a[href], [data-navbar-top] a[href]')]
    .map((link) => new URL(link.href, location.origin))
    .filter((url) => url.origin === location.origin && (url.pathname === '/dashboard' || url.pathname.startsWith('/admin/')))
    .filter((url) => !url.pathname.includes('/print') && !url.pathname.includes('/export') && !url.pathname.includes('/download'))
    .map((url) => url.pathname + url.search))].sort())()`);
}

async function crawlCanonicalRoutes(routes) {
  const widths = [
    [320, 700], [360, 800], [390, 844], [430, 932], [768, 1024], [1024, 768], [1366, 768],
  ];
  const failures = [];
  const warnings = [];
  let visits = 0;

  for (const route of routes) {
    await setViewport(390, 844);
    await navigate(route, 60000);
    for (const [width, height] of widths) {
      await setViewport(width, height);
      await sleep(100);
      const current = await evaluate(`({ path: location.pathname, title: document.title, text: document.body.innerText.slice(0, 1000) })`);
      if (current.path.includes('/login') || /403 Forbidden|404 Not Found|500 Server Error/.test(current.text)) {
        failures.push({ width, route, reason: `Unexpected destination ${current.path}` });
        continue;
      }
      const layout = await auditLayout();
      const blocking = layout.bodyOverflow > 0
        || layout.fixedOverflow.length
        || layout.formOverflow.length
        || layout.overlayOverflow.length
        || layout.unwrappedTables.length;
      if (blocking) failures.push({ width, route, layout });
      if (layout.smallInputs.length || layout.clippedText.length) warnings.push({ width, route, smallInputs: layout.smallInputs, clippedText: layout.clippedText });
      visits += 1;
    }
  }

  return { widths, routes: routes.length, routeList: routes, visits, failures, warnings };
}

async function testOfflineForm() {
  await setViewport(390, 844);
  await navigate('/admin/purchases/purchase-requisitions/create');
  await setField('[name="department"]', 'Offline retained value');
  const cachedFallback = await evaluate(`caches.match('/offline').then((response) => response?.text() || '')`);
  assert(/offline|اتصال|الإنترنت/i.test(cachedFallback), 'Localized offline fallback was not present in the active Service Worker cache.');
  let outcome;
  try {
    offlineExpected = true;
    await client.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
    await evaluate(`window.dispatchEvent(new Event('offline'))`);
    const connectivity = await evaluate(`({ hidden: document.querySelector('[data-erp-connectivity-status]')?.hidden, text: document.querySelector('[data-erp-connectivity-status]')?.innerText || '' })`);
    assert(connectivity.hidden === false && /offline|اتصال|الإنترنت/i.test(connectivity.text), `Offline status was not visible: ${JSON.stringify(connectivity)}`);
    outcome = await evaluate(`(async () => {
      const form = document.querySelector('[data-procurement-form]');
      try {
        await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
        return 'unexpected-success';
      } catch (error) {
        return document.querySelector('[name="department"]')?.value || '';
      }
    })()`);
    assert(outcome === 'Offline retained value', `Offline form did not fail safely or retain data: ${outcome}`);
  } finally {
    await client.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    offlineExpected = false;
  }
  await navigate('/dashboard');
  return { formRetained: true, fallbackCached: true, connectivityVisible: true };
}

try {
  await setViewport(390, 844);
  await login();
  await navigate('/dashboard');

  const manifest = await evaluate(`fetch('/manifest.webmanifest').then(async (response) => ({ status: response.status, body: await response.json() }))`);
  assert(manifest.status === 200, `Manifest returned ${manifest.status}.`);
  assert(manifest.body.display === 'standalone' && manifest.body.scope === '/' && manifest.body.start_url === '/dashboard', `Invalid manifest: ${JSON.stringify(manifest.body)}`);
  assert(manifest.body.icons.some((icon) => icon.sizes === '192x192') && manifest.body.icons.some((icon) => icon.sizes === '512x512'), 'Manifest install icons are incomplete.');
  const iconStatuses = await evaluate(`Promise.all(${JSON.stringify(manifest.body.icons)}.map((icon) => fetch(icon.src).then((response) => response.status)))`);
  assert(iconStatuses.every((status) => status === 200), `Manifest icon request failed: ${iconStatuses.join(', ')}`);

  const firstRegistration = await evaluate(`navigator.serviceWorker.ready.then((registration) => ({ scope: registration.scope, active: registration.active?.state, script: registration.active?.scriptURL }))`);
  assert(firstRegistration.active === 'activated' && firstRegistration.scope === `${baseUrl}/`, `Service Worker did not activate: ${JSON.stringify(firstRegistration)}`);
  await navigate('/dashboard');
  const controlled = await evaluate(`Boolean(navigator.serviceWorker.controller)`);
  assert(controlled, 'Service Worker did not control the authenticated page after reload.');
  const appManifest = await client.send('Page.getAppManifest');
  const installability = await client.send('Page.getInstallabilityErrors').catch((error) => ({ unsupported: error.message }));
  assert(!(appManifest.errors || []).some((error) => error.critical === 2), `Critical app manifest errors: ${JSON.stringify(appManifest.errors)}`);

  await client.send('Emulation.setEmulatedMedia', { features: [{ name: 'display-mode', value: 'standalone' }] });
  const standalone = await evaluate(`matchMedia('(display-mode: standalone)').matches`);
  await waitUntil(
    () => evaluate(`document.querySelector('[data-erp-pwa-navigation]')?.hidden === false`),
    'Installed PWA navigation controls did not become visible.',
  );
  const pwaNavigation = await evaluate(`(() => {
    const toolbar = document.querySelector('[data-erp-pwa-navigation]');
    const buttons = toolbar ? [...toolbar.querySelectorAll('button')] : [];
    const bounds = toolbar?.getBoundingClientRect();

    return {
      buttonCount: buttons.length,
      labels: buttons.map((button) => button.getAttribute('aria-label')),
      insideViewport: Boolean(bounds && bounds.left >= 0 && bounds.right <= innerWidth),
      visible: Boolean(toolbar && !toolbar.hidden),
    };
  })()`);
  assert(
    pwaNavigation.visible && pwaNavigation.buttonCount === 3 && pwaNavigation.labels.every(Boolean),
    `Invalid installed PWA navigation controls: ${JSON.stringify(pwaNavigation)}`,
  );
  assert(pwaNavigation.insideViewport, `PWA navigation overflowed the mobile viewport: ${JSON.stringify(pwaNavigation)}`);

  const staticCache = await evaluate(`(async () => {
    const asset = [...document.scripts].map((script) => script.src).find((src) => src.includes('/assets/js/theme.js'));
    await fetch(asset);
    await new Promise((resolve) => setTimeout(resolve, 300));
    const keys = await caches.keys();
    const matches = await Promise.all(keys.map(async (key) => Boolean(await (await caches.open(key)).match(asset))));
    return { asset, keys, cached: matches.some(Boolean) };
  })()`);
  assert(staticCache.cached, `Versioned static asset was not cached: ${JSON.stringify(staticCache)}`);

  const purchase = await createMobilePurchaseRequisition();
  const offline = await testOfflineForm();
  await navigate('/dashboard');
  const soundBaseline = await enableSound();
  let push;
  if (skipPush) {
    await sleep(100);
    await evaluate(`{ window.AppNotificationSound.play(); window.AppNotificationSound.play(); window.AppNotificationSound.play(); }`);
    const throttledAudioPlays = await evaluate('window.__erpAudioPlayCount');
    assert(throttledAudioPlays - soundBaseline <= 1, `Rapid foreground sound overlapped: ${throttledAudioPlays - soundBaseline} plays.`);
    await evaluate(`document.querySelector('[data-notification-sound-toggle]')?.click()`);
    await waitUntil(() => evaluate(`document.querySelector('[data-notification-sound-toggle]')?.getAttribute('aria-pressed') === 'false'`), 'Sound was not muted through the UI.');
    await evaluate(`window.AppNotificationSound.play()`);
    assert((await evaluate('window.__erpAudioPlayCount')) === throttledAudioPlays, 'Muted foreground sound played unexpectedly.');
    push = { skippedInChrome: true, sound: { optIn: true, rapidPlayDelta: throttledAudioPlays - soundBaseline, noOverlap: true, muted: true } };
  } else {
    const subscription = await subscribeToPush();
    await writeFile(path.join(artifactDirectory, 'push-ready.json'), JSON.stringify(subscription, null, 2));
    await waitUntil(() => evaluate(`window.__erpPushMessages.length >= 1`), 'Foreground Web Push was not delivered.', 120000);
    await sleep(4000);
    const foreground = await evaluate(`({ messages: window.__erpPushMessages.length, audioPlays: window.__erpAudioPlayCount, unread: document.querySelector('[data-notifications-count]')?.textContent || '0' })`);
    assert(foreground.messages >= 1 && Number(foreground.unread) >= 1, `Foreground notification did not synchronize: ${JSON.stringify(foreground)}`);
    assert(foreground.audioPlays - soundBaseline === 1, `Foreground sound played ${foreground.audioPlays - soundBaseline} times instead of once.`);

    await evaluate(`document.querySelector('[data-notification-sound-toggle]')?.click()`);
    await waitUntil(() => evaluate(`document.querySelector('[data-notification-sound-toggle]')?.getAttribute('aria-pressed') === 'false'`), 'Sound was not muted through the UI.');
    const mutedBaseline = await evaluate('window.__erpAudioPlayCount');
    const messageBaseline = foreground.messages;
    await writeFile(path.join(artifactDirectory, 'muted-ready.json'), JSON.stringify({ messageBaseline }));
    await waitUntil(() => evaluate(`window.__erpPushMessages.length > ${messageBaseline}`), 'Muted foreground Web Push was not delivered.', 120000);
    await sleep(1000);
    const muted = await evaluate(`({ messages: window.__erpPushMessages.length, audioPlays: window.__erpAudioPlayCount })`);
    assert(muted.audioPlays === mutedBaseline, `Muted push unexpectedly played audio: ${JSON.stringify(muted)}`);
    push = { ...subscription, foreground, muted };
  }

  await writeFile(path.join(artifactDirectory, 'update-ready.json'), '{}');
  await waitUntil(async () => {
    await evaluate(`navigator.serviceWorker.ready.then((registration) => registration.update())`);
    return evaluate(`navigator.serviceWorker.ready.then((registration) => Boolean(registration.waiting))`);
  }, 'Updated Service Worker did not reach waiting state.', 120000);
  await waitUntil(() => evaluate(`document.querySelector('[data-erp-pwa-update]')?.hidden === false`), 'PWA update prompt did not appear.');
  const beforeReload = loadCount;
  await evaluate(`document.querySelector('[data-erp-pwa-reload]')?.click()`);
  await waitUntil(() => loadCount > beforeReload, 'PWA update action did not reload the page.', 60000);
  const updateState = await evaluate(`navigator.serviceWorker.ready.then(async (registration) => ({ active: registration.active?.state, caches: await caches.keys() }))`);
  assert(updateState.active === 'activated' && updateState.caches.some((key) => key.includes('v2-qa')), `PWA update did not activate the new cache: ${JSON.stringify(updateState)}`);
  assert(!updateState.caches.some((key) => key.includes('v1')), `Old PWA cache remained after activation: ${JSON.stringify(updateState.caches)}`);

  const routes = await deriveCanonicalRoutes();
  assert(routes.length > 20, `Canonical menu registry exposed only ${routes.length} routes.`);
  const crawl = await crawlCanonicalRoutes(routes);
  assert(crawl.failures.length === 0, `Canonical route crawl failures: ${JSON.stringify(crawl.failures.slice(0, 20))}`);

  if (!skipPush) {
    await navigate('/dashboard');
    await evaluate(`document.querySelector('[data-push-notification-toggle]')?.click()`);
    await waitUntil(() => evaluate(`document.querySelector('[data-push-notification-toggle]')?.getAttribute('aria-pressed') === 'false'`), 'Push subscription was not removed through the UI.', 60000);
    push.unsubscribed = true;
  }

  const result = {
    manifest: { display: manifest.body.display, scope: manifest.body.scope, startUrl: manifest.body.start_url, iconStatuses },
    serviceWorker: { registration: firstRegistration, controlled, staticCache, update: updateState },
    installability: { manifestErrors: appManifest.errors || [], installability },
    standalone,
    pwaNavigation,
    purchase,
    offline,
    push,
    crawl,
    browserErrors: [...new Set(browserErrors)].filter(Boolean),
    responseErrors: [...new Set(responseErrors)],
    failedRequests: [...new Set(failedRequests)].filter((message) => !message.includes('ERR_ABORTED')),
  };
  assert(result.browserErrors.length === 0, `Browser errors: ${result.browserErrors.join(' | ')}`);
  assert(result.responseErrors.length === 0, `HTTP errors: ${result.responseErrors.join(' | ')}`);
  assert(result.failedRequests.length === 0, `Network errors: ${result.failedRequests.join(' | ')}`);
  await writeFile(path.join(artifactDirectory, 'result.json'), JSON.stringify(result, null, 2));
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
} catch (error) {
  await writeFile(path.join(artifactDirectory, 'failure.txt'), `${error.stack || error}\nURL: ${await evaluate('location.href').catch(() => 'unknown')}\n${await evaluate('document.body.innerText').catch(() => '')}`);
  throw error;
} finally {
  client.close();
}
