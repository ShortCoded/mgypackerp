<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\MenuConfigFileOrder;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function openingStockActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function openingStockContext(object $test, string $branchType = Branch::TypeWarehouse, ?Company $company = null): array
{
    static $number = 7100;

    $number++;
    $company ??= Company::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Company-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Inventory Company '.$number,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);

    $branch = Branch::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Branch-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Inventory Branch '.$number,
        'type' => $branchType,
        'status' => 'active',
    ]);

    $period = FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Inventory Period '.$number,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    openingStockSelectContext($test, $company, $branch, $period);

    return compact('company', 'branch', 'period');
}

function openingStockSelectContext(object $test, Company $company, Branch $branch, FinancialPeriod $period): void
{
    $test->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);
}

function openingStockUnit(Company $company, ?string $name = null): ItemUnit
{
    static $number = 8100;

    $number++;

    return ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'Unit-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => $name ?? 'Piece '.$number,
        'status' => 'active',
    ]);
}

function openingStockProduct(Company $company, array $overrides = []): Product
{
    static $number = 9100;

    $number++;
    $unit = $overrides['unit'] ?? openingStockUnit($company);
    unset($overrides['unit']);

    return Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'Product-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Opening Stock Product '.$number,
        'barcode' => 'BC'.$number,
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(),
        'cost_as_inventory' => true,
        'status' => 'active',
        ...$overrides,
    ]);
}

function openingStockPayload(Product $product, array $overrides = []): array
{
    return [
        'document_date' => '2026-02-01',
        'notes' => 'Opening quantities only',
        'submit_action' => 'save',
        'lines' => [
            ['product_doc_num' => $product->doc_num, 'quantity' => '5.5000', 'notes' => 'Initial'],
        ],
        ...$overrides,
    ];
}

function openingStockRecord(Company $company, FinancialPeriod $period, Branch $branch, Product $product, int $docNumber, array $overrides = []): OpeningStock
{
    $record = OpeningStock::query()->create([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'doc_number' => $docNumber,
        'doc_num' => 'OS-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'document_date' => '2026-02-01',
        'is_closed' => true,
        'status' => OpeningStock::StatusClosed,
        'approved' => false,
        ...$overrides,
    ]);

    OpeningStockLine::query()->create([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'opening_stock_id' => $record->getKey(),
        'line_no' => 1,
        'product_id' => $product->getKey(),
        'product_snapshot' => [
            'doc_num' => $product->doc_num,
            'name' => $product->name,
            'unit_label' => $product->unit?->name,
        ],
        'quantity' => '3.0000',
    ]);

    return $record;
}

function openingStockDataTablePayload(): array
{
    $columns = ['checkbox', 'doc_num', 'document_date', 'branch', 'hall', 'lines_count', 'total_quantity', 'document_status', 'approval_status', 'approved_by', 'approved_at', 'created_by', 'created_at', 'updated_by', 'updated_at', 'actions'];

    return [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => '', 'regex' => false],
        'order' => [['column' => 1, 'dir' => 'desc']],
        'columns' => array_map(fn (string $column): array => [
            'data' => $column,
            'name' => $column,
            'searchable' => true,
            'orderable' => ! in_array($column, ['checkbox', 'actions'], true),
            'search' => ['value' => '', 'regex' => false],
        ], $columns),
    ];
}

beforeEach(function (): void {
    Storage::fake('public');
});

test('inventory menu is ordered after purchases and opening stock routes exist', function (): void {
    $files = array_map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), app(MenuConfigFileOrder::class)->files());

    expect($files)->toContain('purchases')
        ->and($files)->toContain('inventory')
        ->and(array_search('inventory', $files, true))->toBeGreaterThan(array_search('purchases', $files, true))
        ->and(array_search('inventory', $files, true))->toBeLessThan(array_search('tools', $files, true));

    foreach (['index', 'data', 'create', 'store', 'show', 'edit', 'update', 'destroy', 'restore', 'approve'] as $action) {
        expect(Route::has("admin.inventory.opening-stocks.{$action}"))->toBeTrue();
    }
});

