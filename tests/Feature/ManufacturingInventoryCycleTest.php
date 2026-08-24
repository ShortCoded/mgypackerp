<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\StockCountService;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCycleService;
use Spatie\Permission\Models\Permission;

/** @return array<string, mixed> */
function manufacturingInventoryFixture(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
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

test('the canonical manufacturing cycle reconciles physical stock, reservations, waste, quality, and partial finished receipts', function () {
    $fixture = manufacturingInventoryFixture();
    $cycle = app(ProductionCycleService::class);
    $availability = app(InventoryAvailabilityService::class);
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

    $firstReceipt = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '2');
    $secondReceipt = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->getKey(), '2');
    expect($firstReceipt->document_type)->toBe(InventoryDocument::TypeProductionReceipt)
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
        ->toThrow(DomainException::class, 'exceeds stock on hand');

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
        'inventory.opening_stocks.view',
        'inventory.opening_stocks.create',
        'inventory.opening_stocks.approve',
        'production.work_orders.view',
        'production.work_orders.complete',
        'production.work_orders.print',
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
            ]],
        ]);
    $document = InventoryDocument::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('admin.inventory.documents.show', $document));

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
});
