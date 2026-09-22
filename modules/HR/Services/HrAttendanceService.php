<?php

namespace Modules\HR\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Services\DateFormatService;
use Modules\HR\Models\HrAttendanceDailyRecord;
use Modules\HR\Models\HrAttendanceEvent;
use Modules\HR\Models\HrAttendanceSession;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeShiftAssignment;
use Modules\HR\Models\HrShift;

class HrAttendanceService
{
    public function __construct(
        private readonly DateFormatService $dates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function statusForUser(User $user): array
    {
        $employee = HrEmployee::query()
            ->with(['branch', 'defaultShift'])
            ->where('user_id', $user->getKey())
            ->first();

        if (! $employee instanceof HrEmployee) {
            return [
                'linked' => false,
                'state' => 'not_linked',
                'attendance_available' => false,
                'can_submit_requests' => false,
                'allowed_actions' => [],
                'recent_events' => [],
            ];
        }

        return $this->statusForEmployee($employee);
    }

    public function employeeForUser(User $user): HrEmployee
    {
        $employee = HrEmployee::query()
            ->with(['branch', 'defaultShift'])
            ->where('user_id', $user->getKey())
            ->first();

        if (! $employee instanceof HrEmployee) {
            throw new DomainException(__('hr_attendance.messages.employee_account_not_linked'));
        }

        if ($employee->status !== 'active') {
            throw new DomainException(__('hr_attendance.messages.employee_inactive'));
        }

        if ($employee->company_id === null) {
            throw new DomainException(__('hr_attendance.messages.employee_company_missing'));
        }

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordSelfServicePunch(User $user, array $data, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $employee = $this->employeeForUser($user);

        if (! $employee->attendance_tracking_enabled) {
            throw new DomainException(__('hr_attendance.messages.tracking_disabled'));
        }

        return $this->recordPunch(
            employee: $employee,
            eventType: (string) $data['event_type'],
            data: $data,
            source: 'self_service',
            actor: $user,
            occurredAt: CarbonImmutable::now(),
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordManualPunch(HrEmployee $employee, User $actor, array $data): array
    {
        return $this->recordPunch(
            employee: $employee->loadMissing(['branch', 'defaultShift']),
            eventType: (string) $data['event_type'],
            data: $data,
            source: 'manual',
            actor: $actor,
            occurredAt: CarbonImmutable::parse((string) $data['occurred_at']),
            ipAddress: null,
            userAgent: null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordBiometricPunch(HrEmployee $employee, User $actor, array $data, CarbonImmutable $occurredAt): array
    {
        return $this->recordPunch(
            employee: $employee->loadMissing(['branch', 'defaultShift']),
            eventType: (string) $data['event_type'],
            data: $data,
            source: 'biometric_import',
            actor: $actor,
            occurredAt: $occurredAt,
            ipAddress: null,
            userAgent: null,
            includeStatus: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function statusForEmployee(HrEmployee $employee): array
    {
        $employee->loadMissing(['branch', 'defaultShift']);
        $openSessionId = DB::table('hr_attendance_open_sessions')
            ->where('employee_id', $employee->getKey())
            ->value('session_id');
        $session = $openSessionId === null
            ? null
            : HrAttendanceSession::query()->with(['events', 'assignedBranch'])->find($openSessionId);
        $lastEvent = $session?->events->last();
        $attendanceAvailable = $employee->status === 'active'
            && $employee->company_id !== null
            && $employee->attendance_tracking_enabled;
        $state = match (true) {
            $employee->status !== 'active' => 'inactive',
            $employee->company_id === null => 'company_missing',
            ! $employee->attendance_tracking_enabled => 'tracking_disabled',
            default => match ($lastEvent?->event_type) {
                HrAttendanceEvent::CheckIn, HrAttendanceEvent::BreakEnd => 'working',
                HrAttendanceEvent::BreakStart => 'on_break',
                default => 'not_checked_in',
            },
        };

        $allowedActions = $attendanceAvailable
            ? match ($state) {
                'working' => [HrAttendanceEvent::BreakStart, HrAttendanceEvent::CheckOut],
                'on_break' => [HrAttendanceEvent::BreakEnd],
                default => [HrAttendanceEvent::CheckIn],
            }
        : [];
        $now = CarbonImmutable::now();
        $metrics = $session instanceof HrAttendanceSession
            ? $this->metrics($session->events->all(), $now)
            : ['break_minutes' => 0, 'worked_minutes' => 0];
        $recentEvents = HrAttendanceEvent::query()
            ->where('employee_id', $employee->getKey())
            ->latest('occurred_at')
            ->limit(12)
            ->get()
            ->map(fn (HrAttendanceEvent $event): array => [
                'type' => $event->event_type,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'geofence_status' => $event->geofence_status,
                'distance_meters' => $event->distance_meters,
            ])->all();

        return [
            'linked' => true,
            'employee' => [
                'doc_num' => $employee->doc_num,
                'name' => $employee->full_name,
                'branch' => $employee->branch?->name,
                'branch_doc_num' => $employee->branch?->doc_num,
            ],
            'state' => $state,
            'attendance_available' => $attendanceAvailable,
            'can_submit_requests' => $employee->status === 'active' && $employee->company_id !== null,
            'session_uuid' => $session?->public_uuid,
            'check_in_at' => $session?->started_at?->toIso8601String(),
            'check_in_display' => $this->dates->formatDateTime($session?->started_at, ''),
            'last_event_at' => $lastEvent?->occurred_at?->toIso8601String(),
            'elapsed_minutes' => $session?->started_at?->diffInMinutes($now) ?? 0,
            'break_minutes' => $metrics['break_minutes'],
            'worked_minutes' => $metrics['worked_minutes'],
            'allowed_actions' => $allowedActions,
            'recent_events' => $recentEvents,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function recordPunch(HrEmployee $employee, string $eventType, array $data, string $source, User $actor, CarbonImmutable $occurredAt, ?string $ipAddress, ?string $userAgent, bool $includeStatus = true): array
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $existing = HrAttendanceEvent::query()
            ->where('employee_id', $employee->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof HrAttendanceEvent) {
            $this->ensureIdempotencyMatches($existing, $eventType);

            return $includeStatus ? $this->statusForEmployee($employee) : [];
        }

        DB::transaction(function () use ($employee, $eventType, $data, $source, $actor, $occurredAt, $ipAddress, $userAgent, $idempotencyKey): void {
            $lockedEmployee = HrEmployee::query()->lockForUpdate()->findOrFail($employee->getKey());
            $existingAfterLock = HrAttendanceEvent::query()
                ->where('employee_id', $lockedEmployee->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingAfterLock instanceof HrAttendanceEvent) {
                $this->ensureIdempotencyMatches($existingAfterLock, $eventType);

                return;
            }

            $openPointer = DB::table('hr_attendance_open_sessions')
                ->where('employee_id', $lockedEmployee->getKey())
                ->lockForUpdate()
                ->first();
            $session = $openPointer === null
                ? null
                : HrAttendanceSession::query()->lockForUpdate()->find($openPointer->session_id);

            if ($eventType === HrAttendanceEvent::CheckIn) {
                if ($session instanceof HrAttendanceSession) {
                    throw new DomainException(__('hr_attendance.messages.already_checked_in'));
                }

                $shift = $this->effectiveShift($lockedEmployee, $occurredAt);
                $session = HrAttendanceSession::query()->create([
                    'employee_id' => $lockedEmployee->getKey(),
                    'company_id' => $lockedEmployee->company_id,
                    'assigned_branch_id' => $lockedEmployee->branch_id,
                    'shift_id' => $shift?->getKey(),
                    'scheduled_start_time' => $shift?->start_time,
                    'scheduled_end_time' => $shift?->end_time,
                    'scheduled_crosses_midnight' => (bool) $shift?->crosses_midnight,
                    'allowed_late_minutes' => (int) $lockedEmployee->allow_late_minutes,
                    'allowed_early_leave_minutes' => (int) $lockedEmployee->allow_early_leave_minutes,
                    'overtime_enabled' => (bool) $lockedEmployee->overtime_enabled,
                    'work_date' => $this->workDate($shift, $occurredAt),
                    'status' => HrAttendanceSession::StatusOpen,
                    'started_at' => $occurredAt,
                    'source' => $source,
                    'created_by' => $actor->getKey(),
                ]);

                DB::table('hr_attendance_open_sessions')->insert([
                    'employee_id' => $lockedEmployee->getKey(),
                    'session_id' => $session->getKey(),
                    'created_at' => now(),
                ]);
            } elseif (! $session instanceof HrAttendanceSession) {
                throw new DomainException(__('hr_attendance.messages.no_open_session'));
            }

            $lastEvent = HrAttendanceEvent::query()
                ->where('session_id', $session->getKey())
                ->latest('occurred_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $this->ensureLegalTransition($lastEvent?->event_type, $eventType);

            if ($lastEvent?->occurred_at !== null && $occurredAt->lessThan($lastEvent->occurred_at)) {
                throw new DomainException(__('hr_attendance.messages.event_before_previous'));
            }

            $assignedBranch = $session->assignedBranch()->first();
            $assessment = $this->locationAssessment($assignedBranch, $data);

            if ($source === 'self_service'
                && $assignedBranch?->attendance_location_policy === 'reject'
                && $assessment['status'] !== 'inside') {
                throw new DomainException(__('hr_attendance.messages.location_rejected'));
            }

            HrAttendanceEvent::query()->create([
                'session_id' => $session->getKey(),
                'employee_id' => $lockedEmployee->getKey(),
                'company_id' => $session->company_id,
                'assigned_branch_id' => $session->assigned_branch_id,
                'actual_branch_id' => $assessment['actual_branch_id'],
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'received_at' => now(),
                'source' => $source,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'accuracy_meters' => $data['accuracy_meters'] ?? null,
                'distance_meters' => $assessment['distance_meters'],
                'geofence_status' => $assessment['status'],
                'location_source' => $data['location_source'] ?? ($source === 'self_service' ? 'browser' : null),
                'idempotency_key' => $idempotencyKey,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'notes' => $data['notes'] ?? null,
                'client_context' => $data['client_context'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            $events = HrAttendanceEvent::query()
                ->where('session_id', $session->getKey())
                ->oldest('occurred_at')
                ->oldest('id')
                ->get()
                ->all();
            $metrics = $this->metrics($events, $occurredAt);
            $closing = $eventType === HrAttendanceEvent::CheckOut;
            $session->update([
                'status' => $closing ? HrAttendanceSession::StatusClosed : HrAttendanceSession::StatusOpen,
                'ended_at' => $closing ? $occurredAt : null,
                'total_break_minutes' => $metrics['break_minutes'],
                'worked_minutes' => $metrics['worked_minutes'],
                'updated_by' => $actor->getKey(),
            ]);

            if ($closing) {
                DB::table('hr_attendance_open_sessions')->where('employee_id', $lockedEmployee->getKey())->delete();
            }

            $this->rebuildDailyRecord($session, $occurredAt);
        });

        return $includeStatus ? $this->statusForEmployee($employee->fresh(['branch', 'defaultShift'])) : [];
    }

    private function ensureIdempotencyMatches(HrAttendanceEvent $existing, string $eventType): void
    {
        if ($existing->event_type !== $eventType) {
            throw new DomainException(__('hr_attendance.messages.idempotency_conflict'));
        }
    }

    private function ensureLegalTransition(?string $previous, string $next): void
    {
        $allowed = match ($previous) {
            null => [HrAttendanceEvent::CheckIn],
            HrAttendanceEvent::CheckIn, HrAttendanceEvent::BreakEnd => [HrAttendanceEvent::BreakStart, HrAttendanceEvent::CheckOut],
            HrAttendanceEvent::BreakStart => [HrAttendanceEvent::BreakEnd],
            default => [],
        };

        if (! in_array($next, $allowed, true)) {
            throw new DomainException(__('hr_attendance.messages.illegal_transition'));
        }
    }

    /**
     * @param  list<HrAttendanceEvent>  $events
     * @return array{break_minutes: int, worked_minutes: int}
     */
    private function metrics(array $events, CarbonInterface $until): array
    {
        $checkIn = null;
        $breakStart = null;
        $breakMinutes = 0;
        $checkOut = null;

        foreach ($events as $event) {
            if ($event->event_type === HrAttendanceEvent::CheckIn) {
                $checkIn = $event->occurred_at;
            } elseif ($event->event_type === HrAttendanceEvent::BreakStart) {
                $breakStart = $event->occurred_at;
            } elseif ($event->event_type === HrAttendanceEvent::BreakEnd && $breakStart !== null) {
                $breakMinutes += $breakStart->diffInMinutes($event->occurred_at);
                $breakStart = null;
            } elseif ($event->event_type === HrAttendanceEvent::CheckOut) {
                $checkOut = $event->occurred_at;
            }
        }

        if ($breakStart !== null && $checkOut === null) {
            $breakMinutes += $breakStart->diffInMinutes($until);
        }

        $end = $checkOut ?? $until;
        $workedMinutes = $checkIn === null ? 0 : (int) max(0, $checkIn->diffInMinutes($end) - $breakMinutes);

        return ['break_minutes' => $breakMinutes, 'worked_minutes' => $workedMinutes];
    }

    private function workDate(?HrShift $shift, CarbonImmutable $occurredAt): string
    {
        if ($shift?->crosses_midnight && filled($shift->end_time) && $occurredAt->format('H:i:s') <= $shift->end_time) {
            return $occurredAt->subDay()->toDateString();
        }

        return $occurredAt->toDateString();
    }

    private function effectiveShift(HrEmployee $employee, CarbonImmutable $occurredAt): ?HrShift
    {
        $assignment = HrEmployeeShiftAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('effective_from', '<=', $occurredAt->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $occurredAt->toDateString()))
            ->latest('effective_from')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($assignment instanceof HrEmployeeShiftAssignment) {
            return HrShift::withTrashed()->find($assignment->shift_id);
        }

        return $employee->defaultShift()->withTrashed()->first();
    }

    private function rebuildDailyRecord(HrAttendanceSession $session, CarbonInterface $calculatedAt): void
    {
        $sessions = HrAttendanceSession::query()
            ->where('employee_id', $session->employee_id)
            ->whereDate('work_date', $session->work_date)
            ->get();

        $firstCheckIn = $sessions->min('started_at');
        $hasOpenSession = $sessions->contains('status', HrAttendanceSession::StatusOpen);
        $lastCheckOut = $hasOpenSession ? null : $sessions->max('ended_at');
        $schedule = $this->scheduleVariance($session, $firstCheckIn, $lastCheckOut);

        HrAttendanceDailyRecord::query()->updateOrCreate(
            ['employee_id' => $session->employee_id, 'work_date' => $session->work_date],
            [
                'company_id' => $session->company_id,
                'branch_id' => $session->assigned_branch_id,
                'shift_id' => $session->shift_id,
                'check_in_at' => $firstCheckIn,
                'check_out_at' => $lastCheckOut,
                'total_break_minutes' => (int) $sessions->sum('total_break_minutes'),
                'worked_minutes' => (int) $sessions->sum('worked_minutes'),
                'late_minutes' => $schedule['late_minutes'],
                'early_leave_minutes' => $schedule['early_leave_minutes'],
                'overtime_minutes' => $schedule['overtime_minutes'],
                'status' => 'present',
                'last_calculated_at' => $calculatedAt,
            ],
        );
    }

    /**
     * @return array{late_minutes: int, early_leave_minutes: int, overtime_minutes: int}
     */
    private function scheduleVariance(HrAttendanceSession $session, ?CarbonInterface $checkIn, ?CarbonInterface $checkOut): array
    {
        if (blank($session->scheduled_start_time) || blank($session->scheduled_end_time) || $checkIn === null) {
            return ['late_minutes' => 0, 'early_leave_minutes' => 0, 'overtime_minutes' => 0];
        }

        $workDate = $session->work_date;
        $scheduledStart = CarbonImmutable::parse($workDate->format('Y-m-d').' '.$session->scheduled_start_time);
        $scheduledEnd = CarbonImmutable::parse($workDate->format('Y-m-d').' '.$session->scheduled_end_time);

        if ($session->scheduled_crosses_midnight || $scheduledEnd->lessThanOrEqualTo($scheduledStart)) {
            $scheduledEnd = $scheduledEnd->addDay();
        }

        $late = $checkIn->greaterThan($scheduledStart)
            ? max(0, (int) $scheduledStart->diffInMinutes($checkIn) - $session->allowed_late_minutes)
            : 0;
        $early = $checkOut !== null && $checkOut->lessThan($scheduledEnd)
            ? max(0, (int) $checkOut->diffInMinutes($scheduledEnd) - $session->allowed_early_leave_minutes)
            : 0;
        $overtime = $session->overtime_enabled && $checkOut !== null && $checkOut->greaterThan($scheduledEnd)
            ? (int) $scheduledEnd->diffInMinutes($checkOut)
            : 0;

        return ['late_minutes' => $late, 'early_leave_minutes' => $early, 'overtime_minutes' => $overtime];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: string, distance_meters: int|null, actual_branch_id: int|null}
     */
    private function locationAssessment(?Branch $branch, array $data): array
    {
        $latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $accuracy = isset($data['accuracy_meters']) ? (float) $data['accuracy_meters'] : null;

        if (! $branch instanceof Branch || $branch->attendance_latitude === null || $branch->attendance_longitude === null) {
            return ['status' => 'unconfigured', 'distance_meters' => null, 'actual_branch_id' => null];
        }

        if ($latitude === null || $longitude === null) {
            return ['status' => 'unavailable', 'distance_meters' => null, 'actual_branch_id' => null];
        }

        if ($accuracy !== null && $accuracy > (int) $branch->attendance_max_accuracy_meters) {
            return ['status' => 'poor_accuracy', 'distance_meters' => null, 'actual_branch_id' => null];
        }

        $distance = $this->distanceMeters($latitude, $longitude, (float) $branch->attendance_latitude, (float) $branch->attendance_longitude);
        $inside = $distance <= (float) $branch->attendance_radius_meters;

        return [
            'status' => $inside ? 'inside' : 'outside',
            'distance_meters' => (int) round($distance),
            'actual_branch_id' => $inside ? $branch->getKey() : null,
        ];
    }

    private function distanceMeters(float $latitude, float $longitude, float $branchLatitude, float $branchLongitude): float
    {
        $earthRadius = 6371000;
        $latitudeDelta = deg2rad($branchLatitude - $latitude);
        $longitudeDelta = deg2rad($branchLongitude - $longitude);
        $value = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($branchLatitude)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($value), sqrt(1 - $value));
    }
}
