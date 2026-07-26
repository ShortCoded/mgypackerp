(function ($, window, document) {
    'use strict';

    const messages = window.fixedAssetsMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    let fixedAssetsTable = null;

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

    function columnName(column) {
        const map = {
            doc_num: 'fixed_assets.doc_number',
            asset_name: 'fixed_assets.asset_name',
            entry_type: 'fixed_assets.entry_type',
            asset_category: 'category_accounts.account_code',
            branch: 'branches.name',
            cost_center: 'cost_centers.cost_center_code',
            purchase_value: 'fixed_assets.purchase_value',
            currency: 'currencies.code',
            previous_depreciation: 'fixed_assets.previous_depreciation',
            net_value: 'fixed_assets.net_value',
            is_depreciable: 'fixed_assets.is_depreciable',
            status: 'fixed_assets.status',
            created_by: 'created_users.name',
            created_at: 'fixed_assets.created_at',
            updated_by: 'updated_users.name',
            updated_at: 'fixed_assets.updated_at',
            deleted_by: 'deleted_users.name',
            deleted_at: 'fixed_assets.deleted_at'
        };

        return map[column] || column;
    }

    function columnClass(column, index) {
        if (index === 0) {
            return 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control';
        }

        if (column === 'status') {
            return 'align-middle white-space-nowrap dt-status';
        }

        if (['purchase_value', 'previous_depreciation', 'net_value'].indexOf(column) !== -1) {
            return 'align-middle white-space-nowrap dt-number text-center';
        }

        if (['created_at', 'updated_at', 'deleted_at'].indexOf(column) !== -1) {
            return 'align-middle white-space-nowrap dt-date';
        }

        return 'align-middle white-space-nowrap dt-text dt-ellipsis';
    }

    function tableColumns() {
        const configuredColumns = window.fixedAssetsCrudColumns || [];
        const columns = [
            { data: 'checkbox', name: 'checkbox', orderable: false, searchable: false, className: 'dt-select no-colvis all align-middle text-center', responsivePriority: 1, width: '2.25rem' }
        ];

        configuredColumns.forEach(function (column, index) {
            columns.push({
                data: column,
                name: columnName(column),
                className: columnClass(column, index),
                responsivePriority: index < 2 ? 2 + index : 10 + index
            });
        });

        columns.push({ data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'align-middle white-space-nowrap all no-colvis dt-actions', responsivePriority: 3 });

        return columns;
    }

    function alertElement($form) {
        let $alert = $form.find('.js-form-alert').first();

        if ($alert.length === 0) {
            $alert = $('<div class="alert alert-danger d-none js-form-alert"><div class="js-form-alert-message"></div></div>');
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
            .find('.js-form-alert-message')
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

    function select2Selection($field) {
        return $field.next('.select2-container').find('.select2-selection').first();
    }

    function markFieldInvalid($field) {
        $field.addClass('is-invalid');

        if ($field.hasClass('select2-hidden-accessible')) {
            select2Selection($field).addClass('is-invalid');
        }
    }

    function clearFieldError($field) {
        const name = $field.attr('name');
        const $form = $field.closest('form');

        if (!name || $form.length === 0) {
            return;
        }

        $field.removeClass('is-invalid');
        select2Selection($field).removeClass('is-invalid');
        $form.find('[data-error-for="' + name + '"]').text('');
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
            .find('.js-form-alert-message')
            .empty()
            .append($list);

        Object.keys(errors || {}).forEach(function (field) {
            const normalizedField = field.replace(/\.\d+$/, '').replace(/\.\d+\./g, '.');
            const baseField = normalizedField.split('.')[0];
            const message = $.isArray(errors[field]) ? errors[field][0] : errors[field];
            const $input = $form.find('[name="' + normalizedField + '"], [name="' + baseField + '"]');

            $input.each(function () {
                markFieldInvalid($(this));
            });
            $form.find('[data-error-for="' + normalizedField + '"], [data-error-for="' + baseField + '"]').text(message || '');
        });
    }

    function showFormNotice($form, message, type) {
        alertElement($form)
            .removeClass('d-none alert-danger alert-warning alert-success alert-info')
            .addClass('alert-' + (type || 'danger'))
            .find('.js-form-alert-message')
            .text(message || msg('unexpectedError'));
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

    function trimNumber(value, precision) {
        const numeric = window.AppNumbers.number(value, NaN);

        if (!Number.isFinite(numeric)) {
            return '';
        }

        return numeric.toFixed(precision || 6).replace(/\.?0+$/, '');
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

            const dependsOn = $select.data('depends-on');
            const dependentParam = $select.data('dependent-param');
            const disableWhenDependencyEmpty = String($select.data('disable-when-dependency-empty')) === 'true';

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
                        const data = {
                            q: params.term || '',
                            page: params.page || 1
                        };

                        if (dependsOn && dependentParam) {
                            data[dependentParam] = $(dependsOn).val() || '';
                        }

                        return data;
                    }
                }
            });

            if (dependsOn) {
                const namespace = String($select.attr('id') || $select.attr('name') || Math.random()).replace(/[^A-Za-z0-9_]/g, '_');
                const syncDependency = function (shouldClear) {
                    const hasDependency = String($(dependsOn).val() || '').trim() !== '';

                    if (shouldClear) {
                        $select.val(null).trigger('change.select2');
                        clearFieldError($select);
                    }

                    if (disableWhenDependencyEmpty) {
                        $select.prop('disabled', !hasDependency).trigger('change.select2');
                    }
                };

                $(document).off('change.fixedAssetsSelect2Dependency.' + namespace, dependsOn)
                    .on('change.fixedAssetsSelect2Dependency.' + namespace, dependsOn, function () {
                        syncDependency(true);
                    });

                syncDependency(false);
            }
        });
    }

    function updateNetValue() {
        const purchase = window.AppNumbers.number($('#purchase_value').val(), NaN);
        const previous = window.AppNumbers.number($('#previous_depreciation').val(), NaN);

        if (!Number.isFinite(purchase)) {
            $('.js-fixed-asset-net-value').val('');
            return;
        }

        $('.js-fixed-asset-net-value').val(window.AppNumbers.format(trimNumber(purchase - (Number.isFinite(previous) ? previous : 0), 4)));
    }

    function formIsDepreciable() {
        return String($('#is_depreciable').val() || '0') === '1';
    }

    function selectedDepreciationMethod() {
        return String($('#depreciation_method').val() || '').trim();
    }

    function selectedCurrencyIsMain($form) {
        const mainCurrencyDocNum = String($form.data('main-currency-doc-num') || '');
        const $currency = $form.find('.js-fixed-asset-currency');
        const selected = $currency.select2 && $currency.data('select2') ? $currency.select2('data')[0] : null;

        return Boolean(
            (selected && selected.is_main)
            || ($currency.val() && String($currency.val()) === mainCurrencyDocNum)
            || $currency.find('option:selected').data('is-main') === 1
        );
    }

    function applyMainCurrencyExchangeRate($form) {
        const $exchangeRate = $form.find('.js-fixed-asset-exchange-rate');

        if ($exchangeRate.length === 0) {
            return;
        }

        if (selectedCurrencyIsMain($form)) {
            $exchangeRate.val('1').prop('readonly', true);
            return;
        }

        $exchangeRate.prop('readonly', false);
    }

    function toggleDepreciationFields() {
        const depreciable = formIsDepreciable();
        const $controls = $('.js-fixed-asset-depreciation-control');
        const $operationDate = $('.js-fixed-asset-operation-date');
        const $method = $('#depreciation_method');
        const defaultMethod = String($method.data('default-method') || 'straight_line');
        const method = depreciable ? (selectedDepreciationMethod() || defaultMethod) : '';
        const rateValue = String($('.js-fixed-asset-depreciation-rate').val() || '').trim();
        const usefulLifeRequired = depreciable && (
            method === 'double_declining_balance'
            || method === 'sum_of_years_digits'
            || (method === 'straight_line' && rateValue === '')
        );
        const annualRateRequired = depreciable && method === 'declining_balance';
        const usageUnitsRequired = depreciable && method === 'units_of_production';

        $('.js-depreciable-required-marker').toggleClass('d-none', !depreciable);
        $operationDate.prop('required', depreciable);
        $method.prop('required', depreciable);
        $('#salvage_value').prop('required', depreciable);

        if ($method.length) {
            if (!depreciable) {
                $method.val('');
            } else if ($method.val() === '') {
                $method.val(method);
            }
        }

        $controls.prop('disabled', !depreciable).closest('.col-md-4, .col-md-6').toggleClass('fixed-asset-disabled-field', !depreciable);
        $('.js-fixed-asset-method-field-container').each(function () {
            const $container = $(this);
            const methods = String($container.data('depreciation-methods') || '').split(',').map(function (value) {
                return $.trim(value);
            }).filter(Boolean);
            const applies = depreciable && methods.indexOf(method) !== -1;

            $container.toggleClass('fixed-asset-hidden-field', !applies);
            $container.find('.js-fixed-asset-method-control').prop('disabled', !applies);
        });
        $('.js-fixed-asset-useful-life').prop('required', usefulLifeRequired);
        $('.js-fixed-asset-depreciation-rate').prop('required', annualRateRequired);
        $('.js-fixed-asset-usage-units').prop('required', usageUnitsRequired);
        $('.js-fixed-asset-useful-life-required-marker').toggleClass('d-none', !usefulLifeRequired);
        $('.js-fixed-asset-annual-rate-required-marker').toggleClass('d-none', !annualRateRequired);
        $('.js-fixed-asset-usage-units-required-marker').toggleClass('d-none', !usageUnitsRequired);

        if (!depreciable) {
            $controls.each(function () {
                clearFieldError($(this));
            });
        }

        toggleOpeningAssetRequirement();
        togglePreviousDepreciationDateRequirement();
    }

    function toggleOpeningAssetRequirement() {
        const openingAsset = formIsDepreciable() && String($('#entry_type').val() || '') === 'opening_asset';
        const $previous = $('#previous_depreciation');

        $('.js-opening-asset-required-marker').toggleClass('d-none', !openingAsset);
        $previous.prop('required', openingAsset);
    }

    function togglePreviousDepreciationDateRequirement() {
        const previous = window.AppNumbers.number($('#previous_depreciation').val(), NaN);
        const required = formIsDepreciable() && Number.isFinite(previous) && previous > 0;
        const $untilDate = $('#previous_depreciation_until_date');

        $('.js-previous-depreciation-date-required-marker').toggleClass('d-none', !required);
        $untilDate.prop('required', required);
    }

    function syncDepreciationPair(changedField) {
        if (!formIsDepreciable() || selectedDepreciationMethod() !== 'straight_line') {
            return;
        }

        const $usefulLife = $('.js-fixed-asset-useful-life');
        const $rate = $('.js-fixed-asset-depreciation-rate');

        if ($usefulLife.length === 0 || $rate.length === 0) {
            return;
        }

        if (changedField === 'useful_life') {
            const usefulLife = window.AppNumbers.number($usefulLife.val(), NaN);

            if (Number.isFinite(usefulLife) && usefulLife > 0) {
                $rate.val(trimNumber(100 / usefulLife, 4));
                window.AppNumbers.refresh($rate[0]);
            }

            return;
        }

        const rate = window.AppNumbers.number($rate.val(), NaN);

        if (Number.isFinite(rate) && rate > 0) {
            $usefulLife.val(trimNumber(100 / rate, 2));
            window.AppNumbers.refresh($usefulLife[0]);
        }
    }

    function initTable() {
        const $table = $('.js-fixed-assets-table').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const $root = $('[data-fixed-assets-root]').first();
        const protectedColumns = [0, 1, -1];
        const selectedDocNums = new Set();
        const dataTableOptions = window.AppDataTables && typeof window.AppDataTables.options === 'function'
            ? window.AppDataTables.options
            : function (options) { return options; };
        const rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select';
        const selectAllSelector = '#select_all_records';
        const trashFilterSelector = '.js-fixed-assets-trash-filter';

        function checkboxDocNum(checkbox) {
            return String($(checkbox).data('doc-num') || checkbox.value || '').trim();
        }

        function pageCheckboxes(api) {
            return api && typeof api.rows === 'function'
                ? $(api.rows({ page: 'current' }).nodes()).find('input.js-record-select')
                : $table.find(rowCheckboxSelector);
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
            const $applyButton = $('#bulk_action_apply');
            const applyLabel = $applyButton.data('label') || '';

            $actions.toggleClass('d-none', selectedCount === 0).toggleClass('d-flex', selectedCount > 0);
            $('#bulk_selected_count').text(selectedCount);
            $applyButton.prop('disabled', selectedCount === 0).find('span:last').text(applyLabel + (selectedCount > 0 ? ' (' + selectedCount + ')' : ''));
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

        fixedAssetsTable = $table.DataTable(dataTableOptions({
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
            columns: tableColumns(),
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

        $(trashFilterSelector).off('change.fixedAssetsTrash').on('change.fixedAssetsTrash', function () {
            clearSelection(fixedAssetsTable);
            fixedAssetsTable.ajax.reload(null, false);
        });

        $(selectAllSelector).off('change.fixedAssetsSelectAll').on('change.fixedAssetsSelectAll', function () {
            const checked = $(this).is(':checked');

            pageCheckboxes(fixedAssetsTable).each(function () {
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

            updateSelectAllState(fixedAssetsTable);
            updateBulkActionsUi();
        });

        $table.off('change.fixedAssetsSelect', rowCheckboxSelector).on('change.fixedAssetsSelect', rowCheckboxSelector, function () {
            const docNum = checkboxDocNum(this);

            if (docNum === '') {
                return;
            }

            if ($(this).is(':checked')) {
                selectedDocNums.add(docNum);
            } else {
                selectedDocNums.delete(docNum);
            }

            updateSelectAllState(fixedAssetsTable);
            updateBulkActionsUi();
        });

        $table.off('dblclick.fixedAssetsEditRow', 'tbody tr:not(.child)').on('dblclick.fixedAssetsEditRow', 'tbody tr:not(.child)', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            const editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        });

        $('#bulk_action_apply').off('click.fixedAssetsBulk').on('click.fixedAssetsBulk', function () {
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
                    clearSelection(fixedAssetsTable);
                    fixedAssetsTable.ajax.reload(null, false);
                    showToast('success', response.message);
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'));
                });
            });
        });
    }

    function fixedAssetImagePreviewElements($field) {
        return {
            image: $field.find('.js-fixed-asset-image-preview-image').first(),
            placeholder: $field.find('.js-fixed-asset-image-placeholder').first(),
            fileName: $field.find('.js-fixed-asset-image-file-name').first(),
            removeButton: $field.find('.js-fixed-asset-image-remove').first()
        };
    }

    function clearSelectedAssetImage($form) {
        $form.find('[name="image_archive_file_doc_num"]').val('');
    }

    function setAssetImageRemoveFlag($form, removed) {
        $form.find('[name="remove_image"]').val(removed ? '1' : '0');
    }

    function existingAssetImageUrl($field) {
        return String($field.attr('data-existing-url') || $field.data('existing-url') || '').trim();
    }

    function clearAssetImagePreview($field) {
        const elements = fixedAssetImagePreviewElements($field);

        elements.image
            .off('load.fixedAssetsImagePreview error.fixedAssetsImagePreview')
            .attr('src', '')
            .addClass('d-none');
        elements.placeholder.removeClass('d-none');
        elements.fileName.text($field.data('no-image-label') || '');
        elements.removeButton.addClass('d-none');
    }

    function renderAssetImagePreview($field, options) {
        options = options || {};

        const file = options.file || {};
        const data = options.data || {};
        const src = String(options.src || file.thumbnail_url || data.thumbnail_url || file.url || data.url || '').trim();
        const fileName = String(options.fileName || file.name || data.name || file.original_name || data.original_name || '').trim();
        const hasImageValue = Object.prototype.hasOwnProperty.call(options, 'hasImageValue') ? Boolean(options.hasImageValue) : src !== '';
        const elements = fixedAssetImagePreviewElements($field);
        const image = elements.image.get(0);

        if (!src || !image) {
            clearAssetImagePreview($field);
            return;
        }

        elements.image
            .off('load.fixedAssetsImagePreview error.fixedAssetsImagePreview')
            .addClass('d-none')
            .one('load.fixedAssetsImagePreview', function () {
                $(this).removeClass('d-none');
                elements.placeholder.addClass('d-none');
            })
            .one('error.fixedAssetsImagePreview', function () {
                elements.image.attr('src', '').addClass('d-none');
                elements.placeholder.removeClass('d-none');
                elements.fileName.text(fileName || $field.data('no-image-label') || '');
                elements.removeButton.toggleClass('d-none', !hasImageValue);
            })
            .attr('alt', fileName || elements.image.attr('alt') || '')
            .attr('src', src);

        elements.placeholder.addClass('d-none');
        elements.fileName.text(fileName || $field.data('existing-label') || '');
        elements.removeButton.toggleClass('d-none', !hasImageValue);

        if (image.complete && image.naturalWidth > 0) {
            elements.image.triggerHandler('load.fixedAssetsImagePreview');
        } else if (image.complete && image.naturalWidth === 0) {
            elements.image.triggerHandler('error.fixedAssetsImagePreview');
        }
    }

    function resetAssetImagePreview($field) {
        const currentUrl = String($field.attr('data-current-url') || $field.data('current-url') || '').trim();

        renderAssetImagePreview($field, {
            src: currentUrl,
            fileName: currentUrl ? ($field.data('existing-label') || '') : ($field.data('no-image-label') || ''),
            hasImageValue: currentUrl !== ''
        });
    }

    function clearAssetImageSelection($button) {
        const $form = $button.closest('form');
        let $field = $button.closest('.js-fixed-asset-image-picker-field');

        if (!$field.length) {
            $field = $form.find('.js-fixed-asset-image-picker-field').first();
        }

        clearSelectedAssetImage($form);
        setAssetImageRemoveFlag($form, existingAssetImageUrl($field) !== '');
        clearAssetImagePreview($field);
    }

    function handleAssetImagePickerSelection(payload) {
        const data = payload || {};
        const file = data.file || data;
        const config = data.config || {};
        const $form = $('.js-fixed-asset-form').first();
        const $field = config.uploader ? $(config.uploader).first() : $form.find('.js-fixed-asset-image-picker-field').first();
        const $hidden = config.targetInput ? $(config.targetInput).first() : $form.find('[name="image_archive_file_doc_num"]').first();
        const publicId = file ? (file.public_id || data.public_id || '') : '';

        if (config.collection !== 'fixed_asset_image' || !file || publicId === '' || !$hidden.length || !$field.length) {
            return;
        }

        $hidden.val(publicId).trigger('change');
        renderAssetImagePreview($field, {
            file: file,
            data: data,
            hasImageValue: true
        });
        setAssetImageRemoveFlag($form, false);
        clearFieldError($hidden);
        $form.find('[data-error-for="image"]').text('');
    }

    function handleAssetImagePickerDeletion(payload) {
        const data = payload || {};
        const config = data.config || {};
        const $form = $('.js-fixed-asset-form').first();
        const $field = config.uploader ? $(config.uploader).first() : $form.find('.js-fixed-asset-image-picker-field').first();

        if (config.collection !== 'fixed_asset_image' || !data.was_selected || !$field.length) {
            return;
        }

        setAssetImageRemoveFlag($form, false);
        resetAssetImagePreview($field);
        $form.find('[data-error-for="image"]').text('');
    }

    function updateImagePickerFromResponse($form, response) {
        const data = response && response.data ? response.data : {};

        if (!Object.prototype.hasOwnProperty.call(data, 'image_url')) {
            return;
        }

        const $field = $form.find('.js-fixed-asset-image-picker-field').first();

        $field.attr('data-current-url', data.image_url || '').data('current-url', data.image_url || '');
        $field.attr('data-existing-url', data.image_url || '').data('existing-url', data.image_url || '');
        clearSelectedAssetImage($form);
        setAssetImageRemoveFlag($form, false);
        resetAssetImagePreview($field);
    }

    function submitAjaxForm($form) {
        clearFormErrors($form);

        $.ajax({
            url: $form.attr('action'),
            method: $form.attr('method') || 'POST',
            data: $form.serialize(),
            headers: headers()
        }).done(function (response) {
            if (response.redirect) {
                window.location.href = response.redirect;
                return;
            }

            showToast('success', response.message);
            updateImagePickerFromResponse($form, response);

            if (response.type === 'no_changes') {
                showFormNotice($form, response.message, 'warning');
            }
        }).fail(function (response) {
            const json = response.responseJSON || {};

            if (json.errors) {
                showValidationErrors($form, json.errors);
                return;
            }

            showFormNotice($form, json.message || msg('unexpectedError'), 'danger');
        });
    }

    function initForm() {
        const $form = $('.js-fixed-asset-form').first();

        if ($form.length === 0) {
            return;
        }

        $('.js-finance-submit-action').off('click.fixedAssetsSubmitAction').on('click.fixedAssetsSubmitAction', function () {
            $form.find('[name="submit_action"]').val($(this).data('submit-action') || 'save');
        });

        $form.off('submit.fixedAssets').on('submit.fixedAssets', function (event) {
            event.preventDefault();
            submitAjaxForm($form);
        });

        $form.off('input.fixedAssetsFieldError change.fixedAssetsFieldError', 'input, textarea, select')
            .on('input.fixedAssetsFieldError change.fixedAssetsFieldError', 'input, textarea, select', function () {
                clearFieldError($(this));
            });
        $form.off('select2:select.fixedAssetsFieldError select2:clear.fixedAssetsFieldError', '.js-select2-ajax')
            .on('select2:select.fixedAssetsFieldError select2:clear.fixedAssetsFieldError', '.js-select2-ajax', function () {
                clearFieldError($(this));
            });
        $form.off('file-picker:selected.fixedAssetsImagePicker', '.js-fixed-asset-image-picker-trigger').on('file-picker:selected.fixedAssetsImagePicker', '.js-fixed-asset-image-picker-trigger', function (event, payload) {
            handleAssetImagePickerSelection(payload);
        });
        $form.off('file-picker:deleted.fixedAssetsImagePicker', '.js-fixed-asset-image-picker-trigger').on('file-picker:deleted.fixedAssetsImagePicker', '.js-fixed-asset-image-picker-trigger', function (event, payload) {
            handleAssetImagePickerDeletion(payload);
        });
        $form.off('click.fixedAssetsRemoveImage', '.js-fixed-asset-image-remove').on('click.fixedAssetsRemoveImage', '.js-fixed-asset-image-remove', function (event) {
            event.preventDefault();
            event.stopPropagation();
            clearAssetImageSelection($(this));
        });
        $form.off('input.fixedAssetsNet change.fixedAssetsNet', '.js-fixed-asset-money').on('input.fixedAssetsNet change.fixedAssetsNet', '.js-fixed-asset-money', updateNetValue);
        $form.off('change.fixedAssetsDepreciableToggle', '.js-fixed-asset-is-depreciable').on('change.fixedAssetsDepreciableToggle', '.js-fixed-asset-is-depreciable', function () {
            toggleDepreciationFields();
        });
        $form.off('change.fixedAssetsDepreciationMethod', '.js-fixed-asset-depreciation-method').on('change.fixedAssetsDepreciationMethod', '.js-fixed-asset-depreciation-method', function () {
            toggleDepreciationFields();
        });
        $form.off('change.fixedAssetsEntryType', '.js-fixed-asset-entry-type').on('change.fixedAssetsEntryType', '.js-fixed-asset-entry-type', function () {
            toggleOpeningAssetRequirement();
        });
        $form.off('input.fixedAssetsPreviousDepreciation change.fixedAssetsPreviousDepreciation', '.js-fixed-asset-previous-depreciation').on('input.fixedAssetsPreviousDepreciation change.fixedAssetsPreviousDepreciation', '.js-fixed-asset-previous-depreciation', function () {
            togglePreviousDepreciationDateRequirement();
        });
        $form.off('change.fixedAssetsCurrency select2:select.fixedAssetsCurrency select2:clear.fixedAssetsCurrency', '.js-fixed-asset-currency').on('change.fixedAssetsCurrency select2:select.fixedAssetsCurrency select2:clear.fixedAssetsCurrency', '.js-fixed-asset-currency', function () {
            applyMainCurrencyExchangeRate($form);
        });
        $form.off('input.fixedAssetsDepreciation', '.js-fixed-asset-useful-life').on('input.fixedAssetsDepreciation', '.js-fixed-asset-useful-life', function () {
            syncDepreciationPair('useful_life');
            toggleDepreciationFields();
        });
        $form.off('input.fixedAssetsDepreciation', '.js-fixed-asset-depreciation-rate').on('input.fixedAssetsDepreciation', '.js-fixed-asset-depreciation-rate', function () {
            syncDepreciationPair('annual_depreciation_rate');
            toggleDepreciationFields();
        });
        updateNetValue();
        toggleDepreciationFields();
        toggleOpeningAssetRequirement();
        togglePreviousDepreciationDateRequirement();
        applyMainCurrencyExchangeRate($form);
        resetAssetImagePreview($form.find('.js-fixed-asset-image-picker-field').first());
    }

    function initInlineCategory() {
        $(document).off('click.fixedAssetsInlineOpen', '.js-fixed-asset-inline-create').on('click.fixedAssetsInlineOpen', '.js-fixed-asset-inline-create', function () {
            const $modal = $($(this).data('modal'));

            clearFormErrors($modal.find('form'));
            $modal.find('input[name="name"], textarea[name="notes"]').val('');
            $modal.modal('show');
            $modal.one('shown.bs.modal', function () {
                $modal.find('input[name="name"]').trigger('focus');
            });
        });

        $(document).off('submit.fixedAssetsInline', '.js-fixed-asset-inline-form').on('submit.fixedAssetsInline', '.js-fixed-asset-inline-form', function (event) {
            event.preventDefault();

            const $form = $(this);
            const $target = $($form.data('target-select'));

            clearFormErrors($form);

            $.ajax({
                url: $form.attr('action'),
                method: $form.attr('method') || 'POST',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                selectOption($target, response.data && response.data.option ? response.data.option : null);
                $form.closest('.modal').modal('hide');
                showToast('success', response.message);
            }).fail(function (response) {
                const json = response.responseJSON || {};

                if (json.errors) {
                    showValidationErrors($form, json.errors);
                    return;
                }

                showFormNotice($form, json.message || msg('unexpectedError'), 'danger');
            });
        });
    }

    function initDeleteRestore() {
        $(document).off('click.fixedAssetsDelete', '.js-delete-record').on('click.fixedAssetsDelete', '.js-delete-record', function () {
            const $button = $(this);

            confirmDialog({
                title: msg('bulkDeleteConfirmTitle'),
                text: '',
                confirmButtonText: msg('bulkDeleteConfirmYes')
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.ajax({
                    url: $button.data('delete-url'),
                    method: 'DELETE',
                    headers: headers()
                }).done(function (response) {
                    showToast('success', response.message);

                    if ($button.data('redirect-url')) {
                        window.location.href = $button.data('redirect-url');
                        return;
                    }

                    if (fixedAssetsTable) {
                        fixedAssetsTable.ajax.reload(null, false);
                    }
                }).fail(function (response) {
                    showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'));
                });
            });
        });

        $(document).off('click.fixedAssetsRestore', '.js-restore-record').on('click.fixedAssetsRestore', '.js-restore-record', function () {
            const $button = $(this);

            $.ajax({
                url: $button.data('restore-url'),
                method: 'PATCH',
                headers: headers()
            }).done(function (response) {
                showToast('success', response.message);

                if (fixedAssetsTable) {
                    fixedAssetsTable.ajax.reload(null, false);
                }
            }).fail(function (response) {
                showToast('error', response.responseJSON && response.responseJSON.message ? response.responseJSON.message : msg('unexpectedError'));
            });
        });
    }

    function initDocumentSettings() {
        $(document).off('submit.fixedAssetsDocumentSettings', '.js-fixed-assets-document-number-settings-form').on('submit.fixedAssetsDocumentSettings', '.js-fixed-assets-document-number-settings-form', function (event) {
            event.preventDefault();

            const $form = $(this);

            clearFormErrors($form);

            $.ajax({
                url: $form.attr('action'),
                method: 'PUT',
                data: $form.serialize(),
                headers: headers()
            }).done(function (response) {
                showToast('success', response.message);
            }).fail(function (response) {
                const json = response.responseJSON || {};

                if (json.errors) {
                    showValidationErrors($form, json.errors);
                    return;
                }

                showFormNotice($form, json.message || msg('unexpectedError'), 'danger');
            });
        });
    }

    $(function () {
        initSelect2();
        initTable();
        initForm();
        initInlineCategory();
        initDeleteRestore();
        initDocumentSettings();
    });
})(jQuery, window, document);
