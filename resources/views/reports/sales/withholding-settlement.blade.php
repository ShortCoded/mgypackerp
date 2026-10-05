@extends('reports.layouts.pdf')
@section('report')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<p>{{ __('Customer') }}: {{ $invoice->customer?->name }} · {{ __('Invoice') }}: {{ $invoice->doc_num }}</p>
<p>{{ __('sales_ui.wht.reference') }}: {{ $settlement->certificate_reference }} · {{ __('sales_ui.wht.'.$settlement->status) }}</p>
<table class="report-table"><thead><tr><th>{{ __('sales_ui.wht.posting_date') }}</th><th>{{ __('sales_ui.wht.actual') }}</th><th>{{ __('sales_ui.wht.expected') }}</th><th>{{ __('Journal Entry') }}</th></tr></thead><tbody><tr><td>{{ $dates->formatDate($settlement->posting_date) }}</td><td>{{ $numbers->format($settlement->amount) }} {{ $invoice->currency?->code }}</td><td>{{ $numbers->format($settlement->expected_amount) }}</td><td>{{ $settlement->journal_entry_id ?: '—' }}</td></tr></tbody></table>
<p>{{ $settlement->reason }} · {{ $settlement->preparer?->name }} · {{ $settlement->approver?->name }} · {{ $settlement->approval_reason }}</p>
@if($settlement->reversal_journal_entry_id)<p>{{ __('sales_ui.wht.recovery_reference') }}: {{ $settlement->recovery_reference }} · {{ __('Journal Entry') }} #{{ $settlement->reversal_journal_entry_id }} · {{ $dates->formatDate($settlement->reversal_date) }} · {{ $settlement->reversal_reason }}</p>@endif
<p>{{ __('sales_ui.wht.help') }}</p>
@endsection
