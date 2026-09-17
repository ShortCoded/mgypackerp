<?php

use Modules\Inventory\Services\InventoryValuationService;

test('valuation methods are calculated independently without changing the book policy', function (): void {
    $result = app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '100', 'quantity_out' => '0', 'unit_cost' => '10'],
        ['quantity_in' => '100', 'quantity_out' => '0', 'unit_cost' => '14'],
        ['quantity_in' => '0', 'quantity_out' => '50'],
        ['quantity_in' => '50', 'quantity_out' => '0', 'unit_cost' => '20'],
        ['quantity_in' => '0', 'quantity_out' => '100'],
    ]);

    expect($result['available_quantity'])->toBe('250.00000000')
        ->and($result['available_cost'])->toBe('3400.00000000')
        ->and($result['issued_quantity'])->toBe('150.00000000')
        ->and($result['ending_quantity'])->toBe('100.00000000')
        ->and($result['methods']['moving_average']['issue_cost'])->toBe('2000.00000000')
        ->and($result['methods']['moving_average']['ending_value'])->toBe('1400.00000000')
        ->and($result['methods']['moving_average']['book_method'])->toBeTrue()
        ->and($result['methods']['periodic_weighted_average']['issue_cost'])->toBe('2040.00000000')
        ->and($result['methods']['periodic_weighted_average']['ending_value'])->toBe('1360.00000000')
        ->and($result['methods']['fifo']['issue_cost'])->toBe('1700.00000000')
        ->and($result['methods']['fifo']['ending_value'])->toBe('1700.00000000')
        ->and($result['methods']['last_purchase_reference']['issue_cost'])->toBeNull()
        ->and($result['methods']['last_purchase_reference']['ending_value'])->toBe('2000.00000000')
        ->and($result['methods']['last_purchase_reference']['reference_only'])->toBeTrue();
});

test('valuation comparison refuses a sequence that creates negative stock', function (): void {
    expect(fn () => (new InventoryValuationService)->compareMovements([
        ['quantity_in' => '10', 'quantity_out' => '0', 'unit_cost' => '5'],
        ['quantity_in' => '0', 'quantity_out' => '11'],
    ]))->toThrow(\DomainException::class, 'inventory_accounting.errors.negative_valuation_stock');
});

test('valuation comparison refuses unvalued receipts', function (): void {
    expect(fn () => (new InventoryValuationService)->compareMovements([
        ['quantity_in' => '10', 'quantity_out' => '0'],
    ]))->toThrow(\DomainException::class, 'inventory_accounting.errors.unvalued_receipt');
});
