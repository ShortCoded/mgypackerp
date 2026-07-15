(function ($, helpers) {
    'use strict';

    function showErrors($form, response) {
        const payload = response.responseJSON || {};
        const errors = payload.errors || {};

        Object.keys(errors).forEach(function (field) {
            const $input = $form.find('[name="' + field + '"]');
            const $error = $form.find('[data-error-for="' + field + '"]');

            $input.addClass('is-invalid').attr('aria-invalid', 'true');
            $error.text($.isArray(errors[field]) ? (errors[field][0] || '') : (errors[field] || ''));
        });

        helpers.showValidationAlert($form, helpers.validationMessages(errors));
    }

    function appendClientContext(data, context) {
        if (!context) {
            return data;
        }

        if (context.client_context) {
            data.push({
                name: 'client_context',
                value: JSON.stringify(context.client_context)
            });
        }

        if (context.client_location) {
            data.push({
                name: 'client_location',
                value: JSON.stringify(context.client_location)
            });
        }

        return data;
    }

    function formDataWithContext($form) {
        const data = $form.serializeArray();
        const collector = window.AppClientContext;

        if (!collector || typeof collector.collect !== 'function') {
            return Promise.resolve($form.serialize());
        }

        return collector.collect({
            includeLocation: true
        }).then(function (context) {
            return $.param(appendClientContext(data, context));
        }).catch(function () {
            return $form.serialize();
        });
    }

    function request($form) {
        return formDataWithContext($form).then(function (data) {
            return $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method') || 'POST',
                data: data,
                headers: helpers.authHeaders()
            });
        });
    }

    function handleFailure($form, response) {
        if (response.responseJSON && response.responseJSON.redirect) {
            window.location.assign(response.responseJSON.redirect);

            return;
        }

        if (response.status === 422) {
            showErrors($form, response);

            return;
        }

        helpers.showAlert($form, 'error', helpers.errorMessage(response));
    }

    function submitUnlock($form) {
        const $buttons = $form.find('[type="submit"]');

        if ($form.data('auth-submitting') === true) {
            return;
        }

        $form.data('auth-submitting', true);
        helpers.setLoading($buttons, true);

        helpers.withFreshCsrf(function () {
            return request($form);
        }).then(function (response) {
            if (response.csrf_token && typeof helpers.setCsrfToken === 'function') {
                helpers.setCsrfToken(response.csrf_token);
            }

            if (response.redirect) {
                window.location.assign(response.redirect);
            }
        }).catch(function (response) {
            handleFailure($form, response || { status: 419 });
        }).finally(function () {
            $form.data('auth-submitting', false);
            helpers.setLoading($buttons, false);
        });
    }

    $(function () {
        $('#lock_screen_password').trigger('focus');
    });

    $(document).on('input change', '.js-lock-screen-form .is-invalid', function () {
        const $input = $(this);
        const field = $input.attr('name');

        $input.removeClass('is-invalid').removeAttr('aria-invalid');

        if (field) {
            $input.closest('.js-lock-screen-form').find('[data-error-for="' + field + '"]').text('');
        }
    });

    $(document).on('submit', '.js-lock-screen-form', function (event) {
        event.preventDefault();

        const $form = $(this);

        helpers.clearForm($form);

        if (helpers.focusFirstEmptyRequired($form)) {
            return;
        }

        submitUnlock($form);
    });
})(jQuery, window.AuthHelpers);
