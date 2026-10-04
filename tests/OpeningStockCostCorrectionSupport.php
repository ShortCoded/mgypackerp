<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockCostCorrection;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;
use Modules\Inventory\Services\InventoryOpeningStockPostingService;
use Modules\Inventory\Services\OpeningStockCostCorrectionService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryCostTransitionSupport.php';

/** @return array<string, mixed> */
function openingCorrectionFixture(): array
{
    $fixture = costTransitionFixture(isolatedCompany: true);
    $fixture['product']->update(['item_classification' => Product::ClassificationRawMaterial]);
    foreach (['inventory.opening_stock_cost_corrections.prepare', 'inventory.opening_stock_cost_corrections.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['preparer']->givePermissionTo('inventory.opening_stock_cost_corrections.prepare');
    $fixture['approver']->givePermissionTo('inventory.opening_stock_cost_corrections.approve');
    $accountNumber = (int) Account::withTrashed()->max('doc_number') + 1;
    $fixture['counterpart'] = Account::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => $accountNumber,
        'doc_num' => 'SYNTHETIC-OPENING-CLEAR-'.$accountNumber, 'account_code' => '993'.$accountNumber,
        'name' => 'SYNTHETIC opening cost correction clearing',
        'account_classification_id' => AccountClassification::query()
            ->where('code', PostingAccountResolver::InventoryCostCompletionClearing)->sole()->id,
        'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceCredit,
    ]);
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $opening = OpeningStock::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
        'doc_number' => 998701, 'doc_num' => 'SYNTHETIC-OS-CORRECTION', 'document_date' => $day(1),
        'is_closed' => true, 'approved' => true, 'status' => OpeningStock::StatusApproved,
        'approved_by' => $fixture['approver']->id, 'approved_at' => now(), 'created_by' => $fixture['preparer']->id,
    ]);
    $line = OpeningStockLine::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'opening_stock_id' => $opening->id, 'line_no' => 1,
        'product_id' => $fixture['product']->id,
        'product_snapshot' => ['doc_num' => $fixture['product']->doc_num, 'name' => $fixture['product']->name],
        'quantity' => '10.0000', 'created_by' => $fixture['preparer']->id,
    ]);
    $currency = Currency::query()->forCompany($fixture['company']->id)->where('is_main', true)->sole();
    $pricing = OpeningStockPricing::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'opening_stock_id' => $opening->id, 'currency_id' => $currency->id,
        'doc_number' => 998701, 'doc_num' => 'SYNTHETIC-OSP-CORRECTION', 'document_date' => $day(1),
        'exchange_rate' => '1', 'total_amount' => '50', 'pricing_basis' => OpeningStockPricing::BasisDocumented,
        'source_reference' => 'SYNTHETIC original opening valuation', 'is_closed' => true,
        'status' => OpeningStockPricing::StatusClosed, 'created_by' => $fixture['preparer']->id,
    ]);
    $pricingLine = OpeningStockPricingLine::query()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'pricing_id' => $pricing->id, 'opening_stock_line_id' => $line->id,
        'product_id' => $fixture['product']->id, 'product_snapshot' => $line->product_snapshot,
        'quantity' => '10', 'unit_price' => '5', 'line_total' => '50', 'created_by' => $fixture['preparer']->id,
    ]);
    app(InventoryOpeningStockPostingService::class)->post($opening);
    $fixture += compact('opening', 'line', 'pricing', 'pricingLine');
    $fixture['day'] = $day;
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);

    return $fixture;
}

/** @param array<string, mixed> $fixture */
function prepareOpeningCorrection(array $fixture, string $targetCost, string $postingDate): OpeningStockCostCorrection
{
    return app(OpeningStockCostCorrectionService::class)->prepare(request(), $fixture['opening'], [
        'posting_date' => $postingDate, 'counterpart_account_id' => $fixture['counterpart']->id,
        'reason' => 'SYNTHETIC corrected supplier-supported opening cost',
        'source_reference' => 'SYNTHETIC opening cost evidence REF-001',
    ], [$fixture['line']->id => $targetCost]);
}
