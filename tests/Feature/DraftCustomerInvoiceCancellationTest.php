<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesCycleAuditService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

/** @return array<string, mixed> */
function draftCancellationFixture(bool $fromOrder = false): array
{
    $f = salesCycleFixture();
    foreach (['customer_invoices.view', 'customer_invoices.view_prices', 'customer_invoices.cancel'] as $ability) {
        $f['user']->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    test()->actingAs($f['user'])->withSession(salesCycleSession($f));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($f));
    $service = app(CustomerInvoiceService::class);
    if ($fromOrder) {
        $orders = app(SalesOrderService::class);
        $f['order'] = $orders->approve($orders->create(salesCycleOrderPayload($f)));
        $f['invoice'] = $service->createFromOrder($f['order'],
            [['sales_order_line_id' => $f['order']->lines->first()->id, 'quantity' => '100']],
            [['amount' => '1000', 'due_date' => now()->toDateString()]]);
    } else {
        createSalesPriceList($f, null, [['product' => $f['service'], 'price' => '100']]);
        $f['invoice'] = $service->createDirect([...salesCycleOrderPayload($f),
            'customer_doc_num' => $f['customer']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
            'invoice_date' => now()->toDateString(),
            'lines' => [['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
                'quantity' => '1', 'discount_amount' => '0', 'tax_amount' => '0']],
            'payment_schedules' => [['amount' => '100', 'due_date' => now()->toDateString()]]]);
    }

    return $f;
}

test('draft invoice cancellation retains original rows and releases order quantities once without posting', function (string $locale, bool $fromOrder): void {
    $f = draftCancellationFixture($fromOrder);
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $invoice = $f['invoice'];
    $lines = $invoice->lines->map->getRawOriginal()->all();
    $schedules = $invoice->paymentSchedules->map->getRawOriginal()->all();
    $before = [JournalEntry::count(), InventoryTransaction::count()];
    $url = route('admin.sales.sales-invoices.cancel-draft', $invoice);
    $this->get(route('admin.sales.sales-invoices.show', $invoice))->assertOk()->assertSee($url, false);
    $this->postJson($url, ['reason' => 'SYNTHETIC unused draft withdrawn', '_submission_token' => (string) Str::uuid()])->assertOk();
    $this->postJson($url, ['reason' => 'SYNTHETIC retry', '_submission_token' => (string) Str::uuid()])->assertOk();
    expect($invoice->fresh()->status)->toBe(CustomerInvoice::StatusCancelled)
        ->and($invoice->fresh()->posting_status)->toBe('cancelled')->and($invoice->fresh()->trashed())->toBeFalse()
        ->and($invoice->fresh()->journal_entry_id)->toBeNull()->and($invoice->fresh()->reversal_journal_entry_id)->toBeNull()
        ->and($invoice->fresh()->cancel_reason)->toBe('SYNTHETIC unused draft withdrawn')
        ->and($invoice->fresh()->lines->map->getRawOriginal()->all())->toBe($lines)
        ->and($invoice->fresh()->paymentSchedules->map->getRawOriginal()->all())->toBe($schedules)
        ->and([JournalEntry::count(), InventoryTransaction::count()])->toBe($before)
        ->and(DB::table('activity_log')->where('event', 'customer_invoice.draft_cancelled')->where('subject_id', $invoice->id)->count())->toBe(1);
    expect(fn () => app(CustomerInvoiceService::class)->post($invoice->fresh()))->toThrow(DomainException::class);
    if ($fromOrder) {
        expect($f['order']->lines->first()->fresh()->invoiced_quantity)->toBe('0.00000000');
        $replacement = app(CustomerInvoiceService::class)->createFromOrder($f['order']->fresh(),
            [['sales_order_line_id' => $f['order']->lines->first()->id, 'quantity' => '100']],
            [['amount' => '1000', 'due_date' => now()->toDateString()]]);
        expect($replacement->total_amount)->toBe('1000.0000')
            ->and($f['order']->lines->first()->fresh()->invoiced_quantity)->toBe('100.00000000');
    }
})->with([['ar', false], ['en', false], ['ar', true], ['en', true]]);

