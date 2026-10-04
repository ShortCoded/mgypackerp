@if (! empty($filters))
    <div class="report-filter-summary">
        <strong>{{ __('product_data_report.filters.summary') }}</strong>
        <div>{{ implode(' | ', $filters) }}</div>
    </div>
@endif

@php
    $detailed = ($mode ?? 'summary') === 'detailed';
    $mainColumns = $detailed ? [0, 1, 3, 4, 8, 9] : [0, 1, 2, 4, 14];
    $detailColumns = array_values(array_diff(array_keys($headings), $mainColumns));
    $numericColumnIndexes = $detailed ? [7, 8] : [12, 15];
@endphp

@if (count($rows) > 0)
    <table autosize="1" dir="{{ $direction ?? 'ltr' }}" class="report-table products-data-report-table">
        <thead><tr>
            @foreach ($mainColumns as $columnIndex)<th>{{ $headings[$columnIndex] }}</th>@endforeach
        </tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($mainColumns as $columnIndex)
                        <td @if ($loop->first) rowspan="2" @endif @if (in_array($columnIndex, $numericColumnIndexes, true)) dir="ltr" @endif>{{ $row[$columnIndex] ?? '' }}</td>
                    @endforeach
                </tr>
                <tr><td colspan="{{ count($mainColumns) - 1 }}" class="products-data-details">
                    @foreach ($detailColumns as $columnIndex)
                        <span class="products-data-attribute"><strong>{{ $headings[$columnIndex] }}:</strong>
                            <span @if (in_array($columnIndex, $numericColumnIndexes, true)) dir="ltr" @endif>{{ filled($row[$columnIndex] ?? null) ? $row[$columnIndex] : '—' }}</span>
                        </span>@unless ($loop->last) · @endunless
                    @endforeach
                </td></tr>
            @endforeach
        </tbody>
    </table>
@else
    <div class="report-empty-state">{{ __('reports.no_data') }}</div>
@endif

<style>
    .report-filter-summary {
        border: 1px solid #d8e2ef;
        color: #344050;
        font-size: 12px;
        line-height: 1.5;
        margin-bottom: 8px;
        padding: 6px 8px;
    }
    .products-data-report-table th,
    .products-data-report-table td {
        font-size: 12px;
        line-height: 1.5;
        overflow-wrap: break-word;
        white-space: normal;
        vertical-align: top;
    }
    .products-data-report-table .products-data-details {
        font-size: 12px;
        background: #ffffff;
        padding: 6px 8px 9px;
        border-bottom: 1px solid #aab8c7;
    }
    .products-data-attribute { line-height: 1.6; }
</style>
