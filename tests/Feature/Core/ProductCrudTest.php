<?php

use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemDecal;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\ArchiveFileService;
use Modules\Core\Services\ArchiveFileUsageService;
use Modules\Core\Services\ArchiveFolderService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitConversionService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\Reports\ProductDataReport;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function productCrudActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permissions = array_values(array_unique([...$permissions, 'file_manager.view']));

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function productPayload(array $overrides = []): array
{
    return [
        'name' => 'Frozen Mango',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
        'cost_as_inventory' => '1',
        'is_displayable' => '0',
        'notes' => 'Product master data only',
        'submit_action' => 'save_edit',
        ...$overrides,
    ];
}

function productPayloadWithImage(array $overrides = []): array
{
    $companyId = session(OperatingContextService::CompanyIdKey);
    $company = $companyId ? Company::query()->findOrFail($companyId) : Company::query()->latest('id')->firstOrFail();
    $file = productArchiveFileForCompany(
        $company,
        UploadedFile::fake()->image('product-'.Str::uuid()->toString().'.jpg')->size(64),
    );

    return productPayload([
        'image_archive_file_doc_num' => $file->doc_num,
        ...$overrides,
    ]);
}

function productArchiveFileForCompany(Company $company, UploadedFile $file): ArchiveFile
{
    $root = app(ArchiveFolderService::class)->generalRoot();

    $files = app(ArchiveFileService::class)->upload(
        files: [$file],
        attachable: $company,
        module: 'core',
        recordType: 'company',
        recordDocNum: $company->doc_num,
        folder: $root,
    );

    return $files[0];
}

function productSvgImage(string $name = 'product-vector.svg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1" fill="#fff"/></svg>',
    );
}

function productBmpImage(string $name = 'product-bitmap.bmp'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        base64_decode('Qk1GAAAAAAAAADYAAAAoAAAAAQAAAAEAAAABABgAAAAAAAQAAAAAAAAAAAAAAAAAAAAAAP///wAA', true) ?: '',
    );
}

function productMainImageUsage(Product $product): ?ArchiveFileUsage
{
    return ArchiveFileUsage::query()
        ->where('usable_type', $product->getMorphClass())
        ->where('usable_id', $product->getKey())
        ->where('collection', Product::ImageCollection)
        ->where('role', Product::MainImageRole)
        ->first();
}

function withProductCrudEnvironment(string $environment, callable $callback): mixed
{
    $originalEnvironment = app()->environment();

    try {
        app()->detectEnvironment(fn (): string => $environment);

        return $callback();
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }
}

function productOperatingContext(object $test): Company
{
    static $documentNumber = 6200;

    $documentNumber++;
    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Product Company '.$documentNumber,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Product Branch '.$documentNumber,
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Product Period '.$documentNumber,
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

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->productCompany = productOperatingContext($this);
});

test('products permissions are discoverable and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();

    foreach (['view', 'create', 'edit', 'delete', 'clone', 'view_trashed', 'restore', 'document_number.control', 'document_number_settings.update'] as $action) {
        expect(Permission::query()->where('name', "products.{$action}")->exists())->toBeTrue()
            ->and($adminRole->hasPermissionTo("products.{$action}"))->toBeTrue();
    }
});

test('products index renders through standard core crud shell', function () {
    $actor = productCrudActor([
        'products.view',
        'products.create',
        'products.delete',
        'products.view_trashed',
        'products.document_number_settings.update',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertSee(__('products.title'))
        ->assertSee(__('menu.inventory'))
        ->assertSee(__('products.attributes.color'))
        ->assertSee(__('products.attributes.decal'))
        ->assertSee(__('products.attributes.origin_country'))
        ->assertSee(__('products.attributes.barcode'))
        ->assertSee(__('products.attributes.reorder_point'))
        ->assertSee(__('products.attributes.item_classification'))
        ->assertSee(__('products.attributes.image'))
        ->assertSee('product-table-image', false)
        ->assertSee('id="product-image-preview-modal"', false)
        ->assertSee(__('products.image.preview_title'))
        ->assertSee('js-product-image-preview-image', false)
        ->assertSee('js-product-image-preview-fallback', false)
        ->assertSee(route('admin.products.data'), false)
        ->assertSee(route('admin.products.bulk-delete'), false)
        ->assertSee('id="products_trash_filter"', false)
        ->assertSee('assets/js/modules/Core/products.js', false)
        ->assertDontSee('assets/js/modules/Core/production-guard.js', false)
        ->assertDontSee('is_coolable', false)
        ->assertDontSee('data-id=', false);
});

test('product list preserves valid dashboard filters in its data endpoint', function () {
    $actor = productCrudActor(['products.view']);

    $this->actingAs($actor)
        ->get(route('admin.products.index', [
            'status' => 'active',
            'components_state' => 'with',
        ]))
        ->assertOk()
        ->assertSee(route('admin.products.data', [
            'status' => 'active',
            'components_state' => 'with',
        ]));

    $this->actingAs($actor)
        ->get(route('admin.products.index', [
            'status' => 'unsupported',
            'components_state' => 'unsupported',
        ]))
        ->assertOk()
        ->assertSee(route('admin.products.data'), false)
        ->assertDontSee(route('admin.products.data', ['status' => 'unsupported']))
        ->assertDontSee(route('admin.products.data', ['components_state' => 'unsupported']));
});

test('product data table filters active products by component availability', function () {
    $actor = productCrudActor(['products.view']);
    $componentProduct = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 2250,
        'doc_num' => 'RawMaterial-02250',
        'name' => 'Dashboard Filter Component',
        'item_classification' => Product::ClassificationRawMaterial,
        'status' => 'active',
    ]);
    $activeWithComponents = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 2251,
        'doc_num' => 'Product-02251',
        'name' => 'Active Product With Components',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);
    $activeWithoutComponents = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 2252,
        'doc_num' => 'Product-02252',
        'name' => 'Active Product Without Components',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);
    $inactiveWithComponents = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 2253,
        'doc_num' => 'Product-02253',
        'name' => 'Inactive Product With Components',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'inactive',
    ]);

    foreach ([$activeWithComponents, $inactiveWithComponents] as $product) {
        ProductComponent::query()->create([
            'company_id' => $this->productCompany->getKey(),
            'product_id' => $product->getKey(),
            'component_product_id' => $componentProduct->getKey(),
            'quantity' => 1,
        ]);
    }

    ProductComponent::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'product_id' => $activeWithoutComponents->getKey(),
        'component_product_id' => $componentProduct->getKey(),
        'quantity' => 1,
    ])->delete();

    $withComponents = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'status' => 'active',
            'components_state' => 'with',
        ]))
        ->assertOk()
        ->json();

    expect($withComponents['recordsFiltered'])->toBe(1)
        ->and(array_column($withComponents['data'], 'doc_num'))->toHaveCount(1)
        ->and($withComponents['data'][0]['doc_num'])->toContain($activeWithComponents->doc_num);

    $withoutComponents = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'status' => 'active',
            'components_state' => 'without',
        ]))
        ->assertOk()
        ->json();

    expect($withoutComponents['recordsFiltered'])->toBe(1)
        ->and($withoutComponents['data'][0]['doc_num'])->toContain($activeWithoutComponents->doc_num);
});

test('product component report applies dashboard filters on first render', function () {
    $actor = productCrudActor(['reports.products_data.view']);

    $this->actingAs($actor)
        ->get(route('admin.reports.products-data.index', [
            'result_mode' => ProductDataReport::ModeDetailed,
            'item_scope' => ProductDataReport::ItemScopeProducts,
            'record_state' => 'active',
            'status' => 'active',
            'components_state' => 'with',
        ]))
        ->assertOk()
        ->assertSee('value="detailed" selected', false)
        ->assertSee('value="products" selected', false)
        ->assertSee('value="active" selected', false)
        ->assertSee('value="with" selected', false);
});

test('production guard script is included only for production authenticated layout', function (string $environment, bool $shouldLoad): void {
    $actor = productCrudActor(['products.view']);

    withProductCrudEnvironment($environment, function () use ($actor, $shouldLoad): void {
        $response = $this->actingAs($actor)
            ->get(route('admin.products.index'))
            ->assertOk();

        if ($shouldLoad) {
            $response->assertSee('assets/js/modules/Core/production-guard.js', false);

            return;
        }

        $response->assertDontSee('assets/js/modules/Core/production-guard.js', false);
    });
})->with([
    'production' => ['production', true],
    'local' => ['local', false],
    'development' => ['development', false],
    'testing' => ['testing', false],
]);

test('products create form renders without blade parse errors', function () {
    config()->set('products.image_required', false);

    $actor = productCrudActor(['products.create', 'products.view', 'products.document_number.control', 'item_colors.create', 'item_decals.create', 'item_origin_countries.create', 'file_manager.view', 'file_manager.upload', 'file_manager.folders.create']);
    $legacyClassifierField = 'product'.'_type';

    $response = $this->actingAs($actor)
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertSee(__('products.create'))
        ->assertSee('name="doc_number"', false)
        ->assertSee(__('products.document_number_control.placeholder'))
        ->assertSee(__('products.document_number_control.helper'))
        ->assertSee(__('products.attributes.image'))
        ->assertSee(__('products.image.select'))
        ->assertSee(__('products.image.no_file_selected'))
        ->assertSee('data-file-picker', false)
        ->assertSee('id="file-picker-modal"', false)
        ->assertSee(route('admin.file-manager.picker.items'), false)
        ->assertSee(route('admin.file-manager.picker.files.store'), false)
        ->assertSee(route('admin.file-manager.picker.folders.store'), false)
        ->assertSee('name="image_archive_file_doc_num"', false)
        ->assertSee('assets/js/modules/Core/file-picker.js', false)
        ->assertSee(__('products.attributes.color'))
        ->assertSee(__('products.attributes.decal'))
        ->assertSee(__('products.attributes.origin_country'))
        ->assertSee(__('products.attributes.barcode'))
        ->assertSee(__('products.attributes.reorder_point'))
        ->assertSee(__('products.attributes.item_classification'))
        ->assertDontSee(__('products.classifications.'.Product::ClassificationRawMaterial))
        ->assertDontSee(__('products.classifications.'.Product::ClassificationPackaging))
        ->assertSee(__('products.tabs.components'))
        ->assertSee(__('products.components.helper'))
        ->assertSee(__('products.components.add'))
        ->assertSee(__('products.inline_lookup.add_color'))
        ->assertSee(__('products.inline_lookup.add_decal'))
        ->assertSee(__('products.inline_lookup.add_origin_country'))
        ->assertSee('product-components-table', false)
        ->assertSee('js-product-component-add-row', false)
        ->assertSee('components[__INDEX__][component_product_doc_num]', false)
        ->assertSee('data-template="product-image"', false)
        ->assertSee('components[__INDEX__][quantity]', false)
        ->assertDontSee('id="product-component-editor"', false)
        ->assertDontSee('js-product-component-submit', false)
        ->assertDontSee('js-product-component-reset', false)
        ->assertSee('name="reorder_point"', false)
        ->assertDontSee('name="'.$legacyClassifierField.'"', false)
        ->assertDontSee('id="reorder_point" name="reorder_point" type="number" min="0" step="0.0001" value="0"', false)
        ->assertSee('item_color_doc_num', false)
        ->assertSee('item_decal_doc_num', false)
        ->assertSee('item_origin_country_doc_num', false)
        ->assertDontSee('is_coolable', false)
        ->assertSee('assets/js/modules/Core/products.js', false);

    $formHtml = Str::before(Str::after($response->getContent(), '<form id="product-form"'), '</form>');
    $imageFieldHtml = Str::between($formHtml, '<div class="col-lg-4 product-image-field">', '<div class="col-lg-8 product-options-field">');

    expect($response->getContent())->toMatch('/<input[^>]*id="doc_number"[^>]*name="doc_number"[^>]*value=""[^>]*placeholder="'.preg_quote(__('products.document_number_control.placeholder'), '/').'"/');
    expect($imageFieldHtml)
        ->toContain(__('products.attributes.image'))
        ->not->toContain('aria-hidden="true">*</span>');

    expect($formHtml)
        ->toContain('name="image_archive_file_doc_num"')
        ->toContain('name="remove_image"')
        ->toContain('data-file-picker')
        ->not->toContain('data-picker-preview')
        ->toContain(__('products.image.select'))
        ->toContain(__('products.image.no_file_selected'))
        ->toContain('js-product-image-remove')
        ->toContain('product-image-preview-frame')
        ->toContain('object-fit-contain')
        ->toMatch('/<button[^>]*class="(?=[^"]*js-product-image-remove)(?=[^"]*d-none)[^"]*"/')
        ->not->toContain('type="file"')
        ->not->toContain('name="image"')
        ->not->toContain(__('products.image.upload'))
        ->not->toContain('js-company-logo-input');
});

