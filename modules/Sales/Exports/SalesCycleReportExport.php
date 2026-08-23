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
            $this->sheet('Open Orders', ['Order', 'Customer', 'Required Date', 'Status', 'Ordered', 'Delivered', 'Production Requested'], collect($this->report['openOrders'])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $row->expected_delivery_date?->toDateString(), $row->status, $row->ordered_quantity, $row->delivered_quantity, $row->production_requested_quantity])),
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
