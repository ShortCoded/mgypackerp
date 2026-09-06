<?php

namespace Modules\Sales\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

class SalesCycleReportExport implements WithMultipleSheets
{
    /** @param array<string, mixed> $report */
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        return [
            $this->sheet('Backorders', ['Order', 'Customer', 'Product', 'Warehouse', 'Unit', 'Ordered', 'Delivered', 'Reserved', 'Available', 'Shortage', 'Planned', 'Produced', 'Remaining Production', 'Required Date', 'Days Late'], collect($this->report['backorders'] ?? [])->map(fn (array $row): array => [$row['line']->order->doc_num, $row['line']->order->customer?->name, $row['line']->product?->name, $row['line']->order->branchStore?->name, $row['line']->unit?->name, $row['line']->quantity, $row['line']->delivered_quantity, $row['reserved'], $row['available'], $row['shortage'], $row['line']->production_requested_quantity, $row['line']->produced_quantity, $row['remaining_production'], $row['line']->order->expected_delivery_date?->toDateString(), $row['days_late']])),
            $this->sheet('Sales Ledger', ['Invoice Date', 'Customer', 'Invoice', 'Order', 'Deliveries', 'Currency', 'Gross', 'Discount', 'Tax', 'Net Invoice', 'Returns', 'Net Sales', 'Collected', 'Outstanding'], collect($this->report['salesLedger'] ?? [])->map(fn ($row): array => [$row->invoice_date?->toDateString(), $row->customer?->name, $row->doc_num, $row->order?->doc_num, $row->deliveries->pluck('doc_num')->implode(', '), $row->currency?->code, $row->subtotal_amount, $row->discount_amount, $row->tax_amount, $row->total_amount, $row->returns_amount ?? '0', bcsub($row->total_amount, (string) ($row->returns_amount ?? 0), 4), $row->paid_amount, $row->remaining_amount])),
            $this->sheet('Invoice Lines', ['Invoice', 'Customer', 'Item', 'Category', 'Quantity', 'Price', 'Discount', 'Tax', 'Value', 'Returned', 'Net Sold'], collect($this->report['salesLedger'] ?? [])->flatMap(fn ($invoice) => $invoice->lines->map(fn ($line): array => [$invoice->doc_num, $invoice->customer?->name, $line->product?->name, $line->product?->category?->name, $line->quantity, $line->unit_price, $line->discount_amount, $line->tax_amount, $line->line_total, $line->returned_quantity ?? '0', bcsub($line->quantity, (string) ($line->returned_quantity ?? 0), 8)]))),
            $this->sheet('Quotations', ['Quotation', 'Customer', 'Date', 'Valid Until', 'Status', 'Revision', 'Total'], collect($this->report['quotations'])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $row->quotation_date?->toDateString(), $row->valid_until?->toDateString(), $row->status, $row->currentRevision?->revision_code, $row->currentRevision?->total])),
            $this->sheet('Open Orders', ['Order', 'Customer', 'Required Date', 'Status', 'Ordered', 'Reserved', 'Produced', 'Delivered', 'Production Requested'], collect($this->report['openOrders'])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $row->expected_delivery_date?->toDateString(), $row->status, $row->ordered_quantity, $row->reserved_quantity, $row->produced_quantity, $row->delivered_quantity, $row->production_requested_quantity])),
            $this->sheet('Order History', ['Order', 'Customer', 'Order Date', 'Required Date', 'Status', 'Ordered', 'Delivered'], collect($this->report['orderHistory'])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $row->order_date?->toDateString(), $row->expected_delivery_date?->toDateString(), $row->status, $row->ordered_quantity, $row->delivered_quantity])),
            $this->sheet('Sales by Customer', ['Customer Code', 'Customer', 'Sales', 'Outstanding'], collect($this->report['salesByCustomer'])->map(fn ($row): array => [$row->doc_num, $row->name, $row->sales_value, $row->outstanding])),
            $this->sheet('Sales by Product', ['Product Code', 'Product', 'Quantity', 'Sales'], collect($this->report['salesByItem'])->map(fn ($row): array => [$row->doc_num, $row->name, $row->sold_quantity, $row->sales_value])),
            $this->sheet('Customer Product Sales', ['Customer Code', 'Customer', 'Product Code', 'Product', 'Quantity', 'Sales'], collect($this->report['salesByCustomerItem'])->map(fn ($row): array => [$row->customer_doc_num, $row->customer_name, $row->product_doc_num, $row->product_name, $row->sold_quantity, $row->sales_value])),
            $this->sheet('Sales by Period', ['Date', 'Invoice Count', 'Sales'], collect($this->report['salesByPeriod'])->map(fn ($row): array => [(string) $row->invoice_date, $row->invoice_count, $row->sales_value])),
            $this->sheet('Outstanding Invoices', ['Invoice', 'Customer', 'Due Date', 'Total', 'Outstanding'], collect($this->report['invoiceOutstanding'])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $row->due_date?->toDateString(), $row->total_amount, $row->remaining_amount])),
            $this->sheet('Due Installments', ['Invoice', 'Customer', 'Due Date', 'Outstanding'], collect($this->report['installments'])->map(fn ($row): array => [$row->doc_num, $row->name, $row->due_date, $row->outstanding])),
            $this->sheet('Upcoming Collections', ['Invoice', 'Customer', 'Due Date', 'Outstanding'], collect($this->report['upcomingCollections'])->map(fn ($row): array => [$row->doc_num, $row->name, $row->due_date, $row->outstanding])),
            $this->sheet('Customer Aging', ['Customer', 'Current', '1-30', '31-60', '61-90', '90+'], collect($this->report['aging'])->map(fn (array $row): array => [$row['customer'], $row['current'], $row['1_30'], $row['31_60'], $row['61_90'], $row['over_90']])),
            $this->sheet('Returns by Reason', ['Reason', 'Returns', 'Returned Qty', 'Saleable Qty', 'Rejected Qty'], collect($this->report['returns'])->map(fn ($row): array => [$row->reason_code, $row->return_count, $row->returned_quantity, $row->saleable_quantity, $row->rejected_quantity])),
            $this->sheet('Return Quality', ['Customer Code', 'Customer', 'Product Code', 'Product', 'Reason', 'Disposition', 'Returned Qty'], collect($this->report['returnAnalysis'])->map(fn ($row): array => [$row->customer_doc_num, $row->customer_name, $row->product_doc_num, $row->product_name, $row->reason_code, $row->quality_disposition, $row->returned_quantity])),
        ];
    }

    /** @param list<string> $headings */
    private function sheet(string $title, array $headings, Collection $rows): SalesCycleReportSheet
    {
        return new SalesCycleReportSheet($title, $headings, $rows->values()->all());
    }
}

class SalesCycleReportSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
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
