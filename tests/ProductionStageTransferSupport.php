<?php

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\ProductionStageTransfer;
use Modules\Production\Models\QualityInspectionType;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionStageTransferService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';

function physicalStageFixture(string $rawCost = '10', string $startAt = '2026-09-29 12:00:00'): array
{
    test()->travelTo(Carbon::parse($startAt));
    $f = manufacturingInventoryFixture('-PHYSICAL-'.Str::random(6), isolatedCompany: true);
    if (now()->month > 9) {
        $f['period']->update(['to_date' => now()->endOfYear()->toDateString()]);
    }
    $f['store'] = BranchStore::query()->create(['branch_id' => $f['branch']->id, 'name' => 'SYNTHETIC physical stage store']);
    $stages = collect(['Form', 'Finish', 'Pack'])->map(fn (string $name, int $i) => ProductionStage::query()->create([
        'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'code' => 'SYNTHETIC-PHYSICAL-'.$i, 'name' => 'SYNTHETIC '.$name,
    ]));
    $materials = collect([$f['raw']]);
    foreach ([1, 2] as $i) {
        $material = $f['raw']->replicate(['public_id', 'doc_num', 'doc_number']);
        $material->forceFill(['doc_number' => (int) Product::withTrashed()->max('doc_number') + 1, 'doc_num' => 'SYNTHETIC-PHYSICAL-MATERIAL-'.$f['company']->id.'-'.$i, 'name' => 'SYNTHETIC stage material '.$i])->save();
        $materials->push($material);
        ProductComponent::query()->create(['company_id' => $f['company']->id, 'product_id' => $f['finished']->id,
            'component_product_id' => $material->id, 'unit_id' => $f['unit']->id, 'production_stage_id' => $stages[$i]->id,
            'calculation_method' => ProductComponent::CalculationDirect, 'quantity' => '1']);
    }
    ProductComponent::query()->where('product_id', $f['finished']->id)->where('component_product_id', $f['raw']->id)->update(['production_stage_id' => $stages[0]->id]);
    foreach ([$rawCost, '5', '2'] as $i => $cost) {
        app(InventoryMovementService::class)->createAndPost(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
            'financial_period_id' => $f['period']->id, 'branch_store_id' => $f['store']->id, 'document_date' => now()->toDateString(),
            'document_type' => InventoryDocument::TypeAdjustmentIn], [['product_id' => $materials[$i]->id, 'quantity' => $i === 0 ? '20' : '10', 'unit_cost' => $cost]]);
    }
    $permissions = ['production.runs.view', 'production.runs.account_materials', 'production.runs.correct_approve', 'production.runs.progress',
        'production.runs.setup', 'production.runs.receive', 'production.runs.cancel', 'production.quality.release_normal'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $f['user']->givePermissionTo($permissions);
    $approver = closureSyntheticUser();
    $approver->givePermissionTo($permissions);
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->releaseOrder($cycle->createMakeToStockOrder(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'order_stage_public_ids' => $stages->pluck('public_id')->all()],
        [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '10']]));
    $line = $order->lines->sole();
    $runs = $order->orderStageSnapshots->sortBy('sequence')->values()->map(function ($stage, int $i) use ($f, $cycle, $line): ProductionRun {
        $number = (int) CostCenter::withTrashed()->max('doc_number') + 1;
        $center = CostCenter::query()->create(['company_id' => $f['company']->id, 'doc_number' => $number,
            'doc_num' => 'SYNTHETIC-PHYSICAL-CENTER-'.$number, 'cost_center_code' => 'SYNTHETIC-STAGE-'.$i,
            'name' => 'SYNTHETIC stage cost center '.$i, 'status' => 'active', 'is_group' => false]);
        $run = $cycle->createRun($line, ['production_order_stage_snapshot_id' => $stage->id, 'planned_quantity' => '10',
            'planned_start_at' => now()->addHours($i), 'planned_end_at' => now()->addHours($i + 1),
            'production_machine_id' => $f['machine']->id, 'production_mold_id' => $f['mold']->id]);
        $run->update(['cost_center_id' => $center->id]);
        $run = $cycle->enableOutputEvidence($run, $run->requirements->map(fn ($r): array => ['requirement_public_id' => $r->public_id, 'basis' => 'measured_material'])->all(), 'physical_route');
        $cycle->reserveRun($run, $f['store']->id);
        $cycle->issueMaterials($run->fresh(), $f['store']->id);

        return $cycle->completeSetup($cycle->startSetup($run->fresh()));
    });
    $qualityType = QualityInspectionType::query()->create(['company_id' => $f['company']->id, 'code' => 'SYNTHETIC-PHYSICAL-RELEASE',
        'name' => 'SYNTHETIC stage batch release', 'is_final_production' => true, 'is_active' => true]);
    $runs[0] = $cycle->startRun($runs[0]);
    $f = [...$f, ...compact('approver', 'cycle', 'order', 'line', 'runs', 'materials', 'qualityType')];
    physicalStageActor($f);

    return $f;
}

function physicalStageActor(array $f, bool $reviewer = false): void
{
    $user = $reviewer ? $f['approver'] : $f['user'];
    test()->actingAs($user)->withSession(manufacturingIntegritySession($f));
    request()->setUserResolver(fn () => $user);
}

function physicalStageProgress(array $f, int $index, string $quantity, string $rawQuantity): void
{
    physicalStageActor($f);
    $run = $f['runs'][$index]->fresh();
    $f['cycle']->recordProgress($run, ['good_base_quantity' => $quantity, 'stage_input_base_quantity' => $index === 0 ? null : $quantity,
        'material_evidence' => [['requirement_public_id' => $run->requirements->sole()->public_id, 'measured_quantity' => $rawQuantity]]]);
    $f['cycle']->recordInspection($run->fresh(), ['quality_inspection_type_id' => $f['qualityType']->id, 'result' => 'passed',
        'disposition' => 'release', 'affected_base_quantity' => $quantity]);
}

function physicalStagePrepare(array $f, int $index, string $quantity): ProductionStageTransfer
{
    physicalStageActor($f);
    $service = app(ProductionStageTransferService::class);
    $source = $f['runs'][$index]->fresh();
    $target = $f['runs'][$index + 1]->fresh();

    return $service->prepare($source, $target, $quantity, 'SYNTHETIC partial stage transfer', 'SYNTHETIC signed delivery record',
        $service->preview($source, $target)['fingerprint'], (string) Str::uuid());
}

function physicalStagePost(array $f, int $index, string $quantity): ProductionStageTransfer
{
    $owner = physicalStagePrepare($f, $index, $quantity);
    physicalStageActor($f, true);

    return app(ProductionStageTransferService::class)->approve($owner);
}
