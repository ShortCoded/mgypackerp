<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\MenuConfigFileOrder;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryOpeningStockPostingService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function openingStockPricingActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function openingStockPricingContext(object $test, string $branchType = Branch::TypeWarehouse, ?Company $company = null): array
{
    static $number = 12100;

    $number++;
    $company ??= Company::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Company-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Pricing Company '.$number,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);

    $branch = Branch::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Branch-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Pricing Branch '.$number,
        'type' => $branchType,
        'status' => 'active',
    ]);

    $period = FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Pricing Period '.$number,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    openingStockPricingSelectContext($test, $company, $branch, $period);

    return compact('company', 'branch', 'period');
}

function openingStockPricingSelectContext(object $test, Company $company, Branch $branch, FinancialPeriod $period): void
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

function openingStockPricingCurrency(Company $company, bool $main = true): Currency
{
    static $number = 13100;

    $number++;

    return Currency::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'Currency-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => $main ? 'Egyptian Pound' : 'Dollar',
        'code' => $main ? 'EGP' : 'USD',
        'is_main' => $main,
        'status' => 'active',
    ]);
}

function openingStockPricingProduct(Company $company, array $overrides = []): Product
{
    static $number = 14100;

    $number++;
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'Unit-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Piece '.$number,
        'status' => 'active',
    ]);

    return Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $number,
        'doc_num' => 'Product-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Pricing Product '.$number,
        'barcode' => 'PRICE-'.$number,
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
        ...$overrides,
    ]);
}

function openingStockPricingOpeningStock(Company $company, FinancialPeriod $period, Branch $branch, array $products, int $docNumber = 1, ?BranchHall $hall = null): OpeningStock
{
    $record = OpeningStock::query()->create([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'branch_hall_id' => $hall?->getKey(),
        'doc_number' => $docNumber,
        'doc_num' => 'OS-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'document_date' => '2026-02-01',
        'is_closed' => true,
        'status' => OpeningStock::StatusApproved,
        'approved' => true,
    ]);

    foreach (array_values($products) as $index => $product) {
        OpeningStockLine::query()->create([
            'company_id' => $company->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $branch->getKey(),
            'opening_stock_id' => $record->getKey(),
            'line_no' => $index + 1,
            'product_id' => $product->getKey(),
            'product_snapshot' => [
                'doc_num' => $product->doc_num,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'unit_label' => $product->unit?->name,
                'image_url' => $product->image_path ? Storage::disk('public')->url($product->image_path) : null,
            ],
            'quantity' => number_format($index + 2, 4, '.', ''),
        ]);
    }

    return $record;
}

function openingStockPricingPayload(OpeningStock $openingStock, Currency $currency, array $overrides = []): array
{
    return [
        'document_date' => '2026-03-01',
        'branch_doc_num' => $openingStock->branch?->doc_num,
        'branch_hall_uuid' => $openingStock->branchHall?->public_uuid,
        'opening_stock_doc_num' => $openingStock->doc_num,
        'currency_doc_num' => $currency->doc_num,
        'exchange_rate' => $currency->is_main ? '1' : '50',
        'notes' => 'Pricing only',
        'submit_action' => 'save',
        'lines' => $openingStock->lines->map(fn (OpeningStockLine $line): array => [
            'opening_stock_line_public_id' => $line->public_id,
            'unit_price' => '10.5000',
            'quantity' => '9999',
            'line_total' => '999999',
            'product_snapshot' => ['name' => 'Client Fake'],
            'notes' => 'Priced',
        ])->values()->all(),
        ...$overrides,
    ];
}

function openingStockPricingDataTablePayload(): array
{
    $columns = ['checkbox', 'doc_num', 'document_date', 'branch', 'hall', 'opening_stock_doc_num', 'currency', 'exchange_rate', 'total_amount', 'status', 'lines_count', 'created_by', 'created_at', 'updated_by', 'updated_at', 'actions'];

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

test('opening stock pricing menu route permissions and schema exist', function (): void {
    $files = array_map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), app(MenuConfigFileOrder::class)->files());

    expect($files)->toContain('inventory')
        ->and(array_search('inventory', $files, true))->toBeGreaterThan(array_search('purchases', $files, true))
        ->and(Route::has('admin.inventory.opening-stock-pricings.index'))->toBeTrue()
        ->and(Route::has('admin.inventory.opening-stock-pricings.store'))->toBeTrue()
        ->and(Schema::hasTable('inventory_opening_stock_pricings'))->toBeTrue()
        ->and(Schema::hasTable('inventory_opening_stock_pricing_lines'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_opening_stock_pricing_lines', 'product_snapshot'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_opening_stock_pricing_lines', 'unit_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_opening_stock_pricings', 'journal_entry_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_opening_stock_pricings', 'inventory_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_opening_stock_pricings', 'credit_account_id'))->toBeFalse();

    $this->seed(PermissionSeeder::class);

    foreach (['view', 'create', 'clone', 'edit', 'delete', 'view_trashed', 'restore', 'document_number.control', 'document_number_settings.update'] as $action) {
        expect(Permission::query()->where('name', "inventory.opening_stock_pricings.{$action}")->exists())->toBeTrue();
    }
});

