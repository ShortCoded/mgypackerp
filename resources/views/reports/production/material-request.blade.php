@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.partials.company-identity')
    <table class="report-table" style="margin-bottom:9px"><tbody>
        <tr><th>{{ __('production_execution.fields.document') }}</th><td dir="ltr">{{ $record->doc_num }}</td><th>{{ __('production_execution.fields.date') }}</th><td>{{ $dates->formatDate($record->request_date, '—') }}</td></tr>
        <tr><th>{{ __('production_execution.fields.production_order') }}</th><td dir="ltr">{{ $record->order?->doc_num }}</td><th>{{ __('production_execution.fields.run') }}</th><td dir="ltr">{{ $record->run?->run_number }}</td></tr>
        <tr><th>{{ __('production_execution.fields.stage') }}</th><td>{{ $record->run?->stageSnapshot?->stage_name ?: '—' }}</td><th>{{ __('production_execution.fields.store') }}</th><td>{{ $record->store?->name }}</td></tr>
        <tr><th>{{ __('production_execution.fields.request_type') }}</th><td>{{ __('production_execution.material_request_types.'.$record->request_type) }}</td><th>{{ __('production_execution.fields.required_by') }}</th><td>{{ $dates->formatDate($record->required_by_date, '—') }}</td></tr>
        <tr><th>{{ __('production_execution.fields.status') }}</th><td>{{ __('production_execution.statuses.'.$record->status) }}</td><th>{{ __('production_execution.fields.purchase_request') }}</th><td dir="ltr">{{ $record->purchaseRequisition?->doc_num ?: '—' }}</td></tr>
    </tbody></table>
    <table class="report-table"><thead><tr><th>#</th><th>{{ __('production_execution.fields.product') }}</th><th>{{ __('production_execution.fields.unit') }}</th><th>{{ __('production_execution.reports.columns.planned') }}</th><th>{{ __('production_execution.print.requested') }}</th><th>{{ __('production_execution.print.approved') }}</th><th>{{ __('production_execution.reports.columns.reserved') }}</th><th>{{ __('production_execution.reports.columns.issued') }}</th><th>{{ __('production_execution.print.shortage') }}</th></tr></thead><tbody>
        @foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $line->unit?->name ?: '—' }}</td><td dir="ltr">{{ $numbers->format($line->planned_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->requested_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->approved_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->reserved_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->issued_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->shortage_quantity) }}</td></tr>@endforeach
    </tbody></table>
    @if($record->reason)<p><strong>{{ __('production_execution.fields.reason') }}:</strong> {{ $record->reason }}</p>@endif
    @if($record->notes)<p><strong>{{ __('production_execution.fields.notes') }}:</strong> {{ $record->notes }}</p>@endif
    @include('reports.production.partials.signatures', ['areas' => ['production_supervisor', 'warehouse']])
@endsection
