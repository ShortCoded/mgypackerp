<?php

namespace Modules\Production\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Core\Services\DateFormatService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class ProductionReportExport implements WithMultipleSheets
{
    /** @param array<string, mixed> $report */
    public function __construct(
        private readonly array $report,
        private readonly string $section = 'overview',
    ) {}

    /** @return list<ProductionReportSheet> */
    public function sheets(): array
    {
        if ($this->section === 'control') {
            return $this->controlSheets();
        }

        $runRows = collect($this->report['runs'])->map(fn ($run): array => [$run->run_number, $run->order?->doc_num, $run->order?->salesOrder?->doc_num, $run->stageSnapshot?->stage_name, $run->fixedAsset?->asset_name, $run->product?->doc_num, $run->product?->name, app(DateFormatService::class)->formatDateTime($run->actual_start_at, ''), app(DateFormatService::class)->formatDateTime($run->actual_end_at, ''), $run->actualDurationHours(), $run->planned_labor_count, $run->actual_labor_count, $run->totalLaborHours(), $run->planned_base_quantity, $run->good_base_quantity, $run->rejected_base_quantity, $run->rework_base_quantity, $run->scrap_base_quantity, $run->received_base_quantity, $run->yield_percent, __('production_execution.statuses.'.$run->status)]);
        $runRows->push([__('production_execution.reports.columns.total'), null, null, null, null, null, null, null, null, null, null, null, null, $this->report['kpis']['planned_base_quantity'], $this->report['kpis']['good_base_quantity'], null, null, $this->report['kpis']['loss_base_quantity'], null, null, null]);
        $sheets = [
            'overview' => $this->sheet(
                __('production_execution.reports.sections.overview'),
                [__('production_execution.reports.columns.metric'), __('production_execution.reports.columns.value')],
                collect($this->report['kpis'])->map(fn ($value, string $key): array => [__('production_execution.reports.kpis.'.$key), $value]),
            ),
            'orders' => $this->sheet(__('production_execution.reports.sections.orders'), $this->headings(['order', 'date', 'source', 'sales_order', 'status', 'planned_quantity', 'received_quantity']), collect($this->report['orders'])->map(fn ($order): array => [$order->doc_num, app(DateFormatService::class)->formatDate($order->production_order_date, ''), __('production_execution.source_types.'.$order->source_type), $order->salesOrder?->doc_num, __('production_execution.statuses.'.$order->status), $order->lines->sum('base_quantity'), $order->lines->sum('received_base_quantity')])),
            'runs' => $this->sheet(__('production_execution.reports.sections.runs'), $this->headings(['run', 'order', 'sales_order', 'stage', 'fixed_asset', 'product_code', 'product', 'actual_start', 'actual_end', 'duration_hours', 'planned_labor', 'actual_labor', 'total_labor_hours', 'planned', 'good', 'rejected', 'rework', 'scrap', 'received', 'yield_percent', 'status']), $runRows),
            'quality' => $this->sheet(__('production_execution.reports.sections.quality'), $this->headings(['inspection', 'sampled_at', 'run', 'order', 'sales_order', 'stage', 'inspection_type', 'result', 'disposition', 'affected_quantity', 'status', 'defect_code', 'notes', 'corrective_action', 'attachments']), collect($this->report['qualityInspections'])->map(fn ($inspection): array => [$inspection->doc_num, app(DateFormatService::class)->formatDateTime($inspection->sampled_at, ''), $inspection->run?->run_number, $inspection->run?->order?->doc_num, $inspection->run?->order?->salesOrder?->doc_num, $inspection->stageSnapshot?->stage_name, $inspection->qualityType?->name, __('production_execution.quality_results.'.$inspection->result), $inspection->disposition ? __('production_execution.quality_dispositions.'.$inspection->disposition) : null, $inspection->affected_base_quantity, __('production_execution.statuses.'.$inspection->status), $inspection->defect_code, $inspection->notes, $inspection->corrective_action, count($inspection->evidence ?? [])])),
            'materials' => $this->sheet(__('production_execution.reports.sections.materials'), $this->materialHeadings(), collect($this->report['materials'])->map(fn ($line): array => $this->materialRow($line))),
            'receipts' => $this->sheet(__('production_execution.reports.sections.receipts'), $this->finishedGoodsHeadings(), collect($this->report['finishedGoodsReceipts'])->flatMap(fn ($document): Collection => $document->lines->map(fn ($line): array => $this->finishedGoodsRow($document, $line)))),
        ];

        return [$sheets[$this->section] ?? $sheets['overview']];
    }

