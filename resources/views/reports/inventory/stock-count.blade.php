@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    <h2>{{ __('inventory.stock_counts.title') }} — <span dir="ltr">{{ $record->doc_num }}</span></h2>
    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('inventory.stock_counts.attributes.count_date') }}</th><td>{{ $dates->formatDate($record->count_date, '') }}</td><th>{{ __('inventory.stock_counts.attributes.status') }}</th><td>{{ __('inventory.stock_counts.statuses.'.$record->status) }}</td></tr>
        <tr><th>{{ __('inventory.stock_counts.attributes.store') }}</th><td>{{ $record->branchStore?->name }}</td><th>{{ __('inventory.stock_counts.attributes.location') }}</th><td>{{ $record->warehouseLocation ? trim($record->warehouseLocation->code.' — '.$record->warehouseLocation->name) : '—' }}</td></tr>
        <tr><th>{{ __('Adjustment document') }}</th><td colspan="3" dir="ltr">{{ $record->adjustmentDocument?->doc_num ?: '—' }}</td></tr>
    </tbody></table>
    <table class="report-table" style="margin-bottom:9px"><tbody><tr>
        <th>{{ __('inventory.stock_counts.summary.total') }} {{ __('inventory.stock_counts.attributes.system_quantity') }}</th><td dir="ltr">{{ $numbers->format($totals['system']) }}</td>
        <th>{{ __('inventory.stock_counts.summary.total') }} {{ __('inventory.stock_counts.attributes.physical_quantity') }}</th><td dir="ltr">{{ $numbers->format($totals['physical']) }}</td>
        <th>{{ __('inventory.stock_counts.summary.shortage') }}</th><td dir="ltr">{{ $numbers->format($totals['shortage']) }}</td>
        <th>{{ __('inventory.stock_counts.summary.surplus') }}</th><td dir="ltr">{{ $numbers->format($totals['surplus']) }}</td>
    </tr></tbody></table>
    <table class="report-table stock-count-lines"><thead><tr><th>#</th><th>{{ __('inventory.stock_counts.attributes.product') }}</th><th>{{ __('inventory.stock_counts.attributes.stock_status') }}</th><th>{{ __('inventory.stock_counts.attributes.batch_lot') }}</th><th>{{ __('inventory.stock_counts.attributes.system_quantity') }}</th><th>{{ __('inventory.stock_counts.attributes.physical_quantity') }}</th><th>{{ __('inventory.stock_counts.attributes.variance_quantity') }}</th><th>{{ __('inventory.stock_counts.attributes.variance_type') }}</th><th>{{ __('inventory.stock_counts.attributes.variance_reason') }}</th></tr></thead><tbody>
        @foreach($record->lines as $line)
            @php($varianceType = bccomp((string) $line->variance_quantity, '0', 8) < 0 ? 'shortage' : (bccomp((string) $line->variance_quantity, '0', 8) > 0 ? 'surplus' : 'match'))
            <tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td>{{ __('inventory.movements.stock_statuses.'.$line->stock_status) }}</td><td dir="ltr">{{ $line->batch_lot ?: '—' }}</td><td dir="ltr">{{ $numbers->format($line->system_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->physical_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->variance_quantity) }}</td><td>{{ __('inventory.stock_counts.variance_types.'.$varianceType) }}</td><td>{{ $line->variance_reason }}</td></tr>
        @endforeach
    </tbody></table>
    <table style="width:100%; margin-top:20px;"><tr><td style="border:0; text-align:center;">{{ __('Counted by') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Reviewed by') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Approved by') }}: __________________</td></tr></table>
    <style>.stock-count-lines thead{display:table-header-group}.stock-count-lines tr{page-break-inside:avoid}.stock-count-lines th,.stock-count-lines td{font-size:6.9px}</style>
@endsection
