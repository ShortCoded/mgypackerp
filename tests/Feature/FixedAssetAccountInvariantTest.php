<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Services\AccountTreeReport;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Services\FixedAssetDuplicateAccountAuditService;
use Modules\FixedAssets\Services\FixedAssetDuplicateAccountRepairService;
use Modules\FixedAssets\Services\FixedAssetService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod, currency: Currency, categories: array{Account, Account}}
 */
function fixedAssetInvariantContext(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(AccountClassificationsSeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->oldest('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->oldest('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->oldest('id')->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->where('status', 'active')->orderByDesc('is_main')->firstOrFail();
    fixedAssetInvariantSelectContext($company, $branch, $period);
    $root = Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', '121')
        ->firstOrFail();
    $categories = Account::query()
        ->where('company_id', $company->getKey())
        ->where('parent_id', $root->getKey())
        ->where('status', 'active')
        ->where('is_group', true)
        ->where('is_postable', false)
        ->oldest('id')
        ->limit(2)
        ->get()
        ->all();

    while (count($categories) < 2) {
        $categories[] = app(BusinessPartnerAccountService::class)->createGroup(
            BusinessPartnerAccountService::FixedAsset,
            'Fixed Asset Category '.(count($categories) + 1),
        );
    }

    return compact('company', 'branch', 'period', 'currency', 'categories');
}

function fixedAssetInvariantSelectContext(Company $company, Branch $branch, FinancialPeriod $period): void
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

function fixedAssetInvariantActor(array $permissions): User
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

function fixedAssetInvariantPayload(array $context, array $overrides = []): array
{
    $creditAccount = Account::query()
        ->where('company_id', $context['company']->getKey())
        ->where('status', 'active')
        ->where('is_group', false)
        ->where('is_postable', true)
        ->oldest('id')
        ->firstOrFail();

    return [
        'asset_date' => $context['period']->from_date->toDateString(),
        'asset_name' => 'Invariant Asset',
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'asset_group_account_doc_num' => $context['categories'][0]->doc_num,
        'credit_account_doc_num' => $creditAccount->doc_num,
        'branch_doc_num' => $context['branch']->doc_num,
        'description' => 'Fixed asset invariant regression',
        'purchase_date' => $context['period']->from_date->toDateString(),
        'operation_date' => $context['period']->from_date->toDateString(),
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

function fixedAssetInvariantRecordAccountChange(FixedAsset $asset, Account $oldAccount, Account $newAccount): Activity
{
    return Activity::query()->create([
        'log_name' => 'fixed_assets',
        'description' => 'fixed_assets.fixed_assets.update.success',
        'subject_type' => $asset->getMorphClass(),
        'subject_id' => $asset->getKey(),
        'event' => 'fixed_assets.update',
        'properties' => [
            'changes' => [
                'account_id' => [
                    'old' => $oldAccount->getKey(),
                    'new' => $newAccount->getKey(),
                ],
            ],
        ],
        'company_id' => $asset->company_id,
        'module' => 'fixed_assets',
        'action' => 'fixed_assets.update',
        'status' => 'success',
    ]);
}

test('category change reparents the canonical legacy account instead of creating a replacement', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create', 'fixed_assets.edit']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context))
        ->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Invariant Asset')->firstOrFail();
    $canonicalAccount = Account::query()->findOrFail($asset->account_id);
    $originalAccountId = $canonicalAccount->getKey();
    $originalDocumentNumber = $canonicalAccount->doc_num;
    $oldParentSnapshot = $context['categories'][0]->only([
        'id', 'doc_number', 'doc_num', 'account_code', 'name', 'name_en', 'parent_id',
        'account_classification_id', 'normal_balance', 'status',
    ]);
    $newParentSnapshot = $context['categories'][1]->only([
        'id', 'doc_number', 'doc_num', 'account_code', 'name', 'name_en', 'parent_id',
        'account_classification_id', 'normal_balance', 'status',
    ]);
    $staleClassification = AccountClassification::query()->where('code', '!=', 'fixed_assets')->oldest('id')->firstOrFail();
    $canonicalAccount->forceFill(['account_classification_id' => $staleClassification->getKey()])->save();

    $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetInvariantPayload($context, [
        'asset_group_account_doc_num' => $context['categories'][1]->doc_num,
    ]))->assertOk();

    $asset->refresh();
    $canonicalAccount->refresh();
    $renderedRows = app(AccountTreeReport::class)->rows(['account_search' => 'Invariant Asset']);

    expect((int) $asset->account_id)->toBe((int) $originalAccountId)
        ->and($canonicalAccount->doc_num)->toBe($originalDocumentNumber)
        ->and((int) $canonicalAccount->parent_id)->toBe((int) $context['categories'][1]->getKey())
        ->and($canonicalAccount->classification?->code)->toBe('fixed_assets')
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->where('name', 'Invariant Asset')->count())->toBe(1)
        ->and($renderedRows->where('name', 'Invariant Asset'))->toHaveCount(1)
        ->and((int) $renderedRows->firstWhere('name', 'Invariant Asset')->parent_id)->toBe((int) $context['categories'][1]->getKey())
        ->and($context['categories'][0]->refresh()->only(array_keys($oldParentSnapshot)))->toBe($oldParentSnapshot)
        ->and($context['categories'][1]->refresh()->only(array_keys($newParentSnapshot)))->toBe($newParentSnapshot)
        ->and($canonicalAccount->fixedAsset?->is($asset))->toBeTrue();
});

test('audit reports the exact historical orphan shape as blocked review evidence when stable history is absent', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context))
        ->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Invariant Asset')->firstOrFail();
    $originalAccount = Account::query()->findOrFail($asset->account_id);
    $replacementAccount = app(AccountService::class)->createChildFromParent($context['categories'][1], [
        'name' => 'Invariant Asset',
        'classification_code' => 'fixed_assets',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $asset->forceFill([
        'account_id' => $replacementAccount->getKey(),
        'asset_group_account_id' => $context['categories'][1]->getKey(),
    ])->save();
    $beforeAsset = $asset->fresh()->getAttributes();
    $beforeAccounts = Account::query()
        ->whereIn('id', [$originalAccount->getKey(), $replacementAccount->getKey()])
        ->oldest('id')
        ->get()
        ->map->getAttributes()
        ->all();

    expect($asset->getKey())->toBe(1)
        ->and($asset->doc_num)->toBe('FA-00001')
        ->and($originalAccount->getKey())->not->toBe($replacementAccount->getKey())
        ->and((int) $asset->account_id)->toBe((int) $replacementAccount->getKey())
        ->and(FixedAsset::query()->where('account_id', $originalAccount->getKey())->exists())->toBeFalse();

    $result = app(FixedAssetDuplicateAccountAuditService::class)->audit($context['company'], $asset->doc_num);
    $case = $result['cases'][0];
    $originalCandidate = collect($case['accounts'])->firstWhere('id', $originalAccount->getKey());
    $replacementCandidate = collect($case['accounts'])->firstWhere('id', $replacementAccount->getKey());

    expect($result['duplicate_assets'])->toBe(0)
        ->and($result['review_candidates'])->toBe(1)
        ->and($result['safe_cases'])->toBe(0)
        ->and($result['blocked_cases'])->toBe(1)
        ->and(collect($case['accounts'])->pluck('id')->all())->toBe([$originalAccount->getKey(), $replacementAccount->getKey()])
        ->and($case['current_account_id'])->toBe($replacementAccount->getKey())
        ->and($case['repair_status'])->toBe('blocked')
        ->and($originalCandidate['association_role'])->toBe('review_candidate')
        ->and($originalCandidate['detection_confidence'])->toBe('review_only')
        ->and($originalCandidate['created_at'])->not->toBeNull()
        ->and($originalCandidate['path'])->toContain('Fixed Asset Category 1')
        ->and($originalCandidate['journal_lines'])->toBe(0)
        ->and($originalCandidate['debit_total'])->toBe('0.0000')
        ->and($originalCandidate['credit_total'])->toBe('0.0000')
        ->and($originalCandidate['balance'])->toBe('0.0000')
        ->and($replacementCandidate['association_role'])->toBe('current_referenced_account')
        ->and($replacementCandidate['detection_confidence'])->toBe('high')
        ->and($replacementCandidate['path'])->toContain('Fixed Asset Category 2');

    $this->artisan('fixed-assets:audit-linked-accounts', [
        '--company' => $context['company']->doc_num,
        '--asset' => $asset->doc_num,
    ])
        ->expectsOutputToContain('actual_duplicates=0 review_candidates=1 safe_empty_account_repairs=0')
        ->expectsOutputToContain($originalAccount->doc_num)
        ->expectsOutputToContain($replacementAccount->doc_num)
        ->assertSuccessful();

    expect($asset->fresh()->getAttributes())->toBe($beforeAsset)
        ->and(Account::query()->whereIn('id', [$originalAccount->getKey(), $replacementAccount->getKey()])->oldest('id')->get()->map->getAttributes()->all())->toBe($beforeAccounts);
});

