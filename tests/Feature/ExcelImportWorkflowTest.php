<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ExcelImportBatch;
use Modules\Core\Models\ExcelImportRow;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function excelImportActor(array $permissions): User
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
 * @return array<string, int|string>
 */
function excelImportContext(): array
{
    $company = Company::factory()->create(['status' => 'active']);
    $branch = Branch::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 9821,
        'doc_num' => 'Branch-09821',
        'name' => 'Excel Import Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 9821,
        'doc_num' => 'Period-09821',
        'name' => 'Excel Import Period',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    return [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
}

/**
 * @return array{session: array<string, int|string>, company: Company, branch: Branch, period: FinancialPeriod, currency_doc_num: string, asset_category_doc_num: string, credit_account_doc_num: string}
 */
function excelImportFixedAssetContext(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(AccountClassificationsSeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }
    session($session);

    $accounts = app(BusinessPartnerAccountService::class);
    $root = $accounts->rootAccount(BusinessPartnerAccountService::FixedAsset);
    $assetCategory = Account::query()
        ->where('company_id', $company->getKey())
        ->where('parent_id', $root->getKey())
        ->where('status', 'active')
        ->where('is_group', true)
        ->where('is_postable', false)
        ->whereNull('deleted_at')
        ->orderBy('account_code')
        ->first()
        ?? $accounts->createGroup(BusinessPartnerAccountService::FixedAsset, 'Excel Import Assets');
    $creditAccount = Account::query()
        ->where('company_id', $company->getKey())
        ->where('status', 'active')
        ->where('is_group', false)
        ->where('is_postable', true)
        ->whereNull('deleted_at')
        ->orderBy('account_code')
        ->firstOrFail();
    $currencyDocNum = (string) Currency::query()
        ->where('company_id', $company->getKey())
        ->where('status', 'active')
        ->orderByDesc('is_main')
        ->value('doc_num');

    return [
        'session' => $session,
        'company' => $company,
        'branch' => $branch,
        'period' => $period,
        'currency_doc_num' => $currencyDocNum,
        'asset_category_doc_num' => (string) $assetCategory->doc_num,
        'credit_account_doc_num' => (string) $creditAccount->doc_num,
    ];
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('products.image_required', false);
});

test('only authorized users see and access the product import workflow', function (): void {
    $context = excelImportContext();
    $authorized = excelImportActor(['products.view', 'products.create', 'products.import']);
    $unauthorized = excelImportActor(['products.view', 'products.create']);
    $authorizedFixedAssets = excelImportActor(['fixed_assets.view', 'fixed_assets.create', 'fixed_assets.import']);
    $unauthorizedFixedAssets = excelImportActor(['fixed_assets.view', 'fixed_assets.create']);

    $this->actingAs($authorized)
        ->withSession($context)
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertSee(route('admin.products.import.index'), false);

    $this->actingAs($unauthorized)
        ->withSession($context)
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertDontSee(route('admin.products.import.index'), false);

    $this->actingAs($unauthorized)
        ->withSession($context)
        ->get(route('admin.products.import.index'))
        ->assertForbidden();

    $this->actingAs($authorizedFixedAssets)
        ->withSession($context)
        ->get(route('admin.fixed-assets.assets.index'))
        ->assertOk()
        ->assertSee(route('admin.fixed-assets.assets.import.index'), false);

    $this->actingAs($unauthorizedFixedAssets)
        ->withSession($context)
        ->get(route('admin.fixed-assets.assets.index'))
        ->assertOk()
        ->assertDontSee(route('admin.fixed-assets.assets.import.index'), false);

    $this->actingAs($unauthorizedFixedAssets)
        ->withSession($context)
        ->get(route('admin.fixed-assets.assets.import.index'))
        ->assertForbidden();
});

