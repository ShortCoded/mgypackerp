@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

@section('title', __('Cheque').' '.$record->doc_num)

@section('content')
<div class="page-print-actions d-flex justify-content-end gap-2 mb-3">
    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.finance.cheques.show', $record->doc_num) }}">{{ __('Back') }}</a>
    <button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()"><span class="fas fa-print me-1"></span>{{ __('Print') }}</button>
</div>
<div class="card erp-document-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
    <div class="card-body">
        <x-company-print-header :identity="$companyPrintIdentity" />
        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
            <div><h3 class="mb-1">{{ $record->isIssued() ? __('Issued / Payment Cheque') : __('Received Cheque') }}</h3><div class="text-700" dir="ltr">{{ $record->doc_num }}</div></div>
            <div class="text-end"><div class="fw-semibold">{{ str($record->status)->replace('_', ' ')->title() }}</div><div dir="ltr">{{ $dates->formatDate($record->cheque_date, '—') }}</div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-4"><div class="text-700">{{ __('Cheque number') }}</div><div class="fw-semibold" dir="ltr">{{ $record->cheque_number }}</div></div>
            <div class="col-md-4"><div class="text-700">{{ __('Due date') }}</div><div class="fw-semibold" dir="ltr">{{ $dates->formatDate($record->due_date, '—') }}</div></div>
            <div class="col-md-4"><div class="text-700">{{ __('Beneficiary / party') }}</div><div class="fw-semibold">{{ $record->party_name ?: '—' }}</div></div>
            <div class="col-md-6"><div class="text-700">{{ __('Issuing bank / branch / account') }}</div><div class="fw-semibold">{{ $record->bankAccount?->bank?->name ?? $record->bankAccount?->bank?->name_en }} / {{ $record->bankAccount?->bank_branch_name ?: '—' }} / <span dir="ltr">{{ $record->bankAccount?->account_number }}</span></div></div>
            <div class="col-md-3"><div class="text-700">{{ __('Currency') }}</div><div class="fw-semibold">{{ $record->currency?->code }} / {{ $record->currency?->name }}</div></div>
            <div class="col-md-3"><div class="text-700">{{ __('Amount') }}</div><div class="fw-semibold" dir="ltr">{{ $numbers->format($record->amount) }}</div></div>
            <div class="col-12"><div class="text-700">{{ __('Reason') }}</div><div class="fw-semibold">{{ $record->reason }}</div></div>
        </div>
        <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="bg-100"><tr><th>#</th><th>{{ __('Account') }}</th><th>{{ __('Description') }}</th><th class="text-end">{{ __('Amount') }}</th></tr></thead>
            <tbody>@foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->account?->codeNameLabel() }}</td><td>{{ $line->description ?: '—' }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->amount) }}</td></tr>@endforeach</tbody>
            <tfoot><tr class="fw-bold"><th colspan="3">{{ __('Total') }}</th><th class="text-end" dir="ltr">{{ $numbers->format($record->amount) }}</th></tr></tfoot>
        </table>
        <x-company-print-authorization :identity="$companyPrintIdentity" />
    </div>
</div>
@endsection
