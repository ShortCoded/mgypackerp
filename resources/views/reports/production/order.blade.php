@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.partials.company-identity')
    <table class="report-table" style="margin-bottom:9px"><tbody>
        <tr><th>{{ __('production_execution.fields.production_order') }}</th><td dir="ltr">{{ $record->doc_num }}</td><th>{{ __('production_execution.fields.sales_order') }}</th><td dir="ltr">{{ $record->salesOrder?->doc_num }}</td></tr>
        <tr><th>{{ __('production_execution.fields.order_date') }}</th><td>{{ $dates->formatDate($record->production_order_date, '') }}</td><th>{{ __('production_execution.fields.delivery_date') }}</th><td>{{ $dates->formatDate($record->expected_delivery_date, '') }}</td></tr>
        <tr><th>{{ __('production_execution.fields.status') }}</th><td>{{ __('production_execution.statuses.'.$record->status) }}</td><th>{{ __('production_execution.fields.branch') }}</th><td>{{ $record->salesOrder?->branch?->name ?: '—' }}</td></tr>
        @if($record->salesOrder?->salesEmployee)<tr><th>{{ __('production_execution.fields.sales_representative') }}</th><td colspan="3">{{ $record->salesOrder->salesEmployee->doc_num }} / {{ $record->salesOrder->salesEmployee->full_name ?: $record->salesOrder->salesEmployee->name }}</td></tr>@endif
    </tbody></table>
    <table class="report-table"><thead><tr><th>#</th><th>{{ __('production_execution.fields.product') }}</th><th>{{ __('production_execution.fields.unit') }}</th><th>{{ __('production_execution.fields.quantity') }}</th><th>{{ __('production_execution.fields.specifications') }}</th><th>{{ __('production_execution.fields.production_route') }}</th></tr></thead><tbody>
        @foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} / {{ $line->description }}</td><td>{{ $line->unit?->name }}</td><td dir="ltr">{{ $numbers->format($line->quantity) }}</td><td>{{ is_array($line->specifications) ? collect($line->specifications)->map(fn($value, $key) => $key.': '.$value)->join(' · ') : $line->specifications }}</td><td>{{ $line->stageSnapshots->map(fn($stage) => $stage->sequence.'. '.$stage->stage_name)->join(' ← ') ?: '—' }}</td></tr>@endforeach
    </tbody></table>
    @foreach($record->lines as $line)
        @php($formula = $formulas[$line->getKey()] ?? null)
        <h3>{{ __('production_execution.orders.material_requirements') }} — {{ $line->product?->doc_num }} / {{ $line->description }}</h3>
        @if($formula && $formula['is_draft_preview'])<p>{{ __('production_execution.orders.formula_preview') }}</p>@endif
        @if($formula)<p>{{ __('production_execution.orders.bom_basis', ['quantity' => $numbers->format($formula['basis_quantity']), 'unit' => $formula['basis_unit_name'] ?? '']) }}</p>@endif
        <table class="report-table"><thead><tr><th>#</th><th>{{ __('production_execution.fields.product') }}</th><th>{{ __('production_execution.orders.per_equivalent_unit') }}</th><th>{{ __('production_execution.orders.required_quantity') }}</th></tr></thead><tbody>
            @forelse($formula['components'] ?? [] as $index => $component)
                <tr><td>{{ $index + 1 }}</td><td>{{ $component['product_doc_num'] }} — {{ $component['product_name'] }}</td><td dir="ltr">{{ $numbers->format($component['quantity_per_output']) }} {{ $component['unit_name'] }}</td><td dir="ltr">{{ $numbers->format(bcmul($component['quantity_per_output'], $formula['basis_quantity'], 8)) }} {{ $component['unit_name'] }}</td></tr>
            @empty
                <tr><td colspan="4">{{ __('production_execution.orders.no_components') }}</td></tr>
            @endforelse
        </tbody></table>
    @endforeach
    @if($record->production_notes)<p><strong>{{ __('production_execution.fields.notes') }}:</strong> {{ $record->production_notes }}</p>@endif
@endsection