test('draft cancellation rejects missing reason unauthorized closed period and forged posting state without releasing source', function (): void {
    $f = draftCancellationFixture(true);
    $invoice = $f['invoice'];
    $url = route('admin.sales.sales-invoices.cancel-draft', $invoice);
    $payload = ['reason' => 'SYNTHETIC guarded draft', '_submission_token' => (string) Str::uuid()];
    $this->postJson($url, ['_submission_token' => (string) Str::uuid()])->assertUnprocessable();
    $f['user']->revokePermissionTo('customer_invoices.cancel');
    $this->postJson($url, $payload)->assertForbidden();
    $f['user']->givePermissionTo('customer_invoices.cancel');
    $f['period']->update(['is_closed' => true]);
    expect(fn () => app(CustomerInvoiceService::class)->cancelDraft($invoice, 'SYNTHETIC closed'))->toThrow(DomainException::class);
    $f['period']->update(['is_closed' => false]);
    $invoice->update(['posting_revision' => 1]);
    expect(fn () => app(CustomerInvoiceService::class)->cancelDraft($invoice->fresh(), 'SYNTHETIC altered'))->toThrow(DomainException::class);
    expect($f['order']->lines->first()->fresh()->invoiced_quantity)->toBe('100.00000000')
        ->and($invoice->fresh()->status)->toBe(CustomerInvoice::StatusDraft)
        ->and(DB::table('activity_log')->where('event', 'customer_invoice.draft_cancelled')->count())->toBe(0);
});

test('draft cancellation audit failure rolls source counters and invoice state back together', function (): void {
    $f = draftCancellationFixture(true);
    $invoice = $f['invoice'];
    $this->mock(SalesCycleAuditService::class)->shouldReceive('record')->once()->withArgs(fn ($subject, $event): bool => $event === 'customer_invoice.draft_cancelled')
        ->andThrow(new RuntimeException('SYNTHETIC audit unavailable'));
    expect(fn () => app(CustomerInvoiceService::class)->cancelDraft($invoice, 'SYNTHETIC rollback'))->toThrow(RuntimeException::class);
    expect($invoice->fresh()->status)->toBe(CustomerInvoice::StatusDraft)->and($invoice->fresh()->cancelled_at)->toBeNull()
        ->and($f['order']->lines->first()->fresh()->invoiced_quantity)->toBe('100.00000000');
});

test('request sourced draft cancellation restores conversion capacity with preserved source lineage and one reversal audit', function (): void {
    $f = draftCancellationFixture();
    $requests = app(SalesRequestService::class);
    $source = $requests->save(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1']]]);
    $requests->transition($source, 'submitted');
    $requests->transition($source->fresh(), 'approved');
    $payload = [...salesCycleOrderPayload($f), 'customer_doc_num' => $f['customer']->doc_num,
        'currency_doc_num' => $f['currency']->doc_num, 'invoice_date' => now()->toDateString(),
        'lines' => [['source_request_line_public_id' => $source->fresh()->lines->sole()->public_id,
            'product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
            'quantity' => '1', 'discount_amount' => '0', 'tax_amount' => '0']],
        'payment_schedules' => [['amount' => '100', 'due_date' => now()->toDateString()]]];
    $service = app(CustomerInvoiceService::class);
    $invoice = $service->createDirect($payload, $source->fresh());
    $original = $invoice->lines->map->getRawOriginal()->all();
    expect($source->fresh()->lines->sole()->converted_quantity)->toBe('1.00000000');
    $service->cancelDraft($invoice, 'SYNTHETIC source request withdrawn');
    $service->cancelDraft($invoice->fresh(), 'SYNTHETIC retry');
    expect($source->fresh()->lines->sole()->converted_quantity)->toBe('0.00000000')
        ->and($source->fresh()->status)->toBe('approved')->and($invoice->fresh()->lines->map->getRawOriginal()->all())->toBe($original)
        ->and(DB::table('activity_log')->where('event', 'sales_request.conversion_reversed')->where('subject_id', $source->id)->count())->toBe(1);
    $replacement = $service->createDirect($payload, $source->fresh());
    expect($replacement->total_amount)->toBe('100.0000')->and($source->fresh()->lines->sole()->converted_quantity)->toBe('1.00000000');
});
