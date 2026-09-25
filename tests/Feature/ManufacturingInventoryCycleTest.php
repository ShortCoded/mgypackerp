<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Inventory\Services\StockCountService;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Maintenance\Models\MaintenanceMeterReading;
use Modules\Maintenance\Models\MaintenancePlan;
use Modules\Maintenance\Models\MaintenancePlanDue;
use Modules\Maintenance\Models\MaintenanceRequest;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionProgressEntry;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionRunBatch;
use Modules\Production\Models\QualityInspectionType;
use Modules\Production\Models\QualityStockHold;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionMaterialRequestService;
use Modules\Production\Services\ProductionReportService;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** @return array<string, mixed> */
function manufacturingInventoryFixture(): array
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
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Manufacturing Store', 'position' => 1]);
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 9901, 'doc_num' => 'UNIT-MFG',
        'name' => 'Manufacturing Unit', 'status' => 'active',
    ]);
    $finished = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 9901, 'doc_num' => 'FG-MFG',
        'name' => 'Plastic Finished Unit', 'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    $raw = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 9902, 'doc_num' => 'RM-MFG',
        'name' => 'Plastic Resin', 'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    ProductComponent::query()->create([
        'company_id' => $company->getKey(), 'product_id' => $finished->getKey(),
        'component_product_id' => $raw->getKey(), 'unit_id' => $unit->getKey(),
        'calculation_method' => ProductComponent::CalculationDirect, 'quantity' => '2',
        'created_by' => $user->getKey(),
    ]);
    InventoryTransaction::query()->create([
        'posting_key' => 'manufacturing-opening-resin', 'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(), 'branch_id' => $branch->getKey(),
        'branch_store_id' => $store->getKey(), 'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => now()->toDateString(), 'transaction_type' => 'opening_stock',
        'product_id' => $raw->getKey(), 'unit_id' => $unit->getKey(), 'quantity_in' => '1000',
        'quantity_out' => 0, 'source_type' => 'test', 'source_id' => 1,
        'source_doc_num' => 'OPEN-MFG', 'unit_cost' => '2', 'total_cost' => '2000',
        'created_by' => $user->getKey(),
    ]);
    $machine = ProductionMachine::query()->create([
        'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
        'code' => 'M-01', 'name' => 'Injection Machine', 'created_by' => $user->getKey(),
    ]);
    $mold = ProductionMold::query()->create([
        'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(),
        'code' => 'MD-01', 'name' => 'Container Mold', 'created_by' => $user->getKey(),
    ]);
    $machine->molds()->attach($mold);
    $mold->products()->attach($finished);

    return compact('user', 'company', 'branch', 'period', 'store', 'unit', 'finished', 'raw', 'machine', 'mold');
}

/** @param array<string, mixed> $fixture */
function manufacturingMaintenanceAssetAccount(array $fixture): Account
{
    return Account::query()
        ->where('company_id', $fixture['company']->getKey())
        ->where('status', 'active')
        ->where('is_group', false)
        ->where('is_postable', true)
        ->whereDoesntHave('fixedAsset')
        ->whereHas('classification', fn ($query) => $query
            ->where('code', AccountClassification::FixedAssets)
            ->where('status', 'active'))
        ->firstOrFail();
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function manufacturingIntegrityRun(array $fixture, string $plannedQuantity = '1'): array
{
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => $plannedQuantity,
    ]]);
    $order = $cycle->releaseOrder($order);
    $orderLine = $order->lines->firstOrFail();
    $run = $cycle->createRun($orderLine, [
        'planned_quantity' => $plannedQuantity,
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(2),
        'production_machine_id' => $fixture['machine']->getKey(),
        'production_mold_id' => $fixture['mold']->getKey(),
        'batch_lot' => 'INTEGRITY-LOT-001',
    ]);

    return compact('cycle', 'order', 'orderLine', 'run');
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function manufacturingIntegritySession(array $fixture): array
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

/** @param array<string, mixed> $payload @return array<string, mixed> */
function productionSubmission(array $payload = []): array
{
    return ['_submission_token' => (string) Str::uuid(), ...$payload];
}

test('a production batch issues only available BOM materials for all included product lines', function () {
    $fixture = manufacturingInventoryFixture();
    InventoryTransaction::query()
        ->where('product_id', $fixture['raw']->getKey())
        ->where('source_doc_num', 'OPEN-MFG')
        ->update(['quantity_in' => '9', 'total_cost' => '18']);

    $secondFinished = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9903,
        'doc_num' => 'FG-MFG-SECOND',
        'name' => 'Second Finished Unit',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(),
        'status' => 'active',
    ]);
    ProductComponent::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'product_id' => $secondFinished->getKey(),
        'component_product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'calculation_method' => ProductComponent::CalculationDirect,
        'quantity' => '2',
        'created_by' => $fixture['user']->getKey(),
    ]);

    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [
        ['product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'quantity' => '8'],
        ['product_id' => $secondFinished->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'quantity' => '8'],
    ]);
    $order = $cycle->releaseOrder($order);
    Permission::findOrCreate('production.runs.plan', 'web');
    Permission::findOrCreate('production.runs.issue', 'web');
    Permission::findOrCreate('production.runs.receive', 'web');
    Permission::findOrCreate('production.runs.view', 'web');
    Permission::findOrCreate('inventory.documents.create', 'web');
    Permission::findOrCreate('inventory.documents.issue', 'web');
    Permission::findOrCreate('inventory.documents.receive', 'web');
    Permission::findOrCreate('production.material_requests.create', 'web');
    Permission::findOrCreate('inventory.documents.view', 'web');
    $fixture['user']->givePermissionTo([
        'production.runs.plan',
        'production.runs.issue',
        'production.runs.receive',
        'production.runs.view',
        'inventory.documents.create',
        'inventory.documents.issue',
        'inventory.documents.receive',
        'production.material_requests.create',
        'inventory.documents.view',
    ]);
    $session = manufacturingIntegritySession($fixture);
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.runs.create'))
        ->assertOk()
        ->assertSee('name="production_order_doc_num"', false)
        ->assertSee('name="fixed_asset_doc_num"', false)
        ->assertDontSee('name="production_machine_public_id"', false)
        ->assertSee('data-production-run-batch-lines', false)
        ->assertSee('data-line-card-label="بند أمر الإنتاج"', false)
        ->assertDontSee('name="production_shift_id"', false)
        ->assertDontSee('name="cost_center_doc_num"', false)
        ->assertSee('data-depends-on="#production-run-batch-line-__INDEX__"', false)
        ->assertSeeInOrder([
            'name="production_order_doc_num"',
            'name="fixed_asset_doc_num"',
            'name="notes"',
            'data-production-run-batch-lines',
        ], false);
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.production.runs.select2.orders', ['q' => $order->doc_num]))
        ->assertOk()
        ->assertJsonPath('results.0.id', $order->doc_num);
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.production.runs.orders.lines', ['docNum' => $order->doc_num]))
        ->assertOk()
        ->assertJsonPath('data.order.id', $order->doc_num)
        ->assertJsonCount(2, 'data.lines');
    $createToken = (string) Str::uuid();
    $createPayload = [
        '_submission_token' => $createToken,
        'production_order_doc_num' => $order->doc_num,
        'production_machine_public_id' => $fixture['machine']->public_id,
        'planned_start_at' => now()->addHour()->toDateTimeString(),
        'planned_end_at' => now()->addHours(2)->toDateTimeString(),
        'lines' => $order->lines->map(fn ($line): array => [
            'production_order_line_public_id' => $line->public_id,
            'planned_quantity' => '4',
        ])->all(),
    ];
    $createUrl = route('admin.production.runs.store');
    $createResponse = $this->actingAs($fixture['user'])->withSession($session)
        ->postJson($createUrl, $createPayload)
        ->assertCreated();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson($createUrl, $createPayload)
        ->assertCreated()
        ->assertExactJson($createResponse->json());
    $batch = ProductionRunBatch::query()->where('batch_number', data_get($createResponse->json(), 'data.batch_number'))->firstOrFail();
    $firstRun = $batch->runs()->with('requirements.product')->firstOrFail();

    $materialRequestForm = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.material-requests.create', ['run' => $firstRun->getKey()]))
        ->assertOk()
        ->assertSee($fixture['raw']->doc_num)
        ->assertSee('data-planned-remaining="8.00000000"', false)
        ->assertSee('data-navigation-url=', false);
    preg_match('/<select\b[^>]*data-material-run-select[^>]*>/s', $materialRequestForm->getContent(), $runSelectMatches);
    expect($runSelectMatches[0] ?? null)->toContain('data-navigation-url=')
        ->not->toContain('data-url=');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.runs.batches.show', $batch))
        ->assertOk()
        ->assertSee($batch->batch_number)
        ->assertSee(__('production_execution.runs.issue_batch'));
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.inventory.documents.create'))
        ->assertOk()
        ->assertSee(__('inventory.movements.fields.production_run_batch'));
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.inventory.documents.select2.production-run-batches', ['q' => $batch->batch_number]))
        ->assertOk()
        ->assertJsonPath('results.0.id', $batch->public_id);
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.inventory.documents.production-batches.details', $batch->public_id))
        ->assertOk()
        ->assertJsonCount(2, 'data.materials')
        ->assertJsonPath('data.materials.0.quantity', '8');

    $issueToken = (string) Str::uuid();
    $issueUrl = route('admin.inventory.documents.store');
    $issuePayload = [
        '_submission_token' => $issueToken,
        'document_type' => InventoryDocument::TypeIssue,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'production_run_batch_public_id' => $batch->public_id,
    ];
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson($issueUrl, [...$issuePayload, 'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'quantity' => '100000',
        ]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines');
    $movementService = app(InventoryMovementService::class);
    $failingMovementService = Mockery::mock($movementService)->makePartial();
    $failingMovementService->shouldReceive('createAndPost')
        ->once()
        ->andReturnUsing(function (array $header, array $lines) use ($movementService): never {
            $movementService->createAndPost($header, $lines);
            throw new RuntimeException('Injected failure after batch issue posting.');
        });
    $this->app->instance(InventoryMovementService::class, $failingMovementService);
    $failingCycle = app(ProductionCycleService::class);
    expect(fn () => $failingCycle->issueRunBatchMaterials($batch, $fixture['store']->getKey()))
        ->toThrow(RuntimeException::class, 'Injected failure after batch issue posting.');
    $requirementIds = $batch->runs()->with('requirements')->get()->flatMap->requirements->pluck('id')->all();
    expect(InventoryDocument::query()->where('production_run_batch_id', $batch->getKey())->count())->toBe(0)
        ->and(InventoryReservation::query()->whereIn('production_material_requirement_id', $requirementIds)->count())->toBe(0)
        ->and($batch->runs()->with('requirements')->get()->flatMap->requirements->pluck('issued_quantity')->unique()->all())
        ->toBe(['0.00000000'])
        ->and(InventoryTransaction::query()->whereIn('production_run_id', $batch->runs()->pluck('id'))
            ->where('stock_status', InventoryTransaction::StatusProductionStaging)
            ->count())->toBe(0);
    $this->app->instance(InventoryMovementService::class, $movementService);
    $cycle = app(ProductionCycleService::class);
    $issueResponse = $this->actingAs($fixture['user'])->withSession($session)
        ->postJson($issueUrl, $issuePayload)
        ->assertCreated();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson($issueUrl, $issuePayload)
        ->assertCreated()
        ->assertExactJson($issueResponse->json());
    $document = InventoryDocument::query()->where('doc_num', data_get($issueResponse->json(), 'data.doc_num'))->firstOrFail();
    $document->load('lines');
    $runs = $batch->runs()->with('requirements')->get();
    $requirements = $runs->flatMap->requirements->sortBy('id')->values();
    $costPositions = app(ProductionCostService::class)->positions($runs);

    expect($document->status)->toBe(InventoryDocument::StatusPosted)
        ->and($document->production_run_id)->toBeNull()
        ->and((int) $document->production_run_batch_id)->toBe((int) $batch->getKey())
        ->and($document->lines)->toHaveCount(2)
        ->and($document->lines->pluck('quantity')->all())->toBe(['8.00000000', '1.00000000'])
        ->and($document->lines->pluck('production_run_id')->unique()->count())->toBe(2)
        ->and($requirements->pluck('issued_quantity')->all())->toBe(['8.00000000', '1.00000000'])
        ->and($costPositions->get($runs[0]->getKey())['issued'])->toBe('16.00000000')
        ->and($costPositions->get($runs[1]->getKey())['issued'])->toBe('2.00000000')
        ->and(app(InventoryAvailabilityService::class)->forProduct(
            $fixture['company']->getKey(),
            $fixture['store']->getKey(),
            $fixture['raw']->getKey(),
        )['available'])->toBe('0.00000000');
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.inventory.documents.show', $document))
        ->assertOk()
        ->assertSee(app(DateFormatService::class)->formatDate($document->document_date, '—'))
        ->assertSee($batch->batch_number);

    $runs->each(function (ProductionRun $run) use ($cycle, $fixture): void {
        $run = $cycle->startSetup($run);
        $run = $cycle->completeSetup($run);
        $run = $cycle->startRun($run);
        $accounting = $run->requirements->mapWithKeys(fn ($requirement): array => [
            $requirement->getKey() => [
                'consumed_quantity' => (string) $requirement->issued_quantity,
                'waste_quantity' => '0',
            ],
        ])->all();
        $cycle->accountMaterials($run, $fixture['store']->getKey(), $accounting);
        $cycle->recordProgress($run, [
            'good_base_quantity' => '1',
            'scrap_base_quantity' => '0',
            'notes' => 'Batch line completed for reconciliation',
        ]);
    });
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.inventory.documents.select2.production-run-batches', [
            'document_type' => InventoryDocument::TypeReceipt,
            'q' => $batch->batch_number,
        ]))
        ->assertOk()
        ->assertJsonPath('results.0.id', $batch->public_id);
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.inventory.documents.production-batches.details', [
            'publicId' => $batch->public_id,
            'document_type' => InventoryDocument::TypeReceipt,
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'data.outputs');
    $receiptResponse = $this->actingAs($fixture['user'])->withSession($session)
        ->postJson($issueUrl, [
            '_submission_token' => (string) Str::uuid(),
            'document_type' => InventoryDocument::TypeReceipt,
            'branch_store_uuid' => $fixture['store']->public_uuid,
            'production_run_batch_public_id' => $batch->public_id,
        ])
        ->assertCreated();
    $receiptDocuments = InventoryDocument::query()->whereIn('doc_num', data_get($receiptResponse->json(), 'data.doc_nums'))->get();
    expect($receiptDocuments)->toHaveCount(2)
        ->and($receiptDocuments->every(fn (InventoryDocument $receipt): bool => $receipt->document_type === InventoryDocument::TypeProductionReceipt
            && (int) $receipt->production_run_batch_id === (int) $batch->getKey()))->toBeTrue();
    foreach ($runs as $run) {
        expect($cycle->completeRun($run->fresh())->status)->toBe(ProductionRun::StatusCompleted);
    }
    $batchReconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
    ))->keyBy('key');
    expect($batchReconciliation['wip']['difference'])->toBe('0.0000')
        ->and($batchReconciliation['finished_goods']['difference'])->toBe('0.0000');
});

function operationalReportCount(string $html, string $key): int
{
    preg_match('/data-report-count="'.preg_quote($key, '/').'"[^>]*>\s*([0-9,]+)\s*</', $html, $matches);

    return (int) str_replace(',', '', $matches[1] ?? '0');
}

function operationalDashboardCount(string $html, string $key): int
{
    preg_match('/data-operational-card="'.preg_quote($key, '/').'".*?data-operational-card-value[^>]*>\s*([0-9,]+)\s*</s', $html, $matches);

    return (int) str_replace(',', '', $matches[1] ?? '0');
}

