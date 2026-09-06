@extends('reports.layouts.pdf')

@section('report')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    @endphp

    @include('reports.partials.company-identity')

    <div class="document-title-row">
        <h1>{{ __('Supply Order') }}</h1>
        <strong dir="ltr">{{ $record->doc_num }}</strong>
        <span class="document-status">{{ __(str($record->fulfillmentStatus())->replace('_', ' ')->title()->toString()) }}</span>
    </div>

    <table class="document-meta-table">
        <tr>
            <td><strong>{{ __('purchase_orders.attributes.document_date') }}</strong><br><span dir="ltr">{{ $dates->formatDate($record->document_date, '—') }}</span></td>
            <td><strong>{{ __('purchase_orders.attributes.supplier') }}</strong><br>{{ trim(implode(' / ', array_filter([$record->supplier?->doc_num, $record->supplier?->name]))) ?: '—' }}</td>
            <td><strong>{{ __('purchase_orders.attributes.branch_store') }}</strong><br>{{ $record->branchStore?->name ?: '—' }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('purchase_orders.attributes.currency') }}</strong><br>{{ $record->currency?->code }} / {{ $record->currency?->name }}</td>
            <td><strong>{{ __('purchase_orders.attributes.exchange_rate') }}</strong><br><span dir="ltr">{{ $numbers->format($record->exchange_rate) }}</span></td>
            <td><strong>{{ __('purchase_orders.attributes.expected_delivery_date') }}</strong><br><span dir="ltr">{{ $dates->formatDate($record->expected_delivery_date, '—') }}</span></td>
        </tr>
        <tr><td><strong>{{ __('Branch') }}</strong><br>{{ $record->branch?->name }}</td><td><strong>{{ __('Source Purchase Requests') }}</strong><br>{{ $record->lines->map(fn ($line) => $line->requisitionLine?->requisition?->doc_num)->filter()->unique()->join(' / ') }}</td><td><strong>{{ __('Payment terms') }}</strong><br>{{ $record->payment_terms }}</td></tr>
        @if($record->supplier_reference)
            <tr><td colspan="3"><strong>{{ __('purchase_orders.attributes.supplier_reference') }}</strong><br>{{ $record->supplier_reference }}</td></tr>
        @endif
    </table>

    <table class="report-table purchase-order-print-table">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('purchase_orders.attributes.product') }}</th>
                <th>{{ __('purchase_orders.attributes.unit') }}</th>
                <th class="text-end">{{ __('purchase_orders.attributes.ordered_quantity') }}</th>
                <th class="text-end">{{ __('purchase_orders.attributes.unit_price') }}</th>
                <th class="text-end">{{ __('Discount') }}</th>
                <th class="text-end">{{ __('Tax') }}</th>
                <th class="text-end">{{ __('purchase_orders.attributes.line_total') }}</th>
                <th class="text-end">{{ __('purchase_orders.attributes.received_quantity') }}</th>
                <th class="text-end">{{ __('purchase_orders.attributes.remaining_quantity') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($record->lines as $line)
                @php($snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [])
                <tr>
                    <td>{{ $line->line_number }}</td>
                    <td>@include('reports.partials.item-details', ['line' => $line])</td>
                    <td>{{ $line->unit?->name ?? $snapshot['unit_label'] ?? '—' }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->ordered_quantity) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->unit_price) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->discount_amount) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->tax_amount) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->line_total) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->received_quantity) }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($line->remaining_quantity) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="document-totals-table">
        @foreach([__('Subtotal') => $record->subtotal_amount, __('Discount') => $record->lines->sum('discount_amount'), __('Tax') => $record->lines->sum('tax_amount'), __('Freight') => $record->freight_amount, __('Grand total') => $record->total_amount] as $label => $amount)
            <tr><th>{{ $label }}</th><td class="text-end" dir="ltr">{{ $numbers->format($amount) }} {{ $record->currency?->code }}</td></tr>
        @endforeach
    </table>

    @if($record->notes)
        <div class="report-filter-summary"><strong>{{ __('purchase_orders.attributes.notes') }}:</strong> {{ $record->notes }}</div>
    @endif

    @include('reports.partials.amount-in-words')
    @include('reports.partials.document-signatures', ['signatureType' => 'purchase-order'])
    @include('reports.partials.company-authorization')

@endsection
