<?php

use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Sales\Models\PriceList;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

test('isolated PostgreSQL persists the full original integer capacity with eight price decimals', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Run only against the dedicated disposable PostgreSQL acceptance database.');
    }
    if (! in_array(DB::selectOne('select current_database() as name')->name, ['mgypack_acceptance_receipt_20261001', 'mgypack_acceptance_closure_20261003'], true)) {
        $this->markTestSkipped('Run only against the dedicated disposable PostgreSQL acceptance database.');
    }

    $this->seed(DefaultOperatingContextSeeder::class);
    $this->seed(CurrencySeeder::class);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->where('is_main', true)->firstOrFail();
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 999932, 'doc_num' => 'UNIT-PG-PRECISION',
        'name' => 'Synthetic precision unit', 'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(), 'doc_number' => 999932, 'doc_num' => 'SYN-PG-PRECISION',
        'name' => 'Synthetic precision item', 'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(), 'status' => 'active',
    ]);
    $priceList = PriceList::query()->create([
        'company_id' => $company->getKey(), 'currency_id' => $currency->getKey(),
        'doc_number' => 999932, 'doc_num' => 'PL-PG-PRECISION',
        'price_list_date' => now()->toDateString(), 'valid_from' => now()->toDateString(),
    ]);
    $line = $priceList->lines()->create([
        'line_number' => 1, 'product_id' => $product->getKey(), 'unit_price' => '0.00000001',
    ]);

    foreach (['0.00000001', '22.54545123', '9999999999999999.12345678'] as $price) {
        $line->forceFill(['unit_price' => $price])->save();
        expect($line->fresh()->unit_price)->toBe($price)
            ->and((string) DB::table('price_list_lines')->where('id', $line->getKey())->value('unit_price'))->toBe($price);
    }
});
