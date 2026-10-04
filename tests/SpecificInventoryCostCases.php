<?php

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\BranchStore;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryCostPolicyTransitionService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryCostTransitionSupport.php';

/** @return array<string, mixed> */
function specificCostFixture(bool $distinctBatches = false, bool $serials = false, string $method = InventoryCostPolicy::SpecificIdentification): array
{
    $fixture = costTransitionFixture('-SYNTHETIC-SPECIFIC-'.Str::random(8));
    if ($serials) {
        $fixture['product']->update(['tracks_serials' => true]);
    }
    foreach (['inventory.documents.view', 'inventory.documents.create', 'inventory.documents.issue', 'inventory.documents.post', 'inventory.documents.transfer', 'inventory.documents.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['preparer']->givePermissionTo($permission);
    }
    test()->actingAs($fixture['preparer'])->withSession(session()->all())->postJson(route('admin.inventory.cost-policies.store'), [
        'branch_store_uuid' => $fixture['store']->public_uuid, 'method' => $method,
        'effective_from' => $fixture['period']->from_date->toDateString(), 'reason' => 'SYNTHETIC selected receipt cost',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(InventoryCostPolicy::query()->where('branch_store_id', $fixture['store']->id)->sole()->method)->toBe($method);
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $cheap = costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '10', '10',
        [...($distinctBatches ? ['batch_lot' => 'SYNTHETIC-CHEAP'] : []), ...($serials ? ['serial_numbers' => array_map(fn (int $i): string => 'SYNTHETIC-CHEAP-'.$i, range(1, 10))] : [])]);
    $expensive = costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '10', '20',
        [...($distinctBatches ? ['batch_lot' => 'SYNTHETIC-EXPENSIVE'] : []), ...($serials ? ['serial_numbers' => array_map(fn (int $i): string => 'SYNTHETIC-EXPENSIVE-'.$i, range(1, 10))] : [])]);
    $layer = InventoryReceiptLayer::query()->whereIn('receipt_transaction_id', $expensive->transactions->modelKeys())->orderBy('id')->firstOrFail();

    return [...$fixture, 'day' => $day, 'cheap' => $cheap, 'expensive' => $expensive, 'layer' => $layer];
}

/** @return array<string, mixed> */
function specificIssuePayload(array $fixture, array $lines): array
{
    return ['branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => $fixture['day'],
        'document_type' => InventoryDocument::TypeIssue, 'movement_reason' => 'SYNTHETIC selected batch issue',
        'source_stock_status' => 'available', 'submit_action' => 'post_and_view', 'lines' => $lines];
}

test('specific identification policy selector and actual issue cost the selected expensive layer and reverse exactly', function (): void {
    $fixture = specificCostFixture();
    $this->get(route('admin.inventory.documents.create'))->assertOk()->assertSee('selected_receipt_layer_id', false);
    config(['select2.pagination.per_page' => 1]);
    $selector = route('admin.inventory.documents.select2.receipt-layers', ['branch_store_uuid' => $fixture['store']->public_uuid,
        'product_doc_num' => $fixture['product']->doc_num, 'document_date' => $fixture['day'], 'per_page' => 1]);
    $this->getJson($selector)->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('pagination.more', true);
    $response = $this->postJson(route('admin.inventory.documents.store'), specificIssuePayload($fixture, [[
        'product_doc_num' => $fixture['product']->doc_num, 'quantity' => '4', 'selected_receipt_layer_id' => $fixture['layer']->id,
    ]]))->assertCreated();
    $issue = InventoryDocument::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $transaction = $issue->transactions->sole();
    expect($transaction->cost_method)->toBe(InventoryCostPolicy::SpecificIdentification)->and($transaction->cost_basis)->toBe('selected_receipt_layer')
        ->and($transaction->total_cost)->toBe('80.00000000')->and($fixture['layer']->fresh()->remaining_quantity)->toBe('6.00000000')
        ->and(InventoryLayerAllocation::query()->where('issue_transaction_id', $transaction->id)->sole()->inventory_receipt_layer_id)->toBe($fixture['layer']->id);
    app(InventoryDocumentPostingService::class)->reverse($issue, 'SYNTHETIC selected issue reversal');
    $net = $issue->transactions()->selectRaw('sum(quantity_in-quantity_out) as quantity, sum(case when quantity_in>0 then total_cost else -total_cost end) as value')->first();
    expect(bccomp((string) $net->quantity, '0', 8))->toBe(0)->and(bccomp((string) $net->value, '0', 8))->toBe(0);
});

test('approved existing moving-average balances transition to selected identification at their frozen book basis', function (): void {
    $fixture = costTransitionFixture('-SYNTHETIC-EXISTING-'.Str::random(8));
    foreach (['inventory.documents.create', 'inventory.documents.issue', 'inventory.documents.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['preparer']->givePermissionTo($permission);
    }
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeAdjustmentIn, '10', '10');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeAdjustmentIn, '10', '20');
    $originalIssue = costTransitionMovement($fixture, $day(3), InventoryDocument::TypeIssue, '12');
    $layer = InventoryReceiptLayer::query()->where('branch_store_id', $fixture['store']->id)->where('remaining_quantity', '>', 0)->sole();
    expect($originalIssue->transactions->sole()->total_cost)->toBe('180.00000000')->and($layer->unit_cost)->toBe('20.00000000');
    $transactionsBefore = InventoryTransaction::query()->count();
    $journalsBefore = JournalEntry::query()->count();
    $this->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture))
        ->post(route('admin.inventory.cost-policies.transitions.prepare'), [
            'transition_branch_store_uuid' => $fixture['store']->public_uuid, 'transition_effective_from' => $day(4),
            'transition_target_method' => InventoryCostPolicy::SpecificIdentification, 'transition_reason' => 'SYNTHETIC explicit selected-cost transition',
        ])->assertRedirect()->assertSessionHasNoErrors();
    $transition = InventoryCostPolicyTransition::query()->where('branch_store_id', $fixture['store']->id)->sole();
    expect($transition->target_method)->toBe(InventoryCostPolicy::SpecificIdentification)
        ->and($transition->total_book_value)->toBe('120.00000000')->and($transition->bases->sole()->basis_unit_cost)->toBe('15.00000000');
    expect(fn () => $transition->update(['target_method' => InventoryCostPolicy::Fifo]))->toThrow(DomainException::class);
    $transition->refresh();
    $this->actingAs($fixture['approver'])->post(route('admin.inventory.cost-policies.transitions.approve', $transition))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('admin.inventory.cost-policies.transitions.activate', $transition))->assertRedirect()->assertSessionHasNoErrors();
    expect($transition->fresh()->policy->method)->toBe(InventoryCostPolicy::SpecificIdentification)
        ->and(InventoryTransaction::query()->count())->toBe($transactionsBefore)
        ->and(JournalEntry::query()->count())->toBe($journalsBefore)
        ->and($layer->fresh()->unit_cost)->toBe('20.00000000');
    $this->actingAs($fixture['preparer']);
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', ['branch_store_uuid' => $fixture['store']->public_uuid,
        'product_doc_num' => $fixture['product']->doc_num, 'document_date' => $day(5)]))->assertOk()
        ->assertJsonPath('results.0.unit_cost', '15.00000000')->assertJsonPath('results.0.receipt_unit_cost', '20.00000000');
    $fixture['day'] = $day(5);
    $response = $this->postJson(route('admin.inventory.documents.store'), specificIssuePayload($fixture, [[
        'product_doc_num' => $fixture['product']->doc_num, 'quantity' => '3', 'selected_receipt_layer_id' => $layer->id,
    ]]))->assertCreated();
    $issue = InventoryDocument::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $issue->transactions->sole()->id)->sole();
    expect($issue->transactions->sole()->total_cost)->toBe('45.00000000')
        ->and($allocation->inventory_cost_policy_transition_basis_id)->toBe($transition->bases->sole()->id)
        ->and($transition->bases->sole()->fresh()->remaining_book_value)->toBe('75.00000000');
    app(InventoryDocumentPostingService::class)->reverse($issue, 'SYNTHETIC original selected basis restore');
    $restored = InventoryReceiptLayer::query()->where('source_allocation_id', $allocation->id)->sole();
    expect($transition->bases->sole()->fresh()->remaining_quantity)->toBe('5.00000000')
        ->and($transition->bases->sole()->fresh()->remaining_book_value)->toBe('75.00000000')
        ->and($restored->remaining_quantity)->toBe('3.00000000')->and($restored->source_allocation_cost_snapshot)->toBe('45.00000000')
        ->and($restored->unit_cost)->toBe('15.00000000')
        ->and(bcadd($layer->fresh()->remaining_quantity, $restored->remaining_quantity, 8))->toBe('8.00000000')
        ->and($originalIssue->fresh()->transactions->sole()->total_cost)->toBe('180.00000000');
});

