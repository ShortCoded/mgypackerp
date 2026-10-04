<?php

namespace Modules\HR\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Production\Models\ProductionRun;

final class PayrollLifecycleService
{
    public function __construct(private readonly PayrollCostAllocationService $allocations) {}

    public function submitForReview(int $payrollRunId, int $companyId): object
    {
        return DB::transaction(function () use ($payrollRunId, $companyId): object {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $run = $this->run($payrollRunId, $companyId, lock: true);
            $this->assertPieceEvidenceCurrent($run);
            $this->assertWageEvidenceCurrent($run);
            $this->assertLeaveEvidenceCurrent($run);

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

    public function returnForRecalculation(int $payrollRunId, int $companyId): object
    {
        return DB::transaction(function () use ($payrollRunId, $companyId): object {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $run = $this->run($payrollRunId, $companyId, lock: true);

            if ($run->status !== 'under_review') {
                throw new DomainException(__('hr_payroll.messages.return_for_recalculation_invalid'));
            }

            DB::table('hr_payroll_runs')->where('id', $payrollRunId)->update([
                'status' => 'calculated',
                'reviewed_at' => null,
                'reviewed_by' => null,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
            DB::table('hr_payslips')->where('payroll_run_id', $payrollRunId)->update(['status' => 'calculated', 'updated_at' => now()]);
            DB::table('hr_payroll_run_employees')->where('payroll_run_id', $payrollRunId)->update(['status' => 'calculated', 'updated_at' => now()]);

            return $this->run($payrollRunId, $companyId);
        });
    }

    /** @return array{run: object, journal_entry_id: int} */
    public function approve(int $payrollRunId, int $companyId): array
    {
        return DB::transaction(function () use ($payrollRunId, $companyId): array {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
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

            $this->assertPieceEvidenceCurrent($run);
            $this->assertWageEvidenceCurrent($run);
            $this->assertLeaveEvidenceCurrent($run);

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

    private function assertWageEvidenceCurrent(object $payrollRun): void
    {
        $items = DB::table('hr_payslip_items as item')
            ->join('hr_payslips as slip', 'slip.id', '=', 'item.payslip_id')
            ->join('hr_payroll_items as kind', 'kind.id', '=', 'item.payroll_item_id')
            ->where('slip.payroll_run_id', $payrollRun->id)
            ->where('kind.code', 'BASIC')
            ->get(['slip.employee_id', 'item.source_type', 'item.source_id', 'item.source_snapshot']);
        if ($items->isEmpty()) {
            return;
        }

        $employeeIds = $items->pluck('employee_id')->unique()->values()->all();
        $employees = DB::table('hr_employees')->whereIn('id', $employeeIds)
            ->get(['id', 'pay_basis', 'basic_salary', 'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate'])
            ->keyBy('id');
        $assignments = DB::table('hr_employee_salary_assignments')
            ->whereIn('employee_id', $employeeIds)
            ->where('effective_from', '<=', $payrollRun->period_end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $payrollRun->period_start))
            ->whereNull('deleted_at')
            ->get(['id', 'employee_id', 'effective_from', 'effective_to', 'pay_basis', 'basic_salary', 'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate'])
            ->groupBy('employee_id');

        foreach ($items as $item) {
            $snapshot = json_decode((string) $item->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $employee = $employees->get($item->employee_id);
            $basis = $snapshot['pay_basis'] ?? null;
            $from = $snapshot['segment_from'] ?? null;
            $to = $snapshot['segment_to'] ?? null;
            $rateField = match ($basis) {
                'monthly_salary' => 'basic_salary',
                'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate' => $basis,
                default => null,
            };
            if ($employee === null || $rateField === null
                || ! is_string($from) || ! is_string($to) || $from > $to) {
                throw new DomainException(__('hr_payroll.messages.wage_evidence_changed'));
            }

            $covering = ($assignments->get($item->employee_id) ?? collect())
                ->filter(fn (object $assignment): bool => $assignment->effective_from <= $to
                    && ($assignment->effective_to === null || $assignment->effective_to >= $from))->values();
            if ($item->source_type === 'employee_master') {
                $currentRate = $employee->{$rateField};
                if ($covering->isNotEmpty() || (int) $item->source_id !== (int) $employee->id
                    || $basis !== $employee->pay_basis) {
                    throw new DomainException(__('hr_payroll.messages.wage_evidence_changed'));
                }
            } elseif ($item->source_type === 'salary_assignment') {
                if ($covering->count() !== 1 || (int) $covering->first()->id !== (int) $item->source_id) {
                    throw new DomainException(__('hr_payroll.messages.wage_evidence_changed'));
                }
                $assignment = $covering->first();
                if ((string) $assignment->effective_from !== (string) ($snapshot['effective_from'] ?? '')
                    || $assignment->effective_to !== ($snapshot['effective_to'] ?? null)
                    || ($assignment->pay_basis !== null && $assignment->pay_basis !== $basis)
                    || ($assignment->pay_basis === null && $basis !== $employee->pay_basis)) {
                    throw new DomainException(__('hr_payroll.messages.wage_evidence_changed'));
                }
                $currentRate = $assignment->pay_basis === null && $basis !== 'monthly_salary'
                    ? $employee->{$rateField}
                    : $assignment->{$rateField};
            } else {
                throw new DomainException(__('hr_payroll.messages.wage_evidence_changed'));
            }

            if ($currentRate === null || bccomp((string) $currentRate, (string) ($snapshot['base_rate'] ?? '0'), 4) !== 0) {
                throw new DomainException(__('hr_payroll.messages.wage_evidence_changed'));
            }
        }
    }

    private function assertLeaveEvidenceCurrent(object $payrollRun): void
    {
        $inputs = DB::table('hr_payroll_attendance_inputs')
            ->where('payroll_run_id', $payrollRun->id)
            ->get(['employee_id', 'payload']);
        $slipEmployeeIds = DB::table('hr_payslips')
            ->where('payroll_run_id', $payrollRun->id)
            ->pluck('employee_id')
            ->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
        $inputEmployeeIds = $inputs->pluck('employee_id')
            ->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

        if ($slipEmployeeIds !== $inputEmployeeIds) {
            throw new DomainException(__('hr_payroll.messages.leave_evidence_changed'));
        }

        $current = DB::table('hr_leave_request_days as day')
            ->join('hr_leave_requests as leave', 'leave.id', '=', 'day.leave_request_id')
            ->whereIn('leave.employee_id', $inputEmployeeIds)
            ->where('leave.status', 'approved')
            ->whereNull('leave.deleted_at')
            ->whereBetween('day.leave_date', [$payrollRun->period_start, $payrollRun->period_end])
            ->orderBy('day.id')
            ->get([
                'leave.employee_id',
                'day.id as leave_day_id',
                'day.leave_request_id',
                'day.leave_date',
                'day.day_fraction',
                'leave.payment_status',
                'leave.leave_type_id',
            ])
            ->groupBy('employee_id');

        foreach ($inputs as $input) {
            $snapshot = json_decode((string) $input->payload, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot) || ! isset($snapshot['canonical_leave_sources'])) {
                throw new DomainException(__('hr_payroll.messages.leave_evidence_changed'));
            }

            $sources = ($current->get($input->employee_id) ?? collect())
                ->map(fn (object $day): array => [
                    'leave_day_id' => (int) $day->leave_day_id,
                    'leave_request_id' => (int) $day->leave_request_id,
                    'leave_date' => (string) $day->leave_date,
                    'day_fraction' => bcadd((string) $day->day_fraction, '0', 4),
                    'payment_status' => (string) $day->payment_status,
                    'leave_type_id' => (int) $day->leave_type_id,
                ])->values()->all();
            $captured = collect($snapshot['canonical_leave_sources'])
                ->sortBy('leave_day_id')->values()->all();

            if ($captured !== $sources) {
                throw new DomainException(__('hr_payroll.messages.leave_evidence_changed'));
            }
        }
    }

    private function assertPieceEvidenceCurrent(object $payrollRun): void
    {
        $current = DB::table('production_piece_approvals as approval')
            ->join('production_runs as run', 'run.id', '=', 'approval.production_run_id')
            ->whereNull('approval.revoked_at')
            ->whereColumn('approval.correction_sequence', 'run.correction_sequence')
            ->where('approval.company_id', $payrollRun->company_id)
            ->when($payrollRun->branch_id !== null, fn ($query) => $query->where('approval.branch_id', $payrollRun->branch_id))
            ->where('run.status', ProductionRun::StatusCompleted)
            ->whereNull('run.deleted_at')
            ->whereBetween('run.actual_end_at', [
                $payrollRun->period_start.' 00:00:00',
                $payrollRun->period_end.' 23:59:59',
            ])
            ->orderBy('approval.id')
            ->sharedLock()
            ->get(['approval.id', 'approval.employee_id', 'approval.quantity', 'approval.rate'])
            ->keyBy('id');

        $items = DB::table('hr_payslip_items as item')
            ->join('hr_payslips as slip', 'slip.id', '=', 'item.payslip_id')
            ->join('hr_payroll_items as kind', 'kind.id', '=', 'item.payroll_item_id')
            ->join('hr_employees as employee', 'employee.id', '=', 'slip.employee_id')
            ->where('slip.payroll_run_id', $payrollRun->id)
            ->where('kind.code', 'BASIC')
            ->get(['item.source_type', 'item.source_snapshot', 'slip.employee_id', 'employee.pay_basis']);

        $snapshotIds = [];
        foreach ($items as $item) {
            $snapshot = json_decode((string) $item->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (($snapshot['pay_basis'] ?? null) !== 'piece_rate') {
                continue;
            }

            if ($item->source_type === 'employee_master' && $item->pay_basis !== 'piece_rate') {
                throw new DomainException(__('hr_payroll.messages.piece_basis_changed', ['employee' => $item->employee_id]));
            }

            foreach (data_get($snapshot, 'accrual.evidence.sources', []) as $source) {
                $id = (int) ($source['piece_approval_id'] ?? 0);
                $approved = $current->get($id);
                if ($id <= 0 || $approved === null
                    || (int) $approved->employee_id !== (int) $item->employee_id
                    || bccomp((string) $approved->quantity, (string) ($source['quantity'] ?? '0'), 8) !== 0
                    || bccomp((string) $approved->rate, (string) ($source['rate'] ?? '0'), 4) !== 0) {
                    throw new DomainException(__('hr_payroll.messages.piece_evidence_changed'));
                }
                $snapshotIds[] = $id;
            }
        }

        $currentIds = $current->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all();
        sort($snapshotIds);
        if ($snapshotIds !== $currentIds) {
            throw new DomainException(__('hr_payroll.messages.piece_evidence_changed'));
        }
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
