<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryPositionReconciliationService
{
    public function reconcileNegativeNullBatchPosition(
        int $companyId,
        int $financialPeriodId,
        int $branchId,
        int $branchStoreId,
        int $productId,
        string $transactionDate,
        string $reference,
    ): int {
        return DB::transaction(function () use (
            $companyId,
            $financialPeriodId,
            $branchId,
            $branchStoreId,
            $productId,
            $transactionDate,
            $reference,
        ): int {
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($financialPeriodId);
            $store = BranchStore::query()->lockForUpdate()->findOrFail($branchStoreId);
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            if ((int) $period->company_id !== $companyId || $period->is_closed
                || (int) $store->branch_id !== $branchId || (int) $product->company_id !== $companyId) {
                throw new DomainException('Inventory position reconciliation requires an open, related operating context.');
            }

            InventoryTransaction::query()
                ->where('company_id', $companyId)
                ->where('branch_store_id', $branchStoreId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->get(['id']);
            $quantityBefore = $this->totalQuantity($companyId, $branchStoreId, $productId);
            $negativeNullBatch = $this->nullBatchQuantity($companyId, $branchStoreId, $productId);
            if (bccomp($negativeNullBatch, '0', 8) >= 0) {
                return 0;
            }

            $remaining = bcmul($negativeNullBatch, '-1', 8);
            $positions = InventoryTransaction::query()
                ->where('company_id', $companyId)
                ->where('branch_store_id', $branchStoreId)
                ->where('product_id', $productId)
                ->where('stock_status', InventoryTransaction::StatusAvailable)
                ->whereNotNull('batch_lot')
                ->groupBy('batch_lot')
                ->havingRaw('sum(quantity_in - quantity_out) > 0')
                ->orderByRaw('min(transaction_date), min(id)')
                ->get(['batch_lot', DB::raw('sum(quantity_in - quantity_out) as quantity')]);
            $createdTransactions = 0;

            foreach ($positions as $position) {
                if (bccomp($remaining, '0', 8) <= 0) {
                    break;
                }

                $quantity = bccomp($remaining, (string) $position->quantity, 8) > 0
                    ? (string) $position->quantity
                    : $remaining;
                $unitCost = (string) (InventoryTransaction::query()
                    ->where('company_id', $companyId)
                    ->where('branch_store_id', $branchStoreId)
                    ->where('product_id', $productId)
                    ->where('batch_lot', $position->batch_lot)
                    ->where('unit_cost', '>', 0)
                    ->latest('id')
                    ->value('unit_cost') ?? '0');
                $totalCost = bcmul($quantity, $unitCost, 8);
                $key = hash('sha256', $reference.'|'.$position->batch_lot);
                $attributes = [
                    'company_id' => $companyId,
                    'financial_period_id' => $financialPeriodId,
                    'branch_id' => $branchId,
                    'branch_store_id' => $branchStoreId,
                    'stock_status' => InventoryTransaction::StatusAvailable,
                    'transaction_date' => $transactionDate,
                    'transaction_type' => InventoryTransaction::TypePositionReconciliation,
                    'product_id' => $productId,
                    'unit_id' => $product->item_unit_id,
                    'source_type' => self::class,
                    'source_id' => $productId,
                    'source_doc_num' => $reference,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'notes' => 'Historical stock-position reconciliation; aggregate quantity, value, and receipt-layer availability are unchanged.',
                    'created_by' => auth()->id(),
                ];
                $outbound = InventoryTransaction::query()->firstOrCreate(
                    ['posting_key' => "position-reconciliation:{$key}:out"],
                    [...$attributes, 'batch_lot' => $position->batch_lot, 'quantity_in' => 0, 'quantity_out' => $quantity],
                );
                $inbound = InventoryTransaction::query()->firstOrCreate(
                    ['posting_key' => "position-reconciliation:{$key}:in"],
                    [...$attributes, 'batch_lot' => null, 'quantity_in' => $quantity, 'quantity_out' => 0],
                );
                $createdTransactions += (int) $outbound->wasRecentlyCreated + (int) $inbound->wasRecentlyCreated;
                $remaining = bcsub($remaining, $quantity, 8);
            }

            if (bccomp($remaining, '0', 8) > 0
                || bccomp($quantityBefore, $this->totalQuantity($companyId, $branchStoreId, $productId), 8) !== 0
                || bccomp($this->nullBatchQuantity($companyId, $branchStoreId, $productId), '0', 8) < 0) {
                throw new DomainException('Inventory position reconciliation did not preserve and balance the stock ledger.');
            }

            return $createdTransactions;
        }, 3);
    }

    private function totalQuantity(int $companyId, int $branchStoreId, int $productId): string
    {
        return (string) InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity')
            ->value('quantity');
    }

    private function nullBatchQuantity(int $companyId, int $branchStoreId, int $productId): string
    {
        return (string) InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->where('stock_status', InventoryTransaction::StatusAvailable)
            ->whereNull('batch_lot')
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity')
            ->value('quantity');
    }
}
