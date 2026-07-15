(function ($, window, document) {
    'use strict';

    const messages = window.archiveMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    const fileManagerTables = {};

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken,
            Accept: 'application/json'
        };
    }

    function confirmDialog(options) {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: options.title,
            text: options.text,
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: options.confirmButtonText,
            cancelButtonText: options.cancelButtonText || messages.no || '',
            confirmButtonColor: options.confirmButtonColor || '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function setLoading($button, loading) {
        $button.prop('disabled', loading);
        $button.toggleClass('disabled', loading);
    }

    function fieldName(name) {
        return String(name || '').replace(/\[\]$/, '');
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('.alert').addClass('d-none');
    }

    function showValidationErrors($form, errors) {
        Object.keys(errors || {}).forEach(function (field) {
            const normalized = fieldName(field);
            const message = errors[field][0] || '';

            $form.find('[name="' + normalized + '"]').addClass('is-invalid');
            $form.find('[data-error-for="' + normalized + '"]').text(message);
        });
    }

    function showFormNotice($form, message) {
        $form.find('.js-archive-document-number-settings-alert')
            .removeClass('d-none')
            .find('.js-archive-document-number-settings-alert-message')
            .text(message || messages.unexpectedError || '');
    }

    function initDocumentNumberSettings() {
        $(document)
            .off('submit.fileManagerDocSettings', '.js-archive-document-number-settings-form')
            .on('submit.fileManagerDocSettings', '.js-archive-document-number-settings-form', function (event) {
                event.preventDefault();

                const $form = $(this);
                const $button = $form.find('[type="submit"]');

                clearFormErrors($form);
                setLoading($button, true);

                $.ajax({
                    url: $form.attr('action'),
                    method: $form.attr('method') || 'POST',
                    data: $form.serialize(),
                    headers: headers()
                }).done(function (response) {
                    const data = response && response.data ? response.data : {};

                    if (data.archive_files) {
                        $form.find('[name="archive_files_prefix"]').val(data.archive_files.prefix || '');
                        $form.find('[name="archive_files_padding"]').val(data.archive_files.padding);
                    }

                    if (data.archive_folders) {
                        $form.find('[name="archive_folders_prefix"]').val(data.archive_folders.prefix || '');
                        $form.find('[name="archive_folders_padding"]').val(data.archive_folders.padding);
                    }

                    showToast('success', response.message);
                }).fail(function (response) {
                    if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                        showValidationErrors($form, response.responseJSON.errors);
                        return;
                    }

                    showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    setLoading($button, false);
                });
            });

        $(document)
            .off('input.fileManagerDocSettings change.fileManagerDocSettings', '.js-archive-document-number-settings-form .is-invalid')
            .on('input.fileManagerDocSettings change.fileManagerDocSettings', '.js-archive-document-number-settings-form .is-invalid', function () {
                const $input = $(this);
                const field = fieldName($input.attr('name'));

                $input.removeClass('is-invalid');
                $input.closest('form').find('[data-error-for="' + field + '"]').text('');
            });
    }

    function publicLinkModal() {
        const $modal = $('#archive-public-link-modal');

        if ($modal.length === 0) {
            return null;
        }

        return {
            $element: $modal,
            instance: window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance($modal[0]) : null
        };
    }

    function publicLinkPayload(response) {
        return response && response.data ? response.data : {};
    }

    function setPublicLinkModalState($source, data) {
        const modal = publicLinkModal();

        if (!modal) {
            return;
        }

        const $modal = modal.$element;
        const exists = Boolean(data.exists && data.url);
        const createUrl = $source.data('public-link-create-url') || $modal.data('public-link-create-url') || '';
        const revokeUrl = $source.data('public-link-revoke-url') || $modal.data('public-link-revoke-url') || '';
        const itemType = $source.data('public-link-item-type') || $modal.data('public-link-item-type') || '';
        const itemName = $source.data('public-link-item-name') || $modal.data('public-link-item-name') || '';

        $modal.data('public-link-create-url', createUrl);
        $modal.data('public-link-revoke-url', revokeUrl);
        $modal.data('public-link-item-type', itemType);
        $modal.data('public-link-item-name', itemName);
        $modal.find('.js-archive-public-link-item').text($.trim(itemType + ' - ' + itemName));
        $modal.find('.js-archive-public-link-url-wrap').toggleClass('d-none', !exists);
        $modal.find('.js-archive-public-link-url').val(exists ? data.url : '');
        $modal.find('.js-archive-public-link-open').attr('href', exists ? data.url : '#').toggleClass('disabled', !exists);
        $modal.find('.js-archive-public-link-revoke').toggleClass('d-none', !exists || !revokeUrl);
        $modal.find('.js-archive-public-link-modal-create').toggleClass('d-none', !createUrl);
        $modal.find('.js-archive-public-link-create-label').toggleClass('d-none', exists);
        $modal.find('.js-archive-public-link-save-label').toggleClass('d-none', !exists);
        $modal.find('.js-archive-public-link-allow-download')
            .prop('checked', Boolean(data.allow_download))
            .prop('disabled', !createUrl);
        $modal.find('.js-archive-public-link-preview-badge').toggleClass('d-none', !exists || !data.allow_preview);
        $modal.find('.js-archive-public-link-download-badge').toggleClass('d-none', !exists || data.allow_download);
        $modal.find('.js-archive-public-link-download-enabled-badge').toggleClass('d-none', !exists || !data.allow_download);
        $modal.find('.js-archive-public-link-alert')
            .toggleClass('d-none', exists)
            .text(exists ? '' : (messages.publicLinkMissing || ''));

        if (modal.instance) {
            modal.instance.show();
        } else {
            $modal.modal('show');
        }
    }

    function publicLinkSettingsPayload() {
        const $modal = $('#archive-public-link-modal');

        return {
            allow_preview: 1,
            allow_download: $modal.find('.js-archive-public-link-allow-download').is(':checked') ? 1 : 0
        };
    }

    function requestPublicLink($source, url, method, data) {
        if (!url || $source.prop('disabled')) {
            return;
        }

        $source.prop('disabled', true);

        $.ajax({
            url: url,
            method: method,
            data: data || {},
            headers: headers()
        }).done(function (response) {
            if (response && response.message) {
                showToast('success', response.message);
            }

            setPublicLinkModalState($source, publicLinkPayload(response));
        }).fail(function (response) {
            showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
        }).always(function () {
            $source.prop('disabled', false);
        });
    }

    function copyPublicLink() {
        const $input = $('#archive-public-link-modal').find('.js-archive-public-link-url').first();
        const value = $input.val() || '';

        if (value === '') {
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                showToast('success', messages.publicLinkCopied || '');
            });
            return;
        }

        $input.trigger('select');
        document.execCommand('copy');
        showToast('success', messages.publicLinkCopied || '');
    }

    function initPublicLinkHandlers() {
        $(document)
            .off('click.fileManagerPublicLinkCreate', '.js-archive-public-link-create')
            .on('click.fileManagerPublicLinkCreate', '.js-archive-public-link-create', function () {
                const $button = $(this);

                requestPublicLink($button, $button.data('public-link-create-url'), 'POST');
            });

        $(document)
            .off('click.fileManagerPublicLinkManage', '.js-archive-public-link-manage')
            .on('click.fileManagerPublicLinkManage', '.js-archive-public-link-manage', function () {
                const $button = $(this);

                requestPublicLink($button, $button.data('public-link-show-url'), 'GET');
            });

        $(document)
            .off('click.fileManagerPublicLinkModalCreate', '.js-archive-public-link-modal-create')
            .on('click.fileManagerPublicLinkModalCreate', '.js-archive-public-link-modal-create', function () {
                const $button = $(this);
                const url = $('#archive-public-link-modal').data('public-link-create-url') || '';

                requestPublicLink($button, url, 'POST', publicLinkSettingsPayload());
            });

        $(document)
            .off('click.fileManagerPublicLinkRevoke', '.js-archive-public-link-revoke')
            .on('click.fileManagerPublicLinkRevoke', '.js-archive-public-link-revoke', function () {
                const $button = $(this);
                const url = $('#archive-public-link-modal').data('public-link-revoke-url') || '';

                requestPublicLink($button, url, 'DELETE');
            });

        $(document)
            .off('click.fileManagerPublicLinkCopy', '.js-archive-public-link-copy')
            .on('click.fileManagerPublicLinkCopy', '.js-archive-public-link-copy', copyPublicLink);
    }

    function initFileManagerTable() {
        $('.js-file-manager-table, #archive-files-table').each(function () {
            initFileManagerTableInstance($(this));
        });
    }

    function initFileManagerTableInstance($table) {
        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0]) || $table.data('fileManagerTableInitialized')) {
            return;
        }

        $table.data('fileManagerTableInitialized', true);

        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) { return options; };
        const protectedColumns = [0, 1, -1];
        const responsiveControlTarget = 1;
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-file-manager-select';
        const selectAllSelector = $table.data('select-all') || '#file_manager_select_all';
        const bulkActionsBarSelector = $table.data('bulk-actions-bar') || '#file_manager_bulk_actions_bar';
        const bulkActionSelectSelector = $table.data('bulk-action-select') || '#file_manager_bulk_action_select';
        const bulkActionApplySelector = $table.data('bulk-action-apply') || '#file_manager_bulk_action_apply';
        const selectedCountSelector = $table.data('selected-count') || '#file_manager_selected_count';
        const trashFilterSelector = $table.data('trash-filter') || '#file_manager_trash_filter';
        const tableKey = $table.attr('id') || 'archive-files-table';
        const tableNamespace = String(tableKey).replace(/[^A-Za-z0-9_]/g, '');
        const selectedItems = new Map();
        const moveModalSelector = '#archive-move-modal';
        let moveFolderSearchTimer = null;

        function shieldSelectionEvents() {
            if ($table.data('file-manager-selection-shielded')) {
                return;
            }

            ['click', 'mousedown', 'mouseup'].forEach(function (eventName) {
                $table[0].addEventListener(eventName, function (event) {
                    if (event.target && event.target.closest('input.js-file-manager-select')) {
                        event.stopPropagation();
                    }
                }, true);
            });

            $table.data('file-manager-selection-shielded', true);
        }

        function itemFromCheckbox(checkbox) {
            const $checkbox = $(checkbox);
            const docNum = String($checkbox.data('doc-num') || checkbox.value || '').trim();

            return {
                docNum: docNum,
                itemType: String($checkbox.data('item-type') || '').trim(),
                isTrashed: String($checkbox.data('is-trashed') || '0') === '1'
            };
        }

        function pageCheckboxes(api) {
            if (api && typeof api.rows === 'function') {
                return $(api.rows({ page: 'current' }).nodes()).find('input.js-file-manager-select');
            }

            return $table.find(rowCheckboxSelector);
        }

        function selectedFiles() {
            return Array.from(selectedItems.values()).filter(function (item) {
                return item.itemType === 'file';
            });
        }

        function hasSelectedFolders() {
            return Array.from(selectedItems.values()).some(function (item) {
                return item.itemType === 'folder';
            });
        }

        function selectedMoveItems() {
            return Array.from(selectedItems.values()).map(function (item) {
                return {
                    item_type: item.itemType,
                    item_doc_num: item.docNum
                };
            }).filter(function (item) {
                return item.item_type !== '' && item.item_doc_num !== '';
            });
        }

        function responseMessage(response) {
            const json = response.responseJSON || {};

            if (json.message) {
                return json.message;
            }

            if (json.errors) {
                const firstKey = Object.keys(json.errors)[0];
                const firstError = firstKey ? json.errors[firstKey] : null;

                if ($.isArray(firstError)) {
                    return firstError[0] || '';
                }

                return firstError || '';
            }

            return messages.unexpectedError || '';
        }

        function currentTrashFilter() {
            const $filter = $(trashFilterSelector);

            return $filter.length ? String($filter.val() || 'active') : 'active';
        }

        function updateBulkActionOptions() {
            const trashFilter = currentTrashFilter();
            const $select = $(bulkActionSelectSelector);
            let firstVisibleAction = '';

            $select.find('option').each(function () {
                const $option = $(this);
                const filters = String($option.data('visible-filters') || '').split(/\s+/).filter(Boolean);
                const isVisible = filters.length === 0 || filters.indexOf(trashFilter) !== -1;

                $option.prop('disabled', !isVisible).toggleClass('d-none', !isVisible);

                if (isVisible && firstVisibleAction === '') {
                    firstVisibleAction = String($option.val() || '');
                }
            });

            if (firstVisibleAction !== '' && $select.find('option:selected:not(:disabled)').length === 0) {
                $select.val(firstVisibleAction);
            }

            if (firstVisibleAction === '') {
                $select.val('');
            }

            return firstVisibleAction !== '';
        }

        function updateBulkActionsUi() {
            const selectedCount = selectedItems.size;
            const hasVisibleBulkAction = updateBulkActionOptions();
            const $actions = $(bulkActionsBarSelector);
            const $applyButton = $(bulkActionApplySelector);
            const applyLabel = $applyButton.data('label') || '';
            const shouldShow = selectedCount > 0 && hasVisibleBulkAction;

            $actions
                .toggleClass('d-none', !shouldShow)
                .toggleClass('d-flex', shouldShow);

            $(selectedCountSelector).text(selectedCount);

            $applyButton
                .prop('disabled', selectedCount === 0 || !hasVisibleBulkAction)
                .find('span:last')
                .text(applyLabel + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));
        }

        function updateSelectAllState(api) {
            const $checkboxes = pageCheckboxes(api);
            const selectableDocNums = $checkboxes.map(function () {
                return itemFromCheckbox(this).docNum;
            }).get().filter(function (docNum) {
                return docNum !== '';
            });
            const checkedOnPage = selectableDocNums.filter(function (docNum) {
                return selectedItems.has(docNum);
            }).length;

            $(selectAllSelector)
                .prop('checked', selectableDocNums.length > 0 && checkedOnPage === selectableDocNums.length)
                .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < selectableDocNums.length);
        }

        function restoreSelectionState(api) {
            pageCheckboxes(api).each(function () {
                const item = itemFromCheckbox(this);

                $(this).prop('checked', item.docNum !== '' && selectedItems.has(item.docNum));
            });

            updateSelectAllState(api);
            updateBulkActionsUi();
        }

        function clearSelection(api) {
            selectedItems.clear();
            pageCheckboxes(api).prop('checked', false);
            updateSelectAllState(api);
            updateBulkActionsUi();
        }

        function patchResponsiveControlTarget(api) {
            const settings = api && typeof api.settings === 'function' ? api.settings()[0] : null;
            const responsive = settings && settings._responsive ? settings._responsive : null;

            if (!responsive || responsive._fileManagerControlTargetPatched) {
                return;
            }

            responsive.c.details.target = responsiveControlTarget;
            responsive._fileManagerControlTargetPatched = true;
            responsive._controlClass = function () {
                const dt = this.s.dt;

                dt.cells(null, function (index) {
                    return index !== responsiveControlTarget;
                }, { page: 'current' }).nodes().to$()
                    .filter('.dtr-control')
                    .removeClass('dtr-control')
                    .removeAttr('tabindex')
                    .removeData('dtr-keyboard');

                dt.cells(null, responsiveControlTarget, { page: 'current' }).nodes().to$()
                    .addClass('dtr-control');

                this._tabIndexes();
            };
        }

        function syncResponsiveControlColumn(api) {
            patchResponsiveControlTarget(api);

            const $rows = api && typeof api.rows === 'function'
                ? $(api.rows({ page: 'current' }).nodes())
                : $table.find('tbody tr:not(.child)');

            $table.find('thead th').eq(0).removeClass('dtr-control');
            $table.find('thead th').eq(responsiveControlTarget).addClass('dtr-control');

            $rows.each(function () {
                const $cells = $(this).children('td, th');

                $cells.eq(0)
                    .removeClass('dtr-control')
                    .removeAttr('tabindex')
                    .removeData('dtr-keyboard');

                $cells.eq(responsiveControlTarget).addClass('dtr-control');
            });
        }

        function queueResponsiveControlSync(api) {
            syncResponsiveControlColumn(api);

            window.requestAnimationFrame(function () {
                syncResponsiveControlColumn(api);
            });

            window.setTimeout(function () {
                syncResponsiveControlColumn(api);
            }, 50);
        }

        function protectStateColumns(data) {
            if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
                window.AppDataTables.protectStateColumns(data, protectedColumns);
            }
        }

        function showProtectedColumns(api) {
            if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                window.AppDataTables.showColumns(api, protectedColumns);
            }
        }

        function reloadTable() {
            table.ajax.reload(null, false);
        }

        function moveModal() {
            const $modal = $(moveModalSelector);

            if ($modal.length === 0) {
                return null;
            }

            return {
                $element: $modal,
                instance: window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance($modal[0]) : null
            };
        }

        function showMoveAlert(message) {
            const modal = moveModal();

            if (!modal) {
                return;
            }

            modal.$element.find('.js-archive-move-alert')
                .toggleClass('d-none', !message)
                .text(message || '');
        }

        function setMoveLoading(loading) {
            const modal = moveModal();

            if (!modal) {
                return;
            }

            modal.$element.find('.js-archive-move-confirm').prop('disabled', loading);
            modal.$element.find('.js-archive-move-destination-folder').prop('disabled', loading);
            modal.$element.find('.js-archive-move-folder-search').prop('disabled', loading);
        }

        function populateMoveFolderOptions(options) {
            const modal = moveModal();

            if (!modal) {
                return;
            }

            const $select = modal.$element.find('.js-archive-move-destination-folder');

            $select.empty();

            (options || []).forEach(function (option) {
                $('<option></option>')
                    .attr('value', option.id || '')
                    .text(option.text || '')
                    .appendTo($select);
            });
        }

        function loadMoveFolderOptions(search) {
            const modal = moveModal();
            const folderOptionsUrl = $table.data('folder-options-url');

            if (!modal || !folderOptionsUrl) {
                return;
            }

            setMoveLoading(true);
            showMoveAlert('');

            $.ajax({
                url: folderOptionsUrl,
                method: 'GET',
                data: { q: search || '' },
                headers: headers()
            }).done(function (response) {
                const data = response && response.data ? response.data : {};

                populateMoveFolderOptions(data.options || []);
            }).fail(function (response) {
                showMoveAlert(responseMessage(response));
            }).always(function () {
                setMoveLoading(false);
            });
        }

        function openMoveModal(items, bulk, label) {
            const modal = moveModal();

            if (!modal) {
                return;
            }

            modal.$element.data('items', items || []);
            modal.$element.data('bulk', Boolean(bulk));
            modal.$element.find('.js-archive-move-item-label').text(label || '');
            modal.$element.find('.js-archive-move-folder-search').val('');
            modal.$element.find('.js-archive-move-destination-error').text('');
            modal.$element.find('.js-archive-move-destination-folder').removeClass('is-invalid');
            showMoveAlert('');
            populateMoveFolderOptions([]);
            loadMoveFolderOptions('');

            if (modal.instance) {
                modal.instance.show();
            } else {
                modal.$element.modal('show');
            }
        }

        function submitMove() {
            const modal = moveModal();

            if (!modal) {
                return;
            }

            const $modal = modal.$element;
            const items = $modal.data('items') || [];
            const bulk = Boolean($modal.data('bulk'));
            const destination = $modal.find('.js-archive-move-destination-folder').val();
            const moveUrl = bulk ? $table.data('bulk-move-url') : $table.data('move-url');

            if (!moveUrl || items.length === 0) {
                showToast('warning', messages.noFilesSelected || '');
                return;
            }

            setMoveLoading(true);
            showMoveAlert('');

            const payload = bulk
                ? { items: items, destination_folder: destination || '' }
                : {
                    item_type: items[0].item_type,
                    item_doc_num: items[0].item_doc_num,
                    destination_folder: destination || ''
                };

            $.ajax({
                url: moveUrl,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                headers: headers()
            }).done(function (response) {
                if (modal.instance) {
                    modal.instance.hide();
                } else {
                    $modal.modal('hide');
                }

                clearSelection(table);
                reloadTable();
                showToast('success', response && response.message ? response.message : '');
            }).fail(function (response) {
                showMoveAlert(responseMessage(response));
                showToast('error', responseMessage(response));
            }).always(function () {
                setMoveLoading(false);
            });
        }

        const table = $table.DataTable(dataTableOptions({
            processing: true,
            serverSide: true,
            stateSave: true,
            stateLoadParams: function (settings, data) {
                protectStateColumns(data);
            },
            stateSaveParams: function (settings, data) {
                protectStateColumns(data);
            },
            ajax: {
                url: $table.data('url'),
                data: function (data) {
                    data.folder_doc_num = $table.data('folder-doc-num') || '';
                    data.global_search = String($table.attr('data-global-search-active') || '0') === '1' ? 1 : 0;
                    data.trash_filter = currentTrashFilter();
                }
            },
            responsive: {
                details: {
                    type: 'inline',
                    target: responsiveControlTarget
                }
            },
            order: [[4, 'asc'], [2, 'asc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: 'doc_num', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'name', name: 'name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis', responsivePriority: 3 },
                { data: 'folder_path', name: 'folder_path', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'item_type', name: 'item_type', className: 'align-middle white-space-nowrap dt-text', responsivePriority: 10 },
                { data: 'module', name: 'module', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'related_record', name: 'related_record', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'usage', name: 'usage', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'picker_visibility', name: 'picker_visibility', className: 'align-middle white-space-nowrap dt-text' },
                { data: 'extension', name: 'extension', className: 'align-middle white-space-nowrap dt-text' },
                { data: 'size', name: 'size', className: 'align-middle white-space-nowrap' },
                { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'deleted_by', name: 'deleted_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'deleted_at', name: 'deleted_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'restored_by', name: 'restored_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'restored_at', name: 'restored_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions', responsivePriority: 4 }
            ],
            columnDefs: [
                { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
                { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
                { className: 'dt-text dt-ellipsis', responsivePriority: 3, targets: 2 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 4, searchable: false, targets: -1 },
                { responsivePriority: 20, targets: [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18] }
            ],
            createdRow: function (row) {
                $(row).addClass('btn-reveal-trigger');
            },
            initComplete: function () {
                showProtectedColumns(this.api());
                restoreSelectionState(this.api());
            },
            drawCallback: function () {
                syncResponsiveControlColumn(this.api());
                restoreSelectionState(this.api());

                if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                    window.AppDataTables.applyFalconEnhancements(document);
                }
            }
        }));

        shieldSelectionEvents();
        patchResponsiveControlTarget(table);
        queueResponsiveControlSync(table);

        const $browser = $table.closest('.js-archive-browser');
        const $globalSearchForm = $browser.find('.js-file-manager-global-search-form').first();
        const $globalSearchInput = $globalSearchForm.find('.js-file-manager-global-search-input').first();
        const $globalSearchClear = $globalSearchForm.find('.js-file-manager-global-search-clear').first();

        $globalSearchForm.off('submit.fileManagerGlobalSearch').on('submit.fileManagerGlobalSearch', function (event) {
            event.preventDefault();

            const value = $.trim($globalSearchInput.val() || '');

            $table.attr('data-global-search-active', '1');
            $globalSearchClear.toggleClass('d-none', value === '');
            table.search(value).draw();
        });

        $globalSearchClear.off('click.fileManagerGlobalSearch').on('click.fileManagerGlobalSearch', function () {
            $globalSearchInput.val('');
            $table.attr('data-global-search-active', '0');
            $globalSearchClear.addClass('d-none');
            table.search('').draw();
        });

        $(trashFilterSelector)
            .off('change.fileManagerTrash' + tableNamespace)
            .on('change.fileManagerTrash' + tableNamespace, function () {
                clearSelection(table);
                updateBulkActionOptions();
                table.ajax.reload(null, false);
            });

        table
            .off('draw.dt.fileManagerResponsive column-visibility.dt.fileManagerResponsive column-sizing.dt.fileManagerResponsive responsive-resize.dt.fileManagerResponsive')
            .on('draw.dt.fileManagerResponsive column-visibility.dt.fileManagerResponsive column-sizing.dt.fileManagerResponsive responsive-resize.dt.fileManagerResponsive', function () {
                queueResponsiveControlSync(table);
            });

        $(selectAllSelector).off('change.fileManagerSelectAll').on('change.fileManagerSelectAll', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(table).each(function () {
                const item = itemFromCheckbox(this);

                if (item.docNum === '') {
                    return;
                }

                if (checked) {
                    selectedItems.set(item.docNum, item);
                } else {
                    selectedItems.delete(item.docNum);
                }

                $(this).prop('checked', checked);
            });

            updateSelectAllState(table);
            updateBulkActionsUi();
        });

        $table.off('click.fileManagerSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.fileManagerSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-file-manager-select').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $(document)
            .off('click.fileManagerSelectStop mousedown.fileManagerSelectStop mouseup.fileManagerSelectStop', '.js-file-manager-select, .js-file-manager-select-all, td.dt-select')
            .on('click.fileManagerSelectStop mousedown.fileManagerSelectStop mouseup.fileManagerSelectStop', '.js-file-manager-select, .js-file-manager-select-all, td.dt-select', function (event) {
                event.stopPropagation();
            });

        $table.off('change.fileManagerSelect', rowCheckboxSelector).on('change.fileManagerSelect', rowCheckboxSelector, function () {
            const item = itemFromCheckbox(this);

            if (item.docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedItems.set(item.docNum, item);
            } else {
                selectedItems.delete(item.docNum);
            }

            updateSelectAllState(table);
            updateBulkActionsUi();
        });

        $table.off('click.fileManagerMoveItem', '.js-archive-move-item').on('click.fileManagerMoveItem', '.js-archive-move-item', function (event) {
            event.preventDefault();

            const $button = $(this);
            const itemType = String($button.data('item-type') || '').trim();
            const itemDocNum = String($button.data('item-doc-num') || '').trim();

            if (itemType === '' || itemDocNum === '') {
                return;
            }

            openMoveModal([{
                item_type: itemType,
                item_doc_num: itemDocNum
            }], false, $button.data('item-name') || itemDocNum);
        });

        $(document)
            .off('click.fileManagerMoveConfirm' + tableNamespace, moveModalSelector + ' .js-archive-move-confirm')
            .on('click.fileManagerMoveConfirm' + tableNamespace, moveModalSelector + ' .js-archive-move-confirm', submitMove)
            .off('input.fileManagerMoveSearch' + tableNamespace, moveModalSelector + ' .js-archive-move-folder-search')
            .on('input.fileManagerMoveSearch' + tableNamespace, moveModalSelector + ' .js-archive-move-folder-search', function () {
                const search = $(this).val() || '';

                window.clearTimeout(moveFolderSearchTimer);
                moveFolderSearchTimer = window.setTimeout(function () {
                    loadMoveFolderOptions(search);
                }, 250);
            });

        $(bulkActionApplySelector).off('click.fileManagerBulk').on('click.fileManagerBulk', function () {
            const action = $(bulkActionSelectSelector).val();

            if (selectedItems.size === 0) {
                updateBulkActionsUi();
                return;
            }

            if (action === 'bulk_move') {
                openMoveModal(
                    selectedMoveItems(),
                    true,
                    (messages.moveSelected || '') + ' (' + selectedItems.size + ')'
                );

                return;
            }

            if (action !== 'bulk_download' && action !== 'bulk_delete' && action !== 'bulk_restore') {
                return;
            }

            if (hasSelectedFolders()) {
                const folderWarning = action === 'bulk_restore'
                    ? (messages.foldersCannotBulkRestore || '')
                    : (action === 'bulk_delete' ? (messages.foldersCannotBulkDelete || '') : (messages.foldersCannotBulkDownload || ''));

                showToast('warning', folderWarning);
                return;
            }

            const files = selectedFiles();
            const fileDocNums = files.map(function (item) { return item.docNum; }).filter(function (docNum) { return docNum !== ''; });

            if (fileDocNums.length === 0) {
                showToast('warning', messages.noFilesSelected || '');
                return;
            }

            if (action === 'bulk_restore') {
                const restoreUrl = $table.data('bulk-restore-url');

                if (!restoreUrl) {
                    return;
                }

                confirmDialog({
                    title: messages.bulkRestoreConfirmTitle || '',
                    text: (messages.bulkRestoreConfirmText || '').replace(':count', fileDocNums.length),
                    confirmButtonText: messages.bulkRestoreConfirmYes || messages.restore || '',
                    confirmButtonColor: '#00d27a'
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    $.ajax({
                        url: restoreUrl,
                        method: 'PATCH',
                        contentType: 'application/json',
                        data: JSON.stringify({ file_doc_nums: fileDocNums }),
                        headers: headers()
                    }).done(function (response) {
                        clearSelection(table);
                        reloadTable();
                        showToast('success', response && response.message ? response.message : '');
                    }).fail(function (response) {
                        showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    });
                });

                return;
            }

            if (action === 'bulk_delete') {
                confirmDialog({
                    title: messages.bulkDeleteConfirmTitle || '',
                    text: (messages.bulkDeleteConfirmText || '').replace(':count', fileDocNums.length),
                    confirmButtonText: messages.bulkDeleteConfirmYes || ''
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    $.ajax({
                        url: $table.data('bulk-delete-url'),
                        method: 'DELETE',
                        contentType: 'application/json',
                        data: JSON.stringify({ file_doc_nums: fileDocNums }),
                        headers: headers()
                    }).done(function (response) {
                        clearSelection(table);
                        reloadTable();
                        showToast('success', response && response.message ? response.message : '');
                    }).fail(function (response) {
                        showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    });
                });

                return;
            }

            const url = $table.data('bulk-download-url');

            if (!url) {
                return;
            }

            const $form = $('<form>', { method: 'POST', action: url })
                .append($('<input>', { type: 'hidden', name: '_token', value: csrfToken }));

            files.forEach(function (item) {
                $form.append($('<input>', { type: 'hidden', name: 'file_doc_nums[]', value: item.docNum }));
            });

            $('body').append($form);
            showToast('success', messages.downloadStarted || '');
            $form.trigger('submit');
            window.setTimeout(function () {
                $form.remove();
            }, 1000);
        });

        fileManagerTables[tableKey] = {
            clearSelection: function () {
                clearSelection(table);
            },
            reload: function () {
                table.ajax.reload(null, false);
            }
        };

        window.AppFileManager = {
            tables: fileManagerTables,
            clearSelection: function (key) {
                const manager = key ? fileManagerTables[key] : fileManagerTables[Object.keys(fileManagerTables)[0]];

                if (manager && typeof manager.clearSelection === 'function') {
                    manager.clearSelection();
                }
            },
            reload: function (key) {
                const manager = key ? fileManagerTables[key] : fileManagerTables[Object.keys(fileManagerTables)[0]];

                if (manager && typeof manager.reload === 'function') {
                    manager.reload();
                }
            },
            reloadAll: function () {
                Object.keys(fileManagerTables).forEach(function (key) {
                    fileManagerTables[key].reload();
                });
            }
        };
    }

    function initPickerVisibilityHandlers() {
        $(document)
            .off('click.fileManagerPickerVisibility', '.js-archive-picker-visibility-toggle')
            .on('click.fileManagerPickerVisibility', '.js-archive-picker-visibility-toggle', function () {
                const $button = $(this);
                const url = $button.data('picker-visibility-url') || '';

                if (!url || $button.prop('disabled')) {
                    return;
                }

                setLoading($button, true);

                $.ajax({
                    url: url,
                    method: 'PATCH',
                    data: {
                        hidden_from_picker: String($button.data('hidden-from-picker')) === '1' ? 1 : 0
                    },
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response && response.message ? response.message : '');

                    if (window.AppFileManager && typeof window.AppFileManager.reloadAll === 'function') {
                        window.AppFileManager.reloadAll();
                    }
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    setLoading($button, false);
                });
            });
    }

    function initRestoreHandlers() {
        $(document)
            .off('click.fileManagerRestore', '.js-archive-restore[data-archive-restore-url]')
            .on('click.fileManagerRestore', '.js-archive-restore[data-archive-restore-url]', function () {
                const $button = $(this);
                const url = $button.data('archive-restore-url') || '';

                if (!url || $button.prop('disabled')) {
                    return;
                }

                confirmDialog({
                    title: messages.restoreConfirmTitle || '',
                    text: messages.restoreConfirmText || '',
                    confirmButtonText: messages.restoreConfirmYes || messages.restore || '',
                    confirmButtonColor: '#00d27a'
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    setLoading($button, true);

                    $.ajax({
                        url: url,
                        method: 'PATCH',
                        headers: headers()
                    }).done(function (response) {
                        showToast('success', response && response.message ? response.message : '');

                        if (window.AppFileManager && typeof window.AppFileManager.clearSelection === 'function') {
                            window.AppFileManager.clearSelection();
                        }

                        if (window.AppFileManager && typeof window.AppFileManager.reloadAll === 'function') {
                            window.AppFileManager.reloadAll();
                        }
                    }).fail(function (response) {
                        showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    }).always(function () {
                        setLoading($button, false);
                    });
                });
            });
    }

    $(function () {
        initDocumentNumberSettings();
        initPublicLinkHandlers();
        initPickerVisibilityHandlers();
        initRestoreHandlers();
        initFileManagerTable();
        if (window.AppArchiveFiles && typeof window.AppArchiveFiles.init === 'function') {
            window.AppArchiveFiles.init(document);
        }
    });
})(jQuery, window, document);
