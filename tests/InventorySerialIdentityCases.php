<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ProductComponent;
use Modules\Inventory\Exports\InventoryPeriodicCostCloseExport;
use Modules\Inventory\Exports\InventoryReportExport;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventorySerialIdentity;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Inventory\Services\OpeningStockPricingService;
use Modules\Inventory\Services\OpeningStockService;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionCycleService;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesIssueOrderService;
use Modules\Sales\Services\SalesReturnService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryCostTransitionSupport.php';
require_once __DIR__.'/ManufacturingInventorySupport.php';
require_once __DIR__.'/ProcurementSupport.php';
require_once __DIR__.'/SpecificSalesCostCases.php';

function assertSerialPosition(InventorySerialIdentity $identity, ?int $layerId): void
{
    $identity->refresh();
    $layers = InventoryReceiptLayer::where('inventory_serial_identity_id', $identity->id)->where('remaining_quantity', '>', 0)->get();
    expect($identity->current_receipt_layer_id)->toBe($layerId)->and($layers)->toHaveCount($layerId === null ? 0 : 1);
    if ($layerId !== null) {
        expect($layers->sole()->id)->toBe($layerId)->and($layers->sole()->remaining_quantity)->toBe('1.00000000');
    }
}

test('serialized receipt stores separately identified units and selected serial issue keeps its exact original value', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update(['tracks_serials' => true]);
    app(InventoryCostPolicyService::class)->createVersion($fixture['company']->id, [
        'branch_store_id' => $fixture['store']->id, 'method' => InventoryCostPolicy::SpecificIdentification,
        'effective_from' => $fixture['period']->from_date->toDateString(), 'reason' => 'SYNTHETIC serial identity acceptance',
    ], $fixture['preparer']->id);
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '2', '3.12345678',
        ['serial_numbers' => ['SYNTHETIC-SERIAL-A', 'SYNTHETIC-SERIAL-B']]);
    $layers = InventoryReceiptLayer::query()->with('serialIdentity')->whereIn('receipt_transaction_id', $receipt->transactions->modelKeys())->orderBy('id')->get();
    expect($layers)->toHaveCount(2)->and($layers->pluck('remaining_quantity')->all())->toBe(['1.00000000', '1.00000000'])
        ->and($layers->pluck('serialIdentity.serial_number')->all())->toBe(['SYNTHETIC-SERIAL-A', 'SYNTHETIC-SERIAL-B']);
    $issue = costTransitionMovement($fixture, $day, InventoryDocument::TypeIssue, '1', lineOverrides: ['selected_receipt_layer_id' => $layers->last()->id]);
    expect($issue->transactions->sole()->total_cost)->toBe('3.12345678')
        ->and($layers->first()->fresh()->remaining_quantity)->toBe('1.00000000')
        ->and($layers->last()->fresh()->remaining_quantity)->toBe('0.00000000');
    assertSerialPosition($layers->last()->serialIdentity, null);
    assertSerialPosition($layers->first()->serialIdentity, $layers->first()->id);
});

test('serial duplicate casing invalid count and fractional issue fail atomically and retain the current unit', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update(['tracks_serials' => true]);
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '1', '10', ['serial_number' => ' SYNTHETIC-Serial ']);
    $layer = InventoryReceiptLayer::where('receipt_transaction_id', $receipt->transactions->sole()->id)->sole();
    $before = [InventoryTransaction::count(), InventoryDocument::count(), InventorySerialIdentity::count()];
    expect(fn () => costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '1', '10', ['serial_number' => 'synthetic-serial']))->toThrow(DomainException::class)
        ->and(fn () => costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '2', '10', ['serial_numbers' => ['SYNTHETIC-TWO']]))->toThrow(DomainException::class)
        ->and(fn () => costTransitionMovement($fixture, $day, InventoryDocument::TypeIssue, '0.5', lineOverrides: ['selected_receipt_layer_id' => $layer->id]))->toThrow(DomainException::class)
        ->and(fn () => costTransitionMovement($fixture, $day, InventoryDocument::TypeIssue, '1'))->toThrow(DomainException::class)
        ->and([InventoryTransaction::count(), InventoryDocument::count(), InventorySerialIdentity::count()])->toBe($before);
    assertSerialPosition($layer->serialIdentity, $layer->id);
    expect(fn () => $fixture['product']->update(['tracks_serials' => false]))->toThrow(DomainException::class);
});

