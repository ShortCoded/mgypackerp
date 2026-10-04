<?php

use App\Services\PostingAccountResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\ReconciliationCenterService;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Sales\Models\CustomerCreditAllocation;
use Modules\Sales\Models\CustomerCreditRefund;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerCreditService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

test('mixed-price quarantine retains return source cost through disposition and independent correction', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_inspected', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_inspected');
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->id);
    $fulfillment = app(SalesFulfillmentService::class);
    $firstDelivery = $fulfillment->deliver($order, [['sales_order_line_id' => $line->id, 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $first = $returns->receive($returns->authorize($returns->createFromDelivery($firstDelivery, SalesReturn::ReasonWrongItem,
        'SYNTHETIC quarantine at five', [['delivery_line_id' => $firstDelivery->lines->sole()->id, 'quantity' => '5']])));
    app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
        'source_stock_status' => InventoryTransaction::StatusAvailable,
        'document_type' => InventoryDocument::TypeAdjustmentIn, 'document_date' => now()->toDateString(),
    ], [['product_id' => $fixture['finished']->id, 'quantity' => '80', 'unit_cost' => '15', 'batch_lot' => 'SALES-OPENING-BATCH']]);
    $secondDelivery = $fulfillment->deliver($order->fresh(), [['sales_order_line_id' => $line->id, 'quantity' => '5']]);
    expect($secondDelivery->transactions->sole()->total_cost)->toBe('50.00000000');
    $second = $returns->receive($returns->authorize($returns->createFromDelivery($secondDelivery, SalesReturn::ReasonWrongItem,
        'SYNTHETIC quarantine at ten', [['delivery_line_id' => $secondDelivery->lines->sole()->id, 'quantity' => '5']])));
    $first = $returns->inspect($first, [['sales_return_line_id' => $first->lines->sole()->id,
        'saleable_quantity' => '2', 'rework_quantity' => '2', 'scrap_quantity' => '1']]);
    expect($first->lines->sole()->original_unit_cost)->toBe('5.00000000')
        ->and($first->lines->sole()->source_snapshot['disposition_costs'])->toBe([
            'saleable_base_quantity' => '10.00000000', 'rework_base_quantity' => '10.00000000', 'scrap_base_quantity' => '5.00000000']);
    $quarantine = app(PostingAccountResolver::class)->resolve($fixture['company']->id,
        PostingAccountResolver::QuarantineInventory, 'SYNTHETIC mixed return');
    $quarantineValue = fn (): string => (string) InventoryTransaction::query()->where('company_id', $fixture['company']->id)
        ->where('branch_store_id', $fixture['store']->id)->where('product_id', $fixture['finished']->id)
        ->where('stock_status', InventoryTransaction::StatusQuarantine)->sum(DB::raw(InventoryTransaction::signedValueSql()));
    $quarantineGl = fn (): string => (string) DB::table('journal_entry_lines')->where('account_id', $quarantine->id)
        ->where('branch_id', $fixture['branch']->id)->sum(DB::raw('debit_amount-credit_amount'));
    expect(bccomp($quarantineValue(), '50', 8))->toBe(0)->and(bccomp($quarantineGl(), '50', 4))->toBe(0);
    $first = $returns->correctInspected($first, 'SYNTHETIC independent mixed-pool correction');
    expect($first->status)->toBe(SalesReturn::StatusCancelled)->and($second->fresh()->status)->toBe(SalesReturn::StatusReceived)
        ->and(bccomp($quarantineValue(), '50', 8))->toBe(0)->and(bccomp($quarantineGl(), '50', 4))->toBe(0);
});

/** @param array<string, mixed> $fixture */
function activateReturnCorrectionContext(array $fixture): void
{
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    request()->session()->put(salesCycleSession($fixture));
}

test('a received unbilled sales return can be corrected by reversing its exact receipt and journal', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_receipt', 'web');
    Permission::findOrCreate('sales_returns.view', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_receipt', 'sales_returns.view');
    expect(app(PermissionRegistryService::class)->all())->toContain('sales_returns.correct_receipt');

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery(
        $delivery,
        SalesReturn::ReasonWrongItem,
        'Documented wrong item',
        [['delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5']],
    )));
    $receipt = $return->returnInventoryDocument;
    $journal = $return->quarantineJournalEntry;
    expect($return->status)->toBe(SalesReturn::StatusReceived)
        ->and($receipt?->status)->toBe(InventoryDocument::StatusPosted)
        ->and($journal)->not->toBeNull()
        ->and($line->fresh()->delivered_quantity)->toBe('15.00000000')
        ->and($line->fresh()->returned_quantity)->toBe('5.00000000');
    $this->get(route('admin.sales.sales-returns.show', $return))
        ->assertOk()
        ->assertSee(route('admin.sales.sales-returns.correct-receipt', $return), false)
        ->assertSee(__('sales_return_correction.explanation'));

    $response = $this->post(route('admin.sales.sales-returns.correct-receipt', $return), [
        'reason' => 'Received against the wrong return; replace with a corrected document.',
    ]);
    $response->assertOk();
    $return->refresh();
    expect($return->status)->toBe(SalesReturn::StatusCancelled)
        ->and($return->cancel_reason)->toContain('wrong return')
        ->and($receipt->fresh()->status)->toBe(InventoryDocument::StatusReversed)
        ->and($journal->fresh()->reversed_entry_id)->not->toBeNull()
        ->and(JournalEntry::query()->find($journal->fresh()->reversed_entry_id)?->source_type)->toBe('sales_return_quarantine_reversal')
        ->and($line->fresh()->delivered_quantity)->toBe('20.00000000')
        ->and($line->fresh()->returned_quantity)->toBe('0.00000000')
        ->and($order->fresh()->status)->toBe(SalesOrder::StatusPartiallyFulfilled)
        ->and((string) InventoryTransaction::query()->where('product_id', $fixture['finished']->getKey())->sum(DB::raw('quantity_in - quantity_out')))->toBe('80');
    $this->get(route('admin.sales.sales-returns.show', $return))
        ->assertOk()
        ->assertDontSee(route('admin.sales.sales-returns.correct-receipt', $return), false);

    $this->post(route('admin.sales.sales-returns.correct-receipt', $return), ['reason' => 'Repeat'])->assertUnprocessable();
    expect(InventoryTransaction::query()->where('source_id', $receipt->getKey())->where('source_type', InventoryDocument::class)->where('is_reversal', true)->count())->toBe(1);

    $replacement = $returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, 'Corrected document', [[
        'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5',
    ]]);
    expect($replacement->status)->toBe(SalesReturn::StatusPendingAuthorization);
});

