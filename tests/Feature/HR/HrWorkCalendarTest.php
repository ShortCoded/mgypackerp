<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('authorized company calendar configuration creates dated days and one scoped employee assignment', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 9901, 'doc_num' => 'CAL-BR-9901',
        'name' => 'Calendar Test Branch', 'type' => Branch::TypeAdministrative, 'status' => 'active',
    ]);
    $employee = HrEmployee::query()->create([
        'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
        'doc_number' => 9901, 'doc_num' => 'CAL-EMP-9901', 'employee_code' => 'CAL-E001',
        'full_name' => 'Synthetic Calendar Employee', 'name' => 'Synthetic Calendar Employee',
        'person_type' => 'fixed_employee', 'status' => 'active',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.work_calendars.view', 'web');
    Permission::findOrCreate('hr.work_calendars.manage', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo(['hr.work_calendars.view', 'hr.work_calendars.manage']);
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
    ];

    $this->actingAs($actor)->withSession($session)
        ->get(route('admin.hr.work-calendars.index'))
        ->assertOk();
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.store'), [
            'code' => 'CAL-2026-A', 'name' => 'Synthetic 2026 Calendar', 'branch_doc_num' => $branch->doc_num,
        ])->assertRedirect()->assertSessionHasNoErrors();
    $calendar = DB::table('hr_work_calendars')->where('company_id', $company->getKey())->where('code', 'CAL-2026-A')->sole();
    $this->actingAs($actor)->withSession($session)
        ->get(route('admin.hr.work-calendars.index', ['calendar_id' => $calendar->id]))
        ->assertOk()->assertSee('js-select2-ajax', false);

    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.days.store', $calendar->id), [
            'work_date' => '2026-10-02', 'day_type' => 'holiday_paid', 'label' => 'Synthetic paid holiday',
        ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.days.fill-range', $calendar->id), [
            'range_from' => '2026-10-01', 'range_to' => '2026-10-03', 'working_weekdays' => ['4', '5'],
        ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.assignments.store', $calendar->id), [
            'employee_id' => $employee->getKey(), 'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31',
        ])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hr_work_calendar_days', ['calendar_id' => $calendar->id, 'work_date' => '2026-10-02', 'day_type' => 'holiday_paid']);
    $this->assertDatabaseHas('hr_work_calendar_days', ['calendar_id' => $calendar->id, 'work_date' => '2026-10-01', 'day_type' => 'working']);
    $this->assertDatabaseHas('hr_work_calendar_days', ['calendar_id' => $calendar->id, 'work_date' => '2026-10-03', 'day_type' => 'weekend']);
    $dayId = (int) DB::table('hr_work_calendar_days')->where('calendar_id', $calendar->id)
        ->where('work_date', '2026-10-02')->value('id');
    $this->actingAs($actor)->withSession($session)
        ->patch(route('admin.hr.work-calendars.days.update', [$calendar->id, $dayId]), [
            'expected_day_type' => 'holiday_paid', 'day_type' => 'off',
            'label' => 'Corrected rest day', 'reason' => 'Approved calendar correction',
        ])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hr_work_calendar_days', [
        'id' => $dayId, 'day_type' => 'off', 'label' => 'Corrected rest day',
    ]);
    expect(Activity::query()->where('action', 'hr.work_calendars.day.change')->count())->toBe(1);
    $this->actingAs($actor)->withSession($session)
        ->patch(route('admin.hr.work-calendars.days.update', [$calendar->id, $dayId]), [
            'expected_day_type' => 'holiday_paid', 'day_type' => 'working',
            'reason' => 'Stale calendar correction',
        ])->assertSessionHasErrors('day');
    $this->assertDatabaseHas('hr_work_calendar_assignments', ['calendar_id' => $calendar->id, 'employee_id' => $employee->getKey(), 'effective_from' => '2026-10-01']);
    $assignmentId = (int) DB::table('hr_work_calendar_assignments')
        ->where('calendar_id', $calendar->id)->where('employee_id', $employee->getKey())->value('id');
    $this->actingAs($actor)->withSession($session)
        ->get(route('admin.hr.work-calendars.index', ['calendar_id' => $calendar->id]))
        ->assertOk()->assertSee(route('admin.hr.work-calendars.assignments.update', [$calendar->id, $assignmentId]), false);
    $this->actingAs($actor)->withSession($session)
        ->patch(route('admin.hr.work-calendars.assignments.update', [$calendar->id, $assignmentId]), [
            'expected_effective_from' => '2026-10-01', 'expected_effective_to' => '2026-10-31',
            'effective_from' => '2026-10-01', 'effective_to' => '2026-10-20',
            'reason' => 'Correct assigned calendar period',
        ])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hr_work_calendar_assignments', ['id' => $assignmentId, 'effective_to' => '2026-10-20']);
    expect(Activity::query()->where('action', 'hr.work_calendars.assignment.change')->count())->toBe(1);
    $this->actingAs($actor)->withSession($session)
        ->patch(route('admin.hr.work-calendars.assignments.update', [$calendar->id, $assignmentId]), [
            'expected_effective_from' => '2026-10-01', 'expected_effective_to' => '2026-10-31',
            'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31',
            'reason' => 'Stale assigned calendar period',
        ])->assertSessionHasErrors('assignment');
    $this->actingAs($actor)->withSession($session)
        ->patch(route('admin.hr.work-calendars.assignments.update', [$calendar->id, $assignmentId]), [
            'expected_effective_from' => '2026-10-01', 'expected_effective_to' => '2026-10-20',
            'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31',
            'reason' => 'Restore assigned calendar period',
        ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.assignments.store', $calendar->id), [
            'employee_id' => $employee->getKey(), 'effective_from' => '2026-10-15', 'effective_to' => '2026-11-15',
        ])->assertSessionHasErrors('assignment');
    expect(DB::table('hr_work_calendar_assignments')->where('employee_id', $employee->getKey())->count())->toBe(1);

    $payrollPeriodId = DB::table('hr_payroll_periods')->insertGetId([
        'company_id' => $company->getKey(), 'period_start' => '2026-10-01',
        'period_end' => '2026-11-30', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payrollRunId = DB::table('hr_payroll_runs')->insertGetId([
        'payroll_period_id' => $payrollPeriodId, 'status' => 'draft',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_payslips')->insert([
        'payroll_run_id' => $payrollRunId, 'employee_id' => $employee->getKey(),
        'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.days.store', $calendar->id), [
            'work_date' => '2026-10-04', 'day_type' => 'holiday_paid',
        ])->assertSessionHasErrors('day');
    $this->actingAs($actor)->withSession($session)
        ->patch(route('admin.hr.work-calendars.days.update', [$calendar->id, $dayId]), [
            'expected_day_type' => 'off', 'day_type' => 'working',
            'reason' => 'Posted source correction attempt',
        ])->assertSessionHasErrors('day');
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.assignments.store', $calendar->id), [
            'employee_id' => $employee->getKey(), 'effective_from' => '2026-11-01', 'effective_to' => '2026-11-02',
        ])->assertSessionHasErrors('assignment');
    $this->actingAs($actor)->withSession($session)
        ->patch(route('admin.hr.work-calendars.assignments.update', [$calendar->id, $assignmentId]), [
            'expected_effective_from' => '2026-10-01', 'expected_effective_to' => '2026-10-31',
            'effective_from' => '2026-10-01', 'effective_to' => '2026-10-20',
            'reason' => 'Calculated payroll period correction attempt',
        ])->assertSessionHasErrors('assignment');
    expect(DB::table('hr_work_calendar_days')->where('calendar_id', $calendar->id)->where('work_date', '2026-10-04')->exists())->toBeFalse();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('hr.work_calendars.view');
    $this->actingAs($viewer)->withSession($session)
        ->patch(route('admin.hr.work-calendars.assignments.update', [$calendar->id, $assignmentId]), [
            'expected_effective_from' => '2026-10-01', 'expected_effective_to' => '2026-10-31',
            'effective_from' => '2026-10-01', 'effective_to' => '2026-10-20',
            'reason' => 'Viewer must not change assignment',
        ])->assertForbidden();
    $this->actingAs($viewer)->withSession($session)
        ->post(route('admin.hr.work-calendars.days.store', $calendar->id), [
            'work_date' => '2026-10-03', 'day_type' => 'working',
        ])->assertForbidden();
});

