@extends('reports.layouts.pdf')

@section('report')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $dates = app(\Modules\Core\Services\DateFormatService::class);
    @endphp
    <table style="width:100%; margin-bottom:9px;"><tr>
        <td style="border:0;"><h2 style="margin:0;">{{ str($record->document_type)->replace('_', ' ')->title() }}</h2><strong dir="ltr">{{ $record->doc_num }}</strong></td>
        <td style="border:0; text-align:{{ $direction === 'rtl' ? 'left' : 'right' }};">{{ $dates->formatDate($record->document_date, '') }}<br>{{ str($record->status)->title() }}</td>
    </tr></table>

    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('Source store') }}</th><td>{{ $record->branchStore?->name }}</td><th>{{ __('Destination store') }}</th><td>{{ $record->destinationBranchStore?->name ?: '—' }}</td></tr>
        <tr><th>{{ __('Source status') }}</th><td>{{ str($record->source_stock_status)->replace('_', ' ')->title() }}</td><th>{{ __('Destination status') }}</th><td>{{ str($record->destination_stock_status)->replace('_', ' ')->title() ?: '—' }}</td></tr>
        <tr><th>{{ __('Source document') }}</th><td dir="ltr">{{ $record->source_doc_num ?: '—' }}</td><th>{{ __('Production') }}</th><td dir="ltr">{{ $record->productionOrder?->doc_num }} {{ $record->productionRun?->run_number }}</td></tr>
        <tr><th>{{ __('Reason') }}</th><td colspan="3">{{ $record->movement_reason ?: $record->purpose ?: '—' }}</td></tr>
    </tbody></table>

    <table class="report-table inventory-document-lines">
        <thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Batch / lot') }}</th><th>{{ __('Location') }}</th><th>{{ __('Source line') }}</th></tr></thead>
        <tbody>@foreach($record->lines as $line)<tr>
            <td>{{ $line->line_number }}</td>
            <td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td>
            <td>{{ $line->unit?->name }}</td>
            <td dir="ltr">{{ $numbers->format($line->transaction_quantity ?: $line->quantity) }}</td>
            <td dir="ltr">{{ $line->batch_lot ?: '—' }}</td>
            <td>{{ $line->warehouseLocation?->code ?: '—' }}</td>
            <td dir="ltr">{{ $line->source_line_public_id ?: $line->source_line_id ?: '—' }}</td>
        </tr>@endforeach</tbody>
    </table>

    @if($record->notes)<div style="margin-top:8px;"><strong>{{ __('Notes') }}:</strong> {{ $record->notes }}</div>@endif
    <table style="width:100%; margin-top:20px; page-break-inside:avoid;"><tr><td style="border:0; text-align:center;">{{ __('Prepared by') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Received by') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Approved by') }}: __________________</td></tr></table>
    <style>.inventory-document-lines thead{display:table-header-group}.inventory-document-lines tr{page-break-inside:avoid}.inventory-document-lines th,.inventory-document-lines td{font-size:7.2px;overflow-wrap:break-word}</style>
@endsection
