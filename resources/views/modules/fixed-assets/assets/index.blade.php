@extends('layouts.app')

@php
    $resource = 'fixed_assets';
    $routePrefix = 'admin.fixed-assets.assets';
    $title = __('fixed_assets.title');
    $columns = ['doc_num', 'asset_name', 'asset_category', 'branch', 'status', 'purchase_value', 'previous_depreciation', 'net_value', 'entry_type', 'cost_center', 'currency', 'is_depreciable', 'created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_by', 'deleted_at'];
    $auditColumns = ['created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_by', 'deleted_at'];
@endphp

@section('title', $title)

@section('content')
    @can('fixed_assets.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#fixed-assets-document-number-settings"
                    aria-expanded="false"
                    aria-controls="fixed-assets-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="fixed-assets-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-fixed-assets-document-number-settings-form"
                        action="{{ route($routePrefix.'.document-number-settings.update') }}"
                        method="POST"
                        novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-form-alert" role="alert">
                            <span class="js-form-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="fixed-assets-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="fixed-assets-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="fixed-assets-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <input class="form-control" id="fixed-assets-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 0 }}" required>
                                <div class="invalid-feedback d-block" data-error-for="padding"></div>
                            </div>
                            <div class="col-md-auto">
                                <button type="submit" class="btn btn-falcon-primary">
                                    <span class="fas fa-save me-1"></span>{{ __('common.document_number_settings.save') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <div class="card mb-3">
        <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div>
                <h5 class="mb-1">{{ $title }}</h5>
                <p class="text-600 mb-0">{{ __('fixed_assets.product.register_help') }}</p>
            </div>
            <div class="d-flex flex-column flex-sm-row gap-2 flex-shrink-0">
                <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.fixed-assets.movements.index') }}">
                    <span class="fas fa-exchange-alt me-1" aria-hidden="true"></span>{{ __('fixed_assets.product.open_movements') }}
                </a>
                @can('fixed_assets.depreciation.preview')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.depreciation.index') }}"><span class="fas fa-calculator me-1" aria-hidden="true"></span>{{ __('fixed_assets.lifecycle.depreciation_run') }}</a>@endcan
                @can('fixed_assets.reports')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.reports.index') }}"><span class="fas fa-chart-bar me-1" aria-hidden="true"></span>{{ __('fixed_assets.reports.title') }}</a>@endcan
            </div>
        </div>
    </div>
    <form class="card card-body mb-3 js-asset-register-filters" autocomplete="off">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h6 class="mb-0">{{ __('fixed_assets.product.register_filters') }}</h6>
            <span class="small text-600">{{ __('fixed_assets.product.register_filters_help') }}</span>
        </div>
        <div class="row g-2 align-items-end">
            @foreach(['status' => \Modules\FixedAssets\Models\FixedAsset::statuses(), 'entry_type' => \Modules\FixedAssets\Models\FixedAsset::entryTypes()] as $field => $choices)
            <div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="asset-filter-{{ $field }}">{{ __('fixed_assets.attributes.'.$field) }}</label><select class="form-select" name="{{ $field }}" id="asset-filter-{{ $field }}"><option value=""></option>@foreach($choices as $choice)<option value="{{ $choice }}">{{ __($field === 'status' ? 'fixed_assets.statuses.'.$choice : 'fixed_assets.entry_types.'.$choice) }}</option>@endforeach</select></div>
            @endforeach
            @foreach(['asset_group_account_doc_num' => ['asset_group_account', 'asset-categories'], 'branch_doc_num' => ['branch', 'branches'], 'cost_center_doc_num' => ['cost_center', 'cost-centers']] as $field => [$label, $endpoint])
            <div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="asset-filter-{{ $field }}">{{ __('fixed_assets.attributes.'.$label) }}</label><select class="form-select js-select2-ajax" name="{{ $field }}" id="asset-filter-{{ $field }}" data-url="{{ route('admin.fixed-assets.select2.'.$endpoint) }}" data-allow-clear="true"></select></div>
            @endforeach
            <div class="col-12 col-sm-auto d-grid d-sm-block"><button type="reset" class="btn btn-falcon-default">{{ __('common.actions.reset') }}</button></div>
        </div>
    </form>
    <div class="card erp-datatable-card fixed-assets-datatable-card" data-fixed-assets-root data-bulk-delete-url="{{ route($routePrefix.'.bulk-delete') }}">
        <div class="card-header">
            <div class="row flex-between-center gy-2">
                <div class="col-12 col-sm-auto d-flex align-items-center pe-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ $title }}</h5>
                </div>
                <div class="col-12 col-sm-auto ms-auto text-end ps-0 d-flex justify-content-end align-items-center gap-2 flex-wrap">
                    @can('fixed_assets.accounting.configure')
                        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.fixed-assets.accounting.index') }}" title="{{ __('fixed_assets.product.accounting_override_help') }}" aria-label="{{ __('fixed_assets.lifecycle.accounting_mappings') }}">
                            <span class="fas fa-sliders-h" aria-hidden="true"></span><span class="d-none d-xl-inline ms-1">{{ __('fixed_assets.prerequisites.advanced') }}</span>
                        </a>
                    @endcan
                    @can('fixed_assets.view_trashed')
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="fixed_assets_trash_filter">{{ __('business_partners.trash.filter_label') }}</label>
                            <select class="form-select form-select-sm w-auto js-fixed-assets-trash-filter" id="fixed_assets_trash_filter" aria-label="{{ __('business_partners.trash.filter_label') }}">
                                <option value="active">{{ __('business_partners.trash.active') }}</option>
                                <option value="trashed">{{ __('business_partners.trash.trashed') }}</option>
                                <option value="all">{{ __('business_partners.trash.all') }}</option>
                            </select>
                        </div>
                    @endcan
                    @can('fixed_assets.delete')
                        <div class="d-none align-items-center gap-2" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('business_partners.bulk_action') }}">
                                <option value="delete">{{ __('common.actions.delete') }}</option>
                            </select>
                            <button type="button" class="btn btn-falcon-danger btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                            </button>
                        </div>
                    @endcan
                    @can('fixed_assets.import')
                        @can('fixed_assets.create')
                            <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.fixed-assets.assets.import.index') }}">
                                <span class="fas fa-file-import me-1" aria-hidden="true"></span><span class="d-none d-md-inline">{{ __('excel_imports.actions.import') }}</span>
                            </a>
                        @endcan
                    @endcan
                    <x-buttons.add-record :href="route($routePrefix.'.create')" permission="fixed_assets.create" />
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="fixed-assets-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-fixed-assets-table"
                            data-url="{{ route($routePrefix.'.data') }}"
                            data-table-name="fixed_assets">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('business_partners.select_all') }}">
                                        </div>
                                    </th>
                                    @foreach($columns as $index => $column)
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap {{ $index === 0 ? 'all no-colvis dt-code' : 'dt-text dt-ellipsis' }}">{{ in_array($column, $auditColumns, true) ? __("common.fields.{$column}") : __(match ($column) { 'purchase_value' => 'fixed_assets.reports.columns.cost', 'previous_depreciation' => 'fixed_assets.reports.columns.accumulated_depreciation', 'net_value' => 'fixed_assets.reports.columns.net_book_value', default => "fixed_assets.columns.{$column}" }) }}</th>
                                    @endforeach
                                    <th class="text-900 no-sort pe-1 align-middle data-table-row-action all no-colvis dt-actions"></th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.fixedAssetsMessages = @json(__('fixed_assets.js'));
        window.fixedAssetsCrudColumns = @json($columns);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/FixedAssets/fixed-assets.js') }}"></script>
@endpush
