@extends('layouts.app')

@php
    $title = __('ledger_reports.types.'.$type);
    $subjectField = match($type) {
        'customer_statement' => 'customer_doc_num',
        'supplier_statement' => 'supplier_doc_num',
        default => 'account_doc_num',
    };
    $subjectUrl = match($type) {
        'customer_statement' => route('admin.accounting.journal-entries.select2.customers'),
        'supplier_statement' => route('admin.accounting.journal-entries.select2.suppliers'),
        default => route('admin.accounting.journal-entries.select2.accounts'),
    };
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $exportPermission = 'reports.'.$type.'.export';
    $exportRoute = match($type) {
        'customer_statement' => 'admin.accounting.reports.customer-statement.export.',
        'supplier_statement' => 'admin.accounting.reports.supplier-statement.export.',
        default => 'admin.accounting.reports.account-ledger.export.',
    };
    $fromDate = request('from_date', $period?->from_date?->format('Y-m-d'));
    $toDate = request('to_date', $period?->to_date?->format('Y-m-d'));
@endphp

@section('title', $title)

@section('content')
    <div class="card mb-3">
        <div class="card-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-1">{{ $title }}</h5>
                    <p class="text-700 fs-10 mb-0">{{ __('ledger_reports.messages.posted_source_only') }}</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @if($result)
                        @can($exportPermission)
                            <a class="btn btn-falcon-default btn-sm" href="{{ route($exportRoute.'excel', request()->query()) }}"><span class="fas fa-file-excel me-1"></span>{{ __('reports.export_excel') }}</a>
                            <a class="btn btn-falcon-default btn-sm" href="{{ route($exportRoute.'csv', request()->query()) }}"><span class="fas fa-file-csv me-1"></span>{{ __('reports.export_csv') }}</a>
                            <a class="btn btn-falcon-default btn-sm" href="{{ route($exportRoute.'pdf', request()->query()) }}" target="_blank" rel="noopener"><span class="fas fa-file-pdf me-1"></span>{{ __('reports.export_pdf') }}</a>
                        @endcan
                    @endif
                    <button class="btn btn-falcon-default btn-sm" type="button" onclick="window.print()"><span class="fas fa-print me-1"></span>{{ __('erp_ui_shell.actions.print') }}</button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route(request()->route()->getName()) }}" data-ledger-report-form>
                <input name="run" type="hidden" value="1">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6 col-xl-3">
                        <label class="form-label" for="ledger_subject">{{ __('ledger_reports.filters.'.$subjectField) }}</label>
                        <select class="form-select js-ledger-select" id="ledger_subject" name="{{ $subjectField }}" data-url="{{ $subjectUrl }}" data-placeholder="{{ __('common.placeholders.select') }}" required>
                            @if($selected)<option value="{{ $selected['doc_num'] }}" selected>{{ $selected['doc_num'] }} / {{ $selected['name'] }}</option>@endif
                        </select>
                        @error($subjectField)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 col-xl-2">
                        <label class="form-label" for="from_date">{{ __('ledger_reports.filters.from_date') }}</label>
                        <input class="form-control" id="from_date" name="from_date" type="date" value="{{ $fromDate }}" required>
                        @error('from_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 col-xl-2">
                        <label class="form-label" for="to_date">{{ __('ledger_reports.filters.to_date') }}</label>
                        <input class="form-control" id="to_date" name="to_date" type="date" value="{{ $toDate }}" required>
                        @error('to_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <label class="form-label" for="branch_doc_num">{{ __('ledger_reports.filters.branch') }}</label>
                        <select class="form-select" id="branch_doc_num" name="branch_doc_num">
                            <option value="">{{ __('ledger_reports.filters.all') }}</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->doc_num }}" @selected(request('branch_doc_num') === $branch->doc_num)>{{ $branch->doc_num }} / {{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <label class="form-label" for="cost_center_doc_num">{{ __('ledger_reports.filters.cost_center') }}</label>
                        <select class="form-select js-ledger-select" id="cost_center_doc_num" name="cost_center_doc_num" data-url="{{ route('admin.accounting.journal-entries.select2.cost-centers') }}" data-placeholder="{{ __('ledger_reports.filters.all') }}" data-allow-clear="true">
                            @if(request('cost_center_doc_num'))<option value="{{ request('cost_center_doc_num') }}" selected>{{ request('cost_center_doc_num') }}</option>@endif
                        </select>
                        @error('cost_center_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-falcon-primary" type="submit"><span class="fas fa-filter me-1"></span>{{ __('ledger_reports.actions.run') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if($result)
        <div class="row g-3 mb-3">
            @foreach(['opening', 'period', 'ending'] as $summary)
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="text-700">{{ __('ledger_reports.summary.'.$summary) }}</h6>
                            <div class="d-flex justify-content-between gap-3"><span>{{ __('ledger_reports.columns.debit') }}</span><strong dir="ltr">{{ $numbers->format($result[$summary]['debit']) }}</strong></div>
                            <div class="d-flex justify-content-between gap-3"><span>{{ __('ledger_reports.columns.credit') }}</span><strong dir="ltr">{{ $numbers->format($result[$summary]['credit']) }}</strong></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="card-header">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <h6 class="mb-1">{{ $selected['doc_num'] }} / {{ $selected['name'] }}</h6>
                        <span class="text-700 fs-10">{{ $selected['account'] }} / {{ data_get($result, 'currency.code') }}</span>
                    </div>
                    <div class="text-end fs-10 text-700">
                        <div>{{ $fromDate }} — {{ $toDate }}</div>
                        <div>{{ data_get($operatingContext, 'company.label') }} / {{ data_get($operatingContext, 'financial_period.label') }}</div>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="erp-datatable-scroll">
                    <table class="table table-sm table-striped table-hover align-middle mb-0 erp-datatable-wide">
                        <thead class="bg-100">
                            <tr>
                                <th class="dt-date">{{ __('ledger_reports.columns.date') }}</th>
                                <th>{{ __('ledger_reports.columns.source_type') }}</th>
                                <th class="dt-code">{{ __('ledger_reports.columns.document') }}</th>
                                <th class="dt-code">{{ __('ledger_reports.columns.reference') }}</th>
                                <th class="dt-text">{{ __('ledger_reports.columns.description') }}</th>
                                <th class="dt-code">{{ __('ledger_reports.columns.cost_center') }}</th>
                                <th class="dt-code">{{ __('ledger_reports.columns.branch') }}</th>
                                <th class="dt-number text-end">{{ __('ledger_reports.columns.debit') }}</th>
                                <th class="dt-number text-end">{{ __('ledger_reports.columns.credit') }}</th>
                                <th class="dt-number text-end">{{ __('ledger_reports.columns.running_debit') }}</th>
                                <th class="dt-number text-end">{{ __('ledger_reports.columns.running_credit') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-info">
                                <td>{{ $dates->formatDate($fromDate, $fromDate) }}</td>
                                <td colspan="6">{{ __('ledger_reports.summary.opening') }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($result['opening']['debit']) }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($result['opening']['credit']) }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($result['opening']['debit']) }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($result['opening']['credit']) }}</td>
                            </tr>
                            @forelse($result['movements'] as $movement)
                                <tr>
                                    <td>{{ $dates->formatDate($movement['entry_date'], $movement['entry_date']) }}</td>
                                    <td>{{ __('ledger_reports.sources.'.($movement['source_type'] ?: 'manual')) }}</td>
                                    <td dir="ltr">
                                        @can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $movement['doc_num']) }}">{{ $movement['doc_num'] }}</a>@else{{ $movement['doc_num'] }}@endcan
                                    </td>
                                    <td dir="ltr">{{ $movement['reference_no'] ?: $movement['source_doc_num'] }}</td>
                                    <td>{{ $movement['description'] }}</td>
                                    <td>{{ $movement['cost_center'] }}</td>
                                    <td>{{ $movement['branch'] }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($movement['debit']) }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($movement['credit']) }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($movement['running_debit']) }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($movement['running_credit']) }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-700 py-4" colspan="11">{{ __('ledger_reports.messages.no_movements') }}</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-light fw-semibold">
                            <tr>
                                <td colspan="7">{{ __('ledger_reports.summary.period') }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($result['period']['debit']) }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($result['period']['credit']) }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($result['ending']['debit']) }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($result['ending']['credit']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer text-700 fs-11 d-flex flex-wrap justify-content-between gap-2">
                <span>{{ __('ledger_reports.audit.generated_by') }}: {{ $result['generated_by'] ?? '—' }}</span>
                <span>{{ __('ledger_reports.audit.generated_at') }}: {{ $dates->formatDateTime($result['generated_at'], '') }}</span>
            </div>
        </div>
    @elseif(request()->boolean('run'))
        <div class="alert alert-info">{{ __('ledger_reports.messages.no_movements') }}</div>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/modules/Accounting/ledger-reports.js') }}"></script>
@endpush
