<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Exports\InventoryBookValuationExport;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryOpeningStockPostingService;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

/** @return array<string, mixed> */
function inventoryBookValuationFixture(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(CurrencySeeder::class);
    $user = User::factory()->create();
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 991001, 'doc_num' => 'BOOK-BRANCH',
        'name' => 'Book valuation branch', 'type' => Branch::TypeWarehouse, 'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Book valuation store']);
    $otherStore = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Book valuation destination']);
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 991001, 'doc_num' => 'BOOK-UNIT',
        'name' => 'Book base unit', 'status' => 'active',
    ]);
    $alternateUnit = ItemUnit::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 991002, 'doc_num' => 'BOOK-ALT-UNIT',
        'name' => 'Book alternate unit', 'equivalent_value' => '2', 'equivalent_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 991001, 'doc_num' => 'BOOK-ITEM',
        'name' => 'Book valuation item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);

    return compact('user', 'company', 'branch', 'period', 'store', 'otherStore', 'unit', 'alternateUnit', 'product');
}

/** @param array<string, mixed> $fixture @param array<string, mixed> $overrides */
function inventoryBookTransaction(array $fixture, array $overrides): InventoryTransaction
{
    static $sourceId = 0;
    $sourceId++;

    return InventoryTransaction::query()->create([
        'posting_key' => 'book-valuation-'.Str::uuid(),
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => $fixture['period']->from_date->copy()->addDays(1)->toDateString(),
        'transaction_type' => InventoryDocument::TypeReceipt,
        'product_id' => $fixture['product']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => '0', 'quantity_out' => '0',
        'source_type' => 'inventory_book_valuation_test', 'source_id' => $sourceId,
        'source_doc_num' => 'BOOK-'.$sourceId,
        ...$overrides,
    ]);
}

