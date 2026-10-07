<?php

namespace Modules\Production\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryAccountingPostingService;
use Modules\Production\Models\ProductionProgressEntry;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionStageOutputCostOwner;

final class ProductionStageOutputCostService
{
    public const OutputFields = ['good' => 'good_base_quantity', 'rejected' => 'rejected_base_quantity', 'rework' => 'rework_base_quantity', 'scrap' => 'scrap_base_quantity'];

    public function requireSchema(): void
    {
        if (! Schema::hasTable('production_stage_output_cost_owners') || ! Schema::hasColumn('production_stage_input_consumptions', 'cost_components')) {
            throw new DomainException(__('production_stage_transfer.component_migration_required'));
        }
    }

    /** @return array<string, mixed> */
    public function preview(ProductionRun $run): array
    {
        Gate::authorize('production.runs.view');
        $this->requireSchema();
        $run = $this->scoped($run);
        $this->assertManaged($run);
        $snapshot = $this->allocationSource($run);

        return ['record' => $run, 'fingerprint' => $this->digest($snapshot), 'quantities' => $this->quantities($run),
            'components' => app(ProductionStageCostComponentService::class)->outputPool($run), 'position' => $this->position($run)];
    }

    /** @param array<string, int> $qualityIds */
    public function prepareAllocation(ProductionRun $run, string $scrapTreatment, bool $completedStageUnits, array $qualityIds, string $reason, string $evidence, string $fingerprint, string $token): ProductionStageOutputCostOwner
    {
        Gate::authorize('production.runs.account_materials');
        $this->requireSchema();

        return DB::transaction(function () use ($run, $scrapTreatment, $completedStageUnits, $qualityIds, $reason, $evidence, $fingerprint, $token): ProductionStageOutputCostOwner {
            $run = $this->lockRun($run);
            $this->text($reason, $evidence);
            $existing = $this->existing($run, $token);
            if ($existing !== null) {
                if ($existing->kind !== 'allocation' || $existing->posting_snapshot['scrap_treatment'] !== $scrapTreatment
                    || $existing->posting_snapshot['quality_ids'] !== $qualityIds || ! $completedStageUnits
                    || $existing->reason !== trim($reason) || $existing->evidence !== trim($evidence)) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }

                return $existing;
            }
            $this->assertUnabsorbed($run);
            if ($this->activeAllocation($run) !== null || ! $completedStageUnits) {
                throw new DomainException(__('production_stage_transfer.allocation_basis_required'));
            }
            $source = $this->allocationSource($run);
            if (! hash_equals($this->digest($source), $fingerprint)) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $posting = $this->allocationPosting($run, $scrapTreatment, $qualityIds);

            return $this->create($run, 'allocation', $token, $source, $posting, $reason, $evidence);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function recoveryPreview(ProductionStageOutputCostOwner $parent): array
    {
        Gate::authorize('production.runs.view');
        $parent = $this->scopedOwner($parent);
        $this->assertOwner($parent);
        $run = $this->scoped($parent->run);

        return ['parent' => $parent, 'position' => $this->position($run), 'fingerprint' => $this->digest($this->recoverySource($run, $parent))];
    }

    public function prepareRecovery(ProductionStageOutputCostOwner $parent, string $kind, string $quantity, int $qualityId, string $reason, string $evidence, string $fingerprint, string $token): ProductionStageOutputCostOwner
    {
        Gate::authorize('production.runs.account_materials');
        $this->requireSchema();

        return DB::transaction(function () use ($parent, $kind, $quantity, $qualityId, $reason, $evidence, $fingerprint, $token): ProductionStageOutputCostOwner {
            $run = $this->lockRun($parent->run);
            $parent = $this->scopedOwner($parent, true);
            $this->assertOwner($parent);
            $this->text($reason, $evidence);
            $quantity = app(ProductionOutputEvidenceService::class)->quantity($quantity);
            $existing = $this->existing($run, $token);
            if ($existing !== null) {
                if ($existing->kind !== 'recovery' || (int) $existing->parent_owner_id !== (int) $parent->id || $existing->output_kind !== $kind
                    || (int) $existing->quality_inspection_id !== $qualityId || bccomp((string) $existing->base_quantity, $quantity, 8) !== 0
                    || $existing->reason !== trim($reason) || $existing->evidence !== trim($evidence)) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }

                return $existing;
            }
            if ($parent->kind !== 'allocation' || ! in_array($kind, ['rejected', 'rework'], true)) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            app(ProductionPieceOutputApprovalService::class)->assertMutable($run);
            $source = $this->recoverySource($run, $parent);
            if (! hash_equals($this->digest($source), $fingerprint)) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $posting = $this->recoveryPosting($run, $parent, $kind, $quantity, $qualityId);

            return $this->create($run, 'recovery', $token, $source, $posting, $reason, $evidence, $parent);
        }, 3);
    }

    public function approve(ProductionStageOutputCostOwner $owner): ProductionStageOutputCostOwner
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($owner): ProductionStageOutputCostOwner {
            $run = $this->lockRun($owner->run);
            $owner = $this->scopedOwner($owner, true);
            $this->assertSeal($owner);
            if ((int) $owner->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            if ($owner->status === 'posted') {
                $this->assertOwner($owner);

                return $owner;
            }
            if ($owner->status !== 'prepared' || $owner->posting_date->toDateString() !== now()->toDateString()) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            if ($owner->kind === 'allocation') {
                $this->assertUnabsorbed($run);
                if ($this->activeAllocation($run) !== null) {
                    throw new DomainException(__('production_run_correction.stale'));
                }
                $source = $this->allocationSource($run);
                $posting = $this->allocationPosting($run, $owner->posting_snapshot['scrap_treatment'], $owner->posting_snapshot['quality_ids']);
            } else {
                $parent = $this->scopedOwner($owner->parentOwner, true);
                $this->assertOwner($parent);
                $source = $this->recoverySource($run, $parent);
                $posting = $this->recoveryPosting($run, $parent, $owner->output_kind, (string) $owner->base_quantity, (int) $owner->quality_inspection_id);
            }
            if (! hash_equals($this->digest($owner->source_snapshot), $this->digest($source))
                || ! hash_equals($this->digest($owner->posting_snapshot), $this->digest($posting))) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $journal = bccomp((string) $owner->booked_loss_amount, '0', 4) > 0
                ? app(JournalEntryService::class)->createPostedFromSource($this->journalHeader($owner), $this->journalLines($owner)) : null;
            $execution = [];
            if ($owner->kind === 'recovery') {
                $execution = $this->recoveryProgress($run, $owner, false);
            }
            $owner->forceFill(['status' => 'posted', 'journal_entry_id' => $journal?->id, 'approved_by' => auth()->id(), 'approved_at' => now()->startOfSecond(),
                'execution_snapshot' => $execution, 'execution_seal' => $this->digest($execution)])->save();
            $this->audit($owner, 'posted', $this->postedAudit($owner));
            $this->assertOwner($owner);

            return $owner->refresh();
        }, 3);
    }

