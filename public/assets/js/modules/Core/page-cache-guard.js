(function (window, document) {
  'use strict';

  if (window.__pageCacheGuardLoaded) {
    return;
  }

  window.__pageCacheGuardLoaded = true;

  var reloadKey = 'erp.page_cache_guard.reloaded_url';

  function navigationEntry() {
    if (!window.performance || typeof window.performance.getEntriesByType !== 'function') {
      return null;
    }

    var entries = window.performance.getEntriesByType('navigation');

    return entries && entries.length ? entries[0] : null;
  }

  function isBackForwardRestore(event) {
    var navigation = navigationEntry();

    return Boolean(event.persisted || (navigation && navigation.type === 'back_forward'));
  }

  function clearPasswordFields() {
    document.querySelectorAll('input[type="password"]').forEach(function (input) {
      input.value = '';
    });
  }

  function reloadedUrl() {
    try {
      return window.sessionStorage.getItem(reloadKey);
    } catch (error) {
      return null;
    }
  }

  function rememberReloadUrl() {
    try {
      window.sessionStorage.setItem(reloadKey, window.location.href);
    } catch (error) {
      // sessionStorage can be disabled; the reload is still safe without it.
    }
  }

  function clearReloadUrl() {
    try {
      window.sessionStorage.removeItem(reloadKey);
    } catch (error) {
      // Nothing to clear when sessionStorage is unavailable.
    }
  }

  window.addEventListener('pageshow', function (event) {
    if (!isBackForwardRestore(event)) {
      clearReloadUrl();

      return;
    }

    clearPasswordFields();

    if (reloadedUrl() === window.location.href) {
      return;
    }

    rememberReloadUrl();
    window.location.reload();
  });
})(window, document);
