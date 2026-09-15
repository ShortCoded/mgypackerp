@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @php($dates = app(\Modules\Core\Services\DateFormatService::class))
    @include('reports.partials.company-identity')
    <table class="report-table" style="margin-bottom:9px"><tbody>
        <tr><th>{{ __('production_execution.fields.document') }}</th><td dir="ltr">{{ $record->doc_num }}</td><th>{{ __('production_execution.fields.date') }}</th><td>{{ $dates->formatDate($record->request_date, '—') }}</td></tr>
        <tr><th>{{ __('production_execution.fields.production_order') }}</th><td dir="ltr">{{ $record->run?->order?->doc_num }}</td><th>{{ __('production_execution.fields.run') }}</th><td dir="ltr">{{ $record->run?->run_number }}</td></tr>
        <tr><th>{{ __('production_execution.fields.stage') }}</th><td>{{ $record->run?->stageSnapshot?->stage_name ?: '—' }}</td><th>{{ __('production_execution.fields.status') }}</th><td>{{ __('production_execution.statuses.'.$record->status) }}</td></tr>
        <tr><th>{{ __('production_execution.fields.amount') }}</th><td dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</td><th>{{ __('production_execution.fields.payment_channel') }}</th><td>{{ __('production_execution.payment_channels.'.$record->payment_channel) }}</td></tr>
        <tr><th>{{ __('production_execution.fields.cashbox') }}</th><td>{{ $record->cashbox?->name ?: '—' }}</td><th>{{ __('production_execution.fields.bank_account') }}</th><td>{{ $record->bankAccount?->account_name ?: '—' }}</td></tr>
        <tr><th>{{ __('production_execution.fields.expense_account') }}</th><td>{{ $record->expenseAccount?->name ?: '—' }}</td><th>{{ __('production_execution.fields.payment_voucher') }}</th><td dir="ltr">{{ $record->cashVoucher?->doc_num ?: '—' }}</td></tr>
    </tbody></table>
    <p><strong>{{ __('production_execution.fields.reason') }}:</strong> {{ $record->reason }}</p>
    @if($record->notes)<p><strong>{{ __('production_execution.fields.notes') }}:</strong> {{ $record->notes }}</p>@endif
    @include('reports.production.partials.signatures', ['areas' => ['production_supervisor', 'finance']])
@endsection
