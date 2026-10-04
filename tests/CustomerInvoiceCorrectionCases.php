<?php

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\RequestMemo;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Inventory\Services\InventoryAccountingPostingService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptSettlementService;
use Modules\Sales\Services\SalesBalanceProjectionService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/CustomerInvoiceCorrectionSupport.php';

test('an independently approved later invoice correction restores original production layers and preserves the dated invoice', function (): void {
    $f = invoiceCorrectionFixture();
    $originalLines = $f['invoice']->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $originalHeader = $f['invoice']->only(['invoice_date', 'financial_period_id', 'total_amount', 'sales_order_id', 'delivery_document_id', 'journal_entry_id']);
    $collection = invoiceCorrectionCollection($f, $f['invoice'], '35');
    Permission::findOrCreate('customer_receipts.cancel', 'web');
    $f['user']->givePermissionTo('customer_receipts.cancel');
    $f['period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $f['target'] = FinancialPeriod::query()->create(['company_id' => $f['company']->id, 'doc_number' => 99311, 'doc_num' => 'SYNTHETIC-INVOICE-OCT',
        'name' => 'SYNTHETIC invoice recovery period', 'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false]);
    $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
    invoiceCorrectionActor($f, $f['user']);
    $service = app(CustomerInvoiceCorrectionService::class);
    $preview = $service->preview($f['invoice']);
    expect($preview['dependencies'])->toHaveCount(1)->and($preview['dependencies'][0]['permission'])->toBe('customer_receipts.cancel');
    expect(fn () => $service->prepare($f['invoice'], invoiceCorrectionPayload($f)))->toThrow(DomainException::class);
    $this->postJson(route('admin.sales.customer-receipts.reverse', $collection), ['reason' => 'SYNTHETIC recovered collection for invoice correction'])->assertOk();
    invoiceCorrectionActor($f, $f['user']);
    expect($collection->fresh()->status)->toBe('cancelled')
        ->and(JournalEntry::query()->findOrFail($collection->fresh()->reversal_journal_entry_id)->financial_period_id)->toBe($f['target']->id);
    $preview = $service->preview($f['invoice']);
    $payload = ['source_fingerprint' => $preview['fingerprint'], 'reason' => 'SYNTHETIC original invoice error',
        'posting_date' => '2026-10-03', 'recovery_reference' => 'SYNTHETIC signed physical recovery 001'];
    $proposal = $service->prepare($f['invoice'], $payload);
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC review'))->toThrow(DomainException::class);
    invoiceCorrectionActor($f, $f['approver']);
    $approved = $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC independent original evidence review');
    expect($approved->status)->toBe('approved')->and($f['invoice']->fresh()->only(array_keys($originalHeader)))->toEqual($originalHeader)
        ->and($f['invoice']->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalLines)
        ->and($f['invoice']->fresh()->credited_amount)->toBe('100.0000')->and($f['invoice']->fresh()->remaining_amount)->toBe('0.0000')
        ->and($f['delivery']->fresh()->status)->toBe('reversed')->and($f['salesLine']->fresh()->invoiced_quantity)->toBe('0.00000000')
        ->and($f['salesLine']->fresh()->delivered_quantity)->toBe('0.00000000');
    $credit = $f['invoice']->creditNotes()->sole();
    expect($credit->invoice_date->toDateString())->toBe('2026-10-03')->and($credit->financial_period_id)->toBe($f['target']->id)
        ->and($credit->journalEntry->lines->sum('debit_amount'))->toEqual($f['invoice']->journalEntry->lines->sum('credit_amount'));
    $receiptTransaction = $f['receipt']->transactions()->where('is_reversal', false)->sole();
    expect(bcadd((string) InventoryReceiptLayer::query()->whereIn('receipt_transaction_id', app(InventoryLayerService::class)->receiptLineageTransactionIds((int) $receiptTransaction->id))->sum('remaining_quantity'), '0', 8))->toBe('10.00000000');
    $service->assertApproved($approved);
    $balances = app(SalesBalanceProjectionService::class);
    expect($balances->invoicesAt($f['company']->id, '2026-09-30')->findOrFail($f['invoice']->id)->remaining_amount)->toBe('65.0000')
        ->and($balances->invoicesAt($f['company']->id, '2026-10-03')->findOrFail($f['invoice']->id)->remaining_amount)->toBe('0.0000');
    expect($service->approve($f['invoice'], $proposal->id, 'SYNTHETIC independent original evidence review')->id)->toBe($proposal->id)
        ->and($f['invoice']->creditNotes()->count())->toBe(1);
    invoiceCorrectionActor($f, $f['user']);
    $production = app(ProductionRunCorrectionService::class);
    $plan = $production->preview($f['run']);
    $correction = $production->propose($f['run'], ['good_base_quantity' => '9', 'rejected_base_quantity' => '1',
        'rework_base_quantity' => '0', 'scrap_base_quantity' => '0'], 'SYNTHETIC production source recovery', $plan['fingerprint'], '2026-10-03', mode: 'later_period');
    invoiceCorrectionActor($f, $f['approver']);
    $production->approve($f['run'], $correction->id);
    expect($f['run']->fresh()->status)->toBe('running')
        ->and(InventoryTransaction::query()->where('reversal_of_id', $receiptTransaction->id)->sole()->financial_period_id)->toBe($f['target']->id);
    $balance = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['target']->id))->keyBy('key');
    expect($balance['finished_goods']['difference'])->toBe('0.0000')->and($balance['wip']['difference'])->toBe('0.0000');
    $f['cycle']->accountMaterials($f['run']->fresh(), $f['store']->id, [$f['requirement']->id => ['consumed_quantity' => '18', 'waste_quantity' => '2']]);
    $inspection = $f['cycle']->recordInspection($f['run']->fresh(), ['result' => 'passed', 'disposition' => 'release']);
    $f['cycle']->reviewInspection($inspection, true);
    $replacementReceipt = $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '9');
    $completed = $f['cycle']->completeRun($f['run']->fresh());
    expect($completed->status)->toBe('completed')->and($replacementReceipt->lines->sole()->total_cost)->toBe('36.00000000');
    $delivery = app(SalesFulfillmentService::class)->deliver($f['salesOrder']->fresh(), [['sales_order_line_id' => $f['salesLine']->id, 'quantity' => '9']]);
    $invoices = app(CustomerInvoiceService::class);
    $replacementInvoice = $invoices->post($invoices->createFromOrder($f['salesOrder']->fresh(),
        [['sales_order_line_id' => $f['salesLine']->id, 'delivery_line_id' => $delivery->lines->sole()->id, 'quantity' => '9']],
        [['due_date' => '2026-10-03', 'amount' => '90']], $delivery));
    invoiceCorrectionCollection($f, $replacementInvoice, '90');
    expect($replacementInvoice->fresh()->remaining_amount)->toBe('0.0000')->and($replacementInvoice->fresh()->paid_amount)->toBe('90.0000');
    $service->assertApproved($approved);
    $balances->assertCorrectionEvidence($f['company']->id);
    foreach ([$f['period'], $f['target']] as $period) {
        $position = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $period->id))->keyBy('key');
        expect($position['finished_goods']['difference'])->toBe('0.0000')->and($position['wip']['difference'])->toBe('0.0000');
    }
});

