<x-admin.report.table-card class="mb-3" :title="__('production_invoice_cost.stage')" :table-id="'stage-cost-quantities-'.$impactKey">
    <thead><tr><th>{{ __('production_invoice_cost.stage') }}</th><th>{{ __('Good') }}</th><th>{{ __('Rejected') }}</th><th>{{ __('Rework') }}</th><th>{{ __('Scrap') }}</th><th>{{ __('production_invoice_cost.received') }}</th></tr></thead>
    <tbody>@foreach($impact['stage_cost_graph']['runs'] as $state)
    <tr><td>@can('production.runs.view')<a href="{{ route('admin.production.runs.show', $state['run']['public_id']) }}">{{ $state['run']['run_number'] }}</a>@else{{ $state['run']['run_number'] }}@endcan</td>
        @foreach(['good_base_quantity', 'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity', 'received_base_quantity'] as $field)<td>{{ $numbers->format($state['run'][$field]) }}</td>@endforeach</tr>
    @endforeach</tbody>
</x-admin.report.table-card>
<x-admin.report.table-card class="mb-3" :title="__('production_invoice_cost.value_change')" :table-id="'stage-cost-values-'.$impactKey">
    <thead><tr><th>{{ __('Document') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Account') }}</th><th>{{ __('production_invoice_cost.value_change') }}</th><th>{{ __('production_invoice_cost.posting_amount') }}</th></tr></thead>
    <tbody>@foreach($impact['effects'] as $effect)
    <tr><td>{{ $effect['source_doc_num'] }}</td><td>{{ $effect['branch_label'] }}</td><td>{{ $effect['account_labels'][app()->getLocale()] ?? $effect['account_labels']['en'] }}</td><td>{{ $numbers->formatWithMinimumDecimals($effect['stage_value']['delta'] ?? $effect['amount'], 8) }}</td><td>{{ $numbers->formatWithMinimumDecimals($effect['amount'], 4) }}</td></tr>
    @endforeach</tbody>
</x-admin.report.table-card>
