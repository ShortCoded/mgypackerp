<?php

namespace Modules\Core\Services;

use Database\Seeders\IntegratedPlasticFactorySeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Company;

class IntegratedPlasticFactoryVerifier
{
    /**
     * @return array{ok: bool, company: string|null, checks: array<string, bool>, counts: array<string, int>, documents: array<string, list<string>>, journal_sources: array<string, int>, inventory_balances: array<string, string>}
     */
    public function verify(): array
    {
        $company = Company::query()->where('name', IntegratedPlasticFactorySeeder::CompanyName)->first();
        if (! $company instanceof Company) {
            return $this->missingResult();
        }

        $companyId = (int) $company->getKey();
        $branchIds = DB::table('branches')->where('company_id', $companyId)->pluck('id');
        $storeIds = DB::table('branch_stores')->whereIn('branch_id', $branchIds)->pluck('id');
        $productIds = DB::table('products')->where('company_id', $companyId)->pluck('id');
        $mapping = DB::table('inventory_accounting_mappings')->where('company_id', $companyId)->first();
        $mappingColumns = [
            'raw_material_inventory_account_id', 'packaging_inventory_account_id', 'semi_finished_inventory_account_id',
            'finished_goods_inventory_account_id', 'wip_account_id', 'production_waste_account_id',
            'warehouse_damage_loss_account_id', 'inventory_adjustment_gain_account_id', 'inventory_adjustment_loss_account_id',
            'quarantine_inventory_account_id', 'rework_inventory_account_id', 'grni_account_id',
            'purchase_price_variance_account_id', 'production_cost_center_id',
        ];
        $quotationId = DB::table('quotations')->where('company_id', $companyId)->where('customer_reference', 'CP-PO-DEMO-260705')->value('id');
        $salesOrderId = DB::table('sales_orders')->where('company_id', $companyId)->where('quotation_id', $quotationId)->value('id');
        $invoiceId = DB::table('customer_invoices')->where('company_id', $companyId)->where('sales_order_id', $salesOrderId)->value('id');
        $requisitionId = DB::table('purchase_requisitions')->where('company_id', $companyId)
            ->where('notes', 'like', '%'.IntegratedPlasticFactorySeeder::Marker.'%')->where('status', '<>', 'draft')->value('id');
        $rfqId = DB::table('request_for_quotations')->where('purchase_requisition_id', $requisitionId)->value('id');
        $selectionId = DB::table('supplier_selections')->where('request_for_quotation_id', $rfqId)->value('id');
        $productionOrderId = DB::table('production_orders')->where('company_id', $companyId)
            ->where('production_notes', 'like', '%'.IntegratedPlasticFactorySeeder::Marker.'%')->value('id');

        $checks = [
            'demo_company_exists' => true,
            'required_masters_exist' => $branchIds->isNotEmpty() && $storeIds->isNotEmpty() && $productIds->isNotEmpty(),
            'inventory_accounting_mapping_complete' => $mapping !== null
                && collect($mappingColumns)->every(fn (string $column): bool => $mapping->{$column} !== null),
            'all_journals_balance' => $this->unbalancedJournalCount($companyId) === 0,
            'inventory_has_no_negative_positions' => $this->negativePositionCount($companyId) === 0,
            'sales_lineage_complete' => $quotationId !== null && $salesOrderId !== null && $invoiceId !== null
                && DB::table('inventory_documents')->where('source_document_type', 'Modules\\Sales\\Models\\SalesOrder')->where('source_document_id', $salesOrderId)->exists()
                && DB::table('customer_receipt_allocations')->whereIn('customer_receipt_id', DB::table('customer_receipts')->where('company_id', $companyId)->select('id'))->exists()
                && DB::table('sales_returns')->where('customer_invoice_id', $invoiceId)->whereNotNull('credit_note_id')->exists(),
            'procurement_lineage_complete' => $requisitionId !== null && $rfqId !== null
                && DB::table('supplier_quotations')->where('request_for_quotation_id', $rfqId)->count() >= 2
                && $selectionId !== null
                && DB::table('purchase_orders')->where('supplier_selection_id', $selectionId)->exists()
                && DB::table('unpriced_inventory_receipts')->whereIn('purchase_order_id', DB::table('purchase_orders')->where('supplier_selection_id', $selectionId)->select('id'))->exists()
                && DB::table('purchase_invoices')->whereIn('purchase_order_id', DB::table('purchase_orders')->where('supplier_selection_id', $selectionId)->select('id'))->exists(),
            'production_lineage_complete' => $productionOrderId !== null
                && DB::table('production_runs')->where('production_order_id', $productionOrderId)->exists()
                && DB::table('inventory_documents')->where('production_order_id', $productionOrderId)->exists(),
            'fixed_asset_mapping_complete' => DB::table('fixed_asset_category_mappings')->where('company_id', $companyId)
                ->whereNotNull('accumulated_depreciation_account_id')->whereNotNull('depreciation_expense_account_id')
                ->whereNotNull('disposal_gain_account_id')->whereNotNull('disposal_loss_account_id')->exists(),
            'no_orphan_sales_documents' => DB::table('sales_orders as orders')->leftJoin('quotations', 'quotations.id', '=', 'orders.quotation_id')
                ->where('orders.company_id', $companyId)->whereNotNull('orders.quotation_id')->whereNull('quotations.id')->doesntExist(),
            'no_orphan_procurement_documents' => DB::table('purchase_orders as orders')->leftJoin('suppliers', 'suppliers.id', '=', 'orders.supplier_id')
                ->where('orders.company_id', $companyId)->whereNull('suppliers.id')->doesntExist(),
        ];

        return [
            'ok' => ! in_array(false, $checks, true),
            'company' => $company->name,
            'checks' => $checks,
            'counts' => $this->counts($companyId, $branchIds, $storeIds),
            'documents' => $this->documents($companyId),
            'journal_sources' => DB::table('journal_entries')->where('company_id', $companyId)->whereNull('deleted_at')
                ->selectRaw("coalesce(source_type, 'manual') as source, count(*) as aggregate")
                ->groupBy('source_type')->orderBy('source_type')->pluck('aggregate', 'source')->map(fn (mixed $count): int => (int) $count)->all(),
            'inventory_balances' => DB::table('inventory_transactions as movements')->join('products', 'products.id', '=', 'movements.product_id')
                ->where('movements.company_id', $companyId)->groupBy('products.name')
                ->selectRaw('products.name, sum(movements.quantity_in - movements.quantity_out) as balance')
                ->orderBy('products.name')->pluck('balance', 'products.name')->all(),
        ];
    }