test('invoice correction renders bilingual shared controls and authorized independently approved routes', function (): void {
    $f = invoiceCorrectionFixture();
    $url = route('admin.sales.sales-invoices.corrections.index', $f['invoice']);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->get($url)->assertOk()->assertSee(__('invoice_correction.title'))->assertSee('js-date-picker', false)
            ->assertSee('novalidate', false)->assertDontSee('type="date"', false);
    }
    invoiceCorrectionActor($f, $f['user']);
    $payload = invoiceCorrectionPayload($f);
    $id = $this->postJson(route('admin.sales.sales-invoices.corrections.store', $f['invoice']), $payload)->assertOk()->json('data.proposal_id');
    $this->postJson(route('admin.sales.sales-invoices.corrections.approve', [$f['invoice'], $id]), ['approval_reason' => 'SYNTHETIC self approval'])->assertUnprocessable();
    invoiceCorrectionActor($f, $f['approver']);
    $this->postJson(route('admin.sales.sales-invoices.corrections.approve', [$f['invoice'], $id]), ['approval_reason' => 'SYNTHETIC independent evidence'])->assertOk()->assertJsonPath('data.status', 'approved');
    $this->get($url)->assertOk()->assertSee($f['invoice']->creditNotes()->sole()->doc_num)
        ->assertDontSee(__('invoice_correction.dependencies'));
    expect(app(CustomerInvoiceCorrectionService::class)->preview($f['invoice'])['dependencies'])->toBe([]);
    expect($f['invoice']->issueOrder()->sole()->status)->toBe('corrected');
});

