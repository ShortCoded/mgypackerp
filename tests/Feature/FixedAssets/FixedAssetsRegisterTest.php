<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ArchiveFileService;
use Modules\Core\Services\ArchiveFolderService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Services\FixedAssetDepreciationCalculator;
use Modules\FixedAssets\Services\FixedAssetImageResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function fixedAssetsActor(array $permissions): User
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
 * @return array{company: Company, branch: Branch, period: FinancialPeriod, currency: Currency}
 */
function fixedAssetsContext(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(AccountClassificationsSeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->where('status', 'active')->orderByDesc('is_main')->firstOrFail();

    fixedAssetsSelectContext($company, $branch, $period);

    return compact('company', 'branch', 'period', 'currency');
}

function fixedAssetsSelectContext(Company $company, Branch $branch, FinancialPeriod $period): void
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

function fixedAssetsPayload(array $context, array $overrides = []): array
{
    return [
        'asset_date' => $context['period']->from_date->toDateString(),
        'asset_name' => 'Production Line '.fake()->unique()->numberBetween(1000, 9999),
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'asset_group_account_doc_num' => fixedAssetsAssetCategory($context)->doc_num,
        'credit_account_doc_num' => fixedAssetsCreditAccount($context)->doc_num,
        'branch_doc_num' => $context['branch']->doc_num,
        'description' => 'Fixed asset description',
        'purchase_date' => $context['period']->from_date->toDateString(),
        'operation_date' => $context['period']->from_date->toDateString(),
        'status' => 'active',
        'currency_doc_num' => $context['currency']->doc_num,
        'exchange_rate' => '1',
        'purchase_value' => '1000',
        'salvage_value' => '0',
        'previous_depreciation' => '0',
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'useful_life' => '10',
        'is_depreciable' => '1',
        'submit_action' => 'save',
        ...$overrides,
    ];
}

function fixedAssetsArchiveFileForCompany(Company $company, UploadedFile $file): ArchiveFile
{
    $root = app(ArchiveFolderService::class)->generalRoot();

    $files = app(ArchiveFileService::class)->upload(
        files: [$file],
        attachable: $company,
        module: 'core',
        recordType: 'company',
        recordDocNum: $company->doc_num,
        folder: $root,
    );

    return $files[0];
}

function fixedAssetMainImageUsage(FixedAsset $asset): ?ArchiveFileUsage
{
    return ArchiveFileUsage::query()
        ->where('usable_type', $asset->getMorphClass())
        ->where('usable_id', $asset->getKey())
        ->where('collection', FixedAsset::ImageCollection)
        ->where('role', FixedAsset::MainImageRole)
        ->first();
}

function fixedAssetsAssetCategory(array $context): Account
{
    $root = app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset);

    $category = Account::query()
        ->where('company_id', $context['company']->getKey())
        ->where('parent_id', $root->getKey())
        ->where('status', 'active')
        ->where('is_group', true)
        ->where('is_postable', false)
        ->whereNull('deleted_at')
        ->orderBy('account_code')
        ->first();

    return $category instanceof Account
        ? $category
        : app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::FixedAsset, 'General Fixed Assets');
}

function fixedAssetsCreditAccount(array $context): Account
{
    return Account::query()
        ->where('company_id', $context['company']->getKey())
        ->where('status', 'active')
        ->where('is_group', false)
        ->where('is_postable', true)
        ->whereNull('deleted_at')
        ->orderBy('account_code')
        ->firstOrFail();
}

test('Fixed Assets appear under Accounting and Costing with permission control', function (): void {
    $this->seed(PermissionSeeder::class);

    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['currencies.view', 'fixed_assets.view', 'customers.view', 'suppliers.view', 'file_manager.view']);
    $this->actingAs($actor);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);

    $menu = app(MenuService::class)->getMenu($actor);
    $labels = array_column($menu, 'label');

    expect($labels)->toContain('accounting_costing')
        ->toContain('sales')
        ->toContain('purchases')
        ->not->toContain('finance', 'fixed_assets')
        ->and(array_search('purchases', $labels, true))->toBeLessThan(array_search('accounting_costing', $labels, true));

    $accounting = collect($menu)->firstWhere('label', 'accounting_costing');
    $fixedAssets = collect($accounting['children'])->firstWhere('label', 'fixed_assets');
    expect(json_encode($fixedAssets, JSON_THROW_ON_ERROR))->toContain('fixed_assets_register')
        ->and(app(PermissionRegistryService::class)->all())->toContain('fixed_assets.view');

    $fullyAuthorized = fixedAssetsActor([
        'fixed_assets.view',
        'fixed_assets.accounting.configure',
        'fixed_assets.depreciation.preview',
        'fixed_assets.reports',
    ]);
    $this->actingAs($fullyAuthorized);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);
    $authorizedAccounting = collect(app(MenuService::class)->getMenu($fullyAuthorized))->firstWhere('label', 'accounting_costing');
    $authorizedFixedAssets = collect($authorizedAccounting['children'])->firstWhere('label', 'fixed_assets');
    $authorizedReports = collect(app(MenuService::class)->getMenu($fullyAuthorized))->firstWhere('label', 'reports');
    $assetReports = collect($authorizedReports['children'] ?? [])->firstWhere('label', 'asset_reports');
    $canonicalChildren = collect($authorizedFixedAssets['children'] ?? [])
        ->flatMap(fn (array $group): array => empty($group['children']) ? [$group['label']] : array_column($group['children'], 'label'))
        ->all();

    expect($canonicalChildren)->toEqualCanonicalizing([
        'fixed_assets_register',
        'fixed_asset_movements',
        'fixed_asset_depreciation',
        'fixed_asset_reports',
    ])->not->toContain('asset_inspection', 'asset_documents', 'asset_insurance')
        ->and($assetReports)->toBeNull();

    $blocked = fixedAssetsActor(['customers.view']);
    $this->actingAs($blocked);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);

    expect(array_column(app(MenuService::class)->getMenu($blocked), 'label'))->not->toContain('accounting_costing');
});

test('Fixed Assets table has company and linked account fields', function (): void {
    expect(Schema::hasTable('fixed_assets'))->toBeTrue()
        ->and(Schema::hasColumns('fixed_assets', [
            'company_id',
            'account_id',
            'asset_group_account_id',
            'credit_account_id',
            'cost_center_id',
            'branch_hall_id',
            'asset_date',
            'image_path',
            'entry_type',
            'purchase_value',
            'salvage_value',
            'previous_depreciation',
            'previous_depreciation_until_date',
            'depreciation_start_date',
            'net_value',
            'depreciation_method',
            'annual_depreciation_rate',
            'expected_usage_units',
            'useful_life',
        ]))->toBeTrue();
});

