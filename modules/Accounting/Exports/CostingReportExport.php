<?php

namespace Modules\Accounting\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

final class CostingReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /** @param array{columns: array<string, string>, rows: Collection<int, array<string, mixed>>} $report */
    public function __construct(private readonly array $report) {}

    public function collection(): Collection
    {
        $rows = $this->report['rows']->values();
        if (($this->report['totals'] ?? []) === []) {
            return $rows;
        }

        $total = array_fill_keys(array_keys($this->report['columns']), null);
        $firstColumn = array_key_first($this->report['columns']);
        $total[$firstColumn] = __('common.total');
        foreach ($this->report['totals'] as $key => $value) {
            if (array_key_exists($key, $total)) {
                $total[$key] = $value;
            }
        }

        return $rows->push($total);
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
}
