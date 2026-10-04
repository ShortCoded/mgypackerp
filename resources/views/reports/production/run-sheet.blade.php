@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.production.partials.run-header')
    @include('reports.production.partials.run-formula')
    <h3>{{ __('production_execution.print.follow_up_rows') }}</h3>
    <table dir="{{ $direction ?? 'ltr' }}" class="report-table"><thead><tr><th>{{ __('production_execution.print.day') }}</th><th>{{ __('production_execution.fields.date') }}</th><th>{{ __('production_execution.fields.product') }}</th><th>{{ __('production_execution.fields.shift') }}</th><th>{{ __('production_execution.print.good_output') }}</th><th>{{ __('production_execution.print.loss_output') }}</th><th>{{ __('production_execution.reports.columns.total') }}</th></tr></thead><tbody>
        @forelse($record->progressEntries as $entry)
            @php($loss = bcadd(bcadd((string) $entry->rejected_base_quantity, (string) $entry->rework_base_quantity, 8), (string) $entry->scrap_base_quantity, 8))
            <tr><td>{{ $entry->recorded_at?->translatedFormat('l') }}</td><td>{{ $dates->formatDate($entry->recorded_at, '—') }}</td><td>{{ $record->product?->name }}</td><td>{{ $record->shift?->name ?: '—' }}</td><td dir="ltr">{{ $numbers->format($entry->good_base_quantity) }}@if($entry->good_weight_kg !== null)<br><small>{{ $numbers->format($entry->good_weight_kg) }} kg</small>@endif</td><td dir="ltr">{{ $numbers->format($loss) }}@if($entry->production_scrap_weight_kg !== null)<br><small>{{ $numbers->format($entry->production_scrap_weight_kg) }} kg</small>@endif</td><td dir="ltr">{{ $numbers->format(bcadd((string) $entry->good_base_quantity, $loss, 8)) }}</td></tr>
        @empty
            @for($row = 0; $row < 14; $row++)<tr><td>&nbsp;</td><td></td><td>{{ $record->product?->name }}</td><td></td><td></td><td></td><td></td></tr>@endfor
        @endforelse
    </tbody></table>
    <table dir="{{ $direction ?? 'ltr' }}" class="report-table" style="margin-top:9px"><tbody><tr><th>{{ __('production_execution.reports.columns.good') }}</th><td dir="ltr">{{ $numbers->format($record->good_base_quantity) }}</td><th>{{ __('production_execution.reports.columns.rejected') }}</th><td dir="ltr">{{ $numbers->format($record->rejected_base_quantity) }}</td><th>{{ __('production_execution.reports.columns.rework') }}</th><td dir="ltr">{{ $numbers->format($record->rework_base_quantity) }}</td><th>{{ __('production_execution.reports.columns.scrap') }}</th><td dir="ltr">{{ $numbers->format($record->scrap_base_quantity) }}</td></tr></tbody></table>
    @if(collect($record->labor_details ?? [])->contains(fn ($labor) => !empty($labor['work_segments'])))
        <h3>{{ __('production_execution.fields.daily_work_hours') }}</h3>
        <table class="report-table"><thead><tr><th>{{ __('production_execution.fields.worker_name') }}</th><th>{{ __('production_execution.fields.work_date') }}</th><th>{{ __('production_execution.fields.actual_hours') }}</th></tr></thead><tbody>
            @foreach($record->labor_details ?? [] as $labor)@foreach($labor['work_segments'] ?? [] as $segment)
                <tr><td>{{ $labor['name'] ?? $labor['employee_doc_num'] ?? '—' }}</td><td>{{ $dates->formatDate($segment['work_date']) }}</td><td dir="ltr">{{ $numbers->format($segment['actual_hours']) }}</td></tr>
            @endforeach
            @endforeach
        </tbody></table>
    @endif
    @include('reports.production.partials.signatures', ['areas' => ['production_supervisor', 'quality']])
@endsection
