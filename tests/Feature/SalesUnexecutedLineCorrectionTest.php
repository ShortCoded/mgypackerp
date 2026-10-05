<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

beforeEach(function (): void {
    $this->fixture = salesCycleFixture();
    $this->actingAs($this->fixture['user'])->withSession(salesCycleSession($this->fixture));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($this->fixture));
});

test('unexecuted draft orders replace add remove and reorder items through the priced HTTP form', function (): void {
    $fixture = $this->fixture;
    $carton = ItemUnit::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 8002,
        'doc_num' => 'Unit-CORRECTION-CARTON', 'name' => 'Carton', 'status' => 'active']);
    $fixture['finished']->update(['equivalent_value' => '0.1', 'equivalent_unit_id' => $carton->id]);
    createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '5'], ['product' => $fixture['service'], 'price' => '33.75']]);
    $orders = app(SalesOrderService::class);
    $order = $orders->create(salesCycleOrderPayload($fixture));
    [$original, $removed] = $order->lines->all();
    $this->get(route('admin.sales.sales-orders.edit', $order))->assertOk()
        ->assertSee('data-identity-editable', false)
        ->assertSee(__('sales_ui.unexecuted_line_correction_help'));
    $beforeStock = DB::table('inventory_transactions')->get()->toArray();
    $beforeJournals = DB::table('journal_entries')->count();
    $payload = [
        'amendment_token' => $order->amendmentToken(), 'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'order_date' => $order->order_date->toDateString(),
        'expected_delivery_date' => $order->expected_delivery_date->toDateString(),
        'lines' => [
            ['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $carton->doc_num, 'quantity' => '1'],
            ['public_id' => $original->public_id, 'product_doc_num' => $fixture['service']->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num, 'description' => $original->description, 'quantity' => '2', 'unit_price' => '10'],
        ],
        'payment_schedules' => [['title' => 'Corrected value', 'due_date' => now()->addMonth()->toDateString(), 'amount' => '117.5']],
    ];
    $this->putJson(route('admin.sales.sales-orders.update', $order), $payload)->assertOk();
    $updated = $order->fresh()->load('lines');
    $replacement = $updated->lines->firstWhere('public_id', $original->public_id);
    expect($replacement->id)->toBe($original->id)
        ->and($replacement->product_id)->toBe($fixture['service']->id)
        ->and($replacement->unit_price)->toBe('33.75000000')
        ->and($replacement->description)->toBe($fixture['service']->name)
        ->and($replacement->line_number)->toBe(2)
        ->and($updated->lines->first()->conversion_factor)->toBe('10.00000000')
        ->and($updated->lines->first()->base_quantity)->toBe('10.00000000')
        ->and($updated->total_amount)->toBe('117.5000')
        ->and($updated->status)->toBe(SalesOrder::StatusDraft)
        ->and($updated->lines->pluck('id')->all())->not->toContain($removed->id)
        ->and(DB::table('inventory_transactions')->get()->toArray())->toEqual($beforeStock)
        ->and(DB::table('journal_entries')->count())->toBe($beforeJournals);
    $properties = json_decode(DB::table('activity_log')->where('event', 'sales_order.amended')->latest('id')->value('properties'), true);
    expect($properties['before_lines'][0]['product_id'])->toBe($fixture['finished']->id)
        ->and($properties['before_lines'][1]['id'])->toBe($removed->id)
        ->and($properties['after_lines'])->toHaveCount(2);
    $this->putJson(route('admin.sales.sales-orders.update', $order), $payload)->assertUnprocessable()
        ->assertJsonPath('message', __('sales_ui.amendment_stale'));
});

