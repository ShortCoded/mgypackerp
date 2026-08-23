<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function productComponentActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function productComponentOperatingContext(object $test): Company
{
    static $documentNumber = 6300;

    $documentNumber++;
    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Product Component Company '.$documentNumber,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Product Component Branch '.$documentNumber,
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Product Component Period '.$documentNumber,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    $test->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return $company;
}

function productComponentSwitchContext(object $test, Company $company): void
{
    $branch = Branch::query()
        ->where('company_id', $company->getKey())
        ->firstOrFail();
    $period = FinancialPeriod::query()
        ->where('company_id', $company->getKey())
        ->firstOrFail();

    $test->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);
}

function productComponentUnit(Company $company, int $number = 1, string $name = 'Piece'): ItemUnit
{
    return ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'Unit-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => $name,
        'status' => 'active',
    ]);
}

function productComponentProduct(Company $company, array $overrides = []): Product
{
    static $number = 100;

    $number++;

    return Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'Product-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Component Product '.$number,
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    $this->componentCompany = productComponentOperatingContext($this);
});

test('product components schema and routes use public identifiers', function () {
    expect(Schema::hasTable('product_components'))->toBeTrue()
        ->and(Schema::hasColumn('product_components', 'public_id'))->toBeTrue()
        ->and(Schema::hasColumn('product_components', 'company_id'))->toBeTrue()
        ->and(Schema::hasColumn('product_components', 'product_id'))->toBeTrue()
        ->and(Schema::hasColumn('product_components', 'component_product_id'))->toBeTrue()
        ->and(Schema::hasColumn('product_components', 'unit_id'))->toBeTrue()
        ->and(Schema::hasColumn('product_components', 'quantity'))->toBeTrue()
        ->and(ProductComponent::calculationMethods())->toBe([
            ProductComponent::CalculationDirect,
            ProductComponent::CalculationPercentage,
            ProductComponent::CalculationQuantity,
            ProductComponent::CalculationCount,
        ]);

    foreach (['index', 'store', 'update', 'destroy'] as $action) {
        expect(Route::has("admin.products.components.{$action}"))->toBeTrue();
    }
});

test('can add raw material component and unit is derived from raw material product', function () {
    $actor = productComponentActor(['products.view', 'products.edit']);
    $unit = productComponentUnit($this->componentCompany, 11, 'Square Meter');
    $product = productComponentProduct($this->componentCompany, [
        'doc_number' => 1,
        'doc_num' => 'Product-00001',
        'name' => 'Finished Wardrobe',
    ]);
    $rawMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 2,
        'doc_num' => 'Product-00002',
        'name' => 'Raw Board',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);

    $payload = $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '2.5000',
            'notes' => 'For one unit',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');

    $component = ProductComponent::query()->where('public_id', $payload['public_id'])->firstOrFail();

    expect($payload)
        ->toHaveKey('public_id')
        ->not->toHaveKey('id')
        ->and($payload['component_product_doc_num'])->toBe($rawMaterial->doc_num)
        ->and($payload['unit'])->toContain('Square Meter')
        ->and($component->unit_id)->toBe($unit->getKey())
        ->and((string) $component->quantity)->toBe('2.50000000');

    $list = $this->actingAs($actor)
        ->getJson(route('admin.products.components.index', $product->doc_num))
        ->assertOk()
        ->json();

    expect($list['data'][0] ?? [])
        ->toHaveKey('public_id')
        ->toHaveKey('raw_material')
        ->not->toHaveKey('id')
        ->and($list['data'][0]['public_id'] ?? null)->toBe($component->public_id);
});

test('component quantities preserve eight-place precision and strictly normalize grouped input', function () {
    $actor = productComponentActor(['products.view', 'products.edit']);
    $unit = productComponentUnit($this->componentCompany, 113, 'Precision Unit');
    $product = productComponentProduct($this->componentCompany, [
        'doc_number' => 113,
        'doc_num' => 'Product-00113',
        'name' => 'Precision Product',
    ]);
    $rawMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 114,
        'doc_num' => 'Product-00114',
        'name' => 'Precision Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);

    $tiny = $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '0.0004582',
        ])
        ->assertOk()
        ->assertJsonPath('data.quantity', '0.0004582')
        ->assertJsonPath('data.quantity_raw', '0.00045820')
        ->json('data');

    expect((string) ProductComponent::query()->where('public_id', $tiny['public_id'])->value('quantity'))
        ->toBe('0.00045820');

    $grouped = $this->actingAs($actor)
        ->putJson(route('admin.products.components.update', [$product->doc_num, $tiny['public_id']]), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1,250.5',
        ])
        ->assertOk()
        ->assertJsonPath('data.quantity', '1,250.5')
        ->assertJsonPath('data.quantity_raw', '1250.50000000')
        ->json('data');

    expect((string) ProductComponent::query()->where('public_id', $grouped['public_id'])->value('quantity'))
        ->toBe('1250.50000000');

    $this->actingAs($actor)
        ->putJson(route('admin.products.components.update', [$product->doc_num, $tiny['public_id']]), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1,2,3',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['quantity']);
});

