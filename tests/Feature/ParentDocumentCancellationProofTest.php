<?php

use App\Services\DocumentOwnerEffectProofService;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\CustomerReceiptSettlementService;
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesCycleAuditService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

/** @return array<string, mixed> */
function parentCancellationFixture(): array
{
    $f = salesCycleFixture();
    foreach (['sales_orders.cancel', 'customer_invoices.cancel'] as $ability) {
        $f['user']->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    test()->actingAs($f['user'])->withSession(salesCycleSession($f));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($f));
    $orders = app(SalesOrderService::class);
    $f['order'] = $orders->approve($orders->create(salesCycleOrderPayload($f)));

    return $f;
}

test('parent sales cancellation accepts audited draft inverse with zero source effects and preserves child history', function (string $locale): void {
    $f = parentCancellationFixture();
    app()->setLocale($locale);
    $order = $f['order'];
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->createFromOrder($order, [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '100']],
        [['amount' => '1000', 'due_date' => now()->toDateString()]]);
    $original = $invoice->lines->map->getRawOriginal()->all();
    $ledger = [JournalEntry::count(), InventoryTransaction::count()];
    expect($order->fresh()->canCancelSafely())->toBeFalse();
    $invoices->cancelDraft($invoice, 'SYNTHETIC unused child withdrawn');
    expect($order->fresh()->canCancelSafely())->toBeTrue()->and($order->fresh()->hasDownstreamDocuments())->toBeTrue()
        ->and($order->fresh()->canReplaceUnexecutedLines())->toBeFalse();
    $this->postJson(route('admin.sales.sales-orders.cancel', $order), ['reason' => 'SYNTHETIC root withdrawn'])->assertOk();
    $cancelled = $order->fresh();
    expect(app(DocumentOwnerEffectProofService::class)->cancelledSalesOrderIsSettled($cancelled))->toBeTrue(json_encode([
        'status' => $cancelled->status, 'cancelled_by' => $cancelled->cancelled_by, 'cancelled_at' => $cancelled->cancelled_at,
        'reason' => $cancelled->cancel_reason, 'invoice_settled' => app(DocumentOwnerEffectProofService::class)->salesInvoiceIsSettled($invoice->fresh()),
        'native_audit_count' => DB::table('activity_log')->where('subject_type', SalesOrder::class)->where('subject_id', $order->id)->where('event', 'sales_order.cancelled')->count(),
    ], JSON_THROW_ON_ERROR));
    app(SalesOrderService::class)->cancel($order->fresh(), 'SYNTHETIC duplicate');
    expect($order->fresh()->status)->toBe(SalesOrder::StatusCancelled)
        ->and($invoice->fresh()->lines->map->getRawOriginal()->all())->toBe($original)
        ->and($order->fresh()->invoices()->withTrashed()->sole()->id)->toBe($invoice->id)
        ->and($order->lines->first()->fresh()->invoiced_quantity)->toBe('0.00000000')
        ->and([JournalEntry::count(), InventoryTransaction::count()])->toBe($ledger)
        ->and(DB::table('activity_log')->where('event', 'sales_order.cancelled')->where('subject_id', $order->id)->count())->toBe(1);
})->with(['ar', 'en']);

test('parent sales cancellation rejects a status-only child or altered released quantity proof', function (): void {
    $f = parentCancellationFixture();
    $invoice = app(CustomerInvoiceService::class)->createFromOrder($f['order'],
        [['sales_order_line_id' => $f['order']->lines->first()->id, 'quantity' => '100']],
        [['amount' => '1000', 'due_date' => now()->toDateString()]]);
    $invoice->update(['status' => CustomerInvoice::StatusCancelled, 'posting_status' => 'cancelled']);
    $f['order']->lines->first()->update(['invoiced_quantity' => '0', 'invoiced_base_quantity' => '0']);
    expect($f['order']->fresh()->canCancelSafely())->toBeFalse();
    $invoice->update(['status' => CustomerInvoice::StatusDraft, 'posting_status' => 'unposted']);
    $f['order']->lines->first()->update(['invoiced_quantity' => '100', 'invoiced_base_quantity' => '100']);
    app(CustomerInvoiceService::class)->cancelDraft($invoice->fresh(), 'SYNTHETIC native cancellation');
    $invoice->lines->sole()->update(['quantity' => '99']);
    expect($f['order']->fresh()->canCancelSafely())->toBeFalse()
        ->and(fn () => app(SalesOrderService::class)->cancel($f['order']->fresh(), 'SYNTHETIC invalid lineage'))->toThrow(DomainException::class)
        ->and($f['order']->fresh()->status)->toBe(SalesOrder::StatusApproved);
});

