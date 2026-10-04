<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\ProductComponent;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesOrderRemainderClosure;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesIssueOrderService;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

/** @return array<string, mixed> */
function declinedRemainderFixture(): array
{
    $fixture = salesCycleFixture();
    Permission::findOrCreate('sales_orders.close_remainder', 'web');
    $fixture['user']->givePermissionTo('sales_orders.close_remainder');
    test()->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }
    request()->session()->put(salesCycleSession($fixture));

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(
        salesCycleOrderPayload($fixture, [
            'lines' => [[
                'product_id' => $fixture['finished']->getKey(),
                'unit_id' => $fixture['unit']->getKey(),
                'description' => 'Declined remainder product',
                'quantity' => '100',
                'unit_price' => '10',
                'discount_amount' => '0',
                'tax_amount' => '0',
            ]],
            'payment_schedules' => [[
                'title' => 'Declined remainder invoice',
                'amount' => '1000',
                'due_date' => now()->addMonth()->toDateString(),
            ]],
        ]),
    ));

    return [...$fixture, 'order' => $order, 'line' => $order->lines->sole()];
}

/** @return array<string, mixed> */
function declinedRemainderPayload(SalesOrderLine $line, string $token, string $reason = 'Customer permanently declined the remaining twenty units.'): array
{
    return [
        '_submission_token' => $token,
        'closure_date' => now()->toDateString(),
        'reason' => $reason,
        'lines' => [[
            'sales_order_line_public_id' => $line->public_id,
            'expected_remaining_quantity' => '20',
        ]],
    ];
}

/** @return list<array<string, mixed>> */
function declinedRemainderAttributes($records): array
{
    return $records->map(fn ($record): array => $record->getAttributes())->all();
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function completeDeclinedRemainderProduction(array $fixture): array
{
    $fixture['branch']->forceFill(['type' => Branch::TypeFactory])->save();
    InventoryTransaction::query()->where('posting_key', 'sales-cycle-opening-stock')->delete();
    ProductComponent::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'product_id' => $fixture['finished']->getKey(),
        'component_product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'calculation_method' => ProductComponent::CalculationDirect,
        'quantity' => '2',
        'created_by' => $fixture['user']->getKey(),
    ]);
    InventoryTransaction::query()->create([
        'posting_key' => 'declined-remainder-production-raw',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'opening_stock',
        'stock_status' => InventoryTransaction::StatusAvailable,
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => '200',
        'quantity_out' => '0',
        'source_type' => 'synthetic_fixture',
        'source_id' => 1,
        'source_doc_num' => 'DECLINED-REMAINDER-RAW',
        'unit_cost' => '2',
        'total_cost' => '400',
        'created_by' => $fixture['user']->getKey(),
    ]);

    $production = app(SalesProductionDemandService::class)->create($fixture['order'], [[
        'sales_order_line_id' => $fixture['line']->getKey(),
        'quantity' => '100',
    ]]);
    $cycle = app(ProductionCycleService::class);
    $productionLine = $cycle->releaseOrder($production)->lines->sole();
    $run = $cycle->createRun($productionLine, [
        'planned_quantity' => '100',
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(2),
        'batch_lot' => 'DECLINED-REMAINDER-OUTPUT',
    ]);
    $cycle->reserveRun($run, $fixture['store']->getKey());
    $cycle->issueMaterials($run, $fixture['store']->getKey());
    $cycle->startSetup($run);
    $cycle->completeSetup($run->fresh());
    $cycle->startRun($run->fresh());
    $cycle->recordProgress($run->fresh(), ['good_base_quantity' => '100']);
    $materialAccounting = $run->fresh()->requirements->mapWithKeys(fn ($requirement): array => [
        $requirement->getKey() => [
            'consumed_quantity' => (string) $requirement->issued_quantity,
            'waste_quantity' => '0',
        ],
    ])->all();
    $cycle->accountMaterials($run->fresh(), $fixture['store']->getKey(), $materialAccounting);
    $inspection = $cycle->recordInspection($run->fresh(), ['result' => 'passed']);
    $cycle->reviewInspection($inspection, true);
    $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '100');
    $run = $cycle->completeRun($run->fresh());
    $receipt = InventoryDocument::query()
        ->where('production_run_id', $run->getKey())
        ->where('document_type', InventoryDocument::TypeProductionReceipt)
        ->sole();

    return compact('production', 'productionLine', 'run', 'receipt');
}

