<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\RequestMemo;
use Modules\Inventory\Models\InventoryAllocationCostCompletion;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptCostBasis;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryAccountingPostingService;
use Modules\Inventory\Services\InventoryCostPolicyTransitionService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Production\Services\ProductionCostService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesReturnService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryValueAdjustmentSupport.php';

test('fully consumed historical receipt accepts an exact source workbook and requires independent verified approval', function (): void {
    Storage::set('local', Storage::fake('receipt-completion-'.getmypid()));
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '3');
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '3');
    $service = app(InventoryReceiptCostProposalService::class);
    $path = $service->template(request(), $receipt, true);
    $sheet = IOFactory::load($path);
    try {
        $sheet->getActiveSheet()->setCellValueExplicit('D2', '0.33333333', DataType::TYPE_STRING);
        (new Xlsx($sheet))->save($path);
        $proposal = $service->prepare(request(), $receipt, [
            'basis' => InventoryReceiptCostProposal::BasisDocumented, 'source_reference' => 'SYNTHETIC fully-consumed source workbook',
            'posting_date' => $day(3), 'counterpart_account_id' => $fixture['counterpart']->id,
        ], [], new UploadedFile($path, 'synthetic-cost-evidence.xlsx', null, null, true));
        expect($proposal->source_file_sha256)->toBe(hash_file('sha256', $path))
            ->and($proposal->line_snapshot[0]['unit_cost'])->toBe('0.33333333')
            ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0);
        $stored = Storage::disk('local');
        $original = $stored->get($proposal->source_file_path);
        $stored->put($proposal->source_file_path, 'SYNTHETIC changed evidence');
        test()->actingAs($fixture['approver']);
        request()->setUserResolver(fn (): User => $fixture['approver']);
        expect(fn () => $service->approve(request(), $receipt, $proposal, $proposal->source_reference, 'SYNTHETIC invalid proof'))->toThrow(DomainException::class)
            ->and($proposal->fresh()->status)->toBe(InventoryReceiptCostProposal::StatusPending);
        $stored->put($proposal->source_file_path, $original);
        $service->approve(request(), $receipt, $proposal, $proposal->source_reference, 'SYNTHETIC verified workbook approval');
        $adjustment = $proposal->fresh()->valueAdjustment;
        expect($adjustment->source_snapshot['source_total'])->toBe('0.99999999')
            ->and($adjustment->lines->where('effect', 'expense')->sole()->amount)->toBe('0.99999999')
            ->and($receipt->transactions->sole()->fresh()->total_cost)->toBeNull()
            ->and($issue->transactions->sole()->fresh()->total_cost)->toBeNull()
            ->and($issue->transactions->sole()->completedTotalCost())->toBe('0.99999999')
            ->and(app(InventoryReportService::class)->agingLayers($fixture['company']->id,
                ['branch_store_id' => $fixture['store']->id, 'as_of' => $day(3)]))->toBeEmpty();
        $totals = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)
            ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value')->first();
        expect(bccomp((string) $totals->quantity, '0', 8))->toBe(0)->and(bccomp((string) $totals->value, '0', 8))->toBe(0);
    } finally {
        $sheet->disconnectWorksheets();
        @unlink($path);
    }
});

