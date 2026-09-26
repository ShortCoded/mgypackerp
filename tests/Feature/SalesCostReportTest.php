<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Illuminate\Http\Request;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Sales\Exports\SalesCycleReportExport;
use Modules\Sales\Http\Controllers\SalesCycleReportController;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

function costReportPermissions(array $fixture): void
{
    foreach (['reports.sales.cost_of_sales.view', 'reports.sales.cost_of_sales.print', 'reports.sales.cost_of_sales.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo([
        'reports.sales.cost_of_sales.view',
        'reports.sales.cost_of_sales.print',
        'reports.sales.cost_of_sales.export',
    ]);
}

/** @return array{data: array, screen: string} */
function costReportDataAndScreen(mixed $testInstance, array $fixture, array $session, array $params = []): array
{
    $data = costReportControllerData($fixture, $session, $params);

    $screen = $testInstance->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'cost_of_sales', ...$params]))
        ->assertOk()->getContent();

    return compact('data', 'screen');
}

/** @return array<string, mixed> */
function costReportControllerData(array $fixture, array $session, array $params = []): array
{
    $report = app()->make(SalesCycleReportController::class);
    $request = Request::create(
        route('admin.reports.sales.sales-orders.index'),
        'GET',
        ['report' => 'cost_of_sales', ...$params],
    );
    $request->setUserResolver(fn () => $fixture['user']);
    $request->setLaravelSession(session()->driver());
    foreach ($session as $key => $value) {
        session()->put($key, $value);
    }

    return $report->index($request)->getData();
}

/**
 * Create a full sales cycle: order → deliver → invoice, returning
 * the delivery and invoice for downstream use.
 */
function costReportDeliverAndInvoice(array $fixture): array
{
    $order = app(SalesOrderService::class)->approve(
        app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)),
    );

    $delivery = app(SalesFulfillmentService::class)->deliver(
        $order,
        [['sales_order_line_id' => $order->lines->first()->getKey(), 'quantity' => '100']],
        ['branch_store_id' => $fixture['store']->getKey()],
    );

    $invoice = app(CustomerInvoiceService::class)->post(
        app(CustomerInvoiceService::class)->createFromOrder(
            $order,
            [['sales_order_line_id' => $order->lines->first()->getKey(), 'quantity' => '100']],
            [['due_date' => now()->toDateString(), 'amount' => '1000.0000']],
            $delivery,
        ),
    );

    return compact('order', 'delivery', 'invoice');
}

/*
|--------------------------------------------------------------------------
| A. Canonical delivery and return trace
|--------------------------------------------------------------------------
*/

test('delivery report rows equal posted inventory cost and COGS journal debit', function () {
    $fixture = salesCycleFixture();
    costReportPermissions($fixture);
    $session = salesCycleSession($fixture);

    ['delivery' => $delivery] = costReportDeliverAndInvoice($fixture);

    $delivery->load('lines.product', 'journalEntry.lines');
    $expectedCost = '0.0000';
    foreach ($delivery->lines as $line) {
        $expectedCost = bcadd($expectedCost, (string) $line->total_cost, 4);
    }
    expect(bccomp($expectedCost, '0', 4) > 0)->toBeTrue('Delivery must have non-zero inventory cost');

    $cogsAccount = app(PostingAccountResolver::class)
        ->resolveFirst((int) $fixture['company']->getKey(), PostingAccountResolver::CostOfGoodsSold, 'test');
    $journal = $delivery->journalEntry;
    expect($journal)->not->toBeNull();
    $cogsDebit = '0.0000';
    foreach ($journal->lines as $jl) {
        if ((int) $jl->account_id === (int) $cogsAccount->getKey()) {
            $cogsDebit = bcadd($cogsDebit, (string) $jl->debit_amount, 4);
        }
    }
    expect(bccomp($cogsDebit, $expectedCost, 4))->toBe(0, 'COGS journal debit must equal delivery aggregate cost');

    ['data' => $data] = costReportDataAndScreen($this, $fixture, $session);
    $rows = $data['costOfSalesRows'];
    expect($rows->count())->toBeGreaterThan(0, 'Report must have delivery rows');

    $reportDeliveryCost = '0.0000';
    foreach ($rows as $row) {
        expect($row['movement_kind'])->toBe('delivery');
        expect($row['signed_total_cost'])->not->toBeNull();
        $reportDeliveryCost = bcadd($reportDeliveryCost, (string) $row['signed_total_cost'], 4);
    }
    expect(bccomp($reportDeliveryCost, $expectedCost, 4))->toBe(0, 'Report delivery aggregate must equal posted cost');
});

