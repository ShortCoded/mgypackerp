<?php

namespace Modules\Accounting\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Accounting\Services\AccountTreeReport;

class AccountsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  array<string, string>  $filters
     */
    public function __construct(
        private readonly AccountTreeReport $report,
        private readonly array $filters = [],
    ) {}

    public function collection(): Collection
    {
        return $this->report->rows($this->filters);
    }

    public function headings(): array
    {
        return $this->report->headings();
    }

    public function map($row): array
    {
        return $this->report->map($row);
    }
}