test('received return correction is denied without its specific permission and after quality disposition', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery(
        $delivery,
        SalesReturn::ReasonWrongItem,
        null,
        [['delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5']],
    )));
    $url = route('admin.sales.sales-returns.correct-receipt', $return);
    $this->post($url, ['reason' => 'Unpermitted'])->assertForbidden();
    Permission::findOrCreate('sales_returns.correct_receipt', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_receipt');
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 9901,
        'doc_num' => 'BRANCH-OTHER-RETURN', 'name' => 'Other branch', 'type' => Branch::TypeFactory,
    ]);
    $this->withSession([
        OperatingContextService::BranchIdKey => $otherBranch->getKey(),
        OperatingContextService::BranchDocNumKey => $otherBranch->doc_num,
    ])->post($url, ['reason' => 'Wrong branch'])->assertNotFound();
    $this->withSession(salesCycleSession($fixture));
    $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(), 'quarantine_quantity' => '5',
    ]]);

    $this->post($url, ['reason' => 'Too late'])->assertUnprocessable();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusInspected)
        ->and($return->returnInventoryDocument->fresh()->status)->toBe(InventoryDocument::StatusPosted);
});

test('correcting a received invoiced return preserves its customer balance and original invoice', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_receipt', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_receipt');

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $line->getKey(),
        'delivery_line_id' => $delivery->lines->first()->getKey(),
        'quantity' => '20',
    ]], [['due_date' => now()->addWeek()->toDateString(), 'amount' => '200']], $delivery));
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->create($invoice, SalesReturn::ReasonWrongItem, null, [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $beforeBalance = $invoice->fresh()->remaining_amount;
    $beforeCredited = $invoice->fresh()->credited_amount;
    $beforeDelivered = $line->fresh()->delivered_quantity;

    $this->post(route('admin.sales.sales-returns.correct-receipt', $return), ['reason' => 'Correct receipt and re-enter the return.'])->assertOk();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusCancelled)
        ->and($return->fresh()->credit_note_id)->toBeNull()
        ->and($invoice->fresh()->remaining_amount)->toBe($beforeBalance)
        ->and($invoice->fresh()->credited_amount)->toBe($beforeCredited)
        ->and($line->fresh()->delivered_quantity)->toBe($beforeDelivered)
        ->and($return->returnInventoryDocument->fresh()->status)->toBe(InventoryDocument::StatusReversed);
    expect($returns->create($invoice, SalesReturn::ReasonWrongItem, 'Replacement', [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '5',
    ]])->status)->toBe(SalesReturn::StatusPendingAuthorization);
});

test('received return correction refuses a closed receipt period without changing stock or ledgers', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, null, [[
        'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $receipt = $return->returnInventoryDocument;
    $journal = $return->quarantineJournalEntry;
    $fixture['period']->update(['is_closed' => true]);

    expect(fn (): SalesReturn => $returns->correctReceived($return, 'Correction after period close'))
        ->toThrow(DomainException::class);
    expect($return->fresh()->status)->toBe(SalesReturn::StatusReceived)
        ->and($receipt->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($journal?->fresh()->reversed_entry_id)->toBeNull()
        ->and($line->fresh()->returned_quantity)->toBe('5.00000000');
});

test('a later replacement delivery blocks correcting its earlier received return', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    InventoryTransaction::query()->where('posting_key', 'sales-cycle-opening-stock')->update([
        'quantity_in' => '200', 'total_cost' => '1000',
    ]);
    Permission::findOrCreate('sales_returns.correct_receipt', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_receipt');
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $fulfillment = app(SalesFulfillmentService::class);
    $delivery = $fulfillment->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, null, [[
        'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $fulfillment->deliver($order->fresh(), [['sales_order_line_id' => $line->getKey(), 'quantity' => '85']]);
    $receipt = $return->returnInventoryDocument;
    $journal = $return->quarantineJournalEntry;

    $this->post(route('admin.sales.sales-returns.correct-receipt', $return), ['reason' => 'Replacement was already delivered.'])->assertUnprocessable();
    expect($line->fresh()->delivered_quantity)->toBe('100.00000000')
        ->and($line->fresh()->returned_quantity)->toBe('5.00000000')
        ->and($return->fresh()->status)->toBe(SalesReturn::StatusReceived)
        ->and($receipt->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($journal?->fresh()->reversed_entry_id)->toBeNull();
});

test('a valid sub-cent return without a posted quarantine journal can still be corrected', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_receipt', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_receipt');
    InventoryTransaction::query()->where('posting_key', 'sales-cycle-opening-stock')->update([
        'unit_cost' => '0.00001000', 'total_cost' => '0.00100000',
    ]);
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, null, [[
        'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '1',
    ]])));
    expect($return->lines->first()->original_unit_cost)->toBe('0.00001000')
        ->and($return->quarantine_journal_entry_id)->toBeNull();

    $this->post(route('admin.sales.sales-returns.correct-receipt', $return), ['reason' => 'Correct tiny-cost return.'])->assertOk();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusCancelled)
        ->and($return->returnInventoryDocument->fresh()->status)->toBe(InventoryDocument::StatusReversed);
});

