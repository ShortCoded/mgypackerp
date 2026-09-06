<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Services\FixedAssetBookValueService;
use Modules\FixedAssets\Services\FixedAssetCostMovementService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;

uses(RefreshDatabase::class);
require_once dirname(__DIR__).'/FixedAssetCycleSupport.php';

beforeEach(function (): void {
    coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post', 'fixed_assets.accounting.configure']);
});

function prerequisitesUniqueChart(array $context): void
{
    Account::query()->where('company_id', $context['company']->id)->where('is_group', false)
        ->whereNotIn('id', $context['postingAccounts']->pluck('id'))->whereHas('classification', fn ($query) => $query->whereIn('code', ['accumulated_depreciation', 'depreciation_expense', 'factory_depreciation_expense', 'gain_on_asset_disposal', 'loss_on_asset_disposal']))->update(['status' => 'inactive']);
}

function prerequisitesPreview(array $context, FixedAsset $asset): array
{
    return app(FixedAssetDepreciationService::class)->preview(['financial_period_doc_num' => $context['period']->doc_num, 'posting_date' => $context['period']->from_date->copy()->endOfMonth()->toDateString(), 'asset_doc_nums' => [$asset->doc_num]]);
}

test('chart classifications resolve depreciation without category overrides or disposal accounts', function (): void {
    $context = coreFixedAssetContext();
    prerequisitesUniqueChart($context);
    FixedAssetCategoryMapping::query()->delete();
    Account::whereKey([$context['postingAccounts'][2]->id, $context['postingAccounts'][3]->id])->update(['status' => 'inactive']);
    $asset = coreFixedAsset($context);
    expect(prerequisitesPreview($context, $asset)['eligible'])->toBeEmpty();
    $asset = app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString());
    expect(FixedAssetCategoryMapping::count())->toBe(0);
    $preview = prerequisitesPreview($context, $asset);
    expect($preview['excluded'])->toBeEmpty()->and($preview['eligible'])->toHaveCount(1);
    $run = corePostMonth($context, $asset);
    expect($run->journalEntry->is_posted)->toBeTrue();
    expect(fn () => app(FixedAssetLifecycleService::class)->previewDisposal($asset->fresh(), ['disposal_date' => $context['period']->from_date->copy()->addMonth()->toDateString(), 'disposition_type' => 'write_off']))->toThrow(DomainException::class, FixedAssetCategoryMapping::accountLabel('disposal_loss_account_id'));
});

test('capitalization ignores unrelated invalid overrides and opening only needs accumulated account', function (): void {
    $context = coreFixedAssetContext();
    prerequisitesUniqueChart($context);
    $mapping = FixedAssetCategoryMapping::firstOrFail();
    $mapping->update(['disposal_gain_account_id' => null, 'disposal_loss_account_id' => null, 'depreciation_expense_account_id' => null, 'disposal_clearing_account_id' => null]);
    $asset = coreFixedAsset($context, ['entry_type' => 'opening_asset', 'acquisition_date' => $context['period']->from_date->toDateString(), 'purchase_date' => $context['period']->from_date->toDateString(), 'previous_depreciation' => '20000', 'previous_depreciation_until_date' => $context['period']->from_date->copy()->subDay()->toDateString()]);
    $context['period']->update(['allows_opening_entries' => true]);
    $asset = app(FixedAssetLifecycleService::class)->activate($asset, $context['period']->from_date->toDateString());
    expect($asset->hasPostedRecognition())->toBeTrue()->and(prerequisitesPreview($context, $asset)['eligible'])->toHaveCount(1);
});

test('eligibility reports the actual state before recognition requirements', function (array $attributes, string $reason): void {
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context, $attributes);
    $preview = prerequisitesPreview($context, $asset);
    expect($preview['eligible'])->toBeEmpty()->and($preview['excluded'][0]['reason'])->toBe(__($reason));
})->with([
    'land' => [['is_depreciable' => false], 'fixed_assets.lifecycle.exclusions.non_depreciable'],
    'draft' => [['status' => 'draft'], 'fixed_assets.prerequisites.draft'],
    'disposed' => [['status' => 'disposed'], 'fixed_assets.lifecycle.exclusions.disposed'],
    'fully depreciated' => [['status' => 'fully_depreciated', 'purchase_value' => '120000', 'previous_depreciation' => '100000', 'salvage_value' => '20000'], 'fixed_assets.lifecycle.exclusions.fully_depreciated'],
    'future start' => [['depreciation_start_date' => '2035-01-01'], 'fixed_assets.lifecycle.exclusions.not_in_service'],
    'new active' => [['status' => 'active'], 'fixed_assets.cycle.recognition_required'],
]);

