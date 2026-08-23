@extends('reports.layouts.pdf')

@section('report')
    @if (! empty($filters))
        <div class="report-filter-summary">
            <strong>{{ __('cost_centers.filters.summary') }}</strong>
            <div>{{ implode(' | ', $filters) }}</div>
        </div>
    @endif

    <table class="report-table cost-centers-report-table">
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php
                    $indent = min(max(((int) $row['level']) - 1, 0), 6) * 10;
                    $indentProperty = app()->getLocale() === 'ar' ? 'padding-right' : 'padding-left';
                @endphp
                <tr>
                    <td class="cost-center-code" dir="ltr">{{ $row['cost_center_code'] }}</td>
                    <td class="cost-center-name" style="{{ $indentProperty }}: {{ $indent }}px;">
                        {{ $row['name'] }}
                    </td>
                    <td>{{ $row['is_group'] }}</td>
                    <td>{{ $row['parent_code'] }}</td>
                    <td dir="ltr">{{ $row['default_account_doc_num'] }}</td>
                    <td>{{ $row['default_account'] }}</td>
                    <td>{{ $row['status'] }}</td>
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
            font-size: 8.5px;
            line-height: 1.5;
            margin-bottom: 8px;
            padding: 6px 8px;
        }

        .cost-centers-report-table {
            table-layout: fixed;
        }

        .cost-centers-report-table th,
        .cost-centers-report-table td {
            font-size: 8.2px;
            line-height: 1.35;
            overflow-wrap: break-word;
        }

        .cost-centers-report-table .cost-center-code {
            width: 18%;
            white-space: nowrap;
            font-weight: 700;
        }

        .cost-centers-report-table .cost-center-name {
            width: 38%;
        }
    </style>
@endsection
