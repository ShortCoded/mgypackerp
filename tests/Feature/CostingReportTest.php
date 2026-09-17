<?php

use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Accounting\Services\CostingReportService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

test('costing report shells render posted production costs and export the same source', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $fixture = salesCycleFixture();
    foreach (['view', 'export', 'print'] as $action) {
        Permission::findOrCreate("reports.costing.product_cost.{$action}", 'web');
    }
    $fixture['user']->givePermissionTo([
        'reports.costing.product_cost.view',
        'reports.costing.product_cost.export',
        'reports.costing.product_cost.print',
    ]);

    $order = ProductionOrder::query()->create([
        'doc_number' => 99101,
        'doc_num' => 'PO-COST-99101',
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'production_order_date' => now()->toDateString(),
        'source_type' => 'make_to_stock',
        'status' => ProductionOrder::StatusCompleted,
    ]);
    $orderLine = ProductionOrderLine::query()->create([
        'production_order_id' => $order->id,
        'line_number' => 1,
        'product_id' => $fixture['finished']->id,
        'unit_id' => $fixture['unit']->id,
        'description' => 'Costed finished product',
        'quantity' => 10,
        'base_quantity' => 10,
    ]);
    $run = ProductionRun::query()->create([
        'run_number' => 'RUN-COST-99101',
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'production_order_id' => $order->id,
        'production_order_line_id' => $orderLine->id,
        'product_id' => $fixture['finished']->id,
        'unit_id' => $fixture['unit']->id,
        'cost_center_id' => CostCenter::query()->create([
            'company_id' => $fixture['company']->id,
            'doc_number' => 99101,
            'doc_num' => 'CC-COST-99101',
            'cost_center_code' => 'COST-99101',
            'name' => 'Costing target',
            'is_group' => false,
            'status' => 'active',
        ])->id,
        'planned_quantity' => 10,
        'planned_base_quantity' => 10,
        'good_base_quantity' => 10,
        'received_base_quantity' => 10,
        'planned_start_at' => now()->subHour(),
        'planned_end_at' => now(),
        'status' => ProductionRun::StatusCompleted,
    ]);

    foreach ([
        [InventoryDocument::TypeMaterialIssue, 100],
        [InventoryDocument::TypeMaterialReturn, 20],
        [InventoryDocument::TypeProductionWaste, 10],
        [InventoryDocument::TypeProductionReceipt, 70],
    ] as $offset => [$type, $totalCost]) {
        $document = InventoryDocument::query()->create([
            'doc_number' => 99110 + $offset,
            'doc_num' => 'INV-COST-'.(99110 + $offset),
            'company_id' => $fixture['company']->id,
            'financial_period_id' => $fixture['period']->id,
            'branch_id' => $fixture['branch']->id,
            'branch_store_id' => $fixture['store']->id,
            'document_type' => $type,
            'document_date' => now()->toDateString(),
            'status' => InventoryDocument::StatusPosted,
            'production_order_id' => $order->id,
            'production_run_id' => $run->id,
        ]);
        InventoryDocumentLine::query()->create([
            'inventory_document_id' => $document->id,
            'company_id' => $fixture['company']->id,
            'financial_period_id' => $fixture['period']->id,
            'line_number' => 1,
            'product_id' => $fixture['finished']->id,
            'unit_id' => $fixture['unit']->id,
            'quantity' => 10,
            'base_quantity' => 10,
            'production_order_id' => $order->id,
            'production_run_id' => $run->id,
            'unit_cost' => $totalCost / 10,
            'total_cost' => $totalCost,
        ]);
    }

    $sourceCenter = CostCenter::query()->create([
        'company_id' => $fixture['company']->id,
        'doc_number' => 99102,
        'doc_num' => 'CC-COST-99102',
        'cost_center_code' => 'COST-POOL',
        'name' => 'Costing source pool',
        'is_group' => false,
        'status' => 'active',
    ]);
    $sourceAccount = Account::query()->forCompany($fixture['company']->id)->eligibleForDirectPosting()->firstOrFail();
    $rule = OverheadAllocationRule::query()->create([
        'company_id' => $fixture['company']->id,
        'branch_id' => $fixture['branch']->id,
        'doc_number' => 99101,
        'doc_num' => 'OHR-COST-99101',
        'name' => 'Costing report overhead',
        'source_cost_center_id' => $sourceCenter->id,
        'source_account_ids' => [$sourceAccount->id],
        'target_cost_center_ids' => [$run->cost_center_id],
        'basis' => OverheadAllocationRule::BasisMachineHours,
        'cost_behavior' => OverheadAllocationRule::BehaviorFixed,
        'normal_capacity_hours' => '20.00000000',
        'effective_from' => $fixture['period']->from_date,
        'status' => 'active',
    ]);
    $allocation = OverheadAllocationRun::query()->create([
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'rule_id' => $rule->id,
        'doc_number' => 99101,
        'doc_num' => 'OHA-COST-99101',
        'from_date' => $fixture['period']->from_date,
        'to_date' => $fixture['period']->to_date,
        'status' => OverheadAllocationRun::StatusPosted,
        'basis_used' => OverheadAllocationRule::BasisMachineHours,
        'eligible_cost' => '50.0000',
        'allocatable_cost' => '30.0000',
        'allocated_cost' => '30.0000',
        'unallocated_cost' => '20.0000',
        'actual_capacity' => '12.00000000',
        'utilization_percent' => '60.0000',
        'input_fingerprint' => str_repeat('a', 64),
        'idempotency_key' => str_repeat('b', 64),
        'policy_snapshot' => [
            'unused_capacity_cost' => '20.0000',
            'unused_capacity_reason' => 'below_normal_capacity',
        ],
    ]);
    $allocation->lines()->create([
        'production_run_id' => $run->id,
        'cost_center_id' => $run->cost_center_id,
        'machine_hours' => '12.00000000',
        'direct_material_cost' => '70.0000',
        'basis_value' => '12.00000000',
        'allocation_percent' => '100.00000000',
        'allocated_amount' => '30.0000',
    ]);

    $session = salesCycleSession($fixture);
    $route = route('admin.reports.costing.product-cost.index');
    $this->actingAs($fixture['user'])->withSession($session)->get($route)
        ->assertOk()
        ->assertSee('Product Cost')
        ->assertSee($fixture['finished']->name)
        ->assertSee('70');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.costing.product-cost.index', ['type' => CostingReportService::Profitability]))
        ->assertOk()
        ->assertSee(__('costing_reports.types.product_cost.title'))
        ->assertSee('value="product_cost" selected', false)
        ->assertDontSee('value="profitability" selected', false);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.costing.product-cost.data', ['draw' => 4]))
        ->assertOk()
        ->assertJsonPath('draw', 4)
        ->assertJsonPath('recordsFiltered', 1)
        ->assertJsonPath('data.0.recognized_cost', '70.00000000')
        ->assertJsonPath('data.0.allocated_overhead', '30.00000000')
        ->assertJsonPath('data.0.actual_cost', '100.00000000')
        ->assertJsonPath('data.0.wip', '30.00000000');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.costing.product-cost.data', ['draw' => 5, 'type' => 'allocation_analysis']))
        ->assertOk()
        ->assertJsonPath('data.0.product', $fixture['finished']->doc_num.' / '.$fixture['finished']->name)
        ->assertJsonMissing(['allocation_run' => $allocation->doc_num]);

    foreach (['work_in_progress', 'finished_goods_cost', 'allocation_analysis'] as $type) {
        Permission::findOrCreate("reports.costing.{$type}.view", 'web');
    }
    $fixture['user']->givePermissionTo([
        'reports.costing.work_in_progress.view',
        'reports.costing.finished_goods_cost.view',
        'reports.costing.allocation_analysis.view',
    ]);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.costing.work-in-progress.data', ['draw' => 6]))
        ->assertOk()
        ->assertJsonPath('data.0.wip', '30.00000000');
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.costing.finished-goods-cost.data', ['draw' => 7]))
        ->assertOk()
        ->assertJsonPath('data.0.recognized_cost', '70.00000000');
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.costing.allocation-analysis.data', ['draw' => 8]))
        ->assertOk()
        ->assertJsonPath('data.0.allocation_run', $allocation->doc_num)
        ->assertJsonPath('data.0.unused_capacity_cost', '20.0000');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.accounting.reports.costing.export.excel', ['type' => 'product_cost']))
        ->assertOk()
        ->assertHeader('content-disposition');
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.accounting.reports.costing.export.pdf', ['type' => 'product_cost']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $fixture['user']->revokePermissionTo([
        'reports.costing.product_cost.export',
        'reports.costing.product_cost.print',
    ]);
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.accounting.reports.costing.export.excel', ['type' => 'product_cost']))
        ->assertForbidden();
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.accounting.reports.costing.export.pdf', ['type' => 'product_cost']))
        ->assertForbidden();
});

