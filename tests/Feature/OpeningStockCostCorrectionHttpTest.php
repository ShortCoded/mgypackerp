<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OpenDocumentsService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\OpeningStockCostCorrection;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../OpeningStockCostCorrectionSupport.php';

test('opening cost screen uses shared controls and two distinct permissions through actual approval routes', function (): void {
    $fixture = openingCorrectionFixture();
    $context = costTransitionSession($fixture);
    $day = $fixture['day'];
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '4');
    $source = $fixture['opening'];
    $show = route('admin.inventory.opening-stock-cost-corrections.show', $source);
    $this->actingAs($fixture['preparer'])->withSession($context)->get($show)->assertOk()
        ->assertSee('novalidate', false)->assertSee('js-date-picker', false)->assertSee('select2/accounts', false);
    $accounts = $this->getJson(route('admin.inventory.opening-stock-cost-corrections.select2.accounts', ['q' => 'SYNTHETIC opening cost correction clearing']))
        ->assertOk()->assertJsonStructure(['results', 'pagination' => ['more']])->json('results');
    expect(collect($accounts)->pluck('id')->all())->toBe([(string) $fixture['counterpart']->id]);
    $data = ['posting_date' => $day(3), 'counterpart_account_id' => $fixture['counterpart']->id,
        'reason' => 'SYNTHETIC HTTP source correction', 'source_reference' => 'SYNTHETIC cost source REF-002',
        'unit_costs' => [$fixture['line']->id => '8.12345678']];
    $prepare = route('admin.inventory.opening-stock-cost-corrections.prepare', $source);
    $this->post($prepare, $data)->assertRedirect($show)->assertSessionHasNoErrors();
    $proposal = OpeningStockCostCorrection::query()->where('opening_stock_id', $source->id)->sole();
    expect($proposal->unit_costs[(string) $fixture['line']->id])->toBe('8.12345678');
    $this->get($show)->assertOk()->assertSee('8.12345678')->assertSee($data['source_reference']);
    $approve = route('admin.inventory.opening-stock-cost-corrections.approve', [$source, $proposal]);
    $this->post($approve, ['approval_reference' => 'SYNTHETIC forbidden preparer'])->assertForbidden();
    $this->actingAs($fixture['approver'])->withSession($context)->get($show)->assertOk()->assertSee('approval_reference', false);
    $this->post($prepare, $data)->assertForbidden();
    $this->post($approve, ['approval_reference' => 'SYNTHETIC independent HTTP review'])->assertRedirect($show)->assertSessionHasNoErrors();
    $this->get($show)->assertOk()->assertSee($proposal->fresh()->valueAdjustment->journalEntry->doc_num);
    $this->post($approve, ['approval_reference' => 'SYNTHETIC independent HTTP review'])->assertRedirect($show);
    expect(InventoryValueAdjustment::query()->where('company_id', $source->company_id)->count())->toBe(1)
        ->and($issue->transactions->sole()->fresh()->completedTotalCost())->toBe('32.49382712')
        ->and($source->fresh()->lines->sole()->quantity)->toBe('10.0000');
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $fixture['approver']->update(['locale' => $locale]);
        $this->get($show)->assertOk()->assertSee(__('opening_stock_cost_correction.title'));
    }
    Permission::findOrCreate('journal_entries.view', 'web');
    $fixture['approver']->givePermissionTo('journal_entries.view');
    $journal = $proposal->fresh()->valueAdjustment->journalEntry;
    $storedDescription = $journal->description;
    $this->get(route('admin.accounting.journal-entries.show', $journal))->assertOk()
        ->assertSee(__('opening_stock_cost_correction.title'))->assertDontSee('Historical receipt cost completion');
    expect($journal->fresh()->description)->toBe($storedDescription);
    expect(json_encode(app(MenuService::class)->permissionStructure(), JSON_THROW_ON_ERROR))->toContain('inventory.opening_stock_cost_corrections.approve');
});

