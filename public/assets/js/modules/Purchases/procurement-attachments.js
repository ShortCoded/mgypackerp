document.addEventListener('DOMContentLoaded', () => {
    if (window.jQuery) {
        const $ = window.jQuery;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        function appendAttachment(trigger, file) {
            const form = trigger.closest('form');
            if (!form || !file?.public_id) return false;

            const scope = trigger.closest('.js-procurement-attachment-scope') || form;
            const inputs = scope.querySelector('.js-procurement-attachment-inputs');
            const list = scope.querySelector('.js-procurement-selected-attachments');
            if (!inputs || !list || inputs.querySelector(`[data-public-id="${CSS.escape(String(file.public_id))}"]`)) return false;

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = trigger.dataset.inputName || 'attachment_file_doc_nums[]';
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
            remove.setAttribute('aria-label', trigger.dataset.removeLabel || 'Remove attachment');
            remove.innerHTML = '<span class="fas fa-times" aria-hidden="true"></span>';
            item.append(remove);
            list.append(item);
            list.classList.remove('d-none');

            return true;
        }

        function updateCameraStatus(input, message, isError) {
            const status = input.closest('.js-procurement-attachment-scope')?.querySelector('.js-procurement-camera-status');
            if (!status) return;

            status.textContent = message;
            status.classList.toggle('d-none', !message);
            status.classList.toggle('text-danger', Boolean(isError));
            status.classList.toggle('text-success', !isError && Boolean(message));
        }

        function uploadCameraPhotos(input) {
            const files = Array.from(input.files || []);
            if (!files.length || !input.dataset.uploadUrl || !window.FilePickerUploader) return;

            input.disabled = true;
            updateCameraStatus(input, input.dataset.uploadingLabel || '', false);

            let uploaded = 0;
            const uploadNext = (index) => {
                if (index >= files.length) {
                    input.disabled = false;
                    input.value = '';
                    updateCameraStatus(input, input.dataset.uploadedLabel || '', false);
                    return;
                }

                const formData = new FormData();
                formData.append('file', files[index]);
                formData.append('accept', 'image');

                window.FilePickerUploader.upload({
                    url: input.dataset.uploadUrl,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        Accept: 'application/json'
                    }
                }).done(function (response) {
                    if (appendAttachment(input, response?.data?.file)) uploaded += 1;
                    uploadNext(index + 1);
                }).fail(function () {
                    input.disabled = false;
                    input.value = '';
                    const suffix = uploaded ? ` (${uploaded}/${files.length})` : '';
                    updateCameraStatus(input, `${input.dataset.uploadErrorLabel || ''}${suffix}`, true);
                });
            };

            uploadNext(0);
        }

        $(document)
            .off('file-picker:selected.procurement', '.js-procurement-attachment-picker')
            .on('file-picker:selected.procurement', '.js-procurement-attachment-picker', function (event, payload) {
                const file = payload?.file;
                appendAttachment(this, file);
            })
            .off('change.procurementCamera', '.js-procurement-camera-input')
            .on('change.procurementCamera', '.js-procurement-camera-input', function () {
                uploadCameraPhotos(this);
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