test('inventory cost defaults on create and preserves submitted stored and cloned values', function () {
    $actor = productCrudActor(['products.create', 'products.edit', 'products.clone']);
    $checkbox = static fn (string $html): string => Str::match(
        '/<input[^>]*id="cost_as_inventory"[^>]*>/',
        $html,
    );

    $createHtml = $this->actingAs($actor)
        ->get(route('admin.products.create'))
        ->assertOk()
        ->getContent();

    expect($createHtml)->toContain('<input type="hidden" name="cost_as_inventory" value="0">')
        ->and($checkbox($createHtml))->toContain('checked');

    $failedUncheckedHtml = $this->actingAs($actor)
        ->withSession(['_old_input' => ['cost_as_inventory' => '0']])
        ->get(route('admin.products.create'))
        ->assertOk()
        ->getContent();

    expect($checkbox($failedUncheckedHtml))->not->toContain('checked');
    session()->forget('_old_input');

    $store = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Unchecked Inventory Cost Product',
            'cost_as_inventory' => '0',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);
    $product = Product::query()->where('doc_num', $store->json('data.doc_num'))->firstOrFail();

    expect($product->cost_as_inventory)->toBeFalse();

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Unchecked Inventory Cost Product',
            'cost_as_inventory' => '1',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($product->refresh()->cost_as_inventory)->toBeTrue();

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Unchecked Inventory Cost Product',
            'cost_as_inventory' => '0',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($product->refresh()->cost_as_inventory)->toBeFalse();

    $editHtml = $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->getContent();
    $cloneHtml = $this->actingAs($actor)
        ->get(route('admin.products.clone', $product->doc_num))
        ->assertOk()
        ->getContent();

    expect($checkbox($editHtml))->not->toContain('checked')
        ->and($checkbox($cloneHtml))->not->toContain('checked');
});

test('product create form marks image required only when product config requires it', function () {
    config()->set('products.image_required', true);
    $actor = productCrudActor(['products.create', 'file_manager.view']);

    $formHtml = $this->actingAs($actor)
        ->get(route('admin.products.create'))
        ->assertOk()
        ->getContent();
    $imageFieldHtml = Str::between($formHtml, '<div class="col-lg-4 product-image-field">', '<div class="col-lg-8 product-options-field">');

    expect($imageFieldHtml)
        ->toContain(__('products.attributes.image'))
        ->toContain('aria-hidden="true">*</span>');
});

test('product form shows remove image button when image exists or picker selection is present', function () {
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'products.edit', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('selected-form.jpg')->size(64));

    $createHtml = $this->actingAs($actor)
        ->withSession(['_old_input' => ['image_archive_file_doc_num' => $file->doc_num]])
        ->get(route('admin.products.create'))
        ->assertOk()
        ->getContent();
    $createFormHtml = Str::before(Str::after($createHtml, '<form id="product-form"'), '</form>');

    expect($createFormHtml)
        ->toContain(__('products.image.remove'))
        ->toContain(route('admin.file-manager.files.preview', $file->doc_num))
        ->toMatch('/<img[^>]*class="(?=[^"]*js-product-image-preview-image)(?![^"]*d-none)[^"]*"/')
        ->toMatch('/<span[^>]*class="(?=[^"]*js-product-image-placeholder)(?=[^"]*d-none)[^"]*"/')
        ->toMatch('/<button[^>]*class="(?=[^"]*js-product-image-remove)(?![^"]*d-none)[^"]*"/');

    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 39,
        'doc_num' => 'Product-00039',
        'name' => 'Existing Form Image Product',
        'image_path' => 'products/images/existing-form.jpg',
        'status' => 'active',
    ]);

    $editHtml = $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->getContent();
    $editFormHtml = Str::before(Str::after($editHtml, '<form id="product-form"'), '</form>');

    expect($editFormHtml)
        ->toContain(__('products.image.remove'))
        ->toContain('name="remove_image"')
        ->toMatch('/<img[^>]*class="(?=[^"]*js-product-image-preview-image)(?![^"]*d-none)[^"]*"/')
        ->toMatch('/<span[^>]*class="(?=[^"]*js-product-image-placeholder)(?=[^"]*d-none)[^"]*"/')
        ->toMatch('/<button[^>]*class="(?=[^"]*js-product-image-remove)(?![^"]*d-none)[^"]*"/');
});

test('products schema has new item data fields and no refrigeratable column', function () {
    $legacyClassifierField = 'product'.'_type';
    $imageColumn = collect(Schema::getColumns('products'))->firstWhere('name', 'image_path');

    expect(Schema::hasColumn('products', 'item_color_id'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'item_decal_id'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'item_origin_country_id'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'image_path'))->toBeTrue()
        ->and($imageColumn)->not->toBeNull()
        ->and((bool) ($imageColumn['nullable'] ?? false))->toBeTrue()
        ->and(Schema::hasColumn('products', 'barcode'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'reorder_point'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'item_classification'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'company_id'))->toBeTrue()
        ->and(Schema::hasColumn('products', $legacyClassifierField))->toBeFalse()
        ->and(Schema::hasColumn('products', 'is_coolable'))->toBeFalse();
});

test('authorized user can create product with generated document number and lookup doc nums', function () {
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);

    $unit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 11,
        'doc_num' => 'Unit-00011',
        'name' => 'Kilogram',
        'status' => 'active',
    ]);
    $category = ItemCategory::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 12,
        'doc_num' => 'Category-00012',
        'name' => 'Fruit',
        'status' => 'active',
    ]);
    $color = ItemColor::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 13,
        'doc_num' => 'Color-00013',
        'name' => 'Yellow',
        'status' => 'active',
    ]);
    $decal = ItemDecal::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 14,
        'doc_num' => 'Decal-00014',
        'name' => 'Oak Pattern',
        'status' => 'active',
    ]);
    $originCountry = ItemOriginCountry::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 15,
        'doc_num' => 'Origin-00015',
        'name' => 'Egypt',
        'status' => 'active',
    ]);

    $store = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'barcode' => 'MANGO-001',
            'item_classification' => Product::ClassificationSemiFinished,
            'reorder_point' => '12.5000',
            'item_unit_doc_num' => $unit->doc_num,
            'item_category_doc_num' => $category->doc_num,
            'item_color_doc_num' => $color->doc_num,
            'item_decal_doc_num' => $decal->doc_num,
            'item_origin_country_doc_num' => $originCountry->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    $product = Product::query()->where('doc_num', $store['data']['doc_num'])->firstOrFail();

    expect($product->doc_num)->toBe('Product-00001')
        ->and($product->item_unit_id)->toBe($unit->getKey())
        ->and($product->item_category_id)->toBe($category->getKey())
        ->and($product->item_color_id)->toBe($color->getKey())
        ->and($product->item_decal_id)->toBe($decal->getKey())
        ->and($product->item_origin_country_id)->toBe($originCountry->getKey())
        ->and($product->barcode)->toBe('MANGO-001')
        ->and($product->item_classification)->toBe(Product::ClassificationSemiFinished)
        ->and((string) $product->reorder_point)->toBe('12.5000')
        ->and($product->cost_as_inventory)->toBeTrue()
        ->and($product->is_displayable)->toBeFalse();
});

test('product create form leaves document number empty instead of previewing the next number', function () {
    $actor = productCrudActor(['products.create', 'products.document_number.control']);

    Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 1,
        'doc_num' => 'Product-00001',
        'name' => 'Existing Numbered Product',
        'status' => 'active',
    ]);

    $response = $this->actingAs($actor)
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertDontSee('Product-00002', false);

    expect($response->getContent())->toMatch('/<input[^>]*id="doc_number"[^>]*name="doc_number"[^>]*value=""[^>]*placeholder="'.preg_quote(__('products.document_number_control.placeholder'), '/').'"/');
});

test('product save and new does not return or render the next document number', function () {
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit', 'products.document_number.control']);

    Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 3,
        'doc_num' => 'Product-00003',
        'name' => 'Existing Save New Product',
        'status' => 'active',
    ]);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Saved And New Product',
            'doc_number' => '',
            'submit_action' => 'save',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true);

    expect($response->json())->not->toHaveKey('next_doc_number')
        ->and($response->json('data.doc_num'))->toBe('Product-00004');

    $createResponse = $this->actingAs($actor)
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertDontSee('Product-00005', false);

    expect($createResponse->getContent())->toMatch('/<input[^>]*id="doc_number"[^>]*name="doc_number"[^>]*value=""[^>]*placeholder="'.preg_quote(__('products.document_number_control.placeholder'), '/').'"/');
});

test('product can be created with a manual company scoped document number', function () {
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit', 'products.document_number.control']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Manual Number Product',
            'doc_number' => '777',
        ]))
        ->assertOk()
        ->assertJsonPath('data.doc_number', 777)
        ->assertJsonPath('data.doc_num', 'Product-00777');

    expect(Product::query()->where('name', 'Manual Number Product')->first()?->doc_number)->toBe(777);
});

test('product rejects duplicate manual document number inside the same company', function () {
    $actor = productCrudActor(['products.create', 'products.document_number.control']);

    Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 778,
        'doc_num' => 'Product-00778',
        'name' => 'Existing Manual Product',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Duplicate Manual Product',
            'doc_number' => '778',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['doc_number']);
});

test('product manual document number uniqueness is company scoped', function () {
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit', 'products.document_number.control']);

    Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 779,
        'doc_num' => 'Product-00779',
        'name' => 'Company A Manual Product',
        'status' => 'active',
    ]);

    $companyB = productOperatingContext($this);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Company B Manual Product',
            'doc_number' => '779',
        ]))
        ->assertOk()
        ->assertJsonPath('data.doc_number', 779)
        ->assertJsonPath('data.doc_num', 'Product-00779');

    expect(Product::query()->where('company_id', $companyB->getKey())->where('doc_number', 779)->where('name', 'Company B Manual Product')->exists())->toBeTrue();
});

test('product can be created without image when product image is optional', function () {
    config()->set('products.image_required', false);

    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload(['name' => 'No Image Product']))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($product->name)->toBe('No Image Product')
        ->and($product->image_path)->toBeNull()
        ->and(productMainImageUsage($product))->toBeNull();
});

test('product create fails without image when product image is required', function () {
    config()->set('products.image_required', true);
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload(['name' => 'Required No Image Product']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', __('products.validation.image_required'));

    expect(Product::query()->where('name', 'Required No Image Product')->exists())->toBeFalse();
});

test('product create fails when selected image is removed before save and image is required', function () {
    config()->set('products.image_required', true);
    $actor = productCrudActor(['products.create']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Removed Create Image Product',
            'remove_image' => '1',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', __('products.validation.image_required'));

    expect(Product::query()->where('name', 'Removed Create Image Product')->exists())->toBeFalse();
});

test('product can be created with valid selected image', function () {
    config()->set('products.image_required', true);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('product.jpg')->size(64));

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Image Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $usage = productMainImageUsage($product);

    expect($product->image_path)->toBe($file->path)
        ->and($usage)->not->toBeNull()
        ->and($usage?->archive_file_id)->toBe($file->getKey())
        ->and($usage?->collection)->toBe(Product::ImageCollection)
        ->and($usage?->role)->toBe(Product::MainImageRole)
        ->and($usage?->company_attachable_id)->toBe($this->productCompany->getKey());
    Storage::disk('local')->assertExists($product->image_path);
});

test('product can be created with selected file manager image', function () {
    config()->set('products.image_required', false);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('picked-product.jpg')->size(64));

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Picked Image Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $usage = productMainImageUsage($product);

    expect($product->image_path)->toBe($file->path)
        ->and($usage)->not->toBeNull()
        ->and($usage?->archive_file_id)->toBe($file->getKey())
        ->and($response->json('data.image_url'))->toBe(route('admin.products.image', $product->doc_num));

    $this->actingAs($actor)
        ->get(route('admin.products.image', $product->doc_num))
        ->assertOk();

    $table = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Picked Image Product'],
        ]))
        ->assertOk()
        ->json('data.0.image');

    expect($table)->toContain(route('admin.products.image', $product->doc_num));
});

test('product image validation accepts selected svg and bmp archive images', function () {
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit', 'file_manager.view']);

    foreach ([
        'SVG Image Product' => productSvgImage('product-vector.svg'),
        'BMP Image Product' => productBmpImage('product-bitmap.bmp'),
    ] as $productName => $uploadedFile) {
        $file = productArchiveFileForCompany($this->productCompany, $uploadedFile);

        $response = $this->actingAs($actor)
            ->postJson(route('admin.products.store'), productPayload([
                'name' => $productName,
                'image_archive_file_doc_num' => $file->doc_num,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

        expect(productMainImageUsage($product)?->archive_file_id)->toBe($file->getKey())
            ->and($product->image_path)->toBe($file->path);
    }
});

test('product image resolver and table prefer archive file usage over legacy image path', function () {
    Storage::fake('local');
    Storage::fake('public');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.view', 'file_manager.view']);
    Storage::disk('public')->put('products/images/legacy.jpg', 'legacy-image');
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('usage-main.jpg')->size(64));
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 37,
        'doc_num' => 'Product-00037',
        'name' => 'Usage Preferred Product',
        'image_path' => 'products/images/legacy.jpg',
        'status' => 'active',
    ]);

    app(ArchiveFileUsageService::class)->replaceFileForRecord($file, $product, Product::ImageCollection, Product::MainImageRole);

    expect(app(ProductImageResolver::class)->url($product->fresh()))->toBe(route('admin.products.image', $product->doc_num));

    $table = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Usage Preferred Product'],
        ]))
        ->assertOk()
        ->json('data.0.image');

    expect($table)
        ->toContain(route('admin.products.image', $product->doc_num))
        ->not->toContain('/storage/products/images/legacy.jpg');
});

