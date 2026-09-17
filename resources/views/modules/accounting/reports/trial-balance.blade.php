@extends('layouts.app')

@php
    $title = __('trial_balance.title');
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $fromDate = request('from_date', $period?->from_date?->format('Y-m-d'));
    $toDate = request('to_date', $period?->to_date?->format('Y-m-d'));
    $valueMode = request('value_mode', \Modules\Accounting\Services\TrialBalanceQueryService::ValueCombined);
    $totalsBasis = request('totals_basis', \Modules\Accounting\Services\TrialBalanceQueryService::TotalsPeriod);
    $displayMode = request('display_mode', \Modules\Accounting\Services\TrialBalanceQueryService::DisplayTree);
    $selectedLevel = (int) request('level', last($accountLevels) ?: 1);
    $visibleColumns = data_get($result, 'presentation.columns', []);
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
            <div class="col-sm-6 col-xl-4">
                <x-forms.label for="account_doc_num" :label="__('trial_balance.filters.account')" />
                <x-forms.select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="account_doc_num" name="account_doc_num" data-url="{{ route('admin.accounting.journal-entries.select2.accounts', ['report_scope' => 1, 'include_historical' => 1, 'hierarchy' => 1]) }}" data-placeholder="{{ __('trial_balance.filters.all') }}" data-allow-clear="true">
                    @if(request('account_doc_num'))<option value="{{ request('account_doc_num') }}" selected>{{ request('account_doc_num') }}</option>@endif
                </x-forms.select>
                @error('account_doc_num')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="value_mode" :label="__('trial_balance.filters.value_mode')" :required="true" />
                <x-forms.select class="form-select form-select-sm js-report-filter-control" id="value_mode" name="value_mode" required>
                    @foreach(['totals', 'balances', 'combined'] as $mode)
                        <option value="{{ $mode }}" @selected($valueMode === $mode)>{{ __('trial_balance.value_modes.'.$mode) }}</option>
                    @endforeach
                </x-forms.select>
                @error('value_mode')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="totals_basis" :label="__('trial_balance.filters.totals_basis')" :required="true" />
                <x-forms.select class="form-select form-select-sm js-report-filter-control" id="totals_basis" name="totals_basis" required>
                    @foreach(['period', 'cumulative'] as $basis)
                        <option value="{{ $basis }}" @selected($totalsBasis === $basis)>{{ __('trial_balance.totals_bases.'.$basis) }}</option>
                    @endforeach
                </x-forms.select>
                @error('totals_basis')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="display_mode" :label="__('trial_balance.filters.display_mode')" :required="true" />
                <x-forms.select class="form-select form-select-sm js-report-filter-control" id="display_mode" name="display_mode" required>
                    @foreach(['aggregate', 'detail', 'tree'] as $mode)
                        <option value="{{ $mode }}" @selected($displayMode === $mode)>{{ __('trial_balance.display_modes.'.$mode) }}</option>
                    @endforeach
                </x-forms.select>
                @error('display_mode')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="level" :label="__('trial_balance.filters.level')" />
                <x-forms.select class="form-select form-select-sm js-report-filter-control" id="level" name="level">
                    @foreach($accountLevels as $level)
                        <option value="{{ $level }}" @selected($selectedLevel === $level)>{{ __('trial_balance.filters.level_value', ['level' => $level]) }}</option>
                    @endforeach
                </x-forms.select>
                @error('level')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
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
                        <div class="text-700 fs-10 mt-1">
                            {{ __('trial_balance.value_modes.'.$result['presentation']['value_mode']) }} /
                            {{ __('trial_balance.display_modes.'.$result['presentation']['display_mode']) }} /
                            {{ __('trial_balance.totals_bases.'.$result['presentation']['totals_basis']) }}
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="mb-1">{{ data_get($operatingContext, 'company.label') }} / {{ data_get($operatingContext, 'financial_period.label') }}</div>
                        <span class="badge rounded-pill {{ $result['scope_is_partial'] ? 'bg-warning-subtle text-warning-emphasis' : ($result['is_balanced'] ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis') }}">
                            {{ __('trial_balance.messages.'.$result['balance_status']) }}
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-striped table-hover align-middle mb-0 erp-datatable-wide" @if($result['presentation']['display_mode'] === 'tree') data-trial-balance-tree data-initial-level="{{ $result['presentation']['level'] }}" @endif>
                            <thead class="bg-100">
                                <tr>
                                    <th class="dt-code align-middle">{{ __('trial_balance.columns.account_code') }}</th>
                                    <th class="dt-text align-middle">{{ __('trial_balance.columns.account_name') }}</th>
                                    @foreach($visibleColumns as $column)
                                        <th class="dt-number text-end">{{ __('trial_balance.headings.'.$column) }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($result['rows'] as $row)
                                    <tr class="{{ $row['is_group'] ? 'fw-semibold bg-light' : '' }}" data-account-id="{{ $row['id'] }}" data-parent-id="{{ $row['parent_id'] }}" data-level="{{ $row['level'] }}">
                                        <td dir="ltr">{{ $row['account_code'] }}</td>
                                        <td>
                                            <span style="padding-inline-start: {{ max(0, $row['level'] - 1) * 1.25 }}rem">
                                                @if($result['presentation']['display_mode'] === 'tree' && $row['has_children'])
                                                    <button type="button" class="btn btn-link btn-sm p-0 me-1 text-decoration-none js-trial-balance-toggle" data-toggle-account="{{ $row['id'] }}" aria-expanded="{{ $row['level'] < $result['presentation']['level'] ? 'true' : 'false' }}" aria-label="{{ __('trial_balance.actions.toggle') }}">
                                                        <i class="fas {{ $row['level'] < $result['presentation']['level'] ? 'fa-chevron-down' : 'fa-chevron-right' }}" aria-hidden="true"></i>
                                                    </button>
                                                @endif
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
                                        @foreach($visibleColumns as $column)
                                            <td class="text-end" dir="ltr">{{ $numbers->format($row[$column]) }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-700 py-4" colspan="{{ 2 + count($visibleColumns) }}">{{ __('trial_balance.messages.no_accounts') }}</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="2">{{ __('trial_balance.total') }}</td>
                                    @foreach($visibleColumns as $column)
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-trial-balance-tree]').forEach((table) => {
        const initialLevel = Number(table.dataset.initialLevel || 1);
        const rows = [...table.querySelectorAll('tbody tr[data-account-id]')];
        const rowsById = new Map(rows.map((row) => [row.dataset.accountId, row]));
        const expanded = new Set(rows.filter((row) => Number(row.dataset.level) < initialLevel).map((row) => row.dataset.accountId));

        const isVisible = (row) => {
            let parentId = row.dataset.parentId;

            while (parentId) {
                if (!expanded.has(parentId)) {
                    return false;
                }

                parentId = rowsById.get(parentId)?.dataset.parentId || '';
            }

            return true;
        };

        const render = () => {
            rows.forEach((row) => {
                row.hidden = !isVisible(row);
            });

            table.querySelectorAll('[data-toggle-account]').forEach((button) => {
                const isExpanded = expanded.has(button.dataset.toggleAccount);
                button.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
                button.querySelector('i')?.classList.toggle('fa-chevron-down', isExpanded);
                button.querySelector('i')?.classList.toggle('fa-chevron-right', !isExpanded);
            });
        };

        table.addEventListener('click', (event) => {
            const button = event.target.closest('[data-toggle-account]');

            if (!button) {
                return;
            }

            const accountId = button.dataset.toggleAccount;
            expanded.has(accountId) ? expanded.delete(accountId) : expanded.add(accountId);
            render();
        });

        render();
    });
});
</script>
@endpush
