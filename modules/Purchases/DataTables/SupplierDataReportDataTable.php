<?php

namespace Modules\Purchases\DataTables;

use Modules\Core\DataTables\BusinessPartnerDataReportDataTable;
use Modules\Core\Services\DataTableSearchService;
use Modules\Purchases\Services\Reports\SupplierDataReport;

class SupplierDataReportDataTable extends BusinessPartnerDataReportDataTable
{
    public function __construct(SupplierDataReport $report, DataTableSearchService $searchService)
    {
        parent::__construct($report, $searchService);
    }
}
