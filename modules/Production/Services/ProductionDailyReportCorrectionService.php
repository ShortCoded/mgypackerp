<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionProgressEntry;
use Modules\Production\Models\ProductionRun;

final class ProductionDailyReportCorrectionService
{
    public const Kind = 'daily_report_entry';

    public function __construct(private readonly ProductionCorrectionContextService $context) {}

    /** @return array<string, mixed> */
    public function preview(ProductionRun $run, string $entryPublicId): array
    {
        abort_unless(Gate::any(['production.runs.correct', 'production.runs.correct_approve', 'production.quality.review']), 403);
        $run = $this->scopedRun($run);
        $entry = $this->entry($run, $entryPublicId);
        $snapshot = $this->snapshot($run, $entry);
        $blockers = [];
        try {
            $this->context->target($run, now()->toDateString(), $this->mode($run));
            $this->assertOwnerRecovery($run);
            app(ProductionQualityQuantityService::class)->assertDailyCorrectionWithdrawals($run);
        } catch (DomainException $exception) {
            $blockers[] = $exception->getMessage();
        }

        return ['record' => $run, 'entry' => $entry, 'snapshot' => $snapshot, 'fingerprint' => $this->digest($snapshot),
            'effective_quantity' => bcdiv($this->effectiveBase($run, $entry), (string) $run->conversion_factor, 8),
            'effective_quantities' => array_map(fn (string $quantity): string => bcdiv($quantity, (string) $run->conversion_factor, 8), $this->effectiveOutcomes($run, $entry)),
            'correction_mode' => $this->mode($run), 'dependency_steps' => app(ProductionCorrectionDependencyService::class)->steps($snapshot['dependency_snapshot']),
            'blockers' => $blockers, 'batches' => DB::table('production_quality_output_batches')->where('production_run_id', $run->id)->whereNull('withdrawn_at')->get(),
            'corrections' => DB::table('production_run_corrections')->where('production_run_id', $run->id)
                ->where('corrected_output->kind', self::Kind)->where('corrected_output->entry_id', $entry->id)->orderByDesc('id')->get()];
    }

