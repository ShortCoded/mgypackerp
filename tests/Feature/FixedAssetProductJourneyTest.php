<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\MenuService;
use Modules\FixedAssets\Services\FixedAssetCostMovementService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetReportService;

require_once __DIR__.'/../FixedAssetCycleSupport.php';

test('asset navigation exposes four real destinations and retains deployed workflow permissions', function (): void {
    $actor = coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.reports', 'fixed_assets.depreciation.preview', 'fixed_assets.accounting.configure']);
    coreFixedAssetContext();
    $menu = collect(app(MenuService::class)->getMenu($actor))->firstWhere('label', 'accounting_costing');
    $assets = collect($menu['children'])->firstWhere('label', 'fixed_assets');
    expect(array_column($assets['children'], 'label'))->toBe(['fixed_assets_register', 'fixed_asset_movements', 'fixed_asset_depreciation', 'fixed_asset_reports']);
    expect(config('erp_ui_screens.fixed_assets.screens', []))->toBe([]);
    foreach (['asset-acquisition', 'asset-capitalization', 'asset-improvement', 'asset-custody', 'asset-history', 'asset-sale', 'depreciation-review'] as $obsolete) {
        expect(Route::has('admin.fixed-assets.'.$obsolete.'.index'))->toBeFalse();
    }
    foreach (['fixed_assets.improvement.post', 'fixed_assets.improvement.reverse', 'fixed_assets.custody.post', 'fixed_assets.recognition.reverse', 'fixed_assets.accounting.configure'] as $permission) {
        expect(app(PermissionRegistryService::class)->all())->toContain($permission);
    }
    $this->get(route('admin.fixed-assets.assets.index'))->assertOk()->assertSee(route('admin.fixed-assets.accounting.index'), false);
});

test('draft asset opens the canonical card with the appropriate next recognition action', function (string $type): void {
    coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.delete', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context, ['status' => 'draft', 'entry_type' => $type]);
    $this->get(route('admin.fixed-assets.assets.edit', $asset))->assertOk();
    $response = $this->get(route('admin.fixed-assets.assets.show', $asset))->assertOk()
        ->assertSee(route('admin.fixed-assets.assets.edit', $asset), false)
        ->assertSee('id="recognition-modal"', false)
        ->assertDontSee('id="addition-modal"', false)
        ->assertDontSee('id="disposal-modal"', false)
        ->assertSee(__('fixed_assets.product.'.($type === 'opening_asset' ? 'open' : 'capitalize')));
    foreach (['overview', 'financial', 'depreciation', 'movements', 'documents', 'audit'] as $tab) {
        $response->assertSee('id="asset-'.$tab.'"', false);
    }
})->with(['new_asset', 'opening_asset']);

test('capitalized assets allow descriptive edits while disposed cards hide invalid actions', function (): void {
    coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.delete', 'fixed_assets.activate', 'fixed_assets.improvement.post', 'fixed_assets.transfer', 'fixed_assets.custody.post', 'fixed_assets.dispose']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context, ['status' => 'draft']);
    $originalCost = $asset->purchase_value;
    $this->get(route('admin.fixed-assets.assets.edit', $asset))->assertOk()
        ->assertSee(__('fixed_assets.messages.financial_fields_locked'));
    $this->putJson(route('admin.fixed-assets.assets.update', $asset), [
        'asset_name' => 'Updated descriptive asset name',
        'description' => 'Updated description after recognition',
        'serial_number' => 'SAFE-EDIT-001',
        'notes' => 'Descriptive edit only',
        'purchase_value' => '1',
        'asset_group_account_doc_num' => $context['postingAccounts'][4]->doc_num,
    ])->assertOk()->assertJsonPath('success', true);
    expect($asset->refresh()->purchase_value)->toBe($originalCost)
        ->and($asset->asset_name)->toBe('Updated descriptive asset name')
        ->and($asset->account->refresh()->name)->toBe('Updated descriptive asset name');
    $this->get(route('admin.fixed-assets.assets.show', $asset))->assertOk()
        ->assertSee(route('admin.fixed-assets.assets.edit', $asset), false)
        ->assertDontSee('data-delete-url="'.route('admin.fixed-assets.assets.destroy', $asset).'"', false)
        ->assertDontSee('id="recognition-modal"', false)
        ->assertSee('id="addition-modal"', false)->assertSee('id="transfer-modal"', false)->assertSee('id="custody-modal"', false);
    $disposal = app(FixedAssetLifecycleService::class)->dispose($asset, ['disposition_type' => 'write_off', 'disposal_date' => $context['period']->from_date->copy()->addDays(15)->toDateString(), 'reason' => 'Product state verification', 'proceeds' => '0']);
    $this->get(route('admin.fixed-assets.assets.edit', $asset->fresh()))->assertStatus(409);
    $this->get(route('admin.fixed-assets.assets.show', $asset))->assertOk()
        ->assertDontSee(route('admin.fixed-assets.assets.edit', $asset), false)
        ->assertDontSee('id="addition-modal"', false)->assertDontSee('id="transfer-modal"', false)->assertDontSee('id="custody-modal"', false)->assertDontSee('id="disposal-modal"', false)
        ->assertSee($disposal->doc_num);
});

