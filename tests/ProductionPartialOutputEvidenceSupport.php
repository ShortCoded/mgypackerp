<?php

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Core\Models\BranchStore;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\QualityInspectionType;
use Modules\Production\Services\ProductionCycleService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';

function partialOutputFixture(string $basis = 'output_components', bool $factoryWorkflow = false): array
{
    test()->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $fixture = manufacturingInventoryFixture('-PARTIAL-'.Str::random(6), isolatedCompany: true);
    $fixture['store'] = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC partial output evidence']);
    foreach (['production.runs.view', 'production.runs.progress', 'production.runs.account_materials', 'production.runs.receive', 'production.runs.setup', 'production.quality.release_normal'] as $name) {
        Permission::findOrCreate($name, 'web');
        $fixture['user']->givePermissionTo($name);
    }
    app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'financial_period_id' => $fixture['period']->id, 'branch_store_id' => $fixture['store']->id,
        'document_date' => now()->toDateString(), 'document_type' => InventoryDocument::TypeAdjustmentIn,
    ], [['product_id' => $fixture['raw']->id, 'quantity' => '30', 'unit_cost' => '10']]);
    if ($factoryWorkflow) {
        Permission::findOrCreate('production.orders.release', 'web');
        $fixture['user']->givePermissionTo('production.orders.release');
        $stages = collect(['SYNTHETIC review', 'SYNTHETIC operation', 'SYNTHETIC quality', 'SYNTHETIC receipt'])->map(fn (string $name, int $index) => ProductionStage::query()->create([
            'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'code' => 'SYNTHETIC-PARTIAL-'.$index, 'name' => $name,
        ]));
        $cycle = app(ProductionCycleService::class);
        $order = $cycle->createMakeToStockOrder(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
            'financial_period_id' => $fixture['period']->id, 'order_stage_public_ids' => $stages->pluck('public_id')->all()],
            [['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '10']]);
        $order = $cycle->releaseOrder($order);
        $orderLine = $order->lines->sole();
        $run = $cycle->createRun($orderLine, ['planned_quantity' => '10', 'planned_start_at' => now(), 'planned_end_at' => now()->addHour(),
            'production_machine_id' => $fixture['machine']->id, 'production_mold_id' => $fixture['mold']->id,
            'production_order_stage_snapshot_id' => $order->orderStageSnapshots->sortBy('sequence')->first()->id]);
        $fixture = [...$fixture, ...compact('cycle', 'order', 'orderLine', 'run')];
    } else {
        $fixture = [...$fixture, ...manufacturingIntegrityRun($fixture, '10')];
    }
    $requirement = $fixture['run']->requirements->sole();
    $fixture['cycle']->reserveRun($fixture['run'], $fixture['store']->id);
    $fixture['cycle']->issueMaterials($fixture['run']->fresh(), $fixture['store']->id);
    $type = QualityInspectionType::query()->create(['company_id' => $fixture['company']->id, 'code' => 'SYNTHETIC-PARTIAL', 'name' => 'SYNTHETIC final quantity inspection', 'is_final_production' => true, 'is_active' => true]);
    $stageRoles = $factoryWorkflow ? $fixture['order']->orderStageSnapshots->sortBy('sequence')->values()->map(fn ($stage, int $index): array => ['stage_public_id' => $stage->public_id, 'role' => ['checklist', 'manufacturing', 'quality', 'receipt'][$index], 'confirmation_reason' => $index === 0 ? 'SYNTHETIC explicit review of order requirements' : null])->all() : [];
    $run = $fixture['cycle']->enableOutputEvidence($fixture['run']->fresh(), [['requirement_public_id' => $requirement->public_id, 'basis' => $basis]], $factoryWorkflow ? 'factory_workflow' : 'physical_route', $stageRoles);
    $run = $fixture['cycle']->startRun($fixture['cycle']->completeSetup($fixture['cycle']->startSetup($run)));

    test()->actingAs($fixture['user'])->withSession(manufacturingIntegritySession($fixture));

    return [...$fixture, 'run' => $run, 'requirement' => $requirement, 'qualityType' => $type];
}

function partialOutputApprove(array $fixture, string $quantity, ?string $accepted = null): void
{
    $fixture['cycle']->recordInspection($fixture['run']->fresh(), ['quality_inspection_type_id' => $fixture['qualityType']->id,
        'result' => 'passed', 'disposition' => 'release', 'affected_base_quantity' => $quantity, 'accepted_base_quantity' => $accepted]);
}
