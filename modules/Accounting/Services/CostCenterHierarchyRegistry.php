<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;

final class CostCenterHierarchyRegistry
{
    public function __construct(private readonly DocumentNumberService $documentNumbers) {}

    /**
     * @return list<array{code: string, parent_code: string|null, name: string, name_en: string, is_group: bool, allocation_type: string}>
     */
    public function definitions(): array
    {
        return [
            $this->node('1', null, 'الإنتاج', 'Production', true, 'direct'),
            $this->node('11', '1', 'إنتاج الحقن', 'Injection Production', false, 'direct'),
            $this->node('12', '1', 'إنتاج الكوفير أو التشكيل', 'Cover or Forming Production', false, 'direct'),
            $this->node('13', '1', 'إنتاج الطباعة', 'Printing Production', false, 'direct'),
            $this->node('14', '1', 'الطحن وإعادة التدوير', 'Grinding and Recycling', false, 'direct'),
            $this->node('15', '1', 'التعبئة والتغليف', 'Packing and Packaging', false, 'direct'),
            $this->node('16', '1', 'تخطيط ومراقبة الإنتاج', 'Production Planning and Control', false, 'direct'),

            $this->node('3', null, 'دعم المصنع', 'Factory Support', true, 'indirect'),
            $this->node('31', '3', 'الصيانة والهندسة', 'Maintenance and Engineering', false, 'indirect'),
            $this->node('32', '3', 'مراقبة وضمان الجودة', 'Quality Control and Assurance', false, 'indirect'),
            $this->node('33', '3', 'الطاقة والمرافق الصناعية', 'Factory Utilities and Energy', false, 'indirect'),
            $this->node('34', '3', 'السلامة والصحة المهنية', 'Health, Safety and Environment', false, 'indirect'),
            $this->node('35', '3', 'الأمن والنظافة الصناعية', 'Factory Security and Cleaning', false, 'indirect'),

            $this->node('4', null, 'سلسلة الإمداد', 'Supply Chain', true, 'administrative'),
            $this->node('41', '4', 'المشتريات', 'Procurement', false, 'administrative'),
            $this->node('42', '4', 'مخازن المواد الخام', 'Raw Materials Warehousing', false, 'administrative'),
            $this->node('43', '4', 'مخازن التعبئة والمستلزمات', 'Packaging and Supplies Warehousing', false, 'administrative'),
            $this->node('44', '4', 'مخازن الإنتاج التام', 'Finished Goods Warehousing', false, 'administrative'),
            $this->node('45', '4', 'النقل والتوزيع', 'Logistics and Distribution', false, 'administrative'),

            $this->node('5', null, 'المبيعات والتسويق', 'Selling and Marketing', true, 'sales'),
            $this->node('51', '5', 'المبيعات', 'Sales', false, 'sales'),
            $this->node('52', '5', 'التسويق', 'Marketing', false, 'sales'),
            $this->node('53', '5', 'خدمة العملاء', 'Customer Service', false, 'sales'),

            $this->node('6', null, 'الإدارة العامة والإدارية', 'General and Administrative', true, 'administrative'),
            $this->node('61', '6', 'الإدارة التنفيذية', 'Executive Management', false, 'administrative'),
            $this->node('62', '6', 'المالية والحسابات', 'Finance and Accounting', false, 'administrative'),
            $this->node('63', '6', 'الموارد البشرية', 'Human Resources', false, 'administrative'),
            $this->node('64', '6', 'تقنية المعلومات', 'Information Technology', false, 'administrative'),
            $this->node('65', '6', 'الشؤون القانونية والالتزام', 'Legal and Compliance', false, 'administrative'),
            $this->node('66', '6', 'الإدارة العامة', 'General Administration', false, 'administrative'),
        ];
    }

    /**
     * @return array{companies: int, create: int, reuse: int, conflicts: list<string>, blocked: list<string>, create_codes: list<string>, reuse_codes: list<string>}
     */
    public function plan(?int $companyId = null): array
    {
        $result = $this->emptyResult();
        $companies = Company::query()
            ->whereNull('deleted_at')
            ->when($companyId !== null, fn ($query) => $query->whereKey($companyId))
            ->orderBy('id')
            ->get(['id', 'doc_num', 'name']);

        $result['companies'] = $companies->count();

        foreach ($companies as $company) {
            $this->planCompany((int) $company->getKey(), (string) ($company->doc_num ?? $company->getKey()), $result);
        }

        return $result;
    }