test('closing a declined twenty unit remainder preserves the delivered eighty and releases only unused reservation', function (): void {
    $fixture = declinedRemainderFixture();
    $fulfillment = app(SalesFulfillmentService::class);
    $reservation = $fulfillment->reserve($fixture['line'], '100');
    $delivery = $fulfillment->deliver($fixture['order'], [[
        'sales_order_line_id' => $fixture['line']->getKey(),
        'quantity' => '80',
    ]]);

    $availabilityBefore = app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['finished']->getKey(),
    );
    $deliverySnapshot = $delivery->fresh()->getAttributes();
    $deliveryTransactions = declinedRemainderAttributes($delivery->transactions()->orderBy('id')->get());
    $deliveryJournal = $delivery->journalEntry->fresh()->getAttributes();
    $inventoryTransactionCount = InventoryTransaction::query()->count();

    expect($fixture['order']->fresh()->status)->toBe(SalesOrder::StatusPartiallyFulfilled)
        ->and($fixture['line']->fresh()->delivered_quantity)->toBe('80.00000000')
        ->and($reservation->fresh()->status)->toBe(InventoryReservation::StatusActive)
        ->and($reservation->fresh()->consumed_quantity)->toBe('80.00000000')
        ->and($reservation->fresh()->remaining_quantity)->toBe('20.00000000')
        ->and($availabilityBefore['on_hand'])->toBe('20.00000000')
        ->and($availabilityBefore['available'])->toBe('0.00000000');

    $token = (string) Str::uuid();
    $payload = declinedRemainderPayload($fixture['line'], $token);
    $url = route('admin.sales.sales-orders.close-remainder', $fixture['order']);
    $first = $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk()->assertExactJson($first->json());
    $this->postJson($url, declinedRemainderPayload($fixture['line'], $token, 'Changed replay payload.'))
        ->assertConflict();

    $closure = SalesOrderRemainderClosure::query()->with('lines')->sole();
    $closureLine = $closure->lines->sole();
    $line = $fixture['line']->fresh();
    $reservation->refresh();
    $availabilityAfter = app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['finished']->getKey(),
    );

    expect($closure->status)->toBe(SalesOrderRemainderClosure::StatusApplied)
        ->and($closureLine->declined_quantity)->toBe('20.00000000')
        ->and($closureLine->delivered_quantity_snapshot)->toBe('80.00000000')
        ->and($closureLine->released_reservation_quantity)->toBe('20.00000000')
        ->and($fixture['order']->fresh()->status)->toBe(SalesOrder::StatusClosed)
        ->and($line->quantity)->toBe('100.00000000')
        ->and($line->delivered_quantity)->toBe('80.00000000')
        ->and($line->declined_quantity)->toBe('20.00000000')
        ->and($line->effectiveQuantity())->toBe('80.00000000')
        ->and($line->remainingDeliveryQuantity())->toBe('0.00000000')
        ->and($line->activeReservedQuantity())->toBe('0.00000000')
        ->and($reservation->status)->toBe(InventoryReservation::StatusReleased)
        ->and($reservation->consumed_quantity)->toBe('80.00000000')
        ->and($reservation->released_quantity)->toBe('20.00000000')
        ->and($reservation->remaining_quantity)->toBe('0.00000000')
        ->and($availabilityAfter['on_hand'])->toBe('20.00000000')
        ->and($availabilityAfter['available'])->toBe('20.00000000')
        ->and(InventoryTransaction::query()->count())->toBe($inventoryTransactionCount)
        ->and($delivery->fresh()->getAttributes())->toBe($deliverySnapshot)
        ->and(declinedRemainderAttributes($delivery->transactions()->orderBy('id')->get()))->toBe($deliveryTransactions)
        ->and($delivery->journalEntry->fresh()->getAttributes())->toBe($deliveryJournal)
        ->and(DB::table('document_submissions')->count())->toBe(1)
        ->and(DB::table(config('activitylog.table_name', 'activity_log'))
            ->where('event', 'sales_order.remainder_closed')
            ->where('subject_type', SalesOrderRemainderClosure::class)
            ->where('subject_id', $closure->getKey())
            ->count())->toBe(1);

    $migration = require base_path('modules/Sales/Database/Migrations/2026_10_04_080000_create_sales_order_remainder_closures.php');
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'this migration cannot be rolled back');
});

