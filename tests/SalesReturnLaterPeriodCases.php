<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnCorrection;
use Modules\Sales\Services\CustomerCreditService;
use Modules\Sales\Services\Reports\SalesCostReportService;
use Modules\Sales\Services\SalesReturnCorrectionService;
use Modules\Sales\Services\SalesReturnService;

require_once __DIR__.'/SalesReturnLaterPeriodSupport.php';

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

test('later-period sales return correction requires independent approval and preserves original accounting while creating one pending replacement', function (): void {
    $f = laterReturnCloseSource(laterReturnFixture());
    $service = app(SalesReturnCorrectionService::class);
    $journal = $f['credit']->journalEntry;
    $originalLines = $journal->lines()->orderBy('line_no')->get()->map->getAttributes()->all();
    $originalInvoice = $f['invoice']->fresh();
    $beforeJournals = JournalEntry::query()->where('company_id', $f['company']->id)->count();
    $url = route('admin.sales.sales-returns.corrections.index', $f['return']);
    $this->get($url)->assertOk()->assertSee('Sales Return Correction in a Later Period')->assertSee('js-date-picker', false)->assertSee('novalidate', false);
    $payload = laterReturnPayload($f);
    $proposalId = $this->postJson(route('admin.sales.sales-returns.corrections.store', $f['return']), $payload)->assertOk()->json('data.proposal_id');
    expect($service->prepare($f['return'], $payload)->id)->toBe($proposalId)
        ->and(JournalEntry::query()->where('company_id', $f['company']->id)->count())->toBe($beforeJournals);
    $approveUrl = route('admin.sales.sales-returns.corrections.approve', [$f['return'], $proposalId]);
    $this->postJson($approveUrl, ['approval_reason' => 'SYNTHETIC maker cannot approve'])->assertUnprocessable();
    $this->postJson(route('admin.sales.sales-returns.correct-closed', $f['return']), ['reason' => 'SYNTHETIC old endpoint remains bounded'])->assertUnprocessable();
    laterReturnActor($f, $f['reviewer']);
    $response = $this->postJson($approveUrl, ['approval_reason' => 'SYNTHETIC independent correction review'])->assertOk()->assertJsonPath('data.status', 'approved');
    $replacement = SalesReturn::query()->findOrFail($response->json('data.replacement_return_id'));
    $inverse = JournalEntry::query()->findOrFail($journal->fresh()->reversed_entry_id);
    $service->assertInverse($journal, $inverse, $f['target']->id, '2026-10-02');
    expect($f['period']->fresh()->is_closed)->toBeTrue()
        ->and($journal->fresh()->financial_period_id)->toBe($f['period']->id)
        ->and($journal->fresh()->entry_date->toDateString())->toBe('2026-09-28')
        ->and($journal->lines()->orderBy('line_no')->get()->map->getAttributes()->all())->toBe($originalLines)
        ->and($f['return']->fresh()->return_date->toDateString())->toBe('2026-09-28')
        ->and($f['return']->fresh()->financial_period_id)->toBe($f['period']->id)
        ->and($f['return']->fresh()->status)->toBe(SalesReturn::StatusCancelled)
        ->and($f['credit']->fresh()->posting_status)->toBe('reversed')
        ->and($originalInvoice->fresh()->paid_amount)->toBe('80.0000')
        ->and($originalInvoice->fresh()->credited_amount)->toBe('0.0000')
        ->and($originalInvoice->fresh()->remaining_amount)->toBe('20.0000')
        ->and($replacement->status)->toBe(SalesReturn::StatusPendingAuthorization)
        ->and($replacement->return_date->toDateString())->toBe('2026-10-02')
        ->and($replacement->financial_period_id)->toBe($f['target']->id)
        ->and($replacement->lines->sole()->quantity)->toBe('0.50000000');
    $this->postJson($approveUrl, ['approval_reason' => 'SYNTHETIC retry'])->assertOk()->assertJsonPath('data.replacement_return_id', $replacement->id);
    expect(JournalEntry::query()->where('company_id', $f['company']->id)->count())->toBe($beforeJournals + 1)
        ->and(SalesReturnCorrection::query()->where('company_id', $f['company']->id)->count())->toBe(1);
    $this->get($url)->assertOk()->assertSee(route('admin.sales.sales-returns.show', $replacement), false);
    $this->get(route('admin.sales.sales-returns.show', $replacement))->assertOk();
});

