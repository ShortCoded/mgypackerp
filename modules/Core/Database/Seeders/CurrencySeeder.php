<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DocumentNumberService;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $documentNumbers = app(DocumentNumberService::class);

            Company::query()
                ->where('status', 'active')
                ->orderBy('id')
                ->each(function (Company $company) use ($documentNumbers): void {
                    $currency = Currency::query()
                        ->withTrashed()
                        ->where('company_id', $company->getKey())
                        ->where('code', 'EGP')
                        ->first();

                    if ($currency instanceof Currency) {
                        return;
                    }

                    Currency::query()->create([
                        ...$documentNumbers->nextForCompany('currencies', Currency::class, (int) $company->getKey()),
                        'company_id' => $company->getKey(),
                        'name' => 'الجنيه المصري',
                        'code' => 'EGP',
                        'minor_unit_name' => 'قرش',
                        'minor_unit_factor' => 100,
                        'is_main' => ! Currency::query()
                            ->where('company_id', $company->getKey())
                            ->where('is_main', true)
                            ->exists(),
                        'status' => 'active',
                    ]);
                });
        });
    }
}
