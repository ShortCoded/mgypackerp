<?php

namespace Modules\HR\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Models\HrEmployee;

final class HrWorkCalendarService
{
    public function __construct(private readonly OperatingScopeAccessService $scope) {}

    /** @param array<string, mixed> $data */
    public function create(int $companyId, array $data, User $actor): object
    {
        return DB::transaction(function () use ($companyId, $data, $actor): object {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $branch = null;
            if (filled($data['branch_doc_num'] ?? null)) {
                $branch = Branch::query()->where('company_id', $companyId)
                    ->where('doc_num', $data['branch_doc_num'])->where('status', 'active')
                    ->whereNull('deleted_at')->first();
                if ($branch === null || ! $this->allowedBranchIds($actor, $company)->contains($branch->getKey())) {
                    throw new DomainException(__('hr_work_calendars.errors.branch_scope'));
                }
            } elseif (! $this->scope->hasUnrestrictedBranchAccess($actor)) {
                throw new DomainException(__('hr_work_calendars.errors.company_scope'));
            }

            $code = strtoupper(trim((string) $data['code']));
            if (DB::table('hr_work_calendars')->where('company_id', $companyId)
                ->whereRaw('lower(code) = ?', [strtolower($code)])->exists()) {
                throw new DomainException(__('hr_work_calendars.errors.code_exists'));
            }

            $id = DB::table('hr_work_calendars')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $branch?->getKey(),
                'code' => $code,
                'name' => trim((string) $data['name']),
                'status' => 'active',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('hr_work_calendars')->find($id);
        }, attempts: 3);
    }

    public function calendar(int $companyId, int $calendarId, User $actor, bool $forMutation = false): object
    {
        $calendar = DB::table('hr_work_calendars')->where('company_id', $companyId)
            ->where('id', $calendarId)->where('status', 'active')->whereNull('deleted_at')->first();
        if ($calendar === null) {
            throw new DomainException(__('hr_work_calendars.errors.calendar_unavailable'));
        }
        if ($calendar->branch_id === null && $forMutation && ! $this->scope->hasUnrestrictedBranchAccess($actor)) {
            throw new DomainException(__('hr_work_calendars.errors.company_scope'));
        }
        $company = Company::query()->findOrFail($companyId);
        if ($calendar->branch_id !== null && ! $this->allowedBranchIds($actor, $company)->contains((int) $calendar->branch_id)) {
            throw new DomainException(__('hr_work_calendars.errors.branch_scope'));
        }

        return $calendar;
    }

    /**
     * @param  list<string>  $dates
     * @return array<string, array{work_date: string, assignment_id: int, calendar_id: int, calendar_day_id: int, day_type: string}>
     */
    public function daysForEmployee(HrEmployee $employee, array $dates, bool $requireComplete): array
    {
        $dates = collect($dates)->map(fn (mixed $date): string => (string) $date)->unique()->sort()->values()->all();
        if ($dates === []) {
            return [];
        }

        $assignments = DB::table('hr_work_calendar_assignments as assignments')
            ->join('hr_work_calendars as calendars', 'calendars.id', '=', 'assignments.calendar_id')
            ->where('assignments.employee_id', $employee->getKey())
            ->where('calendars.company_id', $employee->company_id)
            ->where(fn ($query) => $query->whereNull('calendars.branch_id')
                ->when($employee->branch_id !== null, fn ($branch) => $branch->orWhere('calendars.branch_id', $employee->branch_id)))
            ->where('calendars.status', 'active')
            ->whereNull('assignments.deleted_at')
            ->whereNull('calendars.deleted_at')
            ->where('assignments.effective_from', '<=', end($dates))
            ->where(fn ($query) => $query->whereNull('assignments.effective_to')
                ->orWhere('assignments.effective_to', '>=', $dates[0]))
            ->get(['assignments.id', 'assignments.calendar_id', 'assignments.effective_from', 'assignments.effective_to']);
        $calendarDays = DB::table('hr_work_calendar_days')
            ->whereIn('calendar_id', $assignments->pluck('calendar_id')->unique()->all())
            ->whereBetween('work_date', [$dates[0], end($dates)])
            ->get(['id', 'calendar_id', 'work_date', 'day_type'])
            ->keyBy(fn (object $day): string => $day->calendar_id.'|'.$day->work_date);
        $resolved = [];

        foreach ($dates as $date) {
            $matchingAssignments = $assignments->filter(fn (object $assignment): bool => $assignment->effective_from <= $date
                && ($assignment->effective_to === null || $assignment->effective_to >= $date));
            if ($matchingAssignments->count() > 1 || ($requireComplete && $matchingAssignments->count() !== 1)) {
                throw new DomainException(__('hr_payroll.messages.scheduled_calendar_missing_or_overlapping', [
                    'employee' => $employee->doc_num, 'date' => $date,
                ]));
            }
            if ($matchingAssignments->isEmpty()) {
                continue;
            }

            $assignment = $matchingAssignments->first();
            $day = $calendarDays->get($assignment->calendar_id.'|'.$date);
            if ($day === null || ! in_array($day->day_type, ['working', 'holiday_paid', 'holiday_unpaid', 'holiday', 'weekend', 'non_working', 'off'], true)) {
                if ($requireComplete) {
                    throw new DomainException(__('hr_payroll.messages.scheduled_calendar_day_required', [
                        'employee' => $employee->doc_num, 'date' => $date,
                    ]));
                }

                continue;
            }

            $resolved[$date] = [
                'work_date' => $date,
                'assignment_id' => (int) $assignment->id,
                'calendar_id' => (int) $assignment->calendar_id,
                'calendar_day_id' => (int) $day->id,
                'day_type' => (string) $day->day_type,
            ];
        }

        return $resolved;
    }

