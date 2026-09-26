@php($reportColumns = app(\Modules\Purchases\Services\Reports\ProcurementCycleReport::class)->columns($reportType, $showPrices, $filters['detail_level'] ?? 'summary'))
<table class="report-table table table-sm align-middle">
    <thead><tr>@foreach($reportColumns as $label)<th>{{ __($label) }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($rows as $row)
        <tr>@foreach($reportColumns as $key => $label)
            @php($value = $row[$key] ?? null)
            <td @if(is_numeric($value)) class="text-end" dir="ltr" @endif>
                @if(in_array($key, ['status', 'payment_status', 'selection_status']) && filled($value))
                    {{ __('procurement.statuses.'.$value) }}
                @elseif($key === 'document' && filled($row['document_url'] ?? null) && auth()->user()?->can($row['document_permission'] ?? "reports.purchases.{$reportType}.view"))
                    <a href="{{ $row['document_url'] }}">{{ $value }}</a>
                @elseif($key === 'reference' && filled($row['reference_url'] ?? null) && auth()->user()?->can($row['reference_permission']))
                    <a href="{{ $row['reference_url'] }}">{{ $value }}</a>
                @elseif($key === 'purchase_order' && filled($value) && auth()->user()?->can('purchase_orders.view'))
                    <a href="{{ route('admin.purchases.purchase-orders.show', $value) }}">{{ $value }}</a>
                @elseif($key === 'receipt' && !empty($row['receipt_references']) && auth()->user()?->can('purchases.goods_receipt_notes.view'))
                    @foreach($row['receipt_references'] as $reference)
                        <a href="{{ route('admin.purchases.goods-receipt-notes.show', $reference) }}">{{ $reference }}</a>@unless($loop->last) / @endunless
                    @endforeach
                @elseif($key === 'category' && filled($value))
                    {{ $value }}
                @elseif(is_numeric($value))
                    {{ $numbers->format($value) }}
                @else
                    {{ $value ?: '—' }}
                @endif
            </td>
        @endforeach</tr>
    @empty
        <tr><td colspan="{{ count($reportColumns) }}">{{ __('No matching records.') }}</td></tr>
    @endforelse
    </tbody>
    @if($reportType === \Modules\Purchases\Services\Reports\ProcurementCycleReport::SupplierStatement)
    <tfoot>@foreach($rows->groupBy(fn ($row) => ($row['supplier_doc_num'] ?? '').'|'.($row['currency'] ?? '')) as $group)
        <tr><th colspan="{{ count($reportColumns) - 4 }}">{{ $group->first()['supplier'] }} — {{ __('Closing balance') }}</th><th>{{ $numbers->format($group->sum('debit')) }}</th><th>{{ $numbers->format($group->sum('credit')) }}</th><th>{{ $numbers->format($group->last()['balance']) }}</th><th>{{ $group->first()['currency'] }}</th></tr>
    @endforeach</tfoot>
    @elseif($reportType === \Modules\Purchases\Services\Reports\ProcurementCycleReport::PurchaseLedger)
    <tfoot>@foreach($rows->groupBy('currency') as $currency => $group)<tr>
        @foreach($reportColumns as $key => $label)<th>@if($loop->first){{ __('Total') }}@elseif($key === 'currency'){{ $currency }}@elseif(in_array($key, ['taxable', 'discount', 'tax', 'amount', 'returned_value', 'net_purchases', 'paid', 'outstanding'], true)){{ $numbers->format($group->sum($key)) }}@endif</th>@endforeach
    </tr>@endforeach</tfoot>
    @endif
</table>
