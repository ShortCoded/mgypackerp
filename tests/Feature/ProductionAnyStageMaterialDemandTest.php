<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionMaterialDemandService;
use Modules\Production\Services\ProductionMaterialRequestService;
use Modules\Production\Services\ProductionReportService;

require_once __DIR__.'/../ManufacturingInventorySupport.php';

require_once __DIR__.'/../ProductionAnyStageMaterialSupport.php';

test('Arabic and English later stage forms expose remaining BOM materials without writes or full quantity defaults', function (): void {
    $f = anyStageMaterialFixture();
    expect($f['runs'][1]->requirements)->toHaveCount(0);
    $count = ProductionMaterialRequirement::query()->count();
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->get(route('admin.production.material-requests.create', ['run' => $f['runs'][1]->id]))->assertOk()
            ->assertSee($f['raw']->name)->assertSee(__('production_execution.material_requests.cumulative_remaining'))
            ->assertSee('product_component_id')->assertDontSee('production_execution.material_requests.');
    }
    expect(ProductionMaterialRequirement::query()->count())->toBe($count);
});

test('planned requests at stages two and three share the order demand and create only selected lineage', function (): void {
    $f = anyStageMaterialFixture();
    $service = app(ProductionMaterialRequestService::class);
    $second = $service->create($f['runs'][1], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '20']);
    $third = $service->create($f['runs'][2], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '30']);
    expect($second->lines->sole()->requested_quantity)->toBe('20.00000000')
        ->and($third->lines->sole()->requirement->production_run_id)->toBe($f['runs'][2]->id)
        ->and($service->remainingRequestableFor($second->lines->sole()->requirement))->toBe('150.00000000')
        ->and($service->remainingRequestableFor($f['runs'][0]->requirements->sole()))->toBe('150.00000000');
    expect(fn () => $service->create($f['runs'][2]->fresh(), $f['store']->id, [$third->lines->sole()->production_material_requirement_id => '150.00000001']))
        ->toThrow(DomainException::class, __('production_execution.messages.material_request_exceeds_bom'));
    expect(ProductionMaterialRequest::query()->where('production_order_id', $f['order']->id)->count())->toBe(2);
});

test('a selected later stage component posts through the native form and its repeated request loads its remaining quantity', function (): void {
    $f = anyStageMaterialFixture();
    $payload = ['production_run_id' => $f['runs'][1]->id, 'branch_store_id' => $f['store']->id,
        '_submission_token' => (string) Str::uuid(), 'lines' => [['product_component_id' => $f['componentId'], 'material_choice' => 'primary', 'quantity' => '20']]];
    $this->post(route('admin.production.material-requests.store'), $payload)->assertRedirect();
    $this->post(route('admin.production.material-requests.store'), $payload)->assertRedirect();
    $run = $f['runs'][1]->fresh();
    expect($run->requirements)->toHaveCount(1)
        ->and(app(ProductionMaterialRequestService::class)->remainingRequestableFor($run->requirements->sole()))->toBe('180.00000000');
    $this->get(route('admin.production.material-requests.create', ['run' => $run->id]))->assertOk()->assertSee($f['raw']->name);
});

test('partial issues retain one cumulative commitment and cannot be counted again at another stage', function (): void {
    $f = anyStageMaterialFixture();
    $service = app(ProductionMaterialRequestService::class);
    $request = $service->create($f['runs'][1], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '20']);
    $request = $service->approve($request);
    $service->issue($request, [$request->lines->sole()->id => '10']);
    expect(app(ProductionMaterialDemandService::class)->remaining($f['runs'][0]->requirements->sole()))->toBe('180.00000000');
    $service->issue($request->fresh(), [$request->lines->sole()->id => '10']);
    expect(app(ProductionMaterialDemandService::class)->remaining($f['runs'][0]->requirements->sole()))->toBe('180.00000000');
    $third = $service->create($f['runs'][2], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '180']);
    expect(app(ProductionMaterialDemandService::class)->remaining($f['runs'][0]->requirements->sole()))->toBe('0.00000000');
    $third = $service->approve($third);
    $service->issue($third);
    expect($third->lines->sole()->requirement->fresh()->issued_quantity)->toBe('180.00000000')
        ->and(app(ProductionMaterialDemandService::class)->remaining($f['runs'][0]->requirements->sole()))->toBe('0.00000000');
    expect(fn () => $f['cycle']->reserveRun($f['runs'][0], $f['store']->id))->not->toThrow(DomainException::class);
    expect($f['runs'][0]->requirements->sole()->fresh()->reserved_quantity)->toBe('0.00000000');
});

