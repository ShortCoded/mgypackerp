<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\ProductProductionStage;
use Modules\Production\Services\ProductionCycleService;

uses(RefreshDatabase::class);

test('an order snapshots only its selected route and stage inputs are not duplicated', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 93001,
        'doc_num' => 'UNIT-STAGE-FLOW',
        'name' => 'قطعة',
        'status' => 'active',
    ]);
    $finishedProduct = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 93001,
        'doc_num' => 'FG-STAGE-FLOW',
        'name' => 'منتج اختبار المراحل',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $rawMaterial = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 93002,
        'doc_num' => 'RM-STAGE-FLOW',
        'name' => 'خامة اختبار المراحل',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);

    $routeStages = collect([
        ['code' => 'PREP', 'name' => 'التجهيز'],
        ['code' => 'FORM', 'name' => 'التشكيل'],
        ['code' => 'PACK', 'name' => 'التعبئة'],
    ])->map(function (array $attributes, int $index) use ($company, $finishedProduct, $user): ProductProductionStage {
        $stage = ProductionStage::query()->create([
            'company_id' => $company->getKey(),
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'display_order' => $index + 1,
            'status' => ProductionStage::StatusActive,
            'created_by' => $user->getKey(),
        ]);

        return ProductProductionStage::query()->create([
            'company_id' => $company->getKey(),
            'product_id' => $finishedProduct->getKey(),
            'production_stage_id' => $stage->getKey(),
            'sequence' => $index + 1,
            'status' => ProductionStage::StatusActive,
            'created_by' => $user->getKey(),
        ]);
    })->values();

    ProductComponent::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $finishedProduct->getKey(),
        'component_product_id' => $rawMaterial->getKey(),
        'unit_id' => $unit->getKey(),
        'production_stage_id' => $routeStages[1]->production_stage_id,
        'calculation_method' => ProductComponent::CalculationDirect,
        'quantity' => '2',
        'created_by' => $user->getKey(),
    ]);

    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'source_type' => 'make_to_stock',
        'production_order_date' => now()->toDateString(),
    ], [[
        'product_id' => $finishedProduct->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '10',
        'stage_public_ids' => [$routeStages[2]->public_id, $routeStages[1]->public_id],
    ]]);
    $line = $order->lines->sole();

    expect($line->stageSnapshots()->orderBy('sequence')->pluck('sequence')->all())->toBe([2, 3])
        ->and($line->stageSnapshots()->orderBy('sequence')->pluck('stage_code')->all())->toBe(['FORM', 'PACK'])
        ->and($line->stageSnapshots()->where('production_stage_id', $routeStages[0]->production_stage_id)->exists())->toBeFalse();

    $cycle->releaseOrder($order);
    $stages = $line->stageSnapshots()->orderBy('sequence')->get();
    $formingRun = $cycle->createRun($line, [
        'production_order_stage_snapshot_id' => $stages[0]->getKey(),
        'planned_quantity' => '10',
        'planned_start_at' => now()->addHour()->toDateTimeString(),
        'planned_end_at' => now()->addHours(2)->toDateTimeString(),
    ]);
    $packingRun = $cycle->createRun($line, [
        'production_order_stage_snapshot_id' => $stages[1]->getKey(),
        'planned_quantity' => '6',
        'planned_start_at' => now()->addHours(3)->toDateTimeString(),
        'planned_end_at' => now()->addHours(4)->toDateTimeString(),
    ]);

    expect($formingRun->requirements)->toHaveCount(1)
        ->and($formingRun->requirements->sole()->planned_quantity)->toBe('20.00000000')
        ->and($packingRun->requirements)->toHaveCount(0);

    $formingRequirement = $formingRun->requirements->sole();
    $formingRequirement->update(['issued_quantity' => '20', 'consumed_quantity' => '20']);
    $formingRun->update([
        'status' => ProductionRun::StatusRunning,
        'setup_status' => 'completed',
        'good_base_quantity' => '5',
        'actual_start_at' => now(),
    ]);
    $completedFormingRun = $cycle->completeRun($formingRun);

    expect($completedFormingRun->status)->toBe(ProductionRun::StatusCompleted)
        ->and($completedFormingRun->received_base_quantity)->toBe('0.00000000');

    $compensationRun = $cycle->createRun($line, [
        'production_order_stage_snapshot_id' => $stages[0]->getKey(),
        'planned_quantity' => '5',
        'planned_start_at' => now()->addHours(5)->toDateTimeString(),
        'planned_end_at' => now()->addHours(6)->toDateTimeString(),
    ]);
    expect($compensationRun->planned_base_quantity)->toBe('5.00000000');

    $cycle->startSetup($packingRun);
    $cycle->completeSetup($packingRun);
    expect(fn () => $cycle->startRun($packingRun))
        ->toThrow(DomainException::class, __('production_execution.messages.previous_stage_output_insufficient'));

    $packingRun->update(['planned_quantity' => '5', 'planned_base_quantity' => '5']);
    $startedPackingRun = $cycle->startRun($packingRun->refresh());
    expect($startedPackingRun->status)->toBe(ProductionRun::StatusRunning);

    $startedPackingRun->update(['good_base_quantity' => '5']);
    expect(fn () => $cycle->completeRun($startedPackingRun->refresh()))
        ->toThrow(DomainException::class, __('production_execution.messages.final_run_receipt_required'));
});
