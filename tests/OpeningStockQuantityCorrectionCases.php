<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransitionBasis;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventorySerialIdentity;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockQuantityCorrection;
use Modules\Inventory\Services\InventoryCostPolicyTransitionService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryOpeningStockPostingService;
use Modules\Inventory\Services\InventorySerialService;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Inventory\Services\OpeningStockQuantityCorrectionService;

require_once __DIR__.'/OpeningStockQuantityCorrectionSupport.php';

test('independent approval increases opening quantity through a linked posted document without rewriting the source', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $sourceBefore = $fixture['opening']->fresh()->only(['financial_period_id', 'status', 'approved']);
    $sourceUpdatedAt = $fixture['opening']->updated_at->toISOString();
    $lineBefore = $fixture['line']->fresh()->only(['quantity']);
    $lineUpdatedAt = $fixture['line']->updated_at->toISOString();
    $root = InventoryTransaction::query()->where('source_type', OpeningStock::class)
        ->where('source_id', $fixture['opening']->id)->sole();
    $rootBefore = $root->only(['quantity_in', 'quantity_out', 'unit_cost', 'total_cost']);
    $rootUpdatedAt = $root->updated_at->toISOString();

    $correction = prepareOpeningQuantityCorrection($fixture, '12', ($fixture['day'])(3), '7.25000000');
    expect($correction->status)->toBe(OpeningStockQuantityCorrection::StatusPending)
        ->and($correction->plan['lines'][0]['delta_quantity'])->toBe('2.0000')
        ->and($correction->source_snapshot['source_period_closed'])->toBeFalse()
        ->and($correction->source_snapshot['lines'][0]['effective_unit_cost'])->toBe('5.00000000');
    $fixture['preparer']->givePermissionTo('inventory.opening_stock_quantity_corrections.approve');
    expect(fn () => app(OpeningStockQuantityCorrectionService::class)->approve(
        request(), $fixture['opening'], $correction, 'SYNTHETIC self approval',
    ))->toThrow(DomainException::class);

    actAsOpeningQuantityApprover($fixture);
    $approved = app(OpeningStockQuantityCorrectionService::class)->approve(
        request(), $fixture['opening'], $correction, 'SYNTHETIC approval REF-001',
    );
    $document = InventoryDocument::query()->with('lines', 'transactions', 'journalEntry.lines')
        ->where('source_document_type', OpeningStockQuantityCorrection::class)
        ->where('source_document_id', $approved->id)->sole();
    expect($document->document_type)->toBe(InventoryDocument::TypeAdjustmentIn)
        ->and($document->financial_period_id)->toBe($fixture['period']->id)
        ->and($document->lines->sole()->source_line_type)->toBe(OpeningStockLine::class)
        ->and($document->lines->sole()->unit_cost)->toBe('7.25000000')
        ->and($document->transactions->sole()->quantity_in)->toBe('2.00000000')
        ->and($document->journalEntry->lines->sum('debit_amount'))->toBe(14.5)
        ->and($document->journalEntry->lines->sum('credit_amount'))->toBe(14.5)
        ->and($fixture['opening']->fresh()->only(['financial_period_id', 'status', 'approved']))->toBe($sourceBefore)
        ->and($fixture['opening']->updated_at->toISOString())->toBe($sourceUpdatedAt)
        ->and($fixture['line']->fresh()->only(['quantity']))->toBe($lineBefore)
        ->and($fixture['line']->updated_at->toISOString())->toBe($lineUpdatedAt)
        ->and($root->fresh()->only(['quantity_in', 'quantity_out', 'unit_cost', 'total_cost']))->toBe($rootBefore)
        ->and($root->updated_at->toISOString())->toBe($rootUpdatedAt);

    $again = app(OpeningStockQuantityCorrectionService::class)->approve(
        request(), $fixture['opening'], $approved, 'SYNTHETIC replay',
    );
    expect($again->document_links)->toBe($approved->document_links)
        ->and(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)
            ->where('source_document_id', $approved->id)->count())->toBe(1);
});

test('decrease after partial consumption uses canonical outbound layers and blocks an unavailable correction in preview', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeIssue, '4');
    $correction = prepareOpeningQuantityCorrection($fixture, '8', ($fixture['day'])(3));
    expect($correction->plan['lines'][0]['unit_cost'])->toBe('5.00000000')
        ->and($correction->plan['lines'][0]['value'])->toBe('10.00000000')
        ->and($correction->plan['lines'][0]['value_basis'])->toBe('canonical_position_book_unit_cost');
    actAsOpeningQuantityApprover($fixture);
    $approved = app(OpeningStockQuantityCorrectionService::class)->approve(
        request(), $fixture['opening'], $correction, 'SYNTHETIC decrease approval',
    );
    $document = InventoryDocument::query()->with('transactions')->where('source_document_type', OpeningStockQuantityCorrection::class)
        ->where('source_document_id', $approved->id)->sole();
    $issue = $document->transactions->sole();
    expect($document->document_type)->toBe(InventoryDocument::TypeAdjustmentOut)
        ->and($issue->quantity_out)->toBe('2.00000000')
        ->and($issue->unit_cost)->toBe('5.00000000')
        ->and(bcadd((string) InventoryLayerAllocation::query()->where('issue_transaction_id', $issue->id)->sum('quantity'), '0', 8))->toBe('2.00000000')
        ->and($fixture['line']->fresh()->quantity)->toBe('10.0000');

    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    costTransitionMovement($fixture, ($fixture['day'])(4), InventoryDocument::TypeIssue, '3');
    $preview = app(OpeningStockQuantityCorrectionService::class)->preview(request(), $fixture['opening'], [
        'posting_date' => ($fixture['day'])(5),
        'reason' => 'SYNTHETIC unavailable decrease',
        'source_reference' => 'SYNTHETIC unavailable evidence',
    ], [$fixture['line']->id => ['target_quantity' => '0']]);
    expect($preview['can_prepare'])->toBeFalse()
        ->and($preview['blockers'])->not->toBeEmpty()
        ->and($preview['lines'][0]['dependency_ids']['root_transaction_id'])->toBeInt();
    $documentCount = InventoryDocument::query()->count();
    expect(fn () => prepareOpeningQuantityCorrection($fixture, '0', ($fixture['day'])(5)))
        ->toThrow(DomainException::class)
        ->and(InventoryDocument::query()->count())->toBe($documentCount);
});

