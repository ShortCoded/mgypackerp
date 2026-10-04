<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryPeriodicCostClose;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryPeriodicCostCloseService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryValueAdjustmentSupport.php';

function periodicCostFixture(array $fixture = [], bool $isolatedCompany = false): array
{
    $fixture = $fixture ?: receiptCompletionFixture(isolatedCompany: $isolatedCompany);
    foreach (['inventory.cost_policies.periodic.view', 'inventory.cost_policies.periodic.prepare', 'inventory.cost_policies.periodic.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['preparer']->givePermissionTo(['inventory.cost_policies.periodic.view', 'inventory.cost_policies.periodic.prepare']);
    $fixture['approver']->givePermissionTo(['inventory.cost_policies.periodic.view', 'inventory.cost_policies.periodic.approve']);
    $number = (int) Account::withTrashed()->max('doc_number') + 1;
    $fixture['clearing'] = Account::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => $number, 'doc_num' => 'SYNTHETIC-PWA-CLEAR-'.$number,
        'account_code' => '992'.$number, 'name' => 'SYNTHETIC periodic cost clearing',
        'account_classification_id' => AccountClassification::query()->where('code', PostingAccountResolver::InventoryCostCompletionClearing)->sole()->id,
        'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceCredit]);
    if (app(InventoryCostPolicyService::class)->resolve($fixture['company']->id, $fixture['store']->id, $fixture['period']->from_date->toDateString())['method'] !== InventoryCostPolicy::PeriodicWeightedAverage) {
        app(InventoryCostPolicyService::class)->createVersion($fixture['company']->id, [
            'branch_store_id' => $fixture['store']->id, 'method' => InventoryCostPolicy::PeriodicWeightedAverage,
            'effective_from' => $fixture['period']->from_date->toDateString(), 'reason' => 'SYNTHETIC periodic cost policy',
        ], $fixture['preparer']->id);
    }

    return $fixture;
}

function preparePeriodicCost(array $fixture, array $overrides = []): InventoryPeriodicCostClose
{
    return app(InventoryPeriodicCostCloseService::class)->prepare($fixture['company']->id, [
        'scope_store_id' => $fixture['store']->id, 'from_date' => $fixture['period']->from_date->toDateString(),
        'to_date' => $fixture['period']->from_date->copy()->addDays(4)->toDateString(),
        'posting_date' => $fixture['period']->from_date->copy()->addDays(4)->toDateString(),
        'counterpart_account_id' => $fixture['clearing']->id, 'reason' => 'SYNTHETIC complete warehouse period', ...$overrides,
    ], $fixture['preparer']->id);
}

function approvePeriodicCost(array $fixture, InventoryPeriodicCostClose $close): InventoryPeriodicCostClose
{
    test()->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);

    return app(InventoryPeriodicCostCloseService::class)->approve($close, $fixture['approver']->id, 'SYNTHETIC independent period approval');
}

function periodicSyntheticLegacyJournal(InventoryDocument $document): void
{
    expect($document->company->doc_num)->toStartWith('SYNTHETIC-');
    $groups = [];
    foreach ($document->lines as $line) {
        $snapshot = $line->product_snapshot;
        $book = $snapshot['inventory_accounting'];
        foreach (['debit', 'credit'] as $side) {
            $key = $book[$side.'_account_id'].':'.($book['cost_center_id'] ?? 'none').':'.$side;
            $groups[$key] = bcadd($groups[$key] ?? '0', bcadd((string) $line->total_cost, '0', 4), 4);
        }
        unset($snapshot['inventory_accounting']);
        DB::table('inventory_document_lines')->where('id', $line->id)->update(['product_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    }
    foreach ($document->journalEntry->lines as $line) {
        $side = bccomp((string) $line->debit_amount, '0', 4) > 0 ? 'debit' : 'credit';
        $key = $line->account_id.':'.($line->cost_center_id ?? 'none').':'.$side;
        DB::table('journal_entry_lines')->where('id', $line->id)->update([
            'debit_amount' => $side === 'debit' ? $groups[$key] : '0.0000',
            'credit_amount' => $side === 'credit' ? $groups[$key] : '0.0000']);
    }
}
