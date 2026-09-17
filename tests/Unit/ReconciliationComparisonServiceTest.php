<?php

use Modules\Accounting\Services\ReconciliationCenterService;
use Modules\Accounting\Services\ReconciliationComparisonService;

test('reconciliation center exposes the required twelve control families', function (): void {
    expect(ReconciliationCenterService::types())->toBe([
        'customers_ar',
        'suppliers_ap',
        'cash_safes_gl',
        'banks_gl',
        'bank_statement_books',
        'inventory_gl',
        'fixed_assets_gl',
        'payroll_payable_gl',
        'payroll_settlement_cash_bank',
        'cost_centers_allocation',
        'wip_fg_cogs',
        'financial_statement_cross_checks',
    ]);
});

test('reconciliation preserves offsetting mismatches and the opening movement ending invariant', function (): void {
    $result = app(ReconciliationComparisonService::class)->compare('customers', 'Customers', [
        [
            'key' => 'customer-a',
            'label' => 'Customer A',
            'source_opening' => '100',
            'gl_opening' => '0',
            'source_movement' => '0',
            'gl_movement' => '0',
            'source_ending' => '100',
            'gl_ending' => '0',
        ],
        [
            'key' => 'customer-b',
            'label' => 'Customer B',
            'source_opening' => '0',
            'gl_opening' => '100',
            'source_movement' => '0',
            'gl_movement' => '0',
            'source_ending' => '0',
            'gl_ending' => '100',
        ],
    ]);

    expect($result['status'])->toBe(ReconciliationComparisonService::Difference)
        ->and($result['summary']['mismatch_count'])->toBe(2)
        ->and($result['summary']['absolute_difference_total'])->toBe('200.0000')
        ->and($result['summary']['positive_difference_total'])->toBe('100.0000')
        ->and($result['summary']['negative_difference_total'])->toBe('-100.0000')
        ->and($result['rows'][0]['invariant_difference'])->toBe('0.0000')
        ->and($result['rows'][1]['invariant_difference'])->toBe('0.0000');
});

test('reconciliation never reports empty evidence as matched', function (): void {
    $result = app(ReconciliationComparisonService::class)->compare('inventory', 'Inventory', []);

    expect($result['status'])->toBe(ReconciliationComparisonService::InsufficientData)
        ->and($result['summary']['row_count'])->toBe(0);
});

test('reconciliation status distinguishes missing mapping and not applicable', function (): void {
    $row = [[
        'key' => 'one',
        'source_opening' => '10',
        'gl_opening' => '0',
        'source_movement' => '0',
        'gl_movement' => '0',
        'source_ending' => '10',
        'gl_ending' => '0',
    ]];

    $missing = app(ReconciliationComparisonService::class)->compare('cash', 'Cash', $row, mappingConfigured: false);
    $notApplicable = app(ReconciliationComparisonService::class)->compare('payroll', 'Payroll', [], applicable: false);

    expect($missing['status'])->toBe(ReconciliationComparisonService::MissingMapping)
        ->and($notApplicable['status'])->toBe(ReconciliationComparisonService::NotApplicable);
});

test('a broken source invariant is a difference even when ending balances happen to match', function (): void {
    $result = app(ReconciliationComparisonService::class)->compare('test', 'Test', [[
        'key' => 'one',
        'source_opening' => '10',
        'gl_opening' => '10',
        'source_movement' => '5',
        'gl_movement' => '0',
        'source_ending' => '10',
        'gl_ending' => '10',
    ]]);

    expect($result['status'])->toBe(ReconciliationComparisonService::Difference)
        ->and($result['rows'][0]['movement_difference'])->toBe('5.0000')
        ->and($result['rows'][0]['ending_difference'])->toBe('0.0000')
        ->and($result['rows'][0]['invariant_difference'])->toBe('-5.0000');
});

test('a true opening movement and ending match is reported as matched', function (): void {
    $result = app(ReconciliationComparisonService::class)->compare('bank', 'Bank', [[
        'key' => 'bank-one',
        'source_opening' => '50',
        'gl_opening' => '50',
        'source_movement' => '25',
        'gl_movement' => '25',
        'source_ending' => '75',
        'gl_ending' => '75',
    ]]);

    expect($result['status'])->toBe(ReconciliationComparisonService::Matched)
        ->and($result['summary']['mismatch_count'])->toBe(0)
        ->and($result['summary']['absolute_difference_total'])->toBe('0.0000');
});

test('a bank timing item remains visible as a movement and ending difference', function (): void {
    $result = app(ReconciliationComparisonService::class)->compare('bank', 'Bank', [[
        'key' => 'outstanding-cheque',
        'label' => 'Outstanding cheque',
        'source_opening' => '1000',
        'gl_opening' => '1000',
        'source_movement' => '-250',
        'gl_movement' => '0',
        'source_ending' => '750',
        'gl_ending' => '1000',
    ]]);

    expect($result['status'])->toBe(ReconciliationComparisonService::Difference)
        ->and($result['rows'][0]['movement_difference'])->toBe('-250.0000')
        ->and($result['rows'][0]['ending_difference'])->toBe('-250.0000')
        ->and($result['summary']['negative_difference_total'])->toBe('-250.0000');
});
