<?php

use App\Services\IntegratedPlasticFactoryDemoVerifier;
use Database\Seeders\IntegratedPlasticFactorySeeder;
use Database\Seeders\RuntimeDemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Company;
use Modules\FixedAssets\Models\FixedAsset;

test('integrated plastic factory demo data is balanced and idempotent', function (): void {
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('DROP INDEX IF EXISTS companies_one_active_main_unique');
    }

    expect(collect(Artisan::all())->keys())->toContain('erp:demo-data');

    $this->seed(RuntimeDemoDataSeeder::class);
    $unpostedDraftAsset = FixedAsset::withoutGlobalScopes()
        ->where('serial_number', 'COMP-MGY-DEMO-001')
        ->firstOrFail()
        ->replicate();
    $unpostedDraftAccount = Account::withoutGlobalScopes()
        ->findOrFail($unpostedDraftAsset->account_id)
        ->replicate();
    $unpostedDraftAccount->forceFill([
        'doc_number' => 'ACC-TEST-UNPOSTED-DRAFT',
        'doc_num' => 'ACC-TEST-UNPOSTED-DRAFT',
        'account_code' => '999999',
        'name' => 'Test-only unposted draft asset account',
        'name_en' => 'Test-only unposted draft asset account',
    ])->save();
    $unpostedDraftAsset->forceFill([
        'doc_number' => 'FA-TEST-UNPOSTED-DRAFT',
        'doc_num' => 'FA-TEST-UNPOSTED-DRAFT',
        'account_id' => $unpostedDraftAccount->getKey(),
        'asset_name' => 'Test-only unposted draft asset',
        'serial_number' => 'TEST-UNPOSTED-DRAFT-ASSET',
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'status' => FixedAsset::StatusDraft,
        'purchase_value' => '25000',
        'base_acquisition_value' => '25000',
        'previous_depreciation' => '0',
        'net_value' => '25000',
        'capitalized_at' => null,
        'capitalized_by' => null,
        'notes' => 'Excluded from the capitalized Fixed Asset subledger until activation.',
    ])->save();

    $verifier = app(IntegratedPlasticFactoryDemoVerifier::class);
    $first = $verifier->verify();
    $failedChecks = collect($first['checks'])->filter(fn (bool $passed): bool => ! $passed)->keys()->all();

    expect($failedChecks)->toBe([])
        ->and($first['ok'])->toBeTrue()
        ->and($first['counts']['customers'])->toBeGreaterThanOrEqual(8)
        ->and($first['counts']['suppliers'])->toBeGreaterThanOrEqual(6)
        ->and($first['counts']['products'])->toBeGreaterThanOrEqual(18)
        ->and($first['counts']['units'])->toBeGreaterThanOrEqual(7)
        ->and($first['counts']['boms'])->toBeGreaterThanOrEqual(3)
        ->and($first['counts']['machines'])->toBeGreaterThanOrEqual(3)
        ->and($first['counts']['molds'])->toBeGreaterThanOrEqual(3)
        ->and($first['counts']['fixed_assets'])->toBeGreaterThanOrEqual(10)
        ->and($first['counts']['purchase_order_changes'])->toBeGreaterThanOrEqual(2)
        ->and($first['counts']['fixed_asset_movements'])->toBeGreaterThanOrEqual(1)
        ->and($first['counts']['fixed_asset_disposals'])->toBeGreaterThanOrEqual(3)
        ->and($first['checks']['sales_stock_production_delivery_and_return_are_exact'])->toBeTrue()
        ->and($first['checks']['inventory_subledger_reconciles_to_gl'])->toBeTrue()
        ->and($first['checks']['supplier_subledgers_reconcile_to_gl'])->toBeTrue()
        ->and($first['checks']['customer_subledgers_reconcile_to_gl'])->toBeTrue()
        ->and($first['checks']['customer_statement_examples_are_settled_outstanding_and_credit'])->toBeTrue()
        ->and($first['checks']['production_linked_procurement_report_is_populated'])->toBeTrue()
        ->and($first['customer_balances']['Fully settled']['closing_balance'])->toBe('0.0000')
        ->and((float) $first['customer_balances']['Outstanding']['closing_balance'])->toBeGreaterThan(0)
        ->and((float) $first['customer_balances']['Credit balance']['closing_balance'])->toBeLessThan(0)
        ->and($first['checks']['purchase_order_lifecycle_states_and_changes_exist'])->toBeTrue()
        ->and($first['checks']['fixed_asset_lifecycle_is_complete'])->toBeTrue()
        ->and($first['documents']['sales_quotations'])->not->toBeEmpty()
        ->and($first['documents']['purchase_requisition'])->not->toBeEmpty()
        ->and($first['documents']['production_orders'])->not->toBeEmpty()
        ->and($first['documents']['purchase_order_changes'])->not->toBeEmpty()
        ->and($first['documents']['fixed_asset_disposals'])->not->toBeEmpty();

    $this->seed(IntegratedPlasticFactorySeeder::class);
    $second = $verifier->verify();

    expect($second['counts'])->toBe($first['counts'])
        ->and($second['documents'])->toBe($first['documents'])
        ->and(Company::query()->where('name', IntegratedPlasticFactorySeeder::CompanyName)->count())->toBe(1);

    $this->artisan('erp:demo-data', ['--verify' => true])
        ->expectsOutputToContain('[PASS] all journals are balanced and nonzero')
        ->expectsOutputToContain('[PASS] sales stock production delivery and return are exact')
        ->expectsOutputToContain('[PASS] inventory subledger reconciles to gl')
        ->expectsOutputToContain('[PASS] supplier subledgers reconcile to gl')
        ->expectsOutputToContain('[PASS] customer subledgers reconcile to gl')
        ->expectsOutputToContain('[PASS] customer statement examples are settled outstanding and credit')
        ->expectsOutputToContain('[PASS] production linked procurement report is populated')
        ->expectsOutputToContain('[PASS] purchase order lifecycle states and changes exist')
        ->expectsOutputToContain('[PASS] fixed asset lifecycle is complete')
        ->assertSuccessful();
});