test('an inspected uncredited return reverses every quality disposition before its original receipt', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_disposition', 'web');
    Permission::findOrCreate('sales_returns.view', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_disposition', 'sales_returns.view');

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery(
        $delivery, SalesReturn::ReasonWrongItem, null,
        [['delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5']],
    )));
    $receipt = $return->returnInventoryDocument;
    $return = $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(),
        'saleable_quantity' => '2', 'quarantine_quantity' => '1',
        'rework_quantity' => '1', 'scrap_quantity' => '1',
    ]]);
    $dispositions = InventoryDocument::query()
        ->where('source_document_type', SalesReturn::class)
        ->where('source_document_id', $return->getKey())
        ->where('document_type', InventoryDocument::TypeTransfer)
        ->get();
    expect($dispositions)->toHaveCount(3)
        ->and($return->dispositionJournalEntry)->not->toBeNull();

    $url = route('admin.sales.sales-returns.correct-disposition', $return);
    $this->get(route('admin.sales.sales-returns.show', $return))
        ->assertOk()
        ->assertSee($url, false);
    $this->post($url, ['reason' => 'Quality disposition entered against the wrong return.'])->assertOk();

    expect($return->fresh()->status)->toBe(SalesReturn::StatusCancelled)
        ->and($receipt->fresh()->status)->toBe(InventoryDocument::StatusReversed)
        ->and($dispositions->every(fn (InventoryDocument $document): bool => $document->fresh()->status === InventoryDocument::StatusReversed))->toBeTrue()
        ->and($return->dispositionJournalEntry->fresh()->reversed_entry_id)->not->toBeNull()
        ->and($return->quarantineJournalEntry->fresh()->reversed_entry_id)->not->toBeNull()
        ->and($line->fresh()->delivered_quantity)->toBe('20.00000000')
        ->and($line->fresh()->returned_quantity)->toBe('0.00000000');
    foreach ([
        InventoryTransaction::StatusAvailable => 80,
        InventoryTransaction::StatusQuarantine => 0,
        InventoryTransaction::StatusRework => 0,
        InventoryTransaction::StatusScrap => 0,
    ] as $stockStatus => $expectedQuantity) {
        expect((int) InventoryTransaction::query()
            ->where('product_id', $fixture['finished']->getKey())
            ->where('stock_status', $stockStatus)
            ->sum(DB::raw('quantity_in - quantity_out')))->toBe($expectedQuantity);
    }
    $returnJournalIds = JournalEntry::query()
        ->where('source_id', $return->getKey())
        ->whereIn('source_type', [
            'sales_return_quarantine_receipt', 'sales_return_financial_disposition',
            'sales_return_disposition_reversal', 'sales_return_quarantine_reversal',
        ])->pluck('id');
    $accountEffects = DB::table('journal_entry_lines')
        ->whereIn('journal_entry_id', $returnJournalIds)
        ->selectRaw('account_id, sum(debit_amount) as debit, sum(credit_amount) as credit')
        ->groupBy('account_id')->get();
    expect($returnJournalIds)->toHaveCount(4);
    foreach ($accountEffects as $effect) {
        expect(bccomp((string) $effect->debit, (string) $effect->credit, 4))->toBe(0);
    }
    $this->post($url, ['reason' => 'Repeat'])->assertUnprocessable();
    expect($returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, 'Corrected return', [[
        'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5',
    ]])->status)->toBe(SalesReturn::StatusPendingAuthorization);
});

