@extends('reports.layouts.pdf')
@section('report')
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@include('reports.partials.company-identity')
<h2>{{ __('Sales Request') }} — {{ $record->doc_num }}</h2>
<p>{{ $dates->formatDate($record->request_date, '') }} · {{ $record->customer?->name ?? __('Internal request') }} @if($record->branchStore)· {{ $record->branchStore->name }}@endif</p>
@if($record->required_delivery_date)<p>{{ __('Required date') }}: {{ $dates->formatDate($record->required_delivery_date, '') }}</p>@endif
<table class="report-table"><thead><tr><th>#</th><th>{{ __('Item / Description') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th>@if($showPrices)<th>{{ __('Unit price') }}</th><th>{{ __('Total') }}</th>@endif</tr></thead><tbody>@foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>@include('reports.partials.item-details', ['line' => $line, 'showPacking' => false])</td><td>{{ $line->unit?->name }}</td><td>{{ $numbers->format($line->quantity) }}</td>@if($showPrices)<td>{{ $numbers->format($line->unit_price) }}</td><td>{{ $line->unit_price === null ? '' : $numbers->format(bcmul($line->quantity, $line->unit_price, 4)) }}</td>@endif</tr>@endforeach</tbody></table>
@if(filled($record->notes))<p>{{ $record->notes }}</p>@endif
@include('reports.partials.document-signatures', ['labels' => [__('Prepared by'), __('Approved by'), __('Received by')]])
@endsection
