<?php

namespace Modules\Maintenance\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Core\Services\DateFormatService;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class MaintenanceOperationsReportExport implements WithMultipleSheets
{
    /** @param array<string, mixed> $report */
    public function __construct(
        private readonly array $report,
        private readonly bool $forCsv = false,
    ) {}

    /** @return list<MaintenanceOperationsReportSheet> */
    public function sheets(): array
    {
        $orders = new MaintenanceWorkOrderExport($this->report['orders'], $this->report['canViewFinancial']);
        $sheets = [
            new MaintenanceOperationsReportSheet(
                __('maintenance.reports.title'),
                [__('production_execution.reports.columns.metric'), __('production_execution.reports.columns.value')],
                collect($this->report['kpis'])->map(fn ($value, string $key): array => [__('maintenance.reports.kpis.'.$key), $value])->values()->all(),
            ),
            new MaintenanceOperationsReportSheet(
                __('maintenance.reports.requests_table'),
                [__('maintenance.fields.document'), __('maintenance.fields.reported_at'), __('maintenance.fields.asset'), __('maintenance.fields.request_type'), __('maintenance.fields.priority'), __('maintenance.fields.is_machine_stopped'), __('maintenance.fields.status')],
                $this->report['requests']->map(fn ($request): array => [
                    $request->doc_num,
                    app(DateFormatService::class)->formatDateTime($request->reported_at, ''),
                    $request->asset?->asset_name ?: $request->mold?->name ?: '—',
                    __('maintenance.request_types.'.$request->request_type),
                    __('maintenance.priorities.'.$request->priority),
                    $request->is_machine_stopped ? __('Yes') : __('No'),
                    __('maintenance.statuses.'.$request->status),
                ])->values()->all(),
            ),
            new MaintenanceOperationsReportSheet(
                __('maintenance.reports.plan_due_table'),
                [__('maintenance.fields.source_plan'), __('maintenance.fields.asset'), __('maintenance.fields.due_at'), __('maintenance.fields.status')],
                $this->report['planDues']->map(fn ($due): array => [
                    $due->plan?->doc_num.' — '.$due->plan?->name,
                    $due->plan?->asset?->asset_name ?: $due->plan?->mold?->name ?: '—',
                    app(DateFormatService::class)->formatDateTime($due->due_at, ''),
                    __('maintenance.statuses.'.$due->status),
                ])->values()->all(),
            ),
            new MaintenanceOperationsReportSheet(
                __('maintenance.reports.material_quantities_by_unit'),
                [__('Unit'), __('maintenance.reports.requested_material_quantity'), __('maintenance.reports.issued_material_quantity'), __('maintenance.reports.consumed_material_quantity'), __('maintenance.reports.returned_material_quantity'), __('maintenance.reports.net_material_quantity')],
                $this->report['materialQuantityTotals']->map(fn (array $total): array => [
                    $total['unit'], $total['requested'], $total['issued'], $total['consumed'], $total['returned'], $total['net'],
                ])->values()->all(),
            ),
            new MaintenanceOperationsReportSheet(
                __('maintenance.reports.orders_table'),
                $orders->headings(),
                $orders->array(),
            ),
        ];

        if ($this->report['canViewFinancial']) {
            $sheets[] = new MaintenanceOperationsReportSheet(
                __('maintenance.reports.expense_totals'),
                [__('maintenance.fields.currency'), __('maintenance.reports.requested_amount'), __('maintenance.reports.paid_amount'), __('maintenance.reports.request_count')],
                $this->report['expenseTotals']->map(fn (array $total): array => [
                    $total['currency'], $total['requested'], $total['paid'], $total['count'],
                ])->values()->all(),
            );
        }

        if (! $this->forCsv) {
            return $sheets;
        }

        $rows = [];
        $maximumColumns = max(array_map(fn ($sheet): int => count($sheet->headings()), $sheets));
        foreach ($sheets as $sheet) {
            $rows[] = [$sheet->sectionTitle(), __('sales_ui.reports.export.row_types.headings'), ...$sheet->headings()];
            foreach ($sheet->array() as $row) {
                $rows[] = [$sheet->sectionTitle(), __('sales_ui.reports.export.row_types.data'), ...$row];
            }
        }

        return [new MaintenanceOperationsReportSheet(
            __('maintenance.reports.title'),
            [__('sales_ui.reports.export.headings.section'), __('sales_ui.reports.export.headings.row_type'), ...array_fill(0, $maximumColumns, '')],
            $rows,
        )];
    }
}

class MaintenanceOperationsReportSheet extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithTitle
{
    /** @param list<string> $headings @param list<array<int, mixed>> $rows */
    public function __construct(
        private readonly string $sheetTitle,
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return mb_substr($this->sheetTitle, 0, 31);
    }

    public function sectionTitle(): string
    {
        return $this->sheetTitle;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && preg_match('/^-?\d+\.\d+$/D', $value) === 1) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
