<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

class DataTableSearchService
{
    private const MaxTerms = 8;

    private const PostgreSqlDateTimeFormat = 'DD/MM/YYYY HH12:MI AM';

    private const SqliteLikeEscape = " ESCAPE '\\'";

    /**
     * pg_trgm indexes can be added later on large tables that need faster
     * contains-style ILIKE searches.
     *
     * @return list<string>
     */
    public function terms(?string $search): array
    {
        if ($search === null || trim($search) === '') {
            return [];
        }

        $terms = [];
        $seen = [];

        foreach (preg_split('/&&/', $search) ?: [] as $term) {
            $term = trim((string) $term);

            if ($term === '') {
                continue;
            }

            $key = mb_strtolower($term);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $terms[] = $term;

            if (count($terms) >= self::MaxTerms) {
                break;
            }
        }

        return $terms;
    }

    public function likePattern(string $term): string
    {
        return '%'.str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            trim($term),
        ).'%';
    }

    public function parseDateTerm(string $term): ?Carbon
    {
        $term = trim($term);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $term);
            } catch (\Throwable) {
                continue;
            }

            if ($date instanceof Carbon && $date->format($format) === $term) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    /**
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  list<string>  $terms
     * @param  array{text?: list<string>, dates?: list<string>, date_text?: list<string>, exists?: list<array{table: string, first: string, operator?: string, second: string, where?: list<array{0: string, 1: mixed, 2?: mixed}>, join?: array{table: string, first: string, operator?: string, second: string}, columns: list<string>}>}  $columnsConfig
     */
    public function applyMultiTermSearch(EloquentBuilder|QueryBuilder $query, array $terms, array $columnsConfig): void
    {
        $textColumns = $columnsConfig['text'] ?? [];
        $dateColumns = $columnsConfig['dates'] ?? [];
        $dateTextColumns = $columnsConfig['date_text'] ?? $dateColumns;
        $existsConfigs = $columnsConfig['exists'] ?? [];

        foreach ($terms as $term) {
            $pattern = $this->likePattern($term);
            $parsedDate = $this->parseDateTerm($term);

            $query->where(function (EloquentBuilder|QueryBuilder $termQuery) use ($textColumns, $dateColumns, $dateTextColumns, $existsConfigs, $pattern, $parsedDate): void {
                foreach ($textColumns as $column) {
                    $this->applyTextSearch($termQuery, $column, $pattern);
                }

                if ($parsedDate instanceof Carbon) {
                    foreach ($dateColumns as $column) {
                        $this->applyDateSearch($termQuery, $column, $parsedDate);
                    }
                } else {
                    foreach ($dateTextColumns as $column) {
                        foreach ($this->dateTextSearchSql($termQuery, $column) as $sql) {
                            $termQuery->orWhereRaw($sql, [$pattern]);
                        }
                    }
                }

                foreach ($existsConfigs as $existsConfig) {
                    $this->applyExistsSearch($termQuery, $existsConfig, $pattern);
                }
            });
        }
    }

    /**
     * @param  array{table: string, first: string, operator?: string, second: string, where?: list<array{0: string, 1: mixed, 2?: mixed}>, join?: array{table: string, first: string, operator?: string, second: string}, columns: list<string>}  $config
     */
    private function applyExistsSearch(EloquentBuilder|QueryBuilder $query, array $config, string $pattern): void
    {
        $query->orWhereExists(function (QueryBuilder $existsQuery) use ($config, $pattern): void {
            $existsQuery
                ->selectRaw('1')
                ->from($config['table'])
                ->whereColumn($config['first'], $config['operator'] ?? '=', $config['second']);

            if (isset($config['join'])) {
                $join = $config['join'];
                $existsQuery->join($join['table'], $join['first'], $join['operator'] ?? '=', $join['second']);
            }

            foreach ($config['where'] ?? [] as $where) {
                if (array_key_exists(2, $where)) {
                    $existsQuery->where($where[0], $where[1], $where[2]);

                    continue;
                }

                $existsQuery->where($where[0], $where[1]);
            }

            $existsQuery->where(function (QueryBuilder $existsSearchQuery) use ($config, $pattern): void {
                foreach ($config['columns'] as $column) {
                    $this->applyTextSearch($existsSearchQuery, $column, $pattern);
                }
            });
        });
    }

    private function applyTextSearch(EloquentBuilder|QueryBuilder $query, string $column, string $pattern): void
    {
        if ($this->driverName($query) === 'pgsql') {
            $query->orWhere($column, 'ILIKE', $pattern);

            return;
        }

        $column = $this->wrap($query, $column);

        $query->orWhereRaw("LOWER(CAST({$column} AS TEXT)) LIKE LOWER(?)".self::SqliteLikeEscape, [$pattern]);
    }

    private function applyDateSearch(EloquentBuilder|QueryBuilder $query, string $column, Carbon $date): void
    {
        $start = $date->copy()->startOfDay();
        $end = $start->copy()->addDay();

        $query->orWhere(function (EloquentBuilder|QueryBuilder $dateQuery) use ($column, $start, $end): void {
            $dateQuery
                ->where($column, '>=', $start->toDateString())
                ->where($column, '<', $end->toDateString());
        });
    }

    /**
     * @return list<string>
     */
    private function dateTextSearchSql(EloquentBuilder|QueryBuilder $query, string $column): array
    {
        $column = $this->wrap($query, $column);

        if ($this->driverName($query) === 'pgsql') {
            return ["to_char({$column}, '".self::PostgreSqlDateTimeFormat."') ILIKE ?"];
        }

        return [
            "strftime('%d/%m/%Y %H:%M', {$column}) LIKE ?".self::SqliteLikeEscape,
            "strftime('%Y-%m-%d %H:%M', {$column}) LIKE ?".self::SqliteLikeEscape,
        ];
    }

    private function wrap(EloquentBuilder|QueryBuilder $query, string $column): string
    {
        return $this->baseQuery($query)->getGrammar()->wrap($column);
    }

    private function driverName(EloquentBuilder|QueryBuilder $query): string
    {
        return $this->baseQuery($query)->getConnection()->getDriverName();
    }

    private function baseQuery(EloquentBuilder|QueryBuilder $query): QueryBuilder
    {
        return $query instanceof EloquentBuilder ? $query->getQuery() : $query;
    }
}
