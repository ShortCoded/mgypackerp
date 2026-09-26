<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Core\Models\FinancialPeriod;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\FundTransfer;
use Modules\Finance\Models\OpeningBalance;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Services\FixedAssetBookValueService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesOrder;

final class PeriodClosePreflightService
{
    public function __construct(
        private readonly InventoryGlReconciliationService $inventoryReconciliation,
        private readonly ProcurementCycleReport $procurementReport,
        private readonly CostAccountingReportService $costAccountingReport,
        private readonly FixedAssetDepreciationService $fixedAssetDepreciation,
        private readonly FixedAssetBookValueService $fixedAssetBookValues,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     status: 'pass'|'warning'|'blocker',
     *     message: string,
     *     count?: int,
     *     details?: list<array{label: string, count?: int, url?: string, permission?: string}>
     * }>
     */
    public function checks(FinancialPeriod $period): array
    {
        return [
            $this->overheadAllocationSchemaCheck(),
            $this->financialDocumentsCheck($period),
            $this->unpricedReceiptsCheck($period),
            $this->unvaluedMovementsCheck($period),
            $this->negativeStockCheck($period),
            $this->inventoryReconciliationCheck($period),
            $this->grniReconciliationCheck($period),
            $this->costCenterAllocationCheck($period),
            $this->openOperationalDocumentsCheck($period),
        ];
    }

    public function assertReady(FinancialPeriod $period): void
    {
        $blockers = collect($this->checks($period))
            ->where('status', 'blocker')
            ->values();

        if ($blockers->isNotEmpty()) {
            throw new DomainException(trans_choice(
                'financial_periods.messages.preflight_blockers',
                $blockers->count(),
                [
                    'count' => $blockers->count(),
                    'first' => (string) $blockers->first()['message'],
                ],
            ));
        }
    }

