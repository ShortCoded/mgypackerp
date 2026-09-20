<?php

namespace Modules\HR\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrLeaveType;

class HrEmployeeRequestService
{
    public function __construct(
        private readonly HrAttendanceService $attendance,
        private readonly OperatingScopeAccessService $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForUser(User $user, array $data): HrEmployeeServiceRequest
    {
        $employee = $this->attendance->employeeForUser($user);

        return DB::transaction(function () use ($employee, $user, $data): HrEmployeeServiceRequest {
            $lockedEmployee = HrEmployee::query()->lockForUpdate()->findOrFail($employee->getKey());
            $currencyId = null;

            if (filled($data['currency_doc_num'] ?? null)) {
                $currencyId = Currency::query()
                    ->where('company_id', $lockedEmployee->company_id)
                    ->where('doc_num', $data['currency_doc_num'])
                    ->value('id');
            }

            $payload = $data['payload'] ?? null;
            if ($data['request_type'] === 'leave') {
                $payload = $this->validatedLeavePayload($lockedEmployee, $data);
            }

            return HrEmployeeServiceRequest::query()->create([
                'employee_id' => $lockedEmployee->getKey(),
                'company_id' => $lockedEmployee->company_id,
                'branch_id' => $lockedEmployee->branch_id,
                'request_type' => $data['request_type'],
                'subject' => $data['subject'] ?? null,
                'details' => $data['details'],
                'requested_from' => $data['requested_from'] ?? null,
                'requested_to' => $data['requested_to'] ?? null,
                'requested_minutes' => $data['requested_minutes'] ?? null,
                'amount' => $data['amount'] ?? null,
                'currency_id' => $currencyId,
                'payload' => $payload,
                'status' => HrEmployeeServiceRequest::StatusSubmitted,
                'submitted_at' => now(),
                'created_by' => $user->getKey(),
            ]);
        });
    }

    public function cancelForUser(User $user, HrEmployeeServiceRequest $request): HrEmployeeServiceRequest
    {
        $employee = $this->attendance->employeeForUser($user);

        if ((int) $request->employee_id !== (int) $employee->getKey()) {
            throw new DomainException(__('hr_requests.messages.not_owned'));
        }

        return DB::transaction(function () use ($request, $user): HrEmployeeServiceRequest {
            $locked = HrEmployeeServiceRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->status !== HrEmployeeServiceRequest::StatusSubmitted) {
                throw new DomainException(__('hr_requests.messages.cannot_cancel'));
            }

            $locked->update([
                'status' => HrEmployeeServiceRequest::StatusCancelled,
                'resolved_at' => now(),
                'updated_by' => $user->getKey(),
            ]);

            return $locked->refresh();
        });
    }

