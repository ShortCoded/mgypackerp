<?php

use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\OverheadAllocationService;
use Modules\Accounting\Services\PeriodClosePreflightService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

/** @return array<string, mixed> */
function overheadAllocationFixture(): array
{
    $fixture = salesCycleFixture();
    $sourceCenter = CostCenter::query()->create([
        'company_id' => $fixture['company']->id,
        'doc_number' => 9101,
        'doc_num' => 'CC-OH-SOURCE',
        'cost_center_code' => 'OH-SOURCE',
        'name' => 'Factory Overhead Pool',
        'name_en' => 'Factory Overhead Pool',
        'is_group' => false,
        'status' => 'active',
    ]);
    $targetCenterA = CostCenter::query()->create([
        'company_id' => $fixture['company']->id,
        'doc_number' => 9102,
        'doc_num' => 'CC-OH-A',
        'cost_center_code' => 'OH-A',
        'name' => 'Product A',
        'name_en' => 'Product A',
        'is_group' => false,
        'status' => 'active',
    ]);
    $targetCenterB = CostCenter::query()->create([
        'company_id' => $fixture['company']->id,
        'doc_number' => 9103,
        'doc_num' => 'CC-OH-B',
        'cost_center_code' => 'OH-B',
        'name' => 'Product B',
        'name_en' => 'Product B',
        'is_group' => false,
        'status' => 'active',
    ]);
    $sourceAccount = Account::query()->forCompany($fixture['company']->id)->where('account_code', '523')->firstOrFail();
    $sourceCenter->accounts()->sync([$sourceAccount->id]);

    return [
        ...$fixture,
        'sourceCenter' => $sourceCenter,
        'targetCenterA' => $targetCenterA,
        'targetCenterB' => $targetCenterB,
        'sourceAccount' => $sourceAccount,
    ];
}

function overheadSourceJournal(array $fixture, string $amount, int $sequence = 1): JournalEntry
{
    return app(JournalEntryService::class)->createPostedFromSource([
        'entry_date' => $fixture['period']->from_date->copy()->addDays($sequence)->toDateString(),
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'currency_id' => $fixture['currency']->id,
        'exchange_rate' => 1,
        'description' => 'Eligible factory overhead',
        'source_type' => 'overhead_test_source',
        'source_id' => $sequence,
        'source_doc_num' => 'OH-SOURCE-'.$sequence,
    ], [[
        'account_id' => $fixture['sourceAccount']->id,
        'debit_amount' => $amount,
        'credit_amount' => '0.0000',
        'description' => 'Factory overhead',
        'cost_center_id' => $fixture['sourceCenter']->id,
        'branch_id' => $fixture['branch']->id,
    ], [
        'account_id' => $fixture['cashbox']->account_id,
        'debit_amount' => '0.0000',
        'credit_amount' => $amount,
        'description' => 'Paid factory overhead',
        'branch_id' => $fixture['branch']->id,
    ]]);
}

function overheadProductionRun(array $fixture, CostCenter $costCenter, int $sequence, ?int $machineHours, array $laborHours = []): ProductionRun
{
    $order = ProductionOrder::query()->create([
        'doc_number' => 9200 + $sequence,
        'doc_num' => 'PO-OH-'.$sequence,
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'production_order_date' => $fixture['period']->from_date->copy()->addDays(2)->toDateString(),
        'source_type' => 'make_to_stock',
        'status' => ProductionOrder::StatusCompleted,
    ]);
    $line = ProductionOrderLine::query()->create([
        'production_order_id' => $order->id,
        'line_number' => 1,
        'product_id' => $fixture['finished']->id,
        'unit_id' => $fixture['unit']->id,
        'description' => 'Allocated finished product '.$sequence,
        'quantity' => 100,
        'base_quantity' => 100,
    ]);
    $end = $fixture['period']->from_date->copy()->addDays(10 + $sequence)->endOfDay();

    return ProductionRun::query()->create([
        'run_number' => 'RUN-OH-'.$sequence,
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'production_order_id' => $order->id,
        'production_order_line_id' => $line->id,
        'product_id' => $fixture['finished']->id,
        'unit_id' => $fixture['unit']->id,
        'cost_center_id' => $costCenter->id,
        'planned_quantity' => 100,
        'planned_base_quantity' => 100,
        'good_base_quantity' => 100,
        'received_base_quantity' => 0,
        'planned_start_at' => $end->copy()->subHours($machineHours ?? 1),
        'planned_end_at' => $end,
        'actual_start_at' => $machineHours === null ? null : $end->copy()->subHours($machineHours),
        'actual_end_at' => $end,
        'labor_details' => collect($laborHours)->map(fn (int|float $hours, int $index): array => [
            'employee_id' => $index + 1,
            'actual_hours' => (string) $hours,
        ])->values()->all(),
        'status' => ProductionRun::StatusCompleted,
    ]);
}

