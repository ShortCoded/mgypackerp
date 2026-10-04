<?php

use App\Services\PostingAccountResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\OverheadAllocationService;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Services\CashVoucherService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryStandardCostSettlement;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryAccountingPostingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryStandardCostService;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionExpenseRequestService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryStandardCostSupport.php';

test('mixed-sign posted overhead sources retain only the frozen target WIP account and settle their actual net allocation', function (): void {
    $fixture = standardCostFixture();
    $standard = prepareSyntheticStandard($fixture);
    $service = app(InventoryStandardCostService::class);
    standardCostActor($fixture['approver']);
    $service->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC signed overhead standard');
    standardCostActor($fixture['preparer']);
    $fixture['product'] = $fixture['raw'];
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $centers = [];
    foreach (['source', 'target'] as $kind) {
        $number = (int) CostCenter::withTrashed()->max('doc_number') + 1;
        $centers[$kind] = CostCenter::create(['company_id' => $fixture['company']->id, 'doc_number' => $number,
            'doc_num' => 'SYNTHETIC-STD-CC-'.$number, 'cost_center_code' => 'STD-'.Str::random(10),
            'name' => 'SYNTHETIC signed overhead '.$kind, 'is_group' => false, 'status' => 'active']);
    }
    $centers['source']->accounts()->sync([$fixture['overhead_account']->id, $fixture['labor_account']->id]);
    $currency = Currency::where('company_id', $fixture['company']->id)->where('is_main', true)->sole();
    foreach ([[$fixture['overhead_account']->id, '200', '0'], [$fixture['labor_account']->id, '0', '50']] as $index => [$accountId, $debit, $credit]) {
        app(JournalEntryService::class)->createPostedFromSource(['company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
            'branch_id' => $fixture['branch']->id, 'entry_date' => now()->toDateString(), 'currency_id' => $currency->id, 'exchange_rate' => '1',
            'source_type' => 'synthetic_signed_overhead', 'source_id' => $index + 1, 'source_doc_num' => 'SYNTHETIC-STD-OH-'.($index + 1)],
            [['account_id' => $accountId, 'debit_amount' => $debit, 'credit_amount' => $credit, 'cost_center_id' => $centers['source']->id],
                ['account_id' => $fixture['clearing_account']->id, 'debit_amount' => $credit, 'credit_amount' => $debit]]);
    }
    $allocation = null;
    $run = standardCostCompletedRun($fixture, function ($run) use ($fixture, $centers, &$allocation): void {
        $run->update(['cost_center_id' => $centers['target']->id]);
        $allocations = app(OverheadAllocationService::class);
        $rule = $allocations->createRule([
            'name' => 'SYNTHETIC signed overhead allocation', 'source_cost_center_id' => $centers['source']->id,
            'source_account_ids' => [$fixture['overhead_account']->id, $fixture['labor_account']->id], 'target_cost_center_ids' => [$centers['target']->id],
            'basis' => OverheadAllocationRule::BasisDirectMaterialCost, 'cost_behavior' => 'variable',
            'effective_from' => now()->toDateString(), 'status' => 'active'], $fixture['company']->id, $fixture['branch']->id);
        $allocation = $allocations->approve($allocations->preview($rule, $fixture['period'], $fixture['branch']->id, now()->toDateString(), now()->toDateString()));
        expect($allocation->allocated_cost)->toBe('150.0000');
    });
    expect(app(InventoryAccountingPostingService::class)->historicalWipAccountIds($fixture['company']->id))
        ->not->toContain($fixture['labor_account']->id, $fixture['overhead_account']->id);
    $settlement = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC net signed overhead variance', $fixture['preparer']->id);
    expect($settlement->impact_snapshot['components']['overhead']['actual_total'])->toBe('150.00000000')
        ->and($settlement->impact_snapshot['components']['overhead']['variance'])->toBe('148.00000000');
    standardCostActor($fixture['approver']);
    $service->approveSettlement($settlement, $fixture['approver']->id, 'SYNTHETIC independent signed-source decision');
    expect(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000');
    $reconciled = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    expect($reconciled->every(fn (array $row): bool => bccomp($row['difference'], '0', 4) === 0))->toBeTrue($reconciled->toJson());
});

test('independently approved standard settlement reclassifies legacy expense debits and reverses that capitalization while preserving original payment history', function (bool $paidBeforeReceipt, bool $legacyRateMissing): void {
    $fixture = standardCostFixture();
    $service = app(InventoryStandardCostService::class);
    $standard = prepareSyntheticStandard($fixture);
    standardCostActor($fixture['approver']);
    $service->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC legacy-cost standard');
    standardCostActor($fixture['preparer']);
    $fixture['product'] = $fixture['raw'];
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $expense = null;
    $run = standardCostCompletedRun($fixture, function ($run) use (&$expense, $fixture, $paidBeforeReceipt, $legacyRateMissing): void {
        $expense = standardCostExpenseRequest($fixture, $run);
        if ($legacyRateMissing) {
            $expense->forceFill(['exchange_rate' => null])->save();
        }
        if ($paidBeforeReceipt) {
            standardCostSyntheticLegacyPayment($fixture, $expense);
        }
    });
    $first = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC original legacy variance', $fixture['preparer']->id);
    standardCostActor($fixture['approver']);
    $service->approveSettlement($first, $fixture['approver']->id, 'SYNTHETIC independent historical reclassification');
    standardCostActor($fixture['preparer']);
    if (! $paidBeforeReceipt) {
        standardCostSyntheticLegacyPayment($fixture, $expense);
        $late = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC late legacy expense capitalization', $fixture['preparer']->id);
        standardCostActor($fixture['approver']);
        $service->approveSettlement($late, $fixture['approver']->id, 'SYNTHETIC independent late legacy approval');
        standardCostActor($fixture['preparer']);
    }
    $expense->refresh();
    $original = $expense->journalEntry->getAttributes();
    expect($expense->journalEntry->lines->firstWhere('account_id', $expense->expense_account_id)->debit_amount)->toBe('125.0000')
        ->and(app(ProductionCostService::class)->runPosition($run)['standard_variance'])->toBe('128.00000000')
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000');
    $reconciled = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    expect($reconciled->every(fn ($row): bool => bccomp($row['difference'], '0', 4) === 0))->toBeTrue($reconciled->toJson());
    app(ProductionExpenseRequestService::class)->reverse($expense, 'SYNTHETIC original legacy payment reversal');
    $replacement = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC reverse capitalization and variance', $fixture['preparer']->id);
    expect(collect($replacement->impact_snapshot['effects'])->where('production_cost_role', 'expense_capitalized')->sum('amount'))->toEqual(-125);
    standardCostActor($fixture['approver']);
    $service->approveSettlement($replacement, $fixture['approver']->id, 'SYNTHETIC independent legacy reversal decision');
    $position = app(ProductionCostService::class)->runPosition($run);
    expect($position['capitalizable'])->toBe('38.00000000')->and($position['finished_goods'])->toBe('35.00000000')
        ->and($position['standard_variance'])->toBe('3.00000000')->and($position['wip'])->toBe('0.00000000');
    $reconciled = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    expect($reconciled->every(fn ($row): bool => bccomp($row['difference'], '0', 4) === 0))->toBeTrue($reconciled->toJson());
    $original['reversed_entry_id'] = $expense->fresh()->reversal_journal_entry_id;
    $original['updated_at'] = $expense->journalEntry->fresh()->getRawOriginal('updated_at');
    expect($expense->journalEntry->fresh()->getAttributes())->toBe($original);
})->with(['paid before finished goods receipts' => [true, false], 'paid after standard settlement' => [false, false],
    'legacy null main-currency rate before receipts' => [true, true], 'legacy null main-currency rate after settlement' => [false, true]]);

test('standard revision clears the frozen historical WIP account even after its current classification and selection status change', function (): void {
    $fixture = standardCostFixture();
    $service = app(InventoryStandardCostService::class);
    $standard = prepareSyntheticStandard($fixture);
    standardCostActor($fixture['approver']);
    $service->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC frozen-account standard');
    standardCostActor($fixture['preparer']);
    $fixture['product'] = $fixture['raw'];
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $expense = null;
    $run = standardCostCompletedRun($fixture, function ($run) use (&$expense, $fixture): void {
        $expense = standardCostExpenseRequest($fixture, $run);
    });
    $first = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC baseline variance', $fixture['preparer']->id);
    standardCostActor($fixture['approver']);
    $service->approveSettlement($first, $fixture['approver']->id, 'SYNTHETIC baseline approval');
    standardCostActor($fixture['preparer']);
    $paid = app(ProductionExpenseRequestService::class)->pay($expense);
    $frozenId = $paid->cost_accounting_snapshot['debit_account_id'];
    Account::findOrFail($frozenId)->update(['status' => 'inactive', 'is_postable' => false,
        'account_classification_id' => $fixture['overhead_account']->account_classification_id]);
    $revision = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC exact historical account correction', $fixture['preparer']->id);
    expect(collect($revision->impact_snapshot['effects'])->where('effect', 'wip')->sole()['account_id'])->toBe($frozenId);
    standardCostActor($fixture['approver']);
    $approved = $service->approveSettlement($revision, $fixture['approver']->id, 'SYNTHETIC source-proven historical account approval');
    expect($approved->valueAdjustment->journalEntry->lines->firstWhere('account_id', $frozenId)->credit_amount)->toBe('125.0000')
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000');
    $reconciled = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    expect($reconciled->every(fn ($row): bool => bccomp($row['difference'], '0', 4) === 0))->toBeTrue($reconciled->toJson());
});

test('late paid production expense and its reversal settle incremental overhead variance without leaving completed run WIP or duplicate cash accounting', function (): void {
    $fixture = standardCostFixture();
    $standard = prepareSyntheticStandard($fixture);
    standardCostActor($fixture['approver']);
    $service = app(InventoryStandardCostService::class);
    $service->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC late expense standard');
    standardCostActor($fixture['preparer']);
    $fixture['product'] = $fixture['raw'];
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $currency = Currency::where('company_id', $fixture['company']->id)->where('is_main', true)->sole();
    $cashAccount = Account::where('company_id', $fixture['company']->id)->where('account_type', Account::TypeAsset)->where('is_postable', true)->orderBy('id')->firstOrFail();
    $cashbox = Cashbox::create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->id),
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'account_id' => $cashAccount->id,
        'name' => 'SYNTHETIC late expense cashbox', 'status' => 'active',
    ]);
    CashboxCurrency::create(['cashbox_id' => $cashbox->id, 'currency_id' => $currency->id, 'is_default' => true, 'status' => 'active']);
    $expense = null;
    $expenses = app(ProductionExpenseRequestService::class);
    $run = standardCostCompletedRun($fixture, function ($run) use (&$expense, $expenses, $currency, $cashbox, $fixture): void {
        $expense = $expenses->approve($expenses->create($run, ['amount' => '125', 'currency_id' => $currency->id,
            'payment_channel' => 'cashbox', 'cashbox_id' => $cashbox->id, 'expense_account_id' => $fixture['overhead_account']->id,
            'reason' => 'SYNTHETIC freight approved before receipt and paid after standard finalization']));
    });
    $first = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC original variance', $fixture['preparer']->id);
    standardCostActor($fixture['approver']);
    $service->approveSettlement($first, $fixture['approver']->id, 'SYNTHETIC original independent decision');
    standardCostActor($fixture['preparer']);
    $paid = $expenses->pay($expense);
    $wip = app(PostingAccountResolver::class)->resolve($fixture['company']->id, PostingAccountResolver::WorkInProcessInventory, 'SYNTHETIC');
    expect($paid->journalEntry->lines->firstWhere('account_id', $wip->id)->debit_amount)->toBe('125.0000')
        ->and(JournalEntry::where('source_type', 'production_expense_payment')->where('source_id', $paid->id)->count())->toBe(1)
        ->and(JournalEntry::where('source_type', CashVoucherService::SourcePayment)->where('source_id', $paid->cash_voucher_id)->count())->toBe(0)
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('125.00000000');
    $revision = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC settle late freight', $fixture['preparer']->id);
    expect($revision->impact_snapshot['components']['overhead']['actual_total'])->toBe('125.00000000')
        ->and($revision->impact_snapshot['components']['overhead']['previous_variance'])->toBe('-2.00000000')
        ->and(collect($revision->impact_snapshot['effects'])->where('effect', 'wip')->sole()['amount'])->toBe('-125.00000000');
    standardCostActor($fixture['approver']);
    $service->approveSettlement($revision, $fixture['approver']->id, 'SYNTHETIC independent late freight decision');
    expect(app(ProductionCostService::class)->runPosition($run)['standard_variance'])->toBe('128.00000000')
        ->and(app(ProductionCostService::class)->runPosition($run)['wip'])->toBe('0.00000000');
    standardCostActor($fixture['preparer']);
    $expenses->reverse($paid->fresh(), 'SYNTHETIC payment correction');
    $third = $service->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC reverse late freight variance', $fixture['preparer']->id);
    standardCostActor($fixture['approver']);
    $service->approveSettlement($third, $fixture['approver']->id, 'SYNTHETIC independent reversed freight decision');
    $position = app(ProductionCostService::class)->runPosition($run);
    expect($position['finished_goods'])->toBe('35.00000000')->and($position['capitalizable'])->toBe('38.00000000')
        ->and($position['standard_variance'])->toBe('3.00000000')->and($position['wip'])->toBe('0.00000000');
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    expect($reconciliation->every(fn (array $row): bool => bccomp((string) $row['difference'], '0', 4) === 0))->toBeTrue($reconciliation->toJson());
});