test('module templates have different official sheet structures without internal ids', function (): void {
    $context = excelImportContext();
    $actor = excelImportActor(['products.create', 'products.import', 'fixed_assets.create', 'fixed_assets.import']);

    $productResponse = $this->actingAs($actor)
        ->withSession($context)
        ->get(route('admin.products.import.template'))
        ->assertOk();
    $assetResponse = $this->actingAs($actor)
        ->withSession($context)
        ->get(route('admin.fixed-assets.assets.import.template'))
        ->assertOk();

    $productWorkbook = IOFactory::load($productResponse->baseResponse->getFile()->getPathname());
    $assetWorkbook = IOFactory::load($assetResponse->baseResponse->getFile()->getPathname());

    expect($productWorkbook->getSheetNames())->toBe(['Instructions', 'Products', 'Product Components', 'Lookups', 'Meta'])
        ->and($assetWorkbook->getSheetNames())->toBe(['Instructions', 'FixedAssets', 'Lookups', 'Meta'])
        ->and((string) $productWorkbook->getSheetByName('Products')->getCell('A1')->getValue())->toBe('import_key')
        ->and((string) $assetWorkbook->getSheetByName('Meta')->getCell('B1')->getValue())->toBe('fixed_assets')
        ->and($productWorkbook->getSheetByName('Lookups')->toArray())->not->toContain('id');
});

test('a valid product workbook is staged before atomically creating a new product', function (): void {
    $context = excelImportContext();
    $actor = excelImportActor(['products.create', 'products.import']);
    $templateResponse = $this->actingAs($actor)
        ->withSession($context)
        ->get(route('admin.products.import.template'))
        ->assertOk();
    $workbook = IOFactory::load($templateResponse->baseResponse->getFile()->getPathname());
    $sheet = $workbook->getSheetByName('Products');
    $sheet->setCellValue('A3', 'chair-001');
    $sheet->setCellValue('B3', 'Imported Chair');
    $sheet->setCellValue('C3', Product::ClassificationFinishedProduct);
    $sheet->setCellValue('F3', 'active');
    $path = tempnam(sys_get_temp_dir(), 'product-import-');
    (new Xlsx($workbook))->save($path);
    $workbook->disconnectWorksheets();

    $upload = UploadedFile::fake()->createWithContent('products.xlsx', file_get_contents($path));
    $response = $this->actingAs($actor)
        ->withSession($context)
        ->post(route('admin.products.import.store'), ['workbook' => $upload]);

    $batch = ExcelImportBatch::query()->firstOrFail();
    $response->assertRedirect(route('admin.products.import.show', $batch->public_uuid));
    expect($batch->status)->toBe(ExcelImportBatch::StatusReady)
        ->and(Product::query()->count())->toBe(0);

    $this->actingAs($actor)
        ->withSession($context)
        ->get(route('admin.products.import.show', $batch->public_uuid))
        ->assertOk()
        ->assertSee(__('excel_imports.steps.review'))
        ->assertSee('Imported Chair');

    $this->actingAs($actor)
        ->withSession($context)
        ->post(route('admin.products.import.confirm', $batch->public_uuid), ['confirmed' => '1'])
        ->assertRedirect(route('admin.products.import.show', $batch->public_uuid));

    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->firstOrFail()->name)->toBe('Imported Chair')
        ->and($batch->refresh()->status)->toBe(ExcelImportBatch::StatusImported);
});

