@extends('reports.layouts.pdf')

@section('report')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $header = $report['header'];
    @endphp
    @include('reports.partials.company-identity', ['showRegistrationNumbers' => false])
    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('price_lists.fields.code') }}</th><td dir="ltr">{{ $header['code'] }}</td><th>{{ __('price_lists.fields.date') }}</th><td>{{ $dates->formatDate($header['date'], '') }}</td></tr>
        <tr><th>{{ __('price_lists.fields.customer') }}</th><td><span dir="ltr">{{ $header['customer_code'] }}</span>{{ $header['customer_code'] ? ' / ' : '' }}{{ $header['customer_name'] }}</td><th>{{ __('price_lists.fields.currency') }}</th><td><span dir="ltr">{{ $header['currency_code'] }}</span> / {{ $header['currency_name'] }}</td></tr>
        <tr><th>{{ __('price_lists.fields.valid_from') }}</th><td>{{ $dates->formatDate($header['valid_from'], '') }}</td><th>{{ __('price_lists.fields.valid_until') }}</th><td>{{ $header['valid_until'] ? $dates->formatDate($header['valid_until'], '') : __('price_lists.open_ended') }}</td></tr>
        <tr><th>{{ __('price_lists.fields.pricing_use') }}</th><td>{{ $header['pricing_use'] }}</td><th>{{ __('price_lists.export.state') }}</th><td>{{ $header['deleted_state'] }}</td></tr>
        <tr><th>{{ __('price_lists.fields.notes') }}</th><td colspan="3">{{ $header['notes'] ?: __('common.empty_value') }}</td></tr>
        <tr><th>{{ __('price_lists.fields.prepared_by') }}</th><td>{{ $header['prepared_by'] }}</td><th>{{ __('price_lists.fields.reviewed_by') }}</th><td>{{ $header['reviewed_by'] }}</td></tr>
        <tr><th>{{ __('price_lists.fields.approved_by') }}</th><td>{{ $header['approved_by'] }}</td><th>{{ __('price_lists.fields.lifecycle_status') }}</th><td>{{ $header['approved_at'] ? __('price_lists.approved') : ($header['reviewed_at'] ? __('price_lists.reviewed') : __('price_lists.pending_identity')) }}</td></tr>
    </tbody></table>
    <div class="document-item-details" style="margin-bottom:7px;">{{ __('price_lists.base_unit_help') }}</div>
    <table class="report-table price-list-lines" autosize="1">
        <thead><tr><th>#</th><th>{{ __('price_lists.export.product_code') }}</th><th>{{ __('price_lists.export.product_name') }}</th><th>{{ __('price_lists.export.unit_price') }}</th><th>{{ __('price_lists.export.discount_type') }}</th><th>{{ __('price_lists.export.discount_limit') }}</th></tr></thead>
        <tbody>
        @forelse($report['lines'] as $line)
            <tr><td>{{ $line['line_number'] }}</td><td dir="ltr">{{ $line['product_code'] }}</td><td>{{ $line['product_name'] }}</td><td class="number">{{ $numbers->format($line['unit_price']) }}</td><td>{{ $line['discount_type'] }}</td><td class="number">{{ $numbers->format($line['discount_value']) }}</td></tr>
        @empty
            <tr><td colspan="6">{{ __('common.empty_value') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <style>.price-list-lines { table-layout:fixed; }.price-list-lines th,.price-list-lines td { overflow-wrap:break-word; padding:7px 4px; }.price-list-lines thead { display:table-header-group; }.price-list-lines tr { page-break-inside:avoid; }</style>
@endsection
