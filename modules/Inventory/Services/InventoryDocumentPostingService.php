<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesReturn;

class InventoryDocumentPostingService
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryValuationService $valuation,
        private readonly InventoryAccountingPostingService $accounting,
        private readonly InventoryLayerService $layers,
    ) {}

    public function post(InventoryDocument $document): InventoryDocument
    {
        return DB::transaction(function () use ($document): InventoryDocument {
            $locked = InventoryDocument::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($document->getKey());

            if ($locked->status === InventoryDocument::StatusPosted) {
                return $locked;
            }

            if ($locked->status !== InventoryDocument::StatusDraft) {
                throw new DomainException(__('Only a draft inventory document can be posted.'));
            }

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('An inventory document must contain at least one line.'));
            }

            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($locked->financial_period_id);

            if ($period->is_closed || (int) $period->company_id !== (int) $locked->company_id) {
                throw new DomainException(__('Inventory movements cannot be posted to a closed or unrelated financial period.'));
            }
            app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $locked->company_id, $locked->document_date, (int) $locked->financial_period_id, lockForUpdate: true);

            $profile = $this->movementProfile($locked);
            BranchStore::query()->lockForUpdate()->findOrFail($locked->branch_store_id);

            if ($profile['destination_store_id'] !== null) {
                BranchStore::query()->lockForUpdate()->findOrFail($profile['destination_store_id']);
            }

            foreach ($locked->lines as $line) {
                Product::query()->lockForUpdate()->findOrFail($line->product_id);
                $this->assertChronologicalPosting($locked, $line);
                $quantity = (string) $line->quantity;

                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }

                $unitCost = bccomp((string) $line->unit_cost, '0', 8) > 0
                    ? (string) $line->unit_cost
                    : $this->valuation->movingAverageUnitCost(
                        (int) $locked->company_id,
                        (int) $locked->branch_store_id,
                        (int) $line->product_id,
                        $profile['outbound'] ? $profile['source_status'] : null,
                        $line->warehouse_location_id ?? $locked->warehouse_location_id,
                        $line->batch_lot,
                        $profile['source_status'] === InventoryTransaction::StatusProductionStaging
                            ? $locked->production_run_id
                            : null,
                        $locked->document_date,
                    );

                $sourceIssue = null;
                if ($profile['outbound']) {
                    $this->assertPositionCanIssue($locked, $line, $quantity, $profile['source_status']);
                    $sourceIssue = $this->createTransaction(
                        $locked,
                        $line,
                        'out',
                        (int) $locked->branch_store_id,
                        $line->warehouse_location_id ?? $locked->warehouse_location_id,
                        $profile['source_status'],
                        '0',
                        $quantity,
                        $unitCost,
                    );
                }

                if ($profile['inbound']) {
                    if ($sourceIssue === null && isset($line->product_snapshot['source_issue_transaction_id'])) {
                        $sourceIssue = InventoryTransaction::query()
                            ->lockForUpdate()
                            ->findOrFail($line->product_snapshot['source_issue_transaction_id']);
                    }
                    $receiptTransaction = $this->createTransaction(
                        $locked,
                        $line,
                        'in',
                        $profile['destination_store_id'] ?? (int) $locked->branch_store_id,
                        $line->destination_warehouse_location_id
                            ?? $locked->destination_warehouse_location_id
                            ?? $line->warehouse_location_id
                            ?? $locked->warehouse_location_id,
                        $profile['destination_status'],
                        $quantity,
                        '0',
                        $unitCost,
                    );
                    $this->layers->recordInbound($receiptTransaction, $sourceIssue);
                }

                $line->update([
                    'unit_cost' => $unitCost,
                    'total_cost' => bcmul($quantity, $unitCost, 8),
                ]);
            }

            $this->accounting->post($locked->refresh()->load('lines.product'));

            $locked->update([
                'status' => InventoryDocument::StatusPosted,
                'is_closed' => true,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'closed_by' => auth()->id(),
                'closed_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['lines', 'transactions']);
        });
    }

    public function reverse(InventoryDocument $document): InventoryDocument
    {
        return DB::transaction(function () use ($document): InventoryDocument {
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($locked->status === InventoryDocument::StatusReversed) {
                return $locked;
            }

            if ($locked->status !== InventoryDocument::StatusPosted) {
                throw new DomainException(__('Only a posted inventory document can be reversed.'));
            }

            $salesOrder = null;
            if ($locked->document_type === InventoryDocument::TypeSalesDelivery) {
                $salesOrder = SalesOrder::query()->lockForUpdate()->findOrFail($locked->source_document_id);
                $salesReturn = SalesReturn::query()->where('delivery_document_id', $locked->id)->where('status', '<>', SalesReturn::StatusCancelled)->first();
                if ($salesReturn) {
                    throw new DomainException(__('Delivery is linked to return :return.', ['return' => $salesReturn->doc_num]));
                }
                $invoice = CustomerInvoice::query()->whereHas('lines', fn ($query) => $query->whereIn('delivery_line_id', $locked->lines()->select('id')))->first();
                if ($invoice) {
                    throw new DomainException(__('Delivery :delivery cannot be reversed because it is linked to invoice :invoice.', ['delivery' => $locked->doc_num, 'invoice' => $invoice->doc_num]));
                }
            }

            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($locked->financial_period_id);

            if ($period->is_closed || (int) $period->company_id !== (int) $locked->company_id) {
                throw new DomainException(__('Inventory movements cannot be reversed in a closed or unrelated financial period.'));
            }

            $transactions = InventoryTransaction::query()
                ->where('source_type', InventoryDocument::class)
                ->where('source_id', $locked->getKey())
                ->where('is_reversal', false)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($transactions as $transaction) {
                if (bccomp((string) $transaction->quantity_in, '0', 8) > 0) {
                    $position = $this->availability->forProduct(
                        (int) $transaction->company_id,
                        (int) $transaction->branch_store_id,
                        (int) $transaction->product_id,
                        null,
                        $transaction->warehouse_location_id,
                        (string) $transaction->stock_status,
                        $transaction->batch_lot,
                        true,
                    );

                    if (bccomp((string) $transaction->quantity_in, $position['available'], 8) > 0) {
                        throw new DomainException(__('The document cannot be reversed because its received stock has already been consumed or moved.'));
                    }
                }

                $reversal = InventoryTransaction::query()->firstOrCreate(
                    ['posting_key' => $transaction->posting_key.':reversal'],
                    [
                        ...$transaction->only([
                            'company_id', 'financial_period_id', 'branch_id', 'branch_store_id',
                            'branch_hall_id', 'warehouse_location_id', 'stock_status', 'batch_lot',
                            'manufacture_date', 'expiry_date',
                            'transaction_date', 'transaction_type', 'product_id', 'unit_id',
                            'source_type', 'source_id', 'source_doc_num', 'source_line_type',
                            'source_line_id', 'supplier_id', 'customer_id', 'production_order_id',
                            'production_run_id', 'inventory_reservation_id', 'unit_cost', 'total_cost',
                        ]),
                        'quantity_in' => $transaction->quantity_out,
                        'quantity_out' => $transaction->quantity_in,
                        'is_reversal' => true,
                        'reversal_of_id' => $transaction->getKey(),
                        'notes' => 'Reversal of '.$transaction->posting_key,
                        'created_by' => auth()->id(),
                    ],
                );
                if (bccomp((string) $reversal->quantity_out, '0', 8) > 0) {
                    $this->layers->allocateIssue($reversal);
                } else {
                    $this->layers->recordInbound($reversal);
                }
            }

            $this->accounting->reverse($locked);

            if ($salesOrder) {
                foreach ($locked->lines as $deliveryLine) {
                    $salesLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($deliveryLine->source_line_id);
                    $salesLine->decrement('delivered_quantity', $deliveryLine->transaction_quantity);
                    $salesLine->decrement('delivered_base_quantity', $deliveryLine->quantity);
                }
                $from = $salesOrder->status;
                $status = $salesOrder->lines()->where('delivered_quantity', '>', 0)->exists()
                    ? SalesOrder::StatusPartiallyFulfilled : SalesOrder::StatusApproved;
                $salesOrder->update(['status' => $status, 'updated_by' => auth()->id()]);
                $salesOrder->statusHistory()->create(['from_status' => $from, 'to_status' => $status, 'reason' => 'Delivery reversal '.$locked->doc_num, 'changed_by' => auth()->id(), 'changed_at' => now()]);
            }

            $locked->update([
                'status' => InventoryDocument::StatusReversed,
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['lines', 'transactions']);
        });
    }

    /**
     * @return array{outbound: bool, inbound: bool, source_status: string, destination_status: string, destination_store_id: int|null}
     */
    private function movementProfile(InventoryDocument $document): array
    {
        $transferTypes = [
            InventoryDocument::TypeTransfer,
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue,
            InventoryDocument::TypeMaterialReturn,
            InventoryDocument::TypeDamage,
        ];
        $outboundTypes = [
            InventoryDocument::TypeSalesDelivery,
            InventoryDocument::TypeAdjustmentOut,
            InventoryDocument::TypeMaterialConsumption,
            InventoryDocument::TypeProductionWaste,
            InventoryDocument::TypeScrap,
        ];

        $sourceStatus = $document->source_stock_status ?: match ($document->document_type) {
            InventoryDocument::TypeMaterialConsumption,
            InventoryDocument::TypeProductionWaste,
            InventoryDocument::TypeMaterialReturn => InventoryTransaction::StatusProductionStaging,
            default => InventoryTransaction::StatusAvailable,
        };
        $destinationStatus = $document->destination_stock_status ?: match ($document->document_type) {
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue => InventoryTransaction::StatusProductionStaging,
            InventoryDocument::TypeDamage => InventoryTransaction::StatusDamaged,
            default => InventoryTransaction::StatusAvailable,
        };
        $isTransfer = in_array($document->document_type, $transferTypes, true);

        return [
            'outbound' => $isTransfer || in_array($document->document_type, $outboundTypes, true),
            'inbound' => $isTransfer || ! in_array($document->document_type, $outboundTypes, true),
            'source_status' => $sourceStatus,
            'destination_status' => $destinationStatus,
            'destination_store_id' => $document->destination_branch_store_id
                ? (int) $document->destination_branch_store_id
                : ($isTransfer ? (int) $document->branch_store_id : null),
        ];
    }

    private function assertPositionCanIssue(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        string $quantity,
        string $stockStatus,
    ): void {
        $position = $this->availability->forProduct(
            (int) $document->company_id,
            (int) $document->branch_store_id,
            (int) $line->product_id,
            $line->source_line_type === SalesOrderLine::class ? (int) $line->source_line_id : null,
            $line->warehouse_location_id ?? $document->warehouse_location_id,
            $stockStatus,
            $line->batch_lot,
            $line->warehouse_location_id !== null
                || $document->warehouse_location_id !== null
                || filled($line->batch_lot),
        );

        if (bccomp($quantity, $position['available'], 8) > 0) {
            throw new DomainException(__(
                'The inventory movement exceeds unreserved stock in the selected store, location, batch, and status. Document: :document; product ID: :product_id; requested: :requested; available: :available; store ID: :store_id; status: :status.',
                [
                    'document' => $document->doc_num,
                    'product_id' => (int) $line->product_id,
                    'requested' => $quantity,
                    'available' => $position['available'],
                    'store_id' => (int) $document->branch_store_id,
                    'status' => $stockStatus,
                ],
            ));
        }
    }

    private function createTransaction(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        string $direction,
        int $branchStoreId,
        mixed $warehouseLocationId,
        string $stockStatus,
        string $quantityIn,
        string $quantityOut,
        string $unitCost,
    ): InventoryTransaction {
        $postingKey = "inventory-document:{$document->id}:line:{$line->id}:{$direction}";

        $transaction = InventoryTransaction::query()->firstOrCreate(
            ['posting_key' => $postingKey],
            [
                'company_id' => $document->company_id,
                'financial_period_id' => $document->financial_period_id,
                'branch_id' => $document->branch_id,
                'branch_store_id' => $branchStoreId,
                'branch_hall_id' => $document->branch_hall_id,
                'warehouse_location_id' => $warehouseLocationId,
                'stock_status' => $stockStatus,
                'batch_lot' => $line->batch_lot,
                'manufacture_date' => $line->manufacture_date,
                'expiry_date' => $line->expiry_date,
                'transaction_date' => $document->document_date,
                'transaction_type' => $document->document_type,
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'quantity_in' => $quantityIn,
                'quantity_out' => $quantityOut,
                'source_type' => InventoryDocument::class,
                'source_id' => $document->getKey(),
                'source_doc_num' => $document->doc_num,
                'source_line_type' => $line->source_line_type,
                'source_line_id' => $line->source_line_id,
                'customer_id' => $document->customer_id,
                'production_order_id' => $document->production_order_id,
                'production_run_id' => $line->production_run_id ?? $document->production_run_id,
                'inventory_reservation_id' => $line->inventory_reservation_id ?? null,
                'unit_cost' => $unitCost,
                'total_cost' => bcmul(bcadd($quantityIn, $quantityOut, 8), $unitCost, 8),
                'created_by' => auth()->id(),
            ],
        );

        if (bccomp($quantityOut, '0', 8) > 0) {
            $this->layers->allocateIssue($transaction);
        }

        return $transaction;
    }

    private function assertChronologicalPosting(
        InventoryDocument $document,
        InventoryDocumentLine $line,
    ): void {
        $hasLaterMovement = InventoryTransaction::query()
            ->where('company_id', $document->company_id)
            ->where('branch_store_id', $document->branch_store_id)
            ->where('product_id', $line->product_id)
            ->whereDate('transaction_date', '>', $document->document_date)
            ->exists();

        if ($hasLaterMovement) {
            throw new DomainException(__('Backdated inventory posting is blocked because later valued movements already exist for this product and store.'));
        }
    }
}