test('quantity and count calculation methods validate persist and hydrate their exact values', function () {
    $actor = productComponentActor(['products.view', 'products.create', 'products.edit']);
    $unit = productComponentUnit($this->componentCompany, 121, 'Piece');
    $product = productComponentProduct($this->componentCompany, [
        'doc_number' => 121,
        'doc_num' => 'Product-00121',
        'name' => 'Quantity and Count Product',
    ]);
    $quantityMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 122,
        'doc_num' => 'Product-00122',
        'name' => 'Quantity Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);
    $countMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 123,
        'doc_num' => 'Product-00123',
        'name' => 'Count Material',
        'item_classification' => Product::ClassificationPackaging,
        'item_unit_id' => $unit->getKey(),
    ]);

    $quantity = $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $quantityMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'calculation_method' => ProductComponent::CalculationQuantity,
            'quantity' => '2.75000001',
        ])
        ->assertOk()
        ->assertJsonPath('data.calculation_method', ProductComponent::CalculationQuantity)
        ->assertJsonPath('data.quantity_raw', '2.75000001')
        ->json('data');

    $count = $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $countMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'calculation_method' => ProductComponent::CalculationCount,
            'quantity' => '3',
        ])
        ->assertOk()
        ->assertJsonPath('data.calculation_method', ProductComponent::CalculationCount)
        ->assertJsonPath('data.quantity_raw', '3.00000000')
        ->json('data');

    $this->actingAs($actor)
        ->putJson(route('admin.products.components.update', [$product->doc_num, $count['public_id']]), [
            'component_product_doc_num' => $countMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'calculation_method' => ProductComponent::CalculationCount,
            'quantity' => '1.5',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['quantity']);

    expect(ProductComponent::query()->where('public_id', $quantity['public_id'])->firstOrFail())
        ->calculation_method->toBe(ProductComponent::CalculationQuantity)
        ->quantity->toBe('2.75000001')
        ->and(ProductComponent::query()->where('public_id', $count['public_id'])->firstOrFail())
        ->calculation_method->toBe(ProductComponent::CalculationCount)
        ->quantity->toBe('3.00000000');

    $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->assertSee('"calculation_method":"quantity"', false)
        ->assertSee('"calculation_method":"count"', false)
        ->assertSee('"quantity_raw":"2.75000001"', false)
        ->assertSee('"quantity_raw":"3.00000000"', false);

    $this->withSession([
        '_old_input' => [
            'components' => [[
                'client_key' => (string) Str::uuid(),
                'component_product_doc_num' => $countMaterial->doc_num,
                'unit_doc_num' => $unit->doc_num,
                'calculation_method' => ProductComponent::CalculationCount,
                'quantity' => '4',
            ]],
        ],
    ])->actingAs($actor)
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertSee('"calculation_method":"count"', false)
        ->assertSee('"quantity":"4"', false);
});

