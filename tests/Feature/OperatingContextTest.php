<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\IntendedUrlService;
use Modules\Core\Services\OperatingContextService;

function operatingContextCompany(array $overrides = []): Company
{
    if ($overrides === []) {
        $company = Company::query()->first();

        if ($company instanceof Company) {
            return $company;
        }
    }

    return Company::factory()->create([
        'name' => 'Context Company',
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
        ...$overrides,
    ]);
}

function operatingContextBranch(array $overrides = []): Branch
{
    static $documentNumber = 7000;

    $documentNumber++;
    $company = $overrides['company'] ?? operatingContextCompany();
    unset($overrides['company']);

    return Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Main Branch '.$documentNumber,
        'type' => Branch::TypeFactory,
        'status' => 'active',
        ...$overrides,
    ]);
}

function operatingContextPeriod(array $overrides = []): FinancialPeriod
{
    static $documentNumber = 8000;

    $documentNumber++;
    $company = $overrides['company'] ?? operatingContextCompany();
    unset($overrides['company']);

    return FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'FY '.$documentNumber,
        'from_date' => CarbonImmutable::create(2026, 1, 1)->toDateString(),
        'to_date' => CarbonImmutable::create(2026, 12, 31)->toDateString(),
        'is_closed' => false,
        ...$overrides,
    ]);
}

function operatingContextRestrictedRole(array $overrides = []): Role
{
    static $documentNumber = 9000;

    $documentNumber++;

    return Role::query()->create([
        'name' => 'context-role-'.$documentNumber,
        'guard_name' => 'web',
        'doc_number' => $documentNumber,
        'doc_num' => 'Role-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_access_restricted' => false,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
        ...$overrides,
    ]);
}

test('deferred intended page opens after an operating context is selected from the dashboard', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => '/dashboard/pending-decisions'])
        ->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect('/dashboard')
        ->assertSessionHas(IntendedUrlService::AfterOperatingContextSessionKey, '/dashboard/pending-decisions');

    $branch = operatingContextBranch();
    $company = $branch->company;
    $period = operatingContextPeriod(['company' => $company]);

    $this->postJson(route('admin.operating-context.select'), [
        'company_doc_num' => $company->doc_num,
        'branch_doc_num' => $branch->doc_num,
        'financial_period_doc_num' => $period->doc_num,
    ])->assertOk()->assertJsonPath('reload', true);

    $this->get('/dashboard')
        ->assertRedirect('/dashboard/pending-decisions')
        ->assertSessionMissing(IntendedUrlService::AfterOperatingContextSessionKey);
});

test('authenticated user can save a valid company branch and financial period in session', function () {
    $user = User::factory()->create();
    $branch = operatingContextBranch(['name' => 'Cairo Branch']);
    $company = $branch->company;
    $period = operatingContextPeriod(['company' => $company, 'name' => '2026']);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $company->doc_num,
            'branch_doc_num' => $branch->doc_num,
            'financial_period_doc_num' => $period->doc_num,
        ])
        ->assertOk()
        ->assertJsonPath('reload', true)
        ->assertJsonPath('data.current.company.doc_num', $company->doc_num)
        ->assertJsonPath('data.current.branch.doc_num', $branch->doc_num)
        ->assertJsonPath('data.current.financial_period.doc_num', $period->doc_num)
        ->assertSessionHas(OperatingContextService::CompanyIdKey, $company->getKey())
        ->assertSessionHas(OperatingContextService::CompanyDocNumKey, $company->doc_num)
        ->assertSessionHas(OperatingContextService::BranchIdKey, $branch->getKey())
        ->assertSessionHas(OperatingContextService::BranchDocNumKey, $branch->doc_num)
        ->assertSessionHas(OperatingContextService::FinancialPeriodIdKey, $period->getKey())
        ->assertSessionHas(OperatingContextService::FinancialPeriodDocNumKey, $period->doc_num);
});