test('the canonical manufacturing cycle reconciles physical stock, reservations, waste, quality, and partial finished receipts', function () {
    $fixture = manufacturingInventoryFixture();
    $cycle = app(ProductionCycleService::class);
    $availability = app(InventoryAvailabilityService::class);
    $inProcessInspectionType = QualityInspectionType::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'code' => 'IN-PROCESS',
        'name' => 'In-Process Quality',
        'is_final_production' => false,
        'is_active' => true,
    ]);
    $finalInspectionType = QualityInspectionType::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'code' => 'FINAL-PRODUCTION',
        'name' => 'Final Production Release',
        'is_final_production' => true,
        'is_active' => true,
    ]);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'overproduction_tolerance_percent' => '0',
    ], [[
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '10',
    ]]);
    $order = $cycle->releaseOrder($order);
    $line = $order->lines->first();

    expect($order->status)->toBe(ProductionOrder::StatusReleased)
        ->and($line->bom_snapshot['components'][0]['product_id'])->toBe($fixture['raw']->getKey())
        ->and($line->bom_snapshot['components'][0]['base_quantity_per_output'])->toBe('2.00000000');

    $run = $cycle->createRun($line, [
        'planned_quantity' => '5',
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(3),
        'production_machine_id' => $fixture['machine']->getKey(),
        'production_mold_id' => $fixture['mold']->getKey(),
        'batch_lot' => 'LOT-MFG-001',
    ]);
    expect($run->requirements)->toHaveCount(1)
        ->and($run->requirements->first()->planned_quantity)->toBe('10.00000000');

    expect(fn () => $cycle->createRun($line, [
        'planned_quantity' => '5',
        'planned_start_at' => now()->addHours(2),
        'planned_end_at' => now()->addHours(4),
        'production_machine_id' => $fixture['machine']->getKey(),
        'production_mold_id' => $fixture['mold']->getKey(),
    ]))->toThrow(DomainException::class, __('The selected machine or mold has an overlapping production run.'));

    $competingReservation = InventoryReservation::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '995',
        'stock_status' => InventoryTransaction::StatusAvailable,
        'status' => InventoryReservation::StatusActive,
        'created_by' => $fixture['user']->getKey(),
    ]);
    expect(fn () => $cycle->reserveRun($run, $fixture['store']->getKey()))
        ->toThrow(DomainException::class, __('The production reservation exceeds available stock.'));
    $competingReservation->delete();
    $run = $cycle->reserveRun($run, $fixture['store']->getKey());
    $requirement = $run->requirements->first();
    $position = $availability->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['raw']->getKey(),
    );
    expect($requirement->reserved_quantity)->toBe('10.00000000')
        ->and($position['physical_on_hand'])->toBe('1000.00000000')
        ->and($position['available'])->toBe('990.00000000');

    $issue = $cycle->issueMaterials($run, $fixture['store']->getKey());
    $transactionCount = $issue->transactions()->count();
    app(InventoryDocumentPostingService::class)->post($issue);
    expect($issue->transactions()->count())->toBe($transactionCount)
        ->and($issue->lines->first()->inventory_reservation_id)->not->toBeNull()
        ->and($issue->journalEntry)->toBeInstanceOf(JournalEntry::class)
        ->and((string) $issue->journalEntry->lines->sum('debit_amount'))->toBe('20')
        ->and((string) $issue->journalEntry->lines->sum('credit_amount'))->toBe('20')
        ->and(InventoryTransaction::query()->where('production_run_id', $run->getKey())->where('stock_status', InventoryTransaction::StatusProductionStaging)->sum('quantity_in'))->toEqual(10);

    $cycle->startSetup($run);
    $cycle->completeSetup($run->fresh());
    $run = $cycle->startRun($run->fresh());
    $additionalIssue = $cycle->issueMaterials(
        $run,
        $fixture['store']->getKey(),
        [$requirement->getKey() => '2'],
        true,
    );
    $materialReturn = $cycle->returnMaterials(
        $run,
        $fixture['store']->getKey(),
        [$requirement->getKey() => '1'],
    );
    expect($additionalIssue->document_type)->toBe(InventoryDocument::TypeAdditionalMaterialIssue)
        ->and($materialReturn->document_type)->toBe(InventoryDocument::TypeMaterialReturn);
    $progressTimeFloor = now()->subSecond();
    $progress = $cycle->recordProgress($run, [
        'recorded_at' => now()->subYear(),
        'good_base_quantity' => '4',
        'scrap_base_quantity' => '1',
        'notes' => 'First completed production run',
    ]);
    expect($progress->recorded_at->greaterThanOrEqualTo($progressTimeFloor))->toBeTrue();

    $otherCompany = Company::factory()->create();
    $otherCompanyInspectionType = QualityInspectionType::query()->create([
        'company_id' => $otherCompany->getKey(),
        'code' => 'FOREIGN-CHECKPOINT-TYPE',
        'name' => 'Foreign Checkpoint Type',
        'is_final_production' => false,
        'is_active' => true,
    ]);
    $foreignCheckpointId = DB::table('quality_checkpoints')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'company_id' => $otherCompany->getKey(),
        'quality_inspection_type_id' => $otherCompanyInspectionType->getKey(),
        'code' => 'FOREIGN-CHECKPOINT',
        'name' => 'Foreign Checkpoint',
        'sequence' => 10,
        'response_type' => 'pass_fail',
        'is_required' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $requiredCheckpointId = DB::table('quality_checkpoints')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'company_id' => $fixture['company']->getKey(),
        'quality_inspection_type_id' => $inProcessInspectionType->getKey(),
        'code' => 'DIMENSION-CHECK',
        'name' => 'Dimension Check',
        'sequence' => 10,
        'response_type' => 'numeric',
        'is_required' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(fn () => $cycle->recordInspection($run->fresh(), [
        'quality_inspection_type_id' => $inProcessInspectionType->getKey(),
        'result' => 'passed',
        'results' => [[
            'quality_checkpoint_id' => $foreignCheckpointId,
            'result' => 'passed',
        ]],
    ]))->toThrow(DomainException::class, __('Quality checkpoints must be active and belong to the selected inspection type and operating company.'));
    expect(fn () => $cycle->recordInspection($run->fresh(), [
        'quality_inspection_type_id' => $inProcessInspectionType->getKey(),
        'result' => 'passed',
    ]))->toThrow(DomainException::class, __('production_execution.messages.required_quality_checkpoints_missing'));

    $inspectionTimeFloor = now()->subSecond();
    $failedInspection = $cycle->recordInspection($run->fresh(), [
        'quality_inspection_type_id' => $inProcessInspectionType->getKey(),
        'sampled_at' => now()->subYear(),
        'result' => 'failed',
        'defect_code' => 'QC-DIMENSION',
        'affected_base_quantity' => '1',
        'corrective_action' => 'Verify the mold and resample',
        'results' => [[
            'quality_checkpoint_id' => $requiredCheckpointId,
            'result' => 'failed',
            'measured_value' => '12.40',
        ]],
    ]);
    expect($failedInspection->production_run_id)->toBe($run->getKey())
        ->and(data_get($failedInspection->inspection_plan_snapshot, 'type.code'))->toBe($inProcessInspectionType->code)
        ->and(data_get($failedInspection->inspection_plan_snapshot, 'checkpoints.0.id'))->toBe($requiredCheckpointId)
        ->and(data_get($failedInspection->inspection_plan_snapshot, 'revision'))->not->toBeNull()
        ->and($failedInspection->sampled_at->greaterThanOrEqualTo($inspectionTimeFloor))->toBeTrue()
        ->and($run->fresh()->status)->toBe(ProductionRun::StatusHeld)
        ->and(fn () => $cycle->completeRun($run->fresh()))->toThrow(DomainException::class);
    $passedInspection = $cycle->recordInspection($run->fresh(), [
        'quality_inspection_type_id' => $inProcessInspectionType->getKey(),
        'sampled_at' => now()->addYear(),
        'result' => 'passed',
        'notes' => 'Corrective action verified',
        'results' => [[
            'quality_checkpoint_id' => $requiredCheckpointId,
            'result' => 'passed',
            'measured_value' => '12.00',
        ]],
    ]);
    expect($passedInspection->sampled_at->greaterThanOrEqualTo($failedInspection->sampled_at))->toBeTrue();
    $passedInspection = $cycle->reviewInspection($passedInspection, true);
    $passedInspection->update([
        'status' => ProductionQualityInspection::StatusClosed,
        'closed_by' => $fixture['user']->getKey(),
        'closed_at' => now(),
    ]);
    $run = $cycle->resumeRun($run->fresh());
    expect($run->status)->toBe(ProductionRun::StatusRunning);

    $documents = $cycle->accountMaterials($run->fresh(), $fixture['store']->getKey(), [
        $requirement->getKey() => ['consumed_quantity' => '10', 'waste_quantity' => '1'],
    ]);
    expect($documents)->toHaveKeys(['consumption', 'waste']);

    expect(fn () => $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '2'))
        ->toThrow(DomainException::class, __('A final passed quality inspection is required before finished goods become available.'));
    $finalInspection = $cycle->recordInspection($run->fresh(), [
        'quality_inspection_type_id' => $finalInspectionType->getKey(),
        'sampled_at' => now()->addYear(),
        'result' => 'passed',
        'notes' => 'Final finished-goods release passed',
    ]);
    Storage::fake('public');
    Storage::disk('public')->put('production-quality/final-sample.jpg', 'quality-image');
    $finalInspection->update(['evidence' => [[
        'disk' => 'public',
        'path' => 'production-quality/final-sample.jpg',
        'original_name' => 'final-sample.jpg',
        'mime_type' => 'image/jpeg',
    ]]]);
    Permission::findOrCreate('production.quality.view', 'web');
    $fixture['user']->givePermissionTo('production.quality.view');
    $qualitySession = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    $this->actingAs($fixture['user'])->withSession($qualitySession)
        ->get(route('admin.production.quality.show', $finalInspection->getKey()))
        ->assertOk()
        ->assertSee('Final finished-goods release passed')
        ->assertSee('final-sample.jpg');
    $evidenceResponse = $this->actingAs($fixture['user'])->withSession($qualitySession)
        ->get(route('admin.production.quality.evidence', [$finalInspection->getKey(), 0]))
        ->assertOk();
    expect($evidenceResponse->streamedContent())->toBe('quality-image');
    $cycle->reviewInspection($finalInspection, true);

    $firstReceipt = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '2');
    $secondReceipt = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '2');
    expect($firstReceipt->document_type)->toBe(InventoryDocument::TypeProductionReceipt)
        ->and($firstReceipt->lines->first()->total_cost)->toBe('10.00000000')
        ->and($secondReceipt->lines->first()->total_cost)->toBe('10.00000000')
        ->and($secondReceipt->production_run_id)->toBe($run->getKey());

    $run = $cycle->completeRun($run->fresh());
    expect($run->status)->toBe(ProductionRun::StatusCompleted)
        ->and($run->received_base_quantity)->toBe('4.00000000')
        ->and($run->actual_start_at)->not->toBeNull()
        ->and($run->actual_end_at)->not->toBeNull()
        ->and($run->actualDurationMinutes())->not->toBeNull()
        ->and($run->order->fresh()->status)->toBe(ProductionOrder::StatusPartiallyCompleted);

    $rawPosition = $availability->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['raw']->getKey(),
    );
    $finishedPosition = $availability->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['finished']->getKey(),
    );
    expect($rawPosition['on_hand'])->toBe('989.00000000')
        ->and($rawPosition['physical_on_hand'])->toBe('989.00000000')
        ->and($finishedPosition['on_hand'])->toBe('4.00000000')
        ->and(InventoryTransaction::query()->where('production_run_id', $run->getKey())->count())->toBe(10);
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
    ))->keyBy('key');
    expect($reconciliation['wip']['difference'])->toBe('0.0000')
        ->and($reconciliation['finished_goods']['difference'])->toBe('0.0000')
        ->and($reconciliation['production_waste']['difference'])->toBe('0.0000');

    $secondRun = $cycle->createRun($run->orderLine->fresh(), [
        'planned_quantity' => '5',
        'planned_start_at' => now()->addHours(4),
        'planned_end_at' => now()->addHours(6),
        'production_machine_id' => $fixture['machine']->getKey(),
        'production_mold_id' => $fixture['mold']->getKey(),
    ]);
    $cycle->reserveRun($secondRun, $fixture['store']->getKey());
    $cycle->issueMaterials($secondRun, $fixture['store']->getKey());
    expect(fn () => $cycle->shortCloseOrder($secondRun->order->fresh(), 'Balance no longer required'))
        ->toThrow(DomainException::class, __('All issued materials must be returned before a run can be cancelled.'));
    $cycle->returnMaterials(
        $secondRun->fresh(),
        $fixture['store']->getKey(),
        [$secondRun->requirements->first()->getKey() => '10'],
    );

    $order = $cycle->shortCloseOrder($secondRun->order->fresh(), 'Balance no longer required');
    expect($order->status)->toBe(ProductionOrder::StatusShortClosed)
        ->and($order->short_close_reason)->toBe('Balance no longer required')
        ->and($secondRun->fresh()->status)->toBe(ProductionRun::StatusCancelled);
});

test('production quality runs the controlled request receive inspect review close and reinspection lifecycle', function (): void {
    $fixture = manufacturingInventoryFixture();
    $cycle = app(ProductionCycleService::class);
    $inspectionType = QualityInspectionType::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'code' => 'LINE-HOURLY',
        'name' => 'Hourly Line Sample',
        'name_ar' => 'عينة مراقبة الخط كل ساعة',
        'is_final_production' => false,
        'is_active' => true,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $checkpointId = DB::table('quality_checkpoints')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'company_id' => $fixture['company']->getKey(),
        'quality_inspection_type_id' => $inspectionType->getKey(),
        'code' => 'VISUAL-FINISH',
        'name' => 'Visual finish',
        'name_ar' => 'الفحص الظاهري',
        'sequence' => 10,
        'response_type' => 'pass_fail',
        'acceptance_criteria' => 'No deformation or incomplete injection',
        'is_required' => true,
        'is_active' => true,
        'created_by' => $fixture['user']->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '2',
    ]]);
    $line = $cycle->releaseOrder($order)->lines->firstOrFail();
    $run = $cycle->createRun($line, [
        'planned_quantity' => '2',
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(2),
        'production_machine_id' => $fixture['machine']->getKey(),
        'production_mold_id' => $fixture['mold']->getKey(),
    ]);
    $cycle->reserveRun($run, $fixture['store']->getKey());
    $cycle->issueMaterials($run->fresh(), $fixture['store']->getKey());
    $cycle->startSetup($run->fresh());
    $cycle->completeSetup($run->fresh());
    $run = $cycle->startRun($run->fresh());

    $permissions = [
        'production.quality.view', 'production.quality.create', 'production.quality.receive',
        'production.quality.start', 'production.quality.report', 'production.quality.submit', 'production.quality.review',
        'production.quality.release_normal', 'production.quality.close', 'production.quality.reinspect', 'production.runs.view', 'production.runs.qc', 'production.runs.labor',
        'maintenance.requests.create',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    Storage::fake('public');
    $operator = HrEmployee::query()->create([
        'doc_number' => 98001,
        'doc_num' => 'LABOR-98001',
        'full_name' => 'Operator One',
        'name' => 'Operator One',
        'person_type' => 'regular_labor',
        'status' => 'active',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'job_title' => 'Machine operator',
    ]);
    $qualityHelper = HrEmployee::query()->create([
        'doc_number' => 98002,
        'doc_num' => 'LABOR-98002',
        'full_name' => 'Quality Helper',
        'name' => 'Quality Helper',
        'person_type' => 'casual_labor',
        'status' => 'active',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'job_title' => 'Line helper',
    ]);

    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.production.runs.select2.workers', ['q' => 'LABOR-980']))
        ->assertOk()
        ->assertJsonCount(2, 'results')
        ->assertJsonFragment(['id' => $operator->doc_num, 'text' => $operator->doc_num.' — Operator One'])
        ->assertJsonFragment(['id' => $qualityHelper->doc_num, 'text' => $qualityHelper->doc_num.' — Quality Helper']);

    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.runs.labor', $run), [
            '_submission_token' => (string) Str::uuid(),
            'actual_labor_count' => 2,
            'labor_details' => [
                ['employee_id' => $operator->getKey(), 'planned_hours' => '8', 'actual_hours' => '7.5'],
                ['employee_id' => $qualityHelper->getKey(), 'planned_hours' => '8', 'actual_hours' => '6.25'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.actual_labor_count', 2)
        ->assertJsonPath('data.total_labor_hours', '13.75');

    $run->refresh();
    expect($run->actual_labor_count)->toBe(2)
        ->and($run->labor_details)->toHaveCount(2)
        ->and($run->totalLaborHours())->toBe('13.75')
        ->and($run->actualDurationMinutes())->not->toBeNull();

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.runs.show', $run))
        ->assertOk()
        ->assertSee('Operator One')
        ->assertSee('Machine operator')
        ->assertSee('13.75');

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.production.quality.store'), [
            '_submission_token' => (string) Str::uuid(),
            'production_run_id' => $run->getKey(),
            'quality_inspection_type_id' => $inspectionType->getKey(),
            'affected_base_quantity' => '1',
            'notes' => 'Hourly sample requested from production.',
        ])
        ->assertRedirect();

    $inspection = ProductionQualityInspection::query()->latest('id')->firstOrFail();
    expect($inspection->status)->toBe(ProductionQualityInspection::StatusDraft)
        ->and($inspection->requested_at)->not->toBeNull()
        ->and($inspection->received_at)->toBeNull()
        ->and(data_get($inspection->inspection_plan_snapshot, 'type.code'))->toBe('LINE-HOURLY')
        ->and(data_get($inspection->inspection_plan_snapshot, 'checkpoints.0.name'))->toBe('Visual finish')
        ->and(data_get($inspection->inspection_plan_snapshot, 'revision'))->not->toBeNull();
    DB::table('quality_checkpoints')->where('id', $checkpointId)->update(['name' => 'Changed after inspection creation']);

    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.receive', $inspection->getKey()))
        ->assertOk()
        ->assertJsonPath('status', ProductionQualityInspection::StatusReceived);
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.receive', $inspection->getKey()))
        ->assertUnprocessable();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.start', $inspection->getKey()))
        ->assertOk()
        ->assertJsonPath('status', ProductionQualityInspection::StatusInProgress);
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.quality.show', $inspection->getKey()))
        ->assertOk()
        ->assertSee('الفحص الظاهري')
        ->assertDontSee('Changed after inspection creation');

    foreach ([
        ['reported_at' => now()->subDay()->toDateTimeString(), 'result' => 'pending', 'observations' => 'First-day visual examination is still in progress.'],
        ['reported_at' => now()->toDateTimeString(), 'result' => 'failed', 'disposition' => 'hold', 'observations' => 'Second-day sample shows incomplete injection.'],
    ] as $index => $report) {
        $this->actingAs($fixture['user'])->withSession($session)
            ->post(route('admin.production.quality.reports.store', $inspection->getKey()), [
                '_submission_token' => (string) Str::uuid(),
                ...$report,
                'evidence_files' => [UploadedFile::fake()->image('quality-progress-'.$index.'.jpg')],
            ])
            ->assertRedirect(route('admin.production.quality.show', $inspection->getKey()));
    }
    expect($inspection->refresh()->status)->toBe(ProductionQualityInspection::StatusInProgress)
        ->and($inspection->reports)->toHaveCount(2)
        ->and($inspection->reports->last()->evidence)->toHaveCount(1);

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.production.quality.submit', $inspection->getKey()), [
            'result' => 'failed',
            'disposition' => 'rework',
            'defect_code' => 'INCOMPLETE-INJECTION',
            'affected_base_quantity' => '1',
            'corrective_action' => 'Adjust the machine and inspect a fresh sample.',
            'results' => [[
                'quality_checkpoint_id' => $checkpointId,
                'result' => 'failed',
                'notes' => 'Incomplete edge in the photographed sample.',
            ]],
            'evidence_files' => [UploadedFile::fake()->image('line-sample.jpg')],
        ])
        ->assertRedirect(route('admin.production.quality.show', $inspection->getKey()));

    $inspection->refresh();
    expect($inspection->status)->toBe(ProductionQualityInspection::StatusSubmitted)
        ->and($inspection->results)->toHaveCount(1)
        ->and($inspection->evidence)->toHaveCount(1)
        ->and($run->fresh()->status)->toBe(ProductionRun::StatusHeld);
    Storage::disk('public')->assertExists($inspection->evidence[0]['path']);

    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.maintenance-request', $inspection->getKey()))
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.maintenance-request', $inspection->getKey()))
        ->assertOk();
    $qualityMaintenanceRequest = MaintenanceRequest::query()->sole();
    expect($qualityMaintenanceRequest->quality_inspection_id)->toBe($inspection->getKey())
        ->and($qualityMaintenanceRequest->production_run_id)->toBe($run->getKey())
        ->and($qualityMaintenanceRequest->production_mold_id)->toBe($fixture['mold']->getKey());

    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.reject', $inspection->getKey()), ['reason' => 'Rework and resample are required.'])
        ->assertOk()
        ->assertJsonPath('status', ProductionQualityInspection::StatusRejected);
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.reinspect', $inspection->getKey()), productionSubmission())
        ->assertUnprocessable();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.close', $inspection->getKey()), ['close_notes' => 'Rejected sample closed after corrective action.'])
        ->assertOk()
        ->assertJsonPath('status', ProductionQualityInspection::StatusClosed);
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.reinspect', $inspection->getKey()), productionSubmission())
        ->assertOk()
        ->assertJsonPath('success', true);

    $reinspection = ProductionQualityInspection::query()->latest('id')->firstOrFail();
    expect($reinspection->parent_inspection_id)->toBe($inspection->getKey())
        ->and($reinspection->root_inspection_id)->toBe($inspection->getKey())
        ->and($reinspection->reinspection_number)->toBe(1)
        ->and($reinspection->status)->toBe(ProductionQualityInspection::StatusDraft);

    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.receive', $reinspection->getKey()))
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.start', $reinspection->getKey()))
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.production.quality.submit', $reinspection->getKey()), [
            'result' => 'passed',
            'disposition' => 'release',
            'results' => [[
                'quality_checkpoint_id' => $checkpointId,
                'result' => 'passed',
                'notes' => 'Corrective action verified.',
            ]],
        ])
        ->assertRedirect(route('admin.production.quality.show', $reinspection->getKey()));
    $reinspection->refresh();
    expect($reinspection->status)->toBe(ProductionQualityInspection::StatusApproved)
        ->and($reinspection->released_at)->not->toBeNull();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.approve', $reinspection->getKey()))
        ->assertUnprocessable();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.close', $reinspection->getKey()))
        ->assertOk()
        ->assertJsonPath('status', ProductionQualityInspection::StatusClosed);
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.maintenance-request', $reinspection->getKey()))
        ->assertUnprocessable();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.runs.resume', $run))
        ->assertOk();

    $reinspection->refresh();
    expect($reinspection->received_at)->not->toBeNull()
        ->and($reinspection->started_at)->not->toBeNull()
        ->and($reinspection->submitted_at)->not->toBeNull()
        ->and($reinspection->reviewed_at)->not->toBeNull()
        ->and($reinspection->approved_at)->not->toBeNull()
        ->and($reinspection->released_at)->not->toBeNull()
        ->and($reinspection->closed_at)->not->toBeNull()
        ->and($run->fresh()->status)->toBe(ProductionRun::StatusRunning);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.quality.show', $inspection->getKey()))
        ->assertOk()
        ->assertSee('line-sample.jpg')
        ->assertSee($inspection->doc_num)
        ->assertSee($reinspection->doc_num);
});

test('general quality can inspect warehouse stock across multiple days and be reinspected without a production run', function () {
    $fixture = manufacturingInventoryFixture();
    $permissions = ['production.quality.view', 'production.quality.create', 'production.quality.receive', 'production.quality.start', 'production.quality.report', 'production.quality.submit', 'production.quality.review', 'production.quality.close', 'production.quality.reinspect', 'production.quality.export', 'production.quality.print'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(), OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(), OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(), OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.quality.create'))
        ->assertOk()
        ->assertSee('js-select2-ajax', false)
        ->assertDontSee($fixture['raw']->name);
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.production.quality.select2', ['lookup' => 'products', 'q' => $fixture['raw']->doc_num]))
        ->assertOk()
        ->assertJsonPath('results.0.id', (string) $fixture['raw']->getKey());

    $this->actingAs($fixture['user'])->withSession($session)->post(route('admin.production.quality.store'), [
        '_submission_token' => (string) Str::uuid(),
        'subject_type' => ProductionQualityInspection::SubjectInventoryStock,
        'product_id' => $fixture['raw']->getKey(), 'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable, 'affected_base_quantity' => 100,
    ])->assertRedirect();
    $inspection = ProductionQualityInspection::query()->sole();
    expect($inspection->production_run_id)->toBeNull()->and($inspection->product_id)->toBe($fixture['raw']->getKey());

    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.receive', $inspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.start', $inspection))->assertOk();
    foreach ([now()->subDays(2), now()->subDay(), now()] as $index => $reportedAt) {
        $this->actingAs($fixture['user'])->withSession($session)->post(route('admin.production.quality.reports.store', $inspection), [
            '_submission_token' => (string) Str::uuid(),
            'reported_at' => $reportedAt->toDateTimeString(), 'result' => $index === 2 ? 'passed' : 'pending',
            'observations' => 'Warehouse stock inspection progress '.($index + 1),
        ])->assertRedirect(route('admin.production.quality.show', $inspection));
    }
    expect($inspection->refresh()->status)->toBe(ProductionQualityInspection::StatusInProgress)->and($inspection->reports)->toHaveCount(3);
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.production.quality.active'))->assertOk()->assertSee('data-server-table', false);
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.production.quality.reports.index'))->assertOk()->assertSee('data-server-table', false);
    $this->actingAs($fixture['user'])->withSession($session)->getJson(route('admin.production.quality.reports.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk()->assertJsonPath('recordsFiltered', 3);
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.quality.export', ['subject_type' => ProductionQualityInspection::SubjectInventoryStock, 'product_id' => $fixture['raw']->getKey()]))
        ->assertOk()
        ->assertHeader('Content-Disposition');
    $qualityReportPdf = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.quality.print', ['subject_type' => ProductionQualityInspection::SubjectInventoryStock, 'product_id' => $fixture['raw']->getKey()]));
    $qualityReportPdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($qualityReportPdf->getContent(), '%PDF-'))->toBeTrue();
    $this->actingAs($fixture['user'])->withSession($session)->post(route('admin.production.quality.submit', $inspection), ['result' => 'passed', 'disposition' => 'release'])->assertRedirect();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.approve', $inspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.close', $inspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.reinspect', $inspection), productionSubmission())->assertOk();
    expect(ProductionQualityInspection::query()->latest('id')->firstOrFail()->subject_type)->toBe(ProductionQualityInspection::SubjectInventoryStock);
});

