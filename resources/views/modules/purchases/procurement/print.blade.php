@extends('reports.layouts.pdf')

@section('report')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $document = $record->doc_num ?? $record->cashVoucher?->doc_num;
        $documentTitle = match (true) {
            $type === 'goods-receipt' => app(\Modules\Core\Services\Reports\ReportPdfService::class)->stockDocumentTitle($record),
            $type === 'purchase-return' && $record->purchase_invoice_id !== null => __('procurement.documents.types.purchase-return-invoiced'),
            default => __('procurement.documents.types.'.$type),
        };
        $documentStatus = (string) ($record->status ?? $record->qc_status ?? $record->cashVoucher?->status ?? '');
        $documentStatusLabel = $documentStatus !== '' ? __('procurement.statuses.'.$documentStatus) : '';
        $lines = match ($type) {
            'quotation-comparison' => $record->quotations->flatMap->lines,
            'purchase-order-delivery-schedule' => $record->lines->flatMap->deliverySchedules,
            'supplier-payment' => $record->allocations,
            default => $record->lines ?? collect(),
        };
        $isRequest = $type === 'purchase-requisition';
        $isReceipt = $type === 'goods-receipt';
        $isReturn = $type === 'purchase-return';
        $supplier = $record->supplier ?? $record->purchaseOrder?->supplier ?? $record->supplyOrder?->supplier;
        $showReceiptReference = $isReturn && $lines->pluck('receipt_line_id')->map(fn ($id) => $lines->firstWhere('receipt_line_id', $id)?->receiptLine?->receipt_id)->unique()->count() > 1;
        $showRejected = $isReceipt && $lines->contains(fn ($line) => (float) $line->rejected_quantity > 0 || $line->product?->requiresIncomingInspection());
        $isItemDocument = $type !== 'supplier-payment';
        $showUnitOrDueDate = $isItemDocument || $lines->contains(fn ($line) => filled($line->paymentSchedule?->due_date));
        $originalChangeValues = $record->original_values ?? [];
        $requestedChangeValues = $record->requested_values ?? [];
        if ($type === 'purchase-order-change-request' && ! $showPrices) {
            $originalChangeValues['lines'] = collect($originalChangeValues['lines'] ?? [])->map(fn ($line) => collect($line)->except('unit_price')->all())->all();
            $requestedChangeValues['lines'] = collect($requestedChangeValues['lines'] ?? [])->map(fn ($line) => collect($line)->except('unit_price')->all())->all();
        }
    @endphp

    @include('reports.partials.company-identity')

    <div class="document-title-row">
        <h1>{{ $documentTitle }}</h1>
        <strong dir="ltr">{{ $document }}</strong>
        @if($documentStatusLabel !== '')
            <span class="document-status">{{ $documentStatusLabel }}</span>
        @endif
    </div>

    <table class="document-meta-table">
        <tbody>
            <tr><td><strong>{{ __('Date') }}</strong></td><td>{{ $dates->formatDate($record->request_date ?? $record->document_date ?? $record->return_date ?? $record->payment_date ?? $record->quotation_date ?? $record->selection_date ?? $record->issue_date, '—') }}</td></tr>
            @if($record->branch ?? null)<tr><td><strong>{{ __('Branch') }}</strong></td><td>{{ $record->branch->name }}</td></tr>@endif
            @if($record->branchStore ?? null)<tr><td><strong>{{ __('Warehouse') }}</strong></td><td>{{ $record->branchStore->name }}</td></tr>@endif
            @if($isRequest && $record->requesterEmployee)
                <tr><td><strong>{{ __('procurement.ui.requester_employee') }}</strong></td><td>{{ $record->requesterEmployee->full_name ?: $record->requesterEmployee->name }}</td></tr>
            @endif
            @if($isReturn)<tr><td><strong>{{ __('Return reason') }}</strong></td><td>{{ __(str($record->reason_code)->replace('_', ' ')->title()->toString()) }}</td></tr>@endif
            @if($supplier)
                <tr><td><strong>{{ __('procurement.fields.supplier') }}</strong></td><td>{{ $supplier->doc_num }} / {{ $supplier->name }}</td></tr>
            @endif
            @if($type === 'supplier-quotation' && filled($record->source_doc_num))
                <tr><td><strong>{{ __('Source document') }}</strong></td><td dir="ltr">{{ $record->source_doc_num }}</td></tr>
            @endif
            @if($record->purchaseOrder ?? null)
                <tr><td><strong>{{ __('procurement.fields.purchase_order') }}</strong></td><td dir="ltr">{{ $record->purchaseOrder?->doc_num }}</td></tr>
            @endif
            @if($record->supplyOrder ?? null)
                <tr><td><strong>{{ __('Supply Order') }}</strong></td><td dir="ltr">{{ $record->supplyOrder?->doc_num }}</td></tr>
            @endif
            @if($type === 'supply-order')
                <tr><td><strong>{{ __('Source document') }}</strong></td><td dir="ltr">{{ $record->source_doc_num }}</td></tr>
                @if($record->expected_delivery_date)<tr><td><strong>{{ __('Expected delivery date') }}</strong></td><td dir="ltr">{{ $dates->formatDate($record->expected_delivery_date) }}</td></tr>@endif
            @endif
            @if($record->requisition ?? null)
                <tr><td><strong>{{ __('procurement.fields.purchase_requisition') }}</strong></td><td dir="ltr">{{ $record->requisition?->doc_num }}</td></tr>
            @endif
            @if($record->receipt ?? null)
                <tr><td><strong>{{ __('procurement.fields.goods_receipt') }}</strong></td><td dir="ltr">{{ $record->receipt?->doc_num }}</td></tr>
            @endif
            @if($type === 'goods-receipt-inspection')
                @php
                    $inspectionStore = $record->purchaseOrder?->branchStore ?? $record->supplyOrder?->branchStore;
                    $inspectionBranch = $record->branch ?? $inspectionStore?->branch;
                @endphp
                <tr><td><strong>{{ __('Branch') }}</strong></td><td>{{ $inspectionBranch?->name ?: '—' }}</td></tr>
                <tr><td><strong>{{ __('Warehouse') }}</strong></td><td>{{ $inspectionStore?->name ?: '—' }}</td></tr>
                <tr><td><strong>{{ __('Source document') }}</strong></td><td dir="ltr">{{ $record->source_doc_num ?: '—' }}</td></tr>
                <tr><td><strong>{{ __('Inspection result') }}</strong></td><td>{{ __(str($record->result)->replace('_', ' ')->title()->toString()) }}</td></tr>
                <tr><td><strong>{{ __('Warehouse receipt') }}</strong></td><td dir="ltr">{{ $record->receipt?->doc_num ?: __('Not created yet') }}</td></tr>
            @endif
            @if($isReturn)
                <tr><td><strong>{{ __('Source invoice') }}</strong></td><td dir="ltr">{{ $record->purchaseInvoice?->doc_num ?: '—' }}</td></tr>
                <tr><td><strong>{{ __('Financial treatment') }}</strong></td><td>{{ $record->purchase_invoice_id ? __('Supplier debit note') : __('Inventory / GRNI adjustment only') }}</td></tr>
            @endif
            @if($type === 'goods-receipt')
                @if(filled($record->supplier_delivery_note))<tr><td><strong>{{ __('procurement.fields.supplier_delivery_note') }}</strong></td><td>{{ $record->supplier_delivery_note }}</td></tr>@endif
                <tr><td><strong>{{ __('procurement.fields.qc_status') }}</strong></td><td>{{ __('procurement.statuses.'.$record->qc_status) }}</td></tr>
            @endif
            @if($type === 'supplier-payment')
                <tr><td><strong>{{ __('procurement.fields.payment_date') }}</strong></td><td dir="ltr">{{ $dates->formatDate($record->payment_date, '—') }}</td></tr>
                <tr><td><strong>{{ __('procurement.fields.payment_method') }}</strong></td><td>{{ __('procurement.statuses.'.$record->payment_method) }}</td></tr>
                <tr><td><strong>{{ __('procurement.fields.amount') }}</strong></td><td dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</td></tr>
                @if($record->bankAccount)
                    <tr><td><strong>{{ __('procurement.fields.bank_account') }}</strong></td><td>{{ $record->bankAccount->bank?->name ?? $record->bankAccount->bank?->name_en }} / {{ $record->bankAccount->bank_branch_name ?: '—' }} / <span dir="ltr">{{ $record->bankAccount->account_number }}</span></td></tr>
                @endif
                @if($record->cheque)
                    <tr><td><strong>{{ __('procurement.fields.cheque') }}</strong></td><td dir="ltr">{{ $record->cheque->doc_num }} / {{ $record->cheque->cheque_number }}</td></tr>
                    <tr><td><strong>{{ __('procurement.fields.cheque_due_date_status') }}</strong></td><td><span dir="ltr">{{ $dates->formatDate($record->cheque->due_date, '—') }}</span> / {{ __('procurement.statuses.'.$record->cheque->status) }}</td></tr>
                @endif
            @endif
        </tbody>
    </table>

    @if($lines->count())
        <table class="report-table procurement-document-table">
            <thead><tr>
                <th>#</th>@if($isItemDocument)<th>{{ __('Item Code') }}</th>@endif<th>{{ $isItemDocument ? __('Item Name') : __('Invoice') }}</th>@if($showUnitOrDueDate)<th>{{ $isItemDocument ? __('Unit') : __('Due date') }}</th>@endif
                @if($showReceiptReference)<th>{{ __('Goods Receipt') }}</th>@endif
                @if($isReceipt)<th>{{ __('Ordered') }}</th><th>{{ __('Previously received') }}</th>@endif
                @if($isReturn)<th>{{ __('Received') }}</th><th>{{ __('Previously returned') }}</th>@endif
                <th>{{ $isReceipt ? __('Received now') : ($isItemDocument ? __('Quantity') : __('Paid amount')) }}</th>
                @if($isReceipt || $type === 'goods-receipt-inspection')<th>{{ __('Accepted') }}</th>@endif
                @if($showRejected || $type === 'goods-receipt-inspection')<th>{{ __('Rejected') }}</th>@endif
                @if($showPrices && $isItemDocument && ! $isRequest && ! $isReceipt)<th>{{ __('Unit price') }}</th><th>{{ __('Total') }}</th>@endif
                <th>{{ __('Notes') }}</th>
            </tr></thead>
            <tbody>
                @foreach($lines as $index => $line)
                    @php
                        $product = $line->product ?? $line->purchaseOrderLine?->product;
                        $quantity = $line->requested_quantity ?? $line->ordered_quantity ?? $line->delivered_quantity ?? $line->quantity ?? $line->offered_quantity ?? $line->selected_quantity ?? $line->scheduled_quantity ?? $line->inspected_quantity ?? $line->amount ?? 0;
                        $previouslyReceived = $isReceipt ? \Modules\Inventory\Models\UnpricedInventoryReceiptLine::query()
                            ->when($line->supply_order_line_id, fn ($query) => $query->where('supply_order_line_id', $line->supply_order_line_id), fn ($query) => $query->where('purchase_order_line_id', $line->purchase_order_line_id))
                            ->where('receipt_id', '<', $record->getKey())->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))->sum('accepted_quantity') : 0;
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        @if($isItemDocument)<td dir="ltr">{{ $product?->doc_num }}</td>@endif
                        <td>
                            @if($isItemDocument)
                                @include('reports.partials.item-details', ['line' => $line, 'product' => $product, 'showItemCode' => false])
                            @else
                                {{ $line->purchaseInvoice?->doc_num ?? '—' }}
                            @endif
                            @if(filled($line->specification))
                                <div class="document-item-details">{{ $line->specification }}</div>
                            @endif
                        </td>
                        @if($showUnitOrDueDate)<td>{{ $line->unit?->name ?? $line->paymentSchedule?->due_date?->format('Y-m-d') ?? '—' }}</td>@endif
                        @if($showReceiptReference)<td dir="ltr">{{ $line->receiptLine?->receipt?->doc_num }}</td>@endif
                        @if($isReceipt)<td dir="ltr">{{ $numbers->format($line->supplyOrderLine?->ordered_quantity ?? $line->purchaseOrderLine?->ordered_quantity) }}</td><td dir="ltr">{{ $numbers->format($previouslyReceived) }}</td>@endif
                        @if($isReturn)
                        <td dir="ltr">{{ $numbers->format($line->receiptLine?->accepted_quantity) }}</td>
                        <td dir="ltr">{{ $numbers->format(\Modules\Purchases\Models\PurchaseReturnLine::query()->where('receipt_line_id', $line->receipt_line_id)->where('purchase_return_id', '<', $record->getKey())->whereHas('purchaseReturn', fn ($query) => $query->where('status', 'posted'))->sum('quantity')) }}</td>
                        @endif
                        <td dir="ltr">{{ $numbers->format($quantity) }}</td>
                        @if($isReceipt || $type === 'goods-receipt-inspection')<td dir="ltr">{{ $numbers->format($line->accepted_quantity) }}</td>@endif
                        @if($showRejected || $type === 'goods-receipt-inspection')<td dir="ltr">{{ $numbers->format($line->rejected_quantity) }}</td>@endif
                        @if($showPrices && $isItemDocument && ! $isRequest && ! $isReceipt)<td dir="ltr">{{ isset($line->unit_price) ? $numbers->format($line->unit_price) : '—' }}</td><td dir="ltr">{{ $numbers->format($line->line_total ?? $line->amount ?? 0) }}</td>@endif
                        <td>{{ $line->notes ?? $line->reason ?? $line->result ?? $line->disposition }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($showPrices && isset($record->total_amount))<table class="document-totals-table"><tr><th>{{ __('Grand total') }}</th><td dir="ltr">{{ $numbers->format($record->total_amount) }}</td></tr></table>@endif
    @endif
    @if(filled($record->notes))<div class="document-notes"><strong>{{ __('Notes') }}</strong><br>{{ $record->notes }}</div>@endif

    @if($type === 'purchase-order-change-request')
        <table class="document-meta-table"><tr><td><strong>{{ __('procurement.fields.original_values') }}</strong><pre>{{ json_encode($originalChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td><td><strong>{{ __('procurement.fields.approved_requested_values') }}</strong><pre>{{ json_encode($requestedChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td></tr></table>
    @endif

    @if($showPrices && in_array($type, ['purchase-return', 'supplier-payment'])) @include('reports.partials.amount-in-words') @endif
    @if($isRequest)
        @include('reports.partials.document-signatures', ['signatureNames' => [__('procurement.ui.requester_employee') => $record->requesterEmployee?->full_name ?: $record->requesterEmployee?->name, __('Warehouse keeper') => null, __('Approved By') => $record->approvedBy?->name]])
    @else
        @include('reports.partials.document-signatures')
    @endif
    @include('reports.partials.company-authorization')

@endsection