test('activity account history detects the orphan without an account name match', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context))
        ->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Invariant Asset')->firstOrFail();
    $originalAccount = Account::query()->findOrFail($asset->account_id);
    $originalAccount->forceFill(['name' => 'Historical Account With A Different Name'])->save();
    $replacementAccount = app(AccountService::class)->createChildFromParent($context['categories'][1], [
        'name' => 'Invariant Asset',
        'classification_code' => 'fixed_assets',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    fixedAssetInvariantRecordAccountChange($asset, $originalAccount, $replacementAccount);
    $asset->forceFill([
        'account_id' => $replacementAccount->getKey(),
        'asset_group_account_id' => $context['categories'][1]->getKey(),
    ])->save();

    $result = app(FixedAssetDuplicateAccountAuditService::class)->audit($context['company'], $asset->doc_num);
    $case = $result['cases'][0];
    $originalCandidate = collect($case['accounts'])->firstWhere('id', $originalAccount->getKey());
    $replacementCandidate = collect($case['accounts'])->firstWhere('id', $replacementAccount->getKey());

    expect($originalAccount->getKey())->not->toBe($replacementAccount->getKey())
        ->and($originalAccount->name)->not->toBe($asset->asset_name)
        ->and(collect($case['accounts'])->pluck('id')->all())->toBe([$originalAccount->getKey(), $replacementAccount->getKey()])
        ->and($case['current_account_id'])->toBe($replacementAccount->getKey())
        ->and($case['recommended_canonical_account_id'])->toBe($originalAccount->getKey())
        ->and($case['repair_status'])->toBe('safe')
        ->and($originalCandidate['association_role'])->toBe('previous_orphan_account')
        ->and($originalCandidate['detection_confidence'])->toBe('high')
        ->and(collect($originalCandidate['evidence'])->pluck('source'))->toContain('activity_log.properties.changes.account_id.old')
        ->and($replacementCandidate['association_role'])->toBe('current_referenced_account')
        ->and($replacementCandidate['detection_confidence'])->toBe('high')
        ->and(collect($replacementCandidate['evidence'])->pluck('source'))->toContain(
            'fixed_assets.account_id',
            'activity_log.properties.changes.account_id.new',
        );
});

