(function ($, window, document) {
  'use strict';

  const inboundTypes = ['inventory_receipt', 'inventory_return', 'inventory_adjustment_in'];

  function jsonData(selector, fallback) {
    const element = document.querySelector(selector);
    try {
      return element ? JSON.parse(element.textContent || '') : fallback;
    } catch (error) {
      return fallback;
    }
  }

  const ui = jsonData('[data-inventory-movement-ui]', {});

  function setFieldVisibility(selector, visible, required) {
    const field = document.querySelector(selector);
    if (!field) {
      return;
    }

    field.hidden = !visible;
    field.querySelectorAll('input, select, textarea').forEach(function (input) {
      input.disabled = !visible;
      input.required = visible && required;
      if (!visible && input.tagName === 'SELECT') {
        $(input).val(null).trigger('change.select2');
      }
    });
  }

  function updateMovementFields() {
    const type = String($('[data-movement-type]').val() || '');
    const isTransfer = type === 'inventory_transfer';
    const isInbound = inboundTypes.includes(type);
    const usesDestinationStatus = isTransfer || isInbound || type === 'inventory_damage';
    const sourceLabel = document.querySelector('[data-source-store-label]');

    if (sourceLabel) {
      sourceLabel.childNodes[0].textContent = isTransfer ? (ui.sourceStore || '') : (ui.store || '');
    }

    setFieldVisibility('[data-destination-store-field]', isTransfer, isTransfer);
    setFieldVisibility('[data-source-status-field]', !isInbound, false);
    setFieldVisibility('[data-destination-status-field]', usesDestinationStatus, false);

    if (type === 'inventory_damage') {
      $('#inventory-destination-status').val('damaged').trigger('change.select2');
    } else if (usesDestinationStatus && !$('#inventory-destination-status').val()) {
      $('#inventory-destination-status').val('available').trigger('change.select2');
    }
  }

  function renumberLines() {
    document.querySelectorAll('[data-inventory-line]').forEach(function (row, index) {
      row.dataset.index = index;
      const number = row.querySelector('[data-row-number]');
      if (number) {
        number.textContent = index + 1;
      }
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/lines\[(?:\d+|__INDEX__)\]/, `lines[${index}]`);
      });
    });
  }

  function addLine(values, afterRow, focus) {
    const tableBody = document.querySelector('[data-inventory-lines] tbody');
    const template = document.getElementById('inventory-line-template');
    if (!tableBody || !(template instanceof HTMLTemplateElement)) {
      return null;
    }

    const fragment = template.content.cloneNode(true);
    if (afterRow && afterRow.parentElement === tableBody) {
      tableBody.insertBefore(fragment, afterRow.nextSibling);
    } else {
      tableBody.appendChild(fragment);
    }
    renumberLines();

    const row = afterRow && afterRow.parentElement === tableBody ? afterRow.nextElementSibling : tableBody.lastElementChild;
    if (row && values) {
      const product = row.querySelector('[name$="[product_doc_num]"]');
      if (product && values.product_doc_num) {
        product.appendChild(new Option(values.product_text || String(values.product_doc_num), values.product_doc_num, true, true));
      }
      ['quantity', 'batch_lot', 'manufacture_date', 'expiry_date', 'notes'].forEach(function (fieldName) {
        const field = row.querySelector(`[name$="[${fieldName}]"]`);
        if (field && values[fieldName] !== null && typeof values[fieldName] !== 'undefined') {
          field.value = values[fieldName];
        }
      });
    }
    if (row && window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
      window.AppSelect2Ajax.init(row);
    }
    if (row && window.AppDatePicker && typeof window.AppDatePicker.init === 'function') {
      window.AppDatePicker.init(row);
    }
    if (row && window.AppNumbers && typeof window.AppNumbers.refresh === 'function') {
      window.AppNumbers.refresh(row);
    }
    if (row && focus !== false) {
      row.querySelector('select:not([disabled]), input:not([type="hidden"]):not([disabled]), textarea:not([disabled])')?.focus();
    }

    return row;
  }

  function duplicateLine(row) {
    const values = {};
    ['quantity', 'batch_lot', 'manufacture_date', 'expiry_date', 'notes'].forEach(function (fieldName) {
      values[fieldName] = row.querySelector(`[name$="[${fieldName}]"]`)?.value || '';
    });
    addLine(values, row, true);
  }

  function removeLine(row) {
    const rows = document.querySelectorAll('[data-inventory-line]');
    if (rows.length === 1) {
      $(row).find('select').val(null).trigger('change');
      row.querySelectorAll('input:not([type="hidden"]), textarea').forEach(function (field) { field.value = ''; });
      return;
    }

    $(row).find('select.select2-hidden-accessible').each(function () { $(this).select2('destroy'); });
    row.remove();
    renumberLines();
  }

  function shortcutBlocked(target) {
    return $(target).hasClass('select2-search__field') || $('.select2-container--open').length > 0;
  }

  function isAltShortcut(event, codes, keyCodes, legacyKeys) {
    if (window.AppShortcuts && typeof window.AppShortcuts.isAltShortcut === 'function') {
      return !event.ctrlKey && window.AppShortcuts.isAltShortcut(event, codes, keyCodes, legacyKeys);
    }

    return event.altKey === true
      && !event.ctrlKey
      && !event.metaKey
      && !event.shiftKey
      && (codes.includes(event.code || '') || keyCodes.includes(Number(event.keyCode || event.which || 0)) || legacyKeys.includes(String(event.key || '').toLowerCase()));
  }

  function initializeLineShortcuts() {
    $(document).off('keydown.inventoryMovementLines').on('keydown.inventoryMovementLines', function (event) {
      if (!document.querySelector('[data-inventory-movement-form]') || shortcutBlocked(event.target)) {
        return;
      }

      const row = event.target.closest?.('[data-inventory-line]');
      const isDelete = event.altKey === true && !event.ctrlKey && !event.metaKey && !event.shiftKey
        && (event.key === 'Delete' || event.code === 'Delete' || Number(event.keyCode || event.which || 0) === 46);

      if (isDelete && row) {
        event.preventDefault();
        event.stopPropagation();
        removeLine(row);
        return;
      }

      if (isAltShortcut(event, ['KeyD'], [68], ['d']) && row) {
        event.preventDefault();
        event.stopPropagation();
        duplicateLine(row);
        return;
      }

      if (!isAltShortcut(event, ['KeyN'], [78], ['n'])) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      addLine({}, row || null, true);
    });
  }

  $(document).on('change select2:select', '[data-movement-type]', updateMovementFields);
  $(document).on('click', '[data-add-inventory-line]', function () {
    const activeRow = document.activeElement?.closest?.('[data-inventory-line]');
    addLine({}, activeRow, true);
  });
  $(document).on('click', '[data-duplicate-inventory-line]', function () {
    duplicateLine(this.closest('[data-inventory-line]'));
  });
  $(document).on('click', '[data-remove-inventory-line]', function () {
    removeLine(this.closest('[data-inventory-line]'));
  });

  $(function () {
    const initialLines = jsonData('[data-inventory-movement-lines]', []);
    (Array.isArray(initialLines) && initialLines.length ? initialLines : [{}]).forEach(function (line) {
      addLine(line, null, false);
    });
    updateMovementFields();
    initializeLineShortcuts();
  });
})(jQuery, window, document);
