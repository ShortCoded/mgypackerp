<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function operatingScopeAccessUser(array $permissions = [], ?Role $role = null): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    if ($role instanceof Role) {
        $user->assignRole($role);
    }

    return $user;
}

function operatingScopeAccessRole(array $overrides = []): Role
{
    static $documentNumber = 9900;

    $documentNumber++;

    return Role::query()->create([
        'name' => 'scope-access-role-'.$documentNumber,
        'guard_name' => 'web',
        'doc_number' => $documentNumber,
        'doc_num' => 'Role-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
        ...$overrides,
    ]);
}

function operatingScopeAccessCompany(string $name): Company
{
    static $documentNumber = 9900;

    DB::statement('DROP INDEX IF EXISTS companies_one_active_main_unique');

    $documentNumber++;

    return Company::factory()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => $name,
        'status' => 'active',
    ]);
}

function operatingScopeAccessBranch(Company $company, string $name): Branch
{
    static $documentNumber = 9900;

    $documentNumber++;

    return Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => $name,
        'type' => 'factory',
        'status' => 'active',
    ]);
}

function operatingScopeAccessPeriod(Company $company, string $name): FinancialPeriod
{
    static $documentNumber = 9900;

    $documentNumber++;

    return FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => $name,
        'from_date' => CarbonImmutable::create(2026, 1, 1)->toDateString(),
        'to_date' => CarbonImmutable::create(2026, 12, 31)->toDateString(),
        'is_closed' => false,
    ]);
}

