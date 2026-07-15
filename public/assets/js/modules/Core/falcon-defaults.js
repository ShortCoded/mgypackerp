(function (window, document) {
  'use strict';

  var defaults = {
    theme: 'auto',
    isFluid: true,
    navbarPosition: 'double-top'
  };

  function getSystemTheme() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function initializeMissingPreferences() {
    Object.keys(defaults).forEach(function (key) {
      if (window.localStorage.getItem(key) === null) {
        window.localStorage.setItem(key, defaults[key]);
      }
    });
  }

  function syncConfigDefaults() {
    if (!window.CONFIG) {
      return;
    }

    Object.keys(defaults).forEach(function (key) {
      if (Object.prototype.hasOwnProperty.call(window.CONFIG, key)) {
        window.CONFIG[key] = defaults[key];
      }
    });
  }

  function applyThemeAttribute() {
    var theme = window.localStorage.getItem('theme') || defaults.theme;

    if (theme === 'auto') {
      document.documentElement.setAttribute('data-bs-theme', getSystemTheme());

      return;
    }

    if (theme === 'dark' || theme === 'light') {
      document.documentElement.setAttribute('data-bs-theme', theme);
    }
  }

  function apply() {
    initializeMissingPreferences();
    syncConfigDefaults();
    applyThemeAttribute();
  }

  window.ErpFalconDefaults = {
    defaults: defaults,
    apply: apply,
    initializeMissingPreferences: initializeMissingPreferences,
    syncConfigDefaults: syncConfigDefaults,
    applyThemeAttribute: applyThemeAttribute
  };

  apply();
})(window, document);
