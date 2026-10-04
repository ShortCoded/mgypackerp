<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Services\InventoryMovementService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

test('isolated PostgreSQL receipt cost proposal posts only after independent approval and rolls back synthetic fixtures', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Run only against the dedicated disposable PostgreSQL acceptance database.');
    }
    $databaseName = DB::selectOne('select current_database() as name')->name;
    if ($databaseName !== 'mgypack_acceptance_receipt_20261001') {
        $this->markTestSkipped('Run only against the dedicated disposable PostgreSQL acceptance database.');
    }

    $this->seed(DefaultOperatingContextSeeder::class);
    $this->seed(CurrencySeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Synthetic receipt cost warehouse']);
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 999931, 'doc_num' => 'UNIT-PG-COST',
        'name' => 'Synthetic units', 'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 999931, 'doc_num' => 'RAW-PG-COST',
        'name' => 'Synthetic raw product', 'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    $preparer = User::factory()->create();
    $approver = User::factory()->create();
    foreach (['inventory.documents.view', 'inventory.documents.propose_receipt_cost', 'inventory.documents.approve_receipt_cost'] as $ability) {
        Permission::findOrCreate($ability, 'web');
    }
    $preparer->givePermissionTo(['inventory.documents.view', 'inventory.documents.propose_receipt_cost']);
    $approver->givePermissionTo(['inventory.documents.view', 'inventory.documents.approve_receipt_cost']);
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    auth()->login($preparer);
    request()->setUserResolver(fn (): User => $preparer);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put($session);

    $receipt = app(InventoryMovementService::class)->createAndPost([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'branch_store_id' => $store->getKey(),
        'document_type' => InventoryDocument::TypeReceipt,
        'document_date' => now()->toDateString(),
    ], [['product_id' => $product->getKey(), 'quantity' => '3']]);
    $line = $receipt->lines()->sole();
    $transaction = $receipt->transactions()->sole();
    $layer = InventoryReceiptLayer::query()->where('receipt_transaction_id', $transaction->getKey())->sole();

    $this->actingAs($preparer)->withSession($session)->post(route('admin.inventory.documents.price-receipt', $receipt), [
        'basis' => 'estimate',
        'basis_note' => 'Synthetic PostgreSQL cost evidence',
        'unit_costs' => [$line->getKey() => '7.25'],
    ])->assertRedirect(route('admin.inventory.documents.show', $receipt));
    $proposal = InventoryReceiptCostProposal::query()->where('inventory_document_id', $receipt->getKey())->sole();
    expect($proposal->status)->toBe(InventoryReceiptCostProposal::StatusPending)
        ->and($line->fresh()->unit_cost)->toBeNull()
        ->and($transaction->fresh()->unit_cost)->toBeNull()
        ->and($layer->fresh()->unit_cost)->toBeNull()
        ->and($receipt->fresh()->journal_entry_id)->toBeNull();

    $approvalUrl = route('admin.inventory.documents.receipt-cost-approve', [$receipt, $proposal]);
    $approval = ['source_reference' => 'Synthetic PostgreSQL source QA', 'approval_reference' => 'Synthetic PostgreSQL approval QA'];
    $this->actingAs($preparer)->withSession($session)->post($approvalUrl, $approval)->assertForbidden();
    $this->actingAs($approver)->withSession($session)->post($approvalUrl, $approval)
        ->assertRedirect(route('admin.inventory.documents.show', $receipt));
    expect($proposal->fresh()->status)->toBe(InventoryReceiptCostProposal::StatusApproved)
        ->and($line->fresh()->unit_cost)->toBe('7.25000000')
        ->and($line->fresh()->total_cost)->toBe('21.75000000')
        ->and($transaction->fresh()->total_cost)->toBe('21.75000000')
        ->and($layer->fresh()->unit_cost)->toBe('7.25000000')
        ->and($receipt->fresh()->journal_entry_id)->not->toBeNull();
    $journal = DB::table('journal_entry_lines')->where('journal_entry_id', $receipt->fresh()->journal_entry_id)
        ->selectRaw('sum(debit_amount) as debit, sum(credit_amount) as credit')->first();
    expect((string) $journal->debit)->toBe((string) $journal->credit)
        ->and(bccomp((string) $journal->debit, '21.75', 4))->toBe(0);
    $this->post($approvalUrl, $approval)->assertSessionHasErrors('document');
});