test('invoice correction rejects a changed receipt layer or tampered proposal without effects', function (): void {
    $f = invoiceCorrectionFixture();
    $service = app(CustomerInvoiceCorrectionService::class);
    $proposal = $service->prepare($f['invoice'], invoiceCorrectionPayload($f));
    $layer = DB::table('inventory_receipt_layers')->where('receipt_transaction_id', $f['receipt']->transactions()->where('is_reversal', false)->sole()->id)->sole();
    DB::table('inventory_receipt_layers')->where('id', $layer->id)->update(['batch_lot' => 'SYNTHETIC changed source dimensions']);
    invoiceCorrectionActor($f, $f['approver']);
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC independent review'))->toThrow(DomainException::class)
        ->and($proposal->fresh()->status)->toBe('prepared')->and($f['invoice']->creditNotes()->count())->toBe(0)
        ->and($f['delivery']->fresh()->status)->toBe('posted');
    DB::table('inventory_receipt_layers')->where('id', $layer->id)->update(['batch_lot' => $layer->batch_lot]);
    DB::table('customer_invoice_corrections')->where('id', $proposal->id)->update(['recovery_reference' => 'SYNTHETIC altered authorization']);
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC review'))->toThrow(DomainException::class)
        ->and($f['invoice']->creditNotes()->count())->toBe(0);
});

test('invoice correction rejects balanced source account tampering and revoked approval authority', function (): void {
    $f = invoiceCorrectionFixture();
    $service = app(CustomerInvoiceCorrectionService::class);
    $source = $f['invoice']->journalEntry->lines()->where('credit_amount', '>', 0)->sole();
    DB::table('journal_entry_lines')->where('id', $source->id)->update(['account_id' => $f['invoice']->customer->account_id]);
    expect(fn () => $service->prepare($f['invoice'], invoiceCorrectionPayload($f)))->toThrow(DomainException::class)
        ->and($f['invoice']->creditNotes()->count())->toBe(0);
    DB::table('journal_entry_lines')->where('id', $source->id)->update(['account_id' => $source->account_id]);
    $proposal = $service->prepare($f['invoice'], invoiceCorrectionPayload($f));
    $f['approver']->revokePermissionTo('customer_invoices.correct_approve');
    invoiceCorrectionActor($f, $f['approver']);
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC review'))->toThrow(AuthorizationException::class)
        ->and($proposal->fresh()->status)->toBe('prepared')->and($f['delivery']->fresh()->status)->toBe('posted');
});

