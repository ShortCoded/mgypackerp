<?php

use Illuminate\Support\Facades\DB;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

function inspectionActionsFixture(): array
{
    $fixture = procurementFixture();
    foreach (['view', 'edit', 'cancel', 'delete', 'restore', 'view_trashed'] as $action) {
        $permission = 'purchases.goods_receipt_inspection.'.$action;
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => '1', 'document_date' => now()->toDateString(),
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'SYNTHETIC inspection action proof',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => '10', 'unit_price' => '2']],
    ])['record']);
    $inspection = app(ProcurementReceivingService::class)->inspectPurchaseSource($order, ['lines' => [[
        'purchase_order_line_public_id' => $order->lines->sole()->public_id, 'delivered_quantity' => '2', 'accepted_quantity' => '2', 'rejected_quantity' => '0',
    ]]]);
    test()->actingAs($fixture['user']);

    return [...$fixture, 'order' => $order, 'inspection' => $inspection];
}

test('native unreceived inspection cancellation is visible audited and releases only its pending quantity', function (): void {
    $f = inspectionActionsFixture();
    $record = $f['inspection'];
    $before = [DB::table('inventory_transactions')->count(), DB::table('journal_entries')->count(), $record->lines->sole()->getAttributes()];
    expect($record->canCancelSafely())->toBeTrue()->and($record->hasReceiptableQuantity())->toBeTrue();
    foreach (['ar', 'en'] as $locale) {
        $this->withSession(['locale' => $locale]);
        $this->get(route('admin.purchases.goods-receipt-inspection.show', $record))->assertOk()->assertSee('data-inspection-cancel-form', false);
        $this->getJson(route('admin.purchases.procurement.data', ['goods_receipt_inspections', 'length' => 10]))->assertOk()->assertSee('/cancel', false);
    }
    $this->postJson(route('admin.purchases.goods-receipt-inspection.cancel', $record), [])->assertUnprocessable()->assertJsonValidationErrors('cancel_reason');
    $this->postJson(route('admin.purchases.goods-receipt-inspection.cancel', $record), ['cancel_reason' => 'SYNTHETIC shipment withdrawn'])->assertOk()->assertJsonPath('success', true);
    $record = $record->fresh('lines');
    expect($record->status)->toBe('cancelled')->and($record->result)->toBe('accepted')->and($record->hasReceiptableQuantity())->toBeFalse()
        ->and([DB::table('inventory_transactions')->count(), DB::table('journal_entries')->count(), $record->lines->sole()->getAttributes()])->toBe($before);
    $audit = DB::table('activity_log')->where('event', 'purchase_inspection.cancelled')->sole();
    expect(json_decode($audit->properties, true)['reason'])->toBe('SYNTHETIC shipment withdrawn');
    $this->getJson(route('admin.purchases.procurement.data', ['goods_receipt_inspections', 'status' => 'cancelled', 'length' => 10]))->assertOk()->assertJsonPath('recordsFiltered', 1)->assertSee('cancelled', false);
    $next = app(ProcurementReceivingService::class)->inspectPurchaseSource($f['order'], ['lines' => [[
        'purchase_order_line_public_id' => $f['order']->lines->sole()->public_id, 'delivered_quantity' => '10', 'accepted_quantity' => '10', 'rejected_quantity' => '0',
    ]]]);
    expect($next->lines->sole()->accepted_quantity)->toBe('10.00000000');
});

test('inspection deletion is draft only soft deleted and restored through the native scoped action', function (): void {
    $f = inspectionActionsFixture();
    $record = $f['inspection'];
    $url = route('admin.purchases.goods-receipt-inspection.destroy', $record);
    $this->deleteJson($url)->assertStatus(409);
    expect($record->fresh()->trashed())->toBeFalse();
    $record->update(['status' => 'draft', 'finalized_at' => null, 'finalized_by' => null]);
    $this->get(route('admin.purchases.goods-receipt-inspection.show', $record))->assertOk()->assertSee('data-inspection-delete-form', false);
    $line = $record->lines->sole()->getAttributes();
    $this->deleteJson($url)->assertOk();
    expect(GoodsReceiptInspection::withTrashed()->findOrFail($record->id)->trashed())->toBeTrue()
        ->and($record->lines()->sole()->getAttributes())->toBe($line);
    $this->patchJson(route('admin.purchases.procurement.restore', ['goods_receipt_inspections', $record->doc_num]))->assertOk();
    expect($record->fresh()->trashed())->toBeFalse()->and($record->fresh()->status)->toBe('draft');
    expect(DB::table('activity_log')->where('event', 'purchase_inspection.deleted')->count())->toBe(1)
        ->and(DB::table('activity_log')->where('event', 'document.restored')->count())->toBe(1);
});

test('linked inspection correction is blocked even for deleted receipt evidence and routes to the original receipt', function (): void {
    $f = inspectionActionsFixture();
    $record = $f['inspection'];
    $receipt = app(ProcurementReceivingService::class)->createReceiptFromInspection($record, [
        'document_date' => now()->toDateString(), 'lines' => [['inspection_line_public_id' => $record->lines->sole()->public_id, 'delivered_quantity' => '1']],
    ]);
    expect($receipt)->toBeInstanceOf(UnpricedInventoryReceipt::class)->and($record->fresh()->canCancelSafely())->toBeFalse();
    $before = [$record->fresh()->getAttributes(), $receipt->fresh()->getAttributes(), DB::table('inventory_transactions')->count(), DB::table('journal_entries')->count()];
    $this->postJson(route('admin.purchases.goods-receipt-inspection.cancel', $record), ['cancel_reason' => 'SYNTHETIC blocked linked cancellation'])->assertUnprocessable()->assertJsonPath('message', __('procurement.messages.inspection_correction_blocked'));
    expect([$record->fresh()->getAttributes(), $receipt->fresh()->getAttributes(), DB::table('inventory_transactions')->count(), DB::table('journal_entries')->count()])->toBe($before);
    $receipt->delete();
    expect($record->fresh()->hasReceiptEvidence())->toBeTrue();
    $this->postJson(route('admin.purchases.goods-receipt-inspection.cancel', $record), ['cancel_reason' => 'SYNTHETIC deleted evidence'])->assertUnprocessable();
    $this->get(route('admin.purchases.goods-receipt-inspection.show', $record))->assertOk()->assertSee(__('procurement.messages.inspection_correction_blocked'))->assertDontSee('data-inspection-cancel-form', false);
});