test('missing archive product images render a placeholder instead of a broken image request', function () {
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.view', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('missing.jpg')->size(64));
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 39,
        'doc_num' => 'Product-00039',
        'name' => 'Missing Archive Image Product',
        'image_path' => $file->path,
        'status' => 'active',
    ]);

    Storage::disk('local')->delete($file->path);

    expect(app(ProductImageResolver::class)->url($product->fresh()))->toBeNull();

    $table = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Missing Archive Image Product'],
        ]))
        ->assertOk()
        ->json('data.0.image');

    expect($table)
        ->toContain(__('products.image.no_file_selected'))
        ->not->toContain(route('admin.products.image', $product->doc_num));
});

test('raw material select2 image url uses archive file usage relation', function () {
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('raw-usage.jpg')->size(64));
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 38,
        'doc_num' => 'Product-00038',
        'name' => 'Raw Usage Product',
        'item_classification' => Product::ClassificationRawMaterial,
        'status' => 'active',
    ]);

    app(ArchiveFileUsageService::class)->replaceFileForRecord($file, $product, Product::ImageCollection, Product::MainImageRole);

    $result = $this->actingAs($actor)
        ->getJson(route('admin.select2.raw-material-products', [
            'q' => 'Raw Usage Product',
        ]))
        ->assertOk()
        ->json('results.0');

    expect($result['imageUrl'] ?? null)->toBe(route('admin.products.image', $product->doc_num));
});

test('product create rejects selected non image file manager file', function () {
    config()->set('products.image_required', false);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->create('not-image.pdf', 16, 'application/pdf'));

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Rejected Non Image Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', __('products.validation.selected_file_not_image'));
});

test('product create rejects selected file manager image from another company', function () {
    config()->set('products.image_required', false);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'file_manager.view']);
    $companyA = $this->productCompany;
    $file = productArchiveFileForCompany($companyA, UploadedFile::fake()->image('company-a-image.jpg')->size(64));

    productOperatingContext($this);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Cross Company File Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', __('products.validation.selected_file_unavailable'));
});

test('product create rejects archive image hidden from picker', function () {
    config()->set('products.image_required', false);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('hidden-product.jpg')->size(64));

    $file->forceFill(['hidden_from_picker' => true])->save();

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Hidden Picker Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', __('products.validation.selected_file_hidden_from_picker'));
});

test('product create rejects selected file manager image missing from disk', function () {
    config()->set('products.image_required', false);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('missing-product.jpg')->size(64));

    Storage::disk('local')->delete($file->path);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Missing Picker File Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', __('products.validation.selected_file_unavailable'));

    expect(Product::query()->where('name', 'Missing Picker File Product')->exists())->toBeFalse();
});

test('existing product image still displays and validates after archive file is hidden from picker', function () {
    config()->set('products.image_required', true);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'products.edit', 'products.view', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('existing-hidden-product.jpg')->size(64));

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Existing Hidden Picker Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertOk();

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $file->forceFill(['hidden_from_picker' => true])->save();

    $this->actingAs($actor)
        ->get(route('admin.products.image', $product->doc_num))
        ->assertOk();

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Existing Hidden Picker Product Updated',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('direct product image upload does not satisfy required picker image validation', function () {
    config()->set('products.image_required', true);
    $actor = productCrudActor(['products.create']);

    $this->actingAs($actor)
        ->post(route('admin.products.store'), [
            ...productPayload(['name' => 'Invalid Image Product']),
            'image' => UploadedFile::fake()->create('product.pdf', 16, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', __('products.validation.image_required'));
});

test('oversized direct product image upload is ignored by picker-only validation', function () {
    config()->set('products.image_required', true);
    $actor = productCrudActor(['products.create']);
    $maxKib = (int) config('archive.logo.max_file_size_kib', 2048);

    $this->actingAs($actor)
        ->post(route('admin.products.store'), [
            ...productPayload(['name' => 'Oversized Image Product']),
            'image' => UploadedFile::fake()->image('product.jpg')->size($maxKib + 1),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', __('products.validation.image_required'));
});

test('product edit keeps existing image when no new image is uploaded', function () {
    config()->set('products.image_required', true);
    Storage::fake('public');
    $actor = productCrudActor(['products.edit']);
    Storage::disk('public')->put('products/images/existing.jpg', 'old-image');
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 31,
        'doc_num' => 'Product-00031',
        'name' => 'Existing Image Product',
        'image_path' => 'products/images/existing.jpg',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Existing Image Product Updated',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($product->refresh()->image_path)->toBe('products/images/existing.jpg');
    Storage::disk('public')->assertExists('products/images/existing.jpg');
});

test('product update accepts selected file manager image', function () {
    config()->set('products.image_required', true);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.edit', 'products.view', 'file_manager.view']);
    Storage::disk('public')->put('products/images/old-picked.jpg', 'old-image');
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('replacement-picked.jpg')->size(64));
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 36,
        'doc_num' => 'Product-00036',
        'name' => 'Update Picked Image Product',
        'image_path' => 'products/images/old-picked.jpg',
        'status' => 'active',
    ]);

    $response = $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Update Picked Image Product',
            'remove_image' => '1',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product->refresh();
    $usage = productMainImageUsage($product);

    expect($product->image_path)->toBe($file->path)
        ->and($usage)->not->toBeNull()
        ->and($usage?->archive_file_id)->toBe($file->getKey())
        ->and($response->json('data.image_url'))->toBe(route('admin.products.image', $product->doc_num));

    Storage::disk('public')->assertExists('products/images/old-picked.jpg');
});

test('product update accepts selected file manager image when product image is optional', function () {
    config()->set('products.image_required', false);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.edit', 'products.view', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('optional-replacement-picked.jpg')->size(64));
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 136,
        'doc_num' => 'Product-00136',
        'name' => 'Optional Update Picked Image Product',
        'status' => 'active',
    ]);

    $response = $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Optional Update Picked Image Product Updated',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product->refresh();
    $usage = productMainImageUsage($product);

    expect($product->image_path)->toBe($file->path)
        ->and($usage)->not->toBeNull()
        ->and($usage?->archive_file_id)->toBe($file->getKey())
        ->and($response->json('data.image_url'))->toBe(route('admin.products.image', $product->doc_num));
});

test('product update replaces active main image usage', function () {
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'products.edit', 'products.view', 'file_manager.view']);
    $initialFile = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('initial-main.jpg')->size(64));
    $replacementFile = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('replacement-main.jpg')->size(64));

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Replace Usage Product',
            'image_archive_file_doc_num' => $initialFile->doc_num,
        ]))
        ->assertOk();

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $oldUsage = productMainImageUsage($product);

    expect($oldUsage?->trashed())->toBeFalse();

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Replace Usage Product',
            'image_archive_file_doc_num' => $replacementFile->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $activeUsage = productMainImageUsage($product->refresh());
    $trashedOldUsage = ArchiveFileUsage::withTrashed()->whereKey($oldUsage?->getKey())->firstOrFail();

    expect($activeUsage?->archive_file_id)->toBe($replacementFile->getKey())
        ->and($trashedOldUsage->trashed())->toBeTrue()
        ->and($initialFile->refresh()->trashed())->toBeFalse()
        ->and($product->image_path)->toBe($replacementFile->path);
});

test('product update keeps existing active main image usage when no new image is selected', function () {
    config()->set('products.image_required', true);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'products.edit', 'products.view', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('kept-main.jpg')->size(64));

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Keep Usage Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertOk();

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $usage = productMainImageUsage($product);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Keep Usage Product Updated',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $activeUsage = productMainImageUsage($product->refresh());

    expect($activeUsage?->getKey())->toBe($usage?->getKey())
        ->and($activeUsage?->archive_file_id)->toBe($file->getKey())
        ->and(ArchiveFileUsage::withTrashed()->whereKey($usage?->getKey())->first()?->trashed())->toBeFalse();
});

test('product edit without image succeeds when product image is optional', function () {
    config()->set('products.image_required', false);

    $actor = productCrudActor(['products.edit']);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 134,
        'doc_num' => 'Product-00134',
        'name' => 'Optional Missing Existing Image Product',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Optional Missing Existing Image Product Updated',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($product->refresh()->name)->toBe('Optional Missing Existing Image Product Updated')
        ->and($product->image_path)->toBeNull();
});

test('product edit requires image when product has no existing image and image is required', function () {
    config()->set('products.image_required', true);
    $actor = productCrudActor(['products.edit']);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 34,
        'doc_num' => 'Product-00034',
        'name' => 'Missing Existing Image Product',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Missing Existing Image Product Updated',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image'])
        ->assertJsonPath('errors.image.0', __('products.validation.image_required'));
});

test('product edit clears existing image when product image is optional', function () {
    config()->set('products.image_required', false);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'products.edit', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('optional-clear.jpg')->size(64));

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Optional Clear Existing Usage Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertOk();

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $usage = productMainImageUsage($product);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Optional Clear Existing Usage Product Updated',
            'remove_image' => '1',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $usageAfterSave = ArchiveFileUsage::withTrashed()->whereKey($usage?->getKey())->firstOrFail();

    expect($product->refresh()->image_path)->toBeNull()
        ->and(productMainImageUsage($product))->toBeNull()
        ->and($usageAfterSave->trashed())->toBeTrue()
        ->and($file->refresh()->trashed())->toBeFalse();
});

test('product edit rejects removing existing image without replacement when image is required', function () {
    config()->set('products.image_required', true);
    $actor = productCrudActor(['products.edit']);
    Storage::disk('public')->put('products/images/remove.jpg', 'old-image');
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 35,
        'doc_num' => 'Product-00035',
        'name' => 'Remove Image Product',
        'image_path' => 'products/images/remove.jpg',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Remove Image Product',
            'remove_image' => '1',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);

    expect($product->refresh()->image_path)->toBe('products/images/remove.jpg');
    Storage::disk('public')->assertExists('products/images/remove.jpg');
});

test('failed product image removal does not delete archive file or detach existing usage', function () {
    config()->set('products.image_required', true);
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    $actor = productCrudActor(['products.create', 'products.edit', 'file_manager.view']);
    $file = productArchiveFileForCompany($this->productCompany, UploadedFile::fake()->image('remove-used.jpg')->size(64));

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Remove Existing Usage Product',
            'image_archive_file_doc_num' => $file->doc_num,
        ]))
        ->assertOk();

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $usage = productMainImageUsage($product);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Remove Existing Usage Product',
            'remove_image' => '1',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);

    $usageAfterFailedSave = ArchiveFileUsage::withTrashed()->whereKey($usage?->getKey())->firstOrFail();

    expect($file->refresh()->trashed())->toBeFalse()
        ->and($usageAfterFailedSave->trashed())->toBeFalse()
        ->and($product->refresh()->image_path)->toBe($file->path);
});

test('product edit page shows the saved document number', function () {
    $actor = productCrudActor(['products.edit', 'products.document_number.control']);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 909,
        'doc_num' => 'Product-00909',
        'name' => 'Editable Number Product',
        'status' => 'active',
    ]);

    $response = $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk();

    expect($response->getContent())->toMatch('/<input[^>]*id="doc_number"[^>]*name="doc_number"[^>]*value="909"/');
});

test('product edit form keeps required controls enabled and updates through method spoofed post', function () {
    $actor = productCrudActor(['products.edit']);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 911,
        'doc_num' => 'Product-00911',
        'name' => 'Editable Required Product',
        'image_path' => 'products/images/edit-required.jpg',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'inactive',
    ]);

    $html = $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->getContent();
    $formHtml = Str::before(Str::after($html, '<form id="product-form"'), '</form>');

    expect($formHtml)
        ->toContain('name="_method"')
        ->toContain('value="PUT"')
        ->toContain('data-submit-action="save"')
        ->not->toContain('name="product_type"')
        ->toMatch('/<input(?=[^>]*id="name")(?=[^>]*name="name")(?![^>]*disabled)[^>]*value="Editable Required Product"/')
        ->toMatch('/<select(?=[^>]*id="item_classification")(?=[^>]*name="item_classification")(?![^>]*disabled)[^>]*>/')
        ->toMatch('/<option value="'.preg_quote(Product::ClassificationFinishedProduct, '/').'" selected>/')
        ->toMatch('/<select(?=[^>]*id="status")(?=[^>]*name="status")(?![^>]*disabled)[^>]*>/')
        ->toMatch('/<option value="inactive" selected>/')
        ->toContain(__('products.image.select'))
        ->not->toContain('type="file"')
        ->not->toContain('name="image"');

    $this->actingAs($actor)
        ->post(route('admin.products.update', $product->doc_num), [
            ...productPayload([
                'name' => 'Editable Required Product Updated',
                'item_classification' => Product::ClassificationFinishedProduct,
                'status' => 'active',
            ]),
            '_method' => 'PUT',
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $product->refresh();

    expect($product->name)->toBe('Editable Required Product Updated')
        ->and($product->item_classification)->toBe(Product::ClassificationFinishedProduct)
        ->and($product->status)->toBe('active')
        ->and($product->image_path)->toBe('products/images/edit-required.jpg');
});

