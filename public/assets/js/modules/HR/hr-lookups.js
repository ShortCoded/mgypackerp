(function ($, window) {
    'use strict';

    const messages = window.hrLookupMessages || {};
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

    function currentFormData($form) {
        return {
            name: String($form.find('[name="name"]').val() || '').trim(),
            doc_number: String($form.find('[name="doc_number"]').val() || '').trim(),
            notes: String($form.find('[name="notes"]').val() || '').trim()
        };
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};

        return {
            name: String(original.name || '').trim(),
            doc_number: original.doc_number === null || original.doc_number === undefined ? '' : String(original.doc_number).trim(),
            notes: String(original.notes || '').trim()
        };
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        const original = originalFormData($form);
        const current = currentFormData($form);

        return original.name !== current.name
            || original.doc_number !== current.doc_number
            || original.notes !== current.notes;
    }

    function alertElement($form) {
        let $alert = $form.find('.js-hr-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-hr-alert" role="alert"><span class="js-hr-alert-message"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || '') + '"></button></div>');
            $form.find('.js-hr-form-body, .card-body').first().prepend($alert);
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
            .find('.js-hr-alert-message')
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
            .find('.js-hr-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-hr-alert-message')
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
        $form.find('[name="name"], [name="notes"], [name="doc_number"]').val('');
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

    function initLookupTable() {
        const $table = $('.js-hr-lookup-table').first();

        if ($table.length === 0 || !$.fn.DataTable) {
            return;
        }

        if ($.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) {
                return options;
            };
        const tableName = String($table.data('table-name') || '');
        const protectedColumns = [0, 1, -1];
        const responsiveControlTarget = 1;
        const selectedDocNums = new Set();
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-hr-lookup-row-checkbox';
        const selectAllSelector = '#select_all_records';
        const trashFilterSelector = '#hr_lookup_trash_filter';

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            if (api && typeof api.rows === 'function') {
                return $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-hr-lookup-row-checkbox');
            }

            return $table.find(rowCheckboxSelector);
        }

        function selectedDocNumsArray() {
            return Array.from(selectedDocNums);
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
            const $selectedCount = $('#bulk_selected_count');
            const applyLabel = $applyButton.data('label') || '';

            $actions
                .toggleClass('d-none', selectedCount === 0)
                .toggleClass('d-flex', selectedCount > 0);

            $selectedCount.text(selectedCount);

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

            [0, 1, 8].forEach(function (index) {
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

            [0, 1, 8].forEach(function (index) {
                api.column(index).visible(true, false);
            });

            api.columns.adjust();
        }

        function patchResponsiveControlTarget(api) {
            const settings = api && typeof api.settings === 'function' ? api.settings()[0] : null;
            const responsive = settings && settings._responsive ? settings._responsive : null;

            if (!responsive || responsive._erpControlTargetPatched) {
                return;
            }

            responsive.c.details.target = responsiveControlTarget;
            responsive._erpControlTargetPatched = true;
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
            syncResponsiveControlColumn(api);

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
            lookupTable.ajax.reload(null, false);
        }

        const lookupTable = $table.DataTable(dataTableOptions({
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
                { data: 'name', name: 'name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'notes', name: 'notes', className: 'align-middle dt-text dt-ellipsis' },
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
                { responsivePriority: 10, targets: 2 },
                { responsivePriority: 20, targets: 3 },
                { responsivePriority: 30, targets: [4, 5, 6, 7] }
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

        patchResponsiveControlTarget(lookupTable);
        queueResponsiveControlSync(lookupTable);

        lookupTable
            .off('draw.dt.hrLookupResponsive column-visibility.dt.hrLookupResponsive column-sizing.dt.hrLookupResponsive responsive-resize.dt.hrLookupResponsive')
            .on('draw.dt.hrLookupResponsive column-visibility.dt.hrLookupResponsive column-sizing.dt.hrLookupResponsive responsive-resize.dt.hrLookupResponsive', function () {
                queueResponsiveControlSync(lookupTable);
            });

        $(trashFilterSelector).off('change.hrLookupTrashFilter').on('change.hrLookupTrashFilter', function () {
            clearSelection(lookupTable);
            reloadTable();
        });

        $(selectAllSelector).off('change.hrLookupSelect').on('change.hrLookupSelect', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(lookupTable).each(function () {
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

            updateSelectAllState(lookupTable);
            updateBulkActionsUi();
        });

        $table.off('click.hrLookupSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.hrLookupSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-hr-lookup-row-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $table.off('dblclick.hrLookupEditRow', 'tbody tr:not(.child)').on('dblclick.hrLookupEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $(document)
            .off('click.hrLookupSelectStop mousedown.hrLookupSelectStop mouseup.hrLookupSelectStop', '.js-record-select, #select_all_records, td.dt-select')
            .on('click.hrLookupSelectStop mousedown.hrLookupSelectStop mouseup.hrLookupSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
                event.stopPropagation();
            });

        $table.off('change.hrLookupSelect', rowCheckboxSelector).on('change.hrLookupSelect', rowCheckboxSelector, function () {
            const docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(lookupTable);
            updateBulkActionsUi();
        });

        $(document).off('hrLookup:deleted.hrLookupTable').on('hrLookup:deleted.hrLookupTable', function (event, docNum) {
            if (docNum) {
                selectedDocNums.delete(docNum);
            }

            reloadTable();
            updateSelectAllState(lookupTable);
            updateBulkActionsUi();
        });

        $(document).off('hrLookup:restored.hrLookupTable').on('hrLookup:restored.hrLookupTable', function (event, docNum) {
            if (docNum) {
                selectedDocNums.delete(docNum);
            }

            reloadTable();
            updateSelectAllState(lookupTable);
            updateBulkActionsUi();
        });

        $('#bulk_action_apply').off('click.hrLookupBulk').on('click.hrLookupBulk', function () {
            const docNums = selectedDocNumsArray();
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
                    clearSelection(lookupTable);
                    reloadTable();
                    showToast('success', response.message);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function initDeleteActions() {
        $(document).off('click.hrLookupDelete', '[data-hr-delete-url], .js-delete-record[data-delete-url]').on('click.hrLookupDelete', '[data-hr-delete-url], .js-delete-record[data-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('hr-delete-url') || $button.data('delete-url');
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

                    if ($('.js-hr-lookup-table').length > 0) {
                        $(document).trigger('hrLookup:deleted', [docNum]);
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
        $(document).off('click.hrLookupRestore', '[data-hr-restore-url], .js-restore-record[data-restore-url]').on('click.hrLookupRestore', '[data-hr-restore-url], .js-restore-record[data-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('hr-restore-url') || $button.data('restore-url');
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

                    if ($('.js-hr-lookup-table').length > 0) {
                        $(document).trigger('hrLookup:restored', [docNum]);
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

    function initLookupForm() {
        $(document)
            .off('click.hrLookupSubmitAction', '.js-hr-submit-action')
            .on('click.hrLookupSubmitAction', '.js-hr-submit-action', function () {
                const $button = $(this);
                const action = String($button.data('submit-action') || 'save');

                $button.closest('form').find('[name="submit_action"]').val(action);
                $button.closest('form').data('submit-button', $button);
            });

        $(document).off('submit.hrLookupForm', '.js-hr-lookup-form').on('submit.hrLookupForm', '.js-hr-lookup-form', function (event) {
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

        $(document).off('input.hrLookupForm change.hrLookupForm', '.js-hr-lookup-form .is-invalid').on('input.hrLookupForm change.hrLookupForm', '.js-hr-lookup-form .is-invalid', function () {
            const $input = $(this);
            const field = ($input.attr('name') || '').replace('[]', '');

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"]').text('');
        });
    }

    function initDocumentNumberSettings() {
        $(document)
            .off('submit.hrLookupDocSettings', '.js-hr-document-number-settings-form')
            .on('submit.hrLookupDocSettings', '.js-hr-document-number-settings-form', function (event) {
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
            .off('input.hrLookupDocSettings change.hrLookupDocSettings', '.js-hr-document-number-settings-form .is-invalid')
            .on('input.hrLookupDocSettings change.hrLookupDocSettings', '.js-hr-document-number-settings-form .is-invalid', function () {
                const $input = $(this);
                const field = ($input.attr('name') || '').replace('[]', '');

                $input.removeClass('is-invalid');
                $input.closest('form').find('[data-error-for="' + field + '"]').text('');
            });
    }

    initLookupTable();
    initDeleteActions();
    initRestoreActions();
    initLookupForm();
    initDocumentNumberSettings();
})(jQuery, window);
