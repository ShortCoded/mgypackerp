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
    $isPartnerStatement = in_array($type, ['customer_statement', 'supplier_statement'], true);
    $exportPermission = 'reports.'.$type.'.export';
    $exportRoute = match($type) {
        'customer_statement' => 'admin.accounting.reports.customer-statement.export.',
        'supplier_statement' => 'admin.accounting.reports.supplier-statement.export.',
        default => 'admin.accounting.reports.account-ledger.export.',
    };
    $fromDate = request('from_date', $period?->from_date?->format('Y-m-d'));
    $toDate = request('to_date', $period?->to_date?->format('Y-m-d'));
    $hasFilters = request()->boolean('run') || $errors->any();
    $exportOptions = $result ? [
        ['label' => __('reports.export_excel'), 'url' => route($exportRoute.'excel', request()->query()), 'icon' => 'file-excel', 'permission' => $exportPermission],
        ['label' => __('reports.export_csv'), 'url' => route($exportRoute.'csv', request()->query()), 'icon' => 'file-csv', 'permission' => $exportPermission],
        ['label' => __('reports.export_pdf'), 'url' => route($exportRoute.'pdf', request()->query()), 'icon' => 'file-pdf', 'permission' => $exportPermission, 'newTab' => true],
    ] : [];
@endphp

@section('title', $title)

