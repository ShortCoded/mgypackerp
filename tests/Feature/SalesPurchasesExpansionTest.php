<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Database\Seeders\EmergencyRecoverySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrGovernorate;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierCreditLimit;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCreditLimit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function salesPurchasesActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);

    return $user;
}

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod}
 */
function salesPurchasesContext(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(AccountClassificationsSeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();

    salesPurchasesSelectContext($company, $branch, $period);

    return ['company' => $company, 'branch' => $branch, 'period' => $period];
}

function salesPurchasesSelectContext(Company $company, Branch $branch, FinancialPeriod $period): void
{
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    session($context);
    test()->withSession($context);
}

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod}
 */
function salesPurchasesOtherContext(int $number): array
{
    $company = Company::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Company-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Business Partner Company '.$number,
        'status' => 'active',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Branch-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Business Partner Branch '.$number,
        'type' => 'main',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'FY '.$number,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'status' => 'active',
    ]);

    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    return ['company' => $company, 'branch' => $branch, 'period' => $period];
}

/**
 * @return array{country: HrCountry, governorate: HrGovernorate, city: HrCity, area: HrArea}
 */
function salesPurchasesLocationSet(int $number): array
{
    $country = HrCountry::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Country-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Country '.$number,
    ]);
    $governorate = HrGovernorate::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Governorate-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Governorate '.$number,
        'country_id' => $country->getKey(),
    ]);
    $city = HrCity::query()->create([
        'doc_number' => $number,
        'doc_num' => 'City-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'City '.$number,
        'governorate_id' => $governorate->getKey(),
    ]);
    $area = HrArea::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Area-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Area '.$number,
        'city_id' => $city->getKey(),
    ]);

    return compact('country', 'governorate', 'city', 'area');
}

test('Menu shows sales and purchases while HR stays hidden without HR permission', function (): void {
    $this->seed(PermissionSeeder::class);

    $actor = salesPurchasesActor(['customers.view', 'suppliers.view', 'file_manager.view']);
    $this->actingAs($actor);

    $menu = app(MenuService::class)->getMenu($actor);
    $labels = array_column($menu, 'label');

    expect($labels)
        ->toContain('sales')
        ->toContain('purchases')
        ->toContain('tools')
        ->not->toContain('human_resources')
        ->and(array_search('sales', $labels, true))->toBeLessThan(array_search('purchases', $labels, true))
        ->and(array_search('purchases', $labels, true))->toBeLessThan(array_search('tools', $labels, true));

    $sales = collect($menu)->firstWhere('label', 'sales');
    $purchases = collect($menu)->firstWhere('label', 'purchases');

    expect(collect($sales['children'])->pluck('label')->all())->toContain('customers')
        ->and(collect($purchases['children'])->pluck('label')->all())->toContain('suppliers')
        ->and(app(PermissionRegistryService::class)->all())->toContain('hr.employees.view')
        ->and(app(PermissionRegistryService::class)->all())->toContain('hr.departments.view')
        ->and(app(PermissionRegistryService::class)->all())->toContain('hr.countries.view');

    $customerOnly = salesPurchasesActor(['customers.view']);
    $this->actingAs($customerOnly);
    $customerOnlyLabels = array_column(app(MenuService::class)->getMenu($customerOnly), 'label');

    expect($customerOnlyLabels)->toContain('sales')->not->toContain('purchases');
});

test('Customer and Supplier permissions are discovered for admin role', function (): void {
    $this->seed(PermissionSeeder::class);

    $permissions = app(PermissionRegistryService::class)->all();

    foreach (['customers', 'suppliers'] as $resource) {
        foreach (['view', 'create', 'clone', 'edit', 'delete', 'view_trashed', 'restore', 'document_number.control', 'document_number_settings.update'] as $action) {
            expect($permissions)->toContain("{$resource}.{$action}")
                ->and(Permission::query()->where('name', "{$resource}.{$action}")->exists())->toBeTrue();
        }
    }
});

test('Customer and Supplier tables are company scoped and account linked', function (): void {
    expect(Schema::hasColumns('customers', ['company_id', 'account_id', 'account_group_id']))->toBeTrue()
        ->and(Schema::hasColumns('suppliers', ['company_id', 'account_id', 'account_group_id']))->toBeTrue()
        ->and(Schema::hasColumns('customer_credit_limits', ['company_id', 'customer_id', 'currency_id', 'credit_limit']))->toBeTrue()
        ->and(Schema::hasColumns('supplier_credit_limits', ['company_id', 'supplier_id', 'currency_id', 'credit_limit']))->toBeTrue();
});