    public function review(HrEmployeeServiceRequest $request, User $reviewer, string $decision, ?string $notes): HrEmployeeServiceRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $decision, $notes): HrEmployeeServiceRequest {
            $locked = HrEmployeeServiceRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->status !== HrEmployeeServiceRequest::StatusSubmitted) {
                throw new DomainException(__('hr_requests.messages.already_resolved'));
            }

            if ((int) $locked->created_by === (int) $reviewer->getKey()) {
                throw new DomainException(__('hr_requests.messages.self_review_not_allowed'));
            }

            $company = Company::query()->findOrFail($locked->company_id);
            $canAccessBranch = $locked->branch_id === null
                ? $this->scope->hasUnrestrictedBranchAccess($reviewer)
                : $this->scope->allowedBranchQuery($reviewer, [(string) $company->doc_num])
                    ->where('branches.id', $locked->branch_id)
                    ->exists();

            if (! $canAccessBranch) {
                throw new DomainException(__('hr_requests.messages.branch_scope_invalid'));
            }

            $updates = [
                'status' => $decision,
                'resolved_at' => now(),
                'resolved_by' => $reviewer->getKey(),
                'resolution_notes' => $notes,
                'updated_by' => $reviewer->getKey(),
            ];

            if ($decision === HrEmployeeServiceRequest::StatusApproved && $locked->request_type === 'leave') {
                $payload = $this->consumeLeaveBalance($locked);
                $payload = $this->snapshotLeavePaymentStatus($payload);
                $updates['payload'] = $this->persistCanonicalLeave($locked, $payload, $reviewer);
            }

            $locked->update($updates);

            return $locked->refresh();
        });
    }

    /** @return list<array<string, mixed>> */
    public function leaveOptionsForEmployee(HrEmployee $employee): array
    {
        return HrLeaveType::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function (HrLeaveType $type) use ($employee): array {
                $balanceRows = DB::table('hr_leave_balances')
                    ->where('employee_id', $employee->getKey())
                    ->where('leave_type_id', $type->getKey())
                    ->orderByDesc('balance_year')
                    ->get();

                if ($balanceRows->isEmpty()) {
                    $balanceRows = collect([(object) ['balance_year' => (int) now()->year, 'current_balance' => 0]]);
                }

                return [
                    'code' => $type->code,
                    'name' => $type->name,
                    'requires_balance' => $type->requiresBalance(),
                    'balances' => $balanceRows->map(function (object $balance) use ($employee, $type): array {
                        $year = (int) $balance->balance_year;
                        $current = (float) $balance->current_balance;
                        $pending = $this->pendingLeaveDays((int) $employee->getKey(), (int) $type->getKey(), $year);

                        return [
                            'year' => $year,
                            'current' => $current,
                            'pending' => $pending,
                            'available' => $current - $pending,
                        ];
                    })->all(),
                ];
            })
            ->all();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validatedLeavePayload(HrEmployee $employee, array $data): array
    {
        $leaveType = HrLeaveType::query()
            ->where('code', data_get($data, 'payload.leave_type'))
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (! $leaveType instanceof HrLeaveType) {
            throw new DomainException(__('hr_requests.messages.leave_type_unavailable'));
        }

        $from = CarbonImmutable::parse($data['requested_from']);
        $to = CarbonImmutable::parse($data['requested_to']);
        $overlappingRequestExists = HrEmployeeServiceRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('request_type', 'leave')
            ->whereIn('status', [HrEmployeeServiceRequest::StatusSubmitted, HrEmployeeServiceRequest::StatusApproved])
            ->whereDate('requested_from', '<=', $to->toDateString())
            ->whereDate('requested_to', '>=', $from->toDateString())
            ->exists();

        if ($overlappingRequestExists) {
            throw new DomainException(__('hr_requests.messages.overlapping_leave_request'));
        }

        if ($from->diffInDays($to) > 365) {
            throw new DomainException(__('hr_requests.messages.leave_range_too_long'));
        }

        $chargeableDates = $this->chargeableLeaveDates($employee, $from, $to);
        $days = count($chargeableDates);

        if ($days === 0) {
            throw new DomainException(__('hr_requests.messages.no_chargeable_leave_days'));
        }

        if ($leaveType->requiresBalance()) {
            if ($from->year !== $to->year) {
                throw new DomainException(__('hr_requests.messages.leave_single_year'));
            }

            $balance = DB::table('hr_leave_balances')
                ->where('employee_id', $employee->getKey())
                ->where('leave_type_id', $leaveType->getKey())
                ->where('balance_year', $from->year)
                ->lockForUpdate()
                ->first();
            $available = (float) ($balance?->current_balance ?? 0)
                - $this->pendingLeaveDays((int) $employee->getKey(), (int) $leaveType->getKey(), $from->year);

            if ($balance === null || $available < $days) {
                throw new DomainException(__('hr_requests.messages.insufficient_leave_balance'));
            }
        }

        return [
            'leave_type' => $leaveType->code,
            'leave_type_id' => $leaveType->getKey(),
            'leave_type_name' => $leaveType->name,
            'leave_days' => $days,
            'chargeable_dates' => $chargeableDates,
            'balance_year' => $from->year,
            'requires_balance' => $leaveType->requiresBalance(),
            'payment_status' => $leaveType->isPaid() ? 'paid' : 'unpaid',
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function snapshotLeavePaymentStatus(array $payload): array
    {
        if (in_array($payload['payment_status'] ?? null, ['paid', 'unpaid'], true)) {
            return $payload;
        }

        $leaveType = HrLeaveType::withTrashed()->find($payload['leave_type_id'] ?? 0);
        if ($leaveType instanceof HrLeaveType) {
            $payload['payment_status'] = $leaveType->isPaid() ? 'paid' : 'unpaid';
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function consumeLeaveBalance(HrEmployeeServiceRequest $request): array
    {
        $payload = $request->payload ?? [];
        if (! (bool) ($payload['requires_balance'] ?? false) || isset($payload['leave_balance_ledger_id'])) {
            return $payload;
        }

        $year = CarbonImmutable::parse($request->requested_from)->year;
        $days = (float) ($payload['leave_days'] ?? 0);
        $balance = DB::table('hr_leave_balances')
            ->where('employee_id', $request->employee_id)
            ->where('leave_type_id', $payload['leave_type_id'] ?? 0)
            ->where('balance_year', $year)
            ->lockForUpdate()
            ->first();

        if ($balance === null || (float) $balance->current_balance < $days) {
            throw new DomainException(__('hr_requests.messages.insufficient_leave_balance'));
        }

        $ledgerId = DB::table('hr_leave_balance_ledger')->insertGetId([
            'leave_balance_id' => $balance->id,
            'entry_type' => 'leave_request',
            'amount' => -$days,
            'notes' => 'employee_service_request:'.$request->public_uuid,
            'posted_at' => now(),
        ]);
        DB::table('hr_leave_balances')->where('id', $balance->id)->update([
            'current_balance' => (float) $balance->current_balance - $days,
            'updated_at' => now(),
        ]);

        $payload['leave_balance_ledger_id'] = $ledgerId;

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function persistCanonicalLeave(HrEmployeeServiceRequest $request, array $payload, User $reviewer): array
    {
        if (isset($payload['canonical_leave_request_id'])) {
            return $payload;
        }

        $leaveRequestId = DB::table('hr_leave_requests')->insertGetId([
            'employee_id' => $request->employee_id,
            'leave_type_id' => $payload['leave_type_id'],
            'status' => 'approved',
            'payment_status' => in_array($payload['payment_status'] ?? null, ['paid', 'unpaid'], true)
                ? $payload['payment_status']
                : throw new DomainException(__('hr_requests.messages.leave_type_unavailable')),
            'reason' => trim($request->details.' [employee_service_request:'.$request->public_uuid.']'),
            'created_by' => $request->created_by,
            'updated_by' => $reviewer->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        $days = collect($payload['chargeable_dates'] ?? [])->map(fn (mixed $date): array => [
            'leave_request_id' => $leaveRequestId,
            'leave_date' => (string) $date,
            'day_fraction' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($days !== []) {
            DB::table('hr_leave_request_days')->insert($days);
        }

        $payload['canonical_leave_request_id'] = $leaveRequestId;

        return $payload;
    }

    /** @return list<string> */
    private function chargeableLeaveDates(HrEmployee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $assignments = DB::table('hr_work_calendar_assignments')
            ->join('hr_work_calendars', 'hr_work_calendars.id', '=', 'hr_work_calendar_assignments.calendar_id')
            ->where('hr_work_calendar_assignments.employee_id', $employee->getKey())
            ->where('hr_work_calendars.status', 'active')
            ->whereNull('hr_work_calendar_assignments.deleted_at')
            ->whereNull('hr_work_calendars.deleted_at')
            ->where('hr_work_calendar_assignments.effective_from', '<=', $to->toDateString())
            ->where(fn ($query) => $query->whereNull('hr_work_calendar_assignments.effective_to')
                ->orWhere('hr_work_calendar_assignments.effective_to', '>=', $from->toDateString()))
            ->orderByDesc('hr_work_calendar_assignments.effective_from')
            ->get([
                'hr_work_calendar_assignments.calendar_id',
                'hr_work_calendar_assignments.effective_from',
                'hr_work_calendar_assignments.effective_to',
            ]);
        $calendarIds = $assignments->pluck('calendar_id')->unique()->all();
        $calendarDays = $calendarIds === []
            ? collect()
            : DB::table('hr_work_calendar_days')
                ->whereIn('calendar_id', $calendarIds)
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
                ->get(['calendar_id', 'work_date', 'day_type'])
                ->keyBy(fn (object $day): string => $day->calendar_id.'|'.$day->work_date);
        $nonWorkingDayTypes = ['holiday', 'weekend', 'non_working', 'off'];
        $dates = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $dateString = $date->toDateString();
            $assignment = $assignments->first(fn (object $row): bool => $row->effective_from <= $dateString
                && ($row->effective_to === null || $row->effective_to >= $dateString));
            $calendarDay = $assignment === null ? null : $calendarDays->get($assignment->calendar_id.'|'.$dateString);

            if ($calendarDay !== null && in_array(strtolower((string) $calendarDay->day_type), $nonWorkingDayTypes, true)) {
                continue;
            }

            $dates[] = $dateString;
        }

        return $dates;
    }

    private function pendingLeaveDays(int $employeeId, int $leaveTypeId, int $year): float
    {
        return (float) HrEmployeeServiceRequest::query()
            ->where('employee_id', $employeeId)
            ->where('request_type', 'leave')
            ->where('status', HrEmployeeServiceRequest::StatusSubmitted)
            ->whereYear('requested_from', $year)
            ->get(['payload'])
            ->filter(fn (HrEmployeeServiceRequest $request): bool => (int) data_get($request->payload, 'leave_type_id') === $leaveTypeId)
            ->sum(fn (HrEmployeeServiceRequest $request): float => (float) data_get($request->payload, 'leave_days', 0));
    }
}
