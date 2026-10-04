<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductService;
use Modules\Finance\Models\OpeningBalance;
use Modules\Finance\Services\OpeningBalanceApprovalService;
use Modules\Finance\Services\OpeningBalanceService;
use Modules\Finance\Services\OpeningInventoryValuationService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryOpeningStockPostingService;
use Modules\Inventory\Services\OpeningStockService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function inventoryOpeningFixture(object $test, string $classification = Product::ClassificationRawMaterial): array
{
    $test->seed(DefaultChartOfAccountsSeeder::class);
    $test->seed(CurrencySeeder::class);
    $company = Company::query()->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->id)->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->id)->firstOrFail();
    $period->update(['allows_opening_entries' => true]);
    $store = BranchStore::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'doc_number' => 81234, 'doc_num' => 'Store-81234', 'name' => 'Synthetic opening inventory store', 'status' => 'active']);
    $unit = ItemUnit::query()->create(['company_id' => $company->id, 'doc_number' => 81234,
        'doc_num' => 'Unit-81234', 'name' => 'Synthetic unit', 'status' => 'active']);
    $product = Product::query()->create(['company_id' => $company->id, 'doc_number' => 81234,
        'doc_num' => 'Product-81234', 'name' => 'Synthetic opening inventory product',
        'item_classification' => $classification, 'item_unit_id' => $unit->id, 'status' => 'active']);
    $source = OpeningStock::query()->create(['company_id' => $company->id, 'financial_period_id' => $period->id,
        'branch_id' => $branch->id, 'branch_store_id' => $store->id, 'doc_number' => 81234, 'doc_num' => 'OS-81234',
        'document_date' => $period->from_date, 'approved' => true, 'status' => OpeningStock::StatusApproved]);
    $line = $source->lines()->create(['company_id' => $company->id, 'financial_period_id' => $period->id,
        'branch_id' => $branch->id, 'line_no' => 1, 'product_id' => $product->id, 'quantity' => '10.0000']);
    $currency = Currency::query()->where('company_id', $company->id)->where('is_main', true)->firstOrFail();
    $pricing = OpeningStockPricing::query()->create(['company_id' => $company->id, 'financial_period_id' => $period->id,
        'branch_id' => $branch->id, 'opening_stock_id' => $source->id, 'doc_number' => 81234, 'doc_num' => 'OSP-81234',
        'document_date' => $period->from_date, 'currency_id' => $currency->id, 'exchange_rate' => '1', 'total_amount' => '123.4568']);
    $pricing->lines()->create(['company_id' => $company->id, 'financial_period_id' => $period->id,
        'branch_id' => $branch->id, 'opening_stock_line_id' => $line->id, 'product_id' => $product->id,
        'quantity' => '10.0000', 'unit_price' => '12.34567890', 'line_total' => '123.4568']);
    DB::transaction(fn () => app(InventoryOpeningStockPostingService::class)->post($source));
    $movement = InventoryTransaction::query()->where('source_id', $source->id)->where('source_type', OpeningStock::class)->firstOrFail();
    $account = app(PostingAccountResolver::class)->inventoryForProduct($company->id, $product, 'synthetic opening');
    $counterpart = Account::query()->forCompany($company->id)->eligibleForDirectPosting()
        ->whereHas('classification', fn ($q) => $q->where('code', 'capital'))->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->id)->where('is_main', true)->firstOrFail();
    $actor = User::factory()->create();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['opening_balances.view', 'opening_balances.create', 'opening_balances.edit', 'opening_balances.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $actor->givePermissionTo($permission);
    }
    $test->actingAs($actor)->withSession([
        OperatingContextService::CompanyIdKey => $company->id, OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->id, OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->id, OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);
    request()->setLaravelSession(app('session.store'));
    request()->setUserResolver(fn () => $actor);

    return compact('company', 'branch', 'period', 'source', 'line', 'movement', 'account', 'counterpart', 'currency', 'actor', 'product', 'store', 'pricing');
}

