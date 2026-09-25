<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Sales\Models\SalesDeliveryReceipt;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesDeliveryReceiptService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesIssueOrderService;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../SalesCycleSupport.php';

test('posting an invoice creates an issue order and the warehouse issues its exact lines once', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Finished goods for issue',
            'quantity' => '5',
            'unit_price' => '20',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '100', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '5',
    ]], [['due_date' => now()->toDateString(), 'amount' => '100']]));
    $issueOrder = $invoice->issueOrder()->firstOrFail();

    expect($issueOrder->status)->toBe(SalesIssueOrder::StatusPending)
        ->and($issueOrder->branch_store_id)->toBeNull();

    $issue = app(SalesIssueOrderService::class)->issue($issueOrder, $fixture['store'], now()->toDateString());

    expect($issue->status)->toBe(InventoryDocument::StatusPosted)
        ->and($issue->document_type)->toBe(InventoryDocument::TypeSalesDelivery)
        ->and($issue->sales_issue_order_id)->toBe($issueOrder->getKey())
        ->and((string) $issue->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->transaction_quantity, 8), '0'))->toBe('5.00000000')
        ->and($issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusIssued)
        ->and($issueOrder->fresh()->branch_store_id)->toBe($fixture['store']->getKey())
        ->and($invoice->deliveries()->count())->toBe(1);

    expect(fn () => app(SalesIssueOrderService::class)->issue($issueOrder, $fixture['store'], now()->toDateString()))
        ->toThrow(DomainException::class);
});

test('split invoice rows for one sales order line keep the correct remainder after a partial delivery', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Split invoice rows', 'quantity' => '5', 'unit_price' => '20',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '100', 'due_date' => now()->toDateString()]],
    ])));
    $orderLineId = $order->lines->sole()->getKey();
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [
        ['sales_order_line_id' => $orderLineId, 'quantity' => '2'],
        ['sales_order_line_id' => $orderLineId, 'quantity' => '3'],
    ], [['due_date' => now()->toDateString(), 'amount' => '100']]));
    $firstLine = $invoice->lines->first();

    app(SalesFulfillmentService::class)->deliverInvoice($invoice, [[
        'customer_invoice_line_id' => $firstLine->getKey(), 'quantity' => '1',
    ]], ['branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => now()->toDateString()]);

    $remaining = app(SalesIssueOrderService::class)->remainingLines($invoice->fresh());
    expect(array_map(fn (array $row): string => $row['remaining'], $remaining))->toBe(['1.00000000', '3.00000000']);

    $issue = app(SalesIssueOrderService::class)->issue($invoice->issueOrder, $fixture['store'], now()->toDateString());

    expect((string) $issue->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->transaction_quantity, 8), '0'))->toBe('4.00000000')
        ->and($issue->lines->pluck('source_line_id')->unique()->all())->toBe([$orderLineId])
        ->and($invoice->issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusIssued)
        ->and(app(SalesIssueOrderService::class)->remainingLines($invoice->fresh()))->toBe([]);
});

test('an issue order stays pending when the warehouse cannot cover every invoice line', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Stock not produced yet',
            'quantity' => '120',
            'unit_price' => '10',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '1200', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '120',
    ]], [['due_date' => now()->toDateString(), 'amount' => '1200']]));
    $issueOrder = $invoice->issueOrder()->firstOrFail();

    expect(fn () => app(SalesIssueOrderService::class)->issue($issueOrder, $fixture['store'], now()->toDateString()))
        ->toThrow(DomainException::class);
    expect($issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusPending)
        ->and($invoice->deliveries()->count())->toBe(0);
});

