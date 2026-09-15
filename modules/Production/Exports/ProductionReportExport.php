<?php

namespace Modules\Production\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

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
        $runRows = collect($this->report['runs'])->map(fn ($run): array => [$run->run_number, $run->order?->doc_num, $run->order?->salesOrder?->doc_num, $run->stageSnapshot?->stage_name, $run->fixedAsset?->asset_name, $run->product?->doc_num, $run->product?->name, $run->actual_start_at?->format('Y-m-d H:i:s'), $run->actual_end_at?->format('Y-m-d H:i:s'), $run->actualDurationHours(), $run->planned_labor_count, $run->actual_labor_count, $run->totalLaborHours(), $run->planned_base_quantity, $run->good_base_quantity, $run->rejected_base_quantity, $run->rework_base_quantity, $run->scrap_base_quantity, $run->received_base_quantity, $run->yield_percent, __('production_execution.statuses.'.$run->status)]);
        $runRows->push([__('production_execution.reports.columns.total'), null, null, null, null, null, null, null, null, null, null, null, null, $this->report['kpis']['planned_base_quantity'], $this->report['kpis']['good_base_quantity'], null, null, $this->report['kpis']['loss_base_quantity'], null, null, null]);
        $sheets = [
            'overview' => $this->sheet(
                __('production_execution.reports.sections.overview'),
                [__('production_execution.reports.columns.metric'), __('production_execution.reports.columns.value')],
                collect($this->report['kpis'])->map(fn ($value, string $key): array => [__('production_execution.reports.kpis.'.$key), $value]),
            ),
            'orders' => $this->sheet(__('production_execution.reports.sections.orders'), $this->headings(['order', 'date', 'source', 'sales_order', 'status', 'planned_quantity', 'received_quantity']), collect($this->report['orders'])->map(fn ($order): array => [$order->doc_num, $order->production_order_date?->toDateString(), __('production_execution.source_types.'.$order->source_type), $order->salesOrder?->doc_num, __('production_execution.statuses.'.$order->status), $order->lines->sum('base_quantity'), $order->lines->sum('received_base_quantity')])),
            'runs' => $this->sheet(__('production_execution.reports.sections.runs'), $this->headings(['run', 'order', 'sales_order', 'stage', 'fixed_asset', 'product_code', 'product', 'actual_start', 'actual_end', 'duration_hours', 'planned_labor', 'actual_labor', 'total_labor_hours', 'planned', 'good', 'rejected', 'rework', 'scrap', 'received', 'yield_percent', 'status']), $runRows),
            'quality' => $this->sheet(__('production_execution.reports.sections.quality'), $this->headings(['inspection', 'sampled_at', 'run', 'order', 'sales_order', 'stage', 'inspection_type', 'result', 'disposition', 'affected_quantity', 'status', 'defect_code', 'notes', 'corrective_action', 'attachments']), collect($this->report['qualityInspections'])->map(fn ($inspection): array => [$inspection->doc_num, $inspection->sampled_at?->format('Y-m-d H:i'), $inspection->run?->run_number, $inspection->run?->order?->doc_num, $inspection->run?->order?->salesOrder?->doc_num, $inspection->stageSnapshot?->stage_name, $inspection->qualityType?->name, __('production_execution.quality_results.'.$inspection->result), $inspection->disposition ? __('production_execution.quality_dispositions.'.$inspection->disposition) : null, $inspection->affected_base_quantity, __('production_execution.statuses.'.$inspection->status), $inspection->defect_code, $inspection->notes, $inspection->corrective_action, count($inspection->evidence ?? [])])),
            'materials' => $this->sheet(__('production_execution.reports.sections.materials'), $this->materialHeadings(), collect($this->report['materials'])->map(fn ($line): array => $this->materialRow($line))),
            'receipts' => $this->sheet(__('production_execution.reports.sections.receipts'), $this->finishedGoodsHeadings(), collect($this->report['finishedGoodsReceipts'])->flatMap(fn ($document): Collection => $document->lines->map(fn ($line): array => $this->finishedGoodsRow($document, $line)))),
        ];

        return [$sheets[$this->section] ?? $sheets['overview']];
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
        return [$document->doc_num, $document->document_date?->toDateString(), $document->productionRun?->run_number, $document->productionRun?->order?->doc_num, $document->productionRun?->order?->salesOrder?->doc_num, $document->branchStore?->name, $line->product?->doc_num, $line->product?->name, $line->base_quantity];
    }

    /** @param list<string> $headings
     * @return list<string>
     */
    private function headings(array $headings): array
    {
        return array_map(static fn (string $heading): string => __('production_execution.reports.columns.'.$heading), $headings);
    }
}

class ProductionReportSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
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
}
