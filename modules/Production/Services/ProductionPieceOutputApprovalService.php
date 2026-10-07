<?php

namespace Modules\Production\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Production\Models\ProductionRun;

final class ProductionPieceOutputApprovalService
{
    public const WithdrawalKind = 'measured_piece_output_withdrawal';

    public function prepareWithdrawal(ProductionRun $run, string $reason, string $evidence): object
    {
        Gate::authorize('production.runs.correct');

        return DB::transaction(function () use ($run, $reason, $evidence): object {
            Company::query()->whereKey(app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->firstOrFail();
            $run = ProductionRun::query()->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->findOrFail($run->id);
            $period = app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, now()->toDateString());
            $pieces = DB::table('production_piece_approvals')->where('production_run_id', $run->id)->whereNull('revoked_at')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
            if ($pieces === [] || mb_strlen(trim($reason)) < 5 || mb_strlen(trim($evidence)) < 5
                || DB::table('production_run_corrections')->where('production_run_id', $run->id)->whereIn('status', ['prepared', 'applying'])->exists()) {
                throw new DomainException(__('production_run_correction.reject_existing'));
            }
            $source = ['run' => $run->getRawOriginal(), 'pieces' => $pieces,
                'dependencies' => app(ProductionCorrectionDependencyService::class)->snapshot($run, collect(), collect(), true)];
            $output = ['kind' => self::WithdrawalKind, 'evidence' => trim($evidence), 'source_seal' => $this->seal($source)];
            $mode = (int) $period->id === (int) $run->financial_period_id ? ProductionCorrectionContextService::OriginalPeriod : ProductionCorrectionContextService::LaterPeriod;
            $output['proposal_seal'] = $this->seal(['company_id' => (int) $run->company_id, 'production_run_id' => (int) $run->id,
                'financial_period_id' => (int) $run->financial_period_id, 'posting_financial_period_id' => (int) $period->id,
                'correction_mode' => $mode,
                'posting_date' => now()->toDateString(), 'reason' => trim($reason), 'prepared_by' => (int) auth()->id(), 'source_seal' => $output['source_seal'], 'evidence' => trim($evidence)]);
            $id = DB::table('production_run_corrections')->insertGetId(['company_id' => $run->company_id, 'production_run_id' => $run->id,
                'financial_period_id' => $run->financial_period_id, 'posting_financial_period_id' => $period->id,
                'correction_mode' => $mode,
                'posting_date' => now()->toDateString(), 'reason' => trim($reason), 'fingerprint' => $this->seal($source), 'prepared_by' => auth()->id(), 'status' => 'prepared',
                'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR), 'corrected_output' => json_encode($output, JSON_THROW_ON_ERROR),
                'receipt_date_basis' => '[]', 'receipt_date_evidence' => trim($evidence), 'created_at' => now(), 'updated_at' => now()]);
            app(ActivityLogger::class)->log(request(), 'production', 'production.piece_output.withdrawal_prepared', 'success', ['subject' => $run,
                'company_id' => $run->company_id, 'properties_only' => true, 'properties' => ['correction_id' => $id, 'source_seal' => $output['source_seal']]]);

            return DB::table('production_run_corrections')->find($id);
        }, 3);
    }

