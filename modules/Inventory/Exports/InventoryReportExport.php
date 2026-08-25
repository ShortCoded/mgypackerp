<?php

namespace Modules\Inventory\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventoryReportExport implements WithMultipleSheets
{
    /** @param array<string, mixed> $report */
    public function __construct(
        private readonly array $report,
        private readonly bool $includeFinancial,
    ) {}

    /** @return list<InventoryReportSheet> */
    public function sheets(): array
    {
        $balanceHeadings = ['Store', 'Location', 'Product Code', 'Product', 'Status', 'Batch', 'On Hand'];

        if ($this->includeFinancial) {
            array_push($balanceHeadings, 'Inventory Value', 'Unvalued Receipt Quantity');
        }

        $balanceRows = collect($this->report['balances'])->map(function ($row): array {
            $data = [$row->branchStore?->name, $row->warehouseLocation?->code, $row->product?->doc_num, $row->product?->name, $row->stock_status, $row->batch_lot, $row->on_hand];

            if ($this->includeFinancial) {
                array_push($data, $row->inventory_value, $row->unvalued_receipt_quantity);
            }

            return $data;
        });
        $balanceTotal = ['TOTAL', null, null, null, null, null, $this->report['reportTotals']['on_hand']];

        if ($this->includeFinancial) {
            array_push($balanceTotal, $this->report['reportTotals']['inventory_value'], $this->report['reportTotals']['unvalued_receipt_quantity']);
        }

        $movementRows = collect($this->report['movements'])->map(fn ($row): array => [$row->transaction_date?->toDateString(), $row->source_doc_num, $row->transaction_type, $row->branchStore?->name, $row->warehouseLocation?->code, $row->stock_status, $row->product?->doc_num, $row->product?->name, $row->quantity_in, $row->quantity_out, $this->includeFinancial ? $row->unit_cost : null, $this->includeFinancial ? $row->total_cost : null, $row->productionRun?->run_number]);
        $movementTotal = ['TOTAL', null, null, null, null, null, null, null, $this->report['reportTotals']['quantity_in'], $this->report['reportTotals']['quantity_out'], null, null, null];

        $sheets = [
            $this->sheet('Stock Balance', $balanceHeadings, $balanceRows->push($balanceTotal)),
            $this->sheet('Reservations', ['Product Code', 'Product', 'Store', 'Sales Order', 'Production Order', 'Run', 'Reserved', 'Remaining'], collect($this->report['reservations'])->map(fn ($row): array => [$row->product?->doc_num, $row->product?->name, $row->branchStore?->name, $row->order?->doc_num, $row->productionOrder?->doc_num, $row->productionRun?->run_number, $row->quantity, $row->remaining_quantity])),
            $this->sheet('Movement and Stock Card', ['Date', 'Source', 'Type', 'Store', 'Location', 'Status', 'Product Code', 'Product', 'In', 'Out', 'Unit Cost', 'Total Cost', 'Run'], $movementRows->push($movementTotal)),
            $this->sheet('QC and Quarantine', ['Store', 'Location', 'Product Code', 'Product', 'Status', 'Batch', 'On Hand'], collect($this->report['qualityBalances'])->map(fn ($row): array => [$row->branchStore?->name, $row->warehouseLocation?->code, $row->product?->doc_num, $row->product?->name, $row->stock_status, $row->batch_lot, $row->on_hand])),
            $this->sheet('Damage and Scrap', ['Date', 'Source', 'Type', 'Store', 'Product Code', 'Product', 'In', 'Out'], collect($this->report['damageAndScrap'])->map(fn ($row): array => [$row->transaction_date?->toDateString(), $row->source_doc_num, $row->transaction_type, $row->branchStore?->name, $row->product?->doc_num, $row->product?->name, $row->quantity_in, $row->quantity_out])),
            $this->sheet('Stock Count Variances', ['Count', 'Date', 'Store', 'Product Code', 'Product', 'Status', 'Batch', 'System', 'Physical', 'Variance', 'Reason'], collect($this->report['stockCountVariances'])->map(fn ($row): array => [$row->stockCount?->doc_num, $row->stockCount?->count_date?->toDateString(), $row->stockCount?->branchStore?->name, $row->product?->doc_num, $row->product?->name, $row->stock_status, $row->batch_lot, $row->system_quantity, $row->physical_quantity, $row->variance_quantity, $row->variance_reason])),
            $this->sheet('Reorder', ['Product Code', 'Product', 'Store', 'On Hand', 'Reserved', 'Available', 'Reorder Point', 'Shortage', 'Production Demand'], collect($this->report['reorder'])->map(fn ($row): array => [$row->product?->doc_num, $row->product?->name, $row->branchStore?->name, $row->on_hand, $row->reserved, $row->available, $row->reorder_point, $row->shortage, $row->production_demand])),
            $this->sheet(
                'Inventory Aging',
                array_values(array_filter(['Receipt Date', 'Age Days', 'Age Bucket', 'Receipt Source', 'Store', 'Location', 'Product Code', 'Product', 'Status', 'Batch', 'Remaining Quantity', $this->includeFinancial ? 'Remaining Value' : null])),
                collect($this->report['agingLayers'])->map(fn ($row): array => array_values(array_filter([$row->original_receipt_date?->toDateString(), $row->age_days, $row->age_bucket, $row->source_doc_num, $row->branchStore?->name, $row->warehouseLocation?->code, $row->product?->doc_num, $row->product?->name, $row->stock_status, $row->batch_lot, $row->remaining_quantity, $this->includeFinancial ? $row->remaining_value : null], fn ($value): bool => $value !== null))),
            ),
            $this->sheet('Inventory Expiry', ['Expiry State', 'Days to Expiry', 'Expiry Date', 'Manufacture Date', 'Batch', 'Product Code', 'Product', 'Store', 'Location', 'Status', 'Remaining Quantity'], collect($this->report['expiryLayers'])->map(fn ($row): array => [$row->expiry_state, $row->days_to_expiry, $row->expiry_date?->toDateString(), $row->manufacture_date?->toDateString(), $row->batch_lot, $row->product?->doc_num, $row->product?->name, $row->branchStore?->name, $row->warehouseLocation?->code, $row->stock_status, $row->remaining_quantity])),
        ];

        if ($this->includeFinancial) {
            $sheets[] = $this->report['glReconciliation'] === null
                ? $this->sheet('GL Reconciliation', ['Status', 'Details'], collect([[
                    'Unavailable',
                    $this->report['glReconciliationUnavailableReason'],
                ]]))
                : $this->sheet('GL Reconciliation', ['Control', 'Subledger', 'General Ledger', 'Difference', 'Status'], collect($this->report['glReconciliation'])->map(fn (array $row): array => [$row['label'], $row['subledger'], $row['gl'], $row['difference'], $row['status']]));
        }

        return $sheets;
    }

    /** @param list<string> $headings */
    private function sheet(string $title, array $headings, Collection $rows): InventoryReportSheet
    {
        return new InventoryReportSheet($title, $headings, $rows->values()->all());
    }
}

class InventoryReportSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
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
