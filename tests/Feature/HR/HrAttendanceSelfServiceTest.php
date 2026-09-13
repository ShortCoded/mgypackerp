<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrAttendanceEvent;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrShift;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('attendance and hr request permissions are discoverable', function (): void {
    $this->seed(PermissionSeeder::class);

    $permissions = ['hr.employee_attendance.view', 'hr.employee_attendance.manage', 'hr.employee_attendance.correct', 'hr.employee_attendance.export', 'hr.hr_requests.view', 'hr.hr_requests.manage'];

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

        expect($menuTranslations)->toHaveKeys(['hr_employee_attendance', 'hr_requests'])
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
    Permission::findOrCreate('hr.employee_attendance.manage', 'web');
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(['hr.employee_attendance.view', 'hr.employee_attendance.manage']);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
    ];

    $this->actingAs($reviewer)->withSession($session)
        ->post(route('admin.hr.employee-attendance.manual.store'), [
            'employee_doc_num' => $fixture['employee']->doc_num,
            'event_type' => 'check_in',
            'occurred_at' => '2026-09-13 08:00:00',
            'idempotency_key' => (string) Str::uuid(),
            'notes' => 'Approved manual entry',
        ])->assertRedirect();

    $this->withSession($session)
        ->get(route('admin.hr.employee-attendance.index'))
        ->assertOk()
        ->assertSee($fixture['employee']->full_name)
        ->assertSee(__('hr_attendance.actions.check_in'));
});