test('unrelated and stale-snapshot edits never create or move another account', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create', 'fixed_assets.edit']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context, [
        'asset_name' => 'Stable Snapshot Asset',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Stable Snapshot Asset')->firstOrFail();
    $firstSnapshot = $asset->fresh();
    $secondSnapshot = $asset->fresh();
    $account = Account::query()->findOrFail($asset->account_id);
    $originalAccountId = $account->getKey();
    $originalParentId = $account->parent_id;
    $originalUpdatedAt = $account->updated_at?->toJSON();
    $originalAccountCount = Account::query()->where('company_id', $context['company']->getKey())->count();

    $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetInvariantPayload($context, [
        'asset_name' => 'Stable Snapshot Asset',
        'description' => 'Only the description changed',
    ]))->assertOk();

    expect(Account::query()->where('company_id', $context['company']->getKey())->count())->toBe($originalAccountCount)
        ->and(Account::query()->findOrFail($originalAccountId)->parent_id)->toBe($originalParentId)
        ->and(Account::query()->findOrFail($originalAccountId)->updated_at?->toJSON())->toBe($originalUpdatedAt);

    app(FixedAssetService::class)->update($firstSnapshot, fixedAssetInvariantPayload($context, [
        'asset_name' => 'Stable Snapshot Asset',
        'description' => 'First stale writer',
        'asset_group_account_doc_num' => $context['categories'][1]->doc_num,
    ]));
    app(FixedAssetService::class)->update($secondSnapshot, fixedAssetInvariantPayload($context, [
        'asset_name' => 'Stable Snapshot Asset',
        'description' => 'Second stale writer',
        'asset_group_account_doc_num' => $context['categories'][0]->doc_num,
    ]));

    $asset->refresh();

    expect((int) $asset->account_id)->toBe((int) $originalAccountId)
        ->and((int) $asset->account->parent_id)->toBe((int) $context['categories'][0]->getKey())
        ->and(Account::query()->where('company_id', $context['company']->getKey())->count())->toBe($originalAccountCount)
        ->and(Account::query()->where('company_id', $context['company']->getKey())->where('name', 'Stable Snapshot Asset')->count())->toBe(1);
});

