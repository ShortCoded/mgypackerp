<?php

use App\Services\PostingAccountResolver;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OpenDocumentsService;
use Modules\Core\Services\RequestMemo;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesFulfillmentService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/InventoryMovementCorrectionSupport.php';
require_once __DIR__.'/CustomerInvoiceCorrectionSupport.php';

test('unbilled delivery recovery proves current effects before reuse and production dependency clearance', function (): void {
    $f = invoiceCorrectionFixture(createInvoice: false);
    foreach (['inventory.documents.correct_prepare', 'inventory.documents.correct_approve', 'inventory.documents.correct_later_period'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
        $f['approver']->givePermissionTo($permission);
    }
    $f['period']->update(['is_closed' => true]);
    $periodNumber = (int) FinancialPeriod::withTrashed()->max('doc_number') + 1;
    $f['target'] = FinancialPeriod::query()->create([
        'company_id' => $f['company']->id,
        'doc_number' => $periodNumber,
        'doc_num' => 'SYNTHETIC-UNBILLED-EFFECT-'.$periodNumber,
        'name' => 'SYNTHETIC unbilled effect proof',
        'from_date' => '2026-10-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);
    $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
    invoiceCorrectionActor($f, $f['user']);
    $service = app(InventoryMovementCorrectionService::class);
    $preview = $service->preview($f['delivery']);
    $proposal = $service->prepare($f['delivery'], [
        'source_fingerprint' => $preview['source_fingerprint'],
        'operation' => 'reverse',
        'posting_date' => '2026-10-03',
        'reason' => 'SYNTHETIC independently reviewed current effect proof',
    ]);
    invoiceCorrectionActor($f, $f['approver']);
    $approved = $service->approve($f['delivery'], $proposal->id, 'SYNTHETIC independent current effect review');
    $production = app(ProductionRunCorrectionService::class);
    $service->assertApproved($approved);
    expect($service->approve($f['delivery'], $proposal->id, 'SYNTHETIC healthy approved replay')->id)->toBe($proposal->id)
        ->and($production->preview($f['run'])['correction_steps'])->toBe([]);

    $reversalLine = $f['delivery']->fresh()->reversalJournalEntry->lines()->orderBy('id')->firstOrFail();
    $amountField = bccomp((string) $reversalLine->debit_amount, '0', 4) > 0 ? 'debit_amount' : 'credit_amount';
    $journalAmount = (string) $reversalLine->{$amountField};
    DB::table('journal_entry_lines')->where('id', $reversalLine->id)->update([$amountField => bcadd($journalAmount, '1', 4)]);
    expect(fn () => $service->approve($f['delivery'], $proposal->id, 'SYNTHETIC corrupt journal replay'))->toThrow(DomainException::class)
        ->and(fn () => $production->preview($f['run']))->toThrow(DomainException::class);
    DB::table('journal_entry_lines')->where('id', $reversalLine->id)->update([$amountField => $journalAmount]);

    $inverse = $f['delivery']->transactions()->where('is_reversal', true)->sole();
    $inverseCost = (string) $inverse->total_cost;
    DB::table('inventory_transactions')->where('id', $inverse->id)->update(['total_cost' => bcadd($inverseCost, '1', 8)]);
    expect(fn () => $service->assertApproved($proposal->fresh()))->toThrow(DomainException::class)
        ->and(fn () => $production->preview($f['run']))->toThrow(DomainException::class);
    DB::table('inventory_transactions')->where('id', $inverse->id)->update(['total_cost' => $inverseCost]);

    $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $inverse->reversal_of_id)->firstOrFail();
    $allocationAttributes = $allocation->getAttributes();
    $otherLayerId = InventoryReceiptLayer::query()->where('receipt_transaction_id', $inverse->id)->firstOrFail()->id;
    foreach (['quantity' => '0.1', 'cost_total_snapshot' => '0.1', 'inventory_receipt_layer_id' => $otherLayerId] as $field => $corrupt) {
        DB::table('inventory_layer_allocations')->where('id', $allocation->id)->update([$field => $corrupt]);
        expect(fn () => $service->assertApproved($proposal->fresh()))->toThrow(DomainException::class)
            ->and(fn () => $production->preview($f['run']))->toThrow(DomainException::class);
        DB::table('inventory_layer_allocations')->where('id', $allocation->id)->update([$field => $allocationAttributes[$field]]);
    }
    DB::table('inventory_documents')->where('id', $f['delivery']->id)->update(['status' => InventoryDocument::StatusPosted]);
    expect(fn () => $service->assertApproved($proposal->fresh()))->toThrow(DomainException::class)
        ->and(fn () => $production->preview($f['run']))->toThrow(DomainException::class);
    DB::table('inventory_documents')->where('id', $f['delivery']->id)->update(['status' => InventoryDocument::StatusReversed]);

    $delivered = (string) $f['salesLine']->fresh()->delivered_quantity;
    DB::table('sales_order_lines')->where('id', $f['salesLine']->id)->update(['delivered_quantity' => bcadd($delivered, '1', 8)]);
    expect(fn () => $service->assertApproved($proposal->fresh()))->toThrow(DomainException::class)
        ->and(fn () => $production->preview($f['run']))->toThrow(DomainException::class);
    DB::table('sales_order_lines')->where('id', $f['salesLine']->id)->update(['delivered_quantity' => $delivered]);

    $replacement = app(SalesFulfillmentService::class)->deliver($f['salesOrder']->fresh(), [
        ['sales_order_line_id' => $f['salesLine']->id, 'quantity' => '9'],
    ]);
    $invoiceService = app(CustomerInvoiceService::class);
    $invoice = $invoiceService->post($invoiceService->createFromOrder($f['salesOrder']->fresh(), [[
        'sales_order_line_id' => $f['salesLine']->id,
        'delivery_line_id' => $replacement->lines->sole()->id,
        'quantity' => '9',
    ]], [['due_date' => '2026-10-03', 'amount' => '90']], $replacement));
    $service->assertApproved($proposal->fresh());
    expect($service->approve($f['delivery'], $proposal->id, 'SYNTHETIC replay after healthy replacement')->id)->toBe($proposal->id)
        ->and($invoice->total_amount)->toBe('90.0000')
        ->and($f['salesLine']->fresh()->delivered_quantity)->toBe('9.00000000')
        ->and($f['salesLine']->fresh()->invoiced_quantity)->toBe('9.00000000');
});

test('manual receipt recovery seals its inverse issue allocations', function (): void {
    $f = manualCorrectionClose(manualCorrectionFixture());
    $approved = manualCorrectionExecute($f, $f['receipt']);
    $service = app(InventoryMovementCorrectionService::class);
    $service->assertApproved($approved);
    $inverse = $f['receipt']->transactions()->where('is_reversal', true)->sole();
    $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $inverse->id)->firstOrFail();
    foreach (['quantity', 'cost_unit_snapshot', 'cost_total_snapshot'] as $field) {
        DB::table('inventory_layer_allocations')->where('id', $allocation->id)->update([$field => '0.1']);
        expect(fn () => $service->assertApproved($approved->fresh()))->toThrow(DomainException::class);
        DB::table('inventory_layer_allocations')->where('id', $allocation->id)->update([$field => $allocation->{$field}]);
    }
    $service->assertApproved($approved->fresh());
});

test('manual correction cannot take ownership of a legacy source id with a missing source type', function (): void {
    $f = manualCorrectionFixture();
    $document = $f['receipt'];
    $payload = manualCorrectionPayload($f, $document);
    $document->forceFill(['source_document_id' => 123, 'is_closed' => false])->save();
    $transactions = $document->transactions()->get()->map->getAttributes()->all();
    $layer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $document->transactions()->sole()->id)->sole()->getAttributes();
    $journal = $document->journalEntry;
    $journalLines = $journal->lines()->get()->map->getAttributes()->all();
    expect(fn () => app(InventoryMovementCorrectionService::class)->prepare($document, $payload))
        ->toThrow(DomainException::class, __('inventory_correction.source'));
    expect(app(InventoryDocumentPostingService::class)->reversalPlan($document)['blockers'])
        ->toContain(__('inventory.movements.reversal.source_workflow_required'));
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($document, 'SYNTHETIC denied ownership bypass'))
        ->toThrow(DomainException::class, __('inventory.movements.reversal.source_workflow_required'));
    expect($document->fresh()->status)->toBe('posted')
        ->and($document->transactions()->get()->map->getAttributes()->all())->toBe($transactions)
        ->and(InventoryReceiptLayer::query()->findOrFail($layer['id'])->getAttributes())->toBe($layer)
        ->and($journal->lines()->get()->map->getAttributes()->all())->toBe($journalLines)
        ->and($document->fresh()->reversal_journal_entry_id)->toBeNull();
});

