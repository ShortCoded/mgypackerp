<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\AccountService;
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
use Spatie\Activitylog\Models\Activity;

/** @return array{company: Company, branch: Branch, period: FinancialPeriod, currency: Currency, categories: array{Account, Account}, counter: Account} */
function remediationContext(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(AccountClassificationsSeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->oldest('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->oldest('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->oldest('id')->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->where('status', 'active')->orderByDesc('is_main')->firstOrFail();
    $user = User::factory()->create();
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);

    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    $operatingContext = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    session($operatingContext);
    test()->withSession($operatingContext);
    $root = Account::query()->where('company_id', $company->getKey())->where('account_code', '121')->firstOrFail();
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
            'Remediation Category '.(count($categories) + 1),
        );
    }

    $counter = Account::query()
        ->where('company_id', $company->getKey())
        ->whereNotIn('id', collect($categories)->pluck('id')->all())
        ->where('is_group', false)
        ->where('is_postable', true)
        ->where('status', 'active')
        ->oldest('id')
        ->firstOrFail();
    FixedAssetCategoryMapping::query()->updateOrCreate([
        'company_id' => $company->getKey(),
        'asset_group_account_id' => $categories[1]->getKey(),
    ], [
        'accumulated_depreciation_account_id' => $counter->getKey(),
        'depreciation_expense_account_id' => $counter->getKey(),
        'disposal_gain_account_id' => $counter->getKey(),
        'disposal_loss_account_id' => $counter->getKey(),
        'disposal_clearing_account_id' => $counter->getKey(),
    ]);

    return compact('company', 'branch', 'period', 'currency', 'categories', 'counter');
}

