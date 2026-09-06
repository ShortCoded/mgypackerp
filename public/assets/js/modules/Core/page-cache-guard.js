(function (window, document) {
  'use strict';

  if (window.__pageCacheGuardLoaded) {
    return;
  }

  window.__pageCacheGuardLoaded = true;

  var pageRoot = document.documentElement;
  var previousPointerEvents = '';
  var previousVisibility = '';
  var isNavigating = false;
  var restoreClaimed = false;
  var restoreLocked = false;

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

  function lockRestoredPage() {
    if (restoreLocked) {
      return;
    }

    restoreLocked = true;

    if (!pageRoot || !pageRoot.style) {
      return;
    }

    previousPointerEvents = pageRoot.style.pointerEvents;
    previousVisibility = pageRoot.style.visibility;
    pageRoot.style.pointerEvents = 'none';
    pageRoot.style.visibility = 'hidden';

    if (typeof pageRoot.setAttribute === 'function') {
      pageRoot.setAttribute('data-erp-bfcache-locked', 'true');
    }
  }

  function unlockRestoredPage() {
    if (!restoreLocked) {
      return;
    }

    if (pageRoot && pageRoot.style) {
      pageRoot.style.pointerEvents = previousPointerEvents;
      pageRoot.style.visibility = previousVisibility;

      if (typeof pageRoot.removeAttribute === 'function') {
        pageRoot.removeAttribute('data-erp-bfcache-locked');
      }
    }

    restoreClaimed = false;
    restoreLocked = false;
  }

  function navigateToCurrentPage() {
    if (isNavigating || !window.location) {
      return;
    }

    isNavigating = true;
    window.location.href = window.location.href;
  }

  window.AppPageCacheGuard = {
    claimRestore: function () {
      restoreClaimed = true;
    },
    isLocked: function () {
      return restoreLocked;
    },
    unlock: unlockRestoredPage
  };

  window.addEventListener('pagehide', function (event) {
    if (event.persisted) {
      lockRestoredPage();
    }
  });

  window.addEventListener('pageshow', function (event) {
    if (!isBackForwardRestore(event)) {
      return;
    }

    restoreClaimed = false;
    lockRestoredPage();
    clearPasswordFields();
    window.dispatchEvent(new Event('erp:bfcache-restore'));

    if (!restoreClaimed) {
      navigateToCurrentPage();
    }
  });
})(window, document);
