<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemDecal;
use Modules\Core\Models\ItemGroup;
use Modules\Core\Models\ItemModel;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\ItemSize;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function itemLookupActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function itemLookupOperatingContext(object $test): Company
{
    static $documentNumber = 6100;

    $documentNumber++;
    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Item Lookup Company '.$documentNumber,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Item Lookup Branch '.$documentNumber,
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Item Lookup Period '.$documentNumber,
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
    $this->itemLookupCompany = itemLookupOperatingContext($this);
});

test('item data permissions are discoverable and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();

    foreach (['item_units', 'item_sizes', 'item_colors', 'item_decals', 'item_models', 'item_categories', 'item_groups', 'item_origin_countries'] as $prefix) {
        expect(Permission::query()->where('name', "{$prefix}.view")->exists())->toBeTrue()
            ->and(Permission::query()->where('name', "{$prefix}.restore")->exists())->toBeTrue()
            ->and($adminRole->hasPermissionTo("{$prefix}.document_number_settings.update"))->toBeTrue();
    }
});

test('item unit index renders through shared item lookup crud', function () {
    $actor = itemLookupActor([
        'item_units.view',
        'item_units.create',
        'item_units.delete',
        'item_units.view_trashed',
        'item_units.restore',
        'item_units.document_number_settings.update',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.item-units.index'))
        ->assertOk()
        ->assertSee(__('item_units.title'))
        ->assertSee(__('menu.inventory'))
        ->assertSee(route('admin.item-units.data'), false)
        ->assertSee(route('admin.item-units.bulk-delete'), false)
        ->assertSee('js-item-lookup-table', false)
        ->assertSee('assets/js/modules/Core/item-lookups.js', false)
        ->assertSee('id="item_lookup_trash_filter"', false)
        ->assertDontSee('data-id=', false);
});

test('item lookups and product masters are grouped under inventory item data', function () {
    $actor = itemLookupActor([
        'companies.view',
        'products.view',
        'item_categories.view',
        'item_units.view',
        'item_sizes.view',
        'item_colors.view',
        'item_decals.view',
        'item_models.view',
        'item_groups.view',
        'item_origin_countries.view',
    ]);

    $menu = app(MenuService::class)->getMenu($actor);
    $topLevelLabels = collect($menu)->pluck('label')->all();
    $basicData = collect($menu)->firstWhere('label', 'basic_data');
    $inventory = collect($menu)->firstWhere('label', 'inventory');
    $basicDataLabels = collect($basicData['children'])->pluck('label')->all();
    $inventoryLabels = collect($inventory['children'])->pluck('label')->all();
    $itemData = collect($inventory['children'])->firstWhere('label', 'item_data');
    $itemDataLabels = collect($itemData['children'])->pluck('label')->all();

    expect($topLevelLabels)
        ->toContain('basic_data')
        ->toContain('inventory')
        ->not->toContain('item_data')
        ->and($basicData)->not->toBeNull()
        ->and($inventory)->not->toBeNull()
        ->and($basicDataLabels)->toBe(['organization_setup'])
        ->and($inventoryLabels)->toBe(['item_data'])
        ->and($itemDataLabels)->toBe([
            'products',
            'item_categories',
            'item_units',
            'item_sizes',
            'item_colors',
            'item_decals',
            'item_models',
            'item_groups',
            'item_origin_countries',
        ]);
});

test('item lookup routes config and translations are registered', function (string $routePrefix, string $documentKey, string $prefix, string $translationFile, string $menuKey, string $englishMenu, string $arabicMenu) {
    foreach (['index', 'data', 'create', 'store', 'bulk-delete', 'document-number-settings.update', 'restore', 'clone', 'show', 'edit', 'update', 'destroy'] as $action) {
        expect(Route::has("admin.{$routePrefix}.{$action}"))->toBeTrue();
    }

    expect(config("document_numbers.{$documentKey}"))->toMatchArray([
        'prefix' => $prefix,
        'padding' => 5,
        'column' => 'doc_num',
        'number_column' => 'doc_number',
    ])
        ->and(is_file(lang_path("ar/{$translationFile}.php")))->toBeTrue()
        ->and(is_file(lang_path("en/{$translationFile}.php")))->toBeTrue()
        ->and(__("menu.{$menuKey}", locale: 'en'))->toBe($englishMenu)
        ->and(__("menu.{$menuKey}", locale: 'ar'))->toBe($arabicMenu);
})->with([
    ['item-colors', 'item_colors', 'Color-', 'item_colors', 'item_colors', 'Colors', 'الألوان'],
    ['item-decals', 'item_decals', 'Decal-', 'item_decals', 'item_decals', 'Patterns / Decals', 'النقشات/الديكالات'],
    ['item-origin-countries', 'item_origin_countries', 'Origin-', 'item_origin_countries', 'item_origin_countries', 'Country of Origin', 'بلد المنشأ'],
]);

test('item unit create update delete and restore use public document numbers', function () {
    $actor = itemLookupActor([
        'item_units.view',
        'item_units.create',
        'item_units.edit',
        'item_units.delete',
        'item_units.restore',
        'item_units.view_trashed',
        'item_units.clone',
    ]);

    $store = $this->actingAs($actor)
        ->postJson(route('admin.item-units.store'), [
            'name' => 'Kilogram',
            'status' => 'active',
            'notes' => 'Weight unit',
            'submit_action' => 'save_view',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    $docNum = $store['data']['doc_num'];

    expect($docNum)->toBe('Unit-00001')
        ->and(ItemUnit::query()->where('doc_num', $docNum)->where('name', 'Kilogram')->exists())->toBeTrue();

    $this->actingAs($actor)
        ->putJson(route('admin.item-units.update', $docNum), [
            'name' => 'Kilogram',
            'status' => 'inactive',
            'notes' => 'Updated unit',
            'submit_action' => 'save_edit',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ItemUnit::query()->where('doc_num', $docNum)->first()?->status)->toBe('inactive');

    $this->actingAs($actor)
        ->deleteJson(route('admin.item-units.destroy', $docNum))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ItemUnit::withTrashed()->where('doc_num', $docNum)->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.item-units.restore', $docNum))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ItemUnit::query()->where('doc_num', $docNum)->exists())->toBeTrue();
});

test('item unit forms render equivalence values through the shared numeric input', function () {
    $actor = itemLookupActor([
        'item_units.view',
        'item_units.edit',
        'item_units.clone',
    ]);
    $equivalentUnit = ItemUnit::query()->create([
        'company_id' => $this->itemLookupCompany->getKey(),
        'doc_number' => 97,
        'doc_num' => 'Unit-00097',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $unit = ItemUnit::query()->create([
        'company_id' => $this->itemLookupCompany->getKey(),
        'doc_number' => 98,
        'doc_num' => 'Unit-00098',
        'name' => 'Carton',
        'equivalent_value' => '1250.500000',
        'equivalent_unit_id' => $equivalentUnit->getKey(),
        'status' => 'active',
    ]);

    foreach (['edit', 'clone'] as $action) {
        $this->actingAs($actor)
            ->get(route("admin.item-units.{$action}", $unit->doc_num))
            ->assertOk()
            ->assertSee('data-numeric-input', false)
            ->assertSee('data-numeric-scale="6"', false)
            ->assertSee('data-numeric-allow-negative="false"', false)
            ->assertSee('value="1,250.5"', false)
            ->assertDontSee('id="equivalent_value" name="equivalent_value" type="number"', false);
    }

    $this->actingAs($actor)
        ->get(route('admin.item-units.show', $unit->doc_num))
        ->assertOk()
        ->assertSee('value="1,250.5"', false)
        ->assertSee('dir="ltr"', false);

    $this->actingAs($actor)
        ->withSession(['_old_input' => ['equivalent_value' => '2,500.500000']])
        ->get(route('admin.item-units.edit', $unit->doc_num))
        ->assertOk()
        ->assertSee('value="2,500.5"', false);
});

test('item unit equivalence dirty detection compares canonical decimal strings', function () {
    $actor = itemLookupActor(['item_units.edit']);
    $baseUnit = ItemUnit::query()->create([
        'company_id' => $this->itemLookupCompany->getKey(),
        'doc_number' => 95,
        'doc_num' => 'Unit-00095',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $unit = ItemUnit::query()->create([
        'company_id' => $this->itemLookupCompany->getKey(),
        'doc_number' => 96,
        'doc_num' => 'Unit-00096',
        'name' => 'Carton',
        'equivalent_value' => '2.500000',
        'equivalent_unit_id' => $baseUnit->getKey(),
        'status' => 'active',
    ]);
    $payload = [
        'name' => 'Carton',
        'status' => 'active',
        'notes' => null,
        'equivalent_unit_doc_num' => $baseUnit->doc_num,
        'submit_action' => 'save_edit',
    ];

    $this->actingAs($actor)
        ->putJson(route('admin.item-units.update', $unit->doc_num), [
            ...$payload,
            'equivalent_value' => '2.5',
        ])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('type', 'no_changes');

    $this->actingAs($actor)
        ->putJson(route('admin.item-units.update', $unit->doc_num), [
            ...$payload,
            'equivalent_value' => '2.500001',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect((string) $unit->refresh()->equivalent_value)->toBe('2.500001');

    $this->actingAs($actor)
        ->putJson(route('admin.item-units.update', $unit->doc_num), [
            ...$payload,
            'equivalent_value' => '1,2,3',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['equivalent_value']);
});

test('item color shared item lookup crud uses public document numbers and shared ui', function () {
    $actor = itemLookupActor([
        'item_colors.view',
        'item_colors.create',
        'item_colors.clone',
        'item_colors.edit',
        'item_colors.delete',
        'item_colors.view_trashed',
        'item_colors.restore',
        'item_colors.document_number.control',
        'item_colors.document_number_settings.update',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.item-colors.index'))
        ->assertOk()
        ->assertSee(__('item_colors.title'))
        ->assertSee(__('menu.inventory'))
        ->assertSee(route('admin.item-colors.data'), false)
        ->assertSee(route('admin.item-colors.bulk-delete'), false)
        ->assertSee(route('admin.item-colors.document-number-settings.update'), false)
        ->assertSee('js-item-lookup-table', false)
        ->assertSee('assets/js/modules/Core/item-lookups.js', false)
        ->assertSee('id="item_lookup_trash_filter"', false)
        ->assertDontSee('data-id=', false);

    $this->actingAs($actor)
        ->get(route('admin.item-colors.create'))
        ->assertOk()
        ->assertSee(__('item_colors.create'))
        ->assertSee('js-item-lookup-form', false);

    $store = $this->actingAs($actor)
        ->postJson(route('admin.item-colors.store'), [
            'name' => 'Red',
            'status' => 'active',
            'notes' => 'Primary item color',
            'submit_action' => 'save_view',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    $docNum = $store['data']['doc_num'];

    expect($docNum)->toBe('Color-00001')
        ->and(ItemColor::query()->where('doc_num', $docNum)->where('name', 'Red')->exists())->toBeTrue();

    $dataPayload = $this->actingAs($actor)
        ->getJson(route('admin.item-colors.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->json();

    $dataHtml = json_encode($dataPayload, JSON_THROW_ON_ERROR);

    expect($dataHtml)
        ->toContain('Color-00001')
        ->toContain('dropstart')
        ->toContain('js-delete-record')
        ->not->toContain('"code"')
        ->not->toContain('data-id=');

    $this->actingAs($actor)
        ->get(route('admin.item-colors.clone', $docNum))
        ->assertOk()
        ->assertSee(__('item_lookups.titles.clone'))
        ->assertSee('name="clone_source_token"', false);

    $this->actingAs($actor)
        ->putJson(route('admin.item-colors.update', $docNum), [
            'name' => 'Red Updated',
            'status' => 'inactive',
            'notes' => 'Updated item color',
            'submit_action' => 'save_edit',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ItemColor::query()->where('doc_num', $docNum)->first()?->status)->toBe('inactive');

    $this->actingAs($actor)
        ->deleteJson(route('admin.item-colors.destroy', $docNum))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ItemColor::withTrashed()->where('doc_num', $docNum)->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.item-colors.restore', $docNum))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ItemColor::query()->where('doc_num', $docNum)->exists())->toBeTrue();

    $this->actingAs($actor)
        ->putJson(route('admin.item-colors.document-number-settings.update'), [
            'prefix' => 'Clr-',
            'padding' => 4,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.prefix', 'Clr-')
        ->assertJsonPath('data.padding', 4);
});

test('item lookup create and update do not require a separate code field', function (string $routePrefix, string $permissionPrefix, string $modelClass, string $expectedDocNum) {
    $actor = itemLookupActor([
        "{$permissionPrefix}.view",
        "{$permissionPrefix}.create",
        "{$permissionPrefix}.edit",
    ]);

    $store = $this->actingAs($actor)
        ->postJson(route("admin.{$routePrefix}.store"), [
            'name' => 'Lookup Name',
            'status' => 'active',
            'notes' => 'Created without code',
            'submit_action' => 'save_edit',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json();

    $docNum = $store['data']['doc_num'];

    expect($docNum)->toBe($expectedDocNum)
        ->and($modelClass::query()->where('doc_num', $docNum)->where('name', 'Lookup Name')->exists())->toBeTrue();

    $this->actingAs($actor)
        ->putJson(route("admin.{$routePrefix}.update", $docNum), [
            'name' => 'Lookup Name Updated',
            'status' => 'inactive',
            'notes' => 'Updated without code',
            'submit_action' => 'save_edit',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($modelClass::query()->where('doc_num', $docNum)->first()?->name)->toBe('Lookup Name Updated');
})->with([
    ['item-units', 'item_units', ItemUnit::class, 'Unit-00001'],
    ['item-sizes', 'item_sizes', ItemSize::class, 'Size-00001'],
    ['item-colors', 'item_colors', ItemColor::class, 'Color-00001'],
    ['item-decals', 'item_decals', ItemDecal::class, 'Decal-00001'],
    ['item-models', 'item_models', ItemModel::class, 'Model-00001'],
    ['item-categories', 'item_categories', ItemCategory::class, 'Category-00001'],
    ['item-groups', 'item_groups', ItemGroup::class, 'Group-00001'],
    ['item-origin-countries', 'item_origin_countries', ItemOriginCountry::class, 'Origin-00001'],
]);

test('item lookup tables do not keep a separate code column', function () {
    foreach (['item_units', 'item_sizes', 'item_colors', 'item_decals', 'item_models', 'item_categories', 'item_groups', 'item_origin_countries'] as $tableName) {
        expect(Schema::hasColumn($tableName, 'code'))->toBeFalse()
            ->and(Schema::hasColumn($tableName, 'company_id'))->toBeTrue();
    }
});

test('item unit data table returns dropdown actions without internal ids', function () {
    $actor = itemLookupActor([
        'item_units.view',
        'item_units.edit',
        'item_units.delete',
        'item_units.clone',
    ]);

    ItemUnit::query()->create([
        'company_id' => $this->itemLookupCompany->getKey(),
        'doc_number' => 77,
        'doc_num' => 'Unit-00077',
        'name' => 'Piece',
        'status' => 'active',
    ]);

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.item-units.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->json();

    $html = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($html)
        ->toContain('Unit-00077')
        ->toContain('dropstart')
        ->toContain('js-delete-record')
        ->not->toContain('"code"')
        ->not->toContain('data-id=');
});

test('item unit select2 endpoint returns active units by public document number', function () {
    $actor = itemLookupActor(['branches.create']);

    ItemUnit::query()->create([
        'company_id' => $this->itemLookupCompany->getKey(),
        'doc_number' => 95,
        'doc_num' => 'Unit-00095',
        'name' => 'Ton',
        'status' => 'active',
    ]);
    ItemUnit::query()->create([
        'company_id' => $this->itemLookupCompany->getKey(),
        'doc_number' => 96,
        'doc_num' => 'Unit-00096',
        'name' => 'Old Unit',
        'status' => 'inactive',
    ]);

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.select2.item-units', ['q' => 'ton']))
        ->assertOk()
        ->json();

    expect($payload['results'])
        ->toHaveCount(1)
        ->and($payload['results'][0]['id'])->toBe('Unit-00095')
        ->and($payload['results'][0]['text'])->toContain('Ton')
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Unit-00096');
});

test('item unit validation keeps name unique among active records', function () {
    $actor = itemLookupActor(['item_units.create']);

    ItemUnit::query()->create([
        'company_id' => $this->itemLookupCompany->getKey(),
        'doc_number' => 88,
        'doc_num' => 'Unit-00088',
        'name' => 'Box',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.item-units.store'), [
            'name' => 'Box',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('item lookups are scoped by operating company for creation validation datatable and routes', function () {
    $actor = itemLookupActor([
        'item_units.view',
        'item_units.create',
        'item_units.edit',
    ]);
    $companyA = $this->itemLookupCompany;

    $createdA = $this->actingAs($actor)
        ->postJson(route('admin.item-units.store'), [
            'name' => 'Shared Unit',
            'status' => 'active',
        ])
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)
        ->postJson(route('admin.item-units.store'), [
            'name' => 'Shared Unit',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $companyB = itemLookupOperatingContext($this);

    $createdB = $this->actingAs($actor)
        ->postJson(route('admin.item-units.store'), [
            'name' => 'Shared Unit',
            'status' => 'active',
        ])
        ->assertOk()
        ->json('data.doc_num');

    expect($createdA)->toBe('Unit-00001')
        ->and($createdB)->toBe('Unit-00001')
        ->and(ItemUnit::query()->where('company_id', $companyA->getKey())->where('name', 'Shared Unit')->exists())->toBeTrue()
        ->and(ItemUnit::query()->where('company_id', $companyB->getKey())->where('name', 'Shared Unit')->exists())->toBeTrue();

    $this->actingAs($actor)
        ->putJson(route('admin.item-units.update', $createdB), [
            'name' => 'Shared Unit B',
            'status' => 'active',
            'submit_action' => 'save_edit',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ItemUnit::query()->where('company_id', $companyA->getKey())->where('doc_num', 'Unit-00001')->first()?->name)->toBe('Shared Unit')
        ->and(ItemUnit::query()->where('company_id', $companyB->getKey())->where('doc_num', 'Unit-00001')->first()?->name)->toBe('Shared Unit B');

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.item-units.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->json();

    $html = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($html)
        ->toContain('Shared Unit B')
        ->not->toContain('Shared Unit /');
});

test('new item lookup resources are company scoped and allow duplicate names across companies', function (string $routePrefix, string $permissionPrefix, string $modelClass, string $expectedDocNum) {
    $actor = itemLookupActor([
        "{$permissionPrefix}.view",
        "{$permissionPrefix}.create",
    ]);
    $companyA = $this->itemLookupCompany;

    $createdA = $this->actingAs($actor)
        ->postJson(route("admin.{$routePrefix}.store"), [
            'name' => 'Shared Lookup Name',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.doc_num', $expectedDocNum)
        ->json('data.doc_num');

    $this->actingAs($actor)
        ->postJson(route("admin.{$routePrefix}.store"), [
            'name' => 'Shared Lookup Name',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $companyB = itemLookupOperatingContext($this);

    $createdB = $this->actingAs($actor)
        ->postJson(route("admin.{$routePrefix}.store"), [
            'name' => 'Shared Lookup Name',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.doc_num', $expectedDocNum)
        ->json('data.doc_num');

    expect($createdA)->toBe($expectedDocNum)
        ->and($createdB)->toBe($expectedDocNum)
        ->and($modelClass::query()->where('company_id', $companyA->getKey())->where('name', 'Shared Lookup Name')->exists())->toBeTrue()
        ->and($modelClass::query()->where('company_id', $companyB->getKey())->where('name', 'Shared Lookup Name')->exists())->toBeTrue();

    $payload = $this->actingAs($actor)
        ->getJson(route("admin.{$routePrefix}.data", [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->json();

    expect(json_encode($payload, JSON_THROW_ON_ERROR))
        ->toContain('Shared Lookup Name')
        ->not->toContain((string) $companyA->doc_num)
        ->not->toContain('data-id=');
})->with([
    ['item-decals', 'item_decals', ItemDecal::class, 'Decal-00001'],
    ['item-origin-countries', 'item_origin_countries', ItemOriginCountry::class, 'Origin-00001'],
]);
