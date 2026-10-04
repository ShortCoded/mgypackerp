<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DocumentNumberService;
use Modules\Finance\Models\BankAccount;
use Modules\Inventory\Models\InventoryCostStandard;
use Modules\Inventory\Services\InventoryStandardCostService;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionExpenseRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';
require_once __DIR__.'/InventoryPeriodicCostCloseSupport.php';

function standardCostFixture(bool $isolatedCompany = false): array
{
    $suffix = '-SYNTHETIC-STD-'.Str::random(8);
    $fixture = manufacturingInventoryFixture($suffix, $isolatedCompany);
    DB::table('inventory_transactions')->where('product_id', $fixture['raw']->id)
        ->where('source_type', 'test')->where('posting_key', 'manufacturing-opening-resin'.$suffix)->delete();
    $branchNumber = max(999815, (int) Branch::withTrashed()->max('doc_number') + 1);
    $fixture['branch'] = Branch::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => $branchNumber,
        'doc_num' => 'SYNTHETIC-STD-FACTORY-'.$branchNumber, 'name' => 'SYNTHETIC standard factory'.$suffix, 'type' => Branch::TypeFactory, 'status' => 'active']);
    $fixture['store'] = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC standard warehouse']);
    $fixture['machine']->update(['branch_id' => $fixture['branch']->id]);
    $fixture['mold']->update(['branch_id' => $fixture['branch']->id]);
    request()->session()->put(manufacturingIntegritySession($fixture));
    $fixture['preparer'] = $fixture['user'];
    $fixture['approver'] = closureSyntheticUser();
    foreach (['view', 'prepare', 'settle', 'approve', 'export', 'print'] as $permission) {
        Permission::findOrCreate('inventory.cost_policies.standard.'.$permission, 'web');
    }
    $fixture['preparer']->givePermissionTo(['inventory.cost_policies.standard.view', 'inventory.cost_policies.standard.prepare', 'inventory.cost_policies.standard.settle']);
    $fixture['approver']->givePermissionTo(['inventory.cost_policies.standard.view', 'inventory.cost_policies.standard.approve']);

    return standardCostAccounts($fixture);
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function standardCostAccounts(array $fixture): array
{
    $suffix = '-SYNTHETIC-STD-'.Str::random(8);
    foreach (['materials', 'labor', 'overhead', 'clearing'] as $part) {
        $number = (int) Account::withTrashed()->max('doc_number') + 1;
        $classification = $part === 'clearing'
            ? AccountClassification::query()->where('code', PostingAccountResolver::InventoryCostCompletionClearing)->sole()
            : AccountClassification::query()->create(['doc_number' => (int) AccountClassification::withTrashed()->max('doc_number') + 1,
                'doc_num' => 'SYNTHETIC-STD-'.$part.$suffix, 'code' => 'synthetic_std_'.$part.$suffix, 'name' => 'SYNTHETIC standard '.$part.$suffix,
                'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit, 'status' => 'active']);
        $fixture[$part.'_account'] = Account::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => $number,
            'doc_num' => 'SYNTHETIC-STD-'.$number, 'account_code' => '994'.$number, 'name' => 'SYNTHETIC standard '.$part.$suffix,
            'account_type' => $part === 'clearing' ? Account::TypeLiability : Account::TypeExpense,
            'account_classification_id' => $classification->id,
            'statement_type' => $part === 'clearing' ? Account::StatementFinancialPosition : Account::StatementIncomeStatement,
            'normal_balance' => $part === 'clearing' ? Account::BalanceCredit : Account::BalanceDebit]);
    }

    return $fixture;
}