test('product import preserves all bom methods and percentage reference semantics', function (): void {
    $context = excelImportContext();
    $company = Company::query()->findOrFail($context[OperatingContextService::CompanyIdKey]);
    $actor = excelImportActor(['products.create', 'products.import']);
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 9822,
        'doc_num' => 'Unit-09822',
        'name' => 'Import Piece',
        'status' => 'active',
    ]);
    $quantityMaterial = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 9822,
        'doc_num' => 'RawMaterial-09822',
        'name' => 'Imported Quantity Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $countMaterial = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 9823,
        'doc_num' => 'Packaging-09823',
        'name' => 'Imported Count Material',
        'item_classification' => Product::ClassificationPackaging,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $directMaterial = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 9800,
        'doc_num' => 'Product-09800',
        'name' => 'Imported Finished Component',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $percentageMaterial = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 9825,
        'doc_num' => 'RawMaterial-09825',
        'name' => 'Imported Percentage Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $templateResponse = $this->actingAs($actor)
        ->withSession($context)
        ->get(route('admin.products.import.template'))
        ->assertOk();
    $workbook = IOFactory::load($templateResponse->baseResponse->getFile()->getPathname());
    $productSheet = $workbook->getSheetByName('Products');
    $componentSheet = $workbook->getSheetByName('Product Components');
    $lookupValues = collect($workbook->getSheetByName('Lookups')->toArray())->flatten();

    expect($lookupValues)
        ->toContain(ProductComponent::CalculationQuantity)
        ->toContain(ProductComponent::CalculationCount)
        ->toContain($directMaterial->doc_num);

    $productSheet->setCellValue('A3', 'bom-methods-001');
    $productSheet->setCellValue('B3', 'Imported BOM Methods Product');
    $productSheet->setCellValue('C3', Product::ClassificationFinishedProduct);
    $productSheet->setCellValue('F3', 'active');

    $componentSheet->setCellValue('A3', 'bom-methods-001');
    $componentSheet->setCellValue('B3', 'direct-line');
    $componentSheet->setCellValue('C3', $directMaterial->doc_num);
    $componentSheet->setCellValue('D3', $unit->doc_num);
    $componentSheet->setCellValue('E3', ProductComponent::CalculationDirect);
    $componentSheet->setCellValue('F3', '130');

    $componentSheet->setCellValue('A4', 'bom-methods-001');
    $componentSheet->setCellValue('B4', 'percentage-line');
    $componentSheet->setCellValue('C4', $percentageMaterial->doc_num);
    $componentSheet->setCellValue('D4', $unit->doc_num);
    $componentSheet->setCellValue('E4', ProductComponent::CalculationPercentage);
    $componentSheet->setCellValue('G4', '2');
    $componentSheet->setCellValue('H4', ProductComponent::InputPercentage);
    $componentSheet->setCellValue('I4', 'direct-line');

    $componentSheet->setCellValue('A5', 'bom-methods-001');
    $componentSheet->setCellValue('B5', 'quantity-line');
    $componentSheet->setCellValue('C5', $quantityMaterial->doc_num);
    $componentSheet->setCellValue('D5', $unit->doc_num);
    $componentSheet->setCellValue('E5', ProductComponent::CalculationQuantity);
    $componentSheet->setCellValue('F5', '2.75');

    $componentSheet->setCellValue('A6', 'bom-methods-001');
    $componentSheet->setCellValue('B6', 'count-line');
    $componentSheet->setCellValue('C6', $countMaterial->doc_num);
    $componentSheet->setCellValue('D6', $unit->doc_num);
    $componentSheet->setCellValue('E6', ProductComponent::CalculationCount);
    $componentSheet->setCellValue('F6', '4');

    $path = tempnam(sys_get_temp_dir(), 'product-components-import-');
    (new Xlsx($workbook))->save($path);
    $componentSheet->setCellValue('F6', '4.5');
    $invalidPath = tempnam(sys_get_temp_dir(), 'invalid-product-components-import-');
    (new Xlsx($workbook))->save($invalidPath);
    $workbook->disconnectWorksheets();

    $upload = UploadedFile::fake()->createWithContent('product-components.xlsx', file_get_contents($path));
    $this->actingAs($actor)
        ->withSession($context)
        ->post(route('admin.products.import.store'), ['workbook' => $upload])
        ->assertRedirect();

    $batch = ExcelImportBatch::query()->latest('id')->firstOrFail();
    expect($batch->status)->toBe(ExcelImportBatch::StatusReady);

    $this->actingAs($actor)
        ->withSession($context)
        ->post(route('admin.products.import.confirm', $batch->public_uuid), ['confirmed' => '1'])
        ->assertRedirect(route('admin.products.import.show', $batch->public_uuid));

    $product = Product::query()->where('name', 'Imported BOM Methods Product')->firstOrFail();
    $components = ProductComponent::query()
        ->where('product_id', $product->getKey())
        ->get()
        ->keyBy('calculation_method');

    expect($components)->toHaveCount(4)
        ->and($components[ProductComponent::CalculationDirect]->component_product_id)->toBe($directMaterial->getKey())
        ->and((string) $components[ProductComponent::CalculationDirect]->quantity)->toBe('130.00000000')
        ->and((string) $components[ProductComponent::CalculationPercentage]->percentage)->toBe('2.00000000')
        ->and((string) $components[ProductComponent::CalculationPercentage]->quantity)->toBe('2.60000000')
        ->and($components[ProductComponent::CalculationPercentage]->reference_component_id)
        ->toBe($components[ProductComponent::CalculationDirect]->getKey())
        ->and((string) $components[ProductComponent::CalculationQuantity]->quantity)->toBe('2.75000000')
        ->and((string) $components[ProductComponent::CalculationCount]->quantity)->toBe('4.00000000');

    $invalidUpload = UploadedFile::fake()->createWithContent('invalid-product-components.xlsx', file_get_contents($invalidPath));
    $this->actingAs($actor)
        ->withSession($context)
        ->post(route('admin.products.import.store'), ['workbook' => $invalidUpload])
        ->assertRedirect();

    $invalidBatch = ExcelImportBatch::query()->latest('id')->firstOrFail();
    $issueMessages = $invalidBatch->rows()
        ->get()
        ->pluck('issues')
        ->flatten(1)
        ->pluck('message');

    expect($invalidBatch->status)->toBe(ExcelImportBatch::StatusInvalid)
        ->and($issueMessages)->toContain(__('products.components.count_integer'))
        ->and(Product::query()->where('name', 'Imported BOM Methods Product')->count())->toBe(1);
});

