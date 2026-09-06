<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\LedgerQueryService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDepreciation;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\FixedAssets\Services\FixedAssetBookValueService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetReportService;
use Modules\FixedAssets\Services\FixedAssetScheduleService;
use Modules\FixedAssets\Services\FixedAssetService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\ElectronicInvoicePayloadBuilder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{company: Company, branch: Branch, destinationBranch: Branch, sourceCostCenter: CostCenter, destinationCostCenter: CostCenter, period: FinancialPeriod, currency: Currency, category: Account, postingAccounts: Collection<int, Account>}
 */
function lifecycleFixedAssetContext(): array
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
    $category = Account::query()
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

    return compact('company', 'branch', 'destinationBranch', 'sourceCostCenter', 'destinationCostCenter', 'period', 'currency', 'category', 'postingAccounts');
}

function lifecycleFixedAssetActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    test()->actingAs($user);

    return $user;
}

/** @param array<string, mixed> $overrides */
function lifecycleFixedAsset(array $context, array $overrides = []): FixedAsset
{
    $date = $context['period']->from_date->copy()->addDays(9)->toDateString();
    $payload = [
        'asset_date' => $date,
        'asset_name' => 'Lifecycle Asset '.fake()->unique()->numberBetween(1000, 9999),
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'asset_group_account_doc_num' => $context['category']->doc_num,
        'credit_account_doc_num' => Account::query()->where('company_id', $context['company']->getKey())->where('is_postable', true)->whereNotIn('id', $context['postingAccounts']->modelKeys())->firstOrFail()->doc_num,
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

    if ($payload['entry_type'] === FixedAsset::EntryTypeOpeningAsset) {
        $payload['acquisition_date'] = $payload['purchase_date'] = $payload['operation_date'];
        $context['period']->forceFill(['allows_opening_entries' => true])->save();
    }
    $asset = app(FixedAssetService::class)->create($payload)['record'];
    if (($overrides['_unrecognized'] ?? false) || $asset->status === FixedAsset::StatusDraft || $asset->isDisposed()) {
        return $asset;
    }
    $recognitionDate = $asset->previous_depreciation_until_date?->copy()->max($context['period']->from_date) ?: $asset->operation_date ?: $asset->asset_date;

    return app(FixedAssetLifecycleService::class)->activate($asset, $recognitionDate->toDateString());
}

function assertInlineFixedAssetPdf(TestResponse $response, ?string $filename = null): void
{
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $contentDisposition = (string) $response->baseResponse->headers->get('Content-Disposition');
    expect($contentDisposition)->toStartWith('inline; filename="')
        ->and((string) $response->getContent())->toStartWith('%PDF-');

    if ($filename !== null) {
        expect($contentDisposition)->toBe('inline; filename="'.$filename.'"');
    }
}

test('opening assets establish controlled book values and non-depreciable assets never enter a run', function (): void {
    lifecycleFixedAssetActor(['fixed_assets.create', 'fixed_assets.depreciation.preview', 'fixed_assets.activate', 'fixed_assets.delete']);
    $context = lifecycleFixedAssetContext();
    $throughDate = $context['period']->from_date->copy()->addDays(8)->toDateString();
    $opening = lifecycleFixedAsset($context, [
        'asset_name' => 'Opening Extrusion Line',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '100000',
        'salvage_value' => '10000',
        'previous_depreciation' => '40000',
        'previous_depreciation_until_date' => $throughDate,
        'operation_date' => $context['period']->from_date->copy()->subYears(3)->toDateString(),
    ]);
    $land = lifecycleFixedAsset($context, [
        'asset_name' => 'Factory Land',
        'purchase_value' => '750000',
        'salvage_value' => '0',
        'previous_depreciation' => null,
        'depreciation_method' => null,
        'useful_life' => null,
        'annual_depreciation_rate' => null,
        'operation_date' => null,
        'is_depreciable' => false,
    ]);
    $draft = lifecycleFixedAsset($context, [
        'asset_name' => 'Draft Activation Asset',
        'status' => FixedAsset::StatusDraft,
    ]);
    $activated = app(FixedAssetLifecycleService::class)->activate($draft, $draft->operation_date->toDateString());

    $position = app(FixedAssetBookValueService::class)->position($opening);
    $schedule = app(FixedAssetScheduleService::class)->schedule($land);
    $preview = app(FixedAssetDepreciationService::class)->preview([
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $context['period']->from_date->copy()->endOfMonth()->toDateString(),
    ]);

    expect($position['acquisition_cost'])->toBe('100000.0000')
        ->and($position['accumulated_depreciation'])->toBe('40000.0000')
        ->and($position['net_book_value'])->toBe('60000.0000')
        ->and($position['remaining_depreciable_amount'])->toBe('50000.0000')
        ->and($opening->depreciation_start_date?->toDateString())->toBe($context['period']->from_date->copy()->subYears(3)->toDateString())
        ->and($schedule['rows'])->toBe([])
        ->and($activated->status)->toBe(FixedAsset::StatusActive)
        ->and($activated->capitalized_at)->not->toBeNull()
        ->and($activated->locked_at)->not->toBeNull()
        ->and($activated->costMovements()->first()->journalEntry->source_type)->toBe('fixed_asset_capitalization')
        ->and(collect($preview['excluded'])->pluck('asset.id'))->toContain($land->getKey())
        ->and(FixedAssetDepreciation::query()->where('fixed_asset_id', $land->getKey())->exists())->toBeFalse();

    expect(fn () => app(FixedAssetService::class)->delete($activated))->toThrow(DomainException::class);
});

test('depreciation posts sequential balanced source journals with effective transfer dimensions and ordered reversals', function (): void {
    lifecycleFixedAssetActor(['fixed_assets.create', 'fixed_assets.transfer', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post', 'fixed_assets.depreciation.reverse']);
    $context = lifecycleFixedAssetContext();
    $asset = lifecycleFixedAsset($context, [
        'asset_name' => 'Depreciation Extrusion Line',
        'source_type' => 'legacy_import',
        'source_id' => 901,
        'source_doc_num' => 'PINV-LINE-901',
    ]);
    $postingDate = $context['period']->from_date->copy()->endOfMonth()->toDateString();

    expect(JournalEntry::query()->count())->toBe(1)
        ->and($asset->source_doc_num)->toBe('PINV-LINE-901')
        ->and($asset->base_acquisition_value)->toBe('120000.0000');

    $firstRun = app(FixedAssetDepreciationService::class)->post([
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $postingDate,
        'asset_doc_nums' => [$asset->doc_num],
    ]);
    $firstLine = $firstRun->lines->firstOrFail();
    $journal = $firstRun->journalEntry()->with('lines')->firstOrFail();
    $debit = $journal->lines->sum(fn ($row): float => (float) $row->debit_amount);
    $credit = $journal->lines->sum(fn ($row): float => (float) $row->credit_amount);

    expect($firstRun->status)->toBe('posted')
        ->and($journal->source_type)->toBe('fixed_asset_depreciation_run')
        ->and($journal->is_system_generated)->toBeTrue()
        ->and($journal->is_posted)->toBeTrue()
        ->and($debit)->toEqualWithDelta($credit, 0.0001)
        ->and((float) $firstLine->period_depreciation)->toBeGreaterThan(0)
        ->and($firstLine->period_start->toDateString())->toBe($asset->depreciation_start_date->toDateString())
        ->and((float) $firstLine->closing_net_book_value)->toBeGreaterThanOrEqual((float) $asset->salvage_value)
        ->and($journal->lines->every(fn ($row): bool => (int) $row->branch_id === (int) $context['branch']->getKey()))->toBeTrue()
        ->and($journal->lines->every(fn ($row): bool => (int) $row->cost_center_id === (int) $context['sourceCostCenter']->getKey()))->toBeTrue();

    expect(fn () => app(FixedAssetDepreciationService::class)->post([
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $postingDate,
        'asset_doc_nums' => [$asset->doc_num],
    ]))->toThrow(DomainException::class);

    $secondPostingDate = $context['period']->from_date->copy()->addMonth()->endOfMonth()->toDateString();
    app(FixedAssetLifecycleService::class)->transfer($asset->refresh(), [
        'movement_date' => $context['period']->from_date->copy()->addMonth()->startOfMonth()->toDateString(),
        'destination_branch_doc_num' => $context['destinationBranch']->doc_num,
        'destination_cost_center_doc_num' => $context['destinationCostCenter']->doc_num,
        'destination_location_address' => 'Injection Hall B',
        'reason' => 'Production line relocation',
    ]);
    $secondRun = app(FixedAssetDepreciationService::class)->post([
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $secondPostingDate,
        'asset_doc_nums' => [$asset->doc_num],
    ]);
    $secondLine = $secondRun->lines->firstOrFail();

    expect($secondLine->accumulated_before)->toBe($firstLine->accumulated_after)
        ->and($secondLine->branch_id)->toBe($context['destinationBranch']->getKey())
        ->and($secondLine->cost_center_id)->toBe($context['destinationCostCenter']->getKey())
        ->and($firstLine->refresh()->branch_id)->toBe($context['branch']->getKey())
        ->and($firstLine->cost_center_id)->toBe($context['sourceCostCenter']->getKey());

    expect(fn () => app(FixedAssetDepreciationService::class)->reverse($firstRun, 'Incorrect period selection'))
        ->toThrow(DomainException::class);

    app(FixedAssetDepreciationService::class)->reverse($secondRun, 'Reverse latest period first');
    $reversed = app(FixedAssetDepreciationService::class)->reverse($firstRun, 'Incorrect period selection');

    expect($reversed->status)->toBe('reversed')
        ->and($reversed->reversal_journal_entry_id)->not->toBeNull()
        ->and($firstLine->refresh()->status)->toBe('reversed')
        ->and(app(FixedAssetBookValueService::class)->position($asset->refresh())['accumulated_depreciation'])->toBe('0.0000');
});

test('financial period locking protects depreciation disposal and reversals from partial state', function (): void {
    lifecycleFixedAssetActor([
        'fixed_assets.create', 'fixed_assets.dispose', 'fixed_assets.disposal.reverse',
        'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post', 'fixed_assets.depreciation.reverse',
    ]);
    $context = lifecycleFixedAssetContext();
    $postedAsset = lifecycleFixedAsset($context, ['asset_name' => 'Open Period Depreciation Asset']);
    $blockedDepreciationAsset = lifecycleFixedAsset($context, ['asset_name' => 'Closed Period Depreciation Asset']);
    $blockedSaleAsset = lifecycleFixedAsset($context, [
        'asset_name' => 'Closed Period Sale Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '100000',
        'salvage_value' => '0',
        'previous_depreciation' => '70000',
        'previous_depreciation_until_date' => $context['period']->from_date->copy()->subDay()->toDateString(),
        'operation_date' => $context['period']->from_date->copy()->subYears(3)->toDateString(),
    ]);
    $postedSaleAsset = lifecycleFixedAsset($context, [
        'asset_name' => 'Open Period Sale Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '100000',
        'salvage_value' => '0',
        'previous_depreciation' => '70000',
        'previous_depreciation_until_date' => $context['period']->from_date->copy()->subDay()->toDateString(),
        'operation_date' => $context['period']->from_date->copy()->subYears(3)->toDateString(),
    ]);
    $postingDate = $context['period']->from_date->copy()->endOfMonth()->toDateString();
    $run = app(FixedAssetDepreciationService::class)->post([
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $postingDate,
        'asset_doc_nums' => [$postedAsset->doc_num],
    ]);
    $postedSale = app(FixedAssetLifecycleService::class)->dispose($postedSaleAsset, [
        'disposal_date' => $context['period']->from_date->copy()->addDays(20)->toDateString(),
        'disposition_type' => FixedAssetDisposal::TypeSale,
        'proceeds' => '35000',
        'proceeds_account_doc_num' => $context['postingAccounts'][4]->doc_num,
        'reason' => 'Open period sale verification',
    ]);
    $journalCount = JournalEntry::query()->count();
    $runCount = $run::query()->count();
    $blockedNetValue = $blockedDepreciationAsset->net_value;
    $context['period']->forceFill(['is_closed' => true])->save();

    $this->post(route('admin.fixed-assets.depreciation.post'), [
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $postingDate,
        'asset_doc_nums' => [$blockedDepreciationAsset->doc_num],
    ])->assertSessionHasErrors(['depreciation' => __('journal_entries.messages.period_closed')]);

    $this->post(route('admin.fixed-assets.lifecycle.dispose', $blockedSaleAsset), [
        'disposal_date' => $context['period']->from_date->copy()->addDays(20)->toDateString(),
        'disposition_type' => FixedAssetDisposal::TypeSale,
        'proceeds' => '35000',
        'proceeds_account_doc_num' => $context['postingAccounts'][4]->doc_num,
        'reason' => 'Closed period sale attempt',
    ])->assertSessionHasErrors(['disposal' => __('journal_entries.messages.period_closed')]);

    $this->post(route('admin.fixed-assets.depreciation.reverse', $run), [
        'reason' => 'Closed period reversal attempt',
    ])->assertSessionHasErrors(['reversal' => __('journal_entries.messages.period_closed')]);

    $this->post(route('admin.fixed-assets.disposal.reverse', $postedSale), [
        'reason' => 'Closed period disposal reversal attempt',
    ])->assertSessionHasErrors(['reversal' => __('journal_entries.messages.period_closed')]);

    expect(fn () => app(JournalEntryService::class)->createPostedFromSource([
        'entry_date' => $postingDate,
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'branch_id' => $context['branch']->getKey(),
        'currency_id' => $context['currency']->getKey(),
        'exchange_rate' => '1.000000',
        'description' => 'Closed period shared journal guard',
        'notes' => null,
        'source_type' => 'fixed_asset_control_verification',
        'source_id' => 99002,
        'source_doc_num' => 'FAC-99002',
    ], [
        ['account_id' => $context['postingAccounts'][0]->getKey(), 'debit_amount' => '1', 'credit_amount' => '0', 'description' => 'Closed period debit'],
        ['account_id' => $context['postingAccounts'][1]->getKey(), 'debit_amount' => '0', 'credit_amount' => '1', 'description' => 'Closed period credit'],
    ]))->toThrow(DomainException::class, __('journal_entries.messages.period_closed'));

    expect($run::query()->count())->toBe($runCount)
        ->and($run->refresh()->status)->toBe($run::StatusPosted)
        ->and($run->reversal_journal_entry_id)->toBeNull()
        ->and($postedSale->refresh()->status)->toBe(FixedAssetDisposal::StatusPosted)
        ->and($postedSale->reversal_journal_entry_id)->toBeNull()
        ->and($postedSaleAsset->refresh()->status)->toBe(FixedAsset::StatusSold)
        ->and(FixedAssetDepreciation::query()->where('fixed_asset_id', $blockedDepreciationAsset->getKey())->exists())->toBeFalse()
        ->and($blockedDepreciationAsset->refresh()->net_value)->toBe($blockedNetValue)
        ->and(FixedAssetDisposal::query()->where('fixed_asset_id', $blockedSaleAsset->getKey())->exists())->toBeFalse()
        ->and($blockedSaleAsset->refresh()->status)->toBe(FixedAsset::StatusActive)
        ->and($blockedSaleAsset->disposed_at)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe($journalCount);
});

test('the final depreciation is capped exactly at residual value and the asset remains physically in service', function (): void {
    lifecycleFixedAssetActor(['fixed_assets.create', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post']);
    $context = lifecycleFixedAssetContext();
    $asset = lifecycleFixedAsset($context, [
        'asset_name' => 'Nearly Fully Depreciated Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '100000',
        'salvage_value' => '10000',
        'previous_depreciation' => '89999',
        'previous_depreciation_until_date' => $context['period']->from_date->copy()->subDay()->toDateString(),
        'operation_date' => $context['period']->from_date->copy()->subYears(5)->toDateString(),
    ]);
    $run = app(FixedAssetDepreciationService::class)->post([
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $context['period']->from_date->copy()->endOfMonth()->toDateString(),
        'asset_doc_nums' => [$asset->doc_num],
    ]);
    $line = $run->lines->firstOrFail();
    $nextPostingDate = $context['period']->from_date->copy()->addMonth()->endOfMonth()->toDateString();
    $preview = app(FixedAssetDepreciationService::class)->preview([
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $nextPostingDate,
        'asset_doc_nums' => [$asset->doc_num],
    ]);

    expect($line->period_depreciation)->toBe('1.0000')
        ->and($line->period_start->toDateString())->toBe($asset->previous_depreciation_until_date->copy()->addDay()->toDateString())
        ->and($line->closing_net_book_value)->toBe('10000.0000')
        ->and($asset->refresh()->net_value)->toBe('10000.0000')
        ->and($asset->status)->toBe(FixedAsset::StatusFullyDepreciated)
        ->and($asset->disposed_at)->toBeNull()
        ->and(collect($preview['excluded'])->pluck('asset.id'))->toContain($asset->getKey());

    expect(fn () => app(FixedAssetDepreciationService::class)->post([
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $nextPostingDate,
        'asset_doc_nums' => [$asset->doc_num],
    ]))->toThrow(DomainException::class);
});

test('transfers preserve history and sale and write-off post balanced gain and loss journals', function (): void {
    $actor = lifecycleFixedAssetActor(['fixed_assets.create', 'fixed_assets.transfer', 'fixed_assets.dispose', 'fixed_assets.disposal.reverse', 'fixed_assets.print']);
    $context = lifecycleFixedAssetContext();
    $saleAsset = lifecycleFixedAsset($context, [
        'asset_name' => 'Sale Asset',
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '100000',
        'salvage_value' => '0',
        'previous_depreciation' => '70000',
        'previous_depreciation_until_date' => $context['period']->from_date->copy()->subDay()->toDateString(),
        'operation_date' => $context['period']->from_date->copy()->subYears(3)->toDateString(),
    ]);
    $writeOffAsset = lifecycleFixedAsset($context, ['asset_name' => 'Write-Off Asset', 'purchase_value' => '800', 'salvage_value' => '0']);
    $date = $context['period']->from_date->copy()->addDays(20)->toDateString();

    $movement = app(FixedAssetLifecycleService::class)->transfer($saleAsset, [
        'movement_date' => $date,
        'destination_branch_doc_num' => $context['destinationBranch']->doc_num,
        'destination_cost_center_doc_num' => $context['destinationCostCenter']->doc_num,
        'destination_location_address' => 'Production Hall B',
        'reason' => 'Production layout change',
    ]);
    $saleAsset->forceFill(['status' => FixedAsset::StatusSuspended])->save();
    $sale = app(FixedAssetLifecycleService::class)->dispose($saleAsset->refresh(), [
        'disposal_date' => $date,
        'disposition_type' => FixedAssetDisposal::TypeSale,
        'proceeds' => '35000',
        'proceeds_account_doc_num' => $context['postingAccounts'][4]->doc_num,
        'reason' => 'Sold after replacement',
    ]);
    $writeOff = app(FixedAssetLifecycleService::class)->dispose($writeOffAsset, [
        'disposal_date' => $date,
        'disposition_type' => FixedAssetDisposal::TypeWriteOff,
        'proceeds' => '0',
        'reason' => 'Irreparable damage',
    ]);

    foreach ([$sale, $writeOff] as $disposal) {
        $journal = $disposal->journalEntry()->with('lines')->firstOrFail();
        expect((float) $journal->lines->sum('debit_amount'))->toEqualWithDelta((float) $journal->lines->sum('credit_amount'), 0.0001);
    }

    expect($movement)->toBeInstanceOf(FixedAssetMovement::class)
        ->and($movement->source_location_address)->toBeNull()
        ->and($movement->destination_location_address)->toBe('Production Hall B')
        ->and($saleAsset->refresh()->location_address)->toBe('Production Hall B')
        ->and($saleAsset->status)->toBe(FixedAsset::StatusSold)
        ->and((float) $sale->net_book_value)->toBe(30000.0)
        ->and((float) $sale->gain_amount)->toBe(5000.0)
        ->and((float) $sale->loss_amount)->toBe(0.0)
        ->and((float) $writeOff->loss_amount)->toBe(800.0)
        ->and($saleAsset->postedDepreciations()->count())->toBe(0)
        ->and($writeOffAsset->postedDepreciations()->count())->toBe(0)
        ->and($writeOffAsset->refresh()->status)->toBe(FixedAsset::StatusWrittenOff)
        ->and(app(FixedAssetScheduleService::class)->schedule($writeOffAsset)['rows'])->toBe([])
        ->and($sale->journalEntry->lines()->where('account_id', $context['postingAccounts'][2]->getKey())->where('credit_amount', '>', 0)->exists())->toBeTrue()
        ->and($writeOff->journalEntry->lines()->where('account_id', $context['postingAccounts'][3]->getKey())->where('debit_amount', '>', 0)->exists())->toBeTrue()
        ->and(CustomerInvoice::query()->count())->toBe(0)
        ->and($sale->journalEntry->source_type)->toBe('fixed_asset_disposal')
        ->and($sale->journalEntry->lines()->where('account_id', $context['postingAccounts'][4]->getKey())->where('debit_amount', '35000.0000')->count())->toBe(1)
        ->and($sale->journalEntry->lines()->where('account_id', $saleAsset->account_id)->where('credit_amount', '100000.0000')->count())->toBe(1)
        ->and($sale->journalEntry->lines()->where('account_id', $context['postingAccounts'][0]->getKey())->where('debit_amount', '70000.0000')->count())->toBe(1)
        ->and($sale->journalEntry->lines()->where('account_id', $context['postingAccounts'][2]->getKey())->where('credit_amount', '5000.0000')->count())->toBe(1);

    $reversed = app(FixedAssetLifecycleService::class)->reverseDisposal($sale, 'Sale was cancelled');
    expect($reversed->status)->toBe(FixedAssetDisposal::StatusReversed)
        ->and($saleAsset->refresh()->status)->toBe(FixedAsset::StatusSuspended);

    $this->actingAs($actor);
    assertInlineFixedAssetPdf(
        $this->get(route('admin.fixed-assets.prints.disposal', $writeOff)),
        'asset-write-off-'.$writeOff->doc_num.'.pdf',
    );
});

test('customer invoiced asset disposal clears NBV once without inventory or COGS and reverses canonically', function (string $sellingExpenses): void {
    lifecycleFixedAssetActor(['fixed_assets.create', 'fixed_assets.dispose', 'fixed_assets.disposal.reverse']);
    $context = lifecycleFixedAssetContext();
    $context['company']->forceFill(['vat_registration_number' => '200000001'])->save();
    $receivableClassification = AccountClassification::query()->where('code', 'accounts_receivable')->firstOrFail();
    $receivableParent = Account::query()
        ->where('company_id', $context['company']->getKey())
        ->where('account_classification_id', $receivableClassification->getKey())
        ->where('is_group', true)
        ->orderByDesc('level')
        ->firstOrFail();
    $customerAccount = Account::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'ACCOUNT-ASSET-BUYER-99001',
        'company_id' => $context['company']->getKey(),
        'account_code' => '112199901',
        'name' => 'Fixed Asset Buyer Receivable',
        'parent_id' => $receivableParent->getKey(),
        'level' => ((int) $receivableParent->level) + 1,
        'account_classification_id' => $receivableClassification->getKey(),
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $customer = Customer::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'CUST-ASSET-99001',
        'company_id' => $context['company']->getKey(),
        'account_id' => $customerAccount->getKey(),
        'name' => 'Fixed Asset Buyer',
        'tax_number' => '300000001',
        'status' => 'active',
    ]);
    $asset = lifecycleFixedAsset($context, [
        'asset_name' => 'Customer Invoiced Disposal Asset',
        'purchase_value' => '120000',
        'salvage_value' => '0',
    ]);
    $date = $context['period']->from_date->copy()->addDays(20)->toDateString();
    $inventoryCount = InventoryTransaction::query()->count();
    $cogsJournalCount = JournalEntry::query()->where('source_type', 'sales_delivery_cogs')->count();

    $disposal = app(FixedAssetLifecycleService::class)->dispose($asset, [
        'disposal_date' => $date,
        'disposition_type' => FixedAssetDisposal::TypeSale,
        'settlement_path' => FixedAssetDisposal::SettlementCustomerInvoice,
        'customer_doc_num' => $customer->doc_num,
        'proceeds' => '150000',
        'disposal_expenses' => $sellingExpenses, 'expenses_account_doc_num' => $asset->creditAccount->doc_num,
        'tax_rate' => '14',
        'due_date' => $date,
        'reason' => 'Sold through the canonical customer receivable path.',
    ]);
    $invoice = $disposal->customerInvoice()->with(['lines', 'journalEntry.lines'])->firstOrFail();
    $derecognition = $disposal->journalEntry()->with('lines')->firstOrFail();
    $gainLoss = $disposal->gainLossJournalEntry()->with('lines')->firstOrFail();
    $clearingAccountId = FixedAssetCategoryMapping::query()
        ->where('company_id', $context['company']->getKey())
        ->where('asset_group_account_id', $context['category']->getKey())
        ->valueOrFail('disposal_clearing_account_id');
    $clearingLines = DB::table('journal_entry_lines')
        ->whereIn('journal_entry_id', [$derecognition->getKey(), $invoice->journal_entry_id, $gainLoss->getKey(), $disposal->expenses_journal_entry_id])
        ->where('account_id', $clearingAccountId)
        ->selectRaw('coalesce(sum(debit_amount), 0) as debits, coalesce(sum(credit_amount), 0) as credits')
        ->first();

    expect($disposal->settlement_path)->toBe(FixedAssetDisposal::SettlementCustomerInvoice)
        ->and($disposal->net_book_value)->toBe('120000.0000')
        ->and($disposal->proceeds)->toBe('150000.0000')
        ->and($disposal->gain_amount)->toBe(bcsub('30000', $sellingExpenses, 4))
        ->and($disposal->tax_amount)->toBe('21000.0000')
        ->and($disposal->gross_proceeds)->toBe('171000.0000')
        ->and($invoice->posting_status)->toBe('posted')
        ->and($invoice->total_amount)->toBe('171000.0000')
        ->and($invoice->source_type)->toBe('fixed_asset_disposal')
        ->and($invoice->source_id)->toBe($disposal->getKey())
        ->and($invoice->lines->sole()->is_service)->toBeTrue()
        ->and((float) $derecognition->lines->firstWhere('account_id', $asset->account_id)?->credit_amount)->toBe(120000.0)
        ->and((float) $derecognition->lines->firstWhere('account_id', $clearingAccountId)?->debit_amount)->toBe(120000.0)
        ->and((float) $invoice->journalEntry->lines->firstWhere('account_id', $clearingAccountId)?->credit_amount)->toBe(150000.0)
        ->and((float) $gainLoss->lines->firstWhere('account_id', $clearingAccountId)?->debit_amount)->toBe(30000.0 - (float) $sellingExpenses)
        ->and((float) $clearingLines->debits)->toEqualWithDelta((float) $clearingLines->credits, 0.0001)
        ->and(InventoryTransaction::query()->count())->toBe($inventoryCount)
        ->and(JournalEntry::query()->where('source_type', 'sales_delivery_cogs')->count())->toBe($cogsJournalCount);

    config([
        'e_invoice.issuer_taxpayer_id' => null,
        'e_invoice.branch_code' => 'FACTORY-01',
    ]);
    $payload = app(ElectronicInvoicePayloadBuilder::class)->build($invoice);
    expect($payload['source'])->toBe(['type' => 'fixed_asset_disposal', 'document' => $disposal->doc_num])
        ->and($payload['lines'][0]['unit_code'])->toBe('EA')
        ->and($payload['lines'][0]['tax_code'])->toBe('VAT')
        ->and($payload['totals']['total'])->toBe('171000.0000');

    $reversed = app(FixedAssetLifecycleService::class)->reverseDisposal($disposal, 'Buyer cancelled before settlement.');
    expect($reversed->status)->toBe(FixedAssetDisposal::StatusReversed)
        ->and($reversed->reversal_journal_entry_id)->not->toBeNull()
        ->and($reversed->gain_loss_reversal_journal_entry_id)->not->toBeNull()
        ->and($invoice->fresh()->status)->toBe(CustomerInvoice::StatusReopened)
        ->and($invoice->fresh()->reversal_journal_entry_id)->not->toBeNull()
        ->and($asset->fresh()->status)->toBe(FixedAsset::StatusActive)
        ->and($asset->fresh()->disposed_at)->toBeNull()
        ->and(InventoryTransaction::query()->count())->toBe($inventoryCount);

    $cashClassification = AccountClassification::query()->where('code', 'cash')->firstOrFail();
    $cashParent = Account::query()
        ->where('company_id', $context['company']->getKey())
        ->where('account_code', '1111')
        ->firstOrFail();
    $cashAccount = Account::query()->create([
        'doc_number' => 9900002,
        'doc_num' => 'ACCOUNT-ASSET-CASH-9900002',
        'company_id' => $context['company']->getKey(),
        'account_code' => '111199902',
        'name' => 'Fixed Asset Collection Cash',
        'parent_id' => $cashParent->getKey(),
        'level' => ((int) $cashParent->level) + 1,
        'account_classification_id' => $cashClassification->getKey(),
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $cashbox = Cashbox::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'CASHBOX-ASSET-99001',
        'company_id' => $context['company']->getKey(),
        'branch_id' => $context['branch']->getKey(),
        'account_id' => $cashAccount->getKey(),
        'name' => 'Fixed Asset Collection Cashbox',
        'status' => 'active',
    ]);
    $collectedAsset = lifecycleFixedAsset($context, [
        'asset_name' => 'Collected Customer Disposal Asset',
        'purchase_value' => '120000',
        'salvage_value' => '0',
    ]);
    $collectedDisposal = app(FixedAssetLifecycleService::class)->dispose($collectedAsset, [
        'disposal_date' => $date,
        'disposition_type' => FixedAssetDisposal::TypeSale,
        'settlement_path' => FixedAssetDisposal::SettlementCustomerInvoice,
        'customer_doc_num' => $customer->doc_num,
        'proceeds' => '150000',
        'tax_rate' => '14',
        'due_date' => $date,
        'reason' => 'Customer invoiced and collected asset sale.',
    ]);
    $collectedInvoice = $collectedDisposal->customerInvoice()->with('paymentSchedules')->firstOrFail();
    app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'branch_id' => $context['branch']->getKey(),
        'customer_id' => $customer->getKey(),
        'receipt_date' => $date,
        'currency_id' => $context['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $cashbox->getKey(),
        'amount' => '171000',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $collectedInvoice->paymentSchedules->sole()->getKey(),
        'amount' => '171000',
    ]]);
    $customerLedger = app(LedgerQueryService::class)->accountLedger([
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'account_id' => $customerAccount->getKey(),
        'from_date' => $context['period']->from_date->toDateString(),
        'to_date' => $context['period']->to_date->toDateString(),
        'branch_id' => $context['branch']->getKey(),
    ]);
    expect($collectedInvoice->fresh()->paid_amount)->toBe('171000.0000')
        ->and($collectedInvoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and($customerLedger['ending'])->toBe(['debit' => '0.0000', 'credit' => '0.0000'])
        ->and(InventoryTransaction::query()->count())->toBe($inventoryCount)
        ->and(JournalEntry::query()->where('source_type', 'sales_delivery_cogs')->count())->toBe($cogsJournalCount);
})->with(['without selling expenses' => '0', 'with selling expenses' => '3000']);

test('opening asset reconciliation consumes canonical opening GL balances without another asset journal', function (): void {
    lifecycleFixedAssetActor(['fixed_assets.create', 'fixed_assets.reports']);
    $context = lifecycleFixedAssetContext();
    $openingAsset = lifecycleFixedAsset($context, [
        'asset_name' => 'Opening Reconciliation Asset', '_unrecognized' => true,
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '100000',
        'salvage_value' => '0',
        'previous_depreciation' => '40000',
        'previous_depreciation_until_date' => $context['period']->from_date->copy()->addDays(8)->toDateString(),
        'operation_date' => $context['period']->from_date->copy()->subYears(3)->toDateString(),
    ]);
    $unrelatedOpeningAsset = lifecycleFixedAsset($context, [
        'asset_name' => 'Unrelated Opening Reconciliation Asset', '_unrecognized' => true,
        'entry_type' => FixedAsset::EntryTypeOpeningAsset,
        'purchase_value' => '25000',
        'salvage_value' => '0',
        'previous_depreciation' => '5000',
        'previous_depreciation_until_date' => $context['period']->from_date->copy()->addDays(8)->toDateString(),
        'operation_date' => $context['period']->from_date->copy()->subYears(2)->toDateString(),
    ]);
    $mapping = FixedAssetCategoryMapping::query()->where('asset_group_account_id', $openingAsset->asset_group_account_id)->firstOrFail();
    $entryDate = $context['period']->from_date->copy()->addDays(8)->toDateString();

    $openingJournal = app(JournalEntryService::class)->createPostedFromSource([
        'entry_date' => $entryDate,
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'branch_id' => $context['branch']->getKey(),
        'currency_id' => $context['currency']->getKey(),
        'exchange_rate' => '1.000000',
        'description' => 'Canonical opening balances for fixed assets',
        'notes' => null,
        'source_type' => 'opening_balance',
        'source_id' => 99001,
        'source_doc_num' => 'OB-99001',
    ], [
        ['account_id' => $openingAsset->account_id, 'debit_amount' => '100000', 'credit_amount' => '0', 'branch_id' => $openingAsset->branch_id, 'cost_center_id' => $openingAsset->cost_center_id, 'description' => 'Opening asset cost'],
        ['account_id' => $mapping->accumulated_depreciation_account_id, 'debit_amount' => '0', 'credit_amount' => '40000', 'branch_id' => $openingAsset->branch_id, 'cost_center_id' => $openingAsset->cost_center_id, 'description' => 'Opening accumulated depreciation'],
        ['account_id' => $context['postingAccounts'][4]->getKey(), 'debit_amount' => '0', 'credit_amount' => '60000', 'description' => 'Opening equity offset'],
    ]);

    app(FixedAssetLifecycleService::class)->activate($openingAsset, $entryDate, $openingJournal->doc_num);
    $reconciliation = app(FixedAssetReportService::class)->report([
        'type' => FixedAssetReportService::Reconciliation,
        'asset_doc_num' => $openingAsset->doc_num,
    ]);
    $costRow = $reconciliation['rows']->firstWhere('account', $openingAsset->account->codeNameLabel());
    $accumulatedRow = $reconciliation['rows']->firstWhere('account', $mapping->accumulatedDepreciationAccount->codeNameLabel());

    expect($costRow)->not->toBeNull()
        ->and($costRow['subledger'])->toBe('100000.0000')
        ->and((float) $costRow['general_ledger'])->toEqualWithDelta(100000, 0.0001)
        ->and((float) $costRow['difference'])->toEqualWithDelta(0, 0.0001)
        ->and($accumulatedRow)->not->toBeNull()
        ->and($accumulatedRow['subledger'])->toBe('40000.0000')
        ->and((float) $accumulatedRow['general_ledger'])->toEqualWithDelta(40000, 0.0001)
        ->and((float) $accumulatedRow['difference'])->toEqualWithDelta(0, 0.0001)
        ->and($reconciliation['rows']->pluck('account'))->not->toContain($unrelatedOpeningAsset->account->codeNameLabel())
        ->and(JournalEntry::query()->where('source_type', 'opening_balance')->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', 'like', 'fixed_asset_opening%')->exists())->toBeFalse();
});

test('asset card reports print and export screens use the canonical lifecycle records', function (): void {
    $actor = lifecycleFixedAssetActor([
        'fixed_assets.create', 'fixed_assets.view', 'fixed_assets.transfer', 'fixed_assets.dispose',
        'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post', 'fixed_assets.reports',
        'fixed_assets.print', 'fixed_assets.export', 'fixed_assets.accounting.configure',
    ]);
    $context = lifecycleFixedAssetContext();
    $asset = lifecycleFixedAsset($context, ['asset_name' => 'Runtime Print Asset']);
    $date = $context['period']->from_date->copy()->addDays(20)->toDateString();
    $movement = app(FixedAssetLifecycleService::class)->transfer($asset, [
        'movement_date' => $date,
        'destination_branch_doc_num' => $context['branch']->doc_num,
        'destination_location_address' => 'Warehouse A',
        'reason' => 'Custody relocation',
    ]);
    $run = app(FixedAssetDepreciationService::class)->post([
        'financial_period_doc_num' => $context['period']->doc_num,
        'posting_date' => $context['period']->from_date->copy()->endOfMonth()->toDateString(),
        'asset_doc_nums' => [$asset->doc_num],
    ]);

    $register = app(FixedAssetReportService::class)->report(['type' => FixedAssetReportService::Register]);
    $depreciation = app(FixedAssetReportService::class)->report(['type' => FixedAssetReportService::Depreciation]);
    $movements = app(FixedAssetReportService::class)->report(['type' => FixedAssetReportService::Movements]);
    $reconciliation = app(FixedAssetReportService::class)->report(['type' => FixedAssetReportService::Reconciliation]);
    $periodReconciliation = $reconciliation['rows']->firstWhere('reconciliation_type', __('fixed_assets.reports.reconciliation_types.period_depreciation'));

    expect($register['rows']->pluck('asset'))->toContain($asset->doc_num)
        ->and($depreciation['rows'])->toHaveCount(1)
        ->and($depreciation['rows']->first()['period_depreciation'])->toBe($run->lines->firstOrFail()->period_depreciation)
        ->and($movements['rows']->pluck('document'))->toContain($movement->doc_num)
        ->and((float) $periodReconciliation['difference'])->toEqualWithDelta(0, 0.0001);

    $this->actingAs($actor);
    $this->get(route('admin.fixed-assets.lifecycle.show', $asset))->assertOk()->assertSee($asset->doc_num)->assertSee(__('fixed_assets.cycle.ledger'))->assertSee($movement->doc_num);
    $this->get(route('admin.fixed-assets.accounting.index'))->assertOk()->assertSee($context['category']->account_code);
    assertInlineFixedAssetPdf($this->get(route('admin.fixed-assets.prints.asset', $asset)), 'fixed-asset-'.$asset->doc_num.'.pdf');
    assertInlineFixedAssetPdf($this->get(route('admin.fixed-assets.prints.movement', $movement)), 'asset-transfer-'.$movement->doc_num.'.pdf');
    $this->get(route('admin.fixed-assets.depreciation.show', $run))->assertOk()->assertSee($run->doc_num);
    $this->get(route('admin.fixed-assets.depreciation.index'))->assertOk()->assertSee(__('fixed_assets.lifecycle.depreciation_policy', ['basis' => 365]));
    assertInlineFixedAssetPdf($this->get(route('admin.fixed-assets.depreciation.print', $run)), 'depreciation-run-'.$run->doc_num.'.pdf');
    $this->get(route('admin.fixed-assets.reports.index', ['type' => 'register']))->assertOk()->assertSee($asset->doc_num);
    $this->get(route('admin.fixed-assets.reports.index', ['type' => 'register', 'to_date' => $date]))
        ->assertOk()
        ->assertSee('value="'.app(DateFormatService::class)->formatDate($date, '').'"', false);
    assertInlineFixedAssetPdf($this->get(route('admin.fixed-assets.reports.print', ['type' => 'register'])), 'fixed-assets-register.pdf');

    foreach (FixedAssetReportService::types() as $reportType) {
        assertInlineFixedAssetPdf(
            $this->get(route('admin.fixed-assets.reports.pdf', ['type' => $reportType])),
            'fixed-assets-'.str_replace('_', '-', $reportType).'.pdf',
        );
    }

    $this->get(route('admin.fixed-assets.reports.excel', ['type' => 'register']))->assertOk();

    $actor->forceFill(['locale' => 'ar'])->save();
    assertInlineFixedAssetPdf($this->get(route('admin.fixed-assets.prints.asset', $asset)), 'fixed-asset-'.$asset->doc_num.'.pdf');
});

test('fixed asset PDF endpoints enforce print permission and company-scoped route binding', function (): void {
    $authorized = lifecycleFixedAssetActor(['fixed_assets.create', 'fixed_assets.view', 'fixed_assets.print']);
    $context = lifecycleFixedAssetContext();
    $asset = lifecycleFixedAsset($context, ['asset_name' => 'PDF Authorization Asset']);

    $blocked = lifecycleFixedAssetActor(['fixed_assets.view']);
    $this->actingAs($blocked);
    $this->get(route('admin.fixed-assets.prints.asset', $asset))->assertForbidden();

    $foreignCompany = Company::factory()->create(['name' => 'Foreign PDF Company']);
    $foreignAsset = $asset->replicate();
    $foreignAsset->forceFill([
        'company_id' => $foreignCompany->getKey(),
        'doc_number' => 999901,
        'doc_num' => 'FA-FOREIGN-PDF',
        'asset_name' => 'Foreign PDF Asset',
        'account_id' => null,
        'serial_number' => null,
    ])->save();

    $this->actingAs($authorized);
    $this->get(route('admin.fixed-assets.prints.asset', $foreignAsset))->assertNotFound();
});
