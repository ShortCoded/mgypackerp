@if (! empty($filters))
    <div class="report-filter-summary">
        <strong>{{ __('product_data_report.filters.summary') }}</strong>
        <div>{{ implode(' | ', $filters) }}</div>
    </div>
@endif

@php
    $numericColumnIndexes = ($mode ?? 'summary') === 'detailed' ? [6] : [12, 15];
@endphp

<table class="report-table products-data-report-table {{ ($mode ?? 'summary') === 'detailed' ? 'products-data-report-table-detailed' : 'products-data-report-table-summary' }}">
    <thead>
        <tr>
            @foreach ($headings as $heading)
                <th>{{ $heading }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                @foreach ($row as $columnIndex => $cell)
                    <td @class(['report-number' => in_array($columnIndex, $numericColumnIndexes, true)]) @if (in_array($columnIndex, $numericColumnIndexes, true)) dir="ltr" @endif>{{ $cell }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($headings) }}">{{ __('reports.no_data') }}</td>
            </tr>
        @endforelse
    </tbody>
</table>

<style>
    .report-filter-summary {
        background: #f8fafc;
        border: 1px solid #d8e2ef;
        border-radius: 4px;
        color: #344050;
        font-size: 8.2px;
        line-height: 1.45;
        margin-bottom: 8px;
        padding: 6px 8px;
    }

    .products-data-report-table {
        table-layout: fixed;
    }

    .products-data-report-table th,
    .products-data-report-table td {
        font-size: 7.4px;
        line-height: 1.35;
        overflow-wrap: break-word;
        vertical-align: top;
    }

    .products-data-report-table-summary th,
    .products-data-report-table-summary td {
        font-size: 6.6px;
    }

    .products-data-report-table-detailed th,
    .products-data-report-table-detailed td {
        font-size: 8px;
    }

    .products-data-report-table .report-number {
        direction: ltr;
        font-variant-numeric: tabular-nums;
        unicode-bidi: isolate;
    }

</style>
