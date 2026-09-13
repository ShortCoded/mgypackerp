(function (window, document) {
    'use strict';

    const root = document.querySelector('.employee-self-service');

    if (!root) {
        return;
    }

    const messages = JSON.parse(root.dataset.messages || '{}');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let activeRequest = false;
    const pendingIdempotencyKeys = {};

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (character) {
            const random = Math.random() * 16 | 0;
            return (character === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
        });
    }

    function feedback(message, type) {
        const element = root.querySelector('.js-attendance-feedback');

        if (!element) {
            return;
        }

        element.className = 'js-attendance-feedback alert alert-' + (type || 'info');
        element.textContent = message || '';
    }

    function setLoading(loading) {
        activeRequest = loading;
        root.querySelectorAll('.js-attendance-punch').forEach(function (button) {
            button.disabled = loading;
            button.classList.toggle('is-loading', loading);
        });
    }

    function formatTime(value) {
        return value ? new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(value)) : '';
    }

    function renderStatus(status) {
        const stateLabel = root.querySelector('.js-attendance-state-label');
        const clock = root.querySelector('.js-attendance-clock');
        const checkIn = root.querySelector('.js-attendance-check-in');
        const worked = root.querySelector('.js-worked-minutes');
        const breaks = root.querySelector('.js-break-minutes');

        if (stateLabel) stateLabel.textContent = (messages.states || {})[status.state] || status.state;
        if (clock) {
            clock.dataset.checkInAt = status.check_in_at || '';
            clock.dataset.state = status.state || '';
        }
        if (checkIn) checkIn.textContent = status.check_in_at ? String(messages.checkedInAt || '').replace(':time', status.check_in_display || formatTime(status.check_in_at)) : (messages.notCheckedIn || '');
        if (worked) worked.textContent = String(status.worked_minutes || 0);
        if (breaks) breaks.textContent = String(status.break_minutes || 0);

        root.querySelectorAll('.js-attendance-punch').forEach(function (button) {
            button.classList.toggle('d-none', !(status.allowed_actions || []).includes(button.dataset.eventType));
        });

        tickClock();
    }

    function tickClock() {
        const clock = root.querySelector('.js-attendance-clock');

        if (!clock || !clock.dataset.checkInAt) {
            if (clock) clock.textContent = '00:00';
            return;
        }

        const elapsed = Math.max(0, Math.floor((Date.now() - new Date(clock.dataset.checkInAt).getTime()) / 60000));
        clock.textContent = String(Math.floor(elapsed / 60)).padStart(2, '0') + ':' + String(elapsed % 60).padStart(2, '0');
    }

    function requestPayload(eventType) {
        pendingIdempotencyKeys[eventType] = pendingIdempotencyKeys[eventType] || uuid();
        const contextPromise = window.AppClientContext && typeof window.AppClientContext.collect === 'function'
            ? window.AppClientContext.collect({ includeLocation: true, forceLocationRefresh: true, enableHighAccuracy: true, timeout: 10000, maximumAge: 0 })
            : Promise.resolve({ client_context: {}, client_location: { unavailable: true } });

        return contextPromise.then(function (context) {
            const location = context.client_location || {};

            return {
                event_type: eventType,
                idempotency_key: pendingIdempotencyKeys[eventType],
                latitude: location.latitude,
                longitude: location.longitude,
                accuracy_meters: location.accuracy,
                location_source: location.source || 'browser',
                client_context: context.client_context || {}
            };
        });
    }

    function punch(eventType) {
        if (activeRequest) return;

        setLoading(true);
        feedback(messages.locating || '', 'info');

        requestPayload(eventType).then(function (payload) {
            return window.fetch(root.dataset.punchUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
        }).then(function (response) {
            return response.json().then(function (body) {
                if (response.status === 401) {
                    window.location.assign(root.dataset.loginUrl || '/login');
                }
                if (!response.ok) throw new Error(body.message || messages.failed || '');
                return body;
            });
        }).then(function (body) {
            delete pendingIdempotencyKeys[eventType];
            renderStatus(body.data);
            feedback(body.message || messages.saved || '', 'success');
        }).catch(function (error) {
            feedback(error.message || messages.failed || '', 'danger');
        }).finally(function () {
            setLoading(false);
        });
    }

    function refreshStatus() {
        if (activeRequest || document.hidden || !root.dataset.statusUrl) return;

        window.fetch(root.dataset.statusUrl, {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.status === 401) {
                window.location.assign(root.dataset.loginUrl || '/login');
            }
            if (!response.ok) throw new Error(messages.failed || '');
            return response.json();
        }).then(function (body) {
            if (body.data) renderStatus(body.data);
        }).catch(function () {
            return;
        });
    }

    function updateRequestFields() {
        const type = root.querySelector('#request_type')?.value || '';

        root.querySelectorAll('.js-request-field').forEach(function (field) {
            const visible = String(field.dataset.types || '').split(',').includes(type);
            field.classList.toggle('d-none', !visible);
            field.querySelectorAll('input, select, textarea').forEach(function (input) { input.disabled = !visible; });
        });
    }

    root.addEventListener('click', function (event) {
        const button = event.target.closest('.js-attendance-punch');
        if (button) punch(button.dataset.eventType);
    });

    root.querySelector('#request_type')?.addEventListener('change', updateRequestFields);
    updateRequestFields();
    tickClock();
    window.setInterval(tickClock, 30000);
    window.addEventListener('pageshow', refreshStatus);
    document.addEventListener('visibilitychange', refreshStatus);
})(window, document);
