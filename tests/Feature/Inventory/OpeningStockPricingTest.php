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
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\MenuConfigFileOrder;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
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
        'status' => OpeningStock::StatusClosed,
        'approved' => false,
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
    $this->actingAs($actor)->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($alsoVisible, $currency))->assertOk();

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

test('pricing save requires all lines derives quantity snapshot and totals server side', function (): void {
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
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);

    $this->actingAs($actor)
        ->postJson(route('admin.inventory.opening-stock-pricings.store'), openingStockPricingPayload($openingStock, $currency))
        ->assertOk()
        ->assertJsonPath('redirect', route('admin.inventory.opening-stock-pricings.create'));

    $record = OpeningStockPricing::query()->where('doc_num', 'OSP-00001')->firstOrFail();
    $line = $record->lines()->where('opening_stock_line_id', $firstLine->getKey())->firstOrFail();

    expect($record->company_id)->toBe($context['company']->getKey())
        ->and($record->financial_period_id)->toBe($context['period']->getKey())
        ->and($record->branch_id)->toBe($context['branch']->getKey())
        ->and((string) $line->quantity)->toBe((string) $firstLine->quantity)
        ->and((string) $line->line_total)->toBe(number_format((float) $firstLine->quantity * 10.5, 4, '.', ''))
        ->and((float) $record->total_amount)->toBe(52.5)
        ->and($line->product_snapshot['name'])->toBe($firstLine->product_snapshot['name'])
        ->and($line->product_snapshot)->not->toHaveKeys(['id', 'product_id', 'company_id']);
});

test('fully priced documents disappear and deleted pricing makes them available again without journals', function (): void {
    $context = openingStockPricingContext($this);
    $actor = openingStockPricingActor(['inventory.opening_stock_pricings.view', 'inventory.opening_stock_pricings.create', 'inventory.opening_stock_pricings.delete']);
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
        ->assertSee('js-opening-stock-pricing-product', false);

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