/** @param array<string, mixed> $fixture @return array<string, int|string> */
function inventoryBookSession(array $fixture): array
{
    return [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
}

test('book valuation uses signed posted costs with chronological snapshots and transfer conservation', function (): void {
    $fixture = inventoryBookValuationFixture();
    $day1 = $fixture['period']->from_date->copy()->addDays(1)->toDateString();
    $day2 = $fixture['period']->from_date->copy()->addDays(2)->toDateString();
    $day3 = $fixture['period']->from_date->copy()->addDays(3)->toDateString();
    $day4 = $fixture['period']->from_date->copy()->addDays(4)->toDateString();

    inventoryBookTransaction($fixture, ['transaction_date' => $day1, 'transaction_type' => 'opening_stock', 'quantity_in' => '10', 'unit_cost' => '10', 'total_cost' => '100']);
    inventoryBookTransaction($fixture, ['transaction_date' => $day2, 'transaction_type' => 'purchase_receipt', 'unit_id' => $fixture['alternateUnit']->getKey(), 'quantity_in' => '10', 'unit_cost' => '20', 'total_cost' => '200']);
    inventoryBookTransaction($fixture, ['transaction_date' => $day3, 'transaction_type' => InventoryDocument::TypeSalesDelivery, 'quantity_out' => '5', 'unit_cost' => '15', 'total_cost' => '75']);
    inventoryBookTransaction($fixture, ['transaction_date' => $day3, 'transaction_type' => InventoryDocument::TypeSalesReturnReceipt, 'quantity_in' => '1', 'unit_cost' => '15', 'total_cost' => '15']);
    inventoryBookTransaction($fixture, ['transaction_date' => $day3, 'transaction_type' => InventoryDocument::TypeProductionReceipt, 'quantity_in' => '2', 'unit_cost' => '30', 'total_cost' => '60']);
    inventoryBookTransaction($fixture, ['transaction_date' => $day3, 'transaction_type' => 'purchase_return', 'quantity_out' => '2', 'unit_cost' => '20', 'total_cost' => '40']);
    inventoryBookTransaction($fixture, ['transaction_date' => $day3, 'transaction_type' => 'purchase_return_reversal', 'quantity_in' => '2', 'unit_cost' => '20', 'total_cost' => '40']);
    inventoryBookTransaction($fixture, ['transaction_date' => $day4, 'transaction_type' => InventoryDocument::TypeTransfer, 'quantity_out' => '2', 'unit_cost' => '15', 'total_cost' => '30']);
    inventoryBookTransaction($fixture, ['transaction_date' => $day4, 'transaction_type' => InventoryDocument::TypeTransfer, 'branch_store_id' => $fixture['otherStore']->getKey(), 'quantity_in' => '2', 'unit_cost' => '15', 'total_cost' => '30']);

    $service = app(InventoryReportService::class);
    $atOpening = $service->bookValuation($fixture['company']->getKey(), [$fixture['branch']->getKey()], ['as_of' => $day1]);
    $atSecondReceipt = $service->bookValuation($fixture['company']->getKey(), [$fixture['branch']->getKey()], ['as_of' => $day2]);
    $final = $service->bookValuation($fixture['company']->getKey(), [$fixture['branch']->getKey()], ['as_of' => $day4]);

    expect($atOpening['totals']['quantity'])->toBe('10.00000000')
        ->and($atOpening['totals']['book_value'])->toBe('100.00000000')
        ->and($atSecondReceipt['totals']['quantity'])->toBe('20.00000000')
        ->and($atSecondReceipt['totals']['book_value'])->toBe('300.00000000')
        ->and($final['rows'])->toHaveCount(2)
        ->and($final['totals']['quantity'])->toBe('18.00000000')
        ->and($final['totals']['book_value'])->toBe('300.00000000')
        ->and($final['rows']->first()->product->unit->is($fixture['unit']))->toBeTrue()
        ->and($final['rows']->pluck('book_value')->sort()->values()->all())->toBe(['30.00000000', '270.00000000']);

    $fixture['product']->delete();
    $fixture['store']->delete();
    $historical = $service->bookValuation($fixture['company']->getKey(), [$fixture['branch']->getKey()], ['as_of' => $day4]);
    $historicalStoreRow = $historical['rows']->firstWhere('branch_store_id', $fixture['store']->getKey());

    expect($historical['rows']->first()->product->trashed())->toBeTrue()
        ->and($historicalStoreRow->branchStore->trashed())->toBeTrue()
        ->and($historicalStoreRow->branchStore->name)->toBe('Book valuation store');
});

test('book valuation distinguishes zero cost from missing cost and preserves signed negative positions', function (): void {
    $fixture = inventoryBookValuationFixture();
    $zero = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991002, 'doc_num' => 'BOOK-ZERO',
        'name' => 'Valid zero-cost item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    $missing = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991003, 'doc_num' => 'BOOK-MISSING',
        'name' => 'Missing-cost item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    $negative = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991004, 'doc_num' => 'BOOK-NEGATIVE',
        'name' => 'Negative item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    $halfPopulated = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991006, 'doc_num' => 'BOOK-HALF',
        'name' => 'Half-populated cost item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    $depletedMissing = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991007, 'doc_num' => 'BOOK-DEPLETED',
        'name' => 'Depleted missing-cost item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    inventoryBookTransaction($fixture, ['product_id' => $zero->getKey(), 'quantity_in' => '4', 'unit_cost' => '0', 'total_cost' => '0']);
    inventoryBookTransaction($fixture, ['product_id' => $missing->getKey(), 'quantity_in' => '3', 'unit_cost' => null, 'total_cost' => null]);
    inventoryBookTransaction($fixture, ['product_id' => $negative->getKey(), 'quantity_out' => '2', 'unit_cost' => '5', 'total_cost' => '10']);
    inventoryBookTransaction($fixture, ['product_id' => $halfPopulated->getKey(), 'quantity_in' => '2', 'unit_cost' => '9', 'total_cost' => null]);
    inventoryBookTransaction($fixture, ['product_id' => $depletedMissing->getKey(), 'quantity_in' => '3', 'unit_cost' => null, 'total_cost' => null]);
    inventoryBookTransaction($fixture, ['product_id' => $depletedMissing->getKey(), 'quantity_out' => '3', 'unit_cost' => null, 'total_cost' => null]);
    inventoryBookTransaction($fixture, ['product_id' => $depletedMissing->getKey(), 'quantity_in' => '4', 'unit_cost' => '7', 'total_cost' => '28']);

    $report = app(InventoryReportService::class)->bookValuation(
        $fixture['company']->getKey(), [$fixture['branch']->getKey()],
        ['as_of' => $fixture['period']->to_date->toDateString()],
    );
    $rows = $report['rows']->keyBy('product_id');

    expect($rows[$zero->getKey()]->valuation_status)->toBe('zero_cost')
        ->and($rows[$zero->getKey()]->book_unit_cost)->toBe('0.00000000')
        ->and($rows[$missing->getKey()]->valuation_status)->toBe('unvalued')
        ->and($rows[$missing->getKey()]->book_unit_cost)->toBeNull()
        ->and($rows[$missing->getKey()]->unvalued_quantity)->toBe('3.00000000')
        ->and($rows[$negative->getKey()]->on_hand)->toBe('-2.00000000')
        ->and($rows[$negative->getKey()]->book_value)->toBe('-10.00000000')
        ->and($rows[$negative->getKey()]->is_negative)->toBeTrue()
        ->and($rows[$halfPopulated->getKey()]->valuation_status)->toBe('unvalued')
        ->and($rows[$halfPopulated->getKey()]->book_value)->toBe('0.00000000')
        ->and($rows[$depletedMissing->getKey()]->valuation_status)->toBe('valued')
        ->and($rows[$depletedMissing->getKey()]->unvalued_quantity)->toBe('0.00000000')
        ->and($rows[$depletedMissing->getKey()]->book_value)->toBe('28.00000000')
        ->and($report['totals']['unvalued_rows'])->toBe(2)
        ->and($report['totals']['negative_positions'])->toBe(1);
});

test('posting preserves explicit zero cost through a zero-valued issue while unknown stays null', function (): void {
    $fixture = inventoryBookValuationFixture();
    test()->seed(DefaultChartOfAccountsSeeder::class);
    $movement = app(InventoryMovementService::class);
    $header = [
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'branch_store_id' => $fixture['store']->getKey(),
        'document_date' => $fixture['period']->from_date->copy()->addDays(10)->toDateString(),
        'source_stock_status' => InventoryTransaction::StatusAvailable,
    ];
    $receipt = $movement->createAndPost(
        [...$header, 'document_type' => InventoryDocument::TypeAdjustmentIn],
        [['product_id' => $fixture['product']->getKey(), 'quantity' => '5', 'unit_cost' => '0']],
    );
    $issue = $movement->createAndPost(
        [...$header, 'document_type' => InventoryDocument::TypeIssue],
        [['product_id' => $fixture['product']->getKey(), 'quantity' => '2']],
    );
    $missingInbound = $movement->createAndPost(
        [...$header, 'document_type' => InventoryDocument::TypeAdjustmentIn],
        [['product_id' => $fixture['product']->getKey(), 'quantity' => '1']],
    );
    $unknown = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991005, 'doc_num' => 'BOOK-UNKNOWN',
        'name' => 'Unknown-cost item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    $unknownReceipt = $movement->createAndPost(
        [...$header, 'document_type' => InventoryDocument::TypeAdjustmentIn],
        [['product_id' => $unknown->getKey(), 'quantity' => '1']],
    );
    $transferProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991008, 'doc_num' => 'BOOK-TRANSFER',
        'name' => 'Transfer-cost item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    $sourceLocation = WarehouseLocation::query()->create([
        'branch_store_id' => $fixture['store']->getKey(), 'code' => 'BOOK-TRANSFER-SOURCE',
        'name' => 'Transfer source location',
    ]);
    $destinationLocation = WarehouseLocation::query()->create([
        'branch_store_id' => $fixture['otherStore']->getKey(), 'code' => 'BOOK-TRANSFER-DESTINATION',
        'name' => 'Transfer destination location',
    ]);
    inventoryBookTransaction($fixture, [
        'product_id' => $transferProduct->getKey(),
        'warehouse_location_id' => $sourceLocation->getKey(),
        'transaction_date' => $fixture['period']->from_date->copy()->addDays(9)->toDateString(),
        'quantity_in' => '10', 'unit_cost' => '4', 'total_cost' => '40',
    ]);
    $transfer = $movement->createAndPost(
        [
            ...$header, 'document_type' => InventoryDocument::TypeTransfer,
            'destination_branch_store_id' => $fixture['otherStore']->getKey(),
        ],
        [[
            'product_id' => $transferProduct->getKey(), 'quantity' => '2',
            'warehouse_location_id' => $sourceLocation->getKey(),
            'destination_warehouse_location_id' => $destinationLocation->getKey(),
        ]],
    );
    $transferReceipt = $transfer->transactions->firstWhere('quantity_in', '2.00000000');
    $destinationIssue = $movement->createAndPost(
        [
            ...$header, 'branch_store_id' => $fixture['otherStore']->getKey(),
            'warehouse_location_id' => $destinationLocation->getKey(),
            'document_type' => InventoryDocument::TypeIssue,
        ],
        [['product_id' => $transferProduct->getKey(), 'quantity' => '1']],
    );
    $destinationLayer = InventoryReceiptLayer::query()
        ->where('receipt_transaction_id', $transferReceipt->getKey())
        ->sole();
    $destinationIssueReceiptIds = InventoryLayerAllocation::query()
        ->where('issue_transaction_id', $destinationIssue->transactions->sole()->getKey())
        ->with('layer')
        ->get()
        ->pluck('layer.receipt_transaction_id')
        ->all();
    $negativeValueProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991009, 'doc_num' => 'BOOK-NEGATIVE-VALUE',
        'name' => 'Negative-value item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    inventoryBookTransaction($fixture, [
        'product_id' => $negativeValueProduct->getKey(), 'quantity_in' => '1',
        'unit_cost' => '-5', 'total_cost' => '-5',
    ]);

    expect($receipt->transactions->sole()->unit_cost)->toBe('0.00000000')
        ->and($receipt->transactions->sole()->total_cost)->toBe('0.00000000')
        ->and($issue->transactions->sole()->unit_cost)->toBe('0.00000000')
        ->and($issue->transactions->sole()->total_cost)->toBe('0.00000000')
        ->and($missingInbound->transactions->sole()->unit_cost)->toBeNull()
        ->and($missingInbound->transactions->sole()->total_cost)->toBeNull()
        ->and($unknownReceipt->transactions->sole()->unit_cost)->toBeNull()
        ->and($unknownReceipt->transactions->sole()->total_cost)->toBeNull()
        ->and($transfer->transactions)->toHaveCount(2)
        ->and($transfer->transactions->every(fn (InventoryTransaction $transaction): bool => $transaction->unit_cost === '4.00000000' && $transaction->total_cost === '8.00000000'))->toBeTrue()
        ->and($destinationLayer->branch_store_id)->toBe($fixture['otherStore']->getKey())
        ->and($destinationLayer->warehouse_location_id)->toBe($destinationLocation->getKey())
        ->and($destinationIssue->transactions->sole()->unit_cost)->toBe('4.00000000')
        ->and($destinationIssueReceiptIds)->toBe([$transferReceipt->getKey()])
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition(
            $fixture['company']->getKey(), $fixture['store']->getKey(), $negativeValueProduct->getKey(),
        ))->toBeNull();
});