test('invoice correction detects missing restored layers and changed fulfillment counters while allowing replacement documents', function (): void {
    $f = invoiceCorrectionFixture();
    $service = app(CustomerInvoiceCorrectionService::class);
    $proposal = $service->prepare($f['invoice'], invoiceCorrectionPayload($f));
    invoiceCorrectionActor($f, $f['approver']);
    $approved = $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC review');
    $layer = DB::table('inventory_receipt_layers')->whereIn('receipt_transaction_id', $f['delivery']->transactions()->where('is_reversal', true)->select('id'))->sole();
    DB::table('inventory_receipt_layers')->where('id', $layer->id)->update(['source_allocation_id' => null]);
    expect(fn () => $service->assertApproved($approved))->toThrow(DomainException::class);
    DB::table('inventory_receipt_layers')->where('id', $layer->id)->update(['source_allocation_id' => $layer->source_allocation_id]);
    DB::table('sales_order_lines')->where('id', $f['salesLine']->id)->update(['invoiced_quantity' => '1']);
    expect(fn () => $service->assertApproved($approved))->toThrow(DomainException::class);
    DB::table('sales_order_lines')->where('id', $f['salesLine']->id)->update(['invoiced_quantity' => '0']);
    $delivery = app(SalesFulfillmentService::class)->deliver($f['salesOrder']->fresh(), [['sales_order_line_id' => $f['salesLine']->id, 'quantity' => '10']]);
    $invoices = app(CustomerInvoiceService::class);
    $replacement = $invoices->post($invoices->createFromOrder($f['salesOrder']->fresh(),
        [['sales_order_line_id' => $f['salesLine']->id, 'delivery_line_id' => $delivery->lines->sole()->id, 'quantity' => '10']],
        [['due_date' => '2026-09-30', 'amount' => '100']], $delivery));
    expect($replacement->total_amount)->toBe('100.0000')->and($f['salesLine']->fresh()->invoiced_quantity)->toBe('10.00000000');
    $service->assertApproved($approved);
    $balances = app(SalesBalanceProjectionService::class);
    expect($balances->invoicesAt($f['company']->id, '2026-09-29')->findOrFail($f['invoice']->id)->remaining_amount)->toBe('100.0000')
        ->and($balances->invoicesAt($f['company']->id, '2026-09-30')->findOrFail($f['invoice']->id)->remaining_amount)->toBe('0.0000');
});

test('a shared delivery is corrected once with both partial invoices and rolls back after a late posting failure', function (): void {
    $f = invoiceCorrectionFixture(partialInvoice: true);
    $invoices = app(CustomerInvoiceService::class);
    $second = $invoices->post($invoices->createFromOrder($f['salesOrder']->fresh(),
        [['sales_order_line_id' => $f['salesLine']->id, 'delivery_line_id' => $f['delivery']->lines->sole()->id, 'quantity' => '6']],
        [['due_date' => '2026-09-30', 'amount' => '60']], $f['delivery']));
    $service = app(CustomerInvoiceCorrectionService::class);
    $preview = $service->preview($f['invoice']);
    expect(array_column($preview['snapshot']['invoices'], 'id'))->toBe([$f['invoice']->id, $second->id]);
    $proposal = $service->prepare($f['invoice'], invoiceCorrectionPayload($f));
    invoiceCorrectionActor($f, $f['approver']);
    $original = app(JournalEntryService::class);
    $proxy = Mockery::mock($original);
    $count = 0;
    $proxy->shouldReceive('createPostedReversalFromSource')->andReturnUsing(function ($entry, $header) use ($original, &$count) {
        if (++$count === 2) {
            throw new RuntimeException('SYNTHETIC late second invoice failure');
        }

        return $original->createPostedReversalFromSource($entry, $header);
    });
    app()->instance(JournalEntryService::class, $proxy);
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC review'))->toThrow(RuntimeException::class)
        ->and($f['invoice']->creditNotes()->count())->toBe(0)->and($second->creditNotes()->count())->toBe(0)
        ->and($f['delivery']->fresh()->status)->toBe('posted')->and($proposal->fresh()->status)->toBe('prepared')
        ->and($f['salesLine']->fresh()->invoiced_quantity)->toBe('10.00000000');
    app()->instance(JournalEntryService::class, $original);
    $posting = app(InventoryDocumentPostingService::class);
    $failed = Mockery::mock($posting);
    $failed->shouldReceive('reverseForInvoiceCorrection')->andThrow(new RuntimeException('SYNTHETIC delivery posting failure'));
    app()->instance(InventoryDocumentPostingService::class, $failed);
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC review'))->toThrow(RuntimeException::class)
        ->and($f['invoice']->creditNotes()->count())->toBe(0)->and($second->creditNotes()->count())->toBe(0)
        ->and($f['invoice']->journalEntry->fresh()->reversed_entry_id)->toBeNull()->and($f['delivery']->fresh()->status)->toBe('posted');
    app()->instance(InventoryDocumentPostingService::class, $posting);
    $approved = $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC complete review');
    expect($approved->status)->toBe('approved')->and($f['invoice']->creditNotes()->sole()->total_amount)->toBe('40.0000')
        ->and($second->creditNotes()->sole()->total_amount)->toBe('60.0000')
        ->and($f['delivery']->transactions()->where('is_reversal', true)->count())->toBe(1)
        ->and($f['salesLine']->fresh()->invoiced_quantity)->toBe('0.00000000')->and($f['salesLine']->fresh()->delivered_quantity)->toBe('0.00000000');
    $service->assertApproved($approved);
});

