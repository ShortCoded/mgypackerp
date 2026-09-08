<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Currency;
use Modules\Core\Services\ArchiveFileService;
use Modules\Core\Services\ArchiveFolderService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\OpeningBalance;
use Modules\FixedAssets\Imports\FixedAssetExcelImportDefinition;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Services\FixedAssetAccountingSyncService;
use Modules\FixedAssets\Services\FixedAssetBookValueService;
use Modules\FixedAssets\Services\FixedAssetCostMovementService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetLedgerService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetReportService;
use Modules\FixedAssets\Services\FixedAssetScheduleService;
use Modules\FixedAssets\Services\FixedAssetService;
use Modules\HR\Models\HrEmployee;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../FixedAssetCycleSupport.php';

test('core recognition posts cost exactly once without changing the asset account architecture', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context);
    $accounts = Account::count();
    expect($asset->hasPostedRecognition())->toBeFalse();
    $asset = app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString());
    $movement = $asset->costMovements()->firstOrFail();
    expect(Account::count())->toBe($accounts)->and($asset->hasPostedRecognition())->toBeTrue()
        ->and($movement->journalEntry->lines->where('account_id', $asset->account_id)->sum('debit_amount'))->toEqual(120000)
        ->and($movement->journalEntry->lines->sum('credit_amount'))->toEqual(120000);
    expect(fn () => app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString()))->toThrow(DomainException::class);
    expect(JournalEntry::count())->toBe(1);
});

test('core opening creates a canonical opening balance with cost accumulated and offset', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $date = $context['period']->from_date->copy()->addDays(9)->toDateString();
    $context['period']->forceFill(['allows_opening_entries' => true])->save();
    $asset = coreFixedAsset($context, ['asset_date' => Carbon::parse($date)->addDay()->toDateString(), 'entry_type' => 'opening_asset', 'previous_depreciation' => '40000', 'previous_depreciation_until_date' => $date]);
    $asset = app(FixedAssetLifecycleService::class)->activate($asset, $date);
    $movement = $asset->costMovements()->firstOrFail();
    expect($movement->opening_balance_id)->not->toBeNull()
        ->and($movement->journalEntry->source_type)->toBe('opening_balance')
        ->and($movement->journalEntry->lines->sum('debit_amount'))->toEqual(120000)
        ->and($movement->journalEntry->lines->sum('credit_amount'))->toEqual(120000)
        ->and(app(FixedAssetBookValueService::class)->position($asset)['net_book_value'])->toBe('80000.0000');
    $schedule = app(FixedAssetScheduleService::class)->schedule($asset);
    expect($schedule['rows'])->not->toBeEmpty()
        ->and($schedule['rows'][0]['period_start']->toDateString())->toBe(Carbon::parse($date)->addDay()->toDateString());
    $register = app(FixedAssetReportService::class)->report(['type' => 'register', 'to_date' => $date]);
    expect($register['rows']->first()['cost'])->toBe('120000.0000');
    foreach (app(FixedAssetReportService::class)->report(['type' => 'reconciliation', 'to_date' => $date])['rows'] as $row) {
        expect(bccomp($row['difference'], '0', 4))->toBe(0);
    }
});

test('core depreciation requires recognition and refuses missing months', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.depreciation.post']);
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context);
    $filters = ['financial_period_doc_num' => $context['period']->doc_num, 'posting_date' => $context['period']->from_date->copy()->endOfMonth()->toDateString(), 'asset_doc_nums' => [$asset->doc_num]];
    expect(fn () => app(FixedAssetDepreciationService::class)->post($filters))->toThrow(DomainException::class);
    app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString());
    $run = app(FixedAssetDepreciationService::class)->post($filters);
    expect($run->status)->toBe('posted');
    $filters['posting_date'] = $context['period']->from_date->copy()->addMonths(3)->endOfMonth()->toDateString();
    expect(fn () => app(FixedAssetDepreciationService::class)->post($filters))->toThrow(DomainException::class);
});

test('core improvement changes future basis only and reverses without erasing history', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.improvement.post', 'fixed_assets.improvement.reverse']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $first = corePostMonth($context, $asset);
    $oldAmount = $first->lines->first()->period_depreciation;
    $date = $context['period']->from_date->copy()->addMonth()->addDays(14);
    $service = app(FixedAssetCostMovementService::class);
    $addition = $service->addition($asset, ['submission_key' => (string) Str::uuid(), 'movement_date' => $date->toDateString(), 'amount' => '10000', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Capital improvement', 'revised_useful_life' => '4']);
    expect(app(FixedAssetBookValueService::class)->position($asset, $date->copy()->subDay())['acquisition_cost'])->toBe('120000.0000')
        ->and(app(FixedAssetBookValueService::class)->position($asset, $date)['acquisition_cost'])->toBe('130000.0000')
        ->and($first->lines->first()->fresh()->period_depreciation)->toBe($oldAmount);
    $second = corePostMonth($context, $asset, 1);
    expect($second->lines->first()->acquisition_cost)->toBe('130000.0000');
    expect(fn () => $service->reverse($addition, 'Correction'))->toThrow(DomainException::class);
    app(FixedAssetDepreciationService::class)->reverse($second, 'Correction');
    $service->reverse($addition, 'Correction');
    expect($addition->fresh()->status)->toBe('reversed')
        ->and($addition->fresh()->reversal_journal_entry_id)->not->toBeNull()
        ->and(app(FixedAssetBookValueService::class)->position($asset)['acquisition_cost'])->toBe('120000.0000');
});

test('core zero usage records coverage and permits next month without a fake journal', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.depreciation.post']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context, ['depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction, 'expected_usage_units' => '10000']);
    $zero = corePostMonth($context, $asset, 0, ['usage_units' => [$asset->doc_num => '0']]);
    expect($zero->journal_entry_id)->toBeNull()->and($zero->lines->first()->period_depreciation)->toBe('0.0000');
    $next = corePostMonth($context, $asset, 1, ['usage_units' => [$asset->doc_num => '100']]);
    expect($next->lines->first()->period_depreciation)->toBe('1000.0000');
});

