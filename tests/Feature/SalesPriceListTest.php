<?php

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Modules\Sales\Exports\PriceListExport;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\PriceList;
use Modules\Sales\Models\PriceListLine;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\PriceListPricingService;
use Modules\Sales\Services\PriceListReportData;
use Modules\Sales\Services\PriceListService;
use Modules\Sales\Services\SalesAmountService;
use Spatie\Activitylog\Models\Activity;
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
        ['data' => 'pricing_use', 'name' => 'pricing_use', 'searchable' => 'true', 'orderable' => 'true'],
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

/** @return array<string, mixed> */
function priceListStorePayload(array $fixture, array $lines): array
{
    return [
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'price_list_date' => '2026-09-19',
        'valid_from' => '2026-09-19',
        'valid_until' => '2026-12-31',
        'notes' => 'Wave 1A price list',
        'lines' => $lines,
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
    foreach (['sales_orders.create', 'sales_orders.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
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

    $list->update(['is_print_only' => true]);
    $payload['lines'][0]['unit_price'] = 999;
    $this->putJson(route('admin.sales.sales-orders.update', $order), $payload)->assertOk();
    expect($order->fresh()->lines->sole()->unit_price)->toBe('25.0000')
        ->and($order->fresh()->lines->sole()->price_list_line_id)->toBe($list->lines()->sole()->id);
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
        ->and(config('menu_sections.leaf_subgroups.sales_report_financial'))->toBe('financial_analysis_reports')
        ->and(config('menu_sections.leaf_subgroups.sales_report_receivables'))->toBe('financial_analysis_reports')
        ->and(array_search('price_lists', config('menu_sections.leaf_order.sales'), true))->toBe(array_search('customer_terms', config('menu_sections.leaf_order.sales'), true) + 1);

    expect(app(MenuService::class)->navigationStructure())->not->toBeEmpty();
});

test('price list select2 is permission guarded and company scoped for approved consumers', function (): void {
    $fixture = salesCycleFixture();
    $current = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $otherCompany = Company::factory()->create();
    $other = PriceList::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'PL-OTHER-SELECT2',
        'company_id' => $otherCompany->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'price_list_date' => today(),
        'valid_from' => today(),
    ]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $this->getJson(route('admin.sales.select2.price-lists'))->assertForbidden();

    Permission::findOrCreate('inventory.reports.financial', 'web');
    $fixture['user']->givePermissionTo('inventory.reports.financial');
    $this->getJson(route('admin.sales.select2.price-lists'))->assertForbidden();

    Permission::findOrCreate('inventory.reports.operational', 'web');
    $fixture['user']->givePermissionTo('inventory.reports.operational');
    $this->getJson(route('admin.sales.select2.price-lists'))
        ->assertOk()
        ->assertJsonFragment(['doc_num' => $current->doc_num])
        ->assertJsonMissing(['doc_num' => $other->doc_num]);

    $fixture['user']->revokePermissionTo('inventory.reports.financial');
    $fixture['user']->revokePermissionTo('inventory.reports.operational');
    Permission::findOrCreate('price_lists.view', 'web');
    $fixture['user']->givePermissionTo('price_lists.view');
    $this->getJson(route('admin.sales.select2.price-lists', ['term' => $current->doc_num]))
        ->assertOk()
        ->assertJsonFragment(['doc_num' => $current->doc_num])
        ->assertJsonMissing(['doc_num' => $other->doc_num]);
});

test('price list permissions are seeded for admin and expose the screen without manual grants', function (): void {
    $fixture = salesCycleFixture();
    $this->seed(PermissionSeeder::class);

    $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    foreach (['price_lists.view', 'price_lists.create', 'price_lists.clone', 'price_lists.edit', 'price_lists.delete', 'price_lists.view_trashed', 'price_lists.restore', 'price_lists.print', 'price_lists.export'] as $permission) {
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
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->deleteJson(route('admin.sales.price-lists.bulk-delete'), ['doc_nums' => [$second->doc_num, $priceList->doc_num]])
        ->assertOk()
        ->assertJsonPath('data.deleted', 2);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower(preg_replace('/\s+/', ' ', $query) ?? $query));
    DB::disableQueryLog();
    expect(PriceList::onlyTrashed()->count())->toBe(2)
        ->and($queries->contains(fn (string $query): bool => str_contains($query, 'from "price_lists"') && str_contains($query, '"doc_num" in') && str_contains($query, 'order by "id" asc')))->toBeTrue();
});

test('price list create and edit forms expose and honor the standard save destinations', function (): void {
    $fixture = salesCycleFixture();
    foreach (['price_lists.view', 'price_lists.create', 'price_lists.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $createPage = $this->get(route('admin.sales.price-lists.create'))
        ->assertOk()
        ->assertSee(__('common.actions.save_data'))
        ->assertSee(__('common.actions.save_and_view'))
        ->assertSee(__('common.actions.save_and_edit'))
        ->assertSee(__('common.actions.save_and_back'))
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertSee('data-submit-action="save_new"', false)
        ->assertSee('data-clipboard-actor-id="'.$fixture['user']->getKey().'"', false)
        ->assertSee('data-clipboard-company-id="'.$fixture['company']->getKey().'"', false);

    expect($createPage->getContent())->not->toContain('data-clipboard-user-email', 'data-clipboard-company-name');
    $clipboardScript = file_get_contents(public_path('assets/js/modules/Sales/price-lists.js'));
    expect($clipboardScript)
        ->toContain("'mgypack.priceLists.lineClipboard.' + clipboardScope.actor_id + '.' + clipboardScope.company_id")
        ->toContain('payload?.scope?.actor_id === clipboardScope.actor_id')
        ->toContain('payload?.scope?.company_id === clipboardScope.company_id');

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

test('price list clone copies business data and ordered lines into an independent new document', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('price_lists.clone', 'web');
    $fixture['user']->givePermissionTo('price_lists.clone');
    $sourceCreator = User::factory()->create();
    $source = createSalesPriceList($fixture, $fixture['customer']->getKey(), [
        ['product' => $fixture['finished'], 'price' => '10.0050', 'discount_type' => 'percentage', 'discount_value' => '7.5'],
        ['product' => $fixture['service'], 'price' => '20', 'discount_type' => 'fixed', 'discount_value' => '2'],
    ], '2026-09-01');
    $source->forceFill([
        'valid_until' => '2026-12-31',
        'notes' => 'Source notes',
        'is_print_only' => true,
        'created_by' => $sourceCreator->getKey(),
        'updated_by' => $sourceCreator->getKey(),
        'reviewed_by' => $sourceCreator->getKey(),
        'reviewed_at' => now(),
        'approved_by' => $sourceCreator->getKey(),
        'approved_at' => now(),
    ])->save();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $ordinaryPayload = priceListStorePayload($fixture, [[
        'product_doc_num' => $fixture['finished']->doc_num,
        'unit_price' => '10.0050',
        'allowed_discount_type' => null,
        'allowed_discount_value' => 0,
    ]]);
    $this->post(route('admin.sales.price-lists.store'), $ordinaryPayload)->assertForbidden();

    $clonePage = $this->get(route('admin.sales.price-lists.clone', $source))
        ->assertOk()
        ->assertSee(__('price_lists.clone_from', ['document' => $source->doc_num]))
        ->assertSee(__('price_lists.automatic_code'))
        ->assertSee('10.005');
    $cloneToken = Str::match('/name="clone_source_token" value="([^"]+)"/', (string) $clonePage->getContent());
    $submissionToken = Str::match('/name="_submission_token" value="([^"]+)"/', (string) $clonePage->getContent());
    expect(Str::isUuid($cloneToken))->toBeTrue()
        ->and($submissionToken)->toBe($cloneToken)
        ->and((string) $clonePage->getContent())->toContain('<fieldset disabled')
        ->not->toContain('data-price-list-add');

    $source->forceFill(['notes' => 'Current locked source notes', 'valid_until' => '2027-01-31'])->save();
    $source->lines()->where('line_number', 1)->update(['unit_price' => '12.3456']);
    $source = $source->fresh();
    $sourceSnapshot = $source->toArray();

    $payload = priceListStorePayload($fixture, [[
        'product_doc_num' => 'tampered-product',
        'unit_price' => '999.9999',
        'allowed_discount_type' => null,
        'allowed_discount_value' => 0,
    ]]);
    $payload['customer_doc_num'] = null;
    $payload['currency_doc_num'] = 'tampered-currency';
    $payload['price_list_date'] = '2035-01-01';
    $payload['valid_from'] = '2035-01-01';
    $payload['valid_until'] = null;
    $payload['notes'] = 'Tampered client notes';
    $payload['is_print_only'] = '0';
    $payload['clone_source_token'] = $cloneToken;
    $payload['_submission_token'] = $cloneToken;
    $payload['submit_action'] = 'save_new';
    $cloneResponse = $this->post(route('admin.sales.price-lists.store'), $payload)
        ->assertSessionHas('success', __('price_lists.messages.cloned'));

    $clone = PriceList::query()->whereKeyNot($source->getKey())->sole();
    $cloneResponse->assertRedirect(route('admin.sales.price-lists.clone', $clone));
    expect($clone->getKey())->not->toBe($source->getKey())
        ->and($clone->doc_num)->not->toBe($source->doc_num)
        ->and($clone->company_id)->toBe($source->company_id)
        ->and($clone->customer_id)->toBe($source->customer_id)
        ->and($clone->currency_id)->toBe($source->currency_id)
        ->and($clone->price_list_date->toDateString())->toBe($source->price_list_date->toDateString())
        ->and($clone->valid_from->toDateString())->toBe($source->valid_from->toDateString())
        ->and($clone->valid_until->toDateString())->toBe($source->valid_until->toDateString())
        ->and($clone->notes)->toBe($source->notes)
        ->and($clone->is_print_only)->toBeTrue()
        ->and($clone->created_by)->toBe($fixture['user']->getKey())
        ->and($clone->updated_by)->toBeNull()
        ->and($clone->reviewed_by)->toBeNull()
        ->and($clone->reviewed_at)->toBeNull()
        ->and($clone->approved_by)->toBeNull()
        ->and($clone->approved_at)->toBeNull()
        ->and($clone->lines->pluck('product_id')->all())->toBe([$fixture['finished']->getKey(), $fixture['service']->getKey()])
        ->and($clone->lines->pluck('line_number')->all())->toBe([1, 2])
        ->and($clone->lines->pluck('unit_price')->all())->toBe(['12.3456', '20.0000'])
        ->and($clone->lines->pluck('allowed_discount_type')->all())->toBe(['percentage', 'fixed'])
        ->and($clone->lines->pluck('allowed_discount_value')->all())->toBe(['7.5000', '2.0000'])
        ->and($source->fresh()->toArray())->toBe($sourceSnapshot);

    $activity = Activity::query()
        ->where('subject_type', $clone->getMorphClass())
        ->where('subject_id', $clone->getKey())
        ->where('event', 'price_lists.clone')
        ->sole();
    expect(data_get($activity->properties, 'change_type'))->toBe('clone')
        ->and(data_get($activity->properties, 'action.type'))->toBe('clone')
        ->and(data_get($activity->properties, 'related.source.doc_num'))->toBe($source->doc_num)
        ->and(data_get($activity->properties, 'record.doc_num'))->toBe($clone->doc_num)
        ->and(data_get($activity->properties, 'header_changes.clone_document.old'))->toBe($source->doc_num)
        ->and(data_get($activity->properties, 'header_changes.clone_document.new'))->toBe($clone->doc_num);
    Permission::findOrCreate('price_lists.view', 'web');
    $fixture['user']->givePermissionTo('price_lists.view');
    $this->get(route('admin.sales.price-lists.history', $clone))
        ->assertOk()
        ->assertSee(__('price_lists.history_actions.clone'))
        ->assertSee($source->doc_num)
        ->assertSee($clone->doc_num);

    $clone->lines()->firstOrFail()->update(['unit_price' => '99']);
    expect($source->lines()->firstOrFail()->unit_price)->toBe('12.3456');
});

test('price list clone is authorized company scoped and create rollback is atomic', function (): void {
    $fixture = salesCycleFixture();
    $source = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    Permission::findOrCreate('price_lists.clone', 'web');
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $this->get(route('admin.sales.price-lists.clone', $source))->assertForbidden();
    $fixture['user']->givePermissionTo('price_lists.clone');
    $otherCompanySession = [...salesCycleSession($fixture), OperatingContextService::CompanyIdKey => 999999];
    $this->withSession($otherCompanySession)->get(route('admin.sales.price-lists.clone', $source))->assertNotFound();

    $beforeLists = PriceList::query()->count();
    $beforeLines = PriceListLine::query()->count();
    $payload = priceListStorePayload($fixture, [
        ['product_doc_num' => $fixture['finished']->doc_num, 'unit_price' => '10', 'allowed_discount_type' => null, 'allowed_discount_value' => 0],
        ['product_doc_num' => 'missing-product', 'unit_price' => '20', 'allowed_discount_type' => null, 'allowed_discount_value' => 0],
    ]);

    expect(fn () => app(PriceListService::class)->create($payload, $fixture['company']->getKey()))
        ->toThrow(ModelNotFoundException::class);
    expect(PriceList::query()->count())->toBe($beforeLists)
        ->and(PriceListLine::query()->count())->toBe($beforeLines);
});

test('price list clone rejects forged stale and cross company provenance tokens', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('price_lists.clone', 'web');
    $fixture['user']->givePermissionTo('price_lists.clone');
    $source = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $payload = priceListStorePayload($fixture, [[
        'product_doc_num' => $fixture['finished']->doc_num,
        'unit_price' => '10',
        'allowed_discount_type' => null,
        'allowed_discount_value' => 0,
    ]]);
    $payload['customer_doc_num'] = null;
    $payload['submit_action'] = 'save_new';
    $activeSource = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '11']]);

    $forgedToken = (string) Str::uuid();
    $this->post(route('admin.sales.price-lists.store'), [
        ...$payload,
        'clone_source_token' => $forgedToken,
        '_submission_token' => $forgedToken,
    ])->assertSessionHasErrors(['clone_source_token' => __('price_lists.messages.clone_not_allowed')]);

    $stalePage = $this->get(route('admin.sales.price-lists.clone', $source))->assertOk();
    $staleToken = Str::match('/name="clone_source_token" value="([^"]+)"/', (string) $stalePage->getContent());
    $source->delete();
    $this->post(route('admin.sales.price-lists.store'), [
        ...$payload,
        'clone_source_token' => $staleToken,
        '_submission_token' => $staleToken,
    ])->assertSessionHasErrors(['clone_source_token' => __('price_lists.messages.clone_not_allowed')]);

    $crossCompanyPage = $this->get(route('admin.sales.price-lists.clone', $activeSource))->assertOk();
    $crossCompanyToken = Str::match('/name="clone_source_token" value="([^"]+)"/', (string) $crossCompanyPage->getContent());
    $otherCompany = Company::factory()->create();
    $otherCurrency = Currency::query()->create([
        'company_id' => $otherCompany->getKey(),
        'doc_number' => 98001,
        'doc_num' => 'Currency-98001',
        'name' => 'Clone Boundary Currency',
        'code' => 'CBC',
        'is_main' => false,
        'status' => 'active',
    ]);
    $otherProduct = Product::query()->create([
        'company_id' => $otherCompany->getKey(),
        'doc_number' => 98001,
        'doc_num' => 'Product-CLONE-BOUNDARY',
        'name' => 'Clone Boundary Product',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);
    $crossCompanyPayload = [
        ...$payload,
        'currency_doc_num' => $otherCurrency->doc_num,
        'lines' => [[
            'product_doc_num' => $otherProduct->doc_num,
            'unit_price' => '12.3456',
            'allowed_discount_type' => null,
            'allowed_discount_value' => 0,
        ]],
        'clone_source_token' => $crossCompanyToken,
        '_submission_token' => $crossCompanyToken,
    ];
    $otherCompanySession = [
        ...salesCycleSession($fixture),
        OperatingContextService::CompanyIdKey => $otherCompany->getKey(),
        OperatingContextService::CompanyDocNumKey => $otherCompany->doc_num,
    ];

    $this->withSession($otherCompanySession)
        ->post(route('admin.sales.price-lists.store'), $crossCompanyPayload)
        ->assertSessionHasErrors(['clone_source_token' => __('price_lists.messages.clone_not_allowed')]);
    expect(PriceList::query()->where('company_id', $otherCompany->getKey())->exists())->toBeFalse();
});

test('price list clone token survives transactional failure and is consumed only after success', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('price_lists.clone', 'web');
    $fixture['user']->givePermissionTo('price_lists.clone');
    $source = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $clonePage = $this->get(route('admin.sales.price-lists.clone', $source))->assertOk();
    $cloneToken = Str::match('/name="clone_source_token" value="([^"]+)"/', (string) $clonePage->getContent());
    $payload = priceListStorePayload($fixture, [[
        'product_doc_num' => $fixture['finished']->doc_num,
        'unit_price' => '10.2500',
        'allowed_discount_type' => 'percentage',
        'allowed_discount_value' => '3.5000',
    ]]);
    $payload['customer_doc_num'] = null;
    $payload['clone_source_token'] = $cloneToken;
    $payload['_submission_token'] = $cloneToken;
    $payload['submit_action'] = 'save_new';
    $beforeLists = PriceList::query()->count();
    $beforeLines = PriceListLine::query()->count();
    $realLogger = app(ActivityLogger::class);
    $logAttempts = 0;
    $logger = Mockery::mock(ActivityLogger::class);
    $logger->shouldReceive('log')->twice()->andReturnUsing(function (...$arguments) use (&$logAttempts, $realLogger): mixed {
        $logAttempts++;

        if ($logAttempts === 1) {
            throw new RuntimeException('Simulated clone audit failure.');
        }

        return $realLogger->log(...$arguments);
    });
    $this->app->instance(ActivityLogger::class, $logger);

    $exception = null;
    $this->withoutExceptionHandling();
    try {
        $this->post(route('admin.sales.price-lists.store'), $payload);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        $this->withExceptionHandling();
    }

    expect($exception?->getMessage())->toBe('Simulated clone audit failure.')
        ->and(PriceList::query()->count())->toBe($beforeLists)
        ->and(PriceListLine::query()->count())->toBe($beforeLines)
        ->and(DB::table('document_submissions')->count())->toBe(0);

    $success = $this->post(route('admin.sales.price-lists.store'), $payload)->assertRedirect();
    expect(PriceList::query()->count())->toBe($beforeLists + 1)
        ->and(PriceListLine::query()->count())->toBe($beforeLines + 1)
        ->and(DB::table('document_submissions')->count())->toBe(1);

    $replay = $this->post(route('admin.sales.price-lists.store'), $payload)->assertRedirect();
    expect($replay->headers->get('Location'))->toBe($success->headers->get('Location'))
        ->and(PriceList::query()->count())->toBe($beforeLists + 1)
        ->and(PriceListLine::query()->count())->toBe($beforeLines + 1);

    $this->post(route('admin.sales.price-lists.store'), [...$payload, 'notes' => 'Changed replay payload'])
        ->assertConflict();

    $this->post(route('admin.sales.price-lists.store'), [
        ...$payload,
        '_submission_token' => (string) Str::uuid(),
    ])->assertSessionHasErrors(['_submission_token']);
    expect(DB::table('document_submissions')->count())->toBe(1);
});

