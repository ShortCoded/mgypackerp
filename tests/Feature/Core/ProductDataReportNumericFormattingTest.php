<?php

use App\Models\User;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ProductDataReport;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function productNumericReportActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/**
 * @return array{company: Company, session: array<string, int|string>}
 */
function productNumericReportContext(object $test): array
{
    static $documentNumber = 9700;

    $documentNumber++;
    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.$documentNumber,
        'name' => 'Product Numeric Report Company '.$documentNumber,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.$documentNumber,
        'company_id' => $company->getKey(),
        'name' => 'Product Numeric Report Branch '.$documentNumber,
        'type' => 'factory',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.$documentNumber,
        'company_id' => $company->getKey(),
        'name' => 'Product Numeric Report Period '.$documentNumber,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    $test->withSession($session);

    return compact('company', 'session');
}

/**
 * @return list<array{data: string, name: string, searchable: string, orderable: string, search: array{value: string, regex: string}}>
 */
function productNumericReportDataTableColumns(): array
{
    return array_map(
        fn (string $name): array => [
            'data' => $name,
            'name' => $name,
            'searchable' => 'true',
            'orderable' => 'true',
            'search' => ['value' => '', 'regex' => 'false'],
        ],
        [
            'doc_num',
            'name',
            'item_classification',
            'barcode',
            'unit',
            'category',
            'group',
            'model',
            'size',
            'color',
            'decal',
            'origin_country',
            'reorder_point',
            'equivalent',
            'status',
            'components_count',
            'component_doc_num',
            'component_name',
            'component_classification',
            'component_quantity',
            'component_unit',
            'component_equivalent',
            'component_notes',
            'created_at',
        ],
    );
}

beforeEach(function (): void {
    $this->productNumericReportContext = productNumericReportContext($this);
});

test('product report display mapping groups values while export mapping stays canonical', function (): void {
    $product = new Product;
    $product->forceFill([
        'doc_num' => '00001234',
        'name' => 'Numeric Display Product',
        'barcode' => '001234567890',
        'item_classification' => Product::ClassificationFinishedProduct,
        'reorder_point' => '1250.5000',
        'equivalent_value' => '0.050000',
        'equivalent_unit_doc_num' => 'Unit-00002',
        'equivalent_unit_name' => 'Gram',
        'status' => 'active',
        'components_count' => 1000,
        'component_doc_num' => '00005678',
        'component_name' => 'Numeric Component',
        'component_classification' => Product::ClassificationRawMaterial,
        'component_quantity' => '1234567.89000000',
        'component_unit_doc_num' => 'Unit-00002',
        'component_unit_name' => 'Gram',
        'component_equivalent_value' => '0.00045800',
        'component_equivalent_unit_doc_num' => 'Unit-00003',
        'component_equivalent_unit_name' => 'Kilogram',
        'created_at' => now(),
    ]);

    $report = app(ProductDataReport::class);
    $display = $report->row($product);
    $summary = $report->map($product);
    $summaryExport = $report->exportMap($product);
    $detailed = $report->map($product, ['result_mode' => ProductDataReport::ModeDetailed]);
    $detailedExport = $report->exportMap($product, ['result_mode' => ProductDataReport::ModeDetailed]);

    expect($display['reorder_point'])->toBe('1,250.5')
        ->and($display['equivalent'])->toBe('0.05 Unit-00002 / Gram')
        ->and($display['components_count'])->toBe('1,000')
        ->and($display['component_quantity'])->toBe('1,234,567.89')
        ->and($display['component_equivalent'])->toBe('0.000458 Unit-00003 / Kilogram')
        ->and($summary[12])->toBe('1,250.5')
        ->and($summary[15])->toBe('1,000')
        ->and($summaryExport[0])->toBe('00001234')
        ->and($summaryExport[3])->toBe('001234567890')
        ->and($summaryExport[12])->toBe('1250.5')
        ->and($summaryExport[13])->toBe('0.05 Unit-00002 / Gram')
        ->and($summaryExport[15])->toBe(1000)
        ->and($detailed[6])->toBe('1,234,567.89')
        ->and($detailedExport[6])->toBe('1234567.89')
        ->and($detailedExport[8])->toBe('0.000458 Unit-00003 / Kilogram');
});

