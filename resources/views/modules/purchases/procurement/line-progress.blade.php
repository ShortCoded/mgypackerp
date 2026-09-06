@php
    $progressLines = collect();
    if ($record instanceof \Modules\Purchases\Models\PurchaseRequisition) {
        $progressLines = $record->lines;
    } elseif ($record instanceof \Modules\Purchases\Models\PurchaseOrder) {
        $progressLines = $record->lines()->withQuantityProgress()->with(['product', 'requisitionLine'])->get();
    } elseif ($record->purchase_order_id) {
        $progressLines = $record->purchaseOrder?->lines()->withQuantityProgress()->with(['product', 'requisitionLine'])->get() ?? collect();
    }
    $progressColumns = ['requested' => 'Requested', 'approved' => 'Approved', 'ordered' => 'Ordered', 'received' => 'Received', 'returned' => 'Returned', 'net_received' => 'Net received', 'invoiced' => 'Invoiced', 'remaining' => 'Remaining to receive', 'remaining_to_invoice' => 'Remaining to invoice'];
@endphp
@if($progressLines->isNotEmpty())
<div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Quantity progress') }}</h6></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>{{ __('Item') }}</th>@foreach($progressColumns as $label)<th class="text-end">{{ __($label) }}</th>@endforeach</tr></thead><tbody>
@foreach($progressLines as $progressLine)
    @php
        $progress = $progressLine->quantityProgress();
        if ($progressLine instanceof \Modules\Purchases\Models\PurchaseOrderLine) {
            $progress['requested'] = $progressLine->requisitionLine?->requested_quantity;
            $progress['approved'] = $progressLine->requisitionLine?->approved_quantity;
        }
    @endphp
    <tr><td>{{ $progressLine->product?->doc_num }} / {{ $progressLine->product?->name }}</td>@foreach($progressColumns as $key => $label)<td class="text-end" dir="ltr">{{ isset($progress[$key]) ? app(\Modules\Core\Services\NumericFormatService::class)->format($progress[$key]) : '—' }}</td>@endforeach</tr>
@endforeach
</tbody></table></div></div>
@endif
