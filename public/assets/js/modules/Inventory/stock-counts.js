(function ($, window, document) {
  'use strict';

  const messages = window.stockCountsMessages || {};
  const productLabels = window.stockCountProductLabels || {};
  const csrfToken = $('meta[name="csrf-token"]').attr('content');
  let stockCountsTable = null;

  function trans(key, fallback) {
    return messages[key] || fallback || key;
  }

  function headers() {
    return { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' };
  }

  function showToast(icon, title) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, title);
      return;
    }
    if (window.Swal) {
      window.Swal.fire({ icon: icon, title: title, timer: 1800, showConfirmButton: false });
    }
  }

  function confirmDialog(title, text, confirmButtonText, color) {
    if (!window.Swal) {
      return $.Deferred().resolve({ isConfirmed: window.confirm(title) }).promise();
    }
    return window.Swal.fire({
      icon: 'warning', title: title, text: text, showCancelButton: true, focusCancel: true,
      confirmButtonText: confirmButtonText, cancelButtonText: trans('cancel', 'Cancel'),
      confirmButtonColor: color || '#d33', cancelButtonColor: '#748194'
    });
  }

  function setLoading($button, loading) {
    $button.prop('disabled', loading).css('cursor', loading ? 'wait' : '');
    $('body').css('cursor', loading ? 'wait' : '');
  }

  function columnName(column) {
    const map = {
      doc_num: 'inventory_stock_counts.doc_number', count_date: 'inventory_stock_counts.count_date',
      store: 'branch_stores.name', location: 'warehouse_locations.code', lines_count: 'lines_count',
      system_total: 'system_total', physical_total: 'physical_total', variance_total: 'variance_total',
      status_label: 'inventory_stock_counts.status', approved_by: 'approved_users.name',
      approved_at: 'inventory_stock_counts.approved_at', created_by: 'created_users.name',
      created_at: 'inventory_stock_counts.created_at', updated_by: 'updated_users.name',
      updated_at: 'inventory_stock_counts.updated_at'
    };
    return map[column] || column;
  }

  function tableColumns() {
    const columns = [{ data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all text-center', responsivePriority: 1 }];
    (window.stockCountsColumns || []).forEach(function (column, index) {
      const numeric = ['lines_count', 'system_total', 'physical_total', 'variance_total'].indexOf(column) !== -1;
      columns.push({
        data: column, name: columnName(column),
        className: numeric ? 'align-middle white-space-nowrap dt-number text-center' : 'align-middle white-space-nowrap dt-text dt-ellipsis',
        responsivePriority: index < 4 ? index + 2 : index + 10
      });
    });
    columns.push({ data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'all no-colvis dt-actions', responsivePriority: 2 });
    return columns;
  }

  function initTable() {
    const $table = $('.js-stock-counts-table').first();
    if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
      return;
    }
    const options = window.AppDataTables && typeof window.AppDataTables.options === 'function' ? window.AppDataTables.options : function (value) { return value; };
    stockCountsTable = $table.DataTable(options({
      processing: true, serverSide: true, stateSave: true,
      ajax: { url: $table.data('url'), data: function (data) { data.trash_filter = $('.js-stock-counts-trash-filter').val() || 'active'; } },
      responsive: { details: { type: 'inline', target: 1 } },
      order: [[1, 'desc']], columns: tableColumns(),
      createdRow: function (row) { $(row).addClass('btn-reveal-trigger'); },
      drawCallback: function () {
        if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
          window.AppDataTables.applyFalconEnhancements(document);
        }
      }
    }));
    $('.js-stock-counts-trash-filter').off('change.stockCounts').on('change.stockCounts', function () { stockCountsTable.ajax.reload(null, true); });
    $table.off('dblclick.stockCounts', 'tbody tr:not(.child)').on('dblclick.stockCounts', 'tbody tr:not(.child)', function (event) {
      if ($(event.target).closest('input,button,a,.dropdown,td.dt-select,td.dtr-control').length) return;
      const data = stockCountsTable.row(this).data();
      if (data && data.can_edit) window.location.href = data.edit_url;
      else if (data && data.edit_blocked_message) showToast('warning', data.edit_blocked_message);
    });
  }

  function bracketName(field) {
    const parts = String(field || '').split('.');
    return parts.length < 2 ? field : parts.shift() + parts.map(function (part) { return '[' + part + ']'; }).join('');
  }

  function dotName(name) {
    return String(name || '').replace(/\]/g, '').replace(/\[/g, '.');
  }

  function alertElement($form) {
    return $form.find('.js-form-alert').first();
  }

  function clearErrors($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('.select2-selection.is-invalid').removeClass('is-invalid');
    $form.find('[data-error-for]').text('');
    alertElement($form).addClass('d-none').find('.js-form-alert-message').empty();
  }

  function showErrors($form, errors) {
    clearErrors($form);
    const $list = $('<ul class="mb-0 ps-3"></ul>');
    Object.keys(errors || {}).forEach(function (field) {
      const values = $.isArray(errors[field]) ? errors[field] : [errors[field]];
      values.forEach(function (message) { if (message) $list.append($('<li></li>').text(message)); });
      const $input = $form.find('[name="' + bracketName(field) + '"], [name="' + field + '"]');
      $input.addClass('is-invalid').filter('select').next('.select2-container').find('.select2-selection').addClass('is-invalid');
      $form.find('[data-error-for="' + field + '"], [data-error-for="' + String(field).split('.')[0] + '"]').first().text(values[0] || '');
    });
    alertElement($form).removeClass('d-none').find('.js-form-alert-message').empty().append($list);
    window.scrollTo({ top: Math.max(0, alertElement($form).offset().top - 100), behavior: 'smooth' });
  }

  function initSelect2(root) {
    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') window.AppSelect2Ajax.init(root || document);
  }

  function renumberLines($form) {
    $form.find('.js-stock-count-line').each(function (index) {
      const $row = $(this).attr('data-index', index);
      $row.find('[name]').each(function () { $(this).attr('name', String($(this).attr('name')).replace(/lines\[\d+\]/, 'lines[' + index + ']')); });
      $row.find('[data-error-for]').each(function () {
        $(this).attr('data-error-for', String($(this).attr('data-error-for')).replace(/lines\.(?:\d+|__INDEX__)\./, 'lines.' + index + '.'));
      });
    });
  }

  function emptyRow(index) {
    return $(String($('#stock-count-line-template').html() || '').replace(/__INDEX__/g, String(index)));
  }

  function focusProduct($row) {
    window.setTimeout(function () {
      const $product = $row.find('.js-stock-count-product').first();
      if ($product.data('select2')) $product.select2('open'); else $product.trigger('focus');
    }, 0);
  }

  function rowValues($row) {
    return {
      physical: $row.find('.js-stock-count-physical').val() || '', status: $row.find('.js-stock-count-status').val() || 'available',
      batch: $row.find('.js-stock-count-batch').val() || '', reason: $row.find('.js-stock-count-reason').val() || '', notes: $row.find('.js-stock-count-line-notes').val() || ''
    };
  }

  function addLine($form, values, $after) {
    const $row = emptyRow($form.find('.js-stock-count-line').length);
    if (!$row.length) return;
    if (values) {
      $row.find('.js-stock-count-physical').val(values.physical || '');
      $row.find('.js-stock-count-status').val(values.status || 'available');
      $row.find('.js-stock-count-batch').val(values.batch || '');
      $row.find('.js-stock-count-reason').val(values.reason || '');
      $row.find('.js-stock-count-line-notes').val(values.notes || '');
    }
    if ($after && $after.length) $after.after($row); else $form.find('.js-stock-count-lines tbody').append($row);
    renumberLines($form); initSelect2($row[0]);
    if (window.AppNumbers) window.AppNumbers.refresh($row[0]);
    calculateTotals($form); focusProduct($row);
  }

  function clearRow($row) {
    $row.find('.js-stock-count-product').val(null).trigger('change');
    $row.find('input,textarea').not('[name$="[_delete]"]').val('');
    $row.find('[name$="[_delete]"]').val('0');
    $row.find('.js-stock-count-status').val('available');
    $row.find('.js-stock-count-unit').text('');
    setSystem($row, 0); updateVariance($row);
  }

  function removeLine($row) {
    const $form = $row.closest('form');
    if ($form.find('.js-stock-count-line').length <= 1) clearRow($row); else $row.remove();
    renumberLines($form); calculateTotals($form);
  }

  function numberValue(value) {
    const parsed = Number(String(value === undefined || value === null ? '' : value).replace(/,/g, '').trim());
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function displayNumber(value) {
    return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 8 });
  }

  function setSystem($row, value) {
    $row.find('.js-stock-count-system').attr('data-value', value).text(displayNumber(numberValue(value)));
  }

  function updateVariance($row) {
    const system = numberValue($row.find('.js-stock-count-system').attr('data-value'));
    const physical = numberValue($row.find('.js-stock-count-physical').val());
    const variance = physical - system;
    const $variance = $row.find('.js-stock-count-variance').attr('data-value', variance).text(displayNumber(variance));
    const $type = $row.find('.js-stock-count-variance-type').removeClass('badge-subtle-danger badge-subtle-success badge-subtle-secondary');
    if (variance < 0) $type.addClass('badge-subtle-danger').text(trans('shortage', 'Shortage'));
    else if (variance > 0) $type.addClass('badge-subtle-success').text(trans('surplus', 'Surplus'));
    else $type.addClass('badge-subtle-secondary').text(trans('match', 'Match'));
    $variance.toggleClass('text-danger', variance < 0).toggleClass('text-success', variance > 0);
    calculateTotals($row.closest('form'));
  }

  function calculateTotals($form) {
    let system = 0; let physical = 0; let variance = 0; let shortage = 0; let surplus = 0;
    $form.find('.js-stock-count-line').each(function () {
      const $row = $(this); const rowSystem = numberValue($row.find('.js-stock-count-system').attr('data-value'));
      const rowPhysical = numberValue($row.find('.js-stock-count-physical').val() || $row.find('.js-stock-count-physical').text());
      const rowVariance = numberValue($row.find('.js-stock-count-variance').attr('data-value'));
      system += rowSystem; physical += rowPhysical; variance += rowVariance;
      if (rowVariance < 0) shortage += Math.abs(rowVariance); else surplus += rowVariance;
    });
    $form.find('.js-stock-count-total-system').text(displayNumber(system));
    $form.find('.js-stock-count-total-physical').text(displayNumber(physical));
    $form.find('.js-stock-count-total-variance').text(displayNumber(variance));
    $form.find('.js-stock-count-total-shortage').text(displayNumber(shortage));
    $form.find('.js-stock-count-total-surplus').text(displayNumber(surplus));
  }

  function productValue($row) {
    return String($row.find('.js-stock-count-product').val() || $row.find('.js-stock-count-product-value').val() || '').trim();
  }

  function refreshBalance($row) {
    const $form = $row.closest('form');
    const storeId = $form.find('[name="branch_store_id"]').val();
    const productDocNum = productValue($row);
    if (!storeId || !productDocNum) { setSystem($row, 0); updateVariance($row); return; }
    $row.addClass('opacity-75');
    $.ajax({
      url: $form.data('balance-url'), method: 'GET', headers: headers(), data: {
        branch_store_id: storeId, warehouse_location_id: $form.find('[name="warehouse_location_id"]').val() || '',
        product_doc_num: productDocNum, stock_status: $row.find('.js-stock-count-status').val(), batch_lot: $row.find('.js-stock-count-batch').val() || ''
      }
    }).done(function (response) {
      setSystem($row, response && response.data ? response.data.system_quantity : 0); updateVariance($row);
    }).fail(function () { setSystem($row, 0); updateVariance($row); }).always(function () { $row.removeClass('opacity-75'); });
  }

  function refreshAllBalances($form) {
    $form.find('.js-stock-count-line').each(function () { refreshBalance($(this)); });
  }

  function filterLocations($form) {
    const storeId = String($form.find('.js-stock-count-store').val() || '');
    const $location = $form.find('.js-stock-count-location');
    $location.find('option[data-store-id]').each(function () { $(this).prop('disabled', storeId !== '' && String($(this).data('store-id')) !== storeId); });
    if ($location.find('option:selected').prop('disabled')) $location.val('').trigger('change');
  }

  function renderProductDetails(product) {
    const $modal = $('#stock-count-product-info-modal'); const $details = $modal.find('.js-stock-count-product-details').empty();
    const image = product && product.imageUrl ? String(product.imageUrl) : '';
    $modal.find('.js-stock-count-product-image').toggleClass('d-none', !image).attr('src', image);
    $modal.find('.js-stock-count-product-no-image').toggleClass('d-none', !!image);
    ['doc_num', 'name', 'barcode', 'item_classification', 'unit', 'category', 'group', 'size', 'color', 'model'].forEach(function (field) {
      if (product && product[field]) $details.append($('<dt class="col-sm-4"></dt>').text(productLabels[field] || field)).append($('<dd class="col-sm-8"></dd>').text(product[field]));
    });
    if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance($modal[0]).show();
  }

  function openProductInfo($button) {
    const $row = $button.closest('.js-stock-count-line'); const product = productValue($row);
    if (!product) { showToast('warning', trans('no_product_selected', 'Select a product first.')); return; }
    const url = String($button.closest('form').data('product-details-url-template')).replace('__PRODUCT__', encodeURIComponent(product));
    $.getJSON(url).done(function (response) { renderProductDetails(response.data || {}); }).fail(function () { showToast('error', trans('unexpected_error', 'Unexpected error.')); });
  }

  function updateUrlsAfterDocNumberChange($form, response) {
    const data = response && response.data ? response.data : {}; const urls = data.urls || {};
    if (!data.old_doc_num || !data.doc_num || data.old_doc_num === data.doc_num) return;
    if (urls.update) $form.attr('action', urls.update);
    $('[href*="' + data.old_doc_num + '"], [data-delete-url*="' + data.old_doc_num + '"], [data-restore-url*="' + data.old_doc_num + '"], [data-url*="' + data.old_doc_num + '"]').each(function () {
      const $element = $(this);
      ['href', 'data-delete-url', 'data-restore-url', 'data-url'].forEach(function (attribute) {
        const value = $element.attr(attribute); if (value) $element.attr(attribute, String(value).replace(data.old_doc_num, data.doc_num));
      });
    });
    if (window.history && window.location.pathname.indexOf(data.old_doc_num) !== -1) window.history.replaceState({}, '', window.location.pathname.replace(data.old_doc_num, data.doc_num) + window.location.search + window.location.hash);
  }

  function submitForm($form) {
    const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();
    clearErrors($form); renumberLines($form); setLoading($button, true);
    $.ajax({ url: $form.attr('action'), method: $form.find('[name="_method"]').val() || $form.attr('method'), data: $form.serialize(), headers: headers() })
      .done(function (response) { updateUrlsAfterDocNumberChange($form, response); showToast('success', response.message || trans('saved', 'Saved.')); if (response.redirect) window.location.href = response.redirect; })
      .fail(function (response) {
        if (response.status === 422 && response.responseJSON && response.responseJSON.errors) showErrors($form, response.responseJSON.errors);
        else showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error.'));
      }).always(function () { setLoading($button, false); });
  }

  function isAlt(event, key) {
    return event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey && String(event.key || '').toLowerCase() === key;
  }

  function initForm() {
    const $form = $('.js-stock-count-form').first();
    if (!$form.length) return;
    initSelect2(document); filterLocations($form); calculateTotals($form);
    $(document)
      .off('click.stockCountSubmit', '.js-stock-count-form .js-finance-submit-action')
      .on('click.stockCountSubmit', '.js-stock-count-form .js-finance-submit-action', function () { $(this).closest('form').find('[name="submit_action"]').val($(this).data('submit-action') || 'save'); $(this).closest('form').data('submit-button', $(this)); })
      .off('submit.stockCountForm', '.js-stock-count-form')
      .on('submit.stockCountForm', '.js-stock-count-form', function (event) { event.preventDefault(); submitForm($(this)); })
      .off('click.stockCountAdd', '.js-stock-count-add-line').on('click.stockCountAdd', '.js-stock-count-add-line', function () { addLine($(this).closest('form'), {}, null); })
      .off('click.stockCountDuplicate', '.js-stock-count-duplicate-line').on('click.stockCountDuplicate', '.js-stock-count-duplicate-line', function () { const $row = $(this).closest('tr'); addLine($row.closest('form'), rowValues($row), $row); })
      .off('click.stockCountRemove', '.js-stock-count-remove-line').on('click.stockCountRemove', '.js-stock-count-remove-line', function () { removeLine($(this).closest('tr')); })
      .off('select2:select.stockCountProduct', '.js-stock-count-product').on('select2:select.stockCountProduct', '.js-stock-count-product', function (event) { const data = event.params ? event.params.data : {}; const $row = $(this).closest('tr'); $row.find('.js-stock-count-unit').text(data.unitLabel || data.unit_text || ''); $row.find('.js-stock-count-product-info').prop('disabled', false); refreshBalance($row); })
      .off('select2:clear.stockCountProduct change.stockCountProduct', '.js-stock-count-product').on('select2:clear.stockCountProduct change.stockCountProduct', '.js-stock-count-product', function () { const $row = $(this).closest('tr'); if (!$(this).val()) { $row.find('.js-stock-count-unit').text(''); $row.find('.js-stock-count-product-info').prop('disabled', true); } refreshBalance($row); })
      .off('change.stockCountDimensions', '.js-stock-count-status, .js-stock-count-location').on('change.stockCountDimensions', '.js-stock-count-status, .js-stock-count-location', function () { if ($(this).hasClass('js-stock-count-location')) refreshAllBalances($form); else refreshBalance($(this).closest('tr')); })
      .off('change.stockCountStore', '.js-stock-count-store').on('change.stockCountStore', '.js-stock-count-store', function () { filterLocations($form); refreshAllBalances($form); })
      .off('change.stockCountBatch blur.stockCountBatch', '.js-stock-count-batch').on('change.stockCountBatch blur.stockCountBatch', '.js-stock-count-batch', function () { refreshBalance($(this).closest('tr')); })
      .off('input.stockCountPhysical change.stockCountPhysical', '.js-stock-count-physical').on('input.stockCountPhysical change.stockCountPhysical', '.js-stock-count-physical', function () { updateVariance($(this).closest('tr')); })
      .off('click.stockCountProductInfo', '.js-stock-count-product-info').on('click.stockCountProductInfo', '.js-stock-count-product-info', function () { openProductInfo($(this)); })
      .off('input.stockCountValidation change.stockCountValidation', '.js-stock-count-form input, .js-stock-count-form select, .js-stock-count-form textarea').on('input.stockCountValidation change.stockCountValidation', '.js-stock-count-form input, .js-stock-count-form select, .js-stock-count-form textarea', function () { const field = dotName($(this).attr('name')); $(this).removeClass('is-invalid'); $form.find('[data-error-for="' + field + '"]').text(''); });

    $form.off('keydown.stockCountShortcuts').on('keydown.stockCountShortcuts', function (event) {
      const $row = $(event.target).closest('.js-stock-count-line');
      if ($('.select2-container--open').length) return;
      if (isAlt(event, 'n')) { event.preventDefault(); addLine($form, {}, $row.length ? $row : null); }
      else if (isAlt(event, 'd') && $row.length) { event.preventDefault(); addLine($form, rowValues($row), $row); }
      else if (event.altKey && event.key === 'Delete' && $row.length) { event.preventDefault(); removeLine($row); }
      else if (isAlt(event, 'i') && $row.length) { event.preventDefault(); openProductInfo($row.find('.js-stock-count-product-info')); }
      else if (event.key === 'Enter' && $row.length && !event.ctrlKey && !event.altKey) { event.preventDefault(); $(event.target).closest('td').next().find('input,select,button').first().trigger('focus'); }
    });
  }

  function ajaxAction($button, method, confirmOptions, success) {
    confirmDialog(confirmOptions.title, confirmOptions.text, confirmOptions.confirm, confirmOptions.color).then(function (result) {
      if (!result.isConfirmed) return;
      setLoading($button, true);
      $.ajax({ url: confirmOptions.url, method: method, headers: headers() }).done(function (response) { showToast('success', response.message); if (success) success(response); }).fail(function (response) { showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : trans('unexpected_error', 'Unexpected error.')); }).always(function () { setLoading($button, false); });
    });
  }

  function initActions() {
    $(document)
      .off('click.stockCountApprove', '.js-approve-stock-count').on('click.stockCountApprove', '.js-approve-stock-count', function () { const $button = $(this); ajaxAction($button, 'POST', { url: $button.data('url'), title: trans('confirm_approve_title', 'Approve count?'), text: trans('confirm_approve_text', ''), confirm: trans('confirm_approve_yes', 'Approve'), color: '#00a65a' }, function () { window.location.reload(); }); })
      .off('click.stockCountDelete', '.js-delete-record[data-delete-url]').on('click.stockCountDelete', '.js-delete-record[data-delete-url]', function () { const $button = $(this); ajaxAction($button, 'DELETE', { url: $button.data('delete-url'), title: trans('confirm_delete_title', 'Delete count?'), text: trans('confirm_delete_text', ''), confirm: trans('confirm_delete_yes', 'Delete') }, function () { if (stockCountsTable) stockCountsTable.ajax.reload(null, false); else window.location.href = $button.data('redirect-url'); }); })
      .off('click.stockCountRestore', '.js-restore-record[data-restore-url]').on('click.stockCountRestore', '.js-restore-record[data-restore-url]', function () { const $button = $(this); ajaxAction($button, 'PATCH', { url: $button.data('restore-url'), title: trans('confirm_restore_title', 'Restore count?'), text: trans('confirm_restore_text', ''), confirm: trans('confirm_restore_yes', 'Restore'), color: '#00a65a' }, function () { if (stockCountsTable) stockCountsTable.ajax.reload(null, false); else window.location.reload(); }); });
  }

  function initSettings() {
    $('.js-stock-counts-document-number-settings-form').off('submit.stockCountSettings').on('submit.stockCountSettings', function (event) {
      event.preventDefault(); const $form = $(this); const $button = $form.find('[type="submit"]'); setLoading($button, true); clearErrors($form);
      $.ajax({ url: $form.attr('action'), method: 'PUT', data: $form.serialize(), headers: headers() }).done(function (response) { showToast('success', response.message); }).fail(function (response) { if (response.status === 422) showErrors($form, response.responseJSON.errors); else showToast('error', trans('unexpected_error', 'Unexpected error.')); }).always(function () { setLoading($button, false); });
    });
  }

  $(function () { initTable(); initForm(); initActions(); initSettings(); });
})(window.jQuery, window, document);
