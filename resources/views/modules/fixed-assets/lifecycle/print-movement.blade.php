@extends('layouts.app')

@php($dates = app(\Modules\Core\Services\DateFormatService::class))

@section('title', __('fixed_assets.lifecycle.transfer').' '.$movement->doc_num)

@section('content')
    <div class="page-print-actions d-flex justify-content-end mb-3"><button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">{{ __('common.actions.print') }}</button></div>
    <div class="card erp-document-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="card-body">
        <x-company-print-header :identity="$companyPrintIdentity" />
        <h3>{{ __('fixed_assets.lifecycle.transfer') }}</h3><div class="mb-4 fw-semibold">{{ $movement->doc_num }}</div>
        <div class="row g-3 mb-4">
            @foreach([
                __('fixed_assets.reports.columns.asset') => $movement->asset?->doc_num.' / '.$movement->asset?->asset_name,
                __('fixed_assets.lifecycle.movement_date') => $dates->formatDate($movement->movement_date, ''),
                __('fixed_assets.reports.columns.source') => trim(implode(' / ', array_filter([$movement->sourceBranch?->name, $movement->sourceBranchHall?->name, $movement->source_location_address, $movement->sourceCostCenter?->codeNameLabel()]))),
                __('fixed_assets.reports.columns.destination') => trim(implode(' / ', array_filter([$movement->destinationBranch?->name, $movement->destinationBranchHall?->name, $movement->destination_location_address, $movement->destinationCostCenter?->codeNameLabel()]))),
                __('fixed_assets.lifecycle.reason') => $movement->reason,
                __('fixed_assets.attributes.notes') => $movement->notes,
                __('fixed_assets.reports.columns.posted_by_date') => trim(implode(' / ', array_filter([$movement->requestedBy?->name, $movement->posted_at?->toDateTimeString()]))),
            ] as $label => $value)<div class="col-md-6"><div class="text-700">{{ $label }}</div><div class="fw-semibold">{{ $value ?: __('common.empty_value') }}</div></div>@endforeach
        </div>
        <x-company-print-authorization :identity="$companyPrintIdentity" />
    </div></div>
@endsection
