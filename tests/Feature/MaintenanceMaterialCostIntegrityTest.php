<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Maintenance\Exports\MaintenanceWorkOrderExport;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Maintenance\Models\MaintenanceMaterialRequestLine;
use Modules\Maintenance\Models\MaintenancePlan;
use Modules\Maintenance\Models\MaintenancePlanDue;
use Modules\Maintenance\Models\MaintenanceRequest;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Maintenance\Services\MaintenanceMaterialRequestService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionRun;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

/** @return array<string, mixed> */
function maintenanceMaterialCostFixture(bool $fifo = false, bool $periodic = false): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(CurrencySeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);

    $user = User::factory()->create();
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Maintenance Materials Store', 'position' => 1]);
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 97001, 'doc_num' => 'UNIT-MAINT-COST',
        'name' => 'Maintenance Piece', 'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 97001, 'doc_num' => 'RM-MAINT-COST',
        'name' => 'Maintenance Bearing', 'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    $costCenter = CostCenter::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 97001, 'doc_num' => 'CC-MAINT-COST',
        'cost_center_code' => 'MAINT-COST', 'name' => 'Maintenance Cost Center', 'name_en' => 'Maintenance Cost Center',
        'is_group' => false, 'status' => 'active',
    ]);
    $asset = FixedAsset::query()->create([
        'doc_number' => 97001, 'doc_num' => 'FA-MAINT-COST', 'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(), 'period_id' => $period->getKey(), 'cost_center_id' => $costCenter->getKey(),
        'asset_date' => now()->toDateString(), 'asset_name' => 'Maintenance Cost Asset',
        'status' => FixedAsset::StatusActive, 'created_by' => $user->getKey(),
    ]);
    $workOrder = MaintenanceWorkOrder::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany(
            'maintenance_work_orders', MaintenanceWorkOrder::class, $company->getKey(),
            fn ($query) => $query->where('financial_period_id', $period->getKey()),
        ),
        'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(), 'branch_id' => $branch->getKey(),
        'fixed_asset_id' => $asset->getKey(), 'maintenance_type' => 'corrective', 'service_mode' => 'internal',
        'work_description' => 'Replace a worn bearing.', 'status' => MaintenanceWorkOrder::StatusInProgress,
        'created_by' => $user->getKey(),
    ]);

    $maintenanceExpenseAccount = Account::query()
        ->where('company_id', $company->getKey())->where('account_code', '524')->firstOrFail();
    expect($maintenanceExpenseAccount->classification?->code)->toBe(PostingAccountResolver::FactoryMaintenanceExpense);

    $lowCostLocation = WarehouseLocation::query()->create([
        'branch_store_id' => $store->getKey(), 'code' => 'MAINT-LOW', 'name' => 'Maintenance Low Cost',
    ]);
    $highCostLocation = WarehouseLocation::query()->create([
        'branch_store_id' => $store->getKey(), 'code' => 'MAINT-HIGH', 'name' => 'Maintenance High Cost',
    ]);

    request()->setLaravelSession(app('session.store'));
    $session = [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $company->getKey(), OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(), OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(), OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    request()->session()->put($session);

    if ($fifo || $periodic) {
        Permission::findOrCreate('inventory.cost_policies.manage', 'web');
        $user->givePermissionTo('inventory.cost_policies.manage');
        app(InventoryCostPolicyService::class)->createVersion($company->getKey(), [
            'branch_store_id' => $store->getKey(),
            'method' => $periodic ? InventoryCostPolicy::PeriodicWeightedAverage : InventoryCostPolicy::Fifo,
            'effective_from' => $period->from_date->toDateString(),
        ], $user->getKey());
    }

    foreach ([
        ['key' => 'receipt-low', 'date' => now()->subDays(2)->toDateString(), 'cost' => '2.00000000'],
        ['key' => 'receipt-high', 'date' => now()->subDay()->toDateString(), 'cost' => '6.00000000'],
    ] as $receipt) {
        InventoryTransaction::query()->create([
            'posting_key' => 'maintenance-cost-'.$receipt['key'], 'company_id' => $company->getKey(),
            'financial_period_id' => $period->getKey(), 'branch_id' => $branch->getKey(), 'branch_store_id' => $store->getKey(),
            'stock_status' => InventoryTransaction::StatusAvailable, 'transaction_date' => $receipt['date'],
            'transaction_type' => InventoryDocument::TypeReceipt, 'product_id' => $product->getKey(), 'unit_id' => $unit->getKey(),
            'quantity_in' => '10.00000000', 'quantity_out' => '0.00000000', 'source_type' => 'maintenance_cost_test',
            'source_id' => $receipt['key'] === 'receipt-low' ? 1 : 2, 'source_doc_num' => strtoupper($receipt['key']),
            'unit_cost' => $receipt['cost'], 'total_cost' => bcmul('10', $receipt['cost'], 8), 'created_by' => $user->getKey(),
        ]);
    }

    return compact('user', 'company', 'branch', 'period', 'store', 'unit', 'product', 'costCenter', 'asset', 'workOrder', 'maintenanceExpenseAccount', 'lowCostLocation', 'highCostLocation', 'session');
}

test('FIFO maintenance issue and returned allocation post their exact layer costs to stock and GL', function (): void {
    $fixture = maintenanceMaterialCostFixture(fifo: true);
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture);
    $issue = $service->issue($request)->load('transactions', 'journalEntry.lines');
    $issueTransaction = $issue->transactions->sole();

    expect($issueTransaction->cost_method)->toBe(InventoryCostPolicy::Fifo)
        ->and($issueTransaction->total_cost)->toBe('50.00000000')
        ->and($issue->journalEntry->lines->sum('debit_amount'))->toBe(50.0);

    $line = $request->refresh()->lines()->sole();
    $service->recordConsumption($fixture['workOrder'], [['line_id' => $line->getKey(), 'consumed_quantity' => '3']]);
    $returned = $service->returnUnused($request->fresh())->load('lines', 'transactions', 'journalEntry.lines');
    $returnLines = $returned->lines->sortBy('id')->values();

    expect($returnLines)->toHaveCount(2)
        ->and($returnLines[0]->unit_cost)->toBe('2.00000000')
        ->and($returnLines[0]->total_cost)->toBe('20.00000000')
        ->and($returnLines[1]->unit_cost)->toBe('6.00000000')
        ->and($returnLines[1]->total_cost)->toBe('12.00000000')
        ->and($returned->transactions->sum('total_cost'))->toBe(32.0)
        ->and($returned->journalEntry->lines->sum('debit_amount'))->toBe(32.0);
});

/** @param array<string, mixed> $fixture */
function approvedMaintenanceMaterialRequest(array $fixture, string $quantity = '15'): MaintenanceMaterialRequest
{
    $service = app(MaintenanceMaterialRequestService::class);
    $request = $service->create($fixture['workOrder'], [
        'branch_store_id' => $fixture['store']->getKey(),
        'reason' => 'Controlled maintenance material cost test.',
        'lines' => [['product_id' => $fixture['product']->getKey(), 'item_type' => 'spare_part', 'quantity' => $quantity]],
    ]);

    return $service->approve($request);
}

