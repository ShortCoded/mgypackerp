<?php

namespace Modules\HR\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrPayrollAttendancePolicy;

final class PayrollAttendanceEffectService
{
    /**
     * @return array{
     *     attendance: array<string, mixed>,
     *     approved_request_ids: list<int>,
     *     canonical_leave_request_ids: list<int>,
     *     policy_snapshots: list<array<string, mixed>>,
     *     deductions: list<array{payroll_item_code: string, amount: string, policy_id: int, effect_type: string, snapshot: array<string, mixed>}>,
     *     overtime: array{minutes: int, amount: string, hourly_rate: string, request_ids: list<int>},
     *     summary: array<string, mixed>
     * }
     */
    public function calculate(
        HrEmployee $employee,
        string $start,
        string $end,
        mixed $basicSalary,
        mixed $overtimeHourlyRate,
    ): array {
        $records = $this->attendanceRecords($employee, $start, $end);
        $finalized = $records->filter(fn (object $record): bool => $record->status === 'present' && $record->check_out_at !== null);
        $serviceRequests = $this->approvedServiceRequests($employee, $start, $end);
        $leaveDays = $this->approvedCanonicalLeaveDays($employee, $start, $end);
        $policies = $this->policies($employee, $start, $end);
        $deductions = [];
        $summary = [
            'absence_days' => 0.0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'paid_leave_days' => 0.0,
            'unpaid_leave_days' => 0.0,
        ];
        $leaveByDate = $leaveDays->groupBy('leave_date');

        foreach ($records as $record) {
            $policy = $this->policyForDate($policies, (string) $record->work_date, $employee->branch_id);
            $coveredByLeave = $leaveByDate->has((string) $record->work_date);

            if ($record->status === 'absent' && ! $coveredByLeave) {
                $summary['absence_days']++;
                if ($policy?->deduct_absence) {
                    $this->addDeduction($deductions, $policy, 'absence', $this->dayAmount($basicSalary, $policy), [
                        'attendance_record_ids' => [(int) $record->id],
                        'dates' => [(string) $record->work_date],
                        'units' => 1,
                    ]);
                }
            }

            if ($record->status !== 'present') {
                continue;
            }

            $lateMinutes = max(0, (int) $record->late_minutes);
            $earlyMinutes = max(0, (int) $record->early_leave_minutes);
            $summary['late_minutes'] += $lateMinutes;
            $summary['early_leave_minutes'] += $earlyMinutes;

            if ($lateMinutes > 0 && $policy?->deduct_late) {
                $this->addDeduction($deductions, $policy, 'late', $this->minuteAmount($basicSalary, $policy, $lateMinutes), [
                    'attendance_record_ids' => [(int) $record->id],
                    'dates' => [(string) $record->work_date],
                    'minutes' => $lateMinutes,
                ]);
            }
            if ($earlyMinutes > 0 && $policy?->deduct_early_leave) {
                $this->addDeduction($deductions, $policy, 'early_leave', $this->minuteAmount($basicSalary, $policy, $earlyMinutes), [
                    'attendance_record_ids' => [(int) $record->id],
                    'dates' => [(string) $record->work_date],
                    'minutes' => $earlyMinutes,
                ]);
            }
        }

        foreach ($leaveByDate as $date => $dateLeaveDays) {
            $paidFraction = $this->cappedLeaveFraction($dateLeaveDays, 'paid');
            $unpaidFraction = $this->cappedLeaveFraction($dateLeaveDays, 'unpaid');
            $summary['paid_leave_days'] += (float) $paidFraction;
            $summary['unpaid_leave_days'] += (float) $unpaidFraction;

            if (bccomp($unpaidFraction, '0.0000', 4) <= 0) {
                continue;
            }

            $policy = $this->policyForDate($policies, (string) $date, $employee->branch_id);
            if (! $policy?->deduct_unpaid_leave) {
                continue;
            }

            $this->addDeduction(
                $deductions,
                $policy,
                'unpaid_leave',
                bcmul($this->dayAmount($basicSalary, $policy), $unpaidFraction, 8),
                [
                    'leave_request_ids' => $dateLeaveDays->pluck('leave_request_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
                    'leave_day_ids' => $dateLeaveDays->pluck('leave_day_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
                    'dates' => [(string) $date],
                    'day_fraction' => (float) $unpaidFraction,
                ],
            );
        }

        $approvedOvertime = $serviceRequests->where('request_type', 'overtime');
        $overtimeMinutes = min(
            (int) $finalized->sum('overtime_minutes'),
            (int) $approvedOvertime->sum('requested_minutes'),
        );
        $hourlyRate = $this->money($overtimeHourlyRate);
        $overtimeAmount = $overtimeMinutes > 0
            ? bcmul(bcdiv((string) $overtimeMinutes, '60', 8), $hourlyRate, 4)
            : '0.0000';

        return [
            'attendance' => [
                'record_ids' => $finalized->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                'effect_record_ids' => $records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                'finalized_days' => $finalized->count(),
                'worked_minutes' => (int) $finalized->sum('worked_minutes'),
                'late_minutes' => (int) $finalized->sum('late_minutes'),
                'early_leave_minutes' => (int) $finalized->sum('early_leave_minutes'),
                'recorded_overtime_minutes' => (int) $finalized->sum('overtime_minutes'),
            ],
            'approved_request_ids' => $serviceRequests->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'canonical_leave_request_ids' => $leaveDays->pluck('leave_request_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
            'policy_snapshots' => $policies->map(fn (HrPayrollAttendancePolicy $policy): array => $this->policySnapshot($policy))->values()->all(),
            'deductions' => collect($deductions)
                ->sortBy(fn (array $deduction): string => $deduction['policy_id'].':'.$deduction['effect_type'])
                ->map(function (array $deduction): array {
                    $deduction['amount'] = $this->currencyMoney($deduction['amount']);
                    $deduction['snapshot']['amount'] = $deduction['amount'];

                    return $deduction;
                })
                ->values()
                ->all(),
            'overtime' => [
                'minutes' => $overtimeMinutes,
                'amount' => $overtimeAmount,
                'hourly_rate' => $hourlyRate,
                'request_ids' => $approvedOvertime->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            ],
            'summary' => $summary,
        ];
    }

    /** @return Collection<int, object> */
    private function attendanceRecords(HrEmployee $employee, string $start, string $end): Collection
    {
        return DB::table('hr_attendance_daily_records')
            ->where('employee_id', $employee->getKey())
            ->whereBetween('work_date', [$start, $end])
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $employee->company_id))
            ->when($employee->branch_id !== null, fn ($query) => $query->where(fn ($branch) => $branch->whereNull('branch_id')->orWhere('branch_id', $employee->branch_id)))
            ->orderBy('work_date')
            ->get(['id', 'work_date', 'check_out_at', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'status']);
    }

    /** @return Collection<int, HrEmployeeServiceRequest> */
    private function approvedServiceRequests(HrEmployee $employee, string $start, string $end): Collection
    {
        return HrEmployeeServiceRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('company_id', $employee->company_id)
            ->when($employee->branch_id !== null, fn ($query) => $query->where('branch_id', $employee->branch_id))
            ->where('status', HrEmployeeServiceRequest::StatusApproved)
            ->whereIn('request_type', ['leave', 'overtime'])
            ->whereDate('requested_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('requested_to')->orWhereDate('requested_to', '>=', $start))
            ->get();
    }

    /** @return Collection<int, object> */
    private function approvedCanonicalLeaveDays(HrEmployee $employee, string $start, string $end): Collection
    {
        return DB::table('hr_leave_request_days as day')
            ->join('hr_leave_requests as leave', 'leave.id', '=', 'day.leave_request_id')
            ->where('leave.employee_id', $employee->getKey())
            ->where('leave.status', 'approved')
            ->whereNull('leave.deleted_at')
            ->whereBetween('day.leave_date', [$start, $end])
            ->orderBy('day.leave_date')
            ->get([
                'day.id as leave_day_id',
                'day.leave_request_id',
                'day.leave_date',
                'day.day_fraction',
                'leave.payment_status',
                'leave.leave_type_id',
            ]);
    }

    /** @return Collection<int, HrPayrollAttendancePolicy> */
    private function policies(HrEmployee $employee, string $start, string $end): Collection
    {
        return HrPayrollAttendancePolicy::query()
            ->where('company_id', $employee->company_id)
            ->where(fn ($query) => $query->whereNull('branch_id')->when($employee->branch_id !== null, fn ($branch) => $branch->orWhere('branch_id', $employee->branch_id)))
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
    }

    private function policyForDate(Collection $policies, string $date, mixed $branchId): ?HrPayrollAttendancePolicy
    {
        $effective = $policies->filter(fn (HrPayrollAttendancePolicy $policy): bool => $policy->effective_from->toDateString() <= $date
            && ($policy->effective_to === null || $policy->effective_to->toDateString() >= $date));

        $latestFirst = $effective->sortByDesc(fn (HrPayrollAttendancePolicy $policy): string => $policy->effective_from->format('Y-m-d').':'.str_pad((string) $policy->getKey(), 20, '0', STR_PAD_LEFT));

        return $latestFirst->first(fn (HrPayrollAttendancePolicy $policy): bool => $branchId !== null && (int) $policy->branch_id === (int) $branchId)
            ?? $latestFirst->first(fn (HrPayrollAttendancePolicy $policy): bool => $policy->branch_id === null);
    }

    /** @param array<string, array<string, mixed>> $deductions @param array<string, mixed> $evidence */
    private function addDeduction(
        array &$deductions,
        HrPayrollAttendancePolicy $policy,
        string $effectType,
        string $amount,
        array $evidence,
    ): void {
        if (bccomp($amount, '0.00000000', 8) <= 0) {
            return;
        }

        $key = $policy->getKey().':'.$effectType;
        if (! isset($deductions[$key])) {
            $deductions[$key] = [
                'payroll_item_code' => (string) $policy->deduction_payroll_item_code,
                'amount' => '0.00000000',
                'policy_id' => (int) $policy->getKey(),
                'effect_type' => $effectType,
                'snapshot' => [
                    'effect_type' => $effectType,
                    'policy' => $this->policySnapshot($policy),
                    'attendance_record_ids' => [],
                    'leave_request_ids' => [],
                    'leave_day_ids' => [],
                    'dates' => [],
                    'minutes' => 0,
                    'units' => 0,
                    'day_fraction' => 0,
                ],
            ];
        }

        $deductions[$key]['amount'] = bcadd($deductions[$key]['amount'], $amount, 8);
        foreach (['attendance_record_ids', 'leave_request_ids', 'leave_day_ids', 'dates'] as $listKey) {
            $deductions[$key]['snapshot'][$listKey] = array_values(array_unique([
                ...$deductions[$key]['snapshot'][$listKey],
                ...($evidence[$listKey] ?? []),
            ]));
        }
        foreach (['minutes', 'units', 'day_fraction'] as $numericKey) {
            $deductions[$key]['snapshot'][$numericKey] += $evidence[$numericKey] ?? 0;
        }
        $deductions[$key]['snapshot']['amount'] = $deductions[$key]['amount'];
    }

    /** @return array<string, mixed> */
    private function policySnapshot(HrPayrollAttendancePolicy $policy): array
    {
        return [
            'id' => (int) $policy->getKey(),
            'company_id' => (int) $policy->company_id,
            'branch_id' => $policy->branch_id === null ? null : (int) $policy->branch_id,
            'effective_from' => $policy->effective_from->toDateString(),
            'effective_to' => $policy->effective_to?->toDateString(),
            'deduct_absence' => $policy->deduct_absence,
            'deduct_late' => $policy->deduct_late,
            'deduct_early_leave' => $policy->deduct_early_leave,
            'deduct_unpaid_leave' => $policy->deduct_unpaid_leave,
            'salary_day_divisor' => $policy->salary_day_divisor,
            'standard_day_minutes' => $policy->standard_day_minutes,
            'deduction_payroll_item_code' => $policy->deduction_payroll_item_code,
        ];
    }

    private function dayAmount(mixed $basicSalary, HrPayrollAttendancePolicy $policy): string
    {
        return bcdiv($this->money($basicSalary), (string) $policy->salary_day_divisor, 8);
    }

    private function minuteAmount(mixed $basicSalary, HrPayrollAttendancePolicy $policy, int $minutes): string
    {
        return bcmul(
            bcdiv(
                bcdiv($this->money($basicSalary), (string) $policy->salary_day_divisor, 12),
                (string) $policy->standard_day_minutes,
                12,
            ),
            (string) $minutes,
            8,
        );
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }

    /** @param Collection<int, object> $leaveDays */
    private function cappedLeaveFraction(Collection $leaveDays, string $paymentStatus): string
    {
        $fraction = $leaveDays
            ->where('payment_status', $paymentStatus)
            ->reduce(
                fn (string $total, object $day): string => bcadd($total, (string) $day->day_fraction, 4),
                '0.0000',
            );

        return bccomp($fraction, '1.0000', 4) > 0 ? '1.0000' : $fraction;
    }

    private function currencyMoney(mixed $value): string
    {
        $normalized = bcadd((string) $value, '0', 8);
        $roundingIncrement = str_starts_with($normalized, '-') ? '-0.005' : '0.005';
        $roundedToCents = bcadd(bcadd($normalized, $roundingIncrement, 3), '0', 2);

        return bcadd($roundedToCents, '0', 4);
    }
}
