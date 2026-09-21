<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrLeaveType;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\HR\Services\PayrollAttendancePolicyService;
use Modules\HR\Services\PayrollCalculationService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** @return array<string, mixed> */
function payrollAttendancePolicyFixture(): array
{
    $company = Company::factory()->create();
    $branch = Branch::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 8801,
        'doc_num' => 'PAY-POL-BR-08801',
        'name' => 'Payroll Policy Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $currency = Currency::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 8801,
        'doc_num' => 'PAY-POL-CUR-08801',
        'name' => 'Egyptian Pound',
        'code' => 'EGP',
        'minor_unit_name' => 'Piastre',
        'minor_unit_factor' => 100,
        'is_main' => true,
        'status' => 'active',
    ]);
    $employee = HrEmployee::query()->create([
        'doc_number' => 8801,
        'doc_num' => 'PAY-POL-EMP-08801',
        'employee_code' => 'POL-E001',
        'full_name' => 'Payroll Policy Employee',
        'name' => 'Payroll Policy Employee',
        'person_type' => 'fixed_employee',
        'status' => 'active',
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'hire_date' => '2026-01-01',
        'contract_start_date' => '2026-01-01',
        'pay_basis' => 'monthly_salary',
        'payroll_currency_id' => $currency->getKey(),
        'exchange_rate' => 1,
        'basic_salary' => '9000.00',
        'hourly_wage' => '100.0000',
        'overtime_enabled' => true,
    ]);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $employee->getKey(),
        'effective_from' => '2026-01-01',
        'basic_salary' => '9000.00',
        'components' => json_encode(['items' => [], 'overtime_hourly_rate' => '100.0000'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        ['code' => 'BASIC', 'name' => 'Basic Salary', 'kind' => 'earning'],
        ['code' => 'OVERTIME', 'name' => 'Overtime', 'kind' => 'earning'],
        ['code' => 'ATTENDANCE-DED', 'name' => 'Attendance Deduction', 'kind' => 'deduction'],
    ] as $item) {
        DB::table('hr_payroll_items')->insert([
            'code' => $item['code'],
            'name' => $item['name'],
            'item_kind' => $item['kind'],
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return compact('company', 'branch', 'currency', 'employee');
}

/** @param array<string, mixed> $fixture */
function seedPayrollAttendanceEvidence(array $fixture): void
{
    foreach ([
        ['date' => '2026-12-30', 'status' => 'present', 'check_out_at' => '2026-12-30 18:00:00', 'late' => 60, 'early' => 30, 'overtime' => 120],
        ['date' => '2026-12-31', 'status' => 'absent', 'check_out_at' => null, 'late' => 0, 'early' => 0, 'overtime' => 0],
        ['date' => '2027-01-01', 'status' => 'absent', 'check_out_at' => null, 'late' => 0, 'early' => 0, 'overtime' => 0],
        ['date' => '2027-01-02', 'status' => 'absent', 'check_out_at' => null, 'late' => 0, 'early' => 0, 'overtime' => 0],
    ] as $record) {
        DB::table('hr_attendance_daily_records')->insert([
            'employee_id' => $fixture['employee']->getKey(),
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'work_date' => $record['date'],
            'check_in_at' => $record['status'] === 'present' ? $record['date'].' 08:00:00' : null,
            'check_out_at' => $record['check_out_at'],
            'worked_minutes' => $record['status'] === 'present' ? 480 : 0,
            'late_minutes' => $record['late'],
            'early_leave_minutes' => $record['early'],
            'overtime_minutes' => $record['overtime'],
            'status' => $record['status'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'request_type' => 'overtime',
        'details' => 'Approved overtime',
        'requested_from' => '2026-12-30',
        'requested_to' => '2026-12-30',
        'requested_minutes' => 120,
        'status' => HrEmployeeServiceRequest::StatusApproved,
        'submitted_at' => now(),
        'resolved_at' => now(),
    ]);

    $paid = HrLeaveType::query()->create([
        'code' => 'PAID-YEAR-END',
        'name' => 'Paid Year End Leave',
        'status' => 'active',
        'metadata' => ['payment_status' => 'paid', 'requires_balance' => false],
    ]);
    $unpaid = HrLeaveType::query()->create([
        'code' => 'UNPAID-YEAR-END',
        'name' => 'Unpaid Year End Leave',
        'status' => 'active',
        'metadata' => ['payment_status' => 'unpaid', 'requires_balance' => false],
    ]);

    foreach ([
        ['type' => $paid, 'date' => '2027-01-01', 'payment_status' => 'paid'],
        ['type' => $unpaid, 'date' => '2027-01-02', 'payment_status' => 'unpaid'],
    ] as $leave) {
        $leaveRequestId = DB::table('hr_leave_requests')->insertGetId([
            'employee_id' => $fixture['employee']->getKey(),
            'leave_type_id' => $leave['type']->getKey(),
            'payment_status' => $leave['payment_status'],
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_leave_request_days')->insert([
            'leave_request_id' => $leaveRequestId,
            'leave_date' => $leave['date'],
            'day_fraction' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

test('attendance payroll effects are safely disabled when no policy exists and overtime stays unchanged', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    seedPayrollAttendanceEvidence($fixture);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-12-30',
        'period_end' => '2027-01-02',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);

    expect($result)->toMatchArray([
        'gross' => '9200.0000',
        'deductions' => '0.0000',
        'payable' => '9200.0000',
    ])->and(DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->count())->toBe(0)
        ->and((float) DB::table('hr_payslip_items')->where('source_type', 'approved_overtime')->value('amount'))->toBe(200.0);

    $input = json_decode((string) DB::table('hr_payroll_inputs')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
    expect($input['payroll_attendance_effects']['policies'])->toBe([])
        ->and($input['payroll_attendance_effects']['summary'])->toMatchArray([
            'absence_days' => 1,
            'paid_leave_days' => 1,
            'unpaid_leave_days' => 1,
        ]);
});

test('versioned policy deducts absence late early and unpaid leave exactly once across year boundary', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    seedPayrollAttendanceEvidence($fixture);
    HrLeaveType::query()->where('code', 'PAID-YEAR-END')->update(['metadata' => json_encode(['payment_status' => 'unpaid'])]);
    HrLeaveType::query()->where('code', 'UNPAID-YEAR-END')->update(['metadata' => json_encode(['payment_status' => 'paid'])]);
    $policy = HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-12-01',
        'deduct_absence' => true,
        'deduct_late' => true,
        'deduct_early_leave' => true,
        'deduct_unpaid_leave' => true,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'status' => 'active',
    ]);

    $first = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-12-30',
        'period_end' => '2027-01-02',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $second = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-12-30',
        'period_end' => '2027-01-02',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);

    expect($first)->toMatchArray([
        'gross' => '9200.0000',
        'deductions' => '656.2500',
        'payable' => '8543.7500',
    ])->and($second)->toMatchArray($first)
        ->and(DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->count())->toBe(4)
        ->and(DB::table('hr_payslip_items')->where('source_type', 'approved_overtime')->count())->toBe(1)
        ->and((float) DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->sum('amount'))->toBe(656.25);

    $snapshots = DB::table('hr_payslip_items')
        ->where('source_type', 'attendance_policy')
        ->orderBy('id')
        ->pluck('source_snapshot')
        ->map(fn (string $snapshot): array => json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR));
    expect($snapshots->pluck('effect_type')->sort()->values()->all())->toBe(['absence', 'early_leave', 'late', 'unpaid_leave'])
        ->and($snapshots->every(fn (array $snapshot): bool => $snapshot['policy']['id'] === $policy->getKey()))->toBeTrue()
        ->and($snapshots->firstWhere('effect_type', 'unpaid_leave')['leave_request_ids'])->toHaveCount(1);

    $inputBeforePolicyChange = (string) DB::table('hr_payroll_inputs')->value('payload');
    $policy->update(['salary_day_divisor' => 26]);
    expect((string) DB::table('hr_payroll_inputs')->value('payload'))->toBe($inputBeforePolicyChange);
});

test('policy versions close adjacent periods and reject duplicate starts', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $actorId = User::factory()->create()->getKey();
    $service = app(PayrollAttendancePolicyService::class);
    $data = [
        'branch_doc_num' => $fixture['branch']->doc_num,
        'effective_from' => '2026-01-01',
        'deduct_absence' => false,
        'deduct_late' => false,
        'deduct_early_leave' => false,
        'deduct_unpaid_leave' => false,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => null,
    ];

    $first = $service->createVersion($fixture['company']->getKey(), $data, $actorId);
    $second = $service->createVersion($fixture['company']->getKey(), [...$data, 'effective_from' => '2027-01-01'], $actorId);

    expect($first->refresh()->effective_to?->toDateString())->toBe('2026-12-31')
        ->and($second->effective_to)->toBeNull()
        ->and(fn () => $service->createVersion($fixture['company']->getKey(), $data, $actorId))
        ->toThrow(DomainException::class, __('hr_payroll_policies.validation.effective_from_unique'));
});

test('canonical leave payment status is non null and defaults safely for legacy inserts', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $leaveType = HrLeaveType::query()->create([
        'code' => 'LEGACY-DEFAULT-PAID',
        'name' => 'Legacy Default Paid',
        'status' => 'active',
        'metadata' => ['payment_status' => 'unpaid', 'requires_balance' => false],
    ]);
    $leaveRequestId = DB::table('hr_leave_requests')->insertGetId([
        'employee_id' => $fixture['employee']->getKey(),
        'leave_type_id' => $leaveType->getKey(),
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('hr_leave_requests')->where('id', $leaveRequestId)->value('payment_status'))->toBe('paid')
        ->and(fn () => DB::table('hr_leave_requests')->insert([
            'employee_id' => $fixture['employee']->getKey(),
            'leave_type_id' => $leaveType->getKey(),
            'payment_status' => null,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
});

test('latest applicable branch policy wins and half cent attendance effects round without floats', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $fixture['employee']->update(['basic_salary' => '1.0000']);
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())->update([
        'basic_salary' => '1.0000',
        'updated_at' => now(),
    ]);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2027-01-01',
        'deduct_late' => false,
        'salary_day_divisor' => 1,
        'standard_day_minutes' => 200,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'status' => 'active',
    ]);
    $latest = HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2027-02-01',
        'deduct_late' => true,
        'salary_day_divisor' => 1,
        'standard_day_minutes' => 200,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'status' => 'active',
    ]);
    DB::table('hr_attendance_daily_records')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'work_date' => '2027-02-01',
        'check_in_at' => '2027-02-01 08:01:00',
        'check_out_at' => '2027-02-01 16:00:00',
        'worked_minutes' => 479,
        'late_minutes' => 1,
        'status' => 'present',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2027-02-01',
        'period_end' => '2027-02-01',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);

    expect($result['deductions'])->toBe('0.0100')
        ->and($result['payable'])->toBe('0.9900')
        ->and((int) DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->value('source_id'))->toBe($latest->getKey())
        ->and((string) DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->value('amount'))->toBe('0.01');
});

