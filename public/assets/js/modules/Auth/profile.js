(function ($, window) {
    'use strict';

    const messages = window.profileMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken,
            Accept: 'application/json'
        };
    }

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function formData($form) {
        return {
            name: String($form.find('[name="name"]').val() || '').trim(),
            username: String($form.find('[name="username"]').val() || '').trim(),
            email: String($form.find('[name="email"]').val() || '').trim(),
            phone: String($form.find('[name="phone"]').val() || '').trim(),
            notes: String($form.find('[name="notes"]').val() || '').trim()
        };
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};

        return {
            name: String(original.name || '').trim(),
            username: String(original.username || '').trim(),
            email: String(original.email || '').trim(),
            phone: String(original.phone || '').trim(),
            notes: String(original.notes || '').trim()
        };
    }

    function hasChanges($form) {
        const original = originalFormData($form);
        const current = formData($form);

        return original.name !== current.name
            || original.username !== current.username
            || original.email !== current.email
            || original.phone !== current.phone
            || original.notes !== current.notes;
    }

    function alertElement($form) {
        let $alert = $form.find('.js-profile-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-profile-alert" role="alert"><span class="js-profile-alert-message"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || '') + '"></button></div>');
            $form.find('.js-profile-form-body, .card-body').first().prepend($alert);
        }

        return $alert;
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

        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');

        validationMessages(errors).forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        $alert
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-profile-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const $input = $form.find('[name="' + field + '"]');

            $input.addClass('is-invalid');
            $form.find('[data-error-for="' + field + '"]').text(message || '');
        });
    }

    function showFormNotice($form, message, type) {
        alertElement($form)
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .find('.js-profile-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-profile-alert-message')
            .empty();
    }

    function setLoading($button, loading) {
        $button.prop('disabled', loading);
        $button.css('cursor', loading ? 'wait' : '');
        $('body').css('cursor', loading ? 'wait' : '');
    }

    function updateOriginalFormData($form) {
        $form.data('original', formData($form));
    }

    function defaultContextData($form) {
        return {
            company_doc_num: String($form.find('[name="company_doc_num"]').val() || '').trim(),
            branch_doc_num: String($form.find('[name="branch_doc_num"]').val() || '').trim(),
            financial_period_doc_num: String($form.find('[name="financial_period_doc_num"]').val() || '').trim()
        };
    }

    function originalDefaultContextData($form) {
        const original = $form.data('original') || {};

        return {
            company_doc_num: String(original.company_doc_num || '').trim(),
            branch_doc_num: String(original.branch_doc_num || '').trim(),
            financial_period_doc_num: String(original.financial_period_doc_num || '').trim()
        };
    }

    function defaultContextHasChanges($form) {
        const original = originalDefaultContextData($form);
        const current = defaultContextData($form);

        return original.company_doc_num !== current.company_doc_num
            || original.branch_doc_num !== current.branch_doc_num
            || original.financial_period_doc_num !== current.financial_period_doc_num;
    }

    function updateOriginalDefaultContextData($form) {
        $form.data('original', defaultContextData($form));
    }

    function setDefaultContextClearEnabled($form, enabled) {
        $form.find('.js-profile-default-context-clear').prop('disabled', !enabled);
    }

    function clearAjaxSelect($select) {
        $select.val(null).find('option').remove().end().trigger('change');
    }

    function clearDefaultContextSelects($form) {
        clearAjaxSelect($form.find('[name="company_doc_num"]'));
        clearAjaxSelect($form.find('[name="branch_doc_num"]'));
        clearAjaxSelect($form.find('[name="financial_period_doc_num"]'));
    }

    $(document).off('click.profileSubmitAction', '.js-profile-submit-action').on('click.profileSubmitAction', '.js-profile-submit-action', function () {
        const $button = $(this);
        $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
        $button.closest('form').data('submit-button', $button);
    });

    $(document).off('submit.profileForm', '.js-profile-form').on('submit.profileForm', '.js-profile-form', function (event) {
        event.preventDefault();

        const $form = $(this);
        const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();

        clearFormErrors($form);

        if (!hasChanges($form)) {
            showFormNotice($form, messages.noChanges, 'warning');
            showToast('info', messages.noChanges);
            return;
        }

        setLoading($button, true);

        $.ajax({
            url: $form.attr('action'),
            method: $form.attr('method') || 'POST',
            data: $form.serialize(),
            headers: headers()
        }).done(function (response) {
            if (response && response.success === false && response.type === 'no_changes') {
                showFormNotice($form, response.message || messages.noChanges, 'warning');
                showToast('info', response.message || messages.noChanges);
                return;
            }

            updateOriginalFormData($form);
            showToast('success', response.message);

            if (response && response.redirect) {
                window.location.href = response.redirect;
            }
        }).fail(function (response) {
            if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                showValidationErrors($form, response.responseJSON.errors);
                return;
            }

            showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
        }).always(function () {
            setLoading($button, false);
        });
    });

    $(document).off('submit.profileDefaultContextForm', '.js-profile-default-context-form').on('submit.profileDefaultContextForm', '.js-profile-default-context-form', function (event) {
        event.preventDefault();

        const $form = $(this);
        const $button = $form.find('.js-profile-default-context-save').first();

        clearFormErrors($form);

        if (!defaultContextHasChanges($form)) {
            showFormNotice($form, messages.noChanges, 'warning');
            showToast('info', messages.noChanges);
            return;
        }

        setLoading($button, true);

        $.ajax({
            url: $form.attr('action'),
            method: $form.attr('method') || 'POST',
            data: $form.serialize(),
            headers: headers()
        }).done(function (response) {
            if (response && response.success === false && response.type === 'no_changes') {
                showFormNotice($form, response.message || messages.noChanges, 'warning');
                showToast('info', response.message || messages.noChanges);
                return;
            }

            updateOriginalDefaultContextData($form);
            setDefaultContextClearEnabled($form, true);
            $form.find('.js-profile-default-context-invalid-alert').addClass('d-none');
            showToast('success', response.message);
        }).fail(function (response) {
            if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                showValidationErrors($form, response.responseJSON.errors);
                return;
            }

            showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
        }).always(function () {
            setLoading($button, false);
        });
    });

    $(document).off('click.profileDefaultContextClear', '.js-profile-default-context-clear').on('click.profileDefaultContextClear', '.js-profile-default-context-clear', function () {
        const $button = $(this);
        const $form = $button.closest('.js-profile-default-context-form');
        const clearUrl = String($form.data('clear-url') || '').trim();

        if (!clearUrl) {
            return;
        }

        clearFormErrors($form);
        setLoading($button, true);

        $.ajax({
            url: clearUrl,
            method: 'DELETE',
            headers: headers()
        }).done(function (response) {
            clearDefaultContextSelects($form);
            updateOriginalDefaultContextData($form);
            setDefaultContextClearEnabled($form, false);
            $form.find('.js-profile-default-context-invalid-alert').addClass('d-none');
            showToast('success', response.message);
        }).fail(function (response) {
            showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
        }).always(function () {
            setLoading($button, false);
        });
    });

    $(document).off('input.profileForm change.profileForm', '.js-profile-form .is-invalid, .js-profile-default-context-form .is-invalid').on('input.profileForm change.profileForm', '.js-profile-form .is-invalid, .js-profile-default-context-form .is-invalid', function () {
        const $input = $(this);
        const field = $input.attr('name') || '';

        $input.removeClass('is-invalid');
        $input.closest('form').find('[data-error-for="' + field + '"]').text('');
    });

    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
        window.AppSelect2Ajax.init(document);
    }
})(jQuery, window);
