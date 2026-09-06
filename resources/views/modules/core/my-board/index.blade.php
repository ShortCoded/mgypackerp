@extends('layouts.app')

@section('title', ($boardConfig['mode'] ?? 'personal') === 'team' ? __('user_tasks.team_board') : __('user_tasks.my_board'))

@php
    $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
    $isTeamBoardScreen = ($boardConfig['mode'] ?? 'personal') === 'team';
    $pageTitle = $isTeamBoardScreen ? __('user_tasks.team_board') : __('user_tasks.my_board');
    $canCreateForBoard = ($boardConfig['can']['create'] ?? false)
        && ($boardUser->is(auth()->user()) || ($boardConfig['can']['assign'] ?? false) || ($boardConfig['can']['manageAny'] ?? false));
    $myBoardJsPath = 'assets/js/modules/Core/my-board.js';
@endphp

@push('styles')
    <link href="{{ asset('vendors/summernote/summernote-bs5.min.css') }}" rel="stylesheet">
    <style>
        .my-board-shell .kanban-container { min-height: 24rem; }
        .my-board-shell .kanban-column { min-width: 18rem; max-width: 18rem; }
        .my-board-shell .kanban-items-container { min-height: 8rem; }
        .my-board-card { cursor: pointer; border-inline-start: .1875rem solid var(--falcon-primary); }
        .my-board-card .card-body { padding: .625rem .7rem; }
        .my-board-card-title {
            display: -webkit-box;
            overflow: hidden;
            margin-bottom: 0;
            font-size: .78rem;
            font-weight: 600;
            line-height: 1.25;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }
        .my-board-card-description {
            display: -webkit-box;
            overflow: hidden;
            margin-top: .15rem;
            font-size: .68rem;
            line-height: 1.25;
            color: var(--falcon-gray-600);
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }
        .my-board-card-badges,
        .my-board-card-meta { gap: .25rem; }
        .my-board-card-badges { margin-top: .4rem; }
        .my-board-card .badge {
            padding: .14rem .35rem;
            border-radius: .25rem;
            font-size: .58rem;
            font-weight: 500;
            line-height: 1;
        }
        .my-board-card-footer { margin-top: .45rem; gap: .5rem; }
        .my-board-card .avatar { width: 1.25rem; height: 1.25rem; font-size: .62rem; }
        .my-board-card .avatar-name span { font-size: .62rem; }
        .my-board-card-user-indicator { flex: 0 0 auto; }
        .my-board-card-actions .btn { line-height: 1; }
        .my-board-card .dropdown-toggle::after { display: none; }
        .my-board-empty { border: 1px dashed var(--falcon-border-color); }
        .my-board-avatar-stack .avatar { margin-inline-start: -.35rem; }
        .my-board-avatar-stack .avatar:first-child { margin-inline-start: 0; }
        .my-board-comment { border-inline-start: .1875rem solid var(--falcon-border-color); }
        .my-board-rich-content img { max-width: 100%; height: auto; border-radius: .375rem; }
        .my-board-rich-content p:last-child { margin-bottom: 0; }
        .note-editor.note-frame { border-color: var(--falcon-border-color); }
    </style>
@endpush