test('Fixed Asset create form renders tabs and empty document number input', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.document_number.control', 'file_manager.view']);
    $this->actingAs($actor);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);

    $html = $this->get(route('admin.fixed-assets.assets.create'))
        ->assertOk()
        ->assertSee(__('fixed_assets.tabs.basic_data'))
        ->assertSee(__('fixed_assets.tabs.financial_data'))
        ->assertSee(__('fixed_assets.tabs.location_notes'))
        ->assertSee('name="doc_number"', false)
        ->getContent();

    expect($html)->toContain('id="doc_number"')
        ->toContain('value=""')
        ->toContain('data-layout-row="basic-dates"')
        ->toContain('id="entry_type"')
        ->toContain('id="purchase_date"')
        ->toContain('id="acquisition_date"')
        ->toContain('id="operation_date"')
        ->toContain('name="image_archive_file_doc_num"')
        ->toContain('id="fixed-asset-image-picker-button"')
        ->toContain('class="mb-4 col-12 fixed-asset-image-field" data-layout-row="basic-image"')
        ->toContain('id="depreciation_method"')
        ->toContain('id="salvage_value"')
        ->toContain('id="previous_depreciation_until_date"')
        ->toContain('id="expected_usage_units"')
        ->toContain('value="'.FixedAsset::DepreciationMethodStraightLine.'"')
        ->toContain('value="'.FixedAsset::DepreciationMethodDecliningBalance.'"')
        ->toContain('value="'.FixedAsset::DepreciationMethodDoubleDecliningBalance.'"')
        ->toContain('value="'.FixedAsset::DepreciationMethodSumOfYearsDigits.'"')
        ->toContain('value="'.FixedAsset::DepreciationMethodUnitsOfProduction.'"')
        ->not->toContain('value="none"')
        ->not->toContain('value="manual"')
        ->toContain('id="branch_hall_uuid"')
        ->not->toContain('name="account_id"')
        ->not->toContain('linked_account')
        ->not->toContain('Linked Account')
        ->not->toContain('الحساب المرتبط');

    $basicTab = Str::between($html, 'id="fixed-asset-basic-tab"', 'id="fixed-asset-financial-tab"');
    $locationTab = Str::after($html, 'id="fixed-asset-location-tab"');

    expect($basicTab)->not->toContain('name="branch_doc_num"')
        ->and($locationTab)->toContain('name="branch_doc_num"')
        ->toContain('name="branch_hall_uuid"')
        ->toContain('data-depends-on="#branch_doc_num"');
});

test('Fixed Asset form trims numeric values and defaults main currency exchange rate to one', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.edit']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Numeric Display Asset',
        'status' => 'draft',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '1000.5000',
        'previous_depreciation' => '100.2500',
        'previous_depreciation_until_date' => $context['period']->from_date->toDateString(),
        'exchange_rate' => '1',
        'useful_life' => '10',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Numeric Display Asset')->firstOrFail();
    $html = $this->get(route('admin.fixed-assets.assets.edit', $asset->doc_num))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('id="exchange_rate"')
        ->toContain('value="1"')
        ->toContain('value="1,000.5"')
        ->toContain('value="100.25"')
        ->toContain('value="900.25"')
        ->not->toContain('1.000000')
        ->not->toContain('1000.5000');
});

test('Fixed Asset can be created and links a postable account under Fixed Assets root', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.view']);
    $this->actingAs($actor);
    $payload = fixedAssetsPayload($context, ['asset_name' => 'Production Line Primary']);

    $response = $this->postJson(route('admin.fixed-assets.assets.store'), $payload);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('redirect', route('admin.fixed-assets.assets.show', $response->json('data.doc_num')));

    $asset = FixedAsset::query()->where('asset_name', $payload['asset_name'])->firstOrFail();
    $account = Account::query()->findOrFail($asset->account_id);
    $category = fixedAssetsAssetCategory($context);

    expect((int) $asset->company_id)->toBe((int) $context['company']->getKey())
        ->and((int) $asset->period_id)->toBe((int) $context['period']->getKey())
        ->and((int) $asset->branch_id)->toBe((int) $context['branch']->getKey())
        ->and($asset->branch_hall_id)->toBeNull()
        ->and($asset->entry_type)->toBe(FixedAsset::EntryTypeNewAsset)
        ->and((float) $asset->salvage_value)->toBe(0.0)
        ->and($asset->depreciation_method)->toBe(FixedAsset::DepreciationMethodStraightLine)
        ->and($asset->depreciation_start_date?->toDateString())->toBe($payload['operation_date'])
        ->and((float) $asset->net_value)->toBe(1000.0)
        ->and($account->is_group)->toBeFalse()
        ->and($account->is_postable)->toBeTrue()
        ->and((int) $account->company_id)->toBe((int) $context['company']->getKey())
        ->and((int) $account->parent_id)->toBe((int) $category->getKey());
});

test('Fixed Asset ignores submitted account id and keeps the linked account internal', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.view']);
    $this->actingAs($actor);
    $root = app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset);
    $category = fixedAssetsAssetCategory($context);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Internal Account Asset',
        'status' => 'draft',
        'account_id' => $root->getKey(),
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Internal Account Asset')->firstOrFail();
    $account = Account::query()->findOrFail($asset->account_id);

    expect((int) $asset->account_id)->not->toBe((int) $root->getKey())
        ->and($account->is_group)->toBeFalse()
        ->and($account->is_postable)->toBeTrue()
        ->and((int) $account->parent_id)->toBe((int) $category->getKey());

    $this->get(route('admin.fixed-assets.assets.edit', $asset->doc_num))
        ->assertOk()
        ->assertDontSee('name="account_id"', false)
        ->assertDontSee('linked_account', false)
        ->assertDontSee('Linked Account')
        ->assertDontSee('الحساب المرتبط');
});

test('Fixed Asset enforces main currency exchange rate and calculates depreciation pair server-side', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Main Currency Bad Rate',
        'exchange_rate' => '1.5',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['exchange_rate']);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Calculated Depreciation Asset',
        'useful_life' => '10',
        'annual_depreciation_rate' => null,
        'net_value' => '999999',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Calculated Depreciation Asset')->firstOrFail();

    expect((float) $asset->annual_depreciation_rate)->toBe(10.0)
        ->and((float) $asset->useful_life)->toBe(10.0)
        ->and((float) $asset->exchange_rate)->toBe(1.0)
        ->and((float) $asset->net_value)->toBe(1000.0);
});

test('Fixed Asset rejects invalid non-main exchange rate and inconsistent depreciation values', function (): void {
    $context = fixedAssetsContext();
    $nonMain = Currency::query()->create([
        'doc_number' => 90101,
        'doc_num' => 'CUR-90101',
        'company_id' => $context['company']->getKey(),
        'name' => 'US Dollar',
        'code' => 'USD',
        'is_main' => false,
        'status' => 'active',
    ]);
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Non Main Zero Rate',
        'currency_doc_num' => $nonMain->doc_num,
        'exchange_rate' => '0',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['exchange_rate']);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Bad Depreciation Pair',
        'useful_life' => '10',
        'annual_depreciation_rate' => '25',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['annual_depreciation_rate']);
});

