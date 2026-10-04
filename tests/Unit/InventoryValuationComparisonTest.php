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
        ->and($result['consumed_quantity'])->toBe('150.00000000')
        ->and($result['ending_quantity'])->toBe('100.00000000')
        ->and($result['methods']['moving_average']['issue_cost'])->toBe('2000.00000000')
        ->and($result['methods']['moving_average']['ending_value'])->toBe('1400.00000000')
        ->and($result['methods']['moving_average']['book_method'])->toBeTrue()
        ->and($result['methods']['periodic_weighted_average']['issue_cost'])->toBe('2040.00000000')
        ->and($result['methods']['periodic_weighted_average']['ending_value'])->toBe('1360.00000000')
        ->and($result['methods']['periodic_weighted_average']['difference_vs_reference'])->toBe('-40.00000000')
        ->and($result['methods']['fifo']['issue_cost'])->toBe('1700.00000000')
        ->and($result['methods']['fifo']['ending_value'])->toBe('1700.00000000')
        ->and($result['methods']['fifo']['difference_vs_reference'])->toBe('300.00000000')
        ->and($result['methods']['lifo']['issue_cost'])->toBe('2400.00000000')
        ->and($result['methods']['lifo']['ending_value'])->toBe('1000.00000000')
        ->and($result['methods']['lifo']['difference_vs_reference'])->toBe('-400.00000000')
        ->and($result['methods']['lifo']['book_method'])->toBeFalse()
        ->and($result['methods']['last_inbound_reference']['issue_cost'])->toBeNull()
        ->and($result['methods']['last_inbound_reference']['ending_value'])->toBe('2000.00000000')
        ->and($result['methods']['last_inbound_reference']['reference_only'])->toBeTrue()
        ->and($result['methods']['last_purchase_reference']['ending_value'])->toBeNull()
        ->and($result['checkpoints'][0]['moving_average_unit_cost'])->toBe('10.00000000')
        ->and($result['checkpoints'][1]['moving_average_unit_cost'])->toBe('12.00000000')
        ->and($result['checkpoints'][3]['moving_average_unit_cost'])->toBe('14.00000000')
        ->and($result['remaining_fifo_layers'])->toBe([
            ['quantity' => '50.00000000', 'unit_cost' => '14.00000000'],
            ['quantity' => '50.00000000', 'unit_cost' => '20.00000000'],
        ]);
});

test('comparison reference can change without changing the official book method', function (): void {
    $result = app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '100', 'quantity_out' => '0', 'unit_cost' => '10'],
        ['quantity_in' => '100', 'quantity_out' => '0', 'unit_cost' => '14'],
        ['quantity_in' => '0', 'quantity_out' => '50'],
        ['quantity_in' => '50', 'quantity_out' => '0', 'unit_cost' => '20'],
        ['quantity_in' => '0', 'quantity_out' => '100'],
    ], 'fifo');

    expect($result['reference_method'])->toBe('fifo')
        ->and($result['methods']['moving_average']['book_method'])->toBeTrue()
        ->and($result['methods']['moving_average']['difference_vs_reference'])->toBe('-300.00000000')
        ->and($result['methods']['periodic_weighted_average']['difference_vs_reference'])->toBe('-340.00000000')
        ->and($result['methods']['fifo']['difference_vs_reference'])->toBe('0.00000000');
});

test('internal movement reduces the position but is not reported as consumption or cogs', function (): void {
    $result = app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '100', 'quantity_out' => '0', 'unit_cost' => '10', 'type' => 'inventory_receipt'],
        ['quantity_in' => '0', 'quantity_out' => '20', 'type' => 'inventory_transfer', 'counts_as_consumption' => false],
    ]);

    expect($result['issued_quantity'])->toBe('20.00000000')
        ->and($result['consumed_quantity'])->toBe('0.00000000')
        ->and($result['ending_quantity'])->toBe('80.00000000')
        ->and($result['methods']['moving_average']['issue_cost'])->toBe('0.00000000')
        ->and($result['methods']['moving_average']['ending_value'])->toBe('800.00000000')
        ->and($result['methods']['periodic_weighted_average']['ending_value'])->toBe('800.00000000')
        ->and($result['methods']['fifo']['ending_value'])->toBe('800.00000000');
});

test('valuation comparison refuses an unsupported reference method', function (): void {
    expect(fn () => app(InventoryValuationService::class)->compareMovements([], 'unrecognized_method'))
        ->toThrow(DomainException::class, 'inventory_accounting.errors.invalid_reference_method');
});