test('Customer and Supplier forms remove payment terms and scalar credit limit fields', function (): void {
    salesPurchasesContext();
    $actor = salesPurchasesActor(['customers.create', 'suppliers.create']);
    $customerScript = file_get_contents(public_path('assets/js/modules/Sales/customers.js'));
    $supplierScript = file_get_contents(public_path('assets/js/modules/Purchases/suppliers.js'));

    $customerHtml = $this->actingAs($actor)
        ->get(route('admin.sales.customers.create'))
        ->assertOk()
        ->assertSee(__('customers.tabs.credit_limits'))
        ->assertSee(__('common.fields.actions'))
        ->assertSee(__('business_partners.actions.add_credit_limit_shortcut'), false)
        ->assertSee(__('business_partners.actions.duplicate_credit_limit_shortcut'), false)
        ->assertSee(__('business_partners.actions.delete_credit_limit_shortcut'), false)
        ->assertSee('business-partner-credit-limits-table', false)
        ->assertSee('text-center" name="credit_limits[__INDEX__][credit_limit]"', false)
        ->assertSee('select2NoResults', false)
        ->assertSee('select2Searching', false)
        ->assertDontSee('payment_terms_days', false)
        ->assertDontSee('name="credit_limit"', false)
        ->assertDontSee('common.actions.actions', false)
        ->assertDontSee('data-parent-required-message=', false)
        ->getContent();

    $supplierHtml = $this->actingAs($actor)
        ->get(route('admin.purchases.suppliers.create'))
        ->assertOk()
        ->assertSee(__('suppliers.tabs.credit_limits'))
        ->assertSee(__('common.fields.actions'))
        ->assertSee(__('business_partners.actions.add_credit_limit_shortcut'), false)
        ->assertSee(__('business_partners.actions.duplicate_credit_limit_shortcut'), false)
        ->assertSee(__('business_partners.actions.delete_credit_limit_shortcut'), false)
        ->assertSee('business-partner-credit-limits-table', false)
        ->assertSee('text-center" name="credit_limits[__INDEX__][credit_limit]"', false)
        ->assertSee('select2NoResults', false)
        ->assertSee('select2Searching', false)
        ->assertDontSee('payment_terms_days', false)
        ->assertDontSee('name="credit_limit"', false)
        ->assertDontSee('common.actions.actions', false)
        ->assertDontSee('data-parent-required-message=', false)
        ->getContent();

    expect($customerHtml)
        ->not->toMatch('/id="(?:governorate|city|area)_doc_num"[^>]+data-depends-on=/')
        ->and($supplierHtml)
        ->not->toMatch('/id="(?:governorate|city|area)_doc_num"[^>]+data-depends-on=/');

    expect($customerScript)
        ->toContain('select2NoResults')
        ->toContain('businessCreditLimitShortcuts')
        ->toContain("['KeyN']")
        ->toContain("['KeyD']")
        ->toContain('isAltDelete')
        ->toContain('AppContactActions')
        ->toContain('focusFirstInvalid')
        ->toContain('select2Selection')
        ->and($supplierScript)
        ->toContain('select2NoResults')
        ->toContain('businessCreditLimitShortcuts')
        ->toContain("['KeyN']")
        ->toContain("['KeyD']")
        ->toContain('isAltDelete')
        ->toContain('AppContactActions')
        ->toContain('focusFirstInvalid')
        ->toContain('select2Selection');
});

