<?php

use App\Models\User;
use Database\Seeders\EmergencyRecoverySeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Setting;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\PwaSettingsService;
use Spatie\Permission\Models\Permission;

test('emergency recovery seeder restores an empty database idempotently', function () {
    $this->seed(EmergencyRecoverySeeder::class);
    $this->seed(EmergencyRecoverySeeder::class);

    $admin = User::query()->where('email', 'admin@erp.local')->firstOrFail();
    $role = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    $company = Company::query()->where('name', 'Short Coded')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('name', 'Main Branch')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('name', now()->year)->firstOrFail();
    $registryPermissions = app(PermissionRegistryService::class)->all();

    expect(User::query()->where('email', 'admin@erp.local')->count())->toBe(1)
        ->and(Role::query()->where('name', 'admin')->where('guard_name', 'web')->count())->toBe(1)
        ->and(Company::query()->where('name', 'Short Coded')->count())->toBe(1)
        ->and(Branch::query()->where('company_id', $company->getKey())->where('name', 'Main Branch')->count())->toBe(1)
        ->and(FinancialPeriod::query()->where('company_id', $company->getKey())->where('name', now()->year)->count())->toBe(1)
        ->and($admin->status)->toBe('active')
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($role->company_access_restricted)->toBeFalse()
        ->and($role->branch_access_restricted)->toBeFalse()
        ->and($role->financial_period_access_restricted)->toBeFalse()
        ->and($role->permissions()->count())->toBe(count($registryPermissions))
        ->and($company->status)->toBe('active')
        ->and($branch->status)->toBe('active')
        ->and($period->is_closed)->toBeFalse()
        ->and($period->company_id)->toBe($company->getKey());

    expect(Auth::attempt(['email' => 'admin@erp.local', 'password' => 'password']))->toBeTrue();
    Auth::logout();
});

test('emergency recovery seeder creates selectable operating context and startup settings', function () {
    $this->seed(EmergencyRecoverySeeder::class);

    $admin = User::query()->where('email', 'admin@erp.local')->firstOrFail();
    $company = Company::query()->where('name', 'Short Coded')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('name', 'Main Branch')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('name', now()->year)->firstOrFail();
    $scope = app(OperatingScopeAccessService::class);

    expect($scope->canAccessCompany($admin, $company))->toBeTrue()
        ->and($scope->canAccessBranch($admin, $branch, $company))->toBeTrue()
        ->and($scope->canAccessFinancialPeriod($admin, $period, $company))->toBeTrue();

    $this->actingAs($admin)
        ->postJson(route('admin.operating-context.select'), [
            'company_doc_num' => $company->doc_num,
            'branch_doc_num' => $branch->doc_num,
            'financial_period_doc_num' => $period->doc_num,
        ])
        ->assertOk()
        ->assertJsonPath('data.current.requires_selection', false);

    foreach ([
        'document_numbers.companies.prefix',
        'document_numbers.branches.prefix',
        'document_numbers.financial_periods.prefix',
        'document_numbers.users.prefix',
        'document_numbers.products.prefix',
        PwaSettingsService::AppNameKey,
    ] as $settingKey) {
        expect(Setting::query()->where('key', $settingKey)->exists())->toBeTrue();
    }

    expect(DB::table('currencies')
        ->where('company_id', $company->getKey())
        ->where('code', 'EGP')
        ->where('is_main', true)
        ->exists())->toBeTrue()
        ->and(DB::table('accounts')
            ->where('company_id', $company->getKey())
            ->whereIn('account_code', ['1', '2', '3', '4', '5'])
            ->count())->toBe(5);

    $this->actingAs($admin)
        ->get(route('admin.settings.pwa'))
        ->assertOk();
});

test('permission seeder remains independently runnable after emergency recovery', function () {
    $this->seed(EmergencyRecoverySeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    $registryPermissions = app(PermissionRegistryService::class)->all();
    $adminRole = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();

    expect(Permission::query()->whereIn('name', $registryPermissions)->where('guard_name', 'web')->count())->toBe(count($registryPermissions))
        ->and(Permission::query()->select('name', 'guard_name')->groupBy('name', 'guard_name')->havingRaw('COUNT(*) > 1')->count())->toBe(0)
        ->and($adminRole->permissions()->count())->toBe(count($registryPermissions));
});
