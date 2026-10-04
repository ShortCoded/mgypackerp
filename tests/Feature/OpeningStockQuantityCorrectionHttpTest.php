<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OpenDocumentsService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStockQuantityCorrection;

require_once __DIR__.'/../OpeningStockQuantityCorrectionSupport.php';

test('opening quantity screen prepares an exact plan and independently posts a linked adjustment through actual routes', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $source = $fixture['opening'];
    $context = costTransitionSession($fixture);
    $show = route('admin.inventory.opening-stock-quantity-corrections.show', $source);
    $prepare = route('admin.inventory.opening-stock-quantity-corrections.prepare', $source);
    $this->actingAs($fixture['preparer'])->withSession($context)->get($show)->assertOk()
        ->assertSee('novalidate', false)->assertSee('js-date-picker', false)
        ->assertSee('js-select2-ajax', false)->assertSee('data-dependent-param="posting_date"', false);
    $data = ['posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC HTTP opening quantity evidence',
        'source_reference' => 'SYNTHETIC quantity REF-001',
        'targets' => [$fixture['line']->id => ['target_quantity' => '12.1234', 'unit_cost' => '7.12345678']]];
    $this->post($prepare, $data)->assertRedirect($show)->assertSessionHasNoErrors();
    $proposal = OpeningStockQuantityCorrection::query()->where('opening_stock_id', $source->id)->sole();
    expect($proposal->targets[(string) $fixture['line']->id]['target_quantity'])->toBe('12.1234')
        ->and($proposal->targets[(string) $fixture['line']->id]['unit_cost'])->toBe('7.12345678');
    $this->get($show)->assertOk()->assertSee($data['source_reference'])->assertSee('7.12345678');
    $this->get($show)->assertSee($proposal->plan['lines'][0]['accounting_contract']['debit_account']['name'])
        ->assertSee($proposal->plan['lines'][0]['accounting_contract']['credit_account']['name']);
    $approve = route('admin.inventory.opening-stock-quantity-corrections.approve', [$source, $proposal]);
    $this->post($approve, ['approval_reference' => 'SYNTHETIC preparer attempt'])->assertForbidden();
    $this->actingAs($fixture['approver'])->withSession($context)->get($show)->assertOk()->assertSee('approval_reference', false);
    $this->post($prepare, $data)->assertForbidden();
    $this->post($approve, ['approval_reference' => 'SYNTHETIC independent HTTP review'])->assertRedirect($show)->assertSessionHasNoErrors();
    $document = InventoryDocument::query()->with('transactions', 'journalEntry.lines')
        ->where('source_document_type', OpeningStockQuantityCorrection::class)->where('source_document_id', $proposal->id)->sole();
    $this->get($show)->assertOk()->assertSee($document->doc_num);
    $this->post($approve, ['approval_reference' => 'SYNTHETIC replay'])->assertRedirect($show)->assertSessionHasNoErrors();
    expect($source->fresh()->lines->sole()->quantity)->toBe('10.0000')
        ->and($document->transactions->sole()->quantity_in)->toBe('2.12340000')
        ->and($document->transactions->sole()->unit_cost)->toBe('7.12345678')
        ->and(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)->count())->toBe(1)
        ->and(bcadd((string) $document->journalEntry->lines->sum('debit_amount'), '0', 4))
        ->toBe(bcadd((string) $document->journalEntry->lines->sum('credit_amount'), '0', 4));
    foreach (['en', 'ar'] as $locale) {
        $fixture['approver']->update(['locale' => $locale]);
        app()->setLocale($locale);
        $this->get($show)->assertOk()->assertSee(__('opening_stock_quantity_correction.title'));
    }
    expect(json_encode(app(MenuService::class)->permissionStructure(), JSON_THROW_ON_ERROR))
        ->toContain('inventory.opening_stock_quantity_corrections.approve');
});

test('opening quantity is a navigation-only Open Document workflow with no generic history unlock', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture));
    $before = DB::table('inventory_transactions')->where('company_id', $fixture['company']->id)->count();
    $selection = ['document_type' => OpenDocumentsService::OpeningStockQuantities,
        'from_number' => $fixture['opening']->doc_number, 'to_number' => $fixture['opening']->doc_number];
    $preview = $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk();
    $preview->assertJsonPath('navigation_only', true)->assertJsonPath('documents.0.decision', 'correction_workflow')
        ->assertJsonPath('documents.0.correction_url', route('admin.inventory.opening-stock-quantity-corrections.show', $fixture['opening']));
    $this->postJson(route('admin.tools.open-documents.store'), $selection + ['preview_token' => $preview->json('preview_token')])->assertUnprocessable();
    expect($fixture['opening']->fresh()->isApproved())->toBeTrue()
        ->and(DB::table('inventory_transactions')->where('company_id', $fixture['company']->id)->count())->toBe($before);
});

