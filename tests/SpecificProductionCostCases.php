<?php

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\QualityInspectionType;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionMaterialRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ProductionHandoverSupport.php';
require_once __DIR__.'/ManufacturingInventorySupport.php';

/** @return array<string, mixed> */
function specificProductionFixture(bool $withBatch = false): array
{
    $fixture = manufacturingInventoryFixture('-SYNTHETIC-SELECTED-'.Str::random(8));
    $fixture['branch']->update(['type' => Branch::TypeFactory]);
    $fixture['store'] = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC selected production layers '.$fixture['raw']->doc_num]);
    foreach (['inventory.cost_policies.manage', 'production.material_requests.issue', 'production.material_requests.view', 'inventory.documents.view', 'inventory.documents.issue'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    app(InventoryCostPolicyService::class)->createVersion($fixture['company']->id, [
        'branch_store_id' => $fixture['store']->id, 'method' => InventoryCostPolicy::SpecificIdentification,
        'effective_from' => now()->toDateString(), 'reason' => 'SYNTHETIC selected production acceptance',
    ], $fixture['user']->id);
    $receipts = [];
    foreach ([['4', '10', 'SYNTHETIC-CHEAP'], ['6', '20', 'SYNTHETIC-EXPENSIVE']] as [$quantity, $price, $batch]) {
        $receipt = app(InventoryMovementService::class)->createAndPost([
            'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
            'financial_period_id' => $fixture['period']->id, 'branch_store_id' => $fixture['store']->id,
            'document_date' => now()->toDateString(), 'document_type' => InventoryDocument::TypeAdjustmentIn,
        ], [['product_id' => $fixture['raw']->id, 'quantity' => $quantity, 'unit_cost' => $price, 'batch_lot' => $batch]]);
        $receipts[] = InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->transactions->sole()->id)->sole();
    }
    $details = manufacturingIntegrityRun($fixture, '5', asBatch: $withBatch);
    $requirement = $details['run']->requirements->sole();
    $materials = app(ProductionMaterialRequestService::class);
    $materialRequest = $withBatch ? null : $materials->create($details['run'], $fixture['store']->id, [$requirement->id => '10']);
    if ($materialRequest !== null) {
        $materials->approve($materialRequest);
    }
    $requestLine = $materialRequest?->fresh()->lines->sole();
    test()->actingAs($fixture['user'])->withSession(manufacturingIntegritySession($fixture));

    return [...$fixture, ...$details, 'materials' => $materials, 'materialRequest' => $materialRequest,
        'requestLine' => $requestLine, 'requirement' => $requirement, 'cheapLayer' => $receipts[0], 'expensiveLayer' => $receipts[1]];
}

/** @return array<string, mixed> */
function specificProductionPayload(array $fixture, array $selections, string $quantity = '10'): array
{
    return ['_submission_token' => (string) Str::uuid(), 'return_to' => 'inventory_document', 'lines' => [[
        'request_line_id' => $fixture['requestLine']->id, 'quantity' => $quantity, 'receipt_layers' => $selections,
    ]]];
}

test('production selected batch issue return consumption waste and finished receipt retain actual cost and lineage', function (): void {
    $this->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $fixture = specificProductionFixture();
    $this->get(route('admin.inventory.documents.production-material-issue.create', ['material_request' => $fixture['materialRequest']->doc_num]))
        ->assertOk()->assertSee('lines[0][receipt_layers][0][layer_id]', false);
    $this->get(route('admin.production.material-requests.show', $fixture['materialRequest']))->assertOk()->assertSee('data-material-layer-selections', false);
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', ['branch_store_uuid' => $fixture['store']->public_uuid,
        'product_doc_num' => $fixture['raw']->doc_num, 'document_date' => now()->toDateString()]))->assertOk()->assertJsonCount(2, 'results');
    $this->postJson(route('admin.production.material-requests.issue', $fixture['materialRequest']), specificProductionPayload($fixture, [
        ['layer_id' => $fixture['cheapLayer']->id, 'quantity' => '4'], ['layer_id' => $fixture['expensiveLayer']->id, 'quantity' => '6'],
    ]))->assertOk();
    $issue = InventoryDocument::query()->where('production_material_request_id', $fixture['materialRequest']->id)->sole();
    expect($issue->production_run_id)->toBe($fixture['run']->id)->and($issue->lines)->toHaveCount(2)
        ->and($fixture['requestLine']->fresh()->issued_quantity)->toBe('10.00000000');
    $out = $issue->transactions()->where('quantity_out', '>', 0)->get();
    expect(bcadd((string) $out->sum('total_cost'), '0', 8))->toBe('160.00000000');
    foreach ($out as $transaction) {
        $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $transaction->id)->sole();
        expect($allocation->layer->batch_lot)->toBe($transaction->batch_lot);
    }
    $return = $fixture['cycle']->returnMaterials($fixture['run']->fresh(), $fixture['store']->id, [$fixture['requirement']->id => '2']);
    expect($return->transactions()->where('quantity_in', '>', 0)->sole()->total_cost)->toBe('20.00000000');
    $run = $fixture['cycle']->startRun($fixture['cycle']->completeSetup($fixture['cycle']->startSetup($fixture['run']->fresh())));
    $fixture['cycle']->recordProgress($run, ['good_base_quantity' => '5']);
    $accounting = $fixture['cycle']->accountMaterials($run->fresh(), $fixture['store']->id, [
        $fixture['requirement']->id => ['consumed_quantity' => '7', 'waste_quantity' => '1'],
    ]);
    expect($accounting['consumption']->lines)->toHaveCount(2)
        ->and(bcadd((string) $accounting['consumption']->transactions()->sum('total_cost'), '0', 8))->toBe('120.00000000')
        ->and($accounting['waste']->transactions->sole()->total_cost)->toBe('20.00000000');
    $inspection = $fixture['cycle']->recordInspection($run->fresh(), ['result' => 'passed', 'disposition' => 'release']);
    $fixture['cycle']->reviewInspection($inspection, true);
    $receipt = $fixture['cycle']->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '5');
    $run = $fixture['cycle']->completeRun($run->fresh());
    expect($receipt->transactions->sole()->total_cost)->toBe('120.00000000')
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000')
        ->and(app(ProductionCostService::class)->runPosition($run)['waste'])->toBe('20.00000000');
    expect(bccomp((string) InventoryTransaction::query()->where('production_run_id', $run->id)->where('stock_status', InventoryTransaction::StatusProductionStaging)->selectRaw('sum(quantity_in-quantity_out) as quantity')->first()->quantity, '0', 8))->toBe(0);
});