function standardCostExpenseRequest(array $fixture, ProductionRun $run): ProductionExpenseRequest
{
    $currency = Currency::where('company_id', $fixture['company']->id)->where('is_main', true)->sole();
    $bankGroup = Account::where('company_id', $fixture['company']->id)->where('account_type', Account::TypeAsset)->where('is_group', true)->firstOrFail();
    $ledger = Account::where('company_id', $fixture['company']->id)->where('account_type', Account::TypeAsset)->where('is_postable', true)->orderBy('id')->firstOrFail();
    $bank = BankAccount::create([
        ...app(DocumentNumberService::class)->nextForCompany('bank_accounts', BankAccount::class, $fixture['company']->id),
        'company_id' => $fixture['company']->id, 'bank_id' => $bankGroup->id, 'account_id' => $ledger->id, 'currency_id' => $currency->id,
        'account_name' => 'SYNTHETIC standard expense bank', 'account_number' => 'SYNTHETIC-'.Str::random(12), 'status' => 'active',
    ]);
    $service = app(ProductionExpenseRequestService::class);

    return $service->approve($service->create($run, ['amount' => '125', 'currency_id' => $currency->id, 'payment_channel' => 'bank',
        'bank_account_id' => $bank->id, 'expense_account_id' => $fixture['overhead_account']->id, 'reason' => 'SYNTHETIC standard overhead source']));
}

function standardCostSyntheticLegacyPayment(array $fixture, ProductionExpenseRequest $expense): void
{
    $journal = app(JournalEntryService::class)->createPostedFromSource(['company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id, 'entry_date' => now()->toDateString(),
        'currency_id' => $expense->currency_id, 'exchange_rate' => '1', 'source_type' => 'production_expense_payment',
        'source_id' => $expense->id, 'source_doc_num' => $expense->doc_num, 'description' => 'SYNTHETIC explicit legacy expense-debit history'],
        [['account_id' => $expense->expense_account_id, 'debit_amount' => '125', 'credit_amount' => '0', 'cost_center_id' => $expense->run->cost_center_id],
            ['account_id' => $expense->bankAccount->account_id, 'debit_amount' => '0', 'credit_amount' => '125', 'cost_center_id' => $expense->run->cost_center_id]]);
    $expense->forceFill(['status' => ProductionExpenseRequest::StatusPaid, 'journal_entry_id' => $journal->id,
        'paid_by' => $fixture['preparer']->id, 'paid_at' => now()])->save();
}

function prepareSyntheticStandard(array $fixture): InventoryCostStandard
{
    return app(InventoryStandardCostService::class)->prepareVersion($fixture['company']->id, [
        'branch_id' => $fixture['branch']->id, 'product_id' => $fixture['finished']->id,
        'effective_from' => now()->toDateString(), 'effective_to' => $fixture['period']->to_date->toDateString(),
        'materials_unit_cost' => '3.2', 'labor_unit_cost' => '0.1', 'overhead_unit_cost' => '0.2',
        'materials_variance_account_id' => $fixture['materials_account']->id, 'labor_variance_account_id' => $fixture['labor_account']->id,
        'overhead_variance_account_id' => $fixture['overhead_account']->id, 'counterpart_account_id' => $fixture['clearing_account']->id,
        'source_reference' => 'SYNTHETIC engineered unit standard',
    ], $fixture['preparer']->id);
}

function standardCostActor(User $actor): void
{
    test()->actingAs($actor);
    request()->setUserResolver(fn (): User => $actor);
}

function standardCostCompletedRun(array $fixture, ?Closure $beforeReceipt = null): ProductionRun
{
    $details = manufacturingIntegrityRun($fixture, '10');
    $cycle = $details['cycle'];
    $run = $details['run'];
    $cycle->reserveRun($run, $fixture['store']->id);
    $cycle->issueMaterials($run, $fixture['store']->id);
    $run = $cycle->startRun($cycle->completeSetup($cycle->startSetup($run)));
    $cycle->recordProgress($run, ['good_base_quantity' => '10']);
    $requirement = $run->requirements()->sole();
    $cycle->accountMaterials($run->fresh(), $fixture['store']->id, [$requirement->id => ['consumed_quantity' => '19', 'waste_quantity' => '1']]);
    $beforeReceipt?->__invoke($run->fresh());
    $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '4');
    $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '6');

    return $cycle->completeRun($run->fresh());
}