test('specific identification rejects missing foreign and duplicate exhausted layer choices atomically', function (): void {
    $fixture = specificCostFixture();
    $before = InventoryDocument::query()->count();
    $url = route('admin.inventory.documents.store');
    $line = ['product_doc_num' => $fixture['product']->doc_num, 'quantity' => '4', 'selected_receipt_layer_id' => $fixture['layer']->id];
    $this->postJson($url, specificIssuePayload($fixture, [collect($line)->except('selected_receipt_layer_id')->all()]))->assertUnprocessable();
    $other = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC foreign layer store']);
    $foreignReceipt = costTransitionMovement([...$fixture, 'store' => $other], $fixture['day'], InventoryDocument::TypeAdjustmentIn, '10', '25');
    $foreign = InventoryReceiptLayer::query()->where('receipt_transaction_id', $foreignReceipt->transactions->sole()->id)->sole();
    $this->postJson($url, specificIssuePayload($fixture, [[...$line, 'selected_receipt_layer_id' => $foreign->id]]))->assertUnprocessable();
    $this->postJson($url, specificIssuePayload($fixture, [[...$line, 'quantity' => '6'], [...$line, 'quantity' => '6']]))->assertUnprocessable();
    expect($fixture['layer']->fresh()->remaining_quantity)->toBe('10.00000000')
        ->and(InventoryDocument::query()->count())->toBe($before + 1);
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', ['branch_store_uuid' => $other->public_uuid,
        'product_doc_num' => $fixture['product']->doc_num, 'document_date' => $fixture['day']]))->assertOk()->assertJsonCount(1, 'results');
    $fixture['preparer']->revokePermissionTo('inventory.documents.create');
    $fixture['preparer']->revokePermissionTo('inventory.documents.edit');
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers'))->assertForbidden();
});

