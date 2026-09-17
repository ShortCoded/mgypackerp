<?php

namespace Modules\HR\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

final class PayrollLifecycleService
{
    public function __construct(private readonly PayrollCostAllocationService $allocations) {}

    public function submitForReview(int $payrollRunId, int $companyId): object
    {
        return DB::transaction(function () use ($payrollRunId, $companyId): object {
            $run = $this->run($payrollRunId, $companyId, lock: true);

            if ($run->status === 'under_review') {
                return $run;
            }

            if ($run->status !== 'calculated') {
                throw new DomainException(__('hr_payroll.messages.review_transition_invalid'));
            }

            DB::table('hr_payroll_runs')->where('id', $payrollRunId)->update([
                'status' => 'under_review',
                'reviewed_at' => now(),
                'reviewed_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
            DB::table('hr_payslips')->where('payroll_run_id', $payrollRunId)->update(['status' => 'under_review', 'updated_at' => now()]);
            DB::table('hr_payroll_run_employees')->where('payroll_run_id', $payrollRunId)->update(['status' => 'under_review', 'updated_at' => now()]);

            return $this->run($payrollRunId, $companyId);
        });
    }

    /** @return array{run: object, journal_entry_id: int} */
    public function approve(int $payrollRunId, int $companyId): array
    {
        return DB::transaction(function () use ($payrollRunId, $companyId): array {
            $run = $this->run($payrollRunId, $companyId, lock: true);

            if ($run->status === 'posted') {
                return [
                    'run' => $run,
                    'journal_entry_id' => (int) DB::table('hr_payroll_postings')->where('payroll_run_id', $payrollRunId)->value('journal_entry_id'),
                ];
            }

            if (! in_array($run->status, ['under_review', 'approved'], true)) {
                throw new DomainException(__('hr_payroll.messages.approval_transition_invalid'));
            }

            if ($run->status === 'under_review') {
                DB::table('hr_payroll_runs')->where('id', $payrollRunId)->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                    'updated_at' => now(),
                ]);
                DB::table('hr_payslips')->where('payroll_run_id', $payrollRunId)->update(['status' => 'approved', 'updated_at' => now()]);
                DB::table('hr_payroll_run_employees')->where('payroll_run_id', $payrollRunId)->update(['status' => 'approved', 'updated_at' => now()]);
                $this->applyAdvances($payrollRunId);
            }

            $journalEntryId = $this->allocations->postRun($payrollRunId);

            return [
                'run' => $this->run($payrollRunId, $companyId),
                'journal_entry_id' => $journalEntryId,
            ];
        }, attempts: 3);
    }

    private function applyAdvances(int $payrollRunId): void
    {
        $applications = DB::table('hr_payroll_advance_applications')
            ->where('payroll_run_id', $payrollRunId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($applications as $application) {
            if ($application->applied_at !== null) {
                continue;
            }

            $advance = DB::table('hr_salary_advances')
                ->where('id', $application->salary_advance_id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($advance === null || bccomp((string) $advance->balance, (string) $application->amount, 4) < 0) {
                throw new DomainException(__('hr_payroll.messages.advance_balance_changed'));
            }

            $remaining = bcsub((string) $advance->balance, (string) $application->amount, 4);
            DB::table('hr_salary_advances')->where('id', $advance->id)->update([
                'balance' => $remaining,
                'status' => bccomp($remaining, '0.0000', 4) === 0 ? 'settled' : 'active',
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
            DB::table('hr_payroll_advance_applications')->where('id', $application->id)->update([
                'balance_before' => $advance->balance,
                'balance_after' => $remaining,
                'applied_at' => now(),
                'applied_by' => auth()->id(),
                'updated_at' => now(),
            ]);
        }
    }

    private function run(int $payrollRunId, int $companyId, bool $lock = false): object
    {
        return DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('run.id', $payrollRunId)
            ->where('period.company_id', $companyId)
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first([
                'run.*',
                'period.company_id',
                'period.period_start',
                'period.period_end',
            ]) ?? throw new DomainException(__('hr_payroll.messages.run_not_found'));
    }
}
