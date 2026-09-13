(function ($, window, document) {
    'use strict';

    const messages = window.priceListIndexMessages || {};
    const selectedDocNums = new Set();
    let table = null;

    function headers() {
        return {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            Accept: 'application/json'
        };
    }

    function toast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
        }
    }

    function confirmDialog(title, text, confirmButtonText, confirmButtonColor) {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: title,
            text: text,
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: confirmButtonText,
            cancelButtonText: messages.cancel || '',
            confirmButtonColor: confirmButtonColor || '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function trashFilterValue() {
        const value = String($('#price_lists_trash_filter').val() || 'active');

        return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
    }

    function checkboxDocNum(checkbox) {
        return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
    }

    function pageCheckboxes(api) {
        return api && typeof api.rows === 'function'
            ? $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-price-list-row-checkbox')
            : $('.js-price-lists-table').find('tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-price-list-row-checkbox');
    }

    function updateBulkActionsUi() {
        const selectedCount = selectedDocNums.size;
        const $actions = $('#bulk_actions_bar');
        const $applyButton = $('#bulk_action_apply');
        const applyLabel = $applyButton.data('label') || '';

        $actions.toggleClass('d-none', selectedCount === 0).toggleClass('d-flex', selectedCount > 0);
        $('#bulk_selected_count').text(selectedCount);
        $applyButton
            .prop('disabled', selectedCount === 0)
            .find('span:last')
            .text(applyLabel + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));
    }

    function updateSelectAllState(api) {
        const docNums = pageCheckboxes(api).map(function () {
            return checkboxDocNum(this);
        }).get().filter(Boolean);
        const selectedOnPage = docNums.filter(function (docNum) {
            return selectedDocNums.has(docNum);
        }).length;

        $('#select_all_records')
            .prop('checked', docNums.length > 0 && selectedOnPage === docNums.length)
            .prop('indeterminate', selectedOnPage > 0 && selectedOnPage < docNums.length);
    }

    function restoreSelectionState(api) {
        pageCheckboxes(api).each(function () {
            const docNum = checkboxDocNum(this);
            $(this).prop('checked', docNum !== '' && selectedDocNums.has(docNum));
        });
        updateSelectAllState(api);
        updateBulkActionsUi();
    }

    function clearSelection() {
        selectedDocNums.clear();
        updateSelectAllState(table);
        updateBulkActionsUi();
    }

    function reloadTable() {
        if (table) {
            table.ajax.reload(null, false);
        }
    }

    function initializeTable() {
        const $table = $('.js-price-lists-table').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const options = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (config) { return config; };
        const tableName = String($table.data('table-name') || 'price_lists');

        table = $table.DataTable(options({
            processing: true,
            serverSide: true,
            stateSave: true,
            ajax: {
                url: $table.data('url'),
                data: function (data) {
                    data.trash_filter = trashFilterValue();
                }
            },
            responsive: { details: { type: 'inline', target: 1 } },
            order: [[1, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: tableName + '.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'scope', name: 'scope', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'currency', name: 'currency', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'price_list_date', name: 'price_list_date', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'valid_from', name: 'valid_from', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'valid_until', name: 'valid_until', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'lines_count', name: 'lines_count', searchable: false, className: 'align-middle white-space-nowrap text-center' },
                { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'deleted_by', name: 'deleted_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'deleted_at', name: 'deleted_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions' }
            ],
            createdRow: function (row) {
                $(row).addClass('btn-reveal-trigger');
            },
            initComplete: function () {
                restoreSelectionState(this.api());
            },
            drawCallback: function () {
                restoreSelectionState(this.api());

                if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                    window.AppDataTables.applyFalconEnhancements(document);
                }
            }
        }));

        $('#price_lists_trash_filter').off('change.priceLists').on('change.priceLists', function () {
            clearSelection();
            reloadTable();
        });

        $('#select_all_records').off('change.priceLists').on('change.priceLists', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(table).each(function () {
                const docNum = checkboxDocNum(this);

                if (docNum !== '') {
                    checked ? selectedDocNums.add(docNum) : selectedDocNums.delete(docNum);
                    $(this).prop('checked', checked);
                }
            });
            updateSelectAllState(table);
            updateBulkActionsUi();
        });

        $table.off('change.priceLists', 'input.js-record-select, input.js-price-list-row-checkbox').on('change.priceLists', 'input.js-record-select, input.js-price-list-row-checkbox', function () {
            const docNum = checkboxDocNum(this);

            if (docNum !== '') {
                $(this).is(':checked') ? selectedDocNums.add(docNum) : selectedDocNums.delete(docNum);
            }
            updateSelectAllState(table);
            updateBulkActionsUi();
        });

        $table.off('dblclick.priceLists', 'tbody tr:not(.child)').on('dblclick.priceLists', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, button, a, .dropdown, td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            $(this).find('.js-edit-record').get(0)?.click();
        });

        $('#bulk_action_apply').off('click.priceLists').on('click.priceLists', function () {
            const docNums = Array.from(selectedDocNums);

            if (docNums.length === 0 || $('#bulk_action_select').val() !== 'delete') {
                return;
            }

            confirmDialog(
                messages.bulkDeleteConfirmTitle,
                String(messages.bulkDeleteConfirmText || '').replace(':count', docNums.length),
                messages.bulkDeleteConfirmYes
            ).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: $table.data('bulk-delete-url'),
                    method: 'DELETE',
                    data: { doc_nums: docNums },
                    headers: headers()
                }).done(function (response) {
                    clearSelection();
                    reloadTable();
                    toast('success', response.message);
                }).fail(function (response) {
                    toast('error', response.responseJSON?.message || messages.unexpectedError);
                });
            });
        });
    }

    function initializeRowActions() {
        $(document).off('click.priceListsDelete', '.js-delete-record[data-delete-url]').on('click.priceListsDelete', '.js-delete-record[data-delete-url]', function () {
            const $button = $(this);
            const docNum = String($button.data('doc-num') || '').trim();

            confirmDialog(messages.deleteConfirmTitle, messages.deleteConfirmText, messages.deleteConfirmYes).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({ url: $button.data('delete-url'), method: 'DELETE', headers: headers() })
                    .done(function (response) {
                        selectedDocNums.delete(docNum);
                        reloadTable();
                        toast('success', response.message);
                    })
                    .fail(function (response) {
                        toast('error', response.responseJSON?.message || messages.unexpectedError);
                    });
            });
        });

        $(document).off('click.priceListsRestore', '.js-restore-record[data-restore-url]').on('click.priceListsRestore', '.js-restore-record[data-restore-url]', function () {
            const $button = $(this);

            confirmDialog(messages.restoreConfirmTitle, messages.restoreConfirmText, messages.restoreConfirmYes, '#00a65a').then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({ url: $button.data('restore-url'), method: 'PATCH', headers: headers() })
                    .done(function (response) {
                        reloadTable();
                        toast('success', response.message);
                    })
                    .fail(function (response) {
                        toast('error', response.responseJSON?.message || messages.unexpectedError);
                    });
            });
        });
    }

    $(function () {
        initializeTable();
        initializeRowActions();
    });
})(jQuery, window, document);