    public function reject(ProductionStageOutputCostOwner $owner, string $reason): ProductionStageOutputCostOwner
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($owner, $reason): ProductionStageOutputCostOwner {
            $this->lockRun($owner->run, recovery: true);
            $owner = $this->scopedOwner($owner, true);
            $this->assertSeal($owner);
            if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 2000) {
                throw new DomainException(__('production_execution.messages.reversal_reason_required'));
            }
            if ($owner->status === 'rejected') {
                return $owner;
            }
            if ($owner->status !== 'prepared' || $owner->journal_entry_id !== null || $owner->approved_at !== null
                || $owner->approved_by !== null || $owner->reversal_journal_entry_id !== null
                || JournalEntry::query()->where('company_id', $owner->company_id)->where('source_type', 'production_stage_output_loss')->where('source_id', $owner->id)->exists()) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            $owner->forceFill(['status' => 'rejected'])->save();
            $this->audit($owner, 'rejected', ['proposal_seal' => $owner->proposal_seal, 'reason' => trim($reason)]);

            return $owner->refresh();
        }, 3);
    }

    public function reverse(ProductionStageOutputCostOwner $owner, string $reason): ProductionStageOutputCostOwner
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($owner, $reason): ProductionStageOutputCostOwner {
            $run = $this->lockRun($owner->run, recovery: true);
            $owner = $this->scopedOwner($owner, true);
            $this->text($reason, $reason);
            if ((int) $owner->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            if ($owner->status === 'reversed') {
                $this->assertOwner($owner, true);

                return $owner;
            }
            $this->assertOwner($owner);
            $this->assertUnabsorbed($run);
            $reversalPeriod = app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, now()->toDateString());
            if ($owner->kind === 'allocation' && ProductionStageOutputCostOwner::query()->where('parent_owner_id', $owner->id)->where('status', 'posted')->exists()) {
                throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
            }
            $inverse = $owner->journal_entry_id === null ? null : app(JournalEntryService::class)->createPostedReversalFromSource(
                JournalEntry::query()->findOrFail($owner->journal_entry_id), [...$this->journalHeader($owner),
                    'entry_date' => now()->toDateString(), 'financial_period_id' => $reversalPeriod->id, 'source_type' => 'production_stage_output_loss_reversal', 'description' => trim($reason)]);
            $execution = $owner->kind === 'recovery' ? $this->recoveryProgress($run, $owner, true) : [];
            $owner->forceFill(['status' => 'reversed', 'reversal_journal_entry_id' => $inverse?->id, 'reversal_posting_financial_period_id' => $reversalPeriod->id, 'reversed_by' => auth()->id(),
                'reversed_at' => now()->startOfSecond(), 'reversal_reason' => trim($reason), 'reversal_execution_snapshot' => $execution,
                'reversal_execution_seal' => $this->digest($execution)])->save();
            $this->audit($owner, 'reversed', $this->reversedAudit($owner));
            $this->assertOwner($owner, true);

            return $owner->refresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function position(ProductionRun $run): array
    {
        $components = app(ProductionStageCostComponentService::class);
        $result = ['owner_id' => null, 'owner_seal' => null, 'expensed_cost' => '0.00000000', 'excluded_components' => $components->zero(),
            'held_components' => ['rejected' => $components->zero(), 'rework' => $components->zero()],
            'held_quantities' => ['rejected' => '0.00000000', 'rework' => '0.00000000']];
        if (! app(ProductionStageTransferService::class)->isManaged($run) || ! Schema::hasTable('production_stage_output_cost_owners')) {
            return $result;
        }
        $owner = $this->activeAllocation($run);
        if ($owner === null) {
            return $result;
        }
        $this->assertOwner($owner);
        $result['owner_id'] = (int) $owner->id;
        $result['owner_seal'] = $owner->proposal_seal;
        foreach (['rejected', 'rework'] as $kind) {
            $result['held_components'][$kind] = $owner->posting_snapshot['allocations'][$kind]['components'];
            $result['held_quantities'][$kind] = $owner->posting_snapshot['quantities'][$kind];
        }
        $expected = $owner->posting_snapshot['quantities'];
        foreach (ProductionStageOutputCostOwner::query()->where('company_id', $run->company_id)->where('parent_owner_id', $owner->id)
            ->whereIn('status', ['posted', 'reversed'])->orderBy('id')->get() as $recovery) {
            $this->assertOwner($recovery, $recovery->status === 'reversed');
            if ($recovery->status === 'reversed') {
                continue;
            }
            $kind = $recovery->output_kind;
            $result['held_quantities'][$kind] = bcsub($result['held_quantities'][$kind], (string) $recovery->base_quantity, 8);
            $result['held_components'][$kind] = $components->subtract($result['held_components'][$kind], $recovery->posting_snapshot['components']);
            $expected['good'] = bcadd($expected['good'], (string) $recovery->base_quantity, 8);
            $expected[$kind] = bcsub($expected[$kind], (string) $recovery->base_quantity, 8);
        }
        if ($expected !== $this->quantities($run) || collect($result['held_quantities'])->contains(fn (string $quantity): bool => bccomp($quantity, '0', 8) < 0)) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $excluded = $components->add($result['held_components']['rejected'], $result['held_components']['rework']);
        if ($owner->posting_snapshot['scrap_treatment'] === 'abnormal') {
            $scrap = $owner->posting_snapshot['allocations']['scrap']['components'];
            $result['expensed_cost'] = $components->sum($scrap);
            $excluded = $components->add($excluded, $scrap);
        }
        $result['excluded_components'] = $excluded;

        return $result;
    }

    public function assertAllocationAnchor(int $id, string $seal, ProductionRun $run): void
    {
        $owner = ProductionStageOutputCostOwner::query()->where('company_id', $run->company_id)->where('production_run_id', $run->id)->findOrFail($id);
        if ($owner->kind !== 'allocation' || ! hash_equals($owner->proposal_seal, $seal)) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $this->assertOwner($owner);
    }

    public function assertReadyForOutput(ProductionRun $run): void
    {
        if ($this->hasLosses($run) && $this->position($run)['owner_id'] === null) {
            throw new DomainException(__('production_stage_transfer.loss_requires_owner'));
        }
    }

    public function assertCostMutationAllowed(ProductionRun $run): void
    {
        if (Schema::hasTable('production_stage_output_cost_owners') && $this->activeAllocation($run) !== null) {
            throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
        }
    }

    public function assertQualityMutationAllowed(ProductionQualityInspection $inspection): void
    {
        if ($inspection->production_run_id === null || ! Schema::hasTable('production_stage_output_cost_owners')) {
            return;
        }
        foreach (ProductionStageOutputCostOwner::query()->where('company_id', $inspection->company_id)->where('production_run_id', $inspection->production_run_id)->where('status', 'posted')->get() as $owner) {
            if ((int) $owner->quality_inspection_id === (int) $inspection->id
                || in_array((int) $inspection->id, array_map('intval', $owner->posting_snapshot['quality_ids'] ?? []), true)) {
                throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
            }
        }
    }

    public function recognizedLoss(int $companyId, ?int $periodId, ?int $branchId): string
    {
        if (! Schema::hasTable('production_stage_output_cost_owners')) {
            return '0.00000000';
        }
        $amount = '0.00000000';
        foreach (ProductionStageOutputCostOwner::query()->where('company_id', $companyId)->where('kind', 'allocation')
            ->whereIn('status', ['posted', 'reversed'])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))->lazyById() as $owner) {
            $this->assertOwner($owner, $owner->status === 'reversed');
            if ($periodId === null || (int) ($owner->posting_snapshot['posting_financial_period_id'] ?? $owner->financial_period_id) === $periodId) {
                $amount = bcadd($amount, $owner->posting_snapshot['loss_cost'], 8);
            }
            if ($owner->status === 'reversed' && ($periodId === null || (int) ($owner->reversal_posting_financial_period_id ?? $owner->financial_period_id) === $periodId)) {
                $amount = bcsub($amount, $owner->posting_snapshot['loss_cost'], 8);
            }
        }

        return $amount;
    }

    public function lossRoundingDifference(int $companyId, ?int $periodId, ?int $branchId): string
    {
        if (! Schema::hasTable('production_stage_output_cost_owners')) {
            return '0.00000000';
        }
        $difference = '0.00000000';
        foreach (ProductionStageOutputCostOwner::query()->where('company_id', $companyId)->where('kind', 'allocation')->whereIn('status', ['posted', 'reversed'])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))->lazyById() as $owner) {
            $this->assertOwner($owner, $owner->status === 'reversed');
            $value = bcsub($owner->posting_snapshot['loss_cost'], (string) $owner->booked_loss_amount, 8);
            if ($periodId === null || (int) ($owner->posting_snapshot['posting_financial_period_id'] ?? $owner->financial_period_id) === $periodId) {
                $difference = bcadd($difference, $value, 8);
            }
            if ($owner->status === 'reversed' && ($periodId === null || (int) ($owner->reversal_posting_financial_period_id ?? $owner->financial_period_id) === $periodId)) {
                $difference = bcsub($difference, $value, 8);
            }
        }

        return $difference;
    }

    /** @return list<int> */
    public function historicalLossAccountIds(int $companyId): array
    {
        if (! Schema::hasTable('production_stage_output_cost_owners')) {
            return [];
        }
        $expression = DB::getDriverName() === 'pgsql' ? "(posting_snapshot #>> '{loss_account_id}')::bigint" : "cast(json_extract(posting_snapshot, '$.loss_account_id') as integer)";

        return DB::table('production_stage_output_cost_owners')->where('company_id', $companyId)->whereNotNull('journal_entry_id')
            ->selectRaw('distinct '.$expression.' as account_id')->pluck('account_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
    }

    /** @return array<string, string> */
    private function quantities(ProductionRun $run): array
    {
        $result = [];
        foreach (self::OutputFields as $kind => $field) {
            $result[$kind] = bcadd((string) $run->{$field}, '0', 8);
        }

        return $result;
    }

    private function hasLosses(ProductionRun $run): bool
    {
        return collect($this->quantities($run))->except('good')->contains(fn (string $quantity): bool => bccomp($quantity, '0', 8) > 0);
    }

    /** @return array<string, mixed> */
    private function allocationSource(ProductionRun $run): array
    {
        return ['anchors' => $this->anchors($run), 'quantities' => $this->quantities($run),
            'quality' => $run->inspections()->orderBy('id')->get()->map(fn ($inspection): array => $this->qualitySnapshot($inspection))->all()];
    }

    /** @return array<string, mixed> */
    private function anchors(ProductionRun $run): array
    {
        $documents = InventoryDocument::query()->where('company_id', $run->company_id)->where('status', InventoryDocument::StatusPosted)
            ->whereIn('document_type', [InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue, InventoryDocument::TypeMaterialReturn,
                InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste])
            ->where(fn ($query) => $query->where('production_run_id', $run->id)->orWhereIn('id', DB::table('inventory_document_lines')->where('production_run_id', $run->id)->select('inventory_document_id')))
            ->orderBy('id')->get();
        $uses = DB::table('production_stage_input_consumptions')->join('production_progress_entries as progress', 'progress.id', '=', 'production_stage_input_consumptions.production_progress_entry_id')
            ->where('progress.production_run_id', $run->id)->orderBy('production_stage_input_consumptions.id')->get(['production_stage_input_consumptions.*']);

        return ['run_contract' => [...$run->only(['company_id', 'branch_id', 'financial_period_id', 'production_order_id', 'production_order_line_id',
            'production_order_stage_snapshot_id', 'product_id', 'unit_id', 'conversion_factor', 'cost_center_id', 'material_evidence_policy', 'actual_labor_count']),
            'labor_details' => app(ProductionPieceOutputApprovalService::class)->labor($run)],
            'progress' => $run->progressEntries()->whereNull('stage_output_cost_owner_id')->orderBy('id')->get()->map->getRawOriginal()->all(),
            'requirements' => $run->requirements()->orderBy('id')->get()->map->getRawOriginal()->all(),
            'documents' => $documents->map(fn ($document): array => ['header' => $document->getRawOriginal(), 'lines' => $document->lines()->orderBy('id')->get()->map->getRawOriginal()->all(),
                'journal' => $document->journalEntry?->getRawOriginal(), 'journal_lines' => $document->journalEntry?->lines()->orderBy('id')->get()->map->getRawOriginal()->all()])->all(),
            'input_consumptions' => $uses->map(fn ($row): array => (array) $row)->all(),
            'input_adjustments' => Schema::hasTable('production_stage_input_adjustments')
                ? DB::table('production_stage_input_adjustments')->whereIn('production_progress_entry_id', $run->progressEntries()->select('id'))
                    ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all() : [],
            'native_cost_sources' => app(ProductionStageCostComponentService::class)->nativeSources($run)];
    }

    /** @param array<string, int> $qualityIds @return array<string, mixed> */
    private function allocationPosting(ProductionRun $run, string $treatment, array $qualityIds): array
    {
        $this->assertManaged($run);
        $cost = app(ProductionCostService::class)->runPosition($run);
        foreach (['material_valuation_complete', 'expense_valuation_complete', 'labor_valuation_complete'] as $field) {
            if (! $cost[$field]) {
                throw new DomainException(__('production_stage_transfer.unvalued'));
            }
        }
        if ($run->expenseRequests()->whereNotIn('status', ['paid', 'rejected', 'reversed'])->exists()) {
            throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
        }
        $quantities = $this->quantities($run);
        $whole = array_reduce($quantities, fn (string $sum, string $quantity): string => bcadd($sum, $quantity, 8), '0.00000000');
        if (! $this->hasLosses($run) || bccomp($whole, '0', 8) <= 0 || ! in_array($treatment, ['none', 'normal', 'abnormal'], true)
            || (bccomp($quantities['scrap'], '0', 8) > 0 && $treatment === 'none') || (bccomp($quantities['scrap'], '0', 8) === 0 && $treatment !== 'none')) {
            throw new DomainException(__('production_stage_transfer.loss_treatment_required'));
        }
        $stage = app(ProductionStageTransferService::class)->position($run);
        if (app(ProductionStageTransferService::class)->requiresInput($run) && bccomp($stage['used_quantity'], $whole, 8) !== 0) {
            throw new DomainException(__('production_stage_transfer.input_required'));
        }
        foreach (self::OutputFields as $kind => $field) {
            if (bccomp((string) $run->progressEntries()->whereNull('stage_output_cost_owner_id')->sum($field), $quantities[$kind], 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
        }
        $components = app(ProductionStageCostComponentService::class);
        $pool = $components->outputPool($run);
        if (bccomp($components->sum($pool), '0', 8) <= 0 || bccomp($components->sum($pool), $cost['wip'], 8) > 0) {
            throw new DomainException(__('production_stage_transfer.unvalued'));
        }
        $allocations = [];
        $quality = [];
        $remaining = $pool;
        $denominator = $treatment === 'normal' ? bcsub($whole, $quantities['scrap'], 8) : $whole;
        if (bccomp($denominator, '0', 8) <= 0) {
            throw new DomainException(__('production_stage_transfer.loss_treatment_required'));
        }
        $kinds = collect(['rejected', 'rework', 'scrap', 'good'])->filter(fn (string $kind): bool => bccomp($quantities[$kind], '0', 8) > 0 && ! ($kind === 'scrap' && $treatment === 'normal'))->values();
        foreach ($kinds as $index => $kind) {
            $share = $index === $kinds->count() - 1 ? $remaining : $components->share($pool, $quantities[$kind], $denominator);
            $remaining = $components->subtract($remaining, $share);
            $allocations[$kind] = ['quantity' => $quantities[$kind], 'components' => $share, 'total_cost' => $components->sum($share)];
            if ($kind !== 'good') {
                $inspection = $this->quality($run, (int) ($qualityIds[$kind] ?? 0), $kind === 'rejected' ? 'hold' : $kind, $quantities[$kind]);
                $quality[$kind] = $this->qualitySnapshot($inspection);
            }
        }
        if ($treatment === 'normal') {
            $inspection = $this->quality($run, (int) ($qualityIds['scrap'] ?? 0), 'scrap', $quantities['scrap']);
            $quality['scrap'] = $this->qualitySnapshot($inspection);
        }
        foreach (self::OutputFields as $kind => $field) {
            $allocations[$kind] ??= ['quantity' => $quantities[$kind], 'components' => $components->zero(), 'total_cost' => '0.00000000'];
        }
        if (count(array_unique(array_values($qualityIds))) !== count($quality) || count($qualityIds) !== count($quality)) {
            throw new DomainException(__('production_stage_transfer.loss_quality_required'));
        }
        $loss = $treatment === 'abnormal' ? $allocations['scrap']['total_cost'] : '0.00000000';
        $bookedLoss = bcround($loss, 4);

        return ['version' => 1, 'posting_financial_period_id' => app(ProductionCorrectionContextService::class)->executionPeriodId($run), 'basis' => 'declared_equally_completed_stage_units', 'scrap_treatment' => $treatment, 'quantities' => $quantities,
            'components' => $pool, 'allocations' => $allocations, 'total_cost' => $components->sum($pool), 'base_quantity' => $whole, 'loss_cost' => $loss, 'booked_loss_amount' => $bookedLoss,
            'rounding_rule' => 'half_up_stage_output_loss_gl_4_v1', 'rounding_difference' => bcsub($loss, $bookedLoss, 8),
            'quality_ids' => $qualityIds, 'quality' => $quality,
            'currency_id' => (int) Currency::query()->where('company_id', $run->company_id)->where('is_main', true)->where('status', 'active')->sole()->id,
            'source_account_id' => app(ProductionStageTransferService::class)->historicalSourceAccount($run),
            'loss_account_id' => $treatment === 'abnormal' ? (int) app(PostingAccountResolver::class)->resolve((int) $run->company_id, PostingAccountResolver::AbnormalWasteLoss, 'production_stage_output_loss')->id : null];
    }

    /** @return array<string, mixed> */
    private function recoverySource(ProductionRun $run, ProductionStageOutputCostOwner $parent): array
    {
        return ['parent_id' => (int) $parent->id, 'parent_seal' => $parent->proposal_seal, 'run' => $run->getRawOriginal(),
            'position' => $this->position($run), 'quality' => $run->inspections()->orderBy('id')->get()->map(fn ($inspection): array => $this->qualitySnapshot($inspection))->all()];
    }

    /** @return array<string, mixed> */
    private function recoveryPosting(ProductionRun $run, ProductionStageOutputCostOwner $parent, string $kind, string $quantity, int $qualityId): array
    {
        if (! in_array($kind, ['rejected', 'rework'], true)) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $position = $this->position($run);
        if ($position['owner_id'] !== (int) $parent->id || bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $position['held_quantities'][$kind], 8) > 0
            || ProductionStageOutputCostOwner::query()->where('company_id', $run->company_id)->where('quality_inspection_id', $qualityId)->where('status', 'posted')->exists()) {
            throw new DomainException(__('production_stage_transfer.recovery_quantity_exceeded'));
        }
        $inspection = $this->quality($run, $qualityId, 'release', $quantity);
        $components = app(ProductionStageCostComponentService::class);
        $share = $components->share($position['held_components'][$kind], $quantity, $position['held_quantities'][$kind]);

        return ['version' => 1, 'posting_financial_period_id' => app(ProductionCorrectionContextService::class)->executionPeriodId($run), 'parent_id' => (int) $parent->id, 'output_kind' => $kind, 'base_quantity' => $quantity,
            'components' => $share, 'total_cost' => $components->sum($share), 'loss_cost' => '0.00000000', 'quality_id' => $qualityId,
            'quality' => $this->qualitySnapshot($inspection), 'currency_id' => $parent->posting_snapshot['currency_id']];
    }

    private function quality(ProductionRun $run, int $id, string $disposition, string $quantity, ?int $periodId = null): ProductionQualityInspection
    {
        $inspection = ProductionQualityInspection::query()->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)
            ->where('financial_period_id', $periodId ?? app(ProductionCorrectionContextService::class)->executionPeriodId($run))->where('production_run_id', $run->id)->where('correction_sequence', $run->correction_sequence)->find($id);
        if ($inspection === null || ! in_array($inspection->status, [ProductionQualityInspection::StatusApproved, ProductionQualityInspection::StatusClosed], true)
            || $inspection->subject_type !== ProductionQualityInspection::SubjectProductionRun || $inspection->production_quality_output_batch_id !== null
            || $inspection->approved_by === null || $inspection->approved_at === null || $inspection->disposition !== $disposition
            || ($disposition === 'release' && $inspection->result !== 'passed') || ($disposition !== 'release' && $inspection->result === 'passed')
            || bccomp((string) $inspection->affected_base_quantity, $quantity, 8) !== 0 || blank($inspection->evidence)) {
            throw new DomainException(__('production_stage_transfer.loss_quality_required'));
        }

        return $inspection;
    }

    /** @return array<string, mixed> */
    private function qualitySnapshot(ProductionQualityInspection $inspection): array
    {
        return $inspection->only(['id', 'company_id', 'branch_id', 'financial_period_id', 'production_run_id', 'correction_sequence', 'subject_type',
            'production_quality_output_batch_id', 'result', 'disposition', 'affected_base_quantity', 'accepted_base_quantity', 'approved_by', 'approved_at', 'evidence', 'defect_code']);
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $posting */
    private function create(ProductionRun $run, string $kind, string $token, array $source, array $posting, string $reason, string $evidence, ?ProductionStageOutputCostOwner $parent = null): ProductionStageOutputCostOwner
    {
        $owner = new ProductionStageOutputCostOwner(['public_id' => (string) Str::uuid(), 'company_id' => $run->company_id, 'branch_id' => $run->branch_id,
            'financial_period_id' => $run->financial_period_id, 'production_run_id' => $run->id, 'parent_owner_id' => $parent?->id,
            'kind' => $kind, 'output_kind' => $posting['output_kind'] ?? null, 'quality_inspection_id' => $posting['quality_id'] ?? null,
            'submission_token' => $token, 'posting_date' => now()->toDateString(), 'status' => 'prepared', 'base_quantity' => $posting['base_quantity'],
            'total_cost' => $posting['total_cost'], 'booked_loss_amount' => $posting['booked_loss_amount'] ?? '0.0000', 'source_snapshot' => $source, 'posting_snapshot' => $posting,
            'reason' => trim($reason), 'evidence' => trim($evidence), 'prepared_by' => auth()->id()]);
        $owner->proposal_seal = $this->digest($this->proposal($owner));
        $owner->save();
        $this->audit($owner, 'prepared', ['proposal_seal' => $owner->proposal_seal]);

        return $owner;
    }

    /** @return array<string, mixed> */
    private function recoveryProgress(ProductionRun $run, ProductionStageOutputCostOwner $owner, bool $reverse): array
    {
        app(ProductionPieceOutputApprovalService::class)->assertMutable($run);
        $quantity = (string) $owner->base_quantity;
        $good = $reverse ? bcsub('0', $quantity, 8) : $quantity;
        $held = bcsub('0', $good, 8);
        $field = self::OutputFields[$owner->output_kind];
        if (bccomp(bcadd((string) $run->good_base_quantity, $good, 8), '0', 8) < 0 || bccomp(bcadd((string) $run->{$field}, $held, 8), '0', 8) < 0) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $entry = $run->progressEntries()->create(['stage_output_cost_owner_id' => $owner->id, 'good_base_quantity' => $good, $field => $held,
            'recorded_at' => now(), 'recorded_by' => auth()->id(), 'notes' => $owner->reason.' — '.$owner->evidence, 'material_documents' => []]);
        $run->forceFill(['good_base_quantity' => bcadd((string) $run->good_base_quantity, $good, 8), $field => bcadd((string) $run->{$field}, $held, 8), 'updated_by' => auth()->id()])->save();

        return ['progress' => $entry->fresh()->getRawOriginal()];
    }

    private function assertOwner(ProductionStageOutputCostOwner $owner, bool $reversed = false): void
    {
        $this->assertSeal($owner);
        $run = ProductionRun::query()->findOrFail($owner->production_run_id);
        if ($owner->status !== ($reversed ? 'reversed' : 'posted') || $owner->approved_by === null || $owner->approved_at === null
            || (int) $owner->prepared_by === (int) $owner->approved_by || (int) $owner->company_id !== (int) $run->company_id
            || (int) $owner->branch_id !== (int) $run->branch_id || (int) $owner->financial_period_id !== (int) $run->financial_period_id
            || (! $reversed && ($owner->reversal_journal_entry_id !== null || $owner->reversed_at !== null))
            || ! hash_equals((string) $owner->execution_seal, $this->digest($owner->execution_snapshot ?? []))) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $this->assertManaged($run, historical: $reversed);
        $this->assertAudit($owner, 'posted', $this->postedAudit($owner));
        if ($owner->kind === 'allocation' && ! $reversed) {
            if (! hash_equals($this->digest($owner->source_snapshot['anchors']), $this->digest($this->anchors($run)))) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            foreach ($owner->posting_snapshot['quality'] as $kind => $snapshot) {
                $inspection = $this->quality($run, (int) $snapshot['id'], $kind === 'rejected' ? 'hold' : $kind, $owner->posting_snapshot['quantities'][$kind], (int) $snapshot['financial_period_id']);
                if (! hash_equals($this->digest($snapshot), $this->digest($this->qualitySnapshot($inspection)))) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }
            }
        }
        if ($owner->kind === 'recovery') {
            $this->assertProgressSnapshot($owner, $owner->execution_snapshot);
            if (! $reversed) {
                $parent = $owner->parentOwner;
                if ($parent === null || $parent->status !== 'posted' || ! hash_equals($parent->proposal_seal, $owner->source_snapshot['parent_seal'])) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }
                $inspection = $this->quality($run, (int) $owner->quality_inspection_id, 'release', (string) $owner->base_quantity, (int) $owner->posting_snapshot['quality']['financial_period_id']);
                if (! hash_equals($this->digest($owner->posting_snapshot['quality']), $this->digest($this->qualitySnapshot($inspection)))) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }
            }
        }
        if ($owner->kind === 'allocation' && ($owner->posting_snapshot['rounding_rule'] ?? null) === 'half_up_stage_output_loss_gl_4_v1') {
            if (bccomp((string) $owner->booked_loss_amount, bcround($owner->posting_snapshot['loss_cost'], 4), 4) !== 0
                || bccomp($owner->posting_snapshot['booked_loss_amount'], (string) $owner->booked_loss_amount, 4) !== 0
                || bccomp($owner->posting_snapshot['rounding_difference'], bcsub($owner->posting_snapshot['loss_cost'], (string) $owner->booked_loss_amount, 8), 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
        }
        if (bccomp((string) $owner->booked_loss_amount, '0', 4) > 0) {
            $journal = JournalEntry::query()->with('lines')->findOrFail($owner->journal_entry_id);
            app(InventoryAccountingPostingService::class)->assertPostedJournalHeader($journal, (int) $owner->company_id, (int) ($owner->posting_snapshot['posting_financial_period_id'] ?? $owner->financial_period_id),
                'production_stage_output_loss', (int) $owner->id, (int) $owner->branch_id, $owner->posting_date->toDateString(), (int) $owner->posting_snapshot['currency_id']);
            $actual = $journal->lines->sortBy('line_no')->values();
            if ($actual->count() !== 2 || (! $reversed && JournalEntry::query()->where('reversed_entry_id', $journal->id)->exists())) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            foreach ($this->journalLines($owner) as $index => $line) {
                foreach (['account_id', 'branch_id', 'cost_center_id', 'debit_amount', 'credit_amount'] as $field) {
                    if ((string) $actual[$index]->{$field} !== (string) $line[$field]) {
                        throw new DomainException(__('production_stage_transfer.invalid'));
                    }
                }
                foreach (['customer_id', 'supplier_id', 'employee_id', 'bank_account_id', 'department_id'] as $field) {
                    if ($actual[$index]->{$field} !== null) {
                        throw new DomainException(__('production_stage_transfer.invalid'));
                    }
                }
            }
        } elseif ($owner->journal_entry_id !== null || $owner->reversal_journal_entry_id !== null) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        if ($reversed) {
            if ($owner->reversed_by === null || $owner->reversed_at === null || blank($owner->reversal_reason)
                || ! hash_equals((string) $owner->reversal_execution_seal, $this->digest($owner->reversal_execution_snapshot ?? []))) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            if ($owner->journal_entry_id !== null) {
                $inverse = JournalEntry::query()->findOrFail($owner->reversal_journal_entry_id);
                app(InventoryAccountingPostingService::class)->assertPostedJournalHeader($inverse, (int) $owner->company_id, (int) ($owner->reversal_posting_financial_period_id ?? $owner->financial_period_id),
                    'production_stage_output_loss_reversal', (int) $owner->id, (int) $owner->branch_id, $owner->reversed_at->toDateString(), (int) $owner->posting_snapshot['currency_id']);
                app(JournalEntryService::class)->assertPostedReversal(JournalEntry::query()->findOrFail($owner->journal_entry_id), $inverse);
            }
            if ($owner->kind === 'recovery') {
                $this->assertProgressSnapshot($owner, $owner->reversal_execution_snapshot);
            }
            $this->assertAudit($owner, 'reversed', $this->reversedAudit($owner));
        }
    }

    /** @param array<string, mixed>|null $snapshot */
    private function assertProgressSnapshot(ProductionStageOutputCostOwner $owner, ?array $snapshot): void
    {
        $entry = $snapshot['progress'] ?? null;
        $actual = $entry === null ? null : ProductionProgressEntry::query()->find($entry['id']);
        if ($actual === null || (int) $actual->production_run_id !== (int) $owner->production_run_id || (int) $actual->stage_output_cost_owner_id !== (int) $owner->id
            || ! hash_equals($this->digest($entry), $this->digest($actual->getRawOriginal()))) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
    }

    private function activeAllocation(ProductionRun $run): ?ProductionStageOutputCostOwner
    {
        $owners = ProductionStageOutputCostOwner::query()->where('company_id', $run->company_id)->where('production_run_id', $run->id)
            ->where('kind', 'allocation')->where('status', 'posted')->limit(2)->get();
        if ($owners->count() > 1) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }

        return $owners->first();
    }

    private function assertUnabsorbed(ProductionRun $run): void
    {
        if (bccomp((string) $run->received_base_quantity, '0', 8) !== 0 || app(ProductionStageTransferService::class)->hasCommittedOutput($run)) {
            throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
        }
    }

    private function assertManaged(ProductionRun $run, bool $historical = false): void
    {
        if (! app(ProductionStageTransferService::class)->isManaged($run)
            || ! hash_equals($run->material_evidence_policy['physical_stage_fingerprint'] ?? '', app(ProductionStageTransferService::class)->routeFingerprint($run))) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        if (! $historical) {
            if (app(ProductionReceiptCancellationService::class)->isReceiptOnlyRecovery($run)) {
                app(ProductionCorrectionContextService::class)->executionPeriodId($run);
            } else {
                app(ProductionCorrectionContextService::class)->assertMeasuredExecution($run);
            }
        }
    }

    private function scoped(ProductionRun $run): ProductionRun
    {
        $context = app(OperatingContextService::class)->snapshot(request());
        $run = ProductionRun::query()->where('company_id', (int) $context['company_id'])->where('branch_id', (int) $context['branch_id'])->findOrFail($run->id);
        $company = app(OperatingCompanyContextService::class)->currentCompany();
        $scope = app(OperatingScopeAccessService::class);
        abort_unless($scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $run->branch_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $run->financial_period_id)->exists(), 404);
        app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, now()->toDateString());

        return $run;
    }

    private function lockRun(ProductionRun $run, bool $recovery = false): ProductionRun
    {
        $this->requireSchema();
        Company::query()->whereKey(app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->firstOrFail();
        $run = $this->scoped($run);
        $run = ProductionRun::query()->lockForUpdate()->findOrFail($run->id);
        $this->assertManaged($run);
        if (! $recovery) {
            app(ProductionReceiptCancellationService::class)->assertManufacturingAllowed($run);
        }
        app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, now()->toDateString());

        return $run;
    }

    private function scopedOwner(ProductionStageOutputCostOwner $owner, bool $lock = false): ProductionStageOutputCostOwner
    {
        $query = ProductionStageOutputCostOwner::query()->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId());
        $owner = ($lock ? $query->lockForUpdate() : $query)->findOrFail($owner->id);
        $this->scoped($owner->run);

        return $owner;
    }

    private function existing(ProductionRun $run, string $token): ?ProductionStageOutputCostOwner
    {
        if (! Str::isUuid($token)) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $owner = ProductionStageOutputCostOwner::query()->where('company_id', $run->company_id)->where('submission_token', $token)->lockForUpdate()->first();
        if ($owner !== null) {
            $this->assertSeal($owner);
            if ((int) $owner->production_run_id !== (int) $run->id) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
        }

        return $owner;
    }

    private function text(string $reason, string $evidence): void
    {
        if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 2000 || mb_strlen(trim($evidence)) < 5 || mb_strlen($evidence) > 2000) {
            throw new DomainException(__('production_daily_report.correction.evidence_required'));
        }
    }

    /** @return array<string, mixed> */
    private function proposal(ProductionStageOutputCostOwner $owner): array
    {
        return ['public_id' => $owner->public_id, 'company_id' => (int) $owner->company_id, 'branch_id' => (int) $owner->branch_id,
            'financial_period_id' => (int) $owner->financial_period_id, 'production_run_id' => (int) $owner->production_run_id, 'parent_owner_id' => $owner->parent_owner_id,
            'quality_inspection_id' => $owner->quality_inspection_id, 'kind' => $owner->kind, 'output_kind' => $owner->output_kind, 'submission_token' => $owner->submission_token,
            'posting_date' => $owner->posting_date->toDateString(), 'base_quantity' => (string) $owner->base_quantity, 'total_cost' => (string) $owner->total_cost,
            'booked_loss_amount' => (string) $owner->booked_loss_amount, 'source_snapshot' => $owner->source_snapshot, 'posting_snapshot' => $owner->posting_snapshot,
            'reason' => $owner->reason, 'evidence' => $owner->evidence, 'prepared_by' => (int) $owner->prepared_by];
    }

    private function assertSeal(ProductionStageOutputCostOwner $owner): void
    {
        if (! hash_equals($owner->proposal_seal, $this->digest($this->proposal($owner)))) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
    }

    /** @return array<string, mixed> */
    private function journalHeader(ProductionStageOutputCostOwner $owner): array
    {
        return ['entry_date' => $owner->posting_date->toDateString(), 'company_id' => (int) $owner->company_id, 'financial_period_id' => (int) ($owner->posting_snapshot['posting_financial_period_id'] ?? $owner->financial_period_id),
            'branch_id' => (int) $owner->branch_id, 'currency_id' => $owner->posting_snapshot['currency_id'], 'exchange_rate' => '1',
            'description' => $owner->reason, 'source_type' => 'production_stage_output_loss', 'source_id' => (int) $owner->id, 'source_doc_num' => $owner->public_id];
    }

    /** @return list<array<string, mixed>> */
    private function journalLines(ProductionStageOutputCostOwner $owner): array
    {
        $center = $owner->source_snapshot['anchors']['run_contract']['cost_center_id'];

        return [
            ['account_id' => $owner->posting_snapshot['loss_account_id'], 'branch_id' => (int) $owner->branch_id, 'cost_center_id' => $center,
                'debit_amount' => (string) $owner->booked_loss_amount, 'credit_amount' => '0.0000', 'description' => $owner->reason],
            ['account_id' => $owner->posting_snapshot['source_account_id'], 'branch_id' => (int) $owner->branch_id, 'cost_center_id' => $center,
                'debit_amount' => '0.0000', 'credit_amount' => (string) $owner->booked_loss_amount, 'description' => $owner->reason],
        ];
    }

    /** @return array<string, mixed> */
    private function postedAudit(ProductionStageOutputCostOwner $owner): array
    {
        return ['proposal_seal' => $owner->proposal_seal, 'journal_entry_id' => $owner->journal_entry_id, 'execution_seal' => $owner->execution_seal,
            'approved_by' => $owner->approved_by, 'approved_at' => $owner->approved_at->toISOString()];
    }

    /** @return array<string, mixed> */
    private function reversedAudit(ProductionStageOutputCostOwner $owner): array
    {
        return ['proposal_seal' => $owner->proposal_seal, 'inverse_id' => $owner->reversal_journal_entry_id, 'execution_seal' => $owner->reversal_execution_seal,
            'reversed_by' => $owner->reversed_by, 'reversed_at' => $owner->reversed_at->toISOString(), 'reason' => $owner->reversal_reason,
            ...($owner->reversal_posting_financial_period_id === null ? [] : ['posting_financial_period_id' => (int) $owner->reversal_posting_financial_period_id])];
    }

    /** @param array<string, mixed> $properties */
    private function audit(ProductionStageOutputCostOwner $owner, string $event, array $properties): void
    {
        app(ActivityLogger::class)->log(request(), 'production', 'production.stage_output_cost.'.$event, 'success', ['subject' => $owner,
            'causer' => auth()->user(), 'company_id' => $owner->company_id, 'branch_id' => $owner->branch_id, 'financial_period_id' => $owner->financial_period_id,
            'properties_only' => true, 'properties' => $properties]);
    }

    /** @param array<string, mixed> $properties */
    private function assertAudit(ProductionStageOutputCostOwner $owner, string $event, array $properties): void
    {
        $audit = DB::table('activity_log')->where('company_id', $owner->company_id)->where('subject_type', $owner::class)->where('subject_id', $owner->id)
            ->where('event', 'production.stage_output_cost.'.$event)->latest('id')->first();
        if ($audit === null || (int) $audit->causer_id !== (int) ($event === 'posted' ? $owner->approved_by : $owner->reversed_by)
            || json_decode($audit->properties, true, flags: JSON_THROW_ON_ERROR) !== $properties) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
    }

    /** @param array<mixed> $value */
    private function digest(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
