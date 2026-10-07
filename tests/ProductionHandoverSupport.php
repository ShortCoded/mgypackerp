<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCycleService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ProductionPartialOutputEvidenceSupport.php';

/** @return array<string, mixed> */
function productionHandoverFixture(bool $recordOutput = true): array
{
    test()->travelTo(Carbon::parse('2026-09-29 12:00:00'));
    $f = manufacturingInventoryFixture('-HANDOVER-'.Str::random(6), isolatedCompany: true);
    InventoryTransaction::query()->where('company_id', $f['company']->id)->where('source_type', 'test')->delete();
    app(InventoryMovementService::class)->createAndPost([
        'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'branch_store_id' => $f['store']->id,
        'document_date' => now()->toDateString(), 'document_type' => InventoryDocument::TypeAdjustmentIn,
    ], [['product_id' => $f['raw']->id, 'quantity' => '1000', 'unit_cost' => '2']]);
    $products = collect([$f['finished']]);
    for ($index = 1; $index < 3; $index++) {
        $product = Product::query()->create(['company_id' => $f['company']->id,
            'doc_number' => (int) Product::withTrashed()->max('doc_number') + 1, 'doc_num' => 'SYNTHETIC-HANDOVER-'.$index,
            'name' => 'SYNTHETIC صنف تسليم '.$index, 'item_classification' => Product::ClassificationFinishedProduct,
            'item_unit_id' => $f['unit']->id, 'status' => 'active']);
        ProductComponent::query()->create(['company_id' => $f['company']->id, 'product_id' => $product->id,
            'component_product_id' => $f['raw']->id, 'unit_id' => $f['unit']->id,
            'calculation_method' => ProductComponent::CalculationDirect, 'quantity' => '2', 'created_by' => $f['user']->id]);
        $f['mold']->products()->attach($product);
        $products->push($product);
    }
    $cycle = app(ProductionCycleService::class);
    $order = $cycle->releaseOrder($cycle->createMakeToStockOrder(['company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id],
        $products->map(fn (Product $product): array => ['product_id' => $product->id, 'unit_id' => $f['unit']->id, 'quantity' => '10'])->all()));
    $batch = $cycle->createRunBatch($order, ['planned_start_at' => now()->addHour(), 'planned_end_at' => now()->addHours(2),
        'production_machine_id' => $f['machine']->id, 'production_mold_id' => $f['mold']->id, 'batch_lot' => 'SYNTHETIC-HANDOVER-LOT',
        'lines' => $order->lines->map(fn ($line): array => ['production_order_line_id' => $line->id, 'planned_quantity' => '10'])->all()]);
    foreach ($batch->runs as $run) {
        $cycle->reserveRun($run, $f['store']->id);
        $cycle->issueMaterials($run->fresh(), $f['store']->id);
        $cycle->startRun($cycle->completeSetup($cycle->startSetup($run->fresh())));
        if ($recordOutput) {
            $cycle->recordProgress($run->fresh(), ['good_base_quantity' => '10']);
            $cycle->accountMaterials($run->fresh(), $f['store']->id,
                [$run->requirements->sole()->id => ['consumed_quantity' => '20', 'waste_quantity' => '0']]);
        }
    }
    foreach (['production.handovers.view', 'production.handovers.create', 'production.handovers.approve', 'production.handovers.cancel'] as $key) {
        $f['user']->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    $warehouse = closureSyntheticUser();
    foreach (['inventory.production_receipts.view', 'inventory.production_receipts.create', 'inventory.production_receipts.approve', 'inventory.production_receipts.cancel'] as $key) {
        $warehouse->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    productionHandoverActor($f, $f['user']);

    return [...$f, ...compact('cycle', 'order', 'batch', 'products', 'warehouse'), 'runs' => $batch->runs()->orderBy('id')->get()];
}

/** @param array<string, mixed> $f */
function productionHandoverActor(array $f, User $actor): void
{
    test()->actingAs($actor)->withSession(manufacturingIntegritySession($f));
    request()->attributes->replace([]);
    request()->setUserResolver(fn () => $actor);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(manufacturingIntegritySession($f));
}

/** @param array{_submission_token: string, branch_store_id: int, base_quantity: string, document_date?: string} $payload */
function productionHandoverHttpReceipt(ProductionRun $run, array $payload, User $warehouse): TestResponse
{
    $run = $run->fresh();
    $operator = auth()->user();
    $session = request()->session()->all();
    $token = ['_submission_token' => $payload['_submission_token']];
    try {
        $handoverResponse = test()->postJson(route('admin.production.handovers.store', $run), [...$token,
            'branch_store_id' => $payload['branch_store_id'], 'document_date' => $payload['document_date'] ?? now()->toDateString(),
            'lines' => [['run_public_id' => $run->public_id, 'quantity' => bcdiv($payload['base_quantity'], (string) $run->conversion_factor, 8)]]]);
        if ($handoverResponse->getStatusCode() >= 400) {
            return $handoverResponse;
        }
        $handover = InventoryDocument::query()->where('company_id', $run->company_id)->where('doc_num', $handoverResponse->json('doc_num'))->sole();
        $approval = test()->postJson(route('admin.production.handovers.approve', $handover), $token);
        if ($approval->getStatusCode() >= 400) {
            return $approval;
        }
        test()->actingAs($warehouse)->withSession($session);
        $receiptResponse = test()->postJson(route('admin.inventory.production-receipts.store', $handover), [...$token,
            'document_date' => $payload['document_date'] ?? now()->toDateString(),
            'lines' => $handover->lines->map(fn ($line): array => ['line_public_id' => $line->public_id, 'quantity' => (string) $line->transaction_quantity])->all()]);
        if ($receiptResponse->getStatusCode() >= 400) {
            return $receiptResponse;
        }
        $receipt = InventoryDocument::query()->where('company_id', $run->company_id)->where('doc_num', $receiptResponse->json('doc_num'))->sole();

        return test()->postJson(route('admin.inventory.production-receipts.approve', $receipt), $token);
    } finally {
        test()->actingAs($operator)->withSession($session);
        request()->setUserResolver(fn () => $operator);
        request()->setLaravelSession(app('session.store'));
        request()->session()->put($session);
        request()->attributes->replace([]);
    }
}
