@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('title', __('quotations.print.title').' '.$record->doc_num.' / '.$revision->revision_code)

@push('styles')
<style>
    @page { size: A4 landscape; margin: 12mm; }
    .quotation-print table { break-inside: auto; }
    .quotation-print tr { break-inside: avoid; break-after: auto; }
    .quotation-print thead { display: table-header-group; }
    .quotation-print tfoot, .quotation-print .print-summary, .quotation-print .print-terms { break-inside: avoid; page-break-inside: avoid; }
    @media print { .page-print-actions { display: none !important; } .quotation-print { box-shadow: none !important; border: 0 !important; } .quotation-print .table-responsive { overflow: visible !important; } }
</style>
@endpush

@section('content')
    <div class="page-print-actions d-flex justify-content-end gap-2 mb-3">
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.quotations.show', $record) }}">{{ __('common.actions.back') }}</a>
        <button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">{{ __('quotations.actions.print') }}</button>
    </div>

    <div class="card erp-document-print quotation-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
        <div class="card-body">
            <x-company-print-header :identity="$companyPrintIdentity" />

            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h3 class="mb-1">{{ __('quotations.print.title') }}</h3>
                    <div class="text-700" dir="ltr">{{ $record->doc_num }} / {{ $revision->revision_code }}</div>
                </div>
                <div class="text-end">
                    <span class="badge bg-secondary">{{ __('quotations.statuses.'.$revision->status) }}</span>
                    <div class="mt-2">{{ $dates->formatDate($revision->revision_date, '') }}</div>
                    @if($record->valid_until)<small>{{ __('quotations.attributes.valid_until') }}: {{ $dates->formatDate($record->valid_until, '') }}</small>@endif
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-4"><strong>{{ __('quotations.attributes.customer') }}</strong><div>{{ $record->customer?->doc_num }} / {{ $record->customer?->name }}</div></div>
                <div class="col-4"><strong>{{ __('quotations.attributes.branch') }}</strong><div>{{ $record->branch?->doc_num }} / {{ $record->branch?->name }}</div></div>
                <div class="col-4"><strong>{{ __('quotations.attributes.sales_person') }}</strong><div>{{ $record->salesPerson?->doc_num }} / {{ $record->salesPerson?->name }}</div></div>
                <div class="col-4"><strong>{{ __('quotations.attributes.customer_reference') }}</strong><div>{{ $record->customer_reference ?: __('common.empty_value') }}</div></div>
                <div class="col-4"><strong>{{ __('quotations.attributes.currency') }}</strong><div>{{ $record->currency?->code }} · {{ __('quotations.attributes.exchange_rate') }}: <span dir="ltr">{{ $numbers->format($record->exchange_rate) }}</span></div></div>
                <div class="col-4"><strong>{{ __('quotations.attributes.subject') }}</strong><div>{{ $record->subject ?: $record->project_name ?: __('common.empty_value') }}</div></div>
            </div>

            <table class="table table-sm table-bordered align-middle">
                <thead class="bg-100"><tr>
                    <th>#</th><th>{{ __('quotations.attributes.product') }}</th><th>{{ __('quotations.attributes.unit') }}</th>
                    <th class="text-end">{{ __('quotations.attributes.quantity') }}</th><th>{{ __('quotations.attributes.requested_date') }}</th>
                    <th>{{ __('quotations.attributes.specifications') }}</th><th class="text-end">{{ __('quotations.attributes.unit_price') }}</th>
                    <th class="text-end">{{ __('quotations.attributes.discount_amount') }}</th><th class="text-end">{{ __('quotations.attributes.tax_amount') }}</th>
                    <th class="text-end">{{ __('quotations.attributes.total') }}</th>
                </tr></thead>
                <tbody>
                    @foreach($revision->lines as $line)
                        @php $specifications = collect($line->specifications ?? [])->filter(); @endphp
                        <tr>
                            <td>{{ $line->line_number }}</td>
                            <td>{{ trim(implode(' / ', array_filter([$line->product?->doc_num, $line->product_name_snapshot, $line->description]))) }}@if($line->notes)<br><small>{{ $line->notes }}</small>@endif</td>
                            <td>{{ trim(implode(' / ', array_filter([$line->unit?->doc_num, $line->unit_name_snapshot]))) }}</td>
                            <td class="text-end" dir="ltr">{{ $numbers->format($line->quantity) }}<br><small>{{ __('quotations.attributes.base_quantity') }}: {{ $numbers->format($line->base_quantity) }}</small></td>
                            <td>{{ $dates->formatDate($line->requested_date, __('common.empty_value')) }}</td>
                            <td>{{ $specifications->map(fn($value, $key) => __('quotations.attributes.'.$key).': '.$value)->join(' · ') ?: __('common.empty_value') }}@if($line->warehouse_notes)<br><small>{{ __('quotations.attributes.warehouse_notes') }}: {{ $line->warehouse_notes }}</small>@endif @if($line->production_notes)<br><small>{{ __('quotations.attributes.production_notes') }}: {{ $line->production_notes }}</small>@endif</td>
                            <td class="text-end" dir="ltr">{{ $numbers->format($line->unit_price) }}</td>
                            <td class="text-end" dir="ltr">{{ $numbers->format($line->discount_amount) }}</td>
                            <td class="text-end" dir="ltr">{{ $numbers->format($line->tax_amount) }}</td>
                            <td class="text-end" dir="ltr">{{ $numbers->format($line->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="row justify-content-end print-summary mt-3">
                <div class="col-md-6 col-lg-5"><table class="table table-sm table-bordered mb-0"><tbody>
                    <tr><th>{{ __('quotations.attributes.subtotal') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($revision->subtotal) }}</td></tr>
                    <tr><th>{{ __('quotations.attributes.discount_amount') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($revision->discount_amount) }}</td></tr>
                    <tr><th>{{ __('quotations.attributes.tax_amount') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($revision->tax_amount) }}</td></tr>
                    <tr class="fw-bold"><th>{{ __('quotations.print.grand_total') }}</th><td class="text-end" dir="ltr">{{ $numbers->format($revision->total) }} {{ $record->currency?->code }}</td></tr>
                </tbody></table></div>
            </div>

            @if($revision->paymentMilestones->isNotEmpty())
                <h6 class="mt-4">{{ __('quotations.tabs.payments') }}</h6>
                <table class="table table-sm table-bordered"><thead><tr><th>#</th><th>{{ __('quotations.attributes.milestone_title') }}</th><th>{{ __('quotations.attributes.due_type') }}</th><th>{{ __('quotations.attributes.due_date') }}</th><th class="text-end">{{ __('quotations.attributes.amount') }}</th></tr></thead><tbody>
                    @foreach($revision->paymentMilestones as $milestone)<tr><td>{{ $milestone->line_number }}</td><td>{{ $milestone->title }}</td><td>{{ $milestone->due_type ? __('quotations.due_types.'.$milestone->due_type) : __('common.empty_value') }}</td><td>{{ $dates->formatDate($milestone->due_date, __('common.empty_value')) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($milestone->amount) }}</td></tr>@endforeach
                </tbody></table>
            @endif

            <div class="row g-3 mt-2 print-terms">
                @foreach(['terms_snapshot' => 'terms', 'payment_terms_snapshot' => 'payment_terms', 'delivery_terms_snapshot' => 'delivery_terms', 'warranty_terms_snapshot' => 'warranty_terms', 'technical_notes_snapshot' => 'technical_notes'] as $field => $label)
                    @if(filled($revision->{$field}))<div class="col-6"><strong>{{ __('quotations.attributes.'.$label) }}</strong><div>{!! $revision->{$field} !!}</div></div>@endif
                @endforeach
            </div>

            <x-company-print-authorization :identity="$companyPrintIdentity" />
        </div>
    </div>
@endsection
