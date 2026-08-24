@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    <h2>{{ $reportTitle }}</h2>
    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('Production Order') }}</th><td dir="ltr">{{ $record->order?->doc_num }}</td><th>{{ __('Sales Order') }}</th><td dir="ltr">{{ $record->order?->salesOrder?->doc_num ?: '—' }}</td></tr>
        <tr><th>{{ __('Customer') }}</th><td>{{ $record->order?->salesOrder?->customer?->name ?: '—' }}</td><th>{{ __('Product') }}</th><td>{{ $record->product?->doc_num }} / {{ $record->product?->name }}</td></tr>
        <tr><th>{{ __('Machine') }}</th><td>{{ $record->machine?->code }} / {{ $record->machine?->name }}</td><th>{{ __('Mold') }}</th><td>{{ $record->mold?->code }} / {{ $record->mold?->name }}</td></tr>
        <tr><th>{{ __('Shift') }}</th><td>{{ $record->shift?->name ?: '—' }}</td><th>{{ __('Batch / lot') }}</th><td dir="ltr">{{ $record->batch_lot ?: '—' }}</td></tr>
        <tr><th>{{ __('Planned start') }}</th><td>{{ $dates->formatDateTime($record->planned_start_at, '') }}</td><th>{{ __('Planned end') }}</th><td>{{ $dates->formatDateTime($record->planned_end_at, '') }}</td></tr>
        <tr><th>{{ __('Actual start') }}</th><td>{{ $dates->formatDateTime($record->actual_start_at, '—') }}</td><th>{{ __('Actual end') }}</th><td>{{ $dates->formatDateTime($record->actual_end_at, '—') }}</td></tr>
        <tr><th>{{ __('Target') }}</th><td dir="ltr">{{ $numbers->format($record->planned_base_quantity) }}</td><th>{{ __('Status') }}</th><td>{{ str($record->status)->replace('_', ' ')->title() }}</td></tr>
    </tbody></table>

    <h3>{{ __('Material Requirement and Accountability') }}</h3>
    <table class="report-table run-lines"><thead><tr><th>#</th><th>{{ __('Material') }}</th><th>{{ __('Planned') }}</th><th>{{ __('Reserved') }}</th><th>{{ __('Issued') }}</th><th>{{ __('Additional') }}</th><th>{{ __('Returned') }}</th><th>{{ __('Consumed') }}</th><th>{{ __('Waste') }}</th></tr></thead><tbody>
        @foreach($record->requirements as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td>{{ $numbers->format($line->planned_quantity) }}</td><td>{{ $numbers->format($line->reserved_quantity) }}</td><td>{{ $numbers->format($line->issued_quantity) }}</td><td>{{ $numbers->format($line->additional_issued_quantity) }}</td><td>{{ $numbers->format($line->returned_quantity) }}</td><td>{{ $numbers->format($line->consumed_quantity) }}</td><td>{{ $numbers->format($line->waste_quantity) }}</td></tr>@endforeach
    </tbody></table>

    <h3>{{ __('Progress History') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('Recorded at') }}</th><th>{{ __('Good') }}</th><th>{{ __('Rejected') }}</th><th>{{ __('Rework') }}</th><th>{{ __('Scrap') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>
        @forelse($record->progressEntries as $entry)<tr><td>{{ $dates->formatDateTime($entry->recorded_at, '') }}</td><td>{{ $numbers->format($entry->good_base_quantity) }}</td><td>{{ $numbers->format($entry->rejected_base_quantity) }}</td><td>{{ $numbers->format($entry->rework_base_quantity) }}</td><td>{{ $numbers->format($entry->scrap_base_quantity) }}</td><td>{{ $entry->notes }}</td></tr>@empty<tr><td colspan="6">{{ __('No progress entries.') }}</td></tr>@endforelse
    </tbody></table>

    <h3>{{ __('In-Process Quality') }}</h3>
    <table class="report-table"><thead><tr><th>{{ __('Inspection') }}</th><th>{{ __('Sampled at') }}</th><th>{{ __('Result') }}</th><th>{{ __('Defect') }}</th><th>{{ __('Affected quantity') }}</th><th>{{ __('Corrective action / notes') }}</th></tr></thead><tbody>
        @forelse($record->inspections as $inspection)<tr><td dir="ltr">{{ $inspection->doc_num }}</td><td>{{ $dates->formatDateTime($inspection->sampled_at, '') }}</td><td>{{ str($inspection->result)->title() }}</td><td dir="ltr">{{ $inspection->defect_code }}</td><td>{{ $numbers->format($inspection->affected_base_quantity) }}</td><td>{{ $inspection->corrective_action }} {{ $inspection->notes }}</td></tr>@empty<tr><td colspan="6">{{ __('No quality inspections.') }}</td></tr>@endforelse
    </tbody></table>

    <h3>{{ __('Output Accountability') }}</h3>
    <table class="report-table"><tbody><tr><th>{{ __('Good') }}</th><td>{{ $numbers->format($record->good_base_quantity) }}</td><th>{{ __('Rejected') }}</th><td>{{ $numbers->format($record->rejected_base_quantity) }}</td><th>{{ __('Rework') }}</th><td>{{ $numbers->format($record->rework_base_quantity) }}</td><th>{{ __('Scrap') }}</th><td>{{ $numbers->format($record->scrap_base_quantity) }}</td><th>{{ __('Received FG') }}</th><td>{{ $numbers->format($record->received_base_quantity) }}</td></tr></tbody></table>
    @if($record->notes)<div style="margin-top:8px;"><strong>{{ __('Notes') }}:</strong> {{ $record->notes }}</div>@endif
    <table style="width:100%; margin-top:18px; page-break-inside:avoid;"><tr><td style="border:0; text-align:center;">{{ __('Production supervisor') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Quality') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Warehouse') }}: __________________</td></tr></table>
    <style>.run-lines thead{display:table-header-group}.run-lines tr{page-break-inside:avoid}.run-lines th,.run-lines td{font-size:6.4px;overflow-wrap:break-word}h3{margin:10px 0 4px}</style>
@endsection
