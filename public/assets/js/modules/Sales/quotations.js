(function ($, window, document) {
  'use strict';

  const messages = window.quotationMessages || {};
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
  let quotationTable = null;
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
      window.Swal.fire({ icon: icon, text: title, toast: true, position: 'top-end', timer: 2200, showConfirmButton: false, heightAuto: false });
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
      showCloseButton: true,
      showCancelButton: true,
      focusCancel: true,
      allowEscapeKey: true,
      confirmButtonText: options.confirmButtonText || msg('confirmYes', 'Confirm'),
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

  function dotName(name) {
    return String(name || '').replace(/\]/g, '').replace(/\[/g, '.');
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
      .append($list.length ? $list : $('<span></span>').text(msg('validationFailed', 'Validation failed.')));

    Object.keys(errors || {}).forEach(function (field) {
      const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
      const bracket = bracketName(field);
      const normalized = field.replace(/\.\d+$/, '').replace(/\.\d+\./g, '.');
      const base = normalized.split('.')[0];
      const $input = $form.find('[name="' + bracket + '"], [name="' + bracket + '[]"], [name="' + field + '"], [name="' + normalized + '"], [name="' + base + '"], [name="' + base + '[]"]');

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

  function columnName(column) {
    const map = {
      doc_num: 'quotations.doc_number',
      customer: 'customers.name',
      subject_project: 'quotations.subject',
      quotation_type: 'quotations.quotation_type',
      current_revision: 'current_revisions.revision_number',
      status: 'quotations.status',
      currency: 'currencies.code',
      total: 'current_revisions.total',
      quotation_date: 'quotations.quotation_date',
      valid_until: 'quotations.valid_until',
      created_by: 'created_users.name',
      updated_by: 'updated_users.name'
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

    if (['total'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-number text-end';
    }

    if (['quotation_date', 'valid_until'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-date';
    }

    return 'align-middle white-space-nowrap dt-text dt-ellipsis';
  }

  function tableColumns() {
    const configured = window.quotationColumns || [];
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
    const value = String($('#quotations_trash_filter').val() || 'active');

    return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
  }

  function syncBulkUi() {
    const selectedCount = selectedDocNums.size;
    const restoreMode = trashFilterValue() === 'trashed';
    const $bar = $('#bulk_actions_bar');
    const $select = $('#bulk_action_select');
    const $button = $('#bulk_action_apply');

    $bar.toggleClass('d-none', selectedCount === 0).toggleClass('d-flex', selectedCount > 0);
    $('#bulk_selected_count').text(selectedCount);
    $select.find('option').first().val(restoreMode ? 'restore' : 'delete').text(restoreMode ? msg('restore', 'Restore') : msg('delete', 'Delete'));
    $button
      .toggleClass('btn-falcon-danger', !restoreMode)
      .toggleClass('btn-falcon-success', restoreMode)
      .prop('disabled', selectedCount === 0)
      .find('span:last')
      .text(($button.data('label') || '') + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));
  }

  function pageCheckboxes(api) {
    if (api && typeof api.rows === 'function') {
      return $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select');
    }

    return $('.js-quotations-table').find('tbody input.js-record-select');
  }

  function checkboxDocNum(checkbox) {
    return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
  }

  function restoreSelectionState(api) {
    pageCheckboxes(api).each(function () {
      const docNum = checkboxDocNum(this);
      $(this).prop('checked', docNum !== '' && selectedDocNums.has(docNum));
    });

    syncBulkUi();
  }

  function clearSelection(api) {
    selectedDocNums.clear();
    pageCheckboxes(api).prop('checked', false);
    $('#select_all_records').prop('checked', false).prop('indeterminate', false);
    syncBulkUi();
  }

  function initTable() {
    const $table = $('.js-quotations-table').first();

    if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
      return;
    }

    const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
      ? window.AppDataTables.options
      : function (options) { return options; };
    const protectedColumns = [0, 1, -1];

    quotationTable = $table.DataTable(dataTableOptions({
      processing: true,
      serverSide: true,
      stateSave: true,
      stateLoadParams: function (settings, data) {
        if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
          window.AppDataTables.protectStateColumns(data, protectedColumns);
        }
      },
      stateSaveParams: function (settings, data) {
        if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
          window.AppDataTables.protectStateColumns(data, protectedColumns);
        }
      },
      ajax: {
        url: $table.data('url'),
        data: function (data) {
          data.trash_filter = trashFilterValue();
          const filters = document.getElementById('quotation-filter-form');
          if (filters) new FormData(filters).forEach((value, key) => {data[key] = value;});
        }
      },
      responsive: {
        details: {
          type: 'inline',
          target: 1
        }
      },
      order: [[1, 'desc']],
      columns: tableColumns(),
      createdRow: function (row) {
        $(row).addClass('btn-reveal-trigger');
      },
      initComplete: function () {
        if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
          window.AppDataTables.showColumns(this.api(), protectedColumns);
        }
        restoreSelectionState(this.api());
      },
      drawCallback: function () {
        restoreSelectionState(this.api());
        if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
          window.AppDataTables.applyFalconEnhancements(document);
        }
      }
    }));

    $('#quotation-filter-form').off('submit.quotationFilters reset.quotationFilters').on('submit.quotationFilters', function (event) {event.preventDefault(); quotationTable.ajax.reload();}).on('reset.quotationFilters', function () {setTimeout(() => quotationTable.ajax.reload(), 0);});
    $('#quotations_trash_filter').off('change.quotations').on('change.quotations', function () {
      clearSelection(quotationTable);
      quotationTable.ajax.reload(null, true);
    });

    $('#select_all_records').off('change.quotations').on('change.quotations', function () {
      const checked = $(this).is(':checked');
      pageCheckboxes(quotationTable).each(function () {
        const docNum = checkboxDocNum(this);

        if (docNum === '') {
          return;
        }

        if (checked) {
          selectedDocNums.add(docNum);
        } else {
          selectedDocNums.delete(docNum);
        }

        $(this).prop('checked', checked);
      });
      syncBulkUi();
    });

    $table.off('change.quotationsSelect', 'tbody input.js-record-select').on('change.quotationsSelect', 'tbody input.js-record-select', function () {
      const docNum = checkboxDocNum(this);

      if (docNum === '') {
        return;
      }

      if ($(this).is(':checked')) {
        selectedDocNums.add(docNum);
      } else {
        selectedDocNums.delete(docNum);
      }
      syncBulkUi();
    });

    $table.off('dblclick.quotationsEditRow', 'tbody tr:not(.child)').on('dblclick.quotationsEditRow', 'tbody tr:not(.child)', function (event) {
      if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
        return;
      }

      const data = quotationTable && typeof quotationTable.row === 'function' ? quotationTable.row(this).data() : null;
      if (data && data.can_edit && data.edit_url) {
        window.location.href = data.edit_url;
      }
    });

    $('#bulk_action_apply').off('click.quotationsBulk').on('click.quotationsBulk', function () {
      const action = String($('#bulk_action_select').val() || 'delete');
      const docNums = Array.from(selectedDocNums);
      const url = action === 'restore' ? $table.data('bulk-restore-url') : $table.data('bulk-delete-url');

      if (docNums.length === 0 || !url) {
        showToast('warning', msg('noRowsSelected', 'Select at least one record.'));
        return;
      }

      confirmDialog({
        title: msg(action === 'restore' ? 'bulkRestoreConfirmTitle' : 'bulkDeleteConfirmTitle'),
        text: String(msg(action === 'restore' ? 'bulkRestoreConfirmText' : 'bulkDeleteConfirmText')).replace(':count', docNums.length),
        confirmButtonText: msg(action === 'restore' ? 'bulkRestoreConfirmYes' : 'bulkDeleteConfirmYes'),
        confirmButtonColor: action === 'restore' ? '#00a65a' : '#d33'
      }).then(function (result) {
        if (!result.isConfirmed) {
          return;
        }

        $.ajax({
          url: url,
          method: action === 'restore' ? 'PATCH' : 'DELETE',
          data: { doc_nums: docNums },
          headers: headers()
        }).done(function (response) {
          showToast('success', response.message || msg('saved', 'Saved.'));
          clearSelection(quotationTable);
          quotationTable.ajax.reload(null, false);
        }).fail(function (xhr) {
          showToast('error', responseMessage(xhr));
        });
      });
    });
  }

  function responseMessage(xhr) {
    return xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : msg('unexpectedError', 'Unexpected error occurred.');
  }

  function toNumber(value) {
    return window.AppNumbers.number(value, 0);
  }

  function decimal(value) {
    const normalized = (Math.round((value + Number.EPSILON) * 10000) / 10000).toFixed(4).replace(/\.?0+$/, '') || '0';

    return window.AppNumbers.format(normalized);
  }

  function discountAmount(base, type, value) {
    const numeric = Math.max(0, toNumber(value));

    if (type === 'percentage') {
      return Math.min(base, base * Math.min(numeric, 100) / 100);
    }

    if (type === 'fixed') {
      return Math.min(base, numeric);
    }

    return 0;
  }

  function calculateTotals($form) {
    let subtotal = 0;
    let lineDiscount = 0;
    let tax = 0;

    $form.find('.js-quotation-line').each(function () {
      const $row = $(this);
      const quantity = toNumber($row.find('[name$="[quantity]"]').val());
      const unitPrice = toNumber($row.find('[name$="[unit_price]"]').val());
      const base = quantity * unitPrice;
      const discount = discountAmount(base, $row.find('[name$="[discount_type]"]').val(), $row.find('[name$="[discount_value]"]').val());
      const taxBase = Math.max(0, base - discount);
      const rowTax = taxBase * (toNumber($row.find('[name$="[tax_rate]"]').val()) / 100);
      const total = taxBase + rowTax;

      subtotal += base;
      lineDiscount += discount;
      tax += rowTax;
      $row.find('.js-quotation-line-total').text(decimal(total));
    });

    const headerDiscount = discountAmount(Math.max(0, subtotal - lineDiscount), $form.find('[name="discount_type"]').val(), $form.find('[name="discount_value"]').val());
    const discount = lineDiscount + headerDiscount;
    const total = Math.max(0, subtotal - discount + tax);

    $form.find('.js-quotation-subtotal').text(decimal(subtotal));
    $form.find('.js-quotation-discount').text(decimal(discount));
    $form.find('.js-quotation-tax').text(decimal(tax));
    $form.find('.js-quotation-total').text(decimal(total));
  }

  function renumberRows($table, rowSelector, collection) {
    $table.find(rowSelector).each(function (index) {
      const $row = $(this);
      $row.attr('data-index', index);
      $row.find('[name]').each(function () {
        const $field = $(this);
        $field.attr('name', String($field.attr('name')).replace(new RegExp(collection + '\\[\\d+\\]|' + collection + '\\[__INDEX__\\]'), collection + '[' + index + ']'));
      });
      $row.find('[data-error-for]').each(function () {
        const $error = $(this);
        $error.attr('data-error-for', String($error.attr('data-error-for')).replace(new RegExp(collection + '\\.\\d+\\.|' + collection + '\\.__INDEX__\\.'), collection + '.' + index + '.'));
      });
    });
  }

  function addTemplateRow($table, templateSelector, rowSelector, collection) {
    const $row = $(window.AppLineItemCards.append($table.find('tbody')[0], document.querySelector(templateSelector), collection));
    initSelect2($row[0]);
    initDatePickers($row[0]);

    return $row;
  }

  function removeRow($row, rowSelector, collection) {
    const $table = $row.closest('table');
    const $rows = $table.find(rowSelector);

    if ($rows.length <= 1 && rowSelector === '.js-quotation-line') {
      $row.find('input, textarea').val('');
      $row.find('select').val(null).trigger('change');
      $row.find('[name$="[quantity]"]').val('1');
      $row.find('[name$="[discount_value]"], [name$="[tax_rate]"]').val('0');
      $row.find('.js-quotation-line-total').text('0');
      return;
    }

    window.AppLineItemCards.remove($row[0], collection, rowSelector === '.js-quotation-line' ? 1 : 0);
  }

  function initSummernote() {
    if (!$.fn.summernote) {
      return;
    }

    $('.js-quotation-rich-editor').each(function () {
      const $editor = $(this);

      if ($editor.data('summernote')) {
        return;
      }

      $editor.summernote({
        height: 180,
        direction: $editor.data('direction') || (document.documentElement.getAttribute('dir') || 'ltr'),
        toolbar: [
          ['style', ['bold', 'italic', 'underline', 'clear']],
          ['para', ['ul', 'ol', 'paragraph']],
          ['insert', ['link', 'table']],
          ['view', ['codeview']]
        ]
      });
    });
  }

  function syncSummernote($form) {
    $form.find('.js-quotation-rich-editor').each(function () {
      const $editor = $(this);
      if ($editor.data('summernote')) {
        $editor.val($editor.summernote('code'));
      }
    });
  }

  function updateUrlsAfterDocNumberChange($form, response) {
    const data = response && response.data ? response.data : {};
    const urls = data.urls || {};

    if (!data.old_doc_num || !data.doc_num || data.old_doc_num === data.doc_num) {
      return;
    }

    if (urls.update) {
      $form.attr('action', urls.update);
    }

    $('[href*="' + data.old_doc_num + '"], [data-url*="' + data.old_doc_num + '"], [data-delete-url*="' + data.old_doc_num + '"], [data-restore-url*="' + data.old_doc_num + '"]').each(function () {
      const $element = $(this);
      ['href', 'data-url', 'data-delete-url', 'data-restore-url'].forEach(function (attribute) {
        const value = $element.attr(attribute);
        if (value) {
          $element.attr(attribute, String(value).replace(data.old_doc_num, data.doc_num));
        }
      });
    });

    if (window.history && window.location.pathname.indexOf(data.old_doc_num) !== -1) {
      window.history.replaceState({}, '', window.location.pathname.replace(data.old_doc_num, data.doc_num) + window.location.search + window.location.hash);
    }
  }

  function resetCreateForm($form) {
    $form.find('input[type="text"], input[type="number"], textarea').not('[name="exchange_rate"], [name$="[quantity]"], [name$="[discount_value]"], [name$="[tax_rate]"]').val('');
    $form.find('[name="exchange_rate"]').val('1');
    $form.find('select').val(null).trigger('change');
    $form.find('[name="quotation_type"]').val('standard');
    $form.find('[name="submit_action"]').val('save');
    $form.find('[name="clone_source_token"]').remove();
    $form.find('.js-quotation-lines tbody').empty();
    addTemplateRow($form.find('.js-quotation-lines'), '#quotation-line-template', '.js-quotation-line', 'lines');
    $form.find('.js-quotation-milestones tbody, .js-quotation-schedule tbody, #quotation-selected-attachments, #quotation_attachment_file_inputs').empty();
    $('#quotation-selected-attachments-wrap').addClass('d-none');
    calculateTotals($form);
  }

  function initForm() {
    const $form = $('.js-quotation-form').first();

    initSelect2(document);
    initDatePickers(document);
    initSummernote();
    calculateTotals($form);

    $(document)
      .off('click.quotationsSubmitAction', '.js-quotation-submit-action')
      .on('click.quotationsSubmitAction', '.js-quotation-submit-action', function () {
        const $button = $(this);
        $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
        $button.closest('form').data('submit-button', $button);
      })
      .off('submit.quotationsForm', '.js-quotation-form')
      .on('submit.quotationsForm', '.js-quotation-form', function (event) {
        event.preventDefault();

        const $currentForm = $(this);
        const $button = $currentForm.data('submit-button') || $currentForm.find('[type="submit"]').first();
        const method = $currentForm.find('input[name="_method"]').val() || $currentForm.attr('method') || 'POST';

        clearFormErrors($currentForm);
        syncSummernote($currentForm);
        setLoading($button, true);

        $.ajax({
          url: $currentForm.attr('action'),
          method: method,
          data: $currentForm.serialize(),
          headers: headers()
        }).done(function (response) {
          updateUrlsAfterDocNumberChange($currentForm, response);
          showToast('success', response.message || msg('saved', 'Saved.'));

          if (response.redirect) {
            window.location.href = response.redirect;
            return;
          }

          if (response.reset_form) {
            resetCreateForm($currentForm);
          }
        }).fail(function (xhr) {
          if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
            showValidationErrors($currentForm, xhr.responseJSON.errors);
            return;
          }

          alertElement($currentForm)
            .removeClass('d-none alert-success alert-info alert-warning')
            .addClass('alert-danger')
            .find('.js-form-alert-message')
            .text(responseMessage(xhr));
        }).always(function () {
          setLoading($button, false);
        });
      })
      .off('input.quotationsCalc change.quotationsCalc', '.js-quotation-calc')
      .on('input.quotationsCalc change.quotationsCalc', '.js-quotation-calc', function () {
        calculateTotals($(this).closest('.js-quotation-form'));
      })
      .off('select2:select.quotationsProduct', '.js-quotation-product')
      .on('select2:select.quotationsProduct', '.js-quotation-product', function (event) {
        const data = event.params ? event.params.data : null;
        const $row = $(this).closest('.js-quotation-line');
        const unitDocNum = data && data.unitDocNum ? String(data.unitDocNum) : '';
        const unitLabel = data && data.unitLabel ? String(data.unitLabel) : '';
        const $unit = $row.find('.js-quotation-unit').first();

        if ($unit.length) {
          $unit.empty().append(new Option('', ''));
          (data?.units || []).forEach(unit => $unit.append(new Option(unit.text, unit.id, false, unit.id === unitDocNum)));
          $unit.val(unitDocNum).trigger('change');
        }
        const $description = $row.find('[name$="[description]"]');
        if (!$description.val() || $description.data('autofilled')) $description.val(data?.productData?.name || '').data('autofilled', true);
        let $details = $row.find('[data-product-characteristics]');
        if (!$details.length) $details = $('<small class="text-600" data-product-characteristics></small>').insertAfter(this);
        $details.text([data?.productData?.color, data?.productData?.model, data?.productData?.size].filter(Boolean).join(' · '));
        window.AppSalesPricing?.suggest($row[0]);
      })
      .off('change.quotationPrice', '.js-quotation-unit, [name="customer_doc_num"], [name="currency_doc_num"]')
      .on('change.quotationPrice', '.js-quotation-unit, [name="customer_doc_num"], [name="currency_doc_num"]', function () {
        $(this).closest('.js-quotation-form').find('.js-quotation-line').each(function () {window.AppSalesPricing?.suggest(this);});
      })
      .off('click.quotationsAddLine', '.js-quotation-add-line')
      .on('click.quotationsAddLine', '.js-quotation-add-line', function () {
        addTemplateRow($(this).closest('form').find('.js-quotation-lines'), '#quotation-line-template', '.js-quotation-line', 'lines');
      })
      .off('click.quotationsDuplicateLine', '.js-quotation-duplicate-line')
      .on('click.quotationsDuplicateLine', '.js-quotation-duplicate-line', function () {
        const $row = $(this).closest('.js-quotation-line');
        const $newRow = $(window.AppLineItemCards.append($row.closest('tbody')[0], document.querySelector('#quotation-line-template'), 'lines', $row[0]));
        initSelect2($newRow[0]); initDatePickers($newRow[0]); calculateTotals($row.closest('form'));
      })
      .off('click.quotationsRemoveLine', '.js-quotation-remove-line')
      .on('click.quotationsRemoveLine', '.js-quotation-remove-line', function () {
        const $form = $(this).closest('form');
        removeRow($(this).closest('.js-quotation-line'), '.js-quotation-line', 'lines');
        calculateTotals($form);
      })
      .off('click.quotationsAddMilestone', '.js-quotation-add-milestone')
      .on('click.quotationsAddMilestone', '.js-quotation-add-milestone', function () {
        addTemplateRow($(this).closest('form').find('.js-quotation-milestones'), '#quotation-milestone-template', '.js-quotation-milestone', 'payment_milestones');
      })
      .off('click.quotationsRemoveMilestone', '.js-quotation-remove-milestone')
      .on('click.quotationsRemoveMilestone', '.js-quotation-remove-milestone', function () {
        removeRow($(this).closest('.js-quotation-milestone'), '.js-quotation-milestone', 'payment_milestones');
      })
      .off('click.quotationsAddSchedule', '.js-quotation-add-schedule')
      .on('click.quotationsAddSchedule', '.js-quotation-add-schedule', function () {
        addTemplateRow($(this).closest('form').find('.js-quotation-schedule'), '#quotation-schedule-template', '.js-quotation-schedule-line', 'execution_schedule_lines');
      })
      .off('click.quotationsRemoveSchedule', '.js-quotation-remove-schedule')
      .on('click.quotationsRemoveSchedule', '.js-quotation-remove-schedule', function () {
        removeRow($(this).closest('.js-quotation-schedule-line'), '.js-quotation-schedule-line', 'execution_schedule_lines');
      })
      .off('click.quotationsStatus', '.js-quotation-status-action')
      .on('click.quotationsStatus', '.js-quotation-status-action', function () {
        const url = $(this).data('url');
        if (!url) {
          return;
        }
        $.ajax({ url: url, method: 'POST', headers: headers() }).done(function (response) {
          showToast('success', response.message || msg('saved', 'Saved.'));
          window.location.reload();
        }).fail(function (xhr) {
          showToast('error', responseMessage(xhr));
        });
      })
      .off('click.quotationsConvert', '.js-quotation-convert-action')
      .on('click.quotationsConvert', '.js-quotation-convert-action', function () {
        const $button = $(this);
        const url = $button.data('url');
        if (!url) {
          return;
        }

        setLoading($button, true);
        $.ajax({ url: url, method: 'POST', headers: headers() }).done(function (response) {
          showToast('success', response.message || msg('saved', 'Saved.'));
          if (response.redirect) {
            window.location.href = response.redirect;
          }
        }).fail(function (xhr) {
          showToast('error', responseMessage(xhr));
          setLoading($button, false);
        });
      })
      .off('click.quotationsCreateRevision', '.js-create-quotation-revision')
      .on('click.quotationsCreateRevision', '.js-create-quotation-revision', function () {
        const url = $(this).data('url');
        if (!url) {
          return;
        }
        $.ajax({ url: url, method: 'POST', headers: headers() }).done(function (response) {
          showToast('success', response.message || msg('saved', 'Saved.'));
          if (response.redirect) {
            window.location.href = response.redirect;
          }
        }).fail(function (xhr) {
          showToast('error', responseMessage(xhr));
        });
      });
  }

  function renderSelectedAttachment(file) {
    if (!file || !file.public_id) {
      return;
    }

    const publicId = String(file.public_id);
    if ($('#quotation-selected-attachments tr[data-public-id="' + publicId + '"]').length > 0) {
      return;
    }

    $('<input type="hidden" name="attachment_file_doc_nums[]">').val(publicId).attr('data-public-id', publicId).appendTo('#quotation_attachment_file_inputs');
    $('<tr></tr>')
      .attr('data-public-id', publicId)
      .append($('<td></td>').text(file.original_name || file.name || publicId))
      .append($('<td dir="ltr"></td>').text(file.size_label || ''))
      .append($('<td class="text-end"></td>').append($('<button class="btn btn-link text-danger p-0 js-remove-selected-quotation-attachment" type="button"><span class="fas fa-times"></span></button>')))
      .appendTo('#quotation-selected-attachments');

    $('#quotation-selected-attachments-wrap').removeClass('d-none');
  }

  function initAttachments() {
    $(document)
      .off('file-picker:selected.quotations', '.js-quotation-attachment-picker-trigger')
      .on('file-picker:selected.quotations', '.js-quotation-attachment-picker-trigger', function (event, payload) {
        if (!payload || payload.collection !== 'quotation_attachments') {
          return;
        }
        (payload.files || []).forEach(renderSelectedAttachment);
      })
      .off('click.quotationsRemoveSelectedAttachment', '.js-remove-selected-quotation-attachment')
      .on('click.quotationsRemoveSelectedAttachment', '.js-remove-selected-quotation-attachment', function () {
        const $row = $(this).closest('tr');
        const publicId = $row.data('public-id');
        $row.remove();
        $('#quotation_attachment_file_inputs input[data-public-id="' + publicId + '"]').remove();
        $('#quotation-selected-attachments-wrap').toggleClass('d-none', $('#quotation-selected-attachments tr').length === 0);
      })
      .off('click.quotationsDeleteAttachment', '.js-delete-quotation-attachment')
      .on('click.quotationsDeleteAttachment', '.js-delete-quotation-attachment', function () {
        const $button = $(this);
        const url = $button.data('url');
        if (!url) {
          return;
        }
        $.ajax({ url: url, method: 'DELETE', headers: headers() }).done(function (response) {
          showToast('success', response.message || msg('saved', 'Saved.'));
          $button.closest('tr').remove();
        }).fail(function (xhr) {
          showToast('error', responseMessage(xhr));
        });
      });
  }

  function initRecordActions() {
    $(document)
      .off('click.quotationsDeleteRecord', '.js-delete-record')
      .on('click.quotationsDeleteRecord', '.js-delete-record', function () {
        const $button = $(this);
        const url = $button.data('delete-url');
        if (!url) {
          return;
        }
        confirmDialog({
          title: msg('deleteConfirmTitle'),
          text: msg('deleteConfirmText'),
          confirmButtonText: msg('deleteConfirmYes')
        }).then(function (result) {
          if (!result.isConfirmed) {
            return;
          }
          $.ajax({ url: url, method: 'DELETE', headers: headers() }).done(function (response) {
            showToast('success', response.message || msg('saved', 'Saved.'));
            if ($button.data('redirect-url')) {
              window.location.href = $button.data('redirect-url');
              return;
            }
            if (quotationTable) {
              quotationTable.ajax.reload(null, false);
            }
          }).fail(function (xhr) {
            showToast('error', responseMessage(xhr));
          });
        });
      })
      .off('click.quotationsRestoreRecord', '.js-restore-record')
      .on('click.quotationsRestoreRecord', '.js-restore-record', function () {
        const url = $(this).data('restore-url');
        if (!url) {
          return;
        }
        $.ajax({ url: url, method: 'PATCH', headers: headers() }).done(function (response) {
          showToast('success', response.message || msg('saved', 'Saved.'));
          if (quotationTable) {
            quotationTable.ajax.reload(null, false);
          } else {
            window.location.reload();
          }
        }).fail(function (xhr) {
          showToast('error', responseMessage(xhr));
        });
      });
  }

  function initDocumentNumberSettings() {
    $(document)
      .off('submit.quotationDocSettings', '.js-quotation-document-number-settings-form')
      .on('submit.quotationDocSettings', '.js-quotation-document-number-settings-form', function (event) {
        event.preventDefault();
        const $form = $(this);
        const $button = $form.find('[type="submit"]').first();

        clearFormErrors($form);
        setLoading($button, true);
        $.ajax({
          url: $form.attr('action'),
          method: $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST',
          data: $form.serialize(),
          headers: headers()
        }).done(function (response) {
          showToast('success', response.message || msg('saved', 'Saved.'));
        }).fail(function (xhr) {
          if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
            showValidationErrors($form, xhr.responseJSON.errors);
            return;
          }
          showToast('error', responseMessage(xhr));
        }).always(function () {
          setLoading($button, false);
        });
      });
  }

  $(function () {
    initTable();
    initForm();
    initAttachments();
    initRecordActions();
    initDocumentNumberSettings();
  });
})(jQuery, window, document);
