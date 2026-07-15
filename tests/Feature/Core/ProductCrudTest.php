<?php

use App\Models\User;
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
use Modules\Core\Services\ProductImageResolver;
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
        ->assertSee(__('menu.item_data'))
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
        ->assertSee(__('products.classifications.'.Product::ClassificationRawMaterial))
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
        'item_classification' => Product::ClassificationPackaging,
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
        ->toMatch('/<option value="'.preg_quote(Product::ClassificationPackaging, '/').'" selected>/')
        ->toMatch('/<select(?=[^>]*id="status")(?=[^>]*name="status")(?![^>]*disabled)[^>]*>/')
        ->toMatch('/<option value="inactive" selected>/')
        ->toContain(__('products.image.select'))
        ->not->toContain('type="file"')
        ->not->toContain('name="image"');

    $this->actingAs($actor)
        ->post(route('admin.products.update', $product->doc_num), [
            ...productPayload([
                'name' => 'Editable Required Product Updated',
                'item_classification' => Product::ClassificationPackaging,
                'status' => 'active',
            ]),
            '_method' => 'PUT',
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $product->refresh();

    expect($product->name)->toBe('Editable Required Product Updated')
        ->and($product->item_classification)->toBe(Product::ClassificationPackaging)
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
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Assembled Oak Desk',
            'components' => [
                [
                    'component_product_doc_num' => $rawMaterial->doc_num,
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
        ->and((string) $component->quantity)->toBe('2.5000')
        ->and($component->notes)->toBe('Desktop board');
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

    expect(Product::query()->whereIn('name', ['Missing Component Material', 'Missing Component Quantity'])->exists())->toBeFalse();
});

test('invalid submitted product components reject master creation', function () {
    $actor = productCrudActor(['products.create']);
    $nonRawProduct = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 72,
        'doc_num' => 'Product-00072',
        'name' => 'Finished Chair',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Invalid Component Master',
            'components' => [
                [
                    'component_product_doc_num' => $nonRawProduct->doc_num,
                    'quantity' => '1',
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.0.component_product_doc_num']);

    expect(Product::query()->where('name', 'Invalid Component Master')->exists())->toBeFalse();
});

test('duplicate submitted product component rows are rejected', function () {
    $actor = productCrudActor(['products.create']);
    $rawMaterial = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 73,
        'doc_num' => 'Product-00073',
        'name' => 'Raw Hinge',
        'item_classification' => Product::ClassificationRawMaterial,
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.products.store'), productPayloadWithImage([
            'name' => 'Duplicate Component Master',
            'components' => [
                [
                    'component_product_doc_num' => $rawMaterial->doc_num,
                    'quantity' => '1',
                ],
                [
                    'component_product_doc_num' => $rawMaterial->doc_num,
                    'quantity' => '2',
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components.1.component_product_doc_num']);

    expect(Product::query()->where('name', 'Duplicate Component Master')->exists())->toBeFalse();
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

    expect((string) $componentToUpdate->refresh()->quantity)->toBe('4.2500')
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
            'item_classification' => Product::ClassificationPackaging,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $product = Product::query()->where('name', 'Nullable Barcode Product')->firstOrFail();

    expect($product->barcode)->toBeNull()
        ->and((string) $product->reorder_point)->toBe('5.2500')
        ->and($product->item_classification)->toBe(Product::ClassificationPackaging);
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

test('products data includes raw materials while raw materials data stays raw only', function () {
    $actor = productCrudActor(['products.view', 'raw_materials.view']);
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
        ->toContain($rawMaterial->doc_num)
        ->toContain(__('products.classifications.'.Product::ClassificationRawMaterial))
        ->toContain('badge-subtle-warning');

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
        ->not->toContain($normalProduct->doc_num);
});

test('products edit can move item into and out of raw materials screen', function () {
    config()->set('products.image_required', true);

    $actor = productCrudActor(['products.view', 'products.edit', 'raw_materials.view']);
    $product = Product::query()->create([
        'company_id' => $this->productCompany->getKey(),
        'doc_number' => 2210,
        'doc_num' => 'Product-02210',
        'name' => 'Classification Toggle Item',
        'image_path' => 'products/images/classification-toggle.jpg',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Classification Toggle Item',
            'item_classification' => Product::ClassificationRawMaterial,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($product->refresh()->item_classification)->toBe(Product::ClassificationRawMaterial);

    $productsPayload = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Classification Toggle Item'],
        ]))
        ->assertOk()
        ->json('data');
    $rawMaterialsPayload = $this->actingAs($actor)
        ->getJson(route('admin.raw-materials.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Classification Toggle Item'],
        ]))
        ->assertOk()
        ->json('data');

    expect(json_encode($productsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
        ->toContain($product->doc_num)
        ->toContain(__('products.classifications.'.Product::ClassificationRawMaterial));
    expect(json_encode($rawMaterialsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
        ->toContain($product->doc_num);

    $this->actingAs($actor)
        ->putJson(route('admin.products.update', $product->doc_num), productPayload([
            'name' => 'Classification Toggle Item',
            'item_classification' => Product::ClassificationFinishedProduct,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($product->refresh()->item_classification)->toBe(Product::ClassificationFinishedProduct);

    $productsPayload = $this->actingAs($actor)
        ->getJson(route('admin.products.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Classification Toggle Item'],
        ]))
        ->assertOk()
        ->json('data');
    $rawMaterialsPayload = $this->actingAs($actor)
        ->getJson(route('admin.raw-materials.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Classification Toggle Item'],
        ]))
        ->assertOk()
        ->json('data');

    expect(json_encode($productsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
        ->toContain($product->doc_num)
        ->toContain(__('products.classifications.'.Product::ClassificationFinishedProduct));
    expect(json_encode($rawMaterialsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
        ->not->toContain($product->doc_num);
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
