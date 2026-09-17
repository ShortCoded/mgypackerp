<?php

namespace App\Services;

use Database\Seeders\FixedAssetsProcurementClientDemoSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\Supplier;

class PlasticFactoryDemoVerifier
{
    /**
     * @return array{
     *     ok: bool,
     *     company: string|null,
     *     checks: array<string, bool>,
     *     counts: array<string, int>,
     *     documents: array<string, list<string>>,
     *     evidence: array<string, string>
     * }
     */
    public function verify(): array
    {
        $company = Company::query()->active()->orderBy('id')->first();

        if (! $company instanceof Company) {
            return $this->missingCompanyResult();
        }

        $companyId = (int) $company->getKey();
        $marker = FixedAssetsProcurementClientDemoSeeder::Marker;
        $goldenAsset = FixedAsset::query()
            ->where('company_id', $companyId)
            ->where('serial_number', FixedAssetsProcurementClientDemoSeeder::GoldenAssetSerial)
            ->first();
        $openingAsset = FixedAsset::query()
            ->where('company_id', $companyId)
            ->where('serial_number', 'CD-ASSET-OPENING-100K')
            ->first();
        $goldenRequisition = PurchaseRequisition::query()
            ->where('company_id', $companyId)
            ->where('notes', FixedAssetsProcurementClientDemoSeeder::GoldenRequisitionNote)
            ->first();
        $rfqId = $goldenRequisition === null
            ? null
            : DB::table('request_for_quotations')->where('purchase_requisition_id', $goldenRequisition->getKey())->value('id');
        $selectionId = $rfqId === null
            ? null
            : DB::table('supplier_selections')->where('request_for_quotation_id', $rfqId)->value('id');
        $orderIds = $selectionId === null
            ? collect()
            : DB::table('purchase_orders')->where('supplier_selection_id', $selectionId)->orderBy('id')->pluck('id');
        $supplierA = Supplier::query()->where('company_id', $companyId)->where('name', 'Nile Polymer Supply — Client Demo')->first();
        $supplierB = Supplier::query()->where('company_id', $companyId)->where('name', 'Suez Petrochem Trading — Client Demo')->first();
        $supplierAOutstanding = $this->supplierOutstanding($companyId, $supplierA?->getKey());
        $supplierBOutstanding = $this->supplierOutstanding($companyId, $supplierB?->getKey());
        $receiptTotals = $this->receiptTotals($orderIds->all());
        $returnTotals = $this->returnTotals($orderIds->all());
        $openingGlDifference = $this->openingAssetGlDifference($openingAsset);
        $unbalancedJournals = $this->unbalancedJournals($companyId);
        $statuses = FixedAsset::query()
            ->where('company_id', $companyId)
            ->where('notes', 'like', "%{$marker}%")
            ->pluck('status')
            ->unique();
        $serviceOrderId = DB::table('purchase_orders')
            ->where('company_id', $companyId)
            ->where('internal_reference', 'CD-SERVICE-PO-001')
            ->value('id');

        $checks = [
            'current_company_identity_preserved' => $company->name !== '',
            'open_and_closed_periods_exist' => DB::table('financial_periods')->where('company_id', $companyId)->where('is_closed', false)->exists()
                && DB::table('financial_periods')->where('company_id', $companyId)->where('is_closed', true)->exists(),
            'factory_admin_stores_and_cost_centers_exist' => DB::table('branches')->where('company_id', $companyId)->where('name', 'like', '%Client Demo%')->count() >= 2
                && DB::table('branch_stores')->whereIn('branch_id', DB::table('branches')->where('company_id', $companyId)->select('id'))->where('name', 'like', '%Client Demo%')->count() >= 3
                && DB::table('cost_centers')->where('company_id', $companyId)->where('notes', 'like', "%{$marker}%")->count() >= 4,
            'fixed_asset_categories_are_mapped' => DB::table('fixed_asset_category_mappings')->where('company_id', $companyId)
                ->whereNotNull('accumulated_depreciation_account_id')->whereNotNull('depreciation_expense_account_id')
                ->whereNotNull('disposal_gain_account_id')->whereNotNull('disposal_loss_account_id')
                ->whereNotNull('disposal_clearing_account_id')->count() >= 5,
            'fixed_asset_register_has_thirteen_examples' => FixedAsset::query()->where('company_id', $companyId)->where('notes', 'like', "%{$marker}%")->count() >= 13,
            'fixed_asset_status_coverage' => collect([
                FixedAsset::StatusDraft, FixedAsset::StatusActive, FixedAsset::StatusFullyDepreciated,
                FixedAsset::StatusSold, FixedAsset::StatusWrittenOff, FixedAsset::StatusSuspended,
            ])->every(fn (string $status): bool => $statuses->contains($status)),
            'golden_asset_lifecycle_complete' => $goldenAsset instanceof FixedAsset
                && (float) $goldenAsset->purchase_value === 120000.0
                && (float) $goldenAsset->salvage_value === 12000.0
                && $goldenAsset->status === FixedAsset::StatusSold
                && DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $goldenAsset->getKey())->where('status', 'posted')->count() === 2
                && DB::table('fixed_asset_movements')->where('fixed_asset_id', $goldenAsset->getKey())->where('movement_type', 'capitalization')->where('status', 'posted')->exists()
                && DB::table('fixed_asset_movements')->where('fixed_asset_id', $goldenAsset->getKey())->where('movement_type', 'transfer')->where('status', 'posted')->exists()
                && DB::table('fixed_asset_disposals')->where('fixed_asset_id', $goldenAsset->getKey())->where('disposition_type', FixedAssetDisposal::TypeSale)->where('status', FixedAssetDisposal::StatusPosted)->exists(),
            'write_off_and_reversal_exist' => DB::table('fixed_asset_disposals')->where('company_id', $companyId)
                ->where('disposition_type', FixedAssetDisposal::TypeWriteOff)->where('status', FixedAssetDisposal::StatusReversed)->exists()
                && DB::table('fixed_asset_disposals')->where('company_id', $companyId)
                    ->where('disposition_type', FixedAssetDisposal::TypeWriteOff)->where('status', FixedAssetDisposal::StatusPosted)->exists(),
            'opening_asset_reconciles_to_gl' => $openingAsset instanceof FixedAsset
                && (float) $openingAsset->purchase_value === 100000.0
                && (float) $openingAsset->previous_depreciation === 40000.0
                && abs((float) $openingGlDifference) < 0.0001,
            'seven_suppliers_and_ten_items_exist' => Supplier::query()->where('company_id', $companyId)->where('notes', 'like', "%{$marker}%")->count() >= 7
                && DB::table('products')->where('company_id', $companyId)->where('notes', 'like', "%{$marker}%")->count() >= 10,
            'procurement_lineage_complete' => $goldenRequisition !== null && $rfqId !== null
                && DB::table('supplier_quotations')->where('request_for_quotation_id', $rfqId)->count() === 2
                && $selectionId !== null && $orderIds->count() === 2,
            'procurement_quantity_reconciliation' => $goldenRequisition !== null
                && (float) DB::table('purchase_requisition_lines')->where('purchase_requisition_id', $goldenRequisition->getKey())->sum('requested_quantity') === 10000.0
                && (float) DB::table('purchase_order_lines')->whereIn('purchase_order_id', $orderIds)->sum('ordered_quantity') === 10000.0
                && $receiptTotals['delivered'] === 10000.0 && $receiptTotals['accepted'] === 9900.0 && $receiptTotals['rejected'] === 100.0,
            'three_grns_and_returns_exist' => DB::table('unpriced_inventory_receipts')->whereIn('purchase_order_id', $orderIds)->count() === 3
                && $returnTotals['pre_invoice'] === 100.0 && $returnTotals['post_invoice'] === 500.0,
            'invoices_installments_and_settlement_reconcile' => DB::table('purchase_invoices')->whereIn('purchase_order_id', $orderIds)->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])->count() === 2
                && DB::table('purchase_invoice_payment_schedules')->whereIn('purchase_invoice_id', DB::table('purchase_invoices')->whereIn('purchase_order_id', $orderIds)->select('id'))->count() === 5
                && abs((float) $supplierAOutstanding) < 0.0001 && abs((float) $supplierBOutstanding - 40000.0) < 0.0001,
            'cleared_supplier_cheque_exists' => DB::table('cheques')->where('company_id', $companyId)->where('cheque_number', 'CD-CHK-B-001')->where('status', 'cleared')->exists(),
            'service_purchase_has_no_grn' => $serviceOrderId !== null
                && DB::table('purchase_invoices')->where('purchase_order_id', $serviceOrderId)->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])->exists()
                && DB::table('unpriced_inventory_receipts')->where('purchase_order_id', $serviceOrderId)->doesntExist(),
            'draft_purchase_order_and_invoice_exist' => DB::table('purchase_orders')->where('company_id', $companyId)
                ->where('notes', FixedAssetsProcurementClientDemoSeeder::DraftPurchaseOrderNote)->where('status', 'draft')->exists()
                && DB::table('purchase_invoices')->where('company_id', $companyId)
                    ->where('notes', FixedAssetsProcurementClientDemoSeeder::DraftInvoiceNote)->where('status', PurchaseInvoice::StatusDraft)->exists(),
            'posted_unpaid_overdue_invoice_exists' => DB::table('purchase_invoices as invoices')
                ->join('purchase_invoice_payment_schedules as schedules', 'schedules.purchase_invoice_id', '=', 'invoices.id')
                ->where('invoices.company_id', $companyId)
                ->where('invoices.notes', FixedAssetsProcurementClientDemoSeeder::UnpaidInvoiceNote)
                ->where('invoices.status', PurchaseInvoice::StatusApproved)
                ->where('invoices.remaining_amount', '>', 0)
                ->whereDate('schedules.due_date', '<', today())
                ->exists(),
            'all_company_journals_balance' => $unbalancedJournals === 0,
        ];

        return [
            'ok' => ! in_array(false, $checks, true),
            'company' => $company->name,
            'checks' => $checks,
            'counts' => [
                'assets' => FixedAsset::query()->where('company_id', $companyId)->where('notes', 'like', "%{$marker}%")->count(),
                'asset_categories' => DB::table('fixed_asset_category_mappings')->where('company_id', $companyId)->count(),
                'suppliers' => Supplier::query()->where('company_id', $companyId)->where('notes', 'like', "%{$marker}%")->count(),
                'products' => DB::table('products')->where('company_id', $companyId)->where('notes', 'like', "%{$marker}%")->count(),
                'purchase_orders' => $orderIds->count(),
                'goods_receipts' => DB::table('unpriced_inventory_receipts')->whereIn('purchase_order_id', $orderIds)->count(),
                'purchase_invoices' => DB::table('purchase_invoices')->whereIn('purchase_order_id', $orderIds)->count(),
                'journal_entries' => DB::table('journal_entries')->where('company_id', $companyId)->whereNull('deleted_at')->count(),
            ],
            'documents' => [
                'fixed_assets' => FixedAsset::query()->where('company_id', $companyId)->where('notes', 'like', "%{$marker}%")->orderBy('id')->pluck('doc_num')->all(),
                'purchase_requisitions' => $goldenRequisition === null ? [] : [$goldenRequisition->doc_num],
                'purchase_orders' => DB::table('purchase_orders')->whereIn('id', $orderIds)->orderBy('id')->pluck('doc_num')->all(),
                'goods_receipts' => DB::table('unpriced_inventory_receipts')->whereIn('purchase_order_id', $orderIds)->orderBy('id')->pluck('doc_num')->all(),
                'purchase_invoices' => DB::table('purchase_invoices')->whereIn('purchase_order_id', $orderIds)->orderBy('id')->pluck('doc_num')->all(),
            ],
            'evidence' => [
                'requested_kg' => number_format($goldenRequisition === null ? 0 : (float) $goldenRequisition->lines()->sum('requested_quantity'), 4, '.', ''),
                'accepted_kg' => number_format($receiptTotals['accepted'], 4, '.', ''),
                'rejected_kg' => number_format($receiptTotals['rejected'], 4, '.', ''),
                'supplier_a_outstanding' => number_format($supplierAOutstanding, 4, '.', ''),
                'supplier_b_outstanding' => number_format($supplierBOutstanding, 4, '.', ''),
                'opening_asset_gl_difference' => number_format($openingGlDifference, 4, '.', ''),
                'unbalanced_journals' => (string) $unbalancedJournals,
            ],
        ];
    }

    /** @return array{ok: false, company: null, checks: array<string, bool>, counts: array<string, int>, documents: array<string, list<string>>, evidence: array<string, string>} */
    private function missingCompanyResult(): array
    {
        return ['ok' => false, 'company' => null, 'checks' => ['active_company_exists' => false], 'counts' => [], 'documents' => [], 'evidence' => []];
    }

    private function supplierOutstanding(int $companyId, ?int $supplierId): float
    {
        return $supplierId === null ? 0.0 : (float) DB::table('purchase_invoices')
            ->where('company_id', $companyId)->where('supplier_id', $supplierId)
            ->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])
            ->sum('remaining_amount');
    }

    /** @param list<int> $orderIds @return array{delivered: float, accepted: float, rejected: float} */
    private function receiptTotals(array $orderIds): array
    {
        $totals = DB::table('unpriced_inventory_receipt_lines as lines')
            ->join('unpriced_inventory_receipts as receipts', 'receipts.id', '=', 'lines.receipt_id')
            ->whereIn('receipts.purchase_order_id', $orderIds)
            ->selectRaw('coalesce(sum(lines.delivered_quantity), 0) delivered, coalesce(sum(lines.accepted_quantity), 0) accepted, coalesce(sum(lines.rejected_quantity), 0) rejected')
            ->first();

        return ['delivered' => (float) ($totals->delivered ?? 0), 'accepted' => (float) ($totals->accepted ?? 0), 'rejected' => (float) ($totals->rejected ?? 0)];
    }

    /** @param list<int> $orderIds @return array{pre_invoice: float, post_invoice: float} */
    private function returnTotals(array $orderIds): array
    {
        return [
            'pre_invoice' => (float) DB::table('purchase_returns')->whereIn('purchase_order_id', $orderIds)->whereNull('purchase_invoice_id')->where('status', 'posted')->sum('total_quantity'),
            'post_invoice' => (float) DB::table('purchase_returns')->whereIn('purchase_order_id', $orderIds)->whereNotNull('purchase_invoice_id')->where('status', 'posted')->sum('total_quantity'),
        ];
    }

    private function openingAssetGlDifference(?FixedAsset $asset): float
    {
        if (! $asset instanceof FixedAsset) {
            return INF;
        }

        $entryId = DB::table('fixed_asset_movements')
            ->where('fixed_asset_id', $asset->getKey())
            ->where('movement_type', 'opening')
            ->where('status', 'posted')
            ->value('journal_entry_id');

        if ($entryId === null) {
            return INF;
        }

        return (float) DB::table('journal_entry_lines')->where('journal_entry_id', $entryId)->sum('debit_amount')
            - (float) DB::table('journal_entry_lines')->where('journal_entry_id', $entryId)->sum('credit_amount');
    }

    private function unbalancedJournals(int $companyId): int
    {
        return DB::table('journal_entries as journals')
            ->join('journal_entry_lines as lines', 'lines.journal_entry_id', '=', 'journals.id')
            ->where('journals.company_id', $companyId)->whereNull('journals.deleted_at')
            ->select('journals.id')->groupBy('journals.id')
            ->havingRaw('abs(sum(lines.debit_amount) - sum(lines.credit_amount)) > 0.001')->count();
    }
}
