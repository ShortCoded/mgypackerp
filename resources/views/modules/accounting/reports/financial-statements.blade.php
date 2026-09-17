@extends('layouts.app')

@php
    $title = __('financial_statements.title');
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $fromDate = request('from_date', $period?->from_date?->format('Y-m-d'));
    $toDate = request('to_date', $period?->to_date?->format('Y-m-d'));
    $statementType = request('statement_type', \Modules\Accounting\Services\FinancialStatementQueryService::IncomeStatement);
    $viewMode = request('view_mode', \Modules\Accounting\Services\FinancialStatementQueryService::ViewSummary);
    $hasComparison = filled(request('comparison_from_date')) && filled(request('comparison_to_date'));
    $hasFilters = request()->boolean('run') || $errors->any();
    $exportOptions = $result ? [
        ['label' => __('reports.export_excel'), 'url' => route('admin.accounting.reports.financial-statements.export.excel', request()->query()), 'icon' => 'file-excel', 'permission' => 'reports.financial_statements.export'],
        ['label' => __('reports.export_csv'), 'url' => route('admin.accounting.reports.financial-statements.export.csv', request()->query()), 'icon' => 'file-csv', 'permission' => 'reports.financial_statements.export'],
        ['label' => __('reports.export_pdf'), 'url' => route('admin.accounting.reports.financial-statements.export.pdf', request()->query()), 'icon' => 'file-pdf', 'permission' => 'reports.financial_statements.export', 'newTab' => true],
    ] : [];
@endphp

@section('title', $title)

