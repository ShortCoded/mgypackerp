(function ($, window) {
    'use strict';

    const messages = window.currencyMessages || {};
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

    function fieldValue($form, name) {
        const $field = $form.find('[name="' + name + '"]').last();

        if ($field.attr('type') === 'checkbox') {
            return $field.is(':checked') ? '1' : '0';
        }

        return String($field.val() || '').trim();
    }

    function currentFormData($form) {
        return {
            doc_number: fieldValue($form, 'doc_number'),
            name: fieldValue($form, 'name'),
            code: fieldValue($form, 'code').toUpperCase(),
            minor_unit_name: fieldValue($form, 'minor_unit_name'),
            minor_unit_factor: fieldValue($form, 'minor_unit_factor') || '100',
            is_main: fieldValue($form, 'is_main'),
            status: fieldValue($form, 'status') || 'active',
            notes: fieldValue($form, 'notes')
        };
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};

        return {
            doc_number: original.doc_number === null || original.doc_number === undefined ? '' : String(original.doc_number).trim(),
            name: String(original.name || '').trim(),
            code: String(original.code || '').trim().toUpperCase(),
            minor_unit_name: String(original.minor_unit_name || '').trim(),
            minor_unit_factor: String(original.minor_unit_factor || 100).trim(),
            is_main: original.is_main ? '1' : '0',
            status: String(original.status || 'active').trim(),
            notes: String(original.notes || '').trim()
        };
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        const original = originalFormData($form);
        const current = currentFormData($form);

        return Object.keys(original).some(function (field) {
            if (field === 'minor_unit_factor' && window.AppNumbers && typeof window.AppNumbers.same === 'function') {
                return !window.AppNumbers.same(original[field], current[field]);
            }

            return original[field] !== current[field];
        });
    }

    function alertElement($form) {
        let $alert = $form.find('.js-currency-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-currency-alert" role="alert"><span class="js-currency-alert-message"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || '') + '"></button></div>');
            $form.find('.js-currency-form-body, .card-body').first().prepend($alert);
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
        const allMessages = validationMessages(errors);

        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');

        allMessages.forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        $alert
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-currency-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const normalizedField = field.replace(/\.\d+$/, '');
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const $input = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $input.addClass('is-invalid');
            $form.find('[data-error-for="' + normalizedField + '"]').text(message || '');
        });
    }

    function showFormNotice($form, message, type) {
        const alertType = type || 'danger';

        alertElement($form)
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + alertType)
            .find('.js-currency-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-currency-alert-message')
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
        $form.find('[name="name"], [name="code"], [name="minor_unit_name"], [name="notes"]').val('');
        $form.find('[name="minor_unit_factor"]').val('100');
        $form.find('[name="status"]').val('active');
        $form.find('[name="is_main"]').prop('checked', false);
        $form.find('[name="submit_action"]').val('save');
        $form.find('[name="clone_source_token"]').remove();
        $form.find('[name="doc_number"]').val('');
        updateOriginalFormData($form);
        $form.find('[name="name"]').trigger('focus');
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
            ['currency-delete-url', urls.destroy],
            ['delete-url', urls.destroy],
            ['currency-restore-url', urls.restore],
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
            const newPath = window.location.pathname.replace(data.old_doc_num, data.doc_num);
            window.history.replaceState({}, '', newPath + window.location.search + window.location.hash);
        }
    }

    function initCurrencyTable() {
        const $table = $('.js-currency-table').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) { return options; };
        const tableName = String($table.data('table-name') || 'currencies');
        const protectedColumns = [0, 1, -1];
        const responsiveControlTarget = 1;
        const selectedDocNums = new Set();
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-currency-row-checkbox';
        const selectAllSelector = '#select_all_records';
        const trashFilterSelector = '#currency_trash_filter';

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            if (api && typeof api.rows === 'function') {
                return $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-currency-row-checkbox');
            }

            return $table.find(rowCheckboxSelector);
        }

        function trashFilterValue() {
            const value = String($(trashFilterSelector).val() || 'active');

            return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
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
                $actionSelect.val('delete');
            }
        }

        function protectStateColumns(data) {
            if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
                window.AppDataTables.protectStateColumns(data, protectedColumns);
                return;
            }

            [0, 1, 12].forEach(function (index) {
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

            [0, 1, 12].forEach(function (index) {
                api.column(index).visible(true, false);
            });

            api.columns.adjust();
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

        const currencyTable = $table.DataTable(dataTableOptions({
            processing: true,
            serverSide: true,
            stateSave: true,
            stateLoadParams: function (settings, data) { protectStateColumns(data); },
            stateSaveParams: function (settings, data) { protectStateColumns(data); },
            ajax: {
                url: $table.data('url'),
                data: function (data) {
                    data.trash_filter = trashFilterValue();
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
                { data: 'name', name: tableName + '.name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'code', name: tableName + '.code', className: 'align-middle white-space-nowrap dt-code' },
                { data: 'minor_unit_name', name: tableName + '.minor_unit_name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'minor_unit_factor', name: tableName + '.minor_unit_factor', className: 'align-middle white-space-nowrap dt-number text-center' },
                { data: 'is_main', name: tableName + '.is_main', className: 'align-middle white-space-nowrap dt-status' },
                { data: 'status', name: tableName + '.status', className: 'align-middle white-space-nowrap dt-status' },
                { data: 'created_by', name: 'created_users.name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'created_at', name: tableName + '.created_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'updated_by', name: 'updated_users.name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'updated_at', name: tableName + '.updated_at', className: 'align-middle white-space-nowrap dt-date' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions' }
            ],
            columnDefs: [
                { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
                { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 3, searchable: false, targets: -1 },
                { responsivePriority: 10, targets: [2, 3, 4, 5, 6, 7] },
                { responsivePriority: 30, targets: [8, 9, 10, 11] }
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

        $(trashFilterSelector).off('change.currencyTrashFilter').on('change.currencyTrashFilter', function () {
            clearSelection(currencyTable);
            currencyTable.ajax.reload(null, false);
        });

        $(selectAllSelector).off('change.currencySelect').on('change.currencySelect', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(currencyTable).each(function () {
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

            updateSelectAllState(currencyTable);
            updateBulkActionsUi();
        });

        $table.off('click.currencySelectCell', 'tbody tr:not(.child) td.dt-select').on('click.currencySelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-currency-row-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $table.off('dblclick.currencyEditRow', 'tbody tr:not(.child)').on('dblclick.currencyEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $(document)
            .off('click.currencySelectStop mousedown.currencySelectStop mouseup.currencySelectStop', '.js-record-select, #select_all_records, td.dt-select')
            .on('click.currencySelectStop mousedown.currencySelectStop mouseup.currencySelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
                event.stopPropagation();
            });

        $table.off('change.currencySelect', rowCheckboxSelector).on('change.currencySelect', rowCheckboxSelector, function () {
            const docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(currencyTable);
            updateBulkActionsUi();
        });

        $(document).off('currency:deleted.currencyTable').on('currency:deleted.currencyTable', function (event, docNum) {
            if (docNum) {
                selectedDocNums.delete(docNum);
            }

            currencyTable.ajax.reload(null, false);
            updateSelectAllState(currencyTable);
            updateBulkActionsUi();
        });

        $(document).off('currency:restored.currencyTable').on('currency:restored.currencyTable', function (event, docNum) {
            if (docNum) {
                selectedDocNums.delete(docNum);
            }

            currencyTable.ajax.reload(null, false);
            updateSelectAllState(currencyTable);
            updateBulkActionsUi();
        });

        $('#bulk_action_apply').off('click.currencyBulk').on('click.currencyBulk', function () {
            const docNums = Array.from(selectedDocNums);
            const action = $('#bulk_action_select').val();

            if (docNums.length === 0) {
                updateBulkActionsUi();
                return;
            }

            if (action !== 'delete') {
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
                    clearSelection(currencyTable);
                    currencyTable.ajax.reload(null, false);
                    showToast('success', response.message);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function initDeleteActions() {
        $(document).off('click.currencyDelete', '[data-currency-delete-url], .js-delete-record[data-delete-url]').on('click.currencyDelete', '[data-currency-delete-url], .js-delete-record[data-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('currency-delete-url') || $button.data('delete-url');
            const docNum = String($button.data('doc-num') || '').trim();

            if (!url) {
                showToast('error', messages.unexpectedError);
                return;
            }

            confirmDialog({
                title: messages.deleteConfirmTitle,
                text: messages.deleteConfirmText,
                confirmButtonText: messages.deleteConfirmYes
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

                    if ($('.js-currency-table').length > 0) {
                        $(document).trigger('currency:deleted', [docNum]);
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
        $(document).off('click.currencyRestore', '[data-currency-restore-url], .js-restore-record[data-restore-url]').on('click.currencyRestore', '[data-currency-restore-url], .js-restore-record[data-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('currency-restore-url') || $button.data('restore-url');
            const docNum = String($button.data('doc-num') || '').trim();

            if (!url) {
                showToast('error', messages.unexpectedError);
                return;
            }

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

                $.ajax({
                    url: url,
                    method: 'PATCH',
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response.message);

                    if ($('.js-currency-table').length > 0) {
                        $(document).trigger('currency:restored', [docNum]);
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

    function initCurrencyForm() {
        $(document)
            .off('click.currencySubmitAction', '.js-currency-submit-action')
            .on('click.currencySubmitAction', '.js-currency-submit-action', function () {
                const $button = $(this);
                const action = String($button.data('submit-action') || 'save');

                $button.closest('form').find('[name="submit_action"]').val(action);
                $button.closest('form').data('submit-button', $button);
            });

        $(document).off('submit.currencyForm', '.js-currency-form').on('submit.currencyForm', '.js-currency-form', function (event) {
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
                updateOriginalFormData($form);
                showToast('success', response.message);

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

                showFormNotice($form, response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError, 'danger');
            }).always(function () {
                setLoading($button, false);
            });
        });

        $(document).off('input.currencyForm change.currencyForm', '.js-currency-form .is-invalid').on('input.currencyForm change.currencyForm', '.js-currency-form .is-invalid', function () {
            const $input = $(this);
            const field = ($input.attr('name') || '').replace('[]', '');

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"]').text('');
        });
    }

    function initDocumentNumberSettings() {
        $(document)
            .off('submit.currencyDocSettings', '.js-currency-document-number-settings-form')
            .on('submit.currencyDocSettings', '.js-currency-document-number-settings-form', function (event) {
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

        $(document)
            .off('input.currencyDocSettings change.currencyDocSettings', '.js-currency-document-number-settings-form .is-invalid')
            .on('input.currencyDocSettings change.currencyDocSettings', '.js-currency-document-number-settings-form .is-invalid', function () {
                const $input = $(this);
                const field = ($input.attr('name') || '').replace('[]', '');

                $input.removeClass('is-invalid');
                $input.closest('form').find('[data-error-for="' + field + '"]').text('');
            });
    }

    initCurrencyTable();
    initDeleteActions();
    initRestoreActions();
    initCurrencyForm();
    initDocumentNumberSettings();
})(jQuery, window);