test('permissions scope rejection and stale position prevent posting', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $fixture['preparer']->revokePermissionTo('inventory.opening_stock_quantity_corrections.prepare');
    expect(fn () => prepareOpeningQuantityCorrection($fixture, '11', ($fixture['day'])(3), '5'))
        ->toThrow(AuthorizationException::class);
    $fixture['preparer']->givePermissionTo('inventory.opening_stock_quantity_corrections.prepare');
    $correction = prepareOpeningQuantityCorrection($fixture, '11', ($fixture['day'])(3), '5');
    costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeIssue, '1');
    actAsOpeningQuantityApprover($fixture);
    expect(fn () => app(OpeningStockQuantityCorrectionService::class)->approve(
        request(), $fixture['opening'], $correction, 'SYNTHETIC stale approval',
    ))->toThrow(DomainException::class);
    expect(InventoryDocument::query()->where('company_id', $fixture['company']->id)->where('source_document_type', OpeningStockQuantityCorrection::class)->count())->toBe(0)
        ->and($correction->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusPending);
    expect(app(OpeningStockQuantityCorrectionService::class)->reject(
        request(), $fixture['opening'], $correction, 'SYNTHETIC rejected stale evidence',
    )->status)->toBe(OpeningStockQuantityCorrection::StatusRejected);

    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    request()->session()->put(OperatingContextService::BranchIdKey, 0);
    expect(fn () => prepareOpeningQuantityCorrection($fixture, '11', ($fixture['day'])(4), '5'))
        ->toThrow(DomainException::class);
});

test('closed source posts to the current open period while a closed posting period is rejected', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $sourcePeriod = $fixture['period'];
    $sourcePeriod->update(['is_closed' => true]);
    $postingPeriod = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->id,
        'doc_number' => 998799,
        'doc_num' => 'SYNTHETIC-OPENING-QTY-POSTING',
        'name' => 'SYNTHETIC opening quantity posting period',
        'from_date' => $sourcePeriod->to_date->copy()->addDay(),
        'to_date' => $sourcePeriod->to_date->copy()->addMonth(),
        'is_closed' => false,
    ]);
    request()->session()->put([
        OperatingContextService::FinancialPeriodIdKey => $postingPeriod->id,
        OperatingContextService::FinancialPeriodDocNumKey => $postingPeriod->doc_num,
    ]);
    $correction = prepareOpeningQuantityCorrection($fixture, '11', $postingPeriod->from_date->toDateString(), '5');
    expect($correction->source_snapshot['source_period_closed'])->toBeTrue();
    actAsOpeningQuantityApprover($fixture);
    $approved = app(OpeningStockQuantityCorrectionService::class)->approve(
        request(), $fixture['opening'], $correction, 'SYNTHETIC later-period approval',
    );
    $document = InventoryDocument::query()->with('journalEntry')->findOrFail($approved->document_links[0]['id']);
    expect($document->financial_period_id)->toBe($postingPeriod->id)
        ->and($document->journalEntry->financial_period_id)->toBe($postingPeriod->id)
        ->and($fixture['opening']->fresh()->financial_period_id)->toBe($sourcePeriod->id)
        ->and($sourcePeriod->fresh()->is_closed)->toBeTrue();

    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $postingPeriod->update(['is_closed' => true]);
    expect(fn () => prepareOpeningQuantityCorrection($fixture, '12', $postingPeriod->from_date->copy()->addDay()->toDateString(), '5'))
        ->toThrow(DomainException::class);
});

test('mixed increase and decrease documents roll back atomically when the second canonical post fails', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    addSecondOpeningQuantityLine($fixture);
    $service = app(OpeningStockQuantityCorrectionService::class);
    $correction = $service->prepare(request(), $fixture['opening'], [
        'posting_date' => ($fixture['day'])(3),
        'reason' => 'SYNTHETIC mixed quantity correction',
        'source_reference' => 'SYNTHETIC mixed evidence',
    ], [
        $fixture['line']->id => ['target_quantity' => '12', 'unit_cost' => '6'],
        $fixture['second_line']->id => ['target_quantity' => '4'],
    ]);
    $journalCountBefore = JournalEntry::query()->count();
    $realMovements = app(InventoryMovementService::class);
    $calls = 0;
    $mock = Mockery::mock(InventoryMovementService::class);
    $mock->shouldReceive('createAndPost')->twice()->andReturnUsing(
        function (array $header, array $lines) use (&$calls, $realMovements): InventoryDocument {
            $calls++;
            if ($calls === 2) {
                throw new DomainException('SYNTHETIC second posting failure');
            }

            return $realMovements->createAndPost($header, $lines);
        },
    );
    app()->instance(InventoryMovementService::class, $mock);
    app()->forgetInstance(OpeningStockQuantityCorrectionService::class);
    actAsOpeningQuantityApprover($fixture);
    expect(fn () => app(OpeningStockQuantityCorrectionService::class)->approve(
        request(), $fixture['opening'], $correction, 'SYNTHETIC mixed approval',
    ))->toThrow(DomainException::class, 'SYNTHETIC second posting failure');
    expect($correction->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusPending)
        ->and(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)
            ->where('source_document_id', $correction->id)->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe($journalCountBefore);
});

