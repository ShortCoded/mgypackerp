<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrAttendanceEvent;
use Modules\HR\Models\HrAttendanceSession;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeShiftAssignment;
use Modules\HR\Models\HrShift;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('attendance and hr request permissions are discoverable', function (): void {
    $this->seed(PermissionSeeder::class);

    $permissions = [
        'hr.attendance_settings.view',
        'hr.attendance_settings.manage',
        'hr.shift_assignments.view',
        'hr.shift_assignments.manage',
        'hr.employee_attendance.view',
        'hr.employee_attendance.manage',
        'hr.employee_attendance.correct',
        'hr.employee_attendance.export',
        'hr.hr_requests.view',
        'hr.hr_requests.manage',
    ];

    foreach ($permissions as $permission) {
        expect(Permission::query()->where('name', $permission)->exists())->toBeTrue();
    }

    $hrMenu = collect(require config_path('menu/hr.php'))->firstWhere('label', 'human_resources');
    $routes = collect($hrMenu['children'])->pluck('route');

    expect($routes)
        ->toContain('employee.hr.self-service.index')
        ->toContain('admin.hr.employee-attendance.index')
        ->toContain('admin.hr.hr-requests.index');

    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $registry = app(PermissionRegistryService::class);
        $menuTranslations = require resource_path("lang/{$locale}/menu.php");
        $permissionTranslations = require resource_path("lang/{$locale}/permissions.php");

        expect($menuTranslations)->toHaveKeys(['hr_attendance_settings', 'hr_shift_assignments', 'hr_employee_attendance', 'hr_requests'])
            ->and($permissionTranslations)->toHaveKeys($permissions)
            ->and(__('menu.hr_employee_attendance'))->not->toBe('menu.hr_employee_attendance')
            ->and(__('menu.hr_requests'))->not->toBe('menu.hr_requests');

        foreach ($permissions as $permission) {
            expect($registry->labelForPermission($permission))->not->toBe($permission);
        }
    }
});

function attendanceSelfServiceFixture(array $branchOverrides = []): array
{
    $company = Company::factory()->create();
    $branch = Branch::query()->create([
        'doc_number' => 9101,
        'doc_num' => 'Branch-09101',
        'company_id' => $company->getKey(),
        'name' => 'Attendance Branch',
        'type' => 'administrative',
        'status' => 'active',
        'attendance_latitude' => 30.0444200,
        'attendance_longitude' => 31.2357120,
        'attendance_radius_meters' => 250,
        'attendance_max_accuracy_meters' => 100,
        'attendance_location_policy' => 'reject',
        ...$branchOverrides,
    ]);
    $user = User::factory()->create();
    $shift = HrShift::query()->create([
        'doc_number' => 9101,
        'doc_num' => 'HSH-09101',
        'name' => 'Mobile Shift',
        'start_time' => '08:00:00',
        'end_time' => '09:30:00',
        'break_minutes' => 30,
        'status' => 'active',
    ]);
    $employee = HrEmployee::query()->create([
        'doc_number' => 9101,
        'doc_num' => 'HRE-09101',
        'public_uuid' => (string) Str::uuid(),
        'full_name' => 'Mobile Employee',
        'name' => 'Mobile Employee',
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'user_id' => $user->getKey(),
        'status' => 'active',
        'attendance_tracking_enabled' => true,
        'default_shift_id' => $shift->getKey(),
        'allow_late_minutes' => 5,
        'allow_early_leave_minutes' => 5,
        'overtime_enabled' => true,
    ]);

    return compact('company', 'branch', 'user', 'employee', 'shift');
}

function attendancePunch(string $eventType, string $key): array
{
    return [
        'event_type' => $eventType,
        'idempotency_key' => $key,
        'latitude' => 30.0444200,
        'longitude' => 31.2357120,
        'accuracy_meters' => 12,
        'location_source' => 'browser_geolocation_live',
        'client_context' => ['platform' => 'mobile-test'],
    ];
}