test('serial tracking remains exact under moving average transfer and reversal and a consumed serial cannot be issued twice', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update(['tracks_serials' => true]);
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '1', '10', ['serial_number' => 'SYNTHETIC-TRANSFER']);
    $source = InventoryReceiptLayer::where('receipt_transaction_id', $receipt->transactions->sole()->id)->sole();
    $destination = BranchStore::create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC destination']);
    $transfer = app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_store_id' => $fixture['store']->id, 'destination_branch_store_id' => $destination->id,
        'document_type' => InventoryDocument::TypeTransfer, 'document_date' => $day,
    ], [['product_id' => $fixture['product']->id, 'quantity' => '1', 'selected_receipt_layer_id' => $source->id]]);
    $target = InventoryReceiptLayer::where('branch_store_id', $destination->id)->sole();
    expect($target->sourceAllocation->layer->id)->toBe($source->id)->and($target->serialIdentity->id)->toBe($source->serialIdentity->id);
    assertSerialPosition($source->serialIdentity, $target->id);
    app(InventoryDocumentPostingService::class)->reverse($transfer, 'SYNTHETIC serial transfer reversal');
    $restored = InventoryReceiptLayer::where('branch_store_id', $fixture['store']->id)->where('remaining_quantity', '1')->sole();
    assertSerialPosition($source->serialIdentity, $restored->id);
    $issue = costTransitionMovement($fixture, $day, InventoryDocument::TypeIssue, '1', lineOverrides: ['selected_receipt_layer_id' => $restored->id]);
    expect($issue->transactions->sole()->total_cost)->toBe('10.00000000')
        ->and(fn () => costTransitionMovement($fixture, $day, InventoryDocument::TypeIssue, '1', lineOverrides: ['selected_receipt_layer_id' => $restored->id]))->toThrow(DomainException::class);
    assertSerialPosition($source->serialIdentity, null);
    app(InventoryDocumentPostingService::class)->reverse($issue, 'SYNTHETIC serial issue reversal');
    $last = InventoryReceiptLayer::where('branch_store_id', $fixture['store']->id)->where('remaining_quantity', '1')->sole();
    assertSerialPosition($source->serialIdentity, $last->id);
});

