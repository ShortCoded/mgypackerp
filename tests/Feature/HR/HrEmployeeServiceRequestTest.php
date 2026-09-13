<?php

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
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

    $this->actingAs($fixture['user'])
        ->patch(route('employee.hr.requests.cancel', $request))
        ->assertRedirect();

    expect($request->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusCancelled);
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

    expect($employeeRequest->refresh()->status)->toBe('approved')
        ->and($employeeRequest->resolved_by)->toBe($reviewer->getKey());
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
});
