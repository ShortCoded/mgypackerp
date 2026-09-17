(function ($, window, document) {
    'use strict';

    const config = window.AppAjaxErrorsConfig || {};
    let authRecoveryPending = false;

    function payload(response) {
        return response && response.responseJSON && typeof response.responseJSON === 'object'
            ? response.responseJSON
            : {};
    }

    function statusMessage(response) {
        const status = response && response.status ? response.status : 0;
        const messages = config.messages || {};

        if (status === 401) {
            return messages.authenticationRequired;
        }

        if (status === 403) {
            return messages.permissionDenied;
        }

        if (status === 404) {
            return messages.notFound;
        }

        if (status === 409) {
            return messages.conflict;
        }

        if (status === 419) {
            return messages.sessionExpired;
        }

        if (status === 422) {
            return messages.validationFailed;
        }

        if (status === 429) {
            return messages.rateLimited;
        }

        return status === 0 ? messages.networkError : messages.unexpected;
    }

    function message(response) {
        const data = payload(response);

        return typeof data.message === 'string' && data.message.trim() !== ''
            ? data.message
            : statusMessage(response);
    }

    function bracketName(field) {
        const parts = String(field || '').split('.');

        return parts.shift() + parts.map(function (part) {
            return '[' + part + ']';
        }).join('');
    }

    function fieldInput($form, field) {
        const names = [String(field), bracketName(field)];
        let $input = $();

        names.some(function (name) {
            $input = $form.find('[name="' + String(name).replace(/"/g, '\\"') + '"]').first();

            return $input.length > 0;
        });

        return $input;
    }

    function errorElement($form, field, $input) {
        const escapedField = String(field).replace(/"/g, '\\"');
        let $error = $form.find('[data-error-for="' + escapedField + '"]').first();

        if (!$error.length && $input.length) {
            $error = $input.closest('.form-group, .mb-3, .col, [class*="col-"]').find('.invalid-feedback').first();
        }

        return $error;
    }

    function clear($form) {
        if (!$form || !$form.length) {
            return;
        }

        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.select2-container.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('[data-app-ajax-summary="true"]').addClass('d-none').text('');
    }

    function summaryElement($form) {
        let $alert = $form.find('.js-form-alert, .js-business-alert, [data-form-alert]').first();

        if (!$alert.length) {
            $alert = $('<div class="alert alert-danger d-none" role="alert" data-app-ajax-summary="true"></div>');
            $form.prepend($alert);
        }

        const $message = $alert.find('.js-form-alert-message, .js-finance-alert-message, .js-business-alert-message').first();

        return $message.length ? $message : $alert;
    }

    function showSummary($form, text) {
        if (!$form || !$form.length || !text) {
            return;
        }

        const $summary = summaryElement($form);
        const $alert = $summary.closest('.alert');

        ($alert.length ? $alert : $summary)
            .removeClass('d-none alert-warning alert-success')
            .addClass('alert-danger');
        $summary.text(text);
    }

    function renderFields($form, errors) {
        let $firstInput = $();

        Object.keys(errors || {}).forEach(function (field) {
            const fieldMessages = Array.isArray(errors[field]) ? errors[field] : [errors[field]];
            const text = fieldMessages.filter(Boolean).join(' ');
            const $input = fieldInput($form, field);
            const $error = errorElement($form, field, $input);

            if ($input.length) {
                $input.addClass('is-invalid').attr('aria-invalid', 'true');
                $input.next('.select2-container').addClass('is-invalid');

                if (!$firstInput.length) {
                    $firstInput = $input;
                }
            }

            if ($error.length) {
                $error.text(text);
            }
        });

        return $firstInput;
    }

    function focusFirst($input) {
        if (!$input || !$input.length) {
            return;
        }

        const element = $input.get(0);

        if (element && typeof element.scrollIntoView === 'function') {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        window.setTimeout(function () {
            if ($input.hasClass('select2-hidden-accessible')) {
                $input.select2('open');
                return;
            }

            $input.trigger('focus');
        }, 150);
    }

    function recoveryUrl(response) {
        const data = payload(response);

        if (response && response.status === 401) {
            return data.redirect || data.redirect_url || config.loginUrl || '/login';
        }

        return window.location.href;
    }

    function handleAuthenticationFailure(response) {
        if (!response || [401, 419].indexOf(response.status) === -1 || response.appAjaxAuthHandled || authRecoveryPending) {
            return false;
        }

        response.appAjaxAuthHandled = true;
        authRecoveryPending = true;

        const target = recoveryUrl(response);
        const title = message(response);
        const confirmButtonText = response.status === 401 ? config.loginLabel : config.refreshLabel;

        if (window.AppAlerts && typeof window.AppAlerts.fire === 'function' && window.Swal) {
            window.AppAlerts.fire({
                icon: 'warning',
                title: title,
                confirmButtonText: confirmButtonText,
                allowEscapeKey: false,
                allowOutsideClick: false
            }).then(function () {
                window.location.assign(target);
            });
        } else {
            window.setTimeout(function () {
                window.location.assign(target);
            }, 1500);
        }

        return true;
    }

    function render($form, response, options) {
        const data = payload(response);
        const errors = data.errors && typeof data.errors === 'object' ? data.errors : {};
        const $firstInput = renderFields($form, errors);

        if (window.AppNavigationGuard && typeof window.AppNavigationGuard.submissionFailed === 'function') {
            window.AppNavigationGuard.submissionFailed($form && $form.get ? $form.get(0) : null);
        }

        showSummary($form, message(response));

        if (!options || options.focus !== false) {
            focusFirst($firstInput);
        }

        handleAuthenticationFailure(response);

        return data;
    }

    function beginSubmission($form, $buttons) {
        if (!$form || !$form.length || $form.data('appAjaxSubmitting') === true) {
            return false;
        }

        const $submitButtons = $buttons && $buttons.length ? $buttons : $form.find('[type="submit"]');

        $form.data('appAjaxSubmitting', true).attr('aria-busy', 'true');
        $submitButtons.each(function () {
            const $button = $(this);

            $button.data('appAjaxOriginalDisabled', $button.prop('disabled'));
            $button.prop('disabled', true).attr('aria-disabled', 'true');
        });

        return true;
    }

    function endSubmission($form, $buttons) {
        if (!$form || !$form.length) {
            return;
        }

        const $submitButtons = $buttons && $buttons.length ? $buttons : $form.find('[type="submit"]');

        $form.data('appAjaxSubmitting', false).removeAttr('aria-busy');
        $submitButtons.each(function () {
            const $button = $(this);
            const originallyDisabled = $button.data('appAjaxOriginalDisabled') === true;

            $button.prop('disabled', originallyDisabled).removeAttr('aria-disabled');
            $button.removeData('appAjaxOriginalDisabled');
        });
    }

    window.AppAjaxErrors = {
        beginSubmission: beginSubmission,
        clear: clear,
        endSubmission: endSubmission,
        handleAuthenticationFailure: handleAuthenticationFailure,
        message: message,
        payload: payload,
        render: render
    };
})(jQuery, window, document);