test('opening stock schema is quantity only and has expected master detail tables', function (): void {
    foreach (['unit_id', 'category_id', 'group_id', 'size_id', 'color_id', 'model_id', 'item_category_id', 'item_group_id', 'item_size_id', 'item_color_id', 'item_model_id'] as $snapshotColumn) {
        expect(Schema::hasColumn('inventory_opening_stock_lines', $snapshotColumn))->toBeFalse();
    }

    expect(Schema::hasTable('inventory_opening_stocks'))->toBeTrue()
        ->and(Schema::hasTable('inventory_opening_stock_lines'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_opening_stocks', 'company_id'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_opening_stocks', 'financial_period_id'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_opening_stocks', 'branch_id'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_opening_stock_lines', 'quantity'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_opening_stock_lines', 'product_snapshot'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_opening_stock_lines', 'unit_cost'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_opening_stock_lines', 'line_total'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_opening_stocks', 'total_value'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_opening_stocks', 'inventory_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_opening_stocks', 'credit_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_opening_stocks', 'journal_entry_id'))->toBeFalse();
});

test('opening stock permissions are discoverable from the inventory menu config', function (): void {
    $this->seed(PermissionSeeder::class);

    foreach (['view', 'create', 'clone', 'edit', 'delete', 'view_trashed', 'restore', 'approve', 'document_number.control', 'document_number_settings.update'] as $action) {
        expect(Permission::query()->where('name', "inventory.opening_stocks.{$action}")->exists())->toBeTrue();
    }
});

test('opening stock can only be created from warehouse or factory branches', function (): void {
    $context = openingStockContext($this, Branch::TypeShowroom);
    $actor = openingStockActor(['inventory.opening_stocks.view', 'inventory.opening_stocks.create']);
    $product = openingStockProduct($context['company']);

    $this->actingAs($actor)
        ->get(route('admin.inventory.opening-stocks.create'))
        ->assertForbidden();

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_id']);

    $factory = openingStockContext($this, Branch::TypeFactory, $context['company']);
    $product = openingStockProduct($factory['company']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product))
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('opening stock save uses operating company period branch and closes new documents', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.create', 'inventory.opening_stocks.view']);
    $unit = openingStockUnit($context['company'], 'Carton');
    $untrustedUnit = openingStockUnit($context['company'], 'Untrusted');
    $product = openingStockProduct($context['company'], ['unit' => $unit]);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, [
            'unit_id' => $untrustedUnit->getKey(),
            'branch_id' => 999999,
            'lines' => [
                ['product_doc_num' => $product->doc_num, 'unit_id' => $untrustedUnit->getKey(), 'quantity' => '12.7500', 'product_snapshot' => ['name' => 'Client Fake']],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('redirect', route('admin.inventory.opening-stocks.create'));

    $record = OpeningStock::query()->where('doc_num', 'OS-00001')->firstOrFail();
    $line = $record->lines()->firstOrFail();

    expect($record->company_id)->toBe($context['company']->getKey())
        ->and($record->financial_period_id)->toBe($context['period']->getKey())
        ->and($record->branch_id)->toBe($context['branch']->getKey())
        ->and($record->is_closed)->toBeTrue()
        ->and($record->status)->toBe(OpeningStock::StatusClosed)
        ->and($line->product_snapshot['doc_num'])->toBe($product->doc_num)
        ->and($line->product_snapshot['name'])->toBe($product->name)
        ->and($line->product_snapshot['unit_label'])->toContain('Carton')
        ->and($line->product_snapshot['item_classification'])->toBe(__('products.classifications.'.Product::ClassificationFinishedProduct))
        ->and($line->product_snapshot)->not->toHaveKeys(['id', 'product_id', 'company_id'])
        ->and($line->product_snapshot['name'])->not->toBe('Client Fake')
        ->and((string) $line->quantity)->toBe('12.7500');
});

test('opening stock product snapshot is preserved on quantity edits and regenerated when product changes', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.create', 'inventory.opening_stocks.edit']);
    $firstProduct = openingStockProduct($context['company'], ['name' => 'Snapshot First Product']);
    $secondProduct = openingStockProduct($context['company'], ['name' => 'Snapshot Second Product']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($firstProduct))
        ->assertOk();

    $record = OpeningStock::query()->firstOrFail();
    $line = $record->lines()->firstOrFail();
    $lineId = $line->getKey();
    $publicId = $line->public_id;

    $firstProduct->forceFill(['name' => 'Changed Current Product Name'])->save();
    $record->forceFill(['is_closed' => false, 'status' => OpeningStock::StatusDraft])->save();

    $this->actingAs($actor)
        ->putJson(route('admin.inventory.opening-stocks.update', $record->doc_num), openingStockPayload($firstProduct, [
            'lines' => [
                ['public_id' => $publicId, 'product_doc_num' => $firstProduct->doc_num, 'quantity' => '8.0000', 'notes' => 'Quantity only edit'],
            ],
        ]))
        ->assertOk();

    $line->refresh();

    expect($line->getKey())->toBe($lineId)
        ->and($line->product_snapshot['name'])->toBe('Snapshot First Product')
        ->and((string) $line->quantity)->toBe('8.0000');

    $record->refresh()->forceFill(['is_closed' => false, 'status' => OpeningStock::StatusDraft])->save();

    $this->actingAs($actor)
        ->putJson(route('admin.inventory.opening-stocks.update', $record->doc_num), openingStockPayload($secondProduct, [
            'lines' => [
                ['public_id' => $publicId, 'product_doc_num' => $secondProduct->doc_num, 'quantity' => '2.0000'],
            ],
        ]))
        ->assertOk();

    $line->refresh();

    expect($line->product_id)->toBe($secondProduct->getKey())
        ->and($line->product_snapshot['doc_num'])->toBe($secondProduct->doc_num)
        ->and($line->product_snapshot['name'])->toBe('Snapshot Second Product');
});

test('document numbers are scoped by company and period', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.create', 'inventory.opening_stocks.document_number.control']);
    $product = openingStockProduct($context['company']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, ['doc_number' => 77]))
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, ['doc_number' => 77]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['doc_number']);

    $secondPeriod = FinancialPeriod::query()->create([
        'doc_number' => 9998,
        'doc_num' => 'Period-09998',
        'company_id' => $context['company']->getKey(),
        'name' => 'Second Period',
        'from_date' => '2027-01-01',
        'to_date' => '2027-12-31',
        'is_closed' => false,
    ]);

    openingStockSelectContext($this, $context['company'], $context['branch'], $secondPeriod);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, ['document_date' => '2027-02-01', 'doc_number' => 77]))
        ->assertOk();

    expect(OpeningStock::query()->where('doc_num', 'OS-00077')->count())->toBe(2);
});