function inventoryOpeningPayload(array $f): array
{
    $preview = app(OpeningInventoryValuationService::class)->preview($f['company']->id, $f['period']->id, $f['branch']->id, $f['account']->doc_num);

    return ['document_date' => $f['period']->from_date->toDateString(), 'currency_doc_num' => $f['currency']->doc_num,
        'exchange_rate' => '1', 'inventory_source_fingerprint' => $preview['snapshot']['fingerprint'],
        'lines' => [['account_doc_num' => $f['account']->doc_num, 'transaction_type' => 'credit', 'amount' => '999'],
            ['account_doc_num' => $f['counterpart']->doc_num, 'transaction_type' => 'debit', 'amount' => '1']]];
}

test('priced inventory preview exercises actual schema and posts exact scoped source value once', function (): void {
    $f = inventoryOpeningFixture($this);
    $this->get(route('admin.finance.opening-balances.create'))->assertOk()->assertSee('data-inventory-valuation-url', false);
    $preview = $this->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $f['account']->doc_num]));
    $preview->assertOk()->assertJsonPath('data.amount', '123.4568')->assertJsonPath('data.source_count', 1)
        ->assertJsonPath('data.can_post', true)->assertJsonMissingPath('data.snapshot');
    $response = $this->postJson(route('admin.finance.opening-balances.store'), inventoryOpeningPayload($f))->assertOk();
    $record = OpeningBalance::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    expect($record->is_closed)->toBeTrue()->and($record->lines()->orderBy('line_no')->pluck('debit_amount')->all())->toBe(['123.4568', '0.0000']);
    $this->postJson(route('admin.finance.opening-balances.approve', $record->doc_num))->assertOk();
    $approved = $record->fresh(['journalEntry.lines']);
    expect($approved->journalEntry->lines->pluck('debit_amount')->all())->toBe(['123.4568', '0.0000'])
        ->and($f['movement']->fresh()->total_cost)->toBe('123.45680000')
        ->and(InventoryTransaction::query()->count())->toBe(1);
    $this->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $f['account']->doc_num]))
        ->assertOk()->assertJsonPath('data.can_post', false);
    $this->postJson(route('admin.finance.opening-balances.store'), inventoryOpeningPayload($f))->assertUnprocessable()->assertJsonValidationErrors('lines');
});

test('inventory opening rejects stale preview and approval snapshots without posting side effects', function (): void {
    $f = inventoryOpeningFixture($this);
    $payload = inventoryOpeningPayload($f);
    $f['movement']->update(['total_cost' => '124.00000000']);
    $this->postJson(route('admin.finance.opening-balances.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('lines');
    expect(OpeningBalance::query()->count())->toBe(0);
    $f['movement']->update(['total_cost' => '123.45680000']);
    $response = $this->postJson(route('admin.finance.opening-balances.store'), inventoryOpeningPayload($f))->assertOk();
    $record = OpeningBalance::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $f['movement']->update(['total_cost' => '125.00000000']);
    expect(fn () => app(OpeningBalanceApprovalService::class)->approve($record))->toThrow(DomainException::class)
        ->and(DB::table('journal_entries')->count())->toBe(0)->and($record->fresh()->approved)->toBeFalse();
});

test('second draft cannot approve the same inventory opening and missing snapshot cannot bypass derivation', function (): void {
    $f = inventoryOpeningFixture($this);
    $first = app(OpeningBalanceService::class)->create(inventoryOpeningPayload($f))['record'];
    $second = app(OpeningBalanceService::class)->create(inventoryOpeningPayload($f))['record'];
    app(OpeningBalanceApprovalService::class)->approve($first);
    expect(fn () => app(OpeningBalanceApprovalService::class)->approve($second))->toThrow(DomainException::class)
        ->and(DB::table('journal_entries')->count())->toBe(1);
    $second->update(['inventory_valuation_snapshot' => null]);
    expect(fn () => app(OpeningBalanceApprovalService::class)->approve($second))->toThrow(DomainException::class);
});

test('missing unpriced deleted or unapproved opening source cannot become a manual inventory journal', function (string $case): void {
    $f = inventoryOpeningFixture($this);
    match ($case) {
        'missing' => $f['movement']->update(['source_type' => 'SYNTHETIC-MISSING']),
        'unpriced' => $f['movement']->update(['unit_cost' => null, 'total_cost' => null]),
        'deleted' => $f['source']->delete(),
        'unapproved' => $f['source']->update(['approved' => false, 'status' => OpeningStock::StatusClosed]),
    };
    $this->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $f['account']->doc_num]))->assertUnprocessable();
})->with(['missing', 'unpriced', 'deleted', 'unapproved']);

