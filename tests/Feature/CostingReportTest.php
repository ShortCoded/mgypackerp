<?php

use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionRun;
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

    $session = salesCycleSession($fixture);
    $route = route('admin.reports.costing.product-cost.index');
    $this->actingAs($fixture['user'])->withSession($session)->get($route)
        ->assertOk()
        ->assertSee('Product Cost')
        ->assertSee($fixture['finished']->name)
        ->assertSee('70');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.costing.product-cost.data', ['draw' => 4]))
        ->assertOk()
        ->assertJsonPath('draw', 4)
        ->assertJsonPath('recordsFiltered', 1)
        ->assertJsonPath('data.0.recognized_cost', '70.00000000')
        ->assertJsonPath('data.0.wip', '0.00000000');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.accounting.reports.costing.export.excel', ['type' => 'product_cost']))
        ->assertOk()
        ->assertHeader('content-disposition');
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.accounting.reports.costing.export.pdf', ['type' => 'product_cost']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
