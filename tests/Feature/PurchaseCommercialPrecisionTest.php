<?php

use App\Casts\PurchaseUnitPriceCast;
use Illuminate\Support\Facades\DB;
use Modules\HR\Models\HrEmployee;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderChangeRequest;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseOrderCalculationService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

test('purchase commercial calculations retain fifteen digit prices before monetary rounding', function (string $price, string $quantity, string $total): void {
    $order = app(PurchaseOrderCalculationService::class)->calculate([['ordered_quantity' => $quantity, 'unit_price' => $price]]);
    $invoice = app(PurchaseInvoiceCalculationService::class)->calculate([['quantity' => $quantity, 'unit_price' => $price]], null, 0);
    expect($order['lines'][0]['unit_price'])->toBe($price)
        ->and($invoice['lines'][0]['unit_price'])->toBe($price)
        ->and($order['order']['total_amount'])->toBe($total)
        ->and($invoice['invoice']['total_amount'])->toBe($total);
})->with([
    ['32.916666666666666', '360', '11850.0000'],
    ['0.000000000000001', '1000000000000', '0.0010'],
    ['1.000000000000005', '10000000000', '10000000000.0001'],
]);

test('purchase price cast retains meaningful precision and legacy eight digit representations', function (): void {
    $cast = new PurchaseUnitPriceCast;
    $line = new PurchaseOrderLine;
    expect($cast->get($line, 'unit_price', '32.916666666666666', []))->toBe('32.916666666666666')
        ->and($cast->get($line, 'unit_price', '22.545451230000000', []))->toBe('22.54545123')
        ->and($cast->get($line, 'unit_price', '1.000000000000000', []))->toBe('1.00000000')
        ->and($cast->set($line, 'unit_price', '0.000000000000001', []))->toBe('0.000000000000001');
    expect(fn () => $cast->set($line, 'unit_price', '1.1234567890123456', []))->toThrow(InvalidArgumentException::class);
});

test('native purchase order supports fifteen digit prices and rejects overflow atomically without a requester requirement', function (): void {
    $f = procurementFixture();
    procurementUseBranch($f, procurementAdministrativeBranch($f));
    foreach (['purchase_orders.create', 'purchase_orders.edit', 'purchase_orders.view', 'purchases.prices.view', 'purchases.direct_procurement.override'] as $name) {
        Permission::findOrCreate($name, 'web');
        $f['user']->givePermissionTo($name);
    }
    $this->actingAs($f['user']);
    $payload = ['branch_store_uuid' => $f['store']->public_uuid, 'supplier_doc_num' => $f['firstSupplier']->doc_num,
        'currency_doc_num' => $f['currency']->doc_num, 'document_date' => now()->toDateString(), 'exchange_rate' => '1',
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'SYNTHETIC precision verification',
        'lines' => [['product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'ordered_quantity' => '100000000000', 'unit_price' => '0.000000000000001']]];
    $this->get(route('admin.purchases.purchase-orders.create'))->assertOk()->assertSee('data-numeric-scale="15"', false)->assertDontSee('name="requester_employee_id"', false);
    foreach (['1.1234567890123456', '100000000000000', '1e-15', '-0.000000000000001'] as $invalid) {
        $bad = $payload;
        $bad['lines'][0]['unit_price'] = $invalid;
        $this->postJson(route('admin.purchases.purchase-orders.store'), $bad)->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
    }
    expect(PurchaseOrder::query()->count())->toBe(0);
    $created = $this->postJson(route('admin.purchases.purchase-orders.store'), $payload)->assertOk();
    $order = PurchaseOrder::query()->where('doc_num', $created->json('data.doc_num'))->sole();
    expect($order->total_amount)->toBe('0.0001')->and($order->lines->sole()->unit_price)->toBe('0.000000000000001');
    $this->get(route('admin.purchases.purchase-orders.edit', $order))->assertOk()->assertSee('0.000000000000001')->assertDontSee('name="requester_employee_id"', false);
    $payload['lines'][0]['public_id'] = $order->lines->sole()->public_id;
    $this->putJson(route('admin.purchases.purchase-orders.update', $order), $payload)->assertOk();
    expect($order->fresh()->total_amount)->toBe('0.0001')->and(DB::table('journal_entries')->count())->toBe(0);
});

test('native approved purchase change retains fifteen digit prices and required requester errors in the existing AJAX form', function (): void {
    $f = procurementFixture();
    procurementUseBranch($f, procurementAdministrativeBranch($f));
    foreach (['purchases.purchase_order_change_requests.create', 'purchases.purchase_order_change_requests.approve', 'purchases.prices.view'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $f['user']->givePermissionTo($ability);
    }
    $this->actingAs($f['user']);
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create(['supplier_doc_num' => $f['firstSupplier']->doc_num, 'branch_store_uuid' => $f['store']->public_uuid,
        'currency_doc_num' => $f['currency']->doc_num, 'document_date' => now()->toDateString(), 'exchange_rate' => '1',
        'lines' => [['product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'ordered_quantity' => '360', 'unit_price' => '2']]])['record']);
    $employee = HrEmployee::query()->create(['company_id' => $f['company']->id, 'doc_number' => 9391, 'doc_num' => 'SYNTHETIC-PRICE-REQUESTER',
        'full_name' => 'SYNTHETIC price requester', 'name' => 'SYNTHETIC price requester', 'status' => 'active']);
    $this->get(route('admin.purchases.purchase-order-change-requests.create', $order))->assertOk()->assertSee('data-procurement-form', false)->assertSee('data-numeric-scale="15"', false);
    $this->getJson(route('admin.purchases.select2.employees'))->assertOk()->assertJsonFragment(['id' => (string) $employee->id]);
    $payload = ['request_date' => now()->toDateString(), 'reason' => 'SYNTHETIC price correction', 'requested_values' => ['replace_lines' => true,
        'lines' => [['public_id' => $order->lines->sole()->public_id, 'product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
            'ordered_quantity' => '360', 'unit_price' => '32.916666666666666']]]];
    $url = route('admin.purchases.purchase-order-change-requests.store', $order);
    $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('requester_employee_id');
    expect(PurchaseOrderChangeRequest::query()->count())->toBe(0);
    $payload['requester_employee_id'] = $employee->id;
    $this->postJson($url, $payload)->assertOk();
    $change = PurchaseOrderChangeRequest::query()->sole();
    expect($change->requested_values['lines'][0]['unit_price'])->toBe('32.916666666666666');
    $this->postJson(route('admin.purchases.purchase-order-change-requests.approve', $change))->assertOk();
    expect(bccomp($order->fresh()->lines->sole()->unit_price, '32.916666666666666', DB::getDriverName() === 'sqlite' ? 10 : 15))->toBe(0)
        ->and($change->fresh()->requested_values['lines'][0]['unit_price'])->toBe('32.916666666666666')->and($order->fresh()->total_amount)->toBe('11850.0000')
        ->and(DB::table('inventory_transactions')->count())->toBe(0)->and(DB::table('journal_entries')->count())->toBe(0);
});
