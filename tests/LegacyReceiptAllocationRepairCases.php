<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Modules\Inventory\Services\LegacyReceiptAllocationRepairService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesFulfillmentService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryMovementCorrectionSupport.php';

function legacyAllocationFixture(bool $sales = false): array
{
    $f = manualCorrectionFixture();
    $f['legacy'] = manualCorrectionMovement($f, InventoryDocument::TypeReceipt, '5');
    $f['original'] = $f['legacy']->transactions()->where('is_reversal', false)->sole();
    app(InventoryDocumentPostingService::class)->reverse($f['legacy']);
    $f['legacy']->refresh();
    $f['reversal'] = InventoryTransaction::query()->where('reversal_of_id', $f['original']->id)->sole();
    if ($sales) {
        $account = Account::query()->where('company_id', $f['company']->id)->where('is_postable', true)
            ->whereHas('classification', fn ($q) => $q->where('code', 'accounts_receivable'))->firstOrFail();
        $customer = Customer::query()->create(['company_id' => $f['company']->id, 'account_id' => $account->id,
            'doc_number' => 99121, 'doc_num' => 'SYNTHETIC-LEGACY-EXCHANGE-CUSTOMER', 'name' => 'SYNTHETIC allocation exchange customer']);
        $f['salesOrder'] = SalesOrder::query()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
            'branch_id' => $f['branch']->id, 'branch_store_id' => $f['store']->id, 'doc_number' => 99121,
            'doc_num' => 'SYNTHETIC-LEGACY-EXCHANGE-SO', 'customer_id' => $customer->id,
            'currency_id' => Currency::query()->where('company_id', $f['company']->id)->firstOrFail()->id,
            'order_date' => '2026-09-28', 'expected_delivery_date' => '2026-09-29', 'status' => SalesOrder::StatusApproved, 'credit_status' => 'approved', 'subtotal_amount' => '60', 'total_amount' => '60']);
        $f['salesLine'] = $f['salesOrder']->lines()->create(['line_number' => 1, 'product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id,
            'description' => $f['finished']->name, 'quantity' => '6', 'unit_price' => '10', 'line_total' => '60', 'conversion_factor' => '1',
            'base_quantity' => '6', 'product_classification_snapshot' => $f['finished']->item_classification]);
        $f['issue'] = app(SalesFulfillmentService::class)->deliver($f['salesOrder'], [['sales_order_line_id' => $f['salesLine']->id, 'quantity' => '6']], ['document_date' => '2026-09-28']);
        $invoices = app(CustomerInvoiceService::class);
        $f['invoice'] = $invoices->post($invoices->createFromOrder($f['salesOrder']->fresh(),
            [['sales_order_line_id' => $f['salesLine']->id, 'delivery_line_id' => $f['issue']->lines->sole()->id, 'quantity' => '6']],
            [['due_date' => '2026-09-28', 'amount' => '60']], invoiceDate: '2026-09-28'));
        $f['invoice']->deliveries()->detach();
    } else {
        $f['issue'] = manualCorrectionMovement($f, InventoryDocument::TypeIssue, '6');
    }
    $f['issueTransaction'] = $f['issue']->transactions()->where('is_reversal', false)->sole();
    $f['own'] = InventoryReceiptLayer::query()->where('receipt_transaction_id', $f['original']->id)->sole();
    $f['older'] = InventoryReceiptLayer::query()->where('receipt_transaction_id', $f['receipt']->transactions()->where('is_reversal', false)->sole()->id)->sole();
    InventoryLayerAllocation::query()->where('issue_transaction_id', $f['reversal']->id)->delete();
    $f['foreign'] = InventoryLayerAllocation::query()->create(['inventory_receipt_layer_id' => $f['older']->id, 'issue_transaction_id' => $f['reversal']->id, 'quantity' => '5']);
    $f['existing'] = InventoryLayerAllocation::query()->where('issue_transaction_id', $f['issueTransaction']->id)->sole();
    $f['existing']->update(['quantity' => '2', 'cost_unit_snapshot' => '2', 'cost_total_snapshot' => '4']);
    $f['misplacedIssue'] = InventoryLayerAllocation::query()->create(['inventory_receipt_layer_id' => $f['own']->id, 'issue_transaction_id' => $f['issueTransaction']->id, 'quantity' => '4', 'cost_unit_snapshot' => '2', 'cost_total_snapshot' => '8']);
    $f['own']->update(['remaining_quantity' => '1']);
    $f['older']->update(['remaining_quantity' => '3']);
    manualCorrectionActor($f, $f['user']);

    return $f;
}