function overheadMaterialCost(array $fixture, ProductionRun $run, string $amount, int $sequence): void
{
    $document = InventoryDocument::query()->create([
        'doc_number' => 9300 + $sequence,
        'doc_num' => 'INV-OH-'.$sequence,
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'branch_store_id' => $fixture['store']->id,
        'document_type' => InventoryDocument::TypeMaterialIssue,
        'document_date' => $fixture['period']->from_date->copy()->addDays(5)->toDateString(),
        'status' => InventoryDocument::StatusPosted,
        'production_order_id' => $run->production_order_id,
        'production_run_id' => $run->id,
    ]);
    InventoryDocumentLine::query()->create([
        'inventory_document_id' => $document->id,
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'line_number' => 1,
        'product_id' => $fixture['raw']->id,
        'unit_id' => $fixture['unit']->id,
        'quantity' => 1,
        'base_quantity' => 1,
        'production_order_id' => $run->production_order_id,
        'production_run_id' => $run->id,
        'unit_cost' => $amount,
        'total_cost' => $amount,
    ]);
}

function overheadRule(array $fixture, array $overrides = []): OverheadAllocationRule
{
    return app(OverheadAllocationService::class)->createRule([
        'name' => 'Factory overhead allocation',
        'source_cost_center_id' => $fixture['sourceCenter']->id,
        'source_account_ids' => [$fixture['sourceAccount']->id],
        'target_cost_center_ids' => [$fixture['targetCenterA']->id, $fixture['targetCenterB']->id],
        'basis' => OverheadAllocationRule::BasisMachineHours,
        'fallback_basis' => OverheadAllocationRule::BasisDirectMaterialCost,
        'cost_behavior' => OverheadAllocationRule::BehaviorVariable,
        'normal_capacity_hours' => null,
        'effective_from' => $fixture['period']->from_date->toDateString(),
        'effective_to' => $fixture['period']->to_date->toDateString(),
        ...$overrides,
    ], $fixture['company']->id, $fixture['branch']->id);
}

test('variable overhead allocates 30000 by recorded 60 40 machine hours and posts once', function (): void {
    $fixture = overheadAllocationFixture();
    overheadSourceJournal($fixture, '30000');
    $runA = overheadProductionRun($fixture, $fixture['targetCenterA'], 1, 60);
    $runB = overheadProductionRun($fixture, $fixture['targetCenterB'], 2, 40);
    $rule = overheadRule($fixture);
    $service = app(OverheadAllocationService::class);

    $preview = $service->preview(
        $rule,
        $fixture['period'],
        $fixture['branch']->id,
        $fixture['period']->from_date->toDateString(),
        $fixture['period']->to_date->toDateString(),
    );
    $lines = $preview->lines->keyBy('production_run_id');

    expect((string) $preview->eligible_cost)->toBe('30000.0000')
        ->and((string) $preview->allocated_cost)->toBe('30000.0000')
        ->and((string) $preview->unallocated_cost)->toBe('0.0000')
        ->and((string) $lines[$runA->id]->allocated_amount)->toBe('18000.0000')
        ->and((string) $lines[$runB->id]->allocated_amount)->toBe('12000.0000')
        ->and((string) $lines[$runA->id]->allocation_percent)->toBe('60.00000000')
        ->and((string) $lines[$runB->id]->allocation_percent)->toBe('40.00000000');

    $posted = $service->approve($preview);
    $same = $service->approve($posted);
    $journal = $posted->journalEntry()->with('lines')->firstOrFail();

    expect($posted->status)->toBe(OverheadAllocationRun::StatusPosted)
        ->and($same->journal_entry_id)->toBe($posted->journal_entry_id)
        ->and(JournalEntry::query()->where('source_type', 'overhead_allocation')->where('source_id', $posted->id)->count())->toBe(1)
        ->and(number_format((float) $journal->lines->sum('debit_amount'), 4, '.', ''))->toBe('30000.0000')
        ->and(number_format((float) $journal->lines->sum('credit_amount'), 4, '.', ''))->toBe('30000.0000')
        ->and(app(ProductionCostService::class)->runPosition($runA)['allocated_overhead'])->toBe('18000.00000000')
        ->and(app(ProductionCostService::class)->runPosition($runB)['allocated_overhead'])->toBe('12000.00000000');
});

