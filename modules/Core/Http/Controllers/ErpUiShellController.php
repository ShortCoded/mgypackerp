<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Controllers\CostingReportController;
use Modules\Accounting\Services\CostingReportService;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ErpUi\ErpUiScreenDefinition;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\ErpUi\ErpUiShellOverviewService;
use Modules\Finance\Http\Controllers\FinanceReportController;
use Modules\Finance\Services\FinanceReportService;

class ErpUiShellController
{
    public function __construct(
        private readonly ErpUiScreenRegistry $screens,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ErpUiShellOverviewService $overviews,
    ) {}

    public function index(Request $request): View
    {
        $screen = $this->screen($request);
        $financeReportType = $this->financeReportType($screen);

        if ($financeReportType !== null) {
            $request->route()?->setParameter('finance_report_type', $financeReportType);

            return app(FinanceReportController::class)->index($request);
        }

        $costingReportType = $this->costingReportType($screen);
        if ($costingReportType !== null) {
            $request->route()?->setParameter('costing_report_type', $costingReportType);

            return app(CostingReportController::class)->index($request);
        }

        return view('modules.ui-shell.index', [
            'definition' => $screen,
            'screen' => $screen->toArray(),
            'overview' => $this->overviews->for($screen, $request),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($screen->route('index')),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $screen = $this->screen($request);
        $financeReportType = $this->financeReportType($screen);

        if ($financeReportType !== null) {
            $reports = app(FinanceReportService::class);
            $report = $reports->report($reports->filters($request, $financeReportType));

            return response()->json([
                'draw' => max(0, $request->integer('draw')),
                'recordsTotal' => $report['rows']->count(),
                'recordsFiltered' => $report['rows']->count(),
                'data' => $report['rows']->values(),
            ]);
        }

        $costingReportType = $this->costingReportType($screen);
        if ($costingReportType !== null) {
            $reports = app(CostingReportService::class);
            $report = $reports->report($reports->filters($request, $costingReportType));

            return response()->json([
                'draw' => max(0, $request->integer('draw')),
                'recordsTotal' => $report['rows']->count(),
                'recordsFiltered' => $report['rows']->count(),
                'data' => $report['rows']->values(),
            ]);
        }

        return response()->json([
            'draw' => max(0, $request->integer('draw')),
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, 'create');
    }

    public function show(Request $request, string $doc_num): View
    {
        return $this->form($request, 'view', $doc_num);
    }

    public function edit(Request $request, string $doc_num): View
    {
        return $this->form($request, 'edit', $doc_num);
    }

    public function clone(Request $request, string $doc_num): View
    {
        return $this->form($request, 'clone', $doc_num);
    }

    private function form(Request $request, string $mode, ?string $docNum = null): View
    {
        $screen = $this->screen($request);

        abort_unless($screen->supportsMode($mode), 404);

        return view('modules.ui-shell.form', [
            'definition' => $screen,
            'screen' => $screen->toArray(),
            'mode' => $mode,
            'docNum' => $docNum,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($screen->route('index')),
        ]);
    }

    private function screen(Request $request): ErpUiScreenDefinition
    {
        $key = (string) $request->route('erp_ui_screen', '');
        $screen = $this->screens->find($key);

        abort_unless($screen instanceof ErpUiScreenDefinition, 404);

        return $screen;
    }

    private function financeReportType(ErpUiScreenDefinition $screen): ?string
    {
        return match ($screen->key()) {
            'reports_finance_cashbox_balances' => FinanceReportService::CashboxBalances,
            'reports_finance_cashbox_statement' => FinanceReportService::CashboxStatement,
            'reports_finance_bank_account_balances' => FinanceReportService::BankAccountBalances,
            'reports_finance_bank_account_statement' => FinanceReportService::BankAccountStatement,
            'reports_finance_cheque_transit' => FinanceReportService::DueCheques,
            'reports_finance_treasury_transfers' => FinanceReportService::FundTransfers,
            'reports_finance_customer_aging' => FinanceReportService::CustomerAging,
            'reports_finance_supplier_aging' => FinanceReportService::SupplierAging,
            default => null,
        };
    }

    private function costingReportType(ErpUiScreenDefinition $screen): ?string
    {
        return match ($screen->key()) {
            'reports_costing_product_cost' => CostingReportService::ProductCost,
            'reports_costing_work_order_cost' => CostingReportService::WorkOrderCost,
            'reports_costing_estimated_vs_actual' => CostingReportService::EstimatedVsActual,
            'reports_costing_cost_variance' => CostingReportService::CostVariance,
            'reports_costing_profitability' => CostingReportService::Profitability,
            default => null,
        };
    }
}