test('product form JavaScript submits multipart edits as post so Laravel can parse spoofed put fields', function () {
    $javascript = file_get_contents(public_path('assets/js/modules/Core/products.js'));

    expect($javascript)
        ->toContain('data: new FormData($form[0])')
        ->toContain('type: $form.attr(\'method\') || \'POST\'')
        ->toContain("$(this).closest('form')")
        ->toContain('file-picker:selected.coreProductsImagePicker')
        ->toContain('file.public_id || data.public_id')
        ->toContain('function renderProductImagePreview($field, options)')
        ->toContain('function clearProductImagePreview($field)')
        ->toContain('function handleProductImagePreviewError($field, fileName, hasImageValue)')
        ->toContain('renderProductImagePreview($field, {')
        ->toContain('file.thumbnail_url || data.thumbnail_url || file.url || data.url')
        ->toContain('objectFit: \'contain\'')
        ->toContain('click.coreProductsRemoveImage')
        ->toContain('file-picker:deleted.coreProductsImagePicker')
        ->toContain('handleProductImagePickerDeletion(payload)')
        ->toContain('clearProductImageSelection($(this))')
        ->toContain('setProductImageRemoveFlag($form, existingProductImageUrl($field) !== \'\')')
        ->toContain('$form.find(\'[name="image_archive_file_doc_num"]\').val(\'\');')
        ->not->toContain('setImagePreview($field, previewUrl, fileName)')
        ->not->toContain('type: $form.find(\'[name="_method"]\').val()')
        ->not->toContain('type: $form.find(\'[name=\'_method\']\').val()')
        ->not->toContain('js-company-logo-input')
        ->not->toContain('coreProductsImageInput');
});

test('file picker JavaScript emits reusable selected metadata and keeps upload generic', function () {
    $javascript = file_get_contents(public_path('assets/js/modules/Core/file-picker.js'));

    expect($javascript)
        ->toContain("state.\$trigger.trigger('file-picker:selected'")
        ->toContain('public_id: file.public_id')
        ->toContain('thumbnail_url: file.thumbnail_url')
        ->toContain('mime_type: file.mime_type')
        ->toContain("\$card.find('.js-file-picker-file').first().data('file', item);")
        ->toContain('file-picker:deleted')
        ->toContain('js-file-picker-delete-file')
        ->toContain('reloadFileManagerTables()')
        ->toContain('window.FilePickerUploader')
        ->not->toContain('product_image')
        ->not->toContain('ProductImage');
});

test('product clone form leaves document number automatic instead of copying the source', function () {
    $actor = productCrudActor(['products.clone', 'products.document_number.control']);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 910,
        'doc_num' => 'Product-00910',
        'name' => 'Clone Number Product',
        'status' => 'active',
    ]);

    $response = $this->actingAs($actor)
        ->get(route('admin.products.clone', $product->doc_num))
        ->assertOk()
        ->assertDontSee('value="910"', false);

    expect($response->getContent())->toMatch('/<input[^>]*id="doc_number"[^>]*name="doc_number"[^>]*value=""[^>]*placeholder="'.preg_quote(__('products.document_number_control.placeholder'), '/').'"/');
});

test('product clone token survives a transactional bom validation failure and is consumed after success', function () {
    $actor = productCrudActor(['products.clone']);
    $unit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 911,
        'doc_num' => 'Unit-00911',
        'name' => 'Kilogram',
        'status' => 'active',
    ]);
    $material = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 912,
        'doc_num' => 'RawMaterial-00912',
        'name' => 'Clone Token Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $source = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 913,
        'doc_num' => 'Product-00913',
        'name' => 'Clone Token Source',
        'status' => 'active',
    ]);
    $cloneHtml = $this->actingAs($actor)
        ->get(route('admin.products.clone', $source->doc_num))
        ->assertOk()
        ->getContent();
    $cloneToken = Str::match('/name="clone_source_token" value="([^"]+)"/', $cloneHtml);
    $componentKey = (string) Str::uuid();
    $component = [
        'public_id' => (string) Str::uuid(),
        'client_key' => $componentKey,
        'component_product_doc_num' => $material->doc_num,
        'unit_doc_num' => $unit->doc_num,
        'calculation_method' => ProductComponent::CalculationDirect,
        'quantity' => '1',
        'percentage' => '',
        'reference_component_key' => '',
        'input_source' => ProductComponent::InputWeight,
        'notes' => '',
        '_delete' => '0',
    ];

    expect($cloneToken)->not->toBe('');

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Rejected Clone Token Attempt',
            'submit_action' => 'save_new',
            'clone_source_token' => $cloneToken,
            'components' => [$component],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0.public_id']);

    expect(Product::query()->where('name', 'Rejected Clone Token Attempt')->exists())->toBeFalse();

    $component['public_id'] = '';
    $success = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Successful Clone Token Retry',
            'submit_action' => 'save_new',
            'clone_source_token' => $cloneToken,
            'components' => [$component],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Product::query()->where('doc_num', $success->json('data.doc_num'))->exists())->toBeTrue();

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Consumed Clone Token Attempt',
            'submit_action' => 'save_new',
            'clone_source_token' => $cloneToken,
            'components' => [$component],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('product clone form remaps percentage bom references to the cloned rows', function () {
    config()->set('products.image_required', false);
    $actor = productCrudActor(['products.clone']);
    $unit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 914,
        'doc_num' => 'Unit-00914',
        'name' => 'Kilogram',
        'status' => 'active',
    ]);
    $baseMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 915,
        'doc_num' => 'RawMaterial-00915',
        'name' => 'Clone Base Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $dependentMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 916,
        'doc_num' => 'RawMaterial-00916',
        'name' => 'Clone Dependent Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $source = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 917,
        'doc_num' => 'Product-00917',
        'name' => 'Percentage Clone Source',
        'cost_as_inventory' => false,
        'status' => 'active',
    ]);
    $sourceBase = ProductComponent::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'product_id' => $source->getKey(),
        'component_product_id' => $baseMaterial->getKey(),
        'unit_id' => $unit->getKey(),
        'calculation_method' => ProductComponent::CalculationDirect,
        'quantity' => '100',
    ]);
    $sourceDependent = ProductComponent::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'product_id' => $source->getKey(),
        'component_product_id' => $dependentMaterial->getKey(),
        'unit_id' => $unit->getKey(),
        'calculation_method' => ProductComponent::CalculationPercentage,
        'quantity' => '2',
        'percentage' => '2',
        'reference_component_id' => $sourceBase->getKey(),
    ]);
    $cloneHtml = $this->actingAs($actor)
        ->get(route('admin.products.clone', $source->doc_num))
        ->assertOk()
        ->getContent();
    $cloneToken = Str::match('/name="clone_source_token" value="([^"]+)"/', $cloneHtml);
    $initialRowsMatched = preg_match(
        '/window\.coreProductInitialComponents = (.*?);\s*window\.coreProductUnitConversionEdges =/s',
        $cloneHtml,
        $initialRowsMatches,
    );
    $initialRowsJson = $initialRowsMatches[1] ?? '';

    expect($initialRowsMatched)->toBe(1)
        ->and($initialRowsJson)->toBeJson();
    $initialRows = json_decode($initialRowsJson, true, flags: JSON_THROW_ON_ERROR);
    $baseRow = collect($initialRows)->firstWhere('component_product_doc_num', $baseMaterial->doc_num);
    $dependentRow = collect($initialRows)->firstWhere('component_product_doc_num', $dependentMaterial->doc_num);

    expect($baseRow)->toBeArray()
        ->and($dependentRow)->toBeArray()
        ->and($baseRow['public_id'])->toBe('')
        ->and($dependentRow['public_id'])->toBe('')
        ->and($baseRow['client_key'])->not->toBe($sourceBase->public_id)
        ->and($dependentRow['client_key'])->not->toBe($sourceDependent->public_id)
        ->and($dependentRow['reference_component_key'])->toBe($baseRow['client_key']);

    $submittedRows = collect($initialRows)
        ->map(fn (array $row): array => [
            'public_id' => $row['public_id'],
            'client_key' => $row['client_key'],
            'component_product_doc_num' => $row['component_product_doc_num'],
            'unit_doc_num' => $row['unit_doc_num'],
            'calculation_method' => $row['calculation_method'],
            'quantity' => $row['quantity_raw'],
            'percentage' => $row['percentage_raw'],
            'reference_component_key' => $row['reference_component_key'],
            'input_source' => $row['input_source'],
            'notes' => $row['notes'],
            '_delete' => '0',
        ])
        ->all();
    $store = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Percentage Clone Target',
            'cost_as_inventory' => '0',
            'submit_action' => 'save_new',
            'clone_source_token' => $cloneToken,
            'components' => $submittedRows,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);
    $target = Product::query()->where('doc_num', $store->json('data.doc_num'))->firstOrFail();
    $targetBase = ProductComponent::query()
        ->where('product_id', $target->getKey())
        ->where('component_product_id', $baseMaterial->getKey())
        ->firstOrFail();
    $targetDependent = ProductComponent::query()
        ->where('product_id', $target->getKey())
        ->where('component_product_id', $dependentMaterial->getKey())
        ->firstOrFail();

    expect($target->cost_as_inventory)->toBeFalse()
        ->and($targetBase->public_id)->not->toBe($sourceBase->public_id)
        ->and($targetDependent->public_id)->not->toBe($sourceDependent->public_id)
        ->and($targetDependent->reference_component_id)->toBe($targetBase->getKey())
        ->and($targetDependent->reference_component_id)->not->toBe($sourceBase->getKey())
        ->and((string) $targetDependent->percentage)->toBe('2.00000000')
        ->and((string) $targetDependent->quantity)->toBe('2.00000000');
});