function legacyAllocationPayload(array $f): array
{
    return ['operation' => 'repair_lineage', 'posting_date' => isset($f['target']) ? '2026-10-02' : '2026-09-28',
        'source_fingerprint' => app(InventoryMovementCorrectionService::class)->preview($f['legacy'])['source_fingerprint'],
        'reason' => 'SYNTHETIC exact historical layer exchange with original issue cost'];
}

test('historical allocation exchange preserves posted amounts and reconciles the original layers through independent HTTP approval', function (): void {
    $f = legacyAllocationFixture();
    $before = app(LegacyReceiptAllocationRepairService::class)->plan($f['legacy'])['financial_proof'];
    $this->get(route('admin.inventory.documents.corrections.index', $f['legacy']))->assertOk()->assertSee('repair_lineage', false);
    $proposalId = $this->postJson(route('admin.inventory.documents.corrections.store', $f['legacy']), legacyAllocationPayload($f))
        ->assertOk()->json('data.proposal_id');
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['legacy'], $proposalId]), ['approval_reason' => 'SYNTHETIC same actor denied'])->assertUnprocessable();
    manualCorrectionActor($f, $f['reviewer']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['legacy'], $proposalId]), ['approval_reason' => 'SYNTHETIC independent source verification'])->assertOk()->assertJsonPath('data.status', 'approved');
    $proposal = InventoryMovementCorrection::query()->findOrFail($proposalId);
    expect($proposal->execution_snapshot['financial_proof'])->toBe($before)
        ->and($proposal->execution_snapshot['misplaced_quantity'])->toBe('5.00000000')
        ->and($proposal->execution_snapshot['exchanged_quantity'])->toBe('4.00000000')
        ->and($proposal->execution_snapshot['known_value_change'])->toBe('2.00000000')
        ->and($f['own']->fresh()->remaining_quantity)->toBe('0.00000000')
        ->and($f['older']->fresh()->remaining_quantity)->toBe('4.00000000')
        ->and($f['issueTransaction']->fresh()->total_cost)->toBe('12.00000000')
        ->and($f['existing']->fresh()->quantity)->toBe('6.00000000')
        ->and($f['existing']->fresh()->cost_total_snapshot)->toBe('12.00000000')
        ->and(InventoryLayerAllocation::query()->whereKey($f['misplacedIssue']->id)->exists())->toBeFalse()
        ->and(InventoryLayerAllocation::query()->where('issue_transaction_id', $f['reversal']->id)->sole()->inventory_receipt_layer_id)->toBe($f['own']->id);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['legacy'], $proposalId]), ['approval_reason' => 'SYNTHETIC harmless repeat approval'])->assertOk();
    expect(InventoryMovementCorrection::query()->where('inventory_document_id', $f['legacy']->id)->count())->toBe(1)
        ->and(InventoryTransaction::query()->where('product_id', $f['finished']->id)->sum(DB::raw('quantity_in - quantity_out')))->toEqual(4);
    $this->get(route('admin.inventory.documents.corrections.index', $f['legacy']))->assertOk();
});

test('historical exchange rejects changed source prices and stale or unauthorized approval before allocation changes', function (): void {
    $f = legacyAllocationFixture();
    $service = app(InventoryMovementCorrectionService::class);
    $proposal = $service->prepare($f['legacy'], legacyAllocationPayload($f));
    manualCorrectionActor($f, $f['reviewer']);
    $f['issueTransaction']->update(['unit_cost' => '3', 'total_cost' => '18']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['legacy'], $proposal->id]), ['approval_reason' => 'SYNTHETIC inconsistent cost denied'])->assertUnprocessable();
    expect($f['own']->fresh()->remaining_quantity)->toBe('1.00000000')->and($proposal->fresh()->status)->toBe('prepared');
    $f['issueTransaction']->update(['unit_cost' => '2', 'total_cost' => '12']);
    $f['reviewer']->revokePermissionTo('inventory.documents.correct_approve');
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['legacy'], $proposal->id]), ['approval_reason' => 'SYNTHETIC revoked approval denied'])->assertForbidden();
    expect($f['older']->fresh()->remaining_quantity)->toBe('3.00000000');
});