test('a fixed asset template submitted to products is invalid without creating products', function (): void {
    $context = excelImportContext();
    $actor = excelImportActor(['products.create', 'products.import', 'fixed_assets.create', 'fixed_assets.import']);
    $templateResponse = $this->actingAs($actor)
        ->withSession($context)
        ->get(route('admin.fixed-assets.assets.import.template'))
        ->assertOk();
    $upload = UploadedFile::fake()->createWithContent('assets.xlsx', file_get_contents($templateResponse->baseResponse->getFile()->getPathname()));

    $this->actingAs($actor)
        ->withSession($context)
        ->post(route('admin.products.import.store'), ['workbook' => $upload])
        ->assertRedirect();

    $batch = ExcelImportBatch::query()->firstOrFail();
    expect($batch->module)->toBe(ExcelImportBatch::ModuleProducts)
        ->and($batch->status)->toBe(ExcelImportBatch::StatusInvalid)
        ->and(Product::query()->count())->toBe(0);
});

test('a valid fixed asset workbook is staged and committed through the fixed asset service', function (): void {
    $actor = excelImportActor(['fixed_assets.create', 'fixed_assets.import']);
    $this->actingAs($actor);
    request()->setUserResolver(fn (): User => $actor);
    $context = excelImportFixedAssetContext();
    $templateResponse = $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.fixed-assets.assets.import.template'))
        ->assertOk();
    $workbook = IOFactory::load($templateResponse->baseResponse->getFile()->getPathname());
    $sheet = $workbook->getSheetByName('FixedAssets');
    $columns = collect($sheet->rangeToArray('A1:AA1')[0])
        ->mapWithKeys(fn (mixed $value, int $index): array => [(string) $value => Coordinate::stringFromColumnIndex($index + 1)]);
    $values = [
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'asset_date' => $context['period']->from_date->toDateString(),
        'asset_name' => 'Excel Imported Asset',
        'asset_group_account_doc_num' => $context['asset_category_doc_num'],
        'credit_account_doc_num' => $context['credit_account_doc_num'],
        'branch_doc_num' => $context['branch']->doc_num,
        'description' => 'Excel fixed asset description',
        'purchase_date' => $context['period']->from_date->toDateString(),
        'operation_date' => $context['period']->from_date->toDateString(),
        'purchase_value' => '1000',
        'currency_doc_num' => $context['currency_doc_num'],
        'exchange_rate' => '1',
        'is_depreciable' => '1',
        'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
        'salvage_value' => '0',
        'previous_depreciation' => '0',
        'useful_life' => '10',
        'status' => 'active',
    ];
    foreach ($values as $field => $value) {
        $sheet->setCellValue($columns->get($field).'3', $value);
    }
    $path = tempnam(sys_get_temp_dir(), 'fixed-asset-import-');
    (new Xlsx($workbook))->save($path);
    $workbook->disconnectWorksheets();

    $upload = UploadedFile::fake()->createWithContent('fixed-assets.xlsx', file_get_contents($path));
    $this->actingAs($actor)
        ->withSession($context['session'])
        ->post(route('admin.fixed-assets.assets.import.store'), ['workbook' => $upload])
        ->assertRedirect();

    $batch = ExcelImportBatch::query()->firstOrFail();
    expect($batch->status)->toBe(ExcelImportBatch::StatusReady)
        ->and(FixedAsset::query()->count())->toBe(0);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->post(route('admin.fixed-assets.assets.import.confirm', $batch->public_uuid), ['confirmed' => '1'])
        ->assertRedirect();

    expect(FixedAsset::query()->where('asset_name', 'Excel Imported Asset')->exists())->toBeTrue()
        ->and($batch->refresh()->status)->toBe(ExcelImportBatch::StatusImported);
});