test('standalone percentage components accept one canonical percentage value and preserve legacy updates', function () {
    $actor = productComponentActor(['products.view', 'products.edit']);
    $unit = productComponentUnit($this->componentCompany, 115, 'Kilogram');
    $product = productComponentProduct($this->componentCompany, [
        'doc_number' => 115,
        'doc_num' => 'Product-00115',
        'name' => 'Standalone Percentage Product',
    ]);
    $baseMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 116,
        'doc_num' => 'Product-00116',
        'name' => 'Standalone Base Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);
    $dependentMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 117,
        'doc_num' => 'Product-00117',
        'name' => 'Standalone Dependent Material',
        'item_classification' => Product::ClassificationPackaging,
        'item_unit_id' => $unit->getKey(),
    ]);
    $base = $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $baseMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'calculation_method' => ProductComponent::CalculationDirect,
            'quantity' => '100',
            'input_source' => ProductComponent::InputWeight,
        ])
        ->assertOk()
        ->json('data');
    $dependent = $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $dependentMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'calculation_method' => ProductComponent::CalculationPercentage,
            'quantity' => '2',
            'reference_component_key' => $base['public_id'],
            'input_source' => ProductComponent::InputPercentage,
        ])
        ->assertOk()
        ->assertJsonPath('data.quantity_raw', '2.00000000')
        ->assertJsonPath('data.percentage_raw', '2.00000000')
        ->json('data');

    $this->actingAs($actor)
        ->putJson(route('admin.products.components.update', [$product->doc_num, $dependent['public_id']]), [
            'component_product_doc_num' => $dependentMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'calculation_method' => ProductComponent::CalculationPercentage,
            'quantity' => '10',
            'percentage' => ['stale-percentage'],
            'reference_component_key' => $base['public_id'],
            'input_source' => ProductComponent::InputWeight,
        ])
        ->assertOk()
        ->assertJsonPath('data.quantity_raw', '10.00000000')
        ->assertJsonPath('data.percentage_raw', '10.00000000');

    $component = ProductComponent::query()
        ->where('public_id', $dependent['public_id'])
        ->firstOrFail();

    expect((string) $component->quantity)->toBe('10.00000000')
        ->and((string) $component->percentage)->toBe('10.00000000');

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $dependentMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'calculation_method' => ProductComponent::CalculationPercentage,
            'percentage' => '2',
            'reference_component_key' => $base['public_id'],
            'input_source' => ['forged-source'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['input_source']);
});

test('standalone direct components preserve unitless materials without weakening unit requirements', function () {
    $actor = productComponentActor(['products.view', 'products.edit']);
    $unit = productComponentUnit($this->componentCompany, 118, 'Kilogram');
    $product = productComponentProduct($this->componentCompany, [
        'doc_number' => 118,
        'doc_num' => 'Product-00118',
        'name' => 'Unit Requirement Product',
    ]);
    $unitBearingMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 119,
        'doc_num' => 'Product-00119',
        'name' => 'Unit Bearing Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);
    $unitlessMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 120,
        'doc_num' => 'Product-00120',
        'name' => 'Legacy Unitless Material',
        'item_classification' => Product::ClassificationRawMaterial,
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $unitBearingMaterial->doc_num,
            'unit_doc_num' => '',
            'calculation_method' => ProductComponent::CalculationDirect,
            'quantity' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['unit_doc_num']);

    $unitless = $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $unitlessMaterial->doc_num,
            'unit_doc_num' => '',
            'calculation_method' => ProductComponent::CalculationDirect,
            'quantity' => '1',
        ])
        ->assertOk()
        ->assertJsonPath('data.unit_doc_num', null)
        ->json('data');

    $this->actingAs($actor)
        ->putJson(route('admin.products.components.update', [$product->doc_num, $unitless['public_id']]), [
            'component_product_doc_num' => $unitlessMaterial->doc_num,
            'unit_doc_num' => '',
            'calculation_method' => ProductComponent::CalculationDirect,
            'quantity' => '2',
        ])
        ->assertOk()
        ->assertJsonPath('data.quantity_raw', '2.00000000')
        ->assertJsonPath('data.unit_doc_num', null);

    expect(ProductComponent::query()
        ->where('public_id', $unitless['public_id'])
        ->value('unit_id'))->toBeNull();

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $unitlessMaterial->doc_num,
            'unit_doc_num' => '',
            'calculation_method' => ProductComponent::CalculationPercentage,
            'percentage' => '10',
            'reference_component_key' => $unitless['public_id'],
            'input_source' => ProductComponent::InputPercentage,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['unit_doc_num']);
});

test('can add packaging material component and unit is derived from packaging material', function () {
    $actor = productComponentActor(['products.view', 'products.edit']);
    $unit = productComponentUnit($this->componentCompany, 112, 'Roll');
    $product = productComponentProduct($this->componentCompany, [
        'doc_number' => 3,
        'doc_num' => 'Product-00003',
        'name' => 'Finished Container',
    ]);
    $packagingMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 4,
        'doc_num' => 'Product-00004',
        'name' => 'Packaging Film',
        'item_classification' => Product::ClassificationPackaging,
        'item_unit_id' => $unit->getKey(),
    ]);

    $payload = $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $packagingMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1.2500',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');

    expect(ProductComponent::query()->where('public_id', $payload['public_id'])->value('component_product_id'))
        ->toBe($packagingMaterial->getKey());
});