test('inventory preview respects branch period company and permission boundaries', function (): void {
    $f = inventoryOpeningFixture($this);
    $otherBranch = Branch::query()->create(['company_id' => $f['company']->id, 'doc_number' => 81235,
        'doc_num' => 'Branch-81235', 'name' => 'Synthetic other branch', 'type' => Branch::TypeWarehouse, 'status' => 'active']);
    $this->withSession([OperatingContextService::BranchIdKey => $otherBranch->id, OperatingContextService::BranchDocNumKey => $otherBranch->doc_num])
        ->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $f['account']->doc_num]))->assertUnprocessable();
    $this->withSession([OperatingContextService::BranchIdKey => $f['branch']->id, OperatingContextService::BranchDocNumKey => $f['branch']->doc_num]);
    $this->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => 'foreign-account']))->assertNotFound();
    $f['period']->update(['is_closed' => true]);
    $this->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $f['account']->doc_num]))->assertUnprocessable();
    $this->actingAs(User::factory()->create())->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $f['account']->doc_num]))->assertForbidden();
});

test('inventory mapping derives raw packaging and finished product values from canonical pricing', function (string $classification): void {
    $f = inventoryOpeningFixture($this, $classification);
    $response = $this->postJson(route('admin.finance.opening-balances.store'), inventoryOpeningPayload($f))->assertOk();
    $record = OpeningBalance::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $this->postJson(route('admin.finance.opening-balances.approve', $record->doc_num))->assertOk();
    expect($record->fresh()->inventory_valuation_snapshot['source_rows'][0]['pricing']['doc_num'])->toBe('OSP-81234');
})->with([Product::ClassificationRawMaterial, Product::ClassificationPackaging, Product::ClassificationFinishedProduct]);

test('finalized inventory rejects new opening roots repricing and product account reclassification', function (): void {
    $f = inventoryOpeningFixture($this);
    $record = app(OpeningBalanceService::class)->create(inventoryOpeningPayload($f))['record'];
    app(OpeningBalanceApprovalService::class)->approve($record);
    $newSource = $f['source']->replicate();
    $newSource->fill(['doc_number' => 81235, 'doc_num' => 'OS-81235'])->save();
    $newLine = $f['line']->replicate();
    $newLine->fill(['opening_stock_id' => $newSource->id, 'public_id' => (string) Str::uuid()])->save();
    expect(fn () => DB::transaction(fn () => app(InventoryOpeningStockPostingService::class)->post($newSource)))
        ->toThrow(DomainException::class)->and(InventoryTransaction::query()->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => app(InventoryOpeningStockPostingService::class)->applyPricing($f['pricing'])))
        ->toThrow(DomainException::class);
    expect(fn () => app(ProductService::class)->update($f['product'], ['item_classification' => Product::ClassificationFinishedProduct]))
        ->toThrow(DomainException::class)->and($f['product']->fresh()->item_classification)->toBe(Product::ClassificationRawMaterial);
});