test('a branch restricted calendar viewer cannot see employee assignments from another branch', function (): void {
    $company = Company::factory()->create();
    $branches = collect([1, 2])->map(fn (int $number): Branch => Branch::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 9900 + $number,
        'doc_num' => 'CAL-SCOPE-BR-'.$number, 'name' => 'Calendar scope branch '.$number,
        'type' => Branch::TypeAdministrative, 'status' => 'active',
    ]));
    $calendarId = DB::table('hr_work_calendars')->insertGetId([
        'company_id' => $company->getKey(), 'branch_id' => null,
        'code' => 'CAL-SCOPE', 'name' => 'Company calendar', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($branches as $index => $branch) {
        $employee = HrEmployee::query()->create([
            'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
            'doc_number' => 9900 + $index, 'doc_num' => 'CAL-SCOPE-EMP-'.$index,
            'employee_code' => 'CAL-SCOPE-E'.$index,
            'full_name' => 'Calendar employee '.$index, 'name' => 'Calendar employee '.$index,
            'person_type' => 'fixed_employee', 'status' => 'active',
        ]);
        DB::table('hr_work_calendar_assignments')->insert([
            'employee_id' => $employee->getKey(), 'calendar_id' => $calendarId,
            'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $transferred = HrEmployee::query()->where('doc_num', 'CAL-SCOPE-EMP-0')->sole();
    $transferred->update(['branch_id' => $branches[1]->getKey()]);
    DB::table('hr_employee_organization_assignments')->insert([
        [
            'company_id' => $company->getKey(), 'employee_id' => $transferred->getKey(),
            'branch_id' => $branches[0]->getKey(), 'effective_from' => '2026-01-01',
            'effective_to' => '2026-10-15', 'source_type' => 'initial_verified',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'company_id' => $company->getKey(), 'employee_id' => $transferred->getKey(),
            'branch_id' => $branches[1]->getKey(), 'effective_from' => '2026-10-16',
            'effective_to' => null, 'source_type' => 'transfer',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.work_calendars.view', 'web');
    $viewer = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'Scoped calendar viewer '.Str::random(8), 'guard_name' => 'web',
        'company_access_restricted' => true, 'branch_access_restricted' => true,
    ]);
    $role->givePermissionTo('hr.work_calendars.view');
    $role->companyAccessCompanies()->sync([$company->getKey()]);
    $role->branchAccessBranches()->sync([$branches[0]->getKey()]);
    $viewer->assignRole($role);

    $this->actingAs($viewer)->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
    ])->get(route('admin.hr.work-calendars.index', ['calendar_id' => $calendarId]))
        ->assertOk()->assertSee('CAL-SCOPE-EMP-0')->assertDontSee('CAL-SCOPE-EMP-1')
        ->assertSee(app(DateFormatService::class)->formatDate('2026-10-15', ''))
        ->assertDontSee(app(DateFormatService::class)->formatDate('2026-10-31', ''));
});

test('a verified historical branch may assign its calendar only for its dated employment interval', function (): void {
    $company = Company::factory()->create();
    $source = Branch::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 9911,
        'doc_num' => 'CAL-HISTORY-1', 'name' => 'Historical branch',
        'type' => Branch::TypeAdministrative, 'status' => 'active',
    ]);
    $destination = Branch::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 9912,
        'doc_num' => 'CAL-HISTORY-2', 'name' => 'Current branch',
        'type' => Branch::TypeAdministrative, 'status' => 'active',
    ]);
    $employee = HrEmployee::query()->create([
        'company_id' => $company->getKey(), 'branch_id' => $destination->getKey(),
        'doc_number' => 9911, 'doc_num' => 'CAL-HISTORY-EMP',
        'employee_code' => 'CAL-HISTORY-E1',
        'full_name' => 'Synthetic transferred employee', 'name' => 'Synthetic transferred employee',
        'person_type' => 'fixed_employee', 'status' => 'active',
        'hire_date' => '2026-01-01',
    ]);
    DB::table('hr_employee_organization_assignments')->insert([
        [
            'company_id' => $company->getKey(), 'employee_id' => $employee->getKey(),
            'branch_id' => $source->getKey(), 'effective_from' => '2026-01-01',
            'effective_to' => '2026-09-10', 'source_type' => 'initial_verified',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'company_id' => $company->getKey(), 'employee_id' => $employee->getKey(),
            'branch_id' => $destination->getKey(), 'effective_from' => '2026-09-11',
            'effective_to' => null, 'source_type' => 'transfer',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    $calendarId = DB::table('hr_work_calendars')->insertGetId([
        'company_id' => $company->getKey(), 'branch_id' => $source->getKey(),
        'code' => 'CAL-HISTORY', 'name' => 'Historical schedule', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $destinationCalendarId = DB::table('hr_work_calendars')->insertGetId([
        'company_id' => $company->getKey(), 'branch_id' => $destination->getKey(),
        'code' => 'CAL-DESTINATION', 'name' => 'Destination schedule', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherEmployee = HrEmployee::query()->create([
        'company_id' => $company->getKey(), 'branch_id' => $destination->getKey(),
        'doc_number' => 9913, 'doc_num' => 'CAL-HISTORY-OTHER',
        'employee_code' => 'CAL-HISTORY-E2',
        'full_name' => 'Destination only employee', 'name' => 'Destination only employee',
        'person_type' => 'fixed_employee', 'status' => 'active',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.work_calendars.manage', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo('hr.work_calendars.manage');
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
    ];

    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.assignments.store', $calendarId), [
            'employee_id' => $employee->getKey(),
            'effective_from' => '2026-09-01', 'effective_to' => '2026-09-10',
        ])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hr_work_calendar_assignments', [
        'calendar_id' => $calendarId, 'employee_id' => $employee->getKey(),
        'effective_from' => '2026-09-01', 'effective_to' => '2026-09-10',
    ]);
    $historicalAssignmentId = (int) DB::table('hr_work_calendar_assignments')
        ->where('calendar_id', $calendarId)->where('employee_id', $employee->getKey())->value('id');
    $this->actingAs($actor)->withSession($session)
        ->patch(route('admin.hr.work-calendars.assignments.update', [$calendarId, $historicalAssignmentId]), [
            'expected_effective_from' => '2026-09-01', 'expected_effective_to' => '2026-09-10',
            'effective_from' => '2026-09-01', 'effective_to' => '2026-09-11',
            'reason' => 'Attempt assignment past branch transfer',
        ])->assertSessionHasErrors('assignment');
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.assignments.store', $calendarId), [
            'employee_id' => $employee->getKey(),
            'effective_from' => '2026-09-11', 'effective_to' => '2026-09-30',
        ])->assertSessionHasErrors('assignment');

    Permission::findOrCreate('hr.work_calendars.view', 'web');
    $historicalViewer = User::factory()->create();
    $historicalRole = Role::query()->create([
        'name' => 'Historical calendar viewer '.Str::random(8), 'guard_name' => 'web',
        'company_access_restricted' => true, 'branch_access_restricted' => true,
    ]);
    $historicalRole->givePermissionTo('hr.work_calendars.view');
    $historicalRole->companyAccessCompanies()->sync([$company->getKey()]);
    $historicalRole->branchAccessBranches()->sync([$source->getKey()]);
    $historicalViewer->assignRole($historicalRole);
    $this->actingAs($historicalViewer)->withSession($session)
        ->get(route('admin.hr.work-calendars.index', ['calendar_id' => $calendarId]))
        ->assertOk()->assertSee($employee->doc_num);

    DB::table('hr_employee_organization_assignments')
        ->where('employee_id', $employee->getKey())
        ->where('branch_id', $source->getKey())
        ->update(['effective_to' => '2026-09-30']);
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.work-calendars.assignments.store', $calendarId), [
            'employee_id' => $employee->getKey(),
            'effective_from' => '2026-09-11', 'effective_to' => '2026-09-20',
        ])->assertSessionHasErrors('assignment');
    DB::table('hr_work_calendar_assignments')->insert([
        'employee_id' => $employee->getKey(), 'calendar_id' => $calendarId,
        'effective_from' => '2026-09-11', 'effective_to' => '2026-09-20',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($historicalViewer)->withSession($session)
        ->get(route('admin.hr.work-calendars.index', ['calendar_id' => $calendarId]))
        ->assertOk()->assertSee($employee->doc_num)
        ->assertDontSee(app(DateFormatService::class)->formatDate('2026-09-20', ''));

    $historicalRole->givePermissionTo('hr.work_calendars.manage');
    $this->actingAs($historicalViewer)->withSession($session)
        ->getJson(route('admin.hr.work-calendars.select2.employees', [
            'calendar' => $calendarId, 'q' => $employee->doc_num,
        ]))->assertOk()->assertJsonFragment(['id' => (string) $employee->getKey()]);
    $this->actingAs($historicalViewer)->withSession($session)
        ->getJson(route('admin.hr.work-calendars.select2.employees', [
            'calendar' => $calendarId, 'q' => $otherEmployee->doc_num,
        ]))->assertOk()->assertJsonMissing(['id' => (string) $otherEmployee->getKey()]);
    $this->actingAs($historicalViewer)->withSession($session)
        ->getJson(route('admin.hr.work-calendars.select2.employees', $destinationCalendarId))
        ->assertNotFound();
});