test('selecting operating context updates the current active presence session context', function () {
    $user = User::factory()->create();
    $branch = operatingContextBranch(['name' => 'Presence Branch']);
    $company = $branch->company;
    $period = operatingContextPeriod(['company' => $company, 'name' => 'Presence Period']);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $company->doc_num,
            'branch_doc_num' => $branch->doc_num,
            'financial_period_doc_num' => $period->doc_num,
        ])
        ->assertOk();

    $presence = UserPresenceSession::query()
        ->where('user_id', $user->getKey())
        ->where('status', UserPresenceService::StatusOnline)
        ->firstOrFail();

    expect($presence->branch_id)
        ->toBe($branch->getKey())
        ->and($presence->branch_doc_num)->toBe($branch->doc_num)
        ->and($presence->branch_name)->toBe('Presence Branch')
        ->and($presence->financial_period_id)->toBe($period->getKey())
        ->and($presence->financial_period_doc_num)->toBe($period->doc_num)
        ->and($presence->financial_period_name)->toBe('Presence Period');
});

test('current context options endpoint returns selected display labels', function () {
    $user = User::factory()->create();
    $branch = operatingContextBranch(['name' => 'Alex Branch', 'type' => Branch::TypeShowroom]);
    $company = $branch->company;
    $period = operatingContextPeriod(['company' => $company, 'name' => 'Closed 2026', 'is_closed' => true]);

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
        ->assertOk()
        ->assertJsonPath('data.current.requires_selection', false)
        ->assertJsonPath('data.current.company.name', $company->name)
        ->assertJsonPath('data.current.branch.name', 'Alex Branch')
        ->assertJsonPath('data.current.branch.label', "Alex Branch / {$branch->doc_num} / {$company->name} / ".__('branches.types.showroom'))
        ->assertJsonPath('data.current.financial_period.name', 'Closed 2026')
        ->assertJsonPath('data.current.financial_period.label', "Closed 2026 / {$period->doc_num} / ".__('financial_periods.statuses.closed'));
});

test('company-specific options do not hydrate stale branch or period selections from another company', function () {
    $user = User::factory()->create();
    $firstCompany = operatingContextCompany(['name' => 'Previous Context Company']);
    $secondCompany = operatingContextCompany(['name' => 'Next Context Company']);
    $firstBranch = operatingContextBranch(['company' => $firstCompany, 'name' => 'Previous Branch']);
    $secondBranch = operatingContextBranch(['company' => $secondCompany, 'name' => 'Next Branch']);
    $firstPeriod = operatingContextPeriod(['company' => $firstCompany, 'name' => 'Previous Period']);
    $secondPeriod = operatingContextPeriod(['company' => $secondCompany, 'name' => 'Next Period']);

    $payload = $this->withSession([
        OperatingContextService::CompanyIdKey => $firstCompany->getKey(),
        OperatingContextService::CompanyDocNumKey => $firstCompany->doc_num,
        OperatingContextService::BranchIdKey => $firstBranch->getKey(),
        OperatingContextService::BranchDocNumKey => $firstBranch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $firstPeriod->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $firstPeriod->doc_num,
    ])
        ->actingAs($user)
        ->getJson(route('admin.operating-context.options', ['company_doc_num' => $secondCompany->doc_num]))
        ->assertOk()
        ->assertJsonPath('data.current.company.doc_num', $secondCompany->doc_num)
        ->assertJsonPath('data.current.branch', null)
        ->assertJsonPath('data.current.financial_period', null)
        ->json('data');

    expect(collect($payload['branches'])->pluck('doc_num')->all())
        ->toContain($secondBranch->doc_num)
        ->not->toContain($firstBranch->doc_num)
        ->and(collect($payload['financial_periods'])->pluck('doc_num')->all())
        ->toContain($secondPeriod->doc_num)
        ->not->toContain($firstPeriod->doc_num);
});

test('current context clears stale inactive session selections', function () {
    $user = User::factory()->create();
    $branch = operatingContextBranch(['status' => 'inactive']);
    $company = $branch->company;
    $period = operatingContextPeriod(['company' => $company]);

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
        ->assertOk()
        ->assertJsonPath('data.current.requires_selection', true)
        ->assertSessionMissing(OperatingContextService::CompanyIdKey)
        ->assertSessionMissing(OperatingContextService::CompanyDocNumKey)
        ->assertSessionMissing(OperatingContextService::BranchIdKey)
        ->assertSessionMissing(OperatingContextService::BranchDocNumKey)
        ->assertSessionMissing(OperatingContextService::FinancialPeriodIdKey)
        ->assertSessionMissing(OperatingContextService::FinancialPeriodDocNumKey);
});

