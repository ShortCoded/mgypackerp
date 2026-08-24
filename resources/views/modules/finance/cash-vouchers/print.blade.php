@extends('reports.layouts.pdf')

@section('report')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    @endphp

    @include('reports.partials.company-identity')

    <div class="document-title-row">
        <h1>{{ __($translationKey.'.singular') }}</h1>
        <strong dir="ltr">{{ $record->doc_num }}</strong>
        <span class="document-status">{{ __($translationKey.'.statuses.'.$record->status) }}</span>
    </div>

    <table class="document-meta-table">
        <tr>
            <td><strong>{{ __($translationKey.'.attributes.voucher_date') }}</strong><br><span dir="ltr">{{ $dates->formatDate($record->voucher_date, '—') }}</span></td>
            <td><strong>{{ __($translationKey.'.attributes.person_name') }}</strong><br>{{ $record->person_name ?: '—' }}</td>
            <td><strong>{{ __($translationKey.'.attributes.person_phone') }}</strong><br><span dir="ltr">{{ $record->person_phone ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><strong>{{ __($translationKey.'.attributes.cashbox') }}</strong><br>{{ trim(implode(' / ', array_filter([$record->cashbox?->doc_num, $record->cashbox?->name]))) ?: '—' }}</td>
            <td><strong>{{ __($translationKey.'.attributes.currency') }}</strong><br>{{ trim(implode(' / ', array_filter([$record->currency?->code, $record->currency?->name]))) ?: '—' }}</td>
            <td><strong>{{ __($translationKey.'.attributes.amount') }}</strong><br><span dir="ltr">{{ $numbers->format($record->amount) }} {{ $record->currency?->code }}</span></td>
        </tr>
        <tr><td colspan="3"><strong>{{ __($translationKey.'.attributes.reason') }}</strong><br>{{ $record->reason ?: '—' }}</td></tr>
        @if($record->description)<tr><td colspan="3"><strong>{{ __($translationKey.'.attributes.description') }}</strong><br>{{ $record->description }}</td></tr>@endif
    </table>

    <table class="report-table">
        <thead><tr><th>#</th><th>{{ __($translationKey.'.attributes.account') }}</th><th>{{ __($translationKey.'.attributes.line_description') }}</th><th class="text-end">{{ __($translationKey.'.attributes.line_amount') }}</th></tr></thead>
        <tbody>
            @foreach($record->lines as $line)
                <tr><td>{{ $line->line_number }}</td><td>{{ $line->account?->codeNameLabel() }}</td><td>{{ $line->description ?: '—' }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->amount) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><th colspan="3">{{ __($translationKey.'.attributes.total_distributed') }}</th><th class="text-end" dir="ltr">{{ $numbers->format($record->lines->sum('amount')) }} {{ $record->currency?->code }}</th></tr></tfoot>
    </table>

    @include('reports.partials.company-authorization')
@endsection
