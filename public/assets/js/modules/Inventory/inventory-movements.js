(function ($, window, document) {
  'use strict';

  const inboundTypes = ['inventory_receipt', 'inventory_return', 'inventory_adjustment_in'];
  const ui = window.inventoryMovementUi || {};

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
    const isInbound = inboundTypes.indexOf(type) !== -1;
    const usesDestinationStatus = isTransfer || isInbound || type === 'inventory_damage';
    const sourceLabel = document.querySelector('[data-source-store-label]');

    if (sourceLabel) {
      sourceLabel.textContent = isTransfer ? (ui.sourceStore || 'Source store') : (ui.store || 'Store');
    }

    setFieldVisibility('[data-destination-store-field]', isTransfer, isTransfer);
    setFieldVisibility('[data-source-status-field]', !isInbound, false);
    setFieldVisibility('[data-destination-status-field]', usesDestinationStatus, false);

    if (type === 'inventory_damage') {
      $('#inventory-destination-status').val('damaged').trigger('change.select2');
    } else if (usesDestinationStatus && !$('#inventory-destination-status').val()) {
      $('#inventory-destination-status').val('available').trigger('change.select2');
    }

    const requiresCost = isInbound;
    $('[data-unit-cost]').each(function () {
      this.required = requiresCost;
      $(this).closest('[data-cost-cell]').prop('hidden', !requiresCost);
    });
    $('[data-cost-heading]').prop('hidden', !requiresCost);
  }

  function renumberLines() {
    $('[data-inventory-line]').each(function (index) {
      $(this).find('[name]').each(function () {
        this.name = this.name.replace(/lines\[\d+\]/, 'lines[' + index + ']');
      });
    });
  }

  function addLine(values) {
    const tableBody = document.querySelector('[data-inventory-lines] tbody');
    const template = document.getElementById('inventory-line-template');
    if (!tableBody || !template) {
      return;
    }

    const index = tableBody.querySelectorAll('[data-inventory-line]').length;
    const fragment = template.content.cloneNode(true);
    fragment.querySelectorAll('[name]').forEach(function (field) {
      field.name = field.name.replace('__INDEX__', index);
    });
    tableBody.appendChild(fragment);

    const row = tableBody.lastElementChild;
    if (row && values) {
      const product = row.querySelector('[name$="[product_id]"]');
      if (product && values.product_id) {
        product.appendChild(new Option(values.product_text || String(values.product_id), values.product_id, true, true));
        $(product).trigger('change.select2');
      }
      ['quantity', 'batch_lot', 'unit_cost', 'manufacture_date', 'expiry_date', 'notes'].forEach(function (fieldName) {
        const field = row.querySelector('[name$="[' + fieldName + ']"]');
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
    updateMovementFields();
  }

  $(document).on('change select2:select', '[data-movement-type]', updateMovementFields);
  $(document).on('click', '[data-add-inventory-line]', function () {
    addLine();
  });
  $(document).on('click', '[data-remove-inventory-line]', function () {
    const rows = document.querySelectorAll('[data-inventory-line]');
    if (rows.length === 1) {
      $(rows[0]).find('input').val('');
      $(rows[0]).find('select').val(null).trigger('change');
      return;
    }
    this.closest('[data-inventory-line]').remove();
    renumberLines();
  });

  $(function () {
    const initialLines = Array.isArray(window.inventoryMovementLines) ? window.inventoryMovementLines : [];
    if (initialLines.length) {
      initialLines.forEach(addLine);
    } else {
      addLine();
    }
    updateMovementFields();
  });
})(jQuery, window, document);
