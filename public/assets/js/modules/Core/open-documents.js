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

  function showToast(icon, title) {
    if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
      window.AppAlerts.toast(icon, title);
    }
  }

  function confirmExecution($form) {
    if (!window.Swal) {
      return $.Deferred().resolve({ isConfirmed: window.confirm($form.data('confirm-title') || '') }).promise();
    }

    return window.Swal.fire({
      icon: 'warning',
      title: $form.data('confirm-title') || '',
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

    $(document).on('input change', '.js-open-documents-form :input[name]', function () {
      clearFieldError($(this).closest('.js-open-documents-form'), $(this).attr('name'));
    });

    $(document).on('submit', '.js-open-documents-form', function (event) {
      event.preventDefault();

      const $form = $(this);

      clearErrors($form);

      confirmExecution($form).then(function (result) {
        if (result && result.isConfirmed) {
          submitForm($form);
        }
      });
    });
  });
})(jQuery, window, document);
