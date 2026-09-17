<?php

namespace App\Services;

use Database\Seeders\IntegratedPlasticFactorySeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Company;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;

class IntegratedPlasticFactoryDemoVerifier
{
    /** @return array<string, mixed> */
    public function verify(): array
    {
        $company = Company::withoutGlobalScopes()
            ->where('name', IntegratedPlasticFactorySeeder::CompanyName)
            ->first();

        if (! $company instanceof Company) {
            return [
                'ok' => false,
                'company' => null,
                'period' => null,
                'checks' => ['demo_company_exists' => false],
                'counts' => [],
                'documents' => [],
                'supplier_balances' => [],
                'inventory_reconciliation' => [],
            ];
        }

        $companyId = (int) $company->getKey();
        $period = DB::table('financial_periods')
            ->where('company_id', $companyId)
            ->where('name', 'FY 2026 - Runtime Demo')
            ->first();
        $branchIds = DB::table('branches')->where('company_id', $companyId)->pluck('id');
        $storeIds = DB::table('branch_stores')->whereIn('branch_id', $branchIds)->pluck('id');
        $split = $this->splitCycle($companyId);
        $salesLinkedCycle = $this->salesLinkedCycle($companyId);
        $movementTypes = DB::table('inventory_documents')
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->pluck('document_type')
            ->unique();
        $postingAccounts = app(PostingAccountConfigurationAudit::class)->forCompany($companyId);
        $unbalancedJournals = DB::table('journal_entries as journals')
            ->join('journal_entry_lines as lines', 'lines.journal_entry_id', '=', 'journals.id')
            ->where('journals.company_id', $companyId)
            ->whereNull('journals.deleted_at')
            ->select('journals.id')
            ->groupBy('journals.id')
            ->havingRaw('abs(sum(lines.debit_amount) - sum(lines.credit_amount)) > 0.001')
            ->count();
        $zeroValueJournals = DB::table('journal_entries as journals')
            ->join('journal_entry_lines as lines', 'lines.journal_entry_id', '=', 'journals.id')
            ->where('journals.company_id', $companyId)
            ->whereNull('journals.deleted_at')
            ->select('journals.id')
            ->groupBy('journals.id')
            ->havingRaw('sum(lines.debit_amount) <= 0.001')
            ->count();
        $negativePositions = DB::table('inventory_transactions')
            ->where('company_id', $companyId)
            ->select('branch_store_id', 'warehouse_location_id', 'product_id', 'stock_status', 'batch_lot')
            ->groupBy('branch_store_id', 'warehouse_location_id', 'product_id', 'stock_status', 'batch_lot')
            ->havingRaw('sum(quantity_in - quantity_out) < -0.000001')
            ->count();
        $negativeReceiptLayers = DB::table('inventory_receipt_layers')
            ->where('company_id', $companyId)
            ->where('remaining_quantity', '<', 0)
            ->count();
        $goldenAssetId = DB::table('fixed_assets')
            ->where('company_id', $companyId)
            ->where('serial_number', 'LIFECYCLE-MGY-2025-001')
            ->value('id');
        $fixedAssetLifecycleComplete = $goldenAssetId !== null
            && DB::table('fixed_assets')->where('id', $goldenAssetId)->where('status', 'sold')->whereNotNull('capitalized_at')->exists()
            && DB::table('fixed_asset_movements')->where('fixed_asset_id', $goldenAssetId)->where('status', 'posted')->exists()
            && DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $goldenAssetId)->where('status', 'posted')->count() >= 2
            && DB::table('fixed_asset_disposals')->where('fixed_asset_id', $goldenAssetId)->where('disposition_type', 'sale')->where('status', 'posted')->exists()
            && DB::table('fixed_asset_disposals')->where('company_id', $companyId)->where('disposition_type', 'write_off')->where('status', 'posted')->exists()
            && DB::table('fixed_asset_disposals')->where('company_id', $companyId)->where('disposition_type', 'write_off')->where('status', 'reversed')->exists();
        $supplierBalances = $this->supplierBalances($companyId);
        $customerBalances = $this->customerBalances($companyId);
        $inventoryReconciliation = $period === null
            ? []
            : app(InventoryGlReconciliationService::class)->reconcile($companyId, (int) $period->id);
        $productionProcurementRows = $period === null
            ? collect()
            : app(ProcurementCycleReport::class)->rows(
                ProcurementCycleReport::ProductionAnalysis,
                [],
                $companyId,
                (int) $period->id,
            );

