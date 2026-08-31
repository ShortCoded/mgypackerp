<?php

use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\CostCenterHierarchyRegistry;
use Modules\Core\Models\Company;

function hierarchyTestCompany(): Company
{
    return Company::query()->create([
        'doc_number' => 9001,
        'doc_num' => 'COMP-09001',
        'name' => 'Hierarchy Test Company',
        'legal_name' => 'Hierarchy Test Company',
        'status' => 'active',
        'country' => 'Egypt',
    ]);
}

function hierarchyLegacyProductionRoot(Company $company): CostCenter
{
    return CostCenter::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 1,
        'doc_num' => 'CC-00001',
        'cost_center_code' => CostCenter::RootProductionCode,
        'name' => 'إنتاجي',
        'is_group' => true,
        'status' => 'active',
    ]);
}

test('standard cost center hierarchy dry run is read only and plans exactly thirty managed nodes', function (): void {
    $company = hierarchyTestCompany();
    hierarchyLegacyProductionRoot($company);
    $before = CostCenter::withTrashed()->orderBy('id')->get()->toArray();
    $registry = app(CostCenterHierarchyRegistry::class);

    expect($registry->definitions())->toHaveCount(30);

    $this->artisan('cost-centers:sync-standard-hierarchy')
        ->expectsOutputToContain('companies=1 create=29 reuse=1 conflicts=0 blocked=0')
        ->expectsOutputToContain('Dry run complete; no database writes were performed.')
        ->assertSuccessful();

    expect(CostCenter::withTrashed()->orderBy('id')->get()->toArray())->toBe($before);
});

test('standard hierarchy synchronization is additive and idempotent in the isolated test database', function (): void {
    $company = hierarchyTestCompany();
    hierarchyLegacyProductionRoot($company);
    CostCenter::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 2,
        'doc_num' => 'CC-00002',
        'cost_center_code' => CostCenter::RootServiceCode,
        'name' => 'خدمي',
        'is_group' => true,
        'status' => 'active',
    ]);
    $registry = app(CostCenterHierarchyRegistry::class);

    expect($registry->synchronize((int) $company->getKey()))->toMatchArray([
        'companies' => 1,
        'create' => 29,
        'reuse' => 1,
        'conflicts' => [],
        'blocked' => [],
    ]);

    $managedCodes = collect($registry->definitions())->pluck('code');

    expect(CostCenter::query()->forCompany($company->getKey())->whereIn('cost_center_code', $managedCodes)->count())->toBe(30)
        ->and(CostCenter::query()->forCompany($company->getKey())->where('cost_center_code', CostCenter::RootServiceCode)->exists())->toBeTrue()
        ->and($registry->plan((int) $company->getKey()))->toMatchArray([
            'companies' => 1,
            'create' => 0,
            'reuse' => 30,
            'conflicts' => [],
            'blocked' => [],
        ]);
});

test('occupied or inactive hierarchy codes are conflicts and block descendants without writes', function (): void {
    $company = hierarchyTestCompany();
    hierarchyLegacyProductionRoot($company);
    CostCenter::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 3,
        'doc_num' => 'CC-00003',
        'cost_center_code' => '3',
        'name' => 'دعم المصنع',
        'name_en' => 'Factory Support',
        'is_group' => true,
        'status' => 'inactive',
    ]);
    $before = CostCenter::withTrashed()->orderBy('id')->get()->toArray();
    $plan = app(CostCenterHierarchyRegistry::class)->plan((int) $company->getKey());

    expect($plan['conflicts'])->toContain('COMP-09001:3:occupied_or_mismatched')
        ->and($plan['blocked'])->toContain(
            'COMP-09001:31', 'COMP-09001:32', 'COMP-09001:33', 'COMP-09001:34', 'COMP-09001:35'
        )
        ->and(fn () => app(CostCenterHierarchyRegistry::class)->synchronize((int) $company->getKey()))
        ->toThrow(DomainException::class)
        ->and(CostCenter::withTrashed()->orderBy('id')->get()->toArray())->toBe($before);
});
