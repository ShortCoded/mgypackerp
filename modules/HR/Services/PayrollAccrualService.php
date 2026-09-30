<?php

namespace Modules\HR\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use DomainException;
use Illuminate\Support\Collection;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrPayrollAttendancePolicy;

final class PayrollAccrualService
{
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
            ]);
        }

        $policies = $this->policies($employee, $activeStart->toDateString(), $activeEnd->toDateString());
        $policyByDate = $this->policyByDate($employee, $basis, $activeStart, $activeEnd, $policies);

        return match ($basis) {
            'monthly_salary' => $this->monthly($rate, $periodStart, $periodEnd, $activeStart, $activeEnd, $policyByDate),
            'weekly_wage' => $this->weekly($rate, $activeStart, $activeEnd, $policyByDate),
            'daily_wage' => $this->daily($rate, $activeStart, $activeEnd, $policyByDate, $attendanceEffects),
            'hourly_wage' => $this->hourly($rate, $activeStart, $activeEnd, $policyByDate, $attendanceEffects),
            'shift_wage' => $this->shift($rate, $activeStart, $activeEnd, $policyByDate, $attendanceEffects),
            'piece_rate' => throw new DomainException(__('hr_payroll.messages.accrual_evidence_unavailable', [
                'employee' => $employee->doc_num,
                'pay_basis' => $basis,
            ])),
            default => throw new DomainException(__('hr_payroll.messages.unsupported_pay_basis', [
                'employee' => $employee->doc_num,
                'pay_basis' => $basis,
            ])),
        };
    }

    public function rate(HrEmployee $employee, object $assignment, string $basis): string
    {
        $value = match ($basis) {
            'monthly_salary' => $assignment->basic_salary,
            'weekly_wage' => $employee->weekly_wage,
            'daily_wage' => $employee->daily_wage,
            'hourly_wage' => $employee->hourly_wage,
            'shift_wage' => $employee->shift_wage,
            'piece_rate' => $employee->piece_rate,
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

        foreach (CarbonPeriod::create($activeStart, $activeEnd) as $date) {
            $policy = $policyByDate[$date->toDateString()];
            $methods[] = $policy->monthly_partial_method;
            $dailyAmount = $policy->monthly_partial_method === HrPayrollAttendancePolicy::MonthlyCalendarDays
                ? bcdiv($rate, (string) $date->daysInMonth, 12)
                : bcdiv($rate, (string) $policy->salary_day_divisor, 12);
            $amount = bcadd($amount, $dailyAmount, 12);
        }

        $amount = $this->currencyMoney($amount);

        return $this->result('monthly_salary', implode('+', array_values(array_unique($methods))), $rate, $amount, bcdiv($amount, $rate, 8), $policyByDate, [
            'active_from' => $activeStart->toDateString(),
            'active_to' => $activeEnd->toDateString(),
            'accrued_days' => $activeStart->diffInDays($activeEnd) + 1,
            'period_days' => $periodDays,
        ]);
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate */
    private function weekly(string $rate, CarbonImmutable $activeStart, CarbonImmutable $activeEnd, array $policyByDate): array
    {
        $days = $activeStart->diffInDays($activeEnd) + 1;
        $amount = $this->currencyMoney(bcmul(bcdiv($rate, '7', 12), (string) $days, 12));

        return $this->result('weekly_wage', HrPayrollAttendancePolicy::WeeklyCalendarDays, $rate, $amount, '1.00000000', $policyByDate, [
            'active_from' => $activeStart->toDateString(),
            'active_to' => $activeEnd->toDateString(),
            'accrued_days' => $days,
        ]);
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate @param array<string, mixed> $effects */
    private function daily(string $rate, CarbonImmutable $activeStart, CarbonImmutable $activeEnd, array $policyByDate, array $effects): array
    {
        $records = $this->evidenceRecords($effects, $activeStart, $activeEnd)
            ->filter(fn (array $record): bool => $policyByDate[$record['work_date']]->daily_accrual_method === HrPayrollAttendancePolicy::DailyFinalizedAttendance);
        $this->requireEvidence($records, 'daily_wage');
        $amount = $this->currencyMoney(bcmul($rate, (string) $records->count(), 8));

        return $this->result('daily_wage', HrPayrollAttendancePolicy::DailyFinalizedAttendance, $rate, $amount, '1.00000000', $policyByDate, [
            'attendance_record_ids' => $records->pluck('id')->all(),
            'units' => $records->count(),
        ]);
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate @param array<string, mixed> $effects */
    private function hourly(string $rate, CarbonImmutable $activeStart, CarbonImmutable $activeEnd, array $policyByDate, array $effects): array
    {
        $records = $this->evidenceRecords($effects, $activeStart, $activeEnd)
            ->filter(fn (array $record): bool => $policyByDate[$record['work_date']]->hourly_accrual_method === HrPayrollAttendancePolicy::HourlyFinalizedMinutes);
        $minutes = (int) $records->sum(fn (array $record): int => max(0, $record['worked_minutes'] - $record['overtime_minutes']));
        if ($records->isEmpty() || $minutes <= 0) {
            $this->requireEvidence(collect(), 'hourly_wage');
        }
        $amount = $this->currencyMoney(bcmul($rate, bcdiv((string) $minutes, '60', 12), 12));

        return $this->result('hourly_wage', HrPayrollAttendancePolicy::HourlyFinalizedMinutes, $rate, $amount, '1.00000000', $policyByDate, [
            'attendance_record_ids' => $records->pluck('id')->all(),
            'worked_minutes' => $minutes,
        ]);
    }

    /** @param array<string, HrPayrollAttendancePolicy> $policyByDate @param array<string, mixed> $effects */
    private function shift(string $rate, CarbonImmutable $activeStart, CarbonImmutable $activeEnd, array $policyByDate, array $effects): array
    {
        $records = $this->evidenceRecords($effects, $activeStart, $activeEnd)
            ->filter(fn (array $record): bool => $record['shift_id'] !== null
                && $policyByDate[$record['work_date']]->shift_accrual_method === HrPayrollAttendancePolicy::ShiftFinalizedAttendance);
        $this->requireEvidence($records, 'shift_wage');
        $amount = $this->currencyMoney(bcmul($rate, (string) $records->count(), 8));

        return $this->result('shift_wage', HrPayrollAttendancePolicy::ShiftFinalizedAttendance, $rate, $amount, '1.00000000', $policyByDate, [
            'attendance_record_ids' => $records->pluck('id')->all(),
            'shift_ids' => $records->pluck('shift_id')->unique()->values()->all(),
            'units' => $records->count(),
        ]);
    }

    private function activeStart(HrEmployee $employee, object $assignment, string $basis, string $periodStart): CarbonImmutable
    {
        return collect([
            $periodStart,
            $employee->hire_date?->toDateString(),
            $employee->contract_start_date?->toDateString(),
            $basis === 'monthly_salary' ? $assignment->effective_from : null,
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
            $basis === 'monthly_salary' ? $assignment->effective_to : null,
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

    /** @param array<string, mixed> $effects @return Collection<int, array<string, mixed>> */
    private function evidenceRecords(array $effects, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return collect(data_get($effects, 'attendance.finalized_records', []))
            ->filter(fn (array $record): bool => $record['work_date'] >= $start->toDateString() && $record['work_date'] <= $end->toDateString())
            ->values();
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
            ])->values()->all(),
            'evidence' => $evidence,
        ];
    }

    private function currencyMoney(string $value): string
    {
        $roundingIncrement = str_starts_with($value, '-') ? '-0.00005' : '0.00005';

        return bcadd(bcadd($value, $roundingIncrement, 5), '0', 4);
    }
}