test('posting values issues from the exact location batch position', function (): void {
    $fixture = inventoryBookValuationFixture();
    test()->seed(DefaultChartOfAccountsSeeder::class);
    $movement = app(InventoryMovementService::class);
    $location = WarehouseLocation::query()->create([
        'branch_store_id' => $fixture['store']->getKey(),
        'code' => 'BOOK-EXACT',
        'name' => 'Exact valuation location',
    ]);
    $locationProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991010, 'doc_num' => 'BOOK-LOCATION-COST',
        'name' => 'Exact location cost item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    $batchProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991011, 'doc_num' => 'BOOK-BATCH-COST',
        'name' => 'Exact batch cost item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    $transactionDate = $fixture['period']->from_date->copy()->addDays(9)->toDateString();
    $locationNullBatchReceipt = inventoryBookTransaction($fixture, [
        'product_id' => $locationProduct->getKey(), 'warehouse_location_id' => $location->getKey(),
        'batch_lot' => null, 'transaction_date' => $transactionDate,
        'quantity_in' => '10', 'unit_cost' => '10', 'total_cost' => '100',
    ]);
    inventoryBookTransaction($fixture, [
        'product_id' => $locationProduct->getKey(), 'warehouse_location_id' => $location->getKey(),
        'batch_lot' => 'BATCH-B', 'transaction_date' => $transactionDate,
        'quantity_in' => '10', 'unit_cost' => '20', 'total_cost' => '200',
    ]);
    $batchNullLocationReceipt = inventoryBookTransaction($fixture, [
        'product_id' => $batchProduct->getKey(), 'warehouse_location_id' => null,
        'batch_lot' => 'BATCH-SYMMETRIC', 'transaction_date' => $transactionDate,
        'quantity_in' => '10', 'unit_cost' => '11', 'total_cost' => '110',
    ]);
    inventoryBookTransaction($fixture, [
        'product_id' => $batchProduct->getKey(), 'warehouse_location_id' => $location->getKey(),
        'batch_lot' => 'BATCH-SYMMETRIC', 'transaction_date' => $transactionDate,
        'quantity_in' => '10', 'unit_cost' => '21', 'total_cost' => '210',
    ]);
    $header = [
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'branch_store_id' => $fixture['store']->getKey(),
        'document_type' => InventoryDocument::TypeIssue,
        'document_date' => $fixture['period']->from_date->copy()->addDays(10)->toDateString(),
        'source_stock_status' => InventoryTransaction::StatusAvailable,
    ];
    $locationIssue = $movement->createAndPost($header, [[
        'product_id' => $locationProduct->getKey(), 'quantity' => '2',
        'warehouse_location_id' => $location->getKey(),
    ]]);
    $batchIssue = $movement->createAndPost($header, [[
        'product_id' => $batchProduct->getKey(), 'quantity' => '2',
        'batch_lot' => 'BATCH-SYMMETRIC',
    ]]);
    $locationAllocationReceiptIds = InventoryLayerAllocation::query()
        ->where('issue_transaction_id', $locationIssue->transactions->sole()->getKey())
        ->with('layer')
        ->get()
        ->pluck('layer.receipt_transaction_id')
        ->all();
    $batchAllocationReceiptIds = InventoryLayerAllocation::query()
        ->where('issue_transaction_id', $batchIssue->transactions->sole()->getKey())
        ->with('layer')
        ->get()
        ->pluck('layer.receipt_transaction_id')
        ->all();

    expect($locationIssue->transactions->sole()->unit_cost)->toBe('10.00000000')
        ->and($locationIssue->transactions->sole()->total_cost)->toBe('20.00000000')
        ->and($locationAllocationReceiptIds)->toBe([$locationNullBatchReceipt->getKey()])
        ->and($batchIssue->transactions->sole()->unit_cost)->toBe('11.00000000')
        ->and($batchIssue->transactions->sole()->total_cost)->toBe('22.00000000')
        ->and($batchAllocationReceiptIds)->toBe([$batchNullLocationReceipt->getKey()]);
});

