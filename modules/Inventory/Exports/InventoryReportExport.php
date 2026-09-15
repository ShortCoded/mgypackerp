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
    public function __construct(private readonly array $report) {}

    /** @return list<InventoryReportSheet> */
    public function sheets(): array
    {
        $balanceHeadings = ['Store', 'Location', 'Product Code', 'Product', 'Status', 'Batch', 'On Hand'];

        $balanceRows = collect($this->report['balances'])->map(function ($row): array {
            return [$row->branchStore?->name, $row->warehouseLocation?->code, $row->product?->doc_num, $row->product?->name, $this->stockStatusLabel($row->stock_status), $row->batch_lot, $row->on_hand];
        });
        $balanceTotal = [__('Total'), null, null, null, null, null, $this->report['reportTotals']['on_hand']];

        $movementRows = collect($this->report['movements'])->map(fn ($row): array => $this->movementRow($row));
        $movementTotal = [__('Total'), null, null, null, null, null, null, null, $this->report['reportTotals']['quantity_in'], $this->report['reportTotals']['quantity_out']];

        $movementTotal[] = null;

        $sheets = [
            $this->sheet(__('Stock Balance'), $this->headings($balanceHeadings), $balanceRows->push($balanceTotal)),
            $this->sheet(__('Reservations'), $this->headings(['Product Code', 'Product', 'Store', 'Sales Order', 'Production Order', 'Run', 'Reserved', 'Remaining']), collect($this->report['reservations'])->map(fn ($row): array => [$row->product?->doc_num, $row->product?->name, $row->branchStore?->name, $row->order?->doc_num, $row->productionOrder?->doc_num, $row->productionRun?->run_number, $row->quantity, $row->remaining_quantity])),
            $this->sheet(__('Movement and Stock Card'), $this->movementHeadings(), $movementRows->push($movementTotal)),
            $this->sheet(__('QC and Quarantine'), $this->headings(['Store', 'Location', 'Product Code', 'Product', 'Status', 'Batch', 'On Hand']), collect($this->report['qualityBalances'])->map(fn ($row): array => [$row->branchStore?->name, $row->warehouseLocation?->code, $row->product?->doc_num, $row->product?->name, $this->stockStatusLabel($row->stock_status), $row->batch_lot, $row->on_hand])),
            $this->sheet(__('Damage and Scrap'), $this->headings(['Date', 'Source', 'Type', 'Store', 'Product Code', 'Product', 'In', 'Out']), collect($this->report['damageAndScrap'])->map(fn ($row): array => [$row->transaction_date?->toDateString(), $row->source_doc_num, $this->movementTypeLabel($row->transaction_type), $row->branchStore?->name, $row->product?->doc_num, $row->product?->name, $row->quantity_in, $row->quantity_out])),
            $this->sheet(__('Stock Count Variances'), $this->headings(['Count', 'Date', 'Store', 'Product Code', 'Product', 'Status', 'Batch', 'System', 'Physical', 'Variance', 'Reason']), collect($this->report['stockCountVariances'])->map(fn ($row): array => [$row->stockCount?->doc_num, $row->stockCount?->count_date?->toDateString(), $row->stockCount?->branchStore?->name, $row->product?->doc_num, $row->product?->name, $this->stockStatusLabel($row->stock_status), $row->batch_lot, $row->system_quantity, $row->physical_quantity, $row->variance_quantity, $row->variance_reason])),
            $this->sheet(__('Reorder'), $this->headings(['Product Code', 'Product', 'Store', 'On Hand', 'Reserved', 'Available', 'Reorder Point', 'Shortage', 'Production Demand']), collect($this->report['reorder'])->map(fn ($row): array => [$row->product?->doc_num, $row->product?->name, $row->branchStore?->name, $row->on_hand, $row->reserved, $row->available, $row->reorder_point, $row->shortage, $row->production_demand])),
            $this->sheet(
                __('Inventory Aging'),
                $this->headings(['Receipt Date', 'Age Days', 'Age Bucket', 'Receipt Source', 'Store', 'Location', 'Product Code', 'Product', 'Status', 'Batch', 'Remaining Quantity']),
                collect($this->report['agingLayers'])->map(fn ($row): array => [$row->original_receipt_date?->toDateString(), $row->age_days, $row->age_bucket, $row->source_doc_num, $row->branchStore?->name, $row->warehouseLocation?->code, $row->product?->doc_num, $row->product?->name, $this->stockStatusLabel($row->stock_status), $row->batch_lot, $row->remaining_quantity]),
            ),
            $this->sheet(__('Inventory Expiry'), $this->headings(['Expiry State', 'Days to Expiry', 'Expiry Date', 'Manufacture Date', 'Batch', 'Product Code', 'Product', 'Store', 'Location', 'Status', 'Remaining Quantity']), collect($this->report['expiryLayers'])->map(fn ($row): array => [__(str($row->expiry_state)->replace('_', ' ')->title()->toString()), $row->days_to_expiry, $row->expiry_date?->toDateString(), $row->manufacture_date?->toDateString(), $row->batch_lot, $row->product?->doc_num, $row->product?->name, $row->branchStore?->name, $row->warehouseLocation?->code, $this->stockStatusLabel($row->stock_status), $row->remaining_quantity])),
        ];

        return $sheets;
    }

    /** @param list<string> $headings */
    private function sheet(string $title, array $headings, Collection $rows): InventoryReportSheet
    {
        return new InventoryReportSheet(mb_substr($title, 0, 31), $headings, $rows->values()->all());
    }

    /** @return list<string> */
    private function movementHeadings(): array
    {
        $headings = ['Date', 'Source', 'Type', 'Store', 'Location', 'Status', 'Product Code', 'Product', 'In', 'Out'];

        $headings[] = 'Run';

        return $this->headings($headings);
    }

    /** @return list<mixed> */
    private function movementRow($row): array
    {
        $data = [$row->transaction_date?->toDateString(), $row->source_doc_num, $this->movementTypeLabel($row->transaction_type), $row->branchStore?->name, $row->warehouseLocation?->code, $this->stockStatusLabel($row->stock_status), $row->product?->doc_num, $row->product?->name, $row->quantity_in, $row->quantity_out];

        $data[] = $row->productionRun?->run_number;

        return $data;
    }

    private function movementTypeLabel(string $type): string
    {
        $key = 'inventory.movements.types.'.$type;
        $translated = __($key);

        return $translated === $key
            ? __(str($type)->replace('_', ' ')->title()->toString())
            : $translated;
    }

    private function stockStatusLabel(string $status): string
    {
        return __('inventory.movements.stock_statuses.'.$status);
    }

    /** @param list<string> $headings
     * @return list<string>
     */
    private function headings(array $headings): array
    {
        return array_map(static fn (string $heading): string => __($heading), $headings);
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
