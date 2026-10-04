<?php

namespace Modules\Inventory\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class InventoryPeriodicCostCloseExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStrictNullComparison
{
    /** @param list<array{title: string, headings: list<string>, rows: list<list<string>>}> $sections */
    public function __construct(private readonly array $sections, private readonly bool $csv = false) {}

    /** @return list<list<string>> */
    public function array(): array
    {
        $rows = [];
        foreach ($this->sections as $section) {
            $rows[] = [$section['title']];
            $rows[] = $section['headings'];
            array_push($rows, ...$section['rows']);
            $rows[] = [''];
        }

        return $rows;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        $value = (string) ($value ?? '');
        if ($this->csv && preg_match('/^[=+@\t\r\n-]/', $value) && ! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            $value = "'".$value;
        }
        $cell->setValueExplicit($value, DataType::TYPE_STRING);

        return true;
    }
}
