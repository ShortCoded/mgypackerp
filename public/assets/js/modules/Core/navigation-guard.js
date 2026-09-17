(function (window, document) {
  'use strict';

  var config = window.AppNavigationGuardConfig || {};
  var dirtyForms = new Set();
  var submissionTimers = new WeakMap();

  function formMethod(form) {
    return String(form.getAttribute('method') || 'get').toLowerCase();
  }

  function eligibleForm(form) {
    if (!form || formMethod(form) === 'get' || form.matches('[data-navigation-guard="off"]')) {
      return false;
    }

    if (form.closest('[data-operating-context-modal], [data-erp-mobile-navigation]')) {
      return false;
    }

    return Boolean(form.querySelector('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea, [contenteditable="true"]'));
  }

  function dispatchStateChange() {
    window.dispatchEvent(new CustomEvent('erp:unsaved-changes', {
      detail: { dirty: isDirty() }
    }));
  }

  function markDirty(form) {
    if (!eligibleForm(form)) {
      return;
    }

    form.removeAttribute('data-erp-navigation-submitting');
    dirtyForms.add(form);
    dispatchStateChange();
  }

  function markSaved(form) {
    if (!form) {
      dirtyForms.clear();
      dispatchStateChange();

      return;
    }

    var timer = submissionTimers.get(form);

    if (timer) {
      window.clearTimeout(timer);
      submissionTimers.delete(form);
    }

    form.removeAttribute('data-erp-navigation-submitting');
    dirtyForms.delete(form);
    dispatchStateChange();
  }

  function submissionStarted(form) {
    if (!dirtyForms.has(form)) {
      return;
    }

    form.setAttribute('data-erp-navigation-submitting', 'true');

    var previousTimer = submissionTimers.get(form);

    if (previousTimer) {
      window.clearTimeout(previousTimer);
    }

    submissionTimers.set(form, window.setTimeout(function () {
      submissionTimers.delete(form);

      if (document.contains(form)) {
        form.removeAttribute('data-erp-navigation-submitting');
        dispatchStateChange();
      }
    }, Number(config.submissionGraceMs || 15000)));
  }

  function submissionFailed(form) {
    if (!form || !dirtyForms.has(form)) {
      return;
    }

    var timer = submissionTimers.get(form);

    if (timer) {
      window.clearTimeout(timer);
      submissionTimers.delete(form);
    }

    form.removeAttribute('data-erp-navigation-submitting');
    dispatchStateChange();
  }

  function isDirty() {
    var dirty = false;

    dirtyForms.forEach(function (form) {
      if (!document.contains(form)) {
        dirtyForms.delete(form);
        return;
      }

      if (!form.hasAttribute('data-erp-navigation-submitting')) {
        dirty = true;
      }
    });

    return dirty;
  }

  function run(action) {
    if (isDirty() && !window.confirm(config.unsavedChangesMessage || 'You have unsaved changes. Leave this page?')) {
      return false;
    }

    dirtyForms.clear();
    dispatchStateChange();
    action();

    return true;
  }

  document.addEventListener('input', function (event) {
    if (event.isTrusted) {
      markDirty(event.target.closest('form'));
    }
  }, true);

  document.addEventListener('change', function (event) {
    if (event.isTrusted) {
      markDirty(event.target.closest('form'));
    }
  }, true);

  document.addEventListener('reset', function (event) {
    window.setTimeout(function () {
      markSaved(event.target);
    }, 0);
  });

  document.addEventListener('submit', function (event) {
    submissionStarted(event.target);
  });

  window.addEventListener('beforeunload', function (event) {
    if (!isDirty()) {
      return;
    }

    event.preventDefault();
    event.returnValue = '';
  });

  window.AppNavigationGuard = {
    isDirty: isDirty,
    markDirty: markDirty,
    markSaved: markSaved,
    run: run,
    submissionFailed: submissionFailed,
    submissionStarted: submissionStarted
  };
})(window, document);
