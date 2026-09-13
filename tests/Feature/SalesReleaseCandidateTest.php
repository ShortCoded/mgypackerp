<?php

use App\Services\PostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\FinancialPeriod;
use Modules\Finance\Services\CashVoucherService;
use Modules\Finance\Services\ChequeService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\CustomerCreditLimit;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\CreditControlService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\CustomerReceiptSettlementService;
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesAccountingService;
use Modules\Sales\Services\SalesCycleReadService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

test('a duplicate delivery row cannot consume more than its source order', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->first();
    $input = ['sales_order_line_id' => $line->id, 'quantity' => '60'];
    expect(fn () => app(SalesFulfillmentService::class)->deliver($order, [$input, $input]))->toThrow(DomainException::class);
    expect($line->fresh()->delivered_quantity)->toBe('0.00000000')
        ->and(InventoryDocument::query()->count())->toBe(0);
});

test('a duplicate invoice row cannot invoice the same delivered quantity twice', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->first();
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->id, 'quantity' => '100']]);
    $input = ['sales_order_line_id' => $line->id, 'delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '60'];
    expect(fn () => app(CustomerInvoiceService::class)->createFromOrder($order, [$input, $input], [['due_date' => now()->toDateString(), 'amount' => '1200']], $delivery))->toThrow(DomainException::class);
    expect($line->fresh()->invoiced_quantity)->toBe('0.00000000')->and(CustomerInvoice::query()->count())->toBe(0);
});

test('return duplicate rows cannot exceed the remaining invoiced quantity', function (): void {
    $fixture = salesCycleFixture();
    $invoice = salesPostedServiceInvoice($fixture, '100', '0', '10');
    $input = ['customer_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '6'];
    expect(fn () => app(SalesReturnService::class)->create($invoice, 'other', null, [$input, $input]))->toThrow(DomainException::class);
    expect(DB::table('sales_returns')->count())->toBe(0);
});

test('production demand is limited to shortage after stock and prior planning', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'lines' => [['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '150', 'unit_price' => '10']],
        'payment_schedules' => [],
    ])));
    $line = $order->lines->first();
    app(SalesFulfillmentService::class)->reserve($line, '100');
    $service = app(SalesProductionDemandService::class);
    expect(fn () => $service->create($order, [['sales_order_line_id' => $line->id, 'quantity' => '51']]))->toThrow(DomainException::class);
    $production = $service->create($order, [['sales_order_line_id' => $line->id, 'quantity' => '50']]);
    expect($production->lines->first()->quantity)->toBe('50.00000000');
    expect(fn () => $service->create($order, [['sales_order_line_id' => $line->id, 'quantity' => '1']]))->toThrow(DomainException::class);
    expect(ProductionOrder::query()->count())->toBe(1);
    expect(fn () => app(SalesOrderService::class)->cancel($order, 'Customer cancelled'))->toThrow(DomainException::class);
});

test('sales delivery reversal respects invoice dependency and restores unbilled order quantities', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->first();
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->id, 'quantity' => '40']]);
    app(InventoryDocumentPostingService::class)->reverse($delivery);
    expect($line->fresh()->delivered_quantity)->toBe('0.00000000')->and($order->fresh()->status)->toBe(SalesOrder::StatusApproved);
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->id, 'quantity' => '40']]);
    $invoice = app(CustomerInvoiceService::class)->createFromOrder($order, [['sales_order_line_id' => $line->id, 'delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '40']], [['due_date' => now()->toDateString(), 'amount' => '400']], $delivery);
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($delivery))->toThrow(DomainException::class, $invoice->doc_num);
    expect($delivery->fresh()->status)->toBe(InventoryDocument::StatusPosted)->and($line->fresh()->delivered_quantity)->toBe('40.00000000');
});

