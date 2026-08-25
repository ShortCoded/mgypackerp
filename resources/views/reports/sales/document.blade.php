@extends('reports.layouts.pdf')

@section('report')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $labels = [
            'sales_order' => __('Sales Order'),
            'invoice' => __('Sales Invoice'),
            'credit_note' => __('Sales Credit Note'),
            'customer_receipt' => __('Customer Receipt'),
            'sales_return' => __('Sales Return'),
            'sales_delivery' => __('Delivery Note'),
            'production_request' => __('Production Request'),
            'payment_schedule' => __('Payment Schedule'),
            'quality_disposition' => __('Return Quality Disposition'),
        ];
        $lines = match($kind) {
            'sales_order', 'invoice', 'credit_note', 'sales_return', 'sales_delivery', 'production_request', 'quality_disposition' => $record->lines,
            default => collect(),
        };
    @endphp

    @include('reports.partials.company-identity')

    <table style="width:100%; border-collapse:collapse; margin-bottom:10px;">
        <tr>
            <td style="border:0; width:60%;"><h2 style="margin:0;">{{ $labels[$kind] ?? str($kind)->replace('_', ' ')->title() }}</h2><strong dir="ltr">{{ $record->doc_num }}</strong></td>
            <td style="border:0; width:40%; text-align:{{ $direction === 'rtl' ? 'left' : 'right' }};">
                @if($record->status ?? null)<div>{{ str($record->status)->replace('_', ' ')->title() }}</div>@endif
                <div>{{ $dates->formatDate($record->order_date ?? $record->invoice_date ?? $record->receipt_date ?? $record->return_date ?? $record->document_date ?? $record->production_order_date ?? null, '') }}</div>
            </td>
        </tr>
    </table>

    <table class="report-table" style="margin-bottom:9px;">
        <tbody>
            @if($record->customer ?? null)<tr><th>{{ __('Customer') }}</th><td>{{ $record->customer->doc_num }} / {{ $record->customer->name }}</td></tr>@endif
            @if($record->quotation ?? null)<tr><th>{{ __('Source Quotation') }}</th><td>{{ $record->quotation->doc_num }} / {{ $record->quotationRevision?->revision_code }}</td></tr>@endif
            @if($record->salesOrder ?? $record->order ?? null)<tr><th>{{ __('Source Sales Order') }}</th><td>{{ ($record->salesOrder ?? $record->order)->doc_num }}</td></tr>@endif
            @if(($record->source_doc_num ?? null) && $kind === 'sales_delivery')<tr><th>{{ __('Source Sales Order') }}</th><td>{{ $record->source_doc_num }}</td></tr>@endif
            @if($record->invoice ?? null)<tr><th>{{ __('Original Invoice') }}</th><td>{{ $record->invoice->doc_num }}</td></tr>@endif
            @if($record->originalInvoice ?? null)<tr><th>{{ __('Original Invoice') }}</th><td>{{ $record->originalInvoice->doc_num }}</td></tr>@endif
            @if($record->branchStore ?? null)<tr><th>{{ __('Store') }}</th><td>{{ $record->branchStore->name }}</td></tr>@endif
            @if($record->customer_reference ?? null)<tr><th>{{ __('Customer reference / PO') }}</th><td>{{ $record->customer_reference }}</td></tr>@endif
            @if($record->reason_code ?? null)<tr><th>{{ __('Return reason') }}</th><td>{{ str($record->reason_code)->replace('_', ' ')->title() }} — {{ $record->reason_details }}</td></tr>@endif
            @if($kind === 'customer_receipt')
                <tr><th>{{ __('Payment method') }}</th><td>{{ str($record->payment_method)->replace('_', ' ')->title() }}</td></tr>
                <tr><th>{{ __('Canonical Finance document') }}</th><td>{{ $record->cashVoucher?->doc_num ?? $record->cheque?->doc_num ?? $record->bankAccount?->doc_num ?? $record->reference_no ?? '—' }}</td></tr>
                <tr><th>{{ __('Receipt amount') }}</th><td dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</td></tr>
            @endif
            @if(in_array($kind, ['invoice', 'credit_note'], true))<tr><th>{{ __('Electronic invoice status') }}</th><td>{{ str($record->electronic_invoice_status)->replace('_', ' ')->title() }}</td></tr>@endif
            @if(in_array($kind, ['sales_return', 'quality_disposition'], true))
                <tr><th>{{ __('Quarantine inventory / journal') }}</th><td>{{ $record->returnInventoryDocument?->doc_num ?? '—' }} / {{ $record->quarantineJournalEntry?->doc_num ?? '—' }}</td></tr>
                <tr><th>{{ __('Disposition journal') }}</th><td>{{ $record->dispositionJournalEntry?->doc_num ?? __('No cross-account disposition required') }}</td></tr>
            @endif
        </tbody>
    </table>

    @if($lines->isNotEmpty())
        <table class="report-table sales-document-lines" autosize="1">
            <thead><tr><th>#</th><th>{{ __('Item / Description') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th>@if($kind === 'sales_order')<th>{{ __('Delivered') }}</th>@endif @if($showPrices)<th>{{ __('Unit price') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Tax') }}</th><th>{{ __('Total') }}</th>@endif @if(in_array($kind, ['sales_return', 'quality_disposition'], true))<th>{{ __('Quality disposition') }}</th>@endif</tr></thead>
            <tbody>
                @foreach($lines as $line)
                    @php
                        $printUnit = $line->transactionUnit ?? $line->unit;
                        $printQuantity = $line->transaction_quantity ?? $line->quantity;
                    @endphp
                    <tr>
                        <td>{{ $line->line_number }}</td>
                        <td>{{ trim(implode(' / ', array_filter([$line->product?->doc_num, $line->product?->name, $line->description]))) }}</td>
                        <td>{{ $printUnit?->name }}</td>
                        <td dir="ltr">{{ $numbers->format($printQuantity) }}</td>
                        @if($kind === 'sales_order')<td dir="ltr">{{ $numbers->format($line->delivered_quantity) }}</td>@endif
                        @if($showPrices)<td dir="ltr">{{ $numbers->format($line->unit_price ?? 0) }}</td><td dir="ltr">{{ $numbers->format($line->discount_amount ?? 0) }}</td><td dir="ltr">{{ $numbers->format($line->tax_amount ?? 0) }}</td><td dir="ltr">{{ $numbers->format($line->line_total ?? 0) }}</td>@endif
                        @if(in_array($kind, ['sales_return', 'quality_disposition'], true))<td>{{ $line->quality_disposition ?: __('Pending inspection') }}<br>{{ __('Saleable') }}: {{ $numbers->format($line->saleable_quantity) }} · {{ __('Quarantine') }}: {{ $numbers->format($line->quarantine_quantity) }} · {{ __('Rework') }}: {{ $numbers->format($line->rework_quantity) }} · {{ __('Scrap') }}: {{ $numbers->format($line->scrap_quantity) }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($kind === 'payment_schedule' || ($showPrices && $record->relationLoaded('paymentSchedules') && $record->paymentSchedules->isNotEmpty()))
        <h3>{{ __('Payment Schedule') }}</h3>
        <table class="report-table"><thead><tr><th>#</th><th>{{ __('Due date') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Collected') }}</th><th>{{ __('Credited') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($record->paymentSchedules as $schedule)<tr><td>{{ $schedule->sequence }}</td><td>{{ $dates->formatDate($schedule->due_date, '') }}</td><td>{{ $numbers->format($schedule->amount) }}</td><td>{{ $numbers->format($schedule->collected_amount) }}</td><td>{{ $numbers->format($schedule->credited_amount) }}</td><td>{{ $numbers->format($schedule->outstanding_amount) }}</td></tr>@endforeach</tbody></table>
    @endif

    @if($showPrices && isset($record->total_amount))
        <table class="report-table" style="width:45%; margin-top:10px; margin-{{ $direction === 'rtl' ? 'right' : 'left' }}:55%; page-break-inside:avoid;"><tbody>
            @if(isset($record->subtotal_amount))<tr><th>{{ __('Subtotal') }}</th><td>{{ $numbers->format($record->subtotal_amount) }}</td></tr>@endif
            @if(isset($record->discount_amount))<tr><th>{{ __('Discount') }}</th><td>{{ $numbers->format($record->discount_amount) }}</td></tr>@endif
            @if(isset($record->tax_amount))<tr><th>{{ __('Tax') }}</th><td>{{ $numbers->format($record->tax_amount) }}</td></tr>@endif
            <tr><th>{{ __('Grand total') }}</th><td><strong>{{ $numbers->format($record->total_amount) }}</strong></td></tr>
        </tbody></table>
    @endif

    @if($record->notes ?? null)<div style="margin-top:10px;"><strong>{{ __('Notes') }}:</strong> {{ $record->notes }}</div>@endif
    @include('reports.partials.company-authorization')

    <style>
        .sales-document-lines { table-layout: fixed; }
        .sales-document-lines th, .sales-document-lines td { font-size: 7.4px; overflow-wrap: break-word; padding: 7.5px 4px; }
        .sales-document-lines thead { display: table-header-group; }
        .sales-document-lines tr { page-break-inside: avoid; }
    </style>
@endsection