test('linked employee can complete a mobile attendance session with breaks', function (): void {
    $fixture = attendanceSelfServiceFixture();
    Carbon::setTestNow('2026-09-13 08:00:00');

    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), attendancePunch('check_in', (string) Str::uuid()))
        ->assertOk()
        ->assertJsonPath('data.state', 'working');

    Carbon::setTestNow('2026-09-13 08:10:00');
    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), attendancePunch('break_start', (string) Str::uuid()))
        ->assertOk()
        ->assertJsonPath('data.state', 'on_break');

    Carbon::setTestNow('2026-09-13 08:40:00');
    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), attendancePunch('break_end', (string) Str::uuid()))
        ->assertOk()
        ->assertJsonPath('data.state', 'working');

    Carbon::setTestNow('2026-09-13 09:00:00');
    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), attendancePunch('check_out', (string) Str::uuid()))
        ->assertOk()
        ->assertJsonPath('data.state', 'not_checked_in');

    $this->assertDatabaseCount('hr_attendance_events', 4);
    $this->assertDatabaseMissing('hr_attendance_open_sessions', ['employee_id' => $fixture['employee']->getKey()]);
    $this->assertDatabaseHas('hr_attendance_daily_records', [
        'employee_id' => $fixture['employee']->getKey(),
        'total_break_minutes' => 30,
        'worked_minutes' => 30,
    ]);
    expect($fixture['user']->hrEmployee?->is($fixture['employee']))->toBeTrue();
});

test('daily projection calculates shift lateness and overtime', function (): void {
    $fixture = attendanceSelfServiceFixture(['attendance_location_policy' => 'warn']);
    Carbon::setTestNow('2026-09-13 08:10:00');
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendancePunch('check_in', (string) Str::uuid()))->assertOk();

    Carbon::setTestNow('2026-09-13 09:45:00');
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendancePunch('check_out', (string) Str::uuid()))->assertOk();

    $this->assertDatabaseHas('hr_attendance_daily_records', [
        'employee_id' => $fixture['employee']->getKey(),
        'late_minutes' => 5,
        'early_leave_minutes' => 0,
        'overtime_minutes' => 15,
    ]);
});