@section('content')
    <div class="my-board-shell">
        <div class="mb-3 card">
            <div class="py-3 card-body">
                <div class="row flex-between-center g-3">
                    <div class="col-md-auto">
                        <h4 class="mb-1">{{ $pageTitle }}</h4>
                        @if ($isTeamBoardScreen)
                            <p class="mb-0 fs-10 text-600">{{ __('user_tasks.team_board_subtitle') }}</p>
                        @elseif ($boardUser->isNot(auth()->user()))
                            <p class="mb-0 fs-10 text-600">
                                {{ __('user_tasks.viewing_board_of') }}:
                                <span class="fw-semibold">{{ $boardUser->name }}</span>
                            </p>
                        @else
                            <p class="mb-0 fs-10 text-600">{{ __('user_tasks.board_subtitle') }}</p>
                        @endif
                    </div>
                    @if (! $isTeamBoardScreen)
                        <div class="col-md">
                            <div class="row g-2 justify-content-md-end align-items-center">
                                @if (($boardConfig['can']['viewAny'] ?? false) === true)
                                    <div class="col-md-5 col-xl-4">
                                        <select class="form-select form-select-sm js-select2-ajax js-board-user-selector"
                                            data-url="{{ route('admin.select2.users') }}"
                                            data-placeholder="{{ __('user_tasks.placeholders.board_user') }}"
                                            data-allow-clear="false">
                                            <option value="{{ $boardUser->doc_num }}" selected>{{ trim(implode(' / ', array_filter([$boardUser->name, $boardUser->doc_num]))) }}</option>
                                        </select>
                                    </div>
                                @endif
                                <div class="col-md-auto">
                                    <div class="btn-group btn-group-sm" role="group">
                                        @if ($canCreateForBoard)
                                            <button class="btn btn-primary js-board-add" type="button" data-board-type="task" title="Alt+T" data-bs-title="Alt+T">
                                                <span class="fas fa-plus me-1"></span>{{ __('user_tasks.add_task') }}
                                            </button>
                                            <button class="btn btn-falcon-default js-board-add" type="button" data-board-type="note" title="Alt+M" data-bs-title="Alt+M">
                                                <span class="fas fa-sticky-note me-1"></span>{{ __('user_tasks.add_note') }}
                                            </button>
                                        @endif
                                        @if (($boardConfig['can']['listCreate'] ?? false) === true)
                                            <button class="btn btn-falcon-default js-board-add-list" type="button" title="Alt+L" data-bs-title="Alt+L">
                                                <span class="fas fa-columns me-1"></span>{{ __('user_tasks.add_list') }}
                                            </button>
                                        @endif
                                        <button class="btn btn-falcon-default js-board-refresh" type="button">
                                            <span class="fas fa-sync-alt me-1"></span>{{ __('user_tasks.actions.refresh') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        @if (! $isTeamBoardScreen)
            <ul class="pb-2 nav nav-tabs border-bottom-0" id="myBoardTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tasks-board-tab" data-bs-toggle="tab" data-bs-target="#tasks-board-pane" type="button" role="tab" aria-controls="tasks-board-pane" aria-selected="true">
                        <span class="fas fa-tasks me-1"></span>{{ __('user_tasks.tasks_board') }}
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="notes-board-tab" data-bs-toggle="tab" data-bs-target="#notes-board-pane" type="button" role="tab" aria-controls="notes-board-pane" aria-selected="false">
                        <span class="fas fa-sticky-note me-1"></span>{{ __('user_tasks.notes_board') }}
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="tasks-board-pane" role="tabpanel" aria-labelledby="tasks-board-tab">
                    <div class="pb-3 kanban-container scrollbar js-board-container" data-board-type="task"></div>
                </div>
                <div class="tab-pane fade" id="notes-board-pane" role="tabpanel" aria-labelledby="notes-board-tab">
                    <div class="pb-3 kanban-container scrollbar js-board-container" data-board-type="note"></div>
                </div>
            </div>
        @else
            <div class="my-board-team-screen">
                <div class="mb-3 card">
                    <div class="py-3 card-body">
                        <ul class="nav nav-pills mb-3" id="teamBoardTabs" role="tablist">
                            @if (($boardConfig['can']['viewAllTasks'] ?? false) === true)
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="team-all-tasks-tab" data-bs-toggle="tab" data-bs-target="#team-all-tasks-pane" type="button" role="tab" aria-controls="team-all-tasks-pane" aria-selected="true">
                                        <span class="fas fa-list me-1"></span>{{ __('user_tasks.all_tasks') }}
                                    </button>
                                </li>
                            @endif
                            @if (($boardConfig['can']['viewAllNotes'] ?? false) === true)
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link @if (($boardConfig['can']['viewAllTasks'] ?? false) !== true) active @endif" id="team-all-notes-tab" data-bs-toggle="tab" data-bs-target="#team-all-notes-pane" type="button" role="tab" aria-controls="team-all-notes-pane" aria-selected="{{ ($boardConfig['can']['viewAllTasks'] ?? false) === true ? 'false' : 'true' }}">
                                        <span class="fas fa-sticky-note me-1"></span>{{ __('user_tasks.all_notes') }}
                                    </button>
                                </li>
                            @endif
                        </ul>

                        <div class="tab-content">
                            @if (($boardConfig['can']['viewAllTasks'] ?? false) === true)
                                <div class="tab-pane fade show active" id="team-all-tasks-pane" role="tabpanel" aria-labelledby="team-all-tasks-tab">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-6 col-xl-2">
                                            <x-forms.label for="board_all_tasks_status" :label="__('user_tasks.attributes.status')" />
                                            <select class="form-select form-select-sm js-board-all-tasks-filter" id="board_all_tasks_status" name="status">
                                                <option value="">{{ __('user_tasks.filters.all') }}</option>
                                                @foreach (\Modules\Core\Models\UserTask::Statuses as $status)
                                                    <option value="{{ $status }}">{{ __("user_tasks.statuses.{$status}") }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-6 col-xl-2">
                                            <x-forms.label for="board_all_tasks_list_doc_num" :label="__('user_tasks.attributes.board_list')" />
                                            <select class="form-select form-select-sm js-board-all-tasks-filter js-board-all-tasks-list-filter" id="board_all_tasks_list_doc_num" name="board_list_doc_num">
                                                <option value="">{{ __('user_tasks.filters.all') }}</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 col-xl-2">
                                            <x-forms.label for="board_all_tasks_owner_doc_num" :label="__('user_tasks.attributes.board_owner')" />
                                            <select class="form-select form-select-sm js-select2-ajax js-board-all-tasks-filter" id="board_all_tasks_owner_doc_num" name="board_owner_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.filter_board_owner') }}" data-allow-clear="true"></select>
                                        </div>
                                        <div class="col-md-6 col-xl-2">
                                            <x-forms.label for="board_all_tasks_assigned_user_doc_num" :label="__('user_tasks.attributes.assignees')" />
                                            <select class="form-select form-select-sm js-select2-ajax js-board-all-tasks-filter" id="board_all_tasks_assigned_user_doc_num" name="assigned_user_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.filter_assignee') }}" data-allow-clear="true"></select>
                                        </div>
                                        <div class="col-md-6 col-xl-2">
                                            <x-forms.label for="board_all_tasks_creator_doc_num" :label="__('user_tasks.attributes.creator')" />
                                            <select class="form-select form-select-sm js-select2-ajax js-board-all-tasks-filter" id="board_all_tasks_creator_doc_num" name="creator_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.filter_creator') }}" data-allow-clear="true"></select>
                                        </div>
                                        <div class="col-md-6 col-xl-auto">
                                            <button class="btn btn-falcon-default btn-sm w-100 js-board-all-tasks-refresh" type="button">
                                                <span class="fas fa-sync-alt"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            @if (($boardConfig['can']['viewAllNotes'] ?? false) === true)
                                <div class="tab-pane fade @if (($boardConfig['can']['viewAllTasks'] ?? false) !== true) show active @endif" id="team-all-notes-pane" role="tabpanel" aria-labelledby="team-all-notes-tab">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-4">
                                            <x-forms.label for="board_all_notes_list_doc_num" :label="__('user_tasks.attributes.board_list')" />
                                            <select class="form-select form-select-sm js-board-all-notes-filter js-board-all-notes-list-filter" id="board_all_notes_list_doc_num" name="board_list_doc_num">
                                                <option value="">{{ __('user_tasks.filters.all') }}</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <x-forms.label for="board_all_notes_owner_doc_num" :label="__('user_tasks.attributes.board_owner')" />
                                            <select class="form-select form-select-sm js-select2-ajax js-board-all-notes-filter" id="board_all_notes_owner_doc_num" name="board_owner_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.filter_board_owner') }}" data-allow-clear="true"></select>
                                        </div>
                                        <div class="col-md-3">
                                            <x-forms.label for="board_all_notes_creator_doc_num" :label="__('user_tasks.attributes.creator')" />
                                            <select class="form-select form-select-sm js-select2-ajax js-board-all-notes-filter" id="board_all_notes_creator_doc_num" name="creator_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.filter_creator') }}" data-allow-clear="true"></select>
                                        </div>
                                        <div class="col-md-1">
                                            <button class="btn btn-falcon-default btn-sm w-100 js-board-all-notes-refresh" type="button">
                                                <span class="fas fa-sync-alt"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                @if (($boardConfig['can']['viewAllTasks'] ?? false) === true)
                    <div class="js-board-all-tasks"></div>
                @endif
                @if (($boardConfig['can']['viewAllNotes'] ?? false) === true)
                    <div class="js-board-all-notes @if (($boardConfig['can']['viewAllTasks'] ?? false) === true) d-none @endif"></div>
                @endif
            </div>
        @endif
    </div>

    <div class="modal fade" id="myBoardItemModal" tabindex="-1" aria-labelledby="myBoardItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form id="myBoardItemForm" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title js-board-modal-title" id="myBoardItemModalLabel">{{ __('user_tasks.titles.create_modal') }}</h5>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none js-board-errors"></div>
                        <input type="hidden" name="doc_num">
                        <input type="hidden" name="board_user_doc_num" value="{{ $boardUser->doc_num }}">
                        <div class="row g-3">
                            <div class="col-12">
                                <h6 class="mb-0 text-700">{{ __('user_tasks.sections.details') }}</h6>
                            </div>
                            <div class="col-12">
                                <x-forms.label for="board_title" :label="__('user_tasks.attributes.title')" required />
                                <input class="form-control" id="board_title" name="title" type="text" required>
                                <div class="invalid-feedback" data-error-for="title"></div>
                            </div>
                            <div class="col-md-4">
                                <x-forms.label for="board_type" :label="__('user_tasks.attributes.type')" required />
                                <select class="form-select" id="board_type" name="type" required>
                                    @foreach (\Modules\Core\Models\UserTask::Types as $type)
                                        <option value="{{ $type }}">{{ __("user_tasks.types.{$type}") }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="type"></div>
                            </div>
                            <div class="col-md-4">
                                <x-forms.label for="board_list_doc_num" :label="__('user_tasks.attributes.board_list')" required />
                                <select class="form-select" id="board_list_doc_num" name="board_list_doc_num" required></select>
                                <div class="invalid-feedback" data-error-for="board_list_doc_num"></div>
                            </div>
                            <div class="col-md-4">
                                <x-forms.label for="board_status" :label="__('user_tasks.attributes.status')" required />
                                <select class="form-select" id="board_status" name="status" required>
                                    @foreach (\Modules\Core\Models\UserTask::Statuses as $status)
                                        <option value="{{ $status }}">{{ __("user_tasks.statuses.{$status}") }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="status"></div>
                            </div>
                            <div class="col-md-4 js-board-task-field">
                                <x-forms.label for="board_priority" :label="__('user_tasks.attributes.priority')" required />
                                <select class="form-select" id="board_priority" name="priority" required>
                                    @foreach (\Modules\Core\Models\UserTask::Priorities as $priority)
                                        <option value="{{ $priority }}">{{ __("user_tasks.priorities.{$priority}") }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="priority"></div>
                            </div>
                            <div class="col-md-4 js-board-task-field">
                                <x-forms.label for="board_due_at" :label="__('user_tasks.attributes.due_at')" />
                                <input class="form-control js-date-picker" id="board_due_at" name="due_at" type="text" data-enable-time="true" data-date-format="{{ $dateFormatService->jsDateTimeFormat() }}" dir="ltr">
                                <div class="invalid-feedback" data-error-for="due_at"></div>
                            </div>
                            @if (($boardConfig['can']['assign'] ?? false) === true)
                                <div class="col-md-4 js-board-task-field">
                                    <x-forms.label for="board_assignee_doc_nums" :label="__('user_tasks.attributes.assignees')" />
                                    <select class="form-select js-select2-ajax" id="board_assignee_doc_nums" name="assignee_doc_nums[]" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('user_tasks.placeholders.assignees') }}" data-allow-clear="true" multiple></select>
                                    <div class="invalid-feedback d-block" data-error-for="assignee_doc_nums"></div>
                                </div>
                            @endif
                            <div class="col-md-4">
                                <x-forms.label for="board_color" :label="__('user_tasks.attributes.color')" />
                                <select class="form-select" id="board_color" name="color">
                                    <option value="">{{ __('common.empty_value') }}</option>
                                    @foreach (\Modules\Core\Models\UserTask::Colors as $color)
                                        <option value="{{ $color }}">{{ __("user_tasks.colors.{$color}") }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="color"></div>
                            </div>
                            <div class="col-12">
                                <x-forms.label for="board_description" :label="__('user_tasks.attributes.description')" />
                                <textarea class="form-control js-board-rich-editor" id="board_description" name="description" rows="6" placeholder="{{ __('user_tasks.placeholders.description') }}"></textarea>
                                <div class="invalid-feedback" data-error-for="description"></div>
                            </div>
                            <div class="col-12 d-none js-board-edit-comments-section">
                                <div class="pt-3 border-top">
                                    <h6 class="text-700">{{ __('user_tasks.attributes.comments') }}</h6>
                                    <div class="js-board-edit-comments-list"></div>
                                    @if (($boardConfig['can']['commentCreate'] ?? false) === true)
                                        <div class="mt-3">
                                            <div class="alert alert-danger d-none js-board-edit-comment-errors"></div>
                                            <textarea class="form-control js-board-edit-comment-editor" id="board_edit_comment_body" name="edit_body_html" rows="4" placeholder="{{ __('user_tasks.placeholders.comment') }}"></textarea>
                                            <div class="invalid-feedback d-block" data-error-for="body_html"></div>
                                            <div class="mt-2 text-end">
                                                <button class="btn btn-primary btn-sm js-board-edit-comment-save" type="button">
                                                    <span class="fas fa-comment me-1"></span>{{ __('user_tasks.actions.save_comment') }}
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-falcon-danger me-auto d-none js-board-delete" type="button">
                            <span class="fas fa-trash-alt me-1"></span><span class="js-board-delete-label">{{ __('user_tasks.actions.delete_task') }}</span>
                        </button>
                        <button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button class="btn btn-primary js-board-save" type="submit">{{ __('user_tasks.actions.save_task') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="myBoardDetailsModal" tabindex="-1" aria-labelledby="myBoardDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title js-board-details-title" id="myBoardDetailsModalLabel">{{ __('user_tasks.details') }}</h5>
                        <div class="fs-10 text-600 js-board-details-meta"></div>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 row g-3 js-board-details-grid"></div>
                    <div class="pt-3 border-top">
                        <h6 class="text-700">{{ __('user_tasks.attributes.description') }}</h6>
                        <div class="my-board-rich-content js-board-details-description text-800"></div>
                    </div>
                    <div class="pt-3 mt-3 border-top">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 text-700">{{ __('user_tasks.attributes.viewers') }}</h6>
                            <span class="badge badge-subtle-secondary js-board-details-view-count d-none"></span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 js-board-details-viewers"></div>
                    </div>
                    <div class="pt-3 mt-3 border-top">
                        <h6 class="text-700">{{ __('user_tasks.attributes.comments') }}</h6>
                        <div class="js-board-comments-list"></div>
                        @if (($boardConfig['can']['commentCreate'] ?? false) === true)
                            <form class="mt-3 js-board-comment-form" novalidate>
                                <div class="alert alert-danger d-none js-board-comment-errors"></div>
                                <textarea class="form-control js-board-comment-editor" id="board_comment_body" name="body_html" rows="4" placeholder="{{ __('user_tasks.placeholders.comment') }}"></textarea>
                                <div class="invalid-feedback d-block" data-error-for="body_html"></div>
                                <div class="mt-2 text-end">
                                    <button class="btn btn-primary btn-sm" type="submit">
                                        <span class="fas fa-comment me-1"></span>{{ __('user_tasks.actions.save_comment') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-falcon-danger me-auto d-none js-board-details-delete" type="button">
                        <span class="fas fa-trash-alt me-1"></span>{{ __('user_tasks.actions.delete') }}
                    </button>
                    <button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal">{{ __('common.actions.close') }}</button>
                    <button class="btn btn-primary d-none js-board-details-edit" type="button">
                        <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="myBoardListModal" tabindex="-1" aria-labelledby="myBoardListModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="myBoardListForm" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title" id="myBoardListModalLabel">{{ __('user_tasks.titles.list_modal') }}</h5>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none js-board-list-errors"></div>
                        <input type="hidden" name="doc_num">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <x-forms.label for="board_list_type" :label="__('user_tasks.attributes.type')" required />
                                <select class="form-select" id="board_list_type" name="type" required>
                                    @foreach (\Modules\Core\Models\UserTask::Types as $type)
                                        <option value="{{ $type }}">{{ __("user_tasks.types.{$type}") }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="type"></div>
                            </div>
                            <div class="col-md-6">
                                <x-forms.label for="board_list_status" :label="__('user_tasks.attributes.status')" required />
                                <select class="form-select" id="board_list_status" name="status" required>
                                    @foreach (\Modules\Core\Models\UserTask::Statuses as $status)
                                        <option value="{{ $status }}">{{ __("user_tasks.statuses.{$status}") }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="status"></div>
                            </div>
                            <div class="col-12">
                                <x-forms.label for="board_list_name" :label="__('user_tasks.attributes.name')" required />
                                <input class="form-control" id="board_list_name" name="name" type="text" required>
                                <div class="invalid-feedback" data-error-for="name"></div>
                            </div>
                            <div class="col-12">
                                <x-forms.label for="board_list_color" :label="__('user_tasks.attributes.color')" />
                                <select class="form-select" id="board_list_color" name="color">
                                    @foreach (\Modules\Core\Models\UserTask::Colors as $color)
                                        <option value="{{ $color }}">{{ __("user_tasks.colors.{$color}") }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="color"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button class="btn btn-primary" type="submit">{{ __('user_tasks.actions.save_list') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.MyBoardConfig = @json($boardConfig);
    </script>
    <script src="{{ asset('vendors/sortablejs/Sortable.min.js') }}"></script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('vendors/summernote/summernote-bs5.min.js') }}"></script>
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url($myBoardJsPath) }}"></script>
@endpush