test('Fixed Asset required fields render inline targets and validation errors', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);

    $html = $this->get(route('admin.fixed-assets.assets.create'))
        ->assertOk()
        ->getContent();

    foreach ([
        'entry_type',
        'asset_name',
        'asset_date',
        'purchase_date',
        'asset_group_account_doc_num',
        'credit_account_doc_num',
        'branch_doc_num',
        'description',
        'purchase_value',
        'salvage_value',
        'currency_doc_num',
        'exchange_rate',
        'previous_depreciation',
        'previous_depreciation_until_date',
        'depreciation_method',
        'expected_usage_units',
        'acquisition_date',
        'operation_date',
        'cost_center_doc_num',
        'useful_life',
        'image',
        'status',
    ] as $field) {
        expect($html)->toContain('data-error-for="'.$field.'"');
    }

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'entry_type' => '',
        'asset_name' => '',
        'asset_date' => '',
        'purchase_date' => '',
        'asset_group_account_doc_num' => '',
        'credit_account_doc_num' => '',
        'branch_doc_num' => '',
        'purchase_value' => '',
        'currency_doc_num' => '',
        'exchange_rate' => '',
        'is_depreciable' => '',
        'description' => '',
        'status' => '',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'asset_name',
            'entry_type',
            'asset_date',
            'purchase_date',
            'asset_group_account_doc_num',
            'credit_account_doc_num',
            'branch_doc_num',
            'purchase_value',
            'currency_doc_num',
            'exchange_rate',
            'is_depreciable',
            'description',
            'status',
        ]);
});

test('Fixed Asset date validation only period-checks document date and enforces business sequence', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);
    $outsideBefore = $context['period']->from_date->copy()->subYear()->toDateString();
    $outsideAfter = $context['period']->to_date->copy()->addYear()->toDateString();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Outside Business Dates Asset',
        'purchase_date' => $outsideBefore,
        'acquisition_date' => $outsideBefore,
        'operation_date' => $outsideAfter,
    ]))->assertOk();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Outside Document Date Asset',
        'asset_date' => $outsideBefore,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asset_date'])
        ->assertJsonMissingValidationErrors(['purchase_date', 'acquisition_date', 'operation_date']);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Bad Acquisition Date Asset',
        'purchase_date' => '2025-03-10',
        'acquisition_date' => '2025-03-09',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['acquisition_date']);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Bad Operation Purchase Date Asset',
        'purchase_date' => '2025-03-10',
        'operation_date' => '2025-03-09',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['operation_date']);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Bad Operation Acquisition Date Asset',
        'purchase_date' => '2025-03-10',
        'acquisition_date' => '2025-03-12',
        'operation_date' => '2025-03-11',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['operation_date']);
});

test('Fixed Asset opening accumulated depreciation respects depreciable base and net value is recalculated', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);

    $excessDepreciationResponse = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Excess Depreciation Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '100',
        'salvage_value' => '10',
        'previous_depreciation' => '91',
        'previous_depreciation_until_date' => $context['period']->from_date->toDateString(),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['previous_depreciation']);

    expect($excessDepreciationResponse->json('errors.previous_depreciation'))
        ->toContain(__('fixed_assets.messages.previous_depreciation_exceeds_depreciable_base'))
        ->toContain(__('fixed_assets.messages.previous_depreciation_plus_salvage_exceeds_purchase_value'));

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Fully Depreciated To Salvage Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '100',
        'salvage_value' => '10',
        'previous_depreciation' => '90',
        'previous_depreciation_until_date' => $context['period']->from_date->toDateString(),
    ]))->assertOk();

    $fullyDepreciatedAsset = FixedAsset::query()->where('asset_name', 'Fully Depreciated To Salvage Asset')->firstOrFail();

    expect((float) $fullyDepreciatedAsset->net_value)->toBe(10.0);

    $negativePreviousResponse = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Negative Opening Depreciation Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'previous_depreciation' => '-1',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['previous_depreciation']);

    expect($negativePreviousResponse->json('errors.previous_depreciation'))
        ->toContain(__('fixed_assets.messages.previous_depreciation_negative'));

    $missingPurchaseResponse = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Positive Depreciation Blank Purchase Asset',
        'purchase_value' => '',
        'previous_depreciation' => '1',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['purchase_value', 'previous_depreciation']);

    expect($missingPurchaseResponse->json('errors.purchase_value'))
        ->toContain(__('fixed_assets.messages.purchase_value_required'));

    $zeroPurchaseResponse = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Zero Purchase Asset',
        'purchase_value' => '0',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['purchase_value']);

    expect($zeroPurchaseResponse->json('errors.purchase_value'))
        ->toContain(__('fixed_assets.messages.purchase_value_gt_zero'));

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Recalculated Net Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '300',
        'previous_depreciation' => '25',
        'previous_depreciation_until_date' => $context['period']->from_date->toDateString(),
        'net_value' => '999999',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Recalculated Net Asset')->firstOrFail();

    expect((float) $asset->net_value)->toBe(275.0);
});

test('Fixed Asset reporting fields validate and calculate depreciation readiness values', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Opening Missing Previous Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'previous_depreciation' => '',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['previous_depreciation']);

    $missingUntilResponse = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Opening Missing Until Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'previous_depreciation' => '125',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['previous_depreciation_until_date']);

    expect($missingUntilResponse->json('errors.previous_depreciation_until_date'))
        ->toContain(__('fixed_assets.messages.previous_depreciation_until_required'));

    $negativeSalvageResponse = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Negative Salvage Asset',
        'salvage_value' => '-1',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['salvage_value']);

    expect($negativeSalvageResponse->json('errors.salvage_value'))
        ->toContain(__('fixed_assets.messages.salvage_value_negative'));

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Fully Residual Asset',
        'purchase_value' => '100',
        'salvage_value' => '100',
    ]))->assertOk();

    expect((float) FixedAsset::query()->where('asset_name', 'Fully Residual Asset')->firstOrFail()->net_value)->toBe(100.0);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Missing Operation Date Asset',
        'operation_date' => '',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['operation_date']);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Missing Useful Life Asset',
        'useful_life' => '',
        'annual_depreciation_rate' => '',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['useful_life']);

    $previousUntil = Carbon::parse($context['period']->from_date)->addDays(10)->toDateString();
    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Opening Depreciation Start Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'previous_depreciation' => '125',
        'previous_depreciation_until_date' => $previousUntil,
    ]))->assertOk();

    $openingAsset = FixedAsset::query()->where('asset_name', 'Opening Depreciation Start Asset')->firstOrFail();

    expect($openingAsset->depreciation_start_date?->toDateString())->toBe($openingAsset->operation_date->toDateString())
        ->and((float) $openingAsset->net_value)->toBe(875.0);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Blank Method Asset',
        'depreciation_method' => '',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['depreciation_method']);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Blank Salvage Asset',
        'salvage_value' => '',
    ]))->assertOk();

    $blankSalvageAsset = FixedAsset::query()->where('asset_name', 'Blank Salvage Asset')->firstOrFail();

    expect($blankSalvageAsset->depreciation_method)->toBe(FixedAsset::DepreciationMethodStraightLine)
        ->and((float) $blankSalvageAsset->salvage_value)->toBe(0.0);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Rate Calculates Life Asset',
        'useful_life' => '',
        'annual_depreciation_rate' => '20',
    ]))->assertOk();

    $rateAsset = FixedAsset::query()->where('asset_name', 'Rate Calculates Life Asset')->firstOrFail();

    expect((float) $rateAsset->useful_life)->toBe(5.0)
        ->and((float) $rateAsset->annual_depreciation_rate)->toBe(20.0);
});

