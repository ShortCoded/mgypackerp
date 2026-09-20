<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\AccountService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\OpeningBalance;
use Modules\Finance\Models\OpeningBalanceLine;
use Modules\Finance\Services\OpeningBalanceService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function financeActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function seedFinanceFoundation(): void
{
    test()->seed(AccountClassificationsSeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->orderBy('id')->first();
    $branch = $company instanceof Company
        ? Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->first()
        : null;
    $period = $company instanceof Company
        ? FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->first()
        : null;

    if ($company instanceof Company && $branch instanceof Branch && $period instanceof FinancialPeriod) {
        financeSelectOperatingContext($company, $branch, $period);
    }
}

function financeCompany(): Company
{
    $company = Company::query()->create(['doc_number' => 9001, 'doc_num' => 'Company-09001', 'name' => 'Finance Co', 'status' => 'active']);

    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);
    financeCloneTestAccountsToCompany($company);

    return $company;
}

function financeCurrentCompany(): Company
{
    $companyId = session(OperatingContextService::CompanyIdKey);

    return Company::query()->whereKey($companyId)->firstOrFail();
}

function financeCurrency(string $code = 'EGP', ?Company $company = null): Currency
{
    $company ??= financeCurrentCompany();

    return Currency::query()
        ->where('company_id', $company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

function financeCloneTestAccountsToCompany(Company $company): void
{
    $sourceCompanyId = Account::query()
        ->where('company_id', '!=', $company->getKey())
        ->where('is_system', false)
        ->orderBy('company_id')
        ->value('company_id');

    if ($sourceCompanyId === null) {
        return;
    }

    $sourceAccounts = Account::withTrashed()
        ->with('parent')
        ->where('company_id', $sourceCompanyId)
        ->where('is_system', false)
        ->orderBy('level')
        ->orderBy('account_code')
        ->get();

    foreach ($sourceAccounts as $sourceAccount) {
        if (Account::withTrashed()->where('company_id', $company->getKey())->where('account_code', $sourceAccount->account_code)->exists()) {
            continue;
        }

        $parent = $sourceAccount->parent instanceof Account
            ? Account::withTrashed()->where('company_id', $company->getKey())->where('account_code', $sourceAccount->parent->account_code)->first()
            : null;

        Account::query()->create([
            'doc_number' => $sourceAccount->doc_number,
            'doc_num' => $sourceAccount->doc_num,
            'company_id' => $company->getKey(),
            'account_code' => $sourceAccount->account_code,
            'name' => $sourceAccount->name,
            'name_en' => $sourceAccount->name_en,
            'parent_id' => $parent?->getKey(),
            'level' => $sourceAccount->level,
            'account_classification_id' => $sourceAccount->account_classification_id,
            'account_type' => $sourceAccount->account_type,
            'statement_type' => $sourceAccount->statement_type,
            'normal_balance' => $sourceAccount->normal_balance,
            'is_group' => $sourceAccount->is_group,
            'is_postable' => $sourceAccount->is_postable,
            'is_system' => false,
            'status' => $sourceAccount->status,
            'notes' => $sourceAccount->notes,
        ]);
    }
}

function financeBranch(Company $company, array $overrides = []): Branch
{
    static $documentNumber = 9100;

    $documentNumber++;

    return Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Finance Branch '.$documentNumber,
        'type' => 'main',
        'status' => 'active',
        ...$overrides,
    ]);
}

function financeSelectOperatingCompany(Company $company, Branch $branch): void
{
    test()->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
    ]);
}

function financeSelectOperatingContext(Company $company, Branch $branch, FinancialPeriod $period): void
{
    test()->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);
}

function financePeriod(?Company $company = null): FinancialPeriod
{
    $company ??= Company::query()->orderByDesc('id')->first();

    return FinancialPeriod::query()->create(['doc_number' => 9001, 'doc_num' => 'Period-09001', 'company_id' => $company?->getKey(), 'name' => 'FY Test', 'from_date' => '2026-01-01', 'to_date' => '2026-12-31', 'is_closed' => false]);
}

function financeOpeningBalanceRecord(
    Company $company,
    FinancialPeriod $period,
    Currency $currency,
    int $docNumber,
    string $description,
): OpeningBalance {
    return OpeningBalance::query()->create([
        'doc_number' => $docNumber,
        'doc_num' => 'OB-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'document_date' => '2026-01-01',
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'currency_id' => $currency->getKey(),
        'exchange_rate' => 1,
        'description' => $description,
        'status' => OpeningBalance::StatusDraft,
        'is_closed' => false,
        'approved' => false,
    ]);
}

function financeBankGroup(string $name = 'National Bank'): Account
{
    $mainBanksAccount = Account::query()
        ->where('company_id', session(OperatingContextService::CompanyIdKey))
        ->where('account_code', '1112')
        ->firstOrFail();

    return app(AccountService::class)->createChildFromParent($mainBanksAccount, [
        'name' => $name,
        'classification_code' => 'bank',
        'is_group' => true,
        'is_postable' => false,
        'status' => 'active',
    ]);
}

test('finance and currency permissions are discovered for admin role', function (): void {
    $this->seed(PermissionSeeder::class);

    $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();

    $resourceActions = [
        'currencies' => ['view', 'create', 'edit', 'delete', 'clone', 'view_trashed', 'restore', 'document_number.control', 'document_number_settings.update'],
        'bank_accounts' => ['view', 'create', 'edit', 'delete', 'clone', 'view_trashed', 'restore', 'document_number.control', 'document_number_settings.update'],
        'cashboxes' => ['view', 'create', 'edit', 'delete', 'clone', 'view_trashed', 'restore', 'document_number.control', 'document_number_settings.update'],
        'opening_balances' => ['view', 'create', 'edit', 'delete', 'clone', 'view_trashed', 'restore', 'approve', 'cancel', 'document_number.control', 'document_number_settings.update'],
    ];

    foreach ($resourceActions as $resource => $actions) {
        foreach ($actions as $action) {
            expect(Permission::query()->where('name', "{$resource}.{$action}")->exists())->toBeTrue()
                ->and($admin->hasPermissionTo("{$resource}.{$action}"))->toBeTrue();
        }
    }
});

test('finance foundation tables are company scoped', function (): void {
    expect(Schema::hasColumn('currencies', 'company_id'))->toBeTrue()
        ->and(Schema::hasColumn('bank_accounts', 'company_id'))->toBeTrue()
        ->and(Schema::hasColumn('cashboxes', 'company_id'))->toBeTrue()
        ->and(Schema::hasColumn('opening_balances', 'company_id'))->toBeTrue();
});