test('multi-position approval reserves null and exact stock once and keeps issue lineage', function (): void {
    $f = anyStageMaterialFixture();
    $opening = InventoryTransaction::query()->where('product_id', $f['raw']->id)->sole();
    $opening->forceFill([
        'transaction_date' => now()->subDay()->toDateString(),
        'quantity_in' => '40',
        'total_cost' => '80',
    ])->save();
    $location = WarehouseLocation::query()->create([
        'branch_store_id' => $f['store']->id,
        'code' => 'SYNTHETIC-MULTIPOS',
        'name' => 'SYNTHETIC exact stock position',
        'created_by' => $f['user']->id,
    ]);
    $exactOpening = $opening->replicate();
    $exactOpening->forceFill([
        'posting_key' => 'synthetic-any-stage-multipos-'.$f['raw']->id,
        'warehouse_location_id' => $location->id,
        'batch_lot' => 'SYNTHETIC-MULTIPOS-BATCH',
        'transaction_date' => now()->toDateString(),
        'quantity_in' => '60',
        'unit_cost' => '4',
        'total_cost' => '240',
        'source_doc_num' => 'SYNTHETIC-MULTIPOS-OPENING',
    ])->save();

    $service = app(ProductionMaterialRequestService::class);
    $request = $service->create($f['runs'][1], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '100']);
    $request = $service->approve($request);
    $line = $request->lines->sole();
    $reservations = InventoryReservation::query()
        ->where('production_material_request_line_id', $line->id)
        ->oldest()
        ->get();

    expect($request->status)->toBe(ProductionMaterialRequest::StatusApproved)
        ->and($line->reserved_quantity)->toBe('100.00000000')
        ->and($line->requirement->fresh()->reserved_quantity)->toBe('100.00000000')
        ->and($reservations)->toHaveCount(2)
        ->and($reservations->pluck('quantity')->all())->toBe(['40.00000000', '60.00000000'])
        ->and($reservations->pluck('warehouse_location_id')->all())->toBe([null, $location->id])
        ->and($reservations->pluck('batch_lot')->all())->toBe([null, 'SYNTHETIC-MULTIPOS-BATCH']);

    $issue = $service->issue($request, [$line->id => '70']);
    $issueLines = $issue->lines()->orderBy('id')->get();
    $reservations = $reservations->map->fresh();

    expect($issueLines)->toHaveCount(2)
        ->and($issueLines->pluck('quantity')->all())->toBe(['40.00000000', '30.00000000'])
        ->and($issueLines->pluck('inventory_reservation_id')->all())->toBe($reservations->pluck('id')->all())
        ->and($issueLines->pluck('production_material_request_line_id')->unique()->values()->all())->toBe([$line->id])
        ->and($issueLines->every(fn ($issueLine): bool => $issueLine->unit_cost !== null && $issueLine->total_cost !== null))->toBeTrue()
        ->and($reservations->pluck('consumed_quantity')->all())->toBe(['40.00000000', '30.00000000'])
        ->and($line->fresh()->issued_quantity)->toBe('70.00000000')
        ->and($line->requirement->fresh()->issued_quantity)->toBe('70.00000000');
});

test('foreign requirement and component selectors cannot silently create an unrelated request', function (): void {
    $f = anyStageMaterialFixture();
    $service = app(ProductionMaterialRequestService::class);
    expect(fn () => $service->create($f['runs'][1], $f['store']->id, [$f['runs'][0]->requirements->sole()->id => '1']))->toThrow(DomainException::class);
    expect(fn () => $service->create($f['runs'][1], $f['store']->id, quantitiesByComponentId: [99999999 => '1']))->toThrow(DomainException::class);
    expect($f['runs'][1]->requirements()->count())->toBe(0)->and(ProductionMaterialRequest::query()->where('production_order_id', $f['order']->id)->count())->toBe(0);
});

test('restoring a submitted request rechecks demand committed by another stage', function (): void {
    $f = anyStageMaterialFixture();
    $service = app(ProductionMaterialRequestService::class);
    $request = $service->create($f['runs'][1], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '50']);
    $service->delete($request);
    $service->create($f['runs'][2], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '200']);
    expect(fn () => $service->restore($request))->toThrow(DomainException::class, __('production_execution.messages.material_request_exceeds_bom'));
    expect($request->fresh()->trashed())->toBeTrue();
});

