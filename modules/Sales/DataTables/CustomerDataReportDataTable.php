<?php

namespace Modules\Sales\DataTables;

use Modules\Core\DataTables\BusinessPartnerDataReportDataTable;
use Modules\Core\Services\DataTableSearchService;
use Modules\Sales\Services\Reports\CustomerDataReport;

class CustomerDataReportDataTable extends BusinessPartnerDataReportDataTable
{
    public function __construct(CustomerDataReport $report, DataTableSearchService $searchService)
    {
        parent::__construct($report, $searchService);
    }
}
