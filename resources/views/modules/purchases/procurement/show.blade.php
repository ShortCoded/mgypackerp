@extends('layouts.app')

@php
    $showPrices = $commercial && auth()->user()?->can('purchases.prices.view');
    $document = $record->doc_num ?? $record->cashVoucher?->doc_num;
    $status = $record->status ?? $record->cashVoucher?->status ?? '—';
    $title = str($type)->replace('_', ' ')->title().' '.$document;
    $printType = str($type)->replace('_', '-')->toString();
    $printPermission = match ($type) {
        'purchase_requisition' => 'purchases.purchase_requisitions.print',
        'request_for_quotation' => 'purchases.request_for_quotations.print',
        'supplier_quotation' => 'purchases.supplier_quotation_entry.print',
        'supplier_selection' => 'purchases.supplier_selection.print',
        'purchase_order_change_request' => 'purchases.purchase_order_change_requests.print',
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
        foreach ($record->requestsForQuotation ?? [] as $rfq) {
            $addLineage(__('RFQ'), $rfq, 'admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view');
        }
    } elseif ($type === 'request_for_quotation') {
        $addLineage(__('Purchase Requisition'), $record->requisition, 'admin.purchases.purchase-requisitions.show', 'purchases.purchase_requisitions.view');
        foreach ($record->quotations ?? [] as $quotation) {
            $addLineage(__('Supplier Quotation'), $quotation, 'admin.purchases.supplier-quotation-entry.show', 'purchases.supplier_quotation_entry.view');
        }
    } elseif ($type === 'supplier_quotation') {
        $addLineage(__('RFQ'), $record->requestForQuotation, 'admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view');
    } elseif ($type === 'supplier_selection') {
        $addLineage(__('RFQ'), $record->requestForQuotation, 'admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view');
        foreach (($record->lines ?? collect())->pluck('purchaseOrder')->filter()->unique('id') as $order) {
            $addLineage(__('Purchase Order'), $order, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
        }
    } elseif ($type === 'purchase_order_change_request') {
        $addLineage(__('Purchase Order'), $record->purchaseOrder, 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
    } elseif ($type === 'goods_receipt') {
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
        <div class="card-header d-flex flex-wrap align-items-start justify-content-between gap-2">
            <div>
                <h5 class="mb-1">{{ $title }}</h5>
                <span class="badge badge-subtle-secondary">{{ str((string) $status)->replace('_', ' ')->title() }}</span>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if($printPermission && auth()->user()?->can($printPermission))
                <a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ $printUrl }}">
                    <span class="fas fa-print me-1"></span>{{ __('Print') }}
                </a>
                @endif

                @if($type === 'purchase_requisition' && $record->status === 'draft')
                    @can('purchases.purchase_requisitions.edit')
                    <form method="POST" action="{{ route('admin.purchases.purchase-requisitions.submit', $record->doc_num) }}">@csrf<button class="btn btn-falcon-primary btn-sm">{{ __('Submit for approval') }}</button></form>
                    @endcan
                @endif
                @if($type === 'purchase_requisition' && $record->status === 'pending_approval')
                    @can('purchases.purchase_requisition_approvals.approve')
                    <form method="POST" action="{{ route('admin.purchases.purchase-requisitions.approve', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Approve') }}</button></form>
                    @endcan
                @endif
                @if($type === 'purchase_requisition' && in_array($record->status, ['approved', 'partially_converted'], true))
                    @can('purchases.request_for_quotations.create')
                    <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.purchases.request-for-quotations.create', $record->doc_num) }}">{{ __('Create RFQ') }}</a>
                    @endcan
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
                    <form method="POST" action="{{ route('admin.purchases.supplier-quotation-entry.submit', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Submit quotation') }}</button></form>
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
                @if($type === 'goods_receipt' && $record->qc_status === 'pending_inspection')
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
                @if($type === 'purchase_return' && $record->status === 'draft')
                    @can('purchases.purchase_returns.approve')
                    <form method="POST" action="{{ route('admin.purchases.purchase-returns.approve', $record->doc_num) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Approve and post return') }}</button></form>
                    @endcan
                @endif
                @if($type === 'purchase_return' && $record->status === \Modules\Purchases\Models\PurchaseReturn::StatusPosted)
                    @can('purchases.purchase_returns.approve')
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
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif
            <div class="row g-3">
                <div class="col-md-3"><div class="text-600 fs-10">{{ __('Document') }}</div><div class="fw-semibold" dir="ltr">{{ $document }}</div></div>
                <div class="col-md-3"><div class="text-600 fs-10">{{ __('Status') }}</div><div>{{ str((string) $status)->replace('_', ' ')->title() }}</div></div>
                @if($record->supplier ?? $record->cashVoucher ?? null)
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Supplier') }}</div><div>{{ $record->supplier?->name ?? '—' }}</div></div>
                @endif
                @if($record->purchaseOrder ?? null)
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Purchase Order') }}</div><div dir="ltr">{{ $record->purchaseOrder?->doc_num }}</div></div>
                @endif
                @if($type === 'goods_receipt')
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('QC status') }}</div><div>{{ str($record->qc_status)->replace('_', ' ')->title() }}</div></div>
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Posting status') }}</div><div>{{ str($record->posting_status)->replace('_', ' ')->title() }}</div></div>
                @endif
                @if($type === 'supplier_payment')
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Payment method') }}</div><div>{{ str($record->payment_method)->title() }}</div></div>
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Payment date') }}</div><div dir="ltr">{{ $record->payment_date?->format('Y-m-d') ?: '—' }}</div></div>
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Amount') }}</div><div class="fw-semibold" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($record->amount) }}</div></div>
                    @if($record->bankAccount)
                        <div class="col-md-6"><div class="text-600 fs-10">{{ __('Bank / branch / account') }}</div><div>{{ $record->bankAccount->bank?->name ?? $record->bankAccount->bank?->name_en }} / {{ $record->bankAccount->bank_branch_name ?: '—' }} / <span dir="ltr">{{ $record->bankAccount->account_number }}</span></div></div>
                    @endif
                    @if($record->cheque)
                        <div class="col-md-3"><div class="text-600 fs-10">{{ __('Cheque') }}</div><div dir="ltr">{{ $record->cheque->doc_num }} / {{ $record->cheque->cheque_number }}</div></div>
                        <div class="col-md-3"><div class="text-600 fs-10">{{ __('Cheque status') }}</div><div>{{ str($record->cheque->status)->title() }}</div></div>
                    @endif
                @endif
                @if($type === 'purchase_requisition')
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Department') }}</div><div>{{ $record->department ?: '—' }}</div></div>
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Required by') }}</div><div>{{ $record->required_by_date?->format('Y-m-d') ?: '—' }}</div></div>
                @endif
                @if($showPrices && isset($record->total_amount))
                    <div class="col-md-3"><div class="text-600 fs-10">{{ __('Total') }}</div><div class="fw-semibold" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($record->total_amount) }}</div></div>
                @endif
            </div>
        </div>
    </div>

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
        <div class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Controlled change') }}</h6></div><div class="card-body">
            <p><strong>{{ __('Reason') }}:</strong> {{ $record->reason }}</p>
            <div class="row g-3"><div class="col-lg-6"><h6>{{ __('Original values') }}</h6><pre class="bg-100 rounded p-3">{{ json_encode($originalChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div><div class="col-lg-6"><h6>{{ __('Requested values') }}</h6><pre class="bg-100 rounded p-3">{{ json_encode($requestedChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div>
        </div></div>
    @endif

    @php
        $lines = $record->lines ?? $record->allocations ?? collect();
    @endphp
    @if($lines->count())
        <div class="card">
            <div class="card-header"><h6 class="mb-0">{{ __('Document lines') }}</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive procurement-lines-scroll">
                    <table class="table table-sm align-middle mb-0 procurement-lines-table">
                        <thead class="bg-100"><tr>
                            <th>#</th><th>{{ __('Item / Invoice') }}</th><th>{{ __('Source') }}</th><th class="text-end">{{ __('Quantity') }}</th>
                            @if($type === 'goods_receipt_inspection')<th class="text-end">{{ __('Accepted') }}</th><th class="text-end">{{ __('Rejected') }}</th>@endif
                            @if($showPrices)<th class="text-end">{{ __('Unit price') }}</th><th class="text-end">{{ __('Total') }}</th>@endif
                            <th>{{ __('Disposition / Notes') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach($lines as $index => $line)
                                @php
                                    $item = $line->product?->name ?? $line->purchaseInvoice?->doc_num ?? '—';
                                    $source = $line->source_doc_num ?? $line->rfqLine?->requestForQuotation?->doc_num ?? $line->receiptLine?->receipt?->doc_num ?? $line->paymentSchedule?->public_id ?? '—';
                                    $quantity = $line->requested_quantity ?? $line->quantity ?? $line->offered_quantity ?? $line->selected_quantity ?? $line->inspected_quantity ?? $line->amount ?? 0;
                                    if ($commercial && ! $showPrices && isset($line->amount)) {
                                        $quantity = '—';
                                    }
                                @endphp
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $item }}</td>
                                    <td dir="ltr">{{ $source }}</td>
                                    <td class="text-end" dir="ltr">{{ is_numeric($quantity) ? app(\Modules\Core\Services\NumericFormatService::class)->format($quantity) : $quantity }}</td>
                                    @if($type === 'goods_receipt_inspection')
                                        <td class="text-end" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($line->accepted_quantity) }}</td>
                                        <td class="text-end" dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($line->rejected_quantity) }}</td>
                                    @endif
                                    @if($showPrices)
                                        <td class="text-end" dir="ltr">{{ isset($line->unit_price) ? app(\Modules\Core\Services\NumericFormatService::class)->format($line->unit_price) : '—' }}</td>
                                        <td class="text-end" dir="ltr">{{ isset($line->line_total) ? app(\Modules\Core\Services\NumericFormatService::class)->format($line->line_total) : (isset($line->amount) ? app(\Modules\Core\Services\NumericFormatService::class)->format($line->amount) : '—') }}</td>
                                    @endif
                                    <td>{{ $line->disposition ?? $line->reason ?? $line->specification ?? $line->notes ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @can('file_manager.view')
    @if(($record->attachmentUsages ?? collect())->isNotEmpty())
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">{{ __('Attachments') }}</h6></div>
            <div class="list-group list-group-flush">
                @foreach($record->attachmentUsages as $usage)
                    @if($usage->file)
                        <div class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div><span class="fas fa-paperclip text-500 me-1"></span>{{ $usage->file->original_name }} <span class="text-600 fs-11" dir="ltr">{{ $usage->file->doc_num }}</span></div>
                            <div class="d-flex gap-2">
                                @if($usage->file->isPreviewable())
                                    <a class="btn btn-falcon-default btn-sm" target="_blank" rel="noopener" href="{{ route('admin.file-manager.files.preview', $usage->file->doc_num) }}">{{ __('Preview') }}</a>
                                @endif
                                <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.file-manager.files.download', $usage->file->doc_num) }}">{{ __('Download') }}</a>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
    @endcan
@endsection
