(function (window, document) {
    'use strict';

    const toastContainerClass = 'erp-swal-toast-container';
    const toastPopupClass = 'erp-swal-toast-popup';

    function currentDirection() {
        const direction = (document.documentElement.getAttribute('dir') || document.body.getAttribute('dir') || '').toLowerCase();

        return direction === 'rtl' ? 'rtl' : 'ltr';
    }

    function cancelledResult() {
        if (window.jQuery && typeof window.jQuery.Deferred === 'function') {
            return window.jQuery.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Promise.resolve({ isConfirmed: false });
    }

    function normalizeClassValue(value) {
        if (!value) {
            return [];
        }

        if (Array.isArray(value)) {
            return value;
        }

        return String(value).split(/\s+/).filter(Boolean);
    }

    function mergeClassValue(value, requiredClass) {
        const classes = normalizeClassValue(value);

        if (classes.indexOf(requiredClass) === -1) {
            classes.push(requiredClass);
        }

        return classes.join(' ');
    }

    function mergeCustomClass(customClass) {
        const classes = typeof customClass === 'object' && customClass !== null && !Array.isArray(customClass)
            ? Object.assign({}, customClass)
            : {};

        classes.container = mergeClassValue(classes.container, toastContainerClass);
        classes.popup = mergeClassValue(classes.popup, toastPopupClass);

        return classes;
    }

    function composeLifecycle(userCallback, callback) {
        return function (element) {
            callback(element);

            if (typeof userCallback === 'function') {
                userCallback.call(this, element);
            }
        };
    }

    function prepareToastOptions(options) {
        if (!options || typeof options !== 'object' || Array.isArray(options)) {
            return options;
        }

        const normalized = Object.assign({}, options);

        normalized.customClass = mergeCustomClass(normalized.customClass);
        normalized.position = normalized.position || (currentDirection() === 'rtl' ? 'top-left' : 'top-right');

        return normalized;
    }

    function prepareOptions(options) {
        if (!options || typeof options !== 'object' || Array.isArray(options)) {
            return options;
        }

        if (options.toast === true) {
            return prepareToastOptions(options);
        }

        return Object.assign({}, options);
    }

    function fire(options) {
        if (!window.Swal || typeof window.Swal.fire !== 'function') {
            return cancelledResult();
        }

        return window.Swal.fire(prepareOptions(options));
    }

    window.AppAlerts = {
        direction: currentDirection,
        options: prepareOptions,
        fire: fire,
        toast: function (icon, title, options) {
            if (!title) {
                return cancelledResult();
            }

            if (window.Swal && typeof window.Swal.isVisible === 'function' && window.Swal.isVisible()) {
                const visiblePopup = document.querySelector('.swal2-popup');

                if (visiblePopup && !visiblePopup.classList.contains('swal2-toast')) {
                    window.Swal.close();
                }
            }

            return fire(prepareToastOptions(Object.assign({
                toast: true,
                icon: icon,
                title: title,
                timer: 5000,
                timerProgressBar: true,
                showCloseButton: true,
                showConfirmButton: false,
                didOpen: function (element) {
                    if (window.Swal) {
                        element.addEventListener('mouseenter', window.Swal.stopTimer);
                        element.addEventListener('mouseleave', window.Swal.resumeTimer);
                    }
                }
            }, options || {})));
        },
        confirm: function (options) {
            return fire(Object.assign({
                icon: 'warning',
                showCloseButton: true,
                showCancelButton: true,
                focusCancel: true,
                allowEscapeKey: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#748194'
            }, options || {}));
        }
    };
})(window, document);