test('selected layer quantity correction increases an issue or transfer with exact allocation and original history', function (string $type, string $cost): void {
    $f = manualCorrectionFixture(false, $cost, InventoryCostPolicy::SpecificIdentification);
    $originalLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $f['receipt']->transactions()->sole()->id)->sole();
    $destination = BranchStore::query()->create(['branch_id' => $f['branch']->id, 'name' => 'SYNTHETIC increased transfer destination', 'position' => 3]);
    $document = manualCorrectionMovement($f, $type, '4', null, ['selected_receipt_layer_id' => $originalLayer->id],
        $type === InventoryDocument::TypeTransfer ? ['destination_branch_store_id' => $destination->id] : []);
    $original = $document->transactions()->orderBy('id')->get()->map->getAttributes()->all();
    $journalLines = $document->journalEntry?->lines()->orderBy('line_no')->get()->map->getAttributes()->all();
    $f = manualCorrectionClose($f);
    $this->postJson(route('admin.inventory.documents.corrections.store', $document), manualCorrectionPayload($f, $document, 'replace', '6', '999'))
        ->assertOk()->assertJsonPath('data.status', 'prepared');
    $proposal = InventoryMovementCorrection::query()->where('inventory_document_id', $document->id)->sole();
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$document, $proposal->id]), ['approval_reason' => 'SYNTHETIC same actor denied'])
        ->assertUnprocessable();
    manualCorrectionActor($f, $f['reviewer']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$document, $proposal->id]), ['approval_reason' => 'SYNTHETIC independent increased quantity review'])
        ->assertOk()->assertJsonPath('data.status', 'approved');
    $replacement = $proposal->fresh()->replacementDocument;
    $out = $replacement->transactions()->where('quantity_out', '>', 0)->orderBy('id')->get();
    expect($replacement->status)->toBe('posted')->and($replacement->is_closed)->toBeTrue()
        ->and($replacement->financial_period_id)->toBe($f['target']->id)
        ->and($out->pluck('quantity_out')->all())->toBe(['4.00000000', '2.00000000'])
        ->and($originalLayer->fresh()->remaining_quantity)->toBe('4.00000000')
        ->and($out->pluck('unit_cost')->unique()->all())->toBe([$cost])
        ->and($document->transactions()->where('is_reversal', false)->orderBy('id')->get()->map->getAttributes()->all())->toBe($original)
        ->and($document->journalEntry?->lines()->orderBy('line_no')->get()->map->getAttributes()->all())->toBe($journalLines);
    $restored = InventoryReceiptLayer::query()->whereIn('receipt_transaction_id', $document->transactions()->where('is_reversal', true)->where('quantity_in', '>', 0)->pluck('id'))
        ->where('branch_store_id', $f['store']->id)->sole();
    expect($restored->remaining_quantity)->toBe('0.00000000');
    $allocations = InventoryLayerAllocation::query()->whereIn('issue_transaction_id', $out->modelKeys())->orderBy('id')->get();
    expect($allocations->pluck('inventory_receipt_layer_id')->all())->toBe([$restored->id, $originalLayer->id])
        ->and($allocations->pluck('quantity')->all())->toBe(['4.00000000', '2.00000000']);
    $sourceBalance = InventoryTransaction::query()->where('branch_store_id', $f['store']->id)->where('product_id', $f['finished']->id)
        ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value')->first();
    expect(bccomp((string) $sourceBalance->quantity, '4', 8))->toBe(0)
        ->and(bccomp((string) $sourceBalance->value, bcmul('4', $cost, 8), 8))->toBe(0);
    if ($type === InventoryDocument::TypeTransfer) {
        $destinationBalance = InventoryTransaction::query()->where('branch_store_id', $destination->id)->where('product_id', $f['finished']->id)
            ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value')->first();
        expect(bccomp((string) $destinationBalance->quantity, '6', 8))->toBe(0)
            ->and(bccomp((string) $destinationBalance->value, bcmul('6', $cost, 8), 8))->toBe(0);
    }
    foreach ([$replacement->journalEntry, $document->fresh()->reversalJournalEntry] as $journal) {
        if ($type === InventoryDocument::TypeTransfer) {
            expect($journal)->toBeNull();

            continue;
        }
        expect(bccomp((string) $journal->lines()->sum('debit_amount'), (string) $journal->lines()->sum('credit_amount'), 4))->toBe(0);
    }
    if ($type === InventoryDocument::TypeIssue) {
        expect(bccomp((string) $replacement->journalEntry->lines()->sum('debit_amount'), $cost === '2.00000000' ? '12.0000' : '12.7407', 4))->toBe(0)
            ->and(bccomp((string) $document->fresh()->reversalJournalEntry->lines()->sum('debit_amount'), $cost === '2.00000000' ? '8.0000' : '8.4938', 4))->toBe(0);
    } else {
        expect(JournalEntry::query()->where('company_id', $f['company']->id)->where('source_type', 'inventory_document_posting')
            ->whereIn('source_id', [$document->id, $replacement->id])->count())->toBe(0);
    }
})->with([
    [InventoryDocument::TypeIssue, '2.00000000'], [InventoryDocument::TypeTransfer, '2.00000000'],
    [InventoryDocument::TypeIssue, '2.12345678'], [InventoryDocument::TypeTransfer, '2.12345678'],
]);

