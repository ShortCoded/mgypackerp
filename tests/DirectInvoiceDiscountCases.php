<?php

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Exports\SalesCycleReportExport;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Modules\Sales\Services\SalesWithholdingService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

/** @return array<string, mixed> */
function directDiscountFixture(string $price = '100', string $cap = '100'): array
{
    $f = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    foreach (['customer_invoices.create', 'customer_invoices.edit', 'customer_invoices.view', 'customer_invoices.view_prices', 'customer_invoices.print', 'customer_invoices.post', 'customer_invoices.reopen'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $f['user']->givePermissionTo($ability);
    }
    test()->actingAs($f['user'])->withSession(salesCycleSession($f));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($f));
    createSalesPriceList($f, null, [['product' => $f['service'], 'price' => $price, 'discount_type' => 'percentage', 'discount_value' => $cap]]);

    return $f;
}

/** @param array<string, mixed> $f @param array<string, mixed> $overrides @return array<string, mixed> */
function directDiscountPayload(array $f, array $overrides = []): array
{
    return [...salesCycleOrderPayload($f), 'customer_doc_num' => $f['customer']->doc_num,
        'currency_doc_num' => $f['currency']->doc_num, 'invoice_date' => now()->toDateString(), 'exchange_rate' => '1',
        'lines' => [['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
            'quantity' => '1', 'discount_amount' => '0', 'tax_amount' => '0']], ...$overrides];
}

