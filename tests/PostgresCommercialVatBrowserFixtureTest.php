<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\PriceListPricingService;
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/CommercialVatPercentageSupport.php';

test('prepare only synthetic native VAT forms in the explicitly owned browser clone', function (): void {
    if (getenv('MGYPACK_VAT_BROWSER_FIXTURE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit local synthetic browser fixture preparation only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_vat_percentage_pg_20261006')->and($identity->host)->toBe('127.0.0.1')->and((int) $identity->port)->toBe(5432);
    $path = storage_path('app/test-artifacts/mgypack-percentage-vat-20261006/browser-fixture.json');
    expect(file_exists($path))->toBeFalse();
    $f = commercialVatFixture();
    foreach (['quotations.create', 'quotations.view', 'purchases.prices.view', 'purchases.direct_procurement.override', 'purchase_orders.create', 'purchase_orders.view', 'purchase_orders.approve',
        'purchase_invoices.create', 'purchase_invoices.view', 'purchases.supplier_quotation_entry.create', 'purchases.supplier_quotation_entry.view',
        'sales_requests.view', 'sales_requests.create', 'sales_requests.approve'] as $ability) {
        $f['user']->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    expect(str_starts_with($f['user']->username, 'synthetic-closure-'))->toBeTrue()->and(str_starts_with($f['company']->name, 'SYNTHETIC'))->toBeTrue();
    $requests = app(SalesRequestService::class);
    $source = $requests->save(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id,
        'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '10']]]);
    $requests->transition($source, 'submitted');
    $requests->transition($source->fresh(), 'approved');
    $supplier = Supplier::query()->create(['company_id' => $f['company']->id, 'doc_number' => 99102, 'doc_num' => 'SYNTHETIC-VAT-SUPPLIER-'.$f['company']->id,
        'name' => 'SYNTHETIC VAT supplier', 'status' => 'active']);
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create(['supplier_doc_num' => $supplier->doc_num, 'branch_store_uuid' => $f['store']->public_uuid,
        'currency_doc_num' => $f['currency']->doc_num, 'document_date' => now()->toDateString(), 'exchange_rate' => '1',
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'SYNTHETIC native VAT browser fixture',
        'header_discount_type' => 'fixed', 'header_discount_value' => '50',
        'lines' => [['product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'ordered_quantity' => '10', 'unit_price' => '1000',
            'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14']]])['record']);
    $fixture = ['database' => $identity->db, 'company_id' => $f['company']->id,
        'actors' => ['operator' => ['id' => $f['user']->id, 'username' => $f['user']->username, 'password' => 'password']],
        'context' => ['company_doc_num' => $f['company']->doc_num, 'branch_doc_num' => $f['branch']->doc_num, 'financial_period_doc_num' => $f['period']->doc_num],
        'request' => $source->doc_num, 'purchase_order' => $order->doc_num, 'supplier_quote_source' => SupplierQuotation::SourcePurchaseOrder,
        'source_line_public_id' => $source->fresh()->lines->sole()->public_id, 'service' => $f['service']->doc_num, 'unit' => $f['unit']->doc_num,
        'currency' => $f['currency']->doc_num, 'customer' => $f['customer']->doc_num, 'supplier' => $supplier->doc_num,
        'paths' => ['sales_order' => parse_url(route('admin.sales.sales-orders.create', ['source_request_doc_num' => $source->doc_num]), PHP_URL_PATH).'?source_request_doc_num='.urlencode($source->doc_num),
            'direct_invoice' => parse_url(route('admin.sales.sales-invoices.create'), PHP_URL_PATH).'?direct=1&source_request_doc_num='.urlencode($source->doc_num),
            'quotation' => parse_url(route('admin.sales.quotations.create'), PHP_URL_PATH),
            'purchase_order' => parse_url(route('admin.purchases.purchase-orders.create'), PHP_URL_PATH),
            'purchase_invoice' => parse_url(route('admin.purchases.purchase-invoices.create'), PHP_URL_PATH).'?purchase_order='.urlencode($order->doc_num),
            'supplier_quotation' => parse_url(route('admin.purchases.supplier-quotation-entry.create-source', [SupplierQuotation::SourcePurchaseOrder, $order]), PHP_URL_PATH)],
        'default_database_writes' => false, 'only_new_synthetic_business_fixture' => true];
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($order->status)->toBe('approved')->and($source->fresh()->status)->toBe('approved');
});

test('prepare a native reopened final partial quotation order in the owned browser clone', function (): void {
    if (getenv('MGYPACK_VAT_BROWSER_RESIDUAL_FIXTURE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit local synthetic residual browser preparation only.');
    }
    expect(DB::selectOne('select current_database() as db')->db)->toBe('mgypack_vat_percentage_pg_20261006');
    $path = storage_path('app/test-artifacts/mgypack-percentage-vat-20261006/browser-fixture.json');
    $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (isset($fixture['partial_order'])) {
        $fixture['prior_partial_fixture_attempts'][] = $fixture['partial_order'];
        unset($fixture['partial_order']);
    }
    $user = User::query()->findOrFail($fixture['actors']['operator']['id']);
    $company = Company::query()->findOrFail($fixture['company_id']);
    expect(str_starts_with($user->username, 'synthetic-closure-'))->toBeTrue()->and(str_starts_with($company->name, 'SYNTHETIC'))->toBeTrue();
    $branch = $company->branches()->where('doc_num', $fixture['context']['branch_doc_num'])->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->id)->where('doc_num', $fixture['context']['financial_period_doc_num'])->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->id)->where('doc_num', $fixture['currency'])->firstOrFail();
    $unit = ItemUnit::query()->where('company_id', $company->id)->where('doc_num', $fixture['unit'])->firstOrFail();
    $customer = Customer::query()->where('company_id', $company->id)->where('doc_num', $fixture['customer'])->firstOrFail();
    $this->actingAs($user)->withSession(salesCycleSession(compact('company', 'branch', 'period')));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession(compact('company', 'branch', 'period')));
    request()->setUserResolver(fn () => $user);
    foreach (['quotations.mark_sent', 'quotations.accept', 'sales_orders.reopen', 'sales_orders.approve'] as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $service = Product::query()->create(['company_id' => $company->id,
        'doc_number' => (int) Product::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-VAT-ROUNDING-'.$company->id.'-'.Str::random(6), 'name' => 'SYNTHETIC residual service',
        'item_classification' => Product::ClassificationService, 'item_unit_id' => $unit->id, 'status' => 'active']);
    createSalesPriceList(compact('company', 'branch', 'period', 'currency', 'user'), null,
        [['product' => $service, 'price' => '22.54545', 'discount_type' => 'percentage', 'discount_value' => '100']]);
    $quotes = app(QuotationService::class);
    $price = app(PriceListPricingService::class)->resolve($company->id, $customer->id, $currency->id, $service, $unit->id, '3', now()->toDateString());
    $quote = $quotes->create(['branch_id' => $branch->id, 'customer_doc_num' => $customer->doc_num, 'currency_doc_num' => $currency->doc_num,
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(),
        'discount_type' => 'fixed', 'discount_value' => '0.0002', 'payment_milestones' => [],
        'lines' => [[...$price, 'product_doc_num' => $service->doc_num, 'unit_doc_num' => $unit->doc_num, 'quantity' => '3',
            'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14']]])['record'];
    expect($quote->currentRevision->lines->sole()->allowed_discount_value)->toBe('100.0000');
    $quote = $quotes->accept($quotes->markSent($quote));
    $orders = app(SalesOrderService::class);
    $context = ['company_id' => $company->id, 'branch_id' => $branch->id, 'financial_period_id' => $period->id];
    $created = [];
    for ($index = 0; $index < 3; $index++) {
        $created[] = $orders->createFromQuotation($quote->fresh(), $context, [['public_id' => $quote->currentRevision->lines->sole()->public_uuid, 'quantity' => '1']]);
    }
    $order = $orders->reopen($orders->approve($created[2]), 'SYNTHETIC unchanged rounding browser proof')->load('lines');
    expect($order->total_amount)->toBe('23.1314')->and($order->lines->sole()->discount_amount)->toBe('2.2548');
    $fixture['partial_order'] = ['id' => $order->id, 'doc_num' => $order->doc_num,
        'edit_path' => parse_url(route('admin.sales.sales-orders.edit', $order), PHP_URL_PATH),
        'update_path' => parse_url(route('admin.sales.sales-orders.update', $order), PHP_URL_PATH),
        'quote_total' => $quote->currentRevision->total, 'order_totals' => array_map(fn ($item): string => $item->total_amount, $created),
        'before_header' => $order->getAttributes(), 'before_lines' => $order->lines->map->getAttributes()->all(),
        'before_audit_count' => DB::table('activity_log')->where('subject_type', SalesOrder::class)->where('subject_id', $order->id)->count()];
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});
