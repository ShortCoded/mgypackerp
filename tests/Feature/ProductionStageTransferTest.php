<?php

use App\Services\PostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\OverheadAllocationService;
use Modules\Core\Models\Currency;
use Modules\Finance\Models\BankAccount;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Models\ProductionStageTransfer;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionExpenseRequestService;
use Modules\Production\Services\ProductionQualityQuantityService;
use Modules\Production\Services\ProductionStageTransferService;

require_once __DIR__.'/../ManufacturingInventorySupport.php';

require_once __DIR__.'/../ProductionStageTransferSupport.php';

test('three physical stages retain per stage WIP and recognize partial finished goods once', function (): void {
    $f = physicalStageFixture();
    physicalStageProgress($f, 0, '6', '12');
    $beforeStock = InventoryTransaction::query()->count();
    $first = physicalStagePost($f, 0, '4');
    expect((string) $first->total_cost)->toBe('80.00000000')->and(InventoryTransaction::query()->count())->toBe($beforeStock);
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][1]->fresh());
    physicalStageProgress($f, 1, '2', '2');
    $costs = app(ProductionCostService::class);
    expect(app(ProductionStageTransferService::class)->position($f['runs'][1]->fresh())['used_cost'])->toBe('40.00000000')
        ->and($costs->receiptCost($f['runs'][1]->fresh(), '1'))->toBe('25.00000000');
    $second = physicalStagePost($f, 1, '1');
    expect($second->total_cost)->toBe('25.00000000');
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][2]->fresh());
    physicalStageProgress($f, 2, '1', '1');
    physicalStageProgress($f, 1, '2', '2');
    expect(physicalStagePost($f, 1, '2')->total_cost)->toBe('50.00000000');
    physicalStageProgress($f, 2, '2', '2');
    physicalStageActor($f);
    $receipt = $f['cycle']->receiveFinishedGoods($f['runs'][2]->fresh(), $f['store']->id, '2');
    expect($receipt->lines->sole()->unit_cost)->toBe('27.00000000')->and($receipt->lines->sole()->total_cost)->toBe('54.00000000')
        ->and($f['line']->fresh()->received_base_quantity)->toBe('2.00000000')
        ->and(InventoryTransaction::query()->where('company_id', $f['company']->id)->where('product_id', $f['finished']->id)->where('quantity_in', '>', 0)->count())->toBe(1);
    $positions = $f['runs']->map(fn ($run): array => $costs->runPosition($run->fresh()));
    expect($positions->pluck('wip')->all())->toBe(['120.00000000', '55.00000000', '41.00000000'])
        ->and($positions->pluck('stage_incoming_cost')->all())->toBe(['0.00000000', '80.00000000', '75.00000000'])
        ->and($positions->pluck('stage_outgoing_cost')->all())->toBe(['80.00000000', '75.00000000', '0.00000000']);
    $account = app(PostingAccountResolver::class)->resolve($f['company']->id, PostingAccountResolver::WorkInProcessInventory, 'SYNTHETIC stage reconciliation');
    foreach (['120.0000', '55.0000', '41.0000'] as $index => $expected) {
        $lines = DB::table('journal_entry_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->where('entry.company_id', $f['company']->id)->where('entry.status', JournalEntry::StatusPosted)->where('entry.is_posted', true)
            ->where('line.account_id', $account->id)->where('line.cost_center_id', $f['runs'][$index]->cost_center_id);
        expect(bcsub((string) (clone $lines)->sum('line.debit_amount'), (string) (clone $lines)->sum('line.credit_amount'), 4))->toBe($expected);
    }
    $wip = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id))->firstWhere('key', 'wip');
    expect($wip['subledger'])->toBe('216.0000')->and($wip['gl'])->toBe('216.0000')->and($wip['difference'])->toBe('0.0000');
    expect(fn () => app(ProductionStageTransferService::class)->reverse($first->fresh(), 'SYNTHETIC downstream recovery'))
        ->toThrow(DomainException::class);
    physicalStageActor($f, true);
    $journalCount = JournalEntry::query()->count();
    app(ProductionStageTransferService::class)->approve($first->fresh());
    expect(JournalEntry::query()->count())->toBe($journalCount);
    foreach (['ar', 'en'] as $locale) {
        $this->withSession(['locale' => $locale]);
        app()->setLocale($locale);
        $this->get(route('admin.production.runs.stage-transfers.index', $f['runs'][0]))->assertOk()->assertSee(__('production_stage_transfer.title'))->assertDontSee('production_stage_transfer.status_');
    }
});

