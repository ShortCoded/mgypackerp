document.addEventListener('DOMContentLoaded', () => {
    if (window.jQuery) {
        const $ = window.jQuery;

        $(document)
            .off('file-picker:selected.procurement', '.js-procurement-attachment-picker')
            .on('file-picker:selected.procurement', '.js-procurement-attachment-picker', function (event, payload) {
                const form = this.closest('form');
                const file = payload?.file;
                if (!form || !file?.public_id) return;

                const scope = this.closest('.js-procurement-attachment-scope') || form;
                const inputs = scope.querySelector('.js-procurement-attachment-inputs');
                const list = scope.querySelector('.js-procurement-selected-attachments');
                if (!inputs || !list || inputs.querySelector(`[data-public-id="${CSS.escape(String(file.public_id))}"]`)) return;

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = this.dataset.inputName || 'attachment_file_doc_nums[]';
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
                remove.setAttribute('aria-label', this.dataset.removeLabel || 'Remove attachment');
                remove.innerHTML = '<span class="fas fa-times" aria-hidden="true"></span>';
                item.append(remove);
                list.append(item);
                list.classList.remove('d-none');
            })
            .off('click.procurementAttachment', '.js-remove-procurement-attachment')
            .on('click.procurementAttachment', '.js-remove-procurement-attachment', function () {
                const scope = this.closest('.js-procurement-attachment-scope') || this.closest('form');
                const item = this.closest('[data-public-id]');
                if (!scope || !item) return;

                const publicId = item.dataset.publicId;
                item.remove();
                scope.querySelector(`.js-procurement-attachment-inputs [data-public-id="${CSS.escape(publicId)}"]`)?.remove();
                const list = scope.querySelector('.js-procurement-selected-attachments');
                if (list && list.children.length === 0) list.classList.add('d-none');
            });
    }
});