test('product report DataTable displays grouped values and orders the raw numeric column', function (): void {
    $actor = productNumericReportActor(['reports.products_data.view']);

    foreach (['1000.0000', '10.0000', '100.0000', '2.0000'] as $index => $reorderPoint) {
        Product::query()->create([
            'company_id' => $this->productNumericReportContext['company']->getKey(),
            'doc_number' => 9800 + $index,
            'doc_num' => 'Numeric-Sort-'.($index + 1),
            'name' => 'Numeric Sort Product '.($index + 1),
            'item_classification' => Product::ClassificationFinishedProduct,
            'reorder_point' => $reorderPoint,
            'status' => 'active',
        ]);
    }

    $rows = $this->actingAs($actor)
        ->withSession($this->productNumericReportContext['session'])
        ->getJson(route('admin.reports.products-data.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Numeric Sort Product', 'regex' => false],
            'columns' => productNumericReportDataTableColumns(),
            'order' => [['column' => 12, 'dir' => 'asc']],
        ]))
        ->assertOk()
        ->json('data');

    expect(array_column($rows, 'reorder_point'))->toBe(['2', '10', '100', '1,000']);
});

test('product XLSX keeps numeric cells and text identifiers while CSV stays canonical', function (): void {
    $actor = productNumericReportActor([
        'reports.products_data.view',
        'reports.products_data.export',
        'reports.products_data.pdf',
    ]);
    $company = $this->productNumericReportContext['company'];
    $baseUnit = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 1,
        'doc_num' => 'Unit-00001',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $equivalentUnit = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 2,
        'doc_num' => 'Unit-00002',
        'name' => 'Gram',
        'status' => 'active',
    ]);
    $componentEquivalentUnit = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 3,
        'doc_num' => 'Unit-00003',
        'name' => 'Kilogram',
        'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 10,
        'doc_num' => '00001234',
        'name' => 'Numeric Export Product',
        'barcode' => '001234567890',
        'item_classification' => Product::ClassificationFinishedProduct,
        'reorder_point' => '1250.5000',
        'item_unit_id' => $baseUnit->getKey(),
        'equivalent_value' => '0.050000',
        'equivalent_unit_id' => $equivalentUnit->getKey(),
        'status' => 'active',
    ]);
    $component = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 11,
        'doc_num' => '00005678',
        'name' => 'Numeric Export Component',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $equivalentUnit->getKey(),
        'equivalent_value' => '0.000458',
        'equivalent_unit_id' => $componentEquivalentUnit->getKey(),
        'status' => 'active',
    ]);

    ProductComponent::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'component_product_id' => $component->getKey(),
        'unit_id' => $equivalentUnit->getKey(),
        'quantity' => '1234567.89000000',
    ]);

    $summaryFilters = ['product_doc_num' => $product->doc_num];
    $summaryExcel = $this->actingAs($actor)
        ->withSession($this->productNumericReportContext['session'])
        ->get(route('admin.reports.products-data.export.excel', $summaryFilters))
        ->assertOk()
        ->assertDownload('products-data-report.xlsx');
    $summarySheet = IOFactory::load($summaryExcel->baseResponse->getFile()->getPathname())->getActiveSheet();

    expect($summarySheet->getCell('A2')->getValue())->toBe('00001234')
        ->and($summarySheet->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($summarySheet->getCell('D2')->getValue())->toBe('001234567890')
        ->and($summarySheet->getCell('D2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($summarySheet->getCell('M2')->getValue())->toBe(1250.5)
        ->and($summarySheet->getCell('M2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($summarySheet->getStyle('M2')->getNumberFormat()->getFormatCode())->toBe('#,##0.####')
        ->and($summarySheet->getCell('N2')->getValue())->toBe('0.05 Unit-00002 / Gram')
        ->and($summarySheet->getCell('N2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($summarySheet->getStyle('P2')->getNumberFormat()->getFormatCode())->toBe('#,##0');

    $summaryCsv = $this->actingAs($actor)
        ->withSession($this->productNumericReportContext['session'])
        ->get(route('admin.reports.products-data.export.csv', $summaryFilters))
        ->assertOk()
        ->assertDownload('products-data-report.csv');
    $summaryCsvContents = (string) file_get_contents($summaryCsv->baseResponse->getFile()->getPathname());
    $summaryCsvLines = array_values(array_filter(preg_split('/\R/u', trim($summaryCsvContents)) ?: []));
    $summaryCsvDataLine = collect($summaryCsvLines)->first(fn (string $line): bool => str_contains($line, '00001234'));

    expect($summaryCsvDataLine)->not->toBeNull();

    $summaryCsvRow = str_getcsv($summaryCsvDataLine);

    expect($summaryCsvRow[0])->toBe('00001234')
        ->and($summaryCsvRow[3])->toBe('001234567890')
        ->and($summaryCsvRow[12])->toBe('1250.5')
        ->and($summaryCsvRow[13])->toBe('0.05 Unit-00002 / Gram')
        ->and($summaryCsvContents)->not->toContain('1,250.5');

    $detailedFilters = [
        'product_doc_num' => $product->doc_num,
        'result_mode' => ProductDataReport::ModeDetailed,
    ];
    $detailedExcel = $this->actingAs($actor)
        ->withSession($this->productNumericReportContext['session'])
        ->get(route('admin.reports.products-data.export.excel', $detailedFilters))
        ->assertOk();
    $detailedSheet = IOFactory::load($detailedExcel->baseResponse->getFile()->getPathname())->getActiveSheet();

    expect($detailedSheet->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($detailedSheet->getCell('D2')->getValue())->toBe('00005678')
        ->and($detailedSheet->getCell('D2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($detailedSheet->getCell('G2')->getValue())->toBe(1234567.89)
        ->and($detailedSheet->getCell('G2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($detailedSheet->getStyle('G2')->getNumberFormat()->getFormatCode())->toBe('#,##0.########')
        ->and($detailedSheet->getCell('I2')->getValue())->toBe('0.000458 Unit-00003 / Kilogram')
        ->and($detailedSheet->getCell('I2')->getDataType())->toBe(DataType::TYPE_STRING);

    $detailedCsv = $this->actingAs($actor)
        ->withSession($this->productNumericReportContext['session'])
        ->get(route('admin.reports.products-data.export.csv', $detailedFilters))
        ->assertOk();
    $detailedCsvContents = (string) file_get_contents($detailedCsv->baseResponse->getFile()->getPathname());
    $detailedCsvLines = array_values(array_filter(preg_split('/\R/u', trim($detailedCsvContents)) ?: []));
    $detailedCsvDataLine = collect($detailedCsvLines)->first(fn (string $line): bool => str_contains($line, '00001234'));

    expect($detailedCsvDataLine)->not->toBeNull();

    $detailedCsvRow = str_getcsv($detailedCsvDataLine);

    expect($detailedCsvRow[6])->toBe('1234567.89')
        ->and($detailedCsvRow[8])->toBe('0.000458 Unit-00003 / Kilogram')
        ->and($detailedCsvContents)->not->toContain('1,234,567.89');

    $pdf = $this->actingAs($actor)
        ->withSession($this->productNumericReportContext['session'])
        ->get(route('admin.reports.products-data.export.pdf', $detailedFilters))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($pdf->getContent())->toStartWith('%PDF-');
});