test('warehouse quality hold moves one exact stock quantity once and releases it only after approved reinspection', function (): void {
    $fixture = manufacturingInventoryFixture();
    $permissions = [
        'production.quality.view', 'production.quality.create', 'production.quality.receive',
        'production.quality.start', 'production.quality.submit', 'production.quality.review',
        'production.quality.close', 'production.quality.reinspect',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    $balance = function (string $status) use ($fixture): string {
        $value = InventoryTransaction::query()
            ->where('company_id', $fixture['company']->getKey())
            ->where('branch_store_id', $fixture['store']->getKey())
            ->where('product_id', $fixture['raw']->getKey())
            ->where('stock_status', $status)
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as balance')
            ->value('balance');

        return bcadd((string) $value, '0', 8);
    };

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.production.quality.store'), [
            '_submission_token' => (string) Str::uuid(),
            'subject_type' => ProductionQualityInspection::SubjectInventoryStock,
            'product_id' => $fixture['raw']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'stock_status' => InventoryTransaction::StatusAvailable,
            'affected_base_quantity' => '12',
        ])
        ->assertRedirect();
    $inspection = ProductionQualityInspection::query()->sole();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.receive', $inspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.start', $inspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.production.quality.submit', $inspection), [
            'result' => 'failed',
            'disposition' => 'hold',
            'defect_code' => 'STORAGE-CHECK',
            'affected_base_quantity' => '12',
        ])
        ->assertRedirect(route('admin.production.quality.show', $inspection));

    $hold = QualityStockHold::query()->sole();
    expect($hold->status)->toBe(QualityStockHold::StatusActive)
        ->and($hold->holdInventoryDocument?->status)->toBe(InventoryDocument::StatusPosted)
        ->and($balance(InventoryTransaction::StatusAvailable))->toBe('988.00000000')
        ->and($balance(InventoryTransaction::StatusQcHold))->toBe('12.00000000');
    expect(fn () => app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'destination_branch_store_id' => $fixture['store']->getKey(),
        'document_type' => InventoryDocument::TypeTransfer,
        'document_date' => now()->toDateString(),
        'movement_reason' => 'Attempted manual QC release',
        'source_stock_status' => InventoryTransaction::StatusQcHold,
        'destination_stock_status' => InventoryTransaction::StatusAvailable,
    ], [[
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '1',
    ]]))->toThrow(DomainException::class, __('production_execution.messages.quality_hold_movement_controlled'));
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($hold->holdInventoryDocument))
        ->toThrow(DomainException::class, __('production_execution.messages.quality_inventory_document_controlled'));

    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.approve', $inspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.close', $inspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.reinspect', $inspection), productionSubmission())->assertOk();
    $reinspection = ProductionQualityInspection::query()->whereKeyNot($inspection->getKey())->sole();
    expect($reinspection->stock_status)->toBe(InventoryTransaction::StatusQcHold);

    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.receive', $reinspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.start', $reinspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.production.quality.submit', $reinspection), ['result' => 'passed', 'disposition' => 'release'])
        ->assertRedirect(route('admin.production.quality.show', $reinspection));
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.approve', $reinspection))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.production.quality.approve', $reinspection))->assertUnprocessable();

    expect($hold->refresh()->status)->toBe(QualityStockHold::StatusReleased)
        ->and($hold->dispositionInventoryDocument?->status)->toBe(InventoryDocument::StatusPosted)
        ->and(QualityStockHold::query()->count())->toBe(1)
        ->and($balance(InventoryTransaction::StatusAvailable))->toBe('1000.00000000')
        ->and($balance(InventoryTransaction::StatusQcHold))->toBe('0.00000000');
});

test('warehouse quality draft crud keeps the stock hold synchronized', function (): void {
    $fixture = manufacturingInventoryFixture();
    $permissions = [
        'production.quality.view', 'production.quality.create', 'production.quality.edit',
        'production.quality.delete', 'production.quality.view_trashed', 'production.quality.restore',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    $balance = function (string $status) use ($fixture): string {
        $value = InventoryTransaction::query()
            ->where('company_id', $fixture['company']->getKey())
            ->where('branch_store_id', $fixture['store']->getKey())
            ->where('product_id', $fixture['raw']->getKey())
            ->where('stock_status', $status)
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as balance')
            ->value('balance');

        return bcadd((string) $value, '0', 8);
    };
    $payload = [
        'subject_type' => ProductionQualityInspection::SubjectInventoryStock,
        'product_id' => $fixture['raw']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'affected_base_quantity' => '12',
        'notes' => 'Warehouse quality sample',
    ];

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.production.quality.store'), productionSubmission($payload))
        ->assertRedirect(route('admin.production.quality.index'));
    $inspection = ProductionQualityInspection::query()->sole();
    expect($inspection->stockHold?->status)->toBe(QualityStockHold::StatusActive)
        ->and($balance(InventoryTransaction::StatusAvailable))->toBe('988.00000000')
        ->and($balance(InventoryTransaction::StatusQcHold))->toBe('12.00000000');

    $this->actingAs($fixture['user'])->withSession($session)
        ->put(route('admin.production.quality.update', $inspection), [
            ...$payload,
            'affected_base_quantity' => '20',
            'submit_action' => 'save_view',
        ])
        ->assertRedirect(route('admin.production.quality.show', $inspection));
    expect($inspection->fresh()->stockHold?->base_quantity)->toBe('20.00000000')
        ->and($balance(InventoryTransaction::StatusAvailable))->toBe('980.00000000')
        ->and($balance(InventoryTransaction::StatusQcHold))->toBe('20.00000000');

    $this->actingAs($fixture['user'])->withSession($session)
        ->deleteJson(route('admin.production.quality.destroy', $inspection))
        ->assertOk();
    expect($inspection->fresh()->trashed())->toBeTrue()
        ->and($balance(InventoryTransaction::StatusAvailable))->toBe('1000.00000000')
        ->and($balance(InventoryTransaction::StatusQcHold))->toBe('0.00000000');

    $this->actingAs($fixture['user'])->withSession($session)
        ->patchJson(route('admin.production.quality.restore', $inspection->getKey()))
        ->assertOk();
    expect($inspection->fresh()->trashed())->toBeFalse()
        ->and($inspection->fresh()->stockHold?->status)->toBe(QualityStockHold::StatusActive)
        ->and($balance(InventoryTransaction::StatusAvailable))->toBe('980.00000000')
        ->and($balance(InventoryTransaction::StatusQcHold))->toBe('20.00000000');
});

test('maintenance draft documents can be edited soft deleted and restored in their branch context', function (): void {
    $fixture = manufacturingInventoryFixture();
    $asset = FixedAsset::query()->create([
        'doc_number' => 9911,
        'doc_num' => 'FA-MAINT-CRUD-9911',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'period_id' => $fixture['period']->getKey(),
        'account_id' => manufacturingMaintenanceAssetAccount($fixture)->getKey(),
        'asset_date' => now()->toDateString(),
        'asset_name' => 'Maintenance CRUD Asset',
        'status' => FixedAsset::StatusActive,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $permissions = [
        'maintenance.requests.create', 'maintenance.requests.edit', 'maintenance.requests.delete', 'maintenance.requests.restore',
        'maintenance.orders.create', 'maintenance.orders.edit', 'maintenance.orders.delete', 'maintenance.orders.restore',
        'maintenance.material_requests.create', 'maintenance.material_requests.edit', 'maintenance.material_requests.delete', 'maintenance.material_requests.restore',
        'maintenance.expenses.create', 'maintenance.expenses.edit', 'maintenance.expenses.delete', 'maintenance.expenses.restore',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];

    $requestPayload = [
        'maintainable_key' => 'asset:'.$asset->getKey(),
        'request_type' => 'breakdown',
        'discipline' => 'mechanical',
        'priority' => 'normal',
        'symptoms' => 'Original vibration report.',
    ];
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.requests.store'), $requestPayload)
        ->assertRedirect(route('admin.maintenance.requests.index'));
    $maintenanceRequest = MaintenanceRequest::query()->sole();
    $this->actingAs($fixture['user'])->withSession($session)
        ->put(route('admin.maintenance.requests.update', $maintenanceRequest), [...$requestPayload, 'symptoms' => 'Updated vibration report.'])
        ->assertRedirect(route('admin.maintenance.requests.index'));
    expect($maintenanceRequest->refresh()->symptoms)->toBe('Updated vibration report.');
    $this->actingAs($fixture['user'])->withSession($session)
        ->deleteJson(route('admin.maintenance.requests.destroy', $maintenanceRequest))
        ->assertOk();
    $this->assertSoftDeleted('maintenance_requests', ['id' => $maintenanceRequest->getKey()]);
    $this->actingAs($fixture['user'])->withSession($session)
        ->patchJson(route('admin.maintenance.requests.restore', $maintenanceRequest->doc_num))
        ->assertOk();
    $this->assertDatabaseHas('maintenance_requests', ['id' => $maintenanceRequest->getKey(), 'deleted_at' => null]);

    $orderPayload = [
        'maintainable_key' => 'asset:'.$asset->getKey(),
        'maintenance_type' => 'corrective',
        'discipline' => 'mechanical',
        'priority' => 'normal',
        'service_mode' => 'internal',
        'work_description' => 'Original corrective work.',
    ];
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.store'), $orderPayload)
        ->assertRedirect(route('admin.maintenance.orders.index'));
    $order = MaintenanceWorkOrder::query()->sole();
    $this->actingAs($fixture['user'])->withSession($session)
        ->put(route('admin.maintenance.orders.update', $order), [...$orderPayload, 'work_description' => 'Updated corrective work.'])
        ->assertRedirect(route('admin.maintenance.orders.index'));
    expect($order->refresh()->work_description)->toBe('Updated corrective work.');
    $this->actingAs($fixture['user'])->withSession($session)
        ->deleteJson(route('admin.maintenance.orders.destroy', $order))
        ->assertOk();
    $this->assertSoftDeleted('maintenance_work_orders', ['id' => $order->getKey()]);
    $this->actingAs($fixture['user'])->withSession($session)
        ->patchJson(route('admin.maintenance.orders.restore', $order->doc_num))
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.material-requests.create'))
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.expenses.create'))
        ->assertOk();

    $materialPayload = [
        'maintenance_work_order_id' => $order->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'reason' => 'Original material reason.',
        'lines' => [['product_id' => $fixture['raw']->getKey(), 'item_type' => 'spare_part', 'quantity' => '2']],
    ];
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.material-requests.store'), $materialPayload)
        ->assertRedirect(route('admin.maintenance.material-requests.index'));
    $materialRequest = MaintenanceMaterialRequest::query()->sole();
    $this->actingAs($fixture['user'])->withSession($session)
        ->put(route('admin.maintenance.material-requests.update', $materialRequest), [...$materialPayload, 'reason' => 'Updated material reason.', 'lines' => [['product_id' => $fixture['raw']->getKey(), 'item_type' => 'oil', 'quantity' => '3']]])
        ->assertRedirect(route('admin.maintenance.material-requests.index'));
    expect($materialRequest->refresh()->reason)->toBe('Updated material reason.')
        ->and($materialRequest->lines()->sole()->requested_quantity)->toBe('3.00000000')
        ->and($materialRequest->lines()->sole()->unit_id)->toBe($fixture['raw']->item_unit_id);
    $this->actingAs($fixture['user'])->withSession($session)
        ->deleteJson(route('admin.maintenance.material-requests.destroy', $materialRequest))
        ->assertOk();
    $this->assertSoftDeleted('maintenance_material_requests', ['id' => $materialRequest->getKey()]);
    $this->actingAs($fixture['user'])->withSession($session)
        ->patchJson(route('admin.maintenance.material-requests.restore', $materialRequest->doc_num))
        ->assertOk();

    $currency = Currency::query()->where('company_id', $fixture['company']->getKey())->firstOrFail();
    $cashAccount = Account::query()->where('company_id', $fixture['company']->getKey())->where('is_postable', true)->where('account_type', Account::TypeAsset)->firstOrFail();
    $expenseAccount = Account::query()->where('company_id', $fixture['company']->getKey())->where('is_postable', true)->where('account_type', Account::TypeExpense)->firstOrFail();
    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'account_id' => $cashAccount->getKey(),
        'name' => 'Maintenance CRUD Cashbox',
        'status' => 'active',
    ]);
    CashboxCurrency::query()->create(['cashbox_id' => $cashbox->getKey(), 'currency_id' => $currency->getKey(), 'is_default' => true, 'status' => 'active']);
    $expensePayload = [
        'maintenance_work_order_id' => $order->getKey(),
        'amount' => '75',
        'currency_id' => $currency->getKey(),
        'payment_channel' => 'cashbox',
        'cashbox_id' => $cashbox->getKey(),
        'expense_account_id' => $expenseAccount->getKey(),
        'reason' => 'Original expense reason.',
    ];
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.expenses.store'), $expensePayload)
        ->assertRedirect(route('admin.maintenance.expenses.index'));
    $expense = ProductionExpenseRequest::query()->whereNotNull('maintenance_work_order_id')->sole();
    $this->actingAs($fixture['user'])->withSession($session)
        ->put(route('admin.maintenance.expenses.update', $expense), [...$expensePayload, 'amount' => '90', 'reason' => 'Updated expense reason.'])
        ->assertRedirect(route('admin.maintenance.expenses.index'));
    expect($expense->refresh()->amount)->toBe('90.0000')
        ->and($expense->reason)->toBe('Updated expense reason.');
    $this->actingAs($fixture['user'])->withSession($session)
        ->deleteJson(route('admin.maintenance.expenses.destroy', $expense))
        ->assertOk();
    $this->assertSoftDeleted('production_expense_requests', ['id' => $expense->getKey()]);
    $this->actingAs($fixture['user'])->withSession($session)
        ->patchJson(route('admin.maintenance.expenses.restore', $expense->doc_num))
        ->assertOk();

    $this->assertDatabaseHas('maintenance_work_orders', ['id' => $order->getKey(), 'deleted_at' => null]);
    $this->assertDatabaseHas('maintenance_material_requests', ['id' => $materialRequest->getKey(), 'deleted_at' => null]);
    $this->assertDatabaseHas('production_expense_requests', ['id' => $expense->getKey(), 'deleted_at' => null]);
});

test('available material can be split between sales production orders and a shortage is reserved after replenishment', function () {
    $fixture = manufacturingInventoryFixture();
    $fixture['branch']->update(['type' => Branch::TypeFactory]);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put([
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ]);

    InventoryTransaction::query()->create([
        'posting_key' => 'make-to-order-does-not-use-finished-stock',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'opening_stock',
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => '1000',
        'quantity_out' => 0,
        'source_type' => 'test',
        'source_id' => 15000,
        'source_doc_num' => 'FG-OPEN-ALLOCATE-1000',
        'unit_cost' => '1',
        'total_cost' => '1000',
        'created_by' => $fixture['user']->getKey(),
    ]);

    InventoryTransaction::query()->create([
        'posting_key' => 'allocate-existing-raw-balance',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'test_allocation',
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => 0,
        'quantity_out' => '900',
        'source_type' => 'test',
        'source_id' => 15001,
        'source_doc_num' => 'ALLOCATE-100',
        'unit_cost' => '2',
        'total_cost' => '1800',
        'created_by' => $fixture['user']->getKey(),
    ]);

    $currency = Currency::query()->where('company_id', $fixture['company']->getKey())->firstOrFail();
    $demand = app(SalesProductionDemandService::class);
    $cycle = app(ProductionCycleService::class);
    $materialRequests = app(ProductionMaterialRequestService::class);
    $buildRun = function (int $number, string $finishedQuantity) use ($fixture, $currency, $demand, $cycle): ProductionRun {
        $customer = Customer::query()->create([
            'company_id' => $fixture['company']->getKey(),
            'doc_number' => $number,
            'doc_num' => "CUSTOMER-ALLOCATE-{$number}",
            'name' => "Allocation Customer {$number}",
            'status' => 'active',
        ]);
        $salesOrder = SalesOrder::query()->create([
            'doc_number' => $number,
            'doc_num' => "SO-ALLOCATE-{$number}",
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'customer_id' => $customer->getKey(),
            'currency_id' => $currency->getKey(),
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addWeek()->toDateString(),
            'status' => SalesOrder::StatusApproved,
            'credit_status' => 'approved',
            'subtotal_amount' => $finishedQuantity,
            'total_amount' => $finishedQuantity,
            'created_by' => $fixture['user']->getKey(),
        ]);
        $salesLine = $salesOrder->lines()->create([
            'line_number' => 1,
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => $fixture['finished']->name,
            'quantity' => $finishedQuantity,
            'unit_price' => '1',
            'line_total' => $finishedQuantity,
            'product_classification_snapshot' => Product::ClassificationFinishedProduct,
            'conversion_factor' => '1',
            'base_quantity' => $finishedQuantity,
        ]);
        $productionOrder = $demand->create($salesOrder->refresh(), [[
            'sales_order_line_id' => $salesLine->getKey(),
            'quantity' => $finishedQuantity,
        ]]);
        $productionLine = $cycle->releaseOrder($productionOrder)->lines->firstOrFail();

        return $cycle->createRun($productionLine, [
            'planned_quantity' => $finishedQuantity,
            'planned_start_at' => now()->addMinutes($number),
            'planned_end_at' => now()->addMinutes($number + 30),
        ]);
    };

    $olderOrderRun = $buildRun(15001, '50');
    $newerOrderRun = $buildRun(15002, '25');
    $olderRequirement = $olderOrderRun->requirements->firstOrFail();
    $newerRequirement = $newerOrderRun->requirements->firstOrFail();

    $olderAllocation = $materialRequests->create($olderOrderRun, $fixture['store']->getKey(), [$olderRequirement->getKey() => '50']);
    $newerAllocation = $materialRequests->create($newerOrderRun, $fixture['store']->getKey(), [$newerRequirement->getKey() => '50']);
    $materialRequests->approve($olderAllocation);
    $materialRequests->approve($newerAllocation);
    $shortageRequest = $materialRequests->create($olderOrderRun, $fixture['store']->getKey(), [$olderRequirement->getKey() => '50']);
    $shortageRequest = $materialRequests->approve($shortageRequest);

    expect($shortageRequest->status)->toBe('shortage')
        ->and($shortageRequest->lines->first()->reserved_quantity)->toBe('0.00000000')
        ->and($shortageRequest->lines->first()->shortage_quantity)->toBe('50.00000000')
        ->and($shortageRequest->purchaseRequisition)->not->toBeNull()
        ->and($shortageRequest->purchaseRequisition->lines->first()->requested_quantity)->toBe('50.00000000');

    $olderRequestLine = $olderAllocation->lines->firstOrFail();
    $olderIssue = $materialRequests->issue($olderAllocation->fresh(), [$olderRequestLine->getKey() => '20']);
    expect($olderAllocation->fresh()->status)->toBe('partially_issued')
        ->and($olderAllocation->fresh()->lines->firstOrFail()->issued_quantity)->toBe('20.00000000');
    $materialRequests->issue($olderAllocation->fresh());
    $materialRequests->issue($newerAllocation->fresh());
    expect($olderIssue->production_material_request_id)->toBe($olderAllocation->getKey())
        ->and($olderIssue->lines->firstOrFail()->production_material_request_line_id)->toBe($olderRequestLine->getKey());
    InventoryTransaction::query()->create([
        'posting_key' => 'replenish-production-shortage',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'purchase_receipt_test',
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity_in' => '50',
        'quantity_out' => 0,
        'source_type' => 'purchase_receipt',
        'source_id' => 15003,
        'source_doc_num' => 'GRN-ALLOCATE-50',
        'unit_cost' => '2',
        'total_cost' => '100',
        'created_by' => $fixture['user']->getKey(),
    ]);

    $shortageRequest = $materialRequests->allocateShortage($shortageRequest->fresh());
    expect($shortageRequest->status)->toBe('approved')
        ->and($shortageRequest->lines->first()->reserved_quantity)->toBe('50.00000000')
        ->and($shortageRequest->lines->first()->shortage_quantity)->toBe('0.00000000');

    $lastIssue = $materialRequests->issue($shortageRequest->fresh());
    expect($shortageRequest->fresh()->status)->toBe('issued')
        ->and($olderRequirement->fresh()->issued_quantity)->toBe('100.00000000')
        ->and($newerRequirement->fresh()->issued_quantity)->toBe('50.00000000')
        ->and($lastIssue->journalEntry)->not->toBeNull()
        ->and((string) $lastIssue->journalEntry->lines()->sum('debit_amount'))->toBe((string) $lastIssue->journalEntry->lines()->sum('credit_amount'));
});

test('short-closing a linked production order releases only its unproduced sales demand', function (): void {
    $fixture = manufacturingInventoryFixture();
    $fixture['branch']->update(['type' => Branch::TypeFactory]);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put([
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ]);

    $currency = Currency::query()->where('company_id', $fixture['company']->getKey())->firstOrFail();
    $customer = Customer::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 15010,
        'doc_num' => 'CUSTOMER-SHORT-CLOSE-DEMAND',
        'name' => 'Short Close Demand Customer',
        'status' => 'active',
    ]);
    $salesOrder = SalesOrder::query()->create([
        'doc_number' => 15010,
        'doc_num' => 'SO-SHORT-CLOSE-DEMAND',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'customer_id' => $customer->getKey(),
        'currency_id' => $currency->getKey(),
        'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addWeek()->toDateString(),
        'status' => SalesOrder::StatusApproved,
        'credit_status' => 'approved',
        'subtotal_amount' => '100',
        'total_amount' => '100',
        'created_by' => $fixture['user']->getKey(),
    ]);
    $salesLine = $salesOrder->lines()->create([
        'line_number' => 1,
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'description' => $fixture['finished']->name,
        'quantity' => '100',
        'unit_price' => '1',
        'line_total' => '100',
        'product_classification_snapshot' => Product::ClassificationFinishedProduct,
        'conversion_factor' => '1',
        'base_quantity' => '100',
    ]);
    $demand = app(SalesProductionDemandService::class);
    $production = $demand->create($salesOrder, [['sales_order_line_id' => $salesLine->getKey(), 'quantity' => '100']]);

    app(ProductionCycleService::class)->shortCloseOrder($production, 'Production quantity was reduced');

    expect($salesLine->fresh()->production_requested_quantity)->toBe('0.00000000')
        ->and($salesLine->fresh()->remainingProductionDemandBaseQuantity())->toBe('100.00000000')
        ->and($demand->create($salesOrder->fresh(), [['sales_order_line_id' => $salesLine->getKey(), 'quantity' => '100']])->lines->first()->quantity)
        ->toBe('100.00000000');
});

