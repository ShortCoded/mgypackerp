<?php

namespace Modules\HR\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class PayrollReportExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    /** @param list<string> $headings @param list<list<mixed>> $rows @param list<int> $decimalColumns */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $decimalColumns,
    ) {}

    public function bindValue(Cell $cell, mixed $value): bool
    {
        $column = Coordinate::columnIndexFromString($cell->getColumn());

        if (in_array($column, $this->decimalColumns, true) && is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** @return list<list<mixed>> */
    public function array(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }
}