test('production layer selections fail atomically and survive validation redisplay without broad permissions', function (): void {
    $this->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $fixture = specificProductionFixture();
    $before = InventoryDocument::query()->count();
    $url = route('admin.production.material-requests.issue', $fixture['materialRequest']);
    $this->postJson($url, specificProductionPayload($fixture, [['layer_id' => $fixture['cheapLayer']->id, 'quantity' => '10']]))->assertUnprocessable();
    $this->postJson($url, specificProductionPayload($fixture, [['layer_id' => $fixture['cheapLayer']->id, 'quantity' => '2'], ['layer_id' => $fixture['cheapLayer']->id, 'quantity' => '2']], '4'))->assertUnprocessable();
    $this->postJson($url, specificProductionPayload($fixture, [['layer_id' => $fixture['expensiveLayer']->id, 'quantity' => '1']], '0'))->assertUnprocessable();
    expect($fixture['requestLine']->fresh()->issued_quantity)->toBe('0.00000000')
        ->and($fixture['cheapLayer']->fresh()->remaining_quantity)->toBe('4.00000000')
        ->and($fixture['expensiveLayer']->fresh()->remaining_quantity)->toBe('6.00000000')
        ->and(InventoryDocument::query()->count())->toBe($before);
    $this->withSession(['_old_input' => specificProductionPayload($fixture, [5 => ['layer_id' => $fixture['expensiveLayer']->id, 'quantity' => '6']])])
        ->get(route('admin.inventory.documents.production-material-issue.create', ['material_request' => $fixture['materialRequest']->doc_num]))
        ->assertOk()->assertSee('data-next-slice="6"', false)->assertSee('value="'.$fixture['expensiveLayer']->id.'" selected', false);
    $fixture['user']->revokePermissionTo('production.material_requests.issue');
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers'))->assertForbidden();
});