test('Fixed Asset depreciation methods validate method-specific fields and clear non-depreciable setup', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Non Depreciable Setup Asset',
        'is_depreciable' => '0',
        'operation_date' => '',
        'depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction,
        'salvage_value' => '100',
        'previous_depreciation' => '0',
        'previous_depreciation_until_date' => null,
        'useful_life' => '7',
        'annual_depreciation_rate' => '12',
        'expected_usage_units' => '5000',
    ]))->assertOk();

    $nonDepreciableAsset = FixedAsset::query()->where('asset_name', 'Non Depreciable Setup Asset')->firstOrFail();

    expect($nonDepreciableAsset->is_depreciable)->toBeFalse()
        ->and($nonDepreciableAsset->depreciation_method)->toBeNull()
        ->and($nonDepreciableAsset->depreciation_start_date)->toBeNull()
        ->and($nonDepreciableAsset->useful_life)->toBeNull()
        ->and($nonDepreciableAsset->annual_depreciation_rate)->toBeNull()
        ->and($nonDepreciableAsset->expected_usage_units)->toBeNull()
        ->and((float) $nonDepreciableAsset->salvage_value)->toBe(0.0)
        ->and((float) $nonDepreciableAsset->previous_depreciation)->toBe(0.0)
        ->and((float) $nonDepreciableAsset->net_value)->toBe(1000.0);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Declining Missing Rate Asset',
        'depreciation_method' => FixedAsset::DepreciationMethodDecliningBalance,
        'annual_depreciation_rate' => '',
        'useful_life' => '',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['annual_depreciation_rate']);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Declining Balance Asset',
        'depreciation_method' => FixedAsset::DepreciationMethodDecliningBalance,
        'annual_depreciation_rate' => '15',
        'useful_life' => '10',
        'expected_usage_units' => '1000',
    ]))->assertOk();

    $decliningAsset = FixedAsset::query()->where('asset_name', 'Declining Balance Asset')->firstOrFail();

    expect($decliningAsset->depreciation_method)->toBe(FixedAsset::DepreciationMethodDecliningBalance)
        ->and((float) $decliningAsset->annual_depreciation_rate)->toBe(15.0)
        ->and($decliningAsset->useful_life)->toBeNull()
        ->and($decliningAsset->expected_usage_units)->toBeNull();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Double Declining Asset',
        'depreciation_method' => FixedAsset::DepreciationMethodDoubleDecliningBalance,
        'annual_depreciation_rate' => '',
        'useful_life' => '5',
    ]))->assertOk();

    $doubleDecliningAsset = FixedAsset::query()->where('asset_name', 'Double Declining Asset')->firstOrFail();

    expect($doubleDecliningAsset->depreciation_method)->toBe(FixedAsset::DepreciationMethodDoubleDecliningBalance)
        ->and((float) $doubleDecliningAsset->useful_life)->toBe(5.0)
        ->and((float) $doubleDecliningAsset->annual_depreciation_rate)->toBe(40.0);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Sum Years Asset',
        'depreciation_method' => FixedAsset::DepreciationMethodSumOfYearsDigits,
        'annual_depreciation_rate' => '',
        'useful_life' => '5',
    ]))->assertOk();

    $sumYearsAsset = FixedAsset::query()->where('asset_name', 'Sum Years Asset')->firstOrFail();

    expect($sumYearsAsset->depreciation_method)->toBe(FixedAsset::DepreciationMethodSumOfYearsDigits)
        ->and((float) $sumYearsAsset->useful_life)->toBe(5.0)
        ->and($sumYearsAsset->annual_depreciation_rate)->toBeNull();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Units Missing Expected Usage Asset',
        'depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction,
        'useful_life' => '',
        'annual_depreciation_rate' => '',
        'expected_usage_units' => '',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expected_usage_units']);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Units Production Asset',
        'depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction,
        'useful_life' => '7',
        'annual_depreciation_rate' => '12',
        'expected_usage_units' => '5000',
    ]))->assertOk();

    $unitsAsset = FixedAsset::query()->where('asset_name', 'Units Production Asset')->firstOrFail();

    expect($unitsAsset->depreciation_method)->toBe(FixedAsset::DepreciationMethodUnitsOfProduction)
        ->and((float) $unitsAsset->expected_usage_units)->toBe(5000.0)
        ->and($unitsAsset->useful_life)->toBeNull()
        ->and($unitsAsset->annual_depreciation_rate)->toBeNull();
});

test('Fixed Asset depreciation calculator supports daily straight-line report foundation', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Calculator Asset',
        'purchase_value' => '3650',
        'salvage_value' => '0',
        'useful_life' => '10',
        'operation_date' => $context['period']->from_date->toDateString(),
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Calculator Asset')->firstOrFail();
    $calculator = app(FixedAssetDepreciationCalculator::class);

    expect($calculator->calculateForPeriod(
        $asset,
        Carbon::parse($context['period']->from_date)->subMonth(),
        Carbon::parse($context['period']->from_date)->subDay(),
    ))->toBe(0.0)
        ->and($calculator->calculateForPeriod(
            $asset,
            Carbon::parse($context['period']->from_date),
            Carbon::parse($context['period']->from_date)->addDays(9),
        ))->toBe(10.0);

    $asset->forceFill([
        'purchase_value' => '100.0000',
        'salvage_value' => '90.0000',
        'previous_depreciation' => '9.0000',
        'useful_life' => '1.00',
    ]);

    expect($calculator->calculateForPeriod(
        $asset,
        Carbon::parse($context['period']->from_date),
        Carbon::parse($context['period']->from_date)->addYear(),
    ))->toBe(1.0);

    $asset->forceFill(['is_depreciable' => false]);

    expect($calculator->calculateForPeriod(
        $asset,
        Carbon::parse($context['period']->from_date),
        Carbon::parse($context['period']->from_date)->addMonth(),
    ))->toBe(0.0);

    $asset->forceFill([
        'is_depreciable' => true,
        'purchase_value' => '1000.0000',
        'salvage_value' => '100.0000',
        'previous_depreciation' => '0.0000',
        'depreciation_method' => FixedAsset::DepreciationMethodDecliningBalance,
        'annual_depreciation_rate' => '10.0000',
    ]);

    expect($calculator->theoreticalAnnualDepreciation($asset))->toBe(100.0);

    $asset->forceFill([
        'depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction,
        'annual_depreciation_rate' => null,
        'expected_usage_units' => '900.0000',
    ]);

    expect($calculator->theoreticalAnnualDepreciation($asset))->toBeNull()
        ->and($calculator->depreciationPerUsageUnit($asset))->toBe(1.0);
});

