@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $document = $record->doc_num ?? $record->cashVoucher?->doc_num;
    $title = str($type)->replace('-', ' ')->title();
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

@section('title', $title.' '.$document)

@section('content')
<div class="page-print-actions d-flex justify-content-end mb-3"><button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()"><span class="fas fa-print me-1"></span>{{ __('Print') }}</button></div>
<div class="card erp-document-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="card-body">
    <x-company-print-header :identity="$companyPrintIdentity" />
    <div class="d-flex justify-content-between align-items-start mb-4"><div><h3 class="mb-1">{{ $title }}</h3><div class="text-700" dir="ltr">{{ $document }}</div></div><div class="text-end"><span class="badge badge-subtle-secondary">{{ str((string) ($record->status ?? $record->qc_status ?? $record->cashVoucher?->status ?? ''))->replace('_', ' ')->title() }}</span></div></div>
    <div class="row g-3 mb-4">
        @if($record->supplier ?? null)<div class="col-6"><strong>{{ __('Supplier') }}</strong><div>{{ $record->supplier?->doc_num }} / {{ $record->supplier?->name }}</div></div>@endif
        @if($record->purchaseOrder ?? null)<div class="col-6"><strong>{{ __('Purchase Order') }}</strong><div dir="ltr">{{ $record->purchaseOrder?->doc_num }}</div></div>@endif
        @if($record->requisition ?? null)<div class="col-6"><strong>{{ __('Purchase Requisition') }}</strong><div dir="ltr">{{ $record->requisition?->doc_num }}</div></div>@endif
        @if($record->receipt ?? null)<div class="col-6"><strong>{{ __('Goods Receipt') }}</strong><div dir="ltr">{{ $record->receipt?->doc_num }}</div></div>@endif
        @if($type === 'goods-receipt')<div class="col-6"><strong>{{ __('Supplier delivery note') }}</strong><div>{{ $record->supplier_delivery_note ?: '—' }}</div></div><div class="col-6"><strong>{{ __('QC status') }}</strong><div>{{ str($record->qc_status)->replace('_', ' ')->title() }}</div></div>@endif
        @if($type === 'supplier-payment')
            <div class="col-6"><strong>{{ __('Payment date') }}</strong><div dir="ltr">{{ $dates->formatDate($record->payment_date, '—') }}</div></div>
            <div class="col-6"><strong>{{ __('Payment method') }}</strong><div>{{ str($record->payment_method)->title() }}</div></div>
            <div class="col-6"><strong>{{ __('Amount') }}</strong><div dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</div></div>
            @if($record->bankAccount)<div class="col-6"><strong>{{ __('Bank / branch / account') }}</strong><div>{{ $record->bankAccount->bank?->name ?? $record->bankAccount->bank?->name_en }} / {{ $record->bankAccount->bank_branch_name ?: '—' }} / <span dir="ltr">{{ $record->bankAccount->account_number }}</span></div></div>@endif
            @if($record->cheque)<div class="col-6"><strong>{{ __('Cheque') }}</strong><div dir="ltr">{{ $record->cheque->doc_num }} / {{ $record->cheque->cheque_number }}</div></div><div class="col-6"><strong>{{ __('Cheque due date / status') }}</strong><div><span dir="ltr">{{ $dates->formatDate($record->cheque->due_date, '—') }}</span> / {{ str($record->cheque->status)->title() }}</div></div>@endif
        @endif
    </div>
    @if($lines->count())
        <table class="table table-sm table-bordered align-middle"><thead><tr><th>#</th><th>{{ __('Item / Invoice') }}</th><th>{{ __('Source / Unit') }}</th><th class="text-end">{{ __('Quantity') }}</th>@if($showPrices)<th class="text-end">{{ __('Unit price') }}</th><th class="text-end">{{ __('Total') }}</th>@endif<th>{{ __('Notes / Result') }}</th></tr></thead><tbody>
        @foreach($lines as $index => $line)
            @php
                $item = $line->product?->name ?? $line->purchaseOrderLine?->product?->name ?? $line->purchaseInvoice?->doc_num ?? '—';
                $source = $line->unit?->name ?? $line->quotation?->supplier?->name ?? $line->paymentSchedule?->due_date?->format('Y-m-d') ?? '—';
                $quantity = $line->requested_quantity ?? $line->quantity ?? $line->offered_quantity ?? $line->selected_quantity ?? $line->scheduled_quantity ?? $line->inspected_quantity ?? $line->amount ?? 0;
                if ($type === 'supplier-payment' && ! $showPrices) { $quantity = '—'; }
            @endphp
            <tr><td>{{ $index + 1 }}</td><td>{{ $item }}</td><td>{{ $source }}</td><td class="text-end" dir="ltr">{{ is_numeric($quantity) ? $numbers->format($quantity) : $quantity }}</td>@if($showPrices)<td class="text-end" dir="ltr">{{ isset($line->unit_price) ? $numbers->format($line->unit_price) : '—' }}</td><td class="text-end" dir="ltr">{{ isset($line->line_total) ? $numbers->format($line->line_total) : (isset($line->amount) ? $numbers->format($line->amount) : '—') }}</td>@endif<td>{{ $line->result ?? $line->disposition ?? $line->reason ?? $line->notes ?? '—' }}</td></tr>
        @endforeach
        </tbody>
        @if($showPrices && isset($record->total_amount))<tfoot><tr><th colspan="{{ $showPrices ? 5 : 3 }}">{{ __('Total') }}</th><th class="text-end" dir="ltr">{{ $numbers->format($record->total_amount) }}</th><th></th></tr></tfoot>@endif
        </table>
    @endif
    @if($type === 'purchase-order-change-request')
        <div class="row g-3"><div class="col-6"><strong>{{ __('Original values') }}</strong><pre>{{ json_encode($originalChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div><div class="col-6"><strong>{{ __('Approved requested values') }}</strong><pre>{{ json_encode($requestedChangeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div>
    @endif
    <x-company-print-authorization :identity="$companyPrintIdentity" />
</div></div>
@endsection
