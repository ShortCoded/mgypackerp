<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDepreciationRun;
use Modules\FixedAssets\Services\FixedAssetCostMovementService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetLedgerService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetReportService;
use Modules\FixedAssets\Services\FixedAssetService;
use Modules\HR\Models\HrEmployee;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{company: Company, branch: Branch, destinationBranch: Branch, sourceCostCenter: CostCenter, destinationCostCenter: CostCenter, period: FinancialPeriod, currency: Currency, category: Account, postingAccounts: Collection<int, Account>}
 */
function coreFixedAssetContext(bool $populated = false): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(AccountClassificationsSeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->where('is_main', true)->firstOrFail();
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    session($session);
    test()->withSession($session);

    $root = app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::FixedAsset);
    $category = $populated ? app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::FixedAsset, 'Release Acceptance Assets') : Account::query()
        ->where('company_id', $company->getKey())
        ->where('parent_id', $root->getKey())
        ->where('is_group', true)
        ->where('is_postable', false)
        ->first() ?? app(BusinessPartnerAccountService::class)->createGroup(BusinessPartnerAccountService::FixedAsset, 'Lifecycle Assets');
    $postingAccounts = Account::query()
        ->where('company_id', $company->getKey())
        ->where('is_group', false)
        ->where('is_postable', true)
        ->where('status', 'active')
        ->whereNull('deleted_at')
        ->orderBy('id')
        ->take(5)
        ->get();

    expect($postingAccounts)->toHaveCount(5);
    foreach (['accumulated_depreciation', 'depreciation_expense', 'gain_on_asset_disposal', 'loss_on_asset_disposal'] as $index => $code) {
        $classification = AccountClassification::query()->where('code', $code)->firstOrFail();
        $parent = Account::query()->where('company_id', $company->getKey())->where('account_classification_id', $classification->getKey())->where('is_group', true)->first();
        $postingAccounts[$index] = Account::query()->create([
            'company_id' => $company->getKey(), 'doc_number' => 98000 + $index, 'doc_num' => 'ACC-9800'.$index,
            'account_code' => '9800'.$index, 'name' => $code, 'parent_id' => $parent?->getKey(),
            'account_classification_id' => $classification->getKey(), 'account_type' => $classification->account_type,
            'statement_type' => $classification->statement_type, 'normal_balance' => $classification->normal_balance,
            'is_group' => false, 'is_postable' => true, 'status' => 'active',
        ]);
    }

    $destinationBranch = Branch::query()->create([
        'doc_number' => 99002,
        'doc_num' => 'BR-99002',
        'company_id' => $company->getKey(),
        'name' => 'Lifecycle Destination Branch',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $sourceCostCenter = CostCenter::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 99001,
        'doc_num' => 'CC-99001',
        'cost_center_code' => '99001',
        'name' => 'Lifecycle Source Cost Center',
        'is_group' => false,
        'status' => 'active',
    ]);
    $destinationCostCenter = CostCenter::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 99002,
        'doc_num' => 'CC-99002',
        'cost_center_code' => '99002',
        'name' => 'Lifecycle Destination Cost Center',
        'is_group' => false,
        'status' => 'active',
    ]);

    FixedAssetCategoryMapping::query()->create([
        'company_id' => $company->getKey(),
        'asset_group_account_id' => $category->getKey(),
        'accumulated_depreciation_account_id' => $postingAccounts[0]->getKey(),
        'depreciation_expense_account_id' => $postingAccounts[1]->getKey(),
        'disposal_gain_account_id' => $postingAccounts[2]->getKey(),
        'disposal_loss_account_id' => $postingAccounts[3]->getKey(),
        'disposal_clearing_account_id' => $postingAccounts[4]->getKey(),
    ]);

    return ['report_filters' => $populated ? ['asset_group_account_doc_num' => $category->doc_num] : [], ...compact('company', 'branch', 'destinationBranch', 'sourceCostCenter', 'destinationCostCenter', 'period', 'currency', 'category', 'postingAccounts')];
}

function coreFixedAssetActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $number = max(1000, (int) User::withTrashed()->max('doc_number')) + 1;
    $user = User::factory()->create(['doc_number' => $number, 'doc_num' => 'User-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT)]);
    $user->givePermissionTo($permissions);
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    test()->actingAs($user);

    return $user;
}

function coreFixedAsset(array $context, array $overrides = []): FixedAsset
{
    $date = $context['period']->from_date->copy()->addDays(9)->toDateString();
    $payload = [
        'asset_date' => $date,
        'asset_name' => 'Lifecycle Asset '.fake()->unique()->numberBetween(1000, 9999),
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'asset_group_account_doc_num' => $context['category']->doc_num,
        'credit_account_doc_num' => $context['postingAccounts'][4]->doc_num,
        'branch_doc_num' => $context['branch']->doc_num,
        'cost_center_doc_num' => $context['sourceCostCenter']->doc_num,
        'description' => 'Lifecycle verification asset',
        'purchase_date' => $date,
        'acquisition_date' => $date,
        'operation_date' => $date,
        'status' => FixedAsset::StatusActive,
        'currency_doc_num' => $context['currency']->doc_num,
        'exchange_rate' => '1',
        'purchase_value' => '120000',
        'salvage_value' => '20000',
        'previous_depreciation' => '0',
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'useful_life' => '5',
        'annual_depreciation_rate' => '20',
        'is_depreciable' => true,
        ...$overrides,
    ];

    return app(FixedAssetService::class)->create($payload)['record'];
}

function coreRecognizedAsset(array $context, array $overrides = []): FixedAsset
{
    $asset = coreFixedAsset($context, $overrides);

    return app(FixedAssetLifecycleService::class)->activate($asset, ($asset->previous_depreciation_until_date ?: $asset->operation_date)->toDateString());
}

function corePostMonth(array $context, FixedAsset $asset, int $month = 0, array $extra = []): FixedAssetDepreciationRun
{
    return app(FixedAssetDepreciationService::class)->post(['financial_period_doc_num' => $context['period']->doc_num, 'posting_date' => $context['period']->from_date->copy()->addMonths($month)->endOfMonth()->toDateString(), 'asset_doc_nums' => [$asset->doc_num], ...$extra]);
}

function coreCompleteExistingCycle(array $context): void
{
    $context['period']->forceFill(['allows_opening_entries' => true])->save();
    $start = $context['period']->from_date->copy();
    $original = $start->copy()->subYear()->toDateString();
    $asset = coreRecognizedAsset($context, ['entry_type' => 'opening_asset', 'acquisition_date' => $original, 'purchase_date' => $original, 'operation_date' => $original, 'depreciation_start_date' => $original, 'previous_depreciation' => '20000', 'previous_depreciation_until_date' => $start->toDateString()]);
    $opening = $asset->costMovements()->firstOrFail();
    expect($opening->opening_balance_id)->not->toBeNull();
    $depreciation = app(FixedAssetDepreciationService::class);
    $first = corePostMonth($context, $asset);
    $firstAmount = $first->lines->first()->period_depreciation;
    $depreciation->reverse($first, 'Acceptance reversal');
    expect($first->lines()->first()->status)->toBe('reversed');
    expect(corePostMonth($context, $asset)->lines->first()->period_depreciation)->toBe($firstAmount);
    expect(fn () => corePostMonth($context, $asset))->toThrow(DomainException::class);
    $addition = app(FixedAssetCostMovementService::class)->addition($asset, ['submission_key' => (string) Str::uuid(), 'movement_date' => $start->copy()->addMonth()->toDateString(), 'amount' => '24000', 'description' => 'Capacity improvement', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'revised_useful_life' => '3']);
    $next = corePostMonth($context, $asset, 1);
    expect($next->lines->first()->acquisition_cost)->toBe('144000.0000');
    expect(fn () => app(FixedAssetCostMovementService::class)->reverse($addition, 'Invalid earlier reversal'))->toThrow(DomainException::class);
    $lifecycle = app(FixedAssetLifecycleService::class);
    $date = $start->copy()->addMonths(2)->toDateString();
    $lifecycle->transfer($asset, ['movement_date' => $date, 'destination_branch_doc_num' => $context['destinationBranch']->doc_num, 'destination_cost_center_doc_num' => $context['destinationCostCenter']->doc_num, 'reason' => 'Acceptance transfer']);
    foreach ([1, 2] as $number) {
        $employee = HrEmployee::query()->create(['doc_number' => 99700 + $number, 'doc_num' => 'EMP-9970'.$number, 'company_id' => $asset->company_id, 'branch_id' => $context['destinationBranch']->id, 'full_name' => 'Custodian '.$number, 'name' => 'Custodian '.$number, 'status' => 'active']);
        $lifecycle->custody($asset->fresh(), ['movement_date' => $date, 'custodian_doc_num' => $employee->doc_num, 'reason' => 'Acceptance handover']);
    }
    $sale = ['disposal_date' => $start->copy()->addMonths(2)->addDays(4)->toDateString(), 'disposition_type' => 'sale', 'proceeds' => '130000', 'disposal_expenses' => '3000', 'proceeds_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'expenses_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'reason' => 'Acceptance sale'];
    $preview = $lifecycle->previewDisposal($asset->fresh(), $sale);
    $disposal = $lifecycle->dispose($asset->fresh(), $sale);
    expect($disposal->net_proceeds)->toBe('127000.0000')->and($disposal->gain_amount)->toBe(bcsub('127000', $preview['net_book_value'], 4));
    expect(fn () => $lifecycle->dispose($asset->fresh(), $sale))->toThrow(DomainException::class);
    $reports = app(FixedAssetReportService::class);
    foreach ([$start->copy()->endOfMonth()->toDateString(), $start->copy()->addMonth()->endOfMonth()->toDateString(), $sale['disposal_date']] as $asOf) {
        foreach ([[], ['branch_doc_num' => $context['branch']->doc_num], ['cost_center_doc_num' => $context['sourceCostCenter']->doc_num], ['branch_doc_num' => $context['destinationBranch']->doc_num, 'cost_center_doc_num' => $context['destinationCostCenter']->doc_num]] as $filters) {
            foreach ($reports->report([...($context['report_filters'] ?? []), 'type' => 'reconciliation', 'to_date' => $asOf, ...$filters])['rows'] as $row) {
                expect(bccomp($row['difference'], '0', 4))->toBe(0, $asOf.' '.$row['account']);
            }
        }
    }
    $lifecycle->reverseDisposal($disposal, 'Acceptance return');
    expect(fn () => $lifecycle->reverseDisposal($disposal, 'Duplicate reversal'))->toThrow(DomainException::class);
    $ledger = app(FixedAssetLedgerService::class)->history($asset->fresh());
    foreach (['opening', 'depreciation', 'depreciation_reversal', 'addition', 'transfer', 'custody', 'disposal', 'disposal_reversal'] as $type) {
        expect($ledger->where('type', $type))->not->toBeEmpty();
    }
    foreach (JournalEntry::with('lines')->get() as $journal) {
        expect($journal->is_posted)->toBeTrue()
            ->and($journal->lines->reduce(fn (string $balance, $line): string => bcadd($balance, bcsub($line->debit_amount, $line->credit_amount, 4), 4), '0.0000'))->toBe('0.0000');
    }
    test()->get(route('admin.fixed-assets.lifecycle.show', $asset))->assertOk()->assertSee($opening->doc_num)->assertSee($addition->doc_num)->assertSee($disposal->doc_num);
    foreach ($reports->report([...($context['report_filters'] ?? []), 'type' => 'reconciliation'])['rows'] as $row) {
        expect(bccomp($row['difference'], '0', 4))->toBe(0);
    }
}

function coreCompleteNewCycle(array $context): void
{
    $asset = coreRecognizedAsset($context);
    $run = corePostMonth($context, $asset);
    expect($asset->previous_depreciation)->toBe('0.0000')->and($asset->hasPostedRecognition())->toBeTrue()->and($run->journalEntry)->not->toBeNull();
    test()->get(route('admin.fixed-assets.lifecycle.show', $asset))->assertOk()->assertSee($asset->account->codeNameLabel())->assertSee($run->doc_num);
}