test('maintenance material issue and partial return preserve canonical moving-average cost, source layers, GL, and report trace', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture);
    $issue = $service->issue($request);
    $issue->load(['lines', 'transactions', 'journalEntry.lines.account.classification']);
    $line = $request->refresh()->lines()->sole();
    $issueTransaction = $issue->transactions->sole();

    expect($issue->document_type)->toBe(InventoryDocument::TypeMaintenanceMaterialIssue)
        ->and($issue->lines->sole()->unit_cost)->toBe('4.00000000')
        ->and($issue->lines->sole()->total_cost)->toBe('60.00000000')
        ->and($issueTransaction->quantity_out)->toBe('15.00000000')
        ->and($issueTransaction->unit_cost)->toBe('4.00000000')
        ->and($issueTransaction->total_cost)->toBe('60.00000000')
        ->and(InventoryLayerAllocation::query()->where('issue_transaction_id', $issueTransaction->getKey())->count())->toBe(2)
        ->and(app(InventoryAvailabilityService::class)->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['product']->getKey())['physical_on_hand'])->toBe('5.00000000');

    $allocatedLayers = InventoryLayerAllocation::query()
        ->with('layer')
        ->where('issue_transaction_id', $issueTransaction->getKey())
        ->orderBy('id')
        ->get();
    $allocatedLayers[0]->layer->update(['warehouse_location_id' => $fixture['lowCostLocation']->getKey()]);
    $allocatedLayers[1]->layer->update(['warehouse_location_id' => $fixture['highCostLocation']->getKey()]);

    $issueDebit = $issue->journalEntry->lines->firstWhere('account_id', $fixture['maintenanceExpenseAccount']->getKey());
    $issueInventoryLine = $issue->journalEntry->lines->first(fn ($journalLine): bool => $journalLine->account?->classification?->code === PostingAccountResolver::RawMaterialInventory);
    expect($issueDebit?->debit_amount)->toBe('60.0000')
        ->and($issueDebit?->credit_amount)->toBe('0.0000')
        ->and($issueDebit?->cost_center_id)->toBe($fixture['costCenter']->getKey())
        ->and($issueInventoryLine?->credit_amount)->toBe('60.0000')
        ->and($issue->journalEntry->lines->pluck('account.classification.code')->all())
        ->not->toContain(PostingAccountResolver::InventoryAdjustmentLoss, PostingAccountResolver::InventoryAdjustmentGain);

    $driftCostCenter = CostCenter::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 97002, 'doc_num' => 'CC-MAINT-DRIFT',
        'cost_center_code' => 'MAINT-DRIFT', 'name' => 'Drift Cost Center', 'name_en' => 'Drift Cost Center',
        'is_group' => false, 'status' => 'active',
    ]);
    $maintenanceClassification = AccountClassification::query()->where('code', PostingAccountResolver::FactoryMaintenanceExpense)->firstOrFail();
    $otherExpenseClassification = AccountClassification::query()->where('code', 'other_expense')->firstOrFail();
    $replacementMaintenanceAccount = Account::query()
        ->where('company_id', $fixture['company']->getKey())->where('account_code', '525')->firstOrFail();
    $fixture['maintenanceExpenseAccount']->forceFill(['account_classification_id' => $otherExpenseClassification->getKey()])->save();
    $replacementMaintenanceAccount->forceFill(['account_classification_id' => $maintenanceClassification->getKey()])->save();
    $fixture['product']->update(['item_classification' => Product::ClassificationPackaging]);
    $fixture['asset']->update(['cost_center_id' => $driftCostCenter->getKey()]);

    $service->recordConsumption($fixture['workOrder'], [['line_id' => $line->getKey(), 'consumed_quantity' => '3']]);
    $return = $service->returnUnused($request->fresh());
    $return->load(['lines', 'transactions', 'journalEntry.lines.account.classification']);

    // ── Multi-position return: two lines, two transactions, two layers ──
    $returnLines = $return->lines->sortBy('id')->values();
    $returnTransactions = $return->transactions->sortBy('id')->values();
    expect($return->document_type)->toBe(InventoryDocument::TypeMaintenanceMaterialReturn)
        ->and($returnLines)->toHaveCount(2)
        ->and($returnTransactions)->toHaveCount(2);

    // Per-line assertions: each return line carries source-issue moving-average cost
    expect($returnLines[0]->quantity)->toBe('10.00000000')
        ->and($returnLines[0]->unit_cost)->toBe('4.00000000')
        ->and($returnLines[0]->total_cost)->toBe('40.00000000')
        ->and($returnLines[0]->warehouse_location_id)->toBe($fixture['lowCostLocation']->getKey())
        ->and($returnLines[0]->product_snapshot['source_issue_transaction_id'])->toBe($issueTransaction->getKey())
        ->and($returnLines[0]->product_snapshot['restoration_allocation_id'])->not->toBeNull()
        ->and($returnLines[1]->quantity)->toBe('2.00000000')
        ->and($returnLines[1]->unit_cost)->toBe('4.00000000')
        ->and($returnLines[1]->total_cost)->toBe('8.00000000')
        ->and($returnLines[1]->warehouse_location_id)->toBe($fixture['highCostLocation']->getKey())
        ->and($returnLines[1]->product_snapshot['source_issue_transaction_id'])->toBe($issueTransaction->getKey());

    // Aggregate line totals match canonical return quantity and value
    expect(bcadd((string) $returnLines[0]->quantity, (string) $returnLines[1]->quantity, 8))->toBe('12.00000000')
        ->and(bcadd((string) $returnLines[0]->total_cost, (string) $returnLines[1]->total_cost, 8))->toBe('48.00000000');

    // Per-transaction: each transaction position equals its line; transaction unit_cost = source issue moving-average
    foreach ($returnTransactions as $tx) {
        expect($tx->unit_cost)->toBe('4.00000000')
            ->and($tx->transaction_type)->toBe(InventoryDocument::TypeMaintenanceMaterialReturn)
            ->and($tx->source_line_type)->toBe(MaintenanceMaterialRequestLine::class)
            ->and((int) $tx->source_line_id)->toBe((int) $line->getKey());
    }
    expect($returnTransactions[0]->quantity_in)->toBe('10.00000000')
        ->and($returnTransactions[0]->warehouse_location_id)->toBe($fixture['lowCostLocation']->getKey())
        ->and($returnTransactions[0]->total_cost)->toBe('40.00000000')
        ->and($returnTransactions[1]->quantity_in)->toBe('2.00000000')
        ->and($returnTransactions[1]->warehouse_location_id)->toBe($fixture['highCostLocation']->getKey())
        ->and($returnTransactions[1]->total_cost)->toBe('8.00000000');

    // Aggregate transaction quantity and total_cost
    expect(bcadd((string) $returnTransactions[0]->quantity_in, (string) $returnTransactions[1]->quantity_in, 8))->toBe('12.00000000')
        ->and(bcadd((string) $returnTransactions[0]->total_cost, (string) $returnTransactions[1]->total_cost, 8))->toBe('48.00000000');

    // Restored layers: each transaction produces one layer; layer locations match transaction locations
    $allRestoredLayers = InventoryReceiptLayer::query()
        ->whereIn('receipt_transaction_id', $returnTransactions->pluck('id')->all())
        ->orderBy('id')
        ->get();
    expect($allRestoredLayers)->toHaveCount(2)
        ->and($allRestoredLayers[0]->receipt_transaction_id)->toBe($returnTransactions[0]->getKey())
        ->and($allRestoredLayers[0]->warehouse_location_id)->toBe($fixture['lowCostLocation']->getKey())
        ->and($allRestoredLayers[0]->original_quantity)->toBe('10.00000000')
        ->and($allRestoredLayers[0]->unit_cost)->toBe('4.00000000')
        ->and($allRestoredLayers[0]->sourceAllocation->layer->unit_cost)->toBe('2.00000000')
        ->and($allRestoredLayers[1]->receipt_transaction_id)->toBe($returnTransactions[1]->getKey())
        ->and($allRestoredLayers[1]->warehouse_location_id)->toBe($fixture['highCostLocation']->getKey())
        ->and($allRestoredLayers[1]->original_quantity)->toBe('2.00000000')
        ->and($allRestoredLayers[1]->unit_cost)->toBe('4.00000000')
        ->and($allRestoredLayers[1]->sourceAllocation->layer->unit_cost)->toBe('6.00000000');

    // Transaction position → layer position: per-position quantity_in = layer original_quantity
    expect($returnTransactions[0]->quantity_in)->toBe($allRestoredLayers[0]->original_quantity)
        ->and($returnTransactions[1]->quantity_in)->toBe($allRestoredLayers[1]->original_quantity);

    // Location-filtered availability: each position has restored quantity
    $avail = app(InventoryAvailabilityService::class);
    expect($avail->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['product']->getKey(), null, $fixture['lowCostLocation']->getKey())['physical_on_hand'])->toBe('10.00000000')
        ->and($avail->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['product']->getKey(), null, $fixture['highCostLocation']->getKey())['physical_on_hand'])->toBe('2.00000000')
        ->and($avail->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['product']->getKey())['physical_on_hand'])->toBe('17.00000000');

    // Location-filtered InventoryReportService::bookValuation() — the end-to-end closure gate
    $reportService = app(InventoryReportService::class);
    $bvFilters = [
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['product']->getKey(),
    ];

    $lowBv = $reportService->bookValuation(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        [...$bvFilters, 'warehouse_location_id' => $fixture['lowCostLocation']->getKey()],
    );
    expect($lowBv['totals']['quantity'])->toBe('10.00000000')
        ->and($lowBv['totals']['book_value'])->toBe('40.00000000');

    $highBv = $reportService->bookValuation(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        [...$bvFilters, 'warehouse_location_id' => $fixture['highCostLocation']->getKey()],
    );
    expect($highBv['totals']['quantity'])->toBe('2.00000000')
        ->and($highBv['totals']['book_value'])->toBe('8.00000000');

    $aggregateBv = $reportService->bookValuation(
        $fixture['company']->getKey(),
        [$fixture['branch']->getKey()],
        $bvFilters,
    );
    expect($aggregateBv['totals']['quantity'])->toBe('17.00000000')
        ->and($aggregateBv['totals']['book_value'])->toBe('68.00000000');

    // The unfiltered report intentionally returns one store/product row; its residual is the unfiltered position.
    $aggregateRows = $aggregateBv['rows'];
    $locationReturnSum = bcadd((string) $lowBv['totals']['quantity'], (string) $highBv['totals']['quantity'], 8);
    expect($aggregateRows)->toHaveCount(1)
        ->and($aggregateRows->first()->warehouse_location_id)->toBeNull()
        ->and(bcsub((string) $aggregateBv['totals']['quantity'], $locationReturnSum, 8))->toBe('5.00000000')
        ->and(bcsub((string) $aggregateBv['totals']['book_value'], bcadd((string) $lowBv['totals']['book_value'], (string) $highBv['totals']['book_value'], 8), 8))->toBe('20.00000000');

    // Aggregate restored layer quantity = request returned_quantity = 12
    expect(bcadd((string) $allRestoredLayers[0]->original_quantity, (string) $allRestoredLayers[1]->original_quantity, 8))->toBe('12.00000000')
        ->and($request->fresh()->lines()->sole()->returned_quantity)->toBe('12.00000000');

    // Canonical transaction/GL reversal: journal reversal totals match aggregate return value
    // Position-split returns emit one reversal line per position/slice; sum all matching lines.
    $returnInventoryLines = $return->journalEntry->lines->filter(
        fn ($jl): bool => $jl->account?->classification?->code === PostingAccountResolver::RawMaterialInventory,
    );
    $returnExpenseLines = $return->journalEntry->lines->filter(
        fn ($jl): bool => (int) ($jl->account_id ?? 0) === (int) $fixture['maintenanceExpenseAccount']->getKey(),
    );
    $aggregateInventoryDebit = $returnInventoryLines->reduce(
        fn (string $carry, $jl): string => bcadd($carry, (string) $jl->debit_amount, 4),
        '0.0000',
    );
    $aggregateExpenseCredit = $returnExpenseLines->reduce(
        fn (string $carry, $jl): string => bcadd($carry, (string) $jl->credit_amount, 4),
        '0.0000',
    );
    expect($returnInventoryLines)->not->toBeEmpty()
        ->and($returnExpenseLines)->not->toBeEmpty()
        ->and($aggregateInventoryDebit)->toBe('48.0000')
        ->and($aggregateExpenseCredit)->toBe('48.0000');
    // Every matching line preserves original account and cost-center lineage
    foreach ($returnInventoryLines as $jl) {
        expect($jl->account_id)->toBe($issueInventoryLine?->account_id)
            ->and($jl->cost_center_id)->toBe($issueInventoryLine?->cost_center_id);
    }
    foreach ($returnExpenseLines as $jl) {
        expect($jl->account_id)->toBe($issueDebit?->account_id)
            ->and($jl->cost_center_id)->toBe($fixture['costCenter']->getKey());
    }

    // Source-layer cost lineage explicitly distinct from canonical transaction book cost
    expect($allRestoredLayers[0]->unit_cost)->toBe('4.00000000')
        ->and($allRestoredLayers[0]->sourceAllocation->layer->unit_cost)->toBe('2.00000000')
        ->not->toBe($returnTransactions[0]->unit_cost)
        ->and($allRestoredLayers[1]->unit_cost)->toBe('4.00000000')
        ->and($allRestoredLayers[1]->sourceAllocation->layer->unit_cost)->toBe('6.00000000')
        ->not->toBe($returnTransactions[1]->unit_cost)
        // Original receipt dates preserved from source layers
        ->and($allRestoredLayers[0]->original_receipt_date)->not->toBeNull()
        ->and($allRestoredLayers[1]->original_receipt_date)->not->toBeNull();

    // Duplicate return denial
    expect(fn () => $service->returnUnused($request->fresh()))->toThrow(DomainException::class)
        ->and(fn () => $service->issue($request->fresh()))->toThrow(DomainException::class);

    // No duplicates after rejected repeat
    $posting = app(InventoryDocumentPostingService::class);
    $documentCount = InventoryDocument::query()->count();
    $transactionCount = InventoryTransaction::query()->count();
    $journalCount = DB::table('journal_entries')->count();
    $layerCount = InventoryReceiptLayer::query()->count();
    $stock = app(InventoryAvailabilityService::class)->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['product']->getKey())['physical_on_hand'];
    expect(fn () => $posting->reverse($issue->fresh()))->toThrow(DomainException::class)
        ->and(fn () => $posting->reverse($return->fresh()))->toThrow(DomainException::class)
        ->and(InventoryDocument::query()->count())->toBe($documentCount)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount)
        ->and(InventoryReceiptLayer::query()->count())->toBe($layerCount)
        ->and(DB::table('journal_entries')->count())->toBe($journalCount)
        ->and($issue->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($return->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($request->fresh()->status)->toBe(MaintenanceMaterialRequest::StatusReturned)
        ->and($request->fresh()->lines()->sole()->returned_quantity)->toBe('12.00000000')
        ->and(app(InventoryAvailabilityService::class)->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['product']->getKey())['physical_on_hand'])->toBe($stock);

    // Report and export: trace aggregated return cost
    Permission::findOrCreate('maintenance.reports.view', 'web');
    Permission::findOrCreate('maintenance.reports.financial', 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $fixture['user']->givePermissionTo(['maintenance.reports.view', 'maintenance.reports.financial']);
    $this->actingAs($fixture['user'])->withSession($fixture['session'])->get(route('admin.maintenance.reports.index'))
        ->assertOk()
        ->assertSee('Issued: 15 / Consumed: 3 / Returned: 12 / Net: 3')
        ->assertSee('RM-MAINT-COST — Maintenance Bearing [Maintenance Piece] — Issued: 15.00000000 / Returned: 12.00000000 / Net: 3.00000000')
        ->assertSee('Unit cost: 4.00000000 / Gross: 60.0000 / Returned cost: 48.0000 / Net cost: 12.0000')
        ->assertSee('Gross cost: 60.0000 / Returned cost: 48.0000 / Net material cost: 12.0000');

    $export = new MaintenanceWorkOrderExport(collect([$fixture['workOrder']->fresh()->load([
        'asset', 'mold', 'supplier', 'materialRequests.lines.product', 'materialRequests.lines.unit',
        'materialRequests.issueDocument.lines', 'materialRequests.returnDocument.lines', 'expenses.currency',
    ])]), true);
    $exportRow = $export->array()[0];
    expect($exportRow[array_search(__('maintenance.reports.material_trace'), $export->headings(), true)])
        ->toContain('Unit cost: 4.00000000', 'Gross: 60.0000', 'Returned cost: 48.0000', 'Net cost: 12.0000');

    $fixture['user']->revokePermissionTo('maintenance.reports.financial');
    $this->actingAs($fixture['user'])->withSession($fixture['session'])->get(route('admin.maintenance.reports.index'))
        ->assertOk()
        ->assertDontSee('Gross cost: 60.0000');
});

