(function ($, window) {
    'use strict';

    const allowedFilterNames = [
        'doc_num',
        'name',
        'phone',
        'account_group_doc_num',
        'account_doc_num',
        'country_doc_num',
        'governorate_doc_num',
        'city_doc_num',
        'area_doc_num',
        'data_completeness',
        'status',
        'created_from',
        'created_to'
    ];
    const protectedColumns = [0, 1, 16];
    const defaultOrder = [[0, 'desc']];
    const reportTableDom = '<"row g-3 align-items-center px-3 py-3"<"col-12 col-xl-4"l><"col-12 col-xl-4 d-flex justify-content-xl-center"B><"col-12 col-xl-4"f>><"erp-datatable-scroll"rt><"row g-0 px-3 py-2 align-items-center border-top"<"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-start"i><"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-end"p>>';
    const ReportUI = window.AppReportUI;

    function setPlainTextTitle(cell) {
        const text = String(cell.textContent || '').trim();

        if (text === '') {
            cell.removeAttribute('title');
            return;
        }

        cell.setAttribute('title', text);
    }

    function textColumn(data, priority, className, width) {
        const column = {
            data: data,
            name: data,
            orderable: data !== 'credit_limits',
            searchable: true,
            className: className || 'align-middle',
            responsivePriority: priority,
            render: $.fn.dataTable.render.text(),
            createdCell: setPlainTextTitle
        };

        if (width) {
            column.width = width;
        }

        return column;
    }

    function initTable($page) {
        const $table = $page.find('table.business-partner-data-report-table, table[id$="-data-report-table"]').first();

        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        window.dataTableTranslations = $page.data('datatable-translations') || window.dataTableTranslations || {};

        const options = window.AppDataTables && window.AppDataTables.options
            ? window.AppDataTables.options
            : function (config) { return config; };
        const table = $table.DataTable(options({
            autoWidth: false,
            processing: true,
            serverSide: true,
            stateSave: true,
            stateLoadParams: function (settings, data) { ReportUI.protectStateColumns(data, protectedColumns); },
            stateSaveParams: function (settings, data) { ReportUI.protectStateColumns(data, protectedColumns); },
            ajax: {
                url: $table.data('url'),
                data: function (data) {
                    $.extend(data, ReportUI.filterData({ allowedFilterNames: allowedFilterNames }));
                }
            },
            dom: reportTableDom,
            order: defaultOrder,
            responsive: { details: { type: 'inline', target: 1 } },
            columns: [
                textColumn('doc_num', 1, 'dt-code all align-middle white-space-nowrap'),
                textColumn('name', 2, 'dt-text dt-ellipsis all dtr-control align-middle', '15rem'),
                textColumn('account_group', 4, 'dt-text dt-ellipsis align-middle', '13rem'),
                textColumn('account', 5, 'dt-text dt-ellipsis align-middle', '13rem'),
                textColumn('phone', 6, 'align-middle white-space-nowrap'),
                textColumn('mobile', 7, 'align-middle white-space-nowrap'),
                textColumn('email', 20, 'dt-text dt-ellipsis align-middle', '14rem'),
                textColumn('contact_person', 30, 'dt-text dt-ellipsis align-middle', '12rem'),
                textColumn('address', 80, 'dt-text dt-ellipsis align-middle', '16rem'),
                textColumn('country', 40),
                textColumn('governorate', 50),
                textColumn('city', 60),
                textColumn('area', 70),
                textColumn('tax_number', 9, 'align-middle white-space-nowrap'),
                textColumn('commercial_register', 90, 'align-middle white-space-nowrap'),
                textColumn('credit_limits', 10, 'dt-text dt-ellipsis align-middle', '15rem'),
                textColumn('status', 3, 'align-middle white-space-nowrap'),
                textColumn('created_at', 100, 'align-middle white-space-nowrap')
            ]
        }));

        table.on('draw.dt.businessPartnerReport column-visibility.dt.businessPartnerReport responsive-resize.dt.businessPartnerReport', function () {
            if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                window.AppDataTables.showColumns(table, protectedColumns);
            }
        });

        ReportUI.bindFilters(table, $table, {
            allowedFilterNames: allowedFilterNames,
            defaultOrder: defaultOrder,
            resetTableState: true
        });
    }

    $(function () {
        $('.business-partner-data-report').each(function () {
            const $page = $(this);
            ReportUI.init($page.get(0));
            initTable($page);
        });
    });
})(jQuery, window);