test('egp currency is seeded as the active main currency and duplicate code fails', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['currencies.view', 'currencies.create', 'currencies.delete']);

    expect(Currency::query()->where('code', 'EGP')->where('is_main', true)->exists())->toBeTrue();

    $this->actingAs($actor)
        ->postJson(route('admin.currencies.store'), [
            'name' => 'Duplicate Pound',
            'code' => 'EGP',
            'minor_unit_factor' => 100,
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    $egp = Currency::query()->where('code', 'EGP')->firstOrFail();

    $this->actingAs($actor)
        ->deleteJson(route('admin.currencies.destroy', $egp->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('message', __('currencies.messages.delete_blocked_main'));
});

test('Currency CRUD index uses the lookup table foundation and lives under finance menu', function (): void {
    $actor = financeActor([
        'currencies.view',
        'currencies.create',
        'currencies.delete',
        'currencies.view_trashed',
        'currencies.restore',
        'currencies.document_number_settings.update',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.currencies.index'))
        ->assertOk()
        ->assertSee(__('currencies.title'))
        ->assertSee(route('admin.currencies.data'), false)
        ->assertSee(route('admin.currencies.bulk-delete'), false)
        ->assertSee('js-currency-table', false)
        ->assertSee('assets/js/modules/Core/currencies.js', false)
        ->assertSee('id="currency_trash_filter"', false)
        ->assertSee(__('currencies.attributes.minor_unit_name'))
        ->assertSee(__('currencies.attributes.minor_unit_factor'))
        ->assertDontSee('Symbol')
        ->assertDontSee('name="symbol"', false)
        ->assertDontSee('assets/js/modules/Finance/finance-foundation.js', false)
        ->assertDontSee('data-id=', false);

    $this->actingAs($actor)
        ->get(route('admin.currencies.create'))
        ->assertOk()
        ->assertSee('js-currency-form', false)
        ->assertSee('assets/js/modules/Core/currencies.js', false)
        ->assertDontSee('Symbol')
        ->assertDontSee('name="symbol"', false)
        ->assertDontSee('assets/js/modules/Finance/finance-foundation.js', false)
        ->assertDontSee(__('common.actions.save_and_new'));

    $menu = app(MenuService::class)->getMenu($actor);
    $basicData = collect($menu)->firstWhere('label', 'basic_data');
    $accountingCosting = collect($menu)->firstWhere('label', 'accounting_costing');
    $finance = collect($accountingCosting['children'] ?? [])->firstWhere('label', 'finance');
    $financeChildren = collect($finance['children'] ?? [])->pluck('label')->all();

    expect(collect($basicData['children'] ?? [])->pluck('label')->all())->not->toContain('currencies')
        ->and($finance)->not->toBeNull()
        ->and($financeChildren)->toContain('currencies');
});

test('Accounting and costing menu and currency breadcrumbs are localized in Arabic and English', function (): void {
    $actor = financeActor([
        'currencies.view',
        'currencies.create',
        'bank_accounts.view',
        'cashboxes.view',
        'opening_balances.view',
    ]);

    app()->setLocale('ar');
    $actor->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($actor)
        ->get(route('admin.currencies.index'))
        ->assertOk()
        ->assertSee('الحسابات والتكاليف')
        ->assertSee('العملات')
        ->assertSee('حسابات البنوك')
        ->assertSee('الخزائن')
        ->assertSee('الأرصدة الافتتاحية')
        ->assertSee('رمز العملة')
        ->assertDontSee('Accounting & Costing')
        ->assertDontSee('Currencies')
        ->assertDontSee('Bank Accounts')
        ->assertDontSee('Cashboxes')
        ->assertDontSee('Opening Balances')
        ->assertDontSee('Symbol');

    $this->actingAs($actor)
        ->get(route('admin.currencies.create'))
        ->assertOk()
        ->assertSee('اسم العملة')
        ->assertSee('رمز العملة')
        ->assertSee('العملة الصغرى')
        ->assertSee('عدد الوحدات الصغرى')
        ->assertSee('العملة الرئيسية')
        ->assertDontSee('Symbol');

    app()->setLocale('en');
    $actor->forceFill(['locale' => 'en'])->save();

    $this->actingAs($actor)
        ->get(route('admin.currencies.index'))
        ->assertOk()
        ->assertSee('Accounting & Costing')
        ->assertSee('Currencies')
        ->assertSee('Bank Accounts')
        ->assertSee('Cashboxes')
        ->assertSee('Opening Balances')
        ->assertSee('Currency Code')
        ->assertDontSee('Symbol');

    $this->actingAs($actor)
        ->get(route('admin.currencies.create'))
        ->assertOk()
        ->assertSee('Currency Name')
        ->assertSee('Currency Code')
        ->assertSee('Minor Unit')
        ->assertSee('Minor Unit Factor')
        ->assertSee('Main Currency')
        ->assertDontSee('Symbol');
});

test('Finance CRUD indexes share the same table foundation', function (): void {
    $actor = financeActor([
        'bank_accounts.view',
        'bank_accounts.create',
        'bank_accounts.delete',
        'bank_accounts.view_trashed',
        'bank_accounts.document_number_settings.update',
        'cashboxes.view',
        'cashboxes.create',
        'cashboxes.delete',
        'cashboxes.view_trashed',
        'cashboxes.document_number_settings.update',
        'opening_balances.view',
        'opening_balances.create',
        'opening_balances.delete',
        'opening_balances.view_trashed',
        'opening_balances.document_number_settings.update',
    ]);

    foreach ([
        route('admin.finance.bank-accounts.index') => route('admin.finance.bank-accounts.data'),
        route('admin.finance.cashboxes.index') => route('admin.finance.cashboxes.data'),
        route('admin.finance.opening-balances.index') => route('admin.finance.opening-balances.data'),
    ] as $indexUrl => $dataUrl) {
        $this->actingAs($actor)
            ->get($indexUrl)
            ->assertOk()
            ->assertSee('erp-datatable-card', false)
            ->assertSee('js-finance-table', false)
            ->assertSee('bulk_actions_bar', false)
            ->assertSee('bulk_action_select', false)
            ->assertSee('js-finance-trash-filter', false)
            ->assertSee('assets/js/modules/Finance/finance-foundation.js', false)
            ->assertSee($dataUrl, false)
            ->assertDontSee('js-crud-table', false)
            ->assertDontSee('data-id=', false);
    }
});

test('OpeningBalance normal routes resolve active records and never historical deleted duplicates', function (): void {
    seedFinanceFoundation();
    $actor = financeActor([
        'opening_balances.view',
        'opening_balances.edit',
        'opening_balances.view_trashed',
    ]);
    $company = financeCurrentCompany();
    $period = FinancialPeriod::query()->findOrFail(session(OperatingContextService::FinancialPeriodIdKey));
    $currency = financeCurrency(company: $company);
    $historical = financeOpeningBalanceRecord($company, $period, $currency, 15, 'Historical deleted record');
    $historical->delete();

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.show', $historical->doc_num))
        ->assertNotFound();

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.trashed.show', $historical->doc_num))
        ->assertOk()
        ->assertSee('Historical deleted record');

    $active = financeOpeningBalanceRecord($company, $period, $currency, 15, 'Current active record');

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.show', $active->doc_num))
        ->assertOk()
        ->assertSee('Current active record')
        ->assertDontSee('Historical deleted record');

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.edit', $active->doc_num))
        ->assertOk()
        ->assertSee('Current active record')
        ->assertDontSee('Historical deleted record');
});

test('OpeningBalance explicit restore resolves and audits the deleted record', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.restore']);
    $company = financeCurrentCompany();
    $period = FinancialPeriod::query()->findOrFail(session(OperatingContextService::FinancialPeriodIdKey));
    $currency = financeCurrency(company: $company);
    $historical = financeOpeningBalanceRecord($company, $period, $currency, 16, 'Restorable historical record');
    $historical->delete();

    $this->actingAs($actor)
        ->patchJson(route('admin.finance.opening-balances.restore', $historical->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $restored = OpeningBalance::query()->whereKey($historical->getKey())->firstOrFail();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->restored_by)->toBe($actor->getKey())
        ->and($restored->restored_at)->not->toBeNull();
});

test('OpeningBalance restore rejects an active document number collision without rebinding', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.restore']);
    $company = financeCurrentCompany();
    $period = FinancialPeriod::query()->findOrFail(session(OperatingContextService::FinancialPeriodIdKey));
    $currency = financeCurrency(company: $company);
    $historical = financeOpeningBalanceRecord($company, $period, $currency, 17, 'Historical collision record');
    $historical->delete();
    $active = financeOpeningBalanceRecord($company, $period, $currency, 17, 'Active collision record');

    $this->actingAs($actor)
        ->patchJson(route('admin.finance.opening-balances.restore', $historical->doc_num))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document'])
        ->assertJsonPath('errors.document.0', __('opening_balances.messages.restore_doc_num_conflict', [
            'doc_num' => $historical->doc_num,
        ]));

    expect(OpeningBalance::withTrashed()->whereKey($historical->getKey())->firstOrFail()->trashed())->toBeTrue()
        ->and(OpeningBalance::query()->whereKey($active->getKey())->exists())->toBeTrue();
});

test('BankAccount form uses bank selectors instead of free text bank name', function (): void {
    $actor = financeActor(['accounts.view', 'accounts.create', 'bank_accounts.create']);

    $this->actingAs($actor)
        ->get(route('admin.finance.bank-accounts.create'))
        ->assertOk()
        ->assertSee('name="bank_doc_num"', false)
        ->assertSee('data-resource="bank_accounts"', false)
        ->assertSee('name="account_name"', false)
        ->assertSee('autofocus', false)
        ->assertDontSee('name="account_doc_num"', false)
        ->assertDontSee('name="account_id"', false)
        ->assertSee(__('bank_accounts.actions.add_bank'))
        ->assertDontSee('Add Bank Account')
        ->assertDontSee('إضافة حساب بنكي')
        ->assertDontSee('name="bank_name"', false)
        ->assertDontSee('linked_account')
        ->assertDontSee('Linked Accounting Account')
        ->assertDontSee('Linked Chart Account')
        ->assertDontSee('الحساب المحاسبي المرتبط')
        ->assertDontSee('Accounting Account')
        ->assertDontSee('الحساب المحاسبي');
});

test('Currency create uppercases code and setting a main currency unsets the previous one', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['currencies.view', 'currencies.create']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.currencies.store'), [
            'name' => 'US Dollar',
            'code' => 'usd',
            'minor_unit_name' => 'Cent',
            'minor_unit_factor' => 100,
            'is_main' => true,
            'status' => 'active',
            'submit_action' => 'save',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('reset_form', true)
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonMissingPath('next_doc_number')
        ->json();

    expect($response['data']['doc_num'])->toBeString()
        ->and(Currency::query()->where('code', 'USD')->where('is_main', true)->exists())->toBeTrue()
        ->and(Currency::query()->where('code', 'EGP')->where('is_main', true)->exists())->toBeFalse();
});

test('Currency minor unit factor uses strict grouped numeric input and grouped presentation', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['currencies.view', 'currencies.create', 'currencies.edit']);

    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.currencies.store'), [
            'name' => 'Grouped Minor Unit Currency',
            'code' => 'gmu',
            'minor_unit_name' => 'Subunit',
            'minor_unit_factor' => '1,000',
            'is_main' => false,
            'status' => 'active',
            'submit_action' => 'save_edit',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');

    $currency = Currency::query()->where('doc_num', $docNum)->firstOrFail();

    expect($currency->minor_unit_factor)->toBe(1000);

    $this->actingAs($actor)
        ->get(route('admin.currencies.edit', $currency->doc_num))
        ->assertOk()
        ->assertSee('data-numeric-input', false)
        ->assertSee('inputmode="numeric"', false)
        ->assertSee('value="1,000"', false);

    $rows = $this->actingAs($actor)
        ->getJson(route('admin.currencies.data'))
        ->assertOk()
        ->json('data');
    $row = collect($rows)->first(fn (array $candidate): bool => str_contains((string) ($candidate['code'] ?? ''), 'GMU'));

    expect($row)->not->toBeNull()
        ->and($row['minor_unit_factor'])->toContain('1,000');

    $this->actingAs($actor)
        ->postJson(route('admin.currencies.store'), [
            'name' => 'Malformed Minor Unit Currency',
            'code' => 'badg',
            'minor_unit_factor' => '1,2,3',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['minor_unit_factor']);
});

test('Currency document number update returns old and new doc num data for URL refresh', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['currencies.view', 'currencies.edit', 'currencies.document_number.control']);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $oldDocNum = $currency->doc_num;

    $this->actingAs($actor)
        ->get(route('admin.currencies.edit', $oldDocNum))
        ->assertOk()
        ->assertSee($oldDocNum);

    $this->actingAs($actor)
        ->putJson(route('admin.currencies.update', $oldDocNum), [
            'doc_number' => 9876,
            'name' => $currency->name,
            'code' => $currency->code,
            'minor_unit_name' => $currency->minor_unit_name,
            'minor_unit_factor' => $currency->minor_unit_factor,
            'is_main' => $currency->is_main,
            'status' => $currency->status,
            'notes' => $currency->notes,
            'submit_action' => 'save',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.old_doc_num', $oldDocNum)
        ->assertJsonPath('data.doc_num', 'CUR-09876')
        ->assertJsonPath('data.urls.update', route('admin.currencies.update', 'CUR-09876'));

    $this->actingAs($actor)
        ->get(route('admin.currencies.edit', 'CUR-09876'))
        ->assertOk()
        ->assertSee('CUR-09876');
});

test('Currency parity finance CRUD document number updates return old and new doc num data for URL refresh', function (): void {
    seedFinanceFoundation();
    $actor = financeActor([
        'accounts.view',
        'bank_accounts.view',
        'bank_accounts.create',
        'bank_accounts.edit',
        'bank_accounts.document_number.control',
        'cashboxes.view',
        'cashboxes.create',
        'cashboxes.edit',
        'cashboxes.document_number.control',
        'opening_balances.view',
        'opening_balances.create',
        'opening_balances.edit',
        'opening_balances.document_number.control',
    ]);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $bankAccountNode = financeBankGroup('Doc Refresh Bank');
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);

    $bankRecord = $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            'bank_doc_num' => $bankAccountNode->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Doc Refresh Bank',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('reset_form', true)
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('data.doc_number', 1);
    $bankRecord = BankAccount::query()->where('doc_num', $bankRecord->json('data.doc_num'))->firstOrFail();
    $oldBankDocNum = $bankRecord->doc_num;

    $this->actingAs($actor)
        ->putJson(route('admin.finance.bank-accounts.update', $oldBankDocNum), [
            'doc_number' => 9871,
            'bank_doc_num' => $bankAccountNode->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Doc Refresh Bank',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.old_doc_num', $oldBankDocNum)
        ->assertJsonPath('data.doc_num', 'BANK-09871')
        ->assertJsonPath('data.urls.update', route('admin.finance.bank-accounts.update', 'BANK-09871'));

    $this->actingAs($actor)
        ->get(route('admin.finance.bank-accounts.edit', 'BANK-09871'))
        ->assertOk()
        ->assertSee('BANK-09871');

    $cashbox = $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Doc Refresh Cashbox',
            'currency_doc_nums' => [$currency->doc_num],
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('reset_form', true)
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('data.doc_number', 1);
    $cashbox = Cashbox::query()->where('doc_num', $cashbox->json('data.doc_num'))->firstOrFail();
    $oldCashboxDocNum = $cashbox->doc_num;

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cashboxes.update', $oldCashboxDocNum), [
            'doc_number' => 9872,
            'name' => 'Doc Refresh Cashbox',
            'currency_doc_nums' => [$currency->doc_num],
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.old_doc_num', $oldCashboxDocNum)
        ->assertJsonPath('data.doc_num', 'CASH-09872')
        ->assertJsonPath('data.urls.update', route('admin.finance.cashboxes.update', 'CASH-09872'));

    $this->actingAs($actor)
        ->get(route('admin.finance.cashboxes.edit', 'CASH-09872'))
        ->assertOk()
        ->assertSee('CASH-09872');

    $cashAccount = $cashbox->refresh()->account()->firstOrFail();
    $offsetAccount = Account::query()
        ->forCompany($company->getKey())
        ->where('is_postable', true)
        ->where('is_group', false)
        ->whereKeyNot($cashAccount->getKey())
        ->firstOrFail();

    $openingBalance = $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'document_date' => '2026-01-01',
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'lines' => [
                ['account_doc_num' => $cashAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 100],
                ['account_doc_num' => $offsetAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 100],
            ],
        ])
        ->assertOk();
    $openingBalance = OpeningBalance::query()->where('doc_num', $openingBalance->json('data.doc_num'))->firstOrFail();
    $openingBalance->forceFill(['is_closed' => false])->save();
    $oldOpeningBalanceDocNum = $openingBalance->doc_num;

    $this->actingAs($actor)
        ->putJson(route('admin.finance.opening-balances.update', $oldOpeningBalanceDocNum), [
            'doc_number' => 9873,
            'document_date' => '2026-01-01',
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'lines' => [
                ['account_doc_num' => $cashAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 100],
                ['account_doc_num' => $offsetAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 100],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.old_doc_num', $oldOpeningBalanceDocNum)
        ->assertJsonPath('data.doc_num', 'OB-09873')
        ->assertJsonPath('data.urls.update', route('admin.finance.opening-balances.update', 'OB-09873'));

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.edit', 'OB-09873'))
        ->assertForbidden();
});

test('Finance and currency edit forms do not render duplicate save dropdown actions', function (): void {
    seedFinanceFoundation();
    $actor = financeActor([
        'currencies.view',
        'currencies.edit',
        'bank_accounts.view',
        'bank_accounts.create',
        'bank_accounts.edit',
        'cashboxes.view',
        'cashboxes.create',
        'cashboxes.edit',
        'opening_balances.view',
        'opening_balances.create',
        'opening_balances.edit',
    ]);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $bankAccountNode = financeBankGroup('Actions Bank');
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);

    $bankRecord = $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            'bank_doc_num' => $bankAccountNode->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Actions Bank',
            'status' => 'active',
        ])
        ->assertOk()
        ->json('data.doc_num');
    $cashbox = $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Actions Cashbox',
            'currency_doc_nums' => [$currency->doc_num],
            'status' => 'active',
        ])
        ->assertOk()
        ->json('data.doc_num');
    $cashAccount = Cashbox::query()->where('doc_num', $cashbox)->firstOrFail()->account()->firstOrFail();
    $offsetAccount = Account::query()
        ->forCompany($company->getKey())
        ->where('is_postable', true)
        ->where('is_group', false)
        ->whereKeyNot($cashAccount->getKey())
        ->firstOrFail();

    $openingBalance = $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'document_date' => '2026-01-01',
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'lines' => [
                ['account_doc_num' => $cashAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 100],
                ['account_doc_num' => $offsetAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 100],
            ],
        ])
        ->assertOk()
        ->json('data.doc_num');
    OpeningBalance::query()->where('doc_num', $openingBalance)->firstOrFail()->forceFill(['is_closed' => false])->save();

    foreach ([
        route('admin.currencies.edit', $currency->doc_num),
        route('admin.finance.bank-accounts.edit', $bankRecord),
        route('admin.finance.cashboxes.edit', $cashbox),
        route('admin.finance.opening-balances.edit', $openingBalance),
    ] as $url) {
        $html = $this->actingAs($actor)->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('dropdown-item js-currency-submit-action" type="submit" data-submit-action="save"')
            ->and($html)->not->toContain('dropdown-item js-finance-submit-action" type="submit" data-submit-action="save"')
            ->and($html)->not->toContain(__('common.actions.save_and_new'))
            ->and($html)->not->toContain(__('common.actions.save_and_edit'));
    }
});

