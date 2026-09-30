@extends('reports.layouts.pdf')

@section('report')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $dates = app(\Modules\Core\Services\DateFormatService::class);
    @endphp
    @include('reports.partials.company-identity')
    @php
        $showLot = $record->lines->contains(fn ($line) => filled($line->batch_lot));
        $showLineSource = $record->lines->contains(fn ($line) => filled($line->source_line_public_id));
        $showCost = $record->lines->contains(fn ($line) => $line->unit_cost !== null);
        $costCorrection = data_get($record->lines->first()?->product_snapshot, 'cost_correction');
    @endphp
    <table style="width:100%; margin-bottom:9px;"><tr>
        <td style="border:0;"><h2 style="margin:0;">{{ app(\Modules\Core\Services\Reports\ReportPdfService::class)->stockDocumentTitle($record) }}</h2><strong dir="ltr">{{ $record->doc_num }}</strong></td>
        <td style="border:0; text-align:{{ $direction === 'rtl' ? 'left' : 'right' }};">{{ $dates->formatDate($record->document_date, '') }}<br>{{ __('inventory.movements.statuses.'.$record->status) }}</td>
    </tr></table>

    <table dir="{{ $direction ?? 'ltr' }}" class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('Source store') }}</th><td>{{ $record->branchStore?->name }}</td><th>{{ __('Destination store') }}</th><td>{{ $record->destinationBranchStore?->name ?: '—' }}</td></tr>
        <tr><th>{{ __('Source status') }}</th><td>{{ $record->source_stock_status ? __('inventory.movements.stock_statuses.'.$record->source_stock_status) : '—' }}</td><th>{{ __('Destination status') }}</th><td>{{ $record->destination_stock_status ? __('inventory.movements.stock_statuses.'.$record->destination_stock_status) : '—' }}</td></tr>
        <tr><th>{{ __('Source document') }}</th><td dir="ltr">{{ $record->source_doc_num ?: '—' }}</td><th>{{ __('Production') }}</th><td dir="ltr">{{ $record->productionOrder?->doc_num }} {{ $record->productionRun?->run_number }}</td></tr>
        @if($record->productionMaterialRequest)
            <tr><th>{{ __('inventory.movements.production_material_request') }}</th><td dir="ltr" colspan="3">{{ $record->productionMaterialRequest->doc_num }}</td></tr>
        @endif
        <tr><th>{{ __('Reason') }}</th><td colspan="3">{{ $record->movement_reason ?: $record->purpose ?: '—' }}</td></tr>
    </tbody></table>

    <table dir="{{ $direction ?? 'ltr' }}" class="report-table inventory-document-lines">
        <thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th>@if($showCost)<th>{{ __('inventory.movements.fields.unit_cost') }}</th><th>{{ __('inventory.movements.receipt_pricing_total') }}</th>@endif @if($showLot)<th>{{ __('Batch / lot') }}</th>@endif @if($showLineSource)<th>{{ __('Source line') }}</th>@endif</tr></thead>
        <tbody>@foreach($record->lines as $line)<tr>
            <td>{{ $line->line_number }}</td>
            <td>@include('reports.partials.item-details', ['line' => $line])</td>
            <td>{{ $line->unit?->name }}</td>
            <td dir="ltr">{{ $numbers->format($line->transaction_quantity ?: $line->quantity) }}</td>
            @if($showCost)<td dir="ltr">{{ $line->unit_cost === null ? '—' : $numbers->format($line->unit_cost, 8) }}</td><td dir="ltr">{{ $line->total_cost === null ? '—' : $numbers->format($line->total_cost) }}</td>@endif
            @if($showLot)<td dir="ltr">{{ $line->batch_lot }}</td>@endif
            @if($showLineSource)<td dir="ltr">{{ $line->source_line_public_id }}</td>@endif
        </tr>@endforeach</tbody>
    </table>

    @if(is_array($costCorrection))
        <div style="margin-top:8px;"><strong>{{ __('inventory.movements.receipt_pricing_reference') }}:</strong> {{ $costCorrection['source_reference'] ?? '—' }}</div>
        @if(($costCorrection['basis'] ?? null) === 'local_provisional')
            <div style="margin-top:4px; color:#9a6700;">{{ __('inventory.movements.receipt_pricing_provisional_notice') }}</div>
        @endif
    @endif

    @if($record->notes)<div style="margin-top:8px;"><strong>{{ __('Notes') }}:</strong> {{ $record->notes }}</div>@endif
    @include('reports.partials.document-signatures', ['signatureType' => 'inventory'])
    @include('reports.partials.company-authorization')
@endsection