test('direct invoice HTTP discounts use resolved prices ignore tampered amounts and enforce the combined cap', function (): void {
    $f = directDiscountFixture(cap: '20');
    $payload = directDiscountPayload($f, ['discount_type' => 'fixed', 'discount_value' => '10']);
    $payload['lines'][0] += ['unit_price' => '999', 'discount_type' => 'percentage', 'discount_value' => '10'];
    $payload['lines'][0]['discount_amount'] = '999';
    $this->postJson(route('admin.sales.sales-invoices.store'), $payload)->assertCreated();
    $invoice = CustomerInvoice::query()->where('company_id', $f['company']->id)->with('lines')->sole();
    expect($invoice->total_amount)->toBe('80.0000')->and($invoice->discount_type)->toBe('fixed')
        ->and($invoice->discount_value)->toBe('10.0000')->and($invoice->header_discount_amount)->toBe('10.0000')
        ->and($invoice->lines->sole()->unit_price)->toBe('100.00000000')->and($invoice->lines->sole()->discount_amount)->toBe('20.0000');
    $payload['discount_value'] = '10.0001';
    $this->postJson(route('admin.sales.sales-invoices.store'), $payload)->assertUnprocessable();
    expect(CustomerInvoice::query()->where('company_id', $f['company']->id)->count())->toBe(1);
    foreach (['101', '-1', '1.00001', '1e2'] as $invalid) {
        $payload['discount_value'] = '0';
        $payload['lines'][0]['discount_value'] = $invalid;
        $this->postJson(route('admin.sales.sales-invoices.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('lines.0.discount_value');
    }
});

test('direct legacy amount discounts retain native quantity proration and eight place prices', function (): void {
    $f = directDiscountFixture('22.54545000');
    $invoice = app(CustomerInvoiceService::class)->createDirect(directDiscountPayload($f, ['lines' => [[
        'product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
        'quantity' => '10000', 'discount_amount' => '0.1234', 'tax_amount' => '0']]]));
    expect($invoice->total_amount)->toBe('225454.3766')->and($invoice->discount_type)->toBeNull()
        ->and($invoice->lines->sole()->discount_type)->toBeNull();
    $invoice = app(CustomerInvoiceService::class)->amend($invoice, [['invoice_line_public_id' => $invoice->lines->sole()->public_id, 'quantity' => '5000']],
        [['amount' => '112727.1883', 'due_date' => now()->toDateString()]]);
    expect($invoice->total_amount)->toBe('112727.1883')->and($invoice->lines->sole()->discount_amount)->toBe('0.0617');
});

test('direct typed amendments hydrate stored modes clear the header and never allocate a header twice', function (): void {
    $f = directDiscountFixture();
    $payload = directDiscountPayload($f, ['discount_type' => 'fixed', 'discount_value' => '10']);
    $payload['lines'][0] += ['discount_type' => 'percentage', 'discount_value' => '10'];
    $payload['lines'][0]['quantity'] = '2';
    $service = app(CustomerInvoiceService::class);
    $invoice = $service->createDirect($payload);
    $lineId = $invoice->lines->sole()->public_id;
    $invoice = $service->amend($invoice, [['invoice_line_public_id' => $lineId, 'quantity' => '2']], [['amount' => '170', 'due_date' => now()->toDateString()]]);
    expect($invoice->total_amount)->toBe('170.0000')->and($invoice->lines->sole()->discount_amount)->toBe('30.0000');
    $this->putJson(route('admin.sales.sales-invoices.update', $invoice), ['discount_type' => 'percentage', 'discount_value' => '5',
        'lines' => [['invoice_line_public_id' => $lineId, 'quantity' => '2', 'discount_type' => 'fixed', 'discount_value' => '5', 'discount_amount' => '999']],
        'payment_schedules' => [['amount' => '185.25', 'due_date' => now()->toDateString()]]])->assertOk();
    $invoice->refresh();
    expect($invoice->total_amount)->toBe('185.2500')->and($invoice->header_discount_amount)->toBe('9.7500');
    $invoice = $service->amend($invoice, [['invoice_line_public_id' => $lineId, 'quantity' => '2']], [['amount' => '195', 'due_date' => now()->toDateString()]], discountInputs: ['discount_type' => null]);
    expect($invoice->total_amount)->toBe('195.0000')->and($invoice->header_discount_amount)->toBe('0.0000');
    $invoice = $service->amend($invoice, [['invoice_line_public_id' => $lineId, 'quantity' => '3']], [['amount' => '295', 'due_date' => now()->toDateString()]]);
    expect($invoice->lines->sole()->discount_value)->toBe('5.0000')->and($invoice->total_amount)->toBe('295.0000');
    $before = [$invoice->fresh()->getAttributes(), $invoice->lines->sole()->fresh()->getAttributes()];
    expect(fn () => $service->amend($invoice, [['invoice_line_public_id' => $lineId, 'quantity' => '3', 'discount_type' => 'percentage', 'discount_value' => '101']], [['amount' => '295', 'due_date' => now()->toDateString()]]))->toThrow(DomainException::class);
    expect([$invoice->fresh()->getAttributes(), $invoice->lines->sole()->fresh()->getAttributes()])->toBe($before);
});

test('typed direct invoices post native net revenue and reject posted amendments while safe repost preserves original journal amounts', function (): void {
    $f = directDiscountFixture('1000');
    $payload = directDiscountPayload($f, ['discount_type' => 'fixed', 'discount_value' => '50']);
    $payload['lines'][0] += ['discount_type' => 'percentage', 'discount_value' => '10'];
    $payload['lines'][0]['tax_amount'] = '126';
    $service = app(CustomerInvoiceService::class);
    $invoice = $service->post($service->createDirect($payload));
    $journal = $invoice->journalEntry;
    $originalLines = $journal->lines()->orderBy('id')->get()->map->getAttributes()->all();
    expect(bcadd((string) $journal->lines()->sum('debit_amount'), '0', 4))->toBe('976.0000')
        ->and(bcadd((string) $journal->lines()->sum('credit_amount'), '0', 4))->toBe('976.0000')
        ->and(bcadd((string) $journal->lines()->where('description', 'Service revenue')->sum('credit_amount'), '0', 4))->toBe('850.0000')
        ->and(bcadd((string) $journal->lines()->where('description', 'Output tax')->sum('credit_amount'), '0', 4))->toBe('126.0000');
    expect(fn () => $service->amend($invoice, [['invoice_line_public_id' => $invoice->lines->sole()->public_id, 'quantity' => '1']], [['amount' => '956', 'due_date' => now()->toDateString()]], discountInputs: ['discount_type' => 'fixed', 'discount_value' => '70']))->toThrow(DomainException::class);
    $invoice = $service->reopen($invoice, 'SYNTHETIC safe direct discount amendment');
    $invoice = $service->amend($invoice, [['invoice_line_public_id' => $invoice->lines->sole()->public_id, 'quantity' => '1']], [['amount' => '956', 'due_date' => now()->toDateString()]], discountInputs: ['discount_type' => 'fixed', 'discount_value' => '70']);
    $invoice = $service->post($invoice);
    expect($invoice->posting_revision)->toBe(1)->and($invoice->total_amount)->toBe('956.0000')
        ->and($journal->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalLines)
        ->and(bcadd((string) $invoice->journalEntry->lines()->sum('debit_amount'), '0', 4))->toBe('956.0000');
});

test('order invoice discount metadata conserves booked partial allocations without applying commercial inputs twice', function (): void {
    $f = directDiscountFixture();
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($f, ['payment_schedules' => [], 'discount_type' => 'fixed', 'discount_value' => '0.0002',
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '3', 'unit_price' => '22.54545000', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_amount' => '8.5222']]])));
    $line = $order->lines->sole();
    $service = app(CustomerInvoiceService::class);
    foreach (['23.1317', '23.1317', '23.1314'] as $total) {
        $invoice = $service->createFromOrder($order, [['sales_order_line_id' => $line->id, 'quantity' => '1']], [['amount' => $total, 'due_date' => now()->toDateString()]]);
        $invoice = $service->amend($invoice, [['invoice_line_public_id' => $invoice->lines->sole()->public_id, 'quantity' => '1']], [['amount' => $total, 'due_date' => now()->toDateString()]]);
        expect($invoice->total_amount)->toBe($total)->and($invoice->lines->sole()->discount_type)->toBe('percentage')
            ->and($invoice->lines->sole()->discount_value)->toBe('10.0000');
        expect(fn () => $service->amend($invoice, [['invoice_line_public_id' => $invoice->lines->sole()->public_id, 'quantity' => '1']], [['amount' => $total, 'due_date' => now()->toDateString()]], discountInputs: ['discount_type' => 'fixed', 'discount_value' => '1']))->toThrow(DomainException::class);
    }
    expect(bcadd((string) DB::table('customer_invoice_lines')->where('sales_order_line_id', $line->id)->sum('discount_amount'), '0', 4))->toBe($line->discount_amount)
        ->and(bcadd((string) DB::table('customer_invoice_lines')->where('sales_order_line_id', $line->id)->sum('header_discount_amount'), '0', 4))->toBe($line->header_discount_amount)
        ->and(bcadd((string) DB::table('customer_invoices')->where('sales_order_id', $order->id)->sum('total_amount'), '0', 4))->toBe($order->total_amount);
});

test('direct typed invoices render create edit print and exact CSV XLSX discount exports in both locales', function (string $locale): void {
    $this->withoutExceptionHandling();
    $f = directDiscountFixture('1000');
    $this->withSession(['locale' => $locale]);
    app()->setLocale($locale);
    $payload = directDiscountPayload($f, ['discount_type' => 'fixed', 'discount_value' => '50']);
    $payload['lines'][0] += ['discount_type' => 'percentage', 'discount_value' => '10'];
    $payload['lines'][0]['tax_amount'] = '126';
    $invoice = app(CustomerInvoiceService::class)->createDirect($payload);
    $this->get(route('admin.sales.sales-invoices.create', ['direct' => 1]))->assertOk()->assertSee('data-sales-discount-inputs', false)->assertSee(__('sales_ui.invoice_discount'));
    $this->get(route('admin.sales.sales-invoices.edit', $invoice))->assertOk()->assertSee('lines[0][discount_type]', false)->assertSee('data-invoice-booked-quantity', false);
    $this->get(route('admin.sales.sales-invoices.show', $invoice))->assertOk()->assertSee(__('sales_ui.invoice_discount'))->assertSee('976.00');
    $pdf = $this->get(route('admin.sales.sales-invoices.print', $invoice))->assertOk();
    expect(salesPdfText($pdf->getContent()))->toContain('976.00')->toContain('150.00')->toContain('50.00');
    file_put_contents('/tmp/mgypack-direct-discount-print-'.$locale.'-20261004.pdf', $pdf->getContent());
    $invoice->load(['customer', 'order', 'deliveries', 'currency', 'lines.product.category']);
    $report = ['reportType' => 'invoices', 'salesLedger' => collect([$invoice])];
    $sheets = (new SalesCycleReportExport($report))->sheets();
    expect(array_slice($sheets[0]->array()[0], 14, 3))->toBe([__('quotations.discount_types.fixed'), '50.0000', '50.0000'])
        ->and(array_slice($sheets[1]->array()[0], -3))->toBe([__('quotations.discount_types.percentage'), '10.0000', '50.0000']);
    $csv = Excel::raw(new SalesCycleReportExport($report, true), Maatwebsite\Excel\Excel::CSV);
    expect($csv)->toContain('50.0000')->toContain('10.0000')->toContain(__('quotations.discount_types.percentage'));
    $path = tempnam(sys_get_temp_dir(), 'invoice-discount-xlsx-');
    file_put_contents($path, Excel::raw(new SalesCycleReportExport($report), Maatwebsite\Excel\Excel::XLSX));
    try {
        $book = IOFactory::load($path);
        expect($book->getSheet(0)->getCell('P2')->getValue())->toBe('50.0000')->and($book->getSheet(1)->getCell('M2')->getValue())->toBe('10.0000');
    } finally {
        @unlink($path);
    }
})->with(['en', 'ar']);

test('sales request direct invoice HTTP preserves source quantity and explicitly captures discounts VAT and net basis withholding', function (string $locale): void {
    $f = directDiscountFixture('1000');
    foreach (['sales_requests.view', 'sales_requests.create', 'sales_requests.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $requests = app(SalesRequestService::class);
    $request = $requests->save(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '2']]]);
    $requests->transition($request, 'submitted');
    $requests->transition($request->fresh(), 'approved');
    $sourceLine = $request->fresh()->lines->sole();
    $this->withSession(['locale' => $locale]);
    app()->setLocale($locale);
    $this->get(route('admin.sales.sales-invoices.create', ['direct' => 1, 'source_request_doc_num' => $request->doc_num]))
        ->assertOk()->assertSee('source_request_line_public_id', false)->assertSee('name="withholding_basis"', false);
    $payload = directDiscountPayload($f, ['source_request_doc_num' => $request->doc_num, 'discount_type' => 'fixed', 'discount_value' => '50',
        'withholding_rate' => '1', 'withholding_basis' => SalesWithholdingService::EtaNetExcludingTax]);
    $payload['lines'][0] += ['source_request_line_public_id' => $sourceLine->public_id, 'discount_type' => 'percentage', 'discount_value' => '10'];
    $payload['lines'][0]['tax_rate'] = '14';
    $this->postJson(route('admin.sales.sales-invoices.store'), $payload)->assertCreated();
    $invoice = CustomerInvoice::query()->where('source_type', 'sales_request')->where('source_id', $request->id)->sole();
    expect($invoice->subtotal_amount)->toBe('1000.0000')->and($invoice->discount_amount)->toBe('150.0000')
        ->and($invoice->tax_amount)->toBe('119.0000')->and($invoice->total_amount)->toBe('969.0000')
        ->and($invoice->withholding_amount)->toBe('8.5000')->and($invoice->net_payable_amount)->toBe('960.5000')
        ->and($invoice->remaining_amount)->toBe('969.0000')->and($sourceLine->fresh()->converted_quantity)->toBe('1.00000000')
        ->and($request->fresh()->status)->toBe('partially_converted')->and($invoice->lines->sole()->source_snapshot['sales_request_line_public_id'])->toBe($sourceLine->public_id);
    $this->get(route('admin.sales.sales-invoices.edit', $invoice))->assertOk()->assertSee('eta_t4_net_excluding_tax', false);
})->with(['ar', 'en']);

test('request to order to partial invoice HTTP conserves commercial discount VAT and source quantities with explicit withholding previews', function (): void {
    $f = directDiscountFixture('1000');
    foreach (['sales_requests.view', 'sales_requests.create', 'sales_requests.approve', 'sales_orders.create', 'sales_orders.view', 'sales_orders.approve', 'sales_orders.invoice'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $requests = app(SalesRequestService::class);
    $request = $requests->save(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'customer_id' => $f['customer']->id,
        'currency_id' => $f['currency']->id, 'request_date' => now()->toDateString(), 'exchange_rate' => '1',
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '3']]]);
    $requests->transition($request, 'submitted');
    $requests->transition($request->fresh(), 'approved');
    $source = $request->fresh()->lines->sole();
    $this->postJson(route('admin.sales.sales-orders.store'), ['source_request_doc_num' => $request->doc_num,
        'customer_doc_num' => $f['customer']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
        'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addDay()->toDateString(),
        'discount_type' => 'fixed', 'discount_value' => '50', 'withholding_rate' => '1', 'withholding_basis' => 'eta_t4_net_excluding_tax',
        'lines' => [['source_request_line_public_id' => $source->public_id, 'product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
            'quantity' => '3', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14']]])->assertCreated();
    $order = SalesOrder::query()->where('sales_request_id', $request->id)->sole();
    $order = app(SalesOrderService::class)->approve($order);
    expect($order->withholding_basis)->toBe('eta_t4_net_excluding_tax')->and($order->withholding_amount)->toBe('26.5000')
        ->and($order->total_amount)->toBe('3021.0000')->and($source->fresh()->converted_quantity)->toBe('3.00000000');
    for ($index = 0; $index < 3; $index++) {
        $this->postJson(route('admin.sales.sales-orders.invoices.store', $order), ['invoice_date' => now()->toDateString(),
            'lines' => [['sales_order_line_public_id' => $order->lines->sole()->public_id, 'quantity' => '1']],
            'payment_schedules' => [['amount' => '1007', 'due_date' => now()->toDateString()]]])->assertCreated();
    }
    $invoices = $order->invoices()->orderBy('id')->get();
    expect($invoices)->toHaveCount(3)->and(bcadd((string) $invoices->sum('discount_amount'), '0', 4))->toBe('350.0000')
        ->and(bcadd((string) $invoices->sum('tax_amount'), '0', 4))->toBe('371.0000')
        ->and(bcadd((string) $invoices->sum('total_amount'), '0', 4))->toBe('3021.0000')
        ->and(bcadd((string) $invoices->sum('withholding_amount'), '0', 4))->toBe('26.4999')
        ->and($order->lines->sole()->fresh()->remainingInvoiceQuantity())->toBe('0.00000000');
    foreach ($invoices as $invoice) {
        expect($invoice->withholding_basis)->toBe('eta_t4_net_excluding_tax')->and($invoice->withholding_rate)->toBe('1.0000')
            ->and($invoice->remaining_amount)->toBe('1007.0000')->and($invoice->actual_withholding_amount)->toBe('0.0000')
            ->and($invoice->lines->sole()->discount_type)->toBe('percentage')->and($invoice->lines->sole()->discount_value)->toBe('10.0000');
    }
});