test('serialized production retains selected staging return consumption waste and separately supplied finished serials', function (): void {
    $this->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $fixture = manufacturingInventoryFixture('-SYNTHETIC-SERIAL-'.Str::random(8));
    DB::table('inventory_transactions')->where('product_id', $fixture['raw']->id)->where('source_type', 'test')->delete();
    $fixture['raw']->update(['tracks_serials' => true]);
    $fixture['finished']->update(['tracks_serials' => true]);
    $fixture['product'] = $fixture['raw'];
    $fixture['store'] = BranchStore::create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC serial production']);
    $receipt = costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeAdjustmentIn, '4', '2', ['serial_numbers' => ['SYNTHETIC-R1', 'SYNTHETIC-R2', 'SYNTHETIC-R3', 'SYNTHETIC-R4']]);
    $layers = InventoryReceiptLayer::whereIn('receipt_transaction_id', $receipt->transactions->modelKeys())->orderBy('id')->get();
    $details = manufacturingIntegrityRun($fixture, '2');
    $cycle = $details['cycle'];
    $run = $details['run'];
    $requirement = $run->requirements->sole();
    $cycle->issueMaterials($run, $fixture['store']->id, [$requirement->id => '4'], selectedLayersByRequirementId: [$requirement->id => $layers->map(fn ($layer): array => ['layer_id' => $layer->id, 'quantity' => '1'])->all()]);
    $staged = InventoryReceiptLayer::where('product_id', $fixture['raw']->id)->where('stock_status', InventoryTransaction::StatusProductionStaging)->where('remaining_quantity', '1')->orderBy('id')->get();
    expect($staged)->toHaveCount(4);
    $return = $cycle->returnMaterials($run->fresh(), $fixture['store']->id, [$requirement->id => '1'], selectedSerialLayersByRequirementId: [$requirement->id => [$staged[3]->id]]);
    expect($return->transactions->where('quantity_in', '>', 0)->sole()->inventory_serial_identity_id)->toBe($staged[3]->inventory_serial_identity_id);
    $run = $cycle->startRun($cycle->completeSetup($cycle->startSetup($run->fresh())));
    $cycle->recordProgress($run, ['good_base_quantity' => '2']);
    $cycle->accountMaterials($run->fresh(), $fixture['store']->id, [$requirement->id => [
        'consumed_quantity' => '2', 'waste_quantity' => '1', 'consumed_receipt_layer_ids' => [$staged[0]->id, $staged[1]->id], 'waste_receipt_layer_ids' => [$staged[2]->id],
    ]]);
    $finished = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '2', serialNumbers: ['SYNTHETIC-F1', 'SYNTHETIC-F2']);
    expect($finished->lines)->toHaveCount(2)->and($finished->transactions->pluck('total_cost')->all())->toBe(['2.00000000', '2.00000000']);
    foreach ($staged->take(3) as $layer) {
        assertSerialPosition($layer->serialIdentity, null);
    }
    foreach (InventoryReceiptLayer::whereIn('receipt_transaction_id', $finished->transactions->modelKeys())->get() as $layer) {
        assertSerialPosition($layer->serialIdentity, $layer->id);
    }
    $cost = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($cost['issued'])->toBe('8.00000000')->and($cost['returned'])->toBe('2.00000000')->and($cost['waste'])->toBe('2.00000000')->and($cost['finished_goods'])->toBe('4.00000000')->and($cost['wip'])->toBe('0.00000000');
});

