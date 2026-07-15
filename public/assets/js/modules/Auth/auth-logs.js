(function ($, window) {
    'use strict';

    const responsiveControlTarget = 1;
    const protectedColumns = [0, 1, 3, -1];
    const defaultOrder = [[0, 'desc']];
    const allowedFilterNames = ['date_from', 'date_to', 'user', 'event', 'status', 'ip', 'failure_reason'];
    const removedFilterNames = ['branch', 'branch_id', 'branch_doc_num', 'branch_name', 'financial_period', 'financial_period_id', 'financial_period_doc_num', 'financial_period_name', 'period', 'country', 'city', 'guard', 'browser', 'browser_name', 'platform', 'os_name', 'device', 'device_type', 'remember_me'];
    const reportTableDom = '<"row g-3 align-items-center px-3 py-3"<"col-12 col-xl-4"l><"col-12 col-xl-4 d-flex justify-content-xl-center"B><"col-12 col-xl-4"f>><"erp-datatable-scroll"rt><"row g-0 px-3 py-2 align-items-center border-top"<"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-start"i><"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-end"p>>';
    const ReportUI = window.AppReportUI;

    function filterData() {
        return ReportUI.filterData({ allowedFilterNames: allowedFilterNames });
    }

    function initTable() {
        const $table = $('#auth-logs-table');
        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) { return; }

        const options = window.AppDataTables && window.AppDataTables.options ? window.AppDataTables.options : function (config) { return config; };
        const table = $table.DataTable(options({
            autoWidth: false,
            processing: true,
            serverSide: true,
            stateSave: true,
            stateLoadParams: function (settings, data) { ReportUI.sanitizeSavedState(data, removedFilterNames); ReportUI.protectStateColumns(data, protectedColumns); },
            stateSaveParams: function (settings, data) { ReportUI.protectStateColumns(data, protectedColumns); },
            ajax: { url: $table.data('url'), data: function (data) { $.extend(data, filterData()); } },
            dom: reportTableDom,
            order: defaultOrder,
            responsive: { details: { type: 'inline', target: responsiveControlTarget } },
            columns: [
                { data: 'created_at', name: 'created_at', orderable: true, searchable: true, className: 'dt-auth-log-date dt-date all align-middle white-space-nowrap', responsivePriority: 2, width: '10rem' },
                { data: 'user_label', name: 'user_label', orderable: true, searchable: true, className: 'dt-auth-log-user dt-text dt-ellipsis all dtr-control align-middle', responsivePriority: 1, width: '11rem' },
                { data: 'event_label', name: 'event_label', orderable: true, searchable: true, className: 'dt-text dt-ellipsis align-middle', responsivePriority: 5 },
                { data: 'status_label', name: 'status_label', orderable: true, searchable: true, className: 'all align-middle white-space-nowrap', responsivePriority: 3 },
                { data: 'ip_label', name: 'ip_label', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 20 },
                { data: 'location_label', name: 'location_label', orderable: true, searchable: true, className: 'dt-auth-log-location dt-text align-middle', responsivePriority: 30 },
                { data: 'device_label', name: 'device_label', orderable: true, searchable: true, className: 'dt-auth-log-device align-middle', responsivePriority: 40, width: '13rem' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'dt-actions no-colvis all align-middle white-space-nowrap', responsivePriority: 4 }
            ],
            columnDefs: [
                { className: 'dt-auth-log-date dt-date all', responsivePriority: 2, targets: 0, width: '10rem' },
                { className: 'dt-auth-log-user dt-text dt-ellipsis all dtr-control', responsivePriority: 1, targets: 1, width: '11rem' },
                { responsivePriority: 5, targets: 2 },
                { className: 'all', responsivePriority: 3, targets: 3 },
                { orderable: true, responsivePriority: 20, targets: 4 },
                { className: 'dt-auth-log-location dt-text', orderable: true, responsivePriority: 30, targets: 5 },
                { className: 'dt-auth-log-device', responsivePriority: 40, targets: 6, width: '13rem' },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 4, searchable: false, targets: -1 }
            ]
        }));

        table.on('draw.dt.authLogsResponsive column-visibility.dt.authLogsResponsive responsive-resize.dt.authLogsResponsive', function () {
            if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                window.AppDataTables.showColumns(table, protectedColumns);
            }
        });

        ReportUI.bindFilters(table, $table, {
            allowedFilterNames: allowedFilterNames,
            removedFilterNames: removedFilterNames,
            defaultOrder: defaultOrder,
            resetTableState: true
        });
    }

    $(function () {
        ReportUI.init(document);
        initTable();
        ReportUI.initDetails({ sectionIdPrefix: 'auth-log-details-section' });
    });
})(jQuery, window);
