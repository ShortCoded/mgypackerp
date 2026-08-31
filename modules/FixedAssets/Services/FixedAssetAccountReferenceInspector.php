<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FixedAssetAccountReferenceInspector
{
    /**
     * @param  list<int>  $accountIds
     * @return array<int, list<array{table: string, column: string, count: int, rows: list<array<string, mixed>>}>>
     */
    public function inspect(array $accountIds): array
    {
        $accountIds = collect($accountIds)
            ->map(fn (mixed $accountId): int => (int) $accountId)
            ->filter(fn (int $accountId): bool => $accountId > 0)
            ->unique()
            ->values()
            ->all();
        $references = collect($accountIds)->mapWithKeys(fn (int $accountId): array => [$accountId => []])->all();

        if ($accountIds === []) {
            return $references;
        }

        foreach ($this->accountForeignKeys() as $foreignKey) {
            $table = $foreignKey['table'];
            $column = $foreignKey['column'];
            $counts = DB::table($table)
                ->whereIn($column, $accountIds)
                ->select($column)
                ->selectRaw('COUNT(*) AS aggregate_count')
                ->groupBy($column)
                ->pluck('aggregate_count', $column);

            if ($counts->isEmpty()) {
                continue;
            }

            $sampleColumns = $this->sampleColumns($table, $column);
            $samples = DB::table($table)
                ->whereIn($column, $counts->keys()->all())
                ->select($sampleColumns)
                ->orderBy($column)
                ->when(in_array('id', $sampleColumns, true), fn ($query) => $query->orderBy('id'))
                ->when($table === 'journal_entry_lines', fn ($query) => $query->limit(1000))
                ->get()
                ->groupBy(fn (object $row): int => (int) $row->{$column});

            foreach ($counts as $accountId => $count) {
                $references[(int) $accountId][] = [
                    'table' => $table,
                    'column' => $column,
                    'count' => (int) $count,
                    'rows' => $samples->get((int) $accountId, collect())
                        ->map(fn (object $row): array => $this->normalizeRow((array) $row))
                        ->values()
                        ->all(),
                ];
            }
        }

        return $references;
    }

    /**
     * @return list<array{table: string, column: string}>
     */
    private function accountForeignKeys(): array
    {
        $references = [];

        foreach (Schema::getTables() as $tableDefinition) {
            $table = (string) ($tableDefinition['name'] ?? '');

            if ($table === '') {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== 'accounts'
                    || ($foreignKey['foreign_columns'] ?? []) !== ['id']
                    || count($foreignKey['columns'] ?? []) !== 1
                ) {
                    continue;
                }

                $references[] = [
                    'table' => $table,
                    'column' => (string) $foreignKey['columns'][0],
                ];
            }
        }

        return collect($references)
            ->unique(fn (array $reference): string => $reference['table'].'.'.$reference['column'])
            ->sortBy(fn (array $reference): string => $reference['table'].'.'.$reference['column'])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function sampleColumns(string $table, string $foreignKeyColumn): array
    {
        $available = Schema::getColumnListing($table);
        $preferred = [
            $foreignKeyColumn,
            'id',
            'doc_num',
            'source_type',
            'source_id',
            'source_doc_num',
            'journal_entry_id',
            'fixed_asset_id',
            'account_id',
            'parent_id',
            'status',
            'is_posted',
            'debit_amount',
            'credit_amount',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        return collect($preferred)
            ->filter(fn (string $column): bool => in_array($column, $available, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return collect($row)
            ->map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value)
            ->all();
    }
}
