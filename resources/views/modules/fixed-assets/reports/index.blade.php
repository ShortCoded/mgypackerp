@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $currentType = $filters['type'] ?? \Modules\FixedAssets\Services\FixedAssetReportService::Register;
    $advancedFields = ['asset_group_account_doc_num', 'entry_type', 'branch_doc_num', 'branch_hall_uuid', 'cost_center_doc_num', 'status', 'depreciable', 'posting_status', 'movement_type', 'user'];
    $advancedOpen = collect($advancedFields)->contains(fn (string $field): bool => filled($filters[$field] ?? null));
@endphp

@section('title', __('fixed_assets.reports.title'))

@section('content')
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-1">{{ __('fixed_assets.reports.title') }}</h5>
            <p class="text-600 mb-0">{{ __('fixed_assets.reports.help') }}</p>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.fixed-assets.reports.index') }}" class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="fixed-asset-report-type">{{ __('fixed_assets.reports.report_type') }}</label>
                    <select class="form-select" id="fixed-asset-report-type" name="type">
                        @foreach(\Modules\FixedAssets\Services\FixedAssetReportService::visibleTypes() as $type)
                            <option value="{{ $type }}" data-description="{{ __('fixed_assets.reports.descriptions.'.$type) }}" @selected($currentType === $type)>{{ __('fixed_assets.reports.types.'.$type) }}</option>
                        @endforeach
                    </select>
                    <div class="form-text" id="fixed-asset-report-description">{{ __('fixed_assets.reports.descriptions.'.$currentType) }}</div>
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="fixed-asset-report-period">{{ __('fixed_assets.lifecycle.financial_period') }}</label>
                    <select class="form-select js-select2-ajax" id="fixed-asset-report-period" name="financial_period_doc_num" data-url="{{ route('admin.select2.financial-periods') }}" data-allow-clear="true">
                        @if($filters['financial_period_doc_num'] ?? null)<option value="{{ $filters['financial_period_doc_num'] }}" selected>{{ $filters['financial_period_doc_num'] }}</option>@endif
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="fixed-asset-report-from">{{ __('fixed_assets.reports.from_date') }}</label>
                    <input class="form-control js-date-picker" id="fixed-asset-report-from" name="from_date" value="{{ isset($filters['from_date']) ? $dates->formatDate($filters['from_date'], '') : '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="fixed-asset-report-to">{{ __('fixed_assets.reports.to_date') }}</label>
                    <input class="form-control js-date-picker" id="fixed-asset-report-to" name="to_date" value="{{ isset($filters['to_date']) ? $dates->formatDate($filters['to_date'], '') : '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}">
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="fixed-asset-report-asset">{{ __('fixed_assets.reports.columns.asset') }}</label>
                    <select class="form-select js-select2-ajax" id="fixed-asset-report-asset" name="asset_doc_num" data-url="{{ route('admin.fixed-assets.select2.assets') }}" data-allow-clear="true">
                        @if($filters['asset_doc_num'] ?? null)<option value="{{ $filters['asset_doc_num'] }}" selected>{{ $filters['asset_doc_num'] }}</option>@endif
                    </select>
                </div>

                <div class="col-12">
                    <details class="fa-report-advanced border rounded-2" @if($advancedOpen) open @endif>
                        <summary class="px-3 py-2 fw-semibold text-primary">{{ __('fixed_assets.reports.advanced_filters') }}</summary>
                        <div class="row g-3 px-3 pb-3 pt-1">
                            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="fixed-asset-report-category">{{ __('fixed_assets.attributes.asset_group_account') }}</label><select class="form-select js-select2-ajax" id="fixed-asset-report-category" name="asset_group_account_doc_num" data-url="{{ route('admin.fixed-assets.select2.asset-categories') }}" data-allow-clear="true">@if($filters['asset_group_account_doc_num'] ?? null)<option value="{{ $filters['asset_group_account_doc_num'] }}" selected>{{ $filters['asset_group_account_doc_num'] }}</option>@endif</select></div>
                            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="fixed-asset-report-entry-type">{{ __('fixed_assets.attributes.entry_type') }}</label><select class="form-select" id="fixed-asset-report-entry-type" name="entry_type"><option value=""></option>@foreach(\Modules\FixedAssets\Models\FixedAsset::entryTypes() as $entryType)<option value="{{ $entryType }}" @selected(($filters['entry_type'] ?? null) === $entryType)>{{ __('fixed_assets.entry_types.'.$entryType) }}</option>@endforeach</select></div>
                            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="fixed_asset_report_branch">{{ __('fixed_assets.attributes.branch') }}</label><select class="form-select js-select2-ajax" id="fixed_asset_report_branch" name="branch_doc_num" data-url="{{ route('admin.fixed-assets.select2.branches') }}" data-allow-clear="true">@if($filters['branch_doc_num'] ?? null)<option value="{{ $filters['branch_doc_num'] }}" selected>{{ $filters['branch_doc_num'] }}</option>@endif</select></div>
                            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="fixed-asset-report-hall">{{ __('fixed_assets.attributes.hall') }}</label><select class="form-select js-select2-ajax" id="fixed-asset-report-hall" name="branch_hall_uuid" data-url="{{ route('admin.fixed-assets.select2.branch-halls') }}" data-depends-on="#fixed_asset_report_branch" data-dependent-param="branch_doc_num" data-disable-when-dependency-empty="true" data-allow-clear="true">@if($filters['branch_hall_uuid'] ?? null)<option value="{{ $filters['branch_hall_uuid'] }}" selected>{{ $filters['branch_hall_uuid'] }}</option>@endif</select></div>
                            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="fixed-asset-report-cost-center">{{ __('fixed_assets.attributes.cost_center') }}</label><select class="form-select js-select2-ajax" id="fixed-asset-report-cost-center" name="cost_center_doc_num" data-url="{{ route('admin.fixed-assets.select2.cost-centers') }}" data-allow-clear="true">@if($filters['cost_center_doc_num'] ?? null)<option value="{{ $filters['cost_center_doc_num'] }}" selected>{{ $filters['cost_center_doc_num'] }}</option>@endif</select></div>
                            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="fixed-asset-report-status">{{ __('fixed_assets.attributes.status') }}</label><select class="form-select" id="fixed-asset-report-status" name="status"><option value=""></option>@foreach(\Modules\FixedAssets\Models\FixedAsset::statuses() as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ __('fixed_assets.statuses.'.$status) }}</option>@endforeach</select></div>
                            <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="fixed-asset-report-depreciable">{{ __('fixed_assets.attributes.is_depreciable') }}</label><select class="form-select" id="fixed-asset-report-depreciable" name="depreciable"><option value=""></option><option value="1" @selected(($filters['depreciable'] ?? null) === '1')>{{ __('fixed_assets.booleans.yes') }}</option><option value="0" @selected(($filters['depreciable'] ?? null) === '0')>{{ __('fixed_assets.booleans.no') }}</option></select></div>
                            <div class="col-12 col-md-6 col-xl-3" data-report-types="depreciation"><label class="form-label" for="fixed-asset-report-posting-status">{{ __('fixed_assets.pdf.filters.posting_status') }}</label><select class="form-select" id="fixed-asset-report-posting-status" name="posting_status"><option value=""></option>@foreach([\Modules\FixedAssets\Models\FixedAssetDepreciation::StatusPosted, \Modules\FixedAssets\Models\FixedAssetDepreciation::StatusReversed] as $postingStatus)<option value="{{ $postingStatus }}" @selected(($filters['posting_status'] ?? null) === $postingStatus)>{{ __('fixed_assets.lifecycle.statuses.'.$postingStatus) }}</option>@endforeach</select></div>
                            <div class="col-12 col-md-6 col-xl-3" data-report-types="movements"><label class="form-label" for="fixed-asset-report-movement-type">{{ __('fixed_assets.cycle.type') }}</label><select class="form-select" id="fixed-asset-report-movement-type" name="movement_type"><option value=""></option>@foreach(\Modules\FixedAssets\Services\FixedAssetLedgerService::types() as $movementType)<option value="{{ $movementType }}" @selected(($filters['movement_type'] ?? null) === $movementType)>{{ __('fixed_assets.cycle.'.$movementType) }}</option>@endforeach</select></div>
                            <div class="col-12 col-md-6 col-xl-3" data-report-types="movements"><label class="form-label" for="fixed-asset-report-user">{{ __('fixed_assets.cycle.user') }}</label><input class="form-control" id="fixed-asset-report-user" name="user" value="{{ $filters['user'] ?? '' }}" placeholder="{{ __('fixed_assets.product.user_filter_help') }}"></div>
                        </div>
                    </details>
                </div>
                <div class="col-12 d-flex flex-column flex-sm-row gap-2">
                    <button class="btn btn-falcon-primary" type="submit"><span class="fas fa-filter me-1"></span>{{ __('common.actions.apply') }}</button>
                    <a class="btn btn-falcon-default" href="{{ route('admin.fixed-assets.reports.index') }}">{{ __('common.actions.reset') }}</a>
                </div>
            </form>
        </div>
    </div>

    @if($report['filters'])
        <div class="card mb-3">
            <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
                <span class="fw-semibold me-1">{{ __('fixed_assets.reports.active_filters') }}:</span>
                @foreach($report['filters'] as $label => $value)<span class="badge badge-subtle-primary rounded-pill">{{ $label }}: {{ $value }}</span>@endforeach
            </div>
        </div>
    @endif

    @if($report['totals'])
        <div class="row g-3 mb-3">
            @foreach($report['totals'] as $label => $value)
                <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3"><div class="text-600 fs-10">{{ $label }}</div><div class="fw-bold mt-1" dir="ltr">{{ $numbers->format($value) }}</div></div></div></div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
            <div><h6 class="mb-1">{{ $report['title'] }}</h6><span class="badge badge-subtle-secondary">{{ trans_choice('fixed_assets.reports.results_count', $report['rows']->count(), ['count' => $report['rows']->count()]) }}</span></div>
            <div class="d-grid d-sm-flex gap-2">
                @can('fixed_assets.print')<a target="_blank" class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.reports.pdf', request()->query()) }}"><span class="fas fa-file-pdf me-1"></span>{{ __('fixed_assets.pdf.print_pdf') }}</a>@endcan
                @can('fixed_assets.export')<a class="btn btn-falcon-success btn-sm" href="{{ route('admin.fixed-assets.reports.excel', request()->query()) }}"><span class="fas fa-file-excel me-1"></span>Excel</a>@endcan
            </div>
        </div>
        <div class="card-body p-0"><x-fixed-asset-report-table :report="$report" /></div>
    </div>
@endsection

@push('styles')
    <style>
        .fa-report-advanced > summary { cursor: pointer; list-style-position: inside; }
        .fa-report-mobile-row summary { cursor: pointer; }
        .fa-report-mobile-row:last-child { border-bottom: 0 !important; }
    </style>
@endpush

@push('scripts')
    <script>window.fixedAssetsMessages = @json(__('fixed_assets.js'));</script>
    <script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
    <script>
        const fixedAssetReportType = document.getElementById('fixed-asset-report-type');
        const syncFixedAssetReportFilters = () => {
            const type = fixedAssetReportType?.value || 'register';
            document.getElementById('fixed-asset-report-description').textContent = fixedAssetReportType?.selectedOptions[0]?.dataset.description || '';
            document.querySelectorAll('[data-report-types]').forEach((group) => {
                const visible = group.dataset.reportTypes.split(' ').includes(type);
                group.hidden = !visible;
                group.querySelectorAll('input, select').forEach((control) => { control.disabled = !visible; });
            });
        };
        fixedAssetReportType?.addEventListener('change', syncFixedAssetReportFilters);
        syncFixedAssetReportFilters();
    </script>
@endpush
