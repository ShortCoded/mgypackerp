<?php

use Modules\Accounting\Exports\LedgerReportExport;
use Modules\Accounting\Services\JournalSourceLabelService;

test('journal source labels are canonical in english and arabic without leaking identifiers', function (): void {
    $labels = app(JournalSourceLabelService::class);

    app()->setLocale('en');
    expect($labels->label('cash_receipt_voucher'))->toBe('Cash receipt voucher')
        ->and($labels->label('customer_invoice_post_2'))->toBe('Sales invoice')
        ->and($labels->label('inventory_document_reversal'))->toBe('Inventory transaction reversal')
        ->and($labels->label('unknown_runtime_source'))->toBe('Other posted source');

    app()->setLocale('ar');
    expect($labels->label('cash_receipt_voucher'))->toBe('سند قبض نقدي')
        ->and($labels->label('customer_invoice_post_2'))->toBe('فاتورة مبيعات')
        ->and($labels->label('inventory_document_reversal'))->toBe('عكس حركة مخزنية')
        ->and($labels->label('unknown_runtime_source'))->toBe('مصدر مرحّل آخر');
});

test('every canonical journal source family resolves through the shared bilingual catalog', function (): void {
    $cases = [
        '' => 'manual',
        'journal_entry_correction' => 'journal_entry',
        'Modules\\Inventory\\Models\\OpeningStock' => 'opening_balance',
        'cash_receipt_voucher' => 'cash_receipt_voucher',
        'cash_payment_voucher' => 'cash_payment_voucher',
        'bank_receipt_voucher' => 'bank_receipt_voucher',
        'bank_payment_voucher' => 'bank_payment_voucher',
        'customer_receipt' => 'customer_receipt',
        'supplier_payment' => 'supplier_payment',
        'purchase_invoice' => 'purchase_invoice',
        'Modules\\Purchases\\Models\\PurchaseReturn' => 'purchase_return',
        'customer_invoice' => 'sales_invoice',
        'Modules\\Sales\\Models\\SalesReturn' => 'sales_return',
        'sales_delivery' => 'delivery_cogs',
        'Modules\\Inventory\\Models\\InventoryDocument' => 'inventory',
        'production_order' => 'production',
        'maintenance_expense' => 'maintenance',
        'fixed_asset_capitalization' => 'fixed_assets',
        'hr_payroll_run' => 'payroll',
        'overhead_allocation' => 'overhead_allocation',
        'period_closing' => 'period_closing',
        'cheque_deposit' => 'cheque',
        'unknown_runtime_source' => 'other',
    ];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        foreach ($cases as $source => $key) {
            $label = app(JournalSourceLabelService::class)->label($source);
            expect($label)->toBe(__("ledger_reports.sources.{$key}"))
                ->not->toContain('ledger_reports.sources.');
        }
    }
});

test('ledger export consumes the canonical source mapper without leaking raw keys', function (): void {
    app()->setLocale('ar');
    $export = new LedgerReportExport([
        'filters' => ['from_date' => '2026-01-01', 'to_date' => '2026-12-31'],
        'opening' => ['debit' => '0', 'credit' => '0'],
        'period' => ['debit' => '1', 'credit' => '0'],
        'ending' => ['debit' => '1', 'credit' => '0'],
        'movements' => [[
            'entry_date' => '2026-09-20', 'source_type' => 'inventory_document_reversal',
            'doc_num' => 'JE-1', 'reference_no' => null, 'source_doc_num' => 'INV-1',
            'description' => 'اختبار', 'cost_center' => '', 'branch' => '',
            'debit' => '1', 'credit' => '0', 'running_debit' => '1', 'running_credit' => '0',
        ]],
    ]);

    $rows = $export->array();

    expect($rows[1][1])->toBe('عكس حركة مخزنية')
        ->and(json_encode($rows, JSON_UNESCAPED_UNICODE))->not->toContain('inventory_document_reversal', 'ledger_reports.sources.');
});
