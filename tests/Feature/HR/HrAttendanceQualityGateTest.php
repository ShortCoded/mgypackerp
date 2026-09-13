<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\HR\Models\HrAttendanceDailyRecord;
use Modules\HR\Models\HrAttendanceEvent;
use Modules\HR\Models\HrAttendanceSession;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrShift;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $branchOverrides
 * @param  array<string, mixed>  $employeeOverrides
 * @param  array<string, mixed>  $shiftOverrides
 * @return array<string, mixed>
 */
function attendanceQualityFixture(int $suffix = 1, array $branchOverrides = [], array $employeeOverrides = [], array $shiftOverrides = []): array
{
    $company = Company::factory()->create(['is_main' => $suffix % 2 === 0]);
    $branch = Branch::query()->create([
        'doc_number' => 9400 + $suffix,
        'doc_num' => 'Branch-QA-'.$suffix,
        'company_id' => $company->getKey(),
        'name' => 'Attendance QA Branch '.$suffix,
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
        'attendance_latitude' => 30.0444200,
        'attendance_longitude' => 31.2357120,
        'attendance_radius_meters' => 250,
        'attendance_max_accuracy_meters' => 100,
        'attendance_location_policy' => 'warn',
        ...$branchOverrides,
    ]);
    $user = User::factory()->create();
    $shift = HrShift::query()->create([
        'doc_number' => 9400 + $suffix,
        'doc_num' => 'HSH-QA-'.$suffix,
        'name' => 'Attendance QA Shift '.$suffix,
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'break_minutes' => 30,
        'crosses_midnight' => false,
        'status' => 'active',
        ...$shiftOverrides,
    ]);
    $employee = HrEmployee::query()->create([
        'doc_number' => 9400 + $suffix,
        'doc_num' => 'HRE-QA-'.$suffix,
        'public_uuid' => (string) Str::uuid(),
        'full_name' => 'Attendance QA Employee '.$suffix,
        'name' => 'Attendance QA Employee '.$suffix,
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'user_id' => $user->getKey(),
        'status' => 'active',
        'attendance_tracking_enabled' => true,
        'default_shift_id' => $shift->getKey(),
        'allow_late_minutes' => 5,
        'allow_early_leave_minutes' => 5,
        'overtime_enabled' => true,
        ...$employeeOverrides,
    ]);

    return compact('company', 'branch', 'user', 'shift', 'employee');
}

/** @return array<string, mixed> */
function attendanceQualityPayload(string $eventType, string $key, bool $withLocation = true): array
{
    return array_filter([
        'event_type' => $eventType,
        'idempotency_key' => $key,
        'latitude' => $withLocation ? 30.0444200 : null,
        'longitude' => $withLocation ? 31.2357120 : null,
        'accuracy_meters' => $withLocation ? 12 : null,
        'location_source' => 'browser_geolocation_live',
        'client_context' => ['platform' => 'mobile-qa'],
    ], static fn (mixed $value): bool => $value !== null);
}

/** @return array<string, mixed> */
function attendanceQualityAdminSession(array $fixture): array
{
    return [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
    ];
}

test('attendance endpoints enforce authentication and admin permissions', function (): void {
    $fixture = attendanceQualityFixture();

    $this->get(route('employee.hr.self-service.index'))->assertRedirect(route('login'));
    $this->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', (string) Str::uuid()))->assertUnauthorized();

    $this->actingAs($fixture['user'])
        ->withSession(attendanceQualityAdminSession($fixture))
        ->get(route('admin.hr.employee-attendance.index'))
        ->assertForbidden();
    $this->get(route('admin.hr.employee-attendance.export.csv'))->assertForbidden();
});

test('unlinked inactive and tracking-disabled employees receive safe self-service states', function (): void {
    $unlinked = User::factory()->create();
    $this->actingAs($unlinked)
        ->getJson(route('employee.hr.attendance.status'))
        ->assertOk()
        ->assertJsonPath('data.state', 'not_linked')
        ->assertJsonPath('data.allowed_actions', []);

    $inactive = attendanceQualityFixture(1, [], ['status' => 'inactive']);
    $this->actingAs($inactive['user'])
        ->get(route('employee.hr.self-service.index'))
        ->assertOk()
        ->assertSee(__('hr_attendance.states.inactive'));
    $this->getJson(route('employee.hr.attendance.status'))
        ->assertJsonPath('data.state', 'inactive')
        ->assertJsonPath('data.allowed_actions', []);
    $this->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', (string) Str::uuid()))
        ->assertStatus(409)
        ->assertJsonPath('message', __('hr_attendance.messages.employee_inactive'));

    $disabled = attendanceQualityFixture(2, [], ['attendance_tracking_enabled' => false]);
    $this->actingAs($disabled['user'])
        ->getJson(route('employee.hr.attendance.status'))
        ->assertJsonPath('data.state', 'tracking_disabled')
        ->assertJsonPath('data.can_submit_requests', true)
        ->assertJsonPath('data.allowed_actions', []);
    $this->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', (string) Str::uuid()))
        ->assertStatus(409)
        ->assertJsonPath('message', __('hr_attendance.messages.tracking_disabled'));
});

