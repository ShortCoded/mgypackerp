document.addEventListener('DOMContentLoaded', () => {
    if (window.jQuery) {
        const $ = window.jQuery;

        $(document)
            .off('file-picker:selected.sales', '.js-sales-attachment-picker')
            .on('file-picker:selected.sales', '.js-sales-attachment-picker', function (event, payload) {
                const section = this.closest('[data-sales-attachments]');
                const form = section?.querySelector('form');
                const file = payload?.file;
                if (!form || !file?.public_id) return;

                const inputs = form.querySelector('.js-sales-attachment-inputs');
                const list = section?.querySelector('.js-sales-selected-attachments');
                if (!inputs || !list || inputs.querySelector(`[data-public-id="${CSS.escape(String(file.public_id))}"]`)) return;

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'attachment_doc_nums[]';
                input.value = String(file.public_id);
                input.dataset.publicId = String(file.public_id);
                inputs.append(input);

                const item = document.createElement('li');
                item.className = 'list-group-item px-2 py-2 d-flex align-items-center justify-content-between gap-2';
                item.dataset.publicId = String(file.public_id);

                const label = document.createElement('span');
                label.textContent = file.original_name || file.name || file.public_id;
                item.append(label);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-link text-danger p-0 js-remove-sales-attachment';
                remove.setAttribute('aria-label', this.dataset.removeLabel || 'Remove attachment');
                remove.innerHTML = '<span class="fas fa-times" aria-hidden="true"></span>';
                item.append(remove);
                list.append(item);
                list.classList.remove('d-none');
            })
            .off('click.salesAttachment', '.js-remove-sales-attachment')
            .on('click.salesAttachment', '.js-remove-sales-attachment', function () {
                const section = this.closest('[data-sales-attachments]');
                const form = section?.querySelector('form');
                const item = this.closest('[data-public-id]');
                if (!form || !item) return;

                const publicId = item.dataset.publicId;
                item.remove();
                form.querySelector(`.js-sales-attachment-inputs [data-public-id="${CSS.escape(publicId)}"]`)?.remove();
                const list = section?.querySelector('.js-sales-selected-attachments');
                if (list && list.children.length === 0) list.classList.add('d-none');
            });
    }
});