test('Fixed Asset branch halls selector is scoped by selected branch and validation rejects mismatches', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.view']);
    $this->actingAs($actor);

    $context['branch']->forceFill(['type' => Branch::TypeFactory])->save();
    $hall = $context['branch']->halls()->create(['name' => 'Assembly Hall', 'position' => 1]);
    $otherBranch = Branch::query()->create([
        'doc_number' => 99101,
        'doc_num' => 'Branch-99101',
        'company_id' => $context['company']->getKey(),
        'name' => 'Other Factory',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $otherHall = $otherBranch->halls()->create(['name' => 'Other Hall', 'position' => 1]);

    $selectorPayload = $this->getJson(route('admin.fixed-assets.select2.branch-halls', [
        'branch_doc_num' => $context['branch']->doc_num,
    ]))
        ->assertOk()
        ->json();

    expect(collect($selectorPayload['results'])->pluck('id')->all())->toContain($hall->public_uuid)
        ->not->toContain($otherHall->public_uuid)
        ->and($selectorPayload['results'][0]['id'])->toBe($hall->public_uuid)
        ->and(array_keys($selectorPayload['results'][0]))->not->toContain('branch_id')
        ->not->toContain('internal_id');

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Hall Asset',
        'branch_hall_uuid' => $hall->public_uuid,
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Hall Asset')->firstOrFail();

    expect((int) $asset->branch_hall_id)->toBe((int) $hall->getKey());

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Wrong Hall Asset',
        'branch_hall_uuid' => $otherHall->public_uuid,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_hall_uuid']);
});

test('Fixed Asset form JavaScript clears inline errors for inputs dates and Select2 controls', function (): void {
    $javascript = file_get_contents(base_path('public/assets/js/modules/FixedAssets/fixed-assets.js'));

    expect($javascript)
        ->toContain('clearFieldError')
        ->toContain('select2Selection')
        ->toContain('input.fixedAssetsFieldError change.fixedAssetsFieldError')
        ->toContain('select2:select.fixedAssetsFieldError select2:clear.fixedAssetsFieldError')
        ->toContain('change.fixedAssetsSelect2Dependency.')
        ->toContain('data[dependentParam]')
        ->toContain('toggleDepreciationFields')
        ->toContain('togglePreviousDepreciationDateRequirement')
        ->toContain('handleAssetImagePickerSelection')
        ->toContain('fixed_asset_image')
        ->toContain('js-fixed-asset-method-field-container')
        ->toContain('$rate.val(trimNumber(100 / usefulLife, 4))')
        ->toContain('$usefulLife.val(trimNumber(100 / rate, 2))')
        ->not->toContain('$rate.val(trimNumber(100 / usefulLife, 6))')
        ->not->toContain('$usefulLife.val(trimNumber(100 / rate, 6))');
});

test('Fixed Asset category quick-create and selector stay under the Fixed Assets root', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'accounts.create']);
    $this->actingAs($actor);

    $response = $this->postJson(route('admin.fixed-assets.assets.asset-categories.store'), [
        'name' => 'Vehicles',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $category = Account::query()->where('doc_num', $response->json('data.option.id'))->firstOrFail();
    $root = app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset);

    expect($category->is_group)->toBeTrue()
        ->and($category->is_postable)->toBeFalse()
        ->and((int) $category->parent_id)->toBe((int) $root->getKey());

    $selector = $this->getJson(route('admin.fixed-assets.select2.asset-categories', ['q' => 'Vehicles']));
    $selector->assertOk();

    expect(collect($selector->json('results'))->pluck('id')->all())->toContain($category->doc_num);
});

test('Fixed Assets resolves its generic classification by stable code across modal selectors and account creation', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $this->seed(AccountClassificationsSeeder::class);

    $originalClassification = AccountClassification::query()
        ->where('code', AccountClassification::FixedAssets)
        ->firstOrFail();
    $classificationCount = AccountClassification::query()->count();
    $classificationId = (int) AccountClassification::query()->max('id') + 1000;

    DB::table($originalClassification->getTable())
        ->where($originalClassification->getKeyName(), $originalClassification->getKey())
        ->update(['id' => $classificationId]);

    $this->seed(DefaultChartOfAccountsSeeder::class);

    DB::table($originalClassification->getTable())
        ->where($originalClassification->getKeyName(), $classificationId)
        ->update([
            'name' => 'تسمية يجب تجاهلها',
            'name_en' => 'Label That Must Be Ignored',
        ]);

    $this->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->where('status', 'active')->orderByDesc('is_main')->firstOrFail();
    $context = compact('company', 'branch', 'period', 'currency');
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.view', 'accounts.create', 'accounts.view']);
    $this->actingAs($actor);
    fixedAssetsSelectContext($company, $branch, $period);

    $this->get(route('admin.fixed-assets.assets.create'))
        ->assertOk()
        ->assertSee(route('admin.fixed-assets.assets.asset-categories.store'), false);

    $categoryResponse = $this->postJson(route('admin.fixed-assets.assets.asset-categories.store'), [
        'name' => 'Code-resolved category',
    ])->assertOk()->assertJsonPath('success', true);
    $category = Account::query()->where('doc_num', $categoryResponse->json('data.option.id'))->firstOrFail();

    $selector = $this->getJson(route('admin.fixed-assets.select2.asset-categories', [
        'q' => 'Code-resolved category',
    ]))->assertOk();
    $root = app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset);
    $accountSelector = $this->getJson(route('admin.accounting.select2.accounts', [
        'classification' => AccountClassification::FixedAssets,
        'parent' => $root->doc_num,
        'group' => true,
        'q' => 'Code-resolved category',
    ]))->assertOk();
    $classificationSelector = $this->getJson(route('admin.accounting.select2.account-classifications', [
        'q' => AccountClassification::FixedAssets,
    ]))->assertOk();

    $assetResponse = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Code-resolved asset',
        'asset_group_account_doc_num' => $category->doc_num,
    ]))->assertOk()->assertJsonPath('success', true);
    $asset = FixedAsset::query()->where('doc_num', $assetResponse->json('data.doc_num'))->firstOrFail();
    $linkedAccount = Account::query()->findOrFail($asset->account_id);

    $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetsPayload($context, [
        'asset_name' => 'Code-resolved asset updated',
        'asset_group_account_doc_num' => $category->doc_num,
    ]))->assertOk()->assertJsonPath('success', true);

    expect($classificationId)->not->toBe((int) $originalClassification->getKey())
        ->and(AccountClassification::query()->where('code', AccountClassification::FixedAssets)->count())->toBe(1)
        ->and(AccountClassification::query()->count())->toBe($classificationCount)
        ->and((int) $category->account_classification_id)->toBe($classificationId)
        ->and((int) $linkedAccount->fresh()->account_classification_id)->toBe($classificationId)
        ->and((int) $category->fresh()->account_classification_id)->toBe($classificationId)
        ->and(collect($selector->json('results'))->pluck('id')->all())->toContain($category->doc_num)
        ->and(collect($accountSelector->json('results'))->pluck('id')->all())->toContain($category->doc_num)
        ->and(collect($classificationSelector->json('results'))->pluck('id')->all())->toContain(AccountClassification::FixedAssets);
});

