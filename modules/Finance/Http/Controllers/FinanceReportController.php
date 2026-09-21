<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
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
        $filters = $this->reports->filters($request, $this->defaultReportType($request));
        $this->authorizeRequest($request, $filters['type'], 'view');

        return view('modules.finance.reports.index', [
            'report' => $this->reports->report($filters),
            'filters' => $filters,
            'filterOptions' => $this->reports->filterOptions($filters),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($request->route()?->getName() ?? 'admin.reports.finance.index'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $this->reports->filters($request, $this->defaultReportType($request));
        $this->authorizeRequest($request, $filters['type'], 'view');
        $rows = $this->reports->report($filters)['rows'];

        return response()->json([
            'draw' => max(0, $request->integer('draw')),
            'recordsTotal' => $rows->count(),
            'recordsFiltered' => $rows->count(),
            'data' => $rows->values(),
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
            'title' => $report['title'],
            'companyPrintIdentity' => $printIdentities->forCompany($company),
        ], 'finance-'.$report['type'].'.pdf', 'L');
    }

    private function authorizeRequest(Request $request, string $type, string $action): void
    {
        $prefix = match ($type) {
            FinanceReportService::CashboxBalances => 'reports.finance.cashbox_balances',
            FinanceReportService::CashboxStatement => 'reports.finance.cashbox_statement',
            FinanceReportService::CashVouchers => 'reports.finance.cash_vouchers',
            FinanceReportService::BankAccountBalances => 'reports.finance.bank_account_balances',
            FinanceReportService::BankAccountStatement => 'reports.finance.bank_account_statement',
            FinanceReportService::BankReconciliation => 'reports.finance.bank_reconciliation',
            FinanceReportService::FundTransfers => 'reports.finance.treasury_transfers',
            FinanceReportService::ReceivedCheques => 'reports.finance.received_cheques',
            FinanceReportService::IssuedCheques => 'reports.finance.issued_cheques',
            FinanceReportService::ClearedCheques => 'reports.finance.cleared_cheques',
            FinanceReportService::ReturnedCheques => 'reports.finance.returned_cheques',
            FinanceReportService::DueCheques => 'reports.finance.cheque_transit',
            FinanceReportService::CancelledCheques => 'reports.finance.cancelled_cheques',
            FinanceReportService::AdvancesAllocations => 'reports.finance.advances_allocations',
            FinanceReportService::UnapprovedDocuments => 'reports.finance.unapproved_documents',
            FinanceReportService::CustomerAging => 'reports.finance.customer_aging',
            FinanceReportService::SupplierAging => 'reports.finance.supplier_aging',
        };

        abort_unless(
            (bool) $request->user()?->can('reports.finance.view')
            || (bool) $request->user()?->can("{$prefix}.{$action}")
            || (bool) $request->user()?->can("{$prefix}.view"),
            403,
        );
    }

    private function defaultReportType(Request $request): ?string
    {
        return $request->route()?->getName() === 'admin.reports.finance.index'
            ? null
            : (string) $request->route('finance_report_type', '');
    }
}