test('legacy unitless direct bom rows round trip through product edit and clone', function () {
    config()->set('products.image_required', false);
    $actor = productCrudActor(['products.edit', 'products.clone']);
    $unitlessMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 918,
        'doc_num' => 'RawMaterial-00918',
        'name' => 'Legacy Unitless HTTP Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'status' => 'active',
    ]);
    $source = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 919,
        'doc_num' => 'Product-00919',
        'name' => 'Legacy Unitless HTTP Source',
        'status' => 'active',
    ]);
    $component = ProductComponent::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'product_id' => $source->getKey(),
        'component_product_id' => $unitlessMaterial->getKey(),
        'unit_id' => null,
        'calculation_method' => ProductComponent::CalculationDirect,
        'quantity' => '1',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.products.edit', $source->doc_num))
        ->assertOk()
        ->assertSee('"unit_doc_num":null', false);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $source->doc_num), productPayload([
            'name' => $source->name,
            'components' => [
                [
                    'public_id' => $component->public_id,
                    'client_key' => $component->public_id,
                    'component_product_doc_num' => $unitlessMaterial->doc_num,
                    'unit_doc_num' => '',
                    'calculation_method' => ProductComponent::CalculationDirect,
                    'quantity' => '2',
                    'percentage' => '',
                    'reference_component_key' => '',
                    'input_source' => ProductComponent::InputWeight,
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect((string) $component->refresh()->quantity)->toBe('2.00000000')
        ->and($component->unit_id)->toBeNull();

    $cloneHtml = $this->actingAs($actor)
        ->get(route('admin.products.clone', $source->doc_num))
        ->assertOk()
        ->assertSee('"unit_doc_num":null', false)
        ->getContent();
    $cloneToken = Str::match('/name="clone_source_token" value="([^"]+)"/', $cloneHtml);
    $initialRowsMatched = preg_match(
        '/window\.coreProductInitialComponents = (.*?);\s*window\.coreProductUnitConversionEdges =/s',
        $cloneHtml,
        $initialRowsMatches,
    );
    $initialRows = json_decode($initialRowsMatches[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
    $initialRow = $initialRows[0] ?? null;

    expect($cloneToken)->not->toBe('')
        ->and($initialRowsMatched)->toBe(1)
        ->and($initialRow)->toBeArray()
        ->and($initialRow['unit_doc_num'])->toBeNull();

    $clone = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Legacy Unitless HTTP Clone',
            'submit_action' => 'save_new',
            'clone_source_token' => $cloneToken,
            'components' => [
                [
                    'public_id' => $initialRow['public_id'],
                    'client_key' => $initialRow['client_key'],
                    'component_product_doc_num' => $initialRow['component_product_doc_num'],
                    'unit_doc_num' => '',
                    'calculation_method' => $initialRow['calculation_method'],
                    'quantity' => $initialRow['quantity_raw'],
                    'percentage' => '',
                    'reference_component_key' => '',
                    'input_source' => $initialRow['input_source'],
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);
    $target = Product::query()->where('doc_num', $clone->json('data.doc_num'))->firstOrFail();
    $clonedComponent = ProductComponent::query()
        ->where('product_id', $target->getKey())
        ->firstOrFail();

    expect($clonedComponent->public_id)->not->toBe($component->public_id)
        ->and($clonedComponent->component_product_id)->toBe($unitlessMaterial->getKey())
        ->and($clonedComponent->unit_id)->toBeNull()
        ->and($clonedComponent->calculation_method)->toBe(ProductComponent::CalculationDirect)
        ->and((string) $clonedComponent->quantity)->toBe('2.00000000');
});

test('product edit ignores direct uploaded image and keeps existing product image', function () {
    Storage::fake('public');
    $actor = productCrudActor(['products.edit']);
    Storage::disk('public')->put('products/images/old.jpg', 'old-image');
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 32,
        'doc_num' => 'Product-00032',
        'name' => 'Replace Image Product',
        'image_path' => 'products/images/old.jpg',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->post(route('admin.products.update', $product->doc_num), [
            ...productPayload([
                'name' => 'Replace Image Product Updated',
            ]),
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('new.png'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $product->refresh();

    expect($product->name)->toBe('Replace Image Product Updated')
        ->and($product->image_path)->toBe('products/images/old.jpg');
    Storage::disk('public')->assertExists('products/images/old.jpg');
});

test('product view page displays image preview safely and clone does not copy image preview', function () {
    Storage::fake('public');
    $actor = productCrudActor(['products.view', 'products.clone']);
    Storage::disk('public')->put('products/images/view.jpg', 'image');
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 33,
        'doc_num' => 'Product-00033',
        'name' => 'View Image Product',
        'image_path' => 'products/images/view.jpg',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.products.show', $product->doc_num))
        ->assertOk()
        ->assertSee(__('products.image.existing_file'))
        ->assertSee('/storage/products/images/view.jpg', false);

    $this->actingAs($actor)
        ->get(route('admin.products.clone', $product->doc_num))
        ->assertOk()
        ->assertSee(__('products.image.no_file_selected'))
        ->assertDontSee('/storage/products/images/view.jpg', false);
});

test('product can be created without optional relations or reorder point', function () {
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Product Without Optional Relations',
            'reorder_point' => '',
            'item_unit_doc_num' => null,
            'item_size_doc_num' => null,
            'item_model_doc_num' => null,
            'item_category_doc_num' => null,
            'item_group_doc_num' => null,
            'item_color_doc_num' => null,
            'item_decal_doc_num' => null,
            'item_origin_country_doc_num' => null,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('name', 'Product Without Optional Relations')->firstOrFail();

    expect($product->item_color_id)->toBeNull()
        ->and($product->reorder_point)->toBeNull();
});

test('product components can be submitted while creating product master', function () {
    config()->set('products.image_required', false);
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);
    $unit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 71,
        'doc_num' => 'Unit-00071',
        'name' => 'Meter',
        'status' => 'active',
    ]);
    $rawMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 71,
        'doc_num' => 'Product-00071',
        'name' => 'Raw Oak Board',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Assembled Oak Desk',
            'components' => [
                [
                    'component_product_doc_num' => $rawMaterial->doc_num,
                    'unit_doc_num' => $unit->doc_num,
                    'quantity' => '2.5000',
                    'notes' => 'Desktop board',
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('name', 'Assembled Oak Desk')->firstOrFail();
    $component = ProductComponent::query()->where('product_id', $product->getKey())->firstOrFail();

    expect($component->company_id)->toBe($this->productCompany->getKey())
        ->and($component->component_product_id)->toBe($rawMaterial->getKey())
        ->and($component->unit_id)->toBe($unit->getKey())
        ->and((string) $component->quantity)->toBe('2.50000000')
        ->and($component->notes)->toBe('Desktop board');
});

test('product create accepts decimal quantity and rejects fractional count component values', function () {
    config()->set('products.image_required', false);
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);
    $unit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 713,
        'doc_num' => 'Unit-00713',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $quantityMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 712,
        'doc_num' => 'RawMaterial-00712',
        'name' => 'Quantity HTTP Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $countMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 713,
        'doc_num' => 'Packaging-00713',
        'name' => 'Count HTTP Material',
        'item_classification' => Product::ClassificationPackaging,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);

    $valid = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Quantity Count HTTP Product',
            'components' => [
                [
                    'client_key' => (string) Str::uuid(),
                    'component_product_doc_num' => $quantityMaterial->doc_num,
                    'unit_doc_num' => $unit->doc_num,
                    'calculation_method' => ProductComponent::CalculationQuantity,
                    'quantity' => '1.25000001',
                    'reference_component_key' => (string) Str::uuid(),
                ],
                [
                    'client_key' => (string) Str::uuid(),
                    'component_product_doc_num' => $countMaterial->doc_num,
                    'unit_doc_num' => $unit->doc_num,
                    'calculation_method' => ProductComponent::CalculationCount,
                    'quantity' => '3',
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('doc_num', $valid->json('data.doc_num'))->firstOrFail();
    $components = ProductComponent::query()
        ->where('product_id', $product->getKey())
        ->get()
        ->keyBy('calculation_method');

    expect((string) $components[ProductComponent::CalculationQuantity]->quantity)->toBe('1.25000001')
        ->and($components[ProductComponent::CalculationQuantity]->reference_component_id)->toBeNull()
        ->and((string) $components[ProductComponent::CalculationCount]->quantity)->toBe('3.00000000');

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Rejected Fractional Count Product',
            'components' => [[
                'client_key' => (string) Str::uuid(),
                'component_product_doc_num' => $countMaterial->doc_num,
                'unit_doc_num' => $unit->doc_num,
                'calculation_method' => ProductComponent::CalculationCount,
                'quantity' => '1.5',
            ]],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0.quantity']);

    expect(Product::query()->where('name', 'Rejected Fractional Count Product')->exists())->toBeFalse();
});

test('all bom methods save reload and recalculate from the reference effective weight', function () {
    config()->set('products.image_required', false);
    $actor = productCrudActor(['products.create', 'products.edit', 'products.view']);
    $kilogram = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 714,
        'doc_num' => 'Unit-00714',
        'name' => 'Kilogram',
        'status' => 'active',
    ]);
    $quantityPack = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 715,
        'doc_num' => 'Unit-00715',
        'name' => 'Quantity Pack',
        'equivalent_value' => '0.5',
        'equivalent_unit_id' => $kilogram->getKey(),
        'status' => 'active',
    ]);
    $countPiece = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 716,
        'doc_num' => 'Unit-00716',
        'name' => 'Count Piece',
        'equivalent_value' => '0.25',
        'equivalent_unit_id' => $kilogram->getKey(),
        'status' => 'active',
    ]);
    $baseMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 714,
        'doc_num' => 'RawMaterial-00714',
        'name' => 'HTTP Base Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $kilogram->getKey(),
        'status' => 'active',
    ]);
    $dependentMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 715,
        'doc_num' => 'RawMaterial-00715',
        'name' => 'HTTP Dependent Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $kilogram->getKey(),
        'status' => 'active',
    ]);
    $quantityMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 716,
        'doc_num' => 'RawMaterial-00716',
        'name' => 'HTTP Quantity Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $quantityPack->getKey(),
        'status' => 'active',
    ]);
    $countMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 717,
        'doc_num' => 'RawMaterial-00717',
        'name' => 'HTTP Count Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $countPiece->getKey(),
        'status' => 'active',
    ]);
    $baseClientKey = (string) Str::uuid();
    $dependentClientKey = (string) Str::uuid();
    $quantityClientKey = (string) Str::uuid();
    $countClientKey = (string) Str::uuid();

    $store = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'HTTP Percentage BOM Product',
            'components' => [
                [
                    'client_key' => $baseClientKey,
                    'component_product_doc_num' => $baseMaterial->doc_num,
                    'unit_doc_num' => $kilogram->doc_num,
                    'calculation_method' => ProductComponent::CalculationDirect,
                    'quantity' => '130',
                    'input_source' => ProductComponent::InputWeight,
                ],
                [
                    'client_key' => $dependentClientKey,
                    'component_product_doc_num' => $dependentMaterial->doc_num,
                    'unit_doc_num' => $kilogram->doc_num,
                    'calculation_method' => ProductComponent::CalculationPercentage,
                    'quantity' => '2',
                    'reference_component_key' => $baseClientKey,
                    'input_source' => ProductComponent::InputPercentage,
                ],
                [
                    'client_key' => $quantityClientKey,
                    'component_product_doc_num' => $quantityMaterial->doc_num,
                    'unit_doc_num' => $quantityPack->doc_num,
                    'calculation_method' => ProductComponent::CalculationQuantity,
                    'quantity' => '3.5',
                    'input_source' => ProductComponent::InputWeight,
                ],
                [
                    'client_key' => $countClientKey,
                    'component_product_doc_num' => $countMaterial->doc_num,
                    'unit_doc_num' => $countPiece->doc_num,
                    'calculation_method' => ProductComponent::CalculationCount,
                    'quantity' => '4',
                    'input_source' => ProductComponent::InputWeight,
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('doc_num', $store->json('data.doc_num'))->firstOrFail();
    $components = ProductComponent::query()
        ->where('product_id', $product->getKey())
        ->with(['componentProduct', 'unit'])
        ->get()
        ->keyBy('calculation_method');
    $base = $components[ProductComponent::CalculationDirect];
    $dependent = $components[ProductComponent::CalculationPercentage];
    $quantity = $components[ProductComponent::CalculationQuantity];
    $count = $components[ProductComponent::CalculationCount];
    $totalInKilograms = function () use ($product, $baseMaterial, $kilogram): string {
        $conversions = app(ProductComponentUnitConversionService::class);

        return (string) ProductComponent::query()
            ->where('product_id', $product->getKey())
            ->with(['componentProduct', 'unit'])
            ->get()
            ->reduce(function (BigDecimal $total, ProductComponent $component) use ($conversions, $baseMaterial, $kilogram): BigDecimal {
                $converted = $conversions->convert(
                    $component->quantity,
                    $component->componentProduct,
                    $component->unit,
                    $baseMaterial,
                    $kilogram,
                    8,
                );

                return $total->plus($converted);
            }, BigDecimal::zero())
            ->toScale(8);
    };

    expect((string) $base->quantity)->toBe('130.00000000')
        ->and((string) $dependent->quantity)->toBe('2.60000000')
        ->and((string) $dependent->percentage)->toBe('2.00000000')
        ->and($dependent->reference_component_id)->toBe($base->getKey())
        ->and((string) $quantity->quantity)->toBe('3.50000000')
        ->and((string) $count->quantity)->toBe('4.00000000')
        ->and($totalInKilograms())->toBe('135.35000000');

    $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->assertSee('"calculation_method":"percentage"', false)
        ->assertSee('"quantity_raw":"2.60000000"', false)
        ->assertSee('"percentage_raw":"2.00000000"', false)
        ->assertSee('"calculation_method":"quantity"', false)
        ->assertSee('"calculation_method":"count"', false);

    $this->actingAs($actor)
        ->get(route('admin.products.show', $product->doc_num))
        ->assertOk()
        ->assertSee('"reference_component_key":"'.$base->public_id.'"', false)
        ->assertSee($baseMaterial->name)
        ->assertSee($dependentMaterial->name);

    $javascript = file_get_contents(public_path('assets/js/modules/Core/products.js'));

    expect($javascript)
        ->toContain('var referenceUnitWeight = decimalMultiply(referenceResult.quantity, percentageRatio, componentWorkingScale)')
        ->toContain("if (targetUnit !== '')")
        ->toContain('reference_weight: formatDecimal(referenceResult.quantity)')
        ->not->toContain('convertedReferenceWeight');

    $updateComponents = fn (string $weight, string $percentage): array => [
        [
            'public_id' => $base->public_id,
            'client_key' => $base->public_id,
            'component_product_doc_num' => $baseMaterial->doc_num,
            'unit_doc_num' => $kilogram->doc_num,
            'calculation_method' => ProductComponent::CalculationDirect,
            'quantity' => $weight,
            'input_source' => ProductComponent::InputWeight,
        ],
        [
            'public_id' => $dependent->public_id,
            'client_key' => $dependent->public_id,
            'component_product_doc_num' => $dependentMaterial->doc_num,
            'unit_doc_num' => $kilogram->doc_num,
            'calculation_method' => ProductComponent::CalculationPercentage,
            'quantity' => $percentage,
            'reference_component_key' => $base->public_id,
            'input_source' => ProductComponent::InputPercentage,
        ],
        [
            'public_id' => $quantity->public_id,
            'client_key' => $quantity->public_id,
            'component_product_doc_num' => $quantityMaterial->doc_num,
            'unit_doc_num' => $quantityPack->doc_num,
            'calculation_method' => ProductComponent::CalculationQuantity,
            'quantity' => '3.5',
            'input_source' => ProductComponent::InputWeight,
        ],
        [
            'public_id' => $count->public_id,
            'client_key' => $count->public_id,
            'component_product_doc_num' => $countMaterial->doc_num,
            'unit_doc_num' => $countPiece->doc_num,
            'calculation_method' => ProductComponent::CalculationCount,
            'quantity' => '4',
            'input_source' => ProductComponent::InputWeight,
        ],
    ];

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => $product->name,
            'components' => $updateComponents('200', '2'),
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect((string) $base->refresh()->quantity)->toBe('200.00000000')
        ->and((string) $dependent->refresh()->quantity)->toBe('4.00000000')
        ->and((string) $dependent->percentage)->toBe('2.00000000')
        ->and($dependent->reference_component_id)->toBe($base->getKey())
        ->and($totalInKilograms())->toBe('206.75000000');

    $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->assertSee('"quantity_raw":"4.00000000"', false)
        ->assertSee('"percentage_raw":"2.00000000"', false);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => $product->name,
            'components' => $updateComponents('200', '5'),
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect((string) $dependent->refresh()->quantity)->toBe('10.00000000')
        ->and((string) $dependent->percentage)->toBe('5.00000000')
        ->and($totalInKilograms())->toBe('212.75000000');

    $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->assertSee('"quantity_raw":"10.00000000"', false)
        ->assertSee('"percentage_raw":"5.00000000"', false)
        ->assertSee('"reference_component_key":"'.$base->public_id.'"', false);
});

