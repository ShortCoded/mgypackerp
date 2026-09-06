@extends('reports.layouts.pdf')
@section('report')
@include('reports.partials.company-identity')
<h2>{{ __('Sales Request') }} — {{ $record->doc_num }}</h2>
<p>{{ $record->request_date->toDateString() }} · {{ $record->customer?->name ?? __('Internal request') }} · {{ $record->branchStore?->name }}</p>
<p>{{ __('Required date') }}: {{ $record->required_delivery_date?->toDateString() }} · {{ __('Reference') }}: {{ $record->customer_reference }}</p>
<table class="report-table"><thead><tr><th>#</th><th>{{ __('Item / Description') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Packaging') }}</th><th>{{ __('Quantity') }}</th>@if($showPrices)<th>{{ __('Unit price') }}</th><th>{{ __('Total') }}</th>@endif</tr></thead><tbody>@foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>@include('reports.partials.item-details', ['line' => $line])</td><td>{{ $line->unit?->name }}</td><td>{{ $line->specifications['packaging'] ?? '' }}</td><td>{{ app(\Modules\Core\Services\NumericFormatService::class)->format($line->quantity) }}</td>@if($showPrices)<td>{{ $line->unit_price }}</td><td>{{ $line->unit_price === null ? '' : bcmul($line->quantity, $line->unit_price, 4) }}</td>@endif</tr>@endforeach</tbody></table>
<p>{{ $record->notes }}</p>
@include('reports.partials.document-signatures', ['labels' => [__('Prepared by'), __('Approved by'), __('Received by')]])
@endsection