    /** @return list<ProductionReportSheet> */
    private function controlSheets(): array
    {
        $control = 'production_execution.reports.control.';
        $columns = fn (array $keys): array => array_map(fn (string $key): string => __($control.'columns.'.$key), $keys);
        $productRows = collect($this->report['controlProducts'])->map(fn (array $row): array => [
            $row['branch'], $row['product']?->doc_num, $row['product']?->name, $row['color'], $row['customer'], $row['stage'],
            $row['components'], $row['unit'], $row['pack_size'], $row['equivalent_unit'], $row['runs'],
            $row['planned'], $row['good'], $row['equivalent_good'], $row['good_weight_kg'], $row['unit_weight_kg'],
            $row['production_scrap_weight_kg'], $row['production_scrap_percent'], $row['rejected'], $row['rework'],
            $row['scrap'], $row['received'], $row['yield'],
        ]);
        $dailyRows = collect($this->report['controlDaily'])->map(function (array $row): array {
            $run = $row['run'];

            return [
                app(DateFormatService::class)->formatDate($row['date'], ''), __('production_execution.reports.control.date_bases.'.$row['date_basis']), $run->order?->branch?->name, $run->fixedAsset?->asset_name ?? $run->machine?->name,
                $run->shift?->name, $run->run_number, $run->product?->doc_num, $run->product?->name, $run->output_color_name,
                $run->order?->salesOrder?->customer?->name, $run->product?->unit?->name,
                $row['good'], $row['equivalent_good'], $row['good_weight_kg'], $row['production_scrap_weight_kg'],
                $row['rejected'], $row['rework'], $row['scrap'],
            ];
        });
        $dailyMaterialRows = collect($this->report['controlDailyMaterials'])->map(fn (array $row): array => [
            app(DateFormatService::class)->formatDate($row['date'], ''), $row['run']->order?->branch?->name, $row['run']->run_number,
            $row['run']->product?->doc_num, $row['run']->product?->name,
            $row['material']?->doc_num, $row['material']?->name, $row['unit'],
            $row['consumed'], $row['waste'], $row['total_used'], $row['waste_percent'], $row['documents'],
        ]);
        $machineRows = collect($this->report['controlMachines'])->map(fn (array $row): array => [
            $row['branch'], $row['machine'], $row['stage'], $row['product']?->doc_num,
            $row['product']?->name, $row['color'], $row['unit'], $row['runs'], $row['planned'], $row['good'],
            $row['good_weight_kg'], $row['production_scrap_weight_kg'], $row['scrap'], $row['yield'],
        ]);
        $materialSummaryRows = collect($this->report['controlMaterialSummary'])->map(fn (array $row): array => [
            $row['branch'], $row['product']?->doc_num, $row['product']?->name, $row['color'], $row['customer'], $row['stage'],
            $row['material']?->doc_num, $row['material']?->name, $row['unit'], $row['planned'],
            $row['issued'], $row['returned'], $row['consumed'], $row['waste'], $row['total_used'],
            $row['consumed_per_equivalent'], $row['consumption_unit'], $row['waste_percent'], $row['variance'],
        ]);
        $runColumns = ['branch', 'date', 'shift', 'machine', 'stage', 'order', 'run', 'product', 'status', 'planned', 'good', 'rejected', 'rework', 'scrap', 'received', 'unreceived', 'yield', 'good_weight_kg', 'production_scrap_weight_kg', 'hours', 'material_exceptions', 'quality_holds'];
        $materialColumns = ['branch', 'run', 'product', 'material', 'unit', 'basis', 'per_equivalent_unit', 'planned', 'issued', 'returned', 'consumed', 'waste', 'variance', 'status'];
        $runRows = collect($this->report['controlRuns'])->map(fn ($run): array => [
            $run->order?->branch?->name,
            app(DateFormatService::class)->formatDateTime($run->actual_start_at ?? $run->planned_start_at, ''),
            $run->shift?->name,
            $run->fixedAsset?->asset_name ?? $run->machine?->name,
            $run->stageSnapshot?->stage_name,
            $run->order?->doc_num,
            $run->run_number,
            $run->product?->doc_num.' — '.$run->product?->name,
            __('production_execution.statuses.'.$run->status),
            $run->planned_base_quantity,
            bccomp((string) $run->report_recorded_base_quantity, '0', 8) > 0 ? $run->report_good_base_quantity : null,
            bccomp((string) $run->report_recorded_base_quantity, '0', 8) > 0 ? $run->report_rejected_base_quantity : null,
            bccomp((string) $run->report_recorded_base_quantity, '0', 8) > 0 ? $run->report_rework_base_quantity : null,
            bccomp((string) $run->report_recorded_base_quantity, '0', 8) > 0 ? $run->report_scrap_base_quantity : null,
            bccomp((string) $run->report_received_base_quantity, '0', 8) > 0 ? $run->report_received_base_quantity : null,
            bccomp((string) $run->report_recorded_base_quantity, '0', 8) > 0 ? $run->report_receipt_remaining_base_quantity : null,
            $run->report_yield_percent,
            $run->report_good_weight_kg,
            $run->report_production_scrap_weight_kg,
            $run->actualDurationHours(),
            $run->material_exception_count,
            $run->quality_hold_count,
        ]);
        $materialRows = collect($this->report['controlMaterials'])->map(function (array $entry): array {
            $run = $entry['run'];
            $line = $entry['line'];
            $pending = $entry['status'] === 'pending';

            return [$run->order?->branch?->name, $run->run_number, $run->product?->doc_num.' — '.$run->product?->name, $line->product?->doc_num.' — '.$line->product?->name, $line->unit?->name, $entry['basis_quantity'], $line->component_quantity_snapshot, $line->planned_quantity, $pending ? null : $entry['issued'], $pending ? null : $line->returned_quantity, $pending ? null : $line->consumed_quantity, $pending ? null : $line->waste_quantity, $pending ? null : $entry['variance'], __('production_execution.reports.control.material_statuses.'.$entry['status'])];
        });

        return [
            $this->sheet(__($control.'product_summary'), $columns(['branch', 'product_code', 'product', 'color', 'customer', 'stage', 'components', 'unit', 'pack_size', 'equivalent_unit', 'runs_count', 'planned', 'good', 'equivalent_good', 'good_weight_kg', 'unit_weight_kg', 'production_scrap_weight_kg', 'production_scrap_percent', 'rejected', 'rework', 'scrap', 'received', 'yield']), $productRows),
            $this->sheet(__($control.'daily_output'), $columns(['date', 'date_basis', 'branch', 'machine', 'shift', 'run', 'product_code', 'product', 'color', 'customer', 'unit', 'good', 'equivalent_good', 'good_weight_kg', 'production_scrap_weight_kg', 'rejected', 'rework', 'scrap']), $dailyRows),
            $this->sheet(__($control.'daily_materials'), $columns(['date', 'branch', 'run', 'product_code', 'product', 'material_code', 'material', 'unit', 'consumed', 'waste', 'total_used', 'waste_percent', 'document']), $dailyMaterialRows),
            $this->sheet(__($control.'machine_summary'), $columns(['branch', 'machine', 'stage', 'product_code', 'product', 'color', 'unit', 'runs_count', 'planned', 'good', 'good_weight_kg', 'production_scrap_weight_kg', 'scrap', 'yield']), $machineRows),
            $this->sheet(__($control.'material_summary'), $columns(['branch', 'product_code', 'product', 'color', 'customer', 'stage', 'material_code', 'material', 'unit', 'planned', 'issued', 'returned', 'consumed', 'waste', 'total_used', 'consumed_per_equivalent', 'consumption_unit', 'waste_percent', 'variance']), $materialSummaryRows),
            $this->sheet(__('production_execution.reports.control.run_details'), array_map(fn (string $column): string => __('production_execution.reports.control.columns.'.$column), $runColumns), $runRows),
            $this->sheet(__('production_execution.reports.control.material_details'), array_map(fn (string $column): string => __('production_execution.reports.control.columns.'.$column), $materialColumns), $materialRows),
        ];
    }

