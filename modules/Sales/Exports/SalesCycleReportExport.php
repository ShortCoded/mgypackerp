<?php

namespace Modules\Sales\Exports;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Carbon;
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
        $summary = $this->report['financialSummary'] ?? [];
        $ledgerSummary = $this->report['ledgerSummary'] ?? [];
        $customerSummary = $this->report['customerSummary'] ?? [];
        $productSummary = $this->report['productSummary'] ?? [];
        $customerProductSummary = $this->report['customerProductSummary'] ?? [];
        $periodSummary = $this->report['periodSummary'] ?? [];
        $outstandingSummary = $this->report['outstandingSummary'] ?? [];
        $installmentSummary = $this->report['installmentSummary'] ?? [];
        $agingTotals = $this->report['agingTotals'] ?? [];
        $collectionSummary = $this->report['collectionSummary'] ?? [];
        $upcomingSummary = $this->report['upcomingSummary'] ?? [];
        $returnsSummary = $this->report['returnsSummary'] ?? [];
        $returnAnalysisSummary = $this->report['returnAnalysisSummary'] ?? [];
        $ledgerSource = $this->report['salesLedger'] ?? [];
        if ($ledgerSource instanceof Paginator) {
            $ledgerSource = $ledgerSource->items();
        }
        $ledgerRows = collect($ledgerSource)->map(fn ($row): array => [$row->invoice_date?->toDateString(), $row->customer?->name, $row->doc_num, $row->order?->doc_num, $row->deliveries->pluck('doc_num')->implode(', '), $row->currency?->code, $row->subtotal_amount, $row->discount_amount, $row->tax_amount, $row->total_amount, $row->returns_amount ?? '0', bcsub($row->total_amount, (string) ($row->returns_amount ?? 0), 4), $row->paid_amount, $row->remaining_amount]);
        $ledgerRows->push(['', '', 'TOTAL ('.($ledgerSummary['invoice_count'] ?? 0).')', '', '', '', '', '', '', $ledgerSummary['gross_sales'] ?? 0, $ledgerSummary['returns_amount'] ?? 0, $ledgerSummary['net_sales'] ?? 0, $ledgerSummary['collected'] ?? 0, $ledgerSummary['outstanding'] ?? 0]);
        $customerRows = collect($this->report['salesByCustomer'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $row->sales_value, $row->outstanding]);
        $customerRows->push(['TOTAL ('.($customerSummary['customer_count'] ?? 0).')', '', $customerSummary['sales_value'] ?? 0, $customerSummary['outstanding'] ?? 0]);
        $productRows = collect($this->report['salesByItem'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $row->sold_quantity, $row->sales_value]);
        $productRows->push(['TOTAL ('.($productSummary['product_count'] ?? 0).')', '', $productSummary['sold_quantity'] ?? 0, $productSummary['sales_value'] ?? 0]);
        $customerProductRows = collect($this->report['salesByCustomerItem'] ?? [])->map(fn ($row): array => [$row->customer_doc_num, $row->customer_name, $row->product_doc_num, $row->product_name, $row->sold_quantity, $row->sales_value]);
        $customerProductRows->push(['TOTAL ('.($customerProductSummary['line_count'] ?? 0).')', '', '', '', $customerProductSummary['sold_quantity'] ?? 0, $customerProductSummary['sales_value'] ?? 0]);
        $periodRows = collect($this->report['salesByPeriod'] ?? [])->map(fn ($row): array => [(string) $row->invoice_date, $row->invoice_count, $row->sales_value]);
        $periodRows->push(['TOTAL', $periodSummary['invoice_count'] ?? 0, $periodSummary['sales_value'] ?? 0]);
        $outstandingRows = collect($this->report['invoiceOutstanding'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $row->due_date?->toDateString(), $row->total_amount, $row->remaining_amount]);
        $outstandingRows->push(['TOTAL ('.($outstandingSummary['invoice_count'] ?? 0).')', '', '', $outstandingSummary['total_value'] ?? 0, $outstandingSummary['outstanding'] ?? 0]);
        $installmentRows = collect($this->report['installments'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $row->due_date, $row->outstanding]);
        $installmentRows->push(['TOTAL ('.($installmentSummary['schedule_count'] ?? 0).')', '', '', $installmentSummary['outstanding'] ?? 0]);
        $collectionRows = collect($this->report['customerReceipts'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->receipt_date?->toDateString(), $row->customer?->name, $row->receivedByEmployee?->full_name ?: $row->receivedByEmployee?->name, $this->enumLabel($row->payment_method), $row->reference_no ?: $row->cashVoucher?->doc_num ?: $row->cheque?->doc_num, $row->amount, $row->unallocated_amount, $this->enumLabel($row->status)]);
        $collectionRows->push(['TOTAL ('.($collectionSummary['receipt_count'] ?? 0).')', '', '', '', '', '', $collectionSummary['amount'] ?? 0, $collectionSummary['unallocated'] ?? 0, '']);
        $upcomingRows = collect($this->report['upcomingCollections'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $row->due_date, $row->outstanding]);
        $upcomingRows->push(['TOTAL ('.($upcomingSummary['schedule_count'] ?? 0).')', '', '', $upcomingSummary['outstanding'] ?? 0]);
        $agingRows = collect($this->report['aging'] ?? [])->map(fn (array $row): array => [$row['customer'], $row['current'], $row['1_30'], $row['31_60'], $row['61_90'], $row['over_90']]);
        $agingRows->push(['TOTAL', $agingTotals['current'] ?? 0, $agingTotals['1_30'] ?? 0, $agingTotals['31_60'] ?? 0, $agingTotals['61_90'] ?? 0, $agingTotals['over_90'] ?? 0]);
        $returnsRows = collect($this->report['returns'] ?? [])->map(fn ($row): array => [$this->enumLabel($row->reason_code), $row->return_count, $row->returned_quantity, $row->saleable_quantity, $row->rejected_quantity]);
        $returnsRows->push(['TOTAL', $returnsSummary['return_count'] ?? 0, $returnsSummary['returned_quantity'] ?? 0, $returnsSummary['saleable_quantity'] ?? 0, $returnsSummary['rejected_quantity'] ?? 0]);
        $returnQualityRows = collect($this->report['returnAnalysis'] ?? [])->map(fn ($row): array => [$row->customer_doc_num, $row->customer_name, $row->product_doc_num, $row->product_name, $this->enumLabel($row->reason_code), $this->enumLabel($row->quality_disposition), $row->returned_quantity]);
        $returnQualityRows->push(['TOTAL ('.($returnAnalysisSummary['line_count'] ?? 0).')', '', '', '', '', '', $returnAnalysisSummary['returned_quantity'] ?? 0]);
        $sheets = [
            'summary' => $this->sheet($this->label('sheets.summary'), $this->headings(['metric', 'value']), collect([
                [$this->label('metrics.invoice_count'), $summary['invoice_count'] ?? 0], [$this->label('metrics.gross_sales'), $summary['gross_sales'] ?? 0],
                [$this->label('metrics.credit_notes_returns'), $summary['credit_notes'] ?? 0], [$this->label('metrics.net_sales'), $summary['net_sales'] ?? 0],
                [$this->label('metrics.collections'), $summary['collections'] ?? 0], [$this->label('metrics.outstanding'), $summary['outstanding'] ?? 0],
                [$this->label('metrics.overdue_outstanding'), $summary['overdue_outstanding'] ?? 0], [$this->label('metrics.collection_rate'), $summary['collection_rate'] ?? 0],
                [$this->label('metrics.return_rate'), $summary['return_rate'] ?? 0],
            ])),
            'ledger' => $this->sheet($this->label('sheets.ledger'), $this->headings(['invoice_date', 'customer', 'invoice', 'order', 'deliveries', 'currency', 'gross', 'discount', 'tax', 'net_invoice', 'returns', 'net_sales', 'collected', 'outstanding']), $ledgerRows),
            'invoice_lines' => $this->sheet($this->label('sheets.invoice_lines'), $this->headings(['invoice', 'customer', 'item', 'category', 'quantity', 'price', 'discount', 'tax', 'value', 'returned', 'net_sold']), collect($ledgerSource)->flatMap(fn ($invoice) => $invoice->lines->map(fn ($line): array => [$invoice->doc_num, $invoice->customer?->name, $line->product?->name, $line->product?->category?->name, $line->quantity, $line->unit_price, $line->discount_amount, $line->tax_amount, $line->line_total, $line->returned_quantity ?? '0', bcsub($line->quantity, (string) ($line->returned_quantity ?? 0), 8)]))),
            'quotations' => $this->sheet($this->label('sheets.quotations'), $this->headings(['quotation', 'customer', 'date', 'valid_until', 'status', 'revision', 'total']), collect($this->report['quotations'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $row->quotation_date?->toDateString(), $row->valid_until?->toDateString(), __('quotations.statuses.'.$row->status), $row->currentRevision?->revision_code, $row->currentRevision?->total])),
            'orders' => $this->sheet($this->label('sheets.orders'), $this->headings(['order', 'customer', 'required_date', 'status', 'ordered', 'invoiced', 'delivered', 'remaining_delivery']), collect($this->report['openOrders'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $row->expected_delivery_date?->toDateString(), $this->enumLabel($row->status), $row->ordered_quantity, $row->lines->sum('invoiced_quantity'), $row->delivered_quantity, max(0, (float) $row->ordered_quantity - (float) $row->delivered_quantity)])),
            'customers' => $this->sheet($this->label('sheets.customers'), $this->headings(['customer_code', 'customer', 'sales', 'outstanding']), $customerRows),
            'products' => $this->sheet($this->label('sheets.products'), $this->headings(['product_code', 'product', 'quantity', 'sales']), $productRows),
            'customer_products' => $this->sheet($this->label('sheets.customer_products'), $this->headings(['customer_code', 'customer', 'product_code', 'product', 'quantity', 'sales']), $customerProductRows),
            'period' => $this->sheet($this->label('sheets.period'), $this->headings(['date', 'invoice_count', 'sales']), $periodRows),
            'outstanding' => $this->sheet($this->label('sheets.outstanding'), $this->headings(['invoice', 'customer', 'due_date', 'total', 'outstanding']), $outstandingRows),
            'installments' => $this->sheet($this->label('sheets.installments'), $this->headings(['invoice', 'customer', 'due_date', 'outstanding']), $installmentRows),
            'collections' => $this->sheet($this->label('sheets.collections'), $this->headings(['receipt', 'date', 'customer', 'received_by_employee', 'payment_method', 'reference', 'amount', 'unallocated', 'status']), $collectionRows),
            'upcoming_collections' => $this->sheet($this->label('sheets.upcoming_collections'), $this->headings(['invoice', 'customer', 'due_date', 'outstanding']), $upcomingRows),
            'aging' => $this->sheet($this->label('sheets.aging'), $this->headings(['customer', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90']), $agingRows),
            'returns' => $this->sheet($this->label('sheets.returns'), $this->headings(['reason', 'returns', 'returned_quantity', 'saleable_quantity', 'rejected_quantity']), $returnsRows),
            'return_quality' => $this->sheet($this->label('sheets.return_quality'), $this->headings(['customer_code', 'customer', 'product_code', 'product', 'reason', 'disposition', 'returned_quantity']), $returnQualityRows),
            'unpriced_products' => $this->sheet($this->label('sheets.unpriced_products'), $this->headings(['product_code', 'product', 'category']), collect($this->report['unpricedProducts'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $row->category_name])),
            'customers_without_prices' => $this->sheet($this->label('sheets.customers_without_prices'), $this->headings(['customer_code', 'customer']), collect($this->report['customersWithoutPriceLists'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name])),
            'customer_price_gaps' => $this->sheet($this->label('sheets.customer_price_gaps'), $this->headings(['customer_code', 'customer', 'product_code', 'product']), collect($this->report['customerProductPricingGaps'] ?? [])->map(fn ($row): array => [$row->customer_doc_num, $row->customer_name, $row->product_doc_num, $row->product_name])),
            'cost_of_sales' => $this->sheet($this->label('sheets.cost_of_sales'), $this->headings(['movement_kind', 'document', 'return_document', 'order', 'invoice', 'customer', 'product', 'quantity', 'unit_cost', 'signed_total_cost', 'posting_date', 'journal_entry', 'reconciliation_status']), collect($this->report['costOfSalesRows'] ?? [])->map(fn ($row): array => [$this->enumLabel($row['movement_kind']), $row['document'], $row['return_document'] ?? '', $row['order'] ?? '', $row['invoice'] ?? '', $row['customer'] ?? '', $row['product'] ?? '', $row['quantity'] ?? '', $row['unit_cost'] ?? '', $row['signed_total_cost'] ?? '', $row['posting_date'] instanceof Carbon ? $row['posting_date']->toDateString() : '', $row['journal_entry'] ?? '', $this->enumLabel($row['reconciliation_status'])])),
            'cost_of_sales_summary' => $this->sheet($this->label('sheets.cost_of_sales_summary'), $this->headings(['metric', 'value']), collect([
                [$this->label('metrics.delivery_count'), $this->report['costOfSalesSummary']['delivery_count'] ?? 0],
                [$this->label('metrics.return_count'), $this->report['costOfSalesSummary']['return_count'] ?? 0],
                [$this->label('metrics.delivery_cost'), $this->report['costOfSalesSummary']['delivery_cost'] ?? '0.0000'],
                [$this->label('metrics.return_cost'), $this->report['costOfSalesSummary']['return_cost'] ?? '0.0000'],
                [$this->label('metrics.net_cost'), $this->report['costOfSalesSummary']['net_cost'] ?? '0.0000'],
                [$this->label('metrics.unreconciled_count'), $this->report['costOfSalesSummary']['unreconciled_count'] ?? 0],
            ])),
        ];

        $keys = match ($this->report['reportType'] ?? 'operational') {
            'financial' => ['summary', 'customers', 'outstanding', 'aging'],
            'period' => ['period'],
            'customers' => ['customers'],
            'products' => ['products', 'customer_products'],
            'invoices' => ['ledger', 'invoice_lines'],
            'receivables' => ['outstanding', 'installments', 'aging'],
            'collections' => ['collections', 'upcoming_collections'],
            'returns' => ['returns', 'return_quality'],
            'quotations' => ['quotations'],
            'fulfillment' => ['orders'],
            'pricing' => ['unpriced_products', 'customers_without_prices', 'customer_price_gaps'],
            'cost_of_sales' => ['cost_of_sales_summary', 'cost_of_sales'],
            default => ['summary', 'quotations', 'orders', 'ledger'],
        };

        return collect($keys)->map(fn (string $key): SalesCycleReportSheet => $sheets[$key])->all();
    }

    /** @param list<string> $headings */
    private function sheet(string $title, array $headings, Collection $rows): SalesCycleReportSheet
    {
        return new SalesCycleReportSheet($title, $headings, $rows->values()->all());
    }

    /** @param list<string> $keys @return list<string> */
    private function headings(array $keys): array
    {
        return array_map(fn (string $key): string => $this->label('headings.'.$key), $keys);
    }

    private function label(string $key): string
    {
        return __('sales_ui.reports.export.'.$key);
    }

    private function enumLabel(?string $value): string
    {
        return collect(explode(',', (string) $value))
            ->filter()
            ->map(fn (string $part): string => __(str($part)->replace('_', ' ')->title()->toString()))
            ->join(app()->isLocale('ar') ? '، ' : ', ');
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
