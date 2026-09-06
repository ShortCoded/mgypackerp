@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    <h2>{{ __('Physical Stock Count') }} — <span dir="ltr">{{ $record->doc_num }}</span></h2>
    <table class="report-table" style="margin-bottom:9px;"><tbody>
        <tr><th>{{ __('Date') }}</th><td>{{ $dates->formatDate($record->count_date, '') }}</td><th>{{ __('Status') }}</th><td>{{ __(str($record->status)->replace('_', ' ')->title()->toString()) }}</td></tr>
        <tr><th>{{ __('Store') }}</th><td>{{ $record->branchStore?->name }}</td><th>{{ __('Location') }}</th><td>{{ $record->warehouseLocation?->code ?: '—' }}</td></tr>
        <tr><th>{{ __('Adjustment document') }}</th><td colspan="3" dir="ltr">{{ $record->adjustmentDocument?->doc_num ?: '—' }}</td></tr>
    </tbody></table>
    <table class="report-table stock-count-lines"><thead><tr><th>#</th><th>{{ __('Product') }}</th><th>{{ __('Status') }}</th><th>{{ __('Batch') }}</th><th>{{ __('System') }}</th><th>{{ __('Physical') }}</th><th>{{ __('Variance') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>
        @foreach($record->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td><td>{{ __(str($line->stock_status)->replace('_', ' ')->title()->toString()) }}</td><td dir="ltr">{{ $line->batch_lot ?: '—' }}</td><td dir="ltr">{{ $numbers->format($line->system_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->physical_quantity) }}</td><td dir="ltr">{{ $numbers->format($line->variance_quantity) }}</td><td>{{ $line->variance_reason }}</td></tr>@endforeach
    </tbody></table>
    <table style="width:100%; margin-top:20px;"><tr><td style="border:0; text-align:center;">{{ __('Counted by') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Reviewed by') }}: __________________</td><td style="border:0; text-align:center;">{{ __('Approved by') }}: __________________</td></tr></table>
    <style>.stock-count-lines thead{display:table-header-group}.stock-count-lines tr{page-break-inside:avoid}.stock-count-lines th,.stock-count-lines td{font-size:6.9px}</style>
@endsection