test('maintenance posts multiple material lines once and rejects insufficient stock without partial effects', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $secondProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 97002, 'doc_num' => 'RM-MAINT-COST-2',
        'name' => 'Maintenance Seal', 'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $fixture['unit']->getKey(), 'status' => 'active',
    ]);
    InventoryTransaction::query()->create([
        'posting_key' => 'maintenance-cost-second-product', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(), 'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => now()->subDay()->toDateString(), 'transaction_type' => InventoryDocument::TypeReceipt,
        'product_id' => $secondProduct->getKey(), 'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => '5', 'quantity_out' => '0', 'source_type' => 'maintenance_cost_test', 'source_id' => 3,
        'source_doc_num' => 'RECEIPT-SECOND', 'unit_cost' => '3', 'total_cost' => '15', 'created_by' => $fixture['user']->getKey(),
    ]);
    $service = app(MaintenanceMaterialRequestService::class);
    $request = $service->create($fixture['workOrder'], [
        'branch_store_id' => $fixture['store']->getKey(),
        'lines' => [
            ['product_id' => $fixture['product']->getKey(), 'item_type' => 'spare_part', 'quantity' => '2'],
            ['product_id' => $secondProduct->getKey(), 'item_type' => 'consumable', 'quantity' => '3'],
        ],
    ]);
    $request = $service->approve($request);
    $issue = $service->issue($request);

    expect($issue->lines)->toHaveCount(2)
        ->and($issue->transactions()->where('quantity_out', '>', 0)->count())->toBe(2)
        ->and($issue->lines->pluck('total_cost')->all())->toBe(['8.00000000', '9.00000000'])
        ->and($issue->journalEntry?->lines->sum('debit_amount'))->toEqual(17)
        ->and($issue->journalEntry?->lines->sum('credit_amount'))->toEqual(17);

    $insufficient = approvedMaintenanceMaterialRequest($fixture, '25');
    $documentCount = InventoryDocument::query()->count();
    $transactionCount = InventoryTransaction::query()->count();
    expect(fn () => $service->issue($insufficient))->toThrow(DomainException::class);
    expect($insufficient->fresh()->status)->toBe(MaintenanceMaterialRequest::StatusApproved)
        ->and(InventoryDocument::query()->count())->toBe($documentCount)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount);
});

