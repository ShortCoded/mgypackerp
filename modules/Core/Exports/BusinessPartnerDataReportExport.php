<?php

namespace Modules\Core\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Events\BeforeSheet;
use Modules\Core\Services\Reports\BusinessPartnerDataReport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class BusinessPartnerDataReportExport extends StringValueBinder implements FromQuery, WithColumnWidths, WithCustomChunkSize, WithCustomCsvSettings, WithCustomStartCell, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithTitle
{
    private const HeadingRow = 6;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly BusinessPartnerDataReport $report,
        private readonly array $filters = [],
    ) {}

    /**
     * @return Builder<*>
     */
    public function query(): Builder
    {
        return $this->report->orderedQuery($this->filters);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(fn (string $heading): string => $this->safeText($heading), $this->report->headings());
    }

    /**
     * @return list<string>
     */
    public function map($row): array
    {
        return array_map(fn (mixed $value): string => $this->safeText($value), $this->report->map($row));
    }

    public function startCell(): string
    {
        return 'A'.self::HeadingRow;
    }

    public function title(): string
    {
        return $this->report->reportTitle();
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'line_ending' => "\r\n",
            'use_bom' => true,
            'output_encoding' => 'UTF-8',
        ];
    }

    /**
     * @return array<string, float>
     */
    public function columnWidths(): array
    {
        return [
            'A' => 20,
            'B' => 28,
            'C' => 24,
            'D' => 24,
            'E' => 18,
            'F' => 18,
            'G' => 28,
            'H' => 22,
            'I' => 36,
            'J' => 18,
            'K' => 18,
            'L' => 18,
            'M' => 18,
            'N' => 20,
            'O' => 22,
            'P' => 32,
            'Q' => 14,
            'R' => 22,
        ];
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $filters = $this->report->filterSummary($this->filters);

                $sheet->setCellValueExplicit('A1', $this->safeText($this->report->reportTitle()), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('A2', $this->safeText(__('reports.company')), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B2', $this->safeText($this->report->companyName()), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('A3', $this->safeText(__('reports.generated_at')), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B3', $this->safeText($this->report->generatedAtLabel()), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('A4', $this->safeText(__('reports.active_filters')), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B4', $this->safeText($filters === [] ? __('reports.all_records') : implode(' | ', $filters)), DataType::TYPE_STRING);
            },
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = Coordinate::stringFromColumnIndex(count(BusinessPartnerDataReport::ColumnKeys));
                $highestRow = max(self::HeadingRow, $sheet->getHighestRow());

                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->mergeCells("B2:{$lastColumn}2");
                $sheet->mergeCells("B3:{$lastColumn}3");
                $sheet->mergeCells("B4:{$lastColumn}4");
                $sheet->freezePane('A'.(self::HeadingRow + 1));
                $sheet->setAutoFilter('A'.self::HeadingRow.":{$lastColumn}".self::HeadingRow);
                $sheet->setRightToLeft($this->report->isRtl());
                $sheet->getStyle("A1:{$lastColumn}{$highestRow}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->setSize(16);
                $sheet->getStyle('A2:A4')->getFont()->setBold(true);
                $sheet->getStyle('A'.self::HeadingRow.":{$lastColumn}".self::HeadingRow)->getFont()->setBold(true);
                $sheet->getStyle('A'.self::HeadingRow.":{$lastColumn}".self::HeadingRow)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE7EEF8');
            },
        ];
    }

    private function safeText(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'{$value}" : $value;
    }
}