test('a closed remainder still permits invoicing the accepted eighty without creating a credit', function (): void {
    $fixture = declinedRemainderFixture();
    $delivery = app(SalesFulfillmentService::class)->deliver($fixture['order'], [[
        'sales_order_line_id' => $fixture['line']->getKey(),
        'quantity' => '80',
    ]]);

    $this->postJson(
        route('admin.sales.sales-orders.close-remainder', $fixture['order']),
        declinedRemainderPayload($fixture['line'], (string) Str::uuid()),
    )->assertOk();

    expect($fixture['order']->fresh()->status)->toBe(SalesOrder::StatusClosed)
        ->and($fixture['line']->fresh()->remainingInvoiceQuantity())->toBe('80.00000000')
        ->and(CustomerInvoice::query()->where('document_type', CustomerInvoice::TypeCreditNote)->count())->toBe(0);

    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder(
        $fixture['order']->fresh(),
        [[
            'sales_order_line_id' => $fixture['line']->getKey(),
            'delivery_line_id' => $delivery->lines->sole()->getKey(),
            'quantity' => '80',
        ]],
        [['due_date' => now()->toDateString(), 'amount' => '800']],
        $delivery,
    ));
    $line = $fixture['line']->fresh();

    expect($invoice->posting_status)->toBe('posted')
        ->and($invoice->total_amount)->toBe('800.0000')
        ->and($invoice->remaining_amount)->toBe('800.0000')
        ->and($line->invoiced_quantity)->toBe('80.00000000')
        ->and($line->remainder_credited_quantity)->toBe('0.00000000')
        ->and($line->netInvoicedQuantity())->toBe('80.00000000')
        ->and($line->remainingInvoiceQuantity())->toBe('0.00000000')
        ->and(CustomerInvoice::query()->where('document_type', CustomerInvoice::TypeCreditNote)->count())->toBe(0)
        ->and(SalesReturn::query()->count())->toBe(0)
        ->and($invoice->issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusIssued);

    expect(fn () => $invoices->createFromOrder(
        $fixture['order']->fresh(),
        [['sales_order_line_id' => $line->getKey(), 'quantity' => '1']],
        [['due_date' => now()->toDateString(), 'amount' => '10']],
    ))->toThrow(DomainException::class, __('Invoice quantity exceeds the remaining approved order quantity.'));
});