test('product component grid renders one main row and one percentage details row', function () {
    $actor = productComponentActor(['products.view', 'products.create', 'products.edit']);
    $unit = productComponentUnit($this->componentCompany, 1111, 'Display Unit');
    $product = productComponentProduct($this->componentCompany, ['doc_number' => 1111, 'doc_num' => 'Product-01111']);
    $rawMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 1112,
        'doc_num' => 'Product-01112',
        'name' => 'Display Raw Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);

    ProductComponent::query()->create([
        'company_id' => $this->componentCompany->getKey(),
        'product_id' => $product->getKey(),
        'component_product_id' => $rawMaterial->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '1.2500',
    ]);

    $createResponse = $this->actingAs($actor)
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertSee('js-product-component-unit', false)
        ->assertSee('disabled', false)
        ->assertSee('product-components-scroll', false)
        ->assertSee('product-component-value-column', false)
        ->assertSee(__('products.components.value'))
        ->assertSee('data-component-value-heading', false)
        ->assertSee('form-control text-center js-product-component-quantity', false)
        ->assertSee('name="components[__INDEX__][quantity]"', false)
        ->assertSee('js-product-component-percentage-addon', false)
        ->assertSee('assets/css/modules/Core/products.css', false)
        ->assertSee('js-product-component-row product-component-main-row', false)
        ->assertSee('js-product-component-details-row product-component-details-row d-none', false)
        ->assertSee('product-component-details-layout', false)
        ->assertSee('product-component-reference-control', false)
        ->assertSee('product-component-explanation-control', false)
        ->assertSee('js-product-component-calculation-explanation', false)
        ->assertSee('id="product-component-reference-__INDEX__"', false)
        ->assertSee('aria-describedby="product-component-reference-error-__INDEX__"', false)
        ->assertSee('colspan="7"', false)
        ->assertSee('data-numeric-input', false)
        ->assertSee('data-numeric-scale="8"', false)
        ->assertSee('js-product-component-duplicate-row', false)
        ->assertSee(__('products.components.duplicate_row_shortcut'))
        ->assertSee(__('products.components.delete_row_shortcut'))
        ->assertSee('value="'.ProductComponent::CalculationQuantity.'"', false)
        ->assertSee('value="'.ProductComponent::CalculationCount.'"', false)
        ->assertDontSee('data-component-value-label', false)
        ->assertDontSee('data-component-field="percentage"', false)
        ->assertDontSee('js-product-component-percentage-fields', false)
        ->assertDontSee('js-product-component-percentage"', false)
        ->assertDontSee('name="components[__INDEX__][unit_id]"', false);

    expect($createResponse->getContent())->not->toContain('js-product-component-unit" type="text"');

    expect(file_get_contents(public_path('assets/js/modules/Core/products.js')))
        ->toContain("['direct', 'percentage', 'quantity', 'count']")
        ->toContain("isCount ? '0'")
        ->toContain('function refreshComponentValueHeading($panel)')
        ->toContain("methods.length === 1 ? componentValueHeading(methods[0]) : message('componentValueLabel')")
        ->toContain("percentage: 'componentPercentageLabel'")
        ->toContain('function componentDetailsRow($row)')
        ->toContain('function componentMainRow($element)')
        ->toContain('$details.toggleClass(\'d-none\', !isPercentage)')
        ->toContain('$row.toggleClass(\'product-component-has-details\', isPercentage)')
        ->toContain('function initComponentRowSelect2($row)')
        ->toContain('function resetComponentReferenceSelect2($panel)')
        ->toContain('$reference.removeData(\'select2AjaxInitialized\')')
        ->toContain('$details.find(\'label[for^="product-component-reference-"]\').attr(\'for\', referenceInputId)')
        ->toContain('function renderComponentClientValidation($panel)')
        ->toContain("message('componentReferenceRequired')")
        ->toContain('initSelect2($details[0])')
        ->toContain('$anchor.after($row, $details)')
        ->toContain('componentRowGroup($row).remove()')
        ->toContain('componentMainRow($(this))')
        ->toContain("formatDecimal(weight) + (unitText ? ' ' + unitText : '')")
        ->toContain("'muted',\n                formula")
        ->toContain('setComponentInputSource($row, isPercentage ? \'percentage\' : \'weight\')')
        ->toContain('var referenceUnitWeight = decimalMultiply(referenceResult.quantity, percentageRatio, componentWorkingScale)')
        ->toContain("if (targetUnit !== '')")
        ->not->toContain('convertedReferenceWeight')
        ->not->toContain('updateComponentCalculatedFieldState')
        ->toContain('decimalIsWholeNumber');

    expect(file_get_contents(public_path('assets/css/modules/Core/products.css')))
        ->toContain('grid-template-columns: minmax(24rem, 1.35fr) minmax(18rem, 1fr)')
        ->toContain('overflow-x: auto')
        ->toContain('border-inline-start')
        ->toContain('.product-component-value-column .input-group')
        ->toContain('[data-component-unit-display]')
        ->not->toContain('[dir="rtl"]');

    $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->assertSee('Display Unit')
        ->assertSee('js-product-component-duplicate-row', false)
        ->assertDontSee('name="components[0][unit_id]"', false);

    $this->actingAs($actor)
        ->get(route('admin.products.show', $product->doc_num))
        ->assertOk()
        ->assertSee('Display Unit')
        ->assertDontSee('js-product-component-duplicate-row', false)
        ->assertDontSee('js-product-component-remove-row', false);
});

