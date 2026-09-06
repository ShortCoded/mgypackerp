<?php

use Illuminate\Support\Facades\Lang;
use Modules\Core\Models\Product;
use Modules\Core\Services\ProductComponentUnitConversionService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Sales\Services\SalesUnitConversionService;
use Tests\TestCase;

uses(TestCase::class);

test('domain errors use translated static messages with Arabic text and unchanged English', function (string $path): void {
    $source = file_get_contents(base_path($path));

    expect(preg_match('/new DomainException\s*\(\s*(?:[\'"]|sprintf\s*\()/', $source))->toBe(0);
    expect(preg_match('/->assert(?:Positive|NotGreaterThan)\([^\n]+,\s*\'[A-Z][^\']+\'\s*(?:,\s*\d+)?\)/', $source))->toBe(0);

    preg_match_all('/new DomainException\(__\(\s*\'([^\']+)\'/s', $source, $matches);
    preg_match_all('/->assert(?:Positive|NotGreaterThan)\([^\n]+?__\(\'([^\']+)\'\)/', $source, $helperMatches);
    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique([...$matches[1], ...$helperMatches[1]]) as $key) {
        $arabic = Lang::get($key, [], 'ar');

        expect($arabic)->toBeString()->not->toBe($key)
            ->and(preg_match('/\p{Arabic}/u', $arabic))->toBe(1);

        if (! preg_match('/^[a-z_]+(?:\.[a-z_]+)+$/', $key)) {
            expect(Lang::get($key, [], 'en'))->toBe($key);
        }
    }
})->with([
    'modules/Sales/Services/CustomerInvoiceService.php',
    'modules/Sales/Services/CustomerReceiptService.php',
    'modules/Sales/Services/SalesAccountingService.php',
    'modules/Sales/Services/SalesFulfillmentService.php',
    'modules/Sales/Services/SalesOrderService.php',
    'modules/Sales/Services/SalesReturnService.php',
    'modules/Sales/Services/SalesUnitConversionService.php',
    'modules/Production/Services/ProductionCycleService.php',
    'modules/Production/Services/SalesProductionDemandService.php',
    'modules/Inventory/Services/InventoryDocumentPostingService.php',
    'modules/Inventory/Services/InventoryMovementService.php',
    'modules/Inventory/Services/InventoryOpeningStockPostingService.php',
    'modules/Inventory/Services/InventoryPositionReconciliationService.php',
    'modules/Inventory/Services/InventoryReservationService.php',
    'modules/Inventory/Services/StockCountService.php',
    'modules/HR/Services/PayrollCostAllocationService.php',
    'modules/FixedAssets/Services/FixedAssetDuplicateAccountAuditService.php',
    'modules/FixedAssets/Services/FixedAssetDuplicateAccountRepairService.php',
    'modules/FixedAssets/Services/FixedAssetRootAccountAuditService.php',
    'modules/Accounting/Services/AccountClassificationRegistry.php',
    'modules/Accounting/Services/BusinessPartnerAccountService.php',
    'modules/Accounting/Services/CostCenterHierarchyRegistry.php',
    'modules/Accounting/Services/JournalEntryService.php',
]);

test('sales unit conversion rejects unavailable units in the current language', function (string $locale, string $message): void {
    app()->setLocale($locale);
    $product = (new Product(['item_unit_id' => 10]))
        ->setRelation('unit', null)
        ->setRelation('equivalentUnit', null);
    $unitOptions = Mockery::mock(ProductComponentUnitOptionsService::class);
    $unitOptions->shouldReceive('validUnitIds')->with($product)->once()->andReturn([]);
    $service = new SalesUnitConversionService(app(ProductComponentUnitConversionService::class), $unitOptions);

    expect(fn () => $service->snapshot($product, 99, '1'))->toThrow(DomainException::class, $message);
})->with([
    'arabic' => ['ar', 'الوحدة المحددة غير معدّة لهذا الصنف.'],
    'english' => ['en', 'The selected unit is not configured for this product.'],
]);

test('domain error placeholders preserve document data in both languages', function (string $locale): void {
    $product = 'اختبار Item-17';
    $translated = Lang::get('Product :product does not have a bill of materials.', ['product' => $product], $locale);

    expect($translated)->toContain($product)->not->toContain(':product');

    if ($locale === 'ar') {
        expect($translated)->toBe('لا توجد قائمة مكونات للصنف اختبار Item-17.');
    } else {
        expect($translated)->toBe('Product اختبار Item-17 does not have a bill of materials.');
    }
})->with(['ar', 'en']);
