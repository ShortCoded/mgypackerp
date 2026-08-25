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

    @if($reportType === \Modules\Purchases\Services\Reports\ProcurementCycleReport::GoodsReceivedNotInvoiced)
        <h2>{{ __('Goods Received Not Invoiced') }}</h2>
        <table class="report-table"><thead><tr><th>{{ __('Date / GRN') }}</th><th>{{ __('Supplier / PO') }}</th><th>{{ __('Product / Store') }}</th><th class="text-end">{{ __('Received') }}</th><th class="text-end">{{ __('Invoiced') }}</th><th class="text-end">{{ __('Returned') }}</th><th class="text-end">{{ __('Remaining') }}</th><th class="text-end">{{ __('Unit Value') }}</th><th class="text-end">{{ __('GRNI Value') }}</th><th>{{ __('Currency') }}</th><th class="text-end">{{ __('Age') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@foreach($rows as $row)<tr><td>{{ $row['date'] }} / {{ $row['document'] }}</td><td>{{ $row['supplier'] }} / {{ $row['purchase_order'] }}</td><td>{{ $row['product'] }} / {{ $row['warehouse'] }}</td><td class="text-end">{{ $numbers->format($row['received_quantity']) }}</td><td class="text-end">{{ $numbers->format($row['invoiced_quantity']) }}</td><td class="text-end">{{ $numbers->format($row['returned_quantity']) }}</td><td class="text-end">{{ $numbers->format($row['remaining_quantity']) }}</td><td class="text-end">{{ $numbers->format($row['provisional_unit_value']) }}</td><td class="text-end">{{ $numbers->format($row['remaining_grni_value']) }}</td><td>{{ $row['currency'] }}</td><td class="text-end">{{ $row['age_days'] }}</td><td>{{ __($row['status'] === 'cleared' ? 'Cleared' : 'Open') }}</td></tr>@endforeach</tbody></table>
        @if($grniReconciliation)<h2>{{ __('GRNI Subledger to General Ledger Reconciliation') }}</h2><table class="report-table"><thead><tr><th>{{ __('Account') }}</th><th>{{ __('Subledger') }}</th><th>{{ __('General Ledger') }}</th><th>{{ __('Difference') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody><tr><td>{{ $grniReconciliation['account'] }}</td><td>{{ $numbers->format($grniReconciliation['subledger']) }}</td><td>{{ $numbers->format($grniReconciliation['gl']) }}</td><td>{{ $numbers->format($grniReconciliation['difference']) }}</td><td>{{ __($grniReconciliation['status']) }}</td></tr></tbody></table>@endif
    @endif

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
