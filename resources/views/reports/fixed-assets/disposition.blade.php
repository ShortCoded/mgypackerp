@extends('reports.fixed-assets.layouts.document')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $showProceeds = in_array($disposal->disposition_type, [\Modules\FixedAssets\Models\FixedAssetDisposal::TypeSale, \Modules\FixedAssets\Models\FixedAssetDisposal::TypeDisposal], true);
@endphp

@section('fixed_asset_content')
    <table class="fa-pdf-document-heading">
        <tr><td><div class="fa-pdf-document-title">{{ $title }}</div><div class="fa-pdf-document-number"><span class="fa-pdf-code">{{ $disposal->doc_num }}</span> / {{ __('fixed_assets.lifecycle.disposition_types.'.$disposal->disposition_type) }}</div></td></tr>
    </table>

    <table class="fa-pdf-meta-table">
        <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.asset') }}</div><div class="fa-pdf-value"><span class="fa-pdf-code">{{ $disposal->asset?->doc_num }}</span> / {{ $disposal->asset?->asset_name }}</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.lifecycle.disposal_date') }}</div><div class="fa-pdf-value">{{ $dates->formatDate($disposal->disposal_date, '') }}</div></td></tr>
        <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.lifecycle.reason') }}</div><div class="fa-pdf-value">{{ $disposal->reason }}</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.status') }}</div><div class="fa-pdf-value">{{ __('fixed_assets.lifecycle.statuses.'.$disposal->status) }}</div></td></tr>
        @if ($showProceeds)
            <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.lifecycle.customer') }}</div><div class="fa-pdf-value">{{ $disposal->customer?->name ?: __('common.empty_value') }}</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.lifecycle.proceeds_account') }}</div><div class="fa-pdf-value">{{ $disposal->proceedsAccount?->codeNameLabel() ?: __('common.empty_value') }}</div></td></tr>
        @endif
    </table>

    <div class="fa-pdf-section">
        <div class="fa-pdf-section-title">{{ __('fixed_assets.pdf.sections.financial_summary') }}</div>
        <table class="fa-pdf-values-table">
            <tr><th>{{ __('fixed_assets.reports.columns.cost') }}</th><td class="fa-pdf-number">{{ $numbers->format($disposal->original_cost) }}</td><th>{{ __('fixed_assets.reports.columns.accumulated_depreciation') }}</th><td class="fa-pdf-number">{{ $numbers->format($disposal->accumulated_depreciation) }}</td></tr>
            <tr><th>{{ __('fixed_assets.reports.columns.net_book_value') }}</th><td class="fa-pdf-number">{{ $numbers->format($disposal->net_book_value) }}</td>@if($showProceeds)<th>{{ __('fixed_assets.lifecycle.proceeds') }}</th><td class="fa-pdf-number">{{ $numbers->format($disposal->proceeds) }}</td>@else<th>{{ __('fixed_assets.reports.columns.status') }}</th><td>{{ __('fixed_assets.lifecycle.disposition_types.'.$disposal->disposition_type) }}</td>@endif</tr>
            @if ($showProceeds)
                <tr><th>{{ __('fixed_assets.reports.columns.gain') }}</th><td class="fa-pdf-number">{{ $numbers->format($disposal->gain_amount) }}</td><th>{{ __('fixed_assets.reports.columns.loss') }}</th><td class="fa-pdf-number">{{ $numbers->format($disposal->loss_amount) }}</td></tr>
            @endif
        </table>
    </div>

    <div class="fa-pdf-section">
        <div class="fa-pdf-section-title">{{ __('fixed_assets.pdf.sections.posting') }}</div>
        <table class="fa-pdf-meta-table">
            <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.lifecycle.financial_period') }}</div><div class="fa-pdf-value">{{ $disposal->financialPeriod?->name ?: __('common.empty_value') }}</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.journal_entry') }}</div><div class="fa-pdf-value fa-pdf-code">{{ $disposal->journalEntry?->doc_num ?: __('common.empty_value') }}</div></td></tr>
            <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.pdf.posted_by') }}</div><div class="fa-pdf-value">{{ $disposal->postedBy?->name ?: __('common.empty_value') }} @if($disposal->posted_at) / {{ $dates->formatDateTime($disposal->posted_at, '') }} @endif</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.attributes.notes') }}</div><div class="fa-pdf-value">{{ $disposal->notes ?: __('common.empty_value') }}</div></td></tr>
        </table>
    </div>

    @include('reports.fixed-assets.partials.authorization')
@endsection
