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

test('packaging material finished product selector is isolated and only returns active current-company finished products', function () {
    Storage::fake('public');

    $actor = packagingMaterialsActor([
        'packaging_materials.create',
        'packaging_materials.edit',
        'packaging_materials.view',
        'products.create',
        'products.view',
        'raw_materials.create',
    ]);
    Storage::disk('public')->put('products/images/finished-selector.webp', 'finished product image');
    $finishedProduct = packagingMaterialsItem(
        $this->packagingContext['company'],
        310,
        Product::ClassificationFinishedProduct,
        'Selector Finished Chair',
    );
    $finishedProduct->update([
        'barcode' => 'SELECTOR-FINISHED-310',
        'image_path' => 'products/images/finished-selector.webp',
    ]);
    $rawMaterial = packagingMaterialsItem($this->packagingContext['company'], 311, Product::ClassificationRawMaterial, 'Selector Raw Material');
    $packagingMaterial = packagingMaterialsItem($this->packagingContext['company'], 312, Product::ClassificationPackaging, 'Selector Packaging Material');
    $semiFinishedProduct = packagingMaterialsItem($this->packagingContext['company'], 313, Product::ClassificationSemiFinished, 'Selector Semi Finished Product');
    $inactiveFinishedProduct = packagingMaterialsItem($this->packagingContext['company'], 314, Product::ClassificationFinishedProduct, 'Selector Inactive Finished Product');
    $inactiveFinishedProduct->update(['status' => 'inactive']);
    $deletedFinishedProduct = packagingMaterialsItem($this->packagingContext['company'], 315, Product::ClassificationFinishedProduct, 'Selector Deleted Finished Product');
    $deletedFinishedProduct->delete();
    $otherCompany = Company::factory()->create(['status' => 'active']);
    $otherCompanyFinishedProduct = packagingMaterialsItem($otherCompany, 316, Product::ClassificationFinishedProduct, 'Selector Other Company Finished Product');

    $packagingForm = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.packaging-materials.create'))
        ->assertOk()
        ->assertSee(__('products.packaging_materials.related_finished_products.label'))
        ->assertSee(__('products.packaging_materials.related_finished_products.placeholder'))
        ->assertSee(__('products.packaging_materials.related_finished_products.help'))
        ->assertSee(__('products.packaging_materials.related_finished_products.no_results'))
        ->assertSee('id="packaging-related-finished-products"', false)
        ->assertSee('name="related_finished_product_doc_nums[]"', false)
        ->assertSee(route('admin.select2.finished-products'), false)
        ->assertSee('data-template="product-image"', false)
        ->getContent();

    expect($packagingForm)->toContain('multiple');

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertDontSee('packaging-related-finished-products', false);

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.raw-materials.create'))
        ->assertOk()
        ->assertDontSee('packaging-related-finished-products', false);

    $payload = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->getJson(route('admin.select2.finished-products', ['q' => 'Selector', 'page' => 1]))
        ->assertOk()
        ->json();
    $resultIds = collect($payload['results'])->pluck('id')->all();
    $finishedResult = collect($payload['results'])->firstWhere('id', $finishedProduct->doc_num);

    expect($payload['pagination']['more'])->toBeBool()
        ->and($resultIds)->toContain($finishedProduct->doc_num)
        ->not->toContain($rawMaterial->doc_num)
        ->not->toContain($packagingMaterial->doc_num)
        ->not->toContain($semiFinishedProduct->doc_num)
        ->not->toContain($inactiveFinishedProduct->doc_num)
        ->not->toContain($deletedFinishedProduct->doc_num)
        ->not->toContain($otherCompanyFinishedProduct->doc_num)
        ->and($finishedResult['text'])->toBe($finishedProduct->doc_num.' — '.$finishedProduct->name)
        ->and($finishedResult['imageUrl'])->toBe(Storage::disk('public')->url('products/images/finished-selector.webp'));

    $byDocNum = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->getJson(route('admin.select2.finished-products', ['q' => $finishedProduct->doc_num]))
        ->assertOk()
        ->json('results');

    expect(collect($byDocNum)->pluck('id')->all())->toContain($finishedProduct->doc_num);

    $preloaded = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->getJson(route('admin.select2.finished-products', [
            'selected_doc_nums' => [$finishedProduct->doc_num, $rawMaterial->doc_num],
        ]))
        ->assertOk()
        ->json('results');

    expect(collect($preloaded)->pluck('id')->all())
        ->toContain($finishedProduct->doc_num)
        ->not->toContain($rawMaterial->doc_num);
});

