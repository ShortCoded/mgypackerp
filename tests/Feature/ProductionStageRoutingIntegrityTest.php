<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Carbon\Carbon;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Production\Models\ProductionOrderStageEvent;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionRunBatch;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\ProductProductionStage;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionCycleService;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

test('an order snapshots only its selected route and stage inputs are not duplicated', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $branch->update(['type' => Branch::TypeFactory]);
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
        'order_stage_public_ids' => [$routeStages[1]->stage->public_id],
    ], [[
        'product_id' => $finishedProduct->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '10',
        'stage_public_ids' => [$routeStages[2]->public_id, $routeStages[1]->public_id],
    ]]);
    $line = $order->lines->sole();

    expect($line->stageSnapshots()->orderBy('sequence')->pluck('sequence')->all())->toBe([3])
        ->and($line->stageSnapshots()->orderBy('sequence')->pluck('stage_code')->all())->toBe(['PACK'])
        ->and($order->orderStageSnapshots()->pluck('stage_code')->all())->toBe(['FORM'])
        ->and($line->stageSnapshots()->where('production_stage_id', $routeStages[0]->production_stage_id)->exists())->toBeFalse();

    $legacyDuplicate = ProductionOrderStageSnapshot::query()->create([
        'company_id' => $company->getKey(),
        'production_order_id' => $order->getKey(),
        'production_order_line_id' => $line->getKey(),
        'route_scope_key' => 'line:'.$line->getKey(),
        'production_stage_id' => $routeStages[1]->production_stage_id,
        'product_production_stage_id' => $routeStages[1]->getKey(),
        'sequence' => 1,
        'stage_code' => 'FORM',
        'stage_name' => 'Duplicate forming stage',
        'is_required' => true,
        'status' => ProductionOrderStageSnapshot::StatusPending,
        'created_by' => $user->getKey(),
    ]);
    $effectiveStages = $cycle->stagesForLine($order, $line);

    expect($effectiveStages->pluck('production_stage_id')->all())->toBe([
        $routeStages[1]->production_stage_id,
        $routeStages[2]->production_stage_id,
    ]);

    $cycle->releaseOrder($order);
    $unroutedOrder = $cycle->createMakeToStockOrder([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'source_type' => 'make_to_stock',
        'production_order_date' => now()->toDateString(),
    ], [[
        'product_id' => $finishedProduct->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '1',
    ]]);
    $cycle->releaseOrder($unroutedOrder);
    Permission::findOrCreate('production.runs.view', 'web');
    $user->givePermissionTo('production.runs.view');
    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    $this->actingAs($user)->withSession($context)
        ->getJson(route('admin.production.runs.orders.lines', ['docNum' => $order->doc_num]))
        ->assertOk()->assertJsonPath('data.lines.0.has_stages', true);
    $this->actingAs($user)->withSession($context)
        ->getJson(route('admin.production.runs.orders.lines', ['docNum' => $unroutedOrder->doc_num]))
        ->assertOk()->assertJsonPath('data.lines.0.has_stages', false);

    $stages = $cycle->stagesForLine($order, $line);
    expect(fn () => $cycle->createRun($line, [
        'production_order_stage_snapshot_id' => $legacyDuplicate->getKey(),
        'planned_quantity' => '10',
        'planned_start_at' => now()->addHour()->toDateTimeString(),
        'planned_end_at' => now()->addHours(2)->toDateTimeString(),
    ]))->toThrow(DomainException::class, __('production_execution.messages.order_stage_selection_invalid'));
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

