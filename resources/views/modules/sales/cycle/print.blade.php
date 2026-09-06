@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $title = __(str($kind)->replace('_', ' ')->title()->toString());
    $documentDate = $record->order_date ?? $record->invoice_date ?? $record->receipt_date ?? $record->return_date ?? $record->document_date ?? $record->production_order_date;
    $lines = $record->relationLoaded('lines') ? $record->lines : collect();
    $showRoutes = [
        'sales_order' => 'admin.sales.sales-orders.show', 'invoice' => 'admin.sales.sales-invoices.show',
        'credit_note' => 'admin.sales.sales-invoices.show', 'customer_receipt' => 'admin.sales.customer-receipts.show',
        'sales_return' => 'admin.sales.sales-returns.show', 'sales_delivery' => 'admin.sales.delivery-notes.show',
        'production_request' => request()->routeIs('admin.production.*') ? 'admin.production.work-orders.show' : 'admin.sales.production-requests.show',
        'payment_schedule' => 'admin.sales.sales-invoices.show',
        'quality_disposition' => 'admin.sales.sales-returns.show',
    ];
@endphp

@section('title', $title.' '.$record->doc_num)

@push('styles')
<style>
    @page { size: {{ in_array($kind, ['sales_order', 'invoice', 'credit_note'], true) ? 'A4 landscape' : 'A4 portrait' }}; margin: 12mm; }
    .sales-cycle-print table { break-inside: auto; }
    .sales-cycle-print tr { break-inside: avoid; break-after: auto; }
    .sales-cycle-print thead { display: table-header-group; }
    .sales-cycle-print tfoot, .sales-cycle-print .print-summary { break-inside: avoid; page-break-inside: avoid; }
    @media print { .page-print-actions { display: none !important; } .sales-cycle-print { box-shadow: none !important; border: 0 !important; } .sales-cycle-print .table-responsive { overflow: visible !important; } }
</style>
@endpush

