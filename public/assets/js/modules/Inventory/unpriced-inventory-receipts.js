(function ($, window, document) {
  'use strict';

  const messages = window.unpricedInventoryReceiptsMessages || {};
  const productLabels = window.unpricedInventoryReceiptProductLabels || {};
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
  let receiptsTable = null;

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

  function parseJson(value) {
    if (!value) {
      return null;
    }

    if (typeof value === 'object') {
      return value;
    }

    try {
      return JSON.parse(String(value));
    } catch (error) {
      return null;
    }
  }

  function selectedOption($select) {
    return $select.find('option:selected').first();
  }

  function selectedProductData($select) {
    return parseJson(selectedOption($select).attr('data-product-data') || selectedOption($select).data('product-data')) || {};
  }

  function selectedUnitOptions($select) {
    const data = parseJson(selectedOption($select).attr('data-unit-options') || selectedOption($select).data('unit-options'));

    return $.isArray(data) ? data : [];
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

    $option.attr('data-unit-options', JSON.stringify(data.unit_options || []));
    $option.attr('data-product-data', JSON.stringify(data.productData || {}));
    if (data.imageUrl) {
      $option.attr('data-image-url', data.imageUrl);
    }
  }

  function rowProductValue($row) {
    return String($row.find('.js-unpriced-inventory-receipt-product').val() || $row.find('.js-unpriced-inventory-receipt-product-value').val() || '').trim();
  }

  function syncProductInfoButton($row) {
    $row.find('.js-unpriced-inventory-receipt-product-info').prop('disabled', rowProductValue($row) === '');
  }

  function populateUnitSelect($row, options, selectedUnit) {
    const $unit = $row.find('.js-unpriced-inventory-receipt-unit').first();

    if ($unit.length === 0) {
      return;
    }

    const selected = selectedUnit || '';

    $unit.empty();
    $unit.append($('<option></option>').attr('value', '').text(trans('select_unit', 'Select Unit')));

    (options || []).forEach(function (option) {
      if (!option || option.id === undefined) {
        return;
      }

      const value = String(option.id);
      $unit.append($('<option></option>').attr('value', value).prop('selected', value === String(selected)).text(option.text || value));
    });

    $unit.prop('disabled', (options || []).length === 0);
  }

  function lineValues($row) {
    const $product = $row.find('.js-unpriced-inventory-receipt-product').first();
    const option = selectedOption($product);
    const unitOptions = selectedUnitOptions($product);

    return {
      public_id: $row.find('input[type="hidden"][name$="[public_id]"]').val() || '',
      product_doc_num: $product.val() || '',
      product_label: option.text() || '',
      imageUrl: option.attr('data-image-url') || '',
      unit_doc_num: $row.find('.js-unpriced-inventory-receipt-unit').val() || '',
      unit: $row.find('.js-unpriced-inventory-receipt-unit option:selected').text() || '',
      unit_options: unitOptions,
      quantity: $row.find('.js-unpriced-inventory-receipt-quantity').val() || '',
      notes: $row.find('.js-unpriced-inventory-receipt-line-notes').val() || '',
      productData: selectedProductData($product)
    };
  }

  function emptyRowTemplate(index) {
    return String($('#unpriced-inventory-receipt-line-template').html() || '').replace(/__INDEX__/g, String(index));
  }

  function renumberLines($form) {
    $form.find('.js-unpriced-inventory-receipt-line').each(function (index) {
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
      const $product = $row.find('.js-unpriced-inventory-receipt-product').first();

      if ($product.length > 0 && $product.data('select2') && typeof $product.select2 === 'function') {
        $product.select2('open');
        return;
      }

      $product.trigger('focus');
    }, 0);
  }

  function initSelect2(root) {
    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
      window.AppSelect2Ajax.init(root || document);
    }
  }

  function addLine($form, values, shouldFocus, $afterRow) {
    const $tbody = $form.find('.js-unpriced-inventory-receipt-lines tbody');
    const index = $form.find('.js-unpriced-inventory-receipt-line').length;
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
    const id = data.product_doc_num || data.id || '';
    const text = data.product_label || data.text || '';
    const unitOptions = $.isArray(data.unit_options) ? data.unit_options : [];
    const productData = data.productData || data.product_data || {};

    if (id) {
      const option = new Option(text || id, id, true, true);
      $(option)
        .attr('data-unit-options', JSON.stringify(unitOptions))
        .attr('data-product-data', JSON.stringify(productData));
      if (data.imageUrl) {
        $(option).attr('data-image-url', data.imageUrl);
      }
      $row.find('.js-unpriced-inventory-receipt-product').append(option);
    }

    populateUnitSelect($row, unitOptions, data.unit_doc_num || '');
    $row.find('.js-unpriced-inventory-receipt-quantity').val(data.quantity || '');
    $row.find('.js-unpriced-inventory-receipt-line-notes').val(data.notes || '');
    syncProductInfoButton($row);
    renumberLines($form);
    initSelect2($row[0]);
    window.AppNumbers.refresh($row[0]);

    if (shouldFocus !== false) {
      focusProduct($row);
    }

    return $row;
  }

  function clearRow($row, shouldFocus) {
    $row.find('.js-unpriced-inventory-receipt-product').val(null).trigger('change');
    $row.find('input[type="hidden"][name$="[public_id]"]').val('');
    $row.find('input[type="hidden"][name$="[_delete]"]').val('0');
    populateUnitSelect($row, [], '');
    $row.find('.js-unpriced-inventory-receipt-quantity, .js-unpriced-inventory-receipt-line-notes').val('');
    syncProductInfoButton($row);
    if (shouldFocus !== false) {
      focusProduct($row);
    }
  }

  function removeRow($row) {
    const $form = $row.closest('.js-unpriced-inventory-receipt-form');
    const $rows = $form.find('.js-unpriced-inventory-receipt-line');
    const $focusTarget = $row.next('.js-unpriced-inventory-receipt-line').length ? $row.next('.js-unpriced-inventory-receipt-line') : $row.prev('.js-unpriced-inventory-receipt-line');

    if ($rows.length <= 1) {
      clearRow($row);
      return;
    }

    $row.remove();
    renumberLines($form);
    focusProduct($focusTarget);
  }

  function filterData() {
    return {
      trash_filter: String($('.js-unpriced-inventory-receipts-trash-filter').val() || 'active')
    };
  }

  function columnName(column) {
    const map = {
      doc_num: 'unpriced_inventory_receipts.doc_number',
      document_date: 'unpriced_inventory_receipts.document_date',
      financial_period: 'financial_periods.name',
      branch: 'branches.name',
      hall: 'branch_halls.name',
      store: 'branch_stores.name',
      supplier_reference: 'suppliers.name',
      lines_count: 'lines_count',
      total_quantity: 'total_quantity',
      status: 'unpriced_inventory_receipts.status',
      pricing_status: 'unpriced_inventory_receipts.pricing_status',
      approved_by: 'approved_users.name',
      approved_at: 'unpriced_inventory_receipts.approved_at',
      created_by: 'created_users.name',
      created_at: 'unpriced_inventory_receipts.created_at',
      updated_by: 'updated_users.name',
      updated_at: 'unpriced_inventory_receipts.updated_at'
    };

    return map[column] || column;
  }

  function columnClass(column, index) {
    if (index === 0) {
      return 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control';
    }

    if (['status', 'pricing_status'].indexOf(column) !== -1) {
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
    const configured = window.unpricedInventoryReceiptsColumns || [];
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
    const $table = $('.js-unpriced-inventory-receipts-table').first();

    if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
      return;
    }

    const protectedColumns = [0, 1, -1];
    const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
      ? window.AppDataTables.options
      : function (options) { return options; };

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

    receiptsTable = $table.DataTable(dataTableOptions({
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
          $.extend(data, filterData());
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
      .off('dblclick.unpricedInventoryReceiptsEditRow', 'tbody tr:not(.child)')
      .on('dblclick.unpricedInventoryReceiptsEditRow', 'tbody tr:not(.child)', function (event) {
        if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
          return;
        }

        const data = receiptsTable && typeof receiptsTable.row === 'function' ? receiptsTable.row(this).data() : null;

        if (data && data.can_edit && data.edit_url) {
          window.location.href = data.edit_url;
          return;
        }

        if (data && data.edit_blocked_message) {
          showToast('warning', data.edit_blocked_message);
        }
      });

    $('.js-unpriced-inventory-receipts-trash-filter, .js-unpriced-inventory-receipts-filter')
      .off('change.unpricedInventoryReceiptsTable keyup.unpricedInventoryReceiptsTable')
      .on('change.unpricedInventoryReceiptsTable keyup.unpricedInventoryReceiptsTable', function () {
        receiptsTable.ajax.reload(null, true);
      });
  }

  function submitForm($form, $button) {
    clearFormErrors($form);
    setLoading($button, true);

    $.ajax({
      url: $form.attr('action'),
      method: ($form.find('input[name="_method"]').val() || $form.attr('method') || 'POST').toUpperCase(),
      data: $form.serialize(),
      headers: headers()
    }).done(function (response) {
      showToast('success', response.message || trans('saved', 'Saved successfully.'));
      if (response.redirect) {
        window.location.href = response.redirect;
        return;
      }
      if (response.data && response.data.urls && response.data.urls.show) {
        window.location.href = response.data.urls.show;
      }
    }).fail(function (xhr) {
      if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
        showValidationErrors($form, xhr.responseJSON.errors);
        return;
      }

      showToast('error', (xhr.responseJSON && xhr.responseJSON.message) || trans('unexpected_error', 'Unexpected error occurred.'));
    }).always(function () {
      setLoading($button, false);
    });
  }

  function submitSettingsForm($form, $button) {
    clearFormErrors($form);
    setLoading($button, true);

    $.ajax({
      url: $form.attr('action'),
      method: 'PUT',
      data: $form.serialize(),
      headers: headers()
    }).done(function (response) {
      showToast('success', response.message || trans('saved', 'Saved successfully.'));
    }).fail(function (xhr) {
      if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
        showValidationErrors($form, xhr.responseJSON.errors);
        return;
      }

      showToast('error', (xhr.responseJSON && xhr.responseJSON.message) || trans('unexpected_error', 'Unexpected error occurred.'));
    }).always(function () {
      setLoading($button, false);
    });
  }

  function actionRequest($button, options) {
    const url = $button.data('url') || $button.data('delete-url') || $button.data('restore-url');

    if (!url) {
      return;
    }

    confirmDialog(options.confirm || {}).then(function (result) {
      if (!result.isConfirmed) {
        return;
      }

      setLoading($button, true);

      $.ajax({
        url: url,
        method: options.method || 'POST',
        headers: headers()
      }).done(function (response) {
        showToast('success', response.message || trans('saved', 'Saved successfully.'));
        if ($button.data('redirect-url')) {
          window.location.href = $button.data('redirect-url');
          return;
        }
        if (receiptsTable) {
          receiptsTable.ajax.reload(null, false);
          return;
        }
        if (response.data && response.data.urls && response.data.urls.show) {
          window.location.href = response.data.urls.show;
          return;
        }
        window.location.reload();
      }).fail(function (xhr) {
        showToast('error', (xhr.responseJSON && xhr.responseJSON.message) || trans('unexpected_error', 'Unexpected error occurred.'));
      }).always(function () {
        setLoading($button, false);
      });
    });
  }

  function showProductDetails($button) {
    const $row = $button.closest('.js-unpriced-inventory-receipt-line');
    const productDocNum = rowProductValue($row);
    const $form = $button.closest('form');
    const template = String($form.data('product-details-url-template') || '');

    if (!productDocNum) {
      showToast('warning', trans('no_product_selected', 'Select a product first.'));
      return;
    }

    function render(data) {
      const $modal = $('#unpriced-inventory-receipt-product-info-modal');
      const $image = $modal.find('.js-unpriced-inventory-receipt-product-image');
      const $noImage = $modal.find('.js-unpriced-inventory-receipt-product-no-image');
      const $details = $modal.find('.js-unpriced-inventory-receipt-product-details');

      $details.empty();
      Object.keys(productLabels).forEach(function (key) {
        const value = data && data[key] !== undefined && data[key] !== null && data[key] !== '' ? data[key] : '';
        if (value === '') {
          return;
        }
        $details.append($('<dt class="col-sm-4"></dt>').text(productLabels[key]));
        $details.append($('<dd class="col-sm-8"></dd>').text(value));
      });

      if (data && data.imageUrl) {
        $image.attr('src', data.imageUrl).removeClass('d-none');
        $noImage.addClass('d-none');
      } else {
        $image.attr('src', '').addClass('d-none');
        $noImage.removeClass('d-none');
      }

      if (window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance($modal[0]).show();
      }
    }

    const cached = selectedProductData($row.find('.js-unpriced-inventory-receipt-product').first());
    if (cached && Object.keys(cached).length > 0) {
      render(cached);
      return;
    }

    $.ajax({
      url: template.replace('__PRODUCT__', encodeURIComponent(productDocNum)),
      method: 'GET',
      headers: headers()
    }).done(function (response) {
      render(response.data || {});
    }).fail(function () {
      showToast('error', trans('unexpected_error', 'Unexpected error occurred.'));
    });
  }

  function bindEvents() {
    $(document)
      .off('submit.unpricedInventoryReceiptForm', '.js-unpriced-inventory-receipt-form')
      .on('submit.unpricedInventoryReceiptForm', '.js-unpriced-inventory-receipt-form', function (event) {
        event.preventDefault();
        submitForm($(this), $(document.activeElement).is('button') ? $(document.activeElement) : $(this).find('[type="submit"]').first());
      })
      .off('click.unpricedInventoryReceiptSubmitAction', '.js-finance-submit-action')
      .on('click.unpricedInventoryReceiptSubmitAction', '.js-finance-submit-action', function () {
        const $button = $(this);
        const $form = $button.closest('form');
        $form.find('input[name="submit_action"]').val($button.data('submit-action') || 'save');
      })
      .off('submit.unpricedInventoryReceiptSettings', '.js-unpriced-inventory-receipts-document-number-settings-form')
      .on('submit.unpricedInventoryReceiptSettings', '.js-unpriced-inventory-receipts-document-number-settings-form', function (event) {
        event.preventDefault();
        submitSettingsForm($(this), $(this).find('[type="submit"]').first());
      })
      .off('click.unpricedInventoryReceiptAddLine', '.js-unpriced-inventory-receipt-add-line')
      .on('click.unpricedInventoryReceiptAddLine', '.js-unpriced-inventory-receipt-add-line', function () {
        addLine($(this).closest('form'), {}, true);
      })
      .off('click.unpricedInventoryReceiptDuplicateLine', '.js-unpriced-inventory-receipt-duplicate-line')
      .on('click.unpricedInventoryReceiptDuplicateLine', '.js-unpriced-inventory-receipt-duplicate-line', function () {
        const $row = $(this).closest('.js-unpriced-inventory-receipt-line');
        addLine($row.closest('form'), lineValues($row), true, $row);
      })
      .off('click.unpricedInventoryReceiptRemoveLine', '.js-unpriced-inventory-receipt-remove-line')
      .on('click.unpricedInventoryReceiptRemoveLine', '.js-unpriced-inventory-receipt-remove-line', function () {
        removeRow($(this).closest('.js-unpriced-inventory-receipt-line'));
      })
      .off('select2:select.unpricedInventoryReceiptProduct', '.js-unpriced-inventory-receipt-product')
      .on('select2:select.unpricedInventoryReceiptProduct', '.js-unpriced-inventory-receipt-product', function (event) {
        const $select = $(this);
        const data = event.params ? event.params.data : null;
        storeSelectedProductData($select, data);
        populateUnitSelect($select.closest('.js-unpriced-inventory-receipt-line'), data && data.unit_options ? data.unit_options : [], data && data.unit_options && data.unit_options[0] ? data.unit_options[0].id : '');
        syncProductInfoButton($select.closest('.js-unpriced-inventory-receipt-line'));
        clearFieldError($select);
      })
      .off('change.unpricedInventoryReceiptProduct', '.js-unpriced-inventory-receipt-product')
      .on('change.unpricedInventoryReceiptProduct', '.js-unpriced-inventory-receipt-product', function () {
        const $select = $(this);
        const $row = $select.closest('.js-unpriced-inventory-receipt-line');
        if (!$select.val()) {
          populateUnitSelect($row, [], '');
        } else if ($row.find('.js-unpriced-inventory-receipt-unit option').length <= 1) {
          populateUnitSelect($row, selectedUnitOptions($select), '');
        }
        syncProductInfoButton($row);
        clearFieldError($select);
      })
      .off('change.unpricedInventoryReceiptField keyup.unpricedInventoryReceiptField', '.js-unpriced-inventory-receipt-form input, .js-unpriced-inventory-receipt-form select, .js-unpriced-inventory-receipt-form textarea')
      .on('change.unpricedInventoryReceiptField keyup.unpricedInventoryReceiptField', '.js-unpriced-inventory-receipt-form input, .js-unpriced-inventory-receipt-form select, .js-unpriced-inventory-receipt-form textarea', function () {
        clearFieldError($(this));
      })
      .off('click.unpricedInventoryReceiptProductInfo', '.js-unpriced-inventory-receipt-product-info')
      .on('click.unpricedInventoryReceiptProductInfo', '.js-unpriced-inventory-receipt-product-info', function () {
        showProductDetails($(this));
      })
      .off('click.unpricedInventoryReceiptApprove', '.js-approve-unpriced-inventory-receipt')
      .on('click.unpricedInventoryReceiptApprove', '.js-approve-unpriced-inventory-receipt', function () {
        actionRequest($(this), {
          method: 'POST',
          confirm: {
            title: trans('approve_confirm_title', ''),
            confirmButtonText: trans('approve_confirm_yes', 'Approve'),
            confirmButtonColor: '#00a854'
          }
        });
      })
      .off('click.unpricedInventoryReceiptClose', '.js-close-unpriced-inventory-receipt')
      .on('click.unpricedInventoryReceiptClose', '.js-close-unpriced-inventory-receipt', function () {
        actionRequest($(this), {
          method: 'POST',
          confirm: {
            title: trans('close_confirm_title', ''),
            confirmButtonText: trans('close_confirm_yes', 'Close'),
            confirmButtonColor: '#2c7be5'
          }
        });
      })
      .off('click.unpricedInventoryReceiptCancel', '.js-cancel-unpriced-inventory-receipt')
      .on('click.unpricedInventoryReceiptCancel', '.js-cancel-unpriced-inventory-receipt', function () {
        actionRequest($(this), {
          method: 'POST',
          confirm: {
            title: trans('cancel_confirm_title', ''),
            text: trans('cancel_confirm_text', ''),
            confirmButtonText: trans('cancel_confirm_yes', 'Cancel document'),
            confirmButtonColor: '#d33'
          }
        });
      })
      .off('click.unpricedInventoryReceiptDelete', '.js-delete-record')
      .on('click.unpricedInventoryReceiptDelete', '.js-delete-record', function () {
        actionRequest($(this), {
          method: 'DELETE',
          confirm: {
            title: trans('confirm_delete_title', ''),
            text: trans('confirm_delete_text', ''),
            confirmButtonText: trans('confirm_delete_yes', 'Delete')
          }
        });
      })
      .off('click.unpricedInventoryReceiptRestore', '.js-restore-record')
      .on('click.unpricedInventoryReceiptRestore', '.js-restore-record', function () {
        actionRequest($(this), {
          method: 'PATCH',
          confirm: {
            title: trans('confirm_restore_title', ''),
            text: trans('confirm_restore_text', ''),
            confirmButtonText: trans('confirm_restore_yes', 'Restore'),
            confirmButtonColor: '#00a854'
          }
        });
      });
  }

  $(function () {
    initSelect2(document);
    initTable();
    bindEvents();
    $('.js-unpriced-inventory-receipt-line').each(function () {
      const $row = $(this);
      const $product = $row.find('.js-unpriced-inventory-receipt-product').first();
      if ($product.length) {
        populateUnitSelect($row, selectedUnitOptions($product), $row.find('.js-unpriced-inventory-receipt-unit').val() || '');
      }
      syncProductInfoButton($row);
    });
  });
})(jQuery, window, document);
