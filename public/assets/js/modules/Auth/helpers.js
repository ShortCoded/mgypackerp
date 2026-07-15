(function (window, $) {
    'use strict';

    const messages = window.authMessages || {};

    function csrfToken() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function setCsrfToken(token) {
        if (!token) {
            return;
        }

        $('meta[name="csrf-token"]').attr('content', token);
        $('input[name="_token"]').val(token);

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': token
            }
        });
    }

    function authHeaders() {
        return {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
    }

    function refreshCsrfToken() {
        const csrfConfig = window.authCsrf || {};
        const deferred = $.Deferred();

        if (!csrfConfig.refreshUrl) {
            deferred.resolve(csrfToken());

            return deferred.promise();
        }

        $.ajax({
            url: csrfConfig.refreshUrl,
            method: 'GET',
            cache: false,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done(function (response) {
            const token = response && response.csrf_token;

            if (!token) {
                deferred.reject({ status: 419 });

                return;
            }

            setCsrfToken(token);
            deferred.resolve(token);
        }).fail(function () {
            deferred.reject.apply(deferred, arguments);
        });

        return deferred.promise();
    }

    function toPromise(value) {
        return new Promise(function (resolve, reject) {
            if (value && typeof value.done === 'function' && typeof value.fail === 'function') {
                value.done(resolve).fail(reject);

                return;
            }

            Promise.resolve(value).then(resolve).catch(reject);
        });
    }

    function isCsrfExpired(response) {
        return response && response.status === 419;
    }

    function requestWithCsrfRetry(requestFactory, retryOnCsrfFailure) {
        let request;

        try {
            request = requestFactory();
        } catch (error) {
            return Promise.reject(error);
        }

        return toPromise(request).catch(function (response) {
            if (!isCsrfExpired(response) || retryOnCsrfFailure !== true) {
                throw response;
            }

            return toPromise(refreshCsrfToken()).then(function () {
                return requestWithCsrfRetry(requestFactory, false);
            });
        });
    }

    function withFreshCsrf(requestFactory) {
        return toPromise(refreshCsrfToken()).then(function () {
            return requestWithCsrfRetry(requestFactory, true);
        });
    }

    function clearForm($form) {
        $form.find('.is-invalid').removeClass('is-invalid').removeAttr('aria-invalid');
        $form.find('[data-error-for]').text('');
        alert($form)
            .addClass('d-none')
            .removeClass('alert-success alert-danger')
            .find('.js-auth-alert-message')
            .empty();
    }

    function alert($form) {
        const $container = $form.closest('.card-body');
        let $alert = $container.find('.js-auth-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-auth-alert" role="alert"><span class="js-auth-alert-message"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || 'Close') + '"></button></div>');
            $container.find('.js-auth-form').before($alert);
        }

        return $alert;
    }

    function showAlert($form, type, message) {
        const $alert = alert($form);
        const $message = $alert.find('.js-auth-alert-message');

        $alert
            .removeClass('d-none alert-success alert-danger')
            .addClass('alert-dismissible fade show')
            .addClass(type === 'success' ? 'alert-success' : 'alert-danger');

        $message.empty().text(message || messages.fallbackError);

        ensureCloseButton($alert);
    }

    function showValidationAlert($form, errorMessages) {
        const $alert = alert($form);
        const $message = $alert.find('.js-auth-alert-message');
        const $list = $('<ul class="mb-0 ps-3"></ul>');
        const messagesToShow = errorMessages.length ? errorMessages : [messages.validationSummary || messages.fallbackError];

        $alert
            .removeClass('d-none alert-success alert-danger')
            .addClass('alert-dismissible fade show alert-danger');

        $message.empty();

        messagesToShow.forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        $message.append($list);
        ensureCloseButton($alert);
    }

    function ensureCloseButton($alert) {
        if ($alert.find('.btn-close').length === 0) {
            $alert.append('<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || 'Close') + '"></button>');
        }
    }

    function validationMessages(errors) {
        const errorMessages = [];

        Object.keys(errors || {}).forEach(function (field) {
            if ($.isArray(errors[field])) {
                errors[field].forEach(function (message) {
                    if (message) {
                        errorMessages.push(message);
                    }
                });
            } else if (errors[field]) {
                errorMessages.push(errors[field]);
            }
        });

        return errorMessages;
    }

    function focusFirstEmptyRequired($form) {
        const $field = $form
            .find('[required]')
            .filter(':visible:enabled')
            .filter(function () {
                const $input = $(this);
                const type = ($input.attr('type') || '').toLowerCase();

                if (type === 'checkbox' || type === 'radio') {
                    return !this.checked;
                }

                const value = ($input.val() || '').toString().trim();

                return value.length === 0;
            })
            .first();

        if ($field.length === 0) {
            return false;
        }

        $field.addClass('is-invalid').attr('aria-invalid', 'true');
        showValidationAlert($form, [messages.validationSummary || messages.fallbackError]);
        $field.trigger('focus');

        if (typeof $field[0].select === 'function') {
            $field[0].select();
        }

        return true;
    }

    function errorMessage(response) {
        const payload = response.responseJSON || {};

        if (payload.message) {
            return payload.message;
        }

        if (response.status === 401) {
            return messages.unauthenticated;
        }

        if (response.status === 403) {
            return messages.forbidden;
        }

        if (response.status === 423) {
            return messages.locked;
        }

        if (response.status === 429) {
            return messages.tooManyAttempts;
        }

        if (response.status === 419) {
            return messages.sessionExpiredTryAgain || messages.fallbackError;
        }

        return messages.fallbackError;
    }

    function setLoading($buttons, isLoading) {
        $buttons.prop('disabled', isLoading);
        $buttons.css('cursor', isLoading ? 'wait' : '');
        $('body').css('cursor', isLoading ? 'wait' : '');
    }

    function applyDirection(direction) {
        const isRTL = direction === 'rtl';

        localStorage.setItem('isRTL', JSON.stringify(isRTL));
        $('html').attr('dir', isRTL ? 'rtl' : 'ltr');
        $('#style-default, #user-style-default').each(function () {
            this.disabled = isRTL;
        });
        $('#style-rtl, #user-style-rtl').each(function () {
            this.disabled = !isRTL;
        });
    }

    window.AuthHelpers = {
        authHeaders: authHeaders,
        clearForm: clearForm,
        errorMessage: errorMessage,
        focusFirstEmptyRequired: focusFirstEmptyRequired,
        refreshCsrfToken: refreshCsrfToken,
        setCsrfToken: setCsrfToken,
        showAlert: showAlert,
        showValidationAlert: showValidationAlert,
        setLoading: setLoading,
        validationMessages: validationMessages,
        applyDirection: applyDirection,
        withFreshCsrf: withFreshCsrf
    };

    setCsrfToken(csrfToken());
})(window, jQuery);
