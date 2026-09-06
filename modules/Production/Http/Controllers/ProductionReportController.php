<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Production\Exports\ProductionReportExport;
use Modules\Production\Services\ProductionReportService;
use Modules\Sales\Services\SalesCycleReadService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductionReportController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly ProductionReportService $reports,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request): View
    {
        [, $canViewFinancial, $report] = $this->report($request);

        return view('modules.production.reports.index', [
            ...$report,
            'canViewFinancial' => $canViewFinancial,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [, $canViewFinancial, $report] = $this->report($request);

        return Excel::download(
            new ProductionReportExport($report, $canViewFinancial),
            'production-operations-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function print(Request $request): Response
    {
        [$context, $canViewFinancial, $report] = $this->report($request);
        $company = Company::query()->findOrFail($context['company_id']);

        return $this->pdf->stream('reports.production.operations', [
            ...$report,
            'canViewFinancial' => $canViewFinancial,
            'title' => __('Production Operations Report'),
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], 'production-operations-report.pdf');
    }

    /** @return array{0: array<string, mixed>, 1: bool, 2: array<string, mixed>} */
    private function report(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, 'Company, financial period, and branch context are required.');
        $canViewFinancial = (bool) $request->user()?->can('production.reports.financial');
        $report = $this->reports->report(
            $context['company_id'],
            $context['financial_period_id'],
            $context['branch_id'],
            [
                ...$request->only(['status', 'from', 'to']),
                'production_run_id' => $request->integer('production_run_id') ?: null,
            ],
            $canViewFinancial,
        );

        $report['backorders'] = app(SalesCycleReadService::class)->backorders((int) $context['company_id'], (int) $context['branch_id']);

        return [$context, $canViewFinancial, $report];
    }
}
