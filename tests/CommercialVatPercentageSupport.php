<?php

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

/** @return array<string, mixed> */
function commercialVatFixture(string $price = '1000'): array
{
    $f = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    foreach (['customer_invoices.create', 'customer_invoices.edit', 'customer_invoices.view', 'customer_invoices.view_prices', 'customer_invoices.post', 'customer_invoices.reopen',
        'sales_orders.create', 'sales_orders.edit', 'sales_orders.view', 'sales_orders.view_prices', 'sales_orders.approve'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $f['user']->givePermissionTo($ability);
    }
    test()->actingAs($f['user'])->withSession(salesCycleSession($f));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($f));
    request()->setUserResolver(fn () => $f['user']);
    createSalesPriceList($f, null, [['product' => $f['service'], 'price' => $price, 'discount_type' => 'percentage', 'discount_value' => '100']]);

    return $f;
}

/** @return array<string, mixed> */
function commercialVatDirectPayload(array $f, array $overrides = []): array
{
    return [...salesCycleOrderPayload($f), 'customer_doc_num' => $f['customer']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
        'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'discount_type' => 'fixed', 'discount_value' => '50',
        'lines' => [['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
            'quantity' => '1', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14']], ...$overrides];
}