test('price list percentage increase uses canonical four decimal rounding and updates audit only', function (): void {
    $fixture = salesCycleFixture();
    foreach (['price_lists.view', 'price_lists.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $list = createSalesPriceList($fixture, null, [
        ['product' => $fixture['finished'], 'price' => '10.0050', 'discount_type' => 'percentage', 'discount_value' => '7.5'],
        ['product' => $fixture['service'], 'price' => '20', 'discount_type' => 'fixed', 'discount_value' => '2'],
    ]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $this->postJson(route('admin.sales.price-lists.increase-by-percentage', $list), ['percentage' => '5'])
        ->assertOk()->assertJsonPath('success', true);
    expect($list->fresh()->lines->pluck('unit_price')->all())->toBe(['10.5053', '21.0000'])
        ->and($list->lines->pluck('allowed_discount_value')->all())->toBe(['7.5000', '2.0000'])
        ->and($list->fresh()->updated_by)->toBe($fixture['user']->getKey());

    $activity = Activity::query()
        ->where('subject_type', $list->getMorphClass())
        ->where('subject_id', $list->getKey())
        ->where('event', 'price_lists.percentage')
        ->sole();
    $properties = $activity->properties->all();
    $finishedChange = collect($properties['line_changes'])->first(
        fn (array $change): bool => str_contains((string) $change['product'], $fixture['finished']->doc_num)
    );
    expect($activity->causer_id)->toBe($fixture['user']->getKey())
        ->and($activity->event)->toBe('price_lists.percentage')
        ->and($properties['change_type'])->toBe('percentage')
        ->and($properties['percentage'])->toBe('5')
        ->and($finishedChange['old']['unit_price'])->toBe('10.0050')
        ->and($finishedChange['new']['unit_price'])->toBe('10.5053')
        ->and($finishedChange['old']['allowed_discount_type'])->toBe('percentage')
        ->and($finishedChange['new']['allowed_discount_type'])->toBe('percentage')
        ->and($finishedChange['old']['allowed_discount_value'])->toBe('7.5000')
        ->and($finishedChange['new']['allowed_discount_value'])->toBe('7.5000');

    $this->postJson(route('admin.sales.price-lists.increase-by-percentage', $list), ['percentage' => '2.5'])->assertOk();
    expect($list->fresh()->lines->pluck('unit_price')->all())->toBe(['10.7679', '21.5250']);

    $this->get(route('admin.sales.price-lists.history', $list))
        ->assertOk()
        ->assertSee(__('price_lists.history_actions.percentage'))
        ->assertSee($fixture['finished']->name)
        ->assertSee('10.005')
        ->assertSee('10.5053');
});

test('price list percentage validation authorization scope and empty-list rules are enforced', function (string $percentage): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('price_lists.edit', 'web');
    $list = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $this->postJson(route('admin.sales.price-lists.increase-by-percentage', $list), ['percentage' => $percentage])->assertForbidden();
    $fixture['user']->givePermissionTo('price_lists.edit');
    $this->postJson(route('admin.sales.price-lists.increase-by-percentage', $list), ['percentage' => $percentage])
        ->assertUnprocessable()->assertJsonValidationErrors('percentage');

    $otherCompanySession = [...salesCycleSession($fixture), OperatingContextService::CompanyIdKey => 999999];
    $this->withSession($otherCompanySession)
        ->postJson(route('admin.sales.price-lists.increase-by-percentage', $list), ['percentage' => '5'])->assertNotFound();

    $empty = PriceList::query()->create([
        'doc_number' => 999, 'doc_num' => 'PL-EMPTY', 'company_id' => $fixture['company']->getKey(),
        'currency_id' => $fixture['currency']->getKey(), 'price_list_date' => '2026-09-19', 'valid_from' => '2026-09-19',
    ]);
    $this->withSession(salesCycleSession($fixture))
        ->postJson(route('admin.sales.price-lists.increase-by-percentage', $empty), ['percentage' => '5'])
        ->assertUnprocessable()->assertJsonPath('message', __('price_lists.messages.no_lines_to_increase'));
})->with(['zero' => '0', 'negative' => '-1', 'malformed' => 'five', 'too precise' => '1.00001', 'out of range' => '1000.0001']);