test('legacy rollout baseline preserves history and cannot grandfather later assets or reversed recognition', function (): void {
    $context = coreFixedAssetContext();
    $legacy = coreFixedAsset($context, ['entry_type' => 'opening_asset', 'acquisition_date' => $context['period']->from_date->toDateString(), 'purchase_date' => $context['period']->from_date->toDateString(), 'previous_depreciation' => '20000', 'previous_depreciation_until_date' => $context['period']->from_date->copy()->subDay()->toDateString()]);
    $draft = coreFixedAsset($context, ['status' => 'draft']);
    $recognized = coreRecognizedAsset($context);
    $columns = array_values(array_diff(Schema::getColumnListing('fixed_assets'), ['legacy_recognition']));
    $before = DB::table('fixed_assets')->orderBy('id')->get($columns)->toJson();
    $journals = DB::table('journal_entries')->orderBy('id')->get()->toJson();
    $lines = DB::table('journal_entry_lines')->orderBy('id')->get()->toJson();
    $position = app(FixedAssetBookValueService::class)->position($legacy);
    Schema::table('fixed_assets', fn ($table) => $table->dropColumn('legacy_recognition'));
    $migration = require base_path('modules/FixedAssets/Database/Migrations/2026_09_06_051118_preserve_existing_fixed_asset_recognition.php');
    $migration->up();
    expect(DB::table('fixed_assets')->orderBy('id')->get($columns)->toJson())->toBe($before)
        ->and(DB::table('journal_entries')->orderBy('id')->get()->toJson())->toBe($journals)
        ->and(DB::table('journal_entry_lines')->orderBy('id')->get()->toJson())->toBe($lines);
    expect($legacy->fresh()->hasLegacyRecognition())->toBeTrue()->and($draft->fresh()->hasLegacyRecognition())->toBeFalse()->and($recognized->fresh()->hasLegacyRecognition())->toBeFalse();
    $later = coreFixedAsset($context);
    $migration->up();
    expect($later->fresh()->hasPostedRecognition())->toBeFalse();
    $legacy = $legacy->fresh();
    expect($legacy->isMasterLocked())->toBeTrue()->and(prerequisitesPreview($context, $legacy)['eligible'])->toHaveCount(1);
    $afterPosition = app(FixedAssetBookValueService::class)->position($legacy);
    foreach (['acquisition_cost', 'accumulated_depreciation', 'net_book_value'] as $field) {
        expect($afterPosition[$field])->toBe($position[$field]);
    }
    expect(fn () => app(FixedAssetLifecycleService::class)->activate($legacy, $context['period']->from_date->toDateString()))->toThrow(DomainException::class);
    $this->get(route('admin.fixed-assets.assets.show', $legacy))->assertOk()->assertSee(__('fixed_assets.prerequisites.legacy_title'))->assertDontSee('id="recognition-modal"', false);
    $run = corePostMonth($context, $legacy);
    expect($run->lines)->toHaveCount(1)->and($legacy->fresh()->previous_depreciation)->toBe('20000.0000');
    $cost = app(FixedAssetCostMovementService::class);
    $addition = $cost->addition($legacy->fresh(), ['submission_key' => (string) Str::uuid(), 'movement_date' => $context['period']->from_date->copy()->addMonth()->toDateString(), 'amount' => '1000', 'description' => 'Legacy basis addition', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num]);
    expect(app(FixedAssetBookValueService::class)->position($legacy->fresh())['acquisition_cost'])->toBe(bcadd($position['acquisition_cost'], '1000', 4));
    $cost->reverse($addition, 'Baseline compatibility');
    expect(app(FixedAssetBookValueService::class)->position($legacy->fresh())['acquisition_cost'])->toBe($position['acquisition_cost']);
});

