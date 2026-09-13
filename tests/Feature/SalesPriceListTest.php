<?php

use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\PriceList;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\PriceListPricingService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

/**
 * @return array<string, mixed>
 */
function priceListDataTableQuery(string $trashFilter = 'active'): array
{
    $columns = [
        ['data' => 'checkbox', 'name' => 'checkbox', 'searchable' => 'false', 'orderable' => 'false'],
        ['data' => 'doc_num', 'name' => 'price_lists.doc_number', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'scope', 'name' => 'scope', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'currency', 'name' => 'currency', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'price_list_date', 'name' => 'price_list_date', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'valid_from', 'name' => 'valid_from', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'valid_until', 'name' => 'valid_until', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'lines_count', 'name' => 'lines_count', 'searchable' => 'false', 'orderable' => 'true'],
        ['data' => 'created_by', 'name' => 'created_by', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'updated_by', 'name' => 'updated_by', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'updated_at', 'name' => 'updated_at', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'deleted_by', 'name' => 'deleted_by', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'deleted_at', 'name' => 'deleted_at', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'actions', 'name' => 'actions', 'searchable' => 'false', 'orderable' => 'false'],
    ];

    return [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => '', 'regex' => 'false'],
        'order' => [['column' => 1, 'dir' => 'desc']],
        'columns' => array_map(fn (array $column): array => [...$column, 'search' => ['value' => '', 'regex' => 'false']], $columns),
        'trash_filter' => $trashFilter,
    ];
}

test('pricing resolves the latest list per product with customer priority and general fallback', function (): void {
    $fixture = salesCycleFixture();
    createSalesPriceList($fixture, null, [
        ['product' => $fixture['finished'], 'price' => '10'],
        ['product' => $fixture['service'], 'price' => '50'],
    ], '2026-01-01');
    $latestGeneral = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '12']], '2026-06-01');
    $customerList = createSalesPriceList($fixture, $fixture['customer']->id, [[
        'product' => $fixture['service'], 'price' => '70', 'discount_type' => 'percentage', 'discount_value' => '10',
    ]], '2026-03-01');

    $pricing = app(PriceListPricingService::class);
    $finished = $pricing->resolve($fixture['company']->id, $fixture['customer']->id, $fixture['currency']->id, $fixture['finished'], $fixture['unit']->id, 2, '2026-09-01');
    $service = $pricing->resolve($fixture['company']->id, $fixture['customer']->id, $fixture['currency']->id, $fixture['service'], $fixture['unit']->id, 2, '2026-09-01');

    expect($finished['unit_price'])->toBe('12.0000')->and($finished['price_list_doc_num'])->toBe($latestGeneral->doc_num)->and($finished['source'])->toBe('general')
        ->and($service['unit_price'])->toBe('70.0000')->and($service['price_list_doc_num'])->toBe($customerList->doc_num)->and($service['source'])->toBe('customer')
        ->and($service['maximum_discount_amount'])->toBe('14.0000');
});