test('packaging material finished product relationships validate, synchronize, show, clone, and survive soft deletion', function () {
    $actor = packagingMaterialsActor([
        'packaging_materials.create',
        'packaging_materials.edit',
        'packaging_materials.clone',
        'packaging_materials.view',
        'packaging_materials.delete',
        'packaging_materials.restore',
        'packaging_materials.view_trashed',
        'products.view',
    ]);
    $firstFinishedProduct = packagingMaterialsItem($this->packagingContext['company'], 320, Product::ClassificationFinishedProduct, 'Related Finished Product One');
    $secondFinishedProduct = packagingMaterialsItem($this->packagingContext['company'], 321, Product::ClassificationFinishedProduct, 'Related Finished Product Two');

    $created = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->postJson(route('admin.packaging-materials.store'), packagingMaterialsPayload([
            'name' => 'Related Packaging Material',
            'related_finished_product_doc_nums' => [
                $firstFinishedProduct->doc_num,
                $firstFinishedProduct->doc_num,
                $secondFinishedProduct->doc_num,
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');
    $packagingMaterial = Product::query()->where('doc_num', $created['doc_num'])->firstOrFail();

    expect($packagingMaterial->relatedFinishedProducts()->pluck('products.doc_num')->sort()->values()->all())
        ->toBe([$firstFinishedProduct->doc_num, $secondFinishedProduct->doc_num]);
    $this->assertDatabaseCount('packaging_material_finished_product', 2);

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->putJson(route('admin.packaging-materials.update', $packagingMaterial->doc_num), packagingMaterialsPayload([
            'name' => $packagingMaterial->name,
            'related_finished_product_doc_nums' => [$firstFinishedProduct->doc_num, $secondFinishedProduct->doc_num],
        ]))
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    expect($packagingMaterial->refresh()->relatedFinishedProducts()->pluck('products.doc_num')->sort()->values()->all())
        ->toBe([$firstFinishedProduct->doc_num, $secondFinishedProduct->doc_num]);

    $validationRestoredHtml = $this->actingAs($actor)
        ->withSession([
            ...$this->packagingContext['session'],
            '_old_input' => [
                'related_finished_product_doc_nums' => [$firstFinishedProduct->doc_num, $secondFinishedProduct->doc_num],
            ],
        ])
        ->get(route('admin.packaging-materials.create'))
        ->assertOk()
        ->getContent();

    expect($validationRestoredHtml)
        ->toContain('value="'.$firstFinishedProduct->doc_num.'" selected')
        ->toContain('value="'.$secondFinishedProduct->doc_num.'" selected');

    $editHtml = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.packaging-materials.edit', $packagingMaterial->doc_num))
        ->assertOk()
        ->assertSee($firstFinishedProduct->doc_num)
        ->assertSee($firstFinishedProduct->name)
        ->assertSee($secondFinishedProduct->doc_num)
        ->assertSee($secondFinishedProduct->name)
        ->getContent();

    expect($editHtml)->toContain('value="'.$firstFinishedProduct->doc_num.'" selected')
        ->toContain('value="'.$secondFinishedProduct->doc_num.'" selected');

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->putJson(route('admin.packaging-materials.update', $packagingMaterial->doc_num), packagingMaterialsPayload([
            'related_finished_product_doc_nums' => [$secondFinishedProduct->doc_num],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($packagingMaterial->refresh()->relatedFinishedProducts()->pluck('products.doc_num')->all())
        ->toBe([$secondFinishedProduct->doc_num]);
    $this->assertDatabaseMissing('packaging_material_finished_product', [
        'packaging_material_id' => $packagingMaterial->getKey(),
        'finished_product_id' => $firstFinishedProduct->getKey(),
    ]);
    expect($firstFinishedProduct->fresh())->not->toBeNull();

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->putJson(route('admin.packaging-materials.update', $packagingMaterial->doc_num), packagingMaterialsPayload([
            'related_finished_product_doc_nums' => [''],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($packagingMaterial->refresh()->relatedFinishedProducts()->count())->toBe(0)
        ->and($secondFinishedProduct->fresh())->not->toBeNull();

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->putJson(route('admin.packaging-materials.update', $packagingMaterial->doc_num), packagingMaterialsPayload([
            'related_finished_product_doc_nums' => [$firstFinishedProduct->doc_num, $secondFinishedProduct->doc_num],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.packaging-materials.show', $packagingMaterial->doc_num))
        ->assertOk()
        ->assertSee($firstFinishedProduct->doc_num)
        ->assertSee($firstFinishedProduct->name)
        ->assertSee($secondFinishedProduct->doc_num)
        ->assertSee($secondFinishedProduct->name);

    $clone = $this->actingAs($actor)
        ->withSession([
            ...$this->packagingContext['session'],
            'products.clone_sources.related-packaging-material' => $packagingMaterial->doc_num,
        ])
        ->postJson(route('admin.packaging-materials.store'), packagingMaterialsPayload([
            'name' => 'Cloned Related Packaging Material',
            'clone_source_token' => 'related-packaging-material',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');
    $clonedPackagingMaterial = Product::query()->where('doc_num', $clone['doc_num'])->firstOrFail();

    expect($clonedPackagingMaterial->relatedFinishedProducts()->pluck('products.doc_num')->sort()->values()->all())
        ->toBe([$firstFinishedProduct->doc_num, $secondFinishedProduct->doc_num]);
    expect(Product::query()->whereIn('id', [$firstFinishedProduct->getKey(), $secondFinishedProduct->getKey()])->count())->toBe(2);

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->deleteJson(route('admin.packaging-materials.destroy', $packagingMaterial->doc_num))
        ->assertOk();

    $this->assertDatabaseCount('packaging_material_finished_product', 4);

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->patchJson(route('admin.packaging-materials.restore', $packagingMaterial->doc_num))
        ->assertOk();

    expect($packagingMaterial->fresh()->relatedFinishedProducts()->pluck('products.doc_num')->sort()->values()->all())
        ->toBe([$firstFinishedProduct->doc_num, $secondFinishedProduct->doc_num]);

    $packagingMaterial->forceDelete();

    $this->assertDatabaseCount('packaging_material_finished_product', 2);
    $this->assertDatabaseMissing('packaging_material_finished_product', [
        'packaging_material_id' => $packagingMaterial->getKey(),
        'finished_product_id' => $firstFinishedProduct->getKey(),
    ]);
});

test('packaging material related finished product validation rejects forged values and retains stale existing selections', function () {
    $actor = packagingMaterialsActor([
        'packaging_materials.create',
        'packaging_materials.edit',
        'packaging_materials.view',
    ]);
    $finishedProduct = packagingMaterialsItem($this->packagingContext['company'], 330, Product::ClassificationFinishedProduct, 'Valid Finished Product');
    $rawMaterial = packagingMaterialsItem($this->packagingContext['company'], 331, Product::ClassificationRawMaterial, 'Forged Raw Material');
    $packagingMaterialValue = packagingMaterialsItem($this->packagingContext['company'], 332, Product::ClassificationPackaging, 'Forged Packaging Material');
    $semiFinishedProduct = packagingMaterialsItem($this->packagingContext['company'], 333, Product::ClassificationSemiFinished, 'Forged Semi Finished Product');
    $deletedFinishedProduct = packagingMaterialsItem($this->packagingContext['company'], 334, Product::ClassificationFinishedProduct, 'Deleted Finished Product');
    $deletedFinishedProduct->delete();
    $otherCompany = Company::factory()->create(['status' => 'active']);
    $foreignFinishedProduct = packagingMaterialsItem($otherCompany, 335, Product::ClassificationFinishedProduct, 'Foreign Finished Product');

    foreach ([
        'not an array' => $finishedProduct->doc_num,
        'other company' => [$foreignFinishedProduct->doc_num],
        'raw material' => [$rawMaterial->doc_num],
        'packaging material' => [$packagingMaterialValue->doc_num],
        'semi finished product' => [$semiFinishedProduct->doc_num],
        'deleted product' => [$deletedFinishedProduct->doc_num],
        'unknown product' => ['Missing-Finished-Product'],
    ] as $value) {
        $this->actingAs($actor)
            ->withSession($this->packagingContext['session'])
            ->postJson(route('admin.packaging-materials.store'), packagingMaterialsPayload([
                'name' => 'Invalid Related Packaging '.fake()->uuid(),
                'related_finished_product_doc_nums' => $value,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('related_finished_product_doc_nums');
    }

    $packagingMaterial = packagingMaterialsItem($this->packagingContext['company'], 336, Product::ClassificationPackaging, 'Stale Related Packaging Material');
    $packagingMaterial->relatedFinishedProducts()->attach($finishedProduct->getKey());
    $finishedProduct->update(['status' => 'inactive']);

    $staleEdit = $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.packaging-materials.edit', $packagingMaterial->doc_num))
        ->assertOk()
        ->assertSee($finishedProduct->doc_num)
        ->assertSee($finishedProduct->name)
        ->assertSee(__('products.packaging_materials.related_finished_products.unavailable'))
        ->getContent();

    expect($staleEdit)->toContain('value="'.$finishedProduct->doc_num.'" selected');

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->putJson(route('admin.packaging-materials.update', $packagingMaterial->doc_num), packagingMaterialsPayload([
            'related_finished_product_doc_nums' => [$finishedProduct->doc_num],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($packagingMaterial->refresh()->relatedFinishedProducts()->withTrashed()->pluck('products.doc_num')->all())
        ->toBe([$finishedProduct->doc_num]);

    $finishedProduct->delete();

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->get(route('admin.packaging-materials.edit', $packagingMaterial->doc_num))
        ->assertOk()
        ->assertSee($finishedProduct->doc_num)
        ->assertSee(__('products.packaging_materials.related_finished_products.unavailable'));

    expect($packagingMaterial->fresh()->trashed())->toBeFalse()
        ->and($packagingMaterial->relatedFinishedProducts()->withTrashed()->pluck('products.doc_num')->all())
        ->toBe([$finishedProduct->doc_num]);

    $finishedProduct->restore();
    $finishedProduct->update(['status' => 'active']);

    $this->actingAs($actor)
        ->withSession($this->packagingContext['session'])
        ->putJson(route('admin.packaging-materials.update', $packagingMaterial->doc_num), packagingMaterialsPayload([
            'related_finished_product_doc_nums' => [''],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($packagingMaterial->refresh()->relatedFinishedProducts()->withTrashed()->count())->toBe(0)
        ->and($finishedProduct->fresh())->not->toBeNull();
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