test('Fixed Asset category creation never inserts a replacement classification when the canonical code is unavailable', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'accounts.create']);
    $this->actingAs($actor);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);

    $classification = AccountClassification::query()
        ->where('code', AccountClassification::FixedAssets)
        ->firstOrFail();
    $classificationId = (int) $classification->getKey();
    $accountCount = Account::withTrashed()->count();
    $classification->delete();

    $this->postJson(route('admin.fixed-assets.assets.asset-categories.store'), [
        'name' => 'Must not be created',
    ])->assertUnprocessable();

    expect(AccountClassification::withTrashed()->where('code', AccountClassification::FixedAssets)->count())->toBe(1)
        ->and((int) AccountClassification::withTrashed()->where('code', AccountClassification::FixedAssets)->value('id'))->toBe($classificationId)
        ->and(AccountClassification::query()->where('code', AccountClassification::FixedAssets)->exists())->toBeFalse()
        ->and(Account::withTrashed()->count())->toBe($accountCount);
});

test('Fixed Asset category allocation skips company-wide active and historical account code collisions', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'accounts.create']);
    $this->actingAs($actor);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);

    $root = app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset);
    $prefix = (string) $root->account_code;
    $nextSiblingSuffix = Account::query()
        ->withTrashed()
        ->where('company_id', $context['company']->getKey())
        ->where('parent_id', $root->getKey())
        ->pluck('account_code')
        ->map(function (string $accountCode) use ($prefix): ?int {
            $suffix = substr($accountCode, strlen($prefix));

            return $suffix !== '' && ctype_digit($suffix) ? (int) $suffix : null;
        })
        ->filter(fn (?int $suffix): bool => $suffix !== null)
        ->max() + 1;
    $activeCollisionCode = $prefix.$nextSiblingSuffix;
    $historicalCollisionCode = $prefix.($nextSiblingSuffix + 1);
    $outsideParent = Account::query()
        ->where('company_id', $context['company']->getKey())
        ->whereNull('parent_id')
        ->whereKeyNot($root->getKey())
        ->firstOrFail();
    $accountValues = [
        'company_id' => $context['company']->getKey(),
        'parent_id' => $outsideParent->getKey(),
        'level' => (int) $outsideParent->level + 1,
        'account_classification_id' => $root->account_classification_id,
        'account_type' => $root->account_type,
        'statement_type' => $root->statement_type,
        'normal_balance' => $root->normal_balance,
        'is_group' => true,
        'is_postable' => false,
        'status' => 'active',
    ];

    Account::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $context['company']->getKey()),
        ...$accountValues,
        'account_code' => $activeCollisionCode,
        'name' => 'Active company-wide collision',
    ]);
    $historicalCollision = Account::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $context['company']->getKey()),
        ...$accountValues,
        'account_code' => $historicalCollisionCode,
        'name' => 'Historical company-wide collision',
    ]);
    $historicalCollision->delete();

    $response = $this->postJson(route('admin.fixed-assets.assets.asset-categories.store'), [
        'name' => 'Collision-safe category',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $category = Account::query()->where('doc_num', $response->json('data.option.id'))->firstOrFail();

    expect($category->account_code)
        ->toBe($prefix.($nextSiblingSuffix + 2))
        ->not->toBe($activeCollisionCode, $historicalCollisionCode)
        ->and(Account::query()->where('company_id', $context['company']->getKey())->where('account_code', $category->account_code)->count())
        ->toBe(1);
});

test('Fixed Asset category duplicate name returns a localized field error without a partial account', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'accounts.create']);
    $this->actingAs($actor);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);

    $url = route('admin.fixed-assets.assets.asset-categories.store');
    $this->postJson($url, ['name' => 'Duplicate category'])->assertOk();
    $root = app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset);
    $countBefore = Account::query()
        ->where('company_id', $context['company']->getKey())
        ->where('parent_id', $root->getKey())
        ->count();

    $response = $this->withSession(['locale' => 'en'])->postJson($url, ['name' => 'Duplicate category']);

    $response
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'validation_failed')
        ->assertJsonPath('errors.name.0', 'This name already exists.');

    expect(Account::query()
        ->where('company_id', $context['company']->getKey())
        ->where('parent_id', $root->getKey())
        ->count())->toBe($countBefore);
});

test('Fixed Asset creation rolls back its linked account when master creation fails', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);

    $payload = fixedAssetsPayload($context, ['asset_name' => 'Atomic rollback asset']);
    $accountCountBefore = Account::withTrashed()
        ->where('company_id', $context['company']->getKey())
        ->count();
    $eventName = 'eloquent.creating: '.FixedAsset::class;

    Event::listen($eventName, function (): never {
        throw new RuntimeException('simulated master failure after linked account allocation');
    });

    try {
        $response = $this->postJson(
            route('admin.fixed-assets.assets.store'),
            $payload,
        );
    } finally {
        Event::forget($eventName);
    }

    $response
        ->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', 'internal_error')
        ->assertJsonStructure(['correlation_id']);

    expect(FixedAsset::query()->where('asset_name', 'Atomic rollback asset')->exists())->toBeFalse()
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->count())->toBe($accountCountBefore);
});

test('Fixed Asset categories exclude same-company groups outside the canonical root and foundation checks are write free', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.view', 'fixed_assets.accounting.configure', 'accounts.create']);
    $this->actingAs($actor);
    $accounts = app(BusinessPartnerAccountService::class);
    $accounts->ensureFixedAssetBaselineForCompany((int) $context['company']->getKey());
    $categoryResponse = $this->postJson(route('admin.fixed-assets.assets.asset-categories.store'), [
        'name' => 'Explicit mapped category',
    ])->assertOk();
    $category = Account::query()->where('doc_num', $categoryResponse->json('data.option.id'))->firstOrFail();
    $postingAccounts = Account::query()
        ->where('company_id', $context['company']->getKey())
        ->where('status', 'active')
        ->where('is_group', false)
        ->where('is_postable', true)
        ->orderBy('account_code')
        ->take(5)
        ->get();
    foreach (['accumulated_depreciation', 'depreciation_expense', 'gain_on_asset_disposal', 'loss_on_asset_disposal'] as $index => $code) {
        $classification = AccountClassification::query()->where('code', $code)->firstOrFail();
        $parent = Account::query()->where('company_id', $context['company']->getKey())->where('account_classification_id', $classification->getKey())->where('is_group', true)->first();
        $postingAccounts[$index] = Account::query()->create([
            'company_id' => $context['company']->getKey(), 'doc_number' => 98000 + $index, 'doc_num' => 'ACC-9800'.$index,
            'account_code' => '9800'.$index, 'name' => $code, 'parent_id' => $parent?->getKey(),
            'account_classification_id' => $classification->getKey(), 'account_type' => $classification->account_type,
            'statement_type' => $classification->statement_type, 'normal_balance' => $classification->normal_balance,
            'is_group' => false, 'is_postable' => true, 'status' => 'active',
        ]);
    }

    $this->from(route('admin.fixed-assets.accounting.index'))
        ->post(route('admin.fixed-assets.accounting.store'), [
            'asset_group_account_doc_num' => $category->doc_num,
            'accumulated_depreciation_account_doc_num' => $postingAccounts[0]->doc_num,
            'depreciation_expense_account_doc_num' => $postingAccounts[1]->doc_num,
            'disposal_gain_account_doc_num' => $postingAccounts[2]->doc_num,
            'disposal_loss_account_doc_num' => $postingAccounts[3]->doc_num,
            'disposal_clearing_account_doc_num' => $postingAccounts[4]->doc_num,
        ])
        ->assertRedirect(route('admin.fixed-assets.accounting.index'))
        ->assertSessionHasNoErrors();

    expect(FixedAssetCategoryMapping::query()
        ->where('company_id', $context['company']->getKey())
        ->where('asset_group_account_id', $category->getKey())
        ->exists())->toBeTrue();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Baseline Mapped Asset',
        'asset_group_account_doc_num' => $category->doc_num,
    ]))->assertOk()->assertJsonPath('success', true);
    $asset = FixedAsset::query()->where('asset_name', 'Baseline Mapped Asset')->firstOrFail();

    $this->get(route('admin.fixed-assets.assets.show', $asset->doc_num))
        ->assertOk()
        ->assertSee('Baseline Mapped Asset');

    $root = $accounts->rootAccount(BusinessPartnerAccountService::FixedAsset);
    $baselineCount = Account::query()
        ->where('company_id', $context['company']->getKey())
        ->where('parent_id', $root->getKey())
        ->where('is_group', true)
        ->where('is_postable', false)
        ->count();
    $accounts->ensureFixedAssetBaselineForCompany((int) $context['company']->getKey());

    $rogue = Account::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $context['company']->getKey()),
        'company_id' => $context['company']->getKey(),
        'account_code' => '19991',
        'name' => 'تصنيف أصل خارج الجذر',
        'name_en' => 'Rogue Asset Category',
        'parent_id' => null,
        'level' => 1,
        'account_classification_id' => $root->account_classification_id,
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => true,
        'is_postable' => false,
        'status' => 'active',
    ]);

    $selector = $this->getJson(route('admin.fixed-assets.select2.asset-categories', ['q' => 'Rogue']))->assertOk();

    expect(Account::query()->where('company_id', $context['company']->getKey())->where('parent_id', $root->getKey())->where('is_group', true)->where('is_postable', false)->count())->toBe($baselineCount)
        ->and(collect($selector->json('results'))->pluck('id'))->not->toContain($rogue->doc_num);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_group_account_doc_num' => $rogue->doc_num,
    ]))->assertUnprocessable()->assertJsonValidationErrors('asset_group_account_doc_num');
});