test('inspection actions respect native permissions branch period and registry definitions', function (): void {
    $f = inspectionActionsFixture();
    $record = $f['inspection'];
    $permission = 'purchases.goods_receipt_inspection.cancel';
    $f['user']->revokePermissionTo($permission);
    $this->postJson(route('admin.purchases.goods-receipt-inspection.cancel', $record), ['cancel_reason' => 'SYNTHETIC unauthorized'])->assertForbidden();
    $f['user']->givePermissionTo($permission);
    $f['period']->update(['is_closed' => true]);
    $this->postJson(route('admin.purchases.goods-receipt-inspection.cancel', $record), ['cancel_reason' => 'SYNTHETIC closed period'])->assertUnprocessable();
    expect($record->fresh()->status)->toBe('finalized');
    $f['period']->update(['is_closed' => false]);
    $other = Branch::query()->create(['company_id' => $f['company']->id, 'name' => 'Other synthetic warehouse', 'type' => Branch::TypeWarehouse, 'status' => 'active']);
    procurementUseBranch($f, $other);
    $this->postJson(route('admin.purchases.goods-receipt-inspection.cancel', $record), ['cancel_reason' => 'SYNTHETIC wrong branch'])->assertNotFound();
    foreach (['cancel', 'delete', 'restore', 'view_trashed'] as $action) {
        expect(app(PermissionRegistryService::class)->all())->toContain('purchases.goods_receipt_inspection.'.$action);
    }
});

test('native unreceived inspection edit retains identity and rejects linked or overallocated corrections atomically', function (): void {
    $f = inspectionActionsFixture();
    $record = $f['inspection']->load('lines');
    $line = $record->lines->sole();
    $url = route('admin.purchases.goods-receipt-inspection.update', $record);
    $payload = ['correction_reason' => 'SYNTHETIC corrected quality count', 'observations' => 'SYNTHETIC revised inspection',
        'lines' => [['purchase_order_line_public_id' => $f['order']->lines->sole()->public_id,
            'delivered_quantity' => '3', 'accepted_quantity' => '2.5', 'rejected_quantity' => '0.5', 'reason' => 'SYNTHETIC damaged', 'disposition' => 'quarantine']]];
    $this->get(route('admin.purchases.goods-receipt-inspection.edit', $record))->assertOk()
        ->assertSee('name="correction_reason"', false)->assertSee('data-procurement-form', false)->assertViewHas('editing', fn ($editing) => $editing->lines->sole()->accepted_quantity === '2.00000000');
    $this->putJson($url, [...$payload, 'correction_reason' => null])->assertUnprocessable()->assertJsonValidationErrors('correction_reason');
    $this->putJson($url, $payload)->assertOk();
    $after = $record->fresh('lines');
    expect($after->doc_num)->toBe($record->doc_num)->and($after->lines->sole()->id)->toBe($line->id)
        ->and($after->lines->sole()->public_id)->toBe($line->public_id)->and($after->lines->sole()->accepted_quantity)->toBe('2.50000000')
        ->and($after->result)->toBe('partially_accepted')->and(DB::table('inventory_transactions')->count())->toBe(0)->and(DB::table('journal_entries')->count())->toBe(0);
    $audit = json_decode(DB::table('activity_log')->where('event', 'purchase_inspection.updated')->sole()->properties, true);
    expect($audit['reason'])->toBe($payload['correction_reason'])->and($audit['before']['lines'][0]['accepted_quantity'])->toEqual(2);
    $other = app(ProcurementReceivingService::class)->inspectPurchaseSource($f['order'], ['lines' => [[
        'purchase_order_line_public_id' => $f['order']->lines->sole()->public_id, 'delivered_quantity' => '7', 'accepted_quantity' => '7', 'rejected_quantity' => '0',
    ]]]);
    $before = [$after->getAttributes(), $after->lines->sole()->getAttributes(), DB::table('activity_log')->count()];
    $bad = $payload;
    $bad['lines'][0] = [...$bad['lines'][0], 'delivered_quantity' => '4', 'accepted_quantity' => '4', 'rejected_quantity' => '0'];
    $this->putJson($url, $bad)->assertUnprocessable();
    expect([$record->fresh()->getAttributes(), $record->lines()->sole()->getAttributes(), DB::table('activity_log')->count()])->toBe($before);
    app(ProcurementReceivingService::class)->cancelInspection($other, 'SYNTHETIC release competing inspection');
    $this->putJson($url, $bad)->assertOk();
    $receipt = app(ProcurementReceivingService::class)->createReceiptFromInspection($record->fresh('lines'), [
        'document_date' => now()->toDateString(), 'lines' => [['inspection_line_public_id' => $line->public_id, 'delivered_quantity' => '1']],
    ]);
    $this->putJson($url, $payload)->assertUnprocessable();
    $this->get(route('admin.purchases.goods-receipt-inspection.edit', $record))->assertUnprocessable();
    $receipt->delete();
    $this->putJson($url, $payload)->assertUnprocessable();
});
