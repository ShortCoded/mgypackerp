<?php

use Modules\HR\Models\HrEmployee;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\CustomerTermsService;
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

test('a customer request stops on unpriced items then preserves the resolved commercial snapshot through quotation and order', function (): void {
    $fixture = salesCycleFixture();
    $permissions = [
        'sales_requests.view', 'sales_requests.create', 'sales_requests.edit', 'sales_requests.approve', 'sales_requests.convert',
        'quotations.create', 'sales_orders.create', 'reports.sales.sales_orders.view',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $customer = app(CustomerTermsService::class)->update($fixture['customer'], [
        'quotation_terms' => '<p>Business acceptance terms</p><script>alert(1)</script>',
        'quotation_payment_terms' => '<p>Pay within 30 days</p>',
        'quotation_execution_terms' => '<p>Execution after approval</p>',
        'quotation_warranty_terms' => '<p>One year warranty</p>',
        'quotation_delivery_terms' => '<p>Delivery at customer site</p>',
        'quotation_technical_notes' => '<p>Approved specification</p>',
    ]);
    expect($customer->quotation_terms)->toContain('Business acceptance terms')->not->toContain('<script');

    $generalList = createSalesPriceList($fixture, null, [[
        'product' => $fixture['finished'],
        'price' => '15',
        'discount_type' => 'percentage',
        'discount_value' => '10',
    ]]);
    $response = $this->postJson(route('admin.sales.customer-requests.store'), [
        'request_type' => 'customer',
        'request_date' => now()->toDateString(),
        'required_delivery_date' => now()->addWeek()->toDateString(),
        'customer_doc_num' => $customer->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'lines' => [
            ['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 2, 'unit_price' => 999],
            ['product_doc_num' => $fixture['service']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 1, 'unit_price' => 999],
        ],
    ])->assertOk();
    $request = SalesRequest::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    expect($request->lines)->each(fn ($line) => $line->unit_price->toBeNull());

    $this->postJson(route('admin.sales.customer-requests.transition', $request), ['status' => 'submitted'])->assertOk();
    $this->postJson(route('admin.sales.customer-requests.transition', $request), ['status' => 'approved'])->assertOk();
    $selection = $request->lines->map(fn ($line): array => ['public_id' => $line->public_id, 'quantity' => $line->quantity])->all();
    $this->postJson(route('admin.sales.customer-requests.convert', $request), [
        'target' => 'quotation',
        'lines' => $selection,
    ])->assertUnprocessable()->assertJsonPath('message', fn (string $message): bool => str_contains($message, $fixture['service']->name));

    expect(Quotation::query()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe('approved')
        ->and($request->lines()->sum('converted_quantity'))->toEqual(0);
    $this->get(route('admin.reports.sales.sales-orders.index', ['report' => 'pricing']))
        ->assertOk()
        ->assertSee($fixture['service']->name)
        ->assertSee($customer->name);

    $customerList = createSalesPriceList($fixture, $customer->id, [[
        'product' => $fixture['service'],
        'price' => '80',
        'discount_type' => 'fixed',
        'discount_value' => '5',
    ]]);
    $conversion = $this->postJson(route('admin.sales.customer-requests.convert', $request), [
        'target' => 'quotation',
        'lines' => $selection,
    ])->assertCreated();
    $quotation = Quotation::query()->where('doc_num', $conversion->json('data.doc_num'))->firstOrFail()->load('currentRevision.lines');
    $quotationLines = $quotation->currentRevision->lines->keyBy('product_id');
    expect($quotationLines[$fixture['finished']->id]->unit_price)->toBe('15.0000')
        ->and($quotationLines[$fixture['finished']->id]->price_list_line_id)->toBe($generalList->lines()->sole()->id)
        ->and($quotationLines[$fixture['finished']->id]->allowed_discount_value)->toBe('10.0000')
        ->and($quotationLines[$fixture['service']->id]->unit_price)->toBe('80.0000')
        ->and($quotationLines[$fixture['service']->id]->price_list_line_id)->toBe($customerList->lines()->sole()->id)
        ->and($quotation->currentRevision->terms_snapshot)->toContain('Business acceptance terms');

    $generalList->lines()->update(['unit_price' => 999]);
    $customerList->lines()->update(['unit_price' => 999]);
    $quotations = app(QuotationService::class);
    $quotation = $quotations->accept($quotations->markSent($quotation));
    $order = app(SalesOrderService::class)->createFromQuotation($quotation, [
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
    ]);
    $orderLines = $order->lines->keyBy('product_id');
    expect($orderLines[$fixture['finished']->id]->unit_price)->toBe('15.0000')
        ->and($orderLines[$fixture['service']->id]->unit_price)->toBe('80.0000')
        ->and(data_get($order->terms_snapshot, 'content'))->toContain('Business acceptance terms')
        ->and($request->fresh()->status)->toBe('converted');

    $tamperedLines = $order->lines->map(fn ($line): array => [
        'product_id' => $line->product_id,
        'unit_id' => $line->unit_id,
        'quantity' => $line->quantity,
        'unit_price' => 1,
        'discount_amount' => 999,
        'tax_amount' => 999,
    ])->all();
    $unchanged = app(SalesOrderService::class)->update($order, [
        'company_id' => $order->company_id,
        'branch_id' => $order->branch_id,
        'customer_id' => $order->customer_id,
        'currency_id' => $order->currency_id,
        'order_date' => $order->order_date->toDateString(),
        'expected_delivery_date' => $order->expected_delivery_date->toDateString(),
        'exchange_rate' => $order->exchange_rate,
        'lines' => $tamperedLines,
        'payment_schedules' => [],
    ]);
    expect($unchanged->lines->keyBy('product_id')[$fixture['finished']->id]->unit_price)->toBe('15.0000')
        ->and($unchanged->lines->keyBy('product_id')[$fixture['service']->id]->unit_price)->toBe('80.0000');
});

test('cash collection is balanced and exposes its responsible employee and source in the customer statement', function (): void {
    $fixture = salesCycleFixture();
    foreach (['reports.customer_statement.view', 'reports.customer_statement.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    request()->setLaravelSession(app('session.store'));
    $employee = HrEmployee::query()->create([
        'doc_number' => 98901,
        'doc_num' => 'EMP-COLLECTOR',
        'company_id' => $fixture['company']->id,
        'branch_id' => $fixture['branch']->id,
        'full_name' => 'Omar Collector',
        'name' => 'Omar Collector',
        'status' => 'active',
    ]);
    $invoice = salesPostedServiceInvoice($fixture, '125');
    $receipt = app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'customer_id' => $fixture['customer']->id,
        'received_by_employee_id' => $employee->id,
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->id,
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $fixture['cashbox']->id,
        'bank_account_id' => null,
        'reference_no' => 'COLLECTION-ACCEPTANCE-1',
        'amount' => '125',
        'receipt_type' => 'collection',
    ], [[
        'customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->sole()->id,
        'amount' => '125',
    ]]);

    $journal = $receipt->journalEntry()->with('lines')->firstOrFail();
    expect($invoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and($journal->lines->sum('debit_amount'))->toEqual(125)
        ->and($journal->lines->sum('credit_amount'))->toEqual(125)
        ->and($journal->lines->pluck('employee_id')->filter()->unique()->all())->toBe([$employee->id]);

    $filters = [
        'run' => 1,
        'customer_doc_num' => $fixture['customer']->doc_num,
        'from_date' => now()->subDay()->toDateString(),
        'to_date' => now()->addDay()->toDateString(),
    ];
    $this->get(route('admin.accounting.reports.customer-statement', $filters))
        ->assertOk()
        ->assertSee(__('ledger_reports.columns.collector'))
        ->assertSee(__('ledger_reports.columns.collection_source'))
        ->assertSee($employee->full_name)
        ->assertSee($fixture['cashbox']->name)
        ->assertSee('COLLECTION-ACCEPTANCE-1');
    $this->get(route('admin.accounting.reports.customer-statement.export.csv', $filters))->assertOk()->assertDownload();
    $this->get(route('admin.accounting.reports.customer-statement.export.excel', $filters))->assertOk()->assertDownload();
    $pdf = $this->get(route('admin.accounting.reports.customer-statement.export.pdf', $filters))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(salesPdfText($pdf->getContent()))->toContain('Omar Collector', 'COLLECTION-ACCEPTANCE-1');
});

test('every sales report perspective renders against the same business data including pricing gaps', function (): void {
    $fixture = salesCycleFixture();
    foreach (['reports.sales.sales_orders.view', 'reports.sales.sales_orders.print', 'reports.sales.sales_orders.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '22']]);
    salesPostedServiceInvoice($fixture, '125');
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    foreach (['financial', 'period', 'customers', 'products', 'invoices', 'receivables', 'collections', 'returns', 'quotations', 'fulfillment', 'pricing', 'operational'] as $report) {
        $this->get(route('admin.reports.sales.sales-orders.index', ['report' => $report]))->assertOk();
    }

    $this->get(route('admin.reports.sales.sales-orders.index', ['report' => 'pricing']))
        ->assertOk()
        ->assertSee($fixture['service']->name)
        ->assertSee($fixture['customer']->name);
    $this->get(route('admin.reports.sales.sales-orders.print', ['report' => 'pricing']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.reports.sales.sales-orders.export', ['report' => 'pricing']))
        ->assertOk()
        ->assertDownload();
});
