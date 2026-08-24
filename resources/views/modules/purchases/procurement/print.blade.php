@extends('reports.layouts.pdf')

@section('report')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $document = $record->doc_num ?? $record->cashVoucher?->doc_num;
        $documentTitle = __('procurement.documents.types.'.$type);
        $documentStatus = (string) ($record->status ?? $record->qc_status ?? $record->cashVoucher?->status ?? '');
        $documentStatusLabel = $documentStatus !== '' ? __('procurement.statuses.'.$documentStatus) : '';
        $lines = match ($type) {
            'quotation-comparison' => $record->quotations->flatMap->lines,
            'purchase-order-delivery-schedule' => $record->lines->flatMap->deliverySchedules,
            'supplier-payment' => $record->allocations,
            default => $record->lines ?? collect(),
        };
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
            @if($record->supplier ?? null)
                <tr><td><strong>{{ __('procurement.fields.supplier') }}</strong></td><td>{{ $record->supplier?->doc_num }} / {{ $record->supplier?->name }}</td></tr>
            @endif
            @if($record->purchaseOrder ?? null)
                <tr><td><strong>{{ __('procurement.fields.purchase_order') }}</strong></td><td dir="ltr">{{ $record->purchaseOrder?->doc_num }}</td></tr>
            @endif
            @if($record->requisition ?? null)
                <tr><td><strong>{{ __('procurement.fields.purchase_requisition') }}</strong></td><td dir="ltr">{{ $record->requisition?->doc_num }}</td></tr>
            @endif
            @if($record->receipt ?? null)
                <tr><td><strong>{{ __('procurement.fields.goods_receipt') }}</strong></td><td dir="ltr">{{ $record->receipt?->doc_num }}</td></tr>
            @endif
            @if($type === 'goods-receipt')
                <tr><td><strong>{{ __('procurement.fields.supplier_delivery_note') }}</strong></td><td>{{ $record->supplier_delivery_note ?: '—' }}</td></tr>
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
            <thead><tr><th>#</th><th>{{ __('procurement.fields.item_invoice') }}</th><th>{{ __('procurement.fields.source_unit') }}</th><th class="text-end">{{ __('procurement.fields.quantity') }}</th>@if($type === 'goods-receipt-inspection')<th class="text-end">{{ __('procurement.fields.accepted') }}</th><th class="text-end">{{ __('procurement.fields.rejected') }}</th>@endif @if($showPrices)<th class="text-end">{{ __('procurement.fields.unit_price') }}</th><th class="text-end">{{ __('procurement.fields.total') }}</th>@endif <th>{{ __('procurement.fields.notes_result') }}</th></tr></thead>
            <tbody>
                @foreach($lines as $index => $line)
                    @php
                        $item = $line->product?->name ?? $line->purchaseOrderLine?->product?->name ?? $line->purchaseInvoice?->doc_num ?? '—';
                        $source = $line->unit?->name ?? $line->quotation?->supplier?->name ?? $line->paymentSchedule?->due_date?->format('Y-m-d') ?? '—';
                        $quantity = $line->requested_quantity ?? $line->quantity ?? $line->offered_quantity ?? $line->selected_quantity ?? $line->scheduled_quantity ?? $line->inspected_quantity ?? $line->amount ?? 0;
                        if ($type === 'supplier-payment' && ! $showPrices) { $quantity = '—'; }
                    @endphp
                    <tr><td>{{ $index + 1 }}</td><td>{{ $item }}</td><td>{{ $source }}</td><td class="text-end" dir="ltr">{{ is_numeric($quantity) ? $numbers->format($quantity) : $quantity }}</td>@if($type === 'goods-receipt-inspection')<td class="text-end" dir="ltr">{{ $numbers->format($line->accepted_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->rejected_quantity) }}</td>@endif @if($showPrices)<td class="text-end" dir="ltr">{{ isset($line->unit_price) ? $numbers->format($line->unit_price) : '—' }}</td><td class="text-end" dir="ltr">{{ isset($line->line_total) ? $numbers->format($line->line_total) : (isset($line->amount) ? $numbers->format($line->amount) : '—') }}</td>@endif <td>{{ $line->result ?? $line->disposition ?? $line->reason ?? $line->notes ?? '—' }}</td></tr>
                @endforeach
            </tbody>
            @if($showPrices && isset($record->total_amount))
                <tfoot><tr><th colspan="{{ $type === 'goods-receipt-inspection' ? 6 : 4 }}">{{ __('procurement.fields.total') }}</th><th class="text-end" dir="ltr">{{ $numbers->format($record->total_amount) }}</th><th></th></tr></tfoot>
            @endif
        </table>
    @endif

    @if($type === 'purchase-order-change-request')
        <table class="document-meta-table"><tr><td><strong>{{ __('procurement.fields.original_values') }}</strong><pre>{{ json_encode($originalChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td><td><strong>{{ __('procurement.fields.approved_requested_values') }}</strong><pre>{{ json_encode($requestedChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td></tr></table>
    @endif

    @include('reports.partials.company-authorization')

    <style>.procurement-document-table th, .procurement-document-table td { font-size: 7.8px; overflow-wrap: break-word; }</style>
@endsection