test('stage codes are generated and stage durations are human formatted', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $user = User::factory()->create();
    Permission::findOrCreate('production.stages.create', 'web');
    Permission::findOrCreate('production.stages.view', 'web');
    $user->givePermissionTo(['production.stages.create', 'production.stages.view']);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $branch->update(['type' => Branch::TypeFactory]);
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    $this->actingAs($user)->withSession($context)
        ->get(route('admin.production.stages.create'))
        ->assertOk()
        ->assertSee(__('production_execution.stages.code_generated'))
        ->assertDontSee('name="code"', false)
        ->assertSee('data-submit-action="save"', false)
        ->assertSee('data-submit-action="save_view"', false);

    $storeResponse = $this->actingAs($user)->withSession($context)
        ->post(route('admin.production.stages.store'), [
            '_submission_token' => (string) Str::uuid(),
            'name' => 'مرحلة التعبئة',
            'standard_duration_value' => '0.25',
            'standard_duration_unit' => 'hours',
            'display_order' => '1',
            'status' => 'active',
            'submit_action' => 'save_view',
        ]);

    $stage = ProductionStage::query()->where('company_id', $company->getKey())->where('code', 'STG-00001')->sole();
    $storeResponse->assertRedirect(route('admin.production.stages.show', $stage));
    expect($stage->code)->toBe('STG-00001')
        ->and(app(NumericFormatService::class)->format($stage->standard_duration_value))->toBe('0.25');

    $this->actingAs($user)->withSession($context)
        ->post(route('admin.production.stages.store'), [
            '_submission_token' => (string) Str::uuid(),
            'code' => 'PACK-FINAL',
            'name' => 'مرحلة برمز تلقائي',
            'display_order' => '2',
            'status' => 'active',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('code');

    $this->actingAs($user)->withSession($context)
        ->post(route('admin.production.stages.store'), [
            '_submission_token' => (string) Str::uuid(),
            'name' => 'مرحلة ثانية',
            'display_order' => '3',
            'status' => 'active',
        ])
        ->assertRedirect();

    expect(ProductionStage::query()->where('company_id', $company->getKey())->where('name', 'مرحلة ثانية')->value('code'))
        ->toBe('STG-00002');

    $this->actingAs($user)->withSession($context)
        ->get(route('admin.production.stages.show', $stage))
        ->assertOk()
        ->assertSee($stage->code)
        ->assertSee('0.25');

    $this->actingAs($user)->withSession($context)
        ->getJson(route('admin.production.stages.data'))
        ->assertOk()
        ->assertJsonPath('data.0.duration', '0.25 '.__('production_execution.duration_units.hours'));
});

test('product route editor loads component stage assignments without a blade component collision', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $user = User::factory()->create();
    Permission::findOrCreate('production.product_stages.manage', 'web');
    $user->givePermissionTo('production.product_stages.manage');
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $branch->update(['type' => Branch::TypeFactory]);
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 93501, 'doc_num' => 'UNIT-ROUTE-EDITOR',
        'name' => 'Piece', 'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 93501, 'doc_num' => 'FG-ROUTE-EDITOR',
        'name' => 'Product route editor fixture', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    $componentProduct = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 93502, 'doc_num' => 'RM-ROUTE-EDITOR',
        'name' => 'Component route editor fixture', 'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    $stage = ProductionStage::query()->create([
        'company_id' => $company->getKey(), 'code' => 'STG-EDITOR', 'name' => 'Assembly', 'display_order' => 1,
        'status' => ProductionStage::StatusActive, 'created_by' => $user->getKey(),
    ]);
    $component = ProductComponent::query()->create([
        'company_id' => $company->getKey(), 'product_id' => $product->getKey(), 'component_product_id' => $componentProduct->getKey(),
        'unit_id' => $unit->getKey(), 'calculation_method' => ProductComponent::CalculationDirect,
        'quantity' => '1', 'production_stage_id' => $stage->getKey(), 'created_by' => $user->getKey(),
    ]);
    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    $this->actingAs($user)->withSession($context)
        ->get(route('admin.production.product-stages.edit', $product))
        ->assertOk()
        ->assertSee('component-stage-'.$component->public_id)
        ->assertSee($componentProduct->name)
        ->assertDontSee('value="'.$stage->getKey().'" selected', false);

    ProductProductionStage::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'production_stage_id' => $stage->getKey(),
        'sequence' => 1,
        'status' => ProductionStage::StatusActive,
        'created_by' => $user->getKey(),
    ]);

    $this->actingAs($user)->withSession($context)
        ->get(route('admin.production.product-stages.edit', $product))
        ->assertOk()
        ->assertSee('value="'.$stage->getKey().'" selected', false);
});

