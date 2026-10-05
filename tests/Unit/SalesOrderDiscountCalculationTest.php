<?php

use Modules\Sales\Services\SalesOrderDiscountService;
use Tests\TestCase;

uses(TestCase::class);

test('commercial line and header discounts conserve four place allocations without changing tax', function (): void {
    $result = app(SalesOrderDiscountService::class)->calculate([
        ['quantity' => '100', 'unit_price' => '10', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_amount' => '126'],
        ['quantity' => '1', 'unit_price' => '100', 'discount_type' => 'fixed', 'discount_value' => '10', 'tax_amount' => '12.6'],
    ], 'percentage', '5');
    expect($result['discount_value'])->toBe('5.0000')->and($result['header_discount_amount'])->toBe('49.5000')
        ->and(array_column($result['lines'], 'discount_amount'))->toBe(['145.0000', '14.5000'])
        ->and(array_column($result['lines'], 'tax_amount'))->toBe(['126', '12.6'])
        ->and(array_column($result['lines'], 'line_total'))->toBe(['981.0000', '98.1000']);
});

test('header allocation absorbs rounding residue on the last positive line', function (): void {
    $line = ['quantity' => '1', 'unit_price' => '1', 'discount_amount' => '0'];
    $result = app(SalesOrderDiscountService::class)->calculate([$line, $line, $line], 'fixed', '0.0002');
    expect(array_column($result['lines'], 'header_discount_amount'))->toBe(['0.0000', '0.0000', '0.0002']);
    $zero = app(SalesOrderDiscountService::class)->calculate([['quantity' => '1', 'unit_price' => '1', 'discount_type' => 'percentage', 'discount_value' => '100']], 'percentage', '10');
    expect($zero['header_discount_amount'])->toBe('0.0000')->and($zero['lines'][0]['line_total'])->toBe('0.0000');
});

test('discounts preserve eight place prices and legacy monetary semantics', function (): void {
    $result = app(SalesOrderDiscountService::class)->calculate([['quantity' => '10000', 'unit_price' => '22.54545000', 'discount_amount' => '0.1234']], null, '0');
    expect($result['lines'][0]['line_total'])->toBe('225454.3766')->and($result['lines'][0]['discount_type'])->toBeNull();
    expect(app(SalesOrderDiscountService::class)->lineAmount(['quantity' => '3', 'unit_price' => '22.54545000', 'discount_type' => 'percentage', 'discount_value' => '10']))->toBe('6.7636');
});

test('invalid discount modes and values are rejected', function (?string $type, string $value): void {
    expect(fn () => app(SalesOrderDiscountService::class)->amount('100', $type, $value))->toThrow(DomainException::class);
})->with([[null, '1'], ['percentage', '100.0001'], ['fixed', '101'], ['percentage', '-1'], ['fixed', '1.00001'], ['other', '0'], ['fixed', '1e2']]);
