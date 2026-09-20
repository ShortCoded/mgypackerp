<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrLeaveType;
use Modules\HR\Services\HrEmployeeRequestService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function employeeRequestFixture(): array
{
    $company = Company::factory()->create();
    $branch = Branch::query()->create([
        'doc_number' => 9201,
        'doc_num' => 'Branch-09201',
        'company_id' => $company->getKey(),
        'name' => 'Requests Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $user = User::factory()->create();
    $employee = HrEmployee::query()->create([
        'doc_number' => 9201,
        'doc_num' => 'HRE-09201',
        'public_uuid' => (string) Str::uuid(),
        'full_name' => 'Request Employee',
        'name' => 'Request Employee',
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'user_id' => $user->getKey(),
        'status' => 'active',
    ]);
    HrLeaveType::query()->create(['code' => 'annual', 'name' => 'Annual Leave', 'status' => 'active', 'metadata' => ['requires_balance' => false]]);

    return compact('company', 'branch', 'user', 'employee');
}

test('employee can submit and cancel a self service request', function (): void {
    $fixture = employeeRequestFixture();

    $this->actingAs($fixture['user'])
        ->post(route('employee.hr.requests.store'), [
            'request_type' => 'leave',
            'subject' => 'Annual leave',
            'details' => 'Family commitment',
            'requested_from' => '2026-09-20',
            'requested_to' => '2026-09-22',
            'payload' => ['leave_type' => 'annual'],
        ])->assertRedirect();

    $request = HrEmployeeServiceRequest::query()->sole();
    expect($request->employee_id)->toBe($fixture['employee']->getKey())
        ->and($request->status)->toBe(HrEmployeeServiceRequest::StatusSubmitted);
    $submitActivity = Activity::query()->where('action', 'hr.requests.submit')->sole();
    expect($submitActivity->company_id)->toBe($fixture['company']->getKey())
        ->and($submitActivity->causer_id)->toBe($fixture['user']->getKey())
        ->and($submitActivity->subject_id)->toBe($request->getKey())
        ->and($submitActivity->properties->get('request_type'))->toBe('leave')
        ->and($submitActivity->properties->toArray())->not->toHaveKeys(['details', 'notes', 'amount', 'payload']);

    $this->actingAs($fixture['user'])
        ->patch(route('employee.hr.requests.cancel', $request))
        ->assertRedirect();

    $cancelActivity = Activity::query()->where('action', 'hr.requests.cancel')->sole();
    expect($request->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusCancelled)
        ->and($cancelActivity->company_id)->toBe($fixture['company']->getKey())
        ->and($cancelActivity->causer_id)->toBe($fixture['user']->getKey())
        ->and($cancelActivity->subject_id)->toBe($request->getKey());
});

test('request transition rolls back when its required lifecycle audit cannot be stored', function (): void {
    $fixture = employeeRequestFixture();
    $activityTable = config('activitylog.table_name', 'activity_log');
    $deduplicationIndex = collect(Schema::connection(config('activitylog.database_connection'))->getIndexes($activityTable))
        ->firstWhere('name', 'activity_log_deduplication_key_unique');
    DB::unprepared("CREATE TRIGGER fail_hr_request_audit BEFORE INSERT ON {$activityTable} WHEN NEW.action = 'hr.requests.submit' BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END");
    $failure = null;

    try {
        $this->withoutExceptionHandling()
            ->actingAs($fixture['user'])
            ->post(route('employee.hr.requests.store'), [
                'request_type' => 'device_asset',
                'subject' => 'Work device',
                'details' => 'Laptop for work',
                'payload' => ['asset_type' => 'laptop'],
            ]);
    } catch (QueryException $exception) {
        $failure = $exception;
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_hr_request_audit');
    }

    expect($deduplicationIndex)->not->toBeNull()
        ->and($deduplicationIndex['unique'])->toBeTrue()
        ->and($failure)->toBeInstanceOf(QueryException::class)
        ->and(HrEmployeeServiceRequest::query()->count())->toBe(0)
        ->and(Activity::query()->where('action', 'hr.requests.submit')->exists())->toBeFalse();
});

test('authorized hr reviewer can approve a submitted request', function (): void {
    $fixture = employeeRequestFixture();
    $employeeRequest = HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'request_type' => 'device_asset',
        'details' => 'Laptop for field work',
        'status' => 'submitted',
        'submitted_at' => now(),
        'created_by' => $fixture['user']->getKey(),
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permission = Permission::findOrCreate('hr.hr_requests.manage', 'web');
    $reviewer = User::factory()->create();
    $role = Role::query()->create(['name' => 'HR Reviewer '.Str::random(5), 'guard_name' => 'web']);
    $reviewer->assignRole($role);
    $role->givePermissionTo($permission);
    $role->companyAccessCompanies()->attach($fixture['company']->getKey());

    $this->actingAs($reviewer)
        ->withSession([
            OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
            OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        ])
        ->patch(route('admin.hr.hr-requests.review', $employeeRequest), ['decision' => 'approved', 'resolution_notes' => 'Issued'])
        ->assertRedirect();

    $activity = Activity::query()->where('action', 'hr.requests.approve')->sole();
    expect($employeeRequest->refresh()->status)->toBe('approved')
        ->and($employeeRequest->resolved_by)->toBe($reviewer->getKey())
        ->and($activity->company_id)->toBe($fixture['company']->getKey())
        ->and($activity->causer_id)->toBe($reviewer->getKey())
        ->and($activity->subject_id)->toBe($employeeRequest->getKey())
        ->and($activity->properties->toArray())->not->toHaveKeys(['details', 'resolution_notes', 'notes']);
});

test('rejection requires a resolution note', function (): void {
    $fixture = employeeRequestFixture();
    $request = HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'request_type' => 'other',
        'details' => 'Request details',
        'status' => 'submitted',
        'submitted_at' => now(),
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.hr_requests.manage', 'web');
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo('hr.hr_requests.manage');

    $this->actingAs($reviewer)
        ->patch(route('admin.hr.hr-requests.review', $request), ['decision' => 'rejected'])
        ->assertSessionHasErrors('resolution_notes');

    expect(Activity::query()->where('action', 'hr.requests.reject')->exists())->toBeFalse();
});

test('balance controlled leave reserves pending availability and approval consumes exactly once', function (): void {
    $fixture = employeeRequestFixture();
    $leaveType = HrLeaveType::query()->create(['code' => 'balance-leave', 'name' => 'Balance Leave', 'status' => 'active', 'metadata' => ['requires_balance' => true]]);
    DB::table('hr_leave_balances')->insert([
        'employee_id' => $fixture['employee']->getKey(), 'leave_type_id' => $leaveType->getKey(), 'balance_year' => 2026,
        'opening_balance' => 3, 'current_balance' => 3, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_balances')->insert([
        'employee_id' => $fixture['employee']->getKey(), 'leave_type_id' => $leaveType->getKey(), 'balance_year' => 2025,
        'opening_balance' => 2, 'current_balance' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $calendarId = DB::table('hr_work_calendars')->insertGetId([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'code' => 'CAL-2026',
        'name' => '2026 Calendar',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_work_calendar_assignments')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'calendar_id' => $calendarId,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_work_calendar_days')->insert([
        'calendar_id' => $calendarId,
        'work_date' => '2026-09-21',
        'day_type' => 'holiday',
        'label' => 'Holiday',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payload = [
        'request_type' => 'leave', 'details' => 'Balance leave', 'requested_from' => '2026-09-20',
        'requested_to' => '2026-09-22', 'payload' => ['leave_type' => 'balance-leave'],
    ];
    $this->actingAs($fixture['user'])->post(route('employee.hr.requests.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($fixture['user'])->post(route('employee.hr.requests.store'), $payload)->assertSessionHasErrors('request');

    $employeeRequest = HrEmployeeServiceRequest::query()->where('request_type', 'leave')->sole();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.hr_requests.manage', 'web');
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo('hr.hr_requests.manage');
    $session = [OperatingContextService::CompanyIdKey => $fixture['company']->getKey(), OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num];

    $this->actingAs($reviewer)->withSession($session)
        ->patch(route('admin.hr.hr-requests.review', $employeeRequest), ['decision' => 'approved'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->withSession($session)
        ->patch(route('admin.hr.hr-requests.review', $employeeRequest), ['decision' => 'approved'])
        ->assertSessionHasErrors('request');

    expect((float) DB::table('hr_leave_balances')->where('balance_year', 2026)->value('current_balance'))->toBe(1.0)
        ->and(DB::table('hr_leave_balance_ledger')->count())->toBe(1)
        ->and((float) DB::table('hr_leave_balance_ledger')->value('amount'))->toBe(-2.0)
        ->and(DB::table('hr_leave_requests')->count())->toBe(1)
        ->and(DB::table('hr_leave_request_days')->orderBy('leave_date')->pluck('leave_date')->all())
        ->toBe(['2026-09-20', '2026-09-22']);
    $balanceActivity = Activity::query()->where('action', 'hr.leave_balances.consume')->sole();
    expect($balanceActivity->company_id)->toBe($fixture['company']->getKey())
        ->and($balanceActivity->causer_id)->toBe($reviewer->getKey())
        ->and($balanceActivity->properties->get('leave_days'))->toBe(2)
        ->and(Activity::query()->where('action', 'hr.leave_balances.consume')->count())->toBe(1);

    $cancelPayload = [...$payload, 'requested_from' => '2026-10-01', 'requested_to' => '2026-10-01'];
    $this->actingAs($fixture['user'])->post(route('employee.hr.requests.store'), $cancelPayload)->assertSessionHasNoErrors();
    $cancelled = HrEmployeeServiceRequest::query()->where('status', HrEmployeeServiceRequest::StatusSubmitted)->sole();
    $this->actingAs($fixture['user'])->patch(route('employee.hr.requests.cancel', $cancelled))->assertSessionHasNoErrors();

    expect((float) DB::table('hr_leave_balances')->where('balance_year', 2026)->value('current_balance'))->toBe(1.0)
        ->and(DB::table('hr_leave_balance_ledger')->count())->toBe(1)
        ->and(DB::table('hr_leave_requests')->count())->toBe(1);

    $this->actingAs($fixture['user'])
        ->get(route('employee.hr.self-service.index'))
        ->assertOk()
        ->assertSee('2025')
        ->assertSee('2026');
});

test('leave approval rolls back balance ledger and canonical leave when canonical persistence fails', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The deterministic injected-failure trigger is SQLite-specific.');
    }

    $fixture = employeeRequestFixture();
    $leaveType = HrLeaveType::query()->create(['code' => 'rollback-leave', 'name' => 'Rollback Leave', 'status' => 'active', 'metadata' => ['requires_balance' => true]]);
    DB::table('hr_leave_balances')->insert([
        'employee_id' => $fixture['employee']->getKey(), 'leave_type_id' => $leaveType->getKey(), 'balance_year' => 2026,
        'opening_balance' => 5, 'current_balance' => 5, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($fixture['user'])->post(route('employee.hr.requests.store'), [
        'request_type' => 'leave',
        'details' => 'Rollback leave',
        'requested_from' => '2026-11-01',
        'requested_to' => '2026-11-01',
        'payload' => ['leave_type' => 'rollback-leave'],
    ])->assertSessionHasNoErrors();
    $employeeRequest = HrEmployeeServiceRequest::query()->where('request_type', 'leave')->sole();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.hr_requests.manage', 'web');
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo('hr.hr_requests.manage');
    $session = [OperatingContextService::CompanyIdKey => $fixture['company']->getKey(), OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num];

    DB::unprepared("CREATE TRIGGER fail_canonical_leave BEFORE INSERT ON hr_leave_requests BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
    $this->withoutExceptionHandling();

    try {
        expect(fn () => $this->actingAs($reviewer)->withSession($session)
            ->patch(route('admin.hr.hr-requests.review', $employeeRequest), ['decision' => 'approved']))
            ->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_canonical_leave');
    }

    expect((float) DB::table('hr_leave_balances')->value('current_balance'))->toBe(5.0)
        ->and(DB::table('hr_leave_balance_ledger')->count())->toBe(0)
        ->and(DB::table('hr_leave_requests')->count())->toBe(0)
        ->and($employeeRequest->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusSubmitted);
});

test('identical leave retry cannot persist or consume twice when balance is sufficient for both', function (): void {
    $fixture = employeeRequestFixture();
    $leaveType = HrLeaveType::query()->create(['code' => 'duplicate-leave', 'name' => 'Duplicate Leave', 'status' => 'active', 'metadata' => ['requires_balance' => true]]);
    DB::table('hr_leave_balances')->insert([
        'employee_id' => $fixture['employee']->getKey(), 'leave_type_id' => $leaveType->getKey(), 'balance_year' => 2026,
        'opening_balance' => 10, 'current_balance' => 10, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payload = [
        'request_type' => 'leave', 'details' => 'Identical retry', 'requested_from' => '2026-12-01',
        'requested_to' => '2026-12-02', 'payload' => ['leave_type' => 'duplicate-leave'],
    ];

    $this->actingAs($fixture['user'])->post(route('employee.hr.requests.store'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($fixture['user'])->post(route('employee.hr.requests.store'), $payload)->assertSessionHasErrors('request');
    expect(HrEmployeeServiceRequest::query()->where('request_type', 'leave')->count())->toBe(1);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.hr_requests.manage', 'web');
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo('hr.hr_requests.manage');
    $request = HrEmployeeServiceRequest::query()->where('request_type', 'leave')->sole();
    $session = [OperatingContextService::CompanyIdKey => $fixture['company']->getKey(), OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num];
    $this->actingAs($reviewer)->withSession($session)
        ->patch(route('admin.hr.hr-requests.review', $request), ['decision' => 'approved'])
        ->assertSessionHasNoErrors();

    expect((float) DB::table('hr_leave_balances')->value('current_balance'))->toBe(8.0)
        ->and(DB::table('hr_leave_balance_ledger')->count())->toBe(1)
        ->and(DB::table('hr_leave_requests')->count())->toBe(1)
        ->and(DB::table('hr_leave_request_days')->count())->toBe(2);
});

test('approved paid leave blocks an overlapping unpaid leave of a different type', function (): void {
    $fixture = employeeRequestFixture();
    $paid = HrLeaveType::query()->create([
        'code' => 'overlap-paid',
        'name' => 'Overlap Paid',
        'status' => 'active',
        'metadata' => ['requires_balance' => false, 'payment_status' => 'paid'],
    ]);
    $unpaid = HrLeaveType::query()->create([
        'code' => 'overlap-unpaid',
        'name' => 'Overlap Unpaid',
        'status' => 'active',
        'metadata' => ['requires_balance' => false, 'payment_status' => 'unpaid'],
    ]);
    $service = app(HrEmployeeRequestService::class);
    $paidRequest = $service->createForUser($fixture['user'], [
        'request_type' => 'leave',
        'details' => 'Paid leave first',
        'requested_from' => '2027-01-10',
        'requested_to' => '2027-01-12',
        'payload' => ['leave_type' => $paid->code],
    ]);
    $reviewer = User::factory()->create();
    $service->review($paidRequest, $reviewer, HrEmployeeServiceRequest::StatusApproved, null);

    expect(fn () => $service->createForUser($fixture['user'], [
        'request_type' => 'leave',
        'details' => 'Unpaid leave overlap',
        'requested_from' => '2027-01-12',
        'requested_to' => '2027-01-13',
        'payload' => ['leave_type' => $unpaid->code],
    ]))->toThrow(DomainException::class, __('hr_requests.messages.overlapping_leave_request'));
});