test('maintenance XLSX export traces exact material quantities and costs and redacts financial values', function (): void {
    app()->setLocale('en');
    $fixture = maintenanceMaterialCostFixture();
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture);
    $service->issue($request);
    $line = $request->refresh()->lines()->sole();
    $service->recordConsumption($fixture['workOrder'], [[
        'line_id' => $line->getKey(),
        'consumed_quantity' => '3',
    ]]);
    $service->returnUnused($request->fresh());
    $order = $fixture['workOrder']->fresh()->load([
        'asset', 'mold', 'supplier', 'materialRequests.lines.product', 'materialRequests.lines.unit',
        'materialRequests.issueDocument.lines', 'materialRequests.returnDocument.lines', 'expenses.currency',
    ]);

    Permission::findOrCreate('maintenance.reports.financial', 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $fixture['user']->givePermissionTo('maintenance.reports.financial');
    $authorizedExport = new MaintenanceWorkOrderExport(collect([$order]), $fixture['user']->can('maintenance.reports.financial'));
    $authorized = array_combine($authorizedExport->headings(), $authorizedExport->array()[0]);
    expect($authorized[__('maintenance.reports.issued_material_quantity')])->toBe('15.00000000')
        ->and($authorized[__('maintenance.reports.returned_material_quantity')])->toBe('12.00000000')
        ->and($authorized[__('maintenance.reports.net_material_quantity')])->toBe('3.00000000')
        ->and($authorized[__('maintenance.reports.gross_material_cost')])->toBe('60.0000')
        ->and($authorized[__('maintenance.reports.returned_material_cost')])->toBe('48.0000')
        ->and($authorized[__('maintenance.reports.net_material_cost')])->toBe('12.0000')
        ->and($authorized[__('maintenance.reports.material_trace')])
        ->toContain(
            'RM-MAINT-COST — Maintenance Bearing',
            '[Maintenance Piece]',
            'Issued: 15.00000000',
            'Returned: 12.00000000',
            'Net: 3.00000000',
            'Unit cost: 4.00000000',
            'Gross: 60.0000',
            'Returned cost: 48.0000',
            'Net cost: 12.0000',
        );

    $fixture['user']->revokePermissionTo('maintenance.reports.financial');
    expect($fixture['user']->can('maintenance.reports.financial'))->toBeFalse();
    $redactedExport = new MaintenanceWorkOrderExport(collect([$order]), $fixture['user']->can('maintenance.reports.financial'));
    $redacted = array_combine($redactedExport->headings(), $redactedExport->array()[0]);
    expect($redacted)->not->toHaveKeys([
        __('maintenance.reports.gross_material_cost'),
        __('maintenance.reports.returned_material_cost'),
        __('maintenance.reports.net_material_cost'),
    ])->and($redacted[__('maintenance.reports.material_trace')])
        ->toContain(
            'RM-MAINT-COST — Maintenance Bearing',
            '[Maintenance Piece]',
            'Issued: 15.00000000',
            'Returned: 12.00000000',
            'Net: 3.00000000',
        )->not->toContain(
            'Unit cost:',
            'Gross:',
            'Returned cost:',
            'Net cost:',
        );
});

