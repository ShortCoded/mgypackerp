@extends('layouts.app')

@section('title', $title)

@section('content')
    @php
        $showPrices = $commercial && auth()->user()?->can('purchases.prices.view');
        $createUrl = match ($screen) {
            'purchase_requisitions' => route('admin.purchases.purchase-requisitions.create'),
            'purchase_returns' => route('admin.purchases.purchase-returns.create'),
            'supplier_payments' => route('admin.purchases.supplier-payments.create'),
            'goods_receipt_inspections' => route('admin.purchases.goods-receipt-inspection.choose-source'),
            default => null,
        };
        $showUrl = function ($record) use ($screen) {
            return match ($screen) {
                'purchase_requisitions', 'purchase_requisition_approvals' => route('admin.purchases.purchase-requisitions.show', $record->doc_num),
                'request_for_quotations', 'quotation_comparisons' => route('admin.purchases.request-for-quotations.show', $record->doc_num),
                'supplier_quotations' => route('admin.purchases.supplier-quotation-entry.show', $record->doc_num),
                'supplier_selections' => route('admin.purchases.supplier-selection.show', $record->doc_num),
                'purchase_order_change_requests' => route('admin.purchases.purchase-order-change-requests.show', $record->doc_num),
                'goods_receipts' => route('admin.purchases.goods-receipt-notes.show', $record->doc_num),
                'goods_receipt_inspections' => route('admin.purchases.goods-receipt-inspection.show', $record->doc_num),
                'purchase_returns', 'supplier_debit_notes' => route('admin.purchases.purchase-returns.show', $record->doc_num),
                'supplier_payments', 'supplier_advances' => route('admin.purchases.supplier-payments.show', $record->doc_num),
                default => null,
            };
        };
    @endphp

    @if($screen === 'purchase_requisitions')
    @can('purchase_orders.create') @can('purchases.prices.view')
    <form class="card mb-3" method="GET" action="{{ route('admin.purchases.purchase-orders.create') }}"><div class="card-body row g-3 align-items-end">
        <div class="col-md-9"><label class="form-label">{{ __('Combine approved purchase requests') }}</label><x-forms.select class="form-select" name="purchase_requisition_doc_nums[]" multiple required>
            @foreach($records as $requestRecord) @if(in_array($requestRecord->status, ['approved', 'partially_converted']))<option value="{{ $requestRecord->doc_num }}">{{ $requestRecord->doc_num }} / {{ $requestRecord->branchStore?->name }}</option>@endif @endforeach
        </x-forms.select><div class="form-text">{{ __('Select purchase requests for the same receiving warehouse.') }}</div></div>
        <div class="col-md-3"><button class="btn btn-primary">{{ __('Create Purchase Order') }}</button></div>
    </div></form>
    @endcan @endcan
    @endif
    <div class="card erp-datatable-card">
        <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h5 class="mb-1">{{ $title }}</h5>
                <div class="text-600 fs-10">{{ __('Scoped to the active company, branch, and financial period.') }}</div>
            </div>
            @if($createUrl)
                <a class="btn btn-falcon-primary btn-sm" href="{{ $createUrl }}">
                    <span class="fas fa-plus me-1"></span>{{ __('Create') }}
                </a>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="erp-datatable-scroll table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 procurement-list-table">
                    <thead class="bg-100 text-900">
                        <tr>
                            <th>{{ __('Document') }}</th>
                            <th>{{ __('Date / Source') }}</th>
                            <th>{{ __('Supplier / Item') }}</th>
                            <th class="text-end">{{ __('Quantity / Lines') }}</th>
                            @if($showPrices)
                                <th class="text-end">{{ __('Commercial value') }}</th>
                            @endif
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($records as $record)
                            @php
                                $document = $record->doc_num
                                    ?? $record->purchaseOrder?->doc_num
                                    ?? $record->requisition?->doc_num
                                    ?? $record->purchaseInvoice?->doc_num
                                    ?? $record->cashVoucher?->doc_num
                                    ?? $record->source_doc_num
                                    ?? $record->public_id;
                                $date = $record->request_date
                                    ?? $record->issue_date
                                    ?? $record->quotation_date
                                    ?? $record->selection_date
                                    ?? $record->scheduled_date
                                    ?? $record->document_date
                                    ?? $record->inspection_at
                                    ?? $record->return_date
                                    ?? $record->invoice_date
                                    ?? $record->allocated_at
                                    ?? $record->transaction_date
                                    ?? $record->cashVoucher?->voucher_date;
                                $party = $record->supplier?->name
                                    ?? $record->quotation?->supplier?->name
                                    ?? $record->purchaseOrder?->supplier?->name
                                    ?? $record->receipt?->supplier?->name
                                    ?? $record->purchaseInvoice?->supplier?->name
                                    ?? $record->product?->name
                                    ?? '—';
                                $quantity = $record->total_quantity
                                    ?? $record->scheduled_quantity
                                    ?? $record->requested_quantity
                                    ?? $record->ordered_quantity
                                    ?? $record->delivered_quantity
                                    ?? $record->quantity
                                    ?? ((float) ($record->quantity_in ?? 0) > 0 ? $record->quantity_in : ($record->quantity_out ?? null))
                                    ?? $record->lines_count
                                    ?? $record->allocations_count
                                    ?? '—';
                                if ($commercial && ! $showPrices && isset($record->amount)) {
                                    $quantity = '—';
                                }
                                $commercialValue = $record->total_amount
                                    ?? $record->line_total
                                    ?? $record->amount
                                    ?? $record->cashVoucher?->amount;
                                $status = $record->status
                                    ?? $record->qc_status
                                    ?? $record->transaction_type
                                    ?? $record->cashVoucher?->status
                                    ?? '—';
                                $url = $showUrl($record);
                            @endphp
                            <tr>
                                <td class="fw-semibold" dir="ltr">{{ $document }}</td>
                                <td>{{ $date ? app(\Modules\Core\Services\DateFormatService::class)->formatDate($date, '—') : '—' }}</td>
                                <td>{{ $party }}</td>
                                <td class="text-end" dir="ltr">{{ is_numeric($quantity) ? app(\Modules\Core\Services\NumericFormatService::class)->format($quantity) : $quantity }}</td>
                                @if($showPrices)
                                    <td class="text-end" dir="ltr">{{ $commercialValue !== null ? app(\Modules\Core\Services\NumericFormatService::class)->format($commercialValue) : '—' }}</td>
                                @endif
                                <td><x-status-indicator :status="$status" /></td>
                                <td class="text-end">
                                    @if($url)
                                        <a class="btn btn-falcon-default btn-sm" href="{{ $url }}">{{ __('View') }}</a>
                                    @else
                                        <span class="text-500">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-500 py-5" colspan="{{ $showPrices ? 7 : 6 }}">{{ __('No records found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($records->hasPages())
            <div class="card-footer">{{ $records->links() }}</div>
        @endif
    </div>
@endsection