test('Currency seeder is idempotent for default EGP currency', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $this->seed(CurrencySeeder::class);
    $this->seed(CurrencySeeder::class);

    expect(Currency::query()->where('code', 'EGP')->count())->toBe(1)
        ->and(Currency::query()->where('code', 'EGP')->where('is_main', true)->where('status', 'active')->exists())->toBeTrue();
});

test('Currencies are company scoped across create validation selector and datatable', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['currencies.view', 'currencies.create']);
    $firstCompany = financeCurrentCompany();
    $firstBranch = Branch::query()->where('company_id', $firstCompany->getKey())->firstOrFail();
    $firstPeriod = FinancialPeriod::query()->where('company_id', $firstCompany->getKey())->where('is_closed', false)->firstOrFail();
    $secondCompany = financeCompany();
    $secondBranch = financeBranch($secondCompany);
    $secondPeriod = financePeriod($secondCompany);

    financeSelectOperatingContext($firstCompany, $firstBranch, $firstPeriod);
    $this->actingAs($actor)
        ->postJson(route('admin.currencies.store'), [
            'name' => 'US Dollar',
            'code' => 'USD',
            'minor_unit_factor' => 100,
            'status' => 'active',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.currencies.store'), [
            'name' => 'Duplicate Dollar',
            'code' => 'USD',
            'minor_unit_factor' => 100,
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    financeSelectOperatingContext($secondCompany, $secondBranch, $secondPeriod);
    $this->actingAs($actor)
        ->postJson(route('admin.currencies.store'), [
            'name' => 'US Dollar',
            'code' => 'USD',
            'minor_unit_factor' => 100,
            'status' => 'active',
        ])
        ->assertOk();

    expect(Currency::query()->where('code', 'USD')->count())->toBe(2)
        ->and(Currency::query()->where('code', 'USD')->pluck('company_id')->unique()->count())->toBe(2);

    $rows = $this->actingAs($actor)
        ->getJson(route('admin.currencies.data'))
        ->assertOk()
        ->json('data');

    $currencyCodes = collect($rows)->pluck('code');

    expect($currencyCodes->contains(fn ($code): bool => str_contains((string) $code, 'USD')))->toBeTrue()
        ->and($currencyCodes->filter(fn ($code): bool => str_contains((string) $code, 'USD'))->count())->toBe(1);

    $options = $this->actingAs($actor)
        ->getJson(route('admin.select2.currencies', ['term' => 'USD']))
        ->assertOk()
        ->json('results');

    expect($options)->toHaveCount(1);
});

test('Finance create endpoints ignore submitted company id and use operating context', function (): void {
    seedFinanceFoundation();
    $actor = financeActor([
        'accounts.view',
        'currencies.create',
        'bank_accounts.create',
        'cashboxes.create',
        'opening_balances.create',
    ]);
    $firstCompany = financeCurrentCompany();
    $firstPeriod = FinancialPeriod::query()->where('company_id', $firstCompany->getKey())->where('is_closed', false)->firstOrFail();
    $secondCompany = financeCompany();
    $secondBranch = financeBranch($secondCompany);
    $secondPeriod = financePeriod($secondCompany);
    financeSelectOperatingContext($secondCompany, $secondBranch, $secondPeriod);
    $secondCurrency = financeCurrency('EGP', $secondCompany);
    $bankGroup = financeBankGroup('Submitted Company Ignored Bank');
    $debitAccount = Account::query()->where('company_id', $secondCompany->getKey())->where('is_postable', true)->where('is_group', false)->firstOrFail();
    $creditAccount = Account::query()->where('company_id', $secondCompany->getKey())->where('is_postable', true)->where('is_group', false)->whereKeyNot($debitAccount->getKey())->firstOrFail();

    $currencyDocNum = $this->actingAs($actor)
        ->postJson(route('admin.currencies.store'), [
            'company_id' => $firstCompany->getKey(),
            'name' => 'Submitted Scope Dollar',
            'code' => 'SSD',
            'minor_unit_factor' => 100,
            'status' => 'active',
        ])
        ->assertOk()
        ->json('data.doc_num');

    $bankDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            'company_id' => $firstCompany->getKey(),
            'bank_doc_num' => $bankGroup->doc_num,
            'currency_doc_num' => $secondCurrency->doc_num,
            'account_name' => 'Submitted Scope Bank',
            'status' => 'active',
        ])
        ->assertOk()
        ->json('data.doc_num');

    $cashboxDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'company_id' => $firstCompany->getKey(),
            'name' => 'Submitted Scope Cashbox',
            'branch_doc_num' => $secondBranch->doc_num,
            'currency_doc_nums' => [$secondCurrency->doc_num],
            'status' => 'active',
        ])
        ->assertOk()
        ->json('data.doc_num');

    $openingBalanceDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'company_id' => $firstCompany->getKey(),
            'financial_period_id' => $firstPeriod->getKey(),
            'document_date' => '2026-01-01',
            'currency_doc_num' => $secondCurrency->doc_num,
            'exchange_rate' => 1,
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 100],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 100],
            ],
        ])
        ->assertOk()
        ->json('data.doc_num');

    $bankAccount = BankAccount::query()->with('account')->where('doc_num', $bankDocNum)->firstOrFail();
    $cashbox = Cashbox::query()->with('account')->where('doc_num', $cashboxDocNum)->firstOrFail();
    $openingBalance = OpeningBalance::query()->where('doc_num', $openingBalanceDocNum)->where('company_id', $secondCompany->getKey())->firstOrFail();

    expect(Currency::query()->where('doc_num', $currencyDocNum)->value('company_id'))->toBe($secondCompany->getKey())
        ->and($bankAccount->company_id)->toBe($secondCompany->getKey())
        ->and($bankAccount->account?->company_id)->toBe($secondCompany->getKey())
        ->and($cashbox->company_id)->toBe($secondCompany->getKey())
        ->and($cashbox->account?->company_id)->toBe($secondCompany->getKey())
        ->and($openingBalance->company_id)->toBe($secondCompany->getKey())
        ->and($openingBalance->financial_period_id)->toBe($secondPeriod->getKey());
});

test('BankAccount bank selector returns only group accounts under the main Banks hierarchy', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['accounts.view']);
    $bankAccount = financeBankGroup('Bank Misr');
    $otherBankGroup = financeBankGroup('CIB');
    $postableChild = app(AccountService::class)->createChildFromParent($bankAccount, [
        'name' => 'Current Account EGP',
        'classification_code' => 'bank',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $mainBanksAccount = Account::query()->where('account_code', '1112')->firstOrFail();
    $cashAccount = Account::query()->where('account_code', '1111')->firstOrFail();

    $results = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.select2', ['classification' => 'bank', 'bank_accounts' => 1]))
        ->assertOk()
        ->json('results');

    expect(collect($results)->pluck('id')->all())->toContain($bankAccount->doc_num, $otherBankGroup->doc_num)
        ->not->toContain($mainBanksAccount->doc_num, $cashAccount->doc_num, $postableChild->doc_num);
});

test('BankAccount user with accounts create can inline create bank group in chart of accounts', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['accounts.view', 'accounts.create']);
    $mainBanksAccount = Account::query()->where('account_code', '1112')->firstOrFail();

    $bankOption = $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.bank-groups.store'), [
            'name' => 'بنك مصر',
            'notes' => 'Created from Bank Accounts',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('bank_accounts.messages.bank_created'))
        ->json('data.option');

    $bankGroup = Account::query()->where('doc_num', $bankOption['id'])->firstOrFail();

    expect($bankGroup->parent_id)->toBe($mainBanksAccount->getKey())
        ->and($bankGroup->is_group)->toBeTrue()
        ->and($bankGroup->is_postable)->toBeFalse()
        ->and($bankGroup->classification?->code)->toBe('bank')
        ->and($bankOption['text'])->toContain($bankGroup->account_code, 'بنك مصر');

    $treeCodes = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.tree', ['classification' => 'bank']))
        ->assertOk()
        ->json('data');

    expect(json_encode($treeCodes))->toContain($bankGroup->account_code);
});

test('BankAccount user without accounts create cannot inline create bank group chart account', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['accounts.view']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.bank-groups.store'), ['name' => 'Blocked Bank'])
        ->assertForbidden();
});

