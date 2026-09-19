(function ($, window, document) {
  'use strict';

  const selectedProductionOrders = new Set();
  const recordSelections = new WeakMap();
  const isArabic = String(document.documentElement.lang || '').toLowerCase().startsWith('ar');
  const fallbackMessages = isArabic ? {
    invalidTableConfiguration: 'إعدادات الجدول غير صحيحة.',
    deleteConfirm: 'هل تريد حذف هذا السجل؟',
    done: 'تمت العملية بنجاح.',
    operationFailed: 'تعذر إتمام العملية.',
    reason: 'السبب',
    attachmentsReady: 'ملف جاهز للرفع',
    noAttachments: 'لم يتم اختيار مرفقات.',
    stockBalanceLoading: 'جارٍ التحقق من الرصيد المتاح...',
    stockBalanceAvailable: 'أقصى كمية متاحة للفحص من رصيد المخزن: ',
    stockBalanceInsufficient: 'الكمية المطلوبة أكبر من الرصيد المتاح للفحص.',
    stockBalanceError: 'تعذر قراءة الرصيد الحالي.',
  } : {
    invalidTableConfiguration: 'Invalid table configuration.',
    deleteConfirm: 'Delete this record?',
    done: 'Done.',
    operationFailed: 'Operation failed.',
    reason: 'Reason',
    attachmentsReady: 'file(s) ready to upload',
    noAttachments: 'No attachments selected.',
    stockBalanceLoading: 'Checking available stock...',
    stockBalanceAvailable: 'Maximum inspectable quantity in this warehouse: ',
    stockBalanceInsufficient: 'Requested quantity exceeds inspectable stock.',
    stockBalanceError: 'Could not read the current stock balance.',
  };

  function fallbackMessage(key) {
    return fallbackMessages[key] || '';
  }

  function csrf() {
    return $('meta[name="csrf-token"]').attr('content');
  }

  function submissionToken(element) {
    if (!element.dataset.submissionToken) {
      element.dataset.submissionToken = window.crypto.randomUUID();
    }

    return element.dataset.submissionToken;
  }

  $(document).on('submit', '.production-mobile-workflow form', function () {
    if (String(this.method || 'GET').toUpperCase() !== 'POST' || this.querySelector('[name="_submission_token"]')) {
      return;
    }

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_submission_token';
    input.value = submissionToken(this);
    this.appendChild(input);
  });

  function notify(icon, message) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, message);
      return;
    }

    window.alert(message);
  }

  function clearDocumentNumberSettingsErrors(form) {
    $(form).find('.js-form-alert').addClass('d-none');
    $(form).find('.js-form-alert-message, [data-error-for]').text('');
    $(form).find('.is-invalid').removeClass('is-invalid');
  }

  function showDocumentNumberSettingsErrors(form, errors) {
    const $form = $(form);
    const firstMessage = Object.values(errors || {}).flat()[0] || form.dataset.unexpectedError || '';

    Object.entries(errors || {}).forEach(function ([field, messages]) {
      $form.find(`[name="${field}"]`).addClass('is-invalid');
      $form.find(`[data-error-for="${field}"]`).text(Array.isArray(messages) ? messages[0] : messages);
    });
    $form.find('.js-form-alert-message').text(firstMessage);
    $form.find('.js-form-alert').toggleClass('d-none', !firstMessage);
  }

  function initializeProductionOrderDocumentNumberSettings() {
    $(document)
      .off('submit.productionOrderDocumentNumberSettings', '.js-production-order-document-number-settings-form')
      .on('submit.productionOrderDocumentNumberSettings', '.js-production-order-document-number-settings-form', function (event) {
        event.preventDefault();
        const form = this;
        const $form = $(form);
        const $button = $form.find('[type="submit"]').first();

        clearDocumentNumberSettingsErrors(form);
        $button.prop('disabled', true);
        $.ajax({
          url: $form.attr('action'),
          method: $form.find('input[name="_method"]').val() || 'PUT',
          data: $form.serialize(),
          headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
        }).done(function (payload) {
          notify('success', payload.message || '');
        }).fail(function (xhr) {
          if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
            showDocumentNumberSettingsErrors(form, xhr.responseJSON.errors);
            return;
          }
          notify('error', (xhr.responseJSON && xhr.responseJSON.message) || form.dataset.unexpectedError || '');
        }).always(function () {
          $button.prop('disabled', false);
        });
      });
  }

  function initializeTables() {
    $('[data-server-table]').each(function () {
      const element = this;
      const $table = $(element);
      if (!$.fn.DataTable || $.fn.DataTable.isDataTable(element)) {
        return;
      }

      let columns = [];
      try {
        columns = JSON.parse($table.attr('data-columns') || '[]');
      } catch (error) {
        notify('error', fallbackMessage('invalidTableConfiguration'));
        return;
      }

      const trashFilterSelector = $table.data('trash-filter');
      const initialTrashFilter = String($table.data('initial-trash-filter') || 'active');
      if (trashFilterSelector && ['active', 'trashed', 'all'].includes(initialTrashFilter)) {
        $(trashFilterSelector).val(initialTrashFilter);
      }

      const base = {
        ajax: {
          url: $table.data('url'),
          data: function (data) {
            if (trashFilterSelector) {
              data.trash_filter = $(trashFilterSelector).val() || 'active';
            }
            const recordsRoot = element.closest('[data-records-root]');
            const recordsFilter = recordsRoot && recordsRoot.querySelector('[data-trash-filter]');
            if (recordsFilter) {
              data.trash_filter = recordsFilter.value || 'active';
            }
            recordsRoot?.querySelectorAll('[data-table-filter][name]').forEach(function (filter) {
              data[filter.name] = filter.value || '';
            });
          }
        },
        processing: true,
        serverSide: true,
        stateSave: true,
        responsive: { details: { type: 'inline', target: $table.is('[data-record-selection]') ? 1 : 0 } },
        columns: columns,
        order: [[Number($table.data('order-column') || 0), String($table.data('order-direction') || 'desc')]],
        createdRow: function (row) {
          $(row).addClass('btn-reveal-trigger');
        },
        drawCallback: function () {
          syncProductionOrderSelection(element);
          syncRecordSelection(element);
          if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
            window.AppDataTables.applyFalconEnhancements(document);
          }
        }
      };
      const options = window.AppDataTables && typeof window.AppDataTables.options === 'function'
        ? window.AppDataTables.options(base)
        : base;
      $table.DataTable(options);
    });

    $('[data-client-report-tables] table:not([data-server-table])').each(function () {
      const element = this;
      const $table = $(element);
      if (!$.fn.DataTable || $.fn.DataTable.isDataTable(element)) {
        return;
      }

      $table.find('tbody > tr').filter(function () {
        return this.children.length === 1 && this.children[0].hasAttribute('colspan');
      }).remove();

      const base = {
        pageLength: 25,
        lengthMenu: [10, 25, 50, 75, 100],
        stateSave: false,
        autoWidth: false,
        responsive: { details: { type: 'inline', target: 0 } },
        order: [],
        drawCallback: function () {
          if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
            window.AppDataTables.applyFalconEnhancements(document);
          }
        }
      };
      const options = window.AppDataTables && typeof window.AppDataTables.options === 'function'
        ? window.AppDataTables.options(base)
        : base;
      $table.addClass('data-table erp-datatable');
      $table.DataTable(options);
    });
  }

  function productionOrdersTable() {
    const table = document.getElementById('production-orders-table');

    return table && $.fn.DataTable && $.fn.DataTable.isDataTable(table) ? $(table).DataTable() : null;
  }

  function productionOrderCheckboxes(table) {
    return table && typeof table.rows === 'function'
      ? $(table.rows({ page: 'current' }).nodes()).find('.js-record-select')
      : $('#production-orders-table .js-record-select');
  }

  function productionOrderDocNum(checkbox) {
    return String(checkbox.dataset.docNum || checkbox.value || '').trim();
  }

  function updateProductionOrderBulkBar() {
    const count = selectedProductionOrders.size;

    $('.production-orders-bulk-actions-bar')
      .toggleClass('d-none', count === 0)
      .toggleClass('d-flex', count > 0);
    $('#bulk_selected_count').text(count);
    $('#bulk_action_apply').prop('disabled', count === 0);
  }

  function syncProductionOrderSelection(tableElement) {
    if (!tableElement || tableElement.id !== 'production-orders-table') {
      return;
    }

    const table = productionOrdersTable();
    const checkboxes = productionOrderCheckboxes(table);
    let selectedOnPage = 0;

    checkboxes.each(function () {
      const isSelected = selectedProductionOrders.has(productionOrderDocNum(this));
      this.checked = isSelected;
      selectedOnPage += isSelected ? 1 : 0;
    });

    $('#production_orders_select_all')
      .prop('checked', checkboxes.length > 0 && selectedOnPage === checkboxes.length)
      .prop('indeterminate', selectedOnPage > 0 && selectedOnPage < checkboxes.length);
    updateProductionOrderBulkBar();
  }

  function clearProductionOrderSelection() {
    selectedProductionOrders.clear();
    syncProductionOrderSelection(document.getElementById('production-orders-table'));
  }

  function initializeWorkflowSelects(root) {
    const scope = root && root.querySelectorAll ? root : document;

    scope.querySelectorAll('.production-mobile-workflow select.form-select:not(.js-select2-ajax):not(.js-select2-local), select.form-select:not(.js-select2-ajax):not(.js-select2-local)').forEach(function (select) {
      if (select.closest('.production-mobile-workflow')) {
        select.classList.add('js-select2-local');
      }
    });

    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
      window.AppSelect2Ajax.init(scope);
    }
  }

  function initializeRowNavigation() {
    $('[data-server-table]')
      .off('dblclick.productionRowNavigation', 'tbody tr:not(.child)')
      .on('dblclick.productionRowNavigation', 'tbody tr:not(.child)', function (event) {
        if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, td.dt-select, td.dtr-control').length > 0) {
          return;
        }

        const link = this.querySelector('[data-row-primary-link]');
        if (link instanceof HTMLAnchorElement && link.href) {
          window.location.assign(link.href);
        }
      });
  }

  function reloadTables() {
    let reloaded = false;
    $('[data-server-table]').each(function () {
      if ($.fn.DataTable && $.fn.DataTable.isDataTable(this)) {
        $(this).DataTable().ajax.reload(null, false);
        reloaded = true;
      }
    });

    return reloaded;
  }

  function recordsTable(root) {
    const table = root && root.querySelector('[data-server-table]');

    return table && $.fn.DataTable && $.fn.DataTable.isDataTable(table) ? $(table).DataTable() : null;
  }

  function recordSelection(root) {
    if (!recordSelections.has(root)) {
      recordSelections.set(root, new Set());
    }

    return recordSelections.get(root);
  }

  function visibleRecordCheckboxes(root) {
    const table = recordsTable(root);

    return table ? $(table.rows({ page: 'current' }).nodes()).find('.js-record-select') : $(root).find('.js-record-select');
  }

  function syncRecordSelection(tableElement) {
    const root = tableElement && tableElement.closest('[data-records-root]');
    if (!root) {
      return;
    }

    const selected = recordSelection(root);
    const checkboxes = visibleRecordCheckboxes(root);
    let selectedOnPage = 0;
    checkboxes.each(function () {
      const key = String(this.dataset.docNum || this.value || '').trim();
      this.checked = selected.has(key);
      selectedOnPage += this.checked ? 1 : 0;
    });
    const selectAll = root.querySelector('[data-select-all]');
    if (selectAll) {
      selectAll.checked = checkboxes.length > 0 && selectedOnPage === checkboxes.length;
      selectAll.indeterminate = selectedOnPage > 0 && selectedOnPage < checkboxes.length;
    }
    const bulkActions = root.querySelector('[data-bulk-actions]');
    const count = root.querySelector('[data-selected-count]');
    const button = root.querySelector('[data-bulk-delete]');
    bulkActions?.classList.toggle('d-none', selected.size === 0);
    bulkActions?.classList.toggle('d-flex', selected.size > 0);
    if (count) {
      count.textContent = selected.size;
    }
    if (button) {
      button.disabled = selected.size === 0;
    }
  }

  $(document).on('change', '[data-records-root] [data-trash-filter]', function () {
    const root = this.closest('[data-records-root]');
    recordSelection(root).clear();
    recordsTable(root)?.ajax.reload();
    syncRecordSelection(root.querySelector('[data-server-table]'));
  });

  $(document).on('change', '[data-records-root] [data-table-filter]', function () {
    const root = this.closest('[data-records-root]');
    recordSelection(root).clear();
    recordsTable(root)?.ajax.reload();
  });

  $(document).on('change', '[data-records-root] .js-record-select', function () {
    const root = this.closest('[data-records-root]');
    const selected = recordSelection(root);
    const key = String(this.dataset.docNum || this.value || '').trim();
    this.checked ? selected.add(key) : selected.delete(key);
    syncRecordSelection(root.querySelector('[data-server-table]'));
  });

  $(document).on('change', '[data-records-root] [data-select-all]', function () {
    const root = this.closest('[data-records-root]');
    const selected = recordSelection(root);
    visibleRecordCheckboxes(root).each((index, checkbox) => {
      const key = String(checkbox.dataset.docNum || checkbox.value || '').trim();
      checkbox.checked = this.checked;
      this.checked ? selected.add(key) : selected.delete(key);
    });
    syncRecordSelection(root.querySelector('[data-server-table]'));
  });

  $(document).on('click', '[data-records-root] [data-bulk-delete]', function () {
    const root = this.closest('[data-records-root]');
    const selected = Array.from(recordSelection(root));
    if (selected.length === 0 || !window.confirm(fallbackMessage('deleteConfirm'))) {
      return;
    }

    const button = this;
    button.disabled = true;
    $.ajax({
      url: root.dataset.bulkDeleteUrl,
      method: 'DELETE',
      data: { [root.dataset.bulkParam || 'doc_nums']: selected },
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
    }).done(function (payload) {
      recordSelection(root).clear();
      recordsTable(root)?.ajax.reload(null, false);
      notify('success', payload.message || fallbackMessage('done'));
    }).fail(function (xhr) {
      notify('error', (xhr.responseJSON && xhr.responseJSON.message) || fallbackMessage('operationFailed'));
    }).always(function () {
      button.disabled = false;
    });
  });

  $(document).on('change', '#production_orders_trash_filter', function () {
    clearProductionOrderSelection();
    const table = productionOrdersTable();
    if (table) {
      table.ajax.reload();
    }
  });

  $(document).on('change', '#production-orders-table .js-record-select', function () {
    const docNum = productionOrderDocNum(this);
    if (this.checked) {
      selectedProductionOrders.add(docNum);
    } else {
      selectedProductionOrders.delete(docNum);
    }
    syncProductionOrderSelection(document.getElementById('production-orders-table'));
  });

  $(document).on('change', '#production_orders_select_all', function () {
    productionOrderCheckboxes(productionOrdersTable()).each((index, checkbox) => {
      const docNum = productionOrderDocNum(checkbox);
      checkbox.checked = this.checked;
      if (this.checked) {
        selectedProductionOrders.add(docNum);
      } else {
        selectedProductionOrders.delete(docNum);
      }
    });
    syncProductionOrderSelection(document.getElementById('production-orders-table'));
  });

  $(document).on('click', '.production-orders-bulk-actions-bar #bulk_action_apply', function () {
    const tableElement = document.getElementById('production-orders-table');
    const docNums = Array.from(selectedProductionOrders);
    if (!tableElement || docNums.length === 0) {
      notify('info', tableElement?.dataset.noSelectionMessage || '');
      return;
    }
    if (!window.confirm(tableElement.dataset.bulkConfirmMessage || '')) {
      return;
    }

    const button = this;
    button.disabled = true;
    $.ajax({
      url: tableElement.dataset.bulkDeleteUrl,
      method: 'DELETE',
      data: { doc_nums: docNums },
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
    }).done(function (payload) {
      clearProductionOrderSelection();
      productionOrdersTable()?.ajax.reload(null, false);
      notify('success', payload.message || tableElement.dataset.bulkSuccessMessage || '');
    }).fail(function (xhr) {
      notify('error', (xhr.responseJSON && xhr.responseJSON.message) || '');
    }).always(function () {
      button.disabled = false;
    });
  });

  $(document).on('click', '[data-action="post"], [data-action="delete"], [data-action="restore"]', function () {
    const button = this;
    const method = button.dataset.action === 'delete' ? 'DELETE' : (button.dataset.action === 'restore' ? 'PATCH' : 'POST');
    if (button.dataset.action === 'delete' && !window.confirm(button.dataset.confirm || fallbackMessage('deleteConfirm'))) {
      return;
    }
    button.disabled = true;
    $.ajax({
      url: button.dataset.url,
      method: method,
      data: method === 'POST' ? { _submission_token: submissionToken(button) } : undefined,
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
    }).done(function (payload) {
      notify('success', payload.message || fallbackMessage('done'));
      if (payload.redirect_url) {
        window.location.assign(payload.redirect_url);
        return;
      }
      if (!reloadTables()) {
        window.location.reload();
      }
    }).fail(function (xhr) {
      notify('error', (xhr.responseJSON && xhr.responseJSON.message) || fallbackMessage('operationFailed'));
    }).always(function () {
      button.disabled = false;
    });
  });

  $(document).on('click', '.js-delete-record, .js-restore-record', function () {
    const button = this;
    const isRestore = button.classList.contains('js-restore-record');
    if (!isRestore && !window.confirm(button.dataset.confirm || fallbackMessage('deleteConfirm'))) {
      return;
    }
    button.disabled = true;
    $.ajax({
      url: isRestore ? button.dataset.restoreUrl : button.dataset.deleteUrl,
      method: isRestore ? 'PATCH' : 'DELETE',
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
    }).done(function (payload) {
      notify('success', payload.message || fallbackMessage('done'));
      if (!isRestore && button.dataset.redirectUrl) {
        window.location.assign(button.dataset.redirectUrl);
      } else {
        window.location.reload();
      }
    }).fail(function (xhr) {
      notify('error', (xhr.responseJSON && xhr.responseJSON.message) || fallbackMessage('operationFailed'));
      button.disabled = false;
    });
  });

  $(document).on('click', '[data-maintenance-form] .js-finance-submit-action', function () {
    const form = this.closest('[data-maintenance-form]');
    const action = form && form.querySelector('[name="submit_action"]');
    if (action) {
      action.value = this.dataset.submitAction || 'save';
    }
  });

  $(document).on('click', '[data-action="reason"]', function () {
    const button = this;
    const reason = window.prompt(button.dataset.prompt || fallbackMessage('reason'));
    if (!reason) {
      return;
    }

    button.disabled = true;
    $.ajax({
      url: button.dataset.url,
      method: 'POST',
      data: { [button.dataset.reasonKey || 'reason']: reason, _submission_token: submissionToken(button) },
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
    }).done(function (payload) {
      notify('success', payload.message || fallbackMessage('done'));
      if (!reloadTables()) {
        window.location.reload();
      }
    }).fail(function (xhr) {
      notify('error', (xhr.responseJSON && xhr.responseJSON.message) || fallbackMessage('operationFailed'));
    }).always(function () {
      button.disabled = false;
    });
  });

  $(document).on('click', '[data-navigate-select]', function () {
    const selector = document.querySelector(this.dataset.navigateSelect);
    if (selector && selector.value) {
      window.location.href = selector.value;
    }
  });

  function filterStageOptions() {
    const lineId = String($('[data-route-line]').val() || '');
    const $stage = $('[data-route-stage]');
    $stage.find('option[data-line-id]').each(function () {
      $(this).prop('hidden', String(this.dataset.lineId) !== lineId);
    });
    if ($stage.find('option:selected').prop('hidden')) {
      $stage.val('');
    }
  }

  $(document).on('change', '[data-route-line]', filterStageOptions);

  $(document).on('change', '[data-material-run-select]', function () {
    if (!this.value) {
      return;
    }
    const url = new URL(this.dataset.url, window.location.origin);
    url.searchParams.set('run', this.value);
    const additional = document.querySelector('[data-additional-material]');
    if (additional && additional.checked) {
      url.searchParams.set('additional', '1');
    }
    window.location.href = url.toString();
  });

  function updateAdditionalMaterialForm(resetQuantities) {
    const additional = document.querySelector('[data-additional-material]');
    if (!additional) {
      return;
    }
    const reason = document.querySelector('[data-additional-reason]');
    if (reason) {
      reason.required = additional.checked;
    }
    if (resetQuantities) {
      document.querySelectorAll('[data-planned-remaining]').forEach(function (input) {
        input.value = additional.checked ? '' : input.dataset.plannedRemaining;
      });
    }
  }

  $(document).on('change', '[data-additional-material]', function () { updateAdditionalMaterialForm(true); });

  function updateProductionPaymentFields() {
    const selector = document.querySelector('[data-production-payment-channel]');
    if (!selector) {
      return;
    }
    const cashPayment = selector.value === 'cashbox';
    document.querySelectorAll('[data-production-cashbox-field]').forEach(function (field) {
      field.hidden = !cashPayment;
      field.querySelectorAll('select, input').forEach(function (input) { input.required = cashPayment; input.disabled = !cashPayment; });
    });
    document.querySelectorAll('[data-production-bank-field]').forEach(function (field) {
      field.hidden = cashPayment;
      field.querySelectorAll('select, input').forEach(function (input) { input.required = !cashPayment; input.disabled = cashPayment; });
    });
  }

  $(document).on('change', '[data-production-payment-channel]', updateProductionPaymentFields);

  function filterQualityCheckpoints() {
    $('[data-quality-upload]').each(function () {
      const typeId = String($(this).find('[data-quality-type]').val() || '');
      $(this).find('[data-quality-checkpoint]').each(function () {
        const visible = String(this.dataset.typeId) === typeId;
        this.hidden = !visible;
        $(this).find('[data-quality-checkpoint-input]').each(function () {
          this.disabled = !visible;
          this.required = visible && this.hasAttribute('data-quality-required');
        });
      });
    });
  }

  $(document).on('change', '[data-quality-type]', filterQualityCheckpoints);

  function updateQualityDisposition() {
    $('[data-quality-upload]').each(function () {
      const result = String($(this).find('[data-quality-overall-result]').val() || '');
      const exceptionDetails = this.querySelector('[data-quality-exception-details]');
      if (exceptionDetails) {
        exceptionDetails.hidden = result === 'passed';
      }
    });
  }

  function updateQualitySubjectFields() {
    const selector = document.querySelector('[data-quality-subject-type]');
    if (!selector) {
      return;
    }

    const selected = String(selector.value || 'production_run');
    document.querySelectorAll('[data-quality-subject-field]').forEach(function (field) {
      const isVisible = String(field.dataset.qualitySubjectField || '').split(',').includes(selected);
      field.hidden = !isVisible;
      field.querySelectorAll('select, input, textarea').forEach(function (input) {
        input.disabled = !isVisible;
        input.required = isVisible && input.hasAttribute('data-quality-required');
      });
    });
    updateQualityStockBalance();
  }

  $(document).on('change', '[data-quality-subject-type]', updateQualitySubjectFields);

  let qualityBalanceRequest = null;
  function updateQualityStockBalance() {
    const form = document.querySelector('[data-quality-request-form]');
    const summary = form && form.querySelector('[data-quality-stock-balance]');
    const subject = form && form.querySelector('[name="subject_type"]');
    const product = form && form.querySelector('[name="product_id"]');
    const store = form && form.querySelector('[name="branch_store_id"]');
    const status = form && form.querySelector('[name="stock_status"]');
    const batch = form && form.querySelector('[name="batch_lot"]');
    const quantity = form && form.querySelector('[name="affected_base_quantity"]');
    if (!form || !summary || subject?.value !== 'inventory_stock') {
      quantity?.setCustomValidity('');
      return;
    }
    if (!product?.value || !store?.value || !status?.value) {
      summary.className = 'alert alert-secondary mb-0';
      summary.textContent = fallbackMessage('stockBalanceLoading');
      quantity?.setCustomValidity('');
      return;
    }

    qualityBalanceRequest?.abort();
    summary.className = 'alert alert-info mb-0';
    summary.textContent = fallbackMessage('stockBalanceLoading');
    qualityBalanceRequest = $.getJSON(form.dataset.qualityBalanceUrl, {
      product_id: product.value,
      branch_store_id: store.value,
      stock_status: status.value,
      batch_lot: batch?.value || ''
    }).done(function (payload) {
      const sameHeldPosition = String(product.value || '') === String(form.dataset.originalProduct || '')
        && String(store.value || '') === String(form.dataset.originalStore || '')
        && String(status.value || '') === String(form.dataset.originalStockStatus || '')
        && String(batch?.value || '') === String(form.dataset.originalBatch || '');
      const currentHeld = sameHeldPosition ? Number(form.dataset.currentHeld || 0) : 0;
      const inspectable = Number(payload.inspectable_available || 0) + currentHeld;
      const requested = Number(quantity?.value || 0);
      const insufficient = requested > inspectable;
      summary.className = `alert ${insufficient ? 'alert-danger' : 'alert-success'} mb-0`;
      summary.textContent = `${fallbackMessage('stockBalanceAvailable')}${inspectable.toLocaleString()}` + (insufficient ? ` — ${fallbackMessage('stockBalanceInsufficient')}` : '');
      quantity?.setCustomValidity(insufficient ? fallbackMessage('stockBalanceInsufficient') : '');
    }).fail(function (xhr) {
      if (xhr.statusText === 'abort') {
        return;
      }
      summary.className = 'alert alert-danger mb-0';
      summary.textContent = (xhr.responseJSON && xhr.responseJSON.message) || fallbackMessage('stockBalanceError');
      quantity?.setCustomValidity('');
    });
  }

  $(document).on('change input', '[data-quality-request-form] [name="product_id"], [data-quality-request-form] [name="branch_store_id"], [data-quality-request-form] [name="stock_status"], [data-quality-request-form] [name="batch_lot"], [data-quality-request-form] [name="affected_base_quantity"]', updateQualityStockBalance);

  $(document).on('change', '[data-quality-overall-result]', function () {
    const form = this.closest('[data-quality-upload]');
    const disposition = form && form.querySelector('[data-quality-disposition]');
    if (disposition) {
      disposition.value = this.value === 'passed' ? 'release' : 'hold';
    }
    updateQualityDisposition();
    updateQualitySubjectFields();
  });

  $(document).on('change', '[data-quality-evidence]', function () {
    const input = this;
    const form = input.closest('[data-quality-upload]');
    const summary = form && form.querySelector('[data-quality-evidence-summary]');
    const preview = form && form.querySelector('[data-quality-evidence-preview]');
    const files = form
      ? Array.from(form.querySelectorAll('[data-quality-evidence]')).flatMap(function (field) { return Array.from(field.files || []); })
      : Array.from(input.files || []);

    if (summary) {
      summary.textContent = files.length ? `${files.length} ${input.dataset.selectedLabel || fallbackMessage('attachmentsReady')}` : (input.dataset.emptyLabel || fallbackMessage('noAttachments'));
    }
    if (!preview) {
      return;
    }

    preview.replaceChildren();
    files.forEach(function (file) {
      const item = document.createElement('div');
      item.className = 'quality-evidence-preview-item';
      if (file.type.startsWith('image/')) {
        const image = document.createElement('img');
        image.alt = file.name;
        image.src = URL.createObjectURL(file);
        image.addEventListener('load', function () { URL.revokeObjectURL(image.src); }, { once: true });
        item.appendChild(image);
      }
      const name = document.createElement('span');
      name.textContent = file.name;
      item.appendChild(name);
      preview.appendChild(item);
    });
  });

  function productionOrderSourceVisibility() {
    const form = document.querySelector('[data-production-order-form]');
    const sourceType = form && form.querySelector('#production-source-type');
    const wrapper = form && form.querySelector('[data-production-source-document]');
    const sourceDocument = wrapper && wrapper.querySelector('select');
    if (!sourceType || !wrapper || !sourceDocument) {
      return;
    }

    const isStandalone = sourceType.value === 'make_to_stock';
    wrapper.hidden = isStandalone;
    sourceDocument.required = !isStandalone;
    sourceDocument.disabled = isStandalone;
    if (isStandalone) {
      $(sourceDocument).val(null).trigger('change');
    }
  }

  function reindexProductionOrderLines(form) {
    form.querySelectorAll('[data-production-order-line]').forEach(function (row, index) {
      row.dataset.index = index;
      row.setAttribute('aria-label', `${form.querySelector('[data-line-card-editor]')?.dataset.lineLabel || ''} ${index + 1}`.trim());
      const number = row.querySelector('[data-row-number]');
      if (number) {
        number.textContent = index + 1;
      }
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/lines\[(?:\d+|__INDEX__)\]/, `lines[${index}]`);
      });
      const product = row.querySelector('[name$="[source_line_reference]"]');
      const stages = row.querySelector('[name$="[stage_public_ids][]"]');
      if (product) {
        product.id = `production-order-line-product-${index}`;
      }
      if (stages && product) {
        stages.dataset.extraParams = JSON.stringify({
          source_type: '#production-source-type',
          source_doc_num: '#production-source-document',
          source_line_reference: `#${product.id}`,
        });
      }
    });
  }

  function addProductionOrderLine(form, values, afterRow, focus) {
    const body = form.querySelector('[data-production-order-lines]');
    const template = document.getElementById('production-order-line-template');
    if (!body || !(template instanceof HTMLTemplateElement)) {
      return null;
    }

    const fragment = template.content.cloneNode(true);
    if (afterRow && afterRow.parentElement === body) {
      body.insertBefore(fragment, afterRow.nextSibling);
    } else {
      body.appendChild(fragment);
    }
    reindexProductionOrderLines(form);
    const row = afterRow && afterRow.parentElement === body ? afterRow.nextElementSibling : body.lastElementChild;
    const product = row.querySelector('[name$="[source_line_reference]"]');
    if (values && values.source_line_reference && product) {
      product.appendChild(new Option(values.product_text || values.source_line_reference, values.source_line_reference, true, true));
    }
    ['quantity', 'description', 'production_notes'].forEach(function (name) {
      const field = row.querySelector(`[name$="[${name}]"]`);
      if (field && values && values[name] !== null && values[name] !== undefined) {
        field.value = values[name];
      }
    });
    const stages = row.querySelector('[name$="[stage_public_ids][]"]');
    if (stages && Array.isArray(values?.stages)) {
      values.stages.forEach(function (stage) {
        if (stage?.id) {
          stages.appendChild(new Option(stage.text || stage.id, stage.id, true, true));
        }
      });
    }
    initializeWorkflowSelects(row);
    if (window.AppNumbers && typeof window.AppNumbers.refresh === 'function') {
      window.AppNumbers.refresh(row);
    }
    if (focus !== false) {
      row.querySelector('select:not([disabled]), input:not([type="hidden"]):not([disabled]), textarea:not([disabled])')?.focus();
    }

    return row;
  }

  function loadConfiguredProductionStages(row) {
    const product = row?.querySelector('[name$="[source_line_reference]"]');
    const stages = row?.querySelector('[name$="[stage_public_ids][]"]');
    if (!product || !stages) {
      return;
    }

    $(stages).val(null).trigger('change');
    stages.replaceChildren();
    if (!product.value || !stages.dataset.url) {
      return;
    }

    $.getJSON(stages.dataset.url, {
      source_type: document.querySelector('#production-source-type')?.value || 'make_to_stock',
      source_doc_num: document.querySelector('#production-source-document')?.value || '',
      source_line_reference: product.value,
      page: 1,
    }).done(function (response) {
      (response.results || []).forEach(function (stage) {
        stages.appendChild(new Option(stage.text, stage.id, true, true));
      });
      $(stages).trigger('change');
    });
  }

  function duplicateProductionOrderLine(row) {
    const form = row.closest('[data-production-order-form]');
    if (!form) {
      return;
    }

    const values = {};
    ['quantity', 'description', 'production_notes'].forEach(function (fieldName) {
      values[fieldName] = row.querySelector(`[name$="[${fieldName}]"]`)?.value || '';
    });
    addProductionOrderLine(form, values, row, true);
  }

  function removeProductionOrderLine(row) {
    const form = row.closest('[data-production-order-form]');
    if (!form) {
      return;
    }

    const rows = form.querySelectorAll('[data-production-order-line]');
    if (rows.length === 1) {
      $(row).find('select').val(null).trigger('change');
      row.querySelectorAll('input:not([type="hidden"]), textarea').forEach(function (field) { field.value = ''; });
      return;
    }

    $(row).find('select.select2-hidden-accessible').each(function () { $(this).select2('destroy'); });
    row.remove();
    reindexProductionOrderLines(form);
  }

  function productionOrderShortcutBlocked(target) {
    return $(target).hasClass('select2-search__field') || $('.select2-container--open').length > 0;
  }

  function isProductionOrderAltShortcut(event, codes, keyCodes, legacyKeys) {
    if (window.AppShortcuts && typeof window.AppShortcuts.isAltShortcut === 'function') {
      return !event.ctrlKey && window.AppShortcuts.isAltShortcut(event, codes, keyCodes, legacyKeys);
    }

    return event.altKey === true
      && !event.ctrlKey
      && !event.metaKey
      && !event.shiftKey
      && (codes.includes(event.code || '') || keyCodes.includes(Number(event.keyCode || event.which || 0)) || legacyKeys.includes(String(event.key || '').toLowerCase()));
  }

  function initializeProductionOrderShortcuts(form) {
    $(document).off('keydown.productionOrderLines').on('keydown.productionOrderLines', function (event) {
      if (productionOrderShortcutBlocked(event.target)) {
        return;
      }

      const row = event.target.closest?.('[data-production-order-line]');
      const isDelete = event.altKey === true && !event.ctrlKey && !event.metaKey && !event.shiftKey
        && (event.key === 'Delete' || event.code === 'Delete' || Number(event.keyCode || event.which || 0) === 46);

      if (isDelete && row) {
        event.preventDefault();
        event.stopPropagation();
        removeProductionOrderLine(row);
        return;
      }

      if (isProductionOrderAltShortcut(event, ['KeyD'], [68], ['d']) && row) {
        event.preventDefault();
        event.stopPropagation();
        duplicateProductionOrderLine(row);
        return;
      }

      if (!isProductionOrderAltShortcut(event, ['KeyN'], [78], ['n'])) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      addProductionOrderLine(form, {}, row || null, true);
    });
  }

  function initializeProductionOrderForm() {
    const form = document.querySelector('[data-production-order-form]');
    if (!form || form.dataset.productionOrderInitialized === '1') {
      return;
    }
    form.dataset.productionOrderInitialized = '1';

    const dataElement = document.querySelector('[data-production-order-initial-lines]');
    let lines = [{}];
    try {
      lines = JSON.parse(dataElement?.textContent || '[{}]');
    } catch (error) {
      lines = [{}];
    }
    (lines.length > 0 ? lines : [{}]).forEach(function (line) {
      addProductionOrderLine(form, line, null, false);
    });
    productionOrderSourceVisibility();
    initializeProductionOrderShortcuts(form);
  }

  function clearProductionSourceLines() {
    $('[data-production-order-form] [name$="[source_line_reference]"]').val(null).trigger('change');
  }

  $(document).on('change', '#production-source-type', function () {
    productionOrderSourceVisibility();
    clearProductionSourceLines();
  });

  $(document).on('change', '#production-source-document', clearProductionSourceLines);

  $(document).on('change', '[data-production-order-line] [name$="[source_line_reference]"]', function () {
    loadConfiguredProductionStages(this.closest('[data-production-order-line]'));
  });

  $(document).on('click', '[data-production-order-form] [data-add-production-line]', function () {
    const form = this.closest('[data-production-order-form]');
    const activeRow = document.activeElement?.closest?.('[data-production-order-line]');
    addProductionOrderLine(form, {}, activeRow, true);
  });

  $(document).on('click', '[data-production-order-form] [data-duplicate-production-line]', function () {
    duplicateProductionOrderLine(this.closest('[data-production-order-line]'));
  });

  $(document).on('click', '[data-production-order-form] [data-remove-production-line]', function () {
    removeProductionOrderLine(this.closest('[data-production-order-line]'));
  });

  function reindexLaborRows(container) {
    container.querySelectorAll('[data-labor-row]').forEach(function (row, index) {
      row.dataset.index = index;
      const number = row.querySelector('[data-labor-row-number]');
      if (number) {
        number.textContent = index + 1;
      }
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/labor_details\[(?:\d+|__INDEX__)\]/, `labor_details[${index}]`);
      });
    });
  }

  function addLaborRow(container, values, afterRow, focus) {
    const rows = container && container.querySelector('[data-labor-rows]');
    const template = container && (container.querySelector('[data-labor-row-template]') || document.getElementById('production-labor-row-template'));
    if (!rows || !(template instanceof HTMLTemplateElement)) {
      return null;
    }

    const fragment = template.content.cloneNode(true);
    if (afterRow && afterRow.parentElement === rows) {
      rows.insertBefore(fragment, afterRow.nextSibling);
    } else {
      rows.appendChild(fragment);
    }
    reindexLaborRows(container);
    const row = afterRow && afterRow.parentElement === rows ? afterRow.nextElementSibling : rows.lastElementChild;
    const employee = row.querySelector('[name$="[employee_doc_num]"], [name$="[employee_id]"]');
    const employeeValue = values && (values.employee_doc_num || values.employee_id);
    if (employee && employeeValue) {
      employee.appendChild(new Option(values.name || values.employee_text || employeeValue, employeeValue, true, true));
    }
    ['role', 'planned_hours', 'actual_hours', 'notes'].forEach(function (fieldName) {
      const field = row.querySelector(`[name$="[${fieldName}]"]`);
      if (field && values && values[fieldName] !== null && values[fieldName] !== undefined) {
        field.value = values[fieldName];
      }
    });
    initializeWorkflowSelects(row);
    if (window.AppNumbers && typeof window.AppNumbers.refresh === 'function') {
      window.AppNumbers.refresh(row);
    }
    if (focus !== false) {
      row.querySelector('select:not([disabled]), input:not([disabled]), textarea:not([disabled])')?.focus();
    }

    return row;
  }

  function duplicateLaborRow(row) {
    const container = row.closest('[data-production-labor-planning]');
    const values = {};
    const employee = row.querySelector('[name$="[employee_doc_num]"], [name$="[employee_id]"]');
    if (employee) {
      const selected = employee.options?.[employee.selectedIndex];
      if (employee.name.endsWith('[employee_doc_num]')) {
        values.employee_doc_num = employee.value;
      } else {
        values.employee_id = employee.value;
      }
      values.employee_text = selected?.text || '';
    }
    ['role', 'planned_hours', 'actual_hours', 'notes'].forEach(function (fieldName) {
      values[fieldName] = row.querySelector(`[name$="[${fieldName}]"]`)?.value || '';
    });
    addLaborRow(container, values, row, true);
  }

  function removeLaborRow(row) {
    const container = row && row.closest('[data-production-labor-planning]');
    const rows = container && container.querySelectorAll('[data-labor-row]');
    if (!container || !row || !rows) {
      return;
    }

    if (rows.length === 1) {
      $(row).find('select').val(null).trigger('change');
      row.querySelectorAll('input:not([type="hidden"]), textarea').forEach(function (field) { field.value = ''; });
    } else {
      $(row).find('select.select2-hidden-accessible').each(function () { $(this).select2('destroy'); });
      row.remove();
    }
    reindexLaborRows(container);
  }

  $(document).on('click', '[data-add-labor-row]', function () {
    const container = this.closest('[data-production-labor-planning]');
    const activeRow = document.activeElement?.closest?.('[data-labor-row]');
    addLaborRow(container, {}, activeRow, true);
  });

  $(document).on('click', '[data-duplicate-labor-row]', function () {
    duplicateLaborRow(this.closest('[data-labor-row]'));
  });

  $(document).on('click', '[data-remove-labor-row]', function () {
    removeLaborRow(this.closest('[data-labor-row]'));
  });

  function initializeProductionLaborPlanning() {
    const form = document.querySelector('[data-production-run-plan-form]');
    const container = form && form.querySelector('[data-production-labor-planning]');
    if (!container || container.dataset.laborPlanningInitialized === '1') {
      return;
    }
    container.dataset.laborPlanningInitialized = '1';
    let details = [{}];
    try {
      details = JSON.parse(document.querySelector('[data-production-labor-initial]')?.textContent || '[{}]');
    } catch (error) {
      details = [{}];
    }
    (details.length > 0 ? details : [{}]).forEach(function (labor) {
      addLaborRow(container, labor, null, false);
    });
    $(document).off('keydown.productionLaborLines').on('keydown.productionLaborLines', function (event) {
      if (productionOrderShortcutBlocked(event.target)) {
        return;
      }
      const row = event.target.closest?.('[data-production-run-plan-form] [data-labor-row]');
      const isDelete = event.altKey === true && !event.ctrlKey && !event.metaKey && !event.shiftKey
        && (event.key === 'Delete' || event.code === 'Delete' || Number(event.keyCode || event.which || 0) === 46);
      if (isDelete && row) {
        event.preventDefault();
        removeLaborRow(row);
      } else if (isProductionOrderAltShortcut(event, ['KeyD'], [68], ['d']) && row) {
        event.preventDefault();
        duplicateLaborRow(row);
      } else if (isProductionOrderAltShortcut(event, ['KeyN'], [78], ['n'])) {
        event.preventDefault();
        addLaborRow(container, {}, row || null, true);
      }
    });
  }

  function reindexMaintenanceMaterialRows(container) {
    container.querySelectorAll('[data-maintenance-material-row]').forEach(function (row, index) {
      const number = row.querySelector('[data-row-number]');
      if (number) {
        number.textContent = index + 1;
      }
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/lines\[(?:\d+|__INDEX__)\]/, `lines[${index}]`);
      });
    });
  }

  function addMaintenanceMaterialRow(form, values, afterRow, focus) {
    const rows = form && form.querySelector('[data-maintenance-material-lines]');
    const template = document.getElementById('maintenance-material-line-template');
    if (!rows || !(template instanceof HTMLTemplateElement)) {
      return null;
    }
    const fragment = template.content.cloneNode(true);
    if (afterRow && afterRow.parentElement === rows) {
      rows.insertBefore(fragment, afterRow.nextSibling);
    } else {
      rows.appendChild(fragment);
    }
    reindexMaintenanceMaterialRows(form);
    const row = afterRow && afterRow.parentElement === rows ? afterRow.nextElementSibling : rows.lastElementChild;
    ['product_id', 'item_type', 'quantity', 'notes'].forEach(function (name) {
      const field = row.querySelector(`[name$="[${name}]"]`);
      if (field && values && values[name] !== null && values[name] !== undefined) {
        field.value = values[name];
      }
    });
    initializeWorkflowSelects(row);
    if (window.AppNumbers && typeof window.AppNumbers.refresh === 'function') {
      window.AppNumbers.refresh(row);
    }
    if (focus !== false) {
      row.querySelector('select:not([disabled]), input:not([type="hidden"]):not([disabled])')?.focus();
    }

    return row;
  }

  $(document).on('click', '[data-add-maintenance-material]', function () {
    const form = this.closest('[data-maintenance-material-form]');
    const activeRow = document.activeElement?.closest?.('[data-maintenance-material-row]');
    addMaintenanceMaterialRow(form, {}, activeRow, true);
  });

  $(document).on('click', '[data-duplicate-maintenance-material]', function () {
    const row = this.closest('[data-maintenance-material-row]');
    const form = row && row.closest('[data-maintenance-material-form]');
    const values = {};
    ['product_id', 'item_type', 'quantity', 'notes'].forEach(function (name) {
      values[name] = row.querySelector(`[name$="[${name}]"]`)?.value || '';
    });
    addMaintenanceMaterialRow(form, values, row, true);
  });

  $(document).on('click', '[data-remove-maintenance-material]', function () {
    const form = this.closest('[data-maintenance-material-form]');
    const row = this.closest('[data-maintenance-material-row]');
    if (!form || !row) {
      return;
    }
    if (form.querySelectorAll('[data-maintenance-material-row]').length === 1) {
      row.querySelectorAll('input, select').forEach(function (field) { field.value = field.name.endsWith('[item_type]') ? 'spare_part' : ''; });
    } else {
      $(row).find('select.select2-hidden-accessible').each(function () { $(this).select2('destroy'); });
      row.remove();
    }
    reindexMaintenanceMaterialRows(form);
  });

  function initializeMaintenanceMaterialForm() {
    const form = document.querySelector('[data-maintenance-material-form]');
    if (!form || form.dataset.initialized === '1') {
      return;
    }
    form.dataset.initialized = '1';
    let lines = [{}];
    try {
      lines = JSON.parse(document.querySelector('[data-maintenance-material-initial-lines]')?.textContent || '[{}]');
    } catch (error) {
      lines = [{}];
    }
    (lines.length ? lines : [{}]).forEach(function (line) { addMaintenanceMaterialRow(form, line, null, false); });
  }

  function updateMaintenanceOrderFields() {
    const form = document.querySelector('[data-maintenance-order-form]');
    const mode = form && form.querySelector('[name="service_mode"]');
    if (!mode) {
      return;
    }
    const external = ['external', 'mixed'].includes(mode.value);
    form.querySelectorAll('[data-external-maintenance-fields]').forEach(function (field) { field.hidden = !external; });
  }

  function updateMaintenanceExpenseFields() {
    const form = document.querySelector('[data-maintenance-expense-form]');
    const channel = form && form.querySelector('[name="payment_channel"]');
    if (!channel) {
      return;
    }
    const isCashbox = channel.value === 'cashbox';
    form.querySelectorAll('[data-maintenance-cashbox-field]').forEach(function (field) {
      field.hidden = !isCashbox;
      field.querySelectorAll('select, input').forEach(function (input) { input.required = isCashbox; input.disabled = !isCashbox; });
    });
    form.querySelectorAll('[data-maintenance-bank-field]').forEach(function (field) {
      field.hidden = isCashbox;
      field.querySelectorAll('select, input').forEach(function (input) { input.required = !isCashbox; input.disabled = isCashbox; });
    });
  }

  $(document).on('change', '[data-maintenance-order-form] [name="service_mode"]', updateMaintenanceOrderFields);
  $(document).on('change', '[data-maintenance-expense-form] [name="payment_channel"]', updateMaintenanceExpenseFields);

  $(function () {
    initializeWorkflowSelects(document);
    initializeTables();
    initializeRowNavigation();
    filterStageOptions();
    updateAdditionalMaterialForm(false);
    updateProductionPaymentFields();
    filterQualityCheckpoints();
    updateQualityDisposition();
    updateQualitySubjectFields();
    initializeProductionOrderForm();
    initializeProductionLaborPlanning();
    initializeMaintenanceMaterialForm();
    updateMaintenanceOrderFields();
    updateMaintenanceExpenseFields();
    initializeProductionOrderDocumentNumberSettings();
  });
})(window.jQuery, window, document);