test('repeated category changes keep one active tree path in Arabic and English', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create', 'fixed_assets.edit']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context, [
        'asset_name' => 'Locale Tree Asset',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Locale Tree Asset')->firstOrFail();
    $accountId = $asset->account_id;

    foreach ([1, 0, 1] as $categoryIndex) {
        $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetInvariantPayload($context, [
            'asset_name' => 'Locale Tree Asset',
            'asset_group_account_doc_num' => $context['categories'][$categoryIndex]->doc_num,
        ]))->assertOk();
    }

    $historicalDuplicate = app(AccountService::class)->createChildFromParent($context['categories'][0], [
        'name' => 'Locale Tree Asset',
        'classification_code' => 'fixed_assets',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $historicalDuplicate->delete();
    $report = app(AccountTreeReport::class);

    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $rows = $report->rows(['account_search' => 'Locale Tree Asset']);

        expect($rows->where('name', 'Locale Tree Asset'))->toHaveCount(1)
            ->and($rows->pluck('id'))->toContain($context['categories'][1]->getKey())
            ->not->toContain($context['categories'][0]->getKey(), $historicalDuplicate->getKey());
    }

    expect((int) $asset->refresh()->account_id)->toBe((int) $accountId)
        ->and(Account::query()->where('company_id', $context['company']->getKey())->where('name', 'Locale Tree Asset')->count())->toBe(1)
        ->and(Account::withTrashed()->where('company_id', $context['company']->getKey())->where('name', 'Locale Tree Asset')->count())->toBe(2);
});