test('unused stage transfer reverses its exact journal and releases quality quantity once', function (): void {
    $f = physicalStageFixture();
    physicalStageProgress($f, 0, '6', '12');
    $owner = physicalStagePost($f, 0, '4');
    $service = app(ProductionStageTransferService::class);
    expect(fn () => $f['cycle']->cancelRun($f['runs'][1]->fresh(), 'SYNTHETIC unsafe run cancellation'))->toThrow(DomainException::class, __('production_stage_transfer.owner_recovery_required'));
    $original = JournalEntry::query()->with('lines')->findOrFail($owner->journal_entry_id);
    $stock = InventoryTransaction::query()->count();
    $reversed = $service->reverse($owner, 'SYNTHETIC unused delivery returned');
    $inverse = JournalEntry::query()->findOrFail($reversed->reversal_journal_entry_id);
    app(JournalEntryService::class)->assertPostedReversal($original, $inverse);
    expect($service->position($f['runs'][1]->fresh())['incoming'])->toBe('0.00000000')
        ->and(app(ProductionCostService::class)->runPosition($f['runs'][0]->fresh())['wip'])->toBe('200.00000000')
        ->and(app(ProductionQualityQuantityService::class)->availableQuantity($f['runs'][0]->fresh()))->toBe('6.00000000')
        ->and(InventoryTransaction::query()->count())->toBe($stock);
    $journals = JournalEntry::query()->count();
    $service->reverse($reversed, 'SYNTHETIC unused delivery returned');
    expect(JournalEntry::query()->count())->toBe($journals);
    $f['period']->update(['is_closed' => true]);
    expect(fn () => physicalStagePrepare($f, 0, '1'))->toThrow(DomainException::class);
});

test('stage approvals reject self review tampered quantities stale snapshots and skipped stages atomically', function (): void {
    $f = physicalStageFixture();
    physicalStageProgress($f, 0, '6', '12');
    $service = app(ProductionStageTransferService::class);
    $first = physicalStagePrepare($f, 0, '4');
    $second = physicalStagePrepare($f, 0, '4');
    $journals = JournalEntry::query()->count();
    expect(fn () => $service->approve($first))->toThrow(DomainException::class, __('production_run_correction.independent_approval'));
    expect(fn () => $service->preview($f['runs'][0], $f['runs'][2]))->toThrow(DomainException::class);
    physicalStageActor($f, true);
    $service->approve($first);
    expect(fn () => $service->approve($second))->toThrow(DomainException::class, __('production_run_correction.stale'));
    $second->forceFill(['base_quantity' => '1'])->save();
    expect(fn () => $service->approve($second->fresh()))->toThrow(DomainException::class, __('production_stage_transfer.invalid'));
    expect(JournalEntry::query()->count())->toBe($journals + 1)
        ->and(ProductionStageTransfer::query()->where('status', 'posted')->count())->toBe(1);
    physicalStageActor($f);
    expect(fn () => $f['cycle']->recordProgress($f['runs'][1]->fresh(), ['good_base_quantity' => '5', 'stage_input_base_quantity' => '5']))->toThrow(DomainException::class);
});

test('eight decimal stage valuation is preserved with audited cumulative journal rounding', function (): void {
    $f = physicalStageFixture('1.00000001');
    physicalStageProgress($f, 0, '6', '12');
    $before = [InventoryTransaction::query()->count(), JournalEntry::query()->count()];
    $transfer = physicalStagePrepare($f, 0, '4');
    expect($transfer->total_cost)->toBe('8.00000008')->and($transfer->booked_amount)->toBe('8.0000')
        ->and($transfer->posting_snapshot['rounding_difference'])->toBe('0.00000008')
        ->and(ProductionStageTransfer::query()->count())->toBe(1)
        ->and([InventoryTransaction::query()->count(), JournalEntry::query()->count()])->toBe($before)
        ->and(app(ProductionCostService::class)->runPosition($f['runs'][0]->fresh())['wip'])->toBe('20.00000020');
    physicalStageActor($f, true);
    app(ProductionStageTransferService::class)->approve($transfer);
    expect(app(ProductionStageTransferService::class)->position($f['runs'][1]->fresh())['incoming'])->toBe('8.00000008')
        ->and(JournalEntry::query()->findOrFail($transfer->fresh()->journal_entry_id)->lines->sum('debit_amount'))->toBe(8.0);
});