test('document date must be inside the selected financial period', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.create']);
    $product = openingStockProduct($context['company']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, ['document_date' => '2027-01-01']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_date']);
});

test('detail validation rejects empty duplicate cross company and nonpositive quantity lines', function (): void {
    $context = openingStockContext($this);
    $otherContext = openingStockContext($this, Branch::TypeWarehouse);
    openingStockSelectContext($this, $context['company'], $context['branch'], $context['period']);

    $actor = openingStockActor(['inventory.opening_stocks.create']);
    $product = openingStockProduct($context['company']);
    $otherProduct = openingStockProduct($otherContext['company']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, ['lines' => []]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, [
            'lines' => [
                ['product_doc_num' => $product->doc_num, 'quantity' => '1'],
                ['product_doc_num' => $product->doc_num, 'quantity' => '2'],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.1.product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, [
            'lines' => [['product_doc_num' => $otherProduct->doc_num, 'quantity' => '1']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.product_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, [
            'lines' => [['product_doc_num' => $product->doc_num, 'quantity' => '0']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.quantity']);
});

test('hall is factory only and must belong to the current operating branch', function (): void {
    $context = openingStockContext($this, Branch::TypeFactory);
    $otherBranch = Branch::query()->create([
        'doc_number' => 9997,
        'doc_num' => 'Branch-09997',
        'company_id' => $context['company']->getKey(),
        'name' => 'Other Warehouse',
        'type' => Branch::TypeWarehouse,
        'status' => 'active',
    ]);
    $hall = BranchHall::query()->create(['branch_id' => $otherBranch->getKey(), 'name' => 'Other Hall', 'position' => 1]);
    $actor = openingStockActor(['inventory.opening_stocks.create']);
    $product = openingStockProduct($context['company']);
    $currentHall = BranchHall::query()->create(['branch_id' => $context['branch']->getKey(), 'name' => 'Factory Hall', 'position' => 1]);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, ['branch_hall_uuid' => $currentHall->public_uuid]))
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, ['branch_hall_uuid' => $hall->public_uuid]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_hall_uuid']);

    $warehouse = openingStockContext($this, Branch::TypeWarehouse, $context['company']);
    $product = openingStockProduct($warehouse['company']);
    $warehouseHall = BranchHall::query()->create(['branch_id' => $warehouse['branch']->getKey(), 'name' => 'Warehouse Hall', 'position' => 1]);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product, ['branch_hall_uuid' => $warehouseHall->public_uuid]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_hall_uuid']);
});

test('index datatable is scoped to current company period and branch and exposes configured columns only', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.view']);
    $product = openingStockProduct($context['company']);
    $visible = openingStockRecord($context['company'], $context['period'], $context['branch'], $product, 1);

    $otherBranch = Branch::query()->create([
        'doc_number' => 9001,
        'doc_num' => 'Branch-09001',
        'company_id' => $context['company']->getKey(),
        'name' => 'Other Branch',
        'type' => Branch::TypeWarehouse,
        'status' => 'active',
    ]);
    openingStockRecord($context['company'], $context['period'], $otherBranch, $product, 2);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.inventory.opening-stocks.data', openingStockDataTablePayload()))
        ->assertOk()
        ->json();

    expect($response['data'])->toHaveCount(1)
        ->and($response['data'][0]['doc_num'])->toContain($visible->doc_num)
        ->and($response['data'][0])->toHaveKeys(['checkbox', 'doc_num', 'document_date', 'branch', 'hall', 'lines_count', 'total_quantity', 'document_status', 'approval_status', 'created_by', 'created_at', 'updated_by', 'updated_at', 'actions'])
        ->and($response['data'][0]['branch'])->toContain('dt-ellipsis-content')
        ->and($response['data'][0]['branch'])->not->toContain('&lt;span')
        ->and($response['data'][0]['can_edit'])->toBeFalse()
        ->and($response['data'][0])->not->toHaveKeys(['id', 'company_id', 'financial_period_id', 'branch_id', 'product_id']);
});

test('closed opening stock cannot be edited or deleted but can be cloned', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor([
        'inventory.opening_stocks.view',
        'inventory.opening_stocks.edit',
        'inventory.opening_stocks.delete',
        'inventory.opening_stocks.clone',
    ]);
    $product = openingStockProduct($context['company']);
    $record = openingStockRecord($context['company'], $context['period'], $context['branch'], $product, 6);

    $this->actingAs($actor)
        ->get(route('admin.inventory.opening-stocks.edit', $record->doc_num))
        ->assertForbidden();

    $this->actingAs($actor)
        ->putJson(route('admin.inventory.opening-stocks.update', $record->doc_num), openingStockPayload($product))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->deleteJson(route('admin.inventory.opening-stocks.destroy', $record->doc_num))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->get(route('admin.inventory.opening-stocks.clone', $record->doc_num))
        ->assertOk()
        ->assertSee(__('common.actions.save_data'))
        ->assertSee('js-opening-stock-product', false);
});

test('opening stock form renders image select2 grid modal and inline errors', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.view', 'inventory.opening_stocks.create', 'inventory.opening_stocks.document_number.control']);

    $this->actingAs($actor)
        ->get(route('admin.inventory.opening-stocks.create'))
        ->assertOk()
        ->assertSee(__('inventory.opening_stocks.title'))
        ->assertSee('js-opening-stock-form', false)
        ->assertSee('form-control text-center js-date-picker', false)
        ->assertSee('opening-stock-product-picker', false)
        ->assertSee('data-template="product-image"', false)
        ->assertSee('js-opening-stock-product-info', false)
        ->assertDontSee('js-opening-stock-product-create', false)
        ->assertDontSee('for="current_branch"', false)
        ->assertDontSee('id="current_branch"', false)
        ->assertDontSee('name="branch_id"', false)
        ->assertDontSee('for="branch_hall_uuid"', false)
        ->assertDontSee('name="branch_hall_uuid"', false)
        ->assertSee('opening-stock-product-info-modal', false)
        ->assertSee('data-error-for="document_date"', false)
        ->assertSee('data-error-for="lines.__INDEX__.product_doc_num"', false);

    $factory = openingStockContext($this, Branch::TypeFactory, $context['company']);

    $this->actingAs($actor)
        ->get(route('admin.inventory.opening-stocks.create'))
        ->assertOk()
        ->assertSee('for="branch_hall_uuid"', false)
        ->assertSee('name="branch_hall_uuid"', false)
        ->assertSee(route('admin.inventory.select2.branch-halls'), false);

    $creator = openingStockActor(['inventory.opening_stocks.view', 'inventory.opening_stocks.create', 'products.create']);
    openingStockSelectContext($this, $factory['company'], $factory['branch'], $factory['period']);

    $this->actingAs($creator)
        ->get(route('admin.inventory.opening-stocks.create'))
        ->assertOk()
        ->assertSee('js-opening-stock-product-create', false)
        ->assertSee('target="_blank"', false)
        ->assertSee(route('admin.products.create'), false)
        ->assertSee(__('inventory.opening_stocks.js.product_create_title'));
});

test('opening stock javascript keeps datatable columns and dynamic row handlers aligned', function (): void {
    $script = file_get_contents(public_path('assets/js/modules/Inventory/opening-stocks.js'));
    $view = file_get_contents(resource_path('views/modules/inventory/opening-stocks/index.blade.php'));

    foreach (['doc_num', 'document_date', 'branch', 'hall', 'lines_count', 'total_quantity', 'document_status', 'approval_status', 'approved_by', 'approved_at', 'created_by', 'created_at', 'updated_by', 'updated_at'] as $column) {
        expect($script)->toContain($column)
            ->and($view)->toContain("'{$column}'");
    }

    expect($script)->toContain('js-opening-stock-add-line')
        ->and($script)->toContain('js-opening-stock-remove-line')
        ->and($script)->toContain('js-opening-stock-duplicate-line')
        ->and($script)->toContain('dblclick.openingStocksEditRow')
        ->and($script)->toContain('js-opening-stock-product-create')
        ->and($script)->toContain("['KeyI']")
        ->and($script)->toContain("['KeyP']")
        ->and($script)->toContain('AppSelect2Ajax')
        ->and($script)->toContain('productData');
});

test('product select2 returns active current company non service products only', function (): void {
    $context = openingStockContext($this);
    $otherContext = openingStockContext($this, Branch::TypeWarehouse);
    openingStockSelectContext($this, $context['company'], $context['branch'], $context['period']);

    $actor = openingStockActor(['inventory.opening_stocks.view']);
    $valid = openingStockProduct($context['company'], [
        'name' => 'Selectable Finished Product',
        'cost_as_inventory' => false,
    ]);
    $service = openingStockProduct($context['company'], [
        'name' => 'Hidden Service Product',
        'item_classification' => Product::ClassificationService,
        'cost_as_inventory' => true,
    ]);
    $inactive = openingStockProduct($context['company'], [
        'name' => 'Hidden Inactive Product',
        'status' => 'inactive',
    ]);
    $otherCompany = openingStockProduct($otherContext['company'], ['name' => 'Hidden Other Company Product']);

    $results = $this->actingAs($actor)
        ->getJson(route('admin.inventory.select2.opening-stock-products', ['q' => 'Product', 'per_page' => 50]))
        ->assertOk()
        ->json('results');

    $ids = collect($results)->pluck('id')->all();

    expect($ids)->toContain($valid->doc_num)
        ->and($ids)->not->toContain($service->doc_num)
        ->and($ids)->not->toContain($inactive->doc_num)
        ->and($ids)->not->toContain($otherCompany->doc_num);
});

test('product select2 searches barcode returns image url unit metadata and safe product details', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.view']);
    Storage::disk('public')->put('products/opening-stock.jpg', 'image');
    $unit = openingStockUnit($context['company'], 'Box');
    $product = openingStockProduct($context['company'], [
        'unit' => $unit,
        'name' => 'Barcode Item',
        'barcode' => 'SCAN-OPEN-1',
        'reorder_point' => '1250.5000',
        'image_path' => 'products/opening-stock.jpg',
    ]);

    $result = $this->actingAs($actor)
        ->getJson(route('admin.inventory.select2.opening-stock-products', ['q' => 'SCAN-OPEN-1']))
        ->assertOk()
        ->json('results.0');

    expect($result['id'])->toBe($product->doc_num)
        ->and($result['text'])->toContain('SCAN-OPEN-1')
        ->and($result['imageUrl'])->toContain('products/opening-stock.jpg')
        ->and($result['unitLabel'])->toContain('Box')
        ->and($result['productData'])->toHaveKeys(['doc_num', 'name', 'barcode', 'unit', 'imageUrl'])
        ->and($result['productData'])->not->toHaveKeys(['id', 'company_id', 'product_id']);

    $details = $this->actingAs($actor)
        ->getJson(route('admin.inventory.products.details', $product->doc_num))
        ->assertOk()
        ->json('data');

    expect($details['doc_num'])->toBe($product->doc_num)
        ->and($details['barcode'])->toBe('SCAN-OPEN-1')
        ->and($details['reorder_point'])->toBe('1,250.5')
        ->and($details)->not->toHaveKeys(['id', 'company_id']);
});

test('opening stock accepts non service products and rejects services', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.create']);
    $valid = openingStockProduct($context['company'], [
        'name' => 'Non Costed Inventory Product',
        'cost_as_inventory' => false,
    ]);
    $service = openingStockProduct($context['company'], [
        'name' => 'Service Opening Stock Product',
        'item_classification' => Product::ClassificationService,
        'cost_as_inventory' => true,
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($valid))
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($service))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.product_doc_num']);
});

