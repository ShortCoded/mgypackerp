<?php

namespace Modules\Sales\Exports;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Core\Services\DateFormatService;
use Modules\Sales\Services\SalesCycleReadService;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class SalesCycleReportExport implements WithMultipleSheets
{
    /** @param array<string, mixed> $report */
    public function __construct(private readonly array $report, private readonly bool $forCsv = false) {}

    public function sheets(): array
    {
        $dates = app(DateFormatService::class);
        $dateValue = static fn (mixed $value): string => $value ? $dates->formatDate($value, '') : '';
        $totalLabel = $this->label('row_types.total');
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
        $ledgerRows = collect($ledgerSource)->map(fn ($row): array => [$dateValue($row->invoice_date), $row->customer?->name, $row->doc_num, $row->order?->doc_num, $row->deliveries->pluck('doc_num')->implode(', '), $row->currency?->code, $row->subtotal_amount, $row->discount_amount, $row->tax_amount, $row->total_amount, $row->returns_amount ?? '0', bcsub($row->total_amount, (string) ($row->returns_amount ?? 0), 4), $row->paid_amount, $row->remaining_amount, ...$this->discountColumns($row), $row->actual_withholding_amount]);
        $ledgerRows->push(['', '', $totalLabel.' ('.($ledgerSummary['invoice_count'] ?? 0).')', '', '', '', '', '', '', $ledgerSummary['gross_sales'] ?? 0, $ledgerSummary['returns_amount'] ?? 0, $ledgerSummary['net_sales'] ?? 0, $ledgerSummary['collected'] ?? 0, $ledgerSummary['outstanding'] ?? 0, '', '', '', $ledgerSummary['actual_withholding'] ?? 0]);
        $quantity = app(SalesCycleReadService::class)->reportQuantity(...);
        $invoiceLineRows = collect($ledgerSource)->flatMap(fn ($invoice) => $invoice->lines->map(fn ($line): array => [
            $invoice->doc_num, $invoice->customer?->name, $line->product?->name, $line->product?->category?->name,
            $line->quantity, $line->unit_price, $line->discount_amount, $line->tax_amount, $line->line_total,
            $quantity($line->returned_quantity ?? 0), bcsub((string) $line->quantity, $quantity($line->returned_quantity ?? 0), 8), ...$this->discountColumns($line),
        ]));
        $invoiceCsvRows = collect($ledgerSource)->flatMap(function ($invoice) use ($quantity, $dateValue): Collection {
            $netSales = bcsub((string) $invoice->total_amount, (string) ($invoice->returns_amount ?? 0), 4);
            $header = [$this->label('row_types.invoice'), $invoice->doc_num, $invoice->customer?->name, $dateValue($invoice->invoice_date), $invoice->order?->doc_num, $invoice->currency?->code,
                '', '', '', '', '', '', '', '', '', $invoice->total_amount, $invoice->returns_amount ?? '0', $netSales, $invoice->paid_amount, $invoice->remaining_amount, ...$this->discountColumns($invoice), $invoice->actual_withholding_amount];
            $lines = $invoice->lines->map(fn ($line): array => [
                $this->label('row_types.line'), $invoice->doc_num, '', '', '', '', $line->product?->name, $line->product?->category?->name,
                $line->quantity, $line->unit_price, $line->discount_amount, $line->tax_amount, $line->line_total,
                $quantity($line->returned_quantity ?? 0), bcsub((string) $line->quantity, $quantity($line->returned_quantity ?? 0), 8),
                '', '', '', '', '', ...$this->discountColumns($line), '',
            ]);

            return collect([$header])->concat($lines);
        });
        $invoiceCsvRows->push([$this->label('row_types.total'), $totalLabel.' ('.($ledgerSummary['invoice_count'] ?? 0).')', '', '', '', '', '', '', '', '', '', '', '', '', '',
            $ledgerSummary['gross_sales'] ?? '0', $ledgerSummary['returns_amount'] ?? '0', $ledgerSummary['net_sales'] ?? '0',
            $ledgerSummary['collected'] ?? '0', $ledgerSummary['outstanding'] ?? '0', '', '', '', $ledgerSummary['actual_withholding'] ?? '0']);
        $customerRows = collect($this->report['salesByCustomer'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $row->sales_value, $row->outstanding]);
        $customerRows->push([$totalLabel.' ('.($customerSummary['customer_count'] ?? 0).')', '', $customerSummary['sales_value'] ?? 0, $customerSummary['outstanding'] ?? 0]);
        $productRows = collect($this->report['salesByItem'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $row->sold_quantity, $row->sales_value]);
        $productRows->push([$totalLabel.' ('.($productSummary['product_count'] ?? 0).')', '', $productSummary['sold_quantity'] ?? 0, $productSummary['sales_value'] ?? 0]);
        $customerProductRows = collect($this->report['salesByCustomerItem'] ?? [])->map(fn ($row): array => [$row->customer_doc_num, $row->customer_name, $row->product_doc_num, $row->product_name, $row->sold_quantity, $row->sales_value]);
        $customerProductRows->push([$totalLabel.' ('.($customerProductSummary['line_count'] ?? 0).')', '', '', '', $customerProductSummary['sold_quantity'] ?? 0, $customerProductSummary['sales_value'] ?? 0]);
        $periodRows = collect($this->report['salesByPeriod'] ?? [])->map(fn ($row): array => [$dateValue($row->invoice_date), $row->invoice_count, $row->sales_value]);
        $periodRows->push([$totalLabel, $periodSummary['invoice_count'] ?? 0, $periodSummary['sales_value'] ?? 0]);
        $outstandingRows = collect($this->report['invoiceOutstanding'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $dateValue($row->due_date), $row->total_amount, $row->remaining_amount]);
        $outstandingRows->push([$totalLabel.' ('.($outstandingSummary['invoice_count'] ?? 0).')', '', '', $outstandingSummary['total_value'] ?? 0, $outstandingSummary['outstanding'] ?? 0]);
        $installmentRows = collect($this->report['installments'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $dateValue($row->due_date), $row->outstanding]);
        $installmentRows->push([$totalLabel.' ('.($installmentSummary['schedule_count'] ?? 0).')', '', '', $installmentSummary['outstanding'] ?? 0]);
        $collectionRows = collect($this->report['customerReceipts'] ?? [])->map(fn ($row): array => [$row->doc_num, $dateValue($row->receipt_date), $row->customer?->name, $row->receivedByEmployee?->full_name ?: $row->receivedByEmployee?->name, $this->enumLabel($row->payment_method), $row->reference_no ?: $row->cashVoucher?->doc_num ?: $row->cheque?->doc_num, $row->amount, $row->unallocated_amount, $this->enumLabel($row->status)]);
        $collectionRows->push([$totalLabel.' ('.($collectionSummary['receipt_count'] ?? 0).')', '', '', '', '', '', $collectionSummary['amount'] ?? 0, $collectionSummary['unallocated'] ?? 0, '']);
        $upcomingRows = collect($this->report['upcomingCollections'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $dateValue($row->due_date), $row->outstanding]);
        $upcomingRows->push([$totalLabel.' ('.($upcomingSummary['schedule_count'] ?? 0).')', '', '', $upcomingSummary['outstanding'] ?? 0]);
        $agingRows = collect($this->report['aging'] ?? [])->map(fn (array $row): array => [$row['customer'], $row['current'], $row['1_30'], $row['31_60'], $row['61_90'], $row['over_90']]);
        $agingRows->push([$totalLabel, $agingTotals['current'] ?? 0, $agingTotals['1_30'] ?? 0, $agingTotals['31_60'] ?? 0, $agingTotals['61_90'] ?? 0, $agingTotals['over_90'] ?? 0]);
        $returnsRows = collect($this->report['returns'] ?? [])->map(fn ($row): array => [$this->enumLabel($row->reason_code), $row->return_count, $row->returned_quantity, $row->saleable_quantity, $row->rejected_quantity]);
        $returnsRows->push([$totalLabel, $returnsSummary['return_count'] ?? 0, $returnsSummary['returned_quantity'] ?? 0, $returnsSummary['saleable_quantity'] ?? 0, $returnsSummary['rejected_quantity'] ?? 0]);
        $returnQualityRows = collect($this->report['returnAnalysis'] ?? [])->map(fn ($row): array => [$row->customer_doc_num, $row->customer_name, $row->product_doc_num, $row->product_name, $this->enumLabel($row->reason_code), $this->enumLabel($row->quality_disposition), $row->returned_quantity]);
        $returnQualityRows->push([$totalLabel.' ('.($returnAnalysisSummary['line_count'] ?? 0).')', '', '', '', '', '', $returnAnalysisSummary['returned_quantity'] ?? 0]);
        $costOfSalesRows = collect($this->report['costOfSalesRows'] ?? [])->map(fn (array $row): array => [$this->enumLabel($row['movement_kind']), $row['document'], $row['return_document'] ?? '', $row['order'] ?? '', $row['invoice'] ?? '', $row['customer'] ?? '', $row['product'] ?? '', $row['quantity'] ?? '', $row['unit_cost'] ?? '', $row['signed_total_cost'] ?? '', $dateValue($row['posting_date'] ?? null), $row['journal_entry'] ?? '', $this->enumLabel($row['reconciliation_status'])]);
        $costOfSalesSummaryRows = collect([
            [$this->label('metrics.delivery_count'), $this->report['costOfSalesSummary']['delivery_count'] ?? 0],
            [$this->label('metrics.return_count'), $this->report['costOfSalesSummary']['return_count'] ?? 0],
            [$this->label('metrics.delivery_cost'), $this->report['costOfSalesSummary']['delivery_cost'] ?? '0.0000'],
            [$this->label('metrics.return_cost'), $this->report['costOfSalesSummary']['return_cost'] ?? '0.0000'],
            [$this->label('metrics.net_cost'), $this->report['costOfSalesSummary']['net_cost'] ?? '0.0000'],
            [$this->label('metrics.unreconciled_count'), $this->report['costOfSalesSummary']['unreconciled_count'] ?? 0],
        ]);
        $creditMovementRows = collect($this->report['creditMovements'] ?? [])->map(fn (array $row): array => [$row['document'], $row['customer'], $dateValue($row['posting_date']), __('sales_balance_report.'.$row['kind']), $row['signed_amount']]);
        $creditMovementTotal = $creditMovementRows->reduce(fn (string $total, array $row): string => bcadd($total, $row[4], 4), '0.0000');
        $creditMovementRows->push([$totalLabel, '', '', '', $creditMovementTotal]);
        $requestRow = fn ($row): array => [
            $row->doc_num, $dateValue($row->request_date), $row->customer?->name, $this->enumLabel($row->status),
            $row->requested_quantity, $row->converted_quantity, $row->downstream_declined_quantity,
            $row->net_converted_quantity, $row->remaining_quantity,
        ];
        $requestRows = collect($this->report['salesRequests'] ?? [])->map($requestRow);
        $declinedRequestRows = collect($this->report['declinedSalesRequests'] ?? [])->map($requestRow);
        $sheets = [
            'credit_movements' => $this->sheet(__('sales_balance_report.sheet'), [__('Document'), __('Customer'), __('Date'), __('Type'), __('sales_balance_report.signed_amount')], $creditMovementRows, ['E']),
            'summary' => $this->sheet($this->label('sheets.summary'), $this->headings(['metric', 'value']), collect([
                [$this->label('metrics.invoice_count'), $summary['invoice_count'] ?? 0], [$this->label('metrics.gross_sales'), $summary['gross_sales'] ?? 0],
                [$this->label('metrics.credit_notes_returns'), $summary['credit_notes'] ?? 0], [$this->label('metrics.net_sales'), $summary['net_sales'] ?? 0],
                [$this->label('metrics.collections'), $summary['collections'] ?? 0], [$this->label('metrics.outstanding'), $summary['outstanding'] ?? 0],
                [$this->label('metrics.overdue_outstanding'), $summary['overdue_outstanding'] ?? 0], [$this->label('metrics.collection_rate'), $summary['collection_rate'] ?? 0],
                [$this->label('metrics.return_rate'), $summary['return_rate'] ?? 0],
            ]), ['B']),
            'ledger' => $this->sheet($this->label('sheets.ledger'), $this->headings(['invoice_date', 'customer', 'invoice', 'order', 'deliveries', 'currency', 'gross', 'discount', 'tax', 'net_invoice', 'credit_notes_returns', 'net_sales', 'collected', 'outstanding', 'discount_type', 'discount_value', 'header_discount_amount', 'actual_withholding']), $ledgerRows, [...range('G', 'N'), 'P', 'Q', 'R']),
            'invoice_lines' => $this->sheet($this->label('sheets.invoice_lines'), $this->headings(['invoice', 'customer', 'item', 'category', 'quantity', 'price', 'discount', 'tax', 'value', 'returned', 'net_sold', 'discount_type', 'discount_value', 'header_discount_amount']), $invoiceLineRows, [...range('E', 'K'), 'M', 'N']),
            'quotations' => $this->sheet($this->label('sheets.quotations'), $this->headings(['quotation', 'customer', 'date', 'valid_until', 'status', 'revision', 'total']), collect($this->report['quotations'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->customer?->name, $dateValue($row->quotation_date), $dateValue($row->valid_until), __('quotations.statuses.'.$row->status), $row->currentRevision?->revision_code, $row->currentRevision?->total]), ['G']),
            'requests' => $this->sheet($this->label('sheets.requests'), $this->headings(['document', 'date', 'customer', 'status', 'requested', 'converted', 'downstream_declined', 'net_converted', 'remaining']), $requestRows, range('E', 'I')),
            'request_declines' => $this->sheet($this->label('sheets.request_declines'), $this->headings(['document', 'date', 'customer', 'status', 'requested', 'converted', 'downstream_declined', 'net_converted', 'remaining']), $declinedRequestRows, range('E', 'I')),
            'orders' => $this->sheet($this->label('sheets.orders'), $this->headings(['order', 'customer', 'required_date', 'status', 'ordered', 'declined', 'effective', 'invoiced', 'credited', 'net_invoiced', 'delivered', 'remaining_delivery']), collect($this->report['openOrders'] ?? [])->map(fn ($row): array => $this->fulfillmentRow($row)), range('E', 'L')),
            'customers' => $this->sheet($this->label('sheets.customers'), $this->headings(['customer_code', 'customer', 'sales', 'outstanding']), $customerRows, ['C', 'D']),
            'products' => $this->sheet($this->label('sheets.products'), $this->headings(['product_code', 'product', 'quantity', 'sales']), $productRows, ['C', 'D']),
            'customer_products' => $this->sheet($this->label('sheets.customer_products'), $this->headings(['customer_code', 'customer', 'product_code', 'product', 'quantity', 'sales']), $customerProductRows, ['E', 'F']),
            'period' => $this->sheet($this->label('sheets.period'), $this->headings(['date', 'invoice_count', 'sales']), $periodRows, ['C']),
            'outstanding' => $this->sheet($this->label('sheets.outstanding'), $this->headings(['invoice', 'customer', 'due_date', 'total', 'outstanding']), $outstandingRows, ['D', 'E']),
            'installments' => $this->sheet($this->label('sheets.installments'), $this->headings(['invoice', 'customer', 'due_date', 'outstanding']), $installmentRows, ['D']),
            'collections' => $this->sheet($this->label('sheets.collections'), $this->headings(['receipt', 'date', 'customer', 'received_by_employee', 'payment_method', 'reference', 'amount', 'unallocated', 'status']), $collectionRows, ['G', 'H']),
            'upcoming_collections' => $this->sheet($this->label('sheets.upcoming_collections'), $this->headings(['invoice', 'customer', 'due_date', 'outstanding']), $upcomingRows, ['D']),
            'aging' => $this->sheet($this->label('sheets.aging'), $this->headings(['customer', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90']), $agingRows, range('B', 'F')),
            'returns' => $this->sheet($this->label('sheets.returns'), $this->headings(['reason', 'returns', 'returned_quantity', 'saleable_quantity', 'rejected_quantity']), $returnsRows, ['C', 'D', 'E']),
            'return_quality' => $this->sheet($this->label('sheets.return_quality'), $this->headings(['customer_code', 'customer', 'product_code', 'product', 'reason', 'disposition', 'returned_quantity']), $returnQualityRows, ['G']),
            'unpriced_products' => $this->sheet($this->label('sheets.unpriced_products'), $this->headings(['product_code', 'product', 'category']), collect($this->report['unpricedProducts'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name, $row->category_name])),
            'customers_without_prices' => $this->sheet($this->label('sheets.customers_without_prices'), $this->headings(['customer_code', 'customer']), collect($this->report['customersWithoutPriceLists'] ?? [])->map(fn ($row): array => [$row->doc_num, $row->name])),
            'customer_price_gaps' => $this->sheet($this->label('sheets.customer_price_gaps'), $this->headings(['customer_code', 'customer', 'product_code', 'product']), collect($this->report['customerProductPricingGaps'] ?? [])->map(fn ($row): array => [$row->customer_doc_num, $row->customer_name, $row->product_doc_num, $row->product_name])),
            'cost_of_sales' => $this->sheet($this->label('sheets.cost_of_sales'), $this->headings(['movement_kind', 'document', 'return_document', 'order', 'invoice', 'customer', 'product', 'quantity', 'unit_cost', 'signed_total_cost', 'posting_date', 'journal_entry', 'reconciliation_status']), $costOfSalesRows, ['H', 'I', 'J']),
            'cost_of_sales_summary' => $this->sheet($this->label('sheets.cost_of_sales_summary'), $this->headings(['metric', 'value']), $costOfSalesSummaryRows, ['B']),
        ];

        if ($this->forCsv && ($this->report['reportType'] ?? null) === 'invoices') {
            foreach ($this->report['creditMovements'] ?? [] as $movement) {
                $invoiceCsvRows->push([__('sales_balance_report.'.$movement['kind']), $movement['document'], $movement['customer'], $dateValue($movement['posting_date']), '', '',
                    '', '', '', '', '', '', '', '', '', '', '', $movement['signed_amount'], '', '']);
            }

            return [$this->sheet($this->label('sheets.ledger'), $this->headings([
                'row_type', 'invoice', 'customer', 'invoice_date', 'order', 'currency', 'item', 'category',
                'quantity', 'price', 'discount', 'tax', 'value', 'returned', 'net_sold',
                'net_invoice', 'credit_notes_returns', 'net_sales', 'collected', 'outstanding', 'discount_type', 'discount_value', 'header_discount_amount', 'actual_withholding',
            ]), $invoiceCsvRows, $this->csvTextColumns(24))];
        }

        if ($this->forCsv && ($this->report['reportType'] ?? null) === 'cost_of_sales') {
            $csvRows = $costOfSalesRows->map(fn (array $row): array => [$this->label('row_types.detail'), '', '', ...$row]);
            $csvRows = $csvRows->concat($costOfSalesSummaryRows->map(fn (array $row): array => [
                $this->label('row_types.summary'), $row[0], $row[1], ...array_fill(0, 13, ''),
            ]));

            return [$this->sheet($this->label('sheets.cost_of_sales'), $this->headings([
                'row_type', 'metric', 'summary_value', 'movement_kind', 'document', 'return_document', 'order', 'invoice',
                'customer', 'product', 'quantity', 'unit_cost', 'signed_total_cost', 'posting_date', 'journal_entry', 'reconciliation_status',
            ]), $csvRows, $this->csvTextColumns(16))];
        }

        $keys = match ($this->report['reportType'] ?? 'operational') {
            'financial' => ['summary', 'customers', 'outstanding', 'aging', 'credit_movements'],
            'period' => ['period'],
            'customers' => ['customers'],
            'products' => ['products', 'customer_products'],
            'invoices' => ['ledger', 'invoice_lines', 'credit_movements'],
            'receivables' => ['outstanding', 'installments', 'aging'],
            'collections' => ['collections', 'upcoming_collections'],
            'returns' => ['returns', 'return_quality'],
            'quotations' => ['quotations'],
            'fulfillment' => ['orders'],
            'pricing' => ['unpriced_products', 'customers_without_prices', 'customer_price_gaps'],
            'cost_of_sales' => ['cost_of_sales_summary', 'cost_of_sales'],
            default => ['summary', 'requests', 'request_declines', 'quotations', 'orders', 'ledger', 'credit_movements'],
        };

        if ($this->forCsv && count($keys) > 1) {
            $maximumColumns = max(array_map(fn (string $key): int => count($sheets[$key]->headings()), $keys));
            $csvRows = collect();
            foreach ($keys as $key) {
                $sheet = $sheets[$key];
                $csvRows->push([$sheet->title(), $this->label('row_types.headings'), ...$sheet->headings()]);
                foreach ($sheet->array() as $row) {
                    $csvRows->push([$sheet->title(), $this->label('row_types.data'), ...$row]);
                }
            }

            return [$this->sheet($sheets[$keys[0]]->title(), [
                $this->label('headings.section'), $this->label('headings.row_type'), ...array_fill(0, $maximumColumns, ''),
            ], $csvRows, $this->csvTextColumns($maximumColumns + 2))];
        }

        if ($this->forCsv) {
            $sheet = $sheets[$keys[0]];

            return [$this->sheet($sheet->title(), $sheet->headings(), collect($sheet->array()), $this->csvTextColumns(count($sheet->headings())))];
        }

        return collect($keys)->map(fn (string $key): SalesCycleReportSheet => $sheets[$key])->all();
    }

    /** @return array{string, string, string} */
    private function discountColumns(object $record): array
    {
        return [$record->discount_type ? __('quotations.discount_types.'.$record->discount_type) : '',
            (string) ($record->discount_value ?? ''), (string) ($record->header_discount_amount ?? '0.0000')];
    }

    /** @param list<string> $headings */
    private function sheet(string $title, array $headings, Collection $rows, array $exactDecimalColumns = []): SalesCycleReportSheet
    {
        return new SalesCycleReportSheet($title, $headings, $rows->values()->all(), $exactDecimalColumns);
    }

    /** @return list<string> */
    private function csvTextColumns(int $count): array
    {
        return array_map(fn (int $index): string => Coordinate::stringFromColumnIndex($index), range(1, $count));
    }

    /** @return list<string|null> */
    private function fulfillmentRow(object $order): array
    {
        $quantities = app(SalesCycleReadService::class)->fulfillmentQuantities($order);

        return [
            $order->doc_num,
            $order->customer?->name,
            app(DateFormatService::class)->formatDate($order->expected_delivery_date, ''),
            $this->enumLabel($order->status),
            $quantities['ordered'],
            $quantities['declined'],
            $quantities['effective'],
            $quantities['invoiced'],
            $quantities['credited'],
            $quantities['net_invoiced'],
            $quantities['delivered'],
            $quantities['remaining'],
        ];
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

class SalesCycleReportSheet extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithTitle
{
    /** @param list<string> $headings @param list<array<int, mixed>> $rows */
    public function __construct(
        private readonly string $sheetTitle,
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $exactDecimalColumns = [],
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

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($cell->getRow() > 1 && $value !== null && in_array($cell->getColumn(), $this->exactDecimalColumns, true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
