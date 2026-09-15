<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\QualityStockHold;

class QualityStockHoldService
{
    public function __construct(private readonly InventoryMovementService $movements) {}

    public function activate(ProductionQualityInspection $inspection): QualityStockHold
    {
        return DB::transaction(function () use ($inspection): QualityStockHold {
            $locked = ProductionQualityInspection::query()
                ->with(['product', 'stockHold'])
                ->lockForUpdate()
                ->findOrFail($inspection->getKey());

            if ($locked->subject_type !== ProductionQualityInspection::SubjectInventoryStock) {
                throw new DomainException(__('production_execution.messages.quality_hold_inventory_only'));
            }
            if ($locked->stockHold?->status === QualityStockHold::StatusActive) {
                return $locked->stockHold;
            }

            $rootId = $locked->root_inspection_id ?? $locked->getKey();
            $inspectionIds = ProductionQualityInspection::query()
                ->where(fn ($query) => $query->whereKey($rootId)->orWhere('root_inspection_id', $rootId))
                ->pluck('id');
            $activeChainHold = QualityStockHold::query()
                ->whereIn('quality_inspection_id', $inspectionIds)
                ->where('status', QualityStockHold::StatusActive)
                ->lockForUpdate()
                ->first();
            if ($activeChainHold instanceof QualityStockHold) {
                return $activeChainHold;
            }

            if (! $locked->product || ! $locked->branch_store_id || bccomp((string) $locked->affected_base_quantity, '0', 8) <= 0) {
                throw new DomainException(__('production_execution.messages.quality_hold_quantity_required'));
            }

            $sourceStatus = (string) ($locked->stock_status ?: InventoryTransaction::StatusAvailable);
            if ($sourceStatus === InventoryTransaction::StatusQcHold) {
                throw new DomainException(__('production_execution.messages.quality_hold_source_must_be_usable'));
            }
            $this->resolveExactPosition($locked, $sourceStatus);

            $document = $this->move(
                $locked,
                $sourceStatus,
                InventoryTransaction::StatusQcHold,
                (string) $locked->affected_base_quantity,
                __('production_execution.quality.inventory_hold_reason', ['number' => $locked->doc_num]),
            );

            $values = [
                'company_id' => $locked->company_id,
                'financial_period_id' => $locked->financial_period_id,
                'branch_id' => $locked->branch_id,
                'quality_inspection_id' => $locked->getKey(),
                'product_id' => $locked->product_id,
                'branch_store_id' => $locked->branch_store_id,
                'warehouse_location_id' => $locked->warehouse_location_id,
                'batch_lot' => $locked->batch_lot,
                'source_stock_status' => $sourceStatus,
                'held_stock_status' => InventoryTransaction::StatusQcHold,
                'base_quantity' => $locked->affected_base_quantity,
                'requested_disposition' => $locked->disposition,
                'status' => QualityStockHold::StatusActive,
                'hold_inventory_document_id' => $document->getKey(),
                'activated_by' => auth()->id(),
                'activated_at' => now(),
                'released_by' => null,
                'released_at' => null,
                'dispositioned_by' => null,
                'dispositioned_at' => null,
                'disposition_inventory_document_id' => null,
            ];

            $hold = $locked->stockHold instanceof QualityStockHold
                ? tap($locked->stockHold)->update($values)
                : QualityStockHold::query()->create($values);

            return $hold->refresh()->load(['holdInventoryDocument', 'product', 'branchStore']);
        });
    }

    public function releaseDraftHold(ProductionQualityInspection $inspection): void
    {
        DB::transaction(function () use ($inspection): void {
            $locked = ProductionQualityInspection::query()->with('stockHold')->lockForUpdate()->findOrFail($inspection->getKey());
            $hold = $locked->stockHold;
            if (! $hold instanceof QualityStockHold || $hold->status !== QualityStockHold::StatusActive) {
                return;
            }

            $document = $this->moveHold(
                $locked,
                $hold,
                $hold->source_stock_status,
                __('production_execution.quality.inventory_draft_release_reason', ['number' => $locked->doc_num]),
            );
            $hold->update([
                'status' => QualityStockHold::StatusReleased,
                'disposition_inventory_document_id' => $document->getKey(),
                'released_by' => auth()->id(),
                'released_at' => now(),
            ]);
        });
    }

