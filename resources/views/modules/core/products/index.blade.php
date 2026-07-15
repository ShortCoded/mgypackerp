@extends('layouts.app')

@php
    $isRawMaterialsContext = $isRawMaterialsContext ?? false;
    $resourceTitle = $isRawMaterialsContext ? __('products.raw_materials.title') : __('products.title');
    $resourceSelectedLabel = $isRawMaterialsContext ? __('products.raw_materials.bulk_action') : __('products.bulk_action');
    $resourceSelectAllLabel = $isRawMaterialsContext ? __('products.raw_materials.select_all') : __('products.select_all');
    $resourceNameLabel = $isRawMaterialsContext ? __('products.raw_materials.attributes.name') : __('products.attributes.name');
    $resourceMessagesRoot = $isRawMaterialsContext ? 'products.raw_materials.messages' : 'products.messages';
    $documentNumberSettingsRoot = $isRawMaterialsContext ? 'products.raw_materials.document_number_settings' : 'products.document_number_settings';
    $permissionPrefix = $isRawMaterialsContext ? 'raw_materials' : 'products';
    $routes = $routes ?? [
        'create' => route('admin.products.create'),
        'data' => route('admin.products.data'),
        'bulk_delete' => route('admin.products.bulk-delete'),
        'document_number_settings' => route('admin.products.document-number-settings.update'),
    ];
@endphp

@section('title', $resourceTitle)

@push('styles')
    <style>
        .products-datatable-card .product-table-image {
            width: 2.5rem;
            height: 2.5rem;
        }

        .products-datatable-card .product-table-image-trigger {
            border-radius: .375rem;
            line-height: 0;
        }

        .products-datatable-card .product-table-image-trigger:hover .product-table-image,
        .products-datatable-card .product-table-image-trigger:focus-visible .product-table-image {
            border-color: var(--falcon-primary, #2c7be5) !important;
            box-shadow: 0 0 0 .125rem rgba(44, 123, 229, .18);
        }

        .products-datatable-card .product-table-image img {
            display: block;
        }

        .product-image-preview-modal-image {
            max-width: 100%;
            max-height: min(72vh, 38rem);
            object-fit: contain;
        }
    </style>
@endpush

@section('content')
    @can($permissionPrefix.'.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header py-2">
                <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#products-document-number-settings"
                    aria-expanded="false"
                    aria-controls="products-document-number-settings">
                    <span class="fw-semibold">{{ __($documentNumberSettingsRoot.'.title') }}</span>
                    <span class="fas fa-chevron-down fs-11"></span>
                </button>
            </div>
            <div class="collapse" id="products-document-number-settings">
                <div class="card-body">
                    <p class="text-700 mb-3">{{ __($documentNumberSettingsRoot.'.description') }}</p>
                    <form id="products-document-number-settings-form" action="{{ $routes['document_number_settings'] }}" method="POST" novalidate>
                        @csrf
                        @method('PUT')
                        <div data-form-alert></div>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="products-document-prefix">{{ __($documentNumberSettingsRoot.'.prefix') }}</label>
                                <input class="form-control" id="products-document-prefix" name="prefix" type="text" maxlength="20" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback d-block" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label" for="products-document-padding">{{ __($documentNumberSettingsRoot.'.padding') }}</label>
                                <input class="form-control" id="products-document-padding" name="padding" type="number" min="0" max="10" step="1" value="{{ $documentNumberSettings['padding'] ?? 5 }}">
                                <div class="invalid-feedback d-block" data-error-for="padding"></div>
                            </div>
                            <div class="col-md-auto">
                                <button class="btn btn-falcon-primary" type="submit">
                                    <span class="fas fa-save me-1"></span>{{ __($documentNumberSettingsRoot.'.save') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <div class="card erp-datatable-card products-datatable-card">
        <x-admin.crud-index-toolbar
            :title="$resourceTitle"
            :add-route="$routes['create']"
            :add-permission="$permissionPrefix.'.create'"
            :show-trash-filter="auth()->user()?->can($permissionPrefix.'.view_trashed')"
            :show-bulk-actions="auth()->user()?->can($permissionPrefix.'.delete')"
            trash-filter-id="products_trash_filter"
            bulk-actions-class="products-bulk-actions-bar"
            :bulk-action-label="$resourceSelectedLabel"
            toolbar-actions-class="products-toolbar-actions"
        />
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle" id="products-table"
                            data-url="{{ $routes['data'] }}"
                            data-ajax-url="{{ $routes['data'] }}"
                            data-bulk-delete-url="{{ $routes['bulk_delete'] }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" data-searchable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ $resourceSelectAllLabel }}">
                                        </div>
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('common.fields.document_number') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle white-space-nowrap text-center" data-orderable="false" data-searchable="false">{{ __('products.attributes.image') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ $resourceNameLabel }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-code">{{ __('products.attributes.barcode') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('products.attributes.item_classification') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap text-end">{{ __('products.attributes.reorder_point') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('products.attributes.unit') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('products.attributes.category') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('products.attributes.group') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('products.attributes.color') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('products.attributes.decal') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('products.attributes.origin_country') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('products.attributes.cost_as_inventory') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('products.attributes.is_displayable') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('products.attributes.status') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.created_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.created_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.updated_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.updated_at') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle data-table-row-action all no-colvis dt-actions" data-orderable="false" data-searchable="false"></th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="product-image-preview-modal" tabindex="-1" aria-labelledby="product-image-preview-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="product-image-preview-modal-title">{{ __('products.image.preview_title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="py-3 text-center">
                        <img class="img-fluid rounded-2 shadow-sm product-image-preview-modal-image js-product-image-preview-image d-none" src="" alt="">
                        <div class="js-product-image-preview-fallback d-none text-600 py-5">
                            <span class="fas fa-image text-400 fs-4" aria-hidden="true"></span>
                            <div class="mt-2">{{ __('products.image.no_file_selected') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @php
        $productMessages = [
            'deleteConfirmTitle' => __($resourceMessagesRoot . '.delete_confirm_title'),
            'deleteConfirmText' => __($resourceMessagesRoot . '.delete_confirm_text'),
            'deleteConfirmYes' => __($resourceMessagesRoot . '.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __($resourceMessagesRoot . '.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __($resourceMessagesRoot . '.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __($resourceMessagesRoot . '.bulk_delete_confirm_yes'),
            'deleted' => __($resourceMessagesRoot . '.deleted'),
            'bulkDeleted' => __($resourceMessagesRoot . '.bulk_deleted', ['count' => 0]),
            'settingsSaved' => __($documentNumberSettingsRoot.'.updated_successfully'),
            'noRecordsSelected' => __($resourceMessagesRoot . '.no_records_selected'),
            'noChanges' => __('common.messages.no_changes'),
            'saved' => __('common.messages.saved_successfully'),
            'validationFailed' => __('common.messages.validation_failed'),
            'validationSummaryTitle' => __('products.validation.summary_title'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'cancel' => __('common.actions.cancel'),
            'confirm' => __('common.actions.confirm'),
            'yes' => __('common.actions.yes'),
            'no' => __('common.actions.no'),
            'restore' => __('products.trash.restore'),
            'restoreConfirmTitle' => __('products.trash.restore_confirm_title'),
            'restoreConfirmText' => __('products.trash.restore_confirm_text'),
            'restoreConfirmYes' => __('products.trash.restore_confirm_yes'),
            'imagePreviewTitle' => __('products.image.preview_title'),
            'noImage' => __('products.image.no_file_selected'),
        ];
    @endphp
    <script>
        window.coreProductsMessages = @json($productMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/products.js') }}"></script>
@endpush
