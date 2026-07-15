(function ($, window, document) {
    'use strict';

    const messages = window.projectStructureModelMessages || {};
    const selectedDocNums = new Set();
    let modelsTable = null;

    function csrfToken() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function headers() {
        return {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json'
        };
    }

    function toast(icon, title) {
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
            cancelButtonText: options.cancelButtonText || messages.cancel || '',
            confirmButtonColor: options.confirmButtonColor || '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function formData($form) {
        return {
            doc_number: String($form.find('[name="doc_number"]').val() || '').trim(),
            name: String($form.find('[name="name"]').val() || '').trim(),
            code: String($form.find('[name="code"]').val() || '').trim(),
            short_name: String($form.find('[name="short_name"]').val() || '').trim(),
            status: String($form.find('[name="status"]').val() || '').trim(),
            notes: String($form.find('[name="notes"]').val() || '').trim()
        };
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};

        return {
            doc_number: original.doc_number === null || original.doc_number === undefined ? '' : String(original.doc_number).trim(),
            name: String(original.name || '').trim(),
            code: String(original.code || '').trim(),
            short_name: String(original.short_name || '').trim(),
            status: String(original.status || '').trim(),
            notes: String(original.notes || '').trim()
        };
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        const original = originalFormData($form);
        const current = formData($form);

        return Object.keys(original).some(function (field) {
            return original[field] !== current[field];
        });
    }

    function alertElement($form) {
        let $alert = $form.find('.js-project-structure-model-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger d-none js-project-structure-model-alert" role="alert"><div class="js-project-structure-model-alert-message"></div></div>');
            $form.find('.js-project-structure-model-form-body, .card-body').first().prepend($alert);
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
        const $list = $('<ul class="mb-0 ps-3"></ul>');

        clearFormErrors($form);

        validationMessages(errors).forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        alertElement($form)
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-project-structure-model-alert-message')
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
            .find('.js-project-structure-model-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-project-structure-model-alert-message')
            .empty();
    }

    function setLoading($element, loading) {
        $element.prop('disabled', loading);
        $element.css('cursor', loading ? 'wait' : '');
        $('body').css('cursor', loading ? 'wait' : '');
    }

    function updateOriginalFormData($form) {
        $form.data('original', formData($form));
    }

    function resetCreateForm($form, response) {
        $form.find('[name="name"], [name="code"], [name="short_name"], [name="notes"]').val('');
        $form.find('[name="status"]').val('active');
        $form.find('[name="doc_number"]').val(response.next_doc_number || '');
        $form.find('[name="submit_action"]').val('save_new');
        $form.find('[name="clone_source_token"]').remove();
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

        if (window.history && window.location.pathname.indexOf(data.old_doc_num) !== -1) {
            window.history.replaceState({}, '', window.location.pathname.replace(data.old_doc_num, data.doc_num) + window.location.search + window.location.hash);
        }
    }

    function checkboxDocNum(checkbox) {
        return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
    }

    function pageCheckboxes(api) {
        const selector = 'input.js-record-select, input.js-project-structure-model-row-checkbox';

        return api && typeof api.rows === 'function'
            ? $(api.rows({ page: 'current' }).nodes()).find(selector)
            : $('.js-project-structure-models-table').find('tbody tr:not(.child) ' + selector);
    }

    function trashFilterValue() {
        const value = String($('#project_structure_models_trash_filter').val() || 'active');

        return ['active', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
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

        if (selectedCount === 0) {
            $('#bulk_action_select').val('delete');
        }
    }

    function updateSelectAllState(api) {
        const selectableDocNums = pageCheckboxes(api).map(function () {
            return checkboxDocNum(this);
        }).get().filter(function (docNum) {
            return docNum !== '';
        });
        const checkedOnPage = selectableDocNums.filter(function (docNum) {
            return selectedDocNums.has(docNum);
        }).length;

        $('#select_all_records')
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
        if (modelsTable) {
            modelsTable.ajax.reload(null, false);
        }
    }

    function initTable() {
        const $table = $('.js-project-structure-models-table').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) {
                return options;
            };
        const tableName = String($table.data('table-name') || 'project_structure_models');

        modelsTable = $table.DataTable(dataTableOptions({
            processing: true,
            serverSide: true,
            stateSave: true,
            ajax: {
                url: $table.data('url'),
                data: function (data) {
                    data.trash_filter = trashFilterValue();
                }
            },
            responsive: {
                details: {
                    type: 'inline',
                    target: 1
                }
            },
            order: [[1, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: tableName + '.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'name', name: 'name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'code', name: 'code', className: 'align-middle white-space-nowrap dt-code' },
                { data: 'short_name', name: 'short_name', className: 'align-middle white-space-nowrap dt-code' },
                { data: 'status', name: 'status', className: 'align-middle white-space-nowrap' },
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

        $('#project_structure_models_trash_filter').off('change.projectStructureModelsTrash').on('change.projectStructureModelsTrash', function () {
            clearSelection(modelsTable);
            reloadTable();
        });

        $('#select_all_records').off('change.projectStructureModelsSelectAll').on('change.projectStructureModelsSelectAll', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(modelsTable).each(function () {
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

            updateSelectAllState(modelsTable);
            updateBulkActionsUi();
        });

        $table.off('click.projectStructureModelsSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.projectStructureModelsSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-project-structure-model-row-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $table.off('change.projectStructureModelsSelect', 'input.js-record-select, input.js-project-structure-model-row-checkbox').on('change.projectStructureModelsSelect', 'input.js-record-select, input.js-project-structure-model-row-checkbox', function () {
            const docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(modelsTable);
            updateBulkActionsUi();
        });

        $table.off('dblclick.projectStructureModelsEditRow', 'tbody tr:not(.child)').on('dblclick.projectStructureModelsEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $('#bulk_action_apply').off('click.projectStructureModelsBulk').on('click.projectStructureModelsBulk', function () {
            const docNums = Array.from(selectedDocNums);

            if (docNums.length === 0 || $('#bulk_action_select').val() !== 'delete') {
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
                    clearSelection(modelsTable);
                    reloadTable();
                    toast('success', response.message);
                }).fail(function (response) {
                    toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function initDeleteActions() {
        $(document).off('click.projectStructureModelsDelete', '.js-delete-record[data-delete-url]').on('click.projectStructureModelsDelete', '.js-delete-record[data-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('delete-url');
            const docNum = String($button.data('doc-num') || '').trim();

            confirmDialog({
                title: messages.deleteConfirmTitle,
                text: messages.deleteConfirmText,
                confirmButtonText: messages.deleteConfirmYes
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                setLoading($button, true);

                $.ajax({ url: url, method: 'DELETE', headers: headers() })
                    .done(function (response) {
                        toast('success', response.message);

                        if ($('.js-project-structure-models-table').length > 0) {
                            selectedDocNums.delete(docNum);
                            reloadTable();
                            updateBulkActionsUi();
                            return;
                        }

                        window.location.href = $button.data('redirect-url') || $('[data-shortcut-action="form.back"]').attr('href') || '/';
                    })
                    .fail(function (response) {
                        toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    })
                    .always(function () {
                        setLoading($button, false);
                    });
            });
        });
    }

    function initRestoreActions() {
        $(document).off('click.projectStructureModelsRestore', '.js-restore-record[data-restore-url]').on('click.projectStructureModelsRestore', '.js-restore-record[data-restore-url]', function () {
            const $button = $(this);
            const docNum = String($button.data('doc-num') || '').trim();

            confirmDialog({
                title: messages.restoreConfirmTitle,
                text: messages.restoreConfirmText,
                confirmButtonText: messages.restoreConfirmYes,
                confirmButtonColor: '#00a65a'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                setLoading($button, true);

                $.ajax({ url: $button.data('restore-url'), method: 'PATCH', headers: headers() })
                    .done(function (response) {
                        toast('success', response.message);

                        if ($('.js-project-structure-models-table').length > 0) {
                            selectedDocNums.delete(docNum);
                            reloadTable();
                            updateBulkActionsUi();
                            return;
                        }

                        window.location.href = $button.data('redirect-url') || window.location.href;
                    })
                    .fail(function (response) {
                        toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                    })
                    .always(function () {
                        setLoading($button, false);
                    });
            });
        });
    }

    function initForm() {
        $(document).off('click.projectStructureModelsSubmitAction', '.js-project-structure-model-submit-action').on('click.projectStructureModelsSubmitAction', '.js-project-structure-model-submit-action', function () {
            const $button = $(this);
            $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
            $button.closest('form').data('submit-button', $button);
        });

        $(document).off('submit.projectStructureModelsForm', '.js-project-structure-model-form').on('submit.projectStructureModelsForm', '.js-project-structure-model-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();

            clearFormErrors($form);

            if (!hasChanges($form)) {
                showFormNotice($form, messages.noChanges, 'warning');
                toast('info', messages.noChanges);
                return;
            }

            setLoading($button, true);

            $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                if (!response.success && response.type === 'no_changes') {
                    showFormNotice($form, response.message || messages.noChanges, 'warning');
                    toast('info', response.message || messages.noChanges);
                    return;
                }

                toast('success', response.message || messages.saved);

                if (response.redirect) {
                    window.location.href = response.redirect;
                    return;
                }

                updateUrlsAfterDocNumberChange($form, response);

                if (response.reset_form) {
                    resetCreateForm($form, response);
                    return;
                }

                updateOriginalFormData($form);
            }).fail(function (response) {
                if (response.status === 422 && response.responseJSON && response.responseJSON.errors) {
                    showValidationErrors($form, response.responseJSON.errors);
                    toast('error', messages.validationFailed);
                    return;
                }

                const message = response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError;
                showFormNotice($form, message);
                toast('error', message);
            }).always(function () {
                setLoading($button, false);
            });
        });
    }

    function initDocumentNumberSettings() {
        $(document).off('submit.projectStructureModelsDocumentSettings', '.js-project-structure-model-document-number-settings-form').on('submit.projectStructureModelsDocumentSettings', '.js-project-structure-model-document-number-settings-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.find('[type="submit"]').first();

            $form.find('.is-invalid').removeClass('is-invalid');
            $form.find('[data-error-for]').text('');
            setLoading($button, true);

            $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                toast('success', response.message || messages.saved);
            }).fail(function (response) {
                const errors = response.responseJSON && response.responseJSON.errors ? response.responseJSON.errors : {};

                Object.keys(errors).forEach(function (field) {
                    const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
                    $form.find('[name="' + field + '"]').addClass('is-invalid');
                    $form.find('[data-error-for="' + field + '"]').text(message || '');
                });

                toast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
            }).always(function () {
                setLoading($button, false);
            });
        });
    }

    $(function () {
        initTable();
        initForm();
        initDeleteActions();
        initRestoreActions();
        initDocumentNumberSettings();
    });
})(jQuery, window, document);