test('core disposal excludes disposal month includes expenses and restores state on reverse', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.dispose', 'fixed_assets.disposal.reverse']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $date = $context['period']->from_date->copy()->addMonth()->addDays(14)->toDateString();
    $data = ['disposal_date' => $date, 'disposition_type' => 'sale', 'proceeds' => '130000', 'disposal_expenses' => '3000', 'proceeds_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'expenses_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'reason' => 'Sale'];
    $service = app(FixedAssetLifecycleService::class);
    expect($service->previewDisposal($asset, $data)['has_gap'])->toBeTrue();
    expect(fn () => $service->dispose($asset, $data))->toThrow(DomainException::class);
    corePostMonth($context, $asset);
    $preview = $service->previewDisposal($asset->fresh(), $data);
    $disposal = $service->dispose($asset, $data);
    expect($disposal->net_proceeds)->toBe('127000.0000')->and($disposal->gain_amount)->toBe($preview['gain_loss'])
        ->and($disposal->journalEntry->lines->sum('debit_amount'))->toEqual($disposal->journalEntry->lines->sum('credit_amount'));
    expect($asset->fresh()->isDisposed())->toBeTrue();
    expect(app(FixedAssetBookValueService::class)->position($asset->fresh())['net_book_value'])->toBe('0.0000');
    $historical = app(FixedAssetReportService::class)->report(['type' => 'register', 'to_date' => $context['period']->from_date->copy()->endOfMonth()->toDateString()]);
    expect($historical['rows']->first()['cost'])->toBe('120000.0000');
    foreach (app(FixedAssetReportService::class)->report(['type' => 'reconciliation'])['rows'] as $row) {
        expect(bccomp($row['difference'], '0', 4))->toBe(0);
    }
    $service->reverseDisposal($disposal, 'Correction');
    expect($asset->fresh()->isDisposed())->toBeFalse()->and($disposal->fresh()->status)->toBe('reversed');
});

test('core opening reversal cancels the owned opening document and allows one corrected repost', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.recognition.reverse']);
    $context = coreFixedAssetContext();
    $context['period']->forceFill(['allows_opening_entries' => true])->save();
    $date = $context['period']->from_date->copy()->addDays(9)->toDateString();
    $asset = coreRecognizedAsset($context, ['entry_type' => 'opening_asset', 'previous_depreciation' => '40000', 'previous_depreciation_until_date' => $date]);
    $movement = $asset->costMovements()->firstOrFail();
    app(FixedAssetCostMovementService::class)->reverse($movement, 'Correct opening');
    expect(OpeningBalance::findOrFail($movement->opening_balance_id)->is_cancelled)->toBeTrue()
        ->and($asset->fresh()->isMasterLocked())->toBeFalse();
    app(FixedAssetLifecycleService::class)->activate($asset->fresh(), $date);
    expect($asset->costMovements()->where('status', 'posted')->count())->toBe(1)
        ->and(JournalEntry::count())->toBe(3)
        ->and(app(FixedAssetBookValueService::class)->position($asset->fresh())['net_book_value'])->toBe('80000.0000');
});

