<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Services\HrEmployeeService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('authorized employee wage screen verifies a legacy rate then records a dated change', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 93101,
        'doc_num' => 'HR-WAGE-BR-93101',
        'name' => 'Synthetic wage branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $employee = HrEmployee::query()->create([
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'doc_number' => 93101,
        'doc_num' => 'HR-WAGE-EMP-93101',
        'full_name' => 'Synthetic wage employee',
        'name' => 'Synthetic wage employee',
        'status' => 'active',
        'hire_date' => '2026-01-01',
        'contract_start_date' => '2026-01-01',
        'pay_basis' => 'hourly_wage',
        'hourly_wage' => '50.0000',
    ]);
    $legacyId = DB::table('hr_employee_salary_assignments')->insertGetId([
        'employee_id' => $employee->getKey(),
        'effective_from' => '2026-01-01',
        'basic_salary' => '0.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.employees.view', 'web');
    Permission::findOrCreate('hr.employees.edit', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo(['hr.employees.view', 'hr.employees.edit']);
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
    ];

    $this->actingAs($actor)->withSession($session)
        ->get(route('admin.hr.employees.wage-versions.index', $employee->doc_num))
        ->assertOk()->assertSee(__('hr_wage_versions.legacy'));
    $this->withSession($session)
        ->post(route('admin.hr.employees.wage-versions.store', $employee->doc_num), [
            'effective_from' => '2026-09-11',
            'pay_basis' => 'hourly_wage',
            'rate' => '80.0000',
            'reason' => 'Synthetic approved contract amendment',
        ])->assertSessionHasErrors('rate_version');
    $this->withSession($session)
        ->post(route('admin.hr.employees.wage-versions.verify', [$employee->doc_num, $legacyId]), [
            'pay_basis' => 'hourly_wage',
            'rate' => '50.0000',
            'reason' => 'Verified synthetic contract rate',
        ])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hr_employee_salary_assignments', [
        'id' => $legacyId,
        'pay_basis' => 'hourly_wage',
        'hourly_wage' => '50.0000',
    ]);
    $this->withSession($session)
        ->post(route('admin.hr.employees.wage-versions.store', $employee->doc_num), [
            'effective_from' => '2026-09-11',
            'pay_basis' => 'hourly_wage',
            'rate' => '80.0000',
            'reason' => 'Synthetic approved contract amendment',
        ])->assertRedirect()->assertSessionHasNoErrors();

    $versions = DB::table('hr_employee_salary_assignments')->where('employee_id', $employee->getKey())
        ->orderBy('effective_from')->get();
    expect($versions)->toHaveCount(2)
        ->and((string) $versions[0]->effective_to)->toBe('2026-09-10')
        ->and((string) $versions[1]->effective_from)->toBe('2026-09-11')
        ->and(bcadd((string) $versions[1]->hourly_wage, '0', 4))->toBe('80.0000');
    expect(fn () => app(HrEmployeeService::class)->update($employee, ['hourly_wage' => '90.0000']))
        ->toThrow(DomainException::class, __('hr_wage_versions.messages.card_edit_requires_version'));

    $this->withSession($session)
        ->post(route('admin.hr.employees.wage-versions.store', $employee->doc_num), [
            'effective_from' => now()->addDay()->toDateString(),
            'pay_basis' => 'daily_wage',
            'rate' => '300.0000',
            'reason' => 'Synthetic dated change to daily pay',
        ])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hr_employee_salary_assignments', [
        'employee_id' => $employee->getKey(),
        'pay_basis' => 'daily_wage',
        'daily_wage' => '300.0000',
    ]);
    $this->withSession($session)
        ->get(route('admin.hr.employees.wage-versions.index', $employee->doc_num))
        ->assertOk()
        ->assertSee(__('hr.employees.pay_basis.daily_wage'))
        ->assertSee('300');
    expect($employee->fresh()->pay_basis)->toBe('hourly_wage');

    $unanchored = HrEmployee::query()->create([
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'doc_number' => 93102,
        'doc_num' => 'HR-WAGE-EMP-93102',
        'full_name' => 'Synthetic unanchored employee',
        'name' => 'Synthetic unanchored employee',
        'status' => 'active',
        'pay_basis' => 'hourly_wage',
        'hourly_wage' => '50.0000',
    ]);
    $this->withSession($session)
        ->post(route('admin.hr.employees.wage-versions.store', $unanchored->doc_num), [
            'effective_from' => '2026-09-01',
            'pay_basis' => 'hourly_wage',
            'rate' => '50.0000',
            'reason' => 'No verified employment start',
        ])->assertSessionHasErrors('rate_version');
    expect(DB::table('hr_employee_salary_assignments')->where('employee_id', $unanchored->getKey())->exists())->toBeFalse();

    $actor->revokePermissionTo('hr.employees.edit');
    $this->withSession($session)
        ->post(route('admin.hr.employees.wage-versions.store', $employee->doc_num), [
            'effective_from' => '2026-10-02',
            'pay_basis' => 'hourly_wage',
            'rate' => '90.0000',
            'reason' => 'Must not save',
        ])->assertForbidden();
    expect(DB::table('hr_employee_salary_assignments')->where('employee_id', $employee->getKey())->count())->toBe(3);
});

