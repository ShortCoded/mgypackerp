@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

    <div class="report-filter-summary">
        <strong>{{ $product ? $product->doc_num.' / '.$product->name : ($filterSummary[__('stock_balance_inquiry.filters.branch')] ?? __('stock_balance_inquiry.options.all')) }}</strong>
        <div>{{ $store?->name ?? __('stock_balance_inquiry.options.all') }} — {{ $comparison['as_of'] }}</div>
        <div>{{ __('inventory_accounting.valuation_report.reference_method') }}: {{ __('inventory_accounting.valuation_methods.'.$comparison['reference_method']) }}</div>
    </div>

    @if(! ($comparison['valuation_complete'] ?? true))
        <div class="report-warning">{{ app()->getLocale() === 'ar' ? 'مقارنة جزئية: بنود غير مسعّرة أو حركات غير قابلة للتقييم' : 'Partial comparison: unvalued or invalid stock positions' }} — {{ $comparison['excluded_position_count'] }} / {{ $numbers->format($comparison['excluded_quantity']) }}</div>
    @endif

    <table dir="{{ $direction ?? 'ltr' }}" class="report-table valuation-table">
        <thead><tr>
            <th>{{ __('inventory_accounting.valuation_report.method') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.issue_cost') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.ending_value') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.ending_unit_cost') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.difference_vs_reference') }}</th>
        </tr></thead>
        <tbody>
            @foreach($comparison['methods'] as $method => $result)
                <tr>
                    <td>{{ __('inventory_accounting.valuation_methods.'.$method) }}</td>
                    <td>{{ $result['issue_cost'] === null ? '—' : $numbers->format($result['issue_cost']) }}</td>
                    <td>{{ $numbers->format($result['ending_value']) }}</td>
                    <td>{{ $numbers->format($result['ending_unit_cost']) }}</td>
                    <td>{{ $result['difference_vs_reference'] === null ? '—' : $numbers->format($result['difference_vs_reference']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(($comparison['excluded_positions'] ?? []) !== [])
        <h4>{{ __('inventory_accounting.valuation_report.excluded_title') }}</h4>
        <table dir="{{ $direction ?? 'ltr' }}" class="report-table valuation-table">
            <thead><tr>
                <th>{{ __('inventory_accounting.book_valuation.columns.branch') }}</th>
                <th>{{ __('inventory_accounting.book_valuation.columns.store') }}</th>
                <th>{{ __('inventory_accounting.book_valuation.columns.item') }}</th>
                <th>{{ __('inventory_accounting.book_valuation.columns.quantity') }}</th>
                <th>{{ __('inventory_accounting.valuation_report.excluded_reason') }}</th>
                <th>{{ __('inventory_accounting.valuation_report.excluded_documents') }}</th>
            </tr></thead>
            <tbody>
                @foreach($comparison['excluded_positions'] as $position)
                    <tr>
                        <td>{{ $position['branch_name'] ?? $position['branch_id'] }}</td>
                        <td>{{ $position['store_name'] ?? $position['branch_store_id'] }}</td>
                        <td>{{ $position['product_doc_num'] ?? $position['product_id'] }} — {{ $position['product_name'] }}</td>
                        <td>{{ $numbers->format($position['quantity']) }}</td>
                        <td>{{ __($position['reason']) }}</td>
                        <td>{{ implode('، ', $position['source_doc_nums']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($comparison['sources'] !== [])
    <h4>{{ __('inventory_accounting.valuation_report.sources') }}</h4>
    <table dir="{{ $direction ?? 'ltr' }}" class="report-table valuation-table">
        <thead><tr>
            <th>{{ __('inventory_accounting.valuation_report.date') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.document') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.type') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.stock_status') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.quantity_in') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.quantity_out') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.unit_cost') }}</th>
            <th>{{ __('inventory_accounting.valuation_report.total_cost') }}</th>
        </tr></thead>
        <tbody>
            @foreach($comparison['sources'] as $source)
                <tr>
                    <td>{{ $source['date'] }}</td>
                    <td>{{ $source['document'] }}</td>
                    <td>{{ __('inventory.movements.types.'.$source['type']) }}</td>
                    <td>{{ __('inventory.movements.stock_statuses.'.$source['stock_status']) }}</td>
                    <td>{{ $numbers->format($source['quantity_in']) }}</td>
                    <td>{{ $numbers->format($source['quantity_out']) }}</td>
                    <td>{{ $numbers->format($source['unit_cost']) }}</td>
                    <td>{{ $numbers->format($source['total_cost']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <style>
        .report-filter-summary { background: #f8fafc; border: 1px solid #d8e2ef; margin-bottom: 8px; padding: 6px 8px; }
        .valuation-table { table-layout: fixed; margin-bottom: 12px; }
        .valuation-table th, .valuation-table td { font-size: 7px; line-height: 1.2; }
        .valuation-table th:nth-child(n+2), .valuation-table td:nth-child(n+2) { text-align: right; }
    </style>
@endsection