test('inactive and deleted records are rejected and not stored', function () {
    $user = User::factory()->create();
    $inactiveBranch = operatingContextBranch(['status' => 'inactive']);
    $deletedBranch = operatingContextBranch();
    $deletedBranch->delete();
    $inactiveCompanyOpenPeriod = operatingContextPeriod(['company' => $inactiveBranch->company]);
    $deletedCompanyOpenPeriod = operatingContextPeriod(['company' => $deletedBranch->company]);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $inactiveBranch->company->doc_num,
            'branch_doc_num' => $inactiveBranch->doc_num,
            'financial_period_doc_num' => $inactiveCompanyOpenPeriod->doc_num,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_doc_num']);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $deletedBranch->company->doc_num,
            'branch_doc_num' => $deletedBranch->doc_num,
            'financial_period_doc_num' => $deletedCompanyOpenPeriod->doc_num,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_doc_num']);
});

test('operating context options include open and closed periods with status and exclude deleted periods', function () {
    $user = User::factory()->create();
    $company = operatingContextCompany(['name' => 'Period Status Company']);
    $openPeriod = operatingContextPeriod(['company' => $company, 'name' => 'FY 2026 - Runtime Demo', 'is_closed' => false]);
    $closedPeriod = operatingContextPeriod(['company' => $company, 'name' => 'FY 2025 - Runtime Demo', 'is_closed' => true]);
    $deletedPeriod = operatingContextPeriod(['company' => $company, 'name' => 'Deleted Runtime Period', 'is_closed' => false]);
    $deletedPeriod->delete();

    $periods = $this->actingAs($user)
        ->getJson(route('admin.operating-context.options', ['company_doc_num' => $company->doc_num]))
        ->assertOk()
        ->json('data.financial_periods');

    expect(collect($periods)->pluck('doc_num')->all())
        ->toContain($openPeriod->doc_num, $closedPeriod->doc_num)
        ->not->toContain($deletedPeriod->doc_num)
        ->and(collect($periods)->firstWhere('doc_num', $openPeriod->doc_num)['text'] ?? '')
        ->toBe("FY 2026 - Runtime Demo / {$openPeriod->doc_num} / ".__('financial_periods.statuses.open'))
        ->and(collect($periods)->firstWhere('doc_num', $closedPeriod->doc_num)['text'] ?? '')
        ->toBe("FY 2025 - Runtime Demo / {$closedPeriod->doc_num} / ".__('financial_periods.statuses.closed'));
});

test('closed financial period can be selected as operating context when allowed', function () {
    $user = User::factory()->create();
    $branch = operatingContextBranch();
    $company = $branch->company;
    $closedPeriod = operatingContextPeriod(['company' => $company, 'name' => 'Closed Context Period', 'is_closed' => true]);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $company->doc_num,
            'branch_doc_num' => $branch->doc_num,
            'financial_period_doc_num' => $closedPeriod->doc_num,
        ])
        ->assertOk()
        ->assertJsonPath('data.current.financial_period.doc_num', $closedPeriod->doc_num)
        ->assertJsonPath('data.current.financial_period.label', "Closed Context Period / {$closedPeriod->doc_num} / ".__('financial_periods.statuses.closed'))
        ->assertSessionHas(OperatingContextService::FinancialPeriodIdKey, $closedPeriod->getKey());
});

