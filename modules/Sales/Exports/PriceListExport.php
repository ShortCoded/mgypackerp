<?php

namespace Modules\Sales\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class PriceListExport extends DefaultValueBinder implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings
{
    /** @param array{export_headings: list<string>, export_rows: list<list<mixed>>} $report */
    public function __construct(private readonly array $report, private readonly bool $forCsv = false) {}

    public function collection(): Collection
    {
        return collect($this->report['export_rows'])->map(function (array $row): array {
            if (! $this->forCsv) {
                $row[14] = $row[14] === '' ? null : (float) $row[14];
                $row[16] = $row[16] === '' ? null : (float) $row[16];
            }

            return $row;
        });
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->report['export_headings'];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return $this->forCsv ? [] : ['O' => '#,##0.0000', 'Q' => '#,##0.0000'];
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($value !== null && ! in_array($cell->getColumn(), $this->numericColumns(), true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** @return list<string> */
    private function numericColumns(): array
    {
        return $this->forCsv ? [] : ['L', 'O', 'Q'];
    }
}
