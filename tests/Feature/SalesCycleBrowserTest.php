<?php

test('sales and manufacturing browser routes resolve only to concrete controllers', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('admin.sales.sales-orders.create')?->getActionName())->toContain('SalesCycleController@createOrder')
        ->and($routes->getByName('admin.sales.sales-orders.edit')?->getActionName())->toContain('SalesCycleController@editOrder')
        ->and($routes->getByName('admin.sales.customer-receipts.create')?->getActionName())->toContain('SalesCycleController@createReceipt')
        ->and($routes->getByName('admin.sales.sales-invoices.edit')?->getActionName())->toContain('SalesCycleController@editInvoice')
        ->and($routes->getByName('admin.production.work-orders.index')?->getActionName())->toContain('ProductionOrderController@index')
        ->and($routes->getByName('admin.production.work-orders.complete')?->getActionName())->toContain('ProductionOrderController@complete')
        ->and(collect($routes)->where(fn ($route) => $route->getName() === 'admin.sales.sales-orders.index'))->toHaveCount(1);
});
