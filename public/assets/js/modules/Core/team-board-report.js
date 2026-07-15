(function ($, window, document) {
    'use strict';

    const config = window.TeamBoardReportConfig || {};
    const messages = config.messages || {};
    const taskType = 'task';
    const tables = {};
    const selectedDocNums = {
        task: new Set()
    };

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

    function recordFilterValue() {
        const value = String($('#team_board_record_filter').val() || 'active');

        return ['active', 'inactive', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
    }

    function filtersFor(type) {
        const filters = {
            record_filter: recordFilterValue()
        };

        $('.js-team-board-filter[data-type="' + type + '"]').each(function () {
            const $field = $(this);
            const name = $field.attr('name');

            if (!name) {
                return;
            }

            filters[name] = $field.val() || '';
        });

        return filters;
    }

    function selectedSet(type) {
        if (!selectedDocNums[type]) {
            selectedDocNums[type] = new Set();
        }

        return selectedDocNums[type];
    }

    function tableFor(type) {
        return tables[type || taskType];
    }

    function activeBulkBar(type) {
        return $('.js-team-board-bulk-actions-bar[data-type="' + type + '"]');
    }

    function optionVisibleForFilter($option, filter) {
        const filters = String($option.data('visible-filters') || '').split(/\s+/).filter(Boolean);

        return filters.length === 0 || filters.indexOf(filter) !== -1;
    }

    function syncBulkActionOptions(type) {
        const filter = recordFilterValue();
        const $select = activeBulkBar(type).find('.js-team-board-bulk-action');
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

    function updateBulkActionsUi(type) {
        const selectedCount = selectedSet(type).size;
        const $bar = activeBulkBar(type);
        const $button = $bar.find('.js-team-board-bulk-apply');

        syncBulkActionOptions(type);
        $bar.toggleClass('d-none', selectedCount === 0).toggleClass('d-flex', selectedCount > 0);
        $bar.find('.js-team-board-selected-count').text(selectedCount);
        $button.prop('disabled', selectedCount === 0 || $bar.find('.js-team-board-bulk-action option:not(:disabled)').length === 0);
    }

    function clearSelection(type) {
        selectedSet(type).clear();
        $('.js-team-board-select-all[data-type="' + type + '"]').prop('checked', false).prop('indeterminate', false);
        updateBulkActionsUi(type);
    }

    function checkboxDocNum(checkbox) {
        return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
    }

    function pageCheckboxes(api, type) {
        if (api && typeof api.rows === 'function') {
            return $(api.rows({ page: 'current' }).nodes()).find('input.js-team-board-row-checkbox, input.js-record-select');
        }

        return $('.js-team-board-table[data-type="' + type + '"]').find('input.js-team-board-row-checkbox, input.js-record-select');
    }

    function updateSelectAllState(api, type) {
        const $checkboxes = pageCheckboxes(api, type);
        const selectable = $checkboxes.filter(':not(:disabled)');
        const checked = selectable.filter(':checked');
        const $selectAll = $('.js-team-board-select-all[data-type="' + type + '"]');

        $selectAll
            .prop('checked', selectable.length > 0 && checked.length === selectable.length)
            .prop('indeterminate', checked.length > 0 && checked.length < selectable.length)
            .prop('disabled', selectable.length === 0);
    }

    function restoreSelectionState(api, type) {
        const selected = selectedSet(type);

        pageCheckboxes(api, type).each(function () {
            const docNum = checkboxDocNum(this);

            $(this).prop('checked', selected.has(docNum));
        });

        updateSelectAllState(api, type);
        updateBulkActionsUi(type);
    }

    function reloadTable(type) {
        const table = tables[type];

        if (table) {
            table.ajax.reload(null, false);
        }
    }

    function bulkUrl($table, action) {
        if (action === 'restore') {
            return $table.data('bulk-restore-url') || config.urls?.bulkRestore;
        }

        return $table.data('bulk-delete-url') || config.urls?.bulkDelete;
    }

    function bulkConfirm(action, count) {
        const titleKey = action === 'restore' ? 'bulkRestoreConfirmTitle' : 'bulkDeleteConfirmTitle';
        const textKey = action === 'restore' ? 'bulkRestoreConfirmText' : 'bulkDeleteConfirmText';
        const yesKey = action === 'restore' ? 'bulkRestoreConfirmYes' : 'bulkDeleteConfirmYes';

        return {
            title: message(titleKey),
            text: (message(textKey) || '').replace(':count', count),
            confirmButtonText: message(yesKey),
            confirmButtonColor: action === 'restore' ? '#00a65a' : '#d33'
        };
    }

    function initTable($table) {
        if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const type = $table.data('type') || taskType;
        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) {
                return options;
            };
        const protectedColumns = [0, 1, -1];
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
                url: $table.data('url') || config.urls?.tasks,
                data: function (data) {
                    $.extend(data, filtersFor(type));
                }
            },
            responsive: {
                details: {
                    type: 'inline',
                    target: 1
                }
            },
            order: [[13, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: tableName + '.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'title', name: 'title', className: 'align-middle white-space-nowrap dt-text dt-ellipsis', responsivePriority: 3 },
                { data: 'description_text', name: 'description_text', className: 'align-middle dt-text dt-ellipsis' },
                { data: 'assignees', name: 'assignees', className: 'align-middle white-space-nowrap dt-text dt-ellipsis', responsivePriority: 4 },
                { data: 'board_list', name: 'board_list', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'status', name: 'status', className: 'align-middle white-space-nowrap dt-status' },
                { data: 'priority', name: 'priority', className: 'align-middle white-space-nowrap dt-status' },
                { data: 'due_at', name: 'due_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'attachments_count', name: 'attachments_count', className: 'align-middle white-space-nowrap text-center' },
                { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'comments_count', name: 'comments_count', className: 'align-middle white-space-nowrap text-center' },
                { data: 'views_count', name: 'views_count', className: 'align-middle white-space-nowrap text-center' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions', responsivePriority: 5 }
            ],
            columnDefs: [
                { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
                { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
                { responsivePriority: 3, targets: 2 },
                { responsivePriority: 4, targets: 4 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 5, searchable: false, targets: -1 }
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
    }

    function initTables() {
        $('.js-team-board-table').each(function () {
            initTable($(this));
        });
    }

    $(document)
        .off('click.teamBoardReportApply', '.js-team-board-apply')
        .on('click.teamBoardReportApply', '.js-team-board-apply', function () {
            const type = $(this).data('type') || taskType;

            clearSelection(type);
            reloadTable(type);
        })
        .off('click.teamBoardReportReset', '.js-team-board-reset')
        .on('click.teamBoardReportReset', '.js-team-board-reset', function () {
            const type = $(this).data('type') || taskType;

            $('.js-team-board-filter[data-type="' + type + '"]').each(function () {
                $(this).val('').trigger('change.select2');

                if (this._flatpickr) {
                    this._flatpickr.clear();
                }
            });

            clearSelection(type);
            reloadTable(type);
        })
        .off('change.teamBoardRecordFilter', '#team_board_record_filter')
        .on('change.teamBoardRecordFilter', '#team_board_record_filter', function () {
            clearSelection(taskType);
            reloadTable(taskType);
        })
        .off('change.teamBoardSelectAll', '.js-team-board-select-all')
        .on('change.teamBoardSelectAll', '.js-team-board-select-all', function () {
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
        .off('click.teamBoardSelectCell', '.js-team-board-table tbody tr:not(.child) td.dt-select')
        .on('click.teamBoardSelectCell', '.js-team-board-table tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-team-board-row-checkbox, input.js-record-select').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        })
        .off('change.teamBoardRowSelect', '.js-team-board-row-checkbox, .js-team-board-table input.js-record-select')
        .on('change.teamBoardRowSelect', '.js-team-board-row-checkbox, .js-team-board-table input.js-record-select', function () {
            const $table = $(this).closest('.js-team-board-table');
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
        .off('click.teamBoardBulk', '.js-team-board-bulk-apply')
        .on('click.teamBoardBulk', '.js-team-board-bulk-apply', function () {
            const type = $(this).data('type') || taskType;
            const $table = $('.js-team-board-table[data-type="' + type + '"]').first();
            const docNums = Array.from(selectedSet(type));
            const action = String(activeBulkBar(type).find('.js-team-board-bulk-action').val() || 'delete');
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

                $.ajax({
                    url: url,
                    method: action === 'restore' ? 'PATCH' : 'DELETE',
                    contentType: 'application/json',
                    data: JSON.stringify({ doc_nums: docNums, type: type }),
                    headers: headers()
                }).done(function (response) {
                    clearSelection(type);
                    reloadTable(type);
                    const fallback = action === 'restore'
                        ? (message('bulkRestored') || '').replace(':count', docNums.length)
                        : (message('bulkDeleted') || '').replace(':count', docNums.length);

                    showToast('success', response.message || fallback);
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.teamBoardDelete', '[data-my-board-delete-url], .js-my-board-table-delete[data-delete-url]')
        .on('click.teamBoardDelete', '[data-my-board-delete-url], .js-my-board-table-delete[data-delete-url]', function () {
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
                    selectedSet(taskType).delete(docNum);
                    reloadTable(taskType);
                    updateBulkActionsUi(taskType);
                    showToast('success', response.message || message('deleted'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.teamBoardRestore', '.js-my-board-table-restore[data-restore-url]')
        .on('click.teamBoardRestore', '.js-my-board-table-restore[data-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('restore-url');
            const docNum = String($button.data('doc-num') || '').trim();

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
                    selectedSet(taskType).delete(docNum);
                    reloadTable(taskType);
                    updateBulkActionsUi(taskType);
                    showToast('success', response.message || message('restored'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        });

    $(function () {
        syncBulkActionOptions(taskType);
        initTables();
    });
})(jQuery, window, document);