test('Fixed Asset rejects cross-company related records and outside-period dates', function (): void {
    $context = fixedAssetsContext();
    $other = Company::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'Company-99001',
        'name' => 'Other Fixed Asset Company',
        'status' => 'active',
    ]);
    $otherBranch = Branch::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'Branch-99001',
        'company_id' => $other->getKey(),
        'name' => 'Other Branch',
        'type' => 'main',
        'status' => 'active',
    ]);
    FinancialPeriod::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'Period-99001',
        'company_id' => $other->getKey(),
        'name' => 'Other FY',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $otherCurrency = Currency::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'CUR-99001',
        'company_id' => $other->getKey(),
        'name' => 'Other Pound',
        'code' => 'OEGP',
        'status' => 'active',
    ]);

    $actor = fixedAssetsActor(['fixed_assets.create']);
    $this->actingAs($actor);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);

    $response = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_date' => $context['period']->to_date->copy()->addDay()->toDateString(),
        'branch_doc_num' => $otherBranch->doc_num,
        'currency_doc_num' => $otherCurrency->doc_num,
    ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['asset_date', 'branch_doc_num', 'currency_doc_num']);
});

test('Fixed Asset update syncs linked account name', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.view']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, ['asset_name' => 'Old Asset Name']))->assertOk();
    $asset = FixedAsset::query()->where('asset_name', 'Old Asset Name')->firstOrFail();

    $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetsPayload($context, [
        'asset_name' => 'New Asset Name',
        'currency_doc_num' => $context['currency']->doc_num,
    ]))->assertOk();

    expect($asset->refresh()->asset_name)->toBe('New Asset Name')
        ->and($asset->account()->firstOrFail()->name)->toBe('New Asset Name');
});

test('Fixed Asset image picker stores serves previews and removes archive image usage', function (): void {
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.view', 'file_manager.view']);
    $this->actingAs($actor);
    $file = fixedAssetsArchiveFileForCompany($context['company'], UploadedFile::fake()->image('fixed-asset.jpg')->size(64));

    $response = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
        'asset_name' => 'Asset With Image',
        'status' => 'draft',
        'image_archive_file_doc_num' => $file->doc_num,
    ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $asset = FixedAsset::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $usage = fixedAssetMainImageUsage($asset);

    expect($asset->image_path)->toBe($file->path)
        ->and($usage)->not->toBeNull()
        ->and($usage?->archive_file_id)->toBe($file->getKey())
        ->and($usage?->collection)->toBe(FixedAsset::ImageCollection)
        ->and($usage?->role)->toBe(FixedAsset::MainImageRole)
        ->and(app(FixedAssetImageResolver::class)->url($asset->fresh()))->toBe(route('admin.fixed-assets.assets.image', $asset->doc_num))
        ->and($response->json('data.image_url'))->toBe(route('admin.fixed-assets.assets.image', $asset->doc_num));

    $this->get(route('admin.fixed-assets.assets.image', $asset->doc_num))
        ->assertOk();

    $this->get(route('admin.fixed-assets.assets.edit', $asset->doc_num))
        ->assertOk()
        ->assertSee(route('admin.fixed-assets.assets.image', $asset->doc_num), false)
        ->assertSee('js-fixed-asset-image-picker-field', false);

    $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetsPayload($context, [
        'asset_name' => 'Asset With Image',
        'status' => 'draft',
        'remove_image' => '1',
    ]))
        ->assertOk()
        ->assertJsonPath('data.image_url', null);

    expect($asset->refresh()->image_path)->toBeNull()
        ->and(fixedAssetMainImageUsage($asset))->toBeNull();
});

test('Fixed Asset category changes reparent the internal linked account', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.view', 'accounts.create']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, ['asset_name' => 'Asset To Reparent']))->assertOk();
    $asset = FixedAsset::query()->where('asset_name', 'Asset To Reparent')->firstOrFail();
    $category = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::FixedAsset, 'Machinery Assets');

    $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetsPayload($context, [
        'asset_name' => 'Asset To Reparent',
        'asset_group_account_doc_num' => $category->doc_num,
    ]))->assertOk();

    $asset->refresh();
    $account = Account::query()->findOrFail($asset->account_id);

    expect((int) $asset->asset_group_account_id)->toBe((int) $category->getKey())
        ->and((int) $account->parent_id)->toBe((int) $category->getKey());
});