test('request order cancellation releases native conversion once and audit failure restores both owners', function (): void {
    $f = parentCancellationFixture();
    createSalesPriceList($f, null, [['product' => $f['service'], 'price' => '100']]);
    $requests = app(SalesRequestService::class);
    $source = $requests->save(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1']]]);
    $requests->transition($source, 'submitted');
    $requests->transition($source->fresh(), 'approved');
    $selection = [['public_id' => $source->fresh()->lines->sole()->public_id, 'quantity' => '1']];
    $order = $requests->convert($source->fresh(), 'order', $selection);
    $closedAt = $source->fresh()->closed_at->toISOString();
    $orders = app(SalesOrderService::class);
    $orders->cancel($order, 'SYNTHETIC source conversion withdrawn');
    $orders->cancel($order->fresh(), 'SYNTHETIC retry');
    expect($source->fresh()->status)->toBe('approved')
        ->and($source->fresh()->lines->sole()->converted_quantity)->toBe('0.00000000')
        ->and($source->fresh()->closed_at)->toBeNull()
        ->and(json_decode(DB::table('activity_log')->where('event', 'sales_request.conversion_reversed')->where('subject_id', $source->id)->sole()->properties, true)['closed_at_before'])->toBe($closedAt)
        ->and(DB::table('activity_log')->where('event', 'sales_request.conversion_reversed')->where('subject_id', $source->id)->count())->toBe(1);
    $replacement = $requests->convert($source->fresh(), 'order', $selection);
    $this->mock(SalesCycleAuditService::class)->shouldReceive('record')->withArgs(fn ($subject, $event): bool => $event === 'sales_request.conversion_reversed')
        ->once()->andReturnNull();
    app(SalesCycleAuditService::class)->shouldReceive('record')->withArgs(fn ($subject, $event): bool => $event === 'sales_order.cancelled')
        ->once()->andThrow(new RuntimeException('SYNTHETIC audit failure'));
    expect(fn () => app(SalesOrderService::class)->cancel($replacement, 'SYNTHETIC atomic rollback'))->toThrow(RuntimeException::class)
        ->and($replacement->fresh()->status)->toBe(SalesOrder::StatusDraft)
        ->and($source->fresh()->lines->sole()->converted_quantity)->toBe('1.00000000');
});

test('approved unused parent cancellation retains permission open-period and operating-scope guards', function (): void {
    $f = parentCancellationFixture();
    $order = $f['order'];
    $f['user']->revokePermissionTo('sales_orders.cancel');
    $this->postJson(route('admin.sales.sales-orders.cancel', $order), ['reason' => 'SYNTHETIC unauthorized'])->assertForbidden();
    $f['period']->update(['is_closed' => true]);
    expect(fn () => app(SalesOrderService::class)->cancel($order, 'SYNTHETIC closed'))->toThrow(DomainException::class)
        ->and($order->fresh()->status)->toBe(SalesOrder::StatusApproved);
    $f['period']->update(['is_closed' => false]);
    request()->session()->put(OperatingContextService::BranchIdKey, 99999);
    expect(fn () => app(SalesOrderService::class)->cancel($order, 'SYNTHETIC wrong branch'))->toThrow(DomainException::class)
        ->and($order->fresh()->status)->toBe(SalesOrder::StatusApproved);
});

test('converted quote cancellation preserves order and revision history and releases only its original request commitment', function (): void {
    $f = parentCancellationFixture();
    createSalesPriceList($f, null, [['product' => $f['service'], 'price' => '100']]);
    $requests = app(SalesRequestService::class);
    $source = $requests->save(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '2']]]);
    $requests->transition($source, 'submitted');
    $requests->transition($source->fresh(), 'approved');
    $quote = $requests->convert($source->fresh(), 'quotation', [['public_id' => $source->fresh()->lines->sole()->public_id, 'quantity' => '2']]);
    $quotes = app(QuotationService::class);
    $quote = $quotes->accept($quotes->markSent($quote));
    $orders = app(SalesOrderService::class);
    $order = $orders->createFromQuotation($quote, ['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id]);
    expect($quote->fresh()->status)->toBe('converted')->and($quote->fresh()->canCancel())->toBeFalse();
    $original = $quote->currentRevision->lines->map->getRawOriginal()->all();
    $orders->cancel($order, 'SYNTHETIC quote-owned order withdrawn');
    expect($source->fresh()->lines->sole()->converted_quantity)->toBe('2.00000000')->and($quote->fresh()->canCancel())->toBeTrue();
    $quotes->cancel($quote->fresh(), 'SYNTHETIC quotation withdrawn');
    $quotes->cancel($quote->fresh(), 'SYNTHETIC retry');
    expect($quote->fresh()->status)->toBe('cancelled')->and($source->fresh()->status)->toBe('approved')
        ->and($source->fresh()->lines->sole()->converted_quantity)->toBe('0.00000000')
        ->and($quote->fresh()->currentRevision->lines->map->getRawOriginal()->all())->toBe($original)
        ->and($quote->fresh()->salesOrders()->withTrashed()->sole()->id)->toBe($order->id)
        ->and(DB::table('activity_log')->where('event', 'sales_request.conversion_reversed')->where('subject_id', $source->id)->count())->toBe(1);
});