test('selected layer correction cannot increase beyond the original layer remaining stock and rolls back every effect', function (): void {
    $f = manualCorrectionFixture(false, '2', InventoryCostPolicy::SpecificIdentification);
    $layer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $f['receipt']->transactions()->sole()->id)->sole();
    $document = manualCorrectionMovement($f, InventoryDocument::TypeIssue, '4', null, ['selected_receipt_layer_id' => $layer->id]);
    $f = manualCorrectionClose($f);
    $proposal = app(InventoryMovementCorrectionService::class)->prepare($document, manualCorrectionPayload($f, $document, 'replace', '11'));
    $tables = ['inventory_documents', 'inventory_transactions', 'inventory_receipt_layers', 'inventory_layer_allocations', 'journal_entries', 'journal_entry_lines'];
    $before = collect($tables)->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
    manualCorrectionActor($f, $f['reviewer']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$document, $proposal->id]), ['approval_reason' => 'SYNTHETIC insufficient selected-layer review'])
        ->assertUnprocessable();
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toArray())->toEqual($before[$table]);
    }
    expect($document->fresh()->status)->toBe('posted')->and($proposal->fresh()->status)->toBe('prepared')
        ->and($layer->fresh()->remaining_quantity)->toBe('6.00000000');
});

test('manual correction posts approved replacement atomically in a later period with exact original history', function (): void {
    $f = manualCorrectionClose(manualCorrectionFixture());
    $document = $f['receipt'];
    $original = $document->transactions()->sole()->getAttributes();
    $sourceJournal = $document->journalEntry;
    $journalLines = $sourceJournal->lines()->orderBy('line_no')->get()->map->getAttributes()->all();
    $this->get(route('admin.inventory.documents.show', $document))->assertOk();
    $this->get(route('admin.inventory.documents.corrections.index', $document))->assertOk()->assertSee('js-date-picker', false)->assertSee('novalidate', false);
    $payload = manualCorrectionPayload($f, $document);
    $proposal = app(InventoryMovementCorrectionService::class)->prepare($document, $payload);
    expect(fn () => app(InventoryMovementCorrectionService::class)->approve($document, $proposal->id, 'SYNTHETIC same reviewer'))->toThrow(DomainException::class);
    manualCorrectionActor($f, $f['reviewer']);
    $result = app(InventoryMovementCorrectionService::class)->approve($document, $proposal->id, 'SYNTHETIC independent review');
    $replacement = $result->replacementDocument;
    $inverse = $document->transactions()->where('is_reversal', true)->sole();
    expect($result->status)->toBe('approved')->and($replacement->status)->toBe(InventoryDocument::StatusPosted)->and($replacement->is_closed)->toBeTrue()
        ->and($replacement->financial_period_id)->toBe($f['target']->id)->and($replacement->lines->sole()->quantity)->toBe('6.00000000')
        ->and($replacement->lines->sole()->unit_cost)->toBe('3.00000000')->and($inverse->quantity_out)->toBe('10.00000000')
        ->and($inverse->total_cost)->toBe('20.00000000')->and($inverse->financial_period_id)->toBe($f['target']->id)
        ->and($document->transactions()->where('is_reversal', false)->sole()->getAttributes())->toBe($original)
        ->and($sourceJournal->fresh()->entry_date->toDateString())->toBe('2026-09-28')
        ->and($sourceJournal->lines()->orderBy('line_no')->get()->map->getAttributes()->all())->toBe($journalLines)
        ->and($document->fresh()->reversalJournalEntry->financial_period_id)->toBe($f['target']->id)
        ->and($f['period']->fresh()->is_closed)->toBeTrue();
    $count = InventoryTransaction::query()->count();
    expect(app(InventoryMovementCorrectionService::class)->approve($document, $proposal->id, 'SYNTHETIC replay review')->id)->toBe($proposal->id)
        ->and(InventoryTransaction::query()->count())->toBe($count);
});