test('source lineage pricing provenance and stored balanced amounts cannot be tampered', function (string $case): void {
    $f = inventoryOpeningFixture($this);
    $record = app(OpeningBalanceService::class)->create(inventoryOpeningPayload($f))['record'];
    match ($case) {
        'source_quantity' => $f['line']->update(['quantity' => '9']),
        'source_store' => $f['source']->update(['branch_store_id' => null]),
        'pricing_reference' => $f['pricing']->update(['source_reference' => 'tampered reference']),
        'pricing_price' => $f['pricing']->lines()->update(['unit_price' => '99']),
        'balanced_amount' => $record->lines()->update(['debit_amount' => DB::raw('case when debit_amount > 0 then 999 else 0 end'),
            'credit_amount' => DB::raw('case when credit_amount > 0 then 999 else 0 end')]),
    };
    expect(fn () => app(OpeningBalanceApprovalService::class)->approve($record))->toThrow(DomainException::class)
        ->and(DB::table('journal_entries')->count())->toBe(0);
})->with(['source_quantity', 'source_store', 'pricing_reference', 'pricing_price', 'balanced_amount']);

test('available and quarantine opening values retain their separate canonical GL accounts', function (): void {
    $f = inventoryOpeningFixture($this);
    $source = $f['source']->replicate();
    $source->fill(['doc_number' => 81235, 'doc_num' => 'OS-81235'])->save();
    $line = $f['line']->replicate();
    $line->fill(['opening_stock_id' => $source->id, 'public_id' => (string) Str::uuid(), 'stock_status' => InventoryTransaction::StatusQuarantine])->save();
    $pricing = $f['pricing']->replicate();
    $pricing->fill(['opening_stock_id' => $source->id, 'doc_number' => 81235, 'doc_num' => 'OSP-81235'])->save();
    $pricingLine = $f['pricing']->lines()->firstOrFail()->replicate();
    $pricingLine->fill(['pricing_id' => $pricing->id, 'opening_stock_line_id' => $line->id, 'public_id' => (string) Str::uuid()])->save();
    DB::transaction(fn () => app(InventoryOpeningStockPostingService::class)->post($source));
    $quarantineAccount = app(PostingAccountResolver::class)->resolve($f['company']->id, PostingAccountResolver::QuarantineInventory, 'synthetic quarantine opening');
    foreach ([$f['account'], $quarantineAccount] as $account) {
        $selected = [...$f, 'account' => $account];
        $payload = inventoryOpeningPayload($selected);
        $record = app(OpeningBalanceService::class)->create($payload)['record'];
        app(OpeningBalanceApprovalService::class)->approve($record);
        expect($record->fresh()->inventory_valuation_snapshot['amount'])->toBe('123.4568')
            ->and($record->fresh()->inventory_valuation_snapshot['source_rows'])->toHaveCount(1);
    }
    foreach (app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id) as $row) {
        expect($row['difference'])->toBe('0.0000');
    }
});

test('unpriced roots and products without valued history retain the original configuration path', function (): void {
    $f = inventoryOpeningFixture($this);
    $f['movement']->update(['unit_cost' => null, 'total_cost' => null]);
    app(ProductService::class)->update($f['product'], ['item_classification' => Product::ClassificationFinishedProduct]);
    expect($f['product']->fresh()->item_classification)->toBe(Product::ClassificationFinishedProduct);
    $unpriced = $f['source']->replicate();
    $unpriced->fill(['doc_number' => 81235, 'doc_num' => 'OS-81235', 'approved' => false, 'status' => OpeningStock::StatusClosed])->save();
    $line = $f['line']->replicate();
    $line->fill(['opening_stock_id' => $unpriced->id, 'public_id' => (string) Str::uuid()])->save();
    app(OpeningStockService::class)->approve($unpriced);
    expect(InventoryTransaction::query()->where('source_id', $unpriced->id)->firstOrFail()->total_cost)->toBeNull();
});

test('a different active financial period cannot reuse opening value from the selected period', function (): void {
    $f = inventoryOpeningFixture($this);
    $other = FinancialPeriod::create(['company_id' => $f['company']->id, 'doc_number' => 81235, 'doc_num' => 'Period-81235',
        'name' => 'Synthetic separate period', 'from_date' => '2027-01-01', 'to_date' => '2027-12-31', 'is_closed' => false, 'allows_opening_entries' => true]);
    $this->withSession([OperatingContextService::FinancialPeriodIdKey => $other->id,
        OperatingContextService::FinancialPeriodDocNumKey => $other->doc_num])
        ->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $f['account']->doc_num]))->assertUnprocessable();
});