test('one sales line can be split across production orders without duplicating its demand', function (): void {
    $fixture = manufacturingInventoryFixture();
    $currency = Currency::query()->where('company_id', $fixture['company']->getKey())->firstOrFail();
    $customer = Customer::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 18001,
        'doc_num' => 'CUSTOMER-SPLIT-DEMAND',
        'name' => 'Split Demand Customer',
        'status' => 'active',
    ]);
    $salesOrder = SalesOrder::query()->create([
        'doc_number' => 18001,
        'doc_num' => 'SO-SPLIT-DEMAND',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'customer_id' => $customer->getKey(),
        'currency_id' => $currency->getKey(),
        'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addWeek()->toDateString(),
        'status' => SalesOrder::StatusApproved,
        'credit_status' => 'approved',
        'subtotal_amount' => '100',
        'total_amount' => '100',
        'created_by' => $fixture['user']->getKey(),
    ]);
    $salesLine = $salesOrder->lines()->create([
        'line_number' => 1,
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'description' => $fixture['finished']->name,
        'quantity' => '100',
        'unit_price' => '1',
        'line_total' => '100',
        'product_classification_snapshot' => Product::ClassificationFinishedProduct,
        'conversion_factor' => '1',
        'base_quantity' => '100',
    ]);
    $service = app(SalesProductionDemandService::class);

    $first = $service->create($salesOrder->refresh(), [['sales_order_line_id' => $salesLine->getKey(), 'quantity' => '40']]);
    $second = $service->create($salesOrder->refresh(), [['sales_order_line_id' => $salesLine->getKey(), 'quantity' => '60']]);

    expect($first->getKey())->not->toBe($second->getKey())
        ->and(ProductionOrder::query()->where('sales_order_id', $salesOrder->getKey())->count())->toBe(2)
        ->and($salesLine->fresh()->production_requested_quantity)->toBe('100.00000000')
        ->and(fn () => $service->create($salesOrder->refresh(), [['sales_order_line_id' => $salesLine->getKey(), 'quantity' => '1']]))
        ->toThrow(DomainException::class);
});

test('inventory status transfers remain physically balanced and reject negative positions', function () {
    $fixture = manufacturingInventoryFixture();
    $movement = app(InventoryMovementService::class);
    $availability = app(InventoryAvailabilityService::class);
    $context = [
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'destination_branch_store_id' => $fixture['store']->getKey(),
        'document_type' => InventoryDocument::TypeDamage,
        'document_date' => now()->toDateString(),
        'movement_reason' => 'Damaged resin bag',
    ];

    $damageDocument = $movement->createAndPost($context, [['product_id' => $fixture['raw']->getKey(), 'quantity' => '5']]);
    $statuses = $availability->statusPosition($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['raw']->getKey());
    expect($statuses[InventoryTransaction::StatusAvailable])->toBe('995.00000000')
        ->and($statuses[InventoryTransaction::StatusDamaged])->toBe('5.00000000')
        ->and(array_sum(array_map('floatval', $statuses)))->toEqual(1000.0);

    expect(fn () => $movement->createAndPost($context, [['product_id' => $fixture['raw']->getKey(), 'quantity' => '996']]))
        ->toThrow(DomainException::class, explode(':document', __('The inventory movement exceeds unreserved stock in the selected store, location, batch, and status. Document: :document; product ID: :product_id; requested: :requested; available: :available; store ID: :store_id; status: :status.'))[0]);

    $stockCounts = app(StockCountService::class);
    $count = $stockCounts->createSnapshot([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'product_ids' => [$fixture['raw']->getKey()],
    ]);
    $countLine = $count->lines->first();
    expect($countLine->system_quantity)->toBe('995.00000000');
    $stockCounts->recordCount($count, [
        $countLine->getKey() => ['physical_quantity' => '994', 'variance_reason' => 'Verified bag shortage'],
    ]);
    $adjustments = $stockCounts->approve($count->fresh());
    expect($adjustments)->toHaveCount(1)
        ->and($adjustments[0]->document_type)->toBe(InventoryDocument::TypeAdjustmentOut)
        ->and($availability->forProduct(
            $fixture['company']->getKey(),
            $fixture['store']->getKey(),
            $fixture['raw']->getKey(),
        )['on_hand'])->toBe('994.00000000');

    $zeroBalanceCount = $stockCounts->createSnapshot([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'product_ids' => [$fixture['finished']->getKey()],
    ]);
    expect($zeroBalanceCount->lines)->toHaveCount(1)
        ->and($zeroBalanceCount->lines->first()->system_quantity)->toBe('0.00000000');

    $staleCount = $stockCounts->createSnapshot([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'stock_status' => InventoryTransaction::StatusAvailable,
        'product_ids' => [$fixture['raw']->getKey()],
    ]);
    $staleLine = $staleCount->lines->first();
    $stockCounts->recordCount($staleCount, [
        $staleLine->getKey() => ['physical_quantity' => '994'],
    ]);
    $movement->createAndPost([
        ...$context,
        'document_type' => InventoryDocument::TypeAdjustmentIn,
    ], [['product_id' => $fixture['raw']->getKey(), 'quantity' => '1']]);
    expect(fn () => $stockCounts->approve($staleCount->fresh()))
        ->toThrow(DomainException::class, __('Stock changed after the count snapshot. Create a new count before approval.'));

    $documentCount = InventoryDocument::query()->count();
    $transactionCount = InventoryTransaction::query()->count();
    $fixture['period']->update(['is_closed' => true]);
    expect(fn () => $movement->createAndPost([
        ...$context,
        'document_type' => InventoryDocument::TypeAdjustmentIn,
    ], [['product_id' => $fixture['raw']->getKey(), 'quantity' => '1']]))
        ->toThrow(DomainException::class, __('Inventory movements cannot be posted to a closed or unrelated financial period.'));
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($damageDocument))
        ->toThrow(DomainException::class, __('Inventory movements cannot be reversed in a closed or unrelated financial period.'));
    expect(InventoryDocument::query()->count())->toBe($documentCount)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount);
});

test('stock count web workflow saves master detail lines and approves the count', function () {
    $fixture = manufacturingInventoryFixture();
    $permissions = [
        'inventory.stock_counts.view',
        'inventory.stock_counts.create',
        'inventory.stock_counts.edit',
        'inventory.stock_counts.approve',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $fixture['user']->givePermissionTo($permissions);
    $session = [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];

    $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.inventory.stock-counts.index'))
        ->assertOk()
        ->assertSee('js-stock-counts-table', false)
        ->assertSee(route('admin.inventory.stock-counts.create'), false);

    $this->actingAs($fixture['user'])
        ->withSession($session)
        ->postJson(route('admin.inventory.stock-counts.store'), [
            'branch_store_id' => $fixture['store']->getKey(),
            'count_date' => now()->toDateString(),
            'submit_action' => 'save_view',
            'lines' => [
                [
                    'product_doc_num' => $fixture['raw']->doc_num,
                    'stock_status' => InventoryTransaction::StatusAvailable,
                    'physical_quantity' => '1000',
                ],
                [
                    'product_doc_num' => $fixture['finished']->doc_num,
                    'stock_status' => InventoryTransaction::StatusAvailable,
                    'physical_quantity' => '0',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);
    $count = StockCount::query()->with('lines')->sole();
    expect($count->status)->toBe(StockCount::StatusCounted)
        ->and($count->lines)->toHaveCount(2)
        ->and($count->lines->pluck('system_quantity')->all())->toBe(['1000.00000000', '0.00000000']);

    $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.inventory.stock-counts.show', $count))
        ->assertOk()
        ->assertSee('js-stock-count-form', false)
        ->assertSee('Approve Variances');

    $this->actingAs($fixture['user'])
        ->withSession($session)
        ->postJson(route('admin.inventory.stock-counts.approve', $count))
        ->assertOk()
        ->assertJsonPath('success', true);
    $approvedCount = $count->fresh();
    expect($approvedCount->status)->toBe(StockCount::StatusApproved)
        ->and($approvedCount->approved_by)->toBe($fixture['user']->getKey());
});

test('canonical inventory and production pages use real routes and keep html operators in the workflow', function () {
    $fixture = manufacturingInventoryFixture();
    $permissions = [
        'inventory.documents.view',
        'inventory.documents.create',
        'inventory.documents.adjust',
        'inventory.documents.print',
        'production.runs.view',
        'production.runs.issue',
        'production.runs.print',
        'production.orders.print',
        'production.material_requests.create',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $fixture['user']->givePermissionTo($permissions);
    $session = [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];

    $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.inventory.documents.create'))
        ->assertOk()
        ->assertSee('New Inventory Movement')
        ->assertSee('js-select2-ajax', false)
        ->assertSee('js-date-picker', false);

    $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.inventory.documents.index'))
        ->assertOk()
        ->assertSee('data-server-table', false)
        ->assertSee(route('admin.inventory.documents.data'), false);

    $response = $this->actingAs($fixture['user'])
        ->withSession($session)
        ->post(route('admin.inventory.documents.store'), [
            'branch_store_id' => $fixture['store']->getKey(),
            'document_type' => InventoryDocument::TypeAdjustmentIn,
            'document_date' => now()->toDateString(),
            'movement_reason' => 'HTML operator verification',
            'destination_stock_status' => InventoryTransaction::StatusAvailable,
            'submit_action' => 'post_and_view',
            'lines' => [[
                'product_id' => $fixture['raw']->getKey(),
                'quantity' => '1',
                'unit_cost' => '2',
            ]],
        ]);
    $document = InventoryDocument::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('admin.inventory.documents.show', $document));
    $inventoryPdf = $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.inventory.documents.print', $document));
    $inventoryPdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($inventoryPdf->headers->get('Content-Disposition'))->toContain('inline')
        ->and(str_starts_with($inventoryPdf->getContent(), '%PDF-'))->toBeTrue();

    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '2',
    ]]);
    $line = $cycle->releaseOrder($order)->lines->first();
    $run = $cycle->createRun($line, [
        'planned_quantity' => '2',
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(2),
        'production_machine_id' => $fixture['machine']->getKey(),
        'production_mold_id' => $fixture['mold']->getKey(),
    ]);

    $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.production.runs.show', $run))
        ->assertOk()
        ->assertSee('Additional Material Issue')
        ->assertSee('Unused Material Return')
        ->assertDontSee('In-Process Quality Sample')
        ->assertDontSee('ERP UI Shell');
    $runPdf = $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.production.runs.print', $run));
    $runPdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($runPdf->headers->get('Content-Disposition'))->toContain('inline')
        ->and(str_starts_with($runPdf->getContent(), '%PDF-'))->toBeTrue();
    foreach (['materials.print', 'quality.print', 'completion.print'] as $printRoute) {
        $formalPdf = $this->actingAs($fixture['user'])
            ->withSession($session)
            ->get(route('admin.production.runs.'.$printRoute, $run));
        $formalPdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($formalPdf->headers->get('Content-Disposition'))->toContain('inline')
            ->and(str_starts_with($formalPdf->getContent(), '%PDF-'))->toBeTrue();
    }
    foreach (['admin.production.work-orders.print', 'admin.production.work-orders.requirement.print'] as $printRoute) {
        $formalPdf = $this->actingAs($fixture['user'])
            ->withSession($session)
            ->get(route($printRoute, $order));
        $formalPdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($formalPdf->headers->get('Content-Disposition'))->toContain('inline')
            ->and(str_starts_with($formalPdf->getContent(), '%PDF-'))->toBeTrue();
    }
});

test('manual inventory receipt issue return and transfer use the full posted movement cycle', function () {
    $fixture = manufacturingInventoryFixture();
    $destinationBranch = Branch::query()->create([
        ...app(DocumentNumberService::class)->next('branches', Branch::class),
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Receiving Factory',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $destinationStore = BranchStore::query()->create([
        'branch_id' => $destinationBranch->getKey(),
        'name' => 'Receiving Store',
        'position' => 2,
    ]);
    $permissions = [
        'inventory.documents.view',
        'inventory.documents.create',
        'inventory.documents.receive',
        'inventory.documents.issue',
        'inventory.documents.return',
        'inventory.documents.transfer',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        'locale' => 'ar',
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.inventory.documents.create'))
        ->assertOk()
        ->assertSee('إذن استلام مخزني')
        ->assertSee('إذن صرف مخزني')
        ->assertSee('إذن مرتجع إلى المخزن')
        ->assertSee('تحويل مخزني')
        ->assertSee('متاح');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.inventory.documents.index'))
        ->assertOk()
        ->assertSee('window.dataTableTranslations', false)
        ->assertSee('emptyTable');

    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.inventory.documents.select2.stores', ['scope' => 'destination', 'q' => 'Receiving']))
        ->assertOk()
        ->assertJsonPath('results.0.id', (string) $destinationStore->public_uuid)
        ->assertJsonPath('results.0.text', 'Receiving Factory — Receiving Store');
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.inventory.documents.select2.products', ['q' => 'Plastic Resin']))
        ->assertOk()
        ->assertJsonPath('results.0.id', (string) $fixture['raw']->doc_num);

    $post = function (string $type, array $overrides = []) use ($fixture, $session): InventoryDocument {
        $this->actingAs($fixture['user'])->withSession($session)
            ->post(route('admin.inventory.documents.store'), [
                'branch_store_id' => $fixture['store']->getKey(),
                'document_type' => $type,
                'document_date' => now()->toDateString(),
                'movement_reason' => 'Business cycle verification',
                'source_stock_status' => InventoryTransaction::StatusAvailable,
                'destination_stock_status' => InventoryTransaction::StatusAvailable,
                'submit_action' => 'post_and_view',
                'lines' => [[
                    'product_id' => $fixture['raw']->getKey(),
                    'quantity' => '1',
                    'unit_cost' => '2',
                ]],
                ...$overrides,
            ])
            ->assertRedirect();

        return InventoryDocument::query()->latest('id')->firstOrFail();
    };

    $receipt = $post(InventoryDocument::TypeReceipt, ['lines' => [[
        'product_id' => $fixture['raw']->getKey(), 'quantity' => '10', 'unit_cost' => '2',
    ]]]);
    $issue = $post(InventoryDocument::TypeIssue, ['lines' => [[
        'product_id' => $fixture['raw']->getKey(), 'quantity' => '4',
    ]]]);
    $return = $post(InventoryDocument::TypeReturn, ['lines' => [[
        'product_id' => $fixture['raw']->getKey(), 'quantity' => '1', 'unit_cost' => '2',
    ]]]);
    $transfer = $post(InventoryDocument::TypeTransfer, [
        'destination_branch_store_id' => $destinationStore->getKey(),
        'lines' => [['product_id' => $fixture['raw']->getKey(), 'quantity' => '3']],
    ]);

    expect($receipt->journalEntry)->toBeInstanceOf(JournalEntry::class)
        ->and($issue->journalEntry)->toBeInstanceOf(JournalEntry::class)
        ->and($return->journalEntry)->toBeInstanceOf(JournalEntry::class)
        ->and($transfer->transactions)->toHaveCount(2)
        ->and($transfer->transactions->firstWhere('quantity_in', '3.00000000')?->branch_id)->toBe($destinationBranch->getKey())
        ->and(app(InventoryAvailabilityService::class)->forProduct(
            $fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['raw']->getKey(),
        )['physical_on_hand'])->toBe('1004.00000000')
        ->and(app(InventoryAvailabilityService::class)->forProduct(
            $fixture['company']->getKey(), $destinationStore->getKey(), $fixture['raw']->getKey(),
        )['physical_on_hand'])->toBe('3.00000000');

    $dataResponse = $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.inventory.documents.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 4);
    expect(collect($dataResponse->json('data'))->pluck('document_type'))
        ->toContain('إذن استلام مخزني', 'تحويل مخزني');
});

test('manual inventory movement supports draft edit datatable navigation and controlled posting', function () {
    $fixture = manufacturingInventoryFixture();
    $permissions = [
        'inventory.documents.view',
        'inventory.documents.create',
        'inventory.documents.edit',
        'inventory.documents.post',
        'inventory.documents.adjust',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    $payload = [
        'branch_store_id' => $fixture['store']->getKey(),
        'document_type' => InventoryDocument::TypeAdjustmentIn,
        'document_date' => now()->toDateString(),
        'movement_reason' => 'Draft movement lifecycle',
        'destination_stock_status' => InventoryTransaction::StatusAvailable,
        'lines' => [[
            'product_id' => $fixture['raw']->getKey(),
            'quantity' => '2',
            'unit_cost' => '3',
        ]],
        'submit_action' => 'save_edit',
    ];

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.inventory.documents.store'), $payload)
        ->assertRedirect();
    $document = InventoryDocument::query()->latest('id')->firstOrFail();

    expect($document->status)->toBe(InventoryDocument::StatusDraft)
        ->and($document->transactions()->count())->toBe(0)
        ->and($document->journalEntry)->toBeNull();

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.inventory.documents.edit', $document))
        ->assertOk()
        ->assertSee('Edit Inventory Movement')
        ->assertSee('Draft movement lifecycle')
        ->assertSee('data-inventory-movement-lines', false)
        ->assertDontSee('window.inventoryMovementLines', false);

    $dataResponse = $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.inventory.documents.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
        ->assertOk();
    expect($dataResponse->json('data.0.doc_num'))->toContain(route('admin.inventory.documents.edit', $document));

    $payload['lines'][0]['quantity'] = '5';
    $this->actingAs($fixture['user'])->withSession($session)
        ->put(route('admin.inventory.documents.update', $document), $payload)
        ->assertRedirect(route('admin.inventory.documents.edit', $document));
    expect($document->refresh()->lines->first()->quantity)->toBe('5.00000000')
        ->and($document->transactions()->count())->toBe(0);

    $fixture['user']->revokePermissionTo('inventory.documents.adjust');
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.inventory.documents.post', $document))
        ->assertForbidden();
    $fixture['user']->givePermissionTo('inventory.documents.adjust');

    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.inventory.documents.post', $document))
        ->assertOk()
        ->assertJsonPath('data.status', InventoryDocument::StatusPosted);
    expect($document->refresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($document->transactions()->count())->toBe(1)
        ->and($document->journalEntry)->not->toBeNull();

    $this->actingAs($fixture['user'])->withSession($session)
        ->from(route('admin.inventory.documents.show', $document))
        ->put(route('admin.inventory.documents.update', $document), $payload)
        ->assertRedirect(route('admin.inventory.documents.show', $document))
        ->assertSessionHasErrors('document');
});

test('the browser inventory movement contract posts and prints twenty five quantity only lines', function () {
    $fixture = manufacturingInventoryFixture();
    $permissions = ['inventory.documents.view', 'inventory.documents.create', 'inventory.documents.adjust', 'inventory.documents.print'];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $fixture['user']->givePermissionTo($permissions);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];

    $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.inventory.documents.create'))
        ->assertOk()
        ->assertSee('data-add-inventory-line', false)
        ->assertSee('inventory-line-template', false);

    $lines = collect(range(1, 25))->map(fn (int $line): array => [
        'product_id' => $fixture['finished']->getKey(),
        'quantity' => '1',
        'notes' => "Stress line {$line}",
    ])->all();

    $response = $this->actingAs($fixture['user'])
        ->withSession($session)
        ->post(route('admin.inventory.documents.store'), [
            'branch_store_id' => $fixture['store']->getKey(),
            'document_type' => InventoryDocument::TypeAdjustmentIn,
            'document_date' => now()->toDateString(),
            'movement_reason' => '25-line browser stress document',
            'destination_stock_status' => InventoryTransaction::StatusAvailable,
            'submit_action' => 'post_and_view',
            'lines' => $lines,
        ]);

    $response->assertSessionHasNoErrors();
    $document = InventoryDocument::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('admin.inventory.documents.show', $document));

    expect($document->lines()->count())->toBe(25)
        ->and($document->transactions()->count())->toBe(25)
        ->and($document->lines()->whereNotNull('unit_cost')->count())->toBe(0)
        ->and($document->transactions()->whereNotNull('unit_cost')->count())->toBe(0)
        ->and($document->journalEntry)->toBeNull();

    $pdf = $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.inventory.documents.print', $document));

    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->headers->get('Content-Disposition'))->toContain('inline')
        ->and(str_starts_with($pdf->getContent(), '%PDF-'))->toBeTrue();
});