test('invoice correction requires company warehouse privilege and rejects foreign company or branch contexts', function (): void {
    $f = invoiceCorrectionFixture(companyWarehouse: true);
    $service = app(CustomerInvoiceCorrectionService::class);
    expect($f['delivery']->branch_id)->toBe($f['warehouseBranch']->id);
    expect(fn () => $service->preview($f['invoice']))->toThrow(AuthorizationException::class);
    Permission::findOrCreate('customer_invoices.correct_company_warehouse', 'web');
    $f['user']->givePermissionTo('customer_invoices.correct_company_warehouse');
    $f['approver']->givePermissionTo('customer_invoices.correct_company_warehouse');
    invoiceCorrectionActor($f, $f['user']);
    $proposal = $service->prepare($f['invoice'], invoiceCorrectionPayload($f));
    invoiceCorrectionActor($f, $f['approver']);
    $f['approver']->revokePermissionTo('customer_invoices.correct_company_warehouse');
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC revoked warehouse authority'))->toThrow(AuthorizationException::class);
    $f['approver']->givePermissionTo('customer_invoices.correct_company_warehouse');
    request()->session()->put(OperatingContextService::BranchIdKey, $f['warehouseBranch']->id);
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC wrong invoice branch'))->toThrow(HttpException::class);
    invoiceCorrectionActor($f, $f['approver']);
    $other = Company::query()->where('id', '<>', $f['company']->id)->firstOrFail();
    request()->session()->put([OperatingContextService::CompanyIdKey => $other->id, OperatingContextService::CompanyDocNumKey => $other->doc_num]);
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC wrong company'))->toThrow(ModelNotFoundException::class);
    invoiceCorrectionActor($f, $f['approver']);
    $approved = $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC authorized warehouse recovery');
    $service->assertApproved($approved);
    expect($f['transfer']->fresh()->status)->toBe('posted')->and($f['delivery']->fresh()->status)->toBe('reversed')
        ->and(bcadd((string) DB::table('inventory_receipt_layers')->where('branch_store_id', $f['warehouse']->id)->where('product_id', $f['finished']->id)->sum('remaining_quantity'), '0', 8))->toBe('10.00000000');
    foreach ([null, $f['branch']->id, $f['warehouseBranch']->id] as $branch) {
        $position = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $branch))->keyBy('key');
        expect($position['finished_goods']['difference'])->toBe('0.0000');
    }
});