test('profitability keeps profit and margin unavailable when recognized cost is missing', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Uncosted finished product',
            'quantity' => '1',
            'unit_price' => '100',
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        'payment_schedules' => [[
            'title' => 'Uncosted sale',
            'amount' => '100',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(),
        'quantity' => '1',
    ]], [[
        'due_date' => now()->toDateString(),
        'amount' => '100',
    ]]));

    $productionOrder = ProductionOrder::query()->create([
        'doc_number' => 99201,
        'doc_num' => 'PO-COST-99201',
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'sales_order_id' => $order->id,
        'production_order_date' => now()->toDateString(),
        'source_type' => 'make_to_order',
        'status' => ProductionOrder::StatusCompleted,
    ]);
    $productionLine = ProductionOrderLine::query()->create([
        'production_order_id' => $productionOrder->id,
        'line_number' => 1,
        'sales_order_line_id' => $order->lines->sole()->getKey(),
        'product_id' => $fixture['finished']->id,
        'unit_id' => $fixture['unit']->id,
        'description' => 'Uncosted finished product',
        'quantity' => 1,
        'base_quantity' => 1,
    ]);
    $run = ProductionRun::query()->create([
        'run_number' => 'RUN-COST-99201',
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'production_order_id' => $productionOrder->id,
        'production_order_line_id' => $productionLine->id,
        'product_id' => $fixture['finished']->id,
        'unit_id' => $fixture['unit']->id,
        'planned_quantity' => 1,
        'planned_base_quantity' => 1,
        'good_base_quantity' => 1,
        'received_base_quantity' => 0,
        'planned_start_at' => now()->subHour(),
        'planned_end_at' => now(),
        'status' => ProductionRun::StatusCompleted,
    ]);
    $materialIssue = InventoryDocument::query()->create([
        'doc_number' => 99201,
        'doc_num' => 'INV-COST-99201',
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'branch_store_id' => $fixture['store']->id,
        'document_type' => InventoryDocument::TypeMaterialIssue,
        'document_date' => now()->toDateString(),
        'status' => InventoryDocument::StatusPosted,
        'production_order_id' => $productionOrder->id,
        'production_run_id' => $run->id,
    ]);
    InventoryDocumentLine::query()->create([
        'inventory_document_id' => $materialIssue->id,
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'line_number' => 1,
        'product_id' => $fixture['raw']->id,
        'unit_id' => $fixture['unit']->id,
        'quantity' => 1,
        'base_quantity' => 1,
        'production_order_id' => $productionOrder->id,
        'production_run_id' => $run->id,
        'unit_cost' => 60,
        'total_cost' => 60,
    ]);

    $session = salesCycleSession($fixture);
    $this->withSession($session);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put($session);

    $report = app(CostingReportService::class)->report(['type' => CostingReportService::Profitability]);
    $row = $report['rows']->sole();

    expect($row['revenue'])->toBe('100')
        ->and($row['_cost_complete'])->toBeFalse()
        ->and($row['recognized_cost'])->toBe(__('costing_reports.values.unavailable'))
        ->and($row['gross_profit'])->toBe(__('costing_reports.values.unavailable'))
        ->and($row['margin_percent'])->toBe(__('costing_reports.values.unavailable'))
        ->and($report['totals']['gross_profit'])->toBe(__('costing_reports.values.unavailable'))
        ->and($report['notices'])->toContain(__('costing_reports.notices.incomplete_profitability', ['count' => 1]));
});