test('opening stock pricing index is scoped by current company and period but not current branch', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.view', 'inventory.opening_stock_pricings.create']);
    $currency = openingStockPricingCurrency($context['company']);
    $firstProduct = openingStockPricingProduct($context['company']);
    $secondProduct = openingStockPricingProduct($context['company']);
    $visible = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$firstProduct], 11);

    $otherBranch = Branch::query()->create([
        'doc_number' => 16001,
        'doc_num' => 'Branch-16001',
        'company_id' => $context['company']->getKey(),
        'name' => 'Other Warehouse',
        'type' => Branch::TypeWarehouse,
        'status' => 'active',
    ]);
    $alsoVisible = openingStockPricingOpeningStock($context['company'], $context['period'], $otherBranch, [$secondProduct], 12);

    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($visible, $currency))->assertOk();
    openingStockPricingSelectContext($this, $context['company'], $otherBranch, $context['period']);
    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($alsoVisible, $currency))->assertOk();
    openingStockPricingSelectContext($this, $context['company'], $context['branch'], $context['period']);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.inventory.opening-stock-pricings.data', openingStockPricingDataTablePayload()))
        ->assertOk()
        ->json();

    expect($response['data'])->toHaveCount(2)
        ->and($response['data'][0])->toHaveKeys(['checkbox', 'doc_num', 'document_date', 'branch', 'hall', 'opening_stock_doc_num', 'currency', 'exchange_rate', 'total_amount', 'status', 'lines_count', 'created_by', 'created_at', 'updated_by', 'updated_at', 'actions'])
        ->and($response['data'][0]['opening_stock_doc_num'])->toContain('OS-')
        ->and($response['data'][0]['opening_stock_doc_num'])->toContain('2026')
        ->and($response['data'][0]['branch'])->not->toContain('&lt;span')
        ->and($response['data'][0]['can_edit'])->toBeFalse()
        ->and($response['data'][0])->not->toHaveKeys(['id', 'company_id', 'financial_period_id', 'branch_id', 'currency_id']);
});

test('pricing branch hall opening stock and line selectors are scoped and image aware', function (): void {
    $context = openingStockPricingContext($this, Branch::TypeFactory);
    $other = openingStockPricingContext($this, Branch::TypeWarehouse);
    openingStockPricingSelectContext($this, $context['company'], $context['branch'], $context['period']);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.view']);
    $showroom = Branch::query()->create([
        'doc_number' => 16002,
        'doc_num' => 'Branch-16002',
        'company_id' => $context['company']->getKey(),
        'name' => 'Showroom Hidden',
        'type' => Branch::TypeShowroom,
        'status' => 'active',
    ]);
    $hall = BranchHall::query()->create(['branch_id' => $context['branch']->getKey(), 'name' => 'Factory Hall', 'position' => 1]);
    Storage::disk('public')->put('products/pricing.jpg', 'image');
    $product = openingStockPricingProduct($context['company'], ['name' => 'Barcode Pricing Product', 'barcode' => 'PRICE-SCAN', 'image_path' => 'products/pricing.jpg']);
    $openingStock = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 21, $hall);

    $branchIds = collect($this->actingAs($actor)->getJson(route('admin.inventory.select2.opening-stock-pricing-branches', ['q' => 'Branch']))->assertOk()->json('results'))->pluck('id');

    expect($branchIds)->toContain($context['branch']->doc_num)
        ->and($branchIds)->not->toContain($showroom->doc_num)
        ->and($branchIds)->not->toContain($other['branch']->doc_num);

    $halls = $this->actingAs($actor)
        ->getJson(route('admin.inventory.select2.opening-stock-pricing-branch-halls', ['branch_doc_num' => $context['branch']->doc_num]))
        ->assertOk()
        ->json('results');

    expect($halls[0]['id'])->toBe($hall->public_uuid);

    $documents = $this->actingAs($actor)
        ->getJson(route('admin.inventory.select2.opening-stock-pricing-documents', ['branch_doc_num' => $context['branch']->doc_num, 'branch_hall_uuid' => $hall->public_uuid]))
        ->assertOk()
        ->json('results');

    expect(collect($documents)->pluck('id'))->toContain($openingStock->doc_num);

    $line = $this->actingAs($actor)
        ->getJson(route('admin.inventory.select2.opening-stock-pricing-lines', ['branch_doc_num' => $context['branch']->doc_num, 'opening_stock_doc_num' => $openingStock->doc_num, 'q' => 'PRICE-SCAN']))
        ->assertOk()
        ->json('results.0');

    expect($line['id'])->toBe($openingStock->lines()->firstOrFail()->public_id)
        ->and($line['text'])->toContain('PRICE-SCAN')
        ->and($line['imageUrl'])->toContain('products/pricing.jpg')
        ->and($line['unitLabel'])->not->toBeNull()
        ->and($line['quantity'])->toBe('2');
});

