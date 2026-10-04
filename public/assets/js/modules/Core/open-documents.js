(function ($, window, document) {
  'use strict';

  const csrfToken = $('meta[name="csrf-token"]').attr('content');
  const appSelect2 = window.AppSelect2 || {};
  const messages = window.openDocumentsMessages || {};

  function headers() {
    return {
      'X-CSRF-TOKEN': csrfToken,
      Accept: 'application/json'
    };
  }

  function select2Language() {
    const select2Messages = appSelect2.messages || {};

    return {
      errorLoading: function () { return select2Messages.errorLoading || ''; },
      inputTooShort: function () { return select2Messages.inputTooShort || ''; },
      loadingMore: function () { return select2Messages.loadingMore || ''; },
      noResults: function () { return select2Messages.noResults || ''; },
      searching: function () { return select2Messages.searching || ''; }
    };
  }

  function dropdownParent($field) {
    const $modal = $field.closest('.modal');

    return $modal.length > 0 ? $modal : $(document.body);
  }

  function initSelect2(root) {
    if (!$.fn.select2) {
      return;
    }

    $(root).find('.js-open-documents-type').each(function () {
      const $field = $(this);

      if ($field.data('openDocumentsSelect2')) {
        return;
      }

      $field.select2({
        allowClear: true,
        dir: document.documentElement.getAttribute('dir') || 'ltr',
        dropdownParent: dropdownParent($field),
        language: select2Language(),
        placeholder: $field.data('placeholder') || '',
        theme: 'bootstrap-5',
        width: '100%'
      });

      $field.data('openDocumentsSelect2', true);
    });
  }

  function updateReasonRequirement($form) {
    const $reason = $form.find('[name="reason"]');
    const requiredTypes = String($reason.data('required-types') || '').split(',');
    const required = requiredTypes.indexOf(String($form.find('[name="document_type"]').val() || '')) !== -1;

    $reason.prop('required', required).attr('aria-required', required ? 'true' : 'false');
    const sourcePeriodSupported = ['purchase_receipts', 'purchase_invoices', 'production_runs', 'inventory_movement_corrections'].indexOf(String($form.find('[name="document_type"]').val() || '')) !== -1;
    $form.find('.js-purchase-source-period').toggleClass('d-none', !sourcePeriodSupported);
    $form.find('[name="source_period_doc_num"]').prop('disabled', !sourcePeriodSupported);
  }

  function showToast(icon, title) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, title);
    }
  }

  function confirmExecution($form) {
    const $type = $form.find('[name="document_type"]');
    const selectedType = $type.find('option:selected').text().trim();
    const selection = selectedType + ' (' + String($form.find('[name="from_number"]').val() || '') + '–' + String($form.find('[name="to_number"]').val() || '') + ')';

    if (!window.Swal) {
      return $.Deferred().resolve({ isConfirmed: window.confirm(($form.data('confirm-title') || '') + '\n' + selection) }).promise();
    }

    return window.Swal.fire({
      icon: 'warning',
      title: $form.data('confirm-title') || '',
      text: selection,
      showCloseButton: true,
      showCancelButton: true,
      focusCancel: true,
      allowEscapeKey: true,
      confirmButtonText: $form.data('confirm-yes') || '',
      cancelButtonText: $form.data('confirm-no') || '',
      confirmButtonColor: '#2c7be5',
      cancelButtonColor: '#748194'
    });
  }

  function clearFieldError($form, field) {
    if (!field) {
      return;
    }

    $form.find('[name="' + field + '"]').removeClass('is-invalid');
    if (field === 'document_type') {
      $form.find('.js-open-documents-type').next('.select2-container').find('.select2-selection').removeClass('is-invalid');
    }
    $form.find('[data-error-for="' + field + '"]').text('');
  }

  function clearErrors($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('[data-error-for]').text('');
  }

  function showErrors($form, errors) {
    Object.keys(errors || {}).forEach(function (field) {
      const values = $.isArray(errors[field]) ? errors[field] : [errors[field]];
      const message = values.filter(Boolean).join(' ');

      $form.find('[name="' + field + '"]').addClass('is-invalid');
      if (field === 'document_type') {
        $form.find('.js-open-documents-type').next('.select2-container').find('.select2-selection').addClass('is-invalid');
      }
      $form.find('[data-error-for="' + field + '"]').text(message);
    });
  }

  function resultAlert($form) {
    return $form.find('.js-open-documents-result').first();
  }

  function showResult($form, response) {
    const $alert = resultAlert($form);
    const $message = $alert.find('.js-open-documents-result-message').first();
    const $list = $alert.find('.js-open-documents-result-list').first();
    const resultMessages = $.isArray(response.messages) ? response.messages : [];

    $alert
      .removeClass('d-none alert-success alert-warning alert-danger')
      .addClass(response.success ? 'alert-success' : 'alert-warning');

    $message.text(response.message || '');
    $list.empty();

    resultMessages.forEach(function (message) {
      $('<li></li>').text(message || '').appendTo($list);
    });

    showToast(response.success ? 'success' : 'warning', response.message || '');
  }

  function showFailure($form, message) {
    const $alert = resultAlert($form);

    $alert
      .removeClass('d-none alert-success alert-warning')
      .addClass('alert-danger')
      .find('.js-open-documents-result-message')
      .first()
      .text(message || messages.unexpectedError || '');

    $alert.find('.js-open-documents-result-list').empty();
  }

  function selectionKey($form) {
    return JSON.stringify($form.serializeArray().filter(function (field) {
      return field.name !== 'preview_token' && field.name !== '_token' && field.name !== 'reason';
    }));
  }

  function clearPreview($form) {
    const $preview = $form.find('.js-open-documents-preview');
    $preview.addClass('d-none').removeData('selection').removeData('navigationOnly');
    $preview.find('.js-open-documents-preview-rows').empty();
    $form.find('[name="preview_token"]').val('');
    $form.find('.js-open-documents-action-text').text($form.data('preview-label') || '');
  }

  function showPreview($form, response) {
    const $preview = $form.find('.js-open-documents-preview');
    const $rows = $preview.find('.js-open-documents-preview-rows');
    $rows.empty();

    (response.documents || []).forEach(function (row) {
      const $tr = $('<tr></tr>');
      const $document = $('<td></td>');
      if (row.source_url) {
        $('<a></a>').attr('href', row.source_url).attr('target', '_blank').attr('rel', 'noopener').text(row.doc_num || '').appendTo($document);
      } else {
        $document.text(row.doc_num || '');
      }
      $tr.append($document);
      $('<td></td>').text(row.status_label || row.status || '').appendTo($tr);
      const $decision = $('<td></td>').append($('<div></div>').text(row.decision_label || ''));
      if (row.correction_url) {
        $('<a class="btn btn-sm btn-outline-primary mt-1"></a>')
          .attr('href', row.correction_url).attr('target', '_blank').attr('rel', 'noopener')
          .text(messages.reviewCorrection || '').appendTo($decision);
      }
      $decision.appendTo($tr);
      $('<td></td>').text(row.current_total === null ? '—' : row.current_total).appendTo($tr);
      $('<td></td>').text(row.line_count === null ? '—' : row.line_count).appendTo($tr);
      const dependencies = Object.keys(row.dependent_documents || {}).map(function (label) {
        return label + ': ' + row.dependent_documents[label].join(', ');
      });
      $('<td></td>').text(dependencies.join(' · ') || '—').appendTo($tr);
      $('<td></td>').text(row.posting_effect || '').appendTo($tr);
      $rows.append($tr);
      if (Array.isArray(row.correction_steps) && row.correction_steps.length > 0) {
        const $steps = $('<td colspan="7"></td>');
        $('<h6></h6>').text(messages.correctionSteps || '').appendTo($steps);
        $('<p class="small text-muted"></p>').text(messages.correctionHelp || '').appendTo($steps);
        const $list = $('<ol class="mb-0"></ol>').appendTo($steps);
        row.correction_steps.forEach(function (step) {
          const $item = $('<li class="mb-2"></li>').appendTo($list);
          $('<span></span>').text((step.doc_num || '') + ' — ' + (step.action || '')).appendTo($item);
          $('<small class="d-block text-muted"></small>').text(step.context_label || '').appendTo($item);
          if (step.source_url) {
            $('<a class="ms-2"></a>').attr('href', step.source_url).attr('target', '_blank').attr('rel', 'noopener')
              .text(step.doc_num || '').appendTo($item);
          }
          if (step.correction_url) {
            $('<a class="btn btn-sm btn-outline-primary ms-2"></a>').attr('href', step.correction_url)
              .attr('target', '_blank').attr('rel', 'noopener').text(messages.reviewCorrection || '').appendTo($item);
          }
          if (!step.permitted) {
            $('<small class="d-block text-muted"></small>').text(messages.correctionPermission || '').appendTo($item);
          }
        });
        $rows.append($('<tr></tr>').append($steps));
      }

      if (Array.isArray(row.correction_lines) && row.correction_lines.length > 0) {
        const $details = $('<tr class="table-light"></tr>');
        const $cell = $('<td colspan="7"></td>');
        const $table = $('<table class="table table-sm table-bordered align-middle mb-0"></table>');
        const $head = $('<thead><tr></tr></thead>');
        const columns = [
          ['product', messages.correctionItem],
          ['quantity', messages.correctionQuantity],
          ['before_quantity', messages.correctionBefore],
          ['after_quantity', messages.correctionAfter],
          ['layer_available', messages.correctionLayer],
          ['value_delta', messages.correctionValue]
        ];
        columns.forEach(function (column) { $('<th></th>').text(column[1] || '').appendTo($head.find('tr')); });
        $table.append($head);
        const $body = $('<tbody></tbody>');
        row.correction_lines.forEach(function (line) {
          const $line = $('<tr></tr>');
          columns.forEach(function (column) {
            const value = line[column[0]];
            $('<td></td>').text(value === null || value === undefined ? '—' : value).appendTo($line);
          });
          $body.append($line);
        });
        $table.append($body);
        $('<div class="table-responsive"></div>').append($table).appendTo($cell);
        $details.append($cell);
        $rows.append($details);
      }
    });

    const periodText = String(messages.previewPeriod || '').replace(':period', response.period || '');
    $preview.find('.js-open-documents-preview-context').text(periodText + (response.range_hint ? ' · ' + response.range_hint : ''));
    $preview.find('.js-open-documents-preview-missing').text(String(messages.previewNotFound || '').replace(':count', response.not_found || 0));
    $form.find('[name="preview_token"]').val(response.preview_token || '');
    $preview.data('selection', selectionKey($form)).data('navigationOnly', response.navigation_only === true).removeClass('d-none');
    $form.find('.js-open-documents-action-text').text($form.data(response.navigation_only ? 'preview-label' : 'confirm-label') || '');
  }

  function previewForm($form) {
    const $buttons = $form.find('.js-open-documents-submit');
    $buttons.prop('disabled', true);

    $.ajax({
      url: $form.data('preview-url'),
      method: 'POST',
      data: $form.serialize(),
      headers: headers()
    }).done(function (response) {
      showPreview($form, response || {});
    }).fail(function (xhr) {
      if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
        showErrors($form, xhr.responseJSON.errors);
      }
      showFailure($form, (xhr.responseJSON && xhr.responseJSON.message) || $form.data('error-message') || messages.unexpectedError || '');
    }).always(function () {
      $buttons.prop('disabled', false);
    });
  }

  function submitForm($form) {
    const $buttons = $form.find('.js-open-documents-submit');

    $buttons.prop('disabled', true);

    $.ajax({
      url: $form.attr('action'),
      method: $form.attr('method') || 'POST',
      data: $form.serialize(),
      headers: headers()
    }).done(function (response) {
      showResult($form, response || {});
      clearPreview($form);
    }).fail(function (xhr) {
      if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
        showErrors($form, xhr.responseJSON.errors);
        showFailure($form, xhr.responseJSON.message || $form.data('validation-message') || messages.validationFailed || '');
        return;
      }

      showFailure($form, (xhr.responseJSON && xhr.responseJSON.message) || $form.data('error-message') || messages.unexpectedError || '');
    }).always(function () {
      $buttons.prop('disabled', false);
    });
  }

  $(function () {
    initSelect2(document);
    $('.js-open-documents-form').each(function () { updateReasonRequirement($(this)); });

    $(document).on('change', '.js-open-documents-type', function () {
      updateReasonRequirement($(this).closest('.js-open-documents-form'));
    });

    $(document).on('input change', '.js-open-documents-form :input[name]', function () {
      const $form = $(this).closest('.js-open-documents-form');
      clearFieldError($form, $(this).attr('name'));
      if ($(this).attr('name') !== 'preview_token' && $(this).attr('name') !== 'reason') {
        clearPreview($form);
      }
    });

    $(document).on('submit', '.js-open-documents-form', function (event) {
      event.preventDefault();

      const $form = $(this);

      clearErrors($form);

      if ($form.find('.js-open-documents-preview').data('selection') !== selectionKey($form)
          || $form.find('.js-open-documents-preview').data('navigationOnly') === true) {
        previewForm($form);
        return;
      }

      confirmExecution($form).then(function (result) {
        if (result && result.isConfirmed) {
          submitForm($form);
        }
      });
    });
  });
})(jQuery, window, document);