    /**
     * @return array{companies: int, create: int, reuse: int, conflicts: list<string>, blocked: list<string>, create_codes: list<string>, reuse_codes: list<string>}
     */
    public function synchronize(?int $companyId = null): array
    {
        return DB::transaction(function () use ($companyId): array {
            Company::query()
                ->whereNull('deleted_at')
                ->when($companyId !== null, fn ($query) => $query->whereKey($companyId))
                ->lockForUpdate()
                ->get(['id']);

            $plan = $this->plan($companyId);

            if ($plan['conflicts'] !== [] || $plan['blocked'] !== []) {
                throw new DomainException(__('Cost-center hierarchy conflicts require manual review.'));
            }

            $companyIds = Company::query()
                ->whereNull('deleted_at')
                ->when($companyId !== null, fn ($query) => $query->whereKey($companyId))
                ->orderBy('id')
                ->pluck('id');

            foreach ($companyIds as $currentCompanyId) {
                $this->insertMissingForCompany((int) $currentCompanyId);
            }

            return $plan;
        });
    }

    public function allocationTypeForCode(string $costCenterCode): ?string
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['code'] === $costCenterCode) {
                return $definition['allocation_type'];
            }
        }

        return null;
    }

    /**
     * @param  array{companies: int, create: int, reuse: int, conflicts: list<string>, blocked: list<string>, create_codes: list<string>, reuse_codes: list<string>}  $result
     */
    private function planCompany(int $companyId, string $companyReference, array &$result): void
    {
        $definitions = $this->definitions();
        $codes = array_column($definitions, 'code');
        $records = CostCenter::withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('cost_center_code', $codes)
            ->get()
            ->groupBy('cost_center_code');
        $states = [];

        foreach ($definitions as $definition) {
            $key = $companyReference.':'.$definition['code'];
            $parentState = $definition['parent_code'] === null ? null : ($states[$definition['parent_code']] ?? 'blocked');

            if (in_array($parentState, ['conflict', 'blocked'], true)) {
                $states[$definition['code']] = 'blocked';
                $result['blocked'][] = $key;

                continue;
            }

            $matches = $records->get($definition['code'], collect());

            if ($matches->count() > 1) {
                $states[$definition['code']] = 'conflict';
                $result['conflicts'][] = $key.':duplicate_code';

                continue;
            }

            /** @var CostCenter|null $record */
            $record = $matches->first();

            if (! $record instanceof CostCenter) {
                $states[$definition['code']] = 'create';
                $result['create']++;
                $result['create_codes'][] = $key;

                continue;
            }

            $parentCode = $record->parent_id === null
                ? null
                : CostCenter::withTrashed()->whereKey($record->parent_id)->value('cost_center_code');
            $legacyProductionRoot = $definition['code'] === CostCenter::RootProductionCode
                && $definition['parent_code'] === null
                && trim((string) $record->name) !== '';
            $labelsMatch = $legacyProductionRoot || (
                trim((string) $record->name) === $definition['name']
                && trim((string) $record->name_en) === $definition['name_en']
            );

            if (
                $record->trashed()
                || $record->status !== 'active'
                || (bool) $record->is_group !== $definition['is_group']
                || $parentCode !== $definition['parent_code']
                || ! $labelsMatch
            ) {
                $states[$definition['code']] = 'conflict';
                $result['conflicts'][] = $key.':occupied_or_mismatched';

                continue;
            }

            $states[$definition['code']] = 'reuse';
            $result['reuse']++;
            $result['reuse_codes'][] = $key;
        }
    }

    private function insertMissingForCompany(int $companyId): void
    {
        foreach ($this->definitions() as $definition) {
            if (CostCenter::query()->forCompany($companyId)->where('cost_center_code', $definition['code'])->exists()) {
                continue;
            }

            $parentId = $definition['parent_code'] === null
                ? null
                : CostCenter::query()
                    ->forCompany($companyId)
                    ->where('cost_center_code', $definition['parent_code'])
                    ->valueOrFail('id');

            CostCenter::query()->create([
                'company_id' => $companyId,
                'parent_id' => $parentId,
                ...$this->documentNumbers->nextForCompany('cost_centers', CostCenter::class, $companyId),
                'cost_center_code' => $definition['code'],
                'name' => $definition['name'],
                'name_en' => $definition['name_en'],
                'is_group' => $definition['is_group'],
                'status' => 'active',
            ]);
        }
    }

    /**
     * @return array{code: string, parent_code: string|null, name: string, name_en: string, is_group: bool, allocation_type: string}
     */
    private function node(
        string $code,
        ?string $parentCode,
        string $name,
        string $nameEn,
        bool $isGroup,
        string $allocationType,
    ): array {
        return [
            'code' => $code,
            'parent_code' => $parentCode,
            'name' => $name,
            'name_en' => $nameEn,
            'is_group' => $isGroup,
            'allocation_type' => $allocationType,
        ];
    }

    /**
     * @return array{companies: int, create: int, reuse: int, conflicts: list<string>, blocked: list<string>, create_codes: list<string>, reuse_codes: list<string>}
     */
    private function emptyResult(): array
    {
        return [
            'companies' => 0,
            'create' => 0,
            'reuse' => 0,
            'conflicts' => [],
            'blocked' => [],
            'create_codes' => [],
            'reuse_codes' => [],
        ];
    }
}
