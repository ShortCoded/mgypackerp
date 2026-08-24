@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    <div class="report-filter-summary">{{ $from?->toDateString() }} — {{ $to?->toDateString() }}</div>
    <h3>{{ __('Quotation Status / History') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('Quotation') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th><th>{{ __('Total') }}</th></tr></thead><tbody>@foreach($quotations as $quotation)<tr><td>{{ $quotation->doc_num }}</td><td>{{ $quotation->customer?->name }}</td><td>{{ $quotation->quotation_date?->toDateString() }}</td><td>{{ $quotation->status }}</td><td>{{ $numbers->format($quotation->currentRevision?->total) }}</td></tr>@endforeach</tbody></table>
    <h3>{{ __('Ordered vs Reserved vs Produced vs Delivered') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('Order') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Status') }}</th><th>{{ __('Ordered') }}</th><th>{{ __('Reserved') }}</th><th>{{ __('Produced') }}</th><th>{{ __('Delivered') }}</th></tr></thead><tbody>@foreach($openOrders as $order)<tr><td>{{ $order->doc_num }}</td><td>{{ $order->customer?->name }}</td><td>{{ $order->status }}</td><td>{{ $numbers->format($order->ordered_quantity) }}</td><td>{{ $numbers->format($order->reserved_quantity) }}</td><td>{{ $numbers->format($order->produced_quantity) }}</td><td>{{ $numbers->format($order->delivered_quantity) }}</td></tr>@endforeach</tbody></table>
    <h3>{{ __('Sales / Outstanding by Customer') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Sales') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($salesByCustomer as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td>{{ $numbers->format($row->sales_value) }}</td><td>{{ $numbers->format($row->outstanding) }}</td></tr>@endforeach</tbody></table>
    <h3>{{ __('Sales by Item') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Value') }}</th></tr></thead><tbody>@foreach($salesByItem as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td>{{ $numbers->format($row->sold_quantity) }}</td><td>{{ $numbers->format($row->sales_value) }}</td></tr>@endforeach</tbody></table>
    <h3>{{ __('Due / Overdue Installments') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($installments as $row)<tr><td>{{ $row->doc_num }}</td><td>{{ $row->name }}</td><td>{{ $row->due_date }}</td><td>{{ $numbers->format($row->outstanding) }}</td></tr>@endforeach</tbody></table>
    <h3>{{ __('Returns by Reason and Quality Disposition') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('Reason') }}</th><th>{{ __('Returns') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Saleable') }}</th><th>{{ __('Rejected / Rework / Scrap') }}</th></tr></thead><tbody>@foreach($returns as $row)<tr><td>{{ str($row->reason_code)->replace('_', ' ')->title() }}</td><td>{{ $row->return_count }}</td><td>{{ $numbers->format($row->returned_quantity) }}</td><td>{{ $numbers->format($row->saleable_quantity) }}</td><td>{{ $numbers->format($row->rejected_quantity) }}</td></tr>@endforeach</tbody></table>
    <style>h3 { margin:10px 0 4px; font-size:10px; }.report-table { margin-bottom:8px; }.report-table th,.report-table td { font-size:7px; }.report-table thead { display:table-header-group; }.report-table tr { page-break-inside:avoid; }</style>
@endsection