test('independent receipt completion reprices consumed moving average stock without rewriting receipt quantities or historical values', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10');
    $known = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeReceipt, '10', '20');
    $issue = costTransitionMovement($fixture, $day(3), InventoryDocument::TypeIssue, '4');
    expect($issue->transactions->sole()->total_cost)->toBeNull();
    $beforeQuantity = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)->sum(DB::raw('quantity_in-quantity_out'));
    $proposal = receiptCompletionPrepare($fixture, $receipt, '10', $day(4));
    expect($proposal->impact_snapshot['source_total'])->toBe('100.00000000')
        ->and($proposal->impact_snapshot['source_period_closed'])->toBeFalse();
    expect(fn () => app(InventoryReceiptCostProposalService::class)->approve(request(), $receipt, $proposal,
        'SYNTHETIC costing evidence', 'SYNTHETIC unauthorized approval'))->toThrow(AuthorizationException::class);
    $fixture['preparer']->givePermissionTo('inventory.documents.approve_receipt_cost');
    expect(fn () => app(InventoryReceiptCostProposalService::class)->approve(request(), $receipt, $proposal,
        'SYNTHETIC costing evidence', 'SYNTHETIC self approval'))->toThrow(DomainException::class);
    receiptCompletionApprove($fixture, $receipt, $proposal);
    $adjustment = InventoryValueAdjustment::query()->where('source_id', $proposal->id)->sole();
    expect($proposal->fresh()->status)->toBe(InventoryReceiptCostProposal::StatusApproved)
        ->and($receipt->fresh()->lines->sole()->total_cost)->toBeNull()
        ->and($receipt->transactions->sole()->fresh()->total_cost)->toBeNull()
        ->and($issue->transactions->sole()->fresh()->total_cost)->toBeNull()
        ->and($known->transactions->sole()->fresh()->total_cost)->toBe('200.00000000');
    $totals = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)
        ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value, sum('.InventoryTransaction::unvaluedQuantitySql().') as unvalued')->first();
    expect(bccomp((string) $totals->quantity, (string) $beforeQuantity, 8))->toBe(0)
        ->and(bccomp((string) $totals->value, '240', 8))->toBe(0)->and(bccomp((string) $totals->unvalued, '0', 8))->toBe(0)
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id))->toBe('15.00000000');
    $journal = $adjustment->journalEntry->load('lines');
    expect($journal->lines->sum('debit_amount'))->toBe(100.0)->and($journal->lines->sum('credit_amount'))->toBe(100.0);
    expect($adjustment->lines->where('effect', 'expense')->sole()->amount)->toBe('60.00000000');
    expect(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id,
        asOfDate: $day(3)))->toBeNull();
    $reports = app(InventoryReportService::class);
    $agingSnapshot = fn (string $date): array => $reports->agingLayers($fixture['company']->id, ['branch_store_id' => $fixture['store']->id, 'as_of' => $date])
        ->map(fn ($layer): array => [$layer->id, $layer->remaining_quantity, $layer->remaining_value, $layer->valuation_complete])->all();
    $beforeCompletion = $agingSnapshot($day(3));
    expect($beforeCompletion)->toHaveCount(2)
        ->and($beforeCompletion[0][1])->toBe('6.00000000')->and($beforeCompletion[0][2])->toBeNull()
        ->and($beforeCompletion[1][1])->toBe('10.00000000')->and($beforeCompletion[1][2])->toBeNull();
    $completedAging = $agingSnapshot($day(4));
    expect($completedAging[0][2])->toBe('90.00000000')->and($completedAging[1][2])->toBe('150.00000000');
    $adjustmentMovements = $reports->movements($fixture['company']->id, ['branch_store_id' => $fixture['store']->id,
        'transaction_type' => InventoryTransaction::TypeValueAdjustment]);
    expect($adjustmentMovements)->toHaveCount(2)
        ->and($adjustmentMovements->first()->costCompletionDocument?->id)->toBe($receipt->id);
    $next = costTransitionMovement($fixture, $day(5), InventoryDocument::TypeIssue, '2');
    expect($next->transactions->sole()->total_cost)->toBe('30.00000000');
    expect($agingSnapshot($day(3)))->toBe($beforeCompletion)->and($agingSnapshot($day(4)))->toBe($completedAging);
    $postIssueAging = $agingSnapshot($day(5));
    expect($postIssueAging[0][1])->toBe('4.00000000')->and($postIssueAging[0][2])->toBe('60.00000000')
        ->and($postIssueAging[1][2])->toBe('150.00000000');
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '12', $day(6)));
    expect($agingSnapshot($day(5)))->toBe($postIssueAging)->and($agingSnapshot($day(4)))->toBe($completedAging);
    costTransitionMovement($fixture, $day(6), InventoryDocument::TypeIssue, '1');
    $sameDay = $agingSnapshot($day(6));
    expect($sameDay[0][1])->toBe('3.00000000')->and($sameDay[0][2])->toBe('48.00000000')
        ->and($sameDay[1][2])->toBe('160.00000000')->and($agingSnapshot($day(5)))->toBe($postIssueAging);
});

test('closed receipt period stays closed and its cost completion posts in the explicitly selected open period', function (): void {
    $fixture = receiptCompletionFixture();
    $sourceDay = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $sourceDay, InventoryDocument::TypeReceipt, '3');
    costTransitionMovement($fixture, $sourceDay, InventoryDocument::TypeIssue, '1');
    $fixture['period']->update(['is_closed' => true]);
    $current = FinancialPeriod::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 998001, 'doc_num' => 'SYNTHETIC-COST-CURRENT', 'name' => 'SYNTHETIC current correction period',
        'from_date' => $fixture['period']->to_date->copy()->addDay(), 'to_date' => $fixture['period']->to_date->copy()->addMonth(), 'is_closed' => false]);
    request()->session()->put([OperatingContextService::FinancialPeriodIdKey => $current->id,
        OperatingContextService::FinancialPeriodDocNumKey => $current->doc_num]);
    $proposal = receiptCompletionPrepare($fixture, $receipt, '0.33333333', $current->from_date->toDateString());
    receiptCompletionApprove($fixture, $receipt, $proposal);
    $adjustment = InventoryValueAdjustment::query()->where('source_id', $proposal->id)->sole();
    expect($fixture['period']->fresh()->is_closed)->toBeTrue()
        ->and($adjustment->financial_period_id)->toBe($current->id)->and($adjustment->source_snapshot['source_period_closed'])->toBeTrue()
        ->and($adjustment->source_snapshot['source_total'])->toBe('0.99999999')
        ->and($adjustment->journalEntry->financial_period_id)->toBe($current->id)
        ->and($adjustment->journalEntry->lines->sum('debit_amount'))->toBe(1.0)
        ->and($adjustment->journalEntry->lines->sum('credit_amount'))->toBe(1.0)
        ->and($receipt->transactions->sole()->fresh()->financial_period_id)->toBe($fixture['period']->id);
});

