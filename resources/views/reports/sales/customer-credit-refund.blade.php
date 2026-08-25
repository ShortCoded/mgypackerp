@extends('reports.layouts.pdf')

@section('report')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    @include('reports.partials.company-identity')

    <div class="document-title-row">
        <h1>{{ __('Customer Credit Refund') }}</h1>
        <strong dir="ltr">{{ $record->doc_num }}</strong>
    </div>

    <table class="report-table">
        <tbody>
            <tr><th>{{ __('Customer') }}</th><td>{{ $record->creditNote->customer?->doc_num }} — {{ $record->creditNote->customer?->name }}</td><th>{{ __('Refund date') }}</th><td dir="ltr">{{ $record->refund_date?->toDateString() }}</td></tr>
            <tr><th>{{ __('Credit source') }}</th><td dir="ltr">{{ $record->creditNote?->doc_num }}</td><th>{{ __('Method') }}</th><td>{{ __($record->payment_method === 'cash' ? 'Cash' : 'Bank') }}</td></tr>
            <tr><th>{{ __('Cashbox / Bank') }}</th><td>{{ $record->cashbox?->doc_num ?? $record->bankAccount?->doc_num }} — {{ $record->cashbox?->name ?? $record->bankAccount?->account_name }}</td><th>{{ __('Reference') }}</th><td dir="ltr">{{ $record->reference_no ?: '—' }}</td></tr>
            <tr><th>{{ __('Refunded amount') }}</th><td class="number">{{ $numbers->format($record->amount) }}</td><th>{{ __('Journal Entry') }}</th><td dir="ltr">{{ $record->journalEntry?->doc_num }}</td></tr>
            <tr><th>{{ __('Status') }}</th><td>{{ __($record->status) }}</td><th>{{ __('Authorization') }}</th><td>{{ $record->posted_at?->toDateTimeString() }}</td></tr>
        </tbody>
    </table>

    @if($record->notes)<p><strong>{{ __('Notes') }}:</strong> {{ $record->notes }}</p>@endif
    @include('reports.partials.company-authorization')
@endsection
