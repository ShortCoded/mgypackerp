(function (window, document, $) {
  'use strict';

  const config = window.ErpUiShellConfig || {};
  let activeCard = null;

  function messages() {
    return config.messages || {};
  }

  function operationalToast() {
    const message = messages().operational_ready || '';

    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast('info', message);
      return;
    }

    if (window.Swal && typeof window.Swal.fire === 'function') {
      window.Swal.fire({ toast: true, icon: 'info', title: message, timer: 5000, showConfirmButton: false });
    }
  }

  function tableOptions(table) {
    const columns = (config.columns || []).map(function (column) {
      const normalized = Object.assign({}, column);

      if (column.data === 'select') {
        normalized.render = function () { return ''; };
        normalized.className = 'no-colvis text-center';
      }

      if (column.data === 'actions') {
        normalized.render = function () { return ''; };
        normalized.className = 'no-colvis text-center';
      }

      return normalized;
    });
    const overrides = {
      ajax: {
        url: table.getAttribute('data-url'),
        data: function (data) {
          const scope = document.getElementById('erp_ui_shell_scope_filter');
          data.scope = scope ? scope.value : 'active';
        }
      },
      columns: columns,
      processing: true,
      serverSide: true,
      order: [],
      responsive: true,
      autoWidth: false,
      deferRender: true,
      language: window.dataTableTranslations || {},
      columnDefs: [
        { targets: columns.map(function (column, index) { return column.className && column.className.indexOf('no-colvis') !== -1 ? index : null; }).filter(function (index) { return index !== null; }), visible: true }
      ],
      drawCallback: function () {
        if (window.AppDataTables) {
          window.AppDataTables.applyFalconEnhancements(document);
        }
      }
    };

    return window.AppDataTables && typeof window.AppDataTables.options === 'function'
      ? window.AppDataTables.options(overrides)
      : overrides;
  }

  function initTable() {
    const table = document.querySelector('.js-erp-ui-shell-table');

    if (!table || !$ || !$.fn || !$.fn.DataTable) {
      return null;
    }

    const dataTable = $(table).DataTable(tableOptions(table));
    const scope = document.getElementById('erp_ui_shell_scope_filter');

    if (scope) {
      scope.addEventListener('change', function () { dataTable.ajax.reload(); });
    }

    $(table).on('dblclick', 'tbody tr', function () {
      const row = dataTable.row(this).data();
      const mode = table.getAttribute('data-double-click-mode') || 'view';
      const template = mode === 'edit' ? config.editUrlTemplate : config.viewUrlTemplate;

      if (row && row.doc_num && template) {
        window.location.assign(String(template).replace('__DOC_NUM__', encodeURIComponent(row.doc_num)));
      }
    });

    document.querySelectorAll('.js-report-filters').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        dataTable.ajax.reload();
      });
    });

    return dataTable;
  }

  function directChild(element, selector) {
    if (!element) {
      return null;
    }

    return Array.from(element.children).find(function (child) { return child.matches(selector); }) || null;
  }

  function directChildren(element, selector) {
    return element ? Array.from(element.children).filter(function (child) { return child.matches(selector); }) : [];
  }

  function initializeControls(root) {
    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
      window.AppSelect2Ajax.init(root);
    }

    if (window.AppDatePicker && typeof window.AppDatePicker.init === 'function') {
      window.AppDatePicker.init(root);
    }

    if ($ && $.fn && $.fn.summernote) {
      $(root).find('.js-erp-ui-summernote').each(function () {
        if ($(this).next('.note-editor').length === 0) {
          $(this).summernote({
            height: 140,
            direction: document.documentElement.getAttribute('dir') || 'ltr',
            toolbar: [
              ['style', ['bold', 'italic', 'underline']],
              ['para', ['ul', 'ol']],
              ['insert', ['link']],
              ['view', ['codeview']]
            ]
          });
        }
      });
    }

    if (window.bootstrap && typeof window.bootstrap.Tooltip === 'function') {
      root.querySelectorAll('[data-bs-title]').forEach(function (element) {
        window.bootstrap.Tooltip.getOrCreateInstance(element);
      });
    }
  }

  function resetPluginMarkup(card) {
    card.querySelectorAll('.select2-container, .note-editor, .flatpickr-calendar').forEach(function (element) { element.remove(); });
    card.querySelectorAll('[data-select2-id], [data-date-picker-initialized]').forEach(function (element) {
      element.removeAttribute('data-select2-id');
      element.removeAttribute('data-date-picker-initialized');
    });
    card.querySelectorAll('select').forEach(function (select) {
      select.classList.remove('select2-hidden-accessible');
      select.removeAttribute('aria-hidden');
      select.removeAttribute('tabindex');

      if ($) {
        $(select).removeData('select2').removeData('select2AjaxInitialized');
      }
    });
  }

  function copyValues(source, target) {
    const sourceControls = source.querySelectorAll('input, textarea, select');
    const targetControls = target.querySelectorAll('input, textarea, select');

    sourceControls.forEach(function (sourceControl, index) {
      const targetControl = targetControls[index];

      if (!targetControl || sourceControl.type === 'file') {
        return;
      }

      if (sourceControl.type === 'checkbox' || sourceControl.type === 'radio') {
        targetControl.checked = sourceControl.checked;
        return;
      }

      targetControl.value = sourceControl.value;
    });
  }

  function fieldIdFromName(name) {
    return 'erp-ui-' + String(name || '').replace(/[^A-Za-z0-9_-]/g, '-');
  }

  function reindexRepeater(repeater) {
    const cardsContainer = directChild(repeater, '.js-erp-ui-repeater-cards');
    const cards = directChildren(cardsContainer, '.js-erp-ui-repeater-card');
    const prefix = repeater.getAttribute('data-repeater-prefix') || '';

    cards.forEach(function (card, index) {
      const oldIndex = card.getAttribute('data-card-index');
      const oldToken = prefix + '[' + oldIndex + ']';
      const newToken = prefix + '[' + index + ']';

      card.setAttribute('data-card-index', String(index));
      const number = card.querySelector('.js-erp-ui-card-number');

      if (number) {
        number.textContent = String(index + 1);
      }

      card.querySelectorAll('[name]').forEach(function (control) {
        const name = control.getAttribute('name') || '';

        if (oldToken && name.indexOf(oldToken) !== -1) {
          control.setAttribute('name', name.replace(oldToken, newToken));
        }

        const newName = control.getAttribute('name') || '';
        const id = fieldIdFromName(newName);
        const oldId = control.id;
        control.id = id;

        if (oldId) {
          card.querySelectorAll('label[for="' + oldId.replace(/"/g, '\\"') + '"]').forEach(function (label) {
            label.setAttribute('for', id);
          });
        }
      });

      card.querySelectorAll('.erp-ui-shell-repeater[data-repeater-prefix]').forEach(function (nested) {
        const nestedPrefix = nested.getAttribute('data-repeater-prefix') || '';

        if (oldToken && nestedPrefix.indexOf(oldToken) !== -1) {
          nested.setAttribute('data-repeater-prefix', nestedPrefix.replace(oldToken, newToken));
        }
      });

      card.querySelectorAll('.erp-ui-shell-repeater').forEach(function (nestedRepeater) {
        if (nestedRepeater.closest('.js-erp-ui-repeater-card') === card) {
          reindexRepeater(nestedRepeater);
        }
      });
    });

    const empty = directChild(repeater, '.js-erp-ui-repeater-empty');

    if (empty) {
      empty.classList.toggle('d-none', cards.length !== 0);
    }
  }

  function addCard(repeater, sourceCard) {
    const template = directChild(repeater, '.js-erp-ui-repeater-template');
    const cardsContainer = directChild(repeater, '.js-erp-ui-repeater-cards');

    if (!template || !cardsContainer) {
      return null;
    }

    const index = directChildren(cardsContainer, '.js-erp-ui-repeater-card').length;
    const indexToken = repeater.getAttribute('data-repeater-index-token') || '__INDEX__';
    const numberToken = repeater.getAttribute('data-repeater-number-token') || '__NUMBER__';
    const html = template.innerHTML
      .split(indexToken).join(String(index))
      .split(numberToken).join(String(index + 1));
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html.trim();
    const card = wrapper.firstElementChild;

    if (!card) {
      return null;
    }

    resetPluginMarkup(card);
    cardsContainer.appendChild(card);

    if (sourceCard) {
      copyValues(sourceCard, card);
    }

    reindexRepeater(repeater);
    initializeControls(card);
    activeCard = card;
    card.focus({ preventScroll: false });

    return card;
  }

  function removeCard(card) {
    if (!card) {
      return;
    }

    const repeater = card.closest('.erp-ui-shell-repeater');
    card.remove();
    activeCard = null;
    reindexRepeater(repeater);
  }

  function bindRepeaterEvents() {
    document.addEventListener('focusin', function (event) {
      const card = event.target.closest ? event.target.closest('.js-erp-ui-repeater-card') : null;

      if (card) {
        activeCard = card;
      }
    });

    document.addEventListener('click', function (event) {
      const addButton = event.target.closest ? event.target.closest('.js-erp-ui-add-card') : null;
      const duplicateButton = event.target.closest ? event.target.closest('.js-erp-ui-duplicate-card') : null;
      const deleteButton = event.target.closest ? event.target.closest('.js-erp-ui-delete-card') : null;

      if (addButton) {
        addCard(addButton.closest('.erp-ui-shell-repeater'));
      }

      if (duplicateButton) {
        const card = duplicateButton.closest('.js-erp-ui-repeater-card');
        addCard(card.closest('.erp-ui-shell-repeater'), card);
      }

      if (deleteButton) {
        removeCard(deleteButton.closest('.js-erp-ui-repeater-card'));
      }
    });
  }

  function isTypingTarget(target) {
    if (!target) {
      return false;
    }

    const tag = String(target.tagName || '').toLowerCase();

    return target.isContentEditable
      || ['input', 'textarea', 'select'].indexOf(tag) !== -1
      || !!(target.closest && target.closest('[contenteditable="true"], .select2-search__field, .note-editable, .flatpickr-calendar, .modal'));
  }

  function visibleAction(action) {
    return Array.from(document.querySelectorAll('[data-shortcut-action="' + action + '"]')).find(function (element) {
      return !element.disabled && element.getClientRects().length > 0;
    });
  }

  function activeRepeater() {
    if (activeCard && document.contains(activeCard)) {
      return activeCard.closest('.erp-ui-shell-repeater');
    }

    const activePane = document.querySelector('.tab-pane.active');

    return activePane ? activePane.querySelector('.erp-ui-shell-repeater') : null;
  }

  function bindShortcuts() {
    const digitActions = {
      '0': 'form.back',
      '1': 'form.save',
      '2': 'form.save_view',
      '3': 'form.save_edit',
      '4': 'form.save_back',
      '5': 'form.save_new',
      '6': 'form.save_clone',
      '9': 'form.delete'
    };

    document.addEventListener('keydown', function (event) {
      if (!event.altKey || event.ctrlKey || event.metaKey || isTypingTarget(event.target)) {
        return;
      }

      const key = String(event.key || '').toLowerCase();
      const code = String(event.code || '');
      const digit = code.match(/^(?:Digit|Numpad)([0-9])$/);

      if (digit && digitActions[digit[1]]) {
        const action = visibleAction(digitActions[digit[1]]);

        if (action) {
          event.preventDefault();
          action.click();
        }

        return;
      }

      if (key === 'n' && config.mode !== 'index') {
        const repeater = activeRepeater();

        if (repeater) {
          event.preventDefault();
          addCard(repeater);
        }
      }

      if (key === 'd' && activeCard) {
        event.preventDefault();
        addCard(activeCard.closest('.erp-ui-shell-repeater'), activeCard);
      }

      if ((key === 'delete' || code === 'Delete') && activeCard) {
        event.preventDefault();
        removeCard(activeCard);
      }
    });
  }

  function bindOperationalActions() {
    document.addEventListener('click', function (event) {
      const button = event.target.closest ? event.target.closest('.js-erp-ui-operational-action') : null;

      if (button) {
        event.preventDefault();
        operationalToast();
      }
    });

    document.querySelectorAll('.js-erp-ui-shell-form').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        operationalToast();
      });
    });
  }

  function init() {
    initTable();
    bindOperationalActions();
    bindRepeaterEvents();
    bindShortcuts();
    initializeControls(document);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document, window.jQuery);
