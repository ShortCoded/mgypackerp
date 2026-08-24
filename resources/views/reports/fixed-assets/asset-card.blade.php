@extends('reports.fixed-assets.layouts.document')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $mapping = $asset->categoryMapping;
    $lastDepreciation = $asset->postedDepreciations->last();
    $latestMovement = $asset->movements->first();
    $latestDisposal = $asset->disposals->first();
    $currencyCode = $asset->currency?->code;
@endphp

@section('fixed_asset_content')
    <table class="fa-pdf-document-heading">
        <tr>
            <td>
                <div class="fa-pdf-document-title">{{ __('fixed_assets.lifecycle.asset_card') }}</div>
                <div class="fa-pdf-document-number"><span class="fa-pdf-code">{{ $asset->doc_num }}</span> / {{ $asset->asset_name }}</div>
            </td>
            @if ($assetImageSource)
                <td width="95" align="right"><img class="fa-pdf-asset-image" src="{{ $assetImageSource }}" alt="{{ $asset->asset_name }}"></td>
            @endif
        </tr>
    </table>

    <div class="fa-pdf-section">
        <div class="fa-pdf-section-title">{{ __('fixed_assets.pdf.sections.identity') }}</div>
        <table class="fa-pdf-meta-table">
            <tr>
                <td class="fa-pdf-meta-cell"><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.asset') }}</div><div class="fa-pdf-value fa-pdf-code">{{ $asset->doc_num }}</div></td>
                <td class="fa-pdf-meta-cell"><div class="fa-pdf-label">{{ __('fixed_assets.attributes.status') }}</div><div class="fa-pdf-value">{{ __('fixed_assets.statuses.'.$asset->status) }}</div></td>
            </tr>
            <tr>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.classification') }}</div><div class="fa-pdf-value">{{ $asset->assetGroupAccount?->codeNameLabel() ?: __('common.empty_value') }}</div></td>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.attributes.serial_number') }}</div><div class="fa-pdf-value fa-pdf-code">{{ $asset->serial_number ?: __('common.empty_value') }}</div></td>
            </tr>
        </table>
    </div>

    <div class="fa-pdf-section">
        <div class="fa-pdf-section-title">{{ __('fixed_assets.pdf.sections.acquisition') }}</div>
        <table class="fa-pdf-meta-table">
            @foreach ([
                [__('fixed_assets.attributes.entry_type'), $asset->entryTypeLabel(), __('fixed_assets.attributes.source_document'), $asset->source_doc_num],
                [__('fixed_assets.attributes.acquisition_date'), $dates->formatDate($asset->acquisition_date ?: $asset->purchase_date, ''), __('fixed_assets.attributes.operation_date'), $dates->formatDate($asset->operation_date, '')],
                [__('fixed_assets.reports.columns.cost'), $numbers->format($position['acquisition_cost']).' '.$currencyCode, __('fixed_assets.attributes.currency'), $currencyCode],
            ] as [$firstLabel, $firstValue, $secondLabel, $secondValue])
                <tr>
                    <td><div class="fa-pdf-label">{{ $firstLabel }}</div><div class="fa-pdf-value">{{ $firstValue ?: __('common.empty_value') }}</div></td>
                    <td><div class="fa-pdf-label">{{ $secondLabel }}</div><div class="fa-pdf-value">{{ $secondValue ?: __('common.empty_value') }}</div></td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="fa-pdf-section">
        <div class="fa-pdf-section-title">{{ __('fixed_assets.pdf.sections.accounting') }}</div>
        <table class="fa-pdf-meta-table">
            @foreach ([
                [__('fixed_assets.attributes.account'), $asset->account?->codeNameLabel(), __('fixed_assets.lifecycle.mapping_fields.accumulated_depreciation_account_doc_num'), $mapping?->accumulatedDepreciationAccount?->codeNameLabel()],
                [__('fixed_assets.lifecycle.mapping_fields.depreciation_expense_account_doc_num'), $mapping?->depreciationExpenseAccount?->codeNameLabel(), __('fixed_assets.attributes.cost_center'), $asset->costCenter?->codeNameLabel()],
            ] as [$firstLabel, $firstValue, $secondLabel, $secondValue])
                <tr>
                    <td><div class="fa-pdf-label">{{ $firstLabel }}</div><div class="fa-pdf-value">{{ $firstValue ?: __('common.empty_value') }}</div></td>
                    <td><div class="fa-pdf-label">{{ $secondLabel }}</div><div class="fa-pdf-value">{{ $secondValue ?: __('common.empty_value') }}</div></td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="fa-pdf-section">
        <div class="fa-pdf-section-title">{{ __('fixed_assets.pdf.sections.depreciation') }}</div>
        <table class="fa-pdf-values-table">
            <tr>
                <th>{{ __('fixed_assets.attributes.depreciation_method') }}</th><td>{{ $asset->depreciationMethodLabel() ?: __('common.empty_value') }}</td>
                <th>{{ __('fixed_assets.attributes.useful_life') }}</th><td class="fa-pdf-number">{{ $asset->useful_life ? $numbers->format($asset->useful_life) : __('common.empty_value') }}</td>
            </tr>
            <tr>
                <th>{{ __('fixed_assets.attributes.annual_depreciation_rate') }}</th><td class="fa-pdf-number">{{ $asset->annual_depreciation_rate ? $numbers->format($asset->annual_depreciation_rate).'%' : __('common.empty_value') }}</td>
                <th>{{ __('fixed_assets.reports.columns.residual_value') }}</th><td class="fa-pdf-number">{{ $numbers->format($position['residual_value']) }}</td>
            </tr>
            <tr>
                <th>{{ __('fixed_assets.reports.columns.accumulated_depreciation') }}</th><td class="fa-pdf-number">{{ $numbers->format($position['accumulated_depreciation']) }}</td>
                <th>{{ __('fixed_assets.reports.columns.net_book_value') }}</th><td class="fa-pdf-number">{{ $numbers->format($position['net_book_value']) }}</td>
            </tr>
            <tr>
                <th>{{ __('fixed_assets.pdf.last_depreciation_date') }}</th><td>{{ $dates->formatDate($lastDepreciation?->period_end, '') ?: __('common.empty_value') }}</td>
                <th>{{ __('fixed_assets.reports.columns.journal_entry') }}</th><td class="fa-pdf-code">{{ $lastDepreciation?->journalEntry?->doc_num ?: __('common.empty_value') }}</td>
            </tr>
        </table>
    </div>

    <div class="fa-pdf-section">
        <div class="fa-pdf-section-title">{{ __('fixed_assets.pdf.sections.location') }}</div>
        <table class="fa-pdf-meta-table">
            <tr>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.attributes.branch') }}</div><div class="fa-pdf-value">{{ $asset->branch?->name ?: __('common.empty_value') }}</div></td>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.attributes.hall') }}</div><div class="fa-pdf-value">{{ $asset->branchHall?->name ?: __('common.empty_value') }}</div></td>
            </tr>
            <tr>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.attributes.location_address') }}</div><div class="fa-pdf-value">{{ $asset->location_address ?: __('common.empty_value') }}</div></td>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.attributes.cost_center') }}</div><div class="fa-pdf-value">{{ $asset->costCenter?->codeNameLabel() ?: __('common.empty_value') }}</div></td>
            </tr>
        </table>
    </div>

    <div class="fa-pdf-section">
        <div class="fa-pdf-section-title">{{ __('fixed_assets.pdf.sections.lifecycle_summary') }}</div>
        <table class="fa-pdf-meta-table">
            <tr>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.pdf.latest_transfer') }}</div><div class="fa-pdf-value">{{ $latestMovement ? $latestMovement->doc_num.' / '.$dates->formatDate($latestMovement->movement_date, '') : __('common.empty_value') }}</div></td>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.pdf.latest_disposition') }}</div><div class="fa-pdf-value">{{ $latestDisposal ? $latestDisposal->doc_num.' / '.__('fixed_assets.lifecycle.disposition_types.'.$latestDisposal->disposition_type) : __('common.empty_value') }}</div></td>
            </tr>
            <tr>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.journal_entry') }}</div><div class="fa-pdf-value fa-pdf-code">{{ $latestDisposal?->journalEntry?->doc_num ?: $lastDepreciation?->journalEntry?->doc_num ?: __('common.empty_value') }}</div></td>
                <td><div class="fa-pdf-label">{{ __('fixed_assets.attributes.notes') }}</div><div class="fa-pdf-value">{{ $asset->notes ?: __('common.empty_value') }}</div></td>
            </tr>
        </table>
    </div>

    @include('reports.fixed-assets.partials.authorization')
@endsection
