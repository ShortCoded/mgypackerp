@php
    $paymentField = $record instanceof \Modules\Finance\Models\Cheque ? 'cheque_id' : 'cash_voucher_id';
    $purchasePayment = \Modules\Purchases\Models\SupplierPaymentContext::query()->where('company_id', $record->company_id)
        ->where($paymentField, $record->getKey())->with(['supplier', 'allocations.purchaseInvoice.purchaseOrder'])->first();
@endphp
@if($purchasePayment)
<table class="document-meta-table"><tr><td><strong>{{ __('Supplier') }}</strong><br>{{ $purchasePayment->supplier?->name }}</td><td><strong>{{ __('Source reference') }}</strong><br>{{ $purchasePayment->doc_num }}</td></tr></table>
<table class="report-table"><thead><tr><th>{{ __('Supplier Invoice') }}</th><th>{{ __('Purchase Order') }}</th><th>{{ __('Paid amount') }}</th></tr></thead><tbody>
@foreach($purchasePayment->allocations as $allocation)<tr><td>{{ $allocation->purchaseInvoice?->doc_num }}</td><td>{{ $allocation->purchaseInvoice?->purchaseOrder?->doc_num }}</td><td class="text-end">{{ $numbers->format($allocation->amount) }}</td></tr>@endforeach
</tbody></table>
@endif
