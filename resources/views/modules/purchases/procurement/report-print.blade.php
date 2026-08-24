@extends('layouts.app')

@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

@section('title', __('Procurement Report').' — '.__('procurement.reports.types.'.$reportType))

@section('content')
    <div class="page-print-actions d-flex justify-content-end mb-3">
        <button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()"><span class="fas fa-print me-1"></span>{{ __('Print') }}</button>
    </div>
    <div class="card erp-document-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
        <div class="card-body">
            <x-company-print-header :identity="$companyPrintIdentity" />
            <div class="mb-3">
                <h4 class="mb-1">{{ __('Procurement Report') }}</h4>
                <div>{{ __('procurement.reports.types.'.$reportType) }}</div>
                <div class="text-600 fs-11" dir="ltr">{{ now()->format('Y-m-d H:i') }}</div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle procurement-report-print-table">
                    <thead><tr>
                        <th>{{ __('Date') }}</th><th>{{ __('Document') }}</th><th>{{ __('Status') }}</th><th>{{ __('Supplier') }}</th><th>{{ __('Item') }}</th>
                        <th>{{ __('Purchase requisition') }}</th><th>{{ __('Purchase order') }}</th><th>{{ __('Branch / Warehouse') }}</th><th>{{ __('QC') }}</th>
                        <th>{{ __('Production / Work order') }}</th><th class="text-end">{{ __('Quantity') }}</th>
                        @if($showPrices)<th class="text-end">{{ __('Amount') }}</th>@endif<th class="text-end">{{ __('Outstanding') }}</th><th>{{ __('Overdue') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td dir="ltr">{{ $row['date'] ?: '—' }}</td><td dir="ltr">{{ $row['document'] ?: '—' }}</td><td>{{ $row['status'] ? __('procurement.statuses.'.$row['status']) : '—' }}</td>
                                <td>{{ $row['supplier'] ?: '—' }}</td><td>{{ $row['product'] ?: '—' }}</td><td dir="ltr">{{ $row['requisition'] ?: '—' }}</td>
                                <td dir="ltr">{{ $row['purchase_order'] ?: '—' }}</td><td>{{ collect([$row['branch'], $row['warehouse']])->filter()->join(' / ') ?: '—' }}</td>
                                <td>{{ $row['qc_status'] ? __('procurement.statuses.'.$row['qc_status']) : '—' }}</td><td dir="ltr">{{ collect([$row['production_order'], $row['work_order']])->filter()->join(' / ') ?: '—' }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($row['quantity']) }}</td>
                                @if($showPrices)<td class="text-end" dir="ltr">{{ $numbers->format($row['amount']) }}</td>@endif
                                <td class="text-end" dir="ltr">{{ $numbers->format($row['outstanding']) }}</td><td>{{ $row['overdue'] ? __('Yes') : __('No') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $showPrices ? 14 : 13 }}" class="text-center">{{ __('No matching records.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-company-print-authorization :identity="$companyPrintIdentity" />
        </div>
    </div>
@endsection

@push('styles')
    <style>
        @media print {
            .procurement-report-print-table { font-size: 8px; }
            .procurement-report-print-table thead { display: table-header-group; }
            .procurement-report-print-table tr { break-inside: avoid; }
        }
    </style>
@endpush
