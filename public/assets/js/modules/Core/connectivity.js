(function (window, document) {
  'use strict';

  var status = document.querySelector('[data-erp-connectivity-status]');
  var message = status ? status.querySelector('[data-erp-connectivity-message]') : null;
  var retryButton = status ? status.querySelector('[data-erp-connectivity-retry]') : null;
  var originalFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
  var onlineTimer = null;
  var confirmationTimer = null;
  var reconnectTimer = null;
  var healthRequest = null;
  var verificationVersion = 0;
  var verificationPending = false;
  var confirmedOffline = false;

  if (!status || !message) {
    return;
  }

  if (retryButton) {
    retryButton.hidden = true;
  }

  function cancelVerification() {
    verificationVersion += 1;
    verificationPending = false;
    window.clearTimeout(confirmationTimer);
    confirmationTimer = null;
  }

  function scheduleReconnect() {
    if (!confirmedOffline || reconnectTimer !== null) {
      return;
    }

    reconnectTimer = window.setTimeout(function () {
      reconnectTimer = null;
      verifyServer();
    }, 15000);
  }

  function showOffline() {
    if (confirmedOffline) {
      scheduleReconnect();
      return;
    }

    window.clearTimeout(onlineTimer);
    status.classList.remove('alert-success');
    status.classList.add('alert-warning');
    message.textContent = status.dataset.offlineMessage;
    status.hidden = false;
    confirmedOffline = true;
    document.documentElement.dataset.connectivity = 'offline';

    if (retryButton) {
      retryButton.hidden = false;
    }

    scheduleReconnect();
  }

  function showOnline() {
    if (!confirmedOffline) {
      return;
    }

    window.clearTimeout(onlineTimer);
    window.clearTimeout(reconnectTimer);
    reconnectTimer = null;
    status.classList.remove('alert-warning');
    status.classList.add('alert-success');
    message.textContent = status.dataset.onlineMessage;
    status.hidden = false;
    confirmedOffline = false;
    document.documentElement.dataset.connectivity = 'online';

    if (retryButton) {
      retryButton.hidden = true;
    }

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

  function checkHealth() {
    if (!originalFetch) {
      return Promise.resolve(false);
    }

    if (healthRequest) {
      return healthRequest;
    }

    var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
    var timeout = controller ? window.setTimeout(function () { controller.abort(); }, 5000) : null;
    var options = {
      cache: 'no-store',
      credentials: 'same-origin',
      method: 'HEAD'
    };

    if (controller) {
      options.signal = controller.signal;
    }

    healthRequest = Promise.resolve()
      .then(function () { return originalFetch('/up', options); })
      .then(function (response) { return response.ok; })
      .catch(function () { return false; })
      .finally(function () {
        window.clearTimeout(timeout);
        healthRequest = null;
      });

    return healthRequest;
  }

  function confirmFailure(version, attempt) {
    confirmationTimer = null;

    checkHealth().then(function (healthy) {
      if (!verificationPending || version !== verificationVersion) {
        return;
      }

      if (healthy) {
        cancelVerification();
        return;
      }

      if (attempt === 1) {
        confirmationTimer = window.setTimeout(function () {
          confirmFailure(version, 2);
        }, 1000);
        return;
      }

      cancelVerification();
      showOffline();
    });
  }

  function suspectFailure() {
    if (confirmedOffline || verificationPending || !originalFetch) {
      return;
    }

    verificationPending = true;
    var version = ++verificationVersion;

    confirmationTimer = window.setTimeout(function () {
      confirmFailure(version, 1);
    }, 750);
  }

  function reportSuccess() {
    cancelVerification();
    showOnline();
  }

  function reportFailure(error) {
    if (!error || error.name !== 'AbortError') {
      suspectFailure();
    }
  }

  function verifyServer() {
    if (retryButton) {
      retryButton.disabled = true;
    }

    return checkHealth().then(function (healthy) {
      if (healthy) {
        reportSuccess();
      } else if (confirmedOffline) {
        scheduleReconnect();
      } else {
        suspectFailure();
      }

      return healthy;
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
        if (applicationRequest && response.ok) {
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
      .on('ajaxError.erpConnectivity', function (event, response, settings, thrownError) {
        if (settings && isApplicationRequest(settings.url)
          && response && response.status === 0
          && response.statusText !== 'abort' && thrownError !== 'abort') {
          suspectFailure();
        }
      })
      .on('ajaxSuccess.erpConnectivity', function (event, response, settings) {
        if (settings && isApplicationRequest(settings.url)) {
          reportSuccess();
        }
      });
  }

  window.addEventListener('offline', suspectFailure);
  window.addEventListener('online', verifyServer);

  if (retryButton) {
    retryButton.addEventListener('click', verifyServer);
  }

  if (!window.navigator.onLine) {
    suspectFailure();
  }

  window.AppConnectivity = {
    reportFailure: reportFailure,
    reportSuccess: reportSuccess,
    retry: verifyServer
  };
})(window, document);
