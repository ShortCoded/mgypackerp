<?php

namespace Modules\FixedAssets\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Modules\Core\Services\DateFormatService;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

class FixedAssetReportExport extends StringValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithMapping, WithStrictNullComparison
{
    /** @param array{columns: array<string, string>, rows: Collection<int, array<string, mixed>>} $report */
    public function __construct(private readonly array $report) {}

    public function collection(): Collection
    {
        $summary = collect($this->report['totals'] ?? [])->map(fn (mixed $value, string $label): array => [
            '_report_summary_label' => __('common.total').' — '.$label,
            '_report_summary_value' => $value,
        ])->values();

        return $this->report['rows']->concat($summary);
    }

    public function headings(): array
    {
        return array_values($this->report['columns']);
    }

    public function map($row): array
    {
        if (array_key_exists('_report_summary_label', $row)) {
            return [$row['_report_summary_label'], $row['_report_summary_value'], ...array_fill(0, max(0, count($this->report['columns']) - 2), '')];
        }

        return array_map(function (string $key) use ($row): mixed {
            $value = data_get($row, $key);

            return $value instanceof \DateTimeInterface ? app(DateFormatService::class)->formatDate($value, '') : $value;
        }, array_keys($this->report['columns']));
    }
}
