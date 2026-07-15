(function ($, window) {
    'use strict';

    const messages = window.reportMessages || {};
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    const responsiveControlTarget = 0;
    const protectedColumns = [0, 4, -1];
    const defaultOrder = [[4, 'asc'], [8, 'desc'], [7, 'desc']];
    const reportTableDom = '<"row g-3 align-items-center px-3 py-3"<"col-12 col-xl-4"l><"col-12 col-xl-4 d-flex justify-content-xl-center"B><"col-12 col-xl-4"f>><"erp-datatable-scroll"rt><"row g-0 px-3 py-2 align-items-center border-top"<"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-start"i><"col-sm-12 col-md-6 d-flex justify-content-center justify-content-md-end"p>>';
    const ReportUI = window.AppReportUI;

    function filterData() {
        return ReportUI.filterData();
    }

    function confirmDialog() {
        if (!window.Swal) {
            return $.Deferred().resolve({ isConfirmed: false }).promise();
        }

        return Swal.fire({
            icon: 'warning',
            title: messages.forceLogoutConfirmTitle,
            text: messages.forceLogoutConfirmText,
            showCloseButton: true,
            showCancelButton: true,
            focusCancel: true,
            allowEscapeKey: true,
            confirmButtonText: messages.forceLogoutConfirmYes,
            cancelButtonText: messages.no || '',
            confirmButtonColor: '#d33',
            cancelButtonColor: '#748194'
        });
    }

    function initTable() {
        const $table = $('#auth-sessions-table');
        if ($table.length === 0 || !$.fn.DataTable || $.fn.DataTable.isDataTable($table[0])) { return; }

        const options = window.AppDataTables && window.AppDataTables.options ? window.AppDataTables.options : function (config) { return config; };
        const table = $table.DataTable(options({
            autoWidth: false,
            processing: true,
            serverSide: true,
            stateSave: true,
            stateLoadParams: function (settings, data) { ReportUI.protectStateColumns(data, protectedColumns); },
            stateSaveParams: function (settings, data) { ReportUI.protectStateColumns(data, protectedColumns); },
            ajax: { url: $table.data('url'), data: function (data) { $.extend(data, filterData()); } },
            dom: reportTableDom,
            order: defaultOrder,
            responsive: { details: { type: 'inline', target: responsiveControlTarget } },
            columns: [
                { data: 'user_label', name: 'user_label', orderable: true, searchable: true, className: 'dt-user-cell dt-text dt-ellipsis all dtr-control align-middle', responsivePriority: 1, width: '11rem' },
                { data: 'branch_label', name: 'branch_label', orderable: true, searchable: true, className: 'dt-text dt-ellipsis align-middle', responsivePriority: 12 },
                { data: 'financial_period_label', name: 'financial_period_label', orderable: true, searchable: true, className: 'dt-text dt-ellipsis align-middle', responsivePriority: 13 },
                { data: 'account_status_label', name: 'account_status_label', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 4 },
                { data: 'presence_status_label', name: 'presence_status_label', orderable: true, searchable: true, className: 'all align-middle white-space-nowrap', responsivePriority: 2 },
                { data: 'device_label', name: 'device_label', orderable: true, searchable: true, className: 'dt-text dt-ellipsis align-middle white-space-nowrap', responsivePriority: 20 },
                { data: 'ip_address', name: 'ip_address', orderable: true, searchable: true, className: 'align-middle white-space-nowrap', responsivePriority: 50 },
                { data: 'login_at', name: 'login_at', orderable: true, searchable: true, className: 'dt-date align-middle white-space-nowrap', responsivePriority: 30 },
                { data: 'last_seen_at', name: 'last_seen_at', orderable: true, searchable: true, className: 'dt-date align-middle white-space-nowrap', responsivePriority: 30 },
                { data: 'duration_label', name: 'duration_label', orderable: true, searchable: false, className: 'align-middle white-space-nowrap', responsivePriority: 40 },
                { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'dt-actions no-colvis all align-middle white-space-nowrap', responsivePriority: 3 }
            ],
            columnDefs: [
                { className: 'dt-user-cell dt-text dt-ellipsis all dtr-control', responsivePriority: 1, targets: 0, width: '11rem' },
                { responsivePriority: 12, targets: 1 },
                { responsivePriority: 13, targets: 2 },
                { responsivePriority: 4, targets: 3 },
                { className: 'all', responsivePriority: 2, targets: 4 },
                { responsivePriority: 20, targets: 5 },
                { responsivePriority: 50, targets: 6 },
                { responsivePriority: 30, targets: [7, 8] },
                { orderable: true, responsivePriority: 40, searchable: false, targets: 9 },
                { className: 'dt-actions no-colvis all', orderable: false, responsivePriority: 3, searchable: false, targets: -1 }
            ]
        }));

        table.on('draw.dt.authSessionsResponsive column-visibility.dt.authSessionsResponsive responsive-resize.dt.authSessionsResponsive', function () {
            if (window.AppDataTables && typeof window.AppDataTables.showColumns === 'function') {
                window.AppDataTables.showColumns(table, protectedColumns);
            }
        });

        ReportUI.bindFilters(table, $table, { defaultOrder: defaultOrder });

        $(document).off('click.forceLogoutSession', '.js-force-logout-session').on('click.forceLogoutSession', '.js-force-logout-session', function () {
            const url = $(this).data('force-logout-url');
            confirmDialog().then(function (result) {
                if (!result.isConfirmed) { return; }

                $.ajax({
                    url: url,
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' }
                }).done(function (response) {
                    if (window.Swal && response && response.message) {
                        Swal.fire({ icon: 'success', text: response.message, timer: 1400, showConfirmButton: false });
                    }

                    table.ajax.reload(null, false);
                }).fail(function (response) {
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'error',
                            text: response.responseJSON && response.responseJSON.message ? response.responseJSON.message : messages.unexpectedError,
                            confirmButtonText: messages.confirm || messages.ok || '',
                            showCloseButton: true,
                            allowEscapeKey: true,
                            heightAuto: false
                        });
                    }
                });
            });
        });
    }

    $(function () {
        ReportUI.init(document);
        initTable();
        ReportUI.initDetails({ sectionIdPrefix: 'auth-session-details-section' });
    });
})(jQuery, window);
