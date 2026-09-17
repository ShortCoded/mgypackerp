<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryValuationService
{
    public const Method = 'moving_average';

    private const CalculationScale = 8;

    public function movingAverageUnitCost(
        int $companyId,
        int $branchStoreId,
        int $productId,
        ?string $stockStatus = null,
        ?int $warehouseLocationId = null,
        ?string $batchLot = null,
        ?int $productionRunId = null,
        mixed $asOfDate = null,
    ): string {
        $totals = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->when($stockStatus !== null, fn (Builder $query) => $query->where('stock_status', $stockStatus))
            ->when($warehouseLocationId !== null, fn (Builder $query) => $query->where('warehouse_location_id', $warehouseLocationId))
            ->when($batchLot !== null, fn (Builder $query) => $query->where('batch_lot', $batchLot))
            ->when($productionRunId !== null, fn (Builder $query) => $query->where('production_run_id', $productionRunId))
            ->when($asOfDate !== null, fn (Builder $query) => $query->whereDate('transaction_date', '<=', $asOfDate))
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity')
            ->selectRaw('coalesce(sum(case when quantity_in > 0 then total_cost else -total_cost end), 0) as value')
            ->first();

        if ($totals === null
            || bccomp((string) $totals->quantity, '0', 8) <= 0
            || bccomp((string) $totals->value, '0', 8) <= 0) {
            return '0.00000000';
        }

        return bcdiv((string) $totals->value, (string) $totals->quantity, 8);
    }

    /**
     * Compare alternative valuation methods without changing the inventory ledger.
     *
     * @return array<string, mixed>
     */
    public function comparisonForStockPosition(
        int $companyId,
        int $financialPeriodId,
        int $branchId,
        int $branchStoreId,
        int $productId,
        mixed $asOfDate,
    ): array {
        $transactions = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $financialPeriodId)
            ->where('branch_id', $branchId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->whereDate('transaction_date', '<=', $asOfDate)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $comparison = $this->compareMovements($transactions->map(fn (InventoryTransaction $transaction): array => [
            'quantity_in' => (string) $transaction->quantity_in,
            'quantity_out' => (string) $transaction->quantity_out,
            'unit_cost' => $transaction->unit_cost,
            'total_cost' => $transaction->total_cost,
        ])->all());

        return [
            ...$comparison,
            'as_of' => (string) $asOfDate,
            'source_count' => $transactions->count(),
            'sources' => $transactions->map(fn (InventoryTransaction $transaction): array => [
                'date' => $transaction->transaction_date?->toDateString(),
                'document' => $transaction->source_doc_num,
                'type' => $transaction->transaction_type,
                'quantity_in' => (string) $transaction->quantity_in,
                'quantity_out' => (string) $transaction->quantity_out,
                'unit_cost' => $transaction->unit_cost,
                'total_cost' => $transaction->total_cost,
            ])->all(),
        ];
    }

    /**
     * @param  iterable<array{quantity_in: mixed, quantity_out: mixed, unit_cost?: mixed, total_cost?: mixed}>  $movements
     * @return array{
     *     available_quantity: string,
     *     available_cost: string,
     *     issued_quantity: string,
     *     ending_quantity: string,
     *     methods: array<string, array{issue_cost: ?string, ending_value: string, ending_unit_cost: string, book_method: bool, reference_only: bool}>
     * }
     */
    public function compareMovements(iterable $movements): array
    {
        $availableQuantity = $issuedQuantity = $movingQuantity = $movingValue = '0.00000000';
        $availableCost = $movingIssueCost = $fifoIssueCost = '0.00000000';
        $lastReceiptUnitCost = '0.00000000';
        $fifoLayers = new Collection;

        foreach ($movements as $movement) {
            $quantityIn = $this->decimal($movement['quantity_in'] ?? 0);
            $quantityOut = $this->decimal($movement['quantity_out'] ?? 0);

            if (bccomp($quantityIn, '0', self::CalculationScale) < 0
                || bccomp($quantityOut, '0', self::CalculationScale) < 0
                || (bccomp($quantityIn, '0', self::CalculationScale) > 0 && bccomp($quantityOut, '0', self::CalculationScale) > 0)) {
                throw new DomainException('inventory_accounting.errors.invalid_valuation_movement');
            }

            if (bccomp($quantityIn, '0', self::CalculationScale) > 0) {
                $receiptCost = $this->receiptCost($movement, $quantityIn);
                $receiptUnitCost = bcdiv($receiptCost, $quantityIn, self::CalculationScale);

                $availableQuantity = bcadd($availableQuantity, $quantityIn, self::CalculationScale);
                $availableCost = bcadd($availableCost, $receiptCost, self::CalculationScale);
                $movingQuantity = bcadd($movingQuantity, $quantityIn, self::CalculationScale);
                $movingValue = bcadd($movingValue, $receiptCost, self::CalculationScale);
                $lastReceiptUnitCost = $receiptUnitCost;
                $fifoLayers->push([
                    'quantity' => $quantityIn,
                    'unit_cost' => $receiptUnitCost,
                ]);

                continue;
            }

            if (bccomp($quantityOut, '0', self::CalculationScale) === 0) {
                continue;
            }

            if (bccomp($movingQuantity, $quantityOut, self::CalculationScale) < 0) {
                throw new DomainException('inventory_accounting.errors.negative_valuation_stock');
            }

            $movingUnitCost = bcdiv($movingValue, $movingQuantity, self::CalculationScale);
            $movementIssueCost = bcmul($quantityOut, $movingUnitCost, self::CalculationScale);
            $movingIssueCost = bcadd($movingIssueCost, $movementIssueCost, self::CalculationScale);
            $movingQuantity = bcsub($movingQuantity, $quantityOut, self::CalculationScale);
            $movingValue = bcsub($movingValue, $movementIssueCost, self::CalculationScale);
            $issuedQuantity = bcadd($issuedQuantity, $quantityOut, self::CalculationScale);
            $fifoIssueCost = bcadd(
                $fifoIssueCost,
                $this->consumeFifoLayers($fifoLayers, $quantityOut),
                self::CalculationScale,
            );
        }

        $endingQuantity = bcsub($availableQuantity, $issuedQuantity, self::CalculationScale);
        $periodicUnitCost = bccomp($availableQuantity, '0', self::CalculationScale) > 0
            ? bcdiv($availableCost, $availableQuantity, self::CalculationScale)
            : '0.00000000';
        $periodicIssueCost = bcmul($issuedQuantity, $periodicUnitCost, self::CalculationScale);
        $periodicEndingValue = bcsub($availableCost, $periodicIssueCost, self::CalculationScale);
        $fifoEndingValue = $fifoLayers->reduce(
            fn (string $total, array $layer): string => bcadd(
                $total,
                bcmul($layer['quantity'], $layer['unit_cost'], self::CalculationScale),
                self::CalculationScale,
            ),
            '0.00000000',
        );
        $lastReceiptEndingValue = bcmul($endingQuantity, $lastReceiptUnitCost, self::CalculationScale);

        return [
            'available_quantity' => $availableQuantity,
            'available_cost' => $availableCost,
            'issued_quantity' => $issuedQuantity,
            'ending_quantity' => $endingQuantity,
            'methods' => [
                'moving_average' => $this->methodResult($movingIssueCost, $movingValue, $endingQuantity, true),
                'periodic_weighted_average' => $this->methodResult($periodicIssueCost, $periodicEndingValue, $endingQuantity),
                'fifo' => $this->methodResult($fifoIssueCost, $fifoEndingValue, $endingQuantity),
                'last_purchase_reference' => $this->methodResult(null, $lastReceiptEndingValue, $endingQuantity, false, true),
            ],
        ];
    }

    /** @param array<string, mixed> $movement */
    private function receiptCost(array $movement, string $quantity): string
    {
        if (isset($movement['total_cost']) && $movement['total_cost'] !== '') {
            $cost = $this->decimal($movement['total_cost']);
        } elseif (isset($movement['unit_cost']) && $movement['unit_cost'] !== '') {
            $cost = bcmul($quantity, $this->decimal($movement['unit_cost']), self::CalculationScale);
        } else {
            throw new DomainException('inventory_accounting.errors.unvalued_receipt');
        }

        if (bccomp($cost, '0', self::CalculationScale) < 0) {
            throw new DomainException('inventory_accounting.errors.invalid_valuation_cost');
        }

        return $cost;
    }

    /** @param Collection<int, array{quantity: string, unit_cost: string}> $layers */
    private function consumeFifoLayers(Collection $layers, string $quantity): string
    {
        $remaining = $quantity;
        $issueCost = '0.00000000';

        while (bccomp($remaining, '0', self::CalculationScale) > 0) {
            $layer = $layers->shift();

            if (! is_array($layer)) {
                throw new DomainException('inventory_accounting.errors.negative_valuation_stock');
            }

            $consumed = bccomp($layer['quantity'], $remaining, self::CalculationScale) <= 0
                ? $layer['quantity']
                : $remaining;
            $issueCost = bcadd(
                $issueCost,
                bcmul($consumed, $layer['unit_cost'], self::CalculationScale),
                self::CalculationScale,
            );
            $remaining = bcsub($remaining, $consumed, self::CalculationScale);
            $layerRemainder = bcsub($layer['quantity'], $consumed, self::CalculationScale);

            if (bccomp($layerRemainder, '0', self::CalculationScale) > 0) {
                $layers->prepend([
                    'quantity' => $layerRemainder,
                    'unit_cost' => $layer['unit_cost'],
                ]);
            }
        }

        return $issueCost;
    }

    /**
     * @return array{issue_cost: ?string, ending_value: string, ending_unit_cost: string, book_method: bool, reference_only: bool}
     */
    private function methodResult(
        ?string $issueCost,
        string $endingValue,
        string $endingQuantity,
        bool $bookMethod = false,
        bool $referenceOnly = false,
    ): array {
        return [
            'issue_cost' => $issueCost,
            'ending_value' => $endingValue,
            'ending_unit_cost' => bccomp($endingQuantity, '0', self::CalculationScale) > 0
                ? bcdiv($endingValue, $endingQuantity, self::CalculationScale)
                : '0.00000000',
            'book_method' => $bookMethod,
            'reference_only' => $referenceOnly,
        ];
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new DomainException('inventory_accounting.errors.invalid_valuation_movement');
        }

        return bcadd((string) $value, '0', self::CalculationScale);
    }
}
