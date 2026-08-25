<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Core\Http\Requests\UpdatePwaSettingsRequest;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\PwaSettingsService;

class PwaSettingsController extends Controller
{
    public function __construct(
        private readonly PwaSettingsService $pwaSettings,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(): View
    {
        return view('modules.core.settings.pwa', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.settings.pwa'),
            'settings' => $this->pwaSettings->settings(),
            'displayModes' => ['standalone', 'fullscreen', 'minimal-ui', 'browser'],
            'orientations' => ['any', 'portrait', 'landscape'],
            'directions' => ['auto', 'rtl', 'ltr'],
        ]);
    }

    public function update(UpdatePwaSettingsRequest $request): RedirectResponse
    {
        $this->pwaSettings->update($request->validated());

        return back()->with('success', __('pwa.messages.updated'));
    }

    public function manifest(): JsonResponse
    {
        return response()
            ->json($this->pwaSettings->manifest())
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    public function serviceWorker(): Response
    {
        $settings = $this->pwaSettings->settings();
        $offlineEnabled = $settings['enabled'] && $settings['service_worker_enabled'] && $settings['offline_enabled'];
        $cacheName = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $settings['cache_name']) ?: 'erp-pwa-cache-v1';
        $offlineUrl = route('pwa.offline', [], false);
        $manifestUrl = route('pwa.manifest', [], false);
        $serviceWorkerUrl = route('pwa.service-worker', [], false);
        $dashboardUrl = route('dashboard', [], false);
        $defaultPushTitle = (string) $settings['app_name'];
        $networkOnlyExactPaths = [
            route('auth.csrf-token', [], false),
            route('lock-screen.show', [], false),
            route('lock-screen.store', [], false),
            route('lock-screen.unlock', [], false),
            route('login', [], false),
            route('login.store', [], false),
            route('logout', [], false),
            route('session.status', [], false),
            route('session.touch', [], false),
            $serviceWorkerUrl,
        ];
        $navigationBypassExactPaths = [
            ...$networkOnlyExactPaths,
            $manifestUrl,
        ];
        $networkOnlyPrefixes = [
            '/_debugbar',
            '/admin/chat/',
            '/admin/file-manager/picker/',
            '/admin/navigation-search',
            '/admin/notifications/',
            '/admin/operating-context/',
            '/admin/select2/',
            '/debugbar',
            '/telescope',
        ];
        $staticAssetPrefixes = [
            '/assets/',
            '/build/',
            '/storage/pwa/icons/',
            '/vendors/',
        ];

        $script = sprintf(
            <<<'JS'
const ERP_PWA_CACHE = %s;
const ERP_PWA_OFFLINE_URL = %s;
const ERP_PWA_MANIFEST_URL = %s;
const ERP_PWA_OFFLINE_ENABLED = %s;
const ERP_PWA_NAVIGATION_BYPASS_EXACT_PATHS = %s;
const ERP_PWA_NETWORK_ONLY_EXACT_PATHS = %s;
const ERP_PWA_NETWORK_ONLY_PREFIXES = %s;
const ERP_PWA_STATIC_ASSET_PREFIXES = %s;
const ERP_PWA_DASHBOARD_URL = %s;
const ERP_PWA_DEFAULT_PUSH_TITLE = %s;
const ERP_PWA_STATIC_EXTENSION_PATTERN = /\.(?:avif|bmp|css|eot|gif|ico|jpeg|jpg|js|map|otf|png|svg|ttf|wasm|webp|woff|woff2)$/i;

self.addEventListener('install', (event) => {
  if (!ERP_PWA_OFFLINE_ENABLED) {
    self.skipWaiting();
    return;
  }

  event.waitUntil(
    caches.open(ERP_PWA_CACHE)
      .then((cache) => cache.add(ERP_PWA_OFFLINE_URL))
      .catch(() => undefined)
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => key !== ERP_PWA_CACHE && key.startsWith('erp-pwa-cache'))
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('push', (event) => {
  event.waitUntil(handlePush(event));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(focusOrOpenApp(event.notification.data && event.notification.data.url));
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (!ERP_PWA_OFFLINE_ENABLED || request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin || isNetworkOnlyPath(url.pathname)) {
    return;
  }

  if (url.pathname === ERP_PWA_MANIFEST_URL) {
    event.respondWith(networkFirstCached(request));
    return;
  }

  if (isNavigationRequest(request, url)) {
    event.respondWith(networkFirstNavigation(request));
    return;
  }

  if (isStaticAssetRequest(url)) {
    event.respondWith(cacheFirstStatic(request));
  }
});

function isNavigationRequest(request, url) {
  const accept = request.headers.get('accept') || '';

  return request.mode === 'navigate'
    && accept.includes('text/html')
    && !ERP_PWA_NAVIGATION_BYPASS_EXACT_PATHS.includes(url.pathname);
}

async function handlePush(event) {
  let payload = {};

  if (event.data) {
    try {
      payload = event.data.json();
    } catch (error) {
      payload = { body: event.data.text() };
    }
  }

  const targetUrl = safeAppUrl(payload.data && payload.data.url);
  const windowClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  const foregroundClient = windowClients.find((client) => client.focused && client.visibilityState === 'visible')
    || windowClients.find((client) => client.visibilityState === 'visible');

  if (foregroundClient) {
    foregroundClient.postMessage({ type: 'ERP_PUSH_NOTIFICATION', notification: payload });
    return;
  }

  await self.registration.showNotification(payload.title || ERP_PWA_DEFAULT_PUSH_TITLE, {
    body: payload.body || '',
    icon: payload.icon || undefined,
    badge: payload.badge || payload.icon || undefined,
    tag: payload.tag || (payload.data && payload.data.id) || undefined,
    renotify: false,
    data: Object.assign({}, payload.data || {}, { url: targetUrl })
  });
}

function safeAppUrl(value) {
  try {
    const target = new URL(value || ERP_PWA_DASHBOARD_URL, self.location.origin);

    return target.origin === self.location.origin
      ? target.href
      : new URL(ERP_PWA_DASHBOARD_URL, self.location.origin).href;
  } catch (error) {
    return new URL(ERP_PWA_DASHBOARD_URL, self.location.origin).href;
  }
}

async function focusOrOpenApp(value) {
  const targetUrl = safeAppUrl(value);
  const windowClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  const existingClient = windowClients.find((client) => new URL(client.url).origin === self.location.origin);

  if (existingClient) {
    if (existingClient.url !== targetUrl && 'navigate' in existingClient) {
      await existingClient.navigate(targetUrl);
    }

    return existingClient.focus();
  }

  return self.clients.openWindow(targetUrl);
}

function isNetworkOnlyPath(pathname) {
  return ERP_PWA_NETWORK_ONLY_EXACT_PATHS.includes(pathname)
    || ERP_PWA_NETWORK_ONLY_PREFIXES.some((prefix) => pathname.startsWith(prefix));
}

function isStaticAssetRequest(url) {
  return ERP_PWA_STATIC_ASSET_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))
    || ERP_PWA_STATIC_EXTENSION_PATTERN.test(url.pathname);
}

function cacheableResponse(response) {
  const contentType = response.headers.get('content-type') || '';

  return response
    && response.ok
    && response.type !== 'opaque'
    && !contentType.includes('text/html');
}

function networkFirstNavigation(request) {
  return fetch(request).catch(() => caches.match(ERP_PWA_OFFLINE_URL).then((response) => response || Response.error()));
}

function networkFirstCached(request) {
  return fetch(request)
    .then((response) => {
      if (cacheableResponse(response)) {
        const copy = response.clone();

        caches.open(ERP_PWA_CACHE).then((cache) => cache.put(request, copy));
      }

      return response;
    })
    .catch(() => caches.match(request).then((response) => response || Response.error()));
}

function cacheFirstStatic(request) {
  return caches.match(request).then((cached) => {
    if (cached) {
      return cached;
    }

    return fetch(request).then((response) => {
      if (cacheableResponse(response)) {
        const copy = response.clone();

        caches.open(ERP_PWA_CACHE).then((cache) => cache.put(request, copy));
      }

      return response;
    });
  });
}
JS,
            json_encode($cacheName, JSON_THROW_ON_ERROR),
            json_encode($offlineUrl, JSON_THROW_ON_ERROR),
            json_encode($manifestUrl, JSON_THROW_ON_ERROR),
            $offlineEnabled ? 'true' : 'false',
            json_encode(array_values(array_unique($navigationBypassExactPaths)), JSON_THROW_ON_ERROR),
            json_encode(array_values(array_unique($networkOnlyExactPaths)), JSON_THROW_ON_ERROR),
            json_encode($networkOnlyPrefixes, JSON_THROW_ON_ERROR),
            json_encode($staticAssetPrefixes, JSON_THROW_ON_ERROR),
            json_encode($dashboardUrl, JSON_THROW_ON_ERROR),
            json_encode($defaultPushTitle, JSON_THROW_ON_ERROR),
        );

        return response($script, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    public function offline(): View
    {
        return view('modules.core.settings.offline', [
            'settings' => $this->pwaSettings->settings(),
        ]);
    }
}