test('later-period physical return correction reverses quality disposition before receipt and keeps source cutoff quantities and costs', function (string $state, bool $unbilled): void {
    $f = laterReturnCloseSource(laterReturnFixture($state, $unbilled));
    $service = app(SalesReturnCorrectionService::class);
    $company = $f['company']->id;
    $originals = InventoryTransaction::query()->where('company_id', $company)->where('is_reversal', false)->orderBy('id')->get();
    $source = $originals->map->getAttributes()->all();
    $stock = fn (string $date): array => InventoryTransaction::query()->where('company_id', $company)->whereDate('transaction_date', '<=', $date)
        ->selectRaw('product_id, branch_store_id, stock_status, sum(quantity_in - quantity_out) as quantity, sum(case when quantity_in > 0 then total_cost else -total_cost end) as value')
        ->groupBy('product_id', 'branch_store_id', 'stock_status')->orderBy('product_id')->orderBy('branch_store_id')->orderBy('stock_status')->get()->map(fn ($row) => [$row->product_id, $row->branch_store_id, $row->stock_status, (string) $row->quantity, (string) $row->value])->all();
    $cutoff = $stock('2026-09-30');
    $report = app(SalesCostReportService::class);
    $filters = ['financial_period_id' => $f['period']->id, 'from' => '2026-09-01', 'to' => '2026-09-30'];
    $sourceReportBefore = $report->report($company, $f['branch']->id, $filters);
    $proposal = $service->prepare($f['return'], laterReturnPayload($f, ['lines' => [['sales_return_line_id' => $f['return']->lines->sole()->id, 'quantity' => '3']]]));
    laterReturnActor($f, $f['reviewer']);
    $approved = $service->approve($f['return'], $proposal->id, 'SYNTHETIC independently reviewed stock and books');
    expect($stock('2026-09-30'))->toBe($cutoff)
        ->and(InventoryTransaction::query()->whereIn('id', $originals->modelKeys())->orderBy('id')->get()->map->getAttributes()->all())->toBe($source);
    $sourceReportAfter = $report->report($company, $f['branch']->id, $filters);
    $renderedRows = fn ($result): array => $result->rows->map(fn ($row): array => [...$row, 'posting_date' => $row['posting_date']->toDateString()])->all();
    expect($sourceReportAfter->summary)->toBe($sourceReportBefore->summary)
        ->and($renderedRows($sourceReportAfter))->toBe($renderedRows($sourceReportBefore));
    $correctionReport = $report->report($company, $f['branch']->id, ['financial_period_id' => $f['target']->id, 'from' => '2026-10-01', 'to' => '2026-10-02']);
    expect($correctionReport->rows->sole()['movement_kind'])->toBe('return_correction')
        ->and($correctionReport->summary['net_cost'])->toBe('25.0000')->and($correctionReport->summary['unreconciled_count'])->toBe(0);
    $reversals = InventoryTransaction::query()->where('company_id', $company)->where('is_reversal', true)->orderBy('id')->get();
    expect($reversals)->not->toBeEmpty();
    foreach ($reversals as $inverse) {
        expect($inverse->transaction_date->toDateString())->toBe('2026-10-02')->and($inverse->financial_period_id)->toBe($f['target']->id);
    }
    $returnDocIds = InventoryDocument::query()->where('source_document_type', SalesReturn::class)->where('source_document_id', $f['return']->id)->pluck('id');
    $net = InventoryTransaction::query()->where('source_type', InventoryDocument::class)->whereIn('source_id', $returnDocIds)->selectRaw('sum(quantity_in - quantity_out) as quantity')->first();
    expect(bccomp((string) $net->quantity, '0', 8))->toBe(0);
    $replacement = $approved->replacementReturn;
    expect($replacement->status)->toBe(SalesReturn::StatusPendingAuthorization)->and($replacement->lines->sole()->quantity)->toBe('3.00000000');
    $returns = app(SalesReturnService::class);
    $replacement = $returns->receive($returns->authorize($replacement));
    $replacement = $returns->inspect($replacement, [['sales_return_line_id' => $replacement->lines->sole()->id, 'saleable_quantity' => '3']]);
    $replacement = $returns->close($replacement);
    expect($replacement->status)->toBe(SalesReturn::StatusClosed)->and($replacement->financial_period_id)->toBe($f['target']->id)
        ->and($stock('2026-09-30'))->toBe($cutoff);
})->with(['received invoiced' => ['received', false], 'inspected unbilled' => ['inspected', true], 'closed invoiced' => ['closed', false]]);

