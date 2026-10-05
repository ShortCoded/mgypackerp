<?php

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\SalesCycleAuditService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

/** @return array<string, mixed> */
function directServiceCancellationFixture(): array
{
    $f = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    foreach (['customer_invoices.create', 'customer_invoices.post', 'customer_invoices.cancel', 'customer_invoices.view', 'customer_invoices.view_prices'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $f['user']->givePermissionTo($ability);
    }
    test()->actingAs($f['user'])->withSession(salesCycleSession($f));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($f));
    createSalesPriceList($f, null, [['product' => $f['service'], 'price' => '1000']]);
    $service = app(CustomerInvoiceService::class);
    $f['invoice'] = $service->post($service->createDirect([
        ...salesCycleOrderPayload($f), 'customer_doc_num' => $f['customer']->doc_num,
        'currency_doc_num' => $f['currency']->doc_num, 'invoice_date' => now()->toDateString(),
        'lines' => [['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
            'quantity' => '1', 'discount_amount' => '0', 'tax_amount' => '140']],
        'payment_schedules' => [['title' => 'Full invoice', 'amount' => '1140', 'due_date' => now()->toDateString()]],
    ]));

    return $f;
}

/** @param array<string, mixed> $f @return array<string, mixed> */
function directServiceCancellationReceipt(array $f): array
{
    return ['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'branch_id' => $f['branch']->id, 'customer_id' => $f['customer']->id,
        'receipt_date' => now()->toDateString(), 'currency_id' => $f['currency']->id,
        'exchange_rate' => '1', 'payment_method' => 'cash', 'cashbox_id' => $f['cashbox']->id,
        'amount' => '10', 'receipt_type' => CustomerReceipt::TypeCollection];
}

/** @return array<string, mixed> */
function directServiceCancellationSnapshot(CustomerInvoice $invoice): array
{
    return [$invoice->fresh()->getAttributes(), JournalEntry::query()->count(), InventoryTransaction::query()->count()];
}

