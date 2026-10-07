<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Production\Models\ProductionRun;

final class ProductionCorrectionContextService
{
    public const OriginalPeriod = 'original_period';

    public const LaterPeriod = 'later_period';

    public function scopeRunsForPeriod(Builder|\Illuminate\Database\Eloquent\Builder $query, int $periodId): void
    {
        $query->where(function ($periods) use ($periodId): void {
            $periods->where(fn ($original) => $original->where('production_runs.financial_period_id', $periodId)
                ->where(fn ($target) => $target->whereNull('production_runs.active_correction_id')
                    ->orWhere('production_runs.correction_posting_financial_period_id', $periodId)));
            if (auth()->user()?->can('production.runs.correct_later_period')) {
                $periods->orWhere(fn ($later) => $later->where('production_runs.correction_posting_financial_period_id', $periodId)
                    ->whereExists(fn ($approved) => $approved->selectRaw('1')->from('production_run_corrections as correction')
                        ->whereColumn('correction.id', 'production_runs.active_correction_id')->where('correction.status', 'approved')
                        ->whereColumn('correction.company_id', 'production_runs.company_id')->whereColumn('correction.production_run_id', 'production_runs.id')
                        ->whereColumn('correction.financial_period_id', 'production_runs.financial_period_id')
                        ->whereColumn('correction.posting_financial_period_id', 'production_runs.correction_posting_financial_period_id')));
            }
        });
    }

    public function target(ProductionRun $run, string $date, string $mode, ?int $expectedPeriodId = null): FinancialPeriod
    {
        if (! in_array($mode, [self::OriginalPeriod, self::LaterPeriod], true)) {
            throw new DomainException(__('production_run_correction.posting_date_invalid'));
        }
        $company = app(OperatingCompanyContextService::class)->currentCompany();
        abort_unless($company !== null && (int) $company->id === (int) $run->company_id
            && (int) request()->session()->get(OperatingContextService::BranchIdKey) === (int) $run->branch_id, 404);
        $scope = app(OperatingScopeAccessService::class);
        $source = $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
            ->where('financial_periods.id', $run->financial_period_id)->lockForUpdate()->firstOrFail();
        $activeId = (int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey);
        $target = $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
            ->where('financial_periods.id', $activeId)->lockForUpdate()->firstOrFail();
        if ($expectedPeriodId !== null && (int) $target->id !== $expectedPeriodId) {
            throw new DomainException(__('production_run_correction.target_changed'));
        }
        if ($mode === self::LaterPeriod) {
            Gate::authorize('production.runs.correct_later_period');
            if ((int) $source->id === (int) $target->id || $target->from_date->toDateString() <= $source->to_date->toDateString()
                || $date <= $source->to_date->toDateString()) {
                throw new DomainException(__('production_run_correction.later_period_required'));
            }
        } elseif ($source->is_closed || (int) $source->id !== (int) $target->id) {
            throw new DomainException(__('production_run_correction.open_period_required'));
        }

        return app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $run->company_id, $date,
            expectedPeriodId: (int) $target->id, lockForUpdate: true);
    }

    public function executionPeriodId(ProductionRun $run): int
    {
        if ($run->correction_posting_financial_period_id === null && $run->active_correction_id === null) {
            return (int) $run->financial_period_id;
        }
        $proposal = DB::table('production_run_corrections')->where('id', $run->active_correction_id)
            ->where('company_id', $run->company_id)->where('production_run_id', $run->id)->where('status', 'approved')->first();
        $original = $proposal === null ? null : json_decode($proposal->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $receiptOnly = $proposal !== null && data_get(json_decode($proposal->corrected_output, true, 512, JSON_THROW_ON_ERROR), 'kind') === ProductionReceiptCancellationService::Kind;
        if ($proposal === null || (int) $proposal->financial_period_id !== (int) $run->financial_period_id
            || (int) $proposal->posting_financial_period_id !== (int) $run->correction_posting_financial_period_id
            || $proposal->posting_date !== $run->correction_document_date?->toDateString()
            || (int) data_get($original, 'run.correction_sequence', -1) + ($receiptOnly ? 0 : 1) !== (int) $run->correction_sequence
            || (int) $proposal->prepared_by === (int) $proposal->approved_by || $proposal->approved_at === null) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        if ((int) $proposal->posting_financial_period_id !== (int) $run->financial_period_id) {
            Gate::authorize('production.runs.correct_later_period');
        }

        return (int) $proposal->posting_financial_period_id;
    }

    public function requireExecutionPeriod(ProductionRun $run): FinancialPeriod
    {
        $expected = $this->executionPeriodId($run);
        if ((int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey) !== $expected) {
            throw new DomainException(__('production_run_correction.target_changed'));
        }
        $mode = $expected === (int) $run->financial_period_id ? self::OriginalPeriod : self::LaterPeriod;

        return $this->target($run, $run->correction_document_date?->toDateString() ?? now()->toDateString(), $mode, $expected);
    }

    public function ownerPostingPeriod(ProductionRun $run, string $date): FinancialPeriod
    {
        $active = (int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey);

        return $this->target($run, $date, $active === (int) $run->financial_period_id ? self::OriginalPeriod : self::LaterPeriod, $active);
    }

    public function assertMeasuredExecution(ProductionRun $run): void
    {
        if ($run->active_correction_id === null && (int) $run->correction_sequence === 0) {
            return;
        }
        $this->executionPeriodId($run);
        $proposal = app(ProductionDailyReportCorrectionService::class)->assertApprovedCorrection($run, (int) $run->active_correction_id);
        $output = json_decode($proposal->corrected_output, true, flags: JSON_THROW_ON_ERROR);
        if (! ($output['reopens_execution'] ?? false)) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
    }

    public function assertOwnedPeriod(ProductionRun $run, int $periodId): void
    {
        if (! DB::table('financial_periods')->where('company_id', $run->company_id)->where('id', $periodId)->exists()) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        if ($periodId === (int) $run->financial_period_id) {
            return;
        }
        $owners = DB::table('production_run_corrections')->where('company_id', $run->company_id)->where('production_run_id', $run->id)
            ->where('financial_period_id', $run->financial_period_id)->where('posting_financial_period_id', $periodId)
            ->where('correction_mode', self::LaterPeriod)->where('corrected_output->kind', ProductionDailyReportCorrectionService::Kind)->where('status', 'approved')->orderBy('id')->get();
        if ($owners->isEmpty()) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        foreach ($owners as $owner) {
            app(ProductionDailyReportCorrectionService::class)->assertApprovedCorrection($run, (int) $owner->id);
        }
    }
}
