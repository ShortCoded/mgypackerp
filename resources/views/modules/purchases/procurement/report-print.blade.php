@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

    @include('reports.partials.company-identity')

    <div class="document-title-row">
        <h1>{{ __('Procurement Report') }}</h1>
        <strong>{{ __('procurement.reports.types.'.$reportType) }}</strong>
    </div>

    <div class="report-filter-summary">
        {{ collect([
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $filters['status'] ?? null,
            $filters['supplier_id'] ?? null,
            $filters['product_id'] ?? null,
        ])->filter(fn ($value) => filled($value))->isEmpty()
            ? __('No filters applied.')
            : __('Filtered report') }}
    </div>

    <table class="report-table procurement-report-print-table">
        <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Document') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Supplier') }}</th>
                <th>{{ __('Item') }}</th>
                <th>{{ __('Purchase requisition') }}</th>
                <th>{{ __('Purchase order') }}</th>
                <th>{{ __('Branch / Warehouse') }}</th>
                <th>{{ __('QC') }}</th>
                <th>{{ __('Production / Work order') }}</th>
                <th class="text-end">{{ __('Quantity') }}</th>
                @if($showPrices)
                    <th class="text-end">{{ __('Amount') }}</th>
                @endif
                <th class="text-end">{{ __('Outstanding') }}</th>
                <th>{{ __('Overdue') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td dir="ltr">{{ $row['date'] ?: '—' }}</td>
                    <td dir="ltr">{{ $row['document'] ?: '—' }}</td>
                    <td>{{ $row['status'] ? __('procurement.statuses.'.$row['status']) : '—' }}</td>
                    <td>{{ $row['supplier'] ?: '—' }}</td>
                    <td>{{ $row['product'] ?: '—' }}</td>
                    <td dir="ltr">{{ $row['requisition'] ?: '—' }}</td>
                    <td dir="ltr">{{ $row['purchase_order'] ?: '—' }}</td>
                    <td>{{ collect([$row['branch'], $row['warehouse']])->filter()->join(' / ') ?: '—' }}</td>
                    <td>{{ $row['qc_status'] ? __('procurement.statuses.'.$row['qc_status']) : '—' }}</td>
                    <td dir="ltr">{{ collect([$row['production_order'], $row['work_order']])->filter()->join(' / ') ?: '—' }}</td>
                    <td class="text-end" dir="ltr">{{ $numbers->format($row['quantity']) }}</td>
                    @if($showPrices)
                        <td class="text-end" dir="ltr">{{ $numbers->format($row['amount']) }}</td>
                    @endif
                    <td class="text-end" dir="ltr">{{ $numbers->format($row['outstanding']) }}</td>
                    <td>{{ $row['overdue'] ? __('Yes') : __('No') }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $showPrices ? 14 : 13 }}" style="text-align: center;">{{ __('No matching records.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @include('reports.partials.company-authorization')

    <style>
        .procurement-report-print-table { table-layout: fixed; }
        .procurement-report-print-table th, .procurement-report-print-table td { font-size: 6.8px; line-height: 1.25; overflow-wrap: break-word; }
    </style>
@endsection
