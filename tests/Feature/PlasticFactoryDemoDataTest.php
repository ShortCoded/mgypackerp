<?php

use App\Services\PlasticFactoryDemoVerifier;
use Database\Seeders\DefaultAdminSeeder;
use Database\Seeders\DefaultOperatingContextSeeder;
use Database\Seeders\FixedAssetsProcurementClientDemoSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;

test('fixed assets and procurement client demo data is balanced scoped and idempotent', function (): void {
    $this->seed([DefaultOperatingContextSeeder::class, DefaultAdminSeeder::class]);

    $company = Company::query()->active()->firstOrFail();
    $companyName = $company->name;

    $this->seed(FixedAssetsProcurementClientDemoSeeder::class);
    $verifier = app(PlasticFactoryDemoVerifier::class);
    $first = $verifier->verify();
    $supplierBInvoice = DB::table('purchase_invoices')->where('doc_num', 'PINV-00001-00002')->firstOrFail();
    $supplierBSchedules = DB::table('purchase_invoice_payment_schedules')
        ->where('purchase_invoice_id', $supplierBInvoice->id)
        ->orderBy('line_number')
        ->get();

    expect($first['ok'])->toBeTrue()
        ->and($first['checks'])->each->toBeTrue()
        ->and($first['counts']['assets'])->toBeGreaterThanOrEqual(13)
        ->and($first['counts']['suppliers'])->toBe(7)
        ->and($first['counts']['products'])->toBe(10)
        ->and($first['counts']['purchase_orders'])->toBe(2)
        ->and($first['counts']['goods_receipts'])->toBe(3)
        ->and($first['documents']['purchase_requisitions'])->not->toBeEmpty()
        ->and($first['evidence']['supplier_a_outstanding'])->toBe('0.0000')
        ->and($first['evidence']['supplier_b_outstanding'])->toBe('40000.0000')
        ->and($first['evidence']['opening_asset_gl_difference'])->toBe('0.0000')
        ->and(DB::table('purchase_return_lines')->whereNull('company_id')->count())->toBe(0)
        ->and(DB::table('purchase_return_lines')->whereNull('financial_period_id')->count())->toBe(0)
        ->and(DB::table('purchase_return_lines')->whereNull('line_number')->count())->toBe(0)
        ->and(DB::table('purchase_orders')->where('notes', FixedAssetsProcurementClientDemoSeeder::DraftPurchaseOrderNote)->where('status', 'draft')->exists())->toBeTrue()
        ->and(DB::table('purchase_invoices')->where('notes', FixedAssetsProcurementClientDemoSeeder::DraftInvoiceNote)->where('status', 'draft')->exists())->toBeTrue()
        ->and(DB::table('purchase_invoices')->where('notes', FixedAssetsProcurementClientDemoSeeder::UnpaidInvoiceNote)->where('status', 'approved')->where('remaining_amount', '>', 0)->exists())->toBeTrue()
        ->and($supplierBSchedules->pluck('paid_amount')->map(fn (mixed $amount): float => (float) $amount)->all())->toBe([56954.4, 16954.4])
        ->and($supplierBSchedules->pluck('status')->all())->toBe(['paid', 'partially_paid'])
        ->and((float) $supplierBSchedules->sum(fn (object $schedule): float => (float) $schedule->amount - (float) $schedule->paid_amount - (float) $schedule->credited_amount))->toBe(40000.0)
        ->and(Company::query()->findOrFail($company->getKey())->name)->toBe($companyName);

    $markerCounts = [
        'assets' => DB::table('fixed_assets')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
        'requisitions' => DB::table('purchase_requisitions')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
        'purchase_orders' => DB::table('purchase_orders')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
        'purchase_invoices' => DB::table('purchase_invoices')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
        'opening_stocks' => DB::table('inventory_opening_stocks')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
    ];

    $this->seed(FixedAssetsProcurementClientDemoSeeder::class);
    $second = $verifier->verify();

    expect($second['counts'])->toBe($first['counts'])
        ->and($second['documents'])->toBe($first['documents'])
        ->and([
            'assets' => DB::table('fixed_assets')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
            'requisitions' => DB::table('purchase_requisitions')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
            'purchase_orders' => DB::table('purchase_orders')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
            'purchase_invoices' => DB::table('purchase_invoices')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
            'opening_stocks' => DB::table('inventory_opening_stocks')->where('notes', 'like', '%'.FixedAssetsProcurementClientDemoSeeder::Marker.'%')->count(),
        ])->toBe($markerCounts);
});
