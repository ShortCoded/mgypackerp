@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $imageUrl = app(\Modules\FixedAssets\Services\FixedAssetImageResolver::class)->url($asset);
    $mapping = $asset->categoryMapping;
@endphp

@section('title', __('fixed_assets.lifecycle.asset_card').' '.$asset->doc_num)

@push('styles')
    <style>@page { size: A4 portrait; margin: 12mm; }</style>
@endpush

@section('content')
    <div class="page-print-actions d-flex justify-content-end gap-2 mb-3">
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.lifecycle.show', $asset) }}">{{ __('common.actions.back') }}</a>
        <button class="btn btn-falcon-primary btn-sm" type="button" onclick="window.print()">{{ __('common.actions.print') }}</button>
    </div>
    <div class="card erp-document-print" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="card-body">
        <x-company-print-header :identity="$companyPrintIdentity" />
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div><h3>{{ __('fixed_assets.lifecycle.asset_card') }}</h3><div class="fw-semibold">{{ $asset->doc_num }} / {{ $asset->asset_name }}</div></div>
            @if($imageUrl)<img src="{{ $imageUrl }}" alt="{{ $asset->asset_name }}" style="width:110px;height:110px;object-fit:contain">@endif
        </div>
        <div class="row g-3 mb-4">
            @foreach([
                __('fixed_assets.attributes.status') => __('fixed_assets.statuses.'.$asset->status),
                __('fixed_assets.attributes.entry_type') => $asset->entryTypeLabel(),
                __('fixed_assets.attributes.source_document') => $asset->source_doc_num,
                __('fixed_assets.attributes.asset_group_account') => $asset->assetGroupAccount?->codeNameLabel(),
                __('fixed_assets.attributes.serial_number') => $asset->serial_number,
                __('fixed_assets.attributes.purchase_date') => $dates->formatDate($asset->purchase_date, ''),
                __('fixed_assets.attributes.operation_date') => $dates->formatDate($asset->operation_date, ''),
                __('fixed_assets.attributes.depreciation_method') => $asset->depreciationMethodLabel(),
                __('fixed_assets.attributes.depreciation_start_date') => $dates->formatDate($asset->depreciation_start_date, ''),
                __('fixed_assets.attributes.useful_life') => $asset->useful_life,
                __('fixed_assets.attributes.annual_depreciation_rate') => $asset->annual_depreciation_rate,
                __('fixed_assets.attributes.account') => $asset->account?->codeNameLabel(),
                __('fixed_assets.lifecycle.mapping_fields.accumulated_depreciation_account_doc_num') => $mapping?->accumulatedDepreciationAccount?->codeNameLabel(),
                __('fixed_assets.lifecycle.mapping_fields.depreciation_expense_account_doc_num') => $mapping?->depreciationExpenseAccount?->codeNameLabel(),
                __('fixed_assets.attributes.branch') => $asset->branch?->name,
                __('fixed_assets.attributes.hall') => $asset->branchHall?->name,
                __('fixed_assets.attributes.location_address') => $asset->location_address,
                __('fixed_assets.attributes.cost_center') => $asset->costCenter?->codeNameLabel(),
            ] as $label => $value)
                <div class="col-md-4"><div class="text-700">{{ $label }}</div><div class="fw-semibold">{{ $value ?: __('common.empty_value') }}</div></div>
            @endforeach
        </div>
        <table class="table table-sm table-bordered mb-4"><tbody>
            @foreach([
                __('fixed_assets.reports.columns.cost') => $position['acquisition_cost'],
                __('fixed_assets.reports.columns.depreciation_base') => $position['depreciation_base'],
                __('fixed_assets.reports.columns.accumulated_depreciation') => $position['accumulated_depreciation'],
                __('fixed_assets.reports.columns.net_book_value') => $position['net_book_value'],
                __('fixed_assets.reports.columns.residual_value') => $position['residual_value'],
            ] as $label => $value)<tr><th>{{ $label }}</th><td class="text-end" dir="ltr">{{ $numbers->format($value) }} {{ $asset->currency?->code }}</td></tr>@endforeach
        </tbody></table>
        <h6>{{ __('fixed_assets.lifecycle.depreciation_history') }}</h6>
        <table class="table table-sm table-bordered mb-4"><thead><tr><th>{{ __('fixed_assets.reports.columns.period') }}</th><th>{{ __('fixed_assets.reports.columns.period_depreciation') }}</th><th>{{ __('fixed_assets.reports.columns.accumulated_after') }}</th><th>{{ __('fixed_assets.reports.columns.net_book_value') }}</th><th>{{ __('fixed_assets.attributes.cost_center') }}</th><th>{{ __('fixed_assets.reports.columns.journal_entry') }}</th></tr></thead><tbody>
            @forelse($asset->postedDepreciations as $depreciation)<tr><td>{{ $dates->formatDate($depreciation->period_start, '') }} - {{ $dates->formatDate($depreciation->period_end, '') }}</td><td dir="ltr">{{ $numbers->format($depreciation->period_depreciation) }}</td><td dir="ltr">{{ $numbers->format($depreciation->accumulated_after) }}</td><td dir="ltr">{{ $numbers->format($depreciation->closing_net_book_value) }}</td><td>{{ $depreciation->costCenter?->codeNameLabel() }}</td><td>{{ $depreciation->journalEntry?->doc_num }}</td></tr>@empty<tr><td colspan="6" class="text-center">{{ __('common.empty_value') }}</td></tr>@endforelse
        </tbody></table>
        <h6>{{ __('fixed_assets.lifecycle.movement_history') }}</h6>
        <table class="table table-sm table-bordered mb-4"><thead><tr><th>{{ __('fixed_assets.reports.columns.document') }}</th><th>{{ __('fixed_assets.reports.columns.date') }}</th><th>{{ __('fixed_assets.reports.columns.source') }}</th><th>{{ __('fixed_assets.reports.columns.destination') }}</th><th>{{ __('fixed_assets.lifecycle.reason') }}</th></tr></thead><tbody>
            @forelse($asset->movements as $movement)<tr><td>{{ $movement->doc_num }}</td><td>{{ $dates->formatDate($movement->movement_date, '') }}</td><td>{{ $movement->sourceBranch?->name }} / {{ $movement->source_location_address }}</td><td>{{ $movement->destinationBranch?->name }} / {{ $movement->destination_location_address }}</td><td>{{ $movement->reason }}</td></tr>@empty<tr><td colspan="5" class="text-center">{{ __('common.empty_value') }}</td></tr>@endforelse
        </tbody></table>
        <x-company-print-authorization :identity="$companyPrintIdentity" />
    </div></div>
@endsection
