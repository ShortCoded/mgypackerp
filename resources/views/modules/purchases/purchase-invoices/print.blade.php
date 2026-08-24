@extends('reports.layouts.pdf')

@section('report')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    @endphp

    @include('reports.partials.company-identity')

    <div class="document-title-row">
        <h1>{{ __('purchase_invoices.singular') }}</h1>
        <strong dir="ltr">{{ $record->doc_num }}</strong>
        <span class="document-status">{{ __('purchase_invoices.statuses.'.$record->status) }} / {{ __('purchase_invoices.payment_statuses.'.$record->payment_status) }}</span>
    </div>

    <table class="document-meta-table">
        <tr>
            <td><strong>{{ __('purchase_invoices.attributes.invoice_date') }}</strong><br><span dir="ltr">{{ $dates->formatDate($record->invoice_date, '—') }}</span></td>
            <td><strong>{{ __('purchase_invoices.attributes.financial_period') }}</strong><br>{{ trim(implode(' / ', array_filter([$record->financialPeriod?->doc_num, $record->financialPeriod?->name]))) ?: '—' }}</td>
            <td><strong>{{ __('purchase_invoices.attributes.supplier') }}</strong><br>{{ trim(implode(' / ', array_filter([$record->supplier?->doc_num, $record->supplier?->name]))) ?: '—' }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('purchase_invoices.attributes.supplier_invoice_number') }}</strong><br>{{ $record->supplier_invoice_number ?: '—' }}</td>
            <td><strong>{{ __('purchase_invoices.attributes.supplier_invoice_date') }}</strong><br><span dir="ltr">{{ $dates->formatDate($record->supplier_invoice_date, '—') }}</span></td>
            <td><strong>{{ __('purchase_invoices.attributes.payment_type') }}</strong><br>{{ __('purchase_invoices.payment_types.'.$record->payment_type) }}</td>
        </tr>
        @if($record->purchaseOrder)
            <tr><td colspan="3"><strong>{{ __('purchase_orders.singular') }}:</strong> <span dir="ltr">{{ $record->purchaseOrder->doc_num }}</span></td></tr>
        @endif
    </table>

    <table class="report-table purchase-invoice-print-table">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('purchase_invoices.attributes.product') }}</th>
                <th>{{ __('purchase_invoices.attributes.unit') }}</th>
                <th class="text-end">{{ __('purchase_invoices.attributes.quantity') }}</th>
                <th class="text-end">{{ __('purchase_invoices.attributes.unit_price') }}</th>
                <th class="text-end">{{ __('purchase_invoices.attributes.line_discount_amount') }}</th>
                <th class="text-end">{{ __('purchase_invoices.attributes.line_tax_amount') }}</th>
                <th class="text-end">{{ __('purchase_invoices.attributes.line_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($record->lines as $line)
                <tr>
                    <td>{{ $line->line_number }}</td>
                    <td>{{ trim(implode(' / ', array_filter([$line->product?->doc_num, $line->product?->name]))) }}</td>
                    <td>{{ trim(implode(' / ', array_filter([$line->unit?->doc_num, $line->unit?->name]))) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->quantity) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->unit_price) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->discount_amount) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->tax_amount) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->total_after_tax) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="document-totals-table">
        <tr><th>{{ __('purchase_invoices.totals.subtotal') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->subtotal_amount) }}</td><th>{{ __('purchase_invoices.totals.line_discounts') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->line_discount_amount) }}</td></tr>
        <tr><th>{{ __('purchase_invoices.totals.header_discount') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->header_discount_amount) }}</td><th>{{ __('Freight') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->freight_amount) }}</td></tr>
        <tr><th>{{ __('purchase_invoices.totals.tax') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->tax_amount) }}</td><th>{{ __('purchase_invoices.totals.net_total') }}</th><td class="text-end" dir="ltr"><strong>{{ $numbers->format($record->total_amount) }} {{ $record->currency?->code }}</strong></td></tr>
        <tr><th>{{ __('purchase_invoices.totals.paid') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->paid_amount) }}</td><th>{{ __('purchase_invoices.totals.remaining') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($record->remaining_amount) }}</td></tr>
    </table>

    <h3>{{ __('purchase_invoices.sections.payment_schedule') }}</h3>
    <table class="report-table">
        <thead><tr><th>#</th><th>{{ __('purchase_invoices.attributes.due_date') }}</th><th class="text-end">{{ __('purchase_invoices.attributes.payment_amount') }}</th><th class="text-end">{{ __('purchase_invoices.totals.paid') }}</th><th>{{ __('purchase_invoices.attributes.payment_source_type') }}</th><th>{{ __('purchase_invoices.attributes.linked_payment_voucher') }}</th></tr></thead>
        <tbody>
            @forelse($record->paymentSchedules as $schedule)
                <tr><td>{{ $schedule->line_number }}</td><td dir="ltr">{{ $dates->formatDate($schedule->due_date, '—') }}</td><td class="text-end" dir="ltr">{{ $numbers->format($schedule->amount) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($schedule->paid_amount) }}</td><td>{{ __('purchase_invoices.source_types.'.$schedule->payment_source_type) }}</td><td dir="ltr">{{ $schedule->cashVoucher?->doc_num ?: '—' }}</td></tr>
            @empty
                <tr><td colspan="6" style="text-align: center;">{{ __('purchase_invoices.messages.no_payment_schedule') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @include('reports.partials.company-authorization')

    <style>.purchase-invoice-print-table th, .purchase-invoice-print-table td { font-size: 7.5px; }</style>
@endsection