test('partial quote cancellation restores conversion capacity only for proved cancelled child orders', function (): void {
    $f = parentCancellationFixture();
    createSalesPriceList($f, null, [['product' => $f['service'], 'price' => '100']]);
    $requests = app(SalesRequestService::class);
    $source = $requests->save(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '2']]]);
    $requests->transition($source, 'submitted');
    $requests->transition($source->fresh(), 'approved');
    $quote = $requests->convert($source->fresh(), 'quotation', [['public_id' => $source->fresh()->lines->sole()->public_id, 'quantity' => '2']]);
    $quotes = app(QuotationService::class);
    $quote = $quotes->accept($quotes->markSent($quote));
    $orders = app(SalesOrderService::class);
    $context = ['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id];
    $selection = [['public_id' => $quote->currentRevision->lines->sole()->public_uuid, 'quantity' => '1']];
    $first = $orders->createFromQuotation($quote, $context, $selection);
    $orders->cancel($first, 'SYNTHETIC partial order withdrawn');
    $replacement = $orders->createFromQuotation($quote->fresh(), $context, $selection);
    expect($replacement->lines->sole()->quantity)->toBe('1.00000000')->and($quote->fresh()->status)->toBe('accepted')
        ->and($source->fresh()->lines->sole()->converted_quantity)->toBe('2.00000000')
        ->and($quote->fresh()->canCancel())->toBeFalse();
    $orders->cancel($replacement, 'SYNTHETIC replacement withdrawn');
    $audit = DB::table('activity_log')->where('event', 'sales_request.converted')->where('subject_id', $source->id)->sole();
    $payload = json_decode($audit->properties, true);
    unset($payload['conversion_lines']);
    DB::table('activity_log')->where('id', $audit->id)->update(['properties' => json_encode($payload)]);
    expect($quote->fresh()->canCancel())->toBeFalse()
        ->and(fn () => $quotes->cancel($quote->fresh(), 'SYNTHETIC unsupported historical commitment'))->toThrow(DomainException::class)
        ->and($source->fresh()->lines->sole()->converted_quantity)->toBe('2.00000000');
});

