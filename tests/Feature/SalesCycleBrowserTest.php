<?php

test('sales and manufacturing browser routes resolve only to concrete controllers', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('admin.sales.sales-orders.create')?->getActionName())->toContain('SalesCycleController@createOrder')
        ->and($routes->getByName('admin.sales.quotations.convert')?->getActionName())->toContain('QuotationController@convert')
        ->and($routes->getByName('admin.sales.quotations.print')?->getActionName())->toContain('QuotationController@print')
        ->and($routes->getByName('admin.sales.sales-orders.edit')?->getActionName())->toContain('SalesCycleController@editOrder')
        ->and($routes->getByName('admin.sales.customer-receipts.create')?->getActionName())->toContain('SalesCycleController@createReceipt')
        ->and($routes->getByName('admin.sales.sales-invoices.edit')?->getActionName())->toContain('SalesCycleController@editInvoice')
        ->and($routes->getByName('admin.production.work-orders.index')?->getActionName())->toContain('ProductionOrderController@index')
        ->and($routes->getByName('admin.production.work-orders.complete')?->getActionName())->toContain('ProductionOrderController@complete')
        ->and(collect($routes)->where(fn ($route) => $route->getName() === 'admin.sales.sales-orders.index'))->toHaveCount(1);
});

test('sales navigation exposes only canonical operational screens and no child shells', function () {
    $routes = app('router')->getRoutes();
    $menu = require config_path('menu/sales.php');
    $menuRoutes = collect($menu[0]['children'])->pluck('route')->all();

    expect($menuRoutes)->toBe([
        'admin.sales.customers.index',
        'admin.sales.quotations.index',
        'admin.sales.sales-orders.index',
        'admin.sales.delivery-notes.index',
        'admin.sales.sales-invoices.index',
        'admin.sales.customer-receipts.index',
        'admin.sales.sales-returns.index',
        'admin.reports.sales.sales-orders.index',
    ]);

    foreach ([
        'admin.sales.sales-order-lines.index',
        'admin.sales.sales-order-specifications.index',
        'admin.sales.sales-order-payment-schedule.index',
        'admin.sales.sales-invoice-lines.index',
        'admin.sales.customer-receipt-allocations.index',
        'admin.sales.customer-advances.index',
        'admin.sales.trip-sheets.index',
    ] as $shellRoute) {
        expect($routes->getByName($shellRoute))->toBeNull();
    }
});
