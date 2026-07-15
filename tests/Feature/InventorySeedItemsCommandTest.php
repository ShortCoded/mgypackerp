<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Core\Models\Company;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('app/seed-reports/inventory-items-import'));
});

test('inventory item seeding requires an explicit company id', function () {
    $this->artisan('inventory:seed-items --dry-run')
        ->expectsOutputToContain('The --company-id option is required')
        ->assertFailed();
});

test('inventory item seeding dry run writes reports without inserting products', function () {
    $company = inventorySeedItemsCompany();
    inventorySeedItemsSourceUnits($company);

    $this->artisan("inventory:seed-items --company-id={$company->getKey()} --dry-run")
        ->expectsOutputToContain('Items that would be inserted')
        ->assertSuccessful();

    expect(Product::query()->where('company_id', $company->getKey())->count())->toBe(0)
        ->and(File::glob(storage_path('app/seed-reports/inventory-items-import/import_rows_*.csv')))->not->toBeEmpty()
        ->and(File::glob(storage_path('app/seed-reports/inventory-items-import/classification_review_*.csv')))->not->toBeEmpty();
});

test('inventory item seeding imports source items with tracked classifications', function () {
    $company = inventorySeedItemsCompany();
    inventorySeedItemsSourceUnits($company);

    $this->artisan("inventory:seed-items --company-id={$company->getKey()}")
        ->expectsOutputToContain('Inserted items')
        ->assertSuccessful();

    $service = Product::query()
        ->where('company_id', $company->getKey())
        ->where('name', 'مصنعية تركيب حبل ماجيك')
        ->firstOrFail();
    $rawMaterial = Product::query()
        ->where('company_id', $company->getKey())
        ->where('name', 'تنر الصاروخين')
        ->firstOrFail();
    $other = Product::query()
        ->where('company_id', $company->getKey())
        ->where('name', 'طقم اقلام')
        ->firstOrFail();

    expect($service->item_classification)->toBe(Product::ClassificationService)
        ->and($service->doc_num)->toStartWith('Product-')
        ->and($rawMaterial->item_classification)->toBe(Product::ClassificationRawMaterial)
        ->and($rawMaterial->doc_num)->toStartWith('RAW-')
        ->and($rawMaterial->cost_as_inventory)->toBeTrue()
        ->and($other->item_classification)->toBe(Product::ClassificationOther);

    expect(DB::table('seeded_reference_records')
        ->where('seed_key', 'inventory_items_initial_import_from_tsv')
        ->where('table_name', (new Product)->getTable())
        ->count())->toBe(Product::query()->where('company_id', $company->getKey())->count());
});

test('inventory item rollback skips referenced tracked products', function () {
    $company = inventorySeedItemsCompany();
    inventorySeedItemsSourceUnits($company);

    $this->artisan("inventory:seed-items --company-id={$company->getKey()}")
        ->assertSuccessful();

    $unit = ItemUnit::query()->where('company_id', $company->getKey())->where('name', 'عدد')->firstOrFail();
    $referenced = Product::query()
        ->where('company_id', $company->getKey())
        ->where('name', 'مقبض مط اسود')
        ->firstOrFail();
    $parent = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 99001,
        'doc_num' => 'Product-99001',
        'name' => 'Rollback Parent Product',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);

    ProductComponent::query()->create([
        'company_id' => $company->getKey(),
        'product_id' => $parent->getKey(),
        'component_product_id' => $referenced->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '1.0000',
    ]);

    $this->artisan("inventory:seed-items --company-id={$company->getKey()} --rollback")
        ->expectsOutputToContain('Skipped: 1')
        ->assertSuccessful();

    expect($referenced->refresh()->trashed())->toBeFalse()
        ->and(Product::withTrashed()->where('company_id', $company->getKey())->where('name', 'تنر الصاروخين')->firstOrFail()->trashed())->toBeTrue()
        ->and(DB::table('seeded_reference_records')
            ->where('seed_key', 'inventory_items_initial_import_from_tsv')
            ->where('record_id', $referenced->getKey())
            ->exists())->toBeTrue();
});

function inventorySeedItemsCompany(): Company
{
    return Company::factory()->create([
        'name' => 'Inventory Seed Items Company',
        'status' => 'active',
    ]);
}

function inventorySeedItemsSourceUnits(Company $company): void
{
    $sourcePath = base_path('database/seeders/data/inventory_items_seed.tsv');
    $handle = fopen($sourcePath, 'rb');

    expect($handle)->not->toBeFalse();

    $names = [];

    while (($columns = fgetcsv($handle, 0, "\t")) !== false) {
        $unitName = inventorySeedItemsCleanUnit((string) ($columns[0] ?? ''));
        $itemName = inventorySeedItemsCleanText((string) ($columns[1] ?? ''));

        if ($unitName === '' || $itemName === '' || ($unitName === 'الوحدة' && $itemName === 'اسم الصنف')) {
            continue;
        }

        $names[$unitName] = true;
    }

    fclose($handle);

    $number = 1;

    foreach (array_keys($names) as $name) {
        ItemUnit::query()->create([
            'company_id' => $company->getKey(),
            'doc_number' => $number,
            'doc_num' => 'Unit-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'name' => $name,
            'status' => 'active',
        ]);

        $number++;
    }
}

function inventorySeedItemsCleanUnit(string $value): string
{
    return str_replace('كليو', 'كيلو', inventorySeedItemsCleanText($value));
}

function inventorySeedItemsCleanText(string $value): string
{
    $value = str_replace(["\u{FEFF}", "\u{00A0}", "\t", "\r", "\n"], ' ', $value);
    $value = preg_replace('/[[:space:]]+/u', ' ', $value) ?? $value;

    return trim($value);
}
