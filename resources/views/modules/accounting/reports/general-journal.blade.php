@extends('layouts.app')

@php
    $title = __('ledger_reports.types.general_journal');
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $fromDate = request('from_date', $period?->from_date?->format('Y-m-d'));
    $toDate = request('to_date', $period?->to_date?->format('Y-m-d'));
    $hasFilters = request()->boolean('run') || $errors->any();
    $exportOptions = $result ? [
        ['label' => __('reports.export_excel'), 'url' => route('admin.accounting.reports.general-journal.export.excel', request()->query()), 'icon' => 'file-excel', 'permission' => 'reports.account_ledger.export'],
        ['label' => __('reports.export_csv'), 'url' => route('admin.accounting.reports.general-journal.export.csv', request()->query()), 'icon' => 'file-csv', 'permission' => 'reports.account_ledger.export'],
        ['label' => __('reports.export_pdf'), 'url' => route('admin.accounting.reports.general-journal.export.pdf', request()->query()), 'icon' => 'file-pdf', 'permission' => 'reports.account_ledger.export', 'newTab' => true],
    ] : [];
    $sourceLabel = static function (mixed $sourceType): string {
        $source = filled($sourceType) ? (string) $sourceType : 'manual';
        $key = 'ledger_reports.sources.'.$source;

        return trans()->has($key) ? __($key) : __('ledger_reports.sources.other');
    };
@endphp

@section('title', $title)

