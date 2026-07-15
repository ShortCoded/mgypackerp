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

    function includeLocation($form) {
        return String($form.data('client-location') || '').toLowerCase() === 'true';
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
            includeLocation: includeLocation($form)
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

    function handleSuccess($form, response) {
        helpers.showAlert($form, 'success', response.message);

        if (response.redirect) {
            window.location.assign(response.redirect);
        }
    }

    function handleFailure($form, response) {
        if (response.status === 422) {
            showErrors($form, response);

            return;
        }

        helpers.showAlert($form, 'error', helpers.errorMessage(response));
    }

    function initLanguageSelect() {
        const $select = $('.js-auth-language-select');

        if ($select.length === 0) {
            return;
        }

        if ($.fn.select2) {
            $select.select2({
                theme: 'bootstrap-5',
                minimumResultsForSearch: Infinity,
                width: '100%',
                dropdownParent: $('#settings-offcanvas')
            });
        }

        $select.on('change', function () {
            const $option = $(this).find(':selected');
            const direction = $option.data('dir') || 'ltr';
            const url = ($(this).data('language-switch-url') || '').replace('__LOCALE__', $(this).val());

            helpers.applyDirection(direction);

            $.ajax({
                url: url,
                method: 'GET',
                headers: helpers.authHeaders()
            }).done(function () {
                window.location.reload();
            });
        });
    }

    $(function () {
        initLanguageSelect();
    });

    $(document).on('input change', '.js-auth-form .is-invalid', function () {
        const $input = $(this);
        const field = $input.attr('name');

        $input.removeClass('is-invalid').removeAttr('aria-invalid');

        if (field) {
            $input.closest('.js-auth-form').find('[data-error-for="' + field + '"]').text('');
        }
    });

    $(document).on('submit', '.js-auth-form', function (event) {
        event.preventDefault();

        const $form = $(this);
        const $buttons = $form.find('[type="submit"]');

        if ($form.data('auth-submitting') === true) {
            return;
        }

        helpers.clearForm($form);

        if (helpers.focusFirstEmptyRequired($form)) {
            return;
        }

        $form.data('auth-submitting', true);
        helpers.setLoading($buttons, true);

        helpers.withFreshCsrf(function () {
            return request($form);
        }).then(function (response) {
            handleSuccess($form, response);
        }).catch(function (response) {
            handleFailure($form, response || { status: 419 });
        }).finally(function () {
            $form.data('auth-submitting', false);
            helpers.setLoading($buttons, false);
        });
    });

    $(document).on('click', '[data-auth-logout]', function (event) {
        event.preventDefault();

        helpers.withFreshCsrf(function () {
            return $.ajax({
                url: $(event.currentTarget).attr('href') || $(event.currentTarget).data('url'),
                method: 'POST',
                headers: helpers.authHeaders()
            });
        }).then(function (response) {
            if (response.redirect) {
                window.location.assign(response.redirect);
            }
        }).catch(function (response) {
            if (response && response.redirect) {
                window.location.assign(response.redirect);
            }
        });
    });
})(jQuery, window.AuthHelpers);