test('cost completion approval rejects a stale movement plan and an unrelated account without partial effects', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10');
    $proposal = receiptCompletionPrepare($fixture, $receipt, '7', $day(4));
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '1');
    $before = [InventoryTransaction::query()->count(), JournalEntry::query()->count()];
    expect(fn () => receiptCompletionApprove($fixture, $receipt, $proposal))->toThrow(DomainException::class);
    expect($proposal->fresh()->status)->toBe(InventoryReceiptCostProposal::StatusPending)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)
        ->and([InventoryTransaction::query()->count(), JournalEntry::query()->count()])->toBe($before);
});

test('successive independently approved target costs post incremental positive and negative differences after more consumption', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '4');
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '10', $day(3)));
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    costTransitionMovement($fixture, $day(4), InventoryDocument::TypeIssue, '2');
    $second = receiptCompletionPrepare($fixture, $receipt, '12', $day(5));
    expect($second->impact_snapshot['source_total'])->toBe('20.00000000');
    receiptCompletionApprove($fixture, $receipt, $second);
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $third = receiptCompletionPrepare($fixture, $receipt, '9', $day(6));
    expect($third->impact_snapshot['source_total'])->toBe('-30.00000000');
    receiptCompletionApprove($fixture, $receipt, $third);
    expect(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(3)
        ->and($receipt->transactions->sole()->completedTotalCost())->toBe('90.00000000')
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id))->toBe('9.00000000');
    $thirdJournal = InventoryValueAdjustment::query()->where('source_id', $third->id)->sole()->journalEntry;
    expect($thirdJournal->lines->sum('debit_amount'))->toBe(30.0)->and($thirdJournal->lines->sum('credit_amount'))->toBe(30.0);
});

test('reversing an originally unpriced issue after completion restores its effective cost and reverses the expense difference', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10');
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '4');
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '10', $day(3)));
    app(InventoryDocumentPostingService::class)->reverse($issue, 'SYNTHETIC correction-aware reversal');
    $reversal = $issue->transactions()->where('is_reversal', true)->sole();
    expect($reversal->total_cost)->toBe('40.00000000')->and($reversal->unit_cost)->toBe('10.00000000')
        ->and($reversal->transaction_date->toDateString())->toBe($day(3));
    $totals = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)
        ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value, sum('.InventoryTransaction::unvaluedQuantitySql().') as unvalued')->first();
    expect(bccomp((string) $totals->quantity, '10', 8))->toBe(0)->and(bccomp((string) $totals->value, '100', 8))->toBe(0)
        ->and(bccomp((string) $totals->unvalued, '0', 8))->toBe(0);
    $journal = JournalEntry::query()->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $issue->id)->sole();
    expect($journal->lines->sum('debit_amount'))->toBe(40.0)->and($journal->lines->sum('credit_amount'))->toBe(40.0);
});

test('completed null-cost layers transition to specific identification and consume the frozen basis through actual selection', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '2');
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '10', $day(3)));
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $transitions = app(InventoryCostPolicyTransitionService::class);
    $transition = $transitions->prepare($fixture['company']->id, ['branch_store_id' => $fixture['store']->id,
        'effective_from' => $day(4), 'target_method' => InventoryCostPolicy::SpecificIdentification,
        'reason' => 'SYNTHETIC completed inventory transition'], $fixture['preparer']->id);
    test()->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $transition = $transitions->activate($transitions->approve($transition, $fixture['approver']->id), $fixture['approver']->id);
    $layer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->transactions->sole()->id)->sole();
    expect($transition->total_book_value)->toBe('80.00000000')->and($layer->unit_cost)->toBeNull()
        ->and($layer->bookUnitCostForPolicy($transition->inventory_cost_policy_id))->toBe('10.00000000');
    $issue = costTransitionMovement($fixture, $day(5), InventoryDocument::TypeIssue, '3', null, ['selected_receipt_layer_id' => $layer->id]);
    expect($issue->transactions->sole()->total_cost)->toBe('30.00000000')
        ->and($transition->bases->sole()->fresh()->remaining_book_value)->toBe('50.00000000');
});

