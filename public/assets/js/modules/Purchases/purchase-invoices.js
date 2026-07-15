(function ($, window, document) {
  'use strict';

  const messages = window.purchaseInvoiceMessages || {};
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
  let purchaseInvoiceTable = null;
  const selectedDocNums = new Set();

  function msg(key, fallback) {
    return messages[key] || fallback || key;
  }

  function headers() {
    return {
      'X-CSRF-TOKEN': csrfToken,
      Accept: 'application/json'
    };
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
      $form.find('.card-body, .modal-body').first().prepend($alert);
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

  function setLoading($button, loading) {
    $button.prop('disabled', loading);
    $('body').css('cursor', loading ? 'wait' : '');
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

  function number(value) {
    const parsed = parseFloat(String(value || '0').replace(/,/g, ''));

    return Number.isFinite(parsed) ? parsed : 0;
  }

  function formatAmount(value) {
    const fixed = (Math.round((number(value) + Number.EPSILON) * 10000) / 10000).toFixed(4);

    return fixed.replace(/\.?0+$/, '') || '0';
  }

  function discountAmount(type, value, base) {
    const discount = number(value);

    if (String(type || '') === 'percentage') {
      return Math.min(base, Math.max(0, base * (discount / 100)));
    }

    return Math.min(base, Math.max(0, discount));
  }

  function columnName(column) {
    const map = {
      doc_num: 'purchase_invoices.doc_number',
      invoice_date: 'purchase_invoices.invoice_date',
      supplier: 'suppliers.name',
      financial_period: 'financial_periods.from_date',
      supplier_invoice_number: 'purchase_invoices.supplier_invoice_number',
      payment_type: 'purchase_invoices.payment_type',
      status: 'purchase_invoices.status',
      payment_status: 'purchase_invoices.payment_status',
      currency: 'currencies.code',
      total_amount: 'purchase_invoices.total_amount',
      paid_amount: 'purchase_invoices.paid_amount',
      remaining_amount: 'purchase_invoices.remaining_amount',
      created_by: 'created_users.name',
      created_at: 'purchase_invoices.created_at',
      approved_by: 'approved_users.name',
      approved_at: 'purchase_invoices.approved_at'
    };

    return map[column] || column;
  }

  function columnClass(column, index) {
    if (index === 0) {
      return 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control';
    }

    if (['status', 'payment_status'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-status';
    }

    if (['invoice_date', 'created_at', 'approved_at'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-date';
    }

    if (['total_amount', 'paid_amount', 'remaining_amount'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap text-end';
    }

    return 'align-middle white-space-nowrap dt-text dt-ellipsis';
  }

  function tableColumns() {
    const configured = window.purchaseInvoiceColumns || [];
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
    const value = String($('#purchase_invoices_trash_filter').val() || 'active');

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

  function checkboxDocNum(checkbox) {
    return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
  }

  function pageCheckboxes(api) {
    if (api && typeof api.rows === 'function') {
      return $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select');
    }

    return $('.js-purchase-invoices-table').find('tbody input.js-record-select');
  }

  function restoreSelectionState(api) {
    pageCheckboxes(api).each(function () {
      const docNum = checkboxDocNum(this);
      $(this).prop('checked', docNum !== '' && selectedDocNums.has(docNum));
    });

    syncBulkUi();
  }

  function initTable() {
    const $table = $('.js-purchase-invoices-table').first();

    if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
      return;
    }

    const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
      ? window.AppDataTables.options
      : function (options) {
        return options;
      };

    purchaseInvoiceTable = $table.DataTable(dataTableOptions({
      processing: true,
      serverSide: true,
      responsive: true,
      stateSave: true,
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
        restoreSelectionState(purchaseInvoiceTable);
      }
    }));

    $table.on('change', 'input.js-record-select', function () {
      const docNum = checkboxDocNum(this);

      if (docNum === '') {
        return;
      }

      if (this.checked) {
        selectedDocNums.add(docNum);
      } else {
        selectedDocNums.delete(docNum);
      }

      syncBulkUi();
    });

    $('#select_all_records').on('change', function () {
      pageCheckboxes(purchaseInvoiceTable).prop('checked', this.checked).trigger('change');
    });

    $('#bulk_action_apply').on('click', function () {
      const action = String($('#bulk_action_select').val() || '');

      if (action !== 'delete' || selectedDocNums.size === 0) {
        return;
      }

      confirmDialog({
        title: msg('confirm_delete_title', 'Delete selected records?'),
        text: msg('confirm_delete_text', ''),
        confirmButtonText: msg('delete', 'Delete')
      }).then(function (result) {
        if (!result.isConfirmed) {
          return;
        }

        $.ajax({
          url: $table.data('bulk-delete-url'),
          method: 'DELETE',
          headers: headers(),
          data: { doc_nums: Array.from(selectedDocNums) }
        }).done(function (response) {
          selectedDocNums.clear();
          syncBulkUi();
          purchaseInvoiceTable.ajax.reload(null, false);
          showToast('success', response.message || msg('deleted', 'Deleted.'));
        }).fail(function (xhr) {
          showToast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpected_error', 'Something went wrong.'));
        });
      });
    });
  }

  function actionOptions(action) {
    const map = {
      approve: {
        title: msg('approve_confirm_title', 'Approve invoice?'),
        text: msg('approve_confirm_text', ''),
        confirmButtonText: msg('approve', 'Approve'),
        confirmButtonColor: '#00a65a'
      },
      close: {
        title: msg('close_confirm_title', 'Close invoice?'),
        text: msg('close_confirm_text', ''),
        confirmButtonText: msg('close', 'Close'),
        confirmButtonColor: '#2c7be5'
      },
      cancel: {
        title: msg('cancel_confirm_title', 'Cancel invoice?'),
        text: msg('cancel_confirm_text', ''),
        confirmButtonText: msg('cancel_invoice', 'Cancel invoice'),
        input: 'textarea',
        inputPlaceholder: msg('cancel_reason_placeholder', 'Cancellation reason'),
        inputValidator: function (value) {
          if (!String(value || '').trim()) {
            return msg('cancel_reason_required', 'Cancellation reason is required.');
          }

          return undefined;
        }
      },
      delete: {
        title: msg('delete_confirm_title', 'Delete invoice?'),
        text: msg('delete_confirm_text', ''),
        confirmButtonText: msg('delete', 'Delete')
      },
      restore: {
        title: msg('restore_confirm_title', 'Restore invoice?'),
        text: msg('restore_confirm_text', ''),
        confirmButtonText: msg('restore', 'Restore'),
        confirmButtonColor: '#00a65a'
      }
    };

    return map[action] || {
      title: msg('confirm_title', 'Confirm action?'),
      confirmButtonText: msg('confirm_yes', 'Confirm')
    };
  }

  function runWorkflowAction($button) {
    const action = String($button.data('action') || '');
    const method = String($button.data('method') || 'POST');
    const url = String($button.data('url') || '');
    const options = actionOptions(action);

    if (url === '') {
      return;
    }

    confirmDialog(options).then(function (result) {
      if (!result.isConfirmed) {
        return;
      }

      const payload = {};

      if (action === 'cancel') {
        payload.cancel_reason = result.value;
      }

      setLoading($button, true);
      $.ajax({
        url: url,
        method: method,
        headers: headers(),
        data: payload
      }).done(function (response) {
        showToast('success', response.message || msg('saved', 'Saved.'));

        if (purchaseInvoiceTable) {
          purchaseInvoiceTable.ajax.reload(null, false);
          return;
        }

        window.location.reload();
      }).fail(function (xhr) {
        showToast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpected_error', 'Something went wrong.'));
      }).always(function () {
        setLoading($button, false);
      });
    });
  }

  function cleanSelect2($root) {
    $root.find('.select2-container').remove();
    $root.find('select').each(function () {
      $(this)
        .removeClass('select2-hidden-accessible')
        .removeAttr('data-select2-id')
        .removeAttr('aria-hidden')
        .removeAttr('tabindex');
      $(this).find('option').removeAttr('data-select2-id');
    });
  }

  function renumberRows($rows, prefix) {
    $rows.each(function (index) {
      const $row = $(this);
      const namePattern = new RegExp(prefix + '\\[\\d+\\]', 'g');
      const errorPattern = new RegExp(prefix + '\\.\\d+\\.', 'g');

      $row.attr('data-index', index);
      $row.find('[name]').each(function () {
        this.name = this.name.replace(namePattern, prefix + '[' + index + ']');
      });
      $row.find('[data-error-for]').each(function () {
        const value = String($(this).attr('data-error-for') || '');
        $(this).attr('data-error-for', value.replace(errorPattern, prefix + '.' + index + '.'));
      });
    });
  }

  function parseUnitOptions(data) {
    if (data && $.isArray(data.unit_options)) {
      return data.unit_options;
    }

    if (data && $.isArray(data.unitOptions)) {
      return data.unitOptions;
    }

    const $element = data && data.element ? $(data.element) : $();
    const raw = $element.attr('data-unit-options');

    if (raw) {
      try {
        return JSON.parse(raw);
      } catch (e) {
        return [];
      }
    }

    return [];
  }

  function populateUnitSelect($row, options, selected) {
    const $unit = $row.find('.js-purchase-invoice-unit');
    const current = selected || $unit.val();

    $unit.empty();
    (options || []).forEach(function (option) {
      const $option = $('<option></option>').attr('value', option.id).text(option.text);
      if (String(option.id) === String(current)) {
        $option.prop('selected', true);
      }
      $unit.append($option);
    });

    if (!$unit.val() && options && options.length) {
      $unit.val(options[0].id);
    }
  }

  function calculateLine($row) {
    const qty = number($row.find('.js-purchase-invoice-quantity').val());
    const price = number($row.find('.js-purchase-invoice-unit-price').val());
    const subtotal = qty * price;
    const lineDiscount = discountAmount($row.find('.js-purchase-invoice-discount-type').val(), $row.find('.js-purchase-invoice-discount-value').val(), subtotal);
    const totalBeforeTax = Math.max(0, subtotal - lineDiscount);
    const tax = totalBeforeTax * (number($row.find('.js-purchase-invoice-tax-rate').val()) / 100);
    const total = totalBeforeTax + tax;

    $row.find('.js-purchase-invoice-line-subtotal').text(formatAmount(subtotal));
    $row.find('.js-purchase-invoice-line-discount').text(formatAmount(lineDiscount));
    $row.find('.js-purchase-invoice-line-tax').text(formatAmount(tax));
    $row.find('.js-purchase-invoice-line-total').text(formatAmount(total));

    return {
      subtotal: subtotal,
      lineDiscount: lineDiscount,
      taxable: totalBeforeTax,
      tax: tax,
      total: total
    };
  }

  function calculateTotals($form) {
    let subtotal = 0;
    let lineDiscount = 0;
    let taxableBeforeHeader = 0;
    let tax = 0;

    $form.find('.js-purchase-invoice-line').each(function () {
      const line = calculateLine($(this));
      subtotal += line.subtotal;
      lineDiscount += line.lineDiscount;
      taxableBeforeHeader += line.taxable;
      tax += line.tax;
    });

    const headerDiscount = discountAmount($form.find('.js-purchase-invoice-header-discount-type').val(), $form.find('.js-purchase-invoice-header-discount-value').val(), taxableBeforeHeader);
    const taxable = Math.max(0, taxableBeforeHeader - headerDiscount);
    const total = taxable + tax;
    const paid = number($form.find('.js-purchase-invoice-paid').text());
    const scheduleTotal = calculateScheduleTotals($form, total);

    $form.find('.js-purchase-invoice-subtotal').text(formatAmount(subtotal));
    $form.find('.js-purchase-invoice-line-discounts').text(formatAmount(lineDiscount));
    $form.find('.js-purchase-invoice-header-discount').text(formatAmount(headerDiscount));
    $form.find('.js-purchase-invoice-taxable').text(formatAmount(taxable));
    $form.find('.js-purchase-invoice-tax').text(formatAmount(tax));
    $form.find('.js-purchase-invoice-total').text(formatAmount(total));
    $form.find('.js-purchase-invoice-remaining').text(formatAmount(Math.max(0, total - paid)));
    $form.find('.js-purchase-invoice-schedule-total').text(formatAmount(scheduleTotal));
    $form.find('.js-purchase-invoice-schedule-difference').text(formatAmount(total - scheduleTotal));
  }

  function calculateScheduleTotals($form) {
    let scheduleTotal = 0;

    $form.find('.js-purchase-invoice-schedule-amount').each(function () {
      scheduleTotal += number($(this).val());
    });

    return scheduleTotal;
  }

  function updateHeaderPaymentSource($form) {
    const paymentType = String($form.find('.js-purchase-invoice-payment-type').val() || '');
    const sourceType = String($form.find('.js-purchase-invoice-source-type').val() || '');
    const showImmediateSource = paymentType === 'cash';

    $form.find('.js-purchase-invoice-header-cashbox').toggle(showImmediateSource && sourceType === 'cashbox');
    $form.find('.js-purchase-invoice-header-bank').toggle(showImmediateSource && sourceType === 'bank');
  }

  function updateScheduleSource($row) {
    const sourceType = String($row.find('.js-purchase-invoice-schedule-source').val() || 'scheduled');
    const isCashbox = sourceType === 'cashbox';
    const isBank = sourceType === 'bank';

    $row.find('.js-purchase-invoice-schedule-cashbox').closest('td').toggle(isCashbox);
    $row.find('.js-purchase-invoice-schedule-bank').closest('td').toggle(isBank);
    $row.find('.js-purchase-invoice-payment-date').closest('td').toggle(isCashbox || isBank);
  }

  function initRepeaterRow($row) {
    cleanSelect2($row);
    initSelect2($row);
    initDatePickers($row);
    updateScheduleSource($row);
  }

  function resetLineRow($row) {
    cleanSelect2($row);
    $row.find('input[type="hidden"]').val('');
    $row.find('input').not('[type="hidden"]').val('');
    $row.find('.js-purchase-invoice-discount-value, .js-purchase-invoice-tax-rate').val('0');
    $row.find('select.js-purchase-invoice-product').empty();
    $row.find('select.js-purchase-invoice-unit').empty();
    $row.find('select.js-purchase-invoice-discount-type').val('fixed');
    $row.find('.js-purchase-invoice-line-subtotal, .js-purchase-invoice-line-discount, .js-purchase-invoice-line-tax, .js-purchase-invoice-line-total').text('0');
    initRepeaterRow($row);
  }

  function resetScheduleRow($row) {
    cleanSelect2($row);
    $row.find('input[type="hidden"]').val('');
    $row.find('input').not('[type="hidden"]').val('');
    $row.find('select.js-purchase-invoice-schedule-source').val('scheduled');
    $row.find('select.js-purchase-invoice-schedule-cashbox, select.js-purchase-invoice-schedule-bank').empty();
    $row.find('.js-purchase-invoice-linked-voucher-cell').html('<span class="text-600">' + msg('empty_value', '-') + '</span>');
    initRepeaterRow($row);
  }

  function addLine($form, duplicate) {
    const $tbody = $form.find('.js-purchase-invoice-lines tbody');
    const $source = $tbody.find('.js-purchase-invoice-line').last();
    const $row = $source.clone(false, false);

    cleanSelect2($row);
    $tbody.append($row);
    if (!duplicate) {
      resetLineRow($row);
    } else {
      $row.find('input[type="hidden"]').val('');
      initRepeaterRow($row);
    }
    renumberRows($tbody.find('.js-purchase-invoice-line'), 'lines');
    calculateTotals($form);
  }

  function addSchedule($form, duplicate) {
    const $tbody = $form.find('.js-purchase-invoice-schedules tbody');
    const $source = $tbody.find('.js-purchase-invoice-schedule').last();
    const $row = $source.clone(false, false);

    cleanSelect2($row);
    $tbody.append($row);
    if (!duplicate) {
      resetScheduleRow($row);
    } else {
      $row.find('input[type="hidden"]').val('');
      $row.find('.js-purchase-invoice-linked-voucher-cell').html('<span class="text-600">' + msg('empty_value', '-') + '</span>');
      initRepeaterRow($row);
    }
    renumberRows($tbody.find('.js-purchase-invoice-schedule'), 'payment_schedules');
    calculateTotals($form);
  }

  function removeLine($form, $row) {
    const $rows = $form.find('.js-purchase-invoice-line');

    if ($rows.length <= 1) {
      resetLineRow($row);
    } else {
      $row.remove();
      renumberRows($form.find('.js-purchase-invoice-line'), 'lines');
    }

    calculateTotals($form);
  }

  function removeSchedule($form, $row) {
    const $rows = $form.find('.js-purchase-invoice-schedule');

    if ($rows.length <= 1) {
      resetScheduleRow($row);
    } else {
      $row.remove();
      renumberRows($form.find('.js-purchase-invoice-schedule'), 'payment_schedules');
    }

    calculateTotals($form);
  }

  function handleSaveResponse($form, response) {
    if (response.redirect) {
      window.location.href = response.redirect;
      return;
    }

    showToast('success', response.message || msg('saved', 'Saved.'));

    if (response.reset_form) {
      $form[0].reset();
      $form.find('select').val(null).trigger('change');
      $form.find('.js-purchase-invoice-line').each(function (index) {
        if (index === 0) {
          resetLineRow($(this));
        } else {
          $(this).remove();
        }
      });
      $form.find('.js-purchase-invoice-schedule').each(function (index) {
        if (index === 0) {
          resetScheduleRow($(this));
        } else {
          $(this).remove();
        }
      });
      calculateTotals($form);
      return;
    }

    if (response.data && response.data.urls && response.data.urls.edit) {
      window.location.href = response.data.urls.edit;
    }
  }

  function initForm() {
    const $form = $('.js-purchase-invoice-form').first();

    if ($form.length === 0) {
      return;
    }

    initSelect2($form);
    initDatePickers($form);

    if (String($form.data('readonly') || '0') === '1') {
      return;
    }

    updateHeaderPaymentSource($form);
    $form.find('.js-purchase-invoice-schedule').each(function () {
      updateScheduleSource($(this));
    });
    calculateTotals($form);

    $form.on('select2:select', '.js-purchase-invoice-product', function (event) {
      const data = event.params && event.params.data ? event.params.data : null;
      const $row = $(this).closest('.js-purchase-invoice-line');
      const options = parseUnitOptions(data);

      populateUnitSelect($row, options);
    });

    $form.on('change input', '.js-purchase-invoice-line-number, .js-purchase-invoice-discount-type, .js-purchase-invoice-header-discount-type, .js-purchase-invoice-header-discount-value, .js-purchase-invoice-schedule-amount', function () {
      calculateTotals($form);
    });

    $form.on('change', '.js-purchase-invoice-payment-type, .js-purchase-invoice-source-type', function () {
      updateHeaderPaymentSource($form);
    });

    $form.on('change', '.js-purchase-invoice-schedule-source', function () {
      updateScheduleSource($(this).closest('.js-purchase-invoice-schedule'));
    });

    $form.on('click', '.js-purchase-invoice-add-line', function () {
      addLine($form, false);
    });

    $form.on('click', '.js-purchase-invoice-duplicate-line', function () {
      const $row = $(this).closest('.js-purchase-invoice-line');
      const $clone = $row.clone(false, false);
      cleanSelect2($clone);
      $clone.find('input[type="hidden"]').val('');
      $row.after($clone);
      initRepeaterRow($clone);
      renumberRows($form.find('.js-purchase-invoice-line'), 'lines');
      calculateTotals($form);
    });

    $form.on('click', '.js-purchase-invoice-remove-line', function () {
      removeLine($form, $(this).closest('.js-purchase-invoice-line'));
    });

    $form.on('click', '.js-purchase-invoice-add-schedule', function () {
      addSchedule($form, false);
    });

    $form.on('click', '.js-purchase-invoice-duplicate-schedule', function () {
      const $row = $(this).closest('.js-purchase-invoice-schedule');
      const $clone = $row.clone(false, false);
      cleanSelect2($clone);
      $clone.find('input[type="hidden"]').val('');
      $clone.find('.js-purchase-invoice-linked-voucher-cell').html('<span class="text-600">' + msg('empty_value', '-') + '</span>');
      $row.after($clone);
      initRepeaterRow($clone);
      renumberRows($form.find('.js-purchase-invoice-schedule'), 'payment_schedules');
      calculateTotals($form);
    });

    $form.on('click', '.js-purchase-invoice-remove-schedule', function () {
      removeSchedule($form, $(this).closest('.js-purchase-invoice-schedule'));
    });

    $form.on('click', '.js-purchase-invoice-save', function () {
      const action = String($(this).data('submit-action') || 'save');
      $form.find('input[name="submit_action"]').val(action);
    });

    $form.on('submit', function (event) {
      event.preventDefault();

      const $submit = $form.find('.js-purchase-invoice-save[type="submit"]').filter(':focus').first();
      const $button = $submit.length ? $submit : $form.find('.js-purchase-invoice-save').first();
      const method = $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST';

      clearFormErrors($form);
      setLoading($button, true);
      $.ajax({
        url: $form.attr('action'),
        method: method,
        headers: headers(),
        data: $form.serialize()
      }).done(function (response) {
        handleSaveResponse($form, response);
      }).fail(function (xhr) {
        if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
          showValidationErrors($form, xhr.responseJSON.errors);
        } else {
          alertElement($form)
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-form-alert-message')
            .text((xhr.responseJSON && xhr.responseJSON.message) || msg('unexpected_error', 'Something went wrong.'));
        }
      }).always(function () {
        setLoading($button, false);
      });
    });
  }

  function initDocumentSettings() {
    $('.js-purchase-invoice-document-number-settings-form').on('submit', function (event) {
      event.preventDefault();

      const $form = $(this);
      const $button = $form.find('button[type="submit"]');

      clearFormErrors($form);
      setLoading($button, true);
      $.ajax({
        url: $form.attr('action'),
        method: 'POST',
        headers: headers(),
        data: $form.serialize()
      }).done(function (response) {
        showToast('success', response.message || msg('saved', 'Saved.'));
      }).fail(function (xhr) {
        if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
          showValidationErrors($form, xhr.responseJSON.errors);
        } else {
          showToast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpected_error', 'Something went wrong.'));
        }
      }).always(function () {
        setLoading($button, false);
      });
    });
  }

  $(function () {
    initSelect2(document);
    initDatePickers(document);
    initTable();
    initForm();
    initDocumentSettings();

    $(document).on('click', '.js-purchase-invoice-row-action, .js-purchase-invoice-action', function () {
      runWorkflowAction($(this));
    });
  });
})(jQuery, window, document);