test('manual dependency plan recovers whole issue then transfer before original receipt without changing unrelated receipt', function (): void {
    $f = manualCorrectionFixture();
    $destination = BranchStore::query()->create(['branch_id' => $f['branch']->id, 'name' => 'SYNTHETIC transfer destination', 'position' => 2]);
    $transfer = manualCorrectionMovement($f, InventoryDocument::TypeTransfer, '4', null, [], ['destination_branch_store_id' => $destination->id]);
    $destinationF = [...$f, 'store' => $destination];
    $issue = manualCorrectionMovement($destinationF, InventoryDocument::TypeIssue, '1');
    $unrelated = manualCorrectionMovement($f, InventoryDocument::TypeReceipt, '3', '7');
    $unrelatedLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $unrelated->transactions()->sole()->id)->sole()->getAttributes();
    $f = manualCorrectionClose($f);
    $service = app(InventoryMovementCorrectionService::class);
    $plan = $service->preview($f['receipt']);
    expect(array_column($plan['steps'], 'id'))->toBe([$issue->id, $transfer->id]);
    expect(fn () => $service->prepare($f['receipt'], manualCorrectionPayload($f, $f['receipt'])))->toThrow(DomainException::class);
    manualCorrectionExecute($f, $issue);
    manualCorrectionExecute($f, $transfer);
    $result = manualCorrectionExecute($f, $f['receipt'], 'replace');
    expect($result->status)->toBe('approved')->and($issue->fresh()->status)->toBe('reversed')->and($transfer->fresh()->status)->toBe('reversed')
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $unrelated->transactions()->sole()->id)->sole()->getAttributes())->toBe($unrelatedLayer);
});

test('manual serialized receipt correction retains one identity while replacing cost', function (): void {
    $f = manualCorrectionClose(manualCorrectionFixture(true));
    $original = $f['receipt']->transactions()->sole();
    $id = $original->inventory_serial_identity_id;
    $proposal = manualCorrectionExecute($f, $f['receipt'], 'replace', '1', '3.12345678');
    $replacement = $proposal->replacementDocument;
    $receipt = $replacement->transactions()->sole();
    expect($receipt->inventory_serial_identity_id)->toBe($id)->and($receipt->unit_cost)->toBe('3.12345678')
        ->and($original->fresh()->unit_cost)->toBe('2.00000000')
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->id)->sole()->unit_cost)->toBe('3.12345678')
        ->and($receipt->serialIdentity->current_receipt_layer_id)->toBe(InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->id)->sole()->id);
});