test('BankAccount creates and links a postable child account under selected bank group', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['accounts.view', 'bank_accounts.view', 'bank_accounts.create']);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $bankGroup = financeBankGroup('National Bank');
    $cashAccount = Account::query()->where('account_code', '1111')->firstOrFail();

    expect(Schema::hasColumns('bank_accounts', ['bank_id', 'account_id']))->toBeTrue();

    $payload = [
        'bank_doc_num' => $bankGroup->doc_num,
        'account_id' => $cashAccount->getKey(),
        'currency_doc_num' => $currency->doc_num,
        'account_name' => 'Main Bank',
        'account_number' => '123456',
        'status' => 'active',
    ];

    $this->actingAs($actor)->postJson(route('admin.finance.bank-accounts.store'), $payload)->assertOk();
    $record = BankAccount::query()->with(['bank', 'account.parent'])->firstOrFail();
    $linkedAccount = $record->account;

    expect($record->bank_id)->toBe($bankGroup->getKey())
        ->and($record->bank?->getKey())->toBe($bankGroup->getKey())
        ->and($linkedAccount->getKey())->not->toBe($bankGroup->getKey())
        ->and($linkedAccount->getKey())->not->toBe($cashAccount->getKey())
        ->and($record->account_id)->toBe($linkedAccount->getKey())
        ->and($linkedAccount->parent_id)->toBe($bankGroup->getKey())
        ->and($linkedAccount->is_group)->toBeFalse()
        ->and($linkedAccount->is_postable)->toBeTrue()
        ->and($linkedAccount->account_code)->toStartWith($bankGroup->account_code)
        ->and($linkedAccount->account_type)->toBe($bankGroup->account_type)
        ->and($linkedAccount->statement_type)->toBe($bankGroup->statement_type)
        ->and($linkedAccount->normal_balance)->toBe($bankGroup->normal_balance)
        ->and($linkedAccount->classification?->code)->toBe('bank')
        ->and($linkedAccount->name)->toBe('Main Bank - EGP')
        ->and($linkedAccount->name)->not->toContain('123456');

    $this->actingAs($actor)
        ->get(route('admin.finance.bank-accounts.show', $record->doc_num))
        ->assertOk()
        ->assertSee($bankGroup->account_code)
        ->assertDontSee($linkedAccount->account_code)
        ->assertDontSee('name="account_id"', false)
        ->assertDontSee('name="bank_name"', false)
        ->assertDontSee('linked_account')
        ->assertDontSee('Linked Accounting Account')
        ->assertDontSee('Linked Chart Account')
        ->assertDontSee('الحساب المحاسبي المرتبط')
        ->assertDontSee('Accounting Account')
        ->assertDontSee('الحساب المحاسبي');

    $dataRow = $this->actingAs($actor)
        ->getJson(route('admin.finance.bank-accounts.data'))
        ->assertOk()
        ->assertJsonMissingPath('data.0.id')
        ->json('data.0');

    expect($dataRow)->toHaveKeys(['checkbox', 'doc_num', 'bank', 'currency', 'account_name', 'account_number', 'iban', 'status', 'actions'])
        ->and($dataRow['bank'])->toContain($bankGroup->account_code)
        ->and($dataRow['account_name'])->toContain($linkedAccount->name)
        ->and($dataRow['account_name'])->toContain('<span class="dt-ellipsis-content"')
        ->and($dataRow['account_name'])->not->toContain('&lt;span', 'span class=')
        ->and($dataRow['account_name'])->not->toContain('123456')
        ->not->toHaveKeys(['account', 'bank_name', 'linked_account', 'generated_account']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bank_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [...$payload, 'bank_doc_num' => $cashAccount->doc_num])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bank_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Missing Parent',
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bank_doc_num'])
        ->assertJsonMissingValidationErrors(['bank_name']);

    $tree = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.tree', ['classification' => 'bank']))
        ->assertOk()
        ->json('data');

    expect(json_encode($tree))->toContain($bankGroup->account_code, $linkedAccount->account_code, 'Main Bank - EGP')
        ->not->toContain('123456');
});

test('BankAccount rejects duplicate IBAN and account number on active records only', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['accounts.view', 'bank_accounts.view', 'bank_accounts.create', 'bank_accounts.edit', 'bank_accounts.document_number.control']);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $firstBankGroup = financeBankGroup('Unique First Bank');
    $secondBankGroup = financeBankGroup('Unique Second Bank');

    $payload = [
        'bank_doc_num' => $firstBankGroup->doc_num,
        'currency_doc_num' => $currency->doc_num,
        'account_name' => 'Unique Current Account',
        'account_number' => '  ACC-001  ',
        'iban' => '  EG110000000000000000000001  ',
        'status' => 'active',
    ];

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), $payload)
        ->assertOk();

    $record = BankAccount::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($record->account_number)->toBe('ACC-001')
        ->and($record->iban)->toBe('EG110000000000000000000001');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            ...$payload,
            'bank_doc_num' => $secondBankGroup->doc_num,
            'account_name' => 'Duplicate Account Number',
            'iban' => 'EG110000000000000000000002',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account_number']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            ...$payload,
            'doc_number' => $record->doc_number,
            'bank_doc_num' => $secondBankGroup->doc_num,
            'account_name' => 'Duplicate Document Number',
            'account_number' => 'ACC-002',
            'iban' => 'EG110000000000000000000002',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['doc_number']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            ...$payload,
            'bank_doc_num' => $secondBankGroup->doc_num,
            'account_name' => 'Duplicate IBAN',
            'account_number' => 'ACC-003',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['iban']);

    $this->actingAs($actor)
        ->putJson(route('admin.finance.bank-accounts.update', $record->doc_num), [
            'bank_doc_num' => $firstBankGroup->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Unique Current Account Updated',
            'account_number' => 'ACC-001',
            'iban' => 'EG110000000000000000000001',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonMissingValidationErrors(['account_number', 'iban']);

    $record->delete();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            ...$payload,
            'bank_doc_num' => $secondBankGroup->doc_num,
            'account_name' => 'Reused After Delete',
        ])
        ->assertOk();
});

test('BankAccount and linked chart account stay synced through update delete and restore', function (): void {
    seedFinanceFoundation();
    $actor = financeActor([
        'accounts.view',
        'accounts.create',
        'accounts.edit',
        'accounts.delete',
        'accounts.restore',
        'accounts.view_trashed',
        'bank_accounts.view',
        'bank_accounts.create',
        'bank_accounts.edit',
        'bank_accounts.delete',
        'bank_accounts.restore',
        'bank_accounts.view_trashed',
    ]);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $bankGroup = financeBankGroup('Synced Bank');

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            'bank_doc_num' => $bankGroup->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Synced Current',
            'account_number' => '555',
            'status' => 'active',
        ])
        ->assertOk();

    $record = BankAccount::query()->with('account')->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $linkedAccount = $record->account;

    expect($linkedAccount)->not->toBeNull()
        ->and(BankAccount::query()->where('account_id', $linkedAccount->getKey())->count())->toBe(1);

    $linkedAccount->refresh()->load('parent');

    app(AccountService::class)->update($linkedAccount, [
        'account_code' => $linkedAccount->account_code,
        'name' => 'Manual Chart Bank Account',
        'name_en' => $linkedAccount->name_en,
        'parent_doc_num' => $bankGroup->doc_num,
        'classification_code' => 'bank',
        'account_type' => $linkedAccount->account_type,
        'statement_type' => $linkedAccount->statement_type,
        'normal_balance' => $linkedAccount->normal_balance,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
        'notes' => $linkedAccount->notes,
    ]);

    $dataRow = $this->actingAs($actor)
        ->getJson(route('admin.finance.bank-accounts.data'))
        ->assertOk()
        ->json('data.0');

    expect($dataRow['account_name'])->toContain('Manual Chart Bank Account');

    $this->actingAs($actor)
        ->get(route('admin.finance.bank-accounts.show', $record->doc_num))
        ->assertOk()
        ->assertSee('Manual Chart Bank Account');

    $this->actingAs($actor)
        ->putJson(route('admin.finance.bank-accounts.update', $record->doc_num), [
            'bank_doc_num' => $bankGroup->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Synced Current',
            'account_number' => '555',
            'swift_code' => 'SCBIEGCX',
            'status' => 'active',
        ])
        ->assertOk();

    $linkedAccount->refresh();

    expect($linkedAccount->name)->toBe('Manual Chart Bank Account');

    $this->actingAs($actor)
        ->putJson(route('admin.finance.bank-accounts.update', $record->fresh()->doc_num), [
            'bank_doc_num' => $bankGroup->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Synced Current',
            'account_number' => '556',
            'iban' => 'EG110000000000000000000556',
            'swift_code' => 'SCBIEGCX',
            'status' => 'active',
        ])
        ->assertOk();

    $record->refresh();
    $linkedAccount->refresh();

    expect($record->account_id)->toBe($linkedAccount->getKey())
        ->and($record->account_number)->toBe('556')
        ->and($record->iban)->toBe('EG110000000000000000000556')
        ->and($linkedAccount->name)->toBe('Manual Chart Bank Account')
        ->and(BankAccount::query()->where('account_id', $linkedAccount->getKey())->count())->toBe(1);

    $this->actingAs($actor)
        ->deleteJson(route('admin.finance.bank-accounts.destroy', $record->doc_num))
        ->assertOk();

    expect(BankAccount::withTrashed()->whereKey($record->getKey())->first()?->trashed())->toBeTrue()
        ->and(Account::withTrashed()->whereKey($linkedAccount->getKey())->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.finance.bank-accounts.restore', $record->doc_num))
        ->assertOk();

    expect(BankAccount::withTrashed()->whereKey($record->getKey())->first()?->trashed())->toBeFalse()
        ->and(Account::withTrashed()->whereKey($linkedAccount->getKey())->first()?->trashed())->toBeFalse();

    $this->actingAs($actor)
        ->deleteJson(route('admin.accounting.accounts.destroy', $linkedAccount->doc_num))
        ->assertOk();

    expect(Account::withTrashed()->whereKey($linkedAccount->getKey())->first()?->trashed())->toBeTrue()
        ->and(BankAccount::withTrashed()->whereKey($record->getKey())->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.accounting.accounts.restore', $linkedAccount->doc_num))
        ->assertOk();

    expect(Account::withTrashed()->whereKey($linkedAccount->getKey())->first()?->trashed())->toBeFalse()
        ->and(BankAccount::withTrashed()->whereKey($record->getKey())->first()?->trashed())->toBeFalse();
});