    public function applyApprovedDisposition(ProductionQualityInspection $inspection): void
    {
        DB::transaction(function () use ($inspection): void {
            $locked = ProductionQualityInspection::query()->lockForUpdate()->findOrFail($inspection->getKey());
            $rootId = $locked->root_inspection_id ?? $locked->getKey();
            $inspectionIds = ProductionQualityInspection::query()
                ->where(fn ($query) => $query->whereKey($rootId)->orWhere('root_inspection_id', $rootId))
                ->pluck('id');
            $holds = QualityStockHold::query()
                ->whereIn('quality_inspection_id', $inspectionIds)
                ->where('status', QualityStockHold::StatusActive)
                ->lockForUpdate()
                ->get();

            if ($locked->result === 'passed' && $locked->disposition === 'release') {
                foreach ($holds as $hold) {
                    $document = $this->moveHold(
                        $locked,
                        $hold,
                        $hold->source_stock_status,
                        __('production_execution.quality.inventory_release_reason', ['number' => $locked->doc_num]),
                    );
                    $hold->update([
                        'status' => QualityStockHold::StatusReleased,
                        'disposition_inventory_document_id' => $document->getKey(),
                        'released_by' => auth()->id(),
                        'released_at' => now(),
                    ]);
                }

                return;
            }

            $targetStatus = match ($locked->disposition) {
                'rework' => InventoryTransaction::StatusRework,
                'scrap' => InventoryTransaction::StatusScrap,
                'return' => InventoryTransaction::StatusRejected,
                default => null,
            };
            if ($targetStatus === null) {
                return;
            }

            foreach ($holds as $hold) {
                $document = $this->moveHold(
                    $locked,
                    $hold,
                    $targetStatus,
                    __('production_execution.quality.inventory_disposition_reason', ['number' => $locked->doc_num]),
                );
                $continuingHold = $locked->disposition === 'rework';
                $hold->update([
                    'status' => $continuingHold ? QualityStockHold::StatusActive : QualityStockHold::StatusDispositioned,
                    'held_stock_status' => $targetStatus,
                    'requested_disposition' => $locked->disposition,
                    'disposition_inventory_document_id' => $document->getKey(),
                    'dispositioned_by' => $continuingHold ? null : auth()->id(),
                    'dispositioned_at' => $continuingHold ? null : now(),
                ]);
            }
        });
    }

    private function moveHold(
        ProductionQualityInspection $inspection,
        QualityStockHold $hold,
        string $destinationStatus,
        string $reason,
    ): InventoryDocument {
        $inspection->forceFill([
            'product_id' => $hold->product_id,
            'branch_store_id' => $hold->branch_store_id,
            'warehouse_location_id' => $hold->warehouse_location_id,
            'batch_lot' => $hold->batch_lot,
        ]);

        return $this->move($inspection, $hold->held_stock_status, $destinationStatus, (string) $hold->base_quantity, $reason);
    }

    private function move(
        ProductionQualityInspection $inspection,
        string $sourceStatus,
        string $destinationStatus,
        string $quantity,
        string $reason,
    ): InventoryDocument {
        $inspection->loadMissing('product');

        return $this->movements->createAndPost([
            'company_id' => $inspection->company_id,
            'financial_period_id' => $inspection->financial_period_id,
            'branch_id' => $inspection->branch_id,
            'branch_store_id' => $inspection->branch_store_id,
            'warehouse_location_id' => $inspection->warehouse_location_id,
            'destination_branch_store_id' => $inspection->branch_store_id,
            'destination_warehouse_location_id' => $inspection->warehouse_location_id,
            'document_type' => InventoryDocument::TypeTransfer,
            'document_date' => now()->toDateString(),
            'purpose' => $reason,
            'movement_reason' => $reason,
            'source_stock_status' => $sourceStatus,
            'destination_stock_status' => $destinationStatus,
            'source_document_type' => ProductionQualityInspection::class,
            'source_document_id' => $inspection->getKey(),
            'source_doc_num' => $inspection->doc_num,
        ], [[
            'product_id' => $inspection->product_id,
            'unit_id' => $inspection->product?->item_unit_id,
            'quantity' => $quantity,
            'warehouse_location_id' => $inspection->warehouse_location_id,
            'destination_warehouse_location_id' => $inspection->warehouse_location_id,
            'batch_lot' => $inspection->batch_lot,
            'source_line_type' => ProductionQualityInspection::class,
            'source_line_id' => $inspection->getKey(),
        ]]);
    }

    private function resolveExactPosition(ProductionQualityInspection $inspection, string $sourceStatus): void
    {
        $positions = InventoryTransaction::query()
            ->where('company_id', $inspection->company_id)
            ->where('branch_store_id', $inspection->branch_store_id)
            ->where('product_id', $inspection->product_id)
            ->where('stock_status', $sourceStatus)
            ->when($inspection->warehouse_location_id !== null, fn ($query) => $query->where('warehouse_location_id', $inspection->warehouse_location_id), fn ($query) => $query->whereNull('warehouse_location_id'))
            ->when($inspection->batch_lot !== null, fn ($query) => $query->where('batch_lot', $inspection->batch_lot), fn ($query) => $query->whereNull('batch_lot'))
            ->groupBy(['warehouse_location_id', 'batch_lot'])
            ->havingRaw('sum(quantity_in - quantity_out) > 0')
            ->get([
                'warehouse_location_id',
                'batch_lot',
                DB::raw('sum(quantity_in - quantity_out) as available_quantity'),
            ]);

        if ($positions->count() !== 1) {
            throw new DomainException(__('production_execution.messages.quality_exact_stock_position_required'));
        }

        $position = $positions->first();
        if (bccomp((string) $inspection->affected_base_quantity, (string) $position->available_quantity, 8) > 0) {
            throw new DomainException(__('production_execution.messages.quality_hold_exceeds_available'));
        }

        $inspection->forceFill([
            'warehouse_location_id' => $position->warehouse_location_id,
            'batch_lot' => $position->batch_lot,
        ])->save();
    }
}
