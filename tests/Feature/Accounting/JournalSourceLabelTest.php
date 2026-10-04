<?php

use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Exports\LedgerReportExport;
use Modules\Accounting\Services\JournalSourceLabelService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

test('ledger workbook and CSV preserve an eighteen-digit authorized decimal without spreadsheet coercion', function (): void {
    $amount = '99999999999999.1234';
    $export = new LedgerReportExport([
        'filters' => ['from_date' => '2026-01-01', 'to_date' => '2026-01-31'],
        'opening' => ['debit' => '0.0000', 'credit' => '0.0000'],
        'period' => ['debit' => $amount, 'credit' => '0.0000'],
        'ending' => ['debit' => $amount, 'credit' => '0.0000'],
        'movements' => [[
            'entry_date' => '2026-01-10', 'source_type' => null,
            'doc_num' => 'SYN-LEDGER-1', 'reference_no' => null, 'source_doc_num' => null,
            'description' => 'Synthetic precision movement', 'cost_center' => '', 'branch' => '',
            'debit' => $amount, 'credit' => '0.0000', 'running_debit' => $amount, 'running_credit' => '0.0000',
        ]],
    ]);

    expect(Excel::raw($export, ExcelFormat::CSV))->toContain($amount);

    $path = tempnam(sys_get_temp_dir(), 'ledger-precision-');
    file_put_contents($path, Excel::raw($export, ExcelFormat::XLSX));

    try {
        $sheet = IOFactory::load($path)->getActiveSheet();
        expect($sheet->getCell('H3')->getValue())->toBe($amount)
            ->and($sheet->getCell('H3')->getDataType())->toBe(DataType::TYPE_STRING)
            ->and($sheet->getCell('J4')->getValue())->toBe($amount);
    } finally {
        @unlink($path);
    }
});
