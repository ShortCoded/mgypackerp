<?php

use Illuminate\Validation\ValidationException;
use Modules\Core\Services\MenuService;
use Modules\HR\Models\HrEmployee;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesSelect2Service;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

function salesUiFixture(): array
{
    $fixture = salesCycleFixture();
    foreach (['sales_orders.view', 'sales_orders.create', 'sales_orders.edit', 'sales_orders.view_prices', 'sales_requests.view', 'sales_requests.create', 'quotations.view', 'quotations.create', 'quotations.edit', 'quotations.print'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    request()->setLaravelSession(app('session.store'));
    session(salesCycleSession($fixture));

    return $fixture;
}

function salesUiEmployee(array $fixture, int $number = 991): HrEmployee
{
    return HrEmployee::query()->create(['doc_number' => $number, 'doc_num' => 'EMP-'.$number,
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'full_name' => 'Sales Employee '.$number, 'name' => 'Sales Employee '.$number, 'status' => 'active']);
}

function salesUiQuote(array $fixture): Quotation
{
    return app(QuotationService::class)->create(['branch_id' => $fixture['branch']->id,
        'customer_doc_num' => $fixture['customer']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(),
        'exchange_rate' => 1, 'lines' => [['product_doc_num' => $fixture['finished']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 2, 'unit_price' => 10]]])['record'];
}

test('sales grids paginate and search without embedding the master dataset', function () {
    $f = salesUiFixture();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    for ($index = 0; $index < 3; $index++) {
        app(SalesOrderService::class)->create(salesCycleOrderPayload($f));
    }
    $this->get(route('admin.sales.sales-orders.create'))->assertOk()->assertDontSee($f['finished']->name)->assertSee('js-select2-ajax');
    $response = $this->getJson(route('admin.sales.sales-orders.index', ['draw' => 1, 'start' => 0, 'length' => 2]));
    $this->assertNull($response->json('error'), (string) $response->json('error'));
    $response->assertOk()->assertJsonMissingPath('error')->assertJsonCount(2, 'data')->assertJsonPath('recordsTotal', 3);
    $this->getJson(route('admin.sales.sales-orders.index', ['draw' => 2, 'search' => ['value' => 'absent-document']]))->assertJsonPath('recordsFiltered', 0);
});

test('sales employee pickers paginate and keep inactive history without accepting inactive new selections', function () {
    $f = salesUiFixture();
    $employee = salesUiEmployee($f);
    salesUiEmployee($f, 992);
    $service = app(SalesSelect2Service::class);
    expect($service->employeeId($f['company']->id, $f['branch']->id, $employee->doc_num))->toBe($employee->id);
    $employee->update(['status' => 'inactive']);
    expect(fn () => $service->employeeId($f['company']->id, $f['branch']->id, $employee->doc_num))->toThrow(ValidationException::class);
    expect($service->employeeId($f['company']->id, $f['branch']->id, $employee->doc_num, $employee->id))->toBe($employee->id);
    $this->actingAs($f['user'])->withSession(salesCycleSession($f))->getJson(route('admin.sales.select2.employees', ['per_page' => 1]))
        ->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', 'EMP-992');
});

test('sales business employee is inherited and legacy user references remain separate', function () {
    $f = salesUiFixture();
    $employee = salesUiEmployee($f);
    $quote = salesUiQuote($f);
    $quote->forceFill(['business_employee_id' => $employee->id, 'sales_person_id' => $f['user']->id])->save();
    $service = app(QuotationService::class);
    $quote = $service->accept($service->markSent($quote));
    $order = app(SalesOrderService::class)->createFromQuotation($quote, ['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id]);
    expect($order->salesEmployee->id)->toBe($employee->id)
        ->and($quote->refresh()->legacySalesUser->id)->toBe($f['user']->id)
        ->and($quote->salesPerson->id)->toBe($employee->id)
        ->and($quote->created_by)->toBe($f['user']->id);
});

test('quotation dispatch records audit while cancellation preserves history and deletion is draft only', function () {
    $f = salesUiFixture();
    $quote = salesUiQuote($f);
    $service = app(QuotationService::class);
    expect($quote->canDeleteDraft())->toBeTrue();
    $quote = $service->markSent($quote);
    expect($quote->sent_by)->toBe($f['user']->id)->and($quote->sent_at)->not->toBeNull()->and($quote->canDeleteDraft())->toBeFalse();
    expect(fn () => $service->delete($quote))->toThrow(DomainException::class);
    $quote = $service->cancel($quote);
    expect($quote->status)->toBe('cancelled')->and($quote->trashed())->toBeFalse()->and($quote->canCancel())->toBeFalse();
    expect(fn () => $service->accept($quote))->toThrow(DomainException::class);
    $draft = salesUiQuote($f);
    $service->delete($draft);
    expect($draft->refresh()->trashed())->toBeTrue();
});

test('quotation customer PDF hides empty rich terms and system audit and separates number from revision', function () {
    $f = salesUiFixture();
    $quote = salesUiQuote($f);
    $quote->forceFill(['internal_notes' => 'PRIVATE INTERNAL AUDIT'])->save();
    $quote->currentRevision->update(['warranty_terms_snapshot' => '<p><br></p>', 'payment_terms_snapshot' => '<p>Payment within 30 days</p>']);
    $f['user']->update(['locale' => 'en']);
    $response = $this->actingAs($f['user'])->withSession(salesCycleSession($f))->get(route('admin.sales.quotations.print', $quote))->assertOk();
    $text = salesPdfText($response->getContent());
    expect($text)->toContain($quote->doc_num, 'R01', 'Payment within 30 days')
        ->not->toContain($quote->doc_num.'-R01', 'PRIVATE INTERNAL AUDIT', 'Generated by', 'Prepared by', 'Warranty Terms');
    file_put_contents('/tmp/sales-ui-normalized-quotation.pdf', $response->getContent());
    $f['user']->update(['locale' => 'ar']);
    $arabic = $this->get(route('admin.sales.quotations.print', $quote))->assertOk();
    file_put_contents('/tmp/sales-ui-normalized-quotation-ar.pdf', $arabic->getContent());
});

test('sales request customer type requires customer and internal type uses separate business employee', function () {
    $f = salesUiFixture();
    $employee = salesUiEmployee($f);
    $payload = ['request_type' => 'customer', 'request_date' => now()->toDateString(), 'priority' => 'normal', 'exchange_rate' => 1,
        'currency_doc_num' => $f['currency']->doc_num, 'sales_employee_doc_num' => $employee->doc_num,
        'lines' => [['product_doc_num' => $f['finished']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => 2, 'unit_price' => 5]]];
    $this->actingAs($f['user'])->withSession(salesCycleSession($f))->postJson(route('admin.sales.customer-requests.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('customer_doc_num');
    $this->postJson(route('admin.sales.customer-requests.store'), [...$payload, 'request_type' => 'internal'])->assertSuccessful();
    $request = SalesRequest::query()->latest('id')->firstOrFail();
    expect($request->customer_id)->toBeNull()->and($request->sales_employee_id)->toBeNull()->and($request->business_employee_id)->toBe($employee->id);
    $this->get(route('admin.sales.customer-requests.create'))->assertOk()->assertDontSee($f['finished']->name);
});

test('sales navigation is one ordered journey with canonical statement and collection links', function () {
    $f = salesUiFixture();
    foreach (['customers.view', 'sales_deliveries.view', 'customer_invoices.view', 'sales_returns.view', 'customer_receipts.view', 'reports.customer_statement.view', 'reports.sales.sales_orders.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $menu = app(MenuService::class)->getMenu($f['user']);
    $sales = collect($menu)->firstWhere('label', 'sales');
    expect(collect($sales['children'])->pluck('label')->all())->toBe(['customers', 'sales_requests', 'quotations', 'sales_orders', 'deliveries', 'sales_invoices', 'sales_returns', 'customer_collections', 'customer_statement', 'reports_sales_sales_orders']);
    foreach ($sales['children'] as $item) {
        expect($item['children'] ?? [])->toBeEmpty();
    }
});

test('quotation index exposes only permitted state actions and filters the requested state', function () {
    $f = salesUiFixture();
    foreach (['quotations.mark_sent', 'quotations.accept', 'quotations.reject', 'quotations.cancel', 'quotations.delete', 'quotations.clone', 'quotations.revisions.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $quote = salesUiQuote($f);
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    $actions = fn () => $this->getJson(route('admin.sales.quotations.data', ['draw' => 1, 'length' => 10]))->assertOk()->json('data.0.actions');
    expect($actions())->toContain('/mark-sent', '/edit')->not->toContain('/accept');
    $service = app(QuotationService::class);
    $quote = $service->markSent($quote);
    expect($actions())->toContain('/accept', '/reject', '/revisions')->not->toContain('/mark-sent', 'js-delete-record', 'js-edit-record');
    $this->getJson(route('admin.sales.quotations.data', ['draw' => 1, 'status' => 'draft']))->assertJsonPath('recordsFiltered', 0);
    $quote = $service->accept($quote);
    expect($actions())->toContain('#quotation-conversion')->not->toContain('/mark-sent', '/accept', '/reject');
    $service->cancel($quote);
    expect($actions())->toContain('/print', '/clone')->not->toContain('#quotation-conversion', '/cancel', '/mark-sent', 'js-delete-record');
});
