<?php

namespace Modules\HR\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;

final class PayrollCorrectionService
{
    public const ModeOriginalPeriod = 'original_period';

    public const ModeLaterPeriod = 'later_period';

    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly HrLifecycleAuditLogger $audit,
    ) {}

    /** @return array{run: object, snapshot: array<string, mixed>, fingerprint: string, corrections: Collection} */
    public function preview(int $runId): array
    {
        abort_unless(auth()->user()?->canAny(['hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve']), 403);
        $run = $this->scopedRun($runId);
        $snapshot = $this->snapshot($run);

        return [
            'run' => $run, 'snapshot' => $snapshot, 'fingerprint' => $this->fingerprint($snapshot),
            'corrections' => DB::table('hr_payroll_corrections as correction')
                ->leftJoin('journal_entries as reversal', function (JoinClause $join): void {
                    $join->on('reversal.id', '=', 'correction.reversal_journal_entry_id')
                        ->on('reversal.company_id', '=', 'correction.company_id');
                })
                ->where('correction.payroll_run_id', $runId)->where('correction.company_id', $run->company_id)
                ->orderByDesc('correction.id')->get(['correction.*', 'reversal.doc_num as reversal_journal_doc_num']),
        ];
    }

    public function propose(int $runId, string $date, string $reason, string $fingerprint, string $mode = self::ModeOriginalPeriod): object
    {
        Gate::authorize('hr.payroll_approval.correct');

        return DB::transaction(function () use ($runId, $date, $reason, $fingerprint, $mode): object {
            $companyId = $this->companies->requireCompanyId();
            Company::query()->whereKey($companyId)->active()->lockForUpdate()->firstOrFail();
            $run = $this->scopedRun($runId, true);
            $date = $this->date($date);
            if (trim($reason) === '' || mb_strlen($reason) > 2000) {
                throw new DomainException(__('hr_payroll_correction.reason_required'));
            }
            $snapshot = $this->snapshot($run, true);
            $this->assertCorrectable($run, $snapshot, $date, $mode);
            $this->assertFingerprint($snapshot, $fingerprint);
            $period = $this->postingPeriod($companyId, $date);
            $existing = DB::table('hr_payroll_corrections')->where('payroll_run_id', $runId)
                ->where('status', 'prepared')->orderByDesc('id')->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->fingerprint === $fingerprint && $existing->reversal_date === $date && $existing->reason === trim($reason)
                    && ($existing->correction_mode ?? self::ModeOriginalPeriod) === $mode) {
                    return $existing;
                }
                throw new DomainException(__('hr_payroll_correction.reject_existing'));
            }
            $id = DB::table('hr_payroll_corrections')->insertGetId([
                'company_id' => $companyId, 'payroll_run_id' => $runId,
                'financial_period_id' => $period->getKey(), 'original_journal_entry_id' => $snapshot['posting']->journal_entry_id,
                'reversal_date' => $date, 'reason' => trim($reason), 'status' => 'prepared', 'correction_mode' => $mode,
                'fingerprint' => $fingerprint, 'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'prepared_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->logStrict(request(), 'hr.payroll.correction_prepared', $companyId,
                ['payroll_run_id' => $runId, 'correction_id' => $id, 'fingerprint' => $fingerprint], null, 'payroll-correction:'.$id.':prepared', causer: auth()->user());

            return DB::table('hr_payroll_corrections')->where('id', $id)->first();
        }, attempts: 3);
    }

    public function approve(int $runId, int $correctionId): object
    {
        Gate::authorize('hr.payroll_approval.correct_approve');

        return DB::transaction(function () use ($runId, $correctionId): object {
            $companyId = $this->companies->requireCompanyId();
            Company::query()->whereKey($companyId)->active()->lockForUpdate()->firstOrFail();
            $run = $this->scopedRun($runId, true);
            $proposal = $this->proposal($runId, $correctionId, $companyId);
            if ((int) $proposal->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('hr_payroll_correction.independent_approval'));
            }
            if ($proposal->status === 'approved') {
                return $proposal;
            }
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('hr_payroll_correction.invalid_state'));
            }
            $snapshot = $this->snapshot($run, true);
            $this->assertCorrectable($run, $snapshot, $proposal->reversal_date, $proposal->correction_mode ?? self::ModeOriginalPeriod);
            $this->assertFingerprint($snapshot, $proposal->fingerprint);
            $period = $this->postingPeriod($companyId, $proposal->reversal_date);
            if ((int) $period->getKey() !== (int) $proposal->financial_period_id) {
                throw new DomainException(__('hr_payroll_correction.period_changed'));
            }
            $original = JournalEntry::query()->where('company_id', $companyId)->findOrFail($proposal->original_journal_entry_id);
            $reversal = $this->journals->createPostedReversalFromSource($original, [
                'entry_date' => $proposal->reversal_date, 'company_id' => $companyId,
                'financial_period_id' => $period->getKey(), 'branch_id' => $run->branch_id,
                'currency_id' => $original->currency_id, 'exchange_rate' => $original->exchange_rate,
                'description' => __('hr_payroll_correction.journal', ['run' => $runId]), 'notes' => $proposal->reason,
                'source_type' => 'hr_payroll_run_reversal', 'source_id' => $correctionId, 'source_doc_num' => 'PAYCORR-'.$correctionId,
            ]);
            foreach ($snapshot['applications'] as $application) {
                if ($application->applied_at === null || $application->reversed_at !== null) {
                    continue;
                }
                $slip = $snapshot['slips']->firstWhere('id', $application->payslip_id);
                $item = $snapshot['items']->firstWhere('id', $application->payslip_item_id);
                if ($slip === null || $item === null || (int) $item->payslip_id !== (int) $slip->id
                    || $item->direction !== 'deduction' || $item->source_type !== 'salary_advance'
                    || (int) $item->source_id !== (int) $application->salary_advance_id
                    || bccomp((string) $item->amount, (string) $application->amount, 4) !== 0
                    || $application->balance_before === null || $application->balance_after === null
                    || bccomp(bcsub((string) $application->balance_before, (string) $application->amount, 4), (string) $application->balance_after, 4) !== 0) {
                    throw new DomainException(__('hr_payroll_correction.advance_changed'));
                }
                $advance = DB::table('hr_salary_advances as advance')->join('hr_employees as employee', 'employee.id', '=', 'advance.employee_id')
                    ->where('advance.id', $application->salary_advance_id)->where('employee.company_id', $companyId)
                    ->where('advance.employee_id', $slip?->employee_id)->whereNull('employee.deleted_at')->lockForUpdate()->first(['advance.*']);
                if ($advance === null) {
                    throw new DomainException(__('hr_payroll_correction.advance_changed'));
                }
                $balance = bcadd((string) $advance->balance, (string) $application->amount, 4);
                if ($advance->deleted_at !== null || ! in_array($advance->status, ['active', 'settled'], true)
                    || bccomp($balance, (string) $advance->principal, 4) > 0) {
                    throw new DomainException(__('hr_payroll_correction.advance_changed'));
                }
                DB::table('hr_salary_advances')->where('id', $advance->id)->update([
                    'balance' => $balance, 'status' => 'active', 'updated_by' => auth()->id(), 'updated_at' => now(),
                ]);
                DB::table('hr_payroll_advance_applications')->where('id', $application->id)->update([
                    'reversed_at' => now(), 'reversed_by' => auth()->id(), 'updated_at' => now(),
                ]);
            }
            DB::table('hr_payroll_runs')->where('id', $runId)->update([
                'status' => 'reversed', 'reversal_date' => $proposal->reversal_date,
                'reversal_journal_entry_id' => $reversal->getKey(), 'reversed_at' => now(), 'reversed_by' => auth()->id(),
                'reversal_reason' => $proposal->reason, 'updated_by' => auth()->id(), 'updated_at' => now(),
            ]);
            DB::table('hr_payroll_corrections')->where('id', $correctionId)->update([
                'status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(),
                'reversal_journal_entry_id' => $reversal->getKey(), 'updated_at' => now(),
            ]);
            $this->audit->logStrict(request(), 'hr.payroll.correction_approved', $companyId,
                ['payroll_run_id' => $runId, 'correction_id' => $correctionId, 'journal_entry_id' => $reversal->getKey()], null, 'payroll-correction:'.$correctionId.':approved', causer: auth()->user());

            return DB::table('hr_payroll_corrections')->where('id', $correctionId)->first();
        }, attempts: 3);
    }

    public function reject(int $runId, int $correctionId): void
    {
        Gate::authorize('hr.payroll_approval.correct_approve');
        DB::transaction(function () use ($runId, $correctionId): void {
            $companyId = $this->companies->requireCompanyId();
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->scopedRun($runId, true);
            $proposal = $this->proposal($runId, $correctionId, $companyId);
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('hr_payroll_correction.invalid_state'));
            }
            DB::table('hr_payroll_corrections')->where('id', $correctionId)->update(['status' => 'rejected', 'rejected_at' => now(), 'updated_at' => now()]);
            $this->audit->logStrict(request(), 'hr.payroll.correction_rejected', $companyId,
                ['payroll_run_id' => $runId, 'correction_id' => $correctionId], null, 'payroll-correction:'.$correctionId.':rejected', causer: auth()->user());
        });
    }

    private function scopedRun(int $runId, bool $lock = false): object
    {
        $company = $this->companies->currentCompany();
        abort_unless($company !== null, 409);
        $run = DB::table('hr_payroll_runs as run')->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('run.id', $runId)->where('period.company_id', $company->getKey())
            ->whereNull('run.deleted_at')->whereNull('period.deleted_at')->when($lock, fn ($query) => $query->lockForUpdate())
            ->first(['run.*', 'period.company_id', 'period.period_start', 'period.period_end']);
        abort_unless($run !== null, 404);
        $user = auth()->user();
        if ($run->branch_id === null) {
            abort_unless($this->scope->hasUnrestrictedBranchAccess($user), 404);
        } else {
            abort_unless($this->scope->allowedBranchQuery($user, [$company->doc_num])->where('branches.id', $run->branch_id)->exists(), 404);
        }
        if (! $this->scope->hasUnrestrictedFinancialPeriodAccess($user)) {
            abort_unless($this->scope->allowedFinancialPeriodQuery($user, [$company->doc_num])
                ->whereDate('financial_periods.from_date', '<=', $run->period_start)->whereDate('financial_periods.to_date', '>=', $run->period_end)->exists(), 404);
        }

        return $run;
    }

    private function proposal(int $runId, int $id, int $companyId): object
    {
        return DB::table('hr_payroll_corrections')->where('id', $id)->where('company_id', $companyId)
            ->where('payroll_run_id', $runId)->lockForUpdate()->first() ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function snapshot(object $run, bool $lock = false): array
    {
        $posting = DB::table('hr_payroll_postings')->where('payroll_run_id', $run->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $journal = $posting === null ? null : DB::table('journal_entries')->where('id', $posting->journal_entry_id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $sourcePeriod = $journal === null ? null : DB::table('financial_periods')->where('id', $journal->financial_period_id)
            ->where('company_id', $run->company_id)->whereNull('deleted_at')->when($lock, fn ($query) => $query->lockForUpdate())
            ->first(['id', 'company_id', 'from_date', 'to_date', 'is_closed']);
        $slips = DB::table('hr_payslips')->where('payroll_run_id', $run->id)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $applications = DB::table('hr_payroll_advance_applications')->where('payroll_run_id', $run->id)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($lock) {
            DB::table('hr_payroll_payments')->where('payroll_run_id', $run->id)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        return [
            'run' => $run, 'posting' => $posting, 'journal' => $journal, 'source_period' => $sourcePeriod, 'slips' => $slips,
            'items' => DB::table('hr_payslip_items')->whereIn('payslip_id', $slips->pluck('id'))->orderBy('id')->get(),
            'journal_lines' => DB::table('journal_entry_lines')->where('journal_entry_id', $journal?->id ?? 0)->orderBy('id')->get(),
            'payments' => DB::table('hr_payroll_payments as payment')->leftJoin('cash_vouchers as voucher', 'voucher.id', '=', 'payment.cash_voucher_id')
                ->leftJoin('journal_entries as payment_journal', 'payment_journal.id', '=', 'payment.journal_entry_id')
                ->leftJoin('journal_entries as reversal', 'reversal.id', '=', 'payment.reversal_journal_entry_id')
                ->where('payment.payroll_run_id', $run->id)->orderBy('payment.id')
                ->get(['payment.*', 'voucher.status as voucher_status', 'voucher.deleted_at as voucher_deleted_at', 'voucher.doc_num as voucher_doc_num',
                    'payment_journal.company_id as payment_journal_company_id', 'payment_journal.source_type as payment_journal_source_type',
                    'payment_journal.source_id as payment_journal_source_id', 'payment_journal.reversed_entry_id as payment_journal_reversed_entry_id',
                    'reversal.entry_date as reversal_date', 'reversal.status as reversal_status', 'reversal.is_posted as reversal_is_posted',
                    'reversal.company_id as reversal_company_id', 'reversal.source_type as reversal_source_type', 'reversal.source_id as reversal_source_id']),
            'applications' => $applications,
            'advances' => DB::table('hr_salary_advances')->whereIn('id', $applications->pluck('salary_advance_id'))->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get(),
            'inputs' => DB::table('hr_payroll_inputs')->where('payroll_run_id', $run->id)->orderBy('id')->get(),
            'attendance_inputs' => DB::table('hr_payroll_attendance_inputs')->where('payroll_run_id', $run->id)->orderBy('id')->get(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function assertCorrectable(object $run, array $snapshot, string $date, string $mode): void
    {
        if ($run->status !== 'posted' || $snapshot['journal']?->status !== 'posted'
            || ! $snapshot['journal']->is_posted || $snapshot['journal']->reversed_entry_id !== null || $date < $run->period_end) {
            throw new DomainException(__('hr_payroll_correction.invalid_state'));
        }
        if (! in_array($mode, [self::ModeOriginalPeriod, self::ModeLaterPeriod], true) || $snapshot['source_period'] === null) {
            throw new DomainException(__('hr_payroll_correction.invalid_mode'));
        }
        if ($mode === self::ModeLaterPeriod) {
            Gate::authorize('hr.payroll_approval.correct_later_period');
            $target = $this->postingPeriod((int) $run->company_id, $date);
            if ((int) $target->id === (int) $snapshot['source_period']->id
                || $target->from_date->toDateString() <= CarbonImmutable::parse($snapshot['source_period']->to_date)->toDateString()
                || $date <= CarbonImmutable::parse($snapshot['source_period']->to_date)->toDateString()) {
                throw new DomainException(__('hr_payroll_correction.later_period_required'));
            }
        } else {
            if ((bool) $snapshot['source_period']->is_closed) {
                throw new DomainException(__('hr_payroll_correction.original_period_closed'));
            }
            if ((int) $this->postingPeriod((int) $run->company_id, $date)->id !== (int) $snapshot['source_period']->id) {
                throw new DomainException(__('hr_payroll_correction.later_period_required'));
            }
        }
        $this->journals->assertNoPostedCostAllocations((int) $snapshot['journal']->id);
        foreach ($snapshot['payments'] as $payment) {
            $voidedDraft = $payment->voucher_status === 'draft' && $payment->voucher_deleted_at !== null && $payment->journal_entry_id === null;
            if ($payment->status !== 'cancelled' || ($payment->voucher_status !== 'cancelled' && ! $voidedDraft)
                || ($payment->journal_entry_id !== null && ($payment->reversal_status !== 'posted'
                    || ! $payment->reversal_is_posted || (int) $payment->payment_journal_company_id !== (int) $run->company_id
                    || $payment->payment_journal_source_type !== 'hr_payroll_payment' || (int) $payment->payment_journal_source_id !== (int) $payment->id
                    || (int) $payment->payment_journal_reversed_entry_id !== (int) $payment->reversal_journal_entry_id
                    || (int) $payment->reversal_company_id !== (int) $run->company_id
                    || $payment->reversal_source_type !== 'hr_payroll_payment_reversal' || (int) $payment->reversal_source_id !== (int) $payment->id
                    || CarbonImmutable::parse($payment->reversal_date)->toDateString() > $date))) {
                throw new DomainException(__('hr_payroll_correction.cancel_payments_first'));
            }
        }
    }

    private function postingPeriod(int $companyId, string $date): FinancialPeriod
    {
        $company = $this->companies->currentCompany();
        $periodId = request()->session()->get(OperatingContextService::FinancialPeriodIdKey);
        abort_unless($this->scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $periodId)->exists(), 403);

        return FinancialPeriod::query()->whereKey($periodId)->where('company_id', $companyId)->where('is_closed', false)
            ->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)->lockForUpdate()->first()
            ?? throw new DomainException(__('hr_payroll_correction.period_changed'));
    }

    private function date(string $date): string
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException(__('hr_payroll_correction.period_changed'));
        }

        return $date;
    }

    /** @param array<string, mixed> $snapshot */
    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $snapshot */
    private function assertFingerprint(array $snapshot, string $fingerprint): void
    {
        if (! hash_equals($this->fingerprint($snapshot), $fingerprint)) {
            throw new DomainException(__('hr_payroll_correction.stale'));
        }
    }
}
