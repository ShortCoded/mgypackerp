(function ($, window, document) {
    'use strict';

    const messages = window.financeCrudMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    let financeTable = null;

    function msg(key) {
        return messages[key] || key;
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

    window.financeShowToast = showToast;

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
            cancelButtonText: options.cancelButtonText || msg('cancel'),
            confirmButtonColor: options.confirmButtonColor || '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function columnName(resource, column, tableName) {
        const maps = {
            bank_accounts: {
                doc_num: tableName + '.doc_number',
                bank: 'bank_groups.account_code',
                account_name: 'generated_accounts.name',
                currency: 'currencies.code',
                account_number: 'bank_accounts.account_number',
                iban: 'bank_accounts.iban',
                status: 'bank_accounts.status',
                created_by: 'created_users.name',
                created_at: 'bank_accounts.created_at',
                updated_by: 'updated_users.name',
                updated_at: 'bank_accounts.updated_at'
            },
            cashboxes: {
                doc_num: tableName + '.doc_number',
                name: 'cashboxes.name',
                account: 'accounts.account_code',
                branch: 'branches.name',
                currencies_summary: 'currencies_summary',
                status: 'cashboxes.status',
                created_by: 'created_users.name',
                created_at: 'cashboxes.created_at',
                updated_by: 'updated_users.name',
                updated_at: 'cashboxes.updated_at'
            },
            opening_balances: {
                doc_num: tableName + '.doc_number',
                company: 'companies.name',
                branch: 'branches.name',
                financial_period: 'financial_periods.name',
                account: 'accounts.account_code',
                currency: 'currencies.code',
                debit_amount: 'account_opening_balances.debit_amount',
                credit_amount: 'account_opening_balances.credit_amount',
                status: 'account_opening_balances.status',
                created_by: 'created_users.name',
                created_at: 'account_opening_balances.created_at',
                updated_by: 'updated_users.name',
                updated_at: 'account_opening_balances.updated_at',
            },
            cash_receipt_vouchers: {
                doc_num: tableName + '.doc_number',
                voucher_date: 'cash_vouchers.voucher_date',
                cashbox: 'cashboxes.name',
                person_name: 'cash_vouchers.person_name',
                currency: 'currencies.code',
                exchange_rate: 'cash_vouchers.exchange_rate',
                amount: 'cash_vouchers.amount',
                distributed_amount: 'distributed_total',
                remaining_amount: 'cash_vouchers.amount',
                status: 'cash_vouchers.status',
                reason: 'cash_vouchers.reason',
                created_by: 'created_users.name',
                updated_by: 'updated_users.name',
                approved_by: 'approved_users.name',
                approved_at: 'cash_vouchers.approved_at'
            },
            cash_payment_vouchers: {
                doc_num: tableName + '.doc_number',
                voucher_date: 'cash_vouchers.voucher_date',
                cashbox: 'cashboxes.name',
                person_name: 'cash_vouchers.person_name',
                currency: 'currencies.code',
                exchange_rate: 'cash_vouchers.exchange_rate',
                amount: 'cash_vouchers.amount',
                distributed_amount: 'distributed_total',
                remaining_amount: 'cash_vouchers.amount',
                status: 'cash_vouchers.status',
                reason: 'cash_vouchers.reason',
                created_by: 'created_users.name',
                updated_by: 'updated_users.name',
                approved_by: 'approved_users.name',
                approved_at: 'cash_vouchers.approved_at'
            },
        };

        return maps[resource] && maps[resource][column] ? maps[resource][column] : column;
    }

    function columnClass(column, index) {
        if (index === 0) {
            return 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control';
        }

        if (['status'].indexOf(column) !== -1) {
            return 'align-middle white-space-nowrap dt-status';
        }

        if (['created_at', 'updated_at', 'approved_at', 'cheque_date', 'due_date', 'voucher_date', 'transfer_date'].indexOf(column) !== -1) {
            return 'align-middle white-space-nowrap dt-date';
        }

        if (['debit_amount', 'credit_amount', 'amount', 'exchange_rate', 'distributed_amount', 'remaining_amount', 'source_amount', 'target_amount'].indexOf(column) !== -1) {
            return 'align-middle white-space-nowrap text-end';
        }

        return 'align-middle white-space-nowrap dt-text dt-ellipsis';
    }

    function tableColumns(resource, tableName) {
        const configuredColumns = window.financeCrudColumns || {
            bank_accounts: ['doc_num', 'bank', 'currency', 'account_name', 'account_number', 'iban', 'status', 'created_by', 'created_at', 'updated_by', 'updated_at'],
            cashboxes: ['doc_num', 'name', 'account', 'branch', 'currencies_summary', 'status', 'created_by', 'created_at', 'updated_by', 'updated_at'],
            opening_balances: ['doc_num', 'company', 'branch', 'financial_period', 'account', 'currency', 'debit_amount', 'credit_amount', 'status', 'created_by', 'created_at', 'updated_by', 'updated_at'],
        }[resource] || [];

        const columns = [
            { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' }
        ];

        configuredColumns.forEach(function (column, index) {
            columns.push({
                data: column,
                name: columnName(resource, column, tableName),
                className: columnClass(column, index),
                responsivePriority: index < 2 ? 2 + index : 10 + index
            });
        });

        columns.push({ data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions', responsivePriority: 3 });

        return columns;
    }

    function initTable() {
        const $table = $('.js-finance-table').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const $root = $('[data-finance-crud-root]').first();
        const resource = String($root.data('resource') || '');
        const tableName = String($table.data('table-name') || '');
        const protectedColumns = [0, 1, -1];
        const selectedDocNums = new Set();
        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) {
                return options;
            };
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-finance-row-checkbox';
        const selectAllSelector = '#select_all_records';
        const trashFilterSelector = '.js-finance-trash-filter';
        const extraFilterSelector = '.js-finance-extra-filter';

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            if (api && typeof api.rows === 'function') {
                return $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-finance-row-checkbox');
            }

            return $table.find(rowCheckboxSelector);
        }

        function trashFilterValue() {
            const value = String($(trashFilterSelector).val() || 'active');

            return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
        }

        function protectStateColumns(data) {
            if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
                window.AppDataTables.protectStateColumns(data, protectedColumns);
                return;
            }

            [0, 1, tableColumns(resource, tableName).length - 1].forEach(function (index) {
                if (data && data.columns && data.columns[index]) {
                    data.columns[index].visible = true;
                }
            });
        }

        function showProtectedColumns(api) {
            if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                window.AppDataTables.showColumns(api, protectedColumns);
                return;
            }

            [0, 1, tableColumns(resource, tableName).length - 1].forEach(function (index) {
                api.column(index).visible(true, false);
            });

            api.columns.adjust();
        }

        function updateBulkActionsUi() {
            const selectedCount = selectedDocNums.size;
            const $actions = $('#bulk_actions_bar');
            const $actionSelect = $('#bulk_action_select');
            const $applyButton = $('#bulk_action_apply');
            const applyLabel = $applyButton.data('label') || '';

            $actions
                .toggleClass('d-none', selectedCount === 0)
                .toggleClass('d-flex', selectedCount > 0);

            $('#bulk_selected_count').text(selectedCount);

            $applyButton
                .prop('disabled', selectedCount === 0)
                .find('span:last')
                .text(applyLabel + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));

            if (selectedCount === 0) {
                $actionSelect.val('');
            }
        }

        function updateSelectAllState(api) {
            const $checkboxes = pageCheckboxes(api);
            const selectableDocNums = $checkboxes.map(function () {
                return checkboxDocNum(this);
            }).get().filter(function (docNum) {
                return docNum !== '';
            });
            const checkedOnPage = selectableDocNums.filter(function (docNum) {
                return selectedDocNums.has(docNum);
            }).length;

            $(selectAllSelector)
                .prop('checked', selectableDocNums.length > 0 && checkedOnPage === selectableDocNums.length)
                .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < selectableDocNums.length);
        }

        function restoreSelectionState(api) {
            pageCheckboxes(api).each(function () {
                const docNum = checkboxDocNum(this);

                $(this).prop('checked', docNum !== '' && selectedDocNums.has(docNum));
            });

            updateSelectAllState(api);
            updateBulkActionsUi();
        }

        function clearSelection(api) {
            selectedDocNums.clear();
            pageCheckboxes(api).prop('checked', false);
            updateSelectAllState(api);
            updateBulkActionsUi();
        }

        function reloadTable() {
            financeTable.ajax.reload(null, false);
        }

        financeTable = $table.DataTable(dataTableOptions({
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
                    data.trash_filter = trashFilterValue();

                    $(extraFilterSelector).each(function () {
                        const name = String($(this).data('filter-name') || '').trim();

                        if (name !== '') {
                            data[name] = $(this).val() || '';
                        }
                    });
                }
            },
            responsive: {
                details: {
                    type: 'inline',
                    target: 1
                }
            },
            order: [[1, 'desc']],
            columns: tableColumns(resource, tableName),
            columnDefs: [
                { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
                { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 3, searchable: false, targets: -1 }
            ],
            createdRow: function (row) {
                $(row).addClass('btn-reveal-trigger');
            },
            initComplete: function () {
                showProtectedColumns(this.api());
                restoreSelectionState(this.api());
            },
            drawCallback: function () {
                restoreSelectionState(this.api());

                if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                    window.AppDataTables.applyFalconEnhancements(document);
                }
            }
        }));

        $(trashFilterSelector).off('change.financeTrash').on('change.financeTrash', function () {
            clearSelection(financeTable);
            reloadTable();
        });

        $(extraFilterSelector).off('change.financeExtraFilter').on('change.financeExtraFilter', function () {
            clearSelection(financeTable);
            reloadTable();
        });

        $(selectAllSelector).off('change.financeSelectAll').on('change.financeSelectAll', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(financeTable).each(function () {
                const docNum = checkboxDocNum(this);

                if (docNum === '') {
                    return;
                }

                if (checked) {
                    selectedDocNums.add(docNum);
                } else {
                    selectedDocNums.delete(docNum);
                }

                $(this).prop('checked', checked);
            });

            updateSelectAllState(financeTable);
            updateBulkActionsUi();
        });

        $table.off('click.financeSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.financeSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-finance-row-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $table.off('dblclick.financeEditRow', 'tbody tr:not(.child)').on('dblclick.financeEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const rowData = financeTable && typeof financeTable.row === 'function' ? financeTable.row(this).data() : null;
            if (rowData && Object.prototype.hasOwnProperty.call(rowData, 'can_edit')) {
                if (rowData.can_edit && rowData.edit_url) {
                    window.location.href = rowData.edit_url;
                    return;
                }

                if (rowData.edit_blocked_message) {
                    showToast('warning', rowData.edit_blocked_message);
                }

                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $(document)
            .off('click.financeSelectStop mousedown.financeSelectStop mouseup.financeSelectStop', '.js-record-select, #select_all_records, td.dt-select')
            .on('click.financeSelectStop mousedown.financeSelectStop mouseup.financeSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
                event.stopPropagation();
            });

        $table.off('change.financeSelect', rowCheckboxSelector).on('change.financeSelect', rowCheckboxSelector, function () {
            const docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(financeTable);
            updateBulkActionsUi();
        });

        $(document).off('finance:deleted.financeTable').on('finance:deleted.financeTable', function (event, docNum) {
            if (docNum) {
                selectedDocNums.delete(docNum);
            }

            reloadTable();
            updateSelectAllState(financeTable);
            updateBulkActionsUi();
        });

        $(document).off('finance:restored.financeTable').on('finance:restored.financeTable', function (event, docNum) {
            if (docNum) {
                selectedDocNums.delete(docNum);
            }

            reloadTable();
            updateSelectAllState(financeTable);
            updateBulkActionsUi();
        });

        $('#bulk_action_apply').off('click.financeBulk').on('click.financeBulk', function () {
            const docNums = Array.from(selectedDocNums);
            const action = $('#bulk_action_select').val();
            const $selectedAction = $('#bulk_action_select option:selected');

            if (docNums.length === 0) {
                updateBulkActionsUi();
                showToast('warning', msg('noRowsSelected'));
                return;
            }

            if (!action) {
                showToast('warning', msg('noActionSelected'));
                return;
            }

            const actionConfig = {
                delete: {
                    url: $root.data('bulk-delete-url'),
                    method: 'DELETE',
                    title: msg('bulkDeleteConfirmTitle'),
                    text: String(msg('bulkDeleteConfirmText')).replace(':count', docNums.length),
                    confirmButtonText: msg('bulkDeleteConfirmYes')
                },
                approve: {
                    url: $root.data('bulk-approve-url'),
                    method: 'POST',
                    title: $selectedAction.data('confirm-title') || msg('bulkApproveConfirmTitle'),
                    text: $selectedAction.data('confirm-text') || msg('bulkApproveConfirmText'),
                    confirmButtonText: $selectedAction.data('confirm-yes') || msg('bulkApproveConfirmYes'),
                    confirmButtonColor: '#00a65a'
                }
            }[action];

            if (!actionConfig || !actionConfig.url) {
                showToast('error', msg('unexpectedError'));
                return;
            }

            confirmDialog({
                title: actionConfig.title,
                text: actionConfig.text,
                confirmButtonText: actionConfig.confirmButtonText,
                confirmButtonColor: actionConfig.confirmButtonColor
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: actionConfig.url,
                    method: actionConfig.method,
                    data: { doc_nums: docNums },
                    headers: headers()
                }).done(function (response) {
                    clearSelection(financeTable);
                    reloadTable();
                    showToast('success', response.message);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'));
                });
            });
        });
    }

    function alertElement($form) {
        let $alert = $form.find('.js-form-alert, .js-finance-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-form-alert" role="alert"><div class="js-form-alert-message"></div><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
            $form.find('.card-body').first().prepend($alert);
        }

        return $alert;
    }

    function validationMessages(errors) {
        const result = [];

        Object.keys(errors || {}).forEach(function (field) {
            const values = $.isArray(errors[field]) ? errors[field] : [errors[field]];
            values.forEach(function (message) {
                if (message) {
                    result.push(message);
                }
            });
        });

        return result;
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-form-alert-message, .js-finance-alert-message')
            .empty();
    }

    function showValidationErrors($form, errors) {
        const $alert = alertElement($form);
        const $list = $('<ul class="mb-0 ps-3"></ul>');
        const allMessages = validationMessages(errors);

        clearFormErrors($form);

        (allMessages.length ? allMessages : [msg('validationFailed')]).forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        $alert
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-form-alert-message, .js-finance-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const normalizedField = field.replace(/\.\d+$/, '').replace(/\.\d+\./g, '.');
            const baseField = normalizedField.split('.')[0];
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const parts = field.split('.');
            const exactName = parts.length > 1 ? parts.shift() + parts.map(function (part) {
                return '[' + part + ']';
            }).join('') : field;
            const $input = $form.find('[name="' + field + '"], [name="' + exactName + '"], [name="' + normalizedField + '"], [name="' + normalizedField + '[]"], [name="' + baseField + '"], [name="' + baseField + '[]"]');

            $input.addClass('is-invalid');
            $form.find('[data-error-for="' + field + '"], [data-error-for="' + normalizedField + '"], [data-error-for="' + baseField + '"]').text(message || '');
        });
    }

    function showFormNotice($form, message, type) {
        alertElement($form)
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .find('.js-form-alert-message, .js-finance-alert-message')
            .text(message || msg('unexpectedError'));
    }

    function setLoading($button, loading) {
        $button.prop('disabled', loading);
        $button.css('cursor', loading ? 'wait' : '');
        $('body').css('cursor', loading ? 'wait' : '');
    }

    function financeFormResource($form) {
        if ($form.data('resource')) {
            return String($form.data('resource'));
        }

        return $form.hasClass('js-bank-account-form') ? 'bank_accounts' : '';
    }

    function fieldValue($form, name) {
        const $field = $form.find('[name="' + name + '"]').last();

        if ($field.attr('type') === 'checkbox') {
            return $field.is(':checked') ? '1' : '0';
        }

        return String($field.val() || '').trim();
    }

    function currentBankAccountFormData($form) {
        return {
            doc_number: fieldValue($form, 'doc_number'),
            bank_doc_num: fieldValue($form, 'bank_doc_num'),
            currency_doc_num: fieldValue($form, 'currency_doc_num'),
            account_name: fieldValue($form, 'account_name'),
            account_number: fieldValue($form, 'account_number'),
            iban: fieldValue($form, 'iban'),
            swift_code: fieldValue($form, 'swift_code').toUpperCase(),
            owner_name: fieldValue($form, 'owner_name'),
            bank_branch_name: fieldValue($form, 'bank_branch_name'),
            status: fieldValue($form, 'status') || 'active',
            notes: fieldValue($form, 'notes')
        };
    }

    function originalBankAccountFormData($form) {
        const original = $form.data('original') || {};

        return {
            doc_number: original.doc_number === null || original.doc_number === undefined ? '' : String(original.doc_number).trim(),
            bank_doc_num: String(original.bank_doc_num || '').trim(),
            currency_doc_num: String(original.currency_doc_num || '').trim(),
            account_name: String(original.account_name || '').trim(),
            account_number: String(original.account_number || '').trim(),
            iban: String(original.iban || '').trim(),
            swift_code: String(original.swift_code || '').trim().toUpperCase(),
            owner_name: String(original.owner_name || '').trim(),
            bank_branch_name: String(original.bank_branch_name || '').trim(),
            status: String(original.status || 'active').trim(),
            notes: String(original.notes || '').trim()
        };
    }

    function bankAccountFormHasChanges($form) {
        if (financeFormResource($form) !== 'bank_accounts' || $form.data('mode') !== 'edit') {
            return true;
        }

        const original = originalBankAccountFormData($form);
        const current = currentBankAccountFormData($form);

        return Object.keys(original).some(function (field) {
            return original[field] !== current[field];
        });
    }

    function updateOriginalFormData($form) {
        if (financeFormResource($form) !== 'bank_accounts') {
            return;
        }

        $form.data('original', currentBankAccountFormData($form));
    }

    function resetCreateForm($form) {
        const resource = financeFormResource($form);

        if (resource === 'cashboxes') {
            $form.find('[name="parent_account_doc_num"], [name="branch_doc_num"], [name="currency_doc_nums[]"]').val(null).trigger('change');
            $form.find('[name="name"], [name="notes"]').val('');
            $form.find('[name="status"]').val('active');
            $form.find('[name="submit_action"]').val('save');
            $form.find('[name="clone_source_token"]').remove();
            $form.find('[name="doc_number"]').val('');
            focusPrimaryField($form);
            return;
        }

        if (resource === 'cash_receipt_vouchers' || resource === 'cash_payment_vouchers') {
            $form.trigger('cashVoucher:reset');
            focusPrimaryField($form);
            return;
        }

        if (resource !== 'bank_accounts') {
            if (['cheques', 'fund_transfers'].indexOf(resource) !== -1 && $form[0]) {
                $form[0].reset();
                $form.find('[name="submit_action"]').val('save');
                $form.find('[name="clone_source_token"]').remove();
                $form.find('[name="doc_number"]').val('');
                $form.find('select').val(null).trigger('change');
                $(document).trigger('finance:form-reset', [$form, resource]);
                focusPrimaryField($form);
            }

            return;
        }

        $form.find('[name="bank_doc_num"], [name="currency_doc_num"]').val(null).trigger('change');
        $form.find('[name="account_name"], [name="account_number"], [name="iban"], [name="swift_code"], [name="owner_name"], [name="bank_branch_name"], [name="notes"]').val('');
        $form.find('[name="status"]').val('active');
        $form.find('[name="submit_action"]').val('save');
        $form.find('[name="clone_source_token"]').remove();
        $form.find('[name="doc_number"]').val('');
        updateOriginalFormData($form);
        $form.find('[name="account_name"]').trigger('focus');
    }

    function focusPrimaryField($form) {
        if ($form.data('mode') === 'view') {
            return;
        }

        const primaryField = String($form.data('primary-focus') || (financeFormResource($form) === 'bank_accounts' ? 'account_name' : ''));

        if (primaryField === '') {
            return;
        }

        const $field = $form.find('[name="' + primaryField + '"]:visible:not([readonly]):not(:disabled)').first();

        if ($field.length > 0) {
            $field.trigger('focus');
        }
    }

    function updateUrlsAfterDocNumberChange($form, response) {
        const data = response && response.data ? response.data : {};
        const urls = data.urls || {};

        if (!data.old_doc_num || !data.doc_num || data.old_doc_num === data.doc_num) {
            return;
        }

        if (urls.update) {
            $form.attr('action', urls.update);
        }

        $('a[href*="' + data.old_doc_num + '"]').each(function () {
            const $link = $(this);

            $link.attr('href', String($link.attr('href')).replace(data.old_doc_num, data.doc_num));

            if ($.trim($link.text()) === data.old_doc_num) {
                $link.text(data.doc_num);
            }
        });

        $('[data-doc-num="' + data.old_doc_num + '"]').attr('data-doc-num', data.doc_num).data('doc-num', data.doc_num);

        [
            ['delete-url', urls.destroy],
            ['restore-url', urls.restore],
            ['redirect-url', urls.show]
        ].forEach(function (entry) {
            const attribute = entry[0];
            const url = entry[1];

            if (!url) {
                return;
            }

            $('[data-' + attribute + '*="' + data.old_doc_num + '"]').attr('data-' + attribute, url).data(attribute, url);
        });

        if (window.history && window.location.pathname.indexOf(data.old_doc_num) !== -1) {
            window.history.replaceState({}, '', window.location.pathname.replace(data.old_doc_num, data.doc_num) + window.location.search + window.location.hash);
        }
    }

    function initForm() {
        $(document)
            .off('click.financeSubmitAction', '.js-finance-submit-action, .js-submit-action')
            .on('click.financeSubmitAction', '.js-finance-submit-action, .js-submit-action', function () {
                const $button = $(this);

                $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
                $button.closest('form').data('submit-button', $button);
            });

        $(document).off('submit.financeForm', '.js-finance-form, .js-crud-form').on('submit.financeForm', '.js-finance-form, .js-crud-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();
            const method = $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST';

            clearFormErrors($form);

            if (!bankAccountFormHasChanges($form)) {
                showFormNotice($form, msg('noChanges'), 'warning');
                showToast('info', msg('noChanges'));
                return;
            }

            setLoading($button, true);

            $.ajax({
                url: $form.attr('action'),
                method: method,
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                if (response && response.success === false && response.type === 'no_changes') {
                    showFormNotice($form, response.message || msg('noChanges'), 'warning');
                    showToast('info', response.message || msg('noChanges'));
                    return;
                }

                updateUrlsAfterDocNumberChange($form, response);
                updateOriginalFormData($form);
                showToast('success', response.message || msg('saved'));

                if (response && response.reset_form) {
                    resetCreateForm($form);
                }

                if (response && response.redirect) {
                    window.location.href = response.redirect;
                }
            }).fail(function (response) {
                if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                    showValidationErrors($form, response.responseJSON.errors);
                    return;
                }

                showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'), 'danger');
            }).always(function () {
                setLoading($button, false);
            });
        });

        $(document).off('input.financeForm change.financeForm select2:select.financeForm select2:clear.financeForm', '.js-finance-form .is-invalid, .js-crud-form .is-invalid').on('input.financeForm change.financeForm select2:select.financeForm select2:clear.financeForm', '.js-finance-form .is-invalid, .js-crud-form .is-invalid', function () {
            const $input = $(this);
            const field = ($input.attr('name') || '').replace('[]', '');

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"]').text('');
        });

        $('.js-finance-form').each(function () {
            focusPrimaryField($(this));
        });
    }

    function initDocumentNumberSettings() {
        $(document)
            .off('submit.financeDocSettings', '.js-finance-document-number-settings-form')
            .on('submit.financeDocSettings', '.js-finance-document-number-settings-form', function (event) {
                event.preventDefault();

                const $form = $(this);
                const $button = $form.find('[type="submit"]');

                clearFormErrors($form);
                setLoading($button, true);

                $.ajax({
                    url: $form.attr('action'),
                    method: $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST',
                    data: $form.serialize(),
                    headers: headers()
                }).done(function (response) {
                    const data = response && response.data ? response.data : {};

                    if (Object.prototype.hasOwnProperty.call(data, 'prefix')) {
                        $form.find('[name="prefix"]').val(data.prefix || '');
                    }

                    if (Object.prototype.hasOwnProperty.call(data, 'padding')) {
                        $form.find('[name="padding"]').val(data.padding);
                    }

                    showToast('success', response.message || msg('saved'));
                }).fail(function (response) {
                    if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                        showValidationErrors($form, response.responseJSON.errors);
                        return;
                    }

                    showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'), 'danger');
                }).always(function () {
                    setLoading($button, false);
                });
            });
    }

    function initSelect2() {
        if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
            window.AppSelect2Ajax.init(document);
            return;
        }

        if (!$.fn.select2) {
            return;
        }

        $('.js-select2-ajax').each(function () {
            const $select = $(this);

            if ($select.data('select2')) {
                return;
            }

            $select.select2({
                theme: 'bootstrap-5',
                width: '100%',
                dir: document.documentElement.getAttribute('dir') || 'ltr',
                allowClear: String($select.data('allow-clear')) === 'true',
                placeholder: $select.data('placeholder') || '',
                ajax: {
                    url: $select.data('url'),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        const data = {
                            q: params.term || '',
                            page: params.page || 1
                        };
                        const parentSelector = String($select.data('parent-select') || '');

                        if (parentSelector !== '') {
                            data.parent = $(parentSelector).val() || '';
                        }

                        return data;
                    }
                }
            });
        });
    }

    function selectOption($select, option) {
        if ($select.length === 0 || !option || option.id === undefined || option.text === undefined) {
            return;
        }

        const value = String(option.id);
        const exists = $select.find('option').filter(function () {
            return String(this.value) === value;
        }).length > 0;

        if (!exists) {
            $select.append(new Option(String(option.text), value, true, true));
        }

        $select.val(value).trigger('change').trigger('change.select2');
    }

    function modalApi($modal) {
        return window.bootstrap && window.bootstrap.Modal && $modal.length
            ? window.bootstrap.Modal.getOrCreateInstance($modal[0])
            : null;
    }

    function hideModal($modal) {
        const modal = window.bootstrap && window.bootstrap.Modal && $modal.length
            ? window.bootstrap.Modal.getInstance($modal[0])
            : null;

        if (modal) {
            modal.hide();
            return;
        }

        $modal.modal('hide');
    }

    function resetInlineBankForm($form) {
        clearFormErrors($form);
        $form.find('[name="name"], [name="notes"]').val('');
    }

    function resetInlineCashboxForm($form) {
        clearFormErrors($form);
        $form.find('[name="name"], [name="notes"]').val('');
    }

    function initBankAccountInline() {
        const $bankSelect = $('#bank_doc_num.js-bank-select');

        if ($bankSelect.length === 0) {
            return;
        }

        $(document)
            .off('click.financeBankInlineOpen', '.js-bank-inline-create')
            .on('click.financeBankInlineOpen', '.js-bank-inline-create', function () {
                const $modal = $($(this).data('modal'));
                const $form = $modal.find('form');
                resetInlineBankForm($form);

                const modal = modalApi($modal);
                if (modal) {
                    modal.show();
                    return;
                }

                $modal.modal('show');
            })
            .off('submit.financeBankInline', '.js-bank-inline-form')
            .on('submit.financeBankInline', '.js-bank-inline-form', function (event) {
                event.preventDefault();

                const $form = $(this);
                const targetSelector = String($form.data('target-select') || '');
                const $button = $form.find('[type="submit"]').first();

                clearFormErrors($form);
                setLoading($button, true);

                $.ajax({
                    url: $form.attr('action'),
                    method: 'POST',
                    data: $form.serialize(),
                    headers: headers()
                }).done(function (response) {
                    const option = response && response.data ? response.data.option : null;

                    selectOption($(targetSelector), option);

                    showToast('success', response.message || msg('saved'));
                    hideModal($form.closest('.modal'));
                }).fail(function (response) {
                    if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                        showValidationErrors($form, response.responseJSON.errors);
                        return;
                    }

                    showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'), 'danger');
                }).always(function () {
                    setLoading($button, false);
                });
            })
            .off('input.financeBankInlineValidation change.financeBankInlineValidation', '.js-bank-inline-form input, .js-bank-inline-form textarea')
            .on('input.financeBankInlineValidation change.financeBankInlineValidation', '.js-bank-inline-form input, .js-bank-inline-form textarea', function () {
                const $input = $(this);
                const field = ($input.attr('name') || '').replace('[]', '');

                $input.removeClass('is-invalid');
                $input.closest('form').find('[data-error-for="' + field + '"]').text('');
            });
    }

    function initCashboxAccountGroupInline() {
        const $cashboxGroupSelect = $('#parent_account_doc_num.js-cashbox-group-select');

        if ($cashboxGroupSelect.length === 0) {
            return;
        }

        $(document)
            .off('click.financeCashboxInlineOpen', '.js-cashbox-inline-create')
            .on('click.financeCashboxInlineOpen', '.js-cashbox-inline-create', function () {
                const $modal = $($(this).data('modal'));
                const $form = $modal.find('form');
                resetInlineCashboxForm($form);

                const modal = modalApi($modal);
                if (modal) {
                    modal.show();
                    return;
                }

                $modal.modal('show');
            })
            .off('submit.financeCashboxInline', '.js-cashbox-inline-form')
            .on('submit.financeCashboxInline', '.js-cashbox-inline-form', function (event) {
                event.preventDefault();

                const $form = $(this);
                const targetSelector = String($form.data('target-select') || '');
                const $button = $form.find('[type="submit"]').first();

                clearFormErrors($form);
                setLoading($button, true);

                $.ajax({
                    url: $form.attr('action'),
                    method: 'POST',
                    data: $form.serialize(),
                    headers: headers()
                }).done(function (response) {
                    const option = response && response.data ? response.data.option : null;

                    selectOption($(targetSelector), option);

                    showToast('success', response.message || msg('saved'));
                    hideModal($form.closest('.modal'));
                }).fail(function (response) {
                    if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                        showValidationErrors($form, response.responseJSON.errors);
                        return;
                    }

                    showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'), 'danger');
                }).always(function () {
                    setLoading($button, false);
                });
            })
            .off('input.financeCashboxInlineValidation change.financeCashboxInlineValidation', '.js-cashbox-inline-form input, .js-cashbox-inline-form textarea')
            .on('input.financeCashboxInlineValidation change.financeCashboxInlineValidation', '.js-cashbox-inline-form input, .js-cashbox-inline-form textarea', function () {
                const $input = $(this);
                const field = ($input.attr('name') || '').replace('[]', '');

                $input.removeClass('is-invalid');
                $input.closest('form').find('[data-error-for="' + field + '"]').text('');
            });
    }

    function initDeleteActions() {
        $(document).off('click.financeDelete', '.js-delete-record[data-delete-url]').on('click.financeDelete', '.js-delete-record[data-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('delete-url');
            const docNum = String($button.data('doc-num') || '').trim();

            if (!url) {
                showToast('error', msg('unexpectedError'));
                return;
            }

            confirmDialog({
                title: msg('deleteConfirmTitle'),
                text: msg('deleteConfirmText'),
                confirmButtonText: msg('deleteConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: url,
                    method: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response.message);

                    if ($('.js-finance-table').length > 0) {
                        $(document).trigger('finance:deleted', [docNum]);
                        return;
                    }

                    if ($button.data('redirect-url')) {
                        window.location.href = $button.data('redirect-url');
                    }
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'));
                });
            });
        });
    }

    function initRestoreActions() {
        $(document).off('click.financeRestore', '.js-restore-record[data-restore-url]').on('click.financeRestore', '.js-restore-record[data-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('restore-url');
            const docNum = String($button.data('doc-num') || '').trim();

            if (!url) {
                showToast('error', msg('unexpectedError'));
                return;
            }

            confirmDialog({
                title: msg('restoreConfirmTitle'),
                text: msg('restoreConfirmText'),
                confirmButtonText: msg('restoreConfirmYes'),
                confirmButtonColor: '#00a65a'
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
                    showToast('success', response.message);

                    if ($('.js-finance-table').length > 0) {
                        $(document).trigger('finance:restored', [docNum]);
                        return;
                    }

                    window.location.reload();
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'));
                }).always(function () {
                    setLoading($button, false);
                });
            });
        });
    }

    initTable();
    initSelect2();
    initForm();
    initDocumentNumberSettings();
    initBankAccountInline();
    initCashboxAccountGroupInline();
    initDeleteActions();
    initRestoreActions();
})(jQuery, window, document);