test('historical exchange proves current allocations while permitting a later legitimate issue from restored stock', function (): void {
    $f = legacyAllocationFixture();
    $service = app(InventoryMovementCorrectionService::class);
    $proposal = $service->prepare($f['legacy'], legacyAllocationPayload($f));
    manualCorrectionActor($f, $f['reviewer']);
    $proposal = $service->approve($f['legacy'], $proposal->id, 'SYNTHETIC approve exact source exchange');
    manualCorrectionMovement($f, InventoryDocument::TypeIssue, '1');
    $service->assertApproved($proposal);
    expect($f['older']->fresh()->remaining_quantity)->toBe('3.00000000');
    DB::table('inventory_layer_allocations')->where('id', $f['existing']->id)->update(['quantity' => '5']);
    expect(fn () => $service->assertApproved($proposal))->toThrow(DomainException::class);
});

test('historical sales allocation repair retains line-only invoice links and the original balanced journals and prices', function (): void {
    $f = legacyAllocationFixture(sales: true);
    $service = app(InventoryMovementCorrectionService::class);
    $before = $service->preview($f['legacy'])['legacy_repair']['financial_proof'];
    expect($f['invoice']->delivery_document_id)->toBeNull()->and($f['invoice']->deliveries()->exists())->toBeFalse()
        ->and($before['invoices'][0]['id'])->toBe($f['invoice']->id)
        ->and(bcadd((string) $before['invoices'][0]['subtotal_amount'], '0', 4))->toBe('60.0000')
        ->and(bcadd((string) $before['invoice_lines'][0]['unit_price'], '0', 8))->toBe('10.00000000');
    foreach (['customer_invoices.view', 'customer_invoices.view_prices', 'journal_entries.view'] as $permission) {
        $f['user']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->get(route('admin.inventory.documents.corrections.index', $f['legacy']))->assertOk()
        ->assertSee($f['invoice']->doc_num)->assertSee(route('admin.sales.sales-invoices.show', $f['invoice']->doc_num), false)
        ->assertSee($f['issue']->journalEntry->doc_num);
    $f['user']->revokePermissionTo('customer_invoices.view_prices');
    $this->get(route('admin.inventory.documents.corrections.index', $f['legacy']))->assertOk()
        ->assertDontSee('<th>'.__('Total').'</th>', false);
    $proposal = $service->prepare($f['legacy'], legacyAllocationPayload($f));
    $this->get(route('admin.inventory.documents.corrections.index', $f['legacy']))->assertOk();
    expect($service->prepare($f['legacy'], legacyAllocationPayload($f))->id)->toBe($proposal->id);
    manualCorrectionActor($f, $f['reviewer']);
    $approved = $service->approve($f['legacy'], $proposal->id, 'SYNTHETIC retain original invoice and GL');
    expect($approved->execution_snapshot['financial_proof'])->toBe($before)
        ->and($f['invoice']->fresh()->total_amount)->toBe('60.0000')
        ->and($f['salesOrder']->fresh()->lines->sole()->delivered_quantity)->toBe('6.00000000');
    foreach ($before['journals'] as $journal) {
        $debit = array_reduce($journal['lines'], fn ($sum, $line) => bcadd($sum, $line['debit_amount'], 4), '0');
        $credit = array_reduce($journal['lines'], fn ($sum, $line) => bcadd($sum, $line['credit_amount'], 4), '0');
        expect(bccomp($debit, $credit, 4))->toBe(0);
    }
    DB::table('customer_invoice_lines')->where('customer_invoice_id', $f['invoice']->id)->update(['unit_price' => '11']);
    expect(fn () => $service->assertApproved($approved))->toThrow(DomainException::class);
});

test('historical repair protects a destination allocation sealed by another correction and a previously approved repair of the same source', function (): void {
    $f = legacyAllocationFixture();
    $service = app(InventoryMovementCorrectionService::class);
    $other = InventoryMovementCorrection::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'inventory_document_id' => $f['receipt']->id, 'source_financial_period_id' => $f['period']->id,
        'posting_financial_period_id' => $f['period']->id, 'posting_date' => '2026-09-28', 'operation' => 'replace',
        'reason' => 'SYNTHETIC existing destination allocation evidence', 'prepared_by' => $f['reviewer']->id, 'status' => 'prepared',
        'source_snapshot' => ['allocations' => [$f['existing']->getAttributes()]], 'replacement_payload' => [],
        'source_fingerprint' => str_repeat('a', 64), 'proposal_fingerprint' => str_repeat('b', 64)]);
    expect(fn () => $service->preview($f['legacy']))->toThrow(DomainException::class);
    $other->update(['status' => 'rejected']);
    $proposal = $service->prepare($f['legacy'], legacyAllocationPayload($f));
    manualCorrectionActor($f, $f['reviewer']);
    $service->approve($f['legacy'], $proposal->id, 'SYNTHETIC independent allocation verification');
    InventoryLayerAllocation::query()->where('issue_transaction_id', $f['reversal']->id)->delete();
    InventoryLayerAllocation::query()->create(['inventory_receipt_layer_id' => $f['older']->id, 'issue_transaction_id' => $f['reversal']->id, 'quantity' => '5']);
    $f['existing']->refresh()->update(['quantity' => '2', 'cost_total_snapshot' => '4']);
    InventoryLayerAllocation::query()->create(['inventory_receipt_layer_id' => $f['own']->id, 'issue_transaction_id' => $f['issueTransaction']->id,
        'quantity' => '4', 'cost_unit_snapshot' => '2', 'cost_total_snapshot' => '8']);
    $f['own']->update(['remaining_quantity' => '1']);
    $f['older']->update(['remaining_quantity' => '3']);
    expect(fn () => $service->preview($f['legacy']))->toThrow(DomainException::class);
});

