<?php

namespace Modules\Finance\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class FinanceReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /** @param array{columns: array<string, string>, rows: Collection<int, array<string, mixed>>} $report */
    public function __construct(private readonly array $report) {}

    public function collection(): Collection
    {
        return $this->report['rows'];
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
