(function ($, window, document) {
    'use strict';

    const config = window.MyBoardTableConfig || {};
    const messages = $.extend(true, {}, config.messages || {}, window.myBoardTableMessages || {});
    const taskType = 'task';
    const noteType = 'note';
    const tables = {};
    const selectedDocNums = {
        task: new Set(),
        note: new Set()
    };
    let activeType = taskType;

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

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
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
            cancelButtonText: message('no') || '',
            confirmButtonColor: options.confirmButtonColor || '#d33',
            cancelButtonColor: '#748194',
            heightAuto: false
        });
    }

    function updateCreateLinks() {
        const $link = $('.js-my-board-table-create-link').first();

        if (!$link.length) {
            return;
        }

        const type = activeType || taskType;
        const baseUrl = type === noteType
            ? ($link.data('note-url') || config.urls?.[noteType]?.create)
            : ($link.data('task-url') || config.urls?.[taskType]?.create);
        const label = type === noteType
            ? ($link.data('note-label') || config.text?.createNoteTitle)
            : ($link.data('task-label') || config.text?.createTaskTitle);
        const icon = type === noteType
            ? ($link.data('note-icon') || 'fas fa-sticky-note')
            : ($link.data('task-icon') || 'fas fa-plus');

        if (baseUrl) {
            $link.attr('href', baseUrl);
        }

        $link.attr('data-active-type', type);
        $link.find('span:first').attr('class', icon).attr('data-fa-transform', 'shrink-3 down-2');
        $link.find('span:last').removeClass('d-none').text(label || '');
    }

    function recordFilterValue() {
        const value = String($('#my_board_table_record_filter').val() || 'active');

        return ['active', 'inactive', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
    }

    function optionVisibleForFilter($option, filter) {
        const filters = String($option.data('visible-filters') || '').split(/\s+/).filter(Boolean);

        return filters.length === 0 || filters.indexOf(filter) !== -1;
    }

    function syncBulkActionOptions(type) {
        const filter = recordFilterValue();
        const $select = activeBulkBar(type).find('.js-my-board-table-bulk-action');
        let firstVisible = null;

        $select.find('option').each(function () {
            const $option = $(this);
            const visible = optionVisibleForFilter($option, filter);

            $option.prop('disabled', !visible).prop('hidden', !visible);

            if (visible && firstVisible === null) {
                firstVisible = $option.val();
            }
        });

        if ($select.find('option:selected').prop('disabled') && firstVisible !== null) {
            $select.val(firstVisible);
        }
    }

    function filtersFor(type) {
        return {
            record_filter: recordFilterValue()
        };
    }

    function tableFor(type) {
        return type === noteType ? tables[noteType] : tables[taskType];
    }

    function pageCheckboxes(api, type) {
        if (api && typeof api.rows === 'function') {
            return $(api.rows({ page: 'current' }).nodes()).find('input.js-my-board-table-row-checkbox, input.js-record-select');
        }

        return $('.js-my-board-table[data-type="' + type + '"]').find('input.js-my-board-table-row-checkbox, input.js-record-select');
    }

    function checkboxDocNum(checkbox) {
        return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
    }

    function selectedSet(type) {
        return selectedDocNums[type] || selectedDocNums[taskType];
    }

    function activeBulkBar(type) {
        return $('.js-my-board-table-bulk-actions-bar[data-type="' + type + '"]');
    }

    function updateBulkActionsUi(type) {
        const selected = selectedSet(type);
        const selectedCount = selected.size;
        const $bar = activeBulkBar(type);
        const $applyButton = $bar.find('.js-my-board-table-bulk-apply');
        const applyLabel = $applyButton.data('label') || '';

        syncBulkActionOptions(type);

        $bar
            .toggleClass('d-none', selectedCount === 0 || type !== activeType)
            .toggleClass('d-flex', selectedCount > 0 && type === activeType);

        $bar.find('.js-my-board-table-selected-count').text(selectedCount);

        $applyButton
            .prop('disabled', selectedCount === 0 || $bar.find('.js-my-board-table-bulk-action option:not(:disabled)').length === 0)
            .find('span:last')
            .text(applyLabel + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));

        if (selectedCount === 0) {
            const $select = $bar.find('.js-my-board-table-bulk-action');
            const firstAvailable = $select.find('option:not(:disabled)').first().val();

            if (firstAvailable) {
                $select.val(firstAvailable);
            }
        }
    }

    function updateAllBulkActionsUi() {
        updateBulkActionsUi(taskType);
        updateBulkActionsUi(noteType);
    }

    function updateSelectAllState(api, type) {
        const selected = selectedSet(type);
        const $checkboxes = pageCheckboxes(api, type);
        const selectableDocNums = $checkboxes.map(function () {
            return checkboxDocNum(this);
        }).get().filter(function (docNum) {
            return docNum !== '';
        });
        const checkedOnPage = selectableDocNums.filter(function (docNum) {
            return selected.has(docNum);
        }).length;

        $('.js-my-board-table-select-all[data-type="' + type + '"]')
            .prop('checked', selectableDocNums.length > 0 && checkedOnPage === selectableDocNums.length)
            .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < selectableDocNums.length);
    }

    function restoreSelectionState(api, type) {
        const selected = selectedSet(type);

        pageCheckboxes(api, type).each(function () {
            const docNum = checkboxDocNum(this);

            $(this).prop('checked', docNum !== '' && selected.has(docNum));
        });

        updateSelectAllState(api, type);
        updateBulkActionsUi(type);
    }

    function clearSelection(type) {
        const table = tableFor(type);

        selectedSet(type).clear();
        pageCheckboxes(table, type).prop('checked', false);
        updateSelectAllState(table, type);
        updateBulkActionsUi(type);
    }

    function reloadTable(type) {
        const table = tableFor(type);

        if (table) {
            table.ajax.reload(null, false);
        }
    }

    function reloadTables() {
        reloadTable(taskType);
        reloadTable(noteType);
    }

    function bulkUrl($table, action) {
        if (action === 'restore') {
            return $table.data('bulk-restore-url') || config.urls?.bulkRestore;
        }

        if (action === 'activate' || action === 'deactivate') {
            return $table.data('bulk-active-state-url') || config.urls?.bulkActiveState;
        }

        return $table.data('bulk-delete-url') || config.urls?.bulkDelete;
    }

    function bulkConfirm(action, count) {
        const titleKey = {
            delete: 'bulkDeleteConfirmTitle',
            activate: 'bulkActivateConfirmTitle',
            deactivate: 'bulkDeactivateConfirmTitle',
            restore: 'bulkRestoreConfirmTitle'
        }[action] || 'bulkDeleteConfirmTitle';
        const textKey = {
            delete: 'bulkDeleteConfirmText',
            activate: 'bulkActivateConfirmText',
            deactivate: 'bulkDeactivateConfirmText',
            restore: 'bulkRestoreConfirmText'
        }[action] || 'bulkDeleteConfirmText';
        const yesKey = {
            delete: 'bulkDeleteConfirmYes',
            activate: 'bulkActivateConfirmYes',
            deactivate: 'bulkDeactivateConfirmYes',
            restore: 'bulkRestoreConfirmYes'
        }[action] || 'bulkDeleteConfirmYes';

        return {
            title: message(titleKey),
            text: String(message(textKey) || '').replace(':count', count),
            confirmButtonText: message(yesKey),
            confirmButtonColor: action === 'activate' || action === 'restore' ? '#00a65a' : '#d33'
        };
    }

    function showActiveFilterPanel(type) {
        activeType = type || taskType;
        updateCreateLinks();
        updateAllBulkActionsUi();
    }

    function initTable($table) {
        if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return tableFor($table.data('type') || taskType);
        }

        const type = $table.data('type') || taskType;
        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) {
                return options;
            };
        const protectedColumns = [0, 1, -1];
        const responsiveControlTarget = 1;
        const tableName = String($table.data('table-name') || 'user_tasks');

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

        tables[type] = $table.DataTable(dataTableOptions({
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
                cache: false,
                data: function (data) {
                    $.extend(data, filtersFor(type));
                }
            },
            responsive: {
                details: {
                    type: 'inline',
                    target: responsiveControlTarget
                }
            },
            order: [[1, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: tableName + '.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'title', name: 'title', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'description_text', name: 'description_text', className: 'align-middle dt-text dt-ellipsis' },
                { data: 'board_list', name: 'board_list', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'status', name: 'status', className: 'align-middle white-space-nowrap dt-status' },
                { data: 'priority', name: 'priority', className: 'align-middle white-space-nowrap dt-status' },
                { data: 'color', name: 'color', className: 'align-middle white-space-nowrap dt-status' },
                { data: 'assignees', name: 'assignees', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'due_at', name: 'due_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions' }
            ],
            columnDefs: [
                { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
                { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 3, searchable: false, targets: -1 },
                { responsivePriority: 10, targets: [2, 5] },
                { responsivePriority: 20, targets: [3, 4, 6, 7, 8] },
                { responsivePriority: 30, targets: [9, 10, 11, 12, 13] }
            ],
            createdRow: function (row) {
                $(row).addClass('btn-reveal-trigger');
            },
            initComplete: function () {
                showProtectedColumns(this.api());
                restoreSelectionState(this.api(), type);
            },
            drawCallback: function () {
                restoreSelectionState(this.api(), type);

                if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                    window.AppDataTables.applyFalconEnhancements(document);
                }
            }
        }));

        return tables[type];
    }

    function initTableForType(type) {
        const normalizedType = type === noteType ? noteType : taskType;
        const $table = $('.js-my-board-table[data-type="' + normalizedType + '"]').first();

        return initTable($table);
    }

    function adjustTable(type) {
        const table = tableFor(type);

        if (!table) {
            return;
        }

        table.columns.adjust();

        if (table.responsive && typeof table.responsive.recalc === 'function') {
            table.responsive.recalc();
        }
    }

    function initActiveTable() {
        const type = $('#myBoardTableTabs [data-bs-toggle="tab"].active').data('type') || taskType;

        showActiveFilterPanel(type);
        initTableForType(type);
        adjustTable(type);
    }

    function alertElement($form) {
        let $alert = $form.find('.js-my-board-table-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-my-board-table-alert" role="alert"><span class="js-my-board-table-alert-message"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (message('close') || '') + '"></button></div>');
            $form.find('.js-my-board-table-form-body, .card-body').first().prepend($alert);
        }

        return $alert;
    }

    function validationMessages(errors) {
        const result = [];

        Object.keys(errors || {}).forEach(function (field) {
            const values = $.isArray(errors[field]) ? errors[field] : [errors[field]];

            values.forEach(function (text) {
                if (text) {
                    result.push(text);
                }
            });
        });

        return result;
    }

    function showValidationErrors($form, errors) {
        const $alert = alertElement($form);
        const $list = $('<ul class="mb-0 ps-3"></ul>');

        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');

        validationMessages(errors).forEach(function (text) {
            $list.append($('<li></li>').text(text));
        });

        $alert
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-my-board-table-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const normalizedField = field.replace(/\.\d+$/, '');
            const messageText = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const $input = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $input.addClass('is-invalid');
            $form.find('[data-error-for="' + normalizedField.replace('[]', '') + '"]').text(messageText || '');
        });
    }

    function showFormNotice($form, text, type) {
        alertElement($form)
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .find('.js-my-board-table-alert-message')
            .text(text || message('unexpectedError'));
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-my-board-table-alert-message')
            .empty();
    }

    function setLoading($button, loading) {
        $button.prop('disabled', loading);
        $button.css('cursor', loading ? 'wait' : '');
        $('body').css('cursor', loading ? 'wait' : '');
    }

    function normalizeValues(values) {
        if (!$.isArray(values)) {
            values = values === undefined || values === null || values === '' ? [] : [values];
        }

        return values.map(function (value) {
            return String(value || '').trim();
        }).filter(function (value, index, self) {
            return value !== '' && self.indexOf(value) === index;
        }).sort();
    }

    function syncRichEditors($context) {
        $context.find('.js-my-board-rich-editor').each(function () {
            const $editor = $(this);

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

        $('.js-my-board-rich-editor').each(function () {
            const $editor = $(this);

            if ($editor.data('summernote')) {
                return;
            }

            $editor.summernote({
                height: 220,
                direction: $editor.data('direction') || config.direction || 'ltr',
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
        return new Set($('#user_task_attachment_file_inputs input[name="attachment_file_doc_nums[]"]').map(function () {
            return String($(this).val() || '').trim();
        }).get().filter(Boolean));
    }

    function formatBytes(bytes, fallback) {
        const size = Number(bytes || 0);

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
        const hasRows = $('#user-task-selected-attachments tr').length > 0;

        $('#user-task-selected-attachments-wrap').toggleClass('d-none', !hasRows);
    }

    function addSelectedAttachment(file) {
        const item = file || {};
        const publicId = String(item.public_id || '').trim();
        const fileName = String(item.name || item.original_name || publicId).trim();
        const sizeLabel = formatBytes(item.size, item.size_label);
        const mimeType = String(item.mime_type || item.extension || '').trim();
        const selectedDocNums = selectedAttachmentDocNums();

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
        }).appendTo('#user_task_attachment_file_inputs');

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
                    $('<button type="button" class="btn btn-falcon-danger btn-sm js-remove-selected-user-task-attachment"></button>')
                        .attr('data-public-id', publicId)
                        .append($('<span class="fas fa-times me-1"></span>'))
                        .append($('<span></span>').text(message('removeAttachment')))
                )
            )
            .appendTo('#user-task-selected-attachments');

        updateSelectedAttachmentPanel();
        $('[data-error-for="attachment_file_doc_nums"]').text('');
    }

    function clearSelectedAttachments() {
        $('#user_task_attachment_file_inputs').empty();
        $('#user-task-selected-attachments').empty();
        updateSelectedAttachmentPanel();
    }

    function currentFormData($form) {
        syncRichEditors($form);

        return {
            title: String($form.find('[name="title"]').val() || '').trim(),
            description: String($form.find('[name="description"]').val() || '').trim(),
            type: String($form.find('[name="type"]').val() || '').trim(),
            status: String($form.find('[name="status"]').val() || '').trim(),
            board_list_doc_num: String($form.find('[name="board_list_doc_num"]').val() || '').trim(),
            priority: String($form.find('[name="priority"]').val() || '').trim(),
            color: String($form.find('[name="color"]').val() || '').trim(),
            assignee_doc_nums: normalizeValues($form.find('[name="assignee_doc_nums[]"]').map(function () { return $(this).val(); }).get().flat()),
            due_at: String($form.find('[name="due_at"]').val() || '').trim(),
            attachment_file_doc_nums: normalizeValues($form.find('[name="attachment_file_doc_nums[]"]').map(function () { return $(this).val(); }).get().flat())
        };
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};

        return {
            title: String(original.title || '').trim(),
            description: String(original.description || '').trim(),
            type: String(original.type || '').trim(),
            status: String(original.status || '').trim(),
            board_list_doc_num: String(original.board_list_doc_num || '').trim(),
            priority: String(original.priority || '').trim(),
            color: String(original.color || '').trim(),
            assignee_doc_nums: normalizeValues(original.assignee_doc_nums || []),
            due_at: String(original.due_at || '').trim(),
            attachment_file_doc_nums: normalizeValues(original.attachment_file_doc_nums || [])
        };
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        return JSON.stringify(currentFormData($form)) !== JSON.stringify(originalFormData($form));
    }

    function updateOriginalFormData($form) {
        $form.data('original', currentFormData($form));
    }

    function resetCreateForm($form) {
        $form.find('[name="title"], [name="description"], [name="due_at"]').val('');
        $form.find('[name="status"]').val('todo');
        $form.find('[name="priority"]').val('normal');
        $form.find('[name="color"]').val('primary');
        $form.find('[name="board_list_doc_num"]').val('');
        $form.find('[name="submit_action"]').val('save');
        clearSelectedAttachments();

        if (window.AppDatePicker && typeof window.AppDatePicker.clear === 'function') {
            window.AppDatePicker.clear($form.get(0));
        }

        $form.find('.js-my-board-rich-editor').each(function () {
            const $editor = $(this);

            if ($editor.data('summernote')) {
                $editor.summernote('code', '');
            }
        });

        $form.find('select').trigger('change');
        updateOriginalFormData($form);
    }

    function updateUrlsAfterSave($form, response) {
        const data = response && response.data ? response.data : {};
        const urls = data.urls || {};

        if (urls.update) {
            $form.attr('action', urls.update);
        }

        if (urls.edit && window.history && data.doc_num) {
            window.history.replaceState({}, '', urls.edit);
        }
    }

    function statusModal() {
        const element = document.getElementById('my-board-table-status-modal');

        if (!element || !window.bootstrap || !bootstrap.Modal) {
            return null;
        }

        return bootstrap.Modal.getOrCreateInstance(element);
    }

    function clearStatusModalErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('.js-my-board-table-status-alert').addClass('d-none').text('');
    }

    function showStatusModalError($form, text) {
        $form.find('.js-my-board-table-status-alert')
            .removeClass('d-none')
            .text(text || message('unexpectedError'));
    }

    function openStatusModal($button) {
        const modal = statusModal();

        if (!modal) {
            return;
        }

        const $form = $('.js-my-board-table-status-form').first();
        const currentStatus = String($button.data('current-status') || '');

        clearStatusModalErrors($form);
        $form.find('[name="status_url"]').val($button.data('url') || '');
        $form.find('[name="record_type"]').val($button.data('type') || taskType);
        $form.find('.js-my-board-table-current-status').text($button.data('current-label') || '');
        $form.find('[name="status"]').val(currentStatus);
        modal.show();
    }

    $(document)
        .off('change.myBoardTableRecordFilter', '#my_board_table_record_filter')
        .on('change.myBoardTableRecordFilter', '#my_board_table_record_filter', function () {
            clearSelection(taskType);
            clearSelection(noteType);
            reloadTables();
            updateAllBulkActionsUi();
        })
        .off('shown.bs.tab.myBoardTable', '#myBoardTableTabs [data-bs-toggle="tab"]')
        .on('shown.bs.tab.myBoardTable', '#myBoardTableTabs [data-bs-toggle="tab"]', function () {
            const type = $(this).data('type') || taskType;

            showActiveFilterPanel(type);
            initTableForType(type);
            adjustTable(type);
        })
        .off('change.myBoardTableSelectAll', '.js-my-board-table-select-all')
        .on('change.myBoardTableSelectAll', '.js-my-board-table-select-all', function () {
            const type = $(this).data('type') || taskType;
            const selected = selectedSet(type);
            const checked = $(this).is(':checked');
            const table = tableFor(type);

            pageCheckboxes(table, type).each(function () {
                const docNum = checkboxDocNum(this);

                if (docNum === '') {
                    return;
                }

                if (checked) {
                    selected.add(docNum);
                } else {
                    selected.delete(docNum);
                }

                $(this).prop('checked', checked);
            });

            updateSelectAllState(table, type);
            updateBulkActionsUi(type);
        })
        .off('click.myBoardTableSelectCell', '.js-my-board-table tbody tr:not(.child) td.dt-select')
        .on('click.myBoardTableSelectCell', '.js-my-board-table tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-my-board-table-row-checkbox, input.js-record-select').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        })
        .off('change.myBoardTableRowSelect', '.js-my-board-table-row-checkbox, .js-my-board-table input.js-record-select')
        .on('change.myBoardTableRowSelect', '.js-my-board-table-row-checkbox, .js-my-board-table input.js-record-select', function () {
            const $table = $(this).closest('.js-my-board-table');
            const type = $table.data('type') || taskType;
            const docNum = checkboxDocNum(this);
            const selected = selectedSet(type);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selected.add(docNum);
            } else {
                selected.delete(docNum);
            }

            updateSelectAllState(tableFor(type), type);
            updateBulkActionsUi(type);
        })
        .off('click.myBoardTableBulk', '.js-my-board-table-bulk-apply')
        .on('click.myBoardTableBulk', '.js-my-board-table-bulk-apply', function () {
            const type = $(this).data('type') || taskType;
            const $table = $('.js-my-board-table[data-type="' + type + '"]').first();
            const docNums = Array.from(selectedSet(type));
            const action = String(activeBulkBar(type).find('.js-my-board-table-bulk-action').val() || 'delete');
            const url = bulkUrl($table, action);

            if (docNums.length === 0) {
                showToast('info', message('noRowsSelected'));
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

                const data = { doc_nums: docNums, type: type };

                if (action === 'activate' || action === 'deactivate') {
                    data.is_active = action === 'activate' ? 1 : 0;
                }

                $.ajax({
                    url: url,
                    method: action === 'delete' ? 'DELETE' : 'PATCH',
                    contentType: 'application/json',
                    data: JSON.stringify(data),
                    headers: headers()
                }).done(function (response) {
                    clearSelection(type);
                    reloadTable(type);
                    const fallback = {
                        delete: (message('bulkDeleted') || '').replace(':count', docNums.length),
                        activate: message('bulkActivated'),
                        deactivate: message('bulkDeactivated'),
                        restore: (message('bulkRestored') || '').replace(':count', docNums.length)
                    }[action] || message('updated');

                    showToast('success', response.message || fallback);
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.myBoardTableDelete', '[data-my-board-delete-url], .js-my-board-table-delete[data-delete-url]')
        .on('click.myBoardTableDelete', '[data-my-board-delete-url], .js-my-board-table-delete[data-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('my-board-delete-url') || $button.data('delete-url');
            const docNum = String($button.data('doc-num') || '').trim();

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
                    method: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response.message || message('deleted'));

                    const redirectUrl = $button.data('redirect-url');

                    if (redirectUrl) {
                        window.location.href = redirectUrl;
                        return;
                    }

                    [taskType, noteType].forEach(function (type) {
                        selectedSet(type).delete(docNum);
                        reloadTable(type);
                        updateBulkActionsUi(type);
                    });
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.myBoardTableRestore', '.js-my-board-table-restore[data-restore-url]')
        .on('click.myBoardTableRestore', '.js-my-board-table-restore[data-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('restore-url');
            const docNum = String($button.data('doc-num') || '').trim();
            const type = $button.closest('.js-my-board-table').data('type') || activeType || taskType;

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
                    url: url,
                    method: 'PATCH',
                    headers: headers()
                }).done(function (response) {
                    selectedSet(type).delete(docNum);
                    reloadTable(type);
                    updateBulkActionsUi(type);
                    showToast('success', response.message || message('restored'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.myBoardTableClone', '.js-my-board-table-clone[data-clone-url]')
        .on('click.myBoardTableClone', '.js-my-board-table-clone[data-clone-url]', function () {
            const $button = $(this);
            const url = $button.data('clone-url');
            const type = $button.closest('.js-my-board-table').data('type') || activeType || taskType;

            $.ajax({
                url: url,
                method: 'POST',
                headers: headers()
            }).done(function (response) {
                reloadTable(type);
                showToast('success', response.message || message('duplicated'));
            }).fail(function (xhr) {
                showToast('error', responseMessage(xhr));
            });
        })
        .off('file-picker:selected.myBoardTableAttachmentPicker', '.js-user-task-attachment-picker-trigger')
        .on('file-picker:selected.myBoardTableAttachmentPicker', '.js-user-task-attachment-picker-trigger', function (event, payload) {
            const data = payload || {};
            const file = data.file || data;
            const pickerConfig = data.config || {};

            if (pickerConfig.collection !== 'user_task_attachments') {
                return;
            }

            addSelectedAttachment(file);
        })
        .off('click.myBoardTableRemoveSelectedAttachment', '.js-remove-selected-user-task-attachment')
        .on('click.myBoardTableRemoveSelectedAttachment', '.js-remove-selected-user-task-attachment', function () {
            const publicId = String($(this).data('public-id') || '').trim();

            if (publicId === '') {
                return;
            }

            $('#user_task_attachment_file_inputs input[data-public-id="' + publicId + '"]').remove();
            $('#user-task-selected-attachments tr[data-public-id="' + publicId + '"]').remove();
            updateSelectedAttachmentPanel();
        })
        .off('click.myBoardTableDeleteAttachment', '.js-delete-user-task-attachment')
        .on('click.myBoardTableDeleteAttachment', '.js-delete-user-task-attachment', function () {
            const $button = $(this);
            const $row = $button.closest('tr');

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
                    method: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    $row.remove();
                    showToast('success', response && response.message ? response.message : message('attachmentDeleted'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.myBoardTableStatusOpen', '.js-my-board-table-status-open')
        .on('click.myBoardTableStatusOpen', '.js-my-board-table-status-open', function () {
            openStatusModal($(this));
        })
        .off('submit.myBoardTableStatus', '.js-my-board-table-status-form')
        .on('submit.myBoardTableStatus', '.js-my-board-table-status-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.find('.js-my-board-table-status-save').first();
            const url = $form.find('[name="status_url"]').val();
            const type = $form.find('[name="record_type"]').val() || activeType || taskType;

            clearStatusModalErrors($form);
            setLoading($button, true);

            $.ajax({
                url: url,
                method: 'PATCH',
                headers: headers(),
                data: {
                    status: $form.find('[name="status"]').val()
                }
            }).done(function (response) {
                const modal = statusModal();

                if (modal) {
                    modal.hide();
                }

                reloadTable(type);
                showToast('success', response && response.message ? response.message : message('statusUpdated'));
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    const errors = xhr.responseJSON.errors;
                    const messageText = $.isArray(errors.status) ? errors.status[0] : errors.status;

                    $form.find('[name="status"]').addClass('is-invalid');
                    $form.find('[data-error-for="status"]').text(messageText || message('validationFailed'));
                    showStatusModalError($form, messageText || message('validationFailed'));
                    return;
                }

                showStatusModalError($form, responseMessage(xhr));
            }).always(function () {
                setLoading($button, false);
            });
        })
        .off('dblclick.myBoardTableEditRow', '.js-my-board-table tbody tr:not(.child)')
        .on('dblclick.myBoardTableEditRow', '.js-my-board-table tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        })
        .off('click.myBoardTableSubmitAction', '.js-my-board-table-submit-action')
        .on('click.myBoardTableSubmitAction', '.js-my-board-table-submit-action', function () {
            const $button = $(this);

            $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
            $button.closest('form').data('submit-button', $button);
        })
        .off('submit.myBoardTableForm', '.js-my-board-table-form')
        .on('submit.myBoardTableForm', '.js-my-board-table-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();

            syncRichEditors($form);
            clearFormErrors($form);

            if (!hasChanges($form)) {
                showFormNotice($form, message('noChanges'), 'warning');
                showToast('info', message('noChanges'));
                return;
            }

            setLoading($button, true);

            $.ajax({
                url: $form.attr('action'),
                method: $form.find('[name="_method"]').val() || $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                if (response && response.success === false && response.type === 'no_changes') {
                    showFormNotice($form, response.message || message('noChanges'), 'warning');
                    showToast('info', response.message || message('noChanges'));
                    return;
                }

                updateUrlsAfterSave($form, response);
                clearSelectedAttachments();
                updateOriginalFormData($form);
                showToast('success', response.message);

                if (response && response.reset_form) {
                    resetCreateForm($form);
                }

                if (response && response.redirect) {
                    window.location.href = response.redirect;
                }
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    showValidationErrors($form, xhr.responseJSON.errors);
                    return;
                }

                showFormNotice($form, responseMessage(xhr), 'danger');
            }).always(function () {
                setLoading($button, false);
            });
        })
        .off('input.myBoardTableForm change.myBoardTableForm', '.js-my-board-table-form .is-invalid')
        .on('input.myBoardTableForm change.myBoardTableForm', '.js-my-board-table-form .is-invalid', function () {
            const $input = $(this);
            const field = ($input.attr('name') || '').replace('[]', '');

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"]').text('');
        });

    $(function () {
        initRichEditors();
        updateCreateLinks();
        initActiveTable();
    });
})(jQuery, window, document);
