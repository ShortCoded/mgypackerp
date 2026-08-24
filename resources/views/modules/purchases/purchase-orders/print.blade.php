@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

@section('title', __('purchase_orders.print_title', ['doc' => $record->doc_num]))

@push('styles')
    <style>
        @media print {
            .purchase-order-print table { break-inside: auto; }
            .purchase-order-print tr { break-inside: avoid; page-break-inside: avoid; }
            .purchase-order-print thead { display: table-header-group; }
            .purchase-order-print tfoot { display: table-row-group; break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
@endpush

@section('content')
    <div class="page-print-actions d-flex justify-content-end gap-2 mb-3">
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-orders.show', $record->doc_num) }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
        <button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">
            <span class="fas fa-print me-1"></span>{{ __('purchase_orders.actions.print') }}
        </button>
    </div>

    <div class="card erp-document-print purchase-order-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
        <div class="card-body">
            <x-company-print-header :identity="$companyPrintIdentity" />
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h3 class="mb-1">{{ __('purchase_orders.singular') }}</h3>
                    <div class="text-700">{{ $record->doc_num }}</div>
                </div>
                <div class="text-end">
                    @include('modules.purchases.purchase-orders.partials.status', ['record' => $record])
                    <div class="mt-2">{{ $dates->formatDate($record->document_date, '') }}</div>
                </div>
            </div>

            <div class="row g-3 mb-4">
            <div class="col-6">
                <strong>{{ __('purchase_orders.attributes.supplier') }}</strong>
                <div>{{ trim(implode(' / ', array_filter([$record->supplier?->doc_num, $record->supplier?->name]))) ?: __('common.empty_value') }}</div>
            </div>
            <div class="col-6">
                <strong>{{ __('purchase_orders.attributes.branch_store') }}</strong>
                <div>{{ $record->branchStore?->name ?: __('common.empty_value') }}</div>
            </div>
            <div class="col-4">
                <strong>{{ __('purchase_orders.attributes.currency') }}</strong>
                <div>{{ trim(implode(' / ', array_filter([$record->currency?->code, $record->currency?->name]))) ?: __('common.empty_value') }}</div>
            </div>
            <div class="col-4">
                <strong>{{ __('purchase_orders.attributes.exchange_rate') }}</strong>
                <div dir="ltr">{{ $numbers->format($record->exchange_rate) }}</div>
            </div>
            <div class="col-4">
                <strong>{{ __('purchase_orders.attributes.expected_delivery_date') }}</strong>
                <div>{{ $dates->formatDate($record->expected_delivery_date, __('common.empty_value')) }}</div>
            </div>
            <div class="col-4">
                <strong>{{ __('Freight') }}</strong>
                <div dir="ltr">{{ $numbers->format($record->freight_amount) }}</div>
            </div>
            @if($record->supplier_reference)
                <div class="col-12">
                    <strong>{{ __('purchase_orders.attributes.supplier_reference') }}</strong>
                    <div>{{ $record->supplier_reference }}</div>
                </div>
            @endif
            </div>

            <table class="table table-sm table-bordered align-middle">
            <thead>
                <tr>
                    <th style="width: 3rem;">#</th>
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
                    @php
                        $snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];
                    @endphp
                    <tr>
                        <td>{{ $line->line_number }}</td>
                        <td>{{ trim(implode(' / ', array_filter([$snapshot['doc_num'] ?? $line->product?->doc_num, $snapshot['name'] ?? $line->product?->name]))) }}</td>
                        <td>{{ $snapshot['unit_label'] ?? trim(implode(' / ', array_filter([$line->unit?->doc_num, $line->unit?->name]))) }}</td>
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
            <tfoot>
                <tr>
                    <th colspan="3">{{ __('purchase_orders.totals.net_total') }}</th>
                    <th class="text-end" dir="ltr">{{ $numbers->format($record->total_ordered_quantity) }}</th>
                    <th colspan="3"></th>
                    <th class="text-end" dir="ltr">{{ $numbers->format($record->total_amount) }}</th>
                    <th class="text-end" dir="ltr">{{ $numbers->format($record->total_received_quantity) }}</th>
                    <th class="text-end" dir="ltr">{{ $numbers->format($record->total_remaining_quantity) }}</th>
                </tr>
            </tfoot>
            </table>

            @if($record->notes)
                <div class="mt-4">
                    <strong>{{ __('purchase_orders.attributes.notes') }}</strong>
                    <div>{{ $record->notes }}</div>
                </div>
            @endif
            <x-company-print-authorization :identity="$companyPrintIdentity" />
        </div>
    </div>
@endsection