test('price list percentage increase rolls back all lines when a later line fails', function (): void {
    $fixture = salesCycleFixture();
    $list = createSalesPriceList($fixture, null, [
        ['product' => $fixture['finished'], 'price' => '10'],
        ['product' => $fixture['service'], 'price' => '20'],
    ]);
    $amounts = Mockery::mock(SalesAmountService::class)->makePartial();
    $calls = 0;
    $amounts->shouldReceive('multiply')->andReturnUsing(function ($left, $right, int $scale = 4) use (&$calls): string {
        $calls++;
        if ($calls === 3) {
            throw new RuntimeException('Injected second-line failure');
        }

        return bcmul((string) $left, (string) $right, $scale);
    });
    app()->instance(SalesAmountService::class, $amounts);

    expect(fn () => app(PriceListService::class)->increaseByPercentage($list, '5', $fixture['company']->getKey()))
        ->toThrow(RuntimeException::class, 'Injected second-line failure');
    expect($list->fresh()->lines->pluck('unit_price')->all())->toBe(['10.0000', '20.0000'])
        ->and($list->fresh()->updated_by)->toBeNull();
});

test('price list percentage increase rolls back prices and audit fields when activity persistence fails', function (): void {
    $fixture = salesCycleFixture();
    $list = createSalesPriceList($fixture, null, [
        ['product' => $fixture['finished'], 'price' => '10'],
        ['product' => $fixture['service'], 'price' => '20'],
    ]);
    $activities = Mockery::mock(ActivityLogger::class);
    $activities->shouldReceive('log')->once()->andThrow(new RuntimeException('Injected activity failure'));
    app()->instance(ActivityLogger::class, $activities);

    expect(fn () => app(PriceListService::class)->increaseByPercentage($list, '5', $fixture['company']->getKey()))
        ->toThrow(RuntimeException::class, 'Injected activity failure');
    expect($list->fresh()->lines->pluck('unit_price')->all())->toBe(['10.0000', '20.0000'])
        ->and($list->fresh()->updated_by)->toBeNull();
});