test('editing BankAccount updates managed child account without creating duplicates', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['accounts.view', 'bank_accounts.view', 'bank_accounts.create', 'bank_accounts.edit']);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $usdCurrency = Currency::query()->create([
        'doc_number' => 9901,
        'doc_num' => 'Currency-09901',
        'company_id' => $currency->company_id,
        'name' => 'US Dollar',
        'code' => 'USD',
        'minor_unit_name' => 'Cent',
        'minor_unit_factor' => 100,
        'status' => 'active',
    ]);
    $bankGroup = financeBankGroup('Edit Managed Bank');
    $newBankGroup = financeBankGroup('Moved Managed Bank');

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            'bank_doc_num' => $bankGroup->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Current Account',
            'account_number' => '999',
            'status' => 'active',
        ])
        ->assertOk();

    $record = BankAccount::query()->with('account')->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $linkedAccount = $record->account;
    $childrenCount = Account::query()->where('parent_id', $bankGroup->getKey())->count();

    $this->actingAs($actor)
        ->putJson(route('admin.finance.bank-accounts.update', $record->doc_num), [
            'bank_doc_num' => $bankGroup->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'account_name' => 'Updated Current Account',
            'account_number' => '999',
            'status' => 'active',
        ])
        ->assertOk();

    $record->refresh();
    $linkedAccount->refresh();

    expect($record->account_id)->toBe($linkedAccount->getKey())
        ->and(Account::query()->where('parent_id', $bankGroup->getKey())->count())->toBe($childrenCount)
        ->and($linkedAccount->name)->toBe('Updated Current Account - EGP');

    $this->actingAs($actor)
        ->putJson(route('admin.finance.bank-accounts.update', $record->doc_num), [
            'bank_doc_num' => $bankGroup->doc_num,
            'currency_doc_num' => $usdCurrency->doc_num,
            'account_name' => 'Updated Current Account',
            'account_number' => '999',
            'status' => 'active',
        ])
        ->assertOk();

    $record->refresh();
    $linkedAccount->refresh();

    expect($record->currency_id)->toBe($usdCurrency->getKey())
        ->and($record->account_id)->toBe($linkedAccount->getKey())
        ->and($linkedAccount->name)->toBe('Updated Current Account - USD')
        ->and(Account::query()->whereKey($linkedAccount->getKey())->count())->toBe(1);

    $this->actingAs($actor)
        ->putJson(route('admin.finance.bank-accounts.update', $record->doc_num), [
            'bank_doc_num' => $newBankGroup->doc_num,
            'currency_doc_num' => $usdCurrency->doc_num,
            'account_name' => 'Moved Current Account',
            'account_number' => '999',
            'status' => 'active',
        ])
        ->assertOk();

    $record->refresh();
    $linkedAccount->refresh();

    expect($record->bank_id)->toBe($newBankGroup->getKey())
        ->and($record->account_id)->toBe($linkedAccount->getKey())
        ->and($linkedAccount->parent_id)->toBe($newBankGroup->getKey())
        ->and($linkedAccount->account_code)->toStartWith($newBankGroup->account_code)
        ->and($linkedAccount->name)->toBe('Moved Current Account - USD')
        ->and(Account::query()->whereKey($linkedAccount->getKey())->count())->toBe(1);
});

test('Cashbox creates a clean linked cash chart account and treats empty currencies as all currencies', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['accounts.create', 'cashboxes.view', 'cashboxes.create']);
    $mainCashParent = Account::query()->where('account_code', '1111')->firstOrFail();

    $this->actingAs($actor)
        ->get(route('admin.finance.cashboxes.create'))
        ->assertOk()
        ->assertSee('name="parent_account_doc_num"', false)
        ->assertSee(route('admin.finance.select2.cashbox-parent-accounts'), false)
        ->assertSee(__('cashboxes.attributes.account_group'))
        ->assertSee(__('cashboxes.actions.add_group'))
        ->assertSee(__('cashboxes.account_parent_help'))
        ->assertSee(__('cashboxes.placeholders.cashbox_group'), false)
        ->assertDontSee('مجموعة الحسابات');

    $this->actingAs($actor)->postJson(route('admin.finance.cashboxes.store'), [
        'name' => 'Main Cashbox',
        'status' => 'active',
    ])->assertOk();

    $record = Cashbox::query()->with(['account.parent', 'currencies'])->firstOrFail();
    $linkedAccount = $record->account;
    $mainCashParent->refresh();

    expect($record->currencies()->count())->toBe(0)
        ->and($mainCashParent->is_group)->toBeTrue()
        ->and($mainCashParent->is_postable)->toBeFalse()
        ->and($linkedAccount->parent_id)->toBe($mainCashParent->getKey())
        ->and($linkedAccount->is_group)->toBeFalse()
        ->and($linkedAccount->is_postable)->toBeTrue()
        ->and($linkedAccount->classification?->code)->toBe('cash')
        ->and($linkedAccount->name)->toBe('Main Cashbox')
        ->and($linkedAccount->name)->not->toContain('EGP', 'Branch', $record->doc_num);

    $this->actingAs($actor)
        ->get(route('admin.finance.cashboxes.show', $record->doc_num))
        ->assertOk()
        ->assertSee('Main Cashbox')
        ->assertSee(__('cashboxes.all_currencies'));

    $dataRow = $this->actingAs($actor)
        ->getJson(route('admin.finance.cashboxes.data'))
        ->assertOk()
        ->assertJsonMissingPath('data.0.id')
        ->json('data.0');

    expect($dataRow)->toHaveKeys(['checkbox', 'doc_num', 'name', 'account', 'branch', 'currencies_summary', 'status', 'actions'])
        ->and($dataRow['currencies_summary'])->toContain(__('cashboxes.all_currencies'));
});

test('Cashbox parent account selector is limited to cashbox group accounts and update moves linked account', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['accounts.create', 'cashboxes.view', 'cashboxes.create', 'cashboxes.edit']);
    $mainCashParent = Account::query()->where('account_code', '1111')->firstOrFail();
    $cashSubgroup = app(AccountService::class)->createChildFromParent($mainCashParent, [
        'name' => 'Branch Cash Funds',
        'classification_code' => 'cash',
        'is_group' => true,
        'is_postable' => false,
        'status' => 'active',
    ]);
    $postingCashAccount = app(AccountService::class)->createChildFromParent($mainCashParent, [
        'name' => 'Posting Cash Leaf',
        'classification_code' => 'cash',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $inactiveCashSubgroup = app(AccountService::class)->createChildFromParent($mainCashParent, [
        'name' => 'Inactive Cash Funds',
        'classification_code' => 'cash',
        'is_group' => true,
        'is_postable' => false,
        'status' => 'inactive',
    ]);
    $bankGroup = financeBankGroup('Selector Bank Group');
    $quickCreatedGroup = $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.account-groups.store'), [
            'name' => 'Quick Cashbox Group',
        ])
        ->assertOk()
        ->json('data.option.id');
    $quickCreatedGroupAccount = Account::query()->where('doc_num', $quickCreatedGroup)->firstOrFail();

    expect($quickCreatedGroupAccount->parent_id)->toBe($mainCashParent->getKey())
        ->and($quickCreatedGroupAccount->is_group)->toBeTrue()
        ->and($quickCreatedGroupAccount->is_postable)->toBeFalse();

    $results = $this->actingAs($actor)
        ->getJson(route('admin.finance.select2.cashbox-parent-accounts'))
        ->assertOk()
        ->json('results');

    expect(collect($results)->pluck('id')->all())
        ->toContain($cashSubgroup->doc_num, $quickCreatedGroupAccount->doc_num)
        ->not->toContain($mainCashParent->doc_num)
        ->not->toContain($postingCashAccount->doc_num, $inactiveCashSubgroup->doc_num, $bankGroup->doc_num);

    $blankParentResponse = $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Blank Parent Cashbox',
            'status' => 'active',
        ])
        ->assertOk();

    $blankParentAccount = Cashbox::query()
        ->with('account')
        ->where('doc_num', $blankParentResponse->json('data.doc_num'))
        ->firstOrFail()
        ->account;

    expect($blankParentAccount->parent_id)->toBe($mainCashParent->getKey())
        ->and($blankParentAccount->is_group)->toBeFalse()
        ->and($blankParentAccount->is_postable)->toBeTrue();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Invalid Parent Cashbox',
            'parent_account_doc_num' => $postingCashAccount->doc_num,
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['parent_account_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Inactive Parent Cashbox',
            'parent_account_doc_num' => $inactiveCashSubgroup->doc_num,
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['parent_account_doc_num']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Movable Cashbox',
            'status' => 'active',
        ])
        ->assertOk();

    $cashbox = Cashbox::query()->with('account')->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $linkedAccount = $cashbox->account;

    expect($linkedAccount->parent_id)->toBe($mainCashParent->getKey())
        ->and($linkedAccount->is_group)->toBeFalse()
        ->and($linkedAccount->is_postable)->toBeTrue()
        ->and($linkedAccount->name)->toBe('Movable Cashbox');

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cashboxes.update', $cashbox->doc_num), [
            'name' => 'Moved Cashbox',
            'parent_account_doc_num' => $cashSubgroup->doc_num,
            'status' => 'active',
        ])
        ->assertOk();

    $cashbox->refresh();
    $linkedAccount->refresh();

    expect($cashbox->account_id)->toBe($linkedAccount->getKey())
        ->and($linkedAccount->parent_id)->toBe($cashSubgroup->getKey())
        ->and($linkedAccount->account_code)->toStartWith($cashSubgroup->account_code)
        ->and($linkedAccount->name)->toBe('Moved Cashbox')
        ->and(Account::query()->whereKey($linkedAccount->getKey())->count())->toBe(1);
});

test('Cashbox selected currencies are optional restrictions and linked account stays synced', function (): void {
    seedFinanceFoundation();
    $actor = financeActor([
        'accounts.view',
        'accounts.delete',
        'accounts.restore',
        'accounts.view_trashed',
        'cashboxes.view',
        'cashboxes.create',
        'cashboxes.edit',
        'cashboxes.delete',
        'cashboxes.restore',
        'cashboxes.view_trashed',
    ]);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $usdCurrency = Currency::query()->create([
        'doc_number' => 9902,
        'doc_num' => 'Currency-09902',
        'company_id' => $currency->company_id,
        'name' => 'US Dollar',
        'code' => 'USD',
        'minor_unit_name' => 'Cent',
        'minor_unit_factor' => 100,
        'status' => 'active',
    ]);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Restricted Cashbox',
            'currency_doc_nums' => [$currency->doc_num, $usdCurrency->doc_num],
            'status' => 'active',
        ])
        ->assertOk();

    $record = Cashbox::query()->with('account')->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $linkedAccount = $record->account;

    expect($record->currencies()->count())->toBe(2)
        ->and(Cashbox::query()->where('account_id', $linkedAccount->getKey())->count())->toBe(1);

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cashboxes.update', $record->doc_num), [
            'name' => 'Renamed Cashbox',
            'currency_doc_nums' => [$currency->doc_num],
            'status' => 'inactive',
        ])
        ->assertOk();

    $record->refresh();
    $linkedAccount->refresh();

    expect($record->currencies()->count())->toBe(1)
        ->and($record->status)->toBe('inactive')
        ->and($linkedAccount->name)->toBe('Renamed Cashbox')
        ->and($linkedAccount->name)->not->toContain('EGP', 'USD', $record->doc_num);

    app(AccountService::class)->update($linkedAccount, [
        'account_code' => $linkedAccount->account_code,
        'name' => 'Chart Renamed Cashbox',
        'name_en' => $linkedAccount->name_en,
        'parent_doc_num' => $linkedAccount->parent?->doc_num,
        'classification_code' => 'cash',
        'account_type' => $linkedAccount->account_type,
        'statement_type' => $linkedAccount->statement_type,
        'normal_balance' => $linkedAccount->normal_balance,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
        'notes' => $linkedAccount->notes,
    ]);

    expect($record->refresh()->name)->toBe('Chart Renamed Cashbox')
        ->and($record->status)->toBe('active');

    $this->actingAs($actor)
        ->deleteJson(route('admin.finance.cashboxes.destroy', $record->doc_num))
        ->assertOk();

    expect(Cashbox::withTrashed()->whereKey($record->getKey())->first()?->trashed())->toBeTrue()
        ->and(Account::withTrashed()->whereKey($linkedAccount->getKey())->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.finance.cashboxes.restore', $record->doc_num))
        ->assertOk();

    expect(Cashbox::withTrashed()->whereKey($record->getKey())->first()?->trashed())->toBeFalse()
        ->and(Account::withTrashed()->whereKey($linkedAccount->getKey())->first()?->trashed())->toBeFalse()
        ->and($record->fresh()->currencies()->count())->toBe(1);

    $this->actingAs($actor)
        ->deleteJson(route('admin.accounting.accounts.destroy', $linkedAccount->doc_num))
        ->assertOk();

    expect(Account::withTrashed()->whereKey($linkedAccount->getKey())->first()?->trashed())->toBeTrue()
        ->and(Cashbox::withTrashed()->whereKey($record->getKey())->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.accounting.accounts.restore', $linkedAccount->doc_num))
        ->assertOk();

    expect(Account::withTrashed()->whereKey($linkedAccount->getKey())->first()?->trashed())->toBeFalse()
        ->and(Cashbox::withTrashed()->whereKey($record->getKey())->first()?->trashed())->toBeFalse();
});

