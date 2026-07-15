<?php

namespace Modules\Core\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Core\Models\Product;
use Modules\Core\Services\Reports\ProductDataReport;

class ProductDataReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly ProductDataReport $report,
        private readonly array $filters = [],
    ) {}

    /**
     * @return Collection<int, Product>
     */
    public function collection(): Collection
    {
        return $this->report->rows($this->filters);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->report->headings($this->filters);
    }

    /**
     * @return list<mixed>
     */
    public function map($row): array
    {
        return $this->report->map($row, $this->filters);
    }
}
