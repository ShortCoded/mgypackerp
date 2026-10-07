<?php

namespace Modules\Production\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
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
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionProgressEntry;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionStageTransfer;

final class ProductionStageTransferService
{
    public function isManaged(ProductionRun $run): bool
    {
        return $run->material_accounting_mode === ProductionOutputEvidenceService::Mode
            && ($run->material_evidence_policy['execution_structure'] ?? null) === ProductionOutputEvidenceService::StructurePhysicalRoute
            && ($run->material_evidence_policy['stage_transfer_version'] ?? null) === 1;
    }

    public function requireSchema(): void
    {
        if (! Schema::hasTable('production_stage_transfers')) {
            throw new DomainException(__('production_stage_transfer.migration_required'));
        }
    }

    public function requiresInput(ProductionRun $run): bool
    {
        return $this->isManaged($run) && ! $this->isFirst($run);
    }

    /** @return Collection<int, ProductionOrderStageSnapshot> */
    public function stages(ProductionRun $run): Collection
    {
        return app(ProductionCycleService::class)->stagesForLine($run->order, $run->orderLine)->values();
    }

    public function routeFingerprint(ProductionRun $run): string
    {
        return $this->digest($this->stages($run)->map(fn ($stage): array => $stage->only([
            'id', 'company_id', 'production_order_id', 'production_order_line_id', 'production_stage_id', 'sequence', 'stage_code', 'stage_name', 'is_required',
        ]))->all());
    }

    /** @return array<string, mixed> */
    public function preview(ProductionRun $source, ProductionRun $target): array
    {
        Gate::authorize('production.runs.view');
        $source = $this->scoped($source);
        $target = $this->scoped($target);
        $this->assertPair($source, $target);
        $snapshot = $this->snapshot($source, $target);

        return ['source' => $source, 'target' => $target, 'fingerprint' => $this->digest($snapshot),
            'available_quantity' => $snapshot['source_position']['available_good'],
            'quality_quantity' => app(ProductionQualityQuantityService::class)->availableQuantity($source),
            'source_wip' => $snapshot['source_position']['wip']];
    }

