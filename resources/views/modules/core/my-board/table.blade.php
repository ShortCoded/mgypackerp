@extends('layouts.app')

@section('title', __('user_tasks.my_board_table_title'))

@php
    $taskType = \Modules\Core\Models\UserTask::TypeTask;
    $noteType = \Modules\Core\Models\UserTask::TypeNote;
    $canCreateForBoard = $boardConfig['can']['create'] ?? false;
    $canViewTrashed = $boardConfig['can']['viewTrashed'] ?? false;
    $canBulkDelete = $boardConfig['can']['delete'] ?? false;
    $canBulkActivate = $boardConfig['can']['edit'] ?? false;
    $canBulkDeactivate = $boardConfig['can']['edit'] ?? false;
    $canBulkRestore = $boardConfig['can']['restore'] ?? false;
    $hasBulkActions = $canBulkDelete || $canBulkActivate || $canBulkDeactivate || $canBulkRestore;
    $erpAsset = app(\Modules\Core\Services\AssetVersionService::class);
    $myBoardTableJsPath = 'assets/js/modules/Core/my-board-table.js';
    $tableColumns = [
        ['label' => '', 'class' => 'text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select', 'orderable' => false, 'style' => 'width: 2.25rem;'],
        ['label' => __('common.fields.document_number'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code'],
        ['label' => __('user_tasks.attributes.title'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('user_tasks.attributes.description'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('user_tasks.attributes.board_list'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('user_tasks.attributes.status'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-status'],
        ['label' => __('user_tasks.attributes.priority'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-status'],
        ['label' => __('user_tasks.attributes.color'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-status'],
        ['label' => __('user_tasks.attributes.assignees'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('user_tasks.attributes.due_at'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-date'],
        ['label' => __('user_tasks.attributes.completed_at'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-date'],
        ['label' => __('common.fields.created_by'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('common.fields.created_at'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-date'],
        ['label' => __('common.fields.updated_by'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis'],
        ['label' => __('common.fields.updated_at'), 'class' => 'text-900 sort pe-1 align-middle white-space-nowrap dt-date'],
        ['label' => '', 'class' => 'text-900 no-sort pe-1 align-middle data-table-row-action all no-colvis dt-actions', 'orderable' => false],
    ];
@endphp

@section('content')
    <div class="card erp-datatable-card my-board-table-datatable-card">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-12 col-xl-auto d-flex align-items-center pe-xl-0">
                    <h5 class="fs-9 mb-0 text-nowrap py-2 py-xl-0">{{ __('user_tasks.my_board_table_title') }}</h5>
                </div>
                <div class="col-12 col-xl ms-xl-auto ps-xl-0">
                    <div class="d-flex flex-wrap justify-content-xl-end align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-700 fs-10" for="my_board_table_record_filter">{{ __('user_tasks.records.filter_label') }}</label>
                            <x-forms.select class="form-select form-select-sm w-auto" id="my_board_table_record_filter" aria-label="{{ __('user_tasks.records.filter_label') }}">
                                <option value="active">{{ __('user_tasks.records.active') }}</option>
                                <option value="inactive">{{ __('user_tasks.records.inactive') }}</option>
                                @if ($canViewTrashed)
                                    <option value="trashed">{{ __('user_tasks.records.trashed') }}</option>
                                    <option value="all">{{ __('user_tasks.records.all') }}</option>
                                @endif
                            </x-forms.select>
                        </div>

                        @if ($hasBulkActions)
                            @foreach ([$taskType, $noteType] as $type)
                                <div class="d-none align-items-center gap-2 js-my-board-table-bulk-actions-bar" data-type="{{ $type }}">
                                    <span class="badge rounded-pill badge-subtle-primary js-my-board-table-selected-count">0</span>
                                    <x-forms.select class="form-select form-select-sm w-auto js-my-board-table-bulk-action" aria-label="{{ __('user_tasks.bulk_action') }}">
                                        @if ($canBulkDelete)
                                            <option value="delete" data-visible-filters="active inactive all">{{ __('user_tasks.actions.delete_selected') }}</option>
                                        @endif
                                        @if ($canBulkActivate)
                                            <option value="activate" data-visible-filters="inactive all">{{ __('user_tasks.actions.activate_selected') }}</option>
                                        @endif
                                        @if ($canBulkDeactivate)
                                            <option value="deactivate" data-visible-filters="active all">{{ __('user_tasks.actions.deactivate_selected') }}</option>
                                        @endif
                                        @if ($canBulkRestore)
                                            <option value="restore" data-visible-filters="trashed all">{{ __('user_tasks.actions.restore_selected') }}</option>
                                        @endif
                                    </x-forms.select>
                                    <button type="button" class="btn btn-falcon-default btn-sm js-my-board-table-bulk-apply" data-type="{{ $type }}" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                                        <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                                    </button>
                                </div>
                            @endforeach
                        @endif

                        @if ($canCreateForBoard)
                            <x-buttons.add-record
                                id="btn_add_my_board_item"
                                class="js-my-board-table-create-link"
                                data-active-type="{{ $taskType }}"
                                data-task-url="{{ route('admin.my-board.tasks.create') }}"
                                data-note-url="{{ route('admin.my-board.notes.create') }}"
                                data-task-label="{{ __('user_tasks.add_task') }}"
                                data-note-label="{{ __('user_tasks.add_note') }}"
                                data-task-icon="fas fa-plus"
                                data-note-icon="fas fa-sticky-note"
                                :href="route('admin.my-board.tasks.create')"
                                :permission="'my_board.create'"
                                :label="__('user_tasks.add_task')"
                            />
                        @endif
                    </div>
                </div>
            </div>

            <ul class="nav nav-tabs border-bottom-0 mt-3" id="myBoardTableTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="my-board-table-tasks-tab" data-bs-toggle="tab" data-bs-target="#my-board-table-tasks-pane" type="button" role="tab" aria-controls="my-board-table-tasks-pane" aria-selected="true" data-type="{{ $taskType }}">
                        <span class="fas fa-tasks me-1"></span>{{ __('user_tasks.tabs.tasks') }}
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="my-board-table-notes-tab" data-bs-toggle="tab" data-bs-target="#my-board-table-notes-pane" type="button" role="tab" aria-controls="my-board-table-notes-pane" aria-selected="false" data-type="{{ $noteType }}">
                        <span class="fas fa-sticky-note me-1"></span>{{ __('user_tasks.tabs.notes') }}
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body p-0">
            <div class="tab-content">
                @foreach ([[$taskType, 'tasks', 'my-board-tasks-table'], [$noteType, 'notes', 'my-board-notes-table']] as [$type, $tab, $tableId])
                    <div class="tab-pane fade @if ($type === $taskType) show active @endif" id="my-board-table-{{ $tab }}-pane" role="tabpanel" aria-labelledby="my-board-table-{{ $tab }}-tab">
                        <div class="falcon-data-table">
                            <div class="erp-datatable-wrapper">
                                <div class="erp-datatable-scroll">
                                    <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-my-board-table" id="{{ $tableId }}"
                                        data-type="{{ $type }}"
                                        data-url="{{ route("admin.my-board.{$tab}.datatable") }}"
                                        data-bulk-delete-url="{{ route('admin.my-board.table.bulk-delete') }}"
                                        data-bulk-active-state-url="{{ route('admin.my-board.table.bulk-active-state') }}"
                                        data-bulk-restore-url="{{ route('admin.my-board.table.bulk-restore') }}"
                                        data-table-name="user_tasks">
                                        <thead class="bg-100 text-900">
                                            <tr>
                                                @foreach ($tableColumns as $column)
                                                    <th class="{{ $column['class'] }}" @if (($column['orderable'] ?? true) === false) data-orderable="false" @endif @if (! empty($column['style'])) style="{{ $column['style'] }}" @endif>
                                                        @if ($loop->first)
                                                            <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                                                <x-forms.input class="form-check-input js-record-select-all js-my-board-table-select-all" type="checkbox" data-type="{{ $type }}" aria-label="{{ __('user_tasks.select_all') }}" />
                                                            </div>
                                                        @else
                                                            {{ $column['label'] }}
                                                        @endif
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @include('modules.core.my-board.partials.status-modal')
@endsection

@push('scripts')
    <script>
        window.MyBoardTableConfig = @json($boardConfig);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ $erpAsset->url($myBoardTableJsPath) }}"></script>
@endpush