test('the browser run workflow auto generates and accounts a twenty five component material issue', function () {
    $fixture = manufacturingInventoryFixture();
    $permissions = [
        'production.runs.reserve',
        'production.runs.issue',
        'production.runs.setup',
        'production.runs.progress',
        'production.runs.account_materials',
        'production.runs.receive',
        'production.runs.print',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $fixture['user']->givePermissionTo($permissions);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    $finished = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 11999,
        'doc_num' => 'FG-25-BOM',
        'name' => 'Twenty Five Component Pack',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(),
        'status' => 'active',
    ]);

    foreach (range(1, 25) as $line) {
        $component = Product::query()->create([
            'company_id' => $fixture['company']->getKey(),
            'doc_number' => 12000 + $line,
            'doc_num' => sprintf('RM-BOM-%02d', $line),
            'name' => sprintf('BOM Component %02d', $line),
            'item_classification' => Product::ClassificationRawMaterial,
            'item_unit_id' => $fixture['unit']->getKey(),
            'status' => 'active',
        ]);
        ProductComponent::query()->create([
            'company_id' => $fixture['company']->getKey(),
            'product_id' => $finished->getKey(),
            'component_product_id' => $component->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'calculation_method' => ProductComponent::CalculationDirect,
            'quantity' => '1',
            'created_by' => $fixture['user']->getKey(),
        ]);
        InventoryTransaction::query()->create([
            'posting_key' => "twenty-five-bom-opening-{$line}",
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'stock_status' => InventoryTransaction::StatusAvailable,
            'transaction_date' => now()->toDateString(),
            'transaction_type' => 'opening_stock',
            'product_id' => $component->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'quantity_in' => '100',
            'quantity_out' => 0,
            'source_type' => 'test',
            'source_id' => 100 + $line,
            'source_doc_num' => 'OPEN-25-BOM',
            'unit_cost' => '2',
            'total_cost' => '200',
            'created_by' => $fixture['user']->getKey(),
        ]);
    }

    $fixture['mold']->products()->attach($finished);
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [[
        'product_id' => $finished->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '1',
    ]]);
    $line = $cycle->releaseOrder($order)->lines->firstOrFail();
    $run = $cycle->createRun($line, [
        'planned_quantity' => '1',
        'planned_start_at' => now()->addHours(5),
        'planned_end_at' => now()->addHours(6),
        'production_machine_id' => $fixture['machine']->getKey(),
        'production_mold_id' => $fixture['mold']->getKey(),
        'batch_lot' => 'PACK-25-001',
    ]);

    expect($run->requirements)->toHaveCount(25);

    $post = fn (string $route, array $data = []) => $this->actingAs($fixture['user'])
        ->withSession($session)
        ->post(route($route, $run), productionSubmission($data));

    $post('admin.production.runs.reserve', ['branch_store_id' => $fixture['store']->getKey()])->assertRedirect();
    $post('admin.production.runs.issue', ['branch_store_id' => $fixture['store']->getKey()])->assertRedirect();
    $issue = InventoryDocument::query()
        ->where('production_run_id', $run->getKey())
        ->where('document_type', InventoryDocument::TypeMaterialIssue)
        ->firstOrFail();

    expect($issue->lines)->toHaveCount(25)
        ->and($issue->transactions)->toHaveCount(50);

    $post('admin.production.runs.setup.start')->assertRedirect();
    $post('admin.production.runs.setup.complete')->assertRedirect();
    $post('admin.production.runs.start')->assertRedirect();
    $post('admin.production.runs.progress', ['good_base_quantity' => '1'])->assertRedirect();
    $accountingLines = $run->fresh()->requirements->map(fn ($requirement): array => [
        'requirement_id' => $requirement->getKey(),
        'consumed_quantity' => $requirement->issued_quantity,
        'waste_quantity' => '0',
    ])->all();
    $post('admin.production.runs.account', [
        'branch_store_id' => $fixture['store']->getKey(),
        'lines' => $accountingLines,
    ])->assertRedirect();
    $post('admin.production.runs.receive', [
        'branch_store_id' => $fixture['store']->getKey(),
        'base_quantity' => '1',
    ])->assertRedirect();

    $receipt = InventoryDocument::query()
        ->where('production_run_id', $run->getKey())
        ->where('document_type', InventoryDocument::TypeProductionReceipt)
        ->firstOrFail();

    expect((float) $receipt->lines->first()->total_cost)->toBe(50.0)
        ->and($run->fresh()->received_base_quantity)->toBe('1.00000000');

    $pdf = $this->actingAs($fixture['user'])
        ->withSession($session)
        ->get(route('admin.production.runs.print', $run));

    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($pdf->getContent(), '%PDF-'))->toBeTrue();
});

test('a second packing factory protects customer material across orders and completes two reconciled runs', function () {
    $fixture = manufacturingInventoryFixture();
    $currency = Currency::query()->where('company_id', $fixture['company']->getKey())->where('is_main', true)->firstOrFail();
    $packingBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 14001,
        'doc_num' => 'FACTORY-PACK-02',
        'name' => 'Customer Packing Factory',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $packingStore = BranchStore::query()->create([
        'branch_id' => $packingBranch->getKey(),
        'name' => 'Packing Components Store',
        'position' => 1,
    ]);
    $machine = ProductionMachine::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $packingBranch->getKey(),
        'code' => 'PACK-LINE-02',
        'name' => 'Packing Line 02',
        'created_by' => $fixture['user']->getKey(),
    ]);
    $mold = ProductionMold::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $packingBranch->getKey(),
        'code' => 'PACK-FORMAT-KIT',
        'name' => 'Customer Kit Format',
        'created_by' => $fixture['user']->getKey(),
    ]);
    $machine->molds()->attach($mold);
    $kit = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 14001,
        'doc_num' => 'FG-CUSTOMER-KIT',
        'name' => 'TEST Customer Kit',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $fixture['unit']->getKey(),
        'status' => 'active',
    ]);
    $mold->products()->attach($kit);
    $componentNames = [
        'TEST Printed Wrapper — Customer A',
        'Napkin',
        'Spoon',
        'Fork',
        'Salt',
        'Pepper',
        'Outer Carton',
    ];
    $components = collect($componentNames)->map(function (string $name, int $index) use ($fixture, $kit, $packingBranch, $packingStore): Product {
        $component = Product::query()->create([
            'company_id' => $fixture['company']->getKey(),
            'doc_number' => 14100 + $index,
            'doc_num' => sprintf('PACK-COMP-%02d', $index + 1),
            'name' => $name,
            'item_classification' => Product::ClassificationPackaging,
            'item_unit_id' => $fixture['unit']->getKey(),
            'status' => 'active',
        ]);
        ProductComponent::query()->create([
            'company_id' => $fixture['company']->getKey(),
            'product_id' => $kit->getKey(),
            'component_product_id' => $component->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'calculation_method' => ProductComponent::CalculationDirect,
            'quantity' => '1',
            'created_by' => $fixture['user']->getKey(),
        ]);
        $openingQuantity = $name === 'Fork' ? '1005' : '1000';
        InventoryTransaction::query()->create([
            'posting_key' => "packing-opening-{$index}",
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $packingBranch->getKey(),
            'branch_store_id' => $packingStore->getKey(),
            'stock_status' => InventoryTransaction::StatusAvailable,
            'batch_lot' => $index === 0 ? 'WRAP-CUSTOMER-A-001' : null,
            'transaction_date' => now()->toDateString(),
            'transaction_type' => 'opening_stock',
            'product_id' => $component->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'quantity_in' => $openingQuantity,
            'quantity_out' => 0,
            'source_type' => 'test',
            'source_id' => 200 + $index,
            'source_doc_num' => 'PACK-OPENING',
            'unit_cost' => '1',
            'total_cost' => $openingQuantity,
            'created_by' => $fixture['user']->getKey(),
        ]);

        return $component;
    })->values();
    $customerA = Customer::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 14001,
        'doc_num' => 'CUSTOMER-PACK-A',
        'name' => 'Packing Customer A',
        'status' => 'active',
    ]);
    $customerB = Customer::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 14002,
        'doc_num' => 'CUSTOMER-PACK-B',
        'name' => 'Packing Customer B',
        'status' => 'active',
    ]);
    $salesOrder = function (Customer $customer, int $number) use ($fixture, $currency, $packingBranch, $packingStore, $kit): SalesOrder {
        $order = SalesOrder::query()->create([
            'doc_number' => $number,
            'doc_num' => "SO-PACK-{$number}",
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $packingBranch->getKey(),
            'branch_store_id' => $packingStore->getKey(),
            'customer_id' => $customer->getKey(),
            'currency_id' => $currency->getKey(),
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addWeek()->toDateString(),
            'status' => SalesOrder::StatusApproved,
            'credit_status' => 'approved',
            'subtotal_amount' => '1000',
            'total_amount' => '1000',
            'created_by' => $fixture['user']->getKey(),
        ]);
        $order->lines()->create([
            'line_number' => 1,
            'product_id' => $kit->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => $kit->name,
            'quantity' => '1000',
            'unit_price' => '1',
            'line_total' => '1000',
            'product_classification_snapshot' => Product::ClassificationFinishedProduct,
            'conversion_factor' => '1',
            'base_quantity' => '1000',
        ]);

        return $order->refresh()->load('lines');
    };
    $orderA = $salesOrder($customerA, 14001);
    $orderB = $salesOrder($customerB, 14002);
    $demand = app(SalesProductionDemandService::class);
    $productionA = $demand->create($orderA, [[
        'sales_order_line_id' => $orderA->lines->first()->getKey(),
        'quantity' => '1000',
    ]]);
    $productionB = $demand->create($orderB, [[
        'sales_order_line_id' => $orderB->lines->first()->getKey(),
        'quantity' => '1000',
    ]]);
    $cycle = app(ProductionCycleService::class);
    $lineA = $cycle->releaseOrder($productionA)->lines->firstOrFail();
    $lineB = $cycle->releaseOrder($productionB)->lines->firstOrFail();
    $runOne = $cycle->createRun($lineA, [
        'planned_quantity' => '400',
        'planned_start_at' => now()->addHour(),
        'planned_end_at' => now()->addHours(2),
        'production_machine_id' => $machine->getKey(),
        'production_mold_id' => $mold->getKey(),
        'batch_lot' => 'KIT-A-RUN-400',
    ]);
    $runTwo = $cycle->createRun($lineA, [
        'planned_quantity' => '600',
        'planned_start_at' => now()->addHours(2),
        'planned_end_at' => now()->addHours(4),
        'production_machine_id' => $machine->getKey(),
        'production_mold_id' => $mold->getKey(),
        'batch_lot' => 'KIT-A-RUN-600',
    ]);
    $runB = $cycle->createRun($lineB, [
        'planned_quantity' => '1000',
        'planned_start_at' => now()->addHours(5),
        'planned_end_at' => now()->addHours(7),
        'batch_lot' => 'KIT-B-BLOCKED',
    ]);

    $cycle->reserveRun($runOne, $packingStore->getKey());
    $cycle->reserveRun($runTwo, $packingStore->getKey());
    $wrapper = $components->first();
    $wrapperPosition = app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(),
        $packingStore->getKey(),
        $wrapper->getKey(),
    );

    expect($wrapperPosition['on_hand'])->toBe('1000.00000000')
        ->and($wrapperPosition['reserved'])->toBe('1000.00000000')
        ->and($wrapperPosition['available'])->toBe('0.00000000')
        ->and(fn () => $cycle->reserveRun($runB, $packingStore->getKey()))
        ->toThrow(DomainException::class, __('The production reservation exceeds available stock.'));

    $wrapperReservations = InventoryReservation::query()
        ->where('product_id', $wrapper->getKey())
        ->whereIn('production_run_id', [$runOne->getKey(), $runTwo->getKey()])
        ->get();

    expect($wrapperReservations)->toHaveCount(2)
        ->and($wrapperReservations->pluck('customer_id')->unique()->all())->toBe([$customerA->getKey()])
        ->and($wrapperReservations->pluck('production_order_id')->unique()->all())->toBe([$productionA->getKey()]);

    $completeRun = function (ProductionRun $run, string $goodQuantity, bool $withForkDamage = false) use ($cycle, $packingStore): void {
        $cycle->issueMaterials($run, $packingStore->getKey());

        if ($withForkDamage) {
            $forkRequirement = $run->fresh()->requirements->first(fn ($requirement): bool => $requirement->product?->name === 'Fork');
            $cycle->issueMaterials($run, $packingStore->getKey(), [$forkRequirement->getKey() => '5'], true);
        }

        $cycle->startSetup($run);
        $cycle->completeSetup($run->fresh());
        $cycle->startRun($run->fresh());
        $cycle->recordProgress($run->fresh(), ['good_base_quantity' => $goodQuantity]);
        $accounting = $run->fresh()->requirements->mapWithKeys(function ($requirement) use ($withForkDamage): array {
            $isDamagedFork = $withForkDamage && $requirement->product?->name === 'Fork';

            return [$requirement->getKey() => [
                'consumed_quantity' => bcsub(
                    bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8),
                    $isDamagedFork ? '5' : '0',
                    8,
                ),
                'waste_quantity' => $isDamagedFork ? '5' : '0',
            ]];
        })->all();
        $cycle->accountMaterials($run->fresh(), $packingStore->getKey(), $accounting);
        $inspection = $cycle->recordInspection($run->fresh(), ['result' => 'passed', 'notes' => 'Packing final inspection passed.']);
        $cycle->reviewInspection($inspection, true);

        if ($goodQuantity === '400') {
            $cycle->receiveFinishedGoods($run->fresh(), $packingStore->getKey(), '200');
            $cycle->receiveFinishedGoods($run->fresh(), $packingStore->getKey(), '200');
        } else {
            $cycle->receiveFinishedGoods($run->fresh(), $packingStore->getKey(), $goodQuantity);
        }

        $cycle->completeRun($run->fresh());
    };

    $completeRun($runOne, '400', true);
    expect($orderA->lines->first()->fresh()->activeReservedQuantity())->toBe('400.00000000')
        ->and(bcsub($orderA->lines->first()->fresh()->production_requested_quantity, $orderA->lines->first()->fresh()->produced_quantity, 8))->toBe('600.00000000');
    $completeRun($runTwo, '600');
    $additionalIssue = InventoryDocument::query()
        ->where('production_run_id', $runOne->getKey())
        ->where('document_type', InventoryDocument::TypeAdditionalMaterialIssue)
        ->firstOrFail();
    $waste = InventoryDocument::query()
        ->where('production_run_id', $runOne->getKey())
        ->where('document_type', InventoryDocument::TypeProductionWaste)
        ->firstOrFail();

    expect($additionalIssue->lines->first()->quantity)->toBe('5.00000000')
        ->and($waste->lines->first()->quantity)->toBe('5.00000000')
        ->and($productionA->fresh()->status)->toBe(ProductionOrder::StatusCompleted)
        ->and($orderA->lines->first()->fresh()->produced_quantity)->toBe('1000.00000000')
        ->and(InventoryReservation::query()->where('production_order_id', $productionA->getKey())->whereNotNull('production_material_requirement_id')->where('status', InventoryReservation::StatusActive)->count())->toBe(0)
        ->and($orderA->lines->first()->fresh()->activeReservedQuantity())->toBe('1000.00000000')
        ->and(app(InventoryAvailabilityService::class)->forProduct($fixture['company']->id, $packingStore->id, $kit->id)['available'])->toBe('0.00000000');

    foreach ($runOne->fresh()->requirements->merge($runTwo->fresh()->requirements) as $requirement) {
        $accounted = bcadd((string) $requirement->consumed_quantity, (string) $requirement->waste_quantity, 8);
        $issuedNet = bcsub(
            bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8),
            (string) $requirement->returned_quantity,
            8,
        );
        expect($accounted)->toBe($issuedNet);
    }

    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
    ))->keyBy('key');
    expect($reconciliation['wip']['difference'])->toBe('0.0000')
        ->and($reconciliation['finished_goods']['difference'])->toBe('0.0000')
        ->and($reconciliation['production_waste']['difference'])->toBe('0.0000');
});

