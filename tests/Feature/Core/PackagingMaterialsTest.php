<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function packagingMaterialsActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/** @return array{company: Company, session: array<string, int|string>} */
function packagingMaterialsContext(object $test): array
{
    static $documentNumber = 8800;

    $documentNumber++;
    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Packaging Materials Company '.$documentNumber,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Packaging Materials Branch '.$documentNumber,
        'type' => 'factory',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Packaging Materials Period '.$documentNumber,
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

/** @return array<string, mixed> */
function packagingMaterialsPayload(array $overrides = []): array
{
    return [
        'name' => 'Packaging Film',
        'item_classification' => Product::ClassificationPackaging,
        'status' => 'active',
        'cost_as_inventory' => '1',
        'is_displayable' => '1',
        'submit_action' => 'save',
        ...$overrides,
    ];
}

function packagingMaterialsItem(Company $company, int $number, string $classification, string $name): Product
{
    return Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'Item-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => $name,
        'item_classification' => $classification,
        'status' => 'active',
    ]);
}

beforeEach(function (): void {
    config()->set('products.image_required', false);
    $this->packagingContext = packagingMaterialsContext($this);
});

test('packaging material permissions, menu routes, and access boundary are registered', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();

    foreach (['view', 'create', 'edit', 'delete', 'clone', 'view_trashed', 'restore', 'document_number.control', 'document_number_settings.update'] as $action) {
        expect(Permission::query()->where('name', "packaging_materials.{$action}")->exists())->toBeTrue()
            ->and($adminRole->hasPermissionTo("packaging_materials.{$action}"))->toBeTrue();
    }

    foreach (['index', 'data', 'create', 'store', 'show', 'edit', 'update', 'destroy', 'restore', 'clone', 'bulk-delete'] as $action) {
        expect(route("admin.packaging-materials.{$action}", $action === 'index' || $action === 'data' || $action === 'create' || $action === 'store' || $action === 'bulk-delete' ? [] : 'missing'))->not->toBe('');
    }

    $unauthorized = packagingMaterialsActor(['products.view']);
    $this->actingAs($unauthorized)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.packaging-materials.index'))
        ->assertForbidden();

    $actor = packagingMaterialsActor(['packaging_materials.view', 'packaging_materials.create']);
    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.packaging-materials.index'))
        ->assertOk()
        ->assertSee(__('products.packaging_materials.title'))
        ->assertSee(route('admin.packaging-materials.data'), false)
        ->assertSee(__('menu.packaging_materials'));
});

test('packaging material routes and datatables isolate classifications and companies', function () {
    $actor = packagingMaterialsActor(['products.view', 'raw_materials.view', 'packaging_materials.view', 'packaging_materials.edit', 'packaging_materials.view_trashed']);
    $product = packagingMaterialsItem($this->packagingContext['company'], 1, Product::ClassificationFinishedProduct, 'Boundary Finished Product');
    $rawMaterial = packagingMaterialsItem($this->packagingContext['company'], 2, Product::ClassificationRawMaterial, 'Boundary Raw Material');
    $packagingMaterial = packagingMaterialsItem($this->packagingContext['company'], 3, Product::ClassificationPackaging, 'Boundary Packaging Material');
    $otherCompany = Company::factory()->create(['status' => 'active']);
    $otherPackagingMaterial = packagingMaterialsItem($otherCompany, 4, Product::ClassificationPackaging, 'Boundary Other Company Packaging');

    $payload = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->getJson(route('admin.packaging-materials.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'search' => ['value' => 'Boundary'],
        ]))
        ->assertOk()
        ->json('data');

    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    expect($json)
        ->toContain($packagingMaterial->doc_num)
        ->not->toContain($product->doc_num)
        ->not->toContain($rawMaterial->doc_num)
        ->not->toContain($otherPackagingMaterial->doc_num);

    $this->actingAs($actor)->withSession($this->packagingContext['session']);
    $this->get(route('admin.packaging-materials.show', $product->doc_num))->assertNotFound();
    $this->get(route('admin.packaging-materials.edit', $rawMaterial->doc_num))->assertNotFound();
    $this->get(route('admin.products.show', $packagingMaterial->doc_num))->assertNotFound();
    $this->get(route('admin.raw-materials.show', $packagingMaterial->doc_num))->assertNotFound();
});

test('packaging material images use the shared endpoint with packaging permission', function () {
    Storage::fake('public');
    Storage::disk('public')->put('products/images/packaging-film.jpg', 'packaging image');

    $packagingMaterial = Product::query()->create([
        'company_id' => $this->packagingContext['company']->getKey(),
        'doc_number' => 5,
        'doc_num' => 'Packaging-00005',
        'name' => 'Packaging Image Material',
        'image_path' => 'products/images/packaging-film.jpg',
        'item_classification' => Product::ClassificationPackaging,
        'status' => 'active',
    ]);

    $viewer = packagingMaterialsActor(['packaging_materials.view']);
    $this->actingAs($viewer)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.products.image', $packagingMaterial->doc_num))
        ->assertOk();

    $productViewer = packagingMaterialsActor(['products.view']);
    $this->actingAs($productViewer)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.products.image', $packagingMaterial->doc_num))
        ->assertForbidden();
});