test('a closed return with unused credit can be corrected without losing invoice and schedule balances', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_closed', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_closed');

    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [[
        'sales_order_line_id' => $line->getKey(), 'quantity' => '20',
    ]]);
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $line->getKey(),
        'delivery_line_id' => $delivery->lines->first()->getKey(),
        'quantity' => '20',
    ]], [['due_date' => now()->addWeek()->toDateString(), 'amount' => '200']], $delivery));
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->create($invoice, SalesReturn::ReasonWrongItem, null, [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $return = $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(), 'saleable_quantity' => '5',
    ]]);
    $return = $returns->close($return);
    $credit = $return->creditNote;
    $creditJournal = $credit->journalEntry;
    $originalReceipt = $return->returnInventoryDocument;
    $beforeCredit = (string) $invoice->fresh()->credited_amount;
    expect($return->status)->toBe(SalesReturn::StatusClosed)
        ->and($beforeCredit)->toBe('50.0000')
        ->and($credit->credit_application_snapshot)->not->toBeNull()
        ->and($invoice->paymentSchedules->sole()->fresh()->credited_amount)->toBe('50.0000');

    $url = route('admin.sales.sales-returns.correct-closed', $return);
    $fixture['user']->revokePermissionTo('sales_returns.correct_closed');
    $this->post($url, ['reason' => 'No permission'])->assertForbidden();
    $fixture['user']->givePermissionTo('sales_returns.correct_closed');
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 9902,
        'doc_num' => 'BRANCH-OTHER-CLOSED-RETURN', 'name' => 'Other branch', 'type' => Branch::TypeFactory,
    ]);
    $this->withSession([
        OperatingContextService::BranchIdKey => $otherBranch->getKey(),
        OperatingContextService::BranchDocNumKey => $otherBranch->doc_num,
    ])->post($url, ['reason' => 'Wrong branch'])->assertNotFound();
    $this->withSession(salesCycleSession($fixture));
    $fixture['period']->forceFill(['is_closed' => true])->save();
    $this->post($url, ['reason' => 'Closed period'])->assertUnprocessable();
    expect($creditJournal->fresh()->reversed_entry_id)->toBeNull()
        ->and($credit->fresh()->status)->toBe(CustomerInvoice::StatusPosted);
    $fixture['period']->forceFill(['is_closed' => false])->save();

    $application = $credit->credit_application_snapshot;
    $credit->forceFill(['credit_application_snapshot' => null])->save();
    $this->post($url, ['reason' => 'Missing original credit allocation'])->assertUnprocessable();
    $credit->forceFill(['credit_application_snapshot' => $application])->save();
    $credit->forceFill(['electronic_invoice_status' => 'submitted'])->save();
    $this->post($url, ['reason' => 'Tax submission exists'])->assertUnprocessable();
    $credit->forceFill(['electronic_invoice_status' => 'not_configured'])->save();
    $credit->forceFill(['invoice_date' => now()->addDay()->toDateString()])->save();
    $this->post($url, ['reason' => 'Credit date differs from its posted journal'])->assertUnprocessable();
    $credit->forceFill(['invoice_date' => now()->toDateString()])->save();
    $invoice->forceFill(['branch_id' => $otherBranch->getKey()])->save();
    $this->post($url, ['reason' => 'Original invoice belongs to another branch'])->assertUnprocessable();
    $invoice->forceFill(['branch_id' => $fixture['branch']->getKey()])->save();

    $disposition = InventoryDocument::query()->where('source_document_type', SalesReturn::class)
        ->where('source_document_id', $return->getKey())
        ->where('document_type', InventoryDocument::TypeTransfer)->sole();
    $failureKey = sprintf('inventory-document:%d:line:%d:out:reversal', $disposition->getKey(), $disposition->lines()->firstOrFail()->getKey());
    DB::unprepared("CREATE TRIGGER fail_closed_return_disposition BEFORE INSERT ON inventory_transactions WHEN NEW.posting_key = '{$failureKey}' BEGIN SELECT RAISE(ABORT, 'forced closed return reversal failure'); END");
    expect(fn (): SalesReturn => $returns->correctClosed($return, 'Forced failure after credit reversal.'))->toThrow(QueryException::class);
    expect($creditJournal->fresh()->reversed_entry_id)->toBeNull()
        ->and($credit->fresh()->status)->toBe(CustomerInvoice::StatusPosted)
        ->and($invoice->fresh()->credited_amount)->toBe('50.0000')
        ->and($invoice->paymentSchedules->sole()->fresh()->credited_amount)->toBe('50.0000')
        ->and($disposition->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($return->fresh()->status)->toBe(SalesReturn::StatusClosed);
    DB::unprepared('DROP TRIGGER fail_closed_return_disposition');

    $this->post($url, [
        'reason' => 'Original quality and credit were issued against the wrong return.',
    ])->assertOk();

    expect($return->fresh()->status)->toBe(SalesReturn::StatusCancelled)
        ->and($credit->fresh()->status)->toBe(CustomerInvoice::StatusCancelled)
        ->and($credit->fresh()->posting_status)->toBe('reversed')
        ->and($creditJournal->fresh()->reversed_entry_id)->not->toBeNull()
        ->and($credit->fresh()->reversal_journal_entry_id)->toBe($creditJournal->fresh()->reversed_entry_id)
        ->and($invoice->fresh()->credited_amount)->toBe('0.0000')
        ->and($invoice->fresh()->remaining_amount)->toBe('200.0000')
        ->and($invoice->paymentSchedules->sole()->fresh()->credited_amount)->toBe('0.0000')
        ->and($line->fresh()->returned_quantity)->toBe('0.00000000')
        ->and($originalReceipt->fresh()->status)->toBe(InventoryDocument::StatusReversed)
        ->and((string) InventoryTransaction::query()
            ->where('product_id', $fixture['finished']->getKey())
            ->where('stock_status', InventoryTransaction::StatusAvailable)
            ->sum(DB::raw('quantity_in - quantity_out')))->toBe('80');
    $this->post($url, ['reason' => 'Repeat'])->assertUnprocessable();
    expect($returns->create($invoice, SalesReturn::ReasonWrongItem, 'Replacement', [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '5',
    ]])->status)->toBe(SalesReturn::StatusPendingAuthorization);
});