test('core foreign currency improvements preserve historical base cost at their own exchange rate', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.reports']);
    $context = coreFixedAssetContext();
    $foreign = Currency::query()->create(['company_id' => $context['company']->getKey(), 'doc_number' => 99009, 'doc_num' => 'CUR-99009', 'name' => 'US Dollar', 'code' => 'USD', 'is_main' => false, 'status' => 'active']);
    $asset = coreRecognizedAsset($context, ['currency_doc_num' => $foreign->doc_num, 'exchange_rate' => '2']);
    corePostMonth($context, $asset);
    expect(fn () => app(FixedAssetCostMovementService::class)->addition($asset, ['submission_key' => (string) Str::uuid(), 'movement_date' => $context['period']->from_date->copy()->addMonth()->toDateString(), 'amount' => '10000', 'exchange_rate' => '0.01', 'revised_residual_value' => '128000', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Invalid functional currency residual']))->toThrow(DomainException::class);
    app(FixedAssetCostMovementService::class)->addition($asset, ['submission_key' => (string) Str::uuid(), 'movement_date' => $context['period']->from_date->copy()->addMonth()->toDateString(), 'amount' => '10000', 'exchange_rate' => '3', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Foreign currency improvement']);
    corePostMonth($context, $asset, 1);
    $position = app(FixedAssetBookValueService::class)->position($asset->fresh());
    expect($position['acquisition_cost'])->toBe('130000.0000')->and($position['base_acquisition_cost'])->toBe('270000.0000');
    foreach (app(FixedAssetReportService::class)->report(['type' => 'reconciliation'])['rows'] as $row) {
        expect(bccomp($row['difference'], '0', 4))->toBe(0);
    }
});

test('core reconciliation compares shared depreciation accounts once across all assets in that account', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.reports']);
    $context = coreFixedAssetContext();
    $first = coreRecognizedAsset($context);
    $second = coreRecognizedAsset($context);
    corePostMonth($context, $first);
    corePostMonth($context, $second);
    $report = app(FixedAssetReportService::class)->report(['type' => 'reconciliation', 'asset_doc_num' => $first->doc_num]);
    expect($report['rows']->where('account', $second->account->codeNameLabel()))->toHaveCount(0);
    foreach ($report['rows'] as $row) {
        expect(bccomp($row['difference'], '0', 4))->toBe(0)->and($row['scope'])->toBe(__('fixed_assets.cycle.account_total_scope'));
    }
    $accumulated = $report['rows']->firstWhere('account', $context['postingAccounts'][0]->codeNameLabel());
    expect($accumulated['subledger'])->toBe(bcadd($first->postedDepreciations()->first()->period_depreciation, $second->postedDepreciations()->first()->period_depreciation, 4));
});

test('core as of reconciliation excludes later depreciation and additions on both sides', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.reports']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    corePostMonth($context, $asset);
    $january = $context['period']->from_date->copy()->endOfMonth()->toDateString();
    app(FixedAssetCostMovementService::class)->addition($asset, ['submission_key' => (string) Str::uuid(), 'movement_date' => $context['period']->from_date->copy()->addMonth()->toDateString(), 'amount' => '10000', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Addition']);
    corePostMonth($context, $asset, 1);
    $report = app(FixedAssetReportService::class)->report(['type' => 'reconciliation', 'to_date' => $january, 'branch_doc_num' => $context['branch']->doc_num, 'cost_center_doc_num' => $context['sourceCostCenter']->doc_num]);
    expect($report['rows'])->not->toBeEmpty();
    foreach ($report['rows'] as $row) {
        expect(bccomp($row['difference'], '0', 4))->toBe(0);
    }
    $register = app(FixedAssetReportService::class)->report(['type' => 'register', 'to_date' => $january]);
    expect($register['rows']->first()['cost'])->toBe('120000.0000');
});

test('core asset 360 renders real workflows and ledger with existing chart links', function (): void {
    coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.improvement.post', 'fixed_assets.improvement.reverse', 'fixed_assets.custody.post', 'fixed_assets.dispose', 'accounts.view', 'journal_entries.view']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $this->get(route('admin.fixed-assets.lifecycle.show', $asset))->assertOk()->assertSee($asset->doc_num)->assertSee(route('admin.accounting.accounts.show', $asset->account->doc_num), false)->assertSee('js-disposal-preview', false);
    $this->get(route('admin.fixed-assets.movements.index'))->assertOk()->assertSee($asset->asset_name);
});

test('core recognition reverse and repost preserve journals and permit controlled master correction', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.recognition.reverse']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $movement = $asset->costMovements()->firstOrFail();
    app(FixedAssetCostMovementService::class)->reverse($movement, 'Correct original amount');
    expect($asset->fresh()->hasPostedRecognition())->toBeFalse()->and($asset->fresh()->isMasterLocked())->toBeFalse()
        ->and($movement->fresh()->status)->toBe('reversed')->and(JournalEntry::count())->toBe(2);
    app(FixedAssetLifecycleService::class)->activate($asset->fresh(), $asset->operation_date->toDateString());
    expect($asset->fresh()->hasPostedRecognition())->toBeTrue()->and(JournalEntry::count())->toBe(3);
    $report = app(FixedAssetReportService::class)->report(['type' => 'reconciliation']);
    foreach ($report['rows'] as $row) {
        expect(bccomp($row['difference'], '0', 4))->toBe(0);
    }
});

test('core legacy recognition validates unexplained cost and links matching journals without reposting', function (bool $unexplainedCost): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context);
    $journal = app(JournalEntryService::class)->createPostedFromSource([
        'company_id' => $asset->company_id, 'financial_period_id' => $context['period']->getKey(), 'entry_date' => $asset->operation_date,
        'currency_id' => $asset->currency_id, 'exchange_rate' => '1', 'branch_id' => $asset->branch_id, 'description' => 'Historical acquisition', 'source_type' => 'legacy_asset', 'source_id' => $asset->getKey(), 'source_doc_num' => $asset->doc_num,
    ], [
        ['account_id' => $asset->account_id, 'debit_amount' => '120000', 'credit_amount' => '0', 'branch_id' => $asset->branch_id, 'cost_center_id' => $asset->cost_center_id],
        ['account_id' => $asset->credit_account_id, 'debit_amount' => '0', 'credit_amount' => '120000', 'branch_id' => $asset->branch_id, 'cost_center_id' => $asset->cost_center_id],
    ]);
    expect($asset->fresh()->isMasterLocked())->toBeTrue();
    expect(fn () => app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString()))->toThrow(DomainException::class);
    if ($unexplainedCost) {
        app(JournalEntryService::class)->createPostedFromSource([
            ...$journal->only(['company_id', 'financial_period_id', 'entry_date', 'currency_id', 'exchange_rate', 'branch_id', 'description', 'source_type', 'source_doc_num']),
            'source_id' => $asset->getKey() + 99000,
        ], [
            ['account_id' => $asset->account_id, 'debit_amount' => '1000', 'credit_amount' => '0', 'branch_id' => $asset->branch_id, 'cost_center_id' => $asset->cost_center_id],
            ['account_id' => $asset->credit_account_id, 'debit_amount' => '0', 'credit_amount' => '1000', 'branch_id' => $asset->branch_id, 'cost_center_id' => $asset->cost_center_id],
        ]);
        expect(fn () => app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString(), $journal->doc_num))->toThrow(DomainException::class);
        expect($asset->costMovements()->count())->toBe(0)->and(JournalEntry::count())->toBe(2);

        return;
    }
    $context['period']->forceFill(['is_closed' => true])->save();
    app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString(), $journal->doc_num);
    expect(JournalEntry::count())->toBe(1)->and($asset->fresh()->hasPostedRecognition())->toBeTrue();
    expect(fn () => app(FixedAssetCostMovementService::class)->reverse($asset->costMovements()->first(), 'Unsafe legacy reversal'))->toThrow(DomainException::class);
})->with(['matching legacy recognition' => false, 'unexplained extra cost' => true]);