test('return report rows carry negative COGS from quarantine journal not disposition journal', function () {
    $fixture = salesCycleFixture();
    costReportPermissions($fixture);
    $session = salesCycleSession($fixture);

    ['delivery' => $delivery] = costReportDeliverAndInvoice($fixture);

    $returnService = app(SalesReturnService::class);
    $return = $returnService->createFromDelivery(
        $delivery,
        SalesReturn::ReasonDamaged,
        'E2E return test',
        [['delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '20']],
    );
    $return = $returnService->authorize($return);
    $return = $returnService->receive($return);
    $return = $returnService->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(),
        'saleable_quantity' => '20',
    ]]);
    $return = $returnService->close($return);
    $return->load(['returnInventoryDocument.lines.product', 'quarantineJournalEntry.lines']);

    $invDoc = $return->returnInventoryDocument;
    expect($invDoc)->not->toBeNull();
    expect($invDoc->status)->toBe('posted');

    $returnAggregateCost = '0.0000';
    foreach ($invDoc->lines as $line) {
        $returnAggregateCost = bcadd($returnAggregateCost, (string) $line->total_cost, 4);
    }

    $cogsAccount = app(PostingAccountResolver::class)
        ->resolveFirst((int) $fixture['company']->getKey(), PostingAccountResolver::CostOfGoodsSold, 'test');
    $qJournal = $return->quarantineJournalEntry;
    expect($qJournal)->not->toBeNull();
    $cogsCredit = '0.0000';
    foreach ($qJournal->lines as $jl) {
        if ((int) $jl->account_id === (int) $cogsAccount->getKey()) {
            $cogsCredit = bcadd($cogsCredit, (string) $jl->credit_amount, 4);
        }
    }
    expect(bccomp($cogsCredit, $returnAggregateCost, 4))->toBe(0, 'Quarantine journal COGS credit must equal return cost');

    ['data' => $data] = costReportDataAndScreen($this, $fixture, $session);
    $rows = $data['costOfSalesRows'];
    $returnRows = $rows->where('movement_kind', 'return');
    expect($returnRows->count())->toBeGreaterThan(0, 'Report must have return rows');

    $reportReturnCost = '0.0000';
    foreach ($returnRows as $row) {
        expect(bccomp((string) $row['signed_total_cost'], '0', 4))->toBeLessThan(0, 'Return rows must be negative');
        $reportReturnCost = bcadd($reportReturnCost, (string) $row['signed_total_cost'], 4);
    }

    $reportDeliveryCost = '0.0000';
    foreach ($rows->where('movement_kind', 'delivery') as $row) {
        $reportDeliveryCost = bcadd($reportDeliveryCost, (string) $row['signed_total_cost'], 4);
    }
    $expectedNet = bcadd($reportDeliveryCost, $reportReturnCost, 4);
    expect(bccomp((string) $data['costOfSalesSummary']['net_cost'], $expectedNet, 4))->toBe(0, 'Net cost must equal delivery + return');
    expect((int) $data['costOfSalesSummary']['return_count'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| B. Filters and outputs
|--------------------------------------------------------------------------
*/

test('product filter emits only matching product rows but source still reconciled', function () {
    $fixture = salesCycleFixture();
    costReportPermissions($fixture);
    $session = salesCycleSession($fixture);

    // Create a second finished product with its own opening stock
    $secondProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 9002,
        'doc_num' => 'Product-2ND', 'name' => 'Second Finished',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);

    InventoryTransaction::query()->create([
        'posting_key' => 'cost-report-2nd-opening', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(), 'transaction_date' => now()->toDateString(),
        'transaction_type' => 'opening_stock', 'product_id' => $secondProduct->getKey(),
        'unit_id' => $fixture['unit']->getKey(), 'batch_lot' => 'COST-2ND-OPENING',
        'quantity_in' => '80', 'quantity_out' => 0, 'source_type' => 'test_opening_stock',
        'source_id' => 2, 'source_doc_num' => 'TEST-STOCK-2ND', 'unit_cost' => '3', 'total_cost' => '240',
        'created_by' => $fixture['user']->getKey(),
    ]);

    // Order with both finished products
    $order = app(SalesOrderService::class)->approve(
        app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
            'lines' => [
                ['product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
                    'description' => 'Finished Crate', 'quantity' => '30', 'unit_price' => '10',
                    'discount_amount' => 0, 'tax_amount' => 0],
                ['product_id' => $secondProduct->getKey(), 'unit_id' => $fixture['unit']->getKey(),
                    'description' => 'Second Finished', 'quantity' => '20', 'unit_price' => '8',
                    'discount_amount' => 0, 'tax_amount' => 0],
            ],
            'payment_schedules' => [['title' => 'Full', 'amount' => '460', 'due_date' => now()->toDateString()]],
        ])),
    );

    // Deliver both physical lines in a single canonical delivery
    $delivery = app(SalesFulfillmentService::class)->deliver(
        $order,
        [
            ['sales_order_line_id' => $order->lines->first()->getKey(), 'quantity' => '30'],
            ['sales_order_line_id' => $order->lines->last()->getKey(), 'quantity' => '20'],
        ],
        ['branch_store_id' => $fixture['store']->getKey()],
    );

    // No product filter: both lines appear
    ['data' => $allData] = costReportDataAndScreen($this, $fixture, $session);
    $all = $allData['costOfSalesRows'];
    expect($all->count())->toBeGreaterThanOrEqual(2);

    // Filter to finished product: only finished rows
    ['data' => $filteredData] = costReportDataAndScreen($this, $fixture, $session, [
        'product_doc_num' => $fixture['finished']->doc_num,
    ]);
    $filteredRows = $filteredData['costOfSalesRows'];
    expect($filteredRows->count())->toBeGreaterThanOrEqual(1);
    foreach ($filteredRows as $row) {
        expect($row['product'])->toBe('Finished Crate');
        // Source journal is reconciled because reconciliation runs against the full unfiltered source
        expect($row['reconciliation_status'])->toBe('reconciled');
    }

    // Filter to second product: only second product rows
    ['data' => $secondData] = costReportDataAndScreen($this, $fixture, $session, [
        'product_doc_num' => $secondProduct->doc_num,
    ]);
    $secondRows = $secondData['costOfSalesRows'];
    expect($secondRows->count())->toBeGreaterThanOrEqual(1);
    foreach ($secondRows as $row) {
        expect($row['product'])->toBe('Second Finished');
        expect($row['reconciliation_status'])->toBe('reconciled');
    }

    // Filter to a product not on the delivery: empty
    ['data' => $emptyData] = costReportDataAndScreen($this, $fixture, $session, [
        'product_doc_num' => $fixture['semiFinished']->doc_num,
    ]);
    expect($emptyData['costOfSalesRows']->count())->toBe(0);
    expect((int) $emptyData['costOfSalesSummary']['delivery_count'])->toBe(0);
});

