<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Accounting\Database\Seeders\BaselineChartOfAccountsSeeder;
use Modules\Accounting\Database\Seeders\BaselineCostCentersSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Database\Seeders\BoardListSeeder;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\BoardList;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;

test('default operating context seeder creates valid defaults once', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 10, 12));

    $this->seed(DefaultOperatingContextSeeder::class);
    $this->seed(DefaultOperatingContextSeeder::class);

    $company = Company::query()->where('name', 'Short Coded')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('name', 'Main Branch')->firstOrFail();
    $period = FinancialPeriod::query()->where('name', '2026')->firstOrFail();

    expect(Company::query()->where('name', 'Short Coded')->count())->toBe(1)
        ->and(Company::query()->main()->count())->toBe(1)
        ->and($company->name)->toBe('Short Coded')
        ->and($company->legal_name)->toBe('Short Coded')
        ->and($company->status)->toBe('active')
        ->and($company->is_main)->toBeTrue()
        ->and($company->doc_num)->toBe('Company-00001')
        ->and(Branch::query()->where('company_id', $company->getKey())->where('name', 'Main Branch')->count())->toBe(1)
        ->and($branch->type)->toBe('administrative')
        ->and($branch->status)->toBe('active')
        ->and($branch->doc_num)->toBe('Branch-00001')
        ->and($period->from_date?->toDateString())->toBe('2026-01-01')
        ->and($period->to_date?->toDateString())->toBe('2026-12-31')
        ->and($period->is_closed)->toBeFalse()
        ->and($period->doc_num)->toBe('Period-00001')
        ->and(FinancialPeriod::query()->where('name', '2026')->count())->toBe(1);

    Carbon::setTestNow();
});

test('default operating context seeder does not add a placeholder beside an existing customer company', function () {
    $existing = Company::factory()->main()->create([
        'name' => 'Existing Main',
    ]);

    $this->seed(DefaultOperatingContextSeeder::class);

    $existing->refresh();
    expect(Company::query()->count())->toBe(1)
        ->and($existing->is_main)->toBeTrue()
        ->and($existing->status)->toBe('active')
        ->and(Company::query()->where('name', 'Short Coded')->exists())->toBeFalse();
});