test('a closed return cannot reverse customer credit that was allocated to another invoice', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_closed', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_closed');

    $original = salesPostedServiceInvoice($fixture, '10000', quantity: '5');
    app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'amount' => '10000',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $original->paymentSchedules->sole()->getKey(),
        'amount' => '10000',
    ]]);
    $returns = app(SalesReturnService::class);
    $return = $returns->authorize($returns->create($original, SalesReturn::ReasonOrderEntry, 'Service billed in error.', [[
        'customer_invoice_line_id' => $original->lines->sole()->getKey(), 'quantity' => '1',
    ]]));
    $return = $returns->close($return);
    $credit = $return->creditNote;
    $target = salesPostedServiceInvoice($fixture, '1200');
    $allocation = app(CustomerCreditService::class)->allocate($credit, $target, '100', now()->toDateString());

    $this->post(route('admin.sales.sales-returns.correct-closed', $return), [
        'reason' => 'Credit was already allocated to a second invoice.',
    ])->assertUnprocessable();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusClosed)
        ->and($credit->fresh()->status)->toBe(CustomerInvoice::StatusPosted)
        ->and($credit->fresh()->credit_allocated_amount)->toBe('100.0000')
        ->and($target->fresh()->credited_amount)->toBe('100.0000')
        ->and($original->fresh()->paid_amount)->toBe('10000.0000')
        ->and($credit->journalEntry->fresh()->reversed_entry_id)->toBeNull();

    Permission::findOrCreate('customer_credits.reverse_allocation', 'web');
    $reverseUrl = route('admin.sales.sales-invoices.credit-allocations.reverse', [$credit, $allocation]);
    $this->post($reverseUrl, ['reason' => 'Reverse the allocation before correcting its source return.'])->assertForbidden();
    $fixture['user']->givePermissionTo('customer_credits.reverse_allocation');
    Permission::findOrCreate('customer_invoices.view', 'web');
    $fixture['user']->givePermissionTo('customer_invoices.view');
    expect(app(PermissionRegistryService::class)->all())->toContain('customer_credits.reverse_allocation');
    expect($fixture['user']->can('customer_invoices.view'))->toBeTrue();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.sales.sales-invoices.show', $credit))
        ->assertOk()->assertSee($reverseUrl, false);
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 9903,
        'doc_num' => 'BRANCH-OTHER-CREDIT', 'name' => 'Other branch', 'type' => Branch::TypeFactory,
    ]);
    $this->withSession([
        OperatingContextService::BranchIdKey => $otherBranch->getKey(),
        OperatingContextService::BranchDocNumKey => $otherBranch->doc_num,
    ])->post($reverseUrl, ['reason' => 'Wrong branch'])->assertNotFound();
    $this->withSession(salesCycleSession($fixture));
    $fixture['period']->forceFill(['is_closed' => true])->save();
    $this->post($reverseUrl, ['reason' => 'Closed period'])->assertUnprocessable();
    expect($allocation->fresh()->status)->toBe(CustomerCreditAllocation::StatusApplied)
        ->and($target->fresh()->credited_amount)->toBe('100.0000');
    $fixture['period']->forceFill(['is_closed' => false])->save();

    $credit->fresh()->forceFill(['credit_available_amount' => '1901.0000'])->save();
    $this->post($reverseUrl, ['reason' => 'Reject drifted credit balance.'])->assertUnprocessable();
    expect($allocation->fresh()->status)->toBe(CustomerCreditAllocation::StatusApplied)
        ->and($target->fresh()->credited_amount)->toBe('100.0000');
    $credit->fresh()->forceFill(['credit_available_amount' => '1900.0000'])->save();

    $historicalPeriod = FinancialPeriod::query()->create([
        'doc_number' => 9904, 'doc_num' => 'PERIOD-HISTORICAL-CREDIT',
        'company_id' => $fixture['company']->getKey(), 'name' => 'Historical closed credit period',
        'from_date' => now()->subYear()->startOfYear()->toDateString(),
        'to_date' => now()->subYear()->endOfYear()->toDateString(), 'is_closed' => true,
    ]);
    $allocation->fresh()->forceFill([
        'financial_period_id' => $historicalPeriod->getKey(),
        'allocation_date' => now()->subYear()->toDateString(),
    ])->save();
    $this->post($reverseUrl, ['reason' => 'Reject a closed historical allocation period.'])->assertUnprocessable();
    expect($allocation->fresh()->status)->toBe(CustomerCreditAllocation::StatusApplied)
        ->and($target->fresh()->credited_amount)->toBe('100.0000');
    $allocation->fresh()->forceFill([
        'financial_period_id' => $fixture['period']->getKey(),
        'allocation_date' => now()->toDateString(),
    ])->save();

    $this->post($reverseUrl, ['reason' => 'Reverse the allocation before correcting its source return.'])->assertOk();
    expect($allocation->fresh()->status)->toBe(CustomerCreditAllocation::StatusReversed)
        ->and($allocation->fresh()->reversal_effect_snapshot['amount'])->toBe('100.0000')
        ->and($target->fresh()->credited_amount)->toBe('0.0000')
        ->and($target->fresh()->remaining_amount)->toBe('1200.0000')
        ->and($credit->fresh()->credit_allocated_amount)->toBe('0.0000')
        ->and($credit->fresh()->credit_available_amount)->toBe('2000.0000');
    $this->post($reverseUrl, ['reason' => 'Duplicate reversal'])->assertUnprocessable();
    $effect = $allocation->fresh()->reversal_effect_snapshot;
    $allocation->forceFill(['reversal_effect_snapshot' => [...$effect, 'target_invoice_remaining_after' => '1.0000']])->save();
    $this->post(route('admin.sales.sales-returns.correct-closed', $return), [
        'reason' => 'Reject incomplete allocation reversal evidence.',
    ])->assertUnprocessable();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusClosed)
        ->and($credit->fresh()->status)->toBe(CustomerInvoice::StatusPosted);
    $allocation->forceFill(['reversal_effect_snapshot' => $effect])->save();
    $this->post(route('admin.sales.sales-returns.correct-closed', $return), [
        'reason' => 'Correct after the separately authorized allocation reversal.',
    ])->assertOk();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusCancelled)
        ->and($credit->fresh()->status)->toBe(CustomerInvoice::StatusCancelled)
        ->and($original->fresh()->paid_amount)->toBe('10000.0000')
        ->and($original->fresh()->remaining_amount)->toBe('0.0000')
        ->and($target->fresh()->remaining_amount)->toBe('1200.0000');
});