test('pricing saves selected source lines and loads only remaining lines from the same document', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.create', 'inventory.opening_stock_pricings.view']);
    $currency = openingStockPricingCurrency($context['company']);
    $products = [openingStockPricingProduct($context['company']), openingStockPricingProduct($context['company'])];
    $openingStock = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], $products, 31);
    $firstLine = $openingStock->lines()->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency, [
            'lines' => [
                ['opening_stock_line_public_id' => $firstLine->public_id, 'unit_price' => '5'],
                ['opening_stock_line_public_id' => $openingStock->lines()->whereKeyNot($firstLine->getKey())->firstOrFail()->public_id, 'unit_price' => ''],
            ],
        ]))
        ->assertOk();

    $this->actingAs($actor)->get(route('admin.inventory.opening-stock-pricings.create'))
        ->assertOk()
        ->assertDontSee($firstLine->public_id)
        ->assertDontSee($openingStock->lines()->whereKeyNot($firstLine->getKey())->firstOrFail()->public_id)
        ->assertSee('name="opening_stock_doc_num"', false)
        ->assertDontSee('js-opening-stock-pricing-branch', false)
        ->assertDontSee('name="branch_hall_uuid"', false);

    $documents = $this->actingAs($actor)->getJson(route('admin.inventory.select2.opening-stock-pricing-documents'))->assertOk()->json('results');
    expect(collect($documents)->pluck('id'))->toContain($openingStock->doc_num);
    $loaded = $this->actingAs($actor)->getJson(route('admin.inventory.opening-stock-pricings.remaining-lines', ['opening_stock_doc_num' => $openingStock->doc_num]))->assertOk()->json('data.lines');
    expect(collect($loaded)->pluck('id'))->not->toContain($firstLine->public_id)
        ->toContain($openingStock->lines()->whereKeyNot($firstLine->getKey())->firstOrFail()->public_id);

    $record = OpeningStockPricing::query()->where('doc_num', 'OSP-00001')->firstOrFail();
    $line = $record->lines()->where('opening_stock_line_id', $firstLine->getKey())->firstOrFail();

    expect($record->company_id)->toBe($context['company']->getKey())
        ->and($record->financial_period_id)->toBe($context['period']->getKey())
        ->and($record->branch_id)->toBe($context['branch']->getKey())
        ->and((string) $line->quantity)->toBe((string) $firstLine->quantity)
        ->and((string) $line->line_total)->toBe(number_format((float) $firstLine->quantity * 5, 4, '.', ''))
        ->and((float) $record->total_amount)->toBe(10.0)
        ->and($line->product_snapshot['name'])->toBe($firstLine->product_snapshot['name'])
        ->and($line->product_snapshot)->not->toHaveKeys(['id', 'product_id', 'company_id']);

    $remaining = $openingStock->lines()->whereKeyNot($firstLine->getKey())->firstOrFail();
    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency, [
        'lines' => [['opening_stock_line_public_id' => $remaining->public_id, 'unit_price' => '10.5']],
    ]))->assertOk();

    expect(OpeningStockPricing::query()->where('opening_stock_id', $openingStock->getKey())->count())->toBe(2);
    $documents = $this->actingAs($actor)->getJson(route('admin.inventory.select2.opening-stock-pricing-documents'))->assertOk()->json('results');
    expect(collect($documents)->pluck('id'))->not->toContain($openingStock->doc_num);
});