test('serialized inspection supports partial receipts selected purchase return and exact restored identities', function (): void {
    $fixture = procurementFixture(isolatedCompany: true);
    $fixture['raw']->update(['tracks_serials' => true]);
    $admin = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $admin);
    $orders = app(PurchaseOrderService::class);
    $order = $orders->create([
        'document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => '3', 'unit_price' => '7.12345678']],
    ])['record'];
    $order = $orders->approve($orders->submit($order));
    procurementUseBranch($fixture, $fixture['branch']);
    $receiving = app(ProcurementReceivingService::class);
    $inspection = $receiving->inspectPurchaseSource($order, ['lines' => [[
        'purchase_order_line_public_id' => $order->lines->sole()->public_id, 'delivered_quantity' => '3', 'accepted_quantity' => '3', 'rejected_quantity' => '0',
        'serial_numbers' => ['SYNTHETIC-P1', 'SYNTHETIC-P2', 'SYNTHETIC-P3'],
    ]]]);
    $payload = ['document_date' => now()->toDateString(), 'lines' => [['inspection_line_public_id' => $inspection->lines->sole()->public_id, 'delivered_quantity' => '2', 'serial_numbers' => ['SYNTHETIC-P1', 'SYNTHETIC-P3']]]];
    $receipt = $receiving->postReceipt($receiving->createReceiptFromInspection($inspection, $payload));
    $sourceLine = $receipt->lines->sole();
    $layers = InventoryReceiptLayer::where('product_id', $fixture['raw']->id)->orderBy('id')->get();
    expect($layers)->toHaveCount(2)->and($layers->pluck('serialIdentity.serial_number')->all())->toBe(['SYNTHETIC-P1', 'SYNTHETIC-P3']);
    expect(fn () => $receiving->createReceiptFromInspection($inspection->fresh(), ['document_date' => now()->toDateString(), 'lines' => [[
        'inspection_line_public_id' => $inspection->lines->sole()->public_id, 'delivered_quantity' => '1', 'serial_numbers' => ['SYNTHETIC-P1'],
    ]]]))->toThrow(DomainException::class);
    $last = $receiving->postReceipt($receiving->createReceiptFromInspection($inspection->fresh(), ['document_date' => now()->toDateString(), 'lines' => [[
        'inspection_line_public_id' => $inspection->lines->sole()->public_id, 'delivered_quantity' => '1', 'serial_numbers' => ['SYNTHETIC-P2'],
    ]]]));
    foreach (['purchases.purchase_returns.create', 'purchases.purchase_returns.view', 'purchases.goods_receipt_notes.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(session()->all());
    $selector = ['branch_store_uuid' => $fixture['store']->public_uuid, 'product_doc_num' => $fixture['raw']->doc_num, 'document_date' => now()->toDateString()];
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', $selector))->assertOk()->assertJsonCount(0, 'results');
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', [...$selector, 'receipt_line_public_id' => $sourceLine->public_id]))
        ->assertOk()->assertJsonCount(2, 'results');
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', [...$selector, 'receipt_line_public_id' => $sourceLine->public_id, 'q' => 'P2']))
        ->assertOk()->assertJsonCount(0, 'results');
    $this->get(route('admin.purchases.goods-receipt-notes.show', $receipt->doc_num))->assertOk()->assertSee('SYNTHETIC-P1')->assertSee('SYNTHETIC-P3')->assertDontSee('SYNTHETIC-P2');
    $settlement = app(ProcurementSettlementService::class);
    $return = $settlement->approvePurchaseReturn($settlement->createPurchaseReturn([
        'purchase_order_doc_num' => $order->doc_num, 'return_date' => now()->toDateString(), 'reason_code' => 'commercial_return',
        'lines' => [['receipt_line_public_id' => $sourceLine->public_id, 'quantity' => '1', 'serial_receipt_layer_ids' => [$layers->last()->id]]],
    ]));
    assertSerialPosition($layers->last()->serialIdentity, null);
    assertSerialPosition($layers->first()->serialIdentity, $layers->first()->id);
    $this->get(route('admin.purchases.purchase-returns.show', $return))->assertOk()->assertSee('SYNTHETIC-P3')->assertDontSee('SYNTHETIC-P1')->assertDontSee('SYNTHETIC-P2');
    $return = $settlement->reversePurchaseReturn($return, 'SYNTHETIC exact returned serial restore');
    foreach ($layers as $layer) {
        $restored = InventoryReceiptLayer::where('inventory_serial_identity_id', $layer->inventory_serial_identity_id)->where('remaining_quantity', '1')->sole();
        assertSerialPosition($layer->serialIdentity, $restored->id);
    }
    expect(bcadd((string) InventoryTransaction::where('source_type', PurchaseReturn::class)->where('source_id', $return->id)->sum('quantity_in'), '0', 8))
        ->toBe('1.00000000')->and($last->lines->sole()->serial_numbers)->toBe(['SYNTHETIC-P2']);
});

test('serialized final output assigns the eight decimal residue to the last unit and leaves no WIP', function (): void {
    $fixture = manufacturingInventoryFixture('-SYNTHETIC-SERIAL-RESIDUE-'.Str::random(8));
    $fixture['finished']->update(['tracks_serials' => true]);
    ProductComponent::where('product_id', $fixture['finished']->id)->update(['quantity' => '1.25']);
    $cycle = app(ProductionCycleService::class);
    $run = manufacturingIntegrityRun($fixture, '4')['run'];
    $run = $cycle->reserveRun($run, $fixture['store']->id);
    $requirement = $run->requirements->sole();
    $cycle->issueMaterials($run, $fixture['store']->id, [$requirement->id => '5']);
    $run = $cycle->startRun($cycle->completeSetup($cycle->startSetup($run->fresh())));
    $cycle->recordProgress($run, ['good_base_quantity' => '3']);
    $cycle->accountMaterials($run->fresh(), $fixture['store']->id, [$requirement->id => ['consumed_quantity' => '5', 'waste_quantity' => '0']]);
    $finished = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '3', serialNumbers: ['SYNTHETIC-FINAL-1', 'SYNTHETIC-FINAL-2', 'SYNTHETIC-FINAL-3']);
    expect($finished->transactions->mapWithKeys(fn ($transaction): array => [$transaction->serialNumbers()[0] => $transaction->total_cost])->sortKeys()->all())
        ->toBe(['SYNTHETIC-FINAL-1' => '3.33333333', 'SYNTHETIC-FINAL-2' => '3.33333333', 'SYNTHETIC-FINAL-3' => '3.33333334']);
    $position = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($position['finished_goods'])->toBe('10.00000000')->and($position['wip'])->toBe('0.00000000');
});