test('an unpriced product blocks the whole document and discount cannot exceed the list limit', function (): void {
    $fixture = salesCycleFixture();
    createSalesPriceList($fixture, null, [[
        'product' => $fixture['finished'], 'price' => '25', 'discount_type' => 'fixed', 'discount_value' => '2',
    ]]);
    $pricing = app(PriceListPricingService::class);

    expect(fn () => $pricing->applyToLines([
        ['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => 2, 'discount_amount' => 0],
        ['product_id' => $fixture['service']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => 1, 'discount_amount' => 0],
    ], $fixture['company']->id, $fixture['customer']->id, $fixture['currency']->id, now()->toDateString(), 'amount'))->toThrow(DomainException::class, 'Delivery Service');

    expect(fn () => $pricing->applyToLines([
        ['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => 2, 'discount_amount' => 5],
    ], $fixture['company']->id, $fixture['customer']->id, $fixture['currency']->id, now()->toDateString(), 'amount'))->toThrow(DomainException::class, '4.0000');
});

test('direct invoice ignores a submitted price and stores the price-list snapshot', function (): void {
    $fixture = salesCycleFixture();
    $list = createSalesPriceList($fixture, null, [[
        'product' => $fixture['finished'], 'price' => '25', 'discount_type' => 'percentage', 'discount_value' => '10',
    ]]);
    $invoice = app(CustomerInvoiceService::class)->createDirect([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'customer_doc_num' => $fixture['customer']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'exchange_rate' => 1,
        'lines' => [['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 2, 'unit_price' => 999, 'discount_amount' => 5, 'tax_amount' => 0]],
    ]);
    $line = $invoice->lines->sole();

    expect($invoice)->toBeInstanceOf(CustomerInvoice::class)->and($line->unit_price)->toBe('25.0000')
        ->and($line->price_list_line_id)->toBe($list->lines()->sole()->id)
        ->and($line->allowed_discount_type)->toBe('percentage')->and($line->allowed_discount_value)->toBe('10.0000');
});

test('direct sales order blocks all unpriced products and ignores a submitted price when priced', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('sales_orders.create', 'web');
    $fixture['user']->givePermissionTo('sales_orders.create');
    $session = salesCycleSession($fixture);
    $payload = [
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addWeek()->toDateString(),
        'lines' => [
            ['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 2, 'unit_price' => 999, 'discount_amount' => 0, 'tax_amount' => 0],
            ['product_doc_num' => $fixture['service']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 1, 'unit_price' => 999, 'discount_amount' => 0, 'tax_amount' => 0],
        ],
    ];

    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.sales.sales-orders.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, $fixture['finished']->name) && str_contains($message, $fixture['service']->name));
    expect(SalesOrder::query()->count())->toBe(0);

    $list = createSalesPriceList($fixture, null, [[
        'product' => $fixture['finished'], 'price' => '25', 'discount_type' => 'percentage', 'discount_value' => '10',
    ]]);
    $payload['lines'] = [$payload['lines'][0]];
    $payload['lines'][0]['discount_amount'] = 5;
    $response = $this->postJson(route('admin.sales.sales-orders.store'), $payload)->assertCreated();
    $order = SalesOrder::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($order->lines->sole()->unit_price)->toBe('25.0000')
        ->and($order->lines->sole()->price_list_line_id)->toBe($list->lines()->sole()->id)
        ->and($order->lines->sole()->allowed_discount_value)->toBe('10.0000');
});

test('sales request stores quantities without accepting or rendering a price', function (): void {
    $fixture = salesCycleFixture();
    foreach (['sales_requests.view', 'sales_requests.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $session = salesCycleSession($fixture);
    $payload = [
        'request_type' => 'customer',
        'request_date' => now()->toDateString(),
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'lines' => [[
            'product_doc_num' => $fixture['finished']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'unit_price' => 999,
        ]],
    ];

    $response = $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.sales.customer-requests.store'), $payload)->assertOk();
    $request = SalesRequest::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    expect($request->lines->sole()->unit_price)->toBeNull();

    $form = $this->get(route('admin.sales.customer-requests.create'))->assertOk();
    expect($form->getContent())->not->toContain('[unit_price]', 'data-sales-summary-subtotal', 'data-sales-summary-total');
});

test('general price list is created with automatic code and an optional open end date', function (): void {
    $fixture = salesCycleFixture();
    foreach (['price_lists.view', 'price_lists.create', 'price_lists.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $session = salesCycleSession($fixture);
    $payload = [
        'customer_doc_num' => null,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'price_list_date' => '2026-09-13',
        'valid_from' => '2026-09-13',
        'valid_until' => null,
        'lines' => [[
            'product_doc_num' => $fixture['finished']->doc_num,
            'unit_price' => 25,
            'allowed_discount_type' => 'fixed',
            'allowed_discount_value' => 2,
        ]],
    ];

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.sales.price-lists.store'), $payload)->assertRedirect();
    $priceList = PriceList::query()->sole();
    $lineId = $priceList->lines()->sole()->id;
    expect($priceList->doc_num)->toBe('PL-00001')
        ->and($priceList->customer_id)->toBeNull()
        ->and($priceList->valid_until)->toBeNull();

    $payload['lines'][0]['unit_price'] = 30;
    $this->put(route('admin.sales.price-lists.update', $priceList), $payload)->assertRedirect();
    expect($priceList->lines()->sole()->id)->toBe($lineId)
        ->and($priceList->lines()->sole()->unit_price)->toBe('30.0000');
});

test('price list screen and pricing coverage report are available in sales', function (): void {
    $fixture = salesCycleFixture();
    foreach (['price_lists.view', 'price_lists.create', 'reports.sales.sales_orders.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $session = salesCycleSession($fixture);

    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.price-lists.create'))
        ->assertOk()->assertSee('data-price-list-form', false)->assertDontSee('exchange_rate');
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.reports.sales.sales-orders.index', ['report' => 'pricing']))
        ->assertOk()->assertSee('Unpriced Products')->assertSee('Customers Without Dedicated Price Lists');

    expect(config('menu_sections.leaf_order.sales'))->toContain('customer_terms', 'price_lists')
        ->and(array_search('price_lists', config('menu_sections.leaf_order.sales'), true))->toBe(array_search('customer_terms', config('menu_sections.leaf_order.sales'), true) + 1);
});

test('price list permissions are seeded for admin and expose the screen without manual grants', function (): void {
    $fixture = salesCycleFixture();
    $this->seed(PermissionSeeder::class);

    $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    foreach (['price_lists.view', 'price_lists.create', 'price_lists.edit', 'price_lists.delete', 'price_lists.view_trashed', 'price_lists.restore'] as $permission) {
        expect(Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists())->toBeTrue()
            ->and($admin->hasPermissionTo($permission))->toBeTrue();
    }

    $fixture['user']->assignRole($admin);
    $page = $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.sales.price-lists.index'))
        ->assertOk();

    expect($page->getContent())->toContain(__('price_lists.title'));
});

test('sales documents display resolved prices as read only values and never say automatic on save', function (): void {
    $fixture = salesCycleFixture();
    createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '25']]);
    foreach (['quotations.create', 'sales_orders.create', 'customer_invoices.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    foreach ([
        route('admin.sales.quotations.create'),
        route('admin.sales.sales-orders.create'),
        route('admin.sales.sales-invoices.create', ['direct' => 1]),
    ] as $url) {
        $this->get($url)->assertOk()
            ->assertSee('data-price-display', false)
            ->assertDontSee('تلقائي عند الحفظ')
            ->assertDontSee('Automatic on save');
    }

    $query = [
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'product_doc_num' => $fixture['finished']->doc_num,
        'unit_doc_num' => $fixture['unit']->doc_num,
        'quantity' => 2,
        'document_date' => now()->toDateString(),
    ];
    $this->getJson(route('admin.sales.price-suggestion', $query))
        ->assertOk()
        ->assertJsonPath('data.unit_price', '25.0000')
        ->assertJsonPath('data.scope', 'general')
        ->assertJsonPath('data.maximum_discount_amount', '0.0000');
});

test('price lists use the standard data table with audited soft delete bulk delete and restore', function (): void {
    $fixture = salesCycleFixture();
    foreach (['price_lists.view', 'price_lists.create', 'price_lists.edit', 'price_lists.delete', 'price_lists.view_trashed', 'price_lists.restore'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $priceList = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '25']]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $this->get(route('admin.sales.price-lists.index'))
        ->assertOk()
        ->assertSee('js-price-lists-table', false)
        ->assertSee('price_lists_trash_filter', false)
        ->assertSee(route('admin.sales.price-lists.data'), false);

    $this->getJson(route('admin.sales.price-lists.data', priceListDataTableQuery()))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 1)
        ->assertJsonPath('data.0.lines_count', '1')
        ->assertJsonPath('data.0.scope', fn (string $scope): bool => str_contains($scope, __('price_lists.general')));

    $this->deleteJson(route('admin.sales.price-lists.destroy', $priceList))->assertOk()->assertJsonPath('success', true);
    expect($priceList->refresh()->trashed())->toBeTrue()
        ->and($priceList->deleted_by)->toBe($fixture['user']->getKey());

    $this->get(route('admin.sales.price-lists.show', $priceList))->assertOk();
    $this->getJson(route('admin.sales.price-lists.data', priceListDataTableQuery('trashed')))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 1)
        ->assertJsonPath('data.0.actions', fn (string $actions): bool => str_contains($actions, 'js-restore-record'));

    $this->patchJson(route('admin.sales.price-lists.restore', $priceList))->assertOk()->assertJsonPath('success', true);
    expect($priceList->refresh()->trashed())->toBeFalse()
        ->and($priceList->restored_by)->toBe($fixture['user']->getKey())
        ->and($priceList->restored_at)->not->toBeNull();

    $second = createSalesPriceList($fixture, $fixture['customer']->getKey(), [['product' => $fixture['service'], 'price' => '50']]);
    $this->deleteJson(route('admin.sales.price-lists.bulk-delete'), ['doc_nums' => [$priceList->doc_num, $second->doc_num]])
        ->assertOk()
        ->assertJsonPath('data.deleted', 2);
    expect(PriceList::onlyTrashed()->count())->toBe(2);
});

test('price list create and edit forms expose and honor the standard save destinations', function (): void {
    $fixture = salesCycleFixture();
    foreach (['price_lists.view', 'price_lists.create', 'price_lists.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $this->get(route('admin.sales.price-lists.create'))
        ->assertOk()
        ->assertSee(__('common.actions.save_data'))
        ->assertSee(__('common.actions.save_and_view'))
        ->assertSee(__('common.actions.save_and_edit'))
        ->assertSee(__('common.actions.save_and_back'))
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertSee('data-submit-action="save_new"', false);

    $payload = [
        'customer_doc_num' => null,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'price_list_date' => '2026-09-13',
        'valid_from' => '2026-09-13',
        'valid_until' => null,
        'submit_action' => 'save_edit',
        'lines' => [[
            'product_doc_num' => $fixture['finished']->doc_num,
            'unit_price' => 25,
            'allowed_discount_type' => null,
            'allowed_discount_value' => 0,
        ]],
    ];

    $response = $this->post(route('admin.sales.price-lists.store'), $payload);
    $priceList = PriceList::query()->sole();
    $response->assertRedirect(route('admin.sales.price-lists.edit', $priceList));

    foreach ([
        'save' => route('admin.sales.price-lists.edit', $priceList),
        'save_view' => route('admin.sales.price-lists.show', $priceList),
        'save_back' => route('admin.sales.price-lists.index'),
    ] as $action => $expectedRedirect) {
        $payload['submit_action'] = $action;
        $this->put(route('admin.sales.price-lists.update', $priceList), $payload)->assertRedirect($expectedRedirect);
    }

    $payload['submit_action'] = 'save_new';
    $this->post(route('admin.sales.price-lists.store'), $payload)
        ->assertRedirect(route('admin.sales.price-lists.create'));
});
