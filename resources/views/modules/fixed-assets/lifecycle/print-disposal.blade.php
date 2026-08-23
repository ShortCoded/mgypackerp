@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

@section('title', __('fixed_assets.lifecycle.disposal').' '.$disposal->doc_num)

@section('content')
    <div class="page-print-actions d-flex justify-content-end mb-3"><button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">{{ __('common.actions.print') }}</button></div>
    <div class="card erp-document-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="card-body">
        <x-company-print-header :identity="$companyPrintIdentity" />
        <h3>{{ __('fixed_assets.lifecycle.disposal') }}</h3><div class="mb-4 fw-semibold">{{ $disposal->doc_num }} / {{ __('fixed_assets.lifecycle.disposition_types.'.$disposal->disposition_type) }}</div>
        <div class="row g-3 mb-4">
            @foreach([
                __('fixed_assets.reports.columns.asset') => $disposal->asset?->doc_num.' / '.$disposal->asset?->asset_name,
                __('fixed_assets.lifecycle.disposal_date') => $dates->formatDate($disposal->disposal_date, ''),
                __('fixed_assets.lifecycle.reason') => $disposal->reason,
                __('fixed_assets.lifecycle.customer') => $disposal->customer?->name,
                __('fixed_assets.lifecycle.proceeds_account') => $disposal->proceedsAccount?->codeNameLabel(),
                __('fixed_assets.reports.columns.journal_entry') => $disposal->journalEntry?->doc_num,
                __('fixed_assets.reports.columns.status') => __('fixed_assets.lifecycle.statuses.'.$disposal->status),
            ] as $label => $value)<div class="col-md-6"><div class="text-700">{{ $label }}</div><div class="fw-semibold">{{ $value ?: __('common.empty_value') }}</div></div>@endforeach
        </div>
        <table class="table table-sm table-bordered mb-4"><tbody>
            @foreach([
                __('fixed_assets.reports.columns.cost') => $disposal->original_cost,
                __('fixed_assets.reports.columns.accumulated_depreciation') => $disposal->accumulated_depreciation,
                __('fixed_assets.reports.columns.net_book_value') => $disposal->net_book_value,
                __('fixed_assets.lifecycle.proceeds') => $disposal->proceeds,
                __('fixed_assets.reports.columns.gain') => $disposal->gain_amount,
                __('fixed_assets.reports.columns.loss') => $disposal->loss_amount,
            ] as $label => $value)<tr><th>{{ $label }}</th><td class="text-end" dir="ltr">{{ $numbers->format($value) }}</td></tr>@endforeach
        </tbody></table>
        <x-company-print-authorization :identity="$companyPrintIdentity" />
    </div></div>
@endsection