test('Fixed Asset delete and restore synchronize the internal linked account and block financial movements', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.delete', 'fixed_assets.restore']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, ['asset_name' => 'Asset Delete Restore']))->assertOk();
    $asset = FixedAsset::query()->where('asset_name', 'Asset Delete Restore')->firstOrFail();
    $account = Account::query()->findOrFail($asset->account_id);

    $this->deleteJson(route('admin.fixed-assets.assets.destroy', $asset->doc_num))->assertOk();

    expect(FixedAsset::withTrashed()->find($asset->getKey())?->trashed())->toBeTrue()
        ->and(Account::withTrashed()->find($account->getKey())?->trashed())->toBeTrue();

    $this->patchJson(route('admin.fixed-assets.assets.restore', $asset->doc_num))->assertOk();

    expect(FixedAsset::query()->find($asset->getKey()))->not->toBeNull()
        ->and(Account::query()->find($account->getKey()))->not->toBeNull();

    $journalEntryId = DB::table('journal_entries')->insertGetId([
        'doc_number' => 98101,
        'doc_num' => 'JE-98101',
        'entry_date' => $context['period']->from_date->toDateString(),
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'currency_id' => $context['currency']->getKey(),
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

    $this->deleteJson(route('admin.fixed-assets.assets.destroy', $asset->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'record_in_use')
        ->assertJsonPath('message', __('fixed_assets.messages.delete_blocked_transactions'));
});

test('Fixed Asset view page does not show a separate linked account field', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.view']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, ['asset_name' => 'View Asset Name']))->assertOk();
    $asset = FixedAsset::query()->where('asset_name', 'View Asset Name')->firstOrFail();

    $this->get(route('admin.fixed-assets.assets.show', $asset->doc_num))
        ->assertOk()
        ->assertSee('View Asset Name')
        ->assertSee(__('fixed_assets.attributes.asset_group_account'))
        ->assertSee(__('fixed_assets.attributes.entry_type'))
        ->assertDontSee(__('fixed_assets.attributes.previous_depreciation'))
        ->assertSee(__('fixed_assets.reports.columns.accumulated_depreciation'))
        ->assertSee(__('fixed_assets.attributes.depreciation_start_date'))
        ->assertDontSee('linked_account', false)
        ->assertDontSee('Linked Account')
        ->assertDontSee('الحساب المرتبط')
        ->assertDontSee('name="account_id"', false);
});

test('Fixed Assets DataTable is company-scoped and returns columns configured by JS', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create', 'fixed_assets.view']);
    $this->actingAs($actor);

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, ['asset_name' => 'Visible Asset']))->assertOk();

    $response = $this->getJson(route('admin.fixed-assets.assets.data', [
        'draw' => 1,
        'columns' => [
            ['data' => 'checkbox', 'name' => 'checkbox', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => null, 'regex' => 'false']],
            ['data' => 'doc_num', 'name' => 'fixed_assets.doc_number', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => null, 'regex' => 'false']],
        ],
        'order' => [['column' => 1, 'dir' => 'desc']],
        'start' => 0,
        'length' => 10,
        'search' => ['value' => '', 'regex' => 'false'],
    ]));

    $response->assertOk();
    $row = $response->json('data.0');

    foreach (['checkbox', 'doc_num', 'asset_name', 'entry_type', 'asset_category', 'branch', 'cost_center', 'purchase_value', 'currency', 'previous_depreciation', 'net_value', 'is_depreciable', 'status', 'created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_by', 'deleted_at', 'actions'] as $key) {
        expect($row)->toHaveKey($key);
    }

    expect($row)->not->toHaveKeys(['account', 'account_id', 'account_code', 'account_label', 'account_label_en', 'image_path', 'depreciation_method', 'depreciation_start_date', 'expected_usage_units'])
        ->and($row['currency'])->toContain($context['currency']->code)
        ->not->toContain('<span')
        ->not->toContain('&lt;span')
        ->and($row['purchase_value'])->toBe('1,000 '.$context['currency']->code)
        ->and($row['previous_depreciation'])->toBe('0 '.$context['currency']->code)
        ->and($row['net_value'])->toBe('1,000 '.$context['currency']->code)
        ->and($row['purchase_value'])->not->toContain('<')
        ->and($row['net_value'])->not->toContain('<')
        ->and(strip_tags($row['created_by']))->toContain($actor->name)
        ->and(strip_tags($row['created_by']))->not->toBe((string) $actor->getKey());
});

test('Fixed Assets index does not include a visible linked account column', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.view', 'fixed_assets.view_trashed']);
    $this->actingAs($actor);
    fixedAssetsSelectContext($context['company'], $context['branch'], $context['period']);

    $html = $this->get(route('admin.fixed-assets.assets.index'))
        ->assertOk()
        ->assertSee(__('fixed_assets.columns.asset_name'))
        ->assertSee(__('fixed_assets.columns.asset_category'))
        ->assertSee(__('business_partners.trash.filter_label'))
        ->assertSee(__('business_partners.trash.active'))
        ->assertSee(__('business_partners.trash.trashed'))
        ->assertSee(__('common.fields.created_by'))
        ->assertSee(__('common.fields.created_at'))
        ->assertSee(__('common.fields.updated_by'))
        ->assertSee(__('common.fields.updated_at'))
        ->assertDontSee('Linked Account')
        ->assertDontSee('الحساب المرتبط')
        ->getContent();

    expect($html)->not->toContain("'account'")
        ->not->toContain('"account"');
});

test('Fixed Asset direct decimals keep accepted precision before persistence', function (): void {
    $context = fixedAssetsContext();
    $actor = fixedAssetsActor(['fixed_assets.create']);
    $currency = Currency::query()->create([
        'company_id' => $context['company']->getKey(),
        'doc_number' => 98761,
        'doc_num' => 'Currency-98761',
        'name' => 'Fixed Asset Precision Currency',
        'code' => 'FAP',
        'minor_unit_name' => 'Part',
        'minor_unit_factor' => 100,
        'is_main' => false,
        'status' => 'active',
    ]);
    $capturedAttributes = [];

    FixedAsset::creating(function (FixedAsset $asset) use (&$capturedAttributes): void {
        $capturedAttributes[] = $asset->getAttributes();
    });

    $this->actingAs($actor)
        ->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
            'asset_name' => 'Fixed Asset Precision Units',
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => '999,999,999,999.999999',
            'entry_type' => FixedAsset::EntryTypeOpeningAsset,
            'purchase_value' => '99,999,999,999,999.9999',
            'salvage_value' => '11,111,111,111,111.1111',
            'previous_depreciation' => '22,222,222,222,222.2222',
            'previous_depreciation_until_date' => $context['period']->from_date->toDateString(),
            'depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction,
            'useful_life' => '',
            'annual_depreciation_rate' => '',
            'expected_usage_units' => '88,888,888,888,888.8888',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->postJson(route('admin.fixed-assets.assets.store'), fixedAssetsPayload($context, [
            'asset_name' => 'Fixed Asset Precision Life And Rate',
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => '999,999,999,999.999999',
            'useful_life' => '3.25',
            'annual_depreciation_rate' => '30.7692',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($capturedAttributes)->toHaveCount(2)
        ->and($capturedAttributes[0]['exchange_rate'])->toBe('999999999999.999999')
        ->and($capturedAttributes[0]['purchase_value'])->toBe('99999999999999.9999')
        ->and($capturedAttributes[0]['salvage_value'])->toBe('11111111111111.1111')
        ->and($capturedAttributes[0]['previous_depreciation'])->toBe('22222222222222.2222')
        ->and($capturedAttributes[0]['expected_usage_units'])->toBe('88888888888888.8888')
        ->and($capturedAttributes[1]['useful_life'])->toBe('3.25')
        ->and($capturedAttributes[1]['annual_depreciation_rate'])->toBe('30.7692');
});
