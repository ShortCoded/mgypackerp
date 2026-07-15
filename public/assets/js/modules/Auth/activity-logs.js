(function ($, window) {
    'use strict';

    const responsiveControlTarget = 1;
    const protectedColumns = [0, 1, 4, -1];
    const defaultOrder = [[0, 'desc']];
    const allowedFilterNames = ['date_from', 'date_to', 'causer', 'area', 'action', 'status'];
    const removedFilterNames = ['event', 'method', 'module', 'subject_type', 'record', 'changes', 'technical_details', 'company', 'ip'];
    const reportTableDom = '<"row g-3 align-items-center px-3 py-3"<"col-12 col-xl-4"l><"col-12 col-xl-4 d-flex justify-content-xl-center"B><"col-12 col-xl-4"f>><"erp-datatable-scroll"rt><"row g-0 px-3 py-2 align-items-center border-top"<"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-start"i><"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-end"p>>';
    const ReportUI = window.AppReportUI;

    function filterData() {
        return ReportUI.filterData({ allowedFilterNames: allowedFilterNames });
    }

    function initTable() {
        const $table = $('#activity-logs-table');
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
                { data: 'created_at', name: 'created_at', orderable: true, searchable: true, className: 'dt-activity-log-date dt-date all align-middle white-space-nowrap', responsivePriority: 2, width: '10rem' },
                { data: 'causer_label', name: 'causer_label', orderable: true, searchable: true, className: 'dt-activity-log-user dt-text all dtr-control align-middle', responsivePriority: 1, width: '12rem' },
                { data: 'area_label', name: 'area_label', orderable: true, searchable: true, className: 'dt-activity-log-area align-middle white-space-nowrap', responsivePriority: 8, width: '8rem' },
                { data: 'activity_label', name: 'activity_label', orderable: true, searchable: true, className: 'dt-activity-log-activity dt-text align-middle', responsivePriority: 5, width: '13rem' },
                { data: 'result_label', name: 'result_label', orderable: true, searchable: true, className: 'dt-activity-log-result all align-middle white-space-nowrap', responsivePriority: 3, width: '7rem' },
                { data: 'record_label', name: 'record_label', orderable: true, searchable: true, className: 'dt-activity-log-record dt-text align-middle', responsivePriority: 20, width: '15rem' },
                { data: 'summary_label', name: 'summary_label', orderable: false, searchable: true, className: 'dt-activity-log-summary dt-text align-middle', responsivePriority: 30, width: '18rem' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'dt-actions no-colvis all align-middle white-space-nowrap text-center', responsivePriority: 4, width: '4.5rem' }
            ],
            columnDefs: [
                { className: 'dt-activity-log-date dt-date all', responsivePriority: 2, targets: 0, width: '10rem' },
                { className: 'dt-activity-log-user dt-text all dtr-control', responsivePriority: 1, targets: 1, width: '12rem' },
                { className: 'dt-activity-log-area', responsivePriority: 8, targets: 2, width: '8rem' },
                { className: 'dt-activity-log-activity dt-text', responsivePriority: 5, targets: 3, width: '13rem' },
                { className: 'dt-activity-log-result all', responsivePriority: 3, targets: 4, width: '7rem' },
                { className: 'dt-activity-log-record dt-text', orderable: true, responsivePriority: 20, targets: 5, width: '15rem' },
                { className: 'dt-activity-log-summary dt-text', orderable: false, responsivePriority: 30, targets: 6, width: '18rem' },
                { className: 'dt-actions no-colvis all text-center', orderable: false, responsivePriority: 4, searchable: false, targets: -1, width: '4.5rem' }
            ]
        }));

        table.on('draw.dt.activityLogsResponsive column-visibility.dt.activityLogsResponsive responsive-resize.dt.activityLogsResponsive', function () {
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
        ReportUI.initDetails({ sectionIdPrefix: 'activity-details-section' });
    });
})(jQuery, window);