test('new delivery invoice and production demand use their date period after source period closes', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'lines' => [['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '150', 'unit_price' => '10']],
        'payment_schedules' => [],
    ])));
    $line = $order->lines->first();
    $fixture['period']->update(['to_date' => now()->subDay()->toDateString(), 'is_closed' => true]);
    $period = FinancialPeriod::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 9002, 'doc_num' => 'FP-NEW', 'name' => 'Next period', 'from_date' => now()->toDateString(), 'to_date' => now()->addYear()->toDateString(), 'is_closed' => false]);
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->id, 'quantity' => '60']]);
    $invoice = app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createFromOrder($order, [['sales_order_line_id' => $line->id, 'delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '60']], [['due_date' => now()->toDateString(), 'amount' => '600']], $delivery));
    $production = app(SalesProductionDemandService::class)->create($order, [['sales_order_line_id' => $line->id, 'quantity' => '50']]);
    expect($delivery->financial_period_id)->toBe($period->id)->and($invoice->financial_period_id)->toBe($period->id)->and($production->financial_period_id)->toBe($period->id)
        ->and($order->fresh()->financial_period_id)->toBe($fixture['period']->id);
});

test('approved requests convert partially through quotations without losing source quantities', function (): void {
    $fixture = salesCycleFixture();
    createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $permissions = ['sales_requests.view', 'sales_requests.create', 'sales_requests.edit', 'sales_requests.approve', 'sales_requests.convert', 'sales_requests.print', 'quotations.create', 'sales_orders.create'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $response = $this->postJson(route('admin.sales.customer-requests.store'), [
        'request_type' => 'customer', 'request_date' => now()->toDateString(), 'required_delivery_date' => now()->addWeek()->toDateString(),
        'customer_doc_num' => $fixture['customer']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => '1',
        'lines' => [['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => '100', 'unit_price' => '10']],
    ])->assertSuccessful();
    $request = SalesRequest::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $this->postJson(route('admin.sales.customer-requests.transition', $request), ['status' => 'submitted'])->assertSuccessful();
    $this->postJson(route('admin.sales.customer-requests.transition', $request), ['status' => 'approved'])->assertSuccessful();
    $sourceLine = $request->lines->first();
    $this->postJson(route('admin.sales.customer-requests.convert', $request), ['target' => 'quotation', 'lines' => [['public_id' => $sourceLine->public_id, 'quantity' => '60']]])->assertCreated();
    $quotation = Quotation::query()->where('sales_request_id', $request->id)->firstOrFail();
    expect($request->fresh()->status)->toBe('partially_converted')->and($sourceLine->fresh()->remainingQuantity())->toBe('40.00000000')
        ->and($quotation->currentRevision->lines->first()->sales_request_line_id)->toBe($sourceLine->id)
        ->and(InventoryDocument::query()->count())->toBe(0)->and(CustomerInvoice::query()->count())->toBe(0);
    $quotations = app(QuotationService::class);
    $quotation = $quotations->accept($quotations->markSent($quotation));
    $line = $quotation->currentRevision->lines->first();
    $context = ['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'financial_period_id' => $fixture['period']->id];
    $order = app(SalesOrderService::class)->createFromQuotation($quotation, $context, [['public_id' => $line->public_uuid, 'quantity' => '25']]);
    expect($order->lines->first()->quantity)->toBe('25.00000000')->and($order->sales_request_id)->toBe($request->id)->and($quotation->fresh()->status)->toBe('accepted');
    $second = app(SalesOrderService::class)->createFromQuotation($quotation->fresh(), $context);
    expect($second->lines->first()->quantity)->toBe('35.00000000')->and($quotation->fresh()->status)->toBe('converted');
    $this->get(route('admin.sales.customer-requests.show', $request))->assertOk();
    $this->get(route('admin.sales.customer-requests.print', $request))->assertOk()->assertHeader('content-type', 'application/pdf');
});

test('request can begin without a customer and no-op save preserves its line identity', function (): void {
    $fixture = salesCycleFixture();
    $data = ['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $fixture['finished']->id, 'quantity' => '8', 'unit_id' => $fixture['unit']->id]]];
    $service = app(SalesRequestService::class);
    $record = $service->save($data);
    $updated = $service->save($data, $record);
    expect($updated->customer_id)->toBeNull()->and($updated->lines->first()->public_id)->toBe($record->lines->first()->public_id)
        ->and($updated->updated_at->toISOString())->toBe($record->updated_at->toISOString());
});

test('credit limits fall back to customer currency settings and drafts retain order exposure', function (): void {
    $fixture = salesCycleFixture();
    CustomerCommercialAgreement::query()->where('customer_id', $fixture['customer']->id)->delete();
    CustomerCreditLimit::query()->create(['company_id' => $fixture['company']->id, 'customer_id' => $fixture['customer']->id, 'currency_id' => $fixture['currency']->id, 'credit_limit' => '2000']);
    $orders = app(SalesOrderService::class);
    $first = $orders->approve($orders->create(salesCycleOrderPayload($fixture)));
    expect($first->status)->toBe(SalesOrder::StatusApproved)->and($first->credit_limit_snapshot)->toBe('2000.0000');
    $line = $first->lines->first();
    $delivery = app(SalesFulfillmentService::class)->deliver($first, [['sales_order_line_id' => $line->id, 'quantity' => '40']]);
    app(CustomerInvoiceService::class)->createFromOrder($first, [['sales_order_line_id' => $line->id, 'delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '40']], [['due_date' => now()->toDateString(), 'amount' => '400']], $delivery);
    $second = $orders->create(salesCycleOrderPayload($fixture));
    $evaluation = app(CreditControlService::class)->evaluate($second);
    expect($evaluation['open_order_exposure'])->toBe('1100.0000')->and($evaluation['projected_exposure'])->toBe('2200.0000')->and($evaluation['blocked'])->toBeTrue();
    $unchanged = $orders->update($second, salesCycleOrderPayload($fixture));
    expect($unchanged->lines->first()->public_id)->toBe($second->lines->first()->public_id);
});

test('missing credit configuration does not create a zero limit hold', function (): void {
    $fixture = salesCycleFixture();
    CustomerCommercialAgreement::query()->where('customer_id', $fixture['customer']->id)->delete();
    CustomerCreditLimit::query()->where('customer_id', $fixture['customer']->id)->delete();
    $orders = app(SalesOrderService::class);
    $order = $orders->create(salesCycleOrderPayload($fixture));
    $evaluation = app(CreditControlService::class)->evaluate($order);

    expect($evaluation['credit_limit_configured'])->toBeFalse()
        ->and($evaluation['blocked'])->toBeFalse()
        ->and($orders->approve($order)->status)->toBe(SalesOrder::StatusApproved);
});

test('backorders share free stock once and remain visible after their source period closes', function (): void {
    $fixture = salesCycleFixture();
    $orders = app(SalesOrderService::class);
    $first = $orders->approve($orders->create(salesCycleOrderPayload($fixture)));
    $second = $orders->approve($orders->create(salesCycleOrderPayload($fixture)));
    app(SalesFulfillmentService::class)->reserve($second->lines->first(), '30');
    $fixture['period']->update(['is_closed' => true]);
    $rows = app(SalesCycleReadService::class)->backorders($fixture['company']->id, $fixture['branch']->id);
    expect($rows)->toHaveCount(2)->and($rows[0]['available'])->toBe('70.00000000')->and($rows[0]['shortage'])->toBe('30.00000000')
        ->and($rows[1]['reserved'])->toBe('30.00000000')->and($rows[1]['available'])->toBe('30.00000000')->and($rows[1]['shortage'])->toBe('70.00000000');
});

test('customer overview sales ledger exports and cross period statements render real posted data', function (): void {
    foreach (['sales-by-customer', 'sales-by-product', 'sales-by-employee', 'quotations', 'contract-status', 'delivery-schedule'] as $retiredShell) {
        expect(Route::has('admin.reports.sales.'.$retiredShell.'.index'))->toBeFalse();
    }
    $fixture = salesCycleFixture();
    $invoice = salesPostedServiceInvoice($fixture, '100', '0', '1');
    foreach (['customers.view', 'customer_invoices.view', 'reports.sales.sales_orders.view', 'reports.sales.sales_orders.print', 'reports.sales.sales_orders.export', 'reports.customer_statement.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $this->get(route('admin.sales.customers.show', $fixture['customer']))->assertOk()->assertSee('customer-sales-overview');
    $this->get(route('admin.reports.sales.sales-orders.index'))->assertOk()->assertSee('Sales Ledger')->assertSee($invoice->doc_num);
    $this->get(route('admin.reports.sales.sales-orders.export'))->assertOk();
    $this->get(route('admin.reports.sales.sales-orders.print'))->assertOk()->assertHeader('content-type', 'application/pdf');
    $fixture['period']->update(['to_date' => now()->subDay()->toDateString(), 'is_closed' => true]);
    $period = FinancialPeriod::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 9002, 'doc_num' => 'FP-STATEMENT', 'name' => 'Next period', 'from_date' => now()->toDateString(), 'to_date' => now()->addYear()->toDateString(), 'is_closed' => false]);
    $fixture['period'] = $period;
    $this->withSession(salesCycleSession($fixture))->get(route('admin.accounting.reports.customer-statement', ['run' => 1, 'all_periods' => 1, 'customer_doc_num' => $fixture['customer']->doc_num, 'from_date' => now()->subYear()->toDateString(), 'to_date' => now()->toDateString()]))->assertOk()->assertSee($invoice->doc_num);
});

test('unbilled delivery returns use quality workflow without a credit note and protect invoice quantities', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->first();
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->id, 'quantity' => '60']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->createFromDelivery($delivery, 'wrong_item', 'Unbilled customer rejection', [['delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '20']]);
    expect(fn () => app(CustomerInvoiceService::class)->createFromOrder($order, [['sales_order_line_id' => $line->id, 'delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '41']], [], $delivery))->toThrow(DomainException::class);
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($delivery))->toThrow(DomainException::class, $return->doc_num);
    $return = $returns->receive($returns->authorize($return));
    expect($line->fresh()->delivered_quantity)->toBe('40.00000000');
    $return = $returns->close($returns->inspect($return, [['sales_return_line_id' => $return->lines->first()->id, 'saleable_quantity' => '20']]));
    expect($return->status)->toBe('closed')->and($return->credit_note_id)->toBeNull()->and(CustomerInvoice::query()->count())->toBe(0);
    $invoice = app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createFromOrder($order, [['sales_order_line_id' => $line->id, 'delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '40']], [['due_date' => now()->toDateString(), 'amount' => '400']], $delivery));
    expect($invoice->total_amount)->toBe('400.0000');
    expect(fn () => $returns->createFromDelivery($delivery, 'wrong_item', null, [['delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '1']]))->toThrow(DomainException::class);
    expect((float) InventoryTransaction::query()->where('product_id', $fixture['finished']->id)->selectRaw('sum(quantity_in - quantity_out) as quantity')->value('quantity'))->toBe(60.0);
});

test('sales delivery credits the configured finished goods account', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '10']]);
    $inventoryAccount = app(PostingAccountResolver::class)->inventoryForProduct($fixture['company']->id, $fixture['finished'], 'Sales delivery test');
    $credit = $delivery->journalEntry->lines()->where('credit_amount', '>', 0)->sole();
    expect($credit->account_id)->toBe($inventoryAccount->getKey())->and($credit->credit_amount)->toBe('50.0000');
});

test('sales supporting documents reuse the archive with company scope and duplicate protection', function (): void {
    $fixture = salesCycleFixture();
    foreach (['sales_orders.view', 'sales_orders.edit', 'file_manager.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    Storage::fake('public');
    Storage::disk('public')->put('sales-po.pdf', 'test-pdf-content');
    $file = ArchiveFile::query()->create(['doc_number' => 991, 'doc_num' => 'File-SALES-PO', 'attachable_type' => $fixture['company']->getMorphClass(), 'attachable_id' => $fixture['company']->id,
        'original_name' => 'sales-po.pdf', 'stored_name' => 'sales-po.pdf', 'disk' => 'public', 'path' => 'sales-po.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 16]);
    $order = app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture));
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $url = route('admin.sales.document-attachments.store', ['sales_order', $order->doc_num]);
    $this->postJson($url, ['attachment_doc_nums' => [$file->doc_num]])->assertOk();
    $this->postJson($url, ['attachment_doc_nums' => [$file->doc_num]])->assertOk();
    expect(ArchiveFileUsage::query()->whereMorphedTo('usable', $order)->count())->toBe(1);
    $this->get(route('admin.sales.sales-orders.show', $order))->assertOk()
        ->assertSee('sales-po.pdf')
        ->assertSee('data-sales-attachments', false)
        ->assertSee('data-picker-max="20"', false);
    $file->update(['attachable_id' => $fixture['company']->id + 100]);
    $this->postJson($url, ['attachment_doc_nums' => [$file->doc_num]])->assertUnprocessable();
});

test('canonical cheque collection settles only on clearing and bounced receipts release pending allocations', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    request()->setLaravelSession(app('session.store'));
    $invoice = salesPostedServiceInvoice($fixture, '100', '0', '10');
    $payload = ['company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id, 'customer_id' => $fixture['customer']->id,
        'receipt_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->id, 'exchange_rate' => '1', 'payment_method' => 'cheque',
        'bank_account_id' => $fixture['bankAccount']->id, 'reference_no' => 'SALES-CHQ-CLEAR', 'cheque_due_date' => now()->addDay()->toDateString(), 'external_bank_name' => 'Fixture Bank', 'amount' => '60', 'receipt_type' => 'collection'];
    $allocations = [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->first()->id, 'amount' => '60']];
    $receipts = app(CustomerReceiptService::class);
    $receipt = $receipts->createAndApprove($payload, $allocations);
    expect($receipt->journal_entry_id)->toBeNull()->and($invoice->fresh()->remaining_amount)->toBe('100.0000');
    expect(fn () => $receipts->createAndApprove([...$payload, 'reference_no' => 'DUPLICATE'], $allocations))->toThrow(DomainException::class);
    $cheques = app(ChequeService::class);
    $cheques->markCollected($cheques->markDeposited($receipt->cheque));
    expect($invoice->fresh()->paid_amount)->toBe('60.0000')->and($invoice->fresh()->remaining_amount)->toBe('40.0000')->and($receipt->fresh()->journal_entry_id)->not->toBeNull();
    $second = $receipts->createAndApprove([...$payload, 'amount' => '40', 'reference_no' => 'SALES-CHQ-BOUNCE'], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->first()->id, 'amount' => '40']]);
    $cheques->markReturned($second->cheque);
    expect($second->fresh()->status)->toBe('cancelled')->and($invoice->fresh()->remaining_amount)->toBe('40.0000');
    $cash = $receipts->createAndApprove([...$payload, 'payment_method' => 'cash', 'bank_account_id' => null, 'cashbox_id' => $fixture['cashbox']->id, 'amount' => '40'], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->first()->id, 'amount' => '40']]);
    expect($invoice->fresh()->remaining_amount)->toBe('0.0000');
    app(CashVoucherService::class)->cancel('receipt', $cash->cashVoucher, 'Duplicate customer payment');
    expect($invoice->fresh()->remaining_amount)->toBe('40.0000')->and($cash->fresh()->reversal_journal_entry_id)->not->toBeNull();
    $reversal = $cash->fresh()->reversal_journal_entry_id;
    app(CustomerReceiptSettlementService::class)->reverse($cash, 'Repeated reversal');
    expect($invoice->fresh()->remaining_amount)->toBe('40.0000')->and($cash->fresh()->reversal_journal_entry_id)->toBe($reversal);
});

test('an approved internal request can receive its customer currency and store during conversion', function (): void {
    $fixture = salesCycleFixture();
    createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $requests = app(SalesRequestService::class);
    $request = $requests->save(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '5', 'unit_price' => '10']]]);
    $request = $requests->transition($requests->transition($request, 'submitted'), 'approved');
    $order = $requests->convert($request, 'order', [['public_id' => $request->lines->first()->public_id, 'quantity' => '5']], ['customer_doc_num' => $fixture['customer']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num, 'branch_store_uuid' => $fixture['store']->public_uuid]);
    expect($order->customer_id)->toBe($fixture['customer']->id)->and($order->branch_store_id)->toBe($fixture['store']->id)->and($request->fresh()->status)->toBe('converted');
});

test('price suggestions use the latest applicable price list', function (): void {
    $fixture = salesCycleFixture();
    $list = createSalesPriceList($fixture, $fixture['customer']->id, [['product' => $fixture['service'], 'price' => '10']]);
    Permission::findOrCreate('sales_orders.create', 'web');
    $fixture['user']->givePermissionTo('sales_orders.create');
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $query = ['customer_doc_num' => $fixture['customer']->doc_num, 'product_doc_num' => $fixture['service']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num];
    $this->getJson(route('admin.sales.price-suggestion', $query))->assertOk()->assertJsonPath('data.unit_price', '10.0000')->assertJsonPath('data.source', $list->doc_num);
    $this->getJson(route('admin.sales.price-suggestion', [...$query, 'product_doc_num' => $fixture['finished']->doc_num]))->assertOk()->assertJsonPath('data', null);
});

test('the last partial invoice absorbs discount and tax rounding without changing the order total', function (): void {
    $fixture = salesCycleFixture();
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture, ['lines' => [['product_id' => $fixture['service']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '3', 'unit_price' => '10', 'discount_amount' => '1', 'tax_amount' => '2']], 'payment_schedules' => []])));
    $service = app(CustomerInvoiceService::class);
    foreach (['10.3333', '10.3333', '10.3334'] as $amount) {
        $service->post($service->createFromOrder($order, [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '1']], [['due_date' => now()->toDateString(), 'amount' => $amount]]));
    }
    expect(number_format((float) $order->invoices()->sum('discount_amount'), 4, '.', ''))->toBe('1.0000')
        ->and(number_format((float) $order->invoices()->sum('tax_amount'), 4, '.', ''))->toBe('2.0000')
        ->and(number_format((float) $order->invoices()->sum('total_amount'), 4, '.', ''))->toBe($order->total_amount);
});

test('cancelling an unreceived return releases its source quantity without inventory or credit postings', function (): void {
    $fixture = salesCycleFixture();
    $invoice = salesPostedServiceInvoice($fixture, '100', '0', '10');
    $service = app(SalesReturnService::class);
    $line = ['customer_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '8'];
    $return = $service->create($invoice, 'other', null, [$line]);
    $cancelled = $service->cancel($return, 'Customer retained goods');
    expect($cancelled->status)->toBe('cancelled')->and($cancelled->credit_note_id)->toBeNull();
    expect($service->create($invoice, 'other', null, [$line])->status)->toBe('pending_authorization');
});

test('historical prematurely posted cheques are deferred with a reversal and can then clear once', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    request()->setLaravelSession(app('session.store'));
    $receipt = app(CustomerReceiptService::class)->createAndApprove(['company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id, 'customer_id' => $fixture['customer']->id,
        'receipt_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->id, 'exchange_rate' => '1', 'payment_method' => 'cheque', 'bank_account_id' => $fixture['bankAccount']->id,
        'reference_no' => 'LEGACY-CHEQUE', 'cheque_due_date' => now()->toDateString(), 'amount' => '50', 'receipt_type' => 'advance']);
    $journal = app(SalesAccountingService::class)->postReceipt($receipt);
    $receipt->update(['journal_entry_id' => $journal->id]);
    $this->artisan('sales:reconcile-pending-cheques', ['--company' => $fixture['company']->id, '--apply' => true])->assertSuccessful();
    expect($receipt->fresh()->journal_entry_id)->toBeNull()->and($receipt->fresh()->reversal_journal_entry_id)->not->toBeNull()->and($receipt->fresh()->status)->toBe('approved');
    $cheques = app(ChequeService::class);
    $cheques->markCollected($cheques->markDeposited($receipt->fresh()->cheque));
    expect($receipt->fresh()->journal_entry_id)->not->toBe($journal->id)->and($receipt->fresh()->journalEntry->source_type)->toBe('customer_receipt_clearing');
});
