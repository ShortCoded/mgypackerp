(function (window, document) {
  'use strict';

  const selector = '.js-user-phone-contact';
  let activeTrigger = null;
  let globalListenersBound = false;

  function popoverInstance(element) {
    if (!window.bootstrap || !window.bootstrap.Popover) {
      return null;
    }

    return window.bootstrap.Popover.getOrCreateInstance(element, {
      container: 'body',
      html: true,
      sanitize: true,
      trigger: 'click'
    });
  }

  function hideActive(except) {
    if (!activeTrigger || activeTrigger === except) {
      return;
    }

    const instance = popoverInstance(activeTrigger);

    if (instance) {
      instance.hide();
    }

    activeTrigger = null;
  }

  function initElement(element) {
    if (element.dataset.contactActionsInitialized === 'true' || !popoverInstance(element)) {
      return;
    }

    element.dataset.contactActionsInitialized = 'true';

    element.addEventListener('show.bs.popover', function () {
      hideActive(element);
    });

    element.addEventListener('shown.bs.popover', function () {
      activeTrigger = element;
    });

    element.addEventListener('hidden.bs.popover', function () {
      if (activeTrigger === element) {
        activeTrigger = null;
      }
    });
  }

  function activePopoverElement() {
    if (!activeTrigger) {
      return null;
    }

    const popoverId = activeTrigger.getAttribute('aria-describedby');

    return popoverId ? document.getElementById(popoverId) : null;
  }

  function bindGlobalListeners() {
    if (globalListenersBound) {
      return;
    }

    globalListenersBound = true;

    document.addEventListener('click', function (event) {
      if (!activeTrigger) {
        return;
      }

      const target = event.target;
      const popover = activePopoverElement();

      if (activeTrigger.contains(target) || (popover && popover.contains(target))) {
        return;
      }

      hideActive();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        hideActive();
      }
    });
  }

  function init(root) {
    if (!window.bootstrap || !window.bootstrap.Popover) {
      return;
    }

    const scope = root && root.querySelectorAll ? root : document;

    scope.querySelectorAll(selector).forEach(initElement);
    bindGlobalListeners();
  }

  window.AppContactActions = Object.assign({}, window.AppContactActions || {}, {
    init: init,
    hideActive: hideActive
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }
})(window, document);
