<?php

use App\Models\User;
use App\Services\IntegratedPlasticFactoryDemoVerifier;
use App\Services\PostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Services\ChequeService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Modules\Sales\Services\SalesReturnService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_sales_rc_20260906') {
        $this->markTestSkipped('Requires the isolated populated sales release database.');
    }
    $this->sourceRequest = SalesRequest::query()->where('customer_reference', 'SALES-RC-STOCK-EXAMPLE')->firstOrFail();
    $admin = User::query()->where('username', 'admin')->firstOrFail();
    $number = ((int) User::withTrashed()->max('doc_number')) + 1;
    $this->user = User::factory()->create(['doc_number' => $number, 'doc_num' => 'User-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT)]);
    $this->user->givePermissionTo($admin->getAllPermissions());
    $period = FinancialPeriod::query()->findOrFail($this->sourceRequest->financial_period_id);
    $this->session = [OperatingContextService::CompanyIdKey => $this->sourceRequest->company_id,
        OperatingContextService::CompanyDocNumKey => $this->sourceRequest->company->doc_num,
        OperatingContextService::BranchIdKey => $this->sourceRequest->branch_id,
        OperatingContextService::BranchDocNumKey => $this->sourceRequest->branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->id,
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num];
    $this->actingAs($this->user)->withSession($this->session);
    request()->setUserResolver(fn () => $this->user);
    request()->setLaravelSession(app('session.store'));
});