test('core custody preserves both owners and a return without mutating accounting', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.custody.post']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $employee = HrEmployee::query()->create(['doc_number' => 99881, 'doc_num' => 'EMP-99881', 'company_id' => $asset->company_id, 'branch_id' => $asset->branch_id, 'full_name' => 'Asset Custodian', 'name' => 'Asset Custodian', 'status' => 'active']);
    $data = ['movement_date' => $asset->operation_date->toDateString(), 'custodian_doc_num' => $employee->doc_num, 'reason' => 'Handover'];
    $first = app(FixedAssetLifecycleService::class)->custody($asset, $data);
    expect(fn () => app(FixedAssetLifecycleService::class)->custody($asset, $data))->toThrow(DomainException::class);
    $return = app(FixedAssetLifecycleService::class)->custody($asset, [...$data, 'custodian_doc_num' => null, 'reason' => 'Return']);
    expect($first->destination_custodian_id)->toBe($employee->getKey())->and($return->source_custodian_id)->toBe($employee->getKey())->and($return->destination_custodian_id)->toBeNull()->and(JournalEntry::count())->toBe(1);
    expect(app(FixedAssetLedgerService::class)->history($asset)->where('type', 'custody'))->toHaveCount(2);
});

test('core incorrect depreciation account blocks depreciation but not capitalization', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context);
    $asset->categoryMapping->forceFill(['depreciation_expense_account_id' => $context['postingAccounts'][4]->getKey()])->save();
    $asset = app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString());
    expect(JournalEntry::count())->toBe(1)->and($asset->movements()->count())->toBe(1);
    expect(fn () => corePostMonth($context, $asset))->toThrow(DomainException::class);
    expect(JournalEntry::count())->toBe(1)->and($asset->depreciations()->count())->toBe(0);
});

test('core monthly usage preview responds with recalculated values before posting', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.depreciation.preview']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context, ['depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction, 'expected_usage_units' => '10000']);
    $this->postJson(route('admin.fixed-assets.depreciation.preview'), ['financial_period_doc_num' => $context['period']->doc_num, 'posting_date' => $context['period']->from_date->copy()->endOfMonth()->toDateString(), 'asset_doc_nums' => [$asset->doc_num], 'usage_units' => [$asset->doc_num => '125']])->assertOk()->assertJsonPath('rows.0.period_depreciation', '1250.0000')->assertJsonPath('rows.0.closing_net_book_value', '118750.0000');
    expect($asset->postedDepreciations()->exists())->toBeFalse();
});

test('core financial fields lock after recognition while a no op does not write or log', function (): void {
    config()->set('erp_features.fixed_assets.allow_full_master_crud', false);
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.edit']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $data = [...$asset->getAttributes(), 'asset_group_account_doc_num' => $context['category']->doc_num, 'credit_account_doc_num' => $asset->creditAccount->doc_num, 'currency_doc_num' => $asset->currency->doc_num, 'branch_doc_num' => $asset->branch->doc_num, 'cost_center_doc_num' => $asset->costCenter->doc_num];
    $before = $asset->getRawOriginal();
    $activityCount = Activity::count();
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(update|insert|delete)\s/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    app(FixedAssetService::class)->update($asset, $data);
    expect($writes)->toBeEmpty()->and($asset->fresh()->getRawOriginal())->toBe($before)->and(Activity::count())->toBe($activityCount);
    expect(fn () => app(FixedAssetService::class)->update($asset, [...$data, 'purchase_value' => '999999']))->toThrow(DomainException::class);
    expect($asset->fresh()->purchase_value)->toBe('120000.0000');
});

test('core branch access prevents direct asset workflows and hides assets from the register', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $restricted = coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.activate', 'fixed_assets.reports']);
    $role = Role::create(['name' => 'Restricted asset branch', 'guard_name' => 'web']);
    $role->forceFill(['branch_access_restricted' => true])->save();
    DB::table('role_branch_access')->insert(['role_id' => $role->getKey(), 'branch_id' => $context['destinationBranch']->getKey()]);
    $restricted->assignRole($role);
    $this->get(route('admin.fixed-assets.lifecycle.show', $asset))->assertForbidden();
    $this->get(route('admin.fixed-assets.assets.image', $asset))->assertForbidden();
    $this->post(route('admin.fixed-assets.lifecycle.activate', $asset), ['activation_date' => $asset->operation_date->toDateString()])->assertForbidden();
    expect(app(FixedAssetReportService::class)->report(['type' => 'register'])['rows'])->toBeEmpty();
});

