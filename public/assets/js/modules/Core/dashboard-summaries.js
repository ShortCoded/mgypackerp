(function () {
    'use strict';

    const container = document.querySelector('[data-dashboard-summaries]');
    const config = window.AppDashboardSummaries;

    if (!container || !config) {
        return;
    }

    const form = container.querySelector('[data-summary-filters]');
    const error = container.querySelector('[data-summary-error]');
    let loaded = false;

    const showMessage = (panel, message) => {
        panel.replaceChildren();
        const node = document.createElement('p');
        node.className = 'text-600 mb-0';
        node.textContent = message;
        panel.appendChild(node);
    };

    const render = (panel, payload) => {
        panel.replaceChildren();
        if (!payload.metrics || payload.metrics.length === 0) {
            showMessage(panel, payload.message || config.messages.empty);
            return;
        }

        payload.metrics.forEach((metric) => {
            const card = document.createElement('div');
            card.className = 'card h-100';
            const body = document.createElement('div');
            body.className = 'card-body py-3';
            const category = document.createElement('div');
            category.className = 'small text-600';
            category.textContent = metric.category || '';
            const title = document.createElement('div');
            title.className = 'fw-semibold';
            title.textContent = metric.title || '';
            const value = document.createElement('div');
            value.className = 'fs-5 fw-semibold text-900';
            value.dir = 'ltr';
            value.textContent = metric.value ?? '0';
            const meta = document.createElement('div');
            meta.className = 'small text-600';
            meta.textContent = metric.meta || '';
            body.append(category, title, value, meta);
            card.appendChild(body);
            panel.appendChild(card);
        });
    };

    const load = async () => {
        error.classList.add('d-none');
        const query = new URLSearchParams(new FormData(form));
        const entries = Object.entries(config.urls || {});
        entries.forEach(([type]) => {
            const panel = container.querySelector(`[data-summary-panel="${type}"]`);
            if (panel) {
                showMessage(panel, config.messages.loading);
            }
        });

        try {
            await Promise.all(entries.map(async ([type, url]) => {
                const response = await fetch(`${url}?${query.toString()}`, {
                    headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
                });
                const payload = await response.json();
                if (!response.ok) {
                    throw new Error(payload.message || Object.values(payload.errors || {}).flat()[0] || config.messages.failed);
                }
                const panel = container.querySelector(`[data-summary-panel="${type}"]`);
                if (panel) {
                    render(panel, payload);
                }
            }));
            loaded = true;
        } catch (exception) {
            error.textContent = exception.message || config.messages.failed;
            error.classList.remove('d-none');
        }
    };

    container.addEventListener('toggle', () => {
        if (container.open && !loaded) {
            load();
        }
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        load();
    });
})();