test('later-period return requires each allocation and cash or bank refund to be recovered with a separate independent approval', function (string $method): void {
    $f = laterReturnFixture();
    $credits = app(CustomerCreditService::class);
    $target = salesPostedServiceInvoice($f, '40');
    $allocation = $credits->allocate($f['credit'], $target, '10', '2026-09-28', $target->paymentSchedules->sole());
    $refund = $credits->refund($f['credit']->fresh(), [
        'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id, 'refund_date' => '2026-09-28', 'currency_id' => $f['currency']->id,
        'exchange_rate' => 1, 'payment_method' => $method, 'cashbox_id' => $f['cashbox']->id,
        'bank_account_id' => $f['bankAccount']->id, 'amount' => '5', 'reference_no' => 'SYNTHETIC refund original',
    ]);
    $sourceJournal = $refund->journalEntry;
    $sourceLines = $sourceJournal->lines()->orderBy('line_no')->get()->map->getAttributes()->all();
    $f = laterReturnCloseSource($f);
    $service = app(SalesReturnCorrectionService::class);
    expect(fn () => $service->prepare($f['return'], laterReturnPayload($f)))->toThrow(DomainException::class);
    $allocationProposal = $service->prepare($f['return'], laterReturnPayload($f, ['operation' => 'allocation', 'source_id' => $allocation->id]));
    laterReturnActor($f, $f['reviewer']);
    $service->approve($f['return'], $allocationProposal->id, 'SYNTHETIC independent allocation recovery');
    expect($allocation->fresh()->status)->toBe('reversed')->and($allocation->fresh()->financial_period_id)->toBe($f['period']->id)
        ->and($allocation->fresh()->allocation_date->toDateString())->toBe('2026-09-28')->and($target->fresh()->remaining_amount)->toBe('40.0000')
        ->and($target->paymentSchedules->sole()->fresh()->credited_amount)->toBe('0.0000')
        ->and($refund->fresh()->status)->toBe('posted')->and($f['return']->fresh()->status)->toBe(SalesReturn::StatusClosed);
    laterReturnActor($f, $f['user']);
    expect(fn () => $service->prepare($f['return'], laterReturnPayload($f)))->toThrow(DomainException::class);
    expect(fn () => $service->prepare($f['return'], laterReturnPayload($f, ['operation' => 'refund', 'source_id' => $refund->id])))->toThrow(DomainException::class);
    $refundProposal = $service->prepare($f['return'], laterReturnPayload($f, ['operation' => 'refund', 'source_id' => $refund->id, 'recovery_reference' => 'SYNTHETIC recovered '.$method.' funds receipt']));
    laterReturnActor($f, $f['reviewer']);
    $service->approve($f['return'], $refundProposal->id, 'SYNTHETIC independent actual refund recovery');
    $refund = $refund->fresh();
    $service->assertInverse($sourceJournal, $refund->reversalJournalEntry, $f['target']->id, '2026-10-02');
    expect($sourceJournal->lines()->orderBy('line_no')->get()->map->getAttributes()->all())->toBe($sourceLines)
        ->and($refund->financial_period_id)->toBe($f['period']->id)->and($refund->refund_date->toDateString())->toBe('2026-09-28')
        ->and($credits->refundReversalEvidenceValid($refund, $f['credit']->fresh()))->toBeTrue()
        ->and($f['credit']->fresh()->credit_available_amount)->toBe('30.0000')
        ->and($f['credit']->fresh()->credit_refunded_amount)->toBe('0.0000')->and($f['credit']->fresh()->credit_allocated_amount)->toBe('0.0000');
    $effect = $refund->reversal_effect_snapshot;
    $changed = [...$effect, 'credit_available_before' => '10', 'credit_available_after' => '15'];
    DB::table('customer_credit_refunds')->where('id', $refund->id)->update(['reversal_effect_snapshot' => json_encode($changed)]);
    expect($credits->refundReversalEvidenceValid($refund->fresh(), $f['credit']->fresh()))->toBeFalse();
    DB::table('customer_credit_refunds')->where('id', $refund->id)->update(['reversal_effect_snapshot' => json_encode($effect)]);
    laterReturnActor($f, $f['user']);
    $returnProposal = $service->prepare($f['return'], laterReturnPayload($f));
    laterReturnActor($f, $f['reviewer']);
    $service->approve($f['return'], $returnProposal->id, 'SYNTHETIC final return correction after separate recoveries');
    expect($f['return']->fresh()->status)->toBe(SalesReturn::StatusCancelled)
        ->and(SalesReturnCorrection::query()->where('company_id', $f['company']->id)->where('status', 'approved')->count())->toBe(3);
})->with(['cash', 'bank']);

