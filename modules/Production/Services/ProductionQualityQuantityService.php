<?php

namespace Modules\Production\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\QualityInspectionType;

class ProductionQualityQuantityService
{
    public function __construct(private readonly ProductionOutputEvidenceService $evidence) {}

    public function reserve(ProductionRun $run, ?int $typeId, mixed $quantity, ?ProductionQualityInspection $parent = null): ?int
    {
        if ($run->material_accounting_mode !== ProductionOutputEvidenceService::Mode
            || ! QualityInspectionType::query()->where('company_id', $run->company_id)->whereKey($typeId)->where('is_final_production', true)->where('is_active', true)->exists()) {
            return null;
        }
        if (DB::transactionLevel() < 1) {
            throw new DomainException(__('production_execution.evidence.transaction_required'));
        }
        $amount = $this->evidence->quantity($quantity ?? $parent?->affected_base_quantity ?? '0');
        if (bccomp($amount, '0', 8) <= 0) {
            throw new DomainException(__('production_execution.evidence.quality_quantity_required'));
        }
        if ($parent?->production_quality_output_batch_id !== null) {
            $batch = $this->batches($run)->where('id', $parent->production_quality_output_batch_id)->lockForUpdate()->first();
            if ($batch === null || $batch->withdrawn_at !== null || bccomp($amount, (string) $batch->base_quantity, 8) !== 0) {
                throw new DomainException(__('production_execution.evidence.quality_batch_invalid'));
            }

            return (int) $batch->id;
        }
        $reserved = $this->decimal($this->batches($run)->whereNull('withdrawn_at')->sum('base_quantity'));
        if (bccomp(bcadd($reserved, $amount, 8), (string) $run->good_base_quantity, 8) > 0) {
            throw new DomainException(__('production_execution.evidence.quality_quantity_exceeded'));
        }

        return DB::table('production_quality_output_batches')->insertGetId([
            'company_id' => $run->company_id,
            'branch_id' => $run->branch_id,
            'production_run_id' => $run->id,
            'correction_sequence' => $run->correction_sequence,
            'batch_number' => ((int) $this->batches($run)->max('batch_number')) + 1,
            'base_quantity' => $amount,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function assertInspectionQuantity(ProductionQualityInspection $inspection, mixed $affected, mixed $accepted = null): void
    {
        if ($inspection->production_quality_output_batch_id === null) {
            return;
        }
        $batch = $this->batches($inspection->run)->where('id', $inspection->production_quality_output_batch_id)->lockForUpdate()->first();
        $amount = $this->evidence->quantity($affected ?? $inspection->affected_base_quantity ?? '0');
        $acceptedAmount = $this->evidence->quantity($accepted ?? $inspection->accepted_base_quantity ?? $amount);
        if ($batch === null || $batch->withdrawn_at !== null || bccomp($amount, (string) $batch->base_quantity, 8) !== 0
            || bccomp($acceptedAmount, $amount, 8) > 0 || bccomp($acceptedAmount, $this->received((int) $batch->id), 8) < 0) {
            throw new DomainException(__('production_execution.evidence.quality_batch_invalid'));
        }
    }

    public function assertFailedDispositionAllowed(ProductionQualityInspection $inspection, string $result, string $disposition): void
    {
        if ($inspection->production_quality_output_batch_id !== null && ($result !== 'passed' || $disposition !== 'release')
            && bccomp($this->received((int) $inspection->production_quality_output_batch_id), '0', 8) > 0) {
            throw new DomainException(__('production_execution.evidence.received_batch_requires_stock_quality'));
        }
    }

    /** @return Collection<int, object> */
    public function availableBatches(ProductionRun $run): Collection
    {
        $latest = DB::table('quality_inspections')->selectRaw('production_quality_output_batch_id, max(id) as inspection_id')
            ->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)->where('production_run_id', $run->id)
            ->where('correction_sequence', $run->correction_sequence)->whereNull('deleted_at')
            ->whereNotNull('production_quality_output_batch_id')->groupBy('production_quality_output_batch_id');
        $received = $this->receipts()->where('batch.company_id', $run->company_id)->where('batch.branch_id', $run->branch_id)
            ->where('batch.production_run_id', $run->id)->where('batch.correction_sequence', $run->correction_sequence)
            ->groupBy('batch.id')->selectRaw('batch.id as batch_id, sum(allocation.base_quantity) as received_quantity');

        return $this->batches($run)->whereNull('production_quality_output_batches.withdrawn_at')
            ->joinSub($latest, 'latest', fn ($join) => $join->on('latest.production_quality_output_batch_id', '=', 'production_quality_output_batches.id'))
            ->join('quality_inspections as inspection', 'inspection.id', '=', 'latest.inspection_id')
            ->leftJoinSub($received, 'received', fn ($join) => $join->on('received.batch_id', '=', 'production_quality_output_batches.id'))
            ->whereIn('inspection.status', [ProductionQualityInspection::StatusApproved, ProductionQualityInspection::StatusClosed])
            ->whereNotNull('inspection.approved_at')->where('inspection.result', 'passed')->where('inspection.disposition', 'release')
            ->select(['production_quality_output_batches.id', 'production_quality_output_batches.batch_number'])
            ->selectRaw('coalesce(inspection.accepted_base_quantity, production_quality_output_batches.base_quantity) as accepted_quantity, coalesce(received.received_quantity, 0) as received_quantity')
            ->orderBy('production_quality_output_batches.batch_number')->get()
            ->map(function (object $batch): object {
                $batch->available_quantity = bcsub($this->decimal($batch->accepted_quantity), $this->decimal($batch->received_quantity), 8);

                return $batch;
            })->filter(fn (object $batch): bool => bccomp($batch->available_quantity, '0', 8) > 0)->values();
    }

    public function availableQuantity(ProductionRun $run): string
    {
        return $this->availableBatches($run)->reduce(fn (string $sum, object $batch): string => bcadd($sum, $batch->available_quantity, 8), '0.00000000');
    }

    public function allocateReceipt(ProductionRun $run, InventoryDocument $document, string $quantity): void
    {
        if ((int) $document->company_id !== (int) $run->company_id || (int) $document->branch_id !== (int) $run->branch_id
            || (int) $document->production_run_id !== (int) $run->id || $document->document_type !== InventoryDocument::TypeProductionReceipt
            || $document->status !== InventoryDocument::StatusPosted || DB::transactionLevel() < 1) {
            throw new DomainException(__('production_execution.evidence.quality_batch_invalid'));
        }
        $remaining = $quantity;
        foreach ($this->availableBatches($run) as $batch) {
            if (bccomp($remaining, '0', 8) <= 0) {
                break;
            }
            $slice = bccomp($remaining, $batch->available_quantity, 8) <= 0 ? $remaining : $batch->available_quantity;
            DB::table('production_quality_receipt_allocations')->insert([
                'production_quality_output_batch_id' => $batch->id,
                'inventory_document_id' => $document->id,
                'base_quantity' => $slice,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $remaining = bcsub($remaining, $slice, 8);
        }
        if (bccomp($remaining, '0', 8) !== 0) {
            throw new DomainException(__('production_execution.evidence.quality_receipt_exceeded'));
        }
    }

    public function withdrawDeletedDraft(ProductionQualityInspection $inspection): void
    {
        $id = $inspection->production_quality_output_batch_id;
        if ($id !== null && ! ProductionQualityInspection::query()->where('production_quality_output_batch_id', $id)->exists()
            && bccomp($this->received((int) $id), '0', 8) === 0) {
            $this->batches($inspection->run)->where('id', $id)->update(['withdrawn_at' => now(), 'updated_at' => now()]);
        }
    }

    public function reactivateRestoredDraft(ProductionQualityInspection $inspection): void
    {
        $id = $inspection->production_quality_output_batch_id;
        if ($id === null) {
            return;
        }
        $batch = $this->batches($inspection->run)->where('id', $id)->lockForUpdate()->first();
        if ($batch === null) {
            throw new DomainException(__('production_execution.evidence.quality_batch_invalid'));
        }
        if ($batch->withdrawn_at !== null) {
            $reserved = $this->decimal($this->batches($inspection->run)->whereNull('withdrawn_at')->sum('base_quantity'));
            if (bccomp(bcadd($reserved, (string) $batch->base_quantity, 8), (string) $inspection->run->good_base_quantity, 8) > 0) {
                throw new DomainException(__('production_execution.evidence.quality_quantity_exceeded'));
            }
            $this->batches($inspection->run)->where('id', $id)->update(['withdrawn_at' => null, 'updated_at' => now()]);
        }
    }

    private function batches(ProductionRun $run): Builder
    {
        return DB::table('production_quality_output_batches')->where('production_quality_output_batches.company_id', $run->company_id)
            ->where('production_quality_output_batches.branch_id', $run->branch_id)->where('production_quality_output_batches.production_run_id', $run->id)
            ->where('production_quality_output_batches.correction_sequence', $run->correction_sequence);
    }

    private function received(int $batchId): string
    {
        return $this->decimal($this->receipts()->where('batch.id', $batchId)->sum('allocation.base_quantity'));
    }

    private function receipts(): Builder
    {
        return DB::table('production_quality_receipt_allocations as allocation')
            ->join('production_quality_output_batches as batch', 'batch.id', '=', 'allocation.production_quality_output_batch_id')
            ->join('inventory_documents as document', 'document.id', '=', 'allocation.inventory_document_id')
            ->whereColumn('document.company_id', 'batch.company_id')->whereColumn('document.branch_id', 'batch.branch_id')
            ->whereColumn('document.production_run_id', 'batch.production_run_id')->where('document.document_type', InventoryDocument::TypeProductionReceipt)
            ->where('document.status', InventoryDocument::StatusPosted)->whereNull('document.deleted_at');
    }

    private function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(8, RoundingMode::HalfUp);
    }
}
