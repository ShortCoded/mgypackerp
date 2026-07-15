<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\OperatingContextService;
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
        ->and(Schema::hasColumn('product_components', 'quantity'))->toBeTrue();

    foreach (['index', 'store', 'update', 'destroy'] as $action) {
        expect(Route::has("admin.products.components.{$action}"))->toBeTrue();
    }
});

test('can add raw material component and unit is derived from raw material product', function () {
    $actor = productComponentActor(['products.view', 'products.edit']);
    $unit = productComponentUnit($this->componentCompany, 11, 'Square Meter');
    $untrustedUnit = productComponentUnit($this->componentCompany, 111, 'Untrusted Unit');
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
            'unit_id' => $untrustedUnit->getKey(),
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
        ->and((string) $component->quantity)->toBe('2.5000');

    $list = $this->actingAs($actor)
        ->getJson(route('admin.products.components.index', $product->doc_num))
        ->assertOk()
        ->json();

    expect(json_encode($list, JSON_THROW_ON_ERROR))
        ->toContain($component->public_id)
        ->toContain('Raw Board')
        ->not->toContain('"id"');
});

test('product component grid renders display-only unit centered quantity and duplicate action', function () {
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
        ->assertSee('data-component-unit-display', false)
        ->assertSee('aria-readonly="true"', false)
        ->assertSee('<th class="text-center" style="width: 14%">'.__('products.components.quantity').'</th>', false)
        ->assertSee('form-control text-center js-product-component-quantity', false)
        ->assertSee('js-product-component-duplicate-row', false)
        ->assertSee(__('products.components.duplicate_row_shortcut'))
        ->assertSee(__('products.components.delete_row_shortcut'))
        ->assertDontSee('name="components[__INDEX__][unit_id]"', false);

    expect($createResponse->getContent())->not->toContain('js-product-component-unit" type="text"');

    $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->assertSee('Display Unit')
        ->assertSee('data-component-unit-display', false)
        ->assertSee('js-product-component-duplicate-row', false)
        ->assertDontSee('name="components[0][unit_id]"', false);

    $this->actingAs($actor)
        ->get(route('admin.products.show', $product->doc_num))
        ->assertOk()
        ->assertSee('Display Unit')
        ->assertDontSee('js-product-component-duplicate-row', false)
        ->assertDontSee('js-product-component-remove-row', false);
});

test('component validation blocks duplicates cross-company deleted non-raw self and non-positive quantity', function () {
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
            'quantity' => '1',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'quantity' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['component_product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $otherProduct->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
            'quantity' => '1',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $product->doc_num,
            'quantity' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['component_product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $nonRaw->doc_num,
            'quantity' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['component_product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $deletedRaw->doc_num,
            'quantity' => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['component_product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.components.store', $product->doc_num), [
            'component_product_doc_num' => $rawMaterial->doc_num,
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
            'quantity' => '3.7500',
            'notes' => 'Updated quantity',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingPath('data.id');

    expect((string) $component->refresh()->quantity)->toBe('3.7500')
        ->and($component->notes)->toBe('Updated quantity');

    $this->actingAs($actor)
        ->deleteJson(route('admin.products.components.destroy', [$product->doc_num, $component->public_id]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ProductComponent::withTrashed()->where('public_id', $component->public_id)->first()?->trashed())->toBeTrue();
});

test('raw material select2 returns only active raw products for current company', function () {
    $actor = productComponentActor(['products.view']);
    $unit = productComponentUnit($this->componentCompany, 15, 'Linear Meter');
    $master = productComponentProduct($this->componentCompany, ['doc_number' => 12, 'doc_num' => 'Product-00012', 'name' => 'Master Product']);
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
    productComponentProduct($otherCompany, [
        'doc_number' => 16,
        'doc_num' => 'Product-00016',
        'name' => 'Other Company Raw Fabric',
        'item_classification' => Product::ClassificationRawMaterial,
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
        ->and($imageResult['imageUrl'])->toBe($expectedImageUrl)
        ->and($imageResult)->not->toHaveKey('image_path');

    expect($plainResult)
        ->toBeArray()
        ->and($plainResult['imageUrl'])->toBeNull();

    expect($json)
        ->toContain($rawMaterial->doc_num)
        ->toContain('Raw Visible Fabric')
        ->toContain('Linear Meter')
        ->toContain($plainRawMaterial->doc_num)
        ->not->toContain('Finished Hidden Product')
        ->not->toContain('Deleted Raw Fabric')
        ->not->toContain('Other Company Raw Fabric')
        ->not->toContain('image_path')
        ->not->toContain($master->doc_num);

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
        ->toContain('Product::ClassificationRawMaterial')
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
