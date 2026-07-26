<?php

namespace Modules\Purchases\Http\Controllers;

use Modules\Core\Http\Controllers\BusinessPartnerDataReportController;
use Modules\Core\Services\BreadcrumbService;
use Modules\Purchases\DataTables\SupplierDataReportDataTable;
use Modules\Purchases\Services\Reports\SupplierDataReport;

class SupplierDataReportController extends BusinessPartnerDataReportController
{
    public function __construct(
        SupplierDataReport $report,
        SupplierDataReportDataTable $dataTable,
        BreadcrumbService $breadcrumbs,
    ) {
        parent::__construct($report, $dataTable, $breadcrumbs);
    }
}