test('order route scales BOM per equivalent output unit and records stage history', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $user = User::factory()->create();
    Permission::findOrCreate('production.runs.plan', 'web');
    $user->givePermissionTo('production.runs.plan');
    $this->actingAs($user);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $branch->update(['type' => Branch::TypeFactory]);
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $carton = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 94001,
        'doc_num' => 'UNIT-EQUIV-CARTON',
        'name' => 'Carton',
        'status' => 'active',
    ]);
    $piece = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 94002,
        'doc_num' => 'UNIT-EQUIV-PIECE',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $gram = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 94003,
        'doc_num' => 'UNIT-EQUIV-GRAM',
        'name' => 'Gram',
        'status' => 'active',
    ]);
    $finishedProduct = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 94001,
        'doc_num' => 'FG-EQUIVALENCE',
        'name' => 'Carton of spoons',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $carton->getKey(),
        'equivalent_value' => '3000',
        'equivalent_unit_id' => $piece->getKey(),
        'status' => 'active',
    ]);
    $rawMaterial = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 94002,
        'doc_num' => 'RM-EQUIVALENCE',
        'name' => 'Raw material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $gram->getKey(),
        'status' => 'active',
    ]);
    $firstStage = ProductionStage::query()->create([
        'company_id' => $company->getKey(),
        'code' => 'MIX',
        'name' => 'Mix',
        'display_order' => 1,
        'status' => ProductionStage::StatusActive,
        'created_by' => $user->getKey(),
    ]);
    $finalStage = ProductionStage::query()->create([
        'company_id' => $company->getKey(),
        'code' => 'PACK',
        'name' => 'Pack',
        'display_order' => 2,
        'status' => ProductionStage::StatusActive,
        'created_by' => $user->getKey(),
    ]);
    $component = ProductComponent::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $finishedProduct->getKey(),
        'component_product_id' => $rawMaterial->getKey(),
        'unit_id' => $gram->getKey(),
        'production_stage_id' => $firstStage->getKey(),
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
        'order_stage_public_ids' => [$firstStage->public_id, $finalStage->public_id],
    ], [[
        'product_id' => $finishedProduct->getKey(),
        'unit_id' => $carton->getKey(),
        'quantity' => '10',
    ]]);
    $line = $order->lines->sole();
    $cycle->releaseOrder($order);
    $firstStageSnapshot = $order->orderStageSnapshots()->where('production_stage_id', $firstStage->getKey())->firstOrFail();
    $finalStageSnapshot = $order->orderStageSnapshots()->where('production_stage_id', $finalStage->getKey())->firstOrFail();
    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    $this->actingAs($user)->withSession($context)
        ->getJson(route('admin.production.runs.select2.stages', [
            'production_order_line_public_id' => $line->public_id,
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'results')
        ->assertJsonPath('results.0.id', $firstStageSnapshot->public_id)
        ->assertJsonPath('results.1.id', $finalStageSnapshot->public_id);

    $run = $cycle->createRun($line, [
        'production_order_stage_snapshot_id' => $firstStageSnapshot->getKey(),
        'planned_quantity' => '10',
        'planned_start_at' => now()->addHour()->toDateTimeString(),
        'planned_end_at' => now()->addHours(2)->toDateTimeString(),
    ]);

    expect($line->fresh()->bom_snapshot['basis_base_quantity'])->toBe('3000.000000')
        ->and($line->fresh()->bom_snapshot['basis_unit_name'])->toBe('Piece')
        ->and($run->requirements->sole()->product_component_id)->toBe($component->getKey())
        ->and($run->requirements->sole()->planned_quantity)->toBe('60000.00000000');

    $run->requirements->sole()->update([
        'issued_quantity' => '60000',
        'consumed_quantity' => '60000',
    ]);
    $cycle->startSetup($run);
    $cycle->completeSetup($run->refresh());
    $startedRun = $cycle->startRun($run->refresh());
    $startedRun->update(['good_base_quantity' => '10']);
    $cycle->completeRun($startedRun->refresh());

    expect($firstStageSnapshot->fresh()->status)->toBe('completed')
        ->and(ProductionOrderStageEvent::query()
            ->where('production_order_stage_snapshot_id', $firstStageSnapshot->getKey())
            ->pluck('event_type')->all())->toBe(['run_started', 'stage_completed']);
});

