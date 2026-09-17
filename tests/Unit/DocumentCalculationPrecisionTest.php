<?php

use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseOrderCalculationService;
use Modules\Sales\Services\QuotationCalculationService;

test('quotation calculation preserves direct input decimals before persistence', function (): void {
    $calculator = app(QuotationCalculationService::class);
    $quantityCalculation = $calculator->calculate([[
        'quantity' => '99999999999999.9999',
        'unit_price' => '0.0001',
        'discount_type' => null,
        'discount_value' => '99999999999999.9999',
        'tax_rate' => '99.9999',
    ]], null, '99999999999999.9999');
    $priceCalculation = $calculator->calculate([[
        'quantity' => '0',
        'unit_price' => '99999999999999.9999',
        'discount_type' => null,
        'discount_value' => '0',
        'tax_rate' => '0',
    ]], null, 0);

    expect($quantityCalculation['revision']['discount_value'])->toBe('99999999999999.9999')
        ->and($quantityCalculation['lines'][0]['quantity'])->toBe('99999999999999.99990000')
        ->and($quantityCalculation['lines'][0]['unit_price'])->toBe('0.0001')
        ->and($quantityCalculation['lines'][0]['discount_value'])->toBe('99999999999999.9999')
        ->and($quantityCalculation['lines'][0]['tax_rate'])->toBe('99.9999')
        ->and($priceCalculation['lines'][0]['quantity'])->toBe('0.00000000')
        ->and($priceCalculation['lines'][0]['unit_price'])->toBe('99999999999999.9999');
});

test('purchase invoice calculation preserves direct input decimals before persistence', function (): void {
    $calculator = app(PurchaseInvoiceCalculationService::class);
    $quantityCalculation = $calculator->calculate([[
        'quantity' => '99999999999999.9999',
        'unit_price' => '0.0001',
        'discount_type' => null,
        'discount_value' => '99999999999999.9999',
        'tax_rate' => '99.9999',
    ]], null, '99999999999999.9999');
    $priceCalculation = $calculator->calculate([[
        'quantity' => '1',
        'unit_price' => '99999999999999.9999',
        'discount_type' => null,
        'discount_value' => '0',
        'tax_rate' => '0',
    ]], null, 0);

    expect($quantityCalculation['invoice']['header_discount_value'])->toBe('99999999999999.9999')
        ->and($quantityCalculation['lines'][0]['quantity'])->toBe('99999999999999.99990000')
        ->and($quantityCalculation['lines'][0]['unit_price'])->toBe('0.0001')
        ->and($quantityCalculation['lines'][0]['discount_value'])->toBe('99999999999999.9999')
        ->and($quantityCalculation['lines'][0]['tax_rate'])->toBe('99.9999')
        ->and($priceCalculation['lines'][0]['quantity'])->toBe('1.00000000')
        ->and($priceCalculation['lines'][0]['unit_price'])->toBe('99999999999999.9999');
});

test('purchase invoice calculation keeps accepted maximum quantities exact without float loss', function (): void {
    $calculation = app(PurchaseInvoiceCalculationService::class)->calculate([[
        'quantity' => '99999999999999.99999999',
        'unit_price' => '0.0001',
        'discount_type' => 'fixed',
        'discount_value' => '0.0001',
        'tax_rate' => '0',
    ]], null, 0);

    expect($calculation['lines'][0]['quantity'])->toBe('99999999999999.99999999')
        ->and($calculation['lines'][0]['subtotal_amount'])->toBe('10000000000.0000')
        ->and($calculation['lines'][0]['discount_amount'])->toBe('0.0001')
        ->and($calculation['lines'][0]['total_before_tax'])->toBe('9999999999.9999')
        ->and($calculation['invoice']['total_amount'])->toBe('9999999999.9999');
});

test('purchase order calculation preserves direct and existing input decimals while derived values retain existing rounding', function (): void {
    $calculator = app(PurchaseOrderCalculationService::class);
    $quantityCalculation = $calculator->calculate([[
        'ordered_quantity' => '999999999999.99999999',
        'received_quantity' => '999999999999.99999998',
        'unit_price' => '0.0001',
    ]]);
    $priceCalculation = $calculator->calculate([[
        'ordered_quantity' => '0.00000001',
        'received_quantity' => '0',
        'unit_price' => '99999999999999.9999',
    ]]);

    expect($quantityCalculation['lines'][0]['ordered_quantity'])->toBe('999999999999.99999999')
        ->and($quantityCalculation['lines'][0]['received_quantity'])->toBe('999999999999.99999998')
        ->and($quantityCalculation['lines'][0]['remaining_quantity'])->toBe('0.00000000')
        ->and($quantityCalculation['lines'][0]['unit_price'])->toBe('0.0001')
        ->and($priceCalculation['lines'][0]['ordered_quantity'])->toBe('0.00000001')
        ->and($priceCalculation['lines'][0]['received_quantity'])->toBe('0.00000000')
        ->and($priceCalculation['lines'][0]['remaining_quantity'])->toBe('0.00000001')
        ->and($priceCalculation['lines'][0]['unit_price'])->toBe('99999999999999.9999');
});