test('serialized receipt first inspection posts only accepted identities and its reversal retains those identities', function (): void {
    $fixture = procurementFixture(isolatedCompany: true);
    $fixture['raw']->update(['tracks_serials' => true]);
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
    $orders = app(PurchaseOrderService::class);
    $order = $orders->create(['document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => '1', 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => '3', 'unit_price' => '7.12345678']]])['record'];
    $order = $orders->approve($orders->submit($order));
    procurementUseBranch($fixture, $fixture['branch']);
    $receiving = app(ProcurementReceivingService::class);
    $receipt = $receiving->createReceipt($order, ['document_date' => now()->toDateString(), 'lines' => [[
        'purchase_order_line_public_id' => $order->lines->sole()->public_id, 'delivered_quantity' => '3',
        'serial_numbers' => ['SYNTHETIC-RF1', 'SYNTHETIC-REJECTED', 'SYNTHETIC-RF3'],
    ]]]);
    $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receipt->lines->sole()->public_id,
        'accepted_quantity' => '2', 'rejected_quantity' => '1', 'reason' => 'SYNTHETIC rejected physical unit',
        'serial_numbers' => ['SYNTHETIC-RF1', 'SYNTHETIC-RF3']]]]);
    $receipt = $receiving->postReceipt($receipt->fresh());
    $source = InventoryTransaction::where('posting_key', 'purchase-receipt:'.$receipt->lines->sole()->id)->sole();
    expect($source->serialNumbers())->toBe(['SYNTHETIC-RF1', 'SYNTHETIC-RF3']);
    $layers = InventoryReceiptLayer::where('receipt_transaction_id', $source->id)->with('serialIdentity')->get();
    expect($layers)->toHaveCount(2)->and(InventorySerialIdentity::where('company_id', $fixture['company']->id)->where('serial_number', 'SYNTHETIC-REJECTED')->exists())->toBeFalse();
    $receiving->reverseReceipt($receipt, 'SYNTHETIC accepted serial receipt reversal');
    $reversal = InventoryTransaction::where('reversal_of_id', $source->id)->sole();
    expect($reversal->serialNumbers())->toBe(['SYNTHETIC-RF1', 'SYNTHETIC-RF3'])
        ->and($reversal->quantity_out)->toBe('2.00000000')->and($reversal->total_cost)->toBe($source->total_cost);
    foreach ($layers as $layer) {
        assertSerialPosition($layer->serialIdentity, null);
    }
});

test('serial adjustment reconciliation reads frozen GL rounding and reversal retains other active adjustments', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update(['tracks_serials' => true]);
    $day = now()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '2', '7.12345678',
        ['serial_numbers' => ['SYNTHETIC-ROUND-1', 'SYNTHETIC-ROUND-2']]);
    $reconcile = fn (): array => collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id))
        ->firstWhere('key', 'inventory_adjustments');
    expect($reconcile())->toMatchArray(['subledger' => '14.2470', 'gl' => '14.2470', 'difference' => '0.0000']);
    costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '1', '3.33333333', ['serial_number' => 'SYNTHETIC-ACTIVE']);
    app(InventoryDocumentPostingService::class)->reverse($receipt, 'SYNTHETIC precise adjustment reversal');
    expect($reconcile())->toMatchArray(['subledger' => '3.3333', 'gl' => '3.3333', 'difference' => '0.0000']);
});

