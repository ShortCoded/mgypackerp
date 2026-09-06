(function ($, window, document) {
    'use strict';

    var config = window.AppSession || {};
    var statusUrl = config.statusUrl || '/session/status';
    var touchUrl = config.touchUrl || '/session/touch';
    var sessionIdentity = String(config.identity || '');
    var lifetimeSeconds = parseInt(config.lifetimeSeconds, 10) || 7200;
    var warningBeforeSeconds = parseInt(config.warningBeforeSeconds, 10) || 0;
    var touchThrottleMilliseconds = Math.min(60000, Math.max(10000, lifetimeSeconds * 500));
    var timeoutId = null;
    var lastTouchAttemptAt = Date.now();
    var isReloading = false;
    var isTouching = false;
    var isCheckingStatus = false;
    var shouldTouchAfterStatus = false;
    var statusCheckDebounceId = null;
    var statusCheckDebounceMilliseconds = 1000;
    var statusRequest = null;
    var statusRequestVersion = 0;

    function reloadCurrentPage() {
        if (isReloading) {
            return;
        }

        isReloading = true;
        window.location.href = window.location.href;
    }

    function handleAuthenticationFailure(response) {
        if (window.AppAjaxErrors && typeof window.AppAjaxErrors.handleAuthenticationFailure === 'function') {
            return window.AppAjaxErrors.handleAuthenticationFailure(response);
        }

        reloadCurrentPage();

        return true;
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

    function invalidateStatusRequest() {
        var pendingRequest = statusRequest;

        statusRequestVersion += 1;
        statusRequest = null;
        isCheckingStatus = false;

        if (pendingRequest && typeof pendingRequest.abort === 'function') {
            pendingRequest.abort();
        }
    }

    function unlockBackForwardCacheRestore() {
        var guard = window.AppPageCacheGuard;

        if (guard && typeof guard.unlock === 'function') {
            guard.unlock();
        }
    }

    function isConfirmedActiveStatus(response) {
        return response
            && sessionIdentity !== ''
            && response.session_identity === sessionIdentity
            && response.authenticated === true
            && response.expired === false
            && response.locked !== true
            && response.action !== 'lock'
            && response.action !== 'login';
    }

    function checkStatus(thenTouch, options) {
        var isBackForwardCacheRestore = Boolean(options && options.backForwardCacheRestore);

        if (thenTouch === true) {
            shouldTouchAfterStatus = true;
        }

        window.clearTimeout(statusCheckDebounceId);
        statusCheckDebounceId = null;

        if (isReloading) {
            return;
        }

        if (isCheckingStatus) {
            if (!isBackForwardCacheRestore) {
                return;
            }

            invalidateStatusRequest();
        }

        isCheckingStatus = true;
        var requestVersion = ++statusRequestVersion;

        statusRequest = $.ajax({
            url: statusUrl,
            method: 'GET',
            cache: false,
            headers: {
                Accept: 'application/json',
                'X-Current-Path': currentPath()
            }
        });

        statusRequest.done(function (response) {
            if (requestVersion !== statusRequestVersion) {
                return;
            }

            if (!handleStatusResponse(response)) {
                shouldTouchAfterStatus = false;

                return;
            }

            if (isBackForwardCacheRestore) {
                if (!isConfirmedActiveStatus(response)) {
                    shouldTouchAfterStatus = false;
                    reloadCurrentPage();

                    return;
                }

                unlockBackForwardCacheRestore();
            }

            if (shouldTouchAfterStatus) {
                shouldTouchAfterStatus = false;
                touchSession();
            }
        }).fail(function (response) {
            var responseStatus = response ? response.status : 0;

            if (requestVersion !== statusRequestVersion) {
                return;
            }

            shouldTouchAfterStatus = false;

            if (responseStatus === 423 && response.responseJSON && response.responseJSON.lock_screen_url) {
                redirectToLockScreen(response.responseJSON.lock_screen_url);

                return;
            }

            if (responseStatus === 401 || responseStatus === 419) {
                handleAuthenticationFailure(response);

                return;
            }

            if (isBackForwardCacheRestore) {
                reloadCurrentPage();
            }
        }).always(function () {
            if (requestVersion !== statusRequestVersion) {
                return;
            }

            isCheckingStatus = false;
            statusRequest = null;
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
                    handleAuthenticationFailure(response);
                });

                return;
            }

            if (response.status === 401 || response.status === 419) {
                handleAuthenticationFailure(response);
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

    function scheduleStatusCheck(thenTouch) {
        if (thenTouch === true) {
            shouldTouchAfterStatus = true;
        }

        if (isReloading || isCheckingStatus) {
            return;
        }

        window.clearTimeout(statusCheckDebounceId);
        statusCheckDebounceId = window.setTimeout(function () {
            statusCheckDebounceId = null;
            checkStatus(false);
        }, statusCheckDebounceMilliseconds);
    }

    function handleFocusOrVisibility() {
        scheduleStatusCheck(false);
    }

    function handleBackForwardCacheRestore() {
        var guard = window.AppPageCacheGuard;

        if (guard && typeof guard.claimRestore === 'function') {
            guard.claimRestore();
        }

        checkStatus(true, { backForwardCacheRestore: true });
    }

    scheduleTimeout(lifetimeSeconds);

    $(document).on('pointerdown keydown scroll touchstart', handleActivity);

    window.addEventListener('focus', function () {
        handleFocusOrVisibility();
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            handleFocusOrVisibility();
        }
    });

    window.addEventListener('erp:bfcache-restore', handleBackForwardCacheRestore);

    $(document).ajaxError(function (event, response, settings) {
        if (response.status === 423 && response.responseJSON && response.responseJSON.lock_screen_url) {
            redirectToLockScreen(response.responseJSON.lock_screen_url);

            return;
        }

        if (response.status === 419 && settings && settings.url === touchUrl) {
            return;
        }

        if (response.status === 401 || response.status === 419) {
            handleAuthenticationFailure(response);
        }
    });
})(jQuery, window, document);