test('Cashbox delete does not delete an unmanaged manual chart account', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['cashboxes.view', 'cashboxes.delete']);
    $manualAccount = Account::query()->where('account_code', '1111')->firstOrFail();
    $cashbox = Cashbox::query()->create([
        'doc_number' => 7701,
        'doc_num' => 'CASH-07701',
        'company_id' => $manualAccount->company_id,
        'name' => 'Legacy Manual Account Cashbox',
        'account_id' => $manualAccount->getKey(),
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.finance.cashboxes.destroy', $cashbox->doc_num))
        ->assertOk();

    expect(Cashbox::withTrashed()->whereKey($cashbox->getKey())->first()?->trashed())->toBeTrue()
        ->and(Account::withTrashed()->whereKey($manualAccount->getKey())->first()?->trashed())->toBeFalse();
});

test('Cashbox branch validation is scoped to current company and role branch access', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['cashboxes.view', 'cashboxes.create']);
    $allowedCompany = financeCompany();
    $allowedBranch = financeBranch($allowedCompany, ['name' => 'Allowed Cashbox Branch']);
    $blockedBranch = financeBranch($allowedCompany, ['name' => 'Blocked Cashbox Branch']);
    $role = Role::query()->create([
        'name' => 'cashbox-branch-scope',
        'guard_name' => 'web',
        'doc_number' => 9900,
        'doc_num' => 'Role-09900',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => false,
    ]);
    DB::table('role_company_access')->insert([
        'role_id' => $role->getKey(),
        'company_id' => $allowedCompany->getKey(),
    ]);
    DB::table('role_branch_access')->insert([
        'role_id' => $role->getKey(),
        'branch_id' => $allowedBranch->getKey(),
    ]);
    $actor->assignRole($role);
    financeSelectOperatingCompany($allowedCompany, $allowedBranch);

    $results = $this->actingAs($actor)
        ->getJson(route('admin.finance.select2.branches'))
        ->assertOk()
        ->json('results');

    expect(collect($results)->pluck('id')->all())->toContain($allowedBranch->doc_num)
        ->not->toContain($blockedBranch->doc_num);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Wrong Branch Cashbox',
            'branch_doc_num' => $blockedBranch->doc_num,
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Allowed Branch Cashbox',
            'branch_doc_num' => $allowedBranch->doc_num,
            'status' => 'active',
        ])
        ->assertOk();
});

test('Bank accounts cashboxes and opening balances reject cross-company finance relations', function (): void {
    seedFinanceFoundation();
    $actor = financeActor([
        'bank_accounts.view',
        'bank_accounts.create',
        'cashboxes.view',
        'cashboxes.create',
        'opening_balances.view',
        'opening_balances.create',
    ]);
    $firstCompany = financeCurrentCompany();
    $firstBranch = Branch::query()->where('company_id', $firstCompany->getKey())->firstOrFail();
    $firstPeriod = FinancialPeriod::query()->where('company_id', $firstCompany->getKey())->where('is_closed', false)->firstOrFail();
    $firstCurrency = financeCurrency('EGP', $firstCompany);
    $foreignCurrency = Currency::query()->create([
        'doc_number' => 9100,
        'doc_num' => 'CUR-A-09100',
        'company_id' => $firstCompany->getKey(),
        'name' => 'Scoped Dollar',
        'code' => 'USD',
        'minor_unit_factor' => 100,
        'status' => 'active',
    ]);
    $firstBankGroup = financeBankGroup('Company A Bank');

    $bankDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            'bank_doc_num' => $firstBankGroup->doc_num,
            'currency_doc_num' => $firstCurrency->doc_num,
            'account_name' => 'Scoped Bank Account',
            'status' => 'active',
        ])
        ->assertOk()
        ->json('data.doc_num');

    $cashboxDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Scoped Cashbox',
            'status' => 'active',
        ])
        ->assertOk()
        ->json('data.doc_num');

    $secondCompany = financeCompany();
    $secondBranch = financeBranch($secondCompany);
    $secondPeriod = financePeriod($secondCompany);
    financeSelectOperatingContext($secondCompany, $secondBranch, $secondPeriod);
    $secondBankGroup = financeBankGroup('Company B Bank');
    $debitAccount = Account::query()->where('company_id', $secondCompany->getKey())->where('is_postable', true)->where('is_group', false)->firstOrFail();
    $creditAccount = Account::query()->where('company_id', $secondCompany->getKey())->where('is_postable', true)->where('is_group', false)->whereKeyNot($debitAccount->getKey())->firstOrFail();

    $this->actingAs($actor)
        ->getJson(route('admin.finance.bank-accounts.data'))
        ->assertOk()
        ->assertJsonMissing(['doc_num' => $bankDocNum]);

    $this->actingAs($actor)
        ->getJson(route('admin.finance.cashboxes.data'))
        ->assertOk()
        ->assertJsonMissing(['doc_num' => $cashboxDocNum]);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.bank-accounts.store'), [
            'bank_doc_num' => $secondBankGroup->doc_num,
            'currency_doc_num' => $foreignCurrency->doc_num,
            'account_name' => 'Wrong Currency Bank',
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['currency_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cashboxes.store'), [
            'name' => 'Wrong Scope Cashbox',
            'branch_doc_num' => $firstBranch->doc_num,
            'currency_doc_nums' => [$foreignCurrency->doc_num],
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_doc_num', 'currency_doc_nums.0']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'document_date' => '2026-01-01',
            'currency_doc_num' => $foreignCurrency->doc_num,
            'exchange_rate' => 1,
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 100],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 100],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['currency_doc_num']);
});

test('OpeningBalance enforce debit credit and duplicate rules', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.view', 'opening_balances.create']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $debitAccount = Account::query()->forCompany($company->getKey())->where('is_postable', true)->where('is_group', false)->firstOrFail();
    $creditAccount = Account::query()->forCompany($company->getKey())->where('is_postable', true)->where('is_group', false)->whereKeyNot($debitAccount->getKey())->firstOrFail();
    $groupAccount = Account::query()->forCompany($company->getKey())->where('is_group', true)->firstOrFail();

    $payload = [
        'document_date' => '2026-01-01',
        'currency_doc_num' => $currency->doc_num,
        'exchange_rate' => 1,
        'lines' => [
            ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 100],
            ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 100],
        ],
    ];

    $this->actingAs($actor)->postJson(route('admin.finance.opening-balances.store'), $payload)->assertOk();
    expect(OpeningBalance::query()->firstOrFail()->lines()->where('account_id', $debitAccount->id)->exists())->toBeTrue();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            ...$payload,
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 100],
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 100],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.1.account_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            ...$payload,
            'lines' => [
                ['account_doc_num' => $groupAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 100],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 100],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.account_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            ...$payload,
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 10],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 20],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);
});

test('OpeningBalance validates document period and main currency exchange rate', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.view', 'opening_balances.create', 'opening_balances.edit']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod($company);
    financeSelectOperatingContext($company, $branch, $period);
    $currency = financeCurrency('EGP', $company);
    $debitAccount = Account::query()->where('company_id', $company->getKey())->where('is_postable', true)->where('is_group', false)->where('normal_balance', Account::BalanceDebit)->firstOrFail();
    $creditAccount = Account::query()->where('company_id', $company->getKey())->where('is_postable', true)->where('is_group', false)->where('normal_balance', Account::BalanceCredit)->firstOrFail();
    $payload = fn (array $overrides = []): array => [
        'document_date' => '2026-01-15',
        'currency_doc_num' => $currency->doc_num,
        'exchange_rate' => '1',
        'description' => 'Opening balance validation check',
        'lines' => [
            ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 100],
            ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 100],
        ],
        ...$overrides,
    ];

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), $payload(['document_date' => '2025-12-31']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_date'])
        ->assertJsonFragment(['document_date' => [__('opening_balances.messages.document_date_outside_period')]]);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), $payload(['exchange_rate' => '1.25']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['exchange_rate'])
        ->assertJsonFragment(['exchange_rate' => [__('opening_balances.messages.exchange_rate_main_currency')]]);

    $openingBalanceDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), $payload())
        ->assertOk()
        ->json('data.doc_num');

    $openingBalance = OpeningBalance::query()->where('doc_num', $openingBalanceDocNum)->firstOrFail();
    expect((float) $openingBalance->exchange_rate)->toBe(1.0);

    $openingBalance->forceFill(['is_closed' => false])->save();

    $this->actingAs($actor)
        ->putJson(route('admin.finance.opening-balances.update', $openingBalanceDocNum), $payload(['document_date' => '2027-01-01']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_date']);
});

test('OpeningBalance create form uses localized datepicker default currency and no period or type input', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.view', 'opening_balances.create', 'opening_balances.document_number.control']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);
    $currency = Currency::query()->where('code', 'EGP')->where('is_main', true)->firstOrFail();

    $html = $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.create'))
        ->assertOk()
        ->assertSee('js-date-picker', false)
        ->assertSee('data-date-format', false)
        ->assertSee('data-locale', false)
        ->assertSee('data-primary-focus="description"', false)
        ->assertSee('name="doc_number"', false)
        ->assertSee('placeholder="'.__('item_lookups.document_number_control.placeholder').'"', false)
        ->assertSee(__('item_lookups.document_number_control.helper'), false)
        ->assertSee('name="document_date"', false)
        ->assertSee('id="description"', false)
        ->assertSee('name="description"', false)
        ->assertSee('autofocus', false)
        ->assertSee('name="currency_doc_num"', false)
        ->assertSee('value="'.$currency->doc_num.'" selected', false)
        ->assertSee('data-main-currency-doc-num="'.$currency->doc_num.'"', false)
        ->assertSee('id="exchange_rate"', false)
        ->assertSee('data-numeric-input', false)
        ->assertSee(__('opening_balances.js.add_line_title'), false)
        ->assertSee(__('opening_balances.js.duplicate_line_title'), false)
        ->assertSee(__('opening_balances.js.delete_line_title'), false)
        ->assertSee(__('opening_balances.js.select_account'), false)
        ->assertSee('select2_no_results', false)
        ->assertSee('select2_searching', false)
        ->assertDontSee(__('opening_balances.attributes.balance_difference'), false)
        ->assertDontSee('name="financial_period_id"', false)
        ->assertDontSee('name="opening_balance_type"', false)
        ->getContent();

    expect($html)->not->toContain(__('opening_balances.attributes.opening_balance_type'));
});

test('OpeningBalance account selector returns account nature metadata', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['accounts.view', 'opening_balances.create']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);
    $account = Account::query()
        ->where('company_id', $company->getKey())
        ->where('is_postable', true)
        ->where('is_group', false)
        ->where('status', 'active')
        ->firstOrFail();

    $this->actingAs($actor)
        ->getJson(route('admin.finance.select2.accounts', ['q' => $account->account_code]))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $account->doc_num,
            'normal_balance' => $account->normal_balance,
            'account_nature' => $account->normal_balance,
        ]);

    $script = file_get_contents(public_path('assets/js/modules/Finance/opening-balances.js'));

    expect($script)->toContain('normal_balance')
        ->and($script)->toContain('auto-filled-type')
        ->and($script)->toContain('select2_no_results');
});

