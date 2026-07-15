<?php

namespace Modules\Sales\Services;

use Illuminate\Http\Request;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Sales\Models\ProjectStructure;

class ProjectStructureSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly Select2ResponseService $select2,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function projectStructures(Request $request): array
    {
        $companyId = $this->companies->currentCompanyId($request);
        $query = ProjectStructure::query()
            ->select(['id', 'parent_id', 'doc_num', 'doc_number', 'code', 'name'])
            ->active()
            ->ordered();

        if ($companyId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->forCompany($companyId);
        }

        if ($request->filled('exclude') && $companyId !== null) {
            $excluded = ProjectStructure::query()
                ->select(['id', 'doc_num'])
                ->forCompany($companyId)
                ->where('doc_num', $request->string('exclude')->toString())
                ->first();

            if ($excluded instanceof ProjectStructure) {
                $query->whereNotIn('doc_num', $this->excludedDocNums($excluded));
            } else {
                $query->where('doc_num', '!=', $request->string('exclude')->toString());
            }
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => ['doc_num', 'code', 'name'],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (ProjectStructure $projectStructure): array => [
            'id' => (string) $projectStructure->doc_num,
            'text' => $projectStructure->label(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function excludedDocNums(ProjectStructure $projectStructure): array
    {
        $excluded = [$projectStructure->doc_num];
        $parentIds = [$projectStructure->getKey()];

        while ($parentIds !== []) {
            $children = ProjectStructure::query()
                ->select(['id', 'doc_num'])
                ->forCompany((int) $projectStructure->company_id)
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