test('component validation allows distinct duplicate lines and blocks cross-company deleted non-raw self and non-positive quantity', function () {
    $actor = productComponentActor(['products.edit']);
    $companyA = $this->componentCompany;
    $unit = productComponentUnit($companyA, 12, 'Meter');
    $product = productComponentProduct($companyA, ['doc_number' => 3, 'doc_num' => 'Product-00003', 'name' => 'Master Item']);
    $otherProduct = productComponentProduct($companyA, ['doc_number' => 4, 'doc_num' => 'Product-00004', 'name' => 'Other Master']);
    $rawMaterial = productComponentProduct($companyA, [
        'doc_number' => 5,
        'doc_num' => 'Product-00005',
        'name' => 'Raw Fabric',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);
    $nonRaw = productComponentProduct($companyA, ['doc_number' => 6, 'doc_num' => 'Product-00006', 'name' => 'Finished Child']);
    $deletedRaw = productComponentProduct($companyA, [
        'doc_number' => 7,
        'doc_num' => 'Product-00007',
        'name' => 'Deleted Raw',
        'item_classification' => Product::ClassificationRawMaterial,
    ]);
    $deletedRaw->delete();

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1',
        ])
        ->assertOk();

    expect(ProductComponent::query()
        ->where('product_id', $product->getKey())
        ->where('component_product_id', $rawMaterial->getKey())
        ->count())->toBe(2);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $otherProduct->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $product->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['component_product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $nonRaw->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['component_product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $deletedRaw->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['component_product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '0',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['quantity']);

    $companyB = productComponentOperatingContext($this);
    $otherCompanyRaw = productComponentProduct($companyB, [
        'doc_number' => 70,
        'doc_num' => 'Product-00070',
        'name' => 'Other Company Raw',
        'item_classification' => Product::ClassificationRawMaterial,
    ]);

    productComponentSwitchContext($this, $companyA);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $otherCompanyRaw->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['component_product_doc_num']);

    productComponentSwitchContext($this, $companyB);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'quantity' => '1',
        ])
        ->assertForbidden();
});

