@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

    <div class="report-filter-summary">
        <strong>{{ $product->doc_num }} / {{ $product->name }}</strong>
        <div>{{ $store->name }} — {{ $comparison['as_of'] }}</div>
        <div>{{ __('inventory_accounting.valuation_report.reference_method') }}: {{ __('inventory_accounting.valuation_methods.'.$comparison['reference_method']) }}</div>
    </div>

    <table class="report-table valuation-table">
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

    <h4>{{ __('inventory_accounting.valuation_report.sources') }}</h4>
    <table class="report-table valuation-table">
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

    <style>
        .report-filter-summary { background: #f8fafc; border: 1px solid #d8e2ef; margin-bottom: 8px; padding: 6px 8px; }
        .valuation-table { table-layout: fixed; margin-bottom: 12px; }
        .valuation-table th, .valuation-table td { font-size: 7px; line-height: 1.2; }
        .valuation-table th:nth-child(n+2), .valuation-table td:nth-child(n+2) { text-align: right; }
    </style>
@endsection
