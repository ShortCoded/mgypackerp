<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Services\HrEmployeeOrganizationAssignmentService;
use Modules\HR\Services\HrEmployeeService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('a verified initial assignment and dated branch transfer retain both intervals without inventing legacy history', function (): void {
    $company = Company::factory()->create();
    $firstBranch = Branch::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 92001,
        'doc_num' => 'HR-ORG-BR-92001',
        'name' => 'Synthetic origin',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $secondBranch = Branch::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 92002,
        'doc_num' => 'HR-ORG-BR-92002',
        'name' => 'Synthetic destination',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $employee = HrEmployee::query()->create([
        'company_id' => $company->getKey(),
        'branch_id' => $firstBranch->getKey(),
        'doc_number' => 92001,
        'doc_num' => 'HR-ORG-EMP-92001',
        'full_name' => 'Synthetic dated-transfer employee',
        'name' => 'Synthetic dated-transfer employee',
        'status' => 'active',
        'hire_date' => '2026-01-01',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.employees.edit', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo('hr.employees.edit');
    $this->actingAs($actor);

    expect(DB::table('hr_employee_organization_assignments')->where('employee_id', $employee->getKey())->count())->toBe(0);
    expect(fn () => app(HrEmployeeService::class)->update($employee, ['branch_doc_num' => $secondBranch->doc_num]))
        ->toThrow(DomainException::class, __('hr_organization_assignments.messages.card_edit_requires_transfer'));
    expect(DB::table('hr_employee_organization_assignments')->where('employee_id', $employee->getKey())->count())->toBe(0)
        ->and((int) $employee->refresh()->branch_id)->toBe((int) $firstBranch->getKey());

    $service = app(HrEmployeeOrganizationAssignmentService::class);
    $service->registerInitial((int) $company->getKey(), (int) $employee->getKey(), '2026-09-01', null, 'Verified synthetic starting assignment', $actor);
    $service->transfer((int) $company->getKey(), (int) $employee->getKey(), $secondBranch->doc_num, null, null, '2026-09-11', 'Synthetic transfer', $actor);
    $rows = DB::table('hr_employee_organization_assignments')->where('employee_id', $employee->getKey())->orderBy('effective_from')->get();

    expect($rows)->toHaveCount(2)
        ->and((string) $rows[0]->effective_from)->toBe('2026-09-01')
        ->and((string) $rows[0]->effective_to)->toBe('2026-09-10')
        ->and((int) $rows[0]->branch_id)->toBe((int) $firstBranch->getKey())
        ->and((string) $rows[1]->effective_from)->toBe('2026-09-11')
        ->and($rows[1]->effective_to)->toBeNull()
        ->and((int) $rows[1]->branch_id)->toBe((int) $secondBranch->getKey())
        ->and((int) $employee->refresh()->branch_id)->toBe((int) $secondBranch->getKey());

    expect(fn () => app(HrEmployeeService::class)->update($employee, ['branch_doc_num' => $firstBranch->doc_num]))
        ->toThrow(DomainException::class, __('hr_organization_assignments.messages.card_edit_requires_transfer'));
    expect((int) $employee->refresh()->branch_id)->toBe((int) $secondBranch->getKey());

    expect(fn () => $service->transfer((int) $company->getKey(), (int) $employee->getKey(), $firstBranch->doc_num, null, null, '2026-09-11', 'Overlapping transfer', $actor))
        ->toThrow(DomainException::class, __('hr_organization_assignments.messages.transfer_sequence_invalid'));
    expect(DB::table('hr_employee_organization_assignments')->where('employee_id', $employee->getKey())->count())->toBe(2);

    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $secondBranch->getKey(),
        OperatingContextService::BranchDocNumKey => $secondBranch->doc_num,
    ];
    Permission::findOrCreate('hr.employees.view', 'web');
    $actor->givePermissionTo('hr.employees.view');
    $this->withSession($session)
        ->get(route('admin.hr.employees.organization-assignments.index', $employee->doc_num))
        ->assertOk()
        ->assertSee(__('hr_organization_assignments.sources.initial_verified'))
        ->assertSee(__('hr_organization_assignments.sources.transfer'));
    $this->withSession($session)
        ->post(route('admin.hr.employees.organization-assignments.transfer', $employee->doc_num), [
            'branch_doc_num' => $firstBranch->doc_num,
            'effective_from' => '2026-09-11',
            'reason' => 'Overlapping HTTP transfer',
        ])
        ->assertSessionHasErrors('assignment');
    expect(DB::table('hr_employee_organization_assignments')->where('employee_id', $employee->getKey())->count())->toBe(2);
});
