@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<p>{{ __('inventory_correction.card.opening') }}: <strong dir="ltr">{{ $numbers->format($totals['opening_balance'], 8) }}</strong> · {{ __('inventory_correction.card.closing') }}: <strong dir="ltr">{{ $numbers->format($totals['closing_balance'], 8) }}</strong> {{ $product->unit?->name }}</p>
<div class="table-responsive"><table class="table table-sm report-table">
    <thead><tr>@foreach(['date', 'source', 'store', 'type', 'unit', 'in', 'out', 'balance', 'reversal'] as $key)<th>{{ __('inventory_correction.card.'.$key) }}</th>@endforeach</tr></thead>
    <tbody>@forelse($movements as $row)<tr>
        <td>{{ $dates->formatDate($row->transaction_date) }}</td>
        <td>{{ $row->source_doc_num }} @include('reports.partials.inventory-serial-details', ['transaction' => $row])</td>
        <td>{{ $row->branchStore?->name }}</td><td>{{ __('inventory.movements.types.'.$row->transaction_type) }}</td><td>{{ $row->product?->unit?->name }}</td>
        <td dir="ltr">{{ $numbers->format($row->quantity_in, 8) }}</td><td dir="ltr">{{ $numbers->format($row->quantity_out, 8) }}</td><td dir="ltr">{{ $numbers->format($row->running_balance, 8) }}</td><td>{{ $row->is_reversal ? __('Yes') : __('No') }}</td>
    </tr>@empty<tr><td colspan="9">{{ __('stock_balance_inquiry.empty') }}</td></tr>@endforelse</tbody>
    <tfoot><tr><td colspan="5">{{ __('Total') }}</td><td dir="ltr">{{ $numbers->format($totals['quantity_in'], 8) }}</td><td dir="ltr">{{ $numbers->format($totals['quantity_out'], 8) }}</td><td dir="ltr">{{ $numbers->format($totals['closing_balance'], 8) }}</td><td></td></tr></tfoot>
</table></div>