test('Customer and Supplier indexes use standard record filters and audit headers', function (): void {
    salesPurchasesContext();
    $actor = salesPurchasesActor(['customers.view', 'customers.view_trashed', 'suppliers.view', 'suppliers.view_trashed']);

    $this->actingAs($actor)
        ->get(route('admin.sales.customers.index'))
        ->assertOk()
        ->assertSee(__('business_partners.trash.filter_label'))
        ->assertSee(__('business_partners.trash.active'))
        ->assertSee(__('business_partners.trash.trashed'))
        ->assertSee(__('common.fields.created_by'))
        ->assertSee(__('common.fields.created_at'))
        ->assertSee(__('common.fields.updated_by'))
        ->assertSee(__('common.fields.updated_at'));

    $this->actingAs($actor)
        ->get(route('admin.purchases.suppliers.index'))
        ->assertOk()
        ->assertSee(__('business_partners.trash.filter_label'))
        ->assertSee(__('business_partners.trash.active'))
        ->assertSee(__('business_partners.trash.trashed'))
        ->assertSee(__('common.fields.created_by'))
        ->assertSee(__('common.fields.created_at'))
        ->assertSee(__('common.fields.updated_by'))
        ->assertSee(__('common.fields.updated_at'));
});

test('Customer and Supplier DataTables return audit keys expected by frontend', function (): void {
    salesPurchasesContext();
    $actor = salesPurchasesActor(['customers.view', 'customers.create', 'suppliers.view', 'suppliers.create']);
    $this->actingAs($actor);

    $this->postJson(route('admin.sales.customers.store'), ['name' => 'DataTable Customer', 'status' => 'active', 'phone' => '+201001112233'])
        ->assertOk()
        ->assertJsonPath('reset_form', true)
        ->assertJsonMissingPath('redirect');
    $this->postJson(route('admin.purchases.suppliers.store'), ['name' => 'DataTable Supplier', 'status' => 'active', 'mobile' => '01004445566'])
        ->assertOk()
        ->assertJsonPath('reset_form', true)
        ->assertJsonMissingPath('redirect');

    $customerRow = $this->getJson(route('admin.sales.customers.data'))->assertOk()->json('data.0');
    $supplierRow = $this->getJson(route('admin.purchases.suppliers.data'))->assertOk()->json('data.0');

    foreach ([$customerRow, $supplierRow] as $row) {
        expect($row)->toHaveKeys([
            'checkbox',
            'doc_num',
            'name',
            'account',
            'phone',
            'mobile',
            'email',
            'tax_number',
            'status',
            'created_by',
            'created_at',
            'updated_by',
            'updated_at',
            'actions',
        ])->not->toHaveKeys(['id', 'company_id', 'account_id']);
    }

    expect($customerRow['phone'])
        ->toContain('js-user-phone-contact')
        ->toContain('data-bs-toggle="popover"')
        ->toContain('dir="ltr"')
        ->toContain('tel:+201001112233')
        ->toContain('https://wa.me/201001112233')
        ->and($customerRow['mobile'])->toBe(__('common.empty_value'))
        ->and($supplierRow['phone'])->toBe(__('common.empty_value'))
        ->and($supplierRow['mobile'])
        ->toContain('js-user-phone-contact')
        ->toContain('data-bs-toggle="popover"')
        ->toContain('dir="ltr"')
        ->toContain('tel:01004445566')
        ->toContain('https://wa.me/01004445566');
});

