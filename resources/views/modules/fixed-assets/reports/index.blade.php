@extends('layouts.app')

@php($dates = app(\Modules\Core\Services\DateFormatService::class))

@section('title', __('fixed_assets.reports.title'))

@section('content')
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('fixed_assets.reports.title') }}</h5></div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.fixed-assets.reports.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.reports.report_type') }}</label><select class="form-select" name="type">@foreach(\Modules\FixedAssets\Services\FixedAssetReportService::types() as $type)<option value="{{ $type }}" @selected(($filters['type'] ?? 'register') === $type)>{{ __('fixed_assets.reports.types.'.$type) }}</option>@endforeach</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.reports.columns.asset') }}</label><select class="form-select js-select2-ajax" name="asset_doc_num" data-url="{{ route('admin.fixed-assets.select2.assets') }}" data-allow-clear="true">@if($filters['asset_doc_num'] ?? null)<option value="{{ $filters['asset_doc_num'] }}" selected>{{ $filters['asset_doc_num'] }}</option>@endif</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.attributes.asset_group_account') }}</label><select class="form-select js-select2-ajax" name="asset_group_account_doc_num" data-url="{{ route('admin.fixed-assets.select2.asset-categories') }}" data-allow-clear="true">@if($filters['asset_group_account_doc_num'] ?? null)<option value="{{ $filters['asset_group_account_doc_num'] }}" selected>{{ $filters['asset_group_account_doc_num'] }}</option>@endif</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.lifecycle.financial_period') }}</label><select class="form-select js-select2-ajax" name="financial_period_doc_num" data-url="{{ route('admin.select2.financial-periods') }}" data-allow-clear="true">@if($filters['financial_period_doc_num'] ?? null)<option value="{{ $filters['financial_period_doc_num'] }}" selected>{{ $filters['financial_period_doc_num'] }}</option>@endif</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.reports.from_date') }}</label><input class="form-control js-date-picker" name="from_date" value="{{ isset($filters['from_date']) ? $dates->formatDate($filters['from_date'], '') : '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}"></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.reports.to_date') }}</label><input class="form-control js-date-picker" name="to_date" value="{{ isset($filters['to_date']) ? $dates->formatDate($filters['to_date'], '') : '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}"></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.attributes.entry_type') }}</label><select class="form-select" name="entry_type"><option value=""></option>@foreach(\Modules\FixedAssets\Models\FixedAsset::entryTypes() as $entryType)<option value="{{ $entryType }}" @selected(($filters['entry_type'] ?? null) === $entryType)>{{ __('fixed_assets.entry_types.'.$entryType) }}</option>@endforeach</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.attributes.branch') }}</label><select class="form-select js-select2-ajax" id="fixed_asset_report_branch" name="branch_doc_num" data-url="{{ route('admin.fixed-assets.select2.branches') }}" data-allow-clear="true">@if($filters['branch_doc_num'] ?? null)<option value="{{ $filters['branch_doc_num'] }}" selected>{{ $filters['branch_doc_num'] }}</option>@endif</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.attributes.hall') }}</label><select class="form-select js-select2-ajax" name="branch_hall_uuid" data-url="{{ route('admin.fixed-assets.select2.branch-halls') }}" data-depends-on="#fixed_asset_report_branch" data-dependent-param="branch_doc_num" data-disable-when-dependency-empty="true" data-allow-clear="true">@if($filters['branch_hall_uuid'] ?? null)<option value="{{ $filters['branch_hall_uuid'] }}" selected>{{ $filters['branch_hall_uuid'] }}</option>@endif</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.attributes.cost_center') }}</label><select class="form-select js-select2-ajax" name="cost_center_doc_num" data-url="{{ route('admin.fixed-assets.select2.cost-centers') }}" data-allow-clear="true">@if($filters['cost_center_doc_num'] ?? null)<option value="{{ $filters['cost_center_doc_num'] }}" selected>{{ $filters['cost_center_doc_num'] }}</option>@endif</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.attributes.status') }}</label><select class="form-select" name="status"><option value=""></option>@foreach(\Modules\FixedAssets\Models\FixedAsset::statuses() as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ __('fixed_assets.statuses.'.$status) }}</option>@endforeach</select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.attributes.is_depreciable') }}</label><select class="form-select" name="depreciable"><option value=""></option><option value="1" @selected(($filters['depreciable'] ?? null) === '1')>{{ __('fixed_assets.booleans.yes') }}</option><option value="0" @selected(($filters['depreciable'] ?? null) === '0')>{{ __('fixed_assets.booleans.no') }}</option></select></div>
                <div class="col-md-4 col-xl-3"><label class="form-label">{{ __('fixed_assets.reports.columns.status') }}</label><select class="form-select" name="posting_status"><option value=""></option>@foreach([\Modules\FixedAssets\Models\FixedAssetDepreciation::StatusPosted, \Modules\FixedAssets\Models\FixedAssetDepreciation::StatusReversed] as $postingStatus)<option value="{{ $postingStatus }}" @selected(($filters['posting_status'] ?? null) === $postingStatus)>{{ __('fixed_assets.lifecycle.statuses.'.$postingStatus) }}</option>@endforeach</select></div>
                <div class="col-auto"><button class="btn btn-falcon-primary" type="submit">{{ __('common.actions.apply') }}</button></div>
                <div class="col-auto"><a class="btn btn-falcon-default" href="{{ route('admin.fixed-assets.reports.index') }}">{{ __('common.actions.reset') }}</a></div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><h6 class="mb-0">{{ $report['title'] }}</h6><div class="d-flex gap-2">@can('fixed_assets.print')<a target="_blank" class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.reports.pdf', request()->query()) }}"><span class="fas fa-file-pdf me-1"></span>{{ __('fixed_assets.pdf.print_pdf') }}</a>@endcan @can('fixed_assets.export')<a class="btn btn-falcon-success btn-sm" href="{{ route('admin.fixed-assets.reports.excel', request()->query()) }}">Excel</a>@endcan</div></div>
        <div class="card-body p-0"><x-fixed-asset-report-table :report="$report" /></div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
@endpush
