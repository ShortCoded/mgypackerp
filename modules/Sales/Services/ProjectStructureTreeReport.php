<?php

namespace Modules\Sales\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\ProjectStructure;

class ProjectStructureTreeReport
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
    ) {}

    /**
     * @return array<string, string>
     */
    public function filtersFromRequest(Request $request): array
    {
        $filters = [];

        foreach (['project_structure_search', 'status', 'hierarchy'] as $field) {
            $value = trim((string) $request->input($field, ''));

            if ($value !== '') {
                $filters[$field] = $value;
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, string>  $filters
     * @return Builder<ProjectStructure>
     */
    public function query(array $filters = []): Builder
    {
        $companyId = $this->companies->currentCompanyId();
        $query = ProjectStructure::query()->with('parent');

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->forCompany($companyId);

        $search = $filters['project_structure_search'] ?? '';
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->whereRaw('LOWER(project_structures.code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(project_structures.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(project_structures.doc_num) LIKE ?', [$like]);
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('project_structures.status', $filters['status']);
        }

        if (($filters['hierarchy'] ?? '') === 'root') {
            $query->whereNull('project_structures.parent_id');
        } elseif (($filters['hierarchy'] ?? '') === 'children') {
            $query->whereNotNull('project_structures.parent_id');
        }

        return $query;
    }

    /**
     * @param  array<string, string>  $filters
     * @return Collection<int, ProjectStructure>
     */
    public function rows(array $filters = []): Collection
    {
        return $this->flattenTree($this->withAncestorContext($this->query($filters)->get()));
    }

    /**
     * @param  Collection<int, ProjectStructure>  $projectStructures
     * @return array<int, array<string, mixed>>
     */
    public function treeNodes(Collection $projectStructures): array
    {
        $byParent = $projectStructures
            ->groupBy(fn (ProjectStructure $projectStructure): int => (int) ($projectStructure->parent_id ?? 0))
            ->map(fn (Collection $siblings): Collection => $this->sortSiblings($siblings));
        $included = $projectStructures->keyBy(fn (ProjectStructure $projectStructure): int => (int) $projectStructure->getKey());
        $roots = $this->sortSiblings(
            $projectStructures->filter(fn (ProjectStructure $projectStructure): bool => $projectStructure->parent_id === null || ! $included->has((int) $projectStructure->parent_id))
        );

        return $this->nestedTree($roots, $byParent);
    }

    /**
     * @param  Collection<int, ProjectStructure>  $matched
     * @return Collection<int, ProjectStructure>
     */
    private function withAncestorContext(Collection $matched): Collection
    {
        if ($matched->isEmpty()) {
            return $matched;
        }

        $projectStructures = $matched->keyBy(fn (ProjectStructure $projectStructure): int => (int) $projectStructure->getKey());
        $missingParentIds = $this->missingParentIds($projectStructures);

        while ($missingParentIds->isNotEmpty()) {
            $parents = ProjectStructure::query()
                ->with('parent')
                ->forCompany($this->companies->requireCompanyId())
                ->whereIn('id', $missingParentIds->all())
                ->get();

            if ($parents->isEmpty()) {
                break;
            }

            foreach ($parents as $parent) {
                $projectStructures->put((int) $parent->getKey(), $parent);
            }

            $missingParentIds = $this->missingParentIds($projectStructures);
        }

        return $projectStructures->values();
    }

    /**
     * @param  Collection<int, ProjectStructure>  $projectStructures
     * @return Collection<int, int>
     */
    private function missingParentIds(Collection $projectStructures): Collection
    {
        return $projectStructures
            ->pluck('parent_id')
            ->filter()
            ->map(fn (mixed $parentId): int => (int) $parentId)
            ->reject(fn (int $parentId): bool => $projectStructures->has($parentId))
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, ProjectStructure>  $projectStructures
     * @return Collection<int, ProjectStructure>
     */
    private function flattenTree(Collection $projectStructures): Collection
    {
        $byParent = $projectStructures
            ->groupBy(fn (ProjectStructure $projectStructure): int => (int) ($projectStructure->parent_id ?? 0))
            ->map(fn (Collection $siblings): Collection => $this->sortSiblings($siblings));
        $included = $projectStructures->keyBy(fn (ProjectStructure $projectStructure): int => (int) $projectStructure->getKey());
        $roots = $this->sortSiblings(
            $projectStructures->filter(fn (ProjectStructure $projectStructure): bool => $projectStructure->parent_id === null || ! $included->has((int) $projectStructure->parent_id))
        );
        $flattened = collect();

        foreach ($roots as $root) {
            $this->appendTreeRows($root, $byParent, $flattened);
        }

        return $flattened->values();
    }

    /**
     * @param  Collection<int, Collection<int, ProjectStructure>>  $byParent
     * @param  Collection<int, ProjectStructure>  $flattened
     */
    private function appendTreeRows(ProjectStructure $projectStructure, Collection $byParent, Collection $flattened): void
    {
        $flattened->push($projectStructure);

        foreach ($byParent->get((int) $projectStructure->getKey(), collect()) as $child) {
            $this->appendTreeRows($child, $byParent, $flattened);
        }
    }

    /**
     * @param  Collection<int, ProjectStructure>  $projectStructures
     * @return Collection<int, ProjectStructure>
     */
    private function sortSiblings(Collection $projectStructures): Collection
    {
        return $projectStructures
            ->sort(function (ProjectStructure $first, ProjectStructure $second): int {
                $orderComparison = ((int) $first->sort_order) <=> ((int) $second->sort_order);

                if ($orderComparison !== 0) {
                    return $orderComparison;
                }

                $codeComparison = strnatcmp($first->code, $second->code);

                if ($codeComparison !== 0) {
                    return $codeComparison;
                }

                return strcmp($first->name, $second->name);
            })
            ->values();
    }

    /**
     * @param  Collection<int, ProjectStructure>  $projectStructures
     * @param  Collection<int, Collection<int, ProjectStructure>>  $byParent
     * @return array<int, array<string, mixed>>
     */
    private function nestedTree(Collection $projectStructures, Collection $byParent): array
    {
        return $projectStructures
            ->map(fn (ProjectStructure $projectStructure): array => [
                'id' => $projectStructure->doc_num,
                'code' => $projectStructure->code,
                'name' => $projectStructure->name,
                'status' => __("project_structures.statuses.{$projectStructure->status}"),
                'children' => $this->nestedTree($byParent->get((int) $projectStructure->getKey(), collect()), $byParent),
            ])
            ->values()
            ->all();
    }
}
