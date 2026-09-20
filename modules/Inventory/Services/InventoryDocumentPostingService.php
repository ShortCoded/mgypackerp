<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;

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
            $this->assertQualityHoldAuthority($locked, $profile['source_status'], $profile['destination_status']);
            $sourceStore = BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($locked->branch_store_id);
            $destinationStore = $profile['destination_store_id'] !== null
                ? BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($profile['destination_store_id'])
                : null;

            if ((int) $sourceStore->branch?->company_id !== (int) $locked->company_id
                || ($destinationStore && (int) $destinationStore->branch?->company_id !== (int) $locked->company_id)) {
                throw new DomainException(__('Inventory transfer stores must belong to the document company.'));
            }

            foreach ($locked->lines as $line) {
                Product::query()->lockForUpdate()->findOrFail($line->product_id);
                $this->assertChronologicalPosting($locked, $line);
                $quantity = (string) $line->quantity;

                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }

                $this->hydrateSalesReturnDispositionDimensions($locked, $line, $profile['source_status']);
                $sourceLocationId = $line->warehouse_location_id ?? $locked->warehouse_location_id;
                $sourceProductionRunId = $profile['source_status'] === InventoryTransaction::StatusProductionStaging
                    ? ($line->production_run_id ?? $locked->production_run_id)
                    : null;
                $exactSourceDimensions = $sourceLocationId !== null
                    || filled($line->batch_lot)
                    || $sourceProductionRunId !== null;
                $sourceIssue = $this->validatedSourceIssue($locked, $line, $quantity);

                $unitCost = match (true) {
                    $line->unit_cost !== null => (string) $line->unit_cost,
                    $profile['outbound'] => $this->valuation->bookUnitCostForPosition(
                        (int) $locked->company_id,
                        (int) $locked->branch_store_id,
                        (int) $line->product_id,
                        $profile['source_status'],
                        $sourceLocationId,
                        $line->batch_lot,
                        $sourceProductionRunId,
                        $locked->document_date,
                        $exactSourceDimensions,
                    ),
                    $sourceIssue !== null && $sourceIssue->unit_cost !== null && $sourceIssue->total_cost !== null => (string) $sourceIssue->unit_cost,
                    default => null,
                };

                if (in_array($locked->document_type, [
                    InventoryDocument::TypeMaintenanceMaterialIssue,
                    InventoryDocument::TypeMaintenanceMaterialReturn,
                ], true) && $unitCost === null) {
                    throw new DomainException(__('Maintenance material movements require an authoritative inventory cost.'));
                }

                if ($profile['outbound']) {
                    $this->assertPositionCanIssue($locked, $line, $quantity, $profile['source_status']);
                    $sourceIssue = $this->createTransaction(
                        $locked,
                        $line,
                        'out',
                        (int) $locked->branch_store_id,
                        (int) $sourceStore->branch_id,
                        $line->warehouse_location_id ?? $locked->warehouse_location_id,
                        $profile['source_status'],
                        '0',
                        $quantity,
                        $unitCost,
                    );
                }

                if ($profile['inbound']) {
                    $receiptTransaction = $this->createTransaction(
                        $locked,
                        $line,
                        'in',
                        $profile['destination_store_id'] ?? (int) $locked->branch_store_id,
                        (int) ($destinationStore?->branch_id ?? $sourceStore->branch_id),
                        $line->destination_warehouse_location_id
                            ?? $locked->destination_warehouse_location_id
                            ?? $line->warehouse_location_id
                            ?? $locked->warehouse_location_id,
                        $profile['destination_status'],
                        $quantity,
                        '0',
                        $unitCost,
                    );
                    $restorationAllocations = $this->resolveRestorationAllocations($locked, $line, $sourceIssue);
                    $this->layers->recordInbound($receiptTransaction, $sourceIssue, $restorationAllocations);
                }

                $line->update([
                    'unit_cost' => $unitCost,
                    'total_cost' => $unitCost === null ? null : bcmul($quantity, $unitCost, 8),
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
            if ($locked->source_document_type === MaintenanceMaterialRequest::class
                || in_array($locked->document_type, [
                    InventoryDocument::TypeMaintenanceMaterialIssue,
                    InventoryDocument::TypeMaintenanceMaterialReturn,
                ], true)) {
                throw new DomainException(__('Maintenance material inventory documents are controlled by the maintenance workflow.'));
            }
            if ($locked->source_document_type === ProductionQualityInspection::class) {
                throw new DomainException(__('production_execution.messages.quality_inventory_document_controlled'));
            }
            if ($locked->production_order_id !== null
                || $locked->production_run_id !== null
                || in_array($locked->source_document_type, [ProductionRun::class, ProductionMaterialRequirement::class], true)
                || $locked->lines()->whereIn('source_line_type', [ProductionRun::class, ProductionMaterialRequirement::class])->exists()) {
                throw new DomainException(__('Production-linked inventory documents must be reversed through the production workflow.'));
            }

            $salesOrder = null;
            if ($locked->document_type === InventoryDocument::TypeSalesDelivery) {
                if ($locked->source_document_type === SalesOrder::class) {
                    $salesOrder = SalesOrder::query()->lockForUpdate()->findOrFail($locked->source_document_id);
                }
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
            InventoryDocument::TypeIssue,
            InventoryDocument::TypeAdjustmentOut,
            InventoryDocument::TypeMaintenanceMaterialIssue,
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

    private function validatedSourceIssue(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        string $quantity,
    ): ?InventoryTransaction {
        $sourceIssueId = $line->product_snapshot['source_issue_transaction_id'] ?? null;
        if ($sourceIssueId === null && $document->document_type !== InventoryDocument::TypeSalesReturnReceipt) {
            return null;
        }

        $canonicalSourceIssue = $document->document_type === InventoryDocument::TypeSalesReturnReceipt
            ? $this->canonicalSalesReturnSourceIssue($document, $line)
            : null;
        $sourceIssue = $canonicalSourceIssue ?? (is_numeric($sourceIssueId)
            ? InventoryTransaction::query()->lockForUpdate()->find((int) $sourceIssueId)
            : null);

        if ($canonicalSourceIssue instanceof InventoryTransaction) {
            $line->forceFill([
                'batch_lot' => $sourceIssue->batch_lot,
                'manufacture_date' => $sourceIssue->manufacture_date,
                'expiry_date' => $sourceIssue->expiry_date,
            ])->save();
        }
        $sourceDocument = $sourceIssue?->source_type === InventoryDocument::class
            ? InventoryDocument::query()->lockForUpdate()->find($sourceIssue->source_id)
            : null;
        $expectedProductionRunId = $line->production_run_id ?? $document->production_run_id;
        $isMaintenanceAllocationBound = $document->document_type === InventoryDocument::TypeMaintenanceMaterialReturn
            && isset($line->product_snapshot['restoration_allocation_id']);

        if (! $sourceIssue instanceof InventoryTransaction
            || ! $sourceDocument instanceof InventoryDocument
            || (int) $sourceIssue->company_id !== (int) $document->company_id
            || (int) $sourceIssue->branch_id !== (int) $document->branch_id
            || ($document->document_type !== InventoryDocument::TypeSalesReturnReceipt
                && (int) $sourceIssue->branch_store_id !== (int) $document->branch_store_id)
            || (int) $sourceIssue->product_id !== (int) $line->product_id
            || $sourceIssue->is_reversal
            || bccomp((string) $sourceIssue->quantity_in, '0', 8) !== 0
            || bccomp((string) $sourceIssue->quantity_out, $quantity, 8) < 0
            || $sourceIssue->unit_cost === null
            || $sourceIssue->total_cost === null
            || (! $isMaintenanceAllocationBound && $sourceIssue->batch_lot !== $line->batch_lot)
            || ($sourceIssue->production_run_id === null ? null : (int) $sourceIssue->production_run_id) !== ($expectedProductionRunId === null ? null : (int) $expectedProductionRunId)
            || $sourceIssue->transaction_date?->gt($document->document_date)
            || $sourceDocument->status !== InventoryDocument::StatusPosted
            || ! $this->sourceIssueLinkMatches($document, $line, $sourceIssue, $sourceDocument)) {
            throw new DomainException(__('The linked source issue does not match this inventory return line.'));
        }

        return $sourceIssue;
    }

    private function hydrateSalesReturnDispositionDimensions(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        string $sourceStatus,
    ): void {
        if ($document->document_type !== InventoryDocument::TypeTransfer
            || $document->source_document_type !== SalesReturn::class
            || $line->source_line_type !== SalesReturnLine::class
            || $sourceStatus !== InventoryTransaction::StatusQuarantine) {
            return;
        }

        $salesReturn = SalesReturn::query()->lockForUpdate()->find($document->source_document_id);
        $sourceReceipt = $salesReturn?->return_inventory_document_id
            ? InventoryTransaction::query()
                ->where('source_type', InventoryDocument::class)
                ->where('source_id', $salesReturn->return_inventory_document_id)
                ->where('source_line_type', SalesReturnLine::class)
                ->where('source_line_id', $line->source_line_id)
                ->where('product_id', $line->product_id)
                ->where('stock_status', InventoryTransaction::StatusQuarantine)
                ->where('quantity_in', '>', 0)
                ->where('is_reversal', false)
                ->lockForUpdate()
                ->latest('id')
                ->first()
            : null;

        if (! $sourceReceipt instanceof InventoryTransaction) {
            return;
        }

        $line->forceFill([
            'warehouse_location_id' => $sourceReceipt->warehouse_location_id,
            'batch_lot' => $sourceReceipt->batch_lot,
            'manufacture_date' => $sourceReceipt->manufacture_date,
            'expiry_date' => $sourceReceipt->expiry_date,
        ])->save();
    }

    private function sourceIssueLinkMatches(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        InventoryTransaction $sourceIssue,
        InventoryDocument $sourceDocument,
    ): bool {
        if ($document->document_type === InventoryDocument::TypeMaintenanceMaterialReturn) {
            return $sourceDocument->document_type === InventoryDocument::TypeMaintenanceMaterialIssue
                && $sourceDocument->source_document_type === $document->source_document_type
                && (int) $sourceDocument->source_document_id === (int) $document->source_document_id
                && $sourceIssue->source_line_type === $line->source_line_type
                && (int) $sourceIssue->source_line_id === (int) $line->source_line_id;
        }

        if ($document->document_type !== InventoryDocument::TypeSalesReturnReceipt
            || $document->source_document_type !== SalesReturn::class
            || $line->source_line_type !== SalesReturnLine::class) {
            return false;
        }

        $salesReturn = SalesReturn::query()
            ->where('company_id', $document->company_id)
            ->lockForUpdate()
            ->find($document->source_document_id);
        $returnLine = $salesReturn instanceof SalesReturn
            ? SalesReturnLine::query()
                ->where('sales_return_id', $salesReturn->getKey())
                ->where('product_id', $line->product_id)
                ->lockForUpdate()
                ->find($line->source_line_id)
            : null;
        $deliveryLine = $returnLine instanceof SalesReturnLine
            ? InventoryDocumentLine::query()
                ->lockForUpdate()
                ->find($returnLine->delivery_line_id)
            : null;

        return $salesReturn instanceof SalesReturn
            && $returnLine instanceof SalesReturnLine
            && $deliveryLine instanceof InventoryDocumentLine
            && (int) $deliveryLine->inventory_document_id === (int) $sourceDocument->getKey()
            && $sourceDocument->document_type === InventoryDocument::TypeSalesDelivery
            && $sourceIssue->source_line_type === $deliveryLine->source_line_type
            && (int) $sourceIssue->source_line_id === (int) $deliveryLine->source_line_id;
    }

    private function canonicalSalesReturnSourceIssue(
        InventoryDocument $document,
        InventoryDocumentLine $line,
    ): ?InventoryTransaction {
        if ($document->source_document_type !== SalesReturn::class
            || $line->source_line_type !== SalesReturnLine::class) {
            return null;
        }

        $returnLine = SalesReturnLine::query()
            ->where('sales_return_id', $document->source_document_id)
            ->where('product_id', $line->product_id)
            ->lockForUpdate()
            ->find($line->source_line_id);
        $deliveryLine = $returnLine instanceof SalesReturnLine
            ? InventoryDocumentLine::query()->lockForUpdate()->find($returnLine->delivery_line_id)
            : null;

        if (! $deliveryLine instanceof InventoryDocumentLine) {
            return null;
        }

        return InventoryTransaction::query()
            ->where('source_type', InventoryDocument::class)
            ->where('source_id', $deliveryLine->inventory_document_id)
            ->where('source_line_type', $deliveryLine->source_line_type)
            ->where('source_line_id', $deliveryLine->source_line_id)
            ->where('product_id', $line->product_id)
            ->where('warehouse_location_id', $deliveryLine->warehouse_location_id)
            ->when(
                $deliveryLine->batch_lot !== null,
                fn ($query) => $query->where('batch_lot', $deliveryLine->batch_lot),
                fn ($query) => $query->whereNull('batch_lot'),
            )
            ->where('quantity_in', 0)
            ->where('quantity_out', '>', 0)
            ->where('is_reversal', false)
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    private function assertQualityHoldAuthority(InventoryDocument $document, string $sourceStatus, string $destinationStatus): void
    {
        if (($sourceStatus === InventoryTransaction::StatusQcHold || $destinationStatus === InventoryTransaction::StatusQcHold)
            && $document->source_document_type !== ProductionQualityInspection::class) {
            throw new DomainException(__('production_execution.messages.quality_hold_movement_controlled'));
        }
    }

    private function createTransaction(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        string $direction,
        int $branchStoreId,
        int $branchId,
        mixed $warehouseLocationId,
        string $stockStatus,
        string $quantityIn,
        string $quantityOut,
        ?string $unitCost,
    ): InventoryTransaction {
        $postingKey = "inventory-document:{$document->id}:line:{$line->id}:{$direction}";

        $transaction = InventoryTransaction::query()->firstOrCreate(
            ['posting_key' => $postingKey],
            [
                'company_id' => $document->company_id,
                'financial_period_id' => $document->financial_period_id,
                'branch_id' => $branchId,
                'branch_store_id' => $branchStoreId,
                'branch_hall_id' => $branchId === (int) $document->branch_id ? $document->branch_hall_id : null,
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
                'total_cost' => $unitCost === null
                    ? null
                    : bcmul(bcadd($quantityIn, $quantityOut, 8), $unitCost, 8),
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

    /**
     * Resolve and validate a specific restoration allocation for a maintenance return line.
     * Returns null when the line does not carry explicit allocation lineage, letting the
     * layer service fall back to the general source-issue allocation lookup.
     *
     * Validation:
     *  - Only MaintenanceMaterialReturn documents may use restoration_allocation_id.
     *  - allocation.issue_transaction_id must equal the linked source issue ID.
     *  - allocation layer must exist and match company/store/product/status of the return context.
     *  - return line location, batch, manufacture/expiry must agree with the allocation layer.
     *  - return slice quantity must not exceed allocation quantity.
     *
     * @return Collection<int, InventoryLayerAllocation>|null
     */
    private function resolveRestorationAllocations(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        ?InventoryTransaction $sourceIssue,
    ): ?Collection {
        $allocationId = $line->product_snapshot['restoration_allocation_id'] ?? null;
        if ($allocationId === null || ! is_numeric($allocationId)) {
            return null;
        }

        if ($document->document_type !== InventoryDocument::TypeMaintenanceMaterialReturn) {
            throw new DomainException(__('Only maintenance material returns may use restoration allocation lineage.'));
        }

        $allocation = InventoryLayerAllocation::query()
            ->with(['layer', 'layer.receiptTransaction'])
            ->where('id', (int) $allocationId)
            ->lockForUpdate()
            ->first();

        if ($allocation === null) {
            throw new DomainException(__('The maintenance return references a restoration allocation that does not exist.'));
        }

        if (! $sourceIssue instanceof InventoryTransaction) {
            throw new DomainException(__('Restoration allocation requires a validated source issue transaction.'));
        }

        if ((int) $allocation->issue_transaction_id !== (int) $sourceIssue->getKey()) {
            throw new DomainException(__('The restoration allocation does not belong to the linked source issue.'));
        }

        $layer = $allocation->layer;
        if ($layer === null) {
            throw new DomainException(__('The restoration allocation is missing its receipt layer lineage.'));
        }

        if ((int) $layer->company_id !== (int) $document->company_id
            || (int) $layer->financial_period_id !== (int) $document->financial_period_id
            || (int) $layer->branch_id !== (int) $document->branch_id
            || (int) $layer->branch_store_id !== (int) $document->branch_store_id
            || (int) $layer->product_id !== (int) $line->product_id
            || $layer->stock_status !== $document->destination_stock_status) {
            throw new DomainException(__('The restoration allocation layer does not match the return document context.'));
        }

        $receiptTransaction = $layer->receiptTransaction;
        if ($receiptTransaction !== null
            && $receiptTransaction->production_run_id !== null
            && ($document->production_run_id === null
                || (int) $receiptTransaction->production_run_id !== (int) $document->production_run_id)) {
            throw new DomainException(__('The restoration allocation receipt production run does not match the return document.'));
        }

        $lineLocationId = $line->warehouse_location_id;
        if (($layer->warehouse_location_id ?? null) !== ($lineLocationId ?? null)) {
            throw new DomainException(__('The return line location does not match the restoration allocation layer location.'));
        }

        $lineBatchLot = $line->batch_lot;
        if (($layer->batch_lot ?? null) !== ($lineBatchLot ?? null)) {
            throw new DomainException(__('The return line batch does not match the restoration allocation layer batch.'));
        }

        $lineManufactureDate = $line->manufacture_date?->toDateString();
        $layerManufactureDate = $layer->manufacture_date?->toDateString();
        if (($layerManufactureDate ?? null) !== ($lineManufactureDate ?? null)) {
            throw new DomainException(__('The return line manufacture date does not match the restoration allocation layer.'));
        }

        $lineExpiryDate = $line->expiry_date?->toDateString();
        $layerExpiryDate = $layer->expiry_date?->toDateString();
        if (($layerExpiryDate ?? null) !== ($lineExpiryDate ?? null)) {
            throw new DomainException(__('The return line expiry date does not match the restoration allocation layer.'));
        }

        if (bccomp((string) $line->quantity, (string) $allocation->quantity, 8) > 0) {
            throw new DomainException(__('The return slice quantity exceeds its restoration allocation quantity.'));
        }

        return collect([$allocation]);
    }
}