test('legacy adjustment reconciliation retains the historic truncated value when frozen rounding is absent', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $receipt = costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeAdjustmentIn, '3', '7.12345678');
    $line = $receipt->lines->sole();
    $snapshot = $line->product_snapshot;
    unset($snapshot['inventory_accounting']);
    DB::table('inventory_document_lines')->where('id', $line->id)->update(['product_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    DB::table('journal_entry_lines')->where('journal_entry_id', $receipt->journal_entry_id)->where('debit_amount', '>', 0)->update(['debit_amount' => '21.3703']);
    DB::table('journal_entry_lines')->where('journal_entry_id', $receipt->journal_entry_id)->where('credit_amount', '>', 0)->update(['credit_amount' => '21.3703']);
    $row = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id))->firstWhere('key', 'inventory_adjustments');
    expect($row)->toMatchArray(['subledger' => '21.3703', 'gl' => '21.3703', 'difference' => '0.0000']);
});

test('serialized sales return restores its exact delivery unit and moving average disposition selects it', function (string $disposition): void {
    $fixture = specificSalesFixture(['serials' => true, 'cost_method' => InventoryCostPolicy::MovingAverage, 'quantity' => '2']);
    $layers = InventoryReceiptLayer::whereIn('receipt_transaction_id', $fixture['cheap']->transactions->modelKeys())->orderBy('id')->take(2)->get();
    $invoiceLine = $fixture['invoice']->lines->sole();
    $delivery = app(SalesIssueOrderService::class)->issue($fixture['issueOrder'], $fixture['store'], $fixture['day'], [
        $invoiceLine->id => $layers->map(fn ($layer): array => ['layer_id' => $layer->id, 'quantity' => '1'])->all(),
    ]);
    Permission::findOrCreate('sales_returns.create', 'web');
    $fixture['preparer']->givePermissionTo('sales_returns.create');
    $this->actingAs($fixture['preparer'])->withSession(session()->all());
    config(['select2.pagination.per_page' => 1]);
    $selector = ['invoice_doc_num' => $fixture['invoice']->doc_num, 'invoice_line_public_id' => $invoiceLine->public_id];
    $selectorRoute = 'admin.sales.select2.returnable-serial-deliveries';
    $this->getJson(route($selectorRoute, $selector))->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('pagination.more', true);
    $this->getJson(route($selectorRoute, [...$selector, 'q' => $layers->last()->serialIdentity->serial_number]))
        ->assertOk()->assertJsonPath('results.0.id', (string) $delivery->lines->last()->id);
    $this->getJson(route($selectorRoute, [...$selector, 'invoice_line_public_id' => (string) Str::uuid()]))->assertOk()->assertJsonCount(0, 'results');
    $this->getJson(route($selectorRoute, [...$selector, 'invoice_doc_num' => 'SYNTHETIC-OTHER-INVOICE']))->assertOk()->assertJsonCount(0, 'results');
    $returns = app(SalesReturnService::class);
    $before = SalesReturn::count();
    foreach ([
        ['quantity' => '0.5', 'delivery_line_ids' => [$delivery->lines->last()->id]],
        ['quantity' => '1', 'delivery_line_ids' => [$delivery->lines->last()->id, $delivery->lines->first()->id]],
        ['quantity' => '1', 'delivery_line_ids' => [0]],
        ['quantity' => '1', 'delivery_line_ids' => []],
    ] as $invalid) {
        expect(fn () => $returns->create($fixture['invoice']->fresh(), SalesReturn::ReasonExcess, 'SYNTHETIC invalid serial return', [
            ['customer_invoice_line_id' => $invoiceLine->id, ...$invalid],
        ], $fixture['store']->id))->toThrow(DomainException::class);
    }
    expect(SalesReturn::count())->toBe($before);
    $return = $returns->create($fixture['invoice']->fresh(), SalesReturn::ReasonExcess, 'SYNTHETIC exact serial return', [
        ['customer_invoice_line_id' => $invoiceLine->id, 'quantity' => '1', 'delivery_line_ids' => [$delivery->lines->last()->id]],
    ], $fixture['store']->id);
    $this->getJson(route($selectorRoute, [...$selector, 'q' => $layers->last()->serialIdentity->serial_number]))->assertOk()->assertJsonCount(0, 'results');
    $return = $returns->receive($returns->authorize($return));
    $received = InventoryReceiptLayer::whereIn('receipt_transaction_id', $return->returnInventoryDocument->transactions->modelKeys())->sole();
    expect($received->inventory_serial_identity_id)->toBe($layers->last()->inventory_serial_identity_id);
    assertSerialPosition($layers->first()->serialIdentity, null);
    $return = $returns->close($returns->inspect($return, [['sales_return_line_id' => $return->lines->sole()->id, $disposition.'_quantity' => '1']]));
    $current = InventoryReceiptLayer::where('inventory_serial_identity_id', $layers->last()->inventory_serial_identity_id)->where('remaining_quantity', '1')->sole();
    assertSerialPosition($layers->last()->serialIdentity, $current->id);
    expect($current->stock_status)->toBe(match ($disposition) {
        'saleable' => InventoryTransaction::StatusAvailable, 'rework' => InventoryTransaction::StatusRework, 'scrap' => InventoryTransaction::StatusScrap
    });
    $fixture['preparer']->revokePermissionTo('sales_returns.create');
    $this->getJson(route($selectorRoute, $selector))->assertForbidden();
})->with(['saleable', 'rework', 'scrap']);