test('posting rejects a linked source issue from another product', function (): void {
    $fixture = inventoryBookValuationFixture();
    test()->seed(DefaultChartOfAccountsSeeder::class);
    $movement = app(InventoryMovementService::class);
    $otherProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991012, 'doc_num' => 'BOOK-FOREIGN-ISSUE',
        'name' => 'Foreign source issue item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    $header = [
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'branch_store_id' => $fixture['store']->getKey(),
        'document_date' => $fixture['period']->from_date->copy()->addDays(10)->toDateString(),
        'source_stock_status' => InventoryTransaction::StatusAvailable,
        'source_document_type' => 'inventory-book-source', 'source_document_id' => 77,
    ];
    $movement->createAndPost(
        [...$header, 'document_type' => InventoryDocument::TypeAdjustmentIn],
        [['product_id' => $otherProduct->getKey(), 'quantity' => '5', 'unit_cost' => '6']],
    );
    $issue = $movement->createAndPost(
        [...$header, 'document_type' => InventoryDocument::TypeIssue],
        [[
            'product_id' => $otherProduct->getKey(), 'quantity' => '2',
            'source_line_type' => 'inventory-book-line', 'source_line_id' => 88,
        ]],
    );
    $sourceIssue = $issue->transactions->sole();

    expect(fn () => $movement->createAndPost(
        [...$header, 'document_type' => InventoryDocument::TypeMaintenanceMaterialReturn],
        [[
            'product_id' => $fixture['product']->getKey(), 'quantity' => '1',
            'source_line_type' => 'inventory-book-line', 'source_line_id' => 88,
            'product_snapshot' => ['source_issue_transaction_id' => $sourceIssue->getKey()],
        ]],
    ))->toThrow(DomainException::class, __('The linked source issue does not match this inventory return line.'));
});