test('journal references remain stable and account and asset changes roll back together', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create', 'fixed_assets.edit']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context, [
        'asset_name' => 'Journal Stable Asset',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Journal Stable Asset')->firstOrFail();
    $accountId = $asset->account_id;
    $journalEntryId = DB::table('journal_entries')->insertGetId([
        'doc_number' => 990001,
        'doc_num' => 'JE-990001',
        'entry_date' => $context['period']->from_date->toDateString(),
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'currency_id' => $context['currency']->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $journalLineId = DB::table('journal_entry_lines')->insertGetId([
        'journal_entry_id' => $journalEntryId,
        'line_no' => 1,
        'account_id' => $accountId,
        'debit_amount' => 50,
        'credit_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $failUpdate = true;
    FixedAsset::updating(function () use (&$failUpdate): void {
        if ($failUpdate) {
            throw new DomainException('Forced fixed asset update failure.');
        }
    });

    try {
        $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetInvariantPayload($context, [
            'asset_name' => 'Journal Stable Asset',
            'asset_group_account_doc_num' => $context['categories'][1]->doc_num,
        ]))->assertUnprocessable();
    } finally {
        $failUpdate = false;
    }

    expect((int) $asset->refresh()->asset_group_account_id)->toBe((int) $context['categories'][0]->getKey())
        ->and((int) $asset->account->parent_id)->toBe((int) $context['categories'][0]->getKey())
        ->and((int) DB::table('journal_entry_lines')->where('id', $journalLineId)->value('account_id'))->toBe((int) $accountId);

    $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetInvariantPayload($context, [
        'asset_name' => 'Journal Stable Asset',
        'asset_group_account_doc_num' => $context['categories'][1]->doc_num,
    ]))->assertOk();

    expect((int) $asset->refresh()->account_id)->toBe((int) $accountId)
        ->and((int) $asset->account->parent_id)->toBe((int) $context['categories'][1]->getKey())
        ->and((int) DB::table('journal_entry_lines')->where('id', $journalLineId)->value('account_id'))->toBe((int) $accountId);
});

test('cross-company inactive and deleted category parents are rejected on update', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create', 'fixed_assets.edit']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context, [
        'asset_name' => 'Parent Validation Asset',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Parent Validation Asset')->firstOrFail();
    $inactiveParent = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::FixedAsset, 'Inactive Asset Category');
    $inactiveParent->forceFill(['status' => 'inactive'])->save();
    $deletedParent = app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::FixedAsset, 'Deleted Asset Category');
    $deletedParentDocNum = $deletedParent->doc_num;
    $deletedParent->delete();
    $otherCompany = Company::query()->create([
        'doc_number' => 990002,
        'doc_num' => 'Company-990002',
        'name' => 'Other Invariant Company',
        'status' => 'active',
    ]);
    $crossCompanyParent = Account::query()->create([
        'doc_number' => 990002,
        'doc_num' => 'ACC-990002',
        'company_id' => $otherCompany->getKey(),
        'account_code' => '990002',
        'name' => 'Cross Company Asset Category',
        'level' => 1,
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => true,
        'is_postable' => false,
        'status' => 'active',
    ]);

    foreach ([$inactiveParent->doc_num, $deletedParentDocNum, $crossCompanyParent->doc_num] as $parentDocNum) {
        $this->putJson(route('admin.fixed-assets.assets.update', $asset->doc_num), fixedAssetInvariantPayload($context, [
            'asset_name' => 'Parent Validation Asset',
            'asset_group_account_doc_num' => $parentDocNum,
        ]))->assertUnprocessable()->assertJsonValidationErrors('asset_group_account_doc_num');
    }

    expect((int) $asset->refresh()->account->parent_id)->toBe((int) $context['categories'][0]->getKey())
        ->and(Account::query()->where('company_id', $context['company']->getKey())->where('name', 'Parent Validation Asset')->count())->toBe(1);
});