test('specific identification decrease freezes and consumes the selected eligible layer and rejects wrong or insufficient selections', function (): void {
    $activateSpecificPolicy = function (array $fixture): void {
        $service = app(InventoryCostPolicyTransitionService::class);
        $transition = $service->prepare($fixture['company']->id, [
            'branch_store_id' => $fixture['store']->id,
            'effective_from' => ($fixture['day'])(2),
            'target_method' => InventoryCostPolicy::SpecificIdentification,
            'reason' => 'SYNTHETIC dated selected opening correction',
        ], $fixture['preparer']->id);
        test()->actingAs($fixture['approver']);
        request()->setUserResolver(fn (): User => $fixture['approver']);
        $service->activate($service->approve($transition, $fixture['approver']->id), $fixture['approver']->id);
        test()->actingAs($fixture['preparer']);
        request()->setUserResolver(fn (): User => $fixture['preparer']);
    };
    $fixture = openingQuantityCorrectionFixture();
    $activateSpecificPolicy($fixture);
    $root = InventoryTransaction::query()->where('posting_key', "opening-stock:{$fixture['opening']->id}:line:{$fixture['line']->id}")->sole();
    $rootLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $root->id)->sole();
    $correction = app(OpeningStockQuantityCorrectionService::class)->prepare(request(), $fixture['opening'], [
        'posting_date' => ($fixture['day'])(3),
        'reason' => 'SYNTHETIC selected layer decrease',
        'source_reference' => 'SYNTHETIC selected layer evidence',
    ], [$fixture['line']->id => ['target_quantity' => '8', 'selected_receipt_layer_id' => $rootLayer->id]]);
    expect($correction->plan['lines'][0]['selected_receipt_layer_snapshot']['id'])->toBe($rootLayer->id)
        ->and($correction->plan['lines'][0]['selected_receipt_layer_snapshot']['remaining_quantity'])->toBe('10.00000000')
        ->and($correction->plan['lines'][0]['value'])->toBe('10.00000000')
        ->and($correction->plan['lines'][0]['value_basis'])->toBe('canonical_receipt_layer_allocator');
    actAsOpeningQuantityApprover($fixture);
    $approved = app(OpeningStockQuantityCorrectionService::class)->approve(
        request(), $fixture['opening'], $correction, 'SYNTHETIC selected approval',
    );
    $document = InventoryDocument::query()->with('lines', 'transactions')->findOrFail($approved->document_links[0]['id']);
    $issue = $document->transactions->sole();
    expect($document->lines->sole()->selected_receipt_layer_id)->toBe($rootLayer->id)
        ->and(InventoryLayerAllocation::query()->where('issue_transaction_id', $issue->id)->sole()->inventory_receipt_layer_id)->toBe($rootLayer->id)
        ->and($rootLayer->fresh()->remaining_quantity)->toBe('8.00000000');

    $invalid = openingQuantityCorrectionFixture();
    $activateSpecificPolicy($invalid);
    $wrongReceipt = costTransitionMovement($invalid, ($invalid['day'])(2), InventoryDocument::TypeAdjustmentIn, '3', '5', ['batch_lot' => 'WRONG-BATCH']);
    $wrongLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $wrongReceipt->transactions->sole()->id)->sole();
    $smallReceipt = costTransitionMovement($invalid, ($invalid['day'])(2), InventoryDocument::TypeAdjustmentIn, '1', '5');
    $smallLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $smallReceipt->transactions->sole()->id)->sole();
    foreach ([$wrongLayer, $smallLayer] as $selectedLayer) {
        $preview = app(OpeningStockQuantityCorrectionService::class)->preview(request(), $invalid['opening'], [
            'posting_date' => ($invalid['day'])(3),
            'reason' => 'SYNTHETIC blocked selected layer',
            'source_reference' => 'SYNTHETIC blocked selected evidence',
        ], [$invalid['line']->id => ['target_quantity' => '8', 'selected_receipt_layer_id' => $selectedLayer->id]]);
        expect($preview['can_prepare'])->toBeFalse()
            ->and($preview['lines'][0]['blocked_reasons'])->not->toBeEmpty();
        expect(fn () => app(OpeningStockQuantityCorrectionService::class)->prepare(request(), $invalid['opening'], [
            'posting_date' => ($invalid['day'])(3),
            'reason' => 'SYNTHETIC rejected selected layer',
            'source_reference' => 'SYNTHETIC rejected selected evidence',
        ], [$invalid['line']->id => ['target_quantity' => '8', 'selected_receipt_layer_id' => $selectedLayer->id]]))
            ->toThrow(DomainException::class);
    }
    expect(OpeningStockQuantityCorrection::query()->where('opening_stock_id', $invalid['opening']->id)->count())->toBe(0)
        ->and(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)
            ->where('source_document_id', '>', 0)->where('company_id', $invalid['company']->id)->count())->toBe(0);
});

