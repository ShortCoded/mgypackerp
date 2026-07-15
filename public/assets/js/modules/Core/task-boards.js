(function ($, window, document) {
    'use strict';

    var messages = window.coreTaskBoardsMessages || {};
    var tableSelector = '#task-boards-table';
    var formSelector = '#task-board-form';
    var protectedColumns = [0, 1, -1];
    var deletedAuditColumnIndexes = [13, 14];
    var selectedDocNums = new Set();

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
            return;
        }

        if (window.Swal && title) {
            Swal.fire({
                icon: icon,
                text: title,
                toast: true,
                position: 'top-end',
                timer: 2500,
                showConfirmButton: false,
                heightAuto: false
            });
        }
    }

    function showInfo(text) {
        if (window.Swal && text) {
            Swal.fire({
                icon: 'info',
                text: text,
                confirmButtonText: message('confirm'),
                showCloseButton: true,
                allowEscapeKey: true,
                heightAuto: false
            });
            return;
        }

        showToast('info', text);
    }

    function confirmDialog(options) {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Swal.fire({
            icon: options.icon || 'warning',
            title: options.title,
            text: options.text,
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: options.confirmButtonText,
            cancelButtonText: message('cancel'),
            confirmButtonColor: options.confirmButtonColor || '#d33',
            cancelButtonColor: '#748194',
            heightAuto: false
        });
    }

    function showAlert($container, text, type) {
        if (!$container.length || !text) {
            return;
        }

        $container.html('<div class="alert alert-' + (type || 'danger') + ' mb-3">' + $('<div>').text(text).html() + '</div>');
    }

    function normalizeField(field) {
        return String(field || '').replace('[]', '').replace(/\.\d+$/, '');
    }

    function clearValidation($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.select2-selection.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('[data-form-alert]').empty();
    }

    function renderValidation($form, errors) {
        $.each(errors || {}, function (field, fieldMessages) {
            var normalizedField = normalizeField(field);
            var $field = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $field.addClass('is-invalid');

            if ($field.hasClass('select2-hidden-accessible')) {
                $field.next('.select2-container').find('.select2-selection').addClass('is-invalid');
            }

            $form.find('[data-error-for="' + normalizedField + '"]').first().text((fieldMessages || [])[0] || '');
        });
    }

    function tableApi() {
        if ($.fn.DataTable && $.fn.DataTable.isDataTable(tableSelector)) {
            return $(tableSelector).DataTable();
        }

        return null;
    }

    function reloadTable() {
        var api = tableApi();

        if (api) {
            api.ajax.reload(null, false);
        }
    }

    function recordFilterValue() {
        var value = String($('#task_boards_record_filter').val() || 'active');

        return ['active', 'inactive', 'trashed', 'all'].indexOf(value) !== -1 ? value : 'active';
    }

    function checkboxDocNum(checkbox) {
        return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
    }

    function pageCheckboxes(api) {
        if (api && typeof api.rows === 'function') {
            return $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-task-board-row-checkbox');
        }

        return $(tableSelector).find('tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-task-board-row-checkbox');
    }

    function updateSelectAllState(api) {
        var $checkboxes = pageCheckboxes(api);
        var selectableDocNums = $checkboxes.map(function () {
            return checkboxDocNum(this);
        }).get().filter(function (docNum) {
            return docNum !== '';
        });
        var checkedOnPage = selectableDocNums.filter(function (docNum) {
            return selectedDocNums.has(docNum);
        }).length;

        $('#select_all_records')
            .prop('checked', selectableDocNums.length > 0 && checkedOnPage === selectableDocNums.length)
            .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < selectableDocNums.length);
    }

    function optionVisibleForFilter($option, filter) {
        var filters = String($option.data('visible-filters') || '').split(/\s+/).filter(Boolean);

        return filters.length === 0 || filters.indexOf(filter) !== -1;
    }

    function syncBulkActionOptions() {
        var filter = recordFilterValue();
        var $select = $('#bulk_action_select');
        var firstVisible = null;

        $select.find('option').each(function () {
            var $option = $(this);
            var visible = optionVisibleForFilter($option, filter);

            $option.prop('disabled', !visible).prop('hidden', !visible);

            if (visible && firstVisible === null) {
                firstVisible = $option.val();
            }
        });

        if ($select.find('option:selected').prop('disabled') && firstVisible !== null) {
            $select.val(firstVisible);
        }
    }

    function syncDeletedAuditColumns(api) {
        if (!api || typeof api.column !== 'function') {
            return;
        }

        var showDeletedAudit = ['trashed', 'all'].indexOf(recordFilterValue()) !== -1;

        $.each(deletedAuditColumnIndexes, function (index, columnIndex) {
            api.column(columnIndex).visible(showDeletedAudit, false);
        });

        api.columns.adjust();

        if (api.responsive && typeof api.responsive.recalc === 'function') {
            api.responsive.recalc();
        }
    }

    function updateBulkActionsUi() {
        var selectedCount = selectedDocNums.size;
        var $actions = $('#bulk_actions_bar');
        var $applyButton = $('#bulk_action_apply');
        var applyLabel = $applyButton.data('label') || '';

        syncBulkActionOptions();

        $actions
            .toggleClass('d-none', selectedCount === 0)
            .toggleClass('d-flex', selectedCount > 0);

        $('#bulk_selected_count').text(selectedCount);

        $applyButton
            .prop('disabled', selectedCount === 0 || $('#bulk_action_select option:not(:disabled)').length === 0)
            .find('span:last')
            .text(applyLabel + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));
    }

    function restoreSelectionState(api) {
        pageCheckboxes(api).each(function () {
            var docNum = checkboxDocNum(this);

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

    function bulkUrl(action) {
        var $table = $(tableSelector);
        var urls = {
            delete: $table.data('bulk-delete-url'),
            activate: $table.data('bulk-activate-url'),
            deactivate: $table.data('bulk-deactivate-url'),
            restore: $table.data('bulk-restore-url')
        };

        return urls[action] || '';
    }

    function bulkConfirm(action, count) {
        var textKey = {
            delete: 'bulkDeleteConfirmText',
            activate: 'bulkActivateConfirmText',
            deactivate: 'bulkDeactivateConfirmText',
            restore: 'bulkRestoreConfirmText'
        }[action];

        return {
            title: message({
                delete: 'bulkDeleteConfirmTitle',
                activate: 'bulkActivateConfirmTitle',
                deactivate: 'bulkDeactivateConfirmTitle',
                restore: 'bulkRestoreConfirmTitle'
            }[action]),
            text: String(message(textKey) || '').replace(':count', count),
            confirmButtonText: message({
                delete: 'bulkDeleteConfirmYes',
                activate: 'bulkActivateConfirmYes',
                deactivate: 'bulkDeactivateConfirmYes',
                restore: 'bulkRestoreConfirmYes'
            }[action]),
            confirmButtonColor: action === 'restore' || action === 'activate' ? '#00a65a' : '#d33'
        };
    }

    function initTable() {
        var $table = $(tableSelector);

        if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        var options = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options({
                ajax: {
                    url: $table.data('ajax-url'),
                    data: function (data) {
                        data.record_filter = recordFilterValue();
                    }
                },
                processing: true,
                serverSide: true,
                stateSave: true,
                stateLoadParams: function (settings, data) {
                    if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
                        window.AppDataTables.protectStateColumns(data, protectedColumns);
                    }
                },
                stateSaveParams: function (settings, data) {
                    if (window.AppDataTables && typeof window.AppDataTables.protectStateColumns === 'function') {
                        window.AppDataTables.protectStateColumns(data, protectedColumns);
                    }
                },
                autoWidth: false,
                responsive: { details: { type: 'inline', target: 1 } },
                order: [[12, 'desc']],
                columns: [
                    { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                    { data: 'doc_num', name: 'task_boards.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                    { data: 'name', name: 'task_boards.name', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 3 },
                    { data: 'description', name: 'task_boards.description', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 40 },
                    { data: 'public_status', name: 'public_status', orderable: false, searchable: false, className: 'align-middle white-space-nowrap', responsivePriority: 10 },
                    { data: 'access_code_status', name: 'access_code_status', orderable: false, searchable: false, className: 'align-middle white-space-nowrap', responsivePriority: 20 },
                    { data: 'assignments', name: 'assignments', orderable: false, searchable: false, className: 'align-middle white-space-nowrap', responsivePriority: 30 },
                    { data: 'tasks_count', name: 'tasks_count', orderable: false, searchable: false, className: 'align-middle white-space-nowrap text-center', responsivePriority: 35 },
                    { data: 'operational_status', name: 'task_boards.is_active', className: 'align-middle white-space-nowrap dt-status', responsivePriority: 15 },
                    { data: 'created_by', name: 'created_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis', responsivePriority: 45 },
                    { data: 'created_at', name: 'task_boards.created_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 45 },
                    { data: 'updated_by', name: 'updated_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis', responsivePriority: 45 },
                    { data: 'updated_at', name: 'task_boards.updated_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 45 },
                    { data: 'deleted_by', name: 'deleted_by', className: 'align-middle white-space-nowrap dt-text dt-ellipsis', responsivePriority: 60 },
                    { data: 'deleted_at', name: 'task_boards.deleted_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 60 },
                    { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'dt-actions no-colvis all align-middle white-space-nowrap', responsivePriority: 4 }
                ],
                createdRow: function (row) {
                    $(row).addClass('btn-reveal-trigger');
                },
                initComplete: function () {
                    if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                        window.AppDataTables.showColumns(this.api(), protectedColumns);
                    }

                    syncDeletedAuditColumns(this.api());
                    restoreSelectionState(this.api());
                },
                drawCallback: function () {
                    syncDeletedAuditColumns(this.api());
                    restoreSelectionState(this.api());

                    if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                        window.AppDataTables.applyFalconEnhancements(document);
                    }
                }
            })
            : {};

        $table.DataTable(options);
    }

    function updateUrlsAfterSave($form, response) {
        var data = response && response.data ? response.data : {};

        if (data.urls && data.urls.update) {
            $form.attr('action', data.urls.update);
        }

        if (data.urls && data.urls.edit && window.history) {
            window.history.replaceState({}, '', data.urls.edit);
        }

        updatePublicLinkUrls(data);
    }

    function updatePublicLinkUrls(data) {
        data = data || {};

        if (data.display_url) {
            $('#display_url').val(data.display_url);
            $('[data-task-board-public-link="display"]')
                .filter('.js-copy-task-board-url')
                .attr('data-display-url', data.display_url)
                .data('display-url', data.display_url);
            $('a[data-task-board-public-link="display"]').attr('href', data.display_url);
        }

        if (data.user_display_url) {
            $('#user_display_url').val(data.user_display_url);
            $('[data-task-board-public-link="user-display"]')
                .filter('.js-copy-task-board-url')
                .attr('data-display-url', data.user_display_url)
                .data('display-url', data.user_display_url);
            $('a[data-task-board-public-link="user-display"]').attr('href', data.user_display_url);
        }
    }

    function resetCreateForm($form) {
        if ($form[0]) {
            $form[0].reset();
        }

        $form.find('[name="submit_action"]').val('save');
        $form.find('.js-select2-ajax').val(null).trigger('change.select2');
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        var $temp = $('<textarea readonly>').css({ position: 'absolute', left: '-9999px' }).val(text).appendTo(document.body);
        $temp[0].select();

        try {
            document.execCommand('copy');
            $temp.remove();
            return $.Deferred().resolve().promise();
        } catch (error) {
            $temp.remove();
            return $.Deferred().reject(error).promise();
        }
    }

    $(document)
        .off('change.coreTaskBoardsFilter', '#task_boards_record_filter')
        .on('change.coreTaskBoardsFilter', '#task_boards_record_filter', function () {
            var api = tableApi();

            clearSelection(api);
            syncDeletedAuditColumns(api);
            reloadTable();
        })
        .off('change.coreTaskBoardsSelectAll', '#select_all_records')
        .on('change.coreTaskBoardsSelectAll', '#select_all_records', function () {
            var checked = $(this).is(':checked');
            var api = tableApi();

            pageCheckboxes(api).each(function () {
                var docNum = checkboxDocNum(this);

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

            updateSelectAllState(api);
            updateBulkActionsUi();
        })
        .off('change.coreTaskBoardsSelect', tableSelector + ' input.js-record-select, ' + tableSelector + ' input.js-task-board-row-checkbox')
        .on('change.coreTaskBoardsSelect', tableSelector + ' input.js-record-select, ' + tableSelector + ' input.js-task-board-row-checkbox', function () {
            var docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(tableApi());
            updateBulkActionsUi();
        })
        .off('click.coreTaskBoardsSelectCell', tableSelector + ' tbody tr:not(.child) td.dt-select')
        .on('click.coreTaskBoardsSelectCell', tableSelector + ' tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            var $checkbox = $(this).find('input.js-record-select, input.js-task-board-row-checkbox').first();

            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        })
        .off('click.coreTaskBoardsSelectStop mousedown.coreTaskBoardsSelectStop mouseup.coreTaskBoardsSelectStop', '.js-record-select, #select_all_records, td.dt-select')
        .on('click.coreTaskBoardsSelectStop mousedown.coreTaskBoardsSelectStop mouseup.coreTaskBoardsSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
            event.stopPropagation();
        })
        .off('click.coreTaskBoardsBulk', '#bulk_action_apply')
        .on('click.coreTaskBoardsBulk', '#bulk_action_apply', function () {
            var $button = $(this);
            var docNums = Array.from(selectedDocNums);
            var action = String($('#bulk_action_select').val() || '');
            var url = bulkUrl(action);

            if (docNums.length === 0) {
                updateBulkActionsUi();
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

                $button.prop('disabled', true);

                $.ajax({
                    url: url,
                    type: action === 'delete' ? 'DELETE' : 'PATCH',
                    headers: headers(),
                    data: { doc_nums: docNums }
                }).done(function (response) {
                    clearSelection(tableApi());
                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('saved'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                }).always(function () {
                    $button.prop('disabled', false);
                    updateBulkActionsUi();
                });
            });
        })
        .off('click.coreTaskBoards', '.js-copy-task-board-url')
        .on('click.coreTaskBoards', '.js-copy-task-board-url', function () {
            var url = String($(this).data('display-url') || '').trim();

            if (url === '') {
                showToast('error', message('copyFailed'));
                return;
            }

            $.when(copyText(url)).done(function () {
                showToast('success', message('urlCopied'));
            }).fail(function () {
                showToast('error', message('copyFailed'));
            });
        })
        .off('click.coreTaskBoards', '.js-delete-task-board')
        .on('click.coreTaskBoards', '.js-delete-task-board', function () {
            var $button = $(this);
            var redirectUrl = $button.data('redirect-url');
            var docNum = String($button.data('doc-num') || '').trim();

            confirmDialog({
                title: message('deleteConfirmTitle'),
                text: message('deleteConfirmText'),
                confirmButtonText: message('deleteConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: $button.data('url'),
                    type: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    selectedDocNums.delete(docNum);

                    if (redirectUrl) {
                        window.location.href = redirectUrl;
                        return;
                    }

                    reloadTable();
                    updateBulkActionsUi();
                    showToast('success', response && response.message ? response.message : message('deleted'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.coreTaskBoards', '.js-restore-task-board')
        .on('click.coreTaskBoards', '.js-restore-task-board', function () {
            var $button = $(this);
            var redirectUrl = $button.data('redirect-url');
            var docNum = String($button.data('doc-num') || '').trim();

            confirmDialog({
                title: message('restoreConfirmTitle'),
                text: message('restoreConfirmText'),
                confirmButtonText: message('restoreConfirmYes'),
                confirmButtonColor: '#00a65a'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $button.prop('disabled', true);

                $.ajax({
                    url: $button.data('url'),
                    type: 'PATCH',
                    headers: headers()
                }).done(function (response) {
                    selectedDocNums.delete(docNum);

                    if (redirectUrl) {
                        window.location.href = redirectUrl;
                        return;
                    }

                    if ($(tableSelector).length > 0) {
                        reloadTable();
                        updateBulkActionsUi();
                    } else {
                        window.location.reload();
                    }

                    showToast('success', response && response.message ? response.message : message('saved'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
        })
        .off('click.coreTaskBoards', '.js-regenerate-task-board-url')
        .on('click.coreTaskBoards', '.js-regenerate-task-board-url', function () {
            var $button = $(this);

            confirmDialog({
                title: message('regenerateConfirmTitle'),
                text: message('regenerateConfirmText'),
                confirmButtonText: message('regenerateConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $button.prop('disabled', true);

                $.ajax({
                    url: $button.data('url'),
                    type: 'PATCH',
                    headers: headers()
                }).done(function (response) {
                    if ($(formSelector).length > 0) {
                        updatePublicLinkUrls(response && response.data ? response.data : {});
                    }

                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('saved'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
        })
        .off('dblclick.coreTaskBoards', tableSelector + ' tbody tr')
        .on('dblclick.coreTaskBoards', tableSelector + ' tbody tr', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            var editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

    $(document)
        .off('submit.coreTaskBoards', formSelector)
        .on('submit.coreTaskBoards', formSelector, function (event) {
            event.preventDefault();

            var $form = $(this);
            var formData = new FormData(this);

            clearValidation($form);

            $.ajax({
                url: $form.attr('action'),
                type: $form.find('[name="_method"]').val() || $form.attr('method') || 'POST',
                headers: headers(),
                data: formData,
                processData: false,
                contentType: false
            }).done(function (response) {
                if (response && response.success === false && response.type === 'no_changes') {
                    showAlert($form.find('[data-form-alert]'), response.message || message('noChanges'), 'warning');
                    showInfo(response.message || message('noChanges'));
                    return;
                }

                updateUrlsAfterSave($form, response);
                showToast('success', response && response.message ? response.message : message('saved'));

                if (response && response.redirect) {
                    window.location.href = response.redirect;
                    return;
                }

                if (response && response.reset_form) {
                    resetCreateForm($form);
                }

                if (response && response.message) {
                    showAlert($form.find('[data-form-alert]'), response.message, 'success');
                }
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidation($form, xhr.responseJSON.errors);
                    showAlert($form.find('[data-form-alert]'), message('validationFailed'), 'danger');
                    return;
                }

                showAlert($form.find('[data-form-alert]'), responseMessage(xhr), 'danger');
            });
        })
        .off('click.coreTaskBoards', formSelector + ' [data-submit-action]')
        .on('click.coreTaskBoards', formSelector + ' [data-submit-action]', function () {
            $(formSelector).find('[name="submit_action"]').val($(this).data('submit-action'));
        })
        .off('input.coreTaskBoards change.coreTaskBoards select2:select.coreTaskBoards select2:clear.coreTaskBoards', formSelector + ' .is-invalid, ' + formSelector + ' .select2-hidden-accessible')
        .on('input.coreTaskBoards change.coreTaskBoards select2:select.coreTaskBoards select2:clear.coreTaskBoards', formSelector + ' .is-invalid, ' + formSelector + ' .select2-hidden-accessible', function () {
            var $field = $(this);
            var name = normalizeField($field.attr('name'));

            $field.removeClass('is-invalid');
            $field.next('.select2-container').find('.select2-selection').removeClass('is-invalid');
            $(formSelector).find('[data-error-for="' + name + '"]').text('');
        });

    initTable();
})(jQuery, window, document);