test('standard versions preserve dated components and independently approved account mappings', function (): void {
    $fixture = standardCostFixture();
    $standard = prepareSyntheticStandard($fixture);
    expect($standard->status)->toBe('prepared')->and($standard->materials_unit_cost)->toBe('3.20000000');
    expect(fn () => prepareSyntheticStandard($fixture))->toThrow(DomainException::class);
    $fixture['preparer']->givePermissionTo('inventory.cost_policies.standard.approve');
    expect(fn () => app(InventoryStandardCostService::class)->approveVersion($standard, $fixture['preparer']->id, 'SYNTHETIC self approval'))->toThrow(DomainException::class);
    standardCostActor($fixture['approver']);
    $approved = app(InventoryStandardCostService::class)->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC independently approved');
    expect($approved->status)->toBe('approved')->and($approved->approved_by)->toBe($fixture['approver']->id);
    expect(fn () => $approved->update(['materials_unit_cost' => '7']))->toThrow(DomainException::class);
});

test('standard settlement posts material labor and overhead variances and downstream issue differences without rewriting actual history', function (): void {
    $fixture = standardCostFixture();
    $standard = prepareSyntheticStandard($fixture);
    standardCostActor($fixture['approver']);
    app(InventoryStandardCostService::class)->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC independently approved');
    standardCostActor($fixture['preparer']);
    $fixture['product'] = $fixture['raw'];
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $run = standardCostCompletedRun($fixture);
    $fixture['product'] = $fixture['finished'];
    $issue = costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeIssue, '4', lineOverrides: ['batch_lot' => $run->batch_lot]);
    $history = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)->orderBy('id')->get()->mapWithKeys(fn ($row): array => [$row->id => $row->getAttributes()])->all();
    $settlement = app(InventoryStandardCostService::class)->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC final run variance', $fixture['preparer']->id);
    expect($settlement->impact_snapshot['components']['materials']['variance'])->toBe('6.00000000')
        ->and($settlement->impact_snapshot['components']['labor']['variance'])->toBe('-1.00000000')
        ->and($settlement->impact_snapshot['components']['overhead']['variance'])->toBe('-2.00000000');
    standardCostActor($fixture['approver']);
    $approved = app(InventoryStandardCostService::class)->approveSettlement($settlement, $fixture['approver']->id, 'SYNTHETIC independently approved variances');
    $position = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($approved->status)->toBe('finalized')->and($position['finished_goods'])->toBe('35.00000000')
        ->and($position['capitalizable'])->toBe('38.00000000')->and($position['standard_variance'])->toBe('3.00000000')
        ->and($position['wip'])->toBe('0.00000000')->and($issue->transactions->sole()->completedTotalCost())->toBe('14.00000000');
    expect(InventoryTransaction::query()->whereIn('id', array_keys($history))->orderBy('id')->get()->mapWithKeys(fn ($row): array => [$row->id => $row->getAttributes()])->all())->toBe($history);
    $journal = $approved->valueAdjustment->journalEntry;
    expect($journal)->not->toBeNull();
    expect(bccomp((string) $journal->lines->sum('debit_amount'), (string) $journal->lines->sum('credit_amount'), 4))->toBe(0);
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    foreach (['wip', 'finished_goods'] as $key) {
        expect($reconciliation->firstWhere('key', $key)['difference'])->toBe('0.0000');
    }
    expect(fn () => app(InventoryStandardCostService::class)->approveSettlement($settlement, $fixture['approver']->id, 'SYNTHETIC repeated approval'))->toThrow(DomainException::class);
    expect(InventoryStandardCostSettlement::query()->where('production_run_id', $run->id)->count())->toBe(1);
});

