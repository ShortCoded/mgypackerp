(function (window, document) {
  'use strict';

  var allowedPositions = ['vertical', 'top', 'combo', 'double-top'];
  var defaultPosition = 'double-top';
  var cookieName = 'erp_navbar_position';
  var reloadGuardKey = 'erp_navbar_position_sync';

  function validPosition(value) {
    return allowedPositions.indexOf(value) !== -1 ? value : defaultPosition;
  }

  function storeCookie(value) {
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';

    document.cookie = cookieName + '=' + encodeURIComponent(validPosition(value))
      + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;
  }

  var serverPosition = validPosition(document.documentElement.dataset.navbarPosition);
  var storedPosition = validPosition(window.localStorage.getItem('navbarPosition'));

  if (storedPosition !== serverPosition) {
    storeCookie(storedPosition);

    if (window.sessionStorage.getItem(reloadGuardKey) !== storedPosition) {
      window.sessionStorage.setItem(reloadGuardKey, storedPosition);
      window.location.replace(window.location.href.split('#')[0]);
    }
  } else {
    window.sessionStorage.removeItem(reloadGuardKey);
  }

  document.addEventListener('change', function (event) {
    if (event.target && event.target.matches('[data-theme-control="navbarPosition"]')) {
      storeCookie(event.target.value);
    }
  }, true);

  document.addEventListener('click', function (event) {
    if (event.target && event.target.closest('[data-theme-control="reset"]')) {
      storeCookie(defaultPosition);
    }
  }, true);
})(window, document);
