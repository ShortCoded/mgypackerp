(function ($, window, document) {
  'use strict';

  const messages = window.accountMessages || {};
  const derivedDefaults = window.accountDerivedDefaults || {};
  const selected = new Set();
  let table = null;
  let treeVisible = false;

  function csrf() {
    return $('meta[name="csrf-token"]').attr('content');
  }

  function msg(key) {
    return messages[key] || '';
  }

  function toast(icon, title) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, title);
    }
  }

  function confirmDialog(title, text, confirmText) {
    if (!window.Swal) {
      return $.Deferred().resolve({ isConfirmed: false }).promise();
    }

    return Swal.fire({
      icon: 'warning',
      title: title,
      text: text,
      showCancelButton: true,
      showCloseButton: true,
      focusCancel: true,
      allowEscapeKey: true,
      confirmButtonText: confirmText,
      cancelButtonText: msg('cancel')
    });
  }

  function refreshBulkBar() {
    $('#bulk_selected_count').text(selected.size);
    const $applyButton = $('#bulk_action_apply');
    const applyLabel = $applyButton.data('label') || '';

    $applyButton
      .prop('disabled', selected.size < 1)
      .find('span:last')
      .text(applyLabel + (selected.size > 0 ? ' (' + selected.size + ')' : ''));
    $('#bulk_actions_bar').toggleClass('d-none', selected.size < 1).toggleClass('d-flex', selected.size > 0);

    if (selected.size < 1) {
      $('#bulk_action_select').val('delete');
    }
  }

  function initTable() {
    const $table = $('#accounts-table');

    if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
      return;
    }

    const options = window.AppDataTables && typeof window.AppDataTables.options === 'function' ? window.AppDataTables.options : function (config) { return config; };
    const protectedColumns = [0, 1, -1];
    const responsiveControlTarget = 1;
    const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-account-row-checkbox';
    const selectAllSelector = '#select_all_records';
    const trashFilterSelector = '#accounts_trash_filter';

    function checkboxDocNum(checkbox) {
      return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
    }

    function pageCheckboxes(api) {
      return api && typeof api.rows === 'function'
        ? $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-account-row-checkbox')
        : $table.find(rowCheckboxSelector);
    }

    function trashFilterValue() {
      const value = String($(trashFilterSelector).val() || 'active');

      return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
    }

    function protectStateColumns(data) {
      if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
        window.AppDataTables.protectStateColumns(data, protectedColumns);
      }
    }

    function patchResponsiveControlTarget(api) {
      const settings = api && typeof api.settings === 'function' ? api.settings()[0] : null;
      const responsive = settings && settings._responsive ? settings._responsive : null;

      if (!responsive || responsive._accountsControlTargetPatched) {
        return;
      }

      responsive.c.details.target = responsiveControlTarget;
      responsive._accountsControlTargetPatched = true;
      responsive._controlClass = function () {
        const dt = this.s.dt;

        dt.cells(null, function (index) {
          return index !== responsiveControlTarget;
        }, { page: 'current' }).nodes().to$().filter('.dtr-control').removeClass('dtr-control').removeAttr('tabindex').removeData('dtr-keyboard');
        dt.cells(null, responsiveControlTarget, { page: 'current' }).nodes().to$().addClass('dtr-control');
        this._tabIndexes();
      };
    }

    function syncResponsiveControlColumn(api) {
      patchResponsiveControlTarget(api);

      const $rows = api && typeof api.rows === 'function' ? $(api.rows({ page: 'current' }).nodes()) : $table.find('tbody tr:not(.child)');
      const actionColumnIndex = api && typeof api.columns === 'function'
        ? api.columns().indexes().toArray().length - 1
        : $table.find('thead th').length - 1;

      $table.find('thead th').eq(0).removeClass('dtr-control');
      $table.find('thead th').eq(actionColumnIndex).removeClass('dtr-control');
      $table.find('thead th').eq(responsiveControlTarget).addClass('dtr-control');
      $rows.each(function () {
        const $cells = $(this).children('td, th');

        $cells.eq(0).removeClass('dtr-control').removeAttr('tabindex').removeData('dtr-keyboard');
        $cells.eq(actionColumnIndex).removeClass('dtr-control').removeAttr('tabindex').removeData('dtr-keyboard');
        $cells.eq(responsiveControlTarget).addClass('dtr-control');
      });
    }

    function queueResponsiveControlSync(api) {
      syncResponsiveControlColumn(api);
      window.requestAnimationFrame(function () { syncResponsiveControlColumn(api); });
      window.setTimeout(function () { syncResponsiveControlColumn(api); }, 50);
    }

    function updateSelectAllState(api) {
      const selectableDocNums = pageCheckboxes(api).map(function () {
        return checkboxDocNum(this);
      }).get().filter(function (docNum) {
        return docNum !== '';
      });
      const checkedOnPage = selectableDocNums.filter(function (docNum) {
        return selected.has(docNum);
      }).length;

      $(selectAllSelector)
        .prop('checked', selectableDocNums.length > 0 && checkedOnPage === selectableDocNums.length)
        .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < selectableDocNums.length);
    }

    function restoreSelectionState(api) {
      syncResponsiveControlColumn(api);
      pageCheckboxes(api).each(function () {
        const docNum = checkboxDocNum(this);
        $(this).prop('checked', docNum !== '' && selected.has(docNum));
      });
      updateSelectAllState(api);
      refreshBulkBar();
    }

    function clearSelection(api) {
      selected.clear();
      pageCheckboxes(api).prop('checked', false);
      updateSelectAllState(api);
      refreshBulkBar();
    }

    table = $table.DataTable(options({
      ajax: {
        url: $table.data('url') || $table.data('ajax-url'),
        data: function (data) {
          data.trash_filter = trashFilterValue();
          $.extend(data, filterData());
        }
      },
      processing: true,
      serverSide: true,
      stateSave: true,
      stateLoadParams: function (settings, data) { protectStateColumns(data); },
      stateSaveParams: function (settings, data) { protectStateColumns(data); },
      responsive: { details: { type: 'inline', target: responsiveControlTarget } },
      columns: [
        { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
        { data: 'doc_num', name: 'accounts.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
        { data: 'account_code', name: 'account_code', className: 'align-middle white-space-nowrap dt-code' },
        { data: 'name', name: 'name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'parent', name: 'parent', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'classification', name: 'classification', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'statement_type', name: 'statement_type', className: 'align-middle white-space-nowrap' },
        { data: 'normal_balance', name: 'normal_balance', className: 'align-middle white-space-nowrap' },
        { data: 'status', name: 'status', className: 'align-middle white-space-nowrap' },
        { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
        { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
        { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
        { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap text-end all no-colvis data-table-row-action dt-actions' }
      ],
      columnDefs: [
        { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
        { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
        { className: 'dt-actions no-colvis all data-table-row-action text-end', orderable: false, responsivePriority: 3, searchable: false, targets: -1 },
        { responsivePriority: 10, targets: [2, 3, 4, 5] },
        { responsivePriority: 20, targets: [6, 7, 8] },
        { responsivePriority: 30, targets: [9, 10, 11, 12] }
      ],
      order: [[2, 'asc']],
      createdRow: function (row) { $(row).addClass('btn-reveal-trigger'); },
      initComplete: function () {
        restoreSelectionState(this.api());
      },
      drawCallback: function () {
        restoreSelectionState(this.api());
        if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
          window.AppDataTables.applyFalconEnhancements(document);
        }
      }
    }));

    patchResponsiveControlTarget(table);
    queueResponsiveControlSync(table);

    table.off('draw.dt.accountsResponsive column-visibility.dt.accountsResponsive column-sizing.dt.accountsResponsive responsive-resize.dt.accountsResponsive')
      .on('draw.dt.accountsResponsive column-visibility.dt.accountsResponsive column-sizing.dt.accountsResponsive responsive-resize.dt.accountsResponsive', function () {
        queueResponsiveControlSync(table);
      });

    $(trashFilterSelector).off('change.accountsTrashFilter').on('change.accountsTrashFilter', function () {
      clearSelection(table);
      reloadTable();
    });

    $(selectAllSelector).off('change.accountsSelect').on('change.accountsSelect', function () {
      const checked = $(this).is(':checked');

      pageCheckboxes(table).each(function () {
        const docNum = checkboxDocNum(this);
        if (docNum === '') { return; }
        if (checked) { selected.add(docNum); } else { selected.delete(docNum); }
        $(this).prop('checked', checked);
      });

      updateSelectAllState(table);
      refreshBulkBar();
    });

    $table.off('click.accountsSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.accountsSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
      if ($(event.target).closest('input, label, button, a').length > 0) {
        return;
      }

      const $checkbox = $(this).find('input.js-record-select, input.js-account-row-checkbox').first();
      event.preventDefault();
      event.stopPropagation();

      if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
        return;
      }

      $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
    });

    $table.off('dblclick.accountsEditRow', 'tbody tr:not(.child)').on('dblclick.accountsEditRow', 'tbody tr:not(.child)', function (event) {
      if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
        return;
      }

      const editLink = $(this).find('.js-edit-record').get(0);
      if (editLink) { editLink.click(); }
    });

    $(document).off('click.accountsSelectStop mousedown.accountsSelectStop mouseup.accountsSelectStop', '.js-record-select, #select_all_records, td.dt-select')
      .on('click.accountsSelectStop mousedown.accountsSelectStop mouseup.accountsSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
        event.stopPropagation();
      });

    $table.off('change.accountsSelect', rowCheckboxSelector).on('change.accountsSelect', rowCheckboxSelector, function () {
      const docNum = checkboxDocNum(this);
      if (docNum === '') { return; }
      if ($(this).is(':checked')) { selected.add(docNum); } else { selected.delete(docNum); }
      updateSelectAllState(table);
      refreshBulkBar();
    });

    $(document).off('accounts:deleted.accountsTable accounts:restored.accountsTable').on('accounts:deleted.accountsTable accounts:restored.accountsTable', function (event, docNum) {
      if (docNum) {
        selected.delete(docNum);
      }

      reloadTable();
      updateSelectAllState(table);
      refreshBulkBar();
    });

    $('#bulk_action_apply').off('click.accountsBulk').on('click.accountsBulk', function () {
      const docNums = Array.from(selected);

      if (docNums.length === 0 || $('#bulk_action_select').val() !== 'delete') {
        refreshBulkBar();
        return;
      }

      confirmDialog(msg('bulkDeleteConfirmTitle'), msg('bulkDeleteConfirmText').replace(':count', docNums.length), msg('bulkDeleteConfirmYes')).then(function (result) {
        if (!result.isConfirmed) {
          return;
        }
        request($table.data('bulk-delete-url'), 'DELETE', { doc_nums: docNums }).done(function (response) {
          toast('success', response.message);
          clearSelection(table);
          reloadTable();
        }).fail(function (xhr) {
          toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
        });
      });
    });
  }

  function request(url, method, data) {
    return $.ajax({
      url: url,
      method: method,
      data: data,
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }
    });
  }

  function reloadTable() {
    if (table) {
      table.ajax.reload(null, false);
    }
  }

  function filterData() {
    if (window.AppReportUI && typeof window.AppReportUI.filterData === 'function') {
      return window.AppReportUI.filterData({ filterSelector: '.js-report-filters' });
    }

    const data = {};
    $('.js-report-filters').serializeArray().forEach(function (field) {
      const value = String(field.value || '').trim();
      if (value !== '') {
        data[field.name] = value;
      }
    });

    return data;
  }

  function filteredUrl(url) {
    const query = $.param(filterData());

    return url + (query ? (url.indexOf('?') === -1 ? '?' : '&') + query : '');
  }

  function reloadTree() {
    const $tree = $('[data-accounts-tree]');

    if (!$tree.length || !treeVisible) {
      return;
    }

    $('[data-accounts-tree-list]').html('<div class="text-center text-600 py-4"><span class="fas fa-spinner fa-spin me-1"></span></div>');

    $.getJSON(filteredUrl($tree.data('url'))).done(function (payload) {
      const $treeList = $('[data-accounts-tree-list]');

      $treeList.html(renderTree(payload.data || []));
      initAccountTree($treeList);
    }).fail(function () {
      $('[data-accounts-tree-list]').html('<div class="text-danger p-3">' + $('<div>').text(msg('unexpectedError')).html() + '</div>');
    });
  }

  function reloadCurrentView() {
    reloadTable();
    reloadTree();
  }

  function clearValidation($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('[data-error-for]').text('');
    $form.find('[data-form-alert]').empty();
    $form.find('.js-account-alert').addClass('d-none').removeClass('alert-danger alert-warning alert-success alert-info');
    $form.find('.js-account-alert-message').empty();
  }

  function showFormNotice($form, type, messagesList) {
    const $alert = $form.find('.js-account-alert');
    const $message = $form.find('.js-account-alert-message');
    const messagesArray = Array.isArray(messagesList) ? messagesList : [messagesList];
    const cleanMessages = messagesArray.filter(function (message) { return Boolean(message); });

    if (!$alert.length || cleanMessages.length === 0) {
      return;
    }

    const className = type === 'danger' ? 'alert-danger' : (type === 'warning' ? 'alert-warning' : (type === 'success' ? 'alert-success' : 'alert-info'));
    const content = cleanMessages.length > 1
      ? '<ul class="mb-0 ps-3">' + cleanMessages.map(function (message) { return '<li>' + $('<div>').text(message).html() + '</li>'; }).join('') + '</ul>'
      : $('<div>').text(cleanMessages[0]).html();

    $alert.removeClass('d-none alert-danger alert-warning alert-success alert-info').addClass(className);
    $message.html(content);
  }

  function renderValidation($form, xhr) {
    const errors = xhr.responseJSON && xhr.responseJSON.errors ? xhr.responseJSON.errors : {};
    const list = [];

    $.each(errors, function (field, fieldMessages) {
      const $field = $form.find('[name="' + field + '"]');
      $field.addClass('is-invalid');
      $form.find('[data-error-for="' + field + '"]').text(fieldMessages[0] || '');
      (fieldMessages || []).forEach(function (fieldMessage) {
        list.push(fieldMessage);
      });
    });

    showFormNotice($form, 'danger', list.length ? list : [msg('validationFailed')]);
  }

  function formFieldValue($form, name) {
    const $fields = $form.find('[name="' + name + '"]');
    const $checkbox = $fields.filter('[type="checkbox"]').first();

    if (!$fields.length) {
      return '';
    }

    if ($checkbox.length) {
      return $checkbox.is(':checked');
    }

    return String($fields.first().val() || '');
  }

  function formSnapshot($form) {
    return {
      doc_number: formFieldValue($form, 'doc_number'),
      account_code: formFieldValue($form, 'account_code'),
      name: formFieldValue($form, 'name'),
      parent_doc_num: formFieldValue($form, 'parent_doc_num'),
      classification_code: formFieldValue($form, 'classification_code'),
      account_type: formFieldValue($form, 'account_type'),
      statement_type: formFieldValue($form, 'statement_type'),
      normal_balance: formFieldValue($form, 'normal_balance'),
      status: formFieldValue($form, 'status'),
      is_group: formFieldValue($form, 'is_group'),
      is_postable: formFieldValue($form, 'is_postable'),
      notes: formFieldValue($form, 'notes')
    };
  }

  function normalizeSnapshot(snapshot) {
    const normalized = $.extend({}, snapshot || {});

    ['is_group', 'is_postable'].forEach(function (field) {
      normalized[field] = normalized[field] === true
        || normalized[field] === 1
        || normalized[field] === '1'
        || normalized[field] === 'true';
    });

    Object.keys(normalized).forEach(function (field) {
      if (typeof normalized[field] === 'string') {
        normalized[field] = normalized[field].trim();
      }
    });

    return normalized;
  }

  function snapshotChanged($form) {
    if ($form.data('mode') !== 'edit') {
      return true;
    }

    const original = normalizeSnapshot($form.data('original') || {});
    const current = normalizeSnapshot(formSnapshot($form));

    return JSON.stringify(original) !== JSON.stringify(current);
  }

  function updateOriginalSnapshot($form, response) {
    const snapshot = formSnapshot($form);
    const data = response && response.data ? response.data : {};

    if (data.doc_number !== undefined) {
      snapshot.doc_number = String(data.doc_number || '');
    }

    $form.data('original', normalizeSnapshot(snapshot));
  }

  function clearSelect2Field($field) {
    if (!$field.length) {
      return;
    }

    $field.val(null).trigger('change');
  }

  function resetCreateForm($form) {
    $form.find('[name="doc_number"], [name="account_code"], [name="name"], [name="name_en"], [name="notes"]').val('');
    clearSelect2Field($form.find('[name="parent_doc_num"]'));
    clearSelect2Field($form.find('[name="classification_code"]'));
    $form.find('[name="account_type"]').val('asset');
    $form.find('[name="statement_type"]').val('financial_position');
    $form.find('#statement_type_display').val('financial_position');
    $form.find('[name="normal_balance"]').val('debit');
    $form.find('[name="status"]').val('active');
    $form.find('[name="is_group"][type="checkbox"]').prop('checked', false);
    $form.find('[name="is_postable"][type="checkbox"]').prop('checked', true);
    $form.find('input[name="submit_action"]').val('save');
    $form.find('input[name="clone_source_token"]').remove();
    $form.data('mode', 'create');
    updateOriginalSnapshot($form, {});
  }

  function updateFormUrls($form, response) {
    const urls = response && response.data && response.data.urls ? response.data.urls : null;

    if (!urls) {
      return;
    }

    if (urls.update) {
      $form.attr('action', urls.update);
    }

    $form.find('[data-delete-url]').attr('data-delete-url', urls.destroy).data('delete-url', urls.destroy);

    if (urls.edit && window.history && window.location.href !== urls.edit && $form.data('mode') === 'edit') {
      window.history.replaceState({}, '', urls.edit);
    }
  }

  function applyDerivedFields($form, data) {
    const accountType = String(data.account_type || $form.find('[name="account_type"]').val() || 'asset');
    const statementType = String(data.statement_type || (derivedDefaults.statementByType && derivedDefaults.statementByType[accountType]) || 'financial_position');
    const normalBalance = String(data.normal_balance || (derivedDefaults.normalBalanceByType && derivedDefaults.normalBalanceByType[accountType]) || 'debit');

    $form.find('[name="account_type"]').val(accountType);
    $form.find('[name="statement_type"]').val(statementType);
    $form.find('#statement_type_display').val(statementType);
    $form.find('[name="normal_balance"]').val(normalBalance);
    applyParentClassification($form, data);
  }

  function applyParentClassification($form, data) {
    const $classification = $form.find('[name="classification_code"]');
    const classificationCode = String(data.classification_code || '').trim();
    const expenses = derivedDefaults.expensesClassification || {};
    const expensesCode = String(expenses.code || 'expenses');

    if (!$classification.length) {
      return;
    }

    $classification.data('expenses-locked', classificationCode === expensesCode);

    if (!classificationCode) {
      return;
    }

    const classificationText = String(data.classification_text || (classificationCode === expensesCode ? expenses.label : classificationCode) || classificationCode);

    if (!$classification.find('option[value="' + classificationCode.replace(/"/g, '\\"') + '"]').length) {
      $classification.append(new Option(classificationText, classificationCode, true, true));
    }

    $classification.val(classificationCode).trigger('change.select2');
  }

  function applyRootDerivedFields($form, selectedData) {
    const parentSelected = String($form.find('[name="parent_doc_num"]').val() || '').trim() !== '';

    if (parentSelected) {
      return;
    }

    applyDerivedFields($form, selectedData || {});
  }

  initTable();

  if (window.AppReportUI && typeof window.AppReportUI.init === 'function') {
    window.AppReportUI.init(document);
  }

  $(document).off('submit.accountsReportFilters', '.js-report-filters').on('submit.accountsReportFilters', '.js-report-filters', function (event) {
    event.preventDefault();
    reloadCurrentView();
  });

  $(document).off('change.accountsReportFilters', '.js-report-filter-control').on('change.accountsReportFilters', '.js-report-filter-control', function () {
    reloadCurrentView();
  });

  $(document).off('click.accountsReportReset', '.js-report-reset').on('click.accountsReportReset', '.js-report-reset', function () {
    const $form = $('.js-report-filters');

    if (window.AppReportUI && typeof window.AppReportUI.resetFilters === 'function') {
      window.AppReportUI.resetFilters($form);
    } else if ($form.length) {
      $form.get(0).reset();
      $form.find('.js-select2-ajax').val(null).trigger('change.select2');
    }

    reloadCurrentView();
  });

  $(document).off('click.accountsReportRefresh', '.js-report-refresh').on('click.accountsReportRefresh', '.js-report-refresh', function () {
    reloadCurrentView();
  });

  $(document).off('click.accountsReportExport', '.js-report-export').on('click.accountsReportExport', '.js-report-export', function (event) {
    event.preventDefault();

    const $link = $(this);
    const url = filteredUrl($link.attr('href'));

    if ($link.data('open-in-new-tab') || $link.attr('target') === '_blank') {
      window.open(url, '_blank', 'noopener');
      return;
    }

    window.location.href = url;
  });

  $(document).off('click.accountsDelete', '.js-delete-record[data-delete-url], .js-delete-record[data-url]').on('click.accountsDelete', '.js-delete-record[data-delete-url], .js-delete-record[data-url]', function () {
    const $button = $(this);
    const url = $button.data('delete-url') || $button.data('url');
    const docNum = String($button.data('doc-num') || '').trim();

    if (!url) {
      toast('error', msg('unexpectedError'));
      return;
    }

    confirmDialog(msg('deleteConfirmTitle'), msg('deleteConfirmText'), msg('deleteConfirmYes')).then(function (result) {
      if (!result.isConfirmed) {
        return;
      }
      request(url, 'DELETE').done(function (response) {
        toast('success', response.message);
        $(document).trigger('accounts:deleted', [docNum]);
        if (!table && $button.data('redirect-url')) {
          window.location.href = $button.data('redirect-url');
        }
      }).fail(function (xhr) {
        toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
      });
    });
  });

  $(document).off('click.accountsRestore', '.js-restore-record[data-restore-url], .js-restore-record[data-url]').on('click.accountsRestore', '.js-restore-record[data-restore-url], .js-restore-record[data-url]', function () {
    const $button = $(this);
    const url = $button.data('restore-url') || $button.data('url');
    const docNum = String($button.data('doc-num') || '').trim();

    if (!url) {
      toast('error', msg('unexpectedError'));
      return;
    }

    confirmDialog(msg('restoreConfirmTitle'), msg('restoreConfirmText'), msg('restoreConfirmYes')).then(function (result) {
      if (!result.isConfirmed) {
        return;
      }
      request(url, 'PATCH').done(function (response) {
        toast('success', response.message);
        $(document).trigger('accounts:restored', [docNum]);
        if (!table && $button.data('redirect-url')) {
          window.location.href = $button.data('redirect-url');
        }
      }).fail(function (xhr) {
        toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
      });
    });
  });

  $(document).off('click.accountsSubmitAction', '.js-account-submit-action').on('click.accountsSubmitAction', '.js-account-submit-action', function () {
    const $button = $(this);
    const $form = $button.closest('form');

    if ($form.length) {
      $form.find('input[name="submit_action"]').val($button.data('submit-action') || 'save');
    }
  });

  $(document).off('submit.accountsForm', '#account-form').on('submit.accountsForm', '#account-form', function (event) {
    event.preventDefault();
    const $form = $(this);

    clearValidation($form);

    if (!snapshotChanged($form)) {
      showFormNotice($form, 'warning', msg('noChanges'));
      toast('info', msg('noChanges'));
      return;
    }

    request($form.attr('action'), $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST', $form.serialize())
      .done(function (response) {
        if (response && response.type === 'no_changes') {
          showFormNotice($form, 'warning', response.message || msg('noChanges'));
          toast('info', response.message || msg('noChanges'));
          return;
        }

        toast('success', (response && response.message) || msg('saved'));

        if (response && (response.redirect || response.redirect_url)) {
          window.location.href = response.redirect || response.redirect_url;
          return;
        }

        if (response && response.reset_form) {
          resetCreateForm($form);
          reloadCurrentView();
          return;
        }

        updateOriginalSnapshot($form, response);
        updateFormUrls($form, response);
      })
      .fail(function (xhr) {
        if (xhr.status === 422) {
          renderValidation($form, xhr);
          return;
        }
        toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
      });
  });

  $(document).off('select2:select.accountsParentDerived', '#parent_doc_num').on('select2:select.accountsParentDerived', '#parent_doc_num', function (event) {
    applyDerivedFields($(this).closest('form'), event.params && event.params.data ? event.params.data : {});
  });

  $(document).off('select2:opening.accountsExpenseClassification', '#classification_code').on('select2:opening.accountsExpenseClassification', '#classification_code', function (event) {
    if ($(this).data('expenses-locked')) {
      event.preventDefault();
    }
  });

  $(document).off('select2:clearing.accountsExpenseClassification', '#classification_code').on('select2:clearing.accountsExpenseClassification', '#classification_code', function (event) {
    if ($(this).data('expenses-locked')) {
      event.preventDefault();
    }
  });

  $(document).off('select2:clear.accountsParentDerived', '#parent_doc_num').on('select2:clear.accountsParentDerived', '#parent_doc_num', function () {
    const $form = $(this).closest('form');
    const $classification = $('#classification_code');
    const classificationData = $classification.data('select2') && $classification.select2('data')[0] ? $classification.select2('data')[0] : {};

    applyRootDerivedFields($form, classificationData);
  });

  $(document).off('select2:select.accountsClassificationDerived', '#classification_code').on('select2:select.accountsClassificationDerived', '#classification_code', function (event) {
    applyRootDerivedFields($(this).closest('form'), event.params && event.params.data ? event.params.data : {});
  });

  $(document).off('select2:clear.accountsClassificationDerived', '#classification_code').on('select2:clear.accountsClassificationDerived', '#classification_code', function () {
    applyRootDerivedFields($(this).closest('form'), {});
  });

  $(document).off('submit.accountsDocumentSettings', '#accounts-document-number-settings-form').on('submit.accountsDocumentSettings', '#accounts-document-number-settings-form', function (event) {
    event.preventDefault();
    const $form = $(this);
    clearValidation($form);
    request($form.attr('action'), $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST', $form.serialize())
      .done(function (response) {
        toast('success', response.message || msg('saved'));
      })
      .fail(function (xhr) {
        if (xhr.status === 422) {
          renderValidation($form, xhr);
          return;
        }
        toast('error', (xhr.responseJSON && xhr.responseJSON.message) || msg('unexpectedError'));
      });
  });

  $(document).off('input.accountsValidation change.accountsValidation', '#account-form input, #account-form select, #account-form textarea').on('input.accountsValidation change.accountsValidation', '#account-form input, #account-form select, #account-form textarea', function () {
    const name = $(this).attr('name');
    $(this).removeClass('is-invalid');
    $('[data-error-for="' + name + '"]').text('');
  });

  $(document).off('click.accountsToggleTree', '[data-accounts-toggle-tree]').on('click.accountsToggleTree', '[data-accounts-toggle-tree]', function () {
    const $tree = $('[data-accounts-tree]');
    const $list = $('[data-accounts-list]');
    const showTree = $tree.hasClass('d-none');
    treeVisible = showTree;
    $tree.toggleClass('d-none', !showTree);
    $list.toggleClass('d-none', showTree);
    $('[data-accounts-tree-controls]').toggleClass('d-none', !showTree).toggleClass('d-flex', showTree);
    $(this).html('<span class="fas fa-' + (showTree ? 'list' : 'sitemap') + ' me-1"></span>' + (showTree ? msg('listView') : msg('treeView')));
    $('[data-accounts-panel-title]').text(showTree ? msg('treeView') : msg('title'));
    if (showTree) {
      reloadTree();
    }
  });

  $(document).off('click.accountsTreeExpandAll', '[data-accounts-tree-expand-all]').on('click.accountsTreeExpandAll', '[data-accounts-tree-expand-all]', function () {
    const $tree = $('#accountsTreeView');

    if ($tree.length) {
      setTreeExpanded($tree, true);
    }
  });

  $(document).off('click.accountsTreeCollapseAll', '[data-accounts-tree-collapse-all]').on('click.accountsTreeCollapseAll', '[data-accounts-tree-collapse-all]', function () {
    const $tree = $('#accountsTreeView');

    if ($tree.length) {
      setTreeExpanded($tree, false);
    }
  });

  function renderTree(nodes) {
    if (!nodes.length) {
      return '<div class="text-center text-600 py-4">' + msg('noData') + '</div>';
    }
    return '<ul class="mb-0 treeview treeview-stripe" id="accountsTreeView" role="tree" aria-label="' + escapeHtml(msg('title')) + '" data-options=\'{"striped":true}\'>' + renderTreeNodes(nodes, 1) + '</ul>';
  }

  function renderTreeNodes(nodes, level) {
    return nodes.map(function (node) {
      const children = Array.isArray(node.children) ? node.children : [];
      const hasChildren = children.length > 0;
      const collapseId = 'accountsTree-' + safeTreeId(node.id || node.account_code || Math.random().toString(36).slice(2));
      const expanded = level === 1;
      const hiddenClass = expanded ? ' collapse-show show' : ' collapse-hidden';
      const inactive = node.status === msg('inactive') ? ' opacity-75' : '';
      const nodeText = treeNodeText(node, hasChildren);
      const nodeLabel = treeNodeLabel(node);

      if (hasChildren) {
        return '<li class="treeview-list-item' + inactive + '" role="none">' +
          '<div class="treeview-row"></div>' +
          '<a data-accounts-tree-node data-accounts-tree-branch data-bs-toggle="collapse" href="#' + collapseId + '" role="treeitem" tabindex="-1" aria-expanded="' + (expanded ? 'true' : 'false') + '" aria-controls="' + collapseId + '" aria-level="' + level + '" aria-label="' + escapeHtml(nodeLabel) + '" title="' + branchTitle(expanded) + '">' +
          nodeText +
          '</a>' +
          '<ul class="collapse treeview-list' + hiddenClass + '" id="' + collapseId + '" role="group" data-show="' + (expanded ? 'true' : 'false') + '">' +
          renderTreeNodes(children, level + 1) +
          '</ul>' +
        '</li>';
      }

      return '<li class="treeview-list-item' + inactive + '" role="none">' +
        '<div class="treeview-row"></div>' +
        '<div class="treeview-item" data-accounts-tree-node role="treeitem" tabindex="-1" aria-level="' + level + '" aria-label="' + escapeHtml(nodeLabel) + '">' +
        '<div class="flex-1">' +
        nodeText +
        '</div>' +
        '</div>' +
      '</li>';
    }).join('');
  }

  function treeNodeText(node, hasChildren) {
    const icon = hasChildren ? '' : '<span class="fas fa-file-alt text-500"></span>';
    const code = $('<div>').text(node.account_code || '').html();
    const name = $('<div>').text(node.name || '').html();

    return '<p class="treeview-text">' +
      icon +
      '<span class="accounts-tree-code" dir="ltr">' + code + '</span>' +
      '<span class="text-900">' + name + '</span>' +
    '</p>';
  }

  function treeNodeLabel(node) {
    return [node.account_code || '', node.name || ''].filter(function (value) {
      return String(value).trim() !== '';
    }).join(' ');
  }

  function escapeHtml(value) {
    return $('<div>').text(value || '').html();
  }

  function branchTitle(expanded) {
    return escapeHtml(expanded ? msg('collapseBranch') : msg('expandBranch'));
  }

  function safeTreeId(value) {
    return String(value).replace(/[^A-Za-z0-9_-]/g, '-');
  }

  function initAccountTree($container) {
    const $tree = $container.find('.treeview');

    if (!$tree.length) {
      return;
    }

    stripeAccountTree($tree);

    $tree.find('.treeview-list').each(function () {
      const $list = $(this);

      $list.toggleClass('collapse-show', $list.hasClass('show')).toggleClass('collapse-hidden', !$list.hasClass('show'));
      syncTreeBranchForList($tree, $list, $list.hasClass('show'));
    });
    syncTreeFocus($tree);

    $tree.off('show.bs.collapse.accountsTree shown.bs.collapse.accountsTree hide.bs.collapse.accountsTree hidden.bs.collapse.accountsTree keydown.accountsTree focusin.accountsTree click.accountsTreeNode')
      .on('show.bs.collapse.accountsTree shown.bs.collapse.accountsTree', '.treeview-list', function (event) {
        event.stopPropagation();
        syncTreeBranchForList($tree, $(this), true);
        stripeAccountTree($tree);
        syncTreeFocus($tree);
      })
      .on('hide.bs.collapse.accountsTree hidden.bs.collapse.accountsTree', '.treeview-list', function (event) {
        event.stopPropagation();
        syncTreeBranchForList($tree, $(this), false);
        stripeAccountTree($tree);
        syncTreeFocus($tree);
      })
      .on('keydown.accountsTree', '[data-accounts-tree-node]', function (event) {
        handleTreeKeydown(event, $tree, $(this));
      })
      .on('focusin.accountsTree click.accountsTreeNode', '[data-accounts-tree-node]', function () {
        syncTreeFocus($tree, $(this));
      });
  }

  function setTreeExpanded($tree, expanded) {
    const activeElement = document.activeElement;
    const $activeNode = $(activeElement).closest('[data-accounts-tree-node]');
    const activeWasInTree = $activeNode.length > 0 && $.contains($tree.get(0), $activeNode.get(0));
    const $currentNode = $tree.find('[data-accounts-tree-node][tabindex="0"]').first();

    $tree.find('[data-accounts-tree-branch]').each(function () {
      setBranchExpanded($tree, $(this), expanded);
    });

    stripeAccountTree($tree);

    if (activeWasInTree) {
      focusTreeNode(nearestVisibleTreeNode($tree, $activeNode));
      return;
    }

    syncTreeFocus($tree, $currentNode);
  }

  function setBranchExpanded($tree, $node, expanded) {
    const $list = controlledTreeList($node);

    if (!$list.length) {
      return;
    }

    $list
      .removeClass('collapsing')
      .toggleClass('show collapse-show', expanded)
      .toggleClass('collapse-hidden', !expanded)
      .css('height', '')
      .attr('data-show', expanded ? 'true' : 'false');

    $node
      .attr('aria-expanded', expanded ? 'true' : 'false')
      .attr('title', expanded ? msg('collapseBranch') : msg('expandBranch'));

    syncTreeFocus($tree, $node);
  }

  function syncTreeBranchForList($tree, $list, expanded) {
    const $node = treeNodeForList($tree, $list);

    $list
      .toggleClass('collapse-show', expanded)
      .toggleClass('collapse-hidden', !expanded)
      .attr('data-show', expanded ? 'true' : 'false');

    $node
      .attr('aria-expanded', expanded ? 'true' : 'false')
      .attr('title', expanded ? msg('collapseBranch') : msg('expandBranch'));
  }

  function controlledTreeList($node) {
    const id = String($node.attr('aria-controls') || '').trim();

    return id === '' ? $() : $(document.getElementById(id));
  }

  function treeNodeForList($tree, $list) {
    const id = String($list.attr('id') || '').trim();

    return id === '' ? $() : $tree.find('[data-accounts-tree-node][aria-controls="' + id + '"]').first();
  }

  function visibleTreeNodes($tree) {
    return $tree.find('[data-accounts-tree-node]').filter(function () {
      return $(this).parentsUntil($tree, '.collapse-hidden, .treeview-list:not(.show)').length === 0;
    });
  }

  function isVisibleTreeNode($tree, $node) {
    return $node.length > 0 && visibleTreeNodes($tree).filter($node).length > 0;
  }

  function syncTreeFocus($tree, $preferredNode) {
    const $visibleNodes = visibleTreeNodes($tree);

    if (!$visibleNodes.length) {
      return;
    }

    const $currentNode = $preferredNode && isVisibleTreeNode($tree, $preferredNode)
      ? $preferredNode
      : ($visibleNodes.filter('[tabindex="0"]').first().length ? $visibleNodes.filter('[tabindex="0"]').first() : $visibleNodes.first());

    $tree.find('[data-accounts-tree-node]').attr('tabindex', '-1');
    $currentNode.attr('tabindex', '0');
  }

  function focusTreeNode($node) {
    const $tree = $node.closest('.treeview');

    if (!$tree.length || !$node.length) {
      return;
    }

    syncTreeFocus($tree, $node);
    $node.trigger('focus');
  }

  function nearestVisibleTreeNode($tree, $node) {
    let $candidate = $node;

    while ($candidate.length) {
      if (isVisibleTreeNode($tree, $candidate)) {
        return $candidate;
      }

      $candidate = parentTreeNode($tree, $candidate);
    }

    return visibleTreeNodes($tree).first();
  }

  function parentTreeNode($tree, $node) {
    const $parentList = $node.closest('ul.treeview-list');

    return $parentList.length ? treeNodeForList($tree, $parentList) : $();
  }

  function handleTreeKeydown(event, $tree, $node) {
    if (shouldIgnoreTreeKeydown(event)) {
      return;
    }

    const key = event.key;
    const rtl = String(document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';
    const expandKey = rtl ? 'ArrowLeft' : 'ArrowRight';
    const collapseKey = rtl ? 'ArrowRight' : 'ArrowLeft';

    if (key === 'ArrowDown') {
      event.preventDefault();
      focusAdjacentTreeNode($tree, $node, 1);
      return;
    }

    if (key === 'ArrowUp') {
      event.preventDefault();
      focusAdjacentTreeNode($tree, $node, -1);
      return;
    }

    if (key === 'Home') {
      event.preventDefault();
      focusTreeNode(visibleTreeNodes($tree).first());
      return;
    }

    if (key === 'End') {
      event.preventDefault();
      focusTreeNode(visibleTreeNodes($tree).last());
      return;
    }

    if (key === 'Enter' || key === ' ') {
      event.preventDefault();
      toggleTreeNode($tree, $node);
      return;
    }

    if (key === expandKey) {
      event.preventDefault();
      expandTreeNode($tree, $node);
      return;
    }

    if (key === collapseKey) {
      event.preventDefault();
      collapseTreeNode($tree, $node);
    }
  }

  function shouldIgnoreTreeKeydown(event) {
    return $(event.target).closest('input, select, textarea, button, .dropdown-menu, .modal.show, .select2-container, .select2-search__field, [contenteditable="true"]').length > 0;
  }

  function focusAdjacentTreeNode($tree, $node, offset) {
    const $nodes = visibleTreeNodes($tree);
    const index = $nodes.index($node);
    const nextIndex = index + offset;

    if (nextIndex >= 0 && nextIndex < $nodes.length) {
      focusTreeNode($nodes.eq(nextIndex));
    }
  }

  function toggleTreeNode($tree, $node) {
    if (!$node.is('[data-accounts-tree-branch]')) {
      return;
    }

    setBranchExpanded($tree, $node, $node.attr('aria-expanded') !== 'true');
    stripeAccountTree($tree);
    focusTreeNode($node);
  }

  function expandTreeNode($tree, $node) {
    if (!$node.is('[data-accounts-tree-branch]')) {
      return;
    }

    if ($node.attr('aria-expanded') !== 'true') {
      setBranchExpanded($tree, $node, true);
      stripeAccountTree($tree);
      focusTreeNode($node);
      return;
    }

    const $nextNode = visibleTreeNodes($tree).eq(visibleTreeNodes($tree).index($node) + 1);

    if ($nextNode.length && $nextNode.closest('ul.treeview-list').attr('id') === $node.attr('aria-controls')) {
      focusTreeNode($nextNode);
    }
  }

  function collapseTreeNode($tree, $node) {
    if ($node.is('[data-accounts-tree-branch]') && $node.attr('aria-expanded') === 'true') {
      setBranchExpanded($tree, $node, false);
      stripeAccountTree($tree);
      focusTreeNode($node);
      return;
    }

    focusTreeNode(parentTreeNode($tree, $node));
  }

  function stripeAccountTree($tree) {
    window.setTimeout(function () {
      const $rows = $tree
        .find('> li > .treeview-row, .treeview-list.collapse-show > li > .treeview-row')
        .filter(function () {
          return $(this).parents('.collapse-hidden').length === 0;
        });

      $tree.find('.treeview-row').removeClass('treeview-row-even treeview-row-odd');
      $rows.each(function (index) {
        $(this).addClass(index % 2 === 0 ? 'treeview-row-even' : 'treeview-row-odd');
      });
    }, 0);
  }
})(jQuery, window, document);
