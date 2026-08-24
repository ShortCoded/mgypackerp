<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
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
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryAccountingMapping;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\StockCountService;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\QualityInspectionType;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;

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
    $accountId = fn (string $code): int => (int) Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', $code)
        ->valueOrFail('id');
    InventoryAccountingMapping::query()->create([
        'company_id' => $company->getKey(),
        'raw_material_inventory_account_id' => $accountId('1131'),
        'packaging_inventory_account_id' => $accountId('1134'),
        'semi_finished_inventory_account_id' => $accountId('1132'),
        'finished_goods_inventory_account_id' => $accountId('1133'),
        'wip_account_id' => $accountId('1132'),
        'production_waste_account_id' => $accountId('551'),
        'warehouse_damage_loss_account_id' => $accountId('551'),
        'inventory_adjustment_gain_account_id' => $accountId('432'),
        'inventory_adjustment_loss_account_id' => $accountId('551'),
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
    ]))->toThrow(DomainException::class, 'overlapping production run');

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
        ->toThrow(DomainException::class, 'exceeds available stock');
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
    $cycle->recordProgress($run, [
        'good_base_quantity' => '4',
        'scrap_base_quantity' => '1',
        'notes' => 'First completed production run',
    ]);
    $failedInspection = $cycle->recordInspection($run->fresh(), [
        'quality_inspection_type_id' => $inProcessInspectionType->getKey(),
        'sampled_at' => now()->subMinute(),
        'result' => 'failed',
        'defect_code' => 'QC-DIMENSION',
        'affected_base_quantity' => '1',
        'corrective_action' => 'Verify the mold and resample',
    ]);
    expect($failedInspection->production_run_id)->toBe($run->getKey())
        ->and($run->fresh()->status)->toBe(ProductionRun::StatusHeld)
        ->and(fn () => $cycle->completeRun($run->fresh()))->toThrow(DomainException::class);
    $passedInspection = $cycle->recordInspection($run->fresh(), [
        'quality_inspection_type_id' => $inProcessInspectionType->getKey(),
        'sampled_at' => now(),
        'result' => 'passed',
        'notes' => 'Corrective action verified',
    ]);
    expect($passedInspection->sampled_at->greaterThanOrEqualTo($failedInspection->sampled_at))->toBeTrue();
    $run = $cycle->resumeRun($run->fresh());
    expect($run->status)->toBe(ProductionRun::StatusRunning);

    $documents = $cycle->accountMaterials($run->fresh(), $fixture['store']->getKey(), [
        $requirement->getKey() => ['consumed_quantity' => '10', 'waste_quantity' => '1'],
    ]);
    expect($documents)->toHaveKeys(['consumption', 'waste']);

    expect(fn () => $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '2'))
        ->toThrow(DomainException::class, 'final passed quality inspection');
    $cycle->recordInspection($run->fresh(), [
        'quality_inspection_type_id' => $finalInspectionType->getKey(),
        'sampled_at' => now()->addSecond(),
        'result' => 'passed',
        'notes' => 'Final finished-goods release passed',
    ]);

    $firstReceipt = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '2');
    $secondReceipt = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '2');
    expect($firstReceipt->document_type)->toBe(InventoryDocument::TypeProductionReceipt)
        ->and($firstReceipt->lines->first()->total_cost)->toBe('10.00000000')
        ->and($secondReceipt->lines->first()->total_cost)->toBe('10.00000000')
        ->and($secondReceipt->production_run_id)->toBe($run->getKey());

    $run = $cycle->completeRun($run->fresh());
    expect($run->status)->toBe(ProductionRun::StatusCompleted)
        ->and($run->received_base_quantity)->toBe('4.00000000')
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
        ->toThrow(DomainException::class, 'All issued materials must be returned');
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
        ->toThrow(DomainException::class, 'exceeds unreserved stock');

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
        ->toThrow(DomainException::class, 'Stock changed after the count snapshot');

    $documentCount = InventoryDocument::query()->count();
    $transactionCount = InventoryTransaction::query()->count();
    $fixture['period']->update(['is_closed' => true]);
    expect(fn () => $movement->createAndPost([
        ...$context,
        'document_type' => InventoryDocument::TypeAdjustmentIn,
    ], [['product_id' => $fixture['raw']->getKey(), 'quantity' => '1']]))
        ->toThrow(DomainException::class, 'closed or unrelated financial period');
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($damageDocument))
        ->toThrow(DomainException::class, 'closed or unrelated financial period');
    expect(InventoryDocument::query()->count())->toBe($documentCount)
        ->and(InventoryTransaction::query()->count())->toBe($transactionCount);
});

