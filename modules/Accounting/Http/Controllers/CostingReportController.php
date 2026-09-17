<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Exports\CostingReportExport;
use Modules\Accounting\Services\CostingReportService;
use Modules\Core\Models\Company;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class CostingReportController extends Controller
{
    public function __construct(
        private readonly CostingReportService $reports,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->reports->filters($request, (string) $request->route('costing_report_type', ''));
        $this->authorizeRequest($request, $filters['type'], 'view');

        return view('modules.accounting.reports.costing', [
            'report' => $this->reports->report($filters),
            'filters' => $filters,
            'filterOptions' => $this->reports->filterOptions(),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($request->route()?->getName() ?? 'admin.reports.costing.product-cost.index'),
        ]);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        return $this->spreadsheet($request, ExcelWriter::XLSX, 'xlsx');
    }

    public function csv(Request $request): BinaryFileResponse
    {
        return $this->spreadsheet($request, ExcelWriter::CSV, 'csv');
    }

    public function pdf(Request $request, ReportPdfService $pdf, CompanyPrintIdentityService $printIdentities): Response
    {
        $filters = $this->reports->filters($request);
        $this->authorizeRequest($request, $filters['type'], 'print');
        $report = $this->reports->report($filters);
        $company = Company::query()->findOrFail($this->companies->requireCompanyId());

        return $pdf->stream('reports.costing', [
            'report' => $report,
            'companyPrintIdentity' => $printIdentities->forCompany($company),
        ], 'costing-'.$report['type'].'.pdf', 'L');
    }

    private function spreadsheet(Request $request, string $writer, string $extension): BinaryFileResponse
    {
        $filters = $this->reports->filters($request);
        $this->authorizeRequest($request, $filters['type'], 'export');
        $report = $this->reports->report($filters);

        return Excel::download(new CostingReportExport($report), 'costing-'.$report['type'].'.'.$extension, $writer);
    }

    private function authorizeRequest(Request $request, string $type, string $action): void
    {
        $prefix = 'reports.costing.'.str_replace('_', '.', $type);
        $legacyPrefix = 'reports.costing.'.str_replace('_', '-', $type);
        $screenPrefix = 'reports.costing.'.str_replace('_', '_', $type);

        $authorized = (bool) $request->user()?->can("{$screenPrefix}.{$action}")
            || (bool) $request->user()?->can("{$prefix}.{$action}")
            || (bool) $request->user()?->can("{$legacyPrefix}.{$action}");

        if ($action === 'view') {
            $authorized = $authorized || (bool) $request->user()?->can('reports.costing.view');
        }

        abort_unless($authorized, 403);
    }
}
