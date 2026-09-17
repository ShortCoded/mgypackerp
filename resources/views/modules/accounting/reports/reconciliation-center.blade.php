@extends('layouts.app')

@php
    $title = __('reconciliation_center.title');
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $statusClasses = [
        'MATCHED' => 'success',
        'DIFFERENCE' => 'danger',
        'MISSING MAPPING' => 'warning',
        'INSUFFICIENT DATA' => 'secondary',
        'NOT APPLICABLE' => 'info',
    ];
    $exportQuery = array_filter([...$filters, 'run' => 1], fn ($value) => $value !== null && $value !== '');
    $exportOptions = $report ? [
        ['label' => __('reports.export_excel'), 'url' => route('admin.accounting.reports.reconciliation-center.export.excel', $exportQuery), 'icon' => 'file-excel', 'permission' => 'reports.account_ledger.export'],
        ['label' => __('reports.export_csv'), 'url' => route('admin.accounting.reports.reconciliation-center.export.csv', $exportQuery), 'icon' => 'file-csv', 'permission' => 'reports.account_ledger.export'],
        ['label' => __('reports.export_pdf'), 'url' => route('admin.accounting.reports.reconciliation-center.export.pdf', $exportQuery), 'icon' => 'file-pdf', 'permission' => 'reports.account_ledger.export', 'newTab' => true],
    ] : [];
@endphp

@section('title', $title)

@section('content')
    <x-admin.report.page :title="$title" :description="__('reconciliation_center.description')">
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="reconciliation-center-filters"
                :refresh-url="request()->fullUrl()"
                :export-options="$exportOptions" />
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="reconciliation-center-filters"
            :title="__('reports.filters')"
            :action="route('admin.accounting.reports.reconciliation-center')"
            :expanded="request()->boolean('run') || $errors->any()"
            :apply-label="__('reconciliation_center.actions.run')"
            :reset-url="route('admin.accounting.reports.reconciliation-center')">
            <x-forms.input name="run" type="hidden" value="1" />

            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="reconciliation-from-date" :label="__('reconciliation_center.filters.from_date')" :required="true" />
                <x-forms.date-input id="reconciliation-from-date" name="from_date" :value="$filters['from_date']" :min="$period->from_date->toDateString()" :max="$period->to_date->toDateString()" required />
                @error('from_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-2">
                <x-forms.label for="reconciliation-to-date" :label="__('reconciliation_center.filters.to_date')" :required="true" />
                <x-forms.date-input id="reconciliation-to-date" name="to_date" :value="$filters['to_date']" :min="$period->from_date->toDateString()" :max="$period->to_date->toDateString()" required />
                @error('to_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="reconciliation-branch" :label="__('reconciliation_center.filters.branch')" :required="true" />
                <x-forms.select class="form-select form-select-sm" id="reconciliation-branch" name="branch_doc_num" required>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->doc_num }}" @selected($filters['branch_doc_num'] === $branch->doc_num)>{{ $branch->doc_num }} / {{ $branch->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-sm-6 col-xl-4">
                <x-forms.label for="reconciliation-type" :label="__('reconciliation_center.filters.type')" />
                <x-forms.select class="form-select form-select-sm" id="reconciliation-type" name="type">
                    <option value="">{{ __('reconciliation_center.filters.all') }}</option>
                    @foreach($types as $type)
                        <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ __('reconciliation_center.types.'.$type) }}</option>
                    @endforeach
                </x-forms.select>
            </div>
        </x-admin.report.filter-panel>

        @if($report)
            <div class="row g-3 mb-3">
                <div class="col-sm-6 col-xl-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-700 fs-10">{{ __('reconciliation_center.summary.checks') }}</div>
                        <div class="fs-5 fw-semibold">{{ count($report['results']) }}</div>
                    </div></div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-700 fs-10">{{ __('reconciliation_center.summary.mismatches') }}</div>
                        <div class="fs-5 fw-semibold text-danger">{{ $report['mismatch_count'] }}</div>
                    </div></div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-700 fs-10">{{ __('reconciliation_center.summary.absolute_difference') }}</div>
                        <div class="fs-5 fw-semibold" dir="ltr">{{ $numbers->format($report['absolute_difference_total']) }}</div>
                    </div></div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-700 fs-10">{{ __('reconciliation_center.summary.period') }}</div>
                        <div class="fw-semibold" dir="ltr">{{ $dates->formatDate($filters['from_date'], $filters['from_date']) }} — {{ $dates->formatDate($filters['to_date'], $filters['to_date']) }}</div>
                    </div></div>
                </div>
            </div>

            @foreach($report['results'] as $result)
                <div class="card mb-3">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h6 class="mb-1">{{ $result['title'] }}</h6>
                            <span class="text-700 fs-10">{{ trans_choice('reconciliation_center.summary.row_count', $result['summary']['row_count'], ['count' => $result['summary']['row_count']]) }}</span>
                        </div>
                        <span class="badge rounded-pill bg-{{ $statusClasses[$result['status']] ?? 'secondary' }}-subtle text-{{ $statusClasses[$result['status']] ?? 'secondary' }}-emphasis">{{ __('reconciliation_center.statuses.'.$result['status']) }}</span>
                    </div>
                    @if($result['notes'] !== [])
                        <div class="card-body border-bottom py-2">
                            @foreach($result['notes'] as $note)<div class="text-700 fs-10">{{ $note }}</div>@endforeach
                        </div>
                    @endif
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="bg-100"><tr>
                                <th>{{ __('reconciliation_center.columns.item') }}</th>
                                <th>{{ __('reconciliation_center.columns.status') }}</th>
                                <th class="text-end">{{ __('reconciliation_center.columns.source_opening') }}</th>
                                <th class="text-end">{{ __('reconciliation_center.columns.gl_opening') }}</th>
                                <th class="text-end">{{ __('reconciliation_center.columns.opening_difference') }}</th>
                                <th class="text-end">{{ __('reconciliation_center.columns.source_movement') }}</th>
                                <th class="text-end">{{ __('reconciliation_center.columns.gl_movement') }}</th>
                                <th class="text-end">{{ __('reconciliation_center.columns.movement_difference') }}</th>
                                <th class="text-end">{{ __('reconciliation_center.columns.source_ending') }}</th>
                                <th class="text-end">{{ __('reconciliation_center.columns.gl_ending') }}</th>
                                <th class="text-end">{{ __('reconciliation_center.columns.ending_difference') }}</th>
                            </tr></thead>
                            <tbody>
                                @forelse($result['rows'] as $row)
                                    <tr>
                                        <td>
                                            @if($row['gl_url'] ?? null)<a href="{{ $row['gl_url'] }}">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif
                                        </td>
                                        <td><span class="badge bg-{{ $statusClasses[$row['status']] ?? 'secondary' }}-subtle text-{{ $statusClasses[$row['status']] ?? 'secondary' }}-emphasis">{{ __('reconciliation_center.statuses.'.$row['status']) }}</span></td>
                                        @foreach(['source_opening', 'gl_opening', 'opening_difference', 'source_movement', 'gl_movement', 'movement_difference', 'source_ending', 'gl_ending', 'ending_difference'] as $column)
                                            <td class="text-end {{ str_ends_with($column, 'difference') && bccomp($row[$column], '0', 4) !== 0 ? 'text-danger fw-semibold' : '' }}" dir="ltr">{{ $numbers->format($row[$column]) }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="11" class="text-center text-700 py-4">{{ __('reconciliation_center.messages.no_rows') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif
    </x-admin.report.page>
@endsection
