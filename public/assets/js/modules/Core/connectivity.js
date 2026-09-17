(function (window, document) {
  'use strict';

  var status = document.querySelector('[data-erp-connectivity-status]');
  var message = status ? status.querySelector('[data-erp-connectivity-message]') : null;
  var retryButton = status ? status.querySelector('[data-erp-connectivity-retry]') : null;
  var originalFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
  var onlineTimer = null;
  var confirmedOffline = false;

  if (!status || !message) {
    return;
  }

  function showOffline() {
    window.clearTimeout(onlineTimer);
    status.classList.remove('alert-success');
    status.classList.add('alert-warning');
    message.textContent = status.dataset.offlineMessage;
    status.hidden = false;
    confirmedOffline = true;
    document.documentElement.dataset.connectivity = 'offline';
  }

  function showOnline() {
    window.clearTimeout(onlineTimer);
    status.classList.remove('alert-warning');
    status.classList.add('alert-success');
    message.textContent = status.dataset.onlineMessage;
    status.hidden = false;
    confirmedOffline = false;
    document.documentElement.dataset.connectivity = 'online';
    onlineTimer = window.setTimeout(function () {
      status.hidden = true;
      document.documentElement.removeAttribute('data-connectivity');
    }, 3000);
  }

  function requestUrl(input) {
    try {
      return new URL(typeof input === 'string' ? input : input.url, window.location.href);
    } catch (error) {
      return null;
    }
  }

  function isApplicationRequest(input) {
    var url = requestUrl(input);

    if (!url || url.origin !== window.location.origin) {
      return false;
    }

    return !['/assets/', '/build/', '/storage/', '/vendors/'].some(function (prefix) {
      return url.pathname.indexOf(prefix) === 0;
    });
  }

  function reportSuccess() {
    if (confirmedOffline || document.documentElement.dataset.connectivity === 'offline') {
      showOnline();
    }
  }

  function reportFailure(error) {
    if (!error || error.name !== 'AbortError') {
      showOffline();
    }
  }

  function verifyServer() {
    if (!originalFetch) {
      return Promise.resolve(false);
    }

    if (retryButton) {
      retryButton.disabled = true;
    }

    return originalFetch(window.location.href, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: {
        Accept: 'text/html',
        'X-Requested-With': 'XMLHttpRequest'
      },
      method: 'HEAD'
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Server unavailable');
      }

      showOnline();

      return true;
    }).catch(function () {
      showOffline();

      return false;
    }).finally(function () {
      if (retryButton) {
        retryButton.disabled = false;
      }
    });
  }

  if (originalFetch) {
    window.fetch = function (input, options) {
      var applicationRequest = isApplicationRequest(input);

      return originalFetch(input, options).then(function (response) {
        if (applicationRequest) {
          reportSuccess();
        }

        return response;
      }).catch(function (error) {
        if (applicationRequest) {
          reportFailure(error);
        }

        throw error;
      });
    };
  }

  if (window.jQuery) {
    window.jQuery(document)
      .off('.erpConnectivity')
      .on('ajaxError.erpConnectivity', function (event, response) {
        if (response && response.status === 0) {
          showOffline();
        }
      })
      .on('ajaxSuccess.erpConnectivity', reportSuccess);
  }

  window.addEventListener('offline', showOffline);
  window.addEventListener('online', verifyServer);

  if (retryButton) {
    retryButton.addEventListener('click', verifyServer);
  }

  if (!window.navigator.onLine) {
    showOffline();
  }

  window.AppConnectivity = {
    reportFailure: reportFailure,
    reportSuccess: reportSuccess,
    retry: verifyServer
  };
})(window, document);
