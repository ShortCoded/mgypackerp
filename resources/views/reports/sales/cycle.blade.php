@extends('reports.layouts.pdf')

@section('report')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $dateValue = fn ($value) => $value ? $dates->formatDate($value, '') : '';
    $qualityDispositionLabel = static fn (?string $value): string => collect(explode(',', (string) $value))
        ->filter()
        ->map(fn (string $bucket): string => __(str($bucket)->replace('_', ' ')->title()->toString()))
        ->join(app()->isLocale('ar') ? '، ' : ', ');
@endphp
<div class="report-filter-summary">
    {{ __('Currency') }}: {{ $reportCurrency?->code }}
    @if($from || $to) · {{ $dateValue($from) }} — {{ $dateValue($to) }} @endif
</div>

@if($reportType === 'pricing')
<p>{{ __('sales_ui.reports.pricing_as_of', ['date' => $dateValue($pricingDate), 'currency' => $reportCurrency?->code]) }}</p>
<h3>{{ __('sales_ui.reports.unpriced_products') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Category') }}</th></tr></thead><tbody>@forelse($unpricedProducts as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td>{{ $row->category_name ?: '—' }}</td></tr>@empty<tr><td colspan="2">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody></table>
<h3>{{ __('sales_ui.reports.customers_without_price_lists') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th></tr></thead><tbody>@forelse($customersWithoutPriceLists as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td></tr>@empty<tr><td>{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody></table>
<h3>{{ __('sales_ui.reports.customer_unpriced_products') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Product') }}</th></tr></thead><tbody>@forelse($customerProductPricingGaps as $row)<tr><td>{{ $row->customer_doc_num }} / {{ $row->customer_name }}</td><td>{{ $row->product_doc_num }} / {{ $row->product_name }}</td></tr>@empty<tr><td colspan="2">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody></table>
@endif

@if(in_array($reportType, ['financial', 'operational'], true))
<h3>{{ __('sales_ui.financial_summary') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('sales_ui.invoice_count') }}</th><th>{{ __('sales_ui.gross_sales') }}</th><th>{{ __('sales_ui.credit_notes_returns') }}</th><th>{{ __('sales_ui.net_sales') }}</th><th>{{ __('sales_ui.collections') }}</th><th>{{ __('sales_ui.outstanding') }}</th><th>{{ __('sales_ui.overdue_outstanding') }}</th><th>{{ __('sales_ui.collection_rate') }}</th></tr></thead><tbody><tr><td>{{ $numbers->format($financialSummary['invoice_count']) }}</td><td>{{ $numbers->format($financialSummary['gross_sales']) }}</td><td>{{ $numbers->format($financialSummary['credit_notes']) }}</td><td>{{ $numbers->format($financialSummary['net_sales']) }}</td><td>{{ $numbers->format($financialSummary['collections']) }}</td><td>{{ $numbers->format($financialSummary['outstanding']) }}</td><td>{{ $numbers->format($financialSummary['overdue_outstanding']) }}</td><td>{{ $numbers->format($financialSummary['collection_rate']) }}%</td></tr></tbody></table>
@endif

@if(in_array($reportType, ['customers', 'financial'], true))
<h3>{{ __('Sales / Outstanding by Customer') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Sales') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@forelse($salesByCustomer as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td>{{ $numbers->format($row->sales_value) }}</td><td>{{ $numbers->format($row->outstanding) }}</td></tr>@empty<tr><td colspan="3">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td>{{ __('Totals') }} ({{ $customerSummary['customer_count'] ?? 0 }})</td><td>{{ $numbers->format($customerSummary['sales_value'] ?? 0) }}</td><td>{{ $numbers->format($customerSummary['outstanding'] ?? 0) }}</td></tr></tfoot></table>
@endif

@if($reportType === 'period')
<h3>{{ __('Sales by Period') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Invoices') }}</th><th>{{ __('Value') }}</th></tr></thead><tbody>@forelse($salesByPeriod as $row)<tr><td>{{ $dateValue($row->invoice_date) }}</td><td>{{ $row->invoice_count }}</td><td>{{ $numbers->format($row->sales_value) }}</td></tr>@empty<tr><td colspan="3">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td>{{ __('Totals') }}</td><td>{{ $periodSummary['invoice_count'] ?? 0 }}</td><td>{{ $numbers->format($periodSummary['sales_value'] ?? 0) }}</td></tr></tfoot></table>
@endif

@if($reportType === 'products')
<h3>{{ __('Sales by Item') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Value') }}</th></tr></thead><tbody>@forelse($salesByItem as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td>{{ $numbers->format($row->sold_quantity) }}</td><td>{{ $numbers->format($row->sales_value) }}</td></tr>@empty<tr><td colspan="3">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td>{{ __('Totals') }} ({{ $productSummary['product_count'] ?? 0 }})</td><td>{{ $numbers->format($productSummary['sold_quantity'] ?? 0) }}</td><td>{{ $numbers->format($productSummary['sales_value'] ?? 0) }}</td></tr></tfoot></table>
@endif
@if($reportType === 'products')
<h3>{{ __('Customer / Item Sales Analysis') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Value') }}</th></tr></thead><tbody>@forelse($salesByCustomerItem as $row)<tr><td>{{ $row->customer_doc_num }} / {{ $row->customer_name }}</td><td>{{ $row->product_doc_num }} / {{ $row->product_name }}</td><td>{{ $numbers->format($row->sold_quantity) }}</td><td>{{ $numbers->format($row->sales_value) }}</td></tr>@empty<tr><td colspan="4">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td colspan="2">{{ __('Totals') }} ({{ $customerProductSummary['line_count'] ?? 0 }})</td><td>{{ $numbers->format($customerProductSummary['sold_quantity'] ?? 0) }}</td><td>{{ $numbers->format($customerProductSummary['sales_value'] ?? 0) }}</td></tr></tfoot></table>
@endif

@if(in_array($reportType, ['invoices', 'operational'], true))
<h3>{{ __('Sales Ledger') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Sales Order') }}</th><th>{{ __('Issue Orders') }}</th><th>{{ __('Net Sales') }}</th><th>{{ __('Collected') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@forelse($salesLedger as $invoice)<tr><td>{{ $invoice->doc_num }}</td><td>{{ $invoice->customer?->name }}</td><td>{{ $dateValue($invoice->invoice_date) }}</td><td>{{ $invoice->order?->doc_num ?: '—' }}</td><td>{{ $invoice->deliveries->pluck('doc_num')->join(' / ') ?: '—' }}</td><td>{{ $numbers->format(bcsub((string) $invoice->total_amount, (string) ($invoice->returns_amount ?? 0), 4)) }}</td><td>{{ $numbers->format($invoice->paid_amount) }}</td><td>{{ $numbers->format($invoice->remaining_amount) }}</td></tr>@empty<tr><td colspan="8">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td colspan="5">{{ __('Totals') }} ({{ $ledgerSummary['invoice_count'] ?? 0 }})</td><td>{{ $numbers->format($ledgerSummary['net_sales'] ?? 0) }}</td><td>{{ $numbers->format($ledgerSummary['collected'] ?? 0) }}</td><td>{{ $numbers->format($ledgerSummary['outstanding'] ?? 0) }}</td></tr></tfoot></table>
@endif

@if(in_array($reportType, ['receivables', 'financial'], true))
<h3>{{ __('Invoice Outstanding') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@forelse($invoiceOutstanding as $invoice)<tr><td>{{ $invoice->doc_num }}</td><td>{{ $invoice->customer?->name }}</td><td>{{ $dateValue($invoice->due_date) }}</td><td>{{ $numbers->format($invoice->remaining_amount) }}</td></tr>@empty<tr><td colspan="4">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td colspan="3">{{ __('Totals') }} ({{ $outstandingSummary['invoice_count'] ?? 0 }})</td><td>{{ $numbers->format($outstandingSummary['outstanding'] ?? 0) }}</td></tr></tfoot></table>
@endif
@if(in_array($reportType, ['receivables', 'financial'], true))
<h3>{{ __('Customer Aging') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Current') }}</th><th>1–30</th><th>31–60</th><th>61–90</th><th>90+</th></tr></thead><tbody>@forelse($aging as $row)<tr><td>{{ $row['customer'] }}</td><td>{{ $numbers->format($row['current']) }}</td><td>{{ $numbers->format($row['1_30']) }}</td><td>{{ $numbers->format($row['31_60']) }}</td><td>{{ $numbers->format($row['61_90']) }}</td><td>{{ $numbers->format($row['over_90']) }}</td></tr>@empty<tr><td colspan="6">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td>{{ __('Totals') }}</td><td>{{ $numbers->format($agingTotals['current'] ?? 0) }}</td><td>{{ $numbers->format($agingTotals['1_30'] ?? 0) }}</td><td>{{ $numbers->format($agingTotals['31_60'] ?? 0) }}</td><td>{{ $numbers->format($agingTotals['61_90'] ?? 0) }}</td><td>{{ $numbers->format($agingTotals['over_90'] ?? 0) }}</td></tr></tfoot></table>
@endif

@if($reportType === 'collections')
<h3>{{ __('sales_ui.recorded_collections') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Customer') }}</th><th>{{ __('sales_ui.received_by_employee') }}</th><th>{{ __('Payment method') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@forelse($customerReceipts as $receipt)<tr><td>{{ $receipt->doc_num }}</td><td>{{ $dateValue($receipt->receipt_date) }}</td><td>{{ $receipt->customer?->name }}</td><td>{{ $receipt->receivedByEmployee?->full_name ?: $receipt->receivedByEmployee?->name ?: __('sales_ui.legacy_receiver_unresolved') }}</td><td>{{ __(str($receipt->payment_method)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $receipt->reference_no ?: $receipt->cashVoucher?->doc_num ?: $receipt->cheque?->doc_num ?: '—' }}</td><td>{{ $numbers->format($receipt->amount) }} {{ $receipt->currency?->code }}</td><td>{{ __(str($receipt->status)->replace('_', ' ')->title()->toString()) }}</td></tr>@empty<tr><td colspan="8">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td colspan="6">{{ __('Totals') }} ({{ $collectionSummary['receipt_count'] ?? 0 }})</td><td>{{ $numbers->format($collectionSummary['amount'] ?? 0) }}</td><td></td></tr></tfoot></table>
@endif
@if($reportType === 'collections')
<h3>{{ __('sales_ui.upcoming_collections') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@forelse($upcomingCollections as $row)<tr><td>{{ $row->doc_num }}</td><td>{{ $row->name }}</td><td>{{ $dateValue($row->due_date) }}</td><td>{{ $numbers->format($row->outstanding) }}</td></tr>@empty<tr><td colspan="4">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td colspan="3">{{ __('Totals') }} ({{ $upcomingSummary['schedule_count'] ?? 0 }})</td><td>{{ $numbers->format($upcomingSummary['outstanding'] ?? 0) }}</td></tr></tfoot></table>
@endif

@if(in_array($reportType, ['quotations', 'operational'], true) && $quotations->isNotEmpty())
<h3>{{ __('Quotation Status / History') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Quotation') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Valid until') }}</th><th>{{ __('Status') }}</th><th>{{ __('Revision') }}</th><th>{{ __('Total') }}</th></tr></thead><tbody>@foreach($quotations as $quotation)<tr><td>{{ $quotation->doc_num }}</td><td>{{ $quotation->customer?->name }}</td><td>{{ $dateValue($quotation->quotation_date) }}</td><td>{{ $dateValue($quotation->valid_until) }}</td><td>{{ __('quotations.statuses.'.$quotation->status) }}</td><td>{{ $quotation->currentRevision?->revision_code }}</td><td>{{ $numbers->format($quotation->currentRevision?->total) }}</td></tr>@endforeach</tbody></table>
@endif

@if(in_array($reportType, ['fulfillment', 'operational'], true) && $openOrders->isNotEmpty())
<h3>{{ __('Invoice to Delivery Fulfillment') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Order') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Required date') }}</th><th>{{ __('Ordered') }}</th><th>{{ __('Invoiced') }}</th><th>{{ __('Delivered') }}</th><th>{{ __('Remaining Delivery') }}</th></tr></thead><tbody>@foreach($openOrders as $order)<tr><td>{{ $order->doc_num }}</td><td>{{ $order->customer?->name }}</td><td>{{ $dateValue($order->expected_delivery_date) }}</td><td>{{ $numbers->format($order->ordered_quantity) }}</td><td>{{ $numbers->format($order->lines->sum('invoiced_quantity')) }}</td><td>{{ $numbers->format($order->delivered_quantity) }}</td><td>{{ $numbers->format(max(0, (float) $order->ordered_quantity - (float) $order->delivered_quantity)) }}</td></tr>@endforeach</tbody></table>
@endif

@if($reportType === 'returns')
<h3>{{ __('Returns by Reason and Quality Disposition') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Reason') }}</th><th>{{ __('Returns') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Saleable') }}</th><th>{{ __('Rejected / Rework / Scrap') }}</th></tr></thead><tbody>@forelse($returns as $row)<tr><td>{{ __(str($row->reason_code)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $row->return_count }}</td><td>{{ $numbers->format($row->returned_quantity) }}</td><td>{{ $numbers->format($row->saleable_quantity) }}</td><td>{{ $numbers->format($row->rejected_quantity) }}</td></tr>@empty<tr><td colspan="5">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td>{{ __('Totals') }} ({{ $returnsSummary['return_count'] ?? 0 }})</td><td></td><td>{{ $numbers->format($returnsSummary['returned_quantity'] ?? 0) }}</td><td>{{ $numbers->format($returnsSummary['saleable_quantity'] ?? 0) }}</td><td>{{ $numbers->format($returnsSummary['rejected_quantity'] ?? 0) }}</td></tr></tfoot></table>
@endif
@if($reportType === 'returns')
<h3>{{ __('Customer / Item Return Analysis') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Item') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Disposition') }}</th><th>{{ __('Quantity') }}</th></tr></thead><tbody>@forelse($returnAnalysis as $row)<tr><td>{{ $row->customer_name }}</td><td>{{ $row->product_name }}</td><td>{{ __(str($row->reason_code)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $row->quality_disposition ? $qualityDispositionLabel($row->quality_disposition) : '—' }}</td><td>{{ $numbers->format($row->returned_quantity) }}</td></tr>@empty<tr><td colspan="5">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td colspan="4">{{ __('Totals') }} ({{ $returnAnalysisSummary['line_count'] ?? 0 }})</td><td>{{ $numbers->format($returnAnalysisSummary['returned_quantity'] ?? 0) }}</td></tr></tfoot></table>
@endif

@if($reportType === 'cost_of_sales')
<h3>{{ __('sales_ui.reports.types.cost_of_sales') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('sales_ui.reports.export.headings.movement_kind') }}</th><th>{{ __('sales_ui.reports.export.headings.document') }}</th><th>{{ __('sales_ui.reports.export.headings.return_document') }}</th><th>{{ __('sales_ui.reports.export.headings.order') }}</th><th>{{ __('sales_ui.reports.export.headings.invoice') }}</th><th>{{ __('sales_ui.reports.export.headings.customer') }}</th><th>{{ __('sales_ui.reports.export.headings.product') }}</th><th>{{ __('sales_ui.reports.export.headings.quantity') }}</th><th>{{ __('sales_ui.reports.export.headings.unit_cost') }}</th><th>{{ __('sales_ui.reports.export.headings.total_cost') }}</th><th>{{ __('sales_ui.reports.export.headings.posting_date') }}</th><th>{{ __('sales_ui.reports.export.headings.journal') }}</th><th>{{ __('sales_ui.reports.export.headings.reconciliation') }}</th></tr></thead><tbody>@forelse($costOfSalesRows as $row)<tr><td>{{ __(str($row['movement_kind'])->replace('_', ' ')->title()->toString()) }}</td><td>{{ $row['document'] }}</td><td>{{ $row['return_document'] ?? '—' }}</td><td>{{ $row['order'] ?? '—' }}</td><td>{{ $row['invoice'] ?? '—' }}</td><td>{{ $row['customer'] ?? '—' }}</td><td>{{ $row['product'] ?? '—' }}</td><td>{{ $numbers->format($row['quantity']) }}</td><td>{{ $row['unit_cost'] !== null ? $numbers->format($row['unit_cost']) : '—' }}</td><td>{{ $row['signed_total_cost'] !== null ? $numbers->format($row['signed_total_cost']) : '—' }}</td><td>{{ $dateValue($row['posting_date']) }}</td><td>{{ $row['journal_entry'] ?? '—' }}</td><td>{{ __(str($row['reconciliation_status'])->replace('_', ' ')->title()->toString()) }}</td></tr>@empty<tr><td colspan="13">{{ __('sales_ui.reports.no_results') }}</td></tr>@endforelse</tbody><tfoot><tr><td colspan="7">{{ __('Totals') }} ({{ $costOfSalesSummary['delivery_count'] ?? 0 }} {{ __('sales_ui.reports.export.metrics.deliveries') }} / {{ $costOfSalesSummary['return_count'] ?? 0 }} {{ __('sales_ui.reports.export.metrics.returns') }})</td><td></td><td></td><td>{{ $numbers->format($costOfSalesSummary['net_cost'] ?? '0.0000') }}</td><td colspan="3">{{ $costOfSalesSummary['unreconciled_count'] ?? 0 }} {{ __('sales_ui.reports.export.metrics.unreconciled') }}</td></tr></tfoot></table>
@endif

@if(!in_array($reportType, ['financial', 'operational', 'cost_of_sales'], true)
    && collect([$salesByCustomer, $salesByPeriod, $salesByItem, $salesLedger, $invoiceOutstanding, $customerReceipts, $upcomingCollections, $quotations, $openOrders, $returns, $unpricedProducts, $customersWithoutPriceLists, $customerProductPricingGaps])->every(fn ($rows) => collect($rows)->isEmpty()))
<p class="report-empty-state">{{ __('sales_ui.reports.no_results') }}</p>
@endif

<style>
    h3 { margin: 10px 0 4px; font-size: 10px; }
    .report-table { margin-bottom: 8px; }
    .report-table th, .report-table td { font-size: 7px; }
    .report-table thead { display: table-header-group; }
    .report-table tr { page-break-inside: avoid; }
    .report-empty-state { text-align: center; color: #6c757d; padding: 20px 0; }
</style>
@endsection