test('fully priced documents disappear and deleted pricing makes them available again without journals', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.view', 'inventory.opening_stock_pricings.create', 'inventory.opening_stock_pricings.delete', 'inventory.opening_stock_pricings.restore']);
    $currency = openingStockPricingCurrency($context['company']);
    $product = openingStockPricingProduct($context['company']);
    $openingStock = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 41);
    $journalCount = Schema::hasTable('journal_entries') ? JournalEntry::query()->count() : 0;

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency))
        ->assertOk();

    if (Schema::hasTable('journal_entries')) {
        expect(JournalEntry::query()->count())->toBe($journalCount);
    }

    $results = $this->actingAs($actor)
        ->getJson(route('admin.inventory.select2.opening-stock-pricing-documents', ['branch_doc_num' => $context['branch']->doc_num]))
        ->assertOk()
        ->json('results');

    expect(collect($results)->pluck('id'))->not->toContain($openingStock->doc_num);

    $pricing = OpeningStockPricing::query()->firstOrFail();

    $this->actingAs($actor)
        ->deleteJson(route('admin.inventory.opening-stock-pricings.destroy', $pricing->doc_num))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $pricing->forceFill(['is_closed' => false, 'status' => OpeningStockPricing::StatusDraft])->save();

    $this->actingAs($actor)
        ->deleteJson(route('admin.inventory.opening-stock-pricings.destroy', $pricing->doc_num))
        ->assertOk();

    $results = $this->actingAs($actor)
        ->getJson(route('admin.inventory.select2.opening-stock-pricing-documents', ['branch_doc_num' => $context['branch']->doc_num]))
        ->assertOk()
        ->json('results');

    expect(collect($results)->pluck('id'))->toContain($openingStock->doc_num);

    $sourceLine = $openingStock->lines()->firstOrFail();
    $loaded = $this->actingAs($actor)->getJson(route('admin.inventory.opening-stock-pricings.remaining-lines', ['opening_stock_doc_num' => $openingStock->doc_num]))->assertOk()->json('data.lines');
    expect(collect($loaded)->pluck('id'))->toContain($sourceLine->public_id);

    $this->actingAs($actor)->patchJson(route('admin.inventory.opening-stock-pricings.restore', $pricing->doc_num))
        ->assertOk();
    $this->actingAs($actor)->getJson(route('admin.inventory.opening-stock-pricings.remaining-lines', ['opening_stock_doc_num' => $openingStock->doc_num]))
        ->assertStatus(422)->assertJsonValidationErrors('opening_stock_doc_num');
});

test('main currency exchange rate must be one and document date must be inside period', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.create']);
    $currency = openingStockPricingCurrency($context['company']);
    $product = openingStockPricingProduct($context['company']);
    $openingStock = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 51);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency, ['exchange_rate' => '2']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['exchange_rate']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency, ['document_date' => '2027-01-01']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_date']);

    $context['period']->forceFill(['allows_opening_entries' => false])->save();
    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['financial_period_id']);
});

test('closed opening stock pricing cannot be edited or deleted but can be cloned', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor([
        'inventory.opening_stock_pricings.view',
        'inventory.opening_stock_pricings.edit',
        'inventory.opening_stock_pricings.delete',
        'inventory.opening_stock_pricings.clone',
        'inventory.opening_stock_pricings.create',
    ]);
    $currency = openingStockPricingCurrency($context['company']);
    $product = openingStockPricingProduct($context['company']);
    $openingStock = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 61);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency))
        ->assertOk();

    $record = OpeningStockPricing::query()->where('doc_num', 'OSP-00001')->firstOrFail();

    $this->actingAs($actor)
        ->get(route('admin.inventory.opening-stock-pricings.edit', $record->doc_num))
        ->assertForbidden();

    $this->actingAs($actor)
        ->putJson(route('admin.inventory.opening-stock-pricings.update', $record->doc_num), openingStockPricingPayload($openingStock, $currency))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->deleteJson(route('admin.inventory.opening-stock-pricings.destroy', $record->doc_num))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->get(route('admin.inventory.opening-stock-pricings.clone', $record->doc_num))
        ->assertOk()
        ->assertSee('js-opening-stock-pricing-form', false);

    $script = file_get_contents(public_path('assets/js/modules/Inventory/opening-stock-pricings.js'));

    expect($script)->toContain('dblclick.openingStockPricingsEditRow');
});