test('completion screen uses shared controls and authorized AJAX and rejects a colliding inventory counterpart', function (): void {
    $fixture = receiptCompletionFixture();
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeReceipt, '2');
    $this->withSession(costTransitionSession($fixture))->get(route('admin.inventory.cost-completions.show', $receipt))->assertOk()
        ->assertSee('receipt-cost-posting-date', false)->assertSee('receipt-cost-counterpart', false)->assertDontSee('type="date"', false);
    $this->getJson(route('admin.inventory.cost-completions.select2.accounts'))->assertOk()->assertJsonPath('pagination.more', false)
        ->assertJsonFragment(['id' => (string) $fixture['counterpart']->id]);
    $inventory = app(PostingAccountResolver::class)->inventoryForProduct($fixture['company']->id, $fixture['product'], 'SYNTHETIC invalid counterpart');
    expect(fn () => receiptCompletionPrepare([...$fixture, 'counterpart' => $inventory], $receipt, '7', $day))->toThrow(DomainException::class);
    expect(InventoryReceiptCostProposal::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0);
    $fixture['preparer']->revokePermissionTo('inventory.documents.propose_receipt_cost');
    $this->get(route('admin.inventory.cost-completions.show', $receipt))->assertForbidden();
});

test('cross branch completion and reversal require every affected branch and balance each branch independently', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $destinationBranch = Branch::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 998113, 'doc_num' => 'SYNTHETIC-COMPLETION-DEST', 'name' => 'SYNTHETIC completion destination',
        'type' => Branch::TypeWarehouse, 'status' => 'active']);
    $destination = BranchStore::query()->create(['branch_id' => $destinationBranch->id, 'name' => 'SYNTHETIC completion receiving store']);
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '2');
    $transfer = app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'branch_store_id' => $fixture['store']->id, 'destination_branch_store_id' => $destination->id,
        'document_date' => $day(2), 'document_type' => InventoryDocument::TypeTransfer],
        [['product_id' => $fixture['product']->id, 'quantity' => '4']]);
    $sourceJournal = $receipt->journalEntry;
    $receipt->forceFill(['journal_entry_id' => null])->save();
    foreach ([$receipt, $transfer] as $legacyDocument) {
        $legacyDocument->lines()->update(['unit_cost' => null, 'total_cost' => null]);
        $legacyDocument->transactions()->update(['unit_cost' => null, 'total_cost' => null]);
        InventoryReceiptLayer::query()->whereIn('receipt_transaction_id', $legacyDocument->transactions()->pluck('id'))->update(['unit_cost' => null, 'source_allocation_cost_snapshot' => null]);
    }
    InventoryLayerAllocation::query()->whereIn('issue_transaction_id', $transfer->transactions()->pluck('id'))->update(['cost_unit_snapshot' => null, 'cost_total_snapshot' => null]);
    DB::table('journal_entry_lines')->where('journal_entry_id', $sourceJournal->id)->delete();
    DB::table('journal_entries')->where('id', $sourceJournal->id)->delete();
    $receipt = $receipt->fresh();
    $proposal = receiptCompletionPrepare($fixture, $receipt, '10', $day(3));
    $role = Role::query()->create(['name' => 'SYNTHETIC completion restricted approver', 'guard_name' => 'web',
        'doc_number' => 998113, 'doc_num' => 'SYNTHETIC-COMPLETION-ROLE', 'company_access_restricted' => true,
        'branch_access_restricted' => true, 'financial_period_access_restricted' => true]);
    DB::table('role_company_access')->insert(['role_id' => $role->id, 'company_id' => $fixture['company']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('role_financial_period_access')->insert(['role_id' => $role->id, 'financial_period_id' => $fixture['period']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('role_branch_access')->insert(['role_id' => $role->id, 'branch_id' => $fixture['branch']->id, 'created_at' => now(), 'updated_at' => now()]);
    $fixture['approver']->assignRole($role);
    app(RequestMemo::class)->forget("operating_scope_access.role_scope.{$fixture['approver']->id}");
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.branches.{$role->id}");
    expect(fn () => receiptCompletionApprove($fixture, $receipt, $proposal))->toThrow(AuthorizationException::class)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0);
    DB::table('role_branch_access')->insert(['role_id' => $role->id, 'branch_id' => $destinationBranch->id, 'created_at' => now(), 'updated_at' => now()]);
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.branches.{$role->id}");
    receiptCompletionApprove($fixture, $receipt, $proposal);
    $journal = $proposal->valueAdjustment->journalEntry;
    expect($journal->branch_id)->toBeNull();
    foreach ($journal->lines->groupBy('branch_id') as $lines) {
        expect(bccomp((string) $lines->sum('debit_amount'), (string) $lines->sum('credit_amount'), 4))->toBe(0);
    }
    DB::table('role_branch_access')->where('role_id', $role->id)->where('branch_id', $destinationBranch->id)->delete();
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.branches.{$role->id}");
    $before = [InventoryTransaction::query()->count(), JournalEntry::query()->count()];
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($transfer,
        'SYNTHETIC unauthorized cross branch reversal'))->toThrow(AuthorizationException::class)
        ->and($transfer->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and([InventoryTransaction::query()->count(), JournalEntry::query()->count()])->toBe($before);
    DB::table('role_branch_access')->insert(['role_id' => $role->id, 'branch_id' => $destinationBranch->id, 'created_at' => now(), 'updated_at' => now()]);
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.branches.{$role->id}");
    app(InventoryDocumentPostingService::class)->reverse($transfer, 'SYNTHETIC authorized cross branch reversal');
    expect(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id))->toBe('10.00000000');
    $reversal = JournalEntry::query()->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $transfer->id)->sole();
    foreach ($reversal->lines->groupBy('branch_id') as $lines) {
        expect(bccomp((string) $lines->sum('debit_amount'), (string) $lines->sum('credit_amount'), 4))->toBe(0);
    }
    $beforeRepeat = JournalEntry::query()->count();
    app(InventoryAccountingPostingService::class)->reverse($transfer);
    expect(JournalEntry::query()->count())->toBe($beforeRepeat);
});