test('serial receipt selectors search paginate and exclude foreign warehouses and already consumed units', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update(['tracks_serials' => true]);
    Permission::findOrCreate('inventory.documents.create', 'web');
    $fixture['preparer']->givePermissionTo('inventory.documents.create');
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '2', '10', ['serial_numbers' => ['SYNTHETIC-SEARCH-1', 'SYNTHETIC-SEARCH-2']]);
    $layers = InventoryReceiptLayer::whereIn('receipt_transaction_id', $receipt->transactions->modelKeys())->orderBy('id')->get();
    $this->actingAs($fixture['preparer'])->withSession(session()->all());
    config(['select2.pagination.per_page' => 1]);
    $parameters = ['branch_store_uuid' => $fixture['store']->public_uuid, 'product_doc_num' => $fixture['product']->doc_num, 'document_date' => $day, 'q' => 'SEARCH', 'per_page' => 1];
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', $parameters))->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('pagination.more', true);
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', [...$parameters, 'q' => 'SEARCH-2']))
        ->assertOk()->assertJsonPath('results.0.id', (string) $layers->last()->id)->assertJsonPath('results.0.serial_number', 'SYNTHETIC-SEARCH-2');
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', [...$parameters, 'branch_store_uuid' => (string) Str::uuid()]))->assertOk()->assertJsonCount(0, 'results');
    costTransitionMovement($fixture, $day, InventoryDocument::TypeIssue, '1', lineOverrides: ['selected_receipt_layer_id' => $layers->last()->id]);
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', [...$parameters, 'q' => 'SEARCH-2']))->assertOk()->assertJsonCount(0, 'results');
    $fixture['preparer']->revokePermissionTo('inventory.documents.create');
    $this->getJson(route('admin.inventory.documents.select2.receipt-layers', $parameters))->assertForbidden();
});