test('returned receipt layers keep source locations when the return transaction has a conflicting location', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $request = approvedMaintenanceMaterialRequest($fixture);
    $issue = app(MaintenanceMaterialRequestService::class)->issue($request);
    $issueTransaction = $issue->transactions()->sole();
    $allocatedLayers = InventoryLayerAllocation::query()
        ->with('layer')
        ->where('issue_transaction_id', $issueTransaction->getKey())
        ->orderBy('id')
        ->get();
    $allocatedLayers[0]->layer->update(['warehouse_location_id' => $fixture['lowCostLocation']->getKey()]);
    $allocatedLayers[1]->layer->update(['warehouse_location_id' => $fixture['highCostLocation']->getKey()]);
    $conflictingLocation = WarehouseLocation::query()->create([
        'branch_store_id' => $fixture['store']->getKey(), 'code' => 'MAINT-CONFLICT', 'name' => 'Conflicting Return Location',
    ]);
    $returnTransaction = InventoryTransaction::query()->create([
        'posting_key' => 'maintenance-conflicting-return-location',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'warehouse_location_id' => $conflictingLocation->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => now()->toDateString(),
        'transaction_type' => InventoryDocument::TypeMaintenanceMaterialReturn,
        'product_id' => $fixture['product']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => '12',
        'quantity_out' => '0',
        'source_type' => InventoryDocument::class,
        'source_id' => $issue->getKey(),
        'source_doc_num' => 'MAINT-CONFLICT-RETURN',
        'unit_cost' => '4',
        'total_cost' => '48',
        'created_by' => $fixture['user']->getKey(),
    ]);

    app(InventoryLayerService::class)->recordInbound($returnTransaction, $issueTransaction);

    $restoredLayers = InventoryReceiptLayer::query()
        ->where('receipt_transaction_id', $returnTransaction->getKey())
        ->orderBy('original_receipt_date')
        ->get();
    expect($restoredLayers->pluck('warehouse_location_id')->all())
        ->toBe([$fixture['lowCostLocation']->getKey(), $fixture['highCostLocation']->getKey()])
        ->not->toContain($conflictingLocation->getKey());
});

test('maintenance issue failure rolls back inventory, layers, journal, and request state atomically', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $request = approvedMaintenanceMaterialRequest($fixture);
    $fixture['maintenanceExpenseAccount']->update(['status' => 'inactive']);
    $beforeTransactions = InventoryTransaction::query()->count();

    expect(fn () => app(MaintenanceMaterialRequestService::class)->issue($request))->toThrow(DomainException::class);

    expect($request->fresh()->status)->toBe(MaintenanceMaterialRequest::StatusApproved)
        ->and($request->inventory_issue_document_id)->toBeNull()
        ->and(InventoryDocument::query()->where('source_document_type', MaintenanceMaterialRequest::class)->count())->toBe(0)
        ->and(InventoryTransaction::query()->count())->toBe($beforeTransactions)
        ->and(InventoryReceiptLayer::query()->count())->toBe(0)
        ->and(DB::table('journal_entries')->where('source_type', 'inventory_document_posting')->count())->toBe(0);
});

test('maintenance issue rejects a null authoritative book cost before posting any state', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $request = approvedMaintenanceMaterialRequest($fixture);
    InventoryTransaction::query()->where('source_type', 'maintenance_cost_test')->update([
        'unit_cost' => null,
        'total_cost' => null,
    ]);
    $beforeTransactions = InventoryTransaction::query()->count();

    expect(fn () => app(MaintenanceMaterialRequestService::class)->issue($request))->toThrow(DomainException::class);
    expect($request->fresh()->status)->toBe(MaintenanceMaterialRequest::StatusApproved)
        ->and(InventoryDocument::query()->where('source_document_type', MaintenanceMaterialRequest::class)->count())->toBe(0)
        ->and(InventoryTransaction::query()->count())->toBe($beforeTransactions)
        ->and(InventoryReceiptLayer::query()->count())->toBe(0)
        ->and(DB::table('journal_entries')->where('source_type', 'inventory_document_posting')->count())->toBe(0);
});

test('maintenance return accounting failure rolls back restored stock, layers, document, and request state', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture);
    $issue = $service->issue($request);
    $line = $request->refresh()->lines()->sole();
    $service->recordConsumption($fixture['workOrder'], [['line_id' => $line->getKey(), 'consumed_quantity' => '3']]);
    $issueLine = $issue->lines()->sole();
    $snapshot = $issueLine->product_snapshot;
    unset($snapshot['maintenance_accounting']);
    $issueLine->forceFill(['product_snapshot' => $snapshot])->save();
    $beforeTransactions = InventoryTransaction::query()->count();
    $beforeDocuments = InventoryDocument::query()->count();
    $beforeJournals = DB::table('journal_entries')->count();
    $beforeStock = app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['product']->getKey(),
    )['physical_on_hand'];

    expect(fn () => $service->returnUnused($request->fresh()))->toThrow(DomainException::class);
    expect($request->fresh()->status)->toBe(MaintenanceMaterialRequest::StatusIssued)
        ->and($request->fresh()->lines()->sole()->returned_quantity)->toBe('0.00000000')
        ->and(InventoryDocument::query()->count())->toBe($beforeDocuments)
        ->and(InventoryTransaction::query()->count())->toBe($beforeTransactions)
        ->and(DB::table('journal_entries')->count())->toBe($beforeJournals)
        ->and(InventoryReceiptLayer::query()->whereNotNull('receipt_transaction_id')->count())->toBe(2)
        ->and(app(InventoryAvailabilityService::class)->forProduct(
            $fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['product']->getKey(),
        )['physical_on_hand'])->toBe($beforeStock);
});

test('maintenance return rejects a null authoritative source cost without posting state', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture, '2');
    $issue = $service->issue($request);
    $issue->transactions()->sole()->forceFill(['total_cost' => null])->save();
    $beforeDocuments = InventoryDocument::query()->count();
    $beforeTransactions = InventoryTransaction::query()->count();
    $beforeJournals = DB::table('journal_entries')->count();

    expect(fn () => $service->returnUnused($request->fresh()))->toThrow(DomainException::class);
    expect($request->fresh()->status)->toBe(MaintenanceMaterialRequest::StatusIssued)
        ->and($request->fresh()->lines()->sole()->returned_quantity)->toBe('0.00000000')
        ->and(InventoryDocument::query()->count())->toBe($beforeDocuments)
        ->and(InventoryTransaction::query()->count())->toBe($beforeTransactions)
        ->and(DB::table('journal_entries')->count())->toBe($beforeJournals);
});

test('maintenance material accounting preserves a null cost center when neither source provides one', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $fixture['asset']->update(['cost_center_id' => null]);
    $request = approvedMaintenanceMaterialRequest($fixture, '2');
    $issue = app(MaintenanceMaterialRequestService::class)->issue($request);

    expect($issue->journalEntry?->lines)->toHaveCount(2)
        ->and($issue->journalEntry?->lines->pluck('cost_center_id')->unique()->values()->all())->toBe([null]);
});

test('maintenance material accounting gives the production run cost center precedence over the asset', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $runCostCenter = CostCenter::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 97002, 'doc_num' => 'CC-MAINT-RUN',
        'cost_center_code' => 'MAINT-RUN', 'name' => 'Run Cost Center', 'name_en' => 'Run Cost Center',
        'is_group' => false, 'status' => 'active',
    ]);
    $order = ProductionOrder::query()->create([
        'doc_number' => 97001, 'doc_num' => 'PO-MAINT-COST', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'source_type' => 'manual', 'production_order_date' => now()->toDateString(),
        'status' => ProductionOrder::StatusDraft, 'created_by' => $fixture['user']->getKey(),
    ]);
    $orderLine = ProductionOrderLine::query()->create([
        'production_order_id' => $order->getKey(), 'line_number' => 1, 'product_id' => $fixture['product']->getKey(),
        'unit_id' => $fixture['unit']->getKey(), 'description' => 'Maintenance cost-center precedence fixture',
        'quantity' => '1', 'conversion_factor' => '1', 'base_quantity' => '1',
    ]);
    $run = ProductionRun::query()->create([
        'run_number' => 'RUN-MAINT-COST', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'production_order_id' => $order->getKey(), 'production_order_line_id' => $orderLine->getKey(),
        'product_id' => $fixture['product']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
        'cost_center_id' => $runCostCenter->getKey(), 'planned_quantity' => '1', 'planned_base_quantity' => '1',
        'planned_start_at' => now(), 'planned_end_at' => now()->addHour(), 'created_by' => $fixture['user']->getKey(),
    ]);
    $fixture['workOrder']->update(['production_run_id' => $run->getKey()]);
    $request = approvedMaintenanceMaterialRequest($fixture, '2');
    $issue = app(MaintenanceMaterialRequestService::class)->issue($request);

    expect($issue->journalEntry?->lines->pluck('cost_center_id')->unique()->values()->all())
        ->toBe([$runCostCenter->getKey()]);
});

