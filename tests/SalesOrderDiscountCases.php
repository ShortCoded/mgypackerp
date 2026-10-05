<?php

use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\QuotationRevision;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\QuotationCalculationService;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

/** @return array<string, mixed> */
function salesOrderDiscountFixture(): array
{
    $fixture = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    foreach (['sales_orders.create', 'customer_invoices.create'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $fixture['user']->givePermissionTo($ability);
    }
    test()->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($fixture));

    return $fixture;
}

test('editable orders persist chosen discounts and clear them without applying a header twice', function (): void {
    $payload = salesCycleOrderPayload(salesOrderDiscountFixture(), ['discount_type' => 'fixed', 'discount_value' => '50', 'payment_schedules' => []]);
    $payload['lines'][0] += ['discount_type' => 'percentage', 'discount_value' => '10'];
    $payload['lines'][1] += ['discount_type' => 'fixed', 'discount_value' => '5'];
    $orders = app(SalesOrderService::class);
    $order = $orders->create($payload);
    expect($order->total_amount)->toBe('945.0000')->and($order->discount_amount)->toBe('155.0000')
        ->and($order->header_discount_amount)->toBe('50.0000')->and($order->lines[0]->discount_value)->toBe('10.0000');
    foreach ($order->lines as $i => $line) {
        $payload['lines'][$i]['public_id'] = $line->public_id;
        $payload['lines'][$i]['discount_amount'] = $line->discount_amount;
    }
    $updated = $orders->update($order, $payload);
    expect($updated->total_amount)->toBe('945.0000')->and($updated->discount_amount)->toBe('155.0000');
    $payload['discount_type'] = null;
    $payload['discount_value'] = '0';
    $updated = $orders->update($updated, $payload);
    expect($updated->total_amount)->toBe('995.0000')->and($updated->header_discount_amount)->toBe('0.0000');
    $payload['lines'][0]['discount_value'] = '100.0001';
    $before = $updated->attributesToArray();
    expect(fn () => $orders->update($updated, $payload))->toThrow(DomainException::class);
    expect($updated->fresh()->attributesToArray())->toBe($before);
});

