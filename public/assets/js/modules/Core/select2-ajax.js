(function ($, window, document) {
  'use strict';

  const selector = '.js-select2-ajax, .js-select2-local';
  const initializedFlag = 'select2AjaxInitialized';
  const defaults = window.AppSelect2 || {};
  const selectedRequestCache = {};

  function boolValue(value, fallback) {
    if (value === undefined || value === null || value === '') {
      return fallback;
    }

    return value === true || value === 'true' || value === 1 || value === '1';
  }

  function intValue(value, fallback) {
    const parsed = Number.parseInt(String(value), 10);

    return Number.isNaN(parsed) ? fallback : parsed;
  }

  function normalizedValues(value) {
    const values = $.isArray(value) ? value : (value === undefined || value === null || value === '' ? [] : [value]);

    return values.map(function (item) {
      return String(item || '').trim();
    }).filter(function (item, index, self) {
      return item !== '' && self.indexOf(item) === index;
    });
  }

  function optionForValue($select, value) {
    const normalizedValue = String(value || '');

    return $select.find('option').filter(function () {
      return String(this.value) === normalizedValue;
    }).first();
  }

  function storeDependentValue($select, item, dependentResultField) {
    if (!dependentResultField || !item || item.id === undefined || item[dependentResultField] === undefined || item[dependentResultField] === null) {
      return;
    }

    optionForValue($select, item.id).attr('data-dependent-value', String(item[dependentResultField]));
  }

  function itemImageUrl(item) {
    if (item && item.imageUrl !== undefined && item.imageUrl !== null) {
      return String(item.imageUrl || '').trim();
    }

    if (item && item.element) {
      return String($(item.element).attr('data-image-url') || '').trim();
    }

    return '';
  }

  function storeImageUrl($select, item) {
    if (!item || item.id === undefined) {
      return;
    }

    const $option = optionForValue($select, item.id);

    if (!$option.length) {
      return;
    }

    if (item.imageUrl !== undefined && item.imageUrl !== null && String(item.imageUrl).trim() !== '') {
      $option.attr('data-image-url', String(item.imageUrl).trim());
      return;
    }

    if (Object.prototype.hasOwnProperty.call(item, 'imageUrl')) {
      $option.removeAttr('data-image-url');
    }
  }

  function storeItemMetadata($select, item, dependentResultField) {
    storeDependentValue($select, item, dependentResultField);
    storeImageUrl($select, item);
  }

  function usesProductImageTemplate($select) {
    const template = String($select.data('template') || '').trim();

    return template === 'product-image' || boolValue($select.data('has-image'), false);
  }

  function productImageTemplate(item, isSelection) {
    const text = item && item.text !== undefined && item.text !== null ? String(item.text) : '';
    const imageUrl = itemImageUrl(item);
    const $text = $('<span></span>').text(text);

    if (imageUrl === '') {
      return $text;
    }

    const size = isSelection ? '1.75rem' : '2.5rem';
    const $wrapper = $('<span></span>').css({
      alignItems: 'center',
      display: isSelection ? 'inline-flex' : 'flex',
      maxWidth: '100%',
      minWidth: 0
    });
    const $image = $('<img>')
      .attr('alt', '')
      .attr('src', imageUrl)
      .css({
        backgroundColor: '#fff',
        border: '1px solid rgba(0, 0, 0, .125)',
        borderRadius: '.375rem',
        flex: '0 0 auto',
        height: size,
        marginInlineEnd: isSelection ? '.375rem' : '.5rem',
        objectFit: 'cover',
        width: size
      })
      .on('error', function () {
        $(this).remove();
      });

    $text.css({
      minWidth: 0,
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: isSelection ? 'nowrap' : 'normal'
    });

    return $wrapper.append($image).append($text);
  }

  function templateOptions($select) {
    if (!usesProductImageTemplate($select)) {
      return {};
    }

    return {
      templateResult: function (item) {
        return productImageTemplate(item, false);
      },
      templateSelection: function (item) {
        return productImageTemplate(item, true);
      }
    };
  }

  function dependencyValues(dependsOn) {
    return normalizedValues($(dependsOn).val() || []);
  }

  function extraParams($select) {
    const params = $select.data('extra-params');

    if (!params || typeof params !== 'object') {
      return {};
    }

    return params;
  }

  function extraParamValue(selector) {
    if (selector === undefined || selector === null) {
      return '';
    }

    const value = String(selector);

    if (value.charAt(0) !== '#' && value.charAt(0) !== '.' && value.charAt(0) !== '[') {
      return value;
    }

    return $(value).val() || '';
  }

  function updateDependencyDisabled($select, dependsOn) {
    if (!dependsOn || !boolValue($select.data('disable-when-dependency-empty'), false)) {
      return;
    }

    const disabled = dependencyValues(dependsOn).length === 0;

    if ($select.prop('disabled') !== disabled) {
      $select.prop('disabled', disabled).trigger('change.select2');
    }
  }

  function syncDependentSelection($select, dependsOn) {
    if (!dependsOn) {
      return;
    }

    const preserve = boolValue($select.data('preserve-dependent-values'), false);

    if (!preserve) {
      $select.val(null).trigger('change.select2');
      updateDependencyDisabled($select, dependsOn);
      return;
    }

    const allowedDependencies = dependencyValues(dependsOn);
    const currentValues = normalizedValues($select.val() || []);
    const keptValues = currentValues.filter(function (value) {
      const dependentValue = String(optionForValue($select, value).attr('data-dependent-value') || '').trim();

      return dependentValue !== '' && allowedDependencies.indexOf(dependentValue) !== -1;
    });

    if (keptValues.length !== currentValues.length || keptValues.some(function (value, index) { return value !== currentValues[index]; })) {
      $select.val(keptValues.length > 0 ? keptValues : null).trigger('change.select2');
    }

    updateDependencyDisabled($select, dependsOn);
  }

  function language($select) {
    const messages = defaults.messages || {};
    const noResults = $select.data('no-results');

    return {
      errorLoading: function () { return messages.errorLoading || ''; },
      inputTooShort: function () { return messages.inputTooShort || ''; },
      loadingMore: function () { return messages.loadingMore || ''; },
      noResults: function () { return noResults || messages.noResults || ''; },
      removeAllItems: function () { return defaults.clearAllLabel || ''; },
      removeItem: function () { return defaults.clearAllLabel || ''; },
      search: function () { return messages.searching || ''; },
      searching: function () { return messages.searching || ''; }
    };
  }

  function dropdownParent($select) {
    const $modal = $select.closest('.modal');

    if ($modal.length > 0) {
      return $modal;
    }

    return $(document.body);
  }

  function initSelect(select) {
    const $select = $(select);

    if ($select.data(initializedFlag) || !$.fn.select2) {
      return;
    }

    const url = $select.data('url');
    const perPage = intValue($select.data('per-page'), intValue(defaults.perPage, 25));
    const delay = intValue($select.data('delay'), intValue(defaults.delay, 250));
    const minimumInputLength = intValue($select.data('minimum-input-length'), intValue(defaults.minimumInputLength, 0));
    const allowClear = boolValue($select.data('allow-clear'), true);
    const isMultiple = select.multiple || boolValue($select.data('multiple'), false);
    const dependsOn = $select.data('depends-on');
    const dependentParam = $select.data('dependent-param');
    const dependentResultField = $select.data('dependent-result-field');

    const options = {
      allowClear: allowClear,
      closeOnSelect: !isMultiple,
      dir: document.documentElement.getAttribute('dir') || 'ltr',
      dropdownParent: dropdownParent($select),
      language: language($select),
      minimumInputLength: minimumInputLength,
      placeholder: $select.data('placeholder') || '',
      theme: 'bootstrap-5',
      width: '100%'
    };

    if (url) {
      options.ajax = {
        url: url,
        delay: delay,
        dataType: 'json',
        data: function (params) {
          const data = {
            q: params.term || '',
            term: params.term || '',
            page: params.page || 1,
            per_page: perPage
          };

          if (dependsOn && dependentParam) {
            data[dependentParam] = $(dependsOn).val() || [];
          }

          Object.keys(extraParams($select)).forEach(function (param) {
            data[param] = extraParamValue(extraParams($select)[param]);
          });

          return data;
        },
        processResults: function (data, params) {
          params.page = params.page || 1;

          return {
            results: data && data.results ? data.results : [],
            pagination: {
              more: !!(data && data.pagination && data.pagination.more)
            }
          };
        }
      };
    }

    $select.select2(Object.assign(options, templateOptions($select)));

    $select.data(initializedFlag, true);
    $select.on('select2:select.select2AjaxDependencyData', function (event) {
      storeItemMetadata($select, event.params ? event.params.data : null, dependentResultField);
    });

    if (dependsOn) {
      const dependencyNamespace = String(select.id || $select.attr('name') || Math.random()).replace(/[^A-Za-z0-9_]/g, '_');

      $(document).off('change.select2AjaxDependency.' + dependencyNamespace, dependsOn).on('change.select2AjaxDependency.' + dependencyNamespace, dependsOn, function () {
        syncDependentSelection($select, dependsOn);
      });

      updateDependencyDisabled($select, dependsOn);
    }

    hydrateSelectedOptions($select);
  }

  function hydrateSelectedOptions($select) {
    const selectedUrl = $select.data('selected-url');

    if (!selectedUrl || $select.data('select2SelectedHydrated')) {
      return;
    }

    $select.data('select2SelectedHydrated', true);
    $select.data('select2HydratingSelected', true);

    selectedRequest(selectedUrl).done(function (data) {
      const selectedKey = $select.data('selected-key');
      const results = selectedKey
        ? (data && data[selectedKey] ? [data[selectedKey]] : [])
        : (data && data.results ? data.results : []);

      results.forEach(function (item) {
        if (!item || item.id === undefined || item.text === undefined) {
          return;
        }

        const value = String(item.id);
        const exists = $select.find('option').filter(function () {
          return String(this.value) === value;
        }).length > 0;

        if (!exists) {
          $select.append(new Option(String(item.text), value, true, true));
        }

        storeItemMetadata($select, item, $select.data('dependent-result-field'));
      });

      $select.trigger('change');
    }).always(function () {
      $select.data('select2HydratingSelected', false);
    });
  }

  function selectedRequest(url) {
    if (!selectedRequestCache[url]) {
      selectedRequestCache[url] = $.ajax({
        url: url,
        dataType: 'json'
      });
    }

    return selectedRequestCache[url];
  }

  function init(root) {
    const scope = root && root.querySelectorAll ? root : document;

    scope.querySelectorAll(selector).forEach(function (element) {
      const form = element.closest('[data-price-list-form]');
      if (form && form.getAttribute('data-mode') === 'view') {
        return;
      }
      initSelect(element);
    });
  }

  window.AppSelect2Ajax = Object.assign({}, window.AppSelect2Ajax || {}, {
    init: init
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }
})(jQuery, window, document);
