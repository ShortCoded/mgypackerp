@extends('reports.layouts.pdf')

@section('report')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $dates = app(\Modules\Core\Services\DateFormatService::class);
    @endphp
    @include('reports.partials.company-identity')
    <table style="width:100%; border-collapse:collapse; margin-bottom:10px;"><tr><td style="border:0;"><h2 style="margin:0;">{{ __('quotations.print.title') }}</h2><strong dir="ltr">{{ $record->doc_num }} / {{ $revision->revision_code }}</strong></td><td style="border:0; text-align:{{ $direction === 'rtl' ? 'left' : 'right' }};">{{ __('quotations.statuses.'.$revision->status) }}<br>{{ $dates->formatDate($revision->revision_date, '') }}</td></tr></table>
    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('quotations.attributes.customer') }}</th><td>{{ $record->customer?->doc_num }} / {{ $record->customer?->name }}</td><th>{{ __('quotations.attributes.branch') }}</th><td>{{ $record->branch?->name }}</td></tr>
        <tr><th>{{ __('quotations.attributes.sales_person') }}</th><td>{{ $record->salesPerson?->name }}</td><th>{{ __('quotations.attributes.valid_until') }}</th><td>{{ $dates->formatDate($record->valid_until, '') }}</td></tr>
        <tr><th>{{ __('quotations.attributes.customer_reference') }}</th><td>{{ $record->customer_reference }}</td><th>{{ __('quotations.attributes.currency') }}</th><td>{{ $record->currency?->code }}</td></tr>
    </tbody></table>
    <table class="report-table sales-quotation-lines" autosize="1"><thead><tr><th>#</th><th>{{ __('quotations.attributes.product') }}</th><th>{{ __('quotations.attributes.unit') }}</th><th>{{ __('quotations.attributes.quantity') }}</th><th>{{ __('quotations.attributes.requested_date') }}</th><th>{{ __('quotations.attributes.specifications') }}</th><th>{{ __('quotations.attributes.unit_price') }}</th><th>{{ __('quotations.attributes.tax_amount') }}</th><th>{{ __('quotations.attributes.total') }}</th></tr></thead><tbody>
        @foreach($revision->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} / {{ $line->product_name_snapshot }} / {{ $line->description }}</td><td>{{ $line->unit_name_snapshot }}</td><td>{{ $numbers->format($line->quantity) }}</td><td>{{ $dates->formatDate($line->requested_date, '') }}</td><td>{{ collect($line->specifications ?? [])->filter()->map(fn($value, $key) => str($key)->replace('_', ' ')->title().': '.$value)->join(' · ') }}</td><td>{{ $numbers->format($line->unit_price) }}</td><td>{{ $numbers->format($line->tax_amount) }}</td><td>{{ $numbers->format($line->line_total) }}</td></tr>@endforeach
    </tbody></table>
    <table class="report-table" style="width:45%; margin-top:10px; margin-{{ $direction === 'rtl' ? 'right' : 'left' }}:55%; page-break-inside:avoid;"><tbody><tr><th>{{ __('quotations.attributes.subtotal') }}</th><td>{{ $numbers->format($revision->subtotal) }}</td></tr><tr><th>{{ __('quotations.attributes.discount_amount') }}</th><td>{{ $numbers->format($revision->discount_amount) }}</td></tr><tr><th>{{ __('quotations.attributes.tax_amount') }}</th><td>{{ $numbers->format($revision->tax_amount) }}</td></tr><tr><th>{{ __('quotations.print.grand_total') }}</th><td><strong>{{ $numbers->format($revision->total) }}</strong></td></tr></tbody></table>
    @include('reports.partials.company-authorization')
    <style>.sales-quotation-lines { table-layout:fixed; }.sales-quotation-lines th,.sales-quotation-lines td { font-size:6.8px; overflow-wrap:break-word; padding:7px 4px; }.sales-quotation-lines thead { display:table-header-group; }.sales-quotation-lines tr { page-break-inside:avoid; }</style>
@endsection