    /** @return array<string, mixed> */
    private function financialDocumentsCheck(FinancialPeriod $period): array
    {
        $companyId = (int) $period->company_id;
        $periodId = (int) $period->getKey();
        $from = $period->from_date->toDateString();
        $to = $period->to_date->toDateString();

        $details = [
            $this->detail(
                __('financial_periods.closing.document_types.customer_invoices'),
                CustomerInvoice::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->where('status', '!=', CustomerInvoice::StatusCancelled)
                    ->where('posting_status', '!=', 'posted')
                    ->count(),
                'admin.sales.customer-invoices.index',
                'customer_invoices.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.purchase_invoices'),
                PurchaseInvoice::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->where('status', PurchaseInvoice::StatusDraft)
                    ->count(),
                'admin.purchases.purchase-invoices.index',
                'purchase_invoices.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.customer_receipts'),
                CustomerReceipt::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->whereIn('status', [CustomerReceipt::StatusDraft, CustomerReceipt::StatusReopened])
                    ->count(),
                'admin.sales.customer-receipts.index',
                'customer_receipts.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.supplier_payments'),
                SupplierPaymentContext::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->where('status', SupplierPaymentContext::StatusDraft)
                    ->count(),
                'admin.purchases.supplier-payments.index',
                'supplier_payments.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.cash_vouchers'),
                CashVoucher::query()
                    ->where('company_id', $companyId)
                    ->whereBetween('voucher_date', [$from, $to])
                    ->where(function (Builder $query): void {
                        $query->where('status', CashVoucher::StatusDraft)
                            ->orWhere(function (Builder $approved): void {
                                $approved->where('status', CashVoucher::StatusApproved)
                                    ->whereNotExists(function (QueryBuilder $linked): void {
                                        $linked->selectRaw('1')
                                            ->from('customer_receipts')
                                            ->whereColumn('customer_receipts.cash_voucher_id', 'cash_vouchers.id')
                                            ->whereNotNull('customer_receipts.journal_entry_id')
                                            ->whereNull('customer_receipts.deleted_at');
                                    })
                                    ->whereNotExists(function (QueryBuilder $linked): void {
                                        $linked->selectRaw('1')
                                            ->from('supplier_payment_contexts')
                                            ->whereColumn('supplier_payment_contexts.cash_voucher_id', 'cash_vouchers.id')
                                            ->whereNotNull('supplier_payment_contexts.journal_entry_id');
                                    })
                                    ->whereNotExists(function (QueryBuilder $linked): void {
                                        $linked->selectRaw('1')
                                            ->from('production_expense_requests')
                                            ->whereColumn('production_expense_requests.cash_voucher_id', 'cash_vouchers.id')
                                            ->whereNotNull('production_expense_requests.journal_entry_id')
                                            ->whereNull('production_expense_requests.deleted_at');
                                    });
                            });
                    })
                    ->count(),
                'admin.finance.cash-payment-vouchers.index',
                'cash_payment_vouchers.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.fund_transfers'),
                FundTransfer::query()
                    ->where('company_id', $companyId)
                    ->whereBetween('transfer_date', [$from, $to])
                    ->whereIn('status', [FundTransfer::StatusDraft, FundTransfer::StatusApproved])
                    ->count(),
                'admin.finance.fund-transfers.index',
                'fund_transfers.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.inventory_documents'),
                InventoryDocument::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->where('status', InventoryDocument::StatusDraft)
                    ->count(),
                'admin.inventory.documents.index',
                'inventory.documents.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.unpriced_receipts'),
                UnpricedInventoryReceipt::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->whereNotIn('status', [UnpricedInventoryReceipt::StatusCancelled, UnpricedInventoryReceipt::StatusReversed])
                    ->where(fn (Builder $query): Builder => $query
                        ->whereNull('posting_status')
                        ->orWhere('posting_status', '!=', 'posted'))
                    ->count(),
                'admin.inventory.unpriced-inventory-receipts.index',
                'inventory.unpriced_inventory_receipts.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.opening_balances'),
                OpeningBalance::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->where('status', OpeningBalance::StatusDraft)
                    ->count(),
                'admin.finance.opening-balances.index',
                'opening_balances.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.opening_stocks'),
                OpeningStock::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->where(fn (Builder $query): Builder => $query
                        ->where('status', OpeningStock::StatusDraft)
                        ->orWhere(fn (Builder $query): Builder => $query
                            ->where('approved', false)
                            ->where('is_closed', false)))
                    ->count(),
                'admin.inventory.opening-stocks.index',
                'inventory.opening_stocks.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.opening_stock_pricings'),
                OpeningStockPricing::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->where(fn (Builder $query): Builder => $query
                        ->where('status', OpeningStockPricing::StatusDraft)
                        ->orWhere('is_closed', false))
                    ->count(),
                'admin.inventory.opening-stock-pricings.index',
                'inventory.opening_stock_pricings.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.overhead_allocation_runs'),
                Schema::hasTable('cost_overhead_allocation_runs')
                    ? OverheadAllocationRun::query()
                        ->where('company_id', $companyId)
                        ->where('financial_period_id', $periodId)
                        ->where('status', OverheadAllocationRun::StatusDraft)
                        ->count()
                    : 0,
                'admin.costing.overhead-allocation-run.index',
                'costing.overhead_allocation_run.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.production_expenses'),
                ProductionExpenseRequest::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->whereIn('status', [
                        ProductionExpenseRequest::StatusDraft,
                        ProductionExpenseRequest::StatusSubmitted,
                        ProductionExpenseRequest::StatusApproved,
                    ])
                    ->whereNull('journal_entry_id')
                    ->count(),
                'admin.production.expenses.index',
                'production.expenses.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.purchase_returns'),
                PurchaseReturn::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->where('status', PurchaseReturn::StatusDraft)
                    ->count(),
                'admin.purchases.purchase-returns.index',
                'purchases.purchase_returns.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.payroll_runs'),
                DB::table('hr_payroll_runs as payroll_run')
                    ->join('hr_payroll_periods as payroll_period', 'payroll_period.id', '=', 'payroll_run.payroll_period_id')
                    ->where('payroll_period.company_id', $companyId)
                    ->whereDate('payroll_period.period_end', '>=', $from)
                    ->whereDate('payroll_period.period_start', '<=', $to)
                    ->whereNotIn('payroll_run.status', ['posted', 'cancelled', 'rejected'])
                    ->count(),
                null,
                'hr.employees.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.fixed_asset_depreciation'),
                $this->dueDepreciationCount($period),
                'admin.fixed-assets.depreciation.index',
                'fixed_assets.depreciation.preview',
            ),
        ];

        $details = collect($details)->where('count', '>', 0)->values()->all();
        $count = collect($details)->sum('count');

        return [
            'key' => 'unposted_financial_documents',
            'status' => $count > 0 ? 'blocker' : 'pass',
            'message' => $count > 0
                ? trans_choice('financial_periods.closing.checks.unposted_financial_documents', $count, ['count' => $count])
                : __('financial_periods.closing.checks.no_unposted_financial_documents'),
            'count' => $count,
            'details' => $details,
        ];
    }

    /** @return array<string, mixed> */
    private function overheadAllocationSchemaCheck(): array
    {
        $available = Schema::hasTable('cost_overhead_allocation_runs');

        return [
            'key' => 'overhead_allocation_schema',
            'status' => $available ? 'pass' : 'blocker',
            'message' => $available
                ? __('financial_periods.closing.checks.overhead_allocation_schema_ready')
                : __('financial_periods.closing.checks.overhead_allocation_schema_missing'),
        ];
    }

    /** @return array<string, mixed> */
    private function unpricedReceiptsCheck(FinancialPeriod $period): array
    {
        $count = UnpricedInventoryReceipt::query()
            ->where('company_id', $period->company_id)
            ->where('financial_period_id', $period->getKey())
            ->where('approved', true)
            ->where('posting_status', 'posted')
            ->where('pricing_status', UnpricedInventoryReceipt::PricingStatusUnpriced)
            ->whereNotIn('status', [UnpricedInventoryReceipt::StatusCancelled, UnpricedInventoryReceipt::StatusReversed])
            ->count();

        return [
            'key' => 'unpriced_inventory_receipts',
            'status' => $count > 0 ? 'blocker' : 'pass',
            'message' => $count > 0
                ? trans_choice('financial_periods.closing.checks.unpriced_inventory_receipts', $count, ['count' => $count])
                : __('financial_periods.closing.checks.no_unpriced_inventory_receipts'),
            'count' => $count,
            'details' => $count > 0 ? [$this->detail(
                __('financial_periods.closing.document_types.unpriced_receipts'),
                $count,
                'admin.inventory.unpriced-inventory-receipts.index',
                'inventory.unpriced_inventory_receipts.view',
            )] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function unvaluedMovementsCheck(FinancialPeriod $period): array
    {
        $count = InventoryTransaction::query()
            ->where('company_id', $period->company_id)
            ->where('financial_period_id', $period->getKey())
            ->whereRaw('(quantity_in <> 0 or quantity_out <> 0)')
            ->where(fn (Builder $query): Builder => $query->whereNull('unit_cost')->orWhereNull('total_cost'))
            ->count();

        return [
            'key' => 'unvalued_inventory_movements',
            'status' => $count > 0 ? 'blocker' : 'pass',
            'message' => $count > 0
                ? trans_choice('financial_periods.closing.checks.unvalued_inventory_movements', $count, ['count' => $count])
                : __('financial_periods.closing.checks.no_unvalued_inventory_movements'),
            'count' => $count,
            'details' => $count > 0 ? [$this->detail(
                __('financial_periods.closing.document_types.inventory_movements'),
                $count,
                'admin.inventory.reports.index',
                'inventory.reports.operations.view',
            )] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function negativeStockCheck(FinancialPeriod $period): array
    {
        $positions = InventoryTransaction::query()
            ->where('company_id', $period->company_id)
            ->whereDate('transaction_date', '<=', $period->to_date->toDateString())
            ->groupBy('branch_store_id', 'warehouse_location_id', 'product_id', 'stock_status', 'batch_lot')
            ->selectRaw('branch_store_id, warehouse_location_id, product_id, stock_status, batch_lot, sum(quantity_in - quantity_out) as quantity');
        $count = DB::query()->fromSub($positions, 'stock_positions')->where('quantity', '<', 0)->count();

        return [
            'key' => 'negative_stock',
            'status' => $count > 0 ? 'blocker' : 'pass',
            'message' => $count > 0
                ? trans_choice('financial_periods.closing.checks.negative_stock', $count, ['count' => $count])
                : __('financial_periods.closing.checks.no_negative_stock'),
            'count' => $count,
            'details' => $count > 0 ? [$this->detail(
                __('financial_periods.closing.document_types.stock_positions'),
                $count,
                'admin.inventory.stock-balances.index',
                'inventory.reports.stock_balances.view',
            )] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function inventoryReconciliationCheck(FinancialPeriod $period): array
    {
        $hasActivity = InventoryTransaction::query()
            ->where('company_id', $period->company_id)
            ->where('financial_period_id', $period->getKey())
            ->exists();

        if (! $hasActivity) {
            return $this->pass('inventory_gl_reconciliation', __('financial_periods.closing.checks.no_inventory_activity'));
        }

        try {
            $differences = collect($this->inventoryReconciliation->reconcile(
                (int) $period->company_id,
                (int) $period->getKey(),
            ))->where('status', 'difference')->values();
        } catch (DomainException $exception) {
            return [
                'key' => 'inventory_gl_reconciliation',
                'status' => 'blocker',
                'message' => __('financial_periods.closing.checks.inventory_reconciliation_configuration', ['reason' => $exception->getMessage()]),
            ];
        }

        return [
            'key' => 'inventory_gl_reconciliation',
            'status' => $differences->isNotEmpty() ? 'blocker' : 'pass',
            'message' => $differences->isNotEmpty()
                ? trans_choice('financial_periods.closing.checks.inventory_reconciliation_differences', $differences->count(), ['count' => $differences->count()])
                : __('financial_periods.closing.checks.inventory_reconciled'),
            'count' => $differences->count(),
            'details' => $differences->map(fn (array $row): array => [
                'label' => $row['label'].' — '.__('financial_periods.closing.difference_value', ['amount' => $row['difference']]),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function grniReconciliationCheck(FinancialPeriod $period): array
    {
        $hasActivity = UnpricedInventoryReceipt::query()
            ->where('company_id', $period->company_id)
            ->where('financial_period_id', $period->getKey())
            ->whereNotIn('status', [UnpricedInventoryReceipt::StatusCancelled, UnpricedInventoryReceipt::StatusReversed])
            ->exists();

        if (! $hasActivity) {
            return $this->pass('grni_reconciliation', __('financial_periods.closing.checks.no_grni_activity'));
        }

        $reconciliation = $this->procurementReport->grniReconciliation(
            (int) $period->company_id,
            (int) $period->getKey(),
        );
        $blocked = $reconciliation['status'] !== 'reconciled';

        return [
            'key' => 'grni_reconciliation',
            'status' => $blocked ? 'blocker' : 'pass',
            'message' => match ($reconciliation['status']) {
                'not_configured' => __('financial_periods.closing.checks.grni_not_configured'),
                'difference' => __('financial_periods.closing.checks.grni_difference', ['amount' => $reconciliation['difference']]),
                default => __('financial_periods.closing.checks.grni_reconciled'),
            },
            'details' => $blocked ? [$this->detail(
                __('financial_periods.closing.document_types.grni'),
                null,
                'admin.purchases.procurement-cycle-report.index',
                'reports.purchases.goods_received_not_invoiced.view',
                ['report_type' => ProcurementCycleReport::GoodsReceivedNotInvoiced],
            )] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function costCenterAllocationCheck(FinancialPeriod $period): array
    {
        $count = $this->costAccountingReport->unallocatedRequiredTransactions(
            (int) $period->company_id,
            (int) $period->getKey(),
        )->count();

        return [
            'key' => 'cost_center_allocation',
            'status' => $count > 0 ? 'blocker' : 'pass',
            'message' => $count > 0
                ? trans_choice('financial_periods.closing.checks.unallocated_cost_transactions', $count, ['count' => $count])
                : __('financial_periods.closing.checks.cost_centers_complete'),
            'count' => $count,
            'details' => $count > 0 ? [$this->detail(
                __('financial_periods.closing.document_types.cost_transactions'),
                $count,
                'admin.reports.costing.work-order-cost.index',
                'reports.costing.work_order_cost.view',
            )] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function openOperationalDocumentsCheck(FinancialPeriod $period): array
    {
        $companyId = (int) $period->company_id;
        $periodId = (int) $period->getKey();
        $details = [
            $this->detail(
                __('financial_periods.closing.document_types.sales_orders'),
                SalesOrder::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->whereNotIn('status', [SalesOrder::StatusFulfilled, SalesOrder::StatusClosed, SalesOrder::StatusCancelled, SalesOrder::StatusRejected])
                    ->count(),
                'admin.sales.sales-orders.index',
                'sales_orders.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.purchase_orders'),
                PurchaseOrder::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->whereNotIn('status', [PurchaseOrder::StatusClosed, PurchaseOrder::StatusCancelled, PurchaseOrder::StatusRejected])
                    ->count(),
                'admin.purchases.purchase-orders.index',
                'purchase_orders.view',
            ),
            $this->detail(
                __('financial_periods.closing.document_types.production_orders'),
                ProductionOrder::query()
                    ->where('company_id', $companyId)
                    ->where('financial_period_id', $periodId)
                    ->whereNotIn('status', [ProductionOrder::StatusCompleted, ProductionOrder::StatusShortClosed, ProductionOrder::StatusCancelled])
                    ->count(),
                'admin.production.work-orders.index',
                'production.orders.view',
            ),
        ];
        $details = collect($details)->where('count', '>', 0)->values()->all();
        $count = collect($details)->sum('count');

        return [
            'key' => 'open_operational_documents',
            'status' => $count > 0 ? 'warning' : 'pass',
            'message' => $count > 0
                ? trans_choice('financial_periods.closing.checks.open_operational_documents', $count, ['count' => $count])
                : __('financial_periods.closing.checks.no_open_operational_documents'),
            'count' => $count,
            'details' => $details,
        ];
    }

    /** @return array{label: string, count?: int, url?: string, permission?: string} */
    private function detail(
        string $label,
        ?int $count,
        ?string $routeName = null,
        ?string $permission = null,
        array $routeParameters = [],
    ): array {
        return array_filter([
            'label' => $label,
            'count' => $count,
            'url' => $routeName !== null && Route::has($routeName) ? route($routeName, $routeParameters) : null,
            'permission' => $permission,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array{key: string, status: 'pass', message: string} */
    private function pass(string $key, string $message): array
    {
        return ['key' => $key, 'status' => 'pass', 'message' => $message];
    }

    private function dueDepreciationCount(FinancialPeriod $period): int
    {
        return FixedAsset::query()
            ->forCompany((int) $period->company_id)
            ->depreciationEligible()
            ->with(['postedDepreciations', 'costMovements.journalEntry', 'disposals'])
            ->get()
            ->filter(function (FixedAsset $asset) use ($period): bool {
                $next = $this->fixedAssetDepreciation->nextUnpostedDate($asset, $period->to_date);

                return $next !== null
                    && $next->lte($period->to_date)
                    && bccomp($this->fixedAssetBookValues->position($asset, $next)['remaining_depreciable_amount'], '0', 4) > 0;
            })
            ->count();
    }
}