test('selected identification consumes multiple frozen bases including final residue and restores a partial slice once', function (): void {
    $fixture = costTransitionFixture();
    foreach (['inventory.documents.create', 'inventory.documents.issue', 'inventory.documents.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['preparer']->givePermissionTo($permission);
    }
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeAdjustmentIn, '3', '1');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeAdjustmentIn, '4', '2');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeIssue, '2');
    $service = app(InventoryCostPolicyTransitionService::class);
    $transition = $service->prepare($fixture['company']->id, ['branch_store_id' => $fixture['store']->id,
        'effective_from' => $day(4), 'target_method' => InventoryCostPolicy::SpecificIdentification,
        'reason' => 'SYNTHETIC fractional multi-layer transition'], $fixture['preparer']->id);
    $this->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $transition = $service->activate($service->approve($transition, $fixture['approver']->id), $fixture['approver']->id);
    expect($transition->total_book_value)->toBe('7.85714286')->and($transition->bases)->toHaveCount(2);
    $layers = $transition->bases->sortBy('inventory_receipt_layer_id')->values();
    $fixture['day'] = $day(5);
    $this->actingAs($fixture['preparer']);
    $issueSlice = function (int $layerId, string $quantity) use ($fixture): InventoryDocument {
        $response = $this->postJson(route('admin.inventory.documents.store'), specificIssuePayload($fixture, [[
            'product_doc_num' => $fixture['product']->doc_num, 'quantity' => $quantity, 'selected_receipt_layer_id' => $layerId,
        ]]))->assertCreated();

        return InventoryDocument::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    };
    $first = $issueSlice($layers[0]->inventory_receipt_layer_id, '1');
    $second = $issueSlice($layers[1]->inventory_receipt_layer_id, '1');
    $partial = $issueSlice($layers[1]->inventory_receipt_layer_id, '2');
    $final = $issueSlice($layers[1]->inventory_receipt_layer_id, '1');
    expect($first->transactions->sole()->total_cost)->toBe('1.57142857')
        ->and($second->transactions->sole()->total_cost)->toBe('1.57142857')
        ->and($partial->transactions->sole()->total_cost)->toBe('3.14285714')
        ->and($final->transactions->sole()->total_cost)->toBe('1.57142858');
    app(InventoryDocumentPostingService::class)->reverse($partial, 'SYNTHETIC partial frozen-basis restoration');
    $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $partial->transactions->first()->id)->sole();
    $restored = InventoryReceiptLayer::query()->where('source_allocation_id', $allocation->id)->sole();
    expect($restored->remaining_quantity)->toBe('2.00000000')->and($restored->source_allocation_cost_snapshot)->toBe('3.14285714');
    $reissue = $issueSlice($restored->id, '2');
    expect($reissue->transactions->sole()->total_cost)->toBe('3.14285714');
    $net = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)
        ->where('product_id', $fixture['product']->id)->get()->reduce(fn (array $net, $transaction): array => [
            'quantity' => bcadd($net['quantity'], bcsub($transaction->quantity_in, $transaction->quantity_out, 8), 8),
            'value' => bcadd($net['value'], bccomp($transaction->quantity_in, '0', 8) > 0 ? $transaction->total_cost : bcsub('0', $transaction->total_cost, 8), 8),
        ], ['quantity' => '0.00000000', 'value' => '0.00000000']);
    expect($net['quantity'])->toBe('0.00000000')->and($net['value'])->toBe('0.00000000')
        ->and(bcadd((string) $transition->bases()->sum('remaining_book_value'), '0', 8))->toBe('0.00000000');
});