test('capability permissions separate warehouse planning quality and cost access server side', function () {
    $fixture = manufacturingInventoryFixture();
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '1',
    ]]);
    $line = $cycle->releaseOrder($order)->lines->firstOrFail();
    $run = $cycle->createRun($line, [
        'planned_quantity' => '1',
        'planned_start_at' => now()->addHours(8),
        'planned_end_at' => now()->addHours(9),
        'production_machine_id' => $fixture['machine']->getKey(),
        'production_mold_id' => $fixture['mold']->getKey(),
    ]);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    $userWith = function (array $permissions): User {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    };
    $operator = $userWith([
        'inventory.documents.view',
        'inventory.documents.create',
        'inventory.documents.transfer',
        'inventory.reports.operational',
        'inventory.stock_counts.create',
    ]);

    $this->actingAs($operator)->withSession($session)
        ->get(route('admin.inventory.reports.index'))
        ->assertOk()
        ->assertDontSee('<th>'.__('Value').'</th>', false)
        ->assertDontSee(__('Inventory / Production to General Ledger Reconciliation'));
    expect(Route::has('admin.inventory.accounting.index'))->toBeFalse();
    $this->actingAs($operator)->withSession($session)
        ->get(route('admin.inventory.documents.create'))
        ->assertOk()
        ->assertSee(InventoryDocument::TypeTransfer)
        ->assertDontSee(InventoryDocument::TypeAdjustmentIn);
    $this->actingAs($operator)->withSession($session)
        ->post(route('admin.inventory.documents.store'), [
            'branch_store_id' => $fixture['store']->getKey(),
            'document_type' => InventoryDocument::TypeAdjustmentIn,
            'document_date' => now()->toDateString(),
            'movement_reason' => 'Unauthorized financial adjustment',
            'lines' => [['product_id' => $fixture['raw']->getKey(), 'quantity' => '1', 'unit_cost' => '2']],
        ])
        ->assertForbidden();

    $planner = $userWith([
        'production.orders.view',
        'production.orders.plan',
        'production.orders.release',
        'production.runs.view',
        'production.runs.plan',
    ]);
    $this->actingAs($planner)->withSession($session)
        ->get(route('admin.production.runs.show', $run))
        ->assertOk();
    $this->actingAs($planner)->withSession($session)
        ->post(route('admin.production.runs.issue', $run), ['branch_store_id' => $fixture['store']->getKey()])
        ->assertForbidden();

    $qualityUser = $userWith(['production.quality.view']);
    $this->actingAs($qualityUser)->withSession($session)
        ->get(route('admin.production.quality.index'))
        ->assertOk();
    $this->actingAs($qualityUser)->withSession($session)
        ->get(route('admin.inventory.documents.create'))
        ->assertForbidden();

    $costUser = $userWith([
        'inventory.reports.operational',
        'inventory.reports.financial',
        'inventory.reports.export',
        'production.reports.operational',
        'production.reports.financial',
        'production.reports.export',
    ]);
    $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.inventory.reports.index'))
        ->assertOk()
        ->assertDontSee('<th>'.__('Value').'</th>', false)
        ->assertDontSee(__('Inventory / Production to General Ledger Reconciliation'));
    $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.inventory.reports.export'))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $inventoryPdf = $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.inventory.reports.print'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="inventory-operations-report.pdf"');
    expect(str_starts_with($inventoryPdf->getContent(), '%PDF-'))->toBeTrue();
    $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.production.reports.index'))
        ->assertOk()
        ->assertSee(__('production_execution.reports.title'))
        ->assertDontSee(__('Production Cost and Work in Process'));
    $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.production.reports.export'))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $productionPdf = $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.production.reports.print'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="production-overview-report.pdf"');
    expect(str_starts_with($productionPdf->getContent(), '%PDF-'))->toBeTrue();
    $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.inventory.documents.create'))
        ->assertForbidden();
});

test('operational inventory reports remain usable when a required account classification is missing', function () {
    $fixture = manufacturingInventoryFixture();
    $rawInventoryClassification = AccountClassification::query()->where('code', 'raw_material_inventory')->firstOrFail();
    Account::query()
        ->where('company_id', $fixture['company']->getKey())
        ->where('account_classification_id', $rawInventoryClassification->getKey())
        ->update(['account_classification_id' => null]);

    $permissions = [
        'inventory.reports.operational',
        'inventory.reports.financial',
        'inventory.reports.export',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $financialUser = User::factory()->create();
    $financialUser->givePermissionTo($permissions);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    $unavailableMessage = __('accounts.messages.posting_account_missing', [
        'classification' => $rawInventoryClassification->displayName(),
        'code' => $rawInventoryClassification->code,
        'event' => __('Inventory reconciliation'),
    ]);

    $this->actingAs($financialUser)->withSession($session)
        ->get(route('admin.inventory.reports.index'))
        ->assertOk()
        ->assertSee('Plastic Resin')
        ->assertDontSee('<th>'.__('Value').'</th>', false)
        ->assertDontSee($unavailableMessage)
        ->assertDontSee(__('Reconciled'));

    $this->actingAs($financialUser)->withSession($session)
        ->get(route('admin.inventory.reports.export'))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->actingAs($financialUser)->withSession($session)
        ->get(route('admin.inventory.reports.print'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="inventory-operations-report.pdf"');

    expect(str_starts_with($pdf->getContent(), '%PDF-'))->toBeTrue();
});

test('receipt layers preserve aging and enforce FEFO without consuming expired stock on failure', function () {
    $fixture = manufacturingInventoryFixture();
    $layers = app(InventoryLayerService::class);
    $reports = app(InventoryReportService::class);
    $agingProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9991,
        'doc_num' => 'RM-AGING',
        'name' => 'Aging Resin',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $fixture['unit']->getKey(),
        'status' => 'active',
    ]);
    $expiryProduct = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9992,
        'doc_num' => 'RM-EXPIRY',
        'name' => 'Expiry Controlled Additive',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $fixture['unit']->getKey(),
        'tracks_expiry' => true,
        'status' => 'active',
    ]);
    $inbound = function (Product $product, string $key, int $ageDays, string $quantity, ?int $expiresInDays = null) use ($fixture, $layers): InventoryTransaction {
        $transaction = InventoryTransaction::query()->create([
            'posting_key' => $key,
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'stock_status' => InventoryTransaction::StatusAvailable,
            'transaction_date' => now()->subDays($ageDays)->toDateString(),
            'transaction_type' => 'purchase_receipt',
            'product_id' => $product->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'quantity_in' => $quantity,
            'quantity_out' => 0,
            'expiry_date' => $expiresInDays === null ? null : now()->addDays($expiresInDays)->toDateString(),
            'source_type' => 'layer_runtime_test',
            'source_id' => abs(crc32($key)),
            'source_doc_num' => strtoupper($key),
            'unit_cost' => '2',
            'total_cost' => bcmul($quantity, '2', 8),
            'created_by' => $fixture['user']->getKey(),
        ]);
        $layers->recordInbound($transaction);

        return $transaction;
    };
    $issue = function (Product $product, string $key, string $quantity) use ($fixture): InventoryTransaction {
        return InventoryTransaction::query()->create([
            'posting_key' => $key,
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'branch_store_id' => $fixture['store']->getKey(),
            'stock_status' => InventoryTransaction::StatusAvailable,
            'transaction_date' => now()->toDateString(),
            'transaction_type' => 'material_issue',
            'product_id' => $product->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'quantity_in' => 0,
            'quantity_out' => $quantity,
            'source_type' => 'layer_runtime_test',
            'source_id' => abs(crc32($key)),
            'source_doc_num' => strtoupper($key),
            'unit_cost' => '2',
            'total_cost' => bcmul($quantity, '2', 8),
            'created_by' => $fixture['user']->getKey(),
        ]);
    };

    $inbound($agingProduct, 'aging-old', 200, '100');
    $inbound($agingProduct, 'aging-mid', 60, '100');
    $inbound($agingProduct, 'aging-new', 10, '100');
    $agingIssue = $issue($agingProduct, 'aging-issue', '150');
    $layers->allocateIssue($agingIssue);
    $aging = $reports->agingLayers($fixture['company']->getKey(), [
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $agingProduct->getKey(),
        'as_of' => now()->toDateString(),
    ]);
    expect($aging)->toHaveCount(2)
        ->and($aging->firstWhere('age_bucket', '31–60')?->remaining_quantity)->toBe('50.00000000')
        ->and($aging->firstWhere('age_bucket', '0–30')?->remaining_quantity)->toBe('100.00000000')
        ->and(InventoryLayerAllocation::query()->where('issue_transaction_id', $agingIssue->getKey())->sum('quantity'))->toEqual(150);

    $expired = $inbound($expiryProduct, 'expiry-expired', 10, '10', -1);
    $soon = $inbound($expiryProduct, 'expiry-soon', 5, '20', 20);
    $later = $inbound($expiryProduct, 'expiry-later', 4, '30', 80);
    $expiryIssue = $issue($expiryProduct, 'expiry-issue', '25');
    $layers->allocateIssue($expiryIssue);
    $allocatedReceiptIds = InventoryLayerAllocation::query()
        ->where('issue_transaction_id', $expiryIssue->getKey())
        ->with('layer')
        ->get()
        ->pluck('layer.receipt_transaction_id')
        ->all();
    expect($allocatedReceiptIds)->toBe([$soon->getKey(), $later->getKey()])
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $expired->getKey())->value('remaining_quantity'))->toBe('10.00000000')
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $soon->getKey())->value('remaining_quantity'))->toBe('0.00000000')
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $later->getKey())->value('remaining_quantity'))->toBe('25.00000000');

    $failedIssue = $issue($expiryProduct, 'expiry-failed-issue', '26');
    expect(fn () => $layers->allocateIssue($failedIssue))
        ->toThrow(DomainException::class, __('The issue exceeds non-expired stock. Expired or undated expiry layers are blocked.'));
    expect(InventoryLayerAllocation::query()->where('issue_transaction_id', $failedIssue->getKey())->count())->toBe(0)
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $later->getKey())->value('remaining_quantity'))->toBe('25.00000000');

    $expiry = $reports->expiryLayers($fixture['company']->getKey(), [
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $expiryProduct->getKey(),
        'as_of' => now()->toDateString(),
        'expiry_within_days' => 90,
    ]);
    expect($expiry)->toHaveCount(2)
        ->and($expiry->firstWhere('expiry_state', 'expired')?->remaining_quantity)->toBe('10.00000000')
        ->and($expiry->firstWhere('expiry_state', 'expiring')?->remaining_quantity)->toBe('25.00000000');
});

test('maintenance flows from a breakdown report through external work completion and formal reporting', function () {
    $fixture = manufacturingInventoryFixture();
    $fixedAssetAccount = manufacturingMaintenanceAssetAccount($fixture);
    $asset = FixedAsset::query()->create([
        'doc_number' => 9910,
        'doc_num' => 'FA-MAINT-9910',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'period_id' => $fixture['period']->getKey(),
        'account_id' => $fixedAssetAccount->getKey(),
        'asset_date' => now()->toDateString(),
        'asset_name' => 'Injection Line Main Motor',
        'status' => FixedAsset::StatusActive,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $maintenanceAccount = function (int $number, string $name) use ($fixedAssetAccount, $fixture): Account {
        return Account::query()->create([
            'doc_number' => $number,
            'doc_num' => 'ACC-MAINT-'.$number,
            'company_id' => $fixture['company']->getKey(),
            'account_code' => '129'.$number,
            'name' => $name,
            'parent_id' => $fixedAssetAccount->parent_id,
            'level' => $fixedAssetAccount->level,
            'account_classification_id' => $fixedAssetAccount->account_classification_id,
            'account_type' => $fixedAssetAccount->account_type,
            'statement_type' => $fixedAssetAccount->statement_type,
            'normal_balance' => $fixedAssetAccount->normal_balance,
            'is_group' => false,
            'is_postable' => true,
            'status' => 'active',
        ]);
    };
    $disposedAsset = FixedAsset::query()->create([
        'doc_number' => 9911,
        'doc_num' => 'FA-MAINT-9911',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'period_id' => $fixture['period']->getKey(),
        'account_id' => $maintenanceAccount(9911, 'Disposed maintenance asset account')->getKey(),
        'asset_date' => now()->toDateString(),
        'asset_name' => 'Disposed Maintenance Machine',
        'status' => FixedAsset::StatusDisposed,
        'disposed_at' => now()->toDateString(),
        'created_by' => $fixture['user']->getKey(),
    ]);
    $otherBranch = Branch::query()->create([
        'doc_number' => 9912,
        'doc_num' => 'BR-MAINT-9912',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Other Maintenance Branch',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $foreignBranchAsset = FixedAsset::query()->create([
        'doc_number' => 9912,
        'doc_num' => 'FA-MAINT-9912',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $otherBranch->getKey(),
        'period_id' => $fixture['period']->getKey(),
        'account_id' => $maintenanceAccount(9912, 'Foreign branch maintenance asset account')->getKey(),
        'asset_date' => now()->toDateString(),
        'asset_name' => 'Foreign Branch Maintenance Machine',
        'status' => FixedAsset::StatusActive,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $nonFixedAssetAccount = Account::query()
        ->where('company_id', $fixture['company']->getKey())
        ->where('status', 'active')
        ->where('is_group', false)
        ->whereHas('classification', fn ($query) => $query->where('code', '!=', AccountClassification::FixedAssets)->where('status', 'active'))
        ->firstOrFail();
    $nonFixedClassificationAsset = FixedAsset::query()->create([
        'doc_number' => 9913,
        'doc_num' => 'FA-MAINT-9913',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'period_id' => $fixture['period']->getKey(),
        'account_id' => $nonFixedAssetAccount->getKey(),
        'asset_date' => now()->toDateString(),
        'asset_name' => 'Non Fixed Account Machine',
        'status' => FixedAsset::StatusActive,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $permissions = [
        'maintenance.requests.view',
        'maintenance.requests.create',
        'maintenance.plans.view',
        'maintenance.plans.create',
        'maintenance.orders.view',
        'maintenance.orders.create',
        'maintenance.orders.approve',
        'maintenance.orders.start',
        'maintenance.orders.pause',
        'maintenance.orders.external',
        'maintenance.orders.complete',
        'maintenance.orders.close',
        'maintenance.orders.print',
        'maintenance.orders.export',
        'maintenance.material_requests.view',
        'maintenance.material_requests.create',
        'maintenance.material_requests.approve',
        'maintenance.material_requests.issue',
        'maintenance.material_requests.return',
        'maintenance.expenses.view',
        'maintenance.expenses.create',
        'maintenance.expenses.approve',
        'maintenance.expenses.pay',
        'maintenance.expenses.reverse',
        'maintenance.reports.view',
        'maintenance.reports.export',
        'maintenance.reports.financial',
        'production.material_requests.view',
        'production.expenses.view',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.orders.create'))
        ->assertOk()
        ->assertSee('js-select2-ajax', false)
        ->assertDontSee($asset->asset_name);
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.maintenance.select2', ['lookup' => 'assets', 'q' => $asset->doc_num]))
        ->assertOk()
        ->assertJsonPath('results.0.id', (string) $asset->getKey());
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.maintenance.select2', ['lookup' => 'assets', 'q' => $disposedAsset->doc_num]))
        ->assertOk()
        ->assertJsonCount(0, 'results');
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.maintenance.select2', ['lookup' => 'assets', 'q' => $foreignBranchAsset->doc_num]))
        ->assertOk()
        ->assertJsonCount(0, 'results');
    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.maintenance.select2', ['lookup' => 'assets', 'q' => $nonFixedClassificationAsset->doc_num]))
        ->assertOk()
        ->assertJsonCount(0, 'results');
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.plans.index'))
        ->assertOk()
        ->assertSee($asset->asset_name)
        ->assertDontSee($disposedAsset->asset_name)
        ->assertDontSee($foreignBranchAsset->asset_name)
        ->assertDontSee($nonFixedClassificationAsset->asset_name);
    $this->actingAs($fixture['user'])->withSession($session)
        ->from(route('admin.maintenance.requests.create'))
        ->post(route('admin.maintenance.requests.store'), [
            'fixed_asset_id' => $nonFixedClassificationAsset->getKey(),
            'request_type' => 'breakdown',
            'discipline' => 'mechanical',
            'priority' => 'normal',
            'symptoms' => 'Must not accept an asset outside fixed asset accounts.',
        ])
        ->assertRedirect(route('admin.maintenance.requests.create'))
        ->assertSessionHasErrors('request');
    expect(MaintenanceRequest::query()->count())->toBe(0);
    $this->actingAs($fixture['user'])->withSession($session)
        ->from(route('admin.maintenance.plans.index'))
        ->post(route('admin.maintenance.plans.store'), [
            'fixed_asset_id' => $nonFixedClassificationAsset->getKey(),
            'name' => 'Invalid asset plan',
            'maintenance_type' => 'preventive',
            'service_mode' => 'internal',
            'frequency_basis' => MaintenancePlan::FrequencyCalendar,
            'interval_value' => 30,
            'schedule_anchor' => MaintenancePlan::AnchorPlanned,
            'next_due_at' => now()->addMonth()->toDateString(),
            'task_template' => 'This plan must be rejected.',
        ])
        ->assertRedirect(route('admin.maintenance.plans.index'))
        ->assertSessionHasErrors('plan');
    expect(MaintenancePlan::query()->count())->toBe(0);

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.requests.store'), [
            'fixed_asset_id' => $asset->getKey(),
            'request_type' => 'breakdown',
            'discipline' => 'electrical',
            'priority' => 'urgent',
            'symptoms' => 'Motor trips after startup.',
        ])
        ->assertRedirect(route('admin.maintenance.requests.index'));
    $maintenanceRequest = MaintenanceRequest::query()->sole();

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.store'), [
            'maintenance_request_doc_num' => $maintenanceRequest->doc_num,
            'maintenance_type' => 'external',
            'discipline' => 'electrical',
            'priority' => 'urgent',
            'service_mode' => 'external',
            'production_mold_id' => $fixture['mold']->getKey(),
            'external_provider_name' => 'Certified Motor Workshop',
            'external_provider_contact' => '01000000000',
            'planned_start_at' => now()->addHour()->toDateTimeString(),
            'planned_end_at' => now()->addHours(4)->toDateTimeString(),
            'work_description' => 'Inspect windings and replace damaged bearings.',
            'external_cost' => '1250.50',
            'next_due_date' => now()->addMonths(3)->toDateString(),
        ])
        ->assertRedirect(route('admin.maintenance.orders.index'));
    $order = MaintenanceWorkOrder::query()->sole();

    expect($maintenanceRequest->refresh()->status)->toBe(MaintenanceRequest::StatusConverted)
        ->and($order->maintenance_request_id)->toBe($maintenanceRequest->getKey())
        ->and($order->production_mold_id)->toBe($fixture['mold']->getKey())
        ->and($order->external_cost)->toBe('1250.5000');
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.orders.show', $order))
        ->assertOk()
        ->assertSee('FA-MAINT-9910')
        ->assertSee('Certified Motor Workshop')
        ->assertDontSee('ERP UI Shell');
    $maintenanceTable = $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.maintenance.orders.index', ['draw' => 1, 'start' => 0, 'length' => 10]));
    $maintenanceTable->assertOk()->assertJsonPath('recordsFiltered', 1);
    expect($maintenanceTable->getContent())->toContain('data-row-primary-link');
    foreach ([
        'admin.maintenance.requests.index',
        'admin.maintenance.material-requests.index',
        'admin.maintenance.expenses.index',
        'admin.production.material-requests.index',
        'admin.production.expenses.index',
    ] as $dataTableRoute) {
        $dataTable = $this->actingAs($fixture['user'])->withSession($session)
            ->getJson(route($dataTableRoute, ['draw' => 1, 'start' => 0, 'length' => 10]));
        $dataTable->assertOk();
        expect($dataTable->json('error'))->toBeNull();
    }

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.approve', $order))
        ->assertOk()
        ->assertJsonPath('status', MaintenanceWorkOrder::StatusApproved);
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.approve', $order))
        ->assertStatus(422);
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.start', $order))
        ->assertOk()
        ->assertJsonPath('status', MaintenanceWorkOrder::StatusInProgress);
    expect($fixture['mold']->fresh()->status)->toBe(ProductionMold::StatusMaintenance);
    $order->update(['actual_start_at' => now()->subHours(2)]);
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.events.store', [$order, 'pause']), [
        'occurred_at' => now()->subMinutes(90)->toDateTimeString(),
        'reason' => 'Waiting for the approved external workshop pickup.',
    ])->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.events.store', [$order, 'pause']), [
        'occurred_at' => now()->subMinutes(80)->toDateTimeString(),
        'reason' => 'Duplicate pause must be rejected.',
    ])->assertUnprocessable();
    $this->actingAs($fixture['user'])->withSession($session)->post(route('admin.maintenance.orders.complete', $order), [
        'diagnosis' => 'Pending external diagnosis.', 'work_performed' => 'Work is paused.', 'test_result' => 'failed',
    ])->assertSessionHasErrors('order');
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.events.store', [$order, 'resume']), [
        'occurred_at' => now()->subMinutes(60)->toDateTimeString(),
        'notes' => 'Workshop pickup arrived.',
    ])->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.events.store', [$order, 'external-dispatch']), [
        'occurred_at' => now()->subMinutes(50)->toDateTimeString(),
        'recipient' => 'Certified Motor Workshop courier',
        'item_condition' => 'Motor isolated and tagged; shaft does not rotate freely.',
        'accessories' => 'Motor, coupling, and mounting bolts',
    ])->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->post(route('admin.maintenance.orders.complete', $order), [
        'diagnosis' => 'External repair pending.', 'work_performed' => 'Motor dispatched.', 'test_result' => 'failed',
    ])->assertSessionHasErrors('order');
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.events.store', [$order, 'external-receive']), [
        'occurred_at' => now()->subMinutes(20)->toDateTimeString(),
        'item_condition' => 'Motor returned with free shaft rotation and workshop test certificate.',
        'accessories' => 'Motor, coupling, mounting bolts, and certificate',
    ])->assertOk();
    $order->refresh();
    expect($order->events)->toHaveCount(4)
        ->and($order->total_paused_minutes)->toBe(30)
        ->and($order->paused_at)->toBeNull()
        ->and($order->external_in_transit)->toBeFalse();

    $beforeMaintenanceIssue = app(InventoryAvailabilityService::class)->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['raw']->getKey())['physical_on_hand'];
    $this->actingAs($fixture['user'])->withSession($session)->post(route('admin.maintenance.material-requests.store'), [
        'maintenance_work_order_id' => $order->getKey(), 'branch_store_id' => $fixture['store']->getKey(), 'reason' => 'Bearing service materials',
        'lines' => [
            ['product_id' => $fixture['raw']->getKey(), 'item_type' => 'oil', 'quantity' => '3'],
        ],
    ])->assertRedirect(route('admin.maintenance.material-requests.index'));
    $materialRequest = MaintenanceMaterialRequest::query()->sole();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.material-requests.approve', $materialRequest))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.material-requests.issue', $materialRequest))->assertOk();
    $materialRequest->refresh();
    expect($materialRequest->issueDocument?->status)->toBe(InventoryDocument::StatusPosted)
        ->and(app(InventoryAvailabilityService::class)->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['raw']->getKey())['physical_on_hand'])->toBe(bcsub($beforeMaintenanceIssue, '3', 8));
    $currency = Currency::query()->where('company_id', $fixture['company']->getKey())->firstOrFail();
    $cashAccount = Account::query()->where('company_id', $fixture['company']->getKey())->where('is_postable', true)->where('account_type', Account::TypeAsset)->firstOrFail();
    $expenseAccount = Account::query()->where('company_id', $fixture['company']->getKey())->where('is_postable', true)->where('account_type', Account::TypeExpense)->firstOrFail();
    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(), 'account_id' => $cashAccount->getKey(),
        'name' => 'Maintenance Cashbox', 'status' => 'active',
    ]);
    CashboxCurrency::query()->create(['cashbox_id' => $cashbox->getKey(), 'currency_id' => $currency->getKey(), 'is_default' => true, 'status' => 'active']);
    $this->actingAs($fixture['user'])->withSession($session)->post(route('admin.maintenance.expenses.store'), [
        'maintenance_work_order_id' => $order->getKey(), 'amount' => '125', 'currency_id' => $currency->getKey(),
        'payment_channel' => 'cashbox', 'cashbox_id' => $cashbox->getKey(), 'expense_account_id' => $expenseAccount->getKey(), 'reason' => 'External technician transport',
    ])->assertRedirect(route('admin.maintenance.expenses.index'));
    $expense = ProductionExpenseRequest::query()->where('maintenance_work_order_id', $order->getKey())->sole();
    $ordinaryProductionExpense = ProductionExpenseRequest::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('production_expense_requests', ProductionExpenseRequest::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'request_date' => now()->toDateString(),
        'amount' => '10',
        'currency_id' => $currency->getKey(),
        'payment_channel' => 'cashbox',
        'cashbox_id' => $cashbox->getKey(),
        'expense_account_id' => $expenseAccount->getKey(),
        'reason' => 'Ordinary production-only expense',
        'status' => ProductionExpenseRequest::StatusSubmitted,
        'submitted_by' => $fixture['user']->getKey(),
        'submitted_at' => now(),
        'created_by' => $fixture['user']->getKey(),
    ]);
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.maintenance.expenses.approve', $ordinaryProductionExpense))
        ->assertNotFound();
    expect($ordinaryProductionExpense->fresh()->status)->toBe(ProductionExpenseRequest::StatusSubmitted);
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.expenses.approve', $expense))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.expenses.pay', $expense))->assertOk();
    $expense->refresh()->load(['cashVoucher', 'journalEntry.lines']);
    expect($expense->status)->toBe(ProductionExpenseRequest::StatusPaid)
        ->and($expense->cashVoucher)->not->toBeNull()
        ->and($expense->journalEntry)->not->toBeNull()
        ->and((string) $expense->journalEntry->lines->sum('debit_amount'))->toBe((string) $expense->journalEntry->lines->sum('credit_amount'));
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.expenses.reverse', $expense), ['reason' => 'Duplicate technician transport request'])->assertOk();
    $expense->refresh()->load('reversalJournalEntry.lines');
    expect($expense->status)->toBe(ProductionExpenseRequest::StatusReversed)
        ->and($expense->reversalJournalEntry)->not->toBeNull()
        ->and((string) $expense->reversalJournalEntry->lines->sum('debit_amount'))->toBe((string) $expense->reversalJournalEntry->lines->sum('credit_amount'));
    $materialLine = $materialRequest->lines()->sole();
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.complete', $order), [
            'diagnosis' => 'Bearing seizure caused overload.',
            'root_cause' => 'Lubrication interval was exceeded.',
            'work_performed' => 'Bearings replaced but the first load test failed.',
            'test_result' => 'failed',
            'material_usage' => [['line_id' => $materialLine->getKey(), 'consumed_quantity' => '2']],
        ])
        ->assertRedirect(route('admin.maintenance.orders.index'));
    expect($order->refresh()->status)->toBe(MaintenanceWorkOrder::StatusInProgress)
        ->and($order->test_result)->toBe('failed')
        ->and($order->machine_released_at)->toBeNull()
        ->and($fixture['mold']->fresh()->status)->toBe(ProductionMold::StatusMaintenance)
        ->and($materialRequest->fresh()->inventory_return_document_id)->toBeNull();

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.complete', $order), [
            'diagnosis' => 'Bearing seizure caused overload.',
            'root_cause' => 'Lubrication interval was exceeded.',
            'work_performed' => 'Bearings replaced and motor load tested.',
            'completion_notes' => 'Machine returned to production.',
            'test_result' => 'passed',
            'repair_outcome' => 'permanent',
            'material_usage' => [['line_id' => $materialLine->getKey(), 'consumed_quantity' => '2']],
            'labor_details' => [['name' => 'Lead Maintenance Engineer', 'discipline' => 'electrical', 'actual_hours' => '2.5']],
            'next_due_date' => now()->addMonths(3)->toDateString(),
        ])
        ->assertRedirect(route('admin.maintenance.orders.index'));
    $materialRequest->refresh();
    expect($materialRequest->returnDocument?->status)->toBe(InventoryDocument::StatusPosted)
        ->and($materialLine->refresh()->consumed_quantity)->toBe('2.00000000')
        ->and($materialLine->returned_quantity)->toBe('1.00000000')
        ->and(app(InventoryAvailabilityService::class)->forProduct($fixture['company']->getKey(), $fixture['store']->getKey(), $fixture['raw']->getKey())['physical_on_hand'])->toBe(bcsub($beforeMaintenanceIssue, '2', 8))
        ->and($fixture['mold']->fresh()->status)->toBe(ProductionMold::StatusAvailable);
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.maintenance.material-requests.return', $materialRequest))
        ->assertUnprocessable();
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.close', $order))
        ->assertOk()
        ->assertJsonPath('status', MaintenanceWorkOrder::StatusClosed);

    expect($order->refresh()->status)->toBe(MaintenanceWorkOrder::StatusClosed)
        ->and($order->actual_start_at)->not->toBeNull()
        ->and($order->actual_end_at)->not->toBeNull()
        ->and($order->machine_released_at)->not->toBeNull()
        ->and($order->cost_closed_at)->not->toBeNull()
        ->and($order->labor_details)->toHaveCount(1)
        ->and($maintenanceRequest->refresh()->status)->toBe(MaintenanceRequest::StatusClosed);

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.store'), [
            'fixed_asset_id' => $asset->getKey(),
            'maintenance_type' => 'preventive',
            'discipline' => 'mechanical',
            'priority' => 'normal',
            'service_mode' => 'internal',
            'planned_start_at' => now()->addDay()->toDateTimeString(),
            'planned_end_at' => now()->addDay()->addHours(2)->toDateTimeString(),
            'work_description' => 'Internal preventive inspection and lubrication.',
            'next_due_date' => now()->addMonths(2)->toDateString(),
        ])
        ->assertRedirect(route('admin.maintenance.orders.index'));
    $internalOrder = MaintenanceWorkOrder::query()->where('id', '<>', $order->getKey())->sole();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.approve', $internalOrder))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.start', $internalOrder))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.complete', $internalOrder), [
            'diagnosis' => 'Preventive interval reached.',
            'root_cause' => 'Scheduled maintenance.',
            'work_performed' => 'Inspected and lubricated internally.',
            'completion_notes' => 'Asset is ready.',
            'test_result' => 'passed',
            'repair_outcome' => 'permanent',
            'next_due_date' => now()->addMonths(2)->toDateString(),
        ])
        ->assertRedirect(route('admin.maintenance.orders.index'));
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.close', $internalOrder))->assertOk();
    expect($internalOrder->refresh()->status)->toBe(MaintenanceWorkOrder::StatusClosed)
        ->and($internalOrder->service_mode)->toBe('internal')
        ->and($internalOrder->supplier_id)->toBeNull()
        ->and($internalOrder->external_provider_name)->toBeNull();

    $pdf = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.orders.print', $order));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($pdf->getContent(), '%PDF-'))->toBeTrue();
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.orders.export'))
        ->assertOk()
        ->assertDownload();
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.reports.index'))
        ->assertOk()
        ->assertSee('Maintenance Operational Reports')
        ->assertSee($order->doc_num)
        ->assertSee($internalOrder->doc_num)
        ->assertSee('Issued: 3 / Consumed: 2 / Returned: 1');
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.reports.export'))
        ->assertOk()
        ->assertDownload();
    $maintenanceReportPdf = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.reports.print'));
    $maintenanceReportPdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($maintenanceReportPdf->getContent(), '%PDF-'))->toBeTrue();

    $fixture['user']->revokePermissionTo('maintenance.reports.financial');
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.reports.index'))
        ->assertOk()
        ->assertDontSee('Expense Totals');
});

