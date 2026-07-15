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

                    if (! $currency) {
                        $document = $documentNumbers->nextForCompany('currencies', Currency::class, (int) $company->getKey());
                        $currency = new Currency($document);
                    }

                    if ($currency->trashed()) {
                        $currency->restore();
                    }

                    $currency->fill([
                        'company_id' => $company->getKey(),
                        'name' => 'الجنيه المصري',
                        'code' => 'EGP',
                        'minor_unit_name' => 'قرش',
                        'minor_unit_factor' => 100,
                        'is_main' => true,
                        'status' => 'active',
                        'notes' => null,
                        'deleted_at' => null,
                        'deleted_by' => null,
                    ]);
                    $currency->save();

                    Currency::query()
                        ->where('company_id', $company->getKey())
                        ->whereKeyNot($currency->getKey())
                        ->where('is_main', true)
                        ->update(['is_main' => false]);
                });
        });
    }
}