test('price list percentage increase does not reprice an existing invoice snapshot', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('price_lists.edit', 'web');
    $fixture['user']->givePermissionTo('price_lists.edit');
    $list = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '25']]);
    $invoice = app(CustomerInvoiceService::class)->createDirect([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'customer_doc_num' => $fixture['customer']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'exchange_rate' => 1,
        'lines' => [['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 1, 'unit_price' => 999, 'discount_amount' => 0, 'tax_amount' => 0]],
    ]);
    $snapshotLine = $invoice->lines->sole();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->postJson(route('admin.sales.price-lists.increase-by-percentage', $list), ['percentage' => '10'])->assertOk();

    expect($list->fresh()->lines->sole()->unit_price)->toBe('27.5000')
        ->and($snapshotLine->fresh()->unit_price)->toBe('25.0000')
        ->and($snapshotLine->price_list_line_id)->toBe($list->lines()->sole()->getKey());
});

test('print only schema defaults safely and administration can create edit display and audit the flag', function (): void {
    $fixture = salesCycleFixture();
    foreach (['price_lists.view', 'price_lists.create', 'price_lists.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    expect(Schema::hasColumn('price_lists', 'is_print_only'))->toBeTrue();
    $legacy = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '9']]);
    $legacy->refresh();
    expect($legacy->is_print_only)->toBeFalse()->and($legacy->is_print_only)->toBeBool();

    $payload = priceListStorePayload($fixture, [[
        'product_doc_num' => $fixture['service']->doc_num,
        'unit_price' => '25',
        'allowed_discount_type' => null,
        'allowed_discount_value' => 0,
    ]]);
    $payload['is_print_only'] = '1';
    $this->post(route('admin.sales.price-lists.store'), $payload)->assertRedirect();
    $record = PriceList::query()->whereKeyNot($legacy->getKey())->sole();
    expect($record->is_print_only)->toBeTrue()->and($record->created_by)->toBe($fixture['user']->getKey());

    $this->get(route('admin.sales.price-lists.show', $record))
        ->assertOk()->assertSee(__('price_lists.fields.is_print_only'))->assertSee(__('price_lists.print_only_help'));
    $this->getJson(route('admin.sales.price-lists.data', priceListDataTableQuery()))
        ->assertOk()->assertJsonPath('recordsTotal', 2)
        ->assertJsonPath('data.0.pricing_use', fn (string $value): bool => in_array($value, [__('price_lists.print_only'), __('price_lists.operational')], true))
        ->assertJsonMissingPath('data.0.is_print_only');

    unset($payload['is_print_only']);
    $this->put(route('admin.sales.price-lists.update', $record), $payload)->assertRedirect();
    expect($record->fresh()->is_print_only)->toBeFalse()
        ->and($record->fresh()->updated_by)->toBe($fixture['user']->getKey());

    $payload['is_print_only'] = '1';
    $this->put(route('admin.sales.price-lists.update', $record), $payload)->assertRedirect();
    expect($record->fresh()->is_print_only)->toBeTrue();

    unset($payload['is_print_only']);
    $this->put(route('admin.sales.price-lists.update', $record), $payload)->assertRedirect();
    expect($record->fresh()->is_print_only)->toBeFalse();

    $this->get(route('admin.sales.price-lists.history', $record))
        ->assertOk()
        ->assertSee(__('price_lists.history_actions.manual'))
        ->assertSee(__('price_lists.fields.is_print_only'));
});

test('print only migration backfills an existing row safely and rolls back only its column', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $connection = 'sal003_migration_test';
    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_num')->unique();
            $table->text('notes')->nullable();
        });
        Schema::create('price_list_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_list_id');
            $table->decimal('unit_price', 18, 4);
        });
        DB::table('price_lists')->insert(['id' => 41, 'doc_num' => 'PL-LEGACY', 'notes' => 'preserve']);
        DB::table('price_list_lines')->insert(['id' => 73, 'price_list_id' => 41, 'unit_price' => '19.5000']);

        $migration = require base_path('modules/Sales/Database/Migrations/2026_09_19_052638_add_is_print_only_to_price_lists_table.php');
        $migration->up();
        $column = collect(DB::select('pragma table_info(price_lists)'))->first(fn (object $item): bool => $item->name === 'is_print_only');

        expect(Schema::hasColumn('price_lists', 'is_print_only'))->toBeTrue()
            ->and((int) $column->notnull)->toBe(1)
            ->and((int) DB::table('price_lists')->where('id', 41)->value('is_print_only'))->toBe(0)
            ->and(DB::table('price_lists')->where('id', 41)->value('doc_num'))->toBe('PL-LEGACY')
            ->and((int) DB::table('price_list_lines')->where('id', 73)->value('price_list_id'))->toBe(41)
            ->and((string) DB::table('price_list_lines')->where('id', 73)->value('unit_price'))->toBe('19.5');

        $migration->down();
        expect(Schema::hasColumn('price_lists', 'is_print_only'))->toBeFalse()
            ->and(DB::table('price_lists')->where('id', 41)->value('doc_num'))->toBe('PL-LEGACY')
            ->and(DB::table('price_list_lines')->where('id', 73)->exists())->toBeTrue();
    } finally {
        DB::disconnect($connection);
        DB::setDefaultConnection($originalConnection);
    }
});

test('print only input rejects malformed supplied values and unauthorized mutations', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('price_lists.create', 'web');
    $fixture['user']->givePermissionTo('price_lists.create');
    $payload = priceListStorePayload($fixture, [[
        'product_doc_num' => $fixture['finished']->doc_num,
        'unit_price' => '25',
        'allowed_discount_type' => null,
        'allowed_discount_value' => 0,
    ]]);
    $payload['is_print_only'] = 'malformed';
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->post(route('admin.sales.price-lists.store'), $payload)
        ->assertSessionHasErrors('is_print_only');

    $record = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $payload['is_print_only'] = '1';
    $this->put(route('admin.sales.price-lists.update', $record), $payload)->assertForbidden();
    expect($record->fresh()->is_print_only)->toBeFalse();
});

