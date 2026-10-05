<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Product;
use Modules\HR\Models\HrEmployee;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderChangeRequest;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/ProcurementSupport.php';

beforeEach(function (): void {
    $this->fixture = procurementFixture();
    $fixture = $this->fixture;
    $this->replacementProduct = Product::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 9104, 'doc_num' => 'Product-SYNTHETIC-CORRECT-RAW', 'name' => 'SYNTHETIC corrected resin',
        'item_classification' => Product::ClassificationRawMaterial, 'item_unit_id' => $fixture['unit']->id, 'status' => 'active']);
    foreach (['purchases.purchase_order_change_requests.create', 'purchases.purchase_order_change_requests.approve',
        'purchases.prices.view', 'purchases.purchase_requisitions.edit'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $fixture['user']->givePermissionTo($ability);
    }
    $this->actingAs($fixture['user']);
});

test('approved unexecuted purchase change requests replace add and remove items through the real approval route', function (): void {
    $fixture = $this->fixture;
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
    $employee = HrEmployee::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 9301, 'doc_num' => 'HR-SYNTHETIC-CORRECTION', 'full_name' => 'SYNTHETIC requester', 'name' => 'SYNTHETIC requester', 'status' => 'active']);
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1, 'document_date' => now()->toDateString(), 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [
            ['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 10, 'unit_price' => 2],
            ['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 1, 'unit_price' => 3],
        ],
    ])['record']);
    [$original, $removed] = $order->lines->all();
    $this->get(route('admin.purchases.purchase-order-change-requests.create', $order))->assertOk()
        ->assertSee('requested_values[replace_lines]', false)->assertSee('data-add-procurement-line', false);
    $payload = ['_submission_token' => (string) Str::uuid(), 'requester_employee_id' => $employee->id,
        'request_date' => now()->toDateString(), 'reason' => 'SYNTHETIC wrong purchase product before execution',
        'requested_values' => ['replace_lines' => true, 'lines' => [
            ['public_id' => $original->public_id, 'product_doc_num' => $this->replacementProduct->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => '3', 'unit_price' => '7'],
            ['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
                'ordered_quantity' => '2', 'unit_price' => '4'],
        ]]];
    $storeUrl = route('admin.purchases.purchase-order-change-requests.store', $order);
    $this->postJson($storeUrl, $payload)->assertSuccessful();
    $change = PurchaseOrderChangeRequest::query()->sole();
    expect($order->fresh()->lines->first()->product_id)->toBe($fixture['raw']->id)
        ->and($change->original_values['lines'][0]['product_id'])->toBe($fixture['raw']->id);
    $this->postJson(route('admin.purchases.purchase-order-change-requests.approve', $change))->assertSuccessful();
    $corrected = $order->fresh();
    expect($corrected->status)->toBe(PurchaseOrder::StatusApproved)
        ->and($corrected->lines->first()->public_id)->toBe($original->public_id)
        ->and($corrected->lines->first()->product_id)->toBe($this->replacementProduct->id)
        ->and($corrected->lines->first()->product_snapshot['name'])->toBe($this->replacementProduct->name)
        ->and($corrected->lines->pluck('id')->all())->not->toContain($removed->id)
        ->and($corrected->total_amount)->toBe('29.0000')
        ->and($corrected->total_ordered_quantity)->toBe('5.00000000')
        ->and($change->fresh()->status)->toBe('approved')
        ->and(DB::table('inventory_transactions')->count())->toBe(0)
        ->and(DB::table('journal_entries')->count())->toBe(0);
    $fixture['user']->revokePermissionTo('purchases.purchase_order_change_requests.approve');
    $this->postJson(route('admin.purchases.purchase-order-change-requests.approve', $change))->assertForbidden();
});