test('maintenance return is rejected in a closed period without partial effects', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture, '2');
    $service->issue($request);
    $fixture['period']->update(['is_closed' => true]);
    $beforeDocuments = InventoryDocument::query()->count();
    $beforeTransactions = InventoryTransaction::query()->count();

    expect(fn () => $service->returnUnused($request->fresh()))->toThrow(DomainException::class);
    expect($request->fresh()->status)->toBe(MaintenanceMaterialRequest::StatusIssued)
        ->and($request->fresh()->lines()->sole()->returned_quantity)->toBe('0.00000000')
        ->and(InventoryDocument::query()->count())->toBe($beforeDocuments)
        ->and(InventoryTransaction::query()->count())->toBe($beforeTransactions);
});

test('maintenance material posting respects financial-period and operating-context boundaries', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $request = approvedMaintenanceMaterialRequest($fixture);
    $fixture['period']->update(['is_closed' => true]);

    expect(fn () => app(MaintenanceMaterialRequestService::class)->issue($request))->toThrow(DomainException::class);
    expect($request->fresh()->status)->toBe(MaintenanceMaterialRequest::StatusApproved)
        ->and(InventoryDocument::query()->where('source_document_type', MaintenanceMaterialRequest::class)->count())->toBe(0);

    $fixture['period']->update(['is_closed' => false]);
    $otherBranch = Branch::query()->create([
        'doc_number' => 97002,
        'doc_num' => 'BR-MAINT-OTHER',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Other Maintenance Branch',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    request()->session()->put([
        OperatingContextService::BranchIdKey => $otherBranch->getKey(),
        OperatingContextService::BranchDocNumKey => $otherBranch->doc_num,
    ]);

    expect(fn () => app(MaintenanceMaterialRequestService::class)->issue($request->fresh()))
        ->toThrow(DomainException::class, __('maintenance.messages.document_outside_context'));
    expect($request->fresh()->status)->toBe(MaintenanceMaterialRequest::StatusApproved)
        ->and(InventoryDocument::query()->where('source_document_type', MaintenanceMaterialRequest::class)->count())->toBe(0);
});

test('maintenance screen PDF Excel and CSV include the same filtered request due and work order groups', function (): void {
    app()->setLocale('en');
    $fixture = maintenanceMaterialCostFixture();
    $maintenanceRequest = MaintenanceRequest::query()->create([
        'doc_number' => 97002,
        'doc_num' => 'MR-MAINT-REPORT',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'fixed_asset_id' => $fixture['asset']->getKey(),
        'reported_at' => now(),
        'request_type' => 'breakdown',
        'priority' => 'urgent',
        'symptoms' => 'Synthetic report breakdown',
        'is_machine_stopped' => true,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $plan = MaintenancePlan::query()->create([
        'doc_number' => 97002,
        'doc_num' => 'MP-MAINT-REPORT',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'fixed_asset_id' => $fixture['asset']->getKey(),
        'name' => 'Synthetic Maintenance Plan',
        'frequency_basis' => MaintenancePlan::FrequencyCalendar,
        'interval_value' => '30',
        'task_template' => 'Synthetic planned maintenance',
        'status' => MaintenancePlan::StatusApproved,
        'created_by' => $fixture['user']->getKey(),
    ]);
    MaintenancePlanDue::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'maintenance_plan_id' => $plan->getKey(),
        'due_key' => 'maintenance-report-due',
        'due_at' => now()->addDay(),
        'generated_at' => now(),
        'generated_by' => $fixture['user']->getKey(),
    ]);

    foreach (['maintenance.reports.view', 'maintenance.reports.export', 'maintenance.reports.financial'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $fixture['user']->givePermissionTo(['maintenance.reports.view', 'maintenance.reports.export', 'maintenance.reports.financial']);
    $filters = ['from' => now()->subDay()->toDateString(), 'to' => now()->addDays(2)->toDateString()];
    $screen = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.index', $filters))->assertOk();
    $screen->assertSee($maintenanceRequest->doc_num)
        ->assertSee($plan->doc_num)
        ->assertSee($fixture['workOrder']->doc_num);

    $pdf = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.print', $filters))->assertOk();
    $pdfPath = tempnam(sys_get_temp_dir(), 'maintenance-group-pdf-');
    if ($pdfPath === false) {
        throw new RuntimeException('Could not create a temporary PDF path.');
    }
    try {
        file_put_contents($pdfPath, $pdf->getContent());
        $pdfText = (new Process(['pdftotext', $pdfPath, '-']))->mustRun()->getOutput();
        expect((new Process(['pdfinfo', $pdfPath]))->mustRun()->getOutput())->toMatch('/Pages:\s+1/');
    } finally {
        @unlink($pdfPath);
    }
    expect($pdfText)->toContain($maintenanceRequest->doc_num, $plan->doc_num, $fixture['workOrder']->doc_num);

    $excel = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.export', $filters))->assertOk();
    $workbook = IOFactory::load($excel->baseResponse->getFile()->getPathname());
    expect($workbook->getSheetCount())->toBe(6)
        ->and($workbook->getSheet(1)->getCell('A2')->getValue())->toBe($maintenanceRequest->doc_num)
        ->and($workbook->getSheet(2)->getCell('A2')->getValue())->toContain($plan->doc_num)
        ->and($workbook->getSheet(4)->getCell('A2')->getValue())->toBe($fixture['workOrder']->doc_num);

    $csv = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.export.csv', $filters))->assertOk();
    $csvText = file_get_contents($csv->baseResponse->getFile()->getPathname());
    expect($csvText)->toContain($maintenanceRequest->doc_num, $plan->doc_num, $fixture['workOrder']->doc_num)
        ->toContain(__('maintenance.reports.requests_table'), __('maintenance.reports.plan_due_table'), __('maintenance.reports.orders_table'));
    app()->setLocale('ar');
    $arabicPdf = $this->actingAs($fixture['user'])
        ->withSession([...$fixture['session'], 'locale' => 'ar'])
        ->get(route('admin.maintenance.reports.print', $filters))->assertOk();
    $arabicPdfPath = tempnam(sys_get_temp_dir(), 'maintenance-arabic-pdf-');
    if ($arabicPdfPath === false) {
        throw new RuntimeException('Could not create a temporary Arabic PDF path.');
    }
    try {
        file_put_contents($arabicPdfPath, $arabicPdf->getContent());
        expect((new Process(['pdfinfo', $arabicPdfPath]))->mustRun()->getOutput())->toMatch('/Pages:\s+1/');
        expect((new Process(['pdftotext', $arabicPdfPath, '-']))->mustRun()->getOutput())
            ->toContain($maintenanceRequest->doc_num, $plan->doc_num, $fixture['workOrder']->doc_num);
    } finally {
        @unlink($arabicPdfPath);
    }
});

test('maintenance material quantities remain separated by unit on screen and exports', function (): void {
    app()->setLocale('en');
    $fixture = maintenanceMaterialCostFixture();
    $kilogram = ItemUnit::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 97002,
        'doc_num' => 'UNIT-MAINT-KG',
        'name' => 'Maintenance Kilogram',
        'status' => 'active',
    ]);
    $secondProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 97002,
        'doc_num' => 'RM-MAINT-KG',
        'name' => 'Maintenance Compound',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $kilogram->getKey(),
        'status' => 'active',
    ]);
    app(MaintenanceMaterialRequestService::class)->create($fixture['workOrder'], [
        'branch_store_id' => $fixture['store']->getKey(),
        'lines' => [
            ['product_id' => $fixture['product']->getKey(), 'item_type' => 'spare_part', 'quantity' => '2'],
            ['product_id' => $secondProduct->getKey(), 'item_type' => 'consumable', 'quantity' => '3'],
        ],
    ]);
    foreach (['maintenance.reports.view', 'maintenance.reports.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $fixture['user']->givePermissionTo(['maintenance.reports.view', 'maintenance.reports.export']);

    $screen = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.index'))->assertOk();
    $screen->assertSee(__('maintenance.reports.material_quantities_by_unit'))
        ->assertSee('Maintenance Piece')
        ->assertSee('Maintenance Kilogram');
    $excel = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.export'))->assertOk();
    $workbook = IOFactory::load($excel->baseResponse->getFile()->getPathname());
    expect($workbook->getSheetCount())->toBe(5)
        ->and($workbook->getSheet(3)->getCell('A2')->getValue())->toBe('Maintenance Piece')
        ->and($workbook->getSheet(3)->getCell('B2')->getValue())->toBe('2.00000000')
        ->and($workbook->getSheet(3)->getCell('A3')->getValue())->toBe('Maintenance Kilogram')
        ->and($workbook->getSheet(3)->getCell('B3')->getValue())->toBe('3.00000000');
    $csv = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.export.csv'))->assertOk();
    $csvRows = array_map('str_getcsv', file($csv->baseResponse->getFile()->getPathname()));
    $quantityRows = collect($csvRows)->filter(fn (array $row): bool => ($row[0] ?? '') === __('maintenance.reports.material_quantities_by_unit') && ($row[1] ?? '') === __('sales_ui.reports.export.row_types.data'))->values();
    expect($quantityRows)->toHaveCount(2)
        ->and($quantityRows[0][2])->toBe('Maintenance Piece')
        ->and($quantityRows[0][3])->toBe('2.00000000')
        ->and($quantityRows[1][2])->toBe('Maintenance Kilogram')
        ->and($quantityRows[1][3])->toBe('3.00000000');
    $pdf = $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.print'))->assertOk();
    $pdfPath = tempnam(sys_get_temp_dir(), 'maintenance-unit-pdf-');
    if ($pdfPath === false) {
        throw new RuntimeException('Could not create a temporary PDF path.');
    }
    try {
        file_put_contents($pdfPath, $pdf->getContent());
        $pdfText = (new Process(['pdftotext', $pdfPath, '-']))->mustRun()->getOutput();
    } finally {
        @unlink($pdfPath);
    }
    expect($pdfText)->toContain('Maintenance Piece', 'Maintenance Kilogram');
});