test('core posting failure and a closed period roll back the whole recognition', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context);
    $context['period']->forceFill(['is_closed' => true])->save();
    expect(fn () => app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString()))->toThrow(DomainException::class);
    expect($asset->costMovements()->exists())->toBeFalse()->and(JournalEntry::count())->toBe(0);
    $context['period']->forceFill(['is_closed' => false])->save();
    $event = 'eloquent.creating: '.JournalEntry::class;
    Event::listen($event, static function (): void {
        throw new DomainException('Simulated posting failure');
    });
    try {
        expect(fn () => app(FixedAssetLifecycleService::class)->activate($asset, $asset->operation_date->toDateString()))->toThrow(DomainException::class);
    } finally {
        Event::forget($event);
    }
    expect($asset->costMovements()->exists())->toBeFalse()->and(JournalEntry::count())->toBe(0)->and($asset->fresh()->capitalized_at)->toBeNull();
});

test('core documents use the shared archive and duplicate attachment is a no op', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.edit', 'file_manager.view']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $files = app(ArchiveFileService::class)->upload(
        files: [UploadedFile::fake()->create('asset-certificate.pdf', 20, 'application/pdf')],
        attachable: $context['company'], module: 'core', recordType: 'company', recordDocNum: $context['company']->doc_num,
        folder: app(ArchiveFolderService::class)->generalRoot(),
    );
    $data = ['archive_file_doc_num' => $files[0]->doc_num, 'movement_doc_num' => $asset->costMovements()->first()->doc_num];
    $this->post(route('admin.fixed-assets.movements.document', $asset), $data)->assertRedirect()->assertSessionHasNoErrors();
    $activity = Activity::count();
    $this->post(route('admin.fixed-assets.movements.document', $asset), $data)->assertRedirect()->assertSessionHasNoErrors();
    expect($asset->costMovements()->first()->archiveFileUsages()->where('collection', 'fixed_asset_documents')->count())->toBe(1)
        ->and(Activity::count())->toBe($activity);
});

test('core a capitalized asset keeps account rename synchronization but rejects account deactivation', function (): void {
    config()->set('erp_features.fixed_assets.allow_full_master_crud', false);
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $account = $asset->account;
    $account->forceFill(['name' => 'Renamed capitalized asset'])->save();
    app(FixedAssetAccountingSyncService::class)->syncFixedAssetForAccountUpdate($account->refresh());
    expect($asset->fresh()->asset_name)->toBe('Renamed capitalized asset')->and($asset->fresh()->status)->toBe(FixedAsset::StatusActive);
    $before = $account->status;
    expect(function () use ($account): void {
        DB::transaction(function () use ($account): void {
            $account->forceFill(['status' => 'inactive'])->save();
            app(FixedAssetAccountingSyncService::class)->syncFixedAssetForAccountUpdate($account->refresh());
        });
    })->toThrow(DomainException::class);
    expect($account->fresh()->status)->toBe($before);
});