test('sales return resolves the canonical issue for a later delivery line without trusting its snapshot id', function (): void {
    $fixture = salesCycleFixture();
    $deliveryLocation = WarehouseLocation::query()->create([
        'branch_store_id' => $fixture['store']->getKey(), 'code' => 'SALES-RETURN-SOURCE',
        'name' => 'Sales return source location',
    ]);
    InventoryTransaction::query()->where('posting_key', 'sales-cycle-opening-stock')->update([
        'warehouse_location_id' => $deliveryLocation->getKey(),
    ]);
    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $returns = app(SalesReturnService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture)));
    $goodsLine = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $firstDelivery = $fulfillment->deliver($order, [[
        'sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '60',
    ]]);
    $laterDelivery = $fulfillment->deliver($order->fresh(), [[
        'sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '40',
    ]]);
    $return = $returns->createFromDelivery(
        $laterDelivery,
        SalesReturn::ReasonWrongItem,
        'Later delivery lineage regression',
        [['delivery_line_id' => $laterDelivery->lines->sole()->getKey(), 'quantity' => '10']],
    );
    $returns->authorize($return);
    $received = $returns->receive($return->fresh());
    $returnDocument = $received->returnInventoryDocument()->with('lines')->firstOrFail();
    $receipt = $returnDocument->transactions()->where('quantity_in', '>', 0)->sole();
    $sourceIssue = $laterDelivery->transactions()->where('quantity_out', '>', 0)->sole();
    $receiptLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->getKey())->sole();
    $returnLine = $received->lines()->sole();
    $returns->inspect($received, [[
        'sales_return_line_id' => $returnLine->getKey(), 'saleable_quantity' => '10',
    ]]);
    $dispositionIssue = InventoryTransaction::query()
        ->where('transaction_type', InventoryDocument::TypeTransfer)
        ->where('source_line_type', SalesReturnLine::class)
        ->where('source_line_id', $returnLine->getKey())
        ->where('stock_status', InventoryTransaction::StatusQuarantine)
        ->where('quantity_out', '>', 0)
        ->sole();
    $dispositionReceiptIds = InventoryLayerAllocation::query()
        ->where('issue_transaction_id', $dispositionIssue->getKey())
        ->with('layer')
        ->get()
        ->pluck('layer.receipt_transaction_id')
        ->all();

    expect($laterDelivery->lines->sole()->getKey())->not->toBe($goodsLine->getKey())
        ->and($returnDocument->lines->sole()->product_snapshot['source_issue_transaction_id'] ?? null)->not->toBe($sourceIssue->getKey())
        ->and($sourceIssue->warehouse_location_id)->toBe($deliveryLocation->getKey())
        ->and($receipt->warehouse_location_id)->toBeNull()
        ->and($receiptLayer->warehouse_location_id)->toBeNull()
        ->and($receipt->unit_cost)->toBe($sourceIssue->unit_cost)
        ->and($receipt->batch_lot)->toBe($sourceIssue->batch_lot)
        ->and($receiptLayer->unit_cost)->toBe($sourceIssue->unit_cost)
        ->and($receiptLayer->batch_lot)->toBe($sourceIssue->batch_lot)
        ->and($dispositionIssue->warehouse_location_id)->toBeNull()
        ->and($dispositionReceiptIds)->toBe([$receipt->getKey()])
        ->and($firstDelivery->getKey())->not->toBe($laterDelivery->getKey());
});