test('failed completion posting rolls back every adjustment and preserves immutable approved evidence', function (): void {
    $fixture = receiptCompletionFixture();
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeReceipt, '10');
    costTransitionMovement($fixture, $day, InventoryDocument::TypeIssue, '4');
    $proposal = receiptCompletionPrepare($fixture, $receipt, '10', $day);
    $this->partialMock(JournalEntryService::class, fn ($mock) => $mock->shouldReceive('createPostedFromSource')->once()->andThrow(new DomainException('SYNTHETIC injected accounting failure')));
    $before = [InventoryTransaction::query()->count(), JournalEntry::query()->count()];
    expect(fn () => receiptCompletionApprove($fixture, $receipt, $proposal))->toThrow(DomainException::class)
        ->and($proposal->fresh()->status)->toBe(InventoryReceiptCostProposal::StatusPending)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)
        ->and([InventoryTransaction::query()->count(), JournalEntry::query()->count()])->toBe($before);
});

test('approved cost completion evidence cannot be rewritten while physical consumption advances its remaining basis', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '4');
    $proposal = receiptCompletionPrepare($fixture, $receipt, '10', $day(3));
    receiptCompletionApprove($fixture, $receipt, $proposal);
    $adjustment = $proposal->valueAdjustment;
    $line = $adjustment->lines->first();
    $basis = InventoryReceiptCostBasis::query()->where('inventory_value_adjustment_id', $adjustment->id)->sole();
    $allocation = InventoryAllocationCostCompletion::query()->where('inventory_value_adjustment_id', $adjustment->id)->sole();
    expect(fn () => $adjustment->fresh()->update(['source_snapshot' => []]))->toThrow(DomainException::class)
        ->and(fn () => $line->fresh()->update(['amount' => '0']))->toThrow(DomainException::class)
        ->and(fn () => $basis->fresh()->update(['completed_total_cost' => '0']))->toThrow(DomainException::class)
        ->and(fn () => $allocation->fresh()->delete())->toThrow(DomainException::class)
        ->and(fn () => $adjustment->fresh()->delete())->toThrow(DomainException::class);
    costTransitionMovement($fixture, $day(4), InventoryDocument::TypeIssue, '2');
    expect($basis->fresh()->remaining_quantity)->toBe('4.00000000')
        ->and($basis->fresh()->remaining_value)->toBe('40.00000000')
        ->and($basis->fresh()->completed_total_cost)->toBe('100.00000000')
        ->and($allocation->fresh()->completed_total_cost)->toBe('40.00000000');
});

