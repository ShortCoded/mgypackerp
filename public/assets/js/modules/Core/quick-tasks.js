(function ($, window, document) {
    'use strict';

    var messages = window.coreQuickTasksMessages || {};
    var tableSelector = '#quick-tasks-table';
    var formSelector = '#quick-task-form';
    var boardSelector = '#quick-task-board';
    var protectedColumns = [0, 1, -1];
    var deletedAuditColumnIndexes = [13, 14];
    var selected = new Set();
    var boardLoading = false;
    var boardTimer = null;

    function csrfToken() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json'
        };
    }

    function message(key) {
        return messages[key] || '';
    }

    function responseMessage(xhr) {
        return xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : message('unexpectedError');
    }

    function showAlert($container, text, type) {
        if (!$container.length || !text) {
            return;
        }

        $container.html('<div class="alert alert-' + (type || 'danger') + ' mb-3">' + $('<div>').text(text).html() + '</div>');
    }

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
            return;
        }

        if (window.Swal && title) {
            Swal.fire({
                icon: icon,
                text: title,
                toast: true,
                position: 'top-end',
                timer: 2500,
                showConfirmButton: false,
                heightAuto: false
            });
        }
    }

    function showInfo(text) {
        if (window.Swal && text) {
            Swal.fire({
                icon: 'info',
                text: text,
                confirmButtonText: message('confirm'),
                showCloseButton: true,
                allowEscapeKey: true,
                heightAuto: false
            });
            return;
        }

        showToast('info', text);
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
            cancelButtonText: options.cancelButtonText || message('no') || message('cancel'),
            confirmButtonColor: '#d33',
            cancelButtonColor: '#748194',
            heightAuto: false
        });
    }

    function clearValidation($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.select2-selection.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('[data-form-alert]').empty();
    }

    function normalizeField(field) {
        var normalized = String(field || '').replace('[]', '');

        if (normalized.indexOf('attachments.') === 0) {
            return 'attachments';
        }

        return normalized.replace(/\.\d+$/, '');
    }

    function renderValidation($form, errors) {
        $.each(errors || {}, function (field, fieldMessages) {
            var normalizedField = normalizeField(field);
            var $field = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $field.addClass('is-invalid');

            if ($field.hasClass('select2-hidden-accessible')) {
                $field.next('.select2-container').find('.select2-selection').addClass('is-invalid');
            }

            $form.find('[data-error-for="' + normalizedField + '"]').first().text((fieldMessages || [])[0] || '');
        });
    }

    function reloadTable() {
        if ($.fn.DataTable && $.fn.DataTable.isDataTable(tableSelector)) {
            $(tableSelector).DataTable().ajax.reload(null, false);
        }
    }

    function selectedDocNums() {
        return Array.from(selected).filter(function (docNum) {
            return String(docNum || '').trim() !== '';
        });
    }

    function updateBulkBar() {
        var count = selected.size;

        syncBulkActionOptions();

        $('#bulk_actions_bar').toggleClass('d-none', count === 0).toggleClass('d-flex', count > 0);
        $('#bulk_selected_count').text(count);
        $('#bulk_action_apply')
            .prop('disabled', count === 0 || $('#bulk_action_select option:not(:disabled)').length === 0)
            .find('span:last')
            .text(($('#bulk_action_apply').data('label') || '') + (count > 0 ? ' (' + count + ')' : ''));
    }

    function clearSelection() {
        selected.clear();
        $(tableSelector).find('.js-record-checkbox, .js-record-select-all').prop('checked', false);
        updateBulkBar();
    }

    function collectFilters(data) {
        data.trash_filter = recordFilterValue();
        data.status_filter = $('#quick_tasks_status_filter').val() || '';
        data.priority_filter = $('#quick_tasks_priority_filter').val() || '';
        data.assigned_user_doc_num = $('#quick_tasks_assigned_filter').val() || '';
        data.created_from = $('#quick_tasks_created_from').val() || '';
        data.created_to = $('#quick_tasks_created_to').val() || '';
    }

    function recordFilterValue() {
        var value = String($('#quick_tasks_record_filter').val() || 'active');

        return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
    }

    function optionVisibleForFilter($option, filter) {
        var filters = String($option.data('visible-filters') || '').split(/\s+/).filter(Boolean);

        return filters.length === 0 || filters.indexOf(filter) !== -1;
    }

    function syncBulkActionOptions() {
        var filter = recordFilterValue();
        var $select = $('#bulk_action_select');
        var firstVisible = null;

        $select.find('option').each(function () {
            var $option = $(this);
            var visible = optionVisibleForFilter($option, filter);

            $option.prop('disabled', !visible).prop('hidden', !visible);

            if (visible && firstVisible === null) {
                firstVisible = $option.val();
            }
        });

        if ($select.find('option:selected').prop('disabled') && firstVisible !== null) {
            $select.val(firstVisible);
        }
    }

    function syncDeletedAuditColumns(api) {
        if (!api || typeof api.column !== 'function') {
            return;
        }

        var showDeletedAudit = ['trashed', 'all'].indexOf(recordFilterValue()) !== -1;

        $.each(deletedAuditColumnIndexes, function (index, columnIndex) {
            api.column(columnIndex).visible(showDeletedAudit, false);
        });

        api.columns.adjust();

        if (api.responsive && typeof api.responsive.recalc === 'function') {
            api.responsive.recalc();
        }
    }

    function bulkUrl(action) {
        var $table = $(tableSelector);
        var urls = {
            delete: $table.data('bulk-delete-url'),
            restore: $table.data('bulk-restore-url')
        };

        return urls[action] || '';
    }

    function bulkConfirm(action, count) {
        return {
            title: message(action === 'restore' ? 'bulkRestoreConfirmTitle' : 'bulkDeleteConfirmTitle'),
            text: String(message(action === 'restore' ? 'bulkRestoreConfirmText' : 'bulkDeleteConfirmText') || '').replace(':count', count),
            confirmButtonText: message(action === 'restore' ? 'bulkRestoreConfirmYes' : 'bulkDeleteConfirmYes'),
            confirmButtonColor: action === 'restore' ? '#00a65a' : '#d33'
        };
    }

    function initTable() {
        var $table = $(tableSelector);

        if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        var options = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options({
                ajax: {
                    url: $table.data('ajax-url'),
                    data: collectFilters
                },
                processing: true,
                serverSide: true,
                stateSave: true,
                stateLoadParams: function (settings, data) {
                    if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
                        window.AppDataTables.protectStateColumns(data, protectedColumns);
                    }
                },
                stateSaveParams: function (settings, data) {
                    if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
                        window.AppDataTables.protectStateColumns(data, protectedColumns);
                    }
                },
                autoWidth: false,
                responsive: { details: { type: 'inline', target: 1 } },
                order: [[12, 'desc']],
                columns: [
                    { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                    { data: 'doc_num', name: 'quick_tasks.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                    { data: 'title', name: 'quick_tasks.title', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 10 },
                    { data: 'summary', name: 'quick_tasks.summary', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 30 },
                    { data: 'task_board', name: 'task_board', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 35 },
                    { data: 'status', name: 'quick_tasks.status', className: 'align-middle white-space-nowrap dt-status', responsivePriority: 20 },
                    { data: 'priority', name: 'quick_tasks.priority', className: 'align-middle white-space-nowrap dt-status', responsivePriority: 20 },
                    { data: 'assigned_to', name: 'assigned_to', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 35 },
                    { data: 'attachments_count', name: 'attachments_count', orderable: false, searchable: false, className: 'align-middle white-space-nowrap text-center', responsivePriority: 35 },
                    { data: 'created_by', name: 'created_by', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 40 },
                    { data: 'created_at', name: 'quick_tasks.created_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 40 },
                    { data: 'updated_by', name: 'updated_by', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 45 },
                    { data: 'updated_at', name: 'quick_tasks.updated_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 45 },
                    { data: 'deleted_by', name: 'deleted_by', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 60 },
                    { data: 'deleted_at', name: 'quick_tasks.deleted_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 60 },
                    { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'dt-actions no-colvis all align-middle white-space-nowrap', responsivePriority: 3 }
                ],
                createdRow: function (row) {
                    $(row).addClass('btn-reveal-trigger');
                },
                initComplete: function () {
                    if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                        window.AppDataTables.showColumns(this.api(), protectedColumns);
                    }
                    syncDeletedAuditColumns(this.api());
                    restoreSelectionState();
                },
                drawCallback: function () {
                    syncDeletedAuditColumns(this.api());
                    if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                        window.AppDataTables.applyFalconEnhancements(document);
                    }
                    restoreSelectionState();
                }
            })
            : {};

        $table.DataTable(options);
    }

    function restoreSelectionState() {
        $(tableSelector).find('.js-record-checkbox').each(function () {
            this.checked = selected.has(String($(this).data('doc-num') || this.value || '').trim());
        });

        var $checkboxes = $(tableSelector).find('.js-record-checkbox');
        var checkedCount = $checkboxes.filter(':checked').length;

        $('#select_all_records')
            .prop('checked', $checkboxes.length > 0 && checkedCount === $checkboxes.length)
            .prop('indeterminate', checkedCount > 0 && checkedCount < $checkboxes.length);
    }

    function updateUrlsAfterSave($form, response) {
        var data = response && response.data ? response.data : {};

        if (data.urls && data.urls.update) {
            $form.attr('action', data.urls.update);
        }

        if (data.urls && data.urls.edit && window.history) {
            window.history.replaceState({}, '', data.urls.edit);
        }
    }

    function resetCreateForm($form) {
        if ($form[0]) {
            $form[0].reset();
        }

        $form.find('[name="submit_action"]').val('save');
        $form.find('.js-select2-ajax').val(null).trigger('change.select2');
        $form.find('[type="file"]').val('');
        $form.find('.js-quick-task-rich-editor').each(function () {
            var $editor = $(this);

            if ($editor.data('summernote')) {
                $editor.summernote('code', '');
            }

            $editor.val('');
        });
        clearSelectedAttachments();
    }

    function statusConfirmation(status) {
        if (status === 'done') {
            return {
                title: message('statusDoneConfirmTitle'),
                text: message('statusDoneConfirmText'),
                confirmButtonText: message('statusDoneConfirmYes')
            };
        }

        if (status === 'cancelled') {
            return {
                title: message('statusCancelConfirmTitle'),
                text: message('statusCancelConfirmText'),
                confirmButtonText: message('statusCancelConfirmYes')
            };
        }

        return null;
    }

    function updateStatus(url, status) {
        return $.ajax({
            url: url,
            type: 'PATCH',
            headers: headers(),
            data: { status: status }
        });
    }

    function requestStatusChange(url, status) {
        var confirmation = statusConfirmation(status);
        var deferred = $.Deferred();

        if (!confirmation) {
            updateStatus(url, status).done(deferred.resolve).fail(deferred.reject);

            return deferred.promise();
        }

        confirmDialog(confirmation).then(function (result) {
            if (!result.isConfirmed) {
                deferred.reject({ cancelled: true });
                return;
            }

            updateStatus(url, status).done(deferred.resolve).fail(deferred.reject);
        });

        return deferred.promise();
    }

    function refreshBoard(force) {
        var $board = $(boardSelector);
        var url = $board.data('url');

        if (!$board.length || !url || boardLoading) {
            return;
        }

        boardLoading = true;
        $board.addClass('is-loading');

        $.ajax({
            url: url,
            type: 'GET',
            headers: { Accept: 'application/json' }
        }).done(function (response) {
            if (response && response.html) {
                $board.html(response.html);
            }

            if (response && response.last_updated_at) {
                $('#quick-task-board-last-updated').text(response.last_updated_at);
            }

            $board.data('had-error', false);
        }).fail(function () {
            if (force || !$board.data('had-error')) {
                showToast('error', message('boardRefreshFailed'));
            }

            $board.data('had-error', true);
        }).always(function () {
            boardLoading = false;
            $board.removeClass('is-loading');
        });
    }

    function initBoard() {
        var $board = $(boardSelector);

        if (!$board.length) {
            return;
        }

        refreshBoard(true);

        if (boardTimer) {
            window.clearInterval(boardTimer);
        }

        boardTimer = window.setInterval(function () {
            refreshBoard(false);
        }, Number.parseInt($board.data('interval'), 10) || 10000);
    }

    function setDisplayMode(active) {
        $('body').toggleClass('quick-task-display-mode', active);
        $('.js-quick-task-display-mode [data-display-mode-label]').text(active ? message('exitDisplayMode') : message('displayMode'));
    }

    function syncRichEditors($context) {
        $context.find('.js-quick-task-rich-editor').each(function () {
            var $editor = $(this);

            if ($editor.data('summernote')) {
                $editor.val($editor.summernote('code'));
            }
        });
    }

    function summernoteIcons() {
        return {
            bold: 'fas fa-bold',
            italic: 'fas fa-italic',
            underline: 'fas fa-underline',
            eraser: 'fas fa-eraser',
            unorderedlist: 'fas fa-list-ul',
            orderedlist: 'fas fa-list-ol',
            alignLeft: 'fas fa-align-left',
            alignCenter: 'fas fa-align-center',
            alignRight: 'fas fa-align-right',
            alignJustify: 'fas fa-align-justify',
            outdent: 'fas fa-outdent',
            indent: 'fas fa-indent',
            link: 'fas fa-link',
            code: 'fas fa-code',
            caret: 'fas fa-caret-down',
            question: 'fas fa-question',
            close: 'fas fa-times',
            undo: 'fas fa-undo',
            redo: 'fas fa-redo'
        };
    }

    function initRichEditors() {
        if (!$.fn.summernote) {
            return;
        }

        $('.js-quick-task-rich-editor').each(function () {
            var $editor = $(this);

            if ($editor.data('summernote')) {
                return;
            }

            $editor.summernote({
                height: 220,
                direction: $editor.data('direction') || 'ltr',
                dialogsInBody: true,
                tooltip: false,
                icons: summernoteIcons(),
                toolbar: [
                    ['style', ['bold', 'italic', 'underline', 'clear']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['link']],
                    ['view', ['codeview']]
                ],
                callbacks: {
                    onChange: function (contents) {
                        $editor.val(contents).trigger('change');
                    }
                }
            });
        });
    }

    function selectedAttachmentDocNums() {
        return new Set($('#quick_task_attachment_file_inputs input[name="attachment_file_doc_nums[]"]').map(function () {
            return String($(this).val() || '').trim();
        }).get().filter(Boolean));
    }

    function formatBytes(bytes, fallback) {
        var size = Number(bytes || 0);

        if (fallback) {
            return fallback;
        }

        if (size < 1024) {
            return size + ' B';
        }

        if (size < 1048576) {
            return (size / 1024).toFixed(1) + ' KB';
        }

        return (size / 1048576).toFixed(1) + ' MB';
    }

    function updateSelectedAttachmentPanel() {
        var hasRows = $('#quick-task-selected-attachments tr').length > 0;

        $('#quick-task-selected-attachments-wrap').toggleClass('d-none', !hasRows);
    }

    function addSelectedAttachment(file) {
        var item = file || {};
        var publicId = String(item.public_id || '').trim();
        var fileName = String(item.name || item.original_name || publicId).trim();
        var sizeLabel = formatBytes(item.size, item.size_label);
        var mimeType = String(item.mime_type || item.extension || '').trim();
        var selectedDocNums = selectedAttachmentDocNums();

        if (publicId === '') {
            return;
        }

        if (selectedDocNums.has(publicId)) {
            showToast('info', message('attachmentDuplicate'));
            return;
        }

        $('<input>', {
            type: 'hidden',
            name: 'attachment_file_doc_nums[]',
            value: publicId,
            'data-public-id': publicId
        }).appendTo('#quick_task_attachment_file_inputs');

        $('<tr></tr>')
            .attr('data-public-id', publicId)
            .append(
                $('<td class="min-w-0"></td>').append(
                    $('<div class="fw-semibold text-truncate"></div>')
                        .attr('title', fileName)
                        .append($('<span class="fas fa-paperclip text-500 me-1"></span>'))
                        .append(document.createTextNode(fileName)),
                    $('<div class="text-600 fs-11"></div>').text(mimeType)
                ),
                $('<td class="white-space-nowrap" dir="ltr"></td>').text(sizeLabel),
                $('<td class="text-end white-space-nowrap"></td>').append(
                    $('<button type="button" class="btn btn-falcon-danger btn-sm js-remove-selected-quick-task-attachment"></button>')
                        .attr('data-public-id', publicId)
                        .append($('<span class="fas fa-times me-1"></span>'))
                        .append($('<span></span>').text(message('removeAttachment')))
                )
            )
            .appendTo('#quick-task-selected-attachments');

        updateSelectedAttachmentPanel();
        $('[data-error-for="attachment_file_doc_nums"]').text('');
    }

    function clearSelectedAttachments() {
        $('#quick_task_attachment_file_inputs').empty();
        $('#quick-task-selected-attachments').empty();
        updateSelectedAttachmentPanel();
    }

    $(document)
        .off('change.coreQuickTasks', tableSelector + ' .js-record-checkbox')
        .on('change.coreQuickTasks', tableSelector + ' .js-record-checkbox', function () {
            var docNum = String($(this).data('doc-num') || this.value || '').trim();

            if (docNum === '') {
                return;
            }

            if (this.checked) {
                selected.add(docNum);
            } else {
                selected.delete(docNum);
            }

            restoreSelectionState();
            updateBulkBar();
        })
        .off('change.coreQuickTasks', '#select_all_records')
        .on('change.coreQuickTasks', '#select_all_records', function () {
            var checked = this.checked;

            $(tableSelector).find('.js-record-checkbox').each(function () {
                var docNum = String($(this).data('doc-num') || this.value || '').trim();
                this.checked = checked;

                if (checked && docNum !== '') {
                    selected.add(docNum);
                } else {
                    selected.delete(docNum);
                }
            });

            updateBulkBar();
        })
        .off('click.coreQuickTasksSelectCell', tableSelector + ' tbody tr:not(.child) td.dt-select')
        .on('click.coreQuickTasksSelectCell', tableSelector + ' tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            var $checkbox = $(this).find('input.js-record-select, input.js-record-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if (!$checkbox.length || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        })
        .off('click.coreQuickTasksSelectStop mousedown.coreQuickTasksSelectStop mouseup.coreQuickTasksSelectStop', '.js-record-select, #select_all_records, td.dt-select')
        .on('click.coreQuickTasksSelectStop mousedown.coreQuickTasksSelectStop mouseup.coreQuickTasksSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
            event.stopPropagation();
        })
        .off('change.coreQuickTasks select2:select.coreQuickTasks select2:clear.coreQuickTasks', '.js-quick-task-filter')
        .on('change.coreQuickTasks select2:select.coreQuickTasks select2:clear.coreQuickTasks', '.js-quick-task-filter', function () {
            clearSelection();
            if ($.fn.DataTable && $.fn.DataTable.isDataTable(tableSelector)) {
                syncDeletedAuditColumns($(tableSelector).DataTable());
            }
            reloadTable();
        })
        .off('click.coreQuickTasks', '.js-quick-task-reset-filters')
        .on('click.coreQuickTasks', '.js-quick-task-reset-filters', function () {
            $('#quick_tasks_record_filter').val('active');
            $('#quick_tasks_status_filter, #quick_tasks_priority_filter, #quick_tasks_created_from, #quick_tasks_created_to').val('');
            $('#quick_tasks_assigned_filter').val(null).trigger('change.select2');
            clearSelection();
            reloadTable();
        })
        .off('click.coreQuickTasks', '.js-delete-record')
        .on('click.coreQuickTasks', '.js-delete-record', function () {
            var $button = $(this);
            var url = $button.data('url');
            var docNum = String($button.data('doc-num') || '').trim();
            var redirectUrl = $button.data('redirect-url');

            confirmDialog({
                title: message('deleteConfirmTitle'),
                text: message('deleteConfirmText'),
                confirmButtonText: message('deleteConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: url,
                    type: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    if (docNum !== '') {
                        selected.delete(docNum);
                    }

                    if (redirectUrl) {
                        window.location.href = redirectUrl;
                        return;
                    }

                    updateBulkBar();
                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('deleted'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.coreQuickTasks', '.js-restore-record')
        .on('click.coreQuickTasks', '.js-restore-record', function () {
            var $button = $(this);

            confirmDialog({
                title: message('restoreConfirmTitle'),
                text: message('restoreConfirmText'),
                confirmButtonText: message('restoreConfirmYes'),
                confirmButtonColor: '#00a65a'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: $button.data('url'),
                    type: 'PATCH',
                    headers: headers()
                }).done(function (response) {
                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('saved'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.coreQuickTasks', '#bulk_action_apply')
        .on('click.coreQuickTasks', '#bulk_action_apply', function () {
            var $button = $(this);
            var docNums = selectedDocNums();
            var action = String($('#bulk_action_select').val() || '');
            var url = bulkUrl(action);

            if (docNums.length === 0) {
                showToast('info', message('noRecordsSelected'));
                return;
            }

            if (!url) {
                showToast('error', message('unexpectedError'));
                return;
            }

            confirmDialog(bulkConfirm(action, docNums.length)).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $button.prop('disabled', true);

                $.ajax({
                    url: url,
                    type: action === 'restore' ? 'PATCH' : 'DELETE',
                    headers: headers(),
                    data: { doc_nums: docNums }
                }).done(function (response) {
                    clearSelection();
                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('saved'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                }).always(function () {
                    $button.prop('disabled', false);
                    updateBulkBar();
                });
            });
        })
        .off('click.coreQuickTasks', '.js-quick-task-status-action')
        .on('click.coreQuickTasks', '.js-quick-task-status-action', function () {
            var $button = $(this);
            var status = String($button.data('status') || '').trim();

            $button.prop('disabled', true);

            requestStatusChange($button.data('url'), status).done(function (response) {
                reloadTable();
                showToast('success', response && response.message ? response.message : message('saved'));
            }).fail(function (xhr) {
                if (!xhr || !xhr.cancelled) {
                    showToast('error', responseMessage(xhr));
                }
            }).always(function () {
                $button.prop('disabled', false);
            });
        })
        .off('click.coreQuickTasks', '.js-delete-quick-task-attachment')
        .on('click.coreQuickTasks', '.js-delete-quick-task-attachment', function () {
            var $button = $(this);
            var $row = $button.closest('tr');

            confirmDialog({
                title: message('attachmentDeleteConfirmTitle'),
                text: message('attachmentDeleteConfirmText'),
                confirmButtonText: message('attachmentDeleteConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: $button.data('url'),
                    type: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    $row.remove();
                    showToast('success', response && response.message ? response.message : message('saved'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.coreQuickTasks', '.js-quick-task-board-refresh')
        .on('click.coreQuickTasks', '.js-quick-task-board-refresh', function () {
            refreshBoard(true);
        })
        .off('click.coreQuickTasks', '.js-quick-task-display-mode')
        .on('click.coreQuickTasks', '.js-quick-task-display-mode', function () {
            setDisplayMode(!$('body').hasClass('quick-task-display-mode'));
        })
        .off('click.coreQuickTasks', '.js-board-status-action')
        .on('click.coreQuickTasks', '.js-board-status-action', function () {
            var $button = $(this);

            $button.prop('disabled', true);

            requestStatusChange($button.data('url'), String($button.data('status') || '')).done(function (response) {
                showToast('success', response && response.message ? response.message : message('saved'));
                refreshBoard(true);
            }).fail(function (xhr) {
                if (xhr && xhr.cancelled) {
                    return;
                }

                showToast('error', responseMessage(xhr));
            }).always(function () {
                $button.prop('disabled', false);
            });
        })
        .off('dblclick.coreQuickTasks', tableSelector + ' tbody tr')
        .on('dblclick.coreQuickTasks', tableSelector + ' tbody tr', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle').length > 0) {
                return;
            }

            var editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        })
        .off('file-picker:selected.coreQuickTaskAttachmentPicker', '.js-quick-task-attachment-picker-trigger')
        .on('file-picker:selected.coreQuickTaskAttachmentPicker', '.js-quick-task-attachment-picker-trigger', function (event, payload) {
            var data = payload || {};
            var file = data.file || data;
            var config = data.config || {};

            if (config.collection !== 'quick_task_attachments') {
                return;
            }

            addSelectedAttachment(file);
        })
        .off('click.coreQuickTasks', '.js-remove-selected-quick-task-attachment')
        .on('click.coreQuickTasks', '.js-remove-selected-quick-task-attachment', function () {
            var publicId = String($(this).data('public-id') || '').trim();

            if (publicId === '') {
                return;
            }

            $('#quick_task_attachment_file_inputs input[data-public-id="' + publicId + '"]').remove();
            $('#quick-task-selected-attachments tr[data-public-id="' + publicId + '"]').remove();
            updateSelectedAttachmentPanel();
        });

    $(document)
        .off('submit.coreQuickTasks', formSelector)
        .on('submit.coreQuickTasks', formSelector, function (event) {
            event.preventDefault();

            var $form = $(this);

            syncRichEditors($form);

            var formData = new FormData(this);

            clearValidation($form);

            $.ajax({
                url: $form.attr('action'),
                type: $form.attr('method') || 'POST',
                headers: headers(),
                data: formData,
                processData: false,
                contentType: false
            }).done(function (response) {
                if (response && response.success === false && response.type === 'no_changes') {
                    showAlert($form.find('[data-form-alert]'), response.message || message('noChanges'), 'warning');
                    showInfo(response.message || message('noChanges'));
                    return;
                }

                if (!response || !response.reset_form) {
                    updateUrlsAfterSave($form, response);
                }
                showToast('success', response && response.message ? response.message : message('saved'));

                if (response && response.redirect) {
                    window.location.href = response.redirect;
                    return;
                }

                if (response && response.reset_form) {
                    resetCreateForm($form);
                }

                if (response && response.message) {
                    showAlert($form.find('[data-form-alert]'), response.message, 'success');
                }
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidation($form, xhr.responseJSON.errors);
                    showAlert($form.find('[data-form-alert]'), message('validationFailed'), 'danger');
                    return;
                }

                showAlert($form.find('[data-form-alert]'), responseMessage(xhr), 'danger');
            });
        })
        .off('click.coreQuickTasks', formSelector + ' [data-submit-action]')
        .on('click.coreQuickTasks', formSelector + ' [data-submit-action]', function () {
            $(formSelector).find('[name="submit_action"]').val($(this).data('submit-action'));
        })
        .off('input.coreQuickTasks change.coreQuickTasks select2:select.coreQuickTasks select2:clear.coreQuickTasks', formSelector + ' .is-invalid, ' + formSelector + ' .select2-hidden-accessible')
        .on('input.coreQuickTasks change.coreQuickTasks select2:select.coreQuickTasks select2:clear.coreQuickTasks', formSelector + ' .is-invalid, ' + formSelector + ' .select2-hidden-accessible', function () {
            var $field = $(this);
            var name = normalizeField($field.attr('name'));

            $field.removeClass('is-invalid');
            $field.next('.select2-container').find('.select2-selection').removeClass('is-invalid');
            $(formSelector).find('[data-error-for="' + name + '"]').text('');
        });

    initTable();
    initBoard();
    initRichEditors();
    updateSelectedAttachmentPanel();
})(jQuery, window, document);
