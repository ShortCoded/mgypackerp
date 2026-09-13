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

@if(in_array($reportType, ['customers', 'financial'], true) && $salesByCustomer->isNotEmpty())
<h3>{{ __('Sales / Outstanding by Customer') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Sales') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($salesByCustomer as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td>{{ $numbers->format($row->sales_value) }}</td><td>{{ $numbers->format($row->outstanding) }}</td></tr>@endforeach</tbody></table>
@endif

@if($reportType === 'period' && $salesByPeriod->isNotEmpty())
<h3>{{ __('Sales by Period') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Invoices') }}</th><th>{{ __('Value') }}</th></tr></thead><tbody>@foreach($salesByPeriod as $row)<tr><td>{{ $dateValue($row->invoice_date) }}</td><td>{{ $row->invoice_count }}</td><td>{{ $numbers->format($row->sales_value) }}</td></tr>@endforeach</tbody></table>
@endif

@if($reportType === 'products' && $salesByItem->isNotEmpty())
<h3>{{ __('Sales by Item') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Value') }}</th></tr></thead><tbody>@foreach($salesByItem as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td>{{ $numbers->format($row->sold_quantity) }}</td><td>{{ $numbers->format($row->sales_value) }}</td></tr>@endforeach</tbody></table>
@endif
@if($reportType === 'products' && $salesByCustomerItem->isNotEmpty())
<h3>{{ __('Customer / Item Sales Analysis') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Value') }}</th></tr></thead><tbody>@foreach($salesByCustomerItem as $row)<tr><td>{{ $row->customer_doc_num }} / {{ $row->customer_name }}</td><td>{{ $row->product_doc_num }} / {{ $row->product_name }}</td><td>{{ $numbers->format($row->sold_quantity) }}</td><td>{{ $numbers->format($row->sales_value) }}</td></tr>@endforeach</tbody></table>
@endif

@if(in_array($reportType, ['invoices', 'operational'], true) && collect($salesLedger)->isNotEmpty())
<h3>{{ __('Sales Ledger') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Sales Order') }}</th><th>{{ __('Issue Orders') }}</th><th>{{ __('Net Sales') }}</th><th>{{ __('Collected') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($salesLedger as $invoice)<tr><td>{{ $invoice->doc_num }}</td><td>{{ $invoice->customer?->name }}</td><td>{{ $dateValue($invoice->invoice_date) }}</td><td>{{ $invoice->order?->doc_num ?: '—' }}</td><td>{{ $invoice->deliveries->pluck('doc_num')->join(' / ') ?: '—' }}</td><td>{{ $numbers->format(bcsub((string) $invoice->total_amount, (string) ($invoice->returns_amount ?? 0), 4)) }}</td><td>{{ $numbers->format($invoice->paid_amount) }}</td><td>{{ $numbers->format($invoice->remaining_amount) }}</td></tr>@endforeach</tbody></table>
@endif

@if(in_array($reportType, ['receivables', 'financial'], true) && $invoiceOutstanding->isNotEmpty())
<h3>{{ __('Invoice Outstanding') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($invoiceOutstanding as $invoice)<tr><td>{{ $invoice->doc_num }}</td><td>{{ $invoice->customer?->name }}</td><td>{{ $dateValue($invoice->due_date) }}</td><td>{{ $numbers->format($invoice->remaining_amount) }}</td></tr>@endforeach</tbody></table>
@endif
@if(in_array($reportType, ['receivables', 'financial'], true) && $aging->isNotEmpty())
<h3>{{ __('Customer Aging') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Current') }}</th><th>1–30</th><th>31–60</th><th>61–90</th><th>90+</th></tr></thead><tbody>@foreach($aging as $row)<tr><td>{{ $row['customer'] }}</td><td>{{ $numbers->format($row['current']) }}</td><td>{{ $numbers->format($row['1_30']) }}</td><td>{{ $numbers->format($row['31_60']) }}</td><td>{{ $numbers->format($row['61_90']) }}</td><td>{{ $numbers->format($row['over_90']) }}</td></tr>@endforeach</tbody></table>
@endif

@if($reportType === 'collections' && $customerReceipts->isNotEmpty())
<h3>{{ __('sales_ui.recorded_collections') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Customer') }}</th><th>{{ __('sales_ui.received_by_employee') }}</th><th>{{ __('Payment method') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($customerReceipts as $receipt)<tr><td>{{ $receipt->doc_num }}</td><td>{{ $dateValue($receipt->receipt_date) }}</td><td>{{ $receipt->customer?->name }}</td><td>{{ $receipt->receivedByEmployee?->full_name ?: $receipt->receivedByEmployee?->name ?: __('sales_ui.legacy_receiver_unresolved') }}</td><td>{{ __(str($receipt->payment_method)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $receipt->reference_no ?: $receipt->cashVoucher?->doc_num ?: $receipt->cheque?->doc_num ?: '—' }}</td><td>{{ $numbers->format($receipt->amount) }} {{ $receipt->currency?->code }}</td><td>{{ __(str($receipt->status)->replace('_', ' ')->title()->toString()) }}</td></tr>@endforeach</tbody></table>
@endif
@if($reportType === 'collections' && $upcomingCollections->isNotEmpty())
<h3>{{ __('sales_ui.upcoming_collections') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($upcomingCollections as $row)<tr><td>{{ $row->doc_num }}</td><td>{{ $row->name }}</td><td>{{ $dateValue($row->due_date) }}</td><td>{{ $numbers->format($row->outstanding) }}</td></tr>@endforeach</tbody></table>
@endif

@if(in_array($reportType, ['quotations', 'operational'], true) && $quotations->isNotEmpty())
<h3>{{ __('Quotation Status / History') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Quotation') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Valid until') }}</th><th>{{ __('Status') }}</th><th>{{ __('Revision') }}</th><th>{{ __('Total') }}</th></tr></thead><tbody>@foreach($quotations as $quotation)<tr><td>{{ $quotation->doc_num }}</td><td>{{ $quotation->customer?->name }}</td><td>{{ $dateValue($quotation->quotation_date) }}</td><td>{{ $dateValue($quotation->valid_until) }}</td><td>{{ __('quotations.statuses.'.$quotation->status) }}</td><td>{{ $quotation->currentRevision?->revision_code }}</td><td>{{ $numbers->format($quotation->currentRevision?->total) }}</td></tr>@endforeach</tbody></table>
@endif

@if(in_array($reportType, ['fulfillment', 'operational'], true) && $openOrders->isNotEmpty())
<h3>{{ __('Invoice to Delivery Fulfillment') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Order') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Required date') }}</th><th>{{ __('Ordered') }}</th><th>{{ __('Invoiced') }}</th><th>{{ __('Delivered') }}</th><th>{{ __('Remaining Delivery') }}</th></tr></thead><tbody>@foreach($openOrders as $order)<tr><td>{{ $order->doc_num }}</td><td>{{ $order->customer?->name }}</td><td>{{ $dateValue($order->expected_delivery_date) }}</td><td>{{ $numbers->format($order->ordered_quantity) }}</td><td>{{ $numbers->format($order->lines->sum('invoiced_quantity')) }}</td><td>{{ $numbers->format($order->delivered_quantity) }}</td><td>{{ $numbers->format(max(0, (float) $order->lines->sum('invoiced_quantity') - (float) $order->delivered_quantity)) }}</td></tr>@endforeach</tbody></table>
@endif

@if($reportType === 'returns' && $returns->isNotEmpty())
<h3>{{ __('Returns by Reason and Quality Disposition') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Reason') }}</th><th>{{ __('Returns') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Saleable') }}</th><th>{{ __('Rejected / Rework / Scrap') }}</th></tr></thead><tbody>@foreach($returns as $row)<tr><td>{{ __(str($row->reason_code)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $row->return_count }}</td><td>{{ $numbers->format($row->returned_quantity) }}</td><td>{{ $numbers->format($row->saleable_quantity) }}</td><td>{{ $numbers->format($row->rejected_quantity) }}</td></tr>@endforeach</tbody></table>
@endif
@if($reportType === 'returns' && $returnAnalysis->isNotEmpty())
<h3>{{ __('Customer / Item Return Analysis') }}</h3>
<table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Item') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Disposition') }}</th><th>{{ __('Quantity') }}</th></tr></thead><tbody>@foreach($returnAnalysis as $row)<tr><td>{{ $row->customer_name }}</td><td>{{ $row->product_name }}</td><td>{{ __(str($row->reason_code)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $row->quality_disposition ? $qualityDispositionLabel($row->quality_disposition) : '—' }}</td><td>{{ $numbers->format($row->returned_quantity) }}</td></tr>@endforeach</tbody></table>
@endif

@if(!in_array($reportType, ['financial', 'operational'], true)
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
