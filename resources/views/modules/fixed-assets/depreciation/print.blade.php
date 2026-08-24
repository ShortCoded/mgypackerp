@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

@section('title', __('fixed_assets.lifecycle.depreciation_run').' '.$run->doc_num)

@push('styles')
    <style>@page { size: A4 landscape; margin: 12mm; }</style>
@endpush

@section('content')
    <div class="page-print-actions d-flex justify-content-end mb-3"><button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">{{ __('common.actions.print') }}</button></div>
    <div class="card erp-document-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="card-body">
        <x-company-print-header :identity="$companyPrintIdentity" />
        <h3>{{ __('fixed_assets.lifecycle.depreciation_run') }}</h3><div class="mb-4 fw-semibold">{{ $run->doc_num }}</div>
        <div class="row g-3 mb-4">
            @foreach([
                __('fixed_assets.lifecycle.financial_period') => $run->financialPeriod?->name,
                __('fixed_assets.reports.columns.period') => $dates->formatDate($run->period_start, '').' - '.$dates->formatDate($run->period_end, ''),
                __('fixed_assets.lifecycle.posting_date') => $dates->formatDate($run->posting_date, ''),
                __('fixed_assets.attributes.branch') => $run->branch?->name,
                __('fixed_assets.reports.columns.journal_entry') => $run->journalEntry?->doc_num,
                __('fixed_assets.reports.columns.status') => __('fixed_assets.lifecycle.statuses.'.$run->status),
            ] as $label => $value)<div class="col-md-4"><div class="text-700">{{ $label }}</div><div class="fw-semibold">{{ $value ?: __('common.empty_value') }}</div></div>@endforeach
        </div>
        <table class="table table-sm table-bordered mb-4"><thead><tr><th>{{ __('fixed_assets.reports.columns.asset') }}</th><th>{{ __('fixed_assets.reports.columns.period_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.accumulated_before') }}</th><th>{{ __('fixed_assets.reports.columns.accumulated_after') }}</th><th>{{ __('fixed_assets.reports.columns.closing_net_book_value') }}</th><th>{{ __('fixed_assets.attributes.cost_center') }}</th></tr></thead><tbody>
            @foreach($run->lines as $line)<tr><td>{{ $line->asset?->doc_num }} / {{ $line->asset?->asset_name }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->period_depreciation) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->accumulated_before) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->accumulated_after) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->closing_net_book_value) }}</td><td>{{ $line->costCenter?->codeNameLabel() }}</td></tr>@endforeach
        </tbody><tfoot><tr><th>{{ __('common.total') }}</th><th class="text-end" dir="ltr">{{ $numbers->format($run->total_depreciation) }}</th><th colspan="4"></th></tr></tfoot></table>
        <x-company-print-authorization :identity="$companyPrintIdentity" />
    </div></div>
@endsection
