<?php

namespace Modules\Accounting\Services;

use Illuminate\Http\Request;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Select2ResponseService;

class CostCenterSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly Select2ResponseService $select2,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function costCenters(Request $request): array
    {
        $companyId = $this->companies->currentCompanyId($request);
        $query = CostCenter::query()
            ->select(['doc_num', 'cost_center_code', 'name', 'doc_number'])
            ->where('status', 'active')
            ->where('is_group', ! $request->boolean('postable'))
            ->orderByRaw('LENGTH(cost_center_code), cost_center_code');

        if ($companyId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->forCompany($companyId);
        }

        if ($request->filled('exclude') && $companyId !== null) {
            $excluded = CostCenter::query()
                ->select(['id', 'doc_num'])
                ->forCompany($companyId)
                ->where('doc_num', $request->string('exclude')->toString())
                ->first();

            if ($excluded instanceof CostCenter) {
                $query->whereNotIn('doc_num', $this->excludedDocNums($excluded));
            } else {
                $query->where('doc_num', '!=', $request->string('exclude')->toString());
            }
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'cost_center_code', 'name']]);
        }

        return $this->select2->paginated($query, $request, fn (CostCenter $costCenter): array => [
            'id' => $costCenter->doc_num,
            'text' => $costCenter->codeNameLabel(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function excludedDocNums(CostCenter $costCenter): array
    {
        $excluded = [$costCenter->doc_num];
        $parentIds = [$costCenter->getKey()];

        while ($parentIds !== []) {
            $children = CostCenter::query()
                ->select(['id', 'doc_num'])
                ->forCompany((int) $costCenter->company_id)
                ->whereIn('parent_id', $parentIds)
                ->get();

            if ($children->isEmpty()) {
                break;
            }

            $excluded = array_merge($excluded, $children->pluck('doc_num')->all());
            $parentIds = $children->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        }

        return array_values(array_unique(array_filter($excluded)));
    }
}