test('historical exchange rolls back every allocation and approval when execution fails after the first foreign restoration', function (): void {
    $f = legacyAllocationFixture();
    $service = app(InventoryMovementCorrectionService::class);
    $proposal = $service->prepare($f['legacy'], legacyAllocationPayload($f));
    manualCorrectionActor($f, $f['reviewer']);
    $event = 'eloquent.deleted: '.InventoryLayerAllocation::class;
    Event::listen($event, fn () => throw new RuntimeException('SYNTHETIC fail after first restored foreign allocation'));
    try {
        expect(fn () => $service->approve($f['legacy'], $proposal->id, 'SYNTHETIC rollback verification'))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect($proposal->fresh()->status)->toBe('prepared')->and($proposal->fresh()->approved_by)->toBeNull()
        ->and($f['older']->fresh()->remaining_quantity)->toBe('3.00000000')
        ->and($f['own']->fresh()->remaining_quantity)->toBe('1.00000000')
        ->and(InventoryLayerAllocation::query()->whereKey($f['foreign']->id)->exists())->toBeTrue()
        ->and($f['existing']->fresh()->quantity)->toBe('2.00000000');
    $service->approve($f['legacy'], $proposal->id, 'SYNTHETIC independent retry after rollback');
    expect($proposal->fresh()->status)->toBe('approved');
});

test('historical repair rejects recreated corruption with entirely new allocation ids after an approved source repair', function (): void {
    $f = legacyAllocationFixture();
    $service = app(InventoryMovementCorrectionService::class);
    $proposal = $service->prepare($f['legacy'], legacyAllocationPayload($f));
    manualCorrectionActor($f, $f['reviewer']);
    $service->approve($f['legacy'], $proposal->id, 'SYNTHETIC first source repair');
    manualCorrectionMovement($f, InventoryDocument::TypeIssue, '1');
    $service->assertApproved($proposal->fresh());
    InventoryLayerAllocation::query()->whereIn('issue_transaction_id', [$f['reversal']->id, $f['issueTransaction']->id])->delete();
    InventoryLayerAllocation::query()->create(['inventory_receipt_layer_id' => $f['older']->id, 'issue_transaction_id' => $f['reversal']->id, 'quantity' => '5']);
    InventoryLayerAllocation::query()->create(['inventory_receipt_layer_id' => $f['older']->id, 'issue_transaction_id' => $f['issueTransaction']->id,
        'quantity' => '2', 'cost_unit_snapshot' => '2', 'cost_total_snapshot' => '4']);
    InventoryLayerAllocation::query()->create(['inventory_receipt_layer_id' => $f['own']->id, 'issue_transaction_id' => $f['issueTransaction']->id,
        'quantity' => '4', 'cost_unit_snapshot' => '2', 'cost_total_snapshot' => '8']);
    $f['own']->update(['remaining_quantity' => '1']);
    $f['older']->update(['remaining_quantity' => '2']);
    expect(fn () => $service->preview($f['legacy']))->toThrow(DomainException::class);
    DB::table('inventory_movement_corrections')->where('id', $proposal->id)->update(['inventory_document_id' => $f['receipt']->id, 'status' => 'rejected']);
    expect(fn () => $service->preview($f['legacy']))->toThrow(DomainException::class);
});
