@extends('layouts.app')

@section('title', $title)

@section('content')
    @can($resource.'.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#{{ $resource }}-document-number-settings"
                    aria-expanded="false"
                    aria-controls="{{ $resource }}-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="{{ $resource }}-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-finance-document-number-settings-form"
                        action="{{ route($routePrefix.'.document-number-settings.update') }}"
                        method="POST"
                        novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-finance-alert" role="alert">
                            <span class="js-finance-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="{{ $resource }}-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="{{ $resource }}-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="{{ $resource }}-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <input class="form-control" id="{{ $resource }}-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 0 }}" required>
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

    <div class="card erp-datatable-card finance-datatable-card" data-finance-crud-root data-resource="{{ $resource }}" data-bulk-delete-url="{{ route($routePrefix.'.bulk-delete') }}" @if($resource === 'opening_balances') data-bulk-approve-url="{{ route($routePrefix.'.bulk-approve') }}" @endif>
        <div class="card-header">
            <div class="row flex-between-center">
                <div class="col-6 col-sm-auto d-flex align-items-center pe-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ $title }}</h5>
                </div>
                <div class="col-6 col-sm-auto ms-auto text-end ps-0 d-flex justify-content-end align-items-center gap-2 finance-toolbar-actions">
                    @can($resource.'.view_trashed')
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="{{ $resource }}_trash_filter">{{ __('finance.trash.filter_label') }}</label>
                            <select class="form-select form-select-sm w-auto js-finance-trash-filter" id="{{ $resource }}_trash_filter" aria-label="{{ __('finance.trash.filter_label') }}">
                                <option value="active">{{ __('finance.trash.active') }}</option>
                                <option value="trashed">{{ __('finance.trash.trashed') }}</option>
                                <option value="all">{{ __('finance.trash.all') }}</option>
                            </select>
                        </div>
                    @endcan
                    @if(auth()->user()?->can($resource.'.delete') || ($resource === 'opening_balances' && auth()->user()?->can('opening_balances.approve')))
                        <div class="d-none align-items-center gap-2 finance-bulk-actions-bar" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('finance.bulk_action') }}">
                                <option value="">{{ __('finance.bulk_action') }}</option>
                                @can($resource.'.delete')
                                    <option value="delete">{{ __('common.actions.delete') }}</option>
                                @endcan
                                @if($resource === 'opening_balances')
                                    @can('opening_balances.approve')
                                        <option value="approve" data-confirm-title="{{ __('opening_balances.actions.bulk_approve') }}" data-confirm-text="{{ __('opening_balances.messages.bulk_approve_confirm') }}" data-confirm-yes="{{ __('opening_balances.actions.approve') }}">{{ __('opening_balances.actions.approve') }}</option>
                                    @endcan
                                @endif
                            </select>
                            <button type="button" class="btn btn-falcon-danger btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                            </button>
                        </div>
                    @endif
                    <x-buttons.add-record :href="route($routePrefix.'.create')" :permission="$resource.'.create'" />
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="{{ $tableId }}" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-finance-table"
                            data-url="{{ route($routePrefix.'.data') }}"
                            data-table-name="{{ $tableName ?? str_replace('-', '_', basename(str_replace('.', '/', $routePrefix))) }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('finance.select_all') }}">
                                        </div>
                                    </th>
                                    @foreach($columns as $index => $column)
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap {{ $index === 0 ? 'all no-colvis dt-code' : 'dt-text dt-ellipsis' }}">{{ __("finance.columns.{$column}") }}</th>
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
        window.financeCrudMessages = @json(__('finance.js'));
        window.financeCrudColumns = @json($columns);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/finance-foundation.js') }}"></script>
@endpush
