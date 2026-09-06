<?php

namespace Modules\Purchases\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;

class ProcurementCycleReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(
        private readonly ProcurementCycleReport $report,
        private readonly Collection $rows,
        private readonly bool $showPrices,
        private readonly ?string $reportType = null,
        private readonly string $detailLevel = 'summary',
    ) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->report->headings($this->showPrices, $this->reportType, $this->detailLevel);
    }

    /** @param array<string, mixed> $row */
    public function map($row): array
    {
        return $this->report->exportMap($row, $this->showPrices, $this->reportType, $this->detailLevel);
    }
}