test('a frozen alternative selection keeps original demand capacity while issue and cost use the actual material', function (): void {
    $f = anyStageMaterialFixture();
    $snapshotAlternative = $f['line']->bom_snapshot['components'][0]['alternatives'][0];
    expect($snapshotAlternative['product_id'])->toBe($f['alternative']->id)
        ->and($snapshotAlternative['conversion_factor'])->toBe('1.50000000');
    $f['alternativeDefinition']->update(['conversion_factor' => '2.00000000']);

    $service = app(ProductionMaterialRequestService::class);
    $request = $service->create(
        $f['runs'][1],
        $f['store']->id,
        quantitiesByComponentId: [$f['componentId'] => '30'],
        materialChoicesByComponentId: [$f['componentId'] => $f['alternativeDefinition']->public_id],
    );
    $requirement = $request->lines->sole()->requirement;
    expect($requirement->product_id)->toBe($f['alternative']->id)
        ->and($requirement->original_product_id)->toBe($f['raw']->id)
        ->and($requirement->material_conversion_factor)->toBe('1.50000000')
        ->and($request->lines->sole()->requested_quantity)->toBe('30.00000000')
        ->and($request->lines->sole()->original_requested_quantity)->toBe('20.00000000')
        ->and($service->remainingRequestableFor($f['runs'][0]->requirements->sole()))->toBe('180.00000000');

    $request = $service->approve($request);
    $issue = $service->issue($request);
    expect($issue->lines()->sole()->product_id)->toBe($f['alternative']->id)
        ->and($issue->lines()->sole()->quantity)->toBe('30.00000000')
        ->and($issue->lines()->sole()->total_cost)->toBe('90.00000000');
});

test('financial material reporting subtracts canonical alternative returns from actual cost', function (): void {
    $f = anyStageMaterialFixture();
    $service = app(ProductionMaterialRequestService::class);
    $request = $service->create(
        $f['runs'][1],
        $f['store']->id,
        quantitiesByComponentId: [$f['componentId'] => '30'],
        materialChoicesByComponentId: [$f['componentId'] => $f['alternativeDefinition']->public_id],
    );
    $request = $service->approve($request);
    $service->issue($request);
    $requirement = $request->lines->sole()->requirement;
    $f['cycle']->returnMaterials($f['runs'][1], $f['store']->id, [$requirement->id => '10']);

    $report = app(ProductionReportService::class)->report(
        $f['company']->id,
        $f['period']->id,
        $f['branch']->id,
        ['production_run_id' => $f['runs'][1]->id],
        true,
    );

    expect($report['materials']->sole()->actual_cost)->toBe('60.00000000')
        ->and(app(ProductionCostService::class)->runPosition($f['runs'][1])['direct_material_cost'])->toBe('60.00000000');
});

test('a request cannot select material outside the released BOM snapshot', function (): void {
    $f = anyStageMaterialFixture();
    expect(fn () => app(ProductionMaterialRequestService::class)->create(
        $f['runs'][1],
        $f['store']->id,
        quantitiesByComponentId: [$f['componentId'] => '1'],
        materialChoicesByComponentId: [$f['componentId'] => (string) Str::uuid()],
    ))->toThrow(DomainException::class, __('production_execution.messages.material_choice_not_approved'));
    expect($f['runs'][1]->requirements()->count())->toBe(0);
});

test('one BOM component can be partially allocated to primary and alternative materials under one original cap', function (): void {
    $f = anyStageMaterialFixture();
    $service = app(ProductionMaterialRequestService::class);
    $request = $service->create(
        $f['runs'][1],
        $f['store']->id,
        componentAllocations: [
            ['product_component_id' => $f['componentId'], 'material_choice' => 'primary', 'quantity' => '40'],
            ['product_component_id' => $f['componentId'], 'material_choice' => $f['alternativeDefinition']->public_id, 'quantity' => '60'],
        ],
    );

    $lines = $request->lines()->with('requirement')->orderBy('id')->get();
    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('product_id')->sort()->values()->all())->toBe(collect([$f['raw']->id, $f['alternative']->id])->sort()->values()->all())
        ->and($lines->reduce(fn (string $total, $line): string => bcadd($total, (string) $line->original_requested_quantity, 8), '0.00000000'))->toBe('80.00000000')
        ->and($f['runs'][1]->requirements()->whereNotNull('allocation_root_requirement_id')->count())->toBe(1)
        ->and($service->remainingRequestableFor($f['runs'][0]->requirements->sole()))->toBe('120.00000000');

    $reportRequirements = $f['runs'][1]->fresh()->requirements;
    app(ProductionReportService::class)->projectMaterialAllocationPlans($reportRequirements);
    $reported = $reportRequirements->keyBy('product_id');
    expect($reported[$f['raw']->id]->report_planned_quantity)->toBe('40.00000000')
        ->and($reported[$f['alternative']->id]->report_planned_quantity)->toBe('60.00000000')
        ->and($reported[$f['raw']->id]->report_component_original_planned_quantity)->toBe('80.00000000');
});