test('approval closes confirms quantities without journals and locks later changes', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.create', 'inventory.opening_stocks.edit', 'inventory.opening_stocks.delete', 'inventory.opening_stocks.approve']);
    $product = openingStockProduct($context['company']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.store'), openingStockPayload($product))
        ->assertOk();

    $record = OpeningStock::query()->firstOrFail();
    $journalCount = Schema::hasTable('journal_entries') ? JournalEntry::query()->count() : 0;

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.approve', $record->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $record->refresh();

    expect($record->approved)->toBeTrue()
        ->and($record->approved_by)->toBe($actor->getKey())
        ->and($record->approved_at)->not->toBeNull()
        ->and($record->status)->toBe(OpeningStock::StatusApproved)
        ->and(Schema::hasColumn('inventory_opening_stocks', 'journal_entry_id'))->toBeFalse();

    if (Schema::hasTable('journal_entries')) {
        expect(JournalEntry::query()->count())->toBe($journalCount);
    }

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stocks.approve', $record->doc_num))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->putJson(route('admin.inventory.opening-stocks.update', $record->doc_num), openingStockPayload($product))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->deleteJson(route('admin.inventory.opening-stocks.destroy', $record->doc_num))
        ->assertStatus(422);
});

test('editing a reopened document saves it closed again', function (): void {
    $context = openingStockContext($this);
    $actor = openingStockActor(['inventory.opening_stocks.edit']);
    $product = openingStockProduct($context['company']);
    $record = openingStockRecord($context['company'], $context['period'], $context['branch'], $product, 8, [
        'is_closed' => false,
        'status' => OpeningStock::StatusDraft,
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.inventory.opening-stocks.update', $record->doc_num), openingStockPayload($product, ['notes' => 'Edited']))
        ->assertOk();

    $record->refresh();

    expect($record->is_closed)->toBeTrue()
        ->and($record->status)->toBe(OpeningStock::StatusClosed)
        ->and($record->notes)->toBe('Edited');
});
