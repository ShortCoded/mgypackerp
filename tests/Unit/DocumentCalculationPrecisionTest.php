<?php

use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Services\DateFormatService;
use Modules\Inventory\Exports\InventoryBookValuationExport;
use Modules\Inventory\Exports\InventorySalesValuationExport;
use Modules\Inventory\Exports\InventoryValuationComparisonExport;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseOrderCalculationService;
use Modules\Sales\Exports\PriceListExport;
use Modules\Sales\Exports\SalesCycleReportExport;
use Modules\Sales\Exports\SalesCycleReportSheet;
use Modules\Sales\Services\QuotationCalculationService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

uses(TestCase::class);

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

test('purchase invoice payment schedule distinguishes a near-limit one-unit remainder', function (): void {
    $calculator = app(PurchaseInvoiceCalculationService::class);
    $invoice = $calculator->calculate([[
        'quantity' => '1000',
        'unit_price' => '999999999999.99990000',
        'tax_rate' => '0',
    ]], null, 0);
    $schedules = array_fill(0, 10, ['amount' => '99999999999999.9900']);
    $matching = $calculator->schedulesMatchTotal($schedules, $invoice['invoice']['total_amount']);
    $schedules[9]['amount'] = '99999999999999.9899';
    $scheduled = $calculator->scheduledAmount($schedules);

    expect($invoice['invoice']['total_amount'])->toBe('999999999999999.9000')
        ->and($matching)->toBeTrue()
        ->and($scheduled)->toBe('999999999999999.8999')
        ->and($calculator->schedulesMatchTotal($schedules, $invoice['invoice']['total_amount']))->toBeFalse()
        ->and(bccomp($invoice['invoice']['total_amount'], $scheduled, 4))->toBe(1);
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
        ->and($quantityCalculation['lines'][0]['remaining_quantity'])->toBe('0.00000001')
        ->and($quantityCalculation['lines'][0]['unit_price'])->toBe('0.0001')
        ->and($priceCalculation['lines'][0]['ordered_quantity'])->toBe('0.00000001')
        ->and($priceCalculation['lines'][0]['received_quantity'])->toBe('0.00000000')
        ->and($priceCalculation['lines'][0]['remaining_quantity'])->toBe('0.00000001')
        ->and($priceCalculation['lines'][0]['unit_price'])->toBe('99999999999999.9999');
});

test('all document calculators retain eight digit unit prices and round only calculated money', function (): void {
    $quotation = app(QuotationCalculationService::class)->calculate([[
        'quantity' => '10000', 'unit_price' => '22.54545', 'discount_type' => null,
        'discount_value' => '0', 'tax_rate' => '0',
    ]], null, 0);
    $order = app(PurchaseOrderCalculationService::class)->calculate([[
        'ordered_quantity' => '10000', 'received_quantity' => '0', 'unit_price' => '22.54545123',
    ]]);
    $invoice = app(PurchaseInvoiceCalculationService::class)->calculate([[
        'quantity' => '1000000', 'unit_price' => '0.00000001', 'discount_type' => null,
        'discount_value' => '0', 'tax_rate' => '0',
    ]], null, 0);

    expect($quotation['lines'][0]['unit_price'])->toBe('22.54545')
        ->and($quotation['revision']['total'])->toBe('225454.5000')
        ->and($order['lines'][0]['unit_price'])->toBe('22.54545123')
        ->and($order['lines'][0]['subtotal_amount'])->toBe('225454.5123')
        ->and($invoice['lines'][0]['unit_price'])->toBe('0.00000001')
        ->and($invoice['invoice']['total_amount'])->toBe('0.0100');
});