test('empty submitted product component rows are ignored', function () {
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Empty Component Rows Product',
            'components' => [
                [
                    'component_product_doc_num' => '',
                    'quantity' => '',
                    'notes' => '',
                ],
                [],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('name', 'Empty Component Rows Product')->firstOrFail();

    expect(ProductComponent::query()->where('product_id', $product->getKey())->exists())->toBeFalse();

    $nullStore = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Null Component Collection Product',
            'components' => null,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);
    $nullStoreProduct = Product::query()
        ->where('doc_num', $nullStore->json('data.doc_num'))
        ->firstOrFail();

    expect(ProductComponent::query()
        ->where('product_id', $nullStoreProduct->getKey())
        ->exists())->toBeFalse();

    $material = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 920,
        'doc_num' => 'RawMaterial-00920',
        'name' => 'Null BOM Payload Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'status' => 'active',
    ]);
    $component = ProductComponent::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'product_id' => $product->getKey(),
        'component_product_id' => $material->getKey(),
        'unit_id' => null,
        'quantity' => '1',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => $product->name,
            'components' => null,
        ]))
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    expect(ProductComponent::query()->whereKey($component->getKey())->exists())->toBeTrue();
});

test('partially filled product component rows validate on product save', function () {
    $actor = productCrudActor(['products.create']);
    $rawMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 78,
        'doc_num' => 'Product-00078',
        'name' => 'Raw Missing Quantity',
        'item_classification' => Product::ClassificationRawMaterial,
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Missing Component Material',
            'components' => [
                [
                    'quantity' => '1',
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0.component_product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Missing Component Quantity',
            'components' => [
                [
                    'component_product_doc_num' => $rawMaterial->doc_num,
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0.quantity']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Incomplete Percentage Component',
            'components' => [
                [
                    'calculation_method' => ProductComponent::CalculationPercentage,
                    'input_source' => ProductComponent::InputWeight,
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'components.0.component_product_doc_num',
            'components.0.quantity',
            'components.0.reference_component_key',
        ]);

    expect(Product::query()
        ->whereIn('name', [
            'Missing Component Material',
            'Missing Component Quantity',
            'Incomplete Percentage Component',
        ])
        ->exists())->toBeFalse();
});

test('product bom requests require compatible units without rejecting unitless direct rows', function () {
    config()->set('products.image_required', false);
    $actor = productCrudActor(['products.create']);
    $unit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 921,
        'doc_num' => 'Unit-00921',
        'name' => 'Kilogram',
        'status' => 'active',
    ]);
    $otherUnit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 922,
        'doc_num' => 'Unit-00922',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $unitBearingMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 923,
        'doc_num' => 'RawMaterial-00923',
        'name' => 'Nested Unit Bearing Material',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $unitlessMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 924,
        'doc_num' => 'RawMaterial-00924',
        'name' => 'Nested Unitless Material',
        'item_classification' => Product::ClassificationPackaging,
        'status' => 'active',
    ]);

    foreach (['' => 'unit_required', $otherUnit->doc_num => 'invalid_unit'] as $submittedUnit => $case) {
        $this->actingAs($actor)
            ->postJson(route('admin.products.store'), productPayload([
                'name' => "Rejected Nested Direct Unit {$case}",
                'components' => [
                    [
                        'client_key' => (string) Str::uuid(),
                        'component_product_doc_num' => $unitBearingMaterial->doc_num,
                        'unit_doc_num' => $submittedUnit,
                        'calculation_method' => ProductComponent::CalculationDirect,
                        'quantity' => '1',
                    ],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['components.0.unit_doc_num']);
    }

    $baseKey = (string) Str::uuid();
    $dependentKey = (string) Str::uuid();

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Rejected Nested Percentage Without Unit',
            'components' => [
                [
                    'client_key' => $baseKey,
                    'component_product_doc_num' => $unitlessMaterial->doc_num,
                    'unit_doc_num' => '',
                    'calculation_method' => ProductComponent::CalculationDirect,
                    'quantity' => '100',
                    'input_source' => ProductComponent::InputWeight,
                ],
                [
                    'client_key' => $dependentKey,
                    'component_product_doc_num' => $unitlessMaterial->doc_num,
                    'unit_doc_num' => '',
                    'calculation_method' => ProductComponent::CalculationPercentage,
                    'percentage' => '2',
                    'reference_component_key' => $baseKey,
                    'input_source' => ProductComponent::InputPercentage,
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.1.unit_doc_num']);

    $forgedBaseKey = (string) Str::uuid();

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Rejected Nested Forged Input Source',
            'components' => [
                [
                    'client_key' => $forgedBaseKey,
                    'component_product_doc_num' => $unitBearingMaterial->doc_num,
                    'unit_doc_num' => $unit->doc_num,
                    'calculation_method' => ProductComponent::CalculationDirect,
                    'quantity' => '100',
                    'input_source' => ProductComponent::InputWeight,
                ],
                [
                    'client_key' => (string) Str::uuid(),
                    'component_product_doc_num' => $unitBearingMaterial->doc_num,
                    'unit_doc_num' => $unit->doc_num,
                    'calculation_method' => ProductComponent::CalculationPercentage,
                    'percentage' => '2',
                    'reference_component_key' => $forgedBaseKey,
                    'input_source' => ['forged-source'],
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.1.input_source']);

    expect(Product::query()
        ->whereIn('name', [
            'Rejected Nested Direct Unit unit_required',
            'Rejected Nested Direct Unit invalid_unit',
            'Rejected Nested Percentage Without Unit',
            'Rejected Nested Forged Input Source',
        ])
        ->exists())->toBeFalse();
});

test('invalid submitted product components reject master creation', function () {
    $actor = productCrudActor(['products.create']);
    $nonComponentProduct = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 72,
        'doc_num' => 'Product-00072',
        'name' => 'Freight Service',
        'item_classification' => Product::ClassificationService,
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Invalid Component Master',
            'components' => [
                [
                    'component_product_doc_num' => $nonComponentProduct->doc_num,
                    'quantity' => '1',
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0.component_product_doc_num']);

    expect(Product::query()->where('name', 'Invalid Component Master')->exists())->toBeFalse();
});

test('duplicate submitted product component rows remain distinct bom lines', function () {
    $actor = productCrudActor(['products.create', 'products.edit']);
    $unit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 73,
        'doc_num' => 'Unit-00073',
        'name' => 'Kilogram',
        'status' => 'active',
    ]);
    $rawMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 73,
        'doc_num' => 'Product-00073',
        'name' => 'Raw Hinge',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Duplicate Component Master',
            'components' => [
                [
                    'component_product_doc_num' => $rawMaterial->doc_num,
                    'unit_doc_num' => $unit->doc_num,
                    'quantity' => '1',
                ],
                [
                    'component_product_doc_num' => $rawMaterial->doc_num,
                    'unit_doc_num' => $unit->doc_num,
                    'quantity' => '2',
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($product->components)->toHaveCount(2)
        ->and($product->components->pluck('component_product_id')->unique()->all())->toBe([$rawMaterial->getKey()])
        ->and($product->components->pluck('quantity')->all())->toBe(['1.00000000', '2.00000000']);
});

test('existing product components can be updated and deleted from product edit save', function () {
    $actor = productCrudActor(['products.edit']);
    $unit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 74,
        'doc_num' => 'Unit-00074',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 74,
        'doc_num' => 'Product-00074',
        'name' => 'Component Edit Master',
        'image_path' => 'products/images/component-edit.jpg',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);
    $rawOne = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 75,
        'doc_num' => 'Product-00075',
        'name' => 'Raw Screw',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $rawTwo = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 76,
        'doc_num' => 'Product-00076',
        'name' => 'Raw Bracket',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $componentToUpdate = ProductComponent::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'product_id' => $product->getKey(),
        'component_product_id' => $rawOne->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '1.0000',
    ]);
    $componentToDelete = ProductComponent::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'product_id' => $product->getKey(),
        'component_product_id' => $rawTwo->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '2.0000',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Component Edit Master',
            'item_classification' => Product::ClassificationFinishedProduct,
            'components' => [
                [
                    'public_id' => $componentToUpdate->public_id,
                    'component_product_doc_num' => $rawOne->doc_num,
                    'unit_doc_num' => $unit->doc_num,
                    'quantity' => '4.2500',
                    'notes' => 'Adjusted quantity',
                ],
                [
                    'public_id' => $componentToDelete->public_id,
                    '_delete' => '1',
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect((string) $componentToUpdate->refresh()->quantity)->toBe('4.25000000')
        ->and($componentToUpdate->notes)->toBe('Adjusted quantity')
        ->and(ProductComponent::withTrashed()->where('public_id', $componentToDelete->public_id)->first()?->trashed())->toBeTrue();
});

test('submitted product components are scoped to the operating company', function () {
    $actor = productCrudActor(['products.create']);
    $companyA = $this->productCompany;
    $rawMaterial = Product::query()->create([
        'company_id' => $companyA->getKey(),
        'doc_number' => 77,
        'doc_num' => 'Product-00077',
        'name' => 'Company A Raw Foam',
        'item_classification' => Product::ClassificationRawMaterial,
        'status' => 'active',
    ]);

    productOperatingContext($this);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Cross Company Component Master',
            'components' => [
                [
                    'component_product_doc_num' => $rawMaterial->doc_num,
                    'quantity' => '1',
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0.component_product_doc_num']);

    expect(Product::query()->where('name', 'Cross Company Component Master')->exists())->toBeFalse();
});

test('product color can be changed cleared and participates in no change detection', function () {
    $actor = productCrudActor(['products.edit']);
    $red = ItemColor::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 21,
        'doc_num' => 'Color-00021',
        'name' => 'Red',
        'status' => 'active',
    ]);
    $blue = ItemColor::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 22,
        'doc_num' => 'Color-00022',
        'name' => 'Blue',
        'status' => 'active',
    ]);
    $classicDecal = ItemDecal::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 23,
        'doc_num' => 'Decal-00023',
        'name' => 'Classic Pattern',
        'status' => 'active',
    ]);
    $modernDecal = ItemDecal::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 24,
        'doc_num' => 'Decal-00024',
        'name' => 'Modern Pattern',
        'status' => 'active',
    ]);
    $egypt = ItemOriginCountry::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 25,
        'doc_num' => 'Origin-00025',
        'name' => 'Egypt',
        'status' => 'active',
    ]);
    $italy = ItemOriginCountry::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 26,
        'doc_num' => 'Origin-00026',
        'name' => 'Italy',
        'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 41,
        'doc_num' => 'Product-00041',
        'name' => 'Color Product',
        'image_path' => 'products/images/color.jpg',
        'item_color_id' => $red->getKey(),
        'item_decal_id' => $classicDecal->getKey(),
        'item_origin_country_id' => $egypt->getKey(),
        'cost_as_inventory' => false,
        'is_displayable' => true,
        'status' => 'active',
        'notes' => 'Color unchanged',
    ]);

    $samePayload = productPayload([
        'name' => 'Color Product',
        'item_color_doc_num' => $red->doc_num,
        'item_decal_doc_num' => $classicDecal->doc_num,
        'item_origin_country_doc_num' => $egypt->doc_num,
        'cost_as_inventory' => '0',
        'is_displayable' => '1',
        'notes' => 'Color unchanged',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), $samePayload)
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('type', 'no_changes');

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), [
            ...$samePayload,
            'item_color_doc_num' => $blue->doc_num,
            'item_decal_doc_num' => $modernDecal->doc_num,
            'item_origin_country_doc_num' => $italy->doc_num,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $activityProperties = Activity::query()
        ->where('action', 'products.update')
        ->latest('id')
        ->firstOrFail()
        ->properties
        ->toArray();

    expect($product->refresh()->item_color_id)->toBe($blue->getKey())
        ->and($product->item_decal_id)->toBe($modernDecal->getKey())
        ->and($product->item_origin_country_id)->toBe($italy->getKey())
        ->and(data_get($activityProperties, 'changes.color.old'))->toContain('Color-00021')
        ->and(data_get($activityProperties, 'changes.color.new'))->toContain('Color-00022')
        ->and(data_get($activityProperties, 'changes.decal.old'))->toContain('Decal-00023')
        ->and(data_get($activityProperties, 'changes.decal.new'))->toContain('Decal-00024')
        ->and(data_get($activityProperties, 'changes.origin_country.old'))->toContain('Origin-00025')
        ->and(data_get($activityProperties, 'changes.origin_country.new'))->toContain('Origin-00026')
        ->and(data_get($activityProperties, 'changes.item_color_id'))->toBeNull()
        ->and(data_get($activityProperties, 'changes.item_decal_id'))->toBeNull()
        ->and(data_get($activityProperties, 'changes.item_origin_country_id'))->toBeNull();

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), [
            ...$samePayload,
            'item_color_doc_num' => null,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($product->refresh()->item_color_id)->toBeNull();
});