test('formula cells are rejected at their physical Excel row and produce an error workbook', function (): void {
    $context = excelImportContext();
    $actor = excelImportActor(['products.create', 'products.import']);
    $templateResponse = $this->actingAs($actor)
        ->withSession($context)
        ->get(route('admin.products.import.template'))
        ->assertOk();
    $workbook = IOFactory::load($templateResponse->baseResponse->getFile()->getPathname());
    $sheet = $workbook->getSheetByName('Products');
    $sheet->setCellValue('A5', 'formula-001');
    $sheet->setCellValue('B5', '=1+1');
    $sheet->setCellValue('C5', Product::ClassificationFinishedProduct);
    $sheet->setCellValue('F5', 'active');
    $path = tempnam(sys_get_temp_dir(), 'product-formula-import-');
    (new Xlsx($workbook))->save($path);
    $workbook->disconnectWorksheets();

    $upload = UploadedFile::fake()->createWithContent('formula-products.xlsx', file_get_contents($path));
    $this->actingAs($actor)
        ->withSession($context)
        ->post(route('admin.products.import.store'), ['workbook' => $upload])
        ->assertRedirect();

    $batch = ExcelImportBatch::query()->firstOrFail();
    $row = ExcelImportRow::query()->where('batch_id', $batch->getKey())->firstOrFail();
    expect($batch->status)->toBe(ExcelImportBatch::StatusInvalid)
        ->and($row->excel_row)->toBe(5)
        ->and(collect($row->issues)->pluck('column')->all())->toContain('name')
        ->and(Product::query()->count())->toBe(0);

    $errorResponse = $this->actingAs($actor)
        ->withSession($context)
        ->get(route('admin.products.import.errors', $batch->public_uuid))
        ->assertOk();
    $errorWorkbook = IOFactory::load($errorResponse->baseResponse->getFile()->getPathname());

    expect($errorWorkbook->getSheetByName('Validation Errors'))->not->toBeNull();
});
