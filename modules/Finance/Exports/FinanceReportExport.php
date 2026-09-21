<?php

namespace Modules\Finance\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class FinanceReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /** @param array{columns: array<string, string>, rows: Collection<int, array<string, mixed>>, currency_totals?: array<string, array<string, string>>} $report */
    public function __construct(private readonly array $report) {}

    /** @return Collection<int, array<string, mixed>> */
    public function collection(): Collection
    {
        return $this->report['rows']->values()
            ->concat($this->currencyTotalRows())
            ->values();
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_values($this->report['columns']);
    }

    /** @return list<mixed> */
    public function map($row): array
    {
        return array_map(fn (string $key): mixed => data_get($row, $key), array_keys($this->report['columns']));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function currencyTotalRows(): Collection
    {
        $columns = $this->report['columns'];
        $columnKeys = array_keys($columns);

        return collect($this->report['currency_totals'] ?? [])->map(function (array $totals, string $currency) use ($columns, $columnKeys): array {
            $totalColumnKeys = collect($totals)
                ->keys()
                ->map(fn (string $label): int|string|false => array_search($label, $columns, true))
                ->filter(fn (int|string|false $key): bool => is_string($key))
                ->values()
                ->all();
            $markerKey = collect($columnKeys)->first(
                fn (string $key): bool => $key !== 'currency' && ! in_array($key, $totalColumnKeys, true),
            ) ?? collect($columnKeys)->first(fn (string $key): bool => $key !== 'currency');
            $row = array_fill_keys($columnKeys, null);

            if (is_string($markerKey)) {
                $row[$markerKey] = __('common.total');
            }
            if (array_key_exists('currency', $columns)) {
                $row['currency'] = $currency;
            }
            foreach ($totals as $label => $value) {
                $columnKey = array_search($label, $columns, true);
                if (is_string($columnKey)) {
                    $row[$columnKey] = $value;
                }
            }

            $row['_is_total'] = true;
            $row['_total_currency'] = $currency;

            return $row;
        })->values();
    }
}