test('inclusive date and company branch period and customer isolation', function () {
    $fixture = salesCycleFixture();
    costReportPermissions($fixture);
    $session = salesCycleSession($fixture);

    ['delivery' => $delivery] = costReportDeliverAndInvoice($fixture);

    $deliveryDate = $delivery->document_date->toDateString();

    ['data' => $included] = costReportDataAndScreen($this, $fixture, $session, ['from' => $deliveryDate, 'to' => $deliveryDate]);
    expect($included['costOfSalesRows']->count())->toBeGreaterThanOrEqual(1);

    ['data' => $excluded] = costReportDataAndScreen($this, $fixture, $session, [
        'from' => now()->subYear()->toDateString(),
        'to' => now()->subYear()->addDay()->toDateString(),
    ]);
    expect($excluded['costOfSalesRows']->count())->toBe(0);

    $otherCustomer = Customer::query()->create([
        'doc_number' => 99001, 'doc_num' => 'COST-OTHER', 'company_id' => $fixture['company']->getKey(),
        'name' => 'Other Customer', 'status' => 'active',
    ]);
    ['data' => $customerFiltered] = costReportDataAndScreen($this, $fixture, $session, [
        'customer_doc_num' => $otherCustomer->doc_num,
    ]);
    expect($customerFiltered['costOfSalesRows']->count())->toBe(0);
});

test('permission denial blocks cost of sales report', function () {
    $fixture = salesCycleFixture();
    $session = salesCycleSession($fixture);

    $guest = User::factory()->create();
    $this->actingAs($guest)->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'cost_of_sales']))
        ->assertForbidden();
});

