<?php

use Dom\HTMLDocument;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

function salesIndexFixture(): array
{
    $fixture = salesCycleFixture();
    foreach (['sales_requests.view', 'sales_requests.create', 'sales_requests.edit', 'sales_requests.approve', 'sales_requests.cancel', 'sales_requests.print', 'sales_requests.delete', 'sales_requests.restore', 'sales_requests.view_trashed', 'sales_orders.view', 'sales_orders.view_prices', 'sales_orders.edit', 'sales_orders.approve', 'sales_orders.print', 'sales_orders.delete', 'sales_orders.restore', 'sales_orders.view_trashed'] as $permission) {
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
    $this->get(route('admin.sales.customer-requests.index'))
        ->assertOk()
        ->assertSee('vendors/sweetalert2/sweetalert2.all.min.js', false);
    $url = route('admin.sales.customer-requests.index', ['draw' => 1]);
    $actions = $this->getJson($url)->assertOk()->assertJsonMissingPath('error')->json('data.0.actions');
    expect($this->getJson($url)->json('data.0.amount'))->toBe('0');
    expect($actions)
        ->toContain('/print', 'data-status="submitted"', 'data-status="cancelled"', 'data-reason="1"', 'data-method="DELETE"', 'dropdown-caret-none')
        ->not->toContain('data-status="approved"');
    $this->deleteJson(route('admin.sales.customer-requests.destroy', $record))->assertOk();
    $this->getJson($url)->assertJsonCount(0, 'data');
    $trashed = $this->getJson(route('admin.sales.customer-requests.index', ['draw' => 1, 'trash' => 'trashed']))->assertOk();
    expect($trashed->json('data.0.actions'))->toContain('/restore')->not->toContain('/print', '/transition');
    $this->patchJson(route('admin.sales.customer-requests.restore', $record->doc_num))->assertOk();
    expect($record->fresh()->trashed())->toBeFalse();
    $this->postJson(route('admin.sales.customer-requests.transition', $record), ['status' => 'submitted'])
        ->assertOk()
        ->assertJsonPath('message', __('Saved successfully.'));
    expect($record->fresh()->status)->toBe('submitted');
    $this->postJson(route('admin.sales.customer-requests.transition', $record), ['status' => 'submitted'])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('This sales request status transition is not allowed.'));
    expect($record->fresh()->status_history)->toHaveCount(1);
    expect($this->getJson($url)->json('data.0.actions'))
        ->toContain('data-status="approved"', 'data-status="rejected"', 'data-status="cancelled"', 'data-reason="1"')
        ->not->toContain('data-method="DELETE"');
    $this->postJson(route('admin.sales.customer-requests.transition', $record), ['status' => 'approved'])->assertOk();
    expect($record->fresh()->status)->toBe('approved');
    expect($this->getJson($url)->json('data.0.actions'))
        ->toContain('data-status="closed"', 'data-status="cancelled"', 'data-reason="1"')
        ->not->toContain('data-status="approved"', 'data-method="DELETE"');
    $this->deleteJson(route('admin.sales.customer-requests.destroy', $record))->assertConflict();
});

test('sales index workflow binding remains active when the shared datatable initialized first', function () {
    $script = file_get_contents(public_path('assets/js/modules/Sales/sales-index.js'));

    expect($script)
        ->toContain('$.fn.DataTable.isDataTable(table[0])')
        ->toContain("$(document).off('click.salesIndex', '.js-sales-index-action').on('click.salesIndex'")
        ->toContain("button.data('processing', true).prop('disabled', true)")
        ->not->toContain('if (!table.length || $.fn.DataTable.isDataTable(table[0])) return;');
});

test('request create keeps its optional delivery date empty', function () {
    $f = salesIndexFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));

    $response = $this->get(route('admin.sales.customer-requests.create'))->assertOk();
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);

    expect($document->getElementById('required_delivery_date')?->getAttribute('value'))->toBe('');
});

test('trash access is permission guarded and document filtering remains server side', function () {
    $f = salesIndexFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    salesIndexRequest($f);
    $this->getJson(route('admin.sales.customer-requests.index', ['draw' => 1, 'document' => 'not-a-document']))->assertJsonCount(0, 'data');
    $f['user']->revokePermissionTo('sales_requests.view_trashed');
    $this->getJson(route('admin.sales.customer-requests.index', ['draw' => 1, 'trash' => 'trashed']))->assertForbidden();
});

test('request workflow actions and endpoints enforce approve and cancel permissions', function () {
    $f = salesIndexFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    $record = salesIndexRequest($f);
    app(SalesRequestService::class)->transition($record, 'submitted');
    $f['user']->revokePermissionTo(['sales_requests.approve', 'sales_requests.cancel']);

    $actions = $this->getJson(route('admin.sales.customer-requests.index', ['draw' => 1]))
        ->assertOk()
        ->json('data.0.actions');

    expect($actions)
        ->not->toContain('data-status="approved"', 'data-status="rejected"', 'data-status="cancelled"');
    $this->postJson(route('admin.sales.customer-requests.transition', $record), ['status' => 'approved'])
        ->assertForbidden();
    $this->postJson(route('admin.sales.customer-requests.transition', $record), [
        'status' => 'cancelled',
        'reason' => 'Not approved for cancellation.',
    ])->assertForbidden();
    expect($record->fresh()->status)->toBe('submitted');
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