test('mobile location input is strictly validated', function (): void {
    $fixture = attendanceQualityFixture();

    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), [
            'event_type' => 'check_in',
            'idempotency_key' => (string) Str::uuid(),
            'latitude' => 91,
            'longitude' => -181,
            'accuracy_meters' => -1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude', 'accuracy_meters']);

    $this->postJson(route('employee.hr.attendance.punch'), [
        'event_type' => 'check_in',
        'idempotency_key' => (string) Str::uuid(),
        'latitude' => 30.0444200,
    ])->assertUnprocessable()->assertJsonValidationErrors('longitude');
});

test('warn policy records unavailable outside and poor-accuracy location decisions without trusting the client', function (): void {
    $fixture = attendanceQualityFixture();

    Carbon::setTestNow('2026-09-13 08:00:00');
    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', (string) Str::uuid(), false))
        ->assertOk();

    Carbon::setTestNow('2026-09-13 09:00:00');
    $outside = attendanceQualityPayload('check_out', (string) Str::uuid());
    $outside['latitude'] = 31.2001;
    $outside['longitude'] = 29.9187;
    $outside['geofence_status'] = 'inside';
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), $outside)->assertOk();

    Carbon::setTestNow('2026-09-13 10:00:00');
    $poorAccuracy = attendanceQualityPayload('check_in', (string) Str::uuid());
    $poorAccuracy['accuracy_meters'] = 500;
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), $poorAccuracy)->assertOk();

    expect(HrAttendanceEvent::query()->orderBy('id')->pluck('geofence_status')->all())
        ->toBe(['unavailable', 'outside', 'poor_accuracy'])
        ->and(HrAttendanceEvent::query()->where('geofence_status', 'outside')->value('actual_branch_id'))->toBeNull();
});

test('attendance trusts authenticated employee and server time instead of spoofed client fields', function (): void {
    $fixture = attendanceQualityFixture();
    $other = attendanceQualityFixture(2);
    Carbon::setTestNow('2026-09-13 11:22:33');
    $payload = attendanceQualityPayload('check_in', (string) Str::uuid());
    $payload += [
        'employee_id' => $other['employee']->getKey(),
        'company_id' => $other['company']->getKey(),
        'assigned_branch_id' => $other['branch']->getKey(),
        'occurred_at' => '2001-01-01 00:00:00',
        'distance_meters' => 0,
        'geofence_status' => 'inside',
    ];

    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), $payload)
        ->assertOk();

    $event = HrAttendanceEvent::query()->sole();
    expect($event->employee_id)->toBe($fixture['employee']->getKey())
        ->and($event->company_id)->toBe($fixture['company']->getKey())
        ->and($event->assigned_branch_id)->toBe($fixture['branch']->getKey())
        ->and($event->occurred_at?->toDateTimeString())->toBe('2026-09-13 11:22:33');
});

test('idempotency is employee-scoped and rejects payload changes for a used key', function (): void {
    $first = attendanceQualityFixture();
    $second = attendanceQualityFixture(2);
    $key = (string) Str::uuid();

    $this->actingAs($first['user'])
        ->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', $key))
        ->assertOk();
    $this->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', $key))->assertOk();
    $this->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_out', $key))
        ->assertStatus(409)
        ->assertJsonPath('message', __('hr_attendance.messages.idempotency_conflict'));

    $this->actingAs($second['user'])
        ->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', $key))
        ->assertOk();

    expect(HrAttendanceEvent::query()->where('idempotency_key', $key)->count())->toBe(2);
});

test('break state machine blocks checkout during a break and calculates live and final work minutes', function (): void {
    $fixture = attendanceQualityFixture();
    app(SettingService::class)->set('date_time_format', 'Y|m|d H:i');

    Carbon::setTestNow('2026-09-13 08:00:00');
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', (string) Str::uuid()))->assertOk();
    Carbon::setTestNow('2026-09-13 08:30:00');
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('break_start', (string) Str::uuid()))->assertOk();
    Carbon::setTestNow('2026-09-13 08:45:00');
    $this->actingAs($fixture['user'])->getJson(route('employee.hr.attendance.status'))
        ->assertJsonPath('data.state', 'on_break')
        ->assertJsonPath('data.check_in_display', '2026|09|13 08:00')
        ->assertJsonPath('data.worked_minutes', 30)
        ->assertJsonPath('data.break_minutes', 15);
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_out', (string) Str::uuid()))->assertStatus(409);
    Carbon::setTestNow('2026-09-13 09:00:00');
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('break_end', (string) Str::uuid()))->assertOk();
    Carbon::setTestNow('2026-09-13 10:00:00');
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_out', (string) Str::uuid()))->assertOk();

    $this->assertDatabaseHas('hr_attendance_daily_records', [
        'employee_id' => $fixture['employee']->getKey(),
        'total_break_minutes' => 30,
        'worked_minutes' => 90,
    ]);
});

