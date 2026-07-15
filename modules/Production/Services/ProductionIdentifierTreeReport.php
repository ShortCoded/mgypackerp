<?php

namespace Modules\Production\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Production\Models\ProductionIdentifier;

class ProductionIdentifierTreeReport
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
    ) {}

    /**
     * @return array<string, string>
     */
    public function filtersFromRequest(Request $request): array
    {
        return [];
    }

    /**
     * @param  array<string, string>  $filters
     * @return Builder<ProductionIdentifier>
     */
    public function query(array $filters = []): Builder
    {
        $companyId = $this->companies->currentCompanyId();
        $query = ProductionIdentifier::query()->with('parent');

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->forCompany($companyId);

        return $query;
    }

    /**
     * @param  array<string, string>  $filters
     * @return Collection<int, ProductionIdentifier>
     */
    public function rows(array $filters = []): Collection
    {
        return $this->flattenTree($this->withAncestorContext($this->query($filters)->get()));
    }

    /**
     * @param  Collection<int, ProductionIdentifier>  $identifiers
     * @return array<int, array<string, mixed>>
     */
    public function treeNodes(Collection $identifiers): array
    {
        $byParent = $identifiers
            ->groupBy(fn (ProductionIdentifier $identifier): int => (int) ($identifier->parent_id ?? 0))
            ->map(fn (Collection $siblings): Collection => $this->sortSiblings($siblings));
        $included = $identifiers->keyBy(fn (ProductionIdentifier $identifier): int => (int) $identifier->getKey());
        $roots = $this->sortSiblings(
            $identifiers->filter(fn (ProductionIdentifier $identifier): bool => $identifier->parent_id === null || ! $included->has((int) $identifier->parent_id))
        );

        return $this->nestedTree($roots, $byParent);
    }

    /**
     * @param  Collection<int, ProductionIdentifier>  $matched
     * @return Collection<int, ProductionIdentifier>
     */
    private function withAncestorContext(Collection $matched): Collection
    {
        if ($matched->isEmpty()) {
            return $matched;
        }

        $identifiers = $matched->keyBy(fn (ProductionIdentifier $identifier): int => (int) $identifier->getKey());
        $missingParentIds = $this->missingParentIds($identifiers);

        while ($missingParentIds->isNotEmpty()) {
            $parents = ProductionIdentifier::query()
                ->with('parent')
                ->forCompany($this->companies->requireCompanyId())
                ->whereIn('id', $missingParentIds->all())
                ->get();

            if ($parents->isEmpty()) {
                break;
            }

            foreach ($parents as $parent) {
                $identifiers->put((int) $parent->getKey(), $parent);
            }

            $missingParentIds = $this->missingParentIds($identifiers);
        }

        return $identifiers->values();
    }

    /**
     * @param  Collection<int, ProductionIdentifier>  $identifiers
     * @return Collection<int, int>
     */
    private function missingParentIds(Collection $identifiers): Collection
    {
        return $identifiers
            ->pluck('parent_id')
            ->filter()
            ->map(fn (mixed $parentId): int => (int) $parentId)
            ->reject(fn (int $parentId): bool => $identifiers->has($parentId))
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, ProductionIdentifier>  $identifiers
     * @return Collection<int, ProductionIdentifier>
     */
    private function flattenTree(Collection $identifiers): Collection
    {
        $byParent = $identifiers
            ->groupBy(fn (ProductionIdentifier $identifier): int => (int) ($identifier->parent_id ?? 0))
            ->map(fn (Collection $siblings): Collection => $this->sortSiblings($siblings));
        $included = $identifiers->keyBy(fn (ProductionIdentifier $identifier): int => (int) $identifier->getKey());
        $roots = $this->sortSiblings(
            $identifiers->filter(fn (ProductionIdentifier $identifier): bool => $identifier->parent_id === null || ! $included->has((int) $identifier->parent_id))
        );
        $flattened = collect();

        foreach ($roots as $root) {
            $this->appendTreeRows($root, $byParent, $flattened);
        }

        return $flattened->values();
    }

    /**
     * @param  Collection<int, Collection<int, ProductionIdentifier>>  $byParent
     * @param  Collection<int, ProductionIdentifier>  $flattened
     */
    private function appendTreeRows(ProductionIdentifier $identifier, Collection $byParent, Collection $flattened): void
    {
        $flattened->push($identifier);

        foreach ($byParent->get((int) $identifier->getKey(), collect()) as $child) {
            $this->appendTreeRows($child, $byParent, $flattened);
        }
    }

    /**
     * @param  Collection<int, ProductionIdentifier>  $identifiers
     * @return Collection<int, ProductionIdentifier>
     */
    private function sortSiblings(Collection $identifiers): Collection
    {
        return $identifiers
            ->sort(function (ProductionIdentifier $first, ProductionIdentifier $second): int {
                if ((int) ($first->doc_number ?? 0) !== (int) ($second->doc_number ?? 0)) {
                    return (int) ($first->doc_number ?? 0) <=> (int) ($second->doc_number ?? 0);
                }

                return strcmp($first->name, $second->name);
            })
            ->values();
    }

    /**
     * @param  Collection<int, ProductionIdentifier>  $identifiers
     * @param  Collection<int, Collection<int, ProductionIdentifier>>  $byParent
     * @return array<int, array<string, mixed>>
     */
    private function nestedTree(Collection $identifiers, Collection $byParent): array
    {
        return $identifiers
            ->map(fn (ProductionIdentifier $identifier): array => [
                'id' => $identifier->doc_num,
                'doc_num' => $identifier->doc_num,
                'name' => $identifier->name,
                'is_group' => (bool) $identifier->is_group,
                'status' => __("production_identifiers.statuses.{$identifier->status}"),
                'children' => $this->nestedTree($byParent->get((int) $identifier->getKey(), collect()), $byParent),
            ])
            ->values()
            ->all();
    }
}
