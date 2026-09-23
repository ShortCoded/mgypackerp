<?php

namespace Modules\Inventory\Services;

use App\Services\PostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryGlReconciliationService
{
    public function __construct(private readonly PostingAccountResolver $accounts) {}

    /** @return list<array{key: string, label: string, subledger: string, gl: string, difference: string, status: string}> */
    public function reconcile(int $companyId, ?int $financialPeriodId = null, ?int $branchId = null): array
    {
        $rawAccountIds = collect([
            $this->accounts->resolve($companyId, PostingAccountResolver::RawMaterialInventory, __('Inventory reconciliation'))->getKey(),
            $this->accounts->resolve($companyId, PostingAccountResolver::PackagingMaterialInventory, __('Inventory reconciliation'))->getKey(),
        ])->unique()->values()->all();

        return [
            $this->row(
                'raw_materials',
                __('Raw materials / packaging inventory'),
                $this->inventoryValue($companyId, [
                    Product::ClassificationRawMaterial,
                    Product::ClassificationPackaging,
                    Product::ClassificationOther,
                ], [InventoryTransaction::StatusProductionStaging, InventoryTransaction::StatusWip], $financialPeriodId, $branchId),
                $this->accountBalance($companyId, $rawAccountIds, $financialPeriodId, $branchId),
            ),
            $this->row(
                'wip',
                __('Production work in process'),
                $this->wipValue($companyId, $financialPeriodId, $branchId),
                $this->accountBalance($companyId, [
                    $this->accounts->resolve($companyId, PostingAccountResolver::WorkInProcessInventory, __('Inventory reconciliation'))->getKey(),
                ], $financialPeriodId, $branchId),
            ),
            $this->row(
                'finished_goods',
                __('Finished goods inventory'),
                $this->inventoryValue($companyId, [Product::ClassificationFinishedProduct], [], $financialPeriodId, $branchId),
                $this->accountBalance($companyId, [
                    $this->accounts->resolve($companyId, PostingAccountResolver::FinishedGoodsInventory, __('Inventory reconciliation'))->getKey(),
                ], $financialPeriodId, $branchId),
            ),
            $this->row(
                'production_waste',
                __('Production waste'),
                $this->eventValue($companyId, [InventoryDocument::TypeProductionWaste], $financialPeriodId, $branchId),
                $this->eventGlValue($companyId, [InventoryDocument::TypeProductionWaste], [
                    $this->accounts->resolve($companyId, PostingAccountResolver::AbnormalWasteLoss, __('Inventory reconciliation'))->getKey(),
                ], $financialPeriodId, $branchId),
            ),
            $this->row(
                'inventory_adjustments',
                __('Inventory adjustments and warehouse scrap'),
                $this->eventValue($companyId, [
                    InventoryDocument::TypeAdjustmentIn,
                    InventoryDocument::TypeAdjustmentOut,
                    InventoryDocument::TypeScrap,
                ], $financialPeriodId, $branchId),
                $this->eventGlValue($companyId, [
                    InventoryDocument::TypeAdjustmentIn,
                    InventoryDocument::TypeAdjustmentOut,
                    InventoryDocument::TypeScrap,
                ], [
                    $this->accounts->resolve($companyId, PostingAccountResolver::InventoryAdjustmentGain, __('Inventory reconciliation'))->getKey(),
                    $this->accounts->resolve($companyId, PostingAccountResolver::InventoryAdjustmentLoss, __('Inventory reconciliation'))->getKey(),
                    $this->accounts->resolve($companyId, PostingAccountResolver::WarehouseDamageLoss, __('Inventory reconciliation'))->getKey(),
                ], $financialPeriodId, $branchId),
            ),
        ];
    }

    /** @param list<string> $classifications @param list<string> $excludedStatuses */
    private function inventoryValue(
        int $companyId,
        array $classifications,
        array $excludedStatuses = [],
        ?int $financialPeriodId = null,
        ?int $branchId = null,
    ): string {
        $value = InventoryTransaction::query()
            ->join('products', 'products.id', '=', 'inventory_transactions.product_id')
            ->where('inventory_transactions.company_id', $companyId)
            ->whereIn('products.item_classification', $classifications)
            ->when($financialPeriodId !== null, fn ($query) => $query->where('inventory_transactions.financial_period_id', $financialPeriodId))
            ->when($branchId !== null, fn ($query) => $query->whereExists(function ($storeQuery) use ($branchId): void {
                $storeQuery->selectRaw('1')
                    ->from('branch_stores')
                    ->whereColumn('branch_stores.id', 'inventory_transactions.branch_store_id')
                    ->where('branch_stores.branch_id', $branchId);
            }))
            ->when($excludedStatuses !== [], fn ($query) => $query->whereNotIn('inventory_transactions.stock_status', $excludedStatuses))
            ->selectRaw('coalesce(sum(case when quantity_in > 0 then total_cost else -total_cost end), 0) as value')
            ->value('value');

        return $this->amount($value);
    }

    private function wipValue(int $companyId, ?int $financialPeriodId, ?int $branchId): string
    {
        $value = DB::table('inventory_document_lines')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->where('inventory_documents.company_id', $companyId)
            ->when($financialPeriodId !== null, fn ($query) => $query->where('inventory_documents.financial_period_id', $financialPeriodId))
            ->when($branchId !== null, fn ($query) => $query->where('inventory_documents.branch_id', $branchId))
            ->where('inventory_documents.status', InventoryDocument::StatusPosted)
            ->where(function ($query): void {
                $query->whereNotNull('inventory_documents.production_run_id')
                    ->orWhereNotNull('inventory_document_lines.production_run_id');
            })
            ->whereNull('inventory_document_lines.deleted_at')
            ->selectRaw(
                'coalesce(sum(case
                    when inventory_documents.document_type in (?, ?) then inventory_document_lines.total_cost
                    when inventory_documents.document_type in (?, ?, ?) then -inventory_document_lines.total_cost
                    else 0 end), 0) as value',
                [
                    InventoryDocument::TypeMaterialIssue,
                    InventoryDocument::TypeAdditionalMaterialIssue,
                    InventoryDocument::TypeMaterialReturn,
                    InventoryDocument::TypeProductionWaste,
                    InventoryDocument::TypeProductionReceipt,
                ],
            )
            ->value('value');

        return $this->amount($value);
    }

    /** @param list<int> $accountIds */
    private function accountBalance(int $companyId, array $accountIds, ?int $financialPeriodId, ?int $branchId): string
    {
        $value = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->when($financialPeriodId !== null, fn ($query) => $query->where('journal_entries.financial_period_id', $financialPeriodId))
            ->when($branchId !== null, fn ($query) => $query->where('journal_entries.branch_id', $branchId))
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)
            ->whereIn('journal_entry_lines.account_id', array_values(array_unique($accountIds)))
            ->selectRaw('coalesce(sum(journal_entry_lines.debit_amount - journal_entry_lines.credit_amount), 0) as value')
            ->value('value');

        return $this->amount($value);
    }

    /** @param list<string> $documentTypes */
    private function eventValue(int $companyId, array $documentTypes, ?int $financialPeriodId, ?int $branchId): string
    {
        $value = DB::table('inventory_document_lines')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->where('inventory_documents.company_id', $companyId)
            ->where('inventory_documents.status', InventoryDocument::StatusPosted)
            ->whereIn('inventory_documents.document_type', $documentTypes)
            ->when($financialPeriodId !== null, fn ($query) => $query->where('inventory_documents.financial_period_id', $financialPeriodId))
            ->when($branchId !== null, fn ($query) => $query->where('inventory_documents.branch_id', $branchId))
            ->whereNull('inventory_document_lines.deleted_at')
            ->sum('inventory_document_lines.total_cost');

        return $this->amount($value);
    }

    /** @param list<string> $documentTypes @param list<int> $accountIds */
    private function eventGlValue(
        int $companyId,
        array $documentTypes,
        array $accountIds,
        ?int $financialPeriodId,
        ?int $branchId,
    ): string {
        $balances = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('inventory_documents', function ($join): void {
                $join->on('inventory_documents.id', '=', 'journal_entries.source_id')
                    ->whereIn('journal_entries.source_type', ['inventory_document_posting', 'inventory_document_reversal']);
            })
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->whereIn('inventory_documents.document_type', $documentTypes)
            ->whereIn('journal_entry_lines.account_id', array_values(array_unique($accountIds)))
            ->when($financialPeriodId !== null, fn ($query) => $query->where('journal_entries.financial_period_id', $financialPeriodId))
            ->when($branchId !== null, fn ($query) => $query->where('journal_entries.branch_id', $branchId))
            ->groupBy('journal_entries.source_id', 'journal_entry_lines.account_id')
            ->selectRaw('sum(journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) as value')
            ->pluck('value');

        $value = $balances->reduce(
            fn (string $total, mixed $balance): string => bcadd($total, ltrim((string) $balance, '-'), 4),
            '0.0000',
        );

        return $this->amount($value);
    }

    private function amount(mixed $value): string
    {
        return number_format(is_numeric($value) ? (float) $value : 0, 4, '.', '');
    }

    /** @return array{key: string, label: string, subledger: string, gl: string, difference: string, status: string} */
    private function row(string $key, string $label, string $subledger, string $gl): array
    {
        $difference = bcsub($subledger, $gl, 4);

        return [
            'key' => $key,
            'label' => $label,
            'subledger' => $subledger,
            'gl' => $gl,
            'difference' => $difference,
            'status' => abs((float) $difference) <= 0.001 ? 'reconciled' : 'difference',
        ];
    }
}
