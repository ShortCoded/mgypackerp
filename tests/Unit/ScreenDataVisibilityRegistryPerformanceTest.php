<?php

use Modules\Core\Models\Product;
use Modules\Core\Services\ScreenDataVisibilityRegistry;
use Modules\Finance\Models\CashVoucher;

test('visibility registry reuses immutable definitions and a model-specific route index', function (): void {
    $registry = new ScreenDataVisibilityRegistry;
    $definitions = $registry->all();
    $unsupportedDefinitions = $registry->unsupported();

    $definitions['customers']['table'] = 'mutated';
    $unsupportedDefinitions['accounts']['supported'] = true;

    expect($registry->all()['customers']['table'])->toBe('customers')
        ->and($registry->unsupported()['accounts']['supported'])->toBeFalse()
        ->and($registry->screenKeyForModelAndRoute(Product::class, 'admin.products.index'))->toBe('products');

    $definitionsProperty = new ReflectionProperty($registry, 'definitions');
    $unsupportedProperty = new ReflectionProperty($registry, 'unsupportedDefinitions');
    $routeIndexProperty = new ReflectionProperty($registry, 'routePatternsByModel');
    $routeIndex = $routeIndexProperty->getValue($registry);

    expect($definitionsProperty->getValue($registry))->toHaveCount(30)
        ->and($unsupportedProperty->getValue($registry))->toHaveCount(6)
        ->and(array_keys($routeIndex[Product::class]))->toBe([
            'products',
            'raw_materials',
            'packaging_materials',
        ])
        ->and(array_keys($routeIndex[CashVoucher::class]))->toBe([
            'cash_receipt_vouchers',
            'cash_payment_vouchers',
        ]);
});

test('visibility route lookup keeps first-match behavior within each model', function (string $model, string $route, ?string $screenKey): void {
    expect((new ScreenDataVisibilityRegistry)->screenKeyForModelAndRoute($model, $route))->toBe($screenKey);
})->with([
    'finished products' => [Product::class, 'admin.products.index', 'products'],
    'raw material select' => [Product::class, 'admin.select2.raw-material-products', 'raw_materials'],
    'combined component select' => [Product::class, 'admin.select2.component-products', null],
    'packaging material screen' => [Product::class, 'admin.packaging-materials.index', 'packaging_materials'],
    'cash receipt' => [CashVoucher::class, 'admin.finance.cash-receipt-vouchers.index', 'cash_receipt_vouchers'],
    'cash payment' => [CashVoucher::class, 'admin.finance.cash-payment-vouchers.show', 'cash_payment_vouchers'],
    'unrelated route' => [Product::class, 'admin.users.index', null],
    'missing route' => [Product::class, '', null],
]);
