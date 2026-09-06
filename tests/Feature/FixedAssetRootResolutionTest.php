<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Services\FixedAssetRootAccountAuditService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod, currency: Currency, assets: Account, root: Account, category: Account, credit: Account}
 */
function fixedAssetRootRegressionContext(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(AccountClassificationsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $classification = AccountClassification::query()->where('code', AccountClassification::FixedAssets)->firstOrFail();
    $assets = fixedAssetRootRegressionAccount($company, '1', 'الأصول', 'Assets');
    $root = fixedAssetRootRegressionAccount(
        $company,
        '12',
        'أصول ثابتة',
        'Fixed Assets',
        $assets,
        $classification,
    );
    $category = fixedAssetRootRegressionAccount(
        $company,
        '122',
        'آلات إنتاجية',
        'Production Machinery',
        $root,
        $classification,
    );
    $credit = fixedAssetRootRegressionAccount(
        $company,
        '2',
        'حساب دائن',
        'Credit Account',
        isGroup: false,
    );
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    session($session);
    test()->withSession($session);

    return compact('company', 'branch', 'period', 'currency', 'assets', 'root', 'category', 'credit');
}

function fixedAssetRootRegressionAccount(
    Company $company,
    string $code,
    string $name,
    string $nameEn,
    ?Account $parent = null,
    ?AccountClassification $classification = null,
    bool $isGroup = true,
): Account {
    return Account::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, (int) $company->getKey()),
        'company_id' => $company->getKey(),
        'account_code' => $code,
        'name' => $name,
        'name_en' => $nameEn,
        'parent_id' => $parent?->getKey(),
        'level' => $parent instanceof Account ? (int) $parent->level + 1 : 1,
        'account_classification_id' => $classification?->getKey(),
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => $isGroup,
        'is_postable' => ! $isGroup,
        'status' => 'active',
    ]);
}

function fixedAssetRootRegressionActor(array $permissions): User
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

/** @param array{company: Company, branch: Branch, period: FinancialPeriod, currency: Currency, category: Account, credit: Account} $context */
function fixedAssetRootRegressionPayload(array $context, array $overrides = []): array
{
    $date = $context['period']->from_date->toDateString();

    return [
        'asset_date' => $date,
        'asset_name' => 'Regression Production Line',
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'asset_group_account_doc_num' => $context['category']->doc_num,
        'credit_account_doc_num' => $context['credit']->doc_num,
        'branch_doc_num' => $context['branch']->doc_num,
        'description' => 'Fixed Asset root regression coverage',
        'purchase_date' => $date,
        'operation_date' => $date,
        'status' => FixedAsset::StatusActive,
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

test('Fixed Asset form and selector reads preserve the canonical classified root without creating code 121', function (): void {
    $context = fixedAssetRootRegressionContext();
    $this->actingAs(fixedAssetRootRegressionActor(['fixed_assets.create', 'fixed_assets.view']));
    $accounts = app(BusinessPartnerAccountService::class);
    $attributesBefore = Account::withTrashed()
        ->where('company_id', $context['company']->getKey())
        ->get()
        ->mapWithKeys(fn (Account $account): array => [(int) $account->getKey() => $account->getAttributes()])
        ->all();

    expect($accounts->rootAccount(BusinessPartnerAccountService::FixedAsset)->is($context['root']))->toBeTrue();
    expect($accounts->rootAccount(BusinessPartnerAccountService::FixedAsset)->is($context['root']))->toBeTrue();

    $this->get(route('admin.fixed-assets.assets.create'))->assertOk();
    $this->get(route('admin.fixed-assets.assets.create'))->assertOk();

    foreach (['', 'آلات إنتاجية', 'Production Machinery', '122', $context['category']->doc_num] as $term) {
        $response = $this->getJson(route('admin.fixed-assets.select2.asset-categories', ['q' => $term]))->assertOk();

        expect(collect($response->json('results'))->pluck('id')->all())
            ->toContain($context['category']->doc_num)
            ->not->toContain($context['root']->doc_num);
    }

    $attributesAfter = Account::withTrashed()
        ->where('company_id', $context['company']->getKey())
        ->get()
        ->mapWithKeys(fn (Account $account): array => [(int) $account->getKey() => $account->getAttributes()])
        ->all();

    expect($attributesAfter)->toBe($attributesBefore)
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->where('account_code', '121')->exists())->toBeFalse()
        ->and($context['root']->fresh()->doc_num)->toBe($context['root']->doc_num)
        ->and((int) $context['root']->fresh()->getKey())->toBe((int) $context['root']->getKey());
});

test('Fixed Asset selector starts at canonical children and excludes a duplicate foundational branch', function (): void {
    $context = fixedAssetRootRegressionContext();
    $this->actingAs(fixedAssetRootRegressionActor(['fixed_assets.view']));
    $classification = AccountClassification::query()->where('code', AccountClassification::FixedAssets)->firstOrFail();
    $duplicate = fixedAssetRootRegressionAccount(
        $context['company'],
        '121',
        'أصول ثابتة',
        'Fixed Assets',
        $context['root'],
        $classification,
    );
    $duplicateChild = fixedAssetRootRegressionAccount(
        $context['company'],
        '1211',
        'تصنيف داخل الجذر المكرر',
        'Duplicate Root Category',
        $duplicate,
        $classification,
    );
    $countBefore = Account::withTrashed()->where('company_id', $context['company']->getKey())->count();

    $response = $this->getJson(route('admin.fixed-assets.select2.asset-categories'))->assertOk();
    $resultIds = collect($response->json('results'))->pluck('id')->all();

    expect(app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset)->is($context['root']))->toBeTrue()
        ->and($resultIds)->toContain($context['category']->doc_num)
        ->not->toContain($context['root']->doc_num, $duplicate->doc_num, $duplicateChild->doc_num)
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->count())->toBe($countBefore);
});

