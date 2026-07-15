<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Services\OperatingCompanyContextService;

class CostCenterTreeReport
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

        foreach (['cost_center_search', 'status', 'hierarchy'] as $field) {
            $value = trim((string) $request->input($field, ''));

            if ($value !== '') {
                $filters[$field] = $value;
            }
        }

        return $filters;
    }

    /**
     * @param  array<string, string>  $filters
     * @return Builder<CostCenter>
     */
    public function query(array $filters = []): Builder
    {
        $companyId = $this->companies->currentCompanyId();
        $query = CostCenter::query()->with('parent');

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->forCompany($companyId);

        $search = $filters['cost_center_search'] ?? '';
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';

                $builder->whereRaw('LOWER(cost_centers.cost_center_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(cost_centers.name) LIKE ?', [$like]);
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('cost_centers.status', $filters['status']);
        }

        if (($filters['hierarchy'] ?? '') === 'root') {
            $query->whereNull('cost_centers.parent_id');
        } elseif (($filters['hierarchy'] ?? '') === 'children') {
            $query->whereNotNull('cost_centers.parent_id');
        }

        return $query;
    }

    /**
     * @param  array<string, string>  $filters
     * @return Collection<int, CostCenter>
     */
    public function rows(array $filters = []): Collection
    {
        return $this->flattenTree($this->withAncestorContext($this->query($filters)->get()));
    }

    /**
     * @param  Collection<int, CostCenter>  $costCenters
     * @return array<int, array<string, mixed>>
     */
    public function treeNodes(Collection $costCenters): array
    {
        $byParent = $costCenters
            ->groupBy(fn (CostCenter $costCenter): int => (int) ($costCenter->parent_id ?? 0))
            ->map(fn (Collection $siblings): Collection => $this->sortSiblings($siblings));
        $included = $costCenters->keyBy(fn (CostCenter $costCenter): int => (int) $costCenter->getKey());
        $roots = $this->sortSiblings(
            $costCenters->filter(fn (CostCenter $costCenter): bool => $costCenter->parent_id === null || ! $included->has((int) $costCenter->parent_id))
        );

        return $this->nestedTree($roots, $byParent);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            __('cost_centers.attributes.cost_center_code'),
            __('cost_centers.attributes.name'),
            __('cost_centers.attributes.is_group'),
            __('cost_centers.attributes.parent_code'),
            __('cost_centers.attributes.status'),
        ];
    }

    /**
     * @return list<string>
     */
    public function map(CostCenter $row): array
    {
        return [
            str_repeat('  ', max(0, $this->level($row) - 1)).$row->cost_center_code,
            $row->name,
            $row->is_group ? __('common.actions.yes') : __('common.actions.no'),
            $this->parentDisplay($row),
            __("cost_centers.statuses.{$row->status}"),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pdfRows(Collection $rows): array
    {
        return $rows
            ->map(fn (CostCenter $row): array => [
                'cost_center_code' => $row->cost_center_code,
                'name' => $row->name,
                'is_group' => $row->is_group ? __('common.actions.yes') : __('common.actions.no'),
                'level' => $this->level($row),
                'parent_code' => $this->parentDisplay($row),
                'status' => __("cost_centers.statuses.{$row->status}"),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    public function filterSummary(array $filters): array
    {
        $summary = [];

        foreach ($filters as $key => $value) {
            $labelKey = $key === 'cost_center_search' ? 'search' : $key;
            $summary[] = __('cost_centers.filters.'.$labelKey).': '.$this->filterValueLabel($key, $value);
        }

        return $summary;
    }

    private function filterValueLabel(string $key, string $value): string
    {
        return match ($key) {
            'status' => __("cost_centers.statuses.{$value}"),
            'hierarchy' => __("cost_centers.hierarchy_filters.{$value}"),
            default => $value,
        };
    }

    private function parentDisplay(CostCenter $costCenter): string
    {
        return $costCenter->parent?->codeNameLabel() ?? '';
    }

    private function level(CostCenter $costCenter): int
    {
        $level = 1;
        $parent = $costCenter->parent;

        while ($parent instanceof CostCenter) {
            $level++;
            $parent = $parent->parent;
        }

        return $level;
    }

    /**
     * @param  Collection<int, CostCenter>  $matched
     * @return Collection<int, CostCenter>
     */
    private function withAncestorContext(Collection $matched): Collection
    {
        if ($matched->isEmpty()) {
            return $matched;
        }

        $costCenters = $matched->keyBy(fn (CostCenter $costCenter): int => (int) $costCenter->getKey());
        $missingParentIds = $this->missingParentIds($costCenters);

        while ($missingParentIds->isNotEmpty()) {
            $parents = CostCenter::query()
                ->with('parent')
                ->forCompany($this->companies->requireCompanyId())
                ->whereIn('id', $missingParentIds->all())
                ->get();

            if ($parents->isEmpty()) {
                break;
            }

            foreach ($parents as $parent) {
                $costCenters->put((int) $parent->getKey(), $parent);
            }

            $missingParentIds = $this->missingParentIds($costCenters);
        }

        return $costCenters->values();
    }

    /**
     * @param  Collection<int, CostCenter>  $costCenters
     * @return Collection<int, int>
     */
    private function missingParentIds(Collection $costCenters): Collection
    {
        return $costCenters
            ->pluck('parent_id')
            ->filter()
            ->map(fn (mixed $parentId): int => (int) $parentId)
            ->reject(fn (int $parentId): bool => $costCenters->has($parentId))
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, CostCenter>  $costCenters
     * @return Collection<int, CostCenter>
     */
    private function flattenTree(Collection $costCenters): Collection
    {
        $byParent = $costCenters
            ->groupBy(fn (CostCenter $costCenter): int => (int) ($costCenter->parent_id ?? 0))
            ->map(fn (Collection $siblings): Collection => $this->sortSiblings($siblings));
        $included = $costCenters->keyBy(fn (CostCenter $costCenter): int => (int) $costCenter->getKey());
        $roots = $this->sortSiblings(
            $costCenters->filter(fn (CostCenter $costCenter): bool => $costCenter->parent_id === null || ! $included->has((int) $costCenter->parent_id))
        );
        $flattened = collect();

        foreach ($roots as $root) {
            $this->appendTreeRows($root, $byParent, $flattened);
        }

        return $flattened->values();
    }

    /**
     * @param  Collection<int, Collection<int, CostCenter>>  $byParent
     * @param  Collection<int, CostCenter>  $flattened
     */
    private function appendTreeRows(CostCenter $costCenter, Collection $byParent, Collection $flattened): void
    {
        $flattened->push($costCenter);

        foreach ($byParent->get((int) $costCenter->getKey(), collect()) as $child) {
            $this->appendTreeRows($child, $byParent, $flattened);
        }
    }

    /**
     * @param  Collection<int, CostCenter>  $costCenters
     * @return Collection<int, CostCenter>
     */
    private function sortSiblings(Collection $costCenters): Collection
    {
        return $costCenters
            ->sort(function (CostCenter $first, CostCenter $second): int {
                $codeComparison = strnatcmp($first->cost_center_code, $second->cost_center_code);

                if ($codeComparison !== 0) {
                    return $codeComparison;
                }

                return strcmp($first->name, $second->name);
            })
            ->values();
    }

    /**
     * @param  Collection<int, CostCenter>  $costCenters
     * @param  Collection<int, Collection<int, CostCenter>>  $byParent
     * @return array<int, array<string, mixed>>
     */
    private function nestedTree(Collection $costCenters, Collection $byParent): array
    {
        return $costCenters
            ->map(fn (CostCenter $costCenter): array => [
                'id' => $costCenter->doc_num,
                'cost_center_code' => $costCenter->cost_center_code,
                'name' => $costCenter->name,
                'is_group' => (bool) $costCenter->is_group,
                'status' => __("cost_centers.statuses.{$costCenter->status}"),
                'children' => $this->nestedTree($byParent->get((int) $costCenter->getKey(), collect()), $byParent),
            ])
            ->values()
            ->all();
    }
}