test('production route lookups search and line details return formatted unit and BOM previews', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $user = User::factory()->create();
    Permission::findOrCreate('production.orders.view', 'web');
    $user->givePermissionTo('production.orders.view');
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $branch->update(['type' => Branch::TypeFactory]);
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $carton = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 95001,
        'doc_num' => 'UNIT-LOOKUP-CARTON',
        'name' => 'Carton',
        'status' => 'active',
    ]);
    $piece = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 95002,
        'doc_num' => 'UNIT-LOOKUP-PIECE',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $gram = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 95003,
        'doc_num' => 'UNIT-LOOKUP-GRAM',
        'name' => 'Gram',
        'status' => 'active',
    ]);
    $finishedProduct = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 95001,
        'doc_num' => 'FG-LOOKUP-EQUIVALENCE',
        'name' => 'Spoon carton',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $carton->getKey(),
        'equivalent_value' => '3000',
        'equivalent_unit_id' => $piece->getKey(),
        'status' => 'active',
    ]);
    $rawMaterial = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 95002,
        'doc_num' => 'RM-LOOKUP',
        'name' => 'Raw material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $gram->getKey(),
        'status' => 'active',
    ]);
    $stage = ProductionStage::query()->create([
        'company_id' => $company->getKey(),
        'code' => 'MIX-LOOKUP',
        'name' => 'Mixing lookup',
        'display_order' => 1,
        'status' => ProductionStage::StatusActive,
        'created_by' => $user->getKey(),
    ]);
    $productStage = ProductProductionStage::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $finishedProduct->getKey(),
        'production_stage_id' => $stage->getKey(),
        'sequence' => 1,
        'status' => ProductionStage::StatusActive,
        'created_by' => $user->getKey(),
    ]);
    ProductComponent::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $finishedProduct->getKey(),
        'component_product_id' => $rawMaterial->getKey(),
        'unit_id' => $gram->getKey(),
        'calculation_method' => ProductComponent::CalculationDirect,
        'quantity' => '2',
        'created_by' => $user->getKey(),
    ]);
    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    $this->actingAs($user)->withSession($context)
        ->getJson(route('admin.production.work-orders.select2.stages', [
            'source_type' => 'make_to_stock',
            'source_line_reference' => 'product:'.$finishedProduct->doc_num,
            'q' => 'MIX-LOOKUP',
        ]))
        ->assertOk()
        ->assertJsonPath('results.0.id', $productStage->public_id);

    $this->actingAs($user)->withSession($context)
        ->getJson(route('admin.production.work-orders.select2.order-stages', ['q' => 'MIX-LOOKUP']))
        ->assertOk()
        ->assertJsonPath('results.0.id', $stage->public_id);

    $details = $this->actingAs($user)->withSession($context)
        ->getJson(route('admin.production.work-orders.select2.line-details', [
            'source_type' => 'make_to_stock',
            'source_line_reference' => 'product:'.$finishedProduct->doc_num,
        ]))
        ->assertOk()
        ->json();

    expect($details['output_factor'])->toBe('3000.00000000')
        ->and($details['equivalence_configured'])->toBeTrue()
        ->and($details['unit'])->toBe('Carton')
        ->and($details['equivalent_unit'])->toBe('Piece')
        ->and($details['components'][0]['unit'])->toBe('Gram')
        ->and($details['components'][0]['required_quantity'])->toBe(app(NumericFormatService::class)->format('6000'));

    $finishedProduct->update(['equivalent_value' => null, 'equivalent_unit_id' => null]);
    $this->actingAs($user)->withSession($context)
        ->getJson(route('admin.production.work-orders.select2.line-details', [
            'source_type' => 'make_to_stock',
            'source_line_reference' => 'product:'.$finishedProduct->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('equivalence_configured', false);
});

test('one production batch can produce partial quantities for multiple order lines on each selected line stage', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $user = User::factory()->create();
    $this->actingAs($user);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $carton = ItemUnit::query()->create(['company_id' => $company->getKey(), 'doc_number' => 95001, 'doc_num' => 'UNIT-BATCH-CARTON', 'name' => 'Carton', 'status' => 'active']);
    $piece = ItemUnit::query()->create(['company_id' => $company->getKey(), 'doc_number' => 95002, 'doc_num' => 'UNIT-BATCH-PIECE', 'name' => 'Piece', 'status' => 'active']);
    $gram = ItemUnit::query()->create(['company_id' => $company->getKey(), 'doc_number' => 95003, 'doc_num' => 'UNIT-BATCH-GRAM', 'name' => 'Gram', 'status' => 'active']);
    $products = collect([
        ['code' => 'FG-BATCH-A', 'raw' => 'RM-BATCH-A', 'equivalent' => '3000', 'component_qty' => '2', 'quantity' => '30', 'planned' => '10'],
        ['code' => 'FG-BATCH-B', 'raw' => 'RM-BATCH-B', 'equivalent' => '10', 'component_qty' => '5', 'quantity' => '8', 'planned' => '3'],
    ])->map(function (array $specification, int $index) use ($company, $carton, $piece, $gram, $user): array {
        $finished = Product::query()->create([
            'company_id' => $company->getKey(), 'doc_number' => 95010 + $index, 'doc_num' => $specification['code'],
            'name' => $specification['code'], 'item_classification' => Product::ClassificationFinishedProduct,
            'item_unit_id' => $carton->getKey(), 'equivalent_value' => $specification['equivalent'],
            'equivalent_unit_id' => $piece->getKey(), 'status' => 'active',
        ]);
        $raw = Product::query()->create([
            'company_id' => $company->getKey(), 'doc_number' => 95020 + $index, 'doc_num' => $specification['raw'],
            'name' => $specification['raw'], 'item_classification' => Product::ClassificationRawMaterial,
            'item_unit_id' => $gram->getKey(), 'status' => 'active',
        ]);
        $stage = ProductionStage::query()->create([
            'company_id' => $company->getKey(), 'code' => 'BATCH-'.$index, 'name' => 'Batch stage '.$index,
            'display_order' => $index + 1, 'status' => ProductionStage::StatusActive, 'created_by' => $user->getKey(),
        ]);
        $routeStage = ProductProductionStage::query()->create([
            'company_id' => $company->getKey(), 'product_id' => $finished->getKey(),
            'production_stage_id' => $stage->getKey(), 'sequence' => 1,
            'status' => ProductionStage::StatusActive, 'created_by' => $user->getKey(),
        ]);
        ProductComponent::query()->create([
            'company_id' => $company->getKey(), 'product_id' => $finished->getKey(),
            'component_product_id' => $raw->getKey(), 'unit_id' => $gram->getKey(),
            'production_stage_id' => $stage->getKey(), 'calculation_method' => ProductComponent::CalculationDirect,
            'quantity' => $specification['component_qty'], 'created_by' => $user->getKey(),
        ]);

        return [...$specification, 'product' => $finished, 'stage' => $stage, 'route_stage' => $routeStage];
    })->values();

    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(), 'branch_id' => $branch->getKey(),
        'source_type' => 'make_to_stock', 'production_order_date' => now()->toDateString(),
    ], $products->map(fn (array $product): array => [
        'product_id' => $product['product']->getKey(), 'unit_id' => $carton->getKey(), 'quantity' => $product['quantity'],
        'stage_public_ids' => [$product['route_stage']->public_id],
    ])->all());
    $cycle->releaseOrder($order);

    $batch = $cycle->createRunBatch($order, [
        'planned_start_at' => now()->addHour()->toDateTimeString(),
        'planned_end_at' => now()->addHours(2)->toDateTimeString(),
        'lines' => $order->lines->values()->map(fn ($line, int $index): array => [
            'production_order_line_id' => $line->getKey(),
            'production_order_stage_snapshot_id' => $line->stageSnapshots()->value('id'),
            'planned_quantity' => $products[$index]['planned'],
        ])->all(),
    ]);

    expect($batch)->toBeInstanceOf(ProductionRunBatch::class)
        ->and($batch->runs)->toHaveCount(2)
        ->and($batch->runs->pluck('production_order_stage_snapshot_id')->unique()->count())->toBe(2)
        ->and($batch->runs->pluck('planned_quantity')->all())->toBe(['10.00000000', '3.00000000'])
        ->and($batch->runs[0]->requirements->sole()->planned_quantity)->toBe('60000.00000000')
        ->and($batch->runs[1]->requirements->sole()->planned_quantity)->toBe('150.00000000')
        ->and($batch->runs->every(fn (ProductionRun $run): bool => (int) $run->production_run_batch_id === (int) $batch->getKey()))->toBeTrue();

    expect(fn () => $cycle->createRun($batch->runs[0]->orderLine, [
        'production_order_stage_snapshot_id' => $batch->runs[1]->production_order_stage_snapshot_id,
        'planned_quantity' => '1',
        'planned_start_at' => now()->addHours(3)->toDateTimeString(),
        'planned_end_at' => now()->addHours(4)->toDateTimeString(),
    ]))->toThrow(DomainException::class, __('production_execution.messages.order_stage_selection_invalid'));
});

