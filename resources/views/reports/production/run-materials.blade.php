@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.production.partials.run-header')
    @php($runFormulaBasis = is_array($record->orderLine?->bom_snapshot) ? bcmul((string) $record->planned_base_quantity, (string) ($record->orderLine->bom_snapshot['basis_base_quantity'] ?? '1'), 8) : null)
    <h3>{{ __('production_execution.print.material_requirement') }}</h3>
    <table dir="{{ $direction ?? 'ltr' }}" class="report-table"><thead><tr><th>#</th><th>{{ __('production_execution.reports.columns.material') }}</th><th>{{ __('production_execution.orders.per_equivalent_unit') }}</th><th>{{ __('production_execution.reports.columns.planned') }}</th><th>{{ __('production_execution.reports.columns.reserved') }}</th><th>{{ __('production_execution.reports.columns.issued') }}</th><th>{{ __('production_execution.reports.columns.additional') }}</th><th>{{ __('production_execution.reports.columns.returned') }}</th><th>{{ __('production_execution.reports.columns.consumed') }}</th><th>{{ __('production_execution.reports.columns.waste') }}</th></tr></thead><tbody>
        @forelse($record->requirements as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td dir="ltr">{{ $numbers->format($line->component_quantity_snapshot) }} {{ $line->unit?->name }}</td><td dir="ltr">{{ $numbers->format($line->planned_quantity) }} {{ $line->unit?->name }}@if($runFormulaBasis !== null)<br><small>{{ $numbers->format($runFormulaBasis) }} × {{ $numbers->format($line->component_quantity_snapshot) }} = {{ $numbers->format($line->planned_quantity) }}</small>@endif</td><td dir="ltr">{{ $numbers->format($line->reserved_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->issued_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->additional_issued_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->returned_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->consumed_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->waste_quantity) }}</td></tr>@empty<tr><td colspan="10">{{ __('production_execution.print.no_materials') }}</td></tr>@endforelse
    </tbody></table>
    @include('reports.production.partials.signatures', ['areas' => ['production_supervisor', 'warehouse']])
@endsection