test('can update and delete product component by public id only', function () {
    $actor = productComponentActor(['products.view', 'products.edit']);
    $unit = productComponentUnit($this->componentCompany, 13, 'Piece');
    $product = productComponentProduct($this->componentCompany, ['doc_number' => 8, 'doc_num' => 'Product-00008']);
    $rawMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 9,
        'doc_num' => 'Product-00009',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);
    $component = ProductComponent::query()->create([
        'company_id' => $this->componentCompany->getKey(),
        'product_id' => $product->getKey(),
        'component_product_id' => $rawMaterial->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '1.0000',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.components.update', [$product->doc_num, $component->public_id]), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '3.7500',
            'notes' => 'Updated quantity',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingPath('data.id');

    expect((string) $component->refresh()->quantity)->toBe('3.75000000')
        ->and($component->notes)->toBe('Updated quantity');

    $this->actingAs($actor)
        ->deleteJson(route('admin.products.components.destroy', [$product->doc_num, $component->public_id]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ProductComponent::withTrashed()->where('public_id', $component->public_id)->first()?->trashed())->toBeTrue();
});

test('material select2 returns active raw and packaging materials for the current company', function () {
    $actor = productComponentActor(['products.view']);
    $unit = productComponentUnit($this->componentCompany, 15, 'Linear Meter');
    $master = productComponentProduct($this->componentCompany, ['doc_number' => 12, 'doc_num' => 'Product-00012', 'name' => 'Master Product']);
    Storage::disk('public')->put('products/images/raw-visible-fabric.webp', 'raw material image');

    $rawMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 13,
        'doc_num' => 'Product-00013',
        'name' => 'Raw Visible Fabric',
        'image_path' => 'products/images/raw-visible-fabric.webp',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);
    $plainRawMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 17,
        'doc_num' => 'Product-00017',
        'name' => 'Raw Plain Fabric',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);
    $packagingMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 18,
        'doc_num' => 'Product-00018',
        'name' => 'Packaging Visible Fabric Film',
        'item_classification' => Product::ClassificationPackaging,
        'item_unit_id' => $unit->getKey(),
    ]);
    productComponentProduct($this->componentCompany, [
        'doc_number' => 14,
        'doc_num' => 'Product-00014',
        'name' => 'Finished Hidden Product',
    ]);
    $deletedRaw = productComponentProduct($this->componentCompany, [
        'doc_number' => 15,
        'doc_num' => 'Product-00015',
        'name' => 'Deleted Raw Fabric',
        'item_classification' => Product::ClassificationRawMaterial,
    ]);
    $deletedRaw->delete();

    $otherCompany = productComponentOperatingContext($this);
    $foreignEquivalentUnit = productComponentUnit($otherCompany, 19, 'Foreign Equivalent Unit');
    productComponentProduct($otherCompany, [
        'doc_number' => 16,
        'doc_num' => 'Product-00016',
        'name' => 'Other Company Raw Fabric',
        'item_classification' => Product::ClassificationRawMaterial,
    ]);
    $deletedEquivalentUnit = productComponentUnit($this->componentCompany, 20, 'Deleted Equivalent Unit');
    $deletedEquivalentUnit->delete();

    $rawMaterial->update([
        'equivalent_value' => '1000',
        'equivalent_unit_id' => $foreignEquivalentUnit->getKey(),
    ]);
    $plainRawMaterial->update([
        'equivalent_value' => '1000',
        'equivalent_unit_id' => $deletedEquivalentUnit->getKey(),
    ]);

    $this->withSession([
        OperatingContextService::CompanyIdKey => $this->componentCompany->getKey(),
        OperatingContextService::CompanyDocNumKey => $this->componentCompany->doc_num,
    ]);

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.select2.raw-material-products', [
            'q' => 'Fabric',
            'current_product_doc_num' => $master->doc_num,
        ]))
        ->assertOk()
        ->json();

    $results = collect($payload['results'] ?? []);
    $imageResult = $results->firstWhere('id', $rawMaterial->doc_num);
    $plainResult = $results->firstWhere('id', $plainRawMaterial->doc_num);
    $packagingResult = $results->firstWhere('id', $packagingMaterial->doc_num);
    $expectedImageUrl = Storage::disk('public')->url('products/images/raw-visible-fabric.webp');
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($payload)
        ->toHaveKey('results')
        ->toHaveKey('pagination')
        ->and($payload['pagination'])->toHaveKey('more');

    expect($imageResult)
        ->toBeArray()
        ->and($imageResult['id'])->toBe($rawMaterial->doc_num)
        ->and($imageResult['id'])->not->toBe((string) $rawMaterial->getKey())
        ->and($imageResult['text'])->toContain($rawMaterial->doc_num)
        ->and($imageResult['text'])->toContain('Raw Visible Fabric')
        ->and($imageResult['unit_text'])->toContain('Linear Meter')
        ->and($imageResult['unit_options'])->toHaveCount(1)
        ->and($imageResult['unit_options'][0]['id'])->toBe((string) $unit->doc_num)
        ->and($imageResult['imageUrl'])->toBe($expectedImageUrl)
        ->and($imageResult)->not->toHaveKey('image_path');

    expect($plainResult)
        ->toBeArray()
        ->and($plainResult['imageUrl'])->toBeNull();

    expect($packagingResult)
        ->toBeArray()
        ->and($packagingResult['text'])->toContain('Packaging Visible Fabric Film')
        ->and($packagingResult['unit_text'])->toContain('Linear Meter');

    expect($json)
        ->toContain($rawMaterial->doc_num)
        ->toContain('Raw Visible Fabric')
        ->toContain('Linear Meter')
        ->toContain($plainRawMaterial->doc_num)
        ->toContain($packagingMaterial->doc_num)
        ->not->toContain('Finished Hidden Product')
        ->not->toContain('Deleted Raw Fabric')
        ->not->toContain('Other Company Raw Fabric')
        ->not->toContain('Foreign Equivalent Unit')
        ->not->toContain('Deleted Equivalent Unit')
        ->not->toContain('image_path')
        ->not->toContain($master->doc_num);

    expect(app(ProductComponentUnitOptionsService::class)->options($rawMaterial->fresh()))
        ->toHaveCount(1)
        ->and(app(ProductComponentUnitOptionsService::class)->options($plainRawMaterial->fresh()))
        ->toHaveCount(1)
        ->and(app(ProductComponentUnitOptionsService::class)->options($packagingMaterial->fresh()))
        ->toHaveCount(1);

    $selectedPayload = $this->actingAs($actor)
        ->getJson(route('admin.select2.raw-material-products', [
            'selected_doc_num' => $rawMaterial->doc_num,
            'current_product_doc_num' => $master->doc_num,
        ]))
        ->assertOk()
        ->json();

    expect($selectedPayload['results'][0]['id'] ?? null)->toBe($rawMaterial->doc_num)
        ->and($selectedPayload['results'][0]['unit_text'] ?? null)->toContain('Linear Meter')
        ->and($selectedPayload['results'][0]['imageUrl'] ?? null)->toBe($expectedImageUrl);
});

