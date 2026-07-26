(function ($, window) {
    'use strict';

    const messages = window.hrEmployeesMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

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

    function formFieldNames($form) {
        const names = [];

        $form.find('input[name], select[name], textarea[name]').each(function () {
            const name = String($(this).attr('name') || '');

            if (['_token', '_method', 'submit_action', 'clone_source_token'].indexOf(name) !== -1) {
                return;
            }

            if (names.indexOf(name) === -1) {
                names.push(name);
            }
        });

        return names;
    }

    function fieldValue($form, name) {
        const $checkbox = $form.find('[name="' + name + '"][type="checkbox"]');

        if ($checkbox.length > 0) {
            return $checkbox.is(':checked') ? '1' : '0';
        }

        return String($form.find('[name="' + name + '"]').val() || '').trim();
    }

    function currentFormData($form) {
        const data = {};

        formFieldNames($form).forEach(function (name) {
            data[name] = fieldValue($form, name);
        });

        return data;
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};
        const data = {};

        formFieldNames($form).forEach(function (name) {
            data[name] = original[name] === null || original[name] === undefined ? '' : String(original[name]).trim();
        });

        return data;
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        const original = originalFormData($form);
        const current = currentFormData($form);

        return Object.keys(current).some(function (field) {
            const $input = $form.find('[name="' + field + '"]').first();

            if ($input.is('[data-numeric-input]') && window.AppNumbers && typeof window.AppNumbers.same === 'function') {
                return !window.AppNumbers.same(original[field], current[field]);
            }

            return String(original[field] || '') !== String(current[field] || '');
        });
    }

    function alertElement($form, selector, messageSelector) {
        let $alert = $form.find(selector).first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none ' + selector.replace('.', '') + '" role="alert"><span class="' + messageSelector.replace('.', '') + '"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || '') + '"></button></div>');
            $form.find('.js-hr-employees-form-body, .card-body').first().prepend($alert);
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

    function errorKeyToInputName(field) {
        const parts = String(field || '').split('.');

        if (parts.length < 2) {
            return String(field || '');
        }

        return parts[0] + parts.slice(1).map(function (part) {
            return '[' + part + ']';
        }).join('');
    }

    function inputNameToErrorKey(name) {
        return String(name || '')
            .replace(/\[\]/g, '')
            .replace(/\]/g, '')
            .replace(/\[/g, '.');
    }

    function fieldErrorTargets(field) {
        const inputName = errorKeyToInputName(field);
        const baseField = String(field || '').replace(/\.\d+\.[^.]+$/, '');

        return {
            inputName: inputName,
            baseField: baseField
        };
    }

    function showValidationErrors($form, errors, alertSelector, messageSelector) {
        const $alert = alertElement($form, alertSelector || '.js-hr-employees-alert', messageSelector || '.js-hr-employees-alert-message');
        const $list = $('<ul class="mb-0 ps-3"></ul>');

        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');

        validationMessages(errors).forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        $alert
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find(messageSelector || '.js-hr-employees-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const targets = fieldErrorTargets(field);
            let $input = $form.find('[name="' + targets.inputName + '"], [name="' + targets.inputName + '[]"]');

            if ($input.length === 0) {
                $input = $form.find('[name="' + field + '"], [name="' + field + '[]"]');
            }

            if ($input.length === 0 && targets.baseField !== field) {
                $input = $form.find('[name^="' + errorKeyToInputName(targets.baseField) + '"]');
            }

            $input.addClass('is-invalid');
            $input.filter('.js-hr-document-file-input').closest('.hr-document-file-control').find('.js-hr-document-file-display').addClass('is-invalid');
            $form.find('[data-error-for="' + field + '"]').text(message || '');
        });

        const $firstInvalidPane = $form.find('.is-invalid').first().closest('.tab-pane');

        if ($firstInvalidPane.length > 0 && window.bootstrap && window.bootstrap.Tab) {
            const selector = '[data-bs-target="#' + $firstInvalidPane.attr('id') + '"]';
            const trigger = document.querySelector(selector);

            if (trigger) {
                window.bootstrap.Tab.getOrCreateInstance(trigger).show();
            }
        }
    }

    function showFormNotice($form, message, type) {
        alertElement($form, '.js-hr-employees-alert', '.js-hr-employees-alert-message')
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .find('.js-hr-employees-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form, alertSelector, messageSelector) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form, alertSelector || '.js-hr-employees-alert', messageSelector || '.js-hr-employees-alert-message')
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find(messageSelector || '.js-hr-employees-alert-message')
            .empty();
    }

    function setLoading($button, loading) {
        $button.prop('disabled', loading);
        $button.css('cursor', loading ? 'wait' : '');
        $('body').css('cursor', loading ? 'wait' : '');
    }

    function updateOriginalFormData($form) {
        $form.data('original', currentFormData($form));
    }

    function setSelect2Option($select, value, text, extraData) {
        if (!value) {
            $select.val(null).trigger('change');
            return;
        }

        let $option = $select.find('option[value="' + value + '"]');

        if ($option.length === 0) {
            $option = $(new Option(text || value, value, true, true));
            $select.append($option);
        }

        Object.keys(extraData || {}).forEach(function (key) {
            $option.attr('data-' + key.replace(/_/g, '-'), extraData[key]);
            $option.data(key.replace(/_/g, '-'), extraData[key]);
        });

        $select.val(value).trigger('change');
    }

    function restoreDefaultPayrollCurrency($form) {
        const $currency = $form.find('[name="payroll_currency_doc_num"]');

        if ($currency.length === 0) {
            return;
        }

        setSelect2Option(
            $currency,
            String($form.data('default-payroll-currency-doc-num') || ''),
            String($form.data('default-payroll-currency-text') || ''),
            { is_main: 1 }
        );
    }

    function resetCreateForm($form) {
        formFieldNames($form).forEach(function (name) {
            const $fields = $form.find('[name="' + name + '"]');
            const $checkbox = $fields.filter('[type="checkbox"]');

            if (name === 'status') {
                $fields.val('active');
                return;
            }

            if (name === 'person_type') {
                $fields.val('fixed_employee');
                return;
            }

            if ($checkbox.length > 0) {
                $checkbox.prop('checked', name === 'attendance_tracking_enabled');
                return;
            }

            $fields.val('');
        });

        $form.find('.js-select2-ajax').val(null).trigger('change');
        resetDynamicRows($form);
        resetEmployeePhotoPicker($form);
        resetEmployeeSignaturePicker($form);
        restoreDefaultPayrollCurrency($form);
        applyMainCurrencyExchangeRate($form);
        updatePayAmountFields($form, false);
        $form.find('[name="submit_action"]').val('save');
        $form.find('[name="clone_source_token"]').remove();
        updateOriginalFormData($form);
    }

    function resetDynamicRows($form) {
        const $biometricRows = $form.find('.js-hr-biometric-rows');
        const $documentRows = $form.find('.js-hr-document-rows');

        $biometricRows.find('.js-hr-biometric-row').remove();
        $biometricRows.data('next-index', 0);
        updateBiometricEmptyState($biometricRows);

        $documentRows.find('.js-hr-document-row').remove();
        $documentRows.data('next-index', 0);
        updateDocumentEmptyState($documentRows);
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

        if (window.history && window.location.pathname.indexOf(data.old_doc_num) !== -1) {
            const newPath = window.location.pathname.replace(data.old_doc_num, data.doc_num);
            window.history.replaceState({}, '', newPath + window.location.search + window.location.hash);
        }
    }

    function selectedCurrencyIsMain($form) {
        const mainCurrencyDocNum = String($form.data('main-currency-doc-num') || '');
        const $currency = $form.find('[name="payroll_currency_doc_num"]');
        const selected = $currency.select2 && $currency.data('select2') ? $currency.select2('data')[0] : null;

        return Boolean(
            (selected && selected.is_main)
            || ($currency.val() && String($currency.val()) === mainCurrencyDocNum)
            || $currency.find('option:selected').data('is-main') === 1
        );
    }

    function applyMainCurrencyExchangeRate($form) {
        const $exchangeRate = $form.find('.js-hr-employee-exchange-rate');

        if ($exchangeRate.length === 0) {
            return;
        }

        if (selectedCurrencyIsMain($form)) {
            $exchangeRate.val('1').prop('readonly', true);
            return;
        }

        $exchangeRate.prop('readonly', false);
    }

    function updatePayAmountFields($form, clearHidden) {
        const payBasis = String($form.find('.js-hr-pay-basis, [name="pay_basis"]').first().val() || '');

        $form.find('.js-hr-pay-amount-field').each(function () {
            const $field = $(this);
            const isActive = payBasis !== '' && String($field.data('pay-basis') || '') === payBasis;

            $field.toggleClass('d-none', !isActive);

            if (!isActive && clearHidden) {
                $field.find('.js-hr-pay-amount-input').val('');
            }
        });
    }

    function initDynamicDatePickers(root) {
        if (typeof window.flatpickr !== 'function') {
            return;
        }

        $(root || document).find('.datetimepicker').each(function () {
            if (this._flatpickr) {
                return;
            }

            let options = {};

            try {
                options = $(this).data('options') || {};
            } catch (error) {
                options = {};
            }

            window.flatpickr(this, options);
        });

        if (window.AppDatePicker && typeof window.AppDatePicker.init === 'function') {
            window.AppDatePicker.init(root || document);
        }
    }

    function initDynamicSelect2(root) {
        if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
            window.AppSelect2Ajax.init(root || document);
        }
    }

    function initTable() {
        const $table = $('.js-hr-employees-table').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) { return options; };
        const protectedColumns = [0, 1, -1];
        const responsiveControlTarget = 1;
        const selectedDocNums = new Set();
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-hr-employees-row-checkbox';
        const selectAllSelector = '#select_all_records';
        const trashFilterSelector = '#hr_employees_trash_filter';
        const deletedColumnIndexes = [21, 22];

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            return api && typeof api.rows === 'function'
                ? $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-hr-employees-row-checkbox')
                : $table.find(rowCheckboxSelector);
        }

        function trashFilterValue() {
            const value = String($(trashFilterSelector).val() || 'active');

            return ['active', 'inactive', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
        }

        function protectedStateIndexes(data) {
            const total = data && data.columns ? data.columns.length : 0;

            return protectedColumns.map(function (index) {
                return index < 0 ? total + index : index;
            });
        }

        function protectStateColumns(data) {
            if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
                window.AppDataTables.protectStateColumns(data, protectedColumns);
                return;
            }

            protectedStateIndexes(data).forEach(function (index) {
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

            [0, 1, api.columns().count() - 1].forEach(function (index) {
                api.column(index).visible(true, false);
            });

            api.columns.adjust();
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
            const $checkboxes = pageCheckboxes(api);
            const selectableDocNums = $checkboxes.map(function () { return checkboxDocNum(this); }).get().filter(Boolean);
            const checkedOnPage = selectableDocNums.filter(function (docNum) { return selectedDocNums.has(docNum); }).length;

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
            table.ajax.reload(null, false);
        }

        function toggleDeletedColumns(api) {
            const showDeletedColumns = ['trashed', 'all'].indexOf(trashFilterValue()) !== -1;

            deletedColumnIndexes.forEach(function (index) {
                api.column(index).visible(showDeletedColumns, false);
            });

            api.columns.adjust();
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
                    data.trash_filter = trashFilterValue();
                }
            },
            responsive: { details: { type: 'inline', target: responsiveControlTarget } },
            order: [[1, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: 'hr_employees.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'avatar', name: 'avatar', orderable: false, searchable: false, className: 'align-middle white-space-nowrap text-center', responsivePriority: 3 },
                { data: 'full_name', name: 'hr_employees.full_name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'national_id', name: 'hr_employees.national_id', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'person_type', name: 'hr_employees.person_type', className: 'align-middle white-space-nowrap' },
                { data: 'branch', name: 'branch', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'department', name: 'department', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'job_section', name: 'job_section', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'job', name: 'job', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'job_type', name: 'job_type', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'pay_basis', name: 'hr_employees.pay_basis', className: 'align-middle white-space-nowrap' },
                { data: 'pay_amount', name: 'pay_amount', className: 'align-middle white-space-nowrap text-end' },
                { data: 'payroll_currency', name: 'payroll_currency', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'status', name: 'hr_employees.status', className: 'align-middle white-space-nowrap' },
                { data: 'biometric_indicator', name: 'biometric_indicator', orderable: false, searchable: false, className: 'align-middle white-space-nowrap text-center' },
                { data: 'end_date', name: 'hr_employees.end_date', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'deleted_by', name: 'deleted_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'deleted_at', name: 'deleted_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions' }
            ],
            columnDefs: [
                { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
                { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 3, searchable: false, targets: -1 },
                { responsivePriority: 10, targets: [2, 3] },
                { responsivePriority: 20, targets: [4, 5, 14] },
                { responsivePriority: 30, targets: [6, 7, 8, 9, 10, 11, 12, 13, 15, 16, 17, 18, 19, 20] },
                { visible: false, targets: deletedColumnIndexes }
            ],
            createdRow: function (row) {
                $(row).addClass('btn-reveal-trigger');
            },
            initComplete: function () {
                showProtectedColumns(this.api());
                toggleDeletedColumns(this.api());
                restoreSelectionState(this.api());
            },
            drawCallback: function () {
                toggleDeletedColumns(this.api());
                restoreSelectionState(this.api());

                if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                    window.AppDataTables.applyFalconEnhancements(document);
                }
            }
        }));

        $(trashFilterSelector).off('change.hrEmployeesTrashFilter').on('change.hrEmployeesTrashFilter', function () {
            clearSelection(table);
            toggleDeletedColumns(table);
            reloadTable();
        });

        $(selectAllSelector).off('change.hrEmployeesSelect').on('change.hrEmployeesSelect', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(table).each(function () {
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

            updateSelectAllState(table);
            updateBulkActionsUi();
        });

        $table.off('change.hrEmployeesSelect', rowCheckboxSelector).on('change.hrEmployeesSelect', rowCheckboxSelector, function () {
            const docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(table);
            updateBulkActionsUi();
        });

        $table.off('click.hrEmployeesSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.hrEmployeesSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-hr-employees-row-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $(document)
            .off('click.hrEmployeesSelectStop mousedown.hrEmployeesSelectStop mouseup.hrEmployeesSelectStop', '.js-record-select, #select_all_records, td.dt-select')
            .on('click.hrEmployeesSelectStop mousedown.hrEmployeesSelectStop mouseup.hrEmployeesSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
                event.stopPropagation();
            });

        $table.off('dblclick.hrEmployeesEditRow', 'tbody tr:not(.child)').on('dblclick.hrEmployeesEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $(document).off('hrEmployees:deleted.hrEmployeesTable hrEmployees:restored.hrEmployeesTable')
            .on('hrEmployees:deleted.hrEmployeesTable hrEmployees:restored.hrEmployeesTable', function (event, docNum) {
                if (docNum) {
                    selectedDocNums.delete(docNum);
                }

                reloadTable();
                updateSelectAllState(table);
                updateBulkActionsUi();
            });

        $('#bulk_action_apply').off('click.hrEmployeesBulk').on('click.hrEmployeesBulk', function () {
            const docNums = Array.from(selectedDocNums);
            const action = String($('#bulk_action_select').val() || 'delete');

            if (docNums.length === 0) {
                updateBulkActionsUi();
                return;
            }

            if (action === 'restore') {
                confirmDialog({
                    title: messages.bulkRestoreConfirmTitle,
                    text: (messages.bulkRestoreConfirmText || '').replace(':count', docNums.length),
                    confirmButtonText: messages.bulkRestoreConfirmYes || messages.restore,
                    confirmButtonColor: '#00a65a'
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    $.ajax({
                        url: $table.data('bulk-restore-url'),
                        method: 'PATCH',
                        data: { doc_nums: docNums },
                        headers: headers()
                    }).done(function (response) {
                        clearSelection(table);
                        reloadTable();
                        showToast('success', response.message);
                    }).fail(function (response) {
                        showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    });
                });

                return;
            }

            if (['activate', 'deactivate'].indexOf(action) !== -1) {
                const activating = action === 'activate';

                confirmDialog({
                    title: messages.bulkStatusConfirmTitle,
                    text: (activating ? (messages.bulkActivateConfirmText || '') : (messages.bulkDeactivateConfirmText || '')).replace(':count', docNums.length),
                    confirmButtonText: activating ? messages.bulkActivateConfirmYes : messages.bulkDeactivateConfirmYes,
                    confirmButtonColor: activating ? '#00a65a' : '#d33'
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    $.ajax({
                        url: $table.data('bulk-status-url'),
                        method: 'PATCH',
                        data: {
                            doc_nums: docNums,
                            status: activating ? 'active' : 'inactive'
                        },
                        headers: headers()
                    }).done(function (response) {
                        clearSelection(table);
                        reloadTable();
                        showToast('success', response.message);
                    }).fail(function (response) {
                        showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    });
                });

                return;
            }

            confirmDialog({
                title: messages.bulkDeleteConfirmTitle,
                text: (messages.bulkDeleteConfirmText || '').replace(':count', docNums.length),
                confirmButtonText: messages.bulkDeleteConfirmYes
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: $table.data('bulk-delete-url'),
                    method: 'DELETE',
                    data: { doc_nums: docNums },
                    headers: headers()
                }).done(function (response) {
                    clearSelection(table);
                    reloadTable();
                    showToast('success', response.message);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function initDeleteActions() {
        $(document).off('click.hrEmployeesDelete', '[data-hr-employees-delete-url]').on('click.hrEmployeesDelete', '[data-hr-employees-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('hr-employees-delete-url');
            const docNum = String($button.data('doc-num') || '').trim();

            confirmDialog({
                title: messages.deleteConfirmTitle,
                text: messages.deleteConfirmText,
                confirmButtonText: messages.deleteConfirmYes
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({ url: url, method: 'DELETE', headers: headers() }).done(function (response) {
                    showToast('success', response.message);

                    if ($('.js-hr-employees-table').length > 0) {
                        $(document).trigger('hrEmployees:deleted', [docNum]);
                        return;
                    }

                    window.location.href = $button.data('redirect-url') || $('[data-shortcut-action="form.back"]').attr('href') || '/';
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function initRestoreActions() {
        $(document).off('click.hrEmployeesRestore', '[data-hr-employees-restore-url]').on('click.hrEmployeesRestore', '[data-hr-employees-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('hr-employees-restore-url');
            const docNum = String($button.data('doc-num') || '').trim();

            confirmDialog({
                title: messages.restoreConfirmTitle,
                text: messages.restoreConfirmText,
                confirmButtonText: messages.restoreConfirmYes || messages.restore,
                confirmButtonColor: '#00a65a'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                setLoading($button, true);

                $.ajax({ url: url, method: 'PATCH', headers: headers() }).done(function (response) {
                    showToast('success', response.message);

                    if ($('.js-hr-employees-table').length > 0) {
                        $(document).trigger('hrEmployees:restored', [docNum]);
                        return;
                    }

                    window.location.reload();
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    setLoading($button, false);
                });
            });
        });
    }

    function initForm() {
        $(document)
            .off('click.hrEmployeesSubmitAction', '.js-hr-employees-submit-action')
            .on('click.hrEmployeesSubmitAction', '.js-hr-employees-submit-action', function () {
                const $button = $(this);
                const action = String($button.data('submit-action') || 'save');

                $button.closest('form').find('[name="submit_action"]').val(action);
                $button.closest('form').data('submit-button', $button);
            });

        $(document).off('submit.hrEmployeesForm', '.js-hr-employees-form').on('submit.hrEmployeesForm', '.js-hr-employees-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();

            clearFormErrors($form);

            if (!hasChanges($form)) {
                showFormNotice($form, messages.noChanges, 'warning');
                showToast('info', messages.noChanges);
                return;
            }

            setLoading($button, true);

            $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                if (response && response.success === false && response.type === 'no_changes') {
                    showFormNotice($form, response.message || messages.noChanges, 'warning');
                    showToast('info', response.message || messages.noChanges);
                    return;
                }

                updateUrlsAfterDocNumberChange($form, response);
                showToast('success', response.message);

                if (response && response.reset_form) {
                    resetCreateForm($form);
                    applyMainCurrencyExchangeRate($form);

                    if (Object.prototype.hasOwnProperty.call(response, 'next_doc_number') && response.next_doc_number !== null && String(response.next_doc_number) !== '') {
                        $form.find('[name="doc_number"]').val(String(response.next_doc_number));
                    }

                    updateOriginalFormData($form);
                } else {
                    updateOriginalFormData($form);
                }

                if (response && response.redirect) {
                    window.location.href = response.redirect;
                }
            }).fail(function (response) {
                if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                    showValidationErrors($form, response.responseJSON.errors, '.js-hr-employees-alert', '.js-hr-employees-alert-message');
                    return;
                }

                showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
            }).always(function () {
                setLoading($button, false);
            });
        });

        $(document).off('input.hrEmployeesForm change.hrEmployeesForm', '.js-hr-employees-form .is-invalid').on('input.hrEmployeesForm change.hrEmployeesForm', '.js-hr-employees-form .is-invalid', function () {
            const $input = $(this);
            const field = inputNameToErrorKey(($input.attr('name') || '').replace('[]', ''));

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"]').text('');
        });

        $(document)
            .off('change.hrEmployeesCurrency select2:select.hrEmployeesCurrency select2:clear.hrEmployeesCurrency', '.js-hr-employees-form [name="payroll_currency_doc_num"]')
            .on('change.hrEmployeesCurrency select2:select.hrEmployeesCurrency select2:clear.hrEmployeesCurrency', '.js-hr-employees-form [name="payroll_currency_doc_num"]', function () {
                applyMainCurrencyExchangeRate($(this).closest('form'));
            });

        $(document)
            .off('change.hrEmployeesPayBasis', '.js-hr-employees-form .js-hr-pay-basis')
            .on('change.hrEmployeesPayBasis', '.js-hr-employees-form .js-hr-pay-basis', function () {
                updatePayAmountFields($(this).closest('form'), true);
            });

        $('.js-hr-employees-form').each(function () {
            applyMainCurrencyExchangeRate($(this));
            updatePayAmountFields($(this), false);
        });
    }

    function initDocumentNumberSettings() {
        $(document)
            .off('submit.hrEmployeesDocSettings', '.js-hr-employees-document-number-settings-form')
            .on('submit.hrEmployeesDocSettings', '.js-hr-employees-document-number-settings-form', function (event) {
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

                    if (Object.prototype.hasOwnProperty.call(data, 'prefix')) {
                        $form.find('[name="prefix"]').val(data.prefix || '');
                    }

                    if (Object.prototype.hasOwnProperty.call(data, 'padding')) {
                        $form.find('[name="padding"]').val(data.padding);
                    }

                    showToast('success', response.message);
                }).fail(function (response) {
                    if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                        showValidationErrors($form, response.responseJSON.errors, '.js-hr-employees-alert', '.js-hr-employees-alert-message');
                        return;
                    }

                    showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
                }).always(function () {
                    setLoading($button, false);
                });
            });
    }

    function initDocumentForm() {
        $(document)
            .off('submit.hrEmployeeDocumentForm', '.js-hr-employee-document-form')
            .on('submit.hrEmployeeDocumentForm', '.js-hr-employee-document-form', function (event) {
                event.preventDefault();

                const $form = $(this);
                const $button = $form.find('[type="submit"]');

                clearFormErrors($form, '.js-hr-employee-document-alert', '.js-hr-employee-document-alert-message');
                setLoading($button, true);

                $.ajax({
                    url: $form.attr('action'),
                    method: 'POST',
                    data: $form.serialize(),
                    headers: headers()
                }).done(function (response) {
                    const row = response && response.data ? response.data.row : null;

                    if (row) {
                        $('.js-hr-employee-documents-empty').remove();
                        $('.js-hr-employee-documents-table tbody').prepend(row);
                    }

                    $form[0].reset();
                    $form.find('.js-select2-ajax').val(null).trigger('change');
                    $form.find('.js-hr-document-file-name').text(messages.documentFileRequired || '');
                    showToast('success', response.message);
                }).fail(function (response) {
                    if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                        showValidationErrors($form, response.responseJSON.errors, '.js-hr-employee-document-alert', '.js-hr-employee-document-alert-message');
                        return;
                    }

                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    setLoading($button, false);
                });
            });

        $(document)
            .off('click.hrEmployeeDocumentDelete', '[data-hr-employee-document-delete-url]')
            .on('click.hrEmployeeDocumentDelete', '[data-hr-employee-document-delete-url]', function () {
                const $button = $(this);
                const $row = $button.closest('tr');

                confirmDialog({
                    title: messages.documentDeleteConfirmTitle,
                    text: messages.documentDeleteConfirmText,
                    confirmButtonText: messages.documentDeleteConfirmYes
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    setLoading($button, true);

                    $.ajax({
                        url: $button.data('hr-employee-document-delete-url'),
                        method: 'DELETE',
                        headers: headers()
                    }).done(function (response) {
                        $row.remove();

                        if ($('.js-hr-employee-documents-table tbody tr').length === 0) {
                            $('.js-hr-employee-documents-table tbody').append('<tr class="js-hr-employee-documents-empty"><td colspan="9" class="text-center text-600 py-4">' + (messages.emptyDocuments || '') + '</td></tr>');
                        }

                        showToast('success', response.message);
                    }).fail(function (response) {
                        showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    }).always(function () {
                        setLoading($button, false);
                    });
                });
            });
    }

    function employeePhotoElements($field) {
        return {
            image: $field.find('.js-product-image-preview-image').first(),
            placeholder: $field.find('.js-product-image-placeholder').first(),
            fileName: $field.find('.js-product-image-file-name').first(),
            removeButton: $field.find('.js-product-image-remove').first()
        };
    }

    function clearEmployeePhotoPreview($field) {
        const elements = employeePhotoElements($field);

        elements.image
            .off('load.hrEmployeePhoto error.hrEmployeePhoto')
            .attr('src', '')
            .addClass('d-none');
        elements.placeholder.removeClass('d-none');
        elements.fileName.text($field.data('no-image-label') || messages.noPhotoSelected || '');
        elements.removeButton.addClass('d-none');
    }

    function renderEmployeePhotoPreview($field, file, payload) {
        const elements = employeePhotoElements($field);
        const image = elements.image.get(0);
        const src = String(
            (file && (file.thumbnail_url || file.url))
            || (payload && (payload.thumbnail_url || payload.url))
            || ''
        ).trim();
        const fileName = String(
            (file && (file.name || file.original_name))
            || (payload && (payload.name || payload.original_name))
            || ''
        ).trim();

        if (!src || !image) {
            clearEmployeePhotoPreview($field);
            return;
        }

        elements.image
            .off('load.hrEmployeePhoto error.hrEmployeePhoto')
            .addClass('w-100 h-100 object-fit-contain d-none')
            .one('load.hrEmployeePhoto', function () {
                $(this).removeClass('d-none');
                elements.placeholder.addClass('d-none');
            })
            .one('error.hrEmployeePhoto', function () {
                clearEmployeePhotoPreview($field);
                elements.fileName.text(fileName || $field.data('existing-label') || '');
                elements.removeButton.removeClass('d-none');
            })
            .attr('alt', fileName || elements.image.attr('alt') || '')
            .attr('src', src);

        elements.placeholder.addClass('d-none');
        elements.fileName.text(fileName || $field.data('existing-label') || '');
        elements.removeButton.removeClass('d-none');

        if (image.complete && image.naturalWidth > 0) {
            elements.image.triggerHandler('load.hrEmployeePhoto');
        } else if (image.complete && image.naturalWidth === 0) {
            elements.image.triggerHandler('error.hrEmployeePhoto');
        }
    }

    function resetEmployeePhotoPicker($form) {
        const $field = $form.find('#hr-employee-photo-picker-field');

        if ($field.length === 0) {
            return;
        }

        $form.find('[name="photo_archive_file_doc_num"]').val('').trigger('change');
        clearEmployeePhotoPreview($field);
    }

    function resetEmployeeSignaturePicker($form) {
        const $field = $form.find('#hr-employee-signature-picker-field');

        if ($field.length === 0) {
            return;
        }

        $form.find('[name="signature_archive_file_doc_num"]').val('').trigger('change');
        clearEmployeePhotoPreview($field);
    }

    function handleEmployeePhotoSelected(payload) {
        const data = payload || {};
        const file = data.file || data;
        const config = data.config || {};

        if (config.collection !== 'employee_photo' || !file) {
            return;
        }

        const publicId = String(file.public_id || data.public_id || '').trim();
        const $hidden = config.targetInput ? $(config.targetInput).first() : $('[name="photo_archive_file_doc_num"]').first();
        const $form = $hidden.closest('form');
        const $field = config.uploader ? $(config.uploader).first() : $form.find('#hr-employee-photo-picker-field');

        if (publicId === '' || $hidden.length === 0 || $field.length === 0) {
            return;
        }

        $hidden.val(publicId).trigger('change').removeClass('is-invalid');
        $form.find('[data-error-for="photo_archive_file_doc_num"]').text('');
        renderEmployeePhotoPreview($field, file, data);
    }

    function handleEmployeePhotoDeleted(payload) {
        const data = payload || {};
        const config = data.config || {};

        if (config.collection !== 'employee_photo' || !data.was_selected) {
            return;
        }

        const $hidden = config.targetInput ? $(config.targetInput).first() : $('[name="photo_archive_file_doc_num"]').first();
        const $form = $hidden.closest('form');
        const $field = config.uploader ? $(config.uploader).first() : $form.find('#hr-employee-photo-picker-field');

        $hidden.val('').trigger('change');
        clearEmployeePhotoPreview($field);
    }

    function handleEmployeeSignatureSelected(payload) {
        const data = payload || {};
        const file = data.file || data;
        const config = data.config || {};

        if (config.collection !== 'employee_signature' || !file) {
            return;
        }

        const publicId = String(file.public_id || data.public_id || '').trim();
        const $hidden = config.targetInput ? $(config.targetInput).first() : $('[name="signature_archive_file_doc_num"]').first();
        const $form = $hidden.closest('form');
        const $field = config.uploader ? $(config.uploader).first() : $form.find('#hr-employee-signature-picker-field');

        if (publicId === '' || $hidden.length === 0 || $field.length === 0) {
            return;
        }

        $hidden.val(publicId).trigger('change').removeClass('is-invalid');
        $form.find('[data-error-for="signature_archive_file_doc_num"]').text('');
        renderEmployeePhotoPreview($field, file, data);
    }

    function handleEmployeeSignatureDeleted(payload) {
        const data = payload || {};
        const config = data.config || {};

        if (config.collection !== 'employee_signature' || !data.was_selected) {
            return;
        }

        const $hidden = config.targetInput ? $(config.targetInput).first() : $('[name="signature_archive_file_doc_num"]').first();
        const $form = $hidden.closest('form');
        const $field = config.uploader ? $(config.uploader).first() : $form.find('#hr-employee-signature-picker-field');

        $hidden.val('').trigger('change');
        clearEmployeePhotoPreview($field);
    }

    function initFilePickerIntegrations() {
        $(document)
            .off('file-picker:selected.hrEmployeePhoto', '.js-product-image-picker-trigger')
            .on('file-picker:selected.hrEmployeePhoto', '.js-product-image-picker-trigger', function (event, payload) {
                handleEmployeePhotoSelected(payload);
            })
            .off('file-picker:deleted.hrEmployeePhoto', '.js-product-image-picker-trigger')
            .on('file-picker:deleted.hrEmployeePhoto', '.js-product-image-picker-trigger', function (event, payload) {
                handleEmployeePhotoDeleted(payload);
            })
            .off('click.hrEmployeePhotoClear', '.js-product-image-remove')
            .on('click.hrEmployeePhotoClear', '.js-product-image-remove', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const $form = $(this).closest('form');
                const $field = $(this).closest('.js-product-image-picker-field');

                $form.find('[name="photo_archive_file_doc_num"]').val('').trigger('change');
                clearEmployeePhotoPreview($field);
            })
            .off('file-picker:selected.hrEmployeeSignature', '.js-product-image-picker-trigger')
            .on('file-picker:selected.hrEmployeeSignature', '.js-product-image-picker-trigger', function (event, payload) {
                handleEmployeeSignatureSelected(payload);
            })
            .off('file-picker:deleted.hrEmployeeSignature', '.js-product-image-picker-trigger')
            .on('file-picker:deleted.hrEmployeeSignature', '.js-product-image-picker-trigger', function (event, payload) {
                handleEmployeeSignatureDeleted(payload);
            })
            .off('click.hrEmployeeSignatureClear', '.js-hr-employee-signature-field .js-product-image-remove')
            .on('click.hrEmployeeSignatureClear', '.js-hr-employee-signature-field .js-product-image-remove', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const $form = $(this).closest('form');
                const $field = $(this).closest('.js-product-image-picker-field');

                $form.find('[name="signature_archive_file_doc_num"]').val('').trigger('change');
                clearEmployeePhotoPreview($field);
            })
            .off('file-picker:selected.hrEmployeeDocument', '.js-hr-document-file-picker')
            .on('file-picker:selected.hrEmployeeDocument', '.js-hr-document-file-picker', function (event, payload) {
                const file = payload && payload.file ? payload.file : {};
                const $row = $(this).closest('.js-hr-document-row');

                if ($row.length > 0) {
                    $row.find('.js-hr-document-file-display').val(file.name || '');
                    $row.find('.js-hr-document-file-input, .js-hr-document-file-display').removeClass('is-invalid');
                    $row.find('[data-error-for$=".archive_file_doc_num"]').text('');

                    if (!$row.find('[name$="[file_label]"]').val()) {
                        $row.find('[name$="[file_label]"]').val(file.name || '');
                    }

                    return;
                }

                const $form = $(this).closest('form');

                $form.find('.js-hr-document-file-name').text(file.name || '');

                if (!$form.find('[name="title"]').val()) {
                    $form.find('[name="title"]').val(file.name || '');
                }
            });
    }

    function updateDocumentEmptyState($rows) {
        const $visibleRows = $rows.find('.js-hr-document-row').not('.d-none');

        $rows.find('.js-hr-document-empty').remove();

        if ($visibleRows.length === 0) {
            $rows.append('<tr class="js-hr-document-empty"><td colspan="8" class="text-center text-600 py-4">' + (messages.emptyDocuments || '') + '</td></tr>');
        }
    }

    function initDocumentRows() {
        $(document)
            .off('click.hrDocumentAdd', '.js-hr-document-add')
            .on('click.hrDocumentAdd', '.js-hr-document-add', function () {
                const template = document.getElementById('hr-document-row-template');
                const $rows = $(this).closest('.tab-pane').find('.js-hr-document-rows').first();

                if (!template || !$rows.length) {
                    return;
                }

                const index = Number($rows.data('next-index') || 0);
                const html = template.innerHTML.replace(/__INDEX__/g, String(index));
                const $row = $(html);

                $rows.find('.js-hr-document-empty').remove();
                $rows.append($row);
                $rows.data('next-index', index + 1);
                initDynamicSelect2($row.get(0));
                initDynamicDatePickers($row.get(0));
            })
            .off('click.hrDocumentRemove', '.js-hr-document-remove')
            .on('click.hrDocumentRemove', '.js-hr-document-remove', function () {
                const $row = $(this).closest('.js-hr-document-row');
                const $rows = $row.closest('.js-hr-document-rows');
                const hasId = String($row.find('[name$="[id]"]').val() || '').trim() !== '';

                if (hasId) {
                    $row.find('.js-hr-document-delete-flag').val('1').prop('disabled', false);
                    $row.find('input, select, textarea').not('[name$="[id]"], .js-hr-document-delete-flag').prop('disabled', true);
                    $row.addClass('d-none');
                    updateDocumentEmptyState($rows);
                    return;
                }

                $row.remove();
                updateDocumentEmptyState($rows);
            });
    }

    function updateBiometricEmptyState($rows) {
        const $visibleRows = $rows.find('.js-hr-biometric-row').not('.d-none');

        $rows.find('.js-hr-biometric-empty').remove();

        if ($visibleRows.length === 0) {
            $rows.append('<div class="text-center text-600 py-3 js-hr-biometric-empty">' + (messages.emptyBiometric || '') + '</div>');
        }
    }

    function initBiometricRows() {
        $(document)
            .off('click.hrBiometricAdd', '.js-hr-biometric-add')
            .on('click.hrBiometricAdd', '.js-hr-biometric-add', function () {
                const template = document.getElementById('hr-biometric-row-template');
                const $rows = $('.js-hr-biometric-rows').first();

                if (!template || !$rows.length) {
                    return;
                }

                const index = Number($rows.data('next-index') || 0);
                const html = template.innerHTML.replace(/__INDEX__/g, String(index));
                const $row = $(html);

                $rows.find('.js-hr-biometric-empty').remove();
                $rows.append($row);
                $rows.data('next-index', index + 1);
                initDynamicSelect2($row.get(0));
                initDynamicDatePickers($row.get(0));
            })
            .off('click.hrBiometricRemove', '.js-hr-biometric-remove')
            .on('click.hrBiometricRemove', '.js-hr-biometric-remove', function () {
                const $row = $(this).closest('.js-hr-biometric-row');
                const $rows = $row.closest('.js-hr-biometric-rows');
                const hasId = String($row.find('[name$="[id]"]').val() || '').trim() !== '';

                if (hasId) {
                    $row.find('.js-hr-biometric-delete-flag').val('1').prop('disabled', false);
                    $row.find('input, select, textarea').not('[name$="[id]"], .js-hr-biometric-delete-flag').prop('disabled', true);
                    $row.addClass('d-none');
                    updateBiometricEmptyState($rows);
                    return;
                }

                $row.remove();
                updateBiometricEmptyState($rows);
            });
    }

    initTable();
    initDeleteActions();
    initRestoreActions();
    initForm();
    initDocumentNumberSettings();
    initDocumentForm();
    initFilePickerIntegrations();
    initDocumentRows();
    initBiometricRows();

    $(window).on('pageshow', function (e) {
        if (e.originalEvent && e.originalEvent.persisted) {
            var $table = $('.js-hr-employees-table').first();
            if ($.fn.DataTable && $.fn.DataTable.isDataTable($table[0])) {
                $table.DataTable().ajax.reload(null, false);
            } else {
                initTable();
            }
        }
    });
})(jQuery, window);
