@extends('reports.layouts.pdf')
@section('report')
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($showRequestPrices = $showPrices && $record->lines->contains(fn ($line) => $line->unit_price !== null))
@include('reports.partials.company-identity')
<table class="report-table" style="margin-bottom:9px;"><tbody>
    <tr><th>{{ __('Document number') }}</th><td dir="ltr">{{ $record->doc_num }}</td><th>{{ __('Date') }}</th><td>{{ $dates->formatDate($record->request_date, '') }}</td></tr>
    <tr><th>{{ __('Customer') }}</th><td>{{ $record->customer?->name ?? __('Internal request') }}</td>@if($record->required_delivery_date)<th>{{ __('Required date') }}</th><td>{{ $dates->formatDate($record->required_delivery_date, '') }}</td>@else<td colspan="2"></td>@endif</tr>
</tbody></table>
<table class="report-table"><thead><tr><th>#</th><th>{{ __('Item / Description') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th>@if($showRequestPrices)<th>{{ __('Unit price') }}</th><th>{{ __('Total') }}</th>@endif</tr></thead><tbody>@foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>@include('reports.partials.item-details', ['line' => $line, 'showPacking' => false, 'showClassification' => false])</td><td>{{ $line->unit?->name }}</td><td>{{ $numbers->format($line->quantity) }}</td>@if($showRequestPrices)<td>{{ $line->unit_price === null ? '—' : $numbers->format($line->unit_price) }}</td><td>{{ $line->unit_price === null ? '—' : $numbers->format(bcmul($line->quantity, $line->unit_price, 4)) }}</td>@endif</tr>@endforeach</tbody></table>
@if(filled($record->notes))<p>{{ $record->notes }}</p>@endif
@include('reports.partials.document-signatures', ['labels' => [__('Prepared by'), __('Approved by'), __('Received by')]])
@endsection
