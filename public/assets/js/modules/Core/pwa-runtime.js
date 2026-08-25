(function (window, document) {
  'use strict';

  var config = window.AppPwaRuntime || {};
  var updateNotice = document.querySelector('[data-erp-pwa-update]');
  var reloadButton = document.querySelector('[data-erp-pwa-reload]');
  var registration = null;
  var reloadRequested = false;

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
    window.navigator.serviceWorker.register(config.serviceWorkerUrl, {
      scope: config.scope
    }).then(watchRegistration).catch(function () {});
  }

  function unregister() {
    window.navigator.serviceWorker.getRegistrations().then(function (registrations) {
      registrations.forEach(function (currentRegistration) {
        if (currentRegistration.active && currentRegistration.active.scriptURL.indexOf('/pwa-service-worker.js') !== -1) {
          currentRegistration.unregister();
        }
      });
    }).catch(function () {});

    if ('caches' in window) {
      window.caches.keys().then(function (keys) {
        keys.filter(function (key) {
          return key.indexOf(config.cachePrefix || 'erp-pwa-cache') === 0;
        }).forEach(function (key) {
          window.caches.delete(key);
        });
      }).catch(function () {});
    }
  }

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

  window.addEventListener('load', config.enabled ? register : unregister);
})(window, document);