test('later-period correction rejects stale period, journal, product, or proposal facts without partial execution', function (string $change): void {
    $f = laterReturnCloseSource(laterReturnFixture());
    $service = app(SalesReturnCorrectionService::class);
    $proposal = $service->prepare($f['return'], laterReturnPayload($f));
    $journalCount = JournalEntry::query()->where('company_id', $f['company']->id)->count();
    match ($change) {
        'source reopened' => $f['period']->update(['is_closed' => false]),
        'target bounds' => $f['target']->update(['to_date' => '2026-10-30']),
        'target closed' => $f['target']->update(['is_closed' => true]),
        'product' => $f['service']->update(['name' => 'SYNTHETIC changed product after review']),
        'journal' => DB::table('journal_entry_lines')->where('journal_entry_id', $f['credit']->journal_entry_id)->update(['description' => 'SYNTHETIC changed journal']),
        'proposal' => DB::table('sales_return_corrections')->where('id', $proposal->id)->update(['reason' => 'SYNTHETIC forged proposal']),
    };
    laterReturnActor($f, $f['reviewer']);
    $this->postJson(route('admin.sales.sales-returns.corrections.approve', [$f['return'], $proposal->id]), ['approval_reason' => 'SYNTHETIC stale approval attempt'])->assertUnprocessable();
    expect($proposal->fresh()->status)->toBe('prepared')->and($f['return']->fresh()->status)->toBe(SalesReturn::StatusClosed)
        ->and($f['credit']->fresh()->posting_status)->toBe('posted')->and($f['credit']->journalEntry->fresh()->reversed_entry_id)->toBeNull()
        ->and(JournalEntry::query()->where('company_id', $f['company']->id)->count())->toBe($journalCount);
})->with(['source reopened', 'target bounds', 'target closed', 'product', 'journal', 'proposal']);

test('later-period proposal boundaries reject permission revocation, wrong branch and unauthorized rejection', function (): void {
    $f = laterReturnCloseSource(laterReturnFixture());
    $url = route('admin.sales.sales-returns.corrections.store', $f['return']);
    $payload = laterReturnPayload($f);
    $f['user']->revokePermissionTo('sales_returns.correct_later_period');
    $this->postJson($url, $payload)->assertForbidden();
    $f['user']->givePermissionTo('sales_returns.correct_later_period');
    $branch = Branch::query()->create(['company_id' => $f['company']->id, 'doc_number' => (int) Branch::withTrashed()->max('doc_number') + 1, 'doc_num' => 'SYNTHETIC-OTHER-RETURN-BRANCH-'.((int) Branch::withTrashed()->max('doc_number') + 1), 'name' => 'SYNTHETIC other branch', 'type' => Branch::TypeAdministrative, 'status' => 'active']);
    $this->withSession([OperatingContextService::BranchIdKey => $branch->id, OperatingContextService::BranchDocNumKey => $branch->doc_num]);
    $this->postJson($url, $payload)->assertNotFound();
    laterReturnActor($f, $f['user']);
    $proposal = app(SalesReturnCorrectionService::class)->prepare($f['return'], $payload);
    laterReturnActor($f, $f['reviewer']);
    $f['reviewer']->revokePermissionTo('sales_returns.correct_closed');
    $this->postJson(route('admin.sales.sales-returns.corrections.approve', [$f['return'], $proposal->id]), ['approval_reason' => 'SYNTHETIC permission revoked'])->assertForbidden();
    $this->postJson(route('admin.sales.sales-returns.corrections.reject', [$f['return'], $proposal->id]))->assertForbidden();
    $f['reviewer']->givePermissionTo('sales_returns.correct_closed');
    $this->postJson(route('admin.sales.sales-returns.corrections.reject', [$f['return'], $proposal->id]))->assertOk();
    expect($proposal->fresh()->status)->toBe('rejected')->and($f['credit']->fresh()->posting_status)->toBe('posted');
});

test('failed replacement creation rolls back every target inverse, stock movement and source counter', function (): void {
    $f = laterReturnCloseSource(laterReturnFixture('closed'));
    $service = app(SalesReturnCorrectionService::class);
    $proposal = $service->prepare($f['return'], laterReturnPayload($f, ['lines' => [['sales_return_line_id' => $f['return']->lines->sole()->id, 'quantity' => '3']]]));
    $tables = ['sales_returns', 'sales_return_lines', 'customer_invoices', 'customer_invoice_payment_schedules', 'sales_order_lines', 'inventory_documents', 'inventory_transactions', 'inventory_receipt_layers', 'inventory_layer_allocations', 'journal_entries', 'journal_entry_lines'];
    $snapshot = fn (): array => collect($tables)->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
    $before = $snapshot();
    $originalDispatcher = SalesReturn::getEventDispatcher();
    SalesReturn::setEventDispatcher(clone $originalDispatcher);
    SalesReturn::creating(fn () => throw new RuntimeException('SYNTHETIC injected replacement failure'));
    laterReturnActor($f, $f['reviewer']);
    try {
        expect(fn () => $service->approve($f['return'], $proposal->id, 'SYNTHETIC rollback acceptance'))->toThrow(RuntimeException::class, 'SYNTHETIC injected replacement failure');
    } finally {
        SalesReturn::setEventDispatcher($originalDispatcher);
    }
    expect($snapshot())->toBe($before)->and($proposal->fresh()->status)->toBe('prepared')->and($proposal->fresh()->approved_by)->toBeNull();
    $service->approve($f['return'], $proposal->id, 'SYNTHETIC retry after failure cleared');
    expect($proposal->fresh()->status)->toBe('approved');
});
