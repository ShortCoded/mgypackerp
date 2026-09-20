<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryDocument;
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
        bool $exactDimensions = false,
    ): string {
        return $this->bookUnitCostForPosition(
            $companyId,
            $branchStoreId,
            $productId,
            $stockStatus,
            $warehouseLocationId,
            $batchLot,
            $productionRunId,
            $asOfDate,
            $exactDimensions,
        ) ?? '0.00000000';
    }

    public function bookUnitCostForPosition(
        int $companyId,
        int $branchStoreId,
        int $productId,
        ?string $stockStatus = null,
        ?int $warehouseLocationId = null,
        ?string $batchLot = null,
        ?int $productionRunId = null,
        mixed $asOfDate = null,
        bool $exactDimensions = false,
    ): ?string {
        $totals = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->when($stockStatus !== null, fn (Builder $query) => $query->where('stock_status', $stockStatus))
            ->when(
                $warehouseLocationId !== null,
                fn (Builder $query) => $query->where('warehouse_location_id', $warehouseLocationId),
                fn (Builder $query) => $exactDimensions ? $query->whereNull('warehouse_location_id') : $query,
            )
            ->when(
                $batchLot !== null,
                fn (Builder $query) => $query->where('batch_lot', $batchLot),
                fn (Builder $query) => $exactDimensions ? $query->whereNull('batch_lot') : $query,
            )
            ->when(
                $productionRunId !== null,
                fn (Builder $query) => $query->where('production_run_id', $productionRunId),
                fn (Builder $query) => $exactDimensions ? $query->whereNull('production_run_id') : $query,
            )
            ->when($asOfDate !== null, fn (Builder $query) => $query->whereDate('transaction_date', '<=', $asOfDate))
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity')
            ->selectRaw('coalesce(sum(case
                when unit_cost is not null and total_cost is not null then case when quantity_in > 0 then total_cost else -total_cost end
                else 0 end), 0) as value')
            ->selectRaw('coalesce(sum(case when unit_cost is null or total_cost is null then quantity_in - quantity_out else 0 end), 0) as unvalued_quantity')
            ->first();

        if ($totals === null
            || bccomp((string) $totals->quantity, '0', 8) <= 0
            || bccomp((string) $totals->unvalued_quantity, '0', 8) !== 0
            || bccomp((string) $totals->value, '0', 8) < 0) {
            return null;
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
        string $referenceMethod = self::Method,
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

        if ($transactions->isEmpty()) {
            throw new DomainException('inventory_accounting.errors.no_valuation_movements');
        }

        $documentUrls = auth()->user()?->can('inventory.documents.view')
            ? InventoryDocument::withTrashed()
                ->where('company_id', $companyId)
                ->where('financial_period_id', $financialPeriodId)
                ->whereIn('doc_num', $transactions->pluck('source_doc_num')->filter()->unique())
                ->get()
                ->mapWithKeys(fn (InventoryDocument $document): array => [
                    $document->doc_num => route('admin.inventory.documents.show', $document),
                ])
            : collect();

        $positions = $transactions
            ->groupBy(fn (InventoryTransaction $transaction): string => implode(':', [
                $transaction->stock_status,
                $transaction->warehouse_location_id ?? 'none',
                $transaction->batch_lot ?? 'none',
                $transaction->production_run_id ?? 'none',
            ]))
            ->map(function (Collection $positionTransactions, string $positionKey) use ($referenceMethod): array {
                /** @var InventoryTransaction $first */
                $first = $positionTransactions->first();

                return [
                    'key' => $positionKey,
                    'stock_status' => $first->stock_status,
                    'warehouse_location_id' => $first->warehouse_location_id,
                    'batch_lot' => $first->batch_lot,
                    'production_run_id' => $first->production_run_id,
                    ...$this->compareMovements(
                        $positionTransactions->map(fn (InventoryTransaction $transaction): array => $this->comparisonMovement($transaction))->all(),
                        $referenceMethod,
                    ),
                ];
            })
            ->values();
        $comparison = $this->aggregatePositionComparisons($positions, $referenceMethod);

        return [
            ...$comparison,
            'as_of' => (string) $asOfDate,
            'source_count' => $transactions->count(),
            'sources' => $transactions->map(fn (InventoryTransaction $transaction): array => [
                'date' => $transaction->transaction_date?->toDateString(),
                'document' => $transaction->source_doc_num,
                'document_url' => $documentUrls[$transaction->source_doc_num] ?? null,
                'type' => $transaction->transaction_type,
                'stock_status' => $transaction->stock_status,
                'warehouse_location_id' => $transaction->warehouse_location_id,
                'batch_lot' => $transaction->batch_lot,
                'production_run_id' => $transaction->production_run_id,
                'quantity_in' => (string) $transaction->quantity_in,
                'quantity_out' => (string) $transaction->quantity_out,
                'unit_cost' => $transaction->unit_cost,
                'total_cost' => $transaction->total_cost,
                'counts_as_consumption' => $this->countsAsConsumption($transaction),
            ])->all(),
        ];
    }

    /**
     * @param  iterable<array{quantity_in: mixed, quantity_out: mixed, unit_cost?: mixed, total_cost?: mixed, type?: string, counts_as_consumption?: bool}>  $movements
     * @return array{
     *     available_quantity: string,
     *     available_cost: string,
     *     issued_quantity: string,
     *     ending_quantity: string,
     *     methods: array<string, array{issue_cost: ?string, ending_value: string, ending_unit_cost: string, difference_vs_reference: ?string, book_method: bool, reference_only: bool}>
     * }
     */
    public function compareMovements(iterable $movements, string $referenceMethod = self::Method): array
    {
        $this->assertReferenceMethod($referenceMethod);
        $availableQuantity = $issuedQuantity = $movingQuantity = $movingValue = '0.00000000';
        $availableCost = $consumedQuantity = $movingIssueCost = $fifoIssueCost = '0.00000000';
        $lastReceiptUnitCost = '0.00000000';
        $fifoLayers = new Collection;
        $checkpoints = [];

        foreach ($movements as $sequence => $movement) {
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
                $checkpoints[] = $this->checkpoint(
                    $sequence,
                    $movement,
                    $movingQuantity,
                    $movingValue,
                    $receiptUnitCost,
                );

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
            $movingQuantity = bcsub($movingQuantity, $quantityOut, self::CalculationScale);
            $movingValue = bcsub($movingValue, $movementIssueCost, self::CalculationScale);
            $issuedQuantity = bcadd($issuedQuantity, $quantityOut, self::CalculationScale);
            $fifoMovementIssueCost = $this->consumeFifoLayers($fifoLayers, $quantityOut);

            if ((bool) ($movement['counts_as_consumption'] ?? true)) {
                $consumedQuantity = bcadd($consumedQuantity, $quantityOut, self::CalculationScale);
                $movingIssueCost = bcadd($movingIssueCost, $movementIssueCost, self::CalculationScale);
                $fifoIssueCost = bcadd($fifoIssueCost, $fifoMovementIssueCost, self::CalculationScale);
            }

            $checkpoints[] = $this->checkpoint($sequence, $movement, $movingQuantity, $movingValue);
        }

        $endingQuantity = bcsub($availableQuantity, $issuedQuantity, self::CalculationScale);
        $periodicUnitCost = bccomp($availableQuantity, '0', self::CalculationScale) > 0
            ? bcdiv($availableCost, $availableQuantity, self::CalculationScale)
            : '0.00000000';
        $periodicIssueCost = bcmul($consumedQuantity, $periodicUnitCost, self::CalculationScale);
        $periodicOutboundCost = bcmul($issuedQuantity, $periodicUnitCost, self::CalculationScale);
        $periodicEndingValue = bcsub($availableCost, $periodicOutboundCost, self::CalculationScale);
        $fifoEndingValue = $fifoLayers->reduce(
            fn (string $total, array $layer): string => bcadd(
                $total,
                bcmul($layer['quantity'], $layer['unit_cost'], self::CalculationScale),
                self::CalculationScale,
            ),
            '0.00000000',
        );
        $lastReceiptEndingValue = bcmul($endingQuantity, $lastReceiptUnitCost, self::CalculationScale);

        $methods = [
            'moving_average' => $this->methodResult($movingIssueCost, $movingValue, $endingQuantity, true),
            'periodic_weighted_average' => $this->methodResult($periodicIssueCost, $periodicEndingValue, $endingQuantity),
            'fifo' => $this->methodResult($fifoIssueCost, $fifoEndingValue, $endingQuantity),
            'last_purchase_reference' => $this->methodResult(null, $lastReceiptEndingValue, $endingQuantity, false, true),
        ];
        $methods = $this->withReferenceDifferences($methods, $referenceMethod);

        return [
            'available_quantity' => $availableQuantity,
            'available_cost' => $availableCost,
            'issued_quantity' => $issuedQuantity,
            'consumed_quantity' => $consumedQuantity,
            'ending_quantity' => $endingQuantity,
            'reference_method' => $referenceMethod,
            'methods' => $methods,
            'checkpoints' => $checkpoints,
            'remaining_fifo_layers' => $fifoLayers->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function comparisonMovement(InventoryTransaction $transaction): array
    {
        return [
            'quantity_in' => (string) $transaction->quantity_in,
            'quantity_out' => (string) $transaction->quantity_out,
            'unit_cost' => $transaction->unit_cost,
            'total_cost' => $transaction->total_cost,
            'type' => $transaction->transaction_type,
            'counts_as_consumption' => $this->countsAsConsumption($transaction),
        ];
    }

    private function countsAsConsumption(InventoryTransaction $transaction): bool
    {
        if (bccomp((string) $transaction->quantity_out, '0', self::CalculationScale) <= 0) {
            return false;
        }

        return ! in_array($transaction->transaction_type, [
            InventoryDocument::TypeTransfer,
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue,
            InventoryDocument::TypeMaterialReturn,
            InventoryDocument::TypeDamage,
            InventoryTransaction::TypePositionReconciliation,
        ], true);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $positions
     * @return array<string, mixed>
     */
    private function aggregatePositionComparisons(Collection $positions, string $referenceMethod): array
    {
        $methodKeys = ['moving_average', 'periodic_weighted_average', 'fifo', 'last_purchase_reference'];
        $methods = collect($methodKeys)->mapWithKeys(function (string $method) use ($positions): array {
            $issueCost = $method === 'last_purchase_reference'
                ? null
                : $this->sumPositionValue($positions, "methods.{$method}.issue_cost");
            $endingValue = $this->sumPositionValue($positions, "methods.{$method}.ending_value");
            $endingQuantity = $this->sumPositionValue($positions, 'ending_quantity');

            return [$method => $this->methodResult(
                $issueCost,
                $endingValue,
                $endingQuantity,
                $method === self::Method,
                $method === 'last_purchase_reference',
            )];
        })->all();

        return [
            'available_quantity' => $this->sumPositionValue($positions, 'available_quantity'),
            'available_cost' => $this->sumPositionValue($positions, 'available_cost'),
            'issued_quantity' => $this->sumPositionValue($positions, 'issued_quantity'),
            'consumed_quantity' => $this->sumPositionValue($positions, 'consumed_quantity'),
            'ending_quantity' => $this->sumPositionValue($positions, 'ending_quantity'),
            'reference_method' => $referenceMethod,
            'methods' => $this->withReferenceDifferences($methods, $referenceMethod),
            'positions' => $positions->all(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $positions */
    private function sumPositionValue(Collection $positions, string $path): string
    {
        return $positions->reduce(
            fn (string $sum, array $position): string => bcadd($sum, (string) data_get($position, $path, '0'), self::CalculationScale),
            '0.00000000',
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $methods
     * @return array<string, array<string, mixed>>
     */
    private function withReferenceDifferences(array $methods, string $referenceMethod): array
    {
        $referenceValue = (string) $methods[$referenceMethod]['ending_value'];

        foreach ($methods as $method => $result) {
            $methods[$method]['difference_vs_reference'] = $method === 'last_purchase_reference'
                ? null
                : bcsub((string) $result['ending_value'], $referenceValue, self::CalculationScale);
        }

        return $methods;
    }

    private function assertReferenceMethod(string $referenceMethod): void
    {
        if (! in_array($referenceMethod, [self::Method, 'periodic_weighted_average', 'fifo'], true)) {
            throw new DomainException('inventory_accounting.errors.invalid_reference_method');
        }
    }

    /** @param array<string, mixed> $movement
     * @return array<string, mixed>
     */
    private function checkpoint(
        int|string $sequence,
        array $movement,
        string $quantity,
        string $value,
        ?string $receiptUnitCost = null,
    ): array {
        return [
            'sequence' => is_numeric($sequence) ? (int) $sequence + 1 : $sequence,
            'type' => $movement['type'] ?? null,
            'quantity_in' => $this->decimal($movement['quantity_in'] ?? 0),
            'quantity_out' => $this->decimal($movement['quantity_out'] ?? 0),
            'receipt_unit_cost' => $receiptUnitCost,
            'ending_quantity' => $quantity,
            'ending_value' => $value,
            'moving_average_unit_cost' => bccomp($quantity, '0', self::CalculationScale) > 0
                ? bcdiv($value, $quantity, self::CalculationScale)
                : '0.00000000',
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
     * @return array{issue_cost: ?string, ending_value: string, ending_unit_cost: string, difference_vs_reference: null, book_method: bool, reference_only: bool}
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
            'difference_vs_reference' => null,
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