    private function unbalancedJournalCount(int $companyId): int
    {
        $query = DB::table('journal_entries as journals')->join('journal_entry_lines as lines', 'lines.journal_entry_id', '=', 'journals.id')
            ->where('journals.company_id', $companyId)->whereNull('journals.deleted_at')->select('journals.id')
            ->groupBy('journals.id')->havingRaw('abs(sum(lines.debit_amount) - sum(lines.credit_amount)) > 0.001');

        return DB::query()->fromSub($query, 'unbalanced_journals')->count();
    }

    private function negativePositionCount(int $companyId): int
    {
        $query = DB::table('inventory_transactions')->where('company_id', $companyId)
            ->select('branch_store_id', 'product_id', 'stock_status')->groupBy('branch_store_id', 'product_id', 'stock_status')
            ->havingRaw('sum(quantity_in - quantity_out) < -0.000001');

        return DB::query()->fromSub($query, 'negative_positions')->count();
    }

    /** @return array<string, int> */
    private function counts(int $companyId, Collection $branchIds, Collection $storeIds): array
    {
        $companyCount = fn (string $table): int => DB::table($table)->where('company_id', $companyId)->count();

        return [
            'companies' => DB::table('companies')->count(), 'branches' => $companyCount('branches'),
            'halls' => DB::table('branch_halls')->whereIn('branch_id', $branchIds)->count(),
            'stores' => DB::table('branch_stores')->whereIn('branch_id', $branchIds)->count(),
            'cost_centers' => $companyCount('cost_centers'), 'accounts' => $companyCount('accounts'),
            'banks' => $companyCount('bank_accounts'), 'cash_safes' => $companyCount('cashboxes'),
            'customers' => $companyCount('customers'), 'suppliers' => $companyCount('suppliers'),
            'products' => $companyCount('products'), 'units' => $companyCount('item_units'),
            'boms' => DB::table('product_components')->whereIn('product_id', DB::table('products')->where('company_id', $companyId)->select('id'))->distinct('product_id')->count('product_id'),
            'machines' => $companyCount('production_machines'), 'molds' => $companyCount('production_molds'),
            'assets' => $companyCount('fixed_assets'), 'journal_entries' => $companyCount('journal_entries'),
            'inventory_positions' => DB::table('inventory_transactions')->whereIn('branch_store_id', $storeIds)->distinct()->count(DB::raw("concat(product_id, ':', stock_status)")),
        ];
    }

    /** @return array<string, list<string>> */
    private function documents(int $companyId): array
    {
        $numbers = function (string $table) use ($companyId): array {
            $query = DB::table($table)->where('company_id', $companyId);
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            return $query->orderBy('id')->pluck('doc_num')->filter()->values()->all();
        };

        return [
            'quotations' => $numbers('quotations'), 'sales_orders' => $numbers('sales_orders'),
            'deliveries' => DB::table('inventory_documents')->where('company_id', $companyId)->where('document_type', 'sales_delivery')->orderBy('id')->pluck('doc_num')->filter()->values()->all(),
            'customer_invoices' => $numbers('customer_invoices'), 'customer_receipts' => $numbers('customer_receipts'),
            'sales_returns' => $numbers('sales_returns'), 'purchase_requisitions' => $numbers('purchase_requisitions'),
            'rfqs' => $numbers('request_for_quotations'), 'supplier_quotations' => $numbers('supplier_quotations'),
            'supplier_selections' => $numbers('supplier_selections'), 'purchase_orders' => $numbers('purchase_orders'),
            'goods_receipts' => $numbers('unpriced_inventory_receipts'), 'purchase_invoices' => $numbers('purchase_invoices'),
            'supplier_payments' => $numbers('supplier_payment_contexts'), 'purchase_returns' => $numbers('purchase_returns'),
            'production_orders' => $numbers('production_orders'), 'inventory_documents' => $numbers('inventory_documents'),
            'fixed_assets' => $numbers('fixed_assets'),
        ];
    }

    /** @return array{ok: false, company: null, checks: array{demo_company_exists: false}, counts: array{}, documents: array{}, journal_sources: array{}, inventory_balances: array{}} */
    private function missingResult(): array
    {
        return ['ok' => false, 'company' => null, 'checks' => ['demo_company_exists' => false], 'counts' => [], 'documents' => [], 'journal_sources' => [], 'inventory_balances' => []];
    }
}