test('manual correction replacement posting failure rolls back source inverse and proposal approval', function (): void {
    $f = manualCorrectionClose(manualCorrectionFixture());
    $issue = null;
    $document = $f['receipt'];
    $proposal = app(InventoryMovementCorrectionService::class)->prepare($document, manualCorrectionPayload($f, $document));
    manualCorrectionActor($f, $f['reviewer']);
    $count = InventoryTransaction::query()->count();
    InventoryDocument::creating(function ($new) use (&$issue): void {
        if ($new->notes === 'SYNTHETIC correct manual inventory quantity and cost') {
            $issue = true;
            throw new DomainException('SYNTHETIC injected replacement failure');
        }
    });
    try {
        expect(fn () => app(InventoryMovementCorrectionService::class)->approve($document, $proposal->id, 'SYNTHETIC independent review'))->toThrow(DomainException::class, 'SYNTHETIC injected replacement failure');
    } finally {
        InventoryDocument::flushEventListeners();
        InventoryDocument::clearBootedModels();
    }
    expect($issue)->toBeTrue()->and($document->fresh()->status)->toBe('posted')->and($proposal->fresh()->status)->toBe('prepared')
        ->and(InventoryTransaction::query()->count())->toBe($count)->and($document->fresh()->reversal_journal_entry_id)->toBeNull();
});

test('manual correction rejects changed snapshots payload seals and missing original journals before inventory effects', function (): void {
    $f = manualCorrectionClose(manualCorrectionFixture());
    $document = $f['receipt'];
    $service = app(InventoryMovementCorrectionService::class);
    $proposal = $service->prepare($document, manualCorrectionPayload($f, $document));
    DB::table('inventory_movement_corrections')->where('id', $proposal->id)->update(['replacement_payload' => json_encode([['line_id' => $document->lines->sole()->id, 'quantity' => '9', 'unit_cost' => '99']])]);
    manualCorrectionActor($f, $f['reviewer']);
    expect(fn () => $service->approve($document, $proposal->id, 'SYNTHETIC independent review'))->toThrow(DomainException::class);
    expect($document->fresh()->status)->toBe('posted')->and($document->transactions()->where('is_reversal', true)->count())->toBe(0);
    $document->forceFill(['journal_entry_id' => null])->save();
    manualCorrectionActor($f, $f['user']);
    expect(fn () => $service->prepare($document, manualCorrectionPayload($f, $document)))->toThrow(DomainException::class);
});

test('manual correction revoked approval permission and closed target cannot mutate the source', function (): void {
    $f = manualCorrectionClose(manualCorrectionFixture());
    $service = app(InventoryMovementCorrectionService::class);
    $proposal = $service->prepare($f['receipt'], manualCorrectionPayload($f, $f['receipt']));
    manualCorrectionActor($f, $f['reviewer']);
    $f['reviewer']->revokePermissionTo('inventory.documents.correct_approve');
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['receipt'], $proposal->id]), ['approval_reason' => 'SYNTHETIC review'])->assertForbidden();
    $f['reviewer']->givePermissionTo(Permission::findByName('inventory.documents.correct_approve'));
    $f['target']->update(['is_closed' => true]);
    expect(fn () => $service->approve($f['receipt'], $proposal->id, 'SYNTHETIC review'))->toThrow(DomainException::class);
    expect($f['receipt']->fresh()->status)->toBe('posted')->and($proposal->fresh()->status)->toBe('prepared');
});