test('serial opening stock preserves three unit identities before pricing and exact FIFO source rounding after pricing', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update(['tracks_serials' => true]);
    $fixture['period']->update(['allows_opening_entries' => true]);
    app(InventoryCostPolicyService::class)->createVersion($fixture['company']->id, ['branch_store_id' => $fixture['store']->id,
        'method' => InventoryCostPolicy::Fifo, 'effective_from' => $fixture['period']->from_date->toDateString(), 'reason' => 'SYNTHETIC serial FIFO rounding'], $fixture['preparer']->id);
    $openingService = app(OpeningStockService::class);
    $day = $fixture['period']->from_date->toDateString();
    $opening = $openingService->approve($openingService->create(['document_date' => $day, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['product_doc_num' => $fixture['product']->doc_num, 'quantity' => '3', 'serial_numbers' => "SYNTHETIC-OPEN-1\nSYNTHETIC-OPEN-2\nSYNTHETIC-OPEN-3"]]])['record']);
    $layers = InventoryReceiptLayer::where('product_id', $fixture['product']->id)->orderBy('id')->get();
    expect($layers)->toHaveCount(3)->and($layers->pluck('unit_cost')->all())->toBe([null, null, null]);
    $currency = Currency::where('company_id', $fixture['company']->id)->where('is_main', true)->firstOrFail();
    $pricing = app(OpeningStockPricingService::class)->create(['document_date' => $day, 'opening_stock_doc_num' => $opening->doc_num,
        'currency_doc_num' => $currency->doc_num, 'source_reference' => 'SYNTHETIC documented source',
        'lines' => [['opening_stock_line_public_id' => $opening->lines->sole()->public_id, 'unit_price' => '3.33333333']]], request())['record'];
    expect($pricing->total_amount)->toBe('10.0000');
    $totals = [];
    foreach ($layers->reverse() as $layer) {
        $issue = costTransitionMovement($fixture, $day, InventoryDocument::TypeIssue, '1', lineOverrides: ['selected_receipt_layer_id' => $layer->id]);
        $totals[] = $issue->transactions->sole()->total_cost;
        assertSerialPosition($layer->serialIdentity, null);
    }
    expect($totals)->toBe(['3.33333334', '3.33333333', '3.33333333']);
});

test('serial migration rollback retains product configuration even before the first receipt', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update(['tracks_serials' => true]);
    $migration = require base_path('modules/Inventory/Database/Migrations/2026_10_03_120900_add_inventory_serial_identity.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(Schema::hasColumn('products', 'tracks_serials'))->toBeTrue()
        ->and($fixture['product']->fresh()->tracks_serials)->toBeTrue();
});

test('serial movement XLSX CSV screen and PDF share exact identifiers while stock balance remains aggregated', function (): void {
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update(['tracks_serials' => true]);
    foreach (['inventory.reports.operations.view', 'inventory.reports.operations.export', 'inventory.reports.operations.print'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['preparer']->givePermissionTo($permission);
    }
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '2', '7.12345678', ['serial_numbers' => ['000000001', '=SYNTHETIC-LITERAL']]);
    $this->actingAs($fixture['preparer'])->withSession(session()->all());
    $query = ['product_id' => $fixture['product']->id, 'from' => $day, 'to' => $day, 'as_of' => $day];
    $response = $this->get(route('admin.inventory.reports.index', $query))->assertOk()->assertSee('000000001')->assertSee('=SYNTHETIC-LITERAL')
        ->assertSee(route('admin.inventory.reports.export.csv', $query));
    $data = $response->viewData('movements');
    expect($data)->toHaveCount(2)->and($response->viewData('balances'))->toHaveCount(1);
    $report = app(InventoryReportService::class)->report($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id, $query, null);
    $export = new InventoryReportExport($report);
    $path = tempnam(sys_get_temp_dir(), 'serial-xlsx-').'.xlsx';
    file_put_contents($path, Maatwebsite\Excel\Facades\Excel::raw($export, Excel::XLSX));
    $workbook = IOFactory::load($path);
    try {
        $sheet = $workbook->getSheet(2);
        expect($sheet->getCell('K2')->getValue())->toBe('=SYNTHETIC-LITERAL')
            ->and($sheet->getCell('K2')->getDataType())->toBe(DataType::TYPE_STRING)
            ->and($sheet->getCell('K3')->getValue())->toBe('000000001');
        $csv = Maatwebsite\Excel\Facades\Excel::raw(new InventoryPeriodicCostCloseExport($export->sections(), true), Excel::CSV);
        expect($csv)->toContain('000000001', "'=SYNTHETIC-LITERAL");
    } finally {
        $workbook->disconnectWorksheets();
        unlink($path);
    }
    $this->get(route('admin.inventory.reports.export.csv', $query))->assertOk()->assertDownload();
    $this->get(route('admin.inventory.reports.print', $query))->assertOk()->assertHeader('content-type', 'application/pdf');
    $fixture['preparer']->revokePermissionTo('inventory.reports.operations.export');
    $this->get(route('admin.inventory.reports.export.csv', $query))->assertForbidden();
});
