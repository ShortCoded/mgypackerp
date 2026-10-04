<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Services\NumericFormatService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;

class InventoryValuationService
{
    public const Method = 'moving_average';

    private const CalculationScale = 8;

    public function __construct(private readonly PurchaseInvoiceCalculationService $purchaseCalculator) {}

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
        return $this->bookPositionUnitCost($this->bookPositionForPosition(
            $companyId, $branchStoreId, $productId, $stockStatus, $warehouseLocationId,
            $batchLot, $productionRunId, $asOfDate, $exactDimensions,
        ));
    }

    /** @return array{quantity: string, value: string, unvalued_quantity: string} */
    public function bookPositionForPosition(
        int $companyId,
        int $branchStoreId,
        int $productId,
        ?string $stockStatus = null,
        ?int $warehouseLocationId = null,
        ?string $batchLot = null,
        ?int $productionRunId = null,
        mixed $asOfDate = null,
        bool $exactDimensions = false,
    ): array {
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
                fn (Builder $query) => $exactDimensions && $stockStatus === InventoryTransaction::StatusProductionStaging
                    ? $query->whereNull('production_run_id')
                    : $query,
            )
            ->when($asOfDate !== null, fn (Builder $query) => $query->whereDate('transaction_date', '<=', $asOfDate))
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity')
            ->selectRaw('coalesce(sum('.InventoryTransaction::signedValueSql().'), 0) as value')
            ->selectRaw('coalesce(sum('.InventoryTransaction::unvaluedQuantitySql().'), 0) as unvalued_quantity')
            ->first();

        $numbers = app(NumericFormatService::class);

        return [
            'quantity' => bcadd($numbers->normalize($totals?->quantity ?? '0') ?? '0', '0', 8),
            'value' => bcadd($numbers->normalize($totals?->value ?? '0') ?? '0', '0', 8),
            'unvalued_quantity' => bcadd($numbers->normalize($totals?->unvalued_quantity ?? '0') ?? '0', '0', 8),
        ];
    }

    /** @param array{quantity: string, value: string, unvalued_quantity: string} $position */
    private function bookPositionUnitCost(array $position): ?string
    {
        if (bccomp($position['quantity'], '0', 8) <= 0
            || bccomp($position['unvalued_quantity'], '0', 8) !== 0
            || bccomp($position['value'], '0', 8) < 0) {
            return null;
        }

        return bcdiv($position['value'], $position['quantity'], 8);
    }

    /**
     * Preview one canonical movement against a virtual book position, in posting order.
     *
     * @param  array{quantity: string, value: string, unvalued_quantity: string}  $position
     * @return array{unit_cost: string|null, total_cost: string|null, position: array{quantity: string, value: string, unvalued_quantity: string}}
     */
    public function previewBookPositionMovement(array $position, string $quantityIn, string $quantityOut, ?string $receiptUnitCost = null): array
    {
        $inbound = bccomp($quantityIn, '0', 8) > 0;
        if (bccomp($quantityIn, '0', 8) < 0 || bccomp($quantityOut, '0', 8) < 0
            || ($inbound && bccomp($quantityOut, '0', 8) > 0)
            || (! $inbound && bccomp($quantityOut, '0', 8) <= 0)
            || (! $inbound && bccomp($quantityOut, $position['quantity'], 8) > 0)) {
            throw new DomainException(__('The issue exceeds the receipt-layer quantity available for allocation.'));
        }
        $unitCost = $inbound ? $receiptUnitCost : $this->bookPositionUnitCost($position);
        $quantity = $inbound ? $quantityIn : $quantityOut;
        $value = $unitCost === null ? null : bcmul($quantity, $unitCost, 8);
        $position['quantity'] = bcadd($position['quantity'], bcsub($quantityIn, $quantityOut, 8), 8);
        if ($value === null) {
            $position['unvalued_quantity'] = bcadd($position['unvalued_quantity'], $inbound ? $quantity : bcsub('0', $quantity, 8), 8);
        } else {
            $position['value'] = bcadd($position['value'], $inbound ? $value : bcsub('0', $value, 8), 8);
        }

        return ['unit_cost' => $unitCost, 'total_cost' => $value, 'position' => $position];
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
        $transactions = $this->effectiveTransactions($transactions, $asOfDate);

        if ($transactions->isEmpty()) {
            throw new DomainException('inventory_accounting.errors.no_valuation_movements');
        }

        $purchaseReferences = $this->approvedPurchaseReferences(
            $companyId,
            collect([(object) ['branch_store_id' => $branchStoreId, 'product_id' => $productId]]),
            $asOfDate,
        );

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
                $this->costPositionRunId($transaction) ?? 'none',
            ]))
            ->map(function (Collection $positionTransactions, string $positionKey) use ($referenceMethod, $purchaseReferences): array {
                /** @var InventoryTransaction $first */
                $first = $positionTransactions->first();

                return [
                    'key' => $positionKey,
                    'stock_status' => $first->stock_status,
                    'warehouse_location_id' => $first->warehouse_location_id,
                    'batch_lot' => $first->batch_lot,
                    'production_run_id' => $this->costPositionRunId($first),
                    ...$this->compareMovements(
                        $positionTransactions->map(fn (InventoryTransaction $transaction): array => $this->comparisonMovement($transaction))->all(),
                        $referenceMethod,
                        $purchaseReferences->get($this->purchaseReferenceKey($first->branch_store_id, $first->product_id)),
                    ),
                ];
            })
            ->values();
        $comparison = $this->aggregatePositionComparisons($positions, $referenceMethod);
        $comparison = $this->markPostedBookMethod($comparison, $transactions);

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
     * Compare every visible stock position together while showing positions whose costs are still unknown.
     *
     * @param  Collection<int, InventoryTransaction>  $bookRows
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function comparisonForStockScope(
        int $companyId,
        Collection $bookRows,
        mixed $asOfDate,
        string $referenceMethod = self::Method,
        array $filters = [],
    ): array {
        $this->assertReferenceMethod($referenceMethod);
        $bookRowsByPosition = $bookRows->keyBy(fn (InventoryTransaction $row): string => $this->stockScopePositionKey($row));
        $positionKeys = $bookRowsByPosition->map(fn (): bool => true);
        $transactions = $positionKeys->isEmpty() ? collect() : InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $bookRows->pluck('branch_id')->unique())
            ->whereIn('branch_store_id', $bookRows->pluck('branch_store_id')->unique())
            ->whereIn('product_id', $bookRows->pluck('product_id')->unique())
            ->whereDate('transaction_date', '<=', $asOfDate)
            ->when($filters['stock_status'] ?? null, fn (Builder $query, string $status) => $query->where('stock_status', $status))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (InventoryTransaction $transaction): bool => $positionKeys->has($this->stockScopePositionKey($transaction)));
        $transactions = $this->effectiveTransactions($transactions, $asOfDate);
        $purchaseReferences = $this->approvedPurchaseReferences($companyId, $bookRows, $asOfDate);

        $positions = collect();
        $excluded = collect();
        foreach ($transactions->groupBy(fn (InventoryTransaction $transaction): string => implode(':', [
            $transaction->branch_id,
            $transaction->branch_store_id,
            $transaction->branch_hall_id ?? 'none',
            $transaction->product_id,
            $transaction->stock_status,
            $transaction->warehouse_location_id ?? 'none',
            $transaction->batch_lot ?? 'none',
            $this->costPositionRunId($transaction) ?? 'none',
        ])) as $key => $movements) {
            $first = $movements->first();
            try {
                $positions->push([
                    'key' => $key,
                    'branch_id' => $first->branch_id,
                    'branch_store_id' => $first->branch_store_id,
                    'product_id' => $first->product_id,
                    ...$this->compareMovements(
                        $movements->map(fn (InventoryTransaction $transaction): array => $this->comparisonMovement($transaction)),
                        $referenceMethod,
                        $purchaseReferences->get($this->purchaseReferenceKey($first->branch_store_id, $first->product_id)),
                    ),
                ]);
            } catch (DomainException $exception) {
                $bookRow = $bookRowsByPosition->get($this->stockScopePositionKey($first));
                $excluded->push([
                    'key' => $key,
                    'branch_id' => $first->branch_id,
                    'branch_store_id' => $first->branch_store_id,
                    'product_id' => $first->product_id,
                    'branch_name' => $bookRow?->branch?->name,
                    'store_name' => $bookRow?->branchStore?->name,
                    'product_doc_num' => $bookRow?->product?->doc_num,
                    'product_name' => $bookRow?->product?->name,
                    'unit_id' => $bookRow?->product?->item_unit_id,
                    'unit_name' => $bookRow?->product?->unit?->name ?? '—',
                    'quantity' => $movements->reduce(fn (string $total, InventoryTransaction $transaction): string => bcadd($total, bcsub((string) $transaction->quantity_in, (string) $transaction->quantity_out, 8), 8), '0.00000000'),
                    'reason' => $exception->getMessage(),
                    'source_doc_nums' => $movements->pluck('source_doc_num')->filter()->unique()->values()->all(),
                ]);
            }
        }

        $excludedQuantityByUnit = $excluded
            ->groupBy(fn (array $position): string => $position['unit_id'] !== null
                ? 'unit:'.$position['unit_id']
                : 'product:'.$position['product_id'])
            ->map(function (Collection $unitPositions): array {
                $first = $unitPositions->first();

                return [
                    'unit_id' => $first['unit_id'],
                    'unit_name' => $first['unit_name'],
                    'quantity' => $unitPositions->reduce(
                        fn (string $total, array $position): string => bcadd($total, $position['quantity'], 8),
                        '0.00000000',
                    ),
                ];
            })
            ->values()
            ->all();

        return [
            ...$this->markPostedBookMethod($this->aggregatePositionComparisons($positions, $referenceMethod), $transactions),
            'as_of' => (string) $asOfDate,
            'source_count' => $transactions->count(),
            'sources' => [],
            'valued_position_count' => $positions->count(),
            'excluded_position_count' => $excluded->count(),
            'excluded_quantity' => $excluded->reduce(fn (string $total, array $position): string => bcadd($total, $position['quantity'], 8), '0.00000000'),
            'excluded_mixed_units' => count($excludedQuantityByUnit) > 1,
            'excluded_quantity_by_unit' => $excludedQuantityByUnit,
            'excluded_positions' => $excluded->all(),
            'valuation_complete' => $excluded->isEmpty(),
        ];
    }

    private function costPositionRunId(InventoryTransaction $transaction): ?int
    {
        return $transaction->stock_status === InventoryTransaction::StatusProductionStaging
            ? $transaction->production_run_id
            : null;
    }

    private function purchaseReferenceKey(int $storeId, int $productId): string
    {
        return $storeId.':'.$productId;
    }

    /**
     * The approved receipt is the provisional source until an eligible approved invoice
     * supplies its final net line price. Freight and recoverable tax are separate postings.
     * Neither source changes the historical inventory ledger through this report.
     *
     * @param  Collection<int, object>  $positions
     * @return Collection<string, array<string, mixed>>
     */
    private function approvedPurchaseReferences(int $companyId, Collection $positions, mixed $asOfDate): Collection
    {
        $pairs = $positions->mapWithKeys(fn (object $position): array => [
            $this->purchaseReferenceKey((int) $position->branch_store_id, (int) $position->product_id) => true,
        ]);
        $references = collect();
        if ($pairs->isEmpty()) {
            return $references;
        }

        UnpricedInventoryReceiptLine::query()
            ->where('company_id', $companyId)
            ->whereIn('product_id', $positions->pluck('product_id')->unique())
            ->where('inventory_posted_quantity', '>', 0)
            ->whereNotNull('grni_journal_entry_id')
            ->whereHas('receipt', fn (Builder $query): Builder => $query
                ->where('company_id', $companyId)
                ->whereIn('branch_store_id', $positions->pluck('branch_store_id')->unique())
                ->whereDate('document_date', '<=', $asOfDate)
                ->whereDate('approved_at', '<=', $asOfDate)
                ->where(function (Builder $reversal) use ($asOfDate): void {
                    $reversal->whereNull('reversed_at')->orWhereDate('reversed_at', '>', $asOfDate);
                }))
            ->with(['receipt.purchaseOrder.currency', 'purchaseOrderLine'])
            ->chunkById(200, function (Collection $receiptLines) use ($companyId, $pairs, $references, $asOfDate): void {
                $eligibleLines = $receiptLines->filter(fn (UnpricedInventoryReceiptLine $line): bool => $line->receipt !== null
                    && $line->purchaseOrderLine !== null
                    && $pairs->has($this->purchaseReferenceKey((int) $line->receipt->branch_store_id, (int) $line->product_id)));

                foreach ($eligibleLines as $line) {
                    $receipt = $line->receipt;
                    $order = $receipt->purchaseOrder;
                    $factor = $line->purchaseOrderLine->stockConversionFactor();
                    if ($order === null || bccomp((string) $line->provisional_unit_value, '0', self::CalculationScale) < 0) {
                        continue;
                    }

                    $this->rememberPurchaseReference($references, $this->purchaseReferenceKey((int) $receipt->branch_store_id, (int) $line->product_id), [
                        'unit_cost' => bcdiv((string) $line->provisional_unit_value, $factor, self::CalculationScale),
                        'date' => $receipt->document_date->toDateString(),
                        'purchase_date' => $receipt->document_date->toDateString(),
                        'purchase_line_id' => $line->getKey(),
                        'document' => $receipt->doc_num,
                        'source' => 'approved_receipt',
                        'source_id' => $line->getKey(),
                        'currency' => $order->currency?->code,
                        'exchange_rate' => (string) $order->exchange_rate,
                        'basis' => 'net_order_line_excluding_freight_tax',
                    ]);
                }

                $invoiceAmounts = [];
                $invoiceLines = PurchaseInvoiceLine::query()
                    ->where('company_id', $companyId)
                    ->whereIn('receipt_line_id', $eligibleLines->modelKeys())
                    ->whereHas('purchaseInvoice', fn (Builder $query): Builder => $query
                        ->where('company_id', $companyId)
                        ->whereNotNull('journal_entry_id')
                        ->whereDate('invoice_date', '<=', $asOfDate)
                        ->whereDate('approved_at', '<=', $asOfDate)
                        ->where(function (Builder $reversal) use ($asOfDate): void {
                            $reversal->whereNull('reversed_at')->orWhereDate('reversed_at', '>', $asOfDate);
                        }))
                    ->with(['purchaseInvoice.currency'])
                    ->get();
                $linesById = $eligibleLines->keyBy('id');
                foreach ($invoiceLines as $invoiceLine) {
                    $receiptLine = $linesById->get($invoiceLine->receipt_line_id);
                    $invoice = $invoiceLine->purchaseInvoice;
                    if ($receiptLine === null || $invoice === null
                        || (int) $invoiceLine->product_id !== (int) $receiptLine->product_id
                        || (int) $invoiceLine->purchase_order_line_id !== (int) $receiptLine->purchase_order_line_id
                        || bccomp((string) $invoiceLine->quantity, '0', self::CalculationScale) <= 0) {
                        continue;
                    }

                    $invoiceAmounts[$invoice->getKey()] ??= $this->purchaseCalculator->netAmountsByLine($invoice);
                    $factor = $receiptLine->purchaseOrderLine->stockConversionFactor();
                    $stockQuantity = bcmul((string) $invoiceLine->quantity, $factor, self::CalculationScale);
                    if (bccomp($stockQuantity, '0', self::CalculationScale) <= 0) {
                        continue;
                    }

                    $netBaseValue = bcmul(
                        (string) ($invoiceAmounts[$invoice->getKey()][$invoiceLine->getKey()] ?? '0'),
                        (string) $invoice->exchange_rate,
                        self::CalculationScale,
                    );
                    $this->rememberPurchaseReference($references, $this->purchaseReferenceKey((int) $receiptLine->receipt->branch_store_id, (int) $receiptLine->product_id), [
                        'unit_cost' => bcdiv($netBaseValue, $stockQuantity, self::CalculationScale),
                        'date' => $invoice->invoice_date->toDateString(),
                        'purchase_date' => $receiptLine->receipt->document_date->toDateString(),
                        'purchase_line_id' => $receiptLine->getKey(),
                        'document' => $invoice->doc_num,
                        'source' => 'approved_invoice',
                        'source_id' => $invoiceLine->getKey(),
                        'currency' => $invoice->currency?->code,
                        'exchange_rate' => (string) $invoice->exchange_rate,
                        'basis' => 'net_line_excluding_freight_tax',
                    ]);
                }
            });

        return $references;
    }

    /** @param Collection<string, array<string, mixed>> $references @param array<string, mixed> $candidate */
    private function rememberPurchaseReference(Collection $references, string $key, array $candidate): void
    {
        $current = $references->get($key);
        $candidateRank = $candidate['source'] === 'approved_invoice' ? 2 : 1;
        $currentRank = ($current['source'] ?? null) === 'approved_invoice' ? 2 : 1;
        if ($current === null
            || $candidate['purchase_date'] > $current['purchase_date']
            || ($candidate['purchase_date'] === $current['purchase_date'] && $candidate['purchase_line_id'] > $current['purchase_line_id'])
            || ($candidate['purchase_line_id'] === $current['purchase_line_id'] && $candidateRank > $currentRank)
            || ($candidate['purchase_line_id'] === $current['purchase_line_id'] && $candidateRank === $currentRank && $candidate['date'] > $current['date'])
            || ($candidate['purchase_line_id'] === $current['purchase_line_id'] && $candidateRank === $currentRank && $candidate['date'] === $current['date'] && $candidate['source_id'] > $current['source_id'])) {
            $references->put($key, $candidate);
        }
    }

    /** @param array<string, mixed> $comparison @param Collection<int, InventoryTransaction> $transactions
     * @return array<string, mixed>
     */
    private function markPostedBookMethod(array $comparison, Collection $transactions): array
    {
        foreach ($comparison['methods'] as $method => $result) {
            $comparison['methods'][$method]['book_method'] = false;
        }

        $outbounds = $transactions->filter(fn (InventoryTransaction $transaction): bool => bccomp((string) $transaction->quantity_out, '0', 8) > 0);
        $methods = $outbounds->map(fn (InventoryTransaction $transaction): string => $transaction->cost_method ?? InventoryCostPolicy::MovingAverage)->unique()->values();
        if ($methods->count() !== 1 || $outbounds->contains(fn (InventoryTransaction $transaction): bool => $transaction->cost_basis !== null
            && ! in_array($transaction->cost_basis, ['moving_average', 'fifo_allocations'], true))
            || $transactions->contains(fn (InventoryTransaction $transaction): bool => bccomp(bcadd((string) $transaction->quantity_in, (string) $transaction->quantity_out, 8), '0', 8) > 0
            && ($transaction->unit_cost === null || $transaction->total_cost === null))) {
            return $comparison;
        }

        $method = $methods->first();
        if (! isset($comparison['methods'][$method])) {
            return $comparison;
        }

        $postedValue = $transactions->reduce(fn (string $total, InventoryTransaction $transaction): string => bcadd(
            $total,
            bccomp((string) $transaction->quantity_in, '0', 8) > 0
                ? (string) $transaction->total_cost
                : bcmul((string) $transaction->total_cost, '-1', 8),
            8,
        ), '0.00000000');
        $comparison['methods'][$method]['book_method'] = bccomp($postedValue, (string) $comparison['methods'][$method]['ending_value'], 8) === 0;

        return $comparison;
    }

    private function stockScopePositionKey(InventoryTransaction $transaction): string
    {
        return implode(':', [
            $transaction->branch_id,
            $transaction->branch_store_id,
            $transaction->branch_hall_id ?? 'none',
            $transaction->warehouse_location_id ?? 'none',
            $transaction->product_id,
        ]);
    }

    /**
     * A reversal only cancels its original when its ledger position, quantity, and cost agree.
     * Invalid lineage must be visible as a report error, rather than silently dropping stock.
     *
     * @param  Collection<int, InventoryTransaction>  $transactions
     * @return Collection<int, InventoryTransaction>
     */
    private function effectiveTransactions(Collection $transactions, mixed $asOfDate): Collection
    {
        if ($transactions->isEmpty()) {
            return $transactions;
        }

        $transactions = $this->analysisWithCompletedCosts($transactions, $asOfDate);

        if ($transactions->contains(fn (InventoryTransaction $transaction): bool => $transaction->is_reversal && $transaction->reversal_of_id === null)) {
            throw new DomainException('inventory_accounting.errors.invalid_valuation_reversal');
        }

        $originalIds = $transactions->filter(fn (InventoryTransaction $transaction): bool => ! $transaction->is_reversal)
            ->pluck('id')
            ->merge($transactions->where('is_reversal', true)->pluck('reversal_of_id'))
            ->filter()
            ->unique()
            ->values();
        $reversals = collect();
        foreach ($originalIds->chunk(500) as $ids) {
            $reversals = $reversals->concat(InventoryTransaction::query()
                ->where('is_reversal', true)
                ->whereIn('reversal_of_id', $ids)
                ->whereDate('transaction_date', '<=', $asOfDate)
                ->get());
        }
        $originals = $transactions->filter(fn (InventoryTransaction $transaction): bool => ! $transaction->is_reversal)->keyBy('id');
        $missingIds = $originalIds->diff($originals->keys());
        foreach ($missingIds->chunk(500) as $ids) {
            foreach ($this->analysisWithCompletedCosts(InventoryTransaction::query()->whereIn('id', $ids)->get(), $asOfDate) as $original) {
                $originals->put($original->getKey(), $original);
            }
        }

        $reversals = $this->analysisWithCompletedCosts($reversals, $asOfDate);
        $reversedIds = collect();
        foreach ($reversals->groupBy('reversal_of_id') as $originalId => $group) {
            $original = $originals->get($originalId);
            if ($group->count() !== 1
                || ! $original instanceof InventoryTransaction
                || $original->is_reversal
                || $original->transaction_date?->toDateString() > (string) $asOfDate
                || ! $this->isMatchingReversal($original, $group->first())) {
                throw new DomainException('inventory_accounting.errors.invalid_valuation_reversal');
            }
            $reversedIds->push((int) $originalId);
        }

        return $transactions->filter(fn (InventoryTransaction $transaction): bool => ! $transaction->is_reversal
            && ! $reversedIds->contains((int) $transaction->getKey()));
    }

    /** @param Collection<int, InventoryTransaction> $transactions @return Collection<int, InventoryTransaction> */
    private function analysisWithCompletedCosts(Collection $transactions, mixed $asOfDate): Collection
    {
        $transactions = $transactions->reject(fn (InventoryTransaction $row): bool => $row->transaction_type === InventoryTransaction::TypeValueAdjustment);
        $corrections = InventoryValueAdjustmentLine::query()->where('effect', InventoryValueAdjustmentLine::EffectStock)
            ->whereIn('source_transaction_id', $transactions->pluck('id'))
            ->whereHas('adjustment', fn ($query) => $query->where('status', InventoryValueAdjustment::StatusPosted)->whereDate('posting_date', '<=', $asOfDate))
            ->get()->groupBy('source_transaction_id');

        return $transactions->map(function (InventoryTransaction $row) use ($corrections): InventoryTransaction {
            $parts = $corrections->get($row->id, collect());
            if ($parts->isEmpty()) {
                return $row;
            }
            $copy = clone $row;
            $inbound = bccomp((string) $row->quantity_in, '0', 8) > 0;
            $quantity = $inbound ? (string) $row->quantity_in : (string) $row->quantity_out;
            $delta = $parts->reduce(fn (string $sum, InventoryValueAdjustmentLine $part): string => bcadd($sum, (string) $part->amount, 8), '0');
            $total = bcadd((string) ($row->total_cost ?? '0'), bcmul($delta, $inbound ? '1' : '-1', 8), 8);
            $copy->setAttribute('total_cost', $total);
            $copy->setAttribute('unit_cost', bcdiv($total, $quantity, 8));
            $copy->setAttribute('cost_basis', 'approved_receipt_cost_completion');

            return $copy;
        });
    }

    private function isMatchingReversal(InventoryTransaction $original, InventoryTransaction $reversal): bool
    {
        foreach (['company_id', 'branch_id', 'branch_store_id', 'branch_hall_id', 'warehouse_location_id',
            'stock_status', 'batch_lot', 'production_run_id', 'product_id', 'unit_id'] as $field) {
            if ($original->{$field} !== $reversal->{$field}) {
                return false;
            }
        }

        return bccomp((string) $original->quantity_in, (string) $reversal->quantity_out, 8) === 0
            && bccomp((string) $original->quantity_out, (string) $reversal->quantity_in, 8) === 0
            && $this->sameOptionalCost($original->unit_cost, $reversal->unit_cost)
            && $this->sameOptionalCost($original->total_cost, $reversal->total_cost);
    }

    private function sameOptionalCost(?string $first, ?string $second): bool
    {
        return $first === null || $second === null
            ? $first === $second
            : bccomp($first, $second, 8) === 0;
    }

    /**
     * @param  iterable<array{quantity_in: mixed, quantity_out: mixed, unit_cost?: mixed, total_cost?: mixed, type?: string, counts_as_consumption?: bool, date?: string, document?: string}>  $movements
     * @param  array{unit_cost: string, date: string, document: string, source: string, currency: ?string, exchange_rate: string, basis: string}|null  $approvedPurchase
     * @return array{
     *     available_quantity: string,
     *     available_cost: string,
     *     issued_quantity: string,
     *     ending_quantity: string,
     *     methods: array<string, array{issue_cost: ?string, ending_value: string, ending_unit_cost: string, difference_vs_reference: ?string, book_method: bool, reference_only: bool}>
     * }
     */
    public function compareMovements(iterable $movements, string $referenceMethod = self::Method, ?array $approvedPurchase = null): array
    {
        $this->assertReferenceMethod($referenceMethod);
        $availableQuantity = $issuedQuantity = $movingQuantity = $movingValue = '0.00000000';
        $availableCost = $consumedQuantity = $movingIssueCost = $fifoIssueCost = $lifoIssueCost = '0.00000000';
        $lastReceiptUnitCost = '0.00000000';
        $lastReceiptSource = null;
        $fifoLayers = new Collection;
        $lifoLayers = new Collection;
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
                $lastReceiptSource = [
                    'date' => $movement['date'] ?? null,
                    'document' => $movement['document'] ?? null,
                    'source' => 'inbound_movement',
                    'movement_type' => $movement['type'] ?? null,
                    'unit_cost' => $receiptUnitCost,
                ];
                $fifoLayers->push([
                    'quantity' => $quantityIn,
                    'unit_cost' => $receiptUnitCost,
                ]);
                $lifoLayers->push([
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
            $lifoMovementIssueCost = $this->consumeLifoLayers($lifoLayers, $quantityOut);

            if ((bool) ($movement['counts_as_consumption'] ?? true)) {
                $consumedQuantity = bcadd($consumedQuantity, $quantityOut, self::CalculationScale);
                $movingIssueCost = bcadd($movingIssueCost, $movementIssueCost, self::CalculationScale);
                $fifoIssueCost = bcadd($fifoIssueCost, $fifoMovementIssueCost, self::CalculationScale);
                $lifoIssueCost = bcadd($lifoIssueCost, $lifoMovementIssueCost, self::CalculationScale);
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
        $lifoEndingValue = $lifoLayers->reduce(
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
            'lifo' => $this->methodResult($lifoIssueCost, $lifoEndingValue, $endingQuantity),
            'last_inbound_reference' => [
                ...$this->methodResult(null, $lastReceiptEndingValue, $endingQuantity, false, true),
                'source' => $lastReceiptSource,
            ],
            'last_purchase_reference' => [
                ...$this->referenceResult($approvedPurchase['unit_cost'] ?? null, $endingQuantity),
                'source' => $approvedPurchase,
            ],
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
            'remaining_lifo_layers' => $lifoLayers->values()->all(),
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
            'date' => $transaction->transaction_date?->toDateString(),
            'document' => $transaction->source_doc_num,
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
        $methodKeys = ['moving_average', 'periodic_weighted_average', 'fifo', 'lifo', 'last_inbound_reference', 'last_purchase_reference'];
        $multipleProducts = $positions->pluck('product_id')->filter()->unique()->count() > 1;
        $methods = collect($methodKeys)->mapWithKeys(function (string $method) use ($positions, $multipleProducts): array {
            $referenceOnly = in_array($method, ['last_inbound_reference', 'last_purchase_reference'], true);
            $issueCost = $referenceOnly
                ? null
                : $this->sumPositionValue($positions, "methods.{$method}.issue_cost");
            $hasUnknownReference = $method === 'last_purchase_reference'
                && $positions->contains(fn (array $position): bool => data_get($position, "methods.{$method}.ending_value") === null
                    && bccomp((string) $position['ending_quantity'], '0', self::CalculationScale) > 0);
            $endingValue = $hasUnknownReference ? null : $this->sumPositionValue($positions, "methods.{$method}.ending_value");
            $endingQuantity = $this->sumPositionValue($positions, 'ending_quantity');

            $result = $endingValue === null
                ? $this->referenceResult(null, $endingQuantity)
                : $this->methodResult($issueCost, $endingValue, $endingQuantity, $method === self::Method, $referenceOnly);
            if ($multipleProducts) {
                $result['ending_unit_cost'] = null;
            }
            if ($referenceOnly) {
                $result['sources'] = $positions->pluck("methods.{$method}.source")->filter()->unique(fn (array $source): string => json_encode($source))->values()->all();
            }

            return [$method => $result];
        })->all();

        return [
            'available_quantity' => $this->sumPositionValue($positions, 'available_quantity'),
            'available_cost' => $this->sumPositionValue($positions, 'available_cost'),
            'issued_quantity' => $this->sumPositionValue($positions, 'issued_quantity'),
            'consumed_quantity' => $this->sumPositionValue($positions, 'consumed_quantity'),
            'ending_quantity' => $this->sumPositionValue($positions, 'ending_quantity'),
            'multiple_products' => $multipleProducts,
            'reference_method' => $referenceMethod,
            'methods' => $this->withReferenceDifferences($methods, $referenceMethod),
            'positions' => $positions->all(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $positions */
    private function sumPositionValue(Collection $positions, string $path): string
    {
        return $positions->reduce(
            fn (string $sum, array $position): string => bcadd($sum, (string) (data_get($position, $path) ?? '0'), self::CalculationScale),
            '0.00000000',
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $methods
     * @return array<string, array<string, mixed>>
     */
    private function withReferenceDifferences(array $methods, string $referenceMethod): array
    {
        $referenceValue = $methods[$referenceMethod]['ending_value'];

        foreach ($methods as $method => $result) {
            $methods[$method]['difference_vs_reference'] = $result['reference_only'] || $referenceValue === null || $result['ending_value'] === null
                ? null
                : bcsub((string) $result['ending_value'], (string) $referenceValue, self::CalculationScale);
        }

        return $methods;
    }

    private function assertReferenceMethod(string $referenceMethod): void
    {
        if (! in_array($referenceMethod, [self::Method, 'periodic_weighted_average', 'fifo', 'lifo', 'last_inbound_reference', 'last_purchase_reference'], true)) {
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

    /** @param Collection<int, array{quantity: string, unit_cost: string}> $layers */
    private function consumeLifoLayers(Collection $layers, string $quantity): string
    {
        $remaining = $quantity;
        $issueCost = '0.00000000';

        while (bccomp($remaining, '0', self::CalculationScale) > 0) {
            $layer = $layers->pop();
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
                $layers->push(['quantity' => $layerRemainder, 'unit_cost' => $layer['unit_cost']]);
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

    /** @return array{issue_cost: null, ending_value: ?string, ending_unit_cost: ?string, difference_vs_reference: null, book_method: false, reference_only: true} */
    private function referenceResult(?string $unitCost, string $endingQuantity): array
    {
        if ($unitCost === null) {
            return [
                'issue_cost' => null,
                'ending_value' => null,
                'ending_unit_cost' => null,
                'difference_vs_reference' => null,
                'book_method' => false,
                'reference_only' => true,
            ];
        }

        return $this->methodResult(null, bcmul($endingQuantity, $unitCost, self::CalculationScale), $endingQuantity, false, true);
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new DomainException('inventory_accounting.errors.invalid_valuation_movement');
        }

        return bcadd((string) $value, '0', self::CalculationScale);
    }
}