test('repeated baseline seeding preserves disabled company branch and closed period decisions', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 5, 10, 12));

    try {
        $this->seed(DefaultOperatingContextSeeder::class);
        $company = Company::query()->where('name', 'Short Coded')->firstOrFail();
        $branch = Branch::query()->where('company_id', $company->getKey())->where('name', 'Main Branch')->firstOrFail();
        $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('name', '2026')->firstOrFail();

        $company->update(['status' => 'inactive', 'legal_name' => 'Approved legal identity']);
        $branch->update(['status' => 'inactive', 'type' => Branch::TypeFactory]);
        $period->update(['is_closed' => true, 'allows_opening_entries' => false]);

        $this->seed(DefaultOperatingContextSeeder::class);

        expect($company->fresh()->status)->toBe('inactive')
            ->and($company->fresh()->legal_name)->toBe('Approved legal identity')
            ->and($branch->fresh()->status)->toBe('inactive')
            ->and($branch->fresh()->type)->toBe(Branch::TypeFactory)
            ->and($period->fresh()->is_closed)->toBeTrue()
            ->and($period->fresh()->allows_opening_entries)->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

test('repeated baseline seeding never restores deleted operating records', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 5, 10, 12));

    try {
        $this->seed(DefaultOperatingContextSeeder::class);
        $company = Company::query()->where('name', 'Short Coded')->firstOrFail();
        $branch = Branch::query()->where('company_id', $company->getKey())->where('name', 'Main Branch')->firstOrFail();
        $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('name', '2026')->firstOrFail();
        $period->delete();
        $branch->delete();
        $company->delete();

        $this->seed(DefaultOperatingContextSeeder::class);

        expect(Company::withTrashed()->findOrFail($company->getKey())->trashed())->toBeTrue()
            ->and(Branch::withTrashed()->findOrFail($branch->getKey())->trashed())->toBeTrue()
            ->and(FinancialPeriod::withTrashed()->findOrFail($period->getKey())->trashed())->toBeTrue()
            ->and(Company::withTrashed()->where('name', 'Short Coded')->count())->toBe(1)
            ->and(Branch::withTrashed()->where('company_id', $company->getKey())->count())->toBe(1)
            ->and(FinancialPeriod::withTrashed()->where('company_id', $company->getKey())->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

test('repeated baseline chart and center seeders preserve deleted and customized roots', function (): void {
    $this->seed([DefaultOperatingContextSeeder::class, BaselineChartOfAccountsSeeder::class, BaselineCostCentersSeeder::class]);
    $company = Company::query()->where('name', 'Short Coded')->firstOrFail();
    $deletedAccount = Account::query()->where('company_id', $company->getKey())->where('account_code', '1')->firstOrFail();
    $customAccount = Account::query()->where('company_id', $company->getKey())->where('account_code', '2')->firstOrFail();
    $deletedCenter = CostCenter::query()->where('company_id', $company->getKey())->orderBy('id')->firstOrFail();
    $customCenter = CostCenter::query()->where('company_id', $company->getKey())->whereKeyNot($deletedCenter->getKey())->firstOrFail();
    $deletedAccount->delete();
    $deletedCenter->delete();
    $customAccount->forceFill(['status' => 'inactive', 'name' => 'Approved account name'])->save();
    $customCenter->forceFill(['status' => 'inactive', 'name' => 'Approved center name'])->save();

    $this->seed([BaselineChartOfAccountsSeeder::class, BaselineCostCentersSeeder::class]);

    expect(Account::withTrashed()->findOrFail($deletedAccount->getKey())->trashed())->toBeTrue()
        ->and(CostCenter::withTrashed()->findOrFail($deletedCenter->getKey())->trashed())->toBeTrue()
        ->and($customAccount->fresh()->status)->toBe('inactive')
        ->and($customAccount->fresh()->name)->toBe('Approved account name')
        ->and($customCenter->fresh()->status)->toBe('inactive')
        ->and($customCenter->fresh()->name)->toBe('Approved center name');
});

test('full baseline seeding is repeatable beside verified customer master data', function (): void {
    $company = Company::factory()->main()->create(['name' => 'Synthetic customer company']);
    $admin = User::factory()->create([
        'username' => 'synthetic.customer.admin',
        'password' => Hash::make('Synthetic private password'),
    ]);
    $passwordHash = $admin->password;

    $this->seed(DatabaseSeeder::class);
    $accountCount = Account::query()->where('company_id', $company->getKey())->count();
    $centerCount = CostCenter::query()->where('company_id', $company->getKey())->count();
    $this->seed(DatabaseSeeder::class);

    expect(Company::query()->count())->toBe(1)
        ->and(Company::query()->where('name', 'Short Coded')->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(1)
        ->and(User::query()->where('username', 'admin')->exists())->toBeFalse()
        ->and($admin->fresh()->password)->toBe($passwordHash)
        ->and($accountCount)->toBe(5)
        ->and($centerCount)->toBe(2)
        ->and(Account::query()->where('company_id', $company->getKey())->count())->toBe($accountCount)
        ->and(CostCenter::query()->where('company_id', $company->getKey())->count())->toBe($centerCount);
});

test('explicit chart currency and board seeders preserve customized and deleted records', function (): void {
    $this->seed([DefaultOperatingContextSeeder::class, DefaultChartOfAccountsSeeder::class, CurrencySeeder::class, BoardListSeeder::class]);
    $company = Company::query()->where('name', 'Short Coded')->firstOrFail();
    $customAccount = Account::query()->where('company_id', $company->getKey())->where('account_code', '1131')->firstOrFail();
    $deletedAccount = Account::query()->where('company_id', $company->getKey())->where('account_code', '1132')->firstOrFail();
    $customAccount->forceFill(['name' => 'Reviewed raw inventory account', 'status' => 'inactive'])->save();
    $deletedAccount->delete();

    $egp = Currency::query()->where('company_id', $company->getKey())->where('code', 'EGP')->firstOrFail();
    $egp->forceFill(['name' => 'Approved Egyptian pound name', 'status' => 'inactive', 'is_main' => false])->save();
    $this->seed(CurrencySeeder::class);
    expect($egp->fresh()->name)->toBe('Approved Egyptian pound name')
        ->and($egp->fresh()->status)->toBe('inactive')
        ->and($egp->fresh()->is_main)->toBeFalse();
    $usd = Currency::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('currencies', Currency::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => 'Reviewed main currency',
        'code' => 'USD',
        'is_main' => true,
        'status' => 'active',
    ]);
    $egp->delete();

    $customList = BoardList::query()->orderBy('id')->firstOrFail();
    $deletedList = BoardList::query()->whereKeyNot($customList->getKey())->orderBy('id')->firstOrFail();
    $customList->forceFill(['name' => 'Reviewed board list', 'color' => 'secondary'])->save();
    $deletedList->delete();

    $this->seed([DefaultChartOfAccountsSeeder::class, CurrencySeeder::class, BoardListSeeder::class]);

    expect($customAccount->fresh()->name)->toBe('Reviewed raw inventory account')
        ->and($customAccount->fresh()->status)->toBe('inactive')
        ->and(Account::withTrashed()->findOrFail($deletedAccount->getKey())->trashed())->toBeTrue()
        ->and(Currency::withTrashed()->findOrFail($egp->getKey())->trashed())->toBeTrue()
        ->and($usd->fresh()->is_main)->toBeTrue()
        ->and($customList->fresh()->name)->toBe('Reviewed board list')
        ->and($customList->fresh()->color)->toBe('secondary')
        ->and(BoardList::withTrashed()->findOrFail($deletedList->getKey())->trashed())->toBeTrue();
});