    /** @param array<string, mixed> $data */
    public function addDay(int $companyId, int $calendarId, array $data, User $actor): object
    {
        return DB::transaction(function () use ($companyId, $calendarId, $data, $actor): object {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $this->calendar($companyId, $calendarId, $actor, true);
            if (DB::table('hr_work_calendar_days')->where('calendar_id', $calendarId)
                ->where('work_date', $data['work_date'])->exists()) {
                throw new DomainException(__('hr_work_calendars.errors.day_exists'));
            }
            $this->assertCalendarPayrollUnchanged($companyId, $calendarId, $data['work_date'], $data['work_date']);

            $id = DB::table('hr_work_calendar_days')->insertGetId([
                'calendar_id' => $calendarId,
                'work_date' => $data['work_date'],
                'day_type' => $data['day_type'],
                'label' => filled($data['label'] ?? null) ? trim((string) $data['label']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('hr_work_calendar_days')->find($id);
        }, attempts: 3);
    }

    /** @param array{day_type: string, expected_day_type: string, label?: string|null} $data @return array{before: object, after: object} */
    public function changeDay(int $companyId, int $calendarId, int $dayId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($companyId, $calendarId, $dayId, $data, $actor): array {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $this->calendar($companyId, $calendarId, $actor, true);
            $day = DB::table('hr_work_calendar_days')->where('calendar_id', $calendarId)
                ->where('id', $dayId)->lockForUpdate()->first();
            if ($day === null) {
                throw new DomainException(__('hr_work_calendars.errors.day_unavailable'));
            }
            if ($day->day_type !== $data['expected_day_type']) {
                throw new DomainException(__('hr_work_calendars.errors.day_stale'));
            }
            $this->assertCalendarPayrollUnchanged($companyId, $calendarId, $day->work_date, $day->work_date);
            $label = filled($data['label'] ?? null) ? trim((string) $data['label']) : null;
            if ($day->day_type === $data['day_type'] && $day->label === $label) {
                throw new DomainException(__('hr_work_calendars.errors.day_unchanged'));
            }
            DB::table('hr_work_calendar_days')->where('id', $dayId)->update([
                'day_type' => $data['day_type'], 'label' => $label, 'updated_at' => now(),
            ]);

            return ['before' => $day, 'after' => DB::table('hr_work_calendar_days')->find($dayId)];
        }, attempts: 3);
    }

    /** @param list<int> $workingWeekdays */
    public function fillRange(int $companyId, int $calendarId, string $from, string $to, array $workingWeekdays, User $actor): int
    {
        return DB::transaction(function () use ($companyId, $calendarId, $from, $to, $workingWeekdays, $actor): int {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $this->calendar($companyId, $calendarId, $actor, true);
            $first = CarbonImmutable::parse($from);
            $last = CarbonImmutable::parse($to);
            if ($first->greaterThan($last) || $first->diffInDays($last) > 365) {
                throw new DomainException(__('hr_work_calendars.errors.range_length'));
            }

            $existing = DB::table('hr_work_calendar_days')
                ->where('calendar_id', $calendarId)->whereBetween('work_date', [$from, $to])
                ->pluck('work_date')->flip();
            $rows = [];
            $now = now();
            foreach (CarbonPeriod::create($first, $last) as $date) {
                $workDate = $date->toDateString();
                if ($existing->has($workDate)) {
                    continue;
                }
                $rows[] = [
                    'calendar_id' => $calendarId,
                    'work_date' => $workDate,
                    'day_type' => in_array($date->dayOfWeek, $workingWeekdays, true) ? 'working' : 'weekend',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                $this->assertCalendarPayrollUnchanged($companyId, $calendarId, $rows[0]['work_date'], $rows[array_key_last($rows)]['work_date']);
                DB::table('hr_work_calendar_days')->insert($rows);
            }

            return count($rows);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function assign(int $companyId, int $calendarId, array $data, User $actor): object
    {
        return DB::transaction(function () use ($companyId, $calendarId, $data, $actor): object {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $calendar = $this->calendar($companyId, $calendarId, $actor, true);
            $employee = HrEmployee::query()->where('company_id', $companyId)
                ->where('status', 'active')->lockForUpdate()->find($data['employee_id']);
            if ($employee === null) {
                throw new DomainException(__('hr_work_calendars.errors.employee_scope'));
            }
            $this->assertDatedBranchCoverage(
                $employee,
                $calendar,
                $data['effective_from'],
                $data['effective_to'] ?? '9999-12-31',
                $this->allowedBranchIds($actor, $company),
            );

            $overlap = DB::table('hr_work_calendar_assignments')
                ->where('employee_id', $employee->getKey())->whereNull('deleted_at')
                ->where('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $data['effective_from']))
                ->lockForUpdate()->exists();
            if ($overlap) {
                throw new DomainException(__('hr_work_calendars.errors.assignment_overlap'));
            }
            $this->assertNoCalculatedPayroll($companyId, [(int) $employee->getKey()], $data['effective_from'], $data['effective_to'] ?? '9999-12-31');

            $id = DB::table('hr_work_calendar_assignments')->insertGetId([
                'employee_id' => $employee->getKey(),
                'calendar_id' => $calendarId,
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('hr_work_calendar_assignments')->find($id);
        }, attempts: 3);
    }

    /**
     * @param  array{expected_effective_from: string, expected_effective_to?: string|null, effective_from: string, effective_to?: string|null, reason: string}  $data
     * @return array{before: object, after: object}
     */
    public function changeAssignment(int $companyId, int $calendarId, int $assignmentId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($companyId, $calendarId, $assignmentId, $data, $actor): array {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $calendar = $this->calendar($companyId, $calendarId, $actor, true);
            $assignment = DB::table('hr_work_calendar_assignments')
                ->where('calendar_id', $calendarId)->whereNull('deleted_at')
                ->where('id', $assignmentId)->lockForUpdate()->first();
            if ($assignment === null) {
                throw new DomainException(__('hr_work_calendars.errors.assignment_unavailable'));
            }
            if ($assignment->effective_from !== $data['expected_effective_from']
                || $assignment->effective_to !== ($data['expected_effective_to'] ?? null)) {
                throw new DomainException(__('hr_work_calendars.errors.assignment_stale'));
            }
            if ($assignment->effective_from === $data['effective_from']
                && $assignment->effective_to === ($data['effective_to'] ?? null)) {
                throw new DomainException(__('hr_work_calendars.errors.assignment_unchanged'));
            }
            $employee = HrEmployee::query()->where('company_id', $companyId)
                ->where('status', 'active')->lockForUpdate()->find($assignment->employee_id);
            if ($employee === null) {
                throw new DomainException(__('hr_work_calendars.errors.employee_scope'));
            }
            $allowedBranchIds = $this->allowedBranchIds($actor, $company);
            $this->assertDatedBranchCoverage(
                $employee,
                $calendar,
                $assignment->effective_from,
                $assignment->effective_to ?? '9999-12-31',
                $allowedBranchIds,
            );
            $this->assertDatedBranchCoverage(
                $employee,
                $calendar,
                $data['effective_from'],
                $data['effective_to'] ?? '9999-12-31',
                $allowedBranchIds,
            );
            $overlap = DB::table('hr_work_calendar_assignments')
                ->where('employee_id', $employee->getKey())->whereNull('deleted_at')
                ->where('id', '!=', $assignmentId)
                ->where('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $data['effective_from']))
                ->lockForUpdate()->exists();
            if ($overlap) {
                throw new DomainException(__('hr_work_calendars.errors.assignment_overlap'));
            }
            $this->assertNoCalculatedPayroll($companyId, [(int) $employee->getKey()], $assignment->effective_from, $assignment->effective_to ?? '9999-12-31');
            $this->assertNoCalculatedPayroll($companyId, [(int) $employee->getKey()], $data['effective_from'], $data['effective_to'] ?? '9999-12-31');

            DB::table('hr_work_calendar_assignments')->where('id', $assignmentId)->update([
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'updated_by' => $actor->getKey(),
                'updated_at' => now(),
            ]);

            return [
                'before' => $assignment,
                'after' => DB::table('hr_work_calendar_assignments')->find($assignmentId),
            ];
        }, attempts: 3);
    }

    /** @param Collection<int, int> $allowedBranchIds */
    private function assertDatedBranchCoverage(HrEmployee $employee, object $calendar, string $from, string $to, Collection $allowedBranchIds): void
    {
        $organizations = DB::table('hr_employee_organization_assignments')
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->where('effective_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->orderBy('effective_from')->lockForUpdate()->get(['branch_id', 'effective_from', 'effective_to']);
        if ($organizations->isEmpty()) {
            if (! $allowedBranchIds->contains((int) $employee->branch_id)
                || ($calendar->branch_id !== null && (int) $calendar->branch_id !== (int) $employee->branch_id)) {
                throw new DomainException(__('hr_work_calendars.errors.employee_scope'));
            }

            return;
        }

        $nextDate = $from;
        $coveredTo = false;
        foreach ($organizations as $index => $organization) {
            if ($coveredTo || ($index === 0 && $organization->effective_from > $nextDate)
                || ($index > 0 && $organization->effective_from !== $nextDate)) {
                throw new DomainException(__('hr_work_calendars.errors.organization_coverage'));
            }
            if (! $allowedBranchIds->contains((int) $organization->branch_id)
                || ($calendar->branch_id !== null && (int) $calendar->branch_id !== (int) $organization->branch_id)) {
                throw new DomainException(__('hr_work_calendars.errors.employee_scope'));
            }
            $lastDate = min((string) ($organization->effective_to ?? $to), $to);
            if ($lastDate >= $to) {
                $coveredTo = true;

                continue;
            }
            $nextDate = CarbonImmutable::parse($lastDate)->addDay()->toDateString();
        }

        if (! $coveredTo) {
            throw new DomainException(__('hr_work_calendars.errors.organization_coverage'));
        }
    }

    private function assertCalendarPayrollUnchanged(int $companyId, int $calendarId, string $from, string $to): void
    {
        $employeeIds = DB::table('hr_work_calendar_assignments')
            ->where('calendar_id', $calendarId)->whereNull('deleted_at')
            ->where('effective_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->distinct()->pluck('employee_id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->assertNoCalculatedPayroll($companyId, $employeeIds, $from, $to);
    }

    /** @param list<int> $employeeIds */
    private function assertNoCalculatedPayroll(int $companyId, array $employeeIds, string $from, string $to): void
    {
        if ($employeeIds === []) {
            return;
        }

        $hasCalculatedSource = DB::table('hr_payslips as payslips')
            ->join('hr_payroll_runs as runs', 'runs.id', '=', 'payslips.payroll_run_id')
            ->join('hr_payroll_periods as periods', 'periods.id', '=', 'runs.payroll_period_id')
            ->where('periods.company_id', $companyId)
            ->whereIn('payslips.employee_id', $employeeIds)
            ->where('periods.period_start', '<=', $to)
            ->where('periods.period_end', '>=', $from)
            ->exists();
        if ($hasCalculatedSource) {
            throw new DomainException(__('hr_work_calendars.errors.payroll_source_locked'));
        }
    }

    /** @return Collection<int, int> */
    private function allowedBranchIds(User $actor, Company $company): Collection
    {
        return $this->scope->allowedBranchQuery($actor, [(string) $company->doc_num])
            ->pluck('branches.id')->map(fn (mixed $id): int => (int) $id);
    }
}
