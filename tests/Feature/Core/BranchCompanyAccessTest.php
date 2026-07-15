<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function branchCompanyAccessActor(array $permissions, ?Role $role = null): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    if ($role instanceof Role) {
        $user->assignRole($role);
    }

    return $user;
}

function branchCompanyAccessCompany(string $name): Company
{
    static $documentNumber = 9100;

    $documentNumber++;

    return Company::factory()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => $name,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
}

function branchCompanyAccessRole(array $overrides = []): Role
{
    static $documentNumber = 9200;

    $documentNumber++;

    return Role::query()->create([
        'name' => 'branch-company-access-'.$documentNumber,
        'guard_name' => 'web',
        'doc_number' => $documentNumber,
        'doc_num' => 'Role-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_access_restricted' => true,
        'branch_access_restricted' => false,
        'financial_period_access_restricted' => false,
        ...$overrides,
    ]);
}

function grantBranchCompanyAccess(Role $role, Company $company): void
{
    DB::table('role_company_access')->insert([
        'role_id' => $role->getKey(),
        'company_id' => $company->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function branchCompanyAccessBranch(Company $company, array $overrides = []): Branch
{
    static $documentNumber = 9300;

    $documentNumber++;

    return Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Company Access Branch '.$documentNumber,
        'type' => 'factory',
        'status' => 'active',
        ...$overrides,
    ]);
}

test('branch company selector returns only companies allowed by operating scope', function () {
    $allowedCompany = branchCompanyAccessCompany('Allowed Branch Selector Company');
    $blockedCompany = branchCompanyAccessCompany('Blocked Branch Selector Company');
    $role = branchCompanyAccessRole();
    grantBranchCompanyAccess($role, $allowedCompany);
    $actor = branchCompanyAccessActor(['branches.create'], $role);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.select2.companies', [
            'access_scope' => 'branch_form',
            'q' => 'Branch Selector Company',
        ]))
        ->assertOk();

    $ids = collect($response->json('results'))->pluck('id')->all();

    expect($ids)
        ->toContain($allowedCompany->doc_num)
        ->not->toContain($blockedCompany->doc_num);
});

test('full access branch company selector still returns all active companies', function () {
    $firstCompany = branchCompanyAccessCompany('Full Access First Company');
    $secondCompany = branchCompanyAccessCompany('Full Access Second Company');
    $role = branchCompanyAccessRole(['company_access_restricted' => false]);
    $actor = branchCompanyAccessActor(['branches.create'], $role);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.select2.companies', [
            'access_scope' => 'branch_form',
            'q' => 'Full Access',
        ]))
        ->assertOk();

    $ids = collect($response->json('results'))->pluck('id')->all();

    expect($ids)
        ->toContain($firstCompany->doc_num)
        ->toContain($secondCompany->doc_num);
});

test('branch creation rejects a company outside the user operating scope', function () {
    $allowedCompany = branchCompanyAccessCompany('Allowed Create Company');
    $blockedCompany = branchCompanyAccessCompany('Blocked Create Company');
    $role = branchCompanyAccessRole();
    grantBranchCompanyAccess($role, $allowedCompany);
    $actor = branchCompanyAccessActor(['branches.create'], $role);

    $this->actingAs($actor)
        ->postJson(route('admin.branches.store'), [
            'company_doc_num' => $blockedCompany->doc_num,
            'name' => 'Unauthorized Company Branch',
            'type' => 'factory',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_doc_num')
        ->assertJsonPath('errors.company_doc_num.0', __('branches.validation.company_forbidden'));
});

test('branch update rejects changing to a company outside the user operating scope', function () {
    $allowedCompany = branchCompanyAccessCompany('Allowed Update Company');
    $blockedCompany = branchCompanyAccessCompany('Blocked Update Company');
    $role = branchCompanyAccessRole();
    grantBranchCompanyAccess($role, $allowedCompany);
    $actor = branchCompanyAccessActor(['branches.edit'], $role);
    $branch = branchCompanyAccessBranch($allowedCompany);

    $this->actingAs($actor)
        ->putJson(route('admin.branches.update', $branch->doc_num), [
            'company_doc_num' => $blockedCompany->doc_num,
            'name' => $branch->name,
            'type' => 'factory',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_doc_num')
        ->assertJsonPath('errors.company_doc_num.0', __('branches.validation.company_forbidden'));
});

test('editing a branch under an inaccessible company is forbidden', function () {
    $allowedCompany = branchCompanyAccessCompany('Allowed Edit Company');
    $blockedCompany = branchCompanyAccessCompany('Blocked Edit Company');
    $role = branchCompanyAccessRole();
    grantBranchCompanyAccess($role, $allowedCompany);
    $actor = branchCompanyAccessActor(['branches.edit'], $role);
    $blockedBranch = branchCompanyAccessBranch($blockedCompany);

    $this->actingAs($actor)
        ->get(route('admin.branches.edit', $blockedBranch->doc_num))
        ->assertForbidden();
});
