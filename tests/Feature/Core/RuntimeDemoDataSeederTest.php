<?php

use Database\Seeders\RuntimeDemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ItemDecal;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;

test('runtime demo data seeder creates enhanced product data and remains idempotent', function () {
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('DROP INDEX IF EXISTS companies_one_active_main_unique');
    }

    $this->seed(RuntimeDemoDataSeeder::class);

    $firstComponentCount = ProductComponent::query()->count();

    expect(ItemDecal::query()->where('name', 'Oak Grain')->exists())->toBeTrue()
        ->and(ItemOriginCountry::query()->where('name', 'Egypt')->exists())->toBeTrue()
        ->and(Product::query()->whereNotNull('barcode')->where('reorder_point', '>', 0)->whereNotNull('item_classification')->exists())->toBeTrue()
        ->and($firstComponentCount)->toBeGreaterThan(0);

    $this->seed(RuntimeDemoDataSeeder::class);

    expect(ProductComponent::query()->count())->toBe($firstComponentCount);
});