test('asset register exposes a labelled actions menu with state appropriate crud actions', function (): void {
    coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.delete', 'fixed_assets.activate']);
    $context = coreFixedAssetContext();
    $unrecognized = coreFixedAsset($context, ['status' => 'active']);
    $recognized = coreRecognizedAsset($context, ['status' => 'draft']);

    $this->get(route('admin.fixed-assets.assets.index'))->assertOk()
        ->assertSee(__('common.fields.actions'))
        ->assertSee('fa-cog', false);

    $unrecognizedActions = view('modules.fixed-assets.assets.actions', ['record' => $unrecognized])->render();
    expect($unrecognizedActions)->toContain(__('common.fields.actions'))
        ->and($unrecognizedActions)->toContain(route('admin.fixed-assets.assets.edit', $unrecognized))
        ->and($unrecognizedActions)->toContain('data-delete-url="'.route('admin.fixed-assets.assets.destroy', $unrecognized).'"');

    $recognizedActions = view('modules.fixed-assets.assets.actions', ['record' => $recognized])->render();
    expect($recognizedActions)->toContain(__('common.fields.actions'))
        ->and($recognizedActions)->toContain(route('admin.fixed-assets.assets.edit', $recognized))
        ->and($recognizedActions)->not->toContain('data-delete-url="'.route('admin.fixed-assets.assets.destroy', $recognized).'"');
});

test('movement history filters original and reversed documents without losing their journal links', function (): void {
    $actor = coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.create', 'fixed_assets.activate', 'journal_entries.view']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $service = app(FixedAssetCostMovementService::class);
    $addition = $service->addition($asset, ['submission_key' => (string) Str::uuid(), 'movement_date' => $context['period']->from_date->copy()->addDays(15)->toDateString(), 'amount' => '1000', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Browser-style capital addition']);
    expect($service->canReverse($addition))->toBeTrue();
    $reversal = $service->reverse($addition, 'Keep both history rows');
    $this->get(route('admin.fixed-assets.movements.index', ['asset_doc_num' => $asset->doc_num, 'movement_type' => 'reversal', 'user' => $actor->name]))->assertOk()
        ->assertSee($addition->doc_num)->assertSee(route('admin.accounting.journal-entries.show', $reversal->reversalJournalEntry), false)
        ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()['_type'] === 'addition_reversal');
    expect($service->canReverse($reversal))->toBeFalse();
    $this->get(route('admin.fixed-assets.movements.index', ['asset_doc_num' => $asset->doc_num, 'user' => 'No matching user']))->assertOk()->assertSee(__('fixed_assets.product.no_movements'));
});

test('report menu keeps distinct reports and supports bookmarked grouped register reports', function (): void {
    coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.reports', 'fixed_assets.create']);
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context);
    expect(FixedAssetReportService::visibleTypes())->toContain('register', 'reconciliation', 'capital_additions')
        ->not->toContain('by_branch', 'by_location', 'by_cost_center', 'by_category');
    $this->get(route('admin.fixed-assets.reports.index'))->assertOk();
    $this->get(route('admin.fixed-assets.depreciation.index', ['asset_doc_nums' => [$asset->doc_num]]))->assertForbidden();
});

test('depreciation opened from a card preserves its asset selection', function (): void {
    coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.create', 'fixed_assets.depreciation.preview']);
    $context = coreFixedAssetContext();
    $asset = coreFixedAsset($context);
    $this->get(route('admin.fixed-assets.depreciation.index', ['asset_doc_nums' => [$asset->doc_num]]))->assertOk()
        ->assertSee('value="'.$asset->doc_num.'" selected', false);
});

test('reverse actions obey closed periods and later posted documents', function (): void {
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.view', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.reverse', 'fixed_assets.disposal.reverse']);
    $context = coreFixedAssetContext();
    $asset = coreRecognizedAsset($context);
    $run = corePostMonth($context, $asset);
    $depreciation = app(FixedAssetDepreciationService::class);
    expect($depreciation->canReverse($run))->toBeTrue();
    $context['period']->forceFill(['is_closed' => true])->save();
    expect($depreciation->canReverse($run))->toBeFalse();
    $this->get(route('admin.fixed-assets.depreciation.show', $run))->assertOk()->assertDontSee('action="'.route('admin.fixed-assets.depreciation.reverse', $run).'"', false);
    $context['period']->forceFill(['is_closed' => false])->save();
    $addition = app(FixedAssetCostMovementService::class)->addition($asset, ['submission_key' => (string) Str::uuid(), 'movement_date' => $context['period']->from_date->copy()->addMonth()->toDateString(), 'amount' => '1000', 'counter_account_doc_num' => $context['postingAccounts'][4]->doc_num, 'description' => 'Dependent addition']);
    expect($depreciation->canReverse($run))->toBeFalse()
        ->and(app(FixedAssetCostMovementService::class)->canReverse($addition))->toBeTrue();
    corePostMonth($context, $asset, 1);
    expect(app(FixedAssetCostMovementService::class)->canReverse($addition))->toBeFalse();
    $lifecycle = app(FixedAssetLifecycleService::class);
    $disposal = $lifecycle->dispose($asset->fresh(), ['disposition_type' => 'scrap', 'disposal_date' => $context['period']->from_date->copy()->addMonths(2)->toDateString(), 'reason' => 'Reverse guard', 'proceeds' => '0']);
    expect($lifecycle->canReverseDisposal($disposal))->toBeTrue();
    $context['period']->forceFill(['is_closed' => true])->save();
    expect($lifecycle->canReverseDisposal($disposal))->toBeFalse();
});

test('view only users can filter the register without acquiring posting permission', function (): void {
    coreFixedAssetActor(['fixed_assets.view']);
    $context = coreFixedAssetContext();
    foreach (['assets', 'asset-categories', 'branches', 'cost-centers'] as $lookup) {
        $this->getJson(route('admin.fixed-assets.select2.'.$lookup))->assertOk();
    }
    $this->getJson(route('admin.fixed-assets.select2.credit-accounts'))->assertForbidden();
    $this->get(route('admin.fixed-assets.assets.create'))->assertForbidden();
});
