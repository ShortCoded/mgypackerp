(function ($, window, document) {
  'use strict';

  const messages = window.purchaseOrderMessages || {};
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
  const selectedDocNums = new Set();
  let purchaseOrderTable = null;

  function msg(key, fallback) {
    return messages[key] || fallback || key;
  }

  function headers() {
    return {
      'X-CSRF-TOKEN': csrfToken,
      Accept: 'application/json'
    };
  }

  function number(value) {
    return window.AppNumbers.number(value, 0);
  }

  function formatAmount(value) {
    const fixed = (Math.round((number(value) + Number.EPSILON) * 10000) / 10000).toFixed(4);

    return window.AppNumbers.format(fixed.replace(/\.?0+$/, '') || '0');
  }

  function formatQuantity(value) {
    const fixed = (Math.round((number(value) + Number.EPSILON) * 100000000) / 100000000).toFixed(8);

    return window.AppNumbers.format(fixed.replace(/\.?0+$/, '') || '0');
  }

  function showToast(icon, title) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, title);
      return;
    }

    if (window.Swal) {
      window.Swal.fire({ icon: icon, text: title, toast: true, position: 'top-end', timer: 2400, showConfirmButton: false, heightAuto: false });
    }
  }

  function confirmDialog(options) {
    if (!window.Swal) {
      return $.Deferred().resolve({ isConfirmed: window.confirm(options.title || '') }).promise();
    }

    return window.Swal.fire({
      icon: options.icon || 'warning',
      title: options.title || '',
      text: options.text || '',
      input: options.input || undefined,
      inputPlaceholder: options.inputPlaceholder || undefined,
      inputValidator: options.inputValidator || undefined,
      showCloseButton: true,
      showCancelButton: true,
      focusCancel: !options.input,
      allowEscapeKey: true,
      confirmButtonText: options.confirmButtonText || msg('confirm_yes', 'Confirm'),
      cancelButtonText: options.cancelButtonText || msg('cancel', 'Cancel'),
      confirmButtonColor: options.confirmButtonColor || '#d33',
      cancelButtonColor: '#748194',
      heightAuto: false
    });
  }

  function bracketName(field) {
    const parts = String(field || '').split('.');

    if (parts.length < 2) {
      return field;
    }

    return parts.shift() + parts.map(function (part) {
      return '[' + part + ']';
    }).join('');
  }

  function alertElement($form) {
    let $alert = $form.find('.js-form-alert').first();

    if ($alert.length === 0) {
      $alert = $('<div class="alert alert-danger d-none js-form-alert"><div class="js-form-alert-message"></div></div>');
      $form.prepend($alert);
    }

    return $alert;
  }

  function clearFormErrors($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('.select2-selection.is-invalid').removeClass('is-invalid');
    $form.find('[data-error-for]').text('');
    alertElement($form)
      .addClass('d-none')
      .removeClass('alert-warning alert-success alert-info')
      .addClass('alert-danger')
      .find('.js-form-alert-message')
      .empty();
  }

  function validationMessages(errors) {
    const result = [];

    Object.keys(errors || {}).forEach(function (field) {
      const values = $.isArray(errors[field]) ? errors[field] : [errors[field]];
      values.forEach(function (message) {
        if (message) {
          result.push(message);
        }
      });
    });

    return result;
  }

  function select2Selection($field) {
    return $field.next('.select2-container').find('.select2-selection');
  }

  function showValidationErrors($form, errors) {
    const $alert = alertElement($form);
    const $list = $('<ul class="mb-0 ps-3"></ul>');

    clearFormErrors($form);
    validationMessages(errors).forEach(function (message) {
      $list.append($('<li></li>').text(message));
    });

    $alert
      .removeClass('d-none alert-warning alert-success alert-info')
      .addClass('alert-danger')
      .find('.js-form-alert-message')
      .empty()
      .append($list.children().length ? $list : $('<span></span>').text(msg('validation_failed', 'Validation failed.')));

    Object.keys(errors || {}).forEach(function (field) {
      const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
      const bracket = bracketName(field);
      const normalized = field.replace(/\.\d+$/, '').replace(/\.\d+\./g, '.');
      const base = normalized.split('.')[0];
      const $input = $form.find('[name="' + bracket + '"], [name="' + field + '"], [name="' + normalized + '"], [name="' + base + '"]');

      $input.addClass('is-invalid');
      $input.filter('select').each(function () {
        select2Selection($(this)).addClass('is-invalid');
      });
      $form.find('[data-error-for="' + field + '"], [data-error-for="' + normalized + '"], [data-error-for="' + base + '"]').text(message || '');
    });
  }

  function initSelect2(root) {
    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
      window.AppSelect2Ajax.init(root || document);
    }
  }

  function initDatePickers(root) {
    if (window.AppDatePicker && typeof window.AppDatePicker.init === 'function') {
      window.AppDatePicker.init(root || document);
    }
  }

  function columnName(column) {
    const map = {
      doc_num: 'purchase_orders.doc_number',
      document_date: 'purchase_orders.document_date',
      supplier: 'suppliers.name',
      branch_store: 'branch_stores.name',
      currency: 'currencies.code',
      total_ordered_quantity: 'purchase_orders.total_ordered_quantity',
      total_amount: 'purchase_orders.total_amount',
      expected_delivery_date: 'purchase_orders.expected_delivery_date',
      status: 'purchase_orders.status',
      created_by: 'created_users.name',
      created_at: 'purchase_orders.created_at',
      approved_by: 'approved_users.name',
      approved_at: 'purchase_orders.approved_at'
    };

    return map[column] || column;
  }

  function columnClass(column, index) {
    if (index === 0) {
      return 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control';
    }

    if (column === 'status') {
      return 'align-middle white-space-nowrap dt-status';
    }

    if (['document_date', 'expected_delivery_date', 'created_at', 'approved_at'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-date';
    }

    if (['total_ordered_quantity', 'total_amount'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-number text-end';
    }

    return 'align-middle white-space-nowrap dt-text dt-ellipsis';
  }

  function tableColumns() {
    const configured = window.purchaseOrderColumns || [];
    const columns = [
      { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' }
    ];

    configured.forEach(function (column, index) {
      columns.push({
        data: column,
        name: columnName(column),
        className: columnClass(column, index),
        responsivePriority: index < 2 ? 2 + index : 10 + index
      });
    });

    columns.push({ data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions', responsivePriority: 3 });

    return columns;
  }

  function trashFilterValue() {
    const value = String($('#purchase_orders_trash_filter').val() || 'active');

    return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
  }

  function syncBulkUi() {
    const selectedCount = selectedDocNums.size;
    const $bar = $('#bulk_actions_bar');
    const $button = $('#bulk_action_apply');

    $bar.toggleClass('d-none', selectedCount === 0).toggleClass('d-flex', selectedCount > 0);
    $('#bulk_selected_count').text(selectedCount);
    $button.prop('disabled', selectedCount === 0);
    $button.find('span:last').text(($button.data('label') || '') + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));

    if (selectedCount === 0) {
      $('#bulk_action_select').val('');
    }
  }

  function refreshTable() {
    if (purchaseOrderTable) {
      purchaseOrderTable.ajax.reload(null, false);
    }
  }

  function initDataTable() {
    const $table = $('.js-purchase-orders-table');

    if ($table.length === 0 || !$.fn.DataTable) {
      return;
    }

    purchaseOrderTable = $table.DataTable({
      processing: true,
      serverSide: true,
      responsive: true,
      ajax: {
        url: $table.data('url'),
        data: function (data) {
          data.trash_filter = trashFilterValue();
        }
      },
      columns: tableColumns(),
      order: [[1, 'desc']],
      language: window.dataTableTranslations || {},
      drawCallback: function () {
        selectedDocNums.clear();
        $('.js-record-select-all').prop('checked', false);
        syncBulkUi();
      }
    });
  }

  function rowAction($button) {
    const fallbackAction = $button.data('delete-url') ? 'delete' : ($button.data('restore-url') ? 'restore' : '');
    const action = String($button.data('action') || fallbackAction);
    const method = String($button.data('method') || (action === 'delete' ? 'DELETE' : (action === 'restore' ? 'PATCH' : 'POST')));
    const url = String($button.data('url') || $button.data('delete-url') || $button.data('restore-url') || '');
    const options = {
      title: msg(action + '_confirm_title', msg('confirm_title', 'Are you sure?')),
      text: msg(action + '_confirm_text', ''),
      icon: action === 'delete' || action === 'cancel' ? 'warning' : 'question',
      confirmButtonText: msg(action + '_confirm_button', msg('confirm_yes', 'Confirm')),
      confirmButtonColor: action === 'delete' || action === 'cancel' ? '#d33' : '#2c7be5'
    };

    if (action === 'cancel') {
      options.input = 'textarea';
      options.inputPlaceholder = msg('cancel_reason_placeholder', '');
      options.inputValidator = function (value) {
        return String(value || '').trim() === '' ? msg('cancel_reason_required', 'Required') : undefined;
      };
    }

    confirmDialog(options).done(function (result) {
      if (!result.isConfirmed) {
        return;
      }

      $.ajax({
        url: url,
        method: method,
        headers: headers(),
        data: action === 'cancel' ? { cancel_reason: result.value } : {}
      }).done(function (response) {
        showToast('success', response.message || msg('saved', 'Saved successfully.'));

        if ($button.data('redirect-url')) {
          window.location.assign($button.data('redirect-url'));
          return;
        }

        refreshTable();
      }).fail(function (xhr) {
        const response = xhr.responseJSON || {};

        showToast('error', response.message || msg('unexpected_error', 'Something went wrong.'));
      });
    });
  }

  function parseUnitOptions(data) {
    if (!data) {
      return [];
    }

    if ($.isArray(data.unit_options)) {
      return data.unit_options;
    }

    if (data.element) {
      const raw = $(data.element).attr('data-unit-options') || '';

      if (raw) {
        try {
          return JSON.parse(raw);
        } catch (error) {
          return [];
        }
      }
    }

    return [];
  }

  function populateUnits($row, options, selected) {
    const $unit = $row.find('.js-purchase-order-unit');
    const selectedValue = String(selected || $unit.val() || '');

    $unit.empty();
    (options || []).forEach(function (option) {
      const value = String(option.id || '');
      const text = String(option.text || value);

      if (value !== '') {
        $unit.append(new Option(text, value, value === selectedValue, value === selectedValue));
      }
    });

    if (!selectedValue && options && options.length > 0) {
      $unit.val(String(options[0].id || ''));
    }

    $unit.trigger('change');
  }

  function calculateTotals() {
    let totalOrdered = 0;
    let totalReceived = 0;
    let totalRemaining = 0;
    let totalAmount = number($('#freight_amount').val() || $('#freight_amount').text() || '0');

    $('.js-purchase-order-line').each(function () {
      const $row = $(this);
      const quantity = number($row.find('.js-line-quantity').val() || $row.find('td').eq(3).text());
      const price = number($row.find('.js-line-unit-price').val() || $row.find('td').eq(4).text());
      const discountType = String($row.find('.js-line-discount-type').val() || 'fixed');
      const discountValue = number($row.find('.js-line-discount-value').val() || '0');
      const taxRate = Math.min(100, Math.max(0, number($row.find('.js-line-tax-rate').val() || '0')));
      const received = number($row.find('.js-line-received').text());
      const remaining = Math.max(0, quantity - received);
      const subtotal = quantity * price;
      const discountAmount = discountType === 'percentage'
        ? subtotal * Math.min(100, Math.max(0, discountValue)) / 100
        : Math.min(subtotal, Math.max(0, discountValue));
      const taxableAmount = Math.max(0, subtotal - discountAmount);
      const lineTotal = taxableAmount + taxableAmount * taxRate / 100;

      $row.find('.js-line-total').text(formatAmount(lineTotal));
      $row.find('.js-line-remaining').text(formatQuantity(remaining));

      totalOrdered += quantity;
      totalReceived += received;
      totalRemaining += remaining;
      totalAmount += lineTotal;
    });

    $('.js-total-ordered').text(formatQuantity(totalOrdered));
    $('.js-total-received').text(formatQuantity(totalReceived));
    $('.js-total-remaining').text(formatQuantity(totalRemaining));
    $('.js-total-amount').text(formatAmount(totalAmount));
  }

  function reindexLines() {
    $('.js-purchase-order-line').each(function (index) {
      const $row = $(this);

      $row.find('.js-line-number').text(index + 1);
      $row.find('[name]').each(function () {
        this.name = String(this.name).replace(/lines\[\d+\]/, 'lines[' + index + ']');
      });
      $row.find('[data-error-for]').each(function () {
        $(this).attr('data-error-for', String($(this).attr('data-error-for')).replace(/lines\.\d+\./, 'lines.' + index + '.'));
      });
    });
  }

  function addLine() {
    const template = document.getElementById('purchase-order-line-template');

    if (!template) {
      return;
    }

    const index = $('.js-purchase-order-line').length;
    const html = template.innerHTML.replace(/__INDEX__/g, String(index)).replace(/__NUMBER__/g, String(index + 1));
    const $row = $(html);

    $('.js-purchase-order-lines').append($row);
    initSelect2($row[0]);
    calculateTotals();
  }

  function removeLine($button) {
    if ($('.js-purchase-order-line').length <= 1) {
      $button.closest('.js-purchase-order-line').find('input, select').val('').trigger('change');
      calculateTotals();
      return;
    }

    $button.closest('.js-purchase-order-line').remove();
    reindexLines();
    calculateTotals();
  }

  function initExistingUnits() {
    $('.js-purchase-order-line').each(function () {
      const $row = $(this);
      const option = $row.find('.js-purchase-order-product option:selected')[0];

      if (!option) {
        return;
      }

      const unitOptions = parseUnitOptions({ element: option });

      if (unitOptions.length > 0) {
        populateUnits($row, unitOptions, $row.find('.js-purchase-order-unit').val());
      }
    });
  }

  function submitForm($form, $submitter) {
    const $button = $submitter && $submitter.length ? $submitter : $form.find('[type="submit"]').first();

    clearFormErrors($form);
    $button.prop('disabled', true);

    $.ajax({
      url: $form.attr('action'),
      method: ($form.find('[name="_method"]').val() || $form.attr('method') || 'POST').toUpperCase(),
      headers: headers(),
      data: $form.serialize()
    }).done(function (response) {
      showToast('success', response.message || msg('saved', 'Saved successfully.'));

      if (response.redirect) {
        window.location.assign(response.redirect);
        return;
      }

      if (response.reset_form) {
        $form[0].reset();
        $form.find('.js-select2-ajax').val(null).trigger('change');
        $('.js-purchase-order-line').slice(1).remove();
        $('.js-purchase-order-line').find('input, select').val('').trigger('change');
        reindexLines();
        calculateTotals();
      }
    }).fail(function (xhr) {
      const response = xhr.responseJSON || {};

      if (xhr.status === 422 && response.errors) {
        showValidationErrors($form, response.errors);
        return;
      }

      alertElement($form)
        .removeClass('d-none alert-warning alert-success alert-info')
        .addClass('alert-danger')
        .find('.js-form-alert-message')
        .text(response.message || msg('unexpected_error', 'Something went wrong.'));
    }).always(function () {
      $button.prop('disabled', false);
    });
  }

  function submitSettings($form) {
    clearFormErrors($form);

    $.ajax({
      url: $form.attr('action'),
      method: 'PUT',
      headers: headers(),
      data: $form.serialize()
    }).done(function (response) {
      showToast('success', response.message || msg('saved', 'Saved successfully.'));
    }).fail(function (xhr) {
      const response = xhr.responseJSON || {};

      if (xhr.status === 422 && response.errors) {
        showValidationErrors($form, response.errors);
        return;
      }

      alertElement($form)
        .removeClass('d-none alert-warning alert-success alert-info')
        .addClass('alert-danger')
        .find('.js-form-alert-message')
        .text(response.message || msg('unexpected_error', 'Something went wrong.'));
    });
  }

  $(function () {
    initDataTable();
    initSelect2(document);
    initDatePickers(document);
    initExistingUnits();
    calculateTotals();

    $(document).on('change', '#purchase_orders_trash_filter', refreshTable);

    $(document).on('change', '.js-record-select', function () {
      const value = String($(this).val() || '');

      if (this.checked) {
        selectedDocNums.add(value);
      } else {
        selectedDocNums.delete(value);
      }

      syncBulkUi();
    });

    $(document).on('change', '.js-record-select-all', function () {
      const checked = this.checked;

      $('.js-record-select').each(function () {
        this.checked = checked;
        $(this).trigger('change');
      });
    });

    $(document).on('click', '#bulk_action_apply', function () {
      const action = String($('#bulk_action_select').val() || '');
      const $table = $('.js-purchase-orders-table');

      if (action !== 'delete' || selectedDocNums.size === 0) {
        return;
      }

      confirmDialog({
        title: msg('delete_confirm_title', ''),
        text: msg('bulk_delete_confirm_text', ''),
        confirmButtonText: msg('delete_confirm_button', '')
      }).done(function (result) {
        if (!result.isConfirmed) {
          return;
        }

        $.ajax({
          url: $table.data('bulk-delete-url'),
          method: 'DELETE',
          headers: headers(),
          data: { doc_nums: Array.from(selectedDocNums) }
        }).done(function (response) {
          showToast('success', response.message || msg('deleted', 'Deleted successfully.'));
          refreshTable();
        }).fail(function (xhr) {
          const response = xhr.responseJSON || {};

          showToast('error', response.message || msg('unexpected_error', 'Something went wrong.'));
        });
      });
    });

    $(document).on('click', '.js-purchase-order-row-action, .js-delete-record, .js-restore-record', function () {
      rowAction($(this));
    });

    $(document).on('click', '.js-purchase-order-add-line', addLine);

    $(document).on('click', '.js-purchase-order-remove-line', function () {
      removeLine($(this));
    });

    $(document).on('select2:select', '.js-purchase-order-product', function (event) {
      const $row = $(this).closest('.js-purchase-order-line');
      const data = event.params ? event.params.data : {};
      const options = parseUnitOptions(data);

      populateUnits($row, options, data.unitDocNum);
    });

    $(document).on('select2:clear change', '.js-purchase-order-product', function () {
      if ($(this).val()) {
        return;
      }

      populateUnits($(this).closest('.js-purchase-order-line'), [], null);
    });

    $(document).on('input change', '#freight_amount, .js-line-quantity, .js-line-unit-price, .js-line-discount-type, .js-line-discount-value, .js-line-tax-rate', calculateTotals);

    $(document).on('click', '.js-finance-submit-action', function () {
      $(this).closest('form').find('[name="submit_action"]').val($(this).data('submit-action') || 'save');
    });

    $(document).on('submit', '.js-purchase-order-form', function (event) {
      event.preventDefault();
      submitForm($(this), $(document.activeElement));
    });

    $(document).on('submit', '.js-purchase-order-document-number-settings-form', function (event) {
      event.preventDefault();
      submitSettings($(this));
    });
  });
})(jQuery, window, document);
