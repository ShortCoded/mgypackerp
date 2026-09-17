<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Company;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Finance\Exports\FinanceReportExport;
use Modules\Finance\Services\FinanceReportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class FinanceReportController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $reports,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->reports->filters($request, (string) $request->route('finance_report_type', ''));
        $this->authorizeRequest($request, $filters['type'], 'view');

        return view('modules.finance.reports.index', [
            'report' => $this->reports->report($filters),
            'filters' => $filters,
            'filterOptions' => $this->reports->filterOptions(),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($request->route()?->getName() ?? 'admin.reports.finance.index'),
        ]);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $filters = $this->reports->filters($request);
        $this->authorizeRequest($request, $filters['type'], 'export');
        $report = $this->reports->report($filters);

        return Excel::download(new FinanceReportExport($report), 'finance-'.$report['type'].'.xlsx');
    }

    public function csv(Request $request): BinaryFileResponse
    {
        $filters = $this->reports->filters($request);
        $this->authorizeRequest($request, $filters['type'], 'export');
        $report = $this->reports->report($filters);

        return Excel::download(new FinanceReportExport($report), 'finance-'.$report['type'].'.csv', \Maatwebsite\Excel\Excel::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function pdf(
        Request $request,
        ReportPdfService $pdf,
        CompanyPrintIdentityService $printIdentities,
    ): Response {
        $filters = $this->reports->filters($request);
        $this->authorizeRequest($request, $filters['type'], 'pdf');
        $report = $this->reports->report($filters);
        $company = Company::query()->findOrFail($this->companies->requireCompanyId());

        return $pdf->stream('reports.finance', [
            'report' => $report,
            'companyPrintIdentity' => $printIdentities->forCompany($company),
        ], 'finance-'.$report['type'].'.pdf', 'L');
    }

    private function authorizeRequest(Request $request, string $type, string $action): void
    {
        $prefix = match ($type) {
            FinanceReportService::CashboxBalances => 'reports.finance.cashbox_balances',
            FinanceReportService::CashboxStatement, FinanceReportService::CashVouchers => 'reports.finance.cashbox_statement',
            FinanceReportService::BankAccountBalances => 'reports.finance.bank_account_balances',
            FinanceReportService::BankAccountStatement, FinanceReportService::BankReconciliation => 'reports.finance.bank_account_statement',
            FinanceReportService::FundTransfers => 'reports.finance.treasury_transfers',
            FinanceReportService::CustomerAging => 'reports.finance.customer_aging',
            FinanceReportService::SupplierAging => 'reports.finance.supplier_aging',
            default => 'reports.finance.cheque_transit',
        };

        abort_unless(
            (bool) $request->user()?->can('reports.finance.view')
            || (bool) $request->user()?->can("{$prefix}.{$action}")
            || (bool) $request->user()?->can("{$prefix}.view"),
            403,
        );
    }
}