test('OpeningBalance normal create save redirects to fresh create page', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.view', 'opening_balances.create']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);
    $currency = Currency::query()->where('company_id', $company->getKey())->where('code', 'EGP')->firstOrFail();
    $debitAccount = Account::query()->where('company_id', $company->getKey())->where('is_postable', true)->where('is_group', false)->where('normal_balance', Account::BalanceDebit)->firstOrFail();
    $creditAccount = Account::query()->where('company_id', $company->getKey())->where('is_postable', true)->where('is_group', false)->where('normal_balance', Account::BalanceCredit)->firstOrFail();

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'submit_action' => 'save',
            'document_date' => '2026-01-01',
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => '1',
            'description' => 'Fresh create redirect check',
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 10],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 10],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('redirect', route('admin.finance.opening-balances.create'));

    expect($response->json('redirect'))->not->toBe(route('admin.finance.opening-balances.edit', $response->json('data.doc_num')));

    $this->actingAs($actor)
        ->get($response->json('redirect'))
        ->assertOk()
        ->assertDontSee('Fresh create redirect check');
});

test('Closed OpeningBalance cannot be edited or deleted but remains cloneable', function (): void {
    seedFinanceFoundation();
    $actor = financeActor([
        'opening_balances.view',
        'opening_balances.create',
        'opening_balances.edit',
        'opening_balances.delete',
        'opening_balances.clone',
    ]);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);
    $currency = Currency::query()->where('company_id', $company->getKey())->where('code', 'EGP')->firstOrFail();
    $debitAccount = Account::query()->where('company_id', $company->getKey())->where('is_postable', true)->where('is_group', false)->where('normal_balance', Account::BalanceDebit)->firstOrFail();
    $creditAccount = Account::query()->where('company_id', $company->getKey())->where('is_postable', true)->where('is_group', false)->where('normal_balance', Account::BalanceCredit)->firstOrFail();
    $payload = [
        'document_date' => '2026-01-01',
        'currency_doc_num' => $currency->doc_num,
        'exchange_rate' => '1',
        'description' => 'Closed lock check',
        'lines' => [
            ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 10],
            ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 10],
        ],
    ];

    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), $payload)
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.edit', $docNum))
        ->assertForbidden();

    $this->actingAs($actor)
        ->putJson(route('admin.finance.opening-balances.update', $docNum), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->deleteJson(route('admin.finance.opening-balances.destroy', $docNum))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.clone', $docNum))
        ->assertOk()
        ->assertSee('js-opening-balance-account', false);

    $rows = $this->actingAs($actor)
        ->getJson(route('admin.finance.opening-balances.data'))
        ->assertOk()
        ->json('data');

    expect($rows[0]['can_edit'])->toBeFalse()
        ->and($rows[0]['edit_blocked_message'])->toBe(__('opening_balances.messages.closed_edit_forbidden'))
        ->and(file_get_contents(public_path('assets/js/modules/Finance/finance-foundation.js')))->toContain('can_edit');
});

test('OpeningBalance show page displays persisted totals and clean exchange rate', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.view', 'opening_balances.create']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $debitAccount = Account::query()->where('is_postable', true)->where('is_group', false)->firstOrFail();
    $creditAccount = Account::query()->where('is_postable', true)->where('is_group', false)->whereKeyNot($debitAccount->getKey())->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'document_date' => '2026-01-01',
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => '1.000000',
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 3276],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 3276],
            ],
        ])
        ->assertOk();

    $openingBalance = OpeningBalance::query()->firstOrFail();

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.show', $openingBalance->doc_num))
        ->assertOk()
        ->assertSeeInOrder([
            __('opening_balances.attributes.document_status'),
            __('opening_balances.statuses.draft'),
            __('opening_balances.attributes.total_debit'),
            '3,276',
            __('opening_balances.attributes.total_credit'),
            '3,276',
        ], false)
        ->assertDontSee(__('opening_balances.attributes.balance_difference'), false)
        ->assertSee(__('common.sections.audit_information'))
        ->assertSee(__('common.fields.created_by'))
        ->assertSee(__('common.fields.created_at'))
        ->assertDontSee('1.000000', false);
});

test('OpeningBalance approval posts one protected journal entry and blocks direct edits', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.view', 'opening_balances.create', 'opening_balances.edit', 'opening_balances.approve']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $debitAccount = Account::query()->where('is_postable', true)->where('is_group', false)->firstOrFail();
    $creditAccount = Account::query()->where('is_postable', true)->where('is_group', false)->whereKeyNot($debitAccount->getKey())->firstOrFail();

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'document_date' => '2026-01-01',
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'description' => 'Opening GL balances',
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 250, 'description' => 'Opening debit'],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 250, 'description' => 'Opening credit'],
            ],
        ])
        ->assertOk();

    $openingBalance = OpeningBalance::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.approve', $openingBalance->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $openingBalance->refresh()->load('journalEntry.lines');
    expect($openingBalance->approved)->toBeTrue()
        ->and($openingBalance->approved_by)->toBe($actor->getKey())
        ->and($openingBalance->approved_at)->not->toBeNull()
        ->and($openingBalance->status)->toBe(OpeningBalance::StatusApproved)
        ->and($openingBalance->journalEntry)->not->toBeNull()
        ->and($openingBalance->journalEntry->company_id)->toBe($company->id)
        ->and($openingBalance->journalEntry->financial_period_id)->toBe($period->id)
        ->and($openingBalance->journalEntry->branch_id)->toBe($branch->id)
        ->and($openingBalance->journalEntry->source_type)->toBe('opening_balance')
        ->and($openingBalance->journalEntry->is_system_generated)->toBeTrue()
        ->and($openingBalance->journalEntry->is_posted)->toBeTrue()
        ->and($openingBalance->journalEntry->lines)->toHaveCount(2)
        ->and($openingBalance->journalEntry->lines->every(fn ($line): bool => (int) $line->branch_id === (int) $branch->id))->toBeTrue()
        ->and($openingBalance->lines()->where('branch_id', $branch->id)->count())->toBe(2);

    expect(JournalEntry::query()->where('source_type', 'opening_balance')->where('source_id', $openingBalance->id)->count())->toBe(1);

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.show', $openingBalance->doc_num))
        ->assertOk()
        ->assertSee(__('opening_balances.statuses.approved'))
        ->assertSee(__('opening_balances.attributes.approved_by'))
        ->assertSee(__('opening_balances.attributes.approved_at'))
        ->assertSee($actor->doc_num);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.approve', $openingBalance->doc_num))
        ->assertStatus(422);

    $this->actingAs($actor)
        ->putJson(route('admin.finance.opening-balances.update', $openingBalance->doc_num), [
            'document_date' => '2026-01-01',
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 250],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 250],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);
});

test('OpeningBalance later periods reject duplicate manual carry forward', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.create', 'opening_balances.approve']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $firstPeriod = financePeriod($company);
    financeSelectOperatingContext($company, $branch, $firstPeriod);
    $currency = financeCurrency('EGP', $company);
    $debitAccount = Account::query()->forCompany($company->getKey())->eligibleForDirectPosting()->firstOrFail();
    $creditAccount = Account::query()->forCompany($company->getKey())->eligibleForDirectPosting()->whereKeyNot($debitAccount->getKey())->firstOrFail();
    $firstOpeningDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'document_date' => $firstPeriod->from_date->toDateString(),
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'description' => 'First-period opening',
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 250],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 250],
            ],
        ])
        ->assertOk()
        ->json('data.doc_num');
    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.approve', $firstOpeningDocNum))
        ->assertOk();
    $laterPeriod = FinancialPeriod::query()->create([
        'doc_number' => 9002,
        'doc_num' => 'Period-09002',
        'company_id' => $company->getKey(),
        'name' => 'FY Test 2',
        'from_date' => $firstPeriod->to_date->copy()->addDay(),
        'to_date' => $firstPeriod->to_date->copy()->addYear(),
        'is_closed' => false,
    ]);
    financeSelectOperatingContext($company, $branch, $laterPeriod);
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'document_date' => $laterPeriod->from_date->toDateString(),
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'description' => 'Duplicate later-period opening',
            'lines' => [
                ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => 250],
                ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => 250],
            ],
        ])
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.approve', $docNum))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    expect(OpeningBalance::query()
        ->where('company_id', $company->getKey())
        ->where('financial_period_id', $laterPeriod->getKey())
        ->where('doc_num', $docNum)
        ->firstOrFail()
        ->approved)->toBeFalse();
});

test('OpeningBalance bulk approve is permission based and approves scoped draft documents', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.view', 'opening_balances.create', 'opening_balances.approve']);
    $withoutApprove = financeActor(['opening_balances.view', 'opening_balances.create']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);
    $currency = Currency::query()->where('company_id', $company->getKey())->where('code', 'EGP')->firstOrFail();
    $debitAccount = Account::query()->where('company_id', $company->getKey())->where('is_postable', true)->where('is_group', false)->where('normal_balance', Account::BalanceDebit)->firstOrFail();
    $creditAccount = Account::query()->where('company_id', $company->getKey())->where('is_postable', true)->where('is_group', false)->where('normal_balance', Account::BalanceCredit)->firstOrFail();
    $payload = fn (int $amount, string $documentDate = '2026-01-01'): array => [
        'document_date' => $documentDate,
        'currency_doc_num' => $currency->doc_num,
        'exchange_rate' => 1,
        'lines' => [
            ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => $amount],
            ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => $amount],
        ],
    ];

    $this->actingAs($withoutApprove)
        ->get(route('admin.finance.opening-balances.index'))
        ->assertOk()
        ->assertDontSee(__('opening_balances.actions.approve'));

    $this->actingAs($actor)
        ->get(route('admin.finance.opening-balances.index'))
        ->assertOk()
        ->assertSee(route('admin.finance.opening-balances.bulk-approve'), false)
        ->assertSee(__('opening_balances.actions.approve'));

    $first = $this->actingAs($actor)->postJson(route('admin.finance.opening-balances.store'), $payload(10))->assertOk()->json('data.doc_num');
    $second = $this->actingAs($actor)->postJson(route('admin.finance.opening-balances.store'), $payload(20))->assertOk()->json('data.doc_num');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.bulk-approve'), ['doc_nums' => [$first, $second]])
        ->assertOk()
        ->assertJsonPath('data.approved', 2)
        ->assertJsonPath('data.skipped', 0);

    expect(OpeningBalance::query()->whereIn('doc_num', [$first, $second])->where('approved', true)->count())->toBe(2);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.bulk-approve'), ['doc_nums' => [$first, $second]])
        ->assertOk()
        ->assertJsonPath('data.approved', 0)
        ->assertJsonPath('data.skipped', 2);
});

