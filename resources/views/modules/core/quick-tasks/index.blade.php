@extends('layouts.app')

@php
    use Modules\Core\Models\QuickTask;
    use Modules\Core\Services\DateFormatService;
    use Modules\Core\Services\AssetVersionService;

    $dateFormatService = app(DateFormatService::class);
    $erpAsset = app(AssetVersionService::class);
    $canBulkDelete = auth()->user()?->can('quick_tasks.delete');
    $canBulkRestore = auth()->user()?->can('quick_tasks.restore');
    $hasBulkActions = $canBulkDelete || $canBulkRestore;
@endphp

@section('title', __('quick_tasks.management_title'))

@push('styles')
    <link href="{{ $erpAsset->url('assets/css/modules/Core/quick-tasks.css') }}" rel="stylesheet">
@endpush

@section('content')
    <div class="card mb-3 quick-tasks-filter-card">
        <div class="card-header py-2">
            <button class="btn btn-link text-decoration-none p-0 w-100 text-start d-flex align-items-center justify-content-between" type="button" data-bs-toggle="collapse" data-bs-target="#quick-tasks-filters" aria-expanded="false" aria-controls="quick-tasks-filters">
                <span class="fw-semibold">{{ __('quick_tasks.sections.filters') }}</span>
                <span class="fas fa-chevron-down fs-11"></span>
            </button>
        </div>
        <div class="collapse" id="quick-tasks-filters">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="quick_tasks_status_filter">{{ __('quick_tasks.attributes.status') }}</label>
                        <x-forms.select class="form-select form-select-sm js-quick-task-filter" id="quick_tasks_status_filter" name="status_filter">
                            <option value="">{{ __('quick_tasks.filters.all_statuses') }}</option>
                            @foreach (QuickTask::Statuses as $status)
                                <option value="{{ $status }}">{{ __("quick_tasks.statuses.{$status}") }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="quick_tasks_priority_filter">{{ __('quick_tasks.attributes.priority') }}</label>
                        <x-forms.select class="form-select form-select-sm js-quick-task-filter" id="quick_tasks_priority_filter" name="priority_filter">
                            <option value="">{{ __('quick_tasks.filters.all_priorities') }}</option>
                            @foreach (QuickTask::Priorities as $priority)
                                <option value="{{ $priority }}">{{ __("quick_tasks.priorities.{$priority}") }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="quick_tasks_assigned_filter">{{ __('quick_tasks.filters.assigned_to') }}</label>
                        <x-forms.select class="form-select form-select-sm js-select2-ajax js-quick-task-filter" id="quick_tasks_assigned_filter" name="assigned_user_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('quick_tasks.placeholders.assigned_to') }}" data-allow-clear="true"></x-forms.select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="quick_tasks_created_from">{{ __('quick_tasks.filters.created_from') }}</label>
                        <x-forms.date-input class="form-control form-control-sm js-date-picker js-quick-task-filter" id="quick_tasks_created_from" name="created_from" type="text" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ $dateFormatService->dateFormat() }}" autocomplete="off" dir="ltr" />
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="quick_tasks_created_to">{{ __('quick_tasks.filters.created_to') }}</label>
                        <x-forms.date-input class="form-control form-control-sm js-date-picker js-quick-task-filter" id="quick_tasks_created_to" name="created_to" type="text" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ $dateFormatService->dateFormat() }}" autocomplete="off" dir="ltr" />
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-falcon-default btn-sm js-quick-task-reset-filters" type="button">
                            <span class="fas fa-undo me-1"></span>{{ __('common.actions.reset') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card erp-datatable-card quick-tasks-datatable-card">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-12 col-lg-auto">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ __('quick_tasks.management_title') }}</h5>
                </div>
                <div class="col-12 col-lg-auto ms-lg-auto d-flex flex-wrap justify-content-lg-end align-items-center gap-2 quick-tasks-toolbar-actions">
                    <div class="d-flex align-items-center gap-2">
                        <label class="form-label mb-0 text-700 fs-10" for="quick_tasks_record_filter">{{ __('quick_tasks.filters.records') }}</label>
                        <x-forms.select class="form-select form-select-sm w-auto js-quick-task-filter" id="quick_tasks_record_filter" name="trash_filter" aria-label="{{ __('quick_tasks.filters.records') }}">
                            <option value="active">{{ __('quick_tasks.filters.active') }}</option>
                            @can('quick_tasks.restore')
                                <option value="trashed">{{ __('quick_tasks.filters.trashed') }}</option>
                                <option value="all">{{ __('quick_tasks.filters.all') }}</option>
                            @endcan
                        </x-forms.select>
                    </div>
                    @if ($hasBulkActions)
                        <div class="d-none align-items-center gap-2 quick-tasks-bulk-actions-bar" id="bulk_actions_bar">
                            <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                            <x-forms.select class="form-select form-select-sm w-auto" id="bulk_action_select" aria-label="{{ __('quick_tasks.bulk_action') }}">
                                @if ($canBulkDelete)
                                    <option value="delete" data-visible-filters="active all">{{ __('quick_tasks.actions.delete_selected') }}</option>
                                @endif
                                @if ($canBulkRestore)
                                    <option value="restore" data-visible-filters="trashed all">{{ __('quick_tasks.actions.restore_selected') }}</option>
                                @endif
                            </x-forms.select>
                            <button class="btn btn-falcon-default btn-sm" type="button" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                            </button>
                        </div>
                    @endif
                    @can('task_boards.view')
                        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.task-boards.index') }}">
                            <span class="fas fa-columns me-1"></span>{{ __('task_boards.title') }}
                        </a>
                    @endcan
                    <x-buttons.add-record :href="route('admin.quick-tasks.create')" permission="quick_tasks.create" :label="__('quick_tasks.create')" />
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle" id="quick-tasks-table"
                            data-ajax-url="{{ route('admin.quick-tasks.data') }}"
                            data-bulk-delete-url="{{ route('admin.quick-tasks.bulk-delete') }}"
                            data-bulk-restore-url="{{ route('admin.quick-tasks.bulk-restore') }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" data-searchable="false" style="width: 2.25rem;">
                                        @if ($hasBulkActions)
                                            <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                                <x-forms.input class="form-check-input js-record-select-all" type="checkbox" id="select_all_records" aria-label="{{ __('quick_tasks.select_all') }}" />
                                            </div>
                                        @endif
                                    </th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('quick_tasks.attributes.doc_num') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('quick_tasks.attributes.title') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('quick_tasks.attributes.summary') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('quick_tasks.attributes.task_board') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('quick_tasks.attributes.status') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('quick_tasks.attributes.priority') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('quick_tasks.attributes.assigned_to') }}</th>
                                    <th class="text-900 no-sort pe-1 align-middle white-space-nowrap text-center" data-orderable="false" data-searchable="false">{{ __('quick_tasks.attributes.attachments_count') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('quick_tasks.attributes.created_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('quick_tasks.attributes.created_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('quick_tasks.attributes.updated_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('quick_tasks.attributes.updated_at') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('quick_tasks.attributes.deleted_by') }}</th>
                                    <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('quick_tasks.attributes.deleted_at') }}</th>
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
        $quickTaskMessages = [
            'deleteConfirmTitle' => __('quick_tasks.messages.delete_confirm_title'),
            'deleteConfirmText' => __('quick_tasks.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('quick_tasks.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('quick_tasks.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('quick_tasks.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('quick_tasks.messages.bulk_delete_confirm_yes'),
            'bulkRestoreConfirmTitle' => __('quick_tasks.messages.bulk_restore_confirm_title'),
            'bulkRestoreConfirmText' => __('quick_tasks.messages.bulk_restore_confirm_text'),
            'bulkRestoreConfirmYes' => __('quick_tasks.messages.bulk_restore_confirm_yes'),
            'restoreConfirmTitle' => __('quick_tasks.messages.restore_confirm_title'),
            'restoreConfirmText' => __('quick_tasks.messages.restore_confirm_text'),
            'restoreConfirmYes' => __('quick_tasks.messages.restore_confirm_yes'),
            'statusDoneConfirmTitle' => __('quick_tasks.messages.status_done_confirm_title'),
            'statusDoneConfirmText' => __('quick_tasks.messages.status_done_confirm_text'),
            'statusDoneConfirmYes' => __('quick_tasks.messages.status_done_confirm_yes'),
            'statusCancelConfirmTitle' => __('quick_tasks.messages.status_cancel_confirm_title'),
            'statusCancelConfirmText' => __('quick_tasks.messages.status_cancel_confirm_text'),
            'statusCancelConfirmYes' => __('quick_tasks.messages.status_cancel_confirm_yes'),
            'attachmentDeleteConfirmTitle' => __('quick_tasks.messages.attachment_delete_confirm_title'),
            'attachmentDeleteConfirmText' => __('quick_tasks.messages.attachment_delete_confirm_text'),
            'attachmentDeleteConfirmYes' => __('quick_tasks.messages.attachment_delete_confirm_yes'),
            'deleted' => __('quick_tasks.messages.deleted'),
            'bulkDeleted' => __('quick_tasks.messages.bulk_deleted', ['count' => 0]),
            'saved' => __('common.messages.saved_successfully'),
            'noChanges' => __('common.messages.no_changes'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'noRecordsSelected' => __('quick_tasks.messages.no_records_selected'),
            'boardRefreshFailed' => __('quick_tasks.messages.board_refresh_failed'),
            'cancel' => __('common.actions.cancel'),
            'no' => __('common.actions.no'),
            'confirm' => __('common.actions.confirm'),
            'yes' => __('common.actions.yes'),
            'displayMode' => __('quick_tasks.actions.display_mode'),
            'exitDisplayMode' => __('quick_tasks.actions.exit_display_mode'),
        ];
    @endphp
    <script>
        window.coreQuickTasksMessages = @json($quickTaskMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ $erpAsset->url('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/quick-tasks.js') }}"></script>
@endpush