@section('content')
    <x-admin.report.page :title="$title" :description="__('ledger_reports.messages.general_journal_posted_source_only')">
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="general-journal-filters"
                :refresh-url="request()->fullUrl()"
                :export-options="$exportOptions">
                <x-slot:extraActions>
                    @if($result)
                        <button class="btn btn-falcon-default btn-sm" type="button" onclick="window.print()">
                            <span class="fas fa-print me-1"></span>{{ __('common.actions.print') }}
                        </button>
                    @endif
                </x-slot:extraActions>
            </x-admin.report.actions-toolbar>
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="general-journal-filters"
            :title="__('reports.filters')"
            :action="route('admin.accounting.reports.general-journal')"
            :expanded="$hasFilters"
            :apply-label="__('ledger_reports.actions.run')"
            :reset-url="route('admin.accounting.reports.general-journal')">
            <x-forms.input name="run" type="hidden" value="1" />

            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="general_journal_account" :label="__('ledger_reports.filters.account_doc_num')" />
                <x-forms.select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="general_journal_account" name="account_doc_num" data-url="{{ route('admin.accounting.journal-entries.select2.accounts', ['report_scope' => 1, 'include_historical' => 1]) }}" data-placeholder="{{ __('ledger_reports.filters.all') }}" data-allow-clear="true" data-minimum-input-length="1">
                    @if($selectedAccount)<option value="{{ $selectedAccount['doc_num'] }}" selected>{{ $selectedAccount['name'] }}</option>@endif
                </x-forms.select>
                @error('account_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="from_date" :label="__('ledger_reports.filters.from_date')" :required="true" />
                <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="from_date" name="from_date" value="{{ $dates->formatDate($fromDate, $fromDate) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required />
                @error('from_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="to_date" :label="__('ledger_reports.filters.to_date')" :required="true" />
                <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="to_date" name="to_date" value="{{ $dates->formatDate($toDate, $toDate) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required />
                @error('to_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="branch_doc_num" :label="__('ledger_reports.filters.branch')" />
                <x-forms.select class="form-select form-select-sm js-report-filter-control" id="branch_doc_num" name="branch_doc_num">
                    <option value="">{{ __('ledger_reports.filters.all') }}</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->doc_num }}" @selected(request('branch_doc_num') === $branch->doc_num)>{{ $branch->doc_num }} / {{ $branch->name }}</option>
                    @endforeach
                </x-forms.select>
                @error('branch_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="cost_center_doc_num" :label="__('ledger_reports.filters.cost_center')" />
                <x-forms.select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="cost_center_doc_num" name="cost_center_doc_num" data-url="{{ route('admin.accounting.journal-entries.select2.cost-centers') }}" data-placeholder="{{ __('ledger_reports.filters.all') }}" data-allow-clear="true">
                    @if(request('cost_center_doc_num'))<option value="{{ request('cost_center_doc_num') }}" selected>{{ request('cost_center_doc_num') }}</option>@endif
                </x-forms.select>
                @error('cost_center_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        </x-admin.report.filter-panel>

        @if($result)
            <div class="row g-3 mb-3">
                @foreach(['debit', 'credit'] as $side)
                    <div class="col-sm-6 col-lg-3">
                        <div class="card h-100"><div class="card-body py-2">
                            <div class="text-600 fs-10">{{ __('ledger_reports.columns.'.$side) }}</div>
                            <strong dir="ltr">{{ $numbers->format($result['totals'][$side]) }}</strong>
                        </div></div>
                    </div>
                @endforeach
            </div>

            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between gap-2">
                    <div><h6 class="mb-1">{{ $title }}</h6><span class="text-700 fs-10">{{ data_get($result, 'currency.code') }}</span></div>
                    <div class="text-end fs-10 text-700">
                        <div dir="ltr">{{ $dates->formatDate($fromDate, $fromDate) }} — {{ $dates->formatDate($toDate, $toDate) }}</div>
                        <div>{{ data_get($operatingContext, 'company.label') }} / {{ data_get($operatingContext, 'financial_period.label') }}</div>
                    </div>
                </div>
                <div class="card-body p-0"><div class="erp-datatable-scroll">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead class="bg-100"><tr>
                            @foreach(['date', 'document', 'source_type', 'reference', 'account', 'description', 'cost_center', 'branch', 'debit', 'credit'] as $column)
                                <th class="{{ in_array($column, ['debit', 'credit'], true) ? 'text-end' : '' }}">{{ __('ledger_reports.columns.'.$column) }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                            @forelse($result['movements'] as $movement)
                                <tr>
                                    <td>{{ $dates->formatDate($movement['entry_date'], $movement['entry_date']) }}</td>
                                    <td dir="ltr">@can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $movement['doc_num']) }}">{{ $movement['doc_num'] }}</a>@else{{ $movement['doc_num'] }}@endcan</td>
                                    <td>{{ $sourceLabel($movement['source_type']) }}</td>
                                    <td dir="ltr">{{ $movement['reference_no'] ?: ($movement['source_doc_num'] ?: '—') }}</td>
                                    <td>@can('reports.account_ledger.view')<a href="{{ route('admin.accounting.reports.account-ledger', ['run' => 1, 'all_periods' => 1, 'account_doc_num' => $movement['account_doc_num'], 'from_date' => $fromDate, 'to_date' => $toDate, 'branch_doc_num' => request('branch_doc_num'), 'cost_center_doc_num' => request('cost_center_doc_num')]) }}">{{ $movement['account'] }}</a>@else{{ $movement['account'] }}@endcan</td>
                                    <td>{{ $movement['description'] }}</td>
                                    <td>{{ $movement['cost_center'] ?: '—' }}</td>
                                    <td>{{ $movement['branch'] ?: '—' }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($movement['debit']) }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($movement['credit']) }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-700 py-4" colspan="10">{{ __('ledger_reports.messages.no_movements') }}</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot><tr class="fw-semibold"><td colspan="8">{{ __('ledger_reports.summary.period') }}</td><td class="text-end" dir="ltr">{{ $numbers->format($result['totals']['debit']) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($result['totals']['credit']) }}</td></tr></tfoot>
                    </table>
                </div></div>
            </div>

            <div class="d-flex flex-wrap justify-content-between gap-2 mt-2 fs-10 text-700">
                <span>{{ __('ledger_reports.audit.generated_by') }}: {{ $result['generated_by'] }}</span>
                <span>{{ __('ledger_reports.audit.generated_at') }}: {{ $dates->formatDateTime($result['generated_at'], '') }}</span>
            </div>
        @endif
    </x-admin.report.page>
@endsection