require_once __DIR__.'/../ManufacturingInventorySupport.php';

test('actual prior-stage material stock and GL cost cannot be silently omitted from a final-stage receipt', function (): void {
    $this->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $f = manufacturingInventoryFixture('-SYNTHETIC-STAGE-COST', isolatedCompany: true);
    $store = BranchStore::query()->create(['branch_id' => $f['branch']->id, 'name' => 'SYNTHETIC actual stage cost store']);
    app(InventoryMovementService::class)->createAndPost(['company_id' => $f['company']->id,
        'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id, 'branch_store_id' => $store->id,
        'document_type' => InventoryDocument::TypeAdjustmentIn, 'document_date' => now()->toDateString()],
        [['product_id' => $f['raw']->id, 'quantity' => '4', 'unit_cost' => '10']]);
    $stages = collect(['FORM', 'PACK'])->map(fn (string $code) => ProductionStage::query()->create(['company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id, 'code' => 'SYNTHETIC-COST-'.$code, 'name' => 'SYNTHETIC '.$code, 'status' => 'active']));
    ProductComponent::query()->where('company_id', $f['company']->id)->where('product_id', $f['finished']->id)->update(['production_stage_id' => $stages->first()->id]);
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->releaseOrder($cycle->createMakeToStockOrder(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'order_stage_public_ids' => $stages->pluck('public_id')->all()],
        [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '2']]));
    $snapshots = $cycle->stagesForLine($order, $order->lines->sole());
    $runs = $snapshots->map(fn ($stage) => $cycle->createRun($order->lines->sole(), ['production_order_stage_snapshot_id' => $stage->id,
        'planned_quantity' => '2', 'planned_start_at' => now(), 'planned_end_at' => now()->addHour(), 'production_machine_id' => $f['machine']->id, 'production_mold_id' => $f['mold']->id]));
    $forming = $runs->first();
    $cycle->reserveRun($forming, $store->id);
    $cycle->issueMaterials($forming->fresh(), $store->id);
    $cycle->startRun($cycle->completeSetup($cycle->startSetup($forming->fresh())));
    $cycle->recordProgress($forming->fresh(), ['good_base_quantity' => '2']);
    $cycle->accountMaterials($forming->fresh(), $store->id, [$forming->requirements->sole()->id => ['consumed_quantity' => '4', 'waste_quantity' => '0']]);
    $cycle->completeRun($forming->fresh());
    $packing = $cycle->startRun($cycle->completeSetup($cycle->startSetup($runs->last()->fresh())));
    $cycle->recordProgress($packing->fresh(), ['good_base_quantity' => '2']);
    $costs = app(ProductionCostService::class);
    expect($forming->fresh()->good_base_quantity)->toBe('2.00000000')->and($forming->fresh()->status)->toBe('completed')
        ->and($costs->runPosition($forming->fresh())['wip'])->toBe('40.00000000')->and($costs->runPosition($packing->fresh())['wip'])->toBe('0.00000000');
    $wipAccount = app(PostingAccountResolver::class)->resolve($f['company']->id, PostingAccountResolver::WorkInProcessInventory, 'SYNTHETIC actual stage cost');
    $net = JournalEntryLine::query()->where('account_id', $wipAccount->id)->whereHas('journalEntry', fn ($query) => $query->where('is_posted', true))
        ->selectRaw('coalesce(sum(debit_amount - credit_amount), 0) as balance')->value('balance');
    expect(bcadd((string) $net, '0', 4))->toBe('40.0000');
    $ledger = [InventoryTransaction::count(), JournalEntry::count()];
    expect(fn () => DB::transaction(fn () => $cycle->prepareFinishedGoodsReceipt($packing->fresh(), $store->id, '2')))
        ->toThrow(DomainException::class, __('production_daily_report.correction.stage_cost_transfer_required'));
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($ledger)
        ->and($packing->fresh()->received_base_quantity)->toBe('0.00000000');
});