test('an approved request sourced order changes item identity after reopen while retaining original source history', function (): void {
    $fixture = $this->fixture;
    $requests = app(SalesRequestService::class);
    $record = $requests->save(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'customer_id' => $fixture['customer']->id, 'currency_id' => $fixture['currency']->id,
        'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '10']]]);
    $record = $requests->transition($requests->transition($record, 'submitted'), 'approved');
    createSalesPriceList($fixture, null, [['product' => $fixture['finished'], 'price' => '10'], ['product' => $fixture['service'], 'price' => '25']]);
    $orders = app(SalesOrderService::class);
    $order = $requests->convertToOrder($record, salesCycleOrderPayload($fixture, [
        'lines' => [['source_request_line_public_id' => $record->lines->sole()->public_id,
            'product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '10']],
        'payment_schedules' => [['title' => 'Original value', 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100']],
    ]));
    $order = $orders->approve($order);
    $sourceSnapshot = [$record->fresh()->attributesToArray(), $record->lines()->get()->map->attributesToArray()->all()];
    $line = $order->lines->sole();
    $this->postJson(route('admin.sales.sales-orders.reopen', $order), ['reason' => 'SYNTHETIC wrong product before execution'])->assertOk();
    $order = $order->fresh();
    $this->get(route('admin.sales.sales-orders.edit', $order))->assertOk()->assertSee('data-identity-editable', false);
    $this->putJson(route('admin.sales.sales-orders.update', $order), [
        'amendment_token' => $order->amendmentToken(), 'customer_doc_num' => $fixture['customer']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'order_date' => $order->order_date->toDateString(),
        'expected_delivery_date' => $order->expected_delivery_date->toDateString(),
        'lines' => [['public_id' => $line->public_id, 'product_doc_num' => $fixture['service']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => '4']],
        'payment_schedules' => [['title' => 'Corrected value', 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100']],
    ])->assertOk();
    $amended = $order->fresh();
    expect($amended->lines->sole()->id)->toBe($line->id)
        ->and($amended->lines->sole()->sales_request_line_id)->toBe($line->sales_request_line_id)
        ->and($amended->lines->sole()->product_id)->toBe($fixture['service']->id)
        ->and($amended->lines->sole()->unit_price)->toBe('25.00000000')
        ->and($amended->status)->toBe(SalesOrder::StatusReopened)
        ->and($amended->reopen_snapshot['lines'][0]['product_id'])->toBe($fixture['finished']->id)
        ->and([$record->fresh()->attributesToArray(), $record->lines()->get()->map->attributesToArray()->all()])->toBe($sourceSnapshot);
    $approved = $orders->approve($orders->submit($amended));
    expect($approved->status)->toBe(SalesOrder::StatusApproved)
        ->and($approved->lines->sole()->product_id)->toBe($fixture['service']->id);
});

test('unconverted sales request items can be replaced added and removed with reapproval and an original snapshot', function (bool $approved, string $locale): void {
    $fixture = $this->fixture;
    foreach (['sales_requests.edit', 'sales_requests.approve', 'sales_requests.reopen'] as $ability) {
        Permission::findOrCreate($ability, 'web');
    }
    $fixture['user']->givePermissionTo(['sales_requests.edit', 'sales_requests.approve', 'sales_requests.reopen']);
    $this->withSession(['locale' => $locale]);
    app()->setLocale($locale);
    $requests = app(SalesRequestService::class);
    $record = $requests->save(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'currency_id' => $fixture['currency']->id, 'request_date' => now()->toDateString(),
        'lines' => [
            ['product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '10'],
            ['product_id' => $fixture['service']->id, 'unit_id' => $fixture['unit']->id, 'quantity' => '1'],
        ]]);
    if ($approved) {
        $record = $requests->transition($requests->transition($record, 'submitted'), 'approved');
        $this->get(route('admin.sales.customer-requests.edit', $record))->assertUnprocessable();
        $this->postJson(route('admin.sales.customer-requests.reopen', $record), ['reason' => 'SYNTHETIC item correction'])->assertOk();
    }
    $this->get(route('admin.sales.customer-requests.edit', $record))->assertOk()->assertDontSee('data-amendment-locked-line', false);
    $payload = ['request_type' => 'internal', 'currency_doc_num' => $fixture['currency']->doc_num,
        'request_date' => now()->toDateString(), 'exchange_rate' => '1', 'lines' => [
            ['product_doc_num' => $fixture['service']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => '3'],
            ['product_doc_num' => $fixture['finished']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => '7'],
        ]];
    $this->putJson(route('admin.sales.customer-requests.update', $record), $payload)->assertOk();
    $updated = $record->fresh();
    expect($updated->lines->first()->product_id)->toBe($fixture['service']->id)
        ->and($updated->lines->first()->quantity)->toBe('3.00000000')
        ->and($updated->lines->last()->quantity)->toBe('7.00000000')
        ->and($updated->status)->toBe($approved ? SalesRequest::StatusReopened : SalesRequest::StatusDraft);
    if ($approved) {
        $history = collect($updated->status_history)->last();
        expect($history['before_snapshot']['lines'][0]['product_id'])->toBe($fixture['finished']->id)
            ->and($history['after_snapshot']['lines'][0]['product_id'])->toBe($fixture['service']->id);
        $this->postJson(route('admin.sales.customer-requests.transition', $record), ['status' => 'submitted'])->assertOk();
        $this->postJson(route('admin.sales.customer-requests.transition', $record), ['status' => 'approved'])->assertOk();
        expect($record->fresh()->status)->toBe(SalesRequest::StatusApproved);
    }
})->with([[false, 'en'], [true, 'en'], [false, 'ar'], [true, 'ar']]);

test('reservation history blocks product replacement and removal without changing any committed records', function (): void {
    $fixture = $this->fixture;
    $orders = app(SalesOrderService::class);
    $payload = salesCycleOrderPayload($fixture);
    $order = $orders->approve($orders->create($payload));
    app(SalesFulfillmentService::class)->reserve($order->lines->first(), '1');
    $order = $orders->reopen($order->fresh(), 'SYNTHETIC future quantity review');
    $this->get(route('admin.sales.sales-orders.edit', $order))->assertOk()->assertDontSee('data-identity-editable', false);
    expect($order->canReplaceUnexecutedLines())->toBeFalse();
    $payload['amendment_token'] = $order->amendmentToken();
    foreach ($order->lines as $index => $line) {
        $payload['lines'][$index]['public_id'] = $line->public_id;
    }
    $before = [$order->attributesToArray(), $order->lines()->get()->map->attributesToArray()->all(), DB::table('inventory_reservations')->get()->toArray(), DB::table('activity_log')->count()];
    $replacement = $payload;
    $replacement['lines'][0]['product_id'] = $fixture['service']->id;
    expect(fn () => $orders->update($order, $replacement))->toThrow(DomainException::class, __('sales_ui.production_amendment_locked_terms'));
    $removal = $payload;
    array_shift($removal['lines']);
    expect(fn () => $orders->update($order, $removal))->toThrow(DomainException::class, __('sales_ui.production_amendment_preserve_lines'));
    expect([$order->fresh()->attributesToArray(), $order->lines()->get()->map->attributesToArray()->all(), DB::table('inventory_reservations')->get()->toArray(), DB::table('activity_log')->count()])->toEqual($before);
});

test('unexecuted line corrections reject foreign or duplicate row identities and roll back failed schedules', function (): void {
    $orders = app(SalesOrderService::class);
    $payload = salesCycleOrderPayload($this->fixture);
    $order = $orders->create($payload)->fresh()->load('lines');
    $payload['amendment_token'] = $order->amendmentToken();
    foreach ($order->lines as $index => $line) {
        $payload['lines'][$index]['public_id'] = $line->public_id;
    }
    $before = [$order->attributesToArray(), $order->lines()->get()->map->attributesToArray()->all(), $order->paymentSchedules()->get()->map->attributesToArray()->all(), DB::table('activity_log')->count()];
    $unknown = $payload;
    $unknown['lines'][0]['public_id'] = (string) Str::uuid();
    expect(fn () => $orders->update($order, $unknown))->toThrow(DomainException::class, __('sales_ui.production_amendment_line_identity'));
    $duplicate = $payload;
    $duplicate['lines'][1]['public_id'] = $duplicate['lines'][0]['public_id'];
    expect(fn () => $orders->update($order, $duplicate))->toThrow(DomainException::class, __('sales_ui.production_amendment_line_identity'));
    $invalidSchedules = $payload;
    $invalidSchedules['lines'] = array_reverse($invalidSchedules['lines']);
    $invalidSchedules['lines'][0]['quantity'] = '2';
    expect(fn () => $orders->update($order, $invalidSchedules))->toThrow(DomainException::class);
    expect([$order->fresh()->attributesToArray(), $order->lines()->get()->map->attributesToArray()->all(), $order->paymentSchedules()->get()->map->attributesToArray()->all(), DB::table('activity_log')->count()])->toEqual($before);
});

test('unchanged retained sales rows keep contiguous numbering when only the header or another row changes', function (): void {
    $orders = app(SalesOrderService::class);
    $payload = salesCycleOrderPayload($this->fixture);
    $order = $orders->create($payload);
    $originalIds = $order->lines->modelKeys();
    foreach ($order->lines as $index => $line) {
        $payload['lines'][$index]['public_id'] = $line->public_id;
    }
    $payload['notes'] = 'SYNTHETIC header-only correction';
    $payload['amendment_token'] = $order->amendmentToken();
    $order = $orders->update($order, $payload);
    expect($order->lines->modelKeys())->toBe($originalIds)
        ->and($order->lines->pluck('line_number')->all())->toBe([1, 2]);
    $payload['lines'][0]['quantity'] = '101';
    $payload['payment_schedules'][0]['amount'] = '1110';
    $payload['amendment_token'] = $order->amendmentToken();
    $order = $orders->update($order, $payload);
    expect($order->lines->modelKeys())->toBe($originalIds)
        ->and($order->lines->pluck('line_number')->all())->toBe([1, 2]);
});