test('warehouse preview rejects a combined shortage for repeated finished goods lines', function (): void {
    $fixture = salesCycleFixture();
    foreach (['inventory.documents.create', 'inventory.documents.issue'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [
            [
                'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
                'description' => 'First finished goods line', 'quantity' => '60', 'unit_price' => '10',
            ],
            [
                'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
                'description' => 'Second finished goods line', 'quantity' => '60', 'unit_price' => '10',
            ],
        ],
        'payment_schedules' => [['title' => 'Due', 'amount' => '1200', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, $order->lines->map(fn ($line): array => [
        'sales_order_line_id' => $line->getKey(), 'quantity' => '60',
    ])->all(), [['due_date' => now()->toDateString(), 'amount' => '1200']]));

    $this->getJson(route('admin.inventory.documents.sales-issue-orders.details', [
        'salesIssueOrder' => $invoice->issueOrder,
        'branch_store_uuid' => $fixture['store']->public_uuid,
    ]))->assertOk()
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.can_issue', false)
        ->assertJsonPath('data.lines.0.enough', true)
        ->assertJsonPath('data.lines.1.enough', false);
});

test('warehouse preview includes reservations for every line issued together', function (): void {
    $fixture = salesCycleFixture();
    foreach (['inventory.documents.create', 'inventory.documents.issue'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'lines' => [
            [
                'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
                'description' => 'Reserved first line', 'quantity' => '30', 'unit_price' => '10',
            ],
            [
                'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
                'description' => 'Reserved second line', 'quantity' => '40', 'unit_price' => '10',
            ],
        ],
        'payment_schedules' => [['title' => 'Due', 'amount' => '700', 'due_date' => now()->toDateString()]],
    ])));
    $orderLines = $order->lines->values();
    $fulfillment = app(SalesFulfillmentService::class);
    $fulfillment->reserve($orderLines[0], '30');
    $fulfillment->reserve($orderLines[1], '40');
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [
        ['sales_order_line_id' => $orderLines[0]->getKey(), 'quantity' => '30'],
        ['sales_order_line_id' => $orderLines[1]->getKey(), 'quantity' => '40'],
    ], [['due_date' => now()->toDateString(), 'amount' => '700']]));

    $this->getJson(route('admin.inventory.documents.sales-issue-orders.details', [
        'salesIssueOrder' => $invoice->issueOrder,
        'branch_store_uuid' => $fixture['store']->public_uuid,
    ]))->assertOk()
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.can_issue', true)
        ->assertJsonPath('data.lines.0.enough', true)
        ->assertJsonPath('data.lines.1.enough', true);

    $issue = app(SalesIssueOrderService::class)->issue($invoice->issueOrder, $fixture['store'], now()->toDateString());
    expect($issue->status)->toBe(InventoryDocument::StatusPosted)
        ->and((string) $issue->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->transaction_quantity, 8), '0'))->toBe('70.00000000');
});

test('warehouse preview does not release an unused reservation to another order line', function (): void {
    $fixture = salesCycleFixture();
    foreach (['inventory.documents.create', 'inventory.documents.issue'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'lines' => [
            [
                'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
                'description' => 'Partly invoiced reserved line', 'quantity' => '80', 'unit_price' => '10',
            ],
            [
                'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
                'description' => 'Unreserved line', 'quantity' => '70', 'unit_price' => '10',
            ],
        ],
        'payment_schedules' => [['title' => 'Due', 'amount' => '1500', 'due_date' => now()->toDateString()]],
    ])));
    $orderLines = $order->lines->values();
    app(SalesFulfillmentService::class)->reserve($orderLines[0], '80');
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [
        ['sales_order_line_id' => $orderLines[0]->getKey(), 'quantity' => '10'],
        ['sales_order_line_id' => $orderLines[1]->getKey(), 'quantity' => '70'],
    ], [['due_date' => now()->toDateString(), 'amount' => '800']]));

    $this->getJson(route('admin.inventory.documents.sales-issue-orders.details', [
        'salesIssueOrder' => $invoice->issueOrder,
        'branch_store_uuid' => $fixture['store']->public_uuid,
    ]))->assertOk()
        ->assertJsonPath('data.can_issue', false)
        ->assertJsonPath('data.lines.0.enough', true)
        ->assertJsonPath('data.lines.1.enough', false);

    expect(fn () => app(SalesIssueOrderService::class)->issue($invoice->issueOrder, $fixture['store'], now()->toDateString()))
        ->toThrow(DomainException::class);
    expect($invoice->issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusPending);
});