test('invoice correction rejects forged recovered collection journal evidence atomically', function (): void {
    $f = invoiceCorrectionFixture();
    $receipt = invoiceCorrectionCollection($f, $f['invoice'], '35');
    app(CustomerReceiptSettlementService::class)->reverse($receipt, 'SYNTHETIC collection recovered');
    $inverse = JournalEntry::query()->findOrFail($receipt->fresh()->reversal_journal_entry_id);
    DB::table('journal_entry_lines')->where('journal_entry_id', $inverse->id)->where('debit_amount', '>', 0)->increment('debit_amount', 1);
    DB::table('journal_entry_lines')->where('journal_entry_id', $inverse->id)->where('credit_amount', '>', 0)->increment('credit_amount', 1);
    $service = app(CustomerInvoiceCorrectionService::class);
    expect(fn () => $service->preview($f['invoice']))->toThrow(DomainException::class)
        ->and($f['invoice']->creditNotes()->count())->toBe(0)->and($f['delivery']->fresh()->status)->toBe('posted');
});

test('invoice correction accepts a canonically recovered physical return and rejects a forged return inverse', function (): void {
    $f = invoiceCorrectionFixture();
    foreach (['sales_returns.correct_closed', 'customer_credits.reverse_allocation'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $returns = app(SalesReturnService::class);
    $return = $returns->authorize($returns->create($f['invoice'], SalesReturn::ReasonOrderEntry,
        'SYNTHETIC physical return recovery', [['customer_invoice_line_id' => $f['invoice']->lines->sole()->id, 'quantity' => '2']]));
    $return = $returns->receive($return);
    $return = $returns->inspect($return, [['sales_return_line_id' => $return->lines->sole()->id,
        'saleable_quantity' => '2', 'rework_quantity' => '0', 'scrap_quantity' => '0']]);
    $return = $returns->close($return);
    $service = app(CustomerInvoiceCorrectionService::class);
    expect($service->preview($f['invoice'])['dependencies'])->not->toBeEmpty();
    $return = $returns->correctClosed($return, 'SYNTHETIC recovered return before invoice correction');
    $returns->assertCancelledRecovery($return);
    $inverse = $return->returnInventoryDocument->transactions()->where('is_reversal', true)->sole();
    DB::table('inventory_transactions')->where('id', $inverse->id)->update(['quantity_out' => '1']);
    expect(fn () => $service->preview($f['invoice']))->toThrow(DomainException::class);
    DB::table('inventory_transactions')->where('id', $inverse->id)->update(['quantity_out' => $inverse->quantity_out]);
    $proposal = $service->prepare($f['invoice'], invoiceCorrectionPayload($f));
    invoiceCorrectionActor($f, $f['approver']);
    $approved = $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC recovered return and invoice');
    $service->assertApproved($approved);
    expect($f['invoice']->fresh()->remaining_amount)->toBe('0.0000')->and($f['delivery']->fresh()->status)->toBe('reversed');
});

test('invoice correction proves and seals actual delivery cost completion inverses', function (): void {
    $f = invoiceCorrectionFixture(completedReceiptCost: true);
    $service = app(CustomerInvoiceCorrectionService::class);
    $accounting = app(InventoryAccountingPostingService::class);
    $source = $f['delivery']->transactions()->where('is_reversal', false)->sole();
    expect($source->total_cost)->toBe('20.00000000')
        ->and(InventoryValueAdjustmentLine::query()->where('source_transaction_id', $source->id)
            ->whereHas('adjustment', fn ($query) => $query->where('status', 'posted'))->count())->toBeGreaterThan(0);
    $journal = $f['delivery']->journalEntry;
    $bogus = app(JournalEntryService::class)->createPostedFromSource([
        'entry_date' => '2026-09-30', 'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'branch_id' => $f['branch']->id, 'currency_id' => $journal->currency_id, 'exchange_rate' => '1',
        'source_type' => 'inventory_document_cost_completion_reversal', 'source_id' => $f['delivery']->id,
        'source_doc_num' => $f['delivery']->doc_num, 'description' => 'SYNTHETIC forged completion inverse',
    ], $journal->lines->map(fn ($line): array => ['account_id' => $line->account_id, 'branch_id' => $line->branch_id,
        'debit_amount' => $line->debit_amount, 'credit_amount' => $line->credit_amount, 'description' => 'SYNTHETIC forged completion'])->all());
    expect(fn () => $service->prepare($f['invoice'], invoiceCorrectionPayload($f)))->toThrow(DomainException::class)
        ->and($f['delivery']->fresh()->status)->toBe('posted')->and($f['invoice']->creditNotes()->count())->toBe(0);
    DB::table('journal_entry_lines')->where('journal_entry_id', $bogus->id)->delete();
    DB::table('journal_entries')->where('id', $bogus->id)->delete();
    $proposal = $service->prepare($f['invoice'], invoiceCorrectionPayload($f));
    invoiceCorrectionActor($f, $f['approver']);
    $approved = $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC reviewed completed receipt basis');
    $accounting->assertCorrectionCompletionReversal($f['delivery']->fresh());
    $service->assertApproved($approved);
    $inverse = JournalEntry::query()->where('source_type', 'inventory_document_cost_completion_reversal')
        ->where('source_id', $f['delivery']->id)->sole();
    expect($inverse->lines->sum('debit_amount'))->toEqual(10)->and($inverse->lines->sum('credit_amount'))->toEqual(10);
    $position = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id))->keyBy('key');
    expect($position['finished_goods']['difference'])->toBe('0.0000');
    DB::table('journal_entry_lines')->where('journal_entry_id', $inverse->id)->where('debit_amount', '>', 0)->increment('debit_amount', 1);
    DB::table('journal_entry_lines')->where('journal_entry_id', $inverse->id)->where('credit_amount', '>', 0)->increment('credit_amount', 1);
    expect(fn () => $service->assertApproved($approved))->toThrow(DomainException::class)
        ->and(fn () => app(SalesBalanceProjectionService::class)->assertCorrectionEvidence($f['company']->id))->toThrow(DomainException::class);
});