test('actual selected warehouse transfer preserves the selected source layer cost and reverses both stores', function (): void {
    $fixture = specificCostFixture(true);
    $destination = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC selected transfer '.$fixture['product']->doc_num]);
    $response = $this->postJson(route('admin.inventory.documents.store'), [
        ...specificIssuePayload($fixture, [['product_doc_num' => $fixture['product']->doc_num, 'quantity' => '4',
            'selected_receipt_layer_id' => $fixture['layer']->id, 'batch_lot' => 'SYNTHETIC-EXPENSIVE']]),
        'document_type' => InventoryDocument::TypeTransfer, 'destination_branch_store_uuid' => $destination->public_uuid,
        'destination_stock_status' => 'available',
    ])->assertCreated();
    $transfer = InventoryDocument::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    $out = $transfer->transactions()->where('quantity_out', '>', 0)->sole();
    $in = $transfer->transactions()->where('quantity_in', '>', 0)->sole();
    expect($out->total_cost)->toBe('80.00000000')->and($in->total_cost)->toBe('80.00000000')
        ->and($in->branch_store_id)->toBe($destination->id);
    $destinationLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $in->id)->sole();
    $sourceAllocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $out->id)->sole();
    expect($destinationLayer->source_allocation_id)->toBe($sourceAllocation->id)
        ->and($destinationLayer->unit_cost)->toBe('20.00000000')->and($destinationLayer->remaining_quantity)->toBe('4.00000000');
    app(InventoryDocumentPostingService::class)->reverse($transfer, 'SYNTHETIC selected transfer reversal');
    expect($destinationLayer->refresh()->remaining_quantity)->toBe('0.00000000');
    foreach ([$fixture['store']->id => '20.00000000', $destination->id => '0.00000000'] as $storeId => $quantity) {
        $net = InventoryTransaction::query()->where('branch_store_id', $storeId)
            ->where('product_id', $fixture['product']->id)->selectRaw('sum(quantity_in - quantity_out) as quantity')->sole();
        expect(bcadd((string) $net->quantity, '0', 8))->toBe($quantity);
    }
});
