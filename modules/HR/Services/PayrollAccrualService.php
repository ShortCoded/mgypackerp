<?php

namespace Modules\HR\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\Production\Models\ProductionRun;

final class PayrollAccrualService
{
    public function __construct(private readonly HrWorkCalendarService $workCalendars) {}

    /**
     * @param  array<string, mixed>  $attendanceEffects
     * @return array<string, mixed>
     */
    public function calculate(
        HrEmployee $employee,
        object $assignment,
        string $periodStart,
        string $periodEnd,
        array $attendanceEffects,
    ): array {
        $basis = (string) $employee->pay_basis;
        if ($basis !== 'piece_rate' && $this->hasPieceApprovals($employee, $periodStart, $periodEnd)) {
            throw new DomainException(__('hr_payroll.messages.piece_basis_changed', ['employee' => $employee->doc_num]));
        }

        $rate = $this->rate($employee, $assignment, $basis);
        $activeStart = $this->activeStart($employee, $assignment, $basis, $periodStart);
        $activeEnd = $this->activeEnd($employee, $assignment, $basis, $periodEnd);

        if ($activeStart->greaterThan($activeEnd)) {
            throw new DomainException(__('hr_payroll.messages.accrual_period_invalid', ['employee' => $employee->doc_num]));
        }

        $periodFirst = CarbonImmutable::parse($periodStart);
        $periodLast = CarbonImmutable::parse($periodEnd);
        if ($basis === 'monthly_salary'
            && $periodFirst->isStartOfMonth()
            && $periodLast->isEndOfMonth()
            && $periodFirst->isSameMonth($periodLast)
            && $activeStart->toDateString() === $periodStart
            && $activeEnd->toDateString() === $periodEnd) {
            return $this->result($basis, 'full_period', $rate, $rate, '1.00000000', [], [
                'active_from' => $periodStart,
                'active_to' => $periodEnd,
                'earning_by_date' => $this->distributeByDate($rate, collect(CarbonPeriod::create($periodFirst, $periodLast))
                    ->mapWithKeys(fn ($date): array => [$date->toDateString() => '1'])->all()),
            ]);
        }

        $policies = $this->policies($employee, $activeStart->toDateString(), $activeEnd->toDateString());
        $policyByDate = $this->policyByDate($employee, $basis, $activeStart, $activeEnd, $policies);

        return match ($basis) {
            'monthly_salary' => $this->monthly($rate, $periodStart, $periodEnd, $activeStart, $activeEnd, $policyByDate),
            'weekly_wage' => $this->weekly($employee, $rate, $activeStart, $activeEnd, $policyByDate, $attendanceEffects),
            'daily_wage' => $this->daily($employee, $rate, $activeStart, $activeEnd, $policyByDate, $attendanceEffects),
            'hourly_wage' => $this->hourly($rate, $activeStart, $activeEnd, $policyByDate, $attendanceEffects),
            'shift_wage' => $this->shift($employee, $rate, $activeStart, $activeEnd, $policyByDate, $attendanceEffects),
            'piece_rate' => $this->piece($employee, $rate, $activeStart, $activeEnd, $policyByDate),
            default => throw new DomainException(__('hr_payroll.messages.unsupported_pay_basis', [
                'employee' => $employee->doc_num,
                'pay_basis' => $basis,
            ])),
        };
    }

