@extends('reports.layouts.pdf')

@section('report')
    @if (! empty($filters))
        <div class="report-filter-summary">
            <strong>{{ __('accounts.filters.summary') }}</strong>
            <div>{{ implode(' | ', $filters) }}</div>
        </div>
    @endif

    <table class="report-table accounts-report-table">
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
                <tr class="{{ $row['is_group'] ? 'account-group-row' : '' }}">
                    <td class="account-code" dir="ltr">{{ $row['account_code'] }}</td>
                    <td class="account-name" style="{{ $indentProperty }}: {{ $indent }}px;">
                        {{ $row['name'] }}
                    </td>
                    <td>{{ $row['parent_code'] }}</td>
                    <td>{{ $row['classification'] }}</td>
                    <td>{{ $row['statement_type'] }}</td>
                    <td>{{ $row['normal_balance'] }}</td>
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

        .accounts-report-table {
            table-layout: fixed;
        }

        .accounts-report-table th,
        .accounts-report-table td {
            font-size: 8.2px;
            line-height: 1.35;
            overflow-wrap: break-word;
        }

        .accounts-report-table .account-code {
            width: 12%;
            white-space: nowrap;
            font-weight: 700;
        }

        .accounts-report-table .account-name {
            width: 30%;
        }

        .accounts-report-table .account-group-row .account-code,
        .accounts-report-table .account-group-row .account-name {
            font-weight: 700;
        }
    </style>
@endsection
