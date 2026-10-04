<div class="border rounded p-3 mb-3">
    <h6>{{ __('inventory.movements.receipt_completion_impact') }}</h6>
    <p class="mb-2">{{ __('inventory.movements.receipt_completion_posting_date') }}: {{ app(\Modules\Core\Services\DateFormatService::class)->formatDate($impact['posting_date']) }} · {{ __('inventory.movements.receipt_pricing_total') }}: <strong>{{ $numbers->format($impact['source_total'], 8) }}</strong></p>
    <p class="mb-2">{{ __('inventory.movements.receipt_completion_counterpart') }}: {{ data_get($impact, 'counterpart_account_labels.'.app()->getLocale()) ?? $impact['counterpart_account_id'] }}</p>
    @if($impact['source_period_closed'])<p class="text-muted small">{{ __('inventory.movements.receipt_completion_closed_source') }}</p>@endif
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>
        <th>{{ __('Document') }}</th><th>{{ __('inventory.movements.receipt_completion_effect') }}</th>
        <th>{{ __('Account') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Cost Center') }}</th>
        <th>{{ __('inventory.movements.receipt_completion_cost_before') }}</th><th>{{ __('inventory.movements.receipt_completion_cost_after') }}</th>
        <th>{{ __('inventory.movements.receipt_completion_amount') }}</th>
    </tr></thead><tbody>
    @foreach($impact['effects'] as $effect)
        <tr><td class="text-nowrap">{{ $effect['source_doc_num'] }}</td><td>{{ __('inventory.movements.receipt_completion_effects.'.$effect['effect']) }}</td>
            <td>{{ data_get($effect, 'account_labels.'.app()->getLocale()) ?? $effect['account_id'] }}</td><td>{{ $effect['branch_label'] ?? $effect['branch_id'] }}</td>
            <td>{{ data_get($effect, 'cost_center_labels.'.app()->getLocale()) ?? $effect['cost_center_id'] ?? '—' }}</td>
            <td dir="ltr" class="text-nowrap">{{ $effect['original_total_cost'] === null ? '—' : $numbers->format($effect['original_total_cost'], 8) }}</td>
            <td dir="ltr" class="text-nowrap">{{ $numbers->format($effect['completed_total_cost'], 8) }}</td>
            <td dir="ltr" class="text-nowrap">{{ $numbers->format($effect['amount'], 8) }}</td>
        </tr>
    @endforeach
    </tbody></table></div>
</div>
