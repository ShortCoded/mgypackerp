(function (window, document) {
  'use strict';

  var config = window.AppPwaRuntime || {};
  var updateNotice = document.querySelector('[data-erp-pwa-update]');
  var reloadButton = document.querySelector('[data-erp-pwa-reload]');
  var navigationToolbar = document.querySelector('[data-erp-pwa-navigation]');
  var backButton = document.querySelector('[data-erp-pwa-back]');
  var forwardButton = document.querySelector('[data-erp-pwa-forward]');
  var pageReloadButton = document.querySelector('[data-erp-pwa-page-reload]');
  var registration = null;
  var reloadRequested = false;
  var legacyServiceWorkerPath = '/service-worker.js';
  var disabledCleanupMarkerKey = 'erp-pwa-disabled-cleanup-v1';

  function installedDisplayModeQuery() {
    if (typeof window.matchMedia !== 'function') {
      return null;
    }

    return window.matchMedia([
      '(display-mode: standalone)',
      '(display-mode: fullscreen)',
      '(display-mode: minimal-ui)',
      '(display-mode: window-controls-overlay)'
    ].join(', '));
  }

  function isInstalledDisplayMode(displayModeQuery) {
    return window.navigator.standalone === true || Boolean(displayModeQuery && displayModeQuery.matches);
  }

  function updateNavigationButtonAvailability() {
    var navigationApi = window.navigation;
    var canGoBack = navigationApi && typeof navigationApi.canGoBack === 'boolean'
      ? navigationApi.canGoBack
      : true;
    var canGoForward = navigationApi && typeof navigationApi.canGoForward === 'boolean'
      ? navigationApi.canGoForward
      : true;

    if (backButton) {
      backButton.disabled = !canGoBack;
    }

    if (forwardButton) {
      forwardButton.disabled = !canGoForward;
    }
  }

  function initializePwaNavigation() {
    if (!navigationToolbar) {
      return;
    }

    var displayModeQuery = installedDisplayModeQuery();
    var syncVisibility = function () {
      var isInstalled = isInstalledDisplayMode(displayModeQuery);

      navigationToolbar.hidden = !isInstalled;
      document.documentElement.classList.toggle('erp-pwa-standalone', isInstalled);

      if (isInstalled) {
        updateNavigationButtonAvailability();
      }
    };

    if (backButton) {
      backButton.addEventListener('click', function () {
        window.history.back();
      });
    }

    if (forwardButton) {
      forwardButton.addEventListener('click', function () {
        window.history.forward();
      });
    }

    if (pageReloadButton) {
      pageReloadButton.addEventListener('click', function () {
        window.location.reload();
      });
    }

    if (displayModeQuery) {
      if (typeof displayModeQuery.addEventListener === 'function') {
        displayModeQuery.addEventListener('change', syncVisibility);
      } else if (typeof displayModeQuery.addListener === 'function') {
        displayModeQuery.addListener(syncVisibility);
      }
    }

    if (window.navigation && typeof window.navigation.addEventListener === 'function') {
      window.navigation.addEventListener('currententrychange', updateNavigationButtonAvailability);
    }

    window.addEventListener('pageshow', updateNavigationButtonAvailability);
    window.addEventListener('popstate', updateNavigationButtonAvailability);
    syncVisibility();
  }

  function scriptPath(value) {
    try {
      return new URL(value, window.location.href).pathname;
    } catch (error) {
      return String(value || '');
    }
  }

  function registrationUsesPath(currentRegistration, path) {
    return ['active', 'waiting', 'installing'].some(function (state) {
      var worker = currentRegistration[state];

      return worker && scriptPath(worker.scriptURL) === path;
    });
  }

  function registrations() {
    if (typeof window.navigator.serviceWorker.getRegistrations !== 'function') {
      return Promise.resolve([]);
    }

    return window.navigator.serviceWorker.getRegistrations();
  }

  function disabledCleanupVersion() {
    return [
      scriptPath(config.serviceWorkerUrl),
      String(config.scope || ''),
      String(config.cachePrefix || 'erp-pwa-cache'),
      legacyServiceWorkerPath
    ].join('|');
  }

  function disabledCleanupIsCurrent() {
    try {
      return window.localStorage.getItem(disabledCleanupMarkerKey) === disabledCleanupVersion();
    } catch (error) {
      return false;
    }
  }

  function markDisabledCleanupComplete() {
    try {
      window.localStorage.setItem(disabledCleanupMarkerKey, disabledCleanupVersion());
    } catch (error) {}
  }

  function clearDisabledCleanupMarker() {
    try {
      window.localStorage.removeItem(disabledCleanupMarkerKey);
    } catch (error) {}
  }

  function unregisterLegacy() {
    return registrations().then(function (currentRegistrations) {
      return Promise.all(currentRegistrations
        .filter(function (currentRegistration) {
          return registrationUsesPath(currentRegistration, legacyServiceWorkerPath);
        })
        .map(function (currentRegistration) {
          return currentRegistration.unregister().catch(function () {
            return false;
          });
        }));
    });
  }

  function showUpdateNotice() {
    if (updateNotice) {
      updateNotice.hidden = false;
    }
  }

  function watchRegistration(currentRegistration) {
    registration = currentRegistration;

    if (registration.waiting && window.navigator.serviceWorker.controller) {
      showUpdateNotice();
    }

    registration.addEventListener('updatefound', function () {
      var worker = registration.installing;

      if (!worker) {
        return;
      }

      worker.addEventListener('statechange', function () {
        if (worker.state === 'installed' && window.navigator.serviceWorker.controller) {
          showUpdateNotice();
        }
      });
    });
  }

  function register() {
    return unregisterLegacy().then(function () {
      return window.navigator.serviceWorker.register(config.serviceWorkerUrl, {
        scope: config.scope
      });
    }).then(watchRegistration).catch(function () {});
  }

  function unregister() {
    var managedPaths = [scriptPath(config.serviceWorkerUrl), legacyServiceWorkerPath];
    var unregisterPromise = registrations().then(function (currentRegistrations) {
      return Promise.all(currentRegistrations
        .filter(function (currentRegistration) {
          return managedPaths.some(function (path) {
            return registrationUsesPath(currentRegistration, path);
          });
        })
        .map(function (currentRegistration) {
          return currentRegistration.unregister();
        }));
    });
    var cachePromise = Promise.resolve([]);

    if ('caches' in window) {
      cachePromise = window.caches.keys().then(function (keys) {
        return Promise.all(keys
          .filter(function (key) {
            return key.indexOf(config.cachePrefix || 'erp-pwa-cache') === 0;
          })
          .map(function (key) {
            return window.caches.delete(key);
          }));
      });
    }

    return Promise.all([unregisterPromise, cachePromise]);
  }

  function unregisterOnce() {
    if (disabledCleanupIsCurrent()) {
      return Promise.resolve();
    }

    return unregister().then(function () {
      markDisabledCleanupComplete();
    });
  }

  function registerEnabledRuntime() {
    clearDisabledCleanupMarker();

    return register();
  }

  initializePwaNavigation();

  if (!('serviceWorker' in window.navigator)) {
    return;
  }

  if (reloadButton) {
    reloadButton.addEventListener('click', function () {
      if (!registration || !registration.waiting) {
        return;
      }

      reloadRequested = true;
      reloadButton.disabled = true;
      registration.waiting.postMessage({ type: 'SKIP_WAITING' });
    });
  }

  window.navigator.serviceWorker.addEventListener('controllerchange', function () {
    if (reloadRequested) {
      window.location.reload();
    }
  });

  window.addEventListener('load', config.enabled ? registerEnabledRuntime : function () {
    return unregisterOnce().catch(function () {});
  });
})(window, document);
