@extends('layouts.app')

@section('title', __('price_lists.title'))

@php
    $messages = [
        'deleteConfirmTitle' => __('price_lists.messages.delete_confirm_title'),
        'deleteConfirmText' => __('price_lists.messages.delete_confirm_text'),
        'deleteConfirmYes' => __('price_lists.messages.delete_confirm_yes'),
        'bulkDeleteConfirmTitle' => __('price_lists.messages.bulk_delete_confirm_title'),
        'bulkDeleteConfirmText' => __('price_lists.messages.bulk_delete_confirm_text'),
        'bulkDeleteConfirmYes' => __('price_lists.messages.bulk_delete_confirm_yes'),
        'restoreConfirmTitle' => __('price_lists.messages.restore_confirm_title'),
        'restoreConfirmText' => __('price_lists.messages.restore_confirm_text'),
        'restoreConfirmYes' => __('price_lists.messages.restore_confirm_yes'),
        'increaseTitle' => __('price_lists.messages.increase_title'),
        'increaseText' => __('price_lists.messages.increase_text'),
        'increasePlaceholder' => __('price_lists.messages.increase_placeholder'),
        'increaseConfirmYes' => __('price_lists.messages.increase_confirm_yes'),
        'increaseInvalid' => __('price_lists.validation.increase_percentage'),
        'increaseMaximum' => __('price_lists.validation.increase_percentage_max'),
        'cancel' => __('common.actions.cancel'),
        'unexpectedError' => __('common.messages.unexpected_error'),
    ];
@endphp

@section('content')
    <div class="card erp-datatable-card price-lists-datatable-card">
        <x-admin.crud-index-toolbar
            :title="__('price_lists.title')"
            :add-route="route('admin.sales.price-lists.create')"
            add-permission="price_lists.create"
            :add-label="__('price_lists.create')"
            :show-trash-filter="auth()->user()?->can('price_lists.view_trashed')"
            :show-bulk-actions="auth()->user()?->can('price_lists.delete')"
            trash-filter-id="price_lists_trash_filter"
            bulk-actions-class="price-lists-bulk-actions-bar"
            bulk-action-label="{{ __('price_lists.bulk_action') }}"
            toolbar-actions-class="price-lists-toolbar-actions"
        />
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="price-lists-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-price-lists-table"
                            data-url="{{ route('admin.sales.price-lists.data') }}"
                            data-bulk-delete-url="{{ route('admin.sales.price-lists.bulk-delete') }}"
                            data-table-name="price_lists">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <x-forms.input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('price_lists.select_all') }}" />
                                        </div>
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('price_lists.fields.code') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('price_lists.fields.scope') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('price_lists.fields.currency') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('price_lists.fields.date') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('price_lists.fields.valid_from') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('price_lists.fields.valid_until') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('price_lists.fields.items') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.created_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.created_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.updated_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.updated_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.deleted_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.deleted_at') }}</th>
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
        window.priceListIndexMessages = @json($messages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Sales/price-lists-index.js') }}"></script>
@endpush