test('standard variance revision after a periodic material correction keeps the approved standard and books only new component differences', function (): void {
    $fixture = standardCostFixture();
    $fixture['product'] = $fixture['raw'];
    foreach (['inventory.cost_policies.view', 'inventory.cost_policies.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['preparer']->givePermissionTo(['inventory.cost_policies.view', 'inventory.cost_policies.manage']);
    $fixture = periodicCostFixture($fixture);
    $standard = prepareSyntheticStandard($fixture);
    standardCostActor($fixture['approver']);
    app(InventoryStandardCostService::class)->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC independent standard');
    standardCostActor($fixture['preparer']);
    $today = now()->toDateString();
    costTransitionMovement($fixture, $today, InventoryDocument::TypeReceipt, '20', '2');
    $run = standardCostCompletedRun($fixture);
    $first = app(InventoryStandardCostService::class)->prepareSettlement($run, $today, 'SYNTHETIC initial variance', $fixture['preparer']->id);
    standardCostActor($fixture['approver']);
    app(InventoryStandardCostService::class)->approveSettlement($first, $fixture['approver']->id, 'SYNTHETIC first independent variance');
    standardCostActor($fixture['preparer']);
    costTransitionMovement($fixture, $today, InventoryDocument::TypeReceipt, '20', '4');
    approvePeriodicCost($fixture, preparePeriodicCost($fixture, ['from_date' => $today, 'to_date' => $today, 'posting_date' => $today]));
    $position = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($position['direct_material_cost'])->toBe('57.00000000')->and($position['finished_goods'])->toBe('54.00000000')
        ->and($position['standard_variance'])->toBe('3.00000000')->and($position['wip'])->toBe('0.00000000');
    standardCostActor($fixture['preparer']);
    $revision = app(InventoryStandardCostService::class)->prepareSettlement($run, $today, 'SYNTHETIC corrected material variance', $fixture['preparer']->id);
    expect($revision->revision)->toBe(2)
        ->and($revision->impact_snapshot['components']['materials']['variance'])->toBe('25.00000000')
        ->and($revision->impact_snapshot['components']['materials']['previous_variance'])->toBe('6.00000000');
    standardCostActor($fixture['approver']);
    $final = app(InventoryStandardCostService::class)->approveSettlement($revision, $fixture['approver']->id, 'SYNTHETIC revised independent variance');
    $position = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($position['finished_goods'])->toBe('35.00000000')->and($position['standard_variance'])->toBe('22.00000000')
        ->and($position['wip'])->toBe('0.00000000');
    foreach (['materials' => '19.0000', 'labor' => '0.0000', 'overhead' => '0.0000'] as $component => $expected) {
        $net = $final->valueAdjustment->journalEntry->lines->where('account_id', $fixture[$component.'_account']->id)
            ->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), 4), '0.0000');
        expect($net)->toBe($expected);
    }
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    foreach (['raw_materials', 'wip', 'finished_goods', 'production_waste'] as $key) {
        expect($reconciliation->firstWhere('key', $key)['difference'])->toBe('0.0000');
    }
    standardCostActor($fixture['preparer']);
    expect(fn () => app(InventoryStandardCostService::class)->prepareSettlement($run, $today, 'SYNTHETIC unchanged third variance', $fixture['preparer']->id))->toThrow(DomainException::class);
});