test('opening stock pricing preserves accepted exchange rate and unit price precision before persistence', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.create']);
    $currency = openingStockPricingCurrency($context['company'], false);
    $product = openingStockPricingProduct($context['company']);
    $openingStock = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 71);
    $line = $openingStock->lines()->firstOrFail();
    $capturedExchangeRate = null;
    $capturedUnitPrice = null;

    OpeningStockPricing::creating(function (OpeningStockPricing $pricing) use (&$capturedExchangeRate): void {
        $capturedExchangeRate = $pricing->getAttributes()['exchange_rate'] ?? null;
    });
    OpeningStockPricingLine::creating(function (OpeningStockPricingLine $pricingLine) use (&$capturedUnitPrice): void {
        $capturedUnitPrice = $pricingLine->getAttributes()['unit_price'] ?? null;
    });

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency, [
            'exchange_rate' => '999,999,999,999.999999',
            'lines' => [[
                'opening_stock_line_public_id' => $line->public_id,
                'unit_price' => '12,345,678,901.2345',
            ]],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($capturedExchangeRate)->toBe('999999999999.999999')
        ->and($capturedUnitPrice)->toBe('12345678901.2345');
});

test('browser pricing values the approved opening stock ledger in base currency without creating a journal', function (): void {
    $context = openingStockPricingContext($this, Branch::TypeFactory);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.create', 'inventory.opening_stock_pricings.delete']);
    $currency = openingStockPricingCurrency($context['company'], false);
    $product = openingStockPricingProduct($context['company']);
    $store = BranchStore::query()->create([
        'branch_id' => $context['branch']->getKey(),
        'name' => 'Opening Raw Materials',
        'position' => 1,
    ]);
    $openingStock = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 72);
    $openingStock->forceFill([
        'branch_store_id' => $store->getKey(),
        'approved' => true,
        'status' => OpeningStock::StatusApproved,
    ])->save();

    $this->actingAs($actor);
    app(InventoryOpeningStockPostingService::class)->post($openingStock);

    $movement = InventoryTransaction::query()->where('source_id', $openingStock->getKey())->firstOrFail();
    $movementCount = InventoryTransaction::query()->count();
    $journalCount = JournalEntry::query()->count();

    expect($movement->unit_cost)->toBeNull()
        ->and($movement->total_cost)->toBeNull();

    $this->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency))
        ->assertOk()
        ->assertJsonPath('success', true);

    $movement->refresh();

    expect((string) $movement->unit_cost)->toBe('525.00000000')
        ->and((string) $movement->total_cost)->toBe('1050.00000000')
        ->and((string) InventoryReceiptLayer::query()->where('receipt_transaction_id', $movement->getKey())->firstOrFail()->unit_cost)->toBe('525.00000000')
        ->and(InventoryTransaction::query()->count())->toBe($movementCount)
        ->and(JournalEntry::query()->count())->toBe($journalCount);

    $pricing = OpeningStockPricing::query()->firstOrFail();
    $pricing->forceFill(['is_closed' => false, 'status' => OpeningStockPricing::StatusDraft])->save();
    $this->actingAs($actor)->deleteJson(route('admin.inventory.opening-stock-pricings.destroy', $pricing->doc_num))->assertOk();
    expect($movement->fresh()->unit_cost)->toBeNull()
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $movement->getKey())->firstOrFail()->unit_cost)->toBeNull();
});

test('opening stock can be priced after a fully reversed issue without leaving stale layer costs', function (): void {
    $context = openingStockPricingContext($this, Branch::TypeFactory);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.create']);
    $currency = openingStockPricingCurrency($context['company']);
    $product = openingStockPricingProduct($context['company']);
    $store = BranchStore::query()->create(['branch_id' => $context['branch']->getKey(), 'name' => 'Reversed issue store']);
    $openingStock = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 73);
    $openingStock->forceFill(['branch_store_id' => $store->getKey()])->save();

    $this->actingAs($actor);
    app(InventoryOpeningStockPostingService::class)->post($openingStock);
    $opening = InventoryTransaction::query()->where('source_id', $openingStock->getKey())->firstOrFail();
    $document = app(InventoryMovementService::class)->createAndPost([
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'branch_id' => $context['branch']->getKey(),
        'branch_store_id' => $store->getKey(),
        'document_type' => InventoryDocument::TypeIssue,
        'document_date' => '2026-02-02',
        'source_stock_status' => InventoryTransaction::StatusAvailable,
    ], [['product_id' => $product->getKey(), 'quantity' => '1']]);
    $issue = $document->transactions->sole();
    app(InventoryDocumentPostingService::class)->reverse($document);
    $reversal = InventoryTransaction::query()->where('reversal_of_id', $issue->getKey())->sole();

    $this->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency))
        ->assertOk()->assertJsonPath('success', true);

    expect((string) $opening->fresh()->unit_cost)->toBe('10.50000000')
        ->and((string) $issue->fresh()->total_cost)->toBe('10.50000000')
        ->and((string) $reversal->fresh()->total_cost)->toBe('10.50000000')
        ->and((string) $document->lines()->sole()->total_cost)->toBe('10.50000000')
        ->and(InventoryReceiptLayer::query()->whereIn('receipt_transaction_id', [$opening->getKey(), $reversal->getKey()])->pluck('unit_cost')->unique()->all())->toBe(['10.50000000']);

    $pricing = OpeningStockPricing::query()->where('opening_stock_id', $openingStock->getKey())->sole();
    $posting = app(InventoryOpeningStockPostingService::class);
    $posting->clearPricing($pricing);
    expect($opening->fresh()->unit_cost)->toBeNull()
        ->and($issue->fresh()->unit_cost)->toBeNull()
        ->and($reversal->fresh()->unit_cost)->toBeNull()
        ->and($document->lines()->sole()->unit_cost)->toBeNull()
        ->and(InventoryReceiptLayer::query()->whereIn('receipt_transaction_id', [$opening->getKey(), $reversal->getKey()])->whereNotNull('unit_cost')->count())->toBe(0);
    $posting->applyPricing($pricing);
    expect((string) $document->lines()->sole()->total_cost)->toBe('10.50000000');
});

