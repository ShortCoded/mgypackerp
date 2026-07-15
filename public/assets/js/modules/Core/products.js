(function ($, window) {
    'use strict';

    var messages = window.coreProductsMessages || {};
    var tableSelector = '#products-table';
    var formSelector = '#product-form';
    var protectedColumns = [0, 1, -1];
    var selected = new Set();
    var trashFilterSelector = '#products_trash_filter';
    var responsiveControlTarget = 1;
    var rowCheckboxSelector = 'tbody tr:not(.child) input.js-record-select, tbody tr:not(.child) input.js-record-checkbox';
    var selectAllSelector = '#select_all_records';
    var imagePreviewModalSelector = '#product-image-preview-modal';
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
        var componentErrors = {};

        $.each(errors || {}, function (field, fieldMessages) {
            var normalizedField = String(field || '').replace('[]', '');
            var $field = $form.find('[name="' + normalizedField + '"], [name="' + normalizedField + '[]"]');

            if (normalizedField.indexOf('components.') === 0) {
                componentErrors[normalizedField] = fieldMessages;
            }

            $field.addClass('is-invalid');
            if ($field.hasClass('select2-hidden-accessible')) {
                $field.next('.select2-container').find('.select2-selection').addClass('is-invalid');
            }
            $form.find('[data-error-for="' + normalizedField + '"]').text((fieldMessages || [])[0] || '');
        });

        if (Object.keys(componentErrors).length > 0) {
            renderComponentValidation(componentPanel(), componentErrors);
        }
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
        $field.next('.select2-container').find('.select2-selection').removeClass('is-invalid');
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
                { data: 'doc_num', name: 'products.doc_number', className: 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', responsivePriority: 2 },
                { data: 'image', name: 'image', orderable: false, searchable: false, className: 'align-middle text-center white-space-nowrap', responsivePriority: 12, width: '3.25rem' },
                { data: 'name', name: 'products.name', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 10 },
                { data: 'barcode', name: 'products.barcode', className: 'dt-code align-middle white-space-nowrap', responsivePriority: 25 },
                { data: 'item_classification', name: 'products.item_classification', className: 'align-middle white-space-nowrap', responsivePriority: 25 },
                { data: 'reorder_point', name: 'products.reorder_point', className: 'align-middle white-space-nowrap text-end', responsivePriority: 30 },
                { data: 'unit', name: 'unit_name', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 20 },
                { data: 'category', name: 'category_name', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 30 },
                { data: 'group', name: 'group_name', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 30 },
                { data: 'color', name: 'color_name', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 20 },
                { data: 'decal', name: 'decal_name', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 35 },
                { data: 'origin_country', name: 'origin_country_name', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 35 },
                { data: 'cost_as_inventory', name: 'products.cost_as_inventory', className: 'align-middle white-space-nowrap text-center', responsivePriority: 40 },
                { data: 'is_displayable', name: 'products.is_displayable', className: 'align-middle white-space-nowrap text-center', responsivePriority: 40 },
                { data: 'status', name: 'products.status', className: 'align-middle white-space-nowrap', responsivePriority: 20 },
                { data: 'created_by', name: 'created_by', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 50 },
                { data: 'created_at', name: 'products.created_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 50 },
                { data: 'updated_by', name: 'updated_by', className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 50 },
                { data: 'updated_at', name: 'products.updated_at', className: 'dt-date align-middle white-space-nowrap', responsivePriority: 50 },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'dt-actions no-colvis all align-middle white-space-nowrap', responsivePriority: 3 }
            ],
            columnDefs: [
                { className: 'dt-select no-colvis all', orderable: false, responsivePriority: 1, searchable: false, targets: 0 },
                { className: 'dt-code no-colvis all dtr-control', responsivePriority: 2, targets: 1 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 3, searchable: false, targets: -1 },
                { orderable: false, responsivePriority: 12, searchable: false, targets: 2 },
                { responsivePriority: 10, targets: [3] },
                { responsivePriority: 20, targets: [7, 10, 15] },
                { responsivePriority: 25, targets: [4, 5] },
                { responsivePriority: 30, targets: [6, 8, 9] },
                { responsivePriority: 35, targets: [11, 12] },
                { responsivePriority: 40, targets: [13, 14] },
                { responsivePriority: 50, targets: [16, 17, 18, 19] }
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

        dataTable.off('draw.dt.coreProductsResponsive column-visibility.dt.coreProductsResponsive column-sizing.dt.coreProductsResponsive responsive-resize.dt.coreProductsResponsive')
            .on('draw.dt.coreProductsResponsive column-visibility.dt.coreProductsResponsive column-sizing.dt.coreProductsResponsive responsive-resize.dt.coreProductsResponsive', function () {
                queueResponsiveControlSync(dataTable);
            });

        dataTable.on('draw.dt.coreProducts column-visibility.dt.coreProducts responsive-resize.dt.coreProducts', function () {
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

    function initSelect2(root) {
        if (window.AppSelect2Ajax && typeof window.AppSelect2Ajax.init === 'function') {
            window.AppSelect2Ajax.init(root || document);
        }
    }

    function select2DropdownParent($select) {
        var $modal = $select.closest('.modal');

        return $modal.length > 0 ? $modal : $(document.body);
    }

    function initComponentUnitSelect(root) {
        var $scope = root ? $(root) : $(document);
        var $selects = $scope.is('.js-product-component-unit')
            ? $scope
            : $scope.find('.js-product-component-unit');

        if (!$.fn.select2) {
            return;
        }

        $selects.each(function () {
            var $select = $(this);

            if (!$select.is('select') || $select.data('componentUnitSelect2Initialized')) {
                return;
            }

            $select.select2({
                allowClear: true,
                dir: document.documentElement.getAttribute('dir') || 'ltr',
                dropdownParent: select2DropdownParent($select),
                minimumResultsForSearch: 8,
                placeholder: componentUnitPlaceholder($select),
                theme: 'bootstrap-5',
                width: '100%'
            });

            $select.data('componentUnitSelect2Initialized', true);
        });
    }

    function clearSelectedArchiveImage($form) {
        $form.find('[name="image_archive_file_doc_num"]').val('');
    }

    function setProductImageRemoveFlag($form, removed) {
        $form.find('[name="remove_image"]').val(removed ? '1' : '0');
    }

    function existingProductImageUrl($field) {
        return String($field.attr('data-existing-url') || $field.data('existing-url') || '').trim();
    }

    function productImagePreviewUrl(file, data) {
        file = file || {};
        data = data || {};

        return String(file.thumbnail_url || data.thumbnail_url || file.url || data.url || '').trim();
    }

    function productImageFileName(file, data, fallback) {
        file = file || {};
        data = data || {};

        return String(file.name || data.name || file.original_name || data.original_name || fallback || '').trim();
    }

    function productImagePreviewElements($field) {
        return {
            image: $field.find('.js-product-image-preview-image').first(),
            placeholder: $field.find('.js-product-image-placeholder').first(),
            fileName: $field.find('.js-product-image-file-name').first(),
            removeButton: $field.find('.js-product-image-remove').first()
        };
    }

    function normalizeProductImageElement($image) {
        $image
            .addClass('h-100 w-100 object-fit-contain')
            .removeAttr('width height')
            .css({
                display: 'block',
                width: '100%',
                height: '100%',
                maxWidth: '100%',
                maxHeight: '100%',
                objectFit: 'contain'
            });
    }

    function handleProductImagePreviewError($field, fileName, hasImageValue) {
        var elements = productImagePreviewElements($field);

        elements.image
            .off('load.coreProductsImagePreview error.coreProductsImagePreview')
            .attr('src', '')
            .addClass('d-none');
        elements.placeholder.removeClass('d-none');
        elements.fileName.text(fileName || $field.data('no-image-label') || '');
        elements.removeButton.toggleClass('d-none', !hasImageValue);
    }

    function clearProductImagePreview($field) {
        var elements = productImagePreviewElements($field);

        elements.image
            .off('load.coreProductsImagePreview error.coreProductsImagePreview')
            .attr('src', '')
            .addClass('d-none');
        elements.placeholder.removeClass('d-none');
        elements.fileName.text($field.data('no-image-label') || '');
        elements.removeButton.addClass('d-none');
    }

    function renderProductImagePreview($field, options) {
        options = options || {};

        var src = String(options.src || productImagePreviewUrl(options.file, options.data)).trim();
        var fileName = productImageFileName(options.file, options.data, options.fileName);
        var hasImageValue = Object.prototype.hasOwnProperty.call(options, 'hasImageValue') ? Boolean(options.hasImageValue) : src !== '';
        var elements = productImagePreviewElements($field);
        var image = elements.image.get(0);

        if (!src || !image) {
            clearProductImagePreview($field);
            return;
        }

        normalizeProductImageElement(elements.image);
        elements.image
            .off('load.coreProductsImagePreview error.coreProductsImagePreview')
            .addClass('d-none')
            .one('load.coreProductsImagePreview', function () {
                $(this).removeClass('d-none');
                elements.placeholder.addClass('d-none');
            })
            .one('error.coreProductsImagePreview', function () {
                handleProductImagePreviewError($field, fileName, hasImageValue);
            })
            .attr('alt', fileName || elements.image.attr('alt') || '')
            .attr('src', src);

        elements.placeholder.addClass('d-none');
        elements.fileName.text(fileName || $field.data('existing-label') || '');
        elements.removeButton.toggleClass('d-none', !hasImageValue);

        if (image.complete && image.naturalWidth > 0) {
            elements.image.triggerHandler('load.coreProductsImagePreview');
        } else if (image.complete && image.naturalWidth === 0) {
            elements.image.triggerHandler('error.coreProductsImagePreview');
        }
    }

    function resetImagePreview($field) {
        var currentUrl = String($field.attr('data-current-url') || $field.data('current-url') || '');
        var fileName = currentUrl ? ($field.data('existing-label') || '') : ($field.data('no-image-label') || '');

        renderProductImagePreview($field, {
            src: currentUrl,
            fileName: fileName,
            hasImageValue: currentUrl !== ''
        });
    }

    function clearProductImageSelection($button) {
        var $form = $button.closest('form');
        var $field = $button.closest('.js-product-image-picker-field');

        if (!$field.length) {
            $field = $form.find('.js-product-image-picker-field').first();
        }

        clearSelectedArchiveImage($form);
        setProductImageRemoveFlag($form, existingProductImageUrl($field) !== '');
        clearProductImagePreview($field);
    }

    function showProductImagePreviewFallback($modal) {
        $modal.find('.js-product-image-preview-image')
            .off('error.coreProductsImagePreview')
            .attr('src', '')
            .attr('alt', '')
            .addClass('d-none');
        $modal.find('.js-product-image-preview-fallback').removeClass('d-none');
    }

    function resetProductImagePreviewModal($modal) {
        var fallbackTitle = message('imagePreviewTitle') || '';

        $modal.find('.js-product-image-preview-image')
            .off('error.coreProductsImagePreview')
            .attr('src', '')
            .attr('alt', '')
            .addClass('d-none');
        $modal.find('.js-product-image-preview-fallback').addClass('d-none');
        $modal.find('#product-image-preview-modal-title').text(fallbackTitle);
    }

    function showProductImagePreview($trigger) {
        var imageUrl = String($trigger.data('product-image-url') || '').trim();
        var productName = String($trigger.data('product-name') || '').trim();
        var $modal = $(imagePreviewModalSelector);
        var $image = $modal.find('.js-product-image-preview-image');
        var title = productName || message('imagePreviewTitle') || '';

        if (!$modal.length || imageUrl === '') {
            return;
        }

        $modal.find('#product-image-preview-modal-title').text(title);
        $modal.find('.js-product-image-preview-fallback').addClass('d-none');
        $image
            .off('error.coreProductsImagePreview')
            .one('error.coreProductsImagePreview', function () {
                showProductImagePreviewFallback($modal);
            })
            .attr('alt', productName || message('imagePreviewTitle') || '')
            .attr('src', imageUrl)
            .removeClass('d-none');

        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance($modal[0]).show();
            return;
        }

        $modal.modal('show');
    }

    function updateImagePickerFromResponse($form, response) {
        var data = response && response.data ? response.data : {};

        if (!Object.prototype.hasOwnProperty.call(data, 'image_url')) {
            return;
        }

        var $field = $form.find('.js-product-image-picker-field').first();

        $field.attr('data-current-url', data.image_url || '').data('current-url', data.image_url || '');
        $field.attr('data-existing-url', data.image_url || '').data('existing-url', data.image_url || '');
        clearSelectedArchiveImage($form);
        setProductImageRemoveFlag($form, false);
        resetImagePreview($field);
    }

    function resetProductCreateForm($form, response) {
        var lookupFields = [
            'item_unit_doc_num',
            'equivalent_unit_doc_num',
            'item_category_doc_num',
            'item_group_doc_num',
            'item_size_doc_num',
            'item_color_doc_num',
            'item_decal_doc_num',
            'item_model_doc_num',
            'item_origin_country_doc_num'
        ];
        var $field = $form.find('.js-product-image-picker-field').first();
        var $focusTarget = $form.find('[name="doc_number"]:visible:enabled').first();
        var defaultClassification = String($form.data('default-classification') || 'finished_product');

        clearValidation($form);

        $form.find('[name="doc_number"]').val('');
        $form.find('[name="name"], [name="barcode"], [name="notes"]').val('');
        $form.find('[name="item_classification"]').val(defaultClassification).trigger('change');
        $form.find('[name="reorder_point"]').val('');
        $form.find('[name="equivalent_value"]').val('');
        $form.find('[name="status"]').val('active').trigger('change');
        $form.find('[name="cost_as_inventory"]').prop('checked', false);
        $form.find('[name="is_displayable"]').prop('checked', true);
        $field.attr('data-current-url', '').data('current-url', '');
        $field.attr('data-existing-url', '').data('existing-url', '');
        setProductImageRemoveFlag($form, false);
        clearSelectedArchiveImage($form);
        $form.find('[name="submit_action"]').val('save');
        $form.find('[name="clone_source_token"]').remove();

        lookupFields.forEach(function (fieldName) {
            $form.find('[name="' + fieldName + '"]').val(null).trigger('change').trigger('change.select2');
        });

        $field.attr('data-current-url', '').data('current-url', '');
        resetImagePreview($field);
        resetComponents($form.find('.product-components-panel').first());

        if (!$focusTarget.length) {
            $focusTarget = $form.find('[name="name"]:visible:enabled').first();
        }

        window.setTimeout(function () {
            $focusTarget.trigger('focus');
        }, 0);
    }

    function currentModal() {
        return $('#product-inline-lookup-modal');
    }

    function focusFirstModalInput($modal) {
        if (!$modal.length) {
            return;
        }

        var focusInput = function () {
            var $input = $modal.find('[name="name"]:visible:enabled:not([readonly])').first();

            if (!$input.length) {
                $input = $modal.find('input[type="text"]:visible:enabled:not([readonly]), input[type="search"]:visible:enabled:not([readonly])').first();
            }

            if (!$input.length) {
                return;
            }

            $input.trigger('focus');

            if (typeof $input[0].select === 'function') {
                $input[0].select();
            }
        };

        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(focusInput);
        }

        window.setTimeout(focusInput, 30);
    }

    function showInlineLookupModal($button) {
        var $modal = currentModal();
        var $form = $('#product-inline-lookup-form');
        var label = String($button.data('label') || '');
        var targetSelect = String($button.data('target-select') || '');

        if (!$modal.length || !$form.length || !$button.data('url') || targetSelect === '') {
            return;
        }

        clearValidation($form);
        $form.attr('action', $button.data('url'));
        $form.find('[name="target_select"]').val(targetSelect);
        $form.find('[name="name"], [name="notes"]').val('');
        $modal.find('.js-inline-lookup-title').text(message('inlineLookupTitle').replace(':lookup', label));

        var modal = window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance($modal[0])
            : null;

        if (modal) {
            modal.show();
            return;
        }

        $modal.modal('show');
    }

    function selectInlineLookupOption(targetSelector, option) {
        var $select = $(targetSelector);

        if (!$select.length || !option || option.id === undefined || option.text === undefined) {
            return;
        }

        var value = String(option.id);
        var exists = $select.find('option').filter(function () {
            return String(this.value) === value;
        }).length > 0;

        if (!exists) {
            $select.append(new Option(String(option.text), value, true, true));
        }

        $select.val(value).trigger('change').trigger('change.select2');
    }

    function escapeHtml(value) {
        return $('<div></div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function componentPanel() {
        return $('.product-components-panel').first();
    }

    function componentReadonly($panel) {
        return String($panel.data('readonly') || '') === 'true';
    }

    function componentColumnCount($panel) {
        return componentReadonly($panel) ? 4 : 5;
    }

    function showComponentAlert($panel, text, type) {
        var $container = $panel.find('[data-components-alert]');

        if (!$container.length || !text) {
            return;
        }

        $container.html('<div class="alert alert-' + (type || 'danger') + ' mb-3">' + escapeHtml(text) + '</div>');
    }

    function clearComponentAlert($panel) {
        $panel.find('[data-components-alert]').empty();
    }

    function clearComponentValidation($panel) {
        $panel.find('.is-invalid').removeClass('is-invalid');
        $panel.find('[data-error-for^="components."]').text('');
        clearComponentAlert($panel);
    }

    function componentFieldName(index, field) {
        return 'components[' + index + '][' + field + ']';
    }

    function componentErrorKey(index, field) {
        return 'components.' + index + '.' + field;
    }

    function componentNameToErrorKey(name) {
        var match = String(name || '').match(/^components\[(\d+)\]\[([^\]]+)\]$/);

        return match ? componentErrorKey(match[1], match[2]) : String(name || '');
    }

    function componentErrorToName(field) {
        var match = String(field || '').match(/^components\.(\d+)\.([^.\]]+)$/);

        return match ? componentFieldName(match[1], match[2]) : String(field || '');
    }

    function clearComponentFieldValidation($field) {
        var name = $field.attr('name');
        var errorKey = componentNameToErrorKey(name);
        var $panel = $field.closest('.product-components-panel');

        $field.removeClass('is-invalid');

        if (errorKey !== '') {
            $panel.find('[data-error-for="' + errorKey + '"]').text('');
        }
    }

    function renderComponentValidation($panel, errors) {
        $.each(errors || {}, function (field, fieldMessages) {
            var normalizedField = String(field || '').replace('[]', '');
            var messageText = ($.isArray(fieldMessages) ? fieldMessages : [fieldMessages])[0] || '';
            var fieldName = componentErrorToName(normalizedField);
            var $field = $panel.find('[name="' + fieldName + '"]').first();

            if ($field.length > 0) {
                $field.addClass('is-invalid');
            }

            $panel.find('[data-error-for="' + normalizedField + '"]').text(messageText);
        });

        renderComponentValidationSummary($panel, errors);
    }

    function renderComponentValidationSummary($panel, errors) {
        var messagesList = validationMessages(errors);

        if (messagesList.length === 0) {
            showComponentAlert($panel, message('validationFailed'), 'danger');
            return;
        }

        var $list = $('<ul class="mb-0 ps-3"></ul>');

        messagesList.forEach(function (fieldMessage) {
            $list.append($('<li></li>').text(fieldMessage));
        });

        $panel.find('[data-components-alert]').empty().append(
            $('<div class="alert alert-danger mb-3"></div>')
                .append($('<div class="fw-semibold mb-1"></div>').text(message('validationSummaryTitle') || message('validationFailed')))
                .append($list)
        );
    }

    function componentTemplateHtml(index) {
        var template = document.getElementById('product-component-row-template');

        if (!template) {
            return '';
        }

        return String(template.innerHTML || '').replace(/__INDEX__/g, String(index));
    }

    function editableComponentRow(index, values) {
        var rowValues = values || {};
        var $row = $(componentTemplateHtml(index).trim()).first();
        var rawMaterialValue = String(rowValues.component_product_doc_num || '');
        var rawMaterialText = String(rowValues.raw_material || rawMaterialValue);

        if (!$row.length) {
            return $row;
        }

        $row.find('[data-component-field="public_id"]').val(rowValues.public_id || '');
        $row.find('[data-component-field="_delete"]').val(rowValues._delete ? '1' : '0');
        setComponentUnitOptions($row, rowValues.unit_options || fallbackComponentUnitOptions(rowValues), rowValues.unit_doc_num || '');
        $row.find('[data-component-field="quantity"]').val(rowValues.quantity_raw || rowValues.quantity || '');
        $row.find('[data-component-field="notes"]').val(rowValues.notes || '');

        if (rawMaterialValue !== '') {
            var option = new Option(rawMaterialText, rawMaterialValue, true, true);

            if (rowValues.imageUrl) {
                $(option).attr('data-image-url', String(rowValues.imageUrl));
            }

            $row.find('.js-product-component-raw-material')
                .append(option)
                .val(rawMaterialValue);
        }

        return $row;
    }

    function readonlyComponentRow($panel, values) {
        var rowValues = values || {};
        var $row = $('<tr class="js-product-component-readonly-row"></tr>');

        $row.append('<td class="dt-text dt-ellipsis"><span class="dt-ellipsis-content" title="' + escapeHtml(rowValues.raw_material || '') + '">' + escapeHtml(rowValues.raw_material || '') + '</span></td>');
        $row.append('<td class="dt-text dt-ellipsis"><span class="dt-ellipsis-content" title="' + escapeHtml(rowValues.unit || '') + '">' + escapeHtml(rowValues.unit || '') + '</span></td>');
        $row.append('<td class="text-center white-space-nowrap" dir="ltr">' + escapeHtml(rowValues.quantity || '') + '</td>');
        $row.append('<td class="dt-text dt-ellipsis"><span class="dt-ellipsis-content" title="' + escapeHtml(rowValues.notes || '') + '">' + escapeHtml(rowValues.notes || '') + '</span></td>');

        if (!componentReadonly($panel)) {
            $row.append('<td></td>');
        }

        return $row;
    }

    function componentUnitPlaceholder($unit) {
        return String($unit.data('placeholder') || $unit.attr('data-placeholder') || '');
    }

    function fallbackComponentUnitOptions(rowValues) {
        var values = rowValues || {};
        var unitDocNum = String(values.unit_doc_num || '').trim();
        var unitText = String(values.unit || unitDocNum).trim();

        if (unitDocNum === '') {
            return [];
        }

        return [{ id: unitDocNum, text: unitText }];
    }

    function normalizedComponentUnitOptions(options) {
        var seen = {};
        var result = [];

        (options || []).forEach(function (option) {
            var id = String(option && option.id !== undefined ? option.id : '').trim();
            var text = String(option && option.text !== undefined ? option.text : id).trim();

            if (id === '' || seen[id]) {
                return;
            }

            seen[id] = true;
            result.push({ id: id, text: text || id });
        });

        return result;
    }

    function setComponentUnitOptions($row, options, selectedUnitDocNum) {
        var $unit = $row.find('.js-product-component-unit').first();
        var unitOptions = normalizedComponentUnitOptions(options);
        var selectedValue = String(selectedUnitDocNum || '').trim();
        var selectedExists = false;

        if (!$unit.length) {
            return;
        }

        if (!$unit.is('select')) {
            $unit.text('');
            return;
        }

        $unit.empty().append($('<option></option>').attr('value', '').text(componentUnitPlaceholder($unit)));

        unitOptions.forEach(function (option) {
            if (option.id === selectedValue) {
                selectedExists = true;
            }

            $unit.append($('<option></option>').attr('value', option.id).text(option.text));
        });

        if (!selectedExists) {
            selectedValue = unitOptions.length === 1 ? unitOptions[0].id : '';
        }

        $unit
            .prop('disabled', unitOptions.length === 0)
            .val(selectedValue)
            .trigger('change')
            .trigger('change.select2');
    }

    function componentUnitOptionsFromData(data) {
        return data && $.isArray(data.unit_options) ? data.unit_options : [];
    }

    function storeComponentUnitOptions($field, data) {
        var unitOptions = componentUnitOptionsFromData(data);
        var $option = $field.find('option:selected').first();

        $field.data('componentUnitOptions', unitOptions);

        if (!$option.length) {
            return;
        }

        if (unitOptions.length > 0) {
            $option.attr('data-unit-options', JSON.stringify(unitOptions));
        } else {
            $option.removeAttr('data-unit-options');
        }
    }

    function storedComponentUnitOptions($field) {
        var storedOptions = $field.data('componentUnitOptions');

        if ($.isArray(storedOptions)) {
            return storedOptions;
        }

        var rawOptions = String($field.find('option:selected').attr('data-unit-options') || '').trim();

        if (rawOptions === '') {
            return [];
        }

        try {
            var parsed = JSON.parse(rawOptions);

            return $.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function componentUnitOptionsForSelection($field, data) {
        var dataOptions = componentUnitOptionsFromData(data);

        if (dataOptions.length > 0) {
            storeComponentUnitOptions($field, data);

            return dataOptions;
        }

        return storedComponentUnitOptions($field);
    }

    function loadComponentUnitOptions($row, selectedUnitDocNum) {
        var $field = $row.find('.js-product-component-raw-material').first();
        var componentDocNum = String($field.val() || '').trim();
        var url = String($field.data('url') || $field.attr('data-url') || '').trim();
        var requestKey = componentDocNum + ':' + Date.now();

        if (componentDocNum === '' || url === '') {
            setComponentUnitOptions($row, [], '');

            return;
        }

        $row.data('componentUnitRequestKey', requestKey);

        $.ajax({
            url: url,
            dataType: 'json',
            data: {
                selected_doc_num: componentDocNum
            }
        }).done(function (response) {
            var result = response && $.isArray(response.results) ? response.results[0] : null;
            var unitOptions = result ? componentUnitOptionsFromData(result) : [];

            if ($row.data('componentUnitRequestKey') !== requestKey || String($field.val() || '').trim() !== componentDocNum) {
                return;
            }

            if (result) {
                storeComponentUnitOptions($field, result);
            }

            setComponentUnitOptions($row, unitOptions, selectedUnitDocNum || '');
        }).fail(function () {
            if ($row.data('componentUnitRequestKey') !== requestKey) {
                return;
            }

            setComponentUnitOptions($row, [], '');
        });
    }

    function setComponentUnitOptionsForSelection($field, data, selectedUnitDocNum) {
        var $row = $field.closest('.js-product-component-row');
        var unitOptions = componentUnitOptionsForSelection($field, data);

        setComponentUnitOptions($row, [], '');

        if (unitOptions.length > 0) {
            setComponentUnitOptions($row, unitOptions, selectedUnitDocNum || '');
            return;
        }

        loadComponentUnitOptions($row, selectedUnitDocNum || '');
    }

    function componentUnitText($row) {
        var $unit = $row.find('.js-product-component-unit').first();

        if (!$unit.length) {
            return '';
        }

        if ($unit.is('select')) {
            return String($unit.find('option:selected').text() || '').trim();
        }

        return $unit.is('input, textarea') ? String($unit.val() || '') : String($unit.text() || '');
    }

    function componentUnitValue($row) {
        return String($row.find('.js-product-component-unit').first().val() || '').trim();
    }

    function componentRowValues($row, options) {
        var settings = options || {};
        var copyRawMaterial = settings.copyRawMaterial === true;
        var $rawMaterial = $row.find('.js-product-component-raw-material').first();
        var selectedText = $rawMaterial.find('option:selected').text() || '';

        return {
            public_id: '',
            component_product_doc_num: copyRawMaterial ? ($rawMaterial.val() || '') : '',
            raw_material: copyRawMaterial ? selectedText : '',
            unit: copyRawMaterial ? componentUnitText($row) : '',
            unit_doc_num: copyRawMaterial ? componentUnitValue($row) : '',
            quantity_raw: $row.find('[data-component-field="quantity"]').val() || '',
            notes: $row.find('[data-component-field="notes"]').val() || '',
            _delete: false
        };
    }

    function visibleEditableComponentRows($panel) {
        return $panel.find('.js-product-component-row').filter(function () {
            return !$(this).hasClass('d-none') && String($(this).find('[data-component-field="_delete"]').val() || '') !== '1';
        });
    }

    function visibleReadonlyComponentRows($panel) {
        return $panel.find('.js-product-component-readonly-row');
    }

    function updateComponentEmptyState($panel) {
        var $tbody = $panel.find('.product-components-table tbody');
        var rowCount = componentReadonly($panel)
            ? visibleReadonlyComponentRows($panel).length
            : visibleEditableComponentRows($panel).length;

        $tbody.find('.js-product-components-empty').remove();

        if (rowCount > 0) {
            return;
        }

        $tbody.append(
            '<tr class="js-product-components-empty"><td class="text-600 text-center py-3" colspan="' + componentColumnCount($panel) + '">' + escapeHtml(message('componentEmpty')) + '</td></tr>'
        );
    }

    function renumberComponents($panel) {
        $panel.find('.js-product-component-row').each(function (index) {
            var $row = $(this);

            $row.attr('data-component-index', index);
            $row.find('[data-component-field]').each(function () {
                var $field = $(this);
                var field = String($field.data('component-field') || '');

                if (field !== '') {
                    $field.attr('name', componentFieldName(index, field));
                }
            });
            $row.find('[data-error-for^="components."]').each(function () {
                var $error = $(this);
                var field = String($error.attr('data-error-for') || '').split('.').pop();

                if (field !== '') {
                    $error.attr('data-error-for', componentErrorKey(index, field));
                }
            });
        });
    }

    function syncComponentInputs($panel) {
        renumberComponents($panel);
    }

    function focusComponentRawMaterial($row) {
        window.setTimeout(function () {
            var $select = $row.find('.js-product-component-raw-material').first();

            if (!$select.length) {
                return;
            }

            if ($select.data('select2') && typeof $select.select2 === 'function') {
                $select.select2('open');
                return;
            }

            $select.trigger('focus');
        }, 0);
    }

    function addComponentRow($panel, values, shouldFocus, $afterRow) {
        if (!$panel.length || componentReadonly($panel)) {
            return $();
        }

        if ($panel.data('addingComponentRow')) {
            return $();
        }

        $panel.data('addingComponentRow', true);
        window.setTimeout(function () {
            $panel.removeData('addingComponentRow');
        }, 120);

        var $tbody = $panel.find('.product-components-table tbody');
        var index = $panel.find('.js-product-component-row').length;
        var $row = editableComponentRow(index, values || {});

        if (!$row.length) {
            return $row;
        }

        $tbody.find('.js-product-components-empty').remove();
        if ($afterRow && $afterRow.length && $.contains($tbody.get(0), $afterRow.get(0))) {
            $afterRow.after($row);
        } else {
            $tbody.append($row);
        }
        renumberComponents($panel);
        initSelect2($row[0]);
        initComponentUnitSelect($row);
        updateComponentEmptyState($panel);

        if (shouldFocus !== false) {
            focusComponentRawMaterial($row);
        }

        return $row;
    }

    function duplicateComponentRow($row) {
        if (!$row.length || $row.hasClass('d-none')) {
            return $();
        }

        return addComponentRow(
            $row.closest('.product-components-panel'),
            componentRowValues($row, { copyRawMaterial: false }),
            true,
            $row
        );
    }

    function resetComponents($panel) {
        if (!$panel.length) {
            return;
        }

        $panel.find('.product-components-table tbody').empty();
        clearComponentValidation($panel);
        updateComponentEmptyState($panel);
    }

    function removeComponentRow($row) {
        if (!$row.length) {
            return;
        }

        var $panel = $row.closest('.product-components-panel');
        var publicId = String($row.find('[data-component-field="public_id"]').val() || '').trim();

        if (publicId === '') {
            var $focusTarget = $row.next('.js-product-component-row:not(.d-none)').length
                ? $row.next('.js-product-component-row:not(.d-none)')
                : $row.prev('.js-product-component-row:not(.d-none)');

            $row.remove();
            renumberComponents($panel);
            updateComponentEmptyState($panel);

            if ($focusTarget.length) {
                focusComponentRawMaterial($focusTarget);
            }

            return;
        }

        $row.addClass('d-none').attr('aria-hidden', 'true');
        $row.find('[data-component-field="_delete"]').val('1');
        $row.find(':input').not('[data-component-field="public_id"], [data-component-field="_delete"]').prop('disabled', true);
        clearComponentValidation($panel);
        renumberComponents($panel);
        updateComponentEmptyState($panel);
    }

    function renderInitialComponents($panel, initialRows) {
        var $tbody = $panel.find('.product-components-table tbody');

        $tbody.empty();

        initialRows.forEach(function (row, index) {
            var $row = componentReadonly($panel)
                ? readonlyComponentRow($panel, row)
                : editableComponentRow(index, row);

            $tbody.append($row);

            if (!componentReadonly($panel)) {
                initSelect2($row[0]);
                initComponentUnitSelect($row);
            }
        });

        if (!componentReadonly($panel)) {
            renumberComponents($panel);
        }

        updateComponentEmptyState($panel);
    }

    function isAltShortcut(event, codes, keyCodes, legacyKeys) {
        if (window.AppShortcuts && typeof window.AppShortcuts.isAltShortcut === 'function') {
            return !event.ctrlKey && window.AppShortcuts.isAltShortcut(event, codes, keyCodes, legacyKeys);
        }

        var key = String(event.key || '').toLowerCase();
        var code = event.code || '';
        var keyCode = Number(event.keyCode || event.which || 0);

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
        var code = event.code || '';
        var keyCode = Number(event.keyCode || event.which || 0);

        return event.altKey === true
            && !event.ctrlKey
            && !event.metaKey
            && !event.shiftKey
            && (event.key === 'Delete' || code === 'Delete' || keyCode === 46);
    }

    function componentShortcutBlockedBySelect2(target) {
        var $target = $(target);

        if ($target.hasClass('select2-search__field') || $('.select2-container--open').length > 0) {
            return true;
        }

        return false;
    }

    function componentShortcutContextActive($panel, target) {
        if (!$panel.length || componentReadonly($panel)) {
            return false;
        }

        if ($(target).closest('.product-components-panel').length > 0) {
            return true;
        }

        var $pane = $panel.closest('.tab-pane');

        return $pane.length === 0 || ($pane.hasClass('active') && $pane.hasClass('show'));
    }

    function initComponentShortcuts() {
        $(document).off('keydown.coreProductsComponentShortcuts').on('keydown.coreProductsComponentShortcuts', function (event) {
            var $panel = componentPanel();
            var $target = $(event.target);
            var $row = $target.closest('.js-product-component-row');

            if (!componentShortcutContextActive($panel, event.target)) {
                return;
            }

            if (isAltDelete(event) && $row.length > 0 && !componentShortcutBlockedBySelect2(event.target)) {
                event.preventDefault();
                event.stopPropagation();
                removeComponentRow($row);
                return;
            }

            if (isAltShortcut(event, ['KeyD'], [68], ['d']) && $row.length > 0 && !componentShortcutBlockedBySelect2(event.target)) {
                event.preventDefault();
                event.stopPropagation();
                duplicateComponentRow($row);
                return;
            }

            if (!isAltShortcut(event, ['KeyN'], [78], ['n']) || componentShortcutBlockedBySelect2(event.target)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            addComponentRow($panel, {}, true, $row.length > 0 ? $row : null);
        });
    }

    function initComponents() {
        var $panel = componentPanel();

        if (!$panel.length) {
            return;
        }

        var initialRows = $.isArray(window.coreProductInitialComponents) ? window.coreProductInitialComponents : [];

        renderInitialComponents($panel, initialRows);
        initComponentShortcuts();
    }

    function handleProductImagePickerSelection(payload) {
        var data = payload || {};
        var file = data.file || data;
        var config = data.config || {};
        var $form = $(formSelector);
        var $field = config.uploader ? $(config.uploader).first() : $form.find('.product-image-field .js-product-image-picker-field').first();
        var $hidden = config.targetInput ? $(config.targetInput).first() : $form.find('[name="image_archive_file_doc_num"]').first();
        var publicId = file ? (file.public_id || data.public_id || '') : '';
        var previewUrl = file ? (file.thumbnail_url || data.thumbnail_url || file.url || data.url || '') : '';
        var fileName = file ? (file.name || data.name || file.original_name || '') : '';

        if (config.collection !== 'product_image' || !file || publicId === '' || !$hidden.length || !$field.length) {
            return;
        }

        $hidden.val(publicId).trigger('change');
        renderProductImagePreview($field, {
            src: previewUrl,
            fileName: fileName,
            file: file,
            data: data,
            hasImageValue: true
        });
        setProductImageRemoveFlag($form, false);
        clearFieldValidation($hidden);
        $form.find('[data-error-for="image"]').text('');
    }

    function handleProductImagePickerDeletion(payload) {
        var data = payload || {};
        var config = data.config || {};
        var $form = $(formSelector);
        var $field = config.uploader ? $(config.uploader).first() : $form.find('.product-image-field .js-product-image-picker-field').first();

        if (config.collection !== 'product_image' || !data.was_selected || !$field.length) {
            return;
        }

        setProductImageRemoveFlag($form, false);
        resetImagePreview($field);
        $form.find('[data-error-for="image"]').text('');
    }

    $(document)
        .off('change.coreProductsTrash', trashFilterSelector)
        .on('change.coreProductsTrash', trashFilterSelector, function () {
            clearSelection(currentTable());
            reloadTable();
        })
        .off('change.coreProductsSelectAll', selectAllSelector)
        .on('change.coreProductsSelectAll', selectAllSelector, function () {
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
        .off('click.coreProductsSelectCell', tableSelector + ' tbody tr:not(.child) td.dt-select')
        .on('click.coreProductsSelectCell', tableSelector + ' tbody tr:not(.child) td.dt-select', function (event) {
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
        .off('change.coreProducts', tableSelector + ' ' + rowCheckboxSelector)
        .on('change.coreProducts', tableSelector + ' ' + rowCheckboxSelector, function () {
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
        .off('click.coreProductsSelectStop mousedown.coreProductsSelectStop mouseup.coreProductsSelectStop', '.js-record-select, .js-record-checkbox, #select_all_records, td.dt-select')
        .on('click.coreProductsSelectStop mousedown.coreProductsSelectStop mouseup.coreProductsSelectStop', '.js-record-select, .js-record-checkbox, #select_all_records, td.dt-select', function (event) {
            event.stopPropagation();
        })
        .off('click.coreProductsImagePreview', '.js-product-image-preview')
        .on('click.coreProductsImagePreview', '.js-product-image-preview', function (event) {
            event.preventDefault();
            event.stopPropagation();

            showProductImagePreview($(this));
        })
        .off('hidden.bs.modal.coreProductsImagePreview', imagePreviewModalSelector)
        .on('hidden.bs.modal.coreProductsImagePreview', imagePreviewModalSelector, function () {
            resetProductImagePreviewModal($(this));
        })
        .off('file-picker:selected.coreProductsImagePicker', '.js-product-image-picker-trigger')
        .on('file-picker:selected.coreProductsImagePicker', '.js-product-image-picker-trigger', function (event, payload) {
            handleProductImagePickerSelection(payload);
        })
        .off('file-picker:deleted.coreProductsImagePicker', '.js-product-image-picker-trigger')
        .on('file-picker:deleted.coreProductsImagePicker', '.js-product-image-picker-trigger', function (event, payload) {
            handleProductImagePickerDeletion(payload);
        })
        .off('click.coreProductsRemoveImage', '.js-product-image-remove')
        .on('click.coreProductsRemoveImage', '.js-product-image-remove', function (event) {
            event.preventDefault();
            event.stopPropagation();

            clearProductImageSelection($(this));
        })
        .off('click.coreProducts', '.js-delete-record')
        .on('click.coreProducts', '.js-delete-record', function () {
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
        .off('dblclick.coreProducts', tableSelector + ' tbody tr')
        .on('dblclick.coreProducts', tableSelector + ' tbody tr', function (event) {
            if ($(event.target).closest('input, select, textarea, button, a, label, .dropdown, .dropdown-menu, .dropdown-toggle, [data-bs-toggle="dropdown"], td.dt-select, td.dtr-control').length > 0) {
                return;
            }

            var editLink = $(this).find('.js-edit-record').get(0);

            if (editLink) {
                editLink.click();
            }
        })
        .off('click.coreProductsBulk', '#bulk_action_apply')
        .on('click.coreProductsBulk', '#bulk_action_apply', function () {
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
        .off('click.coreProductsRestore', '.js-restore-record[data-restore-url]')
        .on('click.coreProductsRestore', '.js-restore-record[data-restore-url]', function () {
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
        .off('submit.coreProducts', formSelector)
        .on('submit.coreProducts', formSelector, function (event) {
            event.preventDefault();

            var $form = $(this);
            syncComponentInputs($form.find('.product-components-panel').first());
            clearValidation($form);

            $.ajax({
                url: $form.attr('action'),
                type: $form.attr('method') || 'POST',
                headers: headers(),
                data: new FormData($form[0]),
                processData: false,
                contentType: false
            }).done(function (response) {
                if (response && response.success === false && response.type === 'no_changes') {
                    showAlert($form.find('[data-form-alert]'), response.message || message('noChanges'), 'warning');
                    showInfo(response.message || message('noChanges'));
                    return;
                }

                showToast('success', response && response.message ? response.message : message('saved'));

                if (response && response.reset_form) {
                    resetProductCreateForm($form, response);

                    if (response.message) {
                        showAlert($form.find('[data-form-alert]'), response.message, 'success');
                    }

                    return;
                }

                updateUrlsAfterSave($form, response);
                updateImagePickerFromResponse($form, response);

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
        .off('click.coreProducts', formSelector + ' [data-submit-action]')
        .on('click.coreProducts', formSelector + ' [data-submit-action]', function () {
            var $form = $(this).closest('form');

            if (!$form.length) {
                $form = $(formSelector).first();
            }

            $form.find('[name="submit_action"]').val($(this).data('submit-action'));
        })
        .off('click.coreProductsInlineLookup', '.js-inline-lookup-create')
        .on('click.coreProductsInlineLookup', '.js-inline-lookup-create', function () {
            showInlineLookupModal($(this));
        })
        .off('shown.bs.modal.coreProductsInlineAutofocus', '#product-inline-lookup-modal')
        .on('shown.bs.modal.coreProductsInlineAutofocus', '#product-inline-lookup-modal', function () {
            focusFirstModalInput($(this));
        })
        .off('submit.coreProductsInlineLookup', '#product-inline-lookup-form')
        .on('submit.coreProductsInlineLookup', '#product-inline-lookup-form', function (event) {
            event.preventDefault();

            var $form = $(this);
            var targetSelector = String($form.find('[name="target_select"]').val() || '');

            clearValidation($form);

            $.ajax({
                url: $form.attr('action'),
                type: 'POST',
                headers: headers(),
                data: $form.serialize()
            }).done(function (response) {
                var option = response && response.data ? response.data.option : null;

                selectInlineLookupOption(targetSelector, option);
                showToast('success', response && response.message ? response.message : message('inlineLookupCreated'));

                var $modal = currentModal();
                var modal = window.bootstrap && window.bootstrap.Modal && $modal.length
                    ? window.bootstrap.Modal.getInstance($modal[0])
                    : null;

                if (modal) {
                    modal.hide();
                    return;
                }

                $modal.modal('hide');
            }).fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidation($form, xhr.responseJSON.errors);
                    renderValidationSummary($form, xhr.responseJSON.errors);
                    return;
                }

                showAlert($form.find('[data-form-alert]'), responseMessage(xhr), 'danger');
            });
        })
        .off('input.coreProductsValidation change.coreProductsValidation', formSelector + ' input, ' + formSelector + ' select, ' + formSelector + ' textarea')
        .on('input.coreProductsValidation change.coreProductsValidation', formSelector + ' input, ' + formSelector + ' select, ' + formSelector + ' textarea', function () {
            clearFieldValidation($(this));
        })
        .off('input.coreProductsInlineValidation change.coreProductsInlineValidation', '#product-inline-lookup-form input, #product-inline-lookup-form textarea')
        .on('input.coreProductsInlineValidation change.coreProductsInlineValidation', '#product-inline-lookup-form input, #product-inline-lookup-form textarea', function () {
            var $field = $(this);
            var name = $field.attr('name');

            if (!name) {
                return;
            }

            $field.removeClass('is-invalid');
            $('#product-inline-lookup-form').find('[data-error-for="' + name + '"]').text('');
        })
        .off('select2:selecting.coreProductsComponents', '.js-product-component-raw-material')
        .on('select2:selecting.coreProductsComponents', '.js-product-component-raw-material', function (event) {
            var data = event.params && event.params.args ? event.params.args.data : null;

            if (data) {
                storeComponentUnitOptions($(this), data);
            }
        })
        .off('select2:select.coreProductsComponents', '.js-product-component-raw-material')
        .on('select2:select.coreProductsComponents', '.js-product-component-raw-material', function (event) {
            var data = event.params ? event.params.data : null;
            var $field = $(this);

            setComponentUnitOptionsForSelection($field, data, '');
            clearComponentFieldValidation($field);
        })
        .off('change.coreProductsComponentsRawClear', '.js-product-component-raw-material')
        .on('change.coreProductsComponentsRawClear', '.js-product-component-raw-material', function () {
            var $field = $(this);
            var $row = $field.closest('.js-product-component-row');
            var value = $field.val();

            if (value) {
                var $unit = $row.find('.js-product-component-unit').first();
                var needsOptions = $unit.length && $unit.is('select') && $unit.find('option').length <= 1;

                if (needsOptions) {
                    var storedOptions = storedComponentUnitOptions($field);

                    if (storedOptions.length > 0) {
                        setComponentUnitOptions($row, storedOptions, '');
                    } else {
                        loadComponentUnitOptions($row, '');
                    }
                }
            } else {
                $field.removeData('componentUnitOptions');
                setComponentUnitOptions($row, [], '');
            }

            clearComponentFieldValidation($field);
        })
        .off('input.coreProductsComponentsValidation change.coreProductsComponentsValidation', '.product-components-panel [data-component-field]')
        .on('input.coreProductsComponentsValidation change.coreProductsComponentsValidation', '.product-components-panel [data-component-field]', function () {
            clearComponentFieldValidation($(this));
        })
        .off('click.coreProductsComponentsAdd', '.js-product-component-add-row')
        .on('click.coreProductsComponentsAdd', '.js-product-component-add-row', function () {
            addComponentRow($(this).closest('.product-components-panel'), {}, true);
        })
        .off('click.coreProductsComponentsRemove', '.js-product-component-remove-row')
        .on('click.coreProductsComponentsRemove', '.js-product-component-remove-row', function () {
            removeComponentRow($(this).closest('.js-product-component-row'));
        })
        .off('click.coreProductsComponentsDuplicate', '.js-product-component-duplicate-row')
        .on('click.coreProductsComponentsDuplicate', '.js-product-component-duplicate-row', function () {
            duplicateComponentRow($(this).closest('.js-product-component-row'));
        })
        .off('submit.coreProducts', '#products-document-number-settings-form')
        .on('submit.coreProducts', '#products-document-number-settings-form', function (event) {
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
    initSelect2(document);
    initComponents();
})(jQuery, window);
