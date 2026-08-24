@extends('reports.fixed-assets.layouts.document')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

@section('fixed_asset_content')
    <table class="fa-pdf-document-heading">
        <tr><td><div class="fa-pdf-document-title">{{ __('fixed_assets.lifecycle.depreciation_run') }}</div><div class="fa-pdf-document-number fa-pdf-code">{{ $run->doc_num }}</div></td></tr>
    </table>

    <table class="fa-pdf-meta-table">
        <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.lifecycle.financial_period') }}</div><div class="fa-pdf-value">{{ $run->financialPeriod?->name }}</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.period') }}</div><div class="fa-pdf-value">{{ $dates->formatDate($run->period_start, '') }} - {{ $dates->formatDate($run->period_end, '') }}</div></td></tr>
        <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.lifecycle.posting_date') }}</div><div class="fa-pdf-value">{{ $dates->formatDate($run->posting_date, '') }}</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.attributes.branch') }}</div><div class="fa-pdf-value">{{ $run->branch?->name ?: __('reports.all_records') }}</div></td></tr>
        <tr><td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.journal_entry') }}</div><div class="fa-pdf-value fa-pdf-code">{{ $run->journalEntry?->doc_num ?: __('common.empty_value') }}</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.reports.columns.status') }}</div><div class="fa-pdf-value">{{ __('fixed_assets.lifecycle.statuses.'.$run->status) }}</div></td></tr>
    </table>

    <div class="fa-pdf-section">
        <table class="fa-pdf-report-table">
            <thead>
                <tr>
                    <th>{{ __('fixed_assets.reports.columns.asset') }}</th>
                    <th>{{ __('fixed_assets.reports.columns.classification') }}</th>
                    <th>{{ __('fixed_assets.reports.columns.cost') }}</th>
                    <th>{{ __('fixed_assets.reports.columns.residual_value') }}</th>
                    <th>{{ __('fixed_assets.reports.columns.period') }}</th>
                    <th>{{ __('fixed_assets.reports.columns.period_depreciation') }}</th>
                    <th>{{ __('fixed_assets.reports.columns.accumulated_before') }}</th>
                    <th>{{ __('fixed_assets.reports.columns.accumulated_after') }}</th>
                    <th>{{ __('fixed_assets.reports.columns.closing_net_book_value') }}</th>
                    <th>{{ __('fixed_assets.attributes.cost_center') }}</th>
                    <th>{{ __('fixed_assets.reports.columns.journal_entry') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($run->lines as $line)
                    <tr>
                        <td><span class="fa-pdf-code">{{ $line->asset?->doc_num }}</span> / {{ $line->asset?->asset_name }}</td>
                        <td>{{ $line->asset?->assetGroupAccount?->codeNameLabel() }}</td>
                        <td class="fa-pdf-number">{{ $numbers->format($line->acquisition_cost) }}</td>
                        <td class="fa-pdf-number">{{ $numbers->format($line->asset?->salvage_value) }}</td>
                        <td>{{ $dates->formatDate($line->period_start, '') }} - {{ $dates->formatDate($line->period_end, '') }}</td>
                        <td class="fa-pdf-number">{{ $numbers->format($line->period_depreciation) }}</td>
                        <td class="fa-pdf-number">{{ $numbers->format($line->accumulated_before) }}</td>
                        <td class="fa-pdf-number">{{ $numbers->format($line->accumulated_after) }}</td>
                        <td class="fa-pdf-number">{{ $numbers->format($line->closing_net_book_value) }}</td>
                        <td>{{ $line->costCenter?->codeNameLabel() }}</td>
                        <td class="fa-pdf-code">{{ $run->journalEntry?->doc_num }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><th colspan="5">{{ __('common.total') }}</th><th class="fa-pdf-number">{{ $numbers->format($run->total_depreciation) }}</th><th colspan="5"></th></tr>
            </tfoot>
        </table>
    </div>

    <div class="fa-pdf-section">
        <table class="fa-pdf-meta-table"><tr><td><div class="fa-pdf-label">{{ __('fixed_assets.pdf.posted_by') }}</div><div class="fa-pdf-value">{{ $run->postedBy?->name ?: __('common.empty_value') }} @if($run->posted_at) / {{ $dates->formatDateTime($run->posted_at, '') }} @endif</div></td><td><div class="fa-pdf-label">{{ __('fixed_assets.pdf.total_assets') }}</div><div class="fa-pdf-value fa-pdf-number">{{ $run->lines->count() }}</div></td></tr></table>
    </div>

    @include('reports.fixed-assets.partials.authorization')
@endsection
