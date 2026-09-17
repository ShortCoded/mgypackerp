@extends('layouts.app')

@php
    $title = __('trial_balance.title');
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $fromDate = request('from_date', $period?->from_date?->format('Y-m-d'));
    $toDate = request('to_date', $period?->to_date?->format('Y-m-d'));
    $hasFilters = request()->boolean('run') || $errors->any();
    $exportOptions = $result ? [
        ['label' => __('reports.export_excel'), 'url' => route('admin.accounting.reports.trial-balance.export.excel', request()->query()), 'icon' => 'file-excel', 'permission' => 'reports.trial_balance.export'],
        ['label' => __('reports.export_csv'), 'url' => route('admin.accounting.reports.trial-balance.export.csv', request()->query()), 'icon' => 'file-csv', 'permission' => 'reports.trial_balance.export'],
        ['label' => __('reports.export_pdf'), 'url' => route('admin.accounting.reports.trial-balance.export.pdf', request()->query()), 'icon' => 'file-pdf', 'permission' => 'reports.trial_balance.export', 'newTab' => true],
    ] : [];
@endphp

@section('title', $title)

@section('content')
    <x-admin.report.page :title="$title" :description="__('trial_balance.messages.posted_source_only')">
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="trial-balance-filters"
                :refresh-url="request()->fullUrl()"
                :export-options="$exportOptions" />
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="trial-balance-filters"
            :title="__('reports.filters')"
            :action="route('admin.accounting.reports.trial-balance')"
            :expanded="$hasFilters"
            :apply-label="__('trial_balance.actions.run')"
            :reset-url="route('admin.accounting.reports.trial-balance')">
            <x-forms.input name="run" type="hidden" value="1" />

            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="from_date" :label="__('trial_balance.filters.from_date')" :required="true" />
                <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="from_date" name="from_date" value="{{ $dates->formatDate($fromDate, $fromDate) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required />
                @error('from_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="to_date" :label="__('trial_balance.filters.to_date')" :required="true" />
                <x-forms.date-input class="form-control form-control-sm js-date-picker js-report-filter-control" id="to_date" name="to_date" value="{{ $dates->formatDate($toDate, $toDate) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required />
                @error('to_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="branch_doc_num" :label="__('trial_balance.filters.branch')" />
                <x-forms.select class="form-select form-select-sm js-report-filter-control" id="branch_doc_num" name="branch_doc_num">
                    <option value="">{{ __('trial_balance.filters.all') }}</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->doc_num }}" @selected(request('branch_doc_num') === $branch->doc_num)>{{ $branch->doc_num }} / {{ $branch->name }}</option>
                    @endforeach
                </x-forms.select>
                @error('branch_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="cost_center_doc_num" :label="__('trial_balance.filters.cost_center')" />
                <x-forms.select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="cost_center_doc_num" name="cost_center_doc_num" data-url="{{ route('admin.accounting.journal-entries.select2.cost-centers') }}" data-placeholder="{{ __('trial_balance.filters.all') }}" data-allow-clear="true">
                    @if(request('cost_center_doc_num'))<option value="{{ request('cost_center_doc_num') }}" selected>{{ request('cost_center_doc_num') }}</option>@endif
                </x-forms.select>
                @error('cost_center_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3 d-flex align-items-end">
                <div class="form-check mb-1">
                    <x-forms.input name="include_zero" type="hidden" value="0" />
                    <x-forms.input class="form-check-input" type="checkbox" id="include_zero" name="include_zero" value="1" :checked="request()->boolean('include_zero')" />
                    <x-forms.label class="form-check-label" for="include_zero" :label="__('trial_balance.filters.include_zero')" />
                </div>
            </div>
        </x-admin.report.filter-panel>

        @if($result)
            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h6 class="mb-1">{{ $title }}</h6>
                        <span class="text-700 fs-10" dir="ltr">{{ $dates->formatDate($fromDate, $fromDate) }} — {{ $dates->formatDate($toDate, $toDate) }}</span>
                    </div>
                    <div class="text-end">
                        <div class="mb-1">{{ data_get($operatingContext, 'company.label') }} / {{ data_get($operatingContext, 'financial_period.label') }}</div>
                        <span class="badge rounded-pill {{ $result['is_balanced'] ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                            {{ __('trial_balance.messages.'.($result['is_balanced'] ? 'balanced' : 'unbalanced')) }}
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-striped table-hover align-middle mb-0 erp-datatable-wide">
                            <thead class="bg-100">
                                <tr>
                                    <th rowspan="2" class="dt-code align-middle">{{ __('trial_balance.columns.account_code') }}</th>
                                    <th rowspan="2" class="dt-text align-middle">{{ __('trial_balance.columns.account_name') }}</th>
                                    <th colspan="2" class="text-center">{{ __('trial_balance.columns.opening') }}</th>
                                    <th colspan="2" class="text-center">{{ __('trial_balance.columns.period') }}</th>
                                    <th colspan="2" class="text-center">{{ __('trial_balance.columns.ending') }}</th>
                                </tr>
                                <tr>
                                    @foreach(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'ending_debit', 'ending_credit'] as $column)
                                        <th class="dt-number text-end">{{ __('trial_balance.columns.'.$column) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($result['rows'] as $row)
                                    <tr class="{{ $row['is_group'] ? 'fw-semibold bg-light' : '' }}">
                                        <td dir="ltr">{{ $row['account_code'] }}</td>
                                        <td>
                                            <span style="padding-inline-start: {{ max(0, $row['level'] - 1) * 1.25 }}rem">
                                                @if(! $row['is_group'])
                                                    @can('reports.account_ledger.view')
                                                        <a href="{{ route('admin.accounting.reports.account-ledger', array_filter(['run' => 1, 'all_periods' => 1, 'account_doc_num' => $row['doc_num'], 'from_date' => $fromDate, 'to_date' => $toDate, 'branch_doc_num' => request('branch_doc_num'), 'cost_center_doc_num' => request('cost_center_doc_num')])) }}">{{ $row['name'] }}</a>
                                                    @else
                                                        {{ $row['name'] }}
                                                    @endcan
                                                @else
                                                    {{ $row['name'] }}
                                                @endif
                                                @if($row['is_inactive'])<span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">{{ __('trial_balance.status.inactive') }}</span>@endif
                                            </span>
                                        </td>
                                        @foreach(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'ending_debit', 'ending_credit'] as $column)
                                            <td class="text-end" dir="ltr">{{ $numbers->format($row[$column]) }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-700 py-4" colspan="8">{{ __('trial_balance.messages.no_accounts') }}</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="2">{{ __('trial_balance.total') }}</td>
                                    @foreach(['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'ending_debit', 'ending_credit'] as $column)
                                        <td class="text-end" dir="ltr">{{ $numbers->format($result['totals'][$column]) }}</td>
                                    @endforeach
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="card-footer text-700 fs-11 d-flex flex-wrap justify-content-between gap-2">
                    <span>{{ __('trial_balance.audit.generated_by') }}: {{ $result['generated_by'] ?? '—' }}</span>
                    <span>{{ __('trial_balance.audit.generated_at') }}: {{ $dates->formatDateTime($result['generated_at'], '') }}</span>
                </div>
            </div>
        @endif
    </x-admin.report.page>
@endsection
