@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp
@section('title', __('cashbox_count.title'))

@section('content')
<div data-cashbox-count>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h1 class="h4 mb-1">{{ __('cashbox_count.title') }}</h1><p class="text-600 mb-0">{{ __('cashbox_count.description') }}</p></div>
        <button class="btn btn-falcon-default btn-sm" type="button" onclick="window.print()"><span class="fas fa-print me-1"></span>{{ __('cashbox_count.actions.print_worksheet') }}</button>
    </div>
    <div class="alert alert-info py-2">{{ __('cashbox_count.worksheet_notice') }}</div>

    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0">{{ __('cashbox_count.filters_title') }}</h2></div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.finance.cashbox-count.index') }}" class="row g-3">
                <div class="col-12 col-md-4"><label class="form-label" for="cashbox-count-as-of">{{ __('cashbox_count.filters.as_of_date') }}</label><x-forms.date-input class="form-control" id="cashbox-count-as-of" name="as_of_date" value="{{ $filters['as_of_date'] ?? '' }}" /></div>
                <div class="col-12 col-md-4"><label class="form-label" for="cashbox-count-cashbox">{{ __('cashbox_count.filters.cashbox') }}</label><x-forms.select id="cashbox-count-cashbox" name="cashbox_doc_num" variant="local"><option value=""></option>@foreach($filterOptions['cashboxes'] as $cashbox)<option value="{{ $cashbox->doc_num }}" @selected(($filters['cashbox_doc_num'] ?? null) === $cashbox->doc_num)>{{ $cashbox->doc_num }} / {{ $cashbox->name }}</option>@endforeach</x-forms.select></div>
                <div class="col-12 col-md-4"><label class="form-label" for="cashbox-count-currency">{{ __('cashbox_count.filters.currency') }}</label><x-forms.select id="cashbox-count-currency" name="currency_doc_num" variant="local"><option value=""></option>@foreach($filterOptions['currencies'] as $currency)<option value="{{ $currency->doc_num }}" @selected(($filters['currency_doc_num'] ?? null) === $currency->doc_num)>{{ $currency->code }} / {{ $currency->name }}</option>@endforeach</x-forms.select></div>
                <div class="col-12 d-flex gap-2"><button class="btn btn-falcon-primary" type="submit">{{ __('common.actions.apply') }}</button><a class="btn btn-falcon-default" href="{{ route('admin.finance.cashbox-count.index') }}">{{ __('common.actions.reset') }}</a></div>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between"><h2 class="h6 mb-0">{{ __('cashbox_count.worksheet_title') }}</h2><span class="badge badge-subtle-secondary">{{ __('cashbox_count.results_count', ['count' => $report['rows']->count()]) }}</span></div>
        <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
            <thead><tr><th>{{ __('cashbox_count.columns.cashbox') }}</th><th>{{ __('cashbox_count.columns.branch') }}</th><th>{{ __('cashbox_count.columns.currency') }}</th><th class="text-end">{{ __('cashbox_count.columns.book_balance') }}</th><th>{{ __('cashbox_count.columns.counted_balance') }}</th><th class="text-end">{{ __('cashbox_count.columns.variance') }}</th><th>{{ __('cashbox_count.columns.notes') }}</th><th></th></tr></thead>
            <tbody>@forelse($report['rows'] as $index => $row)
                @php($cashbox = $filterOptions['cashboxes']->firstWhere('id', $row['_cashbox_id'] ?? null))
                @php($currency = $filterOptions['currencies']->firstWhere('id', $row['_currency_id'] ?? null))
                <tr data-cashbox-count-row data-book-balance="{{ $row['balance'] }}">
                    <td>{{ $row['cashbox'] }}</td><td>{{ $row['branch'] }}</td><td>{{ $row['currency'] }}</td><td class="text-end" dir="ltr">{{ $numbers->format($row['balance']) }}</td>
                    <td><form id="cashbox-count-form-{{ $index }}" method="POST" action="{{ route('admin.finance.cashbox-count.store') }}">@csrf<x-forms.input type="hidden" name="count_date" value="{{ $filters['as_of_date'] }}" /><x-forms.input type="hidden" name="cashbox_doc_num" value="{{ $cashbox?->doc_num }}" /><x-forms.input type="hidden" name="currency_doc_num" value="{{ $currency?->doc_num }}" /><x-forms.numeric-input class="form-control-sm text-end js-cashbox-counted" name="actual_amount" :scale="4" min="0" step="0.0001" required /></form></td>
                    <td class="text-end js-cashbox-variance" dir="ltr">—</td><td><x-forms.input class="form-control form-control-sm" name="notes" form="cashbox-count-form-{{ $index }}" maxlength="2000" /></td>
                    <td>@can('finance.cashbox_count.create')<button class="btn btn-falcon-primary btn-sm" form="cashbox-count-form-{{ $index }}" type="submit">{{ __('cashbox_count.actions.save') }}</button>@endcan</td>
                </tr>
            @empty<tr><td colspan="8" class="text-center text-600 py-4">{{ __('cashbox_count.no_results') }}</td></tr>@endforelse</tbody>
        </table></div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="h6 mb-0">{{ __('cashbox_count.history_title') }}</h2></div>
        <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>{{ __('cashbox_count.columns.document') }}</th><th>{{ __('cashbox_count.columns.count_date') }}</th><th>{{ __('cashbox_count.columns.cashbox') }}</th><th>{{ __('cashbox_count.columns.currency') }}</th><th class="text-end">{{ __('cashbox_count.columns.variance') }}</th><th>{{ __('cashbox_count.columns.status') }}</th></tr></thead><tbody>@forelse($counts as $count)<tr><td><a href="{{ route('admin.finance.cashbox-count.show', $count) }}">{{ $count->doc_num }}</a></td><td>{{ $dates->formatDate($count->count_date, '') }}</td><td>{{ $count->cashbox?->name }}</td><td>{{ $count->currency?->code }}</td><td class="text-end" dir="ltr">{{ $numbers->format($count->variance) }}</td><td>{{ __('cashbox_count.statuses.'.$count->status) }}</td></tr>@empty<tr><td colspan="6" class="text-center text-600 py-4">{{ __('cashbox_count.no_history') }}</td></tr>@endforelse</tbody></table></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-cashbox-count-row]').forEach((row) => {
    const input = row.querySelector('.js-cashbox-counted');
    const output = row.querySelector('.js-cashbox-variance');
    input?.addEventListener('input', () => {
        const difference = window.AppNumbers?.subtract(input.value, row.dataset.bookBalance || '0', 4);
        output.textContent = input.value === '' || difference === null ? '—' : window.AppNumbers.format(difference);
    });
});
</script>
@endpush