test('maintenance PDF report renders exact per-material costs and redacts them without financial permission', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture, '1.125');
    $service->issue($request);
    $line = $request->refresh()->lines()->sole();
    $service->recordConsumption($fixture['workOrder'], [[
        'line_id' => $line->getKey(),
        'consumed_quantity' => '0.125',
    ]]);
    $service->returnUnused($request->fresh());

    Permission::findOrCreate('maintenance.reports.export', 'web');
    Permission::findOrCreate('maintenance.reports.view', 'web');
    Permission::findOrCreate('maintenance.reports.financial', 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $fixture['user']->givePermissionTo(['maintenance.reports.view', 'maintenance.reports.export', 'maintenance.reports.financial']);

    $extractPdfText = function (string $contents): string {
        $path = tempnam(sys_get_temp_dir(), 'maintenance-report-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary PDF path.');
        }

        try {
            file_put_contents($path, $contents);
            $process = new Process(['pdftotext', $path, '-']);
            $process->mustRun();
            expect((new Process(['pdfinfo', $path]))->mustRun()->getOutput())->toMatch('/Pages:\s+1/');

            return preg_replace('/\s+/', ' ', $process->getOutput()) ?? '';
        } finally {
            @unlink($path);
        }
    };

    $authorizedResponse = $this->actingAs($fixture['user'])
        ->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.print'));
    $authorizedResponse->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $authorizedText = $extractPdfText($authorizedResponse->getContent());
    expect($authorizedText)
        ->toContain('RM-MAINT-COST', 'Maintenance Piece', 'Issued: 1.12500000', 'Returned: 1.00000000', 'Net: 0.12500000')
        ->toContain('Unit cost: 4.00000000 / Gross: 4.5000 / Returned cost: 4.0000 / Net cost: 0.5000');
    $csvRoute = route('admin.maintenance.reports.export.csv');
    $authorizedCsv = $this->actingAs($fixture['user'])
        ->withSession($fixture['session'])->get($csvRoute)->assertOk();
    $authorizedCsvContents = file_get_contents($authorizedCsv->baseResponse->getFile()->getPathname());
    expect($authorizedCsvContents)->toContain('RM-MAINT-COST', '0.5000', __('maintenance.reports.net_material_cost'));
    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.index'))
        ->assertOk()->assertSee($csvRoute, false);

    $fixture['user']->revokePermissionTo('maintenance.reports.financial');
    $redactedResponse = $this->actingAs($fixture['user'])
        ->withSession($fixture['session'])
        ->get(route('admin.maintenance.reports.print'));
    $redactedResponse->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $redactedText = $extractPdfText($redactedResponse->getContent());
    expect($redactedText)
        ->toContain('RM-MAINT-COST', 'Issued: 1.12500000', 'Returned: 1.00000000', 'Net: 0.12500000')
        ->not->toContain('Unit cost:', 'Gross:', 'Returned cost:', 'Net cost:');
    $redactedCsv = $this->actingAs($fixture['user'])
        ->withSession($fixture['session'])->get($csvRoute)->assertOk();
    $redactedCsvContents = file_get_contents($redactedCsv->baseResponse->getFile()->getPathname());
    expect($redactedCsvContents)->toContain('RM-MAINT-COST')
        ->not->toContain(__('maintenance.reports.net_material_cost'), '0.5000');
    $fixture['user']->revokePermissionTo('maintenance.reports.export');
    $this->actingAs($fixture['user'])->withSession($fixture['session'])
        ->get($csvRoute)->assertForbidden();
});

test('maintenance return with null-location legacy allocation preserves null on transaction and restored layer', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture, '2');
    $issue = $service->issue($request);
    $issueTransaction = $issue->transactions()->sole();

    // Null-location legacy: allocation layers have null warehouse_location_id
    $allocatedLayers = InventoryLayerAllocation::query()
        ->with('layer')
        ->where('issue_transaction_id', $issueTransaction->getKey())
        ->orderBy('id')
        ->get();
    expect($allocatedLayers)->toHaveCount(1);
    $allocatedLayers[0]->layer->update(['warehouse_location_id' => null]);

    $line = $request->refresh()->lines()->sole();
    $return = $service->returnUnused($request->fresh());
    $return->load(['lines', 'transactions']);
    $returnLine = $return->lines->sole();
    $returnTransaction = $return->transactions->sole();
    $restoredLayer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $returnTransaction->getKey())->sole();

    expect($returnLine->warehouse_location_id)->toBeNull()
        ->and($returnTransaction->warehouse_location_id)->toBeNull()
        ->and($restoredLayer->warehouse_location_id)->toBeNull()
        ->and($returnLine->quantity)->toBe('2.00000000')
        ->and($returnTransaction->quantity_in)->toBe('2.00000000')
        ->and($restoredLayer->original_quantity)->toBe('2.00000000')
        // The restored book value follows its issue allocation; native receipt history remains separately traceable.
        ->and($restoredLayer->unit_cost)->toBe('4.00000000')
        ->and($restoredLayer->sourceAllocation->layer->unit_cost)->toBe('2.00000000')
        ->and($returnTransaction->unit_cost)->toBe('4.00000000')
        // Original receipt date preserved from source layer
        ->and($restoredLayer->original_receipt_date)->not->toBeNull();

    // Aggregate availability remains consistent with null location
    expect(app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['product']->getKey(),
    )['physical_on_hand'])->toBe('20.00000000');
});