test('sales return preserves canonical cost when received into another store in the same branch', function (): void {
    $fixture = salesCycleFixture();
    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $invoices = app(CustomerInvoiceService::class);
    $returns = app(SalesReturnService::class);
    $returnStore = BranchStore::query()->create([
        'branch_id' => $fixture['branch']->getKey(), 'name' => 'Cross-store sales returns',
    ]);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Cross-store return item', 'quantity' => '10', 'unit_price' => '10',
            'discount_amount' => 0, 'tax_amount' => 0,
        ]],
        'payment_schedules' => [[
            'title' => 'Cross-store return invoice', 'amount' => '100',
            'due_date' => now()->addMonth()->toDateString(),
        ]],
    ])));
    $goodsLine = $order->lines->sole();
    $delivery = $fulfillment->deliver($order, [[
        'sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '10',
    ]]);
    $invoice = $invoices->createFromOrder(
        $order->fresh(),
        [[
            'sales_order_line_id' => $goodsLine->getKey(),
            'delivery_line_id' => $delivery->lines->sole()->getKey(),
            'quantity' => '10',
        ]],
        [[
            'due_date' => now()->addMonth()->toDateString(), 'amount' => '100',
        ]],
        $delivery,
    );
    $invoice = $invoices->post($invoice);
    $return = $returns->create(
        $invoice,
        SalesReturn::ReasonExcess,
        'Receive in another store',
        [['customer_invoice_line_id' => $invoice->lines()->sole()->getKey(), 'quantity' => '2']],
        $returnStore->getKey(),
    );
    $returns->authorize($return);
    $received = $returns->receive($return->fresh());
    $sourceIssue = $delivery->transactions()->where('quantity_out', '>', 0)->sole();
    $receipt = $received->returnInventoryDocument->transactions()->where('quantity_in', '>', 0)->sole();
    $receiptLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->getKey())->sole();

    expect($sourceIssue->branch_store_id)->toBe($fixture['store']->getKey())
        ->and($receipt->branch_store_id)->toBe($returnStore->getKey())
        ->and($receipt->branch_id)->toBe($sourceIssue->branch_id)
        ->and($receipt->unit_cost)->toBe($sourceIssue->unit_cost)
        ->and($receipt->total_cost)->toBe('10.00000000')
        ->and($receiptLayer->branch_store_id)->toBe($returnStore->getKey())
        ->and($receiptLayer->unit_cost)->toBe($sourceIssue->unit_cost);
});

