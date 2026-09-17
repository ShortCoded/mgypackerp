@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $numericColumns = ['receipt', 'receipts', 'payment', 'payments', 'balance', 'amount', 'amount_base', 'source_amount', 'target_amount', 'exchange_rate', 'allocated', 'unallocated', 'original_amount', 'settled_amount', 'outstanding', 'days_overdue'];
    $exportQuery = request()->query() + ['type' => $report['type']];
@endphp

@section('title', __('finance_reports.title'))

@section('content')
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $report['title'] }}</h1>
            <p class="text-600 mb-0">{{ $report['description'] }}</p>
        </div>
        <div class="d-grid d-sm-flex gap-2">
            <a class="btn btn-falcon-success btn-sm" href="{{ route('admin.reports.finance.export.excel', $exportQuery) }}"><span class="fas fa-file-excel me-1"></span>{{ __('reports.export_excel') }}</a>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.reports.finance.export.csv', $exportQuery) }}"><span class="fas fa-file-csv me-1"></span>{{ __('reports.export_csv') }}</a>
            <a target="_blank" class="btn btn-falcon-default btn-sm" href="{{ route('admin.reports.finance.export.pdf', $exportQuery) }}"><span class="fas fa-file-pdf me-1"></span>{{ __('reports.export_pdf') }}</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0">{{ __('finance_reports.filters_title') }}</h2></div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.reports.finance.index') }}" class="row g-3">
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="finance-report-type">{{ __('finance_reports.filters.type') }}</label>
                    <x-forms.select class="form-select js-select2-local" id="finance-report-type" name="type">
                        @foreach(\Modules\Finance\Services\FinanceReportService::types() as $type)
                            <option value="{{ $type }}" @selected($report['type'] === $type)>{{ __('finance_reports.types.'.$type.'.title') }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="finance-report-from">{{ __('finance_reports.filters.from_date') }}</label>
                    <x-forms.input class="form-control" id="finance-report-from" name="from_date" type="date" value="{{ $filters['from_date'] ?? '' }}" />
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="finance-report-to">{{ __('finance_reports.filters.to_date') }}</label>
                    <x-forms.input class="form-control" id="finance-report-to" name="to_date" type="date" value="{{ $filters['to_date'] ?? '' }}" />
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="finance-report-as-of">{{ __('finance_reports.filters.as_of_date') }}</label>
                    <x-forms.input class="form-control" id="finance-report-as-of" name="as_of_date" type="date" value="{{ $filters['as_of_date'] ?? '' }}" />
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="finance-report-status">{{ __('finance_reports.filters.status') }}</label>
                    <x-forms.select class="form-select" id="finance-report-status" name="status">
                        <option value=""></option>
                        @foreach(['draft', 'approved', 'received', 'issued', 'deposited', 'delivered', 'collected', 'cleared', 'returned', 'cancelled', 'clearing_reversed'] as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ __('finance_reports.values.'.$status) }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="finance-report-cashbox">{{ __('finance_reports.filters.cashbox_doc_num') }}</label>
                    <x-forms.select class="form-select js-select2-local" id="finance-report-cashbox" name="cashbox_doc_num" data-allow-clear="true">
                        <option value=""></option>
                        @foreach($filterOptions['cashboxes'] as $cashbox)
                            <option value="{{ $cashbox->doc_num }}" @selected(($filters['cashbox_doc_num'] ?? null) === $cashbox->doc_num)>{{ $cashbox->doc_num }} / {{ $cashbox->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="finance-report-bank">{{ __('finance_reports.filters.bank_account_doc_num') }}</label>
                    <x-forms.select class="form-select js-select2-local" id="finance-report-bank" name="bank_account_doc_num" data-allow-clear="true">
                        <option value=""></option>
                        @foreach($filterOptions['bank_accounts'] as $bank)
                            <option value="{{ $bank->doc_num }}" @selected(($filters['bank_account_doc_num'] ?? null) === $bank->doc_num)>{{ implode(' / ', array_filter([$bank->doc_num, $bank->account_name, $bank->account_number])) }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="finance-report-currency">{{ __('finance_reports.filters.currency_doc_num') }}</label>
                    <x-forms.select class="form-select js-select2-local" id="finance-report-currency" name="currency_doc_num" data-allow-clear="true">
                        <option value=""></option>
                        @foreach($filterOptions['currencies'] as $currency)
                            <option value="{{ $currency->doc_num }}" @selected(($filters['currency_doc_num'] ?? null) === $currency->doc_num)>{{ $currency->code }} / {{ $currency->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="col-12 d-flex flex-column flex-sm-row gap-2">
                    <button class="btn btn-falcon-primary" type="submit"><span class="fas fa-filter me-1"></span>{{ __('common.actions.apply') }}</button>
                    <a class="btn btn-falcon-default" href="{{ route('admin.reports.finance.index') }}">{{ __('common.actions.reset') }}</a>
                </div>
            </form>
        </div>
    </div>

    @foreach($report['notices'] as $notice)
        <div class="alert alert-info py-2" role="alert"><span class="fas fa-info-circle me-1"></span>{{ $notice }}</div>
    @endforeach

    @if($report['filters'])
        <div class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
            <span class="fw-semibold">{{ __('finance_reports.active_filters') }}:</span>
            @foreach($report['filters'] as $label => $value)<span class="badge badge-subtle-primary rounded-pill">{{ $label }}: {{ $value }}</span>@endforeach
        </div></div>
    @endif

    @if($report['currency_totals'])
        <div class="row g-3 mb-3">
            @foreach($report['currency_totals'] as $currency => $totals)
                <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header py-2 fw-semibold">{{ $currency }}</div><div class="card-body py-2 d-flex flex-wrap gap-3">
                    @foreach($totals as $label => $value)<span><span class="text-600">{{ $label }}:</span> <strong dir="ltr">{{ $numbers->format($value) }}</strong></span>@endforeach
                </div></div></div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">{{ $report['title'] }}</h2>
            <span class="badge badge-subtle-secondary">{{ __('finance_reports.results_count', ['count' => $report['rows']->count()]) }}</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead><tr>@foreach($report['columns'] as $label)<th class="text-nowrap">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr>
                                @foreach($report['columns'] as $key => $label)
                                    @php($value = $row[$key] ?? '')
                                    <td class="{{ in_array($key, $numericColumns, true) ? 'text-end text-nowrap' : '' }}">
                                        @if($key === 'document' && filled($row['_url'] ?? null))
                                            <a href="{{ $row['_url'] }}">{{ $value }}</a>
                                        @elseif(in_array($key, $numericColumns, true) && is_numeric($value))
                                            <span dir="ltr">{{ $numbers->format($value) }}</span>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($report['columns']) }}" class="text-center text-600 py-4">{{ __('finance_reports.no_results') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
