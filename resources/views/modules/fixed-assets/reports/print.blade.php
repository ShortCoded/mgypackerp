@extends('layouts.app')

@section('title', $report['title'])

@section('content')
    <div class="page-print-actions d-flex justify-content-end gap-2 mb-3"><button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">{{ __('common.actions.print') }}</button></div>
    <div class="card erp-document-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="card-body">
        <x-company-print-header :identity="$companyPrintIdentity" />
        <h3 class="mb-4">{{ $report['title'] }}</h3>
        <x-fixed-asset-report-table :report="$report" />
        <x-company-print-authorization :identity="$companyPrintIdentity" />
    </div></div>
@endsection