test('core historical depreciation gaps block later runs improvements and disposal until reversed', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    corePostMonth($context, $asset);
    $later = corePostMonth($context, $asset, 1);
    $later->lines()->update(['period_start' => $context['period']->from_date->copy()->addMonths(3)->toDateString(), 'period_end' => $context['period']->from_date->copy()->addMonths(3)->endOfMonth()->toDateString()]);
    $later->forceFill(['period_start' => $context['period']->from_date->copy()->addMonths(3), 'period_end' => $context['period']->from_date->copy()->addMonths(3)->endOfMonth(), 'posting_date' => $context['period']->from_date->copy()->addMonths(3)->endOfMonth()])->save();
    $later->journalEntry->forceFill(['entry_date' => $later->posting_date])->save();
    $date = $context['period']->from_date->copy()->addMonths(4)->toDateString();
    expect(fn () => corePostMonth($context, $asset, 4))->toThrow(DomainException::class);
    expect(fn () => app(FixedAssetCostMovementService::class)->addition($asset, ['submission_key' => (string) Str::uuid(), 'movement_date' => $date, 'amount' => '1000', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Cannot bypass missing depreciation']))->toThrow(DomainException::class);
    expect(fn () => app(FixedAssetLifecycleService::class)->previewDisposal($asset, ['disposal_date' => $date]))->toThrow(DomainException::class);
    app(FixedAssetDepreciationService::class)->reverse($later, 'Repair historical gap');
    expect(corePostMonth($context, $asset->fresh(), 1)->status)->toBe('posted');
});

test('core transfer reports include incoming and outgoing movements with historical dimensions', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.reports']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $date = $context['period']->from_date->copy()->addMonth();
    $movement = app(FixedAssetLifecycleService::class)->transfer($asset, ['movement_date' => $date->toDateString(), 'destination_branch_doc_num' => $context['destinationBranch']->doc_num, 'destination_cost_center_doc_num' => $context['destinationCostCenter']->doc_num, 'reason' => 'Transfer with history']);
    foreach (['movements', 'transfers'] as $type) {
        foreach ([[$context['branch'], $context['sourceCostCenter']], [$context['destinationBranch'], $context['destinationCostCenter']]] as [$branch, $center]) {
            $rows = app(FixedAssetReportService::class)->report(['type' => $type, 'branch_doc_num' => $branch->doc_num, 'cost_center_doc_num' => $center->doc_num])['rows'];
            expect($rows->pluck('document'))->toContain($movement->doc_num);
        }
    }
    $historical = app(FixedAssetReportService::class)->report(['type' => 'register', 'to_date' => $date->copy()->subDay()->toDateString()]);
    expect($historical['rows']->first()['branch'])->toBe($context['branch']->name);
});

test('core usage improvements split actual units at the effective date and preserve remaining unit estimates', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context, ['depreciation_method' => FixedAsset::DepreciationMethodUnitsOfProduction, 'expected_usage_units' => '10000']);
    corePostMonth($context, $asset, 0, ['usage_units' => [$asset->doc_num => '100']]);
    $date = $context['period']->from_date->copy()->addMonth()->addDays(14);
    $data = ['submission_key' => (string) Str::uuid(), 'movement_date' => $date->toDateString(), 'amount' => '10000', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Usage based improvement'];
    expect(fn () => app(FixedAssetCostMovementService::class)->addition($asset, $data))->toThrow(DomainException::class);
    $addition = app(FixedAssetCostMovementService::class)->addition($asset, [...$data, 'actual_usage_before_addition' => '50']);
    expect(data_get($addition->snapshot, 'plan.estimated_remaining_units'))->toBe('9850.0000');
    expect(fn () => corePostMonth($context, $asset, 1, ['usage_units' => [$asset->doc_num => '40']]))->toThrow(DomainException::class);
    $run = corePostMonth($context, $asset, 1, ['usage_units' => [$asset->doc_num => '200']]);
    expect($run->lines->first()->period_depreciation)->toBe('2152.2842')->and($run->lines->first()->usage_units)->toBe('200.0000');
    $next = app(FixedAssetCostMovementService::class)->addition($asset, [...$data, 'submission_key' => (string) Str::uuid(), 'movement_date' => $date->copy()->addMonth()->toDateString(), 'actual_usage_before_addition' => '20']);
    expect(data_get($next->snapshot, 'plan.estimated_remaining_units'))->toBe('9680.0000');
});

test('core improvements restart depreciation for an operational fully depreciated asset without artificial missing periods', function (string $method): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $context['period']->forceFill(['allows_opening_entries' => true])->save();
    $original = $context['period']->from_date->copy()->subYears(6)->toDateString();
    $asset = coreRecognizedAsset($context, ['entry_type' => 'opening_asset', 'depreciation_method' => $method, 'expected_usage_units' => '12000', 'acquisition_date' => $original, 'purchase_date' => $original, 'operation_date' => $original, 'depreciation_start_date' => $original, 'previous_depreciation' => '120000', 'salvage_value' => '0', 'previous_depreciation_until_date' => $context['period']->from_date->copy()->endOfMonth()->toDateString()]);
    expect($asset->status)->toBe(FixedAsset::StatusFullyDepreciated)->and($asset->isOperational())->toBeTrue();
    $date = $context['period']->from_date->copy()->addMonths(4)->addDays(14);
    $data = ['submission_key' => (string) Str::uuid(), 'movement_date' => $date->toDateString(), 'amount' => '10000', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Extend useful service'];
    expect(fn () => app(FixedAssetCostMovementService::class)->addition($asset, $data))->toThrow(DomainException::class);
    $revisions = $method === FixedAsset::DepreciationMethodUnitsOfProduction ? ['estimated_remaining_units' => '1000', 'actual_usage_before_addition' => '0'] : ['revised_useful_life' => '2'];
    app(FixedAssetCostMovementService::class)->addition($asset, [...$data, ...$revisions]);
    $run = corePostMonth($context, $asset->fresh(), 4, ['usage_units' => [$asset->doc_num => '50']]);
    expect($run->lines->first()->period_start->toDateString())->toBe($date->toDateString())
        ->and($run->lines->first()->period_depreciation)->toBe($method === FixedAsset::DepreciationMethodUnitsOfProduction ? '500.0000' : '232.8767')->and($asset->fresh()->isOperational())->toBeTrue();
    expect(corePostMonth($context, $asset->fresh(), 5, ['usage_units' => [$asset->doc_num => '50']])->status)->toBe('posted');
})->with([FixedAsset::DepreciationMethodStraightLine, FixedAsset::DepreciationMethodUnitsOfProduction]);

