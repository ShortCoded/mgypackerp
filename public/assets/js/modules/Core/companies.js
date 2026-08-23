(function ($, window) {
    'use strict';

    const messages = window.companiesMessages || {};
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

    const trackedFields = [
        'doc_number',
        'name',
        'legal_name',
        'commercial_name',
        'authorized_signatory_name',
        'authorized_signatory_title',
        'company_stamp_archive_file_doc_num',
        'authorized_signatory_signature_archive_file_doc_num',
        'status',
        'is_main',
        'notes',
        'commercial_register_number',
        'commercial_register_office',
        'commercial_register_date',
        'commercial_register_expiry_date',
        'tax_card_number',
        'tax_file_number',
        'tax_office',
        'vat_registration_number',
        'industrial_register_number',
        'import_card_number',
        'export_card_number',
        'phone',
        'mobile',
        'hotline',
        'fax',
        'email',
        'website',
        'country_doc_num',
        'governorate_doc_num',
        'city_doc_num',
        'area_doc_num',
        'address',
        'postal_code',
        'map_url',
        'industry',
        'activity_type',
        'business_description'
    ];

    function fieldValue($form, field) {
        if (field === 'is_main') {
            const $checkbox = $form.find('[name="is_main"][type="checkbox"]');

            return $checkbox.length > 0 ? ($checkbox.is(':checked') ? '1' : '0') : '';
        }

        return String($form.find('[name="' + field + '"]').val() || '').trim();
    }

    function currentFormData($form) {
        const data = {};

        trackedFields.forEach(function (field) {
            data[field] = fieldValue($form, field);
        });

        data.logo_changed = $form.find('[name="logo"]').get(0) && $form.find('[name="logo"]').get(0).files.length > 0 ? '1' : '0';
        data.remove_logo = $form.find('[name="remove_logo"]').is(':checked') ? '1' : '0';
        data.favicon_changed = $form.find('[name="favicon"]').get(0) && $form.find('[name="favicon"]').get(0).files.length > 0 ? '1' : '0';
        data.remove_favicon = $form.find('[name="remove_favicon"]').is(':checked') ? '1' : '0';

        return data;
    }

    function originalFormData($form) {
        const original = $form.data('original') || {};
        const data = {};

        trackedFields.forEach(function (field) {
            data[field] = original[field] === null || original[field] === undefined ? '' : String(original[field]).trim();
        });

        data.logo_changed = '0';
        data.remove_logo = '0';
        data.favicon_changed = '0';
        data.remove_favicon = '0';

        return data;
    }

    function hasChanges($form) {
        if ($form.data('mode') !== 'edit') {
            return true;
        }

        const original = originalFormData($form);
        const current = currentFormData($form);

        return trackedFields.some(function (field) { return original[field] !== current[field]; })
            || current.logo_changed === '1'
            || current.remove_logo === '1'
            || current.favicon_changed === '1'
            || current.remove_favicon === '1';
    }

    function alertElement($form) {
        let $alert = $form.find('.js-company-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger alert-dismissible fade show d-none js-company-alert" role="alert"><span class="js-company-alert-message"></span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' + (messages.close || '') + '"></button></div>');
            $form.find('.js-company-form-body, .card-body').first().prepend($alert);
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
            .find('.js-company-alert-message')
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
            .find('.js-company-alert-message')
            .text(message || messages.unexpectedError);
    }

    function clearFormErrors($form) {
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        alertElement($form)
            .addClass('d-none')
            .removeClass('alert-warning alert-success alert-info')
            .addClass('alert-danger')
            .find('.js-company-alert-message')
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

    function resetCompanyCreateForm($form) {
        trackedFields.forEach(function (field) {
            if (field === 'status') {
                $form.find('[name="status"]').val('active');
                return;
            }

            if (field === 'is_main') {
                $form.find('[name="is_main"][type="checkbox"]').prop('checked', false);
                return;
            }

            $form.find('[name="' + field + '"]').val('');
        });

        $form.find('.js-company-location-select').val(null).trigger('change');
        if (window.AppArchiveImagePicker && typeof window.AppArchiveImagePicker.reset === 'function') {
            window.AppArchiveImagePicker.reset($form);
        }
        $form.find('[name="logo"], [name="remove_logo"], [name="favicon"], [name="remove_favicon"]').val('').prop('checked', false);
        $form.find('.js-company-logo-uploader').attr('data-current-url', '').data('current-url', '');
        resetLogoUploader($form.find('.js-company-logo-uploader'));
        $form.find('[name="submit_action"]').val('save');
        $form.find('[name="clone_source_token"]').remove();
        updateOriginalFormData($form);
    }

    function resetLogoUploader($uploaders) {
        $uploaders.each(function () {
            const $uploader = $(this);
            const currentUrl = String($uploader.attr('data-current-url') || '');
            const hasCurrentLogo = currentUrl !== '';

            $uploader.find('.js-company-logo-preview-image')
                .attr('src', hasCurrentLogo ? currentUrl : '')
                .toggleClass('d-none', !hasCurrentLogo);
            $uploader.find('.js-company-logo-placeholder').toggleClass('d-none', hasCurrentLogo);
            $uploader.find('.js-company-logo-file-name').text(
                hasCurrentLogo
                    ? String($uploader.data('existing-label') || '')
                    : String($uploader.data('no-file-label') || '')
            );
            $uploader.find('.js-company-logo-clear').addClass('d-none');
            $uploader.find('[data-logo-error]').text('');
        });
    }

    function renderLogoPreview($uploader, src, fileName) {
        $uploader.find('.js-company-logo-preview-image').attr('src', src).removeClass('d-none');
        $uploader.find('.js-company-logo-placeholder').addClass('d-none');
        $uploader.find('.js-company-logo-file-name').text(fileName || '');
        $uploader.find('.js-company-logo-clear').removeClass('d-none');
    }

    function logoAcceptedExtensions($uploader) {
        return String($uploader.data('accepted-files') || '')
            .split(',')
            .map(function (extension) { return extension.replace(/^\./, '').toLowerCase().trim(); })
            .filter(function (extension) { return extension !== ''; });
    }

    function logoHasAcceptedExtension($uploader, file) {
        const extensions = logoAcceptedExtensions($uploader);

        if (extensions.length === 0 || !file || !file.name) {
            return true;
        }

        const extension = String(file.name).split('.').pop().toLowerCase();

        return extensions.indexOf(extension) !== -1;
    }

    function logoIsWithinMaxSize($uploader, file) {
        const maxMiB = Number($uploader.data('max-file-size') || 0);

        if (!maxMiB || !file || !file.size) {
            return true;
        }

        return file.size <= maxMiB * 1024 * 1024;
    }

    function showLogoError($uploader, message) {
        $uploader.find('[data-logo-error]').text(message || $uploader.data('invalid-file-type') || messages.invalidLogoType || '');
    }

    function setLogoInputFile(input, file) {
        if (!file) {
            return false;
        }

        try {
            const transfer = new DataTransfer();
            transfer.items.add(file);
            input.files = transfer.files;
            $(input).trigger('change');

            return true;
        } catch (error) {
            return false;
        }
    }

    function updateLogoCurrentUrl($form, response) {
        const data = response && response.data ? response.data : {};

        if (Object.prototype.hasOwnProperty.call(data, 'logo_url')) {
            updateUploaderCurrentUrl($form, 'logo', data.logo_url || '');
        }

        if (Object.prototype.hasOwnProperty.call(data, 'favicon_url')) {
            updateUploaderCurrentUrl($form, 'favicon', data.favicon_url || '');
        }
    }

    function updateUploaderCurrentUrl($form, inputName, url) {
        $form.find('.js-company-logo-uploader[data-input-name="' + inputName + '"]')
            .attr('data-current-url', url)
            .data('current-url', url);
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

    function initCompaniesTable() {
        const $table = $('#companies-table');

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) { return options; };
        const protectedColumns = [0, 1, -1];
        const responsiveControlTarget = 1;
        const selectedDocNums = new Set();
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-company-row-checkbox';
        const selectAllSelector = '#select_all_records, #companies-select-all';
        const trashFilterSelector = '#companies_trash_filter';

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            return api && typeof api.rows === 'function'
                ? $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select, input.js-company-row-checkbox')
                : $table.find(rowCheckboxSelector);
        }

        function trashFilterValue() {
            const value = String($(trashFilterSelector).val() || 'active');

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
                }, { page: 'current' }).nodes().to$().filter('.dtr-control').removeClass('dtr-control').removeAttr('tabindex').removeData('dtr-keyboard');
                dt.cells(null, responsiveControlTarget, { page: 'current' }).nodes().to$().addClass('dtr-control');
                this._tabIndexes();
            };
        }

        function syncResponsiveControlColumn(api) {
            patchResponsiveControlTarget(api);

            const $rows = api && typeof api.rows === 'function' ? $(api.rows({ page: 'current' }).nodes()) : $table.find('tbody tr:not(.child)');
            $table.find('thead th').eq(0).removeClass('dtr-control');
            $table.find('thead th').eq(responsiveControlTarget).addClass('dtr-control');
            $rows.each(function () {
                const $cells = $(this).children('td, th');

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
            const selectableDocNums = pageCheckboxes(api).map(function () {
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

            [0, 1, 14].forEach(function (index) {
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

            [0, 1, 15].forEach(function (index) {
                api.column(index).visible(true, false);
            });
            api.columns.adjust();
        }

        const companiesTable = $table.DataTable(dataTableOptions({
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
            responsive: { details: { type: 'inline', target: responsiveControlTarget } },
            order: [[1, 'desc']],
            columns: [
                { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' },
                { data: 'doc_num', name: 'companies.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'name', name: 'name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'legal_name', name: 'legal_name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'commercial_name', name: 'commercial_name', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'status', name: 'status', className: 'align-middle white-space-nowrap' },
                { data: 'is_main', name: 'is_main', className: 'align-middle white-space-nowrap' },
                { data: 'phone', name: 'phone', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'email', name: 'email', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
                { data: 'city', name: 'city', className: 'align-middle white-space-nowrap dt-text dt-ellipsis' },
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
                { responsivePriority: 10, targets: [2, 3, 4] },
                { responsivePriority: 20, targets: [5, 6, 7, 8, 9] },
                { responsivePriority: 30, targets: [10, 11, 12, 13] }
            ],
            createdRow: function (row) { $(row).addClass('btn-reveal-trigger'); },
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

        patchResponsiveControlTarget(companiesTable);
        queueResponsiveControlSync(companiesTable);

        companiesTable.off('draw.dt.companiesResponsive column-visibility.dt.companiesResponsive column-sizing.dt.companiesResponsive responsive-resize.dt.companiesResponsive')
            .on('draw.dt.companiesResponsive column-visibility.dt.companiesResponsive column-sizing.dt.companiesResponsive responsive-resize.dt.companiesResponsive', function () {
                queueResponsiveControlSync(companiesTable);
            });

        function reloadTable() {
            companiesTable.ajax.reload(null, false);
        }

        $(trashFilterSelector).off('change.companiesTrashFilter').on('change.companiesTrashFilter', function () {
            clearSelection(companiesTable);
            reloadTable();
        });

        $(selectAllSelector).off('change.companiesSelect').on('change.companiesSelect', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(companiesTable).each(function () {
                const docNum = checkboxDocNum(this);
                if (docNum === '') { return; }
                if (checked) { selectedDocNums.add(docNum); } else { selectedDocNums.delete(docNum); }
                $(this).prop('checked', checked);
            });

            updateSelectAllState(companiesTable);
            updateBulkActionsUi();
        });

        $table.off('click.companiesSelectCell', 'tbody tr:not(.child) td.dt-select').on('click.companiesSelectCell', 'tbody tr:not(.child) td.dt-select', function (event) {
            if ($(event.target).closest('input, label, button, a').length > 0) {
                return;
            }

            const $checkbox = $(this).find('input.js-record-select, input.js-company-row-checkbox').first();
            event.preventDefault();
            event.stopPropagation();

            if ($checkbox.length === 0 || $checkbox.prop('disabled')) {
                return;
            }

            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        });

        $table.off('dblclick.companiesEditRow', 'tbody tr:not(.child)').on('dblclick.companiesEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);
            if (editLink) { editLink.click(); }
        });

        $(document).off('click.companiesSelectStop mousedown.companiesSelectStop mouseup.companiesSelectStop', '.js-record-select, #select_all_records, td.dt-select')
            .on('click.companiesSelectStop mousedown.companiesSelectStop mouseup.companiesSelectStop', '.js-record-select, #select_all_records, td.dt-select', function (event) {
                event.stopPropagation();
            });

        $table.off('change.companiesSelect', rowCheckboxSelector).on('change.companiesSelect', rowCheckboxSelector, function () {
            const docNum = checkboxDocNum(this);
            if (docNum === '') { return; }
            if ($(this).is(':checked')) { selectedDocNums.add(docNum); } else { selectedDocNums.delete(docNum); }
            updateSelectAllState(companiesTable);
            updateBulkActionsUi();
        });

        $(document).off('companies:deleted.companiesTable companies:restored.companiesTable').on('companies:deleted.companiesTable companies:restored.companiesTable', function (event, docNum) {
            if (docNum) {
                selectedDocNums.delete(docNum);
            }

            reloadTable();
            updateSelectAllState(companiesTable);
            updateBulkActionsUi();
        });

        $('#bulk_action_apply').off('click.companiesBulk').on('click.companiesBulk', function () {
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
                if (!result.isConfirmed) { return; }

                $.ajax({
                    url: $table.data('bulk-delete-url'),
                    method: 'DELETE',
                    data: { doc_nums: docNums },
                    headers: headers()
                }).done(function (response) {
                    clearSelection(companiesTable);
                    reloadTable();
                    showToast('success', response.message);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                });
            });
        });
    }

    function companiesTableApi() {
        const $table = $('#companies-table');

        if ($table.length === 0 || !$.fn.DataTable || !$.fn.DataTable.isDataTable($table[0])) {
            return null;
        }

        return $table.DataTable();
    }

    function initCompanyRecordActions() {
        $(document).off('click.companiesDelete', '[data-company-delete-url]').on('click.companiesDelete', '[data-company-delete-url]', function () {
            const $button = $(this);
            const url = $button.data('company-delete-url');
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

                $.ajax({ url: url, method: 'DELETE', headers: headers() }).done(function (response) {
                    showToast('success', response.message);

                    if (companiesTableApi()) {
                        $(document).trigger('companies:deleted', [docNum]);
                        return;
                    }

                    window.location.href = $button.data('redirect-url') || $('[data-shortcut-action="form.back"]').attr('href') || '/admin/companies';
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError);
                }).always(function () {
                    setLoading($button, false);
                });
            });
        });

        $(document).off('click.companiesRestore', '[data-company-restore-url], .js-restore-record[data-restore-url]').on('click.companiesRestore', '[data-company-restore-url], .js-restore-record[data-restore-url]', function () {
            const $button = $(this);
            const url = $button.data('company-restore-url') || $button.data('restore-url');
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

                $.ajax({ url: url, method: 'PATCH', headers: headers() }).done(function (response) {
                    showToast('success', response.message);

                    if (companiesTableApi()) {
                        $(document).trigger('companies:restored', [docNum]);
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

    function initCompanyForm() {
        $(document).off('click.companiesSubmitAction', '.js-company-submit-action').on('click.companiesSubmitAction', '.js-company-submit-action', function () {
            const $button = $(this);
            $button.closest('form').find('[name="submit_action"]').val(String($button.data('submit-action') || 'save'));
            $button.closest('form').data('submit-button', $button);
        });

        $(document).off('submit.companiesForm', '.js-company-form').on('submit.companiesForm', '.js-company-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const formElement = $form.get(0);
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
                data: new FormData(formElement),
                processData: false,
                contentType: false,
                headers: headers()
            }).done(function (response) {
                if (response && response.success === false && response.type === 'no_changes') {
                    showFormNotice($form, response.message || messages.noChanges, 'warning');
                    showToast('info', response.message || messages.noChanges);
                    return;
                }

                updateUrlsAfterDocNumberChange($form, response);
                updateLogoCurrentUrl($form, response);
                $form.find('[name="logo"], [name="favicon"]').val('');
                $form.find('[name="remove_logo"], [name="remove_favicon"]').prop('checked', false);
                resetLogoUploader($form.find('.js-company-logo-uploader'));
                updateOriginalFormData($form);
                showToast('success', response.message);

                if (response && response.reset_form) {
                    resetCompanyCreateForm($form);
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

        $(document).off('input.companiesForm change.companiesForm', '.js-company-form .is-invalid').on('input.companiesForm change.companiesForm', '.js-company-form .is-invalid', function () {
            const $input = $(this);
            const field = ($input.attr('name') || '').replace('[]', '');

            $input.removeClass('is-invalid');
            $input.closest('form').find('[data-error-for="' + field + '"]').text('');
        });
    }

    function initDocumentNumberSettings() {
        $(document).off('submit.companiesDocSettings', '.js-company-document-number-settings-form').on('submit.companiesDocSettings', '.js-company-document-number-settings-form', function (event) {
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

    function initCompanyLogoUpload() {
        $(document)
            .off('click.companiesLogoUploader keydown.companiesLogoUploader', '.js-company-logo-uploader')
            .on('click.companiesLogoUploader keydown.companiesLogoUploader', '.js-company-logo-uploader', function (event) {
                if ($(event.target).closest('input, button, a, .js-company-logo-clear').length > 0) {
                    return;
                }

                if ($(this).find('.js-company-logo-input').prop('disabled')) {
                    return;
                }

                if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) {
                    return;
                }

                event.preventDefault();
                $('#' + $(this).data('input-id')).trigger('click');
            });

        $(document)
            .off('dragenter.companiesLogoUploader dragover.companiesLogoUploader dragleave.companiesLogoUploader drop.companiesLogoUploader', '.js-company-logo-uploader')
            .on('dragenter.companiesLogoUploader dragover.companiesLogoUploader', '.js-company-logo-uploader', function (event) {
                if ($(this).find('.js-company-logo-input').prop('disabled')) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                $(this).addClass('border-primary');
            })
            .on('dragleave.companiesLogoUploader', '.js-company-logo-uploader', function (event) {
                event.preventDefault();
                event.stopPropagation();
                $(this).removeClass('border-primary');
            })
            .on('drop.companiesLogoUploader', '.js-company-logo-uploader', function (event) {
                const dropEvent = event.originalEvent;
                const file = dropEvent && dropEvent.dataTransfer && dropEvent.dataTransfer.files.length > 0
                    ? dropEvent.dataTransfer.files[0]
                    : null;
                const $uploader = $(this);
                const input = $('#' + $uploader.data('input-id')).get(0);

                if ($uploader.find('.js-company-logo-input').prop('disabled')) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                $uploader.removeClass('border-primary');

                if (!input || !setLogoInputFile(input, file)) {
                    showLogoError($uploader, messages.unexpectedError);
                }
            });

        $(document)
            .off('click.companiesLogoClear', '.js-company-logo-clear')
            .on('click.companiesLogoClear', '.js-company-logo-clear', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const $uploader = $(this).closest('.js-company-logo-uploader');
                const $input = $('#' + $uploader.data('input-id'));

                $input.val('');
                resetLogoUploader($uploader);
            });

        $(document)
            .off('change.companiesLogoInput', '.js-company-logo-input')
            .on('change.companiesLogoInput', '.js-company-logo-input', function () {
                const input = this;
                const file = input.files && input.files[0] ? input.files[0] : null;
                const $uploader = $(input).closest('.js-company-logo-uploader');

                $uploader.find('[data-logo-error]').text('');

                if (!file) {
                    resetLogoUploader($uploader);
                    return;
                }

                if ((file.type && !file.type.match(/^image\//)) || !logoHasAcceptedExtension($uploader, file)) {
                    input.value = '';
                    resetLogoUploader($uploader);
                    showLogoError($uploader, $uploader.data('invalid-file-type') || messages.invalidLogoType);
                    return;
                }

                if (!logoIsWithinMaxSize($uploader, file)) {
                    input.value = '';
                    resetLogoUploader($uploader);
                    showLogoError($uploader, $uploader.data('file-too-large') || messages.logoTooLarge);
                    return;
                }

                const reader = new FileReader();
                reader.onload = function (event) {
                    renderLogoPreview($uploader, event.target.result, file.name);
                };
                reader.readAsDataURL(file);
            });
    }

    initCompaniesTable();
    initCompanyRecordActions();
    initCompanyForm();
    initDocumentNumberSettings();
    initCompanyLogoUpload();
    if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
        window.AppSelect2Ajax.init(document);
    }
    if (window.AppDatePicker && typeof window.AppDatePicker.init === 'function') {
        window.AppDatePicker.init(document);
    }
    if (window.AppContactActions && typeof window.AppContactActions.init === 'function') {
        window.AppContactActions.init(document);
    }
})(jQuery, window);