test('purchase request item correction supports reorder replacement addition removal and approved snapshot history', function (): void {
    $fixture = $this->fixture;
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture)));
    $sourceLine = $request->lines->sole();
    $request = $sourcing->reopenRequisition($request, 'SYNTHETIC wrong requirement item');
    $updated = $sourcing->updateRequisition($request, ['request_date' => now()->toDateString(),
        'branch_store_uuid' => $fixture['store']->public_uuid, 'lines' => [
            ['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'requested_quantity' => '2'],
            ['public_id' => $sourceLine->public_id, 'product_doc_num' => $this->replacementProduct->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num, 'requested_quantity' => '6'],
        ]]);
    expect($updated->lines->last()->id)->toBe($sourceLine->id)
        ->and($updated->lines->last()->product_id)->toBe($this->replacementProduct->id)
        ->and($updated->lines->last()->approved_quantity)->toBe('0.00000000');
    $updated = $sourcing->updateRequisition($updated, ['request_date' => now()->toDateString(),
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['public_id' => $sourceLine->public_id, 'product_doc_num' => $this->replacementProduct->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'requested_quantity' => '6']]]);
    expect($updated->lines)->toHaveCount(1)->and($updated->lines->sole()->line_number)->toBe(1);
    $reopened = json_decode(DB::table('activity_log')->where('event', 'purchase_requisition.reopened')->latest('id')->value('properties'), true);
    expect($reopened['approved_snapshot']['lines'][0]['product_id'])->toBe($fixture['raw']->id);
    $updated = $sourcing->approveRequisition($sourcing->submitRequisition($updated));
    expect($updated->lines->sole()->approved_quantity)->toBe('6.00000000');
});

test('purchase item correction refuses stale proposals and downstream documents at approval without partial changes', function (string $mode, string $locale): void {
    $fixture = $this->fixture;
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
    app()->setLocale($locale);
    $orders = app(PurchaseOrderService::class);
    $settlement = app(ProcurementSettlementService::class);
    $order = $orders->approve($orders->create(['supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'document_date' => now()->toDateString(),
        'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => '10', 'unit_price' => '2']]])['record']);
    $change = $settlement->requestPurchaseOrderChange($order, ['request_date' => now()->toDateString(),
        'reason' => 'SYNTHETIC proposed replacement', 'requested_values' => ['replace_lines' => true,
            'lines' => [['public_id' => $order->lines->sole()->public_id,
                'product_doc_num' => $this->replacementProduct->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
                'ordered_quantity' => '10', 'unit_price' => '3']]]]);
    if ($mode === 'stale') {
        $order->update(['notes' => 'SYNTHETIC change after proposal']);
    } else {
        $order->deliverySchedules()->create(['company_id' => $order->company_id, 'financial_period_id' => $order->financial_period_id,
            'purchase_order_line_id' => $order->lines->sole()->id, 'scheduled_date' => now()->toDateString(), 'sequence' => 1,
            'scheduled_quantity' => '1', 'received_quantity' => '0', 'status' => 'planned']);
    }
    $before = [$order->fresh()->attributesToArray(), $order->lines()->get()->map->attributesToArray()->all(), DB::table('activity_log')->count()];
    expect(fn () => $settlement->approvePurchaseOrderChange($change))->toThrow(DomainException::class,
        __('procurement.messages.'.($mode === 'stale' ? 'line_correction_stale' : 'line_correction_execution_blocked')));
    expect([$order->fresh()->attributesToArray(), $order->lines()->get()->map->attributesToArray()->all(), DB::table('activity_log')->count()])->toBe($before)
        ->and($change->fresh()->status)->toBe('pending')
        ->and(DB::table('inventory_transactions')->count())->toBe(0)
        ->and(DB::table('journal_entries')->count())->toBe(0);
})->with([['stale', 'en'], ['dependent', 'en'], ['dependent', 'ar']]);

test('purchase source references reject an incompatible replacement while preserving approved demand', function (): void {
    $fixture = $this->fixture;
    $sourcing = app(ProcurementSourcingService::class);
    $source = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture)));
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create(['supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'document_date' => now()->toDateString(),
        'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['purchase_requisition_line_id' => $source->lines->sole()->id, 'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => '10', 'unit_price' => '2']]])['record']);
    $settlement = app(ProcurementSettlementService::class);
    $change = $settlement->requestPurchaseOrderChange($order, ['request_date' => now()->toDateString(),
        'reason' => 'SYNTHETIC incompatible source product', 'requested_values' => ['replace_lines' => true,
            'lines' => [['public_id' => $order->lines->sole()->public_id,
                'product_doc_num' => $this->replacementProduct->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
                'ordered_quantity' => '10', 'unit_price' => '3']]]]);
    expect(fn () => $settlement->approvePurchaseOrderChange($change))->toThrow(DomainException::class,
        __('Purchase order item and unit must match the approved purchase request line.'));
    expect($order->fresh()->lines->sole()->product_id)->toBe($fixture['raw']->id)
        ->and($source->fresh()->lines->sole()->product_id)->toBe($fixture['raw']->id)
        ->and($change->fresh()->status)->toBe('pending');
});
