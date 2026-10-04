<x-admin.report.table-card :title="__('production_run_correction.stock_impact')" :table-id="'run-correction-stock-impact-'.($impactKey ?? 'current')" class="mt-3">
<thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Product') }}</th><th>{{ __('inventory.stock_counts.attributes.stock_status') }}</th><th>{{ __('production_run_correction.quantity_change') }}</th><th>{{ __('production_run_correction.value_change') }}</th></tr></thead><tbody>
@foreach($impact['stock'] as $row)<tr><td>{{ $row['document'] }}</td><td>{{ $dates->formatDate($row['date']) }}</td><td>{{ $row['product'] }}</td><td>{{ __('inventory.movements.stock_statuses.'.$row['stock_status']) }}</td><td>{{ $numbers->format($row['quantity_change']) }}</td><td>{{ $row['value_change'] === null ? '—' : $numbers->format($row['value_change']) }}</td></tr>@endforeach
</tbody></x-admin.report.table-card>
<x-admin.report.table-card :title="__('production_run_correction.journal_impact')" :table-id="'run-correction-journal-impact-'.($impactKey ?? 'current')" class="mt-3">
<thead><tr><th>{{ __('Document') }}</th><th>{{ __('Account') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Cost Center') }}</th><th>{{ __('Debit') }}</th><th>{{ __('Credit') }}</th></tr></thead><tbody>
@foreach($impact['journals'] as $row)<tr><td>{{ $row['document'] }}</td><td>{{ $row['account'] }}</td><td>{{ $row['branch'] }}</td><td>{{ $row['cost_center'] }}</td><td>{{ $numbers->format($row['debit']) }}</td><td>{{ $numbers->format($row['credit']) }}</td></tr>@endforeach
</tbody></x-admin.report.table-card>