test('OpeningBalance uses current company period scope and scoped document numbers', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.view', 'opening_balances.create', 'opening_balances.edit', 'opening_balances.document_number.control']);
    $currency = Currency::query()->where('code', 'EGP')->firstOrFail();
    $debitAccount = Account::query()->where('is_postable', true)->where('is_group', false)->firstOrFail();
    $creditAccount = Account::query()->where('is_postable', true)->where('is_group', false)->whereKeyNot($debitAccount->getKey())->firstOrFail();
    $companyA = financeCompany();
    $branchA = financeBranch($companyA);
    $period2026 = financePeriod();
    $alternatePeriod = FinancialPeriod::query()->create([
        'doc_number' => 9002,
        'doc_num' => 'Period-09002',
        'company_id' => $companyA->getKey(),
        'name' => 'FY Test Alternate',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);
    $companyB = Company::query()->whereKeyNot($companyA->getKey())->orderBy('id')->firstOrFail();
    $branchB = Branch::query()->where('company_id', $companyB->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $periodB2026 = FinancialPeriod::query()->where('company_id', $companyB->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();
    $payload = fn (int $amount): array => [
        'document_date' => '2026-01-01',
        'currency_doc_num' => $currency->doc_num,
        'exchange_rate' => 1,
        'lines' => [
            ['account_doc_num' => $debitAccount->doc_num, 'transaction_type' => 'debit', 'amount' => $amount],
            ['account_doc_num' => $creditAccount->doc_num, 'transaction_type' => 'credit', 'amount' => $amount],
        ],
    ];

    financeSelectOperatingContext($companyA, $branchA, $period2026);
    $first = $this->actingAs($actor)->postJson(route('admin.finance.opening-balances.store'), $payload(100))->assertOk()->json('data.doc_num');
    $second = $this->actingAs($actor)->postJson(route('admin.finance.opening-balances.store'), $payload(200))->assertOk()->json('data.doc_num');

    expect($first)->toBe('OB-00001')
        ->and($second)->toBe('OB-00002');

    financeSelectOperatingContext($companyA, $branchA, $alternatePeriod);
    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), $payload(300))
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'OB-00001');

    financeSelectOperatingContext($companyB, $branchB, $periodB2026);
    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), $payload(400))
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'OB-00001');

    financeSelectOperatingContext($companyA, $branchA, $period2026);
    $rows = $this->actingAs($actor)
        ->getJson(route('admin.finance.opening-balances.data'))
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(2)
        ->and(array_key_exists('financial_period', $rows[0]))->toBeFalse()
        ->and(array_key_exists('opening_balance_type', $rows[0]))->toBeFalse();

    $duplicateTarget = OpeningBalance::query()
        ->where('company_id', $companyA->id)
        ->where('financial_period_id', $period2026->id)
        ->where('doc_num', 'OB-00002')
        ->firstOrFail();
    $duplicateTarget->forceFill(['is_closed' => false])->save();

    $this->actingAs($actor)
        ->putJson(route('admin.finance.opening-balances.update', $duplicateTarget->doc_num), [
            ...$payload(200),
            'doc_number' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['doc_number']);
});

test('finance datatables do not expose internal ids', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['currencies.view', 'bank_accounts.view', 'cashboxes.view', 'opening_balances.view']);

    $this->actingAs($actor)
        ->getJson(route('admin.currencies.data'))
        ->assertOk()
        ->assertJsonMissingPath('data.0.id')
        ->assertJsonStructure(['data' => [['doc_num', 'name', 'code', 'minor_unit_name', 'minor_unit_factor', 'is_main', 'status', 'actions']]]);
    $this->actingAs($actor)->getJson(route('admin.finance.bank-accounts.data'))->assertOk()->assertJsonMissingPath('data.0.id');
    $this->actingAs($actor)->getJson(route('admin.finance.cashboxes.data'))->assertOk()->assertJsonMissingPath('data.0.id');
    $this->actingAs($actor)->getJson(route('admin.finance.opening-balances.data'))->assertOk()->assertJsonMissingPath('data.0.id');
});

test('finance selectors require eligible same-company linked ledger accounts and keep pagination correct', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['fund_transfers.view', 'cash_receipt_vouchers.view']);
    $company = financeCurrentCompany();
    $currency = financeCurrency(company: $company);
    config(['select2.pagination.per_page' => 1]);

    $account = function (int $number, string $name, array $overrides = []) use ($company): Account {
        return Account::query()->create([
            'doc_number' => $number,
            'doc_num' => 'ACC-SELECT-'.$number,
            'company_id' => $company->getKey(),
            'account_code' => '19'.$number,
            'name' => $name,
            'name_en' => $name,
            'level' => 1,
            'account_type' => Account::TypeAsset,
            'statement_type' => Account::StatementFinancialPosition,
            'normal_balance' => Account::BalanceDebit,
            'is_group' => false,
            'is_postable' => true,
            'status' => 'active',
            ...$overrides,
        ]);
    };
    $eligibleOne = $account(8101, 'Eligible Ledger One');
    $eligibleTwo = $account(8102, 'Eligible Ledger Two');
    $inactive = $account(8103, 'Inactive Ledger', ['status' => 'inactive']);
    $deleted = $account(8104, 'Deleted Ledger');
    $deleted->delete();
    $group = $account(8105, 'Group Ledger', ['is_group' => true, 'is_postable' => false]);
    $otherCompany = Company::query()->create([
        'doc_number' => 8199,
        'doc_num' => 'COMP-SELECT-8199',
        'name' => 'Other Selector Company',
        'status' => 'active',
    ]);
    $wrongCompany = $account(8106, 'Wrong Company Ledger');
    $wrongCompany->forceFill(['company_id' => $otherCompany->getKey()])->save();

    $cashbox = function (int $number, Account $linkedAccount, string $name) use ($company): Cashbox {
        return Cashbox::query()->create([
            'doc_number' => $number,
            'doc_num' => 'CASH-SELECT-'.$number,
            'company_id' => $company->getKey(),
            'name' => $name,
            'account_id' => $linkedAccount->getKey(),
            'status' => 'active',
        ]);
    };
    $eligibleCashboxOne = $cashbox(8201, $eligibleOne, 'Eligible Cashbox One');
    $eligibleCashboxTwo = $cashbox(8202, $eligibleTwo, 'Eligible Cashbox Two');
    $cashbox(8203, $inactive, 'Inactive Ledger Cashbox');
    $cashbox(8204, $deleted, 'Deleted Ledger Cashbox');
    $cashbox(8205, $group, 'Group Ledger Cashbox');
    $cashbox(8206, $wrongCompany, 'Wrong Company Ledger Cashbox');

    $firstPage = $this->actingAs($actor)->getJson(route('admin.finance.select2.cashboxes', ['page' => 1]))->assertOk()->json();
    $secondPage = $this->actingAs($actor)->getJson(route('admin.finance.select2.cashboxes', ['page' => 2]))->assertOk()->json();
    expect(collect($firstPage['results'])->pluck('id')->all())->toBe([$eligibleCashboxOne->doc_num])
        ->and($firstPage['pagination']['more'])->toBeTrue()
        ->and(collect($secondPage['results'])->pluck('id')->all())->toBe([$eligibleCashboxTwo->doc_num])
        ->and($secondPage['pagination']['more'])->toBeFalse();

    foreach (['Inactive Ledger Cashbox', 'Deleted Ledger Cashbox', 'Group Ledger Cashbox', 'Wrong Company Ledger Cashbox'] as $search) {
        $this->actingAs($actor)
            ->getJson(route('admin.finance.select2.cashboxes', ['q' => $search]))
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    $bankGroup = financeBankGroup('Selector Bank Group');
    $bankAccount = function (int $number, Account $linkedAccount, string $name) use ($bankGroup, $company, $currency): BankAccount {
        return BankAccount::query()->create([
            'doc_number' => $number,
            'doc_num' => 'BANK-SELECT-'.$number,
            'company_id' => $company->getKey(),
            'bank_id' => $bankGroup->getKey(),
            'account_id' => $linkedAccount->getKey(),
            'currency_id' => $currency->getKey(),
            'account_name' => $name,
            'account_number' => 'SELECT-'.$number,
            'status' => 'active',
        ]);
    };
    $eligibleBank = $bankAccount(8301, $eligibleOne, 'Eligible Bank Holder');
    $bankAccount(8302, $inactive, 'Inactive Ledger Bank');
    $bankAccount(8303, $deleted, 'Deleted Ledger Bank');
    $bankAccount(8304, $group, 'Group Ledger Bank');
    $bankAccount(8305, $wrongCompany, 'Wrong Company Ledger Bank');

    $bankResults = $this->actingAs($actor)
        ->getJson(route('admin.finance.select2.bank-accounts'))
        ->assertOk()
        ->json('results');
    expect(collect($bankResults)->pluck('id')->all())->toBe([$eligibleBank->doc_num]);

    $this->actingAs($actor)
        ->getJson(route('admin.finance.select2.bank-accounts', ['q' => 'Deleted Ledger Bank']))
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

test('opening balance lines retain soft deleted historical accounts', function (): void {
    seedFinanceFoundation();
    $company = financeCurrentCompany();
    $currency = financeCurrency(company: $company);
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->firstOrFail();
    $account = Account::query()->forCompany($company->getKey())->eligibleForDirectPosting()->firstOrFail();
    $openingBalance = financeOpeningBalanceRecord($company, $period, $currency, 8401, 'Historical account relation');
    $line = $openingBalance->lines()->create([
        'line_no' => 1,
        'account_id' => $account->getKey(),
        'debit_amount' => 10,
        'credit_amount' => 0,
    ]);

    $account->delete();

    expect($line->fresh()?->account)->toBeInstanceOf(Account::class)
        ->and($line->fresh()?->account?->trashed())->toBeTrue();
});

test('OpeningBalance lines keep maximum accepted decimal precision before persistence', function (): void {
    seedFinanceFoundation();
    $actor = financeActor(['opening_balances.create']);
    $company = financeCompany();
    $branch = financeBranch($company);
    $period = financePeriod();
    financeSelectOperatingContext($company, $branch, $period);
    $currency = Currency::query()->where('company_id', $company->getKey())->where('is_main', true)->firstOrFail();
    $accounts = Account::query()
        ->forCompany($company->getKey())
        ->where('is_postable', true)
        ->where('is_group', false)
        ->limit(2)
        ->get();
    $capturedLines = [];

    OpeningBalanceLine::creating(function (OpeningBalanceLine $line) use (&$capturedLines): void {
        $capturedLines[] = $line->getAttributes();
    });

    $this->actingAs($actor)
        ->postJson(route('admin.finance.opening-balances.store'), [
            'document_date' => $period->from_date->toDateString(),
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => '1',
            'lines' => [
                [
                    'account_doc_num' => $accounts[0]->doc_num,
                    'transaction_type' => 'debit',
                    'amount' => '99,999,999,999,999.9999',
                ],
                [
                    'account_doc_num' => $accounts[1]->doc_num,
                    'transaction_type' => 'credit',
                    'amount' => '99,999,999,999,999.9999',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($capturedLines)->toHaveCount(2)
        ->and($capturedLines[0]['debit_amount'])->toBe('99999999999999.9999')
        ->and($capturedLines[0]['credit_amount'])->toBe('0.0000')
        ->and($capturedLines[1]['debit_amount'])->toBe('0.0000')
        ->and($capturedLines[1]['credit_amount'])->toBe('99999999999999.9999');

    $creditLine = new OpeningBalanceLine;
    $creditLine->setRawAttributes([
        'debit_amount' => null,
        'credit_amount' => '1250.5000',
        'description' => null,
    ]);
    $creditLine->setRelation('account', $accounts[1]);
    $comparisonRecord = new OpeningBalance;
    $comparisonRecord->setRelation('lines', new Collection([$creditLine]));
    $linesChanged = new ReflectionMethod(OpeningBalanceService::class, 'linesChanged');

    expect($linesChanged->invoke(app(OpeningBalanceService::class), $comparisonRecord, [[
        'account_doc_num' => $accounts[1]->doc_num,
        'transaction_type' => 'credit',
        'amount' => '1,250.5',
        'description' => null,
    ]]))->toBeFalse();
});
