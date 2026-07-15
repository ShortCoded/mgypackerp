(function ($, window) {
    'use strict';

    var messages = window.coreFinancialPeriodsMessages || {};
    var tableSelector = '#financial-periods-table';
    var formSelector = '#financial-period-form';
    var protectedColumns = [0, 1, -1];
    var selected = new Set();
    var trashFilterSelector = '#financial_periods_trash_filter';
    var responsiveControlTarget = 1;
    var rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-record-checkbox';
    var selectAllSelector = '#select_all_records';
    var dataTable = null;

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

    function checkboxDocNum(checkbox) {
        return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
    }

    function showAlert($container, text, type) {
        if (!$container.length || !text) {
            return;
        }

        $container.html('<div class="alert alert-' + (type || 'danger') + ' mb-3">' + $('<div>').text(text).html() + '</div>');
    }

    function showAlertContent($container, $content, type) {
        if (!$container.length || !$content || !$content.length) {
            return;
        }

        $container.empty().append(
            $('<div></div>')
                .addClass('alert alert-' + (type || 'danger') + ' mb-3')
                .append($content)
        );
    }

    function showToast(icon, title) {
        if (window.AppAlerts && typeof window.AppAlerts.toast === 'function') {
            window.AppAlerts.toast(icon, title);
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
            icon: 'warning',
            title: options.title,
            text: options.text,
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: options.confirmButtonText,
            cancelButtonText: message('cancel') || message('no'),
            confirmButtonColor: '#d33',
            cancelButtonColor: '#748194',
            heightAuto: false
        });
    }

    function clearValidation($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $form.find('[data-form-alert]').empty();
    }

    function renderValidation($form, errors) {
        $.each(errors || {}, function (field, fieldMessages) {
            var normalizedField = String(field || '').replace('[]', '');
            var $field = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            $field.addClass('is-invalid');
            $form.find('[data-error-for="' + normalizedField + '"]').text((fieldMessages || [])[0] || '');
        });
    }

    function validationMessages(errors) {
        var result = [];

        $.each(errors || {}, function (field, fieldMessages) {
            var values = $.isArray(fieldMessages) ? fieldMessages : [fieldMessages];

            values.forEach(function (fieldMessage) {
                if (fieldMessage) {
                    result.push(fieldMessage);
                }
            });
        });

        return result;
    }

    function renderValidationSummary($form, errors) {
        var $content = $('<div></div>');
        var $list = $('<ul class="mb-0 ps-3"></ul>');
        var validationSummaryTitle = message('validationSummaryTitle') || message('validationFailed');
        var messagesList = validationMessages(errors);

        if (validationSummaryTitle) {
            $content.append($('<div class="fw-semibold mb-1"></div>').text(validationSummaryTitle));
        }

        messagesList.forEach(function (fieldMessage) {
            $list.append($('<li></li>').text(fieldMessage));
        });

        if (messagesList.length > 0) {
            $content.append($list);
            showAlertContent($form.find('[data-form-alert]'), $content, 'danger');
            return;
        }

        showAlert($form.find('[data-form-alert]'), message('validationFailed'), 'danger');
    }

    function clearFieldValidation($field) {
        var name = $field.attr('name');

        if (!name) {
            return;
        }

        $field.removeClass('is-invalid');
        $(formSelector).find('[data-error-for="' + name + '"]').text('');
    }

    function initTable() {
        var $table = $(tableSelector);

        if (!$table.length || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        var dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (config) { return config; };
        var options = dataTableOptions({
            ajax: {
                url: $table.data('ajax-url') || $table.data('url'),
                data: function (data) {
                    data.trash_filter = $(trashFilterSelector).val() || 'active';
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
            responsive: { details: { type: 'inline', target: responsiveControlTarget } },
            order: [[1, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: 'financial_periods.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'name', name: 'name', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 10 },
                { data: 'from_date', name: 'from_date', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 10 },
                { data: 'to_date', name: 'to_date', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 10 },
                { data: 'is_closed', name: 'is_closed', className: 'align-middle white-space-nowrap', responsivePriority: 20 },
                { data: 'created_by', name: 'created_by', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 30 },
                { data: 'created_at', name: 'created_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 30 },
                { data: 'updated_by', name: 'updated_by', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 30 },
                { data: 'updated_at', name: 'updated_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 30 },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'dt-actions no-colvis all align-middle white-space-nowrap', responsivePriority: 3 }
            ],
            columnDefs: [
                { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
                { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 3, searchable: false, targets: -1 },
                { responsivePriority: 10, targets: [2, 3, 4] },
                { responsivePriority: 20, targets: [5] },
                { responsivePriority: 30, targets: [6, 7, 8, 9] }
            ],
            createdRow: function (row) {
                $(row).addClass('btn-reveal-trigger');
            },
            initComplete: function () {
                if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                    window.AppDataTables.showColumns(this.api(), protectedColumns);
                }
                restoreSelectionState(this.api());
            },
            drawCallback: function () {
                restoreSelectionState(this.api());
                if (window.AppDataTables && typeof window.AppDataTables.applyFalconEnhancements === 'function') {
                    window.AppDataTables.applyFalconEnhancements(document);
                }
            }
        });

        dataTable = $table.DataTable(options);

        patchResponsiveControlTarget(dataTable);
        queueResponsiveControlSync(dataTable);

        dataTable.off('draw.dt.coreFinancialPeriodsResponsive column-visibility.dt.coreFinancialPeriodsResponsive column-sizing.dt.coreFinancialPeriodsResponsive responsive-resize.dt.coreFinancialPeriodsResponsive')
            .on('draw.dt.coreFinancialPeriodsResponsive column-visibility.dt.coreFinancialPeriodsResponsive column-sizing.dt.coreFinancialPeriodsResponsive responsive-resize.dt.coreFinancialPeriodsResponsive', function () {
                queueResponsiveControlSync(dataTable);
            });

        dataTable.on('draw.dt.coreFinancialPeriods column-visibility.dt.coreFinancialPeriods responsive-resize.dt.coreFinancialPeriods', function () {
            if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                window.AppDataTables.showColumns(dataTable, protectedColumns);
            }
            restoreSelectionState(dataTable);
        });

        restoreSelectionState(dataTable);
    }

    function reloadTable() {
        if ($.fn.DataTable.isDataTable(tableSelector)) {
            $(tableSelector).DataTable().ajax.reload(null, false);
        }
    }

    function selectedDocNums() {
        return Array.from(selected).filter(function (docNum) {
            return String(docNum || '').trim() !== '';
        });
    }

    function currentTable() {
        if (dataTable) {
            return dataTable;
        }

        if ($.fn.DataTable && $.fn.DataTable.isDataTable(tableSelector)) {
            dataTable = $(tableSelector).DataTable();
        }

        return dataTable;
    }

    function pageCheckboxes(api) {
        return api && typeof api.rows === 'function'
            ? $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-record-checkbox')
            : $(tableSelector).find(rowCheckboxSelector);
    }

    function patchResponsiveControlTarget(api) {
        var settings = api && typeof api.settings === 'function' ? api.settings()[0] : null;
        var responsive = settings && settings._responsive ? settings._responsive : null;

        if (!responsive || responsive._erpControlTargetPatched) {
            return;
        }

        responsive.c.details.target = responsiveControlTarget;
        responsive._erpControlTargetPatched = true;
        responsive._controlClass = function () {
            var dt = this.s.dt;

            dt.cells(null, function (index) {
                return index !== responsiveControlTarget;
            }, { page: 'current' }).nodes().to$().filter('.dtr-control').removeClass('dtr-control').removeAttr('tabindex').removeData('dtr-keyboard');
            dt.cells(null, responsiveControlTarget, { page: 'current' }).nodes().to$().addClass('dtr-control');
            this._tabIndexes();
        };
    }

    function syncResponsiveControlColumn(api) {
        patchResponsiveControlTarget(api);

        var $rows = api && typeof api.rows === 'function' ? $(api.rows({ page: 'current' }).nodes()) : $(tableSelector).find('tbody tr:not(.child)');

        $(tableSelector).find('thead th').eq(0).removeClass('dtr-control');
        $(tableSelector).find('thead th').eq(responsiveControlTarget).addClass('dtr-control');
        $rows.each(function () {
            var $cells = $(this).children('td, th');

            $cells.eq(0).removeClass('dtr-control').removeAttr('tabindex').removeData('dtr-keyboard');
            $cells.eq(responsiveControlTarget).addClass('dtr-control');
        });
    }

    function queueResponsiveControlSync(api) {
        syncResponsiveControlColumn(api);
        window.requestAnimationFrame(function () { syncResponsiveControlColumn(api); });
        window.setTimeout(function () { syncResponsiveControlColumn(api); }, 50);
    }

    function updateSelectAllState(api) {
        var selectableDocNums = pageCheckboxes(api).map(function () {
            return checkboxDocNum(this);
        }).get().filter(function (docNum) {
            return docNum !== '';
        });
        var checkedOnPage = selectableDocNums.filter(function (docNum) {
            return selected.has(docNum);
        }).length;

        $(selectAllSelector)
            .prop('checked', selectableDocNums.length > 0 && checkedOnPage === selectableDocNums.length)
            .prop('indeterminate', checkedOnPage > 0 && checkedOnPage < selectableDocNums.length);
    }

    function clearSelection(api) {
        selected.clear();
        pageCheckboxes(api).prop('checked', false);
        updateSelectAllState(api);
        updateBulkBar();
    }

    function updateBulkBar() {
        var $bar = $('#bulk_actions_bar');
        $bar.toggleClass('d-none', selected.size === 0).toggleClass('d-flex', selected.size > 0);
        $('#bulk_selected_count').text(selected.size);
        $('#bulk_action_apply').prop('disabled', selected.size === 0);
    }

    function restoreSelectionState(api) {
        var total = 0;
        var checked = 0;

        syncResponsiveControlColumn(api);
        pageCheckboxes(api).each(function () {
            var docNum = checkboxDocNum(this);
            var isChecked = selected.has(docNum);

            total++;
            if (isChecked) {
                checked++;
            }

            $(this).prop('checked', isChecked);
        });

        updateSelectAllState(api);

        updateBulkBar();
    }

    function updateUrlsAfterSave($form, response) {
        var data = response && response.data ? response.data : {};

        if (data.urls && data.urls.update) {
            $form.attr('action', data.urls.update);
        }

        if (data.urls && data.urls.edit && window.history) {
            window.history.replaceState({}, '', data.urls.edit);
        }

        if (data.old_doc_num && data.doc_num && data.old_doc_num !== data.doc_num) {
            $('a[href*="' + data.old_doc_num + '"]').each(function () {
                var $link = $(this);

                $link.attr('href', String($link.attr('href')).replace(data.old_doc_num, data.doc_num));

                if ($.trim($link.text()) === data.old_doc_num) {
                    $link.text(data.doc_num);
                }
            });
        }
    }

    $(document)
        .off('change.coreFinancialPeriodsTrash', trashFilterSelector)
        .on('change.coreFinancialPeriodsTrash', trashFilterSelector, function () {
            clearSelection(currentTable());
            reloadTable();
        })
        .off('change.coreFinancialPeriodsSelectAll', selectAllSelector)
        .on('change.coreFinancialPeriodsSelectAll', selectAllSelector, function () {
            var checked = $(this).is(':checked');
            var api = currentTable();

            pageCheckboxes(api).each(function () {
                var docNum = checkboxDocNum(this);

                if (docNum === '') {
                    return;
                }

                if (checked) {
                    selected.add(docNum);
                } else {
                    selected.delete(docNum);
                }

                $(this).prop('checked', checked);
            });

            updateSelectAllState(api);
            updateBulkBar();
        })
        .off('click.coreFinancialPeriodsSelectCell', tableSelector + ' tbody tr:not(.child) td.dt-select')
        .on('click.coreFinancialPeriodsSelectCell', tableSelector + ' tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            var $checkbox = $(this).find('input.js-record-select, input.js-record-checkbox').first();
            event.preventDefault();
            event.stopPropagation();

            if (!$checkbox.length || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        })
        .off('change.coreFinancialPeriods', tableSelector + ' ' + rowCheckboxSelector)
        .on('change.coreFinancialPeriods', tableSelector + ' ' + rowCheckboxSelector, function () {
            var docNum = checkboxDocNum(this);
            var api = currentTable();

            if (docNum === '') {
                return;
            }

            if (this.checked) {
                selected.add(docNum);
            } else {
                selected.delete(docNum);
            }

            updateSelectAllState(api);
            updateBulkBar();
        })
        .off('click.coreFinancialPeriodsSelectStop mousedown.coreFinancialPeriodsSelectStop mouseup.coreFinancialPeriodsSelectStop', '.js-record-select, .js-record-checkbox, #select_all_records, td.dt-select')
        .on('click.coreFinancialPeriodsSelectStop mousedown.coreFinancialPeriodsSelectStop mouseup.coreFinancialPeriodsSelectStop', '.js-record-select, .js-record-checkbox, #select_all_records, td.dt-select', function (event) {
            event.stopPropagation();
        })
        .off('click.coreFinancialPeriods', '.js-delete-record')
        .on('click.coreFinancialPeriods', '.js-delete-record', function () {
            var $button = $(this);
            var url = $button.data('url') || $button.data('delete-url');
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
                    url: url,
                    type: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    if (docNum !== '') {
                        selected.delete(docNum);
                    }

                    restoreSelectionState(currentTable());
                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('deleted'));

                    if (!$(tableSelector).length && $button.data('redirect-url')) {
                        window.location.href = $button.data('redirect-url');
                    }
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('dblclick.coreFinancialPeriods', tableSelector + ' tbody tr')
        .on('dblclick.coreFinancialPeriods', tableSelector + ' tbody tr', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            var editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        })
        .off('click.coreFinancialPeriodsBulk', '#bulk_action_apply')
        .on('click.coreFinancialPeriodsBulk', '#bulk_action_apply', function () {
            var url = $(tableSelector).data('bulk-delete-url');
            var docNums = selectedDocNums();

            if (docNums.length === 0) {
                showToast('info', message('noRecordsSelected'));
                return;
            }

            confirmDialog({
                title: message('bulkDeleteConfirmTitle'),
                text: message('bulkDeleteConfirmText').replace(':count', docNums.length),
                confirmButtonText: message('bulkDeleteConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: url,
                    type: 'DELETE',
                    headers: headers(),
                    data: { doc_nums: docNums }
                }).done(function (response) {
                    clearSelection(currentTable());
                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('bulkDeleted'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        })
        .off('click.coreFinancialPeriodsRestore', '.js-restore-record[data-restore-url]')
        .on('click.coreFinancialPeriodsRestore', '.js-restore-record[data-restore-url]', function () {
            var $button = $(this);
            var url = $button.data('restore-url');

            confirmDialog({
                title: message('restoreConfirmTitle'),
                text: message('restoreConfirmText'),
                confirmButtonText: message('restoreConfirmYes') || message('restore')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: url,
                    type: 'PATCH',
                    headers: headers()
                }).done(function (response) {
                    selected.delete(String($button.data('doc-num') || '').trim());
                    restoreSelectionState(currentTable());
                    reloadTable();
                    showToast('success', response && response.message ? response.message : message('restore'));
                }).fail(function (xhr) {
                    showToast('error', responseMessage(xhr));
                });
            });
        });

    $(document)
        .off('submit.coreFinancialPeriods', formSelector)
        .on('submit.coreFinancialPeriods', formSelector, function (event) {
            event.preventDefault();

            var $form = $(this);
            clearValidation($form);

            $.ajax({
                url: $form.attr('action'),
                type: $form.find('[name="_method"]').val() || $form.attr('method') || 'POST',
                headers: headers(),
                data: $form.serialize()
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

                if (response && response.message) {
                    showAlert($form.find('[data-form-alert]'), response.message, 'success');
                }
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidation($form, xhr.responseJSON.errors);
                    renderValidationSummary($form, xhr.responseJSON.errors);
                    return;
                }

                showAlert($form.find('[data-form-alert]'), responseMessage(xhr), 'danger');
            });
        })
        .off('click.coreFinancialPeriods', formSelector + ' [data-submit-action]')
        .on('click.coreFinancialPeriods', formSelector + ' [data-submit-action]', function () {
            $(formSelector).find('[name="submit_action"]').val($(this).data('submit-action'));
        })
        .off('input.coreFinancialPeriodsValidation change.coreFinancialPeriodsValidation', formSelector + ' input, ' + formSelector + ' select, ' + formSelector + ' textarea')
        .on('input.coreFinancialPeriodsValidation change.coreFinancialPeriodsValidation', formSelector + ' input, ' + formSelector + ' select, ' + formSelector + ' textarea', function () {
            clearFieldValidation($(this));
        })
        .off('submit.coreFinancialPeriods', '#financial-periods-document-number-settings-form')
        .on('submit.coreFinancialPeriods', '#financial-periods-document-number-settings-form', function (event) {
            event.preventDefault();

            var $form = $(this);
            clearValidation($form);

            $.ajax({
                url: $form.attr('action'),
                type: 'PUT',
                headers: headers(),
                data: $form.serialize()
            }).done(function (response) {
                var data = response && response.data ? response.data : {};

                if (Object.prototype.hasOwnProperty.call(data, 'prefix')) {
                    $form.find('[name="prefix"]').val(data.prefix || '');
                }

                if (Object.prototype.hasOwnProperty.call(data, 'padding')) {
                    $form.find('[name="padding"]').val(data.padding);
                }

                showAlert($form.find('[data-form-alert]'), response && response.message ? response.message : message('settingsSaved'), 'success');
                showToast('success', response && response.message ? response.message : message('settingsSaved'));
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidation($form, xhr.responseJSON.errors);
                    showAlert($form.find('[data-form-alert]'), message('validationFailed'), 'danger');
                    return;
                }

                showAlert($form.find('[data-form-alert]'), responseMessage(xhr), 'danger');
                showToast('error', responseMessage(xhr));
            });
        });

    initTable();
})(jQuery, window);
