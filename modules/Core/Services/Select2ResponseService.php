<?php

namespace Modules\Core\Services;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class Select2ResponseService
{
    /**
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  Closure(mixed): array{id: string, text: string}  $map
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function paginated(EloquentBuilder|QueryBuilder $query, Request $request, Closure $map): array
    {
        $perPage = $this->perPage();
        $page = max(1, (int) $request->integer('page', 1));
        $items = $query
            ->skip(($page - 1) * $perPage)
            ->take($perPage + 1)
            ->get();

        $collection = $this->collection($items);
        $hasMore = $collection->count() > $perPage;

        return [
            'results' => $collection
                ->take($perPage)
                ->map($map)
                ->values()
                ->all(),
            'pagination' => [
                'more' => $hasMore,
            ],
        ];
    }

    public function perPage(): int
    {
        return max(1, min(100, (int) config('select2.pagination.per_page', 25)));
    }

    private function collection(mixed $items): Collection
    {
        if ($items instanceof Collection) {
            return $items;
        }

        if ($items instanceof EloquentCollection) {
            return $items->toBase();
        }

        if ($items instanceof Arrayable) {
            return collect($items->toArray());
        }

        return collect($items);
    }
}