test('direct source selection loads one document and keeps identical products independent', function (): void {
    $context = openingStockPricingContext($this, Branch::TypeFactory);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.create']);
    $currency = openingStockPricingCurrency($context['company']);
    $product = openingStockPricingProduct($context['company']);
    $firstHall = BranchHall::query()->create(['branch_id' => $context['branch']->getKey(), 'name' => 'First Hall', 'position' => 1]);
    $secondHall = BranchHall::query()->create(['branch_id' => $context['branch']->getKey(), 'name' => 'Second Hall', 'position' => 2]);
    $firstStore = BranchStore::query()->create(['branch_id' => $context['branch']->getKey(), 'name' => 'First Store', 'position' => 1]);
    $secondStore = BranchStore::query()->create(['branch_id' => $context['branch']->getKey(), 'name' => 'Second Store', 'position' => 2]);
    $first = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 81, $firstHall);
    $second = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 82, $secondHall);
    $first->forceFill(['branch_store_id' => $firstStore->getKey()])->save();
    $second->forceFill(['branch_store_id' => $secondStore->getKey()])->save();
    $firstLine = $first->lines()->firstOrFail();
    $secondLine = $second->lines()->firstOrFail();

    $this->actingAs($actor)->get(route('admin.inventory.opening-stock-pricings.create'))
        ->assertOk()
        ->assertSee('name="opening_stock_doc_num"', false)
        ->assertSee('تحميل البنود')
        ->assertDontSee('js-opening-stock-pricing-branch', false)
        ->assertDontSee('name="branch_hall_uuid"', false)
        ->assertDontSee('<tr class="js-opening-stock-pricing-line" data-index="0"', false)
        ->assertDontSee($firstLine->public_id)
        ->assertDontSee($secondLine->public_id);

    $createPage = $this->actingAs($actor)->get(route('admin.inventory.opening-stock-pricings.create'));
    $createPage->assertSee('js-opening-stock-pricing-add-line', false)
        ->assertSee('data-depends-on="#opening_stock_doc_num"', false)
        ->assertDontSee('js-opening-stock-pricing-duplicate-line', false);
    expect((bool) preg_match('/js-opening-stock-pricing-add-line[^>]*disabled/', $createPage->getContent()))->toBeTrue();

    $options = $this->actingAs($actor)->getJson(route('admin.inventory.select2.opening-stock-pricing-documents'))->assertOk()->json('results');
    $firstOption = collect($options)->firstWhere('id', $first->doc_num);
    expect(collect($options)->pluck('id'))->toContain($first->doc_num, $second->doc_num)
        ->and($firstOption['text'])->toContain($first->doc_num, '2026', $context['branch']->name, 'First Hall', 'First Store');

    $firstLoaded = $this->actingAs($actor)->getJson(route('admin.inventory.opening-stock-pricings.remaining-lines', ['opening_stock_doc_num' => $first->doc_num]))->assertOk()->json('data.lines');
    $secondLoaded = $this->actingAs($actor)->getJson(route('admin.inventory.opening-stock-pricings.remaining-lines', ['opening_stock_doc_num' => $second->doc_num]))->assertOk()->json('data.lines');
    expect(collect($firstLoaded)->pluck('id')->all())->toBe([$firstLine->public_id])
        ->and(collect($secondLoaded)->pluck('id')->all())->toBe([$secondLine->public_id]);

    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($first, $currency, [
        'lines' => [
            ['opening_stock_line_public_id' => $firstLine->public_id, 'unit_price' => '1.2345'],
            ['opening_stock_line_public_id' => $secondLine->public_id, 'unit_price' => '2.0000'],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('lines.1.opening_stock_line_public_id');

    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($first, $currency, [
        'lines' => [['opening_stock_line_public_id' => $firstLine->public_id, 'unit_price' => '1.2345']],
    ]))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($second, $currency, [
        'lines' => [['opening_stock_line_public_id' => $secondLine->public_id, 'unit_price' => '2.0000']],
    ]))->assertOk();

    $pricings = OpeningStockPricing::query()->orderBy('opening_stock_id')->get();
    expect($pricings)->toHaveCount(2)
        ->and($pricings[0]->opening_stock_id)->toBe($first->getKey())
        ->and($pricings[0]->branch_hall_id)->toBe($firstHall->getKey())
        ->and($pricings[1]->opening_stock_id)->toBe($second->getKey())
        ->and($pricings[1]->branch_hall_id)->toBe($secondHall->getKey())
        ->and((string) $pricings[0]->lines()->firstOrFail()->line_total)->toBe('2.4690')
        ->and(InventoryTransaction::query()->count())->toBe(0);
});

