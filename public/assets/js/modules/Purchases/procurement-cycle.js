document.addEventListener('DOMContentLoaded', () => {
    const syncProductUnit = (select) => {
        const option = select.selectedOptions[0];
        const row = select.closest('[data-procurement-line]');
        if (!row || !option) return;
        const unit = row.querySelector('.js-procurement-unit');
        const label = row.querySelector('.js-procurement-unit-label');
        if (unit) unit.value = option.dataset.unit || '';
        if (label) label.value = option.dataset.unitLabel || option.dataset.unit || '';
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
        form.querySelector('[data-add-procurement-line]')?.addEventListener('click', () => {
            if (!container || !template) return;
            const index = container.querySelectorAll('[data-procurement-line]').length;
            container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)).replaceAll('__NUMBER__', String(index + 1)));
        });
        form.addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-procurement-line]');
            if (!button || !container || container.querySelectorAll('[data-procurement-line]').length <= 1) return;
            button.closest('[data-procurement-line]')?.remove();
            renumber(container);
        });
        form.addEventListener('change', (event) => {
            if (event.target.matches('.js-procurement-product')) syncProductUnit(event.target);
        });
        form.querySelectorAll('.js-procurement-product').forEach(syncProductUnit);
    });

    if (window.jQuery) {
        const $ = window.jQuery;

        $(document)
            .off('file-picker:selected.procurement', '.js-procurement-attachment-picker')
            .on('file-picker:selected.procurement', '.js-procurement-attachment-picker', function (event, payload) {
                const form = this.closest('form');
                const file = payload?.file;
                if (!form || !file?.public_id) return;

                const inputs = form.querySelector('.js-procurement-attachment-inputs');
                const list = form.querySelector('.js-procurement-selected-attachments');
                if (!inputs || !list || inputs.querySelector(`[data-public-id="${CSS.escape(String(file.public_id))}"]`)) return;

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'attachment_file_doc_nums[]';
                input.value = String(file.public_id);
                input.dataset.publicId = String(file.public_id);
                inputs.append(input);

                const item = document.createElement('li');
                item.className = 'list-group-item px-0 d-flex align-items-center justify-content-between gap-2';
                item.dataset.publicId = String(file.public_id);

                const label = document.createElement('span');
                label.textContent = file.original_name || file.name || file.public_id;
                item.append(label);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-link text-danger p-0 js-remove-procurement-attachment';
                remove.setAttribute('aria-label', 'Remove attachment');
                remove.innerHTML = '<span class="fas fa-times" aria-hidden="true"></span>';
                item.append(remove);
                list.append(item);
                list.classList.remove('d-none');
            })
            .off('click.procurementAttachment', '.js-remove-procurement-attachment')
            .on('click.procurementAttachment', '.js-remove-procurement-attachment', function () {
                const form = this.closest('form');
                const item = this.closest('[data-public-id]');
                if (!form || !item) return;

                const publicId = item.dataset.publicId;
                item.remove();
                form.querySelector(`.js-procurement-attachment-inputs [data-public-id="${CSS.escape(publicId)}"]`)?.remove();
                const list = form.querySelector('.js-procurement-selected-attachments');
                if (list && list.children.length === 0) list.classList.add('d-none');
            });
    }
});