    public function prepare(ProductionRun $run, string $entryPublicId, string $quantity, string $reason, string $evidence, string $fingerprint, string $date, array $quantities = []): object
    {
        Gate::authorize('production.runs.correct');

        return DB::transaction(function () use ($run, $entryPublicId, $quantity, $reason, $evidence, $fingerprint, $date, $quantities): object {
            $this->lockCompany();
            $run = $this->scopedRun($run, true);
            $entry = $this->entry($run, $entryPublicId);
            $this->assertOwnerRecovery($run);
            app(ProductionQualityQuantityService::class)->assertDailyCorrectionWithdrawals($run);
            $mode = $this->mode($run);
            $target = $this->context->target($run, $date, $mode);
            if ($date < $entry->recorded_at->toDateString() || $date > now()->toDateString()) {
                throw new DomainException(__('production_run_correction.posting_date_invalid'));
            }
            $this->reason($reason, $evidence);
            $snapshot = $this->snapshot($run, $entry);
            if (! hash_equals($this->digest($snapshot), $fingerprint)) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $output = ['kind' => self::Kind, 'entry_id' => $entry->id, 'entry_public_id' => $entry->public_id,
                'quantity' => app(ProductionOutputEvidenceService::class)->quantity($quantity), 'evidence' => trim($evidence),
                'reopens_execution' => $run->status === ProductionRun::StatusCompleted || $run->active_correction_id !== null || $mode === ProductionCorrectionContextService::LaterPeriod];
            if ($quantities !== []) {
                $current = $this->effectiveOutcomes($run, $entry);
                $output['quantities'] = [];
                foreach (ProductionStageOutputCostService::OutputFields as $kind => $field) {
                    $output['quantities'][$kind] = app(ProductionOutputEvidenceService::class)->quantity($kind === 'good' ? $output['quantity']
                        : ($quantities[$kind] ?? bcdiv($current[$kind], (string) $run->conversion_factor, 8)));
                }
            }
            $deltas = $this->outcomeDeltas($run, $entry, $output);
            $delta = array_reduce($deltas, fn (string $sum, string $value): string => bcadd($sum, $value, 8), '0.00000000');
            $output['input_plan'] = app(ProductionStageInputAdjustmentService::class)->plan($run, $entry, $delta);
            $data = ['company_id' => $run->company_id, 'production_run_id' => $run->id, 'financial_period_id' => $run->financial_period_id,
                'posting_financial_period_id' => $target->id, 'correction_mode' => $mode,
                'posting_date' => $date, 'reason' => trim($reason), 'fingerprint' => $fingerprint, 'prepared_by' => auth()->id()];
            $output['proposal_seal'] = $this->digest(['proposal' => $data, 'output' => $output]);
            $pending = DB::table('production_run_corrections')->where('production_run_id', $run->id)->whereIn('status', ['prepared', 'applying'])->lockForUpdate()->first();
            if ($pending !== null) {
                if ($pending->status === 'prepared' && json_decode($pending->corrected_output, true, flags: JSON_THROW_ON_ERROR) === $output) {
                    return $pending;
                }
                throw new DomainException(__('production_run_correction.reject_existing'));
            }
            $id = DB::table('production_run_corrections')->insertGetId([...$data, 'status' => 'prepared',
                'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'corrected_output' => json_encode($output, JSON_THROW_ON_ERROR),
                'receipt_date_basis' => '[]', 'receipt_date_evidence' => trim($evidence), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($run, 'production.daily_report_correction.prepared', ['correction_id' => $id, 'entry_id' => $entry->id, 'proposal_seal' => $output['proposal_seal']]);

            return DB::table('production_run_corrections')->find($id);
        }, 3);
    }

    public function approve(ProductionRun $run, int $id): object
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($run, $id): object {
            $this->lockCompany();
            $run = $this->scopedRun($run, true);
            $proposal = DB::table('production_run_corrections')->where('company_id', $run->company_id)->where('production_run_id', $run->id)->lockForUpdate()->find($id);
            abort_if($proposal === null, 404);
            $output = json_decode($proposal->corrected_output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSeal($proposal, $output);
            if ((int) $proposal->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            if ($proposal->status === 'approved') {
                $this->assertExecution($run, $proposal, $output);

                return $proposal;
            }
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('production_run_correction.invalid_state'));
            }
            $entry = $this->entry($run, $output['entry_public_id']);
            if ((int) $entry->id !== (int) $output['entry_id']) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            $this->assertOwnerRecovery($run);
            app(ProductionQualityQuantityService::class)->assertDailyCorrectionWithdrawals($run);
            $this->context->target($run, $proposal->posting_date, $proposal->correction_mode, (int) $proposal->posting_financial_period_id);
            $snapshot = json_decode($proposal->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
            if (! hash_equals($proposal->fingerprint, $this->digest($snapshot))
                || ! hash_equals($proposal->fingerprint, $this->digest($this->snapshot($run, $entry)))) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $deltas = $this->outcomeDeltas($run, $entry, $output);
            $delta = array_reduce($deltas, fn (string $sum, string $value): string => bcadd($sum, $value, 8), '0.00000000');
            $inputService = app(ProductionStageInputAdjustmentService::class);
            $inputPlan = $inputService->plan($run, $entry, $delta);
            if ($inputPlan !== ($output['input_plan'] ?? [])) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            DB::table('production_run_corrections')->where('id', $id)->update(['status' => 'applying']);
            $inputAdjustments = $inputService->apply($run, $entry, $id, $delta, $inputPlan);
            app(ProductionRunCorrectionService::class)->invalidatePiecePayroll($run, $id, $snapshot['piece_approvals'], $snapshot['dependency_snapshot']);
            $adjustment = $run->progressEntries()->create(['production_shift_entry_id' => $entry->production_shift_entry_id,
                'production_run_correction_id' => $id, ...$deltas, 'recorded_at' => $proposal->posting_date.' '.now()->format('H:i:s'),
                'recorded_by' => auth()->id(), 'notes' => $proposal->reason.' — '.$output['evidence'], 'material_documents' => []]);
            $run->update(collect($deltas)->mapWithKeys(fn (string $value, string $field): array => [$field => bcadd((string) $run->{$field}, $value, 8)])->all());
            if ($output['reopens_execution'] ?? false) {
                $labor = collect($run->labor_details ?? [])->map(function (array $row): array {
                    unset($row['approved_piece_quantity'], $row['piece_quantity_approved_at'], $row['piece_quantity_approved_by']);

                    return $row;
                })->all();
                $run->update(['status' => ProductionRun::StatusRunning, 'completed_by' => null,
                    'correction_sequence' => $run->correction_sequence + 1, 'active_correction_id' => $id,
                    'correction_document_date' => $proposal->posting_date, 'correction_posting_financial_period_id' => $proposal->posting_financial_period_id,
                    'labor_details' => $labor, 'updated_by' => auth()->id()]);
                if ($run->production_order_stage_snapshot_id !== null) {
                    $stage = $run->stageSnapshot()->lockForUpdate()->firstOrFail();
                    $previous = $stage->status;
                    $stage->update(['status' => ProductionOrderStageSnapshot::StatusInProgress, 'completed_at' => null, 'completed_by' => null]);
                    app(ProductionRoutingService::class)->recordStageEvent($stage, 'daily_report_correction', $previous, $stage->status, $run->id);
                }
                $order = $run->order()->lockForUpdate()->firstOrFail();
                $order->update(['status' => $order->lines()->where('received_base_quantity', '>', 0)->exists()
                    ? ProductionOrder::StatusPartiallyCompleted : ProductionOrder::StatusInProgress, 'updated_by' => auth()->id()]);
            }
            $output['execution'] = ['adjustment' => $adjustment->fresh()->getRawOriginal(), 'approved_by' => auth()->id(), 'input_adjustments' => $inputAdjustments];
            $output['execution']['piece_approvals'] = DB::table('production_piece_approvals')->where('production_run_id', $run->id)
                ->whereIn('id', array_column($snapshot['piece_approvals'], 'id'))->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
            $output['execution_seal'] = $this->digest($output['execution']);
            DB::table('production_run_corrections')->where('id', $id)->update(['status' => 'approved', 'approved_by' => auth()->id(),
                'approved_at' => now(), 'corrected_output' => json_encode($output, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $this->audit($run, 'production.daily_report_correction.approved', ['correction_id' => $id, 'entry_id' => $entry->id,
                'original_quantity' => (string) $entry->good_base_quantity, 'corrected_quantity' => $output['quantity'], 'delta_base_quantity' => $deltas['good_base_quantity'], 'outcome_deltas' => $deltas,
                'evidence' => $output['evidence'], 'proposal_seal' => $output['proposal_seal'], 'execution_seal' => $output['execution_seal']]);

            return DB::table('production_run_corrections')->find($id);
        }, 3);
    }

    public function assertOwnerRecovery(ProductionRun $run): void
    {
        foreach (ProductionStageOutputCostService::OutputFields as $field) {
            if (bccomp((string) $run->{$field}, (string) $run->progressEntries()->sum($field), 8) !== 0) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
        }
        app(ProductionStageTransferService::class)->assertRunRecovery($run, inputDocumentCorrection: true);
        if ($run->trashed() || ! app(ProductionShiftEvidenceService::class)->hasDailyReports($run)
            || bccomp((string) $run->good_base_quantity, (string) $run->progressEntries()->sum('good_base_quantity'), 8) !== 0
            || ! in_array($run->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld, ProductionRun::StatusCompleted], true)
            || bccomp((string) $run->received_base_quantity, '0', 8) !== 0
            || (! app(ProductionStageTransferService::class)->isManaged($run) && ProductionRun::query()->where('production_order_line_id', $run->production_order_line_id)->where('id', '<>', $run->id)
                ->whereHas('progressEntries')->exists())) {
            throw new DomainException(__('production_daily_report.correction.owner_recovery_required'));
        }
        if ($run->active_correction_id !== null) {
            $this->context->executionPeriodId($run);
            $active = DB::table('production_run_corrections')->find($run->active_correction_id);
            $kind = data_get(json_decode($active->corrected_output, true, flags: JSON_THROW_ON_ERROR), 'kind');
            if (! in_array($kind, [self::Kind, ProductionReceiptCancellationService::Kind], true)) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            if ($kind === self::Kind) {
                $this->assertApprovedCorrection($run, (int) $active->id);
            }
        }
        $dependencies = $this->dependencies($run);
        if (app(ProductionCorrectionDependencyService::class)->steps($dependencies) !== []) {
            throw new DomainException(__('production_daily_report.correction.payroll_recovery_required'));
        }
        app(ProductionCorrectionDependencyService::class)->assertCanInvalidateCalculatedPayroll($dependencies);
        $documents = InventoryDocument::withTrashed()->whereIn('id', DB::table('inventory_document_lines')->where('production_run_id', $run->id)->select('inventory_document_id'));
        foreach ($documents->whereIn('document_type', [InventoryDocument::TypeProductionHandover, InventoryDocument::TypeProductionReceipt])->orderBy('id')->get() as $document) {
            if ($document->trashed() || (int) $document->company_id !== (int) $run->company_id) {
                throw new DomainException(__('production_daily_report.correction.owner_recovery_required'));
            }
            if ($document->document_type === InventoryDocument::TypeProductionReceipt && $document->status === InventoryDocument::StatusReversed
                && app(ProductionWarehouseReceiptCorrectionService::class)->hasVerifiedReversal($document)) {
                continue;
            }
            $event = $document->document_type === InventoryDocument::TypeProductionHandover ? 'production.handover.cancelled' : 'production.warehouse_receipt.cancelled';
            if ($document->status !== InventoryDocument::StatusCancelled || $document->cancelled_at === null || $document->cancelled_by === null
                || blank($document->cancel_reason) || $document->transactions()->exists() || $document->journal_entry_id !== null
                || ! DB::table('activity_log')->where('company_id', $run->company_id)->where('subject_type', InventoryDocument::class)
                    ->where('subject_id', $document->id)->where('event', $event)->exists()) {
                throw new DomainException(__('production_daily_report.correction.owner_recovery_required'));
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ProductionRun $run, ProductionProgressEntry $entry): array
    {
        $rows = fn (string $table): array => DB::table($table)->where('production_run_id', $run->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $documents = InventoryDocument::withTrashed()->whereIn('id', DB::table('inventory_document_lines')->where('production_run_id', $run->id)->select('inventory_document_id'))->orderBy('id')->get();

        return ['run' => $run->getRawOriginal(), 'entry' => $entry->getRawOriginal(), 'progress' => $rows('production_progress_entries'),
            'shifts' => $rows('production_shift_entries'), 'quality' => $rows('quality_inspections'), 'batches' => $rows('production_quality_output_batches'),
            'requirements' => $rows('production_material_requirements'), 'piece_approvals' => $rows('production_piece_approvals'),
            'dependency_snapshot' => $this->dependencies($run),
            'input_consumptions' => Schema::hasTable('production_stage_input_consumptions')
                ? DB::table('production_stage_input_consumptions')->whereIn('production_progress_entry_id', $run->progressEntries()->select('id'))
                    ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all() : [],
            'input_adjustments' => Schema::hasTable('production_stage_input_adjustments')
                ? DB::table('production_stage_input_adjustments')->whereIn('production_progress_entry_id', $run->progressEntries()->select('id'))
                    ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all() : [],
            'documents' => $documents->map->getRawOriginal()->all(),
            'transactions' => DB::table('inventory_transactions')->whereIn('source_id', $documents->modelKeys())->where('source_type', InventoryDocument::class)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()];
    }

    private function scopedRun(ProductionRun $run, bool $lock = false): ProductionRun
    {
        $query = ProductionRun::query()->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId())
            ->where('branch_id', (int) request()->session()->get(OperatingContextService::BranchIdKey));

        $record = ($lock ? $query->lockForUpdate() : $query)->findOrFail($run->id);
        $company = app(OperatingCompanyContextService::class)->currentCompany();
        $scope = app(OperatingScopeAccessService::class);
        abort_unless($scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $record->branch_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $record->financial_period_id)->exists(), 404);

        return $record;
    }

    private function lockCompany(): void
    {
        Company::query()->whereKey(app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->firstOrFail();
    }

    private function mode(ProductionRun $run): string
    {
        return (int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey) === (int) $run->financial_period_id
            ? ProductionCorrectionContextService::OriginalPeriod : ProductionCorrectionContextService::LaterPeriod;
    }

    /** @return array<string, mixed> */
    private function dependencies(ProductionRun $run): array
    {
        return app(ProductionCorrectionDependencyService::class)->snapshot($run, collect(), collect(), DB::transactionLevel() > 0);
    }

    private function entry(ProductionRun $run, string $publicId): ProductionProgressEntry
    {
        $entry = $run->progressEntries()->where('public_id', $publicId)->whereNull('production_run_correction_id')->firstOrFail();
        $shift = DB::table('production_shift_entries')->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)
            ->where('production_run_id', $run->id)->find($entry->production_shift_entry_id);
        if ($shift === null || data_get(json_decode($shift->sheet_fields, true, flags: JSON_THROW_ON_ERROR), 'entry_source') !== 'daily_sheet') {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }

        return $entry;
    }

    private function effectiveBase(ProductionRun $run, ProductionProgressEntry $entry): string
    {
        foreach ($run->progressEntries()->where('production_shift_entry_id', $entry->production_shift_entry_id)->whereNotNull('production_run_correction_id')->get() as $delta) {
            $proposal = DB::table('production_run_corrections')->find($delta->production_run_correction_id);
            if ($proposal === null || (int) $proposal->production_run_id !== (int) $run->id || $proposal->status !== 'approved') {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            $output = json_decode($proposal->corrected_output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSeal($proposal, $output);
            $this->assertExecution($run, $proposal, $output);
        }

        return bcadd((string) $run->progressEntries()->where('production_shift_entry_id', $entry->production_shift_entry_id)->sum('good_base_quantity'), '0', 8);
    }

    /** @return array<string, string> */
    private function effectiveOutcomes(ProductionRun $run, ProductionProgressEntry $entry): array
    {
        $this->effectiveBase($run, $entry);
        $rows = $run->progressEntries()->where('production_shift_entry_id', $entry->production_shift_entry_id)->get();
        $result = [];
        foreach (ProductionStageOutputCostService::OutputFields as $kind => $field) {
            $result[$kind] = $rows->reduce(fn (string $sum, ProductionProgressEntry $row): string => bcadd($sum, (string) $row->{$field}, 8), '0.00000000');
            if (bccomp($result[$kind], '0', 8) < 0) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $output @return array<string, string> */
    private function outcomeDeltas(ProductionRun $run, ProductionProgressEntry $entry, array $output): array
    {
        $current = $this->effectiveOutcomes($run, $entry);
        $delta = [];
        $whole = '0.00000000';
        foreach (ProductionStageOutputCostService::OutputFields as $kind => $field) {
            $quantity = $output['quantities'][$kind] ?? ($kind === 'good' ? $output['quantity'] : bcdiv($current[$kind], (string) $run->conversion_factor, 8));
            $base = ($kind !== 'good' && ! isset($output['quantities']))
                || bccomp((string) $quantity, bcdiv($current[$kind], (string) $run->conversion_factor, 8), 8) === 0
                ? $current[$kind] : bcmul($quantity, (string) $run->conversion_factor, 8);
            $delta[$field] = bcsub($base, $current[$kind], 8);
            if ($kind !== 'good' && bccomp($delta[$field], '0', 8) !== 0 && ! app(ProductionStageTransferService::class)->isManaged($run)) {
                throw new DomainException(__('production_stage_transfer.loss_requires_managed_run'));
            }
            $next = bcadd((string) $run->{$field}, $delta[$field], 8);
            if (bccomp($base, '0', 8) < 0 || bccomp($next, '0', 8) < 0) {
                throw new DomainException(__('production_daily_report.correction.quantity_invalid'));
            }
            $whole = bcadd($whole, $next, 8);
        }
        if (bccomp($whole, (string) $run->planned_base_quantity, 8) > 0) {
            throw new DomainException(__('production_daily_report.correction.quantity_invalid'));
        }

        return $delta;
    }

    /** @param array<string, mixed> $output */
    private function assertSeal(object $proposal, array $output): void
    {
        $seal = $output['proposal_seal'] ?? '';
        unset($output['proposal_seal'], $output['execution'], $output['execution_seal']);
        $data = [];
        foreach (['company_id', 'production_run_id', 'financial_period_id', 'posting_financial_period_id', 'correction_mode', 'posting_date', 'reason', 'fingerprint', 'prepared_by'] as $field) {
            $data[$field] = $proposal->{$field};
        }
        if (($output['kind'] ?? null) !== self::Kind || ! hash_equals($seal, $this->digest(['proposal' => $data, 'output' => $output]))) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
    }

    /** @param array<string, mixed> $output */
    private function assertExecution(ProductionRun $run, object $proposal, array $output): void
    {
        $execution = $output['execution'] ?? [];
        $delta = $run->progressEntries()->where('production_run_correction_id', $proposal->id)->get();
        $inputAdjustments = Schema::hasTable('production_stage_input_adjustments')
            ? DB::table('production_stage_input_adjustments')->where('production_run_correction_id', $proposal->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all() : [];
        $pieces = array_key_exists('piece_approvals', $execution) ? DB::table('production_piece_approvals')->where('production_run_id', $run->id)
            ->whereIn('id', array_column($execution['piece_approvals'], 'id'))->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all() : [];
        if ($proposal->approved_at === null || (int) $proposal->approved_by === (int) $proposal->prepared_by
            || (int) $proposal->approved_by !== (int) ($execution['approved_by'] ?? 0) || $delta->count() !== 1
            || ($execution['adjustment'] ?? null) !== $delta->sole()->getRawOriginal()
            || ($execution['input_adjustments'] ?? []) !== $inputAdjustments
            || ($execution['piece_approvals'] ?? []) !== $pieces
            || ! DB::table('activity_log')->where('company_id', $run->company_id)->where('subject_type', ProductionRun::class)
                ->where('subject_id', $run->id)->where('event', 'production.daily_report_correction.approved')
                ->where('properties->correction_id', $proposal->id)->where('properties->execution_seal', $output['execution_seal'] ?? '')->exists()
            || ! hash_equals($output['execution_seal'] ?? '', $this->digest($execution))) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
    }

    public function assertApprovedCorrection(ProductionRun $run, int $id): object
    {
        $proposal = DB::table('production_run_corrections')->where('company_id', $run->company_id)->where('production_run_id', $run->id)->find($id);
        if ($proposal === null || $proposal->status !== 'approved' || (int) $proposal->financial_period_id !== (int) $run->financial_period_id) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $output = json_decode($proposal->corrected_output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSeal($proposal, $output);
        $this->assertExecution($run, $proposal, $output);

        return $proposal;
    }

    private function reason(string $reason, string $evidence): void
    {
        if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 2000 || mb_strlen(trim($evidence)) < 5 || mb_strlen($evidence) > 2000) {
            throw new DomainException(__('production_daily_report.correction.evidence_required'));
        }
    }

    /** @param array<string, mixed> $data */
    private function digest(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string, mixed> $properties */
    private function audit(ProductionRun $run, string $event, array $properties): void
    {
        app(ActivityLogger::class)->log(request(), 'production', $event, 'success', ['subject' => $run,
            'company_id' => $run->company_id, 'properties_only' => true, 'properties' => $properties]);
    }
}