test('posted goods order cancellation requires the independent invoice recovery and exact collection inverse while retaining original ledgers', function (): void {
    $f = parentCancellationFixture();
    foreach (['customer_invoices.correct_prepare', 'customer_invoices.correct_approve', 'customer_receipts.cancel'] as $key) {
        $f['user']->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    $line = $f['order']->lines->first();
    $delivery = app(SalesFulfillmentService::class)->deliver($f['order'], [['sales_order_line_id' => $line->id, 'quantity' => '100']]);
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($f['order']->fresh(),
        [['sales_order_line_id' => $line->id, 'delivery_line_id' => $delivery->lines->sole()->id, 'quantity' => '100']], [['amount' => '1000', 'due_date' => now()->toDateString()]], $delivery));
    $receipt = app(CustomerReceiptService::class)->createAndApprove(['company_id' => $f['company']->id,
        'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id, 'sales_order_id' => $f['order']->id, 'customer_id' => $f['customer']->id,
        'receipt_date' => now()->toDateString(), 'currency_id' => $f['currency']->id, 'exchange_rate' => '1', 'payment_method' => 'bank_transfer',
        'bank_account_id' => $f['bankAccount']->id, 'amount' => '100', 'receipt_type' => 'collection'],
        [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules()->sole()->id, 'amount' => '100']]);
    expect($f['order']->fresh()->canCancelSafely())->toBeFalse();
    $receipt = app(CustomerReceiptSettlementService::class)->reverse($receipt, 'SYNTHETIC recovered payment owner');
    $proof = app(DocumentOwnerEffectProofService::class);
    expect($proof->customerReceiptIsSettled($receipt))->toBeTrue();
    $corrections = app(CustomerInvoiceCorrectionService::class);
    $proposal = $corrections->prepare($invoice, ['source_fingerprint' => $corrections->preview($invoice)['fingerprint'],
        'reason' => 'SYNTHETIC goods error recovered', 'posting_date' => now()->toDateString(), 'recovery_reference' => 'SYNTHETIC physical goods count ref 004']);
    $reviewer = closureSyntheticUser();
    $reviewer->givePermissionTo(Permission::findOrCreate('customer_invoices.correct_approve', 'web'));
    $this->actingAs($reviewer)->withSession(salesCycleSession($f));
    request()->setUserResolver(fn () => $reviewer);
    request()->attributes->replace([]);
    $corrections->approve($invoice, $proposal->id, 'SYNTHETIC independent physical and financial recovery review');
    expect($proof->salesInvoiceIsSettled($invoice->fresh()))->toBeTrue()->and($f['order']->fresh()->canCancelSafely())->toBeTrue();
    $inverse = JournalEntry::query()->findOrFail($receipt->fresh()->reversal_journal_entry_id)->lines->first();
    $originalInverse = $inverse->getRawOriginal();
    $amount = $inverse->debit_amount;
    $inverse->update(['debit_amount' => bcadd((string) $amount, '1', 4)]);
    expect($f['order']->fresh()->canCancelSafely())->toBeFalse();
    DB::table($inverse->getTable())->where('id', $inverse->id)->update($originalInverse);
    expect($f['order']->fresh()->canCancelSafely())->toBeTrue();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    request()->setUserResolver(fn () => $f['user']);
    request()->attributes->replace([]);
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    app(SalesOrderService::class)->cancel($f['order']->fresh(), 'SYNTHETIC final root cancellation');
    expect($f['order']->fresh()->status)->toBe('cancelled')->and([InventoryTransaction::count(), JournalEntry::count()])->toBe($before)
        ->and($invoice->fresh()->status)->toBe('posted')->and($delivery->fresh()->status)->toBe('reversed')
        ->and($invoice->fresh()->creditNotes()->count())->toBe(1);
});
