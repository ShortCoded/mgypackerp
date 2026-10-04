<?php

namespace Modules\HR\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\HR\Models\HrAttendanceSession;
use Modules\HR\Services\HrAttendanceReportService;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class HrAttendanceReportExport extends DefaultValueBinder implements FromQuery, ShouldAutoSize, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison
{
    /** @param array<string, mixed> $filters @param list<int>|null $branchIds */
    public function __construct(
        private readonly HrAttendanceReportService $reports,
        private readonly int $companyId,
        private readonly array $filters,
        private readonly ?array $branchIds,
    ) {}

    /** @return Builder<HrAttendanceSession> */
    public function query(): Builder
    {
        return $this->reports->exportQuery($this->companyId, $this->filters, $this->branchIds);
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->reports->headings();
    }

    /** @return list<mixed> */
    public function map(mixed $row): array
    {
        return $this->reports->row($row);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $summary = $this->reports->summary($this->companyId, $this->filters, $this->branchIds);
                $summaryRows = $this->reports->summaryRows($summary);
                if ($summaryRows === []) {
                    return;
                }

                $sheet = $event->sheet->getDelegate();
                $firstSummaryRow = $sheet->getHighestRow() + 2;
                foreach ($summaryRows as $offset => [$label, $value]) {
                    $row = $firstSummaryRow + $offset;
                    $sheet->setCellValueExplicit('A'.$row, $label, DataType::TYPE_STRING);
                    $sheet->setCellValue('B'.$row, $value);
                }
                $sheet->getStyle('A'.$firstSummaryRow.':B'.($firstSummaryRow + count($summaryRows) - 1))->getFont()->setBold(true);
            },
        ];
    }
}