test('a closed return requires an audited refund recovery before its credit can be corrected', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_closed', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_closed');

    $original = salesPostedServiceInvoice($fixture, '10000', quantity: '5');
    app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'amount' => '10000',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $original->paymentSchedules->sole()->getKey(),
        'amount' => '10000',
    ]]);
    $returns = app(SalesReturnService::class);
    $return = $returns->close($returns->authorize($returns->create(
        $original,
        SalesReturn::ReasonOrderEntry,
        'Correct a service return after refund recovery.',
        [['customer_invoice_line_id' => $original->lines->sole()->getKey(), 'quantity' => '1']],
    )));
    $credit = $return->creditNote;
    $credits = app(CustomerCreditService::class);
    $refundData = [
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'refund_date' => now()->toDateString(),
        'payment_method' => CustomerCreditRefund::MethodCash,
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'amount' => '2000',
        'idempotency_key' => 'refund-to-reverse-for-return-correction',
    ];
    $refund = $credits->refund($credit, $refundData);
    $refundJournal = $refund->journalEntry()->with('lines')->firstOrFail();
    $correctUrl = route('admin.sales.sales-returns.correct-closed', $return);
    $reverseUrl = route('admin.sales.sales-invoices.credit-refunds.reverse', [$credit, $refund]);
    $this->post($correctUrl, ['reason' => 'Refund remains paid out.'])->assertUnprocessable();
    $this->post($reverseUrl, [
        'reason' => 'Recover the mistakenly paid refund.',
        'recovery_reference' => 'CASH-RECOVERY-001',
    ])->assertForbidden();
    Permission::findOrCreate('customer_credits.reverse_refund', 'web');
    Permission::findOrCreate('customer_invoices.view', 'web');
    $fixture['user']->givePermissionTo('customer_credits.reverse_refund', 'customer_invoices.view');
    expect(app(PermissionRegistryService::class)->all())->toContain('customer_credits.reverse_refund');
    $this->get(route('admin.sales.sales-invoices.show', $credit))->assertOk()->assertSee($reverseUrl, false);
    $this->post($reverseUrl, ['reason' => 'Missing recovery reference.'])->assertSessionHasErrors('recovery_reference');

    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 9905,
        'doc_num' => 'BRANCH-OTHER-REFUND', 'name' => 'Other branch', 'type' => Branch::TypeFactory,
    ]);
    $this->withSession([
        OperatingContextService::BranchIdKey => $otherBranch->getKey(),
        OperatingContextService::BranchDocNumKey => $otherBranch->doc_num,
    ])->post($reverseUrl, [
        'reason' => 'Wrong branch.', 'recovery_reference' => 'CASH-RECOVERY-001',
    ])->assertNotFound();
    $this->withSession(salesCycleSession($fixture));
    $fixture['period']->forceFill(['is_closed' => true])->save();
    $this->post($reverseUrl, [
        'reason' => 'Closed period.', 'recovery_reference' => 'CASH-RECOVERY-001',
    ])->assertUnprocessable();
    $fixture['period']->forceFill(['is_closed' => false])->save();
    $refund->fresh()->forceFill(['refund_date' => now()->subDay()->toDateString()])->save();
    expect(fn (): CustomerCreditRefund => $credits->reverseRefund(
        $refund,
        'Reject a stale refund date.',
        'CASH-RECOVERY-001',
    ))->toThrow(DomainException::class);
    expect($refund->fresh()->status)->toBe(CustomerCreditRefund::StatusPosted)
        ->and($refundJournal->fresh()->reversed_entry_id)->toBeNull();
    $refund->fresh()->forceFill(['refund_date' => now()->toDateString()])->save();

    $this->post($reverseUrl, [
        'reason' => 'Recover the mistakenly paid refund.',
        'recovery_reference' => 'CASH-RECOVERY-001',
    ])->assertOk();
    $refund->refresh();
    $reversalJournal = $refund->reversalJournalEntry()->with('lines')->firstOrFail();
    expect($refund->status)->toBe(CustomerCreditRefund::StatusReversed)
        ->and($refund->recovery_reference)->toBe('CASH-RECOVERY-001')
        ->and($refundJournal->fresh()->reversed_entry_id)->toBe($reversalJournal->getKey())
        ->and($credit->fresh()->credit_available_amount)->toBe('2000.0000')
        ->and($credit->fresh()->credit_refunded_amount)->toBe('0.0000')
        ->and($reversalJournal->lines->firstWhere('account_id', $fixture['customer']->account_id)?->credit_amount)->toBe('2000.0000')
        ->and($reversalJournal->lines->firstWhere('account_id', $fixture['cashbox']->account_id)?->debit_amount)->toBe('2000.0000');
    $this->post($reverseUrl, [
        'reason' => 'Duplicate recovery.', 'recovery_reference' => 'CASH-RECOVERY-002',
    ])->assertUnprocessable();
    expect(fn (): CustomerCreditRefund => $credits->refund($credit->fresh(), $refundData))
        ->toThrow(DomainException::class);

    $effect = $refund->reversal_effect_snapshot;
    $refund->forceFill(['reversal_effect_snapshot' => [...$effect, 'amount' => '1.0000']])->save();
    $this->post($correctUrl, ['reason' => 'Reject invalid refund recovery evidence.'])->assertUnprocessable();
    $refund->forceFill(['reversal_effect_snapshot' => $effect])->save();
    $this->post($correctUrl, ['reason' => 'Correct after documented refund recovery.'])->assertOk();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusCancelled)
        ->and($credit->fresh()->status)->toBe(CustomerInvoice::StatusCancelled)
        ->and($original->fresh()->paid_amount)->toBe('10000.0000')
        ->and($original->fresh()->remaining_amount)->toBe('0.0000');
    foreach ([ReconciliationCenterService::CashSafes, ReconciliationCenterService::Customers] as $reportType) {
        $report = app(ReconciliationCenterService::class)->report(
            $fixture['company']->getKey(),
            $fixture['period']->getKey(),
            $fixture['branch']->getKey(),
            now()->toDateString(),
            now()->toDateString(),
            $reportType,
        );
        expect($report['mismatch_count'])->toBe(0);
    }
});

