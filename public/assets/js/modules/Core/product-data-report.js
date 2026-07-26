(function ($, window) {
    'use strict';

    const responsiveControlTarget = 1;
    const protectedColumns = [0, 1, 2, 14];
    const detailColumns = [16, 17, 18, 19, 20, 21, 22];
    const defaultOrder = [[0, 'desc']];
    const allowedFilterNames = [
        'result_mode',
        'item_scope',
        'record_state',
        'status',
        'product_doc_num',
        'doc_num',
        'name',
        'barcode',
        'item_classification',
        'item_category_doc_num',
        'item_group_doc_num',
        'item_model_doc_num',
        'item_size_doc_num',
        'item_color_doc_num',
        'item_decal_doc_num',
        'item_unit_doc_num',
        'item_origin_country_doc_num',
        'components_state',
        'component_product_doc_num',
        'component_unit_doc_num',
        'created_from',
        'created_to'
    ];
    const reportTableDom = '<"row g-3 align-items-center px-3 py-3"<"col-12 col-xl-4"l><"col-12 col-xl-4 d-flex justify-content-xl-center"B><"col-12 col-xl-4"f>><"erp-datatable-scroll"rt><"row g-0 px-3 py-2 align-items-center border-top"<"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-start"i><"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-end"p>>';
    const ReportUI = window.AppReportUI;

    function filterData() {
        return ReportUI.filterData({ allowedFilterNames: allowedFilterNames });
    }

    function currentMode() {
        return String($('[name="result_mode"]').val() || 'summary') === 'detailed' ? 'detailed' : 'summary';
    }

    function currentItemScope() {
        return String($('#product-data-item-scope').val() || 'all');
    }

    function setPlainTextTitle(cell) {
        const text = String(cell.textContent || '').trim();

        if (text === '') {
            cell.removeAttribute('title');
            return;
        }

        cell.setAttribute('title', text);
    }

    function syncClassificationOptions() {
        const scope = currentItemScope();
        const $classification = $('#product-data-classification');
        const $selected = $classification.find('option:selected');

        $classification.find('option[value!=""]').each(function () {
            const optionScope = String($(this).data('item-scope') || '');
            const isCompatible = scope === 'all' || optionScope === scope;

            $(this).prop('disabled', !isCompatible).prop('hidden', !isCompatible);
        });

        if ($selected.length > 0 && $selected.prop('disabled')) {
            $classification.val(null);
        }

        $classification.trigger('change.select2');
    }

    function rememberSelectedItemScope(event) {
        const item = event.params && event.params.data ? event.params.data : null;

        if (!item || item.id === undefined || !item.item_scope) { return; }

        $('#product-data-product').find('option').filter(function () {
            return String(this.value) === String(item.id);
        }).attr('data-item-scope', String(item.item_scope));
    }

    function syncSelectedItem() {
        const scope = currentItemScope();
        const $item = $('#product-data-product');

        if (scope === 'all' || !$item.val()) { return; }

        const selectedData = $item.select2('data')[0] || {};
        const selectedScope = String(selectedData.item_scope || $item.find('option:selected').attr('data-item-scope') || '');

        if (selectedScope === scope) { return; }

        $item.val(null).find('option[value!=""]').remove();
        $item.trigger('change.select2');
    }

    function orderUsesDetailColumns(order) {
        if (!$.isArray(order)) { return false; }

        return order.some(function (item) {
            return $.isArray(item) && detailColumns.indexOf(Number(item[0])) !== -1;
        });
    }

    function syncModeColumns(table) {
        if (!table) { return; }

        const detailed = currentMode() === 'detailed';

        table.columns(detailColumns).visible(detailed, false);

        if (!detailed && orderUsesDetailColumns(table.order())) {
            table.order(defaultOrder);
        }

        if (table.responsive && typeof table.responsive.recalc === 'function') {
            table.columns.adjust();
            table.responsive.recalc();
        } else {
            table.columns.adjust();
        }
    }

    function initTable() {
        const $table = $('#product-data-report-table');
        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) { return; }

        const options = window.AppDataTables && window.AppDataTables.options ? window.AppDataTables.options : function (config) { return config; };
        const table = $table.DataTable(options({
            autoWidth: false,
            processing: true,
            serverSide: true,
            stateSave: true,
            stateLoadParams: function (settings, data) {
                if (orderUsesDetailColumns(data.order)) {
                    data.order = defaultOrder;
                }
                ReportUI.protectStateColumns(data, protectedColumns);
            },
            stateSaveParams: function (settings, data) { ReportUI.protectStateColumns(data, protectedColumns); },
            ajax: { url: $table.data('url'), data: function (data) { $.extend(data, filterData()); } },
            dom: reportTableDom,
            order: defaultOrder,
            responsive: { details: { type: 'inline', target: responsiveControlTarget } },
            columns: [
                { data: 'doc_num', name: 'doc_num', orderable: true, searchable: true, className: 'dt-code all align-middle white-space-nowrap', responsivePriority: 1, createdCell: setPlainTextTitle },
                { data: 'name', name: 'name', orderable: true, searchable: true, className: 'dt-text dt-ellipsis all dtr-control align-middle', responsivePriority: 2, width: '14rem', createdCell: setPlainTextTitle },
                { data: 'item_classification', name: 'item_classification', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 3 },
                { data: 'barcode', name: 'barcode', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 20 },
                { data: 'unit', name: 'unit', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 30 },
                { data: 'category', name: 'category', orderable: true, searchable: true, className: 'align-middle', responsivePriority: 40 },
                { data: 'group', name: 'group', orderable: true, searchable: true, className: 'align-middle', responsivePriority: 50 },
                { data: 'model', name: 'model', orderable: true, searchable: true, className: 'align-middle', responsivePriority: 60 },
                { data: 'size', name: 'size', orderable: true, searchable: true, className: 'align-middle', responsivePriority: 70 },
                { data: 'color', name: 'color', orderable: true, searchable: true, className: 'align-middle', responsivePriority: 80 },
                { data: 'decal', name: 'decal', orderable: true, searchable: true, className: 'align-middle', responsivePriority: 90 },
                { data: 'origin_country', name: 'origin_country', orderable: true, searchable: true, className: 'align-middle', responsivePriority: 100 },
                { data: 'reorder_point', name: 'reorder_point', orderable: true, searchable: true, className: 'dt-number align-middle text-end white-space-nowrap', responsivePriority: 110 },
                { data: 'equivalent', name: 'equivalent', orderable: false, searchable: false, className: 'align-middle white-space-nowrap', responsivePriority: 120 },
                { data: 'status', name: 'status', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 4 },
                { data: 'components_count', name: 'components_count', orderable: true, searchable: false, className: 'dt-number align-middle text-end white-space-nowrap', responsivePriority: 5 },
                { data: 'component_doc_num', name: 'component_doc_num', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 6, createdCell: setPlainTextTitle },
                { data: 'component_name', name: 'component_name', orderable: true, searchable: true, className: 'dt-text dt-ellipsis align-middle', responsivePriority: 7, width: '13rem', createdCell: setPlainTextTitle },
                { data: 'component_classification', name: 'component_classification', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 8 },
                { data: 'component_quantity', name: 'component_quantity', orderable: true, searchable: false, className: 'dt-number align-middle text-end white-space-nowrap', responsivePriority: 9 },
                { data: 'component_unit', name: 'component_unit', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 10 },
                { data: 'component_equivalent', name: 'component_equivalent', orderable: false, searchable: false, className: 'align-middle white-space-nowrap', responsivePriority: 11 },
                { data: 'component_notes', name: 'component_notes', orderable: false, searchable: true, className: 'dt-text dt-ellipsis align-middle', responsivePriority: 130, createdCell: setPlainTextTitle },
                { data: 'created_at', name: 'created_at', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 140 }
            ],
            columnDefs: [
                { visible: false, targets: detailColumns }
            ],
            initComplete: function () {
                syncModeColumns(table);
            }
        }));

        table.on('draw.dt.productDataReport column-visibility.dt.productDataReport responsive-resize.dt.productDataReport', function () {
            if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                window.AppDataTables.showColumns(table, protectedColumns);
            }
            syncModeColumns(table);
        });

        $('#product-data-product')
            .off('select2:select.productDataReportItemScope')
            .on('select2:select.productDataReportItemScope', rememberSelectedItemScope);

        $(document)
            .off('change.productDataReportMode', '[name="result_mode"]')
            .on('change.productDataReportMode', '[name="result_mode"]', function () {
                syncModeColumns(table);
            })
            .off('change.productDataReportItemScope', '#product-data-item-scope')
            .on('change.productDataReportItemScope', '#product-data-item-scope', function () {
                syncClassificationOptions();
                syncSelectedItem();
            })
            .off('click.productDataReportMode', '.js-report-reset')
            .on('click.productDataReportMode', '.js-report-reset', function () {
                window.setTimeout(function () {
                    syncModeColumns(table);
                    syncClassificationOptions();
                    syncSelectedItem();
                }, 0);
            });

        syncClassificationOptions();
        syncSelectedItem();

        ReportUI.bindFilters(table, $table, {
            allowedFilterNames: allowedFilterNames,
            defaultOrder: defaultOrder,
            resetTableState: true
        });
    }

    $(function () {
        ReportUI.init(document);
        initTable();
    });
})(jQuery, window);
