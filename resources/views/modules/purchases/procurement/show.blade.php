@extends('layouts.app')

@php
    $showPrices = $commercial && auth()->user()?->can('purchases.prices.view');
    $document = $record->doc_num ?? $record->cashVoucher?->doc_num;
    $status = $record->status ?? $record->cashVoucher?->status ?? '—';
    $title = __(str($type)->replace('_', ' ')->title()->toString()).' '.$document;
    $printType = str($type)->replace('_', '-')->toString();
    $printPermission = match ($type) {
        'purchase_requisition' => 'purchases.purchase_requisitions.print',
        'request_for_quotation' => 'purchases.request_for_quotations.print',
        'supplier_quotation' => 'purchases.supplier_quotation_entry.print',
        'supplier_selection' => 'purchases.supplier_selection.print',
        'purchase_order_change_request' => 'purchases.purchase_order_change_requests.print',
        'supply_order' => 'purchases.supply_orders.print',
        'goods_receipt' => 'purchases.goods_receipt_notes.print',
        'goods_receipt_inspection' => 'purchases.goods_receipt_inspection.print',
        'purchase_return' => 'purchases.purchase_returns.print',
        'supplier_payment' => 'supplier_payments.print',
        default => null,
    };
    $printUrl = route('admin.purchases.procurement.print', [$printType, $document]);
    if ($type === 'supplier_payment' && $record->payment_method === \Modules\Purchases\Models\SupplierPaymentContext::MethodCash && $record->cashVoucher && auth()->user()?->can('cash_payment_vouchers.print')) {
        $printUrl = route('admin.finance.cash-payment-vouchers.print', $record->cashVoucher->doc_num);
    }
    if ($type === 'supplier_payment' && $record->payment_method === \Modules\Purchases\Models\SupplierPaymentContext::MethodCheque && $record->cheque && auth()->user()?->can('cheques.print')) {
        $printUrl = route('admin.finance.cheques.print', $record->cheque->doc_num);
    }
    $originalChangeValues = $record->original_values ?? [];
    $requestedChangeValues = $record->requested_values ?? [];
    $documentAttachmentCollection = match ($type) {
        'supplier_quotation' => \Modules\Purchases\Models\SupplierQuotation::AttachmentCollection,
        'goods_receipt_inspection' => \Modules\Purchases\Models\GoodsReceiptInspection::AttachmentCollection,
        default => \Modules\Purchases\Services\ProcurementAttachmentService::OperationalCollection,
    };
    $isOwnBranch = (int) ($record->branch_id ?? 0) === (int) ($activeBranchId ?? 0);
    if ($type === 'purchase_order_change_request' && ! $showPrices) {
        $originalChangeValues['lines'] = collect($originalChangeValues['lines'] ?? [])->map(fn ($line) => collect($line)->except('unit_price')->all())->all();
        $requestedChangeValues['lines'] = collect($requestedChangeValues['lines'] ?? [])->map(fn ($line) => collect($line)->except('unit_price')->all())->all();
    }

    $lineage = collect();
    $addLineage = function (string $label, mixed $related, string $routeName, string $permission) use ($lineage): void {
        if ($related && filled($related->doc_num ?? null)) {
            $lineage->push([
                'label' => $label,
                'doc_num' => $related->doc_num,
                'url' => route($routeName, $related->doc_num),
                'permission' => $permission,
            ]);
        }
    };

    if ($type === 'purchase_requisition') {
        foreach ($record->supplierQuotations ?? [] as $quotation) {
            $addLineage(__('Supplier Quotation'), $quotation, 'admin.purchases.supplier-quotation-entry.show', 'purchases.supplier_quotation_entry.view');
        }
        foreach ($record->requestsForQuotation ?? [] as $rfq) {
            $addLineage(__('RFQ'), $rfq, 'admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view');
        }
    } elseif ($type === 'request_for_quotation') {
        $addLineage(__('Purchase Requisition'), $record->requisition, 'admin.purchases.purchase-requisitions.show', 'purchases.purchase_requisitions.view');
        foreach ($record->quotations ?? [] as $quotation) {
            $addLineage(__('Supplier Quotation'), $quotation, 'admin.purchases.supplier-quotation-entry.show', 'purchases.supplier_quotation_entry.view');
        }
    } elseif ($type === 'supplier_quotation') {
        $addLineage(__('Purchase Requisition'), $record->purchaseRequisition, 'admin.purchases.purchase-requisitions.show', 'purchases.purchase_requisitions.view');
        $addLineage(__('Purchase Order'), $record->purchaseOrder, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
        $addLineage(__('RFQ'), $record->requestForQuotation, 'admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view');
    } elseif ($type === 'supplier_selection') {
        $addLineage(__('RFQ'), $record->requestForQuotation, 'admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view');
        foreach (($record->lines ?? collect())->pluck('purchaseOrder')->filter()->unique('id') as $order) {
            $addLineage(__('Purchase Order'), $order, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
        }
    } elseif ($type === 'purchase_order_change_request') {
        $addLineage(__('Purchase Order'), $record->purchaseOrder, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
    } elseif ($type === 'supply_order') {
        $addLineage(__('Purchase Order'), $record->purchaseOrder, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
        $addLineage(__('Purchase Invoice'), $record->purchaseInvoice, 'admin.purchases.purchase-invoices.show', 'purchase_invoices.view');
        foreach ($record->receipts ?? [] as $receipt) {
            $addLineage(__('Goods Receipt'), $receipt, 'admin.purchases.goods-receipt-notes.show', 'purchases.goods_receipt_notes.view');
        }
    } elseif ($type === 'goods_receipt') {
        $addLineage(__('Supply Order'), $record->supplyOrder, 'admin.purchases.supply-orders.show', 'purchases.supply_orders.view');
        $addLineage(__('Purchase Order'), $record->purchaseOrder, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
        $addLineage(__('Incoming QC Inspection'), $record->inspection, 'admin.purchases.goods-receipt-inspection.show', 'purchases.goods_receipt_inspection.view');
    } elseif ($type === 'goods_receipt_inspection') {
        $addLineage(__('Goods Receipt'), $record->receipt, 'admin.purchases.goods-receipt-notes.show', 'purchases.goods_receipt_notes.view');
        $addLineage(__('Purchase Order'), $record->receipt?->purchaseOrder, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
    } elseif ($type === 'purchase_return') {
        $addLineage(__('Purchase Order'), $record->purchaseOrder, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
        $addLineage(__('Goods Receipt'), $record->receipt, 'admin.purchases.goods-receipt-notes.show', 'purchases.goods_receipt_notes.view');
        $addLineage(__('Purchase Invoice'), $record->purchaseInvoice, 'admin.purchases.purchase-invoices.show', 'purchase_invoices.view');
    } elseif ($type === 'supplier_payment') {
        $addLineage(__('Purchase Order'), $record->purchaseOrder, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
        foreach (($record->allocations ?? collect())->pluck('purchaseInvoice')->filter()->unique('id') as $invoice) {
            $addLineage(__('Purchase Invoice'), $invoice, 'admin.purchases.purchase-invoices.show', 'purchase_invoices.view');
        }
        $addLineage(__('Cash Payment Voucher'), $record->cashVoucher, 'admin.finance.cash-payment-vouchers.show', 'cash_payment_vouchers.view');
        $addLineage(__('Issued Cheque'), $record->cheque, 'admin.finance.cheques.show', 'cheques.view');
    }
@endphp

@section('title', $title)

@section('content')
    <div class="card mb-3">
        <div class="card-header py-2 d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div>
                <h5 class="mb-1">{{ $title }}</h5>
                <x-status-indicator :status="$status" />
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-falcon-default btn-sm" href="{{ url()->previous() }}">
                    <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
                </a>
                @if($printPermission && auth()->user()?->can($printPermission))
                <a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ $printUrl }}">
                    <span class="fas fa-print me-1"></span>{{ __('Print') }}
                </a>
                @endif

                @if($type === 'purchase_requisition' && $record->status === 'draft' && $isOwnBranch)
                    @can('purchases.purchase_requisitions.delete')<form method="POST" action="{{ route('admin.purchases.purchase-requisitions.destroy', $record) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">{{ __('Delete draft') }}</button></form>@endcan
                    @can('purchases.purchase_requisitions.edit')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-requisitions.edit', $record->doc_num) }}">{{ __('Edit') }}</a>@endcan
                    @can('purchases.purchase_requisitions.submit')
                    <form method="POST" action="{{ route('admin.purchases.purchase-requisitions.submit', $record->doc_num) }}">@csrf<button class="btn btn-falcon-primary btn-sm">{{ __('Submit for approval') }}</button></form>
                    @endcan
                @endif
                @if($type === 'purchase_requisition' && $record->status === 'pending_approval' && ($isAdministrativeBranch ?? false))
                    @can('purchases.purchase_requisition_approvals.approve')
                    <form id="purchase-request-approval" method="POST" action="{{ route('admin.purchases.purchase-requisitions.approve', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Approve') }}</button></form>
                    @endcan
                @endif
                @if($type === 'purchase_requisition' && ($isAdministrativeBranch ?? false) && in_array($record->status, ['approved', 'partially_converted', 'fully_converted'], true))
                    @can('purchases.supplier_quotation_entry.create')
                    @can('purchases.prices.view')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supplier-quotation-entry.create-source', [\Modules\Purchases\Models\SupplierQuotation::SourcePurchaseRequisition, $record->doc_num]) }}">{{ __('Enter supplier quotation') }}</a>
                    @endcan
                    @endcan
                    @if($record->status !== 'fully_converted')
                    @can('purchase_orders.create')
                    @can('purchases.prices.view')
                    <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.purchase-orders.create', ['purchase_requisition_doc_nums' => [$record->doc_num]]) }}">{{ __('Create Purchase Order') }}</a>
                    @endcan
                    @endcan
                    @endif
                @endif

                @if($type === 'purchase_requisition')
                    @if($record->status === 'pending_approval' && ($isAdministrativeBranch ?? false))
                        @can('purchases.purchase_requisition_approvals.reject')
                        <form method="POST" action="{{ route('admin.purchases.purchase-requisitions.reject', $record->doc_num) }}" class="d-flex gap-2">@csrf<input class="form-control form-control-sm" name="rejection_reason" placeholder="{{ __('Rejection reason') }}" required><button class="btn btn-danger btn-sm">{{ __('Reject') }}</button></form>
                        @endcan
                    @endif
                    @if(($isOwnBranch || ($isAdministrativeBranch ?? false)) && !in_array($record->status, ['cancelled', 'closed']))
                        @can('purchases.purchase_requisitions.cancel')
                        <form method="POST" action="{{ route('admin.purchases.purchase-requisitions.cancel', $record->doc_num) }}" class="d-flex gap-2">@csrf<input class="form-control form-control-sm" name="cancel_reason" placeholder="{{ __('Cancellation reason') }}" required><button class="btn btn-falcon-danger btn-sm">{{ __('Cancel') }}</button></form>
                        @endcan
                    @endif
                    @if(($isOwnBranch || ($isAdministrativeBranch ?? false)) && in_array($record->status, ['approved', 'partially_converted', 'fully_converted']))
                        @can('purchases.purchase_requisitions.close')
                        <form method="POST" action="{{ route('admin.purchases.purchase-requisitions.close', $record->doc_num) }}">@csrf<button class="btn btn-falcon-default btn-sm">{{ __('Close') }}</button></form>
                        @endcan
                    @endif
                @endif

                @if($type === 'request_for_quotation' && $record->status === 'draft')
                    @can('purchases.request_for_quotations.approve')
                    <form method="POST" action="{{ route('admin.purchases.request-for-quotations.issue', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Issue RFQ') }}</button></form>
                    @endcan
                @endif
                @if($type === 'request_for_quotation' && $record->status === 'issued')
                    @can('purchases.supplier_quotation_entry.create')
                    @can('purchases.prices.view')
                    <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.supplier-quotation-entry.create', $record->doc_num) }}">{{ __('Enter supplier quotation') }}</a>
                    @endcan
                    @endcan
                    @can('purchases.supplier_quotation_comparison.view')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supplier-quotation-comparison.show', $record->doc_num) }}">{{ __('Compare') }}</a>
                    @endcan
                    @can('purchases.supplier_selection.create')
                    @can('purchases.prices.view')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supplier-selection.create', $record->doc_num) }}">{{ __('Select suppliers') }}</a>
                    @endcan
                    @endcan
                @endif

                @if($type === 'supplier_quotation' && $record->status === 'draft')
                    @can('purchases.supplier_quotation_entry.edit')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supplier-quotation-entry.edit', $record->doc_num) }}">{{ __('Edit') }}</a>
                    <form method="POST" action="{{ route('admin.purchases.supplier-quotation-entry.submit', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Submit quotation') }}</button></form>
                    @endcan
                    @can('purchases.supplier_quotation_entry.delete')
                    <form method="POST" action="{{ route('admin.purchases.supplier-quotation-entry.destroy', $record->doc_num) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">{{ __('Delete draft') }}</button></form>
                    @endcan
                @endif
                @if($type === 'supplier_selection' && $record->status === 'draft')
                    @can('purchases.supplier_selection.approve')
                    @can('purchases.prices.view')
                    <form method="POST" action="{{ route('admin.purchases.supplier-selection.approve', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Approve and generate POs') }}</button></form>
                    @endcan
                    @endcan
                @endif
                @if($type === 'purchase_order_change_request' && $record->status === 'pending')
                    @can('purchases.purchase_order_change_requests.approve')
                    @can('purchases.prices.view')
                    <form method="POST" action="{{ route('admin.purchases.purchase-order-change-requests.approve', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Approve change') }}</button></form>
                    @endcan
                    @endcan
                @endif
                @if($type === 'supply_order' && $record->status === 'draft' && $isOwnBranch)
                    @can('purchases.supply_orders.edit')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supply-orders.edit', $record) }}">{{ __('Edit draft') }}</a>@endcan
                    @can('purchases.supply_orders.delete')<form method="POST" action="{{ route('admin.purchases.supply-orders.destroy', $record) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">{{ __('Delete draft') }}</button></form>@endcan
                    @can('purchases.supply_orders.issue')<form method="POST" action="{{ route('admin.purchases.supply-orders.issue', $record) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Issue Supply Order') }}</button></form>@endcan
                @endif
                @if($type === 'supply_order' && in_array($record->status, ['issued', 'partially_received'], true))
                    @if((int) $record->branchStore?->branch_id === (int) ($activeBranchId ?? 0) && ! ($isAdministrativeBranch ?? false)) @can('purchases.goods_receipt_notes.create')<a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.goods-receipt-notes.create', $record) }}">{{ __('Create Goods Receipt') }}</a>@endcan @endif
                    @if((int) $record->branch_id === (int) ($activeBranchId ?? 0)) @can('purchases.supply_orders.cancel')<form method="POST" action="{{ route('admin.purchases.supply-orders.cancel', $record) }}" class="d-flex gap-2">@csrf<input class="form-control form-control-sm" name="cancel_reason" placeholder="{{ __('Cancellation reason') }}" required><button class="btn btn-danger btn-sm">{{ __('Cancel') }}</button></form>@endcan @endif
                @endif
                @if($type === 'goods_receipt' && $record->posting_status === 'posted')
                    @if($isAdministrativeBranch ?? false)
                    @can('purchase_invoices.create')
                    @can('purchases.prices.view')
                    <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.purchase-invoices.create', ['purchase_order' => $record->purchaseOrder->doc_num, 'receipts' => [$record->doc_num]]) }}">{{ __('Create supplier invoice') }}</a>
                    @endcan
                    @endcan
                    @endif
                    @if($isOwnBranch && ! ($isAdministrativeBranch ?? false))
                    @can('purchases.purchase_returns.create')
                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-returns.create', ['purchase_order' => $record->purchaseOrder->doc_num, 'receipt' => $record->doc_num]) }}">{{ __('Create purchase return') }}</a>
                    @endcan
                    @endif
                @endif
                @if($type === 'goods_receipt' && $record->status === 'draft' && $isOwnBranch && ! ($isAdministrativeBranch ?? false))
                    @if(in_array($record->qc_status, ['pending_inspection', 'not_required'], true))
                    @can('purchases.goods_receipt_notes.edit')
                    <a class="btn btn-primary btn-sm" href="{{ route('admin.purchases.goods-receipt-notes.edit', $record->doc_num) }}">{{ __('Edit draft') }}</a>
                    @endcan
                    @can('purchases.goods_receipt_notes.delete')
                    <form method="POST" action="{{ route('admin.purchases.goods-receipt-notes.destroy', $record->doc_num) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">{{ __('Delete draft') }}</button></form>
                    @endcan
                    @endif
                    @if($record->qc_status !== 'pending_inspection')
                    @can('purchases.goods_receipt_notes.post')
                    <form method="POST" action="{{ route('admin.purchases.goods-receipt-notes.post', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Post receipt') }}</button></form>
                    @endcan
                    @endif
                @endif
                @if($type === 'goods_receipt' && $isOwnBranch && ! ($isAdministrativeBranch ?? false) && in_array($record->posting_status, ['posted', 'partially_posted']))
                    @can('purchases.goods_receipt_notes.reverse')
                    <form method="POST" action="{{ route('admin.purchases.goods-receipt-notes.reverse', $record->doc_num) }}" class="d-flex gap-2">@csrf<input class="form-control form-control-sm" name="reversal_reason" placeholder="{{ __('Reversal reason') }}" required><button class="btn btn-danger btn-sm">{{ __('Reverse receipt') }}</button></form>
                    @endcan
                @endif
                @if($type === 'goods_receipt' && $isOwnBranch && ! ($isAdministrativeBranch ?? false) && $record->status === 'draft' && $record->posting_status === 'unposted' && $record->qc_status === 'pending_inspection')
                    @can('purchases.goods_receipt_inspection.create')
                    <a class="btn btn-warning btn-sm" href="{{ route('admin.purchases.goods-receipt-inspection.create', $record->doc_num) }}">{{ __('Inspect receipt') }}</a>
                    @endcan
                    @can('purchases.goods_receipt_notes.edit')
                    <form method="POST" action="{{ route('admin.purchases.goods-receipt-notes.cancel', $record->doc_num) }}" class="d-flex gap-2">
                        @csrf
                        <input class="form-control form-control-sm" name="cancel_reason" placeholder="{{ __('Cancellation reason') }}" required>
                        <button class="btn btn-danger btn-sm">{{ __('Cancel before QC') }}</button>
                    </form>
                    @endcan
                @endif
                @if($type === 'purchase_return' && $isOwnBranch && ! ($isAdministrativeBranch ?? false) && $record->status === 'draft')
                    @can('purchases.purchase_returns.edit')<a class="btn btn-primary btn-sm" href="{{ route('admin.purchases.purchase-returns.edit', $record) }}">{{ __('Edit draft') }}</a>@endcan
                    @can('purchases.purchase_returns.delete')<form method="POST" action="{{ route('admin.purchases.purchase-returns.destroy', $record) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">{{ __('Delete draft') }}</button></form>@endcan
                    @can('purchases.purchase_returns.post')
                    <form method="POST" action="{{ route('admin.purchases.purchase-returns.approve', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Approve and post return') }}</button></form>
                    @endcan
                @endif
                @if($type === 'purchase_return' && $isOwnBranch && ! ($isAdministrativeBranch ?? false) && $record->status === \Modules\Purchases\Models\PurchaseReturn::StatusPosted)
                    @can('purchases.purchase_returns.reverse')
                    <form method="POST" action="{{ route('admin.purchases.purchase-returns.reverse', $record->doc_num) }}" class="d-flex gap-2">
                        @csrf
                        <input class="form-control form-control-sm" name="reversal_reason" placeholder="{{ __('Reversal reason') }}" required>
                        <button class="btn btn-danger btn-sm">{{ __('Reverse return') }}</button>
                    </form>
                    @endcan
                @endif
                @if($type === 'supplier_payment' && $record->isDraft())
                    @can('supplier_payments.approve')
                    @can('purchases.prices.view')
                    <form method="POST" action="{{ route('admin.purchases.supplier-payments.approve', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Approve payment') }}</button></form>
                    @endcan
                    @endcan
                @endif
                @if($type === 'supplier_payment' && $record->isApproved())
                    @can('supplier_payments.cancel')
                    <form method="POST" action="{{ route('admin.purchases.supplier-payments.cancel', $record->doc_num) }}" class="d-flex gap-2">
                        @csrf
                        <input class="form-control form-control-sm" name="cancel_reason" placeholder="{{ __('Reversal reason') }}" required>
                        <button class="btn btn-danger btn-sm">{{ __('Reverse payment') }}</button>
                    </form>
                    @endcan
                @endif
            </div>
        </div>
        <div class="card-body py-3">
            @if($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif
            <div class="row g-3">
                <div class="col-md-3"><div class="text-600 fs-10">{{ __('Document') }}</div><div class="fw-semibold" dir="ltr">{{ $document }}</div></div>
                <div class="col-md-3"><div class="text-600 fs-10">{{ __('Status') }}</div><div>{{ __(str((string) $status)->replace('_', ' ')->title()->toString()) }}</div></div>
                @if($record->supplier ?? $record->cashVoucher ?? null)
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Supplier') }}</div><div>{{ $record->supplier?->name ?? '—' }}</div></div>
                @endif
                @if($record->purchaseOrder ?? null)
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Purchase Order') }}</div><div dir="ltr">{{ $record->purchaseOrder?->doc_num }}</div></div>
                @endif
                @if($type === 'goods_receipt' && $record->supplyOrder)
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Supply Order') }}</div><div dir="ltr">{{ $record->supplyOrder->doc_num }}</div></div>
                @endif
                @if($type === 'goods_receipt')
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('QC status') }}</div><div>{{ __(str($record->qc_status)->replace('_', ' ')->title()->toString()) }}</div></div>
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Posting status') }}</div><div>{{ __(str($record->posting_status)->replace('_', ' ')->title()->toString()) }}</div></div>
                @endif
                @if($type === 'supplier_payment')
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Payment method') }}</div><div>{{ __(str($record->payment_method)->replace('_', ' ')->title()->toString()) }}</div></div>
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Payment date') }}</div><div dir="ltr">{{ $record->payment_date?->format('Y-m-d') ?: '—' }}</div></div>
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Amount') }}</div><div class="fw-semibold" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($record->amount) }}</div></div>
                    @if($record->bankAccount)
                        <div class="col-md-6"><div class="text-600 fs-10">{{ __('Bank / branch / account') }}</div><div>{{ $record->bankAccount->bank?->name ?? $record->bankAccount->bank?->name_en }} / {{ $record->bankAccount->bank_branch_name ?: '—' }} / <span dir="ltr">{{ $record->bankAccount->account_number }}</span></div></div>
                    @endif
                    @if($record->cheque)
                        <div class="col-md-3"><div class="text-600 fs-10">{{ __('Cheque') }}</div><div dir="ltr">{{ $record->cheque->doc_num }} / {{ $record->cheque->cheque_number }}</div></div>
                        <div class="col-md-3"><div class="text-600 fs-10">{{ __('Cheque status') }}</div><div>{{ __(str($record->cheque->status)->replace('_', ' ')->title()->toString()) }}</div></div>
                    @endif
                @endif
                @if($type === 'supplier_quotation' && filled($record->source_doc_num))
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Source document') }}</div><div dir="ltr">{{ $record->source_doc_num }}</div></div>
                @endif
                @if($type === 'purchase_requisition')
                    @foreach([__('Company') => $record->company?->name, __('Branch') => $record->branch?->name, __('Warehouse') => $record->branchStore?->name, __('procurement.ui.requester_employee') => $record->requesterEmployee?->full_name ?: $record->requesterEmployee?->name, __('Submitted By') => $record->submittedBy?->name, __('Submitted At') => $record->submitted_at?->format('Y-m-d H:i'), __('Approved By') => $record->approvedBy?->name, __('Approved At') => $record->approved_at?->format('Y-m-d H:i'), __('Rejected By') => $record->rejectedBy?->name, __('Rejected At') => $record->rejected_at?->format('Y-m-d H:i'), __('Rejection reason') => $record->rejection_reason, __('Notes') => $record->notes] as $label => $value)
                        @if(filled($value))<div class="col-md-3"><div class="text-600 fs-10">{{ $label }}</div><div>{{ $value }}</div></div>@endif
                    @endforeach
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Department') }}</div><div>{{ $record->department ?: '—' }}</div></div>
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Required by') }}</div><div>{{ $record->required_by_date?->format('Y-m-d') ?: '—' }}</div></div>
                @endif
                @if($showPrices && isset($record->total_amount))
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Total') }}</div><div class="fw-semibold" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($record->total_amount) }}</div></div>
                @endif
            </div>
        </div>
    </div>

    @include('modules.purchases.procurement.document-cycle', ['record' => $record])

    @if($lineage->contains(fn (array $item): bool => (bool) auth()->user()?->can($item['permission'])))
        <div class="card mb-3">
            <div class="card-header py-2"><h6 class="mb-0">{{ __('Document lineage') }}</h6></div>
            <div class="card-body py-2 d-flex flex-wrap gap-2">
                @foreach($lineage as $item)
                    @can($item['permission'])
                        <a class="btn btn-falcon-default btn-sm" href="{{ $item['url'] }}">
                            <span class="text-600">{{ $item['label'] }}:</span> <span dir="ltr">{{ $item['doc_num'] }}</span>
                        </a>
                    @endcan
                @endforeach
            </div>
        </div>
    @endif

    @if($type === 'purchase_order_change_request')
        <div class="card mb-3"><div class="card-header py-2"><h6 class="mb-0">{{ __('Controlled change') }}</h6></div><div class="card-body py-3">
            <p><strong>{{ __('Reason') }}:</strong> {{ $record->reason }}</p>
            <div class="row g-3"><div class="col-lg-6"><h6>{{ __('Original values') }}</h6><pre class="bg-100 rounded p-3">{{ json_encode($originalChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div><div class="col-lg-6"><h6>{{ __('Requested values') }}</h6><pre class="bg-100 rounded p-3">{{ json_encode($requestedChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div>
        </div></div>
    @endif

    @php
        $lines = $record->lines ?? $record->allocations ?? collect();
        $lineAttachmentsSupported = in_array($type, [
            'purchase_requisition',
            'request_for_quotation',
            'supplier_quotation',
            'supply_order',
            'goods_receipt',
            'goods_receipt_inspection',
            'purchase_return',
        ], true);
    @endphp
    @if($lines->count())
        <div class="card">
            <div class="card-header py-2"><h6 class="mb-0">{{ __('Document lines') }}</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive procurement-lines-scroll">
                    <table class="table table-sm align-middle mb-0 procurement-lines-table">
                        <thead class="bg-100"><tr>
                            <th>#</th><th>{{ __('Item / Invoice') }}</th><th>{{ __('Source') }}</th><th class="text-end">{{ __('Quantity') }}</th>
                            @if($type === 'purchase_requisition')<th>{{ __('Approved Quantity') }}</th><th>{{ __('Ordered Quantity') }}</th><th>{{ __('Remaining to order') }}</th>@endif
                            @if($type === 'goods_receipt_inspection')<th class="text-end">{{ __('Accepted') }}</th><th class="text-end">{{ __('Rejected') }}</th>@endif
                            @if($showPrices)<th class="text-end">{{ __('Unit price') }}</th><th class="text-end">{{ __('Total') }}</th>@endif
                            <th>{{ __('Disposition / Notes') }}</th>
                            @if($lineAttachmentsSupported)<th>{{ __('Attachments') }}</th>@endif
                        </tr></thead>
                        <tbody>
                            @foreach($lines as $index => $line)
                                @php
                                    $item = $line->product?->name ?? $line->purchaseInvoice?->doc_num ?? '—';
                                    $source = $line->source_doc_num ?? $line->rfqLine?->requestForQuotation?->doc_num ?? $line->receiptLine?->receipt?->doc_num ?? $line->paymentSchedule?->public_id ?? '—';
                                    $quantity = $line->requested_quantity ?? $line->ordered_quantity ?? $line->delivered_quantity ?? $line->quantity ?? $line->offered_quantity ?? $line->selected_quantity ?? $line->inspected_quantity ?? $line->amount ?? 0;
                                    if ($commercial && ! $showPrices && isset($line->amount)) {
                                        $quantity = '—';
                                    }
                                @endphp
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $item }}</td>
                                    <td dir="ltr">{{ $source }}</td>
                                    <td class="text-end" dir="ltr">{{ is_numeric($quantity) ? app(\Modules\Core\Services\NumericFormatService::class)->format($quantity) : $quantity }}</td>
                                    @if($type === 'purchase_requisition')
                                        <td>@if($record->status === 'pending_approval' && ($isAdministrativeBranch ?? false) && auth()->user()?->can('purchases.purchase_requisition_approvals.approve'))
                                        <x-forms.numeric-input class="form-control-sm" form="purchase-request-approval" name="approved_quantities[{{ $line->public_id }}]" :scale="8" min="0" :max="$line->requested_quantity" step="0.00000001" :value="old('approved_quantities.'.$line->public_id, $line->requested_quantity)" />
                                        @else{{ app(\Modules\Core\Services\NumericFormatService::class)->format($line->approved_quantity) }}@endif</td><td>{{ app(\Modules\Core\Services\NumericFormatService::class)->format($line->orderedQuantity()) }}</td><td>{{ app(\Modules\Core\Services\NumericFormatService::class)->format($line->remainingToOrder()) }}</td>
                                    @endif
                                    @if($type === 'goods_receipt_inspection')
                                        <td class="text-end" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($line->accepted_quantity) }}</td>
                                        <td class="text-end" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($line->rejected_quantity) }}</td>
                                    @endif
                                    @if($showPrices)
                                        <td class="text-end" dir="ltr">{{ isset($line->unit_price) ? app(\Modules\Core\Services\NumericFormatService::class)->format($line->unit_price) : '—' }}</td>
                                        <td class="text-end" dir="ltr">{{ isset($line->line_total) ? app(\Modules\Core\Services\NumericFormatService::class)->format($line->line_total) : (isset($line->amount) ? app(\Modules\Core\Services\NumericFormatService::class)->format($line->amount) : '—') }}</td>
                                    @endif
                                    <td>{{ $line->disposition ?? $line->reason ?? $line->specification ?? $line->notes ?? '—' }}</td>
                                    @if($lineAttachmentsSupported)
                                        <td>
                                            @include('modules.purchases.procurement.line-attachments', [
                                                'attachmentLine' => $line,
                                                'attachmentCompanyId' => $record->company_id,
                                                'index' => $index,
                                                'lineAttachmentsReadonly' => true,
                                            ])
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @include('modules.purchases.procurement.attachments', ['attachmentRecord' => $record, 'attachmentsReadonly' => true, 'attachmentCollection' => $documentAttachmentCollection])
@endsection