test('print only lists are excluded while customer and general operational fallback ordering is preserved', function (): void {
    $fixture = salesCycleFixture();
    $general = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']], '2026-01-01');
    $newerGeneralPrintOnly = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '88']], '2026-04-01');
    $newerGeneralPrintOnly->update(['is_print_only' => true]);
    $customer = createSalesPriceList($fixture, $fixture['customer']->getKey(), [['product' => $fixture['finished'], 'price' => '20']], '2026-02-01');
    $printOnly = createSalesPriceList($fixture, $fixture['customer']->getKey(), [['product' => $fixture['finished'], 'price' => '99']], '2026-03-01');
    $printOnly->update(['is_print_only' => true]);
    $pricing = app(PriceListPricingService::class);

    $resolved = $pricing->resolve($fixture['company']->getKey(), $fixture['customer']->getKey(), $fixture['currency']->getKey(), $fixture['finished'], $fixture['unit']->getKey(), 1, '2026-09-01');
    expect($resolved['unit_price'])->toBe('20.0000')->and($resolved['price_list_doc_num'])->toBe($customer->doc_num);

    $customer->update(['is_print_only' => true]);
    $resolved = $pricing->resolve($fixture['company']->getKey(), $fixture['customer']->getKey(), $fixture['currency']->getKey(), $fixture['finished'], $fixture['unit']->getKey(), 1, '2026-09-01');
    expect($resolved['unit_price'])->toBe('10.0000')->and($resolved['price_list_doc_num'])->toBe($general->doc_num)->and($resolved['source'])->toBe('general');

    $general->update(['is_print_only' => true]);
    expect(fn () => $pricing->resolve($fixture['company']->getKey(), $fixture['customer']->getKey(), $fixture['currency']->getKey(), $fixture['finished'], $fixture['unit']->getKey(), 1, '2026-09-01'))
        ->toThrow(DomainException::class, $fixture['finished']->name);
});

test('print only operational exclusion remains company isolated', function (): void {
    $fixture = salesCycleFixture();
    $current = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $current->update(['is_print_only' => true]);
    $otherCompany = Company::factory()->create();
    $other = PriceList::query()->create([
        'doc_number' => 9001,
        'doc_num' => 'PL-OTHER-COMPANY',
        'company_id' => $otherCompany->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'price_list_date' => '2026-01-01',
        'valid_from' => '2026-01-01',
        'is_print_only' => false,
    ]);
    $other->lines()->create([
        'line_number' => 1,
        'product_id' => $fixture['finished']->getKey(),
        'unit_price' => '77',
        'allowed_discount_type' => null,
        'allowed_discount_value' => 0,
    ]);

    expect(fn () => app(PriceListPricingService::class)->resolve(
        $fixture['company']->getKey(),
        $fixture['customer']->getKey(),
        $fixture['currency']->getKey(),
        $fixture['finished'],
        $fixture['unit']->getKey(),
        1,
        '2026-09-01',
    ))->toThrow(DomainException::class, $fixture['finished']->name);
});

test('persisted price locking acquires candidate headers and lines in deterministic order', function (): void {
    $fixture = salesCycleFixture();
    $operational = createSalesPriceList($fixture, null, [
        ['product' => $fixture['finished'], 'price' => '10'],
        ['product' => $fixture['service'], 'price' => '20'],
    ]);
    $printOnly = createSalesPriceList($fixture, null, [
        ['product' => $fixture['finished'], 'price' => '99'],
        ['product' => $fixture['service'], 'price' => '99'],
    ], '2026-02-01');
    $printOnly->update(['is_print_only' => true]);
    DB::flushQueryLog();
    DB::enableQueryLog();

    [$eligibleIds, $resolved] = DB::transaction(function () use ($fixture): array {
        $pricing = app(PriceListPricingService::class);
        $eligibleIds = $pricing->lockForPersistedResolution(
            $fixture['company']->getKey(),
            $fixture['customer']->getKey(),
            $fixture['currency']->getKey(),
            [$fixture['service']->getKey(), $fixture['finished']->getKey()],
            '2026-09-01',
        );

        return [$eligibleIds, $pricing->resolveFromLockedCandidates(
            $fixture['company']->getKey(),
            $fixture['customer']->getKey(),
            $fixture['currency']->getKey(),
            $fixture['finished'],
            $fixture['unit']->getKey(),
            1,
            '2026-09-01',
            $eligibleIds,
        )];
    });
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower(preg_replace('/\s+/', ' ', $query) ?? $query));
    DB::disableQueryLog();

    expect($eligibleIds)->toBe([$operational->getKey()])
        ->and($eligibleIds)->not->toContain($printOnly->getKey())
        ->and($resolved['unit_price'])->toBe('10.0000')
        ->and($resolved['price_list_doc_num'])->toBe($operational->doc_num)
        ->and($queries->contains(fn (string $query): bool => str_contains($query, 'from "price_lists"') && str_contains($query, 'order by "id" asc')))->toBeTrue()
        ->and($queries->contains(fn (string $query): bool => str_contains($query, 'from "price_list_lines"') && str_contains($query, 'order by "price_list_id" asc, "id" asc')))->toBeTrue();
});

test('stored order repricing locks every unmatched product in one batch and preserves input order', function (): void {
    $fixture = salesCycleFixture();
    createSalesPriceList($fixture, null, [
        ['product' => $fixture['finished'], 'price' => '10'],
        ['product' => $fixture['service'], 'price' => '20'],
    ]);
    $lines = [
        ['product_id' => $fixture['service']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'quantity' => 1, 'discount_amount' => 0],
        ['product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'quantity' => 1, 'discount_amount' => 0],
    ];
    DB::flushQueryLog();
    DB::enableQueryLog();

    $resolved = DB::transaction(fn (): array => app(PriceListPricingService::class)->preserveStoredOrderPrices(
        $lines,
        collect(),
        $fixture['company']->getKey(),
        $fixture['customer']->getKey(),
        $fixture['currency']->getKey(),
        '2026-09-01',
        lockForUpdate: true,
    ));
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower(preg_replace('/\s+/', ' ', $query) ?? $query));
    DB::disableQueryLog();
    $lineLockBatches = $queries->filter(fn (string $query): bool => str_contains($query, 'from "price_list_lines"')
        && str_contains($query, '"price_list_id" in')
        && str_contains($query, '"product_id" in')
        && str_contains($query, 'order by "price_list_id" asc, "id" asc'));

    expect(array_column($resolved, 'product_id'))->toBe([$fixture['service']->getKey(), $fixture['finished']->getKey()])
        ->and(array_column($resolved, 'unit_price'))->toBe(['20.0000', '10.0000'])
        ->and($lineLockBatches)->toHaveCount(1);
});