test('an approved maintenance plan generates idempotent calendar and meter dues without repeated order approval', function (): void {
    $fixture = manufacturingInventoryFixture();
    $asset = FixedAsset::query()->create([
        'doc_number' => 9920,
        'doc_num' => 'FA-PLAN-9920',
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'period_id' => $fixture['period']->getKey(),
        'account_id' => manufacturingMaintenanceAssetAccount($fixture)->getKey(),
        'asset_date' => now()->toDateString(),
        'asset_name' => 'Planned Maintenance Asset',
        'status' => FixedAsset::StatusActive,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $permissions = [
        'maintenance.plans.view', 'maintenance.plans.create', 'maintenance.plans.approve',
        'maintenance.plans.generate', 'maintenance.plans.execute', 'maintenance.plans.readings',
        'maintenance.orders.view', 'maintenance.orders.start', 'maintenance.orders.complete', 'maintenance.orders.close',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    $firstDueAt = now()->subDay()->startOfMinute();

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.plans.store'), [
            'fixed_asset_id' => $asset->getKey(),
            'name' => 'Monthly safety and lubrication round',
            'maintenance_type' => 'preventive',
            'discipline' => 'mechanical',
            'service_mode' => 'internal',
            'frequency_basis' => 'calendar',
            'interval_value' => '30',
            'schedule_anchor' => 'planned',
            'next_due_at' => $firstDueAt->toDateTimeString(),
            'task_template' => 'Inspect safety guards and lubricate the approved points.',
            'expected_duration_minutes' => 90,
            'estimated_cost' => 0,
        ])
        ->assertRedirect(route('admin.maintenance.plans.index'));
    $plan = MaintenancePlan::query()->sole();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.maintenance.plans.generate', $plan))
        ->assertUnprocessable();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.maintenance.plans.approve', $plan))
        ->assertOk();
    $plan->refresh();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.maintenance.plans.generate', $plan))
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.maintenance.plans.generate', $plan))
        ->assertUnprocessable();
    $due = MaintenancePlanDue::query()->sole();
    expect(MaintenancePlanDue::query()->count())->toBe(1)
        ->and($plan->fresh()->next_due_at?->equalTo($firstDueAt->copy()->addDays(30)))->toBeTrue();

    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.maintenance.plans.dues.convert', $due))
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.maintenance.plans.dues.convert', $due))
        ->assertOk();
    $order = MaintenanceWorkOrder::query()->sole();
    expect($order->status)->toBe(MaintenanceWorkOrder::StatusApproved)
        ->and($order->approved_by)->toBe($plan->approved_by)
        ->and($order->maintenance_plan_due_id)->toBe($due->getKey())
        ->and(MaintenanceWorkOrder::query()->count())->toBe(1);

    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.start', $order))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.orders.complete', $order), [
            'diagnosis' => 'Approved periodic work became due.',
            'work_performed' => 'Safety guards inspected and approved points lubricated.',
            'test_result' => 'passed',
            'repair_outcome' => 'permanent',
        ])
        ->assertRedirect(route('admin.maintenance.orders.index'));
    expect($due->fresh()->status)->toBe(MaintenancePlanDue::StatusCompleted)
        ->and($plan->fresh()->last_completed_at)->not->toBeNull();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.orders.close', $order))->assertOk();

    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.maintenance.plans.store'), [
            'fixed_asset_id' => $asset->getKey(),
            'name' => 'Motor service by operating hours',
            'maintenance_type' => 'preventive',
            'service_mode' => 'internal',
            'frequency_basis' => 'operating_hours',
            'interval_value' => '50',
            'schedule_anchor' => 'actual',
            'next_meter_value' => '100',
            'task_template' => 'Inspect motor after the approved operating-hours interval.',
        ])
        ->assertRedirect(route('admin.maintenance.plans.index'));
    $meterPlan = MaintenancePlan::query()->whereKeyNot($plan->getKey())->sole();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.plans.approve', $meterPlan))->assertOk();
    $idempotencyKey = (string) Str::uuid();
    $readingData = [
        'basis' => 'operating_hours', 'reading_value' => '120', 'reading_type' => 'reading',
        'recorded_at' => now()->toDateTimeString(), 'idempotency_key' => $idempotencyKey,
    ];
    $this->actingAs($fixture['user'])->withSession($session)->post(route('admin.maintenance.plans.readings.store', $meterPlan), $readingData)->assertRedirect();
    $this->actingAs($fixture['user'])->withSession($session)->post(route('admin.maintenance.plans.readings.store', $meterPlan), $readingData)->assertRedirect();
    expect(MaintenanceMeterReading::query()->count())->toBe(1);
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.plans.generate', $meterPlan))->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)->postJson(route('admin.maintenance.plans.generate', $meterPlan))->assertOk();
    expect(MaintenancePlanDue::query()->where('maintenance_plan_id', $meterPlan->getKey())->count())->toBe(1);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.maintenance.plans.index'))
        ->assertOk()
        ->assertSee('Monthly safety and lubrication round')
        ->assertSee('Motor service by operating hours');
});

test('inventory and production screens translate labels without changing status values', function (string $locale): void {
    $fixture = manufacturingInventoryFixture();
    $factoryBranch = Branch::query()->create([
        ...app(DocumentNumberService::class)->next('branches', Branch::class),
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Manufacturing Test Factory',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $permissions = ['inventory.documents.create', 'inventory.documents.adjust', 'production.orders.view'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $fixture['user']->forceFill(['locale' => $locale])->save();
    $session = [
        'locale' => $locale,
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $factoryBranch->getKey(),
        OperatingContextService::BranchDocNumKey => $factoryBranch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    $arabic = $locale === 'ar';

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.inventory.documents.create'))
        ->assertOk()
        ->assertSee($arabic ? 'حركة مخزون جديدة' : 'New Inventory Movement')
        ->assertSee($arabic ? 'تسوية زيادة مخزون' : 'Stock Surplus Adjustment');

    $order = app(ProductionCycleService::class)->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $factoryBranch->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '2',
    ]]);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.production.work-orders.index'))
        ->assertOk()
        ->assertSee($arabic ? 'أوامر الإنتاج' : 'Production Orders')
        ->assertSee('data-server-table', false);

    $response = $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.production.work-orders.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
        ->assertOk()
        ->assertSee($order->doc_num);

    expect((string) data_get($response->json(), 'data.0.status'))
        ->toContain($arabic ? 'مسودة' : 'Draft');
})->with(['ar', 'en']);