test('a later cost completion replays the frozen transition and posts only subsequent issue differences', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeReceipt, '10', '20');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeIssue, '4');
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '10', $day(4)));
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $service = app(InventoryCostPolicyTransitionService::class);
    $transition = $service->prepare($fixture['company']->id, ['branch_store_id' => $fixture['store']->id,
        'effective_from' => $day(5), 'target_method' => InventoryCostPolicy::Fifo,
        'reason' => 'SYNTHETIC frozen average to FIFO'], $fixture['preparer']->id);
    test()->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $transition = $service->activate($service->approve($transition, $fixture['approver']->id), $fixture['approver']->id);
    $fifoIssue = costTransitionMovement($fixture, $day(6), InventoryDocument::TypeIssue, '6');
    expect($fifoIssue->transactions->sole()->total_cost)->toBe('90.00000000');
    $frozen = $transition->fresh()->bases->map->only(['original_quantity', 'original_book_value', 'remaining_quantity', 'remaining_book_value'])->all();
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $revision = receiptCompletionPrepare($fixture, $receipt, '20', $day(7));
    expect($revision->impact_snapshot['source_total'])->toBe('100.00000000');
    receiptCompletionApprove($fixture, $receipt, $revision);
    expect($fifoIssue->transactions->sole()->fresh()->total_cost)->toBe('90.00000000')
        ->and($fifoIssue->transactions->sole()->completedTotalCost())->toBe('120.00000000')
        ->and($transition->fresh()->bases->map->only(['original_quantity', 'original_book_value', 'remaining_quantity', 'remaining_book_value'])->all())->toBe($frozen)
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id))->toBe('20.00000000');
    $historical = app(InventoryReportService::class)->agingLayers($fixture['company']->id,
        ['branch_store_id' => $fixture['store']->id, 'as_of' => $day(5)]);
    expect($historical)->toHaveCount(2)->and($historical->first()->remaining_quantity)->toBe('6.00000000')
        ->and($historical->first()->remaining_value)->toBe('90.00000000')
        ->and($historical->last()->remaining_value)->toBe('150.00000000');
    $issued = app(InventoryReportService::class)->agingLayers($fixture['company']->id,
        ['branch_store_id' => $fixture['store']->id, 'as_of' => $day(6)]);
    expect($issued)->toHaveCount(1)->and($issued->sole()->remaining_quantity)->toBe('10.00000000')
        ->and($issued->sole()->remaining_value)->toBe('150.00000000');
    $next = costTransitionMovement($fixture, $day(8), InventoryDocument::TypeIssue, '5');
    expect($next->transactions->sole()->total_cost)->toBe('100.00000000');
    $currentAging = app(InventoryReportService::class)->agingLayers($fixture['company']->id,
        ['branch_store_id' => $fixture['store']->id, 'as_of' => $day(8)]);
    expect($currentAging->sole()->remaining_quantity)->toBe('5.00000000')->and($currentAging->sole()->remaining_value)->toBe('100.00000000');
});

test('moving average transfer allocations retain the actual issue value after completion and reverse exactly', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $receipt = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeReceipt, '10', '20');
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '10', $day(3)));
    $destination = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC transfer destination']);
    $transfer = app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'branch_store_id' => $fixture['store']->id, 'destination_branch_store_id' => $destination->id,
        'document_type' => InventoryDocument::TypeTransfer, 'document_date' => $day(4),
    ], [['product_id' => $fixture['product']->id, 'quantity' => '4']]);
    $source = $transfer->transactions->where('quantity_out', '>', 0)->sole();
    $received = $transfer->transactions->where('quantity_in', '>', 0)->sole();
    $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $source->id)->sole();
    expect($source->total_cost)->toBe('60.00000000')->and($received->total_cost)->toBe('60.00000000')
        ->and($allocation->cost_total_snapshot)->toBe('60.00000000');
    app(InventoryDocumentPostingService::class)->reverse($transfer, 'SYNTHETIC average transfer restore');
    expect($transfer->transactions()->where('is_reversal', true)->pluck('total_cost')->all())->toBe(['60.00000000', '60.00000000'])
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id))->toBe('15.00000000');
});