test('release additions reject missing keys and replay one journal without changing posted history', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate', 'fixed_assets.improvement.post']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $service = app(FixedAssetCostMovementService::class);
    $data = ['movement_date' => $asset->operation_date->toDateString(), 'amount' => '1000', 'description' => 'Retry-safe addition', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num];
    $this->postJson(route('admin.fixed-assets.movements.addition', $asset), $data)->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    expect(fn () => $service->addition($asset, $data))->toThrow(DomainException::class);
    $data['submission_key'] = (string) Str::uuid();
    $first = $service->addition($asset, $data);
    $writes = JournalEntry::count();
    $this->post(route('admin.fixed-assets.movements.addition', $asset), $data)->assertRedirect()->assertSessionHasNoErrors();
    expect($service->addition($asset, $data)->getKey())->toBe($first->getKey())
        ->and(JournalEntry::count())->toBe($writes)
        ->and($asset->costMovements()->where('movement_type', 'addition')->count())->toBe(1);
    expect(fn () => $service->addition($asset, [...$data, 'amount' => '2000']))->toThrow(DomainException::class);
    $service->reverse($first, 'Correction');
    expect($service->addition($asset, $data)->status)->toBe('reversed')
        ->and($asset->costMovements()->where('movement_type', 'addition')->count())->toBe(1);
});

test('release existing asset completes its entire lifecycle with historical dimensional reconciliation', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.view', 'fixed_assets.activate', 'fixed_assets.reports', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post', 'fixed_assets.depreciation.reverse', 'fixed_assets.improvement.post', 'fixed_assets.transfer', 'fixed_assets.custody.post', 'fixed_assets.dispose', 'fixed_assets.disposal.reverse']);
    $context = coreFixedAssetContext();
    coreCompleteExistingCycle($context);
});

test('release new asset capitalizes depreciates and opens the linked asset card', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.view', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    coreCompleteNewCycle($context);
});

test('release closed periods reject every financial post and reversal atomically', function (string $operation): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $context['period']->forceFill(['allows_opening_entries' => true])->save();
    $asset = coreFixedAsset($context);
    $cost = app(FixedAssetCostMovementService::class);
    $lifecycle = app(FixedAssetLifecycleService::class);
    $data = ['submission_key' => (string) Str::uuid(), 'movement_date' => $asset->operation_date->toDateString(), 'amount' => '1000', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Closed period test'];
    if ($operation === 'opening') {
        $asset = coreFixedAsset($context, ['entry_type' => 'opening_asset', 'previous_depreciation' => '1000', 'previous_depreciation_until_date' => $asset->operation_date->toDateString()]);
    }
    if (! in_array($operation, ['recognition', 'opening'], true)) {
        $asset = $lifecycle->activate($asset, $asset->operation_date->toDateString());
    }
    $movement = $operation === 'addition_reverse' ? $cost->addition($asset, $data) : $asset->costMovements()->first();
    $run = in_array($operation, ['depreciation_reverse', 'disposal_reverse'], true) ? corePostMonth($context, $asset) : null;
    $sale = ['disposal_date' => $context['period']->from_date->copy()->addMonth()->toDateString(), 'disposition_type' => 'write_off', 'proceeds' => '0', 'reason' => 'Closed period'];
    $disposal = $operation === 'disposal_reverse' ? $lifecycle->dispose($asset, $sale) : null;
    $context['period']->forceFill(['is_closed' => true])->save();
    $before = [JournalEntry::count(), DB::table('fixed_asset_movements')->count(), DB::table('fixed_asset_depreciations')->count(), DB::table('fixed_asset_disposals')->count(), $asset->fresh()->net_value];
    $action = match ($operation) {
        'recognition', 'opening' => fn () => $lifecycle->activate($asset, $asset->operation_date->toDateString()),
        'recognition_reverse', 'addition_reverse' => fn () => $cost->reverse($movement, 'Closed'),
        'addition' => fn () => $cost->addition($asset, $data),
        'depreciation' => fn () => corePostMonth($context, $asset),
        'depreciation_reverse' => fn () => app(FixedAssetDepreciationService::class)->reverse($run, 'Closed'),
        'disposal' => fn () => $lifecycle->dispose($asset, $sale),
        'disposal_reverse' => fn () => $lifecycle->reverseDisposal($disposal, 'Closed'),
    };
    expect($action)->toThrow(DomainException::class);
    expect([JournalEntry::count(), DB::table('fixed_asset_movements')->count(), DB::table('fixed_asset_depreciations')->count(), DB::table('fixed_asset_disposals')->count(), $asset->fresh()->net_value])->toBe($before);
})->with(['recognition', 'opening', 'recognition_reverse', 'addition', 'addition_reverse', 'depreciation', 'depreciation_reverse', 'disposal', 'disposal_reverse']);

test('release view only users cannot post reverse export edit or delete asset documents', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $run = corePostMonth($context, $asset);
    $movement = $asset->costMovements()->firstOrFail();
    $restricted = coreFixedAssetActor(['fixed_assets.view']);
    $this->get(route('admin.fixed-assets.lifecycle.show', $asset))->assertOk();
    foreach ([['admin.fixed-assets.lifecycle.activate', $asset], ['admin.fixed-assets.lifecycle.transfer', $asset], ['admin.fixed-assets.lifecycle.dispose', $asset], ['admin.fixed-assets.movements.addition', $asset], ['admin.fixed-assets.movements.custody', $asset], ['admin.fixed-assets.movements.reverse', $movement], ['admin.fixed-assets.depreciation.post', null], ['admin.fixed-assets.depreciation.reverse', $run]] as [$route, $document]) {
        $this->postJson(route($route, $document))->assertForbidden();
    }
    $this->get(route('admin.fixed-assets.assets.edit', $asset))->assertForbidden();
    $this->deleteJson(route('admin.fixed-assets.assets.destroy', $asset))->assertForbidden();
    $this->get(route('admin.fixed-assets.reports.excel'))->assertForbidden();
    expect($restricted->roles)->toBeEmpty()->and($asset->fresh()->hasPostedRecognition())->toBeTrue();
});

