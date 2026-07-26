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
    var componentCalculationScale = 8;
    var componentWorkingScale = 24;
    var componentUuidCounter = 0;

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

    function refreshNumericInputs(root) {
        if (window.AppNumbers && typeof window.AppNumbers.refresh === 'function') {
            window.AppNumbers.refresh(root);
        }
    }

    function normalizeNumericForm(form) {
        if (window.AppNumbers && typeof window.AppNumbers.normalizeForm === 'function') {
            window.AppNumbers.normalizeForm(form);
        }
    }

    function normalizedDecimal(value) {
        if (window.AppNumbers && typeof window.AppNumbers.normalize === 'function') {
            return window.AppNumbers.normalize(value);
        }

        var normalized = String(value === null || value === undefined ? '' : value).trim().replace(/,/g, '');

        return normalized === '' || /^-?\d+(?:\.\d+)?$/.test(normalized) ? normalized : null;
    }

    function decimalParts(value) {
        var normalized = normalizedDecimal(value);

        if (normalized === null || normalized === '' || typeof BigInt !== 'function') {
            return null;
        }

        var negative = normalized.charAt(0) === '-';
        var unsigned = negative ? normalized.slice(1) : normalized;
        var parts = unsigned.split('.');
        var fraction = parts[1] || '';
        var digits = ((parts[0] || '0') + fraction).replace(/^0+(?=\d)/, '');

        try {
            var integer = BigInt(digits || '0');

            return {
                integer: negative ? -integer : integer,
                scale: fraction.length
            };
        } catch (error) {
            return null;
        }
    }

    function decimalPowerOfTen(scale) {
        var result = BigInt(1);
        var ten = BigInt(10);

        for (var index = 0; index < scale; index += 1) {
            result *= ten;
        }

        return result;
    }

    function decimalRoundDivide(numerator, denominator) {
        if (denominator === BigInt(0)) {
            return null;
        }

        var negative = (numerator < BigInt(0)) !== (denominator < BigInt(0));
        var positiveNumerator = numerator < BigInt(0) ? -numerator : numerator;
        var positiveDenominator = denominator < BigInt(0) ? -denominator : denominator;
        var quotient = positiveNumerator / positiveDenominator;
        var remainder = positiveNumerator % positiveDenominator;

        if (remainder * BigInt(2) >= positiveDenominator) {
            quotient += BigInt(1);
        }

        return negative ? -quotient : quotient;
    }

    function decimalScaledInteger(value, scale) {
        var parts = decimalParts(value);

        if (!parts) {
            return null;
        }

        if (parts.scale === scale) {
            return parts.integer;
        }

        if (parts.scale < scale) {
            return parts.integer * decimalPowerOfTen(scale - parts.scale);
        }

        return decimalRoundDivide(parts.integer, decimalPowerOfTen(parts.scale - scale));
    }

    function decimalFromScaledInteger(integer, scale) {
        if (integer === null || integer === undefined) {
            return null;
        }

        var negative = integer < BigInt(0);
        var digits = String(negative ? -integer : integer).padStart(scale + 1, '0');
        var integerPart = scale > 0 ? digits.slice(0, -scale) : digits;
        var fraction = scale > 0 ? digits.slice(-scale).replace(/0+$/, '') : '';
        var result = integerPart + (fraction === '' ? '' : '.' + fraction);

        return negative && result !== '0' ? '-' + result : result;
    }

    function decimalMultiply(left, right, scale) {
        var leftParts = decimalParts(left);
        var rightParts = decimalParts(right);

        if (!leftParts || !rightParts) {
            return null;
        }

        var sourceScale = leftParts.scale + rightParts.scale;
        var product = leftParts.integer * rightParts.integer;
        var result = sourceScale > scale
            ? decimalRoundDivide(product, decimalPowerOfTen(sourceScale - scale))
            : product * decimalPowerOfTen(scale - sourceScale);

        return decimalFromScaledInteger(result, scale);
    }

    function decimalDivide(left, right, scale) {
        var leftParts = decimalParts(left);
        var rightParts = decimalParts(right);

        if (!leftParts || !rightParts || rightParts.integer === BigInt(0)) {
            return null;
        }

        var numerator = leftParts.integer * decimalPowerOfTen(rightParts.scale + scale);
        var denominator = rightParts.integer * decimalPowerOfTen(leftParts.scale);

        return decimalFromScaledInteger(decimalRoundDivide(numerator, denominator), scale);
    }

    function decimalAdd(left, right, scale) {
        var leftInteger = decimalScaledInteger(left, scale);
        var rightInteger = decimalScaledInteger(right, scale);

        if (leftInteger === null || rightInteger === null) {
            return null;
        }

        return decimalFromScaledInteger(leftInteger + rightInteger, scale);
    }

    function decimalIsPositive(value) {
        if (window.AppNumbers && typeof window.AppNumbers.compare === 'function') {
            return window.AppNumbers.compare(value, '0') === 1;
        }

        var parts = decimalParts(value);

        return parts !== null && parts.integer > BigInt(0);
    }

    function bigIntegerAbsolute(value) {
        return value < BigInt(0) ? -value : value;
    }

    function bigIntegerGreatestCommonDivisor(left, right) {
        var first = bigIntegerAbsolute(left);
        var second = bigIntegerAbsolute(right);

        while (second !== BigInt(0)) {
            var remainder = first % second;

            first = second;
            second = remainder;
        }

        return first;
    }

    function normalizedRational(numerator, denominator) {
        if (denominator === BigInt(0)) {
            return null;
        }

        var normalizedNumerator = denominator < BigInt(0) ? -numerator : numerator;
        var normalizedDenominator = denominator < BigInt(0) ? -denominator : denominator;
        var divisor = bigIntegerGreatestCommonDivisor(normalizedNumerator, normalizedDenominator);

        return {
            numerator: normalizedNumerator / divisor,
            denominator: normalizedDenominator / divisor
        };
    }

    function decimalRational(value) {
        var parts = decimalParts(value);

        if (!parts) {
            return null;
        }

        return normalizedRational(parts.integer, decimalPowerOfTen(parts.scale));
    }

    function rationalMultiply(left, right) {
        if (!left || !right) {
            return null;
        }

        return normalizedRational(
            left.numerator * right.numerator,
            left.denominator * right.denominator
        );
    }

    function rationalInverse(value) {
        if (!value || value.numerator === BigInt(0)) {
            return null;
        }

        return normalizedRational(value.denominator, value.numerator);
    }

    function rationalToDecimal(value, scale) {
        if (!value) {
            return null;
        }

        return decimalFromScaledInteger(
            decimalRoundDivide(
                value.numerator * decimalPowerOfTen(scale),
                value.denominator
            ),
            scale
        );
    }

    function rationalFactorsAreOutputEquivalent(left, right) {
        if (!left || !right) {
            return false;
        }

        var leftAtCommonDenominator = left.numerator * right.denominator;
        var rightAtCommonDenominator = right.numerator * left.denominator;
        var difference = bigIntegerAbsolute(leftAtCommonDenominator - rightAtCommonDenominator);

        if (difference === BigInt(0)) {
            return true;
        }

        var largestFactor = leftAtCommonDenominator > rightAtCommonDenominator
            ? leftAtCommonDenominator
            : rightAtCommonDenominator;

        if (largestFactor <= BigInt(0)) {
            return false;
        }

        var maximumStoredWeightNumerator = BigInt('999999999999999999');
        var maximumStoredWeightDenominator = BigInt('100000000');
        var halfStoredWeightQuantumNumerator = BigInt('5');
        var halfStoredWeightQuantumDenominator = BigInt('1000000000');

        return difference * maximumStoredWeightNumerator * halfStoredWeightQuantumDenominator
            <= largestFactor * halfStoredWeightQuantumNumerator * maximumStoredWeightDenominator;
    }

    function formatDecimal(value) {
        if (value === null || value === undefined || value === '') {
            return '';
        }

        if (window.AppNumbers && typeof window.AppNumbers.format === 'function') {
            return window.AppNumbers.format(value);
        }

        return String(value);
    }

    function setNumericFieldValue($field, value) {
        if (!$field.length || value === null || value === undefined) {
            return;
        }

        $field.val(value);

        if (!window.AppNumbers) {
            return;
        }

        if (document.activeElement === $field.get(0) && typeof window.AppNumbers.validateInput === 'function') {
            window.AppNumbers.validateInput($field.get(0));
            return;
        }

        if (typeof window.AppNumbers.formatInput === 'function') {
            window.AppNumbers.formatInput($field.get(0));
        }
    }

    function interpolateMessage(template, replacements) {
        var result = String(template || '');

        $.each(replacements || {}, function (key, value) {
            result = result.split(':' + key).join(String(value === null || value === undefined ? '' : value));
        });

        return result;
    }

    function componentUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        var bytes = new Uint8Array(16);

        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            window.crypto.getRandomValues(bytes);
        } else {
            componentUuidCounter += 1;
            var seed = Date.now() + componentUuidCounter;

            for (var index = 0; index < bytes.length; index += 1) {
                seed = (seed * 1664525 + 1013904223) % 4294967296;
                bytes[index] = seed % 256;
            }
        }

        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;

        return Array.prototype.map.call(bytes, function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('').replace(/^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5');
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
                { data: 'reorder_point', name: 'products.reorder_point', className: 'dt-number align-middle white-space-nowrap text-end', responsivePriority: 30 },
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
        $form.find('[name="cost_as_inventory"]').prop('checked', true);
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
        refreshNumericInputs($form[0]);

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
        return componentReadonly($panel) ? 6 : 7;
    }

    function componentClientKey($row) {
        return String($row.find('[data-component-field="client_key"]').val() || $row.attr('data-client-key') || '').trim();
    }

    function componentCalculationMethod($row) {
        var method = String($row.find('[data-component-field="calculation_method"]').val() || $row.attr('data-calculation-method') || 'direct').trim();

        return method === 'percentage' ? 'percentage' : 'direct';
    }

    function componentInputSource($row) {
        var source = String($row.find('[data-component-field="input_source"]').val() || 'weight').trim();

        return source === 'percentage' ? 'percentage' : 'weight';
    }

    function setComponentInputSource($row, source) {
        $row.find('[data-component-field="input_source"]').val(source === 'percentage' ? 'percentage' : 'weight');
        updateComponentCalculatedFieldState($row);
    }

    function componentReferenceKey($row) {
        return String($row.find('[data-component-field="reference_component_key"]').val() || $row.attr('data-reference-component-key') || '').trim();
    }

    function componentQuantityValue($row) {
        return normalizedDecimal($row.find('[data-component-field="quantity"]').val());
    }

    function componentPercentageValue($row) {
        return normalizedDecimal($row.find('[data-component-field="percentage"]').val());
    }

    function componentActive($row) {
        return !$row.hasClass('d-none') && String($row.find('[data-component-field="_delete"]').val() || '') !== '1';
    }

    function componentBoolean(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function normalizedInitialComponentRows(initialRows) {
        var usedKeys = {};
        var publicIdMap = {};
        var rows = (initialRows || []).map(function (row) {
            var values = $.extend({}, row || {});
            var key = String(values.client_key || '').trim();

            if (key === '' || usedKeys[key]) {
                key = componentUuid();
            }

            values.client_key = key;
            values.calculation_method = values.calculation_method === 'percentage' ? 'percentage' : 'direct';
            values.input_source = values.input_source === 'percentage' ? 'percentage' : 'weight';
            values.reference_component_key = String(values.reference_component_key || values.reference_client_key || '').trim();
            values._delete = componentBoolean(values._delete);
            usedKeys[key] = true;

            if (values.public_id) {
                publicIdMap[String(values.public_id)] = key;
            }

            return values;
        });

        rows.forEach(function (values) {
            if (values.reference_component_key && publicIdMap[values.reference_component_key]) {
                values.reference_component_key = publicIdMap[values.reference_component_key];
            }
        });

        return rows;
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
        if ($field.hasClass('select2-hidden-accessible')) {
            $field.next('.select2-container').find('.select2-selection').removeClass('is-invalid');
        }

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
        var method = rowValues.calculation_method === 'percentage' ? 'percentage' : 'direct';
        var referenceKey = String(rowValues.reference_component_key || rowValues.reference_client_key || '').trim();
        var inputSource = rowValues.input_source === 'percentage' ? 'percentage' : 'weight';
        var deleted = componentBoolean(rowValues._delete);

        if (!$row.length) {
            return $row;
        }

        $row.attr('data-client-key', rowValues.client_key || componentUuid());
        $row.find('[data-component-field="client_key"]').val($row.attr('data-client-key'));
        $row.find('[data-component-field="public_id"]').val(rowValues.public_id || '');
        $row.find('[data-component-field="_delete"]').val(deleted ? '1' : '0');
        $row.find('[data-component-field="calculation_method"]').val(method);
        $row.find('[data-component-field="input_source"]').val(inputSource);
        $row.find('[data-component-field="percentage"]').val(rowValues.percentage_raw || rowValues.percentage || '');
        $row.find('[data-component-field="reference_component_key"]').val(referenceKey);
        $row.data('componentMetadataComplete', Object.prototype.hasOwnProperty.call(rowValues, 'unit_conversion_edges'));
        $row.data('componentUnitConversionEdges', $.isArray(rowValues.unit_conversion_edges) ? rowValues.unit_conversion_edges : []);
        setComponentUnitOptions($row, rowValues.unit_options || fallbackComponentUnitOptions(rowValues), rowValues.unit_doc_num || '');
        $row.find('[data-component-field="quantity"]').val(rowValues.quantity_raw || rowValues.quantity || '');
        $row.find('[data-component-field="notes"]').val(rowValues.notes || '');
        updateComponentMethodUi($row);

        if (rawMaterialValue !== '') {
            var option = new Option(rawMaterialText, rawMaterialValue, true, true);

            if (rowValues.imageUrl) {
                $(option).attr('data-image-url', String(rowValues.imageUrl));
            }

            if ($.isArray(rowValues.unit_options)) {
                $(option).attr('data-unit-options', JSON.stringify(rowValues.unit_options));
            }

            if ($.isArray(rowValues.unit_conversion_edges)) {
                $(option).attr('data-unit-conversion-edges', JSON.stringify(rowValues.unit_conversion_edges));
            }

            $row.find('.js-product-component-raw-material')
                .append(option)
                .val(rawMaterialValue);
        }

        if (referenceKey !== '') {
            $row.find('.js-product-component-reference')
                .append(new Option(referenceKey, referenceKey, true, true))
                .val(referenceKey);
        }

        if (deleted) {
            $row.addClass('d-none').attr('aria-hidden', 'true');
            $row.find(':input')
                .not('[data-component-field="client_key"], [data-component-field="public_id"], [data-component-field="_delete"]')
                .prop('disabled', true);
        }

        refreshNumericInputs($row[0]);

        return $row;
    }

    function readonlyComponentRow($panel, values, rowMap) {
        var rowValues = values || {};
        var method = rowValues.calculation_method === 'percentage' ? 'percentage' : 'direct';
        var referenceKey = String(rowValues.reference_component_key || '').trim();
        var reference = rowMap[referenceKey] || null;
        var $row = $('<tr class="js-product-component-readonly-row"></tr>')
            .attr('data-client-key', rowValues.client_key || '')
            .attr('data-calculation-method', method)
            .attr('data-reference-component-key', referenceKey)
            .attr('data-unit-doc-num', rowValues.unit_doc_num || '')
            .attr('data-unit-text', rowValues.unit || '')
            .attr('data-quantity', rowValues.quantity_raw || rowValues.quantity || '');
        $row.data('componentUnitConversionEdges', $.isArray(rowValues.unit_conversion_edges) ? rowValues.unit_conversion_edges : []);
        var rawQuantity = rowValues.quantity_raw !== null && rowValues.quantity_raw !== undefined && rowValues.quantity_raw !== ''
            ? rowValues.quantity_raw
            : (rowValues.quantity === null || rowValues.quantity === undefined ? '' : rowValues.quantity);
        var quantity = formatDecimal(rawQuantity);
        var percentage = formatDecimal(rowValues.percentage_raw || rowValues.percentage || '');
        var methodText = method === 'percentage' ? message('componentPercentage') : message('componentDirect');
        var calculationText = message('componentDirectFormula');

        if (method === 'percentage') {
            var referenceText = reference
                ? componentLineLabelFromValues(reference, Number(reference._line_number || 0))
                : (message('componentUnknownReference') || referenceKey);
            var resultWeight = quantity + (rowValues.unit ? ' ' + rowValues.unit : '');

            calculationText = referenceText;

            if (reference && percentage !== '') {
                var $referenceConversionRow = $('<span></span>');

                $referenceConversionRow.data(
                    'componentUnitConversionEdges',
                    $.isArray(reference.unit_conversion_edges) ? reference.unit_conversion_edges : []
                );

                var referenceFactor = componentConversionFactor(
                    $panel,
                    reference.unit_doc_num || '',
                    rowValues.unit_doc_num || '',
                    $referenceConversionRow,
                    $row
                );
                var convertedReference = referenceFactor === null
                    ? null
                    : decimalMultiply(
                        reference.quantity_raw || reference.quantity || '',
                        referenceFactor,
                        componentWorkingScale
                    );

                calculationText = convertedReference === null
                    ? referenceText + ' · ' + message('componentIncompatibleUnits')
                    : referenceText + ' · ' + interpolateMessage(message('componentFormulaTemplate'), {
                        reference_weight: formatDecimal(convertedReference) + (rowValues.unit ? ' ' + rowValues.unit : ''),
                        percentage: percentage,
                        weight: resultWeight
                    });
            }
        }

        $row.append('<td class="dt-text dt-ellipsis"><span class="dt-ellipsis-content" title="' + escapeHtml(rowValues.raw_material || '') + '">' + escapeHtml(rowValues.raw_material || '') + '</span></td>');
        $row.append('<td class="dt-text dt-ellipsis"><span class="dt-ellipsis-content" title="' + escapeHtml(rowValues.unit || '') + '">' + escapeHtml(rowValues.unit || '') + '</span></td>');
        $row.append('<td class="dt-text white-space-nowrap">' + escapeHtml(methodText) + '</td>');
        $row.append('<td class="dt-number text-center white-space-nowrap" dir="ltr">' + escapeHtml(quantity) + '</td>');
        $row.append('<td class="dt-text"><span class="small">' + escapeHtml(calculationText) + '</span></td>');
        $row.append('<td class="dt-text dt-ellipsis"><span class="dt-ellipsis-content" title="' + escapeHtml(rowValues.notes || '') + '">' + escapeHtml(rowValues.notes || '') + '</span></td>');

        if (!componentReadonly($panel)) {
            $row.append('<td></td>');
        }

        return $row;
    }

    function componentLineLabelFromValues(values, lineNumber) {
        var componentText = String(values && (values.raw_material || values.component_product_doc_num) || '').trim();
        var template = componentText === '' ? message('componentLineOnlyLabel') : message('componentLineLabel');

        return interpolateMessage(template, {
            line: lineNumber,
            component: componentText
        });
    }

    function updateComponentMethodUi($row) {
        var isPercentage = componentCalculationMethod($row) === 'percentage';

        $row.find('.js-product-component-percentage-fields').toggleClass('d-none', !isPercentage);

        if (!isPercentage) {
            setComponentInputSource($row, 'weight');
            updateComponentCalculatedFieldState($row);
            return;
        }

        updateComponentCalculatedFieldState($row);
    }

    function updateComponentCalculatedFieldState($row) {
        var $weight = $row.find('[data-component-field="quantity"]').first();
        var $percentage = $row.find('[data-component-field="percentage"]').first();
        var $fields = $weight.add($percentage);

        $fields
            .removeClass('product-component-calculated-field')
            .removeAttr('data-calculated title aria-description');

        if (componentCalculationMethod($row) !== 'percentage') {
            return;
        }

        var weightIsCalculated = componentInputSource($row) === 'percentage';
        var $calculatedField = weightIsCalculated ? $weight : $percentage;
        var title = weightIsCalculated
            ? message('componentCalculatedWeightTitle')
            : message('componentCalculatedPercentageTitle');

        $calculatedField
            .addClass('product-component-calculated-field')
            .attr('data-calculated', 'true')
            .attr('title', title)
            .attr('aria-description', title);
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

    function componentUnitConversionEdgesFromData(data) {
        if (data && $.isArray(data.unit_conversion_edges)) {
            return data.unit_conversion_edges;
        }

        return [];
    }

    function storeComponentUnitConversionEdges($field, data) {
        var edges = componentUnitConversionEdgesFromData(data);
        var $option = $field.find('option:selected').first();

        $field.data('componentUnitConversionEdges', edges);
        $field.closest('.js-product-component-row').data('componentUnitConversionEdges', edges);

        if (!$option.length) {
            return;
        }

        if (edges.length > 0) {
            $option.attr('data-unit-conversion-edges', JSON.stringify(edges));
        } else {
            $option.removeAttr('data-unit-conversion-edges');
        }
    }

    function storedComponentUnitConversionEdges($field) {
        var storedEdges = $field.data('componentUnitConversionEdges');

        if ($.isArray(storedEdges)) {
            return storedEdges;
        }

        var rawEdges = String($field.find('option:selected').attr('data-unit-conversion-edges') || '').trim();

        if (rawEdges === '') {
            return [];
        }

        try {
            var parsed = JSON.parse(rawEdges);

            return $.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function storeComponentUnitOptions($field, data) {
        var unitOptions = componentUnitOptionsFromData(data);
        var $option = $field.find('option:selected').first();

        $field.data('componentUnitOptions', unitOptions);
        storeComponentUnitConversionEdges($field, data);

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
                var $selectedOption = $field.find('option:selected').first();

                if ($selectedOption.length && result.text) {
                    $selectedOption.text(String(result.text));
                }

                if ($selectedOption.length && result.imageUrl) {
                    $selectedOption.attr('data-image-url', String(result.imageUrl));
                }

                storeComponentUnitOptions($field, result);
                $field.trigger('change.select2');
            }

            setComponentUnitOptions($row, unitOptions, selectedUnitDocNum || '');
            rebuildComponentReferenceOptions($row.closest('.product-components-panel'));
            recalculateComponentGraph($row.closest('.product-components-panel'));
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

        storeComponentUnitConversionEdges($field, data || {});

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
            client_key: componentUuid(),
            public_id: '',
            component_product_doc_num: copyRawMaterial ? ($rawMaterial.val() || '') : '',
            raw_material: copyRawMaterial ? selectedText : '',
            unit: copyRawMaterial ? componentUnitText($row) : '',
            unit_doc_num: copyRawMaterial ? componentUnitValue($row) : '',
            unit_options: copyRawMaterial ? storedComponentUnitOptions($rawMaterial) : [],
            unit_conversion_edges: copyRawMaterial ? storedComponentUnitConversionEdges($rawMaterial) : [],
            calculation_method: componentCalculationMethod($row),
            quantity_raw: $row.find('[data-component-field="quantity"]').val() || '',
            percentage: $row.find('[data-component-field="percentage"]').val() || '',
            reference_component_key: componentReferenceKey($row),
            input_source: componentInputSource($row),
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

    function componentSelectedText($row) {
        var $selected = $row.find('.js-product-component-raw-material option:selected').first();

        return String($selected.text() || $row.find('.js-product-component-raw-material').val() || '').trim();
    }

    function componentLineLabel($row, lineNumber) {
        return componentLineLabelFromValues({
            raw_material: componentSelectedText($row)
        }, lineNumber);
    }

    function activeComponentRowMap($panel) {
        var rowMap = {};

        visibleEditableComponentRows($panel).each(function () {
            var $row = $(this);
            var key = componentClientKey($row);

            if (key !== '') {
                rowMap[key] = $row;
            }
        });

        return rowMap;
    }

    function componentDependentKeys($panel, rootKey) {
        var rowMap = activeComponentRowMap($panel);
        var result = {};
        var queue = [rootKey];

        while (queue.length > 0) {
            var targetKey = queue.shift();

            $.each(rowMap, function (key, $row) {
                if (!result[key] && componentCalculationMethod($row) === 'percentage' && componentReferenceKey($row) === targetKey) {
                    result[key] = true;
                    queue.push(key);
                }
            });
        }

        return result;
    }

    function rebuildComponentReferenceOptions($panel) {
        if (!$panel.length || componentReadonly($panel)) {
            return;
        }

        var $rows = visibleEditableComponentRows($panel);

        $rows.each(function (visibleIndex) {
            $(this).attr('data-line-number', visibleIndex + 1);
        });

        $rows.each(function () {
            var $row = $(this);
            var selfKey = componentClientKey($row);
            var selectedKey = componentReferenceKey($row);
            var excludedKeys = componentDependentKeys($panel, selfKey);
            var $reference = $row.find('.js-product-component-reference').first();
            var selectedFound = false;

            if (!$reference.length) {
                return;
            }

            $reference.empty().append($('<option></option>').attr('value', '').text($reference.data('placeholder') || ''));

            $rows.each(function (candidateIndex) {
                var $candidate = $(this);
                var candidateKey = componentClientKey($candidate);

                if (candidateKey === '' || candidateKey === selfKey || excludedKeys[candidateKey]) {
                    return;
                }

                var label = componentLineLabel($candidate, candidateIndex + 1);

                $reference.append($('<option></option>').attr('value', candidateKey).text(label));

                if (candidateKey === selectedKey) {
                    selectedFound = true;
                }
            });

            if (selectedKey !== '' && !selectedFound) {
                $reference.append(
                    $('<option></option>')
                        .attr('value', selectedKey)
                        .text(message('componentUnknownReference') || selectedKey)
                );
            }

            $reference.val(selectedKey).trigger('change.select2');
        });
    }

    function componentGraphStatuses($panel) {
        var rowMap = activeComponentRowMap($panel);
        var statuses = {};
        var visiting = {};
        var visited = {};
        var path = [];

        $.each(rowMap, function (key, $row) {
            if (componentCalculationMethod($row) !== 'percentage') {
                return;
            }

            var referenceKey = componentReferenceKey($row);

            if (referenceKey === '') {
                statuses[key] = 'missing';
            } else if (referenceKey === key) {
                statuses[key] = 'self';
            } else if (!rowMap[referenceKey]) {
                statuses[key] = 'missing';
            }
        });

        function visit(key) {
            if (visited[key] || !rowMap[key]) {
                return;
            }

            if (visiting[key]) {
                var cycleStart = path.indexOf(key);

                path.slice(cycleStart < 0 ? 0 : cycleStart).forEach(function (cycleKey) {
                    statuses[cycleKey] = 'cycle';
                });

                return;
            }

            visiting[key] = true;
            path.push(key);

            var $row = rowMap[key];
            var referenceKey = componentCalculationMethod($row) === 'percentage'
                ? componentReferenceKey($row)
                : '';

            if (referenceKey !== '' && referenceKey !== key && rowMap[referenceKey]) {
                visit(referenceKey);
            }

            path.pop();
            delete visiting[key];
            visited[key] = true;
        }

        $.each(rowMap, function (key) {
            visit(key);
        });

        return statuses;
    }

    function conversionEdgeValues(edges) {
        if ($.isArray(edges)) {
            return edges;
        }

        if (edges && $.isArray(edges.edges)) {
            return edges.edges;
        }

        return [];
    }

    function firstConversionEdgeValue(edge, keys) {
        var value = '';

        keys.some(function (key) {
            if (edge && edge[key] !== undefined && edge[key] !== null && String(edge[key]).trim() !== '') {
                value = String(edge[key]).trim();
                return true;
            }

            return false;
        });

        return value;
    }

    function normalizedConversionEdge(edge) {
        var from = firstConversionEdgeValue(edge, ['from_unit_doc_num', 'source_unit_doc_num', 'from_unit', 'source_unit', 'from', 'source', 'unit_doc_num']);
        var to = firstConversionEdgeValue(edge, ['to_unit_doc_num', 'target_unit_doc_num', 'to_unit', 'target_unit', 'to', 'target', 'equivalent_unit_doc_num']);
        var factor = firstConversionEdgeValue(edge, ['factor', 'multiplier', 'conversion_factor', 'equivalent_value', 'ratio']);
        var numerator = firstConversionEdgeValue(edge, ['numerator', 'ratio_numerator']);
        var denominator = firstConversionEdgeValue(edge, ['denominator', 'ratio_denominator']);

        if (factor === '' && numerator !== '' && denominator !== '') {
            factor = decimalDivide(numerator, denominator, componentWorkingScale);
        }

        if (from === '' || to === '' || !decimalIsPositive(factor)) {
            return null;
        }

        return {
            from: from,
            to: to,
            factor: factor
        };
    }

    function allComponentConversionEdges($panel, componentRows) {
        var edges = [];

        conversionEdgeValues(window.coreProductUnitConversionEdges || []).forEach(function (edge) {
            edges.push(edge);
        });

        var $conversionRows = componentRows && componentRows.length
            ? componentRows
            : $panel.find('.js-product-component-row, .js-product-component-readonly-row');

        $conversionRows.each(function () {
            conversionEdgeValues($(this).data('componentUnitConversionEdges') || []).forEach(function (edge) {
                edges.push(edge);
            });
        });

        return edges;
    }

    function componentConversionFactor($panel, fromUnit, toUnit, $fromRow, $toRow) {
        var from = String(fromUnit || '').trim();
        var to = String(toUnit || '').trim();

        if (from === '' || to === '') {
            return null;
        }

        if (from === to) {
            return '1';
        }

        var adjacency = {};

        var $conversionRows = $();

        if ($fromRow && $fromRow.length) {
            $conversionRows = $conversionRows.add($fromRow);
        }

        if ($toRow && $toRow.length) {
            $conversionRows = $conversionRows.add($toRow);
        }

        allComponentConversionEdges($panel, $conversionRows).forEach(function (rawEdge) {
            var edge = normalizedConversionEdge(rawEdge);
            var edgeFactor = edge ? decimalRational(edge.factor) : null;

            if (!edge || !edgeFactor) {
                return;
            }

            adjacency[edge.from] = adjacency[edge.from] || [];
            adjacency[edge.to] = adjacency[edge.to] || [];
            adjacency[edge.from].push({ unit: edge.to, factor: edgeFactor });

            var inverseFactor = rationalInverse(edgeFactor);

            if (inverseFactor !== null) {
                adjacency[edge.to].push({ unit: edge.from, factor: inverseFactor });
            }
        });

        var queue = [from];
        var factors = {};
        var processed = {};

        factors[from] = normalizedRational(BigInt(1), BigInt(1));

        while (queue.length > 0) {
            var currentUnit = queue.shift();

            if (processed[currentUnit]) {
                continue;
            }

            processed[currentUnit] = true;

            for (var edgeIndex = 0; edgeIndex < (adjacency[currentUnit] || []).length; edgeIndex += 1) {
                var edge = adjacency[currentUnit][edgeIndex];
                var factor = rationalMultiply(factors[currentUnit], edge.factor);

                if (factor === null) {
                    continue;
                }

                if (factors[edge.unit] === undefined) {
                    factors[edge.unit] = factor;
                    queue.push(edge.unit);
                    continue;
                }

                if (!rationalFactorsAreOutputEquivalent(factors[edge.unit], factor)) {
                    return null;
                }
            }
        }

        return factors[to] === undefined
            ? null
            : rationalToDecimal(factors[to], componentWorkingScale);
    }

    function setComponentCalculationState($row, text, state) {
        var $state = $row.find('.js-product-component-calculation-state').first();
        var style = state || 'muted';

        $state
            .removeClass('text-600 text-success text-danger text-warning')
            .addClass(style === 'danger' ? 'text-danger' : (style === 'success' ? 'text-success' : (style === 'warning' ? 'text-warning' : 'text-600')))
            .text(text || '');
        $row
            .attr('data-component-calculation-state', style)
            .toggleClass('table-danger', style === 'danger');
    }

    function componentGraphStatusMessage(status) {
        if (status === 'self') {
            return message('componentReferenceSelf');
        }

        if (status === 'cycle') {
            return message('componentReferenceCycle');
        }

        return message('componentReferenceMissing');
    }

    function prepareComponentDependentsForRecalculation($panel, rootKey) {
        var pending = [rootKey];
        var visited = {};

        while (pending.length > 0) {
            var referenceKey = pending.shift();

            visibleEditableComponentRows($panel).each(function () {
                var $row = $(this);
                var key = componentClientKey($row);

                if (visited[key] || componentCalculationMethod($row) !== 'percentage' || componentReferenceKey($row) !== referenceKey) {
                    return;
                }

                visited[key] = true;

                if (decimalIsPositive(componentPercentageValue($row))) {
                    setComponentInputSource($row, 'percentage');
                }

                pending.push(key);
            });
        }
    }

    function recalculateComponentGraph($panel, options) {
        if (!$panel.length || componentReadonly($panel) || $panel.data('hydratingComponents') || $panel.data('calculatingComponents')) {
            updateComponentTotals($panel);
            return true;
        }

        var settings = options || {};
        $panel.data('calculatingComponents', true);

        var rowMap = activeComponentRowMap($panel);
        var statuses = componentGraphStatuses($panel);
        var results = {};
        var clientValid = true;

        function evaluate(key) {
            if (results[key]) {
                return results[key];
            }

            var $row = rowMap[key];

            if (!$row) {
                return { valid: false };
            }

            updateComponentMethodUi($row);

            if (componentCalculationMethod($row) === 'direct') {
                var directQuantity = componentQuantityValue($row);

                setComponentCalculationState($row, message('componentDirectFormula'), 'muted');
                results[key] = {
                    valid: decimalIsPositive(directQuantity),
                    quantity: directQuantity,
                    unit: componentUnitValue($row),
                    row: $row
                };

                return results[key];
            }

            if (statuses[key]) {
                clientValid = false;
                setComponentCalculationState($row, componentGraphStatusMessage(statuses[key]), 'danger');
                results[key] = { valid: false };

                return results[key];
            }

            var referenceKey = componentReferenceKey($row);
            var referenceResult = evaluate(referenceKey);

            if (!referenceResult.valid || !decimalIsPositive(referenceResult.quantity)) {
                clientValid = false;
                setComponentCalculationState($row, message('componentReferenceWeightUnavailable'), 'danger');
                results[key] = { valid: false };

                return results[key];
            }

            var unit = componentUnitValue($row);
            var conversionFactor = componentConversionFactor($panel, referenceResult.unit, unit, referenceResult.row, $row);

            if (conversionFactor === null) {
                clientValid = false;
                setComponentCalculationState($row, message('componentIncompatibleUnits'), 'danger');
                results[key] = { valid: false };

                return results[key];
            }

            var convertedReferenceWeight = decimalMultiply(referenceResult.quantity, conversionFactor, componentWorkingScale);
            var source = componentInputSource($row);
            var weight = componentQuantityValue($row);
            var percentage = componentPercentageValue($row);

            if (source === 'weight') {
                if (!decimalIsPositive(weight) || !decimalIsPositive(convertedReferenceWeight)) {
                    clientValid = false;
                    setComponentCalculationState($row, message('componentCalculationIncomplete'), 'danger');
                    results[key] = { valid: false };

                    return results[key];
                }

                var percentageNumerator = decimalMultiply(
                    weight,
                    '100',
                    componentWorkingScale
                );
                var calculatedPercentage = decimalDivide(
                    percentageNumerator,
                    convertedReferenceWeight,
                    componentCalculationScale
                );

                if (!settings.preserveValues || !decimalIsPositive(percentage)) {
                    percentage = calculatedPercentage;
                    setNumericFieldValue($row.find('[data-component-field="percentage"]'), percentage);

                    var durablePercentageRatio = decimalDivide(percentage, '100', componentWorkingScale);
                    var durableWeight = decimalMultiply(
                        convertedReferenceWeight,
                        durablePercentageRatio,
                        componentCalculationScale
                    );

                    if (decimalIsPositive(durableWeight)) {
                        weight = durableWeight;
                        setNumericFieldValue($row.find('[data-component-field="quantity"]'), weight);
                    }
                }
            } else {
                if (!decimalIsPositive(percentage) || !decimalIsPositive(convertedReferenceWeight)) {
                    clientValid = false;
                    setComponentCalculationState($row, message('componentCalculationIncomplete'), 'danger');
                    results[key] = { valid: false };

                    return results[key];
                }

                var percentageRatio = decimalDivide(percentage, '100', componentWorkingScale);

                var calculatedWeight = decimalMultiply(convertedReferenceWeight, percentageRatio, componentCalculationScale);

                if (!settings.preserveValues || !decimalIsPositive(weight)) {
                    weight = calculatedWeight;
                    setNumericFieldValue($row.find('[data-component-field="quantity"]'), weight);
                }
            }

            if (!decimalIsPositive(weight) || !decimalIsPositive(percentage)) {
                clientValid = false;
                setComponentCalculationState($row, message('componentCalculationIncomplete'), 'danger');
                results[key] = { valid: false };

                return results[key];
            }

            var unitText = componentUnitText($row);
            var formula = interpolateMessage(message('componentFormulaTemplate'), {
                reference_weight: formatDecimal(convertedReferenceWeight) + (unitText ? ' ' + unitText : ''),
                percentage: formatDecimal(percentage),
                weight: formatDecimal(weight) + (unitText ? ' ' + unitText : '')
            });

            setComponentCalculationState($row, formula, 'success');
            results[key] = {
                valid: true,
                quantity: weight,
                unit: unit,
                row: $row
            };

            return results[key];
        }

        $.each(rowMap, function (key) {
            evaluate(key);
        });

        $panel.removeData('calculatingComponents');
        updateComponentTotals($panel);

        return clientValid;
    }

    function componentRowsForTotals($panel) {
        var rows = [];

        if (componentReadonly($panel)) {
            visibleReadonlyComponentRows($panel).each(function () {
                var $row = $(this);

                rows.push({
                    invalid: false,
                    quantity: String($row.attr('data-quantity') || ''),
                    row: $row,
                    unit: String($row.attr('data-unit-doc-num') || ''),
                    unitText: String($row.attr('data-unit-text') || '')
                });
            });

            return rows;
        }

        visibleEditableComponentRows($panel).each(function () {
            var $row = $(this);

            rows.push({
                invalid: String($row.attr('data-component-calculation-state') || '') === 'danger',
                quantity: componentQuantityValue($row),
                row: $row,
                unit: componentUnitValue($row),
                unitText: componentUnitText($row)
            });
        });

        return rows;
    }

    function updateComponentTotals($panel) {
        var $container = $panel.find('[data-components-total]').first();

        if (!$container.length) {
            return;
        }

        var groups = [];
        var incomplete = false;

        componentRowsForTotals($panel).forEach(function (row) {
            if (row.invalid || !decimalIsPositive(row.quantity) || row.unit === '') {
                incomplete = true;
                return;
            }

            var matchedGroup = null;
            var convertedQuantity = row.quantity;

            groups.some(function (group) {
                var factor = componentConversionFactor($panel, row.unit, group.unit, row.row, group.row);

                if (factor === null) {
                    return false;
                }

                var candidateQuantity = decimalMultiply(row.quantity, factor, componentCalculationScale);

                if (candidateQuantity === null) {
                    return false;
                }

                convertedQuantity = candidateQuantity;
                matchedGroup = group;

                return true;
            });

            if (!matchedGroup) {
                matchedGroup = {
                    quantity: '0',
                    row: row.row,
                    unit: row.unit,
                    unitText: row.unitText || row.unit
                };
                groups.push(matchedGroup);
                convertedQuantity = row.quantity;
            }

            matchedGroup.quantity = decimalAdd(matchedGroup.quantity, convertedQuantity, componentCalculationScale);
        });

        $container.empty();

        if (groups.length === 0) {
            if (incomplete && componentRowsForTotals($panel).length > 0) {
                $container.append($('<span class="text-warning"></span>').text(message('componentTotalUnavailable')));
            }

            return;
        }

        var totalText = groups.map(function (group) {
            return formatDecimal(group.quantity) + (group.unitText ? ' ' + group.unitText : '');
        }).join(' + ');

        $container.append(
            $('<span></span>').text(interpolateMessage(message('componentTotalTemplate'), { total: totalText }))
        );

        if (incomplete) {
            $container.append(
                $('<span class="d-block small text-warning fw-normal mt-1"></span>').text(message('componentTotalIncomplete'))
            );
        }
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

        rebuildComponentReferenceOptions($panel);
    }

    function syncComponentInputs($panel) {
        renumberComponents($panel);

        return recalculateComponentGraph($panel, { preserveValues: true });
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
        recalculateComponentGraph($panel);

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

    function destroyComponentSelect2($root) {
        if (!$.fn.select2 || !$root.length) {
            return;
        }

        $root.find('select.select2-hidden-accessible').each(function () {
            try {
                $(this).select2('destroy');
            } catch (error) {
                // The row is being discarded; a partially initialized Select2 needs no recovery.
            }
        });
    }

    function resetComponents($panel) {
        if (!$panel.length) {
            return;
        }

        destroyComponentSelect2($panel);
        $panel.find('.product-components-table tbody').empty();
        clearComponentValidation($panel);
        updateComponentEmptyState($panel);
        updateComponentTotals($panel);
    }

    function removeComponentRow($row) {
        if (!$row.length) {
            return;
        }

        var $panel = $row.closest('.product-components-panel');
        var clientKey = componentClientKey($row);
        var dependentLines = [];
        var $firstDependentReference = $();

        visibleEditableComponentRows($panel).each(function (visibleIndex) {
            var $candidate = $(this);

            if (
                componentClientKey($candidate) !== clientKey
                && componentCalculationMethod($candidate) === 'percentage'
                && componentReferenceKey($candidate) === clientKey
            ) {
                dependentLines.push(componentLineLabel($candidate, visibleIndex + 1));

                if (!$firstDependentReference.length) {
                    $firstDependentReference = $candidate.find('.js-product-component-reference').first();
                }
            }
        });

        if (dependentLines.length > 0) {
            var dependencyMessage = interpolateMessage(message('componentDeleteReferenced'), {
                lines: dependentLines.join(', ')
            });

            showComponentAlert($panel, dependencyMessage, 'danger');
            showToast('error', dependencyMessage);
            $firstDependentReference.trigger('focus');

            return;
        }

        clearComponentAlert($panel);

        var publicId = String($row.find('[data-component-field="public_id"]').val() || '').trim();

        if (publicId === '') {
            var $focusTarget = $row.next('.js-product-component-row:not(.d-none)').length
                ? $row.next('.js-product-component-row:not(.d-none)')
                : $row.prev('.js-product-component-row:not(.d-none)');

            destroyComponentSelect2($row);
            $row.remove();
            renumberComponents($panel);
            updateComponentEmptyState($panel);
            recalculateComponentGraph($panel);

            if ($focusTarget.length) {
                focusComponentRawMaterial($focusTarget);
            }

            return;
        }

        $row.addClass('d-none').attr('aria-hidden', 'true');
        $row.find('[data-component-field="_delete"]').val('1');
        $row.find(':input')
            .not('[data-component-field="client_key"], [data-component-field="public_id"], [data-component-field="_delete"]')
            .prop('disabled', true);
        clearComponentValidation($panel);
        renumberComponents($panel);
        updateComponentEmptyState($panel);
        recalculateComponentGraph($panel);
    }

    function renderInitialComponents($panel, initialRows) {
        var $tbody = $panel.find('.product-components-table tbody');
        var normalizedRows = normalizedInitialComponentRows($.isArray(initialRows) ? initialRows : []);
        var rowMap = {};
        var readonlyEdges = [];

        destroyComponentSelect2($panel);
        $tbody.empty();
        $panel.data('hydratingComponents', true);

        normalizedRows.forEach(function (row, index) {
            row._line_number = index + 1;
            rowMap[row.client_key] = row;
            conversionEdgeValues(row.unit_conversion_edges || []).forEach(function (edge) {
                readonlyEdges.push(edge);
            });
        });
        $panel.data('readonlyComponentConversionEdges', readonlyEdges);

        normalizedRows.forEach(function (row, index) {
            var $row = componentReadonly($panel)
                ? readonlyComponentRow($panel, row, rowMap)
                : editableComponentRow(index, row);

            $tbody.append($row);

            if (!componentReadonly($panel)) {
                initSelect2($row[0]);
                initComponentUnitSelect($row);

                if (componentActive($row) && !$row.data('componentMetadataComplete') && String(row.component_product_doc_num || '').trim() !== '') {
                    loadComponentUnitOptions($row, row.unit_doc_num || '');
                }
            }
        });

        if (!componentReadonly($panel)) {
            renumberComponents($panel);
        }

        $panel.removeData('hydratingComponents');
        updateComponentEmptyState($panel);

        if (componentReadonly($panel)) {
            updateComponentTotals($panel);
        } else {
            recalculateComponentGraph($panel, { preserveValues: true });
        }
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
            var $componentPanel = $form.find('.product-components-panel').first();

            clearValidation($form);

            if (!syncComponentInputs($componentPanel)) {
                showComponentAlert($componentPanel, message('componentClientValidationFailed'), 'danger');

                var componentsTab = document.getElementById('product-components-tab');

                if (componentsTab && window.bootstrap && window.bootstrap.Tab) {
                    window.bootstrap.Tab.getOrCreateInstance(componentsTab).show();
                }

                return;
            }

            normalizeNumericForm($form[0]);

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

                var responseData = response && response.data ? response.data : {};

                if ($.isArray(responseData.unit_conversion_edges)) {
                    window.coreProductUnitConversionEdges = responseData.unit_conversion_edges;
                }

                if ($.isArray(responseData.components) && $componentPanel.length) {
                    renderInitialComponents($componentPanel, responseData.components);
                }

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
            rebuildComponentReferenceOptions($field.closest('.product-components-panel'));
            recalculateComponentGraph($field.closest('.product-components-panel'));
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
                $field.removeData('componentUnitConversionEdges');
                $row.removeData('componentUnitConversionEdges');
                setComponentUnitOptions($row, [], '');
            }

            clearComponentFieldValidation($field);
            rebuildComponentReferenceOptions($row.closest('.product-components-panel'));
            prepareComponentDependentsForRecalculation($row.closest('.product-components-panel'), componentClientKey($row));
            recalculateComponentGraph($row.closest('.product-components-panel'));
        })
        .off('change.coreProductsComponentsMethod', '.js-product-component-calculation-method')
        .on('change.coreProductsComponentsMethod', '.js-product-component-calculation-method', function () {
            var $row = $(this).closest('.js-product-component-row');
            var $panel = $row.closest('.product-components-panel');

            if ($panel.data('hydratingComponents') || $panel.data('calculatingComponents')) {
                return;
            }

            if (componentCalculationMethod($row) === 'direct') {
                $row.find('[data-component-field="reference_component_key"]').val('').trigger('change.select2');
                $row.find('[data-component-field="percentage"]').val('');
                setComponentInputSource($row, 'weight');
            } else {
                setComponentInputSource($row, 'weight');
            }

            updateComponentMethodUi($row);
            rebuildComponentReferenceOptions($panel);
            prepareComponentDependentsForRecalculation($panel, componentClientKey($row));
            recalculateComponentGraph($panel);
        })
        .off('change.coreProductsComponentsReference', '.js-product-component-reference')
        .on('change.coreProductsComponentsReference', '.js-product-component-reference', function () {
            var $row = $(this).closest('.js-product-component-row');
            var $panel = $row.closest('.product-components-panel');

            if ($panel.data('hydratingComponents') || $panel.data('calculatingComponents')) {
                return;
            }

            setComponentInputSource($row, decimalIsPositive(componentPercentageValue($row)) ? 'percentage' : 'weight');
            clearComponentFieldValidation($(this));
            rebuildComponentReferenceOptions($panel);
            prepareComponentDependentsForRecalculation($panel, componentClientKey($row));
            recalculateComponentGraph($panel);
        })
        .off('input.coreProductsComponentsWeight', '.js-product-component-quantity')
        .on('input.coreProductsComponentsWeight', '.js-product-component-quantity', function () {
            var $row = $(this).closest('.js-product-component-row');
            var $panel = $row.closest('.product-components-panel');

            if ($panel.data('hydratingComponents') || $panel.data('calculatingComponents')) {
                return;
            }

            if (componentCalculationMethod($row) === 'percentage') {
                setComponentInputSource($row, 'weight');
            }

            prepareComponentDependentsForRecalculation($panel, componentClientKey($row));
            recalculateComponentGraph($panel);
        })
        .off('input.coreProductsComponentsPercentage', '.js-product-component-percentage')
        .on('input.coreProductsComponentsPercentage', '.js-product-component-percentage', function () {
            var $row = $(this).closest('.js-product-component-row');
            var $panel = $row.closest('.product-components-panel');

            if ($panel.data('hydratingComponents') || $panel.data('calculatingComponents')) {
                return;
            }

            setComponentInputSource($row, 'percentage');
            prepareComponentDependentsForRecalculation($panel, componentClientKey($row));
            recalculateComponentGraph($panel);
        })
        .off('change.coreProductsComponentsUnit', '.js-product-component-unit')
        .on('change.coreProductsComponentsUnit', '.js-product-component-unit', function () {
            var $row = $(this).closest('.js-product-component-row');
            var $panel = $row.closest('.product-components-panel');

            if ($panel.data('hydratingComponents') || $panel.data('calculatingComponents')) {
                return;
            }

            if (componentCalculationMethod($row) === 'percentage' && decimalIsPositive(componentPercentageValue($row))) {
                setComponentInputSource($row, 'percentage');
            }

            prepareComponentDependentsForRecalculation($panel, componentClientKey($row));
            recalculateComponentGraph($panel);
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
