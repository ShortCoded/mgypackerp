(function () {
    'use strict';

    document.addEventListener('submit', async function (event) {
        const form = event.target.closest('[data-document-cancellation-form]');
        if (!form) return;
        event.preventDefault();
        if (form.dataset.submitting === '1' || !form.reportValidity()) return;
        const button = form.querySelector('button[type="submit"]');
        const error = form.querySelector('[data-cancellation-error]');
        form.dataset.submitting = '1';
        button.disabled = true;
        error.classList.add('d-none');
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
            });
            let payload;
            try {
                payload = await response.json();
            } catch (_) {
                throw new Error(form.dataset.errorMessage);
            }
            if (!response.ok || payload.success === false) {
                const messages = Object.values(payload.errors || {}).flat();
                throw new Error(messages.join('\n') || payload.message || form.dataset.errorMessage);
            }
            if (form.dataset.successUrl) {
                window.location.assign(form.dataset.successUrl);
            } else {
                window.location.reload();
            }
        } catch (failure) {
            error.textContent = failure.message || form.dataset.errorMessage;
            error.classList.remove('d-none');
            form.dataset.submitting = '0';
            button.disabled = false;
        }
    });
}());
