<?php

namespace Modules\Inventory\Services;

use Modules\Core\Services\NumericFormatService;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryAvailabilityService
{
    public function __construct(
        private readonly InventoryValuationService $valuation,
        private readonly NumericFormatService $numbers,
    ) {}

    /** @return array{on_hand: string, reserved: string, available: string, physical_on_hand: string} */
    public function forProduct(
        int $companyId,
        int $branchStoreId,
        int $productId,
        ?int $exceptOrderLineId = null,
        ?int $warehouseLocationId = null,
        string $stockStatus = InventoryTransaction::StatusAvailable,
        ?string $batchLot = null,
        bool $exactDimensions = false,
    ): array {
        $positionQuery = InventoryTransaction::query()
            ->where('company_id', $companyId)->where('branch_store_id', $branchStoreId)->where('product_id', $productId)
            ->when(
                $warehouseLocationId !== null,
                fn ($query) => $query->where('warehouse_location_id', $warehouseLocationId),
                fn ($query) => $exactDimensions ? $query->whereNull('warehouse_location_id') : $query,
            )
            ->when(
                $batchLot !== null,
                fn ($query) => $query->where('batch_lot', $batchLot),
                fn ($query) => $exactDimensions ? $query->whereNull('batch_lot') : $query,
            );
        $physicalOnHand = (clone $positionQuery)
            ->selectRaw('round(coalesce(sum(quantity_in - quantity_out), 0), 8) as on_hand')->value('on_hand') ?? '0';
        $stock = $positionQuery->where('stock_status', $stockStatus)
            ->selectRaw('round(coalesce(sum(quantity_in - quantity_out), 0), 8) as on_hand')->value('on_hand') ?? '0';

        $reservedQuery = InventoryReservation::query()
            ->where('company_id', $companyId)->where('branch_store_id', $branchStoreId)->where('product_id', $productId)
            ->where('stock_status', $stockStatus)
            ->when(
                $warehouseLocationId !== null,
                fn ($query) => $query->where(function ($reservationQuery) use ($warehouseLocationId): void {
                    $reservationQuery->where('warehouse_location_id', $warehouseLocationId)->orWhereNull('warehouse_location_id');
                }),
                fn ($query) => $exactDimensions ? $query->whereNull('warehouse_location_id') : $query,
            )
            ->when(
                $batchLot !== null,
                fn ($query) => $query->where(function ($reservationQuery) use ($batchLot): void {
                    $reservationQuery->where('batch_lot', $batchLot)->orWhereNull('batch_lot');
                }),
                fn ($query) => $exactDimensions ? $query->whereNull('batch_lot') : $query,
            )
            ->where('status', InventoryReservation::StatusActive);
        if ($exceptOrderLineId !== null) {
            $reservedQuery->where(fn ($query) => $query
                ->whereNull('sales_order_line_id')
                ->orWhere('sales_order_line_id', '<>', $exceptOrderLineId));
        }
        $reserved = $reservedQuery->selectRaw('round(coalesce(sum(quantity - consumed_quantity - released_quantity), 0), 8) as reserved')->value('reserved') ?? '0';

        $stock = $this->quantity($stock);
        $reserved = $this->quantity($reserved);

        return [
            'on_hand' => $stock,
            'reserved' => $reserved,
            'available' => bcsub($stock, $reserved, 8),
            'physical_on_hand' => $this->quantity($physicalOnHand),
        ];
    }

    /** @return array<string, string> */
    public function statusPosition(int $companyId, int $branchStoreId, int $productId): array
    {
        return InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->groupBy('stock_status')
            ->selectRaw('stock_status, round(coalesce(sum(quantity_in - quantity_out), 0), 8) as quantity')
            ->pluck('quantity', 'stock_status')
            ->map(fn (mixed $quantity): string => $this->quantity($quantity))
            ->all();
    }

    private function quantity(mixed $value): string
    {
        return bcadd($this->numbers->normalizeScientificNotation((string) ($value ?? '0')) ?? '0', '0', 8);
    }

    public function averageCost(int $companyId, int $branchStoreId, int $productId): string
    {
        return $this->valuation->movingAverageUnitCost($companyId, $branchStoreId, $productId);
    }
}