test('screen contains row table and totals PDF is valid XLSX and CSV contain matching cost rows', function () {
    $fixture = salesCycleFixture();
    costReportPermissions($fixture);
    $session = salesCycleSession($fixture);

    ['delivery' => $delivery] = costReportDeliverAndInvoice($fixture);

    ['data' => $data, 'screen' => $screen] = costReportDataAndScreen($this, $fixture, $session);

    expect($screen)->toContain('cost-of-sales');
    expect($screen)->toContain('Totals');

    $pdf = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.print', ['report' => 'cost_of_sales']))
        ->assertOk();
    expect($pdf->getContent())->toStartWith('%PDF-');
    expect(salesPdfText($pdf->getContent()))->toContain('Totals');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.export', ['report' => 'cost_of_sales', 'format' => 'xlsx']))
        ->assertOk()->assertDownload();
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.export', ['report' => 'cost_of_sales', 'format' => 'csv']))
        ->assertOk()->assertDownload();

    $data['costOfSalesRows'] = collect($data['costOfSalesRows']->all());
    $sheets = array_values((new SalesCycleReportExport($data))->sheets());
    expect(count($sheets))->toBe(2);

    // First sheet is summary, second is detail (order defined in export match)
    $summarySheet = $sheets[0];
    $detailSheet = $sheets[1];

    // The export builds titles via __() which resolves to English in the seeded locale
    expect($summarySheet->title())->toBe('Cost of Sales Summary');
    expect($detailSheet->title())->toBe('Cost of Sales');

    $detailRows = $detailSheet->array();
    expect(count($detailRows))->toBe($data['costOfSalesRows']->count());

    $summaryRows = $summarySheet->array();
    expect(count($summaryRows))->toBeGreaterThanOrEqual(6);
});

