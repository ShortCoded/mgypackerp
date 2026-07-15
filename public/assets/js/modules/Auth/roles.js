(function ($, window) {
    'use strict';

    const messages = window.rolesMessages || {};
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

    function normalizeValues(values) {
        return (values || []).map(function (value) {
            return String(value || '').trim();
        }).filter(function (value, index, self) {
            return value !== '' && self.indexOf(value) === index;
        }).sort();
    }

    function normalizePermissions(values) {
        return normalizeValues(values);
    }

    function currentFormData($form) {
        return {
            name: String($form.find('[name="name"]').val() || '').trim(),
            doc_number: String($form.find('[name="doc_number"]').val() || '').trim(),
            notes: String($form.find('[name="notes"]').val() || '').trim(),
            permissions: normalizePermissions($form.find('[name="permissions[]"]:checked').map(function () {
                return $(this).val();
            }).get()),
            accessible_company_doc_nums: normalizeValues($form.find('[name="accessible_company_doc_nums[]"]').val() || []),
            accessible_branch_doc_nums: normalizeValues($form.find('[name="accessible_branch_doc_nums[]"]').val() || []),
            accessible_financial_period_doc_nums: normalizeValues($form.find('[name="accessible_financial_period_doc_nums[]"]').val() || [])
        };
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};

        return {
            name: String(original.name || '').trim(),
            doc_number: original.doc_number === null || original.doc_number === undefined ? '' : String(original.doc_number).trim(),
            notes: String(original.notes || '').trim(),
            permissions: normalizePermissions(original.permissions || []),
            accessible_company_doc_nums: normalizeValues(original.accessible_company_doc_nums || []),
            accessible_branch_doc_nums: normalizeValues(original.accessible_branch_doc_nums || []),
            accessible_financial_period_doc_nums: normalizeValues(original.accessible_financial_period_doc_nums || [])
        };
    }

    function arraysMatch(first, second) {
        if (first.length !== second.length) {
            return false;
        }

        return first.every(function (value, index) {
            return value === second[index];
        });
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        const original = originalFormData($form);
        const current = currentFormData($form);

        return original.name !== current.name
            || original.doc_number !== current.doc_number
            || original.notes !== current.notes
            || !arraysMatch(original.permissions, current.permissions)
            || !arraysMatch(original.accessible_company_doc_nums, current.accessible_company_doc_nums)
            || !arraysMatch(original.accessible_branch_doc_nums, current.accessible_branch_doc_nums)
            || !arraysMatch(original.accessible_financial_period_doc_nums, current.accessible_financial_period_doc_nums);
    }

    function alertElement($form) {
        let $alert = $form.find('.js-role-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-role-alert" role="alert"><span class="js-role-alert-message"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || '') + '"></button></div>');
            $form.find('.js-role-form-body, .card-body').first().prepend($alert);
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
            .find('.js-role-alert-message')
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
            .find('.js-role-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-role-alert-message')
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

    function resetRoleCreateForm($form, response) {
        $form.find('[name="name"], [name="notes"]').val('');
        $form.find('[name="permissions[]"]').prop('checked', false).trigger('change');
        $form.find('[name="accessible_company_doc_nums[]"]').val(null).trigger('change');
        $form.find('[name="accessible_branch_doc_nums[]"]').val(null).trigger('change');
        $form.find('[name="accessible_financial_period_doc_nums[]"]').val(null).trigger('change');
        $form.find('[name="doc_number"]').val('');
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

        $form.find('.js-role-doc-num').each(function () {
            const $element = $(this);

            if ($element.is('input, textarea')) {
                $element.val(data.doc_num);
            } else {
                $element.text(data.doc_num);
            }
        });
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

    function initRolesTable() {
        const $table = $('#roles-table');

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
        const protectedColumns = [0, 1, -1];
        const responsiveControlTarget = 1;
        const selectedDocNums = new Set();
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-role-row-checkbox';
        const selectAllSelector = '#select_all_records, #roles-select-all';
        const trashFilterSelector = '#roles_trash_filter';

        function shieldSelectionEvents() {
            if ($table.data('roles-selection-shielded')) {
                return;
            }

            ['click', 'mousedown', 'mouseup'].forEach(function (eventName) {
                $table[0].addEventListener(eventName, function (event) {
                    if (event.target && event.target.closest('input.js-record-select, input.js-role-row-checkbox')) {
                        event.stopPropagation();
                    }
                }, true);
            });

            $table.data('roles-selection-shielded', true);
        }

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            if (api && typeof api.rows === 'function') {
                return $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-role-row-checkbox');
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

        const rolesTable = $table.DataTable(dataTableOptions({
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
                { data: 'doc_num', name: 'roles.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
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

        shieldSelectionEvents();
        patchResponsiveControlTarget(rolesTable);
        queueResponsiveControlSync(rolesTable);

        rolesTable
            .off('draw.dt.rolesResponsive column-visibility.dt.rolesResponsive column-sizing.dt.rolesResponsive responsive-resize.dt.rolesResponsive')
            .on('draw.dt.rolesResponsive column-visibility.dt.rolesResponsive column-sizing.dt.rolesResponsive responsive-resize.dt.rolesResponsive', function () {
                queueResponsiveControlSync(rolesTable);
            });

        function reloadTable() {
            rolesTable.ajax.reload(null, false);
        }

        $(trashFilterSelector).off('change.rolesTrashFilter').on('change.rolesTrashFilter', function () {
            clearSelection(rolesTable);
            reloadTable();
        });

        $(selectAllSelector).off('change.rolesSelect').on('change.rolesSelect', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(rolesTable).each(function () {
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

            updateSelectAllState(rolesTable);
            updateBulkActionsUi();
        });

        $table.off('click.rolesSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.rolesSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-role-row-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $table.off('dblclick.rolesEditRow', 'tbody tr:not(.child)').on('dblclick.rolesEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $(document)
            .off('click.rolesSelectStop mousedown.rolesSelectStop mouseup.rolesSelectStop', '.js-record-select, #select_all_records, td.dt-select')
            .on('click.rolesSelectStop mousedown.rolesSelectStop mouseup.rolesSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
                event.stopPropagation();
            });

        $table.off('change.rolesSelect', rowCheckboxSelector).on('change.rolesSelect', rowCheckboxSelector, function () {
            const docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(rolesTable);
            updateBulkActionsUi();
        });

        $(document).off('roles:deleted.rolesTable roles:restored.rolesTable').on('roles:deleted.rolesTable roles:restored.rolesTable', function (event, docNum) {
            if (docNum) {
                selectedDocNums.delete(docNum);
            }

            reloadTable();
            updateSelectAllState(rolesTable);
            updateBulkActionsUi();
        });

        $('#bulk_action_apply').off('click.rolesBulk').on('click.rolesBulk', function () {
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
                    clearSelection(rolesTable);
                    reloadTable();
                    showToast('success', response.message);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function rolesTableApi() {
        const $table = $('#roles-table');

        if ($table.length === 0 || !$.fn.DataTable || !$.fn.DataTable.isDataTable($table[0])) {
            return null;
        }

        return $table.DataTable();
    }

    function initRoleRecordActions() {
        $(document).off('click.rolesDelete', '[data-role-delete-url], .js-delete-record[data-delete-url]').on('click.rolesDelete', '[data-role-delete-url], .js-delete-record[data-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('role-delete-url') || $button.data('delete-url');
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

                setLoading($button, true);

                $.ajax({
                    url: url,
                    method: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response.message);

                    if (rolesTableApi()) {
                        $(document).trigger('roles:deleted', [docNum]);
                        return;
                    }

                    window.location.href = $button.data('redirect-url') || $('[data-shortcut-action="form.back"]').attr('href') || '/admin/roles';
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    setLoading($button, false);
                });
            });
        });

        $(document).off('click.rolesRestore', '[data-role-restore-url], .js-restore-record[data-restore-url]').on('click.rolesRestore', '[data-role-restore-url], .js-restore-record[data-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('role-restore-url') || $button.data('restore-url');
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

                    if (rolesTableApi()) {
                        $(document).trigger('roles:restored', [docNum]);
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

    function initRoleForm() {
        $(document)
            .off('click.rolesSubmitAction', '.js-role-submit-action')
            .on('click.rolesSubmitAction', '.js-role-submit-action', function () {
                const $button = $(this);
                const action = String($button.data('submit-action') || 'save');

                $button.closest('form').find('[name="submit_action"]').val(action);
                $button.closest('form').data('submit-button', $button);
            });

        $(document).off('submit.rolesForm', '.js-role-form').on('submit.rolesForm', '.js-role-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();

            clearFormErrors($form);

            if ($form.find('.js-select2-ajax').filter(function () { return $(this).data('select2HydratingSelected') === true; }).length > 0) {
                showFormNotice($form, messages.loading, 'info');
                return;
            }

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
                    resetRoleCreateForm($form, response);
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

        $(document).off('input.rolesForm change.rolesForm', '.js-role-form .is-invalid').on('input.rolesForm change.rolesForm', '.js-role-form .is-invalid', function () {
            const $input = $(this);
            const field = ($input.attr('name') || '').replace('[]', '');

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"]').text('');
        });
    }

    function initDocumentNumberSettings() {
        $(document)
            .off('submit.rolesDocSettings', '.js-role-document-number-settings-form')
            .on('submit.rolesDocSettings', '.js-role-document-number-settings-form', function (event) {
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
            .off('input.rolesDocSettings change.rolesDocSettings', '.js-role-document-number-settings-form .is-invalid')
            .on('input.rolesDocSettings change.rolesDocSettings', '.js-role-document-number-settings-form .is-invalid', function () {
                const $input = $(this);
                const field = ($input.attr('name') || '').replace('[]', '');

                $input.removeClass('is-invalid');
                $input.closest('form').find('[data-error-for="' + field + '"]').text('');
            });
    }

    function permissionCheckboxes($scope) {
        return $scope.find('.js-permission-checkbox');
    }

    function permissionCheckboxesForNode($selector, node) {
        const nodeKey = String(node || '');

        if (!nodeKey) {
            return $();
        }

        return permissionCheckboxes($selector).filter(function () {
            const nodes = String($(this).attr('data-permission-nodes') || '').split(/\s+/);

            return nodes.indexOf(nodeKey) !== -1;
        });
    }

    function permissionCheckboxesForResource($selector, resource) {
        const resourceKey = String(resource || '');

        if (!resourceKey) {
            return $();
        }

        return permissionCheckboxes($selector).filter(function () {
            return String($(this).attr('data-permission-resource') || '') === resourceKey;
        });
    }

    function setCheckboxState($checkbox, $checkboxes) {
        const checked = $checkboxes.filter(':checked').length;
        const total = $checkboxes.length;

        $checkbox
            .prop('checked', total > 0 && checked === total)
            .prop('indeterminate', checked > 0 && checked < total);
    }

    function updatePermissionSelector($selector) {
        setCheckboxState($selector.find('.js-permission-global-check'), permissionCheckboxes($selector));

        $selector.find('.js-permission-group-check').each(function () {
            const $checkbox = $(this);
            const node = $checkbox.data('permission-node') || $checkbox.data('permission-group');

            setCheckboxState($checkbox, permissionCheckboxesForNode($selector, node));
        });

        $selector.find('.js-permission-resource-check').each(function () {
            const $checkbox = $(this);
            const resource = $checkbox.data('permission-resource');

            setCheckboxState($checkbox, permissionCheckboxesForResource($selector, resource));
        });
    }

    function initPermissionSelector() {
        $(document)
            .off('change.rolesPermissions', '.js-permission-selector .js-permission-checkbox')
            .on('change.rolesPermissions', '.js-permission-selector .js-permission-checkbox', function () {
                updatePermissionSelector($(this).closest('.js-permission-selector'));
            });

        $(document)
            .off('change.rolesPermissionGlobal', '.js-permission-selector .js-permission-global-check')
            .on('change.rolesPermissionGlobal', '.js-permission-selector .js-permission-global-check', function () {
                const $global = $(this);
                const $selector = $global.closest('.js-permission-selector');

                permissionCheckboxes($selector).prop('checked', $global.is(':checked'));
                updatePermissionSelector($selector);
            });

        $(document)
            .off('change.rolesPermissionGroup', '.js-permission-selector .js-permission-group-check')
            .on('change.rolesPermissionGroup', '.js-permission-selector .js-permission-group-check', function () {
                const $group = $(this);
                const $selector = $group.closest('.js-permission-selector');
                const node = $group.data('permission-node') || $group.data('permission-group');

                permissionCheckboxesForNode($selector, node).prop('checked', $group.is(':checked'));
                updatePermissionSelector($selector);
            });

        $(document)
            .off('change.rolesPermissionResource', '.js-permission-selector .js-permission-resource-check')
            .on('change.rolesPermissionResource', '.js-permission-selector .js-permission-resource-check', function () {
                const $resource = $(this);
                const $selector = $resource.closest('.js-permission-selector');
                const resource = $resource.data('permission-resource');

                permissionCheckboxesForResource($selector, resource).prop('checked', $resource.is(':checked'));
                updatePermissionSelector($selector);
            });

        $('.js-permission-selector').each(function () {
            updatePermissionSelector($(this));
        });
    }

    initRolesTable();
    initRoleRecordActions();
    initRoleForm();
    initDocumentNumberSettings();
    initPermissionSelector();
})(jQuery, window);
