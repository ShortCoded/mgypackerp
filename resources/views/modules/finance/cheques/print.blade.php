@extends('reports.layouts.pdf')

@section('report')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    @endphp

    @include('reports.partials.company-identity')

    <div class="document-title-row">
        <h1>{{ $record->isIssued() ? __('Issued / Payment Cheque') : __('Received Cheque') }}</h1>
        <strong dir="ltr">{{ $record->doc_num }}</strong>
        <span class="document-status">{{ str($record->status)->replace('_', ' ')->title() }}</span>
    </div>

    <table class="document-meta-table">
        <tr>
            <td><strong>{{ __('Cheque number') }}</strong><br><span dir="ltr">{{ $record->cheque_number }}</span></td>
            <td><strong>{{ __('Cheque date') }}</strong><br><span dir="ltr">{{ $dates->formatDate($record->cheque_date, '—') }}</span></td>
            <td><strong>{{ __('Due date') }}</strong><br><span dir="ltr">{{ $dates->formatDate($record->due_date, '—') }}</span></td>
        </tr>
        <tr>
            <td colspan="2"><strong>{{ $record->isIssued() ? __('Payee / supplier') : __('Drawer / party') }}</strong><br>{{ $record->party_name ?: '—' }}</td>
            <td><strong>{{ __('Currency') }}</strong><br>{{ $record->currency?->code }} / {{ $record->currency?->name }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>{{ __('Issuing bank / branch / account') }}</strong><br>{{ $record->bankAccount?->bank?->name ?? $record->bankAccount?->bank?->name_en }} / {{ $record->bankAccount?->bank_branch_name ?: '—' }} / <span dir="ltr">{{ $record->bankAccount?->account_number }}</span></td>
            <td><strong>{{ __('Amount') }}</strong><br><span dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</span></td>
        </tr>
        <tr><td colspan="3"><strong>{{ __('Reason') }}</strong><br>{{ $record->reason ?: '—' }}</td></tr>
    </table>

    <table class="report-table">
        <thead><tr><th>#</th><th>{{ __('Account') }}</th><th>{{ __('Description') }}</th><th class="text-end">{{ __('Amount') }}</th></tr></thead>
        <tbody>
            @foreach($record->lines as $line)
                <tr><td>{{ $line->line_number }}</td><td>{{ $line->account?->codeNameLabel() }}</td><td>{{ $line->description ?: '—' }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->amount) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><th colspan="3">{{ __('Total') }}</th><th class="text-end" dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</th></tr></tfoot>
    </table>

    @if($record->clearingEvents->isNotEmpty())
        <h2>{{ __('Bank Clearing and Reversal Lineage') }}</h2>
        <table class="report-table"><thead><tr><th>#</th><th>{{ __('Clearing date') }}</th><th>{{ __('Status') }}</th><th>{{ __('Clearing Journal') }}</th><th>{{ __('Reversal Journal') }}</th><th>{{ __('Reason') }}</th></tr></thead><tbody>@foreach($record->clearingEvents as $event)<tr><td>{{ $event->sequence }}</td><td>{{ $dates->formatDate($event->clearing_date, '—') }}</td><td>{{ str($event->status)->replace('_', ' ')->title() }}</td><td>{{ $event->clearingJournalEntry?->doc_num }}</td><td>{{ $event->reversalJournalEntry?->doc_num }}</td><td>{{ $event->reversal_reason }}</td></tr>@endforeach</tbody></table>
    @endif

    @include('reports.partials.company-authorization')
@endsection
