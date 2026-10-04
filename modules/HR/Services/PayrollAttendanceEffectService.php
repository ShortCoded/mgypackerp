<?php

namespace Modules\HR\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrPayrollAttendancePolicy;

final class PayrollAttendanceEffectService
{
    public function __construct(private readonly HrWorkCalendarService $workCalendars) {}

    /**
     * @return array{
     *     attendance: array<string, mixed>,
     *     approved_request_ids: list<int>,
     *     canonical_leave_request_ids: list<int>,
     *     canonical_leave_sources: list<array<string, mixed>>,
     *     paid_leave_days: list<array{leave_date: string, day_fraction: string, leave_request_ids: list<int>, leave_day_ids: list<int>}>,
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
        string $payBasis = 'monthly_salary',
    ): array {
        $records = $this->attendanceRecords($employee, $start, $end);
        $calendarDays = $this->workCalendars->daysForEmployee($employee, $records->pluck('work_date')->all(), false);
        $finalized = $records->filter(fn (object $record): bool => $record->status === 'present' && $record->check_out_at !== null);
        $organizationAssignments = $this->organizationAssignments($employee, $start, $end);
        $serviceRequests = $this->approvedServiceRequests($employee, $start, $end);
        foreach ($serviceRequests->where('request_type', 'overtime') as $serviceRequest) {
            $from = max($start, $serviceRequest->requested_from->toDateString());
            $to = min($end, $serviceRequest->requested_to?->toDateString() ?? $from);
            if ($serviceRequest->branch_id !== null && (
                $this->branchForDate($employee, $organizationAssignments, $from) !== (int) $serviceRequest->branch_id
                || $this->branchForDate($employee, $organizationAssignments, $to) !== (int) $serviceRequest->branch_id
            )) {
                throw new \DomainException(__('hr_payroll.messages.overtime_approval_requires_dated_allocation', ['employee' => $employee->doc_num]));
            }
        }
        $leaveDays = $this->approvedCanonicalLeaveDays($employee, $start, $end);
        $branchIds = $organizationAssignments->pluck('branch_id')
            ->merge($records->pluck('branch_id'))
            ->push($employee->branch_id)
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $policies = $this->policies($employee, $start, $end, $branchIds);
        $deductions = [];
        $summary = [
            'absence_days' => 0.0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'paid_leave_days' => 0.0,
            'unpaid_leave_days' => 0.0,
        ];
        $leaveByDate = $leaveDays->groupBy('leave_date');
        $paidLeaveDays = [];

        foreach ($records as $record) {
            $branchId = $this->branchForDate($employee, $organizationAssignments, (string) $record->work_date, $record->branch_id);
            $policy = $this->policyForDate($policies, (string) $record->work_date, $branchId);
            $coveredByLeave = $leaveByDate->has((string) $record->work_date);

            $nonWorkingCalendarDay = in_array($calendarDays[(string) $record->work_date]['day_type'] ?? null,
                ['holiday_paid', 'holiday_unpaid', 'holiday', 'weekend', 'non_working', 'off'], true);
            if ($record->status === 'absent' && ! $coveredByLeave && ! $nonWorkingCalendarDay) {
                $summary['absence_days']++;
                if ($policy?->deduct_absence) {
                    $this->addDeduction($deductions, $policy, 'absence', $this->ruleAmount($policy, 'absence', $basicSalary, $payBasis), [
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

            $lateAmount = $lateMinutes > 0 && $policy?->deduct_late
                ? $this->ruleAmount($policy, 'late', $basicSalary, $payBasis, $lateMinutes)
                : '0.00000000';
            $earlyAmount = $earlyMinutes > 0 && $policy?->deduct_early_leave
                ? $this->ruleAmount($policy, 'early_leave', $basicSalary, $payBasis, $earlyMinutes)
                : '0.00000000';
            if (bccomp($lateAmount, '0', 8) > 0 && bccomp($earlyAmount, '0', 8) > 0) {
                switch ($policy->same_day_late_early_mode ?? 'sum') {
                    case 'higher':
                        if (bccomp($lateAmount, $earlyAmount, 8) >= 0) {
                            $earlyAmount = '0.00000000';
                        } else {
                            $lateAmount = '0.00000000';
                        }
                        break;
                    case 'late_first':
                        $earlyAmount = '0.00000000';
                        break;
                    case 'early_first':
                        $lateAmount = '0.00000000';
                        break;
                }
            }

            if ($lateMinutes > 0 && $policy?->deduct_late) {
                $this->addDeduction($deductions, $policy, 'late', $lateAmount, [
                    'attendance_record_ids' => [(int) $record->id],
                    'dates' => [(string) $record->work_date],
                    'minutes' => $lateMinutes,
                ]);
            }
            if ($earlyMinutes > 0 && $policy?->deduct_early_leave) {
                $this->addDeduction($deductions, $policy, 'early_leave', $earlyAmount, [
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
            if (bccomp($paidFraction, '0.0000', 4) > 0) {
                $paidRows = $dateLeaveDays->where('payment_status', 'paid');
                $paidLeaveDays[] = [
                    'leave_date' => (string) $date,
                    'day_fraction' => $paidFraction,
                    'leave_request_ids' => $paidRows->pluck('leave_request_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
                    'leave_day_ids' => $paidRows->pluck('leave_day_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
                ];
            }

            if (bccomp($unpaidFraction, '0.0000', 4) <= 0) {
                continue;
            }

            $branchId = $this->branchForDate($employee, $organizationAssignments, (string) $date);
            $policy = $this->policyForDate($policies, (string) $date, $branchId);
            if (! $policy?->deduct_unpaid_leave) {
                continue;
            }

            $this->addDeduction(
                $deductions,
                $policy,
                'unpaid_leave',
                $this->ruleAmount($policy, 'unpaid_leave', $basicSalary, $payBasis, 0, $unpaidFraction),
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
                'calendar_days' => array_values($calendarDays),
                'finalized_days' => $finalized->count(),
                'worked_minutes' => (int) $finalized->sum('worked_minutes'),
                'late_minutes' => (int) $finalized->sum('late_minutes'),
                'early_leave_minutes' => (int) $finalized->sum('early_leave_minutes'),
                'recorded_overtime_minutes' => (int) $finalized->sum('overtime_minutes'),
                'finalized_records' => $finalized->map(fn (object $record): array => [
                    'id' => (int) $record->id,
                    'work_date' => (string) $record->work_date,
                    'worked_minutes' => (int) $record->worked_minutes,
                    'overtime_minutes' => (int) $record->overtime_minutes,
                    'shift_id' => $record->shift_id === null ? null : (int) $record->shift_id,
                ])->values()->all(),
            ],
            'approved_request_ids' => $serviceRequests->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'canonical_leave_request_ids' => $leaveDays->pluck('leave_request_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
            'canonical_leave_sources' => $leaveDays->map(fn (object $day): array => [
                'leave_day_id' => (int) $day->leave_day_id,
                'leave_request_id' => (int) $day->leave_request_id,
                'leave_date' => (string) $day->leave_date,
                'day_fraction' => bcadd((string) $day->day_fraction, '0', 4),
                'payment_status' => (string) $day->payment_status,
                'leave_type_id' => (int) $day->leave_type_id,
            ])->sortBy('leave_day_id')->values()->all(),
            'paid_leave_days' => $paidLeaveDays,
            'policy_snapshots' => $policies->map(fn (HrPayrollAttendancePolicy $policy): array => $this->policySnapshot($policy))->values()->all(),
            'deductions' => collect($deductions)
                ->sortBy(fn (array $deduction): string => $deduction['policy_id'].':'.$deduction['effect_type'])
                ->map(function (array $deduction): array {
                    $deduction['snapshot']['unrounded_amount'] = $deduction['amount'];
                    $deduction['amount'] = $this->finalizeDeductionAmount($deduction['amount'], $deduction['snapshot']['policy'], $deduction['effect_type']);
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
            ->orderBy('work_date')
            ->get(['id', 'branch_id', 'shift_id', 'work_date', 'check_out_at', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'status']);
    }

    /** @return Collection<int, HrEmployeeServiceRequest> */
    private function approvedServiceRequests(HrEmployee $employee, string $start, string $end): Collection
    {
        return HrEmployeeServiceRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('company_id', $employee->company_id)
            ->where('status', HrEmployeeServiceRequest::StatusApproved)
            ->whereIn('request_type', ['leave', 'overtime'])
            ->whereDate('requested_from', '<=', $end)
            ->where(fn ($query) => $query->whereDate('requested_to', '>=', $start)
                ->orWhere(fn ($singleDay) => $singleDay->whereNull('requested_to')->whereDate('requested_from', '>=', $start)))
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
    /** @param list<int> $branchIds */
    private function policies(HrEmployee $employee, string $start, string $end, array $branchIds): Collection
    {
        return HrPayrollAttendancePolicy::query()
            ->where('company_id', $employee->company_id)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, object> */
    private function organizationAssignments(HrEmployee $employee, string $start, string $end): Collection
    {
        return DB::table('hr_employee_organization_assignments')
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->orderBy('effective_from')
            ->get(['id', 'branch_id', 'effective_from', 'effective_to']);
    }

    private function branchForDate(HrEmployee $employee, Collection $assignments, string $date, mixed $recordBranchId = null): ?int
    {
        if ($assignments->isEmpty()) {
            return $recordBranchId === null ? $employee->branch_id : (int) $recordBranchId;
        }

        $assignment = $assignments->first(fn (object $candidate): bool => (string) $candidate->effective_from <= $date
            && ($candidate->effective_to === null || (string) $candidate->effective_to >= $date));
        if ($assignment === null) {
            throw new \DomainException(__('hr_payroll.messages.organization_assignment_gap', ['employee' => $employee->doc_num]));
        }
        if ($recordBranchId !== null && (int) $recordBranchId !== (int) $assignment->branch_id) {
            throw new \DomainException(__('hr_payroll.messages.attendance_branch_mismatch', ['employee' => $employee->doc_num, 'date' => $date]));
        }

        return (int) $assignment->branch_id;
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
            'monthly_partial_method' => $policy->monthly_partial_method,
            'weekly_accrual_method' => $policy->weekly_accrual_method,
            'weekly_work_days' => $policy->weekly_work_days,
            'daily_accrual_method' => $policy->daily_accrual_method,
            'hourly_accrual_method' => $policy->hourly_accrual_method,
            'hourly_rounding_mode' => $policy->hourly_rounding_mode,
            'hourly_rounding_increment_minutes' => $policy->hourly_rounding_increment_minutes,
            'shift_accrual_method' => $policy->shift_accrual_method,
            'piece_accrual_method' => $policy->piece_accrual_method,
            'salary_day_divisor' => $policy->salary_day_divisor,
            'standard_day_minutes' => $policy->standard_day_minutes,
            'deduction_payroll_item_code' => $policy->deduction_payroll_item_code,
            'deduction_rules' => $policy->deduction_rules,
            'same_day_late_early_mode' => $policy->same_day_late_early_mode ?? 'sum',
            'deduction_rounding_mode' => $policy->deduction_rounding_mode ?? 'half_up',
        ];
    }

    private function ruleAmount(
        HrPayrollAttendancePolicy $policy,
        string $effectType,
        mixed $basicSalary,
        string $payBasis,
        int $minutes = 0,
        string $dayFraction = '1.0000',
    ): string {
        $rule = $policy->deduction_rules[$effectType] ?? [];
        $method = $rule['method'] ?? 'salary_time';
        $timeAmount = in_array($effectType, ['late', 'early_leave'], true)
            ? $this->minuteAmount($basicSalary, $policy, $minutes, $payBasis)
            : bcmul($this->dayAmount($basicSalary, $policy, $payBasis), $dayFraction, 8);

        return match ($method) {
            'fixed' => bcmul((string) ($rule['value'] ?? '0'), $dayFraction, 8),
            'percentage' => bcmul($timeAmount, bcdiv((string) ($rule['value'] ?? '0'), '100', 12), 8),
            'tiered' => $this->tierAmount($rule['tiers'] ?? [], $minutes),
            default => $timeAmount,
        };
    }

    /** @param list<array<string, mixed>> $tiers */
    private function tierAmount(array $tiers, int $minutes): string
    {
        foreach ($tiers as $tier) {
            if (! isset($tier['up_to']) || $minutes <= (int) $tier['up_to']) {
                return bcadd((string) ($tier['amount'] ?? '0'), '0', 8);
            }
        }

        return '0.00000000';
    }

    private function dayAmount(mixed $basicSalary, HrPayrollAttendancePolicy $policy, string $payBasis): string
    {
        return match ($payBasis) {
            'monthly_salary' => bcdiv($this->money($basicSalary), (string) $policy->salary_day_divisor, 8),
            'weekly_wage' => $this->weeklyDayAmount($basicSalary, $policy),
            'daily_wage', 'shift_wage' => $this->money($basicSalary),
            'hourly_wage' => bcdiv(bcmul($this->money($basicSalary), (string) $policy->standard_day_minutes, 8), '60', 8),
            default => '0.00000000',
        };
    }

    private function weeklyDayAmount(mixed $rate, HrPayrollAttendancePolicy $policy): string
    {
        $divisor = $policy->weekly_accrual_method === HrPayrollAttendancePolicy::WeeklyCalendarDays
            ? 7 : (int) $policy->weekly_work_days;
        if ($divisor < 1 || $divisor > 7) {
            throw new \DomainException(__('hr_payroll_policies.validation.weekly_work_days_required'));
        }

        return bcdiv($this->money($rate), (string) $divisor, 8);
    }

    private function minuteAmount(mixed $basicSalary, HrPayrollAttendancePolicy $policy, int $minutes, string $payBasis): string
    {
        $dayAmount = $this->dayAmount($basicSalary, $policy, $payBasis);
        if (bccomp($dayAmount, '0.00000000', 8) === 0) {
            return '0.00000000';
        }

        return bcmul(
            bcdiv(
                $dayAmount,
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

    /** @param array<string, mixed> $policySnapshot */
    public function finalizeDeductionAmount(mixed $value, array $policySnapshot, string $effectType): string
    {
        $rule = $policySnapshot['deduction_rules'][$effectType] ?? [];
        $amount = bcadd((string) $value, '0', 8);
        if (isset($rule['cap']) && $rule['cap'] !== '' && bccomp($amount, (string) $rule['cap'], 8) > 0) {
            $amount = bcadd((string) $rule['cap'], '0', 8);
        }

        $mode = $policySnapshot['deduction_rounding_mode'] ?? 'half_up';
        if ($mode === 'half_up') {
            return $this->currencyMoney($amount);
        }

        $truncated = bcadd($amount, '0', 2);
        if ($mode === 'up' && bccomp($amount, $truncated, 8) > 0) {
            $truncated = bcadd($truncated, '0.01', 2);
        }

        return bcadd($truncated, '0', 4);
    }
}