test('future dated wage is projected onto the employee card only when due and only once', function (): void {
    Carbon::setTestNow('2026-10-01 09:00:00');

    try {
        $company = Company::factory()->create();
        $branch = Branch::query()->create([
            'company_id' => $company->getKey(),
            'doc_number' => 93103,
            'doc_num' => 'HR-WAGE-BR-93103',
            'name' => 'Synthetic future wage branch',
            'type' => Branch::TypeAdministrative,
            'status' => 'active',
        ]);
        $employee = HrEmployee::query()->create([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'doc_number' => 93103,
            'doc_num' => 'HR-WAGE-EMP-93103',
            'full_name' => 'Synthetic future wage employee',
            'name' => 'Synthetic future wage employee',
            'status' => 'active',
            'hire_date' => '2026-01-01',
            'contract_start_date' => '2026-01-01',
            'pay_basis' => 'hourly_wage',
            'hourly_wage' => '50.0000',
        ]);
        DB::table('hr_employee_salary_assignments')->insert([
            [
                'employee_id' => $employee->getKey(),
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-10-01',
                'basic_salary' => '0.00',
                'pay_basis' => 'hourly_wage',
                'hourly_wage' => '50.0000',
                'daily_wage' => null,
                'reason' => 'Verified synthetic initial wage',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'employee_id' => $employee->getKey(),
                'effective_from' => '2026-10-02',
                'effective_to' => null,
                'basic_salary' => '0.00',
                'pay_basis' => 'daily_wage',
                'hourly_wage' => null,
                'daily_wage' => '300.0000',
                'reason' => 'Verified synthetic future wage',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->artisan('hr:wages:project-current')->assertSuccessful();
        expect($employee->fresh()->pay_basis)->toBe('hourly_wage')
            ->and((string) $employee->fresh()->hourly_wage)->toBe('50.0000');

        Carbon::setTestNow('2026-10-02 00:20:00');
        $this->artisan('hr:wages:project-current')->assertSuccessful();
        expect($employee->fresh()->pay_basis)->toBe('daily_wage')
            ->and((string) $employee->fresh()->daily_wage)->toBe('300.0000')
            ->and(DB::table('activity_log')->where('subject_type', $employee->getMorphClass())
                ->where('subject_id', $employee->getKey())
                ->where('action', 'employee_wage_projection.apply')->count())->toBe(1);

        $this->artisan('hr:wages:project-current')->assertSuccessful();
        expect(DB::table('activity_log')->where('subject_type', $employee->getMorphClass())
            ->where('subject_id', $employee->getKey())
            ->where('action', 'employee_wage_projection.apply')->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});
