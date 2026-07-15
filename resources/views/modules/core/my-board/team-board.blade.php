@extends('layouts.app')

@section('title', __('user_tasks.team_board'))

@php
    $taskType = \Modules\Core\Models\UserTask::TypeTask;
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $erpAsset = app(\Modules\Core\Services\AssetVersionService::class);
    $teamBoardReportJsPath = 'assets/js/modules/Core/team-board-report.js';
    $canViewTasks = ($boardConfig['can']['viewAllTasks'] ?? false) === true;
    $canCreateTask = ($boardConfig['can']['create'] ?? false) === true;
    $canViewTrashed = ($boardConfig['can']['viewTrashed'] ?? false) === true;
    $canBulkDelete = ($boardConfig['can']['delete'] ?? false) === true;
    $canBulkRestore = ($boardConfig['can']['restore'] ?? false) === true;
    $hasBulkActions = $canBulkDelete || $canBulkRestore;
    $taskColumns = [
        ['label' => '', 'class' => 'text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select', 'orderable' => false, 'style' => 'width: 2.25rem;'],
        ['label' => __('common.fields.document_number'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code'],
        ['label' => __('user_tasks.attributes.title'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('user_tasks.attributes.description'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('user_tasks.attributes.assigned_to'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('user_tasks.attributes.board'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('user_tasks.attributes.status'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-status'],
        ['label' => __('user_tasks.attributes.priority'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-status'],
        ['label' => __('user_tasks.attributes.due_at'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-date'],
        ['label' => __('user_tasks.attributes.attachments'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap text-center'],
        ['label' => __('common.fields.created_by'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('common.fields.created_at'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-date'],
        ['label' => __('common.fields.updated_by'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('common.fields.updated_at'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-date'],
        ['label' => __('user_tasks.attributes.comments'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap text-center'],
        ['label' => __('user_tasks.attributes.viewers'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap text-center'],
        ['label' => '', 'class' => 'text-900 no-sort pe-1 align-middle data-table-row-action all no-colvis dt-actions', 'orderable' => false],
    ];
@endphp

@section('content')
    @if ($canViewTasks)
        <div class="card mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0">{{ __('user_tasks.filters.filter') }}</h6>
            </div>
            <div class="card-body py-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6 col-xl-2">
                        <x-forms.label for="team_board_tasks_assignee" :label="__('user_tasks.attributes.assigned_to')" />
                        <select class="form-select form-select-sm js-select2-ajax js-team-board-filter" id="team_board_tasks_assignee" data-type="{{ $taskType }}" name="assigned_user_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.filter_assignee') }}" data-allow-clear="true"></select>
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <x-forms.label for="team_board_tasks_creator" :label="__('user_tasks.attributes.creator')" />
                        <select class="form-select form-select-sm js-select2-ajax js-team-board-filter" id="team_board_tasks_creator" data-type="{{ $taskType }}" name="creator_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.filter_creator') }}" data-allow-clear="true"></select>
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <x-forms.label for="team_board_tasks_status" :label="__('user_tasks.attributes.status')" />
                        <select class="form-select form-select-sm js-team-board-filter" id="team_board_tasks_status" data-type="{{ $taskType }}" name="status">
                            <option value="">{{ __('user_tasks.filters.all') }}</option>
                            @foreach (\Modules\Core\Models\UserTask::Statuses as $status)
                                <option value="{{ $status }}">{{ __("user_tasks.statuses.{$status}") }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <x-forms.label for="team_board_tasks_list" :label="__('user_tasks.attributes.board')" />
                        <select class="form-select form-select-sm js-team-board-filter" id="team_board_tasks_list" data-type="{{ $taskType }}" name="board_list_doc_num">
                            <option value="">{{ __('user_tasks.filters.all') }}</option>
                            @foreach ($boardConfig['boards'][$taskType] ?? [] as $list)
                                <option value="{{ $list['doc_num'] }}">{{ $list['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <x-forms.label for="team_board_tasks_priority" :label="__('user_tasks.attributes.priority')" />
                        <select class="form-select form-select-sm js-team-board-filter" id="team_board_tasks_priority" data-type="{{ $taskType }}" name="priority">
                            <option value="">{{ __('user_tasks.filters.all') }}</option>
                            @foreach (\Modules\Core\Models\UserTask::Priorities as $priority)
                                <option value="{{ $priority }}">{{ __("user_tasks.priorities.{$priority}") }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <x-forms.label for="team_board_tasks_completion" :label="__('user_tasks.filters.completion')" />
                        <select class="form-select form-select-sm js-team-board-filter" id="team_board_tasks_completion" data-type="{{ $taskType }}" name="completion">
                            <option value="">{{ __('user_tasks.filters.all') }}</option>
                            <option value="open">{{ __('user_tasks.filters.open') }}</option>
                            <option value="completed">{{ __('user_tasks.filters.completed') }}</option>
                        </select>
                    </div>
                    @foreach (['due_at', 'created_at', 'updated_at'] as $dateField)
                        <div class="col-md-6 col-xl-2">
                            <x-forms.label :for="'team_board_tasks_'.$dateField.'_from'" :label="__('user_tasks.filters.'.$dateField.'_from')" />
                            <input class="form-control form-control-sm js-date-picker js-team-board-filter" id="team_board_tasks_{{ $dateField }}_from" data-type="{{ $taskType }}" name="{{ $dateField }}_from" type="text" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ $dateFormatService->dateFormat() }}" autocomplete="off" dir="ltr">
                        </div>
                        <div class="col-md-6 col-xl-2">
                            <x-forms.label :for="'team_board_tasks_'.$dateField.'_to'" :label="__('user_tasks.filters.'.$dateField.'_to')" />
                            <input class="form-control form-control-sm js-date-picker js-team-board-filter" id="team_board_tasks_{{ $dateField }}_to" data-type="{{ $taskType }}" name="{{ $dateField }}_to" type="text" data-date-format="{{ $dateFormatService->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" placeholder="{{ $dateFormatService->dateFormat() }}" autocomplete="off" dir="ltr">
                        </div>
                    @endforeach
                    <div class="col-md-auto">
                        <button class="btn btn-primary btn-sm js-team-board-apply" type="button" data-type="{{ $taskType }}">
                            <span class="fas fa-filter me-1"></span>{{ __('user_tasks.filters.apply_filters') }}
                        </button>
                    </div>
                    <div class="col-md-auto">
                        <button class="btn btn-falcon-default btn-sm js-team-board-reset" type="button" data-type="{{ $taskType }}">
                            <span class="fas fa-undo me-1"></span>{{ __('user_tasks.filters.reset') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card erp-datatable-card team-board-report-card">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col-12 col-xl-auto d-flex align-items-center pe-xl-0">
                        <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ __('user_tasks.title') }}</h5>
                    </div>
                    <div class="col-12 col-xl ms-xl-auto ps-xl-0">
                        <div class="d-flex flex-wrap justify-content-xl-end align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <label class="form-label mb-0 text-700 fs-10" for="team_board_record_filter">{{ __('user_tasks.records.filter_label') }}</label>
                                <select class="form-select form-select-sm w-auto js-team-board-record-filter" id="team_board_record_filter" aria-label="{{ __('user_tasks.records.filter_label') }}">
                                    <option value="active">{{ __('user_tasks.records.active') }}</option>
                                    <option value="inactive">{{ __('user_tasks.records.inactive') }}</option>
                                    @if ($canViewTrashed)
                                        <option value="trashed">{{ __('user_tasks.records.trashed') }}</option>
                                        <option value="all">{{ __('user_tasks.records.all') }}</option>
                                    @endif
                                </select>
                            </div>

                            @if ($hasBulkActions)
                                <div class="d-none align-items-center gap-2 js-team-board-bulk-actions-bar" data-type="{{ $taskType }}">
                                    <span class="badge rounded-pill badge-subtle-primary js-team-board-selected-count">0</span>
                                    <select class="form-select form-select-sm w-auto js-team-board-bulk-action" aria-label="{{ __('user_tasks.bulk_action') }}">
                                        @if ($canBulkDelete)
                                            <option value="delete" data-visible-filters="active inactive all">{{ __('user_tasks.actions.delete_selected') }}</option>
                                        @endif
                                        @if ($canBulkRestore)
                                            <option value="restore" data-visible-filters="trashed all">{{ __('user_tasks.actions.restore_selected') }}</option>
                                        @endif
                                    </select>
                                    <button type="button" class="btn btn-falcon-default btn-sm js-team-board-bulk-apply" data-type="{{ $taskType }}" disabled>
                                        <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                                    </button>
                                </div>
                            @endif

                            @if ($canCreateTask)
                                <x-buttons.add-record
                                    id="btn_add_team_board_task"
                                    class="js-team-board-create-link"
                                    :href="route('admin.tools.team-board.tasks.create')"
                                    :permission="'my_board.create'"
                                    :label="__('user_tasks.add_task')"
                                />
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="falcon-data-table">
                    <div class="erp-datatable-wrapper">
                        <div class="erp-datatable-scroll">
                            <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-team-board-table" id="team-board-tasks-table"
                                data-type="{{ $taskType }}"
                                data-url="{{ route('admin.tools.team-board.tasks.data') }}"
                                data-bulk-delete-url="{{ route('admin.tools.team-board.bulk-delete') }}"
                                data-bulk-restore-url="{{ route('admin.tools.team-board.bulk-restore') }}"
                                data-table-name="user_tasks">
                                <thead class="bg-100 text-900">
                                    <tr>
                                        @foreach ($taskColumns as $column)
                                            @if ($loop->first)
                                                <th class="{{ $column['class'] }}" data-orderable="false" style="{{ $column['style'] ?? '' }}">
                                                    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                                        <input class="form-check-input js-team-board-select-all" type="checkbox" data-type="{{ $taskType }}" aria-label="{{ __('user_tasks.select_all') }}">
                                                    </div>
                                                </th>
                                            @else
                                                <th class="{{ $column['class'] }}" @if (($column['orderable'] ?? true) === false) data-orderable="false" @endif @if (! empty($column['style'])) style="{{ $column['style'] }}" @endif>{{ $column['label'] }}</th>
                                            @endif
                                        @endforeach
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        window.TeamBoardReportConfig = {
            urls: {
                tasks: @json(route('admin.tools.team-board.tasks.data')),
                createTask: @json(route('admin.tools.team-board.tasks.create')),
                bulkDelete: @json(route('admin.tools.team-board.bulk-delete')),
                bulkRestore: @json(route('admin.tools.team-board.bulk-restore'))
            },
            messages: {
                deleteConfirmTitle: @json(__('user_tasks.messages.record_delete_confirm_title')),
                deleteConfirmText: @json(__('user_tasks.messages.record_delete_confirm_text')),
                deleteConfirmYes: @json(__('user_tasks.messages.record_delete_confirm_yes')),
                bulkDeleteConfirmTitle: @json(__('user_tasks.messages.bulk_delete_confirm_title')),
                bulkDeleteConfirmText: @json(__('user_tasks.messages.bulk_delete_confirm_text')),
                bulkDeleteConfirmYes: @json(__('user_tasks.messages.record_delete_confirm_yes')),
                bulkRestoreConfirmTitle: @json(__('user_tasks.messages.bulk_restore_confirm_title')),
                bulkRestoreConfirmText: @json(__('user_tasks.messages.bulk_restore_confirm_text')),
                bulkRestoreConfirmYes: @json(__('user_tasks.actions.restore_selected')),
                restoreConfirmTitle: @json(__('user_tasks.messages.restore_confirm_title')),
                restoreConfirmText: @json(__('user_tasks.messages.restore_confirm_text')),
                restoreConfirmYes: @json(__('common.actions.restore')),
                deleted: @json(__('user_tasks.messages.record_deleted')),
                restored: @json(__('user_tasks.messages.record_restored')),
                bulkDeleted: @json(__('user_tasks.messages.bulk_deleted', ['count' => ':count'])),
                bulkRestored: @json(__('user_tasks.messages.bulk_restored', ['count' => ':count'])),
                noRowsSelected: @json(__('user_tasks.messages.no_records_selected')),
                no: @json(__('common.actions.no')),
                unexpectedError: @json(__('common.messages.unexpected_error'))
            }
        };
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ $erpAsset->url($teamBoardReportJsPath) }}"></script>
@endpush
