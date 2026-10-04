<?php

use App\Models\User;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;
use Modules\Inventory\Models\OpeningStockQuantityCorrection;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Inventory\Services\InventoryOpeningStockPostingService;
use Modules\Inventory\Services\OpeningStockQuantityCorrectionService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/OpeningStockCostCorrectionSupport.php';

/** @return array<string, mixed> */
function openingQuantityCorrectionFixture(): array
{
    $fixture = openingCorrectionFixture();
    foreach (['inventory.opening_stock_quantity_corrections.prepare', 'inventory.opening_stock_quantity_corrections.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['preparer']->givePermissionTo('inventory.opening_stock_quantity_corrections.prepare');
    $fixture['approver']->givePermissionTo('inventory.opening_stock_quantity_corrections.approve');

    return $fixture;
}

/** @return array<string, mixed> */
function serializedOpeningQuantityCorrectionFixture(string $costMethod = InventoryCostPolicy::SpecificIdentification): array
{
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update([
        'item_classification' => Product::ClassificationRawMaterial,
        'tracks_serials' => true,
    ]);
    foreach (['inventory.opening_stock_quantity_corrections.prepare', 'inventory.opening_stock_quantity_corrections.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['preparer']->givePermissionTo('inventory.opening_stock_quantity_corrections.prepare');
    $fixture['approver']->givePermissionTo('inventory.opening_stock_quantity_corrections.approve');
    app(InventoryCostPolicyService::class)->createVersion($fixture['company']->id, [
        'branch_store_id' => $fixture['store']->id,
        'method' => $costMethod,
        'effective_from' => $fixture['period']->from_date->toDateString(),
        'reason' => 'SYNTHETIC serialized opening correction policy',
    ], $fixture['preparer']->id);
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $opening = OpeningStock::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
        'doc_number' => 998702, 'doc_num' => 'SYNTHETIC-SERIAL-OS-CORRECTION', 'document_date' => $day(1),
        'is_closed' => true, 'approved' => true, 'status' => OpeningStock::StatusApproved,
        'approved_by' => $fixture['approver']->id, 'approved_at' => now(), 'created_by' => $fixture['preparer']->id,
    ]);
    $serialNumbers = ['SYNTHETIC-OPEN-SERIAL-A', 'SYNTHETIC-OPEN-SERIAL-B', 'SYNTHETIC-OPEN-SERIAL-C'];
    $line = OpeningStockLine::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'opening_stock_id' => $opening->id, 'line_no' => 1,
        'product_id' => $fixture['product']->id,
        'product_snapshot' => ['doc_num' => $fixture['product']->doc_num, 'name' => $fixture['product']->name,
            'serial_numbers' => $serialNumbers],
        'quantity' => '3.0000', 'created_by' => $fixture['preparer']->id,
    ]);
    $currency = Currency::query()->forCompany($fixture['company']->id)->where('is_main', true)->sole();
    $pricing = OpeningStockPricing::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'opening_stock_id' => $opening->id, 'currency_id' => $currency->id,
        'doc_number' => 998702, 'doc_num' => 'SYNTHETIC-SERIAL-OSP-CORRECTION', 'document_date' => $day(1),
        'exchange_rate' => '1', 'total_amount' => '10', 'pricing_basis' => OpeningStockPricing::BasisDocumented,
        'source_reference' => 'SYNTHETIC serialized original valuation', 'is_closed' => true,
        'status' => OpeningStockPricing::StatusClosed, 'created_by' => $fixture['preparer']->id,
    ]);
    $pricingLine = OpeningStockPricingLine::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'pricing_id' => $pricing->id, 'opening_stock_line_id' => $line->id,
        'product_id' => $fixture['product']->id, 'product_snapshot' => $line->product_snapshot,
        'quantity' => '3', 'unit_price' => '3.33333333', 'line_total' => '10', 'created_by' => $fixture['preparer']->id,
    ]);
    app(InventoryOpeningStockPostingService::class)->post($opening);
    $root = InventoryTransaction::query()->where('posting_key', "opening-stock:{$opening->id}:line:{$line->id}")->sole();
    $serialLayers = InventoryReceiptLayer::query()->with('serialIdentity')->where('receipt_transaction_id', $root->id)->get()
        ->keyBy(fn (InventoryReceiptLayer $layer): string => (string) $layer->serialIdentity->serial_number);
    $fixture += compact('opening', 'line', 'pricing', 'pricingLine', 'serialNumbers', 'root', 'serialLayers');
    $fixture['serial_layers'] = $serialLayers;
    $fixture['day'] = $day;
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);

    return $fixture;
}

/** @param array<string, mixed> $fixture */
function prepareOpeningQuantityCorrection(
    array $fixture,
    string $targetQuantity,
    string $postingDate,
    ?string $unitCost = null,
): OpeningStockQuantityCorrection {
    $target = ['target_quantity' => $targetQuantity];
    if ($unitCost !== null) {
        $target['unit_cost'] = $unitCost;
    }

    return app(OpeningStockQuantityCorrectionService::class)->prepare(request(), $fixture['opening'], [
        'posting_date' => $postingDate,
        'reason' => 'SYNTHETIC documented opening quantity correction',
        'source_reference' => 'SYNTHETIC opening quantity evidence REF-001',
    ], [$fixture['line']->id => $target]);
}

/** @param array<string, mixed> $fixture */
function addSecondOpeningQuantityLine(array &$fixture): OpeningStockLine
{
    $number = (int) Product::withTrashed()->max('doc_number') + 1;
    $product = $fixture['product']->replicate();
    $product->fill([
        'doc_number' => $number,
        'doc_num' => 'SYNTHETIC-OPENING-QTY-'.$number,
        'name' => 'SYNTHETIC second opening quantity product',
    ])->save();
    $line = OpeningStockLine::query()->create([
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'opening_stock_id' => $fixture['opening']->id,
        'line_no' => 2,
        'product_id' => $product->id,
        'product_snapshot' => ['doc_num' => $product->doc_num, 'name' => $product->name],
        'quantity' => '5.0000',
        'created_by' => $fixture['preparer']->id,
    ]);
    $firstRoot = InventoryTransaction::query()
        ->where('posting_key', "opening-stock:{$fixture['opening']->id}:line:{$fixture['line']->id}")->sole();
    $root = InventoryTransaction::query()->create([
        'posting_key' => "opening-stock:{$fixture['opening']->id}:line:{$line->id}",
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'branch_store_id' => $fixture['store']->id,
        'stock_status' => InventoryTransaction::StatusAvailable,
        'transaction_date' => $fixture['opening']->document_date,
        'transaction_type' => 'opening_stock',
        'product_id' => $product->id,
        'unit_id' => $fixture['unit']->id,
        'quantity_in' => '5',
        'quantity_out' => '0',
        'source_type' => OpeningStock::class,
        'source_id' => $fixture['opening']->id,
        'source_doc_num' => $fixture['opening']->doc_num,
        'source_line_type' => OpeningStockLine::class,
        'source_line_id' => $line->id,
        'unit_cost' => '5',
        'total_cost' => '25',
        'cost_method' => $firstRoot->cost_method,
        'cost_policy_id' => $firstRoot->cost_policy_id,
        'cost_basis' => 'opening_stock',
        'created_by' => $fixture['preparer']->id,
    ]);
    app(InventoryLayerService::class)->recordInbound($root);
    $fixture['second_product'] = $product;
    $fixture['second_line'] = $line;

    return $line;
}

function actAsOpeningQuantityApprover(array $fixture): void
{
    test()->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
}