test('only explicit Fixed Asset category and asset writes add one correctly parented account each', function (): void {
    $context = fixedAssetRootRegressionContext();
    $this->actingAs(fixedAssetRootRegressionActor(['fixed_assets.create', 'fixed_assets.view', 'fixed_assets.edit', 'accounts.create']));
    $categoryUrl = route('admin.fixed-assets.assets.asset-categories.store');
    $accountCountBeforeCategory = Account::withTrashed()->where('company_id', $context['company']->getKey())->count();

    $categoryResponse = $this->postJson($categoryUrl, ['name' => 'وسائل نقل'])->assertOk()->assertJsonPath('success', true);
    $category = Account::query()->where('doc_num', $categoryResponse->json('data.option.id'))->firstOrFail();

    expect(Account::withTrashed()->where('company_id', $context['company']->getKey())->count())->toBe($accountCountBeforeCategory + 1)
        ->and((int) $category->parent_id)->toBe((int) $context['root']->getKey())
        ->and($category->is_group)->toBeTrue()
        ->and($category->is_postable)->toBeFalse();

    $this->postJson($categoryUrl, ['name' => 'أصول ثابتة'])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('fixed_assets.messages.asset_category_reserved_root_name'));
    $this->postJson($categoryUrl, ['name' => 'الأصول الثابتة'])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('fixed_assets.messages.asset_category_reserved_root_name'));

    expect(Account::withTrashed()->where('company_id', $context['company']->getKey())->count())->toBe($accountCountBeforeCategory + 1);

    $accountCountBeforeAsset = Account::withTrashed()->where('company_id', $context['company']->getKey())->count();
    $assetResponse = $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetRootRegressionPayload($context, [
        'asset_group_account_doc_num' => $category->doc_num,
        'status' => 'draft',
    ]))->assertOk()->assertJsonPath('success', true);
    $asset = FixedAsset::query()->where('doc_num', $assetResponse->json('data.doc_num'))->firstOrFail();
    $assetAccount = Account::query()->findOrFail($asset->account_id);
    $attributesAfterAssetCreation = Account::withTrashed()
        ->where('company_id', $context['company']->getKey())
        ->get()
        ->map->getAttributes()
        ->all();

    $this->get(route('admin.fixed-assets.assets.show', $asset->doc_num))->assertOk();
    $this->get(route('admin.fixed-assets.assets.edit', $asset->doc_num))->assertOk();

    expect(Account::withTrashed()->where('company_id', $context['company']->getKey())->count())->toBe($accountCountBeforeAsset + 1)
        ->and((int) $assetAccount->parent_id)->toBe((int) $category->getKey())
        ->and($assetAccount->is_group)->toBeFalse()
        ->and($assetAccount->is_postable)->toBeTrue()
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->where('account_code', '121')->exists())->toBeFalse()
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->get()->map->getAttributes()->all())
        ->toBe($attributesAfterAssetCreation);
});

test('missing and ambiguous Fixed Asset roots return localized errors with no account writes', function (): void {
    $context = fixedAssetRootRegressionContext();
    $this->actingAs(fixedAssetRootRegressionActor(['fixed_assets.view']));
    $classification = AccountClassification::query()->where('code', AccountClassification::FixedAssets)->firstOrFail();
    $context['root']->forceFill(['account_classification_id' => null])->save();
    $missingSnapshot = Account::withTrashed()->where('company_id', $context['company']->getKey())->get()->map->getAttributes()->all();

    $this->withSession(['locale' => 'en'])
        ->getJson(route('admin.fixed-assets.select2.asset-categories'))
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'business_rule_violation')
        ->assertJsonPath('message', __('fixed_assets.messages.root_account_missing'));

    expect(Account::withTrashed()->where('company_id', $context['company']->getKey())->get()->map->getAttributes()->all())->toBe($missingSnapshot);

    $context['root']->forceFill(['account_classification_id' => $classification->getKey()])->save();
    $otherRoot = fixedAssetRootRegressionAccount(
        $context['company'],
        '99',
        'أصول ثابتة',
        'Fixed Assets',
        classification: $classification,
    );
    $ambiguousSnapshot = Account::withTrashed()->where('company_id', $context['company']->getKey())->get()->map->getAttributes()->all();
    $response = $this->withSession(['locale' => 'ar'])
        ->getJson(route('admin.fixed-assets.select2.asset-categories'))
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'business_rule_violation');

    expect((string) $response->json('message'))
        ->toContain('تعذر تحديد حساب الأصول الثابتة الرئيسي')
        ->toContain('code=12', 'code=99', $context['root']->doc_num, $otherRoot->doc_num)
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->get()->map->getAttributes()->all())->toBe($ambiguousSnapshot);
});