        $checks = [
            'demo_company_exists' => true,
            'current_financial_period_is_open' => $period !== null && ! $period->is_closed,
            'master_data_has_manual_testing_breadth' => DB::table('branches')->where('company_id', $companyId)->count() >= 3
                && DB::table('branch_halls')->whereIn('branch_id', $branchIds)->count() >= 3
                && DB::table('branch_stores')->whereIn('branch_id', $branchIds)->count() >= 4
                && DB::table('warehouse_locations')->whereIn('branch_store_id', $storeIds)->count() >= 7
                && DB::table('customers')->where('company_id', $companyId)->count() >= 5
                && DB::table('suppliers')->where('company_id', $companyId)->count() >= 8
                && DB::table('products')->where('company_id', $companyId)->count() >= 18,
            'classification_posting_accounts_complete' => $postingAccounts['ok'],
            'all_journals_are_balanced_and_nonzero' => $unbalancedJournals === 0 && $zeroValueJournals === 0,
            'inventory_has_no_negative_positions' => $negativePositions === 0 && $negativeReceiptLayers === 0,
            'inventory_subledger_reconciles_to_gl' => $inventoryReconciliation !== [] && collect($inventoryReconciliation)
                ->every(fn (array $row): bool => $row['status'] === 'reconciled'),
            'split_award_is_exactly_6000_and_4000' => $split['requisition_id'] !== null
                && $split['requested'] === 10000.0
                && $split['selected'] === [6000.0, 4000.0]
                && $split['ordered'] === 10000.0,
            'split_receipts_and_qc_reconcile' => count($split['purchase_order_ids']) === 2
                && count($split['receipt_ids']) === 3
                && $split['received'] === 10000.0
                && $split['accepted'] === 9900.0
                && $split['rejected'] === 100.0,
            'pre_and_post_invoice_returns_exist' => DB::table('purchase_returns')
                ->whereIn('purchase_order_id', $split['purchase_order_ids'])
                ->whereNull('purchase_invoice_id')->where('status', 'posted')->exists()
                && DB::table('purchase_returns')
                    ->whereIn('purchase_order_id', $split['purchase_order_ids'])
                    ->whereNotNull('purchase_invoice_id')->where('status', 'posted')->exists(),
            'cash_bank_and_cheque_supplier_payments_exist' => collect(['cash', 'bank', 'cheque'])->every(
                fn (string $method): bool => DB::table('supplier_payment_contexts')
                    ->where('company_id', $companyId)
                    ->where('payment_method', $method)
                    ->where('status', 'approved')
                    ->exists(),
            ),
            'supplier_advance_and_due_schedules_exist' => DB::table('supplier_payment_contexts')
                ->where('company_id', $companyId)->where('is_advance', true)->where('status', 'approved')->exists()
                && DB::table('purchase_invoice_payment_schedules')
                    ->where('company_id', $companyId)
                    ->whereRaw('(amount - paid_amount - credited_amount) > 0.001')
                    ->whereDate('due_date', '<=', now()->addWeek()->toDateString())
                    ->exists(),
            'supplier_subledgers_reconcile_to_gl' => $this->supplierSubledgersReconcileToGl($companyId, $supplierBalances),
            'customer_subledgers_reconcile_to_gl' => $this->customerSubledgersReconcileToGl($companyId),
            'customer_statement_examples_are_settled_outstanding_and_credit' => abs((float) $customerBalances['Fully settled']['closing_balance']) <= 0.001
                && (float) $customerBalances['Outstanding']['closing_balance'] > 0.001
                && (float) $customerBalances['Credit balance']['closing_balance'] < -0.001,
            'sales_lineage_complete' => DB::table('quotations')->where('company_id', $companyId)->exists()
                && DB::table('sales_orders')->where('company_id', $companyId)->whereNotNull('quotation_id')->exists()
                && DB::table('customer_invoices')->where('company_id', $companyId)->whereNotNull('sales_order_id')->exists()
                && DB::table('sales_returns')->where('company_id', $companyId)->whereNotNull('credit_note_id')->exists(),
            'sales_stock_production_delivery_and_return_are_exact' => $salesLinkedCycle['order_quantity'] === 100.0
                && $salesLinkedCycle['reserved_quantity'] === 100.0
                && ($salesLinkedCycle['order_quantity'] - $salesLinkedCycle['production_requested_quantity']) === 30.0
                && $salesLinkedCycle['production_requested_quantity'] === 70.0
                && $salesLinkedCycle['produced_quantity'] === 70.0
                && $salesLinkedCycle['production_runs'] === [20.0, 20.0, 30.0]
                && $salesLinkedCycle['deliveries'] === [60.0, 40.0]
                && $salesLinkedCycle['invoice_total'] === 13000.0
                && $salesLinkedCycle['receipts'] === [5000.0, 8000.0]
                && $salesLinkedCycle['saleable_return'] === 7.0
                && $salesLinkedCycle['rework_return'] === 2.0
                && $salesLinkedCycle['scrap_return'] === 1.0,
            'production_lineage_and_movements_complete' => DB::table('production_orders')->where('company_id', $companyId)->count() >= 3
                && DB::table('production_runs')->where('company_id', $companyId)->count() >= 2
                && collect([
                    'production_material_issue', 'production_additional_material_issue',
                    'production_material_return', 'production_waste', 'production_receipt',
                ])->every(fn (string $type): bool => $movementTypes->contains($type)),
            'production_linked_procurement_report_is_populated' => $productionProcurementRows->contains(
                fn (array $row): bool => filled($row['production_order']) && filled($row['requisition']),
            ),
            'inventory_operational_movements_complete' => collect([
                'inventory_transfer', 'inventory_damage', 'inventory_scrap', 'inventory_adjustment_out',
                'sales_delivery', 'sales_return_receipt',
            ])->every(fn (string $type): bool => $movementTypes->contains($type)),
            'purchase_order_lifecycle_states_and_changes_exist' => DB::table('purchase_orders')
                ->where('company_id', $companyId)->where('status', 'draft')->exists()
                && DB::table('purchase_orders')->where('company_id', $companyId)->where('status', 'approved')
                    ->where('total_received_quantity', 0)->exists()
                && DB::table('purchase_orders')->where('company_id', $companyId)->where('status', 'approved')
                    ->where('total_received_quantity', '>', 0)->where('total_remaining_quantity', '>', 0)->exists()
                && DB::table('purchase_orders')->where('company_id', $companyId)->where('status', 'closed')
                    ->where('total_remaining_quantity', 0)->exists()
                && collect(['approved', 'pending'])->every(fn (string $status): bool => DB::table('purchase_order_change_requests')
                    ->where('company_id', $companyId)->where('status', $status)->exists()),
            'fixed_asset_register_is_populated' => DB::table('fixed_assets')->where('company_id', $companyId)->count() >= 7
                && DB::table('fixed_asset_category_mappings')->where('company_id', $companyId)->count() >= 1,
            'fixed_asset_lifecycle_is_complete' => $fixedAssetLifecycleComplete,
            'fixed_asset_subledger_reconciles_to_gl' => $this->fixedAssetSubledgerReconciles($companyId),
        ];

