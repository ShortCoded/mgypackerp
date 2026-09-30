<?php

use Dom\HTMLDocument;
use Modules\Production\Models\ProductionOrder;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

function salesIndexFixture(): array
{
    $fixture = salesCycleFixture();
    foreach (['sales_requests.view', 'sales_requests.create', 'sales_requests.edit', 'sales_requests.approve', 'sales_requests.cancel', 'sales_requests.reopen', 'sales_requests.print', 'sales_requests.delete', 'sales_requests.restore', 'sales_requests.view_trashed', 'sales_orders.view', 'sales_orders.view_prices', 'sales_orders.edit', 'sales_orders.approve', 'sales_orders.print', 'sales_orders.delete', 'sales_orders.restore', 'sales_orders.view_trashed', 'tools.open_documents.view', 'tools.open_documents.execute'] as $permission) {
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

test('operational sales report opens a pending request through its current route', function () {
    $fixture = salesIndexFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $request = salesIndexRequest($fixture);
    $fixture['user']->givePermissionTo(Permission::findOrCreate('reports.sales.operational.view', 'web'));
    $reportUrl = route('admin.reports.sales.sales-orders.index', ['report' => 'operational']);

    $this->get($reportUrl)
        ->assertOk()
        ->assertSee(route('admin.sales.customer-requests.show', $request), false);

    $fixture['user']->revokePermissionTo('sales_requests.view');
    $this->get($reportUrl)
        ->assertOk()
        ->assertSee($request->doc_num)
        ->assertDontSee(route('admin.sales.customer-requests.show', $request), false);
});

test('every sales analysis menu report renders with a pending request', function () {
    $fixture = salesIndexFixture();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    salesIndexRequest($fixture);

    foreach (['financial', 'period', 'customers', 'products', 'invoices', 'receivables', 'collections', 'returns', 'quotations', 'fulfillment', 'pricing', 'operational', 'cost_of_sales'] as $report) {
        $fixture['user']->givePermissionTo(Permission::findOrCreate("reports.sales.{$report}.view", 'web'));
        $this->get(route('admin.reports.sales.sales-orders.index', ['report' => $report]))->assertOk();
    }
});

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
        ->toContain('data-status="closed"', 'tools/open-documents', 'document_type=sales_requests', 'data-reason="1"')
        ->not->toContain('data-status="approved"', 'data-status="cancelled"', 'data-method="DELETE"');
    $this->deleteJson(route('admin.sales.customer-requests.destroy', $record))->assertConflict();
});

test('request index exposes reopened workflow and hides reopen for historical downstream lineage', function () {
    $f = salesIndexFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    $record = salesIndexRequest($f);
    $service = app(SalesRequestService::class);
    $service->transition($record, 'submitted');
    $service->transition($record->fresh(), 'approved');
    $service->reopen($record->fresh(), 'Index amendment check.');

    $reopenedUrl = route('admin.sales.customer-requests.index', ['draw' => 1, 'status' => 'reopened', 'document' => $record->doc_num]);
    $reopened = $this->getJson($reopenedUrl)->assertOk()->json('data.0.actions');
    expect($reopened)
        ->toContain('data-status="submitted"')
        ->not->toContain('data-status="cancelled"', 'document_type=sales_requests');

    $service->transition($record->fresh(), 'submitted');
    $service->transition($record->fresh(), 'approved');
    app(SalesOrderService::class)->create(salesCycleOrderPayload($f, ['sales_request_id' => $record->getKey()]))->delete();

    $approvedUrl = route('admin.sales.customer-requests.index', ['draw' => 1, 'status' => 'approved', 'document' => $record->doc_num]);
    expect($this->getJson($approvedUrl)->assertOk()->json('data.0.actions'))->not->toContain('document_type=sales_requests');

    $invoiceRecord = salesIndexRequest($f);
    $service->transition($invoiceRecord, 'submitted');
    $service->transition($invoiceRecord->fresh(), 'approved');
    CustomerInvoice::query()->create([
        'doc_number' => 99002,
        'doc_num' => 'INV-INDEX-HISTORICAL',
        'company_id' => $f['company']->getKey(),
        'financial_period_id' => $f['period']->getKey(),
        'branch_id' => $f['branch']->getKey(),
        'customer_id' => $f['customer']->getKey(),
        'currency_id' => $f['currency']->getKey(),
        'invoice_date' => now()->toDateString(),
        'source_type' => 'sales_request',
        'source_id' => $invoiceRecord->getKey(),
        'source_doc_num' => $invoiceRecord->doc_num,
    ])->delete();
    $invoiceUrl = route('admin.sales.customer-requests.index', ['draw' => 1, 'status' => 'approved', 'document' => $invoiceRecord->doc_num]);
    expect($this->getJson($invoiceUrl)->assertOk()->json('data.0.actions'))->not->toContain('document_type=sales_requests');

    $convertedRecord = salesIndexRequest($f);
    $service->transition($convertedRecord, 'submitted');
    $service->transition($convertedRecord->fresh(), 'approved');
    $convertedRecord->lines()->update(['converted_quantity' => '1']);
    $convertedUrl = route('admin.sales.customer-requests.index', ['draw' => 1, 'status' => 'approved', 'document' => $convertedRecord->doc_num]);
    expect($this->getJson($convertedUrl)->assertOk()->json('data.0.actions'))->not->toContain('document_type=sales_requests');

    $this->get(route('admin.sales.customer-requests.index'))
        ->assertOk()
        ->assertSee('value="reopened"', false)
        ->assertSee(__('Reopened'));
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

test('sales order row hides cancel and reopen after a historical production document', function () {
    $fixture = salesIndexFixture();
    $fixture['user']->givePermissionTo([
        Permission::findOrCreate('sales_orders.cancel', 'web'),
        Permission::findOrCreate('sales_orders.reopen', 'web'),
    ]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $service = app(SalesOrderService::class);
    $order = $service->approve($service->create(salesCycleOrderPayload($fixture)));
    $url = route('admin.sales.sales-orders.index', ['draw' => 1, 'document' => $order->doc_num]);
    expect($this->getJson($url)->assertOk()->json('data.0.actions'))
        ->toContain('document_type=sales_orders', '/cancel');

    $order->productionOrders()->create([
        'doc_number' => 99003,
        'doc_num' => 'PROD-INDEX-HISTORICAL',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'production_order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->toDateString(),
        'status' => ProductionOrder::StatusCancelled,
    ])->delete();

    expect($this->getJson($url)->assertOk()->json('data.0.actions'))
        ->not->toContain('document_type=sales_orders', '/cancel');
});