test('opening quantity rejects malformed precision unauthorized and cross-company source requests without posting', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $context = costTransitionSession($fixture);
    $this->actingAs($fixture['preparer'])->withSession($context);
    $data = ['posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC invalid precision', 'source_reference' => 'SYNTHETIC REF-003',
        'targets' => [$fixture['line']->id => ['target_quantity' => '12.12345', 'unit_cost' => '5']]];
    $prepare = route('admin.inventory.opening-stock-quantity-corrections.prepare', $fixture['opening']);
    $this->postJson($prepare, $data)->assertUnprocessable()->assertJsonValidationErrors('targets');
    $data['targets'][$fixture['line']->id] = ['target_quantity' => '12', 'unit_cost' => '5.123456789'];
    $this->postJson($prepare, $data)->assertUnprocessable()->assertJsonValidationErrors('targets');
    expect(OpeningStockQuantityCorrection::query()->count())->toBe(0);
    $other = openingQuantityCorrectionFixture();
    $other['opening']->update(['doc_num' => 'SYNTHETIC-OTHER-QUANTITY-OS']);
    $this->actingAs($fixture['preparer'])->withSession($context)
        ->get(route('admin.inventory.opening-stock-quantity-corrections.show', $other['opening']))->assertNotFound();
    $outsider = closureSyntheticUser();
    $this->actingAs($outsider)->withSession($context)
        ->get(route('admin.inventory.opening-stock-quantity-corrections.index'))->assertForbidden();
    $this->get(route('admin.inventory.opening-stock-quantity-corrections.show', $fixture['opening']))->assertForbidden();
    expect(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)->count())->toBe(0);
});

test('opening layer selector is scoped to the source line and posting date with the paginated Select2 contract', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture));
    $layer = InventoryReceiptLayer::query()->where('company_id', $fixture['company']->id)->sole();
    $future = $layer->replicate();
    $future->forceFill(['original_receipt_date' => ($fixture['day'])(7), 'source_doc_num' => 'SYNTHETIC future layer'])->save();
    $url = route('admin.inventory.opening-stock-quantity-corrections.select2.layers', [$fixture['opening'], $fixture['line']]);
    $this->getJson($url)->assertUnprocessable();
    $results = $this->getJson($url.'?posting_date='.($fixture['day'])(3))->assertOk()
        ->assertJsonStructure(['results', 'pagination' => ['more']])->json('results');
    expect(collect($results)->pluck('id')->all())->toBe([(string) $layer->id]);
    $other = openingQuantityCorrectionFixture();
    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture))
        ->getJson(route('admin.inventory.opening-stock-quantity-corrections.select2.layers', [$fixture['opening'], $other['line']], false)
            .'?posting_date='.($fixture['day'])(3))->assertNotFound();
});

test('stale opening quantity approval returns a useful error and leaves source stock and pending plan unchanged', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $context = costTransitionSession($fixture);
    $proposal = prepareOpeningQuantityCorrection($fixture, '12', ($fixture['day'])(3), '5');
    costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeIssue, '1');
    $before = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->count();
    $this->actingAs($fixture['approver'])->withSession($context)
        ->postJson(route('admin.inventory.opening-stock-quantity-corrections.approve', [$fixture['opening'], $proposal]),
            ['approval_reference' => 'SYNTHETIC stale HTTP review'])
        ->assertUnprocessable()->assertJsonValidationErrors('opening_stock_quantity');
    expect($proposal->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusPending)
        ->and($fixture['line']->fresh()->quantity)->toBe('10.0000')
        ->and(InventoryTransaction::query()->where('company_id', $fixture['company']->id)->count())->toBe($before);
});

test('serialized quantity routes expose available serials preserve validation selection and post the reviewed average', function (): void {
    $fixture = serializedOpeningQuantityCorrectionFixture(InventoryCostPolicy::MovingAverage);
    $context = costTransitionSession($fixture);
    $source = $fixture['opening'];
    $show = route('admin.inventory.opening-stock-quantity-corrections.show', $source);
    $prepare = route('admin.inventory.opening-stock-quantity-corrections.prepare', $source);
    $selector = route('admin.inventory.opening-stock-quantity-corrections.select2.layers', [$source, $fixture['line']]);
    $layers = $fixture['serialLayers']->values();
    $this->actingAs($fixture['preparer'])->withSession($context)->get($show)->assertOk()
        ->assertSee('multiple', false)->assertSee('serial_receipt_layer_ids', false)->assertSee('serial_numbers', false);
    $this->getJson($selector.'?posting_date='.($fixture['day'])(3).'&q=SYNTHETIC-OPEN-SERIAL-B')
        ->assertOk()->assertJsonPath('pagination.more', false)->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.id', (string) $fixture['serialLayers']['SYNTHETIC-OPEN-SERIAL-B']->id);
    $data = ['posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC HTTP serial correction',
        'source_reference' => 'SYNTHETIC selected unit evidence',
        'targets' => [$fixture['line']->id => ['target_quantity' => '1', 'unit_cost' => '7.123456789',
            'serial_receipt_layer_ids' => [$layers[0]->id, $layers[1]->id]]]];
    $this->from($show)->post($prepare, $data)->assertRedirect($show)->assertSessionHasErrors('targets');
    $this->get($show)->assertOk()->assertSee('selected value="'.$layers[0]->id.'"', false)
        ->assertSee('selected value="'.$layers[1]->id.'"', false);
    unset($data['targets'][$fixture['line']->id]['unit_cost']);
    $this->post($prepare, $data)->assertRedirect($show)->assertSessionHasNoErrors();
    $proposal = OpeningStockQuantityCorrection::query()->where('opening_stock_id', $source->id)->sole();
    $this->get($show)->assertOk()->assertSee('SYNTHETIC-OPEN-SERIAL-A')->assertSee('SYNTHETIC-OPEN-SERIAL-B')
        ->assertSee($proposal->plan['lines'][0]['serial_units'][0]['accounting_contract']['debit_account']['name']);
    $this->actingAs($fixture['approver'])->withSession($context)
        ->post(route('admin.inventory.opening-stock-quantity-corrections.approve', [$source, $proposal]),
            ['approval_reference' => 'SYNTHETIC independent selected unit review'])->assertRedirect($show)->assertSessionHasNoErrors();
    $document = InventoryDocument::query()->with('transactions')->where('source_document_type', OpeningStockQuantityCorrection::class)
        ->where('source_document_id', $proposal->id)->sole();
    expect($document->transactions->pluck('total_cost')->all())->toBe(['3.33333333', '3.33333333']);
});