test('Customer can be created with nullable optional fields and a postable account under Customers root', function (): void {
    $context = salesPurchasesContext();
    $actor = salesPurchasesActor(['customers.create', 'customers.view']);

    $this->actingAs($actor)
        ->postJson(route('admin.sales.customers.store'), [
            'name' => 'Acme Customer',
            'status' => 'active',
            'company_id' => 999999,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $customer = Customer::query()->firstOrFail();
    $account = Account::query()->findOrFail($customer->account_id);
    $root = Account::query()->where('company_id', $context['company']->getKey())->where('account_code', '1121')->firstOrFail();

    expect($customer->company_id)->toBe($context['company']->getKey())
        ->and($customer->phone)->toBeNull()
        ->and($customer->account_group_id)->toBeNull()
        ->and($account->company_id)->toBe($context['company']->getKey())
        ->and($account->parent_id)->toBe($root->getKey())
        ->and($account->name)->toBe('Acme Customer')
        ->and($account->is_group)->toBeFalse()
        ->and($account->is_postable)->toBeTrue();
});

test('Customer saves nullable locations and multi-currency credit limits transactionally', function (): void {
    $context = salesPurchasesContext();
    $actor = salesPurchasesActor(['customers.create', 'customers.edit', 'customers.view']);
    $this->actingAs($actor);
    $locations = salesPurchasesLocationSet(12001);
    $egp = Currency::query()->where('company_id', $context['company']->getKey())->firstOrFail();
    $usd = Currency::query()->create([
        'doc_number' => 12001,
        'doc_num' => 'Currency-12001',
        'company_id' => $context['company']->getKey(),
        'name' => 'US Dollar Test',
        'code' => 'USD-T',
        'minor_unit_name' => 'Cent',
        'minor_unit_factor' => 100,
        'status' => 'active',
    ]);

    $this->postJson(route('admin.sales.customers.store'), [
        'name' => 'Credit Customer',
        'status' => 'active',
        'country_doc_num' => $locations['country']->doc_num,
        'governorate_doc_num' => $locations['governorate']->doc_num,
        'city_doc_num' => $locations['city']->doc_num,
        'area_doc_num' => $locations['area']->doc_num,
        'credit_limits' => [
            ['currency_doc_num' => $egp->doc_num, 'credit_limit' => '1000.50'],
            ['currency_doc_num' => $usd->doc_num, 'credit_limit' => '2500', 'notes' => 'Seasonal'],
        ],
    ])->assertOk();

    $customer = Customer::query()->where('name', 'Credit Customer')->firstOrFail();

    expect($customer->country_id)->toBe($locations['country']->getKey())
        ->and($customer->area_id)->toBe($locations['area']->getKey())
        ->and(CustomerCreditLimit::query()->where('customer_id', $customer->getKey())->count())->toBe(2);

    $this->putJson(route('admin.sales.customers.update', $customer->doc_num), [
        'name' => 'Credit Customer',
        'status' => 'active',
        'country_doc_num' => $locations['country']->doc_num,
        'governorate_doc_num' => $locations['governorate']->doc_num,
        'city_doc_num' => $locations['city']->doc_num,
        'area_doc_num' => $locations['area']->doc_num,
        'credit_limits' => [
            ['currency_doc_num' => $egp->doc_num, 'credit_limit' => '333'],
        ],
    ])->assertOk();

    expect(CustomerCreditLimit::query()->where('customer_id', $customer->getKey())->count())->toBe(1)
        ->and(CustomerCreditLimit::query()->where('customer_id', $customer->getKey())->where('currency_id', $egp->getKey())->value('credit_limit'))->toBe('333.0000');

    $this->postJson(route('admin.sales.customers.store'), [
        'name' => 'Duplicate Currency Customer',
        'status' => 'active',
        'credit_limits' => [
            ['currency_doc_num' => $egp->doc_num, 'credit_limit' => '1'],
            ['currency_doc_num' => $egp->doc_num, 'credit_limit' => '2'],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['credit_limits.1.currency_doc_num']);
});

test('Customer accepts independent locations and exposes location quick create', function (): void {
    salesPurchasesContext();
    $actor = salesPurchasesActor(['customers.create']);
    $this->actingAs($actor);
    $valid = salesPurchasesLocationSet(12011);
    $other = salesPurchasesLocationSet(12012);

    $this->postJson(route('admin.sales.customers.store'), [
        'name' => 'Bad Location Customer',
        'status' => 'active',
        'country_doc_num' => $valid['country']->doc_num,
        'governorate_doc_num' => $other['governorate']->doc_num,
    ])->assertOk();

    $customer = Customer::query()->where('name', 'Bad Location Customer')->firstOrFail();
    expect($customer->country_id)->toBe($valid['country']->getKey())
        ->and($customer->governorate_id)->toBe($other['governorate']->getKey());

    $this->getJson(route('admin.select2.governorates', ['q' => 'Governorate 1201']))
        ->assertOk()
        ->assertJsonFragment(['id' => $valid['governorate']->doc_num])
        ->assertJsonFragment(['id' => $other['governorate']->doc_num]);

    $this->postJson(route('admin.select2.inline.locations.store', 'cities'), [
        'name' => 'Inline Customer City',
    ])->assertOk()
        ->assertJsonPath('data.option.text', 'Inline Customer City');
});

test('Customer group selector quick create and selected group account linking are scoped', function (): void {
    $context = salesPurchasesContext();
    $actor = salesPurchasesActor(['customers.view', 'customers.create', 'accounts.create']);
    $this->actingAs($actor);

    $root = Account::query()->where('company_id', $context['company']->getKey())->where('account_code', '1121')->firstOrFail();
    $group = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::Customer, 'Retail Customers');
    $inactiveGroup = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::Customer, 'Inactive Customers');
    $inactiveGroup->forceFill(['status' => 'inactive'])->save();
    app(AccountService::class)->createChildFromParent($root, [
        'name' => 'Postable Customer Account',
        'classification_code' => 'accounts_receivable',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);

    $this->getJson(route('admin.sales.select2.customer-groups', ['q' => 'Customers']))
        ->assertOk()
        ->assertJsonFragment(['id' => $group->doc_num])
        ->assertJsonMissing(['id' => $inactiveGroup->doc_num]);

    $this->postJson(route('admin.sales.customers.account-groups.store'), ['name' => 'Wholesale Customers'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissing(['id' => (string) $group->getKey()]);

    $this->postJson(route('admin.sales.customers.store'), [
        'name' => 'Grouped Customer',
        'status' => 'active',
        'account_group_doc_num' => $group->doc_num,
    ])->assertOk();

    $customer = Customer::query()->where('name', 'Grouped Customer')->firstOrFail();
    $account = Account::query()->findOrFail($customer->account_id);

    expect($customer->account_group_id)->toBe($group->getKey())
        ->and($account->parent_id)->toBe($group->getKey())
        ->and($account->is_group)->toBeFalse();
});

test('Customer rejects cross-company and postable accounts as groups', function (): void {
    $context = salesPurchasesContext();
    $other = salesPurchasesOtherContext(9911);
    $actor = salesPurchasesActor(['customers.create']);
    $this->actingAs($actor);

    salesPurchasesSelectContext($other['company'], $other['branch'], $other['period']);
    $otherGroup = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::Customer, 'Other Company Customers');

    salesPurchasesSelectContext($context['company'], $context['branch'], $context['period']);
    $root = Account::query()->where('company_id', $context['company']->getKey())->where('account_code', '1121')->firstOrFail();
    $postable = app(AccountService::class)->createChildFromParent($root, [
        'name' => 'Postable Customer Account',
        'classification_code' => 'accounts_receivable',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);

    $this->postJson(route('admin.sales.customers.store'), [
        'name' => 'Cross Company Customer',
        'status' => 'active',
        'account_group_doc_num' => $otherGroup->doc_num,
    ])->assertStatus(422)->assertJsonValidationErrors(['account_group_doc_num']);

    $this->postJson(route('admin.sales.customers.store'), [
        'name' => 'Postable Group Customer',
        'status' => 'active',
        'account_group_doc_num' => $postable->doc_num,
    ])->assertStatus(422)->assertJsonValidationErrors(['account_group_doc_num']);
});

test('Customer edit syncs name status and parent account, list is company scoped and hides internal IDs', function (): void {
    $context = salesPurchasesContext();
    $other = salesPurchasesOtherContext(9922);
    $actor = salesPurchasesActor(['customers.view', 'customers.create', 'customers.edit']);
    $this->actingAs($actor);

    $this->postJson(route('admin.sales.customers.store'), ['name' => 'Before Customer', 'status' => 'active'])->assertOk();
    $customer = Customer::query()->firstOrFail();
    $group = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::Customer, 'Moved Customers');

    $this->putJson(route('admin.sales.customers.update', $customer->doc_num), [
        'name' => 'After Customer',
        'status' => 'inactive',
        'account_group_doc_num' => $group->doc_num,
    ])->assertOk();

    $customer->refresh();
    $account = Account::query()->findOrFail($customer->account_id);

    expect($customer->name)->toBe('After Customer')
        ->and($customer->status)->toBe('inactive')
        ->and($customer->account_group_id)->toBe($group->getKey())
        ->and($account->name)->toBe('After Customer')
        ->and($account->status)->toBe('inactive')
        ->and($account->parent_id)->toBe($group->getKey());

    salesPurchasesSelectContext($other['company'], $other['branch'], $other['period']);
    $this->postJson(route('admin.sales.customers.store'), ['name' => 'Other Company Customer', 'status' => 'active'])->assertOk();

    salesPurchasesSelectContext($context['company'], $context['branch'], $context['period']);
    $response = $this->getJson(route('admin.sales.customers.data'));

    $response->assertOk()
        ->assertJsonMissing(['name' => 'Other Company Customer']);

    $row = $response->json('data.0');
    expect($row)->not->toHaveKeys(['id', 'company_id', 'account_id', 'account_group_id']);

    $this->get(route('admin.sales.customers.edit', $customer->doc_num))
        ->assertOk()
        ->assertDontSee('name="account_id"', false)
        ->assertDontSee('name="company_id"', false);
});

test('Customer delete blocks financial movements and restore brings linked account back', function (): void {
    $context = salesPurchasesContext();
    $actor = salesPurchasesActor(['customers.create', 'customers.delete', 'customers.restore']);
    $this->actingAs($actor);

    $this->postJson(route('admin.sales.customers.store'), ['name' => 'Delete Customer', 'status' => 'active'])->assertOk();
    $customer = Customer::query()->firstOrFail();
    $account = Account::query()->findOrFail($customer->account_id);

    $this->deleteJson(route('admin.sales.customers.destroy', $customer->doc_num))->assertOk();

    expect(Customer::withTrashed()->find($customer->getKey())?->trashed())->toBeTrue()
        ->and(Account::withTrashed()->find($account->getKey())?->trashed())->toBeTrue();

    $this->patchJson(route('admin.sales.customers.restore', $customer->doc_num))->assertOk();

    expect(Customer::query()->find($customer->getKey()))->not->toBeNull()
        ->and(Account::query()->find($account->getKey()))->not->toBeNull();

    $currency = Currency::query()->where('company_id', $context['company']->getKey())->firstOrFail();
    $journalEntryId = DB::table('journal_entries')->insertGetId([
        'doc_number' => 9001,
        'doc_num' => 'JE-09001',
        'entry_date' => '2026-05-19',
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'currency_id' => $currency->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('journal_entry_lines')->insert([
        'journal_entry_id' => $journalEntryId,
        'line_no' => 1,
        'account_id' => $account->getKey(),
        'debit_amount' => 1,
        'credit_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson(route('admin.sales.customers.destroy', $customer->doc_num))
        ->assertStatus(422)
        ->assertJsonPath('message', __('customers.messages.delete_blocked_transactions'));
});

test('Supplier can be created with selected group and synchronized postable account', function (): void {
    $context = salesPurchasesContext();
    $actor = salesPurchasesActor(['suppliers.view', 'suppliers.create', 'accounts.create']);
    $this->actingAs($actor);

    $group = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::Supplier, 'Raw Material Suppliers');

    $this->postJson(route('admin.purchases.suppliers.store'), [
        'name' => 'Main Supplier',
        'status' => 'active',
        'account_group_doc_num' => $group->doc_num,
    ])->assertOk();

    $supplier = Supplier::query()->firstOrFail();
    $account = Account::query()->findOrFail($supplier->account_id);

    expect($supplier->company_id)->toBe($context['company']->getKey())
        ->and($supplier->account_group_id)->toBe($group->getKey())
        ->and($account->parent_id)->toBe($group->getKey())
        ->and($account->name)->toBe('Main Supplier')
        ->and($account->is_group)->toBeFalse()
        ->and($account->is_postable)->toBeTrue();
});

test('Supplier saves locations and multi-currency credit limits with company-scoped currencies', function (): void {
    $context = salesPurchasesContext();
    $other = salesPurchasesOtherContext(12021);
    $actor = salesPurchasesActor(['suppliers.create', 'suppliers.edit', 'suppliers.view']);
    $this->actingAs($actor);
    salesPurchasesSelectContext($context['company'], $context['branch'], $context['period']);
    $locations = salesPurchasesLocationSet(12022);
    $localCurrency = Currency::query()->where('company_id', $context['company']->getKey())->firstOrFail();
    $otherCurrency = Currency::query()->create([
        'doc_number' => 12021,
        'doc_num' => 'Currency-12021',
        'company_id' => $other['company']->getKey(),
        'name' => 'Other Supplier Currency',
        'code' => 'OSC',
        'minor_unit_name' => 'Cent',
        'minor_unit_factor' => 100,
        'status' => 'active',
    ]);

    $this->postJson(route('admin.purchases.suppliers.store'), [
        'name' => 'Credit Supplier',
        'status' => 'active',
        'country_doc_num' => $locations['country']->doc_num,
        'governorate_doc_num' => $locations['governorate']->doc_num,
        'city_doc_num' => $locations['city']->doc_num,
        'area_doc_num' => $locations['area']->doc_num,
        'credit_limits' => [
            ['currency_doc_num' => $localCurrency->doc_num, 'credit_limit' => '700'],
        ],
    ])->assertOk();

    $supplier = Supplier::query()->where('name', 'Credit Supplier')->firstOrFail();

    expect($supplier->city_id)->toBe($locations['city']->getKey())
        ->and(SupplierCreditLimit::query()->where('supplier_id', $supplier->getKey())->count())->toBe(1);

    $this->putJson(route('admin.purchases.suppliers.update', $supplier->doc_num), [
        'name' => 'Credit Supplier',
        'status' => 'active',
        'country_doc_num' => $locations['country']->doc_num,
        'governorate_doc_num' => $locations['governorate']->doc_num,
        'city_doc_num' => $locations['city']->doc_num,
        'area_doc_num' => $locations['area']->doc_num,
        'credit_limits' => [],
    ])->assertOk();

    expect(SupplierCreditLimit::query()->where('supplier_id', $supplier->getKey())->count())->toBe(0);

    $this->postJson(route('admin.purchases.suppliers.store'), [
        'name' => 'Cross Currency Supplier',
        'status' => 'active',
        'credit_limits' => [
            ['currency_doc_num' => $otherCurrency->doc_num, 'credit_limit' => '10'],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['credit_limits.0.currency_doc_num']);
});

test('Supplier accepts independent locations and supports location quick create', function (): void {
    salesPurchasesContext();
    $actor = salesPurchasesActor(['suppliers.create']);
    $this->actingAs($actor);
    $valid = salesPurchasesLocationSet(12031);
    $other = salesPurchasesLocationSet(12032);

    $this->postJson(route('admin.purchases.suppliers.store'), [
        'name' => 'Bad Location Supplier',
        'status' => 'active',
        'governorate_doc_num' => $valid['governorate']->doc_num,
        'city_doc_num' => $other['city']->doc_num,
    ])->assertOk();

    $supplier = Supplier::query()->where('name', 'Bad Location Supplier')->firstOrFail();
    expect($supplier->governorate_id)->toBe($valid['governorate']->getKey())
        ->and($supplier->city_id)->toBe($other['city']->getKey());

    $this->getJson(route('admin.select2.cities', ['q' => 'City 1203']))
        ->assertOk()
        ->assertJsonFragment(['id' => $valid['city']->doc_num])
        ->assertJsonFragment(['id' => $other['city']->doc_num]);

    $this->postJson(route('admin.select2.inline.locations.store', 'areas'), [
        'name' => 'Inline Supplier Area',
    ])->assertOk()
        ->assertJsonPath('data.option.text', 'Inline Supplier Area');
});

test('Supplier selector quick create validation list scope delete and restore behavior work', function (): void {
    $context = salesPurchasesContext();
    $other = salesPurchasesOtherContext(9933);
    $actor = salesPurchasesActor(['suppliers.view', 'suppliers.create', 'suppliers.edit', 'suppliers.delete', 'suppliers.restore', 'accounts.create']);
    $this->actingAs($actor);

    $group = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::Supplier, 'Local Suppliers');

    $this->getJson(route('admin.purchases.select2.supplier-groups', ['q' => 'Local']))
        ->assertOk()
        ->assertJsonFragment(['id' => $group->doc_num]);

    $this->postJson(route('admin.purchases.suppliers.account-groups.store'), ['name' => 'Import Suppliers'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->postJson(route('admin.purchases.suppliers.store'), ['name' => 'Before Supplier', 'status' => 'active'])->assertOk();
    $supplier = Supplier::query()->where('name', 'Before Supplier')->firstOrFail();

    $this->putJson(route('admin.purchases.suppliers.update', $supplier->doc_num), [
        'name' => 'After Supplier',
        'status' => 'inactive',
        'account_group_doc_num' => $group->doc_num,
    ])->assertOk();

    $supplier->refresh();
    $account = Account::query()->findOrFail($supplier->account_id);

    expect($account->name)->toBe('After Supplier')
        ->and($account->status)->toBe('inactive')
        ->and($account->parent_id)->toBe($group->getKey());

    salesPurchasesSelectContext($other['company'], $other['branch'], $other['period']);
    $this->postJson(route('admin.purchases.suppliers.store'), ['name' => 'Other Company Supplier', 'status' => 'active'])->assertOk();

    salesPurchasesSelectContext($context['company'], $context['branch'], $context['period']);
    $response = $this->getJson(route('admin.purchases.suppliers.data'));
    $response->assertOk()->assertJsonMissing(['name' => 'Other Company Supplier']);
    expect($response->json('data.0'))->not->toHaveKeys(['id', 'company_id', 'account_id', 'account_group_id']);

    $this->deleteJson(route('admin.purchases.suppliers.destroy', $supplier->doc_num))->assertOk();
    expect(Supplier::withTrashed()->find($supplier->getKey())?->trashed())->toBeTrue()
        ->and(Account::withTrashed()->find($account->getKey())?->trashed())->toBeTrue();

    $this->patchJson(route('admin.purchases.suppliers.restore', $supplier->doc_num))->assertOk();
    expect(Supplier::query()->find($supplier->getKey()))->not->toBeNull()
        ->and(Account::query()->find($account->getKey()))->not->toBeNull();
});

test('Supplier rejects cross-company and postable accounts as groups', function (): void {
    $context = salesPurchasesContext();
    $other = salesPurchasesOtherContext(9944);
    $actor = salesPurchasesActor(['suppliers.create']);
    $this->actingAs($actor);

    salesPurchasesSelectContext($other['company'], $other['branch'], $other['period']);
    $otherGroup = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::Supplier, 'Other Company Suppliers');

    salesPurchasesSelectContext($context['company'], $context['branch'], $context['period']);
    $root = Account::query()->where('company_id', $context['company']->getKey())->where('account_code', '2111')->firstOrFail();
    $postable = app(AccountService::class)->createChildFromParent($root, [
        'name' => 'Postable Supplier Account',
        'classification_code' => 'accounts_payable',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);

    $this->postJson(route('admin.purchases.suppliers.store'), [
        'name' => 'Cross Company Supplier',
        'status' => 'active',
        'account_group_doc_num' => $otherGroup->doc_num,
    ])->assertStatus(422)->assertJsonValidationErrors(['account_group_doc_num']);

    $this->postJson(route('admin.purchases.suppliers.store'), [
        'name' => 'Postable Group Supplier',
        'status' => 'active',
        'account_group_doc_num' => $postable->doc_num,
    ])->assertStatus(422)->assertJsonValidationErrors(['account_group_doc_num']);
});

test('Seeder creates customer and supplier roots per company without breaking BankAccount Cashbox Account roots', function (): void {
    salesPurchasesContext();
    $other = salesPurchasesOtherContext(9955);

    $this->seed(DefaultChartOfAccountsSeeder::class);
    salesPurchasesSelectContext($other['company'], $other['branch'], $other['period']);
    $this->seed(EmergencyRecoverySeeder::class);

    foreach (Company::query()->where('status', 'active')->get() as $company) {
        foreach (['1121' => 'accounts_receivable', '2111' => 'accounts_payable', '1111' => 'cash', '1112' => 'bank'] as $code => $classification) {
            $account = Account::query()->where('company_id', $company->getKey())->where('account_code', $code)->firstOrFail();

            expect($account->is_group)->toBeTrue()
                ->and($account->is_postable)->toBeFalse()
                ->and($account->status)->toBe('active')
                ->and($account->classification?->code)->toBe($classification);
        }
    }
});

test('Customer credit limit keeps maximum accepted precision before persistence', function (): void {
    $context = salesPurchasesContext();
    $actor = salesPurchasesActor(['customers.create']);
    $currency = Currency::query()->where('company_id', $context['company']->getKey())->firstOrFail();
    $capturedCreditLimit = null;

    CustomerCreditLimit::creating(function (CustomerCreditLimit $creditLimit) use (&$capturedCreditLimit): void {
        $capturedCreditLimit = $creditLimit->getAttributes()['credit_limit'] ?? null;
    });

    $this->actingAs($actor)
        ->postJson(route('admin.sales.customers.store'), [
            'name' => 'Maximum Precision Customer',
            'status' => 'active',
            'credit_limits' => [[
                'currency_doc_num' => $currency->doc_num,
                'credit_limit' => '99,999,999,999,999.9999',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($capturedCreditLimit)->toBe('99999999999999.9999');
});