    public function prepare(ProductionRun $source, ProductionRun $target, string $quantity, string $reason, string $evidence, string $fingerprint, string $token): ProductionStageTransfer
    {
        Gate::authorize('production.runs.account_materials');
        $this->requireSchema();

        return DB::transaction(function () use ($source, $target, $quantity, $reason, $evidence, $fingerprint, $token): ProductionStageTransfer {
            $this->lockCompany();
            [$source, $target] = $this->lockPair($source, $target);
            $quantity = app(ProductionOutputEvidenceService::class)->quantity($quantity);
            $this->assertText($reason, $evidence);
            if (! Str::isUuid($token)) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            $existing = ProductionStageTransfer::query()->where('company_id', $source->company_id)->where('submission_token', $token)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertSeal($existing);
                if ((int) $existing->source_run_id !== (int) $source->id || (int) $existing->target_run_id !== (int) $target->id
                    || bccomp((string) $existing->base_quantity, $quantity, 8) !== 0 || $existing->reason !== trim($reason) || $existing->evidence !== trim($evidence)) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }

                return $existing;
            }
            $this->assertPair($source, $target);
            $snapshot = $this->snapshot($source, $target);
            if (! hash_equals($this->digest($snapshot), $fingerprint)) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $posting = $this->posting($source, $target, $quantity);
            $cost = app(ProductionCostService::class)->receiptCost($source, $quantity);
            if (bccomp($cost, '0', 8) <= 0 || bccomp($posting['booked_amount'], '0', 4) < 0) {
                throw new DomainException(__('production_stage_transfer.unvalued'));
            }
            if (bccomp($cost, $snapshot['source_position']['wip'], 8) > 0) {
                throw new DomainException(__('production_stage_transfer.unvalued'));
            }
            $transfer = new ProductionStageTransfer([
                'public_id' => (string) Str::uuid(), 'company_id' => $source->company_id, 'branch_id' => $source->branch_id,
                'financial_period_id' => $source->financial_period_id, 'source_run_id' => $source->id, 'target_run_id' => $target->id,
                'submission_token' => $token, 'posting_date' => now()->toDateString(), 'status' => 'prepared',
                'base_quantity' => $quantity, 'total_cost' => $cost, 'booked_amount' => $posting['booked_amount'],
                'source_snapshot' => $snapshot, 'posting_snapshot' => $posting, 'reason' => trim($reason), 'evidence' => trim($evidence),
                'prepared_by' => auth()->id(),
            ]);
            $transfer->proposal_seal = $this->digest($this->proposal($transfer));
            $transfer->save();
            $this->audit($transfer, 'prepared', ['proposal_seal' => $transfer->proposal_seal]);

            return $transfer;
        }, 3);
    }

    public function approve(ProductionStageTransfer $transfer): ProductionStageTransfer
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($transfer): ProductionStageTransfer {
            $this->lockCompany();
            $transfer = $this->scopedTransfer($transfer);
            [$source, $target] = $this->lockPair($transfer->sourceRun, $transfer->targetRun);
            $this->assertSeal($transfer);
            if ((int) $transfer->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            if ($transfer->status === 'posted') {
                $this->assertPosted($transfer);

                return $transfer;
            }
            if ($transfer->status !== 'prepared') {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            $this->assertPair($source, $target);
            if ($transfer->posting_date->toDateString() !== now()->toDateString()
                || ! hash_equals($this->digest($transfer->source_snapshot), $this->digest($this->snapshot($source, $target)))
                || $transfer->posting_snapshot !== $this->posting($source, $target, (string) $transfer->base_quantity)
                || bccomp((string) $transfer->total_cost, app(ProductionCostService::class)->receiptCost($source, (string) $transfer->base_quantity), 8) !== 0) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $journal = bccomp((string) $transfer->booked_amount, '0', 4) > 0
                ? app(JournalEntryService::class)->createPostedFromSource($this->journalHeader($transfer), $this->journalLines($transfer)) : null;
            $transfer->forceFill(['status' => 'posted', 'journal_entry_id' => $journal?->id, 'approved_by' => auth()->id(), 'approved_at' => now()->startOfSecond()])->save();
            foreach ($transfer->posting_snapshot['quality_allocations'] as $allocation) {
                DB::table('production_stage_quality_allocations')->insert([
                    'production_stage_transfer_id' => $transfer->id, 'production_quality_output_batch_id' => $allocation['batch_id'],
                    'base_quantity' => $allocation['base_quantity'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit($transfer, 'posted', ['proposal_seal' => $transfer->proposal_seal, 'journal_entry_id' => $journal?->id,
                'approved_by' => $transfer->approved_by, 'approved_at' => $transfer->approved_at->toISOString()]);
            $this->assertPosted($transfer);

            return $transfer->refresh();
        }, 3);
    }

    public function reject(ProductionStageTransfer $transfer, string $reason): ProductionStageTransfer
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($transfer, $reason): ProductionStageTransfer {
            $this->lockCompany();
            $transfer = $this->scopedTransfer($transfer);
            $this->lockPair($transfer->sourceRun, $transfer->targetRun);
            $this->assertSeal($transfer);
            $this->assertText($reason, $reason);
            if ($transfer->status === 'rejected') {
                return $transfer;
            }
            if ($transfer->status !== 'prepared' || $transfer->journal_entry_id !== null || $transfer->approved_at !== null
                || $transfer->approved_by !== null || $transfer->reversal_journal_entry_id !== null
                || DB::table('production_stage_quality_allocations')->where('production_stage_transfer_id', $transfer->id)->exists()
                || JournalEntry::query()->where('company_id', $transfer->company_id)->where('source_type', 'production_stage_transfer')->where('source_id', $transfer->id)->exists()) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            $transfer->forceFill(['status' => 'rejected'])->save();
            $this->audit($transfer, 'rejected', ['proposal_seal' => $transfer->proposal_seal, 'reason' => trim($reason)]);

            return $transfer->refresh();
        }, 3);
    }

    public function reverse(ProductionStageTransfer $transfer, string $reason): ProductionStageTransfer
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($transfer, $reason): ProductionStageTransfer {
            $this->lockCompany();
            $transfer = $this->scopedTransfer($transfer);
            [$source, $target] = $this->lockPair($transfer->sourceRun, $transfer->targetRun);
            $this->assertText($reason, $reason);
            if ((int) $transfer->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            if ($transfer->status === 'reversed') {
                $this->assertReversed($transfer);

                return $transfer;
            }
            $this->assertPosted($transfer);
            $this->open($source);
            $this->open($target);
            $reversalPeriod = app(ProductionCorrectionContextService::class)->ownerPostingPeriod($source, now()->toDateString());
            app(ProductionStageOutputCostService::class)->assertCostMutationAllowed($target);
            if (bccomp((string) $target->total_output_base_quantity, '0', 8) !== 0 || $this->hasCommittedOutput($target)
                || bccomp((string) $target->received_base_quantity, '0', 8) !== 0
                || bccomp(app(ProductionStageInputAdjustmentService::class)->consumption($transfer)['quantity'], '0', 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
            }
            $journal = $transfer->journal_entry_id === null ? null : JournalEntry::query()->findOrFail($transfer->journal_entry_id);
            $inverse = $journal === null ? null : app(JournalEntryService::class)->createPostedReversalFromSource($journal, [
                ...$this->journalHeader($transfer), 'entry_date' => now()->toDateString(), 'financial_period_id' => $reversalPeriod->id,
                'source_type' => 'production_stage_transfer_reversal', 'description' => trim($reason),
            ]);
            $transfer->forceFill(['status' => 'reversed', 'reversal_journal_entry_id' => $inverse?->id, 'reversal_posting_financial_period_id' => $reversalPeriod->id,
                'reversed_by' => auth()->id(), 'reversed_at' => now()->startOfSecond(), 'reversal_reason' => trim($reason)])->save();
            $this->audit($transfer, 'reversed', ['proposal_seal' => $transfer->proposal_seal, 'inverse_id' => $inverse?->id,
                'reversed_by' => $transfer->reversed_by, 'reversed_at' => $transfer->reversed_at->toISOString(), 'reason' => trim($reason), 'posting_financial_period_id' => $reversalPeriod->id]);
            $this->assertReversed($transfer);

            return $transfer->refresh();
        }, 3);
    }

    /** @return array{incoming: string, outgoing: string, incoming_quantity: string, outgoing_quantity: string, used_cost: string, used_quantity: string} */
    public function position(ProductionRun $run): array
    {
        $result = array_fill_keys(['incoming', 'outgoing', 'incoming_quantity', 'outgoing_quantity', 'used_cost', 'used_quantity'], '0.00000000');
        $components = app(ProductionStageCostComponentService::class);
        foreach (['incoming_components', 'outgoing_components', 'used_components'] as $field) {
            $result[$field] = $components->zero();
        }
        if (! $this->isManaged($run)) {
            return $result;
        }
        $this->assertPolicy($run);
        $owners = ProductionStageTransfer::query()->where('company_id', $run->company_id)
            ->where(fn ($query) => $query->where('source_run_id', $run->id)->orWhere('target_run_id', $run->id))
            ->whereIn('status', ['posted', 'reversed'])->orderBy('id')->get();
        foreach ($owners as $owner) {
            if ($owner->status === 'reversed') {
                $this->assertReversed($owner);
                $recovered = app(ProductionStageInputAdjustmentService::class)->consumption($owner);
                if ((int) $owner->target_run_id === (int) $run->id && (bccomp($recovered['quantity'], '0', 8) !== 0 || bccomp($recovered['cost'], '0', 8) !== 0)) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }

                continue;
            }
            $this->assertPosted($owner);
            $direction = (int) $owner->source_run_id === (int) $run->id ? 'outgoing' : 'incoming';
            $result[$direction] = bcadd($result[$direction], (string) $owner->total_cost, 8);
            $parts = $owner->posting_snapshot['cost_components'] ?? ['material' => (string) $owner->total_cost, 'other_direct' => '0.00000000', 'direct_labor' => '0.00000000', 'overhead' => '0.00000000'];
            $result[$direction.'_components'] = $components->add($result[$direction.'_components'], $parts);
            $result[$direction.'_quantity'] = bcadd($result[$direction.'_quantity'], (string) $owner->base_quantity, 8);
            if ($direction === 'incoming') {
                foreach (DB::table('production_stage_input_consumptions')->where('production_stage_transfer_id', $owner->id)->orderBy('id')->get() as $use) {
                    $this->assertConsumption($owner, $use);
                }
                $used = app(ProductionStageInputAdjustmentService::class)->consumption($owner);
                $result['used_cost'] = bcadd($result['used_cost'], $used['cost'], 8);
                $result['used_components'] = $components->add($result['used_components'], $used['components']);
                $result['used_quantity'] = bcadd($result['used_quantity'], $used['quantity'], 8);
            }
        }
        if (bccomp($result['used_quantity'], $result['incoming_quantity'], 8) > 0 || bccomp($result['used_cost'], $result['incoming'], 8) > 0) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }

        return $result;
    }

    /** @param array<string, string> $values */
    public function assertProgress(ProductionRun $run, array $values, mixed $inputQuantity): void
    {
        if (! $this->isManaged($run)) {
            return;
        }
        $this->assertPolicy($run);
        app(ProductionStageOutputCostService::class)->requireSchema();
        app(ProductionStageOutputCostService::class)->assertCostMutationAllowed($run);
        $losses = collect(['rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity'])->contains(fn (string $field): bool => bccomp($values[$field], '0', 8) !== 0);
        if ($losses && ($this->hasCommittedOutput($run) || bccomp((string) $run->received_base_quantity, '0', 8) !== 0)) {
            throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
        }
        $outputQuantity = array_reduce($values, fn (string $sum, string $value): string => bcadd($sum, $value, 8), '0.00000000');
        if ($this->isFirst($run)) {
            if ($inputQuantity !== null && bccomp(app(ProductionOutputEvidenceService::class)->quantity($inputQuantity), '0', 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.input_required'));
            }

            return;
        }
        $quantity = app(ProductionOutputEvidenceService::class)->quantity($inputQuantity ?? '0');
        $position = $this->position($run);
        if (bccomp($quantity, $outputQuantity, 8) !== 0
            || bccomp($quantity, bcsub($position['incoming_quantity'], $position['used_quantity'], 8), 8) > 0) {
            throw new DomainException(__('production_stage_transfer.input_required'));
        }
    }

    public function consumeInput(ProductionRun $run, ProductionProgressEntry $entry): void
    {
        if (! $this->isManaged($run) || $this->isFirst($run)) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new DomainException(__('production_execution.evidence.transaction_required'));
        }
        app(ProductionStageOutputCostService::class)->requireSchema();
        $components = app(ProductionStageCostComponentService::class);
        $outputQuantities = [];
        foreach (ProductionStageOutputCostService::OutputFields as $kind => $field) {
            $outputQuantities[$kind] = bcadd((string) $entry->{$field}, '0', 8);
        }
        $remaining = array_reduce($outputQuantities, fn (string $sum, string $quantity): string => bcadd($sum, $quantity, 8), '0.00000000');
        foreach (ProductionStageTransfer::query()->where('company_id', $run->company_id)->where('target_run_id', $run->id)
            ->where('status', 'posted')->orderBy('id')->lockForUpdate()->get() as $owner) {
            $this->assertPosted($owner);
            $effective = app(ProductionStageInputAdjustmentService::class)->consumption($owner);
            $usedQuantity = $effective['quantity'];
            $available = bcsub((string) $owner->base_quantity, $usedQuantity, 8);
            if (bccomp($available, '0', 8) <= 0 || bccomp($remaining, '0', 8) <= 0) {
                continue;
            }
            $quantity = bccomp($remaining, $available, 8) <= 0 ? $remaining : $available;
            $usedCost = $effective['cost'];
            $cost = bccomp($quantity, $available, 8) === 0 ? bcsub((string) $owner->total_cost, $usedCost, 8)
                : bcdiv(bcmul((string) $owner->total_cost, $quantity, 16), (string) $owner->base_quantity, 8);
            $parts = $owner->posting_snapshot['cost_components'] ?? ['material' => (string) $owner->total_cost, 'other_direct' => '0.00000000', 'direct_labor' => '0.00000000', 'overhead' => '0.00000000'];
            $usedParts = $effective['components'];
            $parts = bccomp($quantity, $available, 8) === 0 ? $components->subtract($parts, $usedParts)
                : $components->share($parts, $quantity, (string) $owner->base_quantity, $cost);
            $proof = ['production_stage_transfer_id' => (int) $owner->id, 'production_progress_entry_id' => (int) $entry->id,
                'base_quantity' => $quantity, 'total_cost' => $cost, 'cost_components' => $parts, 'output_quantities' => $outputQuantities];
            DB::table('production_stage_input_consumptions')->insert([...$proof, 'cost_components' => json_encode($parts, JSON_THROW_ON_ERROR),
                'output_quantities' => json_encode($outputQuantities, JSON_THROW_ON_ERROR), 'evidence_seal' => $this->digest($proof), 'created_at' => now(), 'updated_at' => now()]);
            $remaining = bcsub($remaining, $quantity, 8);
        }
        if (bccomp($remaining, '0', 8) !== 0) {
            throw new DomainException(__('production_stage_transfer.input_required'));
        }
    }

    public function assertStart(ProductionRun $run): void
    {
        $this->assertPolicy($run);
        if (! $this->isFirst($run) && bccomp($this->position($run)['incoming_quantity'], '0', 8) <= 0) {
            throw new DomainException(__('production_stage_transfer.input_required'));
        }
    }

    public function assertFinalReceipt(ProductionRun $run): void
    {
        $this->assertPolicy($run);
        app(ProductionStageOutputCostService::class)->assertReadyForOutput($run);
        $stages = $this->stages($run);
        $output = bcadd(bcadd(bcadd((string) $run->good_base_quantity, (string) $run->rejected_base_quantity, 8), (string) $run->rework_base_quantity, 8), (string) $run->scrap_base_quantity, 8);
        if ((int) $stages->last()?->id !== (int) $run->production_order_stage_snapshot_id
            || (! $this->isFirst($run) && bccomp($this->position($run)['used_quantity'], $output, 8) !== 0)) {
            throw new DomainException(__('production_stage_transfer.input_required'));
        }
        if ($this->sourceAccount($run) !== (int) app(PostingAccountResolver::class)->resolve((int) $run->company_id,
            PostingAccountResolver::WorkInProcessInventory, 'production_stage_transfer')->id) {
            throw new DomainException(__('production_stage_transfer.mixed_accounts'));
        }
        foreach (ProductionRun::withTrashed()->where('company_id', $run->company_id)->where('production_order_line_id', $run->production_order_line_id)
            ->whereIn('production_order_stage_snapshot_id', $stages->slice(0, -1)->pluck('id'))->get() as $prior) {
            if ($prior->trashed() || (! $this->isManaged($prior) && (bccomp((string) $prior->total_output_base_quantity, '0', 8) !== 0
                || bccomp(app(ProductionCostService::class)->runPosition($prior)['wip'], '0', 8) !== 0))) {
                throw new DomainException(__('production_daily_report.correction.stage_cost_transfer_required'));
            }
        }
    }

    public function hasCommittedOutput(ProductionRun $run): bool
    {
        return $this->isManaged($run) && bccomp($this->position($run)['outgoing_quantity'], '0', 8) > 0;
    }

    public function assertCostMutationAllowed(ProductionRun $run, bool $conversionOnly = false): void
    {
        if ($this->isManaged($run)) {
            app(ProductionStageOutputCostService::class)->assertCostMutationAllowed($run);
        }
        if ($this->isManaged($run) && (bccomp($this->position($run)['outgoing_quantity'], '0', 8) > 0
            || bccomp((string) $run->received_base_quantity, '0', 8) > 0
            || (! $conversionOnly && bccomp($this->position($run)['used_quantity'], '0', 8) > 0))) {
            throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
        }
    }

    public function assertRunRecovery(ProductionRun $run, bool $inputDocumentCorrection = false): void
    {
        if ($this->isManaged($run)) {
            app(ProductionStageOutputCostService::class)->assertCostMutationAllowed($run);
            $position = $this->position($run);
            if (bccomp($position['outgoing_quantity'], '0', 8) > 0 || bccomp((string) $run->received_base_quantity, '0', 8) > 0
                || (! $inputDocumentCorrection && (bccomp($position['incoming_quantity'], '0', 8) > 0 || bccomp($position['used_quantity'], '0', 8) > 0))) {
                throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
            }
        }
    }

    public function qualityQuantity(int $batchId): string
    {
        $run = ProductionRun::query()->find(DB::table('production_quality_output_batches')->where('id', $batchId)->value('production_run_id'));
        if ($run === null || ! $this->isManaged($run)) {
            return '0.00000000';
        }
        $quantity = '0.00000000';
        foreach (ProductionStageTransfer::query()->where('company_id', $run->company_id)->where('source_run_id', $run->id)
            ->whereIn('status', ['posted', 'reversed'])->whereIn('id', DB::table('production_stage_quality_allocations')->where('production_quality_output_batch_id', $batchId)->select('production_stage_transfer_id'))->get() as $owner) {
            if ($owner->status === 'reversed') {
                $this->assertReversed($owner);

                continue;
            }
            $this->assertPosted($owner);
            $quantity = bcadd($quantity, (string) DB::table('production_stage_quality_allocations')->where('production_stage_transfer_id', $owner->id)
                ->where('production_quality_output_batch_id', $batchId)->sum('base_quantity'), 8);
        }

        return $quantity;
    }

    public function assertBookPrecision(string $cost): void
    {
        if (bccomp($cost, bcadd($cost, '0', 4), 8) !== 0) {
            throw new DomainException(__('production_stage_transfer.precision_requires_owner'));
        }
    }

    private function assertPolicy(ProductionRun $run): void
    {
        $this->requireSchema();
        if (! $this->isManaged($run) || ! hash_equals($run->material_evidence_policy['physical_stage_fingerprint'] ?? '', $this->routeFingerprint($run))) {
            throw new DomainException(__('production_execution.evidence.policy_changed'));
        }
    }

    private function isFirst(ProductionRun $run): bool
    {
        return (int) $this->stages($run)->first()?->id === (int) $run->production_order_stage_snapshot_id;
    }

    private function assertPair(ProductionRun $source, ProductionRun $target): void
    {
        $this->assertPolicy($source);
        $this->assertPolicy($target);
        $this->open($source);
        $this->open($target);
        app(ProductionReceiptCancellationService::class)->assertManufacturingAllowed($source);
        app(ProductionReceiptCancellationService::class)->assertManufacturingAllowed($target);
        app(ProductionCorrectionContextService::class)->assertMeasuredExecution($source);
        app(ProductionCorrectionContextService::class)->assertMeasuredExecution($target);
        if (app(ProductionCorrectionContextService::class)->executionPeriodId($source) !== app(ProductionCorrectionContextService::class)->executionPeriodId($target)) {
            throw new DomainException(__('production_run_correction.target_changed'));
        }
        $stages = $this->stages($source);
        $index = $stages->search(fn ($stage): bool => (int) $stage->id === (int) $source->production_order_stage_snapshot_id);
        foreach (['company_id', 'branch_id', 'financial_period_id', 'production_order_id', 'production_order_line_id', 'product_id', 'unit_id', 'conversion_factor'] as $field) {
            if ((string) $source->{$field} !== (string) $target->{$field}) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
        }
        if ($source->id === $target->id || $index === false || (int) $stages->get($index + 1)?->id !== (int) $target->production_order_stage_snapshot_id
            || ! in_array($source->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld, ProductionRun::StatusCompleted], true)
            || in_array($target->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)
            || app(ProductionShiftEvidenceService::class)->pendingDailyReports($source)) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ProductionRun $source, ProductionRun $target): array
    {
        $cost = app(ProductionCostService::class)->runPosition($source);
        $stage = $this->position($source);
        $cost['available_good'] = bcsub(bcsub((string) $source->good_base_quantity, (string) $source->received_base_quantity, 8), $stage['outgoing_quantity'], 8);
        $rows = fn (string $table, int $runId): array => DB::table($table)->where('production_run_id', $runId)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $documents = InventoryDocument::withTrashed()->where('company_id', $source->company_id)->whereIn('id', DB::table('inventory_document_lines')
            ->whereIn('production_run_id', [$source->id, $target->id])->select('inventory_document_id'))->orderBy('id')->get();
        $owners = ProductionStageTransfer::query()->where('company_id', $source->company_id)->whereIn('status', ['posted', 'reversed'])
            ->where(fn ($query) => $query->whereIn('source_run_id', [$source->id, $target->id])->orWhereIn('target_run_id', [$source->id, $target->id]))->orderBy('id')->get();

        return ['source' => $source->getRawOriginal(), 'target' => $target->getRawOriginal(), 'source_position' => $cost,
            'quality' => $rows('quality_inspections', $source->id), 'batches' => $rows('production_quality_output_batches', $source->id),
            'progress' => $rows('production_progress_entries', $source->id),
            'requirements' => [...$rows('production_material_requirements', $source->id), ...$rows('production_material_requirements', $target->id)],
            'documents' => $documents->map(fn ($doc): array => ['header' => $doc->getRawOriginal(),
                'lines' => $doc->lines()->orderBy('id')->get()->map->getRawOriginal()->all(),
                'journal' => $doc->journalEntry?->getRawOriginal(), 'journal_lines' => $doc->journalEntry?->lines()->orderBy('id')->get()->map->getRawOriginal()->all()])->all(),
            'stage_owners' => $owners->map->getRawOriginal()->all(), 'route' => $this->routeFingerprint($source)];
    }

    /** @return array<string, mixed> */
    private function posting(ProductionRun $source, ProductionRun $target, string $quantity): array
    {
        $cost = app(ProductionCostService::class)->runPosition($source);
        foreach (['material_valuation_complete', 'expense_valuation_complete', 'labor_valuation_complete'] as $field) {
            if (! $cost[$field]) {
                throw new DomainException(__('production_stage_transfer.unvalued'));
            }
        }
        app(ProductionStageOutputCostService::class)->requireSchema();
        app(ProductionStageOutputCostService::class)->assertReadyForOutput($source);
        $nativeSources = app(ProductionStageCostComponentService::class)->nativeSources($source);
        foreach (['other_direct_cost' => 'other_direct', 'direct_labor_cost' => 'direct_labor', 'allocated_overhead' => 'overhead'] as $field => $component) {
            if (bccomp($cost[$field], $nativeSources['components'][$component], 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
            }
        }
        $output = bcadd(bcadd(bcadd((string) $source->good_base_quantity, (string) $source->rejected_base_quantity, 8), (string) $source->rework_base_quantity, 8), (string) $source->scrap_base_quantity, 8);
        if ($source->expenseRequests()->whereNotIn('status', ['paid', 'rejected', 'reversed'])->exists()) {
            throw new DomainException(__('production_stage_transfer.nonmaterial_requires_owner'));
        }
        $position = $this->position($source);
        $available = bcsub(bcsub((string) $source->good_base_quantity, (string) $source->received_base_quantity, 8), $position['outgoing_quantity'], 8);
        $targetPosition = $this->position($target);
        if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $available, 8) > 0
            || bccomp(bcadd($targetPosition['incoming_quantity'], $quantity, 8), (string) $target->planned_base_quantity, 8) > 0
            || (! $this->isFirst($source) && bccomp($position['used_quantity'], $output, 8) !== 0)) {
            throw new DomainException(__('production_stage_transfer.quantity_exceeded'));
        }
        $quality = [];
        $remaining = $quantity;
        foreach (app(ProductionQualityQuantityService::class)->availableBatches($source) as $batch) {
            if (bccomp($remaining, '0', 8) <= 0) {
                break;
            }
            $slice = bccomp($remaining, $batch->available_quantity, 8) <= 0 ? $remaining : $batch->available_quantity;
            $quality[] = ['batch_id' => (int) $batch->id, 'base_quantity' => $slice];
            $remaining = bcsub($remaining, $slice, 8);
        }
        if (bccomp($remaining, '0', 8) !== 0) {
            throw new DomainException(__('production_execution.evidence.quality_receipt_exceeded'));
        }
        $sourceAccount = $this->sourceAccount($source);
        $targetAccount = app(PostingAccountResolver::class)->resolve((int) $target->company_id, PostingAccountResolver::WorkInProcessInventory, 'production_stage_transfer');
        if ($target->cost_center_id !== null) {
            abort_unless(DB::table('cost_centers')->where('company_id', $target->company_id)->where('id', $target->cost_center_id)->whereNull('deleted_at')->exists(), 422);
        }

        $parts = app(ProductionStageCostComponentService::class)->transferComponents($source, $quantity);
        $exactCost = app(ProductionStageCostComponentService::class)->sum($parts);
        $priorBooked = bcadd((string) ProductionStageTransfer::query()->where('company_id', $source->company_id)
            ->where('source_run_id', $source->id)->where('status', 'posted')->sum('booked_amount'), '0', 4);
        $cumulative = bcadd($position['outgoing'], $exactCost, 8);
        $booked = bcsub(bcround($cumulative, 4), $priorBooked, 4);
        if (bccomp($booked, '0', 4) < 0) {
            throw new DomainException(__('production_stage_transfer.precision_requires_owner'));
        }
        $loss = app(ProductionStageOutputCostService::class)->position($source);

        return ['version' => 2, 'posting_financial_period_id' => app(ProductionCorrectionContextService::class)->executionPeriodId($source), 'cost_components' => $parts, 'native_cost_sources' => $nativeSources,
            'output_cost_owner_id' => $loss['owner_id'], 'output_cost_owner_seal' => $loss['owner_seal'], 'currency_id' => (int) Currency::query()->where('company_id', $source->company_id)->where('is_main', true)->where('status', 'active')->sole()->id,
            'source_account_id' => $sourceAccount, 'target_account_id' => (int) $targetAccount->id,
            'source_cost_center_id' => $source->cost_center_id, 'target_cost_center_id' => $target->cost_center_id,
            'quality_allocations' => $quality, 'valuation_rule' => 'measured_inputs_and_native_cost_components_v2',
            'book_rule' => 'cumulative_half_up_stage_transfer_gl_4_v2', 'booked_amount' => $booked,
            'rounding_difference' => bcsub($exactCost, $booked, 8), 'rounding_source' => ['previous_exact_cost' => $position['outgoing'],
                'previous_booked_amount' => $priorBooked, 'cumulative_exact_cost' => $cumulative, 'cumulative_booked_amount' => bcround($cumulative, 4)]];
    }

    public function historicalSourceAccount(ProductionRun $run): int
    {
        return $this->sourceAccount($run);
    }

    private function sourceAccount(ProductionRun $run): int
    {
        $accounts = [];
        $documents = InventoryDocument::query()->where('company_id', $run->company_id)->where('status', InventoryDocument::StatusPosted)
            ->whereIn('document_type', [InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue, InventoryDocument::TypeMaterialReturn, InventoryDocument::TypeProductionWaste])
            ->whereIn('id', DB::table('inventory_document_lines')->where('production_run_id', $run->id)->select('inventory_document_id'))->orderBy('id')->get();
        foreach ($documents as $document) {
            app(InventoryAccountingPostingService::class)->assertManualCorrectionAccounting($document);
            foreach ($document->lines()->where('production_run_id', $run->id)->get() as $line) {
                $snapshot = $line->product_snapshot['inventory_accounting'] ?? null;
                $side = in_array($document->document_type, [InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue], true) ? 'debit' : 'credit';
                if (! is_array($snapshot) || ($snapshot['cost_center_id'] ?? null) !== $run->cost_center_id
                    || (int) ($snapshot[$side.'_branch_id'] ?? 0) !== (int) $run->branch_id) {
                    throw new DomainException(__('production_stage_transfer.unvalued'));
                }
                $accounts[] = (int) $snapshot[$side.'_account_id'];
            }
        }
        foreach (ProductionStageTransfer::query()->where('company_id', $run->company_id)->where('target_run_id', $run->id)->where('status', 'posted')->get() as $owner) {
            $this->assertPosted($owner);
            if ($owner->posting_snapshot['target_cost_center_id'] !== $run->cost_center_id) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            $accounts[] = $owner->posting_snapshot['target_account_id'];
        }
        array_push($accounts, ...app(ProductionStageCostComponentService::class)->nativeSources($run)['accounts']);
        $accounts = array_values(array_unique($accounts));
        if (count($accounts) !== 1 || ! Account::query()->forCompany((int) $run->company_id)->eligibleForDirectPosting()->whereKey($accounts[0])
            ->whereHas('classification', fn ($query) => $query->where('company_id', $run->company_id)->where('code', PostingAccountResolver::WorkInProcessInventory))->exists()) {
            throw new DomainException(__('production_stage_transfer.mixed_accounts'));
        }

        return $accounts[0];
    }

    private function assertPosted(ProductionStageTransfer $owner, bool $reversal = false): void
    {
        $this->assertSeal($owner);
        if (($reversal ? $owner->status !== 'reversed' : $owner->status !== 'posted')
            || $owner->approved_by === null || $owner->approved_at === null || (int) $owner->prepared_by === (int) $owner->approved_by
            || (! $reversal && ($owner->reversal_journal_entry_id !== null || $owner->reversed_at !== null))) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $source = ProductionRun::withTrashed()->findOrFail($owner->source_run_id);
        $target = ProductionRun::withTrashed()->findOrFail($owner->target_run_id);
        foreach (['source' => $source, 'target' => $target] as $side => $run) {
            if ($run->trashed() || (int) $run->company_id !== (int) $owner->company_id || (int) $run->branch_id !== (int) $owner->branch_id
                || (int) $run->financial_period_id !== (int) $owner->financial_period_id) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            foreach (['production_order_id', 'production_order_line_id', 'production_order_stage_snapshot_id', 'product_id', 'unit_id', 'conversion_factor', 'cost_center_id'] as $field) {
                $unchanged = $field === 'conversion_factor'
                    ? bccomp((string) $run->{$field}, (string) ($owner->source_snapshot[$side][$field] ?? '0'), 8) === 0
                    : (string) $run->{$field} === (string) ($owner->source_snapshot[$side][$field] ?? null);
                if (! $unchanged) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }
            }
            $this->assertPolicy($run);
        }
        if (($owner->posting_snapshot['version'] ?? 1) === 2) {
            $components = app(ProductionStageCostComponentService::class);
            if (bccomp($components->sum($owner->posting_snapshot['cost_components']), (string) $owner->total_cost, 8) !== 0
                || (! $reversal && ! hash_equals($this->digest($owner->posting_snapshot['native_cost_sources']), $this->digest($components->nativeSources($source))))) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            if (! $reversal && $owner->posting_snapshot['output_cost_owner_id'] !== null) {
                app(ProductionStageOutputCostService::class)->assertAllocationAnchor((int) $owner->posting_snapshot['output_cost_owner_id'], $owner->posting_snapshot['output_cost_owner_seal'], $source);
            }
        }
        if (($owner->posting_snapshot['book_rule'] ?? 'exact_gl_4_v1') === 'cumulative_half_up_stage_transfer_gl_4_v2') {
            $rounding = $owner->posting_snapshot['rounding_source'];
            if (bccomp($rounding['cumulative_exact_cost'], bcadd($rounding['previous_exact_cost'], (string) $owner->total_cost, 8), 8) !== 0
                || bccomp($rounding['cumulative_booked_amount'], bcround($rounding['cumulative_exact_cost'], 4), 4) !== 0
                || bccomp((string) $owner->booked_amount, bcsub($rounding['cumulative_booked_amount'], $rounding['previous_booked_amount'], 4), 4) !== 0
                || bccomp($owner->posting_snapshot['booked_amount'], (string) $owner->booked_amount, 4) !== 0
                || bccomp($owner->posting_snapshot['rounding_difference'], bcsub((string) $owner->total_cost, (string) $owner->booked_amount, 8), 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
        } else {
            $this->assertBookPrecision((string) $owner->total_cost);
            if (bccomp((string) $owner->total_cost, (string) $owner->booked_amount, 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
        }
        if ($owner->journal_entry_id === null) {
            if (($owner->posting_snapshot['book_rule'] ?? null) !== 'cumulative_half_up_stage_transfer_gl_4_v2'
                || bccomp((string) $owner->booked_amount, '0', 4) !== 0 || bccomp((string) $owner->total_cost, '0', 8) <= 0 || $owner->reversal_journal_entry_id !== null) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
            $this->assertPostedManifest($owner);

            return;
        }
        if (bccomp((string) $owner->booked_amount, '0', 4) <= 0) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $journal = JournalEntry::query()->with('lines')->findOrFail($owner->journal_entry_id);
        app(InventoryAccountingPostingService::class)->assertPostedJournalHeader($journal, (int) $owner->company_id, (int) ($owner->posting_snapshot['posting_financial_period_id'] ?? $owner->financial_period_id),
            'production_stage_transfer', (int) $owner->id, (int) $owner->branch_id, $owner->posting_date->toDateString(), $owner->posting_snapshot['currency_id']);
        if (! $reversal && (JournalEntry::query()->where('reversed_entry_id', $journal->id)->exists() || $journal->reversed_entry_id !== null)) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $expected = $this->journalLines($owner);
        $actual = $journal->lines->sortBy('line_no')->values();
        if ($actual->count() !== 2) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        foreach ($expected as $i => $line) {
            foreach (['account_id', 'branch_id', 'cost_center_id', 'debit_amount', 'credit_amount'] as $field) {
                if ((string) $actual[$i]->{$field} !== (string) $line[$field]) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }
            }
            foreach (['customer_id', 'supplier_id', 'employee_id', 'bank_account_id', 'department_id'] as $field) {
                if ($actual[$i]->{$field} !== null) {
                    throw new DomainException(__('production_stage_transfer.invalid'));
                }
            }
        }
        $this->assertPostedManifest($owner);
    }

    private function assertPostedManifest(ProductionStageTransfer $owner): void
    {
        $allocations = DB::table('production_stage_quality_allocations')->where('production_stage_transfer_id', $owner->id)->orderBy('id')->get()
            ->map(fn ($row): array => ['batch_id' => (int) $row->production_quality_output_batch_id, 'base_quantity' => bcadd((string) $row->base_quantity, '0', 8)])->all();
        if ($allocations !== $owner->posting_snapshot['quality_allocations']) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        $this->assertAudit($owner, 'posted', ['proposal_seal' => $owner->proposal_seal, 'journal_entry_id' => $owner->journal_entry_id,
            'approved_by' => $owner->approved_by, 'approved_at' => $owner->approved_at->toISOString()]);
    }

    private function assertReversed(ProductionStageTransfer $owner): void
    {
        $this->assertPosted($owner, true);
        if (($owner->journal_entry_id !== null && $owner->reversal_journal_entry_id === null) || $owner->reversed_at === null || $owner->reversed_by === null || blank($owner->reversal_reason)) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
        if ($owner->journal_entry_id !== null) {
            $journal = JournalEntry::query()->findOrFail($owner->journal_entry_id);
            $inverse = JournalEntry::query()->findOrFail($owner->reversal_journal_entry_id);
            app(InventoryAccountingPostingService::class)->assertPostedJournalHeader($inverse, (int) $owner->company_id, (int) ($owner->reversal_posting_financial_period_id ?? $owner->financial_period_id),
                'production_stage_transfer_reversal', (int) $owner->id, (int) $owner->branch_id, $owner->reversed_at->toDateString(), $owner->posting_snapshot['currency_id']);
            app(JournalEntryService::class)->assertPostedReversal($journal, $inverse);
        }
        $this->assertAudit($owner, 'reversed', ['proposal_seal' => $owner->proposal_seal, 'inverse_id' => $owner->reversal_journal_entry_id,
            'reversed_by' => $owner->reversed_by, 'reversed_at' => $owner->reversed_at->toISOString(), 'reason' => $owner->reversal_reason,
            ...($owner->reversal_posting_financial_period_id === null ? [] : ['posting_financial_period_id' => (int) $owner->reversal_posting_financial_period_id])]);
    }

    private function assertConsumption(ProductionStageTransfer $owner, object $use): void
    {
        $proof = ['production_stage_transfer_id' => (int) $use->production_stage_transfer_id, 'production_progress_entry_id' => (int) $use->production_progress_entry_id,
            'base_quantity' => bcadd((string) $use->base_quantity, '0', 8), 'total_cost' => bcadd((string) $use->total_cost, '0', 8)];
        $entry = ProductionProgressEntry::query()->findOrFail($use->production_progress_entry_id);
        $output = (string) $entry->good_base_quantity;
        if (($use->cost_components ?? null) !== null) {
            $proof['cost_components'] = json_decode($use->cost_components, true, flags: JSON_THROW_ON_ERROR);
            $proof['output_quantities'] = json_decode($use->output_quantities, true, flags: JSON_THROW_ON_ERROR);
            $actual = [];
            foreach (ProductionStageOutputCostService::OutputFields as $kind => $field) {
                $actual[$kind] = bcadd((string) $entry->{$field}, '0', 8);
            }
            $output = array_reduce($actual, fn (string $sum, string $quantity): string => bcadd($sum, $quantity, 8), '0.00000000');
            if ($proof['output_quantities'] !== $actual || bccomp(app(ProductionStageCostComponentService::class)->sum($proof['cost_components']), $proof['total_cost'], 8) !== 0
                || bccomp((string) DB::table('production_stage_input_consumptions')->where('production_progress_entry_id', $entry->id)->sum('base_quantity'), $output, 8) !== 0) {
                throw new DomainException(__('production_stage_transfer.invalid'));
            }
        }
        if (! hash_equals($use->evidence_seal, $this->digest($proof)) || (int) $entry->production_run_id !== (int) $owner->target_run_id
            || bccomp($proof['base_quantity'], '0', 8) <= 0 || bccomp($proof['total_cost'], '0', 8) < 0
            || bccomp($proof['base_quantity'], $output, 8) > 0 || $entry->production_run_correction_id !== null) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
    }

    private function assertSeal(ProductionStageTransfer $owner): void
    {
        if (! hash_equals($owner->proposal_seal, $this->digest($this->proposal($owner)))) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
    }

    /** @return array<string, mixed> */
    private function proposal(ProductionStageTransfer $owner): array
    {
        return ['public_id' => $owner->public_id, 'company_id' => (int) $owner->company_id, 'branch_id' => (int) $owner->branch_id,
            'financial_period_id' => (int) $owner->financial_period_id, 'source_run_id' => (int) $owner->source_run_id,
            'target_run_id' => (int) $owner->target_run_id, 'submission_token' => $owner->submission_token,
            'posting_date' => $owner->posting_date->toDateString(), 'base_quantity' => (string) $owner->base_quantity,
            'total_cost' => (string) $owner->total_cost, 'booked_amount' => (string) $owner->booked_amount,
            'source_snapshot' => $owner->source_snapshot, 'posting_snapshot' => $owner->posting_snapshot,
            'reason' => $owner->reason, 'evidence' => $owner->evidence, 'prepared_by' => (int) $owner->prepared_by];
    }

    /** @return array<string, mixed> */
    private function journalHeader(ProductionStageTransfer $owner): array
    {
        return ['entry_date' => $owner->posting_date->toDateString(), 'company_id' => (int) $owner->company_id,
            'financial_period_id' => (int) ($owner->posting_snapshot['posting_financial_period_id'] ?? $owner->financial_period_id), 'branch_id' => (int) $owner->branch_id,
            'currency_id' => $owner->posting_snapshot['currency_id'], 'exchange_rate' => '1', 'description' => __('production_stage_transfer.title'),
            'source_type' => 'production_stage_transfer', 'source_id' => (int) $owner->id, 'source_doc_num' => $owner->public_id];
    }

    /** @return list<array<string, mixed>> */
    private function journalLines(ProductionStageTransfer $owner): array
    {
        $snapshot = $owner->posting_snapshot;

        return [
            ['account_id' => $snapshot['target_account_id'], 'branch_id' => (int) $owner->branch_id, 'cost_center_id' => $snapshot['target_cost_center_id'],
                'debit_amount' => (string) $owner->booked_amount, 'credit_amount' => '0.0000', 'description' => __('production_stage_transfer.title')],
            ['account_id' => $snapshot['source_account_id'], 'branch_id' => (int) $owner->branch_id, 'cost_center_id' => $snapshot['source_cost_center_id'],
                'debit_amount' => '0.0000', 'credit_amount' => (string) $owner->booked_amount, 'description' => __('production_stage_transfer.title')],
        ];
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

    /** @return array{ProductionRun, ProductionRun} */
    private function lockPair(ProductionRun $source, ProductionRun $target): array
    {
        $source = $this->scoped($source);
        $target = $this->scoped($target);
        $runs = ProductionRun::query()->whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$runs[$source->id], $runs[$target->id]];
    }

    private function scopedTransfer(ProductionStageTransfer $owner): ProductionStageTransfer
    {
        $this->requireSchema();
        $owner = ProductionStageTransfer::query()->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->findOrFail($owner->id);
        $this->scoped($owner->sourceRun);
        $this->scoped($owner->targetRun);

        return $owner;
    }

    private function lockCompany(): void
    {
        Company::query()->whereKey(app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->firstOrFail();
    }

    private function open(ProductionRun $run): void
    {
        app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, now()->toDateString());
    }

    private function assertText(string $reason, string $evidence): void
    {
        if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 2000 || mb_strlen(trim($evidence)) < 5 || mb_strlen($evidence) > 2000) {
            throw new DomainException(__('production_daily_report.correction.evidence_required'));
        }
    }

    /** @param array<string, mixed> $properties */
    private function audit(ProductionStageTransfer $owner, string $event, array $properties): void
    {
        app(ActivityLogger::class)->log(request(), 'production', 'production.stage_transfer.'.$event, 'success', ['subject' => $owner,
            'causer' => auth()->user(),
            'company_id' => $owner->company_id, 'branch_id' => $owner->branch_id, 'financial_period_id' => $owner->financial_period_id,
            'properties_only' => true, 'properties' => $properties]);
    }

    /** @param array<string, mixed> $properties */
    private function assertAudit(ProductionStageTransfer $owner, string $event, array $properties): void
    {
        $audit = DB::table('activity_log')->where('company_id', $owner->company_id)->where('subject_type', $owner::class)->where('subject_id', $owner->id)
            ->where('event', 'production.stage_transfer.'.$event)->latest('id')->first();
        $actor = $event === 'posted' ? $owner->approved_by : $owner->reversed_by;
        if ($audit === null || (int) $audit->causer_id !== (int) $actor || json_decode($audit->properties, true, flags: JSON_THROW_ON_ERROR) !== $properties) {
            throw new DomainException(__('production_stage_transfer.invalid'));
        }
    }

    /** @param array<mixed> $value */
    private function digest(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