test('leave type crud includes detail activation soft delete visibility and restore permissions', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permissions = [
        'hr.leave_types.view',
        'hr.leave_types.create',
        'hr.leave_types.update',
        'hr.leave_types.delete',
        'hr.leave_types.view_deleted',
        'hr.leave_types.restore',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    $this->actingAs($actor)->post(route('admin.hr.leave-types.store'), [
        'code' => 'annual-crud',
        'name' => 'Annual CRUD Leave',
        'payment_status' => 'paid',
        'requires_balance' => '1',
        'annual_entitlement_days' => '21',
        'carry_forward_max_days' => '5',
        'status' => 'active',
        'notes' => 'Initial policy',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $leaveType = HrLeaveType::query()->where('code', 'ANNUAL-CRUD')->sole();
    expect($leaveType->metadata)->toMatchArray([
        'payment_status' => 'paid',
        'requires_balance' => true,
        'annual_entitlement_days' => '21',
        'carry_forward_max_days' => '5',
    ]);
    $this->actingAs($actor)->get(route('admin.hr.leave-types.show', $leaveType))->assertOk()->assertSee('Annual CRUD Leave');

    $this->actingAs($actor)->patch(route('admin.hr.leave-types.update', $leaveType), [
        'code' => 'ANNUAL-CRUD',
        'name' => 'Annual CRUD Leave',
        'payment_status' => 'unpaid',
        'requires_balance' => '0',
        'annual_entitlement_days' => null,
        'carry_forward_max_days' => null,
        'status' => 'inactive',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($leaveType->refresh()->status)->toBe('inactive')
        ->and($leaveType->isPaid())->toBeFalse()
        ->and($leaveType->requiresBalance())->toBeFalse();

    $this->actingAs($actor)->delete(route('admin.hr.leave-types.destroy', $leaveType))->assertRedirect();
    expect($leaveType->refresh()->trashed())->toBeTrue()
        ->and((int) $leaveType->deleted_by)->toBe((int) $actor->getKey());
    $trashResponse = $this->actingAs($actor)->get(route('admin.hr.leave-types.index', ['trash' => 'only']))->assertOk();
    expect($trashResponse->viewData('trash'))->toBe('only')
        ->and($trashResponse->viewData('leaveTypes')->count())->toBe(1);
    $trashResponse->assertSee('ANNUAL-CRUD');
    $this->actingAs($actor)->get(route('admin.hr.leave-types.show', $leaveType->getKey()))->assertOk()->assertSee('Annual CRUD Leave');

    $this->actingAs($actor)->patch(route('admin.hr.leave-types.restore', $leaveType->getKey()))->assertRedirect();
    expect($leaveType->refresh()->trashed())->toBeFalse()
        ->and((int) $leaveType->restored_by)->toBe((int) $actor->getKey());

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('hr.leave_types.view');
    $this->actingAs($viewer)->post(route('admin.hr.leave-types.store'), [])->assertForbidden();
});

test('legacy lowercase leave codes normalize and block a later uppercase duplicate', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.leave_types.create', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo('hr.leave_types.create');
    $legacy = HrLeaveType::query()->create([
        'code' => ' legacy-case ',
        'name' => 'Legacy Case Leave',
        'status' => 'active',
        'metadata' => ['payment_status' => 'paid', 'requires_balance' => false],
    ]);

    expect($legacy->code)->toBe('LEGACY-CASE');
    $this->actingAs($actor)->post(route('admin.hr.leave-types.store'), [
        'code' => 'LEGACY-CASE',
        'name' => 'Duplicate Case Leave',
        'payment_status' => 'paid',
        'requires_balance' => '0',
        'status' => 'active',
    ])->assertSessionHasErrors('code');
    expect(HrLeaveType::query()->where('code', 'LEGACY-CASE')->count())->toBe(1);
});

test('payroll attendance policy http workflow enforces company and branch scope', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 8802,
        'doc_num' => 'PAY-POL-BR-08802',
        'name' => 'Forbidden Payroll Policy Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['hr.payroll_attendance_policies.view', 'hr.payroll_attendance_policies.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'Scoped payroll policy '.uniqid(),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => false,
    ]);
    $actor->assignRole($role);
    $role->givePermissionTo(['hr.payroll_attendance_policies.view', 'hr.payroll_attendance_policies.manage']);
    $role->companyAccessCompanies()->attach($fixture['company']->getKey());
    $role->branchAccessBranches()->attach($fixture['branch']->getKey());
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
    ];
    $payload = [
        'branch_doc_num' => $fixture['branch']->doc_num,
        'effective_from' => '2026-01-01',
        'deduct_absence' => '1',
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
    ];

    $policyResponse = $this->actingAs($actor)->withSession($session)
        ->get(route('admin.hr.payroll-attendance-policies.index'))
        ->assertOk()
        ->assertSee(route('admin.select2.branches'), false)
        ->assertSee('js-select2-ajax', false);
    expect($policyResponse->viewData('branches')->pluck('doc_num')->all())->toBe([$fixture['branch']->doc_num]);
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hr_payroll_attendance_policies', [
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'deduct_absence' => true,
    ]);

    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), [...$payload, 'branch_doc_num' => $otherBranch->doc_num, 'effective_from' => '2027-01-01'])
        ->assertSessionHasErrors('branch_doc_num');
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), [...$payload, 'branch_doc_num' => null, 'effective_from' => '2027-01-01'])
        ->assertSessionHasErrors('branch_doc_num');
});