test('bank refund recovery preserves both sides of historical as-of reconciliation', function (): void {
    $this->travelTo(now()->startOfYear()->addMonths(2)->startOfDay()->addHours(12));

    try {
        $fixture = salesCycleFixture();
        $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
        activateReturnCorrectionContext($fixture);
        $refundDate = now()->toDateString();
        $original = salesPostedServiceInvoice($fixture, '100', quantity: '1');
        app(CustomerReceiptService::class)->createAndApprove([
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'customer_id' => $fixture['customer']->getKey(),
            'receipt_date' => $refundDate,
            'currency_id' => $fixture['currency']->getKey(),
            'exchange_rate' => 1,
            'payment_method' => 'cash',
            'cashbox_id' => $fixture['cashbox']->getKey(),
            'amount' => '100',
            'receipt_type' => CustomerReceipt::TypeCollection,
        ], [[
            'customer_invoice_payment_schedule_id' => $original->paymentSchedules->sole()->getKey(),
            'amount' => '100',
        ]]);
        $returns = app(SalesReturnService::class);
        $return = $returns->close($returns->authorize($returns->create(
            $original,
            SalesReturn::ReasonOrderEntry,
            'Bank refund recovery as-of test.',
            [['customer_invoice_line_id' => $original->lines->sole()->getKey(), 'quantity' => '1']],
        )));
        $credit = $return->creditNote;
        $refund = app(CustomerCreditService::class)->refund($credit, [
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'refund_date' => $refundDate,
            'payment_method' => CustomerCreditRefund::MethodBank,
            'bank_account_id' => $fixture['bankAccount']->getKey(),
            'currency_id' => $fixture['currency']->getKey(),
            'exchange_rate' => 1,
            'amount' => '100',
        ]);

        $this->travelTo(now()->addDay());
        app(CustomerCreditService::class)->reverseRefund($refund, 'Recovered by bank transfer.', 'BANK-RECOVERY-001');
        $reconciliations = app(ReconciliationCenterService::class);
        foreach ([$refundDate, now()->toDateString()] as $cutoff) {
            foreach ([ReconciliationCenterService::Banks, ReconciliationCenterService::Customers] as $reportType) {
                $result = $reconciliations->report(
                    $fixture['company']->getKey(),
                    $fixture['period']->getKey(),
                    $fixture['branch']->getKey(),
                    $cutoff,
                    $cutoff,
                    $reportType,
                );
                expect($result['mismatch_count'])->toBe(0);
            }
        }
        expect($refund->fresh()->status)->toBe(CustomerCreditRefund::StatusReversed)
            ->and($refund->fresh()->reversal_effect_snapshot['recovery_reference'])->toBe('BANK-RECOVERY-001');
    } finally {
        $this->travelBack();
    }
});

test('a closed return rejects an unrelated journal with its proposed credit reversal source', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_closed', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_closed');

    $invoice = salesPostedServiceInvoice($fixture, '100', quantity: '2');
    $returns = app(SalesReturnService::class);
    $return = $returns->close($returns->authorize($returns->create($invoice, SalesReturn::ReasonOrderEntry, null, [[
        'customer_invoice_line_id' => $invoice->lines->sole()->getKey(), 'quantity' => '1',
    ]])));
    $credit = $return->creditNote;
    $unrelated = app(JournalEntryService::class)->createPostedFromSource([
        'entry_date' => now()->toDateString(), 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'currency_id' => $fixture['currency']->getKey(), 'exchange_rate' => 1,
        'description' => 'Unrelated journal with the same source',
        'source_type' => 'customer_credit_note_correction', 'source_id' => $credit->getKey(),
        'source_doc_num' => $credit->doc_num,
    ], [
        ['account_id' => $fixture['customer']->account_id, 'debit_amount' => '7', 'credit_amount' => '0', 'description' => 'Wrong debit'],
        ['account_id' => $fixture['cashbox']->account_id, 'debit_amount' => '0', 'credit_amount' => '7', 'description' => 'Wrong credit'],
    ]);

    $this->post(route('admin.sales.sales-returns.correct-closed', $return), [
        'reason' => 'Do not accept a journal with another financial effect.',
    ])->assertUnprocessable();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusClosed)
        ->and($credit->fresh()->status)->toBe(CustomerInvoice::StatusPosted)
        ->and($credit->journalEntry->fresh()->reversed_entry_id)->toBeNull()
        ->and($unrelated->fresh()->source_type)->toBe('customer_credit_note_correction')
        ->and($invoice->fresh()->credited_amount)->toBe('50.0000');
});

test('an inspected return cannot be corrected after its released stock is consumed', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_disposition', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_disposition');

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, null, [[
        'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $return = $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(), 'saleable_quantity' => '5',
    ]]);
    $disposition = InventoryDocument::query()->where('source_document_type', SalesReturn::class)
        ->where('source_document_id', $return->getKey())
        ->where('document_type', InventoryDocument::TypeTransfer)->sole();
    app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'document_type' => InventoryDocument::TypeIssue,
        'document_date' => now()->toDateString(),
        'source_stock_status' => InventoryTransaction::StatusAvailable,
    ], [[
        'product_id' => $fixture['finished']->getKey(),
        'quantity' => '85',
        'batch_lot' => 'SALES-OPENING-BATCH',
    ]]);

    $this->post(route('admin.sales.sales-returns.correct-disposition', $return), [
        'reason' => 'Correction after released stock was issued.',
    ])->assertUnprocessable();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusInspected)
        ->and($disposition->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($return->returnInventoryDocument->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($return->dispositionJournalEntry->fresh()->reversed_entry_id)->toBeNull()
        ->and($line->fresh()->returned_quantity)->toBe('5.00000000');
});

test('an invoiced inspected return cannot be corrected after credit or in a closed period', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_disposition', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_disposition');

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $line->getKey(),
        'delivery_line_id' => $delivery->lines->first()->getKey(),
        'quantity' => '20',
    ]], [['due_date' => now()->addWeek()->toDateString(), 'amount' => '200']], $delivery));
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->create($invoice, SalesReturn::ReasonWrongItem, null, [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $return = $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(), 'saleable_quantity' => '5',
    ]]);
    $receipt = $return->returnInventoryDocument;
    $beforeCustomerBalance = $invoice->fresh()->remaining_amount;
    $fixture['period']->update(['is_closed' => true]);
    $url = route('admin.sales.sales-returns.correct-disposition', $return);
    $this->post($url, ['reason' => 'Closed period'])->assertUnprocessable();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusInspected)
        ->and($receipt->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($invoice->fresh()->remaining_amount)->toBe($beforeCustomerBalance);

    $fixture['period']->update(['is_closed' => false]);
    $closed = $returns->close($return);
    expect($closed->creditNote)->not->toBeNull();
    $this->post($url, ['reason' => 'Credit already posted'])->assertUnprocessable();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusClosed)
        ->and($receipt->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($closed->creditNote->fresh()->posting_status)->toBe('posted');
});