test('native paid conversion expenses travel with their original audited stage cost components', function (): void {
    $f = physicalStageFixture();
    physicalStageProgress($f, 0, '6', '12');
    $currency = Currency::query()->where('company_id', $f['company']->id)->where('is_main', true)->sole();
    $parent = Account::query()->where('company_id', $f['company']->id)->where('account_code', '1112')->firstOrFail();
    $bankLedger = Account::query()->create(['company_id' => $f['company']->id,
        'doc_number' => (int) Account::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-STAGE-BANK-'.$f['company']->id, 'account_code' => '11129991',
        'name' => 'SYNTHETIC conversion bank', 'parent_id' => $parent->id, 'level' => $parent->level + 1,
        'account_classification_id' => $parent->account_classification_id, 'account_type' => 'asset',
        'statement_type' => 'financial_position', 'normal_balance' => 'debit', 'is_group' => false, 'is_postable' => true, 'status' => 'active']);
    $bank = BankAccount::query()->create(['company_id' => $f['company']->id,
        'doc_number' => (int) BankAccount::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-STAGE-BANK-'.$f['company']->id, 'account_id' => $bankLedger->id,
        'currency_id' => $currency->id, 'account_name' => 'SYNTHETIC stage bank', 'account_number' => 'SYNTHETIC-991', 'status' => 'active']);
    $account = Account::query()->where('company_id', $f['company']->id)->where('account_code', '523')->firstOrFail();
    $expenses = app(ProductionExpenseRequestService::class);
    $paid = $expenses->pay($expenses->approve($expenses->create($f['runs'][0]->fresh(), ['amount' => '50',
        'currency_id' => $currency->id, 'payment_channel' => 'bank', 'bank_account_id' => $bank->id,
        'expense_account_id' => $account->id, 'reason' => 'SYNTHETIC actual conversion expense'])));
    $before = [InventoryTransaction::query()->count(), JournalEntry::query()->count(), $paid->fresh()->getAttributes()];
    $cost = app(ProductionCostService::class)->runPosition($f['runs'][0]->fresh());
    expect($cost['other_direct_cost'])->toBe('50.00000000')->and($cost['wip'])->toBe('250.00000000');
    $transfer = physicalStagePrepare($f, 0, '4');
    expect($transfer->posting_snapshot['cost_components']['other_direct'])->toBe('33.33333333')
        ->and($transfer->posting_snapshot['cost_components']['material'])->toBe('80.00000000')
        ->and(ProductionStageTransfer::query()->count())->toBe(1)
        ->and([InventoryTransaction::query()->count(), JournalEntry::query()->count(), $paid->fresh()->getAttributes()])->toBe($before)
        ->and(app(ProductionCostService::class)->runPosition($f['runs'][0]->fresh()))->toBe($cost);
    physicalStageActor($f, true);
    app(ProductionStageTransferService::class)->approve($transfer);
    expect($paid->fresh()->getAttributes())->toBe($before[2])
        ->and(app(ProductionStageTransferService::class)->position($f['runs'][1]->fresh())['incoming'])->toBe($transfer->total_cost);
});

test('intermediate loss evidence retains input lineage and requires its cost owner before transfer', function (string $field): void {
    $f = physicalStageFixture();
    physicalStageProgress($f, 0, '6', '12');
    physicalStagePost($f, 0, '4');
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][1]->fresh());
    $run = $f['runs'][1]->fresh();
    $before = [$run->getAttributes(), $run->progressEntries()->count(), InventoryTransaction::query()->count(),
        JournalEntry::query()->count(), app(ProductionStageTransferService::class)->position($run)];
    $f['cycle']->recordProgress($run, ['good_base_quantity' => '1', $field => '1', 'stage_input_base_quantity' => '2',
        'material_evidence' => [['requirement_public_id' => $run->requirements->sole()->public_id, 'measured_quantity' => '2']]]);
    expect($run->fresh()->{$field})->toBe('1.00000000')->and($run->progressEntries()->count())->toBe($before[1] + 1)
        ->and(app(ProductionStageTransferService::class)->position($run->fresh())['used_quantity'])->toBe('2.00000000');
    expect(fn () => physicalStagePrepare($f, 1, '1'))->toThrow(DomainException::class);
})->with(['rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity']);

