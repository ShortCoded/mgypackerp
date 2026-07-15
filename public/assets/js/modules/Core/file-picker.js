(function ($, window, document) {
    'use strict';

    const modalSelector = '.js-file-picker-modal';
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    const FilePickerUploader = window.FilePickerUploader || {
        upload: function (options) {
            return $.ajax(options);
        }
    };
    const state = {
        $trigger: $(),
        config: {},
        currentFolder: '',
        selectedFile: null,
        permissions: {
            upload: false,
            createFolder: false
        }
    };

    window.FilePickerUploader = FilePickerUploader;

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken,
            Accept: 'application/json'
        };
    }

    function modalMessage(name, fallback) {
        const value = modal().data(name);

        if (value !== undefined && value !== null && String(value) !== '') {
            return String(value);
        }

        return fallback || '';
    }

    function confirmDeleteFile() {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: modalMessage('delete-confirm-title'),
            text: modalMessage('delete-confirm-text'),
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: modalMessage('delete-confirm-yes'),
            cancelButtonText: modalMessage('delete-confirm-no'),
            confirmButtonColor: '#d33',
            cancelButtonColor: '#748194',
            heightAuto: false
        });
    }

    function reloadFileManagerTables() {
        if (window.AppFileManager && typeof window.AppFileManager.reloadAll === 'function') {
            window.AppFileManager.reloadAll();
        }
    }

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function escapeHtml(value) {
        return $('<div></div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function modal() {
        return $(modalSelector).first();
    }

    function bootstrapModal($modal) {
        return window.bootstrap && window.bootstrap.Modal && $modal.length
            ? window.bootstrap.Modal.getOrCreateInstance($modal[0])
            : null;
    }

    function boolData($element, name, fallback) {
        const attribute = $element.attr('data-' + name);

        if (attribute === undefined) {
            return fallback;
        }

        return String(attribute) === 'true' || String(attribute) === '1';
    }

    function triggerConfig($trigger) {
        return {
            accept: String($trigger.data('picker-accept') || 'image'),
            max: Number($trigger.data('picker-max') || 1),
            title: String($trigger.data('picker-title') || ''),
            targetInput: String($trigger.data('picker-target-input') || ''),
            preview: String($trigger.data('picker-preview') || ''),
            uploader: String($trigger.data('picker-uploader') || ''),
            collection: String($trigger.data('picker-collection') || ''),
            initialFolder: String($trigger.data('picker-current-folder') || ''),
            allowUpload: boolData($trigger, 'picker-allow-upload', true),
            allowCreateFolder: boolData($trigger, 'picker-allow-create-folder', true)
        };
    }

    function setAlert(message) {
        modal().find('.js-file-picker-alert')
            .toggleClass('d-none', !message)
            .text(message || '');
    }

    function responseMessage(xhr) {
        if (xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }

        if (xhr.responseJSON && xhr.responseJSON.errors) {
            const firstKey = Object.keys(xhr.responseJSON.errors)[0];
            const firstError = firstKey ? xhr.responseJSON.errors[firstKey] : null;

            if ($.isArray(firstError)) {
                return firstError[0] || '';
            }

            return firstError || '';
        }

        return '';
    }

    function setLoading(loading) {
        const $modal = modal();

        $modal.find('.js-file-picker-loading').toggleClass('d-none', !loading);
        $modal.find('.js-file-picker-grid').toggleClass('d-none', loading);
        $modal.find('.js-file-picker-empty').addClass('d-none');
    }

    function updateActions() {
        const $modal = modal();
        const canUpload = state.config.allowUpload && state.permissions.upload;
        const canCreateFolder = state.config.allowCreateFolder && state.permissions.createFolder;

        $modal.find('.js-file-picker-upload-button').toggleClass('d-none', !canUpload);
        $modal.find('.js-file-picker-create-folder-toggle').toggleClass('d-none', !canCreateFolder);
        $modal.find('.js-file-picker-create-folder-form').toggleClass('d-none', true).toggleClass('d-flex', false);
        $modal.find('.js-file-picker-select').prop('disabled', !state.selectedFile);
    }

    function updatePickerLabels() {
        const $modal = modal();
        const documentMode = state.config.accept === 'document';

        $modal.find('.js-file-picker-upload-label').text(modalMessage(documentMode ? 'upload-file-label' : 'upload-image-label'));
        $modal.find('.js-file-picker-select-label').text(modalMessage(documentMode ? 'choose-file-label' : 'choose-image-label'));
        $modal.find('.js-file-picker-empty-label').text(modalMessage(documentMode ? 'empty-files-label' : 'empty-images-label'));
    }

    function renderBreadcrumbs(items) {
        const $breadcrumb = modal().find('.js-file-picker-breadcrumb');

        $breadcrumb.empty();

        (items || []).forEach(function (item, index) {
            const isLast = index === items.length - 1;
            const $li = $('<li></li>').addClass('breadcrumb-item').toggleClass('active', isLast);

            if (isLast) {
                $li.attr('aria-current', 'page').text(item.name || '');
            } else {
                $('<button type="button"></button>')
                    .addClass('btn btn-link p-0 align-baseline js-file-picker-breadcrumb-item')
                    .attr('data-folder', item.public_id || '')
                    .text(item.name || '')
                    .appendTo($li);
            }

            $breadcrumb.append($li);
        });
    }

    function folderCard(item) {
        return [
            '<button type="button" class="file-picker-card js-file-picker-card js-file-picker-folder p-0 overflow-hidden" data-type="folder" data-public-id="', escapeHtml(item.public_id), '">',
            '<div class="file-picker-thumb d-flex align-items-center justify-content-center">',
            '<span class="fas fa-folder text-warning file-picker-folder-icon" aria-hidden="true"></span>',
            '</div>',
            '<div class="p-2">',
            '<div class="fw-semibold text-900 text-truncate" title="', escapeHtml(item.name), '">', escapeHtml(item.name), '</div>',
            '<div class="small text-600 text-truncate" dir="ltr">', escapeHtml(item.doc_num || item.public_id), '</div>',
            '</div>',
            '</button>'
        ].join('');
    }

    function fileIconClass(item) {
        const extension = String(item.extension || '').toLowerCase();

        if (extension === 'pdf') {
            return 'fa-file-pdf text-danger';
        }

        if (['doc', 'docx'].indexOf(extension) !== -1) {
            return 'fa-file-word text-primary';
        }

        if (['xls', 'xlsx'].indexOf(extension) !== -1) {
            return 'fa-file-excel text-success';
        }

        if (extension === 'txt') {
            return 'fa-file-alt text-500';
        }

        return 'fa-file text-400';
    }

    function fileCard(item) {
        const image = item.thumbnail_url
            ? '<img src="' + escapeHtml(item.thumbnail_url) + '" alt="' + escapeHtml(item.name) + '">'
            : '<span class="fas ' + fileIconClass(item) + ' fs-4" aria-hidden="true"></span>';
        const deleteButton = item.can_delete && item.delete_url
            ? [
                '<button type="button" class="btn btn-falcon-default btn-sm text-danger rounded-circle position-absolute top-0 end-0 m-1 p-0 file-picker-delete-button js-file-picker-delete-file"',
                ' data-delete-url="', escapeHtml(item.delete_url), '"',
                ' data-public-id="', escapeHtml(item.public_id), '"',
                ' data-file-name="', escapeHtml(item.name), '"',
                ' aria-label="', escapeHtml(modalMessage('delete-confirm-title')), '"',
                ' title="', escapeHtml(modalMessage('delete-confirm-title')), '">',
                '<span class="fas fa-trash-alt fs-10" aria-hidden="true"></span>',
                '</button>'
            ].join('')
            : '';

        return [
            '<div class="file-picker-card-wrap position-relative">',
            deleteButton,
            '<button type="button" class="file-picker-card js-file-picker-card js-file-picker-file p-0 overflow-hidden" data-type="file" data-public-id="', escapeHtml(item.public_id), '">',
            '<div class="file-picker-thumb d-flex align-items-center justify-content-center overflow-hidden">', image, '</div>',
            '<div class="p-2">',
            '<div class="fw-semibold text-900 text-truncate" title="', escapeHtml(item.name), '">', escapeHtml(item.name), '</div>',
            '<div class="small text-600 text-truncate">', escapeHtml(item.size_label || ''), '</div>',
            '</div>',
            '</button>',
            '</div>'
        ].join('');
    }

    function renderItems(data) {
        const $modal = modal();
        const folders = data.folders || [];
        const files = data.files || [];
        const $grid = $modal.find('.js-file-picker-grid');

        state.currentFolder = data.folder && data.folder.public_id ? data.folder.public_id : '';
        state.selectedFile = null;
        state.permissions = {
            upload: Boolean(data.permissions && data.permissions.upload),
            createFolder: Boolean(data.permissions && data.permissions.create_folder)
        };

        renderBreadcrumbs(data.breadcrumbs || []);
        $grid.empty();

        folders.forEach(function (item) {
            $grid.append(folderCard(item));
        });

        files.forEach(function (item) {
            const $card = $(fileCard(item));

            $card.data('file', item);
            $card.find('.js-file-picker-file').first().data('file', item);
            $grid.append($card);
        });

        $modal.find('.js-file-picker-empty').toggleClass('d-none', folders.length + files.length > 0);
        updateActions();
    }

    function loadFolder(folder, search) {
        const $modal = modal();
        const url = $modal.data('list-url');

        setAlert('');
        setLoading(true);

        $.ajax({
            url: url,
            method: 'GET',
            data: {
                folder: folder || '',
                accept: state.config.accept || 'image',
                q: search || ''
            },
            headers: headers()
        }).done(function (response) {
            renderItems(response && response.data ? response.data : {});
        }).fail(function (xhr) {
            setAlert(responseMessage(xhr));
        }).always(function () {
            setLoading(false);
        });
    }

    function openPicker($trigger) {
        const $modal = modal();

        if (!$modal.length) {
            return;
        }

        state.$trigger = $trigger;
        state.config = triggerConfig($trigger);
        state.currentFolder = state.config.initialFolder || '';
        state.selectedFile = null;

        $modal.find('.js-file-picker-title').text(state.config.title || $modal.find('.js-file-picker-title').text());
        updatePickerLabels();
        $modal.find('.js-file-picker-search-input').val('');
        $modal.find('.js-file-picker-upload-input')
            .val('')
            .attr('accept', state.config.accept === 'document'
                ? String($modal.data('accepted-document-files') || '')
                : String($modal.data('accepted-image-files') || ''));
        setAlert('');
        updateActions();

        const instance = bootstrapModal($modal);

        if (instance) {
            instance.show();
        } else {
            $modal.modal('show');
        }

        loadFolder(state.currentFolder, '');
    }

    function selectCard($card) {
        modal().find('.js-file-picker-card').removeClass('is-selected');
        $card.addClass('is-selected');

        if ($card.data('type') === 'file') {
            state.selectedFile = $card.data('file') || null;
        } else {
            state.selectedFile = null;
        }

        modal().find('.js-file-picker-select').prop('disabled', !state.selectedFile);
    }

    function applyTargets(file) {
        if (state.config.targetInput) {
            $(state.config.targetInput).val(file.public_id || '').trigger('change');
        }

        if (state.config.preview && (file.thumbnail_url || file.url)) {
            $(state.config.preview).attr('src', file.thumbnail_url || file.url).removeClass('d-none');
        }
    }

    function selectedPayload(file) {
        return {
            public_id: file.public_id || '',
            name: file.name || file.original_name || '',
            url: file.url || '',
            thumbnail_url: file.thumbnail_url || '',
            mime_type: file.mime_type || '',
            size: Number(file.size || 0),
            file: file,
            files: [file],
            config: state.config,
            trigger: state.$trigger.get(0) || null
        };
    }

    function confirmSelection() {
        const $modal = modal();

        if (!state.selectedFile) {
            setAlert($modal.data('cannot-select-message') || '');
            return;
        }

        const payload = selectedPayload(state.selectedFile);

        applyTargets(state.selectedFile);

        if (state.$trigger.length) {
            state.$trigger.trigger('file-picker:selected', [payload]);
        }

        const instance = bootstrapModal($modal);

        if (instance) {
            instance.hide();
        } else {
            $modal.modal('hide');
        }
    }

    function uploadFile(file) {
        const $modal = modal();
        const formData = new FormData();

        if (!file) {
            return;
        }

        formData.append('file', file);
        formData.append('folder', state.currentFolder || '');
        formData.append('accept', state.config.accept || 'image');

        setAlert('');
        setLoading(true);

        FilePickerUploader.upload({
            url: $modal.data('upload-url'),
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: headers()
        }).done(function (response) {
            showToast('success', response && response.message ? response.message : '');
            reloadFileManagerTables();
            loadFolder(state.currentFolder, $modal.find('.js-file-picker-search-input').val() || '');
        }).fail(function (xhr) {
            setAlert(responseMessage(xhr));
            setLoading(false);
        }).always(function () {
            $modal.find('.js-file-picker-upload-input').val('');
        });
    }

    function createFolder($form) {
        const $modal = modal();
        const name = $.trim($form.find('.js-file-picker-folder-name').val() || '');

        if (name === '') {
            return;
        }

        setAlert('');

        $.ajax({
            url: $modal.data('folder-store-url'),
            method: 'POST',
            data: {
                name: name,
                parent_folder: state.currentFolder || ''
            },
            headers: headers()
        }).done(function (response) {
            $form.find('.js-file-picker-folder-name').val('');
            $form.addClass('d-none').removeClass('d-flex');
            showToast('success', response && response.message ? response.message : '');
            reloadFileManagerTables();
            loadFolder(state.currentFolder, $modal.find('.js-file-picker-search-input').val() || '');
        }).fail(function (xhr) {
            setAlert(responseMessage(xhr));
        });
    }

    function clearDeletedTarget(publicId) {
        const selectedPublicId = String(publicId || '').trim();

        if (!state.config.targetInput || selectedPublicId === '') {
            return;
        }

        const $target = $(state.config.targetInput).first();

        if (!$target.length || String($target.val() || '').trim() !== selectedPublicId) {
            return;
        }

        $target.val('').trigger('change');

        if (state.$trigger.length) {
            state.$trigger.trigger('file-picker:deleted', [{
                public_id: selectedPublicId,
                config: state.config,
                trigger: state.$trigger.get(0) || null,
                was_selected: true
            }]);
        }
    }

    function deleteFile($button) {
        const url = String($button.data('delete-url') || '').trim();
        const publicId = String($button.data('public-id') || '').trim();

        if (!url || $button.prop('disabled')) {
            return;
        }

        confirmDeleteFile().then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            $button.prop('disabled', true);
            setAlert('');

            $.ajax({
                url: url,
                method: 'DELETE',
                headers: headers()
            }).done(function (response) {
                if (state.selectedFile && state.selectedFile.public_id === publicId) {
                    state.selectedFile = null;
                    updateActions();
                }

                clearDeletedTarget(publicId);
                showToast('success', response && response.message ? response.message : '');
                reloadFileManagerTables();
                loadFolder(state.currentFolder, modal().find('.js-file-picker-search-input').val() || '');
            }).fail(function (xhr) {
                setAlert(responseMessage(xhr) || modalMessage('unexpected-error-message'));
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    }

    $(document)
        .off('click.coreFilePickerOpen', '[data-file-picker]')
        .on('click.coreFilePickerOpen', '[data-file-picker]', function (event) {
            event.preventDefault();
            openPicker($(this));
        })
        .off('submit.coreFilePickerSearch', modalSelector + ' .js-file-picker-search-form')
        .on('submit.coreFilePickerSearch', modalSelector + ' .js-file-picker-search-form', function (event) {
            event.preventDefault();
            loadFolder(state.currentFolder, $(this).find('.js-file-picker-search-input').val() || '');
        })
        .off('click.coreFilePickerBreadcrumb', modalSelector + ' .js-file-picker-breadcrumb-item')
        .on('click.coreFilePickerBreadcrumb', modalSelector + ' .js-file-picker-breadcrumb-item', function () {
            loadFolder($(this).data('folder') || '', modal().find('.js-file-picker-search-input').val() || '');
        })
        .off('click.coreFilePickerCard', modalSelector + ' .js-file-picker-card')
        .on('click.coreFilePickerCard', modalSelector + ' .js-file-picker-card', function () {
            selectCard($(this));
        })
        .off('click.coreFilePickerDeleteFile', modalSelector + ' .js-file-picker-delete-file')
        .on('click.coreFilePickerDeleteFile', modalSelector + ' .js-file-picker-delete-file', function (event) {
            event.preventDefault();
            event.stopPropagation();
            deleteFile($(this));
        })
        .off('dblclick.coreFilePickerFolder', modalSelector + ' .js-file-picker-folder')
        .on('dblclick.coreFilePickerFolder', modalSelector + ' .js-file-picker-folder', function () {
            loadFolder($(this).data('public-id') || '', modal().find('.js-file-picker-search-input').val() || '');
        })
        .off('dblclick.coreFilePickerFile', modalSelector + ' .js-file-picker-file')
        .on('dblclick.coreFilePickerFile', modalSelector + ' .js-file-picker-file', function () {
            selectCard($(this));
            confirmSelection();
        })
        .off('keydown.coreFilePickerCard', modalSelector + ' .js-file-picker-card')
        .on('keydown.coreFilePickerCard', modalSelector + ' .js-file-picker-card', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            event.preventDefault();
            selectCard($(this));

            if ($(this).data('type') === 'folder') {
                loadFolder($(this).data('public-id') || '', modal().find('.js-file-picker-search-input').val() || '');
                return;
            }

            if (event.key === 'Enter') {
                confirmSelection();
            }
        })
        .off('click.coreFilePickerSelect', modalSelector + ' .js-file-picker-select')
        .on('click.coreFilePickerSelect', modalSelector + ' .js-file-picker-select', confirmSelection)
        .off('click.coreFilePickerUpload', modalSelector + ' .js-file-picker-upload-button')
        .on('click.coreFilePickerUpload', modalSelector + ' .js-file-picker-upload-button', function () {
            modal().find('.js-file-picker-upload-input').trigger('click');
        })
        .off('change.coreFilePickerUpload', modalSelector + ' .js-file-picker-upload-input')
        .on('change.coreFilePickerUpload', modalSelector + ' .js-file-picker-upload-input', function () {
            uploadFile(this.files && this.files.length ? this.files[0] : null);
        })
        .off('click.coreFilePickerFolderToggle', modalSelector + ' .js-file-picker-create-folder-toggle')
        .on('click.coreFilePickerFolderToggle', modalSelector + ' .js-file-picker-create-folder-toggle', function () {
            const $form = modal().find('.js-file-picker-create-folder-form');

            $form.toggleClass('d-none').toggleClass('d-flex');

            if (!$form.hasClass('d-none')) {
                $form.find('.js-file-picker-folder-name').trigger('focus');
            }
        })
        .off('submit.coreFilePickerFolder', modalSelector + ' .js-file-picker-create-folder-form')
        .on('submit.coreFilePickerFolder', modalSelector + ' .js-file-picker-create-folder-form', function (event) {
            event.preventDefault();
            createFolder($(this));
        })
        .off('hidden.bs.modal.coreFilePicker', modalSelector)
        .on('hidden.bs.modal.coreFilePicker', modalSelector, function () {
            state.$trigger = $();
            state.selectedFile = null;
            $(this).find('.js-file-picker-upload-input').val('');
        });
})(jQuery, window, document);