test('release import validates entry type opening basis and remains uncapitalized until posted', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.import']);
    $context = coreFixedAssetContext();
    $date = $context['period']->from_date->copy()->addDays(9)->toDateString();
    $payload = ['asset_name' => 'Imported release asset', 'asset_date' => $date, 'entry_type' => 'new_asset', 'asset_group_account_doc_num' => $context['category']->doc_num, 'credit_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'branch_doc_num' => $context['branch']->doc_num, 'description' => 'Import acceptance', 'purchase_date' => $date, 'acquisition_date' => $date, 'operation_date' => $date, 'purchase_value' => '120000', 'currency_doc_num' => $context['currency']->doc_num, 'exchange_rate' => '1', 'is_depreciable' => '1', 'depreciation_method' => 'straight_line', 'salvage_value' => '20000', 'useful_life' => '5', 'annual_depreciation_rate' => '20', 'status' => 'active', 'previous_depreciation' => '0'];
    $definition = app(FixedAssetExcelImportDefinition::class);
    foreach ([['previous_depreciation' => '1000'], ['entry_type' => 'opening_asset', 'previous_depreciation' => '110000', 'previous_depreciation_until_date' => $date], ['entry_type' => 'opening_asset', 'previous_depreciation' => '20000']] as $invalid) {
        $result = $definition->validateRows(['FixedAssets' => [['excel_row' => 2, 'data' => [...$payload, ...$invalid]]]], request());
        expect($result[0]['issues'])->not->toBeEmpty()->and($result[0]['normalized_data'])->toBeNull();
    }
    $result = $definition->validateRows(['FixedAssets' => [['excel_row' => 2, 'data' => [...$payload, 'entry_type' => 'opening_asset', 'previous_depreciation' => '20000', 'previous_depreciation_until_date' => $date]]]], request());
    expect($result[0]['issues'])->toBeEmpty();
    $asset = app(FixedAssetService::class)->create($result[0]['normalized_data'])['record'];
    expect($asset->hasPostedRecognition())->toBeFalse()->and(JournalEntry::count())->toBe(0);
    expect(fn () => corePostMonth($context, $asset))->toThrow(DomainException::class);
});

test('release depreciation preview preserves localized posting dates across repeated previews', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.depreciation.preview']);
    $context = coreFixedAssetContext();
    $date = $context['period']->from_date->copy()->addMonths(8)->endOfMonth();
    $display = app(DateFormatService::class)->formatDate($date);
    foreach ([$display, $date->toDateString()] as $input) {
        $this->post(route('admin.fixed-assets.depreciation.preview'), ['financial_period_doc_num' => $context['period']->doc_num, 'posting_date' => $input])->assertOk()
            ->assertSee('name="posting_date" value="'.$display.'"', false)
            ->assertSee('name="posting_date" value="'.$date->toDateString().'"', false);
    }
});

test('release permission provisioning contains every financial asset action', function (): void {
    $registered = app(PermissionRegistryService::class)->all();
    foreach (['fixed_assets.activate', 'fixed_assets.recognition.reverse', 'fixed_assets.improvement.post', 'fixed_assets.improvement.reverse', 'fixed_assets.custody.post', 'fixed_assets.transfer', 'fixed_assets.dispose', 'fixed_assets.disposal.reverse', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post', 'fixed_assets.depreciation.reverse'] as $permission) {
        expect($registered)->toContain($permission);
    }
});

test('release public document identifiers cannot cross company context', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.view', 'fixed_assets.activate', 'fixed_assets.recognition.reverse', 'fixed_assets.reports']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $movement = $asset->costMovements()->firstOrFail();
    $company = $context['company']->replicate(['doc_number', 'doc_num']);
    $company->fill(['doc_number' => 99000, 'doc_num' => 'CO-99000', 'is_main' => false, 'name' => 'Isolated asset company'])->save();
    $branch = $context['branch']->replicate(['doc_number', 'doc_num']);
    $branch->fill(['company_id' => $company->id, 'doc_number' => 99000, 'doc_num' => 'BR-99000'])->save();
    $period = $context['period']->replicate(['doc_number', 'doc_num']);
    $period->fill(['company_id' => $company->id, 'doc_number' => 99000, 'doc_num' => 'FP-99000'])->save();
    $session = [];
    foreach (['Company' => $company, 'Branch' => $branch, 'FinancialPeriod' => $period] as $prefix => $model) {
        $session[constant(OperatingContextService::class.'::'.$prefix.'IdKey')] = $model->id;
        $session[constant(OperatingContextService::class.'::'.$prefix.'DocNumKey')] = $model->doc_num;
    }
    session($session);
    $this->withSession($session);
    $this->get(route('admin.fixed-assets.lifecycle.show', $asset))->assertNotFound();
    $this->get(route('admin.fixed-assets.assets.image', $asset))->assertNotFound();
    $this->postJson(route('admin.fixed-assets.movements.reverse', $movement), ['reason' => 'Foreign document'])->assertNotFound();
    expect(app(FixedAssetReportService::class)->report(['type' => 'register'])['rows'])->toBeEmpty();
    expect($asset->fresh()->hasPostedRecognition())->toBeTrue();
});