test('document selection and saving honor branch access and reject a stale second submission', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.create']);
    $currency = openingStockPricingCurrency($context['company']);
    $product = openingStockPricingProduct($context['company']);
    $own = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 91);
    $otherBranch = Branch::query()->create([
        'doc_number' => 16091,
        'doc_num' => 'Branch-16091',
        'company_id' => $context['company']->getKey(),
        'name' => 'Other Store',
        'type' => Branch::TypeWarehouse,
        'status' => 'active',
    ]);
    $other = openingStockPricingOpeningStock($context['company'], $context['period'], $otherBranch, [$product], 92);
    $restrictedRole = Role::query()->create([
        'name' => 'pricing-limited-branch',
        'guard_name' => 'web',
        'doc_number' => 16092,
        'doc_num' => 'Role-16092',
    ]);
    $restrictedRole->forceFill(['branch_access_restricted' => true])->save();
    $restrictedRole->branchAccessBranches()->sync([$context['branch']->getKey()]);
    $actor->assignRole($restrictedRole);

    $options = $this->actingAs($actor)->getJson(route('admin.inventory.select2.opening-stock-pricing-documents'))->assertOk()->json('results');
    expect(collect($options)->pluck('id'))->toContain($own->doc_num)->not->toContain($other->doc_num);
    $this->actingAs($actor)->getJson(route('admin.inventory.opening-stock-pricings.remaining-lines', ['opening_stock_doc_num' => $other->doc_num]))
        ->assertStatus(422)->assertJsonValidationErrors('opening_stock_doc_num');

    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($other, $currency))
        ->assertStatus(422)->assertJsonValidationErrors('opening_stock_doc_num');

    $payload = openingStockPricingPayload($own, $currency);
    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), $payload)->assertOk();
    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), $payload)
        ->assertStatus(422)->assertJsonValidationErrors('opening_stock_doc_num');
    expect(OpeningStockPricingLine::query()->count())->toBe(1);
});

test('a reversed pricing cannot be restored after its source line was priced again', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor([
        'inventory.opening_stock_pricings.create',
        'inventory.opening_stock_pricings.delete',
        'inventory.opening_stock_pricings.restore',
    ]);
    $currency = openingStockPricingCurrency($context['company']);
    $product = openingStockPricingProduct($context['company']);
    $source = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 99);
    $payload = openingStockPricingPayload($source, $currency);

    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), $payload)->assertOk();
    $oldPricing = OpeningStockPricing::query()->firstOrFail();
    $oldPricing->forceFill(['is_closed' => false, 'status' => OpeningStockPricing::StatusDraft])->save();
    $this->actingAs($actor)->deleteJson(route('admin.inventory.opening-stock-pricings.destroy', $oldPricing->doc_num))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), $payload)->assertOk();

    $this->actingAs($actor)->patchJson(route('admin.inventory.opening-stock-pricings.restore', $oldPricing->doc_num))
        ->assertStatus(422)
        ->assertJsonValidationErrors('document');
    expect(OpeningStockPricing::withTrashed()->findOrFail($oldPricing->getKey())->trashed())->toBeTrue()
        ->and(OpeningStockPricingLine::query()->count())->toBe(1);
});

