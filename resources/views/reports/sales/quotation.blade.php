@extends('reports.layouts.pdf')

@section('report')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $showRequestedDate = $revision->lines->contains(fn ($line) => filled($line->requested_date));
        $showDiscount = $revision->lines->contains(fn ($line) => (float) $line->discount_amount > 0);
        $showTax = $revision->lines->contains(fn ($line) => (float) $line->tax_amount > 0);
    @endphp
    @include('reports.partials.company-identity', ['showRegistrationNumbers' => false])
    @php
        $information = array_filter([
            __('quotations.attributes.customer') => $record->customer?->name,
            __('quotations.attributes.subject') => $record->subject ?: $record->project_name,
            __('quotations.attributes.sales_person') => $record->salesPerson?->name,
            __('quotations.attributes.customer_reference') => $record->customer_reference,
            __('quotations.attributes.currency') => $record->currency?->code,
            __('quotations.attributes.valid_until') => $record->valid_until ? $dates->formatDate($record->valid_until, '') : null,
        ], fn ($value) => filled($value));
        $characteristics = [__('Material') => 'material', __('Color') => 'color'];
        $characteristicValue = fn ($line, $key) => data_get($line->specifications, $key) ?: ($key === 'color' ? $line->product?->color?->name : null);
        $characteristics = array_filter($characteristics, fn ($key) => $revision->lines->contains(fn ($line) => filled($characteristicValue($line, $key))));
    @endphp
    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('quotations.attributes.doc_num') }}</th><td dir="ltr">{{ $record->doc_num }}</td><th>{{ __('quotations.attributes.revision_code') }}</th><td dir="ltr">R{{ str_pad((string) $revision->revision_number, 2, '0', STR_PAD_LEFT) }}</td></tr>
        <tr><th>{{ __('Date') }}</th><td colspan="3">{{ $dates->formatDate($revision->revision_date, '') }}</td></tr>
        @foreach(collect($information)->chunk(2) as $pair)<tr>@foreach($pair as $label => $value)<th>{{ $label }}</th><td @if($pair->count() === 1) colspan="3" @endif>{{ $value }}</td>@endforeach</tr>@endforeach
    </tbody></table>
    <table class="report-table sales-quotation-lines" autosize="1">
        <thead><tr>
            <th>#</th><th>{{ __('quotations.attributes.product') }}</th>@foreach($characteristics as $label => $key)<th>{{ $label }}</th>@endforeach<th>{{ __('quotations.attributes.unit') }}</th><th>{{ __('quotations.attributes.quantity') }}</th>
            @if($showRequestedDate)<th>{{ __('quotations.attributes.requested_date') }}</th>@endif
            <th>{{ __('quotations.attributes.unit_price') }}</th>
            @if($showDiscount)<th>{{ __('quotations.attributes.discount_amount') }}</th>@endif
            @if($showTax)<th>{{ __('quotations.attributes.tax_amount') }}</th>@endif
            <th>{{ __('quotations.attributes.total') }}</th>
        </tr></thead>
        <tbody>@foreach($revision->lines as $line)
            <tr>
                <td>{{ $line->line_number }}</td>
                <td>@if($line->product?->doc_num)<div class="document-item-code" dir="ltr">{{ $line->product->doc_num }}</div>@endif<strong>{{ $line->product_name_snapshot ?: $line->product?->name }}</strong>
                    @if(filled($line->description) && $line->description !== $line->product_name_snapshot)<div>{{ $line->description }}</div>@endif
                    @if(filled($line->notes))<div class="document-item-details">{{ $line->notes }}</div>@endif
                </td>
                @foreach($characteristics as $key)<td>{{ $characteristicValue($line, $key) }}</td>@endforeach
                <td>{{ $line->unit_name_snapshot }}</td><td class="number">{{ $numbers->format($line->quantity) }}</td>
                @if($showRequestedDate)<td>{{ $dates->formatDate($line->requested_date, '') }}</td>@endif
                <td class="number">{{ $numbers->format($line->unit_price) }}</td>
                @if($showDiscount)<td class="number">{{ $numbers->format($line->discount_amount) }}</td>@endif
                @if($showTax)<td class="number">{{ $numbers->format($line->tax_amount) }}</td>@endif
                <td class="number">{{ $numbers->format($line->line_total) }}</td>
            </tr>
        @endforeach</tbody>
    </table>
    <table class="report-table" style="width:45%; margin-top:10px; margin-{{ $direction === 'rtl' ? 'right' : 'left' }}:55%; page-break-inside:avoid;"><tbody><tr><th>{{ __('quotations.attributes.subtotal') }}</th><td>{{ $numbers->format($revision->subtotal) }}</td></tr><tr><th>{{ __('quotations.attributes.discount_amount') }}</th><td>{{ $numbers->format($revision->discount_amount) }}</td></tr><tr><th>{{ __('quotations.attributes.tax_amount') }}</th><td>{{ $numbers->format($revision->tax_amount) }}</td></tr><tr><th>{{ __('quotations.print.grand_total') }}</th><td><strong>{{ $numbers->format($revision->total) }} {{ $record->currency?->code }}</strong></td></tr></tbody></table>
    @include('reports.partials.amount-in-words', ['amount' => $revision->total])
    @if($revision->paymentMilestones->isNotEmpty())
        <h3>{{ __('quotations.tabs.payments') }}</h3>
        <table class="report-table"><thead><tr><th>#</th><th>{{ __('quotations.attributes.milestone_title') }}</th><th>{{ __('quotations.attributes.due_type') }}</th><th>{{ __('quotations.attributes.due_date') }}</th><th>{{ __('quotations.attributes.amount') }}</th></tr></thead><tbody>
            @foreach($revision->paymentMilestones as $milestone)
                <tr><td>{{ $milestone->line_number }}</td><td>{{ $milestone->title }}@if($milestone->notes)<div class="document-item-details">{{ $milestone->notes }}</div>@endif</td><td>{{ $milestone->due_type ? __('quotations.due_types.'.$milestone->due_type) : '' }}</td><td>{{ $dates->formatDate($milestone->due_date, '') }}</td><td class="number">{{ $numbers->format($milestone->amount) }}</td></tr>
            @endforeach
        </tbody></table>
    @endif
    @if($revision->executionScheduleLines->isNotEmpty())
        <h3>{{ __('quotations.tabs.execution') }}</h3>
        <table class="report-table"><thead><tr><th>{{ __('quotations.attributes.phase_name') }}</th><th>{{ __('quotations.attributes.start_date') }}</th><th>{{ __('quotations.attributes.end_date') }}</th><th>{{ __('quotations.attributes.responsibility') }}</th></tr></thead><tbody>
            @foreach($revision->executionScheduleLines as $phase)
                <tr><td>{{ $phase->phase_name }}<div class="document-item-details">{{ $phase->description }} {{ $phase->notes }}</div></td><td>{{ $dates->formatDate($phase->start_date, '') }}</td><td>{{ $dates->formatDate($phase->end_date, '') }}</td><td>{{ $phase->responsibility }}</td></tr>
            @endforeach
        </tbody></table>
    @endif
    @include('reports.partials.document-terms', [
        'richText' => true,
        'terms' => collect(['notes_snapshot' => 'notes', 'terms_snapshot' => 'terms', 'payment_terms_snapshot' => 'payment_terms', 'execution_terms_snapshot' => 'execution_terms', 'delivery_terms_snapshot' => 'delivery_terms', 'warranty_terms_snapshot' => 'warranty_terms', 'technical_notes_snapshot' => 'technical_notes'])
            ->mapWithKeys(fn ($label, $field) => [__('quotations.attributes.'.$label) => $revision->{$field}])->filter(fn ($value) => trim(str_replace(' ', '', html_entity_decode(strip_tags((string) $value)))) !== ''),
    ])
    @include('reports.partials.company-authorization')
    <style>.sales-quotation-lines { table-layout:fixed; }.sales-quotation-lines th,.sales-quotation-lines td { overflow-wrap:break-word; padding:7px 4px; }.sales-quotation-lines thead { display:table-header-group; }.sales-quotation-lines tr { page-break-inside:avoid; }</style>
@endsection
