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

  function showProductionRunBatchPreview(message, materials, productLabel) {
    const preview = document.querySelector('[data-production-run-batch-preview]');
    const submit = document.querySelector('[data-production-run-batch-submit]');
    if (!preview) return;
    preview.replaceChildren();
    preview.hidden = false;
    if (!Array.isArray(materials)) {
      preview.textContent = message || '';
      if (submit) submit.disabled = true;
      return;
    }

    const title = document.createElement('div');
    title.className = 'fw-semibold mb-2';
    title.textContent = message;
    preview.appendChild(title);
    const list = document.createElement('ul');
    list.className = 'mb-0 ps-3';
    materials.forEach(function (material) {
      const row = document.createElement('li');
      const line = `${productLabel || ui.batchMaterial}: ${material.product || ''} — ${material.quantity || ''} ${material.unit || ''}`.trim();
      const context = [material.finished_product, material.stage, material.run_number].filter(Boolean).join(' · ');
      row.textContent = context ? `${line} (${context})` : line;
      list.appendChild(row);
    });
    preview.appendChild(list);
    if (submit) submit.disabled = materials.length === 0;
  }

  function updateProductionRunBatchMode(enabled) {
    const form = document.querySelector('[data-inventory-movement-form]');
    const wrapper = form?.querySelector('[data-production-run-batch-wrapper]');
    const batchSelect = form?.querySelector('[data-production-run-batch]');
    const batchHelp = form?.querySelector('[data-production-run-batch-help]');
    const typeSelect = form?.querySelector('[data-movement-type]');
    const reason = form?.querySelector('[name="movement_reason"]');
    const lines = form?.querySelector('[data-inventory-lines-section]');
    if (!form || !batchSelect || !typeSelect || !lines) return;
    const documentType = String(typeSelect.value || '');
    const batchTypeAllowed = ['inventory_issue', 'inventory_receipt'].includes(documentType);
    enabled = enabled && batchTypeAllowed;
    if (wrapper) wrapper.hidden = !batchTypeAllowed;
    if (batchHelp) batchHelp.textContent = documentType === 'inventory_receipt'
      ? (ui.batchReceiptHelp || '')
      : (ui.batchIssueHelp || '');

    if (enabled) {
      if (form.dataset.productionBatchMode !== '1' && !Object.hasOwn(typeSelect.dataset, 'previousValue')) {
        typeSelect.dataset.previousValue = typeSelect.value;
      }
      if (form.dataset.productionBatchMode !== '1' && !Object.hasOwn(typeSelect.dataset, 'previousDisabled')) {
        typeSelect.dataset.previousDisabled = typeSelect.disabled ? '1' : '0';
      }
      $(typeSelect).prop('disabled', true).trigger('change.select2');
      let hiddenType = form.querySelector('[data-batch-document-type]');
      if (!hiddenType) {
        hiddenType = document.createElement('input');
        hiddenType.type = 'hidden';
        hiddenType.name = 'document_type';
        hiddenType.dataset.batchDocumentType = '1';
        form.appendChild(hiddenType);
      }
      hiddenType.value = documentType;
      if (reason) {
        if (!Object.hasOwn(reason.dataset, 'previousValue')) reason.dataset.previousValue = reason.value;
        reason.value = documentType === 'inventory_receipt' ? (ui.batchReceiptReason || '') : (ui.batchIssueReason || '');
      }
      if (form.dataset.productionBatchMode !== '1') {
        lines.hidden = true;
        lines.querySelectorAll('input, select, textarea, button').forEach(function (field) {
          field.dataset.batchWasDisabled = field.disabled ? '1' : '0';
          $(field).prop('disabled', true);
          if (field.tagName === 'SELECT') $(field).trigger('change.select2');
        });
      }
      form.dataset.productionBatchMode = '1';
    } else {
      if (form.dataset.productionBatchMode === '1') {
        form.querySelector('[data-batch-document-type]')?.remove();
        $(typeSelect).prop('disabled', typeSelect.dataset.previousDisabled === '1');
        if (Object.hasOwn(typeSelect.dataset, 'previousValue')) {
          $(typeSelect).val(typeSelect.dataset.previousValue).trigger('change');
          $(typeSelect).trigger('change.select2');
          delete typeSelect.dataset.previousValue;
          delete typeSelect.dataset.previousDisabled;
        }
        if (reason && Object.hasOwn(reason.dataset, 'previousValue')) {
          reason.value = reason.dataset.previousValue;
          delete reason.dataset.previousValue;
        }
        lines.hidden = false;
        lines.querySelectorAll('input, select, textarea, button').forEach(function (field) {
          $(field).prop('disabled', field.dataset.batchWasDisabled === '1');
          delete field.dataset.batchWasDisabled;
          if (field.tagName === 'SELECT') $(field).trigger('change.select2');
        });
      }
      form.dataset.productionBatchMode = '0';
    }

    form.querySelectorAll('[data-standard-movement-actions]').forEach(function (actions) { actions.hidden = enabled; });
    const submit = form.querySelector('[data-production-run-batch-submit]');
    if (submit) {
      submit.hidden = !enabled;
      submit.disabled = enabled;
      submit.textContent = documentType === 'inventory_receipt' ? (ui.batchReceiveAction || '') : (ui.batchIssueAction || '');
    }
    const preview = form.querySelector('[data-production-run-batch-preview]');
    if (!enabled && preview) {
      preview.hidden = true;
      preview.replaceChildren();
    }
  }

  function loadProductionRunBatch() {
    const form = document.querySelector('[data-inventory-movement-form]');
    const batchSelect = form?.querySelector('[data-production-run-batch]');
    const preview = form?.querySelector('[data-production-run-batch-preview]');
    if (!form || !batchSelect || !preview) return;
    const publicId = String(batchSelect.value || '');
    const documentType = String(form.querySelector('[data-movement-type]')?.value || '');
    updateProductionRunBatchMode(publicId !== '' && ['inventory_issue', 'inventory_receipt'].includes(documentType));
    if (!publicId) return;

    showProductionRunBatchPreview(ui.batchLoading || '', null);
    const detailsUrl = String(batchSelect.dataset.detailsUrl || '').replace('__BATCH_ID__', encodeURIComponent(publicId));
    const requestUrl = new URL(detailsUrl, window.location.origin);
    requestUrl.searchParams.set('document_type', documentType);
    $.getJSON(requestUrl.toString()).done(function (response) {
      const batch = response.data || {};
      const materials = documentType === 'inventory_receipt' ? batch.outputs : batch.materials;
      if (!Array.isArray(materials) || materials.length === 0) {
        showProductionRunBatchPreview(ui.batchEmpty || '', null);
        return;
      }
      const title = `${ui.batchTitle || ''} — ${batch.batch_number || ''} / ${ui.batchOrder || ''}: ${batch.order_number || ''}`;
      showProductionRunBatchPreview(title, materials, documentType === 'inventory_receipt' ? ui.batchFinishedProduct : ui.batchMaterial);
    }).fail(function () {
      showProductionRunBatchPreview(ui.batchLoadFailed || '', null);
    });
  }

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

    const batch = document.querySelector('[data-production-run-batch]');
    const batchTypeAllowed = ['inventory_issue', 'inventory_receipt'].includes(type);
    if (batch && !batchTypeAllowed && batch.value) {
      $(batch).val(null).trigger('change');
    } else {
      updateProductionRunBatchMode(Boolean(batch?.value) && batchTypeAllowed);
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
  $(document).on('change', '[data-production-run-batch]', loadProductionRunBatch);
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
    loadProductionRunBatch();
    initializeLineShortcuts();
  });
})(jQuery, window, document);
