@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    <h2>{{ __('inventory.opening_stocks.title') }} — <span dir="ltr">{{ $record->doc_num }}</span></h2>
    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('Date') }}</th><td>{{ $dates->formatDate($record->document_date, '') }}</td><th>{{ __('Status') }}</th><td>{{ __(str($record->status)->replace('_', ' ')->title()->toString()) }}</td></tr>
        <tr><th>{{ __('Branch') }}</th><td>{{ $record->branch?->name }}</td><th>{{ __('Store') }}</th><td>{{ $record->branchStore?->name }}</td></tr>
        <tr><th>{{ __('Hall') }}</th><td>{{ $record->branchHall?->name ?: '—' }}</td><th>{{ __('Approved by') }}</th><td>{{ $record->approvedBy?->name ?: '—' }}</td></tr>
    </tbody></table>
    <table class="report-table opening-lines"><thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Status') }}</th><th>{{ __('Batch') }}</th><th>{{ __('Location') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>
        @foreach($record->lines as $line)<tr><td>{{ $line->line_no }}</td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td dir="ltr">{{ $numbers->format($line->quantity) }}</td><td>{{ __(str($line->stock_status ?: 'available')->replace('_', ' ')->title()->toString()) }}</td><td dir="ltr">{{ $line->batch_lot ?: '—' }}</td><td>{{ $line->warehouseLocation?->code ?: '—' }}</td><td>{{ $line->notes }}</td></tr>@endforeach
    </tbody></table>
    @include('reports.partials.company-authorization')
    <style>.opening-lines thead{display:table-header-group}.opening-lines tr{page-break-inside:avoid}.opening-lines th,.opening-lines td{font-size:7px}</style>
@endsection