test('a legacy closed order without a declined remainder remains ineligible for invoicing', function (): void {
    $fixture = declinedRemainderFixture();
    $fixture['order']->update(['status' => SalesOrder::StatusClosed]);
    foreach (['customer_invoices.create', 'sales_orders.invoice', 'sales_orders.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }

    $this->getJson(route('admin.sales.select2.invoiceable-orders', ['q' => $fixture['order']->doc_num]))
        ->assertOk()
        ->assertJsonMissing(['id' => $fixture['order']->doc_num]);
    $this->get(route('admin.sales.sales-invoices.create', ['sales_order_doc_num' => $fixture['order']->doc_num]))
        ->assertNotFound();

    expect(fn () => app(CustomerInvoiceService::class)->createFromOrder(
        $fixture['order']->fresh(),
        [['sales_order_line_id' => $fixture['line']->getKey(), 'quantity' => '1']],
        [['due_date' => now()->toDateString(), 'amount' => '10']],
    ))->toThrow(DomainException::class, __('The sales order is not eligible for invoicing.'));
});

test('an invoice for one hundred receives one financial-only credit for the declined twenty without reversing delivery', function (): void {
    $fixture = declinedRemainderFixture();
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder(
        $fixture['order'],
        [['sales_order_line_id' => $fixture['line']->getKey(), 'quantity' => '100']],
        [['due_date' => now()->toDateString(), 'amount' => '1000']],
    ));
    $invoiceLine = $invoice->lines->sole();
    $issueOrder = $invoice->issueOrder()->sole();
    $delivery = app(SalesFulfillmentService::class)->deliverInvoice($invoice, [[
        'customer_invoice_line_id' => $invoiceLine->getKey(),
        'quantity' => '80',
    ]], [
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
    ]);
    $deliverySnapshot = $delivery->fresh()->getAttributes();
    $deliveryTransactions = declinedRemainderAttributes($delivery->transactions()->orderBy('id')->get());
    $deliveryJournal = $delivery->journalEntry->fresh()->getAttributes();
    $invoiceJournal = $invoice->journalEntry->fresh()->getAttributes();
    $inventoryTransactionCount = InventoryTransaction::query()->count();

    expect($issueOrder->status)->toBe(SalesIssueOrder::StatusPending)
        ->and(array_map(
            fn (array $row): string => $row['remaining'],
            app(SalesIssueOrderService::class)->remainingLines($invoice->fresh()),
        ))->toBe(['20.00000000']);

    $token = (string) Str::uuid();
    $payload = declinedRemainderPayload($fixture['line'], $token);
    $url = route('admin.sales.sales-orders.close-remainder', $fixture['order']);
    $first = $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk()->assertExactJson($first->json());

    $closure = SalesOrderRemainderClosure::query()->with('lines')->sole();
    $credit = CustomerInvoice::query()
        ->where('document_type', CustomerInvoice::TypeCreditNote)
        ->where('original_invoice_id', $invoice->getKey())
        ->sole();
    $creditLine = $credit->lines()->sole();
    $line = $fixture['line']->fresh();

    expect($fixture['order']->fresh()->status)->toBe(SalesOrder::StatusClosed)
        ->and($line->quantity)->toBe('100.00000000')
        ->and($line->delivered_quantity)->toBe('80.00000000')
        ->and($line->declined_quantity)->toBe('20.00000000')
        ->and($line->invoiced_quantity)->toBe('100.00000000')
        ->and($line->remainder_credited_quantity)->toBe('20.00000000')
        ->and($line->netInvoicedQuantity())->toBe('80.00000000')
        ->and($line->remainingInvoiceQuantity())->toBe('0.00000000')
        ->and($closure->lines->sole()->credited_remainder_quantity)->toBe('20.00000000')
        ->and($credit->posting_status)->toBe('posted')
        ->and($credit->total_amount)->toBe('200.0000')
        ->and($credit->credit_available_amount)->toBe('0.0000')
        ->and($credit->source_type)->toBe(SalesOrderRemainderClosure::class)
        ->and($credit->source_id)->toBe($closure->getKey())
        ->and($credit->credit_application_snapshot['applied_to_original'])->toBe('200.0000')
        ->and($creditLine->quantity)->toBe('20.00000000')
        ->and($creditLine->delivery_line_id)->toBeNull()
        ->and($creditLine->source_snapshot['financial_only'])->toBeTrue()
        ->and($invoice->fresh()->credited_amount)->toBe('200.0000')
        ->and($invoice->fresh()->remaining_amount)->toBe('800.0000')
        ->and($invoice->paymentSchedules()->sole()->credited_amount)->toBe('200.0000')
        ->and(SalesReturn::query()->count())->toBe(0)
        ->and($line->returned_quantity)->toBe('0.00000000')
        ->and(CustomerInvoice::query()->where('document_type', CustomerInvoice::TypeCreditNote)->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', 'customer_credit_note')->where('source_id', $credit->getKey())->count())->toBe(1)
        ->and(InventoryTransaction::query()->count())->toBe($inventoryTransactionCount)
        ->and($delivery->fresh()->getAttributes())->toBe($deliverySnapshot)
        ->and(declinedRemainderAttributes($delivery->transactions()->orderBy('id')->get()))->toBe($deliveryTransactions)
        ->and($delivery->journalEntry->fresh()->getAttributes())->toBe($deliveryJournal)
        ->and($invoice->journalEntry->fresh()->getAttributes())->toBe($invoiceJournal)
        ->and($invoice->journalEntry->fresh()->reversed_entry_id)->toBeNull()
        ->and($issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusShortClosed)
        ->and(app(SalesIssueOrderService::class)->remainingLines($invoice->fresh()))->toBe([])
        ->and(SalesOrderRemainderClosure::query()->count())->toBe(1)
        ->and(DB::table('document_submissions')->count())->toBe(1);

    expect(fn () => app(SalesFulfillmentService::class)->deliverInvoice($invoice->fresh(), [[
        'customer_invoice_line_id' => $invoiceLine->getKey(),
        'quantity' => '1',
    ]], [
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
    ]))->toThrow(DomainException::class, __('Delivery quantity exceeds the invoiced quantity remaining for delivery.'));
    expect(InventoryTransaction::query()->count())->toBe($inventoryTransactionCount)
        ->and($delivery->fresh()->getAttributes())->toBe($deliverySnapshot);

    foreach (['customer_invoices.view', 'sales_deliveries.create', 'sales_returns.create'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->getJson(route('admin.sales.select2.deliverable-invoices', ['q' => $invoice->doc_num]))
        ->assertOk()
        ->assertJsonMissing(['id' => $invoice->doc_num]);
    $this->getJson(route('admin.sales.select2.returnable-invoices', ['q' => $invoice->doc_num]))
        ->assertOk()
        ->assertJsonPath('results.0.id', $invoice->doc_num);
});

test('completed production of one hundred remains intact and leaves twenty available after closing the delivered eighty remainder', function (): void {
    $fixture = declinedRemainderFixture();
    $production = completeDeclinedRemainderProduction($fixture);
    $delivery = app(SalesFulfillmentService::class)->deliver($fixture['order']->fresh(), [[
        'sales_order_line_id' => $fixture['line']->getKey(),
        'quantity' => '80',
    ]]);

    $productionOrderSnapshot = $production['production']->fresh()->getAttributes();
    $productionLineSnapshot = $production['productionLine']->fresh()->getAttributes();
    $runSnapshot = $production['run']->fresh()->getAttributes();
    $receiptSnapshot = $production['receipt']->fresh()->getAttributes();
    $receiptTransactions = declinedRemainderAttributes($production['receipt']->transactions()->orderBy('id')->get());
    $deliverySnapshot = $delivery->fresh()->getAttributes();
    $inventoryTransactionCount = InventoryTransaction::query()->count();
    $availabilityBefore = app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['finished']->getKey(),
    );

    expect($production['production']->fresh()->status)->toBe(ProductionOrder::StatusCompleted)
        ->and($production['run']->fresh()->status)->toBe(ProductionRun::StatusCompleted)
        ->and($fixture['line']->fresh()->produced_quantity)->toBe('100.00000000')
        ->and($fixture['line']->fresh()->delivered_quantity)->toBe('80.00000000')
        ->and($availabilityBefore['on_hand'])->toBe('20.00000000')
        ->and($availabilityBefore['available'])->toBe('0.00000000');

    $this->postJson(
        route('admin.sales.sales-orders.close-remainder', $fixture['order']),
        declinedRemainderPayload($fixture['line'], (string) Str::uuid()),
    )->assertOk();

    $closureLine = SalesOrderRemainderClosure::query()->with('lines')->sole()->lines->sole();
    $line = $fixture['line']->fresh();
    $availabilityAfter = app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['finished']->getKey(),
    );

    expect($fixture['order']->fresh()->status)->toBe(SalesOrder::StatusClosed)
        ->and($line->produced_quantity)->toBe('100.00000000')
        ->and($line->production_requested_quantity)->toBe('100.00000000')
        ->and($line->delivered_quantity)->toBe('80.00000000')
        ->and($line->declined_quantity)->toBe('20.00000000')
        ->and($closureLine->released_reservation_quantity)->toBe('20.00000000')
        ->and($closureLine->released_production_quantity)->toBe('0.00000000')
        ->and($production['production']->fresh()->getAttributes())->toBe($productionOrderSnapshot)
        ->and($production['productionLine']->fresh()->getAttributes())->toBe($productionLineSnapshot)
        ->and($production['run']->fresh()->getAttributes())->toBe($runSnapshot)
        ->and($production['receipt']->fresh()->getAttributes())->toBe($receiptSnapshot)
        ->and(declinedRemainderAttributes($production['receipt']->transactions()->orderBy('id')->get()))->toBe($receiptTransactions)
        ->and($delivery->fresh()->getAttributes())->toBe($deliverySnapshot)
        ->and(InventoryTransaction::query()->count())->toBe($inventoryTransactionCount)
        ->and($availabilityAfter['on_hand'])->toBe('20.00000000')
        ->and($availabilityAfter['available'])->toBe('20.00000000')
        ->and(CustomerInvoice::query()->where('document_type', CustomerInvoice::TypeCreditNote)->count())->toBe(0);
});

test('the registered close remainder permission gates both the eligible order action and endpoint', function (): void {
    $fixture = declinedRemainderFixture();
    Permission::findOrCreate('sales_orders.view', 'web');
    $fixture['user']->givePermissionTo('sales_orders.view');
    $showUrl = route('admin.sales.sales-orders.show', $fixture['order']);

    expect(app(PermissionRegistryService::class)->all())->toContain('sales_orders.close_remainder');
    $this->get($showUrl)
        ->assertOk()
        ->assertSeeText('Close customer-declined remainder');

    $fixture['user']->revokePermissionTo('sales_orders.close_remainder');

    $this->get($showUrl)
        ->assertOk()
        ->assertDontSeeText('Close customer-declined remainder');
    $this->postJson(
        route('admin.sales.sales-orders.close-remainder', $fixture['order']),
        declinedRemainderPayload($fixture['line'], (string) Str::uuid()),
    )->assertForbidden();

    expect(SalesOrderRemainderClosure::query()->count())->toBe(0);
});
