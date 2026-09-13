<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionQualityInspectionReport;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\QualityInspectionType;

class ProductionQualityWorkflowService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $context,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(?ProductionRun $run, array $data, ?ProductionQualityInspection $parent = null): ProductionQualityInspection
    {
        return DB::transaction(function () use ($run, $data, $parent): ProductionQualityInspection {
            $context = $this->requiredContext();
            $subjectType = (string) ($data['subject_type'] ?? $parent?->subject_type ?? ProductionQualityInspection::SubjectProductionRun);
            $lockedRun = $run instanceof ProductionRun
                ? ProductionRun::query()->with('order')->lockForUpdate()->findOrFail($run->getKey())
                : null;

            if ($subjectType === ProductionQualityInspection::SubjectProductionRun) {
                if (! $lockedRun instanceof ProductionRun) {
                    throw new DomainException(__('production_execution.messages.quality_run_required'));
                }
                $this->assertContext($lockedRun, $context);
                if (! in_array($lockedRun->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld], true)) {
                    throw new DomainException(__('production_execution.messages.quality_run_not_open'));
                }
            }

            $lockedParent = $parent instanceof ProductionQualityInspection
                ? ProductionQualityInspection::query()->lockForUpdate()->findOrFail($parent->getKey())
                : null;
            if ($lockedParent instanceof ProductionQualityInspection) {
                $this->assertReinspectionAllowed($lockedParent, $context);
            }

            $productId = $lockedRun?->product_id ?? $data['product_id'] ?? $lockedParent?->product_id;
            if ($productId !== null && ! Product::query()->where('company_id', $context['company_id'])->where('status', 'active')->lockForUpdate()->whereKey($productId)->exists()) {
                throw new DomainException(__('production_execution.messages.quality_product_invalid'));
            }
            $storeId = $data['branch_store_id'] ?? $lockedParent?->branch_store_id;
            if ($subjectType === ProductionQualityInspection::SubjectInventoryStock
                && ($storeId === null || ! BranchStore::query()->where('branch_id', $context['branch_id'])->lockForUpdate()->whereKey($storeId)->exists())) {
                throw new DomainException(__('production_execution.messages.quality_store_invalid'));
            }

            $inspectionTypeId = $data['quality_inspection_type_id'] ?? $lockedParent?->quality_inspection_type_id;
            $this->assertInspectionType($context['company_id'], $inspectionTypeId);

            $rootInspectionId = $lockedParent?->root_inspection_id ?? $lockedParent?->getKey();
            $version = 1;
            $reinspectionNumber = 0;
            if ($rootInspectionId !== null) {
                $chain = ProductionQualityInspection::query()
                    ->where(fn ($query) => $query->whereKey($rootInspectionId)->orWhere('root_inspection_id', $rootInspectionId))
                    ->lockForUpdate()
                    ->get(['version', 'reinspection_number']);
                $version = ((int) $chain->max('version')) + 1;
                $reinspectionNumber = ((int) $chain->max('reinspection_number')) + 1;
            }

            $numbers = $this->documents->nextForCompany(
                'quality_inspections',
                ProductionQualityInspection::class,
                $context['company_id'],
                fn ($query) => $query->where('financial_period_id', $context['financial_period_id']),
            );
            $inspection = ProductionQualityInspection::query()->create([
                ...$numbers,
                ...$context,
                'parent_inspection_id' => $lockedParent?->getKey(),
                'root_inspection_id' => $rootInspectionId,
                'subject_type' => $subjectType,
                'production_order_id' => $lockedRun?->production_order_id,
                'production_run_id' => $lockedRun?->getKey(),
                'production_order_stage_id' => $lockedRun?->production_order_stage_snapshot_id,
                'product_id' => $productId,
                'branch_store_id' => $storeId,
                'stock_status' => $data['stock_status'] ?? $lockedParent?->stock_status,
                'batch_lot' => $data['batch_lot'] ?? $lockedParent?->batch_lot,
                'source_reference' => $data['source_reference'] ?? $lockedParent?->source_reference,
                'quality_inspection_type_id' => $inspectionTypeId,
                'version' => $version,
                'reinspection_number' => $reinspectionNumber,
                'inspection_date' => now()->toDateString(),
                'status' => ProductionQualityInspection::StatusDraft,
                'result' => 'pending',
                'affected_base_quantity' => $data['affected_base_quantity'] ?? $lockedParent?->affected_base_quantity,
                'notes' => $data['notes'] ?? null,
                'requested_by' => auth()->id(),
                'requested_at' => now(),
                'created_by' => auth()->id(),
            ]);

            return $inspection->refresh()->load(['run.product', 'product', 'branchStore', 'stageSnapshot', 'qualityType', 'parentInspection']);
        });
    }

    /** @param array<string, mixed> $data */
    public function addReport(ProductionQualityInspection $inspection, array $data): ProductionQualityInspectionReport
    {
        return DB::transaction(function () use ($inspection, $data): ProductionQualityInspectionReport {
            $context = $this->requiredContext();
            $locked = ProductionQualityInspection::query()->with('run')->lockForUpdate()->findOrFail($inspection->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== ProductionQualityInspection::StatusInProgress) {
                throw new DomainException(__('production_execution.messages.quality_report_in_progress_only'));
            }

            $sequence = ((int) $locked->reports()->withTrashed()->lockForUpdate()->max('sequence')) + 1;
            $report = $locked->reports()->create([
                'sequence' => $sequence,
                'reported_at' => $data['reported_at'],
                'result' => $data['result'],
                'disposition' => $data['disposition'] ?? null,
                'defect_code' => $data['defect_code'] ?? null,
                'affected_base_quantity' => $data['affected_base_quantity'] ?? null,
                'observations' => trim($data['observations']),
                'corrective_action' => $data['corrective_action'] ?? null,
                'evidence' => $data['evidence'] ?? null,
                'status' => ProductionQualityInspectionReport::StatusSubmitted,
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
            ]);

            if (($data['result'] === 'failed' || in_array($data['disposition'] ?? null, ['hold', 'rework', 'scrap', 'return'], true))
                && $locked->run?->status === ProductionRun::StatusRunning) {
                $locked->run->update(['status' => ProductionRun::StatusHeld, 'updated_by' => auth()->id()]);
            }

            return $report->refresh()->load('submittedBy');
        });
    }

    public function receive(ProductionQualityInspection $inspection): ProductionQualityInspection
    {
        return $this->transition($inspection, [ProductionQualityInspection::StatusDraft], ProductionQualityInspection::StatusReceived, [
            'received_by' => auth()->id(),
            'received_at' => now(),
        ]);
    }

    public function start(ProductionQualityInspection $inspection): ProductionQualityInspection
    {
        return $this->transition($inspection, [ProductionQualityInspection::StatusReceived], ProductionQualityInspection::StatusInProgress, [
            'inspector_id' => auth()->id(),
            'started_by' => auth()->id(),
            'started_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function submit(ProductionQualityInspection $inspection, array $data): ProductionQualityInspection
    {
        return DB::transaction(function () use ($inspection, $data): ProductionQualityInspection {
            $context = $this->requiredContext();
            $locked = ProductionQualityInspection::query()->with('run')->lockForUpdate()->findOrFail($inspection->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== ProductionQualityInspection::StatusInProgress) {
                throw new DomainException(__('production_execution.messages.quality_submit_in_progress_only'));
            }

            $this->validateResults($locked, $data);
            $locked->results()->delete();
            foreach (array_values($data['results'] ?? []) as $index => $result) {
                $locked->results()->create([
                    'quality_checkpoint_id' => $result['quality_checkpoint_id'],
                    'sequence' => $index + 1,
                    'result' => $result['result'],
                    'measured_value' => $result['measured_value'] ?? null,
                    'notes' => $result['notes'] ?? null,
                    'recorded_by' => auth()->id(),
                    'recorded_at' => now(),
                ]);
            }

            $locked->update([
                'status' => ProductionQualityInspection::StatusSubmitted,
                'result' => $data['result'],
                'disposition' => $data['disposition'],
                'sampled_at' => now(),
                'defect_code' => $data['defect_code'] ?? null,
                'affected_base_quantity' => $data['affected_base_quantity'] ?? $locked->affected_base_quantity,
                'corrective_action' => $data['corrective_action'] ?? null,
                'rework_notes' => $data['rework_notes'] ?? null,
                'notes' => $data['notes'] ?? $locked->notes,
                'evidence' => $data['evidence'] ?? null,
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            if (($data['result'] === 'failed' || $data['disposition'] !== 'release')
                && $locked->run?->status === ProductionRun::StatusRunning) {
                $locked->run->update(['status' => ProductionRun::StatusHeld, 'updated_by' => auth()->id()]);
            }

            return $locked->refresh()->load('results');
        });
    }

    public function review(ProductionQualityInspection $inspection, bool $approved, ?string $reason = null): ProductionQualityInspection
    {
        return DB::transaction(function () use ($inspection, $approved, $reason): ProductionQualityInspection {
            $context = $this->requiredContext();
            $locked = ProductionQualityInspection::query()->with('run')->lockForUpdate()->findOrFail($inspection->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== ProductionQualityInspection::StatusSubmitted) {
                throw new DomainException(__('production_execution.messages.quality_review_submitted_only'));
            }
            if (! $approved && blank($reason)) {
                throw new DomainException(__('production_execution.messages.quality_rejection_reason_required'));
            }

            $released = $approved && $locked->result === 'passed' && $locked->disposition === 'release';
            $locked->update($approved ? [
                'status' => ProductionQualityInspection::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'released_by' => $released ? auth()->id() : null,
                'released_at' => $released ? now() : null,
                'updated_by' => auth()->id(),
            ] : [
                'status' => ProductionQualityInspection::StatusRejected,
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'rejection_reason' => trim((string) $reason),
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            if ((! $approved || $locked->result === 'failed' || $locked->disposition !== 'release')
                && $locked->run?->status === ProductionRun::StatusRunning) {
                $locked->run->update(['status' => ProductionRun::StatusHeld, 'updated_by' => auth()->id()]);
            }

            return $locked->refresh();
        });
    }

    public function close(ProductionQualityInspection $inspection, ?string $notes = null): ProductionQualityInspection
    {
        return $this->transition(
            $inspection,
            [ProductionQualityInspection::StatusApproved, ProductionQualityInspection::StatusRejected],
            ProductionQualityInspection::StatusClosed,
            ['closed_by' => auth()->id(), 'closed_at' => now(), 'close_notes' => $notes],
        );
    }

    public function reinspect(ProductionQualityInspection $inspection): ProductionQualityInspection
    {
        $inspection->loadMissing('run');

        return $this->create($inspection->run, [
            'subject_type' => $inspection->subject_type,
            'product_id' => $inspection->product_id,
            'branch_store_id' => $inspection->branch_store_id,
            'stock_status' => $inspection->stock_status,
            'batch_lot' => $inspection->batch_lot,
            'source_reference' => $inspection->source_reference,
            'quality_inspection_type_id' => $inspection->quality_inspection_type_id,
            'affected_base_quantity' => $inspection->affected_base_quantity,
            'notes' => __('production_execution.quality.reinspection_of', ['number' => $inspection->doc_num]),
        ], $inspection);
    }

    /** @param list<string> $from @param array<string, mixed> $extra */
    private function transition(ProductionQualityInspection $inspection, array $from, string $to, array $extra): ProductionQualityInspection
    {
        return DB::transaction(function () use ($inspection, $from, $to, $extra): ProductionQualityInspection {
            $context = $this->requiredContext();
            $locked = ProductionQualityInspection::query()->lockForUpdate()->findOrFail($inspection->getKey());
            $this->assertContext($locked, $context);
            if (! in_array($locked->status, $from, true)) {
                throw new DomainException(__('production_execution.messages.quality_invalid_transition'));
            }
            $locked->update([...$extra, 'status' => $to, 'updated_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function validateResults(ProductionQualityInspection $inspection, array $data): void
    {
        $checkpointIds = collect($data['results'] ?? [])->pluck('quality_checkpoint_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $activeCheckpoints = $this->activeCheckpoints($inspection);

        if ($activeCheckpoints->whereIn('id', $checkpointIds)->count() !== $checkpointIds->count()) {
            throw new DomainException(__('production_execution.messages.quality_checkpoint_invalid'));
        }
        if ($activeCheckpoints->where('is_required', true)->pluck('id')->diff($checkpointIds)->isNotEmpty()) {
            throw new DomainException(__('production_execution.messages.required_quality_checkpoints_missing'));
        }

        $resultsByCheckpoint = collect($data['results'] ?? [])->keyBy(fn (array $result): int => (int) $result['quality_checkpoint_id']);
        $missingMeasurements = $activeCheckpoints
            ->where('is_required', true)
            ->where('response_type', 'numeric')
            ->pluck('id')
            ->filter(fn (int $checkpointId): bool => blank($resultsByCheckpoint->get($checkpointId)['measured_value'] ?? null));
        if ($missingMeasurements->isNotEmpty()) {
            throw new DomainException(__('production_execution.messages.required_quality_measurements_missing'));
        }

        $checkpointResults = collect($data['results'] ?? [])->pluck('result');
        if (($checkpointResults->contains('failed') && $data['result'] !== 'failed')
            || ($checkpointResults->contains('conditional') && $data['result'] === 'passed')) {
            throw new DomainException(__('production_execution.messages.quality_overall_result_inconsistent'));
        }
        if ($data['result'] === 'failed' && $data['disposition'] === 'release') {
            throw new DomainException(__('production_execution.messages.failed_quality_cannot_release'));
        }
    }

    /** @return Collection<int, object> */
    private function activeCheckpoints(ProductionQualityInspection $inspection): Collection
    {
        if ($inspection->quality_inspection_type_id === null) {
            return collect();
        }

        return DB::table('quality_checkpoints')
            ->where('company_id', $inspection->company_id)
            ->where('quality_inspection_type_id', $inspection->quality_inspection_type_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sequence')
            ->get(['id', 'response_type', 'is_required']);
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function assertReinspectionAllowed(ProductionQualityInspection $parent, array $context): void
    {
        $this->assertContext($parent, $context);
        if ($parent->status !== ProductionQualityInspection::StatusClosed) {
            throw new DomainException(__('production_execution.messages.quality_reinspection_not_allowed'));
        }
        $rootInspectionId = $parent->root_inspection_id ?? $parent->getKey();
        if (ProductionQualityInspection::query()
            ->where(fn ($query) => $query->whereKey($rootInspectionId)->orWhere('root_inspection_id', $rootInspectionId))
            ->whereKeyNot($parent->getKey())
            ->whereNotIn('status', [ProductionQualityInspection::StatusClosed])
            ->exists()) {
            throw new DomainException(__('production_execution.messages.quality_reinspection_open_exists'));
        }
    }

    private function assertInspectionType(int $companyId, mixed $inspectionTypeId): void
    {
        if ($inspectionTypeId === null) {
            return;
        }
        if (! QualityInspectionType::query()->whereKey($inspectionTypeId)->where('company_id', $companyId)->where('is_active', true)->exists()) {
            throw new DomainException(__('production_execution.messages.quality_type_invalid'));
        }
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(): array
    {
        $context = $this->context->snapshot(request());
        if (! $context['company_id'] || ! $context['financial_period_id'] || ! $context['branch_id']) {
            throw new DomainException(__('production_execution.messages.operating_context_required'));
        }

        return ['company_id' => (int) $context['company_id'], 'financial_period_id' => (int) $context['financial_period_id'], 'branch_id' => (int) $context['branch_id']];
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function assertContext(Model $record, array $context): void
    {
        if ((int) $record->getAttribute('company_id') !== $context['company_id']
            || (int) $record->getAttribute('financial_period_id') !== $context['financial_period_id']
            || (int) $record->getAttribute('branch_id') !== $context['branch_id']) {
            throw new DomainException(__('production_execution.messages.document_outside_context'));
        }
    }
}
