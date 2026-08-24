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
        private readonly bool $includeFinancial,
    ) {}

    /** @return list<ProductionReportSheet> */
    public function sheets(): array
    {
        $runRows = collect($this->report['runs'])->map(fn ($run): array => [$run->run_number, $run->order?->doc_num, $run->order?->salesOrder?->doc_num, $run->order?->salesOrder?->customer?->name, $run->product?->doc_num, $run->product?->name, $run->planned_base_quantity, $run->good_base_quantity, $run->rejected_base_quantity, $run->rework_base_quantity, $run->scrap_base_quantity, $run->received_base_quantity, $run->yield_percent, $run->status]);
        $runRows->push(['TOTAL', null, null, null, null, null, $this->report['kpis']['planned_base_quantity'], $this->report['kpis']['good_base_quantity'], null, null, $this->report['kpis']['loss_base_quantity'], null, null, null]);
        $sheets = [
            $this->sheet('Production Orders', ['Order', 'Date', 'Source', 'Sales Order', 'Customer', 'Status', 'Planned Quantity', 'Received Quantity'], collect($this->report['orders'])->map(fn ($order): array => [$order->doc_num, $order->production_order_date?->toDateString(), $order->source_type, $order->salesOrder?->doc_num, $order->salesOrder?->customer?->name, $order->status, $order->lines->sum('base_quantity'), $order->lines->sum('received_base_quantity')])),
            $this->sheet('Runs Plan vs Actual', ['Run', 'Order', 'Sales Order', 'Customer', 'Product Code', 'Product', 'Planned', 'Good', 'Rejected', 'Rework', 'Scrap', 'Received', 'Yield %', 'Status'], $runRows),
            $this->sheet('Material Requirements', $this->materialHeadings(), collect($this->report['materials'])->map(fn ($line): array => $this->materialRow($line))),
            $this->sheet('Finished Goods Receipts', ['Receipt', 'Date', 'Run', 'Order', 'Sales Order', 'Customer', 'Store', 'Product Code', 'Product', 'Quantity', 'Value', 'Journal'], collect($this->report['finishedGoodsReceipts'])->flatMap(fn ($document): Collection => $document->lines->map(fn ($line): array => [$document->doc_num, $document->document_date?->toDateString(), $document->productionRun?->run_number, $document->productionRun?->order?->doc_num, $document->productionRun?->order?->salesOrder?->doc_num, $document->productionRun?->order?->salesOrder?->customer?->name, $document->branchStore?->name, $line->product?->doc_num, $line->product?->name, $line->base_quantity, $this->includeFinancial ? $line->total_cost : null, $this->includeFinancial ? $document->journalEntry?->doc_num : null]))),
        ];

        if ($this->includeFinancial) {
            $sheets[] = $this->sheet('Production Cost and WIP', ['Run', 'Order', 'Issued', 'Returned', 'Waste', 'Capitalizable', 'Finished Goods', 'WIP'], collect($this->report['runCosts'])->map(fn ($row): array => [$row->run?->run_number, $row->run?->order?->doc_num, $row->issued, $row->returned, $row->waste, $row->capitalizable, $row->finished_goods, $row->wip]));
        }

        return $sheets;
    }

    /** @param list<string> $headings */
    private function sheet(string $title, array $headings, Collection $rows): ProductionReportSheet
    {
        return new ProductionReportSheet($title, $headings, $rows->values()->all());
    }

    /** @return list<string> */
    private function materialHeadings(): array
    {
        $headings = ['Run', 'Order', 'Material Code', 'Material', 'Planned', 'Reserved', 'Issued', 'Additional', 'Returned', 'Consumed', 'Waste', 'Quantity Variance', 'Accountability Variance'];

        if ($this->includeFinancial) {
            array_push($headings, 'Planned Cost', 'Actual Cost', 'Waste Cost', 'Cost Variance');
        }

        return $headings;
    }

    /** @return list<mixed> */
    private function materialRow($line): array
    {
        $row = [$line->run?->run_number, $line->run?->order?->doc_num, $line->product?->doc_num, $line->product?->name, $line->planned_quantity, $line->reserved_quantity, $line->issued_quantity, $line->additional_issued_quantity, $line->returned_quantity, $line->consumed_quantity, $line->waste_quantity, $line->quantity_variance, $line->accountability_variance];

        if ($this->includeFinancial) {
            array_push($row, $line->planned_cost, $line->actual_cost, $line->waste_cost, $line->cost_variance);
        }

        return $row;
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