function grantOperatingScopeAccess(Role $role, ?Company $company = null, ?Branch $branch = null, ?FinancialPeriod $period = null): void
{
    if ($company instanceof Company) {
        DB::table('role_company_access')->insert([
            'role_id' => $role->getKey(),
            'company_id' => $company->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    if ($branch instanceof Branch) {
        DB::table('role_branch_access')->insert([
            'role_id' => $role->getKey(),
            'branch_id' => $branch->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    if ($period instanceof FinancialPeriod) {
        DB::table('role_financial_period_access')->insert([
            'role_id' => $role->getKey(),
            'financial_period_id' => $period->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

test('restricted operating scope ids are loaded once per dimension during a request', function (): void {
    $company = operatingScopeAccessCompany('Memoized Scope Company');
    $branch = operatingScopeAccessBranch($company, 'Memoized Scope Branch');
    $period = operatingScopeAccessPeriod($company, 'Memoized Scope Period');
    $role = operatingScopeAccessRole();
    grantOperatingScopeAccess($role, $company, $branch, $period);
    $user = operatingScopeAccessUser([], $role);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $this->withSession([
            OperatingContextService::CompanyIdKey => $company->getKey(),
            OperatingContextService::CompanyDocNumKey => $company->doc_num,
            OperatingContextService::BranchIdKey => $branch->getKey(),
            OperatingContextService::BranchDocNumKey => $branch->doc_num,
            OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
            OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
        ])
            ->actingAs($user)
            ->getJson(route('admin.operating-context.options'))
            ->assertOk();

        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'role_company_access')))->toHaveCount(1)
        ->and($queries->filter(fn (array $query): bool => str_contains($query['query'], 'role_branch_access')))->toHaveCount(1)
        ->and($queries->filter(fn (array $query): bool => str_contains($query['query'], 'role_financial_period_access')))->toHaveCount(1);
});

test('operating context options auto-select the only allowed company and expose its branches and periods', function () {
    $allowedCompany = operatingScopeAccessCompany('Allowed Modal Company');
    $blockedCompany = operatingScopeAccessCompany('Blocked Modal Company');
    operatingScopeAccessBranch($allowedCompany, 'Allowed Modal Branch One');
    operatingScopeAccessBranch($allowedCompany, 'Allowed Modal Branch Two');
    operatingScopeAccessPeriod($allowedCompany, 'Allowed Modal Period One');
    operatingScopeAccessPeriod($allowedCompany, 'Allowed Modal Period Two');
    $role = operatingScopeAccessRole([
        'branch_access_restricted' => false,
        'financial_period_access_restricted' => false,
    ]);
    grantOperatingScopeAccess($role, $allowedCompany);
    $user = operatingScopeAccessUser([], $role);

    $payload = $this->actingAs($user)
        ->getJson(route('admin.operating-context.options'))
        ->assertOk()
        ->json('data');

    expect(collect($payload['companies'])->pluck('doc_num')->all())
        ->toContain($allowedCompany->doc_num)
        ->not->toContain($blockedCompany->doc_num)
        ->and($payload['auto_select']['company']['doc_num'])->toBe($allowedCompany->doc_num)
        ->and(collect($payload['branches'])->pluck('company_doc_num')->unique()->values()->all())->toBe([$allowedCompany->doc_num])
        ->and($payload['branches'])->toHaveCount(2)
        ->and($payload['financial_periods'])->toHaveCount(2);
});

test('operating context branch and period options are limited to selected company and allowed role scope', function () {
    $company = operatingScopeAccessCompany('Allowed Dependent Company');
    $otherCompany = operatingScopeAccessCompany('Other Dependent Company');
    $allowedBranch = operatingScopeAccessBranch($company, 'Allowed Dependent Branch');
    $blockedSameCompanyBranch = operatingScopeAccessBranch($company, 'Blocked Same Company Branch');
    $blockedOtherBranch = operatingScopeAccessBranch($otherCompany, 'Blocked Other Company Branch');
    $allowedPeriod = operatingScopeAccessPeriod($company, 'Allowed Dependent Period');
    $blockedSameCompanyPeriod = operatingScopeAccessPeriod($company, 'Blocked Same Company Period');
    $blockedOtherPeriod = operatingScopeAccessPeriod($otherCompany, 'Blocked Other Company Period');
    $role = operatingScopeAccessRole();
    grantOperatingScopeAccess($role, $company, $allowedBranch, $allowedPeriod);
    $user = operatingScopeAccessUser([], $role);

    $payload = $this->actingAs($user)
        ->getJson(route('admin.operating-context.options', ['company_doc_num' => $company->doc_num]))
        ->assertOk()
        ->json('data');

    expect(collect($payload['branches'])->pluck('doc_num')->all())
        ->toContain($allowedBranch->doc_num)
        ->not->toContain($blockedSameCompanyBranch->doc_num)
        ->not->toContain($blockedOtherBranch->doc_num)
        ->and(collect($payload['financial_periods'])->pluck('doc_num')->all())
        ->toContain($allowedPeriod->doc_num)
        ->not->toContain($blockedSameCompanyPeriod->doc_num)
        ->not->toContain($blockedOtherPeriod->doc_num);
});

test('saving operating context rejects unauthorized companies branches and financial periods', function () {
    $company = operatingScopeAccessCompany('Allowed Save Company');
    $blockedCompany = operatingScopeAccessCompany('Blocked Save Company');
    $allowedBranch = operatingScopeAccessBranch($company, 'Allowed Save Branch');
    $blockedSameCompanyBranch = operatingScopeAccessBranch($company, 'Blocked Save Branch');
    $blockedOtherBranch = operatingScopeAccessBranch($blockedCompany, 'Other Save Branch');
    $allowedPeriod = operatingScopeAccessPeriod($company, 'Allowed Save Period');
    $blockedSameCompanyPeriod = operatingScopeAccessPeriod($company, 'Blocked Save Period');
    $blockedOtherPeriod = operatingScopeAccessPeriod($blockedCompany, 'Other Save Period');
    $role = operatingScopeAccessRole();
    grantOperatingScopeAccess($role, $company, $allowedBranch, $allowedPeriod);
    $user = operatingScopeAccessUser([], $role);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $blockedCompany->doc_num,
            'branch_doc_num' => $blockedOtherBranch->doc_num,
            'financial_period_doc_num' => $blockedOtherPeriod->doc_num,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_doc_num', 'branch_doc_num', 'financial_period_doc_num']);

    $this->postJson(route('admin.operating-context.select'), [
        'company_doc_num' => $company->doc_num,
        'branch_doc_num' => $blockedOtherBranch->doc_num,
        'financial_period_doc_num' => $allowedPeriod->doc_num,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_doc_num']);

    $this->postJson(route('admin.operating-context.select'), [
        'company_doc_num' => $company->doc_num,
        'branch_doc_num' => $blockedSameCompanyBranch->doc_num,
        'financial_period_doc_num' => $allowedPeriod->doc_num,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_doc_num']);

    $this->postJson(route('admin.operating-context.select'), [
        'company_doc_num' => $company->doc_num,
        'branch_doc_num' => $allowedBranch->doc_num,
        'financial_period_doc_num' => $blockedOtherPeriod->doc_num,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['financial_period_doc_num']);

    $this->postJson(route('admin.operating-context.select'), [
        'company_doc_num' => $company->doc_num,
        'branch_doc_num' => $allowedBranch->doc_num,
        'financial_period_doc_num' => $blockedSameCompanyPeriod->doc_num,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['financial_period_doc_num']);
});

test('roles operating scope company selector returns only assignable companies for limited admins', function () {
    $allowedCompany = operatingScopeAccessCompany('Assignable Company');
    $blockedCompany = operatingScopeAccessCompany('Unassignable Company');
    operatingScopeAccessRole(['name' => 'admin']);
    $role = operatingScopeAccessRole([
        'branch_access_restricted' => false,
        'financial_period_access_restricted' => false,
    ]);
    grantOperatingScopeAccess($role, $allowedCompany);
    $admin = operatingScopeAccessUser(['roles.create', 'roles.operating_scope.manage'], $role);

    $ids = collect($this->actingAs($admin)
        ->getJson(route('admin.select2.companies', [
            'access_scope' => 'operating_scope',
            'q' => 'Company',
        ]))
        ->assertOk()
        ->json('results'))->pluck('id')->all();

    expect($ids)
        ->toContain($allowedCompany->doc_num)
        ->not->toContain($blockedCompany->doc_num);
});

test('roles branch and period selectors are empty until companies are selected', function () {
    $admin = operatingScopeAccessUser(['roles.create', 'roles.operating_scope.manage']);
    $company = operatingScopeAccessCompany('Empty Dependency Company');
    operatingScopeAccessBranch($company, 'Empty Dependency Branch');
    operatingScopeAccessPeriod($company, 'Empty Dependency Period');

    $this->actingAs($admin)
        ->getJson(route('admin.select2.branches', ['access_scope' => 'operating_scope']))
        ->assertOk()
        ->assertJsonPath('results', []);

    $this->actingAs($admin)
        ->getJson(route('admin.select2.financial-periods', ['access_scope' => 'operating_scope']))
        ->assertOk()
        ->assertJsonPath('results', []);
});

test('roles branch and period selectors return only records for selected companies', function () {
    $admin = operatingScopeAccessUser(['roles.create', 'roles.operating_scope.manage']);
    $firstCompany = operatingScopeAccessCompany('Selected First Company');
    $secondCompany = operatingScopeAccessCompany('Selected Second Company');
    $firstBranch = operatingScopeAccessBranch($firstCompany, 'Selected First Branch');
    $secondBranch = operatingScopeAccessBranch($secondCompany, 'Selected Second Branch');
    $firstPeriod = operatingScopeAccessPeriod($firstCompany, 'Selected First Period');
    $secondPeriod = operatingScopeAccessPeriod($secondCompany, 'Selected Second Period');

    $branchIds = collect($this->actingAs($admin)
        ->getJson(route('admin.select2.branches', [
            'access_scope' => 'operating_scope',
            'company_doc_nums' => [$firstCompany->doc_num],
            'q' => 'Selected',
        ]))
        ->assertOk()
        ->json('results'))->pluck('id')->all();

    $periodIds = collect($this->actingAs($admin)
        ->getJson(route('admin.select2.financial-periods', [
            'access_scope' => 'operating_scope',
            'company_doc_nums' => [$firstCompany->doc_num],
            'q' => 'Selected',
        ]))
        ->assertOk()
        ->json('results'))->pluck('id')->all();

    expect($branchIds)
        ->toContain($firstBranch->doc_num)
        ->not->toContain($secondBranch->doc_num)
        ->and($periodIds)
        ->toContain($firstPeriod->doc_num)
        ->not->toContain($secondPeriod->doc_num);

    $multiCompanyBranchIds = collect($this->actingAs($admin)
        ->getJson(route('admin.select2.branches', [
            'access_scope' => 'operating_scope',
            'company_doc_nums' => [$firstCompany->doc_num, $secondCompany->doc_num],
            'q' => 'Selected',
        ]))
        ->assertOk()
        ->json('results'))->pluck('id')->all();

    expect($multiCompanyBranchIds)
        ->toContain($firstBranch->doc_num)
        ->toContain($secondBranch->doc_num);
});

test('role create and update reject branch and period values outside selected companies', function () {
    $admin = operatingScopeAccessUser(['roles.create', 'roles.edit', 'roles.operating_scope.manage']);
    $company = operatingScopeAccessCompany('Role Selected Company');
    $otherCompany = operatingScopeAccessCompany('Role Other Company');
    $otherBranch = operatingScopeAccessBranch($otherCompany, 'Role Other Branch');
    $otherPeriod = operatingScopeAccessPeriod($otherCompany, 'Role Other Period');

    $this->actingAs($admin)
        ->postJson(route('admin.roles.store'), [
            'name' => 'branch-company-mismatch-role',
            'permissions' => [],
            'accessible_company_doc_nums' => [$company->doc_num],
            'accessible_branch_doc_nums' => [$otherBranch->doc_num],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_branch_doc_nums']);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'period-company-mismatch-role',
        'permissions' => [],
        'accessible_company_doc_nums' => [$company->doc_num],
        'accessible_financial_period_doc_nums' => [$otherPeriod->doc_num],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_financial_period_doc_nums']);

    operatingScopeAccessRole(['name' => 'admin']);
    $role = operatingScopeAccessRole([
        'name' => 'role-update-mismatch-target',
        'company_access_restricted' => false,
        'branch_access_restricted' => false,
        'financial_period_access_restricted' => false,
    ]);

    $this->putJson(route('admin.roles.update', $role->doc_num), [
        'name' => $role->name,
        'permissions' => [],
        'accessible_company_doc_nums' => [$company->doc_num],
        'accessible_branch_doc_nums' => [$otherBranch->doc_num],
        'accessible_financial_period_doc_nums' => [$otherPeriod->doc_num],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_branch_doc_nums', 'accessible_financial_period_doc_nums']);
});

test('limited admins cannot assign unauthorized operating scope or create unrestricted scoped roles', function () {
    $allowedCompany = operatingScopeAccessCompany('Limited Allowed Company');
    $blockedCompany = operatingScopeAccessCompany('Limited Blocked Company');
    $allowedBranch = operatingScopeAccessBranch($allowedCompany, 'Limited Allowed Branch');
    $blockedBranch = operatingScopeAccessBranch($allowedCompany, 'Limited Blocked Branch');
    $allowedPeriod = operatingScopeAccessPeriod($allowedCompany, 'Limited Allowed Period');
    $blockedPeriod = operatingScopeAccessPeriod($allowedCompany, 'Limited Blocked Period');
    $adminRole = operatingScopeAccessRole();
    grantOperatingScopeAccess($adminRole, $allowedCompany, $allowedBranch, $allowedPeriod);
    $admin = operatingScopeAccessUser(['roles.create', 'roles.edit', 'roles.operating_scope.manage'], $adminRole);

    $this->actingAs($admin)
        ->postJson(route('admin.roles.store'), [
            'name' => 'limited-unauthorized-company-role',
            'permissions' => [],
            'accessible_company_doc_nums' => [$blockedCompany->doc_num],
            'accessible_branch_doc_nums' => [$allowedBranch->doc_num],
            'accessible_financial_period_doc_nums' => [$allowedPeriod->doc_num],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_company_doc_nums', 'accessible_branch_doc_nums', 'accessible_financial_period_doc_nums']);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'limited-unauthorized-branch-period-role',
        'permissions' => [],
        'accessible_company_doc_nums' => [$allowedCompany->doc_num],
        'accessible_branch_doc_nums' => [$blockedBranch->doc_num],
        'accessible_financial_period_doc_nums' => [$blockedPeriod->doc_num],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_branch_doc_nums', 'accessible_financial_period_doc_nums']);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'limited-unrestricted-role',
        'permissions' => [],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_company_doc_nums', 'accessible_branch_doc_nums', 'accessible_financial_period_doc_nums']);

    $role = operatingScopeAccessRole([
        'name' => 'limited-update-target',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ]);
    grantOperatingScopeAccess($role, $allowedCompany, $allowedBranch, $allowedPeriod);

    $this->putJson(route('admin.roles.update', $role->doc_num), [
        'name' => $role->name,
        'permissions' => [],
        'accessible_company_doc_nums' => [$allowedCompany->doc_num],
        'accessible_branch_doc_nums' => [$blockedBranch->doc_num],
        'accessible_financial_period_doc_nums' => [$blockedPeriod->doc_num],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessible_branch_doc_nums', 'accessible_financial_period_doc_nums']);
});

test('full admins can still assign all operating scope by leaving selectors empty', function () {
    $admin = operatingScopeAccessUser(['roles.create', 'roles.operating_scope.manage']);
    $firstCompany = operatingScopeAccessCompany('Full Assign First Company');
    $secondCompany = operatingScopeAccessCompany('Full Assign Second Company');

    $ids = collect($this->actingAs($admin)
        ->getJson(route('admin.select2.companies', [
            'access_scope' => 'operating_scope',
            'q' => 'Full Assign',
        ]))
        ->assertOk()
        ->json('results'))->pluck('id')->all();

    expect($ids)
        ->toContain($firstCompany->doc_num)
        ->toContain($secondCompany->doc_num);

    $this->postJson(route('admin.roles.store'), [
        'name' => 'full-admin-unrestricted-role',
        'permissions' => [],
    ])
        ->assertOk();

    $role = Role::query()->where('name', 'full-admin-unrestricted-role')->firstOrFail();

    expect($role->company_access_restricted)->toBeFalse()
        ->and($role->branch_access_restricted)->toBeFalse()
        ->and($role->financial_period_access_restricted)->toBeFalse();
});
