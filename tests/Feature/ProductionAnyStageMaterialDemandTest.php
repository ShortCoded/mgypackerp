<?php

use Illuminate\Support\Str;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Services\ProductionMaterialDemandService;
use Modules\Production\Services\ProductionMaterialRequestService;

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
        '_submission_token' => (string) Str::uuid(), 'lines' => [['product_component_id' => $f['componentId'], 'quantity' => '20']]];
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