test('overnight attendance keeps work date and assigned branch snapshots stable', function (): void {
    $fixture = attendanceQualityFixture(
        1,
        ['attendance_location_policy' => 'reject'],
        ['allow_late_minutes' => 0],
        ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true],
    );
    $newBranch = Branch::query()->create([
        'doc_number' => 9499,
        'doc_num' => 'Branch-QA-99',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Transferred Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
        'attendance_latitude' => 25.6872,
        'attendance_longitude' => 32.6396,
        'attendance_location_policy' => 'reject',
    ]);

    Carbon::setTestNow('2026-09-14 00:30:00');
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', (string) Str::uuid()))->assertOk();
    $fixture['employee']->update([
        'branch_id' => $newBranch->getKey(),
        'allow_late_minutes' => 1000,
        'allow_early_leave_minutes' => 1000,
        'overtime_enabled' => false,
    ]);
    $fixture['shift']->update(['start_time' => '00:00:00', 'end_time' => '01:00:00', 'crosses_midnight' => false]);
    Carbon::setTestNow('2026-09-14 06:15:00');
    $this->flushSession();
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_out', (string) Str::uuid()))->assertOk();

    $session = HrAttendanceSession::query()->sole();
    expect($session->employee_id)->toBe($fixture['employee']->getKey())
        ->and($session->assigned_branch_id)->toBe($fixture['branch']->getKey())
        ->and($session->work_date?->toDateString())->toBe('2026-09-13')
        ->and($session->scheduled_start_time)->toBe('22:00:00')
        ->and($session->scheduled_end_time)->toBe('06:00:00')
        ->and($session->scheduled_crosses_midnight)->toBeTrue()
        ->and($session->allowed_late_minutes)->toBe(0)
        ->and($session->overtime_enabled)->toBeTrue();
    $this->assertDatabaseCount('hr_attendance_events', 2);
    expect(HrAttendanceEvent::query()->where('assigned_branch_id', $fixture['branch']->getKey())->count())->toBe(2)
        ->and(HrAttendanceEvent::query()->where('geofence_status', 'inside')->count())->toBe(2);
    $daily = HrAttendanceDailyRecord::query()->sole();
    expect($daily->employee_id)->toBe($fixture['employee']->getKey())
        ->and($daily->work_date?->toDateString())->toBe('2026-09-13')
        ->and($daily->late_minutes)->toBe(150)
        ->and($daily->overtime_minutes)->toBe(15);
});

test('attendance report is tenant-isolated standardized responsive filterable and exportable', function (): void {
    $first = attendanceQualityFixture();
    $second = attendanceQualityFixture(2);
    Carbon::setTestNow('2026-09-13 08:00:00');
    $this->actingAs($first['user'])->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', (string) Str::uuid()))->assertOk();
    $this->actingAs($second['user'])->postJson(route('employee.hr.attendance.punch'), attendanceQualityPayload('check_in', (string) Str::uuid()))->assertOk();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['hr.employee_attendance.view', 'hr.employee_attendance.manage', 'hr.employee_attendance.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(['hr.employee_attendance.view', 'hr.employee_attendance.manage', 'hr.employee_attendance.export']);
    $session = attendanceQualityAdminSession($first);

    $response = $this->actingAs($reviewer)->withSession($session)->get(route('admin.hr.employee-attendance.index', [
        'date_from' => '2026-09-13',
        'date_to' => '2026-09-13',
        'branch' => $first['branch']->doc_num,
    ]));
    $response->assertOk()
        ->assertSee($first['employee']->full_name)
        ->assertDontSee($second['employee']->full_name)
        ->assertSee('admin-report-page', false)
        ->assertSee('report-table-card', false)
        ->assertSee('js-date-picker', false)
        ->assertSee('data-storage-format="Y-m-d"', false)
        ->assertSee('data-attendance-mobile-cards', false);
    $response->assertSee(route('admin.hr.employee-attendance.export.csv', $response->viewData('filters')));
    expect($response->viewData('summary')['session_count'])->toBe(1)
        ->and($response->viewData('summary')['employee_count'])->toBe(1);

    $this->withSession($session)
        ->get(route('admin.hr.employee-attendance.index', ['date_from' => '2026-09-14', 'date_to' => '2026-09-13']))
        ->assertRedirect()
        ->assertSessionHasErrors('date_to');

    $export = $this->withSession($session)->get(route('admin.hr.employee-attendance.export.csv', ['date_from' => '2026-09-13', 'date_to' => '2026-09-13']));
    $export->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($export->streamedContent())->toContain($first['employee']->full_name)->not->toContain($second['employee']->full_name);

    $this->withSession($session)->post(route('admin.hr.employee-attendance.manual.store'), [
        'employee_doc_num' => $second['employee']->doc_num,
        'event_type' => 'check_out',
        'occurred_at' => '2026-09-13 09:00:00',
        'idempotency_key' => (string) Str::uuid(),
        'notes' => 'Cross-company attempt',
    ])->assertRedirect()->assertSessionHasErrors('employee_doc_num');
});