@section('content')
    <div class="page-print-actions d-flex justify-content-end gap-2 mb-3">
        <a class="btn btn-falcon-default btn-sm" href="{{ route($showRoutes[$kind], $record) }}">{{ __('Back') }}</a>
        <button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
    <div class="card erp-document-print sales-cycle-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
        <div class="card-body">
            <x-company-print-header :identity="$companyPrintIdentity" />
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div><h3 class="mb-1">{{ $title }}</h3><div class="text-700">{{ $record->doc_num }}</div></div>
                <div class="text-end"><span class="badge bg-secondary">{{ __(str($record->status)->replace('_', ' ')->title()->toString()) }}</span><div class="mt-2">{{ $dates->formatDate($documentDate, '') }}</div></div>
            </div>
            <div class="row g-3 mb-4">
                @if($record->customer ?? null)<div class="col-6"><strong>{{ __('Customer') }}</strong><div>{{ $record->customer->doc_num }} / {{ $record->customer->name }}</div></div>@endif
                @if($record->quotation ?? null)<div class="col-6"><strong>{{ __('Source Quotation') }}</strong><div>{{ $record->quotation->doc_num }} / {{ $record->quotationRevision?->revision_code }}</div></div>@endif
                @if(($record->customer?->tax_number ?? null) && in_array($kind, ['invoice', 'credit_note'], true))<div class="col-6"><strong>{{ __('Customer tax number') }}</strong><div dir="ltr">{{ $record->customer->tax_number }}</div></div>@endif
                @if(($record->customer?->address ?? null) && in_array($kind, ['invoice', 'credit_note'], true))<div class="col-12"><strong>{{ __('Customer address') }}</strong><div>{{ $record->customer->address }}</div></div>@endif
                @if($record->salesOrder ?? $record->order ?? null)<div class="col-6"><strong>{{ __('Source Sales Order') }}</strong><div>{{ ($record->salesOrder ?? $record->order)->doc_num }}</div></div>@endif
                @if(($record->source_doc_num ?? null) && $kind === 'sales_delivery')<div class="col-6"><strong>{{ __('Source Sales Order') }}</strong><div>{{ $record->source_doc_num }}</div></div>@endif
                @if($record->customer_reference ?? null)<div class="col-6"><strong>{{ __('Customer reference / PO') }}</strong><div>{{ $record->customer_reference }}</div></div>@endif
                @if($record->expected_delivery_date ?? null)<div class="col-6"><strong>{{ __('Required date') }}</strong><div>{{ $dates->formatDate($record->expected_delivery_date, '') }}</div></div>@endif
                @if($record->due_date ?? null)<div class="col-6"><strong>{{ __('Due date') }}</strong><div>{{ $dates->formatDate($record->due_date, '') }}</div></div>@endif
                @if($record->invoice ?? null)<div class="col-6"><strong>{{ __('Original Invoice') }}</strong><div>{{ $record->invoice->doc_num }}</div></div>@endif
                @if($record->originalInvoice ?? null)<div class="col-6"><strong>{{ __('Original Invoice') }}</strong><div>{{ $record->originalInvoice->doc_num }}</div></div>@endif
                @if($record->relationLoaded('deliveries') && $record->deliveries->isNotEmpty())<div class="col-6"><strong>{{ __('Source Deliveries') }}</strong><div>{{ $record->deliveries->pluck('doc_num')->join(' / ') }}</div></div>
                @elseif($record->delivery ?? null)<div class="col-6"><strong>{{ __('Source Delivery') }}</strong><div>{{ $record->delivery->doc_num }}</div></div>@endif
                @if($record->branchStore ?? null)<div class="col-6"><strong>{{ __('Store') }}</strong><div>{{ $record->branchStore->public_uuid }} / {{ $record->branchStore->name }}</div></div>@endif
                @if($record->recipient_name ?? null)<div class="col-6"><strong>{{ __('Recipient') }}</strong><div>{{ $record->recipient_name }} / {{ $record->recipient_phone }}</div></div>@endif
                @if($record->vehicle_number ?? null)<div class="col-6"><strong>{{ __('Vehicle / Driver') }}</strong><div>{{ $record->vehicle_number }} / {{ $record->driver_name }}</div></div>@endif
                @if($record->reason_code ?? null)<div class="col-12"><strong>{{ __('Return reason') }}</strong><div>{{ __(str($record->reason_code)->replace('_', ' ')->title()->toString()) }} — {{ $record->reason_details }}</div></div>@endif
                @if($record->salesReturn ?? null)<div class="col-12"><strong>{{ __('Credit reason / Return') }}</strong><div>{{ $record->salesReturn->doc_num }} — {{ __(str($record->salesReturn->reason_code)->replace('_', ' ')->title()->toString()) }}</div></div>@endif
                @if($kind === 'customer_receipt')<div class="col-6"><strong>{{ __('Payment method') }}</strong><div>{{ __(str($record->payment_method)->replace('_', ' ')->title()->toString()) }}</div></div><div class="col-6"><strong>{{ __('Cash / Bank / Cheque reference') }}</strong><div>{{ $record->cashVoucher?->doc_num ?? $record->cheque?->doc_num ?? $record->bankAccount?->doc_num ?? $record->reference_no ?? '—' }}</div></div><div class="col-6"><strong>{{ __('Receipt amount') }}</strong><div dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</div></div>@endif
                @if(in_array($kind, ['invoice', 'credit_note'], true))<div class="col-6"><strong>{{ __('Electronic invoice status') }}</strong><div>{{ __(str($record->electronic_invoice_status)->replace('_', ' ')->title()->toString()) }}</div></div>@endif
                @if($kind === 'quality_disposition')<div class="col-6"><strong>{{ __('Inspector') }}</strong><div>{{ $record->inspectedBy?->name ?? '—' }}</div></div><div class="col-6"><strong>{{ __('Inspection date') }}</strong><div>{{ $dates->formatDate($record->inspected_at, '—') }}</div></div>@endif
                @if($kind === 'payment_schedule')<div class="col-6"><strong>{{ __('Invoice total') }}</strong><div dir="ltr">{{ $numbers->format($record->total_amount) }}</div></div><div class="col-6"><strong>{{ __('Outstanding') }}</strong><div dir="ltr">{{ $numbers->format($record->remaining_amount) }}</div></div>@endif
            </div>

            @if($lines->isNotEmpty())
                <table class="table table-sm table-bordered align-middle">
                    <thead><tr><th>#</th><th>{{ __('Item / Description') }}</th><th>{{ __('Unit') }}</th><th class="text-end">{{ __('Quantity') }}</th>@if($kind === 'sales_order')<th class="text-end">{{ __('Delivered') }}</th>@endif @if($showPrices)<th class="text-end">{{ __('Unit price') }}</th><th class="text-end">{{ __('Discount') }}</th><th class="text-end">{{ __('Tax') }}</th><th class="text-end">{{ __('Total') }}</th>@endif @if(in_array($kind, ['sales_return', 'quality_disposition'], true))<th>{{ __('Quality disposition') }}</th>@endif</tr></thead>
                    <tbody>
                        @foreach($lines as $line)
                            @php
                                $printUnit = $line->transactionUnit ?? $line->unit;
                                $printQuantity = $line->transaction_quantity ?? $line->quantity;
                                $specifications = collect($line->specifications ?? $line->product_snapshot['specifications'] ?? [])->filter();
                            @endphp
                            <tr><td>{{ $line->line_number }}</td><td>{{ trim(implode(' / ', array_filter([$line->product?->doc_num, $line->product?->name, $line->description]))) }}@if($specifications->isNotEmpty())<br><small>{{ $specifications->map(fn($value, $key) => __(str($key)->replace('_', ' ')->title()->toString()).': '.$value)->join(' · ') }}</small>@endif @if($line->production_notes ?? null)<br><small>{{ $line->production_notes }}</small>@endif</td><td>{{ trim(implode(' / ', array_filter([$printUnit?->doc_num, $printUnit?->name]))) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($printQuantity) }}@if($kind === 'production_request' && isset($line->base_quantity))<br><small>{{ __('Base') }}: {{ $numbers->format($line->base_quantity) }}</small>@endif</td>@if($kind === 'sales_order')<td class="text-end" dir="ltr">{{ $numbers->format($line->delivered_quantity) }}</td>@endif @if($showPrices)<td class="text-end" dir="ltr">{{ $numbers->format($line->unit_price ?? 0) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->discount_amount ?? 0) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->tax_amount ?? 0) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->line_total ?? 0) }}</td>@endif @if(in_array($kind, ['sales_return', 'quality_disposition'], true))<td>{{ $line->quality_disposition ? __(str($line->quality_disposition)->replace('_', ' ')->title()->toString()) : __('Pending inspection') }}<br><small>{{ __('Saleable') }}: {{ $numbers->format($line->saleable_quantity) }} / {{ __('Quarantine') }}: {{ $numbers->format($line->quarantine_quantity) }} / {{ __('Rework') }}: {{ $numbers->format($line->rework_quantity) }} / {{ __('Scrap') }}: {{ $numbers->format($line->scrap_quantity) }}</small>@if($line->inspection_notes)<br><small>{{ $line->inspection_notes }}</small>@endif</td>@endif</tr>
                        @endforeach
                    </tbody>
                    @if($showPrices && isset($record->total_amount))<tfoot><tr><th colspan="{{ $kind === 'sales_order' ? 5 : 4 }}">{{ __('Total') }}</th><th colspan="4" class="text-end" dir="ltr">{{ $numbers->format($record->total_amount) }}</th></tr></tfoot>@endif
                </table>
            @endif

            @if($showPrices && isset($record->total_amount))
                <div class="row justify-content-end print-summary mt-3"><div class="col-md-6 col-lg-5"><table class="table table-sm table-bordered mb-0"><tbody>
                    @if(isset($record->subtotal_amount))<tr><th>{{ __('Subtotal') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->subtotal_amount) }}</td></tr>@endif
                    @if(isset($record->discount_amount))<tr><th>{{ __('Discount') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->discount_amount) }}</td></tr>@endif
                    @if(isset($record->tax_amount))<tr><th>{{ __('Tax') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->tax_amount) }}</td></tr>@endif
                    <tr class="fw-bold"><th>{{ __('Grand total') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->total_amount) }}</td></tr>
                </tbody></table></div></div>
            @endif

            @if($showPrices && $record->relationLoaded('paymentSchedules') && $record->paymentSchedules->isNotEmpty())
                <h6 class="mt-4">{{ __('Payment Schedule') }}</h6><table class="table table-sm table-bordered"><thead><tr><th>#</th><th>{{ __('Due date') }}</th><th class="text-end">{{ __('Amount') }}</th><th class="text-end">{{ __('Collected') }}</th><th class="text-end">{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($record->paymentSchedules as $schedule)<tr><td>{{ $schedule->sequence ?? $schedule->line_number }}</td><td>{{ $dates->formatDate($schedule->due_date, '') }}</td><td class="text-end">{{ $numbers->format($schedule->amount) }}</td><td class="text-end">{{ $numbers->format($schedule->collected_amount) }}</td><td class="text-end">{{ $numbers->format($schedule->outstanding_amount ?? $schedule->remaining_amount) }}</td></tr>@endforeach</tbody></table>
            @endif

            @if($showPrices && $record->relationLoaded('allocations') && $record->allocations->isNotEmpty())
                <h6 class="mt-4">{{ __('Receipt Allocations') }}</h6><table class="table table-sm table-bordered"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Installment due') }}</th><th class="text-end">{{ __('Allocated') }}</th></tr></thead><tbody>@foreach($record->allocations as $allocation)<tr><td>{{ $allocation->invoice?->doc_num }}</td><td>{{ $dates->formatDate($allocation->invoiceSchedule?->due_date, '') }}</td><td class="text-end">{{ $numbers->format($allocation->allocated_amount) }}</td></tr>@endforeach</tbody></table>
                <div class="text-end"><strong>{{ __('Unallocated customer credit') }}:</strong> {{ $numbers->format($record->unallocated_amount) }}</div>
            @endif

            @if($record->notes ?? null)<div class="mt-4"><strong>{{ __('Notes') }}</strong><div>{{ $record->notes }}</div></div>@endif
            <x-company-print-authorization :identity="$companyPrintIdentity" />
        </div>
    </div>
@endsection
