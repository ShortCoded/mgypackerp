<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;

function baselineSeederContextSession(): array
{
    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();

    return [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
}

function baselineSeederFlattenPermissionLabels(array $nodes): array
{
    $labels = [];

    foreach ($nodes as $node) {
        $labels = array_merge($labels, collect($node['permissions'] ?? [])->pluck('label')->all());
        $labels = array_merge($labels, baselineSeederFlattenPermissionLabels($node['children'] ?? []));
    }

    return $labels;
}

test('default database seeder is baseline only and idempotent', function (): void {
    $this->seed(DatabaseSeeder::class);
    $firstPermissionCount = Permission::query()->count();

    $this->seed(DatabaseSeeder::class);

    $registryPermissions = app(PermissionRegistryService::class)->all();
    $adminRole = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    $adminUser = User::query()->where('email', 'info@shortcoded.com')->firstOrFail();
    $company = Company::query()->firstOrFail();

    expect(Company::query()->count())->toBe(1)
        ->and(Branch::query()->count())->toBe(1)
        ->and(FinancialPeriod::query()->count())->toBe(1)
        ->and(Role::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1)
        ->and($adminUser->hasRole('admin'))->toBeTrue()
        ->and($adminRole->company_access_restricted)->toBeFalse()
        ->and($adminRole->branch_access_restricted)->toBeFalse()
        ->and($adminRole->financial_period_access_restricted)->toBeFalse()
        ->and($adminRole->permissions()->count())->toBe(count($registryPermissions))
        ->and(Permission::query()->whereIn('name', $registryPermissions)->where('guard_name', 'web')->count())->toBe(count($registryPermissions))
        ->and(Permission::query()->select('name', 'guard_name')->groupBy('name', 'guard_name')->havingRaw('COUNT(*) > 1')->count())->toBe(0)
        ->and(Permission::query()->count())->toBe($firstPermissionCount)
        ->and(DB::table('account_classifications')->count())->toBe(0)
        ->and(DB::table('currencies')->count())->toBe(0)
        ->and(DB::table('products')->count())->toBe(0)
        ->and(Account::query()->where('company_id', $company->getKey())->count())->toBe(5)
        ->and(Account::query()->where('company_id', $company->getKey())->whereNull('parent_id')->count())->toBe(5)
        ->and(CostCenter::query()->where('company_id', $company->getKey())->count())->toBe(2)
        ->and(CostCenter::query()->where('company_id', $company->getKey())->whereNull('parent_id')->count())->toBe(2);
});

test('baseline chart of accounts creates only protected roots', function (): void {
    $this->seed(DatabaseSeeder::class);
    $company = Company::query()->firstOrFail();
    $accounts = Account::query()->where('company_id', $company->getKey())->orderBy('account_code')->get();

    expect($accounts->pluck('account_code')->all())->toBe(Account::ProtectedRootCodes);

    $accounts->each(function (Account $account): void {
        expect($account->parent_id)->toBeNull()
            ->and($account->is_group)->toBeTrue()
            ->and($account->is_postable)->toBeFalse()
            ->and($account->is_system)->toBeTrue()
            ->and($account->isProtectedRoot())->toBeTrue();
    });
});

test('baseline cost centers creates only protected production and service roots', function (): void {
    $this->seed(DatabaseSeeder::class);
    $company = Company::query()->firstOrFail();
    $costCenters = CostCenter::query()->where('company_id', $company->getKey())->orderBy('cost_center_code')->get();

    expect($costCenters->pluck('cost_center_code')->all())->toBe(CostCenter::ProtectedRootCodes)
        ->and($costCenters->pluck('name')->all())->toBe(['إنتاجي', 'خدمي']);

    $costCenters->each(function (CostCenter $costCenter): void {
        expect($costCenter->parent_id)->toBeNull()
            ->and($costCenter->is_group)->toBeTrue()
            ->and($costCenter->isProtectedRoot())->toBeTrue();
    });
});

test('protected root accounts cannot be deleted individually or in bulk', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('email', 'info@shortcoded.com')->firstOrFail();
    $root = Account::query()->where('account_code', '1')->firstOrFail();
    $session = baselineSeederContextSession();

    $this->withSession($session)
        ->actingAs($admin)
        ->deleteJson(route('admin.accounting.accounts.destroy', $root->doc_num))
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($root->refresh()->trashed())->toBeFalse();

    $this->withSession($session)
        ->actingAs($admin)
        ->deleteJson(route('admin.accounting.accounts.bulk-delete'), [
            'doc_nums' => [$root->doc_num],
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($root->refresh()->trashed())->toBeFalse();
});

test('protected root cost centers cannot be deleted individually or in bulk', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('email', 'info@shortcoded.com')->firstOrFail();
    $root = CostCenter::query()->where('cost_center_code', CostCenter::RootProductionCode)->firstOrFail();
    $session = baselineSeederContextSession();

    $this->withSession($session)
        ->actingAs($admin)
        ->deleteJson(route('admin.accounting.cost-centers.destroy', $root->doc_num))
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($root->refresh()->trashed())->toBeFalse();

    $this->withSession($session)
        ->actingAs($admin)
        ->deleteJson(route('admin.accounting.cost-centers.bulk-delete'), [
            'doc_nums' => [$root->doc_num],
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($root->refresh()->trashed())->toBeFalse();
});

test('permission seeder is idempotent and registry extracts new menu permissions', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    $registry = app(PermissionRegistryService::class);
    $fakePermissions = $registry->extractFromMenuItems([
        [
            'label' => 'fake_screen',
            'route' => 'admin.fake.index',
            'permission' => 'fake.screen.view',
            'actions' => [
                'view' => 'fake.screen.view',
                'approve' => 'fake.screen.approve',
            ],
        ],
    ]);

    expect(Permission::query()->select('name', 'guard_name')->groupBy('name', 'guard_name')->havingRaw('COUNT(*) > 1')->count())->toBe(0)
        ->and($fakePermissions)->toContain('fake.screen.view', 'fake.screen.approve');
});

test('permission labels are localized for custom accounting and finance actions', function (): void {
    $registry = app(PermissionRegistryService::class);
    $permissions = [
        'accounts.account_code.control',
        'opening_balances.approve',
        'opening_balances.cancel',
        'cost_centers.print',
    ];

    app()->setLocale('ar');

    $arabicLabels = baselineSeederFlattenPermissionLabels($registry->groupedForForm($permissions));

    expect($registry->labelForPermission('accounts.account_code.control', 'account_code_control'))->toBe('التحكم في كود الحساب')
        ->and($registry->labelForPermission('opening_balances.approve', 'approve'))->toBe('اعتماد الرصيد الافتتاحي')
        ->and($registry->labelForPermission('opening_balances.cancel', 'cancel'))->toBe('إلغاء اعتماد الرصيد الافتتاحي')
        ->and($arabicLabels)->toContain('التحكم في كود الحساب')
        ->and($arabicLabels)->toContain('اعتماد الرصيد الافتتاحي')
        ->and($arabicLabels)->toContain('إلغاء اعتماد الرصيد الافتتاحي')
        ->and($arabicLabels)->toContain('طباعة مراكز التكلفة')
        ->and($arabicLabels)->not->toContain('Control Account Account Code')
        ->and($arabicLabels)->not->toContain('Approve Opening Balance')
        ->and($arabicLabels)->not->toContain('Cancel Opening Balance');

    app()->setLocale('en');

    $englishLabels = baselineSeederFlattenPermissionLabels($registry->groupedForForm($permissions));

    expect($registry->labelForPermission('accounts.account_code.control', 'account_code_control'))->toBe('Control account code')
        ->and($registry->labelForPermission('opening_balances.approve', 'approve'))->toBe('Approve opening balance')
        ->and($registry->labelForPermission('opening_balances.cancel', 'cancel'))->toBe('Cancel opening balance')
        ->and($englishLabels)->toContain('Control account code')
        ->and($englishLabels)->toContain('Approve opening balance')
        ->and($englishLabels)->toContain('Cancel opening balance')
        ->and($englishLabels)->toContain('Print Cost Centers');
});