test('empty state shows zero totals and no rows', function () {
    $fixture = salesCycleFixture();
    costReportPermissions($fixture);
    $session = salesCycleSession($fixture);

    ['data' => $data] = costReportDataAndScreen($this, $fixture, $session);
    expect($data['costOfSalesRows']->count())->toBe(0);
    expect((int) $data['costOfSalesSummary']['delivery_count'])->toBe(0);
    expect((int) $data['costOfSalesSummary']['return_count'])->toBe(0);
    expect($data['costOfSalesSummary']['delivery_cost'])->toBe('0.0000');
    expect($data['costOfSalesSummary']['return_cost'])->toBe('0.0000');
    expect($data['costOfSalesSummary']['net_cost'])->toBe('0.0000');
    expect((int) $data['costOfSalesSummary']['unreconciled_count'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| C. Manufactured finished-good proof
|--------------------------------------------------------------------------
*/

test('manufactured product uses production receipt cost not selling price', function () {
    $fixture = salesCycleFixture();
    costReportPermissions($fixture);
    $session = salesCycleSession($fixture);

    // Create a new finished product with no opening stock
    $mfProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 9001,
        'doc_num' => 'Product-MF', 'name' => 'Manufactured FG',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);

    // Post a canonical TypeProductionReceipt with explicit unit cost via InventoryMovementService
    $movementService = app(InventoryMovementService::class);
    $receipt = $movementService->createAndPost([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'document_type' => InventoryDocument::TypeProductionReceipt,
        'document_date' => now()->toDateString(),
        'purpose' => 'Manufactured goods receipt',
    ], [
        [
            'product_id' => $mfProduct->getKey(),
            'quantity' => '50',
            'unit_cost' => '7.5000',
        ],
    ]);

    expect($receipt->status)->toBe('posted');

    // Verify the posted receipt line carries the explicit cost from the movement input
    $receipt->load('lines');
    $receiptLine = $receipt->lines->first();
    expect((string) $receiptLine->unit_cost)->toBe('7.50000000');
    expect((string) $receiptLine->total_cost)->toBe('375.00000000');

    // Create/approve a sales order for the manufactured product at a deliberately different selling price
    $order = app(SalesOrderService::class)->approve(
        app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
            'lines' => [
                ['product_id' => $mfProduct->getKey(), 'unit_id' => $fixture['unit']->getKey(),
                    'description' => 'Manufactured FG sale', 'quantity' => '50',
                    'unit_price' => '25.00', 'discount_amount' => 0, 'tax_amount' => 0],
            ],
            'payment_schedules' => [['title' => 'Full', 'amount' => '1250', 'due_date' => now()->toDateString()]],
        ])),
    );

    // Deliver through SalesFulfillmentService
    $delivery = app(SalesFulfillmentService::class)->deliver(
        $order,
        [['sales_order_line_id' => $order->lines->first()->getKey(), 'quantity' => '50']],
        ['branch_store_id' => $fixture['store']->getKey()],
    );

    $delivery->load('lines');
    $deliveryLine = $delivery->lines->first();

    // The delivery inventory issue cost must match the production receipt layer cost, not selling price
    expect((string) $deliveryLine->unit_cost)->toBe('7.50000000', 'Delivery unit cost must equal production receipt cost');
    expect(bccomp((string) $deliveryLine->total_cost, '375.00000000', 4))->toBe(0, 'Delivery total cost = 50 × 7.50');

    // Report must reflect the production receipt cost
    ['data' => $data] = costReportDataAndScreen($this, $fixture, $session);
    $mfRows = $data['costOfSalesRows']->filter(fn ($row) => $row['product'] === 'Manufactured FG');
    expect($mfRows->count())->toBe(1);

    $reportRow = $mfRows->first();
    expect((string) $reportRow['unit_cost'])->toBe('7.50000000');
    expect(bccomp((string) $reportRow['signed_total_cost'], '375.00000000', 4))->toBe(0);

    // Mutate the sales order line selling price after delivery posting
    // The report result must remain unchanged — cost is derived from inventory layer, not selling price
    $order->lines->first()->update(['unit_price' => '99.00']);

    ['data' => $mutatedData] = costReportDataAndScreen($this, $fixture, $session);
    $mutatedRows = $mutatedData['costOfSalesRows']->filter(fn ($row) => $row['product'] === 'Manufactured FG');
    expect($mutatedRows->count())->toBe(1);
    $mutatedRow = $mutatedRows->first();
    expect((string) $mutatedRow['unit_cost'])->toBe('7.50000000', 'Cost must not change after selling price mutation');
    expect(bccomp((string) $mutatedRow['signed_total_cost'], '375.00000000', 4))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| D. Integrity diagnostics
|--------------------------------------------------------------------------
*/

test('exact repeated-product return line linkage uses source_line_type_id not product_id', function () {
    $fixture = salesCycleFixture();
    costReportPermissions($fixture);
    $session = salesCycleSession($fixture);

    ['delivery' => $delivery] = costReportDeliverAndInvoice($fixture);

    $returnService = app(SalesReturnService::class);
    $return = $returnService->createFromDelivery(
        $delivery,
        SalesReturn::ReasonDamaged,
        'Lineage test',
        [['delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '10']],
    );
    $return = $returnService->authorize($return);
    $return = $returnService->receive($return);
    $return = $returnService->inspect($return, [[
        'sales_return_line_id' => $return->lines->first()->getKey(),
        'saleable_quantity' => '10',
    ]]);
    $return = $returnService->close($return);

    $return->load('returnInventoryDocument.lines');
    foreach ($return->returnInventoryDocument->lines as $line) {
        expect($line->source_line_type)->toBe(SalesReturnLine::class);
        expect($line->source_line_id)->not->toBeNull();
    }

    ['data' => $data] = costReportDataAndScreen($this, $fixture, $session);
    $returnRows = $data['costOfSalesRows']->where('movement_kind', 'return');
    expect($returnRows->count())->toBeGreaterThan(0);
    foreach ($returnRows as $row) {
        expect($row['reconciliation_status'])->toBe('reconciled');
    }
});

test('missing journal renders unreconciled and null cost stays null not zero', function () {
    $fixture = salesCycleFixture();
    costReportPermissions($fixture);
    $session = salesCycleSession($fixture);

    $delivery = InventoryDocument::query()->create([
        'doc_number' => 999901, 'doc_num' => 'DEL-NOJOURNAL',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'document_type' => InventoryDocument::TypeSalesDelivery,
        'document_date' => now()->toDateString(),
        'purpose' => 'Test delivery without journal',
        'source_document_type' => SalesOrder::class,
        'source_document_id' => 1, 'source_doc_num' => 'TEST',
        'customer_id' => $fixture['customer']->getKey(),
        'status' => InventoryDocument::StatusPosted,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $delivery->lines()->create([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1, 'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'transaction_quantity' => '10', 'quantity' => '10',
        'unit_cost' => null, 'total_cost' => null,
        'created_by' => $fixture['user']->getKey(),
    ]);

    ['data' => $data, 'screen' => $screen] = costReportDataAndScreen($this, $fixture, $session);
    $nullCostRows = $data['costOfSalesRows']->where('document', 'DEL-NOJOURNAL');
    expect($nullCostRows->count())->toBe(1);

    $row = $nullCostRows->first();
    expect($row['unit_cost'])->toBeNull();
    expect($row['signed_total_cost'])->toBeNull();
    expect($row['reconciliation_status'])->toBe('unreconciled');

    expect((int) $data['costOfSalesSummary']['unreconciled_count'])->toBeGreaterThanOrEqual(1);

    expect($screen)->toContain('DEL-NOJOURNAL');
});