test('document selector and pricing reject unapproved draft and deleted sources', function (): void {
    $context = openingStockPricingContext($this);
    $product = openingStockPricingProduct($context['company']);
    $currency = openingStockPricingCurrency($context['company']);
    $draft = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 101);
    $deleted = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 102);
    $valid = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 103);
    $closedUnapproved = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 104);
    $statusOnly = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 105);
    $flagOnly = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 106);
    $draft->forceFill(['is_closed' => false, 'approved' => false, 'status' => OpeningStock::StatusDraft])->save();
    $closedUnapproved->forceFill(['approved' => false, 'status' => OpeningStock::StatusClosed])->save();
    $statusOnly->forceFill(['approved' => false])->save();
    $flagOnly->forceFill(['status' => OpeningStock::StatusClosed])->save();
    $deleted->delete();
    $viewer = openingStockPricingActor(['inventory.opening_stock_pricings.view']);
    $creator = openingStockPricingActor(['inventory.opening_stock_pricings.create']);

    $this->actingAs($viewer)->get(route('admin.inventory.opening-stock-pricings.create'))->assertForbidden();
    $options = $this->actingAs($creator)->getJson(route('admin.inventory.select2.opening-stock-pricing-documents'))->assertOk()->json('results');
    expect(collect($options)->pluck('id'))->toContain($valid->doc_num)
        ->not->toContain($draft->doc_num, $deleted->doc_num, $closedUnapproved->doc_num, $statusOnly->doc_num, $flagOnly->doc_num);

    $this->actingAs($creator)->getJson(route('admin.inventory.select2.opening-stock-pricing-lines', ['opening_stock_doc_num' => $closedUnapproved->doc_num]))
        ->assertOk()->assertJsonCount(0, 'results');
    $this->actingAs($creator)->getJson(route('admin.inventory.opening-stock-pricings.remaining-lines', ['opening_stock_doc_num' => $closedUnapproved->doc_num]))
        ->assertStatus(422)->assertJsonValidationErrors('opening_stock_doc_num');
    $this->actingAs($creator)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($closedUnapproved, $currency))
        ->assertStatus(422)->assertJsonValidationErrors('opening_stock_doc_num');
    $this->actingAs($creator)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($statusOnly, $currency))
        ->assertStatus(422)->assertJsonValidationErrors('opening_stock_doc_num');
    $this->actingAs($creator)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($flagOnly, $currency))
        ->assertStatus(422)->assertJsonValidationErrors('opening_stock_doc_num');
});

test('product selector returns only the chosen approved opening stock lines', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.create']);
    $first = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [
        openingStockPricingProduct($context['company']),
        openingStockPricingProduct($context['company']),
    ], 111);
    $second = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [
        openingStockPricingProduct($context['company']),
    ], 112);

    $results = $this->actingAs($actor)->getJson(route('admin.inventory.select2.opening-stock-pricing-lines', [
        'opening_stock_doc_num' => $first->doc_num,
    ]))->assertOk()->json('results');

    expect(collect($results)->pluck('id')->all())->toBe($first->lines()->pluck('public_id')->all())
        ->not->toContain($second->lines()->firstOrFail()->public_id);
    $this->actingAs($actor)->getJson(route('admin.inventory.select2.opening-stock-pricing-lines'))
        ->assertOk()->assertJsonCount(0, 'results');
});

test('pricing rejects the same source line twice in one document', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.create']);
    $currency = openingStockPricingCurrency($context['company']);
    $product = openingStockPricingProduct($context['company']);
    $source = openingStockPricingOpeningStock($context['company'], $context['period'], $context['branch'], [$product], 113);
    $linePublicId = $source->lines()->firstOrFail()->public_id;

    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($source, $currency, [
        'lines' => [
            ['opening_stock_line_public_id' => $linePublicId, 'unit_price' => '5'],
            ['opening_stock_line_public_id' => $linePublicId, 'unit_price' => '6'],
        ],
    ]))
        ->assertStatus(422)->assertJsonValidationErrors('lines.1.opening_stock_line_public_id');
    expect(OpeningStockPricing::query()->count())->toBe(0);
});

test('unpriced inventory receipt preserves maximum accepted quantity precision before persistence', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.unpriced_inventory_receipts.create']);
    $product = openingStockPricingProduct($context['company']);
    $capturedQuantity = null;

    UnpricedInventoryReceiptLine::creating(function (UnpricedInventoryReceiptLine $line) use (&$capturedQuantity): void {
        $capturedQuantity = $line->getAttributes()['quantity'] ?? null;
    });

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.unpriced-inventory-receipts.store'), [
            'document_date' => '2026-03-01',
            'branch_doc_num' => $context['branch']->doc_num,
            'lines' => [[
                'product_doc_num' => $product->doc_num,
                'unit_doc_num' => $product->unit?->doc_num,
                'quantity' => '999,999,999,999.99999999',
            ]],
            'submit_action' => 'save',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($capturedQuantity)->toBe('999999999999.99999999');
});