        return [
            'ok' => ! in_array(false, $checks, true),
            'company' => $company->name,
            'period' => $period?->name,
            'checks' => $checks,
            'counts' => $this->counts($companyId, $branchIds, $storeIds),
            'documents' => $this->documents($companyId, $split),
            'supplier_balances' => $supplierBalances,
            'customer_balances' => $customerBalances,
            'inventory_reconciliation' => $inventoryReconciliation,
        ];
    }

    /** @return array<string, mixed> */
    private function splitCycle(int $companyId): array
    {
        $requisitionId = DB::table('purchase_requisitions')
            ->where('company_id', $companyId)
            ->where('notes', 'like', '%Exact 10,000 kg polypropylene split-award%')
            ->value('id');
        $rfqId = DB::table('request_for_quotations')->where('purchase_requisition_id', $requisitionId)->value('id');
        $selectionId = DB::table('supplier_selections')->where('request_for_quotation_id', $rfqId)->value('id');
        $purchaseOrderIds = DB::table('purchase_orders')->where('supplier_selection_id', $selectionId)->pluck('id')->all();
        $receiptIds = DB::table('unpriced_inventory_receipts')->whereIn('purchase_order_id', $purchaseOrderIds)->pluck('id')->all();

        return [
            'requisition_id' => $requisitionId,
            'rfq_id' => $rfqId,
            'selection_id' => $selectionId,
            'purchase_order_ids' => $purchaseOrderIds,
            'receipt_ids' => $receiptIds,
            'requested' => (float) DB::table('purchase_requisition_lines')->where('purchase_requisition_id', $requisitionId)->sum('requested_quantity'),
            'selected' => DB::table('supplier_selection_lines')->where('supplier_selection_id', $selectionId)
                ->orderByDesc('selected_quantity')->pluck('selected_quantity')
                ->map(fn (mixed $quantity): float => (float) $quantity)->all(),
            'ordered' => (float) DB::table('purchase_order_lines')->whereIn('purchase_order_id', $purchaseOrderIds)->sum('ordered_quantity'),
            'received' => (float) DB::table('unpriced_inventory_receipt_lines')->whereIn('receipt_id', $receiptIds)->sum('delivered_quantity'),
            'accepted' => (float) DB::table('unpriced_inventory_receipt_lines')->whereIn('receipt_id', $receiptIds)->sum('accepted_quantity'),
            'rejected' => (float) DB::table('unpriced_inventory_receipt_lines')->whereIn('receipt_id', $receiptIds)->sum('rejected_quantity'),
        ];
    }

    /** @return array<string, float|list<float>> */
    private function salesLinkedCycle(int $companyId): array
    {
        $quotationId = DB::table('quotations')
            ->where('company_id', $companyId)
            ->where('customer_reference', 'PF-SALES-PRODUCTION-V1')
            ->value('id');
        $order = DB::table('sales_orders')->where('quotation_id', $quotationId)->first();
        $line = $order === null ? null : DB::table('sales_order_lines')
            ->where('sales_order_id', $order->id)
            ->where('product_classification_snapshot', 'finished_product')
            ->first();
        $productionOrderId = $order === null ? null : DB::table('production_orders')
            ->where('sales_order_id', $order->id)
            ->value('id');
        $return = $order === null ? null : DB::table('sales_return_lines as lines')
            ->join('sales_returns as returns', 'returns.id', '=', 'lines.sales_return_id')
            ->where('returns.sales_order_id', $order->id)
            ->first();

        return [
            'order_quantity' => (float) ($line?->quantity ?? 0),
            'reserved_quantity' => (float) ($line?->reserved_quantity ?? 0),
            'production_requested_quantity' => (float) ($line?->production_requested_quantity ?? 0),
            'produced_quantity' => (float) ($line?->produced_quantity ?? 0),
            'production_runs' => DB::table('production_runs')->where('production_order_id', $productionOrderId)
                ->orderBy('id')->pluck('good_base_quantity')->map(fn (mixed $quantity): float => (float) $quantity)->all(),
            'deliveries' => $order === null ? [] : DB::table('inventory_document_lines as lines')
                ->join('inventory_documents as documents', 'documents.id', '=', 'lines.inventory_document_id')
                ->where('documents.source_document_type', 'Modules\\Sales\\Models\\SalesOrder')
                ->where('documents.source_document_id', $order->id)
                ->where('documents.document_type', 'sales_delivery')
                ->groupBy('documents.id')->orderBy('documents.id')
                ->selectRaw('sum(lines.transaction_quantity) as quantity')->pluck('quantity')
                ->map(fn (mixed $quantity): float => (float) $quantity)->all(),
            'invoice_total' => $order === null ? 0.0 : (float) DB::table('customer_invoices')
                ->where('sales_order_id', $order->id)->where('document_type', 'invoice')->sum('total_amount'),
            'receipts' => $order === null ? [] : DB::table('customer_receipts')
                ->where('customer_id', $order->customer_id)
                ->where('notes', 'like', '%exact sales-linked production%')
                ->orderBy('id')->pluck('amount')->map(fn (mixed $amount): float => (float) $amount)->all(),
            'saleable_return' => (float) ($return?->saleable_quantity ?? 0),
            'rework_return' => (float) ($return?->rework_quantity ?? 0),
            'scrap_return' => (float) ($return?->scrap_quantity ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function counts(int $companyId, Collection $branchIds, Collection $storeIds): array
    {
        $companyCount = fn (string $table): int => DB::table($table)->where('company_id', $companyId)->count();

        return [
            'companies' => DB::table('companies')->whereNull('deleted_at')->count(),
            'branches' => $companyCount('branches'),
            'halls' => DB::table('branch_halls')->whereIn('branch_id', $branchIds)->count(),
            'stores' => DB::table('branch_stores')->whereIn('branch_id', $branchIds)->count(),
            'locations' => DB::table('warehouse_locations')->whereIn('branch_store_id', $storeIds)->count(),
            'cost_centers' => $companyCount('cost_centers'),
            'accounts' => $companyCount('accounts'),
            'classified_posting_accounts' => app(PostingAccountConfigurationAudit::class)->forCompany($companyId)['valid_count'],
            'bank_accounts' => $companyCount('bank_accounts'),
            'cash_safes' => $companyCount('cashboxes'),
            'customers' => $companyCount('customers'),
            'suppliers' => $companyCount('suppliers'),
            'products' => $companyCount('products'),
            'units' => $companyCount('item_units'),
            'boms' => DB::table('product_components')->whereIn('product_id', DB::table('products')->where('company_id', $companyId)->select('id'))
                ->distinct('product_id')->count('product_id'),
            'machines' => $companyCount('production_machines'),
            'molds' => $companyCount('production_molds'),
            'inventory_movements' => $companyCount('inventory_transactions'),
            'sales_quotations' => $companyCount('quotations'),
            'sales_orders' => $companyCount('sales_orders'),
            'sales_invoices' => $companyCount('customer_invoices'),
            'purchase_requisitions' => $companyCount('purchase_requisitions'),
            'rfqs' => $companyCount('request_for_quotations'),
            'supplier_quotations' => $companyCount('supplier_quotations'),
            'purchase_orders' => $companyCount('purchase_orders'),
            'purchase_order_changes' => $companyCount('purchase_order_change_requests'),
            'grns' => $companyCount('unpriced_inventory_receipts'),
            'incoming_qc_inspections' => $companyCount('goods_receipt_inspections'),
            'purchase_invoices' => $companyCount('purchase_invoices'),
            'supplier_payments' => $companyCount('supplier_payment_contexts'),
            'supplier_returns' => $companyCount('purchase_returns'),
            'production_orders' => $companyCount('production_orders'),
            'production_runs' => $companyCount('production_runs'),
            'fixed_assets' => $companyCount('fixed_assets'),
            'fixed_asset_movements' => $companyCount('fixed_asset_movements'),
            'fixed_asset_depreciation_runs' => $companyCount('fixed_asset_depreciation_runs'),
            'fixed_asset_depreciations' => $companyCount('fixed_asset_depreciations'),
            'fixed_asset_disposals' => $companyCount('fixed_asset_disposals'),
            'journal_entries' => $companyCount('journal_entries'),
        ];
    }

    /** @param array<string, mixed> $split @return array<string, list<string>> */
    private function documents(int $companyId, array $split): array
    {
        $documentNumbers = function (string $table, ?Collection $ids = null) use ($companyId): array {
            $query = DB::table($table)->where('company_id', $companyId);
            if ($ids instanceof Collection) {
                $query->whereIn('id', $ids);
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            return $query->orderBy('id')->pluck('doc_num')->filter()->values()->all();
        };
        $orderIds = collect($split['purchase_order_ids']);
        $receiptIds = collect($split['receipt_ids']);
        $invoiceIds = DB::table('purchase_invoices')->whereIn('purchase_order_id', $orderIds)->pluck('id');

        return [
            'purchase_requisition' => DB::table('purchase_requisitions')->where('id', $split['requisition_id'])->pluck('doc_num')->all(),
            'rfq' => DB::table('request_for_quotations')->where('id', $split['rfq_id'])->pluck('doc_num')->all(),
            'supplier_quotations' => DB::table('supplier_quotations')->where('request_for_quotation_id', $split['rfq_id'])->orderBy('id')->pluck('doc_num')->all(),
            'selection' => DB::table('supplier_selections')->where('id', $split['selection_id'])->pluck('doc_num')->all(),
            'purchase_orders' => DB::table('purchase_orders')->whereIn('id', $orderIds)->orderBy('id')->pluck('doc_num')->all(),
            'purchase_order_changes' => $documentNumbers('purchase_order_change_requests'),
            'delivery_schedules' => DB::table('purchase_order_delivery_schedules')->whereIn('purchase_order_id', $orderIds)
                ->orderBy('id')->pluck('public_id')->all(),
            'grns' => DB::table('unpriced_inventory_receipts')->whereIn('id', $receiptIds)->orderBy('id')->pluck('doc_num')->all(),
            'incoming_qc' => DB::table('goods_receipt_inspections')->whereIn('receipt_id', $receiptIds)->orderBy('id')->pluck('doc_num')->all(),
            'purchase_invoices' => DB::table('purchase_invoices')->whereIn('id', $invoiceIds)->orderBy('id')->pluck('doc_num')->all(),
            'payment_schedules' => DB::table('purchase_invoice_payment_schedules')->whereIn('purchase_invoice_id', $invoiceIds)
                ->orderBy('due_date')->pluck('public_id')->all(),
            'supplier_payments' => DB::table('supplier_payment_contexts')->whereIn('purchase_order_id', $orderIds)->orderBy('id')->pluck('doc_num')->all(),
            'purchase_returns' => DB::table('purchase_returns')->whereIn('purchase_order_id', $orderIds)->orderBy('id')->pluck('doc_num')->all(),
            'outgoing_cheques' => DB::table('cheques')->where('company_id', $companyId)->where('cheque_type', 'issued')->orderBy('id')->pluck('doc_num')->all(),
            'sales_quotations' => $documentNumbers('quotations'),
            'sales_orders' => $documentNumbers('sales_orders'),
            'customer_invoices' => $documentNumbers('customer_invoices'),
            'sales_returns' => $documentNumbers('sales_returns'),
            'production_orders' => $documentNumbers('production_orders'),
            'fixed_assets' => $documentNumbers('fixed_assets'),
            'fixed_asset_movements' => $documentNumbers('fixed_asset_movements'),
            'fixed_asset_depreciation_runs' => $documentNumbers('fixed_asset_depreciation_runs'),
            'fixed_asset_disposals' => $documentNumbers('fixed_asset_disposals'),
        ];
    }

    private function fixedAssetSubledgerReconciles(int $companyId): bool
    {
        $assets = DB::table('fixed_assets')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['disposed', 'sold', 'written_off'])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('fixed_asset_movements')
                    ->whereColumn('fixed_asset_movements.fixed_asset_id', 'fixed_assets.id')
                    ->whereIn('fixed_asset_movements.movement_type', ['opening', 'capitalization'])
                    ->where('fixed_asset_movements.status', 'posted');
            })
            ->get();

        foreach ($assets->groupBy('account_id') as $accountId => $accountAssets) {
            $subledger = $accountAssets->sum(fn (object $asset): float => (float) $asset->base_acquisition_value);
            $generalLedger = $this->accountBalance($companyId, (int) $accountId, true);

            if (abs($subledger - $generalLedger) > 0.001) {
                return false;
            }
        }

        $mappings = DB::table('fixed_asset_category_mappings')
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('asset_group_account_id');
        $accumulatedByAccount = [];
        $expenseAccountIds = collect();

        foreach ($assets as $asset) {
            $mapping = $mappings->get($asset->asset_group_account_id);

            if ($mapping === null) {
                continue;
            }

            $accountId = (int) $mapping->accumulated_depreciation_account_id;
            $postedDepreciation = (float) DB::table('fixed_asset_depreciations')
                ->where('fixed_asset_id', $asset->id)
                ->where('status', 'posted')
                ->sum('base_period_depreciation');
            $accumulatedByAccount[$accountId] = ($accumulatedByAccount[$accountId] ?? 0.0)
                + (float) $asset->previous_depreciation
                + $postedDepreciation;
            $expenseAccountIds->push((int) $mapping->depreciation_expense_account_id);
        }

        foreach ($accumulatedByAccount as $accountId => $subledger) {
            if (abs($subledger - $this->accountBalance($companyId, (int) $accountId, false)) > 0.001) {
                return false;
            }
        }

        $periodDepreciation = (float) DB::table('fixed_asset_depreciations')
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->sum('base_period_depreciation');
        $generalLedgerDepreciation = (float) DB::table('journal_entry_lines as lines')
            ->join('journal_entries as journals', 'journals.id', '=', 'lines.journal_entry_id')
            ->where('journals.company_id', $companyId)
            ->whereNull('journals.deleted_at')
            ->where('journals.is_posted', true)
            ->whereIn('journals.source_type', ['fixed_asset_depreciation_run', 'fixed_asset_depreciation_reversal'])
            ->whereIn('lines.account_id', $expenseAccountIds->unique()->all())
            ->selectRaw('COALESCE(SUM((lines.debit_amount - lines.credit_amount) * journals.exchange_rate), 0) as balance')
            ->value('balance');

        return abs($periodDepreciation - $generalLedgerDepreciation) <= 0.001;
    }

    private function accountBalance(int $companyId, int $accountId, bool $debitNormal): float
    {
        $balance = (float) DB::table('journal_entry_lines as lines')
            ->join('journal_entries as journals', 'journals.id', '=', 'lines.journal_entry_id')
            ->where('journals.company_id', $companyId)
            ->whereNull('journals.deleted_at')
            ->where('journals.is_posted', true)
            ->where('lines.account_id', $accountId)
            ->selectRaw('COALESCE(SUM((lines.debit_amount - lines.credit_amount) * journals.exchange_rate), 0) as balance')
            ->value('balance');

        return $debitNormal ? $balance : -$balance;
    }

    /** @param array<string, array<string, string|null>> $supplierBalances */
    private function supplierSubledgersReconcileToGl(int $companyId, array $supplierBalances): bool
    {
        return collect($supplierBalances)->every(function (array $balance) use ($companyId): bool {
            $supplier = DB::table('suppliers')
                ->where('company_id', $companyId)
                ->where('name', $balance['supplier'])
                ->first(['id', 'account_id']);
            if ($supplier === null || $supplier->account_id === null) {
                return false;
            }

            $generalLedger = (float) DB::table('journal_entry_lines as lines')
                ->join('journal_entries as journals', 'journals.id', '=', 'lines.journal_entry_id')
                ->where('journals.company_id', $companyId)
                ->whereNull('journals.deleted_at')
                ->where('journals.is_posted', true)
                ->where('lines.account_id', $supplier->account_id)
                ->where('lines.supplier_id', $supplier->id)
                ->selectRaw('COALESCE(SUM((lines.credit_amount - lines.debit_amount) * journals.exchange_rate), 0) as balance')
                ->value('balance');

            return abs((float) $balance['outstanding'] - $generalLedger) <= 0.001;
        });
    }

    private function customerSubledgersReconcileToGl(int $companyId): bool
    {
        return DB::table('customers')
            ->where('company_id', $companyId)
            ->whereNotNull('account_id')
            ->get(['id', 'account_id'])
            ->every(function (object $customer) use ($companyId): bool {
                $invoices = (float) DB::table('customer_invoices')
                    ->where('customer_id', $customer->id)
                    ->where('posting_status', 'posted')
                    ->where('document_type', 'invoice')
                    ->sum('total_amount');
                $credits = (float) DB::table('customer_invoices')
                    ->where('customer_id', $customer->id)
                    ->where('posting_status', 'posted')
                    ->where('document_type', 'credit_note')
                    ->sum('total_amount');
                $receipts = (float) DB::table('customer_receipts')
                    ->where('customer_id', $customer->id)
                    ->where('status', 'approved')->whereNotNull('journal_entry_id')
                    ->sum('amount');
                $refunds = (float) DB::table('customer_credit_refunds')
                    ->where('customer_id', $customer->id)
                    ->where('status', 'posted')
                    ->sum('amount');
                $subledger = $invoices - $credits - $receipts + $refunds;
                $generalLedger = (float) DB::table('journal_entry_lines as lines')
                    ->join('journal_entries as journals', 'journals.id', '=', 'lines.journal_entry_id')
                    ->where('journals.company_id', $companyId)
                    ->whereNull('journals.deleted_at')
                    ->where('journals.is_posted', true)
                    ->where('lines.account_id', $customer->account_id)
                    ->where('lines.customer_id', $customer->id)
                    ->selectRaw('COALESCE(SUM((lines.debit_amount - lines.credit_amount) * journals.exchange_rate), 0) as balance')
                    ->value('balance');

                return abs($subledger - $generalLedger) <= 0.001;
            });
    }

    /** @return array<string, array<string, string>> */
    private function customerBalances(int $companyId): array
    {
        $customerNames = [
            'Fully settled' => 'Giza Homeware Industries - Runtime Demo',
            'Outstanding' => 'Cairo Paints & Coatings - Runtime Demo',
            'Credit balance' => 'October Chemical Industries - Runtime Demo',
        ];

        return collect($customerNames)->mapWithKeys(function (string $name, string $label) use ($companyId): array {
            $customer = DB::table('customers')->where('company_id', $companyId)->where('name', $name)->first();
            if ($customer === null) {
                return [$label => [
                    'customer' => $name,
                    'invoices' => '0.0000',
                    'receipts' => '0.0000',
                    'credits' => '0.0000',
                    'closing_balance' => '0.0000',
                ]];
            }

            $invoices = (float) DB::table('customer_invoices')
                ->where('customer_id', $customer->id)
                ->where('posting_status', 'posted')
                ->where('document_type', 'invoice')
                ->sum('total_amount');
            $credits = (float) DB::table('customer_invoices')
                ->where('customer_id', $customer->id)
                ->where('posting_status', 'posted')
                ->where('document_type', 'credit_note')
                ->sum('total_amount');
            $receipts = (float) DB::table('customer_receipts')
                ->where('customer_id', $customer->id)
                ->where('status', 'approved')->whereNotNull('journal_entry_id')
                ->sum('amount');

            return [$label => [
                'customer' => $name,
                'invoices' => number_format($invoices, 4, '.', ''),
                'receipts' => number_format($receipts, 4, '.', ''),
                'credits' => number_format($credits, 4, '.', ''),
                'closing_balance' => number_format($invoices - $receipts - $credits, 4, '.', ''),
            ]];
        })->all();
    }

    /** @return array<string, array<string, string|null>> */
    private function supplierBalances(int $companyId): array
    {
        $supplierNames = [
            'Supplier A' => 'Egypt Polymers Supply - Runtime Demo',
            'Supplier B' => 'Suez Petrochem Trading - Runtime Demo',
            'Supplier C' => 'Industrial Maintenance Solutions - Runtime Demo',
        ];

        return collect($supplierNames)->mapWithKeys(function (string $name, string $label) use ($companyId): array {
            $supplier = DB::table('suppliers')->where('company_id', $companyId)->where('name', $name)->first();
            if ($supplier === null) {
                return [$label => ['supplier' => $name, 'invoices' => '0.0000', 'paid' => '0.0000', 'returns' => '0.0000', 'outstanding' => '0.0000', 'next_due_date' => null]];
            }
            $invoiceIds = DB::table('purchase_invoices')->where('supplier_id', $supplier->id)
                ->whereIn('status', ['approved', 'closed'])->pluck('id');
            $invoices = (float) DB::table('purchase_invoices')->whereIn('id', $invoiceIds)->sum('total_amount');
            $paid = (float) DB::table('supplier_payment_allocations as allocations')
                ->join('supplier_payment_contexts as payments', 'payments.id', '=', 'allocations.supplier_payment_context_id')
                ->whereIn('allocations.purchase_invoice_id', $invoiceIds)
                ->where('payments.status', 'approved')->sum('allocations.amount');
            $returns = (float) DB::table('purchase_returns')
                ->where('supplier_id', $supplier->id)
                ->whereNotNull('purchase_invoice_id')
                ->where('status', 'posted')
                ->sum('total_amount');
            $nextDueDate = DB::table('purchase_invoice_payment_schedules')
                ->whereIn('purchase_invoice_id', $invoiceIds)
                ->whereRaw('(amount - paid_amount - credited_amount) > 0.001')
                ->orderBy('due_date')->value('due_date');

            return [$label => [
                'supplier' => $name,
                'invoices' => number_format($invoices, 4, '.', ''),
                'paid' => number_format($paid, 4, '.', ''),
                'returns' => number_format($returns, 4, '.', ''),
                'outstanding' => number_format($invoices - $paid - $returns, 4, '.', ''),
                'next_due_date' => $nextDueDate,
            ]];
        })->all();
    }
}
