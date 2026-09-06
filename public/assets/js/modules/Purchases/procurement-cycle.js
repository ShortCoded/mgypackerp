document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-procurement-source-picker]').forEach(form => form.addEventListener('submit', event => {
        event.preventDefault();
        location.href = form.dataset.destination.replace('__DOCUMENT__', encodeURIComponent(form.querySelector('select').value));
    }));
    const initialize = (row) => {
        window.AppSelect2Ajax?.init(row);
        window.AppDatePicker?.init(row);
    };
    const syncProductUnit = (select, data) => {
        const row = select.closest('[data-procurement-line]');
        const unit = row?.querySelector('.js-procurement-unit');
        if (!unit) return;
        const options = data?.unit_options || [];
        unit.replaceChildren(...options.map(option => new Option(option.text, option.id, false, option.id === data.unitDocNum)));
        window.jQuery(unit).trigger('change');
    };

    const refreshAvailability = async (form, row) => {
        const output = row.querySelector('[data-stock-availability]');
        if (!output || !form.dataset.availabilityUrl) return;
        row.stockRequest?.abort();
        output.replaceChildren();
        const cell = output.closest('[data-stock-cell]');
        if (cell) cell.hidden = true;
        const warehouse = form.querySelector('[name="branch_store_uuid"]')?.value;
        const product = row.querySelector('.js-procurement-product')?.value;
        if (!product) return;
        const controller = new AbortController();
        row.stockRequest = controller;
        const url = new URL(form.dataset.availabilityUrl, window.location.href);
        if (warehouse) url.searchParams.set('branch_store_uuid', warehouse);
        url.searchParams.set('product_doc_num', product);
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) return;
            const stock = await response.json();
            if (controller.signal.aborted) return;
            const number = new Intl.NumberFormat(document.documentElement.lang || 'ar', { maximumFractionDigits: 8 });
            ['on_hand', 'reserved', 'available'].forEach((key, index) => {
                const span = document.createElement('span');
                span.textContent = [output.dataset.onHandLabel, output.dataset.reservedLabel, output.dataset.availableLabel][index] + ': ' + number.format(Number(stock[key]));
                output.append(span);
            });
            if (cell) cell.hidden = false;
        } catch (error) {
            if (error.name !== 'AbortError') output.textContent = '—';
        }
    };

    const renumber = (container) => {
        container.querySelectorAll('[data-procurement-line]').forEach((row, index) => {
            const number = row.querySelector('[data-line-number]');
            if (number) number.textContent = String(index + 1);
        });
    };

    document.querySelectorAll('[data-procurement-form]').forEach((form) => {
        const container = document.querySelector(form.dataset.linesContainer);
        const template = document.querySelector(form.dataset.lineTemplate);
        let nextLineIndex = container?.querySelectorAll('[data-procurement-line]').length || 0;
        form.querySelector('[data-add-procurement-line]')?.addEventListener('click', () => {
            if (!container || !template) return;
            const index = nextLineIndex++;
            container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)).replaceAll('__NUMBER__', String(index + 1)));
            initialize(container.lastElementChild);
        });
        form.addEventListener('click', (event) => {
            const duplicate = event.target.closest('[data-duplicate-procurement-line]');
            if (duplicate && container && template) {
                const source = duplicate.closest('[data-procurement-line]');
                const row = window.AppLineItemCards.append(container, template, 'lines', source);
                row.querySelector('.js-procurement-existing-attachments')?.remove();
                row.querySelector('.js-procurement-attachment-inputs')?.replaceChildren();
                const selectedAttachments = row.querySelector('.js-procurement-selected-attachments');
                selectedAttachments?.replaceChildren();
                selectedAttachments?.classList.add('d-none');
                nextLineIndex = container.children.length;
                renumber(container); initialize(row); refreshAvailability(form, row);
                return;
            }
            const button = event.target.closest('[data-remove-procurement-line]');
            if (!button || !container || container.querySelectorAll('[data-procurement-line]').length <= 1) return;
            button.closest('[data-procurement-line]')?.remove();
            renumber(container);
        });
        form.addEventListener('change', (event) => {
            if (event.target.matches('.js-procurement-product')) {
                refreshAvailability(form, event.target.closest('[data-procurement-line]'));
            }
            if (event.target.matches('[name="branch_store_uuid"]')) {
                form.querySelectorAll('[data-procurement-line]').forEach(row => refreshAvailability(form, row));
            }
        });
        initialize(form);
        window.jQuery(form).on('select2:select', '.js-procurement-product', function (event) {
            syncProductUnit(this, event.params.data);
            refreshAvailability(form, this.closest('[data-procurement-line]'));
        });
        form.addEventListener('click', event => {
            const submit = event.target.closest('[data-submit-action]');
            if (submit) form.querySelector('[name="submit_action"]').value = submit.dataset.submitAction;
        });
        form.querySelectorAll('[data-procurement-line]').forEach(row => refreshAvailability(form, row));
    });

});