test('editing sibling material allocations spends one shared original equivalent budget', function (): void {
    $f = anyStageMaterialFixture();
    $service = app(ProductionMaterialRequestService::class);
    $request = $service->create($f['runs'][1], $f['store']->id, componentAllocations: [
        ['product_component_id' => $f['componentId'], 'material_choice' => 'primary', 'quantity' => '40'],
        ['product_component_id' => $f['componentId'], 'material_choice' => $f['alternativeDefinition']->public_id, 'quantity' => '60'],
    ]);
    $requirements = $request->lines->keyBy('product_id')->map->production_material_requirement_id;

    expect(fn () => $service->update($request, $f['store']->id, [
        $requirements[$f['raw']->id] => '150',
        $requirements[$f['alternative']->id] => '90',
    ]))->toThrow(DomainException::class, __('production_execution.messages.material_request_exceeds_bom'));
    expect($request->fresh()->lines()->sum('original_requested_quantity'))->toEqual(80);
});

test('restore and approval recheck the combined sibling allocation budget', function (): void {
    $f = anyStageMaterialFixture();
    $service = app(ProductionMaterialRequestService::class);
    $restorable = $service->create($f['runs'][1], $f['store']->id, componentAllocations: [
        ['product_component_id' => $f['componentId'], 'material_choice' => 'primary', 'quantity' => '140'],
        ['product_component_id' => $f['componentId'], 'material_choice' => $f['alternativeDefinition']->public_id, 'quantity' => '90'],
    ]);
    $service->delete($restorable);
    $service->create($f['runs'][2], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '10']);
    expect(fn () => $service->restore($restorable))->toThrow(
        DomainException::class,
        __('production_execution.messages.material_request_exceeds_bom'),
    );

    $approvable = $service->create($f['runs'][0], $f['store']->id, componentAllocations: [
        ['product_component_id' => $f['componentId'], 'material_choice' => 'primary', 'quantity' => '40'],
        ['product_component_id' => $f['componentId'], 'material_choice' => $f['alternativeDefinition']->public_id, 'quantity' => '60'],
    ]);
    $approvable->lines->each(function ($line) use ($f): void {
        $line->forceFill([
            'requested_quantity' => (int) $line->product_id === (int) $f['raw']->id ? '150' : '90',
        ])->save();
    });
    expect(fn () => $service->approve($approvable->fresh()))->toThrow(
        DomainException::class,
        __('production_execution.messages.material_request_exceeds_bom'),
    );
});

test('an unlinked primary reservation cannot credit an alternative sibling or exceed its selected primary quantity', function (): void {
    $f = anyStageMaterialFixture();
    $f['cycle']->reserveRun($f['runs'][0], $f['store']->id);

    expect($f['runs'][0]->requirements->sole()->fresh()->reserved_quantity)->toBe('200.00000000');
    expect(fn () => app(ProductionMaterialRequestService::class)->create(
        $f['runs'][1],
        $f['store']->id,
        quantitiesByComponentId: [$f['componentId'] => '300'],
        materialChoicesByComponentId: [$f['componentId'] => $f['alternativeDefinition']->public_id],
    ))->toThrow(DomainException::class, __('production_execution.messages.material_request_exceeds_bom'));
    expect(fn () => app(ProductionMaterialRequestService::class)->create(
        $f['runs'][1],
        $f['store']->id,
        componentAllocations: [
            ['product_component_id' => $f['componentId'], 'material_choice' => 'primary', 'quantity' => '30'],
            ['product_component_id' => $f['componentId'], 'material_choice' => $f['alternativeDefinition']->public_id, 'quantity' => '255'],
        ],
    ))->toThrow(DomainException::class, __('production_execution.messages.material_request_exceeds_bom'));
});