test('opening cost correction is discoverable from Open Document without a generic unlock or stock change', function (): void {
    $fixture = openingCorrectionFixture();
    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture));
    $before = DB::table('inventory_transactions')->where('company_id', $fixture['company']->id)->count();
    $selection = ['document_type' => OpenDocumentsService::OpeningStockCosts,
        'from_number' => $fixture['opening']->doc_number, 'to_number' => $fixture['opening']->doc_number];
    $preview = $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk();
    $preview->assertJsonPath('navigation_only', true)->assertJsonPath('documents.0.decision', 'correction_workflow')
        ->assertJsonPath('documents.0.correction_url', route('admin.inventory.opening-stock-cost-corrections.show', $fixture['opening']));
    $this->postJson(route('admin.tools.open-documents.store'), $selection + ['preview_token' => $preview->json('preview_token')])->assertUnprocessable();
    expect($fixture['opening']->fresh()->isApproved())->toBeTrue()
        ->and(DB::table('inventory_transactions')->where('company_id', $fixture['company']->id)->count())->toBe($before);
});

test('opening cost routes reject unauthorized context malformed precision and cross-source approval', function (): void {
    $fixture = openingCorrectionFixture();
    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture));
    $data = ['posting_date' => ($fixture['day'])(3), 'counterpart_account_id' => $fixture['counterpart']->id,
        'reason' => 'SYNTHETIC invalid precision', 'source_reference' => 'SYNTHETIC source REF-003',
        'unit_costs' => [$fixture['line']->id => '8.123456789']];
    $this->postJson(route('admin.inventory.opening-stock-cost-corrections.prepare', $fixture['opening']), $data)
        ->assertUnprocessable()->assertJsonValidationErrors('unit_costs');
    expect(OpeningStockCostCorrection::query()->count())->toBe(0);
    $outsider = closureSyntheticUser();
    $this->actingAs($outsider)->withSession(costTransitionSession($fixture))->get(route('admin.inventory.opening-stock-cost-corrections.show', $fixture['opening']))->assertForbidden();
    $this->get(route('admin.inventory.opening-stock-cost-corrections.index'))->assertForbidden();
    $other = openingCorrectionFixture();
    $other['opening']->update(['doc_num' => 'SYNTHETIC-OTHER-OS-CORRECTION']);
    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture))
        ->get(route('admin.inventory.opening-stock-cost-corrections.show', $other['opening']))->assertNotFound();
    expect(bcadd((string) InventoryTransaction::query()->where('company_id', $fixture['company']->id)->sum('quantity_in'), '0', 4))->toBe('10.0000');
});

test('opening correction resolves identical source numbers within the operating company', function (): void {
    $first = openingCorrectionFixture();
    $second = openingCorrectionFixture();
    expect($first['opening']->doc_num)->toBe($second['opening']->doc_num);
    $this->actingAs($second['preparer'])->withSession(costTransitionSession($second))
        ->get(route('admin.inventory.opening-stock-cost-corrections.show', $second['opening']))->assertOk()
        ->assertViewHas('record', fn ($record): bool => $record->id === $second['opening']->id);
});

test('inactive clearing classification blocks crafted opening preparation and stale approval atomically', function (): void {
    $fixture = openingCorrectionFixture();
    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture));
    $classification = $fixture['counterpart']->classification;
    $classification->update(['status' => 'inactive']);
    $data = ['posting_date' => ($fixture['day'])(3), 'counterpart_account_id' => $fixture['counterpart']->id,
        'reason' => 'SYNTHETIC inactive classification', 'source_reference' => 'SYNTHETIC source REF-004',
        'unit_costs' => [$fixture['line']->id => '8']];
    $prepare = route('admin.inventory.opening-stock-cost-corrections.prepare', $fixture['opening']);
    $this->postJson($prepare, $data)->assertUnprocessable()->assertJsonValidationErrors('opening_stock_cost');
    expect(OpeningStockCostCorrection::query()->count())->toBe(0);
    $classification->update(['status' => 'active']);
    $this->post($prepare, $data)->assertSessionHasNoErrors();
    $proposal = OpeningStockCostCorrection::query()->sole();
    $classification->update(['status' => 'inactive']);
    $this->actingAs($fixture['approver'])->withSession(costTransitionSession($fixture))
        ->postJson(route('admin.inventory.opening-stock-cost-corrections.approve', [$fixture['opening'], $proposal]), ['approval_reference' => 'SYNTHETIC stale account review'])
        ->assertUnprocessable()->assertJsonValidationErrors('opening_stock_cost');
    expect($proposal->fresh()->status)->toBe(OpeningStockCostCorrection::StatusPending)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0);
});