test('operational dashboard cards equal their scoped report counts and exclude terminal and foreign branch records', function (): void {
    $fixture = manufacturingInventoryFixture();
    $fixture['raw']->update(['reorder_point' => '1100']);
    $fixture['finished']->update(['reorder_point' => null]);

    $otherBranch = Branch::query()->create([
        ...app(DocumentNumberService::class)->next('branches', Branch::class),
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Foreign Dashboard Branch',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $otherStore = BranchStore::query()->create(['branch_id' => $otherBranch->getKey(), 'name' => 'Foreign Store', 'position' => 1]);
    InventoryTransaction::query()->create([
        'posting_key' => 'foreign-dashboard-stock', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $otherBranch->getKey(),
        'branch_store_id' => $otherStore->getKey(), 'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => now()->toDateString(), 'transaction_type' => 'opening_stock',
        'product_id' => $fixture['raw']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'quantity_in' => '5000',
        'quantity_out' => 0, 'source_type' => 'test', 'source_id' => 2,
        'source_doc_num' => 'OPEN-FOREIGN', 'unit_cost' => '2', 'total_cost' => '10000',
        'created_by' => $fixture['user']->getKey(),
    ]);

    $cycle = app(ProductionCycleService::class);
    $remainingOrder = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'quantity' => '10',
    ]]);
    $remainingOrder = $cycle->releaseOrder($remainingOrder);
    $completedOrder = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'quantity' => '3',
    ]]);
    $completedOrder->lines()->update(['received_base_quantity' => '3']);
    $completedOrder->update(['status' => ProductionOrder::StatusCompleted]);
    $foreignOrder = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $otherBranch->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'quantity' => '7',
    ]]);
    $foreignOrder = $cycle->releaseOrder($foreignOrder);

    $shortageRun = $cycle->createRun($remainingOrder->lines->firstOrFail(), [
        'planned_quantity' => '1', 'planned_start_at' => now()->addHour(), 'planned_end_at' => now()->addHours(2),
    ]);
    $shortageRequirement = $shortageRun->requirements->firstOrFail();
    foreach ([
        ['number' => 99191, 'document' => 'PMR-DASH-SHORTAGE', 'status' => ProductionMaterialRequest::StatusShortage],
        ['number' => 99192, 'document' => 'PMR-DASH-ISSUED', 'status' => ProductionMaterialRequest::StatusIssued],
    ] as $materialRequestFixture) {
        $materialRequest = ProductionMaterialRequest::query()->create([
            'doc_number' => $materialRequestFixture['number'], 'doc_num' => $materialRequestFixture['document'],
            'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(), 'branch_store_id' => $fixture['store']->getKey(),
            'production_order_id' => $remainingOrder->getKey(), 'production_run_id' => $shortageRun->getKey(),
            'request_date' => now()->toDateString(), 'status' => $materialRequestFixture['status'],
            'created_by' => $fixture['user']->getKey(),
        ]);
        $materialRequest->lines()->create([
            'line_number' => 1, 'production_material_requirement_id' => $shortageRequirement->getKey(),
            'product_id' => $fixture['raw']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'planned_quantity' => '2', 'requested_quantity' => '2', 'shortage_quantity' => '2',
        ]);
    }

    foreach ([
        ['number' => 99201, 'document' => 'QI-DASH-PENDING', 'branch' => $fixture['branch'], 'order' => $remainingOrder, 'status' => ProductionQualityInspection::StatusDraft, 'result' => 'pending'],
        ['number' => 99202, 'document' => 'QI-DASH-CLOSED', 'branch' => $fixture['branch'], 'order' => $remainingOrder, 'status' => ProductionQualityInspection::StatusApproved, 'result' => 'passed'],
        ['number' => 99203, 'document' => 'QI-DASH-FOREIGN', 'branch' => $otherBranch, 'order' => $foreignOrder, 'status' => ProductionQualityInspection::StatusDraft, 'result' => 'pending'],
    ] as $inspection) {
        ProductionQualityInspection::query()->create([
            'doc_number' => $inspection['number'], 'doc_num' => $inspection['document'],
            'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $inspection['branch']->getKey(), 'production_order_id' => $inspection['order']->getKey(),
            'subject_type' => ProductionQualityInspection::SubjectProduct, 'product_id' => $fixture['finished']->getKey(),
            'inspection_date' => now()->toDateString(), 'requested_at' => now(), 'sampled_at' => now(),
            'status' => $inspection['status'], 'result' => $inspection['result'], 'affected_base_quantity' => '1',
            'created_by' => $fixture['user']->getKey(),
        ]);
    }

    $asset = FixedAsset::query()->create([
        'doc_number' => 99210, 'doc_num' => 'FA-DASH-99210', 'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'period_id' => $fixture['period']->getKey(),
        'asset_date' => now()->toDateString(), 'asset_name' => 'Dashboard Machine', 'status' => FixedAsset::StatusActive,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $foreignAsset = FixedAsset::query()->create([
        'doc_number' => 99211, 'doc_num' => 'FA-DASH-99211', 'company_id' => $fixture['company']->getKey(),
        'branch_id' => $otherBranch->getKey(), 'period_id' => $fixture['period']->getKey(),
        'asset_date' => now()->toDateString(), 'asset_name' => 'Foreign Dashboard Machine', 'status' => FixedAsset::StatusActive,
        'created_by' => $fixture['user']->getKey(),
    ]);
    foreach ([
        ['number' => 99220, 'document' => 'MR-DASH-OPEN', 'branch' => $fixture['branch'], 'asset' => $asset, 'status' => MaintenanceRequest::StatusOpen],
        ['number' => 99221, 'document' => 'MR-DASH-CLOSED', 'branch' => $fixture['branch'], 'asset' => $asset, 'status' => MaintenanceRequest::StatusClosed],
        ['number' => 99222, 'document' => 'MR-DASH-FOREIGN', 'branch' => $otherBranch, 'asset' => $foreignAsset, 'status' => MaintenanceRequest::StatusOpen],
    ] as $maintenance) {
        MaintenanceRequest::query()->create([
            'doc_number' => $maintenance['number'], 'doc_num' => $maintenance['document'],
            'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $maintenance['branch']->getKey(), 'fixed_asset_id' => $maintenance['asset']->getKey(),
            'reported_at' => now(), 'request_type' => 'breakdown', 'priority' => 'urgent',
            'symptoms' => 'Deterministic dashboard fixture', 'is_machine_stopped' => true,
            'status' => $maintenance['status'], 'created_by' => $fixture['user']->getKey(),
        ]);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permissions = ['inventory.reports.operational', 'production.reports.operational', 'maintenance.reports.view'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $session = [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(), OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(), OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(), OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];

    $dashboard = $this->actingAs($fixture['user'])->withSession($session)->get(route('dashboard'))->assertOk()->getContent();
    $inventory = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.inventory.reports.index', ['as_of' => now()->toDateString(), 'operational_focus' => 'low_stock']))->assertOk()->getContent();
    $production = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.production.reports.orders', ['operational_focus' => 'remaining']))->assertOk()->getContent();
    $materials = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.production.reports.materials', ['operational_focus' => 'shortage']))->assertOk()->getContent();
    $quality = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.production.reports.quality', ['operational_focus' => 'pending']))->assertOk()->getContent();
    $rejectedQuality = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.production.reports.quality', ['operational_focus' => 'rejected']))->assertOk()->getContent();
    $maintenance = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.maintenance.reports.index', ['operational_focus' => 'breakdown']))->assertOk()->getContent();
    $overdueMaintenance = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.maintenance.reports.index', ['operational_focus' => 'overdue']))->assertOk()->getContent();

    expect(operationalDashboardCount($dashboard, 'low_stock'))->toBe(1)
        ->and(operationalDashboardCount($dashboard, 'low_stock'))->toBe(operationalReportCount($inventory, 'low_stock'))
        ->and(operationalDashboardCount($dashboard, 'production_remaining'))->toBe(1)
        ->and(operationalDashboardCount($dashboard, 'production_remaining'))->toBe(operationalReportCount($production, 'production_remaining'))
        ->and(operationalDashboardCount($dashboard, 'material_shortages'))->toBe(1)
        ->and(operationalDashboardCount($dashboard, 'material_shortages'))->toBe(operationalReportCount($materials, 'material_shortages'))
        ->and(operationalDashboardCount($dashboard, 'quality_pending'))->toBe(1)
        ->and(operationalDashboardCount($dashboard, 'quality_pending'))->toBe(operationalReportCount($quality, 'quality_pending'))
        ->and(operationalDashboardCount($dashboard, 'quality_rejected'))->toBe(0)
        ->and(operationalDashboardCount($dashboard, 'quality_rejected'))->toBe(operationalReportCount($rejectedQuality, 'quality_rejected'))
        ->and(operationalDashboardCount($dashboard, 'maintenance_breakdowns'))->toBe(1)
        ->and(operationalDashboardCount($dashboard, 'maintenance_breakdowns'))->toBe(operationalReportCount($maintenance, 'maintenance_breakdowns'))
        ->and(operationalDashboardCount($dashboard, 'maintenance_overdue'))->toBe(0)
        ->and(operationalDashboardCount($dashboard, 'maintenance_overdue'))->toBe(operationalReportCount($overdueMaintenance, 'maintenance_overdue'));

    $limited = User::factory()->create(['locale' => 'ar']);
    $limited->givePermissionTo('inventory.reports.operational');
    $arabicDashboard = $this->actingAs($limited)->withSession([...$session, 'locale' => 'ar'])->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('dashboard.plastics.metrics.low_stock.title'))
        ->getContent();
    expect($arabicDashboard)->toContain('data-operational-card="low_stock"')
        ->not->toContain('data-operational-card="production_remaining"');

    expect(app(ProductionReportService::class)->remainingOrders($fixture['company']->getKey(), [
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
    ])->pluck('doc_num')->all())->toBe([$remainingOrder->doc_num]);
});

test('generic inventory reversal rejects production documents without changing stock or production counters', function (): void {
    $fixture = manufacturingInventoryFixture();
    ['cycle' => $cycle, 'order' => $order, 'run' => $run] = manufacturingIntegrityRun($fixture);
    $cycle->reserveRun($run, $fixture['store']->getKey());
    $document = $cycle->issueMaterials($run, $fixture['store']->getKey());
    $requirement = $run->requirements()->firstOrFail();
    $transactionCount = InventoryTransaction::query()->count();
    $journalCount = JournalEntry::query()->count();

    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($document))
        ->toThrow(DomainException::class, __('Production-linked inventory documents must be reversed through the production workflow.'));

    expect($document->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount)
        ->and(InventoryTransaction::query()->whereNotNull('reversal_of_id')->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and($requirement->fresh()->issued_quantity)->toBe('2.00000000')
        ->and($run->fresh()->status)->toBe(ProductionRun::StatusPlanned)
        ->and($order->fresh()->status)->toBe(ProductionOrder::StatusReleased);

    $originalSourceType = $document->source_document_type;
    $originalSourceId = $document->source_document_id;
    $document->update([
        'production_order_id' => null,
        'production_run_id' => null,
        'source_document_type' => 'test-source',
        'source_document_id' => null,
    ]);
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($document->fresh()))
        ->toThrow(DomainException::class, __('Production-linked inventory documents must be reversed through the production workflow.'));
    $document->update(['source_document_type' => ProductionQualityInspection::class]);
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($document->fresh()))
        ->toThrow(DomainException::class, __('production_execution.messages.quality_inventory_document_controlled'));
    $document->update([
        'production_order_id' => $order->getKey(),
        'production_run_id' => $run->getKey(),
        'source_document_type' => $originalSourceType,
        'source_document_id' => $originalSourceId,
    ]);

    expect(fn () => $cycle->issueMaterials($run, $fixture['store']->getKey()))
        ->toThrow(DomainException::class, __('No positive material issue quantities were supplied.'));
    expect(InventoryDocument::query()->where('production_run_id', $run->getKey())->count())->toBe(1)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount)
        ->and($requirement->fresh()->issued_quantity)->toBe('2.00000000');
});

test('production run creation requires a token and replays one created run', function (): void {
    $fixture = manufacturingInventoryFixture();
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->createMakeToStockOrder([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
    ], [[
        'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '1',
    ]]);
    $orderLine = $cycle->releaseOrder($order)->lines->firstOrFail();
    Permission::findOrCreate('production.runs.plan', 'web');
    $fixture['user']->givePermissionTo('production.runs.plan');
    $session = manufacturingIntegritySession($fixture);
    $payload = [
        'production_order_line_id' => $orderLine->getKey(),
        'production_machine_public_id' => $fixture['machine']->public_id,
        'planned_quantity' => '1',
        'planned_start_at' => now()->addHour()->toDateTimeString(),
        'planned_end_at' => now()->addHours(2)->toDateTimeString(),
        'batch_lot' => 'IDEMPOTENT-RUN-001',
    ];
    $url = route('admin.production.runs.store');

    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson($url, $payload)
        ->assertUnprocessable();
    expect(ProductionRun::query()->count())->toBe(0);

    $token = (string) Str::uuid();
    $first = $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $token)
        ->postJson($url, $payload)
        ->assertCreated();
    $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $token)
        ->postJson($url, $payload)
        ->assertCreated()
        ->assertExactJson($first->json());

    expect(ProductionRun::query()->count())->toBe(1)
        ->and(DB::table('document_submissions')->count())->toBe(1);
});

test('production action retries replay material issue and progress once and reject changed payloads', function (): void {
    $fixture = manufacturingInventoryFixture();
    ['cycle' => $cycle, 'run' => $run] = manufacturingIntegrityRun($fixture);
    $requirement = $run->requirements()->firstOrFail();
    $cycle->reserveRun($run, $fixture['store']->getKey());

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['production.runs.issue', 'production.runs.progress'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo(['production.runs.issue', 'production.runs.progress']);
    $session = manufacturingIntegritySession($fixture);
    $issueToken = (string) Str::uuid();
    $issuePayload = [
        'branch_store_id' => $fixture['store']->getKey(),
        'lines' => [['requirement_id' => $requirement->getKey(), 'quantity' => '2']],
    ];
    $issueUrl = route('admin.production.runs.issue', $run);

    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson($issueUrl, $issuePayload)
        ->assertUnprocessable();
    expect(InventoryDocument::query()->where('production_run_id', $run->getKey())->count())->toBe(0)
        ->and($requirement->fresh()->issued_quantity)->toBe('0.00000000')
        ->and(DB::table('document_submissions')->count())->toBe(0);

    $firstIssue = $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $issueToken)
        ->postJson($issueUrl, $issuePayload)
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $issueToken)
        ->postJson($issueUrl, $issuePayload)
        ->assertOk()
        ->assertExactJson($firstIssue->json());
    $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $issueToken)
        ->postJson($issueUrl, [...$issuePayload, 'lines' => [['requirement_id' => $requirement->getKey(), 'quantity' => '1']]])
        ->assertConflict();

    expect(InventoryDocument::query()->where('production_run_id', $run->getKey())->where('document_type', InventoryDocument::TypeMaterialIssue)->count())->toBe(1)
        ->and($requirement->fresh()->issued_quantity)->toBe('2.00000000');

    $cycle->startSetup($run);
    $cycle->completeSetup($run);
    $cycle->startRun($run);
    $progressToken = (string) Str::uuid();
    $progressPayload = ['good_base_quantity' => '1', 'notes' => 'Idempotent production progress'];
    $progressUrl = route('admin.production.runs.progress', $run);
    $firstProgress = $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $progressToken)
        ->postJson($progressUrl, $progressPayload)
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $progressToken)
        ->postJson($progressUrl, $progressPayload)
        ->assertOk()
        ->assertExactJson($firstProgress->json());
    $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $progressToken)
        ->postJson($progressUrl, [...$progressPayload, 'good_base_quantity' => '0.5'])
        ->assertConflict();

    expect(ProductionProgressEntry::query()->where('production_run_id', $run->getKey())->count())->toBe(1)
        ->and($run->fresh()->good_base_quantity)->toBe('1.00000000')
        ->and(DB::table('document_submissions')->count())->toBe(2);
});

test('quality report upload retries hash file content and recheck permission before replay', function (): void {
    Storage::fake('public');
    $fixture = manufacturingInventoryFixture();
    foreach (['production.quality.create', 'production.quality.receive', 'production.quality.start', 'production.quality.report'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo(['production.quality.create', 'production.quality.receive', 'production.quality.start', 'production.quality.report']);
    $session = manufacturingIntegritySession($fixture);
    $this->actingAs($fixture['user'])->withSession($session)
        ->post(route('admin.production.quality.store'), [
            '_submission_token' => (string) Str::uuid(),
            'subject_type' => ProductionQualityInspection::SubjectProduct,
            'product_id' => $fixture['raw']->getKey(),
            'affected_base_quantity' => '1',
        ])
        ->assertRedirect();
    $inspection = ProductionQualityInspection::query()->sole();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.receive', $inspection))
        ->assertOk();
    $this->actingAs($fixture['user'])->withSession($session)
        ->postJson(route('admin.production.quality.start', $inspection))
        ->assertOk();
    $token = (string) Str::uuid();
    $url = route('admin.production.quality.reports.store', $inspection);
    $payload = [
        'reported_at' => now()->toDateTimeString(),
        'result' => 'passed',
        'observations' => 'File fingerprint retry evidence',
    ];
    $sameFile = fn (): UploadedFile => UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\nsame-content");
    $changedFile = fn (): UploadedFile => UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\ndiff-content");

    $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Accept', 'application/json')
        ->post($url, [...$payload, 'evidence_files' => [$sameFile()]])
        ->assertUnprocessable();
    expect($inspection->reports()->count())->toBe(0);

    $first = $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $token)
        ->post($url, [...$payload, 'evidence_files' => [$sameFile()]])
        ->assertRedirect(route('admin.production.quality.show', $inspection));
    $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $token)
        ->post($url, [...$payload, 'evidence_files' => [$sameFile()]])
        ->assertStatus($first->status())
        ->assertRedirect(route('admin.production.quality.show', $inspection));
    $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $token)
        ->post($url, [...$payload, 'evidence_files' => [$changedFile()]])
        ->assertConflict();

    expect($inspection->reports()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles('production-quality/reports'))->toHaveCount(1);

    $fixture['user']->revokePermissionTo('production.quality.report');
    $fixture['user']->unsetRelation('permissions');
    $this->actingAs($fixture['user'])->withSession($session)
        ->withHeader('Idempotency-Key', $token)
        ->post($url, [...$payload, 'evidence_files' => [$sameFile()]])
        ->assertForbidden();
    expect($inspection->reports()->count())->toBe(1);
});

test('material issue rolls back reservations layers accounting and counters when posting completion fails', function (): void {
    $fixture = manufacturingInventoryFixture();
    ['cycle' => $cycle, 'run' => $run] = manufacturingIntegrityRun($fixture);
    $cycle->reserveRun($run, $fixture['store']->getKey());
    $requirement = $run->requirements()->firstOrFail();
    $reservationSnapshot = InventoryReservation::query()
        ->where('production_run_id', $run->getKey())
        ->get(['id', 'quantity', 'consumed_quantity', 'released_quantity', 'status'])
        ->toArray();
    $position = app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['raw']->getKey(),
    );
    $documentCount = InventoryDocument::query()->count();
    $transactionCount = InventoryTransaction::query()->count();
    $journalCount = JournalEntry::query()->count();
    $receiptLayerCount = InventoryReceiptLayer::query()->count();
    $allocationCount = InventoryLayerAllocation::query()->count();
    $failPostedIssue = true;

    InventoryDocument::updated(function (InventoryDocument $candidate) use (&$failPostedIssue, $run): void {
        if ($failPostedIssue
            && (int) $candidate->production_run_id === (int) $run->getKey()
            && $candidate->document_type === InventoryDocument::TypeMaterialIssue
            && $candidate->status === InventoryDocument::StatusPosted) {
            $failPostedIssue = false;

            throw new RuntimeException('Injected failure after material issue posting.');
        }
    });

    expect(fn () => $cycle->issueMaterials($run, $fixture['store']->getKey()))
        ->toThrow(RuntimeException::class, 'Injected failure after material issue posting.');

    expect(InventoryDocument::query()->count())->toBe($documentCount)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount)
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and(InventoryReceiptLayer::query()->count())->toBe($receiptLayerCount)
        ->and(InventoryLayerAllocation::query()->count())->toBe($allocationCount)
        ->and(InventoryReservation::query()->where('production_run_id', $run->getKey())->get(['id', 'quantity', 'consumed_quantity', 'released_quantity', 'status'])->toArray())->toBe($reservationSnapshot)
        ->and($requirement->fresh()->issued_quantity)->toBe('0.00000000')
        ->and($requirement->fresh()->reserved_quantity)->toBe('2.00000000')
        ->and(app(InventoryAvailabilityService::class)->forProduct(
            $fixture['company']->getKey(),
            $fixture['store']->getKey(),
            $fixture['raw']->getKey(),
        ))->toBe($position);
});

test('finished goods receipt rolls back inventory accounting and counters when the counter update fails', function (): void {
    $fixture = manufacturingInventoryFixture();
    ['cycle' => $cycle, 'orderLine' => $orderLine, 'run' => $run] = manufacturingIntegrityRun($fixture);
    $cycle->reserveRun($run, $fixture['store']->getKey());
    $cycle->issueMaterials($run, $fixture['store']->getKey());
    $cycle->startSetup($run);
    $cycle->completeSetup($run);
    $cycle->startRun($run);
    $cycle->recordProgress($run, ['good_base_quantity' => '1']);
    $requirement = $run->requirements()->firstOrFail();
    $cycle->accountMaterials($run, $fixture['store']->getKey(), [
        $requirement->getKey() => ['consumed_quantity' => '2', 'waste_quantity' => '0'],
    ]);
    $documentCount = InventoryDocument::query()->count();
    $transactionCount = InventoryTransaction::query()->count();
    $journalCount = JournalEntry::query()->count();
    $receiptLayerCount = InventoryReceiptLayer::query()->count();
    $allocationCount = InventoryLayerAllocation::query()->count();
    $reservationSnapshot = InventoryReservation::query()->where('production_run_id', $run->getKey())->get()->toArray();
    $requirementSnapshot = $requirement->fresh()->only([
        'reserved_quantity', 'issued_quantity', 'additional_issued_quantity', 'returned_quantity', 'consumed_quantity', 'waste_quantity',
    ]);
    $finishedPosition = app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['finished']->getKey(),
    );
    $progressCount = ProductionProgressEntry::query()->where('production_run_id', $run->getKey())->count();
    $qualityCount = ProductionQualityInspection::query()->where('production_run_id', $run->getKey())->count();
    $failReceiptCounter = true;

    ProductionRun::updating(function (ProductionRun $candidate) use (&$failReceiptCounter, $run): void {
        if ($failReceiptCounter
            && $candidate->is($run)
            && $candidate->isDirty('received_base_quantity')) {
            $failReceiptCounter = false;

            throw new RuntimeException('Injected failure before production receipt counter update.');
        }
    });

    expect(fn () => $cycle->receiveFinishedGoods($run, $fixture['store']->getKey(), '1'))
        ->toThrow(RuntimeException::class, 'Injected failure before production receipt counter update.');

    expect(InventoryDocument::query()->count())->toBe($documentCount)
        ->and(InventoryDocument::query()->where('production_run_id', $run->getKey())->where('document_type', InventoryDocument::TypeProductionReceipt)->count())->toBe(0)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount)
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and(InventoryReceiptLayer::query()->count())->toBe($receiptLayerCount)
        ->and(InventoryLayerAllocation::query()->count())->toBe($allocationCount)
        ->and(InventoryReservation::query()->where('production_run_id', $run->getKey())->get()->toArray())->toBe($reservationSnapshot)
        ->and($requirement->fresh()->only(array_keys($requirementSnapshot)))->toBe($requirementSnapshot)
        ->and(ProductionProgressEntry::query()->where('production_run_id', $run->getKey())->count())->toBe($progressCount)
        ->and(ProductionQualityInspection::query()->where('production_run_id', $run->getKey())->count())->toBe($qualityCount)
        ->and(app(InventoryAvailabilityService::class)->forProduct(
            $fixture['company']->getKey(),
            $fixture['store']->getKey(),
            $fixture['finished']->getKey(),
        ))->toBe($finishedPosition)
        ->and($run->fresh()->good_base_quantity)->toBe('1.00000000')
        ->and($run->fresh()->status)->toBe(ProductionRun::StatusRunning)
        ->and($run->fresh()->received_base_quantity)->toBe('0.00000000')
        ->and($orderLine->fresh()->received_base_quantity)->toBe('0.00000000');
});
