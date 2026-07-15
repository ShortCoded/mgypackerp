(function ($, window, document) {
    'use strict';

    const config = window.AppArchive || {};
    const messages = $.extend({}, config.messages || {}, window.archiveMessages || {});
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    if (window.Dropzone) {
        window.Dropzone.autoDiscover = false;
    }

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken,
            Accept: 'application/json'
        };
    }

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function confirmDelete() {
        if (!window.Swal) {
            return $.Deferred().resolve({
                isConfirmed: false
            }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: messages.deleteConfirmTitle || '',
            text: messages.deleteConfirmText || '',
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: messages.deleteConfirmYes || '',
            cancelButtonText: messages.no || '',
            confirmButtonColor: '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function confirmFolderDelete() {
        if (!window.Swal) {
            return $.Deferred().resolve({
                isConfirmed: false
            }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: messages.folderDeleteConfirmTitle || messages.deleteConfirmTitle || '',
            text: messages.folderDeleteConfirmText || messages.deleteConfirmText || '',
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: messages.folderDeleteConfirmYes || messages.deleteConfirmYes || '',
            cancelButtonText: messages.no || '',
            confirmButtonColor: '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function updateArchiveContext($listContainer) {
        const $list = $listContainer.find('.js-archive-file-list').first();

        if (!$list.length) {
            return;
        }

        const listUrl = $list.data('current-list-url');
        const uploadUrl = $list.data('current-upload-url');
        const folder = $list.data('current-folder') || '';

        if (listUrl) {
            $listContainer.attr('data-archive-list-url', listUrl).data('archive-list-url', listUrl);
            $('.js-archive-dropzone').attr('data-list-url', listUrl).data('list-url', listUrl);
        }

        if (uploadUrl) {
            $('.js-archive-dropzone').each(function () {
                const dropzone = this.dropzone;

                $(this).attr('action', uploadUrl).attr('data-upload-url', uploadUrl).data('upload-url', uploadUrl);

                if (dropzone) {
                    dropzone.options.url = uploadUrl;
                }
            });
        }

        $('.js-archive-folder-form').find('input[name="parent_folder"]').val(folder);
    }

    function archiveDataTable($source) {
        const $browser = $source && $source.length ? $source.closest('.js-archive-browser') : $();
        const scopedTable = $browser.length ? $browser.find('.js-file-manager-table').get(0) : null;
        const table = scopedTable || $('.js-file-manager-table').get(0) || $('#archive-files-table').get(0);

        if (!table || !$.fn.DataTable || !$.fn.DataTable.isDataTable(table)) {
            return null;
        }

        return $(table).DataTable();
    }

    function refreshListFromContainer($container) {
        const directUrl = $container && $container.length ? $container.data('list-url') : null;
        let $listContainer = directUrl ? $('[data-archive-list-url="' + directUrl + '"]').first() : $();

        if (!$listContainer.length) {
            $listContainer = $container && $container.length
                ? $container.closest('.card').find('[data-archive-list-url]').first()
                : $();
        }

        if (!$listContainer.length) {
            $listContainer = $('[data-archive-list-url]').first();
        }

        const url = directUrl || $listContainer.data('archive-list-url');

        if (!url) {
            return false;
        }

        $.get(url).done(function (html) {
            $listContainer.html(html);
            updateArchiveContext($listContainer);
            if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                window.AppDataTables.applyFalconEnhancements($listContainer.get(0));
            }
        });

        return true;
    }

    function refreshArchiveUi($source) {
        const table = archiveDataTable($source);

        if (table) {
            table.ajax.reload(null, false);
            return;
        }

        refreshListFromContainer($source);
    }

    function errorMessage(file, response) {
        if (typeof response === 'string') {
            return response;
        }

        if (response && response.message) {
            return response.message;
        }

        if (response && response.errors) {
            const firstKey = Object.keys(response.errors)[0];
            const value = firstKey ? response.errors[firstKey] : null;

            if ($.isArray(value)) {
                return value[0];
            }

            return value || messages.unexpectedError;
        }

        return messages.unexpectedError;
    }

    function initDropzone(context) {
        if (!window.Dropzone) {
            return;
        }

        const $context = $(context || document);
        const $dropzones = $context
            .filter('.js-archive-dropzone')
            .add($context.find('.js-archive-dropzone'));

        $dropzones.each(function () {
            const element = this;
            const $element = $(element);

            if (element.dropzone || element.getAttribute('data-archive-dropzone-initialized') === 'true' || $element.data('archiveDropzoneInitialized')) {
                return;
            }

            const previewTemplateElement = element.querySelector('.js-dropzone-preview-template');
            const previewTemplate = previewTemplateElement ? previewTemplateElement.innerHTML.trim() : null;
            const previewsContainer = element.querySelector('.dz-preview-container');

            if (!previewsContainer) {
                return;
            }

            previewsContainer.innerHTML = '';

            const dropzoneOptions = {
                url: $element.data('upload-url') || $element.attr('action'),
                paramName: 'file',
                uploadMultiple: false,
                parallelUploads: Number($element.data('parallel-uploads') || config.parallelUploads || 2),
                maxFiles: Number($element.data('max-files') || config.maxFiles || 100),
                maxFilesize: Number($element.data('max-filesize') || config.maxFileSizeMiB || 50),
                acceptedFiles: $element.data('accepted-files') || config.acceptedFiles || null,
                addRemoveLinks: false,
                previewsContainer: previewsContainer,
                headers: headers(),
                dictMaxFilesExceeded: $element.data('too-many-files-message') || messages.tooManyFiles || '',
                dictFileTooBig: $element.data('file-too-large-message') || messages.fileTooLarge || '',
                dictInvalidFileType: $element.data('invalid-file-type-message') || messages.invalidFileType || ''
            };

            if (previewTemplate) {
                dropzoneOptions.previewTemplate = previewTemplate;
            }

            const dropzone = new window.Dropzone(element, dropzoneOptions);

            element.setAttribute('data-archive-dropzone-initialized', 'true');
            $element.data('archiveDropzoneInitialized', true);

            dropzone.on('success', function (file, response) {
                showToast('success', response && response.message ? response.message : '');
            });

            dropzone.on('queuecomplete', function () {
                refreshArchiveUi($element);
                window.setTimeout(function () {
                    dropzone.removeAllFiles(true);
                }, 750);
            });

            dropzone.on('error', function (file, response) {
                const message = errorMessage(file, response);

                if (file && file.previewElement) {
                    $(file.previewElement).find('[data-dz-errormessage]').text(message || '');
                }

                showToast('error', message);
            });
        });
    }

    function initDeleteHandler() {
        $(document).off('click.archiveFilesDelete', '.js-archive-file-delete').on('click.archiveFilesDelete', '.js-archive-file-delete', function () {
            const $button = $(this);
            const url = $button.data('archive-delete-url');

            if (!url || $button.prop('disabled')) {
                return;
            }

            confirmDelete().then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $button.prop('disabled', true);

                $.ajax({
                    url: url,
                    method: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response && response.message ? response.message : '');
                    refreshArchiveUi($button);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
        });
    }

    function initFolderHandlers() {
        $(document).off('submit.archiveFolderForm', '.js-archive-folder-form').on('submit.archiveFolderForm', '.js-archive-folder-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $submit = $form.find('[type="submit"]').first();

            $form.find('[data-error-for]').text('');
            $form.find('.is-invalid').removeClass('is-invalid');
            $submit.prop('disabled', true);

            $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method') || 'POST',
                headers: headers(),
                data: $form.serialize()
            }).done(function (response) {
                showToast('success', response && response.message ? response.message : '');
                $form.find('input[name="name"]').val('');
                refreshArchiveUi($form);
            }).fail(function (response) {
                const json = response.responseJSON || {};
                const errors = json.errors || {};

                Object.keys(errors).forEach(function (key) {
                    $form.find('[name="' + key + '"]').addClass('is-invalid');
                    $form.find('[data-error-for="' + key + '"]').text($.isArray(errors[key]) ? errors[key][0] : errors[key]);
                });

                showToast('error', json.message || messages.unexpectedError);
            }).always(function () {
                $submit.prop('disabled', false);
            });
        });

        $(document).off('click.archiveFolderLink', '.js-archive-folder-link[data-archive-ajax="true"]').on('click.archiveFolderLink', '.js-archive-folder-link[data-archive-ajax="true"]', function (event) {
            event.preventDefault();

            const $link = $(this);
            const $listContainer = $link.closest('[data-archive-list-url]');

            if (!$listContainer.length) {
                window.location.href = $link.attr('href');
                return;
            }

            $.get($link.attr('href')).done(function (html) {
                $listContainer.html(html);
                updateArchiveContext($listContainer);
                if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                    window.AppDataTables.applyFalconEnhancements($listContainer.get(0));
                }
            }).fail(function () {
                showToast('error', messages.unexpectedError);
            });
        });

        $(document).off('click.archiveFolderDelete', '.js-archive-folder-delete').on('click.archiveFolderDelete', '.js-archive-folder-delete', function () {
            const $button = $(this);
            const url = $button.data('folder-delete-url');

            if (!url || $button.prop('disabled')) {
                return;
            }

            confirmFolderDelete().then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $button.prop('disabled', true);

                $.ajax({
                    url: url,
                    method: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response && response.message ? response.message : '');
                    refreshArchiveUi($button);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
        });

        $(document).off('click.archiveFolderRename', '.js-archive-folder-rename').on('click.archiveFolderRename', '.js-archive-folder-rename', function () {
            const $button = $(this);
            const url = $button.data('folder-update-url');
            const currentName = $button.data('folder-name') || '';

            if (!url || $button.prop('disabled')) {
                return;
            }

            const prompt = window.Swal
                ? Swal.fire({
                    title: messages.renameFolderTitle || '',
                    input: 'text',
                    inputLabel: messages.folderName || '',
                    inputValue: currentName,
                    showCancelButton: true,
                    showCloseButton: true,
                    focusCancel: true,
                    allowEscapeKey: true,
                    confirmButtonText: messages.renameFolderTitle || '',
                    cancelButtonText: messages.no || ''
                })
                : $.Deferred().resolve({ isConfirmed: false, value: '' }).promise();

            prompt.then(function (result) {
                if (!result.isConfirmed || !result.value) {
                    return;
                }

                $button.prop('disabled', true);

                $.ajax({
                    url: url,
                    method: 'PUT',
                    headers: headers(),
                    data: { name: result.value }
                }).done(function (response) {
                    showToast('success', response && response.message ? response.message : '');
                    refreshArchiveUi($button);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
        });
    }

    function selectedFiles($scope) {
        return $scope.find('.js-archive-file-select:checked').map(function () {
            return this.value;
        }).get();
    }

    function updateBulkBar($scope) {
        const selected = selectedFiles($scope);
        const $bar = $scope.find('.js-archive-bulk-bar').first();

        $bar.toggleClass('d-none', selected.length === 0);
        $bar.find('.js-archive-selected-count').text(selected.length);
    }

    function initBulkHandlers() {
        $(document).off('change.archiveFileSelection', '.js-archive-file-select').on('change.archiveFileSelection', '.js-archive-file-select', function () {
            updateBulkBar($(this).closest('.js-archive-file-list'));
        });

        $(document).off('click.archiveBulkDownload', '.js-archive-bulk-download').on('click.archiveBulkDownload', '.js-archive-bulk-download', function () {
            const $list = $(this).closest('.js-archive-file-list');
            const $bar = $list.find('.js-archive-bulk-bar').first();
            const url = $bar.data('bulk-url');
            const files = selectedFiles($list);

            if (!url || files.length === 0) {
                showToast('warning', messages.noFilesSelected || '');
                return;
            }

            const $form = $('<form>', { method: 'POST', action: url }).append($('<input>', { type: 'hidden', name: '_token', value: csrfToken }));
            files.forEach(function (docNum) {
                $form.append($('<input>', { type: 'hidden', name: 'file_doc_nums[]', value: docNum }));
            });

            $('body').append($form);
            $form.trigger('submit');
            window.setTimeout(function () {
                $form.remove();
            }, 1000);
        });
    }

    window.AppArchiveFiles = {
        init: initDropzone,
        refresh: refreshArchiveUi
    };

    $(function () {
        initDropzone(document);
        initDeleteHandler();
        initFolderHandlers();
        initBulkHandlers();
    });
})(jQuery, window, document);
