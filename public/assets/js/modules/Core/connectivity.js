(function (window, document) {
  'use strict';

  var status = document.querySelector('[data-erp-connectivity-status]');
  var message = status ? status.querySelector('[data-erp-connectivity-message]') : null;
  var onlineTimer = null;

  if (!status || !message) {
    return;
  }

  function showOffline() {
    window.clearTimeout(onlineTimer);
    status.classList.remove('alert-success');
    status.classList.add('alert-warning');
    message.textContent = status.dataset.offlineMessage;
    status.hidden = false;
    document.documentElement.dataset.connectivity = 'offline';
  }

  function showOnline() {
    window.clearTimeout(onlineTimer);
    status.classList.remove('alert-warning');
    status.classList.add('alert-success');
    message.textContent = status.dataset.onlineMessage;
    status.hidden = false;
    document.documentElement.dataset.connectivity = 'online';
    onlineTimer = window.setTimeout(function () {
      status.hidden = true;
    }, 3000);
  }

  window.addEventListener('offline', showOffline);
  window.addEventListener('online', showOnline);

  document.addEventListener('submit', function (event) {
    var form = event.target;

    if (window.navigator.onLine || !form || String(form.method || 'get').toLowerCase() === 'get') {
      return;
    }

    event.preventDefault();
    showOffline();
    form.dispatchEvent(new CustomEvent('erp:offline-submit-blocked', { bubbles: true }));
  }, true);

  if (!window.navigator.onLine) {
    showOffline();
  }
})(window, document);
