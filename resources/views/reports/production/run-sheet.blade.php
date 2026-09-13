@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    <h2>{{ $reportTitle }}</h2>
    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('Production Order') }}</th><td dir="ltr">{{ $record->order?->doc_num }}</td><th>{{ __('Sales Order') }}</th><td dir="ltr">{{ $record->order?->salesOrder?->doc_num ?: '—' }}</td></tr>
        <tr><th>{{ __('production_execution.fields.stage') }}</th><td>{{ $record->stageSnapshot?->stage_name ?: '—' }}</td><th>{{ __('Product') }}</th><td>{{ $record->product?->doc_num }} / {{ $record->product?->name }}</td></tr>
        <tr><th>{{ __('production_execution.fields.fixed_asset') }}</th><td>{{ $record->fixedAsset?->doc_num }} / {{ $record->fixedAsset?->asset_name }}</td><th>{{ __('production_execution.fields.planned_labor_count') }}</th><td>{{ $record->planned_labor_count ?? '—' }}</td></tr>
        <tr><th>{{ __('Batch / lot') }}</th><td dir="ltr">{{ $record->batch_lot ?: '—' }}</td><th>{{ __('production_execution.fields.work_description') }}</th><td>{{ $record->work_description ?: '—' }}</td></tr>
        <tr><th>{{ __('Planned start') }}</th><td>{{ $dates->formatDateTime($record->planned_start_at, '') }}</td><th>{{ __('Planned end') }}</th><td>{{ $dates->formatDateTime($record->planned_end_at, '') }}</td></tr>
        <tr><th>{{ __('Actual start') }}</th><td>{{ $dates->formatDateTime($record->actual_start_at, '—') }}</td><th>{{ __('Actual end') }}</th><td>{{ $dates->formatDateTime($record->actual_end_at, '—') }}</td></tr>
        <tr><th>{{ __('production_execution.fields.actual_duration') }}</th><td>{{ $record->actualDurationHours() !== null ? __('production_execution.labor.hours_value', ['hours' => $record->actualDurationHours()]) : '—' }}</td><th>{{ __('production_execution.fields.total_labor_hours') }}</th><td>{{ $record->totalLaborHours() }}</td></tr>
        <tr><th>{{ __('Target') }}</th><td dir="ltr">{{ $numbers->format($record->planned_base_quantity) }}</td><th>{{ __('Status') }}</th><td>{{ __('production_execution.statuses.'.$record->status) }}</td></tr>
    </tbody></table>

    <h3>{{ __('production_execution.labor.actual_details') }}</h3>
    <table class="report-table"><thead><tr><th>#</th><th>{{ __('production_execution.fields.worker_name') }}</th><th>{{ __('production_execution.fields.worker_role') }}</th><th>{{ __('production_execution.fields.planned_hours') }}</th><th>{{ __('production_execution.fields.actual_hours') }}</th><th>{{ __('production_execution.fields.notes') }}</th></tr></thead><tbody>
        @forelse(collect($record->labor_details ?? []) as $index => $labor)<tr><td>{{ $index + 1 }}</td><td>{{ $labor['name'] ?? '—' }}</td><td>{{ $labor['role'] ?? '—' }}</td><td>{{ $labor['planned_hours'] ?? '—' }}</td><td>{{ $labor['actual_hours'] ?? '—' }}</td><td>{{ $labor['notes'] ?? '—' }}</td></tr>@empty<tr><td colspan="6">{{ __('production_execution.labor.no_details') }}</td></tr>@endforelse
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
        @forelse($record->inspections as $inspection)<tr><td dir="ltr">{{ $inspection->doc_num }}</td><td>{{ $dates->formatDateTime($inspection->sampled_at, '') }}</td><td>{{ __('production_execution.quality_results.'.$inspection->result) }}</td><td dir="ltr">{{ $inspection->defect_code }}</td><td>{{ $numbers->format($inspection->affected_base_quantity) }}</td><td>{{ $inspection->corrective_action }} {{ $inspection->notes }}</td></tr>@empty<tr><td colspan="6">{{ __('No quality inspections.') }}</td></tr>@endforelse
    </tbody></table>

    <h3>{{ __('Output Accountability') }}</h3>
    <table class="report-table"><tbody><tr><th>{{ __('Good') }}</th><td>{{ $numbers->format($record->good_base_quantity) }}</td><th>{{ __('Rejected') }}</th><td>{{ $numbers->format($record->rejected_base_quantity) }}</td><th>{{ __('Rework') }}</th><td>{{ $numbers->format($record->rework_base_quantity) }}</td><th>{{ __('Scrap') }}</th><td>{{ $numbers->format($record->scrap_base_quantity) }}</td><th>{{ __('Received FG') }}</th><td>{{ $numbers->format($record->received_base_quantity) }}</td></tr></tbody></table>
    @if($record->notes)<div style="margin-top:8px;"><strong>{{ __('Notes') }}:</strong> {{ $record->notes }}</div>@endif
    <table style="width:100%; margin-top:18px; page-break-inside:avoid;"><tr><td style="border:0; text-align:center;">{{ __('Production supervisor') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Quality') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Warehouse') }}: __________________</td></tr></table>
    <style>.run-lines thead{display:table-header-group}.run-lines tr{page-break-inside:avoid}.run-lines th,.run-lines td{font-size:6.4px;overflow-wrap:break-word}h3{margin:10px 0 4px}</style>
@endsection