test('an incomplete hours group falls back as a whole to net direct material cost', function (): void {
    $fixture = overheadAllocationFixture();
    overheadSourceJournal($fixture, '30000');
    $runA = overheadProductionRun($fixture, $fixture['targetCenterA'], 1, null);
    $runB = overheadProductionRun($fixture, $fixture['targetCenterB'], 2, 40);
    overheadMaterialCost($fixture, $runA, '120000', 1);
    overheadMaterialCost($fixture, $runB, '60000', 2);
    $rule = overheadRule($fixture);

    $preview = app(OverheadAllocationService::class)->preview(
        $rule,
        $fixture['period'],
        $fixture['branch']->id,
        $fixture['period']->from_date->toDateString(),
        $fixture['period']->to_date->toDateString(),
    );
    $lines = $preview->lines->keyBy('production_run_id');

    expect($preview->basis_used)->toBe(OverheadAllocationRule::BasisDirectMaterialCost)
        ->and($preview->fallback_reason)->toBe(__('overhead_allocations.fallback.missing_hours'))
        ->and((string) $lines[$runA->id]->allocated_amount)->toBe('20000.0000')
        ->and((string) $lines[$runB->id]->allocated_amount)->toBe('10000.0000');
});

test('fixed overhead leaves idle capacity as period cost instead of inflating product cost', function (): void {
    $fixture = overheadAllocationFixture();
    overheadSourceJournal($fixture, '30000');
    $runA = overheadProductionRun($fixture, $fixture['targetCenterA'], 1, 60);
    $runB = overheadProductionRun($fixture, $fixture['targetCenterB'], 2, 40);
    $rule = overheadRule($fixture, [
        'cost_behavior' => OverheadAllocationRule::BehaviorFixed,
        'normal_capacity_hours' => 200,
        'fallback_basis' => null,
    ]);

    $preview = app(OverheadAllocationService::class)->preview(
        $rule,
        $fixture['period'],
        $fixture['branch']->id,
        $fixture['period']->from_date->toDateString(),
        $fixture['period']->to_date->toDateString(),
    );
    $lines = $preview->lines->keyBy('production_run_id');

    expect((string) $preview->actual_capacity)->toBe('100.00000000')
        ->and((string) $preview->utilization_percent)->toBe('50.0000')
        ->and((string) $preview->allocatable_cost)->toBe('15000.0000')
        ->and((string) $preview->allocated_cost)->toBe('15000.0000')
        ->and((string) $preview->unallocated_cost)->toBe('15000.0000')
        ->and((string) $lines[$runA->id]->allocated_amount)->toBe('9000.0000')
        ->and((string) $lines[$runB->id]->allocated_amount)->toBe('6000.0000');
});

test('approval rejects a stale preview when recorded production hours change', function (): void {
    $fixture = overheadAllocationFixture();
    overheadSourceJournal($fixture, '30000');
    $runA = overheadProductionRun($fixture, $fixture['targetCenterA'], 1, 60);
    overheadProductionRun($fixture, $fixture['targetCenterB'], 2, 40);
    $rule = overheadRule($fixture);
    $service = app(OverheadAllocationService::class);
    $preview = $service->preview(
        $rule,
        $fixture['period'],
        $fixture['branch']->id,
        $fixture['period']->from_date->toDateString(),
        $fixture['period']->to_date->toDateString(),
    );
    $runA->update(['actual_start_at' => $runA->actual_start_at->copy()->subHour()]);

    expect(fn () => $service->approve($preview))
        ->toThrow(DomainException::class, __('overhead_allocations.messages.stale_preview'))
        ->and($preview->refresh()->status)->toBe(OverheadAllocationRun::StatusDraft)
        ->and(JournalEntry::query()->where('source_type', 'overhead_allocation')->exists())->toBeFalse();
});