    public function rate(HrEmployee $employee, object $assignment, string $basis): string
    {
        $recordedBasis = $assignment->pay_basis ?? null;
        if ($recordedBasis !== null && $recordedBasis !== $basis) {
            throw new DomainException(__('hr_payroll.messages.wage_version_basis_mismatch', [
                'employee' => $employee->doc_num,
            ]));
        }

        $value = match ($basis) {
            'monthly_salary' => $assignment->basic_salary,
            'weekly_wage' => $recordedBasis === null ? $employee->weekly_wage : ($assignment->weekly_wage ?? null),
            'daily_wage' => $recordedBasis === null ? $employee->daily_wage : ($assignment->daily_wage ?? null),
            'hourly_wage' => $recordedBasis === null ? $employee->hourly_wage : ($assignment->hourly_wage ?? null),
            'shift_wage' => $recordedBasis === null ? $employee->shift_wage : ($assignment->shift_wage ?? null),
            'piece_rate' => $recordedBasis === null ? $employee->piece_rate : ($assignment->piece_rate ?? null),
            default => null,
        };

        if ($value === null || bccomp((string) $value, '0', 4) <= 0) {
            throw new DomainException(__('hr_payroll.messages.accrual_rate_required', [
                'employee' => $employee->doc_num,
                'pay_basis' => $basis,
            ]));
        }

        return bcadd((string) $value, '0', 4);
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate */
    private function monthly(
        string $rate,
        string $periodStart,
        string $periodEnd,
        CarbonImmutable $activeStart,
        CarbonImmutable $activeEnd,
        array $policyByDate,
    ): array {
        $periodDays = CarbonImmutable::parse($periodStart)->diffInDays(CarbonImmutable::parse($periodEnd)) + 1;
        $amount = '0.00000000';
        $methods = [];
        $dailyWeights = [];

        foreach (CarbonPeriod::create($activeStart, $activeEnd) as $date) {
            $policy = $policyByDate[$date->toDateString()];
            $methods[] = $policy->monthly_partial_method;
            $dailyAmount = $policy->monthly_partial_method === HrPayrollAttendancePolicy::MonthlyCalendarDays
                ? bcdiv($rate, (string) $date->daysInMonth, 12)
                : bcdiv($rate, (string) $policy->salary_day_divisor, 12);
            $amount = bcadd($amount, $dailyAmount, 12);
            $dailyWeights[$date->toDateString()] = $dailyAmount;
        }

        $amount = $this->currencyMoney($amount);

        return $this->result('monthly_salary', implode('+', array_values(array_unique($methods))), $rate, $amount, bcdiv($amount, $rate, 8), $policyByDate, [
            'active_from' => $activeStart->toDateString(),
            'active_to' => $activeEnd->toDateString(),
            'accrued_days' => $activeStart->diffInDays($activeEnd) + 1,
            'period_days' => $periodDays,
            'earning_by_date' => $this->distributeByDate($amount, $dailyWeights),
        ]);
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate @param array<string, mixed> $effects */
    private function weekly(HrEmployee $employee, string $rate, CarbonImmutable $activeStart, CarbonImmutable $activeEnd, array $policyByDate, array $effects): array
    {
        $attendance = $this->evidenceRecords($effects, $activeStart, $activeEnd)
            ->unique('work_date')->keyBy('work_date');
        $paidLeave = $this->paidLeaveEvidence($effects, $activeStart, $activeEnd);
        $calendarDays = $this->scheduledCalendarDays($employee, $policyByDate, [
            HrPayrollAttendancePolicy::WeeklyScheduledWork,
            HrPayrollAttendancePolicy::WeeklyScheduledWorkAndPaidHoliday,
        ], 'weekly_accrual_method');
        $amount = '0.000000000000';
        $units = '0.0000';
        $dailyWeights = [];
        $attendanceIds = [];
        $paidLeaveRequestIds = [];
        $paidLeaveDayIds = [];
        $usedPaidLeave = [];
        $methods = [];
        $workDays = [];

        foreach (CarbonPeriod::create($activeStart, $activeEnd) as $date) {
            $workDate = $date->toDateString();
            $policy = $policyByDate[$workDate];
            $method = $policy->weekly_accrual_method;
            $methods[] = $method;
            $dayUnits = '1.0000';
            if ($method === HrPayrollAttendancePolicy::WeeklyCalendarDays) {
                $dailyAmount = bcdiv($rate, '7', 12);
            } elseif (in_array($method, [
                HrPayrollAttendancePolicy::WeeklyScheduledWork,
                HrPayrollAttendancePolicy::WeeklyScheduledWorkAndPaidHoliday,
            ], true)) {
                $divisor = (int) $policy->weekly_work_days;
                if ($divisor < 1 || $divisor > 7) {
                    throw new DomainException(__('hr_payroll_policies.validation.weekly_work_days_required'));
                }
                $calendarType = $calendarDays[$workDate]['day_type'];
                if ($calendarType !== 'working'
                    && ! ($method === HrPayrollAttendancePolicy::WeeklyScheduledWorkAndPaidHoliday && $calendarType === 'holiday_paid')) {
                    continue;
                }
                $workDays[$workDate] = $divisor;
                $dailyAmount = bcdiv($rate, (string) $divisor, 12);
            } elseif (in_array($method, [
                HrPayrollAttendancePolicy::WeeklyFinalizedAttendance,
                HrPayrollAttendancePolicy::WeeklyFinalizedAttendanceOrPaidLeave,
            ], true)) {
                $divisor = (int) $policy->weekly_work_days;
                if ($divisor < 1 || $divisor > 7) {
                    throw new DomainException(__('hr_payroll_policies.validation.weekly_work_days_required'));
                }
                if ($attendance->has($workDate)) {
                    $attendanceIds[] = (int) $attendance->get($workDate)['id'];
                } elseif ($method === HrPayrollAttendancePolicy::WeeklyFinalizedAttendanceOrPaidLeave && $paidLeave->has($workDate)) {
                    $leave = $paidLeave->get($workDate);
                    $dayUnits = $leave['day_fraction'];
                    $paidLeaveRequestIds = array_merge($paidLeaveRequestIds, $leave['leave_request_ids']);
                    $paidLeaveDayIds = array_merge($paidLeaveDayIds, $leave['leave_day_ids']);
                    $usedPaidLeave[] = $leave;
                } else {
                    continue;
                }
                $workDays[$workDate] = $divisor;
                $dailyAmount = bcmul(bcdiv($rate, (string) $divisor, 12), $dayUnits, 12);
            } else {
                throw new DomainException(__('hr_payroll.messages.accrual_policy_required', [
                    'employee' => $employee->doc_num, 'pay_basis' => 'weekly_wage', 'date' => $workDate,
                ]));
            }

            $dailyWeights[$workDate] = $dailyAmount;
            $amount = bcadd($amount, $dailyAmount, 12);
            $units = bcadd($units, $dayUnits, 4);
        }

        if ($dailyWeights === []) {
            $this->requireEvidence(collect(), 'weekly_wage');
        }
        $amount = $this->currencyMoney($amount);

        return $this->result('weekly_wage', implode('+', array_values(array_unique($methods))), $rate, $amount, '1.00000000', $policyByDate, [
            'active_from' => $activeStart->toDateString(),
            'active_to' => $activeEnd->toDateString(),
            'accrued_days' => count($dailyWeights),
            'units' => $units,
            'attendance_record_ids' => $attendanceIds,
            'paid_leave_request_ids' => array_values(array_unique($paidLeaveRequestIds)),
            'paid_leave_day_ids' => array_values(array_unique($paidLeaveDayIds)),
            'paid_leave_days' => $usedPaidLeave,
            'calendar_days' => array_values($calendarDays),
            'weekly_work_days' => $workDays === [] ? null : (count(array_unique($workDays)) === 1 ? (int) reset($workDays) : $workDays),
            'earning_by_date' => $this->distributeByDate($amount, $dailyWeights),
        ]);
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate @param array<string, mixed> $effects */
    private function daily(HrEmployee $employee, string $rate, CarbonImmutable $activeStart, CarbonImmutable $activeEnd, array $policyByDate, array $effects): array
    {
        $records = $this->evidenceRecords($effects, $activeStart, $activeEnd)
            ->unique('work_date')->keyBy('work_date');
        $paidLeave = $this->paidLeaveEvidence($effects, $activeStart, $activeEnd);
        $calendarDays = $this->scheduledCalendarDays($employee, $policyByDate, [
            HrPayrollAttendancePolicy::DailyScheduledWork,
            HrPayrollAttendancePolicy::DailyScheduledWorkAndPaidHoliday,
        ], 'daily_accrual_method');
        $billableDates = [];
        $attendanceIds = [];
        $paidLeaveRequestIds = [];
        $paidLeaveDayIds = [];
        $usedPaidLeave = [];
        $units = '0.0000';
        $methods = [];
        foreach (CarbonPeriod::create($activeStart, $activeEnd) as $date) {
            $workDate = $date->toDateString();
            $method = $policyByDate[$workDate]->daily_accrual_method;
            $methods[] = $method;
            if ($method === HrPayrollAttendancePolicy::DailyCalendarDays) {
                $billableDates[$workDate] = '1.0000';
            } elseif (in_array($method, [
                HrPayrollAttendancePolicy::DailyScheduledWork,
                HrPayrollAttendancePolicy::DailyScheduledWorkAndPaidHoliday,
            ], true)) {
                $calendarType = $calendarDays[$workDate]['day_type'];
                if ($calendarType === 'working'
                    || ($method === HrPayrollAttendancePolicy::DailyScheduledWorkAndPaidHoliday && $calendarType === 'holiday_paid')) {
                    $billableDates[$workDate] = '1.0000';
                }
            } elseif (in_array($method, [
                HrPayrollAttendancePolicy::DailyFinalizedAttendance,
                HrPayrollAttendancePolicy::DailyFinalizedAttendanceOrPaidLeave,
            ], true)) {
                if ($records->has($workDate)) {
                    $billableDates[$workDate] = '1.0000';
                    $attendanceIds[] = (int) $records->get($workDate)['id'];
                } elseif ($method === HrPayrollAttendancePolicy::DailyFinalizedAttendanceOrPaidLeave && $paidLeave->has($workDate)) {
                    $leave = $paidLeave->get($workDate);
                    $billableDates[$workDate] = $leave['day_fraction'];
                    $paidLeaveRequestIds = array_merge($paidLeaveRequestIds, $leave['leave_request_ids']);
                    $paidLeaveDayIds = array_merge($paidLeaveDayIds, $leave['leave_day_ids']);
                    $usedPaidLeave[] = $leave;
                }
            } else {
                throw new DomainException(__('hr_payroll.messages.accrual_policy_required', [
                    'employee' => $employee->doc_num, 'pay_basis' => 'daily_wage', 'date' => $workDate,
                ]));
            }
        }
        if ($billableDates === []) {
            $this->requireEvidence(collect(), 'daily_wage');
        }
        foreach ($billableDates as $dayUnits) {
            $units = bcadd($units, $dayUnits, 4);
        }
        $amount = $this->currencyMoney(bcmul($rate, $units, 8));

        return $this->result('daily_wage', implode('+', array_values(array_unique($methods))), $rate, $amount, '1.00000000', $policyByDate, [
            'attendance_record_ids' => $attendanceIds,
            'paid_leave_request_ids' => array_values(array_unique($paidLeaveRequestIds)),
            'paid_leave_day_ids' => array_values(array_unique($paidLeaveDayIds)),
            'paid_leave_days' => $usedPaidLeave,
            'calendar_days' => array_values($calendarDays),
            'units' => $units,
            'earning_by_date' => $this->distributeByDate($amount, $billableDates),
        ]);
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate @param array<string, mixed> $effects */
    private function hourly(string $rate, CarbonImmutable $activeStart, CarbonImmutable $activeEnd, array $policyByDate, array $effects): array
    {
        $records = $this->evidenceRecords($effects, $activeStart, $activeEnd)
            ->filter(fn (array $record): bool => in_array($policyByDate[$record['work_date']]->hourly_accrual_method, [
                HrPayrollAttendancePolicy::HourlyFinalizedMinutes,
                HrPayrollAttendancePolicy::HourlyFinalizedMinutesOrPaidLeave,
            ], true));
        $roundingRecords = $records->map(function (array $record) use ($policyByDate): array {
            $policy = $policyByDate[$record['work_date']];
            $rawMinutes = max(0, $record['worked_minutes'] - $record['overtime_minutes']);

            return [
                'record_id' => $record['id'],
                'work_date' => $record['work_date'],
                'policy_id' => (int) $policy->getKey(),
                'raw_minutes' => $rawMinutes,
                'payable_minutes' => $this->roundHourlyMinutes($rawMinutes, $policy),
                'mode' => $policy->hourly_rounding_mode ?? 'none',
                'increment_minutes' => $policy->hourly_rounding_increment_minutes,
            ];
        });
        $rawMinutes = (int) $roundingRecords->sum('raw_minutes');
        $workedMinutes = (int) $roundingRecords->sum('payable_minutes');
        $paidLeave = $this->paidLeaveEvidence($effects, $activeStart, $activeEnd);
        $workedByDate = $roundingRecords->groupBy('work_date')->map(fn (Collection $daily): int => $daily->sum('payable_minutes'))->all();
        $payableByDate = $workedByDate;
        $leaveMinuteRecords = [];
        $paidLeaveRequestIds = [];
        $paidLeaveDayIds = [];
        foreach ($paidLeave as $date => $leave) {
            $policy = $policyByDate[$date];
            if ($policy->hourly_accrual_method !== HrPayrollAttendancePolicy::HourlyFinalizedMinutesOrPaidLeave) {
                continue;
            }
            $standardMinutes = (int) $policy->standard_day_minutes;
            if ($standardMinutes < 1 || $standardMinutes > 1440) {
                throw new DomainException(__('hr_payroll.messages.accrual_policy_required', [
                    'employee' => '', 'pay_basis' => 'hourly_wage', 'date' => $date,
                ]));
            }
            $approvedMinutes = (int) bcmul((string) $standardMinutes, (string) $leave['day_fraction'], 0);
            $leaveMinutes = min($approvedMinutes, max(0, $standardMinutes - ($workedByDate[$date] ?? 0)));
            if ($leaveMinutes <= 0) {
                continue;
            }
            $payableByDate[$date] = ($payableByDate[$date] ?? 0) + $leaveMinutes;
            $paidLeaveRequestIds = array_merge($paidLeaveRequestIds, $leave['leave_request_ids']);
            $paidLeaveDayIds = array_merge($paidLeaveDayIds, $leave['leave_day_ids']);
            $leaveMinuteRecords[] = [
                ...$leave,
                'policy_id' => (int) $policy->getKey(),
                'standard_day_minutes' => $standardMinutes,
                'credited_minutes' => $leaveMinutes,
            ];
        }
        $leaveMinutes = array_sum(array_column($leaveMinuteRecords, 'credited_minutes'));
        $minutes = $workedMinutes + $leaveMinutes;
        if ($minutes <= 0) {
            $this->requireEvidence(collect(), 'hourly_wage');
        }
        $amount = $this->currencyMoney(bcmul($rate, bcdiv((string) $minutes, '60', 12), 12));

        return $this->result('hourly_wage', implode('+', array_values(array_unique(array_map(fn (HrPayrollAttendancePolicy $policy): string => $policy->hourly_accrual_method, $policyByDate)))), $rate, $amount, '1.00000000', $policyByDate, [
            'attendance_record_ids' => $records->pluck('id')->all(),
            'raw_worked_minutes' => $rawMinutes,
            'worked_minutes' => $workedMinutes,
            'paid_leave_minutes' => $leaveMinutes,
            'paid_leave_request_ids' => array_values(array_unique($paidLeaveRequestIds)),
            'paid_leave_day_ids' => array_values(array_unique($paidLeaveDayIds)),
            'paid_leave_minutes_by_date' => $leaveMinuteRecords,
            'payable_minutes' => $minutes,
            'units' => bcdiv((string) $minutes, '60', 4),
            'rounding_records' => $roundingRecords->values()->all(),
            'earning_by_date' => $this->distributeByDate($amount, $payableByDate),
        ]);
    }

    private function roundHourlyMinutes(int $minutes, HrPayrollAttendancePolicy $policy): int
    {
        $mode = $policy->hourly_rounding_mode ?? 'none';
        if ($mode === 'none') {
            return $minutes;
        }

        $increment = (int) $policy->hourly_rounding_increment_minutes;
        if ($increment < 1 || $increment > 60 || ! in_array($mode, ['down', 'nearest', 'up'], true)) {
            throw new DomainException(__('hr_payroll_policies.validation.hourly_rounding_increment_required'));
        }

        $whole = intdiv($minutes, $increment) * $increment;
        $remainder = $minutes % $increment;

        return match ($mode) {
            'down' => $whole,
            'nearest' => $whole + ($remainder * 2 >= $increment ? $increment : 0),
            'up' => $whole + ($remainder > 0 ? $increment : 0),
        };
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate @param array<string, mixed> $effects */
    private function shift(HrEmployee $employee, string $rate, CarbonImmutable $activeStart, CarbonImmutable $activeEnd, array $policyByDate, array $effects): array
    {
        $records = $this->evidenceRecords($effects, $activeStart, $activeEnd)
            ->filter(fn (array $record): bool => $record['shift_id'] !== null
                && in_array($policyByDate[$record['work_date']]->shift_accrual_method, [
                    HrPayrollAttendancePolicy::ShiftFinalizedAttendance,
                    HrPayrollAttendancePolicy::ShiftFinalizedAttendanceOrPaidLeave,
                ], true))->unique('work_date');
        $paidLeave = $this->paidLeaveEvidence($effects, $activeStart, $activeEnd);
        $shiftAssignments = DB::table('hr_employee_shift_assignments')
            ->where('employee_id', $employee->getKey())
            ->whereDate('effective_from', '<=', $activeEnd->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $activeStart->toDateString()))
            ->whereNull('deleted_at')
            ->orderBy('effective_from')
            ->sharedLock()
            ->get(['id', 'shift_id', 'effective_from', 'effective_to']);
        $billableDates = $records->countBy('work_date')->map(fn (int $count): string => '1.0000')->all();
        $leaveShiftRecords = [];
        $paidLeaveRequestIds = [];
        $paidLeaveDayIds = [];
        foreach ($paidLeave as $date => $leave) {
            if ($policyByDate[$date]->shift_accrual_method !== HrPayrollAttendancePolicy::ShiftFinalizedAttendanceOrPaidLeave
                || isset($billableDates[$date])) {
                continue;
            }
            $matchingAssignments = $shiftAssignments->filter(fn (object $assignment): bool => $assignment->effective_from <= $date
                && ($assignment->effective_to === null || $assignment->effective_to >= $date));
            if ($matchingAssignments->count() !== 1) {
                throw new DomainException(__('hr_payroll.messages.paid_leave_shift_assignment_required', [
                    'employee' => $employee->doc_num, 'date' => $date,
                ]));
            }
            $assignment = $matchingAssignments->first();
            $billableDates[$date] = $leave['day_fraction'];
            $paidLeaveRequestIds = array_merge($paidLeaveRequestIds, $leave['leave_request_ids']);
            $paidLeaveDayIds = array_merge($paidLeaveDayIds, $leave['leave_day_ids']);
            $leaveShiftRecords[] = [
                ...$leave,
                'shift_assignment_id' => (int) $assignment->id,
                'shift_id' => (int) $assignment->shift_id,
            ];
        }
        if ($billableDates === []) {
            $this->requireEvidence(collect(), 'shift_wage');
        }
        ksort($billableDates);
        $units = array_reduce($billableDates, fn (string $total, string $dayUnits): string => bcadd($total, $dayUnits, 4), '0.0000');
        $amount = $this->currencyMoney(bcmul($rate, $units, 8));

        return $this->result('shift_wage', implode('+', array_values(array_unique(array_map(fn (HrPayrollAttendancePolicy $policy): string => $policy->shift_accrual_method, $policyByDate)))), $rate, $amount, '1.00000000', $policyByDate, [
            'attendance_record_ids' => $records->pluck('id')->all(),
            'shift_ids' => $records->pluck('shift_id')->merge(array_column($leaveShiftRecords, 'shift_id'))->unique()->values()->all(),
            'paid_leave_request_ids' => array_values(array_unique($paidLeaveRequestIds)),
            'paid_leave_day_ids' => array_values(array_unique($paidLeaveDayIds)),
            'paid_leave_shifts' => $leaveShiftRecords,
            'units' => $units,
            'earning_by_date' => $this->distributeByDate($amount, $billableDates),
        ]);
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate */
    private function piece(
        HrEmployee $employee,
        string $configuredRate,
        CarbonImmutable $activeStart,
        CarbonImmutable $activeEnd,
        array $policyByDate,
    ): array {
        $approvals = DB::table('production_piece_approvals as approval')
            ->join('production_runs as run', 'run.id', '=', 'approval.production_run_id')
            ->whereNull('approval.revoked_at')
            ->whereColumn('approval.correction_sequence', 'run.correction_sequence')
            ->where('approval.company_id', $employee->company_id)
            ->where('approval.branch_id', $employee->branch_id)
            ->where('approval.employee_id', $employee->getKey())
            ->where('run.status', ProductionRun::StatusCompleted)
            ->whereNull('run.deleted_at')
            ->whereBetween('run.actual_end_at', [$activeStart->startOfDay(), $activeEnd->endOfDay()])
            ->orderBy('run.actual_end_at')
            ->orderBy('approval.id')
            ->sharedLock()
            ->get([
                'approval.id', 'approval.quantity', 'approval.rate', 'approval.pay_basis',
                'approval.run_good_base_quantity', 'approval.approved_at', 'approval.approved_by',
                'run.id as run_id', 'run.public_id', 'run.run_number', 'run.production_order_id',
                'run.actual_end_at', 'run.completed_by', 'run.good_base_quantity',
            ]);

        $sources = $approvals->map(function (object $approval) use ($employee, $policyByDate): ?array {
            $completedDate = CarbonImmutable::parse($approval->actual_end_at)->toDateString();
            if (($policyByDate[$completedDate]->piece_accrual_method ?? null) !== HrPayrollAttendancePolicy::PieceApprovedOutput) {
                return null;
            }

            $quantity = bcadd((string) $approval->quantity, '0', 8);
            $rate = bcadd((string) $approval->rate, '0', 4);
            if ($approval->pay_basis !== 'piece_rate'
                || bccomp((string) $approval->run_good_base_quantity, (string) $approval->good_base_quantity, 8) !== 0
                || bccomp($quantity, '0', 8) <= 0
                || bccomp($quantity, (string) $approval->good_base_quantity, 8) > 0
                || bccomp($rate, '0', 4) <= 0) {
                throw new DomainException(__('hr_payroll.messages.piece_evidence_invalid', [
                    'employee' => $employee->doc_num,
                    'run' => $approval->run_number,
                ]));
            }

            return [
                'piece_approval_id' => (int) $approval->id,
                'production_run_id' => (int) $approval->run_id,
                'production_run_public_id' => (string) $approval->public_id,
                'production_run_number' => (string) $approval->run_number,
                'production_order_id' => (int) $approval->production_order_id,
                'completed_at' => CarbonImmutable::parse($approval->actual_end_at)->toIso8601String(),
                'completed_by' => $approval->completed_by === null ? null : (int) $approval->completed_by,
                'approved_at' => (string) $approval->approved_at,
                'approved_by' => $approval->approved_by === null ? null : (int) $approval->approved_by,
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => $this->currencyMoney(bcmul($quantity, $rate, 12)),
            ];
        })->filter()->values();

        $this->requireEvidence($sources, 'piece_rate');
        $quantity = $sources->reduce(
            fn (string $total, array $source): string => bcadd($total, $source['quantity'], 8),
            '0.00000000',
        );
        $amount = $this->currencyMoney($sources->reduce(
            fn (string $total, array $source): string => bcadd($total, $source['amount'], 4),
            '0.0000',
        ));

        return $this->result('piece_rate', HrPayrollAttendancePolicy::PieceApprovedOutput, $configuredRate, $amount, '1.00000000', $policyByDate, [
            'production_run_ids' => $sources->pluck('production_run_id')->all(),
            'units' => $quantity,
            'sources' => $sources->all(),
            'earning_by_date' => $this->distributeByDate($amount, $sources->groupBy(fn (array $source): string => CarbonImmutable::parse($source['completed_at'])->toDateString())->map(fn (Collection $daily): string => $daily->reduce(fn (string $total, array $source): string => bcadd($total, $source['amount'], 4), '0.0000'))->all()),
        ]);
    }

    private function hasPieceApprovals(HrEmployee $employee, string $periodStart, string $periodEnd): bool
    {
        return DB::table('production_piece_approvals as approval')
            ->join('production_runs as run', 'run.id', '=', 'approval.production_run_id')
            ->whereNull('approval.revoked_at')
            ->whereColumn('approval.correction_sequence', 'run.correction_sequence')
            ->where('approval.company_id', $employee->company_id)
            ->where('approval.employee_id', $employee->getKey())
            ->where('run.status', ProductionRun::StatusCompleted)
            ->whereNull('run.deleted_at')
            ->whereBetween('run.actual_end_at', [CarbonImmutable::parse($periodStart)->startOfDay(), CarbonImmutable::parse($periodEnd)->endOfDay()])
            ->exists();
    }

    private function activeStart(HrEmployee $employee, object $assignment, string $basis, string $periodStart): CarbonImmutable
    {
        return collect([
            $periodStart,
            $employee->hire_date?->toDateString(),
            $employee->contract_start_date?->toDateString(),
            $assignment->effective_from,
        ])
            ->filter()
            ->map(fn (mixed $date): CarbonImmutable => CarbonImmutable::parse((string) $date))
            ->max();
    }

    private function activeEnd(HrEmployee $employee, object $assignment, string $basis, string $periodEnd): CarbonImmutable
    {
        return collect([
            $periodEnd,
            $employee->contract_end_date?->toDateString(),
            $employee->termination_date?->toDateString(),
            $assignment->effective_to,
        ])
            ->filter()
            ->map(fn (mixed $date): CarbonImmutable => CarbonImmutable::parse((string) $date))
            ->min();
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

    /**
     * @param  Collection<int, HrPayrollAttendancePolicy>  $policies
     * @return array<string, HrPayrollAttendancePolicy>
     */
    private function policyByDate(HrEmployee $employee, string $basis, CarbonImmutable $start, CarbonImmutable $end, Collection $policies): array
    {
        $field = match ($basis) {
            'monthly_salary' => 'monthly_partial_method',
            'weekly_wage' => 'weekly_accrual_method',
            'daily_wage' => 'daily_accrual_method',
            'hourly_wage' => 'hourly_accrual_method',
            'shift_wage' => 'shift_accrual_method',
            'piece_rate' => 'piece_accrual_method',
            default => null,
        };
        $resolved = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dateString = $date->toDateString();
            $effective = $policies->filter(fn (HrPayrollAttendancePolicy $policy): bool => $policy->effective_from->toDateString() <= $dateString
                && ($policy->effective_to === null || $policy->effective_to->toDateString() >= $dateString));
            $policy = $effective->where('branch_id', $employee->branch_id)->sortByDesc('effective_from')->first()
                ?? $effective->whereNull('branch_id')->sortByDesc('effective_from')->first();

            if (! $policy instanceof HrPayrollAttendancePolicy || $field === null || blank($policy->{$field})) {
                throw new DomainException(__('hr_payroll.messages.accrual_policy_required', [
                    'employee' => $employee->doc_num,
                    'pay_basis' => $basis,
                    'date' => $dateString,
                ]));
            }

            $resolved[$dateString] = $policy;
        }

        return $resolved;
    }

    /**
     * @param  array<string, HrPayrollAttendancePolicy>  $policyByDate
     * @param  list<string>  $scheduledMethods
     * @return array<string, array{work_date: string, assignment_id: int, calendar_id: int, calendar_day_id: int, day_type: string}>
     */
    private function scheduledCalendarDays(HrEmployee $employee, array $policyByDate, array $scheduledMethods, string $policyField): array
    {
        $dates = array_keys(array_filter($policyByDate, fn (HrPayrollAttendancePolicy $policy): bool => in_array($policy->{$policyField}, $scheduledMethods, true)));

        return $this->workCalendars->daysForEmployee($employee, $dates, true);
    }

    /** @param array<string, mixed> $effects @return Collection<int, array<string, mixed>> */
    private function evidenceRecords(array $effects, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return collect(data_get($effects, 'attendance.finalized_records', []))
            ->filter(fn (array $record): bool => $record['work_date'] >= $start->toDateString() && $record['work_date'] <= $end->toDateString())
            ->values();
    }

    /** @param array<string, mixed> $effects @return Collection<string, array<string, mixed>> */
    private function paidLeaveEvidence(array $effects, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return collect($effects['paid_leave_days'] ?? [])
            ->filter(fn (array $day): bool => $day['leave_date'] >= $start->toDateString()
                && $day['leave_date'] <= $end->toDateString()
                && bccomp((string) $day['day_fraction'], '0', 4) > 0)
            ->keyBy('leave_date');
    }

    /** @param Collection<int, array<string, mixed>> $records */
    private function requireEvidence(Collection $records, string $basis): void
    {
        if ($records->isEmpty()) {
            throw new DomainException(__('hr_payroll.messages.accrual_evidence_required', ['pay_basis' => $basis]));
        }
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate @param array<string, mixed> $evidence @return array<string, mixed> */
    private function result(string $basis, string $method, string $rate, string $amount, string $componentFactor, array $policyByDate, array $evidence): array
    {
        $policies = collect($policyByDate)->unique(fn (HrPayrollAttendancePolicy $policy): int => (int) $policy->getKey());

        return [
            'pay_basis' => $basis,
            'method' => $method,
            'rate' => $rate,
            'amount' => $amount,
            'component_factor' => $componentFactor,
            'policy_snapshots' => $policies->map(fn (HrPayrollAttendancePolicy $policy): array => [
                'id' => (int) $policy->getKey(),
                'company_id' => (int) $policy->company_id,
                'branch_id' => $policy->branch_id === null ? null : (int) $policy->branch_id,
                'effective_from' => $policy->effective_from->toDateString(),
                'effective_to' => $policy->effective_to?->toDateString(),
                'hourly_rounding_mode' => $policy->hourly_rounding_mode,
                'hourly_rounding_increment_minutes' => $policy->hourly_rounding_increment_minutes,
                'weekly_work_days' => $policy->weekly_work_days,
                'weekly_accrual_method' => $policy->weekly_accrual_method,
                'daily_accrual_method' => $policy->daily_accrual_method,
                'hourly_accrual_method' => $policy->hourly_accrual_method,
                'shift_accrual_method' => $policy->shift_accrual_method,
                'standard_day_minutes' => $policy->standard_day_minutes,
            ])->values()->all(),
            'evidence' => $evidence,
        ];
    }

    private function currencyMoney(string $value): string
    {
        $roundingIncrement = str_starts_with($value, '-') ? '-0.00005' : '0.00005';

        return bcadd(bcadd($value, $roundingIncrement, 5), '0', 4);
    }

    /** @param array<string, int|string> $weights @return array<string, string> */
    private function distributeByDate(string $amount, array $weights): array
    {
        $weights = array_filter($weights, fn (int|string $weight): bool => bccomp((string) $weight, '0', 8) > 0);
        $totalWeight = array_reduce($weights, fn (string $total, int|string $weight): string => bcadd($total, (string) $weight, 12), '0.000000000000');
        if ($weights === [] || bccomp($totalWeight, '0', 12) <= 0) {
            return [];
        }

        $distributed = [];
        $remaining = $amount;
        $lastDate = array_key_last($weights);
        foreach ($weights as $date => $weight) {
            $part = $date === $lastDate ? $remaining : $this->currencyMoney(bcdiv(bcmul($amount, (string) $weight, 12), $totalWeight, 12));
            $distributed[$date] = $part;
            $remaining = bcsub($remaining, $part, 4);
        }

        return $distributed;
    }
}
