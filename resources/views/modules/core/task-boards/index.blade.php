@extends('layouts.app')

@php
    use Modules\Core\Services\AssetVersionService;

    $erpAsset = app(AssetVersionService::class);
    $canBulkDelete = auth()->user()?->can('task_boards.delete');
    $canBulkActivate = auth()->user()?->can('task_boards.bulk_activate');
    $canBulkDeactivate = auth()->user()?->can('task_boards.bulk_deactivate');
    $canBulkRestore = auth()->user()?->can('task_boards.restore');
    $hasBulkActions = $canBulkDelete || $canBulkActivate || $canBulkDeactivate || $canBulkRestore;
@endphp

@section('title', __('task_boards.management_title'))

@section('content')
    <div class="card erp-datatable-card task-boards-datatable-card">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-12 col-lg-auto d-flex align-items-center pe-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ __('task_boards.management_title') }}</h5>
                </div>
                <div class="col-12 col-lg-auto ms-lg-auto d-flex flex-wrap justify-content-lg-end align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <label class="form-label mb-0 text-700 fs-10" for="task_boards_record_filter">{{ __('task_boards.records.filter_label') }}</label>
                        <select class="form-select form-select-sm w-auto" id="task_boards_record_filter" aria-label="{{ __('task_boards.records.filter_label') }}">
                            <option value="active">{{ __('task_boards.records.active') }}</option>
                            <option value="inactive">{{ __('task_boards.records.inactive') }}</option>
                            @can('task_boards.view_trashed')
                                <option value="trashed">{{ __('task_boards.records.trashed') }}</option>
                                <option value="all">{{ __('task_boards.records.all') }}</option>
                            @endcan
                        </select>
                    </div>

                    @if ($hasBulkActions)
                        <div class="d-none align-items-center gap-2 task-boards-bulk-actions-bar" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('task_boards.bulk_action') }}">
                                @if ($canBulkDelete)
                                    <option value="delete" data-visible-filters="active inactive all">{{ __('task_boards.actions.delete_selected') }}</option>
                                @endif
                                @if ($canBulkActivate)
                                    <option value="activate" data-visible-filters="inactive all">{{ __('task_boards.actions.activate_selected') }}</option>
                                @endif
                                @if ($canBulkDeactivate)
                                    <option value="deactivate" data-visible-filters="active all">{{ __('task_boards.actions.deactivate_selected') }}</option>
                                @endif
                                @if ($canBulkRestore)
                                    <option value="restore" data-visible-filters="trashed all">{{ __('task_boards.actions.restore_selected') }}</option>
                                @endif
                            </select>
                            <button type="button" class="btn btn-falcon-default btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                            </button>
                        </div>
                    @endif

                    <x-buttons.add-record :href="route('admin.task-boards.create')" permission="task_boards.create" :label="__('task_boards.create')" />
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle" id="task-boards-table"
                            data-ajax-url="{{ route('admin.task-boards.data') }}"
                            data-bulk-delete-url="{{ route('admin.task-boards.bulk-delete') }}"
                            data-bulk-activate-url="{{ route('admin.task-boards.bulk-activate') }}"
                            data-bulk-deactivate-url="{{ route('admin.task-boards.bulk-deactivate') }}"
                            data-bulk-restore-url="{{ route('admin.task-boards.bulk-restore') }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" data-searchable="false" style="width: 2.25rem;">
                                        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                            <input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('task_boards.select_all') }}">
                                        </div>
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('task_boards.attributes.doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('task_boards.attributes.name') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('task_boards.attributes.description') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle white-space-nowrap" data-orderable="false" data-searchable="false">{{ __('task_boards.attributes.public_status') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle white-space-nowrap" data-orderable="false" data-searchable="false">{{ __('task_boards.attributes.access_code_status') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle white-space-nowrap" data-orderable="false" data-searchable="false">{{ __('task_boards.attributes.assignments') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle white-space-nowrap text-center" data-orderable="false" data-searchable="false">{{ __('task_boards.attributes.tasks_count') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-status">{{ __('task_boards.attributes.operational_status') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.created_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.created_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.updated_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('task_boards.attributes.updated_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.deleted_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.deleted_at') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle data-table-row-action all no-colvis dt-actions" data-orderable="false" data-searchable="false"></th>
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
    @php
        $taskBoardMessages = [
            'deleteConfirmTitle' => __('task_boards.messages.delete_confirm_title'),
            'deleteConfirmText' => __('task_boards.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('task_boards.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('task_boards.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('task_boards.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('task_boards.messages.bulk_delete_confirm_yes'),
            'bulkActivateConfirmTitle' => __('task_boards.messages.bulk_activate_confirm_title'),
            'bulkActivateConfirmText' => __('task_boards.messages.bulk_activate_confirm_text'),
            'bulkActivateConfirmYes' => __('task_boards.messages.bulk_activate_confirm_yes'),
            'bulkDeactivateConfirmTitle' => __('task_boards.messages.bulk_deactivate_confirm_title'),
            'bulkDeactivateConfirmText' => __('task_boards.messages.bulk_deactivate_confirm_text'),
            'bulkDeactivateConfirmYes' => __('task_boards.messages.bulk_deactivate_confirm_yes'),
            'bulkRestoreConfirmTitle' => __('task_boards.messages.bulk_restore_confirm_title'),
            'bulkRestoreConfirmText' => __('task_boards.messages.bulk_restore_confirm_text'),
            'bulkRestoreConfirmYes' => __('task_boards.messages.bulk_restore_confirm_yes'),
            'restoreConfirmTitle' => __('task_boards.messages.restore_confirm_title'),
            'restoreConfirmText' => __('task_boards.messages.restore_confirm_text'),
            'restoreConfirmYes' => __('task_boards.messages.restore_confirm_yes'),
            'regenerateConfirmTitle' => __('task_boards.messages.regenerate_confirm_title'),
            'regenerateConfirmText' => __('task_boards.messages.regenerate_confirm_text'),
            'regenerateConfirmYes' => __('task_boards.messages.regenerate_confirm_yes'),
            'deleted' => __('task_boards.messages.deleted'),
            'noRowsSelected' => __('task_boards.messages.no_rows_selected'),
            'saved' => __('common.messages.saved_successfully'),
            'noChanges' => __('common.messages.no_changes'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'urlCopied' => __('task_boards.messages.url_copied'),
            'copyFailed' => __('task_boards.messages.copy_failed'),
            'cancel' => __('common.actions.cancel'),
            'confirm' => __('common.actions.confirm'),
        ];
    @endphp
    <script>
        window.coreTaskBoardsMessages = @json($taskBoardMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ $erpAsset->url('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/task-boards.js') }}"></script>
@endpush
