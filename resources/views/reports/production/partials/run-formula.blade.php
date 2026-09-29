@php($runFormulaBasis = is_array($record->orderLine?->bom_snapshot) ? bcmul((string) $record->planned_base_quantity, (string) ($record->orderLine->bom_snapshot['basis_base_quantity'] ?? '1'), 8) : null)
@if($record->requirements->isNotEmpty())
    <h3>{{ __('production_execution.orders.material_requirements') }}</h3>
    @if($runFormulaBasis !== null)
        <p>{{ __('production_execution.orders.bom_basis', ['quantity' => $numbers->format($runFormulaBasis), 'unit' => $record->orderLine->bom_snapshot['basis_unit_name'] ?? '']) }}</p>
    @endif
    <table dir="{{ $direction ?? 'ltr' }}" class="report-table"><thead><tr><th>#</th><th>{{ __('production_execution.reports.columns.material') }}</th><th>{{ __('production_execution.orders.per_equivalent_unit') }}</th><th>{{ __('production_execution.orders.required_quantity') }}</th></tr></thead><tbody>
        @foreach($record->requirements as $line)
            <tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td dir="ltr">{{ $numbers->format($line->component_quantity_snapshot) }} {{ $line->unit?->name }}</td><td dir="ltr">{{ $numbers->format($line->planned_quantity) }} {{ $line->unit?->name }}@if($runFormulaBasis !== null)<br><small>{{ $numbers->format($runFormulaBasis) }} × {{ $numbers->format($line->component_quantity_snapshot) }} = {{ $numbers->format($line->planned_quantity) }}</small>@endif</td></tr>
        @endforeach
    </tbody></table>
@endif