@section('content')
    <x-admin.report.page :title="$title" :description="__('financial_statements.messages.posted_source_only')">
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="financial-statement-filters"
                :refresh-url="request()->fullUrl()"
                :export-options="$exportOptions" />
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="financial-statement-filters"
            :title="__('reports.filters')"
            :action="route('admin.accounting.reports.financial-statements')"
            :expanded="$hasFilters"
            :apply-label="__('financial_statements.actions.run')"
            :reset-url="route('admin.accounting.reports.financial-statements')">
            <x-forms.input name="run" type="hidden" value="1" />

            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="statement_type" :label="__('financial_statements.filters.statement_type')" :required="true" />
                <x-forms.select class="form-select form-select-sm js-report-filter-control" id="statement_type" name="statement_type" required>
                    @foreach(\Modules\Accounting\Services\FinancialStatementQueryService::types() as $type)
                        <option value="{{ $type }}" @selected($statementType === $type)>{{ __('financial_statements.types.'.$type) }}</option>
                    @endforeach
                </x-forms.select>
                @error('statement_type')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="view_mode" :label="__('financial_statements.filters.view_mode')" :required="true" />
                <x-forms.select class="form-select form-select-sm js-report-filter-control" id="view_mode" name="view_mode" required>
                    @foreach(['summary', 'detailed'] as $mode)
                        <option value="{{ $mode }}" @selected($viewMode === $mode)>{{ __('financial_statements.view_modes.'.$mode) }}</option>
                    @endforeach
                </x-forms.select>
                @error('view_mode')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="from_date" :label="__('financial_statements.filters.from_date')" :required="true" />
                <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="from_date" name="from_date" value="{{ $dates->formatDate($fromDate, $fromDate) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required />
                @error('from_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="to_date" :label="__('financial_statements.filters.to_date')" :required="true" />
                <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="to_date" name="to_date" value="{{ $dates->formatDate($toDate, $toDate) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required />
                @error('to_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="comparison_from_date" :label="__('financial_statements.filters.comparison_from_date')" />
                <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="comparison_from_date" name="comparison_from_date" value="{{ $dates->formatDate(request('comparison_from_date'), request('comparison_from_date', '')) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" />
                @error('comparison_from_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="comparison_to_date" :label="__('financial_statements.filters.comparison_to_date')" />
                <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="comparison_to_date" name="comparison_to_date" value="{{ $dates->formatDate(request('comparison_to_date'), request('comparison_to_date', '')) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" />
                @error('comparison_to_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="branch_doc_num" :label="__('financial_statements.filters.branch')" />
                <x-forms.select class="form-select form-select-sm js-report-filter-control" id="branch_doc_num" name="branch_doc_num">
                    <option value="">{{ __('financial_statements.filters.all') }}</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->doc_num }}" @selected(request('branch_doc_num') === $branch->doc_num)>{{ $branch->doc_num }} / {{ $branch->name }}</option>
                    @endforeach
                </x-forms.select>
                @error('branch_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="cost_center_doc_num" :label="__('financial_statements.filters.cost_center')" />
                <x-forms.select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="cost_center_doc_num" name="cost_center_doc_num" data-url="{{ route('admin.accounting.journal-entries.select2.cost-centers') }}" data-placeholder="{{ __('financial_statements.filters.all') }}" data-allow-clear="true">
                    @if(request('cost_center_doc_num'))<option value="{{ request('cost_center_doc_num') }}" selected>{{ request('cost_center_doc_num') }}</option>@endif
                </x-forms.select>
                @error('cost_center_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        </x-admin.report.filter-panel>

        @if($result)
            @if(! $result['classification_complete'])
                <div class="alert alert-warning">
                    <strong>{{ __('financial_statements.messages.classification_incomplete') }}</strong>
                    <div class="small mt-1">{{ implode('، ', $result['classification_warnings']) }}</div>
                </div>
            @endif

            @if($result['scope_is_partial'])
                <div class="alert alert-warning">{{ __('financial_statements.messages.partial_scope') }}</div>
            @endif

            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h6 class="mb-1">{{ __('financial_statements.types.'.$result['statement_type']) }}</h6>
                        <span class="text-700 fs-10" dir="ltr">{{ $dates->formatDate($fromDate, $fromDate) }} — {{ $dates->formatDate($toDate, $toDate) }}</span>
                    </div>
                    <div class="text-end">
                        <div>{{ data_get($operatingContext, 'company.label') }} / {{ data_get($operatingContext, 'financial_period.label') }}</div>
                        @if($result['statement_type'] === 'financial_position')
                            <span class="badge rounded-pill {{ data_get($result, 'summary.is_balanced') ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                                {{ __('financial_statements.messages.'.(data_get($result, 'summary.is_balanced') ? 'balanced' : 'unbalanced')) }}
                            </span>
                        @elseif(in_array($result['statement_type'], ['cash_flow_direct', 'cash_flow_indirect'], true))
                            <span class="badge rounded-pill {{ data_get($result, 'summary.is_reconciled') ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                                {{ __('financial_statements.messages.'.(data_get($result, 'summary.is_reconciled') ? 'cash_reconciled' : 'cash_unreconciled')) }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-striped table-hover align-middle mb-0 erp-datatable-wide">
                            <thead class="bg-100">
                                <tr>
                                    <th class="dt-text">{{ __('financial_statements.columns.line') }}</th>
                                    @if($result['statement_type'] === 'equity_changes')
                                        @foreach(['opening', 'increases', 'decreases', 'period_result', 'current'] as $column)
                                            <th class="dt-number text-end">{{ __('financial_statements.columns.'.$column) }}</th>
                                        @endforeach
                                        @if($hasComparison)
                                            <th class="dt-number text-end">{{ __('financial_statements.columns.comparison') }}</th>
                                            <th class="dt-number text-end">{{ __('financial_statements.columns.variance') }}</th>
                                        @endif
                                    @else
                                        <th class="dt-number text-end">{{ __('financial_statements.columns.current') }}</th>
                                        @if($hasComparison)
                                            <th class="dt-number text-end">{{ __('financial_statements.columns.comparison') }}</th>
                                            <th class="dt-number text-end">{{ __('financial_statements.columns.variance') }}</th>
                                        @endif
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($result['rows'] as $row)
                                    <tr class="{{ in_array($row['row_type'], ['total', 'grand_total'], true) ? 'fw-bold bg-light' : ($row['row_type'] === 'subtotal' ? 'fw-semibold' : '') }}">
                                        <td class="{{ $row['row_type'] === 'account' ? 'ps-4' : '' }}">
                                            @php($label = $row['label'] ?? __('financial_statements.lines.'.$row['label_key']))
                                            @if(isset($row['account_doc_num']) && auth()->user()?->can('reports.account_ledger.view'))
                                                <a href="{{ route('admin.accounting.reports.account-ledger', ['run' => 1, 'all_periods' => 1, 'account_doc_num' => $row['account_doc_num'], 'from_date' => $fromDate, 'to_date' => $toDate]) }}">{{ $label }}</a>
                                            @else
                                                {{ $label }}
                                            @endif
                                        </td>
                                        @if($result['statement_type'] === 'equity_changes')
                                            @foreach(['opening', 'increases', 'decreases', 'period_result', 'amount'] as $column)
                                                <td class="text-end" dir="ltr">{{ $numbers->format($row[$column]) }}</td>
                                            @endforeach
                                            @if($hasComparison)
                                                <td class="text-end" dir="ltr">{{ isset($row['comparison_amount']) ? $numbers->format($row['comparison_amount']) : '—' }}</td>
                                                <td class="text-end" dir="ltr">{{ isset($row['comparison_amount']) ? $numbers->format(bcsub((string) $row['amount'], (string) $row['comparison_amount'], 4)) : '—' }}</td>
                                            @endif
                                        @else
                                            <td class="text-end" dir="ltr">{{ $numbers->format($row['amount']) }}</td>
                                            @if($hasComparison)
                                                <td class="text-end" dir="ltr">{{ isset($row['comparison_amount']) ? $numbers->format($row['comparison_amount']) : '—' }}</td>
                                                <td class="text-end" dir="ltr">{{ isset($row['comparison_amount']) ? $numbers->format(bcsub((string) $row['amount'], (string) $row['comparison_amount'], 4)) : '—' }}</td>
                                            @endif
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer text-700 fs-11 d-flex flex-wrap justify-content-between gap-2">
                    <span>{{ __('financial_statements.audit.generated_by') }}: {{ $result['generated_by'] ?? '—' }}</span>
                    <span>{{ __('financial_statements.audit.generated_at') }}: {{ $dates->formatDateTime($result['generated_at'], '') }}</span>
                </div>
            </div>

            @if(in_array($result['statement_type'], ['cash_flow_direct', 'cash_flow_indirect'], true))
                <div class="card mb-3">
                    <div class="card-header fw-semibold">{{ __('financial_statements.messages.cash_components') }}</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead><tr>
                                    <th>{{ __('financial_statements.columns.account') }}</th>
                                    <th class="text-end">{{ __('financial_statements.columns.opening') }}</th>
                                    <th class="text-end">{{ __('financial_statements.columns.ending') }}</th>
                                    <th class="text-end">{{ __('financial_statements.columns.change') }}</th>
                                </tr></thead>
                                <tbody>
                                    @forelse($result['cash_components'] as $component)
                                        <tr>
                                            <td>
                                                @can('reports.account_ledger.view')
                                                    <a href="{{ route('admin.accounting.reports.account-ledger', ['run' => 1, 'all_periods' => 1, 'account_doc_num' => $component['account_doc_num'], 'from_date' => $fromDate, 'to_date' => $toDate]) }}">{{ $component['label'] }}</a>
                                                @else
                                                    {{ $component['label'] }}
                                                @endcan
                                            </td>
                                            @foreach(['opening', 'ending', 'change'] as $column)
                                                <td class="text-end" dir="ltr">{{ $numbers->format($component[$column]) }}</td>
                                            @endforeach
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted">{{ __('reports.no_data') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </x-admin.report.page>
@endsection