test('cross branch transfer creation and direct draft posting reject unauthorized destination without stock or journal effects', function (): void {
    $f = invoiceCorrectionFixture();
    $branchNumber = (int) Branch::withTrashed()->max('doc_number') + 1;
    $destination = Branch::query()->create(['company_id' => $f['company']->id, 'doc_number' => $branchNumber,
        'doc_num' => 'SYNTHETIC-TRANSFER-SCOPE-'.$branchNumber, 'name' => 'SYNTHETIC unauthorized destination', 'type' => 'factory', 'status' => 'active']);
    $store = BranchStore::query()->create(['branch_id' => $destination->id, 'name' => 'SYNTHETIC restricted transfer store']);
    $header = ['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
        'branch_store_id' => $f['store']->id, 'destination_branch_store_id' => $store->id,
        'document_type' => InventoryDocument::TypeTransfer, 'document_date' => now()->toDateString()];
    $lines = [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1']];
    $movements = app(InventoryMovementService::class);
    $draft = $movements->createDraft($header, $lines);
    $roleNumber = (int) Role::withTrashed()->max('doc_number') + 1;
    $role = Role::query()->create(['name' => 'SYNTHETIC transfer scope '.$roleNumber, 'guard_name' => 'web',
        'doc_number' => $roleNumber, 'doc_num' => 'SYNTHETIC-TRANSFER-ROLE-'.$roleNumber, 'branch_access_restricted' => true]);
    DB::table('role_branch_access')->insert(['role_id' => $role->id, 'branch_id' => $f['branch']->id, 'created_at' => now(), 'updated_at' => now()]);
    $f['user']->assignRole($role);
    app(RequestMemo::class)->forget("operating_scope_access.role_scope.{$f['user']->id}");
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.branches.{$role->id}");
    invoiceCorrectionActor($f, $f['user']);
    $tables = ['inventory_documents', 'inventory_transactions', 'inventory_receipt_layers', 'journal_entries'];
    $before = array_map(fn ($table): int => DB::table($table)->count(), $tables);
    expect(fn () => $movements->createAndPost($header, $lines))->toThrow(AuthorizationException::class)
        ->and(fn () => app(InventoryDocumentPostingService::class)->post($draft))->toThrow(AuthorizationException::class)
        ->and(array_map(fn ($table): int => DB::table($table)->count(), $tables))->toBe($before)
        ->and($draft->fresh()->status)->toBe('draft')->and($draft->fresh()->journal_entry_id)->toBeNull();
});

test('an unbilled delivery has a real independently approved later recovery and normal sales replacement path', function (): void {
    $f = invoiceCorrectionFixture(createInvoice: false);
    foreach (['inventory.documents.correct_prepare', 'inventory.documents.correct_approve', 'inventory.documents.correct_later_period'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
        $f['approver']->givePermissionTo($permission);
    }
    $original = $f['delivery']->transactions()->sole()->getAttributes();
    $originalLines = $f['delivery']->journalEntry->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $f['period']->update(['is_closed' => true]);
    $f['target'] = FinancialPeriod::query()->create(['company_id' => $f['company']->id, 'doc_number' => 99312,
        'doc_num' => 'SYNTHETIC-UNBILLED-RECOVERY', 'name' => 'SYNTHETIC unbilled delivery October',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false]);
    $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
    invoiceCorrectionActor($f, $f['user']);
    $production = app(ProductionRunCorrectionService::class)->preview($f['run']);
    $step = collect($production['correction_steps'])->firstWhere('doc_num', $f['delivery']->doc_num);
    expect($step['action'])->toBe('recover_unbilled_sales_delivery')
        ->and($step['correction_url'])->toBe(route('admin.inventory.documents.corrections.index', $f['delivery']));
    $service = app(InventoryMovementCorrectionService::class);
    $preview = $service->preview($f['delivery']);
    expect($preview['reverse_only'])->toBeTrue();
    $payload = ['source_fingerprint' => $preview['source_fingerprint'], 'operation' => 'reverse', 'posting_date' => '2026-10-03',
        'reason' => 'SYNTHETIC independently reviewed physical recovery'];
    expect(fn () => $service->prepare($f['delivery'], [...$payload, 'operation' => 'replace']))->toThrow(DomainException::class);
    $proposal = $service->prepare($f['delivery'], $payload);
    invoiceCorrectionActor($f, $f['approver']);
    $approved = $service->approve($f['delivery'], $proposal->id, 'SYNTHETIC independent unbilled recovery review');
    expect($approved->status)->toBe('approved')->and($f['delivery']->fresh()->status)->toBe('reversed')
        ->and($f['salesLine']->fresh()->delivered_quantity)->toBe('0.00000000')
        ->and($f['salesLine']->fresh()->invoiced_quantity)->toBe('0.00000000')
        ->and($f['delivery']->transactions()->where('is_reversal', false)->sole()->getAttributes())->toBe($original)
        ->and($f['delivery']->journalEntry->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalLines)
        ->and(app(ProductionRunCorrectionService::class)->preview($f['run'])['correction_steps'])->toBe([]);
    $replacement = app(SalesFulfillmentService::class)->deliver($f['salesOrder']->fresh(), [['sales_order_line_id' => $f['salesLine']->id, 'quantity' => '9']]);
    expect($replacement->financial_period_id)->toBe($f['target']->id)->and($f['salesLine']->fresh()->delivered_quantity)->toBe('9.00000000');
    $invoice = app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createFromOrder($f['salesOrder']->fresh(),
        [['sales_order_line_id' => $f['salesLine']->id, 'delivery_line_id' => $replacement->lines->sole()->id, 'quantity' => '9']],
        [['due_date' => '2026-10-03', 'amount' => '90']], $replacement));
    expect($invoice->total_amount)->toBe('90.0000')->and($invoice->financial_period_id)->toBe($f['target']->id);
    foreach ([$f['period']->id, $f['target']->id] as $period) {
        $position = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $period))->keyBy('key');
        expect($position['finished_goods']['difference'])->toBe('0.0000');
    }
});