test('document calculators reject unit prices beyond eight significant decimal places', function (): void {
    expect(fn () => app(PurchaseOrderCalculationService::class)->calculate([[
        'ordered_quantity' => '1', 'unit_price' => '22.545451234',
    ]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(QuotationCalculationService::class)->calculate([[
        'quantity' => '1', 'unit_price' => '0.000000001',
    ]], null, 0))->toThrow(InvalidArgumentException::class);
});

test('sales fulfillment export preserves the final eight-place quantity without float cancellation', function (): void {
    $dates = Mockery::mock(DateFormatService::class);
    $dates->shouldReceive('formatDate')->once()->with(null, '')->andReturn('');
    app()->instance(DateFormatService::class, $dates);
    $order = (object) [
        'doc_num' => 'SO-SYNTHETIC-PRECISION',
        'customer' => (object) ['name' => 'Synthetic Customer'],
        'expected_delivery_date' => null,
        'status' => 'approved',
        'ordered_quantity' => '1000000000000.00000001',
        'delivered_quantity' => '1000000000000.00000000',
        'lines' => collect([
            (object) ['invoiced_quantity' => '0.00000001'],
            (object) ['invoiced_quantity' => '0.00000002'],
        ]),
    ];
    $sheet = (new SalesCycleReportExport([
        'reportType' => 'fulfillment',
        'openOrders' => collect([$order]),
    ]))->sheets()[0];
    $row = $sheet->array()[0];

    expect($row[2])->toBe('')
        ->and((string) $row[4])->toBe('1000000000000.00000001')
        ->and((string) $row[5])->toBe('0.00000003')
        ->and((string) $row[6])->toBe('1000000000000.00000000')
        ->and((string) $row[7])->toBe('0.00000001');

    $cell = (new Spreadsheet)->getActiveSheet()->getCell('H2');
    $sheet->bindValue($cell, $row[7]);
    expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($cell->getValue())->toBe('0.00000001');
});

test('purchase return variance keeps a four-place posting remainder beyond float precision', function (): void {
    $variance = app(JournalEntryService::class)->purchaseReturnPriceVariance(
        '1000000000000.00005000',
        '1.00000000',
        '1000000000000.0000',
    );

    expect($variance)->toBe('0.0001')
        ->and(number_format((float) '1000000000000.00005000', 4, '.', ''))->toBe('1000000000000.0000');
});

test('price list Excel export binds an eight-place unit price as exact text', function (): void {
    $row = array_fill(0, 17, '');
    $row[14] = '22.54545123';
    $export = new PriceListExport(['export_headings' => array_fill(0, 17, ''), 'export_rows' => [$row]]);
    $spreadsheet = new Spreadsheet;
    $cell = $spreadsheet->getActiveSheet()->getCell('O2');
    $export->bindValue($cell, $export->collection()->sole()[14]);

    expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($cell->getValue())->toBe('22.54545123');

    $path = tempnam(sys_get_temp_dir(), 'mgypack-price-roundtrip-');
    try {
        (new Xlsx($spreadsheet))->save($path);
        $reloaded = IOFactory::createReader('Xlsx')->load($path)->getActiveSheet()->getCell('O2');
        expect($reloaded->getDataType())->toBe(DataType::TYPE_STRING)
            ->and($reloaded->getValue())->toBe('22.54545123');
    } finally {
        unlink($path);
    }
});

test('stock and sales report Excel exports bind unit prices and costs without spreadsheet coercion', function (): void {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $book = new InventoryBookValuationExport(collect(), [], 'EGP');
    $salesValuation = new InventorySalesValuationExport([]);
    $comparison = new InventoryValuationComparisonExport(['methods' => ['moving_average' => []]]);
    new SalesCycleReportExport([]);
    $invoiceLines = new SalesCycleReportSheet('Invoice lines', [], [], ['F']);
    $costOfSales = new SalesCycleReportSheet('Cost of sales', [], [], ['I']);

    foreach ([
        [$book, 'H2'],
        [$salesValuation, 'H3'],
        [$comparison, 'D2'],
        [$invoiceLines, 'F2'],
        [$costOfSales, 'I2'],
    ] as [$export, $coordinate]) {
        $cell = $sheet->getCell($coordinate);
        $export->bindValue($cell, '99999999999999.54545123');
        expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
            ->and($cell->getValue())->toBe('99999999999999.54545123');
    }
});