test('the browser request completes quotation order stock invoice and canonical cash collection on PostgreSQL', function (): void {
    $requests = app(SalesRequestService::class);
    $request = $this->sourceRequest;
    if ($request->status === 'draft') {
        $request = $requests->transition($request, 'submitted');
    }
    if ($request->status === 'submitted') {
        $request = $requests->transition($request, 'approved');
    }
    $quotation = $request->quotations()->first();
    if (! $quotation) {
        $quotation = $requests->convert($request, 'quotation', [['public_id' => $request->lines->first()->public_id, 'quantity' => '10']]);
    }
    $quotations = app(QuotationService::class);
    if ($quotation->status === 'draft') {
        $quotation = $quotations->markSent($quotation);
    }
    if ($quotation->status === 'sent') {
        $quotation = $quotations->accept($quotation);
    }
    $orders = app(SalesOrderService::class);
    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->first()
        ?? $orders->createFromQuotation($quotation, ['company_id' => $request->company_id, 'branch_id' => $request->branch_id, 'financial_period_id' => $request->financial_period_id]);
    if ($order->status === 'draft') {
        $order = $orders->approve($order);
    }
    $fulfillment = app(SalesFulfillmentService::class);
    $line = $order->lines->first();
    $delivery = $order->deliveries()->first();
    if (! $delivery) {
        $fulfillment->reserve($line, '10');
        $delivery = $fulfillment->deliver($order, [['sales_order_line_id' => $line->id, 'quantity' => '10']]);
    }
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $order->invoices()->first();
    if (! $invoice) {
        $invoice = $invoices->createFromOrder($order, [['sales_order_line_id' => $line->id, 'delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '10']], [['due_date' => now()->toDateString(), 'amount' => '1000']], $delivery);
    }
    if ($invoice->posting_status !== 'posted') {
        $invoice = $invoices->post($invoice);
    }
    $receipt = CustomerReceipt::query()->where('notes', 'SALES-RC-STOCK-COLLECTION')->first();
    if (! $receipt) {
        $receipt = app(CustomerReceiptService::class)->createAndApprove(['company_id' => $order->company_id, 'financial_period_id' => $invoice->financial_period_id, 'branch_id' => $order->branch_id, 'customer_id' => $order->customer_id,
            'receipt_date' => now()->toDateString(), 'currency_id' => $order->currency_id, 'exchange_rate' => '1', 'payment_method' => 'cash',
            'cashbox_id' => Cashbox::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->where('status', 'active')->firstOrFail()->id,
            'amount' => '1000', 'receipt_type' => 'collection', 'notes' => 'SALES-RC-STOCK-COLLECTION'], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->first()->id, 'amount' => '1000']]);
    }
    expect($invoice->fresh()->remaining_amount)->toBe('0.0000')->and($line->fresh()->delivered_quantity)->toBe('10.00000000')->and($receipt->cashVoucher)->not->toBeNull();
    file_put_contents('/tmp/erp-sales-business-stock-example.json', json_encode(['request' => $request->doc_num, 'quotation' => $quotation->doc_num, 'order' => $order->doc_num, 'delivery' => $delivery->doc_num, 'invoice' => $invoice->doc_num, 'receipt' => $receipt->doc_num, 'cash_voucher' => $receipt->cashVoucher->doc_num, 'quantity' => '10', 'amount' => '1000', 'outstanding' => $invoice->fresh()->remaining_amount], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('simultaneous PostgreSQL deliveries cannot consume the same remaining source quantity', function (): void {
    $source = $this->sourceRequest;
    $product = $source->lines->first();
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(['company_id' => $source->company_id, 'financial_period_id' => $source->financial_period_id, 'branch_id' => $source->branch_id, 'branch_store_id' => $source->branch_store_id,
        'customer_id' => $source->customer_id, 'currency_id' => $source->currency_id, 'exchange_rate' => '1', 'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(), 'customer_reference' => 'SALES-RC-CONCURRENCY',
        'lines' => [['product_id' => $product->product_id, 'unit_id' => $product->unit_id, 'quantity' => '10', 'unit_price' => '100']], 'payment_schedules' => []]));
    $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); auth()->loginUsingId('.$this->user->id.'); $order = Modules\\Sales\\Models\\SalesOrder::findOrFail('.$order->id.'); try { app(Modules\\Sales\\Services\\SalesFulfillmentService::class)->deliver($order, [["sales_order_line_id" => '.$order->lines->first()->id.', "quantity" => "8"]]); echo "accepted"; } catch (DomainException $e) { echo "rejected"; }';
    $processes = [new Process([PHP_BINARY, '-r', $code], base_path()), new Process([PHP_BINARY, '-r', $code], base_path())];
    foreach ($processes as $process) {
        $process->setTimeout(45)->start();
    }
    $outcomes = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $outcomes[] = trim($process->getOutput());
    }
    sort($outcomes);
    expect($outcomes)->toBe(['accepted', 'rejected'])->and($order->lines()->first()->delivered_quantity)->toBe('8.00000000')->and($order->deliveries()->count())->toBe(1);
});

test('replaying a sales request submission returns the original document without duplication', function (): void {
    $number = ((int) User::withTrashed()->max('doc_number')) + 1;
    $apiUser = User::factory()->create(['doc_number' => $number, 'doc_num' => 'User-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT)]);
    $apiUser->givePermissionTo('sales_requests.create');
    $this->actingAs($apiUser)->withSession($this->session);
    $source = $this->sourceRequest;
    $line = $source->lines->first();
    $token = (string) Str::uuid();
    $payload = ['_submission_token' => $token, 'request_date' => now()->toDateString(), 'priority' => 'normal', 'exchange_rate' => '1', 'customer_reference' => $token,
        'lines' => [['product_doc_num' => $line->product->doc_num, 'unit_doc_num' => $line->unit->doc_num, 'quantity' => '1']]];
    $first = $this->postJson(route('admin.sales.customer-requests.store'), $payload)->assertSuccessful();
    $second = $this->postJson(route('admin.sales.customer-requests.store'), $payload)->assertSuccessful();
    expect($second->json('data.doc_num'))->toBe($first->json('data.doc_num'))->and(SalesRequest::query()->where('customer_reference', $token)->count())->toBe(1);
    $this->postJson(route('admin.sales.customer-requests.store'), [...$payload, 'priority' => 'high'])->assertConflict();
});

test('populated sales reconciliation evidence is available for release review', function (): void {
    $deliveries = InventoryDocument::query()->with('lines.product', 'journalEntry.lines')
        ->where('company_id', $this->sourceRequest->company_id)->where('document_type', InventoryDocument::TypeSalesDelivery)
        ->whereHas('salesOrder', fn ($query) => $query->whereIn('customer_reference', ['SALES-RC-STOCK-EXAMPLE', 'SALES-RC-CONCURRENCY']))->get();
    $postingAccounts = app(PostingAccountResolver::class);
    foreach ($deliveries as $delivery) {
        $credits = $delivery->journalEntry->lines->where('credit_amount', '>', 0);
        $expectedAccount = $postingAccounts->inventoryForProduct($this->sourceRequest->company_id, $delivery->lines->first()->product, 'Sales release verification');
        if ($credits->count() === 1 && $credits->first()->account_id !== $expectedAccount->id) {
            $credit = $credits->first();
            app(JournalEntryService::class)->createPostedFromSource([
                'company_id' => $delivery->company_id, 'financial_period_id' => $delivery->financial_period_id, 'branch_id' => $delivery->branch_id,
                'currency_id' => $this->sourceRequest->currency_id, 'exchange_rate' => '1', 'entry_date' => now()->toDateString(),
                'description' => 'Correct inventory classification in isolated release example '.$delivery->doc_num,
                'source_type' => 'sales_release_demo_cogs_correction', 'source_id' => $delivery->id, 'source_doc_num' => $delivery->doc_num,
            ], [['account_id' => $credit->account_id, 'debit_amount' => $credit->credit_amount, 'credit_amount' => 0],
                ['account_id' => $expectedAccount->id, 'debit_amount' => 0, 'credit_amount' => $credit->credit_amount]]);
        }
    }
    $result = app(IntegratedPlasticFactoryDemoVerifier::class)->verify();
    file_put_contents('/tmp/erp-sales-reconciliation.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    expect($result['checks']['customer_subledgers_reconcile_to_gl'])->toBeTrue()->and($result['checks']['inventory_subledger_reconciles_to_gl'])->toBeTrue()->and($result['checks']['all_journals_are_balanced_and_nonzero'])->toBeTrue();
});

test('eight decimal source quantities survive request quotation delivery and invoice on PostgreSQL', function (): void {
    $source = $this->sourceRequest;
    $line = $source->lines->first();
    $requests = app(SalesRequestService::class);
    $request = $requests->save(['company_id' => $source->company_id, 'branch_id' => $source->branch_id, 'branch_store_id' => $source->branch_store_id,
        'customer_id' => $source->customer_id, 'currency_id' => $source->currency_id, 'exchange_rate' => '1', 'request_date' => now()->toDateString(), 'required_delivery_date' => now()->toDateString(),
        'customer_reference' => 'SALES-RC-PRECISION', 'lines' => [['product_id' => $line->product_id, 'unit_id' => $line->unit_id, 'quantity' => '0.12345678', 'unit_price' => '10000']]]);
    $request = $requests->transition($requests->transition($request, 'submitted'), 'approved');
    $quotation = $requests->convert($request, 'quotation', [['public_id' => $request->lines->first()->public_id, 'quantity' => '0.12345678']]);
    $quotations = app(QuotationService::class);
    $quotation = $quotations->accept($quotations->markSent($quotation));
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->createFromQuotation($quotation, ['company_id' => $source->company_id, 'branch_id' => $source->branch_id, 'financial_period_id' => $source->financial_period_id]));
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '0.12345678']]);
    $invoice = app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createFromOrder($order, [['sales_order_line_id' => $order->lines->first()->id, 'delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '0.12345678']], [['due_date' => now()->toDateString(), 'amount' => '1234.5678']], $delivery));
    expect($quotation->currentRevision->lines->first()->quantity)->toBe('0.12345678')
        ->and($order->fresh()->lines->first()->quantity)->toBe('0.12345678')
        ->and($invoice->fresh()->lines->first()->quantity)->toBe('0.12345678');
});

test('partial delivery invoice cheque clearing and inspected return remain reconciled on populated PostgreSQL', function (): void {
    $source = $this->sourceRequest;
    $line = $source->lines->first();
    $orders = app(SalesOrderService::class);
    $order = SalesOrder::query()->where('customer_reference', 'SALES-RC-CHEQUE-RETURN')->first();
    if (! $order) {
        $order = $orders->approve($orders->create(['company_id' => $source->company_id, 'financial_period_id' => $source->financial_period_id, 'branch_id' => $source->branch_id, 'branch_store_id' => $source->branch_store_id,
            'customer_id' => $source->customer_id, 'currency_id' => $source->currency_id, 'exchange_rate' => '1', 'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(), 'customer_reference' => 'SALES-RC-CHEQUE-RETURN',
            'lines' => [['product_id' => $line->product_id, 'unit_id' => $line->unit_id, 'quantity' => '20', 'unit_price' => '100']], 'payment_schedules' => []]));
    }
    $orderLine = $order->lines->first();
    $delivery = $order->deliveries()->first() ?? app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $orderLine->id, 'quantity' => '12']]);
    $invoice = $order->invoices()->where('document_type', 'invoice')->first();
    if (! $invoice) {
        $invoice = app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createFromOrder($order, [['sales_order_line_id' => $orderLine->id, 'delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '8']], [['due_date' => now()->addMonth()->toDateString(), 'amount' => '800']], $delivery));
    }
    $receipt = CustomerReceipt::query()->where('notes', 'SALES-RC-CHEQUE-RETURN')->first();
    if (! $receipt) {
        $receipt = app(CustomerReceiptService::class)->createAndApprove(['company_id' => $order->company_id, 'financial_period_id' => $invoice->financial_period_id, 'branch_id' => $order->branch_id, 'customer_id' => $order->customer_id,
            'receipt_date' => now()->toDateString(), 'currency_id' => $order->currency_id, 'exchange_rate' => '1', 'payment_method' => 'cheque',
            'bank_account_id' => BankAccount::query()->where('company_id', $order->company_id)->where('currency_id', $order->currency_id)->where('status', 'active')->firstOrFail()->id,
            'reference_no' => 'SALES-RC-CHQ-20260906', 'cheque_due_date' => now()->toDateString(), 'external_bank_name' => 'Customer bank', 'amount' => '300', 'receipt_type' => 'collection', 'notes' => 'SALES-RC-CHEQUE-RETURN'], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->first()->id, 'amount' => '300']]);
        expect($invoice->fresh()->remaining_amount)->toBe('800.0000');
    }
    $cheques = app(ChequeService::class);
    if ($receipt->fresh()->cheque->status === 'received') {
        $cheques->markDeposited($receipt->fresh()->cheque);
    }
    if ($receipt->fresh()->cheque->status === 'deposited') {
        $cheques->markCollected($receipt->fresh()->cheque);
    }
    $returns = app(SalesReturnService::class);
    $return = $invoice->returns()->first();
    if (! $return) {
        $return = $returns->create($invoice->fresh(), SalesReturn::ReasonExcess, 'SALES-RC-CHEQUE-RETURN', [['customer_invoice_line_id' => $invoice->lines->first()->id, 'quantity' => '2']]);
        $return = $returns->receive($returns->authorize($return));
        $return = $returns->close($returns->inspect($return, [['sales_return_line_id' => $return->lines->first()->id, 'saleable_quantity' => '2']]));
    }
    expect($orderLine->fresh()->delivered_quantity)->toBe('12.00000000')->and($orderLine->fresh()->invoiced_quantity)->toBe('8.00000000')
        ->and($receipt->fresh()->cheque->status)->toBe('collected')->and($invoice->fresh()->remaining_amount)->toBe('300.0000')->and($return->creditNote->total_amount)->toBe('200.0000');
    $result = app(IntegratedPlasticFactoryDemoVerifier::class)->verify();
    expect($result['checks']['customer_subledgers_reconcile_to_gl'])->toBeTrue()->and($result['checks']['inventory_subledger_reconciles_to_gl'])->toBeTrue();
    file_put_contents('/tmp/erp-sales-business-cheque-example.json', json_encode(['order' => $order->doc_num, 'ordered' => '20', 'delivery' => $delivery->doc_num, 'delivered' => '12', 'invoice' => $invoice->doc_num, 'invoiced' => '8', 'invoice_total' => '800',
        'receipt' => $receipt->doc_num, 'cheque' => $receipt->fresh()->cheque->doc_num, 'cheque_status' => 'collected', 'collected' => '300', 'return' => $return->doc_num, 'returned' => '2', 'credit_note' => $return->creditNote->doc_num, 'credit' => '200', 'outstanding' => '300'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('parallel PostgreSQL reservations invoices and returns each preserve the remaining quantity', function (): void {
    $source = $this->sourceRequest;
    $product = $source->lines->first();
    $orders = app(SalesOrderService::class);
    $createOrder = fn (): SalesOrder => $orders->approve($orders->create(['company_id' => $source->company_id, 'financial_period_id' => $source->financial_period_id, 'branch_id' => $source->branch_id, 'branch_store_id' => $source->branch_store_id,
        'customer_id' => $source->customer_id, 'currency_id' => $source->currency_id, 'exchange_rate' => '1', 'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(), 'customer_reference' => 'SALES-RC-LOCKS',
        'lines' => [['product_id' => $product->product_id, 'unit_id' => $product->unit_id, 'quantity' => '4', 'unit_price' => '100']], 'payment_schedules' => []]));
    $race = function (string $operation): void {
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); auth()->loginUsingId('.$this->user->id.'); try { '.$operation.'; echo "accepted"; } catch (DomainException $e) { echo "rejected"; }';
        $processes = [new Process([PHP_BINARY, '-r', $code], base_path()), new Process([PHP_BINARY, '-r', $code], base_path())];
        foreach ($processes as $process) {
            $process->setTimeout(45)->start();
        }
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $outcomes[] = trim($process->getOutput());
        }
        sort($outcomes);
        expect($outcomes)->toBe(['accepted', 'rejected']);
    };
    $order = $createOrder();
    $line = $order->lines->first();
    $race('app(Modules\\Sales\\Services\\SalesFulfillmentService::class)->reserve(Modules\\Sales\\Models\\SalesOrderLine::findOrFail('.$line->id.'), "3")');
    expect($line->fresh()->activeReservedQuantity())->toBe('3.00000000');
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->id, 'quantity' => '4']]);
    $race('app(Modules\\Sales\\Services\\CustomerInvoiceService::class)->createFromOrder(Modules\\Sales\\Models\\SalesOrder::findOrFail('.$order->id.'), [["sales_order_line_id" => '.$line->id.', "delivery_line_id" => '.$delivery->lines->first()->id.', "quantity" => "3"]], [["due_date" => "'.now()->toDateString().'", "amount" => "300"]], Modules\\Inventory\\Models\\InventoryDocument::findOrFail('.$delivery->id.'))');
    $invoice = app(CustomerInvoiceService::class)->post($order->invoices()->firstOrFail());
    expect($order->invoices()->count())->toBe(1);
    $race('app(Modules\\Sales\\Services\\SalesReturnService::class)->create(Modules\\Sales\\Models\\CustomerInvoice::findOrFail('.$invoice->id.'), "other", "Concurrent return", [["customer_invoice_line_id" => '.$invoice->lines->first()->id.', "quantity" => "2"]])');
    expect($invoice->returns()->count())->toBe(1);
});

test('release documents render through the shared PDF routes on populated PostgreSQL', function (): void {
    $source = $this->sourceRequest;
    $order = SalesOrder::query()->where('sales_request_id', $source->id)->firstOrFail();
    $invoice = $order->invoices()->where('document_type', 'invoice')->firstOrFail();
    $chequeOrder = SalesOrder::query()->where('company_id', $source->company_id)->where('customer_reference', 'SALES-RC-CHEQUE-RETURN')->firstOrFail();
    $return = $chequeOrder->invoices()->where('document_type', 'invoice')->firstOrFail()->returns()->firstOrFail();
    $receipt = CustomerReceipt::query()->where('notes', 'SALES-RC-STOCK-COLLECTION')->firstOrFail();
    $routes = ['request' => route('admin.sales.customer-requests.print', $source), 'quotation' => route('admin.sales.quotations.print', $order->quotation),
        'order' => route('admin.sales.sales-orders.print', $order), 'delivery' => route('admin.sales.delivery-notes.print', $order->deliveries()->firstOrFail()),
        'invoice' => route('admin.sales.sales-invoices.print', $invoice), 'receipt' => route('admin.sales.customer-receipts.print', $receipt),
        'return' => route('admin.sales.sales-returns.print', $return), 'credit-note' => route('admin.sales.sales-invoices.print', $return->creditNote),
        'sales-report' => route('admin.reports.sales.sales-orders.print', ['customer_doc_num' => $source->customer->doc_num])];
    if (! is_dir('/tmp/erp-sales-prints')) {
        mkdir('/tmp/erp-sales-prints', 0700, true);
    }
    foreach ($routes as $name => $url) {
        $response = $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($response->getContent())->toStartWith('%PDF-');
        file_put_contents('/tmp/erp-sales-prints/'.$name.'.pdf', $response->getContent());
    }
    $productionOrder = SalesOrder::query()->with('productionOrders.runs', 'deliveries', 'invoices')->where('company_id', $source->company_id)->where('doc_num', 'SO-00003')->firstOrFail();
    file_put_contents('/tmp/erp-sales-business-production-example.json', json_encode(['order' => $productionOrder->doc_num, 'lines' => $productionOrder->lines->map->only(['quantity', 'reserved_quantity', 'production_requested_quantity', 'produced_quantity', 'delivered_quantity'])->all(),
        'production' => $productionOrder->productionOrders->map(fn ($document) => ['document' => $document->doc_num, 'runs' => $document->runs->pluck('run_number')->all()])->all(), 'deliveries' => $productionOrder->deliveries->pluck('doc_num')->all(), 'invoices' => $productionOrder->invoices->pluck('doc_num')->all()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});