test('product color validation rejects missing and deleted colors', function () {
    $actor = productCrudActor(['products.create']);
    $deletedColor = ItemColor::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 23,
        'doc_num' => 'Color-00023',
        'name' => 'Archived Color',
        'status' => 'active',
    ]);
    $deletedColor->delete();

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Missing Color Product',
            'item_color_doc_num' => 'Color-Missing',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['item_color_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Deleted Color Product',
            'item_color_doc_num' => $deletedColor->doc_num,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['item_color_doc_num']);
});

test('product decal and origin country validation reject cross company and deleted lookups', function () {
    $actor = productCrudActor(['products.create']);
    $companyA = $this->productCompany;
    $decalA = ItemDecal::query()->create([
        'company_id' => $companyA->getKey(),
        'doc_number' => 60,
        'doc_num' => 'Decal-00060',
        'name' => 'Company A Pattern',
        'status' => 'active',
    ]);
    $originA = ItemOriginCountry::query()->create([
        'company_id' => $companyA->getKey(),
        'doc_number' => 61,
        'doc_num' => 'Origin-00061',
        'name' => 'Company A Origin',
        'status' => 'active',
    ]);
    $deletedDecal = ItemDecal::query()->create([
        'company_id' => $companyA->getKey(),
        'doc_number' => 62,
        'doc_num' => 'Decal-00062',
        'name' => 'Deleted Pattern',
        'status' => 'active',
    ]);
    $deletedDecal->delete();

    productOperatingContext($this);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Cross Company Decal Product',
            'item_decal_doc_num' => $decalA->doc_num,
            'item_origin_country_doc_num' => $originA->doc_num,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['item_decal_doc_num', 'item_origin_country_doc_num']);

    $this->withSession([
        OperatingContextService::CompanyIdKey => $companyA->getKey(),
        OperatingContextService::CompanyDocNumKey => $companyA->doc_num,
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Deleted Decal Product',
            'item_decal_doc_num' => $deletedDecal->doc_num,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['item_decal_doc_num']);
});

test('product barcode reorder point and item classification are validated', function () {
    $actor = productCrudActor(['products.create', 'products.edit']);

    Storage::disk('public')->put('products/images/display.jpg', 'display image');

    Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 63,
        'doc_num' => 'Product-00063',
        'name' => 'Barcode Product',
        'item_classification' => Product::ClassificationFinishedProduct,
        'barcode' => 'UNIQUE-BC',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Duplicate Barcode Product',
            'barcode' => 'UNIQUE-BC',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['barcode']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Negative Reorder Product',
            'reorder_point' => '-1',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reorder_point']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Invalid Classification Product',
            'item_classification' => 'invalid',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['item_classification']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Nullable Barcode Product',
            'barcode' => '',
            'reorder_point' => '5.2500',
            'item_classification' => Product::ClassificationOther,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('name', 'Nullable Barcode Product')->firstOrFail();

    expect($product->barcode)->toBeNull()
        ->and((string) $product->reorder_point)->toBe('5.2500')
        ->and($product->item_classification)->toBe(Product::ClassificationOther);
});

test('product numeric input accepts valid grouping, rejects malformed grouping, and persists canonical decimals', function () {
    $actor = productCrudActor(['products.create', 'products.edit']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Grouped Reorder Product',
            'barcode' => '000125050',
            'reorder_point' => '1,250.5',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect((string) $product->reorder_point)->toBe('1250.5000')
        ->and($product->barcode)->toBe('000125050');

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Malformed Grouping Product',
            'reorder_point' => '1,2,3',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reorder_point']);
});

test('product numeric presentation is consistent across form modes and both layout directions', function () {
    $actor = productCrudActor([
        'products.view',
        'products.create',
        'products.edit',
        'products.clone',
    ]);
    $baseUnit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 881,
        'doc_num' => 'Unit-00881',
        'name' => 'Carton',
        'status' => 'active',
    ]);
    $equivalentUnit = ItemUnit::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 882,
        'doc_num' => 'Unit-00882',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 883,
        'doc_num' => 'Product-00883',
        'name' => 'Directional Numeric Product',
        'item_classification' => Product::ClassificationFinishedProduct,
        'barcode' => '000125050',
        'reorder_point' => '1250.5000',
        'item_unit_id' => $baseUnit->getKey(),
        'equivalent_value' => '0.000458',
        'equivalent_unit_id' => $equivalentUnit->getKey(),
        'status' => 'active',
    ]);

    $actor->forceFill(['locale' => 'en'])->save();

    foreach (['edit', 'show', 'clone'] as $action) {
        $this->actingAs($actor)
            ->get(route("admin.products.{$action}", $product->doc_num))
            ->assertOk()
            ->assertSee('lang="en" dir="ltr"', false)
            ->assertSee('value="1,250.5"', false)
            ->assertSee('value="0.000458"', false)
            ->assertSee('value="000125050"', false);
    }

    $this->actingAs($actor)
        ->withSession([
            '_old_input' => [
                'reorder_point' => '2.50000000',
                'equivalent_value' => '0.050000',
                'barcode' => '00000012',
            ],
        ])
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertSee('value="2.5"', false)
        ->assertSee('value="0.05"', false)
        ->assertSee('value="00000012"', false);

    $actor->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->assertSee('lang="ar" dir="rtl"', false)
        ->assertSee('value="1,250.5"', false)
        ->assertSee('value="0.000458"', false)
        ->assertSee('name="reorder_point"', false)
        ->assertSee('dir="ltr"', false)
        ->assertDontSee('١٬٢٥٠', false);
});

test('raw and packaging material forms share grouped numeric presentation and canonical persistence', function () {
    $actor = productCrudActor([
        'raw_materials.view',
        'raw_materials.create',
        'raw_materials.edit',
        'raw_materials.clone',
        'raw_materials.document_number.control',
        'packaging_materials.view',
        'packaging_materials.create',
        'packaging_materials.edit',
        'packaging_materials.clone',
        'packaging_materials.document_number.control',
    ]);

    foreach ([
        'raw-materials' => [Product::ClassificationRawMaterial, 91],
        'packaging-materials' => [Product::ClassificationPackaging, 92],
    ] as $routeContext => [$classification, $documentNumber]) {
        $response = $this->actingAs($actor)
            ->postJson(
                route("admin.{$routeContext}.store"),
                productPayloadWithImage([
                    'doc_number' => $documentNumber,
                    'name' => "Grouped {$classification}",
                    'item_classification' => $classification,
                    'reorder_point' => '1,250.5',
                ]),
            )
            ->assertOk()
            ->assertJsonPath('success', true);

        $record = Product::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

        expect((string) $record->reorder_point)->toBe('1250.5000')
            ->and($record->item_classification)->toBe($classification);

        foreach (['edit', 'show', 'clone'] as $action) {
            $this->actingAs($actor)
                ->get(route("admin.{$routeContext}.{$action}", $record->doc_num))
                ->assertOk()
                ->assertSee('value="1,250.5"', false)
                ->assertSee('dir="ltr"', false);
        }
    }
});

test('same product barcode can be reused in another operating company', function () {
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Company A Barcode Product',
            'barcode' => 'SHARED-BC',
        ]))
        ->assertOk();

    productOperatingContext($this);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Company B Barcode Product',
            'barcode' => 'SHARED-BC',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('legacy product classifier payload is ignored', function () {
    $actor = productCrudActor(['products.create', 'products.edit']);
    $legacyClassifierField = 'product'.'_type';

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Legacy Classifier Product',
            $legacyClassifierField => 'SERVICE',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Product::query()->where('name', 'Legacy Classifier Product')->exists())->toBeTrue()
        ->and(Schema::hasColumn('products', $legacyClassifierField))->toBeFalse();
});

test('products data table exposes public document number without internal ids', function () {
    $actor = productCrudActor(['products.view', 'products.edit', 'products.delete', 'products.clone']);
    $legacyClassifierField = 'product'.'_type';
    Storage::disk('public')->put('products/images/display.jpg', 'display image');
    $color = ItemColor::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 24,
        'doc_num' => 'Color-00024',
        'name' => 'Natural',
        'status' => 'active',
    ]);
    $decal = ItemDecal::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 26,
        'doc_num' => 'Decal-00026',
        'name' => 'Natural Grain',
        'status' => 'active',
    ]);
    $originCountry = ItemOriginCountry::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 27,
        'doc_num' => 'Origin-00027',
        'name' => 'Egypt',
        'status' => 'active',
    ]);

    Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 7,
        'doc_num' => 'Product-00007',
        'name' => 'Display Product',
        'image_path' => 'products/images/display.jpg',
        'barcode' => 'DISPLAY-BC',
        'item_classification' => Product::ClassificationFinishedProduct,
        'reorder_point' => '8.2500',
        'item_color_id' => $color->getKey(),
        'item_decal_id' => $decal->getKey(),
        'item_origin_country_id' => $originCountry->getKey(),
        'status' => 'active',
    ]);

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->json();

    $html = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $row = $payload['data'][0] ?? [];
    $columnsScript = (string) file_get_contents(public_path('assets/js/modules/Core/products.js'));
    preg_match_all("/data: '([^']+)'/", $columnsScript, $columnMatches);
    $frontendColumns = $columnMatches[1] ?? [];

    expect($row)
        ->not->toHaveKey('id')
        ->not->toHaveKey('image_path')
        ->not->toHaveKey($legacyClassifierField)
        ->not->toHaveKey('item_color_id')
        ->not->toHaveKey('item_decal_id')
        ->not->toHaveKey('item_origin_country_id');

    expect($frontendColumns)->toContain('image');

    foreach ($frontendColumns as $column) {
        expect($row)->toHaveKey($column);
    }

    expect(array_keys($row))->toEqualCanonicalizing($frontendColumns);

    expect($row['image'])
        ->toContain('product-table-image')
        ->toContain('js-product-image-preview')
        ->toContain('data-product-image-url="/storage/products/images/display.jpg"')
        ->toContain('data-product-name="Display Product"')
        ->toContain(__('products.image.preview'))
        ->toContain('object-fit-cover')
        ->toContain('/storage/products/images/display.jpg');

    expect($columnsScript)
        ->toContain(".on('click.coreProductsImagePreview', '.js-product-image-preview'")
        ->toContain('event.stopPropagation();')
        ->toContain('hidden.bs.modal.coreProductsImagePreview');

    expect($html)
        ->toContain('Product-00007')
        ->toContain('/storage/products/images/display.jpg')
        ->toContain('DISPLAY-BC')
        ->toContain(__('products.classifications.'.Product::ClassificationFinishedProduct))
        ->toContain('8.25')
        ->toContain('Color-00024')
        ->toContain('Natural')
        ->toContain('Decal-00026')
        ->toContain('Natural Grain')
        ->toContain('Origin-00027')
        ->toContain('Egypt')
        ->toContain('dropstart')
        ->toContain('js-delete-record')
        ->not->toContain('"image_path"')
        ->not->toContain('storage/app')
        ->not->toContain('is_coolable')
        ->not->toContain('Coolable')
        ->not->toContain('data-id=');
});

