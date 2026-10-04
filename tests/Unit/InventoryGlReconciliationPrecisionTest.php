<?php

use Modules\Inventory\Services\InventoryGlReconciliationService;

test('inventory reconciliation keeps exact large decimal amounts and flags any four-decimal variance', function (): void {
    $service = (new ReflectionClass(InventoryGlReconciliationService::class))->newInstanceWithoutConstructor();
    $amount = new ReflectionMethod(InventoryGlReconciliationService::class, 'amount');
    $row = new ReflectionMethod(InventoryGlReconciliationService::class, 'row');

    expect($amount->invoke($service, '999999999999.1234'))->toBe('999999999999.1234')
        ->and($amount->invoke($service, '-999999999999.1234'))->toBe('-999999999999.1234')
        ->and($amount->invoke($service, '1.2345E+12'))->toBe('1234500000000.0000')
        ->and($amount->invoke($service, 1.2E-5))->toBe('0.0000')
        ->and($amount->invoke($service, null))->toBe('0.0000')
        ->and($row->invoke($service, 'finished_goods', 'Finished goods', '0.0001', '0.0000')['status'])->toBe('difference');
});
