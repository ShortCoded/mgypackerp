<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Inventory\Exports\InventoryReportExport;
use Modules\Inventory\Services\InventoryAccountingMappingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryReportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryReportController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly InventoryReportService $reports,
        private readonly InventoryAccountingMappingService $accountingMappings,
        private readonly InventoryGlReconciliationService $reconciliation,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request): View
    {
        [$context, $canViewFinancial, $report] = $this->report($request);
        $balances = $report['balances'];

        if (! $canViewFinancial) {
            $balances->each->makeHidden(['inventory_value', 'unvalued_receipt_quantity']);
        }

        return view('modules.inventory.reports.index', [
            ...$report,
            'balances' => $balances,
            'canViewFinancial' => $canViewFinancial,
            'agingSupported' => true,
            'expirySupported' => true,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [, $canViewFinancial, $report] = $this->report($request);

        return Excel::download(
            new InventoryReportExport($report, $canViewFinancial),
            'inventory-operations-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function print(Request $request): Response
    {
        [$context, $canViewFinancial, $report] = $this->report($request);
        $company = Company::query()->findOrFail($context['company_id']);

        return $this->pdf->stream('reports.inventory.operations', [
            ...$report,
            'canViewFinancial' => $canViewFinancial,
            'title' => __('Inventory Operations Report'),
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], 'inventory-operations-report.pdf');
    }

    /** @return array{0: array<string, mixed>, 1: bool, 2: array<string, mixed>} */
    private function report(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, 'Company, financial period, and branch context are required.');
        $canViewFinancial = (bool) $request->user()?->can('inventory.reports.financial');
        $report = $this->reports->report(
            $context['company_id'],
            $context['financial_period_id'],
            $context['branch_id'],
            $request->only(['source_doc_num', 'branch_store_id', 'warehouse_location_id', 'product_id', 'classification', 'stock_status', 'batch_lot', 'transaction_type', 'from', 'to', 'as_of', 'expiry_within_days']),
        );
        $report['glReconciliation'] = null;
        $report['glReconciliationUnavailableReason'] = null;

        if ($canViewFinancial) {
            if ($this->accountingMappings->forCompany($context['company_id']) === null) {
                $report['glReconciliationUnavailableReason'] = __('inventory.reports.gl_reconciliation_unavailable');
            } else {
                $report['glReconciliation'] = $this->reconciliation->reconcile(
                    $context['company_id'],
                    $context['financial_period_id'],
                    $context['branch_id'],
                );
            }
        }

        return [$context, $canViewFinancial, $report];
    }
}
