<?php

namespace Modules\Accounting\Database\Seeders;

use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;

class BaselineCostCentersSeeder extends Seeder
{
    public function run(): void
    {
        if (! Company::query()->whereNull('deleted_at')->exists()) {
            $this->call(DefaultOperatingContextSeeder::class);
        }

        DB::transaction(function (): void {
            Company::query()
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->each(function (Company $company): void {
                    $this->seedForCompany($company);
                });
        });
    }

    private function seedForCompany(Company $company): void
    {
        foreach ($this->rootCostCenters() as $data) {
            $costCenter = CostCenter::withTrashed()
                ->where('company_id', $company->getKey())
                ->where('cost_center_code', $data['cost_center_code'])
                ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
                ->first();

            if ($costCenter instanceof CostCenter) {
                continue;
            }

            $costCenter = new CostCenter(app(DocumentNumberService::class)->nextForCompany('cost_centers', CostCenter::class, $company->getKey()));
            $costCenter->forceFill([
                'company_id' => $company->getKey(),
                'parent_id' => null,
                'cost_center_code' => $data['cost_center_code'],
                'name' => $data['name'],
                'is_group' => true,
                'status' => 'active',
            ])->save();
        }
    }

    /**
     * @return list<array{cost_center_code: string, name: string}>
     */
    private function rootCostCenters(): array
    {
        return [
            ['cost_center_code' => CostCenter::RootProductionCode, 'name' => 'إنتاجي'],
            ['cost_center_code' => CostCenter::RootServiceCode, 'name' => 'خدمي'],
        ];
    }
}
