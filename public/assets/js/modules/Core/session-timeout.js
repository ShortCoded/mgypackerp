(function ($, window, document) {
    'use strict';

    var config = window.AppSession || {};
    var statusUrl = config.statusUrl || '/session/status';
    var touchUrl = config.touchUrl || '/session/touch';
    var lifetimeSeconds = parseInt(config.lifetimeSeconds, 10) || 7200;
    var warningBeforeSeconds = parseInt(config.warningBeforeSeconds, 10) || 0;
    var touchThrottleMilliseconds = Math.min(60000, Math.max(10000, lifetimeSeconds * 500));
    var timeoutId = null;
    var lastTouchAttemptAt = 0;
    var isReloading = false;
    var isTouching = false;

    function reloadCurrentPage() {
        if (isReloading) {
            return;
        }

        isReloading = true;
        window.location.href = window.location.href;
    }

    function currentPath() {
        return window.location.pathname + window.location.search + window.location.hash;
    }

    function redirectToLockScreen(url) {
        if (isReloading) {
            return;
        }

        isReloading = true;
        window.location.assign(url || '/lock-screen');
    }

    function handleStatusResponse(response) {
        if (!response) {
            reloadCurrentPage();

            return false;
        }

        if (response.action === 'lock' || response.locked === true) {
            redirectToLockScreen(response.lock_screen_url);

            return false;
        }

        if (response.action === 'login' || response.authenticated === false || response.expired === true) {
            reloadCurrentPage();

            return false;
        }

        scheduleTimeout(response.seconds_remaining);

        return true;
    }

    function scheduleTimeout(secondsRemaining) {
        var parsedSeconds = parseInt(secondsRemaining, 10);
        var safeSeconds = Number.isNaN(parsedSeconds) ? lifetimeSeconds : parsedSeconds;
        var millisecondsRemaining = Math.max(0, safeSeconds - warningBeforeSeconds) * 1000;

        window.clearTimeout(timeoutId);
        timeoutId = window.setTimeout(handleTimeout, millisecondsRemaining);
    }

    function scheduleFromExpiry(expiresAt, serverTime) {
        scheduleTimeout(parseInt(expiresAt, 10) - parseInt(serverTime, 10));
    }

    function csrfToken() {
        return $('meta[name="csrf-token"]').attr('content') || '';
    }

    function authHeaders() {
        return {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken()
        };
    }

    function refreshCsrfToken() {
        if (window.AuthHelpers && typeof window.AuthHelpers.refreshCsrfToken === 'function') {
            return window.AuthHelpers.refreshCsrfToken();
        }

        return $.Deferred().reject({ status: 419 }).promise();
    }

    function checkStatus(thenTouch) {
        if (isReloading) {
            return;
        }

        $.ajax({
            url: statusUrl,
            method: 'GET',
            cache: false,
            headers: {
                Accept: 'application/json',
                'X-Current-Path': currentPath()
            }
        }).done(function (response) {
            if (!handleStatusResponse(response)) {
                return;
            }

            if (thenTouch === true) {
                touchSession();
            }
        }).fail(function (response) {
            if (response.status === 423 && response.responseJSON && response.responseJSON.lock_screen_url) {
                redirectToLockScreen(response.responseJSON.lock_screen_url);

                return;
            }

            if (response.status === 401 || response.status === 419) {
                reloadCurrentPage();
            }
        });
    }

    function touchSession(retryOnCsrfFailure) {
        var now = Date.now();
        var shouldRetryOnCsrfFailure = retryOnCsrfFailure !== false;

        if (isReloading || isTouching || now - lastTouchAttemptAt < touchThrottleMilliseconds) {
            return;
        }

        lastTouchAttemptAt = now;
        isTouching = true;

        $.ajax({
            url: touchUrl,
            method: 'POST',
            headers: authHeaders()
        }).done(function (response) {
            if (!response || response.ok !== true) {
                return;
            }

            if (response.expires_at && response.server_time) {
                scheduleFromExpiry(response.expires_at, response.server_time);

                return;
            }

            scheduleTimeout(response.lifetime_seconds || lifetimeSeconds);
        }).fail(function (response) {
            if (response.status === 419 && shouldRetryOnCsrfFailure) {
                isTouching = false;
                lastTouchAttemptAt = 0;
                refreshCsrfToken().done(function () {
                    touchSession(false);
                }).fail(function () {
                    reloadCurrentPage();
                });

                return;
            }

            if (response.status === 401 || response.status === 419) {
                reloadCurrentPage();
            }

            if (response.status === 423 && response.responseJSON && response.responseJSON.lock_screen_url) {
                redirectToLockScreen(response.responseJSON.lock_screen_url);
            }
        }).always(function () {
            isTouching = false;
        });
    }

    function handleTimeout() {
        checkStatus(false);
    }

    function handleActivity() {
        touchSession();
    }

    function handleFocusOrVisibility() {
        checkStatus(true);
    }

    scheduleTimeout(lifetimeSeconds);

    $(document).on('mousemove mousedown click keydown scroll touchstart', handleActivity);

    window.addEventListener('focus', function () {
        handleFocusOrVisibility();
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            handleFocusOrVisibility();
        }
    });

    $(document).ajaxError(function (event, response, settings) {
        if (response.status === 423 && response.responseJSON && response.responseJSON.lock_screen_url) {
            redirectToLockScreen(response.responseJSON.lock_screen_url);

            return;
        }

        if (response.status === 419 && settings && settings.url === touchUrl) {
            return;
        }

        if (response.status === 401 || response.status === 419) {
            reloadCurrentPage();
        }
    });
})(jQuery, window, document);
