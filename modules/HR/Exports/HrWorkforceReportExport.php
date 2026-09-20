<?php

namespace Modules\HR\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class HrWorkforceReportExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison
{
    /** @param list<string> $headings @param list<list<mixed>> $rows */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
    ) {}

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