test('packaging material create update and clone always preserve the packaging classification', function () {
    $actor = packagingMaterialsActor([
        'products.create',
        'products.edit',
        'packaging_materials.view',
        'packaging_materials.create',
        'packaging_materials.edit',
        'packaging_materials.clone',
    ]);

    $created = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->postJson(route('admin.packaging-materials.store'), packagingMaterialsPayload([
            'name' => 'Forged Packaging Create',
            'item_classification' => Product::ClassificationFinishedProduct,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');

    $packagingMaterial = Product::query()->where('doc_num', $created['doc_num'])->firstOrFail();
    expect($packagingMaterial->item_classification)->toBe(Product::ClassificationPackaging)
        ->and($packagingMaterial->doc_num)->toBe('PACK-00001');

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->putJson(route('admin.packaging-materials.update', $packagingMaterial->doc_num), packagingMaterialsPayload([
            'name' => 'Forged Packaging Update',
            'item_classification' => Product::ClassificationFinishedProduct,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($packagingMaterial->refresh()->item_classification)->toBe(Product::ClassificationPackaging)
        ->and($packagingMaterial->name)->toBe('Forged Packaging Update');

    $clone = $this->actingAs($actor)
        ->withSession([
            ...$this->packagingContext['session'],
            'products.clone_sources.packaging-material-clone' => $packagingMaterial->doc_num,
        ])
        ->postJson(route('admin.packaging-materials.store'), packagingMaterialsPayload([
            'name' => 'Packaging Material Clone',
            'clone_source_token' => 'packaging-material-clone',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');

    expect(Product::query()->where('doc_num', $clone['doc_num'])->value('item_classification'))->toBe(Product::ClassificationPackaging);

    $product = packagingMaterialsItem($this->packagingContext['company'], 99, Product::ClassificationFinishedProduct, 'Forged Product');
    foreach ([Product::ClassificationRawMaterial, Product::ClassificationPackaging] as $classification) {
        $this->actingAs($actor)
            ->withSession($this->packagingContext['session'])
            ->postJson(route('admin.products.store'), packagingMaterialsPayload([
                'name' => "Forged Product {$classification}",
                'item_classification' => $classification,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_classification');

        $this->actingAs($actor)
            ->withSession($this->packagingContext['session'])
            ->putJson(route('admin.products.update', $product->doc_num), packagingMaterialsPayload([
                'name' => 'Forged Product',
                'item_classification' => $classification,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_classification');
    }

    expect($product->refresh()->item_classification)->toBe(Product::ClassificationFinishedProduct);
});

test('packaging material soft delete restore and bulk validation retain their context boundary', function () {
    $actor = packagingMaterialsActor([
        'packaging_materials.view',
        'packaging_materials.delete',
        'packaging_materials.restore',
        'packaging_materials.view_trashed',
    ]);
    $packagingMaterial = packagingMaterialsItem($this->packagingContext['company'], 101, Product::ClassificationPackaging, 'Deletable Packaging Material');
    $product = packagingMaterialsItem($this->packagingContext['company'], 102, Product::ClassificationFinishedProduct, 'Not Packaging Bulk Item');

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->deleteJson(route('admin.packaging-materials.destroy', $packagingMaterial->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('products', ['id' => $packagingMaterial->getKey()]);

    $trashed = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->getJson(route('admin.packaging-materials.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'trashed',
            'search' => ['value' => 'Deletable Packaging'],
        ]))
        ->assertOk()
        ->json('data');

    expect(json_encode($trashed, JSON_THROW_ON_ERROR))->toContain($packagingMaterial->doc_num);

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->patchJson(route('admin.packaging-materials.restore', $packagingMaterial->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($packagingMaterial->fresh()?->trashed())->toBeFalse();

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->deleteJson(route('admin.packaging-materials.bulk-delete'), [
            'doc_nums' => [$packagingMaterial->doc_num, $product->doc_num],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doc_nums.1');

    expect($packagingMaterial->fresh()?->trashed())->toBeFalse()
        ->and($product->fresh()?->trashed())->toBeFalse();
});

test('product data report scopes and exports separate packaging materials', function () {
    $actor = packagingMaterialsActor(['reports.products_data.view', 'reports.products_data.export']);
    $product = packagingMaterialsItem($this->packagingContext['company'], 201, Product::ClassificationFinishedProduct, 'Report Finished Product');
    $rawMaterial = packagingMaterialsItem($this->packagingContext['company'], 202, Product::ClassificationRawMaterial, 'Report Raw Material');
    $packagingMaterial = packagingMaterialsItem($this->packagingContext['company'], 203, Product::ClassificationPackaging, 'Report Packaging Material');

    $packagingRows = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->getJson(route('admin.reports.products-data.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'item_scope' => 'packaging_materials',
            'search' => ['value' => 'Report'],
        ]))
        ->assertOk()
        ->json('data');

    expect(json_encode($packagingRows, JSON_THROW_ON_ERROR))
        ->toContain($packagingMaterial->doc_num)
        ->not->toContain($product->doc_num)
        ->not->toContain($rawMaterial->doc_num);

    $productRows = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->getJson(route('admin.reports.products-data.data', [
            'draw' => 2,
            'start' => 0,
            'length' => 25,
            'item_scope' => 'products',
            'search' => ['value' => 'Report'],
        ]))
        ->assertOk()
        ->json('data');

    expect(json_encode($productRows, JSON_THROW_ON_ERROR))
        ->toContain($product->doc_num)
        ->not->toContain($packagingMaterial->doc_num);

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.reports.products-data.export.csv', ['item_scope' => 'packaging_materials']))
        ->assertOk()
        ->assertDownload('products-data-report.csv');
});