test('products data table displays grouped reorder points while ordering by the numeric database column', function () {
    $actor = productCrudActor(['products.view']);

    foreach (['1000.0000', '10.0000', '100.0000', '2.0000'] as $index => $reorderPoint) {
        Product::query()->create([
            'company_id' => $this->productCompany->getKey(),
            'doc_number' => 8100 + $index,
            'doc_num' => 'Product-'.(8100 + $index),
            'name' => 'Index Numeric Sort Product '.($index + 1),
            'item_classification' => Product::ClassificationFinishedProduct,
            'reorder_point' => $reorderPoint,
            'status' => 'active',
        ]);
    }

    $columns = collect([
        ['checkbox', 'checkbox', false],
        ['doc_num', 'products.doc_number', true],
        ['image', 'image', false],
        ['name', 'products.name', true],
        ['barcode', 'products.barcode', true],
        ['item_classification', 'products.item_classification', true],
        ['reorder_point', 'products.reorder_point', true],
    ])->map(fn (array $column): array => [
        'data' => $column[0],
        'name' => $column[1],
        'searchable' => $column[2] ? 'true' : 'false',
        'orderable' => $column[2] ? 'true' : 'false',
        'search' => ['value' => '', 'regex' => 'false'],
    ])->all();

    $rows = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Index Numeric Sort Product', 'regex' => 'false'],
            'columns' => $columns,
            'order' => [['column' => 6, 'dir' => 'asc']],
        ]))
        ->assertOk()
        ->json('data');

    expect(array_column($rows, 'reorder_point'))->toBe(['2', '10', '100', '1,000']);
});

test('product, raw material, and packaging material data stay in their own contexts', function () {
    $actor = productCrudActor(['products.view', 'raw_materials.view', 'packaging_materials.view']);
    $normalProduct = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 2201,
        'doc_num' => 'Product-02201',
        'name' => 'Classification Visibility Finished',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);
    $rawMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 2202,
        'doc_num' => 'Material-02202',
        'name' => 'Classification Visibility Raw',
        'item_classification' => Product::ClassificationRawMaterial,
        'status' => 'active',
    ]);
    $packagingMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 2203,
        'doc_num' => 'Packaging-02203',
        'name' => 'Classification Visibility Packaging',
        'item_classification' => Product::ClassificationPackaging,
        'status' => 'active',
    ]);

    $productsPayload = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Classification Visibility'],
        ]))
        ->assertOk()
        ->json('data');

    $productsHtml = json_encode($productsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    expect($productsHtml)
        ->toContain($normalProduct->doc_num)
        ->not->toContain($rawMaterial->doc_num)
        ->not->toContain($packagingMaterial->doc_num);

    $rawMaterialsPayload = $this->actingAs($actor)
        ->getJson(route('admin.raw-materials.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Classification Visibility'],
        ]))
        ->assertOk()
        ->json('data');

    $rawMaterialsHtml = json_encode($rawMaterialsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    expect($rawMaterialsHtml)
        ->toContain($rawMaterial->doc_num)
        ->not->toContain($normalProduct->doc_num)
        ->not->toContain($packagingMaterial->doc_num);

    $packagingMaterialsPayload = $this->actingAs($actor)
        ->getJson(route('admin.packaging-materials.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Classification Visibility'],
        ]))
        ->assertOk()
        ->json('data');

    expect(json_encode($packagingMaterialsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
        ->toContain($packagingMaterial->doc_num)
        ->not->toContain($normalProduct->doc_num)
        ->not->toContain($rawMaterial->doc_num);
});

test('products cannot create or convert material classifications', function () {
    config()->set('products.image_required', true);

    $actor = productCrudActor(['products.view', 'products.edit', 'products.create']);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 2210,
        'doc_num' => 'Product-02210',
        'name' => 'Classification Toggle Item',
        'image_path' => 'products/images/classification-toggle.jpg',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);

    foreach ([Product::ClassificationRawMaterial, Product::ClassificationPackaging] as $classification) {
        $this->actingAs($actor)
            ->putJson(route('admin.products.update', $product->doc_num), productPayload([
                'name' => 'Classification Toggle Item',
                'item_classification' => $classification,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_classification');

        expect($product->refresh()->item_classification)->toBe(Product::ClassificationFinishedProduct);
    }

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayload([
            'name' => 'Forged Packaging Product',
            'item_classification' => Product::ClassificationPackaging,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('item_classification');
});

test('inline lookup create requires lookup create permission', function () {
    $viewer = productCrudActor(['products.create']);

    $this->actingAs($viewer)
        ->postJson(route('admin.products.lookups.store', 'item-units'), ['name' => 'Box'])
        ->assertForbidden();

    $creator = productCrudActor(['products.create', 'item_units.create']);

    $payload = $this->actingAs($creator)
        ->postJson(route('admin.products.lookups.store', 'item-units'), ['name' => 'Box'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    expect(ItemUnit::query()->where('company_id', $this->productCompany->getKey())->where('name', 'Box')->where('status', 'active')->exists())->toBeTrue()
        ->and($payload['data']['option']['id'])->toBe('Unit-00001')
        ->and($payload['data']['option']['text'])->toContain('Box');

    $colorCreator = productCrudActor(['products.create', 'item_colors.create']);

    $colorPayload = $this->actingAs($colorCreator)
        ->postJson(route('admin.products.lookups.store', 'item-colors'), ['name' => 'Green'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    expect(ItemColor::query()->where('company_id', $this->productCompany->getKey())->where('name', 'Green')->where('status', 'active')->exists())->toBeTrue()
        ->and($colorPayload['data']['option']['id'])->toBe('Color-00001')
        ->and($colorPayload['data']['option']['text'])->toContain('Green');

    $decalCreator = productCrudActor(['products.create', 'item_decals.create']);

    $decalPayload = $this->actingAs($decalCreator)
        ->postJson(route('admin.products.lookups.store', 'item-decals'), ['name' => 'Oak Decal'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    expect(ItemDecal::query()->where('company_id', $this->productCompany->getKey())->where('name', 'Oak Decal')->where('status', 'active')->exists())->toBeTrue()
        ->and($decalPayload['data']['option']['id'])->toBe('Decal-00001')
        ->and($decalPayload['data']['option']['text'])->toContain('Oak Decal');

    $originCreator = productCrudActor(['products.create', 'item_origin_countries.create']);

    $originPayload = $this->actingAs($originCreator)
        ->postJson(route('admin.products.lookups.store', 'item-origin-countries'), ['name' => 'Egypt'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    expect(ItemOriginCountry::query()->where('company_id', $this->productCompany->getKey())->where('name', 'Egypt')->where('status', 'active')->exists())->toBeTrue()
        ->and($originPayload['data']['option']['id'])->toBe('Origin-00001')
        ->and($originPayload['data']['option']['text'])->toContain('Egypt');
});

test('product clone form preserves selected color by public document number', function () {
    $actor = productCrudActor(['products.clone']);
    $color = ItemColor::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 25,
        'doc_num' => 'Color-00025',
        'name' => 'Walnut',
        'status' => 'active',
    ]);
    $decal = ItemDecal::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 28,
        'doc_num' => 'Decal-00028',
        'name' => 'Walnut Pattern',
        'status' => 'active',
    ]);
    $originCountry = ItemOriginCountry::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 29,
        'doc_num' => 'Origin-00029',
        'name' => 'Egypt',
        'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 34,
        'doc_num' => 'Product-00034',
        'name' => 'Clone Color Product',
        'item_color_id' => $color->getKey(),
        'item_decal_id' => $decal->getKey(),
        'item_origin_country_id' => $originCountry->getKey(),
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.products.clone', $product->doc_num))
        ->assertOk()
        ->assertSee('value="'.$color->doc_num.'"', false)
        ->assertSee("{$color->doc_num} / {$color->name}", false)
        ->assertSee('value="'.$decal->doc_num.'"', false)
        ->assertSee("{$decal->doc_num} / {$decal->name}", false)
        ->assertSee('value="'.$originCountry->doc_num.'"', false)
        ->assertSee("{$originCountry->doc_num} / {$originCountry->name}", false);
});

test('products can be soft deleted and restored by public document number', function () {
    $actor = productCrudActor(['products.delete', 'products.restore']);

    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 20,
        'doc_num' => 'Product-00020',
        'name' => 'Restorable Product',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.products.destroy', $product->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Product::withTrashed()->where('doc_num', $product->doc_num)->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.products.restore', $product->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Product::query()->where('doc_num', $product->doc_num)->exists())->toBeTrue();
});

test('restored product edit uses model values and submits required fields normally', function () {
    $actor = productCrudActor(['products.delete', 'products.restore', 'products.edit', 'products.view', 'products.document_number.control']);

    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 21,
        'doc_num' => 'Product-00021',
        'name' => 'Restored Editable Product',
        'image_path' => 'products/images/restored.jpg',
        'item_classification' => Product::ClassificationSemiFinished,
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.products.destroy', $product->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->withSession([
        '_old_input' => [
            'name' => '',
            'item_classification' => '',
            'status' => '',
        ],
    ])
        ->actingAs($actor)
        ->patchJson(route('admin.products.restore', $product->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertSessionMissing('_old_input')
        ->assertSessionMissing('errors');

    $editResponse = $this->actingAs($actor)
        ->get(route('admin.products.edit', $product->doc_num))
        ->assertOk()
        ->assertSee('name="name"', false)
        ->assertSee('name="item_classification"', false)
        ->assertSee('name="status"', false)
        ->assertDontSee('name="product_type"', false);

    expect($editResponse->getContent())->toContain('value="Restored Editable Product"')
        ->and($editResponse->getContent())->toMatch('/<option value="'.preg_quote(Product::ClassificationSemiFinished, '/').'" selected>/')
        ->and($editResponse->getContent())->toMatch('/<option value="active" selected>/');

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Restored Editable Product Updated',
            'item_classification' => Product::ClassificationSemiFinished,
            'status' => 'active',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($product->refresh()->name)->toBe('Restored Editable Product Updated');
});

test('product edit validation still rejects genuinely empty required fields', function (array $overrides, string $field) {
    $actor = productCrudActor(['products.edit']);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 22,
        'doc_num' => 'Product-00022',
        'name' => 'Required Fields Product',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Required Fields Product',
            'item_classification' => Product::ClassificationFinishedProduct,
            'status' => 'active',
            ...$overrides,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'name' => [['name' => ''], 'name'],
    'item classification' => [['item_classification' => ''], 'item_classification'],
    'status' => [['status' => ''], 'status'],
]);

test('products and product lookups are scoped by operating company', function () {
    $actor = productCrudActor(['products.view', 'products.create', 'products.edit']);
    $companyA = $this->productCompany;
    $colorA = ItemColor::query()->create([
        'company_id' => $companyA->getKey(),
        'doc_number' => 51,
        'doc_num' => 'Color-00051',
        'name' => 'Company A Color',
        'status' => 'active',
    ]);

    $productA = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Company A Product',
            'item_color_doc_num' => $colorA->doc_num,
        ]))
        ->assertOk()
        ->json('data.doc_num');

    $companyB = productOperatingContext($this);
    $colorB = ItemColor::query()->create([
        'company_id' => $companyB->getKey(),
        'doc_number' => 52,
        'doc_num' => 'Color-00052',
        'name' => 'Company B Color',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Cross Company Color Product',
            'item_color_doc_num' => $colorA->doc_num,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['item_color_doc_num']);

    $productB = $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Company B Product',
            'item_color_doc_num' => $colorB->doc_num,
        ]))
        ->assertOk()
        ->json('data.doc_num');

    expect($productA)->toBe('Product-00001')
        ->and($productB)->toBe('Product-00001')
        ->and(Product::query()->where('company_id', $companyA->getKey())->where('doc_num', 'Product-00001')->first()?->name)->toBe('Company A Product')
        ->and(Product::query()->where('company_id', $companyB->getKey())->where('doc_num', 'Product-00001')->first()?->name)->toBe('Company B Product');

    $selectorPayload = $this->actingAs($actor)
        ->getJson(route('admin.select2.item-colors', ['q' => 'Company']))
        ->assertOk()
        ->json();

    $selectorJson = json_encode($selectorPayload, JSON_THROW_ON_ERROR);

    expect($selectorJson)
        ->toContain('Company B Color')
        ->not->toContain('Company A Color');

    $tablePayload = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->json();

    $tableJson = json_encode($tablePayload, JSON_THROW_ON_ERROR);

    expect($tableJson)
        ->toContain('Company B Product')
        ->not->toContain('Company A Product');

    $this->actingAs($actor)
        ->get(route('admin.products.show', 'Product-00001'))
        ->assertOk()
        ->assertSee('Company B Product')
        ->assertDontSee('Company A Product');
});

test('Product DocumentNumber generation is company scoped without changing company numbering config', function () {
    $actor = productCrudActor(['products.create', 'products.view', 'products.edit']);
    $companyA = $this->productCompany;

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage(['name' => 'Company A Numbered Product']))
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'Product-00001');

    $companyB = productOperatingContext($this);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage(['name' => 'Company B Numbered Product']))
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'Product-00001');

    $numbers = app(DocumentNumberService::class);

    expect($numbers->nextNumberForCompany('products', Product::class, $companyA->getKey()))->toBe(2)
        ->and($numbers->nextNumberForCompany('products', Product::class, $companyB->getKey()))->toBe(2)
        ->and(config('document_numbers.companies.scope'))->toBeNull();
});