test('a revised completion retains the final fractional value after return reversal and repost', function (): void {
    $fixture = receiptCompletionFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '1', '1');
    $receipt = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeReceipt, '3');
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '1', $day(3)));
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $service = app(InventoryCostPolicyTransitionService::class);
    $transition = $service->prepare($fixture['company']->id, ['branch_store_id' => $fixture['store']->id, 'effective_from' => $day(4),
        'target_method' => InventoryCostPolicy::SpecificIdentification, 'reason' => 'SYNTHETIC final remainder'], $fixture['preparer']->id);
    test()->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    $transition = $service->activate($service->approve($transition, $fixture['approver']->id), $fixture['approver']->id);
    $rootLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->transactions->sole()->id)->sole();
    $issue = costTransitionMovement($fixture, $day(5), InventoryDocument::TypeIssue, '3', null, ['selected_receipt_layer_id' => $rootLayer->id])->transactions->sole();
    $layers = app(InventoryLayerService::class);
    $returnSlice = function (int $offset, string $quantity) use ($fixture, $day, $issue, $layers): InventoryTransaction {
        $plan = $layers->planRestoration($issue, $quantity, limitToUnreturned: true);
        $cost = $layers->restorationCost($plan, $quantity);
        $transaction = costTransitionTransaction($fixture, ['transaction_date' => $day($offset),
            'transaction_type' => InventoryDocument::TypeSalesReturnReceipt, 'stock_status' => InventoryTransaction::StatusQuarantine,
            'quantity_in' => $quantity, 'unit_cost' => $cost['unit_cost'], 'total_cost' => $cost['total_cost'],
            'cost_method' => $issue->cost_method, 'cost_policy_id' => $issue->cost_policy_id]);
        $layers->recordInbound($transaction, $issue, restorationPlan: $plan);

        return $transaction;
    };
    $first = $returnSlice(6, '1');
    $reversal = costTransitionTransaction($fixture, ['transaction_date' => $day(7),
        'transaction_type' => InventoryDocument::TypeSalesReturnReceipt, 'stock_status' => InventoryTransaction::StatusQuarantine,
        'quantity_out' => '1', 'unit_cost' => $first->unit_cost, 'total_cost' => $first->total_cost,
        'is_reversal' => true, 'reversal_of_id' => $first->id, 'cost_method' => $issue->cost_method, 'cost_policy_id' => $issue->cost_policy_id]);
    $layers->allocateIssue($reversal, $first->id);
    $replacement = $returnSlice(8, '1');
    $last = $returnSlice(9, '2');
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '0.55555555', $day(10)));
    expect($replacement->completedTotalCost())->toBe('0.66666666')->and($last->completedTotalCost())->toBe('1.33333333')
        ->and(bcadd($replacement->completedTotalCost(), $last->completedTotalCost(), 8))->toBe('1.99999999');
});

