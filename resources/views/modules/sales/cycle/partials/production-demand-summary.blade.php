@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $demandGroups = $backorders->groupBy(fn ($row) => $row['line']->product_id.':'.$row['line']->order->branch_store_id);
@endphp
<section class="card my-3"><div class="card-header"><h5>{{ __('Production demand by product') }}</h5></div><div class="table-responsive"><table class="table table-sm report-table"><thead><tr><th>{{ __('Item') }}</th><th>{{ __('Warehouse') }}</th><th>{{ __('Base unit') }}</th><th>{{ __('Shortage') }}</th><th>{{ __('Remaining production') }}</th><th>{{ __('Unplanned quantity') }}</th><th>{{ __('Source orders') }}</th></tr></thead><tbody>
@foreach($demandGroups as $rows)
@php
    $line = $rows->first()['line'];
    $shortage = $rows->reduce(fn ($total, $row) => bcadd($total, bcmul($row['shortage'], $row['line']->conversion_factor, 8), 8), '0');
    $planned = $rows->reduce(fn ($total, $row) => bcadd($total, bcmul($row['remaining_production'], $row['line']->conversion_factor, 8), 8), '0');
    $unplanned = $rows->reduce(fn ($total, $row) => bcadd($total, $row['unplanned_base'], 8), '0');
@endphp
<tr><td>{{ $line->product?->doc_num }} · {{ $line->product?->name }}</td><td>{{ $line->order->branchStore?->name }}</td><td>{{ $line->product?->unit?->name }}</td><td>{{ $numbers->format($shortage) }}</td><td>{{ $numbers->format($planned) }}</td><td>{{ $numbers->format($unplanned) }}</td><td>@foreach($rows as $row)<span class="d-block">{{ $row['line']->order->doc_num }} · {{ $row['line']->order->customer?->name }} · {{ $numbers->format(bcmul($row['shortage'], $row['line']->conversion_factor, 8)) }}</span>@endforeach</td></tr>
@endforeach
</tbody></table></div></section>
