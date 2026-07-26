(function ($, window, document) {
  'use strict';

  const messages = window.openingStockPricingsMessages || {};
  const productLabels = window.openingStockPricingProductLabels || {};
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
  let openingStockPricingsTable = null;

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

  function formatNumber(value, precision) {
    const number = window.AppNumbers.number(value, NaN);

    if (!Number.isFinite(number)) {
      return '';
    }

    return window.AppNumbers.format(number.toFixed(precision || 4).replace(/\.?0+$/, ''));
  }

  function numericValue(value) {
    return window.AppNumbers.number(value, 0);
  }

  function columnName(column) {
    const map = {
      doc_num: 'inventory_opening_stock_pricings.doc_number',
      document_date: 'inventory_opening_stock_pricings.document_date',
      branch: 'branches.name',
      hall: 'branch_halls.name',
      opening_stock_doc_num: 'inventory_opening_stocks.doc_number',
      currency: 'currencies.code',
      exchange_rate: 'inventory_opening_stock_pricings.exchange_rate',
      total_amount: 'inventory_opening_stock_pricings.total_amount',
      status: 'inventory_opening_stock_pricings.status',
      lines_count: 'lines_count',
      created_by: 'created_users.name',
      created_at: 'inventory_opening_stock_pricings.created_at',
      updated_by: 'updated_users.name',
      updated_at: 'inventory_opening_stock_pricings.updated_at'
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

    if (['document_date', 'created_at', 'updated_at'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-date';
    }

    if (['exchange_rate', 'total_amount', 'lines_count'].indexOf(column) !== -1) {
      return 'align-middle white-space-nowrap dt-number text-center';
    }

    return 'align-middle white-space-nowrap dt-text dt-ellipsis';
  }

  function tableColumns() {
    const configured = window.openingStockPricingsColumns || [];
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
    const $table = $('.js-opening-stock-pricings-table').first();

    if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
      return;
    }

    const protectedColumns = [0, 1, -1];
    const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
      ? window.AppDataTables.options
      : function (options) { return options; };

    function trashFilterValue() {
      const value = String($('.js-opening-stock-pricings-trash-filter').val() || 'active');

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

    openingStockPricingsTable = $table.DataTable(dataTableOptions({
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
      drawCallback: function () {
        if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
          window.AppDataTables.applyFalconEnhancements(document);
        }
      }
    }));

    $table
      .off('dblclick.openingStockPricingsEditRow', 'tbody tr:not(.child)')
      .on('dblclick.openingStockPricingsEditRow', 'tbody tr:not(.child)', function (event) {
        if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
          return;
        }

        const data = openingStockPricingsTable && typeof openingStockPricingsTable.row === 'function' ? openingStockPricingsTable.row(this).data() : null;

        if (data && data.can_edit && data.edit_url) {
          window.location.href = data.edit_url;
          return;
        }

        if (data && data.edit_blocked_message) {
          showToast('warning', data.edit_blocked_message);
        }
      });

    $('.js-opening-stock-pricings-trash-filter').off('change.openingStockPricingsTable').on('change.openingStockPricingsTable', function () {
      openingStockPricingsTable.ajax.reload(null, true);
    });
  }

  function initSelect2(root) {
    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
      window.AppSelect2Ajax.init(root || document);
    }
  }

  function parseJson(value) {
    if (!value) {
      return {};
    }

    if (typeof value === 'object') {
      return value;
    }

    try {
      return JSON.parse(String(value));
    } catch (error) {
      return {};
    }
  }

  function selectedOption($select) {
    return $select.find('option:selected').first();
  }

  function selectedOptionData($select) {
    return parseJson(selectedOption($select).attr('data-product-data') || selectedOption($select).data('product-data'));
  }

  function storeSelectedLineData($select, data) {
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
    $option.attr('data-quantity', data.quantity || '');
    if (data.imageUrl) {
      $option.attr('data-image-url', data.imageUrl);
    }
    $option.attr('data-product-data', JSON.stringify(data.productData || {}));
  }

  function rowLineValue($row) {
    return String($row.find('.js-opening-stock-pricing-product').val() || '').trim();
  }

  function syncProductInfoButton($row) {
    $row.find('.js-opening-stock-pricing-product-info').prop('disabled', rowLineValue($row) === '');
  }

  function setLineMetadata($row, data) {
    const unit = data && (data.unitLabel || data.unit_text) ? (data.unitLabel || data.unit_text) : '';
    const quantity = data && data.quantity ? data.quantity : '';
    const productData = data && data.productData ? data.productData : {};

    $row.find('.js-opening-stock-pricing-unit').text(unit || '');
    $row.find('.js-opening-stock-pricing-quantity').val(quantity || '');
    $row.find('.js-opening-stock-pricing-product-info').attr('data-product', JSON.stringify(productData));
    syncProductInfoButton($row);
    updateLineTotal($row);
  }

  function lineValues($row) {
    const $select = $row.find('.js-opening-stock-pricing-product').first();
    const option = selectedOption($select);

    return {
      public_id: $row.find('input[type="hidden"][name$="[public_id]"]').val() || '',
      opening_stock_line_public_id: $select.val() || '',
      product_label: option.text() || '',
      imageUrl: option.attr('data-image-url') || '',
      unit: option.attr('data-unit-label') || '',
      quantity: $row.find('.js-opening-stock-pricing-quantity').val() || '',
      unit_price: $row.find('.js-opening-stock-pricing-unit-price').val() || '',
      line_total: $row.find('.js-opening-stock-pricing-line-total').val() || '',
      notes: $row.find('.js-opening-stock-pricing-line-notes').val() || '',
      productData: selectedOptionData($select)
    };
  }

  function emptyRowTemplate(index) {
    return String($('#opening-stock-pricing-line-template').html() || '').replace(/__INDEX__/g, String(index));
  }

  function renumberLines($form) {
    $form.find('.js-opening-stock-pricing-line').each(function (index) {
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

  function focusProduct($row) {
    window.setTimeout(function () {
      const $product = $row.find('.js-opening-stock-pricing-product').first();

      if ($product.length > 0 && $product.data('select2') && typeof $product.select2 === 'function') {
        $product.select2('open');
        return;
      }

      $product.trigger('focus');
    }, 0);
  }

  function addLine($form, values, shouldFocus, $afterRow) {
    const $tbody = $form.find('.js-opening-stock-pricing-lines tbody');
    const index = $form.find('.js-opening-stock-pricing-line').length;
    const $row = $(emptyRowTemplate(index));

    if (!$row.length) {
      return $row;
    }

    if ($afterRow && $afterRow.length && $.contains($tbody.get(0), $afterRow.get(0))) {
      $afterRow.after($row);
    } else {
      $tbody.append($row);
    }

    const data = values || {};
    const id = data.opening_stock_line_public_id || data.id || '';
    const text = data.product_label || data.text || '';
    const productData = data.productData || data.product_data || {};

    if (id) {
      const option = new Option(text || id, id, true, true);
      $(option)
        .attr('data-unit-label', data.unit || data.unitLabel || data.unit_text || '')
        .attr('data-quantity', data.quantity || '')
        .attr('data-product-data', JSON.stringify(productData));
      if (data.imageUrl) {
        $(option).attr('data-image-url', data.imageUrl);
      }
      $row.find('.js-opening-stock-pricing-product').append(option);
    }

    $row.find('.js-opening-stock-pricing-unit').text(data.unit || data.unitLabel || data.unit_text || '');
    $row.find('.js-opening-stock-pricing-quantity').val(data.quantity || '');
    $row.find('.js-opening-stock-pricing-unit-price').val(data.unit_price || '');
    $row.find('.js-opening-stock-pricing-line-notes').val(data.notes || '');
    $row.find('.js-opening-stock-pricing-product-info').attr('data-product', JSON.stringify(productData));
    syncProductInfoButton($row);
    updateLineTotal($row);
    renumberLines($form);
    initSelect2($row[0]);
    window.AppNumbers.refresh($row[0]);

    if (shouldFocus !== false) {
      focusProduct($row);
    }

    return $row;
  }

  function clearRow($row, shouldFocus) {
    $row.find('.js-opening-stock-pricing-product').val(null).trigger('change');
    $row.find('input[type="hidden"][name$="[public_id]"]').val('');
    $row.find('input[type="hidden"][name$="[_delete]"]').val('0');
    $row.find('.js-opening-stock-pricing-unit, [data-unit-display]').text('');
    $row.find('.js-opening-stock-pricing-quantity, .js-opening-stock-pricing-unit-price, .js-opening-stock-pricing-line-total, .js-opening-stock-pricing-line-notes').val('');
    $row.find('.js-opening-stock-pricing-product-info').attr('data-product', '{}');
    syncProductInfoButton($row);
    updateTotals($row.closest('.js-opening-stock-pricing-form'));
    if (shouldFocus !== false) {
      focusProduct($row);
    }
  }

  function removeRow($row) {
    const $form = $row.closest('.js-opening-stock-pricing-form');
    const $rows = $form.find('.js-opening-stock-pricing-line');
    const $focusTarget = $row.next('.js-opening-stock-pricing-line').length ? $row.next('.js-opening-stock-pricing-line') : $row.prev('.js-opening-stock-pricing-line');

    if ($rows.length <= 1) {
      clearRow($row);
      return;
    }

    $row.remove();
    renumberLines($form);
    updateTotals($form);

    if ($focusTarget.length > 0) {
      focusProduct($focusTarget);
    }
  }

  function updateLineTotal($row) {
    const quantity = numericValue($row.find('.js-opening-stock-pricing-quantity').val());
    const price = numericValue($row.find('.js-opening-stock-pricing-unit-price').val());
    const total = quantity * price;

    $row.find('.js-opening-stock-pricing-line-total').val(price > 0 && quantity > 0 ? formatNumber(total, 4) : '');
    updateTotals($row.closest('.js-opening-stock-pricing-form'));
  }

  function updateTotals($form) {
    let total = 0;

    $form.find('.js-opening-stock-pricing-line-total').each(function () {
      total += numericValue($(this).val());
    });

    $form.find('.js-opening-stock-pricing-total-amount').text(formatNumber(total, 4) || '0');
  }

  function resetLines($form) {
    const $rows = $form.find('.js-opening-stock-pricing-line');

    if ($rows.length === 0) {
      addLine($form, {}, false);
      return;
    }

    $rows.slice(1).remove();
    clearRow($rows.first(), false);
    renumberLines($form);
  }

  function selectedCurrencyIsMain($form) {
    const mainCurrencyDocNum = String($form.data('main-currency-doc-num') || '');
    const $currency = $form.find('.js-opening-stock-pricing-currency');
    const selected = $currency.select2 && $currency.data('select2') ? $currency.select2('data')[0] : null;

    return Boolean(
      (selected && selected.is_main)
      || ($currency.val() && String($currency.val()) === mainCurrencyDocNum)
      || $currency.find('option:selected').data('is-main') === 1
    );
  }

  function applyMainCurrencyExchangeRate($form) {
    const $exchangeRate = $form.find('.js-opening-stock-pricing-exchange-rate');

    if ($exchangeRate.length === 0) {
      return;
    }

    if (selectedCurrencyIsMain($form)) {
      $exchangeRate.val('1').prop('readonly', true);
      return;
    }

    $exchangeRate.prop('readonly', false);
  }

  function branchType($form) {
    const $branch = $form.find('.js-opening-stock-pricing-branch');
    const selected = $branch.select2 && $branch.data('select2') ? $branch.select2('data')[0] : null;

    return String((selected && selected.type) || $branch.find('option:selected').data('type') || '');
  }

  function syncHallVisibility($form) {
    const isFactory = branchType($form) === 'factory';
    const $group = $form.find('.js-opening-stock-pricing-hall-group');
    const $hall = $form.find('.js-opening-stock-pricing-hall');

    $group.toggleClass('d-none', !isFactory);
    $hall.prop('disabled', !isFactory);

    if (!isFactory && $hall.val()) {
      $hall.val(null).trigger('change');
    }
  }

  function modalApi($modal) {
    return window.bootstrap && window.bootstrap.Modal && $modal.length
      ? window.bootstrap.Modal.getOrCreateInstance($modal[0])
      : null;
  }

  function renderProductDetails(product) {
    const $modal = $('#opening-stock-pricing-product-info-modal');
    const $image = $modal.find('.js-opening-stock-pricing-product-image');
    const $fallback = $modal.find('.js-opening-stock-pricing-product-no-image');
    const $details = $modal.find('.js-opening-stock-pricing-product-details');
    const order = ['doc_num', 'name', 'barcode', 'unit', 'quantity'];
    const imageUrl = product && product.imageUrl ? String(product.imageUrl) : '';

    $details.empty();
    $modal.find('#opening-stock-pricing-product-info-title').text(product && product.name ? product.name : trans('product_details', 'Product Details'));

    if (imageUrl !== '') {
      $image.removeClass('d-none').attr('src', imageUrl).attr('alt', product && product.name ? product.name : '');
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
    const $row = $button.closest('.js-opening-stock-pricing-line');
    let product = parseJson($button.attr('data-product'));

    if ($.isEmptyObject(product)) {
      product = selectedOptionData($row.find('.js-opening-stock-pricing-product').first());
    }

    if ($.isEmptyObject(product)) {
      showToast('warning', trans('no_product_selected', 'Select a product first.'));
      return;
    }

    renderProductDetails(product);
  }

  function addRemainingLines($button) {
    const $form = $button.closest('.js-opening-stock-pricing-form');
    const openingStockDocNum = String($form.find('.js-opening-stock-pricing-opening-stock').val() || '').trim();

    if (openingStockDocNum === '') {
      showToast('warning', trans('select_opening_stock_first', 'Please select an Opening Stock document first.'));
      return;
    }

    setLoading($button, true);
    $.ajax({
      url: $form.data('remaining-lines-url'),
      method: 'GET',
      data: {
        branch_doc_num: $form.find('.js-opening-stock-pricing-branch').val() || '',
        branch_hall_uuid: $form.find('.js-opening-stock-pricing-hall').val() || '',
        opening_stock_doc_num: openingStockDocNum,
        current_pricing_doc_num: $form.find('#current_pricing_doc_num').val() || ''
      },
      headers: headers()
    }).done(function (response) {
      const lines = response && response.data && response.data.lines ? response.data.lines : [];
      const existing = {};
      let added = 0;

      $form.find('.js-opening-stock-pricing-product').each(function () {
        const value = String($(this).val() || '').trim();
        if (value !== '') {
          existing[value] = true;
        }
      });

      lines.forEach(function (line) {
        if (!line || existing[line.id]) {
          return;
        }

        addLine($form, {
          opening_stock_line_public_id: line.id,
          product_label: line.text,
          imageUrl: line.imageUrl,
          unit: line.unitLabel || line.unit_text,
          quantity: line.quantity,
          productData: line.productData || {}
        }, false);
        existing[line.id] = true;
        added++;
      });

      if (added === 0) {
        showToast('info', trans('no_remaining_lines', 'There are no remaining lines to add.'));
        return;
      }

      updateTotals($form);
    }).fail(function (response) {
      showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error occurred.'));
    }).always(function () {
      setLoading($button, false);
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

    $('[href*="' + data.old_doc_num + '"], [data-delete-url*="' + data.old_doc_num + '"], [data-restore-url*="' + data.old_doc_num + '"]').each(function () {
      const $element = $(this);

      ['href', 'data-delete-url', 'data-restore-url'].forEach(function (attribute) {
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

  function currentRow($target) {
    return $target.closest('.js-opening-stock-pricing-line');
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

  function shortcutBlockedBySelect2(target) {
    const $target = $(target);

    return $target.hasClass('select2-search__field') || $('.select2-container--open').length > 0;
  }

  function initForm() {
    const $form = $('.js-opening-stock-pricing-form').first();

    if ($form.length === 0) {
      return;
    }

    initSelect2(document);
    syncHallVisibility($form);
    applyMainCurrencyExchangeRate($form);
    updateTotals($form);
    $form.find('.js-opening-stock-pricing-line').each(function () {
      syncProductInfoButton($(this));
    });

    $(document)
      .off('click.openingStockPricingsSubmitAction', '.js-opening-stock-pricing-form .js-finance-submit-action, .js-opening-stock-pricing-form .js-submit-action')
      .on('click.openingStockPricingsSubmitAction', '.js-opening-stock-pricing-form .js-finance-submit-action, .js-opening-stock-pricing-form .js-submit-action', function () {
        const $button = $(this);

        $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
        $button.closest('form').data('submit-button', $button);
      })
      .off('submit.openingStockPricingsForm', '.js-opening-stock-pricing-form')
      .on('submit.openingStockPricingsForm', '.js-opening-stock-pricing-form', function (event) {
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
      .off('input.openingStockPricingsValidation change.openingStockPricingsValidation select2:select.openingStockPricingsValidation select2:clear.openingStockPricingsValidation', '.js-opening-stock-pricing-form input, .js-opening-stock-pricing-form select, .js-opening-stock-pricing-form textarea')
      .on('input.openingStockPricingsValidation change.openingStockPricingsValidation select2:select.openingStockPricingsValidation select2:clear.openingStockPricingsValidation', '.js-opening-stock-pricing-form input, .js-opening-stock-pricing-form select, .js-opening-stock-pricing-form textarea', function () {
        clearFieldError($(this));
      })
      .off('select2:select.openingStockPricingsBranch select2:clear.openingStockPricingsBranch', '.js-opening-stock-pricing-branch')
      .on('select2:select.openingStockPricingsBranch select2:clear.openingStockPricingsBranch', '.js-opening-stock-pricing-branch', function () {
        const $currentForm = $(this).closest('.js-opening-stock-pricing-form');
        const $hall = $currentForm.find('.js-opening-stock-pricing-hall');
        const $openingStock = $currentForm.find('.js-opening-stock-pricing-opening-stock');

        syncHallVisibility($currentForm);
        $hall.val(null).trigger('change');
        $openingStock.val(null).trigger('change');
        resetLines($currentForm);
      })
      .off('select2:select.openingStockPricingsHall select2:clear.openingStockPricingsHall', '.js-opening-stock-pricing-hall')
      .on('select2:select.openingStockPricingsHall select2:clear.openingStockPricingsHall', '.js-opening-stock-pricing-hall', function () {
        const $currentForm = $(this).closest('.js-opening-stock-pricing-form');

        $currentForm.find('.js-opening-stock-pricing-opening-stock').val(null).trigger('change');
        resetLines($currentForm);
      })
      .off('select2:select.openingStockPricingsOpeningStock select2:clear.openingStockPricingsOpeningStock', '.js-opening-stock-pricing-opening-stock')
      .on('select2:select.openingStockPricingsOpeningStock select2:clear.openingStockPricingsOpeningStock', '.js-opening-stock-pricing-opening-stock', function () {
        resetLines($(this).closest('.js-opening-stock-pricing-form'));
      })
      .off('select2:select.openingStockPricingsCurrency select2:clear.openingStockPricingsCurrency', '.js-opening-stock-pricing-currency')
      .on('select2:select.openingStockPricingsCurrency select2:clear.openingStockPricingsCurrency', '.js-opening-stock-pricing-currency', function () {
        applyMainCurrencyExchangeRate($(this).closest('.js-opening-stock-pricing-form'));
      })
      .off('select2:select.openingStockPricingsProduct', '.js-opening-stock-pricing-product')
      .on('select2:select.openingStockPricingsProduct', '.js-opening-stock-pricing-product', function (event) {
        const data = event.params ? event.params.data : null;
        const $select = $(this);
        const $row = $select.closest('.js-opening-stock-pricing-line');

        storeSelectedLineData($select, data);
        setLineMetadata($row, data);
      })
      .off('change.openingStockPricingsProductClear select2:clear.openingStockPricingsProductClear', '.js-opening-stock-pricing-product')
      .on('change.openingStockPricingsProductClear select2:clear.openingStockPricingsProductClear', '.js-opening-stock-pricing-product', function () {
        const $select = $(this);
        const $row = $select.closest('.js-opening-stock-pricing-line');

        if (!$select.val()) {
          setLineMetadata($row, {});
          return;
        }

        setLineMetadata($row, {
          unitLabel: selectedOption($select).attr('data-unit-label') || '',
          quantity: selectedOption($select).attr('data-quantity') || '',
          productData: selectedOptionData($select)
        });
      })
      .off('input.openingStockPricingsPrice', '.js-opening-stock-pricing-unit-price')
      .on('input.openingStockPricingsPrice', '.js-opening-stock-pricing-unit-price', function () {
        updateLineTotal($(this).closest('.js-opening-stock-pricing-line'));
      })
      .off('click.openingStockPricingsAddLine', '.js-opening-stock-pricing-add-line')
      .on('click.openingStockPricingsAddLine', '.js-opening-stock-pricing-add-line', function () {
        addLine($(this).closest('.js-opening-stock-pricing-form'), {}, true);
      })
      .off('click.openingStockPricingsAddRemaining', '.js-opening-stock-pricing-add-remaining')
      .on('click.openingStockPricingsAddRemaining', '.js-opening-stock-pricing-add-remaining', function () {
        addRemainingLines($(this));
      })
      .off('click.openingStockPricingsDuplicateLine', '.js-opening-stock-pricing-duplicate-line')
      .on('click.openingStockPricingsDuplicateLine', '.js-opening-stock-pricing-duplicate-line', function () {
        const $row = $(this).closest('.js-opening-stock-pricing-line');
        addLine($row.closest('.js-opening-stock-pricing-form'), lineValues($row), true, $row);
      })
      .off('click.openingStockPricingsRemoveLine', '.js-opening-stock-pricing-remove-line')
      .on('click.openingStockPricingsRemoveLine', '.js-opening-stock-pricing-remove-line', function () {
        removeRow($(this).closest('.js-opening-stock-pricing-line'));
      })
      .off('click.openingStockPricingsProductInfo', '.js-opening-stock-pricing-product-info')
      .on('click.openingStockPricingsProductInfo', '.js-opening-stock-pricing-product-info', function () {
        openProductInfo($(this));
      });

    $form.off('keydown.openingStockPricingsShortcuts').on('keydown.openingStockPricingsShortcuts', function (event) {
      const $target = $(event.target);
      const $row = currentRow($target);
      const isSelect2Blocked = shortcutBlockedBySelect2(event.target);

      if (isAltShortcut(event, ['KeyN'], [78], ['n']) && !isSelect2Blocked) {
        event.preventDefault();
        event.stopPropagation();
        addLine($form, {}, true, $row.length ? $row : null);
        return;
      }

      if (isAltShortcut(event, ['KeyD'], [68], ['d']) && $row.length > 0 && !isSelect2Blocked) {
        event.preventDefault();
        event.stopPropagation();
        addLine($form, lineValues($row), true, $row);
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
        openProductInfo($row.find('.js-opening-stock-pricing-product-info').first());
      }
    });
  }

  function initDocumentNumberSettings() {
    $(document)
      .off('submit.openingStockPricingsDocSettings', '.js-opening-stock-pricings-document-number-settings-form')
      .on('submit.openingStockPricingsDocSettings', '.js-opening-stock-pricings-document-number-settings-form', function (event) {
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
      .off('click.openingStockPricingsDelete', '.js-delete-record[data-delete-url]')
      .on('click.openingStockPricingsDelete', '.js-delete-record[data-delete-url]', function () {
        const $button = $(this);
        const url = $button.data('delete-url');

        if (!url) {
          showToast('error', trans('unexpected_error', 'Unexpected error occurred.'));
          return;
        }

        confirmDialog({
          title: trans('confirm_delete_title', 'Delete Opening Stock Pricing?'),
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

              if (openingStockPricingsTable) {
                openingStockPricingsTable.ajax.reload(null, false);
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
      .off('click.openingStockPricingsRestore', '.js-restore-record[data-restore-url]')
      .on('click.openingStockPricingsRestore', '.js-restore-record[data-restore-url]', function () {
        const $button = $(this);
        const url = $button.data('restore-url');

        if (!url) {
          showToast('error', trans('unexpected_error', 'Unexpected error occurred.'));
          return;
        }

        confirmDialog({
          title: trans('confirm_restore_title', 'Restore Opening Stock Pricing?'),
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

              if (openingStockPricingsTable) {
                openingStockPricingsTable.ajax.reload(null, false);
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

    $('#opening-stock-pricing-product-info-modal')
      .off('hidden.bs.modal.openingStockPricingsProductInfo')
      .on('hidden.bs.modal.openingStockPricingsProductInfo', function () {
        $(this).find('.js-opening-stock-pricing-product-image').attr('src', '').attr('alt', '').addClass('d-none');
        $(this).find('.js-opening-stock-pricing-product-details').empty();
      })
      .off('error.openingStockPricingsProductInfo', '.js-opening-stock-pricing-product-image')
      .on('error.openingStockPricingsProductInfo', '.js-opening-stock-pricing-product-image', function () {
        $(this).addClass('d-none').attr('src', '');
        $('#opening-stock-pricing-product-info-modal').find('.js-opening-stock-pricing-product-no-image').removeClass('d-none').text(trans('no_image', 'No image'));
      });
  }

  $(function () {
    initTable();
    initForm();
    initDocumentNumberSettings();
    initRecordActions();
  });
})(jQuery, window, document);