test('period close preflight exposes draft allocation previews as a remediable blocker', function (): void {
    $fixture = overheadAllocationFixture();
    overheadSourceJournal($fixture, '30000');
    overheadProductionRun($fixture, $fixture['targetCenterA'], 1, 60);
    overheadProductionRun($fixture, $fixture['targetCenterB'], 2, 40);
    $preview = app(OverheadAllocationService::class)->preview(
        overheadRule($fixture),
        $fixture['period'],
        $fixture['branch']->id,
        $fixture['period']->from_date->toDateString(),
        $fixture['period']->to_date->toDateString(),
    );
    $check = collect(app(PeriodClosePreflightService::class)->checks($fixture['period']))
        ->firstWhere('key', 'unposted_financial_documents');
    $detail = collect($check['details'])->firstWhere('permission', 'costing.overhead_allocation_run.view');

    expect($preview->status)->toBe(OverheadAllocationRun::StatusDraft)
        ->and($check['status'])->toBe('blocker')
        ->and($detail['count'])->toBe(1)
        ->and($detail['url'])->toBe(route('admin.costing.overhead-allocation-run.index'));
});

test('posted allocation reverses with an audit journal and releases the source for a new preview', function (): void {
    $fixture = overheadAllocationFixture();
    overheadSourceJournal($fixture, '30000');
    $runA = overheadProductionRun($fixture, $fixture['targetCenterA'], 1, 60);
    overheadProductionRun($fixture, $fixture['targetCenterB'], 2, 40);
    $rule = overheadRule($fixture);
    $service = app(OverheadAllocationService::class);
    $preview = $service->preview(
        $rule,
        $fixture['period'],
        $fixture['branch']->id,
        $fixture['period']->from_date->toDateString(),
        $fixture['period']->to_date->toDateString(),
    );
    $posted = $service->approve($preview);
    $reversed = $service->reverse($posted, 'Correct allocation basis');
    $replacement = $service->preview(
        $rule,
        $fixture['period'],
        $fixture['branch']->id,
        $fixture['period']->from_date->toDateString(),
        $fixture['period']->to_date->toDateString(),
    );

    expect($reversed->status)->toBe(OverheadAllocationRun::StatusReversed)
        ->and($reversed->reversal_journal_entry_id)->not->toBeNull()
        ->and($reversed->journalEntry->refresh()->reversed_entry_id)->toBe($reversed->reversal_journal_entry_id)
        ->and(app(ProductionCostService::class)->runPosition($runA)['allocated_overhead'])->toBe('0.00000000')
        ->and($replacement->id)->not->toBe($reversed->id)
        ->and($replacement->status)->toBe(OverheadAllocationRun::StatusDraft)
        ->and((string) $replacement->eligible_cost)->toBe('30000.0000');
});

test('canonical allocation screens render with scoped permissions instead of the generic shell', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $fixture = overheadAllocationFixture();
    foreach (['costing.overhead_allocation_rules.view', 'costing.overhead_allocation_rules.create', 'costing.overhead_allocation_run.view', 'costing.overhead_allocation_run.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo([
        'costing.overhead_allocation_rules.view',
        'costing.overhead_allocation_rules.create',
        'costing.overhead_allocation_run.view',
        'costing.overhead_allocation_run.create',
    ]);

    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.costing.overhead-allocation-rules.index'))
        ->assertOk()
        ->assertSee('Overhead Allocation Rules')
        ->assertSee('New allocation rule')
        ->assertDontSee('New UI Shell');

    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.costing.overhead-allocation-run.index'))
        ->assertOk()
        ->assertSee('Overhead Allocation Runs')
        ->assertSee('Preview allocation')
        ->assertDontSee('New UI Shell');
});
