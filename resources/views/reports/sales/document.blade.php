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
        $quotationReference = trim(implode(' · ', array_filter([
            $record->quotation?->doc_num,
            $record->quotationRevision?->revision_number ? sprintf('R%02d', $record->quotationRevision->revision_number) : null,
        ])));
        $qualityDispositionLabel = static fn (?string $value): string => collect(explode(',', (string) $value))
            ->filter()
            ->map(fn (string $bucket): string => __(str($bucket)->replace('_', ' ')->title()->toString()))
            ->join(app()->isLocale('ar') ? '، ' : ', ');
        $sourceSalesOrder = $record->relationLoaded('salesOrder') ? $record->salesOrder : null;
        $sourceOrder = $record->relationLoaded('order') ? $record->order : null;
        $isInvoiceDocument = in_array($kind, ['invoice', 'credit_note'], true);
        $isLegalCopy = $isInvoiceDocument && ($copy ?? 'operational') === 'legal';
    @endphp

    @include('reports.partials.company-identity')

    @if($isInvoiceDocument)
        <div class="sales-copy-designation {{ $isLegalCopy ? 'sales-copy-designation-legal' : 'sales-copy-designation-operational' }}">
            {{ $isLegalCopy ? __('sales_ui.legal_invoice_copy') : __('sales_ui.operational_invoice_copy') }}
        </div>
    @endif

    <table style="width:100%; border-collapse:collapse; margin-bottom:10px;">
        <tr>
            <td style="border:0; width:60%;"><strong>{{ __('Document number') }}:</strong> <span dir="ltr">{{ $record->doc_num }}</span></td>
            <td style="border:0; width:40%; text-align:{{ $direction === 'rtl' ? 'left' : 'right' }};">
                @if($record->status ?? null)<div>{{ __(str($record->status)->replace('_', ' ')->title()->toString()) }}</div>@endif
                <div>{{ $dates->formatDate($record->order_date ?? $record->invoice_date ?? $record->receipt_date ?? $record->return_date ?? $record->document_date ?? $record->production_order_date ?? null, '') }}</div>
            </td>
        </tr>
    </table>

    <table class="report-table" style="margin-bottom:9px;">
        <tbody>
            @if($record->customer ?? null)<tr><th>{{ __('Customer') }}</th><td>{{ $record->customer->doc_num }} / {{ $record->customer->name }}</td></tr>@endif
            @if($record->quotation ?? null)<tr><th>{{ __('Source Quotation') }}</th><td>{{ $quotationReference }}</td></tr>@endif
            @if($sourceSalesOrder ?? $sourceOrder)<tr><th>{{ __('Source Sales Order') }}</th><td>{{ ($sourceSalesOrder ?? $sourceOrder)->doc_num }}</td></tr>@endif
            @if($record->relationLoaded('customerInvoices') && $record->customerInvoices->isNotEmpty())<tr><th>{{ __('Sales Invoice') }}</th><td>{{ $record->customerInvoices->pluck('doc_num')->join(' / ') }}</td></tr>@endif
            @if(($record->source_doc_num ?? null) && $kind === 'sales_delivery' && ! ($sourceSalesOrder ?? $sourceOrder))<tr><th>{{ __('Sales Invoice') }}</th><td>{{ $record->source_doc_num }}</td></tr>@endif
            @if($record->invoice ?? null)<tr><th>{{ __('Original Invoice') }}</th><td>{{ $record->invoice->doc_num }}</td></tr>@endif
            @if($record->originalInvoice ?? null)<tr><th>{{ __('Original Invoice') }}</th><td>{{ $record->originalInvoice->doc_num }}</td></tr>@endif
            @if($record->branchStore ?? null)<tr><th>{{ __('Store') }}</th><td>{{ $record->branchStore->name }}</td></tr>@endif
            @if($kind === 'sales_delivery')
                @if($sourceSalesOrder?->salesEmployee)<tr><th>{{ __('Sales representative') }}</th><td>{{ $sourceSalesOrder->salesEmployee->doc_num }} / {{ $sourceSalesOrder->salesEmployee->full_name ?: $sourceSalesOrder->salesEmployee->name }}</td></tr>@endif
                @if($record->recipient_name)<tr><th>{{ __('Recipient') }}</th><td>{{ $record->recipient_name }}</td></tr>@endif
                @if($record->recipient_phone)<tr><th>{{ __('Recipient phone') }}</th><td dir="ltr">{{ $record->recipient_phone }}</td></tr>@endif
                @if($record->vehicle_number)<tr><th>{{ __('Vehicle') }}</th><td>{{ $record->vehicle_number }}</td></tr>@endif
                @if($record->driver_name)<tr><th>{{ __('Driver') }}</th><td>{{ $record->driver_name }}</td></tr>@endif
            @endif
            @if($record->reason_code ?? null)<tr><th>{{ __('Return reason') }}</th><td>{{ __(str($record->reason_code)->replace('_', ' ')->title()->toString()) }} — {{ $record->reason_details }}</td></tr>@endif
            @if($kind === 'customer_receipt')
                <tr><th>{{ __('sales_ui.received_by_employee') }}</th><td>@if($record->receivedByEmployee){{ $record->receivedByEmployee->doc_num }} / {{ $record->receivedByEmployee->full_name ?: $record->receivedByEmployee->name }}@else{{ __('sales_ui.legacy_receiver_unresolved') }}@endif</td></tr>
                <tr><th>{{ __('Payment method') }}</th><td>{{ __(str($record->payment_method)->replace('_', ' ')->title()->toString()) }}</td></tr>
                @if($record->cashbox)<tr><th>{{ __('Cashbox') }}</th><td>{{ $record->cashbox->doc_num }} / {{ $record->cashbox->name }}</td></tr>@endif
                @if($record->bankAccount)<tr><th>{{ __('Bank account') }}</th><td>{{ $record->bankAccount->doc_num }} / {{ $record->bankAccount->account_name }}</td></tr>@endif
                @if($record->reference_no)<tr><th>{{ $record->payment_method === 'cheque' ? __('Cheque number') : __('sales_ui.bank_reference') }}</th><td dir="ltr">{{ $record->reference_no }}</td></tr>@endif
                @if($record->payment_method === 'cheque' && $record->external_bank_name)<tr><th>{{ __('Drawer bank') }}</th><td>{{ $record->external_bank_name }}</td></tr>@endif
                @if($record->payment_method === 'cheque' && $record->cheque_due_date)<tr><th>{{ __('Cheque due date') }}</th><td>{{ $dates->formatDate($record->cheque_due_date, '—') }}</td></tr>@endif
                @if($record->cashVoucher)<tr><th>{{ __('Canonical Finance document') }}</th><td>{{ __('Cash Receipt Voucher') }} <span dir="ltr">{{ $record->cashVoucher->doc_num }}</span></td></tr>@endif
                @if($record->cheque)<tr><th>{{ __('Canonical Finance document') }}</th><td>{{ __('Received Cheque') }} <span dir="ltr">{{ $record->cheque->doc_num }}</span>@if($record->cheque->cheque_number) / <span dir="ltr">{{ $record->cheque->cheque_number }}</span>@endif</td></tr>@endif
                <tr><th>{{ __('Receipt amount') }}</th><td dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</td></tr>
            @endif
            @if($isLegalCopy)
                <tr><th>{{ __('Electronic invoice status') }}</th><td>{{ __(str($record->electronic_invoice_status)->replace('_', ' ')->title()->toString()) }}</td></tr>
                @if($record->electronic_invoice_uuid)<tr><th>{{ __('Authority UUID') }}</th><td dir="ltr">{{ $record->electronic_invoice_uuid }}</td></tr>@endif
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
                        <td>@include('reports.partials.item-details', ['line' => $line, 'showPacking' => false, 'showClassification' => false])</td>
                        <td>{{ $printUnit?->name }}</td>
                        <td dir="ltr">{{ $numbers->format($printQuantity) }}</td>
                        @if($kind === 'sales_order')<td dir="ltr">{{ $numbers->format($line->delivered_quantity) }}</td>@endif
                        @if($showPrices)<td dir="ltr">{{ $numbers->format($line->unit_price ?? 0) }}</td><td dir="ltr">{{ $numbers->format($line->discount_amount ?? 0) }}</td><td dir="ltr">{{ $numbers->format($line->tax_amount ?? 0) }}</td><td dir="ltr">{{ $numbers->format($line->line_total ?? 0) }}</td>@endif
                        @if(in_array($kind, ['sales_return', 'quality_disposition'], true))<td>{{ $line->quality_disposition ? $qualityDispositionLabel($line->quality_disposition) : __('Pending inspection') }}<br>{{ __('Saleable') }}: {{ $numbers->format($line->saleable_quantity) }} · {{ __('Quarantine') }}: {{ $numbers->format($line->quarantine_quantity) }} · {{ __('Rework') }}: {{ $numbers->format($line->rework_quantity) }} · {{ __('Scrap') }}: {{ $numbers->format($line->scrap_quantity) }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($kind === 'payment_schedule' || ($showPrices && $record->relationLoaded('paymentSchedules') && $record->paymentSchedules->isNotEmpty()))
        <h3>{{ __('Payment Schedule') }}</h3>
        <table class="report-table"><thead><tr><th>#</th><th>{{ __('Due date') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Collected') }}</th><th>{{ __('Credited') }}</th><th>{{ __('Outstanding') }}</th></tr></thead><tbody>@foreach($record->paymentSchedules as $schedule)<tr><td>{{ $schedule->sequence }}</td><td>{{ $dates->formatDate($schedule->due_date, '') }}</td><td>{{ $numbers->format($schedule->amount) }}</td><td>{{ $numbers->format($schedule->collected_amount) }}</td><td>{{ $numbers->format($schedule->credited_amount) }}</td><td>{{ $numbers->format($schedule->outstanding_amount) }}</td></tr>@endforeach</tbody></table>
    @endif

    @if($kind === 'customer_receipt' && $record->relationLoaded('allocations') && $record->allocations->isNotEmpty())
        <h3>{{ __('Receipt Allocations') }}</h3>
        <table class="report-table"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Installment due') }}</th><th>{{ __('Allocated') }}</th></tr></thead><tbody>@foreach($record->allocations as $allocation)<tr><td>{{ $allocation->invoice?->doc_num }}</td><td>{{ $dates->formatDate($allocation->invoiceSchedule?->due_date, '—') }}</td><td dir="ltr">{{ $numbers->format($allocation->allocated_amount) }} {{ $record->currency?->code }}</td></tr>@endforeach</tbody></table>
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
    @if($showPrices && (isset($record->total_amount) || $kind === 'customer_receipt')) @include('reports.partials.amount-in-words') @endif
    @include('reports.partials.document-signatures', ['signatureNames' => [__('Prepared by') => null, __('Reviewed by') => null, __('Approved By') => null]])
    @if(! $isInvoiceDocument || $isLegalCopy)
        @include('reports.partials.company-authorization')
    @endif

    <style>
        .sales-document-lines { table-layout: fixed; }
        .sales-document-lines th, .sales-document-lines td { overflow-wrap: break-word; padding: 7.5px 4px; }
        .sales-document-lines thead { display: table-header-group; }
        .sales-document-lines tr { page-break-inside: avoid; }
    </style>
@endsection