test('FIFO quantity correction previews exact current layer slices without mutation and posts the same value across reviewer locales', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $transitions = app(InventoryCostPolicyTransitionService::class);
    $transition = $transitions->prepare($fixture['company']->id, [
        'branch_store_id' => $fixture['store']->id, 'effective_from' => ($fixture['day'])(2),
        'target_method' => InventoryCostPolicy::Fifo, 'reason' => 'SYNTHETIC FIFO opening correction',
    ], $fixture['preparer']->id);
    actAsOpeningQuantityApprover($fixture);
    $transitions->activate($transitions->approve($transition, $fixture['approver']->id), $fixture['approver']->id);
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    costTransitionMovement($fixture, ($fixture['day'])(3), InventoryDocument::TypeIssue, '4');
    costTransitionMovement($fixture, ($fixture['day'])(4), InventoryDocument::TypeAdjustmentIn, '3', '7');
    $beforeLayers = InventoryReceiptLayer::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray();
    $beforeBases = InventoryCostPolicyTransitionBasis::query()->where('inventory_cost_policy_transition_id', $transition->id)->orderBy('id')->get()->toArray();
    $beforeTransactions = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->count();
    $correction = prepareOpeningQuantityCorrection($fixture, '2', ($fixture['day'])(5));
    expect($correction->plan['lines'][0]['value'])->toBe('44.00000000')
        ->and($correction->plan['lines'][0]['layer_allocations'])->toHaveCount(2)
        ->and($correction->plan['lines'][0]['layer_allocations'][0]['quantity'])->toBe('6.00000000')
        ->and($correction->plan['lines'][0]['layer_allocations'][1]['quantity'])->toBe('2.00000000')
        ->and(InventoryReceiptLayer::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray())->toBe($beforeLayers)
        ->and(InventoryCostPolicyTransitionBasis::query()->where('inventory_cost_policy_transition_id', $transition->id)->orderBy('id')->get()->toArray())->toBe($beforeBases)
        ->and(InventoryTransaction::query()->where('company_id', $fixture['company']->id)->count())->toBe($beforeTransactions);
    actAsOpeningQuantityApprover($fixture);
    app()->setLocale('ar');
    $approved = app(OpeningStockQuantityCorrectionService::class)->approve(request(), $fixture['opening'], $correction, 'SYNTHETIC Arabic independent review');
    $document = InventoryDocument::query()->with('transactions', 'journalEntry.lines')->findOrFail($approved->document_links[0]['id']);
    expect($document->transactions->sole()->total_cost)->toBe('44.00000000')
        ->and(bcadd((string) $document->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('44.0000')
        ->and(bcadd((string) $document->journalEntry->lines->sum('credit_amount'), '0', 4))->toBe('44.0000')
        ->and($fixture['line']->fresh()->quantity)->toBe('10.0000');
});

test('quantity correction freezes real accounts rounding and journal amounts and stales after a product posting mapping change', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $proposal = prepareOpeningQuantityCorrection($fixture, '12.1234', ($fixture['day'])(3), '7.12345678');
    $contract = $proposal->plan['lines'][0]['accounting_contract'];
    expect($contract['debit_account_id'])->toBeInt()->and($contract['credit_account_id'])->toBeInt()
        ->and($contract['rounding_rule'])->toBe('half_up_line_gl_4_v1')
        ->and($contract['exact_total_cost'])->toBe(bcmul('2.1234', '7.12345678', 8))
        ->and($contract['booked_amount'])->toBe(bcround(bcmul('2.1234', '7.12345678', 8), 4));
    $fixture['product']->update(['item_classification' => Product::ClassificationFinishedProduct]);
    actAsOpeningQuantityApprover($fixture);
    expect(fn () => app(OpeningStockQuantityCorrectionService::class)->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC changed account review'))
        ->toThrow(DomainException::class);
    expect($proposal->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusPending)
        ->and(InventoryDocument::query()->where('company_id', $fixture['company']->id)->where('source_document_type', OpeningStockQuantityCorrection::class)->count())->toBe(0);
    $fixture['product']->update(['item_classification' => Product::ClassificationRawMaterial]);
    $approved = app(OpeningStockQuantityCorrectionService::class)->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC original mapping reviewed');
    $document = InventoryDocument::query()->with('journalEntry.lines', 'lines')->findOrFail($approved->document_links[0]['id']);
    $debit = $document->journalEntry->lines->firstWhere('account_id', $contract['debit_account_id']);
    $credit = $document->journalEntry->lines->firstWhere('account_id', $contract['credit_account_id']);
    expect($debit->debit_amount)->toBe($contract['booked_amount'])->and($credit->credit_amount)->toBe($contract['booked_amount'])
        ->and((int) $debit->branch_id)->toBe($contract['branch_id'])
        ->and($debit->cost_center_id)->toBe($contract['cost_center_id']);
});

test('a linked quantity correction cannot be generically reversed or unlocked away from its immutable approved plan', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $proposal = prepareOpeningQuantityCorrection($fixture, '12', ($fixture['day'])(3), '5');
    actAsOpeningQuantityApprover($fixture);
    $approved = app(OpeningStockQuantityCorrectionService::class)->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC linked review');
    $document = InventoryDocument::query()->findOrFail($approved->document_links[0]['id']);
    $beforeTransactions = InventoryTransaction::query()->count();
    $beforeJournals = JournalEntry::query()->count();
    $posting = app(InventoryDocumentPostingService::class);
    expect($posting->reversalPlan($document)['can_reverse'])->toBeFalse();
    expect(fn () => $posting->reverse($document, 'SYNTHETIC forbidden generic reversal'))->toThrow(DomainException::class);
    expect($approved->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusApproved)
        ->and($document->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and(InventoryTransaction::query()->count())->toBe($beforeTransactions)
        ->and(JournalEntry::query()->count())->toBe($beforeJournals);
});

test('a legal tiny valued quantity correction preserves eight decimal stock value without fabricating a zero journal', function (): void {
    $fixture = openingQuantityCorrectionFixture();
    $proposal = prepareOpeningQuantityCorrection($fixture, '11', ($fixture['day'])(3), '0.00000001');
    expect($proposal->plan['lines'][0]['accounting_contract']['booked_amount'])->toBe('0.0000')
        ->and($proposal->plan['lines'][0]['accounting_contract']['rounding_difference'])->toBe('0.00000001');
    $journalCount = JournalEntry::query()->count();
    actAsOpeningQuantityApprover($fixture);
    $approved = app(OpeningStockQuantityCorrectionService::class)->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC exact tiny valuation review');
    $document = InventoryDocument::query()->with('transactions')->findOrFail($approved->document_links[0]['id']);
    expect($document->status)->toBe(InventoryDocument::StatusPosted)->and($document->journal_entry_id)->toBeNull()
        ->and($document->transactions->sole()->total_cost)->toBe('0.00000001')
        ->and(JournalEntry::query()->count())->toBe($journalCount);
    expect(app(OpeningStockQuantityCorrectionService::class)->approve(request(), $fixture['opening'], $approved, 'SYNTHETIC tiny replay')->id)->toBe($approved->id);
});

test('serialized opening corrections consume selected live identities and receive unique new identities with exact unit accounting', function (): void {
    $fixture = serializedOpeningQuantityCorrectionFixture();
    $service = app(OpeningStockQuantityCorrectionService::class);
    $root = InventoryTransaction::query()->where('posting_key', "opening-stock:{$fixture['opening']->id}:line:{$fixture['line']->id}")->sole();
    $rootBefore = $root->only(['quantity_in', 'quantity_out', 'unit_cost', 'total_cost', 'serial_numbers']);
    $layers = InventoryReceiptLayer::query()->with('serialIdentity')
        ->where('receipt_transaction_id', $root->id)->orderBy('id')->get();
    costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeIssue, '1',
        lineOverrides: ['selected_receipt_layer_id' => $layers[0]->id]);

    $decrease = $service->prepare(request(), $fixture['opening'], [
        'posting_date' => ($fixture['day'])(3),
        'reason' => 'SYNTHETIC selected serialized opening decrease',
        'source_reference' => 'SYNTHETIC serialized decrease evidence',
    ], [$fixture['line']->id => [
        'target_quantity' => '2',
        'serial_receipt_layer_ids' => [$layers[1]->id],
    ]]);
    $decreaseLine = $decrease->plan['lines'][0];
    expect($decreaseLine['delta_quantity'])->toBe('-1.0000')
        ->and($decreaseLine['serial_units'])->toHaveCount(1)
        ->and($decreaseLine['serial_units'][0]['serial_number'])->toBe($layers[1]->serialIdentity->serial_number)
        ->and($decreaseLine['serial_units'][0]['selected_receipt_layer_id'])->toBe($layers[1]->id)
        ->and($decreaseLine['serial_units'][0]['inventory_serial_identity_id'])->toBe($layers[1]->inventory_serial_identity_id)
        ->and($decreaseLine['serial_units'][0]['accounting_contract']['exact_total_cost'])->toBe($decreaseLine['serial_units'][0]['value']);
    actAsOpeningQuantityApprover($fixture);
    $decrease = $service->approve(request(), $fixture['opening'], $decrease, 'SYNTHETIC serialized decrease approval');
    $out = InventoryDocument::query()->with(['lines', 'transactions.serialIdentity', 'journalEntry.lines'])
        ->findOrFail($decrease->document_links[0]['id']);
    expect($out->lines->sole()->selected_receipt_layer_id)->toBe($layers[1]->id)
        ->and($out->transactions->sole()->inventory_serial_identity_id)->toBe($layers[1]->inventory_serial_identity_id)
        ->and($layers[1]->serialIdentity->fresh()->current_receipt_layer_id)->toBeNull()
        ->and($root->fresh()->only(['quantity_in', 'quantity_out', 'unit_cost', 'total_cost', 'serial_numbers']))->toBe($rootBefore)
        ->and($fixture['line']->fresh()->quantity)->toBe('3.0000');

    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $increase = $service->prepare(request(), $fixture['opening'], [
        'posting_date' => ($fixture['day'])(4),
        'reason' => 'SYNTHETIC serialized opening increase',
        'source_reference' => 'SYNTHETIC serialized increase evidence',
    ], [$fixture['line']->id => [
        'target_quantity' => '4', 'unit_cost' => '7.12345678',
        'serial_numbers' => ['SYNTHETIC-OPEN-SERIAL-NEW-1', 'SYNTHETIC-OPEN-SERIAL-NEW-2'],
    ]]);
    expect($increase->plan['lines'][0]['delta_quantity'])->toBe('2.0000')
        ->and($increase->plan['lines'][0]['serial_units'])->toHaveCount(2)
        ->and($increase->plan['lines'][0]['serial_units'][0]['value'])->toBe('7.12345678')
        ->and($increase->plan['lines'][0]['serial_units'][0]['accounting_contract']['booked_amount'])->toBe('7.1235');
    actAsOpeningQuantityApprover($fixture);
    $increase = $service->approve(request(), $fixture['opening'], $increase, 'SYNTHETIC serialized increase approval');
    $in = InventoryDocument::query()->with(['lines', 'transactions.serialIdentity', 'journalEntry.lines'])
        ->findOrFail($increase->document_links[0]['id']);
    expect($in->lines)->toHaveCount(2)
        ->and($in->transactions->pluck('serialIdentity.serial_number')->sort()->values()->all())
        ->toBe(['SYNTHETIC-OPEN-SERIAL-NEW-1', 'SYNTHETIC-OPEN-SERIAL-NEW-2'])
        ->and($in->transactions->pluck('total_cost')->all())->toBe(['7.12345678', '7.12345678'])
        ->and(bcadd((string) $in->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('14.2470')
        ->and($service->approve(request(), $fixture['opening'], $increase, 'SYNTHETIC serialized replay')->document_links)
        ->toBe($increase->document_links);
});

test('serialized opening correction rejects consumed foreign duplicate stale and existing identities atomically', function (): void {
    $fixture = serializedOpeningQuantityCorrectionFixture();
    $service = app(OpeningStockQuantityCorrectionService::class);
    $root = InventoryTransaction::query()->where('posting_key', "opening-stock:{$fixture['opening']->id}:line:{$fixture['line']->id}")->sole();
    $layers = InventoryReceiptLayer::query()->with('serialIdentity')->where('receipt_transaction_id', $root->id)->orderBy('id')->get();
    costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeIssue, '1',
        lineOverrides: ['selected_receipt_layer_id' => $layers[0]->id]);
    $header = [
        'posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC invalid serialized correction',
        'source_reference' => 'SYNTHETIC invalid serialized evidence',
    ];
    foreach ([[$layers[0]->id], [$layers[1]->id, $layers[1]->id]] as $selected) {
        expect(fn () => $service->prepare(request(), $fixture['opening'], $header, [
            $fixture['line']->id => ['target_quantity' => '2', 'serial_receipt_layer_ids' => $selected],
        ]))->toThrow(DomainException::class);
    }
    expect(fn () => $service->prepare(request(), $fixture['opening'], $header, [
        $fixture['line']->id => ['target_quantity' => '4', 'unit_cost' => '8', 'serial_numbers' => [$fixture['serialNumbers'][0]]],
    ]))->toThrow(DomainException::class);
    expect(fn () => $service->prepare(request(), $fixture['opening'], $header, [
        $fixture['line']->id => ['target_quantity' => '5', 'unit_cost' => '8',
            'serial_numbers' => ['SYNTHETIC-DUPLICATE', 'synthetic-duplicate']],
    ]))->toThrow(DomainException::class);

    $pending = $service->prepare(request(), $fixture['opening'], $header, [
        $fixture['line']->id => ['target_quantity' => '2', 'serial_receipt_layer_ids' => [$layers[1]->id]],
    ]);
    costTransitionMovement($fixture, ($fixture['day'])(3), InventoryDocument::TypeIssue, '1',
        lineOverrides: ['selected_receipt_layer_id' => $layers[1]->id]);
    actAsOpeningQuantityApprover($fixture);
    expect(fn () => $service->approve(request(), $fixture['opening'], $pending, 'SYNTHETIC stale identity approval'))
        ->toThrow(DomainException::class)
        ->and($pending->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusPending)
        ->and(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)
            ->where('source_document_id', $pending->id)->count())->toBe(0)
        ->and(InventorySerialIdentity::query()->where('company_id', $fixture['company']->id)->count())->toBe(3);
});