test('actual batch issue selects partial receipt layers replaces only own unlinked reservations and returns exact costs', function (): void {
    $this->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $fixture = specificProductionFixture(true);
    foreach (['production.runs.view', 'production.runs.issue'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $fixture['cycle']->reserveRun($fixture['run'], $fixture['store']->id);
    $original = $fixture['run']->reservations()->get();
    expect($original)->toHaveCount(2);
    $this->get(route('admin.production.runs.batches.show', $fixture['batch']))->assertOk()
        ->assertSee(route('admin.production.runs.batches.issue-create', $fixture['batch']), false);
    $this->get(route('admin.production.runs.batches.issue-create', $fixture['batch']))->assertOk()
        ->assertSee('data-layer-store-selector', false)->assertSee('lines[0][receipt_layers][0][layer_id]', false);
    $url = route('admin.production.runs.batches.issue', $fixture['batch']);
    $payload = ['_submission_token' => (string) Str::uuid(), 'branch_store_id' => $fixture['store']->id, 'lines' => [[
        'requirement_id' => $fixture['requirement']->id, 'receipt_layers' => [['layer_id' => $fixture['expensiveLayer']->id, 'quantity' => '6']],
    ]]];
    $before = InventoryDocument::query()->count();
    $this->postJson($url, [...$payload, '_submission_token' => (string) Str::uuid(), 'lines' => [['requirement_id' => 999999999, 'receipt_layers' => $payload['lines'][0]['receipt_layers']]]])->assertUnprocessable();
    $this->postJson($url, [...$payload, '_submission_token' => (string) Str::uuid(), 'lines' => [['requirement_id' => $fixture['requirement']->id, 'receipt_layers' => [['layer_id' => $fixture['expensiveLayer']->id, 'quantity' => '7']]]]])->assertUnprocessable();
    expect(InventoryDocument::query()->count())->toBe($before)->and($fixture['requirement']->fresh()->issued_quantity)->toBe('0.00000000');
    $response = $this->postJson($url, $payload)->assertOk();
    $first = InventoryDocument::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($first->lines->sole()->selected_receipt_layer_id)->toBe($fixture['expensiveLayer']->id)
        ->and($first->transactions()->where('quantity_out', '>', 0)->sole()->total_cost)->toBe('120.00000000')
        ->and($fixture['requirement']->fresh()->issued_quantity)->toBe('6.00000000');
    foreach ($original as $reservation) {
        expect($reservation->fresh()->status)->toBe(InventoryReservation::StatusReleased)
            ->and($reservation->fresh()->released_by)->toBe($fixture['user']->id);
    }
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.doc_num', $first->doc_num);
    expect($fixture['requirement']->fresh()->issued_quantity)->toBe('6.00000000');
    $response = $this->postJson($url, [...$payload, '_submission_token' => (string) Str::uuid(), 'lines' => [['requirement_id' => $fixture['requirement']->id,
        'receipt_layers' => [['layer_id' => $fixture['cheapLayer']->id, 'quantity' => '4']]]]])->assertOk();
    $second = InventoryDocument::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($second->transactions()->where('quantity_out', '>', 0)->sole()->total_cost)->toBe('40.00000000')
        ->and($fixture['requirement']->fresh()->issued_quantity)->toBe('10.00000000');
    $return = $fixture['cycle']->returnMaterials($fixture['run']->fresh(), $fixture['store']->id, [$fixture['requirement']->id => '10']);
    expect(bcadd((string) $return->transactions()->where('quantity_in', '>', 0)->sum('total_cost'), '0', 8))->toBe('160.00000000')
        ->and($fixture['requirement']->fresh()->returned_quantity)->toBe('10.00000000');
});

test('inventory movement batch issue exposes and accepts the same scoped selected receipt layers', function (): void {
    $this->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $fixture = specificProductionFixture(true);
    foreach (['production.runs.view', 'production.runs.issue', 'inventory.documents.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->get(route('admin.inventory.documents.create'))->assertOk()->assertSee('data-inventory-batch-material-template', false);
    $this->getJson(route('admin.inventory.documents.production-batches.details', ['publicId' => $fixture['batch']->public_id, 'document_type' => InventoryDocument::TypeIssue]))
        ->assertOk()->assertJsonPath('data.materials.0.requirement_id', $fixture['requirement']->id)->assertJsonPath('data.materials.0.product_doc_num', $fixture['raw']->doc_num);
    $payload = ['_submission_token' => (string) Str::uuid(), 'document_type' => InventoryDocument::TypeIssue, 'document_date' => now()->toDateString(),
        'branch_store_uuid' => $fixture['store']->public_uuid, 'production_run_batch_public_id' => $fixture['batch']->public_id,
        'batch_material_selections' => [['requirement_id' => $fixture['requirement']->id, 'receipt_layers' => [
            ['layer_id' => $fixture['cheapLayer']->id, 'quantity' => '4'], ['layer_id' => $fixture['expensiveLayer']->id, 'quantity' => '6'],
        ]]], 'lines' => []];
    $this->withSession(['_old_input' => $payload])->get(route('admin.inventory.documents.create'))->assertOk()
        ->assertSee('value="'.$fixture['batch']->public_id.'" selected', false)->assertSee('value="'.$fixture['store']->public_uuid.'" selected', false)
        ->assertSee('"oldBatchSelections":[{"requirement_id":'.$fixture['requirement']->id, false);
    $this->withSession(['_old_input' => []]);
    $before = InventoryDocument::query()->count();
    $this->postJson(route('admin.inventory.documents.store'), Arr::except($payload, '_submission_token'))->assertUnprocessable();
    expect(InventoryDocument::query()->count())->toBe($before)->and($fixture['requirement']->fresh()->issued_quantity)->toBe('0.00000000');
    $response = $this->postJson(route('admin.inventory.documents.store'), $payload)->assertCreated();
    $issue = InventoryDocument::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($issue->lines->pluck('selected_receipt_layer_id')->sort()->values()->all())->toBe([$fixture['cheapLayer']->id, $fixture['expensiveLayer']->id])
        ->and(bcadd((string) $issue->transactions()->where('quantity_out', '>', 0)->sum('total_cost'), '0', 8))->toBe('160.00000000')
        ->and($issue->document_date->toDateString())->toBe('2026-09-29');
    $this->postJson(route('admin.inventory.documents.store'), $payload)->assertCreated()->assertJsonPath('data.doc_num', $issue->doc_num);
    expect(InventoryDocument::query()->count())->toBe($before + 1);
    $fixture['user']->revokePermissionTo('production.runs.issue');
    $this->postJson(route('admin.inventory.documents.store'), $payload)->assertForbidden();
    $this->postJson(route('admin.inventory.documents.store'), [...$payload, '_submission_token' => (string) Str::uuid()])->assertForbidden();
});

test('selected batch issue preserves approved request reservations while replacing only unlinked quantities', function (): void {
    $this->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $fixture = specificProductionFixture(true);
    foreach (['production.runs.issue', 'production.material_requests.issue'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $materials = app(ProductionMaterialRequestService::class);
    $request = $materials->approve($materials->create($fixture['run'], $fixture['store']->id, [$fixture['requirement']->id => '6']));
    $line = $request->lines->sole();
    $fixture['cycle']->reserveRun($fixture['run']->fresh(), $fixture['store']->id);
    $linked = InventoryReservation::query()->where('production_material_request_line_id', $line->id)->get();
    $snapshot = $linked->map(fn ($reservation) => $reservation->getAttributes())->all();
    $unlinked = $fixture['run']->reservations()->whereNull('production_material_request_line_id')->get();
    expect($linked->sum('quantity'))->toEqual(6)->and($unlinked->sum('quantity'))->toEqual(4);
    $payload = ['_submission_token' => (string) Str::uuid(), 'branch_store_id' => $fixture['store']->id, 'lines' => [[
        'requirement_id' => $fixture['requirement']->id,
        'receipt_layers' => [['layer_id' => $fixture['expensiveLayer']->id, 'quantity' => '5']],
    ]]];
    $before = InventoryDocument::query()->count();
    $url = route('admin.production.runs.batches.issue', $fixture['batch']);
    $this->postJson($url, $payload)->assertUnprocessable();
    expect(InventoryDocument::query()->count())->toBe($before)
        ->and($fixture['requirement']->fresh()->reserved_quantity)->toBe('10.00000000')
        ->and($fixture['requirement']->fresh()->issued_quantity)->toBe('0.00000000')
        ->and($fixture['expensiveLayer']->fresh()->remaining_quantity)->toBe('6.00000000');
    foreach ($unlinked as $reservation) {
        expect($reservation->fresh()->status)->toBe(InventoryReservation::StatusActive);
    }
    $payload['lines'][0]['receipt_layers'][0]['quantity'] = '4';
    $response = $this->postJson($url, $payload)->assertOk();
    $issue = InventoryDocument::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($issue->transactions()->where('quantity_out', '>', 0)->sole()->total_cost)->toBe('80.00000000')
        ->and($fixture['requirement']->fresh()->issued_quantity)->toBe('4.00000000')
        ->and($line->fresh()->reserved_quantity)->toBe('6.00000000')->and($line->fresh()->issued_quantity)->toBe('0.00000000');
    expect($linked->map(fn ($reservation) => $reservation->fresh()->getAttributes())->all())->toBe($snapshot);
    $cheap = ['layer_id' => $fixture['cheapLayer']->id, 'quantity' => '4'];
    $expensive = ['layer_id' => $fixture['expensiveLayer']->id, 'quantity' => '2'];
    $this->postJson(route('admin.production.material-requests.issue', $request), [
        '_submission_token' => (string) Str::uuid(), 'lines' => [['request_line_id' => $line->id, 'quantity' => '6', 'receipt_layers' => [$cheap, $expensive]]],
    ])->assertOk();
    expect($fixture['requirement']->fresh()->issued_quantity)->toBe('10.00000000')
        ->and($line->fresh()->issued_quantity)->toBe('6.00000000');
});

test('finished goods HTTP receipt requires resolved materials and quality and completes only after all partial receipts', function (string $locale): void {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $f = specificProductionFixture();
    $finalType = QualityInspectionType::query()->create([
        'company_id' => $f['company']->id, 'code' => 'SYNTHETIC-FINAL-RECEIPT', 'name' => 'SYNTHETIC final quality release',
        'is_final_production' => true, 'is_active' => true,
    ]);
    foreach (['production.runs.view', 'production.runs.setup', 'production.runs.progress', 'production.runs.account_materials', 'production.runs.receive', 'production.handovers.create', 'production.handovers.approve', 'production.runs.complete'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $f['user']->givePermissionTo($ability);
    }
    $this->withSession(['locale' => $locale]);
    $this->postJson(route('admin.production.material-requests.issue', $f['materialRequest']), specificProductionPayload($f, [
        ['layer_id' => $f['cheapLayer']->id, 'quantity' => '4'], ['layer_id' => $f['expensiveLayer']->id, 'quantity' => '6'],
    ]))->assertOk();
    foreach (['setup.start', 'setup.complete', 'start'] as $action) {
        $this->postJson(route('admin.production.runs.'.$action, $f['run']), ['_submission_token' => (string) Str::uuid()])->assertOk();
    }
    $this->postJson(route('admin.production.runs.progress', $f['run']), ['_submission_token' => (string) Str::uuid(), 'good_base_quantity' => '5'])->assertOk();
    $warehouse = closureSyntheticUser();
    foreach (['inventory.production_receipts.create', 'inventory.production_receipts.approve'] as $permission) {
        $warehouse->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $receive = fn (array $data): TestResponse => productionHandoverHttpReceipt($f['run'], $data, $warehouse);
    $payload = ['_submission_token' => (string) Str::uuid(), 'branch_store_id' => $f['store']->id, 'base_quantity' => '2'];
    $receive($payload)->assertUnprocessable();
    $this->postJson(route('admin.production.runs.account', $f['run']), ['_submission_token' => (string) Str::uuid(), 'branch_store_id' => $f['store']->id,
        'lines' => [['requirement_id' => $f['requirement']->id, 'consumed_quantity' => '10', 'waste_quantity' => '0']]])->assertOk();
    $receive([...$payload, '_submission_token' => (string) Str::uuid()])->assertUnprocessable();
    $inspection = $f['cycle']->recordInspection($f['run']->fresh(), ['quality_inspection_type_id' => $finalType->id, 'result' => 'passed', 'disposition' => 'release']);
    $f['cycle']->reviewInspection($inspection, true);
    $this->get(route('admin.production.runs.show', $f['run']))->assertOk()->assertSee('admin/production/runs', false);
    $first = $receive($payload)->assertOk();
    $receive($payload)->assertOk()->assertJsonPath('doc_num', $first->json('doc_num'));
    expect($f['run']->fresh()->received_base_quantity)->toBe('2.00000000');
    $this->postJson(route('admin.production.runs.complete', $f['run']), [])->assertUnprocessable();
    $receive([...$payload, '_submission_token' => (string) Str::uuid(), 'base_quantity' => '3'])->assertOk();
    $this->postJson(route('admin.production.runs.complete', $f['run']), [])->assertOk();
    $run = $f['run']->fresh();
    expect($run->status)->toBe(ProductionRun::StatusCompleted)->and($run->received_base_quantity)->toBe('5.00000000')
        ->and($run->order->status)->toBe(ProductionOrder::StatusCompleted)
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000');
    $receipts = $run->lineInventoryDocuments()->where('document_type', InventoryDocument::TypeProductionReceipt)->get();
    expect($receipts)->toHaveCount(2);
    $costs = $receipts->map(fn ($receipt): string => $receipt->transactions->sole()->total_cost)->all();
    expect(bcadd($costs[0], $costs[1], 8))->toBe('160.00000000')->and(bcadd($costs[0], '0', 8))->toBe('64.00000000')
        ->and(bcadd($costs[1], '0', 8))->toBe('96.00000000');
    foreach ($receipts as $receipt) {
        $journal = $receipt->journalEntry;
        expect(bccomp((string) $journal->lines->sum('debit_amount'), (string) $journal->lines->sum('credit_amount'), 4))->toBe(0)
            ->and(bccomp((string) $journal->lines->sum('debit_amount'), $receipt->transactions->sole()->total_cost, 4))->toBe(0);
    }
    $stock = InventoryTransaction::query()->where('product_id', $f['finished']->id)->where('branch_store_id', $f['store']->id)
        ->where('stock_status', InventoryTransaction::StatusAvailable)->selectRaw('coalesce(sum(quantity_in - quantity_out),0) as quantity')->first()->quantity;
    expect(bcadd((string) $stock, '0', 8))->toBe('5.00000000');
    $receive([...$payload, '_submission_token' => (string) Str::uuid(), 'base_quantity' => '1'])->assertUnprocessable();
})->with(['ar', 'en']);