/** @return array{asset: FixedAsset, old: Account, new: Account} */
function remediationDuplicate(array $context, int $number, string $name, bool $recordActivity = true): array
{
    $old = app(AccountService::class)->createChildFromParent($context['categories'][0], [
        'name' => $name.' historical',
        'classification_code' => 'fixed_assets',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $new = app(AccountService::class)->createChildFromParent($context['categories'][1], [
        'name' => $name,
        'classification_code' => 'fixed_assets',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $asset = FixedAsset::query()->create([
        'doc_number' => 900000 + $number,
        'doc_num' => 'FA-'.(900000 + $number),
        'company_id' => $context['company']->getKey(),
        'branch_id' => $context['branch']->getKey(),
        'period_id' => $context['period']->getKey(),
        'currency_id' => $context['currency']->getKey(),
        'account_id' => $new->getKey(),
        'asset_group_account_id' => $context['categories'][1]->getKey(),
        'credit_account_id' => $context['counter']->getKey(),
        'asset_date' => $context['period']->from_date,
        'asset_name' => $name,
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'purchase_date' => $context['period']->from_date,
        'operation_date' => $context['period']->from_date,
        'purchase_value' => '1000.0000',
        'base_acquisition_value' => '1000.0000',
        'salvage_value' => '0.0000',
        'exchange_rate' => '1.000000',
        'previous_depreciation' => '0.0000',
        'net_value' => '1000.0000',
        'useful_life' => '10.00',
        'is_depreciable' => true,
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'status' => FixedAsset::StatusActive,
    ]);

    if ($recordActivity) {
        Activity::query()->create([
            'log_name' => 'fixed_assets',
            'description' => 'fixed_assets.update.success',
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->getKey(),
            'event' => 'fixed_assets.update',
            'properties' => ['changes' => ['account_id' => ['old' => $old->getKey(), 'new' => $new->getKey()]]],
            'company_id' => $asset->company_id,
            'module' => 'fixed_assets',
            'action' => 'fixed_assets.update',
            'status' => 'success',
        ]);
    }

    return compact('asset', 'old', 'new');
}

function remediationPostedJournal(array $context, int $number, FixedAsset $asset, Account $account, string $side = 'debit', string $amount = '1000.0000'): int
{
    $journalEntryId = DB::table('journal_entries')->insertGetId([
        'doc_number' => 910000 + $number,
        'doc_num' => 'JE-'.(910000 + $number),
        'entry_date' => $context['period']->from_date->toDateString(),
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'currency_id' => $context['currency']->getKey(),
        'exchange_rate' => '1.000000',
        'source_type' => 'fixed_asset_capitalization',
        'source_id' => $number,
        'source_doc_num' => $asset->doc_num,
        'status' => 'posted',
        'is_posted' => true,
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $accountDebit = $side === 'debit' ? $amount : '0.0000';
    $accountCredit = $side === 'credit' ? $amount : '0.0000';

    DB::table('journal_entry_lines')->insert([
        [
            'journal_entry_id' => $journalEntryId,
            'line_no' => 1,
            'account_id' => $account->getKey(),
            'debit_amount' => $accountDebit,
            'credit_amount' => $accountCredit,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'journal_entry_id' => $journalEntryId,
            'line_no' => 2,
            'account_id' => $context['counter']->getKey(),
            'debit_amount' => $accountCredit,
            'credit_amount' => $accountDebit,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    return $journalEntryId;
}

/** @return array<string, int|string> */
function remediationExpectations(array $pair, array $case): array
{
    $canonicalId = (int) $case['recommended_canonical_account_id'];
    $duplicateId = (int) collect($case['duplicate_account_ids'])->sole();
    $accounts = collect($case['accounts'])->keyBy('id');
    $canonical = $accounts->get($canonicalId);
    $duplicate = $accounts->get($duplicateId);

    return [
        'canonical_account_id' => $canonicalId,
        'duplicate_account_id' => $duplicateId,
        'expected_current_account_id' => (int) $pair['asset']->account_id,
        'expected_parent_account_id' => (int) $pair['asset']->asset_group_account_id,
        'expected_canonical_journal_count' => (int) $canonical['journal_line_count'],
        'expected_canonical_debit' => $canonical['total_debit'],
        'expected_canonical_credit' => $canonical['total_credit'],
        'expected_canonical_balance' => $canonical['balance'],
        'expected_duplicate_journal_count' => (int) $duplicate['journal_line_count'],
        'expected_duplicate_debit' => $duplicate['total_debit'],
        'expected_duplicate_credit' => $duplicate['total_credit'],
        'expected_duplicate_balance' => $duplicate['balance'],
    ];
}

test('discovery separates review-only similarity and classifies all repair outcomes from stable evidence', function (): void {
    $context = remediationContext();
    $caseA = remediationDuplicate($context, 1, 'Case A Asset');
    remediationPostedJournal($context, 1, $caseA['asset'], $caseA['old']);
    $caseB = remediationDuplicate($context, 2, 'Case B Asset');
    remediationPostedJournal($context, 2, $caseB['asset'], $caseB['new']);
    $caseC = remediationDuplicate($context, 3, 'Case C Asset', false);
    Activity::query()->create([
        'log_name' => 'accounts',
        'description' => 'accounts.create.success',
        'subject_type' => $caseC['old']->getMorphClass(),
        'subject_id' => $caseC['old']->getKey(),
        'event' => 'accounts.create',
        'properties' => [
            'source_type' => 'fixed_asset',
            'source_id' => $caseC['asset']->getKey(),
            'source_doc_num' => $caseC['asset']->doc_num,
        ],
        'company_id' => $context['company']->getKey(),
        'module' => 'accounts',
        'action' => 'accounts.create',
        'status' => 'success',
    ]);
    $caseD = remediationDuplicate($context, 4, 'Case D Asset');
    remediationPostedJournal($context, 4, $caseD['asset'], $caseD['old'], amount: '400.0000');
    remediationPostedJournal($context, 5, $caseD['asset'], $caseD['new'], amount: '600.0000');
    $caseE = remediationDuplicate($context, 5, 'Case E Asset');
    app(AccountService::class)->createChildFromParent($caseE['old'], [
        'name' => 'Unexpected child reference',
        'classification_code' => 'fixed_assets',
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $reviewOnly = remediationDuplicate($context, 6, 'Review Only Asset', false);
    $reviewOnly['old']->forceFill(['name' => $reviewOnly['asset']->asset_name])->save();

    $result = app(FixedAssetDuplicateAccountAuditService::class)->audit($context['company']);
    $cases = collect($result['cases'])->keyBy('asset_doc_num');

    expect($result['actual_duplicates'])->toBe(5)
        ->and($result['review_candidates'])->toBe(1)
        ->and($result['safe_empty_account_repairs'])->toBe(3)
        ->and($result['both_posted_blocked_cases'])->toBe(1)
        ->and($result['other_reference_blocked_cases'])->toBe(1)
        ->and($cases[$caseA['asset']->doc_num]['classification'])->toBe(FixedAssetDuplicateAccountAuditService::ClassificationOldPostedNewEmpty)
        ->and($cases[$caseB['asset']->doc_num]['classification'])->toBe(FixedAssetDuplicateAccountAuditService::ClassificationOldEmptyNewPosted)
        ->and($cases[$caseC['asset']->doc_num]['classification'])->toBe(FixedAssetDuplicateAccountAuditService::ClassificationBothEmpty)
        ->and($cases[$caseD['asset']->doc_num]['classification'])->toBe(FixedAssetDuplicateAccountAuditService::ClassificationBothPosted)
        ->and($cases[$caseD['asset']->doc_num]['financial_review_pack']['treatment']['closed_period_rule'])->toBe('Do not rewrite closed-period history.')
        ->and($cases[$caseE['asset']->doc_num]['classification'])->toBe(FixedAssetDuplicateAccountAuditService::ClassificationOtherReferences)
        ->and($cases[$reviewOnly['asset']->doc_num]['is_actual_duplicate'])->toBeFalse()
        ->and($cases[$reviewOnly['asset']->doc_num]['detection_confidence'])->toBe('review_only');

    $oldA = collect($cases[$caseA['asset']->doc_num]['accounts'])->firstWhere('id', $caseA['old']->getKey());
    expect($oldA['posted_journal_line_count'])->toBe(1)
        ->and($oldA['posted_debit_total'])->toBe('1000.0000')
        ->and($oldA['posted_credit_total'])->toBe('0.0000')
        ->and($oldA['posted_balance'])->toBe('1000.0000')
        ->and($oldA['full_path'])->toContain('Case A Asset historical')
        ->and($oldA['referencing_source_documents'])->toHaveCount(1)
        ->and($oldA['all_database_references'])->not->toBeEmpty();
});

test('manifest review is read only and apply requires maintenance acknowledgement token and unchanged state', function (): void {
    $context = remediationContext();
    $pair = remediationDuplicate($context, 10, 'Manifest Asset');
    $journalId = remediationPostedJournal($context, 10, $pair['asset'], $pair['old']);
    $audit = app(FixedAssetDuplicateAccountAuditService::class)->audit($context['company'], $pair['asset']->doc_num);
    $expectations = remediationExpectations($pair, $audit['cases'][0]);
    $beforeLines = DB::table('journal_entry_lines')->where('journal_entry_id', $journalId)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    $review = app(FixedAssetDuplicateAccountRepairService::class)->reviewReadOnly($context['company'], $pair['asset']->doc_num, $expectations);

    expect($review['review_token'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($review['manifest']['classification'])->toBe(FixedAssetDuplicateAccountAuditService::ClassificationOldPostedNewEmpty)
        ->and($review['manifest']['journal_state']['journals'][0]['balanced'])->toBeTrue()
        ->and($pair['new']->refresh()->trashed())->toBeFalse();

    $arguments = [
        '--company' => $context['company']->doc_num,
        '--asset' => $pair['asset']->doc_num,
        '--canonical-account-id' => $expectations['canonical_account_id'],
        '--duplicate-account-id' => $expectations['duplicate_account_id'],
        '--expected-current-account-id' => $expectations['expected_current_account_id'],
        '--expected-parent-account-id' => $expectations['expected_parent_account_id'],
        '--expected-canonical-journal-count' => $expectations['expected_canonical_journal_count'],
        '--expected-canonical-debit' => $expectations['expected_canonical_debit'],
        '--expected-canonical-credit' => $expectations['expected_canonical_credit'],
        '--expected-canonical-balance' => $expectations['expected_canonical_balance'],
        '--expected-duplicate-journal-count' => $expectations['expected_duplicate_journal_count'],
        '--expected-duplicate-debit' => $expectations['expected_duplicate_debit'],
        '--expected-duplicate-credit' => $expectations['expected_duplicate_credit'],
        '--expected-duplicate-balance' => $expectations['expected_duplicate_balance'],
        '--apply' => true,
        '--review-token' => $review['review_token'],
        '--production-ack' => $review['production_acknowledgement'],
    ];

    $this->artisan('fixed-assets:repair-duplicate-account', $arguments)
        ->expectsOutputToContain('Apply requires Laravel maintenance mode')
        ->assertFailed();

    app()->maintenanceMode()->activate([]);

    try {
        $this->artisan('fixed-assets:repair-duplicate-account', [
            ...$arguments,
            '--production-ack' => 'wrong',
        ])->expectsOutputToContain('acknowledgement does not match')->assertFailed();

        $this->artisan('fixed-assets:repair-duplicate-account', $arguments)
            ->expectsOutputToContain('repair committed successfully')
            ->assertSuccessful();

        $this->artisan('fixed-assets:repair-duplicate-account', $arguments)
            ->expectsOutputToContain('already applied')
            ->assertSuccessful();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect((int) $pair['asset']->refresh()->account_id)->toBe((int) $pair['old']->getKey())
        ->and((int) $pair['old']->refresh()->parent_id)->toBe((int) $context['categories'][1]->getKey())
        ->and($pair['old']->status)->toBe('active')
        ->and(Account::withTrashed()->findOrFail($pair['new']->getKey())->trashed())->toBeTrue()
        ->and(Account::withTrashed()->findOrFail($pair['new']->getKey())->status)->toBe('inactive')
        ->and(DB::table('journal_entry_lines')->where('journal_entry_id', $journalId)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($beforeLines)
        ->and(Activity::query()->where('subject_type', $pair['asset']->getMorphClass())->where('subject_id', $pair['asset']->getKey())->where('event', 'duplicate_account_repair')->count())->toBe(1);
});

test('apply refuses a reviewed manifest after financial state drift', function (): void {
    $context = remediationContext();
    $pair = remediationDuplicate($context, 20, 'Drift Asset');
    remediationPostedJournal($context, 20, $pair['asset'], $pair['old']);
    $audit = app(FixedAssetDuplicateAccountAuditService::class)->audit($context['company'], $pair['asset']->doc_num);
    $expectations = remediationExpectations($pair, $audit['cases'][0]);
    $review = app(FixedAssetDuplicateAccountRepairService::class)->reviewReadOnly($context['company'], $pair['asset']->doc_num, $expectations);
    remediationPostedJournal($context, 21, $pair['asset'], $pair['old'], amount: '25.0000');
    app()->maintenanceMode()->activate([]);

    try {
        expect(fn () => app(FixedAssetDuplicateAccountRepairService::class)->apply(
            $context['company'],
            $pair['asset']->doc_num,
            $expectations,
            $review['review_token'],
            $review['production_acknowledgement'],
        ))->toThrow(DomainException::class, __('The :role journal-line count differs from the expected value.', ['role' => 'canonical']))
            ->and((int) $pair['asset']->refresh()->account_id)->toBe((int) $pair['new']->getKey())
            ->and($pair['new']->refresh()->trashed())->toBeFalse();
    } finally {
        app()->maintenanceMode()->deactivate();
    }
});