@section('content')
    <x-admin.report.page :title="$title" :description="__('ledger_reports.messages.'.($isPartnerStatement ? 'partner_posted_source_only' : 'posted_source_only'))">
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="ledger-report-filters"
                :refresh-url="request()->fullUrl()"
                :export-options="$exportOptions" />
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="ledger-report-filters"
            :title="__('reports.filters')"
            :action="route(request()->route()->getName())"
            :expanded="$hasFilters"
            :apply-label="__('ledger_reports.actions.run')"
            :reset-url="route(request()->route()->getName())">
            <input name="run" type="hidden" value="1">

            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="ledger_subject" :label="__('ledger_reports.filters.'.$subjectField)" :required="true" />
                <select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="ledger_subject" name="{{ $subjectField }}" data-url="{{ $subjectUrl }}" data-placeholder="{{ __('common.placeholders.select') }}" data-allow-clear="true" data-delay="150" data-minimum-input-length="{{ $type === 'customer_statement' ? 0 : 1 }}" data-per-page="20" required>
                    @if($selected)<option value="{{ $selected['doc_num'] }}" selected>{{ $selected['doc_num'] }} / {{ $selected['name'] }}</option>@endif
                </select>
                @error($subjectField)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="from_date" :label="__('ledger_reports.filters.from_date')" :required="true" />
                <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="from_date" name="from_date" value="{{ $dates->formatDate($fromDate, $fromDate) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required>
                @error('from_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="to_date" :label="__('ledger_reports.filters.to_date')" :required="true" />
                <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="to_date" name="to_date" value="{{ $dates->formatDate($toDate, $toDate) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required>
                @error('to_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            @unless($isPartnerStatement)
                <div class="col-sm-6 col-xl-2">
                    <x-forms.label for="branch_doc_num" :label="__('ledger_reports.filters.branch')" />
                    <select class="form-select form-select-sm js-report-filter-control" id="branch_doc_num" name="branch_doc_num">
                        <option value="">{{ __('ledger_reports.filters.all') }}</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->doc_num }}" @selected(request('branch_doc_num') === $branch->doc_num)>{{ $branch->doc_num }} / {{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6 col-xl-2">
                    <x-forms.label for="cost_center_doc_num" :label="__('ledger_reports.filters.cost_center')" />
                    <select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="cost_center_doc_num" name="cost_center_doc_num" data-url="{{ route('admin.accounting.journal-entries.select2.cost-centers') }}" data-placeholder="{{ __('ledger_reports.filters.all') }}" data-allow-clear="true">
                        @if(request('cost_center_doc_num'))<option value="{{ request('cost_center_doc_num') }}" selected>{{ request('cost_center_doc_num') }}</option>@endif
                    </select>
                    @error('cost_center_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            @endunless
        </x-admin.report.filter-panel>

    @if($result)
        <div class="row g-3 mb-3">
            @foreach(['opening', 'period', 'ending'] as $summary)
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="text-700">{{ __('ledger_reports.summary.'.($summary === 'opening' && $isPartnerStatement ? 'prior' : $summary)) }}</h6>
                            <div class="d-flex justify-content-between gap-3"><span>{{ __('ledger_reports.columns.debit') }}</span><strong dir="ltr">{{ $numbers->format($result[$summary]['debit']) }}</strong></div>
                            <div class="d-flex justify-content-between gap-3"><span>{{ __('ledger_reports.columns.credit') }}</span><strong dir="ltr">{{ $numbers->format($result[$summary]['credit']) }}</strong></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if($isPartnerStatement && $result['opening_movements'] !== [])
            <details class="card mb-3" data-opening-balance-details open>
                <summary class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="fw-semibold">{{ __('ledger_reports.summary.prior_details') }}</span>
                    <span class="badge rounded-pill bg-200 text-700">{{ count($result['opening_movements']) }}</span>
                </summary>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead class="bg-100"><tr>
                            @foreach(['date', 'document', 'reference', 'description', 'debit', 'credit', 'balance'] as $column)<th class="{{ in_array($column, ['debit', 'credit', 'balance'], true) ? 'text-end' : '' }}">{{ __('ledger_reports.columns.'.$column) }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                            @foreach($result['opening_movements'] as $movement)
                                <tr>
                                    <td>{{ $dates->formatDate($movement['entry_date'], $movement['entry_date']) }}</td>
                                    <td dir="ltr">{{ $movement['source_doc_num'] ?: $movement['doc_num'] }}</td>
                                    <td dir="ltr">{{ $movement['reference_no'] ?: '—' }}</td>
                                    <td>{{ $movement['description'] }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($movement['debit']) }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($movement['credit']) }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format((float) $movement['running_credit'] !== 0.0 ? $movement['running_credit'] : $movement['running_debit']) }} {{ __('ledger_reports.balance.'.((float) $movement['running_credit'] !== 0.0 ? 'credit' : 'debit')) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif

        <div class="card">
            <div class="card-header">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <h6 class="mb-1">{{ $selected['doc_num'] }} / {{ $selected['name'] }}</h6>
                        <span class="text-700 fs-10">
                            @unless($isPartnerStatement){{ $selected['account'] }} / @endunless{{ data_get($result, 'currency.code') }}
                        </span>
                    </div>
                    <div class="text-end fs-10 text-700">
                        <div dir="ltr">{{ $dates->formatDate($fromDate, $fromDate) }} — {{ $dates->formatDate($toDate, $toDate) }}</div>
                        <div>{{ data_get($operatingContext, 'company.label') }} / {{ data_get($operatingContext, 'financial_period.label') }}</div>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="erp-datatable-scroll">
                    @if($isPartnerStatement)
                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="bg-100">
                                <tr>
                                    <th class="dt-date">{{ __('ledger_reports.columns.date') }}</th>
                                    <th class="dt-code">{{ __('ledger_reports.columns.document') }}</th>
                                    <th class="dt-code">{{ __('ledger_reports.columns.reference') }}</th>
                                    <th class="dt-text">{{ __('ledger_reports.columns.description') }}</th>
                                    <th class="dt-number text-end">{{ __('ledger_reports.columns.debit') }}</th>
                                    <th class="dt-number text-end">{{ __('ledger_reports.columns.credit') }}</th>
                                    <th class="dt-number text-end">{{ __('ledger_reports.columns.balance') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="table-info">
                                    <td>{{ $dates->formatDate($fromDate, $fromDate) }}</td>
                                    <td>{{ __('ledger_reports.summary.prior') }}</td>
                                    <td></td>
                                    <td></td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($result['opening']['debit']) }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($result['opening']['credit']) }}</td>
                                    <td class="text-end" dir="ltr">
                                        {{ $numbers->format((float) $result['opening']['credit'] !== 0.0 ? $result['opening']['credit'] : $result['opening']['debit']) }}
                                        {{ __('ledger_reports.balance.'.((float) $result['opening']['credit'] !== 0.0 ? 'credit' : 'debit')) }}
                                    </td>
                                </tr>
                                @forelse($result['movements'] as $movement)
                                    <tr>
                                        <td>{{ $dates->formatDate($movement['entry_date'], $movement['entry_date']) }}</td>
                                        <td dir="ltr">{{ $movement['source_doc_num'] ?: $movement['doc_num'] }}</td>
                                        <td dir="ltr">{{ $movement['reference_no'] ?: '—' }}</td>
                                        <td>{{ $movement['description'] }}</td>
                                        <td class="text-end" dir="ltr">{{ $numbers->format($movement['debit']) }}</td>
                                        <td class="text-end" dir="ltr">{{ $numbers->format($movement['credit']) }}</td>
                                        <td class="text-end" dir="ltr">
                                            {{ $numbers->format((float) $movement['running_credit'] !== 0.0 ? $movement['running_credit'] : $movement['running_debit']) }}
                                            {{ __('ledger_reports.balance.'.((float) $movement['running_credit'] !== 0.0 ? 'credit' : 'debit')) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-700 py-4" colspan="7">{{ __('ledger_reports.messages.no_movements') }}</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-light fw-semibold">
                                <tr>
                                    <td>{{ $dates->formatDate($toDate, $toDate) }}</td>
                                    <td colspan="3">{{ __('ledger_reports.summary.period') }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($result['period']['debit']) }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($result['period']['credit']) }}</td>
                                    <td class="text-end" dir="ltr">
                                        {{ $numbers->format((float) $result['ending']['credit'] !== 0.0 ? $result['ending']['credit'] : $result['ending']['debit']) }}
                                        {{ __('ledger_reports.balance.'.((float) $result['ending']['credit'] !== 0.0 ? 'credit' : 'debit')) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    @else
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
                    @endif
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
    </x-admin.report.page>
@endsection
