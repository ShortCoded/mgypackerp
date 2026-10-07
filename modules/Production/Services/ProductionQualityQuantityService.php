<?php

namespace Modules\Production\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\QualityInspectionType;

class ProductionQualityQuantityService
{
    public function __construct(private readonly ProductionOutputEvidenceService $evidence) {}

    public function usesQuantityBatches(ProductionRun $run): bool
    {
        return $run->material_accounting_mode === ProductionOutputEvidenceService::Mode
            || app(ProductionShiftEvidenceService::class)->hasDailyReports($run);
    }

    public function reserve(ProductionRun $run, ?int $typeId, mixed $quantity, ?ProductionQualityInspection $parent = null): ?int
    {
        if (! $this->usesQuantityBatches($run)
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
            && bccomp(app(ProductionStageTransferService::class)->qualityQuantity((int) $inspection->production_quality_output_batch_id), '0', 8) > 0) {
            throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
        }
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
            ->map(function (object $batch) use ($run): object {
                if (app(ProductionStageTransferService::class)->isManaged($run)) {
                    $batch->received_quantity = bcadd($this->decimal($batch->received_quantity), app(ProductionStageTransferService::class)->qualityQuantity((int) $batch->id), 8);
                }
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
            || bccomp((string) $document->lines()->where('production_run_id', $run->id)->sum('quantity'), $quantity, 8) !== 0
            || $document->document_type !== InventoryDocument::TypeProductionReceipt
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

    public function withdrawForDailyCorrection(ProductionRun $run, int $batchId, string $reason, string $evidence): void
    {
        Gate::authorize('production.quality.review');
        DB::transaction(function () use ($run, $batchId, $reason, $evidence): void {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $run = ProductionRun::query()->where('company_id', $companyId)
                ->where('branch_id', request()->session()->get(OperatingContextService::BranchIdKey))->lockForUpdate()->findOrFail($run->id);
            $company = app(OperatingCompanyContextService::class)->currentCompany();
            abort_unless(app(OperatingScopeAccessService::class)->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $run->branch_id)->exists(), 404);
            app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, now()->toDateString());
            app(ProductionDailyReportCorrectionService::class)->assertOwnerRecovery($run);
            $batch = $this->batches($run)->where('id', $batchId)->lockForUpdate()->first();
            abort_if($batch === null, 404);
            if ($batch->withdrawn_at !== null) {
                $this->assertDailyCorrectionWithdrawals($run, $batchId);

                return;
            }
            if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 2000 || mb_strlen(trim($evidence)) < 5 || mb_strlen($evidence) > 2000
                || bccomp($this->received($batchId), '0', 8) !== 0) {
                throw new DomainException(__('production_daily_report.correction.evidence_required'));
            }
            $inspections = ProductionQualityInspection::withTrashed()->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)
                ->where('production_run_id', $run->id)->where('production_quality_output_batch_id', $batchId)->orderBy('id')->lockForUpdate()->get();
            if ($inspections->isEmpty() || $inspections->contains(fn ($inspection): bool => $inspection->trashed()
                || ! in_array($inspection->status, [ProductionQualityInspection::StatusApproved, ProductionQualityInspection::StatusClosed], true)
                || $inspection->approved_at === null || (int) $inspection->approved_by === (int) auth()->id())) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            $snapshot = ['batch' => (array) $batch, 'inspections' => $inspections->map->getRawOriginal()->all()];
            $this->batches($run)->where('id', $batchId)->update(['withdrawn_at' => now(), 'updated_at' => now()]);
            app(ActivityLogger::class)->log(request(), 'production', 'production.quality.batch_withdrawn', 'success', [
                'subject' => $run, 'company_id' => $run->company_id, 'properties_only' => true,
                'properties' => ['batch_id' => $batchId, 'reason' => trim($reason), 'evidence' => trim($evidence), 'snapshot' => $snapshot,
                    'seal' => hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), (string) config('app.key'))],
            ]);
        }, 3);
    }

    public function assertDailyCorrectionWithdrawals(ProductionRun $run, ?int $batchId = null): void
    {
        foreach ($this->batches($run)->when($batchId !== null, fn ($query) => $query->where('id', $batchId))->orderBy('id')->get() as $batch) {
            if ($batch->withdrawn_at === null) {
                throw new DomainException(__('production_daily_report.correction.quality_withdrawal_required'));
            }
            $audit = DB::table('activity_log')->where('company_id', $run->company_id)->where('subject_type', ProductionRun::class)
                ->where('subject_id', $run->id)->where('event', 'production.quality.batch_withdrawn')->where('properties->batch_id', $batch->id)->latest('id')->first();
            $proof = $audit === null ? [] : json_decode($audit->properties, true, flags: JSON_THROW_ON_ERROR);
            $snapshot = $proof['snapshot'] ?? [];
            $current = (array) $batch;
            unset($current['withdrawn_at'], $current['updated_at']);
            $original = $snapshot['batch'] ?? [];
            unset($original['withdrawn_at'], $original['updated_at']);
            $inspections = ProductionQualityInspection::withTrashed()->where('production_quality_output_batch_id', $batch->id)->orderBy('id')->get();
            if ($audit === null || ! hash_equals($proof['seal'] ?? '', hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), (string) config('app.key')))
                || $current !== $original || ($snapshot['inspections'] ?? []) !== $inspections->map->getRawOriginal()->all()
                || $inspections->contains(fn ($inspection): bool => (int) $inspection->approved_by === (int) $audit->causer_id)
                || bccomp($this->received((int) $batch->id), '0', 8) !== 0) {
                throw new DomainException(__('production_daily_report.correction.quality_withdrawal_required'));
            }
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
        return bcadd($this->decimal($this->receipts()->where('batch.id', $batchId)->sum('allocation.base_quantity')),
            app(ProductionStageTransferService::class)->qualityQuantity($batchId), 8);
    }

    private function receipts(): Builder
    {
        return DB::table('production_quality_receipt_allocations as allocation')
            ->join('production_quality_output_batches as batch', 'batch.id', '=', 'allocation.production_quality_output_batch_id')
            ->join('inventory_documents as document', 'document.id', '=', 'allocation.inventory_document_id')
            ->whereColumn('document.company_id', 'batch.company_id')->whereColumn('document.branch_id', 'batch.branch_id')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('inventory_document_lines as receipt_line')
                ->whereColumn('receipt_line.inventory_document_id', 'document.id')->whereColumn('receipt_line.production_run_id', 'batch.production_run_id')->whereNull('receipt_line.deleted_at'))
            ->where('document.document_type', InventoryDocument::TypeProductionReceipt)
            ->where('document.status', InventoryDocument::StatusPosted)->whereNull('document.deleted_at');
    }

    private function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(8, RoundingMode::HalfUp);
    }
}