test('typed HTTP discounts use resolved prices and enforce the combined price list cap', function (): void {
    $f = salesOrderDiscountFixture();
    createSalesPriceList($f, null, [['product' => $f['service'], 'price' => '100', 'discount_type' => 'percentage', 'discount_value' => '20']]);
    $payload = ['customer_doc_num' => $f['customer']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
        'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(),
        'discount_type' => 'fixed', 'discount_value' => '10', 'lines' => [['product_doc_num' => $f['service']->doc_num,
            'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '1', 'unit_price' => '999',
            'discount_type' => 'percentage', 'discount_value' => '10', 'discount_amount' => '999']]];
    $this->postJson(route('admin.sales.sales-orders.store'), $payload)->assertCreated();
    $order = SalesOrder::query()->where('company_id', $f['company']->id)->with('lines')->sole();
    expect($order->total_amount)->toBe('80.0000')->and($order->lines->sole()->unit_price)->toBe('100.00000000')
        ->and($order->lines->sole()->discount_amount)->toBe('20.0000');
    $payload['discount_value'] = '10.0001';
    $this->postJson(route('admin.sales.sales-orders.store'), $payload)->assertUnprocessable();
    expect(SalesOrder::query()->where('company_id', $f['company']->id)->count())->toBe(1);
    $payload['discount_value'] = '10';
    $payload['lines'][0]['discount_value'] = '1e2';
    $this->postJson(route('admin.sales.sales-orders.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('lines.0.discount_value');
});

test('partial invoices allocate booked commercial discounts exactly once including final rounding residue', function (): void {
    $f = salesOrderDiscountFixture();
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($f, ['discount_type' => 'fixed', 'discount_value' => '0.0002',
        'payment_schedules' => [], 'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id,
            'quantity' => '3', 'unit_price' => '22.54545000', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_amount' => '8.5222']]])));
    $line = $order->lines->sole();
    $invoices = app(CustomerInvoiceService::class);
    foreach (['23.1317', '23.1317', '23.1314'] as $amount) {
        $invoices->createFromOrder($order, [['sales_order_line_id' => $line->id, 'quantity' => '1']], [['amount' => $amount, 'due_date' => now()->toDateString()]]);
    }
    expect(bcadd((string) DB::table('customer_invoice_lines')->where('sales_order_line_id', $line->id)->sum('discount_amount'), '0', 4))->toBe($line->discount_amount)
        ->and(bcadd((string) DB::table('customer_invoices')->where('sales_order_id', $order->id)->sum('total_amount'), '0', 4))->toBe($order->total_amount);
});

test('accepted quotation partial conversion preserves source discount modes and amounts on an unchanged order edit', function (): void {
    $f = salesOrderDiscountFixture();
    $quotation = Quotation::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'doc_number' => 99001, 'doc_num' => 'SYNTHETIC-DISCOUNT-QUOTE-'.$f['company']->id,
        'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'exchange_rate' => '1',
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(), 'status' => Quotation::StatusAccepted]);
    $calculated = app(QuotationCalculationService::class)->calculate([['line_number' => 1,
        'product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '3', 'base_quantity' => '3', 'conversion_factor' => '1',
        'unit_price' => '22.54545', 'discount_type' => 'percentage', 'discount_value' => '10',
        'allowed_discount_type' => 'percentage', 'allowed_discount_value' => '100', 'tax_rate' => '14']], 'fixed', '0.0002');
    $revision = $quotation->revisions()->create([...$calculated['revision'], 'revision_number' => 1,
        'revision_code' => $quotation->doc_num.'-R1', 'revision_date' => now()->toDateString(), 'status' => QuotationRevision::StatusAccepted]);
    $sourceLine = $revision->lines()->create($calculated['lines'][0]);
    $quotation->update(['current_revision_id' => $revision->id]);
    $before = [$revision->fresh()->getAttributes(), $sourceLine->fresh()->getAttributes()];
    $orders = app(SalesOrderService::class);
    $order = $orders->createFromQuotation($quotation, ['company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id], [['public_id' => $sourceLine->public_uuid, 'quantity' => '1']]);
    $line = $order->lines->sole();
    expect($order->discount_type)->toBe('fixed')->and($order->discount_value)->toBe('0.0000')
        ->and($line->discount_type)->toBe('percentage')->and($line->discount_value)->toBe('10.0000')
        ->and($line->discount_amount)->toBe('2.2545')->and($order->total_amount)->toBe('23.1317');
    $order = $orders->update($order, [...$order->attributesToArray(), 'lines' => [[...$line->attributesToArray(), 'public_id' => $line->public_id]], 'payment_schedules' => []]);
    expect($order->total_amount)->toBe('23.1317')->and($order->lines->sole()->discount_amount)->toBe('2.2545')
        ->and($revision->fresh()->total)->toBe('69.3948');
    expect([$revision->fresh()->getAttributes(), $sourceLine->fresh()->getAttributes()])->toBe($before)
        ->and($quotation->fresh()->status)->toBe(Quotation::StatusAccepted);
    $order = $orders->approve($order);
    foreach (['11.5658', '11.5659'] as $total) {
        app(CustomerInvoiceService::class)->createFromOrder($order, [['sales_order_line_id' => $line->id, 'quantity' => '0.5']],
            [['amount' => $total, 'due_date' => now()->toDateString()]]);
    }
    expect(bcadd((string) DB::table('customer_invoices')->where('sales_order_id', $order->id)->sum('total_amount'), '0', 4))->toBe($order->total_amount)
        ->and(bcadd((string) DB::table('customer_invoice_lines')->where('sales_order_line_id', $line->id)->sum('discount_amount'), '0', 4))->toBe($line->discount_amount);
});

test('commercial discount inputs render on orders and direct invoices in both locales', function (string $locale): void {
    salesOrderDiscountFixture();
    $this->withSession(['locale' => $locale]);
    app()->setLocale($locale);
    $this->get(route('admin.sales.sales-orders.create'))->assertOk()->assertSee('data-sales-discount-inputs', false)
        ->assertSee('lines[0][discount_type]', false)->assertSee('name="discount_type"', false)->assertSee(__('sales_ui.header_discount_help'));
    $this->get(route('admin.sales.sales-invoices.create', ['direct' => 1]))->assertOk()->assertSee('data-sales-discount-inputs', false)->assertSee('lines[0][discount_type]', false);
})->with(['en', 'ar']);
