@extends('layouts.app')

@section('title', $title)

@section('content')
    @can('cheques.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#cheques-document-number-settings"
                    aria-expanded="false"
                    aria-controls="cheques-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="cheques-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <div class="row g-3">
                        @foreach(['received_cheques' => 'received', 'issued_cheques' => 'issued'] as $documentKey => $labelKey)
                            <div class="col-lg-6">
                                <form class="js-finance-document-number-settings-form border rounded-2 p-3 h-100"
                                    action="{{ route('admin.finance.cheques.document-number-settings.update') }}"
                                    method="POST"
                                    novalidate>
                                    @csrf
                                    @method('PUT')
                                    <x-forms.input type="hidden" name="document_key" value="{{ $documentKey }}" />
                                    <h6 class="mb-3">{{ __('cheques.document_number_settings.'.$labelKey) }}</h6>
                                    <div class="alert alert-danger alert-dismissible fade show d-none js-finance-alert" role="alert">
                                        <span class="js-finance-alert-message"></span>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                                    </div>
                                    <div class="row g-3 align-items-end">
                                        <div class="col-sm-7">
                                            <label class="form-label" for="{{ $documentKey }}-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                            <x-forms.input class="form-control" id="{{ $documentKey }}-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings[$documentKey]['prefix'] ?? '' }}" />
                                            <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                                        </div>
                                        <div class="col-sm-3">
                                            <label class="form-label" for="{{ $documentKey }}-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                            <x-forms.input class="form-control" id="{{ $documentKey }}-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings[$documentKey]['padding'] ?? 0 }}" required />
                                            <div class="invalid-feedback d-block" data-error-for="padding"></div>
                                        </div>
                                        <div class="col-sm-auto">
                                            <button type="submit" class="btn btn-falcon-primary">
                                                <span class="fas fa-save me-1"></span>{{ __('common.document_number_settings.save') }}
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endcan

    <div class="card erp-datatable-card finance-datatable-card" data-finance-crud-root data-resource="{{ $resource }}" data-bulk-delete-url="{{ route($routePrefix.'.bulk-delete') }}">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-12 col-xl-auto d-flex align-items-center pe-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ $title }}</h5>
                </div>
                <div class="col-12 col-xl-auto ms-xl-auto d-flex flex-wrap justify-content-xl-end align-items-center gap-2 finance-toolbar-actions">
                    <x-forms.select class="form-select form-select-sm w-auto js-finance-extra-filter js-cheque-type-filter" data-filter-name="cheque_type_filter" aria-label="{{ __('cheques.attributes.cheque_type') }}">
                        <option value="">{{ __('cheques.types.all') }}</option>
                        <option value="received">{{ __('cheques.types.received') }}</option>
                        <option value="issued">{{ __('cheques.types.issued') }}</option>
                    </x-forms.select>
                    @can('cheques.view_trashed')
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="cheques_trash_filter">{{ __('finance.trash.filter_label') }}</label>
                            <x-forms.select class="form-select form-select-sm w-auto js-finance-trash-filter" id="cheques_trash_filter" aria-label="{{ __('finance.trash.filter_label') }}">
                                <option value="active">{{ __('finance.trash.active') }}</option>
                                <option value="trashed">{{ __('finance.trash.trashed') }}</option>
                                <option value="all">{{ __('finance.trash.all') }}</option>
                            </x-forms.select>
                        </div>
                    @endcan
                    @if(auth()->user()?->can('cheques.delete'))
                        <div class="d-none align-items-center gap-2 finance-bulk-actions-bar" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <x-forms.select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('finance.bulk_action') }}">
                                <option value="">{{ __('finance.bulk_action') }}</option>
                                <option value="delete">{{ __('common.actions.delete') }}</option>
                            </x-forms.select>
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
                            data-table-name="{{ $tableName }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <x-forms.input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('finance.select_all') }}" />
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
        window.chequeMessages = @json(__('cheques.js'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/finance-foundation.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Finance/cheques.js') }}"></script>
@endpush