test('manual completed-cost receipt transfer issue recovery reconciles original adjustment and dated subset inverses', function (): void {
    $f = manualCorrectionFixture();
    $unpriced = $f['receipt'];
    $destination = BranchStore::query()->create(['branch_id' => $f['branch']->id, 'name' => 'SYNTHETIC completed transfer destination', 'position' => 2]);
    $transfer = manualCorrectionMovement($f, InventoryDocument::TypeTransfer, '4', null, ['batch_lot' => null], ['destination_branch_store_id' => $destination->id]);
    $issue = manualCorrectionMovement([...$f, 'store' => $destination], InventoryDocument::TypeIssue, '1');
    expect($f['company']->doc_num)->toStartWith('SYNTHETIC-');
    foreach ([$unpriced, $transfer, $issue] as $legacy) {
        $journalId = $legacy->journal_entry_id;
        $legacy->forceFill(['journal_entry_id' => null])->save();
        foreach ($legacy->lines as $line) {
            $snapshot = $line->product_snapshot;
            unset($snapshot['inventory_accounting']);
            DB::table('inventory_document_lines')->where('id', $line->id)->update(['unit_cost' => null, 'total_cost' => null, 'product_snapshot' => json_encode($snapshot)]);
        }
        $ids = $legacy->transactions()->pluck('id');
        DB::table('inventory_transactions')->whereIn('id', $ids)->update(['unit_cost' => null, 'total_cost' => null]);
        DB::table('inventory_receipt_layers')->whereIn('receipt_transaction_id', $ids)->update(['unit_cost' => null, 'source_allocation_cost_snapshot' => null]);
        DB::table('inventory_layer_allocations')->whereIn('issue_transaction_id', $ids)->update(['cost_unit_snapshot' => null, 'cost_total_snapshot' => null]);
        if ($journalId !== null) {
            DB::table('journal_entry_lines')->where('journal_entry_id', $journalId)->delete();
            DB::table('journal_entries')->where('id', $journalId)->delete();
        }
    }
    $unpriced = $unpriced->fresh()->load('lines');
    foreach (['propose_receipt_cost', 'approve_receipt_cost'] as $action) {
        $permission = Permission::findOrCreate('inventory.documents.'.$action, 'web');
        $f['user']->givePermissionTo($permission);
        $f['reviewer']->givePermissionTo($permission);
    }
    $counterpart = app(PostingAccountResolver::class)->resolve($f['company']->id,
        PostingAccountResolver::InventoryAdjustmentGain, 'SYNTHETIC cost evidence');
    manualCorrectionActor($f, $f['user']);
    $service = app(InventoryReceiptCostProposalService::class);
    $cost = $service->prepare(request(), $unpriced, ['basis' => InventoryReceiptCostProposal::BasisEstimate,
        'source_reference' => 'SYNTHETIC actual independent cost evidence', 'basis_note' => 'SYNTHETIC isolated estimate',
        'posting_date' => '2026-09-29', 'counterpart_account_id' => $counterpart->id], [$unpriced->lines->sole()->id => '3.12345678'], null);
    manualCorrectionActor($f, $f['reviewer']);
    $service->approve(request(), $unpriced, $cost, $cost->source_reference, 'SYNTHETIC independent cost evidence review');
    $f = manualCorrectionClose($f);
    manualCorrectionExecute($f, $issue);
    manualCorrectionExecute($f, $transfer);
    manualCorrectionExecute($f, $unpriced);
    $transactions = InventoryTransaction::query()->where('company_id', $f['company']->id)->where('product_id', $f['finished']->id)
        ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value')->first();
    expect(bccomp((string) $transactions->quantity, '0', 8))->toBe(0)->and(bccomp((string) $transactions->value, '0', 8))->toBe(0);
    $balances = DB::table('journal_entry_lines as line')->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
        ->where('journal.company_id', $f['company']->id)->where('journal.is_posted', true)->groupBy('line.account_id')
        ->selectRaw('line.account_id, sum(line.debit_amount-line.credit_amount) as net')->get();
    foreach ($balances as $balance) {
        expect(bccomp((string) $balance->net, '0', 4))->toBe(0);
    }
});

test('manual serialized transfer and issue replacements use restored selected layers and canonical costs', function (): void {
    $f = manualCorrectionFixture(true);
    $identityId = $f['receipt']->transactions()->sole()->inventory_serial_identity_id;
    $layer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $f['receipt']->transactions()->sole()->id)->sole();
    $destination = BranchStore::query()->create(['branch_id' => $f['branch']->id, 'name' => 'SYNTHETIC serial destination', 'position' => 2]);
    $transfer = manualCorrectionMovement($f, InventoryDocument::TypeTransfer, '1', null, ['selected_receipt_layer_id' => $layer->id], ['destination_branch_store_id' => $destination->id]);
    $destinationLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $transfer->transactions()->where('quantity_in', '>', 0)->sole()->id)->sole();
    $issue = manualCorrectionMovement([...$f, 'store' => $destination], InventoryDocument::TypeIssue, '1', null, ['selected_receipt_layer_id' => $destinationLayer->id]);
    $f = manualCorrectionClose($f);
    $issueResult = manualCorrectionExecute($f, $issue, 'replace', '1', '999');
    expect($issueResult->replacementDocument->transactions()->sole()->unit_cost)->toBe('2.00000000')
        ->and($issueResult->replacementDocument->transactions()->sole()->inventory_serial_identity_id)->toBe($identityId);
    $replacementRecovery = manualCorrectionExecute($f, $issueResult->replacementDocument);
    $corrections = app(InventoryMovementCorrectionService::class);
    $corrections->assertApproved($issueResult->fresh());
    $corrections->assertApproved($replacementRecovery->fresh());
    $transferResult = manualCorrectionExecute($f, $transfer, 'replace', '1', '999');
    expect($transferResult->replacementDocument->transactions()->where('quantity_out', '>', 0)->sole()->unit_cost)->toBe('2.00000000')
        ->and($transferResult->replacementDocument->transactions()->where('quantity_in', '>', 0)->sole()->inventory_serial_identity_id)->toBe($identityId);
});

test('manual late posting failure restores journals layers serial identity and all documents', function (): void {
    $f = manualCorrectionClose(manualCorrectionFixture(true));
    $document = $f['receipt'];
    $proposal = app(InventoryMovementCorrectionService::class)->prepare($document, manualCorrectionPayload($f, $document, 'replace', '1', '3'));
    $tables = ['inventory_documents', 'inventory_transactions', 'inventory_receipt_layers', 'inventory_layer_allocations', 'journal_entries', 'journal_entry_lines'];
    $before = collect($tables)->mapWithKeys(fn ($table): array => [$table => DB::table($table)->count()])->all();
    $serial = $document->transactions()->sole()->serialIdentity->getAttributes();
    manualCorrectionActor($f, $f['reviewer']);
    InventoryDocument::updating(function ($new) use ($document): void {
        if ($new->id !== $document->id && $new->status === InventoryDocument::StatusPosted) {
            throw new DomainException('SYNTHETIC fail after replacement stock and GL');
        }
    });
    try {
        expect(fn () => app(InventoryMovementCorrectionService::class)->approve($document, $proposal->id, 'SYNTHETIC independent review'))
            ->toThrow(DomainException::class, 'SYNTHETIC fail after replacement stock and GL');
    } finally {
        InventoryDocument::flushEventListeners();
        InventoryDocument::clearBootedModels();
    }
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count, $table);
    }
    expect($document->fresh()->status)->toBe('posted')->and($proposal->fresh()->status)->toBe('prepared')
        ->and($document->transactions()->sole()->serialIdentity->getAttributes())->toBe($serial);
});

