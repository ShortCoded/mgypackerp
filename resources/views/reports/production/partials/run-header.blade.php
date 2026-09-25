@include('reports.partials.company-identity')
<table class="report-table" style="margin-bottom:9px"><tbody>
    <tr><th>{{ __('production_execution.fields.run') }}</th><td dir="ltr">{{ $record->run_number }}</td><th>{{ __('production_execution.fields.production_order') }}</th><td dir="ltr">{{ $record->order?->doc_num ?: '—' }}</td></tr>
    <tr><th>{{ __('production_execution.fields.stage') }}</th><td>{{ $record->stageSnapshot?->stage_name ?: '—' }}</td><th>{{ __('production_execution.fields.product') }}</th><td>{{ $record->product?->doc_num }} — {{ $record->product?->name }}</td></tr>
    <tr><th>{{ __('production_execution.fields.fixed_asset') }}</th><td>{{ $record->fixedAsset?->doc_num }} — {{ $record->fixedAsset?->asset_name }}</td><th>{{ __('production_execution.fields.shift') }}</th><td>{{ $record->shift?->name ?: '—' }}</td></tr>
    <tr><th>{{ __('production_execution.fields.planned_start_at') }}</th><td>{{ $dates->formatDateTime($record->planned_start_at, '—') }}</td><th>{{ __('production_execution.fields.planned_end_at') }}</th><td>{{ $dates->formatDateTime($record->planned_end_at, '—') }}</td></tr>
    <tr><th>{{ __('production_execution.fields.planned_quantity') }}</th><td dir="ltr">{{ $numbers->format($record->planned_base_quantity) }}</td><th>{{ __('production_execution.fields.status') }}</th><td>{{ __('production_execution.statuses.'.$record->status) }}</td></tr>
    @if($record->orderLine && is_array($record->orderLine->bom_snapshot))
        <tr><th>{{ __('production_execution.orders.material_requirements') }}</th><td colspan="3">{{ __('production_execution.orders.bom_basis', ['quantity' => $numbers->format(bcmul((string) $record->planned_base_quantity, (string) ($record->orderLine->bom_snapshot['basis_base_quantity'] ?? '1'), 8)), 'unit' => $record->orderLine->bom_snapshot['basis_unit_name'] ?? '']) }}</td></tr>
    @endif
    <tr><th>{{ __('production_execution.fields.batch_lot') }}</th><td dir="ltr">{{ $record->batch_lot ?: '—' }}</td><th>{{ __('production_execution.fields.work_description') }}</th><td>{{ $record->work_description ?: '—' }}</td></tr>
</tbody></table>