    public function approveWithdrawal(ProductionRun $run, int $id): object
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($run, $id): object {
            Company::query()->whereKey(app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->firstOrFail();
            $run = ProductionRun::query()->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->findOrFail($run->id);
            $owner = DB::table('production_run_corrections')->where('company_id', $run->company_id)->where('production_run_id', $run->id)->lockForUpdate()->find($id);
            abort_if($owner === null, 404);
            $source = json_decode($owner->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $output = json_decode($owner->corrected_output, true, flags: JSON_THROW_ON_ERROR);
            $proposalSeal = $this->seal(['company_id' => (int) $owner->company_id, 'production_run_id' => (int) $owner->production_run_id,
                'financial_period_id' => (int) $owner->financial_period_id, 'posting_financial_period_id' => (int) $owner->posting_financial_period_id,
                'correction_mode' => $owner->correction_mode,
                'posting_date' => $owner->posting_date, 'reason' => $owner->reason, 'prepared_by' => (int) $owner->prepared_by,
                'source_seal' => $output['source_seal'] ?? '', 'evidence' => $output['evidence'] ?? '']);
            if ($owner->status === 'approved' && $owner->approved_at !== null && (int) $owner->prepared_by !== (int) $owner->approved_by
                && ($output['kind'] ?? null) === self::WithdrawalKind
                && hash_equals($output['proposal_seal'] ?? '', $proposalSeal)
                && hash_equals($output['execution_seal'] ?? '', $this->seal($output['execution'] ?? []))
                && ($output['execution'] ?? []) === DB::table('production_piece_approvals')->where('production_run_id', $run->id)
                    ->whereIn('id', array_column($source['pieces'], 'id'))->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()
                && DB::table('activity_log')->where('company_id', $run->company_id)->where('subject_type', ProductionRun::class)->where('subject_id', $run->id)
                    ->where('causer_id', $owner->approved_by)
                    ->where('event', 'production.piece_output.withdrawn')->where('properties->correction_id', $id)->where('properties->execution_seal', $output['execution_seal'])->exists()) {
                app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, now()->toDateString());

                return $owner;
            }
            if ($owner->status !== 'prepared' || (int) $owner->prepared_by === (int) auth()->id() || ($output['kind'] ?? null) !== self::WithdrawalKind
                || ! hash_equals($owner->fingerprint, $this->seal($source)) || ! hash_equals($output['source_seal'], $this->seal($source))
                || ! hash_equals($output['proposal_seal'] ?? '', $proposalSeal)
                || $source['pieces'] !== DB::table('production_piece_approvals')->where('production_run_id', $run->id)->whereNull('revoked_at')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            app(ProductionCorrectionContextService::class)->target($run, $owner->posting_date, $owner->correction_mode, (int) $owner->posting_financial_period_id);
            $dependencies = app(ProductionCorrectionDependencyService::class)->snapshot($run, collect(), collect(), true);
            DB::table('production_run_corrections')->where('id', $id)->update(['status' => 'applying']);
            app(ProductionRunCorrectionService::class)->invalidatePiecePayroll($run, $id, $source['pieces'], $dependencies);
            $output['execution'] = DB::table('production_piece_approvals')->where('production_run_id', $run->id)->whereIn('id', array_column($source['pieces'], 'id'))
                ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
            $output['execution_seal'] = $this->seal($output['execution']);
            DB::table('production_run_corrections')->where('id', $id)->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(),
                'corrected_output' => json_encode($output, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            app(ActivityLogger::class)->log(request(), 'production', 'production.piece_output.withdrawn', 'success', ['subject' => $run,
                'company_id' => $run->company_id, 'properties_only' => true, 'properties' => ['correction_id' => $id, 'execution_seal' => $output['execution_seal']]]);

            return DB::table('production_run_corrections')->find($id);
        }, 3);
    }

    public function approve(ProductionRun $run, string $reason, string $evidence): void
    {
        Gate::authorize('production.runs.complete');
        Gate::authorize('production.runs.correct_approve');
        if (! Schema::hasColumn('production_piece_approvals', 'output_evidence_snapshot')) {
            throw new DomainException(__('production_daily_report.correction.piece_migration_required'));
        }
        DB::transaction(function () use ($run, $reason, $evidence): void {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $run = ProductionRun::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($run->id);
            app(ProductionCorrectionContextService::class)->ownerPostingPeriod($run, now()->toDateString());
            $existing = DB::table('production_piece_approvals')->where('production_run_id', $run->id)->where('correction_sequence', $run->correction_sequence)->whereNull('revoked_at')->get();
            if ($existing->isNotEmpty()) {
                foreach ($existing as $approval) {
                    $this->assertEvidence($approval, $run);
                }

                return;
            }
            app(ProductionReceiptCancellationService::class)->assertManufacturingAllowed($run);
            app(ProductionStageTransferService::class)->assertCostMutationAllowed($run, conversionOnly: true);
            if (! app(ProductionStageTransferService::class)->isManaged($run) || ! in_array($run->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld], true)
                || mb_strlen(trim($reason)) < 5 || mb_strlen(trim($evidence)) < 5
                || app(ProductionShiftEvidenceService::class)->pendingDailyReports($run)
                || bccomp((string) $run->good_base_quantity, '0', 8) <= 0
                || bccomp(app(ProductionQualityQuantityService::class)->availableQuantity($run), (string) $run->good_base_quantity, 8) !== 0) {
                throw new DomainException(__('production_daily_report.correction.piece_output_required'));
            }
            $details = collect($run->labor_details ?? [])->filter(fn (array $labor): bool => bccomp((string) ($labor['piece_quantity'] ?? '0'), '0', 8) > 0);
            $total = $details->reduce(fn (string $sum, array $labor): string => bcadd($sum, (string) $labor['piece_quantity'], 8), '0.00000000');
            if ($details->isEmpty() || bccomp($total, (string) $run->good_base_quantity, 8) > 0
                || $details->contains(fn (array $labor): bool => empty($labor['recorded_by']) || (int) $labor['recorded_by'] === (int) auth()->id()
                    || bccomp((string) ($labor['piece_rate_snapshot'] ?? '0'), '0', 4) <= 0)) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            $snapshot = ['version' => 1, 'kind' => 'measured_piece_output_before_cost_close', 'run' => $this->contract($run),
                'labor' => $this->labor($run), 'progress' => $run->progressEntries()->orderBy('id')->get()->map->getRawOriginal()->all(),
                'batches' => app(ProductionQualityQuantityService::class)->availableBatches($run)->map(fn ($batch): array => (array) $batch)->all(),
                'output_completed_at' => $run->actual_end_at?->toIso8601String() ?? $run->progressEntries()->whereNull('production_run_correction_id')->max('recorded_at'),
                'reason' => trim($reason), 'evidence' => trim($evidence), 'approved_by' => auth()->id(), 'approved_at' => now()->toIso8601String()];
            if ($snapshot['output_completed_at'] === null) {
                throw new DomainException(__('production_daily_report.correction.piece_output_required'));
            }
            $snapshot['output_completed_at'] = CarbonImmutable::parse($snapshot['output_completed_at'])->toDateTimeString();
            foreach ($details as $labor) {
                $id = DB::table('production_piece_approvals')->insertGetId(['production_run_id' => $run->id, 'correction_sequence' => $run->correction_sequence,
                    'company_id' => $run->company_id, 'branch_id' => $run->branch_id, 'employee_id' => $labor['employee_id'], 'pay_basis' => 'piece_rate',
                    'quantity' => $labor['piece_quantity'], 'rate' => $labor['piece_rate_snapshot'], 'run_good_base_quantity' => $run->good_base_quantity,
                    'approved_at' => now(), 'approved_by' => auth()->id(), 'output_evidence_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'output_evidence_seal' => $this->seal($snapshot), 'output_completed_at' => $snapshot['output_completed_at'], 'created_at' => now(), 'updated_at' => now()]);
                app(ActivityLogger::class)->log(request(), 'production', 'production.piece_output.approved', 'success', ['subject' => $run,
                    'company_id' => $run->company_id, 'properties_only' => true, 'properties' => ['piece_approval_id' => $id, 'seal' => $this->seal($snapshot)]]);
            }
        }, 3);
    }

    public function assertEvidence(object $approval, ProductionRun $run): void
    {
        $snapshot = json_decode($approval->output_evidence_snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $labor = collect($snapshot['labor'] ?? [])->firstWhere('employee_id', (int) $approval->employee_id);
        if (($snapshot['kind'] ?? null) !== 'measured_piece_output_before_cost_close' || $labor === null || $approval->revoked_at !== null
            || ! hash_equals($approval->output_evidence_seal ?? '', $this->seal($snapshot))
            || $snapshot['run'] !== $this->contract($run) || $snapshot['labor'] !== $this->labor($run)
            || $snapshot['progress'] !== $run->progressEntries()->orderBy('id')->get()->map->getRawOriginal()->all()
            || (int) $approval->company_id !== (int) $run->company_id || (int) $approval->branch_id !== (int) $run->branch_id
            || (int) $approval->production_run_id !== (int) $run->id || (int) $approval->correction_sequence !== (int) $run->correction_sequence
            || (int) $approval->approved_by !== (int) $snapshot['approved_by'] || (int) $labor['recorded_by'] === (int) $approval->approved_by
            || (string) $approval->output_completed_at !== $snapshot['output_completed_at']
            || bccomp((string) $approval->quantity, (string) $labor['piece_quantity'], 8) !== 0 || bccomp((string) $approval->rate, (string) $labor['piece_rate_snapshot'], 4) !== 0
            || ! DB::table('activity_log')->where('company_id', $run->company_id)->where('subject_type', ProductionRun::class)->where('subject_id', $run->id)
                ->where('event', 'production.piece_output.approved')->where('causer_id', $approval->approved_by)
                ->where('properties->piece_approval_id', $approval->id)->where('properties->seal', $approval->output_evidence_seal)->exists()) {
            throw new DomainException(__('production_daily_report.correction.piece_output_required'));
        }
        foreach ($snapshot['batches'] as $batch) {
            $inspection = DB::table('quality_inspections')->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)
                ->where('production_run_id', $run->id)->where('correction_sequence', $run->correction_sequence)
                ->where('production_quality_output_batch_id', $batch['id'])->whereNull('deleted_at')->orderByDesc('id')->first();
            $source = DB::table('production_quality_output_batches')->where('company_id', $run->company_id)->where('production_run_id', $run->id)->find($batch['id']);
            if ($source === null || $source->withdrawn_at !== null || $inspection === null || ! in_array($inspection->status, ['approved', 'closed'], true)
                || $inspection->approved_at === null || $inspection->result !== 'passed' || $inspection->disposition !== 'release'
                || bccomp((string) ($inspection->accepted_base_quantity ?? $source->base_quantity), (string) $batch['accepted_quantity'], 8) !== 0) {
                throw new DomainException(__('production_daily_report.correction.piece_output_required'));
            }
        }
    }

    public function assertMutable(ProductionRun $run): void
    {
        if (Schema::hasColumn('production_piece_approvals', 'output_evidence_snapshot')
            && DB::table('production_piece_approvals')->where('production_run_id', $run->id)->whereNull('revoked_at')->whereNotNull('output_evidence_snapshot')->exists()) {
            throw new DomainException(__('production_daily_report.correction.piece_recovery_required'));
        }
    }

    /** @return array<string, mixed> */
    private function contract(ProductionRun $run): array
    {
        return $run->only(['id', 'company_id', 'branch_id', 'financial_period_id', 'production_order_id', 'production_order_line_id', 'product_id',
            'unit_id', 'conversion_factor', 'correction_sequence', 'good_base_quantity']);
    }

    /** @return list<array<string, mixed>> */
    public function labor(ProductionRun $run): array
    {
        return collect($run->labor_details ?? [])->map(function (array $row): array {
            unset($row['approved_piece_quantity'], $row['piece_quantity_approved_at'], $row['piece_quantity_approved_by']);

            return $row;
        })->all();
    }

    /** @param array<string, mixed> $snapshot */
    private function seal(array $snapshot): string
    {
        return hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