test('ambiguous chart requires only the relevant optional override', function (): void {
    $context = coreFixedAssetContext();
    prerequisitesUniqueChart($context);
    FixedAssetCategoryMapping::query()->delete();
    $original = $context['postingAccounts'][1];
    $second = $original->replicate(['doc_num', 'doc_number', 'account_code']);
    $second->fill(['doc_num' => 'ACC-98299', 'doc_number' => 98299, 'account_code' => '98299', 'name' => 'Second classified account'])->save();
    $asset = coreRecognizedAsset($context);
    $preview = prerequisitesPreview($context, $asset);
    expect($preview['excluded'][0]['reason'])->toBe(__('fixed_assets.prerequisites.account_ambiguous', ['account' => FixedAssetCategoryMapping::accountLabel('depreciation_expense_account_id')]));
    $mapping = app(FixedAssetLifecycleService::class)->configureCategoryMapping(['asset_group_account_doc_num' => $context['category']->doc_num, 'depreciation_expense_account_doc_num' => $original->doc_num]);
    expect($mapping->disposal_gain_account_id)->toBeNull()->and(prerequisitesPreview($context, $asset->fresh())['eligible'])->toHaveCount(1);
});

test('saving empty advanced overrides is a no op', function (): void {
    $context = coreFixedAssetContext();
    FixedAssetCategoryMapping::query()->delete();
    $result = app(FixedAssetLifecycleService::class)->configureCategoryMapping(['asset_group_account_doc_num' => $context['category']->doc_num]);
    expect($result->exists)->toBeFalse()->and(FixedAssetCategoryMapping::count())->toBe(0);
});

test('disposal account requirements follow functional currency gain or loss', function (): void {
    $context = coreFixedAssetContext();
    prerequisitesUniqueChart($context);
    FixedAssetCategoryMapping::query()->delete();
    $context['postingAccounts'][2]->update(['status' => 'inactive']);
    $currency = Currency::create(['company_id' => $context['company']->id, 'doc_number' => 99330, 'doc_num' => 'CUR-99330', 'code' => 'USD', 'name' => 'US Dollar', 'is_main' => false, 'status' => 'active']);
    $asset = coreRecognizedAsset($context, ['currency_doc_num' => $currency->doc_num, 'exchange_rate' => '1', 'is_depreciable' => false, 'salvage_value' => '0']);
    app(FixedAssetCostMovementService::class)->addition($asset, ['submission_key' => (string) Str::uuid(), 'movement_date' => $context['period']->from_date->copy()->addMonth()->toDateString(), 'amount' => '24000', 'exchange_rate' => '3', 'description' => 'Different historical rate', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num]);
    $preview = app(FixedAssetLifecycleService::class)->previewDisposal($asset->fresh(), ['disposal_date' => $context['period']->from_date->copy()->addMonths(2)->toDateString(), 'disposition_type' => 'sale', 'proceeds' => '160000', 'proceeds_account_doc_num' => $context['postingAccounts'][4]->doc_num]);
    $loss = collect($preview['journal_preview'])->firstWhere('account', $context['postingAccounts'][3]->codeNameLabel());
    expect($loss['debit'])->toBe('32000.0000')->and($loss['credit'])->toBe('0');
});

test('advanced override form accepts one account and preserves company validation', function (): void {
    $context = coreFixedAssetContext();
    FixedAssetCategoryMapping::query()->delete();
    $this->post(route('admin.fixed-assets.accounting.store'), ['asset_group_account_doc_num' => $context['category']->doc_num, 'depreciation_expense_account_doc_num' => $context['postingAccounts'][1]->doc_num])->assertSessionHasNoErrors()->assertRedirect();
    $mapping = FixedAssetCategoryMapping::firstOrFail();
    expect($mapping->depreciation_expense_account_id)->toBe($context['postingAccounts'][1]->id)->and($mapping->accumulated_depreciation_account_id)->toBeNull()->and($mapping->disposal_loss_account_id)->toBeNull();
});