test('book valuation carries prior-period history through the active-period as-of while enforcing company branch and product filters', function (): void {
    $fixture = inventoryBookValuationFixture();
    inventoryBookTransaction($fixture, ['quantity_in' => '5', 'unit_cost' => '4', 'total_cost' => '20']);
    $otherPeriod = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991099, 'doc_num' => 'BOOK-PRIOR-PERIOD',
        'name' => 'Prior book period', 'from_date' => $fixture['period']->from_date->copy()->subYear(),
        'to_date' => $fixture['period']->from_date->copy()->subDay(), 'is_closed' => true,
    ]);
    inventoryBookTransaction($fixture, [
        'financial_period_id' => $otherPeriod->getKey(),
        'transaction_date' => $otherPeriod->to_date->toDateString(),
        'quantity_in' => '9', 'unit_cost' => '4', 'total_cost' => '36',
    ]);
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991099, 'doc_num' => 'BOOK-OTHER-BRANCH',
        'name' => 'Other valuation branch', 'type' => Branch::TypeWarehouse, 'status' => 'active',
    ]);
    $otherBranchStore = BranchStore::query()->create(['branch_id' => $otherBranch->getKey(), 'name' => 'Other branch store']);
    inventoryBookTransaction($fixture, ['branch_id' => $otherBranch->getKey(), 'branch_store_id' => $otherBranchStore->getKey(), 'quantity_in' => '11', 'unit_cost' => '4', 'total_cost' => '44']);
    $otherCompany = Company::query()->create([
        'doc_number' => 991099, 'doc_num' => 'BOOK-OTHER-COMPANY', 'name' => 'Other valuation company',
        'status' => 'active', 'is_main' => false,
    ]);
    $foreignBranch = Branch::query()->create([
        'company_id' => $otherCompany->getKey(), 'doc_number' => 991098, 'doc_num' => 'BOOK-FOREIGN-BRANCH',
        'name' => 'Foreign valuation branch', 'type' => Branch::TypeWarehouse, 'status' => 'active',
    ]);
    $foreignPeriod = FinancialPeriod::query()->create([
        'company_id' => $otherCompany->getKey(), 'doc_number' => 991098, 'doc_num' => 'BOOK-FOREIGN-PERIOD',
        'name' => 'Foreign valuation period', 'from_date' => $fixture['period']->from_date,
        'to_date' => $fixture['period']->to_date, 'is_closed' => false,
    ]);
    $foreignStore = BranchStore::query()->create(['branch_id' => $foreignBranch->getKey(), 'name' => 'Foreign store']);
    $foreignUnit = ItemUnit::query()->create([
        'company_id' => $otherCompany->getKey(), 'doc_number' => 991098, 'doc_num' => 'BOOK-FOREIGN-UNIT',
        'name' => 'Foreign unit', 'status' => 'active',
    ]);
    $foreignProduct = Product::query()->create([
        'company_id' => $otherCompany->getKey(), 'doc_number' => 991098, 'doc_num' => 'BOOK-FOREIGN-ITEM',
        'name' => 'Foreign item', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $foreignUnit->getKey(), 'status' => 'active',
    ]);
    inventoryBookTransaction([
        'company' => $otherCompany, 'branch' => $foreignBranch, 'period' => $foreignPeriod,
        'store' => $foreignStore, 'product' => $foreignProduct, 'unit' => $foreignUnit,
    ], ['quantity_in' => '13', 'unit_cost' => '4', 'total_cost' => '52']);

    $service = app(InventoryReportService::class);
    $report = $service->bookValuation(
        $fixture['company']->getKey(), [$fixture['branch']->getKey()],
        ['as_of' => $fixture['period']->to_date->toDateString(), 'product_doc_num' => $fixture['product']->doc_num],
    );
    $wrongProduct = $service->bookValuation(
        $fixture['company']->getKey(), [$fixture['branch']->getKey()],
        ['as_of' => $fixture['period']->to_date->toDateString(), 'product_doc_num' => 'NOT-THIS-PRODUCT'],
    );

    expect($report['rows'])->toHaveCount(1)
        ->and($report['totals']['quantity'])->toBe('14.00000000')
        ->and($report['totals']['book_value'])->toBe('56.00000000')
        ->and($wrongProduct['rows'])->toBeEmpty();
});

test('approved priced opening stock posts through the real opening workflow into book valuation', function (): void {
    $fixture = inventoryBookValuationFixture();
    $opening = OpeningStock::query()->create([
        'doc_number' => 991010, 'doc_num' => 'BOOK-OPENING',
        'document_date' => $fixture['period']->from_date,
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'branch_store_id' => $fixture['store']->getKey(),
        'approved' => true, 'status' => OpeningStock::StatusApproved, 'approved_at' => now(),
    ]);
    $line = $opening->lines()->create([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'line_no' => 1,
        'product_id' => $fixture['product']->getKey(), 'quantity' => '6',
        'stock_status' => InventoryTransaction::StatusAvailable,
    ]);
    $currency = Currency::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 991020, 'doc_num' => 'BOOK-FX-CURRENCY',
        'name' => 'Book foreign currency', 'code' => 'BFX', 'is_main' => false, 'status' => 'active',
    ]);
    $pricing = OpeningStockPricing::query()->create([
        'doc_number' => 991010, 'doc_num' => 'BOOK-OPENING-PRICE',
        'document_date' => $fixture['period']->from_date,
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'opening_stock_id' => $opening->getKey(),
        'currency_id' => $currency->getKey(), 'exchange_rate' => '2', 'total_amount' => '30',
    ]);
    $pricing->lines()->create([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'opening_stock_line_id' => $line->getKey(),
        'product_id' => $fixture['product']->getKey(), 'quantity' => '6', 'unit_price' => '5', 'line_total' => '30',
    ]);

    app(InventoryOpeningStockPostingService::class)->post($opening);
    $report = app(InventoryReportService::class)->bookValuation(
        $fixture['company']->getKey(), [$fixture['branch']->getKey()],
        ['as_of' => $fixture['period']->from_date->toDateString()],
    );

    $transaction = InventoryTransaction::query()->where('posting_key', "opening-stock:{$opening->getKey()}:line:{$line->getKey()}")->firstOrFail();

    expect($transaction->unit_cost)->toBe('10.00000000')
        ->and($transaction->total_cost)->toBe('60.00000000')
        ->and($report['totals']['quantity'])->toBe('6.00000000')
        ->and($report['totals']['book_value'])->toBe('60.00000000');
});

