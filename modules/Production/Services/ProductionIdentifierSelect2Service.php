<?php

namespace Modules\Production\Services;

use Illuminate\Http\Request;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Production\Models\ProductionIdentifier;

class ProductionIdentifierSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly Select2ResponseService $select2,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function identifiers(Request $request): array
    {
        $companyId = $this->companies->currentCompanyId($request);
        $query = ProductionIdentifier::query()
            ->select(['id', 'doc_num', 'name', 'doc_number', 'company_id'])
            ->where('status', 'active')
            ->where('is_group', true)
            ->orderBy('doc_number')
            ->orderBy('name');

        if ($companyId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->forCompany($companyId);
        }

        if ($request->filled('exclude') && $companyId !== null) {
            $excluded = ProductionIdentifier::query()
                ->select(['id', 'doc_num', 'company_id'])
                ->forCompany($companyId)
                ->where('doc_num', $request->string('exclude')->toString())
                ->first();

            if ($excluded instanceof ProductionIdentifier) {
                $query->whereNotIn('doc_num', $this->excludedDocNums($excluded));
            } else {
                $query->where('doc_num', '!=', $request->string('exclude')->toString());
            }
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name']]);
        }

        return $this->select2->paginated($query, $request, fn (ProductionIdentifier $identifier): array => [
            'id' => $identifier->doc_num,
            'text' => $identifier->documentNameLabel(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function excludedDocNums(ProductionIdentifier $identifier): array
    {
        $excluded = [$identifier->doc_num];
        $parentIds = [$identifier->getKey()];

        while ($parentIds !== []) {
            $children = ProductionIdentifier::query()
                ->select(['id', 'doc_num'])
                ->forCompany((int) $identifier->company_id)
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
