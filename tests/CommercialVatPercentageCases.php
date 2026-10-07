<?php

use Illuminate\Support\Facades\DB;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\QuotationCalculationService;
use Modules\Sales\Services\SalesAmountService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderDiscountService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesTaxService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

require_once __DIR__.'/CommercialVatPercentageSupport.php';

test('VAT rates apply after line and header discounts and preserve explicit legacy money', function (): void {
    $tax = app(SalesTaxService::class);
    foreach (['0' => '0.0000', '14' => '119.0000', '0.1234' => '1.0489', '100' => '850.0000'] as $rate => $expected) {
        $result = app(SalesOrderDiscountService::class)->calculate([['quantity' => '1', 'unit_price' => '1000',
            'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => (string) $rate, 'tax_amount' => '9999']], 'fixed', '50');
        expect($result['lines'][0]['discount_amount'])->toBe('150.0000')->and($result['lines'][0]['tax_amount'])->toBe($expected)
            ->and($result['lines'][0]['tax_calculation_basis'])->toBe(SalesTaxService::Rate);
        $purchase = app(PurchaseInvoiceCalculationService::class)->calculate([['quantity' => '1', 'unit_price' => '1000', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => (string) $rate]], 'fixed', '50');
        expect($purchase['invoice']['tax_amount'])->toBe($expected);
    }
    foreach (['-1', '100.0001', '1.00001', '1e2', 'foo'] as $invalid) {
        expect(fn () => $tax->amount('1000', $invalid))->toThrow(DomainException::class);
    }
    expect($tax->calculate(['tax_amount' => '126'], '850'))->toBe(['tax_rate' => null, 'tax_calculation_basis' => 'legacy_amount', 'tax_amount' => '126.0000']);
    $tiny = app(SalesOrderDiscountService::class)->calculate(array_fill(0, 5, ['quantity' => '1', 'unit_price' => '0.0001', 'tax_rate' => '100']), 'fixed', '0.0002');
    expect(array_column($tiny['lines'], 'header_discount_amount'))->toBe(['0.0000', '0.0000', '0.0000', '0.0001', '0.0001']);
    foreach ($tiny['lines'] as $tinyLine) {
        expect(bccomp($tinyLine['tax_amount'], '0', 4))->toBeGreaterThanOrEqual(0);
    }
    $quotation = app(QuotationCalculationService::class)->calculate([['quantity' => '1', 'unit_price' => '1000', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14']], 'fixed', '50');
    expect($quotation['revision']['tax_amount'])->toBe('119.0000')->and($quotation['revision']['total'])->toBe('969.0000');
});

test('native direct invoice accepts percent only and rejects rate range and precision in both locales', function (string $locale): void {
    $f = commercialVatFixture();
    $this->withSession(['locale' => $locale]);
    app()->setLocale($locale);
    $this->get(route('admin.sales.sales-invoices.create', ['direct' => 1]))->assertOk()->assertSee('lines[0][tax_rate]', false)
        ->assertSee(__('sales_ui.tax_rate'))->assertDontSee('name="lines[0][tax_amount]"', false);
    $payload = commercialVatDirectPayload($f);
    $payload['lines'][0]['tax_amount'] = '999';
    $this->postJson(route('admin.sales.sales-invoices.store'), $payload)->assertCreated();
    $invoice = CustomerInvoice::query()->where('company_id', $f['company']->id)->where('source_type', 'direct')->sole();
    expect($invoice->tax_amount)->toBe('119.0000')->and($invoice->total_amount)->toBe('969.0000')
        ->and($invoice->lines->sole()->tax_rate)->toBe('14.0000');
    $this->get(route('admin.sales.sales-invoices.edit', $invoice))->assertOk()->assertSee('14.0000')->assertDontSee('name="lines[0][tax_amount]"', false);
    foreach (['-1', '100.0001', '0.12345', '1e2'] as $invalid) {
        $bad = $payload;
        $bad['lines'][0]['tax_rate'] = $invalid;
        $this->postJson(route('admin.sales.sales-invoices.store'), $bad)->assertUnprocessable()->assertJsonValidationErrors('lines.0.tax_rate');
    }
    expect(CustomerInvoice::query()->where('company_id', $f['company']->id)->where('source_type', 'direct')->count())->toBe(1);
})->with(['ar', 'en']);

test('rate invoices post net revenue and VAT and safely reopen without rewriting their original journal', function (): void {
    $f = commercialVatFixture();
    $service = app(CustomerInvoiceService::class);
    $invoice = $service->post($service->createDirect(commercialVatDirectPayload($f)));
    $journal = $invoice->journalEntry;
    $original = $journal->lines()->orderBy('id')->get()->map->getAttributes()->all();
    expect(bcadd((string) $journal->lines()->where('description', 'Service revenue')->sum('credit_amount'), '0', 4))->toBe('850.0000')
        ->and(bcadd((string) $journal->lines()->where('description', 'Output tax')->sum('credit_amount'), '0', 4))->toBe('119.0000');
    $lineId = $invoice->lines->sole()->public_id;
    expect(fn () => $service->amend($invoice, [['invoice_line_public_id' => $lineId, 'quantity' => '1', 'tax_rate' => '10']], [['amount' => '935', 'due_date' => now()->toDateString()]]))->toThrow(DomainException::class);
    $invoice = $service->reopen($invoice, 'SYNTHETIC explicit VAT rate correction');
    $invoice = $service->amend($invoice, [['invoice_line_public_id' => $lineId, 'quantity' => '1', 'tax_rate' => '10']], [['amount' => '935', 'due_date' => now()->toDateString()]]);
    $invoice = $service->post($invoice);
    expect($invoice->tax_amount)->toBe('85.0000')->and($invoice->total_amount)->toBe('935.0000')
        ->and($invoice->lines->sole()->tax_rate)->toBe('10.0000')->and($invoice->posting_revision)->toBe(1)
        ->and($journal->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($original);
});

test('repeated partial rate invoices retain source rates and conserve booked tax rounding and header allocations', function (): void {
    $f = commercialVatFixture('22.54545000');
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($f, ['payment_schedules' => [], 'discount_type' => 'fixed', 'discount_value' => '0.0002',
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '3', 'unit_price' => '22.54545000', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14']]])));
    $line = $order->lines->sole();
    $invoices = app(CustomerInvoiceService::class);
    foreach (['23.1317', '23.1317', '23.1314'] as $total) {
        $invoice = $invoices->createFromOrder($order, [['sales_order_line_id' => $line->id, 'quantity' => '1']], [['amount' => $total, 'due_date' => now()->toDateString()]]);
        expect($invoice->lines->sole()->tax_rate)->toBe('14.0000')->and($invoice->lines->sole()->tax_calculation_basis)->toBe(SalesTaxService::SourceAllocation);
        $invoices->amend($invoice, [['invoice_line_public_id' => $invoice->lines->sole()->public_id, 'quantity' => '1']], [['amount' => $total, 'due_date' => now()->toDateString()]]);
        expect(fn () => $invoices->amend($invoice, [['invoice_line_public_id' => $invoice->lines->sole()->public_id, 'quantity' => '1', 'tax_rate' => '10']], [['amount' => $total, 'due_date' => now()->toDateString()]]))->toThrow(DomainException::class);
    }
    expect(bcadd((string) $order->invoices()->sum('tax_amount'), '0', 4))->toBe($order->tax_amount)
        ->and(bcadd((string) $order->invoices()->sum('total_amount'), '0', 4))->toBe($order->total_amount)
        ->and(bcadd((string) DB::table('customer_invoice_lines')->where('sales_order_line_id', $line->id)->sum('header_discount_amount'), '0', 4))->toBe($line->header_discount_amount);
});

test('legacy amount migration preserves historical financial rows and refuses lossy rollback after a rate exists', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        test()->markTestSkipped('Migration preservation is separately verified against all existing PostgreSQL financial rows.');
    }
    $f = commercialVatFixture();
    $data = commercialVatDirectPayload($f);
    unset($data['lines'][0]['tax_rate']);
    $data['lines'][0]['tax_amount'] = '126';
    $invoice = app(CustomerInvoiceService::class)->createDirect($data);
    $line = $invoice->lines->sole();
    $migration = require base_path('modules/Sales/Database/Migrations/2026_10_06_124602_add_vat_rate_contract_to_sales_lines.php');
    $before = $line->only(['quantity', 'unit_price', 'discount_amount', 'tax_amount', 'line_total']);
    $header = $invoice->fresh()->getAttributes();
    $migration->down();
    $migration->up();
    expect($line->fresh()->only(array_keys($before)))->toBe($before)->and($invoice->fresh()->getAttributes())->toBe($header)
        ->and($line->fresh()->tax_rate)->toBeNull()->and($line->fresh()->tax_calculation_basis)->toBe('legacy_amount');
    $line->update(['tax_rate' => '14', 'tax_calculation_basis' => 'rate']);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect($line->fresh()->only(array_keys($before)))->toBe($before);
});

test('unknown legacy order VAT survives unchanged edits and requires an explicit rate for new commercial amounts', function (): void {
    $f = commercialVatFixture();
    $orders = app(SalesOrderService::class);
    $order = $orders->create(salesCycleOrderPayload($f, ['payment_schedules' => [], 'discount_type' => 'fixed', 'discount_value' => '50',
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1', 'unit_price' => '1000',
            'discount_type' => 'percentage', 'discount_value' => '10', 'tax_amount' => '126',
            'allowed_discount_type' => 'percentage', 'allowed_discount_value' => '100']]]));
    $line = $order->lines->sole();
    $this->get(route('admin.sales.sales-orders.edit', $order))->assertOk()->assertSee(__('sales_ui.legacy_tax_help'));
    $payload = ['amendment_token' => $order->amendmentToken(), 'customer_doc_num' => $f['customer']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
        'order_date' => $order->order_date->toDateString(), 'expected_delivery_date' => $order->expected_delivery_date->toDateString(),
        'discount_type' => 'fixed', 'discount_value' => '50', 'payment_schedules' => [],
        'lines' => [['public_id' => $line->public_id, 'product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
            'quantity' => '1', 'unit_price' => '1000', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => null]]];
    $this->putJson(route('admin.sales.sales-orders.update', $order), $payload)->assertOk();
    expect($line->fresh()->tax_amount)->toBe('126.0000')->and($line->fresh()->tax_rate)->toBeNull()->and($order->fresh()->total_amount)->toBe('976.0000');
    $payload['amendment_token'] = $order->fresh()->amendmentToken();
    $payload['lines'][0]['quantity'] = '2';
    $this->putJson(route('admin.sales.sales-orders.update', $order), $payload)->assertUnprocessable();
    expect($line->fresh()->quantity)->toBe('1.00000000')->and($line->fresh()->tax_amount)->toBe('126.0000');
    $payload['lines'][0]['tax_rate'] = '14';
    $this->putJson(route('admin.sales.sales-orders.update', $order), $payload)->assertOk();
    expect($line->fresh()->tax_rate)->toBe('14.0000')->and($line->fresh()->tax_amount)->toBe('245.0000')
        ->and($order->fresh()->total_amount)->toBe('1995.0000');
});

test('native purchase VAT and freight previews enforce the same fractional percentage precision as save requests', function (): void {
    $f = commercialVatFixture();
    foreach (['purchases.prices.view', 'purchase_invoices.create'] as $ability) {
        $f['user']->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $data = ['header_discount_type' => 'fixed', 'header_discount_value' => '50', 'freight_amount' => '100', 'freight_tax_rate' => '0.1234',
        'lines' => [['product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '1', 'unit_price' => '1000',
            'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '0.1234']]];
    $url = route('admin.purchases.purchase-invoices.discount-preview');
    $this->postJson($url, $data)->assertOk()->assertJsonPath('data.calculation.invoice.tax_amount', '1.1723')->assertJsonPath('data.calculation.invoice.total_amount', '951.1723');
    foreach (['-1', '100.0001', '0.12345', '1e2'] as $invalid) {
        $this->postJson($url, [...$data, 'freight_tax_rate' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('freight_tax_rate');
        $bad = $data;
        $bad['lines'][0]['tax_rate'] = $invalid;
        $this->postJson($url, $bad)->assertUnprocessable()->assertJsonValidationErrors('lines.0.tax_rate');
    }
});

test('executed order amendments calculate VAT on new demand and retain the booked financial terms of existing rows', function (): void {
    $f = commercialVatFixture();
    $orders = app(SalesOrderService::class);
    $payload = salesCycleOrderPayload($f, ['payment_schedules' => []]);
    $payload['lines'][0]['tax_rate'] = '14';
    $order = $orders->approve($orders->create($payload));
    app(SalesFulfillmentService::class)->reserve($order->lines->first(), '1');
    $order = $orders->reopen($order->fresh(), 'SYNTHETIC additional future demand');
    foreach ($order->lines as $index => $line) {
        $payload['lines'][$index]['public_id'] = $line->public_id;
        $payload['lines'][$index]['tax_amount'] = $line->tax_amount;
    }
    $payload['amendment_token'] = $order->amendmentToken();
    $payload['lines'][] = ['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id,
        'quantity' => '1', 'unit_price' => '1000', 'tax_rate' => '14'];
    $before = $order->lines->map->getAttributes()->all();
    $taxBefore = $order->tax_amount;
    $totalBefore = $order->total_amount;
    $updated = $orders->update($order, $payload);
    expect($updated->lines->take(2)->map->getAttributes()->all())->toEqual($before)
        ->and($updated->lines->last()->tax_amount)->toBe('140.0000')
        ->and($updated->lines->last()->line_total)->toBe('1140.0000')
        ->and($updated->tax_amount)->toBe(bcadd($taxBefore, '140', 4))
        ->and($updated->total_amount)->toBe(bcadd($totalBefore, '1140', 4));
    $payload['amendment_token'] = $updated->amendmentToken();
    $payload['lines'][0]['quantity'] = bcadd($payload['lines'][0]['quantity'], '1', 8);
    $payload['lines'][2]['public_id'] = $updated->lines->last()->public_id;
    $payload['lines'][2]['tax_amount'] = '140';
    expect(fn () => $orders->update($updated, $payload))->toThrow(DomainException::class, __('sales_ui.production_amendment_locked_terms'));
    expect($updated->fresh()->total_amount)->toBe(bcadd($totalBefore, '1140', 4));
});

test('repeated micro value invoices never overallocate rounded gross amounts and preserve source VAT', function (): void {
    $f = commercialVatFixture('0.00005');
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($f, ['payment_schedules' => [],
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '3', 'unit_price' => '0.00005', 'tax_rate' => '100']]])));
    $invoices = app(CustomerInvoiceService::class);
    $totals = [];
    foreach (['0.0001', '0.0001', '0.0002'] as $total) {
        $invoice = $invoices->createFromOrder($order->fresh(), [['sales_order_line_id' => $order->lines->sole()->id, 'quantity' => '1']], [['amount' => $total, 'due_date' => now()->toDateString()]]);
        $totals[] = $invoice->total_amount;
        expect(bccomp($invoice->subtotal_amount, '0', 4))->not->toBeLessThan(0);
    }
    expect($totals)->toBe(['0.0001', '0.0001', '0.0002'])
        ->and(app(SalesAmountService::class)->sum($totals))->toBe($order->total_amount);
});

test('VAT is recalculated for every known-rate row when amendment redistributes its source header discount', function (): void {
    $f = commercialVatFixture();
    $orders = app(SalesOrderService::class);
    $data = salesCycleOrderPayload($f, ['payment_schedules' => [], 'discount_type' => 'fixed', 'discount_value' => '50',
        'lines' => array_fill(0, 2, ['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1', 'unit_price' => '1000',
            'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14'])]);
    $order = $orders->create($data);
    $order->lines()->update(['tax_calculation_basis' => SalesTaxService::SourceAllocation]);
    $order = $order->fresh()->load('lines');
    foreach ($order->lines as $index => $line) {
        $data['lines'][$index]['public_id'] = $line->public_id;
    }
    $data['lines'][0]['quantity'] = '2';
    $data['amendment_token'] = $order->amendmentToken();
    $order = $orders->update($order, $data);
    expect($order->lines->pluck('tax_amount')->all())->toBe(['247.3333', '123.6667'])
        ->and($order->lines->pluck('tax_calculation_basis')->all())->toBe(['rate', 'rate'])
        ->and($order->tax_amount)->toBe('371.0000')->and($order->total_amount)->toBe('3021.0000');
});

test('unknown legacy VAT blocks a sibling amendment that changes its taxable header allocation without an explicit rate', function (): void {
    $f = commercialVatFixture();
    $orders = app(SalesOrderService::class);
    $data = salesCycleOrderPayload($f, ['payment_schedules' => [], 'discount_type' => 'fixed', 'discount_value' => '50',
        'lines' => array_fill(0, 2, ['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1', 'unit_price' => '1000',
            'discount_type' => 'percentage', 'discount_value' => '10', 'tax_amount' => '126'])]);
    $order = $orders->create($data);
    foreach ($order->lines as $index => $line) {
        $data['lines'][$index]['public_id'] = $line->public_id;
        unset($data['lines'][$index]['tax_amount']);
    }
    $data['lines'][0]['quantity'] = '2';
    $data['lines'][0]['tax_rate'] = '14';
    $order = $order->fresh()->load('lines');
    $data['amendment_token'] = $order->amendmentToken();
    $before = [$order->getAttributes(), $order->lines->map->getAttributes()->all(), DB::table('activity_log')->count()];
    expect(fn () => $orders->update($order, $data))->toThrow(DomainException::class, __('sales_ui.legacy_tax_rate_required'));
    expect([$order->fresh()->getAttributes(), $order->lines()->get()->map->getAttributes()->all(), DB::table('activity_log')->count()])->toEqual($before);
    $data['lines'][1]['tax_rate'] = '14';
    expect($orders->update($order, $data)->total_amount)->toBe('3021.0000');
});
