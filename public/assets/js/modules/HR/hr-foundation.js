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

        return Object.keys(current).some(function (field) {
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
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const $input = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $input.addClass('is-invalid');
            $form.find('[data-error-for="' + normalizedField + '"]').text(message || '');
        });
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

            return {
                data: name,
                name: name,
                className: 'align-middle white-space-nowrap dt-text dt-ellipsis'
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

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"]').text('');
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