test('product component selector and validation do not use legacy product type', function () {
    $selector = file_get_contents(base_path('modules/Core/Http/Controllers/Select2/ProductRawMaterialSelect2Controller.php'));
    $validation = file_get_contents(base_path('modules/Core/Http/Requests/Concerns/ValidatesProductPayload.php'));
    $javascript = file_get_contents(base_path('public/assets/js/modules/Core/products.js'));
    $select2Ajax = file_get_contents(base_path('public/assets/js/modules/Core/select2-ajax.js'));
    $form = file_get_contents(base_path('resources/views/modules/core/products/form.blade.php'));

    expect($selector)
        ->toContain('->materialItems()')
        ->toContain('imageUrl')
        ->not->toContain('product_type')
        ->and($validation)
        ->toContain('Product::ClassificationRawMaterial')
        ->not->toContain('product_type')
        ->and($javascript)
        ->toContain('duplicateComponentRow')
        ->toContain("$(option).attr('data-image-url'")
        ->toContain("public_id: ''")
        ->not->toContain('product_type')
        ->and($select2Ajax)
        ->toContain('productImageTemplate')
        ->toContain('templateResult')
        ->toContain('templateSelection')
        ->toContain('.text(text)')
        ->not->toContain('.html(')
        ->and($form)
        ->toContain('js-product-component-raw-material')
        ->toContain('data-template="product-image"');
});

test('product view mode shows components read only', function () {
    $actor = productComponentActor(['products.view']);
    $unit = productComponentUnit($this->componentCompany, 14, 'Kilogram');
    $product = productComponentProduct($this->componentCompany, ['doc_number' => 10, 'doc_num' => 'Product-00010']);
    $rawMaterial = productComponentProduct($this->componentCompany, [
        'doc_number' => 11,
        'doc_num' => 'Product-00011',
        'name' => 'Raw Glue',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
    ]);

    ProductComponent::query()->create([
        'company_id' => $this->componentCompany->getKey(),
        'product_id' => $product->getKey(),
        'component_product_id' => $rawMaterial->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '0.7500',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.products.show', $product->doc_num))
        ->assertOk()
        ->assertSee(__('products.tabs.components'))
        ->assertSee(__('products.components.helper'))
        ->assertSee('Raw Glue')
        ->assertSee('0.75')
        ->assertSee('product-components-table', false)
        ->assertDontSee('js-product-component-add-row', false)
        ->assertDontSee('js-product-component-submit', false);
});