test('serialized quantity routes reject foreign consumed duplicate malformed and already recorded units without mutation', function (): void {
    $fixture = serializedOpeningQuantityCorrectionFixture();
    $context = costTransitionSession($fixture);
    $foreign = serializedOpeningQuantityCorrectionFixture();
    $layers = $fixture['serialLayers']->values();
    costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeIssue, '1',
        lineOverrides: ['selected_receipt_layer_id' => $layers[0]->id]);
    $prepare = route('admin.inventory.opening-stock-quantity-corrections.prepare', $fixture['opening']);
    $this->actingAs($fixture['preparer'])->withSession($context);
    $data = ['posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC invalid HTTP serial selection',
        'source_reference' => 'SYNTHETIC invalid units evidence'];
    foreach ([[$foreign['serialLayers']->first()->id], [$layers[0]->id]] as $selection) {
        $this->postJson($prepare, $data + ['targets' => [$fixture['line']->id => ['target_quantity' => '2',
            'serial_receipt_layer_ids' => $selection]]])->assertUnprocessable()->assertJsonValidationErrors('opening_stock_quantity');
    }
    $this->postJson($prepare, $data + ['targets' => [$fixture['line']->id => ['target_quantity' => '1',
        'serial_receipt_layer_ids' => [$layers[1]->id, $layers[1]->id]]]])->assertUnprocessable();
    $this->postJson($prepare, $data + ['targets' => [$fixture['line']->id => ['target_quantity' => '4',
        'unit_cost' => '8', 'serial_numbers' => 'synthetic-open-serial-a']]])->assertUnprocessable();
    $this->postJson($prepare, $data + ['targets' => [$fixture['line']->id => ['target_quantity' => '2',
        'serial_receipt_layer_ids' => [['invalid']]]]])->assertUnprocessable();
    expect(OpeningStockQuantityCorrection::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)
        ->and(InventoryDocument::query()->where('company_id', $fixture['company']->id)
            ->where('source_document_type', OpeningStockQuantityCorrection::class)->count())->toBe(0)
        ->and($fixture['line']->fresh()->quantity)->toBe('3.0000');
});

test('malformed correction fields return validation errors and safely redisplay the real form', function (): void {
    $fixture = serializedOpeningQuantityCorrectionFixture();
    $show = route('admin.inventory.opening-stock-quantity-corrections.show', $fixture['opening']);
    $prepare = route('admin.inventory.opening-stock-quantity-corrections.prepare', $fixture['opening']);
    $data = ['posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC malformed form evidence',
        'source_reference' => 'SYNTHETIC malformed input review',
        'targets' => [$fixture['line']->id => ['target_quantity' => '4', 'unit_cost' => '8', 'serial_numbers' => 'SYNTHETIC-VALID-NEW']]];
    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture));
    foreach (['posting_date', 'reason', 'source_reference', 'targets.'.$fixture['line']->id.'.target_quantity',
        'targets.'.$fixture['line']->id.'.unit_cost', 'targets.'.$fixture['line']->id.'.serial_numbers'] as $field) {
        $malformed = $data;
        data_set($malformed, $field, [['invalid']]);
        $this->from($show)->post($prepare, $malformed)->assertRedirect($show)->assertSessionHasErrors();
        $this->get($show)->assertOk()->assertSee('SYNTHETIC malformed');
    }
    expect(OpeningStockQuantityCorrection::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)
        ->and(InventoryDocument::query()->where('company_id', $fixture['company']->id)
            ->where('source_document_type', OpeningStockQuantityCorrection::class)->count())->toBe(0);
});