test('customer receipt requires a posted issue and signed proof and prevents reversing an accepted issue', function (): void {
    Storage::fake('local');
    $fixture = salesCycleFixture();
    foreach (['sales_deliveries.view', 'sales_deliveries.receive'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Signed customer receipt', 'quantity' => '2', 'unit_price' => '20',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '40', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '2',
    ]], [['due_date' => now()->toDateString(), 'amount' => '40']]));
    $issue = app(SalesIssueOrderService::class)->issue($invoice->issueOrder, $fixture['store'], now()->toDateString());

    $this->get(route('admin.sales.delivery-receipts.create', $issue))->assertOk();
    $receipt = app(SalesDeliveryReceiptService::class)->record($issue, ['recipient_name' => 'Client Signer'], UploadedFile::fake()->image('signature.png'));

    expect($receipt)->toBeInstanceOf(SalesDeliveryReceipt::class)
        ->and($receipt->customer_invoice_id)->toBe($invoice->getKey())
        ->and($receipt->inventory_document_id)->toBe($issue->getKey());
    Storage::disk('local')->assertExists($receipt->signature_path);
    $this->get(route('admin.sales.delivery-receipts.show', $receipt))->assertOk()->assertSee('Client Signer');
    $this->get(route('admin.sales.delivery-receipts.signature', $receipt))->assertOk();
    $this->get(route('admin.sales.delivery-receipts.create', $issue))->assertNotFound();
    expect(fn () => app(SalesDeliveryReceiptService::class)->record($issue, ['recipient_name' => 'Duplicate'], UploadedFile::fake()->image('duplicate.png')))
        ->toThrow(DomainException::class);
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($issue))
        ->toThrow(DomainException::class);
});

test('reversing an unsigned warehouse issue restores the pending order and reissues without stale invoice links', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Reissue after reversal', 'quantity' => '2', 'unit_price' => '20',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '40', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '2',
    ]], [['due_date' => now()->toDateString(), 'amount' => '40']]));
    $issueOrder = $invoice->issueOrder()->firstOrFail();
    $firstIssue = app(SalesIssueOrderService::class)->issue($issueOrder, $fixture['store'], now()->toDateString());

    app(InventoryDocumentPostingService::class)->reverse($firstIssue);

    expect($issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusPending)
        ->and($invoice->fresh()->delivery_document_id)->toBeNull();

    $secondIssue = app(SalesIssueOrderService::class)->issue($issueOrder, $fixture['store'], now()->toDateString());

    expect($secondIssue->getKey())->not->toBe($firstIssue->getKey())
        ->and($secondIssue->status)->toBe(InventoryDocument::StatusPosted)
        ->and($invoice->fresh()->delivery_document_id)->toBe($secondIssue->getKey())
        ->and($issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusIssued);
});

test('reversing an issue retains another posted delivery as the invoice delivery pointer', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Two partial deliveries', 'quantity' => '2', 'unit_price' => '20',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '40', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '2',
    ]], [['due_date' => now()->toDateString(), 'amount' => '40']]));
    $legacyDelivery = app(SalesFulfillmentService::class)->deliverInvoice($invoice, [[
        'customer_invoice_line_id' => $invoice->lines->sole()->getKey(), 'quantity' => '1',
    ]], [
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
    ]);
    $issue = app(SalesIssueOrderService::class)->issue($invoice->issueOrder, $fixture['store'], now()->toDateString());
    $invoice->update(['delivery_document_id' => $issue->getKey()]);

    app(InventoryDocumentPostingService::class)->reverse($issue);

    expect($invoice->fresh()->delivery_document_id)->toBe($legacyDelivery->getKey())
        ->and($legacyDelivery->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($invoice->issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusPending);
});

test('sales issue migration blocks rollback when business records exist', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Rollback proof', 'quantity' => '1', 'unit_price' => '20',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '20', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '1',
    ]], [['due_date' => now()->toDateString(), 'amount' => '20']]));
    $migration = require base_path('modules/Sales/Database/Migrations/2026_09_25_031448_create_sales_issue_orders_and_receipts.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'cannot be rolled back');
    expect(SalesIssueOrder::query()->count())->toBe(1);
});

