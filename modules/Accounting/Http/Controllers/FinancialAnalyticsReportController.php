<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Exports\CostingReportExport;
use Modules\Accounting\Services\FinancialAnalyticsReportService;
use Modules\Core\Models\Company;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class FinancialAnalyticsReportController extends Controller
{
    public function __construct(
        private readonly FinancialAnalyticsReportService $reports,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function index(Request $request): View
    {
        $type = (string) $request->route('financial_analytics_type');
        $filters = $this->reports->filters($request, $type);

        return view('modules.accounting.reports.financial-analytics', [
            'report' => $this->reports->report($filters),
            'filters' => $filters,
            'filterOptions' => $this->reports->filterOptions((int) $filters['company_id']),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($request->route()?->getName() ?? ''),
        ]);
    }

    public function export(Request $request, ReportPdfService $pdf, CompanyPrintIdentityService $printIdentities): BinaryFileResponse|Response
    {
        $type = (string) $request->route('financial_analytics_type');
        $format = (string) $request->route('financial_analytics_format');
        $report = $this->reports->report($this->reports->filters($request, $type));
        if ($format === 'pdf') {
            $company = Company::query()->findOrFail($this->companies->requireCompanyId());

            return $pdf->stream('reports.financial-analytics', [
                'report' => $report,
                'companyPrintIdentity' => $printIdentities->forCompany($company),
            ], str_replace('_', '-', $type).'.pdf', 'L');
        }

        return Excel::download(
            new CostingReportExport($report),
            str_replace('_', '-', $type).'.'.($format === 'csv' ? 'csv' : 'xlsx'),
            $format === 'csv' ? ExcelWriter::CSV : ExcelWriter::XLSX,
        );
    }
}