test('manual correction tools navigate closed source and deny missing later-period authority', function (): void {
    $f = manualCorrectionClose(manualCorrectionFixture());
    $f['user']->revokePermissionTo('inventory.documents.correct_later_period');
    expect(fn () => app(InventoryMovementCorrectionService::class)->prepare($f['receipt'], manualCorrectionPayload($f, $f['receipt'])))
        ->toThrow(AuthorizationException::class);
    $f['user']->givePermissionTo(Permission::findByName('inventory.documents.correct_later_period'));
    request()->merge(['source_period_doc_num' => $f['period']->doc_num]);
    $plan = app(OpenDocumentsService::class)->preview(OpenDocumentsService::InventoryCorrections,
        $f['receipt']->doc_number, $f['receipt']->doc_number, request());
    expect($plan['navigation_only'])->toBeTrue()->and($plan['documents'][0]['correction_url'])->toBe(route('admin.inventory.documents.corrections.index', $f['receipt']));
    expect(fn () => app(OpenDocumentsService::class)->reopen(OpenDocumentsService::InventoryCorrections,
        $f['receipt']->doc_number, $f['receipt']->doc_number, request(), $plan['preview_token'], 'SYNTHETIC bulk review'))->toThrow(DomainException::class);
    expect($f['receipt']->fresh()->status)->toBe('posted');
});

test('manual correction preview and approval deny access to transfer destination branch', function (): void {
    $f = manualCorrectionFixture();
    $branchNumber = (int) Branch::withTrashed()->max('doc_number') + 1;
    $destinationBranch = Branch::query()->create(['company_id' => $f['company']->id, 'doc_number' => $branchNumber,
        'doc_num' => 'SYNTHETIC-MANUAL-DEST-'.$branchNumber, 'name' => 'SYNTHETIC destination branch', 'type' => 'factory', 'status' => 'active']);
    $store = BranchStore::query()->create(['branch_id' => $destinationBranch->id, 'name' => 'SYNTHETIC restricted destination', 'position' => 1]);
    $transfer = manualCorrectionMovement($f, InventoryDocument::TypeTransfer, '4', null, [], ['destination_branch_store_id' => $store->id]);
    $f = manualCorrectionClose($f);
    $proposal = app(InventoryMovementCorrectionService::class)->prepare($transfer, manualCorrectionPayload($f, $transfer, 'reverse'));
    $number = (int) Role::withTrashed()->max('doc_number') + 1;
    $role = Role::query()->create(['name' => 'SYNTHETIC restricted manual correction '.$number, 'guard_name' => 'web',
        'doc_number' => $number, 'doc_num' => 'SYNTHETIC-MANUAL-ROLE-'.$number, 'branch_access_restricted' => true]);
    DB::table('role_branch_access')->insert(['role_id' => $role->id, 'branch_id' => $f['branch']->id, 'created_at' => now(), 'updated_at' => now()]);
    $f['reviewer']->assignRole($role);
    app(RequestMemo::class)->forget("operating_scope_access.role_scope.{$f['reviewer']->id}");
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.branches.{$role->id}");
    manualCorrectionActor($f, $f['reviewer']);
    expect(fn () => app(InventoryMovementCorrectionService::class)->preview($transfer))->toThrow(HttpException::class);
    expect(fn () => app(InventoryMovementCorrectionService::class)->approve($transfer, $proposal->id, 'SYNTHETIC independent review'))
        ->toThrow(HttpException::class);
    expect($transfer->fresh()->status)->toBe('posted')->and($proposal->fresh()->status)->toBe('prepared');
});