test('duplicate account audit is explicitly scoped and dry-run makes no changes', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context, [
        'asset_name' => 'Dry Run Duplicate Asset',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Dry Run Duplicate Asset')->firstOrFail();
    $duplicate = app(AccountService::class)->createChildFromParent($context['categories'][1], [
        'name' => 'Dry Run Duplicate Asset',
        'classification_code' => 'fixed_assets',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $beforeAsset = $asset->getAttributes();
    $beforeAccounts = Account::withTrashed()
        ->whereIn('id', [$asset->account_id, $duplicate->getKey()])
        ->oldest('id')
        ->get()
        ->map->getAttributes()
        ->all();

    $this->artisan('fixed-assets:audit-linked-accounts')
        ->expectsOutputToContain('The --company option is required')
        ->assertFailed();
    $this->artisan('fixed-assets:audit-linked-accounts', [
        '--company' => $context['company']->doc_num,
        '--asset' => $asset->doc_num,
    ])
        ->expectsOutputToContain('actual_duplicates=0 review_candidates=1 safe_empty_account_repairs=0')
        ->expectsOutputToContain('Read-only audit complete')
        ->assertSuccessful();

    $afterAccounts = Account::withTrashed()
        ->whereIn('id', [$asset->account_id, $duplicate->getKey()])
        ->oldest('id')
        ->get()
        ->map->getAttributes()
        ->all();

    expect($asset->refresh()->getAttributes())->toBe($beforeAsset)
        ->and($afterAccounts)->toBe($beforeAccounts)
        ->and($duplicate->refresh()->trashed())->toBeFalse();
});

test('safe repair keeps the account with history archives the empty duplicate and is idempotent', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context, [
        'asset_name' => 'Safe Repair Asset',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Safe Repair Asset')->firstOrFail();
    $originalAccount = Account::query()->findOrFail($asset->account_id);
    $replacementAccount = app(AccountService::class)->createChildFromParent($context['categories'][1], [
        'name' => 'Safe Repair Asset',
        'classification_code' => 'fixed_assets',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    fixedAssetInvariantRecordAccountChange($asset, $originalAccount, $replacementAccount);
    $asset->forceFill([
        'account_id' => $replacementAccount->getKey(),
        'asset_group_account_id' => $context['categories'][1]->getKey(),
    ])->save();
    $journalEntryId = DB::table('journal_entries')->insertGetId([
        'doc_number' => 990003,
        'doc_num' => 'JE-990003',
        'entry_date' => $context['period']->from_date->toDateString(),
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'currency_id' => $context['currency']->getKey(),
        'exchange_rate' => 1,
        'source_type' => 'fixed_asset_capitalization',
        'source_id' => $asset->getKey(),
        'source_doc_num' => $asset->doc_num,
        'status' => 'posted',
        'is_posted' => true,
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $journalLineId = DB::table('journal_entry_lines')->insertGetId([
        'journal_entry_id' => $journalEntryId,
        'line_no' => 1,
        'account_id' => $originalAccount->getKey(),
        'debit_amount' => 1000,
        'credit_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('journal_entry_lines')->insert([
        'journal_entry_id' => $journalEntryId,
        'line_no' => 2,
        'account_id' => $asset->credit_account_id,
        'debit_amount' => 0,
        'credit_amount' => 1000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $audit = app(FixedAssetDuplicateAccountAuditService::class)->audit($context['company'], $asset->doc_num);
    $case = $audit['cases'][0];
    $historyAccount = collect($case['accounts'])->firstWhere('id', $originalAccount->getKey());

    expect($audit['duplicate_assets'])->toBe(1)
        ->and($audit['safe_cases'])->toBe(1)
        ->and($case['recommended_canonical_account_id'])->toBe($originalAccount->getKey())
        ->and($historyAccount['journal_lines'])->toBe(1)
        ->and($historyAccount['debit_total'])->toBe('1000.0000')
        ->and($historyAccount['credit_total'])->toBe('0.0000')
        ->and($historyAccount['balance'])->toBe('1000.0000');

    $duplicateAccount = collect($case['accounts'])->firstWhere('id', $replacementAccount->getKey());
    $expectations = [
        'canonical_account_id' => $originalAccount->getKey(),
        'duplicate_account_id' => $replacementAccount->getKey(),
        'expected_current_account_id' => $replacementAccount->getKey(),
        'expected_parent_account_id' => $context['categories'][1]->getKey(),
        'expected_canonical_journal_count' => $historyAccount['journal_line_count'],
        'expected_canonical_debit' => $historyAccount['total_debit'],
        'expected_canonical_credit' => $historyAccount['total_credit'],
        'expected_canonical_balance' => $historyAccount['balance'],
        'expected_duplicate_journal_count' => $duplicateAccount['journal_line_count'],
        'expected_duplicate_debit' => $duplicateAccount['total_debit'],
        'expected_duplicate_credit' => $duplicateAccount['total_credit'],
        'expected_duplicate_balance' => $duplicateAccount['balance'],
    ];
    $repairs = app(FixedAssetDuplicateAccountRepairService::class);
    FixedAssetCategoryMapping::query()->updateOrCreate([
        'company_id' => $context['company']->getKey(),
        'asset_group_account_id' => $context['categories'][1]->getKey(),
    ], [
        'accumulated_depreciation_account_id' => $asset->credit_account_id,
        'depreciation_expense_account_id' => $asset->credit_account_id,
        'disposal_gain_account_id' => $asset->credit_account_id,
        'disposal_loss_account_id' => $asset->credit_account_id,
        'disposal_clearing_account_id' => $asset->credit_account_id,
    ]);
    $review = $repairs->reviewReadOnly($context['company'], $asset->doc_num, $expectations);
    app()->maintenanceMode()->activate([]);

    try {
        $firstApply = $repairs->apply(
            $context['company'],
            $asset->doc_num,
            $expectations,
            $review['review_token'],
            $review['production_acknowledgement'],
        );

        expect($firstApply['changed'])->toBeTrue();

        expect((int) $asset->refresh()->account_id)->toBe((int) $originalAccount->getKey())
            ->and((int) $originalAccount->refresh()->parent_id)->toBe((int) $context['categories'][1]->getKey())
            ->and(Account::withTrashed()->findOrFail($replacementAccount->getKey())->trashed())->toBeTrue()
            ->and((int) DB::table('journal_entry_lines')->where('id', $journalLineId)->value('account_id'))->toBe((int) $originalAccount->getKey());

        $secondApply = $repairs->apply(
            $context['company'],
            $asset->doc_num,
            $expectations,
            $review['review_token'],
            $review['production_acknowledgement'],
        );

        expect($secondApply['idempotent'])->toBeTrue();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect(Account::query()->where('company_id', $context['company']->getKey())->where('name', 'Safe Repair Asset')->count())->toBe(1);
});

test('duplicates with history in more than one account are blocked and never modified', function (): void {
    $actor = fixedAssetInvariantActor(['fixed_assets.create']);
    $this->actingAs($actor);
    $context = fixedAssetInvariantContext();

    $this->postJson(route('admin.fixed-assets.assets.store'), fixedAssetInvariantPayload($context, [
        'asset_name' => 'Blocked Repair Asset',
    ]))->assertOk();

    $asset = FixedAsset::query()->where('asset_name', 'Blocked Repair Asset')->firstOrFail();
    $firstAccount = Account::query()->findOrFail($asset->account_id);
    $secondAccount = app(AccountService::class)->createChildFromParent($context['categories'][1], [
        'name' => 'Blocked Repair Asset',
        'classification_code' => 'fixed_assets',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    fixedAssetInvariantRecordAccountChange($asset, $secondAccount, $firstAccount);
    $journalEntryId = DB::table('journal_entries')->insertGetId([
        'doc_number' => 990004,
        'doc_num' => 'JE-990004',
        'entry_date' => $context['period']->from_date->toDateString(),
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'currency_id' => $context['currency']->getKey(),
        'exchange_rate' => 1,
        'source_type' => 'fixed_asset_capitalization',
        'source_id' => $asset->getKey(),
        'source_doc_num' => $asset->doc_num,
        'status' => 'posted',
        'is_posted' => true,
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('journal_entry_lines')->insert([
        [
            'journal_entry_id' => $journalEntryId,
            'line_no' => 1,
            'account_id' => $firstAccount->getKey(),
            'debit_amount' => 10,
            'credit_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'journal_entry_id' => $journalEntryId,
            'line_no' => 2,
            'account_id' => $secondAccount->getKey(),
            'debit_amount' => 0,
            'credit_amount' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $beforeAsset = $asset->getAttributes();
    $beforeAccounts = Account::query()->whereIn('id', [$firstAccount->getKey(), $secondAccount->getKey()])->oldest('id')->get()->map->getAttributes()->all();

    $this->artisan('fixed-assets:audit-linked-accounts', [
        '--company' => $context['company']->doc_num,
        '--asset' => $asset->doc_num,
    ])
        ->expectsOutputToContain('actual_duplicates=1 review_candidates=0 safe_empty_account_repairs=0 both_posted_blocked_cases=1')
        ->assertSuccessful();

    expect(fn () => app(FixedAssetDuplicateAccountRepairService::class)->reviewReadOnly(
        $context['company'],
        $asset->doc_num,
        [],
    ))->toThrow(DomainException::class, __('The reviewed case is blocked and cannot be applied automatically: :reason', ['reason' => '']));

    expect($asset->refresh()->getAttributes())->toBe($beforeAsset)
        ->and(Account::query()->whereIn('id', [$firstAccount->getKey(), $secondAccount->getKey()])->oldest('id')->get()->map->getAttributes()->all())->toBe($beforeAccounts)
        ->and(Account::query()->whereIn('id', [$firstAccount->getKey(), $secondAccount->getKey()])->count())->toBe(2)
        ->and(DB::table('journal_entry_lines')->whereIn('account_id', [$firstAccount->getKey(), $secondAccount->getKey()])->count())->toBe(2);
});
