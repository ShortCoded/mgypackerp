<?php

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Services\ProductionCycleService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';

function anyStageMaterialFixture(): array
{
    test()->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $f = manufacturingInventoryFixture('-ANY-STAGE-'.Str::random(6), isolatedCompany: true);
    $stages = collect(['Review', 'Manufacture', 'Pack'])->map(fn (string $name, int $index) => ProductionStage::query()->create([
        'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'code' => 'ANY-'.$index, 'name' => 'SYNTHETIC '.$name,
    ]));
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'order_stage_public_ids' => $stages->pluck('public_id')->all()],
        [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '100']]);
    $order = $cycle->releaseOrder($order);
    $line = $order->lines->sole();
    $runs = $order->orderStageSnapshots->sortBy('sequence')->values()->map(fn ($stage, int $index) => $cycle->createRun($line, [
        'production_order_stage_snapshot_id' => $stage->id, 'planned_quantity' => '100',
        'planned_start_at' => now()->addHours($index + 1), 'planned_end_at' => now()->addHours($index + 2),
    ]));
    $f['branch']->update(['type' => Branch::TypeFactory]);
    foreach (['production.material_requests.create', 'production.material_requests.view', 'production.material_requests.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    test()->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));

    return [...$f, ...compact('cycle', 'order', 'line', 'runs'), 'componentId' => $line->bom_snapshot['components'][0]['product_component_id']];
}