test('legacy missing receipt cost reaches production waste finished goods and sales through canonical downstream differences', function (): void {
    $fixture = manufacturingInventoryFixture('-SYNTHETIC-COMPLETION');
    $fixture['branch'] = Branch::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 999812, 'doc_num' => 'SYNTHETIC-COST-FACTORY', 'name' => 'SYNTHETIC cost completion factory',
        'type' => Branch::TypeFactory, 'status' => 'active']);
    $fixture['store'] = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC source store']);
    $fixture['machine']->update(['branch_id' => $fixture['branch']->id]);
    $fixture['mold']->update(['branch_id' => $fixture['branch']->id]);
    request()->session()->put(manufacturingIntegritySession($fixture));
    $fixture['product'] = $fixture['raw'];
    $fixture['preparer'] = $fixture['user'];
    $initialAdjustments = InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count();
    $receipt = costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $details = manufacturingIntegrityRun($fixture, '10');
    $cycle = $details['cycle'];
    $run = $details['run'];
    $cycle->reserveRun($run, $fixture['store']->id);
    $cycle->issueMaterials($run, $fixture['store']->id);
    $run = $cycle->startRun($cycle->completeSetup($cycle->startSetup($run)));
    $cycle->recordProgress($run, ['good_base_quantity' => '10']);
    $requirement = $run->requirements()->sole();
    $cycle->accountMaterials($run->fresh(), $fixture['store']->id, [$requirement->id => ['consumed_quantity' => '19', 'waste_quantity' => '1']]);
    $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '10');
    $run = $cycle->completeRun($run->fresh());
    $customer = Customer::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 999812, 'doc_num' => 'SYNTHETIC-COMPLETION-CUSTOMER', 'name' => 'SYNTHETIC downstream customer', 'status' => 'active']);
    $order = SalesOrder::query()->create(['company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
        'doc_number' => 999812, 'doc_num' => 'SYNTHETIC-COMPLETION-SO', 'customer_id' => $customer->id,
        'currency_id' => Currency::query()->where('company_id', $fixture['company']->id)->where('is_main', true)->sole()->id,
        'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(),
        'status' => SalesOrder::StatusApproved, 'credit_status' => 'approved', 'total_amount' => '100']);
    $salesLine = $order->lines()->create(['line_number' => 1, 'product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id,
        'description' => $fixture['finished']->name,
        'quantity' => '10', 'base_quantity' => '10', 'conversion_factor' => '1', 'unit_price' => '10', 'line_total' => '100',
        'product_classification_snapshot' => Product::ClassificationFinishedProduct]);
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $salesLine->id, 'quantity' => '4']]);
    expect($delivery->transactions->sole()->total_cost)->toBe('15.20000000');
    $legacyJournal = $receipt->journalEntry;
    $receipt->forceFill(['journal_entry_id' => null])->save();
    $receipt->lines()->update(['unit_cost' => null, 'total_cost' => null]);
    $receipt->transactions()->update(['unit_cost' => null, 'total_cost' => null]);
    InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->transactions->sole()->id)->update(['unit_cost' => null]);
    DB::table('journal_entry_lines')->where('journal_entry_id', $legacyJournal->id)->delete();
    DB::table('journal_entries')->where('id', $legacyJournal->id)->delete();
    foreach (['inventory.documents.propose_receipt_cost', 'inventory.documents.approve_receipt_cost'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo('inventory.documents.propose_receipt_cost');
    $fixture['approver'] = closureSyntheticUser();
    $fixture['approver']->givePermissionTo('inventory.documents.approve_receipt_cost');
    $fixture['counterpart'] = app(PostingAccountResolver::class)->resolve($fixture['company']->id,
        PostingAccountResolver::InventoryAdjustmentGain, 'SYNTHETIC old source missing valuation');
    $receipt = $receipt->fresh();
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '3', now()->toDateString()));
    $cost = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($cost['issued'])->toBe('60.00000000')->and($cost['waste'])->toBe('3.00000000')
        ->and($cost['finished_goods'])->toBe('57.00000000')->and($cost['wip'])->toBe('0.00000000')
        ->and($cost['material_valuation_complete'])->toBeTrue()
        ->and($delivery->transactions->sole()->completedTotalCost())->toBe('22.80000000');
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile(
        $fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    foreach (['raw_materials', 'wip', 'finished_goods', 'production_waste'] as $key) {
        expect($reconciliation->firstWhere('key', $key)['difference'])->toBe('0.0000');
    }
    $returns = app(SalesReturnService::class);
    $return = $returns->authorize($returns->createFromDelivery($delivery, 'customer_rejection', 'SYNTHETIC approved source return',
        [['delivery_line_id' => $delivery->lines->sole()->id, 'quantity' => '1']]));
    $return = $returns->receive($return);
    expect($return->lines->sole()->original_unit_cost)->toBe('5.70000000')
        ->and($return->returnInventoryDocument->transactions->sole()->total_cost)->toBe('5.70000000')
        ->and($return->quarantineJournalEntry->lines->sum('debit_amount'))->toBe(5.7);
    $return = $returns->inspect($return, [['sales_return_line_id' => $return->lines->sole()->id,
        'saleable_quantity' => '0.5', 'rework_quantity' => '0.25', 'scrap_quantity' => '0.25']]);
    expect($return->lines->sole()->source_snapshot['disposition_costs'])->toBe([
        'saleable_base_quantity' => '2.85000000', 'rework_base_quantity' => '1.42500000', 'scrap_base_quantity' => '1.42500000']);
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    receiptCompletionApprove($fixture, $receipt, receiptCompletionPrepare($fixture, $receipt, '4', now()->toDateString()));
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile(
        $fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    foreach (['raw_materials', 'wip', 'finished_goods', 'quarantine', 'rework', 'production_waste'] as $key) {
        expect($reconciliation->firstWhere('key', $key)['difference'])->toBe('0.0000');
    }
    $damage = app(PostingAccountResolver::class)->resolve($fixture['company']->id,
        PostingAccountResolver::WarehouseDamageLoss, 'SYNTHETIC completed return scrap');
    $damageBalance = DB::table('journal_entry_lines')->where('account_id', $damage->id)->where('branch_id', $fixture['branch']->id)
        ->sum(DB::raw('debit_amount-credit_amount'));
    expect(bccomp((string) $damageBalance, '1.9', 4))->toBe(0);
    $return = $returns->correctInspected($return->fresh(), 'SYNTHETIC approved completed return correction');
    expect($return->status)->toBe(SalesReturn::StatusCancelled)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count() - $initialAdjustments)->toBe(2);
    $beforeRepeat = JournalEntry::query()->count();
    expect(fn () => $returns->correctInspected($return, 'SYNTHETIC repeat correction'))->toThrow(DomainException::class)
        ->and(JournalEntry::query()->count())->toBe($beforeRepeat);
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile(
        $fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    foreach (['raw_materials', 'wip', 'finished_goods', 'quarantine', 'rework', 'production_waste'] as $key) {
        expect($reconciliation->firstWhere('key', $key)['difference'])->toBe('0.0000');
    }
});