test('the foundational resolver cannot create a missing Accounts Receivable root', function (): void {
    $context = fixedAssetRootRegressionContext();
    $this->actingAs(fixedAssetRootRegressionActor(['customers.view']));
    $classification = AccountClassification::query()->where('code', 'accounts_receivable')->firstOrFail();
    $receivables = fixedAssetRootRegressionAccount(
        $context['company'],
        '1121',
        'ذمم مدينة',
        'Accounts Receivable',
        $context['assets'],
        $classification,
    );
    $accounts = app(BusinessPartnerAccountService::class);

    expect($accounts->rootAccount(BusinessPartnerAccountService::Customer)->is($receivables))->toBeTrue();

    $receivables->forceFill(['account_classification_id' => null])->save();
    $attributesBefore = Account::withTrashed()->where('company_id', $context['company']->getKey())->get()->map->getAttributes()->all();

    $this->withSession(['locale' => 'en'])
        ->getJson(route('admin.sales.select2.customer-groups'))
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'business_rule_violation')
        ->assertJsonPath('message', __('customers.messages.root_account_missing'));

    expect(fn (): Account => $accounts->rootAccount(BusinessPartnerAccountService::Customer))
        ->toThrow(DomainException::class, __('customers.messages.root_account_missing'))
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->get()->map->getAttributes()->all())
        ->toBe($attributesBefore);
});

test('duplicate Fixed Asset root audit is database-enforced read only and reports every archive blocker', function (): void {
    $context = fixedAssetRootRegressionContext();
    $classification = AccountClassification::query()->where('code', AccountClassification::FixedAssets)->firstOrFail();
    $duplicate = fixedAssetRootRegressionAccount(
        $context['company'],
        '121',
        'أصول ثابتة',
        'Fixed Assets',
        $context['root'],
        $classification,
    );
    fixedAssetRootRegressionAccount(
        $context['company'],
        '1211',
        'فرع مكرر',
        'Duplicate Child',
        $duplicate,
        $classification,
    );
    $attributesBefore = Account::withTrashed()->where('company_id', $context['company']->getKey())->get()->map->getAttributes()->all();

    $report = app(FixedAssetRootAccountAuditService::class)->auditReadOnly($context['company']);
    $candidate = collect($report['duplicate_candidates'])->firstWhere('id', (int) $duplicate->getKey());

    $this->artisan('fixed-assets:audit-root-accounts', [
        '--company' => $context['company']->doc_num,
    ])
        ->expectsOutputToContain('resolution=unique duplicate_candidates=1')
        ->expectsOutputToContain($duplicate->doc_num)
        ->expectsOutputToContain('Read-only audit complete')
        ->assertSuccessful();

    expect($report['read_only_enforced'])->toBeTrue()
        ->and($report['resolution_status'])->toBe('unique')
        ->and($report['canonical_root']['id'])->toBe((int) $context['root']->getKey())
        ->and($report['duplicate_candidate_count'])->toBe(1)
        ->and($candidate)->toBeArray()
        ->and($candidate['doc_num'])->toBe($duplicate->doc_num)
        ->and($candidate['code'])->toBe('121')
        ->and($candidate['parent_path'])->toContain('12 / أصول ثابتة', '121 / أصول ثابتة')
        ->and($candidate['children_with_trashed_count'])->toBe(1)
        ->and($candidate['journal_line_count'])->toBe(0)
        ->and($candidate['opening_balance_line_count'])->toBe(0)
        ->and($candidate['archive_status'])->toBe('blocked')
        ->and($candidate['archive_blockers'])->toContain('child_accounts')
        ->and($candidate)->toHaveKeys([
            'fixed_asset_category_references',
            'fixed_asset_references',
            'journal_debit_total',
            'journal_credit_total',
            'journal_balance',
            'opening_balance_debit_total',
            'opening_balance_credit_total',
            'opening_balance',
            'references',
            'other_application_references',
            'status',
            'deleted',
        ])
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->get()->map->getAttributes()->all())
        ->toBe($attributesBefore);
});
