(function (window, document) {
  'use strict';

  const selector = '.js-date-picker';
  const initializedAttribute = 'data-date-picker-initialized';
  const defaults = window.AppDatePicker || {};

  function optionsFor(input) {
    const locale = input.getAttribute('data-locale') || defaults.locale || document.documentElement.getAttribute('lang') || 'en';
    const direction = defaults.direction || document.documentElement.getAttribute('dir') || 'ltr';
    const enableTime = input.getAttribute('data-enable-time') === 'true' || input.getAttribute('data-enable-time') === '1';
    const minuteIncrement = Number.parseInt(input.getAttribute('data-minute-increment') || '5', 10);
    const displayFormat = input.getAttribute('data-date-format') || defaults.dateFormat || 'd/m/Y';
    const storageFormat = input.getAttribute('data-storage-format');
    const options = {
      allowInput: true,
      appendTo: document.body,
      dateFormat: storageFormat || displayFormat,
      disableMobile: true,
      enableTime: enableTime,
      locale: locale,
      minuteIncrement: Number.isNaN(minuteIncrement) ? 5 : minuteIncrement,
      position: direction === 'rtl' ? 'auto right' : 'auto left',
      static: false,
      time_24hr: input.getAttribute('data-time-24hr') === 'true' || input.getAttribute('data-time-24hr') === '1',
      onReady: function (selectedDates, dateStr, instance) {
        if (instance && instance.wrapper) {
          instance.wrapper.classList.add('erp-date-picker-wrapper');
        }

        if (instance && instance.calendarContainer) {
          instance.calendarContainer.classList.add('erp-date-picker-calendar');
        }
      },
      onOpen: function (selectedDates, dateStr, instance) {
        input.dispatchEvent(new CustomEvent('app:date-picker-open', {
          bubbles: true,
          detail: { instance: instance }
        }));
      },
      onClose: function (selectedDates, dateStr, instance) {
        input.dispatchEvent(new CustomEvent('app:date-picker-close', {
          bubbles: true,
          detail: { instance: instance }
        }));
      }
    };

    if (storageFormat) {
      options.altFormat = displayFormat;
      options.altInput = true;
      options.altInputClass = input.className + ' erp-date-picker-display';
    }

    const minDate = input.getAttribute('data-min-date') || input.getAttribute('min');
    const maxDate = input.getAttribute('data-max-date') || input.getAttribute('max');

    if (minDate) {
      options.minDate = minDate;
    }

    if (maxDate) {
      options.maxDate = maxDate;
    }

    if (window.flatpickr && window.flatpickr.l10ns && !window.flatpickr.l10ns[locale]) {
      delete options.locale;
    }

    return options;
  }

  function initInput(input) {
    if (!window.flatpickr || input.getAttribute(initializedAttribute) === 'true' || input.disabled || input.readOnly) {
      return;
    }

    window.flatpickr(input, optionsFor(input));
    input.setAttribute(initializedAttribute, 'true');
  }

  function init(root) {
    const scope = root && root.querySelectorAll ? root : document;

    scope.querySelectorAll(selector).forEach(initInput);
  }

  function clear(root) {
    const scope = root && root.querySelectorAll ? root : document;

    scope.querySelectorAll(selector).forEach(function (input) {
      if (input._flatpickr) {
        input._flatpickr.clear();
        return;
      }

      input.value = '';
    });
  }

  window.AppDatePicker = Object.assign({}, window.AppDatePicker || {}, {
    init: init,
    clear: clear
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }
})(window, document);
