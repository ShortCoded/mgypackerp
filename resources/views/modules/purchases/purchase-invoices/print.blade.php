@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

@section('title', __('purchase_invoices.print_title', ['doc' => $record->doc_num]))

@push('styles')
    <style>
        @media print {
            .navbar,
            .footer,
            .page-print-actions {
                display: none !important;
            }

            .card {
                border: 0 !important;
                box-shadow: none !important;
            }
        }
    </style>
@endpush

@section('content')
    <div class="page-print-actions d-flex justify-content-end gap-2 mb-3">
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.purchase-invoices.show', $record->doc_num) }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
        <button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">
            <span class="fas fa-print me-1"></span>{{ __('purchase_invoices.actions.print') }}
        </button>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="row g-3 mb-4">
                <div class="col">
                    <h4 class="mb-1">{{ __('purchase_invoices.singular') }}</h4>
                    <div class="text-700">{{ $record->doc_num }}</div>
                </div>
                <div class="col-auto text-end">
                    <div class="fw-semibold">{{ __('purchase_invoices.statuses.'.$record->status) }}</div>
                    <div class="text-700">{{ __('purchase_invoices.payment_statuses.'.$record->payment_status) }}</div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="text-700">{{ __('purchase_invoices.attributes.invoice_date') }}</div>
                    <div class="fw-semibold" dir="ltr">{{ $record->invoice_date ? $dates->formatDate($record->invoice_date, '') : __('common.empty_value') }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __('purchase_invoices.attributes.financial_period') }}</div>
                    <div class="fw-semibold">{{ trim(implode(' / ', array_filter([$record->financialPeriod?->doc_num, $record->financialPeriod?->name]))) ?: __('common.empty_value') }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __('purchase_invoices.attributes.supplier') }}</div>
                    <div class="fw-semibold">{{ trim(implode(' / ', array_filter([$record->supplier?->doc_num, $record->supplier?->name]))) ?: __('common.empty_value') }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __('purchase_invoices.attributes.supplier_invoice_number') }}</div>
                    <div class="fw-semibold">{{ $record->supplier_invoice_number ?: __('common.empty_value') }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __('purchase_invoices.attributes.supplier_invoice_date') }}</div>
                    <div class="fw-semibold" dir="ltr">{{ $record->supplier_invoice_date ? $dates->formatDate($record->supplier_invoice_date, '') : __('common.empty_value') }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-700">{{ __('purchase_invoices.attributes.payment_type') }}</div>
                    <div class="fw-semibold">{{ __('purchase_invoices.payment_types.'.$record->payment_type) }}</div>
                </div>
            </div>

            <div class="table-responsive mb-4">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="bg-100">
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
                                <td class="text-end fw-semibold" dir="ltr">{{ $numbers->format($line->total_after_tax) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <h6>{{ __('purchase_invoices.sections.payment_schedule') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="bg-100">
                                <tr>
                                    <th>{{ __('purchase_invoices.attributes.due_date') }}</th>
                                    <th class="text-end">{{ __('purchase_invoices.attributes.payment_amount') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.payment_source_type') }}</th>
                                    <th>{{ __('purchase_invoices.attributes.linked_payment_voucher') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($record->paymentSchedules as $schedule)
                                    <tr>
                                        <td dir="ltr">{{ $schedule->due_date ? $dates->formatDate($schedule->due_date, '') : __('common.empty_value') }}</td>
                                        <td class="text-end" dir="ltr">{{ $numbers->format($schedule->amount) }}</td>
                                        <td>{{ __('purchase_invoices.source_types.'.$schedule->payment_source_type) }}</td>
                                        <td>{{ $schedule->cashVoucher?->doc_num ?: __('common.empty_value') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-600">{{ __('purchase_invoices.messages.no_payment_schedule') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-6">
                    <h6>{{ __('purchase_invoices.sections.totals') }}</h6>
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr>
                                <th>{{ __('purchase_invoices.totals.subtotal') }}</th>
                                <td class="text-end" dir="ltr">{{ $numbers->format($record->subtotal_amount) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('purchase_invoices.totals.line_discounts') }}</th>
                                <td class="text-end" dir="ltr">{{ $numbers->format($record->line_discount_amount) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('purchase_invoices.totals.header_discount') }}</th>
                                <td class="text-end" dir="ltr">{{ $numbers->format($record->header_discount_amount) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('purchase_invoices.totals.tax') }}</th>
                                <td class="text-end" dir="ltr">{{ $numbers->format($record->tax_amount) }}</td>
                            </tr>
                            <tr class="fw-bold">
                                <th>{{ __('purchase_invoices.totals.net_total') }}</th>
                                <td class="text-end" dir="ltr">{{ $numbers->format($record->total_amount) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('purchase_invoices.totals.paid') }}</th>
                                <td class="text-end" dir="ltr">{{ $numbers->format($record->paid_amount) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('purchase_invoices.totals.remaining') }}</th>
                                <td class="text-end" dir="ltr">{{ $numbers->format($record->remaining_amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