test('direct service cancellation reverses the exact native journal once and preserves invoice and source rows', function (string $locale): void {
    $f = directServiceCancellationFixture();
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $invoice = $f['invoice'];
    $original = $invoice->journalEntry;
    $lines = $invoice->lines()->get()->map->getAttributes()->all();
    $schedules = $invoice->paymentSchedules()->get()->map->getAttributes()->all();
    $originalRows = $original->lines()->orderBy('line_no')->get()->map->getAttributes()->all();
    $journalCount = JournalEntry::query()->count();
    $stockCount = InventoryTransaction::query()->count();
    $url = route('admin.sales.sales-invoices.cancel-direct-service', $invoice);
    $this->get(route('admin.sales.sales-invoices.show', $invoice))->assertOk()->assertSee(__('sales_ui.cancel_direct_service_invoice'));
    $this->postJson($url, ['reason' => '  SYNTHETIC service order withdrawn  '])->assertOk();
    $cancelled = $invoice->fresh();
    $reversal = $cancelled->reversalJournalEntry;
    expect($cancelled->status)->toBe('cancelled')->and($cancelled->posting_status)->toBe('cancelled')
        ->and($cancelled->total_amount)->toBe('1140.0000')->and($cancelled->remaining_amount)->toBe('0.0000')
        ->and($cancelled->cancel_reason)->toBe('SYNTHETIC service order withdrawn')
        ->and($cancelled->cancelled_by)->toBe($f['user']->id)->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->posting_revision)->toBe(1)->and($cancelled->isEditable())->toBeFalse()
        ->and($original->fresh()->reversed_entry_id)->toBe($reversal->id)
        ->and($reversal->is_posted)->toBeTrue()->and($reversal->status)->toBe(JournalEntry::StatusPosted)
        ->and($reversal->company_id)->toBe($original->company_id)->and($reversal->branch_id)->toBe($original->branch_id)
        ->and($reversal->financial_period_id)->toBe($original->financial_period_id)
        ->and($reversal->currency_id)->toBe($original->currency_id)->and($reversal->exchange_rate)->toBe($original->exchange_rate)
        ->and($reversal->entry_date->toDateString())->toBe($original->entry_date->toDateString());
    $reversalRows = $reversal->lines()->orderBy('line_no')->get();
    expect($reversalRows)->toHaveCount(count($originalRows));
    foreach ($originalRows as $index => $row) {
        expect($reversalRows[$index]->debit_amount)->toBe(bcadd((string) $row['credit_amount'], '0', 4))
            ->and($reversalRows[$index]->credit_amount)->toBe(bcadd((string) $row['debit_amount'], '0', 4))
            ->and($reversalRows[$index]->account_id)->toBe($row['account_id']);
    }
    $this->postJson($url, ['reason' => 'SYNTHETIC duplicate retry'])->assertOk();
    expect(JournalEntry::query()->count())->toBe($journalCount + 1)
        ->and(InventoryTransaction::query()->count())->toBe($stockCount)
        ->and($original->lines()->orderBy('line_no')->get()->map->getAttributes()->all())->toBe($originalRows)
        ->and($invoice->lines()->get()->map->getAttributes()->all())->toBe($lines)
        ->and($invoice->paymentSchedules()->get()->map->getAttributes()->all())->toBe($schedules)
        ->and($invoice->fresh()->cancel_reason)->toBe('SYNTHETIC service order withdrawn')
        ->and(DB::table('activity_log')->where('subject_type', CustomerInvoice::class)
            ->where('subject_id', $invoice->id)->where('event', 'customer_invoice.cancelled')->count())->toBe(1);
    $this->get(route('admin.sales.sales-invoices.show', $invoice))->assertOk()->assertDontSee('id="direct-service-cancel-reason"', false)
        ->assertDontSee(__('Post Invoice'))->assertDontSee(__('Overdue'))
        ->assertSee('SYNTHETIC service order withdrawn');
    expect(fn () => app(CustomerInvoiceService::class)->post($invoice->fresh()))->toThrow(DomainException::class);
    expect(fn () => app(CustomerReceiptService::class)->createAndApprove(directServiceCancellationReceipt($f), [
        ['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules()->sole()->id, 'amount' => '10'],
    ]))->toThrow(DomainException::class);
})->with(['ar', 'en']);

test('direct service cancellation rejects missing reason and unauthorized callers without journal changes', function (): void {
    $f = directServiceCancellationFixture();
    $before = directServiceCancellationSnapshot($f['invoice']);
    $url = route('admin.sales.sales-invoices.cancel-direct-service', $f['invoice']);
    $this->postJson($url, [])->assertUnprocessable();
    $f['user']->revokePermissionTo('customer_invoices.cancel');
    $this->postJson($url, ['reason' => 'SYNTHETIC unauthorized'])->assertForbidden();
    expect(directServiceCancellationSnapshot($f['invoice']))->toBe($before);
});

test('direct service cancellation fails closed outside the approved state and operating period', function (string $condition): void {
    $f = directServiceCancellationFixture();
    $invoice = $f['invoice'];
    match ($condition) {
        'goods' => $invoice->lines()->update(['is_service' => false]),
        'source' => $invoice->update(['source_type' => 'sales_request']),
        'paid' => $invoice->update(['paid_amount' => '1', 'remaining_amount' => '1139']),
        'credited' => $invoice->update(['credited_amount' => '1', 'remaining_amount' => '1139']),
        'advance' => $invoice->update(['applied_advance_amount' => '1']),
        'draft' => $invoice->update(['status' => 'draft', 'posting_status' => 'unposted']),
        'revision' => $invoice->update(['posting_revision' => 1]),
        'einvoice' => $invoice->update(['electronic_invoice_status' => 'submitted']),
        'einvoice_uuid' => $invoice->update(['electronic_invoice_uuid' => '9c5a1123-a459-426a-899f-7bc265731fd2']),
        'einvoice_date' => $invoice->update(['electronic_invoice_submitted_at' => now()]),
        'closed_period' => $f['period']->update(['is_closed' => true]),
        'context' => $this->withSession([OperatingContextService::BranchIdKey => 0]),
    };
    $before = directServiceCancellationSnapshot($invoice);
    $this->postJson(route('admin.sales.sales-invoices.cancel-direct-service', $invoice), ['reason' => 'SYNTHETIC prohibited cancellation'])->assertUnprocessable();
    expect(directServiceCancellationSnapshot($invoice))->toBe($before);
})->with(['goods', 'source', 'paid', 'credited', 'advance', 'draft', 'revision', 'einvoice', 'einvoice_uuid', 'einvoice_date', 'closed_period', 'context']);