test('serialized opening decreases use the actual posting policy and exhaust exact financial value in selected unit order', function (string $method): void {
    $fixture = serializedOpeningQuantityCorrectionFixture($method);
    $service = app(OpeningStockQuantityCorrectionService::class);
    $layers = $fixture['serialLayers'];
    $selected = [$layers[$fixture['serialNumbers'][2]], $layers[$fixture['serialNumbers'][0]], $layers[$fixture['serialNumbers'][1]]];
    $beforeRoot = $fixture['root']->getAttributes();
    $beforeLayers = InventoryReceiptLayer::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray();
    $proposal = $service->prepare(request(), $fixture['opening'], [
        'posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC serialized policy exhaustion',
        'source_reference' => 'SYNTHETIC whole opening correction evidence',
    ], [$fixture['line']->id => ['target_quantity' => '0', 'serial_receipt_layer_ids' => array_map(fn ($layer): int => $layer->id, $selected)]]);
    $expected = InventoryCostPolicy::usesReceiptLayers($method)
        ? ['3.33333334', '3.33333333', '3.33333333'] : ['3.33333333', '3.33333333', '3.33333334'];
    expect(array_column($proposal->plan['lines'][0]['serial_units'], 'value'))->toBe($expected)
        ->and($proposal->plan['lines'][0]['value'])->toBe('10.00000000')
        ->and(InventoryReceiptLayer::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray())->toBe($beforeLayers);
    actAsOpeningQuantityApprover($fixture);
    $approved = $service->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC policy-specific exact value review');
    $document = InventoryDocument::query()->with(['transactions', 'journalEntry.lines'])->findOrFail($approved->document_links[0]['id']);
    expect($document->transactions->pluck('total_cost')->all())->toBe($expected)
        ->and($document->transactions->pluck('cost_method')->unique()->all())->toBe([$method])
        ->and($document->transactions->pluck('cost_basis')->unique()->all())->toBe([
            match ($method) {
                InventoryCostPolicy::PeriodicWeightedAverage => 'periodic_provisional_moving_average',
                InventoryCostPolicy::MovingAverage => 'moving_average',
                InventoryCostPolicy::Fifo => 'fifo_allocations',
                default => 'selected_receipt_layer',
            },
        ])
        ->and($document->transactions->pluck('inventory_serial_identity_id')->all())->toBe(array_map(fn ($layer): int => $layer->inventory_serial_identity_id, $selected))
        ->and(bcadd((string) $document->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('9.9999')
        ->and(bcadd((string) $document->journalEntry->lines->sum('credit_amount'), '0', 4))->toBe('9.9999')
        ->and($fixture['root']->fresh()->getAttributes())->toBe($beforeRoot)
        ->and(InventorySerialIdentity::query()->where('company_id', $fixture['company']->id)->whereNotNull('current_receipt_layer_id')->count())->toBe(0);
    $transactions = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->get();
    expect($transactions->reduce(fn (string $sum, InventoryTransaction $transaction): string => bcadd($sum,
        bcsub($transaction->quantity_in, $transaction->quantity_out, 8), 8), '0.00000000'))->toBe('0.00000000')
        ->and($transactions->reduce(fn (string $sum, InventoryTransaction $transaction): string => bcadd($sum,
            bccomp($transaction->quantity_in, '0', 8) > 0 ? $transaction->total_cost : bcsub('0', $transaction->total_cost, 8), 8), '0.00000000'))->toBe('0.00000000')
        ->and($service->approve(request(), $fixture['opening'], $approved, 'SYNTHETIC exact policy replay')->id)->toBe($approved->id);
})->with([InventoryCostPolicy::MovingAverage, InventoryCostPolicy::PeriodicWeightedAverage,
    InventoryCostPolicy::Fifo, InventoryCostPolicy::SpecificIdentification]);

test('serialized average correction prices selected old identities from the pooled book balance and preserves physical allocation history', function (string $method): void {
    $fixture = serializedOpeningQuantityCorrectionFixture($method);
    costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeAdjustmentIn, '2', '7',
        lineOverrides: ['serial_numbers' => ['SYNTHETIC-MIXED-NEW-A', 'SYNTHETIC-MIXED-NEW-B']]);
    $selected = [$fixture['serialLayers'][$fixture['serialNumbers'][2]], $fixture['serialLayers'][$fixture['serialNumbers'][0]]];
    $service = app(OpeningStockQuantityCorrectionService::class);
    $proposal = $service->prepare(request(), $fixture['opening'], [
        'posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC pooled average selected identities',
        'source_reference' => 'SYNTHETIC mixed receipt evidence',
    ], [$fixture['line']->id => ['target_quantity' => '1', 'serial_receipt_layer_ids' => array_map(fn ($layer): int => $layer->id, $selected)]]);
    expect(array_column($proposal->plan['lines'][0]['serial_units'], 'value'))->toBe(['4.80000000', '4.80000000'])
        ->and($proposal->plan['lines'][0]['layer_allocations'][0]['total_cost'])->toBe('4.80000000')
        ->and($proposal->plan['lines'][0]['serial_units'][0]['physical_layer_allocations'][0]['total_cost'])->toBe('3.33333333');
    actAsOpeningQuantityApprover($fixture);
    $approved = $service->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC actual average review');
    $document = InventoryDocument::query()->with(['transactions', 'journalEntry.lines'])->findOrFail($approved->document_links[0]['id']);
    expect($document->transactions->pluck('total_cost')->all())->toBe(['4.80000000', '4.80000000'])
        ->and(InventoryLayerAllocation::query()->where('issue_transaction_id', $document->transactions->first()->id)->sole()->cost_total_snapshot)->toBe('4.80000000')
        ->and(bcadd((string) $document->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('9.6000');
    $position = app(InventoryValuationService::class)->bookPositionForPosition(
        $fixture['company']->id, $fixture['store']->id, $fixture['product']->id,
    );
    expect(bcadd($position['quantity'], '0', 8))->toBe('3.00000000')
        ->and(bcadd($position['value'], '0', 8))->toBe('14.40000000');
})->with([InventoryCostPolicy::MovingAverage, InventoryCostPolicy::PeriodicWeightedAverage]);

test('separate opening corrections sharing a book position recheck the actual posted balance before a later source is approved', function (string $method): void {
    $fixture = serializedOpeningQuantityCorrectionFixture($method);
    $secondOpening = $fixture['opening']->replicate(['public_id']);
    $secondOpening->fill(['doc_number' => 998703, 'doc_num' => 'SYNTHETIC-SERIAL-OS-SECOND'])->save();
    $second = $fixture['line']->replicate(['public_id']);
    $second->fill(['opening_stock_id' => $secondOpening->id, 'quantity' => '4', 'product_snapshot' => [
        ...$fixture['line']->product_snapshot, 'serial_numbers' => ['SYNTHETIC-SAME-POSITION-1', 'SYNTHETIC-SAME-POSITION-2', 'SYNTHETIC-SAME-POSITION-3', 'SYNTHETIC-SAME-POSITION-4'],
    ]])->save();
    $secondPricing = $fixture['pricing']->replicate(['public_id']);
    $secondPricing->fill(['opening_stock_id' => $secondOpening->id, 'doc_number' => 998703,
        'doc_num' => 'SYNTHETIC-SERIAL-OSP-SECOND', 'total_amount' => '0.0001'])->save();
    $pricingLine = $fixture['pricingLine']->replicate(['public_id']);
    $pricingLine->fill(['pricing_id' => $secondPricing->id, 'opening_stock_line_id' => $second->id, 'quantity' => '4',
        'unit_price' => '0.00002500', 'line_total' => '0.0001', 'product_snapshot' => $second->product_snapshot])->save();
    app(InventoryOpeningStockPostingService::class)->post($secondOpening);
    $lastLayers = InventoryReceiptLayer::query()->whereHas('serialIdentity',
        fn ($query) => $query->where('company_id', $fixture['company']->id)->where('serial_number', 'like', 'SYNTHETIC-SAME-POSITION-%'))->orderBy('id')->get();
    $service = app(OpeningStockQuantityCorrectionService::class);
    $header = ['posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC shared book balance',
        'source_reference' => 'SYNTHETIC separate opening sources evidence'];
    $proposal = $service->prepare(request(), $fixture['opening'], $header, [
        $fixture['line']->id => ['target_quantity' => '0', 'serial_receipt_layer_ids' => $fixture['serialLayers']->pluck('id')->all()],
    ]);
    $secondTargets = [$second->id => ['target_quantity' => '0', 'serial_receipt_layer_ids' => $lastLayers->modelKeys()]];
    $stale = $service->prepare(request(), $secondOpening, $header, $secondTargets);
    expect($proposal->plan['lines'][0]['value'])->toBe('4.28575713')
        ->and($stale->plan['lines'][0]['value'])->toBe('5.71434284');
    actAsOpeningQuantityApprover($fixture);
    $first = $service->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC first source review');
    expect(fn () => $service->approve(request(), $secondOpening, $stale, 'SYNTHETIC stale later source review'))
        ->toThrow(DomainException::class);
    expect(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)->where('source_document_id', $stale->id)->count())->toBe(0);
    $service->reject(request(), $secondOpening, $stale, 'SYNTHETIC superseded book balance');
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $fresh = $service->prepare(request(), $secondOpening, $header, $secondTargets);
    expect($fresh->plan['lines'][0]['value'])->toBe('5.71434287')
        ->and($fresh->plan['lines'][0]['book_position_before'])->toBe($first->plan['lines'][0]['book_position_after']);
    actAsOpeningQuantityApprover($fixture);
    $approved = $service->approve(request(), $secondOpening, $fresh, 'SYNTHETIC current later source review');
    $document = InventoryDocument::query()->with('transactions.serialIdentity', 'journalEntry.lines')->findOrFail($approved->document_links[0]['id']);
    expect($document->transactions->mapWithKeys(fn ($transaction): array => [
        $transaction->serialIdentity->serial_number => $transaction->total_cost,
    ])->sortKeys()->all())->toBe([
        'SYNTHETIC-SAME-POSITION-1' => '1.42858571',
        'SYNTHETIC-SAME-POSITION-2' => '1.42858572',
        'SYNTHETIC-SAME-POSITION-3' => '1.42858572',
        'SYNTHETIC-SAME-POSITION-4' => '1.42858572',
    ])
        ->and(bcadd((string) $document->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('5.7144')
        ->and($fixture['line']->fresh()->quantity)->toBe('3.0000')
        ->and($second->fresh()->quantity)->toBe('4.0000');
})->with([InventoryCostPolicy::MovingAverage, InventoryCostPolicy::PeriodicWeightedAverage]);

test('failure while consuming a later serial rolls back the entire correction including earlier physical identities and accounting', function (): void {
    $fixture = serializedOpeningQuantityCorrectionFixture(InventoryCostPolicy::MovingAverage);
    $service = app(OpeningStockQuantityCorrectionService::class);
    $proposal = $service->prepare(request(), $fixture['opening'], [
        'posting_date' => ($fixture['day'])(3), 'reason' => 'SYNTHETIC atomic serialized approval',
        'source_reference' => 'SYNTHETIC later unit failure evidence',
    ], [$fixture['line']->id => ['target_quantity' => '1',
        'serial_receipt_layer_ids' => $fixture['serialLayers']->values()->take(2)->pluck('id')->all()]]);
    $before = [
        'identities' => InventorySerialIdentity::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray(),
        'layers' => InventoryReceiptLayer::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray(),
        'transactions' => InventoryTransaction::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray(),
        'journals' => JournalEntry::query()->where('company_id', $fixture['company']->id)->with('lines')->get()->toArray(),
    ];
    $serials = Mockery::mock(InventorySerialService::class)->makePartial();
    $serials->shouldReceive('consume')->once()->passthru()->ordered();
    $serials->shouldReceive('consume')->once()->andThrow(new DomainException('SYNTHETIC later serial consumption failure'))->ordered();
    app()->instance(InventorySerialService::class, $serials);
    actAsOpeningQuantityApprover($fixture);
    expect(fn () => $service->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC atomic unit review'))
        ->toThrow(DomainException::class, 'SYNTHETIC later serial consumption failure');
    expect(InventorySerialIdentity::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray())->toBe($before['identities'])
        ->and(InventoryReceiptLayer::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray())->toBe($before['layers'])
        ->and(InventoryTransaction::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->toArray())->toBe($before['transactions'])
        ->and(JournalEntry::query()->where('company_id', $fixture['company']->id)->with('lines')->get()->toArray())->toBe($before['journals'])
        ->and($proposal->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusPending)
        ->and(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)->where('source_document_id', $proposal->id)->count())->toBe(0)
        ->and(InventoryLayerAllocation::query()->whereHas('issueTransaction', fn ($query) => $query->where('company_id', $fixture['company']->id))->count())->toBe(0);
});
