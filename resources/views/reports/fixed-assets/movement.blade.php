@extends('reports.fixed-assets.layouts.document')

@php($dates = app(\Modules\Core\Services\DateFormatService::class))

@section('fixed_asset_content')
    <table class="fa-pdf-document-heading">
        <tr><td><div class="fa-pdf-document-title">{{ __('fixed_assets.cycle.'.$movement->movement_type) }}</div><div class="fa-pdf-document-number fa-pdf-code">{{ $movement->doc_num }}</div></td></tr>
    </table>

    <table class="fa-pdf-meta-table">
        <tr>
            <td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.asset') }}</div><div class="fa-pdf-value"><span class="fa-pdf-code">{{ $movement->asset?->doc_num }}</span> / {{ $movement->asset?->asset_name }}</div></td>
            <td><div class="fa-pdf-label">{{ __('fixed_assets.lifecycle.movement_date') }}</div><div class="fa-pdf-value">{{ $dates->formatDate($movement->movement_date, '') }}</div></td>
        </tr>
        <tr>
            <td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.status') }}</div><div class="fa-pdf-value">{{ __('fixed_assets.lifecycle.statuses.'.$movement->status) }}</div></td>
            <td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.user') }}</div><div class="fa-pdf-value">{{ $movement->requestedBy?->name ?: __('common.empty_value') }}</div></td>
        </tr>
    </table>

    <div class="fa-pdf-section">
        <table class="fa-pdf-transfer-table">
            <tr>
                <th class="fa-pdf-transfer-side">{{ __('fixed_assets.pdf.from') }}</th>
                <td class="fa-pdf-transfer-arrow"></td>
                <th class="fa-pdf-transfer-side">{{ __('fixed_assets.pdf.to') }}</th>
            </tr>
            @foreach ([
                [__('fixed_assets.cycle.custody'), $movement->sourceCustodian?->full_name, $movement->destinationCustodian?->full_name],
                [__('fixed_assets.attributes.branch'), $movement->sourceBranch?->name, $movement->destinationBranch?->name],
                [__('fixed_assets.attributes.hall'), $movement->sourceBranchHall?->name, $movement->destinationBranchHall?->name],
                [__('fixed_assets.attributes.location_address'), $movement->source_location_address, $movement->destination_location_address],
                [__('fixed_assets.attributes.cost_center'), $movement->sourceCostCenter?->codeNameLabel(), $movement->destinationCostCenter?->codeNameLabel()],
            ] as [$label, $source, $destination])
                <tr>
                    <td><div class="fa-pdf-label">{{ $label }}</div><div class="fa-pdf-value">{{ $source ?: __('common.empty_value') }}</div></td>
                    <td class="fa-pdf-transfer-arrow">{{ app()->isLocale('ar') ? '←' : '→' }}</td>
                    <td><div class="fa-pdf-label">{{ $label }}</div><div class="fa-pdf-value">{{ $destination ?: __('common.empty_value') }}</div></td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="fa-pdf-section">
        <div class="fa-pdf-section-title">{{ __('fixed_assets.pdf.sections.transaction_details') }}</div>
        <table class="fa-pdf-meta-table">
            <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.lifecycle.reason') }}</div><div class="fa-pdf-value">{{ $movement->reason }}</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.attributes.notes') }}</div><div class="fa-pdf-value">{{ $movement->notes ?: __('common.empty_value') }}</div></td></tr>
            <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.pdf.requested_by') }}</div><div class="fa-pdf-value">{{ $movement->requestedBy?->name ?: __('common.empty_value') }}</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.pdf.posted_by') }}</div><div class="fa-pdf-value">{{ $movement->postedBy?->name ?: __('common.empty_value') }} @if($movement->posted_at) / {{ $dates->formatDateTime($movement->posted_at, '') }} @endif</div></td></tr>
        </table>
    </div>

    @if(in_array($movement->movement_type, ['capitalization', 'opening', 'addition'], true))
        <table class="fa-pdf-meta-table">
        @foreach(['amount' => $movement->amount, 'accumulated' => $movement->opening_accumulated, 'revised_life' => $movement->revised_useful_life, 'revised_residual' => $movement->revised_residual_value, 'usage_before_addition' => data_get($movement->snapshot, 'plan.usage_before_effective_date'), 'remaining_units' => data_get($movement->snapshot, 'plan.estimated_remaining_units'), 'journal_entry' => $movement->journalEntry?->doc_num] as $label => $value)
            <tr><td>{{ __('fixed_assets.cycle.'.$label) }}</td><td>{{ $value ?? '—' }}</td></tr>
        @endforeach
        <tr><td>{{ __('fixed_assets.attributes.exchange_rate') }}</td><td>{{ $movement->exchange_rate }}</td></tr>
        </table>
    @endif
    @include('reports.fixed-assets.partials.authorization')
@endsection