test('sales issue migration rejects a historical delivery shared by two invoices', function (): void {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Historical shared delivery', 'quantity' => '2', 'unit_price' => '20',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '40', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $firstInvoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '1',
    ]], [['due_date' => now()->toDateString(), 'amount' => '20']]));
    $secondInvoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '1',
    ]], [['due_date' => now()->toDateString(), 'amount' => '20']]));
    $delivery = app(SalesFulfillmentService::class)->deliverInvoice($firstInvoice, [[
        'customer_invoice_line_id' => $firstInvoice->lines->sole()->getKey(), 'quantity' => '1',
    ]], [
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
    ]);
    DB::table('customer_invoice_deliveries')->insert([
        'customer_invoice_id' => $secondInvoice->getKey(),
        'inventory_document_id' => $delivery->getKey(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $migration = require base_path('modules/Sales/Database/Migrations/2026_09_25_031448_create_sales_issue_orders_and_receipts.php');

    expect(fn () => $migration->assertNoSharedHistoricalDeliveries())->toThrow(RuntimeException::class, 'linked to multiple invoices');
});

test('warehouse issue endpoint rejects changed invoice lines', function (): void {
    $fixture = salesCycleFixture();
    foreach (['inventory.documents.create', 'inventory.documents.issue'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Protected issue lines', 'quantity' => '2', 'unit_price' => '20',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '40', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '2',
    ]], [['due_date' => now()->toDateString(), 'amount' => '40']]));
    $issueOrder = $invoice->issueOrder()->firstOrFail();

    $this->postJson(route('admin.inventory.documents.sales-issue.store'), [
        'sales_issue_order_doc_num' => $issueOrder->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
        'lines' => [['product_id' => $fixture['finished']->getKey(), 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('lines');

    expect($issueOrder->fresh()->status)->toBe(SalesIssueOrder::StatusPending)
        ->and($invoice->deliveries()->count())->toBe(0);
});

test('a warehouse branch can issue an invoice from the sales branch and sales records the signed receipt', function (): void {
    Storage::fake('local');
    $fixture = salesCycleFixture();
    foreach (['inventory.documents.create', 'inventory.documents.issue', 'sales_deliveries.receive', 'sales_deliveries.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $warehouseBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 99001,
        'doc_num' => 'Branch-WAREHOUSE', 'name' => 'Factory warehouse',
        'type' => Branch::TypeFactory, 'status' => 'active',
    ]);
    $warehouseStore = BranchStore::query()->create([
        'branch_id' => $warehouseBranch->getKey(), 'name' => 'Factory finished goods', 'position' => 1,
    ]);
    InventoryTransaction::query()->where('posting_key', 'sales-cycle-opening-stock')->update([
        'branch_id' => $warehouseBranch->getKey(), 'branch_store_id' => $warehouseStore->getKey(),
    ]);
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Factory finished goods', 'quantity' => '2', 'unit_price' => '20',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '40', 'due_date' => now()->toDateString()]],
    ])));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => '2',
    ]], [['due_date' => now()->toDateString(), 'amount' => '40']]));
    $issueOrder = $invoice->issueOrder()->firstOrFail();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    $this->get(route('admin.sales.issue-orders.show', $issueOrder))->assertOk()->assertSee($issueOrder->doc_num);
    $warehouseSession = salesCycleSession($fixture);
    $warehouseSession[OperatingContextService::BranchIdKey] = $warehouseBranch->getKey();
    $warehouseSession[OperatingContextService::BranchDocNumKey] = $warehouseBranch->doc_num;
    $this->actingAs($fixture['user'])->withSession($warehouseSession);

    $this->get(route('admin.inventory.documents.sales-issue.create', ['issue_order' => $issueOrder->doc_num]))->assertOk();
    $this->getJson(route('admin.inventory.documents.select2.sales-issue-orders', [
        'branch_store_uuid' => $warehouseStore->public_uuid,
    ]))->assertOk()->assertSee($issueOrder->doc_num);
    $this->getJson(route('admin.inventory.documents.sales-issue-orders.details', [
        'salesIssueOrder' => $issueOrder, 'branch_store_uuid' => $warehouseStore->public_uuid,
    ]))->assertOk()->assertJsonPath('data.can_issue', true);
    $this->postJson(route('admin.inventory.documents.sales-issue.store'), [
        'sales_issue_order_doc_num' => $issueOrder->doc_num,
        'branch_store_uuid' => $warehouseStore->public_uuid,
        'document_date' => now()->toDateString(),
    ])->assertCreated();

    $issue = $issueOrder->issues()->sole();
    expect($issue->branch_id)->toBe($warehouseBranch->getKey())
        ->and($issue->branch_store_id)->toBe($warehouseStore->getKey())
        ->and($invoice->fresh()->branch_id)->toBe($fixture['branch']->getKey());

    $this->withSession(salesCycleSession($fixture));
    $this->get(route('admin.sales.delivery-receipts.create', $issue))->assertOk();
    $this->post(route('admin.sales.delivery-receipts.store', $issue), [
        'recipient_name' => 'Customer signer',
        'signature' => UploadedFile::fake()->image('customer-signature.png'),
    ])->assertRedirect();

    $receipt = SalesDeliveryReceipt::query()->where('inventory_document_id', $issue->getKey())->sole();
    expect($receipt->branch_id)->toBe($fixture['branch']->getKey())
        ->and($receipt->customer_invoice_id)->toBe($invoice->getKey());
    Storage::disk('local')->assertExists($receipt->signature_path);
});