test('print only prices cannot enter suggestions direct orders or direct invoices', function (): void {
    $fixture = salesCycleFixture();
    foreach (['sales_orders.create', 'customer_invoices.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $list = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '25']]);
    $list->update(['is_print_only' => true]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $query = [
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'product_doc_num' => $fixture['finished']->doc_num,
        'unit_doc_num' => $fixture['unit']->doc_num,
        'quantity' => 1,
        'document_date' => now()->toDateString(),
    ];
    $this->getJson(route('admin.sales.price-suggestion', $query))->assertOk()->assertJsonPath('data', null);

    $orderPayload = [
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addWeek()->toDateString(),
        'lines' => [['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 1, 'discount_amount' => 0, 'tax_amount' => 0]],
    ];
    $this->postJson(route('admin.sales.sales-orders.store'), $orderPayload)->assertUnprocessable();
    expect(SalesOrder::query()->count())->toBe(0);

    expect(fn () => app(CustomerInvoiceService::class)->createDirect([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'customer_doc_num' => $fixture['customer']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'exchange_rate' => 1,
        'lines' => [['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 1, 'discount_amount' => 0, 'tax_amount' => 0]],
    ]))->toThrow(DomainException::class, $fixture['finished']->name);
    expect(CustomerInvoice::query()->count())->toBe(0);
});

test('print only prices cannot enter a persisted quotation snapshot', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('quotations.create', 'web');
    $fixture['user']->givePermissionTo('quotations.create');
    $list = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '25']]);
    $list->update(['is_print_only' => true]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $this->postJson(route('admin.sales.quotations.store'), [
        'customer_doc_num' => $fixture['customer']->doc_num,
        'quotation_type' => Quotation::TypeStandard,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addMonth()->toDateString(),
        'revision_date' => now()->toDateString(),
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => '1',
        'discount_type' => null,
        'discount_value' => '0',
        'lines' => [[
            'product_doc_num' => $fixture['finished']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => '1',
            'unit_price' => '999',
            'discount_type' => null,
            'discount_value' => '0',
            'tax_rate' => '0',
        ]],
    ])->assertUnprocessable()->assertJsonPath('success', false);

    expect(Quotation::query()->count())->toBe(0);
});

test('sales request conversion rejects print only pricing and rolls back conversion state', function (): void {
    $fixture = salesCycleFixture();
    $permissions = ['sales_requests.view', 'sales_requests.create', 'sales_requests.edit', 'sales_requests.approve', 'sales_requests.convert', 'quotations.create'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $response = $this->postJson(route('admin.sales.customer-requests.store'), [
        'request_type' => 'customer',
        'request_date' => now()->toDateString(),
        'required_delivery_date' => now()->addWeek()->toDateString(),
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'lines' => [[
            'product_doc_num' => $fixture['finished']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
        ]],
    ])->assertOk();
    $request = SalesRequest::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $this->postJson(route('admin.sales.customer-requests.transition', $request), ['status' => 'submitted'])->assertOk();
    $this->postJson(route('admin.sales.customer-requests.transition', $request), ['status' => 'approved'])->assertOk();
    $list = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '25']]);
    $list->update(['is_print_only' => true]);

    $this->postJson(route('admin.sales.customer-requests.convert', $request), [
        'target' => 'quotation',
        'lines' => $request->lines->map(fn ($line): array => ['public_id' => $line->public_id, 'quantity' => $line->quantity])->all(),
    ])->assertUnprocessable()->assertJsonPath('message', fn (string $message): bool => str_contains($message, $fixture['finished']->name));

    expect(Quotation::query()->count())->toBe(0)
        ->and($request->fresh()->status)->toBe('approved')
        ->and($request->lines()->sum('converted_quantity'))->toEqual(0);
});

test('all source request document entry paths reject print only pricing and roll back source state', function (): void {
    $fixture = salesCycleFixture();
    $permissions = [
        'sales_requests.view', 'sales_requests.create', 'sales_requests.edit', 'sales_requests.approve',
        'sales_orders.create', 'quotations.create', 'customer_invoices.create',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $response = $this->postJson(route('admin.sales.customer-requests.store'), [
        'request_type' => 'customer',
        'request_date' => now()->toDateString(),
        'required_delivery_date' => now()->addWeek()->toDateString(),
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'lines' => [[
            'product_doc_num' => $fixture['finished']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
        ]],
    ])->assertOk();
    $source = SalesRequest::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $this->postJson(route('admin.sales.customer-requests.transition', $source), ['status' => 'submitted'])->assertOk();
    $this->postJson(route('admin.sales.customer-requests.transition', $source), ['status' => 'approved'])->assertOk();
    $sourceLine = $source->lines->sole();
    $printOnly = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '25']]);
    $printOnly->update(['is_print_only' => true]);

    $this->postJson(route('admin.sales.sales-orders.store'), [
        'source_request_doc_num' => $source->doc_num,
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addWeek()->toDateString(),
        'lines' => [[
            'source_request_line_public_id' => $sourceLine->public_id,
            'product_doc_num' => $fixture['finished']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
    ])->assertUnprocessable();
    expect(SalesOrder::query()->count())->toBe(0)
        ->and($source->fresh()->status)->toBe('approved')
        ->and($source->lines()->sum('converted_quantity'))->toEqual(0);

    $this->postJson(route('admin.sales.quotations.store'), [
        'source_request_doc_num' => $source->doc_num,
        'customer_doc_num' => $fixture['customer']->doc_num,
        'quotation_type' => Quotation::TypeStandard,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addMonth()->toDateString(),
        'revision_date' => now()->toDateString(),
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => '1',
        'discount_type' => null,
        'discount_value' => '0',
        'lines' => [[
            'source_request_line_public_id' => $sourceLine->public_id,
            'product_doc_num' => $fixture['finished']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => '2',
            'discount_type' => null,
            'discount_value' => '0',
            'tax_rate' => '0',
        ]],
    ])->assertUnprocessable();
    expect(Quotation::query()->count())->toBe(0)
        ->and($source->fresh()->status)->toBe('approved')
        ->and($source->lines()->sum('converted_quantity'))->toEqual(0);

    expect(fn () => app(CustomerInvoiceService::class)->createDirect([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'source_request_doc_num' => $source->doc_num,
        'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'exchange_rate' => 1,
        'lines' => [[
            'source_request_line_public_id' => $sourceLine->public_id,
            'product_doc_num' => $fixture['finished']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
    ], $source))->toThrow(DomainException::class, $fixture['finished']->name);
    expect(CustomerInvoice::query()->count())->toBe(0)
        ->and($source->fresh()->status)->toBe('approved')
        ->and($source->lines()->sum('converted_quantity'))->toEqual(0);
});

test('print only state is ignored by all pricing coverage and gap queries', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('reports.sales.sales_orders.view', 'web');
    $fixture['user']->givePermissionTo('reports.sales.sales_orders.view');
    $general = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $general->update(['is_print_only' => true]);
    $dedicated = createSalesPriceList($fixture, $fixture['customer']->getKey(), [['product' => $fixture['finished'], 'price' => '20']]);
    $dedicated->update(['is_print_only' => true]);

    $response = $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'pricing']))
        ->assertOk();

    expect($response->viewData('unpricedProducts')->pluck('doc_num'))->toContain($fixture['finished']->doc_num)
        ->and($response->viewData('customersWithoutPriceLists')->pluck('doc_num'))->toContain($fixture['customer']->doc_num)
        ->and($response->viewData('customerProductPricingGaps')->contains(fn ($row): bool => $row->customer_doc_num === $fixture['customer']->doc_num && $row->product_doc_num === $fixture['finished']->doc_num))->toBeTrue();
});

test('existing sales snapshot remains unchanged when its source list later becomes print only', function (): void {
    $fixture = salesCycleFixture();
    $list = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '25']]);
    $invoice = app(CustomerInvoiceService::class)->createDirect([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'customer_doc_num' => $fixture['customer']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'exchange_rate' => 1,
        'lines' => [['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 1, 'discount_amount' => 0, 'tax_amount' => 0]],
    ]);
    $snapshot = $invoice->lines->sole();

    $list->update(['is_print_only' => true]);
    expect($snapshot->fresh()->unit_price)->toBe('25.0000')
        ->and($snapshot->fresh()->price_list_line_id)->toBe($list->lines()->sole()->getKey());
});

test('price list output routes exist and reject unauthenticated requests', function (): void {
    $fixture = salesCycleFixture();
    $priceList = createSalesPriceList($fixture, null, []);

    auth()->logout();
    request()->setUserResolver(fn (): ?User => null);

    foreach (['print', 'pdf', 'export.xlsx', 'export.csv'] as $suffix) {
        $name = 'admin.sales.price-lists.'.$suffix;
        expect(Route::has($name))->toBeTrue();
        $this->get(route($name, $priceList))->assertRedirect();
    }
});

