(function (document) {
  'use strict';

  function disableNativeValidation(root) {
    if (root instanceof HTMLFormElement) {
      root.noValidate = true;
    }

    if (root.querySelectorAll) {
      root.querySelectorAll('form').forEach(function (form) {
        form.noValidate = true;
      });
    }
  }

  disableNativeValidation(document);
  new MutationObserver(function (mutations) {
    mutations.forEach(function (mutation) {
      mutation.addedNodes.forEach(function (node) {
        if (node.nodeType === Node.ELEMENT_NODE) {
          disableNativeValidation(node);
        }
      });
    });
  }).observe(document.documentElement, { childList: true, subtree: true });
})(document);