test('eight digit maintenance return allocation slices consume the original booked journal with exact final rounding carry', function (): void {
    $fixture = maintenanceMaterialCostFixture();
    foreach (InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)->get() as $receipt) {
        $unitCost = bcadd((string) $receipt->unit_cost, '0.00005678', 8);
        $receipt->update(['unit_cost' => $unitCost, 'total_cost' => bcmul((string) $receipt->quantity_in, $unitCost, 8)]);
    }
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture);
    $issue = $service->issue($request)->load('transactions', 'journalEntry.lines');
    $line = $request->refresh()->lines()->sole();
    expect($issue->transactions->sole()->total_cost)->toBe('60.00085170')->and($issue->journalEntry->lines->sum('debit_amount'))->toBe(60.0009);
    $source = DB::table('journal_entry_lines')->where('journal_entry_id', $issue->journal_entry_id)->orderBy('id')->get()->toArray();
    $service->recordConsumption($fixture['workOrder'], [['line_id' => $line->id, 'consumed_quantity' => '0']]);
    $returned = $service->returnUnused($request->fresh());
    expect($returned->lines)->toHaveCount(2);
    $slices = $returned->lines->sortBy('id')->values();
    expect($slices[0]->product_snapshot['maintenance_return_accounting']['amount'])->toBe('40.0006')
        ->and($slices[1]->product_snapshot['maintenance_return_accounting']['amount'])->toBe('20.0003')
        ->and(bcadd((string) $returned->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('60.0009')
        ->and($request->fresh()->lines->sole()->returned_quantity)->toBe('15.00000000')
        ->and(DB::table('journal_entry_lines')->where('journal_entry_id', $issue->journal_entry_id)->orderBy('id')->get()->toArray())->toEqual($source);
});

test('maintenance return after periodic completion reverses the corrected issue expense and stock with preserved original history', function (): void {
    require_once __DIR__.'/../InventoryPeriodicCostCloseSupport.php';
    $fixture = maintenanceMaterialCostFixture(periodic: true);
    $fixture['preparer'] = $fixture['user'];
    $fixture['approver'] = closureSyntheticUser();
    $fixture = periodicCostFixture($fixture);
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture);
    $issue = $service->issue($request)->load('transactions', 'journalEntry.lines');
    $original = $issue->transactions->sole()->getAttributes();
    $oldJournal = DB::table('journal_entry_lines')->where('journal_entry_id', $issue->journal_entry_id)->orderBy('id')->get()->toArray();
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '10');
    $close = approvePeriodicCost($fixture, preparePeriodicCost($fixture, ['from_date' => now()->subDays(2)->toDateString(),
        'to_date' => now()->toDateString(), 'posting_date' => now()->toDateString()]));
    expect($issue->transactions->sole()->completedTotalCost())->toBe('105.00000000');
    test()->actingAs($fixture['user']);
    request()->setUserResolver(fn (): User => $fixture['user']);
    $this->travel(1)->days();
    try {
        $line = $request->fresh()->lines->sole();
        $service->recordConsumption($fixture['workOrder'], [['line_id' => $line->id, 'consumed_quantity' => '0']]);
        $returned = $service->returnUnused($request->fresh());
        expect(bcadd((string) $returned->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('105.0000')
            ->and($returned->transactions->sum('total_cost'))->toBe(105.0)
            ->and($issue->transactions->sole()->fresh()->getAttributes())->toBe($original)
            ->and(DB::table('journal_entry_lines')->where('journal_entry_id', $issue->journal_entry_id)->orderBy('id')->get()->toArray())->toEqual($oldJournal);
    } finally {
        $this->travelBack();
    }
});

test('maintenance returns reject altered source and completion journal headers atomically before accepting the valid completed cost', function (): void {
    require_once __DIR__.'/../InventoryPeriodicCostCloseSupport.php';
    $fixture = maintenanceMaterialCostFixture(periodic: true);
    $fixture['preparer'] = $fixture['user'];
    $fixture['approver'] = closureSyntheticUser();
    $fixture = periodicCostFixture($fixture);
    $service = app(MaintenanceMaterialRequestService::class);
    $request = approvedMaintenanceMaterialRequest($fixture);
    $issue = $service->issue($request)->load('journalEntry');
    costTransitionMovement($fixture, now()->toDateString(), InventoryDocument::TypeReceipt, '20', '10');
    $close = approvePeriodicCost($fixture, preparePeriodicCost($fixture, ['from_date' => now()->subDays(2)->toDateString(),
        'to_date' => now()->toDateString(), 'posting_date' => now()->toDateString()]));
    $otherCompany = Company::factory()->create();
    $otherCurrency = Currency::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 998712,
        'doc_num' => 'SYNTHETIC-HEADER-CURRENCY', 'name' => 'SYNTHETIC other currency', 'code' => 'SYN',
        'minor_unit_name' => 'SYN', 'minor_unit_factor' => 100, 'is_main' => false, 'status' => 'active']);
    $otherPeriod = FinancialPeriod::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 998712,
        'doc_num' => 'SYNTHETIC-HEADER-PERIOD', 'name' => 'SYNTHETIC other period',
        'from_date' => $fixture['period']->from_date->copy()->addYear(), 'to_date' => $fixture['period']->to_date->copy()->addYear(), 'is_closed' => false]);
    test()->actingAs($fixture['user']);
    request()->setUserResolver(fn (): User => $fixture['user']);
    $this->travel(1)->days();
    try {
        $service->recordConsumption($fixture['workOrder'], [['line_id' => $request->fresh()->lines->sole()->id, 'consumed_quantity' => '0']]);
        $counts = [InventoryDocument::query()->count(), InventoryTransaction::query()->count(), DB::table('journal_entries')->count()];
        foreach ([$issue->journal_entry_id, $close->valueAdjustment->journal_entry_id] as $journalId) {
            $header = (array) DB::table('journal_entries')->where('id', $journalId)->first();
            foreach (['company_id' => $otherCompany->id, 'currency_id' => $otherCurrency->id, 'financial_period_id' => $otherPeriod->id,
                'branch_id' => null, 'entry_date' => now()->addDays(3)->toDateString(), 'source_type' => 'SYNTHETIC-wrong-source',
                'source_id' => 998712, 'exchange_rate' => '2', 'status' => 'draft', 'is_posted' => false] as $field => $value) {
                DB::table('journal_entries')->where('id', $journalId)->update([$field => $value]);
                expect(fn () => $service->returnUnused($request->fresh()))->toThrow(DomainException::class);
                expect([InventoryDocument::query()->count(), InventoryTransaction::query()->count(), DB::table('journal_entries')->count()])
                    ->toBe($counts, 'SYNTHETIC altered '.$field.' must leave no partial return');
                DB::table('journal_entries')->where('id', $journalId)->update([$field => $header[$field]]);
            }
        }
        $return = $service->returnUnused($request->fresh());
        expect(bcadd((string) $return->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe('105.0000');
    } finally {
        $this->travelBack();
    }
});