test('native center linked overhead is allocated to stage transfers without changing its source owner', function (): void {
    $f = physicalStageFixture();
    physicalStageProgress($f, 0, '6', '12');
    $currency = Currency::query()->where('company_id', $f['company']->id)->where('is_main', true)->sole();
    $sourceAccount = Account::query()->where('company_id', $f['company']->id)->where('account_code', '523')->firstOrFail();
    $sourceCenter = CostCenter::query()->create(['company_id' => $f['company']->id,
        'doc_number' => (int) CostCenter::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-STAGE-POOL-'.$f['company']->id, 'cost_center_code' => 'SYNTHETIC-POOL',
        'name' => 'SYNTHETIC stage conversion pool', 'is_group' => false, 'status' => 'active']);
    $sourceCenter->accounts()->sync([$sourceAccount->id]);
    $bankParent = Account::query()->where('company_id', $f['company']->id)->where('account_code', '1112')->firstOrFail();
    $bankLedger = $bankParent->replicate(['id', 'doc_number', 'doc_num', 'account_code']);
    $bankLedger->forceFill(['doc_number' => (int) Account::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-STAGE-POOL-BANK-'.$f['company']->id, 'account_code' => '11129992',
        'name' => 'SYNTHETIC audited conversion bank', 'parent_id' => $bankParent->id, 'level' => $bankParent->level + 1,
        'is_group' => false, 'is_postable' => true, 'is_system' => false])->save();
    app(JournalEntryService::class)->createPostedFromSource(['entry_date' => now()->toDateString(),
        'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id,
        'currency_id' => $currency->id, 'exchange_rate' => '1', 'description' => 'SYNTHETIC audited conversion pool',
        'source_type' => 'synthetic_stage_conversion_pool', 'source_id' => $f['runs'][0]->id,
        'source_doc_num' => $f['runs'][0]->run_number], [
            ['account_id' => $sourceAccount->id, 'cost_center_id' => $sourceCenter->id, 'branch_id' => $f['branch']->id, 'debit_amount' => '30', 'credit_amount' => '0'],
            ['account_id' => $bankLedger->id,
                'branch_id' => $f['branch']->id, 'debit_amount' => '0', 'credit_amount' => '30'],
        ]);
    $allocations = app(OverheadAllocationService::class);
    $rule = $allocations->createRule(['name' => 'SYNTHETIC native stage overhead', 'source_cost_center_id' => $sourceCenter->id,
        'source_account_ids' => [$sourceAccount->id], 'target_cost_center_ids' => [$f['runs'][0]->cost_center_id],
        'basis' => OverheadAllocationRule::BasisDirectMaterialCost, 'fallback_basis' => null,
        'cost_behavior' => OverheadAllocationRule::BehaviorVariable,
        'effective_from' => $f['period']->from_date->toDateString(), 'effective_to' => $f['period']->to_date->toDateString()], $f['company']->id, $f['branch']->id);
    $allocation = $allocations->approve($allocations->preview($rule, $f['period'], $f['branch']->id,
        $f['period']->from_date->toDateString(), $f['period']->to_date->toDateString()))->load('lines');
    $cost = app(ProductionCostService::class)->runPosition($f['runs'][0]->fresh());
    expect($cost['allocated_overhead'])->toBe('30.00000000')->and($cost['wip'])->toBe('230.00000000')
        ->and($allocation->lines->sole()->cost_center_id)->toBe($f['runs'][0]->cost_center_id);
    $before = [InventoryTransaction::query()->count(), JournalEntry::query()->count(), $allocation->getAttributes(), $allocation->lines->map->getAttributes()->all()];
    $transfer = physicalStagePrepare($f, 0, '4');
    expect($transfer->posting_snapshot['cost_components']['overhead'])->toBe('20.00000000')
        ->and(ProductionStageTransfer::query()->count())->toBe(1)
        ->and([InventoryTransaction::query()->count(), JournalEntry::query()->count(), $allocation->fresh()->getAttributes(), $allocation->lines()->get()->map->getAttributes()->all()])->toBe($before)
        ->and(app(ProductionCostService::class)->runPosition($f['runs'][0]->fresh()))->toBe($cost);
    physicalStageActor($f, true);
    app(ProductionStageTransferService::class)->approve($transfer);
    expect($allocation->fresh()->getAttributes())->toBe($before[2])
        ->and($allocation->lines()->get()->map->getAttributes()->all())->toBe($before[3]);
});
