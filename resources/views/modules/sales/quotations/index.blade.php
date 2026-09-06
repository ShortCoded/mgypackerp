@extends('layouts.app')

@php
    $title = __('quotations.title');
    $columns = ['doc_num', 'customer', 'subject_project', 'quotation_type', 'current_revision', 'status', 'currency', 'total', 'quotation_date', 'valid_until', 'created_by', 'updated_by'];
@endphp

@section('title', $title)

@section('content')
    <div class="card mb-3">
        <div class="card-header py-2"><button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between" type="button" data-bs-toggle="collapse" data-bs-target="#quotation-filters" aria-expanded="false"><span class="fw-semibold">{{ __('Filters') }}</span><span class="fas fa-chevron-down fs-11"></span></button></div>
        <div class="collapse" id="quotation-filters"><div class="card-body"><form id="quotation-filter-form" class="row g-3 align-items-end">
            <div class="col-md-4"><label for="quotation-filter-status" class="form-label">{{ __('Status') }}</label><select class="form-select" name="status" id="quotation-filter-status"><option value="">{{ __('All statuses') }}</option>@foreach(['draft','sent','accepted','rejected','expired','cancelled','converted'] as $status)<option value="{{ $status }}">{{ __('quotations.statuses.'.$status) }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label" for="quotation-filter-date">{{ __('From date') }}</label><input class="form-control js-date-picker" name="date_from" id="quotation-filter-date"></div>
            <div class="col-md-4"><button class="btn btn-falcon-primary" type="submit">{{ __('Apply') }}</button> <button class="btn btn-falcon-default" type="reset">{{ __('Reset') }}</button></div>
        </form></div></div>
    </div>
    @can('quotations.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#quotations-document-number-settings"
                    aria-expanded="false"
                    aria-controls="quotations-document-number-settings">
                    <span class="fw-semibold">{{ __('common.document_number_settings.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="quotations-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __('common.document_number_settings.description') }}</p>
                    <form class="js-quotation-document-number-settings-form" action="{{ route('admin.sales.quotations.document-number-settings.update') }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div class="alert alert-danger alert-dismissible fade show d-none js-form-alert" role="alert">
                            <span class="js-form-alert-message"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('auth.alerts.close') }}"></button>
                        </div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="quotations-document-prefix">{{ __('common.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="quotations-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="quotations-document-padding">{{ __('common.document_number_settings.padding') }}</label>
                                <input class="form-control" id="quotations-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}" required>
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

    <div class="card erp-datatable-card quotation-datatable-card" data-quotations-root>
        <x-admin.crud-index-toolbar
            :title="$title"
            :add-route="route('admin.sales.quotations.create')"
            add-permission="quotations.create"
            :show-trash-filter="auth()->user()?->can('quotations.view_trashed')"
            :show-bulk-actions="auth()->user()?->can('quotations.delete') || auth()->user()?->can('quotations.restore')"
            trash-filter-id="quotations_trash_filter"
            bulk-actions-class="quotation-bulk-actions-bar"
            bulk-action-label="{{ __('quotations.bulk_action') }}"
            toolbar-actions-class="quotation-toolbar-actions"
        />
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="quotations-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-quotations-table"
                            data-url="{{ route('admin.sales.quotations.data') }}"
                            data-bulk-delete-url="{{ route('admin.sales.quotations.bulk-delete') }}"
                            data-bulk-restore-url="{{ route('admin.sales.quotations.bulk-restore') }}"
                            data-table-name="quotations">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('quotations.select_all') }}">
                                        </div>
                                    </th>
                                    @foreach ($columns as $index => $column)
                                        <th class="text-900 sort pe-1 align-middle white-space-nowrap {{ $index === 0 ? 'all no-colvis dt-code' : 'dt-text dt-ellipsis' }}">{{ __("quotations.columns.{$column}") }}</th>
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
        window.quotationMessages = @json(__('quotations.js'));
        window.quotationColumns = @json($columns);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Sales/quotations.js') }}"></script>
@endpush