test('canonical inventory and production pages use real routes and keep html operators in the workflow', function () {
    $fixture = manufacturingInventoryFixture();
    $permissions = [
        'inventory.documents.view',
        'inventory.documents.create',
        'inventory.documents.adjust',
        'inventory.documents.print',
        'production.runs.view',
        'production.runs.print',
        'production.orders.print',
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
        ->assertSee('New Inventory Movement');

    $response = $this->actingAs($fixture['user'])
        ->withSession($session)
        ->post(route('admin.inventory.documents.store'), [
            'branch_store_id' => $fixture['store']->getKey(),
            'document_type' => InventoryDocument::TypeAdjustmentIn,
            'document_date' => now()->toDateString(),
            'movement_reason' => 'HTML operator verification',
            'destination_stock_status' => InventoryTransaction::StatusAvailable,
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
        ->assertSee('In-Process Quality Sample')
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

test('the browser inventory movement contract posts and prints twenty five valued lines', function () {
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
        'product_id' => $fixture['raw']->getKey(),
        'quantity' => '1',
        'unit_cost' => '2',
        'batch_lot' => sprintf('STRESS-%02d', $line),
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
            'lines' => $lines,
        ]);

    $document = InventoryDocument::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('admin.inventory.documents.show', $document));

    expect($document->lines()->count())->toBe(25)
        ->and($document->transactions()->count())->toBe(25)
        ->and((float) $document->journalEntry?->lines()->sum('debit_amount'))->toBe(50.0)
        ->and((float) $document->journalEntry?->lines()->sum('credit_amount'))->toBe(50.0);

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
        'production.runs.qc',
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
        ->post(route($route, $run), $data);

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
    $post('admin.production.runs.inspect', ['result' => 'passed', 'notes' => 'Final 25-component inspection'])->assertRedirect();

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
        ->toThrow(DomainException::class, 'exceeds available stock');

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
        $cycle->recordInspection($run->fresh(), ['result' => 'passed', 'notes' => 'Packing final inspection passed.']);

        if ($goodQuantity === '400') {
            $cycle->receiveFinishedGoods($run->fresh(), $packingStore->getKey(), '200');
            $cycle->receiveFinishedGoods($run->fresh(), $packingStore->getKey(), '200');
        } else {
            $cycle->receiveFinishedGoods($run->fresh(), $packingStore->getKey(), $goodQuantity);
        }

        $cycle->completeRun($run->fresh());
    };

    $completeRun($runOne, '400', true);
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
        ->and(InventoryReservation::query()->where('production_order_id', $productionA->getKey())->where('status', InventoryReservation::StatusActive)->count())->toBe(0);

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
        'inventory.stock_counts.record',
    ]);

    $this->actingAs($operator)->withSession($session)
        ->get(route('admin.inventory.reports.index'))
        ->assertOk()
        ->assertDontSee('<th>Value</th>', false)
        ->assertDontSee('Inventory / Production to General Ledger Reconciliation');
    $this->actingAs($operator)->withSession($session)
        ->get(route('admin.inventory.accounting.index'))
        ->assertForbidden();
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
        'production.resources.view',
    ]);
    $this->actingAs($planner)->withSession($session)
        ->get(route('admin.production.runs.show', $run))
        ->assertOk();
    $this->actingAs($planner)->withSession($session)
        ->post(route('admin.production.runs.issue', $run), ['branch_store_id' => $fixture['store']->getKey()])
        ->assertForbidden();

    $qualityUser = $userWith(['production.runs.view', 'production.runs.qc']);
    $this->actingAs($qualityUser)->withSession($session)
        ->post(route('admin.production.runs.inspect', $run), ['result' => 'passed'])
        ->assertRedirect();
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
        'inventory.accounting.view',
    ]);
    $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.inventory.reports.index'))
        ->assertOk()
        ->assertSee('<th>Value</th>', false)
        ->assertSee('Inventory / Production to General Ledger Reconciliation');
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
        ->assertSee('Production Cost and Work in Process');
    $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.production.reports.export'))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $productionPdf = $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.production.reports.print'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="production-operations-report.pdf"');
    expect(str_starts_with($productionPdf->getContent(), '%PDF-'))->toBeTrue();
    $this->actingAs($costUser)->withSession($session)
        ->post(route('admin.inventory.accounting.store'))
        ->assertForbidden();
    $this->actingAs($costUser)->withSession($session)
        ->get(route('admin.inventory.documents.create'))
        ->assertForbidden();
});
