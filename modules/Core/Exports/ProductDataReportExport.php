<?php

namespace Modules\Core\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Core\Models\Product;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\Reports\ProductDataReport;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ProductDataReportExport extends DefaultValueBinder implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithMapping
{
    private readonly NumericFormatService $numbers;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly ProductDataReport $report,
        private readonly array $filters = [],
        private readonly bool $forCsv = false,
        ?NumericFormatService $numbers = null,
    ) {
        $this->numbers = $numbers ?? app(NumericFormatService::class);
    }

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
        return $this->report->exportMap($row, $this->filters);
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        if ($this->forCsv) {
            return [];
        }

        if ($this->report->mode($this->filters) === ProductDataReport::ModeDetailed) {
            return [
                'H' => $this->numbers->excelNumberFormat(8),
                'I' => $this->numbers->excelNumberFormat(8),
            ];
        }

        return [
            'M' => $this->numbers->excelNumberFormat(4),
            'P' => $this->numbers->excelNumberFormat(0),
        ];
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($value !== null && ! in_array($cell->getColumn(), $this->numericColumns(), true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /**
     * @return list<string>
     */
    private function numericColumns(): array
    {
        if ($this->forCsv) {
            return [];
        }

        return $this->report->mode($this->filters) === ProductDataReport::ModeDetailed
            ? ['H', 'I']
            : ['M', 'P'];
    }
}
