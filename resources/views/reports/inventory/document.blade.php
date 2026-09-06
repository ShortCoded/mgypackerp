@extends('reports.layouts.pdf')

@section('report')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $dates = app(\Modules\Core\Services\DateFormatService::class);
    @endphp
    @include('reports.partials.company-identity')
    @php
        $showLot = $record->lines->contains(fn ($line) => filled($line->batch_lot));
        $showLocation = $record->lines->contains(fn ($line) => filled($line->warehouse_location_id));
        $showLineSource = $record->lines->contains(fn ($line) => filled($line->source_line_public_id));
    @endphp
    <table style="width:100%; margin-bottom:9px;"><tr>
        <td style="border:0;"><h2 style="margin:0;">{{ app(\Modules\Core\Services\Reports\ReportPdfService::class)->stockDocumentTitle($record) }}</h2><strong dir="ltr">{{ $record->doc_num }}</strong></td>
        <td style="border:0; text-align:{{ $direction === 'rtl' ? 'left' : 'right' }};">{{ $dates->formatDate($record->document_date, '') }}<br>{{ __(str($record->status)->replace('_', ' ')->title()->toString()) }}</td>
    </tr></table>

    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('Source store') }}</th><td>{{ $record->branchStore?->name }}</td><th>{{ __('Destination store') }}</th><td>{{ $record->destinationBranchStore?->name ?: '—' }}</td></tr>
        <tr><th>{{ __('Source status') }}</th><td>{{ __(str($record->source_stock_status)->replace('_', ' ')->title()->toString()) }}</td><th>{{ __('Destination status') }}</th><td>{{ __(str($record->destination_stock_status)->replace('_', ' ')->title()->toString()) ?: '—' }}</td></tr>
        <tr><th>{{ __('Source document') }}</th><td dir="ltr">{{ $record->source_doc_num ?: '—' }}</td><th>{{ __('Production') }}</th><td dir="ltr">{{ $record->productionOrder?->doc_num }} {{ $record->productionRun?->run_number }}</td></tr>
        <tr><th>{{ __('Reason') }}</th><td colspan="3">{{ $record->movement_reason ?: $record->purpose ?: '—' }}</td></tr>
    </tbody></table>

    <table class="report-table inventory-document-lines">
        <thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th>@if($showLot)<th>{{ __('Batch / lot') }}</th>@endif @if($showLocation)<th>{{ __('Location') }}</th>@endif @if($showLineSource)<th>{{ __('Source line') }}</th>@endif</tr></thead>
        <tbody>@foreach($record->lines as $line)<tr>
            <td>{{ $line->line_number }}</td>
            <td>@include('reports.partials.item-details', ['line' => $line])</td>
            <td>{{ $line->unit?->name }}</td>
            <td dir="ltr">{{ $numbers->format($line->transaction_quantity ?: $line->quantity) }}</td>
            @if($showLot)<td dir="ltr">{{ $line->batch_lot }}</td>@endif
            @if($showLocation)<td>{{ $line->warehouseLocation?->code }}</td>@endif
            @if($showLineSource)<td dir="ltr">{{ $line->source_line_public_id }}</td>@endif
        </tr>@endforeach</tbody>
    </table>

    @if($record->notes)<div style="margin-top:8px;"><strong>{{ __('Notes') }}:</strong> {{ $record->notes }}</div>@endif
    @include('reports.partials.document-signatures', ['signatureType' => 'inventory'])
    @include('reports.partials.company-authorization')
@endsection