test('price list pdf and export permissions are independently enforced with a compatibility print redirect', function (): void {
    $fixture = salesCycleFixture();
    $priceList = createSalesPriceList($fixture, $fixture['customer']->getKey(), [[
        'product' => $fixture['finished'], 'price' => '25.1250', 'discount_type' => 'percentage', 'discount_value' => '7.5000',
    ]]);
    Permission::findOrCreate('price_lists.view', 'web');
    $fixture['user']->givePermissionTo('price_lists.view');
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    foreach (['print', 'pdf', 'export.xlsx', 'export.csv'] as $suffix) {
        $this->get(route('admin.sales.price-lists.'.$suffix, $priceList))->assertForbidden();
    }

    Permission::findOrCreate('price_lists.print', 'web');
    $fixture['user']->givePermissionTo('price_lists.print');
    $this->get(route('admin.sales.price-lists.print', $priceList))
        ->assertRedirectToRoute('admin.sales.price-lists.pdf', $priceList);
    $pdf = $this->get(route('admin.sales.price-lists.pdf', $priceList))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and((string) $pdf->headers->get('Content-Disposition'))->toContain('inline');
    $this->get(route('admin.sales.price-lists.export.xlsx', $priceList))->assertForbidden();
    $this->get(route('admin.sales.price-lists.export.csv', $priceList))->assertForbidden();

    $fixture['user']->revokePermissionTo('price_lists.print');
    Permission::findOrCreate('price_lists.export', 'web');
    $fixture['user']->givePermissionTo('price_lists.export');
    $this->get(route('admin.sales.price-lists.print', $priceList))->assertForbidden();
    $this->get(route('admin.sales.price-lists.pdf', $priceList))->assertForbidden();
    $this->get(route('admin.sales.price-lists.export.xlsx', $priceList))->assertDownload('price-list-'.strtolower($priceList->doc_num).'.xlsx');
    $this->get(route('admin.sales.price-lists.export.csv', $priceList))->assertDownload('price-list-'.strtolower($priceList->doc_num).'.csv');
});

