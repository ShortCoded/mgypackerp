(function ($, window) {
    'use strict';

    const messages = window.hrFoundationMessages || {};
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
            let name = String($(this).attr('name') || '');

            if (name.slice(-2) === '[]') {
                name = name.slice(0, -2);
            }

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
        const $multiCheckboxes = $form.find('[name="' + name + '[]"][type="checkbox"]');

        if ($multiCheckboxes.length > 0) {
            return $multiCheckboxes.filter(':checked').map(function () { return String(this.value || ''); }).get().join(',');
        }

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

        return Object.keys($.extend({}, original, current)).some(function (field) {
            const $input = $form.find('[name="' + field + '"]').first();

            if ($input.is('[data-numeric-input]') && window.AppNumbers && typeof window.AppNumbers.same === 'function') {
                return !window.AppNumbers.same(original[field], current[field]);
            }

            return String(original[field] || '') !== String(current[field] || '');
        });
    }

    function alertElement($form) {
        let $alert = $form.find('.js-hr-foundation-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-hr-foundation-alert" role="alert"><span class="js-hr-foundation-alert-message"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || '') + '"></button></div>');
            $form.find('.js-hr-foundation-form-body, .card-body').first().prepend($alert);
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

    function showValidationErrors($form, errors) {
        const $alert = alertElement($form);
        const $list = $('<ul class="mb-0 ps-3"></ul>');

        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');

        validationMessages(errors).forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        $alert
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-hr-foundation-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const normalizedField = field.replace(/\.\d+$/, '');
            const parts = field.split('.');
            const inputName = parts.length > 1
                ? parts[0] + parts.slice(1).map(function (part) { return '[' + part + ']'; }).join('')
                : normalizedField;
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const $input = $form.find('[name="' + inputName + '"], [name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $input.addClass('is-invalid');
            $form.find('[data-error-for="' + field + '"], [data-error-for="' + normalizedField + '"]').first().text(message || '');
        });
    }

    function reindexTaxBrackets($container) {
        $container.find('.js-tax-bracket-row').each(function (index) {
            const $row = $(this);
            $row.attr('data-tax-bracket-index', index);
            $row.find('.js-tax-bracket-sequence').text((messages.taxBracketSequence || '') + ' ' + (index + 1));

            $row.find('[name]').each(function () {
                const $field = $(this);
                const name = String($field.attr('name') || '').replace(/^tax_brackets\[\d+\]/, 'tax_brackets[' + index + ']');
                const columnMatch = name.match(/\[([^\]]+)]$/);

                $field.attr('name', name);

                if (columnMatch) {
                    const id = 'tax-bracket-' + index + '-' + columnMatch[1];
                    $field.attr('id', id);
                    $row.find('label[for$="-' + columnMatch[1] + '"]').attr('for', id);
                    $row.find('[data-error-for$=".' + columnMatch[1] + '"]').attr('data-error-for', 'tax_brackets.' + index + '.' + columnMatch[1]);
                }
            });
        });
    }

    function reindexInsuranceComponents($container) {
        $container.find('.js-insurance-component-row').each(function (index) {
            const $row = $(this);
            $row.attr('data-insurance-component-index', index);
            $row.find('.js-insurance-component-sequence').text(index + 1);

            $row.find('[name]').each(function () {
                const $field = $(this);
                const name = String($field.attr('name') || '').replace(/^insurance_components\[\d+\]/, 'insurance_components[' + index + ']');
                const columnMatch = name.match(/\[([^\]]+)]$/);

                $field.attr('name', name);

                if (columnMatch) {
                    const id = 'insurance-component-' + index + '-' + columnMatch[1];
                    $field.attr('id', id);
                    $row.find('label[for$="-' + columnMatch[1] + '"]').attr('for', id);
                    $row.find('[data-error-for$=".' + columnMatch[1] + '"]').attr('data-error-for', 'insurance_components.' + index + '.' + columnMatch[1]);
                }
            });
        });
    }

    function insuranceRate(value) {
        const number = Number.parseFloat(String(value || '0'));

        return Number.isFinite(number) ? number : 0;
    }

    function updateInsuranceComponentTotals($form) {
        let employeeTotal = 0;
        let employerTotal = 0;

        $form.find('.js-insurance-component-row').each(function () {
            const $row = $(this);
            const employeeRate = insuranceRate($row.find('[name$="[employee_rate]"]').val());
            const employerRate = insuranceRate($row.find('[name$="[employer_rate]"]').val());
            const isActive = $row.find('.js-insurance-component-active').length === 0 || $row.find('.js-insurance-component-active').is(':checked');

            $row.find('.js-insurance-component-total').text((employeeRate + employerRate).toFixed(4) + '%');

            if (isActive) {
                employeeTotal += employeeRate;
                employerTotal += employerRate;
            }
        });

        $form.find('.js-insurance-employee-total').text(employeeTotal.toFixed(4) + '%');
        $form.find('.js-insurance-employer-total').text(employerTotal.toFixed(4) + '%');
        $form.find('.js-insurance-combined-total').text((employeeTotal + employerTotal).toFixed(4) + '%');
    }

    function resetInsuranceComponents($form) {
        const $container = $form.find('.js-insurance-components');
        const $rows = $container.find('.js-insurance-component-row');

        if ($rows.length === 0) {
            return;
        }

        $rows.slice(1).remove();
        const $row = $rows.first();
        $row.find('input[type="hidden"]').val('');
        $row.find('[name$="[public_uuid]"], [name$="[name]"], [name$="[notes]"]').val('');
        $row.find('[name$="[employee_rate]"], [name$="[employer_rate]"]').val('0');
        $row.find('[name$="[calculation_basis]"]').val('contribution_wage');
        $row.find('[name$="[is_active]"][type="hidden"]').val('0');
        $row.find('.js-insurance-component-active').val('1').prop('checked', true);
        reindexInsuranceComponents($container);
        updateInsuranceComponentTotals($form);
    }

    function resetTaxBrackets($form) {
        const $container = $form.find('.js-tax-brackets');
        const $rows = $container.find('.js-tax-bracket-row');

        if ($rows.length === 0) {
            return;
        }

        $rows.slice(1).remove();
        $rows.first().find('input').val('');
        $rows.first().find('[name$="[from_amount]"]').val('0');
        $rows.first().find('[name$="[rate]"]').val('0');
        reindexTaxBrackets($container);
    }

    function showFormNotice($form, message, type) {
        alertElement($form)
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .find('.js-hr-foundation-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-hr-foundation-alert-message')
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

    function resetCreateForm($form) {
        formFieldNames($form).forEach(function (name) {
            if (name === 'status') {
                $form.find('[name="status"]').val('active');
                return;
            }

            const $checkbox = $form.find('[name="' + name + '"][type="checkbox"]');

            if ($checkbox.length > 0) {
                $checkbox.prop('checked', false);
                return;
            }

            $form.find('[name="' + name + '"]').val('');
        });

        $form.find('.js-select2-ajax').val(null).trigger('change');
        $form.find('[name="submit_action"]').val('save');
        $form.find('[name="clone_source_token"]').remove();
        resetTaxBrackets($form);
        resetInsuranceComponents($form);
        updateOriginalFormData($form);
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

    function initTable() {
        const $table = $('.js-hr-foundation-table').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) { return options; };
        const tableName = String($table.data('table-name') || '');
        const protectedColumns = [0, 1, -1];
        const responsiveControlTarget = 1;
        const selectedDocNums = new Set();
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-hr-foundation-row-checkbox';
        const selectAllSelector = '#select_all_records';
        const trashFilterSelector = '#hr_foundation_trash_filter';

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            return api && typeof api.rows === 'function'
                ? $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-hr-foundation-row-checkbox')
                : $table.find(rowCheckboxSelector);
        }

        function trashFilterValue() {
            const value = String($(trashFilterSelector).val() || 'active');

            return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
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

        const dynamicColumns = (window.hrFoundationDataTableColumns || []).map(function (column) {
            const name = String(column.name || '');
            const isNumeric = ['number', 'decimal'].indexOf(String(column.type || '')) !== -1;

            return {
                data: name,
                name: name,
                className: isNumeric
                    ? 'align-middle white-space-nowrap dt-number text-end'
                    : 'align-middle white-space-nowrap dt-text dt-ellipsis'
            };
        });
        const columns = [
            { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
            { data: 'doc_num', name: tableName + '.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
            { data: 'name', name: 'name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' }
        ].concat(dynamicColumns, [
            { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
            { data: 'created_at', name: 'created_at', className: 'align-middle white-space-nowrap dt-date' },
            { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
            { data: 'updated_at', name: 'updated_at', className: 'align-middle white-space-nowrap dt-date' },
            { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions' }
        ]);

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
            columns: columns,
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

        $(trashFilterSelector).off('change.hrFoundationTrashFilter').on('change.hrFoundationTrashFilter', function () {
            clearSelection(table);
            reloadTable();
        });

        $(selectAllSelector).off('change.hrFoundationSelect').on('change.hrFoundationSelect', function () {
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

        $table.off('change.hrFoundationSelect', rowCheckboxSelector).on('change.hrFoundationSelect', rowCheckboxSelector, function () {
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

        $table.off('click.hrFoundationSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.hrFoundationSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-hr-foundation-row-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $(document)
            .off('click.hrFoundationSelectStop mousedown.hrFoundationSelectStop mouseup.hrFoundationSelectStop', '.js-record-select, #select_all_records, td.dt-select')
            .on('click.hrFoundationSelectStop mousedown.hrFoundationSelectStop mouseup.hrFoundationSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
                event.stopPropagation();
            });

        $table.off('dblclick.hrFoundationEditRow', 'tbody tr:not(.child)').on('dblclick.hrFoundationEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $(document).off('hrFoundation:deleted.hrFoundationTable hrFoundation:restored.hrFoundationTable')
            .on('hrFoundation:deleted.hrFoundationTable hrFoundation:restored.hrFoundationTable', function (event, docNum) {
                if (docNum) {
                    selectedDocNums.delete(docNum);
                }

                reloadTable();
                updateSelectAllState(table);
                updateBulkActionsUi();
            });

        $('#bulk_action_apply').off('click.hrFoundationBulk').on('click.hrFoundationBulk', function () {
            const docNums = Array.from(selectedDocNums);

            if (docNums.length === 0) {
                updateBulkActionsUi();
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
        $(document).off('click.hrFoundationDelete', '[data-hr-foundation-delete-url]').on('click.hrFoundationDelete', '[data-hr-foundation-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('hr-foundation-delete-url');
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

                    if ($('.js-hr-foundation-table').length > 0) {
                        $(document).trigger('hrFoundation:deleted', [docNum]);
                        return;
                    }

                    window.location.href = $('[data-shortcut-action="form.back"]').attr('href') || '/';
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function initRestoreActions() {
        $(document).off('click.hrFoundationRestore', '[data-hr-foundation-restore-url]').on('click.hrFoundationRestore', '[data-hr-foundation-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('hr-foundation-restore-url');
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

                    if ($('.js-hr-foundation-table').length > 0) {
                        $(document).trigger('hrFoundation:restored', [docNum]);
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
        $('.js-hr-foundation-form').each(function () {
            updateInsuranceComponentTotals($(this));
        });

        $(document)
            .off('click.hrFoundationInsuranceAdd', '.js-add-insurance-component')
            .on('click.hrFoundationInsuranceAdd', '.js-add-insurance-component', function () {
                const $form = $(this).closest('form');
                const $container = $form.find('.js-insurance-components');
                const $source = $container.find('.js-insurance-component-row').last();

                if ($source.length === 0) {
                    return;
                }

                const $row = $source.clone(false, false);
                $row.find('[name$="[public_uuid]"], [name$="[name]"], [name$="[notes]"]').val('');
                $row.find('[name$="[employee_rate]"], [name$="[employer_rate]"]').val('0');
                $row.find('[name$="[calculation_basis]"]').val('contribution_wage');
                $row.find('[name$="[is_active]"][type="hidden"]').val('0');
                $row.find('.js-insurance-component-active').val('1').prop('checked', true);
                $row.find('.is-invalid').removeClass('is-invalid');
                $row.find('[data-error-for]').text('');
                $container.append($row);
                reindexInsuranceComponents($container);
                updateInsuranceComponentTotals($form);
                $row.find('[name$="[name]"]').trigger('focus');
            });

        $(document)
            .off('click.hrFoundationInsuranceRemove', '.js-remove-insurance-component')
            .on('click.hrFoundationInsuranceRemove', '.js-remove-insurance-component', function () {
                const $form = $(this).closest('form');
                const $container = $form.find('.js-insurance-components');

                if ($container.find('.js-insurance-component-row').length <= 1) {
                    showToast('info', messages.insuranceComponentMinimum || '');
                    return;
                }

                $(this).closest('.js-insurance-component-row').remove();
                reindexInsuranceComponents($container);
                updateInsuranceComponentTotals($form);
            });

        $(document)
            .off('input.hrFoundationInsurance change.hrFoundationInsurance', '.js-insurance-component-rate, .js-insurance-component-active')
            .on('input.hrFoundationInsurance change.hrFoundationInsurance', '.js-insurance-component-rate, .js-insurance-component-active', function () {
                updateInsuranceComponentTotals($(this).closest('form'));
            });

        $(document)
            .off('click.hrFoundationTaxAdd', '.js-add-tax-bracket')
            .on('click.hrFoundationTaxAdd', '.js-add-tax-bracket', function () {
                const $container = $(this).closest('form').find('.js-tax-brackets');
                const $source = $container.find('.js-tax-bracket-row').last();
                const previousUpper = String($source.find('[name$="[to_amount]"]').val() || '');

                if ($source.length === 0) {
                    return;
                }

                const $row = $source.clone(false, false);
                $row.find('input').val('').removeClass('is-invalid');
                $row.find('[name$="[from_amount]"]').val(previousUpper);
                $row.find('[name$="[rate]"]').val('0');
                $row.find('[data-error-for]').text('');
                $container.append($row);
                reindexTaxBrackets($container);
                $row.find('[name$="[from_amount]"]').trigger('focus');
            });

        $(document)
            .off('click.hrFoundationTaxRemove', '.js-remove-tax-bracket')
            .on('click.hrFoundationTaxRemove', '.js-remove-tax-bracket', function () {
                const $container = $(this).closest('.js-tax-brackets');

                if ($container.find('.js-tax-bracket-row').length <= 1) {
                    showToast('info', messages.taxBracketMinimum || '');
                    return;
                }

                $(this).closest('.js-tax-bracket-row').remove();
                reindexTaxBrackets($container);
            });

        $(document)
            .off('click.hrFoundationSubmitAction', '.js-hr-foundation-submit-action')
            .on('click.hrFoundationSubmitAction', '.js-hr-foundation-submit-action', function () {
                const $button = $(this);
                const action = String($button.data('submit-action') || 'save');

                $button.closest('form').find('[name="submit_action"]').val(action);
                $button.closest('form').data('submit-button', $button);
            });

        $(document).off('submit.hrFoundationForm', '.js-hr-foundation-form').on('submit.hrFoundationForm', '.js-hr-foundation-form', function (event) {
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
                    showValidationErrors($form, response.responseJSON.errors);
                    return;
                }

                showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
            }).always(function () {
                setLoading($button, false);
            });
        });

        $(document).off('input.hrFoundationForm change.hrFoundationForm', '.js-hr-foundation-form .is-invalid').on('input.hrFoundationForm change.hrFoundationForm', '.js-hr-foundation-form .is-invalid', function () {
            const $input = $(this);
            const field = ($input.attr('name') || '').replace('[]', '');
            const dottedField = field.replace(/\[([^\]]+)]/g, '.$1');

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"], [data-error-for="' + dottedField + '"]').text('');
        });
    }

    function initDocumentNumberSettings() {
        $(document)
            .off('submit.hrFoundationDocSettings', '.js-hr-foundation-document-number-settings-form')
            .on('submit.hrFoundationDocSettings', '.js-hr-foundation-document-number-settings-form', function (event) {
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
                        showValidationErrors($form, response.responseJSON.errors);
                        return;
                    }

                    showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
                }).always(function () {
                    setLoading($button, false);
                });
            });
    }

    initTable();
    initDeleteActions();
    initRestoreActions();
    initForm();
    initDocumentNumberSettings();
})(jQuery, window);