test('failed standard accounting rolls back every stock correction and retains a retryable prepared settlement', function (): void {
    $fixture = standardCostFixture();
    $standard = prepareSyntheticStandard($fixture);
    standardCostActor($fixture['approver']);
    app(InventoryStandardCostService::class)->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC independent standard');
    standardCostActor($fixture['preparer']);
    $fixture['product'] = $fixture['raw'];
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $run = standardCostCompletedRun($fixture);
    $settlement = app(InventoryStandardCostService::class)->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC rollback variance', $fixture['preparer']->id);
    $before = InventoryTransaction::query()->count();
    $this->partialMock(JournalEntryService::class, fn ($mock) => $mock->shouldReceive('createPostedFromSource')->once()->andThrow(new DomainException('SYNTHETIC standard journal failure')));
    standardCostActor($fixture['approver']);
    expect(fn () => app(InventoryStandardCostService::class)->approveSettlement($settlement, $fixture['approver']->id, 'SYNTHETIC failed independent variance'))->toThrow(DomainException::class)
        ->and($settlement->fresh()->status)->toBe('prepared')
        ->and(InventoryTransaction::query()->count())->toBe($before)
        ->and(InventoryValueAdjustment::query()->where('source_type', InventoryStandardCostSettlement::class)->where('source_id', $settlement->id)->count())->toBe(0);
    $position = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($position['finished_goods'])->toBe('38.00000000')->and($position['standard_variance'])->toBe('0.00000000')->and($position['wip'])->toBe('0.00000000');
});