test('direct service cancellation rolls back the journal and invoice when its audit fails', function (): void {
    $f = directServiceCancellationFixture();
    $invoice = $f['invoice'];
    $before = [directServiceCancellationSnapshot($invoice), $invoice->journalEntry->getAttributes()];
    $audit = Mockery::mock(SalesCycleAuditService::class);
    $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('SYNTHETIC audit unavailable'));
    app()->instance(SalesCycleAuditService::class, $audit);
    app()->forgetInstance(CustomerInvoiceService::class);
    expect(fn () => app(CustomerInvoiceService::class)->cancelDirectService($invoice, 'SYNTHETIC rollback'))->toThrow(RuntimeException::class, 'SYNTHETIC audit unavailable');
    expect([directServiceCancellationSnapshot($invoice), $invoice->journalEntry->fresh()->getAttributes()])->toBe($before);
});

test('direct service cancellation rejects existing native receipt allocations including pending cheques', function (): void {
    $f = directServiceCancellationFixture();
    $invoice = $f['invoice'];
    app(CustomerReceiptService::class)->createAndApprove([
        ...directServiceCancellationReceipt($f), 'payment_method' => 'cheque', 'cashbox_id' => null, 'bank_account_id' => $f['bankAccount']->id, 'reference_no' => 'SYNTHETIC-UNSETTLED',
        'cheque_due_date' => now()->addDay()->toDateString(), 'external_bank_name' => 'SYNTHETIC BANK',
    ], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules()->sole()->id, 'amount' => '10']]);
    expect($invoice->fresh()->paid_amount)->toBe('0.0000');
    $before = directServiceCancellationSnapshot($invoice);
    $this->postJson(route('admin.sales.sales-invoices.cancel-direct-service', $invoice), ['reason' => 'SYNTHETIC linked cheque'])->assertUnprocessable();
    expect(directServiceCancellationSnapshot($invoice))->toBe($before);
});

test('direct service cancellation rejects native order lineage returns credits and tax submission history', function (string $link): void {
    $f = directServiceCancellationFixture();
    $invoice = $f['invoice'];
    if ($link === 'order') {
        $invoice = salesPostedServiceInvoice($f, '1000', '140');
    } elseif ($link === 'return' || $link === 'credit') {
        $returns = app(SalesReturnService::class);
        $return = $returns->create($invoice, 'other', 'SYNTHETIC cancellation boundary', [
            ['customer_invoice_line_id' => $invoice->lines()->sole()->id, 'quantity' => '1'],
        ]);
        if ($link === 'credit') {
            $returns->close($returns->authorize($return));
        }
    } else {
        $invoice->electronicInvoiceSubmissions()->create([
            'company_id' => $invoice->company_id, 'provider' => 'SYNTHETIC', 'environment' => 'testing',
            'payload_version' => '1', 'payload_hash' => hash('sha256', 'SYNTHETIC rejected submission'),
            'payload' => [], 'status' => 'rejected',
        ]);
    }
    $before = directServiceCancellationSnapshot($invoice);
    $this->postJson(route('admin.sales.sales-invoices.cancel-direct-service', $invoice), ['reason' => 'SYNTHETIC linked cancellation'])->assertUnprocessable();
    expect(directServiceCancellationSnapshot($invoice))->toBe($before);
})->with(['order', 'return', 'credit', 'submission']);
