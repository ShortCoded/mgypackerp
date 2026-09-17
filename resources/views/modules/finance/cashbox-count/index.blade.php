@extends('layouts.app')

@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

@section('title', __('cashbox_count.title'))

@section('content')
    <div data-cashbox-count>
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div>
                <h1 class="h4 mb-1">{{ __('cashbox_count.title') }}</h1>
                <p class="text-600 mb-0">{{ __('cashbox_count.description') }}</p>
            </div>
            <button class="btn btn-falcon-default btn-sm" type="button" onclick="window.print()">
                <span class="fas fa-print me-1"></span>{{ __('cashbox_count.actions.print') }}
            </button>
        </div>

        <div class="alert alert-info py-2" role="alert">
            <span class="fas fa-info-circle me-1"></span>{{ __('cashbox_count.worksheet_notice') }}
        </div>

        <div class="card mb-3">
            <div class="card-header"><h2 class="h6 mb-0">{{ __('cashbox_count.filters_title') }}</h2></div>
            <div class="card-body">
                <form method="GET" action="{{ url()->current() }}" class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="cashbox-count-as-of">{{ __('cashbox_count.filters.as_of_date') }}</label>
                        <x-forms.input class="form-control" id="cashbox-count-as-of" name="as_of_date" type="date" value="{{ $filters['as_of_date'] ?? '' }}" />
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="cashbox-count-cashbox">{{ __('cashbox_count.filters.cashbox') }}</label>
                        <x-forms.select class="form-select js-select2-local" id="cashbox-count-cashbox" name="cashbox_doc_num" data-allow-clear="true">
                            <option value=""></option>
                            @foreach($filterOptions['cashboxes'] as $cashbox)
                                <option value="{{ $cashbox->doc_num }}" @selected(($filters['cashbox_doc_num'] ?? null) === $cashbox->doc_num)>{{ $cashbox->doc_num }} / {{ $cashbox->name }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="cashbox-count-currency">{{ __('cashbox_count.filters.currency') }}</label>
                        <x-forms.select class="form-select js-select2-local" id="cashbox-count-currency" name="currency_doc_num" data-allow-clear="true">
                            <option value=""></option>
                            @foreach($filterOptions['currencies'] as $currency)
                                <option value="{{ $currency->doc_num }}" @selected(($filters['currency_doc_num'] ?? null) === $currency->doc_num)>{{ $currency->code }} / {{ $currency->name }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-falcon-primary" type="submit"><span class="fas fa-filter me-1"></span>{{ __('common.actions.apply') }}</button>
                        <a class="btn btn-falcon-default" href="{{ url()->current() }}">{{ __('common.actions.reset') }}</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">{{ __('cashbox_count.worksheet_title') }}</h2>
                <span class="badge badge-subtle-secondary">{{ __('cashbox_count.results_count', ['count' => $report['rows']->count()]) }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('cashbox_count.columns.cashbox') }}</th>
                                <th>{{ __('cashbox_count.columns.branch') }}</th>
                                <th>{{ __('cashbox_count.columns.currency') }}</th>
                                <th class="text-end">{{ __('cashbox_count.columns.book_balance') }}</th>
                                <th class="text-end">{{ __('cashbox_count.columns.counted_balance') }}</th>
                                <th class="text-end">{{ __('cashbox_count.columns.variance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($report['rows'] as $index => $row)
                                <tr data-cashbox-count-row data-book-balance="{{ $row['balance'] }}">
                                    <td>{{ $row['cashbox'] }}</td>
                                    <td>{{ $row['branch'] }}</td>
                                    <td>{{ $row['currency'] }}</td>
                                    <td class="text-end text-nowrap" dir="ltr">{{ $numbers->format($row['balance']) }}</td>
                                    <td class="text-end">
                                        <x-forms.input class="form-control form-control-sm text-end js-cashbox-counted" id="cashbox-counted-{{ $index }}" type="number" min="0" step="0.0001" inputmode="decimal" aria-label="{{ __('cashbox_count.columns.counted_balance') }}" />
                                    </td>
                                    <td class="text-end text-nowrap js-cashbox-variance" dir="ltr">—</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-600 py-4">{{ __('cashbox_count.no_results') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    const cashboxCountScaledAmount = (value) => {
        const normalized = String(value ?? '').trim();
        const match = normalized.match(/^(-?)(\d+)(?:\.(\d{0,4}))?$/);

        if (!match) {
            return null;
        }

        const units = BigInt(match[2]) * 10000n + BigInt((match[3] || '').padEnd(4, '0'));

        return match[1] === '-' ? -units : units;
    };
    const cashboxCountFormatAmount = (units) => {
        const sign = units < 0n ? '-' : '';
        const absolute = units < 0n ? -units : units;

        return `${sign}${absolute / 10000n}.${String(absolute % 10000n).padStart(4, '0')}`;
    };

    document.querySelectorAll('[data-cashbox-count-row]').forEach((row) => {
        const counted = row.querySelector('.js-cashbox-counted');
        const variance = row.querySelector('.js-cashbox-variance');
        counted?.addEventListener('input', () => {
            if (counted.value === '') {
                variance.textContent = '—';
                return;
            }

            const countedAmount = cashboxCountScaledAmount(counted.value);
            const bookAmount = cashboxCountScaledAmount(row.dataset.bookBalance || '0');
            variance.textContent = countedAmount === null || bookAmount === null
                ? '—'
                : cashboxCountFormatAmount(countedAmount - bookAmount);
        });
    });
</script>
@endpush