test('correcting a settled production run reverses its old variances and settles replacement receipts against their own active basis', function (): void {
    $fixture = standardCostFixture();
    foreach (['production.runs.correct', 'production.runs.correct_approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['preparer']->givePermissionTo('production.runs.correct');
    $fixture['approver']->givePermissionTo('production.runs.correct_approve');
    $standard = prepareSyntheticStandard($fixture);
    standardCostActor($fixture['approver']);
    app(InventoryStandardCostService::class)->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC independent standard');
    standardCostActor($fixture['preparer']);
    $fixture['product'] = $fixture['raw'];
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $run = standardCostCompletedRun($fixture);
    $settlement = app(InventoryStandardCostService::class)->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC original variance', $fixture['preparer']->id);
    standardCostActor($fixture['approver']);
    app(InventoryStandardCostService::class)->approveSettlement($settlement, $fixture['approver']->id, 'SYNTHETIC independently settled');
    standardCostActor($fixture['preparer']);
    $corrections = app(ProductionRunCorrectionService::class);
    $oldReceiptIds = $run->inventoryDocuments()->where('document_type', InventoryDocument::TypeProductionReceipt)->where('status', InventoryDocument::StatusPosted)->pluck('id');
    $preview = $corrections->preview($run->fresh());
    $proposal = $corrections->propose($run->fresh(), ['good_base_quantity' => '9', 'rejected_base_quantity' => '1', 'rework_base_quantity' => '0', 'scrap_base_quantity' => '0'], 'SYNTHETIC corrected output', $preview['fingerprint'], now()->toDateString());
    standardCostActor($fixture['approver']);
    $corrections->approve($run->fresh(), $proposal->id);
    foreach (['materials' => '-6.0000', 'labor' => '1.0000', 'overhead' => '2.0000'] as $component => $expected) {
        $amount = DB::table('journal_entry_lines as line')->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->where('journal.company_id', $fixture['company']->id)->where('journal.source_type', 'inventory_document_cost_completion_reversal')
            ->whereIn('journal.source_id', $oldReceiptIds)->where('line.account_id', $fixture[$component.'_account']->id)
            ->selectRaw('coalesce(sum(line.debit_amount - line.credit_amount), 0) as amount')->value('amount');
        expect(bcadd((string) $amount, '0', 4))->toBe($expected);
    }
    $position = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($position['standard_variance'])->toBe('0.00000000')->and($position['wip'])->toBe('40.00000000');
    standardCostActor($fixture['preparer']);
    $cycle = app(ProductionCycleService::class);
    $cycle->accountMaterials($run->fresh(), $fixture['store']->id, [$run->requirements()->sole()->id => ['consumed_quantity' => '18', 'waste_quantity' => '2']]);
    $inspection = $cycle->recordInspection($run->fresh(), ['result' => 'passed', 'disposition' => 'release']);
    $cycle->reviewInspection($inspection, true);
    $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '9');
    $run = $cycle->completeRun($run->fresh());
    $replacement = app(InventoryStandardCostService::class)->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC replacement variance', $fixture['preparer']->id);
    expect($replacement->impact_snapshot['components']['materials']['previous_variance'])->toBe('0.00000000');
    standardCostActor($fixture['approver']);
    app(InventoryStandardCostService::class)->approveSettlement($replacement, $fixture['approver']->id, 'SYNTHETIC replacement independently settled');
    $position = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($position['capitalizable'])->toBe('36.00000000')->and($position['finished_goods'])->toBe('31.50000000')
        ->and($position['standard_variance'])->toBe('4.50000000')->and($position['wip'])->toBe('0.00000000');
    foreach (['materials' => '7.2000', 'labor' => '-0.9000', 'overhead' => '-1.8000'] as $component => $expected) {
        $amount = DB::table('journal_entry_lines as line')->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->where('journal.company_id', $fixture['company']->id)->where('journal.status', 'posted')->where('line.branch_id', $fixture['branch']->id)
            ->where('line.account_id', $fixture[$component.'_account']->id)->selectRaw('coalesce(sum(line.debit_amount - line.credit_amount), 0) as amount')->value('amount');
        expect(bcround((string) $amount, 4))->toBe($expected);
    }

    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    foreach (['raw_materials', 'wip', 'finished_goods', 'production_waste'] as $key) {
        expect($reconciliation->firstWhere('key', $key)['difference'])->toBe('0.0000');
    }
});

test('standard decisions recheck revoked authority changed account configuration and current financial period without financial writes', function (): void {
    $fixture = standardCostFixture();
    $standard = prepareSyntheticStandard($fixture);
    standardCostActor($fixture['approver']);
    $fixture['approver']->revokePermissionTo('inventory.cost_policies.standard.approve');
    expect(fn () => app(InventoryStandardCostService::class)->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC unauthorized decision'))->toThrow(AuthorizationException::class)
        ->and($standard->fresh()->status)->toBe('prepared');
    $fixture['approver']->givePermissionTo('inventory.cost_policies.standard.approve');
    $originalName = $fixture['materials_account']->name;
    $fixture['materials_account']->update(['name' => 'SYNTHETIC changed mapping identity']);
    expect(fn () => app(InventoryStandardCostService::class)->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC stale mapping decision'))->toThrow(DomainException::class)
        ->and($standard->fresh()->status)->toBe('prepared');
    $fixture['materials_account']->update(['name' => $originalName]);
    app(InventoryStandardCostService::class)->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC restored configured basis');
    standardCostActor($fixture['preparer']);
    $fixture['product'] = $fixture['raw'];
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '2');
    $run = standardCostCompletedRun($fixture);
    $settlement = app(InventoryStandardCostService::class)->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC scoped variance', $fixture['preparer']->id);
    $before = InventoryTransaction::query()->count();
    standardCostActor($fixture['approver']);
    foreach (['missing', 'closed'] as $scenario) {
        request()->session()->put(manufacturingIntegritySession($fixture));
        if ($scenario === 'missing') {
            request()->session()->put(OperatingContextService::FinancialPeriodIdKey, null);
        } else {
            $fixture['period']->update(['is_closed' => true]);
        }
        $error = null;
        try {
            app(InventoryStandardCostService::class)->approveSettlement($settlement, $fixture['approver']->id, 'SYNTHETIC '.$scenario.' period decision');
        } catch (DomainException|AuthorizationException $denied) {
            $error = $denied;
        }
        expect($error)->not->toBeNull()->and($settlement->fresh()->status)->toBe('prepared')
            ->and(InventoryTransaction::query()->count())->toBe($before);
    }
});
