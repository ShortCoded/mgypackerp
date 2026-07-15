(function ($, window, document) {
    'use strict';

    const messages = window.businessPartnerMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    let businessPartnerTable = null;

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
            customers: {
                doc_num: tableName + '.doc_number',
                name: tableName + '.name',
                account: 'accounts.account_code',
                phone: tableName + '.phone',
                mobile: tableName + '.mobile',
                email: tableName + '.email',
                tax_number: tableName + '.tax_number',
                status: tableName + '.status',
                created_by: 'created_users.name',
                created_at: tableName + '.created_at',
                updated_by: 'updated_users.name',
                updated_at: tableName + '.updated_at'
            },
            suppliers: {
                doc_num: tableName + '.doc_number',
                name: tableName + '.name',
                account: 'accounts.account_code',
                phone: tableName + '.phone',
                mobile: tableName + '.mobile',
                email: tableName + '.email',
                tax_number: tableName + '.tax_number',
                status: tableName + '.status',
                created_by: 'created_users.name',
                created_at: tableName + '.created_at',
                updated_by: 'updated_users.name',
                updated_at: tableName + '.updated_at'
            }
        };

        return maps[resource] && maps[resource][column] ? maps[resource][column] : column;
    }

    function columnClass(column, index) {
        if (index === 0) {
            return 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control';
        }

        if (column === 'status') {
            return 'align-middle white-space-nowrap dt-status';
        }

        if (['created_at', 'updated_at'].indexOf(column) !== -1) {
            return 'align-middle white-space-nowrap dt-date';
        }

        return 'align-middle white-space-nowrap dt-text dt-ellipsis';
    }

    function tableColumns(resource, tableName) {
        const configuredColumns = window.businessPartnerCrudColumns || {
            customers: ['doc_num', 'name', 'account', 'phone', 'mobile', 'email', 'tax_number', 'status', 'created_by', 'created_at', 'updated_by', 'updated_at'],
            suppliers: ['doc_num', 'name', 'account', 'phone', 'mobile', 'email', 'tax_number', 'status', 'created_by', 'created_at', 'updated_by', 'updated_at']
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

    function alertElement($form) {
        let $alert = $form.find('.js-form-alert, .js-business-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-form-alert" role="alert"><div class="js-form-alert-message"></div><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
            $form.find('.card-body, .modal-body').first().prepend($alert);
        }

        return $alert;
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.select2-selection.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-form-alert-message, .js-business-alert-message')
            .empty();
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

        clearFormErrors($form);

        (allMessages.length ? allMessages : [msg('validationFailed')]).forEach(function (message) {
            $list.append($('<li></li>').text(message));
        });

        $alert
            .removeClass('d-none alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-form-alert-message, .js-business-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const bracketField = bracketName(field);
            const normalizedField = field.replace(/\.\d+$/, '').replace(/\.\d+\./g, '.');
            const baseField = normalizedField.split('.')[0];
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const $input = $form.find('[name="' + bracketField + '"], [name="' + bracketField + '[]"], [name="' + field + '"], [name="' + normalizedField + '"], [name="' + normalizedField + '[]"], [name="' + baseField + '"], [name="' + baseField + '[]"]');

            $input.addClass('is-invalid');
            $input.filter('select').each(function () {
                select2Selection($(this)).addClass('is-invalid');
            });
            $form.find('[data-error-for="' + field + '"], [data-error-for="' + normalizedField + '"], [data-error-for="' + baseField + '"]').text(message || '');
        });

        focusFirstInvalid($form);
    }

    function showFormNotice($form, message, type) {
        alertElement($form)
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .find('.js-form-alert-message, .js-business-alert-message')
            .text(message || msg('unexpectedError'));
    }

    function setLoading($button, loading) {
        $button.prop('disabled', loading);
        $button.css('cursor', loading ? 'wait' : '');
        $('body').css('cursor', loading ? 'wait' : '');
    }

    function focusPrimaryField($form) {
        if ($form.data('mode') === 'view') {
            return;
        }

        const primaryField = String($form.data('primary-focus') || 'name');
        const $field = $form.find('[name="' + primaryField + '"]:visible:not([readonly]):not(:disabled)').first();

        if ($field.length > 0) {
            $field.trigger('focus');
        }
    }

    function bracketName(field) {
        const parts = String(field || '').split('.');

        if (parts.length < 2) {
            return field;
        }

        return parts.shift() + parts.map(function (part) {
            return '[' + part + ']';
        }).join('');
    }

    function dotName(name) {
        return String(name || '').replace(/\]/g, '').replace(/\[/g, '.');
    }

    function select2Selection($field) {
        return $field.next('.select2-container').find('.select2-selection');
    }

    function focusFirstInvalid($form) {
        const $field = $form.find('.is-invalid').filter('input, select, textarea').first();

        if ($field.length === 0) {
            return;
        }

        if ($field.is('select') && $.fn.select2 && $field.data('select2')) {
            select2Selection($field).trigger('focus');
            window.setTimeout(function () {
                $field.select2('open');
            }, 80);
            return;
        }

        $field.trigger('focus');
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

        if (window.history && window.location.pathname.indexOf(data.old_doc_num) !== -1) {
            window.history.replaceState({}, '', window.location.pathname.replace(data.old_doc_num, data.doc_num) + window.location.search + window.location.hash);
        }
    }

    function resetCreateForm($form) {
        $form.find('[name="account_group_doc_num"]').val(null).trigger('change');
        $form.find('[name="country_doc_num"], [name="governorate_doc_num"], [name="city_doc_num"], [name="area_doc_num"]').val(null).trigger('change');
        $form.find('[name="name"], [name="phone"], [name="mobile"], [name="email"], [name="tax_number"], [name="commercial_register"], [name="national_id"], [name="contact_person"], [name="address"], [name="notes"]').val('');
        $form.find('[name="status"]').val('active');
        $form.find('[name="submit_action"]').val('save');
        $form.find('[name="clone_source_token"]').remove();
        $form.find('[name="doc_number"]').val('');
        $form.find('.js-credit-limits-table tbody').empty();
        focusPrimaryField($form);
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

    function initSelect2() {
        if (!$.fn.select2) {
            return;
        }

        const select2Messages = {
            errorLoading: function () { return msg('select2ErrorLoading'); },
            inputTooShort: function () { return msg('select2InputTooShort'); },
            loadingMore: function () { return msg('select2LoadingMore'); },
            noResults: function () { return msg('select2NoResults'); },
            removeItem: function () { return msg('select2RemoveItem'); },
            searching: function () { return msg('select2Searching'); }
        };

        $('.js-select2-ajax').each(function () {
            const $select = $(this);

            if ($select.data('select2')) {
                return;
            }

            const dependsOn = String($select.data('depends-on') || '');
            const dependentParam = String($select.data('dependent-param') || '');

            $select.select2({
                theme: 'bootstrap-5',
                width: '100%',
                dir: document.documentElement.getAttribute('dir') || 'ltr',
                allowClear: String($select.data('allow-clear')) === 'true',
                placeholder: $select.data('placeholder') || '',
                language: select2Messages,
                ajax: {
                    url: $select.data('url'),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        const payload = {
                            q: params.term || '',
                            page: params.page || 1
                        };

                        if (dependsOn && dependentParam) {
                            payload[dependentParam] = $(dependsOn).val() || '';
                        }

                        return payload;
                    }
                }
            });

            if (dependsOn) {
                $(document).off('change.businessLocationDependency', dependsOn).on('change.businessLocationDependency', dependsOn, function () {
                    $select.val(null).trigger('change');
                });
            }
        });
    }

    function initTable() {
        const $table = $('.js-business-partner-table').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const $root = $('[data-business-partner-root]').first();
        const resource = String($root.data('resource') || '');
        const tableName = String($table.data('table-name') || resource);
        const protectedColumns = [0, 1, -1];
        const selectedDocNums = new Set();
        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) {
                return options;
            };
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select';
        const selectAllSelector = '#select_all_records';
        const trashFilterSelector = '.js-business-trash-filter';

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            if (api && typeof api.rows === 'function') {
                return $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select');
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
            }
        }

        function showProtectedColumns(api) {
            if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                window.AppDataTables.showColumns(api, protectedColumns);
            }
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
            businessPartnerTable.ajax.reload(null, false);
        }

        businessPartnerTable = $table.DataTable(dataTableOptions({
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
                if (window.AppContactActions && typeof window.AppContactActions.init === 'function') {
                    window.AppContactActions.init($table[0]);
                }
            }
        }));

        $(trashFilterSelector).off('change.businessTrash').on('change.businessTrash', function () {
            clearSelection(businessPartnerTable);
            reloadTable();
        });

        $(selectAllSelector).off('change.businessSelectAll').on('change.businessSelectAll', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(businessPartnerTable).each(function () {
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

            updateSelectAllState(businessPartnerTable);
            updateBulkActionsUi();
        });

        $table.off('change.businessSelect', rowCheckboxSelector).on('change.businessSelect', rowCheckboxSelector, function () {
            const docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(businessPartnerTable);
            updateBulkActionsUi();
        });

        $table.off('dblclick.businessEditRow', 'tbody tr:not(.child)').on('dblclick.businessEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $(document)
            .off('business:deleted.businessTable')
            .on('business:deleted.businessTable', function (event, docNum) {
                if (docNum) {
                    selectedDocNums.delete(docNum);
                }

                reloadTable();
                updateSelectAllState(businessPartnerTable);
                updateBulkActionsUi();
            })
            .off('business:restored.businessTable')
            .on('business:restored.businessTable', function (event, docNum) {
                if (docNum) {
                    selectedDocNums.delete(docNum);
                }

                reloadTable();
                updateSelectAllState(businessPartnerTable);
                updateBulkActionsUi();
            });

        $('#bulk_action_apply').off('click.businessBulk').on('click.businessBulk', function () {
            const docNums = Array.from(selectedDocNums);
            const action = $('#bulk_action_select').val();

            if (docNums.length === 0) {
                updateBulkActionsUi();
                showToast('warning', msg('noRowsSelected'));
                return;
            }

            if (action !== 'delete') {
                return;
            }

            confirmDialog({
                title: msg('bulkDeleteConfirmTitle'),
                text: String(msg('bulkDeleteConfirmText')).replace(':count', docNums.length),
                confirmButtonText: msg('bulkDeleteConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: $root.data('bulk-delete-url'),
                    method: 'DELETE',
                    data: { doc_nums: docNums },
                    headers: headers()
                }).done(function (response) {
                    clearSelection(businessPartnerTable);
                    reloadTable();
                    showToast('success', response.message);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'));
                });
            });
        });
    }

    function initForm() {
        $(document)
            .off('click.businessSubmitAction', '.js-finance-submit-action, .js-business-submit-action, .js-submit-action')
            .on('click.businessSubmitAction', '.js-finance-submit-action, .js-business-submit-action, .js-submit-action', function () {
                const $button = $(this);

                $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
                $button.closest('form').data('submit-button', $button);
            });

        $(document).off('submit.businessForm', '.js-business-partner-form, .js-crud-form').on('submit.businessForm', '.js-business-partner-form, .js-crud-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $button = $form.data('submit-button') || $form.find('[type="submit"]').first();
            const method = $form.find('input[name="_method"]').val() || $form.attr('method') || 'POST';

            clearFormErrors($form);
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

        $(document).off('input.businessForm change.businessForm select2:select.businessForm select2:clear.businessForm', '.js-business-partner-form .is-invalid, .js-crud-form .is-invalid').on('input.businessForm change.businessForm select2:select.businessForm select2:clear.businessForm', '.js-business-partner-form .is-invalid, .js-crud-form .is-invalid', function () {
            const $input = $(this);
            const field = dotName(($input.attr('name') || '').replace('[]', ''));

            $input.removeClass('is-invalid');
            select2Selection($input).removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"]').text('');
        });

        $('.js-business-partner-form').each(function () {
            focusPrimaryField($(this));
        });
    }

    function initDocumentNumberSettings() {
        $(document)
            .off('submit.businessDocSettings', '.js-business-document-number-settings-form')
            .on('submit.businessDocSettings', '.js-business-document-number-settings-form', function (event) {
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

    function initInlineGroupCreate() {
        $(document)
            .off('click.businessInlineOpen', '.js-business-partner-inline-create')
            .on('click.businessInlineOpen', '.js-business-partner-inline-create', function () {
                const $modal = $($(this).data('modal'));
                const $form = $modal.find('form');
                clearFormErrors($form);
                $form.find('[name="name"], [name="notes"]').val('');

                $modal.off('shown.bs.modal.businessInline').on('shown.bs.modal.businessInline', function () {
                    $form.find('[name="name"]').trigger('focus');
                });

                const modal = modalApi($modal);
                if (modal) {
                    modal.show();
                    return;
                }

                $modal.modal('show');
            })
            .off('submit.businessInline', '.js-business-partner-inline-form')
            .on('submit.businessInline', '.js-business-partner-inline-form', function (event) {
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
            });
    }

    function nextCreditLimitIndex($table) {
        const indexes = $table.find('.js-credit-limit-row :input[name^="credit_limits["]').map(function () {
            const match = String($(this).attr('name') || '').match(/^credit_limits\[(\d+)]/);

            return match ? Number(match[1]) : -1;
        }).get();

        return indexes.length ? Math.max.apply(Math, indexes) + 1 : 0;
    }

    function focusCreditLimitCurrency($row) {
        window.setTimeout(function () {
            const $select = $row.find('.js-credit-limit-currency').first();

            if ($select.length === 0) {
                return;
            }

            if ($.fn.select2 && $select.data('select2')) {
                $select.select2('open');
                return;
            }

            $select.trigger('focus');
        }, 80);
    }

    function addCreditLimitRow($table, focusRow) {
        const template = String($('#credit-limit-row-template').html() || '');

        if (!template) {
            return $();
        }

        const $row = $(template.replace(/__INDEX__/g, String(nextCreditLimitIndex($table))));
        $table.find('tbody').append($row);
        initSelect2();

        if (focusRow) {
            focusCreditLimitCurrency($row);
        }

        return $row;
    }

    function duplicateCreditLimitRow($source) {
        const $table = $source.closest('.js-credit-limits-table');
        const template = String($('#credit-limit-row-template').html() || '');
        const index = nextCreditLimitIndex($table);
        const $row = $(template.replace(/__INDEX__/g, String(index)));
        const $sourceCurrency = $source.find('[name$="[currency_doc_num]"]');
        const currencyValue = $sourceCurrency.val();
        const currencyText = $sourceCurrency.find('option:selected').text();

        if (currencyValue) {
            $row.find('[name$="[currency_doc_num]"]').append(new Option(currencyText, currencyValue, true, true));
        }

        $row.find('[name$="[credit_limit]"]').val($source.find('[name$="[credit_limit]"]').val());
        $row.find('[name$="[notes]"]').val($source.find('[name$="[notes]"]').val());
        $source.after($row);
        initSelect2();
        focusCreditLimitCurrency($row);

        return $row;
    }

    function removeCreditLimitRow($row) {
        const $focusTarget = $row.next('.js-credit-limit-row').length
            ? $row.next('.js-credit-limit-row')
            : $row.prev('.js-credit-limit-row');

        $row.remove();

        if ($focusTarget.length > 0) {
            focusCreditLimitCurrency($focusTarget);
        }
    }

    function isAltShortcut(event, codes, keyCodes, legacyKeys) {
        if (window.AppShortcuts && typeof window.AppShortcuts.isAltShortcut === 'function') {
            return !event.ctrlKey && window.AppShortcuts.isAltShortcut(event, codes, keyCodes, legacyKeys);
        }

        const key = String(event.key || '').toLowerCase();
        const code = event.code || '';
        const keyCode = Number(event.keyCode || event.which || 0);

        return event.altKey === true
            && !event.ctrlKey
            && !event.metaKey
            && !event.shiftKey
            && (
                codes.indexOf(code) !== -1
                || keyCodes.indexOf(keyCode) !== -1
                || legacyKeys.indexOf(key) !== -1
            );
    }

    function isAltDelete(event) {
        const code = event.code || '';
        const keyCode = Number(event.keyCode || event.which || 0);

        return event.altKey === true
            && !event.ctrlKey
            && !event.metaKey
            && !event.shiftKey
            && (event.key === 'Delete' || code === 'Delete' || keyCode === 46);
    }

    function creditLimitShortcutBlockedBySelect2(target) {
        return $(target).closest('.select2-container, .select2-dropdown, .select2-search__field').length > 0;
    }

    function activeCreditLimitPanel(target) {
        const $targetPanel = $(target).closest('.business-partner-credit-tab.show, .business-partner-credit-tab.active');

        if ($targetPanel.length > 0) {
            return $targetPanel;
        }

        return $('.business-partner-credit-tab.show, .business-partner-credit-tab.active').first();
    }

    function initCreditLimits() {
        $(document)
            .off('click.businessCreditLimitAdd', '.js-credit-limit-add')
            .on('click.businessCreditLimitAdd', '.js-credit-limit-add', function () {
                const $table = $(this).closest('.tab-pane, .card-body, form').find('.js-credit-limits-table').first();
                addCreditLimitRow($table, true);
            })
            .off('click.businessCreditLimitDelete', '.js-credit-limit-delete')
            .on('click.businessCreditLimitDelete', '.js-credit-limit-delete', function () {
                removeCreditLimitRow($(this).closest('.js-credit-limit-row'));
            })
            .off('click.businessCreditLimitDuplicate', '.js-credit-limit-duplicate')
            .on('click.businessCreditLimitDuplicate', '.js-credit-limit-duplicate', function () {
                duplicateCreditLimitRow($(this).closest('.js-credit-limit-row'));
            })
            .off('keydown.businessCreditLimitShortcuts')
            .on('keydown.businessCreditLimitShortcuts', function (event) {
                const $panel = activeCreditLimitPanel(event.target);

                if ($panel.length === 0 || $panel.find('.js-credit-limits-table').length === 0 || creditLimitShortcutBlockedBySelect2(event.target)) {
                    return;
                }

                const $row = $(event.target).closest('.js-credit-limit-row');

                if (isAltShortcut(event, ['KeyD'], [68], ['d']) && $row.length > 0) {
                    event.preventDefault();
                    event.stopPropagation();
                    duplicateCreditLimitRow($row);
                    return;
                }

                if (isAltDelete(event) && $row.length > 0) {
                    event.preventDefault();
                    event.stopPropagation();
                    removeCreditLimitRow($row);
                    return;
                }

                if (!isAltShortcut(event, ['KeyN'], [78], ['n'])) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                addCreditLimitRow($panel.find('.js-credit-limits-table').first(), true);
            });
    }

    function initLocationInlineCreate() {
        $(document)
            .off('click.businessLocationInlineOpen', '.js-business-location-inline-create')
            .on('click.businessLocationInlineOpen', '.js-business-location-inline-create', function () {
                const $button = $(this);
                const parentSelector = String($button.data('parent-select') || '');
                const parentValue = parentSelector ? String($(parentSelector).val() || '') : '';

                const $modal = $($button.data('modal'));
                const $form = $modal.find('form');

                clearFormErrors($form);
                $form.attr('action', $button.data('url'));
                $form.find('[name="name"], [name="notes"]').val('');
                $form.find('[name="target_select"]').val(String($button.data('target-select') || ''));
                $form.find('[name="parent_field"]').val(String($button.data('parent-field') || ''));
                $form.find('[name="parent_value"]').val(parentValue);
                $modal.find('.js-location-inline-title').text($button.data('title') || msg('saved'));

                $modal.off('shown.bs.modal.businessLocationInline').on('shown.bs.modal.businessLocationInline', function () {
                    $form.find('[name="name"]').trigger('focus');
                });

                const modal = modalApi($modal);
                if (modal) {
                    modal.show();
                    return;
                }

                $modal.modal('show');
            })
            .off('submit.businessLocationInline', '.js-business-location-inline-form')
            .on('submit.businessLocationInline', '.js-business-location-inline-form', function (event) {
                event.preventDefault();

                const $form = $(this);
                const targetSelector = String($form.find('[name="target_select"]').val() || '');
                const parentField = String($form.find('[name="parent_field"]').val() || '');
                const parentValue = String($form.find('[name="parent_value"]').val() || '');
                const $button = $form.find('[type="submit"]').first();
                const payload = $form.serializeArray();

                if (parentField && parentValue) {
                    payload.push({ name: parentField, value: parentValue });
                }

                clearFormErrors($form);
                setLoading($button, true);

                $.ajax({
                    url: $form.attr('action'),
                    method: 'POST',
                    data: $.param(payload),
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
            });
    }

    function initDeleteActions() {
        $(document).off('click.businessDelete', '.js-delete-record[data-delete-url]').on('click.businessDelete', '.js-delete-record[data-delete-url]', function () {
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

                    if ($('.js-business-partner-table').length > 0) {
                        $(document).trigger('business:deleted', [docNum]);
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
        $(document).off('click.businessRestore', '.js-restore-record[data-restore-url]').on('click.businessRestore', '.js-restore-record[data-restore-url]', function () {
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

                    if ($('.js-business-partner-table').length > 0) {
                        $(document).trigger('business:restored', [docNum]);
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
    initInlineGroupCreate();
    initCreditLimits();
    initLocationInlineCreate();
    initDeleteActions();
    initRestoreActions();
    if (window.AppContactActions && typeof window.AppContactActions.init === 'function') {
        window.AppContactActions.init(document);
    }
})(jQuery, window, document);