test('dated shift assignment is resolved for attendance and overlapping history is rejected', function (): void {
    $fixture = attendanceSelfServiceFixture(['attendance_location_policy' => 'warn']);
    $assignedShift = HrShift::query()->create([
        'doc_number' => 9102, 'doc_num' => 'HSH-09102', 'name' => 'Assigned Shift',
        'start_time' => '10:00:00', 'end_time' => '18:00:00', 'break_minutes' => 0, 'status' => 'active',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.shift_assignments.view', 'web');
    Permission::findOrCreate('hr.shift_assignments.manage', 'web');
    $manager = User::factory()->create();
    $manager->givePermissionTo(['hr.shift_assignments.view', 'hr.shift_assignments.manage']);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
    ];

    $payload = [
        'employee_ids' => [$fixture['employee']->getKey()],
        'shift_doc_num' => $assignedShift->doc_num,
        'effective_from' => '2026-09-01',
        'effective_to' => null,
    ];
    $this->actingAs($manager)->withSession($session)->post(route('admin.hr.shift-assignments.store'), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($manager)->withSession($session)->post(route('admin.hr.shift-assignments.store'), $payload)
        ->assertRedirect()->assertSessionHasErrors('assignment');
    $assignmentActivity = Activity::query()->where('action', 'hr.shift_assignments.assign')->sole();
    expect(HrEmployeeShiftAssignment::query()->count())->toBe(1)
        ->and($assignmentActivity->company_id)->toBe($fixture['company']->getKey())
        ->and($assignmentActivity->causer_id)->toBe($manager->getKey())
        ->and($assignmentActivity->subject_type)->toBe((new HrEmployeeShiftAssignment)->getMorphClass())
        ->and($assignmentActivity->properties->get('employee_doc_num'))->toBe($fixture['employee']->doc_num)
        ->and(Activity::query()->where('action', 'hr.shift_assignments.assign')->count())->toBe(1);

    $assignment = HrEmployeeShiftAssignment::query()->sole();
    $this->actingAs($manager)->withSession($session)
        ->patch(route('admin.hr.shift-assignments.update', $assignment), ['effective_to' => '2026-09-15'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $endActivity = Activity::query()->where('action', 'hr.shift_assignments.end')->sole();
    expect($endActivity->company_id)->toBe($fixture['company']->getKey())
        ->and($endActivity->causer_id)->toBe($manager->getKey())
        ->and($endActivity->properties->get('new_effective_to'))->toBe('2026-09-15');
    $this->actingAs($manager)->withSession($session)
        ->post(route('admin.hr.shift-assignments.store'), [...$payload, 'effective_from' => '2026-09-16'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($assignment->refresh()->effective_to?->toDateString())->toBe('2026-09-15')
        ->and(HrEmployeeShiftAssignment::query()->count())->toBe(2);

    Carbon::setTestNow('2026-09-13 10:00:00');
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendancePunch('check_in', (string) Str::uuid()))->assertOk();
    $this->assertDatabaseHas('hr_attendance_sessions', ['employee_id' => $fixture['employee']->getKey(), 'shift_id' => $assignedShift->getKey(), 'scheduled_start_time' => '10:00:00']);
});

test('attendance settings are company scoped and update the authoritative branch geofence', function (): void {
    $fixture = attendanceSelfServiceFixture();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.attendance_settings.view', 'web');
    Permission::findOrCreate('hr.attendance_settings.manage', 'web');
    $manager = User::factory()->create();
    $manager->givePermissionTo(['hr.attendance_settings.view', 'hr.attendance_settings.manage']);
    $session = [OperatingContextService::CompanyIdKey => $fixture['company']->getKey(), OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num];

    $this->actingAs($manager)->withSession($session)
        ->patch(route('admin.hr.attendance-settings.update', $fixture['branch']), [
            'attendance_latitude' => 30.1,
            'attendance_longitude' => 31.2,
            'attendance_radius_meters' => 300,
            'attendance_max_accuracy_meters' => 75,
            'attendance_location_policy' => 'reject',
        ])->assertRedirect()->assertSessionHasNoErrors();

    expect($fixture['branch']->refresh()->attendance_radius_meters)->toBe(300)
        ->and($fixture['branch']->attendance_max_accuracy_meters)->toBe(75)
        ->and($fixture['branch']->attendance_location_policy)->toBe('reject');
});

test('branch-restricted hr users cannot reach same-company attendance or shift data outside their scope', function (): void {
    $fixture = attendanceSelfServiceFixture();
    $otherBranch = Branch::query()->create([
        'doc_number' => 9102,
        'doc_num' => 'Branch-09102',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Restricted Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $otherEmployee = HrEmployee::query()->create([
        'doc_number' => 9102,
        'doc_num' => 'HRE-09102',
        'public_uuid' => (string) Str::uuid(),
        'full_name' => 'Restricted Employee',
        'name' => 'Restricted Employee',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $otherBranch->getKey(),
        'status' => 'active',
    ]);
    $otherAssignment = HrEmployeeShiftAssignment::query()->create([
        'employee_id' => $otherEmployee->getKey(),
        'shift_id' => $fixture['shift']->getKey(),
        'effective_from' => '2026-09-01',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permissions = [
        'hr.attendance_settings.view', 'hr.attendance_settings.manage',
        'hr.shift_assignments.view', 'hr.shift_assignments.manage',
        'hr.employee_attendance.view', 'hr.employee_attendance.correct',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $manager = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'Scoped HR '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => false,
    ]);
    $role->givePermissionTo($permissions);
    $role->companyAccessCompanies()->sync([$fixture['company']->getKey()]);
    $role->branchAccessBranches()->sync([$fixture['branch']->getKey()]);
    $manager->assignRole($role);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
    ];

    $this->actingAs($manager)->withSession($session)
        ->get(route('admin.hr.attendance-settings.index'))
        ->assertOk()
        ->assertSee($fixture['branch']->name)
        ->assertDontSee($otherBranch->name);
    $this->withSession($session)
        ->patch(route('admin.hr.attendance-settings.update', $otherBranch), [
            'attendance_latitude' => 30,
            'attendance_longitude' => 31,
            'attendance_radius_meters' => 100,
            'attendance_max_accuracy_meters' => 50,
            'attendance_location_policy' => 'reject',
        ])->assertNotFound();
    $this->withSession($session)
        ->getJson(route('admin.hr.select2.employees', ['q' => 'Restricted']))
        ->assertOk()
        ->assertJsonMissing(['text' => $otherEmployee->full_name.' / '.$otherEmployee->doc_num]);
    $this->withSession($session)
        ->get(route('admin.hr.shift-assignments.index'))
        ->assertOk()
        ->assertDontSee($otherEmployee->full_name);
    $this->withSession($session)
        ->patch(route('admin.hr.shift-assignments.update', $otherAssignment), ['effective_to' => '2026-09-30'])
        ->assertNotFound();
    $this->withSession($session)
        ->post(route('admin.hr.shift-assignments.store'), [
            'employee_ids' => [$otherEmployee->getKey()],
            'shift_doc_num' => $fixture['shift']->doc_num,
            'effective_from' => '2026-10-01',
        ])->assertSessionHasErrors('employee_ids.0');
    $this->withSession($session)
        ->post(route('admin.hr.employee-attendance.manual.store'), [
            'employee_doc_num' => $otherEmployee->doc_num,
            'event_type' => 'check_in',
            'occurred_at' => '2026-09-13 08:00:00',
            'idempotency_key' => (string) Str::uuid(),
            'notes' => 'Must remain isolated',
        ])->assertNotFound();
});

test('unrestricted hr users retain historical attendance and shift visibility for inactive and null branches', function (): void {
    $fixture = attendanceSelfServiceFixture();
    $inactiveBranch = Branch::query()->create([
        'doc_number' => 9198, 'doc_num' => 'Branch-09198', 'company_id' => $fixture['company']->getKey(),
        'name' => 'Historical Inactive Branch', 'type' => 'administrative', 'status' => 'inactive',
    ]);
    $historicalEmployee = HrEmployee::query()->create([
        'doc_number' => 9198, 'doc_num' => 'HRE-09198', 'public_uuid' => (string) Str::uuid(),
        'full_name' => 'Historical Branch Employee', 'name' => 'Historical Branch Employee',
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $inactiveBranch->getKey(), 'status' => 'active',
    ]);
    $nullBranchEmployee = HrEmployee::query()->create([
        'doc_number' => 9199, 'doc_num' => 'HRE-09199', 'public_uuid' => (string) Str::uuid(),
        'full_name' => 'Historical Null Branch Employee', 'name' => 'Historical Null Branch Employee',
        'company_id' => $fixture['company']->getKey(), 'branch_id' => null, 'status' => 'active',
    ]);
    foreach ([$historicalEmployee, $nullBranchEmployee] as $employee) {
        HrEmployeeShiftAssignment::query()->create([
            'employee_id' => $employee->getKey(), 'shift_id' => $fixture['shift']->getKey(), 'effective_from' => '2025-01-01', 'effective_to' => '2025-12-31',
        ]);
        HrAttendanceSession::query()->create([
            'employee_id' => $employee->getKey(), 'company_id' => $fixture['company']->getKey(),
            'assigned_branch_id' => $employee->branch_id, 'shift_id' => $fixture['shift']->getKey(),
            'work_date' => '2025-06-01', 'status' => HrAttendanceSession::StatusClosed,
            'started_at' => '2025-06-01 08:00:00', 'ended_at' => '2025-06-01 16:00:00', 'worked_minutes' => 480,
        ]);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['hr.employee_attendance.view', 'hr.shift_assignments.view', 'hr.shift_assignments.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $manager = User::factory()->create();
    $manager->givePermissionTo(['hr.employee_attendance.view', 'hr.shift_assignments.view', 'hr.shift_assignments.manage']);
    $session = [OperatingContextService::CompanyIdKey => $fixture['company']->getKey(), OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num];

    $this->actingAs($manager)->withSession($session)
        ->get(route('admin.hr.employee-attendance.index', ['date_from' => '2025-06-01', 'date_to' => '2025-06-01']))
        ->assertOk()->assertSee($historicalEmployee->full_name)->assertSee($nullBranchEmployee->full_name);
    $this->withSession($session)->get(route('admin.hr.shift-assignments.index'))
        ->assertOk()->assertSee($historicalEmployee->full_name)->assertSee($nullBranchEmployee->full_name);
    foreach ([$historicalEmployee, $nullBranchEmployee] as $employee) {
        $assignment = HrEmployeeShiftAssignment::query()->where('employee_id', $employee->getKey())->sole();
        $this->withSession($session)
            ->patch(route('admin.hr.shift-assignments.update', $assignment), ['effective_to' => '2025-11-30'])
            ->assertRedirect()->assertSessionHasNoErrors();
        expect($assignment->refresh()->effective_to?->toDateString())->toBe('2025-11-30');
    }
});

test('attendance punches are idempotent and reject illegal transitions', function (): void {
    $fixture = attendanceSelfServiceFixture(['attendance_location_policy' => 'warn']);
    $key = (string) Str::uuid();

    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendancePunch('check_in', $key))->assertOk();
    $this->actingAs($fixture['user'])->postJson(route('employee.hr.attendance.punch'), attendancePunch('check_in', $key))->assertOk();
    $this->assertDatabaseCount('hr_attendance_events', 1);

    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), attendancePunch('check_in', (string) Str::uuid()))
        ->assertStatus(409);
    $this->assertDatabaseCount('hr_attendance_events', 1);
});

test('strict branch geofence rejects outside mobile location', function (): void {
    $fixture = attendanceSelfServiceFixture();
    $payload = attendancePunch(HrAttendanceEvent::CheckIn, (string) Str::uuid());
    $payload['latitude'] = 31.2001;
    $payload['longitude'] = 29.9187;

    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), $payload)
        ->assertStatus(409)
        ->assertJsonPath('message', __('hr_attendance.messages.location_rejected'));

    $this->assertDatabaseCount('hr_attendance_events', 0);
});

test('strict geofence accepts a location rounded exactly to the configured boundary', function (): void {
    $fixture = attendanceSelfServiceFixture([
        'attendance_latitude' => 0,
        'attendance_longitude' => 0,
        'attendance_radius_meters' => 100,
        'attendance_location_policy' => 'reject',
    ]);
    $payload = attendancePunch(HrAttendanceEvent::CheckIn, (string) Str::uuid());
    $payload['latitude'] = 0;
    $payload['longitude'] = rad2deg(100 / 6371000);

    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), $payload)
        ->assertOk();

    $this->assertDatabaseHas('hr_attendance_events', ['employee_id' => $fixture['employee']->getKey(), 'geofence_status' => 'inside', 'distance_meters' => 100]);
});

test('strict geofence rejects a raw distance just outside the boundary even when storage rounds to the radius', function (): void {
    $fixture = attendanceSelfServiceFixture([
        'attendance_latitude' => 0,
        'attendance_longitude' => 0,
        'attendance_radius_meters' => 100,
        'attendance_location_policy' => 'reject',
    ]);
    $payload = attendancePunch(HrAttendanceEvent::CheckIn, (string) Str::uuid());
    $payload['latitude'] = 0;
    $payload['longitude'] = rad2deg(100.4 / 6371000);

    $this->actingAs($fixture['user'])
        ->postJson(route('employee.hr.attendance.punch'), $payload)
        ->assertStatus(409)
        ->assertJsonPath('message', __('hr_attendance.messages.location_rejected'));

    $this->assertDatabaseCount('hr_attendance_events', 0);
});

test('self service screen is available for a linked employee', function (): void {
    $fixture = attendanceSelfServiceFixture(['attendance_location_policy' => 'warn']);

    $this->actingAs($fixture['user'])
        ->get(route('employee.hr.self-service.index'))
        ->assertOk()
        ->assertSee(__('hr_attendance.actions.check_in'))
        ->assertSee('viewport', false)
        ->assertSee('employee-self-service');

    $this->actingAs($fixture['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('hr_attendance.actions.check_in'));
});

test('hr can record and inspect manual attendance events', function (): void {
    $fixture = attendanceSelfServiceFixture(['attendance_location_policy' => 'reject']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.employee_attendance.view', 'web');
    Permission::findOrCreate('hr.employee_attendance.correct', 'web');
    Permission::findOrCreate('hr.employee_attendance.manage', 'web');
    $managerWithoutCorrection = User::factory()->create();
    $managerWithoutCorrection->givePermissionTo('hr.employee_attendance.manage');
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(['hr.employee_attendance.view', 'hr.employee_attendance.correct']);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
    ];

    $this->actingAs($managerWithoutCorrection)->withSession($session)
        ->post(route('admin.hr.employee-attendance.manual.store'), [
            'employee_doc_num' => $fixture['employee']->doc_num,
            'event_type' => 'check_in',
            'occurred_at' => '2026-09-13 08:00:00',
            'idempotency_key' => (string) Str::uuid(),
            'notes' => 'Must require correction permission',
        ])->assertForbidden();
    expect(Activity::query()->where('action', 'hr.attendance.manual_correction')->exists())->toBeFalse();

    $manualKey = (string) Str::uuid();
    $this->actingAs($reviewer)->withSession($session)
        ->post(route('admin.hr.employee-attendance.manual.store'), [
            'employee_doc_num' => $fixture['employee']->doc_num,
            'event_type' => 'check_in',
            'occurred_at' => '2026-09-13 08:00:00',
            'idempotency_key' => $manualKey,
            'notes' => 'Approved manual entry',
        ])->assertRedirect();

    $this->actingAs($reviewer)->withSession($session)
        ->post(route('admin.hr.employee-attendance.manual.store'), [
            'employee_doc_num' => $fixture['employee']->doc_num,
            'event_type' => 'check_in',
            'occurred_at' => '2026-09-13 08:00:00',
            'idempotency_key' => $manualKey,
            'notes' => 'Approved manual entry',
        ])->assertRedirect();

    $activity = Activity::query()->where('action', 'hr.attendance.manual_correction')->sole();
    expect($activity->company_id)->toBe($fixture['company']->getKey())
        ->and($activity->causer_id)->toBe($reviewer->getKey())
        ->and($activity->subject_type)->toBe((new HrAttendanceEvent)->getMorphClass())
        ->and($activity->properties->get('employee_doc_num'))->toBe($fixture['employee']->doc_num)
        ->and($activity->properties->get('has_correction_reason'))->toBeTrue()
        ->and($activity->properties->toArray())->not->toHaveKeys(['notes', 'latitude', 'longitude']);

    $this->withSession($session)
        ->get(route('admin.hr.employee-attendance.index'))
        ->assertOk()
        ->assertSee($fixture['employee']->full_name)
        ->assertSee(__('hr_attendance.actions.check_in'));
});
