<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Services\ProductionMaterialRequestService;
use Modules\Purchases\Models\PurchaseRequisition;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';

test('shortage procurement is explicit scoped permission guarded and retry safe', function (): void {
    test()->travelTo('2026-09-28 10:00:00');
    $f = manufacturingInventoryFixture('-MANUAL-PURCHASE', isolatedCompany: DB::getDriverName() === 'pgsql');
    $f['branch']->update(['type' => Branch::TypeFactory]);
    $run = manufacturingIntegrityRun($f)['run'];
    InventoryTransaction::query()->where('product_id', $f['raw']->id)->update(['quantity_in' => '1', 'total_cost' => '2']);
    foreach (['production.material_requests.view', 'production.material_requests.approve', 'purchases.purchase_requisitions.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $this->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));
    $service = app(ProductionMaterialRequestService::class);
    $request = $service->create($run, $f['store']->id, [$run->requirements->sole()->id => '2']);
    expect(PurchaseRequisition::query()->where('company_id', $f['company']->id)->count())->toBe(0);
    $request = $service->approve($request);
    expect($request->status)->toBe(ProductionMaterialRequest::StatusShortage)->and($request->purchase_requisition_id)->toBeNull()
        ->and($request->lines->sole()->reserved_quantity)->toBe('1.00000000')->and(PurchaseRequisition::query()->where('company_id', $f['company']->id)->count())->toBe(0);
    $service->allocateShortage($request);
    expect(PurchaseRequisition::query()->where('company_id', $f['company']->id)->count())->toBe(0);
    $url = route('admin.production.material-requests.purchase-requisition', $request);
    $f['user']->revokePermissionTo('purchases.purchase_requisitions.create');
    $this->postJson($url, ['_submission_token' => (string) Str::uuid()])->assertForbidden();
    expect(fn () => $service->createPurchaseRequisition($request))->toThrow(AuthorizationException::class);
    $f['user']->givePermissionTo('purchases.purchase_requisitions.create');
    $token = (string) Str::uuid();
    $this->postJson($url, ['_submission_token' => $token])->assertOk();
    $purchase = PurchaseRequisition::query()->where('company_id', $f['company']->id)->with('lines')->sole();
    expect($request->fresh()->purchase_requisition_id)->toBe($purchase->id)->and($purchase->company_id)->toBe($f['company']->id)
        ->and($purchase->lines->sole()->requested_quantity)->toBe('1.00000000')
        ->and($purchase->lines->sole()->source_doc_num)->toBe($run->order->doc_num)
        ->and($purchase->notes)->toContain($request->doc_num);
    $before = $purchase->fresh()->attributesToArray();
    $this->postJson($url, ['_submission_token' => $token])->assertOk();
    $this->postJson($url, ['_submission_token' => (string) Str::uuid()])->assertOk();
    expect(PurchaseRequisition::query()->where('company_id', $f['company']->id)->count())->toBe(1)->and($purchase->fresh()->attributesToArray())->toBe($before);
    foreach (['en', 'ar'] as $locale) {
        $this->withSession(['locale' => $locale]);
        app()->setLocale($locale);
        $this->get(route('admin.production.material-requests.show', $request))->assertOk()->assertSee($purchase->doc_num)->assertSee(__('production_execution.manual_purchase_linked'));
    }
    request()->session()->put(OperatingContextService::BranchIdKey, 999999);
    expect(fn () => $service->createPurchaseRequisition($request))->toThrow(DomainException::class);
});

test('an approved request with sufficient stock cannot generate procurement', function (): void {
    test()->travelTo('2026-09-28 10:00:00');
    $f = manufacturingInventoryFixture('-MANUAL-PURCHASE', isolatedCompany: DB::getDriverName() === 'pgsql');
    $f['branch']->update(['type' => Branch::TypeFactory]);
    $run = manufacturingIntegrityRun($f)['run'];
    foreach (['production.material_requests.view', 'purchases.purchase_requisitions.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $this->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));
    $service = app(ProductionMaterialRequestService::class);
    $request = $service->approve($service->create($run, $f['store']->id, [$run->requirements->sole()->id => '2']));
    expect(fn () => $service->createPurchaseRequisition($request))->toThrow(DomainException::class);
    expect(PurchaseRequisition::query()->where('company_id', $f['company']->id)->count())->toBe(0);
});