test('zero stock approval still rejects product unit drift from the frozen requirement', function (): void {
    $f = anyStageMaterialFixture();
    $request = app(ProductionMaterialRequestService::class)->create(
        $f['runs'][1],
        $f['store']->id,
        quantitiesByComponentId: [$f['componentId'] => '30'],
        materialChoicesByComponentId: [$f['componentId'] => $f['alternativeDefinition']->public_id],
    );
    InventoryTransaction::query()->where('product_id', $f['alternative']->id)->update(['quantity_out' => '1000']);
    $unitNumber = (int) ItemUnit::withTrashed()->max('doc_number') + 1;
    $driftedUnit = ItemUnit::query()->create([
        'company_id' => $f['company']->id,
        'doc_number' => $unitNumber,
        'doc_num' => 'SYNTHETIC-DRIFT-UNIT-'.$unitNumber,
        'name' => 'SYNTHETIC drifted material unit',
        'status' => 'active',
    ]);
    $f['alternative']->update(['item_unit_id' => $driftedUnit->id]);

    expect(fn () => app(ProductionMaterialRequestService::class)->approve($request))->toThrow(
        DomainException::class,
        __('production_execution.messages.material_unit_snapshot_mismatch'),
    );
    expect($request->fresh()->status)->toBe(ProductionMaterialRequest::StatusSubmitted);
});

test('additional-only alternative activity preserves the stored root reporting plan', function (): void {
    $f = anyStageMaterialFixture();
    $root = $f['runs'][0]->requirements->sole();
    $alternative = DB::transaction(fn (): ProductionMaterialRequirement => app(ProductionMaterialDemandService::class)->allocationRequirement(
        $f['runs'][0],
        $f['componentId'],
        $f['alternativeDefinition']->public_id,
    ));
    $alternative->forceFill(['additional_issued_quantity' => '15'])->save();

    $requirements = $f['runs'][0]->fresh()->requirements;
    app(ProductionReportService::class)->projectMaterialAllocationPlans($requirements);
    $reported = $requirements->keyBy('id');

    expect($reported[$root->id]->report_is_split_allocation)->toBeFalse()
        ->and($reported[$root->id]->report_planned_quantity)->toBe('200.00000000')
        ->and($reported[$alternative->id]->report_planned_quantity)->toBe('0.00000000')
        ->and($reported[$root->id]->report_component_original_planned_quantity)->toBe('200.00000000');
});

test('returns offset additional issues before reopening planned BOM capacity', function (): void {
    $f = anyStageMaterialFixture();
    $requirement = $f['runs'][0]->requirements->sole();
    $requirement->forceFill([
        'issued_quantity' => '100',
        'additional_issued_quantity' => '20',
        'returned_quantity' => '20',
    ])->save();

    expect(app(ProductionMaterialDemandService::class)->remaining($requirement->fresh()))->toBe('100.00000000');
});

test('authorized reallocation moves only an unissued remainder and preserves issued reservation evidence', function (): void {
    $f = anyStageMaterialFixture();
    $service = app(ProductionMaterialRequestService::class);
    $source = $service->create($f['runs'][1], $f['store']->id, quantitiesByComponentId: [$f['componentId'] => '30']);
    $source = $service->approve($source);
    $sourceLine = $source->lines->sole();
    $service->issue($source, [$sourceLine->id => '10']);

    $target = $service->reallocateUnissued(
        $source->fresh(),
        $sourceLine->id,
        '10',
        $f['alternativeDefinition']->public_id,
        'SYNTHETIC change of unissued remainder',
    );

    $sourceLine = $sourceLine->fresh();
    $targetLine = $target->lines->sole();
    expect($source->fresh()->status)->toBe(ProductionMaterialRequest::StatusPartiallyIssued)
        ->and($sourceLine->issued_quantity)->toBe('10.00000000')
        ->and($sourceLine->reallocated_quantity)->toBe('10.00000000')
        ->and($sourceLine->reallocated_reserved_quantity)->toBe('10.00000000')
        ->and($sourceLine->remainingReservedQuantity())->toBe('10.00000000')
        ->and($target->status)->toBe(ProductionMaterialRequest::StatusSubmitted)
        ->and($targetLine->product_id)->toBe($f['alternative']->id)
        ->and($targetLine->requested_quantity)->toBe('15.00000000')
        ->and($targetLine->original_requested_quantity)->toBe('10.00000000')
        ->and(DB::table('production_material_request_reallocations')->where('source_material_request_line_id', $sourceLine->id)->where('target_material_request_line_id', $targetLine->id)->count())->toBe(1);
});