test('manual correction reverses a booked completion subset against its frozen counterpart with full source conservation', function (): void {
    $f = manualCorrectionFixture();
    $transaction = $f['receipt']->transactions()->sole();
    $accounts = app(PostingAccountResolver::class);
    $inventory = $accounts->inventoryForProduct($f['company']->id, $f['finished'], 'SYNTHETIC canonical completion inventory');
    $counterpart = $accounts->resolve($f['company']->id, PostingAccountResolver::InventoryAdjustmentGain, 'SYNTHETIC canonical completion counterpart');
    $adjustment = InventoryValueAdjustment::query()->create(['company_id' => $f['company']->id,
        'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id, 'source_type' => 'SYNTHETIC_BOOKED_COMPLETION',
        'source_id' => $transaction->id, 'source_doc_num' => 'SYNTHETIC-BOOKED-COMPLETION', 'posting_date' => '2026-09-29',
        'status' => 'posted', 'source_snapshot' => ['synthetic_acceptance' => true], 'approved_by' => $f['reviewer']->id, 'approved_at' => now()]);
    $base = ['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id];
    $adjustment->lines()->create([...$base, 'effect' => 'stock', 'source_transaction_id' => $transaction->id,
        'account_id' => $inventory->id, 'amount' => '10.00000000', 'source_snapshot' => ['cost_completed' => true]]);
    $adjustment->lines()->create([...$base, 'effect' => 'counterpart', 'account_id' => $counterpart->id, 'amount' => '-10.00000000',
        'source_snapshot' => ['rounded_gl_total' => '10.0000']]);
    $journal = app(JournalEntryService::class)->createPostedFromSource([
        ...$base, 'entry_date' => '2026-09-29', 'currency_id' => Currency::query()->where('company_id', $f['company']->id)->where('is_main', true)->sole()->id,
        'exchange_rate' => '1', 'source_type' => $adjustment::class, 'source_id' => $adjustment->id, 'source_doc_num' => $adjustment->source_doc_num],
        [['account_id' => $inventory->id, 'branch_id' => $f['branch']->id, 'debit_amount' => '10.0000', 'credit_amount' => '0.0000'],
            ['account_id' => $counterpart->id, 'branch_id' => $f['branch']->id, 'debit_amount' => '0.0000', 'credit_amount' => '10.0000']]);
    $adjustment->forceFill(['journal_entry_id' => $journal->id])->save();
    $f = manualCorrectionClose($f);
    $result = manualCorrectionExecute($f, $f['receipt']);
    expect($result->status)->toBe('approved')->and($f['receipt']->transactions()->where('is_reversal', true)->sole()->total_cost)->toBe('30.00000000');
    $inverse = JournalEntry::query()->where('company_id', $f['company']->id)->where('source_type', 'inventory_document_cost_completion_reversal')->sole();
    expect($inverse->lines()->where('account_id', $inventory->id)->sole()->credit_amount)->toBe('10.0000')
        ->and($inverse->lines()->where('account_id', $counterpart->id)->sole()->debit_amount)->toBe('10.0000');
});

test('manual correction requires access to the separate booked completion period before preview or approval', function (): void {
    $f = manualCorrectionClose(manualCorrectionFixture());
    $number = (int) FinancialPeriod::withTrashed()->max('doc_number') + 1;
    $period = FinancialPeriod::query()->create(['company_id' => $f['company']->id, 'doc_number' => $number,
        'doc_num' => 'SYNTHETIC-RESTRICTED-COMPLETION-'.$number, 'name' => 'SYNTHETIC inaccessible completion period',
        'from_date' => '2026-11-01', 'to_date' => '2026-11-30', 'is_closed' => false]);
    $adjustment = InventoryValueAdjustment::query()->create(['company_id' => $f['company']->id,
        'financial_period_id' => $period->id, 'branch_id' => $f['branch']->id, 'source_type' => 'SYNTHETIC_SCOPE_ONLY',
        'source_id' => $f['receipt']->id, 'source_doc_num' => 'SYNTHETIC-SCOPE-ONLY', 'posting_date' => '2026-11-02', 'status' => 'posted',
        'source_snapshot' => ['synthetic_scope_denial' => true], 'approved_by' => $f['reviewer']->id, 'approved_at' => now()]);
    $adjustment->lines()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $period->id,
        'effect' => 'stock', 'source_transaction_id' => $f['receipt']->transactions()->sole()->id, 'amount' => '10',
        'account_id' => app(PostingAccountResolver::class)->inventoryForProduct($f['company']->id, $f['finished'], 'SYNTHETIC scope fixture')->id,
        'source_snapshot' => ['synthetic_scope_denial' => true]]);
    $roleNumber = (int) Role::withTrashed()->max('doc_number') + 1;
    $role = Role::query()->create(['name' => 'SYNTHETIC completion period restriction '.$roleNumber, 'guard_name' => 'web',
        'doc_number' => $roleNumber, 'doc_num' => 'SYNTHETIC-COMPLETION-PERIOD-ROLE-'.$roleNumber, 'financial_period_access_restricted' => true]);
    foreach ([$f['period']->id, $f['target']->id] as $id) {
        DB::table('role_financial_period_access')->insert(['role_id' => $role->id, 'financial_period_id' => $id]);
    }
    $f['reviewer']->assignRole($role);
    app(RequestMemo::class)->forget("operating_scope_access.role_scope.{$f['reviewer']->id}");
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.financial_periods.{$role->id}");
    manualCorrectionActor($f, $f['reviewer']);
    $this->get(route('admin.inventory.documents.show', $f['receipt']))->assertOk();
    expect(fn () => app(InventoryMovementCorrectionService::class)->preview($f['receipt']))->toThrow(HttpException::class);
    expect(fn () => app(InventoryMovementCorrectionService::class)->approve($f['receipt'], 1, 'SYNTHETIC denied period review'))
        ->toThrow(HttpException::class);
    expect($f['receipt']->fresh()->status)->toBe('posted')->and($f['receipt']->transactions()->where('is_reversal', true)->count())->toBe(0);
    DB::table('role_financial_period_access')->where('role_id', $role->id)->where('financial_period_id', $f['period']->id)->delete();
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.financial_periods.{$role->id}");
    $this->get(route('admin.inventory.documents.show', $f['receipt']))->assertNotFound();
});