    /** @param list<string> $headings */
    private function sheet(string $title, array $headings, Collection $rows): ProductionReportSheet
    {
        return new ProductionReportSheet(mb_substr($title, 0, 31), $headings, $rows->values()->all());
    }

    /** @return list<string> */
    private function materialHeadings(): array
    {
        return $this->headings(['run', 'order', 'material_code', 'material', 'planned', 'reserved', 'issued', 'additional', 'returned', 'consumed', 'waste', 'quantity_variance', 'accountability_variance']);
    }

    /** @return list<mixed> */
    private function materialRow($line): array
    {
        return [$line->run?->run_number, $line->run?->order?->doc_num, $line->product?->doc_num, $line->product?->name, $line->planned_quantity, $line->reserved_quantity, $line->issued_quantity, $line->additional_issued_quantity, $line->returned_quantity, $line->consumed_quantity, $line->waste_quantity, $line->quantity_variance, $line->accountability_variance];
    }

    /** @return list<string> */
    private function finishedGoodsHeadings(): array
    {
        return $this->headings(['receipt', 'date', 'run', 'order', 'sales_order', 'store', 'product_code', 'product', 'quantity']);
    }

    /** @return list<mixed> */
    private function finishedGoodsRow($document, $line): array
    {
        return [$document->doc_num, app(DateFormatService::class)->formatDate($document->document_date, ''), $document->productionRun?->run_number, $document->productionRun?->order?->doc_num, $document->productionRun?->order?->salesOrder?->doc_num, $document->branchStore?->name, $line->product?->doc_num, $line->product?->name, $line->base_quantity];
    }

    /** @param list<string> $headings
     * @return list<string>
     */
    private function headings(array $headings): array
    {
        return array_map(static fn (string $heading): string => __('production_execution.reports.columns.'.$heading), $headings);
    }
}

class ProductionReportSheet extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
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
        return $this->sheetTitle;
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = Coordinate::stringFromColumnIndex(count($this->headings));
                $lastRow = count($this->rows) + 1;

                $sheet->setRightToLeft(app()->isLocale('ar'));
                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
                $sheet->getRowDimension(1)->setRowHeight(27);
                $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['argb' => 'FF14335C']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F5F9']],
                    'alignment' => ['vertical' => 'center', 'wrapText' => true],
                ]);
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $sheet->getPageSetup()->setFitToPage(true);
                $sheet->getPageSetup()->setFitToWidth(1);
                $sheet->getPageSetup()->setFitToHeight(0);
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
            },
        ];
    }
}
