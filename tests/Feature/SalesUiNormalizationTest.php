<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Services\MenuService;
use Modules\HR\Models\HrEmployee;
use Modules\Sales\Exports\SalesCycleReportExport;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Modules\Sales\Services\SalesReturnService;
use Modules\Sales\Services\SalesSelect2Service;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

test('sales report workbook headings and sheet titles follow the active locale', function (): void {
    app()->setLocale('ar');

    $sheets = (new SalesCycleReportExport([
        'reportType' => 'financial',
        'financialSummary' => [],
        'salesByCustomer' => collect(),
        'invoiceOutstanding' => collect(),
        'aging' => collect(),
    ]))->sheets();

    expect($sheets)->toHaveCount(4)
        ->and($sheets[0]->title())->toBe('الملخص المالي')
        ->and($sheets[0]->headings())->toBe(['المؤشر', 'القيمة'])
        ->and($sheets[1]->title())->toBe('المبيعات حسب العميل')
        ->and($sheets[1]->headings())->toContain('العميل', 'المبيعات', 'المستحق');
});

function salesUiFixture(): array
{
    $fixture = salesCycleFixture();
    foreach (['sales_orders.view', 'sales_orders.create', 'sales_orders.edit', 'sales_orders.invoice', 'sales_orders.print', 'sales_orders.view_prices', 'sales_requests.view', 'sales_requests.create', 'customer_invoices.create', 'quotations.view', 'quotations.create', 'quotations.edit', 'quotations.print'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    request()->setLaravelSession(app('session.store'));
    session(salesCycleSession($fixture));

    return $fixture;
}

function salesUiEmployee(array $fixture, int $number = 991): HrEmployee
{
    return HrEmployee::query()->create(['doc_number' => $number, 'doc_num' => 'EMP-'.$number,
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'full_name' => 'Sales Employee '.$number, 'name' => 'Sales Employee '.$number, 'status' => 'active']);
}

function salesUiQuote(array $fixture): Quotation
{
    return app(QuotationService::class)->create(['branch_id' => $fixture['branch']->id,
        'customer_doc_num' => $fixture['customer']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(),
        'exchange_rate' => 1, 'lines' => [['product_doc_num' => $fixture['finished']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 2, 'unit_price' => 10]]])['record'];
}

test('sales grids paginate and search without embedding the master dataset', function () {
    $f = salesUiFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    for ($index = 0; $index < 3; $index++) {
        app(SalesOrderService::class)->create(salesCycleOrderPayload($f));
    }
    $this->get(route('admin.sales.sales-orders.create'))->assertOk()->assertDontSee($f['finished']->name)->assertSee('js-select2-ajax');
    $response = $this->getJson(route('admin.sales.sales-orders.index', ['draw' => 1, 'start' => 0, 'length' => 2]));
    $this->assertNull($response->json('error'), (string) $response->json('error'));
    $response->assertOk()->assertJsonMissingPath('error')->assertJsonCount(2, 'data')->assertJsonPath('recordsTotal', 3);
    $this->getJson(route('admin.sales.sales-orders.index', ['draw' => 2, 'search' => ['value' => 'absent-document']]))->assertJsonPath('recordsFiltered', 0);
});

test('sales employee pickers paginate and keep inactive history without accepting inactive new selections', function () {
    $f = salesUiFixture();
    $employee = salesUiEmployee($f);
    salesUiEmployee($f, 992);
    $service = app(SalesSelect2Service::class);
    expect($service->employeeId($f['company']->id, $f['branch']->id, $employee->doc_num))->toBe($employee->id);
    $employee->update(['status' => 'inactive']);
    expect(fn () => $service->employeeId($f['company']->id, $f['branch']->id, $employee->doc_num))->toThrow(ValidationException::class);
    expect($service->employeeId($f['company']->id, $f['branch']->id, $employee->doc_num, $employee->id))->toBe($employee->id);
    $this->actingAs($f['user'])->withSession(salesCycleSession($f))->getJson(route('admin.sales.select2.employees', ['per_page' => 1]))
        ->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', 'EMP-992');
});

test('sales business employee is inherited and legacy user references remain separate', function () {
    $f = salesUiFixture();
    $employee = salesUiEmployee($f);
    $quote = salesUiQuote($f);
    $quote->forceFill(['business_employee_id' => $employee->id, 'sales_person_id' => $f['user']->id])->save();
    $service = app(QuotationService::class);
    $quote = $service->accept($service->markSent($quote));
    $order = app(SalesOrderService::class)->createFromQuotation($quote, ['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id]);
    expect($order->salesEmployee->id)->toBe($employee->id)
        ->and($quote->refresh()->legacySalesUser->id)->toBe($f['user']->id)
        ->and($quote->salesPerson->id)->toBe($employee->id)
        ->and($quote->created_by)->toBe($f['user']->id);
});

test('quotation dispatch records audit while cancellation preserves history and deletion is draft only', function () {
    $f = salesUiFixture();
    $quote = salesUiQuote($f);
    $service = app(QuotationService::class);
    expect($quote->canDeleteDraft())->toBeTrue();
    $quote = $service->markSent($quote);
    expect($quote->sent_by)->toBe($f['user']->id)->and($quote->sent_at)->not->toBeNull()->and($quote->canDeleteDraft())->toBeFalse();
    expect(fn () => $service->delete($quote))->toThrow(DomainException::class);
    $quote = $service->cancel($quote);
    expect($quote->status)->toBe('cancelled')->and($quote->trashed())->toBeFalse()->and($quote->canCancel())->toBeFalse();
    expect(fn () => $service->accept($quote))->toThrow(DomainException::class);
    $draft = salesUiQuote($f);
    $service->delete($draft);
    expect($draft->refresh()->trashed())->toBeTrue();
});

test('customer quotation terms are tenant scoped sanitized and applied by default', function (): void {
    $fixture = salesUiFixture();
    foreach (['customers.view', 'customers.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $session = salesCycleSession($fixture);
    $this->actingAs($fixture['user'])->withSession($session)
        ->put(route('admin.sales.customer-terms.update', $fixture['customer']), [
            'quotation_terms' => '<p onclick="alert(1)">Customer terms</p>',
            'quotation_payment_terms' => '<p>30 days</p>',
            'quotation_execution_terms' => '<p>Two phases</p>',
            'quotation_warranty_terms' => '<p>One year</p>',
            'quotation_delivery_terms' => '<p>Customer warehouse</p>',
            'quotation_technical_notes' => '<p>Approved specification</p>',
        ])
        ->assertRedirect(route('admin.sales.customer-terms.edit', $fixture['customer']));

    $customer = $fixture['customer']->refresh();
    expect($customer->quotation_terms)->toContain('Customer terms')->not->toContain('onclick');

    $this->getJson(route('admin.sales.select2.customer-quotation-terms', [
        'customer_doc_num' => $customer->doc_num,
    ]))
        ->assertOk()
        ->assertJsonPath('data.payment_terms', '<p>30 days</p>')
        ->assertJsonPath('data.warranty_terms', '<p>One year</p>');

    $quotation = salesUiQuote($fixture)->refresh();
    expect($quotation->currentRevision->terms_snapshot)->toContain('Customer terms')
        ->and($quotation->currentRevision->payment_terms_snapshot)->toBe('<p>30 days</p>')
        ->and($quotation->currentRevision->technical_notes_snapshot)->toBe('<p>Approved specification</p>');
});

test('quotation customer PDF hides empty rich terms and system audit and separates number from revision', function () {
    $f = salesUiFixture();
    $quote = salesUiQuote($f);
    $quote->forceFill(['internal_notes' => 'PRIVATE INTERNAL AUDIT'])->save();
    $quote->currentRevision->update(['warranty_terms_snapshot' => '<p><br></p>', 'payment_terms_snapshot' => '<p>Payment within 30 days</p>']);
    $f['user']->update(['locale' => 'en']);
    $response = $this->actingAs($f['user'])->withSession(salesCycleSession($f))->get(route('admin.sales.quotations.print', $quote))->assertOk();
    $text = salesPdfText($response->getContent());
    expect($text)->toContain($quote->doc_num, 'R01', 'Payment within 30 days')
        ->not->toContain($quote->doc_num.'-R01', 'PRIVATE INTERNAL AUDIT', 'Generated by', 'Prepared by', 'Warranty Terms');
    file_put_contents('/tmp/sales-ui-normalized-quotation.pdf', $response->getContent());
    $f['user']->update(['locale' => 'ar']);
    $arabic = $this->get(route('admin.sales.quotations.print', $quote))->assertOk();
    file_put_contents('/tmp/sales-ui-normalized-quotation-ar.pdf', $arabic->getContent());
});

test('sales order displays a concise quotation revision and does not repeat the product name as its description', function () {
    $f = salesUiFixture();
    $quotationService = app(QuotationService::class);
    $quote = $quotationService->accept($quotationService->markSent(salesUiQuote($f)));
    $order = app(SalesOrderService::class)->createFromQuotation($quote, [
        'company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id,
    ]);
    $order->lines()->update(['description' => $f['finished']->name]);
    $session = salesCycleSession($f);

    $this->actingAs($f['user'])->withSession($session)
        ->get(route('admin.sales.sales-orders.show', $order))
        ->assertOk()
        ->assertSee($quote->doc_num.' · R01')
        ->assertDontSee($quote->doc_num.' / '.$quote->currentRevision->revision_code)
        ->assertDontSee($f['finished']->name.' / '.$f['finished']->name);

    $pdf = $this->get(route('admin.sales.sales-orders.print', $order))->assertOk();
    $orderPrintText = salesPdfText($pdf->getContent());
    expect(substr_count($orderPrintText, $order->doc_num))->toBe(1)
        ->and($orderPrintText)
        ->toContain($quote->doc_num, 'R01')
        ->not->toContain($quote->currentRevision->revision_code, $f['finished']->name.' / '.$f['finished']->name, __('Classification'));
});

test('sales request customer type requires customer and internal type uses separate business employee', function () {
    $f = salesUiFixture();
    Permission::findOrCreate('sales_requests.print', 'web');
    $f['user']->givePermissionTo('sales_requests.print');
    $employee = salesUiEmployee($f);
    $payload = ['request_type' => 'customer', 'request_date' => now()->toDateString(), 'priority' => 'normal', 'exchange_rate' => 1,
        'currency_doc_num' => $f['currency']->doc_num, 'sales_employee_doc_num' => $employee->doc_num,
        'lines' => [['product_doc_num' => $f['finished']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => 2]]];
    $this->actingAs($f['user'])->withSession(salesCycleSession($f))->postJson(route('admin.sales.customer-requests.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('customer_doc_num');
    $this->postJson(route('admin.sales.customer-requests.store'), [...$payload, 'request_type' => 'internal'])->assertSuccessful();
    $request = SalesRequest::query()->latest('id')->firstOrFail();
    expect($request->customer_id)->toBeNull()
        ->and($request->sales_employee_id)->toBeNull()
        ->and($request->business_employee_id)->toBe($employee->id)
        ->and($request->lines->sole()->unit_price)->toBeNull();
    $requestForm = $this->get(route('admin.sales.customer-requests.create'))->assertOk()->assertDontSee($f['finished']->name);
    $requestForm->assertDontSee('name="branch_store_uuid"', false)
        ->assertDontSee('name="priority"', false)
        ->assertDontSee('name="customer_reference"', false)
        ->assertDontSee('[description]', false)
        ->assertDontSee('[specifications][packaging]', false)
        ->assertDontSee('[specifications][units_per_package]', false)
        ->assertSee('data-document-summary', false)
        ->assertDontSee('data-sales-summary-subtotal', false)
        ->assertDontSee('data-sales-summary-total', false)
        ->assertSee('data-sales-summary-quantity', false)
        ->assertDontSee('data-sales-summary-discount', false)
        ->assertDontSee('data-sales-summary-tax', false)
        ->assertDontSee(__('sales_ui.optional_unit_price'))
        ->assertDontSee('[unit_price]', false)
        ->assertSee('data-shortcut-action="line.add"', false);
    expect(substr_count($requestForm->getContent(), 'data-sales-add-line'))->toBe(2);

    $printText = salesPdfText($this->get(route('admin.sales.customer-requests.print', $request))->assertOk()->getContent());
    expect(substr_count($printText, __('Sales Request')))->toBe(1)
        ->and(substr_count($printText, $request->doc_num))->toBe(1)
        ->and($printText)->not->toContain(__('Unit price'), __('Classification'));
});

test('sales navigation is one ordered journey with canonical statement and collection links', function () {
    $f = salesUiFixture();
    foreach (['customers.view', 'price_lists.view', 'sales_deliveries.view', 'customer_invoices.view', 'sales_returns.view', 'customer_receipts.view', 'reports.customer_statement.view', 'reports.sales.sales_orders.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $menu = app(MenuService::class)->getMenu($f['user']);
    $sales = collect($menu)->firstWhere('label', 'sales');
    expect(collect($sales['children'])->pluck('label')->all())->toBe(['customers', 'customer_terms', 'price_lists', 'sales_requests', 'quotations', 'sales_orders', 'sales_invoices', 'deliveries', 'customer_collections', 'sales_returns', 'sales_cycle_reports']);
    $reportLabels = collect($sales['children'])->firstWhere('label', 'sales_cycle_reports')['children'] ?? [];
    expect(collect($reportLabels)->pluck('label')->all())->toBe([
        'customer_statement',
        'sales_report_invoices',
        'sales_report_quotations',
        'sales_report_fulfillment',
        'sales_report_pricing',
        'sales_report_operational',
    ]);
    $accounting = collect($menu)->firstWhere('label', 'accounting_costing');
    $financialAnalysisLabels = collect($accounting['children'])->firstWhere('label', 'financial_analysis_reports')['children'] ?? [];
    expect(collect($financialAnalysisLabels)->pluck('label')->all())->toBe([
        'sales_report_financial',
        'sales_report_period',
        'sales_report_customer',
        'sales_report_product',
        'sales_report_receivables',
        'sales_report_collections',
        'sales_report_returns',
    ]);
});

test('sales order and quotation omit production packing fields and expose a second add line control', function () {
    $f = salesUiFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));

    $order = $this->get(route('admin.sales.sales-orders.create'))->assertOk()
        ->assertDontSee('name="customer_reference"', false)
        ->assertDontSee('[specifications][packaging]', false)
        ->assertDontSee('[specifications][customer_specification]', false)
        ->assertDontSee('[warehouse_notes]', false)
        ->assertDontSee('[production_notes]', false)
        ->assertDontSee('[requested_date]', false)
        ->assertSee('data-document-summary', false)
        ->assertSee('data-sales-summary-subtotal', false)
        ->assertSee('data-sales-summary-discount', false)
        ->assertSee('data-sales-summary-taxable', false)
        ->assertSee('data-sales-summary-tax', false)
        ->assertSee('data-sales-summary-total', false);
    expect(substr_count($order->getContent(), 'data-sales-add-line'))->toBe(2);

    $quotation = $this->get(route('admin.sales.quotations.create'))->assertOk()
        ->assertDontSee('[specifications][packaging]', false)
        ->assertDontSee('[specifications][customer_specification]', false)
        ->assertDontSee('[warehouse_notes]', false)
        ->assertDontSee('[production_notes]', false)
        ->assertSee('data-quotation-project-only', false)
        ->assertSee('data-main-currency-doc-num="'.$f['currency']->doc_num.'"', false)
        ->assertSee(__('sales_ui.requested_date_help'));
    expect(substr_count($quotation->getContent(), 'js-quotation-add-line'))->toBeGreaterThanOrEqual(2);
});

test('sales invoice creation starts from an invoiceable sales order without duplicating posting logic', function () {
    $f = salesUiFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));

    $this->get(route('admin.sales.sales-invoices.create'))->assertOk()
        ->assertSee('js-select2-ajax')
        ->assertSee(route('admin.sales.select2.invoiceable-orders'), false)
        ->assertSee(route('admin.sales.select2.convertible-requests'), false)
        ->assertSee(route('admin.sales.sales-invoices.create', ['direct' => 1]), false)
        ->assertSee(__('sales_ui.invoice_source_help'));

    $this->get(route('admin.sales.sales-invoices.create', ['direct' => 1]))->assertOk()
        ->assertSee(route('admin.sales.select2.customers'), false)
        ->assertSee(route('admin.select2.currencies'), false)
        ->assertSee(route('admin.sales.select2.quotation-products'), false)
        ->assertDontSee('[requested_date]', false)
        ->assertDontSee('[description]', false)
        ->assertSee('data-document-summary', false)
        ->assertSee('data-sales-summary-subtotal', false)
        ->assertSee('data-sales-summary-discount', false)
        ->assertSee('data-sales-summary-taxable', false)
        ->assertSee('data-sales-summary-tax', false);
});

test('approved sales request can prefill quotation order and invoice forms', function () {
    $f = salesUiFixture();
    $request = app(SalesRequestService::class)->save([
        'company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id,
        'currency_id' => $f['currency']->id,
        'request_date' => now()->toDateString(),
        'required_delivery_date' => now()->addWeek()->toDateString(),
        'exchange_rate' => '1',
        'lines' => [[
            'product_id' => $f['finished']->id,
            'unit_id' => $f['unit']->id,
            'quantity' => '3',
            'unit_price' => null,
        ]],
    ]);
    $request = app(SalesRequestService::class)->transition($request, 'submitted');
    $request = app(SalesRequestService::class)->transition($request, 'approved');
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));

    $show = $this->get(route('admin.sales.customer-requests.show', $request))->assertOk();
    foreach ([
        route('admin.sales.quotations.create', ['source_request_doc_num' => $request->doc_num]),
        route('admin.sales.sales-orders.create', ['source_request_doc_num' => $request->doc_num]),
        route('admin.sales.sales-invoices.create', ['source_request_doc_num' => $request->doc_num]),
    ] as $url) {
        $show->assertSee($url, false);
    }

    $quotation = $this->get(route('admin.sales.quotations.create', ['source_request_doc_num' => $request->doc_num]))->assertOk();
    $quotation->assertSee($request->doc_num)
        ->assertSee($request->lines->sole()->public_id, false)
        ->assertSee($f['finished']->doc_num);
    $this->get(route('admin.sales.sales-orders.create', ['source_request_doc_num' => $request->doc_num]))->assertOk()
        ->assertSee($request->lines->sole()->public_id, false)
        ->assertSee($f['finished']->doc_num);
    $this->get(route('admin.sales.sales-invoices.create', ['source_request_doc_num' => $request->doc_num]))->assertOk()
        ->assertSee($request->lines->sole()->public_id, false)
        ->assertSee($f['finished']->doc_num);
});

test('direct sales invoice can be posted delivered and returned without a sales order', function () {
    $f = salesUiFixture();
    createSalesPriceList($f, null, [['product' => $f['finished'], 'price' => '50']]);
    foreach (['customer_invoices.view', 'customer_invoices.post', 'sales_deliveries.create', 'sales_deliveries.view', 'sales_returns.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));

    $invoice = app(CustomerInvoiceService::class)->createDirect([
        'company_id' => $f['company']->id,
        'financial_period_id' => $f['period']->id,
        'branch_id' => $f['branch']->id,
        'customer_doc_num' => $f['customer']->doc_num,
        'currency_doc_num' => $f['currency']->doc_num,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addWeek()->toDateString(),
        'exchange_rate' => '1',
        'lines' => [[
            'product_doc_num' => $f['finished']->doc_num,
            'unit_doc_num' => $f['unit']->doc_num,
            'quantity' => '2',
            'unit_price' => '50',
            'discount_amount' => '0',
            'tax_amount' => '0',
        ]],
    ]);
    expect($invoice->sales_order_id)->toBeNull()
        ->and($invoice->source_type)->toBe('direct')
        ->and($invoice->total_amount)->toBe('100.0000');

    $invoice = app(CustomerInvoiceService::class)->post($invoice);
    $this->getJson(route('admin.sales.select2.deliverable-invoices', ['q' => $invoice->doc_num]))
        ->assertOk()
        ->assertJsonPath('results.0.id', $invoice->doc_num);
    $delivery = app(SalesFulfillmentService::class)->deliverInvoice($invoice, [[
        'customer_invoice_line_id' => $invoice->lines->sole()->id,
        'quantity' => '2',
    ]], [
        'branch_store_uuid' => $f['store']->public_uuid,
        'document_date' => now()->toDateString(),
        'recipient_name' => 'Direct Invoice Recipient',
    ]);
    expect($delivery->source_document_type)->toBe(CustomerInvoice::class)
        ->and($delivery->source_document_id)->toBe($invoice->id)
        ->and($delivery->lines->sole()->source_line_type)->toBe(CustomerInvoiceLine::class);

    $return = app(SalesReturnService::class)->create(
        $invoice->fresh(),
        'other',
        'Direct invoice return',
        [['customer_invoice_line_id' => $invoice->lines->sole()->id, 'quantity' => '1']],
        $f['store']->id,
    );
    expect($return->sales_order_id)->toBeNull()
        ->and($return->delivery_document_id)->toBe($delivery->id)
        ->and($return->lines->sole()->quantity)->toBe('1.00000000');
});

test('delivery creation starts from a posted deliverable invoice and uses delivery permission', function () {
    $f = salesUiFixture();
    foreach (['sales_deliveries.create', 'sales_deliveries.view', 'sales_deliveries.print', 'customer_invoices.view', 'customer_invoices.reopen', 'sales_orders.view', 'sales_orders.cancel', 'sales_orders.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($f, [
        'lines' => [
            [
                'product_id' => $f['finished']->getKey(),
                'unit_id' => $f['unit']->getKey(),
                'description' => 'Deliverable invoiced product',
                'quantity' => '1',
                'unit_price' => '10',
            ],
            [
                'product_id' => $f['service']->getKey(),
                'unit_id' => $f['unit']->getKey(),
                'description' => 'Non-deliverable service',
                'quantity' => '1',
                'unit_price' => '10',
            ],
        ],
        'payment_schedules' => [[
            'title' => 'Due',
            'amount' => '20',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $goodsOrderLine = $order->lines->firstWhere('product_id', $f['finished']->getKey());
    $serviceOrderLine = $order->lines->firstWhere('product_id', $f['service']->getKey());
    $invoiceService = app(CustomerInvoiceService::class);
    $invoice = $invoiceService->createFromOrder($order, [
        ['sales_order_line_id' => $goodsOrderLine->getKey(), 'quantity' => '1'],
        ['sales_order_line_id' => $serviceOrderLine->getKey(), 'quantity' => '1'],
    ], [[
        'due_date' => now()->toDateString(),
        'amount' => '20',
    ]]);

    $this->get(route('admin.sales.sales-invoices.show', $invoice))->assertOk()
        ->assertDontSee('id="sales-invoice-delivery"', false);
    $invoice = $invoiceService->post($invoice);

    $this->get(route('admin.sales.delivery-notes.create'))->assertOk()
        ->assertSee(route('admin.sales.select2.deliverable-invoices'), false)
        ->assertSee(__('sales_ui.delivery_source_help'))
        ->assertSee(__('sales_ui.posted_invoice'));
    $invoiceResponse = $this->get(route('admin.sales.sales-invoices.show', $invoice))->assertOk()
        ->assertSee('id="sales-invoice-delivery"', false)
        ->assertSee('data-electronic-invoice-summary', false)
        ->assertSee('card-header py-2', false)
        ->assertDontSee(__('Queue Electronic Invoice Submission'))
        ->assertSee(route('admin.sales.sales-invoices.deliveries.store', $invoice), false);
    preg_match('/<form id="sales-invoice-delivery".*?<\/form>/s', $invoiceResponse->getContent(), $deliveryForm);
    $goodsInvoiceLine = $invoice->lines()->where('is_service', false)->sole();
    $serviceInvoiceLine = $invoice->lines()->where('is_service', true)->sole();
    expect($deliveryForm[0] ?? '')->toContain($goodsInvoiceLine->public_id)->not->toContain($serviceInvoiceLine->public_id);
    $delivery = app(SalesFulfillmentService::class)->deliverInvoice($invoice, [[
        'customer_invoice_line_id' => $goodsInvoiceLine->getKey(),
        'quantity' => '1',
    ]], [
        'branch_store_uuid' => $f['store']->public_uuid,
        'document_date' => now()->toDateString(),
        'recipient_name' => 'Nadia Receiving',
        'recipient_phone' => '01000000001',
        'vehicle_number' => 'DEL-100',
        'driver_name' => 'Mahmoud Driver',
        'notes' => 'Deliver during the agreed receiving hours.',
    ]);
    $this->get(route('admin.sales.delivery-notes.show', $delivery))->assertOk()
        ->assertSee('data-sales-delivery-details', false)
        ->assertSee('Nadia Receiving')
        ->assertSee('Mahmoud Driver')
        ->assertSee('DEL-100')
        ->assertSee($invoice->doc_num);
    $this->get(route('admin.sales.sales-invoices.show', $invoice->fresh()))->assertOk()
        ->assertDontSee(__('Reverse and Reopen'));
    $order = $order->fresh();
    expect($order->canCancelSafely())->toBeFalse()
        ->and($order->canReopenSafely())->toBeFalse();
    $this->get(route('admin.sales.sales-orders.show', $order))->assertOk()
        ->assertDontSee(__('Cancellation reason'))
        ->assertDontSee(__('Reopen for Amendment'));
    $orderActions = $this->getJson(route('admin.sales.sales-orders.index', ['draw' => 1, 'document' => $order->doc_num]))
        ->assertOk()
        ->json('data.0.actions');
    expect($orderActions)->not->toContain('/cancel', '/reopen');
    $deliveryPdf = $this->get(route('admin.sales.delivery-notes.print', $delivery))->assertOk();
    expect(salesPdfText($deliveryPdf->getContent()))
        ->toContain('Nadia Receiving', 'Mahmoud Driver', 'DEL-100', $invoice->doc_num);
    $this->postJson(route('admin.sales.sales-invoices.deliveries.store', $invoice), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document_date', 'branch_store_uuid', 'lines']);
});

test('sales return creation is discoverable and starts from a returnable posted source', function () {
    $f = salesUiFixture();
    foreach (['sales_returns.view', 'sales_returns.create', 'customer_invoices.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $invoice = salesPostedServiceInvoice($f, '125');
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));

    $this->get(route('admin.sales.sales-returns.index'))->assertOk()
        ->assertSee(route('admin.sales.sales-returns.create'), false);
    $this->get(route('admin.sales.sales-returns.create'))->assertOk()
        ->assertSee(route('admin.sales.select2.returnable-invoices'), false)
        ->assertSee(__('sales_ui.return_source_help'));
    $this->getJson(route('admin.sales.select2.returnable-invoices'))
        ->assertOk()
        ->assertJsonPath('results.0.id', $invoice->doc_num);
    $this->get(route('admin.sales.sales-returns.create', [
        'source_type' => 'invoice',
        'invoice_doc_num' => $invoice->doc_num,
    ]))->assertRedirect(route('admin.sales.sales-invoices.show', $invoice).'#sales-invoice-return');

    $this->get(route('admin.sales.sales-invoices.show', $invoice))
        ->assertOk()
        ->assertSee('data-invoice-action-panel', false)
        ->assertSee('data-sales-return-toggle', false)
        ->assertSee(__('sales_ui.return_items_help'))
        ->assertSee(__('sales_ui.select_item_for_return'))
        ->assertSee(__('sales_ui.return_quantity'))
        ->assertSee('id="invoice_return_line_0"', false)
        ->assertSee('for="invoice_return_line_0"', false)
        ->assertSee('id="invoice_return_quantity_0"', false)
        ->assertSee('for="invoice_return_quantity_0"', false);
});

test('customer credit target invoices use the shared paginated ajax picker', function () {
    $f = salesUiFixture();
    Permission::findOrCreate('customer_credits.allocate', 'web');
    $f['user']->givePermissionTo('customer_credits.allocate');
    $invoice = salesPostedServiceInvoice($f, '125');
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));

    $response = $this->getJson(route('admin.sales.select2.credit-target-invoices', [
        'customer_doc_num' => $f['customer']->doc_num,
        'q' => $invoice->doc_num,
        'page' => 1,
        'per_page' => 10,
    ]))->assertOk();

    $response->assertJsonPath('results.0.id', $invoice->doc_num)
        ->assertJsonPath('pagination.more', false);
    expect($response->json('results.0.text'))->toContain($invoice->doc_num, '125');
});

test('sales report uses ajax business filters and exposes financial analysis', function () {
    $f = salesUiFixture();
    Permission::findOrCreate('reports.sales.sales_orders.view', 'web');
    $f['user']->givePermissionTo('reports.sales.sales_orders.view');
    salesPostedServiceInvoice($f, '250');

    $response = $this->actingAs($f['user'])->withSession(salesCycleSession($f))
        ->get(route('admin.reports.sales.sales-orders.index'))
        ->assertOk()
        ->assertSee('data-sales-financial-summary', false)
        ->assertSee('data-sales-financial-kpi-grid', false)
        ->assertSee('col-12 col-sm-6 col-lg-4 col-xl-3', false)
        ->assertSee(__('sales_ui.gross_sales'))
        ->assertSee(__('sales_ui.net_sales'))
        ->assertSee(__('sales_ui.collection_rate'));

    foreach (['customers', 'quotation-products', 'employees', 'stores'] as $picker) {
        expect($response->getContent())->toContain('/admin/sales/select2/'.$picker);
    }
});

test('customer collection records the receiving employee and prints conditional cheque details', function () {
    $f = salesUiFixture();
    foreach (['customer_receipts.create', 'customer_receipts.view', 'customer_receipts.print', 'file_manager.view', 'reports.sales.sales_orders.view', 'reports.sales.sales_orders.print', 'reports.sales.sales_orders.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $employee = salesUiEmployee($f, 997);
    salesPostedServiceInvoice($f, '125');
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));

    $this->get(route('admin.sales.customer-receipts.create'))->assertOk()
        ->assertSee('id="received_by_employee_doc_num"', false)
        ->assertSee('id="receipt_date"', false)
        ->assertSee('value="'.now()->toDateString().'"', false)
        ->assertSee('data-storage-format="Y-m-d"', false)
        ->assertSee('js-date-picker', false)
        ->assertSee(route('admin.sales.select2.employees'), false)
        ->assertSee(route('admin.select2.currencies'), false)
        ->assertSee('data-shortcut-action="form.save"', false)
        ->assertSee(__('common.actions.save_and_back'))
        ->assertSee('data-required-for="cheque"', false);
    $this->getJson(route('admin.sales.select2.cashboxes'))->assertOk()
        ->assertJsonPath('results.0.id', $f['cashbox']->doc_num);
    $this->getJson(route('admin.sales.select2.bank-accounts'))->assertOk()
        ->assertJsonPath('results.0.id', $f['bankAccount']->doc_num);

    $payload = [
        'customer_doc_num' => $f['customer']->doc_num,
        'received_by_employee_doc_num' => $employee->doc_num,
        'receipt_date' => now()->toDateString(),
        'currency_doc_num' => $f['currency']->doc_num,
        'payment_method' => 'cheque',
        'bank_account_doc_num' => $f['bankAccount']->doc_num,
        'amount' => '125.50',
        'receipt_type' => 'collection',
    ];
    $this->postJson(route('admin.sales.customer-receipts.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reference_no', 'cheque_due_date', 'external_bank_name']);

    $response = $this->postJson(route('admin.sales.customer-receipts.store'), [
        ...$payload,
        'reference_no' => 'CHQ-EMP-997',
        'cheque_due_date' => now()->addWeek()->toDateString(),
        'external_bank_name' => 'Receiving Test Bank',
    ])->assertCreated();
    $receipt = CustomerReceipt::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    expect($receipt->received_by_employee_id)->toBe($employee->id)
        ->and($receipt->created_by)->toBe($f['user']->id)
        ->and($receipt->approved_by)->toBe($f['user']->id);

    Storage::fake('public');
    Storage::disk('public')->put('receipt-cheque.pdf', 'receipt-cheque');
    $attachment = ArchiveFile::query()->create([
        'doc_number' => 998,
        'doc_num' => 'File-RECEIPT-998',
        'attachable_type' => $f['company']->getMorphClass(),
        'attachable_id' => $f['company']->id,
        'original_name' => 'receipt-cheque.pdf',
        'stored_name' => 'receipt-cheque.pdf',
        'disk' => 'public',
        'path' => 'receipt-cheque.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size_bytes' => 14,
    ]);
    $this->postJson(route('admin.sales.document-attachments.store', ['customer_receipt', $receipt->doc_num]), [
        'attachment_doc_nums' => [$attachment->doc_num],
    ])->assertOk();

    $this->get(route('admin.sales.customer-receipts.show', $receipt))->assertOk()
        ->assertSee('data-customer-receipt-details', false)
        ->assertSee('data-sales-attachments', false)
        ->assertSee('erp-document-attachments-card', false)
        ->assertSee('receipt-cheque.pdf')
        ->assertSee($employee->full_name)
        ->assertSee('CHQ-EMP-997')
        ->assertSee('Receiving Test Bank');
    $pdf = $this->get(route('admin.sales.customer-receipts.print', $receipt))->assertOk();
    expect(salesPdfText($pdf->getContent()))->toContain($employee->full_name, 'CHQ-EMP-997', 'Receiving Test Bank');

    $this->get(route('admin.reports.sales.sales-orders.index', ['report' => 'collections']))->assertOk()
        ->assertSee('id="recorded-collections"', false)
        ->assertSee($employee->full_name)
        ->assertSee('CHQ-EMP-997');
    $collectionsPdf = $this->get(route('admin.reports.sales.sales-orders.print', ['report' => 'collections']))->assertOk();
    expect(salesPdfText($collectionsPdf->getContent()))->toContain($employee->full_name, 'CHQ-EMP-997');
    $this->get(route('admin.reports.sales.sales-orders.export', ['report' => 'collections']))
        ->assertOk()
        ->assertDownload();
});

test('quotation index exposes only permitted state actions and filters the requested state', function () {
    $f = salesUiFixture();
    foreach (['quotations.mark_sent', 'quotations.accept', 'quotations.reject', 'quotations.cancel', 'quotations.delete', 'quotations.clone', 'quotations.revisions.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $quote = salesUiQuote($f);
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    $actions = fn () => $this->getJson(route('admin.sales.quotations.data', ['draw' => 1, 'length' => 10]))->assertOk()->json('data.0.actions');
    expect($actions())->toContain('/mark-sent', '/edit')->not->toContain('/accept');
    $service = app(QuotationService::class);
    $quote = $service->markSent($quote);
    expect($actions())->toContain('/accept', '/reject', '/revisions')->not->toContain('/mark-sent', 'js-delete-record', 'js-edit-record');
    $this->getJson(route('admin.sales.quotations.data', ['draw' => 1, 'status' => 'draft']))->assertJsonPath('recordsFiltered', 0);
    $quote = $service->accept($quote);
    expect($actions())->toContain('#quotation-conversion')->not->toContain('/mark-sent', '/accept', '/reject');
    $service->cancel($quote);
    expect($actions())->toContain('/print', '/clone')->not->toContain('#quotation-conversion', '/cancel', '/mark-sent', 'js-delete-record');
});