test('last inbound and approved purchase remain distinct analytical references', function (): void {
    $result = app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '100', 'quantity_out' => '0', 'unit_cost' => '10'],
        ['quantity_in' => '100', 'quantity_out' => '0', 'unit_cost' => '14'],
        ['quantity_in' => '0', 'quantity_out' => '50'],
        ['quantity_in' => '50', 'quantity_out' => '0', 'unit_cost' => '20'],
        ['quantity_in' => '0', 'quantity_out' => '100'],
    ], 'last_purchase_reference', [
        'unit_cost' => '12.00000000',
        'date' => '2026-09-25',
        'document' => 'PINV-001',
        'source' => 'approved_invoice',
        'currency' => 'USD',
        'exchange_rate' => '50.000000',
        'basis' => 'net_line_excluding_freight_tax',
    ]);

    expect($result['reference_method'])->toBe('last_purchase_reference')
        ->and($result['methods']['last_inbound_reference']['ending_value'])->toBe('2000.00000000')
        ->and($result['methods']['last_purchase_reference']['ending_value'])->toBe('1200.00000000')
        ->and($result['methods']['last_purchase_reference']['source']['document'])->toBe('PINV-001')
        ->and($result['methods']['last_purchase_reference']['reference_only'])->toBeTrue()
        ->and($result['methods']['last_purchase_reference']['issue_cost'])->toBeNull()
        ->and($result['methods']['last_purchase_reference']['difference_vs_reference'])->toBeNull()
        ->and($result['methods']['moving_average']['book_method'])->toBeTrue()
        ->and($result['methods']['moving_average']['difference_vs_reference'])->toBe('200.00000000');
});

test('approved purchase reference stays unknown without a qualifying purchase', function (): void {
    $result = app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '10', 'quantity_out' => '0', 'unit_cost' => '5', 'type' => 'production_receipt'],
    ], 'last_purchase_reference');

    expect($result['methods']['last_inbound_reference']['ending_value'])->toBe('50.00000000')
        ->and($result['methods']['last_purchase_reference']['ending_value'])->toBeNull()
        ->and($result['methods']['last_purchase_reference']['ending_unit_cost'])->toBeNull()
        ->and($result['methods']['moving_average']['difference_vs_reference'])->toBeNull();
});

test('transfer and return receipts change last inbound without changing an approved purchase reference', function (): void {
    $result = app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '2', 'quantity_out' => '0', 'unit_cost' => '10', 'type' => 'purchase_receipt'],
        ['quantity_in' => '1', 'quantity_out' => '0', 'unit_cost' => '13', 'type' => 'inventory_transfer'],
        ['quantity_in' => '1', 'quantity_out' => '0', 'unit_cost' => '15', 'type' => 'sales_return_receipt'],
    ], 'last_inbound_reference', [
        'unit_cost' => '10.00000000',
        'date' => '2026-09-29',
        'document' => 'GRN-001',
        'source' => 'approved_receipt',
        'currency' => 'EGP',
        'exchange_rate' => '1',
        'basis' => 'net_order_line_excluding_freight_tax',
    ]);

    expect($result['methods']['last_inbound_reference']['ending_value'])->toBe('60.00000000')
        ->and($result['methods']['last_purchase_reference']['ending_value'])->toBe('40.00000000')
        ->and($result['methods']['last_inbound_reference']['source']['movement_type'])->toBe('sales_return_receipt')
        ->and($result['methods']['last_purchase_reference']['source']['document'])->toBe('GRN-001');
});

test('valuation comparison refuses a sequence that creates negative stock', function (): void {
    expect(fn () => app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '10', 'quantity_out' => '0', 'unit_cost' => '5'],
        ['quantity_in' => '0', 'quantity_out' => '11'],
    ]))->toThrow(DomainException::class, 'inventory_accounting.errors.negative_valuation_stock');
});

test('valuation comparison refuses unvalued receipts', function (): void {
    expect(fn () => app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '10', 'quantity_out' => '0'],
    ]))->toThrow(DomainException::class, 'inventory_accounting.errors.unvalued_receipt');
});

test('returns and positive adjustments retain their own chronological receipt costs', function (): void {
    $result = app(InventoryValuationService::class)->compareMovements([
        ['quantity_in' => '10', 'quantity_out' => '0', 'unit_cost' => '10', 'type' => 'opening_stock'],
        ['quantity_in' => '0', 'quantity_out' => '4', 'type' => 'sales_delivery'],
        ['quantity_in' => '2', 'quantity_out' => '0', 'unit_cost' => '10', 'type' => 'sales_return_receipt'],
        ['quantity_in' => '2', 'quantity_out' => '0', 'unit_cost' => '15', 'type' => 'positive_adjustment'],
        ['quantity_in' => '0', 'quantity_out' => '5', 'type' => 'sales_delivery'],
    ]);

    expect($result['ending_quantity'])->toBe('5.00000000')
        ->and($result['methods']['moving_average']['issue_cost'])->toBe('95.00000000')
        ->and($result['methods']['moving_average']['ending_value'])->toBe('55.00000000')
        ->and($result['methods']['fifo']['issue_cost'])->toBe('90.00000000')
        ->and($result['methods']['fifo']['ending_value'])->toBe('60.00000000')
        ->and($result['checkpoints'][2]['moving_average_unit_cost'])->toBe('10.00000000')
        ->and($result['checkpoints'][3]['moving_average_unit_cost'])->toBe('11.00000000');
});