test('correcting an inspected invoiced return leaves its original customer balance unchanged', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_disposition', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_disposition');

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $line->getKey(),
        'delivery_line_id' => $delivery->lines->first()->getKey(),
        'quantity' => '20',
    ]], [['due_date' => now()->addWeek()->toDateString(), 'amount' => '200']], $delivery));
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->create($invoice, SalesReturn::ReasonWrongItem, null, [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $return = $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(), 'saleable_quantity' => '3',
        'quarantine_quantity' => '2',
    ]]);
    $balanceBefore = $invoice->fresh()->remaining_amount;
    $creditedBefore = $invoice->fresh()->credited_amount;

    $this->post(route('admin.sales.sales-returns.correct-disposition', $return), [
        'reason' => 'Incorrect inspection; replace with a correct return.',
    ])->assertOk();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusCancelled)
        ->and($return->fresh()->credit_note_id)->toBeNull()
        ->and($invoice->fresh()->remaining_amount)->toBe($balanceBefore)
        ->and($invoice->fresh()->credited_amount)->toBe($creditedBefore)
        ->and($line->fresh()->delivered_quantity)->toBe('20.00000000')
        ->and($return->returnInventoryDocument->fresh()->status)->toBe(InventoryDocument::StatusReversed);
    expect($returns->create($invoice, SalesReturn::ReasonWrongItem, 'Correct replacement', [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '5',
    ]])->status)->toBe(SalesReturn::StatusPendingAuthorization);
});

test('an inspected return with a missing disposition-journal pointer cannot lose its financial lineage', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_disposition', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_disposition');
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, null, [[
        'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $return = $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(), 'saleable_quantity' => '5',
    ]]);
    $originalJournal = $return->dispositionJournalEntry;
    $disposition = InventoryDocument::query()->where('source_document_type', SalesReturn::class)
        ->where('source_document_id', $return->getKey())
        ->where('document_type', InventoryDocument::TypeTransfer)->sole();
    $return->update(['disposition_journal_entry_id' => null]);

    $this->post(route('admin.sales.sales-returns.correct-disposition', $return), [
        'reason' => 'Incorrect quality record.',
    ])->assertUnprocessable();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusInspected)
        ->and($disposition->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($return->returnInventoryDocument->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($originalJournal->fresh()->reversed_entry_id)->toBeNull()
        ->and($line->fresh()->returned_quantity)->toBe('5.00000000');
});

test('a conflicting existing reversal transaction cannot be accepted as the receipt correction', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    Permission::findOrCreate('sales_returns.correct_receipt', 'web');
    $fixture['user']->givePermissionTo('sales_returns.correct_receipt');
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, null, [[
        'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $receipt = $return->returnInventoryDocument;
    $original = InventoryTransaction::query()->where('source_type', InventoryDocument::class)
        ->where('source_id', $receipt->getKey())->where('is_reversal', false)->sole();
    $poisoned = $original->replicate();
    $poisoned->forceFill([
        'posting_key' => $original->posting_key.':reversal',
        'quantity_in' => '1', 'quantity_out' => '0', 'is_reversal' => true,
        'reversal_of_id' => $delivery->transactions->first()->getKey(),
    ])->save();

    $this->post(route('admin.sales.sales-returns.correct-receipt', $return), [
        'reason' => 'Correct a poisoned reversal.',
    ])->assertUnprocessable();
    expect($return->fresh()->status)->toBe(SalesReturn::StatusReceived)
        ->and($receipt->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($poisoned->fresh()->quantity_in)->toBe('1.00000000')
        ->and($return->quarantineJournalEntry->fresh()->reversed_entry_id)->toBeNull();
});

test('a failure during the second quality-disposition reversal rolls the first one back', function (): void {
    $fixture = salesCycleFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    activateReturnCorrectionContext($fixture);
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '20']]);
    $returns = app(SalesReturnService::class);
    $return = $returns->receive($returns->authorize($returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, null, [[
        'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5',
    ]])));
    $return = $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(),
        'saleable_quantity' => '2', 'rework_quantity' => '2', 'scrap_quantity' => '1',
    ]]);
    $dispositions = InventoryDocument::query()
        ->where('source_document_type', SalesReturn::class)
        ->where('source_document_id', $return->getKey())
        ->where('document_type', InventoryDocument::TypeTransfer)
        ->orderByDesc('id')->get();
    $second = $dispositions->get(1);
    $failureKey = sprintf('inventory-document:%d:line:%d:out:reversal', $second->getKey(), $second->lines()->firstOrFail()->getKey());
    DB::unprepared("CREATE TRIGGER fail_second_return_disposition BEFORE INSERT ON inventory_transactions WHEN NEW.posting_key = '{$failureKey}' BEGIN SELECT RAISE(ABORT, 'forced disposition reversal failure'); END");

    expect(fn (): SalesReturn => $returns->correctInspected($return, 'Correct all quality buckets.'))->toThrow(QueryException::class);
    expect($return->fresh()->status)->toBe(SalesReturn::StatusInspected)
        ->and($return->returnInventoryDocument->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($return->quarantineJournalEntry->fresh()->reversed_entry_id)->toBeNull()
        ->and($return->dispositionJournalEntry->fresh()->reversed_entry_id)->toBeNull()
        ->and($line->fresh()->returned_quantity)->toBe('5.00000000');
    foreach ($dispositions as $disposition) {
        expect($disposition->fresh()->status)->toBe(InventoryDocument::StatusPosted)
            ->and(InventoryTransaction::query()->where('source_id', $disposition->getKey())
                ->where('source_type', InventoryDocument::class)
                ->where('is_reversal', true)->count())->toBe(0);
    }
});