test('price list pdf is the single canonical inline report in English and Arabic', function (): void {
    $fixture = salesCycleFixture();
    $priceList = createSalesPriceList($fixture, null, [
        ['product' => $fixture['finished'], 'price' => '25.1250', 'discount_type' => 'percentage', 'discount_value' => '7.5000'],
        ['product' => $fixture['service'], 'price' => '9.2500'],
    ]);
    $priceList->update(['is_print_only' => true, 'valid_until' => null, 'notes' => null]);
    foreach (['price_lists.view', 'price_lists.print', 'price_lists.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $report = app(PriceListReportData::class)->build($priceList->fresh());
    expect($report['header']['customer_name'])->toBe(__('price_lists.general'))
        ->and($report['header']['valid_until'])->toBeNull()
        ->and($report['header']['pricing_use'])->toBe(__('price_lists.print_only'))
        ->and(array_column($report['lines'], 'product_name'))->toBe([$fixture['finished']->name, $fixture['service']->name]);

    $this->get(route('admin.sales.price-lists.print', $priceList))
        ->assertRedirectToRoute('admin.sales.price-lists.pdf', $priceList);
    $english = $this->get(route('admin.sales.price-lists.pdf', $priceList))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr((string) $english->getContent(), 0, 4))->toBe('%PDF')
        ->and((string) $english->headers->get('Content-Disposition'))->toContain('inline');

    $arabicSession = [...salesCycleSession($fixture), 'locale' => 'ar'];
    $arabicPdf = $this->withSession($arabicSession)->get(route('admin.sales.price-lists.pdf', $priceList))->assertOk();
    expect(substr((string) $arabicPdf->getContent(), 0, 4))->toBe('%PDF');
});

test('price list export has matching localized headings text codes and canonical numeric values', function (): void {
    $fixture = salesCycleFixture();
    $fixture['customer']->update(['doc_num' => '00042']);
    $fixture['finished']->update(['doc_num' => '000007']);
    $priceList = createSalesPriceList($fixture, $fixture['customer']->getKey(), [[
        'product' => $fixture['finished'], 'price' => '25.1250', 'discount_type' => 'fixed', 'discount_value' => '2.5000',
    ]]);
    $priceList->update(['valid_until' => '2026-12-31', 'notes' => 'Dealer prices']);
    $report = app(PriceListReportData::class)->build($priceList->fresh());
    $xlsx = new PriceListExport($report);
    $csv = new PriceListExport($report, true);
    $xlsxRow = $xlsx->collection()->sole();
    $csvRow = $csv->collection()->sole();

    expect($xlsx->headings())->toHaveCount(count($xlsxRow))
        ->and($xlsxRow[0])->toBe($priceList->doc_num)
        ->and($xlsxRow[2])->toBe('00042')
        ->and($xlsxRow[12])->toBe('000007')
        ->and($xlsxRow[14])->toBeFloat()->toBe(25.125)
        ->and($xlsxRow[16])->toBeFloat()->toBe(2.5)
        ->and($csvRow[14])->toBe('25.1250')
        ->and($csvRow[16])->toBe('2.5000')
        ->and($xlsx->columnFormats())->toBe(['O' => '#,##0.0000', 'Q' => '#,##0.0000'])
        ->and($csv->columnFormats())->toBe([]);

    $empty = createSalesPriceList($fixture, null, []);
    $emptyReport = app(PriceListReportData::class)->build($empty);
    expect($emptyReport['export_rows'])->toHaveCount(1)
        ->and($emptyReport['export_rows'][0][0])->toBe($empty->doc_num)
        ->and($emptyReport['export_rows'][0])->toHaveCount(count($emptyReport['export_headings']));
});

test('price list output UI follows backend permissions', function (): void {
    $fixture = salesCycleFixture();
    $priceList = createSalesPriceList($fixture, null, []);
    foreach (['price_lists.view', 'price_lists.edit', 'price_lists.clone', 'price_lists.delete', 'price_lists.restore', 'price_lists.print'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }

    $page = $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.sales.price-lists.show', $priceList))->assertOk();
    $page->assertSee(route('admin.sales.price-lists.pdf', $priceList), false)
        ->assertDontSee(route('admin.sales.price-lists.print', $priceList), false)
        ->assertDontSee(route('admin.sales.price-lists.export.xlsx', $priceList), false)
        ->assertDontSee(route('admin.sales.price-lists.export.csv', $priceList), false);

    Permission::findOrCreate('price_lists.export', 'web');
    $fixture['user']->givePermissionTo('price_lists.export');
    $exportPage = $this->get(route('admin.sales.price-lists.show', $priceList))->assertOk();
    $exportPage->assertSee(route('admin.sales.price-lists.pdf', $priceList), false)
        ->assertSee(route('admin.sales.price-lists.export.xlsx', $priceList), false)
        ->assertSee(route('admin.sales.price-lists.export.csv', $priceList), false)
        ->assertSee('report-actions-toolbar', false);
    expect(substr_count((string) $exportPage->getContent(), 'js-report-export'))->toBe(2);

    $rowActions = view('modules.sales.price-lists.partials.actions', ['record' => $priceList])->render();
    expect($rowActions)
        ->toContain(route('admin.sales.price-lists.show', $priceList))
        ->toContain(route('admin.sales.price-lists.edit', $priceList))
        ->toContain(route('admin.sales.price-lists.destroy', $priceList))
        ->not->toContain(route('admin.sales.price-lists.pdf', $priceList))
        ->not->toContain(route('admin.sales.price-lists.export.xlsx', $priceList))
        ->not->toContain(route('admin.sales.price-lists.export.csv', $priceList))
        ->not->toContain(route('admin.sales.price-lists.clone', $priceList))
        ->not->toContain(route('admin.sales.price-lists.increase-by-percentage', $priceList))
        ->not->toContain(route('admin.sales.price-lists.history', $priceList));

    Permission::findOrCreate('price_lists.view_trashed', 'web');
    $fixture['user']->givePermissionTo('price_lists.view_trashed');
    $priceList->delete();
    $trashedRowActions = view('modules.sales.price-lists.partials.actions', ['record' => $priceList])->render();
    expect($trashedRowActions)
        ->toContain(route('admin.sales.price-lists.show', $priceList))
        ->toContain(route('admin.sales.price-lists.restore', $priceList))
        ->not->toContain(route('admin.sales.price-lists.edit', $priceList))
        ->not->toContain('data-delete-url="'.route('admin.sales.price-lists.destroy', $priceList).'"');

    $priceListUi = file_get_contents(resource_path('views/modules/sales/price-lists/partials/actions.blade.php'))
        .file_get_contents(resource_path('views/modules/sales/price-lists/partials/form-actions.blade.php'))
        .file_get_contents(public_path('assets/js/modules/Sales/price-lists-index.js'));
    expect(file_exists(resource_path('views/modules/sales/price-lists/print.blade.php')))->toBeFalse()
        ->and($priceListUi)->not->toContain('window.print', 'price_lists.actions.print');
});

test('deleted and cross company price lists remain protected while historical relations render', function (): void {
    $fixture = salesCycleFixture();
    $priceList = createSalesPriceList($fixture, $fixture['customer']->getKey(), [[
        'product' => $fixture['finished'], 'price' => '11.0000',
    ]]);
    foreach (['price_lists.view', 'price_lists.print', 'price_lists.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $customerName = $fixture['customer']->name;
    $currencyCode = $fixture['currency']->code;
    $productName = $fixture['finished']->name;
    $fixture['customer']->delete();
    $fixture['currency']->delete();
    $fixture['finished']->delete();
    $this->get(route('admin.sales.price-lists.pdf', $priceList))->assertOk()->assertHeader('content-type', 'application/pdf');
    $historicalReport = app(PriceListReportData::class)->build($priceList->fresh());
    expect($historicalReport['header']['customer_name'])->toBe($customerName)
        ->and($historicalReport['header']['currency_code'])->toBe($currencyCode)
        ->and($historicalReport['lines'][0]['product_name'])->toBe($productName);

    $priceList->delete();
    $this->get(route('admin.sales.price-lists.print', $priceList))->assertNotFound();
    $this->get(route('admin.sales.price-lists.export.csv', $priceList))->assertNotFound();
    $this->get(route('admin.sales.price-lists.history', $priceList))->assertNotFound();
    Permission::findOrCreate('price_lists.view_trashed', 'web');
    $fixture['user']->givePermissionTo('price_lists.view_trashed');
    $this->get(route('admin.sales.price-lists.print', $priceList))->assertRedirectToRoute('admin.sales.price-lists.pdf', $priceList);
    $this->get(route('admin.sales.price-lists.pdf', $priceList))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('admin.sales.price-lists.export.csv', $priceList))->assertDownload();
    $this->get(route('admin.sales.price-lists.history', $priceList))->assertOk();

    $otherCompany = Company::factory()->create();
    $priceList->restore();
    $priceList->update(['company_id' => $otherCompany->getKey()]);
    $this->get(route('admin.sales.price-lists.print', $priceList))->assertNotFound();
    $this->get(route('admin.sales.price-lists.history', $priceList))->assertNotFound();
});

test('price list report graph is bounded and print only output does not change operational eligibility', function (): void {
    $fixture = salesCycleFixture();
    $priceList = createSalesPriceList($fixture, null, [
        ['product' => $fixture['finished'], 'price' => '10'],
        ['product' => $fixture['service'], 'price' => '20'],
    ]);
    $priceList->update(['is_print_only' => true]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $report = app(PriceListReportData::class)->build(PriceList::query()->findOrFail($priceList->getKey()));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($report['lines'])->toHaveCount(2)
        ->and($queryCount)->toBeLessThanOrEqual(6)
        ->and(PriceList::query()->operationalPricingEligible()->whereKey($priceList)->exists())->toBeFalse();
});

test('price list review and approval are permission guarded ordered idempotent and invalidated by material changes', function (): void {
    $fixture = salesCycleFixture();
    $list = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $list->forceFill(['created_by' => $fixture['user']->getKey()])->save();
    $session = salesCycleSession($fixture);
    $this->actingAs($fixture['user'])->withSession($session);

    $this->postJson(route('admin.sales.price-lists.review', $list))->assertForbidden();
    foreach (['price_lists.view', 'price_lists.review', 'price_lists.approve', 'price_lists.edit'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }

    $this->postJson(route('admin.sales.price-lists.approve', $list))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('price_lists.messages.approval_requires_review'));

    $this->get(route('admin.sales.price-lists.show', $list))
        ->assertOk()
        ->assertSee(route('admin.sales.price-lists.review', $list), false)
        ->assertDontSee(route('admin.sales.price-lists.approve', $list), false);

    $this->postJson(route('admin.sales.price-lists.review', $list))->assertOk();
    $reviewedAt = $list->fresh()->reviewed_at;
    $this->postJson(route('admin.sales.price-lists.review', $list))->assertOk();
    expect($list->fresh()->reviewed_by)->toBe($fixture['user']->getKey())
        ->and($list->fresh()->reviewed_at->equalTo($reviewedAt))->toBeTrue()
        ->and(Activity::query()->where('subject_type', $list->getMorphClass())->where('subject_id', $list->getKey())->where('event', 'price_lists.review')->count())->toBe(1);

    $this->get(route('admin.sales.price-lists.show', $list))
        ->assertOk()
        ->assertSee(route('admin.sales.price-lists.approve', $list), false);
    $this->postJson(route('admin.sales.price-lists.approve', $list))->assertOk();
    $approvedAt = $list->fresh()->approved_at;
    $this->postJson(route('admin.sales.price-lists.approve', $list))->assertOk();
    expect($list->fresh()->approved_by)->toBe($fixture['user']->getKey())
        ->and($list->fresh()->approved_at->equalTo($approvedAt))->toBeTrue()
        ->and(Activity::query()->where('subject_type', $list->getMorphClass())->where('subject_id', $list->getKey())->where('event', 'price_lists.approve')->count())->toBe(1);

    $report = app(PriceListReportData::class)->build($list->fresh());
    expect($report['header']['prepared_by'])->toBe($fixture['user']->name)
        ->and($report['header']['reviewed_by'])->toBe($fixture['user']->name)
        ->and($report['header']['reviewed_at'])->not->toBeNull()
        ->and($report['header']['approved_by'])->toBe($fixture['user']->name)
        ->and($report['header']['approved_at'])->not->toBeNull();

    $historicalPreparer = User::factory()->create(['name' => 'Historical Price List Preparer']);
    $list->forceFill(['created_by' => $historicalPreparer->getKey()])->save();
    $historicalPreparer->delete();
    $historicalReport = app(PriceListReportData::class)->build($list->fresh());
    expect($historicalReport['header']['prepared_by'])->toBe('Historical Price List Preparer');

    $this->postJson(route('admin.sales.price-lists.increase-by-percentage', $list), ['percentage' => '5'])->assertOk();
    $list->refresh();
    expect($list->reviewed_by)->toBeNull()
        ->and($list->reviewed_at)->toBeNull()
        ->and($list->approved_by)->toBeNull()
        ->and($list->approved_at)->toBeNull();
    $change = Activity::query()->where('subject_type', $list->getMorphClass())->where('subject_id', $list->getKey())->where('event', 'price_lists.percentage')->latest('id')->firstOrFail();
    expect($change->properties->get('lifecycle_invalidated'))->toBeTrue();

    $this->postJson(route('admin.sales.price-lists.review', $list))->assertOk();
    $this->postJson(route('admin.sales.price-lists.approve', $list))->assertOk();
    $payload = priceListStorePayload($fixture, [[
        'product_doc_num' => $fixture['finished']->doc_num,
        'unit_price' => $list->lines()->sole()->unit_price,
        'allowed_discount_type' => null,
        'allowed_discount_value' => 0,
    ]]);
    $payload['is_print_only'] = '1';
    $this->put(route('admin.sales.price-lists.update', $list), $payload)->assertRedirect();
    expect($list->fresh()->reviewed_at)->toBeNull()->and($list->fresh()->approved_at)->toBeNull();
});

test('price list lifecycle actions reject deleted and cross company records', function (): void {
    $fixture = salesCycleFixture();
    foreach (['price_lists.review', 'price_lists.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $list = createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10']]);
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $list->delete();
    $this->postJson(route('admin.sales.price-lists.review', $list))->assertNotFound();
    $list->restore();
    $list->forceFill(['company_id' => Company::factory()->create()->getKey()])->save();
    $this->postJson(route('admin.sales.price-lists.review', $list))->assertNotFound();
});
