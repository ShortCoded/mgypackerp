(function ($, window, document) {
  'use strict';

  const messages = window.openingStocksMessages || {};
  const productLabels = window.openingStockProductLabels || {};
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
  let openingStocksTable = null;

  function trans(key, fallback) {
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
      window.Swal.fire({ icon: icon, title: title, timer: 1600, showConfirmButton: false });
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
      confirmButtonText: options.confirmButtonText || trans('confirm_yes', 'Confirm'),
      cancelButtonText: options.cancelButtonText || trans('cancel', 'Cancel'),
      confirmButtonColor: options.confirmButtonColor || '#d33',
      cancelButtonColor: '#748194'
    });
  }

  function alertElement($form) {
    let $alert = $form.find('.js-form-alert').first();

    if ($alert.length === 0) {
      $alert = $('<div class="alert alert-danger d-none js-form-alert"><div class="js-form-alert-message"></div></div>');
      $form.find('.card-body').first().prepend($alert);
    }

    return $alert;
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

  function select2Selection($field) {
    return $field.next('.select2-container').find('.select2-selection');
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
      .append($list);

    Object.keys(errors || {}).forEach(function (field) {
      const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
      const baseField = String(field).split('.')[0];
      const bracket = bracketName(field);
      const $input = $form.find('[name="' + field + '"], [name="' + bracket + '"], [name="' + baseField + '"], [name="' + baseField + '[]"]');

      $input.addClass('is-invalid');
      $input.filter('select').each(function () {
        select2Selection($(this)).addClass('is-invalid');
      });

      $form.find('[data-error-for="' + field + '"], [data-error-for="' + baseField + '"]').text(message || '');
    });
  }

  function clearFieldError($field) {
    const $form = $field.closest('form');
    const field = dotName($field.attr('name') || '');

    $field.removeClass('is-invalid');
    if ($field.is('select')) {
      select2Selection($field).removeClass('is-invalid');
    }
    $form.find('[data-error-for="' + field + '"]').text('');
  }

  function setLoading($button, loading) {
    $button.prop('disabled', loading);
    $button.css('cursor', loading ? 'wait' : '');
    $('body').css('cursor', loading ? 'wait' : '');
  }

  function columnName(column) {
    const map = {
      doc_num: 'inventory_opening_stocks.doc_number',
      document_date: 'inventory_opening_stocks.document_date',
      branch: 'branches.name',
      hall: 'branch_halls.name',
      store: 'branch_stores.name',
      lines_count: 'lines_count',
      total_quantity: 'total_quantity',
      document_status: 'inventory_opening_stocks.status',
      approval_status: 'inventory_opening_stocks.approved',
      approved_by: 'approved_users.name',
      approved_at: 'inventory_opening_stocks.approved_at',
      created_by: 'created_users.name',
      created_at: 'inventory_opening_stocks.created_at',
      updated_by: 'updated_users.name',
      updated_at: 'inventory_opening_stocks.updated_at'
    };

    return map[column] || column;
  }

  function columnClass(column, index) {
    if (index === 0) {
      return 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control';
    }

    if (['document_status', 'approval_status'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-status';
    }

    if (['document_date', 'approved_at', 'created_at', 'updated_at'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-date';
    }

    if (['lines_count', 'total_quantity'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-number text-center';
    }

    return 'align-middle white-space-nowrap dt-text dt-ellipsis';
  }

  function tableColumns() {
    const configured = window.openingStocksColumns || [];
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

  function initTable() {
    const $table = $('.js-opening-stocks-table').first();

    if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
      return;
    }

    const protectedColumns = [0, 1, -1];
    const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
      ? window.AppDataTables.options
      : function (options) { return options; };

    function trashFilterValue() {
      const value = String($('.js-opening-stocks-trash-filter').val() || 'active');

      return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
    }

    function protectStateColumns(data) {
      if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
        window.AppDataTables.protectStateColumns(data, protectedColumns);
        return;
      }

      [0, 1, tableColumns().length - 1].forEach(function (index) {
        if (data && data.columns && data.columns[index]) {
          data.columns[index].visible = true;
        }
      });
    }

    function showProtectedColumns(api) {
      if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
        window.AppDataTables.showColumns(api, protectedColumns);
        return;
      }

      [0, 1, tableColumns().length - 1].forEach(function (index) {
        api.column(index).visible(true, false);
      });

      api.columns.adjust();
    }

    openingStocksTable = $table.DataTable(dataTableOptions({
      processing: true,
      serverSide: true,
      stateSave: true,
      stateLoadParams: function (settings, data) {
        protectStateColumns(data);
      },
      stateSaveParams: function (settings, data) {
        protectStateColumns(data);
      },
      ajax: {
        url: $table.data('url'),
        data: function (data) {
          data.trash_filter = trashFilterValue();
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
        showProtectedColumns(this.api());
      },
      drawCallback: function () {
        if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
          window.AppDataTables.applyFalconEnhancements(document);
        }
      }
    }));

    $table
      .off('dblclick.openingStocksEditRow', 'tbody tr:not(.child)')
      .on('dblclick.openingStocksEditRow', 'tbody tr:not(.child)', function (event) {
        if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
          return;
        }

        const data = openingStocksTable && typeof openingStocksTable.row === 'function' ? openingStocksTable.row(this).data() : null;

        if (data && data.can_edit && data.edit_url) {
          window.location.href = data.edit_url;
          return;
        }

        if (data && data.edit_blocked_message) {
          showToast('warning', data.edit_blocked_message);
        }
      });

    $('.js-opening-stocks-trash-filter').off('change.openingStocksTable').on('change.openingStocksTable', function () {
      openingStocksTable.ajax.reload(null, true);
    });
  }

  function initSelect2(root) {
    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
      window.AppSelect2Ajax.init(root || document);
    }
  }

  function renumberLines($form) {
    $form.find('.js-opening-stock-line').each(function (index) {
      const $row = $(this);
      $row.attr('data-index', index);

      $row.find('[name]').each(function () {
        const $field = $(this);
        $field.attr('name', String($field.attr('name')).replace(/lines\[\d+\]/, 'lines[' + index + ']'));
      });

      $row.find('[data-error-for]').each(function () {
        const $error = $(this);
        $error.attr('data-error-for', String($error.attr('data-error-for')).replace(/lines\.\d+\./, 'lines.' + index + '.').replace(/lines\.__INDEX__\./, 'lines.' + index + '.'));
      });
    });
  }

  function rowProductValue($row) {
    const $select = $row.find('.js-opening-stock-product').first();

    if ($select.length > 0) {
      return String($select.val() || '').trim();
    }

    return String($row.find('.js-opening-stock-product-value').val() || '').trim();
  }

  function setUnit($row, value) {
    $row.find('.js-opening-stock-unit, [data-unit-display]').text(value || '');
  }

  function selectedOption($select) {
    return $select.find('option:selected').first();
  }

  function storeSelectedProductData($select, data) {
    if (!data || data.id === undefined) {
      return;
    }

    const $option = $select.find('option').filter(function () {
      return String(this.value) === String(data.id);
    }).first();

    if (!$option.length) {
      return;
    }

    $option.attr('data-unit-label', data.unitLabel || data.unit_text || '');
    $option.data('product-data', data.productData || null);
  }

  function syncProductInfoButton($row) {
    $row.find('.js-opening-stock-product-info').prop('disabled', rowProductValue($row) === '');
  }

  function rowValues($row) {
    return {
      quantity: $row.find('.js-opening-stock-quantity').val() || '',
      stockStatus: $row.find('.js-opening-stock-status').val() || 'available',
      batchLot: $row.find('.js-opening-stock-batch').val() || '',
      notes: $row.find('.js-opening-stock-line-notes').val() || ''
    };
  }

  function emptyRowTemplate(index) {
    return String($('#opening-stock-line-template').html() || '').replace(/__INDEX__/g, String(index));
  }

  function focusProduct($row) {
    window.setTimeout(function () {
      const $product = $row.find('.js-opening-stock-product').first();

      if ($product.length > 0 && $product.data('select2') && typeof $product.select2 === 'function') {
        $product.select2('open');
        return;
      }

      $product.trigger('focus');
    }, 0);
  }

  function addLine($form, values, shouldFocus, $afterRow) {
    const $tbody = $form.find('.js-opening-stock-lines tbody');
    const index = $form.find('.js-opening-stock-line').length;
    const $row = $(emptyRowTemplate(index));

    if (!$row.length) {
      return $row;
    }

    if (values) {
      $row.find('.js-opening-stock-quantity').val(values.quantity || '');
      $row.find('.js-opening-stock-status').val(values.stockStatus || 'available');
      $row.find('.js-opening-stock-batch').val(values.batchLot || '');
      $row.find('.js-opening-stock-line-notes').val(values.notes || '');
    }

    if ($afterRow && $afterRow.length && $.contains($tbody.get(0), $afterRow.get(0))) {
      $afterRow.after($row);
    } else {
      $tbody.append($row);
    }

    renumberLines($form);
    initSelect2($row[0]);
    window.AppNumbers.refresh($row[0]);

    if (shouldFocus !== false) {
      focusProduct($row);
    }

    return $row;
  }

  function clearRow($row) {
    $row.find('.js-opening-stock-product').val(null).trigger('change');
    $row.find('input[type="hidden"][name$="[public_id]"]').val('');
    $row.find('input[type="hidden"][name$="[_delete]"]').val('0');
    $row.find('.js-opening-stock-quantity, .js-opening-stock-batch, .js-opening-stock-line-notes').val('');
    $row.find('.js-opening-stock-status').val('available');
    setUnit($row, '');
    syncProductInfoButton($row);
    focusProduct($row);
  }

  function removeRow($row) {
    const $form = $row.closest('.js-opening-stock-form');
    const $rows = $form.find('.js-opening-stock-line');
    const $focusTarget = $row.next('.js-opening-stock-line').length ? $row.next('.js-opening-stock-line') : $row.prev('.js-opening-stock-line');

    if ($rows.length <= 1) {
      clearRow($row);
      return;
    }

    $row.remove();
    renumberLines($form);

    if ($focusTarget.length > 0) {
      focusProduct($focusTarget);
    }
  }

  function currentRow($target) {
    return $target.closest('.js-opening-stock-line');
  }

  function isAltShortcut(event, codes, keyCodes, legacyKeys) {
    if (window.AppShortcuts && typeof window.AppShortcuts.isAltShortcut === 'function') {
      return !event.ctrlKey && window.AppShortcuts.isAltShortcut(event, codes, keyCodes, legacyKeys);
    }

    const key = String(event.key || '').toLowerCase();
    const code = event.code || '';
    const keyCode = Number(event.keyCode || event.which || 0);

    return event.altKey === true
      && !event.ctrlKey
      && !event.metaKey
      && !event.shiftKey
      && (codes.indexOf(code) !== -1 || keyCodes.indexOf(keyCode) !== -1 || legacyKeys.indexOf(key) !== -1);
  }

  function isAltDelete(event) {
    const code = event.code || '';
    const keyCode = Number(event.keyCode || event.which || 0);

    return event.altKey === true
      && !event.ctrlKey
      && !event.metaKey
      && !event.shiftKey
      && (event.key === 'Delete' || code === 'Delete' || keyCode === 46);
  }

  function isEnter(event) {
    const code = event.code || '';
    const keyCode = Number(event.keyCode || event.which || 0);

    return event.key === 'Enter' || code === 'Enter' || code === 'NumpadEnter' || keyCode === 13;
  }

  function focusRelativeField($form, direction) {
    const fields = $form.find([
      '.js-opening-stock-product',
      '.js-opening-stock-quantity',
      '.js-opening-stock-status',
      '.js-opening-stock-batch',
      '.js-opening-stock-line-notes',
      '.js-opening-stock-product-info',
      '.js-opening-stock-product-create',
      '.js-opening-stock-duplicate-line',
      '.js-opening-stock-remove-line'
    ].join(',')).filter(':not(:disabled):not([readonly])').toArray();
    const active = document.activeElement;
    let index = fields.indexOf(active);

    if (index === -1 && active && active.classList.contains('select2-selection')) {
      const select = $(active).closest('.select2-container').prev('select').get(0);
      index = fields.indexOf(select);
    }

    if (direction > 0 && index === fields.length - 1) {
      addLine($form, {}, true, $form.find('.js-opening-stock-line').last());
      return;
    }

    const nextIndex = Math.max(0, Math.min(fields.length - 1, (index === -1 ? 0 : index) + direction));
    const nextField = fields[nextIndex] || fields[0];

    if (!nextField) {
      return;
    }

    if ($(nextField).hasClass('js-opening-stock-product') && $.fn.select2) {
      $(nextField).select2('open');
      return;
    }

    nextField.focus();
    if (typeof nextField.select === 'function') {
      nextField.select();
    }
  }

  function modalApi($modal) {
    return window.bootstrap && window.bootstrap.Modal && $modal.length
      ? window.bootstrap.Modal.getOrCreateInstance($modal[0])
      : null;
  }

  function productDetailsUrl($form, productDocNum) {
    const template = String($form.data('product-details-url-template') || '');

    return template.replace('__PRODUCT__', encodeURIComponent(productDocNum));
  }

  function selectedProductData($row) {
    const $select = $row.find('.js-opening-stock-product').first();

    if (!$select.length) {
      return null;
    }

    return selectedOption($select).data('product-data') || null;
  }

  function renderProductDetails(product) {
    const $modal = $('#opening-stock-product-info-modal');
    const $image = $modal.find('.js-opening-stock-product-image');
    const $fallback = $modal.find('.js-opening-stock-product-no-image');
    const $details = $modal.find('.js-opening-stock-product-details');
    const order = ['doc_num', 'name', 'barcode', 'item_classification', 'unit', 'category', 'group', 'size', 'color', 'decal', 'model', 'origin_country', 'reorder_point', 'status', 'notes'];
    const imageUrl = product && product.imageUrl ? String(product.imageUrl) : '';

    $details.empty();
    $modal.find('#opening-stock-product-info-title').text(product && product.name ? product.name : trans('product_details', 'Product Details'));

    if (imageUrl !== '') {
      $image
        .removeClass('d-none')
        .attr('src', imageUrl)
        .attr('alt', product && product.name ? product.name : '');
      $fallback.addClass('d-none');
    } else {
      $image.addClass('d-none').attr('src', '').attr('alt', '');
      $fallback.removeClass('d-none').text(trans('no_image', 'No image'));
    }

    order.forEach(function (field) {
      const value = product ? product[field] : null;

      if (value === null || value === undefined || String(value).trim() === '') {
        return;
      }

      $details
        .append($('<dt class="col-sm-4"></dt>').text(productLabels[field] || field))
        .append($('<dd class="col-sm-8 mb-0"></dd>').text(value));
    });

    const modal = modalApi($modal);
    if (modal) {
      modal.show();
      return;
    }

    $modal.modal('show');
  }

  function openProductInfo($button) {
    const $row = $button.closest('.js-opening-stock-line');
    const productDocNum = rowProductValue($row);

    if (productDocNum === '') {
      showToast('warning', trans('no_product_selected', 'Select a product first.'));
      return;
    }

    const existing = selectedProductData($row);
    if (existing) {
      renderProductDetails(existing);
      return;
    }

    $.ajax({
      url: productDetailsUrl($button.closest('.js-opening-stock-form'), productDocNum),
      method: 'GET',
      headers: headers()
    }).done(function (response) {
      renderProductDetails(response && response.data ? response.data : {});
    }).fail(function () {
      showToast('error', trans('unexpected_error', 'Unexpected error occurred.'));
    });
  }

  function openProductCreate($row) {
    if (!$row.length) {
      return;
    }

    const $link = $row.find('.js-opening-stock-product-create[href]').first();
    const url = String($link.attr('href') || '').trim();

    if (url === '') {
      return;
    }

    const opened = window.open(url, '_blank', 'noopener');

    if (opened) {
      opened.opener = null;
    }
  }

  function shortcutBlockedBySelect2(target) {
    const $target = $(target);

    return $target.hasClass('select2-search__field') || $('.select2-container--open').length > 0;
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

    $('[href*="' + data.old_doc_num + '"], [data-delete-url*="' + data.old_doc_num + '"], [data-restore-url*="' + data.old_doc_num + '"], [data-url*="' + data.old_doc_num + '"]').each(function () {
      const $element = $(this);

      ['href', 'data-delete-url', 'data-restore-url', 'data-url'].forEach(function (attribute) {
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

  function initForm() {
    const $form = $('.js-opening-stock-form').first();

    if ($form.length === 0) {
      return;
    }

    initSelect2(document);
    $form.find('.js-opening-stock-line').each(function () {
      syncProductInfoButton($(this));
    });

    $(document)
      .off('click.openingStocksSubmitAction', '.js-opening-stock-form .js-finance-submit-action, .js-opening-stock-form .js-submit-action')
      .on('click.openingStocksSubmitAction', '.js-opening-stock-form .js-finance-submit-action, .js-opening-stock-form .js-submit-action', function () {
        const $button = $(this);

        $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
        $button.closest('form').data('submit-button', $button);
      })
      .off('submit.openingStocksForm', '.js-opening-stock-form')
      .on('submit.openingStocksForm', '.js-opening-stock-form', function (event) {
        event.preventDefault();

        const $currentForm = $(this);
        const $button = $currentForm.data('submit-button') || $currentForm.find('[type="submit"]').first();
        const method = $currentForm.find('input[name="_method"]').val() || $currentForm.attr('method') || 'POST';

        clearFormErrors($currentForm);
        renumberLines($currentForm);
        setLoading($button, true);

        $.ajax({
          url: $currentForm.attr('action'),
          method: method,
          data: $currentForm.serialize(),
          headers: headers()
        }).done(function (response) {
          updateUrlsAfterDocNumberChange($currentForm, response);
          showToast('success', response.message || trans('saved', 'Saved successfully.'));

          if (response && response.redirect) {
            window.location.href = response.redirect;
          }
        }).fail(function (response) {
          if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
            showValidationErrors($currentForm, response.responseJSON.errors);
            return;
          }

          alertElement($currentForm)
            .removeClass('d-none alert-success alert-info alert-warning')
            .addClass('alert-danger')
            .find('.js-form-alert-message')
            .text(response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error occurred.'));
        }).always(function () {
          setLoading($button, false);
        });
      })
      .off('input.openingStocksValidation change.openingStocksValidation select2:select.openingStocksValidation select2:clear.openingStocksValidation', '.js-opening-stock-form input, .js-opening-stock-form select, .js-opening-stock-form textarea')
      .on('input.openingStocksValidation change.openingStocksValidation select2:select.openingStocksValidation select2:clear.openingStocksValidation', '.js-opening-stock-form input, .js-opening-stock-form select, .js-opening-stock-form textarea', function () {
        clearFieldError($(this));
      })
      .off('select2:select.openingStocksProduct', '.js-opening-stock-product')
      .on('select2:select.openingStocksProduct', '.js-opening-stock-product', function (event) {
        const data = event.params ? event.params.data : null;
        const $select = $(this);
        const $row = $select.closest('.js-opening-stock-line');

        storeSelectedProductData($select, data);
        setUnit($row, data && (data.unitLabel || data.unit_text) ? (data.unitLabel || data.unit_text) : '');
        syncProductInfoButton($row);
      })
      .off('change.openingStocksProductClear select2:clear.openingStocksProductClear', '.js-opening-stock-product')
      .on('change.openingStocksProductClear select2:clear.openingStocksProductClear', '.js-opening-stock-product', function () {
        const $select = $(this);
        const $row = $select.closest('.js-opening-stock-line');

        if (!$select.val()) {
          setUnit($row, '');
        } else {
          setUnit($row, selectedOption($select).attr('data-unit-label') || selectedOption($select).data('unit-label') || '');
        }

        syncProductInfoButton($row);
      })
      .off('click.openingStocksAddLine', '.js-opening-stock-add-line')
      .on('click.openingStocksAddLine', '.js-opening-stock-add-line', function () {
        addLine($(this).closest('.js-opening-stock-form'), {}, true);
      })
      .off('click.openingStocksDuplicateLine', '.js-opening-stock-duplicate-line')
      .on('click.openingStocksDuplicateLine', '.js-opening-stock-duplicate-line', function () {
        const $row = $(this).closest('.js-opening-stock-line');
        addLine($row.closest('.js-opening-stock-form'), rowValues($row), true, $row);
      })
      .off('click.openingStocksRemoveLine', '.js-opening-stock-remove-line')
      .on('click.openingStocksRemoveLine', '.js-opening-stock-remove-line', function () {
        removeRow($(this).closest('.js-opening-stock-line'));
      })
      .off('click.openingStocksProductInfo', '.js-opening-stock-product-info')
      .on('click.openingStocksProductInfo', '.js-opening-stock-product-info', function () {
        openProductInfo($(this));
      });

    $form.off('keydown.openingStocksShortcuts').on('keydown.openingStocksShortcuts', function (event) {
      const $target = $(event.target);
      const $row = currentRow($target);
      const isSelect2Search = $target.hasClass('select2-search__field');
      const isSelect2Open = $('.select2-container--open').length > 0;
      const isSelect2Blocked = shortcutBlockedBySelect2(event.target);

      if (isEnter(event) && (isSelect2Search || isSelect2Open)) {
        return;
      }

      if (isAltShortcut(event, ['KeyN'], [78], ['n']) && !isSelect2Blocked) {
        event.preventDefault();
        event.stopPropagation();
        addLine($form, {}, true, $row.length ? $row : null);
        return;
      }

      if (isAltShortcut(event, ['KeyD'], [68], ['d']) && $row.length > 0 && !isSelect2Blocked) {
        event.preventDefault();
        event.stopPropagation();
        addLine($form, rowValues($row), true, $row);
        return;
      }

      if (isAltDelete(event) && $row.length > 0 && !isSelect2Blocked) {
        event.preventDefault();
        event.stopPropagation();
        removeRow($row);
        return;
      }

      if (isAltShortcut(event, ['KeyI'], [73], ['i']) && $row.length > 0 && !isSelect2Blocked) {
        event.preventDefault();
        event.stopPropagation();
        openProductInfo($row.find('.js-opening-stock-product-info').first());
        return;
      }

      if (isAltShortcut(event, ['KeyP'], [80], ['p']) && $row.length > 0 && !isSelect2Blocked) {
        event.preventDefault();
        event.stopPropagation();
        openProductCreate($row);
        return;
      }

      if (isEnter(event) && !event.altKey && !event.ctrlKey && !event.metaKey && $row.length > 0) {
        event.preventDefault();
        event.stopPropagation();
        focusRelativeField($form, event.shiftKey ? -1 : 1);
      }
    });
  }

  function initDocumentNumberSettings() {
    $(document)
      .off('submit.openingStocksDocSettings', '.js-opening-stocks-document-number-settings-form')
      .on('submit.openingStocksDocSettings', '.js-opening-stocks-document-number-settings-form', function (event) {
        event.preventDefault();

        const $form = $(this);
        const $button = $form.find('[type="submit"]').first();
        const method = $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST';

        clearFormErrors($form);
        setLoading($button, true);

        $.ajax({
          url: $form.attr('action'),
          method: method,
          data: $form.serialize(),
          headers: headers()
        }).done(function (response) {
          const data = response && response.data ? response.data : {};

          if (Object.prototype.hasOwnProperty.call(data, 'prefix')) {
            $form.find('[name="prefix"]').val(data.prefix || '');
          }

          if (Object.prototype.hasOwnProperty.call(data, 'padding')) {
            $form.find('[name="padding"]').val(data.padding);
          }

          showToast('success', response.message || trans('saved', 'Saved successfully.'));
        }).fail(function (response) {
          if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
            showValidationErrors($form, response.responseJSON.errors);
            return;
          }

          showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error occurred.'));
        }).always(function () {
          setLoading($button, false);
        });
      });
  }

  function initRecordActions() {
    $(document)
      .off('click.openingStocksDelete', '.js-delete-record[data-delete-url], [data-opening-stock-delete-url]')
      .on('click.openingStocksDelete', '.js-delete-record[data-delete-url], [data-opening-stock-delete-url]', function () {
        const $button = $(this);
        const url = $button.data('delete-url') || $button.data('opening-stock-delete-url');

        if (!url) {
          showToast('error', trans('unexpected_error', 'Unexpected error occurred.'));
          return;
        }

        confirmDialog({
          title: trans('confirm_delete_title', 'Delete Opening Stock?'),
          text: trans('confirm_delete_text', 'This document will be moved to trash.'),
          confirmButtonText: trans('confirm_delete_yes', 'Yes, delete')
        }).then(function (result) {
          if (!result.isConfirmed) {
            return;
          }

          setLoading($button, true);
          $.ajax({ url: url, method: 'DELETE', headers: headers() })
            .done(function (response) {
              showToast('success', response.message);

              if (openingStocksTable) {
                openingStocksTable.ajax.reload(null, false);
                return;
              }

              if ($button.data('redirect-url')) {
                window.location.href = $button.data('redirect-url');
              }
            })
            .fail(function (response) {
              showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error occurred.'));
            })
            .always(function () {
              setLoading($button, false);
            });
        });
      })
      .off('click.openingStocksRestore', '.js-restore-record[data-restore-url]')
      .on('click.openingStocksRestore', '.js-restore-record[data-restore-url]', function () {
        const $button = $(this);
        const url = $button.data('restore-url');

        if (!url) {
          showToast('error', trans('unexpected_error', 'Unexpected error occurred.'));
          return;
        }

        confirmDialog({
          title: trans('confirm_restore_title', 'Restore Opening Stock?'),
          text: trans('confirm_restore_text', 'This document will become active again.'),
          confirmButtonText: trans('confirm_restore_yes', 'Yes, restore'),
          confirmButtonColor: '#00a65a'
        }).then(function (result) {
          if (!result.isConfirmed) {
            return;
          }

          setLoading($button, true);
          $.ajax({ url: url, method: 'PATCH', headers: headers() })
            .done(function (response) {
              showToast('success', response.message);

              if (openingStocksTable) {
                openingStocksTable.ajax.reload(null, false);
                return;
              }

              window.location.reload();
            })
            .fail(function (response) {
              showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error occurred.'));
            })
            .always(function () {
              setLoading($button, false);
            });
        });
      })
      .off('click.openingStocksApprove', '.js-approve-opening-stock')
      .on('click.openingStocksApprove', '.js-approve-opening-stock', function () {
        const $button = $(this);
        const url = $button.data('url');

        if (!url) {
          showToast('error', trans('unexpected_error', 'Unexpected error occurred.'));
          return;
        }

        confirmDialog({
          icon: 'question',
          title: trans('approve_confirm_title', 'Do you want to approve this Opening Stock document?'),
          confirmButtonText: trans('approve_confirm_yes', 'Approve'),
          confirmButtonColor: '#00a65a'
        }).then(function (result) {
          if (!result.isConfirmed) {
            return;
          }

          setLoading($button, true);
          $.ajax({ url: url, method: 'POST', headers: headers() })
            .done(function (response) {
              showToast('success', response.message);

              if (openingStocksTable) {
                openingStocksTable.ajax.reload(null, false);
                return;
              }

              window.location.reload();
            })
            .fail(function (response) {
              showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error occurred.'));
            })
            .always(function () {
              setLoading($button, false);
            });
        });
      });

    $('#opening-stock-product-info-modal')
      .off('hidden.bs.modal.openingStocksProductInfo')
      .on('hidden.bs.modal.openingStocksProductInfo', function () {
        $(this).find('.js-opening-stock-product-image').attr('src', '').attr('alt', '').addClass('d-none');
        $(this).find('.js-opening-stock-product-details').empty();
      })
      .off('error.openingStocksProductInfo', '.js-opening-stock-product-image')
      .on('error.openingStocksProductInfo', '.js-opening-stock-product-image', function () {
        $(this).addClass('d-none').attr('src', '');
        $('#opening-stock-product-info-modal').find('.js-opening-stock-product-no-image').removeClass('d-none').text(trans('no_image', 'No image'));
      });
  }

  $(function () {
    initTable();
    initForm();
    initDocumentNumberSettings();
    initRecordActions();
  });
})(jQuery, window, document);
