@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $numericColumns = ['receipt', 'receipts', 'payment', 'payments', 'balance', 'amount', 'amount_base', 'source_amount', 'target_amount', 'exchange_rate', 'allocated', 'unallocated', 'original_amount', 'settled_amount', 'outstanding', 'days_overdue'];
    $exportQuery = $filters;
    $indexRoute = request()->route()?->getName() ?? 'admin.reports.finance.index';
    $isNamedReport = $indexRoute !== 'admin.reports.finance.index';
    $applicableFilters = \Modules\Finance\Services\FinanceReportService::applicableFilters($report['type']);
    $filterPanelExpanded = request()->query() !== [] || $errors->any();
    $exportOptions = [
        ['label' => __('reports.export_excel'), 'url' => route('admin.reports.finance.export.excel', $exportQuery), 'icon' => 'file-excel'],
        ['label' => __('reports.export_csv'), 'url' => route('admin.reports.finance.export.csv', $exportQuery), 'icon' => 'file-csv'],
        ['label' => __('reports.export_pdf'), 'url' => route('admin.reports.finance.export.pdf', $exportQuery), 'icon' => 'file-pdf', 'newTab' => true],
    ];
@endphp

@section('title', __('finance_reports.title'))

@section('content')
    <x-admin.report.page :title="$report['title']" :description="$report['description']">
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="finance-report-filters"
                :refresh-url="request()->fullUrl()"
                :export-options="$exportOptions" />
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="finance-report-filters"
            :title="__('finance_reports.filters_title')"
            :action="route($indexRoute)"
            :expanded="$filterPanelExpanded"
            :apply-label="__('common.actions.apply')"
            :reset-label="__('common.actions.reset')"
            :reset-url="route($indexRoute)">
                @foreach(['due_state', 'branch_id', 'financial_period_id'] as $hiddenFilter)
                    @if(in_array($hiddenFilter, $applicableFilters, true) && filled($filters[$hiddenFilter] ?? null))
                        <x-forms.input type="hidden" name="{{ $hiddenFilter }}" value="{{ $filters[$hiddenFilter] }}" />
                    @endif
                @endforeach
                @if($isNamedReport)
                    <x-forms.input name="type" type="hidden" value="{{ $report['type'] }}" />
                @else
                    <div class="col-12 col-md-6 col-xl-4">
                        <x-forms.label for="finance-report-type" :label="__('finance_reports.filters.type')" />
                        <x-forms.select class="form-select form-select-sm js-select2-local js-report-filter-control" id="finance-report-type" name="type">
                            @foreach(\Modules\Finance\Services\FinanceReportService::types() as $type)
                                <option value="{{ $type }}" @selected($report['type'] === $type)>{{ __('finance_reports.types.'.$type.'.title') }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                @endif
                @if(in_array('from_date', $applicableFilters, true))
                <div class="col-12 col-md-6 col-xl-2">
                    <x-forms.label for="finance-report-from" :label="__('finance_reports.filters.from_date')" />
                    <x-forms.date-input class="form-control form-control-sm js-report-filter-control" id="finance-report-from" name="from_date" value="{{ $filters['from_date'] ?? '' }}" />
                </div>
                @endif
                @if(in_array('to_date', $applicableFilters, true))
                <div class="col-12 col-md-6 col-xl-2">
                    <x-forms.label for="finance-report-to" :label="__('finance_reports.filters.to_date')" />
                    <x-forms.date-input class="form-control form-control-sm js-report-filter-control" id="finance-report-to" name="to_date" value="{{ $filters['to_date'] ?? '' }}" />
                </div>
                @endif
                @if(in_array('as_of_date', $applicableFilters, true))
                <div class="col-12 col-md-6 col-xl-2">
                    <x-forms.label for="finance-report-as-of" :label="__('finance_reports.filters.as_of_date')" />
                    <x-forms.date-input class="form-control form-control-sm js-report-filter-control" id="finance-report-as-of" name="as_of_date" value="{{ $filters['as_of_date'] ?? '' }}" />
                </div>
                @endif
                @if(in_array('status', $applicableFilters, true))
                <div class="col-12 col-md-6 col-xl-2">
                    <x-forms.label for="finance-report-status" :label="__('finance_reports.filters.status')" />
                    <x-forms.select class="form-select form-select-sm js-report-filter-control" id="finance-report-status" name="status">
                        <option value=""></option>
                        @foreach(['draft', 'approved', 'received', 'issued', 'deposited', 'delivered', 'collected', 'cleared', 'returned', 'cancelled', 'clearing_reversed'] as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ __('finance_reports.values.'.$status) }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                @endif
                @if(in_array('cashbox_doc_num', $applicableFilters, true))
                <div class="col-12 col-md-6 col-xl-4">
                    <x-forms.label for="finance-report-cashbox" :label="__('finance_reports.filters.cashbox_doc_num')" />
                    <x-forms.select class="form-select form-select-sm js-select2-local js-report-filter-control" id="finance-report-cashbox" name="cashbox_doc_num" data-allow-clear="true">
                        <option value=""></option>
                        @foreach($filterOptions['cashboxes'] as $cashbox)
                            <option value="{{ $cashbox->doc_num }}" @selected(($filters['cashbox_doc_num'] ?? null) === $cashbox->doc_num)>{{ $cashbox->doc_num }} / {{ $cashbox->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                @endif
                @if(in_array('bank_account_doc_num', $applicableFilters, true))
                <div class="col-12 col-md-6 col-xl-4">
                    <x-forms.label for="finance-report-bank" :label="__('finance_reports.filters.bank_account_doc_num')" />
                    <x-forms.select class="form-select form-select-sm js-select2-local js-report-filter-control" id="finance-report-bank" name="bank_account_doc_num" data-allow-clear="true">
                        <option value=""></option>
                        @foreach($filterOptions['bank_accounts'] as $bank)
                            <option value="{{ $bank->doc_num }}" @selected(($filters['bank_account_doc_num'] ?? null) === $bank->doc_num)>{{ implode(' / ', array_filter([$bank->doc_num, $bank->account_name, $bank->account_number])) }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                @endif
                @if(in_array('currency_doc_num', $applicableFilters, true))
                <div class="col-12 col-md-6 col-xl-4">
                    <x-forms.label for="finance-report-currency" :label="__('finance_reports.filters.currency_doc_num')" />
                    <x-forms.select class="form-select form-select-sm js-select2-local js-report-filter-control" id="finance-report-currency" name="currency_doc_num" data-allow-clear="true">
                        <option value=""></option>
                        @foreach($filterOptions['currencies'] as $currency)
                            <option value="{{ $currency->doc_num }}" @selected(($filters['currency_doc_num'] ?? null) === $currency->doc_num)>{{ $currency->code }} / {{ $currency->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                @endif
        </x-admin.report.filter-panel>

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
        @php
            $dashboardCountKey = match ($report['type']) {
                \Modules\Finance\Services\FinanceReportService::CustomerAging => ($filters['due_state'] ?? null) === 'due_or_overdue' ? 'due_receivables' : null,
                \Modules\Finance\Services\FinanceReportService::SupplierAging => ($filters['due_state'] ?? null) === 'due_or_overdue' ? 'due_payables' : null,
                \Modules\Finance\Services\FinanceReportService::DueCheques => 'due_cheques',
                \Modules\Finance\Services\FinanceReportService::ReturnedCheques => 'returned_cheques',
                \Modules\Finance\Services\FinanceReportService::UnapprovedDocuments => 'pending_finance_approvals',
                default => null,
            };
        @endphp
        @if($dashboardCountKey)<span class="d-none" data-report-count="{{ $dashboardCountKey }}">{{ $report['rows']->count() }}</span>@endif
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
    </x-admin.report.page>
@endsection