test('same-period authoritative inventory posting reconciles book value to the inventory control general ledger', function (): void {
    $fixture = inventoryBookValuationFixture();
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $document = app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'branch_store_id' => $fixture['store']->getKey(),
        'document_type' => InventoryDocument::TypeAdjustmentIn,
        'document_date' => $fixture['period']->from_date->copy()->addDay()->toDateString(),
        'source_stock_status' => InventoryTransaction::StatusAvailable,
    ], [['product_id' => $fixture['product']->getKey(), 'quantity' => '5', 'unit_cost' => '4']]);
    $report = app(InventoryReportService::class)->bookValuation(
        $fixture['company']->getKey(), [$fixture['branch']->getKey()],
        ['as_of' => $fixture['period']->to_date->toDateString()],
    );
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile(
        $fixture['company']->getKey(), $fixture['period']->getKey(), $fixture['branch']->getKey(),
    ))->keyBy('key');

    expect($document->journal_entry_id)->not->toBeNull()
        ->and($report['totals']['book_value'])->toBe('20.00000000')
        ->and($reconciliation['finished_goods']['subledger'])->toBe('20.0000')
        ->and($reconciliation['finished_goods']['difference'])->toBe('0.0000')
        ->and($reconciliation['inventory_adjustments']['difference'])->toBe('0.0000');
});

test('valuation screen and exports share aggregate rows totals filters currency and permission boundary', function (): void {
    $fixture = inventoryBookValuationFixture();
    inventoryBookTransaction($fixture, ['quantity_in' => '7', 'unit_cost' => '3', 'total_cost' => '21']);
    $report = app(InventoryReportService::class)->bookValuation(
        $fixture['company']->getKey(), [$fixture['branch']->getKey()],
        ['as_of' => $fixture['period']->to_date->toDateString()],
    );
    $currency = Currency::query()->forCompany($fixture['company']->getKey())->where('is_main', true)->value('code');
    $export = new InventoryBookValuationExport($report['rows'], $report['totals'], (string) $currency);
    $pdfRows = view('reports.inventory.book-valuation', [
        'rows' => $report['rows'], 'totals' => $report['totals'], 'currencyCode' => $currency,
        'filterSummary' => ['As of' => $fixture['period']->to_date->toDateString()],
    ])->render();

    expect($export->array())->toHaveCount($report['rows']->count() + 1)
        ->and($export->array()[0][7])->toBe(7.0)
        ->and($export->array()[0][9])->toBe(21.0)
        ->and($export->array()[0][10])->toBe($currency)
        ->and($export->array()[1][7])->toBe(7.0)
        ->and($export->array()[1][9])->toBe(21.0)
        ->and($pdfRows)->toContain('Book valuation item', (string) $currency);

    $query = ['as_of' => $fixture['period']->to_date->toDateString(), 'branch_store_uuid' => $fixture['store']->public_uuid];
    $this->actingAs($fixture['user'])->withSession(inventoryBookSession($fixture))
        ->get(route('admin.inventory.reports.valuation', $query))->assertForbidden();

    foreach (['inventory.reports.financial', 'inventory.reports.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo('inventory.reports.financial');

    $this->actingAs($fixture['user'])->withSession(inventoryBookSession($fixture))
        ->get(route('admin.inventory.reports.valuation', $query))
        ->assertOk()
        ->assertDontSee(route('admin.inventory.reports.valuation.export.excel', $query));

    $fixture['user']->givePermissionTo('inventory.reports.export');

    $this->actingAs($fixture['user'])->withSession(inventoryBookSession($fixture))
        ->get(route('admin.inventory.reports.valuation', $query))
        ->assertOk()
        ->assertSee('Book valuation item')
        ->assertDontSee('21.00000000', false)
        ->assertSee('21', false)
        ->assertSee((string) $currency)
        ->assertSee('report-actions-toolbar', false)
        ->assertSee('data-bs-toggle="dropdown"', false)
        ->assertSee('data-open-in-new-tab="true"', false);
    $this->actingAs($fixture['user'])->withSession(inventoryBookSession($fixture))
        ->get(route('admin.inventory.reports.valuation.export.excel', $query))
        ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $this->actingAs($fixture['user'])->withSession(inventoryBookSession($fixture))
        ->get(route('admin.inventory.reports.valuation.export.csv', $query))
        ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $comparisonQuery = [
        'as_of' => $fixture['period']->to_date->toDateString(),
        'product_id' => $fixture['product']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'reference_method' => 'fifo',
    ];
    $this->actingAs($fixture['user'])->withSession(inventoryBookSession($fixture))
        ->get(route('admin.inventory.reports.valuation', $comparisonQuery))
        ->assertOk()
        ->assertSee(route('admin.inventory.reports.valuation.export.excel', $comparisonQuery));
    $comparisonExport = $this->actingAs($fixture['user'])->withSession(inventoryBookSession($fixture))
        ->get(route('admin.inventory.reports.valuation.export.excel', $comparisonQuery))->assertOk();
    expect($comparisonExport->headers->get('content-disposition'))->toContain('inventory-valuation-comparison.xlsx');
    $pdf = $this->actingAs($fixture['user'])->withSession(inventoryBookSession($fixture))
        ->get(route('admin.inventory.reports.valuation.export.pdf', $query))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(str_starts_with($pdf->getContent(), '%PDF-'))->toBeTrue();
});