test('role operating scope limits selectable companies branches and financial periods', function () {
    $user = User::factory()->create();
    $allowedCompany = operatingContextCompany(['name' => 'Allowed Company']);
    $blockedCompany = operatingContextCompany(['name' => 'Blocked Company']);
    $allowedBranch = operatingContextBranch(['company' => $allowedCompany, 'name' => 'Allowed Branch']);
    $blockedBranch = operatingContextBranch(['company' => $blockedCompany, 'name' => 'Blocked Branch']);
    $allowedPeriod = operatingContextPeriod(['company' => $allowedCompany, 'name' => 'Allowed Period']);
    $blockedPeriod = operatingContextPeriod(['company' => $blockedCompany, 'name' => 'Blocked Period']);
    $role = operatingContextRestrictedRole(['company_access_restricted' => true]);

    DB::table('role_company_access')->insert([
        'role_id' => $role->getKey(),
        'company_id' => $allowedCompany->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('role_branch_access')->insert([
        'role_id' => $role->getKey(),
        'branch_id' => $allowedBranch->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('role_financial_period_access')->insert([
        'role_id' => $role->getKey(),
        'financial_period_id' => $allowedPeriod->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user->assignRole($role);

    $this->actingAs($user)
        ->getJson(route('admin.operating-context.options'))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Allowed Company'])
        ->assertJsonMissing(['name' => 'Blocked Company'])
        ->assertJsonFragment(['name' => 'Allowed Branch'])
        ->assertJsonMissing(['name' => 'Blocked Branch'])
        ->assertJsonFragment(['name' => 'Allowed Period'])
        ->assertJsonMissing(['name' => 'Blocked Period']);

    $this->actingAs($user)
        ->getJson(route('admin.operating-context.options', ['company_doc_num' => $allowedCompany->doc_num]))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Allowed Branch'])
        ->assertJsonMissing(['name' => 'Blocked Branch'])
        ->assertJsonFragment(['name' => 'Allowed Period']);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $allowedCompany->doc_num,
            'branch_doc_num' => $blockedBranch->doc_num,
            'financial_period_doc_num' => $allowedPeriod->doc_num,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_doc_num']);
});

test('operating context select2 endpoints return only scoped dependency results', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $allowedCompany = operatingContextCompany(['name' => 'Allowed Select2 Company']);
    $blockedCompany = operatingContextCompany(['name' => 'Blocked Select2 Company']);
    $allowedBranch = operatingContextBranch(['company' => $allowedCompany, 'name' => 'Allowed Select2 Branch', 'type' => Branch::TypeWarehouse]);
    $blockedBranch = operatingContextBranch(['company' => $blockedCompany, 'name' => 'Blocked Select2 Branch']);
    $allowedPeriod = operatingContextPeriod(['company' => $allowedCompany, 'name' => 'Allowed Select2 Period', 'is_closed' => false]);
    $allowedClosedPeriod = operatingContextPeriod(['company' => $allowedCompany, 'name' => 'Allowed Closed Select2 Period', 'is_closed' => true]);
    $deletedPeriod = operatingContextPeriod(['company' => $allowedCompany, 'name' => 'Deleted Select2 Period', 'is_closed' => false]);
    $blockedPeriod = operatingContextPeriod(['company' => $blockedCompany, 'name' => 'Blocked Select2 Period']);
    $role = operatingContextRestrictedRole(['company_access_restricted' => true]);

    DB::table('role_company_access')->insert([
        'role_id' => $role->getKey(),
        'company_id' => $allowedCompany->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_branch_access')->insert([
        'role_id' => $role->getKey(),
        'branch_id' => $allowedBranch->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_financial_period_access')->insert([
        [
            'role_id' => $role->getKey(),
            'financial_period_id' => $allowedPeriod->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'role_id' => $role->getKey(),
            'financial_period_id' => $allowedClosedPeriod->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'role_id' => $role->getKey(),
            'financial_period_id' => $deletedPeriod->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $deletedPeriod->delete();

    $user->assignRole($role);

    $companyResults = $this->actingAs($user)
        ->getJson(route('admin.select2.companies', ['access_scope' => 'operating_scope']))
        ->assertOk()
        ->json('results');

    expect(collect($companyResults)->pluck('id')->all())
        ->toContain($allowedCompany->doc_num)
        ->not->toContain($blockedCompany->doc_num);

    $this->actingAs($user)
        ->getJson(route('admin.select2.branches', ['access_scope' => 'operating_scope']))
        ->assertOk()
        ->assertJsonPath('results', []);

    $branchResults = $this->actingAs($user)
        ->getJson(route('admin.select2.branches', ['access_scope' => 'operating_scope', 'company_doc_num' => $allowedCompany->doc_num]))
        ->assertOk()
        ->json('results');

    expect(collect($branchResults)->pluck('id')->all())
        ->toContain($allowedBranch->doc_num)
        ->not->toContain($blockedBranch->doc_num)
        ->and(collect($branchResults)->firstWhere('id', $allowedBranch->doc_num)['text'] ?? '')
        ->toBe("Allowed Select2 Branch / {$allowedBranch->doc_num} / Allowed Select2 Company / Warehouse");

    $this->actingAs($user)
        ->getJson(route('admin.select2.financial-periods', ['access_scope' => 'operating_scope']))
        ->assertOk()
        ->assertJsonPath('results', []);

    $periodResults = $this->actingAs($user)
        ->getJson(route('admin.select2.financial-periods', ['access_scope' => 'operating_scope', 'company_doc_num' => $allowedCompany->doc_num]))
        ->assertOk()
        ->json('results');

    expect(collect($periodResults)->pluck('id')->all())
        ->toContain($allowedPeriod->doc_num, $allowedClosedPeriod->doc_num)
        ->not->toContain($blockedPeriod->doc_num, $deletedPeriod->doc_num)
        ->and(collect($periodResults)->firstWhere('id', $allowedPeriod->doc_num)['text'] ?? '')
        ->toBe("Allowed Select2 Period / {$allowedPeriod->doc_num} / Open")
        ->and(collect($periodResults)->firstWhere('id', $allowedClosedPeriod->doc_num)['text'] ?? '')
        ->toBe("Allowed Closed Select2 Period / {$allowedClosedPeriod->doc_num} / Closed");
});

test('unauthorized company selection is rejected', function () {
    $user = User::factory()->create();
    $allowedCompany = operatingContextCompany(['name' => 'Allowed Scope Company']);
    $blockedCompany = operatingContextCompany(['name' => 'Blocked Scope Company']);
    $allowedBranch = operatingContextBranch(['company' => $allowedCompany]);
    $blockedBranch = operatingContextBranch(['company' => $blockedCompany]);
    $period = operatingContextPeriod(['company' => $allowedCompany]);
    $role = operatingContextRestrictedRole(['company_access_restricted' => true, 'branch_access_restricted' => false, 'financial_period_access_restricted' => false]);

    DB::table('role_company_access')->insert([
        'role_id' => $role->getKey(),
        'company_id' => $allowedCompany->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user->assignRole($role);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $blockedCompany->doc_num,
            'branch_doc_num' => $blockedBranch->doc_num,
            'financial_period_doc_num' => $period->doc_num,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_doc_num', 'branch_doc_num', 'financial_period_doc_num']);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $allowedCompany->doc_num,
            'branch_doc_num' => $allowedBranch->doc_num,
            'financial_period_doc_num' => $period->doc_num,
        ])
        ->assertOk();
});

test('branch must belong to selected company', function () {
    $user = User::factory()->create();
    $company = operatingContextCompany(['name' => 'Selected Company']);
    $otherCompany = operatingContextCompany(['name' => 'Other Company']);
    $branch = operatingContextBranch(['company' => $otherCompany]);
    $period = operatingContextPeriod(['company' => $company]);

    $this->actingAs($user)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $company->doc_num,
            'branch_doc_num' => $branch->doc_num,
            'financial_period_doc_num' => $period->doc_num,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_doc_num']);
});

test('single allowed company branch and financial period are auto selected', function () {
    $user = User::factory()->create();
    $company = operatingContextCompany(['name' => 'Single Company']);
    $branch = operatingContextBranch(['company' => $company, 'name' => 'Single Branch']);
    $period = operatingContextPeriod(['company' => $company, 'name' => 'Single Period']);
    $role = operatingContextRestrictedRole(['company_access_restricted' => true]);

    DB::table('role_company_access')->insert([
        'role_id' => $role->getKey(),
        'company_id' => $company->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_branch_access')->insert([
        'role_id' => $role->getKey(),
        'branch_id' => $branch->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_financial_period_access')->insert([
        'role_id' => $role->getKey(),
        'financial_period_id' => $period->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user->assignRole($role);

    $this->actingAs($user)
        ->getJson(route('admin.operating-context.options'))
        ->assertOk()
        ->assertJsonPath('data.current.requires_selection', false)
        ->assertJsonPath('data.current.company.doc_num', $company->doc_num)
        ->assertJsonPath('data.current.branch.doc_num', $branch->doc_num)
        ->assertJsonPath('data.current.financial_period.doc_num', $period->doc_num)
        ->assertJsonPath('data.auto_select.company.doc_num', $company->doc_num)
        ->assertJsonPath('data.auto_select.branch.doc_num', $branch->doc_num)
        ->assertJsonPath('data.auto_select.financial_period.doc_num', $period->doc_num)
        ->assertJsonPath('data.option_counts.companies', 1)
        ->assertJsonPath('data.option_counts.branches', 1)
        ->assertJsonPath('data.option_counts.financial_periods', 1)
        ->assertSessionHas(OperatingContextService::CompanyIdKey, $company->getKey())
        ->assertSessionHas(OperatingContextService::BranchIdKey, $branch->getKey())
        ->assertSessionHas(OperatingContextService::FinancialPeriodIdKey, $period->getKey());
});

test('multiple allowed options require explicit popup selection', function () {
    $user = User::factory()->create();
    operatingContextBranch(['company' => operatingContextCompany(['name' => 'Multi Company A'])]);
    operatingContextBranch(['company' => operatingContextCompany(['name' => 'Multi Company B'])]);
    operatingContextPeriod(['name' => 'Multi Period']);

    $this->actingAs($user)
        ->getJson(route('admin.operating-context.options'))
        ->assertOk()
        ->assertJsonPath('data.current.requires_selection', true)
        ->assertJsonPath('data.auto_select.company', null)
        ->assertJsonPath('data.auto_select.branch', null)
        ->assertJsonPath('data.auto_select.financial_period', null)
        ->assertJsonPath('data.branches', [])
        ->assertJsonPath('data.financial_periods', [])
        ->assertSessionMissing(OperatingContextService::CompanyIdKey)
        ->assertSessionMissing(OperatingContextService::BranchIdKey)
        ->assertSessionMissing(OperatingContextService::FinancialPeriodIdKey);
});

test('single allowed company with multiple branches only preselects the company', function () {
    $user = User::factory()->create();
    $company = operatingContextCompany(['name' => 'Single Branch Choice Company']);
    $firstBranch = operatingContextBranch(['company' => $company, 'name' => 'Branch Choice A']);
    $secondBranch = operatingContextBranch(['company' => $company, 'name' => 'Branch Choice B']);
    $period = operatingContextPeriod(['company' => $company, 'name' => 'Only Period Choice']);
    $role = operatingContextRestrictedRole(['company_access_restricted' => true]);

    DB::table('role_company_access')->insert([
        'role_id' => $role->getKey(),
        'company_id' => $company->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_branch_access')->insert([
        [
            'role_id' => $role->getKey(),
            'branch_id' => $firstBranch->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'role_id' => $role->getKey(),
            'branch_id' => $secondBranch->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('role_financial_period_access')->insert([
        'role_id' => $role->getKey(),
        'financial_period_id' => $period->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user->assignRole($role);

    $this->actingAs($user)
        ->getJson(route('admin.operating-context.options'))
        ->assertOk()
        ->assertJsonPath('data.current.requires_selection', true)
        ->assertJsonPath('data.auto_select.company.doc_num', $company->doc_num)
        ->assertJsonPath('data.auto_select.branch', null)
        ->assertJsonPath('data.auto_select.financial_period.doc_num', $period->doc_num)
        ->assertJsonPath('data.option_counts.companies', 1)
        ->assertJsonPath('data.option_counts.branches', 2)
        ->assertJsonPath('data.option_counts.financial_periods', 1)
        ->assertSessionMissing(OperatingContextService::CompanyIdKey)
        ->assertSessionMissing(OperatingContextService::BranchIdKey)
        ->assertSessionMissing(OperatingContextService::FinancialPeriodIdKey);
});

test('single allowed company and branch with multiple periods leaves period empty', function () {
    $user = User::factory()->create();
    $company = operatingContextCompany(['name' => 'Single Period Choice Company']);
    $branch = operatingContextBranch(['company' => $company, 'name' => 'Only Branch Choice']);
    $firstPeriod = operatingContextPeriod(['company' => $company, 'name' => 'Period Choice A']);
    $secondPeriod = operatingContextPeriod(['company' => $company, 'name' => 'Period Choice B']);
    $role = operatingContextRestrictedRole(['company_access_restricted' => true]);

    DB::table('role_company_access')->insert([
        'role_id' => $role->getKey(),
        'company_id' => $company->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_branch_access')->insert([
        'role_id' => $role->getKey(),
        'branch_id' => $branch->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_financial_period_access')->insert([
        [
            'role_id' => $role->getKey(),
            'financial_period_id' => $firstPeriod->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'role_id' => $role->getKey(),
            'financial_period_id' => $secondPeriod->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $user->assignRole($role);

    $this->actingAs($user)
        ->getJson(route('admin.operating-context.options'))
        ->assertOk()
        ->assertJsonPath('data.current.requires_selection', true)
        ->assertJsonPath('data.auto_select.company.doc_num', $company->doc_num)
        ->assertJsonPath('data.auto_select.branch.doc_num', $branch->doc_num)
        ->assertJsonPath('data.auto_select.financial_period', null)
        ->assertJsonPath('data.option_counts.companies', 1)
        ->assertJsonPath('data.option_counts.branches', 1)
        ->assertJsonPath('data.option_counts.financial_periods', 2)
        ->assertSessionMissing(OperatingContextService::CompanyIdKey)
        ->assertSessionMissing(OperatingContextService::BranchIdKey)
        ->assertSessionMissing(OperatingContextService::FinancialPeriodIdKey);
});

test('zero allowed options return clean empty option lists', function () {
    $user = User::factory()->create();
    $role = operatingContextRestrictedRole(['company_access_restricted' => true]);
    $user->assignRole($role);

    $payload = $this->actingAs($user)
        ->getJson(route('admin.operating-context.options'))
        ->assertOk()
        ->assertJsonPath('data.current.requires_selection', true)
        ->json('data');

    expect($payload['companies'])->toBe([])
        ->and($payload['branches'])->toBe([])
        ->and($payload['financial_periods'])->toBe([]);
});

test('layout loads operating context UI only in authenticated app shell', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-operating-context-trigger', false)
        ->assertSee('data-operating-context-modal', false)
        ->assertSee('id="operating-context-company"', false)
        ->assertSee('id="operating-context-branch"', false)
        ->assertSee('id="operating-context-financial-period"', false)
        ->assertSee('js-select2-ajax', false)
        ->assertSee('data-dependent-param="company_doc_num"', false)
        ->assertSee('data-disable-when-dependency-empty="true"', false)
        ->assertSee('admin/select2/companies', false)
        ->assertSee('admin/select2/branches', false)
        ->assertSee('admin/select2/financial-periods', false)
        ->assertSee('assets/js/modules/Core/select2-ajax.js', false)
        ->assertSee('assets/js/modules/Core/operating-context.js', false);

    $this->app['auth']->guard()->logout();

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('data-operating-context-modal', false)
        ->assertDontSee('assets/js/modules/Core/operating-context.js', false);
});

test('operating context modal script focuses without auto opening select2', function () {
    $script = file_get_contents(public_path('assets/js/modules/Core/operating-context.js'));

    expect($script)->toContain('focusCompanySelect')
        ->not->toContain("select2('open')")
        ->not->toContain('select2("open")');
});

test('operating context modal script reloads only after successful save response asks for reload', function () {
    $script = file_get_contents(public_path('assets/js/modules/Core/operating-context.js'));

    expect($script)->toContain('payload.reload === true')
        ->toContain('reloadCurrentPageAfterModalHide')
        ->toContain('window.location.reload()')
        ->toContain("error.message === 'Validation failed'");
});

test('operating context modal script loads scoped options and clears dependents on company change', function () {
    $script = file_get_contents(public_path('assets/js/modules/Core/operating-context.js'));

    expect($script)->toContain('function loadOptions')
        ->toContain('optionsUrl(companyDocNum)')
        ->toContain("loadOptions('', true)")
        ->toContain('syncOperatingContextModalSelects(true)')
        ->toContain('clearAjaxSelect(branchSelect)')
        ->toContain('clearAjaxSelect(periodSelect)')
        ->toContain('loadOptions(companyDocNum, false)');
});
