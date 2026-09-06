<?php

use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

function salesIndexFixture(): array
{
    $fixture = salesCycleFixture();
    foreach (['sales_requests.view', 'sales_requests.create', 'sales_requests.edit', 'sales_requests.approve', 'sales_requests.print', 'sales_requests.delete', 'sales_requests.restore', 'sales_requests.view_trashed', 'sales_orders.view', 'sales_orders.view_prices', 'sales_orders.edit', 'sales_orders.approve', 'sales_orders.print', 'sales_orders.delete', 'sales_orders.restore', 'sales_orders.view_trashed'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    request()->setLaravelSession(app('session.store'));
    session(salesCycleSession($fixture));

    return $fixture;
}

function salesIndexRequest(array $fixture): SalesRequest
{
    return app(SalesRequestService::class)->save(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'request_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->id,
        'lines' => [['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => 2, 'unit_price' => 12.5]]]);
}

test('request index offers valid workflow and print actions and supports draft recovery', function () {
    $f = salesIndexFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    $record = salesIndexRequest($f);
    $url = route('admin.sales.customer-requests.index', ['draw' => 1]);
    $actions = $this->getJson($url)->assertOk()->assertJsonMissingPath('error')->json('data.0.actions');
    expect($this->getJson($url)->json('data.0.amount'))->toBe('25');
    expect($actions)->toContain('/print', 'data-status="submitted"', 'data-method="DELETE"')->not->toContain('data-status="approved"');
    $this->deleteJson(route('admin.sales.customer-requests.destroy', $record))->assertOk();
    $this->getJson($url)->assertJsonCount(0, 'data');
    $trashed = $this->getJson(route('admin.sales.customer-requests.index', ['draw' => 1, 'trash' => 'trashed']))->assertOk();
    expect($trashed->json('data.0.actions'))->toContain('/restore')->not->toContain('/print', '/transition');
    $this->patchJson(route('admin.sales.customer-requests.restore', $record->doc_num))->assertOk();
    expect($record->fresh()->trashed())->toBeFalse();
    app(SalesRequestService::class)->transition($record, 'submitted');
    expect($this->getJson($url)->json('data.0.actions'))->toContain('data-status="approved"')->not->toContain('data-method="DELETE"');
    $this->deleteJson(route('admin.sales.customer-requests.destroy', $record))->assertConflict();
});

test('trash access is permission guarded and document filtering remains server side', function () {
    $f = salesIndexFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    salesIndexRequest($f);
    $this->getJson(route('admin.sales.customer-requests.index', ['draw' => 1, 'document' => 'not-a-document']))->assertJsonCount(0, 'data');
    $f['user']->revokePermissionTo('sales_requests.view_trashed');
    $this->getJson(route('admin.sales.customer-requests.index', ['draw' => 1, 'trash' => 'trashed']))->assertForbidden();
});

test('order draft recovery preserves lines and rejects non draft deletion', function () {
    $f = salesIndexFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    $service = app(SalesOrderService::class);
    $order = $service->create(salesCycleOrderPayload($f));
    $lineIds = $order->lines()->pluck('id')->all();
    $actions = $this->getJson(route('admin.sales.sales-orders.index', ['draw' => 1]))->assertOk()->assertJsonMissingPath('error')->json('data.0.actions');
    expect($actions)->toContain('/submit', '/approve', '/print', 'data-method="DELETE"');
    $service->delete($order);
    expect($order->fresh()->trashed())->toBeTrue();
    $restored = $service->restore($order);
    expect($restored->lines()->pluck('id')->all())->toBe($lineIds);
    $service->submit($restored);
    expect(fn () => $service->delete($restored))->toThrow(DomainException::class);
});
