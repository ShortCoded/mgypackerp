<?php

namespace Modules\Sales\Http\Controllers;

use Modules\Core\Http\Controllers\BusinessPartnerDataReportController;
use Modules\Core\Services\BreadcrumbService;
use Modules\Sales\DataTables\CustomerDataReportDataTable;
use Modules\Sales\Services\Reports\CustomerDataReport;

class CustomerDataReportController extends BusinessPartnerDataReportController
{
    public function __construct(
        CustomerDataReport $report,
        CustomerDataReportDataTable $dataTable,
        BreadcrumbService $breadcrumbs,
    ) {
        parent::__construct($report, $dataTable, $breadcrumbs);
    }
}
